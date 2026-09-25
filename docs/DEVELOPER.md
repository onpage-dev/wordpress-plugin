# Building an integration

Developer guide for writing an **integration** against this plugin: an external program that reads
from On Page® and pushes structure and content into a WordPress site over the plugin's REST API.

The plugin never pulls. It receives. Everything below is what the caller has to get right.

| You want | Read |
| --- | --- |
| how to write an integration, with working examples | this file |
| the exhaustive payload reference, endpoint by endpoint | [API.md](API.md) |
| how the plugin works inside, service by service | [DEV.md](DEV.md) |
| the architectural decisions and their trade-offs | [design.md](design.md) |
| installing and configuring the plugin as a site admin | [USER.md](USER.md) |
| WooCommerce specifics | [WooCommerce.md](WooCommerce.md) |
| pagination details | [PAGINATION.md](PAGINATION.md) |

---

## 1. The shape of an integration

Three steps, always in this order:

```text
On Page® (PIM)  ──SDK read──▶  your integration  ──authenticated HTTP──▶  WordPress plugin
```

1. **Read** from On Page® with an official SDK (PHP or JS/TS).
2. **Map** PIM fields onto the plugin's payload keys.
3. **Write** to the plugin with plain authenticated `POST`/`DELETE` calls.

There is no SDK for step 3, and none is needed: the write side is JSON over HTTP with a bearer
token. Do not try to reuse the internal `Op\WordPress` manager from the exporter project — it
depends on Laravel, Eloquent and a `request_cache` table, and is not distributable.

### Reading with the On Page® SDKs

**PHP** — [`onpage-dev/onpage-php`](https://github.com/onpage-dev/onpage-php):

```bash
composer require onpage-dev/onpage-php
```

**JS / TS** — [`onpage-js`](https://github.com/onpage-dev/onpage-js):

```bash
npm install onpage-js
```

Both expose the same model: an API token maps to one project (**Schema**), a schema holds
**resources** (collections), a resource holds **things** (items), and a thing exposes its fields
through methods — `val()`, `values()`, `file()`, `rel()` — rather than as an array. Relations must
be preloaded with `with()` and then walked with `rel()`, so one query brings the whole tree home
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

The important hand-off is files. `link()` returns a public URL, and a URL is exactly what the
plugin accepts. **The integration never downloads binaries**: it passes the URL, and the download,
the Media Library import and the deduplication all happen on the WordPress side.

---

## 2. Prerequisites

| Component | When | Consequence if missing |
| --- | --- | --- |
| PHP 8.2+ | always | the plugin code does not run (`Requires PHP` in `onpage.php`) |
| WordPress 7.1+ | always | declared floor (`Requires at least`) |
| **ACF** | **always** | **no route is registered at all** — see below |
| ACF PRO | only for its own field types (`repeater`, `flexible_content`, …) | the plugin does **not** check the ACF edition: `POST /field-groups` passes `fields[].type` straight to `acf_update_field()` and still answers `200`, so a PRO-only field is stored but non-functional |
| WooCommerce | only for `/woocommerce/*` | `500 woocommerce_required` per request |
| WPML | only for language-map payloads | `500 wpml_required` per request |
| Pretty permalinks (anything but **Plain**) | only for the `/wp-json/` URL form | with **Plain**, `/wp-json/onpage/v1/…` 404s; the same routes stay reachable at `/?rest_route=/onpage/v1/…` |

### ACF is a hard dependency

`onpage.php` includes `routes.php` on `plugins_loaded` **only** when `acf_get_field_groups()`
exists. With ACF inactive the plugin registers no routes, so every call gets WordPress' own
`404 rest_no_route` — not a plugin error — and the dashboard shows a notice naming the missing
dependency.

This is deliberate: the plugin reaches ACF's own API with no guard. `Acf::loadFieldTypeMap()`
calls `acf_get_field_groups()` (`src/Services/Acf.php:91`) on the ACF-aware routes, and the
post-type, field-group and taxonomy services call `acf_get_acf_post_types()` /
`acf_get_field_groups()` directly (`src/Services/PostType.php:73`, `src/Services/FieldGroup.php:40`,
`src/Services/Taxonomy.php:290`). A registered route would die with a PHP fatal instead of
answering.

> A `404` on *every* endpoint means either ACF is inactive or permalinks are on **Plain**. Tell
> them apart by the body: ACF inactive gives a JSON `{"code":"rest_no_route"}` (WordPress' own
> code) plus the dashboard notice, while **Plain** permalinks give WordPress' HTML 404 page.

---

## 3. Connecting

Base URL for every call:

```text
https://<site>/wp-json/onpage/v1
```

### Token

Generated from the **On Page®** top-level admin menu (`manage_options` capability) as
`bin2hex(random_bytes(32))` — 64 lowercase hex characters — and stored in the non-autoloaded
option `onpage_auth_token`.

Every one of the 43 routes is authenticated. There is no public route, and no capability check
beyond the token.

```http
Authorization: Bearer <token>
```

The header is read with `get_header('authorization')` and matched against
`/^\s*Bearer\s+(.+)\s*$/i` — the scheme word is case-insensitive, surrounding whitespace is
tolerated, the token itself is compared byte-exact with `hash_equals()`. There is no query
parameter, cookie or nonce fallback.

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

`200` means the plugin answers and the token is good. `401`/`403` is the token. `404` is ACF being
inactive, permalinks on **Plain**, or rewrite rules not flushed.

---

## 4. Rules that apply to every endpoint

**Write bodies are JSON arrays.** Every content `POST` takes a **list of objects**, never a single
object. Every `DELETE` takes a **list of scalars** — `local_key`s, numeric ids or slugs depending
on the route (`DELETE /posts` also accepts `{"local_key": …}` objects). Four exceptions:
`POST /media` is `multipart/form-data`, `POST /media/link` takes a single object, and
`POST /migration` and `DELETE /indexes` take no body at all.

**Nothing is transactional across the batch.** Each endpoint is a `foreach` over the payload that
throws on the first failure. The successful prefix is already committed, there is no per-element
result, and the response body is replaced entirely by the error object.

Within a single element there is a partial rollback: the *create* path of `POST /posts` and
`POST /woocommerce/products` wraps its work in `try`/`catch` and hard-deletes the rows it just
created before re-throwing (`src/Services/Post.php:1807-1813`). So a failed element usually leaves
nothing behind — but earlier elements of the same batch stay.

Most per-element errors carry `Element <i>`, the 0-based position in your array, and that index is
the only machine-usable locator. Some omit it — the post integrity errors `duplicate_title` and
`duplicate_local_key` (`src/Services/Post.php:502`, `:506`) among them — so fall back to matching
the message when the index is absent.

Re-running the same batch is safe, which is what makes partial state acceptable.

**Error envelope.** Deliberate errors are WordPress `WP_Error`s:

```json
{
  "code": "duplicate_title",
  "message": "Post :: Title 'Red Chair' already exists for PostType 'product'",
  "data": { "status": 409 }
}
```

Anything that is *not* a deliberate error is re-thrown unchanged, so it surfaces as a PHP fatal /
generic 500 rather than as JSON. A bare `500 There has been a critical error` is a bug, not a
contract violation — report it.

**Successful content `POST`s** answer `200` with the list of created or updated ids, in payload
order. `POST /media` and `POST /media/link` answer with one result object per file or field,
`POST /migration` with an object of counters, and every `DELETE` with `200` and a `null` body.

**`?ignore` on DELETE** makes already-missing objects tolerable. It is a **presence-only** flag
read strictly from the query string: `?ignore`, `?ignore=0` and `?ignore=false` all enable it.
Honoured by 12 DELETE routes — `field-groups`, `post-types`, `posts`, `taxonomies`, `media` and
all seven `woocommerce/*`. **Not** honoured by `DELETE /terms` or `DELETE /indexes`.

**Pagination** exists on exactly two routes: `GET /posts` and `GET /media`. Everything else
returns the complete set.

- `per_page` defaults to **100** and is hard-capped at **100**; `page` defaults to 1. Garbage,
  `0` and negatives fall back to the defaults.
- Responses carry `X-WP-Total` and `X-WP-TotalPages`.
- On `GET /posts` the headers are **conditional**: the `?id=` and `?title=` branches are lookups,
  not listings, and return no headers. A missing `X-WP-Total` there means "you are on a lookup
  branch", not "the list is empty".

**Send large batches, not single calls.** The product and variation controllers defer term-count
recalculation and parent resynchronisation to the whole request; one call per item loses both.

---

## 5. `local_key`, the idempotency key

`local_key` is your identifier, carried on every **content** object — posts, products, variations,
terms, categories, tags, brands, attributes and attribute terms — so the same import can run any
number of times without creating duplicates.

The structural endpoints have no `local_key`: `POST /post-types`, `POST /taxonomies` and
`POST /field-groups` upsert by `post_type`, `key` and `title`/`key` respectively, and ignore the
field if you send it.

- **Accepted forms**: a positive integer or a non-empty string. Values are `trim()`ed; `""` and
  `"0"` are rejected with `400` — code `invalid_param` on the POST routes and the WooCommerce
  DELETEs, code `input_invalid` on `DELETE /posts?keyfield=local_key`.
- **Integers and numeric strings are equivalent** — `123` and `"123"` resolve to the same object,
  because WordPress stores meta as strings.
- **In responses** an integer-canonical key comes back as an integer, a non-numeric one as a
  string, and an unset one as `null`.
- **Storage**: post meta / term meta `onpage_local_key` — the same key for posts, products,
  variations, terms, categories, tags, brands and attribute terms. Global WooCommerce attributes
  are the exception: they live in the option `onpage_wc_attribute_local_key_{attribute_id}`.
- **WPML**: every translation in a group shares the same `local_key`. It identifies the object,
  not the language.

Use the **On Page® item id** as the `local_key` — not the name, not the SKU. It is the only
identifier that is stable and not editorially owned. Replicating it into a plain ACF `text` field
is a cheap debugging aid, since it makes the key visible in the admin — but **name it `onpage_id`**,
never `local_key` or `_local_key`: both of those names are reserved by the plugin and are wiped by
`DELETE /indexes`.

The key is written **immediately after the row is created**, before the slow work on media, ACF
fields and terms, so a process-level kill — PHP fatal, timeout, OOM — still leaves an object the
next run can resolve. A *thrown* error is different: the create path deletes the rows it just made
before re-throwing, so a failed element leaves nothing keyed.

`DELETE /indexes` moves this ground under you: it wipes every key held in post meta and term meta,
so the next import re-establishes them from scratch. It does **not** touch the global attribute
keys in the `onpage_wc_attribute_local_key_{attribute_id}` options, which survive the wipe.

`POST /migration` is not part of a new integration: it exists only to upgrade a site that ran a
legacy build of the plugin, and does nothing on a site keyed by this version.

---

## 6. Call order

The order is not a convention. Each step consumes identifiers created by the previous ones.

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

What you get for breaking it:

| Violation | Error |
| --- | --- |
| taxonomy and its terms in the **same** HTTP request | `404 not_found` — *Taxonomy '…' not found*. WordPress registers taxonomies on `init`, so a new one is addressable only from the next request |
| `acf_fields` key with no field group carrying it | `400 invalid_param` — *ACF field '…' not found for post '…'* |
| `POST /posts` for an unknown post type | **nothing on insert** — the create path never calls `post_type_exists()`, so the post is written with an unregistered type and is invisible in the admin. The `404 not_found` — *PostType '…' not found* — appears only on the update path, on `GET /posts?type=` and on `DELETE /posts` |
| term reference that resolves to nothing | `404` with code `input_invalid` — *Term reference '…' not found in taxonomy '…'* |
| child term before its parent | `404 not_found` — *Parent local_key '…' not found for taxonomy '…'* |
| variation before its parent, or parent not `variable` | `400 invalid_param` / `404 not_found` on the parent lookup |
| variation option that is not an existing attribute term | `404 not_found` — *Attribute '…' option '…' was not found* |
| language map on a site without WPML | `500 wpml_required`, naming the exact field path |
| `/woocommerce/*` without WooCommerce | `500 woocommerce_required` |

Two constraints worth repeating, because they are the ones that bite:

- **Taxonomies and terms need two separate HTTP requests.**
- **Term references in WooCommerce payloads are `local_key` only** — `brand`, `categories`, `tags`
  and `terms` accept neither slugs nor names. In `Post` payloads a reference may be a `local_key`
  **or** a slug: the plugin tries the key first, then the slug. The one WooCommerce exception is
  the `attributes` map on `POST /woocommerce/variant-products`, which points at attribute terms and
  resolves each value as term id, then slug, then **name**.

---

## 7. Route map

43 routes, all authenticated. `P` marks the two that paginate; `I` marks the DELETEs that honour
`?ignore`.

| Route | Methods | Query params | Purpose |
| --- | --- | --- | --- |
| `/field-groups` | GET POST DELETE `I` | `ignore` | ACF field groups |
| `/post-types` | GET POST DELETE `I` | `ignore` | Custom Post Types |
| `/posts` | GET `P` POST DELETE `I` | `id` `type` `title` `local_key` `status` `updated_after` `page` `per_page` `keyfield` `ignore` | content, ACF values, term assignment |
| `/posts/{id}` | GET | `keyfield` `type` | single post by id or `local_key` |
| `/taxonomies` | GET POST DELETE `I` | `ignore` | taxonomies |
| `/terms` | GET POST DELETE | `taxonomy` `name` `parent_id` `parent_lk` | terms of any taxonomy, per-element `taxonomy` |
| `/media` | GET `P` POST DELETE `I` | `page` `per_page` `post_id` `mime_type` `attachment_id` `attachment_ids` `ignore` | Media Library, multipart upload |
| `/media/link` | POST | — | write ACF file/image fields: import a remote URL, attach an existing `attachment_id` (int), or clear the field (`null`) |
| `/migration` | POST | — | one-off upgrade of a legacy installation; not used by a new integration |
| `/indexes` | DELETE | — | wipe every `local_key` |
| `/woocommerce/attributes` | GET POST DELETE `I` | `id` `slug` `local_key` `name` `ignore` | global `pa_*` attributes |
| `/woocommerce/attributes/{attribute}/terms` | GET POST DELETE `I` | `id` `local_key` `slug` `name` `parent_id` `parent_lk` `ignore` | attribute options |
| `/woocommerce/brands` | GET POST DELETE `I` | `id` `local_key` `slug` `name` `parent_id` `parent_lk` `ignore` | `product_brand`, hierarchical |
| `/woocommerce/categories` | GET POST DELETE `I` | same as brands | `product_cat` |
| `/woocommerce/tags` | GET POST DELETE `I` | same as brands | `product_tag` |
| `/woocommerce/products` | GET POST DELETE `I` | `id` `local_key` `name` `ignore` | simple and variable products |
| `/woocommerce/variant-products` | GET POST DELETE `I` | `id` `local_key` `parent_id` `parent` `ignore` | variations |

`{id}` and `{attribute}` match a single non-`/` path segment. `{attribute}` accepts an attribute
id, or a slug with or without the `pa_` prefix — `color` and `pa_color` both work.

---

## 8. Worked example: structure and content

The whole client is one authenticated `POST` helper and one multilingual helper.

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

Idempotent: re-sending updates in place.

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

`cursor()` is the SDK's pagination and memory-containment helper: it iterates one item at a time
instead of materialising the whole collection. (SDK behaviour — see the `onpage-php` docs, not this
repo.)

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

`local_key`, `type` and `title` are required on insert. `description` lands in `post_excerpt`, not
in a meta field, and is not returned by the GET responses. `term` is the canonical key; `terms` is
a legacy alias used only when `term` is absent.

---

## 9. Worked example: WooCommerce catalogue

One eager-loaded query brings the tree home; each category's products leave in a single batch.

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

Two calls, in order: the parent declaring every possible option, then the variations with one
option each.

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

`local_key` plus either `parent_id` or `parent` are required. `attributes` is required when
creating a variation; omitting it on an update leaves the stored attributes untouched. The
variation `status` is aliased: `draft`, `pending` and `disabled` become `private`, `enabled`
becomes `publish`.

> **`attributes` and `terms` are two different mechanisms.** `attributes` creates **custom**
> product attributes, even when the name you send looks like `pa_colour`. To link a product to the
> **terms of a global attribute**, use `terms` instead — `"terms": {"pa_colour": [5101]}`.
> Attributes drive variations; terms drive taxonomy archives and filters.

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

On `product_tag` and `pa_*` payloads, `parent` is a **raw WordPress term id**, not a `local_key` —
only `product_cat` and `product_brand` reinterpret it as a key. `thumbnail` is accepted but
silently ignored on anything other than `product_cat` (brands have their own thumbnail handling).

---

## 10. Multilingual payloads

Every translatable value takes one of two shapes: a **scalar**, shared across all languages, or a
**language map**.

```json
{
  "local_key": 42,
  "name": { "en": "Red Chair", "it": "Sedia Rossa" },
  "props": { "sku": "CHAIR-RED" }
}
```

Detection is structural: a non-empty object that is not a list, all of whose keys match
`/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})*$/i` — case-insensitive, so `en`, `it`, `pt-br`, `pt_BR` and
`zh-hans-cn` all qualify. **One key that is not language-code-shaped disqualifies the whole
object**, which is then treated as a plain structured value.

That is normally what keeps `props`, `acf_fields` and `attributes` out of the translation path —
but only because their keys are usually longer than a language code. An object whose keys *all*
happen to fit the shape **is** read as a language map: `{"url": …, "alt": …}` and
`{"lat": …, "lng": …}` both qualify, and on a site without WPML that is a `500 wpml_required`.
Give such sub-objects at least one key longer than three letters.

The map's keys are intersected with the site's **active** WPML languages. A language the site has
not activated is **silently dropped**, by design, so one unknown code cannot hide the real
translations. Sending a language map to a site without WPML is a hard `500 wpml_required`, and the
message names the exact field path.

All translations of an object share one `local_key`. Send every language in a single element — the
plugin creates the WPML group, backfills missing languages on update, and repairs translation
slots left orphaned by a killed import.

---

## 11. Media and files

Everywhere except the multipart upload, a file slot accepts **either** an existing attachment id
**or** a remote URL. Inside `acf_fields` that covers the ACF types `image` and `file` only,
including as `repeater` sub-fields — values of `gallery` and `flexible_content` fields are stored
verbatim and never imported.

- An attachment id must be a **JSON integer**. `123` works; `"123"` does not — a numeric string
  falls through to the URL branch. It is then rejected with `400` by `POST /media/link`,
  `POST /posts` (`files`), `image`, `gallery`, `thumbnail` and `downloads[].file`. Inside
  `acf_fields` it is **not** rejected: an `image`/`file` value that is neither an attachment id nor
  a valid URL is written into the field verbatim and the call still answers `200`.
- The exceptions that *do* accept numeric strings: `POST /media`'s own `attachment_id` /
  `attachment_ids` form fields, the id list in the `DELETE /media` body, and `post_id` everywhere.
- A URL is validated with `esc_url_raw()` + `wp_http_validate_url()` before anything is fetched.

**Deduplication.** Remote imports are keyed by the `_onpage_source_url` attachment meta, so the
same URL is never downloaded twice: the existing attachment is reused (`action: "linked"`). If its
file has vanished from disk, the stale row is dropped and the import self-heals
(`action: "created"`).

**Refreshing.** Two paths replace the bytes behind an existing attachment in place:
`downloads[].refresh` on `POST /woocommerce/products`, which re-downloads the remote URL over the
attachment matched by `_onpage_source_url`, and `POST /media` with `attachment_id` /
`attachment_ids`, which uploads new bytes over the attachment you name and answers
`action: "replaced"`. Everywhere else, the same URL means the same file.

**SVG.** Remote SVGs are sanitised on import, and the `image/svg+xml` MIME is enabled only for the
duration of that single sideload. The refresh path does not do this: refreshing an `.svg` fails
with `500 request_failed`.

**Two upload paths:**

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

`POST /media/link` is the only write endpoint whose body is a single object rather than a list.
The parent post must already exist: `404 not_found` — *Media :: Post 123 not found*.

Media is downloaded **synchronously and serially inside the REST request**. A product with an
image, a gallery and two PDFs can spend tens of seconds on I/O alone. Size your batches against
the site's `max_execution_time` and `memory_limit`.

---

## 12. Let the schema drive the mapping

The highest-return technique is reading the On Page® **structure** at runtime instead of
hard-coding field names. An On Page® field folder becomes a content block that editors can extend,
rename and reorder without a deployment:

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
`Field::getLabel()` — belongs to the `onpage-php` SDK, not to this plugin; check its docs for the
current signatures.

The same principle applies to editorial labels and to single-choice options: keeping them as data
in On Page® rather than as strings in code moves control to where the content lives. The trade is
real, though — the field folder becomes a **prerequisite** of the integration, and renaming it
breaks the code.

---

## 13. Error reference

Distinct codes, with the HTTP status they carry. Most messages are prefixed with the entity, and
batch endpoints that track the element add `Element <i>` — but `POST /field-groups` and
`POST /post-types` report no index, and `invalid_keyfield` and `acf_error` carry no entity prefix.
Parse the `code`, not the message shape.

| Code | HTTP | Typical cause | Fix |
| --- | --- | --- | --- |
| `onpage_auth_missing_token` | 401 | no `Authorization: Bearer` header reached PHP | check the client; on some Apache CGI/FastCGI setups the header needs a rewrite rule |
| `onpage_auth_invalid_token` | 403 | token regenerated, truncated or mis-copied | re-copy it from the **On Page®** page |
| `onpage_auth_not_configured` | 500 | no token generated on the site | open the **On Page®** page and generate one |
| `rest_no_route` | 404 | a WordPress core code, not a plugin one: while ACF is inactive the plugin registers no routes at all | activate ACF. If you get WordPress' HTML 404 page instead of a JSON `code`, permalinks are on **Plain** — switch to **Post name** or call `?rest_route=/onpage/v1/…` |
| `invalid_param` | 400 | a required key is missing, or a value has the wrong type/shape | read the message: it names the parameter and the element index |
| `invalid_keyfield` | 400 | `?keyfield=` outside the allowed set | use `id`, `local_key` or `id_or_post_type` as documented per route |
| `missing_title` | 400 | `POST /field-groups` without `title` | send a non-empty `title` |
| `upload_failed` | 400 / 500 | rejected or unwritable upload | check MIME, size and filesystem permissions |
| `not_found` | 404 | either the object the call targets is missing (any DELETE, and id/`local_key` lookups on `/woocommerce/*`, `/field-groups`, `/post-types`, `/taxonomies`, `/media`), or something the payload references — post type, taxonomy, term, parent, attribute, attachment — does not exist | missing DELETE target: send `?ignore`, or fix the id/`local_key`. Missing reference: respect the call order in §6 |
| `input_invalid` | 404 | a term reference in the payload resolves to nothing | sync terms before the content that references them |
| `input_invalid` | 400 | a `DELETE /posts` body element is neither an int id nor a string slug/`local_key`, or a `files` value is neither an existing attachment id nor a valid URL | read the message: it names the element index or the field key |
| `no_post` | 404 | the target post does not exist | check `id` / `local_key` |
| `duplicate_title` | 409 | an **unkeyed** post of that post type has the exact same title and is outside this object's WPML translation group. A post that already carries a *different* `local_key` is a distinct On Page® element and never conflicts, so two same-named elements import fine | give the existing unkeyed post the `local_key`, so the request updates it instead of inserting; or differentiate the title |
| `duplicate_local_key` | 409 | the `local_key` is already held by a different object. On `POST /woocommerce/products` and `POST /terms` it needs an explicit `id` in the payload; on `/posts`, `/woocommerce/attributes` and `/woocommerce/variant-products` it fires with no `id` too | if you sent `id`, drop it and let `local_key` resolve; otherwise the key belongs to another object — re-key it, or clear the stale key |
| `ambiguous_local_key` | 409 | the same `local_key` exists on posts of more than one post type and the request did not scope it | pass the type — `?type=` on `GET /posts/{id}` and `DELETE /posts`, or the `type` key in the `POST /posts` element |
| `parent_mismatch` | 409 | `parent_id` and `parent` point at different parents | send one of the two |
| `wpml_required` | 500 | language map sent to a site without WPML | install WPML, or send scalars |
| `woocommerce_required` | 500 | `/woocommerce/*` with WooCommerce inactive | activate WooCommerce |
| `wpml_error` / `acf_error` | 500 | WPML or ACF refused a write | check the message; usually a field-group or language misconfiguration |
| `request_failed` | 500 | a WordPress query or write failed | check the message and the site error log |
| `migration_failed` | 500 | `POST /migration` could not read or rewrite meta | check DB permissions, then re-run — it is idempotent |
| `delete_failed` | 500 | any DELETE whose underlying WordPress/ACF delete call returned false — field group, post type, taxonomy, term, attachment, product, variation, attribute — or `DELETE /indexes` failing to remove meta | read the message: it names the entity and id. Check filesystem/DB permissions and anything hooked on the delete |

`POST /woocommerce/attributes` is the one route that can answer with a code outside this table:
a failure inside `wc_create_attribute()` / `wc_update_attribute()` is re-thrown with
WooCommerce's own error code and HTTP status (500 when WooCommerce sets none). Treat an unknown
`code` there as a WooCommerce-side rejection and read the message.

---

## 14. Things that will bite you

- **`POST /field-groups` deletes fields you omit.** Group updates replace the field set. Always
  send every field of the group.
- **`fields[].key` is the ACF field *name*.** It is what you put in `acf_fields`. The internal
  ACF `field_...` key is managed by the plugin and preserved across updates.
- **`attributes`, `gallery` and `downloads` are replaced wholesale**, not merged. `null` or `[]`
  clears them.
- **`props` has a fixed key list.** Unrecognised keys are ignored silently — a typo in
  `regular_price` fails quietly, with no error.
- **Slugs are stable by design.** A product slug is written on insert and then left alone unless
  you send `update_slug: true` (a real JSON boolean).
- **WooCommerce term references are `local_key` only.** Slugs work in `Post` payloads and nowhere
  else.
- **`parent: 0` is rejected on `product_cat` and `product_brand`** (`400 invalid_param`): there
  `parent` is a `local_key`, and `0` is not a valid key. On `product_tag`, `pa_*` and `POST /terms`
  it is a raw WordPress term id, so `0` does mean top level. `null` or omitting the key means top
  level everywhere — prefer that.
- **Deletions do not propagate.** An item removed from On Page® stays published until the
  integration calls the matching `DELETE`.
- **The plugin has no dry-run.** Test against the Docker environment in [README](../README.md)
  before pointing an integration at a live site.

---

## 15. Local environment

`docker-compose.yml` at the repo root brings up MySQL, WordPress and Adminer — WordPress on
<http://localhost:8040>, Adminer on <http://localhost:8041>. Use `./start`, `./stop` and
`./restart`. Install ACF (and WooCommerce/WPML if in scope), set permalinks to **Post name**, then
generate a token from the **On Page®** menu.
