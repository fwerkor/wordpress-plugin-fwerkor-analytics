<?php
/**
 * Plugin Name: FWERKOR Analytics
 * Plugin URI: https://github.com/fwerkor/wordpress-plugin-fwerkor-analytics
 * Description: Lightweight, privacy-friendly first-party analytics for WordPress.
 * Version: 1.0.1
 * Author: FWERKOR
 * Author URI: https://github.com/fwerkor
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fwerkor-analytics
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FWERKOR_ANALYTICS_VERSION', '1.0.1');
define('FWERKOR_ANALYTICS_FILE', __FILE__);
define('FWERKOR_ANALYTICS_DIR', plugin_dir_path(__FILE__));
define('FWERKOR_ANALYTICS_URL', plugin_dir_url(__FILE__));

require_once FWERKOR_ANALYTICS_DIR . 'includes/class-fwerkor-analytics-db.php';
require_once FWERKOR_ANALYTICS_DIR . 'includes/class-fwerkor-analytics-tracker.php';
require_once FWERKOR_ANALYTICS_DIR . 'includes/class-fwerkor-analytics-admin.php';

register_activation_hook(
    __FILE__,
    array('FWERKOR_Analytics_DB', 'activate')
);

add_action(
    'plugins_loaded',
    static function (): void {
        FWERKOR_Analytics_DB::maybe_upgrade();

        new FWERKOR_Analytics_Tracker();

        if (is_admin()) {
            new FWERKOR_Analytics_Admin();
        }
    }
);
