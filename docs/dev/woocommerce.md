# WooCommerce internals

This document explains how the plugin writes WooCommerce data: how a product and its variations
are saved, and how its downloadable files (`downloads`) are stored and shown.

It covers internals only. For the request and response contract of each endpoint, see the
[WooCommerce section of API.md](../API.md#woocommerce). The list of endpoints is in the
[endpoint index](../API.md#endpoint-index).

Whenever WooCommerce provides an official method, the plugin uses the native WooCommerce CRUD
classes (`WC_Product_Simple`, `WC_Product_Variable`, `WC_Product_Variation`,
`WC_Product_Download`). It does not write directly to meta in those cases.

## Product save flow

`POST /woocommerce/products` accepts a JSON list of products. Each item is normalized, then created
or updated. The payload fields are documented in
[`POST /woocommerce/products`](../API.md#post-woocommerceproducts).

The target product is resolved as follows:

1. If the payload contains `id`, that product is updated.
2. Otherwise, if a product with the same `local_key` already exists, that product is updated.
3. Otherwise, a new WooCommerce product is created.

For products, `local_key` is stored in the `onpage_local_key` post meta. It must be unique across
products, except for products in the same WPML translation group.

### Save steps

When saving, the service (`Product::persistProductFromParams()`), once per language:

1. Creates or loads the correct WooCommerce object (`simple` or `variable`).
2. Applies title, descriptions and status. `Product::normalizeProductPayload()` always fills
   `status` (`normalizeStatus()`, default `publish`), so a save without `status` publishes the
   product and its translations, on update too.
3. Applies native fields from `props`.
4. Applies attributes (see [Attributes](#attributes)) and downloads.
5. Saves the product with `$product->save()`.
6. Updates `local_key`, the public download IDs, image and gallery.
7. If the product is `variable`:
   - syncs the parent's downloads to the existing variations (see
     [Variable products and variations](#variable-products-and-variations));
   - if the payload sent `attributes`, makes private the variations that use a value the product
     no longer offers (see [Variations with values no longer offered](#variations-with-values-no-longer-offered)).
8. Saves the ACF fields and the taxonomies.

If WPML is active, the service then creates or updates the translations, running the same steps in
each language. On an existing product, current translations are updated. Translations that the
payload carries but that are missing from the WPML group are created at this point.

### Attributes

`attributes` manages the product's **custom** attributes. When the payload sends it:

- The custom attributes are replaced by the ones in the payload.
- The **global** attributes (`pa_*`) already on the product are kept as they are: their options,
  visibility and "used for variations" flag. A site admin may have added them in WooCommerce, and
  variations may be built on them.
- A payload key with the same name as an existing global attribute (for example `pa_color`)
  replaces that attribute.
- `attributes: null` or `{}` removes the custom attributes only.

On a `variable` product, the custom attributes are marked as used for variations.

## Product downloads

`downloads` manages the product's native WooCommerce downloadable files. This is not the same as an
ACF file field:

- values sent in `downloads` go into WooCommerce's download structure;
- files in `acf_fields` are saved in their respective ACF fields.

The accepted payload is documented in
[`downloads`](../API.md#downloads-native-woocommerce-downloadable-files) in API.md.

## How downloads are saved

Downloads are saved by the `ProductDownloads` service, which is called during the product save.

For each download:

1. The `file`/`url` value is resolved for the current language. Its type is preserved: an
   `attachment_id` stays an integer after language resolution.
2. If the resolved value is an `attachment_id`, the plugin checks that it matches an existing
   attachment and uses its local URL directly, with no download. Otherwise the URL is validated.
3. If the URL does not already point to the site's uploads directory, the file is imported into (or
   reused from) the Media Library via `RemoteMedia::urlToMediaLibrary()`. WooCommerce only ever
   receives the local URL.
4. The local URL is covered by an approved download directory (see
   [Approved download directories](#approved-download-directories)).
5. The download ID is resolved (see [Download IDs](#download-ids)).
6. A `WC_Product_Download` object is created with `id`, `name` and `file`. Without a `name`, the
   file name is used.
7. The full list is assigned to the product with `$product->set_downloads($downloads)`.
8. If the list is not empty, the product is marked downloadable with
   `$product->set_downloadable(true)`.
9. The product is then saved with `$product->save()`.

WooCommerce persists these downloads in its native product storage: the `_downloadable_files` post
meta, managed by the WooCommerce data store. The plugin does not save downloads as ACF and does not
keep a custom table. It delegates to WooCommerce through `set_downloads()` and `save()`.

For each file, the structure saved by WooCommerce contains at least:

- the download identifier;
- the download name;
- the file URL/path;
- the download's enabled state.

The `GET /woocommerce/products` response exposes these values in:

```json
{
  "woocommerce": {
    "downloads": [
      {
        "id": "data_sheet",
        "name": "Data sheet",
        "file": "https://example.com/wp-content/uploads/2026/05/data-sheet.pdf",
        "enabled": true
      }
    ]
  }
}
```

### Download IDs

WooCommerce keys a customer's download permissions by product and download ID. A download whose ID
changes on every sync would cut buyers off from files they paid for. `resolveDownloadId()` therefore
picks, in order:

1. the `id` sent in the payload;
2. the ID of the download the product already has for the same file URL. This also keeps the
   random IDs created by earlier plugin versions;
3. a deterministic, UUID-shaped ID: the MD5 of `onpage-download|<source>`. `<source>` is the
   On Page® storage token of the URL when it has one, otherwise the URL, or `attachment:<id>` for an
   attachment ID.

The same source gives the same ID on every sync and in every language. Two entries of one payload
never share an ID: a repeated source gets the variants `<source>#2`, `<source>#3`, and so on.

With `refresh: true` the stored file URL may change. The old random ID is then no longer found by
URL and is replaced, once, by the deterministic one.

### Approved download directories

WooCommerce only serves downloads from approved directories. When approval is enabled,
`ensureWooApprovedDownloadDirectory()` registers:

- one rule for the uploads base URL, for any file under it. It is added at most once per request,
  instead of one rule per `uploads/YYYY/MM` folder;
- the file's own directory, for a file outside the uploads URL (uploads offloaded to a CDN by
  another plugin).

The remote URL a client sent is never approved: only the local URL of the imported file is.
Per-month rules created by earlier versions are not removed.

## Media Library import

If the remote file is not already inside the uploads directory, the plugin imports it into the
Media Library before assigning it to the product. This lets the plugin:

- keep a local file managed by WordPress;
- reuse attachments already imported from the same file;
- get a URL compatible with WooCommerce;
- attach the attachment to the parent product in the Media Library;
- respect WooCommerce's approved download directories logic.

An existing attachment is found by its On Page® storage token first, then by its exact source URL
(see [internals.md](internals.md#media-and-remotemedia)). The download timeout is 12 seconds. Only
media and document file types can be imported this way: see the upload allowlist in internals.md.

If the remote content changes but the URL stays the same, use `refresh: true` to download the file
again and replace the imported one.

The same rules apply when a field receives the `attachment_id` of a file already in the Media
Library: nothing is downloaded. See [Media values](../API.md#media-values-url-or-attachment_id) in
API.md for every field that accepts one.

## Removing downloads

To remove all native WooCommerce downloadable files from a product, send an empty list:

```json
[
  {
    "local_key": 1001,
    "name": "Red chair",
    "downloads": []
  }
]
```

or `null`:

```json
[
  {
    "local_key": 1001,
    "name": "Red chair",
    "downloads": null
  }
]
```

In both cases the list is normalized to empty and passed to WooCommerce with `set_downloads([])`.

If `downloads` is omitted from the payload, existing downloads are left unchanged.

## Public downloads on the product page

The plugin registers a frontend hook on `woocommerce_single_product_summary`. This hook shows a
section on the WooCommerce product page, headed **Documenti** (the heading is hard-coded in
`ProductDownloads::renderProductDownloadLinks()`).

To avoid exposing downloads not managed by the On Page® API, the plugin also saves this meta:

```text
_onpage_public_download_ids
```

It holds the IDs of the downloads sent through the endpoint. The frontend shows only downloads that
are:

- present on the WooCommerce product;
- listed in `_onpage_public_download_ids`;
- enabled in WooCommerce;
- set with a non-empty file.

WooCommerce remains the system that stores the downloadable files. `_onpage_public_download_ids` is
only a safety allowlist that decides which links are shown publicly on the product page.

## Variable products and variations

### Inherited downloads

WooCommerce handles downloads for variable products in a particular way: in the admin,
downloadable files are normally shown on the individual variations. The API has no per-variation
`downloads`.

For this reason, when a `variable` parent receives `downloads`, the plugin saves them on the parent
and copies the same list to its existing variations. When a variation is saved with
`/woocommerce/variant-products`, it inherits the downloads of its parent too.

The copy only touches variations that still inherit from the parent:

- After each copy, a fingerprint of what was written (ID, name and file of each download) is stored
  in the variation meta `_onpage_inherited_downloads`.
- A variation whose current downloads are not empty and differ from that fingerprint has its own
  downloads, set by a site admin in WooCommerce. It is skipped.
- A variation with no fingerprint yet (copied by an earlier version, which always overwrote) is
  overwritten once, then tracked.
- A variation that already has the parent's downloads is not saved again. Repeated syncs, one per
  language, are idempotent.

### Saving a variation

`POST /woocommerce/variant-products` resolves the variation by `id`, then by `local_key` under the
given parent. Before saving, it checks:

- **`local_key`**: the key must not belong to another variation of the same parent, or to a
  variation of a parent outside the parent's WPML group (`409 duplicate_local_key`).
- **Attribute combination**: when the payload sends `attributes`, no other variation of the same
  parent may have the same combination (`409 duplicate_variation`). Trashed variations are ignored.
  Values are compared case-insensitively. A parent variation attribute the variation leaves unset
  counts as "any" (`''`), as WooCommerce stores it. WooCommerce sells the first variation that
  matches a customer's choice, so a duplicate could never be bought. The message names the other
  variation, for example `Parent product 12 already has variation 34 with attributes
  [pa_color=red, size=(any)]`.

### Translated variations

With WPML and a payload in several languages, the variation is saved under the source-language
parent first, then under each translated parent. A translation is found by `local_key` under its
own parent, or created there. `id` always names the source-language variation.

### Variations with values no longer offered

When a product save with `attributes` removes an option (or a whole variation attribute) that
existing variations still use, WooCommerce leaves those variations published. They disappear from
the selectors on the product page but can still be reached and bought through direct links, carts
and feeds.

`VariantProduct::disableVariationsWithUnofferedAttributes()` handles this after the save:

1. It looks at the variations of the product that carry a `local_key` (plugin-managed). Variations
   created by hand are left alone.
2. A variation that uses a value the parent no longer offers is set to `private`. Its previous
   status is stored in the meta `_onpage_held_status`. Nothing is deleted.
3. The parent is synced at once (`WC_Product_Variable::sync()`), so its price range and stock stop
   counting the disabled variations.

The next save of that variation through `/woocommerce/variant-products` decides what happens:

| The variation's attributes | Result |
|---|---|
| All offered again | The payload `status` applies, or the held status if the payload sends none. `_onpage_held_status` is deleted. |
| Still not offered | The variation stays `private`. The status the payload asked for is held instead. |
| Still not offered, and the payload sends `status: private` | The variation stays `private` and `_onpage_held_status` is deleted. |

## Global attributes and their terms

`DELETE /woocommerce/attributes` deletes an attribute in two steps:

1. While the `pa_*` taxonomy is still registered, it deletes the attribute's terms that no object
   uses. A term is deleted only when `get_objects_in_term()` returns no object at all, whatever the
   object's status. The cached term `count` is not used, because it only counts published objects.
2. It calls `wc_delete_attribute()`, which deletes all remaining terms when the taxonomy is
   registered, then removes the `local_key` option.

## Operational notes

- Use stable `id` values in downloads when the file always represents the same logical document,
  for example `data_sheet` or `safety_sheet`. Without an `id`, the plugin derives a stable one from
  the file.
- Send `downloads` only when you want to replace the whole list of WooCommerce downloadable files.
- Omit `downloads` to update the product while leaving its downloadable files unchanged.
- Use `refresh: true` when a remote PDF has been regenerated at the same URL.
- Put commercial or technical documents in `downloads`. Put purely editorial files or custom data
  in `acf_fields` only if they must remain ACF fields.
