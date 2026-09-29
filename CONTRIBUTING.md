# Contributing

This guide explains how to set up a local environment, run the tests and send a change.

Before changing the code, read [docs/dev/architecture.md](docs/dev/architecture.md) for the design
and [docs/dev/internals.md](docs/dev/internals.md) for how the services work.

## Local environment

The project ships a Docker Compose environment with MySQL, WordPress and Adminer.

1. Copy `.env.example` to `.env` and fill in the values.
2. Start the containers with `./start`. Stop them with `./stop`, or restart them with `./restart`.
   The scripts work from any directory, for example `~/projects/wordpress-plugin/start`.
3. Open WordPress and complete the installation.
4. Install and activate **Advanced Custom Fields**. Add WooCommerce and WPML if your change
   touches them.
5. Activate **On Page®** under **Plugins**. You do not need to copy or install it: see below.

| Service | URL |
| --- | --- |
| WordPress | http://localhost:8040 |
| Adminer (database) | http://localhost:8041/?server=mysql&db=wordpress |

Both ports are bound to `127.0.0.1` only, so they are not reachable from other machines on your
network.

In Adminer, log in with the `MYSQL_USER` and `MYSQL_PASSWORD` values from `.env`.

### How the plugin gets into WordPress

The container mounts these paths from the repository:

- `./wp-content` becomes the site's `wp-content`. It holds the other plugins, themes and uploads.
  Docker creates it on the first start. It is not versioned.
- `plugin.php` and `src/` are mounted into `wp-content/plugins/onpage/`.
- `config/.htaccess` becomes the site's `.htaccess`, with the rewrite rules WordPress needs for
  pretty permalinks.

So the plugin runs straight from your working copy. Edits to the code are live on the next request,
with no copy or rebuild. The mounts are listed in [docker-compose.yml](docker-compose.yml).

If you add a new file or folder at the root of the plugin, add a mount for it too. Do not mount
the whole repository: it contains `mysql/`, and the container changes the owner of everything
under `wp-content` on start, which would break the database files.

### What reads `.env`

`.env` is read by Docker Compose (the `MYSQL_*` and `HOST_*` variables) and by the tests (the
`WP_TEST_*` variables, through `src/Env.php`). The plugin itself never reads it: `plugin.php` does
not load `src/Env.php`.

The two readers do not agree on comment syntax. If a `#` comment contains any of
`( ) " ! & | $ { } [ ] ~`, PHP's `parse_ini_file()` drops the **whole** file and every variable
reads as empty, with no warning. Keep comments to plain prose. `./bin/test-launcher` checks for this
before it starts and reports it explicitly.

## Tests

The tests live in [src/Tests/](src/Tests/). They are command-line PHP scripts and are not part of
the plugin: `plugin.php` does not include them, and they are left out of the release archive (see
[RELEASE.md](RELEASE.md)).

You need `php` (8.2 or later) on your host `PATH`. The tests run on the host, not in the container.

### Two kinds of tests

| Test | Kind | Needs |
| --- | --- | --- |
| `MultiLangResolveFields.php` | offline | nothing: no site, no `.env` |
| `MultiLangUnsentLanguages.php` | offline | nothing: no site, no `.env` |
| `FieldGroupMalformedFields.php` | offline | nothing: no site, no `.env` |
| `AcfSharedStructuredFields.php` | end-to-end | a site with ACF |
| `DuplicateTitleDistinctLocalKeys.php` | end-to-end | a site with ACF and WooCommerce |
| `ProductLanguageMaps.php` | end-to-end | a site with ACF, WooCommerce and WPML, with `en` (default) and `it` active |
| `ProductUpdateUnsentValues.php` | end-to-end | a site with ACF and WooCommerce |
| `TermVariationUnsentLanguages.php` | end-to-end | a site with WooCommerce and WPML, with `en` (default) and `it` active |
| `TermDeleteByLocalKey.php` | end-to-end | a site with ACF and WPML, with `en` and `it` active |
| `WooCommerceCatalog.php` | end-to-end | a site with ACF, WooCommerce and WPML, with `en` and `it` active |

- **Offline tests** load one class and replace WordPress with small stubs. They run in a second.
- **End-to-end tests** call the REST API of a real WordPress site with the plugin active. Only
  `WooCommerceCatalog.php`, `TermDeleteByLocalKey.php`, `ProductLanguageMaps.php` and
  `TermVariationUnsentLanguages.php` need WPML.

### Running them

For the end-to-end tests, set these in `.env` (see `.env.example`):

- `WP_TEST_URL`: base URL of the test site, without a trailing slash, for example
  `http://localhost:8040`
- `WP_TEST_TOKEN`: the token generated on the **On Page®** admin page of the test site (the value
  of the `onpage_auth_token` option)
- `ONPAGE_TEST_KEEP` (optional): set it to `1` to keep the data the tests create on the site, so
  you can inspect it in wp-admin after the run. You can also set it for one run only:
  `ONPAGE_TEST_KEEP=1 ./bin/test-launcher`. The process environment wins over `.env`.

Then run:

```sh
./bin/test-launcher              # every test
./bin/test-launcher AcfShared    # only tests whose name contains "AcfShared"
php src/Tests/MultiLangResolveFields.php   # one test on its own
```

The launcher works from any directory inside the repository. It runs the tests one at a time, in
alphabetical order, and shows each result. It **stops at the first failure** and returns that
test's exit code. Rationale: the tests share the same site, so continuing on a dirty state would
only produce follow-on errors.

Before any test, the launcher runs **`src/Tests/Gate.php`**. It always runs first, even with a
filter, and it is not counted among the tests. It wipes every WooCommerce entity from the test
site, so each run starts from an empty catalogue:

- products and variations, of any status, trash included
- global attributes, with the terms of their `pa_*` taxonomies
- every term of `product_cat`, `product_tag` and `product_brand`

It deletes everything, not only what the tests create. The only survivor is the default product
category, which WordPress does not allow to delete. The gate then reads every listing again and
fails if anything else is left. It deletes in small batches, so that no call runs into the proxy
timeout of the test site. **If the gate fails, no test runs.** You can also run it on its own
with `php src/Tests/Gate.php`.

Good to know:

- The end-to-end tests create and delete their own data, and the gate empties the whole WooCommerce
  catalogue. **Never point them at a production site.**
- Every end-to-end test follows a create-delete flow: it builds its own fixture (post type, field
  groups, content), runs its checks and removes everything, even when an assertion fails. Cleanup
  also runs before the fixture, so an interrupted run does not block the next one. Cleanup always
  uses `?ignore=1`.
- With `ONPAGE_TEST_KEEP=1` the final cleanup is skipped and the data stays on the site. The
  cleanup before the fixture and the gate still run, so the next run starts clean. The flag is read
  by `src/Tests/Support/Keep.php`.

### Audit log

Each launcher run rewrites `logs/audit.log` with the full HTTP conversation of that run:

- one `[req]` line per call, with method, URL and body
- one `[res]` line per call, with HTTP status, duration and response body

Calls are grouped under the header of the test that made them, and each test ends with an
`[result]` line. JSON bodies are pretty-printed. A response that is not JSON (a PHP fatal
error, an HTML error page) is logged verbatim.

The token is never written: the `Authorization` header appears as `Bearer <hidden>`, so
the file can be attached to a bug report. A test run on its own with `php src/Tests/<Name>.php`
appends to the same file. Writing the log can never make a test fail: if the file is not writable,
logging is silently disabled. `logs/` is in `.gitignore`.

### What each test covers

- **`WooCommerceCatalog.php`** is the baseline. In one pass it does what every import does:
  - it declares the structures: a custom taxonomy, a WooCommerce global attribute, and ACF field
    groups for product, variation, `product_cat`, `product_tag`, `product_brand`, the custom
    taxonomy and the attribute's `pa_*` taxonomy;
  - it publishes data into them: a brand, a parent and a child category, a tag, the attribute's
    values, a custom-taxonomy term, a simple product, a variable product and its variations, each
    with its own ACF values;
  - the data is multilingual: names, descriptions, ACF values and attributes are sent as WPML
    language maps for `en` and `it`, while prices, stock and taxonomy references are shared;
  - it reads every translation back through the `GET` endpoints, checks each one against the value
    of its language, and finally deletes it all.

  The data is made up in the file. Nothing is read from On Page®, so the test runs against a bare
  WordPress install with ACF, WooCommerce and WPML. The languages are set in the `LANGUAGES`
  constant of the test.
- **`AcfSharedStructuredFields.php`** checks that structured ACF values sent as shared on
  `POST /posts` (a repeater, a group, a multi-checkbox) survive the round trip.
- **`DuplicateTitleDistinctLocalKeys.php`** checks that two different On Page® elements with the
  same name can both be imported and re-imported, on `POST /posts` and on
  `POST /woocommerce/products`.
- **`ProductUpdateUnsentValues.php`** checks what a product update does with values it leaves out
  or sends as `null`: an omitted `status` is kept, `null` keeps the enum and boolean `props` (and
  still clears `sku`), and `attributes: null` removes the global attributes too. The last one is
  read through its documented effect, because product attributes are not in the `GET` response:
  the variation built on the removed global attribute becomes `private`.
- **`ProductLanguageMaps.php`** checks how `POST /woocommerce/products` spreads values over the
  WPML translations. On update, an attribute map without `it` rewrites `en` and keeps the Italian
  attribute. Product attributes are not in the `GET` response, so this is read through
  variations: each translated variation is checked against the options of its own parent. A payload with scalar values only creates one product in the default
  language, and keeps it that way on update. A scalar `name` beside a `short_description` map is
  the name of both translations, and a later scalar-only update writes every translation without
  creating new ones.
- **`TermVariationUnsentLanguages.php`** checks that an update of a term or a variation writes
  only the languages each map contains. On `POST /woocommerce/categories`, a `description` map
  without `it` keeps the Italian description, and an omitted `description` keeps both. On
  `POST /woocommerce/variant-products`, a `description` or `attributes` map without a language
  keeps that language's values, the parent's language included, while a shared price reaches both
  translations.
- **`TermDeleteByLocalKey.php`** checks that `DELETE /terms` with an ID deletes that term only,
  while a `local_key` deletes every WPML translation. It checks the translation group both on
  `category` and on a custom ACF taxonomy that it creates, where the taxonomy is resolved from the
  `local_key`. It also covers `?keyfield=local_key`,
  `409 ambiguous_local_key` for a key held in two taxonomies, and the `400` validation errors.
- **`MultiLangResolveFields.php`** covers the shared-value rule offline, directly on
  `MultiLang::resolveFields()`. It checks both sides of the language-code rule described in
  [internals.md](docs/dev/internals.md#2-shared-vs-translated): real language maps, including
  languages the site has not activated, are resolved; groups with short keys that are not
  languages (`sku`/`alt`, `lat`/`lng`, `cta_url`) reach the field untouched.
- **`MultiLangUnsentLanguages.php`** checks offline the update rule for language maps that leave
  a language out: the helpers in `MultiLang` must skip such a map for that language, so an existing
  translation keeps its value.
- **`FieldGroupMalformedFields.php`** checks offline that a malformed `fields` payload on
  `FieldGroup::persistFields()` stops the request before anything is written or deleted.

## Making a change

1. Create a branch from `main`.
2. Keep each commit focused on one change. Start the commit message with a tag, for example `#feat`
   for a new feature or `#ref` for a refactor or a docs change.
3. Run the tests.
4. Add an entry to [CHANGELOG.md](CHANGELOG.md) under an `## [Unreleased]` section.
5. Open a pull request against `main`.

## Documentation

Update the docs in the same change as the code:

- If you add or change an endpoint, update [docs/API.md](docs/API.md): the route, its parameters,
  the response shape and the error codes. Add new endpoints to its endpoint index too.
- If you add, rename or remove a guide, update [docs/README.md](docs/README.md).
- Write in English. The full style rules, and the table of which document to update for which
  change, are in [AGENTS.md](AGENTS.md). They apply to people as well as to AI agents.

## Releases

Releases are cut by the maintainers. The procedure is in [RELEASE.md](RELEASE.md).
