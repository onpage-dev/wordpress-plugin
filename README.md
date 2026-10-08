# On Page® WordPress Plugin

Lets WordPress receive and sync structured data from **On Page®**. The plugin is a backend for
WordPress: it exposes a REST API and waits for a **client** to push data to it. A client is any
program that calls that API. The plugin never pulls data.

The plugin:

- exposes a REST API that receives data from any client
- creates and updates post types, ACF fields, taxonomies, terms and content
- publishes on WordPress collections that are managed centrally on On Page®
- supports multilingual sites through WPML

Sync goes one way only, from On Page® to WordPress. Changes made in WordPress are not sent back to
On Page®, and the next sync can overwrite them.

## Version

Current version: **1.0.4**. The project follows [Semantic Versioning](https://semver.org/).

- Changes in each version: [CHANGELOG.md](CHANGELOG.md)
- How to cut a release: [RELEASE.md](RELEASE.md)
- Published versions: [GitHub Releases](https://github.com/onpage-dev/wordpress-plugin/releases).
  To install, upload the `onpage-X.Y.Z.zip` attached to a release under **Plugins** in WordPress.
- The plugin is not on wordpress.org, and WordPress never offers updates for it. To update, upload
  the archive of the new release the same way.

## Requirements

- WordPress 7.1 or later
- PHP 8.2 or later
- [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/) 6.1 or later,
  active. Without ACF the REST API stays disabled.
- WooCommerce and WPML are optional. You only need them for the WooCommerce endpoints and for
  multilingual content.

## Documentation

The docs live in [docs/](docs/). See [docs/README.md](docs/README.md) for the full index.

Writing a client? Start with **[docs/dev/integration-guide.md](docs/dev/integration-guide.md)**.
It covers the REST contract, the order of calls, complete PHP examples and the error reference.

| Document | Who it is for |
| --- | --- |
| [docs/user/](docs/user/) | site admins: what the plugin does, installation and configuration |
| [docs/dev/](docs/dev/) | plugin and client developers: internals, design, technical analysis |
| [docs/API.md](docs/API.md) | full REST API reference, endpoint by endpoint |

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the local environment, the tests and how to send a
change. To report a security problem, follow [SECURITY.md](SECURITY.md).

## License

[GPL-2.0-or-later](LICENSE), same as WordPress.
