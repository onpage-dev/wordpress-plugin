# Pagination of GET endpoints

A quick reference showing which `GET` endpoints of the On Page® plugin paginate their results and which always return the full list. For details on each endpoint, see [API.md](API.md).

## How pagination works

Paginated endpoints accept:

- `?per_page=<n>` — page size. Default `100`, maximum `100`.
- `?page=<n>` — page number. Default `1`.

They return two response headers, following the same convention as the WordPress core REST API:

- `X-WP-Total` — total number of items that match the filters, regardless of the page.
- `X-WP-TotalPages` — total number of pages, calculated as `ceil(X-WP-Total / per_page)`.

The client can tell from the current response whether it is on the last page (`page >= X-WP-TotalPages`). There is no need to request one extra page and wait for an empty array.

Example walk-through (250 items, `per_page=100` → 3 pages):

```text
GET /media?page=1&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continue)
GET /media?page=2&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continue)
GET /media?page=3&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (page == X-WP-TotalPages → stop)
```

Client pseudocode:

```text
page = 1
items = []
loop:
  response = GET /media?page={page}&per_page=100
  items += response.body
  if page >= response.headers['X-WP-TotalPages']:
    break
  page += 1
```

## Endpoints

| Endpoint | Paginated | `per_page`/`page` | `X-WP-Total`/`X-WP-TotalPages` | Notes |
|---|---|---|---|---|
| `GET /posts` | ✅ | ✅ | ✅ | Not paginated when using `?id=` or `?title=`: these always return every match, with no pagination headers. |
| `GET /media` | ✅ | ✅ | ✅ | — |
| `GET /terms` | ❌ | — | — | Always returns every term of the requested taxonomy. |
| `GET /field-groups` | ❌ | — | — | Always returns every field group (`acf_get_field_groups()`). |
| `GET /taxonomies` | ❌ | — | — | Always returns every ACF taxonomy (`acf_get_acf_taxonomies()`). |
| `GET /post-types` | ❌ | — | — | Always returns every ACF post type (`acf_get_acf_post_types()`). |
| `GET /woocommerce/brands` | ❌ | — | — | Always returns every brand. |
| `GET /woocommerce/attributes` | ❌ | — | — | Always returns every attribute (`wc_get_attribute_taxonomies()`). |
| `GET /woocommerce/attributes/{attribute}/terms` | ❌ | — | — | Always returns every term of the attribute. |
| `GET /woocommerce/categories` | ❌ | — | — | Always returns every category. |
| `GET /woocommerce/tags` | ❌ | — | — | Always returns every tag. |
| `GET /woocommerce/products` | ❌ | — | — | `numberposts => -1`, explicitly unlimited. Without a query it returns **all** products. |
| `GET /woocommerce/variant-products` | ❌ | — | — | `numberposts => -1`, explicitly unlimited. Without a query it returns **all** variations. |

## Why not every endpoint paginates

It depends on how many items each endpoint is expected to return:

- **Paginated:** `posts` and `media` (Media Library) can easily grow to thousands of items on a real site. Pagination avoids heavy queries and huge JSON responses.
- **Not paginated:** terms, taxonomies, field groups and post types are usually small, bounded sets (tens, at most hundreds of items). Returning everything in one response was considered acceptable.

**Notable exception:** WooCommerce products and variations can be as numerous as posts, but they are **not** paginated. A very large catalog can therefore produce heavy responses on `GET /woocommerce/products` and `GET /woocommerce/variant-products`.
