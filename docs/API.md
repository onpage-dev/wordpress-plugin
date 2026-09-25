# REST API reference

This document describes the REST API exposed by the plugin. It is based on `routes.php` and on the logic actually implemented in the controllers.

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
- [Maintenance](#maintenance)
- [cURL examples](#curl-examples)
- [Implementation notes](#implementation-notes)

## Endpoint index

Every endpoint at a glance. Paths are relative to the base namespace `/wp-json/onpage/v1`. Click an endpoint to open its full specification.

In the **Notes** column, *Paginated* marks the only two endpoints that paginate. `?ignore` marks the `DELETE` endpoints that accept the [`?ignore` flag](#general-conventions).

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
| Terms | `GET` | [`/terms`](#get-terms) | List or search terms of a taxonomy | |
| Terms | `POST` | [`/terms`](#post-terms) | Create or update terms in batch | |
| Terms | `DELETE` | [`/terms`](#delete-terms) | Delete terms by ID | |
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

| Status | When |
| --- | --- |
| `500` | No API token is configured in the WordPress options (`onpage_auth_token`). |
| `401` | The `Authorization` header is missing or does not contain a valid Bearer token. |
| `403` | The token sent does not match the configured one. |

The token is generated from the **On Page®** page in the WordPress admin. Only administrators can access that page (capability `manage_options`). Generating and regenerating the token is also protected by a CSRF nonce. No REST endpoint can read or write the token: it is managed exclusively from the admin UI.

## General conventions

- **Batch writes.** Almost every write endpoint works in batch: the expected JSON body is an array.
- **Exception: `POST /media`.** It uses `multipart/form-data` and accepts one or more files in the same request.
- **Fail fast.** If one element of a batch fails, the request stops immediately and returns a `WP_Error`.
- **`?ignore=1` on DELETE.** The `DELETE` endpoints of `field-groups`, `post-types`, `posts`, `taxonomies`, the WooCommerce resources and `media` accept the query string `?ignore=1` to skip elements that are not found. The flag is enabled by the mere presence of the `ignore` parameter, whatever its value. `DELETE /terms` and `DELETE /indexes` do not support it.
- **Taxonomy identifier.** The term endpoints identify the taxonomy with the `taxonomy` parameter (query string on `GET`/`DELETE /terms`, body field on `POST /terms`). It accepts either the **taxonomy slug** (e.g. `product_cat`, `brand`, `pa_color`) or the **numeric ACF ID** of the taxonomy. The slug takes precedence and is recommended: it is stable across environments (the ACF ID depends on creation order) and it also covers non-ACF taxonomies (WooCommerce `product_cat`/`product_tag`/`pa_*`). The numeric ID is still supported for backward compatibility.
- **Multilingual values.** When WPML is active, some fields can be sent as a language map `{ "<lang>": <value> }`.
- **Exact post types.** `/posts` and `/post-types` use the exact post type sent in the payload. No prefix is added.

### `local_key`

`local_key` is the external On Page® identifier. It can be a **positive integer or a non-empty string**.

- **Input** (payload and query string):
  - The value is trimmed.
  - Only the empty string, `"0"` and non-scalar types are rejected, with `400 invalid_param`.
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

WordPress does not allow two sibling terms with the same name unless the caller provides a free explicit slug. In that case the upsert therefore creates a **separate term** with the requested name and a technical slug (`<slug-base>-<language>`, with a numeric suffix if already taken). The upsert fails with `500 request_failed` and message `term_exists` only if no technical slug is available.

The destination then holds two terms with the same name and different `local_key`s. This is the typical symptom of `local_key`s that are out of sync between source and destination (e.g. the source regenerated its keys). To fix it, call [`DELETE /indexes`](#delete-indexes) and then run a **top-down** re-import. The re-import re-adopts the existing terms and rewrites their `local_key` instead of duplicating them.

### Media values: URL or `attachment_id`

Every field that accepts a remote file URL also accepts the `attachment_id` of a file already in the Media Library, for example one uploaded earlier with `POST /media`. It must be a JSON integer, not a string.

With an `attachment_id` the plugin downloads nothing. It only checks that the ID matches an existing attachment and assigns it directly. If the field has a parent post, product or variation, the attachment is also attached to that parent, as with a URL import.

This applies to:

- `image` and `gallery` of `POST /woocommerce/products` and `POST /woocommerce/variant-products`;
- `thumbnail` of `POST /woocommerce/brands` and `POST /woocommerce/categories`;
- `downloads[].file`/`downloads[].url` of `POST /woocommerce/products`;
- `files` of `POST /posts` and `POST /media/link`;
- every ACF field of type `image`/`file` inside `acf_fields`, on any endpoint (top level, or inside a `repeater` or `group`).

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

#### Endpoints

| Endpoint | Paginated | `per_page`/`page` | `X-WP-Total`/`X-WP-TotalPages` | Notes |
|---|---|---|---|---|
| `GET /posts` | ✅ | ✅ | ✅ | Not paginated when using `?id=` or `?title=`: these always return every match, with no pagination headers. |
| `GET /media` | ✅ | ✅ | ✅ | — |
| `GET /terms` | ❌ | — | — | Always returns every term of the requested taxonomy. |
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

**Simple fields** (`text`, `textarea`, `select`, `number`, `url`, `email`, …)

- The value is passed to ACF unchanged.

**`image` / `file`**

- The value can be a valid URL string (imported into, or reused from, the Media Library).
- It can also be the `attachment_id` (JSON integer) of a file already in the Media Library (e.g. uploaded with `POST /media`).
- In both cases the field stores the resulting `attachment_id`.
- In the `post` context the attachment is also attached to the parent post. For a URL this happens through the download. For an `attachment_id` there is no download: the attachment is only verified and re-attached.
- To clear the field, pass `null` or an empty string.
- An `attachment_id` that does not match an existing attachment is ignored: the original value is passed to ACF unchanged.

**`tab`**

- ACF tabs are UI separators with no value. Keys sent for a `tab` field are silently ignored.
- Tabs do not appear on read (`get_fields()` does not expose them).

**`repeater`**

- The value must be a list of objects, one per row. Each object contains the row's sub-fields. Example: `"certifications": [{"nome": "Marcatura CE", "anno": 2024}, {"nome": "VOC A+"}]`.
- `image`/`file` sub-fields with a URL or `attachment_id` are resolved to an `attachment_id` with the same rules as top-level fields.
- Nested `repeater` sub-fields are supported recursively.
- `tab` sub-fields are ignored.
- A WPML language map must be applied to the whole repeater (`"certifications": {"it": [...rows...], "en": [...rows...]}`), not to individual sub-fields. Language maps inside a row are not supported.
- If a repeater payload is not a list of objects, the endpoint returns `400 invalid_param` with the message `Repeater '<name>' must be a list of rows` or `Repeater '<name>' row N must be an object`.

**`group`**

- The value is an object with the group's sub-fields, for example `"scheda": {"titolo": "…", "allegato": "https://…pdf"}`.
- `image`/`file` sub-fields with a URL or `attachment_id` are converted/resolved to an `attachment_id`.
- A WPML language map must be applied to the whole group.

**`group` whose sub-fields all have two- or three-letter names**

- The field is **cleared**, and the request still returns `200`.
- Why: `MultiLang::isLanguageMapShape()` recognizes a language map from the shape of its keys alone, and any 2-3 letter key counts as a language code. A group such as `{"lat": 45.1, "lng": 9.2}`, `{"sku": …, "ean": …}` or `{"url": …, "alt": …}` is therefore mistaken for a language map. It contains neither the requested language nor the fallback, so it resolves to `null`.
- This behaviour is pinned in `src/Tests/MultiLangResolveFields.php` (`casiDaComportamentoAttuale`). Tightening the detection is a trade-off: a map carrying only languages that are not active on the site would then be written raw into the field instead of clearing it.
- Workaround: give such a group at least one sub-field whose name is longer than three letters, or keep the values in separate fields.
- On **terms** the same payload is written intact, because that code path (`splitAcfFieldsByLanguage`) intersects the keys with the active WPML languages.

### Language maps inside `acf_fields`

Any value in `acf_fields` can be sent as a WPML language map `{ "<lang>": <value> }`. Each language is resolved independently. The value for a language can be a **string**, a **number**, **`null`/`""`**, a **list of objects** (repeater) or an **object** (group). Different languages in the same map can have different types.

```json
"acf_fields": {
  "product_subtitle": { "it": "Sottotitolo", "en": null },
  "certifications": {
    "it": [ { "nome": "Marcatura CE", "anno": 2024 } ],
    "en": [ { "nome": "CE marking", "anno": 2024 } ]
  }
}
```

Consistency with the ACF type is still checked after the language is resolved:

- For a `repeater` field, the resolved value of every language must still be a list of objects. A single object or a string for that language returns `400 invalid_param`.
- For `image`/`file`, the URL → `attachment_id` conversion applies.
- `tab` fields are still ignored.

The language map always goes at field level (or on the whole repeater/group), never on individual sub-fields.

On read (GET), repeaters are returned natively by `\get_fields()` as lists of objects. Tabs do not appear.

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
| `title` | yes | Group title. Missing or empty → `400 missing_title`. |
| `key` | no | ACF group key (convention `group_...`). If missing, it is generated from `title` (`group_` + title slug). |
| `locations` | no | ACF location rules. |
| `description` | no | Group description. |
| `fields` | no | List of fields (see below). |

Upsert rules:

- If a field group with the same `key` **or** the same `title` already exists, it is updated instead of creating a new one.
- When `key` is not sent and a group with that `title` exists, its existing key is reused (it is not changed).
- On update, the group's fields are replaced by the payload. Fields with the same technical key keep their internal ACF field key. Fields no longer present are removed from the field group.

Per-field rules:

- `key` is the technical ACF key, saved as the field name. It is the key to use in the `acf_fields` objects of `POST /posts`, `POST /woocommerce/products` and the term endpoints.
- `name` and `label` are descriptive. If `label` is missing, `name` is used.
- If `key` is missing, `name` is used as the technical key (backward compatibility).
- Every field is sanitized at least for `key`, `label`, `name` and `type`.

If WPML is active, the group is flagged with an ACFML translation mode.

Response `200`:

```json
[123, 124]
```

Main errors:

- `400 missing_title`
- `400 invalid_param`
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

Response `200`:

```json
null
```

## Post Types

### GET `/post-types`

Returns all registered ACF post types.

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
    "rewrite_slug": "catalogo/prodotti"
  }
]
```

| Field | Required | Default | Notes |
| --- | --- | --- | --- |
| `post_type` | yes | | |
| `singular_label` | yes | | |
| `plural_label` | yes | | |
| `hierarchical` | no | `false` | |
| `icon` | no | `dashicons-admin-post` | |
| `supports` | no | `["title", "editor", "thumbnail", "revisions"]` | |
| `taxonomies` | no | `[]` | |
| `rewrite_slug` | no | value of `post_type` | WordPress permalink slug. If `null`, empty or omitted, no custom slug is applied. |

Behaviour:

- **Upsert.** If a post type with the same key (`sanitize_key(post_type)`) exists, its values are updated; otherwise it is created. A duplicate never returns an error.
- **Payload as source of truth.** Every call fully overwrites the ACF fields. Optional properties missing from the payload go back to their default. For example, a previously set `rewrite_slug` is removed if the key is no longer sent.
- The ACF key of the post type is `sanitize_key(post_type)`.
- The WordPress slug is `rewrite_slug` if provided, otherwise the exact value of `post_type`. No `post_` prefix is added.
- `flush_rewrite_rules()` runs once per batch.

Response `200`:

```json
[45]
```

Main errors:

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

- String values are sanitized with `sanitize_key`.
- `flush_rewrite_rules()` runs after each deletion.

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
| `id` | Returns the single matching post (as a one-element list). |
| `type` | Exact post type. Default `any`. In the normal listing an unknown `type` is not an error (empty list). Combined with `title`, an unknown `type` returns `404 not_found`. |
| `local_key` | Filters on the `onpage_local_key` post meta. |
| `status` | Default `any`, which **excludes** trashed posts and auto-drafts. `trashed` (alias of the WP status `trash`) returns **only** trashed items. Any other value (`publish`, `draft`, `pending`, `private`, …) is passed to WordPress unchanged. |
| `updated_after` | Filters on `post_modified_gmt`. An invalid date/time returns `400 invalid_param`. |
| `per_page` | Default `100`, maximum `100`. |
| `page` | Page number. |
| `title` | Exact-title search (see below). |

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

- With `keyfield=id`, `{id}` is the numeric post ID.
- With `keyfield=local_key`, `{id}` is the `local_key` value (an invalid value returns `400 invalid_param`).
- With `keyfield=local_key`, if several posts share the key (WPML translations), the post in the group's **default language** is returned. The other languages are in the `translations` map.

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
  "type": "post_product",
  "status": "publish",
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

Errors:

- `404 no_post`

### POST `/posts`

Creates or updates posts in batch.

How the target post is resolved:

1. If the element has an `id` that exists in WordPress, that post is updated (`id` takes precedence, as in `POST /woocommerce/products`).
2. Otherwise the upsert is driven by `local_key`: if the `local_key` already exists, that post is updated.
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
      "image": "https://storage.op.com?file=12345"
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
| `type` | on insert | On update, defaults to the current post type. Used as the exact post type; no `post_` prefix is added. |
| `title` | on insert | |
| `content` | no | |
| `status` | no | Default `draft` on insert. |
| `local_key` | yes | Always required, also when updating by `id`. |
| `files` | no | Map `acf_field_name => URL or attachment_id`. |
| `acf_fields` | no | See [Handling `acf_fields`](#handling-acf_fields). |
| `terms` | no | Term assignments per taxonomy. |
| `term` | no | Legacy alias of `terms`. |

Supported `terms` formats:

- `<taxonomy_slug> => [term_slug, ...]`
- `<taxonomy_slug> => [lang => term_slug|[term_slug, ...]]`
- `<taxonomy_slug> => [[lang => term_slug, ...], ...]`

#### Insert behaviour

- The title must not already exist in the same post type (see `409 duplicate_title` below for the exact rule).
- The `local_key` must not already exist in the `onpage_local_key` post meta (`409 duplicate_local_key`).
- **`files`:**
  - Each value must be a valid URL reachable by WordPress, or the `attachment_id` (JSON integer) of a file already in the Media Library (e.g. uploaded with `POST /media`).
  - Each remote file is downloaded, imported into the Media Library and attached to the new post.
  - An `attachment_id` is assigned directly, without download, and re-attached to the post as parent.
  - The field named in `files` stores the WordPress `attachment_id` of the imported or given media.
- **`acf_fields` of type `image`:**
  - A valid URL is imported as remote media, and the field stores the resulting `attachment_id`.
  - `null` or an empty string leaves the field without an image.
- If an ACF field named `local_key` exists, the controller fills it automatically.
- **`terms`:**
  - Terms are assigned by reference. Each reference (e.g. `123`, `"SKU-ABC"`) is first looked up as a `local_key`, otherwise it is treated as a slug. Unlike products, slugs are supported here (posts accept an integer/string local_key **or** a slug).
  - For multilingual payloads you can pass a per-language map, for example `{"manufacturer":{"en":"ford-en","it":"ford-it"}}`.
  - A list of language maps is also supported, for example `{"manufacturer":[{"en":"ford-en","it":"ford-it"}]}`.
  - If a term is not found by `local_key` or slug, the request fails with `404`.

#### Update behaviour

- `local_key` is required (also when updating by `id`). It is written on the resolved post and propagated to **all its WPML translations**.
- Resolution order:
  - if the payload has an `id` that **exists** in WordPress, that post is updated (`404 not_found` if the `id` does not exist);
  - otherwise the post is resolved through `local_key`, and the WordPress ID is looked up internally.
- Any of `type`, `title`, `content`, `description`, `acf_fields`, `files`, `terms` or `status` missing from the payload is left unchanged.
- No uniqueness check is performed on `local_key`.
- If `title` changes, it is checked for uniqueness against other posts of the same type.
- `acf_fields` updates only the fields sent.
- `files` updates only the fields sent.
- ACF `image` fields received as URLs are imported or reused as attachments and saved as `attachment_id`.
- An ACF `image` field set to `null` or an empty string is cleared.
- If a URL in `files` was already imported by the plugin, the same attachment is reused and re-attached to the current post. If the remote file is not yet in the Media Library, it is downloaded and a new attachment is created.
- An `attachment_id` in `files` is verified and re-attached to the current post, without download.
- `terms` overwrites the assignments for the taxonomies sent.
- In the multilingual `terms` format, the terms of the current post/translation language are used.

#### Multilingual support with WPML

`title`, `content` and every value in `acf_fields` can be sent as a per-language map:

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
        "it": "https://storage.op.com/it/chair.jpg",
        "en": "https://storage.op.com/en/chair.jpg"
      }
    }
  }
]
```

Rules:

- If `title`, `content`, `description`, `acf_fields`, `files` or `terms` contain multilingual values and WPML is not installed or active, the request returns `500 wpml_required`.
- On insert, the plugin creates the base post in the WPML default language, then the translations.
- On update, the plugin updates the current post and its linked translations.
- If `title` is a string and other fields are multilingual, the same title is used unchanged for every translation. For different titles per language, use a WPML map.
- When `files` is multilingual, each translation receives its own `attachment_id`s in the target fields.
- If the translated title is missing for a language, the shared title or the fallback language is used, without automatic suffixes.
- For multilingual `terms`, terms are resolved in the target language through WPML.

Response `200`:

```json
[321, 322]
```

Main errors:

- `400 input_invalid`
- `400 input_invalid` if a field in `files` contains neither an existing `attachment_id` nor a valid URL
- `404 not_found`
- `404 input_invalid` if a referenced term does not exist
- `409 duplicate_local_key` on insert, if the `local_key` already exists for the post type
- `409 duplicate_title` if the title already exists on an object **without a `local_key`**, outside this element's translation group. An object carrying a different `local_key` is another On Page® element and does not conflict, so two elements with the same title are both imported.
- `500 request_failed`
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
| integer | Deletes the post with that ID. |
| string | Interpreted as an exact post type: **all posts of that type** are deleted. |
| `{"local_key": …, "type": …}` | Deletes by `local_key`; `type` is optional (falls back to the `type` query parameter). |
| `{"id": …}` | Deletes the post with that ID. |
| `{"type": …}` | Deletes all posts of that type. |

Notes:

- To delete by `local_key` (integer or non-empty string) with plain values, use `?keyfield=local_key`. `type` is optional but recommended. In this mode every element must be a valid `local_key` (or a `local_key` object), otherwise `400 input_invalid`.
- Alternatively, use objects with `local_key` and `type` without changing `keyfield`.
- Any other `keyfield` value (besides `local_key` and the default `id_or_post_type`) returns `400 invalid_keyfield`.
- If a `local_key` not filtered by `type` matches several post types, the request returns `409 ambiguous_local_key`.

## WooCommerce

For WooCommerce-specific background see [dev/woocommerce.md](dev/woocommerce.md).

### Shared behaviour of term list endpoints

The list endpoints for brands, categories, tags and attribute terms share the rules below. Each endpoint section states which of them apply.

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

- `name`: exact-name search, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).
- `parent_id` / `parent_lk`: direct-children filters, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).

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

- If `product_brand` does not exist, it is created as a **hierarchical** ACF taxonomy attached to `product`.
- `name`, `slug`, `description`, `local_key` and `acf_fields` behave as in [`POST /terms`](#post-terms).
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

- `400 invalid_param`
- `404 not_found`
- `409 duplicate_local_key`
- `500 woocommerce_required`
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active
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

- `name` performs an **exact-name** search on the attribute label (e.g. `Color`, case-sensitive) and returns every attribute with that name.
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
| `id` | no | | If present, updates **that** attribute (takes precedence, as in `POST /woocommerce/products`). `local_key` is then not required. |
| `local_key` | only if `id` is absent | | |
| `name` | on create | | |
| `slug` | no | | Can be sent as `color` or `pa_color`. |
| `type` | no | WooCommerce default `select` | |
| `order_by` | no | WooCommerce default `menu_order` | |
| `has_archives` | no | `false` | |

Resolution, in order of precedence:

1. If `id` is present, that attribute is updated (`404` if the ID does not exist). If `local_key` is also sent, it is (re)associated with that attribute.
2. Otherwise, if the `local_key` already exists, the attribute associated with it is updated.
3. Otherwise, if `slug` matches an existing attribute, that attribute is updated.
4. Otherwise a new attribute is created.

Other notes:

- The response contains the IDs of the created or updated attributes.
- WooCommerce limits the unprefixed slug to 28 characters and rejects reserved or already used names.
- Attribute values are managed as terms of the generated taxonomy, for example `pa_color` (see [attribute terms](#get-woocommerceattributesattributeterms)).

Response `200`:

```json
[31]
```

Main errors:

- `400 invalid_param`
- `400 invalid_product_attribute_slug_too_long`
- `400 invalid_product_attribute_slug_reserved_name`
- `400 invalid_product_attribute_slug_already_exists`
- `409 duplicate_local_key`
- `404 not_found`
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
```

- `name`: exact-name search, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).

Response `200`:

```json
[
  {
    "id": 101,
    "name": "Red",
    "slug": "red",
    "description": "",
    "taxonomy": "pa_color",
    "local_key": 5101,
    "acf_fields": false
  }
]
```

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
- If you send an `id` that **exists**, that term is updated, and the given `local_key` is written on it and on all its translations. An `id` that does **not** exist (stale) is ignored, and the upsert is driven by `local_key`.
- `name` is required on create. It can be a string or a WPML language map.
- If `name` is a string and other fields are language maps, the same name is used unchanged for every translation. If a per-language `slug` is missing, the translations get a distinct technical slug.
- `slug`, `description` and `acf_fields` support WPML language maps, as for other terms.
- If the attribute taxonomy has an ACF field group with a `local_key` field, the value is synced there too.
- The response contains the IDs of the created or updated terms in the base language.

Response `200`:

```json
[101]
```

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
  - If you send an `id` that **exists**, that category is updated, and the given `local_key` is written on it and on all its translations. An `id` that does **not** exist (stale) is ignored, and the upsert is driven by `local_key`.
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
```

- `name`: exact-name search, see [Shared behaviour](#shared-behaviour-of-term-list-endpoints).

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
- Each body element must be a tag object. Plain strings or language-value maps are not accepted as top-level elements.
- `name` and `slug` can be language-value maps inside the tag object, for example `{ "en": "petrol", "it": "benzina" }`.
- If `name` is a string and other fields are language maps, the same tag name is used unchanged for every translation. If a per-language `slug` is missing, the translations get a distinct technical slug.
- **Resolution:**
  - If you send an `id` that **exists**, that tag is updated, and the given `local_key` is written on it and on all its translations. An `id` that does **not** exist (stale) is ignored, and the upsert is driven by `local_key`.
  - If the `local_key` already exists, the endpoint updates in place the `product_tag` tags and WPML translations with that `local_key`, preserving product-tag associations.
  - If the `local_key` does not exist, it creates new tags for the languages in the payload.
- With WPML, language maps create or update the tag's translations.
- If the payload contains language maps but WPML is not installed or active, the request returns `500 wpml_required`.
- Tags are assigned to products through the `tags` field of `POST /woocommerce/products`, using the tag's `local_key` (integer or string), for example `"tags": [7001]`.

Response `200`:

```json
[22]
```

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

Returns WooCommerce products. Without a query, it returns every product.

**Pagination:** none. The response always contains **all** products matching the filters; there is no `per_page`/`page`. On very large catalogs the response can be heavy.

Optional query:

```text
?id=501
?local_key=1001
?name=Red Chair
?name[it]=Sedia Rossa&name[en]=Red Chair
```

- `id` and `local_key` return the single matching product.
- All WPML translations share the same `local_key`, so `?local_key=` returns the product in the group's **default language** (the same representative used by `?name=`). The other IDs are in the `translations` map.
- `name` performs an **exact-title** search. It accepts two forms:
  - **string** (`?name=Red Chair`): searches the title in the current language of the request;
  - **language map** (`?name[it]=Sedia Rossa&name[en]=Red Chair`): searches each title in the context of its WPML language and returns the **union** of the results, de-duplicated by translation group.
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
        { "nome": "Marcatura CE", "anno": 2024 },
        { "nome": "VOC A+", "anno": 2023 }
      ]
    },
    "terms": []
  }
]
```

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
    "image": "https://storage.example.com/red-chair.jpg",
    "gallery": [
      "https://storage.example.com/red-chair-side.jpg",
      "https://storage.example.com/red-chair-back.jpg"
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
        { "nome": "Marcatura CE", "anno": 2024, "allegato": "https://cdn.example.com/ce.pdf" },
        { "nome": "VOC A+", "anno": 2023 }
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
      "scheda": {
        "it": { "titolo": "Scheda IT", "allegato": "https://cdn.example.com/scheda-it.pdf" },
        "en": { "titolo": "Datasheet EN", "allegato": "https://cdn.example.com/scheda-en.pdf" }
      }
    },
    "downloads": [
      {
        "id": "scheda_tecnica",
        "name": "Scheda tecnica",
        "url": "https://cdn.example.com/scheda-tecnica.pdf"
      },
      {
        "id": "scheda_sicurezza",
        "name": "Scheda di sicurezza",
        "url": "https://cdn.example.com/scheda-sicurezza.pdf"
      }
    ],
    "status": "publish",
    "id": 501
  }
]
```

#### Identity and resolution

- `local_key` and `name` are required.
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
- `status` is optional; default `publish`.

#### Slug

- `slug` is optional and customizes the product permalink (`post_name`). The value is sanitized with `sanitize_title`.
- If omitted, the slug stays as it is: on create WordPress generates it from the title, on update it is not touched. Changing only `name` does not change the permalink.
- It can be a string or a WPML language map, for example `{ "it": "sedia-rossa", "en": "red-chair" }`. If you send a single string with several translations, WordPress makes the slugs unique by adding a suffix.
- An empty or `null` `slug` is ignored (it does not reset the existing slug).
- `update_slug` is optional, boolean, default `false`. It controls when the sent `slug` is applied:
  - `false`: the slug is set **on create only**; later updates do not touch it (existing permalinks are preserved);
  - `true`: the slug is rewritten **on update too**.
  - It only has an effect when `slug` is in the payload.

#### `props` (native WooCommerce fields)

- Native WooCommerce fields go inside `props`: `sku`, `regular_price`, `sale_price`, `price`, `manage_stock`, `stock_quantity`, `stock_status`, `backorders`, `sold_individually`, `weight`, `length`, `width`, `height`, `virtual`, `downloadable`, `featured`, `catalog_visibility`, `tax_status`, `tax_class`, `purchase_note`, `menu_order`, `reviews_allowed`, `product_type`.
- `props.product_type` can be `simple` or `variable`. If omitted on create, it defaults to `simple`.

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
  - if omitted, the gallery is unchanged; `gallery: []` or `gallery: null` empties it;
  - it can be a WPML language map of lists, for example `{ "en": ["https://cdn.example.com/en/1.jpg"], "it": ["https://cdn.example.com/it/1.jpg"] }`. Each translation receives its own gallery. The resolved value for each language must be a list of URLs (an object or a single string returns `400 invalid_param`).

#### `attributes`

- Replaces the product's whole set of custom attributes with the ones sent. A value can be a string/number, a list of values or a WPML language map.
- When `product_type` is `variable`, the attributes sent are flagged as usable by variations (`variation=true`).
- If `attributes` is omitted, existing attributes are not changed. If it is `null` or `{}`, all custom attributes are removed.
- Inside `attributes`, a key whose value is `null` or an empty list is left out of the new set.

#### `acf_fields`

- Reserved for the product's ACF fields. Keys must be ACF technical names or ACF field keys that exist on the product's field group. Unknown fields return `400 invalid_param`.
- For the full type semantics (`tab`, `repeater`, `image`/`file` URL → `attachment_id`, etc.) see [Handling `acf_fields`](#handling-acf_fields).

#### `downloads` (native WooCommerce downloadable files)

- Manages native WooCommerce downloadable files, separate from ACF. It can be a list of objects or `null`.
- Each item accepts `url` or `file` with a valid remote URL, or the `attachment_id` (JSON integer) of a file already in the Media Library (e.g. uploaded with `POST /media`). The value can be `null` or an empty string to skip that download in the resolved language. `name` and `id` are optional.
- For Sigil product PDFs, use stable `id`s: `scheda_tecnica` for the technical datasheet generated by Publisher, and `scheda_sicurezza` for the safety datasheet uploaded by the product team.
- If `downloads.file` does not already point to a URL inside `wp-content/uploads`, the file is imported/reused in the Media Library and WooCommerce uses the imported local URL (compatible with approved download directories).
- If `downloads.file` is an `attachment_id`, nothing is downloaded: the plugin only checks that the ID matches an existing attachment and uses its local URL directly.
- If the remote URL was already imported, the existing attachment is reused without downloading the file again.
- If a remote PDF changes while keeping the same URL, pass `refresh: true` on the download to replace the attachment in the Media Library, keeping the same attachment ID when possible.
- Downloads managed by this endpoint are attached to the parent product in the Media Library and shown publicly on the WooCommerce product page.
- If `downloads` contains at least one valid file, the product is automatically flagged as downloadable (`downloadable=true`).
- For `variable` products, WooCommerce shows downloads in the admin on individual variations: the endpoint automatically copies the parent's downloads to the existing variations.
- `downloads: []` or `downloads: null` removes all WooCommerce downloadable files from the product.
- `name`, `url` and `file` inside `downloads` can be WPML language maps. Each translation receives its own resolved value; `null` or empty values are ignored.

#### Taxonomies: `brand`, `categories`, `tags`, `terms`

- `brand` assigns a single reference from the `product_brand` taxonomy. The reference is the brand's `local_key` (integer or string); slugs are not supported. `null` removes the brand if the taxonomy exists.
- `categories` replaces the whole set of `product_cat` categories with the references sent. Each reference is the category's `local_key` (integer or string); slugs are not supported.
- `tags` replaces the whole set of `product_tag` tags with the references sent. Each reference is the tag's `local_key` (integer or string); slugs are not supported.
- `categories` and `tags` can be lists of `local_key`s, for example `[6001]` or `[7001, 7002]`.
- `categories` and `tags` can be WPML language maps, for example `{ "en": 6011, "it": 6013 }`. Each product translation uses the `local_key` of its language. Since the `local_key` identifies the whole translation group anyway, a single value is usually enough.
- Inside a language map, each value can be a single `local_key`, a list of `local_key`s, or `null` to assign no terms in that language.
- If `brand`, `categories` or `tags` are omitted, that taxonomy is not changed. `brand: null` removes the brand; `categories: []`, `categories: null`, `tags: []` or `tags: null` remove categories or tags.
- **`terms`** (optional) assigns terms from **any taxonomy** registered on the product (including ACF/custom taxonomies such as `tipologia`), in addition to brand/categories/tags:
  - it is an object `{"<taxonomy_slug>": <references>}`;
  - references follow the same rules as `categories`/`tags`: a list of `local_key`s (positive integers), a single value, a WPML language map, or `null`/`[]` to empty that taxonomy;
  - each taxonomy sent replaces its whole assigned set;
  - references are resolved by `local_key`, in any taxonomy; slugs are not supported;
  - if a taxonomy in `terms` is `product_cat`/`product_tag`/`product_brand`, the dedicated field (`categories`/`tags`/`brand`) wins when present;
  - an unknown taxonomy or a term that is not found returns `404`.

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
  - the body is not a valid JSON array;
  - `local_key`/`name` is missing;
  - `name` is not a valid string or language map;
  - `slug` is not a valid string or language map;
  - `update_slug` is not a boolean;
  - `props`/`attributes`/`acf_fields` are not objects;
  - `acf_fields` contains ACF fields that do not exist for the product;
  - `downloads` is not a valid list;
  - `categories`/`tags` are not valid lists or language maps;
  - `terms` is not a valid `taxonomy => references` object.
- `404 not_found` if a referenced product or term does not exist.
- `409 duplicate_local_key` only with an explicit `id` in the payload, when that `local_key` belongs to a different product. Without `id`, the key identifies the product to update and any leftovers are reconciled.
- `409 duplicate_title` if the title already exists on an object **without a `local_key`**, outside this element's translation group. An object carrying a different `local_key` is another On Page® element and does not conflict, so two elements with the same title are both imported.
- `500 woocommerce_required` if WooCommerce is not active.
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active. Language maps cannot be handled until WPML is installed and activated.
- `500 request_failed` if WooCommerce or WordPress fail to save the product.

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
    "image": "https://storage.example.com/shirt-red-s.jpg",
    "acf_fields": {
      "material": "cotton",
      "swatch": "https://cdn.example.com/swatch-red.jpg"
    }
  }
]
```

#### Identity and parent

- `local_key` is required and is saved as the `onpage_local_key` post meta on the variation.
- Resolution: if the element contains `id`, that variation is updated. If `id` is missing but the `local_key` already exists, that variation is updated. Otherwise a new variation is created.
- You must send `parent_id` (WordPress ID) or `parent` (`local_key`). If both are present, they must refer to the same product (`409 parent_mismatch` otherwise).
- The parent must be a `variable` WooCommerce product. It can be created or converted with `POST /woocommerce/products` using `props.product_type: "variable"`.
- The endpoint does not create or configure the parent's attributes automatically.

#### `attributes`

- Required on create, and must be a non-empty object. On update it can be omitted to leave the variation's attributes unchanged.
- Each attribute sent must exist on the parent and be flagged as a variation attribute (`variation=true`).
- Each variation attribute must resolve to a single scalar option. WPML language maps such as `{ "en": "Red", "it": "Rosso" }` are accepted. Lists such as `["Petrol", "Hybrid"]` are not valid for a single variation.
- If a variation attribute contains a language map but WPML is not installed or active, the request returns `500 wpml_required`.
- For global attributes (`pa_color`) the value can be the term slug, name or ID. The variation stores the WooCommerce term slug.
- For custom attributes, the value must be one of the options configured on the parent.

#### `props`, `status`, `image`

- `props` accepts: `sku`, `regular_price`, `sale_price`, `price`, `manage_stock`, `stock_quantity`, `stock_status`, `backorders`, `weight`, `length`, `width`, `height`, `virtual`, `downloadable`, `tax_class`, `menu_order`, `image_id`.
- With WPML, `props.sku` is applied only to the source variation: WooCommerce requires globally unique SKUs, so translated variations cannot store the same SKU.
- `status` on variations:
  - `publish`/`enabled` → enabled variation;
  - `private`/`disabled` → disabled variation;
  - for compatibility, `draft` and `pending` are saved as `private`, because the WooCommerce admin does not show variations with status `draft`.
- `image`:
  - a remote URL to import and set as the variation image, or the `attachment_id` (JSON integer) of a file already in the Media Library (e.g. uploaded with `POST /media`);
  - `image: null` removes the image;
  - it can be a WPML language map, for example `{ "en": "https://cdn.example.com/en/shirt.jpg", "it": "https://cdn.example.com/it/shirt.jpg" }`. Each translated variation receives its own image.

#### `acf_fields`

- Optional associative object `field_name => value` for ACF fields attached to the `product_variation` post type.
- Keys must be ACF technical names or ACF field keys that exist on the variation's field group. Unknown fields return `400 invalid_param`.
- An `image` or `file` ACF field with a valid URL is imported or reused from the Media Library, and the field stores the `attachment_id`. To clear an `image` or `file` field, pass `null` or an empty string.
- Every value in `acf_fields` accepts a WPML language map. Each translated variation receives the value for its language.
- If the variation's ACF field group defines a `local_key` field, it is filled automatically with the variation's `local_key`.

#### Side effects

- If the variable parent has native WooCommerce downloads, the variation inherits them automatically, so they are visible in the WooCommerce UI.
- After saving the variation, the endpoint syncs the variable parent and clears the parent's WooCommerce transients.

#### Multilingual behaviour (WPML)

- If `name`, `description`, `short_description`, `long_description`, `image`, `attributes` or `acf_fields` contain language maps, the endpoint updates the variation of the resolved parent. It then creates or updates the variations for the payload languages that already have a translation of the parent.
- Languages without a translated parent are ignored until that parent translation exists.
- The response contains the variation ID in the language of the parent resolved from `parent_id` or `parent`. The other translated variations are created or updated in the same batch.

Response `200`:

```json
[701]
```

Main errors:

- `400 invalid_param` when:
  - the body is not a valid JSON array;
  - `local_key` is missing;
  - the parent is missing;
  - the parent is not `variable`;
  - `attributes` or `acf_fields` are not valid objects;
  - an attribute is not configured as a variation attribute on the parent;
  - `acf_fields` contains ACF fields that do not exist for the variation.
- `404 not_found` if the parent, the variation or an attribute term does not exist.
- `409 duplicate_local_key`
- `409 parent_mismatch`
- `500 woocommerce_required` if WooCommerce is not active.
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active.
- `500 request_failed` if WooCommerce or WordPress fail to save the variation.

### DELETE `/woocommerce/variant-products`

Deletes WooCommerce variations by `local_key`. If several variations share the same `local_key` (one per language of the parent's group), **all** of them are deleted.

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
| `post_id` | Filters by parent post (`post_parent`). |
| `mime_type` | Filters by the attachment's exact MIME type. |
| `token` | Filters by On Page® storage segment (`_onpage_file_token`, see `POST /media`). Accepts **several comma-separated tokens**, so files already in the library can be re-adopted in bulk with one request instead of one per file. Empty tokens are ignored; if none is left, the request returns `400 invalid_param`. |
| `source_url` | Filters by exact source URL (`_onpage_source_url`). Single value. |
| `per_page` | Default `100`, maximum `100`. |
| `page` | Default `1`. |

Notes:

- The list stays paginated with `token` too: if you ask for more than `per_page` tokens (max `100`), results span several pages. Each row reports its own `token`, so map tokens to attachments from the response.
- `token` and `source_url` are combined with the other filters (`post_id`, `mime_type`) with AND.
- Results are sorted by creation date, newest first.

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

- `hash` is the SHA-256 checksum of the physical file's content. It is computed **on the fly on every request** (`hash_file('sha256', ...)` on `get_attached_file()`) and is **not persisted** in `post_meta`. It is `null` if the physical file is missing or unreadable.
- `token` is the On Page® storage segment indexed on the attachment. It is `null` for media added in other ways (manually in the Media Library, or imported before the segment was indexed; see `POST /migration`).
- `source_url` is the remote URL the media was imported from. It is `null` for files uploaded directly.

Pagination headers:

- `X-WP-Total`: total number of attachments matching the filters (`post_id`/`mime_type`), regardless of the page.
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
| `attachment_id` | Optional. Replaces the content of an existing attachment, when uploading a single file. |
| `attachment_ids` | Optional. Array aligned with the uploaded files, to replace one or more existing attachments in multi-file requests. |
| `token` | Optional. The On Page® storage segment the file comes from (`<token>[.<format>]`, e.g. `aaa111bbb222.1920x1920-contain.jpg`), when uploading a single file. |
| `tokens` | Optional. Array aligned with the uploaded files, same meaning, for multi-file requests (also accepted as a JSON string). Empty positions (`null` or empty string) mean "no token for that file". |

Behaviour:

- The controller accepts both single files and arrays of files in the same field.
- Files are flattened into one list and processed in order.
- Each file is saved with `wp_handle_upload()`.
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
- `key` is the original position of the file in the payload. For `files[]` it is typically `"0"`, `"1"`, and so on.
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
- `files` is required and must be a non-empty JSON object.
- Each key of `files` is the name of the ACF field to update on the post.
- Each value of `files` must be a valid URL reachable by WordPress, or the `attachment_id` (JSON integer) of a file already in the Media Library (e.g. uploaded with `POST /media`).
- Each remote file is downloaded and imported into the Media Library through the WordPress sideload flow. An `attachment_id` is instead verified and re-attached to the requested `post_id`, without download.
- If the same URL was already imported by the plugin, the same attachment is reused and re-attached to the requested `post_id`.
- After the import, reuse or direct link, the controller saves the resulting `attachment_id` in the matching ACF field.
- For URLs, the controller saves the source URL in the `_onpage_source_url` meta.
- **On Page® storage URLs** (`https://storage.onpage.it/<token>[.<format>]/<name>` or `https://app.onpage.it/api/storage/<token>[.<format>]/<name>`):
  - the controller extracts the segment and also saves it in the `_onpage_file_token` meta, the same index used by `POST /media`;
  - reuse looks up the segment **first**, then the exact URL. A file renamed on On Page® changes URL but not segment, so it is neither downloaded again nor duplicated;
  - URLs from other sources produce no segment and are still reused by exact URL.
- The response returns one item per entry in `files`, including the original field name. An item resolved from an `attachment_id` has no `source_url` in the response.

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
    "attachment_id": 601,
    "filename": "image-1.jpg",
    "title": "image-1",
    "url": "https://example.com/wp-content/uploads/2026/04/image-1.jpg",
    "mime_type": "image/jpeg",
    "post_id": 321,
    "source_url": "https://cdn.example.com/image-1.jpg"
  },
  {
    "field": "image_2",
    "action": "linked",
    "attachment_id": 455,
    "filename": "image-2.jpg",
    "title": "image-2",
    "url": "https://example.com/wp-content/uploads/2026/03/image-2.jpg",
    "mime_type": "image/jpeg",
    "post_id": 321,
    "source_url": "https://cdn.example.com/image-2.jpg"
  }
]
```

Main errors:

- `400 invalid_param` if the body is not a valid JSON object.
- `400 invalid_param` if `post_id` is not a positive integer.
- `400 invalid_param` if `files` is not a non-empty object.
- `400 invalid_param` if a value in `files` is neither an existing `attachment_id` nor a valid URL.
- `404 not_found` if `post_id` does not exist.
- `500 request_failed` if downloading or importing the remote file fails.

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

- The body must be a non-empty JSON array.
- Each element must be a positive numeric ID.
- Only posts of type `attachment` are deleted.
- Deletion uses `wp_delete_attachment($id, true)`, so it is forced. It also removes the physical file and all associated metadata, including the On Page® index (`_onpage_file_token` and `_onpage_source_url`). After deletion the media can no longer be re-adopted by token.
- If an element fails, the request stops immediately.
- With `?ignore=1`, attachments that are not found are skipped without error. For those IDs, any orphaned On Page® index left behind (attachment deleted outside WordPress) is still removed.

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

- `400 invalid_param` if the body is not a valid JSON array or contains invalid IDs.
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

| Field | Required |
| --- | --- |
| `key` | yes |
| `singular_label` | yes |
| `plural_label` | yes |
| `description` | no |
| `hierarchical` | no |

Behaviour:

- **Idempotent upsert.** If a taxonomy with the same `key` exists, it is updated in place (same ACF ID); otherwise it is created. Sending the same payload again converges on the same record, with no duplicate errors.
- The `key` is the stable identifier of the taxonomy (also used as its slug). There is no separate `local_key`.
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
- The response is **always a list** with **one object per distinct translation group** (the default-language term is the representative when available), carrying the `translations` map. Names that are translations of the same term → one object. Names from different groups → several objects. This mirrors `GET /woocommerce/products?name=`.

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

- `404 not_found` if `taxonomy` is sent but cannot be resolved

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
| `id` | no | If present, the term is updated. |
| `name` | yes | |
| `slug` | no | |
| `description` | no | |
| `local_key` | no | Stable external identifier. |
| `parent` | no | Default `0`. |
| `acf_fields` | no | Associative object `field_name => value`. |

`acf_fields` behaviour (see also [Handling `acf_fields`](#handling-acf_fields)):

- Text and scalar fields are saved directly on the term.
- An `image` ACF field receiving a valid URL: the file is imported or reused from the Media Library, and the `attachment_id` is saved.
- Remote SVGs are accepted only after validation/sanitization, and are imported as `image/svg+xml`.
- An `image` ACF field receiving `null` or an empty string is cleared.

Resolving the term to update:

1. `id` first, if present (it must exist, otherwise `404 not_found`).
2. Otherwise, a term with the same `local_key`.

`local_key` behaviour:

- Saved as the `onpage_local_key` term meta.
- Written on the **whole WPML translation group** of the resolved term. When a term is updated (even by `id` only), the `local_key` is also applied to translations not included in the payload.
- If the term has an ACF field named `local_key`, it is filled automatically.
- If the `local_key` already exists on another translation group of the same taxonomy, the request returns `409 duplicate_local_key`.

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

Rules:

- If multilingual values are present and WPML is not installed or active, the request returns `500 wpml_required`.
- The base language used to create the term is the WPML default language, or else the first language in the payload that actually has a name.
- **`null` values per language.** Inside a `name` map (or `slug`/`description`), a language can be `null` to mean "no translation" (e.g. `{"it": "Sigillante Ibrido", "en": null, "es": null}`).
  - `null` languages are ignored: they produce no translation and do not count as a name.
  - The base term is created in the first language that has a name, even when the WPML default language is one of the `null` ones.
  - The request fails with `400 invalid_param` (`Name is required`) **only** if no language in the map has a non-empty name.
- **Languages not active in WPML.** If the payload includes language codes not configured in WPML (e.g. the site only has `it` active but receives `{"it": "...", "en": null, "es": null}`), the map is not recognized as multilingual. The plugin still uses the name of the active language present (here `it`) to create the term, and ignores inactive codes. At least one language must have a name, otherwise `400 invalid_param`.
- Translations are created or updated in the same WPML group as the base term.
- `local_key` is propagated to the translated terms too, both as term meta and as an ACF field if present.

Response `200`:

```json
[10, 11]
```

Main errors:

- `400 invalid_param`
- `404 not_found`
- `409 duplicate_local_key`
- `500 request_failed`
- `500 acf_error`
- `500 wpml_required` if the payload contains multilingual values but WPML is not installed or active
- `500 wpml_error`

### DELETE `/terms`

Deletes terms by ID. The body is a list of term IDs.

Body:

```json
[10, 11]
```

Optional query:

```text
?taxonomy=brand
```

- `taxonomy` (**optional**) restricts deletion to one taxonomy (slug or numeric ACF ID). If it cannot be resolved → `404 not_found`.
- If **omitted**, each term's taxonomy is taken from the term itself, so a single request can delete terms of different taxonomies.
- `?ignore=1` is not supported: a missing term always returns `404`.

Response `200`:

```json
null
```

Errors:

- `404 not_found` if `taxonomy` is sent but cannot be resolved, or if a term does not exist
- `500 delete_failed` if WordPress fails to delete the term

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

No payload.

```bash
curl -X POST https://<host>/wp-json/onpage/v1/migration \
  -H "Authorization: Bearer <token>"
```

What it does, in order:

1. **Renames the `local_key`** (`local_key` → `onpage_local_key`). The On Page® key of posts used to be written implicitly by an ACF field under `local_key`; the canonical meta is now `onpage_local_key`. The migration renames the rows in `wp_postmeta`, deletes the orphaned ACF reference meta `_local_key`, and deletes the legacy copies in `wp_termmeta` (terms already stored the canonical key, so those were duplicates).
2. **Backfills the On Page® storage segment** (`_onpage_file_token`). Attachments imported by URL before the segment was indexed only have `_onpage_source_url`. Without the segment, nothing can recognize them when the same file arrives on `POST /media`, so they get duplicated. The segment is extracted from the source URL and written on the attachment. Attachments that already have it, and URLs that are not On Page® storage URLs, are skipped.

Response `200`:

```json
{
  "renamed": 1240,
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

- `renamed` is the number of `wp_postmeta` rows renamed. `acf_reference_removed` and `term_legacy_removed` are the number of legacy rows deleted from `wp_postmeta` and `wp_termmeta` respectively.
- `items` lists the posts affected by the rename, with the key found, for verification. It is captured **before** the rename and can be long on a large site. The `local_key` here is the raw meta value, so it is always a string (unlike other endpoints, which return an integer when the key is a canonical integer).
- `media_tokens.scanned` is the number of attachments examined (those with `_onpage_source_url` and no segment). `media_tokens.written` is the number that received a segment. The difference is media imported from non-On Page® URLs: they have no segment and remain de-duplicated by exact URL.
- On a second run every counter is `0`.

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
      "rewrite_slug": "catalogo/prodotti"
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
