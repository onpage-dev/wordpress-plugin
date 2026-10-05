# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- `POST /woocommerce/products` with a shorter `downloads` list, or `[]`, on a variable product now removes the dropped downloads from the variations that inherited them. They used to keep them.

## [1.0.1] - 2026-09-30

### Added
- `DELETE /terms` now deletes by `local_key`, like `DELETE /posts`: send `{"local_key": …, "taxonomy": …}` or use `?keyfield=local_key`, and every WPML translation holding the key is deleted. An ID, plain or as `{"id": …}`, still deletes that term only. Without a taxonomy, a key held in several taxonomies answers `409 ambiguous_local_key`.

### Changed
- The `409 duplicate_title` and `409 duplicate_local_key` messages of `POST /posts` now always carry `Element N`, like the other per-element errors.
- With Advanced Custom Fields older than 6.1, every endpoint now answers `500 acf_version_unsupported`, naming the active version, and the admin shows a notice. Such calls used to fail with a generic `500 request_failed`.

### Fixed
- `POST /terms`, the WooCommerce term endpoints (brands, categories, tags, attribute terms) and `POST /woocommerce/variant-products` no longer overwrite a language that a language map leaves out on update: `{"description": {"en": "Comfy"}}` now changes only the English description instead of copying it into the other translations.
- `POST /terms` and the WooCommerce term endpoints no longer clear the description of an existing term when the payload omits `description`.
- `POST /woocommerce/variant-products` now writes shared values, such as `props.regular_price`, to every existing translated variation, including those in a language that no map in the payload contains.
- `POST /posts` and `POST /woocommerce/products` no longer overwrite a language that a language map leaves out on update: `{"title": {"en": "Red Chair"}}` now renames only the English translation instead of every language in the WPML group.
- `POST /woocommerce/products` no longer publishes an existing product when the payload has no `status`: an update keeps the current status of every language. A new product still defaults to `publish`.
- `POST /woocommerce/products` with `attributes: null` or `{}` now removes every attribute of the product, global `pa_*` attributes included, instead of the custom ones only. A non-empty `attributes` object still keeps the global attributes it does not name.
- A plugin-managed variation built on an attribute that `POST /woocommerce/products` removes from its product, for example with `"pa_color": null`, is now made `private`. It used to stay published and purchasable, because WooCommerce no longer reported the removed attribute on the variation.
- `POST /posts` no longer empties the title and writes `"Array"` as the content of every language when an update sends a language map with no active language, for example `{"title": {"es": "Silla"}}` on an it/en site. Such a map now leaves every language as it is, as it already did for `acf_fields`, `files` and products.
- `POST /posts` now answers `400 invalid_param` (`Parameter 'title' is required`) when a new post's `title` map has no non-empty title in an active language, for example `{"fr": "Chaise"}` on a site without `fr`. It used to create a post with an empty title, or fail with `500 request_failed`.
- `DELETE /posts` and `DELETE /terms` now answer `400 input_invalid` for a boolean `local_key`, as a plain element with `?keyfield=local_key` or as `{"local_key": true}`. `true` used to delete the object with `local_key` `"1"`.
- `null` on the enum and boolean `props` of `POST /woocommerce/products` and `POST /woocommerce/variant-products` (`stock_status`, `backorders`, `catalog_visibility`, `tax_status`, `manage_stock`, `featured`, `reviews_allowed`, …) now leaves the value as it is, instead of resetting it to a default or to `false`.

## [1.0.0] - 2026-09-28

### Added
- REST API under `/wp-json/onpage/v1` that receives structured data from On Page® from any client. Requests authenticate with a token generated on the plugin's settings page.
- Creation and update of post types, ACF field groups, taxonomies, terms, posts and media, as idempotent upserts keyed by `local_key`.
- WooCommerce integration: products, variations, global attributes and their terms, categories, tags and brands.
- WPML integration for multilingual sites. Per-language values are sent as `{"<lang>": …}` maps.
- Media import from URL, deduplicated by On Page® storage segment.
- `POST /migration` to upgrade the data already on a site after a change of internal format.
- Requirements: WordPress 7.1, PHP 8.2 and an active Advanced Custom Fields. Without ACF the plugin registers no routes and shows a notice in the admin.

[1.0.1]: https://github.com/onpage-dev/wordpress-plugin/releases/tag/v1.0.1
[1.0.0]: https://github.com/onpage-dev/wordpress-plugin/releases/tag/v1.0.0
