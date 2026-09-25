# On Page® Plugin Architecture

This document describes the plugin's **software architecture** and explains the reasoning behind the
main decisions. It covers **how the code is organized and why**.

It is not:

- a usage guide — see [user/guide.md](../user/guide.md) and [API.md](../API.md);
- a performance analysis — see [woocommerce-performance.md](woocommerce-performance.md).

---

## 1. Context and goal

The plugin is the WordPress side of an **On Page® → WordPress** sync. It receives structured data from
the On Page® *Exporter* service and turns it into:

- WordPress content: posts/CPTs, taxonomies, terms, ACF field groups;
- WooCommerce entities: products, variations, categories, tags, brands, global attributes and their terms.

Multilingual support via **WPML** is optional.

The caller is a machine, not a person. The plugin is therefore an **integration backend**, not a UI.
This leads to the first design principle: expose an **idempotent REST contract**. Requests can be
re-run without duplicating data, and an external identifier (`local_key`) drives the matching.

---

## 2. Forces and constraints

The architecture responds to a set of non-negotiable constraints:

- **Shared WordPress/PHP runtime.** The code runs inside the WordPress lifecycle. There is no
  dedicated process, no framework, no build step, no DI container and no ORM.
- **No external dependencies.** There is no `composer.json`. Classes are included manually in
  [onpage.php](../../onpage.php). A small dependency surface avoids version conflicts with other plugins
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
file, [routes.php](../../routes.php), through a small fluent [Router](../../src/Router.php):

```php
$router->bind('POST', '/woocommerce/products', [ProductController::class, 'save'], AuthMiddleware::class);
```

**Why:**

- **One routing table.** It reads at a glance as an index of the whole API. It is the living
  documentation of the contract.
- **Middleware is an explicit parameter.** The router maps the middleware onto the WordPress
  `permission_callback` ([Router.php:108](../../src/Router.php#L108)). Auth is declared on the route, so
  it cannot be forgotten.
- **Centralized dispatch and error handling.** `Router::dispatch()`
  ([Router.php:41-72](../../src/Router.php#L41-L72)) is the **only** place that catches `HttpException`
  and converts it to `WP_Error`. Services throw domain exceptions without knowing about HTTP. The
  translation into a response happens in one place.
- **`{param}` placeholders.** They are converted into named capture groups
  ([Router.php:30-33](../../src/Router.php#L30-L33)). This gives a familiar routing syntax without the
  verbosity of native WordPress.

The router is deliberately minimal: no unused features (route groups, multiple middleware, etc.). The
abstraction costs about 100 lines, and the readability of `routes.php` pays for it.

### 4.2 Thin Controllers, rich Services

Controllers do **only** this:

1. load the ACF field-type map once per request;
2. iterate over the JSON batch;
3. delegate each item to the Service;
4. package the response.

The [Category](../../src/Controllers/WooCommerce/Category.php) controller is a typical example: about 60
lines, no domain logic.

**Why:** the hard logic (WPML resolution, idempotent upsert, media sideloading, ACF) is shared by many
endpoints. Keeping it in Services enables **reuse**. For example:

- `WooCommerce\Term::save` serves categories, tags, brands and attribute terms;
- `Acf::updateFieldValue` serves posts, products, variations and terms.

If this logic lived in Controllers, it would be duplicated 4–6 times.

### 4.3 Errors: domain exceptions inside, `WP_Error` at the edge

Error handling has **two stages**:

1. In the domain, code throws `httpException($msg, $status, $code)`
   ([helpers.php:168](../../src/helpers.php#L168)). This creates an
   [HttpException](../../src/Exceptions/HttpException.php) carrying an HTTP status and an error code.
2. At the edge, `Router::dispatch` turns it into the `WP_Error` that WordPress serializes into the
   response.

**Why:**

- Services do not have to pass error values up the whole call chain. That would be noisy and easy to
  forget. They just throw.
- The uniform message format `Service :: Element {i} :: ...` points every batch error straight to the
  item that caused it.
- Exceptions that are not `HttpException` are re-thrown
  ([Router.php:67-69](../../src/Router.php#L67-L69)). Real bugs surface as 500s with a stack trace
  instead of being masked.

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
  | Token not configured | `500` (never a silent pass-through) |
  | Token missing from request | `401` |
  | Token wrong | `403` |

### 4.5 The batch as contract, idempotency via `local_key`

Every write endpoint accepts a **JSON array** and processes one item at a time. The core of the whole
design is that writes are **idempotent upserts** keyed by `local_key`.

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
already written **stay written**. This is deliberate:

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

The WPML helpers ([helpers.php:75-155](../../src/helpers.php#L75-L155)) are **memoized per request**.
`isWpmlActive`, `getWpmlDefaultLanguage` and `getWpmlLanguages` are called dozens of times per item,
and the set of languages does not change during an import. This is the cheapest, highest-impact
memoization in the plugin (see the WPML memoization notes in [woocommerce-performance.md](woocommerce-performance.md)).

### 4.7 WooCommerce as a specialization of WordPress primitives

The WooCommerce Services do not start from scratch:

- **Categories, tags, brands and attribute terms are terms.** They reuse `WooCommerce\Term::save`,
  which is itself built on the generic `TermService`.
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
- converting URLs to `attachment_id` for `image`/`file` fields;
- recursive normalization of `repeater`/`group` fields;
- the per-field language map.

**Why:** ACF logic is subtle and full of edge cases. Keeping it in one place guarantees that every
endpoint behaves **identically** on repeaters, images and language maps, and that a fix applies
everywhere. The Controller loads the field-type map **once per request** (`Acf::loadFieldTypeMap`),
not once per field.

### 4.9 Remote media isolated in `RemoteMedia`

All media import goes through `RemoteMedia`: product/variation images, ACF `image`/`file` fields,
downloads, category/brand thumbnails. The service downloads the file, deduplicates by source URL
(`findAttachmentBySourceUrl`) and creates the attachment.

**Why:** network I/O is the most fragile and expensive part of an import. Isolating it behind one
service:

- makes download deduplication possible;
- leaves room to move to **asynchronous** import (Action Scheduler) without touching the domain
  Services.

Downloads are currently synchronous and are the main known bottleneck (see the media download notes
in [woocommerce-performance.md](woocommerce-performance.md)).

### 4.10 Repositories for critical lookups

`local_key` lookups use direct `$wpdb` queries (`TermRepository`/`PostRepository`) instead of
`get_terms(meta_query)` / `get_posts(meta_query)`.

**Why:**

- During an import, every write invalidates the `WP_Query` caches.
- WPML's filters on `WP_Query` are expensive when repeated thousands of times.
- A direct prepared query skips that path and is language-independent (`local_key` is the same in
  every language).

This is a targeted optimization where profiling showed the cost. It is not a general bypass of
WordPress.

### 4.11 Bootstrap with manual includes + lightweight `Env`

[onpage.php](../../onpage.php) includes files in an **explicit dependency order**, with no autoloader.
[Env](../../src/Env.php) is a singleton that reads an optional `.env` file for local configuration.

**Why:** without Composer there is no PSR-4 autoloading. Manual include order is verbose, but it
removes any dependency on build tooling and makes the dependency graph **readable in one file**. This
follows from the "no external dependencies" constraint in section 2.

---

## 5. Request lifecycle (example: `POST /woocommerce/products`)

1. WordPress invokes the route registered by `Router::resolve()` on `rest_api_init`.
2. `permission_callback` → `AuthMiddleware::handle` → `Auth::check` validates the Bearer token.
3. `callback` → `Router::dispatch` instantiates `ProductController` and calls `save`.
4. The Controller loads the ACF field-type map once, then iterates over the JSON array.
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
  | Errors | `Router::dispatch` |
  | ACF writes | `Acf::updateFieldValue` |
  | Media | `RemoteMedia` |
  | Languages | Memoized WPML helpers |

- **Uniform write endpoints:** body is an array, upsert by `local_key`, errors prefixed with
  `Service :: Element {i}`, response is an array of IDs.
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
- **No ORM, no generic DB abstraction.** The plugin uses WordPress APIs. Direct queries appear only in
  the Repositories for critical lookups (section 4.10).
- **Imperative validation, not a declarative schema.** Validation is spread across the Services as
  chains of checks. It is repetitive and walks the payload several times (see the validation notes in
  [woocommerce-performance.md](woocommerce-performance.md)). A normalized, single-pass DTO is the most natural refactor if validation cost
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
