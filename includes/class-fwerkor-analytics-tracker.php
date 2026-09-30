<?php

if (!defined('ABSPATH')) {
    exit;
}

final class FWERKOR_Analytics_Tracker
{
    public function __construct()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function enqueue(): void
    {
        if (is_admin() || is_feed() || is_robots() || is_preview()) {
            return;
        }

        if (is_user_logged_in() && !get_option('fwerkor_analytics_track_logged_in', 0)) {
            return;
        }

        wp_enqueue_script(
            'fwerkor-analytics-tracker',
            FWERKOR_ANALYTICS_URL . 'assets/tracker.js',
            array(),
            FWERKOR_ANALYTICS_VERSION,
            true
        );

        $config = array(
            'endpoint' => esc_url_raw(rest_url('fwerkor-analytics/v1/track')),
            'respectDNT' => (bool) get_option('fwerkor_analytics_respect_dnt', 1),
        );

        wp_add_inline_script(
            'fwerkor-analytics-tracker',
            'window.FWERKOR_ANALYTICS=' . wp_json_encode($config) . ';',
            'before'
        );
    }

    public function register_routes(): void
    {
        register_rest_route(
            'fwerkor-analytics/v1',
            '/track',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'track'),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function track(WP_REST_Request $request)
    {
        if (!$this->is_first_party_request($request)) {
            return new WP_Error(
                'fwerkor_analytics_origin',
                'First-party request required.',
                array('status' => 403)
            );
        }

        $user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
        if ($this->is_bot($user_agent)) {
            return new WP_REST_Response(array('ok' => true, 'ignored' => 'bot'), 202);
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $raw = $request->get_body();
            $payload = json_decode($raw, true);
        }

        if (!is_array($payload)) {
            return new WP_Error(
                'fwerkor_analytics_payload',
                'Invalid payload.',
                array('status' => 400)
            );
        }

        $path = $this->normalize_path((string) ($payload['path'] ?? '/'));
        if ($this->is_excluded_path($path)) {
            return new WP_REST_Response(array('ok' => true, 'ignored' => 'path'), 202);
        }

        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        $title = function_exists('mb_substr') ? mb_substr($title, 0, 255) : substr($title, 0, 255);

        $referrer = $this->normalize_referrer((string) ($payload['referrer'] ?? ''));
        [$device, $browser, $os] = $this->classify_user_agent($user_agent);

        $day = wp_date('Y-m-d');
        $visitor_hash = $this->visitor_hash($day, $user_agent);

        if (!FWERKOR_Analytics_DB::record(
            $day,
            $visitor_hash,
            $path,
            $title,
            $referrer,
            $device,
            $browser,
            $os
        )) {
            return new WP_Error(
                'fwerkor_analytics_storage',
                'Analytics storage failed.',
                array('status' => 500)
            );
        }

        $response = new WP_REST_Response(array('ok' => true), 202);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    private function is_first_party_request(WP_REST_Request $request): bool
    {
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ('' === $home_host) {
            return false;
        }

        $fetch_site = strtolower((string) $request->get_header('sec-fetch-site'));
        if ('' !== $fetch_site && !in_array($fetch_site, array('same-origin', 'same-site'), true)) {
            return false;
        }

        foreach (array('origin', 'referer') as $header) {
            $value = trim((string) $request->get_header($header));
            if ('' === $value) {
                continue;
            }

            $host = strtolower((string) wp_parse_url($value, PHP_URL_HOST));
            if (hash_equals($home_host, $host)) {
                return true;
            }
        }

        return false;
    }

    private function normalize_path(string $value): string
    {
        $path = wp_parse_url($value, PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            $path = '/';
        }

        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        if (strlen($path) > 2048) {
            $path = substr($path, 0, 2048);
        }

        return $path;
    }

    private function is_excluded_path(string $path): bool
    {
        foreach (array('/wp-admin', '/wp-login.php', '/wp-json/', '/wp-cron.php') as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function normalize_referrer(string $value): string
    {
        if ('' === $value) {
            return '(direct)';
        }

        $host = strtolower((string) wp_parse_url($value, PHP_URL_HOST));
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

        if ('' === $host || hash_equals($home_host, $host)) {
            return '(direct)';
        }

        $host = preg_replace('/^www\./i', '', $host) ?: $host;

        return substr(sanitize_text_field($host), 0, 255);
    }

    private function visitor_hash(string $day, string $user_agent): string
    {
        $ip = $this->client_ip();

        return hash_hmac(
            'sha256',
            $ip . '|' . $user_agent,
            wp_salt('auth') . '|fwerkor-analytics|' . $day
        );
    }

    private function client_ip(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        if ($this->is_private_or_reserved_ip($remote)) {
            foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR') as $key) {
                $raw = trim((string) ($_SERVER[$key] ?? ''));
                if ('' === $raw) {
                    continue;
                }

                $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
                $candidate = 'HTTP_X_FORWARDED_FOR' === $key
                    ? (string) end($parts)
                    : (string) ($parts[0] ?? '');

                if (false !== filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        if (false !== filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return 'unknown';
    }

    private function is_private_or_reserved_ip(string $ip): bool
    {
        if ('' === $ip || false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        return false === filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private function is_bot(string $user_agent): bool
    {
        if ('' === trim($user_agent)) {
            return true;
        }

        return 1 === preg_match(
            '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|monitor|headless|wget|curl|python|go-http-client|uptime|preview/i',
            $user_agent
        );
    }

    private function classify_user_agent(string $ua): array
    {
        $device = 'desktop';
        if (preg_match('/ipad|tablet/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/mobile|iphone|ipod|android/i', $ua)) {
            $device = 'mobile';
        }

        $browser = 'Other';
        if (preg_match('/Edg\//i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR\//i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/Chrome\//i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox\//i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari\//i', $ua) && preg_match('/Version\//i', $ua)) {
            $browser = 'Safari';
        }

        $os = 'Other';
        if (preg_match('/Windows NT/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        return array($device, $browser, $os);
    }
}
