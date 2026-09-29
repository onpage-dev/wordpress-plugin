# REST API reference

This document describes the REST API exposed by the plugin. It is based on `src/routes.php` and on the logic actually implemented in the controllers.

Base REST namespace:

```text
/wp-json/onpage/v1
```

## Contents

- [Endpoint index](#endpoint-index)
- [Authentication](#authentication)
- [General conventions](#general-conventions)
- [Handling `acf_fields`](#handling-acf_fields)
- [Error format](#error-format)
- [Field Groups](#field-groups)
- [Post Types](#post-types)
- [Posts](#posts)
- [WooCommerce](#woocommerce): brands, attributes, attribute terms, categories, tags, products, variations
- [Media](#media)
- [Taxonomies](#taxonomies)
- [Terms](#terms)
- [Languages](#languages)
- [Maintenance](#maintenance)
- [cURL examples](#curl-examples)
- [Implementation notes](#implementation-notes)

## Endpoint index

Every endpoint at a glance. Paths are relative to the base namespace `/wp-json/onpage/v1`. Click an endpoint to open its full specification.

In the **Notes** column, *Paginated* marks the only two endpoints that paginate. `?ignore` marks the `DELETE` endpoints that accept the [`?ignore` flag](#ignore-on-delete).

| Area | Method | Endpoint | Description | Notes |
| --- | --- | --- | --- | --- |
| Field Groups | `GET` | [`/field-groups`](#get-field-groups) | List all ACF field groups | |
| Field Groups | `POST` | [`/field-groups`](#post-field-groups) | Create or update ACF field groups | |
| Field Groups | `DELETE` | [`/field-groups`](#delete-field-groups) | Delete field groups by ID or title | `?ignore` |
| Post Types | `GET` | [`/post-types`](#get-post-types) | List all ACF post types | |
| Post Types | `POST` | [`/post-types`](#post-post-types) | Create or update ACF post types | |
| Post Types | `DELETE` | [`/post-types`](#delete-post-types) | Delete post types by ID or key | `?ignore` |
| Posts | `GET` | [`/posts`](#get-posts) | List posts, with filters and exact-title search | [Paginated](#pagination) |
| Posts | `GET` | [`/posts/{id}`](#get-postsid) | Get a single post | |
| Posts | `POST` | [`/posts`](#post-posts) | Create or update posts in batch | |
| Posts | `DELETE` | [`/posts`](#delete-posts) | Delete posts by ID, `local_key` or post type | `?ignore` |
| WooCommerce | `GET` | [`/woocommerce/brands`](#get-woocommercebrands) | List WooCommerce brands | |
| WooCommerce | `POST` | [`/woocommerce/brands`](#post-woocommercebrands) | Create or update brands | |
| WooCommerce | `DELETE` | [`/woocommerce/brands`](#delete-woocommercebrands) | Delete brands by `local_key` | `?ignore` |
| WooCommerce | `GET` | [`/woocommerce/attributes`](#get-woocommerceattributes) | List global product attributes | |
| WooCommerce | `POST` | [`/woocommerce/attributes`](#post-woocommerceattributes) | Create or update global product attributes | |
| WooCommerce | `DELETE` | [`/woocommerce/attributes`](#delete-woocommerceattributes) | Delete global product attributes by `local_key` | `?ignore` |
| WooCommerce | `GET` | [`/woocommerce/attributes/{attribute}/terms`](#get-woocommerceattributesattributeterms) | List the terms of an attribute | |
| WooCommerce | `POST` | [`/woocommerce/attributes/{attribute}/terms`](#post-woocommerceattributesattributeterms) | Create or update the terms of an attribute | |
| WooCommerce | `DELETE` | [`/woocommerce/attributes/{attribute}/terms`](#delete-woocommerceattributesattributeterms) | Delete attribute terms by `local_key` | `?ignore` |
| WooCommerce | `GET` | [`/woocommerce/categories`](#get-woocommercecategories) | List product categories | |
| WooCommerce | `POST` | [`/woocommerce/categories`](#post-woocommercecategories) | Create or update product categories | |
| WooCommerce | `DELETE` | [`/woocommerce/categories`](#delete-woocommercecategories) | Delete product categories by `local_key` | `?ignore` |
| WooCommerce | `GET` | [`/woocommerce/tags`](#get-woocommercetags) | List product tags | |
| WooCommerce | `POST` | [`/woocommerce/tags`](#post-woocommercetags) | Create or update product tags | |
| WooCommerce | `DELETE` | [`/woocommerce/tags`](#delete-woocommercetags) | Delete product tags by `local_key` | `?ignore` |
| WooCommerce | `GET` | [`/woocommerce/products`](#get-woocommerceproducts) | List WooCommerce products | |
| WooCommerce | `POST` | [`/woocommerce/products`](#post-woocommerceproducts) | Create or update products | |
| WooCommerce | `DELETE` | [`/woocommerce/products`](#delete-woocommerceproducts) | Delete products by `local_key` | `?ignore` |
| WooCommerce | `GET` | [`/woocommerce/variant-products`](#get-woocommercevariant-products) | List product variations | |
| WooCommerce | `POST` | [`/woocommerce/variant-products`](#post-woocommercevariant-products) | Create or update variations of a variable product | |
| WooCommerce | `DELETE` | [`/woocommerce/variant-products`](#delete-woocommercevariant-products) | Delete variations by `local_key` | `?ignore` |
| Media | `GET` | [`/media`](#get-media) | List Media Library attachments | [Paginated](#pagination) |
| Media | `POST` | [`/media`](#post-media) | Upload files (`multipart/form-data`) | |
| Media | `POST` | [`/media/link`](#post-medialink) | Import files by URL and link them to a post's ACF field | |
| Media | `DELETE` | [`/media`](#delete-media) | Delete attachments by ID | `?ignore` |
| Taxonomies | `GET` | [`/taxonomies`](#get-taxonomies) | List all ACF taxonomies | |
| Taxonomies | `POST` | [`/taxonomies`](#post-taxonomies) | Create or update ACF taxonomies | |
| Taxonomies | `DELETE` | [`/taxonomies`](#delete-taxonomies) | Delete taxonomies by ID or slug | `?ignore` |
| Terms | `GET` | [`/terms`](#get-terms) | List or search terms, of one taxonomy or of all | |
| Terms | `POST` | [`/terms`](#post-terms) | Create or update terms in batch | |
| Terms | `DELETE` | [`/terms`](#delete-terms) | Delete terms by ID or `local_key` | `?ignore` |
| Languages | `GET` | [`/languages`](#get-languages) | List the active WPML languages and the default one | |
| Maintenance | `DELETE` | [`/indexes`](#delete-indexes) | Remove all `local_key` associations | |
| Maintenance | `POST` | [`/migration`](#post-migration) | Run the plugin's data migrations | |

Path parameters:

- `{id}` and `{attribute}` each match a single path segment (no `/`).
- `{attribute}` accepts an attribute ID, or a slug with or without the `pa_` prefix. `color` and `pa_color` both work.

## Authentication

Every endpoint requires a Bearer token.

Required header:

```http
Authorization: Bearer <token>
```

| Error | When |
| --- | --- |
| `500 onpage_auth_not_configured` | No API token is configured in the WordPress options (`onpage_auth_token`). |
| `401 onpage_auth_missing_token` | The `Authorization` header is missing or does not contain a valid Bearer token. |
| `403 onpage_auth_invalid_token` | The token sent does not match the configured one. |

Once the token is accepted, every endpoint returns `500 acf_version_unsupported` when the active Advanced Custom Fields is older than 6.1. The message names the active version. Without ACF at all, the plugin registers no routes, so WordPress answers `404 rest_no_route`.

The token is generated from the **On Page®** page in the WordPress admin. Only administrators can access that page (capability `manage_options`). Generating and regenerating the token is also protected by a CSRF nonce. No REST endpoint can read or write the token: it is managed exclusively from the admin UI.

## General conventions

- **Batch writes.** Almost every write endpoint works in batch: the expected JSON body is an array. See [Request bodies](#request-bodies).
- **Exceptions.** `POST /media` uses `multipart/form-data` and accepts one or more files in the same request. `POST /media/link` takes a single JSON object. `POST /migration` and `DELETE /indexes` take no body.
- **Fail fast.** If one element of a batch fails, the request stops immediately and returns a `WP_Error`.
- **`?ignore` on DELETE.** Most `DELETE` endpoints accept `?ignore` to skip elements that are not found. See [`?ignore` on DELETE](#ignore-on-delete).
- **Taxonomy identifier.** The term endpoints identify the taxonomy with the `taxonomy` parameter (query string on `GET`/`DELETE /terms`, body field on `POST /terms`). It accepts either the **taxonomy slug** (e.g. `product_cat`, `brand`, `pa_color`) or the **numeric ACF ID** of the taxonomy. The slug takes precedence and is recommended: it is stable across environments (the ACF ID depends on creation order) and it also covers non-ACF taxonomies (WooCommerce `product_cat`/`product_tag`/`pa_*`). The numeric ID is still supported for backward compatibility.
- **Multilingual values.** When WPML is active, some fields can be sent as a language map `{ "<lang>": <value> }`.
- **Exact post types.** `/posts` and `/post-types` use the exact post type sent in the payload. No prefix is added.
- **Maps are always objects.** In responses, `acf_fields` and `translations` are always JSON objects. When they are empty (no ACF values, no WPML translations) they are `{}`, never `[]`, `false` or `null`.
- **Numeric IDs.** By default a WordPress ID (`id`, `post_id`, `parent`, `parent_id`, an attachment ID) is read with a lenient rule:
  - a positive JSON integer, a whole-number float (`12.0`) or a string of digits (`"12"`, surrounding spaces allowed) is accepted;
  - booleans, decimals (`1.5`, `"1.9"`) and other notations (`"1e3"`, `"12abc"`) are never cast to an ID. They count as invalid, so `true` can never address object 1.
  - on a `POST`, an `id` that is present and invalid answers `400 invalid_param`. `null`, `0` and `"0"` still mean "no ID" (`POST /woocommerce/products` rejects `0`). It is never ignored, so it cannot create a new object instead of updating one.

  Some endpoints are stricter:

  - `DELETE /field-groups`, `/post-types`, `/taxonomies` and `/posts` read an ID only from a JSON integer. A string, even `"12"`, is a title, key, slug or post type.
  - `DELETE /terms` accepts a positive JSON integer or a string of digits only, as a plain element or as `id` in an object. Leading zeros are allowed (`"012"`). Spaces and floats (`12.0`) are rejected.

### Request bodies

These rules apply to the `POST` and `DELETE` endpoints of `field-groups`, `post-types`, `posts`, `taxonomies`, `terms` and every `woocommerce/*` resource, and to `DELETE /media`. They do not apply to `POST /media` (multipart), `/media/link`, `/migration` or `/indexes`.

- **The body must be a JSON array.** A missing body, a body that is not JSON, a scalar or an object (named keys, or the empty object `{}`) returns `400 invalid_param` with the message `<Prefix> :: Request body must be a JSON array`.
- **Send `Content-Type: application/json`.** WordPress parses the body as JSON only with that header. Without it the body counts as missing, so the request also fails with `400 invalid_param`.
- **An empty array is valid.** `[]` does nothing and returns `200` (`[]` on `POST`, `null` on `DELETE`).
- **Every `POST` element must be a non-empty JSON object.** A scalar, `null`, a list or `{}` returns `400 invalid_param` with the message `<Prefix> :: Element N :: Invalid payload; expected a non-empty JSON object`.
- **Every `DELETE` element is validated.** An element of the wrong type returns `400` before anything is looked up. The accepted types and the error code are listed under each `DELETE` endpoint.

`<Prefix>` names the resource: `FieldGroup`, `PostType`, `Post`, `Media`, `Taxonomy`, `Term`, `WooCommerce Brand`, `WooCommerce Attribute`, `WooCommerce Attribute Term`, `WooCommerce Category`, `WooCommerce Tag`, `WooCommerce Product` or `WooCommerce Variant Product`. `N` is the 0-based position of the element in the array.

### `?ignore` on DELETE

`?ignore` makes a `DELETE` skip elements that are not found, instead of failing with `404 not_found`.

- It is enabled by the mere presence of the `ignore` query parameter, whatever its value. `?ignore`, `?ignore=1` and `?ignore=0` all enable it.
- It is supported by `DELETE /field-groups`, `/post-types`, `/posts`, `/taxonomies`, `/terms`, `/media` and every `DELETE /woocommerce/*`.
- It is not supported by `DELETE /indexes`, which has no body.
- It only skips **missing** elements. An element of the wrong type still returns `400`, and a real delete failure still returns `500`.
- Examples of what it skips: an integer ID that matches nothing, a `local_key` held by no object, a title, key or slug that matches nothing. On `DELETE /taxonomies` a slug that is not an ACF taxonomy of this plugin (for example `product_cat`) is skipped and nothing is changed. On `DELETE /posts` a post type string that is not registered is skipped and nothing is deleted.

### `local_key`

`local_key` is the external On Page® identifier. It can be a **positive integer or a non-empty string**.

- **Input** (payload and query string):
  - The value is trimmed.
  - Only the empty string, `"0"` and non-scalar types are rejected, with `400 invalid_param` (`400 input_invalid` on `DELETE /posts` and `DELETE /terms`).
  - `DELETE /posts` and `DELETE /terms` also reject booleans with `400 input_invalid`, so `true` never deletes the object with `local_key` `"1"`.
  - Integers and numeric strings are **equivalent** (`123` ≡ `"123"`; this is how WordPress stores meta values). The same object is resolved whatever type you send.
  - A text key (e.g. `"SKU-ABC"`) is kept as is, except for surrounding whitespace.
- **Output** (HTTP response):
  - A key that is a canonical integer is returned as an **integer** (backward compatible with existing consumers).
  - A non-numeric key is returned as a **string**.
  - A key that is not set is returned as **`null`**.
  - This applies to every endpoint that exposes it: `posts`, `products`, `variant-products`, `terms`/`brands`/`categories`/`tags`/`attributes/{}/terms`/`attributes`.
- **Posts** expose `local_key` as a top-level response field (`int|string|null`). It is not an ACF field: it is a technical post meta (`onpage_local_key`) and does not appear inside `acf_fields`.
- **Term references:**
  - For **products** (`brand`, `categories`, `tags`, `terms`), references are **local_keys** (integers or strings). Slugs are not supported.
  - For **posts** (`terms`/`term`), a reference can be a local_key (integer or string) **or** a slug. It is first looked up as a local_key, otherwise it is treated as a slug.
  - The `parent` of WooCommerce categories is also a local_key (integer or string).

### `term_exists` conflicts on terms

An existing term with the same name under the same parent but with a different `local_key` is **not** modified, so that a term from another source is never overwritten.

WordPress does not allow two sibling terms with the same name unless the caller provides a free explicit slug. In that case the upsert therefore creates a **separate term** with the requested name and a technical slug (`<slug-base>-<language>`, with a numeric suffix if already taken). The upsert fails only if no technical slug is available. It then returns `500 request_failed` with the message `Term :: Element N :: <WordPress message>` (`Term :: Element N :: Unable to upsert translation '<lang>' :: <WordPress message>` for a translation). The code `term_exists` itself is not returned.

The destination then holds two terms with the same name and different `local_key`s. This is the typical symptom of `local_key`s that are out of sync between source and destination (e.g. the source regenerated its keys). To fix it, call [`DELETE /indexes`](#delete-indexes) and then run a **top-down** re-import. The re-import re-adopts the existing terms and rewrites their `local_key` instead of duplicating them.

### Media values: URL or `attachment_id`

Every field that accepts a remote file URL also accepts the `attachment_id` of a file already in the Media Library, for example one uploaded earlier with `POST /media`. It must be a JSON integer, not a string. The one exception is `acf_fields`, where a string of digits (`"123"`) is also read as an `attachment_id`.

With an `attachment_id` the plugin downloads nothing. It only checks that the ID matches an existing attachment and assigns it directly. If the field has a parent post, product or variation, the attachment is also attached to that parent, as with a URL import.

This applies to:

- `image` and `gallery` of `POST /woocommerce/products` and `POST /woocommerce/variant-products`;
- `thumbnail` of `POST /woocommerce/brands` and `POST /woocommerce/categories`;
- `downloads[].file`/`downloads[].url` of `POST /woocommerce/products`;
- `files` of `POST /posts` and `POST /media/link`;
- every ACF field of type `image`/`file` inside `acf_fields`, on any endpoint (top level, or inside a `repeater` or a `group`).

A value that is neither an existing `attachment_id` nor a valid URL is rejected with `400`. The error code depends on the field: `input_invalid` for `files` of `POST /posts` and for `acf_fields`, `invalid_param` everywhere else. In `acf_fields` only string and integer values are checked (see [Handling `acf_fields`](#handling-acf_fields)).

### Remote file imports

These rules apply to every URL the plugin downloads: the fields listed above and `POST /media/link`.

**URL validation.** The value is trimmed and passed through `esc_url_raw()`, then it must pass `wp_http_validate_url()`. A value that fails is not a valid URL.

**Deduplication.** A URL already imported is not downloaded again. The existing attachment is reused, but only while its file still exists on disk. If the file is gone, the stale index entry is dropped and the URL is downloaded again.

- For an **On Page® storage URL** the plugin also extracts the storage segment (`<token>[.<format>]`) and saves it in the `_onpage_file_token` meta. The lookup tries the segment first, then the exact URL. A file renamed on On Page® changes URL but not segment, so it is reused too.
- Only two URL shapes carry a segment, and only on the host `onpage.it` or one of its subdomains:
  - `https://storage.onpage.it/<token>[.<format>]/<name>`;
  - `https://<host>/api/storage/<token>[.<format>]/<name>`, where `<host>` is `onpage.it` itself or a subdomain, for example `https://app.onpage.it/api/storage/…`.
- Every other URL has no segment. It is reused by exact URL only (`_onpage_source_url` meta).

**Allowed file types.** A remote import is always allowed for these extensions, even when the site restricts uploads:

- images: `jpg`, `jpeg`, `jpe`, `gif`, `png`, `bmp`, `tif`, `tiff`, `webp`, `avif`, `heic`, `heif`, `ico`;
- video: `mp4`, `m4v`, `mov`, `qt`, `wmv`, `avi`, `mpeg`, `mpg`, `mpe`, `ogv`, `webm`, `3gp`, `3gpp`, `3g2`, `3gp2`;
- audio: `mp3`, `m4a`, `m4b`, `aac`, `wav`, `ogg`, `oga`, `flac`, `wma`, `mka`;
- documents: `pdf`, `rtf`, Microsoft Office (`doc`, `docx`, `xls`, `xlsx`, `ppt`, `pptx` and their variants), OpenDocument (`odt`, `ods`, `odp`, …), Apple (`key`, `numbers`, `pages`);
- text: `txt`, `csv`, `tsv`;
- archives: `zip`, `7z`, `rar`, `tar`, `gz`, `gzip`.

SVG files are sanitized before import and allowed as `image/svg+xml`. If sanitizing fails, the import fails with `500 request_failed`.

Any other extension (for example `html`, `js` or `exe`) follows the site's normal WordPress upload rules. It is usually rejected with `500 request_failed` and the WordPress message `Sorry, you are not allowed to upload this file type`.

**Public addresses only.** The URL and every redirect must resolve to public IP addresses. The check looks at the host's IPv4 addresses only. Private, loopback, link-local (including the cloud metadata address `169.254.169.254`), shared (`100.64.0.0/10`) and reserved ranges are refused:

- a URL whose host resolves to such an address returns `400 invalid_param`;
- a redirect to such an address fails the download with `500 request_failed`.

The site's own host and hosts allowed by the WordPress filter `http_request_host_is_external` are exempt. With cURL, the checked IPv4 address is also used for the connection, so a DNS answer that changes in between cannot reach an internal host.

**Size limit.** A remote file larger than 512 MB returns `413 file_too_large`, and nothing is imported. A site can change the limit with the `onpage_remote_media_max_bytes` filter (bytes; `0` or a negative value removes it).

**Timeout.** A download times out after 45 seconds. Files in `downloads` of `POST /woocommerce/products` use a 12-second timeout.

### Pagination

Only two endpoints paginate: `GET /posts` and `GET /media`. Every other list endpoint returns all matching items in a single response.

Paginated endpoints accept:

- `?per_page=<n>` — page size. Default `100`, maximum `100`.
- `?page=<n>` — page number. Default `1`.

Invalid values, `0` and negatives fall back to the defaults.

They return two response headers, following the same convention as the WordPress core REST API:

- `X-WP-Total` — total number of items that match the filters, regardless of the page.
- `X-WP-TotalPages` — total number of pages, calculated as `ceil(X-WP-Total / per_page)`.

The client can tell from the current response whether it is on the last page (`page >= X-WP-TotalPages`). There is no need to request one extra page and wait for an empty array.

Example walk-through (250 items, `per_page=100` → 3 pages):

```text
GET /media?page=1&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continue)
GET /media?page=2&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continue)
GET /media?page=3&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (page == X-WP-TotalPages → stop)
```

Client pseudocode:

```text
page = 1
items = []
loop:
  response = GET /media?page={page}&per_page=100
  items += response.body
  if page >= response.headers['X-WP-TotalPages']:
    break
  page += 1
```

On `GET /posts` the headers are **conditional**. The `?id=` and `?title=` branches are lookups, not listings, and return no headers. A missing `X-WP-Total` there means "you are on a lookup branch", not "the list is empty".

An invalid `?id=` (not a positive ID) or an empty `?title=` is ignored. The request then falls through to the paginated listing, with the headers.

#### Endpoints

| Endpoint | Paginated | `per_page`/`page` | `X-WP-Total`/`X-WP-TotalPages` | Notes |
|---|---|---|---|---|
| `GET /posts` | ✅ | ✅ | ✅ | Not paginated when using `?id=` or `?title=`: these always return every match, with no pagination headers. |
| `GET /media` | ✅ | ✅ | ✅ | — |
| `GET /terms` | ❌ | — | — | Always returns every matching term. `taxonomy` is optional: without it every taxonomy is listed. `parent_id`/`parent_lk` filter the list to direct children. |
| `GET /field-groups` | ❌ | — | — | Always returns every field group (`acf_get_field_groups()`). |
| `GET /taxonomies` | ❌ | — | — | Always returns every ACF taxonomy (`acf_get_acf_taxonomies()`). |
| `GET /post-types` | ❌ | — | — | Always returns every ACF post type (`acf_get_acf_post_types()`). |
| `GET /woocommerce/brands` | ❌ | — | — | Always returns every brand. |
| `GET /woocommerce/attributes` | ❌ | — | — | Always returns every attribute (`wc_get_attribute_taxonomies()`). |
| `GET /woocommerce/attributes/{attribute}/terms` | ❌ | — | — | Always returns every term of the attribute. |
| `GET /woocommerce/categories` | ❌ | — | — | Always returns every category. |
| `GET /woocommerce/tags` | ❌ | — | — | Always returns every tag. |
| `GET /woocommerce/products` | ❌ | — | — | `numberposts => -1`, explicitly unlimited. Without a query it returns **all** products. |
| `GET /woocommerce/variant-products` | ❌ | — | — | `numberposts => -1`, explicitly unlimited. Without a query it returns **all** variations. |

#### Why not every endpoint paginates

It depends on how many items each endpoint is expected to return:

- **Paginated:** `posts` and `media` (Media Library) can easily grow to thousands of items on a real site. Pagination avoids heavy queries and huge JSON responses.
- **Not paginated:** terms, taxonomies, field groups and post types are usually small, bounded sets (tens, at most hundreds of items). Returning everything in one response was considered acceptable.

**Notable exception:** WooCommerce products and variations can be as numerous as posts, but they are **not** paginated. A very large catalog can therefore produce heavy responses on `GET /woocommerce/products` and `GET /woocommerce/variant-products`.

## Handling `acf_fields`

These write endpoints accept an `acf_fields` object:

- `POST /posts`
- `POST /woocommerce/products`
- `POST /woocommerce/variant-products`
- `POST /woocommerce/brands`
- `POST /woocommerce/categories`
- `POST /woocommerce/tags`
- `POST /woocommerce/attributes/{attribute}/terms`
- `POST /terms`

They all share the same assignment logic, centralized in `Acf::updateFieldValue`. For each key inside `acf_fields`, the behaviour depends on the ACF field type.

**Field names are case-insensitive.** `POST /field-groups` stores field names through `sanitize_key()`, which lowercases them. A key in `acf_fields` is matched against the exact stored name first, then against its `sanitize_key()` form. So `"SKU"` finds a field stored as `sku`. The same rule applies to the sub-field names inside `repeater` rows and `group` objects.

**Simple fields** (`text`, `textarea`, `select`, `number`, `url`, `email`, …)

- The value is passed to ACF unchanged.

**`image` / `file`**

- The value can be a valid URL string (imported into, or reused from, the Media Library).
- It can also be the `attachment_id` of a file already in the Media Library (e.g. uploaded with `POST /media`). A JSON integer or a string of digits (`"123"`) both work.
- In both cases the field stores the resulting `attachment_id`.
- In the `post` context the attachment is also attached to the parent post. For a URL this happens through the download. For an `attachment_id` there is no download: the attachment is only verified and re-attached.
- To clear the field, pass `null`, an empty string, `0` or `"0"`.
- Any other string or integer returns `400 input_invalid` with the message `Field '<name>' in acf_fields must be an existing attachment ID or a valid URL`. This covers an `attachment_id` that matches no attachment and a malformed URL.
- Only strings and integers are validated. A value of another type (a float, a boolean, an object) is passed to ACF unchanged. Inside a repeater or a group, `<name>` is the path of the sub-field, for example `certifications[0][attachment]` or `datasheet[attachment]`.

**`tab`**

- ACF tabs are UI separators with no value. Keys sent for a `tab` field are silently ignored.
- Tabs do not appear on read (`get_fields()` does not expose them).

**`repeater`**

- The value must be a list of objects, one per row. Each object contains the row's sub-fields. Example: `"certifications": [{"name": "CE marking", "year": 2024}, {"name": "VOC A+"}]`.
- `image`/`file` sub-fields with a URL or `attachment_id` are resolved to an `attachment_id` with the same rules as top-level fields, `400 input_invalid` included.
- Nested `repeater` and `group` sub-fields are supported recursively.
- `tab` sub-fields are ignored.
- A WPML language map must be applied to the whole repeater (`"certifications": {"it": [...rows...], "en": [...rows...]}`), not to individual sub-fields. Language maps inside a row are not supported.
- If a repeater payload is not a list of objects, the endpoint returns `400 invalid_param` with the message `Repeater '<name>' must be a list of rows` or `Repeater '<name>' row N must be an object`.

**`group`**

- The value is an object with the group's sub-fields, for example `"datasheet": {"title": "…", "attachment": 1234}`.
- Sub-fields are handled like the sub-fields of a repeater row: `image`/`file` sub-fields with a URL or `attachment_id` are resolved to an `attachment_id` with the same rules as top-level fields, `400 input_invalid` included (the message names the path, for example `datasheet[attachment]`).
- Nested `repeater` and `group` sub-fields are supported recursively. `tab` sub-fields are ignored. Other sub-field values are passed to ACF unchanged.
- To clear the group, pass `null` or an empty string.
- If the value is not an object (a string, a number, a non-empty list), the endpoint returns `400 invalid_param` with the message `Group '<name>' must be an object`.
- A WPML language map must be applied to the whole group.

**`flexible_content`**

- The value is a list of rows. Each row is an object whose `acf_fc_layout` is the name of one of the field's layouts, plus the sub-fields of that layout, for example `"blocks": [{"acf_fc_layout": "hero", "title": "…", "image": "https://…"}, {"acf_fc_layout": "quote", "text": "…"}]`.
- Sub-fields are resolved against the row's layout like the sub-fields of a repeater row: `image`/`file` URLs or `attachment_id`s, nested `repeater`, `group` and `flexible_content`.
- The endpoint returns `400 invalid_param` if the value is not a list (`Flexible content '<name>' must be a list of rows`), a row has no `acf_fc_layout`, or the layout is unknown (`Flexible content '<name>' row N has unknown layout '<layout>'`).
- To clear the field, pass `null` or an empty string. A WPML language map must be applied to the whole field.

**`group` whose sub-fields all have short names**

- A group such as `{"lat": 45.1, "lng": 9.2}`, `{"sku": …, "ean": …}` or `{"url": …, "alt": …}` is written as sent. Its keys are not language codes, so it is not read as a language map.
- A key counts as a language code only when it is an active WPML language or an ISO 639-1 code (for example `en`, `it`, `pt-br`). See [How a language map is detected](dev/integration-guide.md#how-a-language-map-is-detected).
- The exception is a group whose sub-field names are **all** two-letter language codes (for example `{"id": …}`). It is read as a language map: give it another sub-field, or rename the sub-field.

### Language maps inside `acf_fields`

Any value in `acf_fields` can be sent as a WPML language map `{ "<lang>": <value> }`. Each language is resolved independently. The value for a language can be a **string**, a **number**, **`null`/`""`**, a **list of objects** (repeater) or an **object** (group). Different languages in the same map can have different types.

```json
"acf_fields": {
  "product_subtitle": { "it": "Sottotitolo", "en": null },
  "certifications": {
    "it": [ { "name": "Marcatura CE", "year": 2024 } ],
    "en": [ { "name": "CE marking", "year": 2024 } ]
  }
}
```

Consistency with the ACF type is still checked after the language is resolved:

- For a `repeater` field, the resolved value of every language must still be a list of objects. A single object or a string for that language returns `400 invalid_param`.
- For `image`/`file`, the URL → `attachment_id` conversion applies.
- `tab` fields are still ignored.
- On `POST /posts`, the `acf_fields`, `files` and `terms` of each translation are written in that translation's WPML language context, on insert as on update. Term slugs therefore resolve to the term of the translation's language.

The language map always goes at field level (or on the whole repeater/group), never on individual sub-fields.

On read (GET), repeaters are returned natively by `\get_fields()` as lists of objects. Tabs do not appear.

### Value shapes by field type

Except for `image`, `file`, `repeater`, `group`, `flexible_content` and `tab` (described above), the plugin passes the value to ACF as it is. It does not convert or validate it. Send the shape ACF stores for that field type. The table lists the common types; the ACF documentation is the reference.

| ACF type | Value to send | Example |
| --- | --- | --- |
| `text`, `textarea`, `wysiwyg`, `email`, `url`, `password` | a string | `"CE marking"` |
| `number`, `range` | a number | `49.9` |
| `true_false` | `1` or `0` | `1` |
| `select` | one choice value; a list of values when the field allows multiple values | `"red"`, `["red", "blue"]` |
| `radio`, `button_group` | one choice value | `"red"` |
| `checkbox` | a list of choice values | `["wifi", "bluetooth"]` |
| `date_picker` | a string in `Ymd` format | `"20260929"` |
| `date_time_picker` | a string in `Y-m-d H:i:s` format | `"2026-09-29 14:30:00"` |
| `time_picker` | a string in `H:i:s` format | `"14:30:00"` |
| `color_picker` | a hex color string | `"#c0392b"` |
| `link` | an object with `title`, `url` and `target` | `{"title": "Datasheet", "url": "https://…", "target": "_blank"}` |
| `gallery` | a list of `attachment_id`s | `[812, 813]` |
| `post_object`, `page_link` | a WordPress post ID; a list of IDs when the field allows multiple values | `321` |
| `relationship` | a list of WordPress post IDs | `[321, 322]` |
| `taxonomy` | a WordPress term ID, or a list of term IDs | `[10, 11]` |
| `user` | a WordPress user ID | `1` |

Choice values are the keys of the field's `choices`, not their labels.

**Relational fields take WordPress IDs, not `local_key`s.** The plugin does not resolve a `local_key` inside `post_object`, `relationship`, `page_link` or `taxonomy`. Look the IDs up first:

- posts: `GET /posts?local_key=<key>&type=<type>`, or the IDs returned by `POST /posts`;
- terms: `GET /terms?taxonomy=<taxonomy>`, which returns each term with its `local_key`.

So the related objects must be imported before the objects that point at them.

**A `gallery` is not imported from URLs.** Upload or link each file first (`POST /media`, or a URL in a `files` slot), then send the list of `attachment_id`s.

**Clearing a field.** Send `null` or `""` for a single value, and `[]` for a list (`checkbox`, `gallery`, `relationship`, a multiple `select`). `image` and `file` also accept `0` or `"0"`. A field left out of `acf_fields` is not changed.

## Error format

Errors are returned as `WP_Error` using the standard WordPress REST shape:

```json
{
  "code": "duplicate_title",
  "message": "Post :: Element 0 :: Title 'Example' already exists for PostType 'news'",
  "data": {
    "status": 409
  }
}
```

In this document, errors are written as `<status> <code>`, for example `400 invalid_param`.

Most messages name the element with `Element N`: the 0-based position of the element in the request array, as in the `409 duplicate_title` above.

An unexpected PHP error or exception during a request (for example one thrown by WooCommerce or WordPress) is returned in the same shape as `500 request_failed`, with the original message.

## Field Groups

### GET `/field-groups`

Returns all ACF field groups.

**Pagination:** none. The response always contains **all** field groups; there is no `per_page`/`page`.

Response `200`:

```json
[
  {
    "ID": 123,
    "key": "group_example",
    "title": "Example Group"
  }
]
```

### POST `/field-groups`

Creates or updates one or more ACF field groups.

Body:

```json
[
  {
    "title": "Product Fields",
    "key": "group_product_fields",
    "locations": [
      {
        "param": "post_type",
        "operator": "==",
        "value": "post_product"
      }
    ],
    "description": "Extra fields for products",
    "fields": [
      {
        "key": "sku",
        "label": "SKU",
        "name": "SKU",
        "type": "text"
      },
      {
        "key": "price",
        "label": "Price",
        "name": "Price",
        "type": "number"
      }
    ]
  }
]
```

Group fields:

| Field | Required | Description |
| --- | --- | --- |
| `title` | yes | Group title. Missing, empty or not a string → `400 missing_title`. |
| `key` | no | ACF group key (convention `group_...`). If missing, it is generated from `title` (`group_` + title slug). |
| `locations` | no | ACF location rules, as a flat list of `{param, operator, value}` objects. The list is **one** ACF rule group: all its rules must match (AND). See [Location rules](#location-rules). |
| `description` | no | Group description. |
| `fields` | no | List of fields (see below). If omitted or `null`, the group's existing fields are left unchanged. `[]` removes them all. A value that is not a list returns `400 invalid_param`. |
| `active` | no | Whether the group is active. Default `true`. |
| `position` | no | ACF position. Default `normal`. |
| `style` | no | ACF style. Default `default`. |
| `label_placement` | no | Default `top`. |
| `instruction_placement` | no | Default `label`. |
| `hide_on_screen` | no | List of screen elements to hide. Default `[]`. |
| `menu_order` | no | Integer. Default `0`. |

Upsert rules:

- If a field group with the same `key` **or** the same `title` already exists, it is updated instead of creating a new one.
- When `key` is not sent and a group with that `title` exists, its existing key is reused (it is not changed).
- On update, when `fields` is sent, the group's fields are replaced by the payload. Fields with the same technical key keep their internal ACF field key. Fields no longer present are removed from the field group. When `fields` is omitted, only the group's own settings (title, description, locations…) are updated.
- The group's own settings are always rewritten from the payload. A setting that is omitted, `locations` included, goes back to its default (for `locations`: no rules).

Per-field rules:

- `key` is the technical ACF key, saved as the field name. It is the key to use in the `acf_fields` objects of `POST /posts`, `POST /woocommerce/products` and the term endpoints.
- `name` and `label` are descriptive. If `label` is missing, `name` is used.
- If `key` is missing, `name` is used as the technical key (backward compatibility).
- Every field is sanitized at least for `key`, `label`, `name` and `type`.
- `type` defaults to `text`.
- A field that is not an object returns `400 invalid_param` with the message `FieldGroup :: Field N must be an object`.
- `repeater` and `group` fields take their children in `sub_fields`.
- `flexible_content` fields take a list of `layouts`. Each layout has a `name` (required; `key` is used when `name` is missing), an optional `label`, `display` (`block`, `table` or `row`; default `block`), `min`, `max`, and its own `sub_fields`. A layout keeps its internal ACF key across updates, matched by `name`, so saved values stay attached. A layout no longer sent is removed with its sub-fields. `layouts` that is not a list, or a layout without a `name` or `key`, returns `400 invalid_param`.

If WPML is active, the group is flagged with an ACFML translation mode.

#### Location rules

- `locations` is a flat list. It becomes a single ACF rule group, so every rule in it must match (AND). There is no way to send alternatives (OR) in one group.
- For the same fields on two targets, create two field groups. For example, a field on both `product` and `product_variation` needs one group with `post_type == product` and one with `post_type == product_variation`. A single group with both rules would match nothing.
- The fields of the group are accepted in `acf_fields` of every post type or taxonomy the rules can match: `==` and `!=` on `post_type` or `taxonomy`, the value `all`, and post rules without a post type (`post_template`, `post_category`, …, matching every post type) or page rules (`page_template`, …, matching `page`).
- A group without `locations` is saved (`200`), but its fields match nothing. Writing them later fails with `400 invalid_param` (`ACF field '<name>' not found for <context> '<target>'`).

Example: a field group on a taxonomy, then a term that fills it.

```json
[
  {
    "title": "Collection Fields",
    "key": "group_collection_fields",
    "locations": [
      { "param": "taxonomy", "operator": "==", "value": "collection" }
    ],
    "fields": [
      { "key": "season", "label": "Season", "type": "text" }
    ]
  }
]
```

Then `POST /terms`:

```json
[
  {
    "taxonomy": "collection",
    "local_key": 501,
    "name": "Summer",
    "acf_fields": { "season": "2026" }
  }
]
```

Response `200`:

```json
[123, 124]
```

Main errors:

- `400 missing_title`
- `400 invalid_param` if the body is not a JSON array, an element is not a non-empty object, `fields` is not a list, a field is not an object or has no `key`/`name`, or a layout is malformed
- `500 acf_error`
- `500 delete_failed`

### DELETE `/field-groups`

Deletes field groups by numeric ID or by title.

Body:

```json
[123, "Product Fields"]
```

Optional query:

```text
?ignore=1
```

- An integer is a field group ID. A string is an exact field group title.
- Any other element (`null`, a float, an object, a list) returns `400 input_invalid` with the message `FieldGroup :: Element N :: Invalid delete value; expected field group ID (int) or title (string)`.
- With `?ignore`, an ID or title that matches nothing is skipped.

Response `200`:

```json
null
```

Main errors:

- `400 invalid_param` if the body is not a JSON array
- `400 input_invalid` for an element that is neither an integer nor a string
- `404 not_found` if an ID or title does not exist and `ignore` is not set
- `500 delete_failed`

## Post Types

### GET `/post-types`

Returns all registered ACF post types. The response is the raw output of `acf_get_acf_post_types()`: one ACF post type definition per item, with ACF's own keys. The plugin does not reshape it.

**Pagination:** none. The response always contains **all** registered post types; there is no `per_page`/`page`.

### POST `/post-types`

Creates or updates one or more ACF post types (upsert by the `post_type` key).

Body:

```json
[
  {
    "post_type": "product",
    "singular_label": "Product",
    "plural_label": "Products",
    "hierarchical": false,
    "icon": "dashicons-cart",
    "supports": ["title", "editor", "thumbnail", "revisions"],
    "taxonomies": ["brand"],
    "rewrite_slug": "catalog/products"
  }
]
```

| Field | Required | Default | Notes |
| --- | --- | --- | --- |
| `post_type` | yes | | Must already be a valid key: lowercase letters, digits, `_` or `-`. See below. |
| `singular_label` | yes | | Non-empty string. |
| `plural_label` | yes | | Non-empty string. |
| `hierarchical` | no | `false` | |
| `icon` | no | `dashicons-admin-post` | |
| `supports` | no | `["title", "editor", "thumbnail", "revisions"]` | |
| `taxonomies` | no | `[]` | |
| `rewrite_slug` | no | none | Custom WordPress permalink slug. Only a non-empty string sets it. Any other value (`null`, `""`, a number, omitted) sets no custom slug. |

Behaviour:

- **Key validation.** `post_type` must be non-empty and must not change under `sanitize_key()`. A value with uppercase letters, spaces or accents (e.g. `"Product"`) returns `400 invalid_param` with the message `PostType :: Parameter 'post_type' must be a non-empty key of lowercase letters, digits, '_' or '-', got 'Product'`. This keeps the ACF key and the registered post type identical.
- **Required labels.** A missing or empty `singular_label` or `plural_label` returns `400 invalid_param` with the message `PostType :: Element N :: Parameter 'singular_label' is required` (or `'plural_label'`).
- **Upsert.** If a post type with the same key exists, its values are updated; otherwise it is created. A duplicate never returns an error.
- **Payload as source of truth.** Every call fully overwrites the ACF fields. Optional properties missing from the payload go back to their default. For example, a previously set `rewrite_slug` is removed if the key is no longer sent.
- The ACF key of the post type is the value of `post_type`.
- The permalink slug is `rewrite_slug` when it is a non-empty string. Otherwise no custom slug is set, and the permalink uses the post type key (`post_type`) as WordPress does by default. No `post_` prefix is added.
- `flush_rewrite_rules()` runs once per batch.

Response `200`:

```json
[45]
```

Main errors:

- `400 invalid_param` if the body is not a JSON array, an element is not a non-empty object, `post_type` is not a valid key, or `singular_label`/`plural_label` is missing or empty
- `500 acf_error`

### DELETE `/post-types`

Deletes post types by ACF ID or by string key.

Body:

```json
[45, "product"]
```

Optional query:

```text
?ignore=1
```

Notes:

- An integer is an ACF post type ID. A string is a post type key; it is sanitized with `sanitize_key`.
- Any other element returns `400 input_invalid` with the message `PostType :: Element N :: Invalid delete value; expected post type ID (int) or key (string)`.
- With `?ignore`, an ID or key that matches nothing is skipped.
- `flush_rewrite_rules()` runs once per batch, also when the batch fails.

Response `200`:

```json
null
```

Main errors:

- `400 invalid_param` if the body is not a JSON array
- `400 input_invalid` for an element that is neither an integer nor a string
- `404 not_found` if an ID or key does not exist and `ignore` is not set
- `500 delete_failed`

## Posts

### GET `/posts`

Returns generic posts, with optional filters useful for sync and mapping. It also supports **exact-title** search (`title` parameter), which can be scoped to a post type with `?type=`.

**Pagination:** yes, through `per_page`/`page` and the `X-WP-Total`/`X-WP-TotalPages` headers (details below). **Exception:** `?id=` and `?title=` always return every match, without pagination.

Optional query:

```text
?id=321
?type=product
?local_key=1001
?updated_after=2026-05-14T10:00:00Z
?status=publish
?status=trashed
?page=1
?per_page=100
?title=My post
?title[it]=Chi siamo&title[en]=About us
```

| Parameter | Description |
| --- | --- |
| `id` | Returns the single matching post (as a one-element list), trashed posts included. A missing post returns `404 no_post`. `id` wins over every other filter, `title` included. An invalid value is ignored. |
| `type` | Exact post type. Default `any`. In the normal listing an unknown `type` is not an error (empty list). Combined with `title`, an unknown `type` returns `404 not_found`. |
| `local_key` | Filters on the `onpage_local_key` post meta. The `status` filter still applies, so trashed posts are left out unless `status=trashed`. An invalid value (empty, `0`) is ignored. |
| `status` | Default `any`, which **excludes** trashed posts and auto-drafts. `trashed` (alias of the WP status `trash`) returns **only** trashed items. Any other value (`publish`, `draft`, `pending`, `private`, …) is passed to WordPress unchanged. |
| `updated_after` | Filters on `post_modified_gmt`. A value with `Z` or an offset (`2026-05-14T12:00:00+02:00`) is converted to UTC. A value without a timezone is read as UTC. An invalid date/time returns `400 invalid_param`. The bound is inclusive: a post modified exactly at that time is returned. |
| `per_page` | Default `100`, maximum `100`. An invalid value is ignored (default used). |
| `page` | Page number. An invalid value is ignored (page `1`). |
| `title` | Exact-title search (see below). |

The normal listing is ordered by modification date, newest first (`modified DESC`). With WPML active it only contains posts in the current request language, and `X-WP-Total` counts only those.

**Pagination headers** (normal listing only, not with `id`/`title`):

- `X-WP-Total`: total number of posts matching the filters, regardless of the page.
- `X-WP-TotalPages`: total number of pages, `ceil(X-WP-Total / per_page)`.
- The client knows from the current response whether it is on the last page (`page >= X-WP-TotalPages`). There is no need to request an extra page and wait for an empty array.

Walk-through (250 posts, `per_page=100` → 3 pages):

```text
GET /posts?page=1&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continue)
GET /posts?page=2&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continue)
GET /posts?page=3&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (page == X-WP-TotalPages → stop, no request for page=4)
```

**Search by `title`:**

- `title` performs an **exact-title** search. It accepts two forms:
  - **string** (`?title=My post`): searches in the current language of the request;
  - **language map** (`?title[it]=Chi siamo&title[en]=About us`): searches each title in the context of its WPML language and returns the **union** of the results.
- The search is scoped to the given `type` (validated; `404 not_found` if it does not exist). Without `type`, it searches every post type.
- The response is **always a list** with **one object per distinct translation group**. The representative is the default-language post when available, and it carries the `translations` map. Titles that are translations of the same post → one object. Titles from different groups → several objects. This mirrors `GET /woocommerce/products?name=`.
- When `title` is present, the other filters (`local_key`, `updated_after`, `status`, `page`, `per_page`) do not apply.

Response `200`:

```json
[
  {
    "id": 321,
    "title": "My post",
    "content": "Body",
    "type": "product",
    "status": "publish",
    "local_key": 1001,
    "translations": {
      "it": 321,
      "en": 322
    },
    "acf_fields": {
      "sku": "ABC123"
    },
    "terms": []
  }
]
```

- `local_key` is always present: an integer, a string, or `null` when the post has no key (see [`local_key`](#local_key)).
- `terms` lists the post's terms as WordPress term objects (`term_id`, `name`, `slug`, `taxonomy`, `parent`, `count`, …).
- `translations` maps each language code to the ID of the post in that language (WPML translation group). Without WPML it is an empty map. It is present in every post response (single, list and `title` search).

> **Note:** there is no `GET /post-types/{post_type}/posts` endpoint. To list or search the posts of a specific post type, use `GET /posts?type={post_type}` (add `&title=...` for exact-title search).

### GET `/posts/{id}`

Returns a single post.

**Pagination:** not applicable (returns one object, not a list).

Optional query:

| Parameter | Description |
| --- | --- |
| `keyfield` | `id` (default) or `local_key`. Any other value returns `400 invalid_keyfield`. |
| `type` | Only with `keyfield=local_key`: restricts the lookup to a post type, to avoid ambiguity across post types. |

- With `keyfield=id`, `{id}` is the numeric post ID. A value that is not a positive ID returns `404 no_post`.
- With `keyfield=local_key`, `{id}` is the `local_key` value (an invalid value returns `400 invalid_param`).
- With `keyfield=local_key`, if several posts share the key (WPML translations), the post in the group's **default language** is returned. The other languages are in the `translations` map.
- With `keyfield=local_key`, trashed posts are left out, as in `GET /posts?local_key=`: a `local_key` held only by trashed posts returns `404 no_post`. (Upserts and deletes by `local_key` still see the trash; see [Trashed posts](#trashed-posts).) With `keyfield=id` a trashed post is returned; check `status` in the response.
- Without `type`, a `local_key` held by posts of more than one post type returns `409 ambiguous_local_key`.

Example:

```bash
curl -X GET \
  "https://example.com/wp-json/onpage/v1/posts/321"
```

Response `200`:

```json
{
  "id": 321,
  "title": "My post",
  "content": "Body",
  "type": "product",
  "status": "publish",
  "local_key": 1001,
  "translations": {
    "it": 321,
    "en": 322
  },
  "acf_fields": {
    "sku": "ABC123"
  },
  "terms": [
    {
      "term_id": 10,
      "taxonomy": "brand",
      "name": "Acme",
      "slug": "acme"
    }
  ]
}
```

The `terms` entries are abridged: they are full WordPress term objects.

Errors:

- `400 invalid_keyfield` if `keyfield` is neither `id` nor `local_key`
- `400 invalid_param` if `keyfield=local_key` and `{id}` is not a valid `local_key`
- `404 no_post` if no post matches
- `409 ambiguous_local_key` if `keyfield=local_key`, no `type` is given and the key exists on several post types

### POST `/posts`

Creates or updates posts in batch.

How the target post is resolved:

1. If the element has a valid `id`, that post is updated (`id` takes precedence, as in `POST /woocommerce/products`). The post must exist, otherwise `404 no_post`. An invalid `id` (not a positive ID) is silently ignored, and the element falls through to step 2.
2. Otherwise the upsert is driven by `local_key`: if the `local_key` already exists (on a post of `type`, when sent), that post is updated. Trashed posts count: see [Trashed posts](#trashed-posts).
3. Otherwise a new post is created.

#### Base payload

```json
[
  {
    "type": "product",
    "title": "Red Chair",
    "content": "Description",
    "status": "publish",
    "local_key": 1001,
    "files": {
      "image": "https://cdn.example.com/red-chair.jpg"
    },
    "acf_fields": {
      "sku": "CHAIR-RED",
      "price": 49.9
    },
    "terms": {
      "brand": ["acme"],
      "collection": ["summer"]
    }
  }
]
```

| Field | Required | Notes |
| --- | --- | --- |
| `type` | on insert | Used as the exact post type; no `post_` prefix is added. Missing or empty on insert → `400 invalid_param`; not registered → `404 not_found`. On update it can be omitted; if sent, it must equal the post's current type (`400 invalid_param` otherwise). |
| `title` | on insert | A non-empty string or a WPML language map. Missing or empty on insert → `400 invalid_param`, and so is a map with no non-empty title in an active language. On update an empty string is accepted. |
| `content` | no | |
| `description` | no | Saved as the post excerpt (`post_excerpt`). GET responses do not return it. |
| `status` | no | Default `draft` on insert. Must be a registered post status (`publish`, `draft`, `pending`, `private`, `future`, or a custom one); `trash`, `auto-draft`, `inherit` or any other value returns `400 invalid_param`. |
| `local_key` | yes | Always required, also when updating by `id`. |
| `files` | no | Map `acf_field_name => URL or attachment_id`. `null` or `""` as a value does not clear the field: it returns `400 input_invalid`. |
| `acf_fields` | no | See [Handling `acf_fields`](#handling-acf_fields). |
| `term` | no | Term assignments per taxonomy. |
| `terms` | no | Alias of `term`. When both are sent, `term` wins and `terms` is ignored. |

Supported `term`/`terms` formats:

- `<taxonomy_slug> => [term_slug, ...]`
- `<taxonomy_slug> => [lang => term_slug|[term_slug, ...]]`
- `<taxonomy_slug> => [[lang => term_slug, ...], ...]`

#### Insert behaviour

- `type`, `title` and `local_key` are required. `type` must be a registered post type (`404 not_found` with the message `Post :: PostType '<type>' not found`).
- `title` must be a non-empty string, or a language map with a non-empty title in at least one active language. Otherwise the request returns `400 invalid_param` with the message `Post :: Element N :: Parameter 'title' is required`. For example, `{"title": {"fr": "Chaise"}}` on a site without `fr` is rejected, and so is `{"title": {"en": null}}`.
- The title must not already exist in the same post type (see `409 duplicate_title` below for the exact rule).
- A `local_key` that already exists never reaches the insert: the element updates that post instead (see above).
- If anything fails after the post was created, the post and every translation created so far are deleted before the error is returned.
- **`files`:**
  - Each value must be a valid URL reachable by WordPress, or the `attachment_id` (JSON integer) of a file already in the Media Library (e.g. uploaded with `POST /media`).
  - Each remote file is downloaded, imported into the Media Library and attached to the new post.
  - An `attachment_id` is assigned directly, without download, and re-attached to the post as parent.
  - The field named in `files` stores the WordPress `attachment_id` of the imported or given media.
- **`acf_fields` of type `image`:**
  - A valid URL is imported as remote media, and the field stores the resulting `attachment_id`.
  - `null`, an empty string, `0` or `"0"` leaves the field without an image.
- **`terms`:**
  - Terms are assigned by reference. Each reference (e.g. `123`, `"SKU-ABC"`) is first looked up as a `local_key`, otherwise it is treated as a slug. Unlike products, slugs are supported here (posts accept an integer/string local_key **or** a slug).
  - For multilingual payloads you can pass a per-language map, for example `{"manufacturer":{"en":"ford-en","it":"ford-it"}}`.
  - A list of language maps is also supported, for example `{"manufacturer":[{"en":"ford-en","it":"ford-it"}]}`.
  - If a term is not found by `local_key` or slug, the request fails with `404`.

#### Update behaviour

- `local_key` is required (also when updating by `id`). It is written on the resolved post and propagated to **all its WPML translations**.
- Resolution order:
  - if the payload has an `id`, that post is updated (`404 no_post` if the `id` does not exist);
  - otherwise the post is resolved through `local_key`, and the WordPress ID is looked up internally. Without `type`, a key held by posts of several post types returns `409 ambiguous_local_key`.
- **Explicit `id` and `local_key`.** With an `id`, the `local_key` must not be held by another post of the same type outside this post's WPML translation group. Otherwise the request returns `409 duplicate_local_key` with the message `Post :: Element N :: local_key '<key>' already exists for PostType '<type>'`. Without `id` there is no such check: the key is what identifies the post.
- **`type` cannot change.** `type` identifies the post; it does not retype it. A `type` different from the post's current type returns `400 invalid_param` (`… the post type cannot be changed`).
- Any of `type`, `title`, `content`, `description`, `acf_fields`, `files`, `terms` or `status` missing from the payload is left unchanged.
- There is no rollback on update. If a step fails (for example an ACF field or a file), the changes already written stay.
- With WPML, a language of the payload that the post's translation group does not have yet is created as a new translation. Before that, group slots whose post no longer exists are removed. If the translation group cannot be resolved, the request returns `500 wpml_error`.
- If `title` changes, it is checked for uniqueness against other posts of the same type.
- The slug (`post_name`) is never sent or changed by the plugin. WordPress generates it from the title when the post is first published. A later change of `title` keeps the old slug.
- `acf_fields` updates only the fields sent.
- `files` updates only the fields sent.
- ACF `image` fields received as URLs are imported or reused as attachments and saved as `attachment_id`.
- An ACF `image` field set to `null`, an empty string, `0` or `"0"` is cleared.
- If a URL in `files` was already imported by the plugin, the same attachment is reused and re-attached to the current post. If the remote file is not yet in the Media Library, it is downloaded and a new attachment is created.
- An `attachment_id` in `files` is verified and re-attached to the current post, without download.
- `terms` overwrites the assignments for the taxonomies sent.
- In the multilingual `terms` format, the terms of the current post/translation language are used.

#### Trashed posts

A trashed post still owns its `local_key`. Every `local_key` lookup of this endpoint includes the trash. (Reads do not: `GET /posts/{id}?keyfield=local_key` and `GET /posts?local_key=` leave trashed posts out.)

- Re-importing a trashed element updates it instead of creating a second post with the same key.
- A live post wins: when both a live post and a trashed post hold the key, the live post is updated and the trashed one stays in the trash.
- The post is restored (`wp_untrash_post()`) together with its trashed translations that hold the same key, then the payload is applied.
- The restore happens after a first set of checks: the post type exists, WPML is available for language maps, the title is not a duplicate and `type` matches the post. A request rejected by these checks (for example `409 duplicate_title`) leaves the post in the trash.
- `acf_fields`, `files` and `terms` are validated only after the restore. A request that fails there (for example `400 input_invalid` on a file) leaves the post restored, because the update path has no rollback.
- The payload `status` then applies. Without `status`, the post keeps the status WordPress restores it to, which is `draft`.
- A trashed post of another translation group that holds the same key stays in the trash.
- A failed restore returns `500 request_failed`.

#### Multilingual support with WPML

`title`, `content`, `description`, `terms` and every value in `acf_fields` and `files` can be sent as a per-language map:

```json
[
  {
    "type": "product",
    "title": {
      "it": "Sedia Rossa",
      "en": "Red Chair"
    },
    "content": {
      "it": "Descrizione IT",
      "en": "English description"
    },
    "acf_fields": {
      "subtitle": {
        "it": "Sottotitolo",
        "en": "Subtitle"
      },
      "sku": "CHAIR-RED"
    }
  }
]
```

`files` also supports the per-language format:

```json
[
  {
    "type": "product",
    "title": {
      "it": "Sedia Rossa",
      "en": "Red Chair"
    },
    "files": {
      "image": {
        "it": "https://cdn.example.com/it/chair.jpg",
        "en": "https://cdn.example.com/en/chair.jpg"
      }
    }
  }
]
```

Rules:

- If `title`, `content`, `description`, `acf_fields`, `files` or `terms` contain multilingual values and WPML is not installed or active, the request returns `500 wpml_required`.
- On insert, the plugin creates the base post in the WPML default language, then the translations. When `title` is sent only per language and the default language is not among them, the base post is created in the first language that has a title instead, so no post is created in a language the payload did not send. Terms and WooCommerce products follow the same rule.
- On update, the plugin updates the current post and its linked translations. A language map writes only the languages it contains: `{"title": {"en": "Red Chair"}}` renames the English translation and leaves the Italian title as it is. The same applies to `content`, `description` and every value in `acf_fields` and `files`. A shared (non-map) value is still written to every language. A map with no active language, for example `{"title": {"es": "Silla"}}` on an it/en site, writes no language: every title stays as it is.
- Only language maps create translations. A payload with scalar values only creates or updates a single post. To create a translation with the same text, repeat the value in the map, for example `{"en": "Chair", "it": "Chair"}`.
- Language maps in `terms` only choose the term for each translation. They do not create translations.
- If `title` is a string and other fields are multilingual, the same title is used unchanged for every translation. For different titles per language, use a WPML map.
- When `files` is multilingual, each translation receives its own `attachment_id`s in the target fields.
- When a translation is created and its title is missing from the map, the shared title or the fallback language is used, without automatic suffixes. An existing translation keeps its title instead.
- For multilingual `terms`, terms are resolved in the target language through WPML.
- On insert, the translations are written in their own WPML language: term slugs and `acf_fields` resolve in the language of each translation, not in the default language.

Response `200`:

```json
[321, 322]
```

The response lists one ID per element: the post in the base language. Translation IDs are not listed. With an explicit `id`, the response holds that `id`, even when it is a translation.

Main errors:

- `400 invalid_param` when:
  - the body is not a JSON array, or an element is not a non-empty object;
  - `local_key` is missing or invalid;
  - `status` is not a registered post status, or is `trash`, `auto-draft` or `inherit`;
  - `type` or `title` is missing on insert;
  - `type` differs from the type of the post being updated;
  - an `acf_fields` key is not an ACF field of the post type (`ACF field '<name>' not found for post '<type>'`);
  - a repeater, group or flexible content value is malformed.
- `400 input_invalid` if a value in `files` or an `image`/`file` value in `acf_fields` is neither an existing `attachment_id` nor a valid URL
- `404 no_post` if the `id` sent does not exist
- `404 not_found` if `type` is not a registered post type
- `404 input_invalid` if a referenced term does not exist
- `409 ambiguous_local_key` if no `type` is sent and the `local_key` exists on several post types
- `409 duplicate_local_key` with an explicit `id`, if the `local_key` belongs to another post of the same type
- `409 duplicate_title` if the title already exists on an object **without a `local_key`**, outside this element's translation group. An object carrying a different `local_key` is another On Page® element and does not conflict, so two elements with the same title are both imported.
- `500 acf_error` if ACF refuses to save a field
- `413 file_too_large` if a remote file is over the [size limit](#remote-file-imports)
- `500 request_failed`, also when `acf_fields`, `files`, `term` or `terms` is not a JSON object or array (for example a string)
- `500 wpml_error` if WPML cannot resolve the translation group (trid) of the post
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active

### DELETE `/posts`

Deletes posts by ID or by `local_key`, or deletes all posts of a given post type.

Body:

```json
[321, "product", {"local_key": 1001, "type": "product"}]
```

Optional query:

```text
?ignore=1
?keyfield=local_key&type=product
```

Accepted body elements (default mode):

| Element | Effect |
| --- | --- |
| integer | Deletes the post with that ID. `0` or a negative integer matches no post: `404 not_found`, or skipped with `?ignore`. |
| string | Interpreted as an exact post type: **all posts of that type** are deleted, in every WPML language. |
| `{"local_key": …, "type": …}` | Deletes by `local_key`; `type` is optional (falls back to the `type` query parameter). |
| `{"id": …}` | Deletes the post with that ID. `id` must be a positive JSON integer. |
| `{"type": …}` | Deletes all posts of that type, but only when the object has neither a `local_key` nor an `id`. |

Notes:

- To delete by `local_key` (integer or non-empty string) with plain values, use `?keyfield=local_key`. `type` is optional but recommended. In this mode every element must be a valid `local_key` (or a `local_key` object), otherwise `400 input_invalid`. A boolean is not a valid `local_key`: `[true]` is rejected, it does not delete the post with `local_key` `"1"`.
- Alternatively, use objects with `local_key` and `type` without changing `keyfield`.
- An object never falls back to `type` because of a bad identifier. It returns `400 input_invalid` instead:
  - when `local_key` is present (not `null`) but invalid (`""`, `"0"`, a boolean, an object…): `Post :: Element N :: Parameter 'local_key' must be a positive integer or a non-empty string`;
  - in the default mode, when `id` is present (not `null`) but not a positive JSON integer (`"12"`, `0`, `1.5`…): `Post :: Element N :: Parameter 'id' must be a positive JSON integer`.
- Any other element (`null`, a float, a list, an object with none of `local_key`, `id` or `type`) returns `400 input_invalid` with the message `Post :: Element N :: Invalid delete value; …`.
- Any other `keyfield` value (besides `local_key` and the default `id_or_post_type`) returns `400 invalid_keyfield`.
- If a `local_key` not filtered by `type` matches several post types, the request returns `409 ambiguous_local_key`.
- Deletion is permanent (`wp_delete_post($id, true)`), not a move to the trash.
- Trashed posts are included: a `local_key` or a post type also deletes the matching posts that are in the trash.
- A `local_key` deletes every post that holds it, so the whole WPML translation group. With the WPML option that deletes translations together with the original, members already removed that way are skipped, not reported as errors. The same applies to the terms deleted by `DELETE /taxonomies`.
- With `?ignore`: a missing ID or `local_key` is skipped, and so is a post type that is not registered: nothing is deleted for it. Without `ignore` an unregistered post type returns `404 not_found`.

Response `200`:

```json
null
```

Main errors:

- `400 invalid_param` if the body is not a JSON array
- `400 input_invalid` for an element of an unsupported type, or an object with an invalid `local_key` or `id`
- `400 invalid_keyfield`
- `404 not_found` if an ID, `local_key` or post type does not exist and `ignore` is not set
- `409 ambiguous_local_key`
- `500 request_failed` if WordPress fails to delete a post

## WooCommerce

For WooCommerce-specific background see [dev/woocommerce.md](dev/woocommerce.md).

Every `/woocommerce/*` endpoint, on every method (`GET`, `POST` and `DELETE`), returns `500 woocommerce_required` when WooCommerce is not active.

### Shared behaviour of term list endpoints

The list endpoints for brands, categories, tags and attribute terms share the rules below. Each endpoint section states which of them apply.

**Lookup filters (`id`, `local_key`, `slug`)**

- `id` returns the term with that WordPress ID, as a one-element list. An ID that is not a term of this taxonomy returns `404 not_found`. A malformed `id` (not a positive ID) is ignored, and the next filter applies.
- `local_key` returns every term that holds the key. WPML translations are **not** grouped: each translation is a separate item.
- `slug` returns zero or one term.

**Exact-name search (`name`)**

- `name` performs an **exact-name** search on the term. It accepts two forms:
  - **string** (`?name=Ford`): searches in the current language of the request;
  - **language map** (`?name[it]=Ford&name[en]=Ford`): searches each name in the context of its WPML language and returns the **union** of the results.
- The response is **always a list** with **one object per distinct translation group** (the default-language term is the representative), carrying the `translations` map. Names that are translations of the same term → one object. Names from different groups → several objects. This mirrors `GET /woocommerce/products?name=`.

**Parent filters (`parent_id`, `parent_lk`)**

- `parent_id` (WP term ID) and `parent_lk` (the parent's local_key) return the **direct children** of the given parent.
- `parent_lk` is expanded to **all WPML translations** of the parent, and children are searched under each of them. Per-language visibility of the children follows the same rules as the normal listing.
- The two parameters are **mutually exclusive** (`400` if both are sent).
- A `parent_lk` that resolves to no term returns an empty list.
- Filter precedence: `id` > `local_key` > `slug` > `name` > `parent_*`.

**`translations`**

- `translations` maps each language code to the term ID in that language. It is present in every response (single, list, search). Without WPML it is an empty map.

### Shared behaviour of DELETE endpoints

Every `DELETE /woocommerce/*` endpoint takes a JSON array of `local_key`s.

- Each element must be a positive integer or a non-empty string. Any other element returns `400 invalid_param` with the message `<Prefix> :: Element N :: Invalid delete value; expected a positive integer or non-empty string local_key`.
- A `local_key` that matches nothing returns `404 not_found`, or is skipped with [`?ignore`](#ignore-on-delete).
- A `local_key` shared by several objects (WPML translations) deletes all of them. With the WPML option that deletes translations together with the original, members already removed that way are skipped, not reported as errors.
- A failed delete returns `500 delete_failed`.

Errors that apply to every method of the term endpoints (`categories`, `tags`, `attributes/{attribute}/terms`), and that `?ignore` never skips:

- `404 not_found` (`<Prefix> :: Taxonomy '<taxonomy>' not found`) when the taxonomy is not registered;
- on `attributes/{attribute}/terms`, `404 not_found` (`WooCommerce Attribute Term :: Attribute '<attribute>' not found`) when `{attribute}` matches no attribute, and `400 invalid_param` (`WooCommerce Attribute Term :: Attribute ID or slug is required`) when it is empty.

### GET `/woocommerce/brands`

Returns WooCommerce brands, stored as terms of the `product_brand` taxonomy.

**Pagination:** none. The response always contains **all** brands matching the filters; there is no `per_page`/`page`.

Optional query:

```text
?id=701
?local_key=4002
?slug=ford
?name=Ford
?name[it]=Ford&name[en]=Ford
?parent_id=700
?parent_lk=4001
```

- `id`, `local_key`, `slug`: lookup filters, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).
- `name`: exact-name search, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).
- `parent_id` / `parent_lk`: direct-children filters, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).
- If the `product_brand` taxonomy does not exist yet (neither registered nor defined in ACF), the response is `[]`.

Response `200`:

```json
[
  {
    "id": 701,
    "name": "Ford",
    "slug": "ford",
    "local_key": 4002,
    "description": "",
    "parent": 0,
    "count": 0,
    "taxonomy": "product_brand",
    "translations": {
      "it": 701,
      "en": 702
    },
    "acf_fields": {}
  }
]
```

### POST `/woocommerce/brands`

Creates or updates WooCommerce brands. The endpoint makes sure the `product_brand` taxonomy exists for the `product` post type, then uses the same payload as the terms endpoint (`POST /terms`).

Body:

```json
[
  {
    "local_key": 4002,
    "name": {
      "en": "Ford",
      "it": "Ford",
      "es": "Ford",
      "ru": "Ford"
    },
    "slug": {
      "en": "ford-en",
      "it": "ford-it",
      "es": "ford-es",
      "ru": "ford-ru"
    },
    "description": "Vehicle manufacturer",
    "thumbnail": "https://cdn.example.com/ford-thumbnail.png",
    "parent": 4001,
    "acf_fields": {
      "logo": "https://cdn.example.com/ford.png"
    }
  }
]
```

Behaviour:

- If `product_brand` is not registered and has no ACF definition, it is created as a **hierarchical** ACF taxonomy attached to `product`. When WooCommerce or another plugin already registers it, nothing is created.
- `name`, `slug`, `description`, `local_key` and `acf_fields` behave as in [`POST /terms`](#post-terms). `name` is required on every save.
- **`id`** (optional): the WordPress term ID of the brand to update, as in `POST /terms`. Unlike categories, tags and attribute terms, a stale `id` is never dropped: an `id` that is not a brand returns `404 not_found`, even with a valid `local_key`.
- **`parent`** (optional):
  - must be `null` or the `local_key` (integer or string) of the parent brand, for example `"parent": 4001`;
  - does not accept `0` or numeric WordPress IDs. For a top-level brand, use `null` or omit the field;
  - the parent brand must already exist, or be created earlier in the same batch;
  - with WPML, the endpoint tries to use the parent's translation in the language of the brand being created or updated.
- **`thumbnail`**:
  - a remote URL to import into the Media Library, or the `attachment_id` (integer) of a file already in the Media Library (e.g. uploaded with `POST /media`);
  - the resolved value is saved as `thumbnail_id`;
  - `null` removes the thumbnail; omitting it leaves the existing one unchanged.
- With WPML, language maps create or update the brand's translations.
- If the payload contains language maps but WPML is not installed or active, the request returns `500 wpml_required`.
- Brands are assigned to products through the `brand` field of `POST /woocommerce/products`, using the brand's `local_key` (integer or string), for example `"brand": 4002`.

Response `200`:

```json
[701]
```

Main errors:

- `400 invalid_param` for an invalid `parent`, `thumbnail`, `local_key` or `acf_fields` value, or a missing `name`
- `400 input_invalid` if an `image`/`file` value in `acf_fields` is neither an existing `attachment_id` nor a valid URL
- `404 not_found` if the `id` or the `parent` does not exist
- `409 duplicate_local_key` with an explicit `id`, when the `local_key` belongs to another brand
- `500 woocommerce_required`
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active
- `500 acf_error` if ACF refuses to save a field
- `500 request_failed`

### DELETE `/woocommerce/brands`

Deletes WooCommerce brands by `local_key`. If several terms share the same `local_key` (e.g. WPML translations), all of them are deleted.

Body:

```json
[4002]
```

Optional query:

```text
?ignore=1
```

If the `product_brand` taxonomy does not exist, every element returns `404 not_found` with the message `WooCommerce Brand :: Brand taxonomy not found`, or is skipped with `?ignore`.

Response `200`:

```json
null
```

### GET `/woocommerce/attributes`

Returns global WooCommerce product attributes, i.e. the definitions that generate `pa_*` taxonomies such as `pa_color` or `pa_size`.

**Pagination:** none. The response always contains **all** attributes matching the filters; there is no `per_page`/`page`.

Optional query:

```text
?id=31
?local_key=5001
?slug=color
?slug=pa_color
?name=Color
```

- Only one filter applies. Precedence: `id` > `slug` > `local_key` > `name`.
- `id` returns the attribute with that ID. An ID that matches no attribute returns `404 not_found`.
- `slug` and `local_key` return zero or one attribute.
- `name` performs an **exact-name** search on the attribute label (e.g. `Color`, case-sensitive) and returns every attribute with that name. `name` is a plain string: a language map (`?name[it]=…`) is ignored.
- Global attributes cannot be translated with WPML, so there is no translation grouping. The `translations` field is present for a consistent shape but is always empty (`{}`).

Response `200`:

```json
[
  {
    "id": 31,
    "local_key": 5001,
    "name": "Color",
    "slug": "pa_color",
    "attribute_slug": "color",
    "type": "select",
    "order_by": "menu_order",
    "has_archives": false,
    "taxonomy": "pa_color",
    "translations": {}
  }
]
```

### POST `/woocommerce/attributes`

Creates or updates global WooCommerce product attributes in batch.

Body:

```json
[
  {
    "local_key": 5001,
    "name": "Color",
    "slug": "color",
    "type": "select",
    "order_by": "menu_order",
    "has_archives": false
  }
]
```

| Field | Required | Default | Notes |
| --- | --- | --- | --- |
| `id` | no | | If present, updates **that** attribute (takes precedence, as in `POST /woocommerce/products`). `local_key` is then not required. A malformed `id` is ignored, as if it were absent. |
| `local_key` | only if `id` is absent | | Without `id`, a missing or invalid `local_key` returns `400 invalid_param`. With `id`, a valid `local_key` is (re)associated with the attribute, and an invalid one is ignored. |
| `name` | on create | | On update it can be omitted, but an empty string returns `400 invalid_param`. |
| `slug` | no | | Can be sent as `color` or `pa_color`. An empty string counts as omitted: the existing slug is kept (on create, WooCommerce derives it from `name`). |
| `type` | no | WooCommerce default `select` | |
| `order_by` | no | WooCommerce default `menu_order` | WooCommerce accepts `menu_order`, `name`, `name_num` and `id`. The plugin passes the value to WooCommerce without checking it. |
| `has_archives` | no | `false` | A boolean, a number (non-zero is `true`) or a string. The strings `1`, `true`, `yes` and `on` (any case) are `true`; any other value is `false`. |

Resolution, in order of precedence:

1. If `id` is present, that attribute is updated (`404` if the ID does not exist). If `local_key` is also sent, it is (re)associated with that attribute.
2. Otherwise, if the `local_key` already exists, the attribute associated with it is updated.
3. Otherwise, if `slug` matches an existing attribute, that attribute is updated.
4. Otherwise a new attribute is created.

Other notes:

- `name`, `slug`, `type` and `order_by` must be scalars. An object or a list returns `400 invalid_param` with the message `WooCommerce Attribute :: Element N :: Parameter '<key>' must be a string`.
- The response contains the IDs of the created or updated attributes.
- WooCommerce limits the unprefixed slug to 28 characters and rejects reserved or already used names.
- Attribute values are managed as terms of the generated taxonomy, for example `pa_color` (see [attribute terms](#get-woocommerceattributesattributeterms)).

Response `200`:

```json
[31]
```

Main errors:

- `400 invalid_param`
- A WooCommerce error is passed through with its own code, status and message (prefixed with `WooCommerce Attribute :: Element N :: `). For example:
  - `400 invalid_product_attribute_slug_too_long`
  - `400 invalid_product_attribute_slug_reserved_name`
  - `400 invalid_product_attribute_slug_already_exists`
- `409 duplicate_local_key` if the `local_key` is already used by another attribute
- `404 not_found` if the `id` does not exist
- `500 woocommerce_required`
- `500 request_failed`

### DELETE `/woocommerce/attributes`

Deletes global WooCommerce product attributes by `local_key`. Deletion uses `wc_delete_attribute()`, which also removes the terms of the attribute taxonomy when the taxonomy is registered.

Body:

```json
[5001]
```

Optional query:

```text
?ignore=1
```

Response `200`:

```json
null
```

### GET `/woocommerce/attributes/{attribute}/terms`

Returns the values (terms) of a global WooCommerce product attribute.

`{attribute}` can be:

- the attribute ID, for example `31`;
- the unprefixed slug, for example `color`;
- the attribute taxonomy, for example `pa_color`.

**Pagination:** none. The response always contains **all** terms of the attribute matching the filters; there is no `per_page`/`page`.

Optional query:

```text
?id=101
?local_key=5101
?slug=red
?name=Red
?name[it]=Rosso&name[en]=Red
?parent_id=100
?parent_lk=5100
```

- `id`, `local_key`, `slug`: lookup filters, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).
- `name`: exact-name search, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).
- `parent_id` / `parent_lk`: direct-children filters, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints). Attribute terms are usually flat, so these filters rarely matter.

Response `200`:

```json
[
  {
    "id": 101,
    "name": "Red",
    "slug": "red",
    "local_key": 5101,
    "description": "",
    "parent": 0,
    "count": 0,
    "taxonomy": "pa_color",
    "translations": {
      "it": 102,
      "en": 101
    },
    "acf_fields": {}
  }
]
```

- `acf_fields` is the value of `get_fields()`, or `{}` when the term has no ACF values.

### POST `/woocommerce/attributes/{attribute}/terms`

Creates or updates the values (terms) of a global WooCommerce product attribute.

Body:

```json
[
  {
    "local_key": 5101,
    "name": {
      "en": "Red",
      "it": "Rosso"
    },
    "slug": {
      "en": "red",
      "it": "rosso"
    },
    "description": "Color option"
  }
]
```

Behaviour:

- `{attribute}` is resolved to the WooCommerce `pa_*` taxonomy.
- `local_key` is saved as the `onpage_local_key` term meta.
- If you send an `id` that **exists**, that term is updated, and the given `local_key` is written on it and on all its translations. An `id` that does **not** exist (stale) is dropped only when a valid `local_key` is also sent: the upsert is then driven by `local_key`. Without a valid `local_key`, a stale `id` returns `404 not_found`.
- `name` is required on every save, update included. It can be a string or a WPML language map.
- `parent` is a WordPress term ID (or `0`/`null` for none), as in [`POST /terms`](#post-terms). Parents by `local_key` are only supported for categories and brands.
- `thumbnail` is not supported and is ignored.
- If `name` is a string and other fields are language maps, the same name is used unchanged for every translation. If a per-language `slug` is missing, the translations get a distinct technical slug.
- `slug`, `description` and `acf_fields` support WPML language maps, as for other terms.
- A `slug` already used by another element's term is not taken over, as in [`POST /terms`](#post-terms).
- The response contains the IDs of the created or updated terms in the base language.

Response `200`:

```json
[101]
```

Main errors:

- `400 invalid_param` for a missing `name`, an invalid `local_key` or `parent`, or an invalid `acf_fields` value; also when `{attribute}` is empty
- `400 input_invalid` if an `image`/`file` value in `acf_fields` is neither an existing `attachment_id` nor a valid URL
- `404 not_found` if `{attribute}` does not resolve to an attribute, or the `id` or `parent` does not exist
- `409 duplicate_local_key` only with an explicit `id` that exists, when the `local_key` belongs to another term
- `500 woocommerce_required`
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active
- `500 acf_error` if ACF refuses to save a field
- `500 request_failed`

### DELETE `/woocommerce/attributes/{attribute}/terms`

Deletes terms of a global WooCommerce product attribute by `local_key`.

Body:

```json
[5101]
```

Optional query:

```text
?ignore=1
```

Response `200`:

```json
null
```

### GET `/woocommerce/categories`

Returns WooCommerce product categories, stored in the `product_cat` taxonomy.

**Pagination:** none. The response always contains **all** categories matching the filters; there is no `per_page`/`page`.

Optional query:

```text
?id=12
?local_key=6001
?slug=chairs
?name=Chairs
?name[it]=Sedie&name[en]=Chairs
?parent_id=575
?parent_lk=6000
```

- `id`, `local_key`, `slug`: lookup filters, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).
- `name`: exact-name search, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).
- `parent_id` / `parent_lk`: direct-children filters, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).

Response `200`:

```json
[
  {
    "id": 12,
    "name": "Chairs",
    "slug": "chairs",
    "local_key": 6001,
    "description": "",
    "parent": 0,
    "count": 0,
    "taxonomy": "product_cat",
    "translations": {
      "it": 12,
      "en": 13
    },
    "acf_fields": {}
  }
]
```

### POST `/woocommerce/categories`

Creates or updates WooCommerce product categories, using the same payload as the terms endpoint (`POST /terms`).

Body:

```json
[
  {
    "local_key": 6001,
    "name": {
      "en": "Chairs",
      "it": "Sedie"
    },
    "slug": {
      "en": "chairs",
      "it": "sedie"
    },
    "description": "Product category",
    "thumbnail": "https://cdn.example.com/chairs-thumbnail.png",
    "parent": 6000,
    "acf_fields": {
      "image": "https://cdn.example.com/chairs.png"
    }
  }
]
```

Behaviour:

- `name`, `slug`, `description`, `local_key` and `acf_fields` behave as in [`POST /terms`](#post-terms).
- If `name` is a string and other fields are language maps, the same category name is used unchanged for every translation. If a per-language `slug` is missing, the translations get a distinct technical slug.
- **`parent`** (optional):
  - must be `null` or the `local_key` (integer or string) of the parent category, for example `"parent": 6000`;
  - does not accept `0` or numeric WordPress IDs. For a top-level category, use `null` or omit the field;
  - the parent category must already exist, or be created earlier in the same batch;
  - with WPML, the endpoint tries to use the parent's translation in the language of the category being created or updated.
- **Resolution:**
  - If you send an `id` that **exists**, that category is updated, and the given `local_key` is written on it and on all its translations. An `id` that does **not** exist (stale) is dropped only when a valid `local_key` is also sent: the upsert is then driven by `local_key`. Without a valid `local_key`, a stale `id` returns `404 not_found`.
  - If the `local_key` already exists, the endpoint updates in place the `product_cat` categories and WPML translations with that `local_key`, preserving product-category associations.
  - If the `local_key` does not exist, it creates new categories for the languages in the payload.
- **`thumbnail`**:
  - a remote URL to import into the Media Library, or the `attachment_id` (integer) of a file already in the Media Library (e.g. uploaded with `POST /media`);
  - the resolved value is saved as `thumbnail_id`;
  - `null` removes the thumbnail; omitting it leaves the existing one unchanged.
- With WPML, language maps create or update the category's translations.
- A category with a different `local_key` is never modified:
  - if it has the **same name under the same parent**, the collision is handled as described in [`term_exists` conflicts on terms](#term_exists-conflicts-on-terms);
  - if it is under a **different parent**, WordPress creates a new term with a de-duplicated slug (`silicone-acetico` → `silicone-acetico-2`).
- If the payload contains language maps but WPML is not installed or active, the request returns `500 wpml_required`.
- Categories are assigned to products through the `categories` field of `POST /woocommerce/products`, using the category's `local_key` (integer or string), for example `"categories": [6001]`.

Response `200`:

```json
[12]
```

Main errors:

- `400 invalid_param` for an invalid `parent`, `thumbnail`, `local_key` or `acf_fields` value, or a missing `name`
- `400 input_invalid` if an `image`/`file` value in `acf_fields` is neither an existing `attachment_id` nor a valid URL
- `404 not_found` if the `id` or the parent category does not exist
- `409 duplicate_local_key` with an explicit `id`, when the `local_key` belongs to another category
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active
- `500 acf_error` if ACF refuses to save a field
- `500 request_failed`

### DELETE `/woocommerce/categories`

Deletes WooCommerce product categories by `local_key`. If several terms share the same `local_key` (e.g. WPML translations), all of them are deleted.

Body:

```json
[6001]
```

Optional query:

```text
?ignore=1
```

Response `200`:

```json
null
```

### GET `/woocommerce/tags`

Returns WooCommerce product tags, stored in the `product_tag` taxonomy.

**Pagination:** none. The response always contains **all** tags matching the filters; there is no `per_page`/`page`.

Optional query:

```text
?id=22
?local_key=7001
?slug=featured
?name=Featured
?name[it]=In evidenza&name[en]=Featured
?parent_id=21
?parent_lk=7000
```

- `id`, `local_key`, `slug`: lookup filters, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).
- `name`: exact-name search, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).
- `parent_id` / `parent_lk`: direct-children filters, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).

Response `200`:

```json
[
  {
    "id": 22,
    "name": "Featured",
    "slug": "featured",
    "local_key": 7001,
    "description": "",
    "parent": 0,
    "count": 0,
    "taxonomy": "product_tag",
    "translations": {
      "it": 22,
      "en": 23
    },
    "acf_fields": {}
  }
]
```

### POST `/woocommerce/tags`

Creates or updates WooCommerce product tags, using the same payload as the terms endpoint (`POST /terms`).

Body:

```json
[
  {
    "local_key": 7001,
    "name": {
      "en": "Featured",
      "it": "In evidenza"
    },
    "slug": {
      "en": "featured",
      "it": "in-evidenza"
    },
    "description": "Product tag"
  }
]
```

Behaviour:

- `name`, `slug`, `description`, `local_key` and `acf_fields` behave as in [`POST /terms`](#post-terms).
- Each body element must be a tag object. A plain string returns `400 invalid_param` (`Invalid payload; expected a non-empty JSON object`). A language-value map as a top-level element (`{"en": "petrol", "it": "benzina"}`) has no `name`, so it returns `400 invalid_param` with the message `Term :: Element N :: Name is required`.
- `name` and `slug` can be language-value maps inside the tag object, for example `{ "en": "petrol", "it": "benzina" }`.
- If `name` is a string and other fields are language maps, the same tag name is used unchanged for every translation. If a per-language `slug` is missing, the translations get a distinct technical slug.
- **Resolution:**
  - If you send an `id` that **exists**, that tag is updated, and the given `local_key` is written on it and on all its translations. An `id` that does **not** exist (stale) is dropped only when a valid `local_key` is also sent: the upsert is then driven by `local_key`. Without a valid `local_key`, a stale `id` returns `404 not_found`.
- `parent` is a WordPress term ID (or `0`/`null` for none), as in [`POST /terms`](#post-terms). Parents by `local_key` are only supported for categories and brands.
- `thumbnail` is not supported for tags and is ignored.
  - If the `local_key` already exists, the endpoint updates in place the `product_tag` tags and WPML translations with that `local_key`, preserving product-tag associations.
  - If the `local_key` does not exist, it creates new tags for the languages in the payload.
- With WPML, language maps create or update the tag's translations.
- If the payload contains language maps but WPML is not installed or active, the request returns `500 wpml_required`.
- Tags are assigned to products through the `tags` field of `POST /woocommerce/products`, using the tag's `local_key` (integer or string), for example `"tags": [7001]`.

Response `200`:

```json
[22]
```

Main errors:

- `400 invalid_param` for an invalid `parent`, `local_key` or `acf_fields` value, or a missing `name`
- `400 input_invalid` if an `image`/`file` value in `acf_fields` is neither an existing `attachment_id` nor a valid URL
- `404 not_found` if the `id` or the `parent` does not exist
- `409 duplicate_local_key` with an explicit `id`, when the `local_key` belongs to another tag
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active
- `500 acf_error` if ACF refuses to save a field
- `500 request_failed`

### DELETE `/woocommerce/tags`

Deletes WooCommerce product tags by `local_key`. If several terms share the same `local_key` (e.g. WPML translations), all of them are deleted.

Body:

```json
[7001]
```

Optional query:

```text
?ignore=1
```

Response `200`:

```json
null
```

### GET `/woocommerce/products`

Returns WooCommerce products. Without a query, it returns every product. With WPML active, the unfiltered listing only contains products in the current request language.

**Pagination:** none. The response always contains **all** products matching the filters; there is no `per_page`/`page`. On very large catalogs the response can be heavy.

Optional query:

```text
?id=501
?local_key=1001
?name=Red Chair
?name[it]=Sedia Rossa&name[en]=Red Chair
```

- Only one filter applies. Precedence: `id` > `local_key` > `name`. A malformed `id` or `local_key` is ignored.
- `id` and `local_key` return the single matching product. A product that does not exist returns `404 not_found`.
- All WPML translations share the same `local_key`, so `?local_key=` returns the product in the group's **default language** (the same representative used by `?name=`). The other IDs are in the `translations` map.
- `name` performs an **exact-title** search. It accepts two forms:
  - **string** (`?name=Red Chair`);
  - **language map** (`?name[it]=Sedia Rossa&name[en]=Red Chair`): returns the **union** of the results for every title, de-duplicated by translation group.
- The title search is **not** limited to a language. A title matches a product in any language, and the language codes of a map do not restrict the match.
- Results are grouped by translation group. The response is **always a list** with **one object per distinct translation group** among the matches (the default-language product is the representative when available). The `translations` map contains all language IDs.
  - If the values are translations of the **same** product → **one object**, with every language in `translations`.
  - If the values match products from **different groups** → **several objects**, one per group.
  - A language whose title matches nothing contributes no results (no error).

Response `200`:

```json
[
  {
    "id": 501,
    "title": "Red Chair",
    "long_description": "Long description",
    "short_description": "Short description",
    "content": "Long description",
    "description": "Short description",
    "type": "product",
    "status": "publish",
    "local_key": 1001,
    "translations": {
      "it": 501,
      "en": 502,
      "fr": 503
    },
    "woocommerce": {
      "product_type": "simple",
      "sku": "CHAIR-RED",
      "regular_price": "49.90",
      "sale_price": "",
      "price": "49.90",
      "stock_status": "instock",
      "image_id": 601,
      "gallery_image_ids": []
    },
    "acf_fields": {
      "custom_badge": "New",
      "manual_pdf": 1234,
      "certifications": [
        { "name": "CE marking", "year": 2024 },
        { "name": "VOC A+", "year": 2023 }
      ]
    },
    "terms": []
  }
]
```

The example is abridged. `woocommerce` also contains `manage_stock`, `stock_quantity`, `backorders`, `sold_individually`, `weight`, `length`, `width`, `height`, `virtual`, `downloadable`, `featured`, `catalog_visibility`, `tax_status`, `tax_class`, `purchase_note`, `menu_order`, `reviews_allowed`, `downloads` (the native downloadable files) and `permalink`.

Response notes:

- `acf_fields` holds the values resolved by `get_fields()`. Repeaters are returned natively as lists of objects (one per row). `image`/`file` sub-fields are attachment IDs or arrays, depending on the `return_format` set on the field group.
- ACF `tab` fields do not appear in `acf_fields` (they are UI separators with no value).
- `translations` maps each language code to the product ID in that language (WPML translation group). Without WPML the map may be empty. The top-level `id` is the ID of the returned representative.

### POST `/woocommerce/products`

Creates or updates WooCommerce products, using a product-specific payload.

Body:

```json
[
  {
    "local_key": 1001,
    "name": {
      "en": "Red Chair [en]",
      "it": "Red Chair [it]",
      "es": "Red Chair [es]",
      "ru": "Red Chair [ru]"
    },
    "long_description": {
      "en": "<p>Long product description [en]</p>",
      "it": "<p>Descrizione lunga prodotto [it]</p>"
    },
    "short_description": {
      "en": "Short product description [en]",
      "it": "Descrizione breve prodotto [it]"
    },
    "slug": {
      "en": "red-chair",
      "it": "sedia-rossa"
    },
    "update_slug": false,
    "brand": 4002,
    "categories": [6011, 6012],
    "tags": [7001, 7002],
    "terms": {
      "tipologia": [112631840],
      "materiale": [8001, 8002]
    },
    "props": {
      "product_type": "simple",
      "sku": "CHAIR-RED",
      "regular_price": "49.90",
      "stock_status": "instock"
    },
    "image": "https://cdn.example.com/red-chair.jpg",
    "gallery": [
      "https://cdn.example.com/red-chair-side.jpg",
      "https://cdn.example.com/red-chair-back.jpg"
    ],
    "attributes": {
      "Color": {
        "en": "Red",
        "it": "Rosso",
        "es": "Rojo",
        "ru": "Red"
      },
      "Doors": 5,
      "Fuel": ["Petrol", "Hybrid"]
    },
    "acf_fields": {
      "custom_badge": "New",
      "manual_pdf": "https://cdn.example.com/manual.pdf",
      "certifications": [
        { "name": "CE marking", "year": 2024, "attachment": "https://cdn.example.com/ce.pdf" },
        { "name": "VOC A+", "year": 2023 }
      ],
      "product_subtitle": {
        "it": "Sigillante monocomponente ad alto modulo",
        "en": "High-modulus one-component sealant"
      },
      "list_of_main_charatteristics": {
        "it": [
          { "lomc_field": "Pasta tissotropica" },
          { "lomc_field": "Varie colorazioni" }
        ],
        "en": [
          { "lomc_field": "Thixotropic paste" },
          { "lomc_field": "Various colours" }
        ]
      },
      "datasheet": {
        "it": { "title": "Scheda IT", "attachment": 1301 },
        "en": { "title": "Datasheet EN", "attachment": 1302 }
      }
    },
    "downloads": [
      {
        "id": "technical_datasheet",
        "name": "Technical datasheet",
        "url": "https://cdn.example.com/technical-datasheet.pdf"
      },
      {
        "name": "Safety datasheet",
        "url": "https://cdn.example.com/safety-datasheet.pdf"
      },
      {
        "name": "Installation software",
        "url": "https://cdn.example.com/installer.zip",
        "public": false
      }
    ],
    "status": "publish",
    "id": 501
  }
]
```

#### Identity and resolution

- `local_key` and `name` are required.
- `id`, when present, must be a positive ID or `null`. Any other value returns `400 invalid_param` (`Parameter 'id' must be a positive integer or null`).
- Resolution: if the element contains `id`, the existing product is updated. If `id` is missing but the `local_key` already exists, that product is updated. Otherwise a new WooCommerce product is created.
- `local_key` is saved as the `onpage_local_key` post meta and must be unique among products.
- The `local_key` is written on **every** language of the WPML translation group (it identifies the same On Page® element, not a single language). It is written before the slow part of the import (media, ACF, terms), so an interruption never leaves translations without a key.
- **Leftover duplicates.** Other products outside the group may still carry the same `local_key` (leftovers of an interrupted import, or of two concurrent imports of the same element). The update reconciles them instead of rejecting the request:
  - a product occupying a language that is still free is attached to the group;
  - a product duplicating a language already present is deleted.
- `409 duplicate_local_key` is returned only when the request sends an explicit `id` and that `local_key` belongs to another product.

#### Text fields and status

- `name` can be a string or a WPML language map. If it is a string, the same name is used unchanged for every translation created by other multilingual fields. For different names per language, use a WPML map.
- `long_description` sets the WooCommerce long description. It can be a string or a WPML language map.
- `short_description` sets the WooCommerce short description. It can be a string or a WPML language map.
- `long_description` and `short_description` are top-level product fields, not part of `props` or `acf_fields`.
- For compatibility, `content` and `description` are still accepted as aliases of `long_description` and `short_description`. If both are present, `long_description` and `short_description` win.
- On update, a WPML language map writes only the languages it contains. `{"name": {"en": "Red Chair"}}` renames the English product and leaves the Italian name as it is. The same applies to `slug`, `long_description`, `short_description`, `image`, `gallery`, each value in `props`, `acf_fields` and `attributes`. An attribute sent as a map without a language keeps that language's current attribute. A new translation created by the same request still falls back to the first language of the map, so it never starts empty.
- Only language maps in the fields above create translations. A payload with scalar values only never creates a translation:
  - On create, it makes one product in the site's default language.
  - On update, it writes each scalar value on every translation that already exists. `{"name": "Chair"}` renames the English and the Italian product alike. To change one language only, send a map with that language, for example `{"name": {"en": "Chair"}}`.
- To create a translation with the same text, repeat the value in the map, for example `{"en": "Chair", "it": "Chair"}`. Maps in `categories`, `tags` and `terms` only choose the term for each translation.
- `status` is optional. On create it defaults to `publish`. On update, a save without `status` keeps the current status of every language, so a draft stays a draft. A translation created by an update without `status` takes the status of the existing product.
- `status` must be a non-empty string, otherwise `400 invalid_param` (`Parameter 'status' must be a string`).

#### Missing, present and null keys

On update, one rule covers most keys:

| In the payload | Effect |
| --- | --- |
| key missing | the value stays as it is |
| key present | the value is replaced entirely (lists such as `gallery`, `attributes` and `downloads` are not merged) |
| `null` or an empty list | the value is cleared |

Exceptions:

- `slug`: `null` or `""` is ignored; the slug is not cleared (see [Slug](#slug)).
- `attributes`: a non-empty object replaces the custom attributes and the global ones it names. Global `pa_*` attributes it does not name stay. `null` or `{}` removes every attribute, global ones included (see [`attributes`](#attributes)).
- Enum and boolean props (`stock_status`, `backorders`, `catalog_visibility`, `tax_status`, `manage_stock`, `featured`, …): `null` leaves the value as it is, because WooCommerce has no empty value for them (see [`props`](#props-native-woocommerce-fields)).

Unknown top-level keys are ignored with no error: a misspelled key answers `200` and writes nothing.

#### Slug

- `slug` is optional and customizes the product permalink (`post_name`). The value is sanitized with `sanitize_title`.
- If omitted, the slug stays as it is: on create WordPress generates it from the title, on update it is not touched. Changing only `name` does not change the permalink.
- It can be a string or a WPML language map, for example `{ "it": "sedia-rossa", "en": "red-chair" }`. If you send a single string with several translations, WordPress makes the slugs unique by adding a suffix.
- An empty or `null` `slug` is ignored (it does not reset the existing slug). So is a value that does not resolve to a non-empty scalar for the language, such as a list: no error is returned.
- `update_slug` is optional, boolean, default `false`. It controls when the sent `slug` is applied:
  - `false`: the slug is set **on create only**; later updates do not touch it (existing permalinks are preserved);
  - `true`: the slug is rewritten **on update too**.
  - It only has an effect when `slug` is in the payload.

#### `props` (native WooCommerce fields)

- Native WooCommerce fields go inside `props`: `sku`, `regular_price`, `sale_price`, `price`, `manage_stock`, `stock_quantity`, `stock_status`, `backorders`, `sold_individually`, `weight`, `length`, `width`, `height`, `virtual`, `downloadable`, `featured`, `catalog_visibility`, `tax_status`, `tax_class`, `purchase_note`, `menu_order`, `reviews_allowed`, `product_type`.
- `props.product_type` can be `simple` or `variable`. Any other value returns `400 invalid_param`. If omitted on create, it defaults to `simple`.
- Unknown keys in `props` are ignored with no error.

Value types:

| Keys | Type | Notes |
| --- | --- | --- |
| `sku`, `regular_price`, `sale_price`, `price`, `weight`, `length`, `width`, `height`, `tax_class`, `purchase_note` | string or number | `null` or `""` clears the value. |
| `stock_quantity` | integer | `null`, `""` or a non-numeric value clears it. |
| `menu_order` | integer | `null` becomes `0`. |
| `manage_stock`, `sold_individually`, `virtual`, `downloadable`, `featured`, `reviews_allowed` | boolean | `null` leaves the value as it is. See the boolean rule below. |
| `stock_status` | `instock`, `outofstock` or `onbackorder` | `null` leaves the value as it is. `""` sets `instock`. |
| `backorders` | `no`, `notify` or `yes` | `null` leaves the value as it is. `""` sets `no`. |
| `catalog_visibility` | `visible`, `catalog`, `search` or `hidden` | `null` leaves the value as it is. `""` sets `visible`. |
| `tax_status` | `taxable`, `shipping` or `none` | `null` leaves the value as it is. `""` sets `taxable`. |

The allowed values of `stock_status`, `backorders`, `catalog_visibility` and `tax_status` are WooCommerce's. The plugin does not check them itself: WooCommerce rejects an unknown value, and the request returns `400 invalid_param`.

**Boolean rule.** A boolean prop is `true` only for `true`, a number equal to `1` (`1`, `"1"`), or the strings `"true"`, `"yes"` and `"on"` (case-insensitive). Every other value is `false`, including `"0"`, `"false"` and `""`. `null` is not a value: it leaves the prop as it is. The same rules apply to the enum and boolean props of variations (`manage_stock`, `stock_status`, `backorders`, `virtual`, `downloadable`).
- **Changing `variable` to `simple` deletes the variations.** WooCommerce permanently deletes every variation of a product whose type changes from `variable` to `simple` (`woocommerce_product_type_changed`), including those created with `POST /woocommerce/variant-products` and those a site admin added. Omit `product_type` on updates when you do not mean to change it.

#### Images

- **`image`**:
  - a remote URL to import and set as the main product image, or the `attachment_id` (JSON integer) of a file already in the Media Library (e.g. uploaded with `POST /media`);
  - `image: null` removes the image; omitting it leaves the existing one unchanged;
  - it can be a WPML language map, for example `{ "en": "https://cdn.example.com/en/chair.jpg", "it": "https://cdn.example.com/it/chair.jpg" }`. Each translation receives its own image.
- **`gallery`**:
  - replaces the whole product gallery with the list sent. Items can be remote URLs and/or `attachment_id`s;
  - each URL is imported/reused in the Media Library with the same rules as `image`: already imported URLs reuse the existing attachment, with no duplicates even within the same list;
  - an `attachment_id` is assigned directly, without download;
  - list order determines gallery order;
  - each entry of the list can also be a per-language map, for example `{ "it": "https://…/it.jpg", "en": null }`. A `null` or empty value skips that position for that language;
  - if omitted, the gallery is unchanged; `gallery: []` or `gallery: null` empties it;
  - it can be a WPML language map of lists, for example `{ "en": ["https://cdn.example.com/en/1.jpg"], "it": ["https://cdn.example.com/it/1.jpg"] }`. Each translation receives its own gallery. The resolved value for each language must be a list of URLs (an object or a single string returns `400 invalid_param`).

#### `attributes`

- Replaces the product's whole set of **custom** attributes with the ones sent. A value can be a string/number, a list of values or a WPML language map.
- **Global attributes.** A key that names a registered global attribute taxonomy (e.g. `pa_color`, created with [`POST /woocommerce/attributes`](#post-woocommerceattributes)) sets that global attribute on the product. Each option can be a term ID, slug or name, and the term must already exist (see [attribute terms](#post-woocommerceattributesattributeterms)). Otherwise the request returns `404 not_found`. With WPML the term is mapped to the product's language, or the original term is used when it has no translation.
- Any other key creates a custom attribute, including a `pa_*` key whose taxonomy does not exist.
- **Global attributes not sent are kept.** When `attributes` is a non-empty object, global `pa_*` attributes already on the product (for example added by a site admin in WooCommerce) and missing from the payload keep their options, visibility and variation flag.
- When the product is `variable` (because `props.product_type` says so, or because it already is and `product_type` is omitted), the attributes sent are flagged as usable by variations (`variation=true`).
- If `attributes` is omitted, existing attributes are not changed. If it is `null` or `{}`, **every** attribute is removed, custom and global, including global attributes a site admin added. On a `variable` product, the plugin-managed variations built on them become `private` (see below).
- Inside `attributes`, a key whose value is `null` or an empty list is left out of the new set. For a global `pa_*` key this removes that global attribute from the product.
- An empty string, as a value or as a list option, returns `400 invalid_param`.
- **Variations left without an option.** On a `variable` product, after a save that sends `attributes`, a plugin-managed variation (one with a `local_key`) that uses a value the parent no longer offers is made `private`. Nothing is deleted. Its previous status is kept in the `_onpage_held_status` meta, and the parent is resynced.
  - The next `POST /woocommerce/variant-products` of that variation whose attribute values are all offered again (sent in the payload or already stored) applies the payload `status`, or the held one when no `status` is sent, and removes the meta.
  - If the variation still uses a value the parent does not offer, it stays `private` and the requested status is held instead. Sending `status: "private"` clears the held status.

#### `acf_fields`

- Reserved for the product's ACF fields. Keys must be ACF technical names or ACF field keys that exist on the product's field group. Unknown fields return `400 invalid_param`.
- For the full type semantics (`tab`, `repeater`, `image`/`file` URL → `attachment_id`, etc.) see [Handling `acf_fields`](#handling-acf_fields).

#### `downloads` (native WooCommerce downloadable files)

- Manages native WooCommerce downloadable files, separate from ACF. It can be a list of objects or `null`.
- Each item accepts `url` or `file` with a valid remote URL, or the `attachment_id` (JSON integer) of a file already in the Media Library (e.g. uploaded with `POST /media`). `file` wins when both are sent. The value can be `null` or an empty string to skip that download in the resolved language. `name`, `id` and `public` are optional.
- Without `name`, the download is named after the file name in its (local) file URL.
- **Download `id`.** WooCommerce keys customers' download permissions by product and download `id`, so the `id` must stay the same across syncs. When `id` is sent, it is used as is. When it is omitted, the plugin picks a stable one:
  - the `id` of the download the product already has for the same file URL (this also keeps the random ids of earlier plugin versions);
  - otherwise an id derived from the file's source: a UUID-shaped MD5 of the On Page® storage segment, or of the URL, or of `attachment:<id>`. The same file gets the same id on every sync and in every language.
  - Two entries of the same payload never share an id: a second entry for the same file gets a different derived id.
  - With `refresh: true` the local file URL can change. The old id is then replaced once by a derived one.
- If `downloads.file` does not already point to a URL inside the site's uploads directory, the file is imported/reused in the Media Library and WooCommerce uses the imported local URL.
- **Approved download directories.** When WooCommerce's approved-directories feature is enabled, the plugin approves the uploads base URL. The remote URL is never approved. A local file served from outside it (for example by a CDN offload plugin) gets an approved-directory rule for its own directory.
- If `downloads.file` is an `attachment_id`, nothing is downloaded: the plugin only checks that the ID matches an existing attachment and uses its local URL directly.
- If the remote URL was already imported, the existing attachment is reused without downloading the file again.
- If a remote PDF changes while keeping the same URL, pass `refresh: true` on the download to replace the attachment in the Media Library, keeping the same attachment ID when possible.
- Downloads managed by this endpoint are attached to the parent product in the Media Library.
- **`public`** (boolean, default `true`) decides whether the download is listed with a direct link in the documents section of the WooCommerce product page. Use it for data sheets or manuals anyone may read. `false` keeps it a regular WooCommerce download, delivered only to customers who bought the product. A value that is not a JSON boolean (`"false"`, `0`, `null`) returns `400 invalid_param` with the message `Parameter 'downloads.N.public' must be a boolean`, before anything is saved.
- `GET /woocommerce/products` returns each download under `woocommerce.downloads` with the keys `id`, `name`, `file`, `enabled` and `public`.
- An error while importing a download file (a non-public host, a file over the size limit, a failed download) is returned as `500 request_failed`, with the message `WooCommerce Product :: Element N :: Failed to import downloadable file for 'downloads.N.file' :: <original message>`. Downloads use a 12-second timeout.
- If `downloads` contains at least one valid file, the product is automatically flagged as downloadable (`downloadable=true`).
- For `variable` products, WooCommerce shows downloads in the admin on individual variations: the endpoint automatically copies the parent's downloads to the existing variations.
  - Only variations that inherit the parent's downloads are updated. The last copied set is fingerprinted in the `_onpage_inherited_downloads` meta.
  - A variation whose own non-empty downloads differ from that fingerprint was edited on purpose and is skipped.
  - A variation without a fingerprint (saved by an earlier plugin version) is overwritten once, then tracked.
  - A variation already in sync is not saved again.
- `downloads: []` or `downloads: null` removes all WooCommerce downloadable files from the product.
- `name`, `url` and `file` inside `downloads` can be WPML language maps. Each translation receives its own resolved value; `null` or empty values are ignored.

#### Taxonomies: `brand`, `categories`, `tags`, `terms`

- `brand` assigns a single reference from the `product_brand` taxonomy. The reference is the brand's `local_key` (integer or string); slugs are not supported. `null` removes the brand if the taxonomy exists. A `brand` reference when the `product_brand` taxonomy does not exist returns `404 not_found`.
- `categories` replaces the whole set of `product_cat` categories with the references sent. Each reference is the category's `local_key` (integer or string); slugs are not supported.
- `tags` replaces the whole set of `product_tag` tags with the references sent. Each reference is the tag's `local_key` (integer or string); slugs are not supported.
- `categories` and `tags` can be lists of `local_key`s, for example `[6001]` or `[7001, 7002]`. A bare value outside a list (`"categories": 6001`) returns `400 invalid_param`.
- `categories` and `tags` can be WPML language maps, for example `{ "en": 6011, "it": 6013 }`. Each product translation uses the `local_key` of its language. Since the `local_key` identifies the whole translation group anyway, a single value is usually enough.
- Inside a language map, each value can be a single `local_key`, a list of `local_key`s, or `null` to assign no terms in that language.
- If `brand`, `categories` or `tags` are omitted, that taxonomy is not changed. `brand: null` removes the brand; `categories: []`, `categories: null`, `tags: []` or `tags: null` remove categories or tags.
- **`terms`** (optional) assigns terms from **any taxonomy** registered on the product (including ACF/custom taxonomies such as `tipologia`), in addition to brand/categories/tags:
  - it is an object `{"<taxonomy_slug>": <references>}`;
  - references follow the same rules as `categories`/`tags`: a list of `local_key`s (positive integers or non-empty strings), a WPML language map, or `null`/`[]` to empty that taxonomy. A single value is accepted only inside a language map;
  - each taxonomy sent replaces its whole assigned set;
  - references are resolved by `local_key`, in any taxonomy; slugs are not supported;
  - if a taxonomy in `terms` is `product_cat`/`product_tag`/`product_brand`, the dedicated field (`categories`/`tags`/`brand`) wins when present;
  - an unknown taxonomy returns `404 not_found`, but only when references are sent for it (`null`/`[]` for an unknown taxonomy is skipped); a term that is not found returns `404 input_invalid`;
  - a taxonomy that exists but is not attached to products, or WooCommerce's internal `product_type` and `product_visibility`, returns `400 invalid_param`. Use `props.product_type`, `props.featured` and `props.catalog_visibility` instead.

#### Variations

- To create variations with `/woocommerce/variant-products`, the parent product must be `variable` and must have variation attributes configured.

#### More examples

Multilingual categories:

```json
"categories": {
  "en": 6011,
  "it": 6013
}
```

Several categories per language:

```json
"categories": {
  "en": [6011, 6012],
  "it": [6013, 6014]
}
```

Brand, categories and tags by `local_key`:

```json
"brand": 4002,
"categories": [6011, 6012],
"tags": [7001, 7002]
```

Variable parent for variations:

```json
[
  {
    "local_key": 1002,
    "name": "Shirt",
    "props": {
      "product_type": "variable"
    },
    "attributes": {
      "pa_color": ["red", "blue"],
      "pa_size": ["s", "m"]
    }
  }
]
```

Response `200`:

```json
[501]
```

Main errors:

- `400 invalid_param` when:
  - the body is not a valid JSON array, or an element is not a non-empty object;
  - `local_key`/`name` is missing;
  - `id` is not a positive integer or `null`;
  - `name` is not a valid string or language map;
  - `props.product_type` is not `simple` or `variable`;
  - `update_slug` is not a boolean;
  - `props`/`attributes`/`acf_fields` are not objects;
  - a `props` value does not resolve to a scalar or `null`;
  - `acf_fields` contains ACF fields that do not exist for the product;
  - `downloads` is not a valid list, or `downloads[].public` is not a boolean;
  - an `attributes` value or option is an empty string;
  - `categories`/`tags` are not valid lists or language maps;
  - `terms` is not a valid `taxonomy => references` object, or names a taxonomy that is not attached to products (or `product_type`/`product_visibility`);
  - `image`, `gallery` or `downloads[].file` is neither an existing `attachment_id` nor a valid URL;
  - the host of `image` or `gallery` does not resolve to a public address (see [Remote file imports](#remote-file-imports));
  - WooCommerce rejects a `props` value, for example a `sku` already used by another product or an unknown `tax_status` or `catalog_visibility`.
- `400 input_invalid` if an `image`/`file` value in `acf_fields` is neither an existing `attachment_id` nor a valid URL.
- `404 not_found` if the `id` sent, a taxonomy in `terms` or a global attribute term in `attributes` does not exist.
- `404 input_invalid` if a referenced term (`brand`, `categories`, `tags`, `terms`) does not exist.
- `409 duplicate_local_key` only with an explicit `id` in the payload, when that `local_key` belongs to a different product. Without `id`, the key identifies the product to update and any leftovers are reconciled.
- `409 duplicate_title` if the title already exists on an object **without a `local_key`**, outside this element's translation group. An object carrying a different `local_key` is another On Page® element and does not conflict, so two elements with the same title are both imported.
- `500 woocommerce_required` if WooCommerce is not active.
- `413 file_too_large` if `image` or `gallery` points to a file over the [size limit](#remote-file-imports).
- `500 acf_error` if ACF refuses to save a field.
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active. Language maps cannot be handled until WPML is installed and activated.
- `500 wpml_error` if WPML cannot resolve the translation group of the product.
- `500 request_failed` if WooCommerce or WordPress fail to save the product, or a `downloads` file cannot be imported.

### DELETE `/woocommerce/products`

Deletes WooCommerce products by `local_key`. If several products share the same `local_key` (WPML translations), **all** of them are deleted: the whole translation group, as for brands.

Body:

```json
[1001]
```

Optional query:

```text
?ignore=1
```

Response `200`:

```json
null
```

Main errors:

- `400 invalid_param` if the body is not a JSON array, or an element is not a valid `local_key` (`WooCommerce Product :: Element N :: Invalid delete value; expected a positive integer or non-empty string local_key`)
- `404 not_found` if a `local_key` matches no product and `ignore` is not set
- `500 delete_failed` if a product could not be deleted
- `500 woocommerce_required`

### GET `/woocommerce/variant-products`

Returns WooCommerce variations (`product_variation`). Without a query, it returns every variation.

**Pagination:** none. The response always contains **all** variations matching the filters; there is no `per_page`/`page`. On very large catalogs the response can be heavy.

Optional query:

```text
?id=701
?local_key=2001
?parent_id=501
?parent=1002
```

- `parent_id` is the WordPress ID of the parent product; `parent` is the parent product's `local_key`.
- Only one filter applies. Precedence: `id` > `local_key` > `parent_id` > `parent`. A malformed value is ignored.
- `id` returns that variation. A variation that does not exist returns `404 not_found`.
- `local_key` returns the first variation (lowest ID) that holds the key, as a one-element list. A key that matches nothing returns `[]`.
- `parent` resolves the parent product by `local_key`. A parent that does not exist returns `404 not_found`.
- `parent_id` is not checked: an ID that is not a product returns `[]`.

Response `200`:

```json
[
  {
    "id": 701,
    "parent_id": 501,
    "parent": 1002,
    "local_key": 2001,
    "status": "publish",
    "description": "Red / Small",
    "attributes": {
      "pa_color": "red",
      "pa_size": "s"
    },
    "woocommerce": {
      "product_type": "variation",
      "sku": "SHIRT-RED-S",
      "regular_price": "29.90",
      "sale_price": "",
      "price": "29.90",
      "stock_status": "instock",
      "image_id": 601
    },
    "acf_fields": {
      "material": "cotton"
    }
  }
]
```

The example is abridged. `woocommerce` also contains `manage_stock`, `stock_quantity`, `backorders`, `weight`, `length`, `width`, `height`, `virtual`, `downloadable`, `tax_class`, `menu_order` and `permalink`.

### POST `/woocommerce/variant-products`

Creates or updates WooCommerce variations for an existing `variable` parent product.

Body:

```json
[
  {
    "local_key": 2001,
    "parent": 1002,
    "attributes": {
      "pa_color": "red",
      "pa_size": "s"
    },
    "props": {
      "sku": "SHIRT-RED-S",
      "regular_price": "29.90",
      "manage_stock": true,
      "stock_quantity": 10,
      "stock_status": "instock"
    },
    "description": "Red / Small",
    "status": "publish",
    "image": "https://cdn.example.com/shirt-red-s.jpg",
    "acf_fields": {
      "material": "cotton",
      "swatch": "https://cdn.example.com/swatch-red.jpg"
    }
  }
]
```

#### Identity and parent

- `local_key` is required and is saved as the `onpage_local_key` post meta on the variation.
- Resolution: if the element contains a valid `id`, that variation is updated. If `id` is missing but the `local_key` already exists under the parent, that variation is updated. Otherwise a new variation is created.
- A malformed `id` (not a positive ID) is silently ignored, as if it were absent.
- An explicit `id` that names a variation of another parent returns `409 duplicate_local_key` (`… local_key '<key>' already exists for another parent product`).
- You must send `parent_id` (WordPress ID) or `parent` (`local_key`). `parent_id` wins when both are sent. Both must then refer to the same product or to translations of it (same WPML translation group); otherwise the request returns `409 parent_mismatch`.
- The parent must be a `variable` WooCommerce product. It can be created or converted with `POST /woocommerce/products` using `props.product_type: "variable"`.
- The endpoint does not create or configure the parent's attributes automatically.

#### `attributes`

- Required on create, and must be a non-empty object. On update it can be omitted to leave the variation's attributes unchanged. When sent, `{}` or `null` returns `400 invalid_param` (`Parameter 'attributes' must not be empty`).
- Each attribute sent must exist on the parent and be flagged as a variation attribute (`variation=true`).
- Each variation attribute must resolve to a single scalar option. WPML language maps such as `{ "en": "Red", "it": "Rosso" }` are accepted. Lists such as `["Petrol", "Hybrid"]` are not valid for a single variation.
- If a variation attribute contains a language map but WPML is not installed or active, the request returns `500 wpml_required`.
- For global attributes (`pa_color`) the value can be the term slug, name or ID. The variation stores the WooCommerce term slug. The term must be one of the options set on the parent, through the product's [`attributes`](#attributes) or in the WooCommerce admin.
- For custom attributes, the value must be one of the options configured on the parent.
- A value that is not one of the parent's options returns `400 invalid_param` (`… option '<value>' is not enabled on the parent product`). A parent attribute that has no options at all accepts any value.
- **Unique combination.** Two variations of the same parent cannot have the same attribute combination: WooCommerce would always sell the first one. When `attributes` is sent and another variation of the parent (not in the trash) already has the same values, the request returns `409 duplicate_variation`, for example `WooCommerce Variant Product :: Element 0 :: Parent product 501 already has variation 702 with attributes [pa_color=red, size=(any)]`.
  - Values are compared case-insensitively.
  - A parent variation attribute that the variation leaves unset counts as "any" (`(any)` in the message).

#### `name`, `short_description`, `props`, `status`, `image`

- `name` (optional) sets the variation name. It can be a string or a WPML language map. An empty value leaves the name unchanged; a value that resolves to an object or a list returns `400 invalid_param`.
- `short_description` (optional) sets the variation description, shown as `description` in `GET` responses. `description` is accepted as an alias; `short_description` wins when both are sent. Both can be WPML language maps, and `null` clears the description.
- `long_description` is **not** saved on variations. It is only read to detect the payload languages.
- Variations have no `terms`, `gallery`, `slug` or `downloads`. These keys, and any other unknown key, are ignored with no error.

- `props` accepts: `sku`, `regular_price`, `sale_price`, `price`, `manage_stock`, `stock_quantity`, `stock_status`, `backorders`, `weight`, `length`, `width`, `height`, `virtual`, `downloadable`, `tax_class`, `menu_order`, `image_id`.
- Each `props` value can be a WPML language map, as for products. The value resolved for a language must be a scalar or `null`; an object or a list returns `400 invalid_param` instead of clearing the field.
- With WPML, `props.sku` is applied only to the source variation: WooCommerce requires globally unique SKUs, so translated variations cannot store the same SKU.
- `status` on variations:
  - optional. When omitted on update, the variation keeps its current status;
  - any value other than those below returns `400 invalid_param` with the message `Parameter 'status' must be one of: publish, private, enabled, disabled, draft, pending`;
  - `publish`/`enabled` → enabled variation;
  - `private`/`disabled` → disabled variation;
  - for compatibility, `draft` and `pending` are saved as `private`, because the WooCommerce admin does not show variations with status `draft`;
  - a variation that uses an attribute value the parent no longer offers stays `private`, and the requested status is held until its attributes are valid again (see [`attributes` of products](#attributes)).
- `image`:
  - a remote URL to import and set as the variation image, or the `attachment_id` (JSON integer) of a file already in the Media Library (e.g. uploaded with `POST /media`);
  - `image: null` removes the image;
  - it can be a WPML language map, for example `{ "en": "https://cdn.example.com/en/shirt.jpg", "it": "https://cdn.example.com/it/shirt.jpg" }`. Each translated variation receives its own image.

#### `acf_fields`

- Optional associative object `field_name => value` for ACF fields attached to the `product_variation` post type.
- Keys must be ACF technical names or ACF field keys that exist on the variation's field group. Unknown fields return `400 invalid_param`.
- An `image` or `file` ACF field with a valid URL is imported or reused from the Media Library, and the field stores the `attachment_id`. To clear an `image` or `file` field, pass `null`, an empty string, `0` or `"0"`.
- Every value in `acf_fields` accepts a WPML language map. Each translated variation receives the value for its language.

#### Side effects

- If the variable parent has native WooCommerce downloads, the variation inherits them automatically, so they are visible in the WooCommerce UI. A variation with its own, different downloads is left alone (see [`downloads`](#downloads-native-woocommerce-downloadable-files)).
- After saving the variation, the endpoint syncs the variable parent and clears the parent's WooCommerce transients.

#### Multilingual behaviour (WPML)

- If `name`, `description`, `short_description`, `long_description`, `image`, `attributes`, `acf_fields` or a `props` value contain language maps, the endpoint updates the variation of the resolved parent. It then creates or updates the variations for the payload languages that already have a translation of the parent.
- Languages without a translated parent are ignored until that parent translation exists.
- On update, a language map writes only the languages it contains, as on `POST /woocommerce/products`. `{"short_description": {"en": "Red"}}` changes the English variation and leaves the Italian description as it is. The same applies to `name`, `description`, `image`, each value in `props`, `acf_fields` and `attributes`. An attribute sent as a map without a language keeps that language's current value. This includes the variation in the parent's language: a map without that language leaves it as it is.
- Shared values are written to every existing translated variation, including those in a language no map contains. A language that no map contains is never created. A new translation created by the same request still falls back to the first language of the payload, so it never starts empty.
- Each translated variation is looked up under its own translated parent: first the variation of that language in the WPML group of the source variation, then the variation with the same `local_key`. If neither exists, it is created there and linked to the WPML group. This also works when the payload has an `id`: the `id` names the variation in the parent's language only.
- The response contains the variation ID in the language of the parent resolved from `parent_id` or `parent`. The other translated variations are created or updated in the same batch.

Response `200`:

```json
[701]
```

Main errors:

- `400 invalid_param` when:
  - the body is not a valid JSON array, or an element is not a non-empty object;
  - `local_key` is missing;
  - the parent is missing;
  - the parent is not `variable`;
  - `attributes` or `acf_fields` are not valid objects, or `attributes` is empty;
  - an attribute is not configured as a variation attribute on the parent, or its value is not an option enabled on the parent;
  - `status` is not one of the supported values;
  - `image` is neither an existing `attachment_id` nor a valid URL, or its host does not resolve to a public address;
  - `acf_fields` contains ACF fields that do not exist for the variation;
  - a `props` value does not resolve to a scalar or `null`;
  - WooCommerce rejects a `props` value, for example a `sku` already used by another product.
- `400 input_invalid` if an `image`/`file` value in `acf_fields` is neither an existing `attachment_id` nor a valid URL.
- `404 not_found` if the parent, the variation or an attribute term does not exist.
- `409 duplicate_local_key` if the `local_key` already belongs to another variation of the same parent, or to a variation of a parent outside this parent's translation group, or if the explicit `id` names a variation of another parent.
- `409 duplicate_variation` if another variation of the parent already has the same attribute combination.
- `409 parent_mismatch` if `parent_id` and `parent` are not the same product or translations of it.
- `413 file_too_large` if `image` points to a file over the [size limit](#remote-file-imports).
- `500 acf_error` if ACF refuses to save a field.
- `500 woocommerce_required` if WooCommerce is not active.
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active.
- `500 wpml_error` if WPML cannot resolve the translation group of the variation.
- `500 request_failed` if WooCommerce or WordPress fail to save the variation.

### DELETE `/woocommerce/variant-products`

Deletes WooCommerce variations by `local_key`. If several variations share the same `local_key` (one per language of the parent's group), **all** of them are deleted.

After the deletions, each parent that lost a variation is resynced once (`WC_Product_Variable::sync()`), so its price range, `_price` and stock status no longer count the deleted variations.

Body:

```json
[2001]
```

Optional query:

```text
?ignore=1
```

Response `200`:

```json
null
```

Main errors:

- `400 invalid_param` if the body is not a JSON array, or an element is not a valid `local_key` (`WooCommerce Variant Product :: Element N :: Invalid delete value; expected a positive integer or non-empty string local_key`)
- `404 not_found` if a `local_key` matches no variation and `ignore` is not set
- `500 delete_failed` if a variation could not be deleted
- `500 request_failed` if a parent product cannot be resynced
- `500 woocommerce_required`

## Media

### GET `/media`

Returns the list of media attachments (Media Library), with optional filters. Use it to find the `attachment_id`s to pass to `DELETE /media`, and to find files already in the library by `token` without uploading them again.

**Pagination:** yes, through `per_page`/`page` and the `X-WP-Total`/`X-WP-TotalPages` headers (details below).

Optional query:

```text
?post_id=321
?mime_type=image/jpeg
?token=aaa111bbb222.1920x1920-contain.jpg
?token=aaa111bbb222.1920x1920-contain.jpg,ccc333ddd444.600x600-contain.webp
?source_url=https://storage.onpage.it/aaa111bbb222.1920x1920-contain.jpg/foto.jpg
?page=1
?per_page=100
```

| Parameter | Description |
| --- | --- |
| `post_id` | Filters by parent post (`post_parent`). `0` returns the media attached to no post. A value that is not a positive ID or `0` returns `400 invalid_param`. The post itself is not checked: a positive ID that matches no post returns `200` with `[]`. |
| `mime_type` | Filters by MIME type, with WordPress `post_mime_type` rules: a full type (`image/jpeg`) or a main type alone (`image`, which matches every image). |
| `token` | Filters by On Page® storage segment (`_onpage_file_token`, see `POST /media`). Accepts **several comma-separated tokens**, so files already in the library can be re-adopted in bulk with one request instead of one per file. Empty tokens are ignored; if none is left, the request returns `400 invalid_param`. |
| `source_url` | Filters by exact source URL (`_onpage_source_url`). Single value. |
| `per_page` | Default `100`, maximum `100`. |
| `page` | Default `1`. |

Notes:

- The list stays paginated with `token` too: if you ask for more than `per_page` tokens (max `100`), results span several pages. Each row reports its own `token`, so map tokens to attachments from the response.
- `token` and `source_url` are combined with the other filters (`post_id`, `mime_type`) with AND.
- Results are sorted by creation date, newest first.
- Only attachments with the WordPress status `inherit` are listed.

Response `200`:

```json
[
  {
    "attachment_id": 501,
    "filename": "image-1.jpg",
    "title": "image-1",
    "url": "https://example.com/wp-content/uploads/2026/04/image-1.jpg",
    "mime_type": "image/jpeg",
    "post_id": 321,
    "hash": "1d8b874f1a5f4a9f9b8c3e4f6a7b2c5d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b",
    "token": "aaa111bbb222.1920x1920-contain.jpg",
    "source_url": "https://storage.onpage.it/aaa111bbb222.1920x1920-contain.jpg/image-1.jpg"
  },
  {
    "attachment_id": 502,
    "filename": "brochure.pdf",
    "title": "brochure",
    "url": "https://example.com/wp-content/uploads/2026/04/brochure.pdf",
    "mime_type": "application/pdf",
    "post_id": 0,
    "hash": null,
    "token": null,
    "source_url": null
  }
]
```

Response notes:

- `hash` is the SHA-256 checksum of the physical file's content (`hash_file('sha256', ...)` on `get_attached_file()`). It is cached in the `_onpage_file_hash` attachment meta, together with the file path, size and modification time it was computed for. When one of them changes, the file is hashed again. It is `null` if the physical file is missing or unreadable.
- `token` is the On Page® storage segment indexed on the attachment. It is `null` for media added in other ways (manually in the Media Library, imported from a URL that is not an On Page® storage URL, or imported before the segment was indexed; see `POST /migration`).
  - Earlier plugin versions could index a wrong segment for URLs outside `onpage.it`. Such a value is still shown here and can still be found with `?token=`, but the URL imports no longer use it for deduplication.
- `source_url` is the remote URL the media was imported from. It is `null` for files uploaded directly.

Pagination headers:

- `X-WP-Total`: total number of attachments matching all the filters (`post_id`, `mime_type`, `token`, `source_url`), regardless of the page.
- `X-WP-TotalPages`: total number of pages, `ceil(X-WP-Total / per_page)`.
- The client knows from the current response whether it is on the last page (`page >= X-WP-TotalPages`). There is no need to request an extra page and wait for an empty array.

Walk-through (250 attachments, `per_page=100` → 3 pages):

```text
GET /media?page=1&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continue)
GET /media?page=2&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continue)
GET /media?page=3&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (page == X-WP-TotalPages → stop, no request for page=4)
```

### POST `/media`

Uploads one or more files via `multipart/form-data`, using the native WordPress media flow.

Content-Type:

```http
multipart/form-data
```

Supported fields:

| Field | Description |
| --- | --- |
| file fields | One or more file fields, e.g. `file`, `files[]` or any other name compatible with PHP `$_FILES`. |
| `post_id` | Optional. Attaches the attachments to an existing post. |
| `attachment_id` | Optional. Replaces the content of an existing attachment, when uploading a single file. With more than one file it returns `400 invalid_param`. |
| `attachment_ids` | Optional. Array aligned with the uploaded files, to replace one or more existing attachments in multi-file requests (also accepted as a JSON string). Empty positions (`null` or empty string) mean "no replacement for that file". Entries beyond the number of files are ignored. |
| `token` | Optional. The On Page® storage segment the file comes from (`<token>[.<format>]`, e.g. `aaa111bbb222.1920x1920-contain.jpg`), when uploading a single file. |
| `tokens` | Optional. Array aligned with the uploaded files, same meaning, for multi-file requests (also accepted as a JSON string). Empty positions (`null` or empty string) mean "no token for that file". |

Behaviour:

- The controller accepts both single files and arrays of files in the same field.
- Files are flattened into one list and processed in order.
- Each file is saved with `wp_handle_upload()`.
- **SVG.** A file whose name ends in `.svg` is sanitized in place (`Svg::sanitizeFile()`) before `wp_handle_upload()`, as for remote imports. If sanitizing fails, the request returns `500 request_failed` (`Media :: Element N :: Failed to upload file '<name>' :: <reason>`, or `replace` when an attachment is replaced). WordPress itself still refuses SVG uploads unless another plugin allows the type; in that case the request fails with `500 upload_failed`.
- If no existing attachment is given, the controller creates a new attachment with `wp_insert_attachment()`.
- If `attachment_id` or a value in `attachment_ids` is given, the controller keeps the same WordPress attachment and replaces only the physical file and metadata.
- **Token:**
  - The `token` is saved on the attachment in the `_onpage_file_token` meta, exactly as sent.
  - It identifies the file's **content**, not its location. The same file requested in different formats (`.1920x1920-contain.jpg`, `.600x600-contain.webp`) therefore stays on separate attachments.
  - The endpoint is **idempotent on the token**: if a `token` is already on an attachment in the library (and its physical file still exists), the uploaded bytes are discarded and that attachment is returned with `action: "linked"`, without creating anything. If `post_id` is set, the existing attachment is still re-attached to that post.
  - An explicit `attachment_id`/`attachment_ids` takes precedence: the replacement always happens, and any `token` sent is rewritten on the replaced attachment. A replacement **without** a `token` removes the stored token, because it described the bytes just overwritten.
- Metadata is generated with `wp_generate_attachment_metadata()`.
- When an existing attachment is replaced, the old file and its generated sizes are removed.
- If a file fails, the request stops immediately and returns a `WP_Error`.

Multiple upload:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer <token>" \
  -F "files[]=@/path/image-1.jpg" \
  -F "files[]=@/path/image-2.png" \
  -F "post_id=321"
```

Single-field upload:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/brochure.pdf"
```

Replacing an existing attachment:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/image-new.jpg" \
  -F "attachment_id=501"
```

Upload with the On Page® storage token:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/image-1.jpg" \
  -F "token=aaa111bbb222.1920x1920-contain.jpg"
```

Multiple upload with tokens aligned to the files:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer <token>" \
  -F "files[]=@/path/image-1.jpg" \
  -F "files[]=@/path/image-2.png" \
  -F "tokens[]=aaa111bbb222.1920x1920-contain.jpg" \
  -F "tokens[]=ccc333ddd444.1920x1920-contain.png"
```

Mixed create + replace in the same multiple upload:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer <token>" \
  -F "files[]=@/path/image-1.jpg" \
  -F "files[]=@/path/image-2.png" \
  -F "attachment_ids[]=501" \
  -F "attachment_ids[]="
```

Response `200`:

```json
[
  {
    "field": "files",
    "key": "0",
    "action": "created",
    "attachment_id": 501,
    "filename": "image-1.jpg",
    "title": "image-1",
    "url": "https://example.com/wp-content/uploads/2026/04/image-1.jpg",
    "mime_type": "image/jpeg",
    "post_id": 321
  },
  {
    "field": "files",
    "key": "1",
    "action": "replaced",
    "attachment_id": 502,
    "filename": "image-2.png",
    "title": "image-2",
    "url": "https://example.com/wp-content/uploads/2026/04/image-2.png",
    "mime_type": "image/png",
    "post_id": 321
  }
]
```

Response notes:

- `field` is the name of the file field received in the form.
- `key` is the original position of the file in the payload. For `files[]` it is typically `"0"`, `"1"`, and so on. For a single, non-array field (`file=@…`) it is `""`. For nested arrays the positions are joined with dots, for example `"0.1"`.
- `action` is:
  - `created` for new attachments;
  - `replaced` when an existing attachment was updated;
  - `linked` when the `token` sent was already in the library and the attachment was reused without uploading anything.
- `token` is present only if the file was sent with a token, and reports the value indexed on the attachment.
- `post_id` is `0` if the file was not attached to a post.

Main errors:

- `400 invalid_param` if the request contains no files or `post_id` is invalid.
- `400 invalid_param` if `attachment_id` and `attachment_ids` are used together or have an invalid format.
- `400 invalid_param` if `token` and `tokens` are used together, if `token` is used with more than one file, or if a token is not a non-empty string.
- `400 upload_failed` if PHP reports an upload error on the file.
- `404 not_found` if `post_id` is set but the post does not exist.
- `404 not_found` if a referenced `attachment_id` does not exist.
- `500 upload_failed` if WordPress refuses to save the file.
- `500 request_failed` if the WordPress attachment is not created or updated correctly.

### POST `/media/link`

Imports one or more remote files by URL (or links attachments already in the Media Library by `attachment_id`). It saves/verifies them in the Media Library, attaches them to an existing post through `post_parent`, and updates the ACF field whose name matches the key inside `files`.

Content-Type:

```http
application/json
```

Body:

```json
{
  "post_id": 321,
  "files": {
    "image": "https://cdn.example.com/image-1.jpg",
    "image_2": "https://cdn.example.com/image-2.jpg"
  }
}
```

Behaviour:

- `post_id` is required and must refer to an existing post.
- It updates that post only, not its WPML translations. To set a file on every language, send it in `files` on [`POST /posts`](#post-posts).
- `files` is required and must be a non-empty JSON object.
- Each key of `files` is the name of the ACF field to update on the post. It must be an ACF field of the post's type: any other key returns `400 invalid_param` (`Media :: ACF field '<name>' not found for post type '<type>'`), checked before anything is downloaded. Only the existence of the field is checked, not its type: any ACF field is accepted.
- The entries are processed one by one, in payload order. If an entry fails, the entries before it stay saved.
- Each value of `files` must be a valid URL reachable by WordPress, the `attachment_id` (JSON integer) of a file already in the Media Library (e.g. uploaded with `POST /media`), or `null`.
- Each remote file is downloaded and imported into the Media Library through the WordPress sideload flow. An `attachment_id` is instead verified and re-attached to the requested `post_id`, without download.
- `null` clears the ACF field. Nothing is downloaded or deleted.
- If the same URL was already imported by the plugin, the same attachment is reused and re-attached to the requested `post_id`.
- After the import, reuse or direct link, the controller saves the resulting `attachment_id` in the matching ACF field.
- For URLs, the controller saves the source URL in the `_onpage_source_url` meta.
- **On Page® storage URLs** (`https://storage.onpage.it/<token>[.<format>]/<name>`, or `https://<host>/api/storage/<token>[.<format>]/<name>` where `<host>` is `onpage.it` or a subdomain, such as `https://app.onpage.it/api/storage/…`):
  - the controller extracts the segment and also saves it in the `_onpage_file_token` meta, the same index used by `POST /media`;
  - reuse looks up the segment **first**, then the exact URL. A file renamed on On Page® changes URL but not segment, so it is neither downloaded again nor duplicated;
  - URLs from other hosts produce no segment, even when their path looks the same, and are reused by exact URL only.
- The allowed file types are listed in [Remote file imports](#remote-file-imports).
- The response returns one item per entry in `files`, in payload order.

Example:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media/link" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "post_id": 321,
    "files": {
      "image": "https://cdn.example.com/image-1.jpg",
      "image_2": "https://cdn.example.com/image-2.jpg"
    }
  }'
```

Response `200`:

```json
[
  {
    "field": "image",
    "action": "created",
    "attachment": {
      "action": "created",
      "attachment_id": 601,
      "filename": "image-1.jpg",
      "title": "image-1",
      "url": "https://example.com/wp-content/uploads/2026/04/image-1.jpg",
      "mime_type": "image/jpeg",
      "post_id": 321,
      "source_url": "https://cdn.example.com/image-1.jpg"
    }
  },
  {
    "field": "image_2",
    "action": "linked",
    "attachment": {
      "action": "linked",
      "attachment_id": 455,
      "filename": "image-2.jpg",
      "title": "image-2",
      "url": "https://example.com/wp-content/uploads/2026/03/image-2.jpg",
      "mime_type": "image/jpeg",
      "post_id": 321,
      "source_url": "https://cdn.example.com/image-2.jpg"
    }
  }
]
```

Response notes:

- `field` is the key of `files`, i.e. the ACF field name.
- `action` is:
  - `created` when the URL was downloaded into a new attachment;
  - `linked` when an existing attachment was reused: a URL already imported, or an `attachment_id`;
  - `cleared` when the value was `null`.
- `attachment` describes the attachment now stored in the field. It has the same `action`. `source_url` is present only for URL values.
- For `cleared`, `attachment` is `null`: `{"field": "image", "action": "cleared", "attachment": null}`.

Main errors:

- `400 invalid_param` if the body is not a valid JSON object.
- `400 invalid_param` if `post_id` is not a positive integer.
- `400 invalid_param` if `files` is not a non-empty object.
- `400 invalid_param` if a key of `files` is not an ACF field of the post type (`Media :: ACF field '<name>' not found for post type '<type>'`).
- `400 invalid_param` if a value in `files` is neither an existing `attachment_id`, a valid URL nor `null`.
- `404 not_found` if `post_id` does not exist.
- `400 invalid_param` if the URL's host does not resolve to a public address (see [Remote file imports](#remote-file-imports)).
- `413 file_too_large` if the remote file is larger than the size limit.
- `500 request_failed` if downloading or importing the remote file fails, including a file type WordPress refuses or a redirect to a non-public address (see [Remote file imports](#remote-file-imports)).
- `500 acf_error` if ACF cannot save the field.

### DELETE `/media`

Deletes one or more media attachments by numeric ID.

Body:

```json
[501, 502, 503]
```

Optional query:

```text
?ignore=1
```

Behaviour:

- The body must be a JSON array (see [Request bodies](#request-bodies)). `[]` does nothing and returns `200` with `null`.
- Each element must be a positive numeric ID (see [Numeric IDs](#general-conventions)). The lenient rule applies: `2.0` and `" 12"` are accepted. `true`, `"1e3"` or `1.9` are rejected, not cast.
- Only posts of type `attachment` are deleted.
- Deletion uses `wp_delete_attachment($id, true)`, so it is forced. It also removes the physical file and all associated metadata, including the On Page® index (`_onpage_file_token` and `_onpage_source_url`). After deletion the media can no longer be re-adopted by token.
- If an element fails, the request stops immediately.
- With `?ignore` (any value, see [`?ignore` on DELETE](#ignore-on-delete)), attachments that are not found are skipped without error. For those IDs, any orphaned On Page® index left behind (attachment deleted outside WordPress) is still removed.

Example:

```bash
curl -X DELETE \
  "https://example.com/wp-json/onpage/v1/media?ignore=1" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '[501, 502, 503]'
```

Response `200`:

```json
null
```

Main errors:

- `400 invalid_param` if the body is not a JSON array (`Media :: Request body must be a JSON array`) or contains invalid IDs.
- `404 not_found` if an attachment does not exist and `ignore` is not set.
- `500 delete_failed` if WordPress fails to delete an attachment.

## Taxonomies

### GET `/taxonomies`

Returns all ACF taxonomies.

**Pagination:** none. The response always contains **all** taxonomies; there is no `per_page`/`page`.

Response `200`:

```json
[
  {
    "id": 67,
    "key": "brand",
    "singular_label": {
      "it": "Brand",
      "en": "Brand"
    },
    "plural_label": {
      "it": "Brands",
      "en": "Brands"
    },
    "description": "Product brand",
    "hierarchical": false
  }
]
```

- `singular_label` and `plural_label` can be plain strings or `language => label` maps, depending on the static data saved by the plugin.

### POST `/taxonomies`

Creates or updates one or more ACF taxonomies (upsert by `key`).

Example payload:

```json
[
  {
    "key": "brand",
    "singular_label": {
      "it": "Brand",
      "en": "Brand"
    },
    "plural_label": {
      "it": "Brands",
      "en": "Brands"
    },
    "description": "Product brand",
    "hierarchical": false
  }
]
```

| Field | Required | Notes |
| --- | --- | --- |
| `key` | yes | The taxonomy slug: at most 32 lowercase letters, digits, `_` or `-` (the form `sanitize_key()` keeps). Any other value, such as `Brand` or `my type`, returns `400 invalid_param`. |
| `singular_label` | yes | A missing or empty label returns `400 invalid_param`. |
| `plural_label` | yes | A missing or empty label returns `400 invalid_param`. |
| `description` | no | |
| `hierarchical` | no | Default `false`. |
| `object_type` | no | List of post types the taxonomy is attached to. Default `[]`. |
| `capabilities` | no | Object with `manage_terms`, `edit_terms`, `delete_terms` and `assign_terms`. Defaults: `manage_categories` for the first three, `edit_posts` for `assign_terms`. The legacy key `deleteTerms` is still read when `delete_terms` is absent. |

Other ACF taxonomy settings are also accepted with their ACF names, for example `public`, `show_ui`, `show_in_rest`, `show_admin_column`, `rewrite` and `default_term`.

Behaviour:

- **Idempotent upsert.** If a taxonomy with the same `key` exists, it is updated in place (same ACF ID); otherwise it is created. Sending the same payload again converges on the same record, with no duplicate errors.
- The `key` is the stable identifier of the taxonomy (also used as its slug). There is no separate `local_key`.
- A missing or empty `key` returns `400 invalid_param` with the message `Taxonomy :: Element N :: Parameter 'key' is required`.
- Labels can be plain strings or per-language maps.
- If labels are per-language maps but WPML is not installed or active, the request returns `500 wpml_required`.
- If labels are multilingual, the service saves a custom map in an option (`onpage_taxonomy_label_translations`) and also tries to register them in ACFML.
- The taxonomy is flagged as translatable in WPML, when available.
- `flush_rewrite_rules()` runs at the end.

Response `200`:

```json
[67]
```

Main errors:

- `400 invalid_param` if the body is not a JSON array, an element is not a non-empty object, `key` is missing, empty or not a valid slug, or a label is missing or empty
- `500 acf_error`
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active

### DELETE `/taxonomies`

Deletes taxonomies by ACF ID or by slug.

Body:

```json
[67, "brand"]
```

Optional query:

```text
?ignore=1
```

Steps performed, in order:

1. Deletes all terms of the taxonomy.
2. Deletes the ACF taxonomy.
3. Deletes the ACF field groups with location `taxonomy == <slug>`.
4. Removes the saved translated labels.
5. Removes the taxonomy from WPML's list of translatable taxonomies.
6. Runs `flush_rewrite_rules()`.

Notes:

- An integer is an ACF taxonomy ID. A string is a taxonomy slug. It is matched as sent first, so a taxonomy saved with a key such as `Brand` before keys were validated can still be deleted, then sanitized with `sanitize_key`.
- The terms of a taxonomy saved with `active: false` (not registered) are deleted too.
- `0` or a negative integer is looked up as an ID and matches nothing: `404 not_found`, or skipped with `?ignore`. An empty string returns `404 not_found` with the message `Taxonomy :: Taxonomy '' not found`, or is skipped with `?ignore`.
- Any other element returns `400 input_invalid` with the message `Taxonomy :: Element N :: Invalid delete value; expected taxonomy ID (int) or slug (string)`.
- Only ACF taxonomies are deleted. With `?ignore`, a slug that is not an ACF taxonomy (for example `product_cat`) is skipped: its terms, field groups and WPML settings stay untouched.

Response `200`:

```json
null
```

Main errors:

- `400 invalid_param` if the body is not a JSON array
- `400 input_invalid` for an element that is neither an integer nor a string
- `404 not_found` if an ID or slug is not an ACF taxonomy and `ignore` is not set
- `500 delete_failed` if a term, a field group or the taxonomy cannot be deleted; a WordPress error on a term is appended to the message
- `500 request_failed` if the terms of the taxonomy cannot be listed

## Terms

All term operations go through `/terms`: list/search (`GET`), create/update (`POST`) and delete (`DELETE`).

```text
/terms    (GET: list/search, POST: create/update, DELETE)
```

The taxonomy is passed as `?taxonomy=` (query string, optional on `GET`/`DELETE`) or as the `taxonomy` body field (required on `POST`). It accepts either the **slug** (e.g. `product_cat`, `brand`, `pa_color`) or the **numeric ACF ID**. The slug takes precedence and is recommended (stable across environments, and valid for non-ACF taxonomies too). The numeric ID is still supported for backward compatibility. Example: `/terms?taxonomy=product_cat`.

There are no `/taxonomies/{id}/terms` routes: the taxonomy is never part of the path.

### GET `/terms`

Lists or searches terms. The taxonomy goes in the query string (`?taxonomy=`), like `GET /posts?type=`.

**Pagination:** none. The response always contains **all** terms matching the filters; there is no `per_page`/`page`.

Optional query:

```text
?taxonomy=brand
?taxonomy=product_cat&name=Chairs
?taxonomy=brand&name[it]=Acme&name[en]=Acme
?name=Acme
?name[it]=Rosso&name[en]=Red
?taxonomy=product_cat&parent_id=575
?taxonomy=product_cat&parent_lk=6000
```

| Parameter | Description |
| --- | --- |
| `taxonomy` | Optional. Slug or numeric ACF ID. If sent but not resolved → `404 not_found`. If **omitted**, listing and `name` search cover **all taxonomies** (each term's taxonomy is taken from the term itself). |
| `name` | Exact-name search: a **string** (`?name=Chairs`, current request language) or a **language map** (`?name[it]=Sedie&name[en]=Chairs`, each name searched in its WPML language, **union** of the results). |
| `parent_id` | WP term ID of the parent: returns its **direct children**. Works without `taxonomy`. |
| `parent_lk` | `local_key` of the parent: returns its **direct children**. Expanded to **all WPML translations** of the parent (children are searched under each). **Requires `taxonomy`** to resolve the local_key (`400` if missing). |

- `parent_id` and `parent_lk` are **mutually exclusive** (`400` if both are sent). A `parent_lk` that resolves to no term returns an empty list. Both have lower precedence than `name`.
- Without `name` or `parent_*`, the full list of terms is returned (of the given taxonomy, or of all taxonomies if `taxonomy` is omitted).
- The response is **always a list**. With `name`, it holds **one object per distinct translation group** (the default-language term is the representative when available), carrying the `translations` map. Names that are translations of the same term → one object. Names from different groups → several objects. This mirrors `GET /woocommerce/products?name=`.
- Without `name`, translations are not grouped: every term is a separate item.

> **Note:** without `taxonomy` and without `name`, the endpoint returns **all** terms of all taxonomies. On large installations, always pass `taxonomy` and/or `name`.

Response `200`:

```json
[
  {
    "id": 10,
    "name": "Acme",
    "slug": "acme",
    "local_key": 4001,
    "description": "",
    "parent": 0,
    "count": 4,
    "taxonomy": "brand",
    "translations": {
      "it": 10,
      "en": 11
    },
    "acf_fields": {
      "logo": 123
    }
  }
]
```

- `translations` maps each language code to the term ID in that language (WPML translation group). Without WPML it is an empty map. It is present in every term response (single, list and search).

Errors:

- `400 invalid_param` if both `parent_id` and `parent_lk` are sent (`Term :: Use only one of 'parent_id' and 'parent_lk'`)
- `400 invalid_param` if `parent_lk` is sent without `taxonomy` (`Term :: 'parent_lk' requires 'taxonomy'`)
- `404 not_found` if `taxonomy` is sent but cannot be resolved
- `500 request_failed` if WordPress fails to list the terms

### POST `/terms`

Creates or updates terms in batch. Each payload element states its own `taxonomy`, so a single request can write terms of different taxonomies.

Simple payload:

```json
[
  {
    "taxonomy": "brand",
    "name": "Acme",
    "slug": "acme",
    "description": "Main brand",
    "local_key": 4001,
    "parent": 0,
    "acf_fields": {
      "headline": "Official reseller",
      "logo": "https://example.com/uploads/acme-logo.png"
    }
  }
]
```

| Field | Required | Notes |
| --- | --- | --- |
| `taxonomy` | yes | Slug (e.g. `product_cat`, `brand`, `pa_color`) or numeric ACF ID. Missing → `400 invalid_param`; not resolved → `404 not_found`. |
| `id` | no | If present, the term is updated. A malformed `id` (not a positive ID) is silently ignored: the term is then resolved by `local_key` or `slug`, and a new term may be created. |
| `name` | yes | Required on every save, update included. |
| `slug` | no | |
| `description` | no | Omitted on update: the stored description is kept. |
| `local_key` | no | Stable external identifier. If sent, it must be a positive integer or a non-empty string (`400 invalid_param`). |
| `parent` | no | WordPress term ID of the parent. Default `0` (top level). A term that does not exist in the taxonomy returns `404 not_found`; a value that is not a term ID, `0` or `null` returns `400 invalid_param`. Unlike `id`, a malformed `parent` is never ignored. |
| `acf_fields` | no | Associative object `field_name => value`. |

`acf_fields` behaviour (see also [Handling `acf_fields`](#handling-acf_fields)):

- Text and scalar fields are saved directly on the term.
- An `image` ACF field receiving a valid URL: the file is imported or reused from the Media Library, and the `attachment_id` is saved.
- Remote SVGs are accepted only after validation/sanitization, and are imported as `image/svg+xml`.
- An `image` ACF field receiving `null`, an empty string, `0` or `"0"` is cleared.

Resolving the term to update:

1. `id` first, if present (it must exist, otherwise `404 not_found`).
2. Otherwise, a term with the same `local_key`.
3. Otherwise, a term with the payload `slug` that has no `local_key` or the same one; otherwise a new term.

Slugs owned by another element:

- When the term is already resolved (by `id` or `local_key`), a term found by the payload `slug` replaces it only if it belongs to the same element: the same term, a member of the same WPML translation group, or a term with the same `local_key`.
- If the slug belongs to another element, the element's own term is updated and **keeps its current slug**. The other term is not touched. The request still returns `200`.
- A new translation adopts an existing same-language term with its slug only if no other `local_key` owns that term.

`local_key` behaviour:

- Saved as the `onpage_local_key` term meta.
- Written on the **whole WPML translation group** of the resolved term. When a term is updated (even by `id` only), the `local_key` is also applied to translations not included in the payload.
- `409 duplicate_local_key` is returned only when the request sends an explicit `id` and the `local_key` already exists on another translation group of the same taxonomy. Without `id`, the `local_key` identifies the term to update.

#### Multilingual support with WPML

`name`, `slug`, `description` and the values inside `acf_fields` can be sent as per-language maps:

```json
[
  {
    "taxonomy": "brand",
    "local_key": 4001,
    "name": {
      "it": "Acme Italia",
      "en": "Acme"
    },
    "slug": {
      "it": "acme-italia",
      "en": "acme"
    },
    "description": {
      "it": "Descrizione italiana",
      "en": "English description"
    },
    "acf_fields": {
      "headline": {
        "it": "Titolo IT",
        "en": "EN title"
      },
      "logo": {
        "it": "https://example.com/uploads/logo-it.png",
        "en": "https://example.com/uploads/logo-en.png"
      }
    }
  }
]
```

Slugs of translations:

- If `name` is multilingual but `slug` is a single string, the slug is applied only to the base term. For translations without an explicit `slug`, WordPress generates a slug from the translated name.
- If a translation's name equals the base-language name (because `name` is a shared string, or because the language map repeats the same value, e.g. `{"it":"Legno","en":"Legno"}`), the endpoint generates a distinct technical slug per language (`<slug-base>-<language>`). This is needed because WordPress rejects same-name terms under the same parent.
- To control translated slugs, send `slug` as a language-value map.
- An explicit translated slug equal to the base term's slug is not used. The translation then gets a slug from its translated name, or `<slug-base>-<language>` when that name also gives the base slug.

Rules:

- If multilingual values are present and WPML is not installed or active, the request returns `500 wpml_required`.
- The base language used to create the term is the WPML default language, or else the first language in the payload that actually has a name.
- **`null` values per language.** Inside a `name` map (or `slug`/`description`), a language can be `null` to mean "no translation" (e.g. `{"it": "Sigillante Ibrido", "en": null, "es": null}`).
  - `null` languages are ignored: they produce no translation and do not count as a name.
  - The base term is created in the first language that has a name, even when the WPML default language is one of the `null` ones.
  - The request fails with `400 invalid_param` (`Name is required`) **only** if no language in the map has a non-empty name.
- **Languages not active in WPML.** A map is recognized as a language map by its shape (every key looks like a language code). Its codes are then intersected with the active WPML languages, and inactive codes are ignored. For example, when the site only has `it` active and receives `{"it": "...", "en": null, "es": null}`, only `it` is used.
  - If none of the codes is active, the map is treated as a shared value. The plugin then uses the first language of the map that has a name.
  - At least one language must have a name, otherwise `400 invalid_param`.
- Translations are created or updated in the same WPML group as the base term.
- On update, a language map writes only the languages it contains, as on `POST /posts`. `{"name": {"en": "Chairs", "it": "Sedie"}, "description": {"en": "Comfy"}}` changes the English description and leaves the Italian one as it is. The same applies to `slug` and to each value in `acf_fields`. A translation is written only when `name` has a value for its language. A new translation created by the same request still falls back to the first language that has a name, so it never starts empty.
- `local_key` is propagated to the translated terms too, as term meta.

Response `200`:

```json
[10, 11]
```

Main errors:

- `400 invalid_param` if the body is not a JSON array, an element is not a non-empty object, `taxonomy` is missing, `local_key` or `parent` is invalid, or no language has a name
- `400 invalid_param` for an `acf_fields` key that is not an ACF field of the taxonomy (`ACF field '<name>' not found for term '<taxonomy>'`), or a malformed repeater, group or flexible content value
- `400 input_invalid` if an `image`/`file` value in `acf_fields` is neither an existing `attachment_id` nor a valid URL
- `404 not_found` if `taxonomy`, the `id` sent or the parent cannot be resolved
- `409 duplicate_local_key` only with an explicit `id` (see above)
- `500 request_failed`
- `500 acf_error`
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active
- `500 wpml_error`

### DELETE `/terms`

Deletes terms by ID or by `local_key`. An ID deletes that term only. A `local_key` deletes every term that holds it, so the whole WPML translation group.

Body:

```json
[10, {"id": 11}, {"local_key": 4001, "taxonomy": "brand"}]
```

Optional query:

```text
?taxonomy=brand
?keyfield=local_key
?ignore=1
```

Accepted body elements (default mode):

| Element | Effect |
| --- | --- |
| integer, or string of digits only (`"10"`) | Deletes the term with that ID. Its WPML translations are kept. |
| `{"id": …}` | Same as a plain ID. `id` follows the same rule. |
| `{"local_key": …}` | Deletes every term holding that `local_key`: the term in every WPML language. |

An object element can also carry `taxonomy` (slug or numeric ACF ID). It overrides `?taxonomy=` for that element.

Notes:

- **IDs are strict.** A positive JSON integer, or a string of digits only. `"12abc"`, `"1.5"`, `" 12"`, `0` and `true` are rejected, never cast, so a malformed value never deletes an unrelated term.
- **A plain string is always an ID.** To delete by `local_key` with plain values, use `?keyfield=local_key`. Every element must then be a valid `local_key` (or a `local_key` object), otherwise `400 input_invalid`. A boolean is not a valid `local_key`: `[true]` is rejected, it does not delete the term with `local_key` `"1"`.
- **Any other `keyfield`** (besides the default `id` and `local_key`) returns `400 invalid_keyfield`.
- **`taxonomy` is optional.**
  - With an ID, the term must be in that taxonomy. Without it, the taxonomy is taken from the term, so one request can delete terms of different taxonomies.
  - With a `local_key`, the key is looked up in that taxonomy only. Without it, the key is looked up in every taxonomy. If it is held by terms of more than one taxonomy, the request returns `409 ambiguous_local_key` and deletes nothing for that element.
  - A `taxonomy` that cannot be resolved returns `404 not_found`.
- **An object never falls back to another identifier.** It returns `400 input_invalid` instead:
  - when `local_key` is present (not `null`) but invalid (`""`, `"0"`, a boolean, an object…): `Term :: Element N :: Parameter 'local_key' must be a positive integer or a non-empty string`;
  - in the default mode, when `id` is present (not `null`) but not a valid ID: `Term :: Element N :: Parameter 'id' must be a term ID (positive integer)`;
  - when `taxonomy` is present but not a string or number;
  - when the object has neither `local_key` nor `id` (in `keyfield=local_key` mode, no `local_key`).
- **Any other element** (`null`, a float, a list…) returns `400 input_invalid` with the message `Term :: Element N :: Invalid delete value; …`.
- **Missing terms.** An ID that does not exist (or is outside the taxonomy) returns `404 not_found` with `Term :: Element N :: Term <id> not found`. A `local_key` held by no term returns `404 not_found` with `Term :: Element N :: Term with local_key '<key>' not found`. With `?ignore`, both are skipped.
- **WPML.** With the WPML option that deletes translations together with the original, deleting one ID can also remove its translations. That is WPML's behaviour, not the plugin's. On a `local_key` delete, members already removed that way are skipped, not reported as errors.

Response `200`:

```json
null
```

Errors:

- `400 invalid_param` if the body is not a JSON array
- `400 input_invalid` if an element is not a valid term ID or `local_key` object
- `400 invalid_keyfield`
- `404 not_found` if `taxonomy` cannot be resolved, or if a term ID or `local_key` does not exist (without `?ignore`)
- `409 ambiguous_local_key` if a `local_key` without a taxonomy is held by terms of several taxonomies
- `500 delete_failed` if WordPress fails to delete an existing term. A WordPress error message is appended to the message (`Term :: Unable to delete :: <WordPress error>`).

## Languages

### GET `/languages`

Returns the WPML languages active on the site and which one is the default.

Use it to **check your language codes before you send them**. No write endpoint takes a list of languages. The plugin works out which translations to create from the codes it finds in the payload's language maps. A code the site does not have is dropped silently, on purpose: a payload may legitimately carry a language that is not active here. Without this endpoint, a misconfigured language code looked exactly like a missing translation. The elements in that language never appeared, and no error was returned.

Recommended client flow:

1. Call this endpoint once, at the start of the import.
2. Remove from every language map the codes that are not in `languages`, nested maps included.
3. Leave out a field whose map is left empty, and log the codes you removed.

The step-by-step rules and what happens without the filter are in the
[integration guide](dev/integration-guide.md#filter-language-maps-before-you-send-them).

`languages` always lists the default language first. The order of `wpml_active_languages` is the site's display order and says nothing about the default. The default matters because it is the language of the source element: every other language is a translation of it.

`wpml_active` tells apart the two reasons `languages` can hold a single entry or none:

- WPML is installed with a single language;
- the site has **no** WPML. There, a language map is not ignored: it is rejected with `500 wpml_required`.

No payload. **Pagination:** no.

```bash
curl https://<host>/wp-json/onpage/v1/languages \
  -H "Authorization: Bearer <token>"
```

Response `200`:

```json
{
  "wpml_active": true,
  "default": "en",
  "languages": ["en", "it", "es"]
}
```

On a site without WPML:

```json
{
  "wpml_active": false,
  "default": null,
  "languages": []
}
```

Errors: only the [authentication](#authentication) errors.

## Maintenance

### DELETE `/indexes`

Removes **all** On Page® `local_key` associations (the "indexes") from posts and terms. Call it when the source system **regenerates its local_keys**: once the keys are cleared at the destination, the next import can reassign them from scratch without creating duplicates.

No payload.

```bash
curl -X DELETE https://<host>/wp-json/onpage/v1/indexes \
  -H "Authorization: Bearer <token>"
```

Response `200`:

```json
{ "posts_removed": 1240, "terms_removed": 312 }
```

It deletes the canonical `onpage_local_key` meta and the legacy copies `local_key` / `_local_key`, from both `wp_postmeta` and `wp_termmeta`. It is idempotent.

It does **not** clear the keys of WooCommerce **global attributes**. Those live in the `onpage_wc_attribute_local_key_{attribute_id}` options, not in meta, and survive the call. The terms of an attribute (`pa_*`) are term meta, so they are cleared like any other term.

Important operational notes:

- **Terms.** After clearing, terms become "unowned". The re-import **re-adopts** them by structural identity (name/slug + parent) and writes the new `local_key`, without creating duplicates.
- **Top-down order.** The re-import must process **parents before children**. Right after clearing, a category's `parent` is resolved by `local_key`; if the parent has not been re-imported yet, the request returns `404 not_found`.
- **Pre-existing duplicates.** Duplicate terms created by failed imports remain (orphaned, without a key). For deterministic adoption, send the real `slug` in the payload or clean up the empty duplicates.
- **Products.** Products have **no** structural adoption. After clearing, a product whose title already exists but has no `local_key` is rejected with `409 duplicate_title`. Such products must be **re-imported** (by existing `id`, or by recreating the link). Keep this in mind before clearing the `postmeta` too.
- **Global attributes.** Their old keys are still stored. When you re-import them with regenerated keys, send the existing `slug` (or the attribute `id`): `POST /woocommerce/attributes` then finds the attribute by slug and overwrites its key. Without a slug the plugin tries to create a new attribute, and WooCommerce rejects it if the generated slug is already taken. A new key that equals the old key of a *different* attribute fails with `409 duplicate_local_key`.

Errors:

- `500 delete_failed` if deleting the meta fails.

### POST `/migration`

Runs the plugin's data migrations: one-off adjustments to data already on the site, required by a change of internal format. Call it **once per site** after a plugin update. It is idempotent: running it again does no harm, and it is a no-op when there is nothing to migrate.

A site that ran the earlier plugin (main file `onpage.php`, any version) must call it **before the first import**. The earlier plugin stored keys in the legacy `local_key` meta, which the lookups no longer read. Until the migration runs, those posts have no `local_key` for the plugin, and importing them fails with `409 duplicate_title`.

No payload.

```bash
curl -X POST https://<host>/wp-json/onpage/v1/migration \
  -H "Authorization: Bearer <token>"
```

What it does, in order:

1. **Renames the `local_key`** (`local_key` → `onpage_local_key`). A post whose key is stored under the legacy meta `local_key` gets it under `onpage_local_key`, the canonical meta.
   - A post that already has `onpage_local_key` keeps it: its legacy `local_key` row is deleted instead of renamed, so no post ends up with two key rows.
   - The other legacy rows in `wp_postmeta` are renamed.
   - The orphaned ACF reference meta `_local_key` is deleted.
   - The legacy copies in `wp_termmeta` are deleted (terms already stored the canonical key, so those were duplicates).
2. **Backfills the On Page® storage segment** (`_onpage_file_token`). Attachments imported by URL before the segment was indexed only have `_onpage_source_url`. Without the segment, nothing can recognize them when the same file arrives on `POST /media`, so they get duplicated. The segment is extracted from the source URL and written on the attachment. Attachments that already have it, and URLs that are not On Page® storage URLs (see [Remote file imports](#remote-file-imports)), are skipped.

Response `200`:

```json
{
  "renamed": 1236,
  "duplicates_removed": 4,
  "acf_reference_removed": 1240,
  "term_legacy_removed": 312,
  "items": [
    { "post_id": 501, "local_key": "12" },
    { "post_id": 502, "local_key": "SKU-7781" }
  ],
  "media_tokens": {
    "scanned": 3480,
    "written": 3452
  }
}
```

Response notes:

- `renamed` is the number of `wp_postmeta` rows renamed. It counts only the renamed rows.
- `duplicates_removed` is the number of legacy `local_key` rows deleted because their post already had `onpage_local_key`.
- `acf_reference_removed` and `term_legacy_removed` are the number of legacy rows deleted from `wp_postmeta` and `wp_termmeta` respectively.
- `items` lists every post that had a legacy `local_key` row (renamed or removed as a duplicate), with the key found, for verification. It is captured **before** the migration and can be long on a large site. The `local_key` here is the raw meta value, so it is always a string (unlike other endpoints, which return an integer when the key is a canonical integer).
- `media_tokens.scanned` is the number of attachments examined (those with `_onpage_source_url` and no segment). `media_tokens.written` is the number that received a segment. The difference is media imported from non-On Page® URLs: they have no segment and remain de-duplicated by exact URL.
- On a second run every counter is `0`, except `media_tokens.scanned`. Media imported from non-On Page® URLs never get a segment, so they are examined again on every run. `media_tokens.written` is `0`.

Errors:

- `500 migration_failed` if any metadata read or write fails. The migration is not transactional: steps already completed stay applied, and the call can be repeated.

## cURL examples

### List taxonomies

```bash
curl -X GET \
  "https://example.com/wp-json/onpage/v1/taxonomies" \
  -H "Authorization: Bearer <token>"
```

### Upsert a post type

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/post-types" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '[
    {
      "post_type": "product",
      "singular_label": "Product",
      "plural_label": "Products",
      "rewrite_slug": "catalog/products"
    }
  ]'
```

### Upsert multilingual terms

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/terms" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '[
    {
      "taxonomy": "brand",
      "local_key": 4001,
      "name": {
        "it": "Acme Italia",
        "en": "Acme"
      },
      "acf_fields": {
        "headline": {
          "it": "Titolo IT",
          "en": "EN title"
        }
      }
    }
  ]'
```

## Implementation notes

- The API relies on ACF plugin functions such as `acf_update_field_group`, `acf_update_post_type` and `acf_update_taxonomy`.
- Many endpoints assume ACF is present and correctly initialized.
- Payload semantics are driven by the current controller code, not by a formal OpenAPI schema.
- Updates are partial only in some cases. For example, posts update only the fields sent, but the taxonomies sent are fully reassigned.
