<?php
/**
 * Plugin Name: Presentonica UI
 * Description: Presentonica frontend: landing, workspace, generation flow and cabinet.
 * Version: 0.3.8
 * Author: Presentonica
 */

if (!defined('ABSPATH')) { exit; }

define('PRESENTONIKA_UI_VERSION', '0.3.8');
define('PRESENTONIKA_UI_PATH', plugin_dir_path(__FILE__));
define('PRESENTONIKA_UI_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
    // Soft dependency: core should be active.
    if (!defined('PRESENTONIKA_CORE_VERSION')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Presentonica UI</strong>: активируйте плагин <strong>Presentonica Core</strong>.</p></div>';
        });
        // Still load shortcodes to show friendly messages on frontend.
    }

    require_once PRESENTONIKA_UI_PATH . 'includes/enqueue.php';
    require_once PRESENTONIKA_UI_PATH . 'includes/shortcodes.php';
    require_once PRESENTONIKA_UI_PATH . 'includes/cabinet.php';
    require_once PRESENTONIKA_UI_PATH . 'includes/demo-landing.php';
    require_once PRESENTONIKA_UI_PATH . 'includes/product-pages.php';
    require_once PRESENTONIKA_UI_PATH . 'includes/product-assets.php';
});
