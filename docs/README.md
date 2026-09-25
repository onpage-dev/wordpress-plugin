# Documentation

The documentation is split by audience.

## For site admins: [`user/`](user/)

Plain-language guides. They explain what the plugin does and how to set it up.

| Document | What it covers |
| --- | --- |
| [user/guide.md](user/guide.md) | what the plugin is, requirements, setup, API token, multilingual sites |
| [user/woocommerce.md](user/woocommerce.md) | what the plugin does in a WooCommerce shop |

## For developers: [`dev/`](dev/)

Technical guides. They cover the REST contract, the plugin internals and the design.

| Document | What it covers |
| --- | --- |
| [dev/integration-guide.md](dev/integration-guide.md) | writing an integration: examples, call order, errors. Start here. |
| [dev/internals.md](dev/internals.md) | plugin internals, controller by controller |
| [dev/architecture.md](dev/architecture.md) | architectural decisions and trade-offs |
| [dev/woocommerce.md](dev/woocommerce.md) | WooCommerce endpoints, product save flow, downloads |
| [dev/woocommerce-performance.md](dev/woocommerce-performance.md) | technical analysis of the WooCommerce `POST` endpoints |
| [dev/pagination.md](dev/pagination.md) | which `GET` endpoints paginate |

## API reference

[API.md](API.md) is the full REST API reference, endpoint by endpoint.
