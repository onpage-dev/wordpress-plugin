# Plugin internals

This guide explains what each controller does. Its main focus is the business logic in `Post.php` and `Term.php`: how they decide when to create, update and link multilingual content.

## Plugin purpose

The plugin exposes REST endpoints. An external system uses them to sync WordPress content, taxonomies and ACF configuration.

The plugin does more than forward calls to the WordPress API. It also:

- normalizes incoming payloads
- detects which data is shared and which is translated per language
- enforces uniqueness constraints
- resolves the base language and the WPML translations
- propagates ACF fields and taxonomies
- returns consistent application errors (`WP_Error`)

## Controllers at a glance

| Controller | Handles | Main responsibilities |
|---|---|---|
| `Post.php` | Posts and custom post types | Read a single post. Create or update posts from a batch payload. Handle multilingual content with WPML. Handle shared and translated ACF fields. Assign taxonomy terms, resolving translated terms. Delete single posts or all posts of a post type. |
| `Term.php` | Terms of a taxonomy | List the terms of a taxonomy. Create or update multilingual terms. Store `local_key` as term meta. Link translations through WPML. Propagate taxonomy-based ACF fields. |
| `FieldGroup.php` | ACF field groups | List field groups. Create field groups and their fields. Always add the `local_key` field. Set WPML-related options (see below). Delete field groups by ID or title. |

### WPML options set by `FieldGroup.php`

When WPML is active, `FieldGroup.php` sets:

- `acfml_field_group_mode = advanced` on the group (ACFML "Expert" mode)
- the WPML translation preference `wpml_cf_preferences = 2` ("Translate") on every field and sub-field

Rationale: the importer writes every language itself. WPML must therefore never copy values from the default language onto the translations.

## Cross-cutting conventions

### 1. Single-language or multilingual payloads

Many fields accept two shapes:

- a plain value
- a map keyed by language

A plain value:

```json
{
  "title": "Titolo condiviso"
}
```

A language map:

```json
{
  "title": {
    "en": "Title",
    "it": "Titolo"
  }
}
```

This applies to:

- `title`
- `content`
- `name`
- `slug`
- `description`
- ACF values

### 2. Shared vs translated

The controllers split the payload into two buckets:

- `shared`: one value used by every language
- `translated`: per-language overrides

This lets a payload mix fields common to all languages with fields specific to one language.

**How a value is classified.** A value is treated as per-language by its shape alone: an associative array whose keys all look like language codes (`MultiLang::isLanguageMapShape()`). Everything else is `shared`.

**Shared values must reach the field write untouched.** This matters most for structured ACF values: repeaters (a list of rows), groups (an object), multi-value checkboxes, galleries and relationships.

**Why this matters.** Running per-language resolution on a value that is not a language map yields `null`. A `null` cannot be told apart from a legitimate request to clear the field. The write does not fail, the response is still `200`, and the data silently disappears.

**Where the rule lives.** It lives in one place only: `MultiLang::resolveFields()`. The post path and the WooCommerce path both use it. Terms follow the same logic through `MultiLang::splitAcfFieldsByLanguage()`. If you need per-language resolution anywhere else, reuse these functions instead of writing a copy. Three diverging copies are what caused this defect in the first place.

**Known false positive.** A `group` whose sub-field keys are two or three letters long (for example `{"sku": "ABC", "alt": "testo"}`) looks like a language map. It is resolved per language and comes out as `null`. `MultiLangResolveFields.php` pins this current behavior on purpose (see [Tests](#tests)).

### 3. Fallback language

When a value is missing for the current language, the controller uses, in order:

1. the requested language, if present
2. otherwise, the first translated language found in the payload
3. otherwise, the shared value

### 4. WPML is optional, but required for multilingual payloads

If the payload contains per-language values and WPML is not active, the controllers do not try to degrade gracefully. They return an error.

### 5. `local_key`

`local_key` is the external identifier the calling system uses to reconcile objects. It is not a WordPress ID. It lets the caller find already-synced content when the internal ID is unknown or not stable from its point of view.

**Accepted values.** A positive integer or a non-empty string.

- `Input::localKey()` normalizes it with `trim`. It rejects `""`, `"0"` and non-scalar values.
- Integers and numeric strings are equivalent, because WordPress meta values are strings anyway.
- On output, `Input::localKeyOut()` returns an integer for integer-canonical keys (backward compatibility) and a string for text keys.

**Where it is stored.** Storage depends on the endpoint type:

| Endpoint | Storage | Key |
|---|---|---|
| `/posts` | `wp_postmeta` | meta key `onpage_local_key`, on the base post and on all its translations |
| `/woocommerce/products` | `wp_postmeta` | meta key `onpage_local_key` |
| `/woocommerce/variant-products` | `wp_postmeta` | meta key `onpage_local_key` |
| `/terms` | `wp_termmeta` | meta key `onpage_local_key` |
| `/woocommerce/categories`, `/woocommerce/tags`, `/woocommerce/brands` | `wp_termmeta` (categories, tags and brands are terms) | meta key `onpage_local_key` |
| `/woocommerce/attributes/{attribute}/terms` | `wp_termmeta` (global attribute values are terms of the `pa_*` taxonomy) | meta key `onpage_local_key` |
| `/woocommerce/attributes` | `wp_options` | option name `onpage_wc_attribute_local_key_{attribute_id}` |

Every endpoint always both writes and looks up `local_key` in the storage listed above.

**Key rules:**

- The technical meta key is always `onpage_local_key`. This holds for post-based endpoints (posts, products, variations) and term-based endpoints (terms, categories, tags, brands, attribute terms).
- For WooCommerce global attributes, the technical key is the option name `onpage_wc_attribute_local_key_{attribute_id}`.
- `local_key` and `_local_key` are legacy meta keys. An earlier version of the plugin wrote them through an ACF field that has since been removed. No endpoint reads them anymore. `POST /migration` renames or cleans them up.
- With WPML, the same `local_key` can appear on several records of one translation group. They represent the same external object in different languages.

## `Post.php`: business logic

### Purpose

`Post.php` processes batches of posts (create, update, delete). It tries to keep the following consistent:

- the base post
- the translated posts
- ACF fields
- taxonomies
- the WPML mapping

### Main helpers

Before reaching `insert()` or `update()`, the service relies on these helpers:

| Helper | What it does |
|---|---|
| `splitAcfFieldsByLanguage()` | Splits ACF fields into shared and translated. |
| `splitValueByLanguage()` | Splits `title`/`content` into shared and translated. |
| `getFieldsForLanguage()` | Builds the final ACF set for one language. |
| `getValueForLanguage()` | Resolves the right value for one language. |
| `resolvePostTitle()` | Decides the final title for one language. |
| `getPostLanguageDetails()` | Reads the WPML language and `trid` of a post. |
| `getPostTrid()` | Fetches the `trid`. |
| `getTranslationPostIds()` | Builds the `language_code => post_id` map. |
| `setPostLanguage()` | Links a post to a WPML group. |
| `runWithWpmlLanguage()` | Runs code while temporarily forcing both the WPML language and the ACF language. |
| `setTerms()` | Assigns terms, translating them per language when needed. |

### Insert or update?

The REST entry point is `save()`.

For each payload item:

- if `id` is set, it calls `update()`
- otherwise, it calls `insert()`

This is the main business rule for posts: an explicit ID decides between update and create.

## `Post::update()` flow

### 1. Initial validation

The method:

- requires `id`
- loads the existing post
- resolves the actual post type with `norm_post_type()`
- checks that the post type exists

If any step fails, the method ends with a `WP_Error`.

### 2. Payload normalization

The payload is read conservatively. The following rule applies to `type`, `title`, `content`, `description`, `acf_fields`, `files`, `term` and `status`:

- present and not `null`: treated as an explicit change
- missing or `null`: treated as "no change"

Only the text fields actually present are turned into:

- `title_map`
- `content_map`
- `description_map`

The service then builds:

- `translated_languages`
- `fallback_language`
- the post's `current_language`
- `trid`
- `translation_ids`: the full map of the current post's existing translations

### 3. WPML rule

If the payload contains translated data but WPML is not active, it returns the error `wpml_required`.

### 4. Title uniqueness

If the payload contains `title`, the service checks that the final title of each language is not already used by another post of the same type.

The check is not limited to the current language:

- if the post has translations, it checks every resolved title for every language
- if it has no translations, it checks only the final title of the current post

### 5. Updating the main post

The service prepares `$data` with:

- `ID`
- `post_type`
- `post_status`

It adds `post_title`, `post_content` and `post_excerpt` only if those fields were actually sent in the payload. Then it runs `wp_update_post()`.

The main post is updated in its own current language, not automatically in the default language.

### 6. ACF on the main post

If `acf_fields` is present, the service:

- builds the correct fields for `current_language`
- saves them with `update_field()`

### 7. Terms on the main post

If `term` is present, the service:

- resolves the received slugs
- assigns the terms with `wp_set_object_terms()`

No language is forced on the main post unless needed.

### 8. Updating the translations

For each translated post in `translation_ids` other than the current post, the service:

- runs the block inside `runWithWpmlLanguage($language_code, ...)`
- updates only the text and status fields actually present in the payload
- saves the ACF fields for that language
- assigns the terms, translated into the target language

This is the core of the business logic. When the payload contains multilingual data, updating a post affects the whole translation group, not just the current record.

### Result

`update()` returns:

- the updated post's ID on success
- a `WP_Error` on failure

## `Post::insert()` flow

### 1. Resolving the context

The method:

- reads `type`
- normalizes it with `norm_post_type()`
- builds `title_map`, `content_map` and `description_map`
- extracts `translated_languages`
- resolves `default_language`
- computes `fallback_language`

The base language used to create the original post is:

- `getWpmlDefaultLanguage()`
- or, if the default language is missing, the first available translated language

### 2. Validation rules

Before creating anything:

- If there are translations but WPML is not active: error.
- If the original post's title already exists on a post **without a `local_key`**: error `duplicate_title`. A post carrying a different `local_key` is a different On Page® element and does not conflict.
- If `local_key` already exists on the same post type: error `duplicate_local_key`.

### 3. Creating the original post

The original post is created with:

- the title resolved for the base language
- the content resolved for the base language
- `post_type`
- `post_status`

Then:

- if there is a base language, the post gets that WPML language
- the base-language ACF fields are saved
- if present, `local_key` is saved in `wp_postmeta` with meta key `onpage_local_key`, on the post and on all its translations
- the terms from the `term` payload are assigned

### 4. Initializing the WPML group

If the payload contains translated languages, the service:

- reads the original post's `language_details`
- fetches the `trid` and the source language
- fails if no `trid` is available

This is the basis for building the translation group.

### 5. Creating the translations

For each translated language other than the base language, the service:

- resolves title and content for that language
- checks that the title is not a duplicate
- creates a new post
- links it to the original's `trid` with `source_language_code = base language`
- saves the ACF fields for the target language
- saves `local_key` (meta key `onpage_local_key`) on the new translation
- assigns the terms using the correct translations

### 6. Cleanup on failure

If something fails during creation, the service deletes the posts it has just created, including the original.

So `insert()` behaves like a pseudo-atomic operation, even though it does not use real SQL transactions.

### Result

`insert()` returns:

- the original post's ID
- a `WP_Error` on failure

## Key business rules in `Post.php`

### The title is a de facto uniqueness key

The service treats the title as a near-unique key within a post type. Different payloads that produce the same final title conflict with each other.

### `local_key` is an external aid, not an internal primary key

For posts, `local_key` is used for lookup and is persisted in `wp_postmeta` (meta key `onpage_local_key`). The choice between create and update still depends mainly on `id`.

### `term` is fully realigned when present

When `term` is present, the service does not merge incrementally. It resolves the received slugs and assigns exactly that set to the post.

When `term` is missing entirely on update, existing assignments are kept.

### ACF per language

An ACF field can be:

- shared
- specific to one language

For each translated post, the controller builds the final ACF payload by combining the shared values with that language's overrides.

## `Term.php` / `TermService`: business logic

### Purpose

`TermService` manages taxonomy terms with multilingual and ACF support.

Unlike `Post.php`, there are no separate `insert()` and `update()` methods. `insert()` performs an upsert:

- if the term exists, it updates it
- if it does not exist, it creates it

## `Term::insert()` flow

### 1. Resolving the taxonomy

The taxonomy comes from the route. It can be:

- a slug
- an ACF taxonomy ID

The service resolves it to a real WordPress taxonomy slug.

### 2. Payload normalization

For each batch item, the service builds:

- `name_map`
- `slug_map`
- `description_map`
- `acf_field_map`
- `local_key`

It then extracts:

- `translated_languages`
- `fallback_language`
- `default_language`
- `base_language`

`base_language` is:

- the WPML default language, if there is one
- otherwise, the first translated language in the payload

### 3. Initial validation

The main rules:

- If there are translations but WPML is not active: error.
- If there is translated content but the base language cannot be determined: error.
- The name in the base language is required.
- If an `id` is sent, the term must exist in the taxonomy.

### 4. `local_key` rule

The service uses `local_key` as the external reconciliation key:

1. It looks for an existing term with the same `local_key`.
2. If an `id` was also requested and the two terms are not in the same translation group, it returns `duplicate_local_key`.

This is an important business rule. The same `local_key` may represent translations of one concept, but never two unrelated terms.

### 5. Create or update?

The base term is chosen as follows:

1. use `id`, if present
2. otherwise, use the term found by `local_key`
3. if there is a multilingual base language, convert the ID to the matching term in that language

If a `term_id` exists at the end, the service calls `wp_update_term()`. Otherwise it calls `wp_insert_term()`.

This is the real business logic of `TermService`: an upsert driven by `id` or `local_key`.

### 6. Saving the base term

After creating or updating the base term, the service:

- saves `local_key` as term meta `onpage_local_key`
- saves the base-language ACF fields
- for ACF fields of type `image`: if the value is a valid URL, imports or reuses the media and saves the `attachment_id`
- sanitizes remote SVGs, and enables the `image/svg+xml` MIME type only while sideloading that single file

### 7. Initializing WPML

If the payload contains translations, the service:

1. reads the base term's language details
2. if the term has no `trid` yet, assigns it the base language
3. reads the WPML details again
4. if there is still no `trid`, returns an error

### 8. Upserting the translations

For each language other than the base language, the service:

- resolves `name`, `slug` and `description`
- skips the language if the translated name is missing
- reads the existing translation from the WPML group
- converts a `term_taxonomy_id`, if any, to a `term_id`
- updates the translated term if it exists, otherwise creates it
- when it creates the term, links it to the base term's `trid`
- saves `local_key`
- saves the ACF fields for that language
- for ACF fields of type `image`, lets each language import and save its own media

### Result

`Term::insert()` returns:

- the base term's ID for each batch item
- a `WP_Error` on failure

## Key business rules in `Term.php`

### `insert()` is an upsert

The method name is misleading. It does not only insert: it chooses between insert and update at runtime.

### `local_key` is the main reconciliation key

For terms, the flow is driven far more by `local_key` than by `id`.

The value is saved in `wp_termmeta` with meta key `onpage_local_key`. The same value is propagated to every WPML translation of the term.

### The translation group is keyed on `term_taxonomy_id`

WPML does not link terms by `term_id`. It links them by `term_taxonomy_id`.

That is why the service has dedicated helpers:

- `getTermTaxonomyId()`
- `getTermIdByTaxonomyId()`

### Languages without `name` are skipped

If the name is missing for a translated language, that translation is neither created nor updated.

## `Post.php` vs `Term.php`

| Aspect | `Post.php` | `Term.php` |
|---|---|---|
| Create/update strategy | `save()` picks `insert()` or `update()` based on whether `id` is present. | `insert()` is already an upsert, using `id` or `local_key`. |
| External key | `local_key` is supporting data. It does not drive the create/update branch in the first place. Read from `wp_postmeta`, meta key `onpage_local_key`. | `local_key` is a core reconciliation key. Read from `wp_termmeta`, meta key `onpage_local_key`. |
| Translation group update | Updates the current post, then iterates over all known translations and realigns them. | Upserts the base term, then upserts the translations one by one. |

## Notes for anyone changing these controllers

### Changing the language logic

Always check:

- what happens when WPML is inactive
- how the base language is resolved
- which fallback is used
- whether ACF correctly follows the current language

### Changing the title or slug logic

Watch out for:

- uniqueness checks
- behavior with partial payloads
- propagation to existing translations

### Changing `wpml_get_element_translations`

For posts, the controller passes the full WPML `element_type` and requests `all_statuses = true`. Otherwise posts in `draft` can be missing from the filter's response.

### Changing `local_key`

Remember:

- For generic posts and WooCommerce products/variations, it is always stored in `wp_postmeta` as `onpage_local_key`.
- For terms, categories, tags, brands and attribute terms, it is stored in `wp_termmeta` as `onpage_local_key`.
- The legacy meta `local_key` / `_local_key` are no longer written. `POST /migration` renames the former to `onpage_local_key` on posts and deletes the redundant copies.
- Changing this logic affects deduplication, lookup and external sync.

### Changing WooCommerce SKUs with WPML

WooCommerce requires globally unique SKUs. Therefore:

- `Product.php` applies `props.sku` to the source product, but not to its translations.
- `VariantProduct.php` applies `props.sku` to the source variation, but not to the translated variations.
- Prices and other WooCommerce fields can still be propagated to the translations.

If this rule is removed, WooCommerce can throw `WC_Data_Exception` from `set_sku()` because of a duplicate SKU.

## Quick mental model

### Post

1. Normalize the payload.
2. Discover languages and existing translations.
3. Validate the title and preconditions.
4. Update or create the original.
5. Save ACF fields.
6. Assign taxonomies.
7. Create/update the WPML translations.

### Term

1. Resolve the taxonomy.
2. Normalize the payload.
3. Determine the base language.
4. Find the term by `id` or `local_key`.
5. Insert/update the base term.
6. Save meta and ACF fields.
7. Initialize the WPML group.
8. Insert/update the translations.

### Taxonomy delete

When a taxonomy is deleted, the service also removes the ACF field groups whose location is `taxonomy == <slug>`.

With `ignore=1`, this cleanup runs even if the taxonomy no longer exists. This lets a sync clean up field groups left over from an earlier partial deletion.

## Tests

Tests live in `src/Tests/`. They are not part of the plugin: `plugin.php` does not include them, and they are left out of the distribution package. They are command-line PHP scripts that talk to a real WordPress site through the plugin's REST API.

### Configuration

Configure them in the `.env` file at the repository root (see `.env.example`):

- `WP_TEST_URL`: base URL of the test site, without a trailing slash
- `WP_TEST_TOKEN`: Bearer token, the same value as the WordPress option `onpage_auth_token`

### Running

```
./bin/test-launcher              all tests
./bin/test-launcher AcfShared    only tests whose name contains "AcfShared"
```

The launcher runs tests one at a time and shows each result. It **stops at the first failure** and returns that test's exit code. Rationale: all tests share the same site, so continuing on a dirty state would only produce follow-on errors.

You can still run a single test on its own with `php src/Tests/<Name>.php`.

### Beware of comments in `.env`

`.env` has two readers that disagree on comment syntax:

- docker compose expects `#`
- PHP expects `;`, and tolerates `#` only while the comment is plain prose

If a `#` comment contains any of `( ) " ! & | $ { } [ ] ~`, `parse_ini_file()` discards **the whole file**. `OnPage\Env` then reads every variable as empty, with no warning. The symptom is a token that appears unset even though it is written in the file.

`./bin/test-launcher` checks for this before starting and reports it explicitly.

### Audit log

Each launcher run rewrites `logs/audit.log` with the full HTTP conversation:

- one `[req]` line per call, with method, URL and body
- one `[res]` line per call, with HTTP status, duration and response body

Calls are grouped under the header of the test that made them. Each test ends with an `[esito]` line.

JSON bodies are pretty-printed over several lines. A nested repeater on a single line is what makes a debugging session slow. A response that is not JSON (a PHP fatal error, an HTML error page) is logged verbatim, which is exactly what you need to see.

The token is never written. The Authorization header appears as `Bearer <nascosto>`, so the file can be attached to a bug report without a second thought.

The file starts from scratch on each launcher run, so it only ever holds the latest run. `logs/` is in `.gitignore` and is not part of the distribution package. A test run on its own with `php src/Tests/<Name>.php` appends to the same file.

Writing the log can never make a test fail. The destination is resolved once. If it is not writable, logging is silently disabled.

### Test structure: create, then delete

Every test follows a **create-delete** flow:

1. It builds its own fixture (post type, field groups, content).
2. It runs its checks.
3. It removes everything it created, even when an assertion fails.

Cleanup also runs **before** the fixture, so an interrupted run does not block the next one. Cleanup always uses `?ignore=1`, so a missing leftover does not become a second error.

Point the tests at a test site, never at production.

### Test files

- **`WooCommerceCatalog.php`** is the baseline test. In one pass, it does what every import does:
  - First it declares the structures: the custom taxonomy, the WooCommerce global attribute, and seven ACF field groups (one each for product, variation, `product_cat`, `product_tag`, `product_brand`, the custom taxonomy, and the attribute's `pa_*` taxonomy).
  - Then it publishes data into them: a brand, a parent and a child category, a tag, the attribute's two values, a custom-taxonomy term, a simple product, a variable product and two variations, each with its own ACF values.
  - It reads everything back through the `GET` endpoints and finally deletes it all.

  The data is **made up in the file**. Unlike the equivalent test in the `connector-wordpress` repository, nothing is read from On Page®, so the test also runs against a bare WordPress install. It requires a site with WooCommerce and ACF active.
- **`AcfSharedStructuredFields.php`** covers the shared-value rule end to end. Structured ACF values sent as shared on `POST /posts` must survive the round trip. Requires a site.
- **`MultiLangResolveFields.php`** covers the same rule offline, directly on `MultiLang::resolveFields()`. It needs neither a site nor `.env`. It reaches shapes the endpoint cannot, in particular the associative value. It also contains a case labeled *current behavior, not the desired one*, which pins the false positive described under [Shared vs translated](#2-shared-vs-translated). Its purpose is to make the test fail if someone changes the predicate, so that the change is a deliberate decision and not an oversight.

### How ACF persists sub-fields

This is worth knowing: misunderstanding it silently loses the sub-fields of `group` fields.

ACF stores **every** field, sub-fields included, as its own `acf-field` post. Its `post_parent` points to the field above it. `acf_update_field()` saves one field and stops; it does not descend into nested `sub_fields`.

Passing it a field with sub-fields inside therefore created the parent and no children. It left a serialized copy of the sub-fields inside the parent's `post_content`, where ACF never looks: a `group` reloads its sub-fields with `acf_get_fields()`, which reads the child posts.

The symptom was a group that returned `200`, then read back with all its sub-fields collapsed onto the empty key. The reason: the group's `format_value()` indexes the result by `$sub_field['_name']`, which does not exist in that serialized copy. A repeater defined the same way appeared to work, and that is what kept the problem hidden.

`FieldGroup::persistFields()` now saves one level at a time, parent before children, recursing into `sub_fields`. This is the same flattening ACF's own import does. Anyone adding a field type with children must go through it.

Not covered: **flexible content**. Its `layouts` contain their own `sub_fields`, which are still serialized inline as before. That type has not been verified at all.
