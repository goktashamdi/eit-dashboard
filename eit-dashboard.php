<?php
/**
 * Plugin Name: EIT Dashboard
 * Description: Ders kitabi yonetim dashboard plugin'i - Excel verisini AJAX ile sunar
 * Version: 2.4.0
 * Author: EIT
 * Text Domain: eit-dashboard
 */

defined('ABSPATH') || exit;

define('EIT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EIT_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once EIT_PLUGIN_DIR . 'includes/data.php';
require_once EIT_PLUGIN_DIR . 'includes/ajax.php';
