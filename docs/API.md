# API del plugin On Page®

Questa documentazione descrive le API REST esposte dal plugin, prendendo come riferimento `routes.php` e la logica effettiva implementata nei controller.

Base namespace REST:

```text
/wp-json/onpage/v1
```

## Autenticazione

Tutti gli endpoint sono protetti da Bearer token.

Header richiesto:

```http
Authorization: Bearer <token>
```

Comportamento:

- `500` se il token API non e' configurato nelle option WordPress (`onpage_auth_token`)
- `401` se l'header `Authorization` manca o non contiene un Bearer token valido
- `403` se il token inviato non coincide con quello configurato

Il token si genera dalla pagina **On Page®** nel backend WordPress, accessibile **solo agli utenti amministratori** (capability `manage_options`); la generazione/rigenerazione e' protetta anche da nonce CSRF. Non esiste alcun endpoint REST per leggere o scrivere il token: e' gestito esclusivamente dalla UI admin.

## Convenzioni generali

- Quasi tutti gli endpoint di scrittura lavorano in batch: il body JSON atteso e' un array.
- L'endpoint `POST /media` fa eccezione: usa `multipart/form-data` e accetta uno o piu' file nella stessa richiesta.
- In caso di errore su un elemento del batch, la richiesta termina immediatamente e restituisce un `WP_Error`.
- Gli endpoint `DELETE` di `field-groups`, `post-types`, `posts` e `taxonomies` supportano la query string `?ignore=1` per ignorare gli elementi non trovati.
- Gli endpoint dei `terms` usano un parametro path `id` che identifica la tassonomia. Accetta indifferentemente lo **slug della tassonomia** (es. `product_cat`, `brand`, `pa_color`) oppure l'**ID ACF numerico** della tassonomia. Lo slug ha la precedenza ed e' consigliato: e' stabile tra ambienti (l'ID ACF dipende dall'ordine di creazione) e copre anche le tassonomie non-ACF (WooCommerce `product_cat`/`product_tag`/`pa_*`). L'ID numerico resta supportato per retrocompatibilita'.
- Dove presente WPML, alcuni campi possono essere inviati in forma multilingua come oggetto `{ "<lang>": <value> }`.
- Gli endpoint `/posts` e `/post-types` usano il post type esatto inviato nel payload, senza aggiungere prefissi.

### `local_key`

`local_key` e' l'identificativo esterno On Page® e puo' essere un **intero positivo o una stringa non vuota**.

- **In input** (payload e query string) e' normalizzato con `trim`: sono rifiutate solo la stringa vuota, `"0"` e i tipi non scalari, con `400 invalid_param`. Interi e stringhe numeriche sono **equivalenti** (`123` ≡ `"123"`, e' cosi' che WordPress memorizza i meta), quindi lo stesso oggetto si risolve indipendentemente dal tipo inviato; una chiave testuale (es. `"SKU-ABC"`) e' conservata cosi' com'e' (whitespace ai lati escluso).
- **In output** (HTTP response) una chiave che e' un intero canonico e' restituita come **intero** (retrocompatibile con i consumatori esistenti); una chiave non numerica e' restituita come **stringa**; quando non e' impostata e' **`null`**. Vale per tutti gli endpoint che lo espongono: `posts`, `products`, `variant-products`, `terms`/`brands`/`categories`/`tags`/`attributes/{}/terms`/`attributes`.
- I **`Post`** espongono `local_key` come campo top-level della response (`int|string|null`). Non è un campo ACF: è una post meta tecnica (`onpage_local_key`) e non compare dentro `acf_fields`.
- **Riferimenti a termini**: per i **prodotti** (`brand`, `categories`, `tags`, `terms`) i riferimenti sono **local_key** (interi o stringhe); gli slug non sono supportati. Per i **`Post`** (`terms`/`term`) un riferimento puo' essere un local_key (intero o stringa) **oppure** uno slug (viene prima cercato come local_key, altrimenti trattato come slug). Anche il `parent` delle categorie WooCommerce e' un local_key (intero o stringa).

### Conflitto `term_exists` sui termini

Un termine gia' esistente con lo stesso nome sotto lo stesso parent ma con un `local_key` diverso **non** viene toccato, per non sovrascrivere un termine di un'altra origine. Poiche' WordPress non ammette due termini fratelli con lo stesso nome — a meno che il chiamante non fornisca uno slug esplicito libero — l'upsert crea in quel caso un **termine distinto** con uno slug tecnico (`<slug-base>-<lingua>`, con suffisso numerico se occupato) e il nome richiesto. L'upsert fallisce con `500 request_failed` e messaggio `term_exists` solo se nessuno slug tecnico e' disponibile.

Restano quindi a destinazione due termini omonimi con `local_key` diverse: e' il sintomo tipico di `local_key` disallineate tra sorgente e destinazione (es. la sorgente ha rigenerato le chiavi). Si risolve con [`DELETE /indexes`](#delete-indexes) seguito da un re-import **top-down**, che fa ri-adottare i termini esistenti riscrivendone la `local_key` invece di duplicarli.

## Gestione `acf_fields`

Tutti gli endpoint di scrittura che accettano un oggetto `acf_fields` (`POST /posts`, `POST /woocommerce/products`, `POST /woocommerce/variant-products`, `POST /woocommerce/brands`, `POST /woocommerce/categories`, `POST /woocommerce/tags`, `POST /woocommerce/attributes/{attribute}/terms`, `POST /terms`) condividono la stessa logica di assegnazione, centralizzata in `Acf::updateFieldValue`. Per ogni chiave dentro `acf_fields`:

- **`text`, `textarea`, `select`, `number`, `url`, `email`, …** (campi semplici): il valore viene passato direttamente ad ACF.
- **`image` / `file`**: il valore puo' essere una stringa URL valida (importata/riusata in Media Library) oppure l'`attachment_id` (intero JSON) di un file gia' presente in Media Library (es. caricato con `POST /media`); in entrambi i casi nel campo viene salvato l'`attachment_id` risultante. Per il contesto `post` l'attachment viene anche collegato al post parent (via download per l'URL, senza download per l'`attachment_id`, che viene solo verificato e ricollegato). Per svuotare il campo passare `null` o stringa vuota. Un `attachment_id` che non corrisponde a un attachment esistente viene ignorato (il valore originale passa invariato ad ACF).
- **`tab`**: i tab ACF sono separatori UI senza valore, eventuali chiavi inviate per un campo di tipo `tab` vengono ignorate silenziosamente. I tab non compaiono in lettura (`get_fields()` non li espone).
- **`repeater`**: il valore deve essere una lista di oggetti (uno per riga); ogni oggetto contiene i sub-field della riga. Esempio: `"certifications": [{"nome": "Marcatura CE", "anno": 2024}, {"nome": "VOC A+"}]`. Sub-field di tipo `image`/`file` con URL o `attachment_id` vengono risolti in `attachment_id` con le stesse regole dei campi top-level. Sub-field di tipo `repeater` sono supportati ricorsivamente. Sub-field di tipo `tab` ignorati. Una mappa lingua WPML va applicata a livello dell'intero repeater (`"certifications": {"it": [...righe...], "en": [...righe...]}`), non a livello di sub-field. Mappe lingua dentro una riga non sono supportate.
- Se il payload di un repeater non e' una lista di oggetti, l'endpoint ritorna `400 invalid_param` con messaggio `Repeater '<name>' must be a list of rows` o `Repeater '<name>' row N must be an object`.
- **`group`**: il valore e' un oggetto con i sub-field del gruppo, ad esempio `"scheda": {"titolo": "…", "allegato": "https://…pdf"}`. Sub-field `image`/`file` con URL o `attachment_id` vengono convertiti/risolti in `attachment_id`. Una mappa lingua WPML va applicata a livello dell'intero gruppo.
- **`group` con sub-field di due o tre lettere**: il campo viene **svuotato** e la richiesta risponde comunque `200`. `MultiLang::isLanguageMapShape()` riconosce una mappa lingua dalla sola forma delle chiavi, e un codice lingua e' qualsiasi chiave di 2-3 lettere: un gruppo come `{"lat": 45.1, "lng": 9.2}`, `{"sku": …, "ean": …}` o `{"url": …, "alt": …}` viene quindi scambiato per una mappa lingua, non contiene ne' la lingua richiesta ne' il fallback, e si risolve in `null`. Il comportamento e' fissato in `src/Tests/MultiLangResolveFields.php` (`casiDaComportamentoAttuale`): irrigidire il riconoscimento e' un compromesso, perche' una mappa che porta solo lingue non attive sul sito verrebbe scritta grezza nel campo invece che svuotarlo. Finche' resta cosi', a un gruppo di questo tipo va dato almeno un sub-field con un nome piu' lungo di tre lettere, oppure i valori vanno tenuti in campi separati. Nota: sui **termini** lo stesso payload viene scritto intatto, perche' quel percorso (`splitAcfFieldsByLanguage`) interseca le chiavi con le lingue WPML attive.

### Mappe lingua dentro `acf_fields`

Ogni valore in `acf_fields` puo' essere inviato come mappa lingua WPML `{ "<lang>": <value> }`. La risoluzione per lingua e' indipendente: il valore di ciascuna lingua puo' essere **stringa**, **numero**, **`null`/`""`**, **lista di oggetti** (repeater) o **oggetto** (group), e lingue diverse della stessa mappa possono avere tipi diversi.

```json
"acf_fields": {
  "product_subtitle": { "it": "Sottotitolo", "en": null },
  "certifications": {
    "it": [ { "nome": "Marcatura CE", "anno": 2024 } ],
    "en": [ { "nome": "CE marking", "anno": 2024 } ]
  }
}
```

La coerenza con il tipo ACF e' comunque verificata a valle, dopo la risoluzione della lingua: per un campo `repeater` il valore risolto di ogni lingua deve restare una lista di oggetti (un oggetto singolo o una stringa per quella lingua ritornano `400 invalid_param`); per `image`/`file` vale la conversione URL → `attachment_id`; i `tab` restano ignorati. La mappa lingua va sempre al livello del campo (o dell'intero repeater/group), mai sui singoli sub-field.

In lettura (GET) i repeater vengono restituiti nativamente da `\get_fields()` come liste di oggetti; i tab non compaiono.

## Struttura errori

Gli errori arrivano come `WP_Error` con schema tipico WordPress REST:

```json
{
  "code": "duplicate_title",
  "message": "Post :: Element 0 :: Title 'Example' already exists for PostType 'news'",
  "data": {
    "status": 409
  }
}
```

## Field Groups

### GET `/field-groups`

Restituisce tutti i field group ACF.

**Paginazione:** no — la risposta contiene sempre **tutti** i field group in un'unica chiamata, nessun `per_page`/`page`.

Response `200`:

```json
[
  {
    "ID": 123,
    "key": "group_example",
    "title": "Example Group"
  }
]
```

### POST `/field-groups`

Crea o aggiorna uno o piu' field group ACF.

Body:

```json
[
  {
    "title": "Product Fields",
    "key": "group_product_fields",
    "locations": [
      {
        "param": "post_type",
        "operator": "==",
        "value": "post_product"
      }
    ],
    "description": "Extra fields for products",
    "fields": [
      {
        "key": "sku",
        "label": "SKU",
        "name": "SKU",
        "type": "text"
      },
      {
        "key": "price",
        "label": "Price",
        "name": "Price",
        "type": "number"
      }
    ]
  }
]
```

Note:

- `title` (obbligatorio) e' il titolo del gruppo. Se manca o e' vuoto, l'endpoint risponde `400 missing_title`.
- `key` (opzionale) e' la chiave ACF del gruppo (convenzione `group_...`). Se manca, viene generata automaticamente dal `title` (`group_` + slug del titolo).
- Se esiste gia' un field group con la stessa `key` oppure lo stesso `title`, viene aggiornato invece di crearne uno nuovo (upsert). Quando `key` non e' passata e un gruppo con quel `title` esiste, ne viene riusata la chiave esistente (non viene cambiata).
- In update, i campi del gruppo vengono sostituiti dal payload: i campi con la stessa chiave tecnica mantengono la field key ACF interna, i campi non piu' presenti vengono rimossi dal field group.
- Per ogni field, `key` e' la chiave tecnica ACF salvata come field name; e' la stessa chiave da usare negli oggetti `acf_fields` di `POST /posts`, `POST /woocommerce/products` e termini.
- `name` e `label` sono descrittivi; se `label` manca, viene usato `name`; se `key` manca, per retrocompatibilita' viene usato `name` come chiave tecnica.
- Ogni field passato viene sanificato almeno per `key`, `label`, `name`, `type`.
- Se WPML e' attivo, il gruppo viene marcato con modalita' traduzione ACFML.

Response `200`:

```json
[123, 124]
```

Errori principali:

- `400 missing_title`
- `400 invalid_param`
- `500 acf_error`
- `500 delete_failed`

### DELETE `/field-groups`

Elimina field group per ID numerico oppure per titolo.

Body:

```json
[123, "Product Fields"]
```

Query opzionale:

```text
?ignore=1
```

Response `200`:

```json
null
```

## Post Types

### GET `/post-types`

Restituisce tutti i post type ACF registrati.

**Paginazione:** no — la risposta contiene sempre **tutti** i post type registrati in un'unica chiamata, nessun `per_page`/`page`.

### POST `/post-types`

Crea o aggiorna uno o piu' post type ACF (upsert per chiave `post_type`).

Body:

```json
[
  {
    "post_type": "product",
    "singular_label": "Product",
    "plural_label": "Products",
    "hierarchical": false,
    "icon": "dashicons-cart",
    "supports": ["title", "editor", "thumbnail", "revisions"],
    "taxonomies": ["brand"],
    "rewrite_slug": "catalogo/prodotti"
  }
]
```

Campi usati:

- `post_type` richiesto
- `singular_label` richiesto
- `plural_label` richiesto
- `hierarchical` opzionale, default `false`
- `icon` opzionale, default `dashicons-admin-post`
- `supports` opzionale, default `["title", "editor", "thumbnail", "revisions"]`
- `taxonomies` opzionale, default `[]`
- `rewrite_slug` opzionale, default = valore di `post_type` (slug WordPress nei permalink). Se `null`, stringa vuota o omesso non viene applicato alcuno slug custom

Note:

- **Upsert**: se esiste gia' un post type con la stessa chiave (`sanitize_key(post_type)`) i valori vengono aggiornati, altrimenti viene creato. Non viene restituito errore in caso di duplicato.
- **Payload-as-truth**: ogni chiamata sovrascrive completamente i campi ACF; le proprieta' opzionali non incluse nel payload tornano al loro default (es. un `rewrite_slug` precedentemente impostato viene rimosso se la chiave non e' piu' presente).
- La chiave ACF del post type viene ottenuta con `sanitize_key(post_type)`.
- Lo slug WordPress usa il valore di `rewrite_slug` se fornito, altrimenti il valore esatto di `post_type`, senza prepend `post_`.
- Dopo ogni batch viene eseguito `flush_rewrite_rules()` una sola volta.

Response `200`:

```json
[45]
```

Errori principali:

- `500 acf_error`

### DELETE `/post-types`

Elimina post type per ID ACF oppure per chiave stringa.

Body:

```json
[45, "product"]
```

Query opzionale:

```text
?ignore=1
```

Note:

- La stringa viene sanificata con `sanitize_key`.
- Dopo ogni cancellazione viene eseguito `flush_rewrite_rules()`.

## Posts

### GET `/posts`

Restituisce post generici, con filtri opzionali utili per sync/mapping. Supporta anche la ricerca per **titolo esatto** (parametro `title`), filtrabile per post type con `?type=`.

**Paginazione:** si', tramite `per_page`/`page` e header `X-WP-Total`/`X-WP-TotalPages` (dettagli sotto) — **tranne** quando si usa `?id=` o `?title=`, che ritornano sempre tutti i match senza paginare.

Query opzionale:

```text
?type=product
?local_key=1001
?updated_after=2026-05-14T10:00:00Z
?status=publish
?status=trashed
?page=1
?per_page=100
?title=My post
?title[it]=Chi siamo&title[en]=About us
```

Note:

- `type` filtra per post type esatto; se omesso usa `any`. Nel listing normale un `type` inesistente non genera errore (ritorna lista vuota); in combinazione con `title` un `type` inesistente ritorna `404 not_found`.
- `local_key` filtra sul meta ACF `local_key`.
- `status` filtra per stato. Se omesso usa `any`, che **esclude** i post nel cestino e gli auto-draft. Il valore `trashed` (alias dello stato WP `trash`) restituisce **solo** gli elementi nel cestino; altri valori (`publish`, `draft`, `pending`, `private`, …) sono passati a WordPress invariati.
- `updated_after` filtra su `post_modified_gmt`.
- `per_page` ha massimo `100` e default `100`.
- `id` restituisce il singolo post corrispondente.

Header di paginazione (solo sul listing normale, non su `id`/`title`):

- `X-WP-Total`: numero totale di post che soddisfano i filtri, indipendentemente dalla pagina.
- `X-WP-TotalPages`: numero totale di pagine, calcolato come `ceil(X-WP-Total / per_page)`.
- Il client sa gia' dalla risposta corrente se e' l'ultima pagina (`page >= X-WP-TotalPages`): non serve chiamare una pagina in piu' per scoprirlo tramite un array vuoto.

Esempio di scorrimento (250 post, `per_page=100` → 3 pagine):

```text
GET /posts?page=1&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continua)
GET /posts?page=2&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continua)
GET /posts?page=3&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (page == X-WP-TotalPages → stop, nessuna richiesta a page=4)
```

Ricerca per `title`:

- `title` esegue una ricerca per **titolo esatto** e accetta due forme:
  - **stringa** (`?title=My post`): ricerca nella lingua corrente della richiesta;
  - **mappa lingua** (`?title[it]=Chi siamo&title[en]=About us`): ricerca ogni titolo nel contesto della rispettiva lingua WPML e ne restituisce l'**unione**.
- Quando `title` e' presente la ricerca e' scopata al `type` indicato (validato, `404 not_found` se inesistente); se `type` e' omesso cerca su qualsiasi post type.
- La risposta è **sempre una lista** con **un oggetto per ogni gruppo di traduzione distinto** (rappresentante in lingua di default quando disponibile), con la mappa `translations`: titoli che sono traduzioni dello stesso post → un solo oggetto; titoli di gruppi diversi → più oggetti. Stesso comportamento di `GET /woocommerce/products?name=`.
- Quando `title` e' presente, gli altri filtri (`local_key`, `updated_after`, `status`, `page`, `per_page`) non si applicano.

Response `200`:

```json
[
  {
    "id": 321,
    "title": "My post",
    "content": "Body",
    "type": "product",
    "status": "publish",
    "translations": {
      "it": 321,
      "en": 322
    },
    "acf_fields": {
      "sku": "ABC123"
    },
    "terms": []
  }
]
```

- `translations` mappa ogni codice lingua all'ID del post in quella lingua (gruppo di traduzione WPML); senza WPML attivo e' una mappa vuota. E' presente in tutte le response dei post (singolo, lista e ricerca per `title`).

> **Nota:** l'endpoint `GET /post-types/{post_type}/posts` è stato rimosso. Per elencare o cercare i post di un post type specifico usa `GET /posts?type={post_type}` (con `&title=...` per la ricerca per titolo esatto).

### GET `/posts/{id}`

Restituisce un singolo post.

**Paginazione:** non applicabile — restituisce un solo oggetto, non una lista.

Query opzionale:

- `keyfield=id`
- `keyfield=local_key`
- default `id`
- con `keyfield=id`, `{id}` e' l'ID numerico del post
- con `keyfield=local_key`, `{id}` e' il valore `local_key`
- con `keyfield=local_key`, puoi passare anche `type=product` per evitare ambiguita' tra post type
- con `keyfield=local_key`, se piu' post condividono la chiave (traduzioni WPML) viene restituito il post in **lingua di default** del gruppo (le altre lingue sono nella mappa `translations`)

Esempi:

```bash
curl -X GET \
  "https://example.com/wp-json/onpage/v1/posts/321"
```

Response `200`:

```json
{
  "id": 321,
  "title": "My post",
  "content": "Body",
  "type": "post_product",
  "status": "publish",
  "translations": {
    "it": 321,
    "en": 322
  },
  "acf_fields": {
    "sku": "ABC123"
  },
  "terms": [
    {
      "term_id": 10,
      "taxonomy": "brand",
      "name": "Acme",
      "slug": "acme"
    }
  ]
}
```

Errore:

- `404 no_post`

### POST `/posts`

Crea o aggiorna post in batch. Se passi un `id` che esiste in WordPress, viene aggiornato quel post (l'`id` ha la precedenza, come in `POST /woocommerce/products`); altrimenti l'upsert e' guidato da `local_key`: se `local_key` esiste gia' aggiorna quel post, altrimenti ne crea uno nuovo.

#### Payload base

```json
[
  {
    "type": "product",
    "title": "Red Chair",
    "content": "Description",
    "status": "publish",
    "local_key": 1001,
    "files": {
      "image": "https://storage.op.com?file=12345"
    },
    "acf_fields": {
      "sku": "CHAIR-RED",
      "price": 49.9
    },
    "terms": {
      "brand": ["acme"],
      "collection": ["summer"]
    }
  }
]
```

Campi:

- `type` richiesto in insert; in update default al tipo del post corrente; viene usato come post type esatto, senza prepend `post_`
- `title` richiesto in insert
- `content` opzionale
- `status` opzionale, default `draft` in insert
- `local_key` richiesto
- `files` opzionale
- `acf_fields` opzionale
- `terms` opzionale
- `term` accettato come alias legacy di `terms`
- Formato supportato per `terms`:
- `<taxonomy_slug> => [term_slug, ...]`
- `<taxonomy_slug> => [lang => term_slug|[term_slug, ...]]`
- `<taxonomy_slug> => [[lang => term_slug, ...], ...]`

#### Comportamento insert

- Verifica che il titolo non esista gia' nello stesso post type.
- Verifica che `local_key` non esista gia' come meta ACF `local_key`.
- Se `files` e' presente, ogni valore deve essere un URL valido raggiungibile da WordPress, oppure l'`attachment_id` (intero JSON) di un file gia' presente in Media Library (es. caricato con `POST /media`).
- Ogni file remoto viene scaricato, importato nella Media Library e associato al post creato; un `attachment_id` viene invece assegnato direttamente, senza download, e ricollegato al post come parent.
- Il valore salvato nel campo indicato dentro `files` e' l'`attachment_id` WordPress del media importato o passato.
- Se un valore dentro `acf_fields` appartiene a un campo ACF di tipo `image` ed e' un URL valido, il controller lo importa come media remoto e salva nel campo l'`attachment_id` risultante.
- Se un campo ACF di tipo `image` viene passato a `null` o stringa vuota, il controller lo lascia senza immagine.
- Se esiste un campo ACF chiamato `local_key`, il controller lo valorizza automaticamente.
- Se `terms` e' presente, i termini vengono assegnati per riferimento: ogni riferimento (es. `123`, `"SKU-ABC"`) viene prima cercato come `local_key`, altrimenti viene trattato come slug. A differenza dei prodotti, qui lo slug e' supportato (i Post accettano local_key intero/stringa **oppure** slug).
- Per payload multilingua puoi passare una mappa per lingua, ad esempio `{"manufacturer":{"en":"ford-en","it":"ford-it"}}`.
- E' supportato anche il formato lista di mappe lingua, ad esempio `{"manufacturer":[{"en":"ford-en","it":"ford-it"}]}`.
- Se un termine non esiste per `local_key` o slug, la richiesta fallisce con `404`.

#### Comportamento update

- `local_key` e' obbligatorio (anche aggiornando per `id`): viene scritto sul post risolto e propagato a **tutte le sue traduzioni WPML**.
- Risoluzione del post da aggiornare (in ordine di precedenza):
  - se il payload contiene un `id` che **esiste** in WordPress, viene aggiornato quel post (`404 not_found` se l'`id` non esiste);
  - altrimenti l'update viene risolto tramite `local_key` e l'ID WordPress viene ricavato internamente.
- Se `type`, `title`, `content`, `description`, `acf_fields`, `files`, `terms` o `status` non sono presenti nel payload, quel dato viene conservato senza modifiche.
- In update non viene eseguito il controllo di unicita' su `local_key`.
- Se cambia `title`, viene controllata l'unicita' rispetto agli altri post dello stesso tipo.
- `acf_fields` aggiorna solo i campi inviati.
- `files` aggiorna solo i campi inviati.
- In update, anche i campi ACF di tipo `image` ricevuti come URL vengono importati o riusati come attachment e salvati come `attachment_id`.
- In update, se un campo ACF di tipo `image` vale `null` o stringa vuota, il campo viene svuotato.
- In update, se un URL in `files` e' gia' stato importato in precedenza dal plugin, viene riusato lo stesso attachment e ricollegato al post corrente.
- Se il file remoto non e' ancora in Media Library, viene scaricato e creato un nuovo attachment.
- In update, un `attachment_id` in `files` viene verificato e ricollegato al post corrente, senza download.
- `terms` sovrascrive le assegnazioni per le tassonomie passate.
- In update, il formato multilingua di `terms` usa i termini della lingua del post/traduzione corrente.

#### Supporto multilingua con WPML

Per `title`, `content` e ogni valore in `acf_fields` e' possibile inviare una mappa per lingua:

```json
[
  {
    "type": "product",
    "title": {
      "it": "Sedia Rossa",
      "en": "Red Chair"
    },
    "content": {
      "it": "Descrizione IT",
      "en": "English description"
    },
    "acf_fields": {
      "subtitle": {
        "it": "Sottotitolo",
        "en": "Subtitle"
      },
      "sku": "CHAIR-RED"
    }
  }
]
```

Anche `files` supporta il formato per lingua:

```json
[
  {
    "type": "product",
    "title": {
      "it": "Sedia Rossa",
      "en": "Red Chair"
    },
    "files": {
      "image": {
        "it": "https://storage.op.com/it/chair.jpg",
        "en": "https://storage.op.com/en/chair.jpg"
      }
    }
  }
]
```

Regole:

- Se sono presenti contenuti multilingua in `title`, `content`, `description`, `acf_fields`, `files` o `terms` e WPML non e' installato o attivo, ritorna errore `500 wpml_required`.
- In insert, il plugin crea il post base nella lingua default WPML e poi le traduzioni.
- In update, il plugin aggiorna il post corrente e le sue traduzioni collegate.
- Se `title` e' una stringa e altri campi sono multilingua, lo stesso titolo viene usato invariato per tutte le traduzioni; per titoli diversi per lingua, usare una mappa WPML.
- Quando `files` e' multilingua, ogni traduzione riceve i propri `attachment_id` nei campi target.
- Se per una lingua manca il titolo tradotto, viene usato il titolo condiviso o la lingua di fallback senza suffissi automatici.
- Nell'assegnazione multilingua di `terms`, i termini vengono risolti nella lingua target tramite WPML.

Response `200`:

```json
[321, 322]
```

Errori principali:

- `400 input_invalid`
- `400 input_invalid` se un campo in `files` non contiene un `attachment_id` esistente o un URL valido
- `404 not_found`
- `404 input_invalid` se un termine referenziato non esiste
- `409 duplicate_title` se il titolo esiste gia' su un oggetto **senza `local_key`**, fuori dal gruppo di traduzione di questo elemento; un oggetto che porta una `local_key` diversa e' un altro elemento On Page® e non fa conflitto, quindi due elementi omonimi si importano entrambi
- `500 request_failed`
- `500 wpml_required` se il payload contiene valori multilingua ma WPML non e' installato o attivo

### DELETE `/posts`

Elimina post per ID, per `local_key`, oppure elimina tutti i post di un dato post type.

Body:

```json
[321, "product", {"local_key": 1001, "type": "product"}]
```

Query opzionale:

```text
?ignore=1
?keyfield=local_key&type=product
```

Note:

- Se il valore e' stringa, viene interpretato come post type esatto.
- In questo caso il controller cancella tutti i post di quel tipo.
- Per cancellare per `local_key` (intero o stringa non vuota), usa `?keyfield=local_key`; `type` e' opzionale ma consigliato.
- In alternativa puoi usare oggetti con `local_key` e `type`, senza cambiare `keyfield`.
- Se un `local_key` non filtrato per `type` corrisponde a piu' post type, ritorna `409 ambiguous_local_key`.

## WooCommerce

### GET `/woocommerce/brands`

Restituisce i brand WooCommerce salvati come termini della tassonomia `product_brand`.

**Paginazione:** no — la risposta contiene sempre **tutti** i brand che soddisfano i filtri in un'unica chiamata, nessun `per_page`/`page`.

Query opzionale:

```text
?id=701
?local_key=4002
?slug=ford
?name=Ford
?name[it]=Ford&name[en]=Ford
?parent_id=700
?parent_lk=4001
```

- `name` esegue una ricerca per **nome esatto** del termine e accetta due forme:
  - **stringa** (`?name=Ford`): ricerca nella lingua corrente della richiesta;
  - **mappa lingua** (`?name[it]=Ford&name[en]=Ford`): ricerca ogni nome nel contesto della rispettiva lingua WPML e ne restituisce l'**unione**.
- La risposta è **sempre una lista** con **un oggetto per ogni gruppo di traduzione distinto** (rappresentante in lingua di default), con la mappa `translations`: nomi che sono traduzioni dello stesso termine → un solo oggetto; nomi di gruppi diversi → più oggetti. Stesso comportamento di `GET /woocommerce/products?name=`.
- `parent_id` (WP term id) e `parent_lk` (local_key del parent) filtrano restituendo i **figli diretti** del parent indicato. `parent_lk` viene espanso a **tutte le traduzioni WPML** del parent (i figli vengono cercati sotto ognuna); la visibilità dei figli per lingua segue le stesse regole del listing normale. I due parametri sono **mutuamente esclusivi** (`400` se passati insieme); un `parent_lk` che non risolve nessun termine restituisce lista vuota. Precedenza: `id` > `local_key` > `slug` > `name` > `parent_*`.

Response `200`:

```json
[
  {
    "id": 701,
    "name": "Ford",
    "slug": "ford",
    "local_key": 4002,
    "description": "",
    "parent": 0,
    "count": 0,
    "taxonomy": "product_brand",
    "translations": {
      "it": 701,
      "en": 702
    },
    "acf_fields": {}
  }
]
```

- `translations` e' presente in tutte le response (singolo/lista/ricerca); senza WPML attivo e' una mappa vuota.

### POST `/woocommerce/brands`

Crea o aggiorna brand WooCommerce. L'endpoint assicura la tassonomia `product_brand` per il post type `product` e poi usa lo stesso payload dei termini (`POST /terms`).

Body:

```json
[
  {
    "local_key": 4002,
    "name": {
      "en": "Ford",
      "it": "Ford",
      "es": "Ford",
      "ru": "Ford"
    },
    "slug": {
      "en": "ford-en",
      "it": "ford-it",
      "es": "ford-es",
      "ru": "ford-ru"
    },
    "description": "Vehicle manufacturer",
    "thumbnail": "https://cdn.example.com/ford-thumbnail.png",
    "parent": 4001,
    "acf_fields": {
      "logo": "https://cdn.example.com/ford.png"
    }
  }
]
```

Comportamento:

- se `product_brand` non esiste, viene creata come tassonomia ACF **gerarchica** agganciata a `product`
- `name`, `slug`, `description`, `local_key` e `acf_fields` hanno lo stesso comportamento dei termini
- `parent` e' opzionale; se presente deve essere `null` oppure il `local_key` (intero o stringa) del brand parent, ad esempio `"parent": 4001`
- `parent` non accetta `0` o ID WordPress numerici; per creare un brand di primo livello usare `null` oppure omettere il campo
- il brand parent deve esistere gia' o essere stato creato prima nello stesso batch
- con WPML attivo, quando `parent` e' un `local_key`, l'endpoint prova a usare la traduzione del brand parent nella lingua del brand creato o aggiornato
- `thumbnail` puo' essere un URL remoto da importare nella Media Library, oppure l'`attachment_id` (intero) di un file gia' presente in Media Library (es. caricato con `POST /media`); il valore risolto viene salvato come `thumbnail_id`; se `null`, rimuove la thumbnail; se omesso, lascia invariata quella esistente
- con WPML attivo, le mappe lingua creano o aggiornano le traduzioni del brand
- se il payload contiene mappe lingua ma WPML non e' installato o attivo, ritorna `500 wpml_required`
- il brand si assegna ai prodotti tramite il campo `brand` di `POST /woocommerce/products`, usando il `local_key` (intero o stringa) del brand, ad esempio `"brand": 4002`

Response `200`:

```json
[701]
```

Errori principali:

- `400 invalid_param`
- `404 not_found`
- `409 duplicate_local_key`
- `500 woocommerce_required`
- `500 wpml_required` se il payload contiene valori multilingua ma WPML non e' installato o attivo
- `500 request_failed`

### DELETE `/woocommerce/brands`

Elimina brand WooCommerce per `local_key`. Se piu' termini condividono lo stesso `local_key` (es. traduzioni WPML), vengono eliminati tutti.

Body:

```json
[4002]
```

Query opzionale:

```text
?ignore=1
```

Response `200`:

```json
null
```

### GET `/woocommerce/attributes`

Restituisce gli attributi prodotto globali WooCommerce, cioe' le definizioni che generano tassonomie `pa_*` come `pa_color` o `pa_size`.

**Paginazione:** no — la risposta contiene sempre **tutti** gli attributi che soddisfano i filtri in un'unica chiamata, nessun `per_page`/`page`.

Query opzionale:

```text
?id=31
?local_key=5001
?slug=color
?slug=pa_color
?name=Color
```

- `name` esegue una ricerca per **nome esatto** dell'attributo (il label, es. `Color`, case-sensitive) e restituisce tutti gli attributi con quel nome. Gli attributi globali non sono traducibili con WPML, quindi non c'e' grouping per traduzione: il campo `translations` e' presente per coerenza di struttura ma sempre vuoto (`{}`).

Response `200`:

```json
[
  {
    "id": 31,
    "local_key": 5001,
    "name": "Color",
    "slug": "pa_color",
    "attribute_slug": "color",
    "type": "select",
    "order_by": "menu_order",
    "has_archives": false,
    "taxonomy": "pa_color",
    "translations": {}
  }
]
```

### POST `/woocommerce/attributes`

Crea o aggiorna attributi prodotto globali WooCommerce in batch.

Body:

```json
[
  {
    "local_key": 5001,
    "name": "Color",
    "slug": "color",
    "type": "select",
    "order_by": "menu_order",
    "has_archives": false
  }
]
```

Campi:

- `id` opzionale, se presente aggiorna **quell'**attributo (ha la precedenza, come in `POST /woocommerce/products`); in questo caso `local_key` non e' obbligatorio
- `local_key` obbligatorio **solo se `id` non e' presente**
- `name` richiesto in creazione
- `slug` opzionale; puo' essere inviato come `color` o `pa_color`
- `type` opzionale, default WooCommerce `select`
- `order_by` opzionale, default WooCommerce `menu_order`
- `has_archives` opzionale, default `false`

Comportamento (in ordine di precedenza):

- se `id` e' presente, viene aggiornato quell'attributo (404 se l'id non esiste); se passi anche `local_key` viene (ri)associato a quell'attributo
- altrimenti, se `local_key` esiste gia', viene aggiornato l'attributo associato a quel `local_key`
- altrimenti, se `slug` corrisponde a un attributo esistente, viene aggiornato quell'attributo
- altrimenti viene creato un nuovo attributo
- la response contiene gli ID degli attributi creati o aggiornati
- WooCommerce limita lo slug non prefissato a 28 caratteri e rifiuta nomi riservati o gia' usati
- i valori dell'attributo si gestiscono come termini della tassonomia generata, ad esempio `pa_color`

Response `200`:

```json
[31]
```

Errori principali:

- `400 invalid_param`
- `400 invalid_product_attribute_slug_too_long`
- `400 invalid_product_attribute_slug_reserved_name`
- `400 invalid_product_attribute_slug_already_exists`
- `409 duplicate_local_key`
- `404 not_found`
- `500 woocommerce_required`
- `500 request_failed`

### DELETE `/woocommerce/attributes`

Elimina attributi prodotto globali WooCommerce per `local_key`. La cancellazione usa `wc_delete_attribute()` e rimuove anche i termini della tassonomia attributo quando la tassonomia e' registrata.

Body:

```json
[5001]
```

Query opzionale:

```text
?ignore=1
```

Response `200`:

```json
null
```

### GET `/woocommerce/attributes/{attribute}/terms`

Restituisce i valori/termini di un attributo prodotto globale WooCommerce.

`{attribute}` puo' essere:

- ID attributo, ad esempio `31`
- slug non prefissato, ad esempio `color`
- tassonomia attributo, ad esempio `pa_color`

**Paginazione:** no — la risposta contiene sempre **tutti** i termini dell'attributo che soddisfano i filtri in un'unica chiamata, nessun `per_page`/`page`.

Query opzionale:

```text
?id=101
?local_key=5101
?slug=red
?name=Red
?name[it]=Rosso&name[en]=Red
```

- `name` esegue una ricerca per **nome esatto** del termine e accetta due forme:
  - **stringa** (`?name=Red`): ricerca nella lingua corrente della richiesta;
  - **mappa lingua** (`?name[it]=Rosso&name[en]=Red`): ricerca ogni nome nel contesto della rispettiva lingua WPML e ne restituisce l'**unione**.
- La risposta è **sempre una lista** con **un oggetto per ogni gruppo di traduzione distinto** (rappresentante in lingua di default), con la mappa `translations`: nomi che sono traduzioni dello stesso termine → un solo oggetto; nomi di gruppi diversi → più oggetti. Stesso comportamento di `GET /woocommerce/products?name=`.

Response `200`:

```json
[
  {
    "id": 101,
    "name": "Red",
    "slug": "red",
    "description": "",
    "taxonomy": "pa_color",
    "local_key": 5101,
    "acf_fields": false
  }
]
```

### POST `/woocommerce/attributes/{attribute}/terms`

Crea o aggiorna valori/termini per un attributo prodotto globale WooCommerce.

Body:

```json
[
  {
    "local_key": 5101,
    "name": {
      "en": "Red",
      "it": "Rosso"
    },
    "slug": {
      "en": "red",
      "it": "rosso"
    },
    "description": "Color option"
  }
]
```

Comportamento:

- `{attribute}` viene risolto nella tassonomia WooCommerce `pa_*`
- `local_key` viene salvato come term meta `onpage_local_key`
- se passi un `id` che **esiste**, viene aggiornato quel termine e il `local_key` indicato viene scritto su di esso e su tutte le sue traduzioni; un `id` **non esistente** (stale) viene ignorato e l'upsert e' guidato dal `local_key`
- `name` e' obbligatorio in creazione e puo' essere una stringa o una mappa lingua WPML
- se `name` e' una stringa e altri campi sono mappe lingua, lo stesso nome viene usato invariato per tutte le traduzioni; se manca uno `slug` per lingua, le traduzioni ricevono uno slug tecnico distinto
- `slug`, `description` e `acf_fields` supportano mappe lingua WPML come gli altri termini
- se la tassonomia attributo ha un field group ACF con campo `local_key`, il valore viene riallineato anche li'
- la response contiene gli ID dei termini creati o aggiornati nella lingua base

Response `200`:

```json
[101]
```

### DELETE `/woocommerce/attributes/{attribute}/terms`

Elimina termini di un attributo prodotto globale WooCommerce per `local_key`.

Body:

```json
[5101]
```

Query opzionale:

```text
?ignore=1
```

Response `200`:

```json
null
```

### GET `/woocommerce/categories`

Restituisce le categorie prodotto WooCommerce salvate nella tassonomia `product_cat`.

**Paginazione:** no — la risposta contiene sempre **tutte** le categorie che soddisfano i filtri in un'unica chiamata, nessun `per_page`/`page`.

Query opzionale:

```text
?id=12
?local_key=6001
?slug=chairs
?name=Chairs
?name[it]=Sedie&name[en]=Chairs
?parent_id=575
?parent_lk=6000
```

- `name` esegue una ricerca per **nome esatto** del termine e accetta due forme:
  - **stringa** (`?name=Chairs`): ricerca nella lingua corrente della richiesta;
  - **mappa lingua** (`?name[it]=Sedie&name[en]=Chairs`): ricerca ogni nome nel contesto della rispettiva lingua WPML e ne restituisce l'**unione**.
- La risposta è **sempre una lista** con **un oggetto per ogni gruppo di traduzione distinto** (rappresentante in lingua di default), con la mappa `translations`: nomi che sono traduzioni dello stesso termine → un solo oggetto; nomi di gruppi diversi → più oggetti. Stesso comportamento di `GET /woocommerce/products?name=`.
- `parent_id` (WP term id) e `parent_lk` (local_key del parent) filtrano restituendo i **figli diretti** del parent indicato. `parent_lk` viene espanso a **tutte le traduzioni WPML** del parent (i figli vengono cercati sotto ognuna); la visibilità dei figli per lingua segue le stesse regole del listing normale. I due parametri sono **mutuamente esclusivi** (`400` se passati insieme); un `parent_lk` che non risolve nessun termine restituisce lista vuota. Precedenza: `id` > `local_key` > `slug` > `name` > `parent_*`.

Response `200`:

```json
[
  {
    "id": 12,
    "name": "Chairs",
    "slug": "chairs",
    "local_key": 6001,
    "description": "",
    "parent": 0,
    "count": 0,
    "taxonomy": "product_cat",
    "translations": {
      "it": 12,
      "en": 13
    },
    "acf_fields": {}
  }
]
```

- `translations` e' presente in tutte le response (singolo/lista/ricerca); senza WPML attivo e' una mappa vuota.

### POST `/woocommerce/categories`

Crea o aggiorna categorie prodotto WooCommerce usando lo stesso payload dei termini (`POST /terms`).

Body:

```json
[
  {
    "local_key": 6001,
    "name": {
      "en": "Chairs",
      "it": "Sedie"
    },
    "slug": {
      "en": "chairs",
      "it": "sedie"
    },
    "description": "Product category",
    "thumbnail": "https://cdn.example.com/chairs-thumbnail.png",
    "parent": 6000,
    "acf_fields": {
      "image": "https://cdn.example.com/chairs.png"
    }
  }
]
```

Comportamento:

- `name`, `slug`, `description`, `local_key` e `acf_fields` hanno lo stesso comportamento dei termini
- se `name` e' una stringa e altri campi sono mappe lingua, lo stesso nome categoria viene usato invariato per tutte le traduzioni; se manca uno `slug` per lingua, le traduzioni ricevono uno slug tecnico distinto
- `parent` e' opzionale; se presente deve essere `null` oppure il `local_key` (intero o stringa) della categoria parent, ad esempio `"parent": 6000`
- `parent` non accetta `0` o ID WordPress numerici; per creare una categoria di primo livello usare `null` oppure omettere il campo
- la categoria parent deve esistere gia' o essere stata creata prima nello stesso batch
- se `local_key` esiste gia', l'endpoint aggiorna in-place le categorie `product_cat` e le traduzioni WPML con quel `local_key`, preservando le associazioni prodotto-categoria; se `local_key` non esiste, crea categorie nuove per le lingue presenti
- per `/woocommerce/categories`, se passi un `id` che **esiste** viene aggiornata quella categoria e il `local_key` indicato viene scritto su di essa e su tutte le sue traduzioni; un `id` **non esistente** (stale) viene ignorato e l'upsert e' guidato dal `local_key`
- `thumbnail` puo' essere un URL remoto da importare nella Media Library, oppure l'`attachment_id` (intero) di un file gia' presente in Media Library (es. caricato con `POST /media`); il valore risolto viene salvato come `thumbnail_id`; se `null`, rimuove la thumbnail; se omesso, lascia invariata quella esistente
- con WPML attivo, le mappe lingua creano o aggiornano le traduzioni della categoria
- con WPML attivo, quando `parent` e' un `local_key`, l'endpoint prova a usare la traduzione della categoria parent nella lingua della categoria creata o aggiornata
- una categoria con `local_key` diverso non viene toccata: se ha lo **stesso nome sotto lo stesso parent** la collisione fallisce con `term_exists` (vedi [Conflitto `term_exists` sui termini](#conflitto-term_exists-sui-termini)); se invece è sotto un **parent diverso**, WordPress crea un nuovo termine con uno slug de-duplicato (`silicone-acetico` → `silicone-acetico-2`)
- se il payload contiene mappe lingua ma WPML non e' installato o attivo, ritorna `500 wpml_required`
- le categorie si assegnano ai prodotti tramite il campo `categories` di `POST /woocommerce/products`, usando il `local_key` (intero o stringa) della categoria, ad esempio `"categories": [6001]`

Response `200`:

```json
[12]
```

### DELETE `/woocommerce/categories`

Elimina categorie prodotto WooCommerce per `local_key`. Se piu' termini condividono lo stesso `local_key` (es. traduzioni WPML), vengono eliminati tutti.

Body:

```json
[6001]
```

Query opzionale:

```text
?ignore=1
```

Response `200`:

```json
null
```

### GET `/woocommerce/tags`

Restituisce i tag prodotto WooCommerce salvati nella tassonomia `product_tag`.

**Paginazione:** no — la risposta contiene sempre **tutti** i tag che soddisfano i filtri in un'unica chiamata, nessun `per_page`/`page`.

Query opzionale:

```text
?id=22
?local_key=7001
?slug=featured
?name=Featured
?name[it]=In evidenza&name[en]=Featured
```

- `name` esegue una ricerca per **nome esatto** del termine e accetta due forme:
  - **stringa** (`?name=Featured`): ricerca nella lingua corrente della richiesta;
  - **mappa lingua** (`?name[it]=In evidenza&name[en]=Featured`): ricerca ogni nome nel contesto della rispettiva lingua WPML e ne restituisce l'**unione**.
- La risposta è **sempre una lista** con **un oggetto per ogni gruppo di traduzione distinto** (rappresentante in lingua di default), con la mappa `translations`: nomi che sono traduzioni dello stesso termine → un solo oggetto; nomi di gruppi diversi → più oggetti. Stesso comportamento di `GET /woocommerce/products?name=`.

Response `200`:

```json
[
  {
    "id": 22,
    "name": "Featured",
    "slug": "featured",
    "local_key": 7001,
    "description": "",
    "parent": 0,
    "count": 0,
    "taxonomy": "product_tag",
    "translations": {
      "it": 22,
      "en": 23
    },
    "acf_fields": {}
  }
]
```

- `translations` e' presente in tutte le response (singolo/lista/ricerca); senza WPML attivo e' una mappa vuota.

### POST `/woocommerce/tags`

Crea o aggiorna tag prodotto WooCommerce usando lo stesso payload dei termini (`POST /terms`).

Body:

```json
[
  {
    "local_key": 7001,
    "name": {
      "en": "Featured",
      "it": "In evidenza"
    },
    "slug": {
      "en": "featured",
      "it": "in-evidenza"
    },
    "description": "Product tag"
  }
]
```

Comportamento:

- `name`, `slug`, `description`, `local_key` e `acf_fields` hanno lo stesso comportamento dei termini
- se `name` e' una stringa e altri campi sono mappe lingua, lo stesso nome tag viene usato invariato per tutte le traduzioni; se manca uno `slug` per lingua, le traduzioni ricevono uno slug tecnico distinto
- ogni elemento del body deve essere un oggetto tag; non sono accettate stringhe semplici o mappe lingua-valore come elemento top-level
- se `local_key` esiste gia', l'endpoint aggiorna in-place i tag `product_tag` e le traduzioni WPML con quel `local_key`, preservando le associazioni prodotto-tag; se `local_key` non esiste, crea tag nuovi per le lingue presenti
- per `/woocommerce/tags`, se passi un `id` che **esiste** viene aggiornato quel tag e il `local_key` indicato viene scritto su di esso e su tutte le sue traduzioni; un `id` **non esistente** (stale) viene ignorato e l'upsert e' guidato dal `local_key`
- con WPML attivo, le mappe lingua creano o aggiornano le traduzioni del tag
- se il payload contiene mappe lingua ma WPML non e' installato o attivo, ritorna `500 wpml_required`
- `name` e `slug` possono essere mappe lingua-valore dentro l'oggetto tag, ad esempio `{ "en": "petrol", "it": "benzina" }`
- i tag si assegnano ai prodotti tramite il campo `tags` di `POST /woocommerce/products`, usando il `local_key` (intero o stringa) del tag, ad esempio `"tags": [7001]`

Response `200`:

```json
[22]
```

### DELETE `/woocommerce/tags`

Elimina tag prodotto WooCommerce per `local_key`. Se piu' termini condividono lo stesso `local_key` (es. traduzioni WPML), vengono eliminati tutti.

Body:

```json
[7001]
```

Query opzionale:

```text
?ignore=1
```

Response `200`:

```json
null
```

### GET `/woocommerce/products`

Restituisce prodotti WooCommerce. Senza query restituisce tutti i prodotti.

**Paginazione:** no — la risposta contiene sempre **tutti** i prodotti che soddisfano i filtri in un'unica chiamata, nessun `per_page`/`page`. Su cataloghi molto grandi la risposta puo' essere pesante.

Query opzionale:

```text
?id=501
?local_key=1001
?name=Red Chair
?name[it]=Sedia Rossa&name[en]=Red Chair
```

- `id` e `local_key` restituiscono il singolo prodotto corrispondente. Poiche' tutte le traduzioni WPML condividono la `local_key`, `?local_key=` restituisce il prodotto in **lingua di default** del gruppo (stesso criterio di rappresentante usato da `?name=`), con gli altri id nella mappa `translations`.
- `name` esegue una ricerca per **titolo esatto**. I risultati sono raggruppati per gruppo di traduzione: viene restituito **un solo oggetto per gruppo** (rappresentante in lingua di default quando disponibile), e la mappa `translations` contiene tutti gli id multilang.
- `name` accetta due forme:
  - **stringa** (`?name=Red Chair`): ricerca il titolo nella lingua corrente della richiesta.
  - **mappa lingua** (`?name[it]=Sedia Rossa&name[en]=Red Chair`): ricerca ogni titolo nel contesto della rispettiva lingua WPML e restituisce **l'unione** dei risultati, deduplicati per gruppo di traduzione.
- La risposta è **sempre una lista** con **un oggetto per ogni gruppo di traduzione distinto** tra i match:
  - se i valori passati sono le traduzioni dello **stesso** prodotto → **un solo oggetto** (con tutte le lingue nella mappa `translations`);
  - se i valori corrispondono a prodotti di **gruppi diversi** → **più oggetti** (uno per gruppo);
  - una lingua il cui titolo non matcha nulla non contribuisce risultati (nessun errore).

Response `200`:

```json
[
  {
    "id": 501,
    "title": "Red Chair",
    "long_description": "Long description",
    "short_description": "Short description",
    "content": "Long description",
    "description": "Short description",
    "type": "product",
    "status": "publish",
    "local_key": 1001,
    "translations": {
      "it": 501,
      "en": 502,
      "fr": 503
    },
    "woocommerce": {
      "product_type": "simple",
      "sku": "CHAIR-RED",
      "regular_price": "49.90",
      "sale_price": "",
      "price": "49.90",
      "stock_status": "instock",
      "image_id": 601,
      "gallery_image_ids": []
    },
    "acf_fields": {
      "custom_badge": "New",
      "manual_pdf": 1234,
      "certifications": [
        { "nome": "Marcatura CE", "anno": 2024 },
        { "nome": "VOC A+", "anno": 2023 }
      ]
    },
    "terms": []
  }
]
```

Note sulla risposta:

- `acf_fields` contiene i valori risolti da `get_fields()`: i repeater vengono restituiti nativamente come liste di oggetti (uno per riga). I sub-field `image`/`file` sono attachment ID o array a seconda del `return_format` impostato sul field group
- i campi ACF di tipo `tab` non compaiono in `acf_fields` (sono separatori UI senza valore)
- `translations` mappa ogni codice lingua all'ID del prodotto in quella lingua (gruppo di traduzione WPML). Senza WPML attivo la mappa puo' essere vuota. L'`id` di primo livello e' quello del rappresentante restituito

### POST `/woocommerce/products`

Crea o aggiorna prodotti WooCommerce usando un payload specifico per i prodotti.

Body:

```json
[
  {
    "local_key": 1001,
    "name": {
      "en": "Red Chair [en]",
      "it": "Red Chair [it]",
      "es": "Red Chair [es]",
      "ru": "Red Chair [ru]"
    },
    "long_description": {
      "en": "<p>Long product description [en]</p>",
      "it": "<p>Descrizione lunga prodotto [it]</p>"
    },
    "short_description": {
      "en": "Short product description [en]",
      "it": "Descrizione breve prodotto [it]"
    },
    "slug": {
      "en": "red-chair",
      "it": "sedia-rossa"
    },
    "update_slug": false,
    "brand": 4002,
    "categories": [6011, 6012],
    "tags": [7001, 7002],
    "terms": {
      "tipologia": [112631840],
      "materiale": [8001, 8002]
    },
    "props": {
      "product_type": "simple",
      "sku": "CHAIR-RED",
      "regular_price": "49.90",
      "stock_status": "instock"
    },
    "image": "https://storage.example.com/red-chair.jpg",
    "gallery": [
      "https://storage.example.com/red-chair-side.jpg",
      "https://storage.example.com/red-chair-back.jpg"
    ],
    "attributes": {
      "Color": {
        "en": "Red",
        "it": "Rosso",
        "es": "Rojo",
        "ru": "Red"
      },
      "Doors": 5,
      "Fuel": ["Petrol", "Hybrid"]
    },
    "acf_fields": {
      "custom_badge": "New",
      "manual_pdf": "https://cdn.example.com/manual.pdf",
      "certifications": [
        { "nome": "Marcatura CE", "anno": 2024, "allegato": "https://cdn.example.com/ce.pdf" },
        { "nome": "VOC A+", "anno": 2023 }
      ],
      "product_subtitle": {
        "it": "Sigillante monocomponente ad alto modulo",
        "en": "High-modulus one-component sealant"
      },
      "list_of_main_charatteristics": {
        "it": [
          { "lomc_field": "Pasta tissotropica" },
          { "lomc_field": "Varie colorazioni" }
        ],
        "en": [
          { "lomc_field": "Thixotropic paste" },
          { "lomc_field": "Various colours" }
        ]
      },
      "scheda": {
        "it": { "titolo": "Scheda IT", "allegato": "https://cdn.example.com/scheda-it.pdf" },
        "en": { "titolo": "Datasheet EN", "allegato": "https://cdn.example.com/scheda-en.pdf" }
      }
    },
    "downloads": [
      {
        "id": "scheda_tecnica",
        "name": "Scheda tecnica",
        "url": "https://cdn.example.com/scheda-tecnica.pdf"
      },
      {
        "id": "scheda_sicurezza",
        "name": "Scheda di sicurezza",
        "url": "https://cdn.example.com/scheda-sicurezza.pdf"
      }
    ],
    "status": "publish",
    "id": 501
  }
]
```

Comportamento:

- `local_key` e `name` sono obbligatori; `name` puo' essere una stringa o una mappa lingua WPML
- se `name` e' una stringa, lo stesso nome viene usato invariato per tutte le traduzioni create da altri campi multilingua; per nomi diversi per lingua, usare una mappa WPML
- se l'elemento contiene `id`, aggiorna il prodotto esistente; se manca `id` ma `local_key` esiste gia', aggiorna quel prodotto; altrimenti crea un prodotto WooCommerce
- `long_description` imposta la descrizione lunga WooCommerce del prodotto; puo' essere una stringa o una mappa lingua WPML
- `short_description` imposta la descrizione breve WooCommerce del prodotto; puo' essere una stringa o una mappa lingua WPML
- `long_description` e `short_description` vanno passati come campi top-level del prodotto, non dentro `props` o `acf_fields`
- per compatibilita', `content` e `description` sono ancora accettati come alias di `long_description` e `short_description`; se sono presenti entrambi, prevalgono `long_description` e `short_description`
- `status` e' opzionale e vale `publish` se omesso
- `slug` e' opzionale e personalizza il permalink del prodotto (`post_name`); il valore viene sanificato con `sanitize_title`. Se omesso, lo slug resta invariato (in creazione WordPress lo genera dal titolo, in update non viene toccato); cambiare solo `name` non modifica il permalink. Puo' essere una stringa o una mappa lingua WPML, ad esempio `{ "it": "sedia-rossa", "en": "red-chair" }`; se passi una stringa singola con piu' traduzioni, WordPress rende gli slug unici aggiungendo un suffisso. Uno `slug` vuoto o `null` viene ignorato (non azzera lo slug esistente)
- `update_slug` e' opzionale, booleano, default `false`. Controlla quando lo `slug` inviato viene applicato: con `false` lo slug viene impostato **solo in creazione** e gli update successivi non lo toccano (preserva i permalink esistenti); con `true` lo slug viene riscritto **anche in update**. Ha effetto solo se `slug` e' presente nel payload
- `local_key` viene salvato come post meta `onpage_local_key` e deve essere unico fra i prodotti
- la `local_key` viene scritta su **tutte** le lingue del gruppo di traduzione WPML (identifica lo stesso elemento On Page®, non una singola lingua), e viene scritta prima del lavoro lento dell'import (media, ACF, termini) perche' un'interruzione non lasci traduzioni senza chiave
- se altri prodotti fuori dal gruppo portano ancora la stessa `local_key` (residui di un import interrotto o di due import concorrenti sullo stesso elemento), l'update li riconcilia invece di rifiutare la richiesta: chi occupa una lingua ancora libera viene agganciato al gruppo, chi duplica una lingua gia' presente viene eliminato. `409 duplicate_local_key` resta solo quando la richiesta indica un `id` esplicito e quella `local_key` appartiene a un altro prodotto
- i campi WooCommerce nativi vanno passati dentro `props`: `sku`, `regular_price`, `sale_price`, `price`, `manage_stock`, `stock_quantity`, `stock_status`, `backorders`, `sold_individually`, `weight`, `length`, `width`, `height`, `virtual`, `downloadable`, `featured`, `catalog_visibility`, `tax_status`, `tax_class`, `purchase_note`, `menu_order`, `reviews_allowed`, `product_type`
- `props.product_type` puo' essere `simple` oppure `variable`; se omesso, in creazione vale `simple`
- `image` puo' essere un URL remoto da importare e assegnare come immagine principale del prodotto, oppure l'`attachment_id` (intero JSON) di un file gia' presente in Media Library (es. caricato con `POST /media`); `image: null` rimuove l'immagine; se omesso, lascia invariata quella esistente
- `image` puo' essere una mappa lingua WPML, ad esempio `{ "en": "https://cdn.example.com/en/chair.jpg", "it": "https://cdn.example.com/it/chair.jpg" }`; ogni traduzione riceve la propria immagine
- `gallery` sostituisce l'intera galleria immagini del prodotto con la lista inviata, i cui elementi possono essere URL remoti e/o `attachment_id`; ogni URL viene importato/riusato in Media Library con le stesse regole di `image` (gli URL gia' importati riusano l'attachment esistente, senza duplicati nemmeno all'interno della stessa lista), mentre un `attachment_id` viene assegnato direttamente senza download; l'ordine della lista determina l'ordine in galleria; se omesso, la galleria resta invariata, mentre `gallery: []` o `gallery: null` la svuota
- `gallery` puo' essere una mappa lingua WPML di liste, ad esempio `{ "en": ["https://cdn.example.com/en/1.jpg"], "it": ["https://cdn.example.com/it/1.jpg"] }`; ogni traduzione riceve la propria galleria. Il valore risolto per ogni lingua deve essere una lista di URL (un oggetto o una stringa singola ritornano `400 invalid_param`)
- `attributes` sostituisce l'intero set di attributi custom del prodotto con quelli inviati nel payload; il valore puo' essere stringa/numero, lista di valori o mappa lingua WPML
- quando `product_type` e' `variable`, gli `attributes` inviati vengono marcati come attributi usabili dalle variazioni (`variation=true`)
- se `attributes` e' omesso, gli attributi esistenti non vengono modificati; se vale `null` o `{}`, tutti gli attributi custom vengono rimossi
- dentro `attributes`, una chiave con valore `null` o lista vuota viene ignorata nel nuovo set finale
- `acf_fields` resta dedicato ai campi ACF del prodotto; le chiavi devono essere nomi tecnici ACF o field key ACF esistenti sul field group del prodotto (campi non esistenti ritornano `400 invalid_param`). Per la semantica completa dei tipi (`tab`, `repeater`, `image`/`file` URL → `attachment_id`, ecc.) vedi la sezione [Gestione `acf_fields`](#gestione-acf_fields)
- `downloads` gestisce i file scaricabili nativi WooCommerce, separati da ACF; puo' essere una lista di oggetti oppure `null`
- ogni elemento di `downloads` accetta `url` oppure `file` con un URL remoto valido, oppure l'`attachment_id` (intero JSON) di un file gia' presente in Media Library (es. caricato con `POST /media`); il valore puo' essere `null` o stringa vuota per saltare quel download nella lingua risolta; `name` e `id` sono opzionali
- per i PDF prodotto Sigil usare `id` stabili: `scheda_tecnica` per la scheda tecnica generata da Publisher e `scheda_sicurezza` per la scheda di sicurezza caricata dal team prodotto
- se `downloads.file` non punta gia' a un URL dentro `wp-content/uploads`, il file viene importato/riusato in Media Library e WooCommerce usera' l'URL locale importato (compatibile con le approved download directories)
- se `downloads.file` e' un `attachment_id`, il plugin non scarica nulla: verifica solo che l'ID corrisponda a un attachment esistente e usa direttamente il suo URL locale
- se l'URL remoto e' gia' stato importato, viene riusato l'attachment esistente senza riscaricare il file
- se un PDF remoto cambia mantenendo lo stesso URL, passare `refresh: true` sul download per sostituire l'attachment in Media Library mantenendo lo stesso attachment ID quando possibile
- i download gestiti da questo endpoint vengono collegati al prodotto parent in Media Library e mostrati pubblicamente nella pagina prodotto WooCommerce
- se `downloads` contiene almeno un file valido, il prodotto viene marcato automaticamente come scaricabile (`downloadable=true`)
- per prodotti `variable`, WooCommerce mostra i download nell'admin sulle singole variazioni: l'endpoint copia automaticamente i download del parent sulle variazioni esistenti
- `downloads: []` o `downloads: null` rimuove tutti i file scaricabili WooCommerce del prodotto
- `name`, `url` e `file` dentro `downloads` possono essere mappe lingua WPML; ogni traduzione riceve il proprio valore risolto e i valori `null` o vuoti vengono ignorati
- `brand` assegna un singolo riferimento della tassonomia `product_brand`; il riferimento e' il `local_key` (intero o stringa) del brand — gli slug non sono supportati; `null` rimuove il brand se la tassonomia esiste
- `categories` sostituisce l'intero set di categorie `product_cat` con i riferimenti inviati; ogni riferimento e' il `local_key` (intero o stringa) della categoria — gli slug non sono supportati
- `tags` sostituisce l'intero set di tag `product_tag` con i riferimenti inviati; ogni riferimento e' il `local_key` (intero o stringa) del tag — gli slug non sono supportati
- `categories` e `tags` possono essere liste di `local_key`, ad esempio `[6001]` o `[7001, 7002]`
- `categories` e `tags` possono essere mappe lingua WPML, ad esempio `{ "en": 6011, "it": 6013 }`; per ogni traduzione del prodotto viene usato il `local_key` della lingua corrispondente (il `local_key` identifica comunque il gruppo di traduzione, quindi tipicamente basta un solo valore)
- dentro una mappa lingua, ogni valore puo' essere un `local_key` singolo, una lista di `local_key` o `null` per non assegnare termini in quella lingua
- se `brand`, `categories` o `tags` sono omessi, l'endpoint non modifica quella tassonomia; `brand: null` rimuove il brand, mentre `categories: []`, `categories: null`, `tags: []` o `tags: null` rimuovono categorie o tag
- `terms` e' opzionale e permette di assegnare termini di **qualsiasi tassonomia** registrata sul prodotto (incluse tassonomie ACF/custom come `tipologia`), oltre a brand/categorie/tag. E' un oggetto `{"<taxonomy_slug>": <riferimenti>}` dove i riferimenti seguono le stesse regole di `categories`/`tags`: lista di `local_key` (interi positivi), valore singolo, mappa lingua WPML, oppure `null`/`[]` per svuotare quella tassonomia. Ogni tassonomia inviata sostituisce l'intero set assegnato. I riferimenti sono risolti per `local_key`, in qualsiasi tassonomia; gli slug non sono supportati. Se una tassonomia in `terms` coincide con `product_cat`/`product_tag`/`product_brand`, prevale il campo dedicato (`categories`/`tags`/`brand`) quando presente. Una tassonomia inesistente o un termine non trovato ritornano `404`
- per creare varianti con `/woocommerce/variant-products`, il prodotto parent deve essere `variable` e deve avere attributi di variazione configurati

Esempio categorie multilingua:

```json
"categories": {
  "en": 6011,
  "it": 6013
}
```

Esempio categorie multiple per lingua:

```json
"categories": {
  "en": [6011, 6012],
  "it": [6013, 6014]
}
```

Esempio brand, categorie e tag per `local_key`:

```json
"brand": 4002,
"categories": [6011, 6012],
"tags": [7001, 7002]
```

Esempio parent variable per variazioni:

```json
[
  {
    "local_key": 1002,
    "name": "Shirt",
    "props": {
      "product_type": "variable"
    },
    "attributes": {
      "pa_color": ["red", "blue"],
      "pa_size": ["s", "m"]
    }
  }
]
```

Response `200`:

```json
[501]
```

Errori principali:

- `400 invalid_param` se il body non e' un array JSON valido, se manca `local_key`/`name`, se `name` non e' una stringa o mappa lingua valida, se `slug` non e' una stringa o mappa lingua valida, se `update_slug` non e' un booleano, se `props`/`attributes`/`acf_fields` non sono oggetti, se `acf_fields` contiene campi ACF non esistenti per il prodotto, se `downloads` non e' una lista valida, se `categories`/`tags` non sono liste o mappe lingua valide o se `terms` non e' un oggetto `taxonomy => riferimenti` valido
- `404 not_found` se un prodotto o termine referenziato non esiste
- `409 duplicate_local_key` solo con `id` esplicito nel payload, quando quella `local_key` appartiene a un prodotto diverso; senza `id` la chiave identifica il prodotto da aggiornare e gli eventuali residui vengono riconciliati
- `409 duplicate_title` se il titolo esiste gia' su un oggetto **senza `local_key`**, fuori dal gruppo di traduzione di questo elemento; un oggetto che porta una `local_key` diversa e' un altro elemento On Page® e non fa conflitto, quindi due elementi omonimi si importano entrambi
- `500 woocommerce_required` se WooCommerce non e' attivo
- `500 wpml_required` se il payload contiene valori multilingua ma WPML non e' installato o attivo; il sistema non puo' gestire mappe lingua finche' WPML non viene installato e attivato
- `500 request_failed` se WooCommerce o WordPress non riescono a salvare il prodotto

### DELETE `/woocommerce/products`

Elimina prodotti WooCommerce per `local_key`. Se piu' prodotti condividono lo stesso `local_key` (traduzioni WPML), vengono eliminati **tutti** — l'intero gruppo di traduzione, come per i brand.

Body:

```json
[1001]
```

Query opzionale:

```text
?ignore=1
```

Response `200`:

```json
null
```

### GET `/woocommerce/variant-products`

Restituisce variazioni WooCommerce (`product_variation`). Senza query restituisce tutte le variazioni.

**Paginazione:** no — la risposta contiene sempre **tutte** le variazioni che soddisfano i filtri in un'unica chiamata, nessun `per_page`/`page`. Su cataloghi molto grandi la risposta puo' essere pesante.

Query opzionale:

```text
?id=701
?local_key=2001
?parent_id=501
?parent=1002
```

Response `200`:

```json
[
  {
    "id": 701,
    "parent_id": 501,
    "parent": 1002,
    "local_key": 2001,
    "status": "publish",
    "description": "Red / Small",
    "attributes": {
      "pa_color": "red",
      "pa_size": "s"
    },
    "woocommerce": {
      "product_type": "variation",
      "sku": "SHIRT-RED-S",
      "regular_price": "29.90",
      "sale_price": "",
      "price": "29.90",
      "stock_status": "instock",
      "image_id": 601
    },
    "acf_fields": {
      "material": "cotton"
    }
  }
]
```

### POST `/woocommerce/variant-products`

Crea o aggiorna variazioni WooCommerce per un parent product `variable` gia' esistente.

Body:

```json
[
  {
    "local_key": 2001,
    "parent": 1002,
    "attributes": {
      "pa_color": "red",
      "pa_size": "s"
    },
    "props": {
      "sku": "SHIRT-RED-S",
      "regular_price": "29.90",
      "manage_stock": true,
      "stock_quantity": 10,
      "stock_status": "instock"
    },
    "description": "Red / Small",
    "status": "publish",
    "image": "https://storage.example.com/shirt-red-s.jpg",
    "acf_fields": {
      "material": "cotton",
      "swatch": "https://cdn.example.com/swatch-red.jpg"
    }
  }
]
```

Comportamento:

- `local_key` e' obbligatorio e viene salvato come post meta `onpage_local_key` sulla variazione
- bisogna passare `parent_id` oppure `parent`; se sono presenti entrambi devono riferirsi allo stesso prodotto
- il parent deve essere un prodotto WooCommerce `variable`; puo' essere creato o convertito con `POST /woocommerce/products` usando `props.product_type: "variable"`
- `attributes` e' obbligatorio in creazione e deve essere un oggetto non vuoto; in update puo' essere omesso per lasciare invariati gli attributi della variazione
- ogni attributo inviato deve esistere sul parent ed essere marcato come attributo per variazioni (`variation=true`)
- ogni attributo della variazione deve risolvere a una singola opzione scalare; sono accettate anche mappe lingua WPML come `{ "en": "Red", "it": "Rosso" }`, mentre liste come `["Petrol", "Hybrid"]` non sono valide per una singola variante
- se un attributo della variazione contiene una mappa lingua ma WPML non e' installato o attivo, ritorna `500 wpml_required`
- per attributi globali (`pa_color`) il valore puo' essere slug, nome o ID del termine; sulla variazione viene salvato lo slug termine WooCommerce
- per attributi custom il valore deve essere una delle opzioni configurate sul parent
- se l'elemento contiene `id`, aggiorna quella variazione; se manca `id` ma `local_key` esiste gia', aggiorna quella variazione; altrimenti crea una nuova variazione
- `props` accetta: `sku`, `regular_price`, `sale_price`, `price`, `manage_stock`, `stock_quantity`, `stock_status`, `backorders`, `weight`, `length`, `width`, `height`, `virtual`, `downloadable`, `tax_class`, `menu_order`, `image_id`
- con WPML attivo, `props.sku` viene applicato solo alla variazione sorgente: WooCommerce richiede SKU globalmente univoci e le variazioni tradotte non possono salvare lo stesso SKU
- `status` sulle variazioni accetta `publish`/`enabled` per una variazione abilitata e `private`/`disabled` per una variazione disabilitata; per compatibilita' `draft` e `pending` vengono salvati come `private`, perche' WooCommerce admin non mostra variazioni con status `draft`
- `image` puo' essere un URL remoto da importare e assegnare come immagine della variazione, oppure l'`attachment_id` (intero JSON) di un file gia' presente in Media Library (es. caricato con `POST /media`); `image: null` rimuove l'immagine
- `image` puo' essere una mappa lingua WPML, ad esempio `{ "en": "https://cdn.example.com/en/shirt.jpg", "it": "https://cdn.example.com/it/shirt.jpg" }`; ogni variazione tradotta riceve la propria immagine
- `acf_fields` opzionale, oggetto associativo `field_name => value` per campi ACF associati al post type `product_variation`; le chiavi devono essere nomi tecnici ACF o field key ACF esistenti sul field group della variazione (campi non esistenti ritornano `400 invalid_param`)
- se un valore dentro `acf_fields` appartiene a un campo ACF di tipo `image` o `file` ed e' un URL valido, il file viene importato o riusato dalla Media Library e nel campo viene salvato l'`attachment_id`; per svuotare un campo `image` o `file` passare `null` o stringa vuota
- ogni valore in `acf_fields` accetta una mappa lingua WPML; ogni variazione tradotta riceve il valore della propria lingua
- se il field group ACF della variazione definisce un campo `local_key`, viene popolato automaticamente con il `local_key` della variazione
- se il parent variable product ha download nativi WooCommerce, la variazione li eredita automaticamente cosi' risultano visibili nella UI WooCommerce
- dopo il salvataggio della variazione l'endpoint sincronizza il parent variable product e pulisce i transient WooCommerce del parent
- l'endpoint non crea ne' configura automaticamente gli attributi del parent variable product
- con WPML attivo, se `name`, `description`, `short_description`, `long_description`, `image`, `attributes` o `acf_fields` contengono mappe lingua, l'endpoint aggiorna la variazione del parent risolto e crea o aggiorna le variazioni nelle lingue del payload che hanno gia' una traduzione del parent; le lingue senza parent tradotto vengono ignorate finche' il parent non esiste
- la response contiene l'ID della variazione nella lingua del parent risolto da `parent_id` o `parent`; le altre variazioni tradotte vengono create o aggiornate nello stesso batch

Response `200`:

```json
[701]
```

Errori principali:

- `400 invalid_param` se il body non e' un array JSON valido, se manca `local_key`, se manca il parent, se il parent non e' `variable`, se `attributes` o `acf_fields` non sono oggetti validi, se un attributo non e' configurato come variation attribute sul parent o se `acf_fields` contiene campi ACF non esistenti per la variazione
- `404 not_found` se il parent, la variazione o un termine attributo non esiste
- `409 duplicate_local_key`
- `409 parent_mismatch`
- `500 woocommerce_required` se WooCommerce non e' attivo
- `500 wpml_required` se il payload contiene valori multilingua ma WPML non e' installato o attivo
- `500 request_failed` se WooCommerce o WordPress non riescono a salvare la variazione

### DELETE `/woocommerce/variant-products`

Elimina variazioni WooCommerce per `local_key`. Se piu' variazioni condividono lo stesso `local_key` (una per lingua del gruppo del parent), vengono eliminate **tutte**.

Body:

```json
[2001]
```

Query opzionale:

```text
?ignore=1
```

Response `200`:

```json
null
```

## Media

### GET `/media`

Restituisce la lista degli attachment media (Media Library), con filtri opzionali. Utile per individuare gli `attachment_id` da passare poi a `DELETE /media`, e per ritrovare per `token` i file gia' presenti in libreria senza ricaricarli.

**Paginazione:** si', tramite `per_page`/`page` e header `X-WP-Total`/`X-WP-TotalPages` (dettagli sotto).

Query opzionale:

```text
?post_id=321
?mime_type=image/jpeg
?token=aaa111bbb222.1920x1920-contain.jpg
?token=aaa111bbb222.1920x1920-contain.jpg,ccc333ddd444.600x600-contain.webp
?source_url=https://storage.onpage.it/aaa111bbb222.1920x1920-contain.jpg/foto.jpg
?page=1
?per_page=100
```

Note:

- `post_id` filtra per post genitore (`post_parent`).
- `mime_type` filtra per MIME type esatto dell'attachment.
- `token` filtra per segmento di storage On Page® (`_onpage_file_token`, vedi `POST /media`) e accetta **piu' token separati da virgola**: e' il modo per riadottare in blocco i file gia' presenti in libreria con una sola richiesta invece di una per file. I token vuoti vengono ignorati; se non ne resta nessuno la richiesta e' `400 invalid_param`.
- `source_url` filtra per URL di origine esatto (`_onpage_source_url`), valore singolo.
- la lista resta paginata anche con `token`: chiedendo piu' di `per_page` token (max `100`) i risultati arrivano su piu' pagine. Ogni riga riporta il proprio `token`, quindi l'associazione token → attachment si fa dalla response.
- `token` e `source_url` si combinano con gli altri filtri (`post_id`, `mime_type`) in AND.
- `per_page` ha massimo `100` e default `100`.
- `page` ha default `1`.
- Ordinamento per data di creazione decrescente.

Response `200`:

```json
[
  {
    "attachment_id": 501,
    "filename": "image-1.jpg",
    "title": "image-1",
    "url": "https://example.com/wp-content/uploads/2026/04/image-1.jpg",
    "mime_type": "image/jpeg",
    "post_id": 321,
    "hash": "1d8b874f1a5f4a9f9b8c3e4f6a7b2c5d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b",
    "token": "aaa111bbb222.1920x1920-contain.jpg",
    "source_url": "https://storage.onpage.it/aaa111bbb222.1920x1920-contain.jpg/image-1.jpg"
  },
  {
    "attachment_id": 502,
    "filename": "brochure.pdf",
    "title": "brochure",
    "url": "https://example.com/wp-content/uploads/2026/04/brochure.pdf",
    "mime_type": "application/pdf",
    "post_id": 0,
    "hash": null,
    "token": null,
    "source_url": null
  }
]
```

Note sulla response:

- `hash` e' il checksum SHA-256 del contenuto del file fisico, calcolato **al volo ad ogni richiesta** (`hash_file('sha256', ...)` su `get_attached_file()`) e **non persistito** in `post_meta`. E' `null` se il file fisico non e' presente/leggibile sul filesystem.
- `token` e' il segmento di storage On Page® indicizzato sull'attachment, `null` per i media caricati per altre vie (a mano in Media Library, o importati prima che il segmento venisse indicizzato — vedi `POST /migration`).
- `source_url` e' l'URL remoto da cui il media e' stato importato, `null` per i file caricati direttamente.

Header di paginazione:

- `X-WP-Total`: numero totale di attachment che soddisfano i filtri (`post_id`/`mime_type`), indipendentemente dalla pagina.
- `X-WP-TotalPages`: numero totale di pagine, calcolato come `ceil(X-WP-Total / per_page)`.
- Il client sa gia' dalla risposta corrente se e' l'ultima pagina (`page >= X-WP-TotalPages`): non serve chiamare una pagina in piu' per scoprirlo tramite un array vuoto.

Esempio di scorrimento (250 attachment, `per_page=100` → 3 pagine):

```text
GET /media?page=1&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continua)
GET /media?page=2&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (continua)
GET /media?page=3&per_page=100  →  X-WP-Total: 250, X-WP-TotalPages: 3   (page == X-WP-TotalPages → stop, nessuna richiesta a page=4)
```

### POST `/media`

Carica uno o piu' file tramite `multipart/form-data` usando il flusso media nativo di WordPress.

Content-Type:

```http
multipart/form-data
```

Campi supportati:

- uno o piu' campi file, ad esempio `file`, `files[]` o altri nomi compatibili con PHP `$_FILES`
- `post_id` opzionale, per associare gli attachment a un post esistente
- `attachment_id` opzionale, per sostituire il contenuto di un attachment esistente quando stai caricando un solo file
- `attachment_ids` opzionale, array allineato ai file caricati per sostituire uno o piu' attachment esistenti in richieste multi-file
- `token` opzionale, il segmento di storage On Page® (`<token>[.<formato>]`, es. `aaa111bbb222.1920x1920-contain.jpg`) da cui proviene il file, quando stai caricando un solo file
- `tokens` opzionale, array allineato ai file caricati con lo stesso significato in richieste multi-file (anche come stringa JSON); le posizioni vuote (`null` o stringa vuota) valgono "nessun token per quel file"

Comportamento:

- il controller accetta sia file singoli sia array di file nello stesso campo
- i file vengono normalizzati in una lista piatta e processati in ordine
- ogni file viene salvato con `wp_handle_upload()`
- se non viene passato un attachment esistente, il controller crea un nuovo attachment con `wp_insert_attachment()`
- se viene passato `attachment_id` o un valore in `attachment_ids`, il controller mantiene lo stesso attachment WordPress e sostituisce solo il file fisico e i metadata
- il `token` viene salvato sull'attachment nel meta `_onpage_file_token`, esattamente come e' stato inviato: identifica il **contenuto** del file, non la sua posizione, quindi lo stesso file richiesto in formati diversi (`.1920x1920-contain.jpg`, `.600x600-contain.webp`) resta su attachment distinti
- l'endpoint e' **idempotente sul token**: se arriva un `token` gia' presente su un attachment della libreria (e il suo file fisico esiste ancora), i byte caricati vengono scartati e viene restituito quell'attachment con `action: "linked"`, senza creare nulla; con `post_id` valorizzato l'attachment esistente viene comunque riagganciato a quel post
- un `attachment_id`/`attachment_ids` esplicito ha la precedenza: la sostituzione avviene comunque, e il `token` eventualmente presente viene riscritto sull'attachment sostituito. Una sostituzione **senza** `token` rimuove il token registrato, perche' descriveva i byte appena sovrascritti
- vengono generati i metadata con `wp_generate_attachment_metadata()`
- quando sostituisce un attachment esistente, il vecchio file e le sue size generate vengono rimossi
- in caso di errore su un file, la richiesta si interrompe immediatamente e ritorna `WP_Error`

Esempio upload multiplo:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer <token>" \
  -F "files[]=@/path/image-1.jpg" \
  -F "files[]=@/path/image-2.png" \
  -F "post_id=321"
```

Esempio upload con campo singolo:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/brochure.pdf"
```

Esempio replace di un attachment esistente:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/image-new.jpg" \
  -F "attachment_id=501"
```

Esempio upload con il token di storage On Page®:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/image-1.jpg" \
  -F "token=aaa111bbb222.1920x1920-contain.jpg"
```

Esempio upload multiplo con token allineati ai file:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer <token>" \
  -F "files[]=@/path/image-1.jpg" \
  -F "files[]=@/path/image-2.png" \
  -F "tokens[]=aaa111bbb222.1920x1920-contain.jpg" \
  -F "tokens[]=ccc333ddd444.1920x1920-contain.png"
```

Esempio misto create + replace nello stesso upload multiplo:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media" \
  -H "Authorization: Bearer <token>" \
  -F "files[]=@/path/image-1.jpg" \
  -F "files[]=@/path/image-2.png" \
  -F "attachment_ids[]=501" \
  -F "attachment_ids[]="
```

Response `200`:

```json
[
  {
    "field": "files",
    "key": "0",
    "action": "created",
    "attachment_id": 501,
    "filename": "image-1.jpg",
    "title": "image-1",
    "url": "https://example.com/wp-content/uploads/2026/04/image-1.jpg",
    "mime_type": "image/jpeg",
    "post_id": 321
  },
  {
    "field": "files",
    "key": "1",
    "action": "replaced",
    "attachment_id": 502,
    "filename": "image-2.png",
    "title": "image-2",
    "url": "https://example.com/wp-content/uploads/2026/04/image-2.png",
    "mime_type": "image/png",
    "post_id": 321
  }
]
```

Note sulla response:

- `field` indica il nome del campo file ricevuto nel form
- `key` rappresenta la posizione originale del file nel payload; per `files[]` tipicamente e' `"0"`, `"1"`, ecc.
- `action` vale `created` per nuovi attachment, `replaced` quando viene aggiornato un attachment esistente e `linked` quando il `token` inviato era gia' in libreria e l'attachment e' stato riusato senza caricare niente
- `token` e' presente solo se il file e' stato inviato con un token, e riporta il valore indicizzato sull'attachment
- `post_id` vale `0` se il file non e' stato associato a un post

Errori principali:

- `400 invalid_param` se non ci sono file nel request o se `post_id` non e' valido
- `400 invalid_param` se `attachment_id` e `attachment_ids` vengono usati insieme o con formato non valido
- `400 invalid_param` se `token` e `tokens` vengono usati insieme, se `token` viene usato con piu' di un file, o se un token non e' una stringa non vuota
- `400 upload_failed` se PHP segnala un errore di upload sul file
- `404 not_found` se `post_id` e' valorizzato ma il post non esiste
- `404 not_found` se un `attachment_id` referenziato non esiste
- `500 upload_failed` se WordPress rifiuta il salvataggio del file
- `500 request_failed` se non viene creato o aggiornato correttamente l'attachment WordPress

### POST `/media/link`

Importa uno o piu' file remoti da URL (oppure collega attachment gia' presenti in Media Library tramite `attachment_id`), li salva/verifica nella Media Library, li collega a un post esistente tramite `post_parent` e aggiorna il campo ACF il cui nome coincide con la chiave dentro `files`.

Content-Type:

```http
application/json
```

Body:

```json
{
  "post_id": 321,
  "files": {
    "image": "https://cdn.example.com/image-1.jpg",
    "image_2": "https://cdn.example.com/image-2.jpg"
  }
}
```

Comportamento:

- `post_id` e' obbligatorio e deve riferirsi a un post esistente
- `files` e' obbligatorio e deve essere un oggetto JSON non vuoto
- ogni chiave di `files` rappresenta il nome del campo ACF da aggiornare sul post
- ogni valore di `files` deve essere un URL valido e raggiungibile da WordPress, oppure l'`attachment_id` (intero JSON) di un file gia' presente in Media Library (es. caricato con `POST /media`)
- ogni file remoto viene scaricato e importato nella Media Library tramite il flusso di sideload WordPress; un `attachment_id` viene invece verificato e ricollegato al `post_id` richiesto, senza download
- se lo stesso URL e' gia' stato importato in precedenza dal plugin, viene riusato lo stesso attachment e ricollegato al `post_id` richiesto
- dopo l'import, il riuso o il collegamento diretto, il controller salva l'`attachment_id` risultante nel campo ACF corrispondente
- per gli URL, il controller salva l'URL sorgente nel meta `_onpage_source_url`
- se l'URL e' un URL di storage On Page® (`https://storage.onpage.it/<token>[.<formato>]/<nome>` o `https://app.onpage.it/api/storage/<token>[.<formato>]/<nome>`), il controller ne estrae il segmento e lo salva anche nel meta `_onpage_file_token`, lo stesso indice usato da `POST /media`. Il riuso cerca **prima** per segmento e poi per URL esatto: un file rinominato su On Page® cambia URL ma non segmento, quindi non viene riscaricato ne' duplicato. Gli URL di altra provenienza non producono nessun segmento e continuano a essere riusati per URL esatto
- la response restituisce un elemento per ogni voce di `files`, incluso il nome del campo originario; l'elemento risolto da `attachment_id` non ha `source_url` in response

Esempio:

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/media/link" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "post_id": 321,
    "files": {
      "image": "https://cdn.example.com/image-1.jpg",
      "image_2": "https://cdn.example.com/image-2.jpg"
    }
  }'
```

Response `200`:

```json
[
  {
    "field": "image",
    "action": "created",
    "attachment_id": 601,
    "filename": "image-1.jpg",
    "title": "image-1",
    "url": "https://example.com/wp-content/uploads/2026/04/image-1.jpg",
    "mime_type": "image/jpeg",
    "post_id": 321,
    "source_url": "https://cdn.example.com/image-1.jpg"
  },
  {
    "field": "image_2",
    "action": "linked",
    "attachment_id": 455,
    "filename": "image-2.jpg",
    "title": "image-2",
    "url": "https://example.com/wp-content/uploads/2026/03/image-2.jpg",
    "mime_type": "image/jpeg",
    "post_id": 321,
    "source_url": "https://cdn.example.com/image-2.jpg"
  }
]
```

Errori principali:

- `400 invalid_param` se il body non e' un oggetto JSON valido
- `400 invalid_param` se `post_id` non e' un intero positivo
- `400 invalid_param` se `files` non e' un oggetto non vuoto
- `400 invalid_param` se uno dei valori in `files` non e' un `attachment_id` esistente o un URL valido
- `404 not_found` se `post_id` non esiste
- `500 request_failed` se il download o l'import del file remoto falliscono

### DELETE `/media`

Elimina uno o piu' attachment media per ID numerico.

Body:

```json
[501, 502, 503]
```

Query opzionale:

```text
?ignore=1
```

Comportamento:

- il body deve essere un array JSON non vuoto
- ogni elemento deve essere un ID numerico positivo
- vengono eliminati solo post di tipo `attachment`
- l'eliminazione usa `wp_delete_attachment($id, true)`, quindi e' forzata e rimuove anche il file fisico e tutti i metadata associati, indice On Page® compreso (`_onpage_file_token` e `_onpage_source_url`): dopo la cancellazione quel media non e' piu' riadottabile per token
- in caso di errore su un elemento, la richiesta si interrompe immediatamente
- con `?ignore=1`, gli attachment non trovati vengono saltati senza errore; per quegli ID viene comunque rimosso l'eventuale indice On Page® rimasto orfano (attachment cancellato fuori da WordPress)

Esempio:

```bash
curl -X DELETE \
  "https://example.com/wp-json/onpage/v1/media?ignore=1" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '[501, 502, 503]'
```

Response `200`:

```json
null
```

Errori principali:

- `400 invalid_param` se il body non e' un array JSON valido o contiene ID non validi
- `404 not_found` se un attachment non esiste e `ignore` non e' presente
- `500 delete_failed` se WordPress non riesce a cancellare un attachment

## Taxonomies

### GET `/taxonomies`

Restituisce tutte le tassonomie ACF.

**Paginazione:** no — la risposta contiene sempre **tutte** le tassonomie in un'unica chiamata, nessun `per_page`/`page`.

Response `200`:

```json
[
  {
    "id": 67,
    "key": "brand",
    "singular_label": {
      "it": "Brand",
      "en": "Brand"
    },
    "plural_label": {
      "it": "Brands",
      "en": "Brands"
    },
    "description": "Product brand",
    "hierarchical": false
  }
]
```

Note:

- `singular_label` e `plural_label` possono essere stringhe semplici oppure mappe lingua => label, in base ai dati statici salvati dal plugin

### POST `/taxonomies`

Crea o aggiorna una o piu' tassonomie ACF (upsert per chiave `key`).

Payload esempio:

```json
[
  {
    "key": "brand",
    "singular_label": {
      "it": "Brand",
      "en": "Brand"
    },
    "plural_label": {
      "it": "Brands",
      "en": "Brands"
    },
    "description": "Product brand",
    "hierarchical": false
  }
]
```

Campi importanti:

- `key` richiesto
- `singular_label` richiesto
- `plural_label` richiesto

Note:

- **Upsert idempotente**: se esiste gia' una tassonomia con la stessa `key` viene aggiornata in-place (stesso ID ACF), altrimenti viene creata. Re-inviare lo stesso payload converge sullo stesso record senza errori di duplicato.
- La `key` e' l'identificatore stabile della tassonomia (usata anche come slug); non esiste un `local_key` separato.
- Le label possono essere stringhe semplici o mappe per lingua.
- Se le label sono mappe per lingua ma WPML non e' installato o attivo, ritorna `500 wpml_required`.
- Se le label sono multilingua, il service salva una mappa custom in option (`onpage_taxonomy_label_translations`) e prova anche a registrarle in ACFML.
- La tassonomia viene marcata come traducibile in WPML, se disponibile.
- Alla fine viene eseguito `flush_rewrite_rules()`.

Response `200`:

```json
[67]
```

Errori principali:

- `500 acf_error`
- `500 wpml_required` se il payload contiene valori multilingua ma WPML non e' installato o attivo

### DELETE `/taxonomies`

Elimina tassonomie per ID ACF oppure per slug.

Body:

```json
[67, "brand"]
```

Query opzionale:

```text
?ignore=1
```

Comportamento:

- Prima elimina tutti i termini della tassonomia.
- Poi elimina la tassonomia ACF.
- Elimina i field group ACF con location `taxonomy == <slug>`.
- Rimuove le label tradotte salvate.
- Rimuove la tassonomia dall'elenco WPML delle tassonomie traducibili.
- Esegue `flush_rewrite_rules()`.

## Terms

Tutte le operazioni sui termini avvengono su `/terms`: lettura/ricerca (`GET`), creazione/aggiornamento (`POST`) ed eliminazione (`DELETE`). La tassonomia si passa come `?taxonomy=` (query, opzionale in `GET`/`DELETE`) o come campo `taxonomy` nel body (richiesto in `POST`).

```text
/terms    (GET: list/search, POST: create/update, DELETE)
```

`taxonomy` (query o body) identifica la tassonomia e accetta sia lo **slug** (es. `product_cat`, `brand`, `pa_color`) sia l'**ID ACF numerico**. Lo slug ha la precedenza ed e' consigliato (stabile tra ambienti e valido anche per tassonomie non-ACF); l'ID numerico resta supportato per retrocompatibilita'. Esempio: `/terms?taxonomy=product_cat`.

### GET `/terms`

Elenca o cerca i termini. La tassonomia si passa nella query string (`?taxonomy=`), come `GET /posts?type=`.

**Paginazione:** no — la risposta contiene sempre **tutti** i termini che soddisfano i filtri in un'unica chiamata, nessun `per_page`/`page`.

> **Nota:** sostituisce il precedente `GET /taxonomies/{id}/terms` (rimosso). La tassonomia, prima nel path `{id}`, va ora passata come query `?taxonomy=`.

Query opzionale:

```text
?taxonomy=brand
?taxonomy=product_cat&name=Chairs
?taxonomy=brand&name[it]=Acme&name[en]=Acme
?name=Acme
?name[it]=Rosso&name[en]=Red
?taxonomy=product_cat&parent_id=575
?taxonomy=product_cat&parent_lk=6000
```

- `taxonomy` (**opzionale**) identifica la tassonomia e accetta sia lo **slug** (es. `product_cat`, `brand`, `pa_color`) sia l'**ID ACF numerico**, con le stesse regole del path `{id}` degli altri endpoint annidati. Se passata ma non risolta ritorna `404 not_found`. Se **omessa**, il listing e la ricerca per `name` spaziano su **tutte le tassonomie** (la tassonomia di ciascun termine è risolta dal termine stesso).
- `name` esegue una ricerca per **nome esatto** del termine e accetta due forme:
  - **stringa** (`?name=Chairs`): ricerca nella lingua corrente della richiesta;
  - **mappa lingua** (`?name[it]=Sedie&name[en]=Chairs`): ricerca ogni nome nel contesto della rispettiva lingua WPML e ne restituisce l'**unione**.
- `parent_id` (WP term id) e `parent_lk` (local_key del parent) filtrano restituendo i **figli diretti** del parent. `parent_lk` viene espanso a **tutte le traduzioni WPML** del parent (i figli vengono cercati sotto ognuna) e **richiede `taxonomy`** (per risolvere il local_key in quella tassonomia; `400` se assente); `parent_id` funziona anche senza `taxonomy`. I due parametri sono **mutuamente esclusivi** (`400` se passati insieme); un `parent_lk` che non risolve nessun termine restituisce lista vuota. Hanno precedenza inferiore a `name`.
- Senza `name` né `parent_*` restituisce l'elenco completo dei termini (della tassonomia indicata, o di tutte le tassonomie se `taxonomy` è omessa).
- La risposta è **sempre una lista** con **un oggetto per ogni gruppo di traduzione distinto** (rappresentante in lingua di default quando disponibile), con la mappa `translations`: nomi che sono traduzioni dello stesso termine → un solo oggetto; nomi di gruppi diversi → più oggetti. Stesso comportamento di `GET /woocommerce/products?name=`.

> **Nota:** senza `taxonomy` e senza `name` l'endpoint restituisce **tutti** i termini di tutte le tassonomie; su installazioni grandi conviene sempre passare `taxonomy` e/o `name`.

Response `200`:

```json
[
  {
    "id": 10,
    "name": "Acme",
    "slug": "acme",
    "local_key": 4001,
    "description": "",
    "parent": 0,
    "count": 4,
    "taxonomy": "brand",
    "translations": {
      "it": 10,
      "en": 11
    },
    "acf_fields": {
      "logo": 123
    }
  }
]
```

- `translations` mappa ogni codice lingua all'ID del termine in quella lingua (gruppo di traduzione WPML). Senza WPML attivo la mappa e' vuota. E' presente in tutte le response dei termini (singolo, lista e ricerca).

Errori:

- `404 not_found` se `taxonomy` è passata ma non viene risolta

### POST `/terms`

Crea o aggiorna termini in batch. Ogni elemento del payload indica la propria `taxonomy` (al posto del path `{id}`), quindi una stessa richiesta può scrivere termini di tassonomie diverse.

> **Nota:** sostituisce il precedente `POST /taxonomies/{id}/terms` (rimosso). La tassonomia, prima nel path `{id}`, va ora passata come campo `taxonomy` in ogni elemento del body.

Payload semplice:

```json
[
  {
    "taxonomy": "brand",
    "name": "Acme",
    "slug": "acme",
    "description": "Main brand",
    "local_key": 4001,
    "parent": 0,
    "acf_fields": {
      "headline": "Official reseller",
      "logo": "https://example.com/uploads/acme-logo.png"
    }
  }
]
```

Campi:

- `taxonomy` **richiesto** per ogni elemento; accetta lo **slug** (es. `product_cat`, `brand`, `pa_color`) oppure l'**ID ACF numerico** della tassonomia, con le stesse regole del path `{id}` degli altri endpoint. Manca → `400 invalid_param`; non risolta → `404 not_found`
- `id` opzionale, se presente tenta update del termine
- `name` richiesto
- `slug` opzionale
- `description` opzionale
- `local_key` opzionale, usato come identificatore esterno stabile
- `parent` opzionale, default `0`
- `acf_fields` opzionale, oggetto associativo `field_name => value`

Comportamento `acf_fields`:

- i campi testuali o scalari vengono salvati direttamente sul termine
- se un campo ACF di tipo `image` riceve un URL valido, il file viene importato o riusato dalla Media Library e viene salvato l'`attachment_id`
- gli SVG remoti vengono accettati solo dopo validazione/sanificazione e importati come `image/svg+xml`
- se un campo ACF di tipo `image` riceve `null` o stringa vuota, il campo viene svuotato

Risoluzione del termine da aggiornare:

- priorita' a `id` se presente (deve esistere, altrimenti `404 not_found`)
- altrimenti prova a trovare un termine con lo stesso `local_key`

Comportamento `local_key`:

- viene salvato come term meta `onpage_local_key`
- viene scritto su **tutto il gruppo di traduzione WPML** del termine risolto: aggiornando un termine (anche solo per `id`) il `local_key` viene applicato anche alle traduzioni non presenti nel payload
- se esiste un campo ACF del Term chiamato `local_key`, viene valorizzato automaticamente
- se `local_key` esiste gia' su un altro gruppo traduzioni della stessa tassonomia, ritorna `409 duplicate_local_key`

#### Supporto multilingua con WPML

I campi `name`, `slug`, `description` e i valori dentro `acf_fields` possono essere inviati come mappa per lingua:

```json
[
  {
    "taxonomy": "brand",
    "local_key": 4001,
    "name": {
      "it": "Acme Italia",
      "en": "Acme"
    },
    "slug": {
      "it": "acme-italia",
      "en": "acme"
    },
    "description": {
      "it": "Descrizione italiana",
      "en": "English description"
    },
    "acf_fields": {
      "headline": {
        "it": "Titolo IT",
        "en": "EN title"
      },
      "logo": {
        "it": "https://example.com/uploads/logo-it.png",
        "en": "https://example.com/uploads/logo-en.png"
      }
    }
  }
]
```

Se `name` e' multilingua ma `slug` e' una stringa singola, lo slug viene applicato solo al termine base; per le traduzioni senza `slug` esplicito WordPress genera uno slug dalla traduzione del nome. Se invece il nome della traduzione coincide con quello della lingua base — perche' `name` e' una stringa condivisa oppure perche' la mappa lingua ripete lo stesso valore (es. `{"it":"Legno","en":"Legno"}`) — l'endpoint genera uno slug tecnico distinto per lingua (`<slug-base>-<lingua>`), necessario perche' WordPress rifiuta termini omonimi con lo stesso genitore. Per controllare gli slug tradotti, inviare `slug` come mappa lingua-valore.

Regole:

- Se sono presenti valori multilingua e WPML non e' installato o attivo, ritorna `500 wpml_required`.
- La lingua base usata per creare il termine e' la default WPML, oppure la prima lingua che nel payload ha effettivamente un nome valorizzato.
- **Valori `null` per lingua**: dentro una mappa `name` (o `slug`/`description`) una lingua puo' valere `null` per indicare "traduzione assente" (es. `{"it": "Sigillante Ibrido", "en": null, "es": null}`). Le lingue a `null` vengono ignorate: non producono traduzioni e non contano come nome. Il termine base viene creato nella prima lingua che ha un nome valorizzato — anche quando la lingua default WPML e' proprio una di quelle a `null`. La richiesta fallisce con `400 invalid_param` (`Name is required`) **solo** se nessuna lingua della mappa ha un nome non vuoto.
- **Lingue non attive in WPML**: se il payload include codici lingua non configurati in WPML (es. il sito ha solo `it` attivo ma arriva `{"it": "...", "en": null, "es": null}`), la mappa non viene riconosciuta come multilingua; il plugin usa comunque il nome della lingua attiva presente (qui `it`) per creare il termine e ignora i codici non attivi. Serve almeno un nome valorizzato per una lingua, altrimenti `400 invalid_param`.
- Le traduzioni vengono create o aggiornate nello stesso gruppo WPML del termine base.
- `local_key` viene propagato anche ai termini tradotti, sia come term meta sia come campo ACF se presente.

Response `200`:

```json
[10, 11]
```

Errori principali:

- `400 invalid_param`
- `404 not_found`
- `409 duplicate_local_key`
- `500 request_failed`
- `500 acf_error`
- `500 wpml_required` se il payload contiene valori multilingua ma WPML non e' installato o attivo
- `500 wpml_error`

### DELETE `/terms`

Elimina termini per ID. Il body è una lista di ID termine.

> **Nota:** sostituisce il precedente `DELETE /taxonomies/{id}/terms` (rimosso). La tassonomia non è più nel path.

Body:

```json
[10, 11]
```

Query opzionale:

```text
?taxonomy=brand
```

- `taxonomy` (**opzionale**) scopa la cancellazione a una tassonomia (slug o ID ACF numerico); se non risolta ritorna `404 not_found`. Se **omessa**, la tassonomia di ciascun termine viene risolta dal termine stesso, quindi una stessa richiesta può eliminare termini di tassonomie diverse.

Response `200`:

```json
null
```

Errori:

- `404 not_found` se `taxonomy` è passata ma non viene risolta, o se un termine non esiste
- `500 delete_failed` se WordPress non riesce a eliminare il termine

## Manutenzione

### DELETE `/indexes`

Rimuove **tutte** le associazioni `local_key` di On Page® (gli "indici") da post e termini. Da chiamare quando il sistema sorgente **rigenera le proprie local_key**: azzerando le chiavi nella destinazione, l'import successivo può riassegnarle da zero senza creare doppioni.

Non accetta payload.

```bash
curl -X DELETE https://<host>/wp-json/onpage/v1/indexes \
  -H "Authorization: Bearer <token>"
```

Response `200`:

```json
{ "posts_removed": 1240, "terms_removed": 312 }
```

Cancella la meta canonica `onpage_local_key` e le copie legacy `local_key` / `_local_key`, sia da `wp_postmeta` sia da `wp_termmeta`. È idempotente.

Note operative importanti:

- **Termini**: dopo l'azzeramento i termini diventano "non posseduti" e il re-import li **ri-adotta** per identità strutturale (nome/slug + parent), riscrivendo la nuova `local_key`, senza creare doppioni.
- **Ordine top-down**: il re-import deve processare **i parent prima dei figli**; subito dopo l'azzeramento il `parent` di una categoria viene risolto per `local_key` e, se il parent non è ancora stato re-importato, ritorna `404 not_found`.
- **Doppioni preesistenti**: termini duplicati creati da import falliti restano (orfani, senza chiave); per un'adozione deterministica conviene inviare lo `slug` reale nel payload o ripulire i doppioni vuoti.
- **Prodotti**: i prodotti **non** hanno adozione strutturale. Dopo l'azzeramento un prodotto con titolo già esistente ma senza `local_key` viene rifiutato con `409 duplicate_title`: vanno **re-importati** (per `id` esistente o ricreando il legame). Tieni conto di questo prima di azzerare anche i `postmeta`.

Errori:

- `500 delete_failed` se la cancellazione delle meta fallisce.

### POST `/migration`

Esegue le migrazioni dati del plugin: adeguamenti una tantum ai dati gia' presenti sul sito, resi necessari da un cambio di formato interno. Va chiamato **una volta per sito** dopo un aggiornamento del plugin; e' idempotente, quindi rieseguirlo non fa danni ed e' un no-op se non c'e' niente da migrare.

Non accetta payload.

```bash
curl -X POST https://<host>/wp-json/onpage/v1/migration \
  -H "Authorization: Bearer <token>"
```

Cosa fa, in ordine:

1. **Rinomina della `local_key`** (`local_key` → `onpage_local_key`). La chiave On Page® dei post era scritta implicitamente da un campo ACF sotto `local_key`; ora la meta canonica e' `onpage_local_key`. La migrazione rinomina le righe in `wp_postmeta`, elimina la meta di riferimento ACF orfana `_local_key` e le copie legacy in `wp_termmeta` (i termini salvavano gia' la chiave canonica, quelle erano duplicati).
2. **Backfill del segmento di storage On Page®** (`_onpage_file_token`). Gli attachment importati per URL prima che il segmento venisse indicizzato hanno solo `_onpage_source_url`: senza segmento nessuno puo' riconoscerli quando lo stesso file arriva su `POST /media`, e vengono duplicati. Il segmento viene estratto dall'URL di origine e scritto sull'attachment. Gli attachment che ce l'hanno gia' e gli URL che non sono di storage On Page® vengono saltati.

Response `200`:

```json
{
  "renamed": 1240,
  "acf_reference_removed": 1240,
  "term_legacy_removed": 312,
  "items": [
    { "post_id": 501, "local_key": "12" },
    { "post_id": 502, "local_key": "SKU-7781" }
  ],
  "media_tokens": {
    "scanned": 3480,
    "written": 3452
  }
}
```

Note sulla response:

- `renamed` e' il numero di righe `wp_postmeta` rinominate; `acf_reference_removed` e `term_legacy_removed` il numero di righe legacy eliminate rispettivamente da `wp_postmeta` e `wp_termmeta`.
- `items` elenca i post interessati dalla rinomina con la chiave trovata, per verifica. E' catturato **prima** della rinomina e su un sito grande puo' essere lungo. La `local_key` qui e' il valore grezzo della meta, quindi sempre una stringa (a differenza degli altri endpoint, che restituiscono un intero quando la chiave e' intero-canonica).
- `media_tokens.scanned` e' il numero di attachment esaminati (quelli con `_onpage_source_url` e senza segmento), `media_tokens.written` quelli a cui e' stato scritto il segmento: la differenza sono i media importati da URL non On Page®, che non hanno un segmento e restano deduplicati per URL esatto.
- Su una seconda esecuzione tutti i contatori sono `0`.

Errori:

- `500 migration_failed` se una delle letture o scritture sui metadati fallisce. La migrazione non e' transazionale: i passi gia' completati restano applicati e la chiamata puo' essere ripetuta.

## Esempi cURL

### Lista tassonomie

```bash
curl -X GET \
  "https://example.com/wp-json/onpage/v1/taxonomies" \
  -H "Authorization: Bearer <token>"
```

### Upsert post type

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/post-types" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '[
    {
      "post_type": "product",
      "singular_label": "Product",
      "plural_label": "Products",
      "rewrite_slug": "catalogo/prodotti"
    }
  ]'
```

### Upsert termini multilingua

```bash
curl -X POST \
  "https://example.com/wp-json/onpage/v1/terms" \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '[
    {
      "taxonomy": "brand",
      "local_key": 4001,
      "name": {
        "it": "Acme Italia",
        "en": "Acme"
      },
      "acf_fields": {
        "headline": {
          "it": "Titolo IT",
          "en": "EN title"
        }
      }
    }
  ]'
```

## Note implementative

- Le API si appoggiano a funzioni ACF plugin come `acf_update_field_group`, `acf_update_post_type` e `acf_update_taxonomy`.
- Molti endpoint assumono che ACF sia presente e inizializzato correttamente.
- La semantica dei payload e' guidata dal codice attuale dei controller, non da uno schema OpenAPI formale.
- Gli update sono parziali solo in alcuni casi: ad esempio nei post vengono aggiornati solo i campi passati, ma le tassonomie inviate vengono riassegnate completamente.
