# Building an integration

This guide is for developers who write an **integration** against this plugin. An integration is an
external program. It reads data from On Page® and pushes structure and content into a WordPress
site through the plugin's REST API.

The plugin never pulls data. It only receives it. This guide covers everything the caller has to
get right.

| You want | Read |
| --- | --- |
| how to write an integration, with working examples | this file |
| the full payload reference, endpoint by endpoint | [API.md](../API.md) |
| how the plugin works inside, service by service | [internals.md](internals.md) |
| the architectural decisions and their trade-offs | [architecture.md](architecture.md) |
| how to install and configure the plugin as a site admin | [user/guide.md](../user/guide.md) |
| the list of every endpoint | [API.md: Endpoint index](../API.md#endpoint-index) |
| how WooCommerce products and downloads are saved | [woocommerce.md](woocommerce.md) |
| pagination details | [API.md: Pagination](../API.md#pagination) |

---

## 1. The shape of an integration

An integration always runs three steps, in this order:

```text
On Page® (PIM)  ──SDK read──▶  your integration  ──authenticated HTTP──▶  WordPress plugin
```

1. **Read** from On Page® with an official SDK (PHP or JS/TS).
2. **Map** PIM fields onto the plugin's payload keys.
3. **Write** to the plugin with plain authenticated `POST`/`DELETE` calls.

Step 3 has no SDK, and it does not need one. The write side is JSON over HTTP with a bearer token.

Do not reuse the internal `Op\WordPress` manager from the exporter project. It depends on Laravel,
Eloquent and a `request_cache` table, and it is not distributable.

### Reading with the On Page® SDKs

**PHP** — [`onpage-dev/onpage-php`](https://github.com/onpage-dev/onpage-php):

```bash
composer require onpage-dev/onpage-php
```

**JS / TS** — [`onpage-js`](https://github.com/onpage-dev/onpage-js):

```bash
npm install onpage-js
```

Both SDKs expose the same model:

- An API token maps to one project, the **Schema**.
- A schema holds **resources** (collections).
- A resource holds **things** (items).
- A thing exposes its fields through methods — `val()`, `values()`, `file()`, `rel()` — not as an
  array.

Preload relations with `with()`, then walk them with `rel()`. One query brings the whole tree home,
and your loops stay free of N+1 calls.

```php
$schema = \OnPage\Schema::fromToken(getenv('ONPAGE_TOKEN'));
$schema->lang = 'en';
$schema->setFallbackLang('en');

$products = $schema->query('products')->with(['categories'])->all();

foreach ($products as $product) {
    $sku       = $product->val('code');               // plain field
    $desc_it   = $product->val('description', 'it');  // translated field
    $image_url = $product->file('image')?->link();    // public URL on storage.onpage.it
    $categories = $product->rel('categories');        // preloaded relation
}
```

```ts
import { Schema } from 'onpage-js'

const schema = await Schema.load({ token: process.env.ONPAGE_TOKEN! })
schema.lang = 'en'
schema.fallback_lang = 'en'

const products = await schema.query('products').with(['categories']).all()

for (const product of products) {
  const sku = product.val('code')
  const descIt = product.val('description', 'it')
  const imageUrl = product.file('image')?.link()
  const categories = product.relSync('categories')
}
```

Files are the key hand-off. `link()` returns a public URL, and the plugin accepts exactly that.
**The integration never downloads binaries.** It passes the URL. The WordPress side handles the
download, the Media Library import and the deduplication.

---

## 2. Prerequisites

| Component | When | Consequence if missing |
| --- | --- | --- |
| PHP 8.2+ | always | the plugin code does not run (`Requires PHP` in `plugin.php`) |
| WordPress 7.1+ | always | declared minimum (`Requires at least`) |
| **ACF** | **always** | **no route is registered at all** — see below |
| ACF PRO | only for its own field types (`repeater`, `flexible_content`, …) | the plugin does **not** check the ACF edition. `POST /field-groups` passes `fields[].type` straight to `acf_update_field()` and still answers `200`. A PRO-only field is stored but does not work |
| WooCommerce | only for `/woocommerce/*` | `500 woocommerce_required` on each request |
| WPML | only for language-map payloads | `500 wpml_required` on each request |
| Pretty permalinks (anything but **Plain**) | only for the `/wp-json/` URL form | with **Plain**, `/wp-json/onpage/v1/…` returns 404. The same routes stay reachable at `/?rest_route=/onpage/v1/…` |

### ACF is a hard dependency

`plugin.php` includes `routes.php` on `plugins_loaded` **only** when `acf_get_field_groups()`
exists. With ACF inactive:

- the plugin registers no routes;
- every call gets WordPress' own `404 rest_no_route` (not a plugin error);
- the dashboard shows a notice naming the missing dependency.

This is deliberate. The plugin calls ACF's API without guards:

- `Acf::loadFieldTypeMap()` calls `acf_get_field_groups()` (`src/Services/Acf.php:91`) on the
  ACF-aware routes.
- The post-type, field-group and taxonomy services call `acf_get_acf_post_types()` /
  `acf_get_field_groups()` directly (`src/Services/PostType.php:73`,
  `src/Services/FieldGroup.php:40`, `src/Services/Taxonomy.php:290`).

If the routes were registered without ACF, they would die with a PHP fatal instead of answering.

> A `404` on *every* endpoint means ACF is inactive or permalinks are on **Plain**. The body tells
> them apart:
>
> - ACF inactive: a JSON `{"code":"rest_no_route"}` (WordPress' own code), plus the dashboard
>   notice.
> - **Plain** permalinks: WordPress' HTML 404 page.

---

## 3. Connecting

Base URL for every call:

```text
https://<site>/wp-json/onpage/v1
```

### Token

- Generate it from the **On Page®** top-level admin menu (requires the `manage_options`
  capability).
- It is created with `bin2hex(random_bytes(32))`: 64 lowercase hex characters.
- It is stored in the non-autoloaded option `onpage_auth_token`.

All 43 routes are authenticated. There is no public route. There is no capability check beyond the
token.

```http
Authorization: Bearer <token>
```

How the header is checked:

- It is read with `get_header('authorization')`.
- It is matched against `/^\s*Bearer\s+(.+)\s*$/i`. The scheme word is case-insensitive and
  surrounding whitespace is tolerated.
- The token is compared byte-exact with `hash_equals()`.
- There is no fallback: no query parameter, cookie or nonce.

| Error | HTTP | Meaning |
| --- | --- | --- |
| `onpage_auth_not_configured` | 500 | no token has been generated on the site yet |
| `onpage_auth_missing_token` | 401 | header absent or not in `Bearer` form |
| `onpage_auth_invalid_token` | 403 | token does not match |

### Smoke test

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  -H "Authorization: Bearer $WP_TOKEN" \
  "https://example.com/wp-json/onpage/v1/post-types"
```

| Result | Meaning |
| --- | --- |
| `200` | the plugin answers and the token is good |
| `401` / `403` | token problem |
| `404` | ACF inactive, permalinks on **Plain**, or rewrite rules not flushed |

---

## 4. Rules that apply to every endpoint

### Request bodies

**Write bodies are JSON arrays.**

- Every content `POST` takes a **list of objects**, never a single object.
- Every `DELETE` takes a **list of scalars**: `local_key`s, numeric ids or slugs, depending on the
  route. `DELETE /posts` also accepts `{"local_key": …}` objects.

Four exceptions:

- `POST /media` is `multipart/form-data`.
- `POST /media/link` takes a single object.
- `POST /migration` takes no body.
- `DELETE /indexes` takes no body.

### Batches are not transactional

Each endpoint loops over the payload and throws on the first failure. As a result:

- the elements processed before the failure are already committed;
- there is no per-element result;
- the error object replaces the whole response body.

Within a single element there is a partial rollback. The *create* path of `POST /posts` and
`POST /woocommerce/products` wraps its work in `try`/`catch`. On failure it hard-deletes the rows
it just created, then re-throws (`src/Services/Post.php:1807-1813`). So a failed element usually
leaves nothing behind. Earlier elements of the same batch stay.

Most per-element errors carry `Element <i>`: the 0-based position in your array. That index is the
only machine-usable locator. Some errors omit it, including the post integrity errors
`duplicate_title` and `duplicate_local_key` (`src/Services/Post.php:502`, `:506`). When the index
is absent, match on the message instead.

Re-running the same batch is safe. That is why partial state is acceptable.

### Error envelope

Deliberate errors are WordPress `WP_Error`s:

```json
{
  "code": "duplicate_title",
  "message": "Post :: Title 'Red Chair' already exists for PostType 'product'",
  "data": { "status": 409 }
}
```

Any other exception is re-thrown unchanged. It surfaces as a PHP fatal or a generic 500, not as
JSON. A bare `500 There has been a critical error` is a bug, not a contract violation. Report it.

### Success responses

| Request | Response |
| --- | --- |
| content `POST` | `200` with the list of created or updated ids, in payload order |
| `POST /media`, `POST /media/link` | one result object per file or field |
| `POST /migration` | an object of counters |
| any `DELETE` | `200` with a `null` body |

### `?ignore` on DELETE

`?ignore` makes a DELETE tolerate objects that are already missing.

- It is a **presence-only** flag, read strictly from the query string. `?ignore`, `?ignore=0` and
  `?ignore=false` all enable it.
- It is honoured by 12 DELETE routes: `field-groups`, `post-types`, `posts`, `taxonomies`, `media`
  and all seven `woocommerce/*`.
- It is **not** honoured by `DELETE /terms` or `DELETE /indexes`.

### Pagination

Only two routes paginate: `GET /posts` and `GET /media`. Every other route returns the complete
set. Read the `X-WP-TotalPages` header to know when to stop. On `GET /posts`, the `?id=` and
`?title=` lookups return no pagination headers at all.

The full rules are in [API.md: Pagination](../API.md#pagination).

### Send large batches

Prefer large batches over single calls. The product and variation controllers defer two things to
the end of the request: term-count recalculation and parent resynchronisation. Sending one call
per item loses both optimisations.

---

## 5. `local_key`, the idempotency key

`local_key` is your identifier. It lets the same import run any number of times without creating
duplicates.

It is carried on every **content** object: posts, products, variations, terms, categories, tags,
brands, attributes and attribute terms.

The structural endpoints have no `local_key`. They ignore the field if you send it, and upsert by
other keys:

| Endpoint | Upserts by |
| --- | --- |
| `POST /post-types` | `post_type` |
| `POST /taxonomies` | `key` |
| `POST /field-groups` | `title` / `key` |

### Rules

- **Accepted forms**: a positive integer or a non-empty string.
- Values are `trim()`ed. `""` and `"0"` are rejected with `400`:
  - code `invalid_param` on the POST routes and the WooCommerce DELETEs;
  - code `input_invalid` on `DELETE /posts?keyfield=local_key`.
- **Integers and numeric strings are equivalent.** `123` and `"123"` resolve to the same object,
  because WordPress stores meta as strings.
- **In responses**:
  - an integer-canonical key comes back as an integer;
  - a non-numeric key comes back as a string;
  - an unset key comes back as `null`.
- **Storage**: post meta / term meta `onpage_local_key`. The same key is used for posts, products,
  variations, terms, categories, tags, brands and attribute terms. Global WooCommerce attributes
  are the exception: their key lives in the option
  `onpage_wc_attribute_local_key_{attribute_id}`.
- **WPML**: every translation in a group shares the same `local_key`. The key identifies the
  object, not the language.

### Which value to use

Use the **On Page® item id** as the `local_key`. Do not use the name or the SKU. The item id is the
only identifier that is stable and not owned by editors.

You can also copy the id into a plain ACF `text` field. This is a cheap debugging aid, because it
makes the key visible in the admin. **Name that field `onpage_id`.** Never name it `local_key` or
`_local_key`: the plugin reserves both names and `DELETE /indexes` wipes them.

### When the key is written

The key is written **immediately after the row is created**. This happens before the slow work on
media, ACF fields and terms. So if the process is killed — PHP fatal, timeout, OOM — the object
still exists and the next run can resolve it.

A *thrown* error is different. The create path deletes the rows it just made before re-throwing.
A failed element therefore leaves nothing keyed.

### `DELETE /indexes` and `POST /migration`

`DELETE /indexes` wipes every key held in post meta and term meta. The next import re-establishes
them from scratch. It does **not** touch the global attribute keys in the
`onpage_wc_attribute_local_key_{attribute_id}` options. Those survive the wipe.

`POST /migration` is not part of a new integration. It only upgrades a site that ran a legacy build
of the plugin. On a site keyed by this version it does nothing.

---

## 6. Call order

The order is not a convention. Each step uses identifiers created by the previous ones.

**Editorial structure:**

```text
1. POST /post-types    → the Custom Post Types
2. POST /taxonomies    → the taxonomies, attached to the post types
3. POST /field-groups  → the ACF fields, located on post types and taxonomies
4. POST /terms         → the terms, parents before children
5. POST /posts         → the content, with ACF values and term assignment
```

**WooCommerce catalogue:**

```text
1. POST /field-groups                              (only if you send acf_fields)
2. POST /woocommerce/attributes                    (pa_* definitions)
3. POST /woocommerce/attributes/{attribute}/terms  (attribute options)
4. POST /woocommerce/brands                        (roots first, then children)
5. POST /woocommerce/categories                    (roots first, then children)
6. POST /woocommerce/tags
7. POST /woocommerce/products                      (with attributes and downloads)
8. POST /woocommerce/variant-products              (parent must already be "variable")
```

What happens when you break the order:

| Violation | Error |
| --- | --- |
| taxonomy and its terms in the **same** HTTP request | `404 not_found` — *Taxonomy '…' not found*. WordPress registers taxonomies on `init`, so a new taxonomy is addressable only from the next request |
| `acf_fields` key with no field group carrying it | `400 invalid_param` — *ACF field '…' not found for post '…'* |
| `POST /posts` for an unknown post type | **no error on insert**. The create path never calls `post_type_exists()`, so the post is written with an unregistered type and is invisible in the admin. The `404 not_found` — *PostType '…' not found* — appears only on the update path, on `GET /posts?type=` and on `DELETE /posts` |
| term reference that resolves to nothing | `404` with code `input_invalid` — *Term reference '…' not found in taxonomy '…'* |
| child term before its parent | `404 not_found` — *Parent local_key '…' not found for taxonomy '…'* |
| variation before its parent, or parent not `variable` | `400 invalid_param` / `404 not_found` on the parent lookup |
| variation option that is not an existing attribute term | `404 not_found` — *Attribute '…' option '…' was not found* |
| language map on a site without WPML | `500 wpml_required`, naming the exact field path |
| `/woocommerce/*` without WooCommerce | `500 woocommerce_required` |

Two constraints cause most problems:

- **Taxonomies and terms need two separate HTTP requests.**
- **Term references in WooCommerce payloads are `local_key` only.** `brand`, `categories`, `tags`
  and `terms` accept neither slugs nor names.
  - In `Post` payloads a reference may be a `local_key` **or** a slug. The plugin tries the key
    first, then the slug.
  - The one WooCommerce exception is the `attributes` map on
    `POST /woocommerce/variant-products`. It points at attribute terms and resolves each value as
    term id, then slug, then **name**.

---

## 7. Route map

There are 43 routes, all authenticated. The [endpoint index](../API.md#endpoint-index) in API.md
lists them all. It also marks the two routes that paginate and the DELETEs that honour `?ignore`.
Each row links to the full specification, query parameters included.

---

## 8. Worked example: structure and content

The whole client is two helpers: one authenticated `POST` helper and one multilingual helper.

```php
<?php

require 'vendor/autoload.php';

use OnPage\Field;
use OnPage\Schema;
use OnPage\Thing;

const WP_BASE  = 'https://example.com';
const LANGS    = ['en', 'it'];

/** Authenticated POST to the plugin. Fail fast on any status outside 2xx. */
function wp(string $path, array $payload): array
{
    $ch = curl_init(WP_BASE . '/wp-json/onpage/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . getenv('WP_TOKEN'),
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Most messages carry "Element <i>": the 0-based position in $payload.
    // POST /post-types and POST /field-groups do not report an index.
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("POST $path -> $status :: $body");
    }

    return json_decode($body, true);
}

/** Turns one closure into a language map. */
function multiLang(callable $value): array
{
    $map = [];
    foreach (LANGS as $lang) {
        $map[$lang] = $value($lang);
    }

    return $map;
}
```

### The structure

These calls are idempotent: sending them again updates in place.

```php
// 1. Custom Post Type.
//    post_type, singular_label and plural_label are required.
wp('/post-types', [[
    'post_type'      => 'catalog',
    'singular_label' => 'Catalogue',
    'plural_label'   => 'Catalogues',
    'icon'           => 'dashicons-archive',
    'supports'       => ['title', 'editor', 'thumbnail'],
    'taxonomies'     => ['catalog_type'],
]]);

// 2. Taxonomy. "key" is both the ACF record key and the taxonomy slug.
//    Labels accept a language map (requires WPML).
wp('/taxonomies', [[
    'key'            => 'catalog_type',
    'singular_label' => ['en' => 'Type', 'it' => 'Tipologia'],
    'plural_label'   => ['en' => 'Types', 'it' => 'Tipologie'],
    'object_type'    => ['catalog'],
    'hierarchical'   => false,
]]);

// 3. ACF field group.
//    "locations" is a FLAT list of rule objects; the plugin wraps it in one group.
//    CAUTION: on update, fields missing from the payload are REMOVED from the group.
//    Always send the complete set.
wp('/field-groups', [[
    'title'     => 'On Page® - Catalogue',
    'key'       => 'group_onpage_catalog',
    'locations' => [
        ['param' => 'post_type', 'operator' => '==', 'value' => 'catalog'],
    ],
    'fields'    => [
        // NOTE: fields[].key becomes the ACF field NAME — it is the key you
        // will use in acf_fields, not an ACF "field_..." key.
        // Do NOT name it local_key: that meta key is owned by the plugin.
        ['key' => 'onpage_id', 'label' => 'On Page® ID', 'type' => 'text'],
        ['key' => 'pdf_link',  'label' => 'PDF',        'type' => 'file'],
        ['key' => 'cover',     'label' => 'Cover',      'type' => 'image'],
    ],
]]);
```

### The content

`cursor()` is the SDK's helper for pagination and memory control. It iterates one item at a time
instead of loading the whole collection. This is SDK behaviour: see the `onpage-php` docs, not this
repo.

```php
$schema = Schema::fromToken(getenv('ONPAGE_TOKEN'));
$schema->lang = LANGS[0];
$schema->setFallbackLang(LANGS[0]);

// 4. Terms, before the content that references them.
$types = [];
$schema->query('catalog_types')->cursor(function (Thing $type) use (&$types) {
    $types[] = [
        'taxonomy'  => 'catalog_type',      // per element: one batch can span taxonomies
        'local_key' => $type->id,
        'name'      => multiLang(fn ($lang) => $type->val('name', $lang)),
    ];
});
wp('/terms', $types);

// 5. The content.
$schema->query('catalogs')
    ->with(['catalog_type'])
    ->cursor(function (Thing $catalog) {
        wp('/posts', [[
            'type'        => 'catalog',
            'local_key'   => $catalog->id,      // the On Page® item id
            'status'      => 'publish',
            'title'       => multiLang(fn ($l) => $catalog->val('title', $l)),
            'content'     => multiLang(fn ($l) => $catalog->val('body', $l)),
            'description' => multiLang(fn ($l) => $catalog->val('abstract', $l)), // post_excerpt
            'acf_fields'  => [
                // every key here must exist in a field group located on this post type
                'onpage_id' => (string) $catalog->id,
                'pdf_link'  => $catalog->file('pdf')?->link(),
                'cover'     => $catalog->file('cover')?->link(),
            ],
            'term'        => [
                // local_key first, slug as fallback — the plugin resolves the WP ids
                'catalog_type' => $catalog->rel('catalog_type')
                    ->map(fn (Thing $t) => $t->id)
                    ->toArray(),
            ],
        ]]);
    });
```

Notes on the post payload:

- `local_key`, `type` and `title` are required on insert.
- `description` is stored in `post_excerpt`, not in a meta field. GET responses do not return it.
- `term` is the canonical key. `terms` is a legacy alias, used only when `term` is absent.

---

## 9. Worked example: WooCommerce catalogue

One eager-loaded query brings the tree home. The products of each category are sent in a single
batch.

```php
$schema->query('brand')
    ->with([
        'categories',
        'categories.products',
        'categories.products.items',
    ])
    ->cursor(function (Thing $brand) {
        // product_brand is hierarchical. For a top-level brand use null or omit
        // "parent" — 0 is rejected. "parent" here is the PARENT BRAND'S local_key.
        wp('/woocommerce/brands', [[
            'local_key' => $brand->id,
            'name'      => $brand->val('name'),
            'parent'    => null,
            'thumbnail' => $brand->file('logo')?->link(),
        ]]);

        foreach ($brand->rel('categories') as $category) {
            wp('/woocommerce/categories', [[
                'local_key'   => $category->id,
                'name'        => multiLang(fn ($l) => $category->val('name', $l)),
                'description' => multiLang(fn ($l) => $category->val('description', $l)),
                // "parent" on product_cat is also a local_key; "thumbnail" is
                // supported on product_cat only (ignored on tags and pa_*).
            ]]);

            $products = [];

            foreach ($category->rel('products') as $product) {
                $products[] = [
                    'local_key'         => $product->id,
                    'name'              => $product->val('name'),
                    'status'            => 'publish',
                    'short_description' => multiLang(fn ($l) => $product->val('short_description', $l)),
                    'long_description'  => multiLang(fn ($l) => longDescription($product, $l)),

                    // Relations: local_key only. Slugs and names are not accepted.
                    // "brand" is a SINGLE reference, "categories"/"tags" are lists.
                    'brand'             => $brand->id,
                    'categories'        => [$category->id],

                    // Native WooCommerce fields. Recognised keys only.
                    'props'             => [
                        'product_type'  => 'simple',   // only "simple" or "variable"
                        'sku'           => $product->val('code'),
                        'regular_price' => $product->val('price'),
                        'stock_status'  => 'instock',
                    ],

                    // Media: URLs, not bytes. Gallery is replaced wholesale.
                    'image'             => $product->file('image')?->link(),
                    'gallery'           => $product->rel('items')
                        ->map(fn (Thing $a) => $a->file('image')?->link())
                        ->filter()
                        ->values()
                        ->toArray(),

                    // Native WooCommerce downloads, replaced wholesale.
                    // Keep the ids stable: WooCommerce indexes the list by download id.
                    'downloads'         => [[
                        'id'      => 'datasheet',
                        'name'    => multiLang(fn ($l) => $product->val('datasheet_name', $l)),
                        'file'    => $product->file('datasheet')?->link(),
                        'refresh' => false,   // true re-downloads and replaces in place
                    ]],

                    // No acf_fields here on purpose: every key would need a field
                    // group located on `post_type == product`, and this example
                    // never creates one. Sending an unknown key is 400 invalid_param.
                ];
            }

            if ($products) {
                wp('/woocommerce/products', $products);   // one POST per category
            }
        }
    });
```

### Variable products

A variable product needs two calls, in order:

1. the parent, declaring every possible option;
2. the variations, with one option each.

```php
wp('/woocommerce/products', [[
    'local_key'  => 1002,
    'name'       => 'T-shirt',
    'props'      => ['product_type' => 'variable'],
    'attributes' => [
        'Colour' => ['Red', 'Blue'],
        'Size'   => ['S', 'M'],
    ],
]]);

wp('/woocommerce/variant-products', [[
    'local_key'  => 2001,
    'parent'     => 1002,                          // parent local_key; or parent_id for a WP id
    'attributes' => ['Colour' => 'Red', 'Size' => 'S'],
    'props'      => ['sku' => 'SHIRT-RED-S', 'regular_price' => '29.90'],
    'status'     => 'publish',
]]);
```

Variation rules:

- `local_key` is required, plus either `parent_id` or `parent`.
- `attributes` is required when creating a variation. If you omit it on an update, the stored
  attributes stay untouched.
- The variation `status` is aliased:
  - `draft`, `pending` and `disabled` become `private`;
  - `enabled` becomes `publish`.

> **`attributes` and `terms` are two different mechanisms.**
>
> - `attributes` creates **custom** product attributes, even when the name you send looks like
>   `pa_colour`. Attributes drive variations.
> - `terms` links a product to the **terms of a global attribute**, e.g.
>   `"terms": {"pa_colour": [5101]}`. Terms drive taxonomy archives and filters.

### Global attributes

```php
wp('/woocommerce/attributes', [[
    'local_key'    => 900,          // required unless you pass an explicit "id"
    'name'         => 'Colour',     // required on create
    'slug'         => 'colour',
    'type'         => 'select',
    'has_archives' => false,
]]);

wp('/woocommerce/attributes/colour/terms', [[   // "colour" or "pa_colour", both resolve
    'local_key' => 5101,
    'name'      => multiLang(fn ($l) => 'Red'),
]]);
```

How `parent` and `thumbnail` behave per taxonomy:

| Taxonomy | `parent` means | `thumbnail` |
| --- | --- | --- |
| `product_cat` | a `local_key` | supported |
| `product_brand` | a `local_key` | brands have their own thumbnail handling |
| `product_tag` | a **raw WordPress term id** | accepted but silently ignored |
| `pa_*` | a **raw WordPress term id** | accepted but silently ignored |

---

## 10. Multilingual payloads

Every translatable value takes one of two shapes:

- a **scalar**, shared across all languages;
- a **language map**.

```json
{
  "local_key": 42,
  "name": { "en": "Red Chair", "it": "Sedia Rossa" },
  "props": { "sku": "CHAIR-RED" }
}
```

### How a language map is detected

Detection is structural. An object is a language map when:

- it is non-empty;
- it is not a list;
- **all** its keys match `/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})*$/i`.

The match is case-insensitive, so `en`, `it`, `pt-br`, `pt_BR` and `zh-hans-cn` all qualify.
**A single key that does not look like a language code disqualifies the whole object.** The object
is then treated as a plain structured value.

This normally keeps `props`, `acf_fields` and `attributes` out of the translation path — but only
because their keys are usually longer than a language code. An object whose keys *all* happen to
fit the pattern **is** read as a language map. For example, `{"url": …, "alt": …}` and
`{"lat": …, "lng": …}` both qualify. On a site without WPML that is a `500 wpml_required`. Give such
sub-objects at least one key longer than three letters.

### How a language map is applied

- The map's keys are intersected with the site's **active** WPML languages.
- A language the site has not activated is **silently dropped**. This is by design: one unknown
  code must not hide the real translations.
- Sending a language map to a site without WPML is a hard `500 wpml_required`. The message names
  the exact field path.

All translations of an object share one `local_key`. Send every language in a single element. The
plugin then:

- creates the WPML group;
- backfills missing languages on update;
- repairs translation slots left orphaned by a killed import.

---

## 11. Media and files

Every file slot, except the multipart upload, accepts **either** an existing attachment id **or** a
remote URL.

Inside `acf_fields` this covers only the ACF types `image` and `file`, including as `repeater`
sub-fields. Values of `gallery` and `flexible_content` fields are stored verbatim and never
imported.

### Attachment ids vs URLs

- An attachment id must be a **JSON integer**. `123` works; `"123"` does not.
- A numeric string falls through to the URL branch. It is then rejected with `400` by
  `POST /media/link`, `POST /posts` (`files`), `image`, `gallery`, `thumbnail` and
  `downloads[].file`.
- Inside `acf_fields` it is **not** rejected. An `image`/`file` value that is neither an attachment
  id nor a valid URL is written into the field verbatim, and the call still answers `200`.
- These places *do* accept numeric strings:
  - `POST /media`'s own `attachment_id` / `attachment_ids` form fields;
  - the id list in the `DELETE /media` body;
  - `post_id`, everywhere.
- A URL is validated with `esc_url_raw()` + `wp_http_validate_url()` before anything is fetched.

### Deduplication

Remote imports are keyed by the `_onpage_source_url` attachment meta. The same URL is never
downloaded twice:

- the existing attachment is reused (`action: "linked"`);
- if its file has vanished from disk, the stale row is dropped and the import self-heals
  (`action: "created"`).

### Refreshing

Two paths replace the bytes behind an existing attachment in place:

- `downloads[].refresh` on `POST /woocommerce/products`. It re-downloads the remote URL over the
  attachment matched by `_onpage_source_url`.
- `POST /media` with `attachment_id` / `attachment_ids`. It uploads new bytes over the attachment
  you name and answers `action: "replaced"`.

Everywhere else, the same URL means the same file.

### SVG

Remote SVGs are sanitised on import. The `image/svg+xml` MIME is enabled only for the duration of
that single sideload. The refresh path does not do this: refreshing an `.svg` fails with
`500 request_failed`.

### Two upload paths

`POST /media` — multipart, arbitrary field names. `attachment_id` / `attachment_ids` switch it to
replace-in-place:

```bash
# multipart, arbitrary field names; attachment_id[s] switch to replace-in-place
curl -X POST "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer $WP_TOKEN" \
  -F "post_id=123" \
  -F "cover=@./cover.jpg"
```

`POST /media/link`:

```json
{
  "post_id": 123,
  "files": {
    "cover":    "https://storage.onpage.it/…/cover.jpg",
    "datasheet": 456
  }
}
```

`POST /media/link` is the only write endpoint whose body is a single object instead of a list. The
parent post must already exist, otherwise: `404 not_found` — *Media :: Post 123 not found*.

### Timing

Media is downloaded **synchronously and serially inside the REST request**. A product with an
image, a gallery and two PDFs can spend tens of seconds on I/O alone. Size your batches against the
site's `max_execution_time` and `memory_limit`.

---

## 12. Let the schema drive the mapping

The highest-return technique is to read the On Page® **structure** at runtime instead of
hard-coding field names. An On Page® field folder then becomes a content block. Editors can extend,
rename and reorder it without a deployment:

```php
/**
 * Long description composed from the fields of an On Page® field folder:
 * output order follows the order set in the tree, and labels arrive translated.
 */
function longDescription(Thing $product, ?string $lang = null): string
{
    $blocks = $product->resource()
        ->folder('web_description')
        ->getFormFields()
        ->map(function (Field $field) use ($product, $lang) {
            $value = $product->val($field->name, $lang);

            return $value ? "<h3>{$field->getLabel($lang)}</h3>\n$value" : null;
        })
        ->filter()
        ->toArray();

    return implode("\n", $blocks);
}
```

Every call in that helper — `Thing::resource()`, `Resource::folder()`, `getFormFields()`,
`Field::getLabel()` — belongs to the `onpage-php` SDK, not to this plugin. Check the SDK docs for
the current signatures.

The same principle applies to editorial labels and single-choice options. Keep them as data in
On Page®, not as strings in code. This moves control to where the content lives.

There is a trade-off: the field folder becomes a **prerequisite** of the integration, and renaming
it breaks the code.

---

## 13. Error reference

The table lists every distinct code with its HTTP status.

About message formats:

- Most messages are prefixed with the entity.
- Batch endpoints that track the element add `Element <i>`.
- `POST /field-groups` and `POST /post-types` report no index.
- `invalid_keyfield` and `acf_error` carry no entity prefix.

Parse the `code`, not the message shape.

| Code | HTTP | Typical cause | Fix |
| --- | --- | --- | --- |
| `onpage_auth_missing_token` | 401 | no `Authorization: Bearer` header reached PHP | check the client. On some Apache CGI/FastCGI setups the header needs a rewrite rule |
| `onpage_auth_invalid_token` | 403 | token regenerated, truncated or mis-copied | copy it again from the **On Page®** page |
| `onpage_auth_not_configured` | 500 | no token generated on the site | open the **On Page®** page and generate one |
| `rest_no_route` | 404 | a WordPress core code, not a plugin one. While ACF is inactive the plugin registers no routes at all | activate ACF. If you get WordPress' HTML 404 page instead of a JSON `code`, permalinks are on **Plain**: switch to **Post name** or call `?rest_route=/onpage/v1/…` |
| `invalid_param` | 400 | a required key is missing, or a value has the wrong type/shape | read the message: it names the parameter and the element index |
| `invalid_keyfield` | 400 | `?keyfield=` outside the allowed set | use `id`, `local_key` or `id_or_post_type`, as documented per route |
| `missing_title` | 400 | `POST /field-groups` without `title` | send a non-empty `title` |
| `upload_failed` | 400 / 500 | rejected or unwritable upload | check MIME, size and filesystem permissions |
| `not_found` | 404 | one of two cases. (a) The object the call targets is missing: any DELETE, and id/`local_key` lookups on `/woocommerce/*`, `/field-groups`, `/post-types`, `/taxonomies`, `/media`. (b) Something the payload references does not exist: post type, taxonomy, term, parent, attribute, attachment | (a) send `?ignore`, or fix the id/`local_key`. (b) respect the call order in [§6](#6-call-order) |
| `input_invalid` | 404 | a term reference in the payload resolves to nothing | sync terms before the content that references them |
| `input_invalid` | 400 | a `DELETE /posts` body element is neither an int id nor a string slug/`local_key`; or a `files` value is neither an existing attachment id nor a valid URL | read the message: it names the element index or the field key |
| `no_post` | 404 | the target post does not exist | check `id` / `local_key` |
| `duplicate_title` | 409 | an **unkeyed** post of that post type has the exact same title and is outside this object's WPML translation group. A post that already carries a *different* `local_key` is a distinct On Page® element and never conflicts, so two elements with the same name import fine | give the existing unkeyed post the `local_key`, so the request updates it instead of inserting; or change the title |
| `duplicate_local_key` | 409 | the `local_key` is already held by a different object. On `POST /woocommerce/products` and `POST /terms` this needs an explicit `id` in the payload. On `/posts`, `/woocommerce/attributes` and `/woocommerce/variant-products` it fires without an `id` too | if you sent `id`, drop it and let `local_key` resolve. Otherwise the key belongs to another object: re-key it, or clear the stale key |
| `ambiguous_local_key` | 409 | the same `local_key` exists on posts of more than one post type, and the request did not scope it | pass the type: `?type=` on `GET /posts/{id}` and `DELETE /posts`, or the `type` key in the `POST /posts` element |
| `parent_mismatch` | 409 | `parent_id` and `parent` point at different parents | send only one of the two |
| `wpml_required` | 500 | language map sent to a site without WPML | install WPML, or send scalars |
| `woocommerce_required` | 500 | `/woocommerce/*` with WooCommerce inactive | activate WooCommerce |
| `wpml_error` / `acf_error` | 500 | WPML or ACF refused a write | check the message. Usually a field-group or language misconfiguration |
| `request_failed` | 500 | a WordPress query or write failed | check the message and the site error log |
| `migration_failed` | 500 | `POST /migration` could not read or rewrite meta | check DB permissions, then re-run. It is idempotent |
| `delete_failed` | 500 | a DELETE whose underlying WordPress/ACF delete call returned false (field group, post type, taxonomy, term, attachment, product, variation, attribute), or `DELETE /indexes` failed to remove meta | read the message: it names the entity and id. Check filesystem/DB permissions and anything hooked on the delete |

`POST /woocommerce/attributes` is the only route that can answer with a code outside this table.
If `wc_create_attribute()` / `wc_update_attribute()` fails, the error is re-thrown with
WooCommerce's own error code and HTTP status (500 when WooCommerce sets none). Treat an unknown
`code` there as a WooCommerce-side rejection and read the message.

---

## 14. Things that will bite you

- **`POST /field-groups` deletes fields you omit.** A group update replaces the field set. Always
  send every field of the group.
- **`fields[].key` is the ACF field *name*.** It is what you put in `acf_fields`. The plugin
  manages the internal ACF `field_...` key and preserves it across updates.
- **`attributes`, `gallery` and `downloads` are replaced wholesale**, not merged. `null` or `[]`
  clears them.
- **`props` has a fixed key list.** Unrecognised keys are silently ignored. A typo in
  `regular_price` fails quietly, with no error.
- **Slugs are stable by design.** A product slug is written on insert and then left alone. To
  change it, send `update_slug: true` (a real JSON boolean).
- **WooCommerce term references are `local_key` only.** Slugs work in `Post` payloads and nowhere
  else.
- **`parent: 0` is rejected on `product_cat` and `product_brand`** (`400 invalid_param`). There
  `parent` is a `local_key`, and `0` is not a valid key. On `product_tag`, `pa_*` and `POST /terms`
  `parent` is a raw WordPress term id, so `0` does mean top level. `null`, or omitting the key,
  means top level everywhere. Prefer that.
- **Deletions do not propagate.** An item removed from On Page® stays published until the
  integration calls the matching `DELETE`.
- **The plugin has no dry-run.** Test against the Docker environment in [README](../../README.md)
  before pointing an integration at a live site.

---

## 15. Local environment

`docker-compose.yml` at the repo root brings up MySQL, WordPress and Adminer:

- WordPress on <http://localhost:8040>;
- Adminer on <http://localhost:8041>.

Control it with `./start`, `./stop` and `./restart`.

Setup steps:

1. Install ACF (and WooCommerce/WPML if in scope).
2. Set permalinks to **Post name**.
3. Generate a token from the **On Page®** menu.
