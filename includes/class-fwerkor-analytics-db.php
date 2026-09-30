<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FWERKOR_Analytics_DB
{
    private const DB_VERSION = '1';

    public static function table(string $name): string
    {
        global $wpdb;

        $allowed = array('daily', 'pages', 'referrers', 'devices', 'uniques');
        if (!in_array($name, $allowed, true)) {
            throw new InvalidArgumentException('Unknown analytics table.');
        }

        return $wpdb->prefix . 'fwerkor_analytics_' . $name;
    }

    public static function activate(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $daily = self::table('daily');
        $pages = self::table('pages');
        $referrers = self::table('referrers');
        $devices = self::table('devices');
        $uniques = self::table('uniques');

        dbDelta(
            "CREATE TABLE {$daily} (
                day date NOT NULL,
                views bigint(20) unsigned NOT NULL DEFAULT 0,
                visitors bigint(20) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (day)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$pages} (
                day date NOT NULL,
                path_hash char(64) NOT NULL,
                path text NOT NULL,
                title varchar(255) NOT NULL DEFAULT '',
                views bigint(20) unsigned NOT NULL DEFAULT 0,
                visitors bigint(20) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (day,path_hash),
                KEY day_views (day,views)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$referrers} (
                day date NOT NULL,
                referrer_hash char(64) NOT NULL,
                referrer varchar(255) NOT NULL,
                views bigint(20) unsigned NOT NULL DEFAULT 0,
                visitors bigint(20) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (day,referrer_hash),
                KEY day_views (day,views)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$devices} (
                day date NOT NULL,
                dimension_hash char(64) NOT NULL,
                device varchar(24) NOT NULL,
                browser varchar(48) NOT NULL,
                os varchar(48) NOT NULL,
                views bigint(20) unsigned NOT NULL DEFAULT 0,
                visitors bigint(20) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (day,dimension_hash),
                KEY day_views (day,views)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$uniques} (
                day date NOT NULL,
                visitor_hash char(64) NOT NULL,
                scope_type varchar(16) NOT NULL,
                scope_key char(64) NOT NULL,
                PRIMARY KEY  (day,visitor_hash,scope_type,scope_key),
                KEY day_scope (day,scope_type)
            ) {$charset};"
        );

        add_option('fwerkor_analytics_db_version', self::DB_VERSION, '', false);
        add_option('fwerkor_analytics_retention_days', 365, '', false);
        add_option('fwerkor_analytics_track_logged_in', 0, '', false);
        add_option('fwerkor_analytics_respect_dnt', 1, '', false);
    }

    public static function maybe_upgrade(): void
    {
        if ((string) get_option('fwerkor_analytics_db_version', '') !== self::DB_VERSION) {
            self::activate();
            update_option('fwerkor_analytics_db_version', self::DB_VERSION, false);
        }
    }

    public static function record(
        string $day,
        string $visitor_hash,
        string $path,
        string $title,
        string $referrer,
        string $device,
        string $browser,
        string $os
    ): bool {
        global $wpdb;

        $daily = self::table('daily');
        $pages = self::table('pages');
        $referrers = self::table('referrers');
        $devices = self::table('devices');
        $uniques = self::table('uniques');

        $path_hash = hash('sha256', $path);
        $referrer_hash = hash('sha256', $referrer);
        $dimension_hash = hash('sha256', $device . '|' . $browser . '|' . $os);

        $ok = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$daily} (day, views, visitors)
                 VALUES (%s, 1, 0)
                 ON DUPLICATE KEY UPDATE views = views + 1",
                $day
            )
        );

        if (false === $ok) {
            return false;
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$pages} (day, path_hash, path, title, views, visitors)
                 VALUES (%s, %s, %s, %s, 1, 0)
                 ON DUPLICATE KEY UPDATE
                    views = views + 1,
                    path = VALUES(path),
                    title = IF(VALUES(title) <> '', VALUES(title), title)",
                $day,
                $path_hash,
                $path,
                $title
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$referrers} (day, referrer_hash, referrer, views, visitors)
                 VALUES (%s, %s, %s, 1, 0)
                 ON DUPLICATE KEY UPDATE
                    views = views + 1,
                    referrer = VALUES(referrer)",
                $day,
                $referrer_hash,
                $referrer
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$devices} (day, dimension_hash, device, browser, os, views, visitors)
                 VALUES (%s, %s, %s, %s, %s, 1, 0)
                 ON DUPLICATE KEY UPDATE views = views + 1",
                $day,
                $dimension_hash,
                $device,
                $browser,
                $os
            )
        );

        self::mark_unique($uniques, $day, $visitor_hash, 'global', 'global', $daily, "day = '" . esc_sql($day) . "'");
        self::mark_unique(
            $uniques,
            $day,
            $visitor_hash,
            'page',
            $path_hash,
            $pages,
            $wpdb->prepare('day = %s AND path_hash = %s', $day, $path_hash)
        );
        self::mark_unique(
            $uniques,
            $day,
            $visitor_hash,
            'referrer',
            $referrer_hash,
            $referrers,
            $wpdb->prepare('day = %s AND referrer_hash = %s', $day, $referrer_hash)
        );
        self::mark_unique(
            $uniques,
            $day,
            $visitor_hash,
            'device',
            $dimension_hash,
            $devices,
            $wpdb->prepare('day = %s AND dimension_hash = %s', $day, $dimension_hash)
        );

        self::maybe_prune();

        return true;
    }

    private static function mark_unique(
        string $uniques_table,
        string $day,
        string $visitor_hash,
        string $scope_type,
        string $scope_key,
        string $aggregate_table,
        string $where
    ): void {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$uniques_table} (day, visitor_hash, scope_type, scope_key)
                 VALUES (%s, %s, %s, %s)",
                $day,
                $visitor_hash,
                $scope_type,
                $scope_key
            )
        );

        if (1 === (int) $wpdb->rows_affected) {
            $wpdb->query("UPDATE {$aggregate_table} SET visitors = visitors + 1 WHERE {$where}");
        }
    }

    private static function maybe_prune(): void
    {
        if (get_transient('fwerkor_analytics_pruned_today')) {
            return;
        }

        global $wpdb;

        $retention = max(30, min(3650, (int) get_option('fwerkor_analytics_retention_days', 365)));
        $timezone = wp_timezone();
        $today = new DateTimeImmutable('today', $timezone);
        $aggregate_cutoff = $today->modify('-' . $retention . ' days')->format('Y-m-d');
        $unique_cutoff = $today->modify('-2 days')->format('Y-m-d');

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . self::table('uniques') . ' WHERE day < %s',
                $unique_cutoff
            )
        );

        foreach (array('daily', 'pages', 'referrers', 'devices') as $name) {
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . self::table($name) . ' WHERE day < %s',
                    $aggregate_cutoff
                )
            );
        }

        set_transient('fwerkor_analytics_pruned_today', 1, DAY_IN_SECONDS);
    }

    public static function summary(int $days): array
    {
        global $wpdb;

        $days = max(1, min(3650, $days));
        $cutoff = self::cutoff($days);
        $daily = self::table('daily');
        $today = wp_date('Y-m-d');

        $range = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(views),0) AS views,
                        COALESCE(SUM(visitors),0) AS visitors
                 FROM {$daily}
                 WHERE day >= %s",
                $cutoff
            ),
            ARRAY_A
        );

        $today_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT views, visitors FROM {$daily} WHERE day = %s",
                $today
            ),
            ARRAY_A
        );

        return array(
            'today_views' => (int) ($today_row['views'] ?? 0),
            'today_visitors' => (int) ($today_row['visitors'] ?? 0),
            'range_views' => (int) ($range['views'] ?? 0),
            'range_visitors' => (int) ($range['visitors'] ?? 0),
        );
    }

    public static function daily(int $days): array
    {
        global $wpdb;

        $days = max(1, min(3650, $days));
        $cutoff = self::cutoff($days);
        $table = self::table('daily');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT day, views, visitors
                 FROM {$table}
                 WHERE day >= %s
                 ORDER BY day ASC",
                $cutoff
            ),
            ARRAY_A
        );

        $indexed = array();
        foreach ($rows as $row) {
            $indexed[$row['day']] = array(
                'views' => (int) $row['views'],
                'visitors' => (int) $row['visitors'],
            );
        }

        $timezone = wp_timezone();
        $start = new DateTimeImmutable($cutoff, $timezone);
        $today = new DateTimeImmutable('today', $timezone);
        $result = array();

        for ($date = $start; $date <= $today; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $result[] = array(
                'day' => $key,
                'views' => (int) ($indexed[$key]['views'] ?? 0),
                'visitors' => (int) ($indexed[$key]['visitors'] ?? 0),
            );
        }

        return $result;
    }

    public static function top_pages(int $days, int $limit = 10): array
    {
        global $wpdb;

        $table = self::table('pages');
        $cutoff = self::cutoff($days);
        $limit = max(1, min(50, $limit));

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT path,
                        MAX(title) AS title,
                        SUM(views) AS views,
                        SUM(visitors) AS visitors
                 FROM {$table}
                 WHERE day >= %s
                 GROUP BY path_hash, path
                 ORDER BY views DESC
                 LIMIT %d",
                $cutoff,
                $limit
            ),
            ARRAY_A
        );
    }

    public static function top_referrers(int $days, int $limit = 10): array
    {
        global $wpdb;

        $table = self::table('referrers');
        $cutoff = self::cutoff($days);
        $limit = max(1, min(50, $limit));

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT referrer,
                        SUM(views) AS views,
                        SUM(visitors) AS visitors
                 FROM {$table}
                 WHERE day >= %s
                 GROUP BY referrer_hash, referrer
                 ORDER BY views DESC
                 LIMIT %d",
                $cutoff,
                $limit
            ),
            ARRAY_A
        );
    }

    public static function devices(int $days): array
    {
        global $wpdb;

        $table = self::table('devices');
        $cutoff = self::cutoff($days);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT device, browser, os,
                        SUM(views) AS views,
                        SUM(visitors) AS visitors
                 FROM {$table}
                 WHERE day >= %s
                 GROUP BY dimension_hash, device, browser, os
                 ORDER BY views DESC
                 LIMIT 20",
                $cutoff
            ),
            ARRAY_A
        );
    }

    private static function cutoff(int $days): string
    {
        $days = max(1, $days);
        $timezone = wp_timezone();

        return (new DateTimeImmutable('today', $timezone))
            ->modify('-' . ($days - 1) . ' days')
            ->format('Y-m-d');
    }
}
