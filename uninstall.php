<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

foreach (array('daily', 'pages', 'referrers', 'devices', 'uniques') as $name) {
    $table = $wpdb->prefix . 'fwerkor_analytics_' . $name;
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

delete_option('fwerkor_analytics_db_version');
delete_option('fwerkor_analytics_retention_days');
delete_option('fwerkor_analytics_track_logged_in');
delete_option('fwerkor_analytics_respect_dnt');
delete_transient('fwerkor_analytics_pruned_today');
