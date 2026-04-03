<?php
defined('ABSPATH') || exit;

/* ========== GET DATA ========== */
add_action('wp_ajax_eit_get_data', 'eit_ajax_get_data');

function eit_ajax_get_data() {
    check_ajax_referer('eit_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('Oturum açmanız gerekiyor');
    }

    if (!current_user_can('eit_view') && !current_user_can('eit_edit') && !current_user_can('eit_manage')) {
        wp_send_json_error('Yetkiniz yok');
    }

    // Check if we have saved data in DB
    $saved = get_option('eit_books_data');
    if ($saved) {
        $books = json_decode($saved, true);
    } else {
        // First load: convert from PHP array
        $raw = eit_get_books();
        $books = [];
        foreach ($raw as $b) {
            $books[] = [
                'id'        => $b[0],
                'baslik'    => $b[1],
                'ders'      => $b[2],
                'sinif'     => $b[3],
                'okul'      => $b[4],
                'yayinevi'  => $b[5],
                'tarih'     => $b[6],
                'yazarlar'  => $b[7],
                'phase'     => $b[8],
                'durumu'    => $b[9],
                'atanan'    => $b[10],
                'guncel_onay' => $b[11],
            ];
        }
        // Save initial data
        update_option('eit_books_data', wp_json_encode($books), false);
    }

    $categories = eit_get_categories();
    $atananlar = json_decode(get_option('eit_atananlar', '[]'), true);

    $version = (int) get_option('eit_data_version', 0);

    wp_send_json_success([
        'books'      => $books,
        'categories' => $categories,
        'atananlar'  => is_array($atananlar) ? $atananlar : [],
        'version'    => $version,
    ]);
}

/* ========== SAVE BOOKS ========== */
add_action('wp_ajax_eit_save_books', 'eit_ajax_save_books');

function eit_ajax_save_books() {
    check_ajax_referer('eit_nonce', 'nonce');

    if (!current_user_can('eit_edit') && !current_user_can('eit_manage')) {
        wp_send_json_error('Yetkiniz yok');
    }

    $json = wp_unslash($_POST['books'] ?? '');
    $books = json_decode($json, true);

    if (!is_array($books)) {
        wp_send_json_error('Geçersiz veri');
    }

    // Sanitize
    foreach ($books as &$b) {
        $b['id']        = sanitize_text_field($b['id'] ?? '');
        $b['baslik']    = sanitize_text_field($b['baslik'] ?? '');
        $b['ders']      = sanitize_text_field($b['ders'] ?? '');
        $b['sinif']     = sanitize_text_field($b['sinif'] ?? '');
        $b['okul']      = sanitize_text_field($b['okul'] ?? '');
        $b['yayinevi']  = sanitize_text_field($b['yayinevi'] ?? '');
        $b['tarih']     = sanitize_text_field($b['tarih'] ?? '');
        $b['phase']     = sanitize_text_field($b['phase'] ?? '');
        $b['durumu']    = sanitize_text_field($b['durumu'] ?? '');
        $b['atanan']    = sanitize_text_field($b['atanan'] ?? '');
        $b['guncel_onay'] = !empty($b['guncel_onay']);
        if (isset($b['olusturmaTarihi'])) $b['olusturmaTarihi'] = sanitize_text_field($b['olusturmaTarihi']);

        // Sanitize nested arrays
        if (isset($b['yazarlar']) && is_array($b['yazarlar'])) {
            $b['yazarlar'] = array_map('sanitize_text_field', $b['yazarlar']);
        }
        if (isset($b['uniteler']) && is_array($b['uniteler'])) {
            $b['uniteler'] = eit_sanitize_uniteler($b['uniteler']);
        }
        if (isset($b['notlar']) && is_array($b['notlar'])) {
            foreach ($b['notlar'] as &$n) {
                $n['metin']  = sanitize_textarea_field($n['metin'] ?? '');
                $n['etiket'] = sanitize_text_field($n['etiket'] ?? '');
                $n['tarih']  = sanitize_text_field($n['tarih'] ?? '');
                $n['yazar']  = sanitize_text_field($n['yazar'] ?? '');
                if (isset($n['resim'])) $n['resim'] = esc_url_raw($n['resim'] ?? '');
            }
            unset($n);
        }
    }
    unset($b);

    // Optimistic lock: check version
    $client_version = isset($_POST['version']) ? (int) $_POST['version'] : -1;
    $server_version = (int) get_option('eit_data_version', 0);
    if ($client_version >= 0 && $client_version < $server_version) {
        wp_send_json_error('Veri başka bir kullanıcı tarafından güncellendi. Sayfayı yenileyin.');
    }

    update_option('eit_books_data', wp_json_encode($books), false);
    $new_version = $server_version + 1;
    update_option('eit_data_version', $new_version);

    // Save atananlar list
    $atananlar_json = wp_unslash($_POST['atananlar'] ?? '[]');
    $atananlar = json_decode($atananlar_json, true);
    if (is_array($atananlar)) {
        $atananlar = array_map('sanitize_text_field', $atananlar);
        update_option('eit_atananlar', wp_json_encode($atananlar), false);
    }

    wp_send_json_success(['saved' => count($books), 'version' => $new_version]);
}

/* ========== GOREV ACTION (tamamla - eit_gorev yeterli) ========== */
add_action('wp_ajax_eit_gorev_action', 'eit_ajax_gorev_action');
function eit_ajax_gorev_action() {
    check_ajax_referer('eit_nonce', 'nonce');

    if (!current_user_can('eit_gorev') && !current_user_can('eit_edit') && !current_user_can('eit_manage')) {
        wp_send_json_error('Yetkiniz yok');
    }

    $book_id = sanitize_text_field($_POST['book_id'] ?? '');
    $ui      = intval($_POST['ui'] ?? -1);
    $ii      = intval($_POST['ii'] ?? -1);
    $action  = sanitize_text_field($_POST['gorev_action'] ?? '');

    if (!$book_id || $ui < 0 || $ii < 0 || $action !== 'tamamla') {
        wp_send_json_error('Geçersiz parametreler');
    }

    // Optimistic lock
    $client_version = isset($_POST['version']) ? (int) $_POST['version'] : -1;
    $server_version = (int) get_option('eit_data_version', 0);
    if ($client_version >= 0 && $client_version < $server_version) {
        wp_send_json_error('Veri başka bir kullanıcı tarafından güncellendi. Sayfayı yenileyin.');
    }

    $saved = get_option('eit_books_data');
    if (!$saved) wp_send_json_error('Veri bulunamadı');
    $books = json_decode($saved, true);
    if (!is_array($books)) wp_send_json_error('Veri okunamadı');

    // Kitabi bul
    $found = false;
    foreach ($books as &$b) {
        if ($b['id'] !== $book_id) continue;
        if (!isset($b['uniteler'][$ui]['icerikler'][$ii])) {
            wp_send_json_error('İçerik bulunamadı');
        }
        $ic = &$b['uniteler'][$ui]['icerikler'][$ii];
        if (!isset($ic['gorev']) || !is_array($ic['gorev'])) {
            wp_send_json_error('Görev bulunamadı');
        }
        // Sadece kendi gorevini degistirebilir
        $current_user_id = get_current_user_id();
        if (intval($ic['gorev']['atananId'] ?? 0) !== $current_user_id) {
            wp_send_json_error('Bu görev size atanmamış');
        }

        $now = wp_date('Y-m-d');
        $ic['gorev']['durum'] = 'Tamamlandı';
        $ic['gorev']['tamamlanmaTarihi'] = $now;
        $found = true;
        break;
    }
    unset($b, $ic);

    if (!$found) wp_send_json_error('Kitap bulunamadı');

    update_option('eit_books_data', wp_json_encode($books), false);
    $version = (int) get_option('eit_data_version', 0) + 1;
    update_option('eit_data_version', $version);

    wp_send_json_success(['version' => $version]);
}

/* ========== IMPORT BOOKS FROM JSON ========== */
add_action('wp_ajax_eit_import_books', 'eit_ajax_import_books');
function eit_ajax_import_books() {
    check_ajax_referer('eit_nonce', 'nonce');
    if (!current_user_can('eit_manage')) wp_send_json_error('Yetkiniz yok');

    $json = wp_unslash($_POST['books'] ?? '');
    $books = json_decode($json, true);
    if (!is_array($books) || empty($books)) wp_send_json_error('Geçersiz veri');

    // Sanitize — save_books ile ayni mantik
    foreach ($books as &$b) {
        $b['id']        = sanitize_text_field($b['id'] ?? '');
        $b['baslik']    = sanitize_text_field($b['baslik'] ?? '');
        $b['ders']      = sanitize_text_field($b['ders'] ?? '');
        $b['sinif']     = sanitize_text_field($b['sinif'] ?? '');
        $b['okul']      = sanitize_text_field($b['okul'] ?? '');
        $b['yayinevi']  = sanitize_text_field($b['yayinevi'] ?? '');
        $b['tarih']     = sanitize_text_field($b['tarih'] ?? '');
        $b['phase']     = sanitize_text_field($b['phase'] ?? '');
        $b['durumu']    = sanitize_text_field($b['durumu'] ?? '');
        $b['atanan']    = sanitize_text_field($b['atanan'] ?? '');
        $b['guncel_onay'] = !empty($b['guncel_onay']);
        if (isset($b['olusturmaTarihi'])) $b['olusturmaTarihi'] = sanitize_text_field($b['olusturmaTarihi']);
        if (isset($b['yazarlar']) && is_array($b['yazarlar'])) {
            $b['yazarlar'] = array_map('sanitize_text_field', $b['yazarlar']);
        }
        if (isset($b['uniteler']) && is_array($b['uniteler'])) {
            $b['uniteler'] = eit_sanitize_uniteler($b['uniteler']);
        }
        if (isset($b['notlar']) && is_array($b['notlar'])) {
            foreach ($b['notlar'] as &$n) {
                $n['metin']  = sanitize_textarea_field($n['metin'] ?? '');
                $n['etiket'] = sanitize_text_field($n['etiket'] ?? '');
                $n['tarih']  = sanitize_text_field($n['tarih'] ?? '');
                $n['yazar']  = sanitize_text_field($n['yazar'] ?? '');
                if (isset($n['resim'])) $n['resim'] = esc_url_raw($n['resim'] ?? '');
            }
            unset($n);
        }
    }
    unset($b);

    update_option('eit_books_data', wp_json_encode($books), false);
    $version = (int) get_option('eit_data_version', 0) + 1;
    update_option('eit_data_version', $version);

    wp_send_json_success(['imported' => count($books), 'version' => $version]);
}

/* ========== HELPER: SANITIZE GOREV ========== */
function eit_sanitize_gorev($g) {
    if (!is_array($g)) return [];
    return [
        'atananId'         => intval($g['atananId'] ?? 0),
        'atananAd'         => sanitize_text_field($g['atananAd'] ?? ''),
        'durum'            => sanitize_text_field($g['durum'] ?? ''),
        'asama'            => sanitize_text_field($g['asama'] ?? ''),
        'atanmaTarihi'     => sanitize_text_field($g['atanmaTarihi'] ?? ''),
        'tahminiGun'       => isset($g['tahminiGun']) && $g['tahminiGun'] !== null ? intval($g['tahminiGun']) : null,
        'sonTarih'         => sanitize_text_field($g['sonTarih'] ?? ''),
        'tamamlanmaTarihi' => sanitize_text_field($g['tamamlanmaTarihi'] ?? ''),
        'atayan'           => sanitize_text_field($g['atayan'] ?? ''),
        'atayanId'         => intval($g['atayanId'] ?? 0),
    ];
}

/* ========== HELPER: SANITIZE UNITELER ========== */
function eit_sanitize_uniteler($uniteler) {
    if (!is_array($uniteler)) return [];
    foreach ($uniteler as &$u) {
        $u['ad'] = sanitize_text_field($u['ad'] ?? '');
        if (isset($u['icerikler']) && is_array($u['icerikler'])) {
            foreach ($u['icerikler'] as &$ic) {
                $ic['ad']       = sanitize_text_field($ic['ad'] ?? '');
                $ic['tur']      = sanitize_text_field($ic['tur'] ?? '');
                $ic['tip']      = sanitize_text_field($ic['tip'] ?? '');
                $ic['mebTur']   = sanitize_text_field($ic['mebTur'] ?? '');
                $ic['aciklama'] = sanitize_textarea_field($ic['aciklama'] ?? '');
                $ic['durum']    = sanitize_text_field($ic['durum'] ?? '');
                $ic['atanan']   = sanitize_text_field($ic['atanan'] ?? '');
                $ic['kazanim']  = sanitize_text_field($ic['kazanim'] ?? '');
                // Icerik tarih alanlari
                if (isset($ic['icerikGelmeTarihi'])) $ic['icerikGelmeTarihi'] = sanitize_text_field($ic['icerikGelmeTarihi']);
                if (isset($ic['icerikBaslamaTarihi'])) $ic['icerikBaslamaTarihi'] = sanitize_text_field($ic['icerikBaslamaTarihi']);
                if (isset($ic['icerikTamamlanmaTarihi'])) $ic['icerikTamamlanmaTarihi'] = sanitize_text_field($ic['icerikTamamlanmaTarihi']);
                if (isset($ic['kontrolTamamlanmaTarihi'])) $ic['kontrolTamamlanmaTarihi'] = sanitize_text_field($ic['kontrolTamamlanmaTarihi']);
                // Asama tarihleri objesi
                if (isset($ic['asamaTarihleri']) && is_array($ic['asamaTarihleri'])) {
                    foreach ($ic['asamaTarihleri'] as $aKey => &$aVal) {
                        $aVal = sanitize_text_field($aVal ?? '');
                    }
                    unset($aVal);
                }
                // Icerik notlari
                if (isset($ic['notlar']) && is_array($ic['notlar'])) {
                    foreach ($ic['notlar'] as &$n) {
                        $n['metin']  = sanitize_textarea_field($n['metin'] ?? '');
                        $n['tarih']  = sanitize_text_field($n['tarih'] ?? '');
                        $n['yazar']  = sanitize_text_field($n['yazar'] ?? '');
                        if (isset($n['resim'])) $n['resim'] = esc_url_raw($n['resim'] ?? '');
                    }
                    unset($n);
                }
                // Gorev object sanitization
                if (isset($ic['gorev']) && is_array($ic['gorev'])) {
                    $ic['gorev'] = eit_sanitize_gorev($ic['gorev']);
                } elseif (isset($ic['gorev'])) {
                    unset($ic['gorev']);
                }
                // Gorev gecmisi sanitization
                if (isset($ic['gorevGecmisi']) && is_array($ic['gorevGecmisi'])) {
                    foreach ($ic['gorevGecmisi'] as &$gg) {
                        $gg = eit_sanitize_gorev($gg);
                    }
                    unset($gg);
                }
            }
            unset($ic);
        }
    }
    unset($u);
    return $uniteler;
}
