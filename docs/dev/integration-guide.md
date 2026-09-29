# Building a client

This guide is for developers who write a **client** for this plugin. A client is any program that
pushes structure and content into a WordPress site through the plugin's REST API. The plugin does
not depend on who writes the client or how it runs.

The plugin never pulls data. It only receives it. This guide covers everything the caller has to
get right.

| You want | Read |
| --- | --- |
| how to write a client, with working examples | this file |
| the full payload reference, endpoint by endpoint | [API.md](../API.md) |
| how the plugin works inside, service by service | [internals.md](internals.md) |
| the architectural decisions and their trade-offs | [architecture.md](architecture.md) |
| how to install and configure the plugin as a site admin | [user/guide.md](../user/guide.md) |
| the list of every endpoint | [API.md: Endpoint index](../API.md#endpoint-index) |
| how WooCommerce products and downloads are saved | [woocommerce.md](woocommerce.md) |
| pagination details | [API.md: Pagination](../API.md#pagination) |

---

## 1. The shape of a client

A typical client runs three steps, in this order:

```text
On Page® (PIM)  ──SDK read──▶  your client  ──authenticated HTTP──▶  WordPress plugin
```

1. **Read** from On Page® with an official SDK (PHP or JS/TS).
2. **Map** PIM fields onto the plugin's payload keys.
3. **Write** to the plugin with plain authenticated `POST`/`DELETE` calls.

Step 3 has no SDK, and it does not need one. The write side is JSON over HTTP with a bearer token.

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
**The client never downloads binaries.** It passes the URL. The WordPress side handles the
download, the Media Library import and the deduplication.

The SDK calls use your On Page® API token, so they count against that token's
[usage limits](https://app.onpage.it/#/help/advanced-tools/api-rate-limits). On large catalogs,
expect throttling. To create the token, see
[Creating and Managing an API Token](https://app.onpage.it/#/help/connections/create-manage-api-token).

### Two tokens

A client holds two tokens. They are not interchangeable:

| Token | Used for | Where it comes from |
| --- | --- | --- |
| On Page® API token | reading from On Page® with the SDK | On Page® |
| plugin token | writing to the WordPress site | the **On Page®** admin page of that site (see [§3](#3-connecting)) |

### Practices that pay off

None of these is required by the plugin, but clients that follow them are easier to run:

- **Keep secrets out of the repository.** Read both tokens and the site URL from the environment
  or a secrets store.
- **Allow a partial run.** Accept a list of `local_key`s, so you can re-sync a few items without
  running the whole import.
- **Keep two logs.** A progress log for humans, and a journal of every HTTP request and response.
  The journal is what you need when a site reports an error.
- **Fail fast.** Stop at the first unexpected error instead of pushing the rest of the batch. A
  structure error usually breaks every later call too. Re-running is safe (see
  [§4](#batches-are-not-transactional)).

---

## 2. Prerequisites

| Component | When | Consequence if missing |
| --- | --- | --- |
| PHP 8.2+ | always | the plugin code does not run (`Requires PHP` in `plugin.php`) |
| WordPress 7.1+ | always | declared minimum (`Requires at least`) |
| **ACF 6.1+** | **always** | ACF missing: **no route is registered at all** — see below. ACF older than 6.1: every authenticated call answers `500 acf_version_unsupported`, and the dashboard shows a notice. The plugin needs 6.1 because it calls `acf_update_post_type()` and `acf_update_taxonomy()`, added in that version |
| ACF PRO | only for its own field types (`repeater`, `flexible_content`, …) | the plugin does **not** check the ACF edition. `POST /field-groups` passes `fields[].type` straight to `acf_update_field()` and still answers `200`. A PRO-only field is stored but does not work |
| WooCommerce | only for `/woocommerce/*` | `500 woocommerce_required` on each request |
| WPML | only for language-map payloads | `500 wpml_required` on each request |
| WPML: post types set to **Translatable** | for multilingual posts and products | `500 wpml_error` (*Unable to resolve translation group*) or `500 request_failed` (*Failed to initialize WPML language details*). The plugin marks its taxonomies translatable, but not post types. The site admin sets them in **WPML > Settings > Post Types Translation**, `product` included, before the first multilingual import |
| PHP `max_execution_time` and `memory_limit` sized for media | when payloads carry files | media is downloaded synchronously, one file after another, inside the request (see [§11](#timing)). A request cut off by the server leaves the element half written |
| Pretty permalinks (anything but **Plain**) | only for the `/wp-json/` URL form | with **Plain**, `/wp-json/onpage/v1/…` returns 404. The same routes stay reachable at `/?rest_route=/onpage/v1/…` |

### ACF is a hard dependency

`plugin.php` includes `src/routes.php` on `plugins_loaded` **only** when `acf_get_field_groups()`
exists. With ACF inactive:

- the plugin registers no routes;
- every call gets WordPress' own `404 rest_no_route` (not a plugin error);
- the dashboard shows a notice naming the missing dependency.

This is deliberate. The plugin calls ACF's API without guards:

- `Acf::loadFieldTypeMap()` calls `acf_get_field_groups()` (`src/Services/Acf.php:188`) on the
  ACF-aware routes.
- The post-type, field-group and taxonomy services call `acf_get_acf_post_types()` /
  `acf_get_field_groups()` directly (`src/Services/PostType.php:19`,
  `src/Services/FieldGroup.php:40`, `src/Services/Taxonomy.php:290`).

If the routes were registered without ACF, they would die with a PHP fatal instead of answering.

An ACF older than 6.1 (`Acf::MIN_VERSION`) lacks `acf_update_post_type()` and
`acf_update_taxonomy()`. In that case the routes are still registered, and the dashboard shows a
notice. After the token check, `Middlewares\Auth::handle()` calls
`Acf::requireSupportedVersion()`, so every call answers `500 acf_version_unsupported` with the
active version in the message. The version is read from `acf_get_setting('version')`, or
`ACF_VERSION`. A version that cannot be read is not blocked.

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
- There is one token per site. It has no expiry and no rotation: regenerating it invalidates the old
  one at once, with no grace period. A run in progress then fails with `403`. Ask the site admin to
  regenerate it in a maintenance window.

All 44 routes are authenticated. There is no public route. There is no capability check beyond the
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

- Every content `POST` takes a **list of objects**, never a single object. Each element must be a
  non-empty JSON object.
- Every `DELETE` takes a **list of scalars**: `local_key`s, numeric ids or slugs, depending on the
  route. `DELETE /posts` also accepts objects: `{"local_key": …, "type": …}`, `{"id": …}` or
  `{"type": …}`. `{"type": …}` alone deletes every post of that type. So an object whose
  `local_key` is present but invalid, or whose `id` is present but not a positive JSON integer,
  fails with `400 input_invalid`. It never falls back to the type.
- Send `Content-Type: application/json`. Without it WordPress does not parse the body, and the
  request fails as if the body were missing.

The plugin checks the shape before doing any work:

| You send | Result |
| --- | --- |
| no body, invalid JSON, a scalar or a single object (`{}` included) | `400 invalid_param` — *`<Prefix>` :: Request body must be a JSON array* |
| `[]` | `200`, nothing happens |
| a `POST` element that is not a non-empty object (`null`, `"x"`, `[]`, `{}`) | `400 invalid_param` — *… Element `<i>` :: Invalid payload; expected a non-empty JSON object* |
| a `DELETE` element of the wrong type | `400 input_invalid` on `field-groups`, `post-types`, `posts`, `taxonomies` and `terms`; `400 invalid_param` on `woocommerce/*` and `media` |

`DELETE /terms` is strict about ids: a positive integer or a string of digits only. `"12abc"`,
`"1.5"` or `true` are rejected, never cast. A plain string is always an ID there: to delete by
`local_key`, send `{"local_key": …}` or use `?keyfield=local_key`. The full rules are in
[API.md: Request bodies](../API.md#request-bodies).

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
it just created, translations included, then re-throws (`src/Services/Post.php:2002-2008`). So a
failed element usually leaves nothing behind. Earlier elements of the same batch stay.

Most per-element errors carry `Element <i>`: the 0-based position in your array. That index is the
only machine-usable locator. A few errors omit it, such as `Post :: PostType '<type>' not found`.
When the index is absent, match on the message instead.

Re-running the same batch is safe. That is why partial state is acceptable.

### Error envelope

Deliberate errors are WordPress `WP_Error`s:

```json
{
  "code": "duplicate_title",
  "message": "Post :: Element 0 :: Title 'Red Chair' already exists for PostType 'product'",
  "data": { "status": 409 }
}
```

Any other exception is caught by the router (`src/Router.php:67-75`). This covers a WooCommerce
`WC_Data_Exception` or a PHP `TypeError`, for example. It comes back in the same JSON envelope,
with code `request_failed`, HTTP `500` and the original exception message. Such a `500` usually
means a bug, not a contract violation. Report it with the message.

### Success responses

| Request | Response |
| --- | --- |
| content `POST` | `200` with the list of created or updated ids, in payload order |
| `POST /media`, `POST /media/link` | one result object per file or field |
| `POST /migration`, `DELETE /indexes` | an object of counters |
| any other `DELETE` | `200` with a `null` body |

### `?ignore` on DELETE

`?ignore` makes a DELETE tolerate objects that are already missing.

- It is a **presence-only** flag, read strictly from the query string. `?ignore`, `?ignore=0` and
  `?ignore=false` all enable it.
- It is honoured by 13 DELETE routes: `field-groups`, `post-types`, `posts`, `taxonomies`,
  `terms`, `media` and all seven `woocommerce/*`.
- It is **not** honoured by `DELETE /indexes`, which has no body.
- It skips only what is **missing**: an id, `local_key`, title, key or slug that matches nothing.
  A malformed element is still a `400`, and a real delete failure is still a `500`.
- On `DELETE /taxonomies`, a slug that is not one of the plugin's ACF taxonomies (e.g.
  `product_cat`) is skipped and nothing is touched. On `DELETE /posts`, a post type string that is
  not registered is skipped the same way.

### Pagination

Only two routes paginate: `GET /posts` and `GET /media`. Every other route returns the complete
set. Read the `X-WP-TotalPages` header to know when to stop. On `GET /posts`, the `?id=` and
`?title=` lookups return no pagination headers at all.

`GET /woocommerce/products` and `GET /woocommerce/variant-products` return every match in a single
response. Always filter them (`?local_key=`, `?id=`, `?parent_id=`) instead of listing a whole
catalogue.

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
  - code `input_invalid` on `DELETE /posts` (`?keyfield=local_key`, or a `local_key` in an object
    element).
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
- **Trash**: a trashed post still owns its `local_key`. `POST /posts` finds it, restores it and
  updates it, instead of creating a second post with the same key. If a live post also holds the
  key, the live post is updated and the trashed one stays in the trash. Without a `status` in the
  payload the restored post is a `draft`. Reads leave the trash out: `GET /posts?local_key=` and
  `GET /posts/{id}?keyfield=local_key` do not return a trashed post (the latter answers
  `404 no_post`).

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

`POST /migration` is not part of a normal import. Call it once after a plugin update, when the
release notes ask for it. On a site with nothing to migrate it does nothing.

---

## 6. Call order

The order is not a convention. Each step uses identifiers created by the previous ones.

On a multilingual site, start every import with `GET /languages`. Keep only the languages it
returns, and filter every language map with them before you send it (see
[Filter language maps before you send them](#filter-language-maps-before-you-send-them)).

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
| `POST /posts` for an unknown post type | `404 not_found` — *Post :: PostType '…' not found*, on insert and on update |
| post type sent with a key that is not already clean (`"Catalog"`, `"my type"`) | `400 invalid_param` from `POST /post-types`. Send the lowercase key you will use in `POST /posts` |
| taxonomy sent with a key that is not already clean (`"Brand"`), or longer than 32 characters | `400 invalid_param` from `POST /taxonomies`. Send the lowercase key you will use in `POST /terms` |
| term reference that resolves to nothing | `404` with code `input_invalid` — *Term reference '…' not found in taxonomy '…'* in `Post` payloads, *Term with local_key '…' not found in taxonomy '…'* in WooCommerce payloads |
| child term before its parent | `404 not_found`. On `product_cat` and `product_brand`: *Parent local_key '…' not found for taxonomy '…'*. On `POST /terms`, a `parent` term ID that does not exist: *Parent term '…' not found for taxonomy '…'* |
| `parent` on `POST /terms` that is not a term ID, `0` or `null` (a slug or a non-numeric `local_key`, for example) | `400 invalid_param`. Send the parent's WordPress term ID, as returned by the earlier `POST /terms` |
| `POST /posts` with a `status` that is not a registered post status, or is `trash`, `auto-draft` or `inherit` | `400 invalid_param`. The message lists the allowed statuses |
| variation before its parent, or parent not `variable` | `400 invalid_param` / `404 not_found` on the parent lookup |
| variation option that is not an existing attribute term | `404 not_found` — *Attribute '…' option '…' was not found* |
| two variations of one parent with the same attributes | `409 duplicate_variation` — *Parent product … already has variation … with attributes […]* |
| language map on a site without WPML | `500 wpml_required`, naming the exact field path |
| `/woocommerce/*` without WooCommerce | `500 woocommerce_required` |
| any call on a site with ACF older than 6.1 | `500 acf_version_unsupported`, naming the active version |

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

There are 44 routes, all authenticated. The [endpoint index](../API.md#endpoint-index) in API.md
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
    // POST /post-types and POST /field-groups report one only for a malformed element.
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
//    post_type must already be a clean key: lowercase letters, digits, "_" or "-".
wp('/post-types', [[
    'post_type'      => 'catalog',
    'singular_label' => 'Catalogue',
    'plural_label'   => 'Catalogues',
    'icon'           => 'dashicons-archive',
    'supports'       => ['title', 'editor', 'thumbnail'],
    'taxonomies'     => ['catalog_type'],
]]);

// 2. Taxonomy. "key" is both the ACF record key and the taxonomy slug.
//    key, singular_label and plural_label are required.
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
        // will use in acf_fields, not an ACF "field_..." key. It is stored
        // lowercased (sanitize_key); acf_fields keys match it case-insensitively.
        // Do NOT name it local_key: that meta key is owned by the plugin.
        ['key' => 'onpage_id', 'label' => 'On Page® ID', 'type' => 'text'],
        ['key' => 'pdf_link',  'label' => 'PDF',        'type' => 'file'],
        ['key' => 'cover',     'label' => 'Cover',      'type' => 'image'],
    ],
]]);
```

### The content

`cursor()` is the SDK's helper for pagination and memory control. It iterates one item at a time
instead of loading the whole collection. It first reads the ids, then loads the items in blocks of
100 (the default of the `request_size` parameter of the PHP SDK). This is SDK behaviour: see the
`onpage-php` docs, not this repo.

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

- `local_key`, `type` and `title` are required on insert (`400 invalid_param`). `type` must be a
  registered post type (`404 not_found`). A `title` map needs a non-empty value in at least one
  language that is active on the site: check your codes with `GET /languages` first.
- On update, `type` may be omitted. If you send it, it must match the post's current type: the
  plugin never retypes a post (`400 invalid_param`).
- If you send an explicit `id`, its `local_key` must not belong to another post of the same type
  (`409 duplicate_local_key`). Without `id` the key simply selects the post.
- `status` is optional. When sent, it must be a registered post status other than `trash`,
  `auto-draft` and `inherit`, otherwise `400 invalid_param`. A typo such as `publsh` is rejected,
  not stored.
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
                    // WooCommerce keys buyers' permissions by download id, so ids must stay
                    // stable. "id" is optional: without it the plugin reuses the id of the
                    // same file, or derives one from the file's source.
                    'downloads'         => [[
                        'id'      => 'datasheet',
                        'name'    => multiLang(fn ($l) => $product->val('datasheet_name', $l)),
                        'file'    => $product->file('datasheet')?->link(),
                        'refresh' => false,   // true re-downloads and replaces in place
                        // JSON boolean, default true: lists the file in the product page's
                        // documents section. Anything else is 400 invalid_param.
                        // GET /woocommerce/products returns it.
                        'public'  => true,
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

To build the variations on **global** attributes, create them and their terms first (steps 2 and
3 of the catalogue order), then use the taxonomy name as the key:
`'attributes' => ['pa_colour' => ['red', 'blue']]` on the parent and
`'attributes' => ['pa_colour' => 'red']` on the variation. The options are term IDs, slugs or
names; a term that does not exist is `404 not_found`.

Variation rules:

- `local_key` is required, plus either `parent_id` or `parent`.
- `attributes` is required when creating a variation. If you omit it on an update, the stored
  attributes stay untouched.
- The variation `status` is aliased:
  - `draft`, `pending` and `disabled` become `private`;
  - `enabled` becomes `publish`.
- Each attribute combination may exist only once per parent. A second variation with the same
  values (case-insensitive; an unset attribute counts as "any") is `409 duplicate_variation`.
- With a language map, each translated variation is found by `local_key` under its own
  translated parent, or created there.
- If a later parent save drops an option that a variation still uses, the plugin makes that
  variation `private` and holds its status in the `_onpage_held_status` meta. The next
  variation save with valid attributes puts the status back. Nothing is deleted.

> **`attributes` and `terms` are two different mechanisms.**
>
> - `attributes` sets the product's attributes, which drive variations. A key naming an existing
>   global attribute (e.g. `pa_colour`) sets that global attribute, with its terms as options.
>   The terms must exist first. Any other key creates a **custom** attribute. Global `pa_*`
>   attributes already on the product (added in the WooCommerce admin) are kept, unless you send
>   the same key.
> - `terms` links a product to the **terms of a global attribute**, e.g.
>   `"terms": {"pa_colour": [5101]}`. Terms drive taxonomy archives and filters.
>   `product_type`, `product_visibility` and any taxonomy not attached to products are refused
>   with `400 invalid_param`. Set the type through `props.product_type`.

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

An object is a language map when:

- it is non-empty;
- it is not a list;
- **all** its keys are language codes. A key is a language code when it is an active WPML
  language, or an ISO 639-1 code (two letters) with optional region or script subtags.

The match is case-insensitive, so `en`, `it`, `pt-br`, `pt_BR` and `zh-hans-cn` all qualify. A
language the site has not activated, such as `es` on an it/en site, still qualifies.
**A single key that is not a language code disqualifies the whole object.** The object is then
treated as a plain structured value.

Short keys that are not languages, such as `{"url": …, "alt": …}`, `{"lat": …, "lng": …}` or
`{"cta_url": …}`, are therefore plain values. An object is still read as a language map when
*all* its keys are language codes by the rule above: active WPML languages, two-letter ISO codes
or ISO codes with subtags such as `en-us`. So `{"id": …}` is a language map, because `id` is the
ISO code for Indonesian.

### How a language map is applied

- The map's keys are intersected with the site's **active** WPML languages.
- A language the site has not activated is **silently dropped**. This is by design: one unknown
  code must not hide the real translations.
- Sending a language map to a site without WPML is a hard `500 wpml_required`. The message names
  the exact field path.
- Call `GET /languages` before the import to check your codes against the site. It returns the
  active languages (default first) and `wpml_active`. A dropped code otherwise looks exactly like a
  missing translation. See [API.md](../API.md#get-languages).

### Filter language maps before you send them

The plugin never reports a language it dropped. The client must do the check. Do it once per
import:

1. Call `GET /languages`.
2. If `wpml_active` is `false`, send scalar values only. A language map returns
   `500 wpml_required`.
3. Otherwise, remove from every language map the keys that are not in `languages`. This includes
   the maps nested in `acf_fields`, `files`, `terms` and `props`.
4. If a map is left empty, leave the key out of the payload. On insert, a post needs a `title`, so
   skip the element or send a scalar title.
5. Log the codes you removed. This is how you find a language that is misspelled or not yet
   activated on the site.

What happens when you skip the filter:

| Payload (site with `it` and `en`) | Result |
| --- | --- |
| `{"title": {"it": "Sedia", "es": "Silla"}}` | `es` is dropped. `it` is written. |
| `{"title": {"es": "Silla"}}` on update | Nothing is written. Every language keeps its title. The response is `200`. |
| `{"title": {"es": "Silla"}}` on insert of `POST /posts` | `400 invalid_param`, `Parameter 'title' is required`. |
| `{"es": "Silla"}` in `acf_fields`, `files` or products, on update | Nothing is written for that field. |

The request still succeeds in most of these cases. Without the filter, a wrong code only shows up
as a translation that never appears.

Only language maps create translations. A payload with scalar values only creates a single object.
To create a translation with the same text, repeat the value: `{"en": "Chair", "it": "Chair"}`.
Maps in `terms`, `categories` and `tags` only choose the right term for each translation; they
never create one.

All translations of an object share one `local_key`. Send every language in a single element. The
plugin then:

- creates the WPML group;
- writes the base object in the site's default language. If the title (or `name`) is sent only
  per language and the default language is missing, the base object is created in the first
  language that has a title instead. No language the payload never sent is created with borrowed
  content;
- backfills missing languages on update;
- on update, writes only the languages each map contains. `{"title": {"en": "Red Chair"}}` leaves
  the Italian title as it is. Shared (non-map) values are written to every language;
- repairs translation slots left orphaned by a killed import.

Before the first multilingual import, the post types must be translatable in WPML (see
[§2](#2-prerequisites)).

Deletes follow the identifier you send:

- `DELETE /posts` and `DELETE /terms` with an ID delete that object only. With a `local_key`
  (`{"local_key": …}`, or `?keyfield=local_key`) they delete the whole translation group, since
  every translation carries the same key.

Some calls act on one object, not on its translation group:

- `POST /media/link` updates a single post. To set a file on every translation, send it in `files`
  on `POST /posts`, as a shared value or a language map.

---

## 11. Media and files

Every file slot, except the multipart upload, accepts **either** an existing attachment id **or** a
remote URL.

Inside `acf_fields` this covers only the ACF types `image` and `file`, including as `repeater`,
`group` and `flexible_content` sub-fields. Values of `gallery` fields are stored verbatim and never
imported: send a list of `attachment_id`s. To fill a gallery from URLs, upload or link each file
first (`POST /media`, or a URL in a `files` slot), then send the ids you got back:

```json
"acf_fields": { "photos": [812, 813, 814] }
```

The shape of every other ACF field type is in
[API.md: Value shapes by field type](../API.md#value-shapes-by-field-type).

### Attachment ids vs URLs

- An attachment id must be a **JSON integer**. `123` works; `"123"` does not.
- A numeric string falls through to the URL branch. It is then rejected with `400` by
  `POST /media/link`, `POST /posts` (`files`), `image`, `gallery`, `thumbnail` and
  `downloads[].file`.
- Inside `acf_fields` a string of digits **is** accepted as an attachment id. An `image`/`file`
  value that is neither an existing attachment id nor a valid URL is rejected with
  `400 input_invalid` (*Field '…' in acf_fields must be an existing attachment ID or a valid URL*).
  Inside a repeater or a group the message names the path, e.g. `gallery_rows[0][photo]` or
  `datasheet[photo]`.
- These places *do* accept numeric strings:
  - `POST /media`'s own `attachment_id` / `attachment_ids` form fields;
  - the id list in the `DELETE /media` body;
  - `post_id`, everywhere.
- A URL is validated with `esc_url_raw()` + `wp_http_validate_url()` before anything is fetched.

### Remote download checks

On top of the URL validation, every download follows these rules
(`RemoteMedia::downloadRemoteFile()`):

- **Public hosts only.** The host must resolve to public IP addresses only. Core leaves out ranges
  such as `169.254.0.0/16` (cloud metadata) and `100.64.0.0/10`; the plugin blocks them too.
  - A non-public host in the URL you send fails with `400 invalid_param` — *… host '…' does not
    resolve to a public address*. Nothing is fetched.
  - Every redirect is checked the same way. A redirect to a non-public host fails with
    `500 request_failed`.
  - The site's own host and hosts allowed through core's `http_request_host_is_external` filter
    are exempt.
- **IPv4 pinning.** With cURL, the IPv4 address that passed the check is pinned for the
  connection. A DNS answer that changes between the check and the request cannot reach an
  internal host.
- **Size cap.** A download is capped at 512 MB. A larger file fails with `413 file_too_large`.
  Site admins can change the cap with the `onpage_remote_media_max_bytes` filter (`0` disables
  it).

### Deduplication

The same remote file is never downloaded twice. The lookup order is:

1. the On Page® storage segment (`_onpage_file_token` meta), when the URL carries one;
2. the exact URL (`_onpage_source_url` meta).

Only On Page® URLs carry a segment: `https://storage.onpage.it/<segment>/<name>` and
`https://<subdomain>.onpage.it/api/storage/<segment>/<name>`, on `onpage.it` or its subdomains.
The segment identifies the content, so a file renamed on On Page® is still reused. Any other URL
is matched by exact URL only.

When a match is found:

- the existing attachment is reused (`action: "linked"`);
- if its file has vanished from disk, the stale row is dropped and the import self-heals
  (`action: "created"`).

### File types

Remote imports force-allow a fixed list of media, document, text and archive extensions, even on
sites that restrict uploads. Anything else (`html`, `js`, `exe`, …) follows WordPress' own upload
rules and usually fails with `500 request_failed` — *Sorry, you are not allowed to upload this
file type*. The full list is in [API.md: Remote file imports](../API.md#remote-file-imports).

### Refreshing

Two paths replace the bytes behind an existing attachment in place:

- `downloads[].refresh` on `POST /woocommerce/products`. It re-downloads the remote URL over the
  attachment matched by the lookup above.
- `POST /media` with `attachment_id` / `attachment_ids`. It uploads new bytes over the attachment
  you name and answers `action: "replaced"`.

Everywhere else, the same URL means the same file.

### SVG

Remote SVGs are sanitised on import, and on `refresh` too. The `image/svg+xml` MIME is enabled
only for the duration of that single sideload. A file that cannot be sanitised fails with
`500 request_failed`.

An `.svg` sent to `POST /media` is sanitised the same way before WordPress stores it. The MIME is
**not** enabled there: WordPress still refuses the upload (`500 upload_failed`) unless another plugin
allows SVG.

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

`files` must be a non-empty object keyed by field name. A list is rejected with
`400 invalid_param`. Every key must be an ACF field of the post's type, otherwise
`400 invalid_param` — *Media :: ACF field '…' not found for post type '…'*. The keys are checked
before anything is downloaded, so a typo imports no file.

### Timing

Media is downloaded **synchronously and serially inside the REST request**. A product with an
image, a gallery and two PDFs can spend tens of seconds on I/O alone. Size your batches against the
site's `max_execution_time` and `memory_limit`. Each file is also capped at 512 MB by default
(`413 file_too_large`, see [Remote download checks](#remote-download-checks)).

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

There is a trade-off: the field folder becomes a **prerequisite** of the client, and renaming
it breaks the code.

---

## 13. Error reference

The table lists every distinct code with its HTTP status.

About message formats:

- Most messages are prefixed with the entity.
- Batch endpoints that track the element add `Element <i>`.
- `POST /field-groups` and `POST /post-types` report an index only for a malformed element.
- `invalid_keyfield` carries no entity prefix, and neither does the `acf_error` raised while
  writing `acf_fields` (*Failed to assign ACF field '…'*). The `acf_error` of `POST /field-groups`,
  `POST /post-types` and `POST /taxonomies` does have one.

Parse the `code`, not the message shape.

| Code | HTTP | Typical cause | Fix |
| --- | --- | --- | --- |
| `onpage_auth_missing_token` | 401 | no `Authorization: Bearer` header reached PHP | check the client. On some Apache CGI/FastCGI setups the header needs a rewrite rule |
| `onpage_auth_invalid_token` | 403 | token regenerated, truncated or mis-copied | copy it again from the **On Page®** page |
| `onpage_auth_not_configured` | 500 | no token generated on the site | open the **On Page®** page and generate one |
| `rest_no_route` | 404 | a WordPress core code, not a plugin one. While ACF is inactive the plugin registers no routes at all | activate ACF. If you get WordPress' HTML 404 page instead of a JSON `code`, permalinks are on **Plain**: switch to **Post name** or call `?rest_route=/onpage/v1/…` |
| `invalid_param` | 400 | the body is not a JSON array; an element is not a non-empty object; a required key is missing; or a value has the wrong type/shape | read the message: it names the parameter and the element index |
| `invalid_keyfield` | 400 | `?keyfield=` outside the allowed set | use `id`, `local_key` or `id_or_post_type`, as documented per route |
| `missing_title` | 400 | `POST /field-groups` without `title` | send a non-empty `title` |
| `upload_failed` | 400 / 500 | rejected or unwritable upload | check MIME, size and filesystem permissions |
| `not_found` | 404 | one of two cases. (a) The object the call targets is missing: any DELETE without `?ignore`, the id/`local_key` lookups of the `GET /woocommerce/*` routes, and an explicit `id` on `POST /terms`, `POST /woocommerce/products` or `POST /woocommerce/attributes`. (b) Something the payload references does not exist: post type, taxonomy, parent, attribute, attachment | (a) send `?ignore`, or fix the id/`local_key`. (b) respect the call order in [§6](#6-call-order) |
| `input_invalid` | 404 | a term reference in the payload of `POST /posts` or `POST /woocommerce/products` resolves to nothing | sync terms before the content that references them |
| `input_invalid` | 400 | a DELETE body element of the wrong type on `field-groups`, `post-types`, `posts`, `taxonomies` or `terms` (on `DELETE /posts` and `DELETE /terms`, also an object element with an invalid `local_key` or `id`); a `files` value on `POST /posts`, or an `image`/`file` value in `acf_fields`, that is neither an existing attachment id nor a valid URL. The same `files` error on `POST /media/link` answers `invalid_param` | read the message: it names the element index or the field key |
| `no_post` | 404 | the target post does not exist: `GET /posts/{id}`, `GET /posts?id=`, or an explicit `id` on `POST /posts` | check `id` / `local_key` |
| `duplicate_title` | 409 | an **unkeyed** post of that post type has the exact same title and is outside this object's WPML translation group. A post that already carries a *different* `local_key` is a distinct On Page® element and never conflicts, so two elements with the same name import fine | give the existing unkeyed post the `local_key`, so the request updates it instead of inserting; or change the title |
| `duplicate_local_key` | 409 | the `local_key` is already held by a different object. On `POST /posts`, `POST /woocommerce/products`, `POST /terms` and the WooCommerce term routes this needs an explicit `id` in the payload. On `/woocommerce/attributes` and `/woocommerce/variant-products` it fires without an `id` too | if you sent `id`, drop it and let `local_key` resolve. Otherwise the key belongs to another object: re-key it, or clear the stale key |
| `duplicate_variation` | 409 | another variation of the same parent already has this attribute combination | give each variation a distinct combination, or delete the old variation |
| `ambiguous_local_key` | 409 | the same `local_key` exists on posts of more than one post type, or on terms of more than one taxonomy, and the request did not scope it | pass the type: `?type=` on `GET /posts/{id}` and `DELETE /posts`, or the `type` key in the `POST /posts` element. On `DELETE /terms`, pass `?taxonomy=` or the `taxonomy` key in the element |
| `parent_mismatch` | 409 | `parent_id` and `parent` point at different parents | send only one of the two |
| `wpml_required` | 500 | language map sent to a site without WPML | install WPML, or send scalars |
| `woocommerce_required` | 500 | `/woocommerce/*` with WooCommerce inactive | activate WooCommerce |
| `acf_version_unsupported` | 500 | the active ACF is older than 6.1. Checked after the token, so it needs a valid one | ask the site admin to update ACF |
| `wpml_error` / `acf_error` | 500 | WPML or ACF refused a write | check the message. Usually a field-group or language misconfiguration. *Unable to resolve translation group* on a post or product means its post type is not translatable in WPML (see [§2](#2-prerequisites)) |
| `file_too_large` | 413 | a remote file is larger than the download cap (512 MB by default, filter `onpage_remote_media_max_bytes`) | shrink the file, or ask the site admin to raise the cap |
| `request_failed` | 500 | a WordPress query or write failed; a remote file could not be downloaded, sanitised or imported (a refused file type or a redirect to a non-public host included); or any unexpected exception, caught by the router and returned with its original message | check the message and the site error log. An unexpected exception is a bug: report it |
| `migration_failed` | 500 | `POST /migration` could not read or rewrite meta | check DB permissions, then re-run. It is idempotent |
| `delete_failed` | 500 | a DELETE whose underlying WordPress/ACF delete call failed (field group, post type, taxonomy, term, attachment, product, variation, attribute), or `DELETE /indexes` failed to remove meta. For terms, the WordPress error message is appended | read the message: it names the entity and id. Check filesystem/DB permissions and anything hooked on the delete |

Two sources can answer with a code outside this table:

- WordPress core itself, before the plugin runs. `rest_no_route` above is one example.
- `POST /woocommerce/attributes`. If `wc_create_attribute()` / `wc_update_attribute()` fails,
  the error is re-thrown with WooCommerce's own error code and HTTP status (500 when WooCommerce
  sets none). Treat an unknown `code` there as a WooCommerce-side rejection and read the message.

---

## 14. Things that will bite you

- **`POST /field-groups` deletes fields you omit from `fields`.** A group update that sends
  `fields` replaces the field set, so always send every field of the group. Leaving `fields` out
  of the payload keeps the existing fields.
- **`fields[].key` is the ACF field *name*.** It is what you put in `acf_fields`. The plugin
  manages the internal ACF `field_...` key and preserves it across updates.
- **`attributes`, `gallery` and `downloads` are replaced wholesale**, not merged. `null` or `[]`
  clears them. For `attributes`, a non-empty object keeps the global `pa_*` attributes it does
  not name, but `null` or `{}` removes every attribute, global ones included.
- **`props.product_type: "simple"` on a variable product deletes its variations.** WooCommerce
  removes them for good when the type changes from `variable` to `simple`. Send `product_type`
  only when you mean to set or change it.
- **`props` has a fixed key list.** Unrecognised keys are silently ignored. A typo in
  `regular_price` fails quietly, with no error. A recognised key is checked, though: a value that
  is not a scalar or `null`, or one WooCommerce rejects (a duplicate SKU, an unknown enum value
  such as a bad `tax_status` or `catalog_visibility`), fails with `400 invalid_param`. Props accept language maps on
  products and on variations alike.
- **Slugs are stable by design.** A product slug is written on insert and then left alone. To
  change it, send `update_slug: true` (a real JSON boolean). Posts have no `slug` key at all:
  WordPress generates the slug from the title and never changes it after the post is published.
- **Absent, present, `null`.** On products, a key left out of the payload leaves the value as it
  is. A key that is present replaces the value entirely. `null` or an empty list clears it. The
  exceptions are listed in [API.md: Missing, present and null keys](../API.md#missing-present-and-null-keys).
  The main one: `null` on an enum or boolean prop, such as `stock_status` or `featured`, leaves
  it as it is. Send the value you want instead.
- **Unknown keys are ignored.** A misspelled top-level key on `POST /woocommerce/products` or
  `POST /woocommerce/variant-products` is dropped with no error, and the call answers `200`.
  Variations also ignore `terms`, `gallery`, `slug` and `downloads`.
- **WooCommerce term references are `local_key` only.** Slugs work in `Post` payloads and nowhere
  else.
- **`parent: 0` is rejected on `product_cat` and `product_brand`** (`400 invalid_param`). There
  `parent` is a `local_key`, and `0` is not a valid key. On `product_tag`, `pa_*` and `POST /terms`
  `parent` is a raw WordPress term id, so `0` does mean top level. `null`, or omitting the key,
  means top level everywhere. Prefer that. On `POST /terms`, anything other than a term ID, `0` or
  `null` is `400 invalid_param`, and a term ID that does not exist is `404 not_found`.
- **An inactive language is dropped with no error.** A language map key that is not active on the
  site is ignored, and the call answers `200`. Filter your maps with `GET /languages` first (see
  [§10](#filter-language-maps-before-you-send-them)).
- **Deletions do not propagate.** An item removed from On Page® stays published until the
  client calls the matching `DELETE`.
- **The trash does not free a `local_key`.** A post trashed in the admin still holds its key.
  The next import restores it rather than creating a new one. To drop it for good, call
  `DELETE /posts`, which deletes permanently.
- **A term slug owned by another element is not taken.** If the `slug` you send already belongs
  to a different element's term, your term is updated but keeps its current slug, with no error.
  Check the slug in the `GET` response if it matters.
- **The plugin has no dry-run.** Test against the Docker environment in [CONTRIBUTING.md](../../CONTRIBUTING.md)
  before pointing a client at a live site.

---

## 15. Local environment

`docker-compose.yml` at the repo root brings up MySQL, WordPress and Adminer:

- WordPress on <http://localhost:8040>;
- Adminer on <http://localhost:8041>.

Both ports are bound to `127.0.0.1` only, so they are not reachable from other machines.

Control it with `./start`, `./stop` and `./restart`. The scripts work from any directory.

Setup steps:

1. Install ACF (and WooCommerce/WPML if in scope).
2. Set permalinks to **Post name**.
3. Generate a token from the **On Page®** menu.
