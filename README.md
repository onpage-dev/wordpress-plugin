# On Page® WordPress Plugin

Lets WordPress receive and sync structured data from **On Page®**, delivered by the On Page®
**Exporter** service.

The plugin:

- exposes a REST API that receives data from Exporter
- creates and updates post types, ACF fields, taxonomies, terms and content
- publishes on WordPress collections that are managed centrally on On Page®
- supports multilingual sites through WPML

## Version

Current version: **1.0.0**. The project follows [Semantic Versioning](https://semver.org/).

- Changes in each version: [CHANGELOG.md](CHANGELOG.md)
- How to cut a release: [RELEASE.md](RELEASE.md)
- Published versions: [GitHub Releases](https://github.com/onpage-dev/wordpress-plugin/releases)

## Requirements

- WordPress 7.1 or later
- PHP 8.2 or later
- [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/), active. Without
  ACF the REST API stays disabled.
- WooCommerce and WPML are optional. You only need them for the WooCommerce endpoints and for
  multilingual content.

## Documentation

Writing an integration? Start with **[docs/DEVELOPER.md](docs/DEVELOPER.md)**. It covers the REST
contract, the order of calls, complete PHP examples and the error reference.

| Document | Who it is for |
| --- | --- |
| [docs/DEVELOPER.md](docs/DEVELOPER.md) | integration authors: examples, call order, errors |
| [docs/API.md](docs/API.md) | full reference, endpoint by endpoint |
| [docs/USER.md](docs/USER.md) | site admins: installation and configuration |
| [docs/DEV.md](docs/DEV.md) | plugin internals, service by service |
| [docs/design.md](docs/design.md) | architectural decisions and trade-offs |
| [docs/Tech.md](docs/Tech.md) | technical analysis of the WooCommerce endpoints |
| [docs/WooCommerce.md](docs/WooCommerce.md) | WooCommerce specifics |
| [docs/PAGINATION.md](docs/PAGINATION.md) | pagination |

## License

[GPL-2.0-or-later](LICENSE), same as WordPress.

## Local environment

The project ships a Docker Compose environment with MySQL, WordPress and Adminer.

| Service | URL |
| --- | --- |
| WordPress | http://localhost:8040 |
| Adminer (database) | http://localhost:8041/?server=mysql&username=wp_user&db=wordpress |
