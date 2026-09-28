# Architecture

This document describes the plugin's **software architecture** and explains the reasoning behind the
main decisions. It covers **how the code is organized and why**.

It is not:

- a usage guide — see [user/guide.md](../user/guide.md) and [API.md](../API.md);
- a performance analysis — see [notes/woocommerce-performance-2026-09.md](notes/woocommerce-performance-2026-09.md).

---

## 1. Context and goal

The plugin is the WordPress side of an **On Page® → WordPress** sync. It receives structured data from
a *client*, any program that calls the plugin's REST API (see
[integration-guide.md](integration-guide.md)). The plugin never pulls data. It turns what it
receives into:

- WordPress content: posts/CPTs, taxonomies, terms, ACF field groups;
- WooCommerce entities: products, variations, categories, tags, brands, global attributes and their terms.

Multilingual support via **WPML** is optional.

The caller is a machine, not a person. The plugin is therefore a **backend**, not a UI.
This leads to the first design principle: expose an **idempotent REST contract**. Requests can be
re-run without duplicating data, and an external identifier (`local_key`) drives the matching.

---

## 2. Forces and constraints

The architecture responds to a set of non-negotiable constraints:

- **Shared WordPress/PHP runtime.** The code runs inside the WordPress lifecycle. There is no
  dedicated process, no framework, no build step, no DI container and no ORM.
- **No external dependencies.** There is no `composer.json`. Classes are included manually in
  [plugin.php](../../plugin.php). A small dependency surface avoids version conflicts with other plugins
  in the same runtime.
- **Three "opaque", mismatched integrations.** ACF, WooCommerce and WPML use different data models
  (postmeta vs. CRUD objects vs. `icl_translations`). They must be orchestrated, not just called.
  Most of the plugin's complexity lives here.
- **Optional WPML.** The same code must work with and without WPML. Multilingual support is a
  cross-cutting dimension, not a separate mode.
- **Bulk imports.** Writes arrive in batches of hundreds or thousands of items. The contract and the
  error handling must cope with partial state.

---

## 3. Overview: a layered architecture

The plugin uses a classic **layered design**, with responsibility growing from top to bottom. A
request flows through the layers downwards:

```
HTTP (WordPress REST API)
  │
  ▼
Router            ── src/Router.php        registers routes; adapter over register_rest_route
  │
  ▼
Middleware        ── src/Middlewares/      permission_callback (Bearer token auth)
  │
  ▼
Controller        ── src/Controllers/      thin: parses the batch, loops, maps to HTTP
  │
  ▼
Service           ── src/Services/         business logic (upsert, WPML, ACF, WooCommerce)
  │
  ▼
Repository / WP   ── src/Services/*Repository.php + WordPress/WooCommerce/ACF/WPML APIs
```

Dependencies point one way only. Controllers know Services. Services know Repositories and the
platform APIs. **Never the reverse.** This keeps Controllers trivial and easy to verify by reading,
and concentrates complexity in a single layer (Service).

### 3.1 Why this layering

- **HTTP is separate from the domain.** The Controller speaks HTTP (status codes, body shape,
  `WP_Error`). The Service speaks domain (products, terms, languages). Changing the REST contract does
  not touch the upsert logic, and vice versa.
- **Uniformity.** Every write endpoint has the same shape (see section 6). This lowers cognitive load
  and makes it obvious where a change belongs.

---

## 4. Key architectural decisions

### 4.1 Custom router on top of the WordPress REST API

Routes are not scattered across dozens of `register_rest_route()` calls. They are all declared in one
file, [src/routes.php](../../src/routes.php), through a small fluent [Router](../../src/Router.php):

```php
$router->bind('POST', '/woocommerce/products', [ProductController::class, 'save'], AuthMiddleware::class);
```

**Why:**

- **One routing table.** It reads at a glance as an index of the whole API. It is the living
  documentation of the contract.
- **Middleware is an explicit parameter.** The router maps the middleware onto the WordPress
  `permission_callback` ([Router.php:96](../../src/Router.php#L96) and
  [Router.php:109-117](../../src/Router.php#L109-L117)). Auth is declared on the route, so it cannot
  be forgotten.
- **Centralized dispatch and error handling.** `Router::dispatch()`
  ([Router.php:41-78](../../src/Router.php#L41-L78)) converts every exception that reaches it into a
  `WP_Error`. Services throw domain exceptions without knowing about HTTP. The translation into a
  response happens in one place. A few Services catch an `HttpException` locally, for a narrow
  reason (see section 6).
- **`{param}` placeholders.** They are converted into named capture groups
  ([Router.php:30-33](../../src/Router.php#L30-L33)). This gives a familiar routing syntax without the
  verbosity of native WordPress.

The router is deliberately minimal: no unused features (route groups, multiple middleware, etc.). The
abstraction costs about 100 lines, and the readability of `routes.php` pays for it.

### 4.2 Thin Controllers, rich Services

Controllers do **only** this:

1. validate the shape of the request body (see section 4.12);
2. load the ACF field-type map once per request, when the endpoint writes ACF fields (endpoints
   that do not preload it, such as `POST /woocommerce/variant-products`, load it lazily on the first
   lookup in `Acf::getFieldType()`);
3. iterate over the JSON batch;
4. delegate each item to the Service;
5. package the response.

The [Category](../../src/Controllers/WooCommerce/Category.php) controller is a typical example: about 60
lines, no domain logic.

**Why:** the hard logic (WPML resolution, idempotent upsert, media sideloading, ACF) is shared by many
endpoints. Keeping it in Services enables **reuse**. For example:

- `Term::save` serves `/terms`, categories, tags, brands and attribute terms (categories, tags and
  attribute terms through the `WooCommerce\Term::save` wrapper, brands through `WooCommerce\Brand::save`);
- `Acf::updateFieldValue` serves posts, products, variations and terms.

If this logic lived in Controllers, it would be duplicated 4–6 times.

### 4.3 Errors: domain exceptions inside, `WP_Error` at the edge

Error handling has **two stages**:

1. In the domain, code throws `onpage_http_exception($msg, $status, $code)`
   ([helpers.php:142](../../src/helpers.php#L142)). This creates an
   [HttpException](../../src/Exceptions/HttpException.php) carrying an HTTP status and an error code.
2. At the edge, `Router::dispatch` turns it into the `WP_Error` that WordPress serializes into the
   response, with the exception's status and error code.

**Why:**

- Services do not have to pass error values up the whole call chain. That would be noisy and easy to
  forget. They just throw.
- The uniform message format `Service :: Element {i} :: ...` points every batch error straight to the
  item that caused it.
- Any other `Throwable` (a WooCommerce `WC_Data_Exception`, a `TypeError`, ...) is caught too
  ([Router.php:67-75](../../src/Router.php#L67-L75)) and becomes `500 request_failed` with the
  exception's message. Without this, WordPress would answer with its HTML fatal-error page. The
  client always gets the same JSON error shape, and the message still names the real failure.

### 4.4 Authentication: Bearer token + admin UI, no token endpoint

Auth is a [middleware](../../src/Middlewares/Auth.php) that delegates to the
[Auth service](../../src/Services/Auth.php). It compares the request's Bearer token with the one stored
in `wp_options` (`onpage_auth_token`), in constant time (`hash_equals`).

The token can be generated **only** from the admin page ([UI.php](../../src/Views/UI.php)). That page
requires the `manage_options` capability and is protected by a CSRF nonce.

**Why:**

- **Operational simplicity.** A machine-to-machine client does not do an OAuth handshake. A static
  Bearer token over HTTPS is the minimum that is sufficient.
- **No REST attack surface on the secret.** There is no endpoint to read or rotate the token. It is
  managed only in the admin UI, which reduces risk.
- **Explicit fail-safe.** Each failure has a distinct, diagnosable status:

  | Situation | Response |
  |---|---|
  | Token not configured | `500 onpage_auth_not_configured` (never a silent pass-through) |
  | Token missing from request | `401 onpage_auth_missing_token` |
  | Token wrong | `403 onpage_auth_invalid_token` |

### 4.5 The batch as contract, idempotency via `local_key`

Every batch endpoint accepts a **JSON array** and processes one item at a time. The `/media`
endpoints are the exception: uploads are multipart, and `POST /media/link` takes one object. The core
of the whole design is that writes are **idempotent upserts** keyed by `local_key`.

`local_key` is the external On Page® identifier. It is a positive integer **or a non-empty string**.
Integers and numeric strings are equivalent, because WordPress meta values are strings anyway.

**Why idempotency:** the sync must be **re-runnable** (retries, partial re-imports, resuming after an
error) without creating duplicates. `local_key` decouples the On Page® identity from the WordPress ID.
The caller does not know the WordPress ID, and it is not stable across environments (e.g.
dev/staging/production).

**Storage convention.** WordPress has no single place for metadata, so the storage depends on the
entity type:

| Entity type | `local_key` storage | Meta key |
|---|---|---|
| Post / CPT | `wp_postmeta` | `onpage_local_key` |
| WooCommerce products and variations | `wp_postmeta` | `onpage_local_key` |
| Terms, categories, tags, brands, attribute terms | `wp_termmeta` | `onpage_local_key` |
| WooCommerce global attributes | `wp_options` | `onpage_wc_attribute_local_key_{id}` |

`local_key` is stored as **technical meta**, not as an ACF field. Persistence must work even when the
field group defines no dedicated field.

Earlier on, the post key was written through an implicit ACF field (meta `local_key` /
`_local_key`). That field has been removed. `POST /migration` brings existing installs in line; see
[internals.md](internals.md) for the full mapping.

**Accepted trade-off: partial state.** If an item fails, the `HttpException` stops the batch. Items
already written **stay written**. Only `POST /posts` and `POST /woocommerce/products` roll back
within one item: a failed insert deletes the source post or product and every translation it
created ([Product.php:895-939](../../src/Services/WooCommerce/Product.php#L895-L939) for products).
This is deliberate:

- There are no SQL transactions across APIs. WooCommerce, ACF and WPML write to different tables
  through their own hooks, so a real rollback is not realistic.
- Idempotency makes partial state acceptable: just re-send the batch.

### 4.6 The `shared` / `translated` model for WPML

WPML is treated as a **cross-cutting dimension**, not as a separate code path.

Every translatable value (`name`, `title`, `content`, `slug`, ACF values, …) can arrive either as:

- a scalar — shared across languages; or
- a map `{ "<lang>": <value> }` — per-language values.

Services **split** the payload into two buckets:

- `shared` — valid for all languages;
- `translated` — per-language overrides.

They then resolve the final value for each language through a fallback chain.

**Why:**

- **One mental model, with or without WPML.** Without WPML, the language list is empty and the
  "translated" branch simply never runs. There are no duplicated `if (wpml) { ... } else { ... }`
  blocks.
- **Fail explicitly.** If the payload is multilingual but WPML is not active, the response is
  `500 wpml_required`. The plugin does not silently degrade, which would produce ambiguous data.
- **Domain rules stay isolated.** Rules such as "a WooCommerce SKU is globally unique, so it applies
  only to the source language, not to translations" (see [internals.md](internals.md)) live in the Services,
  behind the shared/translated model.

The WPML helpers ([helpers.php:43-129](../../src/helpers.php#L43-L129)) are **memoized per request**.
`onpage_is_wpml_active`, `onpage_get_wpml_default_language` and `onpage_get_wpml_languages` are
called dozens of times per item, and the set of languages does not change during an import.
`onpage_get_wpml_current_language` is not memoized, because the current language switches during a
multilingual write. This is the cheapest, highest-impact
memoization in the plugin (see the WPML memoization notes in [the WooCommerce performance analysis](notes/woocommerce-performance-2026-09.md)).

### 4.7 WooCommerce as a specialization of WordPress primitives

The WooCommerce Services do not start from scratch:

- **Categories, tags, brands and attribute terms are terms.** They reuse the generic
  `Term::save` (`TermService`), through thin WooCommerce wrappers.
- **Products are posts**, plus WooCommerce CRUD objects (`WC_Product`, `WC_Product_Variation`) for
  prices, SKUs, attributes and downloads.

**Why:** this maximizes reuse of the upsert/WPML/ACF logic already written for generic terms and
posts. It also keeps `local_key` semantics consistent between the "core" and "commerce" sides.
WooCommerce-specific features (variation sync, lookup tables, download sideloading) are layered *on
top*; they do not replace anything.

### 4.8 Centralized ACF: a single write path for fields

All ACF field writes go through `Acf::updateFieldValue`, shared by posts, products, variations and
terms. In one place it handles:

- skipping `tab` fields;
- converting URLs to `attachment_id` for `image`/`file` fields, and rejecting values that are
  neither an existing attachment nor a URL;
- treating `0` / `"0"` on an `image`/`file` field as "clear the field", as ACF stores an empty
  media field;
- recursive normalization of `repeater`, `group` and `flexible_content` fields (a flexible content
  value must be a list of rows that name a known layout in `acf_fc_layout`);
- matching field names case-insensitively (exact name first, then its `sanitize_key()` form).

The per-language resolution happens before, in one place too: `MultiLang::resolveFields()`.

**Why:** ACF logic is subtle and full of edge cases. Keeping it in one place guarantees that every
endpoint behaves **identically** on repeaters, images and language maps, and that a fix applies
everywhere. The field-type map is loaded **once per request** (`Acf::loadFieldTypeMap`), not once
per field. Most Controllers preload it; otherwise `Acf::getFieldType()` loads it on first use.

### 4.9 Remote media isolated in `RemoteMedia`

All remote media import goes through `RemoteMedia`: product/variation images, ACF `image`/`file`
fields, downloads, category/brand thumbnails, `POST /media/link`. The service deduplicates
(`findAttachmentBySourceUrl`: the On Page® storage token first, then the exact source URL),
downloads the file and creates the attachment. It is also the only place that widens the upload
allowlist (a fixed list of media and document extensions). SVG files are sanitized (`Svg`) both
here and in `Media::handleUpload`, for multipart uploads to `/media`
([Media.php:617-620](../../src/Services/Media.php#L617-L620)). See
[internals.md](internals.md#media-and-remotemedia).

`RemoteMedia::downloadRemoteFile` guards every download:

- **Public addresses only.** The host must resolve to public addresses only
  (`findNonPublicHost`). The site's own host and hosts allowed through
  `http_request_host_is_external` are exempt. A blocked URL is `400 invalid_param`. Each redirect is
  checked the same way; a blocked redirect fails the download as `500 request_failed`.
- **Address pinning.** With cURL, the IPv4 address just checked is pinned for the connection
  (`CURLOPT_RESOLVE`). A DNS answer that changes between the check and the request cannot reach an
  internal host.
- **Size cap.** A download is capped at 512 MB by default. Sites can change the cap with the
  `onpage_remote_media_max_bytes` filter (`0` or less disables it). A larger file is
  `413 file_too_large`.

**Why:** network I/O is the most fragile and expensive part of an import. Isolating it behind one
service:

- makes download deduplication possible;
- leaves room to move to **asynchronous** import (Action Scheduler) without touching the domain
  Services.

Downloads are currently synchronous and are the main known bottleneck (see the media download notes
in [the WooCommerce performance analysis](notes/woocommerce-performance-2026-09.md)).

### 4.10 Repositories for critical lookups

Term `local_key` and slug lookups use direct `$wpdb` queries (`TermRepository`) instead of
`get_terms(meta_query)`. Post lookups (`PostRepository`) still use `get_posts()` with a
`meta_query`, but with `suppress_filters => true` so WPML does not scope them to one language.

**Why:**

- During an import, every write invalidates the term query caches.
- WPML's filters are expensive when repeated thousands of times, and they scope results to the
  current language.
- `local_key` is the same in every language, so its lookup must be language-independent.

This is a targeted optimization where profiling showed the cost. It is not a general bypass of
WordPress. Moving post lookups to `$wpdb` too is still open (see
[the WooCommerce performance analysis](notes/woocommerce-performance-2026-09.md)).

### 4.11 Bootstrap with manual includes

[plugin.php](../../plugin.php) includes files in an **explicit dependency order**, with no autoloader.
Every include uses `require_once __DIR__ . '/...'`, so it does not depend on the current directory.
Routes are registered only when ACF is active: `src/routes.php` is loaded on `plugins_loaded` if
`acf_get_field_groups()` exists, and an admin notice is shown otherwise.

[Env](../../src/Env.php) reads the `.env` file. It is **test-only**: `plugin.php` never loads it, and
the tests in `src/Tests/` require it themselves. A stray `.env` in the plugin folder is therefore
never read in production.

**Why:** without Composer there is no PSR-4 autoloading. Manual include order is verbose, but it
removes any dependency on build tooling and makes the dependency graph **readable in one file**. This
follows from the "no external dependencies" constraint in section 2.

### 4.12 Request body validation in the Controllers

Controllers check the shape of the body before any Service runs, with the helpers in
[Input.php](../../src/Services/Input.php):

- `Input::requireJsonList()`: the body of a batch endpoint must be a JSON array, else
  `400 invalid_param`. `{}` decodes to the same `[]` as an empty list, so the raw body is checked
  too and an empty object is rejected.
- `Input::requireObjectElement()`: each `POST` element must be a non-empty JSON object, else
  `400 invalid_param`.
- `Input::strictPositiveInt()`: an ID must be a positive integer or a digit-only string. A cast
  would turn `"12abc"` into 12 and any array into 1, and delete something nobody asked for.

`DELETE /media` is the exception: `Media::deleteFromRequest()` calls `Input::requireJsonList()`
from the Service.

**Why:** a malformed body used to reach the Services and end as a PHP warning with `200 []`, or as a
`500`. Checking the shape once, at the edge, gives every endpoint the same `400` and lets the
Services assume an array of objects.

### 4.13 Global helpers carry the `onpage_` prefix

The few global functions in [helpers.php](../../src/helpers.php) (`onpage_http_exception`,
`onpage_is_wpml_active`, `onpage_should_ignore_missing`, `onpage_set_pagination_headers`, ...) all
start with `onpage_`. Everything else lives in the `OnPage\` namespace.

**Why:** global functions share one namespace with WordPress core and every other plugin. A generic
name such as `httpException()` or `env()` can collide with another plugin and cause a fatal error on
activation.

One helper also fixes an output convention. Object-shaped output, such as `translations` and
`acf_fields` in the read endpoints, always goes through `onpage_json_map()`
([helpers.php:147-157](../../src/helpers.php#L147-L157)). PHP encodes an empty array as `[]`, and
ACF's `get_fields()` returns `false` when there are no values. The helper turns both into `{}`, so a
client always gets a JSON object.

### 4.14 The release archive holds only runtime files

[.gitattributes](../../.gitattributes) marks everything that is not needed at runtime as
`export-ignore`: the tests and `src/Env.php`, `bin/`, the Docker environment, `config/`, the
`start`/`stop`/`restart` scripts, `.env.example` and the repository tooling (`.gitignore`,
`.gitattributes`). `git archive`, and the source archives GitHub attaches to a release, leave them
out. The archive keeps the plugin code, the README, the LICENSE and the documents the README links
to, including `AGENTS.md`, which `CONTRIBUTING.md` links to. See [RELEASE.md](../../RELEASE.md).

---

## 5. Request lifecycle (example: `POST /woocommerce/products`)

1. WordPress invokes the route registered by `Router::resolve()` on `rest_api_init`.
2. `permission_callback` → `AuthMiddleware::handle` → `Auth::check` validates the Bearer token.
3. `callback` → `Router::dispatch` instantiates `ProductController` and calls `save`.
4. The Controller checks that the body is a JSON array (`Input::requireJsonList`), loads the ACF
   field-type map once, then iterates over the array. Each element must be a JSON object
   (`Input::requireObjectElement`).
5. For each item it calls `Product::save`, which:
   - normalizes the payload, splits shared/translated values and resolves WPML languages;
   - looks up the existing entity by `local_key` and decides between insert and update (upsert);
   - persists the `WC_Product`, ACF fields, terms (categories/tags/brands) and remote media;
   - propagates to WPML translations, applying domain rules (e.g. SKU only on the source language).
6. On an item error: `HttpException` → `Router::dispatch` converts it to `WP_Error`. The batch stops;
   earlier items stay written.
7. On success: array of IDs → `WP_REST_Response` 200.

---

## 6. Invariants and cross-cutting conventions

These rules make the system predictable. Breaking one is almost always a bug.

- **Dependency direction:** Controller → Service → Repository/platform. Never upwards.
- **One place per cross-cutting concern:**

  | Concern | Where |
  |---|---|
  | Auth | Middleware |
  | Body shape | `Input::requireJsonList` / `Input::requireObjectElement` in the Controllers (the `DELETE /media` Service calls `Input::requireJsonList` itself) |
  | Errors | `Router::dispatch` |
  | Object-shaped output | `onpage_json_map` |
  | ACF writes | `Acf::updateFieldValue` |
  | Per-language resolution | `MultiLang::resolveFields` |
  | Remote media | `RemoteMedia` |
  | Languages | Memoized WPML helpers |
  | Missing items on delete | `onpage_should_ignore_missing` (`?ignore`) |

- **Errors reach `Router::dispatch`.** A Service catches an `HttpException` only for a narrow,
  local reason, and rethrows everything else. `WooCommerce\Brand::ensureTaxonomy` ignores
  `already_exists` when it creates the brand taxonomy
  ([Brand.php:111](../../src/Services/WooCommerce/Brand.php#L111)).
  `WooCommerce\VariantProduct` rethrows an `HttpException` as is and wraps any other failure of a
  variation save as `500 request_failed`
  ([VariantProduct.php:724-727](../../src/Services/WooCommerce/VariantProduct.php#L724-L727)).
- **Uniform write endpoints:** body is an array, upsert by `local_key`, errors prefixed with
  `Service :: Element {i}`, response is an array of IDs.
- **Global names are prefixed:** every global function starts with `onpage_`.
- **`local_key` is the reconciliation key.** It is stored as technical meta. The caller is never asked
  for a WordPress ID as primary identity.
- **Transparent WPML:** the same code runs with and without WPML. A multilingual payload without WPML
  is an error, never a silent degradation.

---

## 7. Accepted trade-offs and non-goals

Deliberate choices and their rationale:

- **No transactions; partial state on error.** Transactions cannot be done reliably across
  WooCommerce/ACF/WPML. Idempotency mitigates this (section 4.5). *Non-goal:* batch atomicity.
- **Synchronous media I/O.** It is simple and gives a clear contract (the response already contains
  the `attachment_id`s). It is also the dominant bottleneck in media-heavy imports. Isolating it in
  `RemoteMedia` (section 4.9) keeps an async path open. *Non-goal for now:* background import.
- **Minimal router.** No advanced routing features; only what is needed is added.
- **No ORM, no generic DB abstraction.** The plugin uses WordPress APIs. Direct queries appear in
  the Repositories for critical lookups (section 4.10), and in bulk maintenance operations
  (`POST /migration`, `DELETE /indexes`, WPML orphan cleanup).
- **Imperative validation, not a declarative schema.** Apart from the body shape checked in the
  Controllers (section 4.12), validation is spread across the Services as chains of checks. It is repetitive and walks the payload several times (see the validation notes in
  [the WooCommerce performance analysis](notes/woocommerce-performance-2026-09.md)). A normalized, single-pass DTO is the most natural refactor if validation cost
  ever becomes dominant.

---

## 8. Summary

The architecture is a **layered design** (Router → Middleware → Controller → Service → Repository)
built around one goal: a **batch, idempotent, multilingual REST contract** on top of a platform —
WordPress + ACF + WooCommerce + WPML — that was not designed for machine-to-machine sync.

The recurring decisions follow two principles:

- **Keep each concern in one place** (auth, errors, ACF, media, languages).
- **Decouple the external identity (`local_key`) from the WordPress identity**, so the sync is
  repeatable and integration complexity stays inside the Service layer.

The open trade-offs (partial state, synchronous I/O) are deliberate. Each sits behind a boundary that
allows it to evolve without rewriting the domain.
