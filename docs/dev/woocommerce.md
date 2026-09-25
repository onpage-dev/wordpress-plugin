# WooCommerce

This document describes how the On Page® plugin integrates with WooCommerce through its internal REST API. It focuses on how a product's downloadable files (`downloads`) are handled and saved.

## REST base

All WooCommerce routes are registered under this namespace:

```text
/wp-json/onpage/v1
```

The routes are protected by the plugin's authentication middleware, like every other On Page® API.

## Managed WooCommerce resources

The plugin exposes dedicated endpoints for the main WooCommerce entities:

| Endpoint | Resource |
| --- | --- |
| `GET\|POST\|DELETE /woocommerce/products` | Simple and variable products |
| `GET\|POST\|DELETE /woocommerce/variant-products` | Product variations |
| `GET\|POST\|DELETE /woocommerce/categories` | Product categories (`product_cat`) |
| `GET\|POST\|DELETE /woocommerce/tags` | Product tags (`product_tag`) |
| `GET\|POST\|DELETE /woocommerce/brands` | Product brands (`product_brand`) |
| `GET\|POST\|DELETE /woocommerce/attributes` | Global WooCommerce attributes |
| `GET\|POST\|DELETE /woocommerce/attributes/{attribute}/terms` | Terms of global attributes |

Whenever WooCommerce provides an official method, the plugin uses the native WooCommerce CRUD classes (`WC_Product_Simple`, `WC_Product_Variable`, `WC_Product_Variation`, `WC_Product_Download`). It does not write directly to meta in those cases.

**Pagination:** none of the WooCommerce `GET` endpoints above are paginated. Every call returns **all** items matching the given filters; there is no `per_page`/`page`. On very large catalogs (especially `products` and `variant-products`) the response can be heavy. See [pagination.md](pagination.md) for the full table of the plugin's GET endpoints (WooCommerce and others).

## Categories and tags

`POST /woocommerce/categories` and `POST /woocommerce/tags` use the term payload: `local_key`, `name`, `slug`, `description` and `acf_fields`.

If `name` is a plain string while other fields are WPML language maps, the same name is reused unchanged on every translation created. If no per-language `slug` is given, each translation gets a distinct technical slug, while the visible name stays shared.

An existing term with the same name under the same parent but a different `local_key` is left untouched. This avoids overwriting a category that comes from another source. In WordPress, two sibling terms cannot share a name unless one has an explicit, free slug. So the plugin creates a separate term with a technical slug (`<slug-base>-<language>`, plus a numeric suffix if already taken).

Ending up with two terms of the same name is the typical symptom of `local_key` values that are out of sync between source and destination. To fix it, call `DELETE /indexes` and then re-import top-down (see [API.md](../API.md)).

## Product save flow

`POST /woocommerce/products` accepts a JSON list of products. Each item is normalized, then created or updated.

The target product is resolved as follows:

1. If the payload contains `id`, that product is updated.
2. Otherwise, if a product with the same `local_key` already exists, that product is updated.
3. Otherwise, a new WooCommerce product is created.

`local_key` is the external identifier used by On Page®. It can be a **positive integer or a non-empty string**; integers and numeric strings are equivalent. For products it is stored in the `onpage_local_key` post meta. It must be unique across products, except for products in the same WPML translation group.

### Product payload fields

| Field | Description |
| --- | --- |
| `local_key` | Required. Integer or non-empty string. |
| `name` | Required. A string or a WPML language map. If it is a string, it is reused unchanged on every translation created from other multilingual fields. |
| `status` | Optional. Defaults to `publish`. |
| `long_description`, `short_description` | WooCommerce long and short description. |
| `props` | Native WooCommerce fields, e.g. prices, stock, SKU, weight, dimensions, visibility, `product_type`. |
| `image` | Sets or removes the main image. Accepts a remote URL (imported into the Media Library), the `attachment_id` (integer) of a file already in the Media Library (e.g. uploaded earlier with `POST /media`), or `null` to remove it. |
| `gallery` | Replaces the product gallery. A list of remote URLs and/or `attachment_id`s, or a WPML language map of lists. Gallery order follows array order. Duplicates are ignored; the first occurrence wins. An empty list or `null` clears the gallery. |
| `attributes` | Replaces the product's custom attributes. |
| `acf_fields` | Updates ACF fields. |
| `brand`, `categories`, `tags` | Assign the product taxonomies. |
| `downloads` | Native WooCommerce downloadable files. |

### Save steps

When saving, the service:

1. Creates or loads the correct WooCommerce object (`simple` or `variable`).
2. Applies title, descriptions and status.
3. Applies native fields from `props`.
4. Applies custom attributes and downloads.
5. Saves the product with `$product->save()`.
6. Updates `local_key`, image, gallery, ACF and taxonomies.
7. If WPML is active, creates or updates the translations. On an existing product, current translations are updated. Translations that the payload carries but that are missing from the WPML group are created at this point.
8. If the product is `variable`, syncs the parent's downloads to the existing variations.

## Product downloads

`downloads` manages the product's native WooCommerce downloadable files. This is not the same as an ACF file field:

- values sent in `downloads` go into WooCommerce's download structure;
- files in `acf_fields` are saved in their respective ACF fields.

Example:

```json
[
  {
    "local_key": 1001,
    "name": "Sedia rossa",
    "props": {
      "product_type": "simple",
      "regular_price": "49.90"
    },
    "downloads": [
      {
        "id": "scheda_tecnica",
        "name": "Scheda tecnica",
        "url": "https://cdn.example.com/prod-001/scheda-tecnica.pdf"
      },
      {
        "id": "scheda_sicurezza",
        "name": "Scheda di sicurezza",
        "file": "https://cdn.example.com/prod-001/scheda-sicurezza.pdf",
        "refresh": true
      }
    ]
  }
]
```

Each `downloads` item accepts:

| Field | Description |
| --- | --- |
| `file` or `url` | URL of the downloadable file, or the `attachment_id` (JSON integer) of a file already in the Media Library (e.g. uploaded with `POST /media`). `url` is an accepted alias and is normalized internally to `file`. The value can be `null` or an empty string to skip this download for the resolved language. |
| `name` | Name shown by WooCommerce and on the frontend. If omitted, the file name taken from the URL is used. |
| `id` | Stable download identifier. If omitted, a WordPress UUID is generated. |
| `refresh` | Optional boolean. If `true`, forces a refresh of the file imported into the Media Library when the remote URL is unchanged but its content has changed. |

`file`, `url` and `name` can also be WPML language maps. Each product translation then gets the value resolved for its own language, with fallback where the multilingual service provides one. `null` or empty values in `file`/`url` are ignored for that language.

## How downloads are saved

Downloads are saved by the `ProductDownloads` service, which is called during the product save.

For each download:

1. The `file`/`url` value is resolved for the current language. Its type is preserved: an `attachment_id` stays an integer after language resolution.
2. If the resolved value is an `attachment_id`, the plugin checks that it matches an existing attachment and uses its local URL directly, with no download. Otherwise the URL is validated and normalized.
3. If the URL does not already point to the site's uploads directory, the file is imported into (or reused from) the Media Library via `RemoteMedia::urlToMediaLibrary()`.
4. WooCommerce always receives a URL compatible with its approved download directories.
5. A `WC_Product_Download` object is created.
6. `id`, `name` and `file` are set on the object.
7. The full list is assigned to the product with `$product->set_downloads($downloads)`.
8. If the list is not empty, the product is marked downloadable with `$product->set_downloadable(true)`.
9. The product is then saved with `$product->save()`.

WooCommerce persists these downloads in its native product storage: the `_downloadable_files` post meta, managed by the WooCommerce data store. The plugin does not save downloads as ACF and does not keep a custom table. It delegates to WooCommerce through `set_downloads()` and `save()`.

For each file, the structure saved by WooCommerce contains at least:

- the download identifier;
- the download name;
- the file URL/path;
- the download's enabled state.

WooCommerce indexes the list internally by `download_id`. Use stable `id` values when a document must be updated over time without changing its logical identity.

The `GET /woocommerce/products` response exposes these values in:

```json
{
  "woocommerce": {
    "downloads": [
      {
        "id": "scheda_tecnica",
        "name": "Scheda tecnica",
        "file": "https://example.com/wp-content/uploads/2026/05/scheda-tecnica.pdf",
        "enabled": true
      }
    ]
  }
}
```

## Media Library import

If the remote file is not already inside `wp-content/uploads`, the plugin imports it into the Media Library before assigning it to the product. This lets the plugin:

- keep a local file managed by WordPress;
- reuse attachments already imported from the same URL;
- get a URL compatible with WooCommerce;
- attach the attachment to the parent product in the Media Library;
- respect WooCommerce's approved download directories logic.

If the remote file has already been imported, the existing attachment is reused. If the remote content changes but the URL stays the same, use `refresh: true` to force replacement of the imported file where possible.

### Using an existing `attachment_id`

Image/file fields that accept a remote URL also accept the `attachment_id` of a file already in the Media Library, for example one uploaded earlier with `POST /media`. It must be a JSON integer, not a string.

In this case the plugin downloads nothing. It only checks that the ID matches an existing attachment and assigns it directly. If the field has a parent post/product/variation, the attachment is also attached to that parent, consistent with URL import.

This applies to:

- `image` and `gallery` of `POST /woocommerce/products` and `POST /woocommerce/variant-products`;
- `thumbnail` of `POST /woocommerce/brands` and `POST /woocommerce/categories` (`product_cat` taxonomy);
- `downloads[].file`/`downloads[].url` of `POST /woocommerce/products`;
- `files` of `POST /posts` and `POST /media/link`;
- every ACF field of type `image`/`file` inside `acf_fields`, on any endpoint (top level, or inside a `repeater` or `group`).

## Removing downloads

To remove all native WooCommerce downloadable files from a product, send an empty list:

```json
[
  {
    "local_key": 1001,
    "name": "Sedia rossa",
    "downloads": []
  }
]
```

or `null`:

```json
[
  {
    "local_key": 1001,
    "name": "Sedia rossa",
    "downloads": null
  }
]
```

In both cases the list is normalized to empty and passed to WooCommerce with `set_downloads([])`.

If `downloads` is omitted from the payload, existing downloads are left unchanged.

## Public downloads on the product page

The plugin registers a frontend hook on `woocommerce_single_product_summary`. This hook shows a "Documents" section on the WooCommerce product page.

To avoid exposing downloads not managed by the On Page® API, the plugin also saves this meta:

```text
_onpage_public_download_ids
```

It holds the IDs of the downloads sent through the endpoint. The frontend shows only downloads that are:

- present on the WooCommerce product;
- listed in `_onpage_public_download_ids`;
- enabled in WooCommerce;
- set with a non-empty file.

WooCommerce remains the system that stores the downloadable files. `_onpage_public_download_ids` is only a safety allowlist that decides which links are shown publicly on the product page.

## Variable products and variations

WooCommerce handles downloads for variable products in a particular way: in the admin, downloadable files are normally shown on the individual variations.

For this reason, when a `variable` parent receives `downloads`, the plugin:

- saves the downloads on the parent product;
- builds a WooCommerce-compatible payload;
- copies the same list to the existing variations;
- marks the variations as downloadable if the list is not empty.

When a new variation is saved with `/woocommerce/variant-products`, it automatically inherits the downloads of its parent variable product. This keeps the WooCommerce UI consistent.

## Operational notes

- Use stable `id` values in downloads when the file always represents the same logical document, e.g. `scheda_tecnica` or `scheda_sicurezza`.
- Send `downloads` only when you want to replace the whole list of WooCommerce downloadable files.
- Omit `downloads` to update the product while leaving its downloadable files unchanged.
- Use `refresh: true` when a remote PDF has been regenerated at the same URL.
- Put commercial or technical documents in `downloads`. Put purely editorial files or custom data in `acf_fields` only if they must remain ACF fields.
