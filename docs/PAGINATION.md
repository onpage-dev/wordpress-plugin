# Paginazione degli endpoint GET

Riferimento rapido su quali endpoint `GET` del plugin On Page® paginano i risultati e quali restituiscono sempre l'elenco completo. Per il dettaglio di ogni endpoint vedi [API.md](API.md).

## Come funziona dove e' presente

Gli endpoint paginati accettano:

- `?per_page=<n>` — dimensione pagina, default `100`, massimo `100`
- `?page=<n>` — numero pagina, default `1`

e restituiscono due header sulla response, stessa convenzione della REST API core di WordPress:

- `X-WP-Total` — numero totale di elementi che soddisfano i filtri, indipendentemente dalla pagina
- `X-WP-TotalPages` — numero totale di pagine, calcolato come `ceil(X-WP-Total / per_page)`

Il client sa gia' dalla risposta corrente se e' l'ultima pagina (`page >= X-WP-TotalPages`): non deve chiamare una pagina in piu' e scoprirlo da un array vuoto.

Esempio di scorrimento (250 elementi, `per_page=100` → 3 pagine):

```text
GET /media?page=1&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continua)
GET /media?page=2&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continua)
GET /media?page=3&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (page == X-WP-TotalPages → stop)
```

Pseudocodice client:

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

## Tabella endpoint

| Endpoint | Paginato | `per_page`/`page` | `X-WP-Total`/`X-WP-TotalPages` | Note |
|---|---|---|---|---|
| `GET /posts` | ✅ | ✅ | ✅ | Non paginato quando si usa `?id=` o `?title=` (ritornano sempre tutti i match, nessun header di paginazione in quel caso) |
| `GET /media` | ✅ | ✅ | ✅ | — |
| `GET /terms` | ❌ | — | — | Ritorna sempre tutti i term della tassonomia richiesta |
| `GET /field-groups` | ❌ | — | — | Ritorna sempre tutti i field group (`acf_get_field_groups()`) |
| `GET /taxonomies` | ❌ | — | — | Ritorna sempre tutte le tassonomie ACF (`acf_get_acf_taxonomies()`) |
| `GET /post-types` | ❌ | — | — | Ritorna sempre tutti i post type ACF (`acf_get_acf_post_types()`) |
| `GET /woocommerce/brands` | ❌ | — | — | Ritorna sempre tutti i brand |
| `GET /woocommerce/attributes` | ❌ | — | — | Ritorna sempre tutti gli attributi (`wc_get_attribute_taxonomies()`) |
| `GET /woocommerce/attributes/{attribute}/terms` | ❌ | — | — | Ritorna sempre tutti i term dell'attributo |
| `GET /woocommerce/categories` | ❌ | — | — | Ritorna sempre tutte le categorie |
| `GET /woocommerce/tags` | ❌ | — | — | Ritorna sempre tutti i tag |
| `GET /woocommerce/products` | ❌ | — | — | `numberposts => -1`, esplicitamente illimitato; senza query restituisce **tutti** i prodotti |
| `GET /woocommerce/variant-products` | ❌ | — | — | `numberposts => -1`, esplicitamente illimitato; senza query restituisce **tutte** le variazioni |

## Perche' non tutti paginano

La scelta segue il volume atteso di elementi: `posts` e `media` (Media Library) possono facilmente crescere a migliaia di elementi su un sito reale, quindi paginano per evitare query pesanti e risposte JSON enormi. Term, tassonomie, field group e post type sono tipicamente set piccoli e limitati (decine, al massimo centinaia), quindi restituire tutto in un'unica risposta e' stato considerato accettabile.

I prodotti/variazioni WooCommerce sono un'eccezione degna di nota: potenzialmente numerosi quanto i post, ma **non** paginati — un catalogo molto grande puo' quindi generare risposte pesanti su `GET /woocommerce/products` e `GET /woocommerce/variant-products`.
