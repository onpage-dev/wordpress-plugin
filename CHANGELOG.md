# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-25

### Added
- REST API under `/wp-json/onpage/v1` that receives structured data from On Page® from any client. Requests authenticate with a token generated on the plugin's settings page.
- Creation and update of post types, ACF field groups, taxonomies, terms, posts and media, as idempotent upserts keyed by `local_key`.
- WooCommerce integration: products, variations, global attributes and their terms, categories, tags and brands.
- WPML integration for multilingual sites. Per-language values are sent as `{"<lang>": …}` maps.
- Media import from URL, deduplicated by On Page® storage segment.
- `POST /migration` to upgrade the data of a site that ran an earlier installation of the plugin.
- Requirements: WordPress 7.1, PHP 8.2 and an active Advanced Custom Fields. Without ACF the plugin registers no routes and shows a notice in the admin.

[1.0.0]: https://github.com/onpage-dev/wordpress-plugin/releases/tag/v1.0.0
