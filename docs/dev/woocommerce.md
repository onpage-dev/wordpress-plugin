# WooCommerce internals

This document explains how the plugin writes WooCommerce data: how a product is saved, and how its downloadable files (`downloads`) are stored and shown.

It covers internals only. For the request and response contract of each endpoint, see the [WooCommerce section of API.md](../API.md#woocommerce). The list of endpoints is in the [endpoint index](../API.md#endpoint-index).

Whenever WooCommerce provides an official method, the plugin uses the native WooCommerce CRUD classes (`WC_Product_Simple`, `WC_Product_Variable`, `WC_Product_Variation`, `WC_Product_Download`). It does not write directly to meta in those cases.

## Product save flow

`POST /woocommerce/products` accepts a JSON list of products. Each item is normalized, then created or updated. The payload fields are documented in [`POST /woocommerce/products`](../API.md#post-woocommerceproducts).

The target product is resolved as follows:

1. If the payload contains `id`, that product is updated.
2. Otherwise, if a product with the same `local_key` already exists, that product is updated.
3. Otherwise, a new WooCommerce product is created.

For products, `local_key` is stored in the `onpage_local_key` post meta. It must be unique across products, except for products in the same WPML translation group.

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

The accepted payload is documented in [`downloads`](../API.md#downloads-native-woocommerce-downloadable-files) in API.md.

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

The same rules apply when a field receives the `attachment_id` of a file already in the Media Library: nothing is downloaded. See [Media values](../API.md#media-values-url-or-attachment_id) in API.md for every field that accepts one.

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
