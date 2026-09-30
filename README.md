# FWERKOR Analytics

A lightweight, first-party analytics plugin for WordPress.

It is designed for sites that want useful traffic statistics in the WordPress dashboard without operating a separate analytics stack.

## Features

- Today, 7-day, 30-day, 90-day and 365-day traffic views
- Page views and daily unique visitors
- Top pages
- Referrer domains
- Device, browser and operating-system breakdown
- No third-party JavaScript
- No cookies or local-storage identifiers
- Daily rotating anonymous visitor hashes
- Raw IP addresses are never stored
- Do Not Track support enabled by default
- Logged-in visitors excluded by default
- Configurable aggregate retention
- Short-lived deduplication records
- Works with cached WordPress pages through a REST beacon

## Data model

The plugin stores a small number of WordPress-prefixed tables:

- daily aggregates
- page aggregates
- referrer aggregates
- device aggregates
- short-lived anonymous deduplication hashes

The visitor hash is generated server-side from request metadata and a WordPress secret, rotates every calendar day, and is deleted after a short deduplication window. It is not designed for cross-day user tracking.

## Installation

Copy the repository to:

    wp-content/plugins/fwerkor-analytics

Then activate **FWERKOR Analytics** in WordPress.

No site hostname is embedded in the plugin. The tracking endpoint and first-party hostname are derived from WordPress configuration at runtime.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL or MariaDB compatible with WordPress

## License

GPL-2.0-or-later.
