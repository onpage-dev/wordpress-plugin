# Documentation

The documentation is split by audience.

## For site admins: [`user/`](user/)

Plain-language guides. They explain what the plugin does and how to set it up.

| Document | What it covers |
| --- | --- |
| [user/guide.md](user/guide.md) | what the plugin is, requirements, setup, API token, multilingual sites |
| [user/woocommerce.md](user/woocommerce.md) | what the plugin does in a WooCommerce shop |
| [user/troubleshooting.md](user/troubleshooting.md) | common problems and how to fix them |

## For developers: [`dev/`](dev/)

Technical guides. They cover the REST contract, the plugin internals and the design.

| Document | What it covers |
| --- | --- |
| [dev/integration-guide.md](dev/integration-guide.md) | writing an integration: examples, call order, errors. Start here. |
| [dev/internals.md](dev/internals.md) | plugin internals, controller by controller |
| [dev/architecture.md](dev/architecture.md) | architectural decisions and trade-offs |
| [dev/woocommerce.md](dev/woocommerce.md) | how WooCommerce products and their downloads are saved |

### Notes: [`dev/notes/`](dev/notes/)

Point-in-time analyses. They describe the code as it was when they were written, so check their
status section before acting on them.

| Document | What it covers |
| --- | --- |
| [dev/notes/woocommerce-performance-2026-09.md](dev/notes/woocommerce-performance-2026-09.md) | performance analysis of the WooCommerce `POST` endpoints |

## API reference

[API.md](API.md) is the full REST API reference. Its [endpoint index](API.md#endpoint-index) lists every endpoint.
