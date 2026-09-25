# Contributing

This guide explains how to set up a local environment, run the tests and send a change.

Before changing the code, read [docs/dev/architecture.md](docs/dev/architecture.md) for the design
and [docs/dev/internals.md](docs/dev/internals.md) for how the services work.

## Local environment

The project ships a Docker Compose environment with MySQL, WordPress and Adminer.

1. Copy `.env.example` to `.env` and fill in the values.
2. Start the containers with `./start`. Stop them with `./stop`, or restart them with `./restart`.
3. Open WordPress and complete the installation.
4. Install and activate **Advanced Custom Fields**. Add WooCommerce and WPML if your change
   touches them.
5. Activate **On Page®** under **Plugins**. You do not need to copy or install it: see below.

| Service | URL |
| --- | --- |
| WordPress | http://localhost:8040 |
| Adminer (database) | http://localhost:8041/?server=mysql&db=wordpress |

In Adminer, log in with the `MYSQL_USER` and `MYSQL_PASSWORD` values from `.env`.

### How the plugin gets into WordPress

The container mounts two things from the repository:

- `./wp-content` becomes the site's `wp-content`. It holds the other plugins, themes and uploads.
  Docker creates it on the first start. It is not versioned.
- `plugin.php`, `routes.php` and `src/` are mounted into `wp-content/plugins/onpage/`.

So the plugin runs straight from your working copy. Edits to the code are live on the next request,
with no copy or rebuild. The mounts are listed in [docker-compose.yml](docker-compose.yml).

If you add a new file or folder at the root of the plugin, add a mount for it too. Do not mount
the whole repository: it contains `mysql/`, and the container changes the owner of everything
under `wp-content` on start, which would break the database files.

### Comments in `.env`

`.env` is read by two programs: Docker Compose and the plugin (through `parse_ini_file`). They do
not agree on comment syntax. If a `#` comment contains any of `( ) " ! & | $ { } [ ] ~`, PHP drops
the **whole** file and every variable reads as empty, with no warning. Keep comments to plain prose.

## Tests

The tests live in [src/Tests/](src/Tests/). They are end-to-end tests: they call the REST API of a
real WordPress site.

1. Set `WP_TEST_URL` and `WP_TEST_TOKEN` in `.env`. The token is the one generated on the
   **On Page®** admin page of the test site.
2. Run the tests:

   ```sh
   ./bin/test-launcher              # every test
   ./bin/test-launcher AcfShared    # only tests whose name contains "AcfShared"
   ```

Good to know:

- The tests create and delete their own data. **Never point them at a production site.**
- The run stops at the first failing test. Later tests share the same site, so their failures
  would only be side effects.
- Each run rewrites `logs/audit.log` with the full HTTP conversation of the last run.

## Making a change

1. Create a branch from `master`.
2. Keep each commit focused on one change. Start the commit message with a tag, for example `#feat`
   for a new feature or `#ref` for a refactor or a docs change.
3. Run the tests.
4. Add an entry to [CHANGELOG.md](CHANGELOG.md) under an `## [Unreleased]` section.
5. Open a pull request against `master`.

## Documentation

Update the docs in the same change as the code:

- If you add or change an endpoint, update [docs/API.md](docs/API.md): the route, its parameters,
  the response shape and the error codes. Add new endpoints to its endpoint index too.
- If you add, rename or remove a guide, update [docs/README.md](docs/README.md).
- Write in English. The full style rules are in [AGENTS.md](AGENTS.md). They apply to people as
  well as to AI agents.

## Releases

Releases are cut by the maintainers. The procedure is in [RELEASE.md](RELEASE.md).
