<?php
/**
 * Plugin Name: Presentonika Core
 * Description: Presentonika backend: DB, rate-limit, cron pipelines (Gamma + Orchestrator), bridge, persistence, REST endpoints.
 * Version: 0.2.4
 * Author: Presentonika
 */

if (!defined('ABSPATH')) { exit; }

define('PRESENTONIKA_CORE_VERSION', '0.2.4');
define('PRESENTONIKA_CORE_PATH', plugin_dir_path(__FILE__));
define('PRESENTONIKA_CORE_URL', plugin_dir_url(__FILE__));

require_once PRESENTONIKA_CORE_PATH . 'includes/bootstrap.php';

register_activation_hook(__FILE__, 'pnk_core_activate');
register_deactivation_hook(__FILE__, 'pnk_core_deactivate');
