# Technical Analysis of the WooCommerce POST Endpoints

This document studies how the `POST /woocommerce/*` endpoints validate and write data. It maps:

- the request flow;
- the cost of each element in a batch;
- the places where overhead accumulates.

Main references:

- Routing: [routes.php](../../routes.php)
- Router: [Router.php](../../src/Router.php)
- Controllers: [src/Controllers/WooCommerce/](../../src/Controllers/WooCommerce/)
- Services: [src/Services/WooCommerce/](../../src/Services/WooCommerce/) and
  [src/Services/](../../src/Services/) (Acf, MultiLang, Wpml, RemoteMedia, Term, TermRepository, Input)

---

## 1. Pipeline shared by all POST endpoints

```
HTTP POST
  └─ WordPress rest_api_init
       └─ Router::dispatch
            └─ Middleware Auth (Bearer token)
                 └─ Controller::save (per endpoint)
                      └─ foreach ($request->get_json_params() as $i => $params)
                           └─ Service::save($params, $i)   ←  the real hot loop
```

Key points:

- Dispatch is registered in [routes.php:48-95](../../routes.php#L48-L95).
  Every WooCommerce POST route goes through `AuthMiddleware`, which calls
  `AuthService::check()` once per request.
- The body is ALWAYS a JSON array. Controllers iterate over the array and process one element at a time.
- There is no real batching at the persistence layer: no transaction, no preallocation, no bulk insert.
- If an element fails, the `HttpException` is caught in
  [Router.php:61](../../src/Router.php#L61) and returned as a `WP_Error`.
  Elements processed before the failure stay written. Partial state is by design.

Controller → service map:

| Endpoint | Controller | Service entry point |
|---|---|---|
| `POST /woocommerce/products` | [Product.php:25-39](../../src/Controllers/WooCommerce/Product.php#L25-L39) | [Services/WooCommerce/Product::save](../../src/Services/WooCommerce/Product.php#L709) |
| `POST /woocommerce/categories` | [Category.php:31-45](../../src/Controllers/WooCommerce/Category.php#L31-L45) | [Services/WooCommerce/Term::save](../../src/Services/WooCommerce/Term.php#L231) |
| `POST /woocommerce/tags` | [Tag.php:31-45](../../src/Controllers/WooCommerce/Tag.php#L31-L45) | same `Services/WooCommerce/Term::save` |
| `POST /woocommerce/brands` | [Brand.php:23-37](../../src/Controllers/WooCommerce/Brand.php#L23-L37) | [Services/WooCommerce/Brand::save](../../src/Services/WooCommerce/Brand.php#L242) (→ `Term::upsertFromParams`) |
| `POST /woocommerce/attributes` | [Attribute.php:22-30](../../src/Controllers/WooCommerce/Attribute.php#L22-L30) | [Services/WooCommerce/Attribute::save](../../src/Services/WooCommerce/Attribute.php#L445) |
| `POST /woocommerce/attributes/{attribute}/terms` | [AttributeTerm.php:37-52](../../src/Controllers/WooCommerce/AttributeTerm.php#L37-L52) | same `Services/WooCommerce/Term::save` |
| `POST /woocommerce/variant-products` | [VariantProduct.php:22-34](../../src/Controllers/WooCommerce/VariantProduct.php#L22-L34) | [Services/WooCommerce/VariantProduct::save](../../src/Services/WooCommerce/VariantProduct.php#L520) |

Cross-cutting traits:

- Before the loop, the controller calls `Acf::loadFieldTypeMap(['post'])` or
  `['term']` ([Acf.php:148](../../src/Services/Acf.php#L148)).
  The cost is amortized: it runs once per request.
- All services raise `HttpException` through `httpException()`
  ([helpers.php:137](../../src/helpers.php#L137)).
  Errors are not aggregated: the first problem in an element is fatal.

---

## 2. Validation: shared schema and cost

The "schema" is not declarative. It is a chain of `is_scalar` / `is_array` / `array_is_list`
checks spread across the services. The recurring patterns follow.

### 2.1 Core helpers

- **Input helpers** ([Input.php](../../src/Services/Input.php)) — cheap:
  `Input::positiveInt`, `Input::stringOrNull`, `Input::requireStringParam`,
  `Input::localKey` / `Input::requireLocalKeyParam` / `Input::localKeyOut`.
  The `local_key` helpers normalize an integer-or-string `local_key` on input and render it on output.
- **MultiLang helpers** ([MultiLang.php](../../src/Services/MultiLang.php)):
  `MultiLang::getLanguages`, `isLanguageMapShape`, `requireWpmlForLanguageMap`,
  `requireWpmlForFieldMap`, `requireWpmlForNestedLanguageMaps`.
  - Each one walks the payload again.
  - Each one calls `getWpmlLanguages()` ([helpers.php:104](../../src/helpers.php#L104)),
    which runs `apply_filters('wpml_active_languages', ...)`.
  - **The result is not cached per request.** It is recomputed hundreds of times in a batch.
- **`httpException()`** produces uniform errors of the form `Service :: Element {i} :: ...`.

### 2.2 Product validation

`Product::normalizeProductPayload`
([Product.php:339-408](../../src/Services/WooCommerce/Product.php#L339-L408))
runs these steps, in order, for every element:

1. `local_key`: required.
2. `name`: required. Scalar or WPML map; each language is validated.
3. `status`: optional.
4. `acf_fields`: object, plus `requireWpmlForFieldMap` (recursive).
5. `long_description`/`content`, `short_description`/`description`: WPML check.
6. `props`: object, plus `requireWpmlForProductPropLanguageMaps`
   ([Product.php:144-153](../../src/Services/WooCommerce/Product.php#L144-L153)),
   which iterates over all 22 known WC fields.
7. `image`: WPML check.
8. `attributes`: recursive `validateAttributeValue` for each attribute
   ([Product.php:156-194](../../src/Services/WooCommerce/Product.php#L156-L194)).
9. `downloads`: list. Each download validates `file`/`url`, `name` and `id`, each with a WPML check.
10. `id`: optional, positive integer.
11. `Taxonomy::buildFromParams` ([Taxonomy.php:30-50](../../src/Services/WooCommerce/Taxonomy.php#L30-L50))
    for `categories`, `tags` and `brand`. Each call runs the WPML functions again to detect per-language maps.

Validation cost for an average product (about 10 props, 8 attributes, 4 downloads, 3 languages, 20 ACF fields):

- About 60–80 calls to `getWpmlLanguages()`. Each is an `apply_filters` call, and the
  filters WPML registers internally run SQL queries against `icl_languages`.
- The full payload is walked at least 3 times: validation, `getLanguageContext`, and application.

### 2.3 Term validation (categories / tags / attribute terms / brands)

`Term::upsertFromParams` ([Term.php:1076-1088](../../src/Services/Term.php#L1076-L1088)):

1. `requireWpmlForPayloadLanguageMaps` on `name`, `slug`, `description`, `acf_fields`.
2. `parseTermPayload` ([Term.php:461-501](../../src/Services/Term.php#L461-L501))
   splits every value into `shared`/`translated` with `MultiLang::splitValueByLanguage`.
3. `validateParsedPayload` ([Term.php:516-546](../../src/Services/Term.php#L516-L546))
   immediately resolves `findByLocalKey` (DB) and `findByLocalKeyForLanguage` for `parent_local_key`.
   Part of the validation is therefore already a DB fetch.

For categories, `WooCommerceTerm::prepareParentPayload`
([Services/WooCommerce/Term.php:172-198](../../src/Services/WooCommerce/Term.php#L172-L198))
resolves `parent` as a `local_key` with one more lookup, `TermService::findIdByLocalKey`.

### 2.4 Variation validation

`VariantProduct::save` ([VariantProduct.php:520-543](../../src/Services/WooCommerce/VariantProduct.php#L520-L543))
does very little structural validation (object, required `local_key`). However, it forces:

- `requireParentProduct` ([VariantProduct.php:241-269](../../src/Services/WooCommerce/VariantProduct.php#L241-L269)):
  always 1 `get_posts` (by id or by local_key). If BOTH `parent_id` and `parent` are given,
  it runs 2 separate lookups to check they match.
- Attribute resolution in `applyAttributes`
  ([VariantProduct.php:856-904](../../src/Services/WooCommerce/VariantProduct.php#L856-L904)).
  For each attribute of the variation it cascades through `get_term` (by id), then
  `get_term_by('slug')`, then `get_term_by('name')`
  ([VariantProduct.php:953-963](../../src/Services/WooCommerce/VariantProduct.php#L953-L963)).

### 2.5 Global attribute validation

`Attribute::save` ([Services/WooCommerce/Attribute.php:445-501](../../src/Services/WooCommerce/Attribute.php#L445-L501))
builds its args with `buildAttributeArgs`, then calls WooCommerce's
`wc_update_attribute` / `wc_create_attribute`. Validation is light.
The real cost is in the `wp_options` scans (see §3).

---

## 3. Queries and I/O per element

The tables below show the cost **per batch element** (best case, WPML inactive).

Legend: **Q** = explicit DB query, **H** = external HTTP call, **S** = `$product->save()`.

### 3.1 `POST /woocommerce/products` — INSERT (no WPML)

| Step | Source | Q | H | S | Notes |
|---|---|---|---|---|---|
| Find existing (no id, with local_key) | `findCanonicalProductIdByLocalKey` → `findProductIdsByLocalKey` ([Product.php:514-532](../../src/Services/WooCommerce/Product.php#L514-L532)) | 1 | | | `get_posts` with a `meta_query` on `onpage_local_key` |
| local_key uniqueness pre-check | `findProductIdByLocalKey` ([Product.php:758](../../src/Services/WooCommerce/Product.php#L758)) | 1 | | | REDUNDANT: the lookup above already did this |
| Title uniqueness pre-check | `findDuplicateProductIdByTitle` ([Product.php:762](../../src/Services/WooCommerce/Product.php#L762)) | 1 | | | `get_posts` by `title` |
| Initial save | `persistProductFromParams` → `$product->save()` ([Product.php:956](../../src/Services/WooCommerce/Product.php#L956)) | many | | 1 | `wp_insert_post` + meta_input + lookup table sync + WC hooks |
| Store local_key | `update_post_meta(onpage_local_key)` ([Product.php:965](../../src/Services/WooCommerce/Product.php#L965)) | 1 | | | |
| Link attachments | `linkAttachmentsToProduct` ([ProductDownloads.php:89-110](../../src/Services/WooCommerce/ProductDownloads.php#L89-L110)) | per download | | | `attachment_url_to_postid` + `wp_update_post` |
| Sync public IDs | `syncPublicIdsMeta` ([ProductDownloads.php:113-140](../../src/Services/WooCommerce/ProductDownloads.php#L113-L140)) | 1 | | | `update_post_meta` |
| Image (if `image` is set) | `applyImage` → `RemoteMedia::urlToPost` ([RemoteMedia.php:394-400](../../src/Services/RemoteMedia.php#L394-L400)) | ≥3 | 1 | | `findAttachmentBySourceUrl` (`get_posts`), `download_url` (HTTP), `wp_update_post` |
| Extra save after image | `applyImage` → `$product->save()` ([Product.php:1337](../../src/Services/WooCommerce/Product.php#L1337)) | | | 1 | Product save #2 |
| Sync variations | `syncVariableProductToVariations` ([ProductDownloads.php:143-166](../../src/Services/WooCommerce/ProductDownloads.php#L143-L166)) | N children | | N | One `save()` per child variation |
| ACF fields | `saveAcfFields` ([Product.php](../../src/Services/WooCommerce/Product.php)) | per field | per image | | See note below |
| Taxonomies | `Taxonomy::apply` ([Taxonomy.php:53-104](../../src/Services/WooCommerce/Taxonomy.php#L53-L104)) | ≥1 per term | | | `get_term_by` by slug, optional `wpml_object_id`, then `wp_set_object_terms` |

Notes:

- **`saveAcfFields`** iterates the fields and passes each one to the central `Acf::updateFieldValue`:
  - `tab` fields are skipped;
  - `image`/`file` URLs become an `attachment_id` via `RemoteMedia::urlToPost` / `urlToMediaLibrary`;
  - repeaters are normalized by `Acf::resolveRepeaterValue`, which is recursive, validates the shape
    and resolves image/file sub-fields.

  `Post::savePostAssociations` and `Term::updateAcfFields` share the same logic.
- **Downloads.** Each download may also trigger `normalizeDownloadFileUrl`
  ([ProductDownloads.php:444-485](../../src/Services/WooCommerce/ProductDownloads.php#L444-L485)).
  This does an HTTP download (`RemoteMedia::urlToMediaLibrary`, 12s timeout), then
  `get_attached_file` and `wp_get_attachment_url`.

**Realistic count for one "rich" product** (1 image, 4 remote downloads, 8 attributes,
6 categories/tags, 15 ACF fields including 2 images, no variations, no WPML):

- about 12–25 SQL queries;
- 7 external HTTP calls;
- **3 separate saves of the same `WC_Product`** (initial, after image, optional variable sync).

### 3.2 `POST /woocommerce/products` — WPML multipliers

With WPML active and 3 languages:

- `getLanguageContext` ([Product.php:426-468](../../src/Services/WooCommerce/Product.php#L426-L468))
  walks every translatable field to extract the list of languages.
- Title pre-check: 1 extra query for EACH translated language
  ([Product.php:766-778](../../src/Services/WooCommerce/Product.php#L766-L778)).
- `insertTranslatedProducts` ([Product.php:981-1048](../../src/Services/WooCommerce/Product.php#L981-L1048))
  runs `persistProductFromParams` ONCE PER LANGUAGE. This multiplies by the number of languages:
  - saves;
  - `applyProductFields`, `applyProductAttributes`;
  - `ProductDownloads::apply` (remote files are RE-IMPORTED; the attachment is reused via
    `findAttachmentBySourceUrl`, but that check is one `get_posts` per language);
  - `applyImage`, `saveAcfFields`, `Taxonomy::apply`.

  Language switching is done by `Wpml::runWithLanguage` ([Wpml.php:12-36](../../src/Services/Wpml.php#L12-L36)).
- The UPDATE path ([Product.php:833-909](../../src/Services/WooCommerce/Product.php#L833-L909))
  repeats the same work. `findDuplicateProductIdByTitle` runs one `get_posts` for each language
  in the translations.
- Translation group resolution: `getTranslationProductIds`
  ([Product.php:1429-1494](../../src/Services/WooCommerce/Product.php#L1429-L1494))
  calls 3–4 different WPML filters, each with its own internal query.

Realistic result with 3 languages: about 3x queries, 3x saves and 3x ACF writes.

### 3.3 `POST /woocommerce/categories` (and tags / attribute terms)

For each element, `Term::upsertFromParams` runs:

1. `requireWpmlForPayloadLanguageMaps` — validation only.
2. `validateParsedPayload` → `findByLocalKey` (1 `wpdb->get_col` query from
   [TermRepository.php:38-56](../../src/Services/TermRepository.php#L38-L56)).
3. `upsertBaseTerm`:
   - `findByLocalKeyForLanguage` ([Term.php:232-259](../../src/Services/Term.php#L232-L259)):
     1 query, possibly several WPML lookups per language.
   - `upsertTermData` ([Term.php:879-923](../../src/Services/Term.php#L879-L923)):
     - `findTermIdByDataSlug` (at least 1 query);
     - `wp_update_term` or `wp_insert_term` inside `Wpml::runWithLanguage`;
     - on a `duplicate_term_slug` error, `forceUpdateTermWithExistingSlug`
       ([Term.php:759-830](../../src/Services/Term.php#L759-L830))
       recovers by running `wpdb->update` directly on `wp_terms` and `wp_term_taxonomy`.
   - `setTermLocalKey` (`update_term_meta`).
   - `ensureBaseTermLanguage` (WPML action).
   - `updateAcfFields` for each field (can trigger `RemoteMedia::urlToMediaLibrary`).
4. `syncTranslations` ([Term.php:998-1073](../../src/Services/Term.php#L998-L1073))
   repeats, for each language: translation lookup, `upsertTermData`, `setTermLanguage`, `updateAcfFields`.
5. `WooCommerceTerm::syncThumbnail` ([Services/WooCommerce/Term.php:111-133](../../src/Services/WooCommerce/Term.php#L111-L133))
   runs another `findTermIdsByLocalKey` (query), an optional `RemoteMedia::urlToMediaLibrary` (HTTP),
   and one `update_term_meta` per term ID found. Only for `product_cat` and brands.

### 3.4 `POST /woocommerce/attributes`

`Attribute::save` ([Services/WooCommerce/Attribute.php:445-501](../../src/Services/WooCommerce/Attribute.php#L445-L501)):

- `findAttributeByLocalKey` → `findExistingAttributeIdsByLocalKey` →
  `findAttributeIdsByLocalKey` ([Services/WooCommerce/Attribute.php:143-172](../../src/Services/WooCommerce/Attribute.php#L143-L172))
  runs **one `LIKE %` query** on `wp_options` (`option_name LIKE 'onpage_wc_attribute_local_key_%'`).
  This can get expensive on sites with many options.
- `wc_create_attribute` / `wc_update_attribute` internally call `flush_rewrite_rules`,
  invalidate transients and re-create the taxonomy.
- `persistLocalKeyForAttribute` ([Services/WooCommerce/Attribute.php:209-213](../../src/Services/WooCommerce/Attribute.php#L209-L213))
  runs `findAttributeIdsByLocalKey` again to enforce uniqueness, which is a second LIKE query.

✅ **Optimized (2026-05-27):** `findAttributeIdsByLocalKey` now has a request-scoped memo
(`self::$localKeyIdsCache`). It is invalidated on every local_key option write
(`persistLocalKeyForAttribute` / `deleteLocalKeyForAttribute`).
The second LIKE per save is now a cache hit. The single LIKE remains.

### 3.5 `POST /woocommerce/brands`

Before the actual term-level work
([Services/WooCommerce/Brand.php:102-123](../../src/Services/WooCommerce/Brand.php#L102-L123)):

- `ensureTaxonomy(true)` may call `Taxonomy::insertFromParams` (creates the ACF taxonomy),
  `register_taxonomy` and **`flush_rewrite_rules()`**. This is expensive, but only paid
  the first time, when the brand taxonomy does not exist yet.

After that, the flow is identical to categories. `syncThumbnail`
([Services/WooCommerce/Brand.php:182-204](../../src/Services/WooCommerce/Brand.php#L182-L204))
repeats the same pattern: query by local_key, HTTP download, meta update.

✅ **Optimized (2026-05-27):** `findTermIdsByLocalKey` no longer uses `get_terms(meta_query)`,
whose cache was invalidated on every term write during an import. It now uses a direct `$wpdb`
query, like `WooCommerce/Term` and `TermRepository`. Intended side effect: brand list and
delete-by-local_key now cover all language variants, consistent with categories and tags.

### 3.6 `POST /woocommerce/variant-products`

`VariantProduct::saveOneFromParams`
([VariantProduct.php:632-695](../../src/Services/WooCommerce/VariantProduct.php#L632-L695)):

- Lookups: `findVariationIdByLocalKeyForParent` (1 query),
  `getAllowedParentIdsForLocalKey` (WPML filters), and
  `assertLocalKeyAvailableForParentSet`, which runs another `findVariationIdsByLocalKey` (1 query).
  Then `wp_get_post_parent_id` for each variation found.
- Builds a `\WC_Product_Variation` and applies fields and attributes
  (term resolution with up to 3 `get_term_by` fallbacks).
- **`$variation->save()`** (save #1).
- `update_post_meta(onpage_local_key)`.
- `applyImage` with `RemoteMedia::urlToPost` (HTTP), then **`$variation->save()` again**.
- `Product::syncParentDownloadsToVariation` ([ProductDownloads.php:169-190](../../src/Services/WooCommerce/ProductDownloads.php#L169-L190)):
  `wc_get_product(parent)`, builds the downloads payload, then **`$variation->save()` again**.
- `syncParentProduct` ([VariantProduct.php:907-920](../../src/Services/WooCommerce/VariantProduct.php#L907-L920)):
  `WC_Product_Variable::sync($parent_id)` recomputes min/max prices by iterating over ALL
  variations of the parent, then runs `wc_delete_product_transients` and `clean_post_cache`.
  **Expensive: O(number of variations) for every single variation saved.**

In WPML mode, `saveTranslatedFromParams`
([VariantProduct.php:546-629](../../src/Services/WooCommerce/VariantProduct.php#L546-L629))
runs `saveOneFromParams` once per translation of the parent.
Each run calls `syncParentProduct` for the translated parent.

---

## 4. Main bottlenecks and their causes

### 4.1 Repeated saves of the same CRUD object

`$product->save()` / `$variation->save()` is called 2–4 times per element, even in the simple case
(initial, after image, `syncVariableProductToVariations`, parent sync). Each save:

- runs `wp_insert_post` / `wp_update_post`;
- fires `save_post`, `transition_post_status`, `clean_post_cache`, and WooCommerce hooks
  (`woocommerce_new_product`, `woocommerce_update_product`, `wc_product_meta_lookup` lookup table
  recalculation, `wc_delete_product_transients`);
- re-syncs terms and triggers recounts (deferred, but still triggered).

**Halving the saves roughly halves the wall-clock time of a "rich" import.**

### 4.2 Repeated `get_posts` + `meta_query` lookups on `onpage_local_key` / title

The pattern appears in [Product.php:514-532](../../src/Services/WooCommerce/Product.php#L514-L532),
[Product.php:563-576](../../src/Services/WooCommerce/Product.php#L563-L576) and
[VariantProduct.php:179-225](../../src/Services/WooCommerce/VariantProduct.php#L179-L225).
For every batch element:

- 1 lookup by local_key (canonical resolve);
- 1 repeated lookup right after, for the uniqueness check (REDUNDANT);
- 1 lookup by title;
- 1 lookup per translated language.

All of these go through the generic `WP_Query` engine with WPML filters applied.
This is costly on WPML sites with a large `icl_translations` table.

`TermRepository::findTermIdsByLocalKey`
([TermRepository.php:38-56](../../src/Services/TermRepository.php#L38-L56))
already uses a cheaper direct `wpdb->prepare` query. The same approach should be extended to posts.

### 4.3 `WC_Product_Variable::sync` runs for every variation

`VariantProduct::syncParentProduct` is called in `saveOneFromParams`
([VariantProduct.php:691](../../src/Services/WooCommerce/VariantProduct.php#L691)).
Importing N variations of the same parent in one batch runs the sync N times, each O(N).
The total is **O(N²)** over the parent's children.

### 4.4 Serial remote downloads

`RemoteMedia::downloadRemoteFile` ([RemoteMedia.php:211-223](../../src/Services/RemoteMedia.php#L211-L223))
is synchronous, with a 45s timeout (12s for `downloads_*`). It runs for each:

- product/variation `image`;
- ACF `image`/`file` field;
- `downloads.*.file` that is not already local;
- category/brand `thumbnail`.

Each one does an HTTP `download_url`, a sideload, and `wp_generate_attachment_metadata`
(which generates thumbnails and is CPU-heavy). All of it blocks.
A single product with 4 images and 4 downloads can spend more than 30s on I/O alone.

`findAttachmentBySourceUrl` ([RemoteMedia.php:37-49](../../src/Services/RemoteMedia.php#L37-L49))
runs for every URL, but its result is not memoized across the batch.
Two products that share an image run 2 identical queries.

### 4.5 `getWpmlLanguages()` is not memoized

It is called dozens of times per element, directly or through `MultiLang::getLanguages`.
Each call runs `apply_filters('wpml_active_languages', null, ['skip_missing' => 0])`.
WPML resolves this with a SQL query against `icl_languages`. WPML's internal cache helps,
but each call still costs a function call chain.

### 4.6 The payload is walked repeatedly during validation

For each product:

1. **Walk 1:** `normalizeProductPayload` (validation and extraction).
2. **Walk 2:** `getLanguageContext` ([Product.php:426-468](../../src/Services/WooCommerce/Product.php#L426-L468))
   goes over acf_fields, props, attributes, downloads, name, content, description and image again.
3. **Walk 3:** while applying each language, `resolveFieldsForLanguage`, `resolveValue`, etc.
   traverse the fields once more.

Terms behave the same way: `parseTermPayload`, then `validateParsedPayload`, then `buildTermData` for each language.

### 4.7 `Taxonomy::apply` resolves each term individually

[Taxonomy.php:53-104](../../src/Services/WooCommerce/Taxonomy.php#L53-L104) does, for each term:

1. `findTermForAssignment` → `findTermByLocalKeyForAssignment` (1 query + WPML filters);
2. fallback `findTermBySlugForAssignment` (`get_term_by` for each active language).

With 6 categories × 3 languages this is about 36 lookups. Nothing is pre-resolved in batch.

### 4.8 ACF: partial caching, sequential writes

`Acf::loadFieldTypeMap` ([Acf.php:148-161](../../src/Services/Acf.php#L148-L161))
is the strong point: it is loaded once per request. However:

- `Acf::getFieldType` ([Acf.php:170-206](../../src/Services/Acf.php#L170-L206))
  iterates over "groups" or "group_fields" when the field is not in the scoped context.
  It is called for EVERY ACF field being written.
- `Acf::updateFieldValue` ([Acf.php:271-301](../../src/Services/Acf.php#L271-L301))
  calls `acf_update_value` per field: one postmeta write each, no bulk write.
  For an image/file field with a remote URL, it triggers `RemoteMedia::urlToPost` inline
  ([Product.php:1358-1363](../../src/Services/WooCommerce/Product.php#L1358-L1363)) — a synchronous HTTP call.

### 4.9 `Attribute` LIKE query on `wp_options`

`findAttributeIdsByLocalKey` ([Services/WooCommerce/Attribute.php:143-172](../../src/Services/WooCommerce/Attribute.php#L143-L172))
runs `option_name LIKE '%onpage_wc_attribute_local_key_%' AND option_value = %s`.
On a site with thousands of options, the LIKE cannot use the autoload index.
The query also used to run **twice** per save (uniqueness + assert).

✅ **Optimized (2026-05-27):** a request-scoped memo means only 1 LIKE per local_key per request.
The remaining LIKE is still not indexed on `option_value`. For high attribute volumes,
consider an index or a dedicated store (see §6).

---

## 5. Estimated cost per batch

Variables: N = elements in the batch, L = active languages, D = downloads/images per product.

| Endpoint | Queries/element (no WPML) | Queries/element (WPML × L) | WC saves per element |
|---|---|---|---|
| products INSERT | ~10–15 + ~3·D | ~10–15 + L · (4–6 queries) | 2–3 + N_children |
| products UPDATE | ~8–12 | ~8–12 + L · (3 queries) | 1–2 + N_children |
| variant-products | ~10 + sync O(N_variations_parent) | ~10 · L_parent · L_variation | 3 + re-creation |
| categories/tags | ~4–6 | ~4–6 + L · (~3 queries) | (term updates) |
| brands (first time) | ~6–8 + flush_rewrite | same as categories | same as categories |
| attributes | ~3 LIKE on options + 1 WC | n/a | n/a |
| attribute-terms | same as categories | same as categories | n/a |

For 1000 products, 3 languages and 4 downloads each: close to 100k SQL queries and
4000–8000 sequential HTTP downloads. This is the most realistic case for a bulk
On Page® → WooCommerce import.

---

## 6. Ways to speed up insert/update

Grouped by cost/benefit. **Analysis only** — no code has been changed.

### 6.1 Quick wins (low risk, high return)

1. **Memoize the WPML helpers per request.**
   `getWpmlLanguages`, `getWpmlDefaultLanguage`, `getWpmlCurrentLanguage` and `isWpmlActive`
   are called hundreds of times per batch
   ([helpers.php:72-124](../../src/helpers.php#L72-L124)). Use a static per-request cache.
2. **Remove redundant lookups.** Right after `findCanonicalProductIdByLocalKey`, the
   `findProductIdByLocalKey` check in `insertFromParams`
   ([Product.php:758](../../src/Services/WooCommerce/Product.php#L758))
   repeats the same work. The same applies to attributes (uniqueness assert after findExisting).
3. **Batch-local cache for `findAttachmentBySourceUrl`** in
   `RemoteMedia::urlToMediaLibrary` ([RemoteMedia.php:340-376](../../src/Services/RemoteMedia.php#L340-L376)).
   A `url → attachment_id` map filled on first lookup saves many `get_posts` calls
   for files shared between products.
4. **Coalesce `WC_Product_Variable::sync`.** Instead of calling it inside `saveOneFromParams`,
   collect the touched parent_ids in the controller and call it ONCE per parent at the end of the batch.
   A small helper in the `VariantProduct::save` controller is enough.
5. **Wrap the controller loop in `wp_defer_term_counting(true)` + `wp_suspend_cache_invalidation(true)`.**
   The recount runs at the end. Safe as long as it is recomputed at the end of the batch.
6. **Replace `get_posts(meta_query)` on `onpage_local_key`** (postmeta) with direct
   `wpdb->prepare` queries, in the style of `TermRepository::findTermIdsByLocalKey`.
   This skips `WP_Query`/WPML filters and unnecessary object caching.
   Adding an index on `postmeta(meta_key, meta_value)` does not help, because WordPress already
   indexes `meta_key`. The gain comes from the shorter execution path.
7. **Remove the second `$product->save()` in `applyImage`**
   ([Product.php:1334-1341](../../src/Services/WooCommerce/Product.php#L1334-L1341)).
   Move `set_image_id` before the first save in `persistProductFromParams`.
   Do the same in `VariantProduct::applyImage`.

### 6.2 Medium refactors (reorder operations, low functional risk)

8. **Short-circuit the global path of `getFieldType`.** The request-scoped ACF field type map
   already exists. But when a field is not found in the scoped context, `getFieldType` still does
   the expensive global scan, even when `context`/`target` are known
   ([Acf.php:170-206](../../src/Services/Acf.php#L170-L206)).
9. **Resolve terms in batch** in `Taxonomy::apply`. Collect all requested slugs/local_keys first,
   run 1 query per taxonomy that returns all term_ids, then assign. Once per product if needed.
10. **Merge validation and normalization into one phase.** The payload is walked at least 3 times.
    Build a "normalized DTO" and consume it in a single pass.
11. **Do not re-apply `shared` fields for translations** (SKU, prices, dimensions, downloads,
    non-localized attributes). Today `persistProductFromParams` always applies everything, even
    unchanged values. Separating "shared, once" from "per language" at the first save saves
    ×N_languages postmeta writes.
12. **Pre-resolve remote media per batch.** Before the loop, collect all unique URLs in the payload
    and import them in one pass. Alternatively, mark them as "seen" so the first element downloads
    them and later elements find the attachment in the cache.

### 6.3 Architectural refactors (need broader testing)

13. **Temporarily suspend expensive WooCommerce hooks** (lookup table sync, HPOS search indexer,
    term recount) inside an import block, and regenerate at the end of the batch.
    WooCommerce provides APIs for this (`WC_Background_Process`, `wc_update_product_lookup_tables_column`).
14. **Download media asynchronously** via Action Scheduler. The REST response returns immediately
    with a pending `attachment_id`, and the HTTP import happens outside the request.
    This overlaps I/O and acknowledges the caller sooner. It fits the existing
    `RemoteMedia::ACTION_*` structure.
15. **Replace `get_posts` with targeted `wpdb` queries** for all `local_key` lookups
    (post types, variations, terms). `WP_Query` filter latency adds up over thousands of calls.
16. **Introduce a shared "session cache"** across services (e.g. `BatchContext`). It would hold:
    WPML languages, resolved local_key → post_id, URL → attachment_id, term reference → term_id,
    and the ACF field type map already in `Acf`. Every service reads from it instead of repeating lookups.

### 6.4 Side effects to consider

- Suspending hooks can break third-party plugins (SEO, search index, CDN cache).
  It should sit behind a flag that defaults to the current behavior.
- Importing media in the background changes the REST response contract
  (today it returns the final `attachment_id`).
- Deduplicating local_key lookups across a batch needs consistent invalidation when one element
  CREATES a record that a later element would LOOK UP.
- Bypassing `WP_Query` loses the WPML filters that select the current language.
  Check each lookup to see whether it needs the language filter. For local_key it usually does NOT,
  because local_key is the same in every language.

---

## 7. Summary

The dominant patterns are:

- Linear validation, but repeated several times on the same payload.
- Duplicate `local_key` / title lookups per element.
- Repeated saves of the same CRUD object within one pipeline (initial → set image → sync).
- Cost multiplied ×N languages under WPML, even for fields that are NOT actually translated.
- Synchronous remote I/O as the dominant share of wall-clock time for imports with images/downloads.

Expected gains:

- **Quick wins (6.1): about 70% of the gain, at low risk.** WPML memoization, removal of redundant
  saves/lookups, deferring `Variable::sync` and term counting, batch cache for `findAttachmentBySourceUrl`.
- **Medium refactors (6.2): the next 20%.**
- **Architectural changes (6.3):** only worth it if the required throughput is far above today's.
