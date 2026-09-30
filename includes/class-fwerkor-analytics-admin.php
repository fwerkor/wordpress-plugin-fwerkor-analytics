<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FWERKOR_Analytics_Admin
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('wp_dashboard_setup', array($this, 'dashboard_widget'));
    }

    public function menu(): void
    {
        add_menu_page(
            'Analytics',
            'Analytics',
            'manage_options',
            'fwerkor-analytics',
            array($this, 'render'),
            'dashicons-chart-area',
            3
        );
    }

    public function dashboard_widget(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'fwerkor_analytics_dashboard',
            'Analytics',
            array($this, 'render_dashboard_widget')
        );
    }

    public function render_dashboard_widget(): void
    {
        $today = FWERKOR_Analytics_DB::summary(1);
        $week = FWERKOR_Analytics_DB::summary(7);
        $pages = FWERKOR_Analytics_DB::top_pages(7, 3);

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">';
        echo '<div><strong style="font-size:24px">' . esc_html(number_format_i18n($today['today_views'])) . '</strong><br><span style="color:#646970">Today views</span></div>';
        echo '<div><strong style="font-size:24px">' . esc_html(number_format_i18n($today['today_visitors'])) . '</strong><br><span style="color:#646970">Today visitors</span></div>';
        echo '</div>';
        echo '<p style="margin:0 0 10px"><strong>' . esc_html(number_format_i18n($week['range_views'])) . '</strong> views in the last 7 days</p>';

        if (!empty($pages)) {
            echo '<ol style="margin:0 0 12px 18px">';
            foreach ($pages as $page) {
                $label = '' !== trim((string) $page['title']) ? $page['title'] : $page['path'];
                echo '<li style="margin-bottom:5px"><span>' . esc_html($label) . '</span> <strong style="float:right">' . esc_html(number_format_i18n((int) $page['views'])) . '</strong></li>';
            }
            echo '</ol>';
        }

        echo '<a href="' . esc_url(admin_url('admin.php?page=fwerkor-analytics')) . '">Open Analytics →</a>';
    }

    public function assets(string $hook): void
    {
        if ('toplevel_page_fwerkor-analytics' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'fwerkor-analytics-admin',
            FWERKOR_ANALYTICS_URL . 'assets/admin.css',
            array(),
            FWERKOR_ANALYTICS_VERSION
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view analytics.', 'fwerkor-analytics'));
        }

        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['fwerkor_analytics_settings'])) {
            $this->save_settings();
        }

        $range = isset($_GET['range']) ? absint($_GET['range']) : 30;
        if (!in_array($range, array(7, 30, 90, 365), true)) {
            $range = 30;
        }

        $summary = FWERKOR_Analytics_DB::summary($range);
        $daily = FWERKOR_Analytics_DB::daily($range);
        $pages = FWERKOR_Analytics_DB::top_pages($range, 10);
        $referrers = FWERKOR_Analytics_DB::top_referrers($range, 10);
        $devices = FWERKOR_Analytics_DB::devices($range);

        ?>
        <div class="wrap fwa-wrap">
            <div class="fwa-heading">
                <div>
                    <p class="fwa-eyebrow">FIRST-PARTY ANALYTICS</p>
                    <h1>Analytics</h1>
                    <p class="fwa-subtitle">Cookie-free page analytics stored in this WordPress database.</p>
                </div>
                <nav class="fwa-ranges" aria-label="Date range">
                    <?php foreach (array(7, 30, 90, 365) as $option) : ?>
                        <a
                            class="<?php echo $range === $option ? 'is-active' : ''; ?>"
                            href="<?php echo esc_url(add_query_arg(array('page' => 'fwerkor-analytics', 'range' => $option), admin_url('admin.php'))); ?>"
                        ><?php echo esc_html($option); ?> days</a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <?php if (isset($_GET['settings-updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Analytics settings saved.</p></div>
            <?php endif; ?>

            <section class="fwa-stats">
                <?php $this->metric('Today views', $summary['today_views']); ?>
                <?php $this->metric('Today visitors', $summary['today_visitors']); ?>
                <?php $this->metric($range . '-day views', $summary['range_views']); ?>
                <?php $this->metric($range . '-day visitor-days', $summary['range_visitors']); ?>
            </section>

            <section class="fwa-panel fwa-chart-panel">
                <div class="fwa-panel-head">
                    <div>
                        <p class="fwa-kicker">TRAFFIC</p>
                        <h2>Views and daily visitors</h2>
                    </div>
                    <div class="fwa-legend"><span class="views">Views</span><span class="visitors">Visitors</span></div>
                </div>
                <?php echo $this->chart($daily); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <p class="fwa-note">Visitors are deduplicated per calendar day. The anonymous visitor hash rotates daily and is not used to follow a person across days.</p>
            </section>

            <div class="fwa-grid-two">
                <section class="fwa-panel">
                    <div class="fwa-panel-head"><div><p class="fwa-kicker">CONTENT</p><h2>Top pages</h2></div></div>
                    <?php $this->pages_table($pages); ?>
                </section>

                <section class="fwa-panel">
                    <div class="fwa-panel-head"><div><p class="fwa-kicker">ACQUISITION</p><h2>Referrers</h2></div></div>
                    <?php $this->referrers_table($referrers); ?>
                </section>
            </div>

            <section class="fwa-panel">
                <div class="fwa-panel-head"><div><p class="fwa-kicker">CLIENTS</p><h2>Devices</h2></div></div>
                <?php $this->devices_table($devices); ?>
            </section>

            <section class="fwa-panel fwa-settings">
                <div class="fwa-panel-head">
                    <div><p class="fwa-kicker">SETTINGS</p><h2>Privacy and retention</h2></div>
                </div>
                <form method="post">
                    <?php wp_nonce_field('fwerkor_analytics_settings'); ?>
                    <input type="hidden" name="fwerkor_analytics_settings" value="1">

                    <label class="fwa-setting-row">
                        <span><strong>Retention</strong><small>Aggregated analytics are automatically removed after this many days.</small></span>
                        <input type="number" name="retention_days" min="30" max="3650" step="1" value="<?php echo esc_attr((string) get_option('fwerkor_analytics_retention_days', 365)); ?>">
                    </label>

                    <label class="fwa-setting-row">
                        <span><strong>Track logged-in visitors</strong><small>Off by default so editors and administrators do not affect public traffic.</small></span>
                        <input type="checkbox" name="track_logged_in" value="1" <?php checked((bool) get_option('fwerkor_analytics_track_logged_in', 0)); ?>>
                    </label>

                    <label class="fwa-setting-row">
                        <span><strong>Respect Do Not Track</strong><small>When enabled, browsers sending DNT=1 are not measured.</small></span>
                        <input type="checkbox" name="respect_dnt" value="1" <?php checked((bool) get_option('fwerkor_analytics_respect_dnt', 1)); ?>>
                    </label>

                    <?php submit_button('Save settings', 'primary', 'submit', false); ?>
                </form>
            </section>
        </div>
        <?php
    }

    private function save_settings(): void
    {
        check_admin_referer('fwerkor_analytics_settings');

        $retention = isset($_POST['retention_days']) ? absint($_POST['retention_days']) : 365;
        $retention = max(30, min(3650, $retention));

        update_option('fwerkor_analytics_retention_days', $retention, false);
        update_option('fwerkor_analytics_track_logged_in', isset($_POST['track_logged_in']) ? 1 : 0, false);
        update_option('fwerkor_analytics_respect_dnt', isset($_POST['respect_dnt']) ? 1 : 0, false);

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'fwerkor-analytics',
                    'settings-updated' => '1',
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }

    private function metric(string $label, int $value): void
    {
        ?>
        <article class="fwa-stat">
            <span><?php echo esc_html($label); ?></span>
            <strong><?php echo esc_html(number_format_i18n($value)); ?></strong>
        </article>
        <?php
    }

    private function chart(array $rows): string
    {
        if (empty($rows)) {
            return '<div class="fwa-empty">No traffic recorded yet.</div>';
        }

        $width = 1000.0;
        $height = 250.0;
        $left = 24.0;
        $right = 18.0;
        $top = 18.0;
        $bottom = 34.0;
        $plot_width = $width - $left - $right;
        $plot_height = $height - $top - $bottom;

        $max = 1;
        foreach ($rows as $row) {
            $max = max($max, (int) $row['views'], (int) $row['visitors']);
        }

        $count = count($rows);
        $views = array();
        $visitors = array();

        foreach ($rows as $index => $row) {
            $x = 1 === $count ? $left + ($plot_width / 2) : $left + ($plot_width * $index / ($count - 1));
            $view_y = $top + $plot_height - ($plot_height * ((int) $row['views'] / $max));
            $visitor_y = $top + $plot_height - ($plot_height * ((int) $row['visitors'] / $max));
            $views[] = number_format($x, 2, '.', '') . ',' . number_format($view_y, 2, '.', '');
            $visitors[] = number_format($x, 2, '.', '') . ',' . number_format($visitor_y, 2, '.', '');
        }

        $first = $rows[0]['day'];
        $middle = $rows[(int) floor(($count - 1) / 2)]['day'];
        $last = $rows[$count - 1]['day'];

        ob_start();
        ?>
        <div class="fwa-chart-wrap">
            <svg class="fwa-chart" viewBox="0 0 1000 250" role="img" aria-label="Traffic trend">
                <line x1="24" y1="216" x2="982" y2="216" class="fwa-gridline"></line>
                <line x1="24" y1="150" x2="982" y2="150" class="fwa-gridline"></line>
                <line x1="24" y1="84" x2="982" y2="84" class="fwa-gridline"></line>
                <line x1="24" y1="18" x2="982" y2="18" class="fwa-gridline"></line>
                <polyline points="<?php echo esc_attr(implode(' ', $views)); ?>" class="fwa-line fwa-line-views"></polyline>
                <polyline points="<?php echo esc_attr(implode(' ', $visitors)); ?>" class="fwa-line fwa-line-visitors"></polyline>
                <text x="24" y="242" text-anchor="start"><?php echo esc_html(wp_date('M j', strtotime($first))); ?></text>
                <text x="503" y="242" text-anchor="middle"><?php echo esc_html(wp_date('M j', strtotime($middle))); ?></text>
                <text x="982" y="242" text-anchor="end"><?php echo esc_html(wp_date('M j', strtotime($last))); ?></text>
                <text x="24" y="14" text-anchor="start"><?php echo esc_html(number_format_i18n($max)); ?></text>
            </svg>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private function pages_table(array $rows): void
    {
        if (empty($rows)) {
            echo '<div class="fwa-empty">No page data yet.</div>';
            return;
        }

        echo '<div class="fwa-table">';
        foreach ($rows as $row) {
            $label = '' !== trim((string) $row['title']) ? $row['title'] : $row['path'];
            echo '<div class="fwa-table-row">';
            echo '<span class="fwa-main"><strong>' . esc_html($label) . '</strong><small>' . esc_html($row['path']) . '</small></span>';
            echo '<span>' . esc_html(number_format_i18n((int) $row['views'])) . '<small>views</small></span>';
            echo '<span>' . esc_html(number_format_i18n((int) $row['visitors'])) . '<small>visitor-days</small></span>';
            echo '</div>';
        }
        echo '</div>';
    }

    private function referrers_table(array $rows): void
    {
        if (empty($rows)) {
            echo '<div class="fwa-empty">No referrer data yet.</div>';
            return;
        }

        echo '<div class="fwa-table">';
        foreach ($rows as $row) {
            echo '<div class="fwa-table-row">';
            echo '<span class="fwa-main"><strong>' . esc_html($row['referrer']) . '</strong></span>';
            echo '<span>' . esc_html(number_format_i18n((int) $row['views'])) . '<small>views</small></span>';
            echo '<span>' . esc_html(number_format_i18n((int) $row['visitors'])) . '<small>visitor-days</small></span>';
            echo '</div>';
        }
        echo '</div>';
    }

    private function devices_table(array $rows): void
    {
        if (empty($rows)) {
            echo '<div class="fwa-empty">No device data yet.</div>';
            return;
        }

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['views'];
        }
        $total = max(1, $total);

        echo '<div class="fwa-device-list">';
        foreach ($rows as $row) {
            $views = (int) $row['views'];
            $pct = min(100, ($views / $total) * 100);
            echo '<div class="fwa-device-row">';
            echo '<div class="fwa-device-meta"><strong>' . esc_html(ucfirst($row['device']) . ' · ' . $row['browser']) . '</strong><small>' . esc_html($row['os']) . '</small></div>';
            echo '<div class="fwa-bar"><i style="width:' . esc_attr(number_format($pct, 2, '.', '')) . '%"></i></div>';
            echo '<span>' . esc_html(number_format_i18n($views)) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }
}
