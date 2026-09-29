# Plugin internals

This guide explains what each controller and service does. Its main focus is the business logic in
`Post.php` and `Term.php`: how they decide when to create, update and link multilingual content.

WooCommerce products, variations and downloads are covered in [woocommerce.md](woocommerce.md). The
design decisions behind the code are in [architecture.md](architecture.md). The request and
response contract of each endpoint is in [API.md](../API.md).

## Plugin purpose

The plugin exposes REST endpoints. An external system uses them to sync WordPress content,
taxonomies and ACF configuration.

The plugin does more than forward calls to the WordPress API. It also:

- validates and normalizes incoming payloads
- detects which data is shared and which is translated per language
- enforces uniqueness constraints
- resolves the base language and the WPML translations
- propagates ACF fields and taxonomies
- imports remote media into the Media Library
- returns consistent application errors: services throw `HttpException`, and `Router::dispatch()`
  turns it into a `WP_Error`. Any other `Throwable` becomes `500 request_failed`, so a PHP or
  WooCommerce exception never reaches the client as a fatal error page

## Controllers at a glance

Controllers are thin. They validate the shape of the request body, loop over it and hand each
element to a service in `src/Services/`.

| Controller | Handles | Main responsibilities |
|---|---|---|
| `Post.php` | Posts and custom post types | Read, list and search posts. Create or update posts from a batch payload, with WPML translations, ACF fields and taxonomy terms. Delete posts by ID, `local_key` or post type. |
| `Term.php` | Terms of any taxonomy | List and search terms. Create or update multilingual terms. Store `local_key` as term meta. Link translations through WPML. Delete terms by ID or `local_key`. |
| `FieldGroup.php` | ACF field groups | List field groups. Create or update field groups and their fields. Set WPML-related options. Delete field groups by ID or title. |
| `PostType.php` | ACF post types | List, upsert and delete post types registered through ACF. |
| `Taxonomy.php` | ACF taxonomies | List, upsert and delete taxonomies registered through ACF, with translated labels. |
| `Media.php` | Media Library | List attachments, upload files, link remote files to ACF fields, delete attachments. |
| `Migration.php` | `POST /migration` | One-off data upgrades after a change of internal format. |
| `Index.php` | `DELETE /indexes` | Remove every `local_key` association from posts and terms. |
| `Language.php` | `GET /languages` | List the active WPML languages, default first, and whether WPML is active. Read-only. |
| `WooCommerce/*.php` | WooCommerce entities | See [woocommerce.md](woocommerce.md). |

## Request conventions

### Body validation

Controllers validate the body before any service runs, with the helpers in `Input.php`:

| Helper | Rule | Error |
|---|---|---|
| `Input::requireJsonList()` | The body of every batch endpoint must be a JSON array. A missing or non-JSON body, a scalar or an object is rejected. `{}` decodes to `[]` like the empty list, so the raw body (`get_body()`) is checked too: only a literal array is an empty batch. `[]` is accepted and does nothing. | `400 invalid_param`, `<Prefix> :: Request body must be a JSON array` |
| `Input::requireObjectElement()` | Each element of a `POST` batch must be a non-empty JSON object. Scalars, `null`, lists and `{}` are rejected. | `400 invalid_param`, `<Prefix> :: Element N :: Invalid payload; expected a non-empty JSON object` |
| `Input::positiveInt()` | A positive integer, a whole float (`12.0`) or a digit string. Surrounding spaces are trimmed. Booleans, `"1.9"`, `"1e3"`, `"12abc"` and arrays give `null` instead of being cast. Used for most IDs in payloads and query parameters (`id`, `parent`, `post_id`, ...). | Each caller picks its own error |
| `Input::optionalPositiveIntParam()` | An optional `id` in a `POST` element: absent, `null`, `0` or `"0"` gives `null`, anything else must pass `positiveInt()`. Used by `POST /posts`, `/terms` (and the WooCommerce term endpoints), `/woocommerce/attributes` and `/woocommerce/variant-products`, so an invalid `id` is never dropped and turned into an insert. | `400 invalid_param`, `<Prefix> :: Element N :: Parameter 'id' must be a positive integer or null` |
| `Input::strictPositiveInt()` | Stricter: a positive integer, or a string of digits only. No trimming and no floats. Used by `DELETE /terms`, for a plain ID and for `id` in an object. | `400 input_invalid` |

`DELETE` endpoints that accept IDs or keys check the type of each element too. For example,
`DELETE /field-groups`, `/post-types` and `/taxonomies` reject anything that is not an integer ID
or a string, with `400 input_invalid`.

The `/media` endpoints have their own body rules (multipart upload, a JSON object for
`/media/link`). See [API.md](../API.md).

### `?ignore`

`onpage_should_ignore_missing()` returns `true` when the `ignore` query parameter is present, with
any value. Every `DELETE` endpoint except `DELETE /indexes` reads it. With `?ignore`, an element
that does not exist is skipped instead of failing with `404`. Other errors still fail.

### Pagination headers

`onpage_set_pagination_headers()` sets `X-WP-Total` and `X-WP-TotalPages`, like the WordPress core
REST API. Two listings set them:

- `GET /posts`, on the paginated listing only. The `id` and `title` lookups return every match and
  send no headers.
- `GET /media`.

Both use `WP_Query` rather than `get_posts()`, because only `WP_Query` exposes `found_posts`.

## Cross-cutting conventions

### 1. Single-language or multilingual payloads

Many fields accept two shapes:

- a plain value
- a map keyed by language

A plain value:

```json
{
  "title": "Shared title"
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
- taxonomy labels (`singular_label`, `plural_label`)

### 2. Shared vs translated

The services split the payload into two buckets:

- `shared`: one value used by every language
- `translated`: per-language overrides

This lets a payload mix fields common to all languages with fields specific to one language.

**How a value is classified.** A value is treated as per-language when it is an associative
array whose keys are all language codes (`MultiLang::isLanguageMapShape()`). A key is a language
code when it is an active WPML language, or an ISO 639-1 code with optional subtags
(`MultiLang::ISO_639_1_CODES`). Everything else is `shared`. Language codes the site has not
activated in WPML are recognized, then ignored.

**Shared values must reach the field write untouched.** This matters most for structured ACF
values: repeaters (a list of rows), groups (an object), multi-value checkboxes, galleries and
relationships.

**Why this matters.** Running per-language resolution on a value that is not a language map yields
`null`. A `null` cannot be told apart from a legitimate request to clear the field. The write does
not fail, the response is still `200`, and the data silently disappears.

**Where the rule lives.** It lives in one place only: `MultiLang::resolveFields()`. The post path
and the WooCommerce path both use it. Terms use `MultiLang::splitAcfFieldsByLanguage()`, which
applies the same shape test but only keeps active WPML languages as translations. If you need
per-language resolution anywhere else, reuse these functions instead of writing a copy. Three
diverging copies are what caused this defect in the first place.

**Why a list of codes and not a pattern.** A pattern such as "any 2-3 letter key" mistook groups
like `{"sku": "ABC", "alt": "text"}` or `{"lat": …, "lng": …}` for language maps and cleared them.
The ISO list keeps a language the site has not activated (`es` on an it/en site) recognized. On
the post and WooCommerce paths, `resolveFields()` therefore resolves a map carrying only inactive
languages to `null`, so it clears the field instead of being written raw. The term path differs:
`splitAcfFieldsByLanguage()` finds no active language in such a map, puts it in `shared`, and
the map is written raw into the field. Without WPML, a real language map still returns
`wpml_required`. The offline test
`MultiLangResolveFields.php` covers both sides (see [Tests](../../CONTRIBUTING.md#tests)).

### 3. Fallback language

When a value is missing for the current language, `MultiLang::getValueForLanguage()` uses, in
order:

1. the value for the requested language, if present
2. otherwise, the value for the fallback language: the first translated language found in the
   payload
3. otherwise, the shared value

The fallback only fills languages created by the current request. On update, a language that
already exists in the WPML group ignores a language map without its key and keeps its stored
value: `MultiLang::isMapWithoutLanguage()`, `withoutMapsMissingLanguage()` and
`hasValueForLanguage()` implement that check. Without it, `{"title": {"en": "Red Chair"}}` also
renamed the Italian translation.

### 4. WPML is optional, but required for multilingual payloads

If the payload contains per-language values and WPML is not active, the services do not try to
degrade gracefully. They fail with `500 wpml_required`.

All WPML calls go through the `onpage_` helpers in `src/helpers.php` (`onpage_is_wpml_active()`,
`onpage_get_wpml_default_language()`, `onpage_get_wpml_current_language()`,
`onpage_get_wpml_languages()`). All but the current language are memoized per request.
`Wpml::runWithLanguage()` runs a callback with both the WPML language and the ACF language
switched, and restores them afterwards.

### 5. `local_key`

`local_key` is the external identifier the calling system uses to reconcile objects. It is not a
WordPress ID. It lets the caller find already-synced content when the internal ID is unknown or not
stable from its point of view.

**Accepted values.** A positive integer or a non-empty string.

- `Input::localKey()` normalizes it with `trim`. It rejects `""`, `"0"` and non-scalar values.
- Integers and numeric strings are equivalent, because WordPress meta values are strings anyway.
- On output, `Input::localKeyOut()` returns an integer for integer-canonical keys (backward
  compatibility) and a string for text keys.

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

- The technical meta key is always `onpage_local_key`. This holds for post-based endpoints (posts,
  products, variations) and term-based endpoints (terms, categories, tags, brands, attribute
  terms).
- For WooCommerce global attributes, the technical key is the option name
  `onpage_wc_attribute_local_key_{attribute_id}`.
- `local_key` and `_local_key` are legacy meta keys, once written through an ACF field. The
  plugin never writes them, and no endpoint reads them.
  `POST /migration` renames or cleans them up (see [Migration](#migration-and-indexes)).
- With WPML, the same `local_key` can appear on several records of one translation group. They
  represent the same external object in different languages.

### 6. ACF field names

All ACF writes go through `Acf::updateFieldValue()`. It looks up the field definition from the
field-type map, loaded once per request (`Acf::loadFieldTypeMap()`). Most controllers preload it;
otherwise `Acf::getFieldType()` loads it on the first lookup.

- **Names are matched case-insensitively.** `FieldGroup` saves field names through
  `sanitize_key()`, which lowercases them. The lookup tries the exact name first, then its
  `sanitize_key()` form, so `MyField` in `acf_fields` finds the field stored as `myfield`. Repeater
  rows and groups are re-keyed to the stored sub-field names the same way.
- **`tab` fields** are skipped.
- **`image` and `file` fields** accept an existing attachment ID (an integer or a numeric string)
  or a URL. A URL is imported through `RemoteMedia` (see [Media](#media-and-remotemedia)). `0` and
  `"0"` clear the field, as ACF stores an empty media field. Any other value fails with
  `400 input_invalid`: `Field '<name>' in acf_fields must be an existing
  attachment ID or a valid URL`. Inside a repeater or a group the name is the path, for example
  `rows[0][image]` or `datasheet[image]`.
- **Repeaters** must be a list of row objects (`400 invalid_param` otherwise). **Groups** must be
  an object (`400 invalid_param`, `Group '<name>' must be an object`). Both share
  `Acf::resolveSubFieldValues()`: a repeater row and a group are resolved the same way, with
  nested repeaters and groups handled recursively and `null`/`""` sub-values passed through.
- **Flexible content** must be a list of rows naming a layout in `acf_fc_layout`
  (`400 invalid_param` otherwise). Each row is resolved with `resolveSubFieldValues()` against its
  layout's sub-fields.
- **An unknown field** fails with `400 invalid_param` (`ACF field '<name>' not found for <context>
  '<target>'`), but only when the caller passes both the context and the target (post type or
  taxonomy). Without them, the value is written with `update_field()` under the field key, if one
  is found, or the name as sent. The fields of a post type or taxonomy come from the location rules of every field
  group (`Acf::getFieldGroupScopes()`). ACF shows a group when any rule group matches, and a rule
  group when all its rules do, so each rule group is resolved on its own: `==` narrows the
  registered post types or taxonomies, `!=` removes one, `all` keeps them all, a post rule without
  `post_type` (`post_template`, `post_category`…) matches every post type, and a page rule matches
  `page`.

## `FieldGroup.php`: ACF field groups

### Upsert

`FieldGroup::insertFromParams()` creates or updates one group. `title` is required
(`400 missing_title`). An existing group is found by `key` if sent, otherwise by `title`. Without a
`key`, the service reuses the matched group's key or derives one from the title
(`group_<slug>`), and adopts a group that already carries that derived key.

### Fields

`fields` controls what happens to the group's fields:

- missing or `null`: the existing fields are kept, so a call that only changes the title or the
  description cannot wipe them;
- `[]`: every field of the group is deleted;
- anything that is not a list: `400 invalid_param`, `FieldGroup :: Parameter 'fields' must be a
  list`.

`FieldGroup::persistFields()` writes the fields one level at a time:

1. It validates and normalizes the whole level first. A malformed entry (not an object, no `key` or
   `name`) stops the request while the group is still intact.
2. It saves each field with `acf_update_field()`, reusing the existing field's key and ID when a
   field with the same name exists.
3. It recurses into `sub_fields`, parent before children. A field sent without `sub_fields`, or
   with `[]`, keeps the sub-fields it already has.
4. It deletes the fields of that level (existing fields under the same parent) whose name the
   payload no longer contains. Deleting a field deletes its sub-fields too.

Field names are saved through `sanitize_key()`, types too. The field key is generated
(`field_<uuid>`) the first time and kept afterwards.

### How ACF persists sub-fields

This is worth knowing: misunderstanding it silently loses the sub-fields of `group` fields.

ACF stores **every** field, sub-fields included, as its own `acf-field` post. Its `post_parent`
points to the field above it. `acf_update_field()` saves one field and stops; it does not descend
into nested `sub_fields`.

Passing it a field with sub-fields inside therefore created the parent and no children. It left a
serialized copy of the sub-fields inside the parent's `post_content`, where ACF never looks: a
`group` reloads its sub-fields with `acf_get_fields()`, which reads the child posts.

The symptom was a group that returned `200`, then read back with all its sub-fields collapsed onto
the empty key. The reason: the group's `format_value()` indexes the result by
`$sub_field['_name']`, which does not exist in that serialized copy. A repeater defined the same
way appeared to work, and that is what kept the problem hidden.

`persistFields()` saves one level at a time for this reason. This is the same flattening ACF's own
import does. Anyone adding a field type with children must go through it.

**Flexible content** follows the same rule. `buildLayouts()` stores the `layouts` on the field
without their `sub_fields`, keyed by layout key, and keeps each layout's key when its `name`
already exists. `persistLayouts()` then saves each layout's `sub_fields` as children of the
flexible field, tagged with `parent_layout`, and deletes the children of layouts the payload
dropped. Existing children are matched per layout, since two layouts can have sub-fields with the
same name.

### WPML options

When WPML is active, `FieldGroup.php` sets:

- `acfml_field_group_mode = advanced` on the group (ACFML "Expert" mode)
- the WPML translation preference `wpml_cf_preferences = 2` ("Translate") on every field and
  sub-field that holds a value. Layout-only types (`tab`, `message`, `accordion`) are skipped. A
  `wpml_cf_preferences` value sent in the payload wins.

After the save, `syncTranslationPreferences()` re-applies the preference to every field already
stored in the group, including fields the payload did not send.

Rationale: the importer writes every language itself. WPML must therefore never copy values from
the default language onto the translations. The other ACFML modes let ACFML reset the preferences
to its own "copy" defaults on every group save.

### Delete

`deleteById()` and `deleteByTitle()` delete one group with `acf_delete_field_group()`.

## `PostType.php`: ACF post types

`PostType::saveFromParams()` upserts one post type through `acf_update_post_type()`. The ACF key is
`post_type`. An existing post type with that key is updated.

- `post_type` must already be a valid key: non-empty, and unchanged by `sanitize_key()` (lowercase
  letters, digits, `_` and `-`). Otherwise the request fails with `400 invalid_param`. The ACF key
  and the registered post type name are therefore always identical.
- Defaults: public, shown in the UI and in REST, `supports` = title, editor, thumbnail, revisions,
  icon `dashicons-admin-post`. `rewrite_slug` sets a custom permalink slug.
- The controller flushes rewrite rules once per batch, for both `POST` and `DELETE`, even when an
  element fails.

`DELETE /post-types` deletes by ACF ID (integer) or key (string, passed through `sanitize_key()`).

## `Taxonomy.php`: ACF taxonomies

### Upsert

`Taxonomy::saveFromParams()` upserts one taxonomy by its ACF `key` through `acf_update_taxonomy()`.
Most WordPress taxonomy arguments are accepted and sanitized (see `buildTaxonomyData()`).

- `key` is also the taxonomy slug. It must already be the form WordPress stores: unchanged by
  `sanitize_key()` and at most 32 characters. Otherwise `400 invalid_param`. This is the same rule
  as `POST /post-types`.
- `singular_label` and `plural_label` are required (`400 invalid_param`, `Parameter '<label>' is
  required`). For a language map, the label used is the default-language value, else the first
  scalar value, and it must not be empty.

- `capabilities.delete_terms` is the capability key. The camel-case `deleteTerms`, which older
  versions wrote and WordPress ignored, is still accepted as input.
- The taxonomy is marked translatable in WPML (`taxonomies_sync_option`).
- The controller flushes rewrite rules once after the batch, for both `POST` and `DELETE`. Unlike
  `PostType`, there is no `finally`: when an element fails, the rules are not flushed.

### Translated labels

`singular_label` and `plural_label` accept a language map. The default-language value becomes the
registered label. The maps are stored in the option `onpage_taxonomy_label_translations` and pushed
to ACFML string translation when it is available.

`Taxonomy::boot()` hooks `registered_taxonomy`. Whenever a taxonomy with stored maps is registered,
it swaps its `singular_name`, `name` and `menu_name` labels for the current language (then the
default language, then any language). `GET /taxonomies` returns the stored maps in place of the
plain labels.

### Delete

`DELETE /taxonomies` accepts an ACF ID or a slug. `deleteBySlug()` looks the slug up exactly as
sent first, then in its `sanitize_key()` form. A taxonomy saved before keys were validated may hold
a key such as `Brand`, which `sanitize_key()` would turn into another slug.

For a taxonomy registered through ACF it:

1. deletes every term of the taxonomy (`wp_delete_term()`; a `WP_Error` fails with
   `500 delete_failed`). A taxonomy saved with `active: false` is not registered, and
   `wp_delete_term()` refuses it, so it is registered temporarily for the delete. A term that no
   longer exists (for example removed by WPML with its original) is skipped;
2. deletes the ACF taxonomy;
3. deletes the ACF field groups whose location is `taxonomy == <slug>`;
4. removes the stored label translations and the WPML sync setting.

A slug that is not an ACF taxonomy (for example `product_cat`) is `404 not_found`. With `?ignore`
it does nothing at all: the field groups and WPML settings of a taxonomy the plugin does not own
stay untouched.

## `Post.php`: business logic

### Purpose

`Post.php` processes batches of posts (create, update, delete). It tries to keep the following
consistent:

- the base post
- the translated posts
- ACF fields
- taxonomies
- the WPML mapping

### Main helpers

| Helper | Where | What it does |
|---|---|---|
| `splitValueByLanguage()` | `MultiLang` | Splits `title`/`content`/`description` into shared and translated. |
| `getValueForLanguage()` | `MultiLang` | Resolves the value for one language, with the fallback chain. |
| `resolveFields()` | `MultiLang` | Resolves an `acf_fields` or `files` map for one language. |
| `runWithLanguage()` | `Wpml` | Runs code with the WPML and ACF languages temporarily switched. |
| `findPostsByLocalKey()` | `PostRepository` | Finds the posts holding a `local_key`, ordered by ID. Trashed ones are included unless `$include_trashed` is `false`. |
| `findUpsertPostId()` | `Post` | Picks the post an upsert by `local_key` updates. |
| `pickCanonicalPost()` | `Post` | Among the holders of one key, prefers the default-language post, then an original, then the lowest ID. |
| `resolveUpdatePostTitle()` | `Post` | Decides the final title for one language on update. |
| `getPostLanguageDetails()`, `getPostTrid()`, `getTranslationPostIds()`, `setPostLanguage()` | `Post` | Read and write the WPML language, `trid` and `language_code => post_id` map of a post. |
| `setTerms()` | `Post` | Assigns terms, translating them per language when needed. |

### Insert or update?

The REST entry point is `Post::save()`. For each payload item, in this order:

1. **Explicit `id`.** The post must exist, otherwise `404 no_post`. If another post of the same
   type, outside this post's WPML group, already holds the payload `local_key`, the request fails
   with `409 duplicate_local_key`. Otherwise the post is updated.
2. **`local_key` match.** `findUpsertPostId()` looks the key up, scoped to `type` when sent. If the
   key matches posts of more than one type and no `type` was sent, the request fails with
   `409 ambiguous_local_key`. Otherwise the canonical post of the group is updated.
3. **Otherwise** a new post is inserted.

`local_key` is required on every write (`400 invalid_param`).

Before any of this, `requireValidStatus()` checks a `status` that is sent. It must be a registered
post status other than `trash`, `auto-draft` and `inherit`, which WordPress manages itself.
Anything else is `400 invalid_param` (`Parameter 'status' must be one of: ...`). WordPress would
otherwise store any string, and `trash` would put the post in the bin without its trash metadata.

### Trashed posts

Write lookups by `local_key` include the trash: a trashed element still owns its key. This applies
to the upsert, to the delete by `local_key` and to the insert duplicate check. `DELETE /posts` by
post type also deletes trashed posts. Title lookups (`findIdsByTitle()`) still exclude the trash.

Reads by `local_key` exclude the trash, because a trashed post is not live content.
`Post::requirePostByLocalKey()` (used by `GET /posts/{id}?keyfield=local_key`) passes
`$include_trashed = false`, so it answers `404 no_post` for a key held only by trashed posts. This
matches `GET /posts?local_key=`, whose default `status=any` leaves the trash out.

The upsert resolves to a trashed post only when no live post holds the key.
`findUpsertPostId()` drops the trashed holders whenever a live one exists, so re-importing never
restores a trashed duplicate next to a live post.

When an upsert does resolve to a trashed post, `restoreTrashedPosts()` takes it out of the trash
with `wp_untrash_post()`, together with the trashed translations of its group that hold the same
key. This runs after validation (step 4 below), so a rejected update leaves the posts in the trash.
The payload `status` then applies. Without a `status` the post keeps the status WordPress restores
it to (`draft`). Trashed posts of other groups that hold the same key stay in the trash.

## `Post::update()` flow

### 1. Initial validation

The method:

- loads the post (`404 not_found` if it disappeared)
- rejects a `type` different from the post's own type with `400 invalid_param` (`... the post type
  cannot be changed`). `type` identifies the post; it never retypes it.
- removes WPML language slots whose post no longer exists (`Wpml::deleteOrphanPostTranslations()`)
- checks that the post type is still registered (`404 not_found`)

### 2. Payload normalization

The payload is read conservatively. The following rule applies to `title`, `content`,
`description`, `acf_fields`, `files`, `term` and `status`:

- present and not `null`: treated as an explicit change
- missing or `null`: treated as "no change"

`term` takes precedence over the legacy `terms` key when both are sent.

Only the text fields actually present are turned into `title_map`, `content_map` and
`description_map`. The service then builds `translated_languages`, `fallback_language`, the post's
`current_language`, and `translation_ids`: the map of the post's existing translations.

### 3. WPML rule

If the payload contains translated data but WPML is not active, it fails with `wpml_required`.

### 4. Title uniqueness

If the payload contains `title`, the service checks that the final title of each language is not
already used by another post of the same type. A post in the same WPML group, or one that carries a
different `local_key`, is not a conflict: see [the title rule](#the-title-is-a-near-unique-key).

- if the post has translations, it checks the resolved title of every language the title covers
- if it has no translations, it checks only the current post, and only when the title changes
- a language left out of a title map keeps its title, so it is not checked

### 5. Updating the main post

The service first restores the trashed members of the group (see [Trashed posts](#trashed-posts)).
It prepares the `wp_update_post()` data with `ID`, `post_type` and `post_status` (the
payload status or the current one). It adds `post_title`, `post_content` and `post_excerpt` only
for the fields actually sent, and only when the value is shared or its map contains the post's
language.

The main post is updated in its own current language, not automatically in the default language.

### 6. ACF, files and terms on the main post

If `acf_fields` or `files` is present, the service drops the language maps without
`current_language`, resolves the rest for that language and saves them through `Acf::updateFieldValue()`. If `term` is present, it assigns the terms with
`wp_set_object_terms()`.

### 7. Updating the translations

For each post in `translation_ids` other than the current one, the service, inside
`Wpml::runWithLanguage($language_code, ...)`:

- updates only the text and status fields actually present in the payload, skipping a map that
  does not contain the language
- saves the ACF fields and files for that language, skipping maps that do not contain it
- assigns the terms, translated into the target language

### 8. Creating missing translations

`insertMissingPostTranslations()` creates the payload languages that are not in the WPML group yet.
This covers a post imported before the language existed, or one whose translation was deleted.
Fields absent from the payload start from the source post's own values. If the post has no group
yet, declaring its own language creates one.

### 9. `local_key`

Finally `local_key` is written on the post, on every existing translation and on the new ones.

### Result

`update()` returns the updated post's ID. Any failure throws an `HttpException`.

When the payload contains multilingual data, updating a post affects the whole translation group,
not just the current record.

## `Post::insert()` flow

### 1. Resolving the context

`type` and `title` are required (`400 invalid_param`, `Parameter 'type' is required` or
`Parameter 'title' is required`). `title` must be a non-empty string or a non-empty language map.
The post type must be registered (`404 not_found`, `Post :: PostType '<type>' not found`).

The method builds the language list from `title`, `content`, `description`, `acf_fields` and
`files`. The base language used to create the original post is:

- `onpage_get_wpml_default_language()`;
- or, when `title` is a language map that has no title for the default language, the first
  language that has one (the fallback language), as for terms;
- or, if there is no default language, the first translated language in the payload.

The fallback language is the first language with a non-empty title, else the first translated
language. `WooCommerce\Product::getLanguageContext()` applies the same rule to the product name.

### 2. Validation rules

Before creating anything:

- If there are translations but WPML is not active: `wpml_required`.
- If the original post's title already exists on a post **without a `local_key`** (or with the
  same one): `409 duplicate_title`. A post carrying a different `local_key` is a different
  On Page® element and does not conflict.
- If `local_key` already exists on the same post type, trash included: `409 duplicate_local_key`.

### 3. Creating the original post

The original post is created with the title, content and excerpt resolved for the base language,
`post_type` and `post_status` (`draft` by default). Then:

- `local_key` is saved at once as meta `onpage_local_key`, before the slow media and ACF work. A
  failure the rollback cannot catch (a PHP timeout) then leaves an adoptable post, not an unkeyed
  one that blocks every later import with `duplicate_title`.
- if there is a base language, the post gets that WPML language
- the base-language ACF fields, files and terms are saved

### 4. Creating the translations

If WPML is active and the payload has translated languages, `insertTranslatedPosts()` reads the
original post's `trid` and, for each translated language other than the base language:

- resolves title and content for that language, and checks that the title is not a duplicate
- creates a new post and saves `local_key` on it at once
- links it to the original's `trid` with `source_language_code = base language`
- saves ACF fields, files and terms inside `Wpml::runWithLanguage()`, so term slugs and ACF values
  resolve in the translation's language

### 5. Cleanup on failure

If anything fails after the original post exists, the service deletes every post it has created in
this call: the original and each translation created so far. Then it re-throws the error.

So `insert()` behaves like a pseudo-atomic operation, even though it does not use real SQL
transactions.

### Result

`insert()` returns the original post's ID. Any failure throws an `HttpException`.

## Deleting posts

`DELETE /posts` accepts, per element:

- an integer: a post ID
- a string: a post type slug, which deletes every post of that type, trash included
- an object with `local_key` (optionally `type`), `id` or `type`
- with `?keyfield=local_key`, a plain `local_key` value

For an object, a present identifier must be usable. A `local_key` that is present (not `null`) but
invalid is `400 input_invalid`. With the default keyfield, so is an `id` that is present but not a
positive JSON integer. Only an object with no usable identifier falls back to `type` and deletes
every post of that type. Otherwise a typo in the identifier would wipe the whole post type.

Posts are deleted permanently (`wp_delete_post($id, true)`). A `local_key` that matches posts of
more than one type without a `type` fails with `409 ambiguous_local_key`. With `?ignore`, a missing
ID or key is skipped. The post type string is always checked with `post_type_exists()`: without
`?ignore` an unregistered type fails with `404 not_found`, with `?ignore` it is skipped and nothing
is deleted. An element of the wrong type fails with `400 input_invalid`
(`Post :: Element N :: Invalid delete value; …`).

Deletes tolerate posts that WPML has already removed. With WPML's "delete translations as well"
option, deleting one member of a group removes the others. The delete by `local_key` therefore
skips members that no longer exist, once the group was found, and the delete by post type skips
IDs that are gone. `Term::deleteById()` has a `$skip_missing` flag for the same case.

## Key business rules in `Post.php`

### The title is a near-unique key

The service treats the title as a near-unique key within a post type. The guard exists for one
case: a post created by hand on the site, or left behind by a failed import, that has no
`local_key`. Importing over it would silently produce two posts with the same title.

Posts that carry a different `local_key` are different On Page® elements and may share a title
(`PostRepository::carriesForeignLocalKey()`).

### `local_key` drives the upsert

For posts, `local_key` is required and is persisted in `wp_postmeta` (meta key
`onpage_local_key`). An explicit `id` wins; otherwise the post found by `local_key` is updated.

### `term` is fully realigned when present

When `term` is present, the service does not merge incrementally. It resolves the received
references and assigns exactly that set to the post, per taxonomy. A reference is resolved as a
term `local_key` first, then as a slug. An unknown reference fails with `404 input_invalid`.

When `term` is missing entirely on update, existing assignments are kept.

### ACF per language

An ACF field can be shared or specific to one language. For each translated post, the service
builds the final ACF payload by combining the shared values with that language's overrides.

## `Term.php`: business logic

### Purpose

`Term` manages taxonomy terms with multilingual and ACF support. The same service serves
`/terms` and, through thin wrappers, the WooCommerce categories, tags, brands and attribute terms.

`Term::save()` is an upsert:

- if the term exists, it updates it
- if it does not exist, it creates it

## `Term::save()` flow

### 1. Resolving the taxonomy

The taxonomy comes from the payload (`/terms`) or from the route (WooCommerce endpoints). It can be
a slug or an ACF taxonomy ID. `requireTaxonomySlug()` resolves it to a real WordPress taxonomy
slug.

### 2. Payload normalization

For each batch item, `parseTermPayload()` builds `name_map`, `slug_map`, `description_map`,
`acf_field_map`, `local_key`, the requested `id` and the parent. It then extracts
`translated_languages`, `fallback_language` and `base_language`.

`base_language` is:

- the WPML default language, if there is one
- otherwise, or when `name` is a language map with no entry for the default language, the first
  language in the payload that carries a name

### 3. Initial validation

- A present `local_key` must be valid (`400 invalid_param`).
- `parent` must be a term ID (a positive integer), `0` or `null` (`400 invalid_param`). `0` and
  `null` mean a root term. `id` and `parent` are read with `Input::positiveInt()`, never cast.
- If there are translations but WPML is not active: `wpml_required`.
- If there is translated content but the base language cannot be determined: `500 wpml_error`.
- The name in the base language is required (`400 invalid_param`).
- If an `id` is sent, the term must exist in the taxonomy (`404 not_found`).
- If both `id` and `local_key` are sent and the key belongs to a term outside the requested term's
  translation group: `409 duplicate_local_key`.
- A numeric `parent` must exist in the taxonomy (`404 not_found`). Without this check,
  `wp_insert_term()` would fail with `missing_parent` as `500 request_failed`.
- A parent `local_key` must exist (`404 not_found`). Only the WooCommerce term services send one,
  through the internal key `__parent_local_key`: `WooCommerce\Term::prepareParentPayload()` and
  `WooCommerce\Brand::prepareParentPayload()` set it when the client's `parent` is a `local_key`.
  It is never taken from the client: both methods unset it first, and the `/terms` controller
  unsets it from every element.

### 4. Create or update?

The base term is chosen as follows:

1. use `id`, if present
2. otherwise, use the term found by `local_key`
3. if the payload is multilingual, convert the ID to the matching term in the base language

`upsertTermData()` then looks for a term that already owns the payload slug (see
[slug ownership](#slug-ownership)). If a term ID exists at the end, the service calls
`wp_update_term()`. Otherwise it calls `wp_insert_term()`.

### 5. Saving the base term

After creating or updating the base term, the service:

- saves `local_key` as term meta `onpage_local_key`
- gives the term the base language, if it has none yet
- saves the base-language ACF fields through `Acf::updateFieldValue()`, which imports `image` and
  `file` URLs (see [ACF field names](#6-acf-field-names))

### 6. Initializing WPML

If the payload contains translations, the service:

1. reads the base term's language details
2. if the term has no `trid` yet, assigns it the base language
3. reads the WPML details again
4. if there is still no `trid`, fails with `500 wpml_error`

### 7. Upserting the translations

For each language other than the base language, the service:

- skips the language if the translated name is missing
- finds the existing translation: first by `local_key` in that language, then in the WPML group
  (converting its `term_taxonomy_id` to a `term_id`)
- otherwise adopts a same-language term with the payload slug, but only when no other `local_key`
  owns it
- updates the translated term if it exists, otherwise creates it and links it to the base term's
  `trid`
- saves `local_key` and the ACF fields for that language

Finally `local_key` is written on every member of the translation group, including translations
the payload did not mention.

### Result

`Term::save()` returns the base term's ID. Any failure throws an `HttpException`.

## Key business rules in `Term.php`

### `local_key` is the main reconciliation key

For terms, the flow is driven far more by `local_key` than by `id`. The value is saved in
`wp_termmeta` with meta key `onpage_local_key` and propagated to every WPML translation. The same
`local_key` may represent translations of one concept, but never two unrelated terms.

### Slug ownership

A slug belongs to one term only within a taxonomy. The service never lets a slug collision
overwrite a term that belongs to another element:

- **The element already has a term** (found by `id` or `local_key`). A term found by the payload
  slug replaces it only when it is the same term, in the same WPML translation group, or carries
  the same `local_key` (`slugTermBelongsToElement()`).
- **The element has no term yet.** A term found by slug is adopted only when it has no `local_key`
  or the same one (`termMatchesLocalKey()`). An unowned term, for example one auto-created by a
  product import, is taken over.
- **The slug is owned by another element.** The element's own term is updated but keeps its current
  slug. The other term is not touched. There is no error: the response is `200`.
- **A new term collides with an owned slug.** A distinct term is created instead: WordPress
  generates the slug, or `insertTermWithUniqueSlug()` tries `<slug>-<language>` and numeric
  suffixes when the name collides too. Without WPML, the `duplicate_term_slug` retry never
  overwrites the slug owner either.

### The translation group is keyed on `term_taxonomy_id`

WPML does not link terms by `term_id`. It links them by `term_taxonomy_id`. That is why the service
has the helpers `getTermTaxonomyId()` and `getTermIdByTaxonomyId()`, backed by `TermRepository`.

### Languages without `name` are skipped

If the name is missing for a translated language, that translation is neither created nor updated.

### Delete

`DELETE /terms` mirrors `DELETE /posts`. An element is a term ID, `{"id": …}` or
`{"local_key": …}`, with an optional `taxonomy` in the object that overrides `?taxonomy=`. With
`?keyfield=local_key`, plain values are read as local_keys. Otherwise a plain string is always an
ID.

- **By ID.** The ID is validated with `Input::strictPositiveInt()`. The controller loads the term
  with `get_term()`, scoped to the taxonomy when there is one. A missing term, or one outside that
  taxonomy, is `404 not_found`, or skipped with `?ignore`. Only that term is deleted.
- **By `local_key`.** `Term::deleteByLocalKey()` finds every term holding the key, through
  `TermRepository::findTermIdsByLocalKey()` (one taxonomy) or
  `TermRepository::findTermsByLocalKeyInAnyTaxonomy()` (no taxonomy). Every translation carries the
  key (`propagateLocalKeyToTranslations()`), so this is the whole WPML group. Without a taxonomy, a
  key held in more than one taxonomy fails with `409 ambiguous_local_key`. Each term is deleted
  with `$skip_missing`, because WPML's "delete translations as well" option may already have
  removed it with the original.
- An object with a present but invalid `local_key`, `id` or `taxonomy`, or with no identifier, is
  `400 input_invalid`. It never falls back to another identifier.

`Term::deleteById()` calls `wp_delete_term()`. A `WP_Error` or `false` result fails with
`500 delete_failed`, with the WordPress error message appended.

## `Post.php` vs `Term.php`

| Aspect | `Post.php` | `Term.php` |
|---|---|---|
| Create/update strategy | `save()` picks update by `id`, then by `local_key`, else insert. | `save()` is a single upsert, using `id` or `local_key`, then the payload slug. |
| External key | Required. Read from `wp_postmeta`, meta key `onpage_local_key`, trash included. | Optional but central. Read from `wp_termmeta`, meta key `onpage_local_key`. |
| Translation group update | Updates the current post, then every known translation, then creates the missing ones. | Upserts the base term, then upserts the translations one by one. |
| Failure | An insert rolls back every post it created. An update leaves what it wrote. | No rollback. |

## Media and `RemoteMedia`

### `POST /media`: uploads

`Media::uploadFromRequest()` handles multipart uploads. Each file is stored with WordPress'
`wp_handle_upload()`, so the site's normal upload rules apply. WordPress rejects SVG by default;
when another plugin allows it, `Media::handleUpload()` first sanitizes any `.svg` file in place with
`Svg::sanitizeFile()`, so an SVG is never stored unsanitized. A sanitize failure is
`500 request_failed`. Per file:

- **With `attachment_id`:** the file behind that attachment is replaced. The ID stays the same, the
  old files and sizes are deleted, and the result action is `replaced`.
- **With `token`:** if an attachment already holds that storage segment and its file still exists,
  the upload is dropped and that attachment is returned as `linked`. Otherwise a new attachment is
  created with the token recorded.
- **Otherwise:** a new attachment is created (`created`).

A replacement records the new token, or clears the old one when no token is sent, so a stale token
never points at new content.

### `POST /media/link`: remote files into ACF fields

`Media::linkFromRequest()` takes `post_id` and a `files` map of ACF field name to value. `files`
must be a non-empty JSON object (`400 invalid_param`). Every key must be an ACF field of the post's
type, checked before anything is downloaded (`400 invalid_param`, `ACF field '<name>' not found for
post type '<type>'`). A typo would otherwise import the file and write a stray post meta.

| Value | What happens | `action` |
|---|---|---|
| `null` | The field is cleared. | `cleared` |
| An existing attachment ID | The attachment is linked to the post and written to the field. | `linked` |
| A URL | The file is imported, or an existing import is reused, then linked and written. | `created` or `linked` |

### `GET /media` and `DELETE /media`

`GET /media` filters by `post_id`, `mime_type`, `token` (comma-separated) and `source_url`, and sets
the pagination headers. `post_id=0` lists the media attached to no post. A `post_id` that is
neither `0` nor a positive integer is `400 invalid_param`; it used to be dropped, which listed the
whole library. Each item carries a SHA-256 `hash` of its file. The hash is cached in the
attachment meta `_onpage_file_hash` as `{file, size, mtime, hash}`, and recomputed when the path,
size or modification time changes.

`DELETE /media` reads its body with `Input::requireJsonList()`, like every other batch `DELETE`, so
`[]` is a no-op. It deletes attachments with `wp_delete_attachment()`. With `?ignore`, a missing
attachment is skipped, but its leftover index meta (`_onpage_file_token`, `_onpage_source_url`) is
still removed.

### `RemoteMedia::urlToMediaLibrary()`: remote imports

Every remote file goes through this method: ACF `image`/`file` URLs, `files` on posts, product and
variation images and galleries, `downloads[].file`, category and brand thumbnails, and
`POST /media/link`.

1. **Lookup.** `findAttachmentBySourceUrl()` tries the storage token of the URL first, then the
   exact source URL (meta `_onpage_source_url`).
2. **Dead attachments.** A match whose file is missing from disk is forgotten: its token and source
   URL meta are removed and the lookup runs again.
3. **Reuse.** A live match is returned as `linked`. With `refresh: true` (only `downloads[]`), the
   file is downloaded again and replaces the attachment's file (`replaced`).
4. **Import.** Otherwise the file is downloaded with `download_url()` (45 s timeout by default) and
   sideloaded into a new attachment (`created`). The source URL and the token are recorded.

Every download goes through `RemoteMedia::downloadRemoteFile()`, which adds three guards to core's
`wp_safe_remote_get()`:

- **Public addresses.** `findNonPublicHost()` resolves the host (`gethostbynamel()`) and requires
  every address to pass `FILTER_FLAG_GLOBAL_RANGE`. Core's `wp_http_validate_url()` only blocks
  `127/8`, `10/8`, `0/8`, `172.16/12` and `192.168/16`, so `169.254/16` (cloud metadata) and
  `100.64/10` got through. The site's own host and hosts allowed by `http_request_host_is_external`
  are exempt, as in core. A blocked URL fails with `400 invalid_param`. Each redirect is checked
  the same way from the `requests-requests.before_redirect` action. A blocked redirect aborts the
  download, which then fails with `500 request_failed`.
- **Pinned address.** From `http_api_curl`, the checked IPv4 address is set with `CURLOPT_RESOLVE`.
  cURL then cannot resolve the host again to another address (DNS rebinding, or an internal AAAA
  record). Core passes the original URL to that action on every hop, so the redirect guard records
  the current hop's URL for the pin.
- **Size cap.** `limit_response_size` is set to the cap plus one byte through `http_request_args`.
  A file that reaches it was cut short and is rejected with `413 file_too_large`. The cap is 512 MB
  by default and can be changed with the `onpage_remote_media_max_bytes` filter (`0` or less
  disables it).

### Storage token

`RemoteMedia::tokenFromUrl()` extracts the On Page® storage segment `<token>[.<format>]` from a URL.
It recognizes two shapes only, on the host `onpage.it` or one of its subdomains:

- `https://storage.onpage.it/<segment>/<name>`
- `https://<any>.onpage.it/api/storage/<segment>/<name>`

Any other URL has no token, and deduplication falls back to the exact source URL. Reading a path
segment of any URL would collapse unrelated files onto one attachment
(`storage.example.com/bucket/a.jpg` and `/bucket/b.jpg` would both give `bucket`).

The token is stored in the attachment meta `_onpage_file_token`. A token stored for a non-On Page®
URL is returned by `GET /media`, but no lookup uses it.

### Upload allowlist

The REST request has no logged-in WordPress user, and many hosts narrow the allowed upload types
for anonymous callers. `withUploadableMime()` therefore re-adds the file's standard MIME type to
`upload_mimes` for the duration of one sideload, but only for the extensions in
`RemoteMedia::FORCE_ALLOWED_EXTENSIONS`:

- images: jpg, jpeg, jpe, gif, png, bmp, tif, tiff, webp, avif, heic, heif, ico
- video: mp4, m4v, mov, qt, wmv, avi, mpeg, mpg, mpe, ogv, webm, 3gp, 3gpp, 3g2, 3gp2
- audio: mp3, m4a, m4b, aac, wav, ogg, oga, flac, wma, mka
- documents: pdf, rtf, Microsoft Office, OpenDocument, Apple Keynote/Numbers/Pages
- text: txt, csv, tsv
- archives: zip, 7z, rar, tar, gz, gzip

WordPress still verifies the real bytes. Any other extension (html, js, exe, ...) follows the
site's normal rules, which usually reject it with `500 request_failed` ("Sorry, you are not allowed
to upload this file type").

SVG has its own path: `sideloadWithUploadRules()` sanitizes the file in place (see below), then
allows `image/svg+xml` for that one sideload. The same path runs for `refresh` replacements, so a
replaced SVG is never stored unsanitized. A sanitize failure is `500 request_failed`.

Files uploaded directly to `POST /media` do not go through `RemoteMedia`, so the allowlist does not
apply to them. The SVG sanitizer does: `Media::handleUpload()` runs it before `wp_handle_upload()`.

### `Svg.php`: SVG sanitizer

`Svg::sanitizeFile()` rewrites an SVG file in place, or throws:

- A file with a `DOCTYPE` or `ENTITY` declaration is rejected. The root element must be `svg`.
- Elements are kept only if they are in the SVG namespace (or none) and on `Svg::ALLOWED_ELEMENTS`:
  shapes, text, paint servers, clipping and masking, filters and SMIL animation. Everything else is
  removed, including `script`, `style`, `foreignObject` and all HTML elements. Processing
  instructions are removed too.
- Animation elements (`animate`, `animateMotion`, `animateTransform`, `set`) are removed when they
  target a blocked attribute, so they cannot write back what the sanitizer stripped.
- Attributes named `on*`, `style`, `href` (any prefix, including `xlink:href`), `src`, `action` and
  `formaction` are removed.
- Attribute values are stripped of whitespace and control characters, then removed if they contain
  `javascript:`, `vbscript:`, `livescript:`, `data:` or a `url()` that does not point to a local
  `#` fragment.

Because every `href` is removed, internal references such as `<use href="#icon">` do not survive
sanitizing.

## Migration and indexes

### `POST /migration`

`Migration::renameLocalKeyMeta()` moves post `local_key` meta from the legacy key `local_key` to
`onpage_local_key`:

- A post that already has `onpage_local_key` keeps it. Its legacy row is deleted, not renamed, so
  no post ends up with two key rows. The count is `duplicates_removed`.
- Every other legacy row is renamed. The count is `renamed`.
- The orphaned ACF reference meta `_local_key` is deleted from posts, and both legacy keys are
  deleted from term meta.
- `items` lists every legacy row found, before the changes.

`Migration::backfillMediaTokens()` then writes the storage token on attachments that have a
`_onpage_source_url` but no `_onpage_file_token`. It walks the rows in batches of 500 by `meta_id`,
derives the token with `RemoteMedia::tokenFromUrl()` and skips URLs that carry none. The result is
returned under `media_tokens` as `{scanned, written}`.

Both steps use direct SQL, flush the object cache and are idempotent.

### `DELETE /indexes`

`Index::clearLocalKeys()` deletes `onpage_local_key`, `local_key` and `_local_key` from both post
meta and term meta, then flushes the object cache. It returns `posts_removed` and `terms_removed`.
Use it when the source system regenerates its keys:

- terms become unowned and are adopted again by slug on the next import (see
  [slug ownership](#slug-ownership))
- posts and products must be re-imported; a same-titled post with no `local_key` is then rejected
  with `409 duplicate_title` until it is re-keyed

## Notes for anyone changing these services

### Changing the language logic

Always check:

- what happens when WPML is inactive
- how the base language is resolved
- which fallback is used
- whether ACF correctly follows the current language (`Wpml::runWithLanguage()`)

### Changing the title or slug logic

Watch out for:

- uniqueness checks, and the posts and terms they must skip
- behavior with partial payloads
- propagation to existing translations

### Changing `wpml_get_element_translations`

For posts, the service passes the full WPML `element_type` and requests `all_statuses = true`.
Otherwise posts in `draft` can be missing from the filter's response.

### Changing `local_key`

Remember:

- For generic posts and WooCommerce products/variations, it is always stored in `wp_postmeta` as
  `onpage_local_key`.
- For terms, categories, tags, brands and attribute terms, it is stored in `wp_termmeta` as
  `onpage_local_key`.
- Post lookups for writes include the trash; reads by `local_key` and title lookups do not.
- The legacy meta `local_key` / `_local_key` are no longer written. `POST /migration` renames the
  former to `onpage_local_key` on posts and deletes the redundant copies.
- Changing this logic affects deduplication, lookup and external sync.

### Changing WooCommerce SKUs with WPML

WooCommerce requires globally unique SKUs. Therefore:

- `Product.php` applies `props.sku` to the source product, but not to its translations.
- `VariantProduct.php` applies `props.sku` to the source variation, but not to the translated
  variations.
- Prices and other WooCommerce fields can still be propagated to the translations.

If this rule is removed, WooCommerce can throw `WC_Data_Exception` from `set_sku()` because of a
duplicate SKU.

## Quick mental model

### Post

1. Validate the body and the element.
2. Resolve the target: `id`, then `local_key`, else insert.
3. Discover languages and existing translations.
4. Validate the title and preconditions.
5. Update or create the original.
6. Save ACF fields, files and taxonomies.
7. Create/update the WPML translations.
8. Write `local_key` on the whole group.

### Term

1. Resolve the taxonomy.
2. Normalize the payload.
3. Determine the base language.
4. Find the term by `id` or `local_key`, then check slug ownership.
5. Insert/update the base term.
6. Save meta and ACF fields.
7. Initialize the WPML group.
8. Insert/update the translations.

## Tests

The test suite, how to run it and what each test covers are described in
[CONTRIBUTING.md](../../CONTRIBUTING.md#tests).
