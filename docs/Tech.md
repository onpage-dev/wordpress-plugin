# Analisi tecnica degli endpoint POST WooCommerce

Studio della logica di validazione e scrittura degli endpoint `POST /woocommerce/*`, con
mappatura del flusso, dei costi per elemento e dei punti di accumulo di overhead.

Riferimenti principali:

- Routing: [routes.php](wp-content/plugins/wordpress-plugin/routes.php)
- Router: [Router.php](wp-content/plugins/wordpress-plugin/src/Router.php)
- Controllers: [src/Controllers/WooCommerce/](wp-content/plugins/wordpress-plugin/src/Controllers/WooCommerce/)
- Services: [src/Services/WooCommerce/](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/) +
  [src/Services/](wp-content/plugins/wordpress-plugin/src/Services/) (Acf, MultiLang, Wpml, RemoteMedia, Term, TermRepository, Input)

---

## 1. Pipeline comune a tutti i POST

```
HTTP POST
  └─ WordPress rest_api_init
       └─ Router::dispatch
            └─ Middleware Auth (Bearer token)
                 └─ Controller::save (per endpoint)
                      └─ foreach ($request->get_json_params() as $i => $params)
                           └─ Service::save($params, $i)   ←  vero hot loop
```

Punti rilevanti:

- Il dispatch è registrato in [routes.php:48-95](wp-content/plugins/wordpress-plugin/routes.php#L48-L95).
  Tutte le rotte POST WooCommerce passano per `AuthMiddleware`, che invoca
  `AuthService::check()` una sola volta a richiesta.
- Il corpo è SEMPRE un array JSON. I controller iterano l'array e processano un
  elemento alla volta — non c'è batching reale a livello di persistenza
  (nessuna transazione, nessuna preallocazione, nessun bulk insert).
- In caso di errore su un elemento la `HttpException` viene catturata in
  [Router.php:61](wp-content/plugins/wordpress-plugin/src/Router.php#L61) e
  restituita come `WP_Error` — gli elementi precedenti restano scritti. Stato
  parziale per design.

Tabella controller → service:

| Endpoint | Controller | Service entrypoint |
|---|---|---|
| `POST /woocommerce/products` | [Product.php:25-39](wp-content/plugins/wordpress-plugin/src/Controllers/WooCommerce/Product.php#L25-L39) | [Services/WooCommerce/Product::save](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L709) |
| `POST /woocommerce/categories` | [Category.php:31-45](wp-content/plugins/wordpress-plugin/src/Controllers/WooCommerce/Category.php#L31-L45) | [Services/WooCommerce/Term::save](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Term.php#L231) |
| `POST /woocommerce/tags` | [Tag.php:31-45](wp-content/plugins/wordpress-plugin/src/Controllers/WooCommerce/Tag.php#L31-L45) | stesso `Services/WooCommerce/Term::save` |
| `POST /woocommerce/brands` | [Brand.php:23-37](wp-content/plugins/wordpress-plugin/src/Controllers/WooCommerce/Brand.php#L23-L37) | [Services/WooCommerce/Brand::save](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Brand.php#L242) (→ `Term::upsertFromParams`) |
| `POST /woocommerce/attributes` | [Attribute.php:22-30](wp-content/plugins/wordpress-plugin/src/Controllers/WooCommerce/Attribute.php#L22-L30) | [Services/WooCommerce/Attribute::save](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Attribute.php#L445) |
| `POST /woocommerce/attributes/{attribute}/terms` | [AttributeTerm.php:37-52](wp-content/plugins/wordpress-plugin/src/Controllers/WooCommerce/AttributeTerm.php#L37-L52) | stesso `Services/WooCommerce/Term::save` |
| `POST /woocommerce/variant-products` | [VariantProduct.php:22-34](wp-content/plugins/wordpress-plugin/src/Controllers/WooCommerce/VariantProduct.php#L22-L34) | [Services/WooCommerce/VariantProduct::save](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/VariantProduct.php#L520) |

Caratteristiche trasversali:

- Prima del loop il controller chiama `Acf::loadFieldTypeMap(['post'])` o
  `['term']` ([Acf.php:148](wp-content/plugins/wordpress-plugin/src/Services/Acf.php#L148)).
  Costo amortizzato (una volta per request).
- Tutti i servizi sollevano `HttpException` via `httpException()`
  ([helpers.php:137](wp-content/plugins/wordpress-plugin/src/helpers.php#L137)) — niente codici di
  errore aggregati, errore fatale al primo problema dell'elemento.

---

## 2. Validazione: schema comune e costo

Lo "schema" non è dichiarativo, è implementato come catena di `is_scalar`/`is_array`/`array_is_list`
sparsa nei service. Pattern ricorrenti:

### 2.1 Helpers cardine

- `Input::positiveInt`, `Input::stringOrNull`, `Input::requireStringParam`,
  `Input::localKey` / `Input::requireLocalKeyParam` / `Input::localKeyOut` (normalizzazione del
  `local_key` intero-o-stringa in input e rendering in output)
  ([Input.php](wp-content/plugins/wordpress-plugin/src/Services/Input.php)) — economici.
- `MultiLang::getLanguages`, `isLanguageMapShape`,
  `requireWpmlForLanguageMap`, `requireWpmlForFieldMap`,
  `requireWpmlForNestedLanguageMaps`
  ([MultiLang.php](wp-content/plugins/wordpress-plugin/src/Services/MultiLang.php)). Tutti
  ricamminano il payload e ognuno chiama `getWpmlLanguages()` ([helpers.php:104](wp-content/plugins/wordpress-plugin/src/helpers.php#L104)) che
  esegue `apply_filters('wpml_active_languages', ...)`. **Non c'è caching
  request-scoped** del risultato — viene rieseguito centinaia di volte in un batch.
- `httpException()` produce errori uniformi `Service :: Element {i} :: ...`.

### 2.2 Validazione prodotti

`Product::normalizeProductPayload`
([Product.php:339-408](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L339-L408))
fa, in ordine, per ogni elemento:

1. `local_key` obbligatorio.
2. `name` obbligatorio (scalar o mappa WPML; valida ogni lingua).
3. `status` opzionale.
4. `acf_fields`: oggetto + `requireWpmlForFieldMap` (ricorsivo).
5. `long_description`/`content`, `short_description`/`description`: WPML check.
6. `props`: oggetto + `requireWpmlForProductPropLanguageMaps`
   ([Product.php:144-153](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L144-L153))
   che itera tutti i 22 campi WC noti.
7. `image`: WPML check.
8. `attributes`: per ogni attributo `validateAttributeValue` ricorsiva
   ([Product.php:156-194](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L156-L194)).
9. `downloads`: lista; per ogni download valida `file`/`url`, `name`, `id` —
   ognuno con check WPML.
10. `id`: opzionale, intero positivo.
11. `Taxonomy::buildFromParams` ([Taxonomy.php:30-50](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Taxonomy.php#L30-L50))
    per `categories`, `tags`, `brand`. Ognuno richiama nuovamente le funzioni
    WPML per riconoscere mappe per lingua.

Complessità di validazione per un prodotto medio (≈10 props, ≈8 attributi, ≈4
download, 3 lingue, 20 campi ACF):

- ≈ 60–80 invocazioni di `getWpmlLanguages()` → altrettanti `apply_filters` →
  filtri WPML registrati internamente fanno query SQL su `icl_languages`.
- Ricamminazione completa del payload almeno 3 volte (validazione, calcolo
  `getLanguageContext`, applicazione).

### 2.3 Validazione term (categories/tags/attribute-terms/brands)

`Term::upsertFromParams` ([Term.php:1076-1088](wp-content/plugins/wordpress-plugin/src/Services/Term.php#L1076-L1088)):

1. `requireWpmlForPayloadLanguageMaps` su `name`, `slug`, `description`,
   `acf_fields`.
2. `parseTermPayload` ([Term.php:461-501](wp-content/plugins/wordpress-plugin/src/Services/Term.php#L461-L501)) — splitta
   ogni valore in `shared`/`translated` con `MultiLang::splitValueByLanguage`.
3. `validateParsedPayload` ([Term.php:516-546](wp-content/plugins/wordpress-plugin/src/Services/Term.php#L516-L546)) —
   risolve subito `findByLocalKey` (DB) e `findByLocalKeyForLanguage` per `parent_local_key`,
   quindi parte della validazione è già fetch dal DB.

Per category-specifico, `WooCommerceTerm::prepareParentPayload`
([Services/WooCommerce/Term.php:172-198](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Term.php#L172-L198))
risolve `parent` come `local_key` con un ulteriore lookup
`TermService::findIdByLocalKey`.

### 2.4 Validazione variation

`VariantProduct::save` ([VariantProduct.php:520-543](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/VariantProduct.php#L520-L543))
fa pochissima validazione strutturale (oggetto, `local_key` richiesto) ma forza:

- `requireParentProduct` ([VariantProduct.php:241-269](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/VariantProduct.php#L241-L269)):
  sempre 1 `get_posts` (per id o per local_key); se sono dati ENTRAMBI
  `parent_id` e `parent`, fa 2 lookup distinti per verificare coerenza.
- Attribute resolution in `applyAttributes`
  ([VariantProduct.php:856-904](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/VariantProduct.php#L856-L904))
  fa, per ciascun attributo della variation:
  - `get_term` (per id), poi `get_term_by('slug')`, poi `get_term_by('name')`
    in cascata ([VariantProduct.php:953-963](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/VariantProduct.php#L953-L963)).

### 2.5 Validazione attributi globali

`Attribute::save` ([Services/WooCommerce/Attribute.php:445-501](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Attribute.php#L445-L501)) costruisce
gli args con `buildAttributeArgs` ed esegue `wc_update_attribute` /
`wc_create_attribute` di WooCommerce. La validazione è leggera; il vero costo è
nelle scansioni di `wp_options` (vedi §3).

---

## 3. Catalogazione delle query e degli I/O per elemento

Tabella riassuntiva — costo "per elemento" del batch (best case, senza WPML attivo).
"H" = chiamata HTTP esterna, "Q" = query DB esplicita, "S" = `$product->save()`.

### 3.1 `POST /woocommerce/products` — caso INSERT (no WPML)

| Fase | Sorgente | Q | H | S | Note |
|---|---|---|---|---|---|
| Lookup esistente (no id, con local_key) | `findCanonicalProductIdByLocalKey` → `findProductIdsByLocalKey` ([Product.php:514-532](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L514-L532)) | 1 |  |  | `get_posts` con `meta_query` su `onpage_local_key` |
| Pre-check unicità local_key | `findProductIdByLocalKey` ([Product.php:758](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L758)) | 1 |  |  | RIDONDANTE: il lookup sopra l'ha già fatto |
| Pre-check unicità title | `findDuplicateProductIdByTitle` ([Product.php:762](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L762)) | 1 |  |  | `get_posts` con `title` |
| Save iniziale | `persistProductFromParams` → `$product->save()` ([Product.php:956](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L956)) | molte |  | 1 | `wp_insert_post` + meta_input + lookup_table sync + hooks WC |
| `update_post_meta(onpage_local_key)` ([Product.php:965](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L965)) | 1 |  |  |  |
| `linkAttachmentsToProduct` ([ProductDownloads.php:89-110](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/ProductDownloads.php#L89-L110)) | per download |  |  | `attachment_url_to_postid` + `wp_update_post` |
| `syncPublicIdsMeta` ([ProductDownloads.php:113-140](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/ProductDownloads.php#L113-L140)) | 1 |  |  | `update_post_meta` |
| `applyImage` (se `image` presente) | `RemoteMedia::urlToPost` ([RemoteMedia.php:394-400](wp-content/plugins/wordpress-plugin/src/Services/RemoteMedia.php#L394-L400)) | ≥3 | 1 |  | `findAttachmentBySourceUrl` (`get_posts`), `download_url` (HTTP), `wp_update_post`. Poi `$product->save()` di nuovo ([Product.php:1337](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L1337)) | + 1 |
| `applyImage` save aggiuntivo | | | | 1 | save #2 del prodotto |
| `syncVariableProductToVariations` ([ProductDownloads.php:143-166](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/ProductDownloads.php#L143-L166)) | N children |  |  | N | una `save()` per ogni variazione figlia |
| `saveAcfFields` ([Product.php](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php)) | per campo |  | per immagine | | Itera i campi e li passa a `Acf::updateFieldValue` (centralizzato): skip `tab`, `image`/`file` URL → `attachment_id` via `RemoteMedia::urlToPost`/`urlToMediaLibrary`, repeater normalizzati da `Acf::resolveRepeaterValue` (ricorsivo, valida shape, risolve sub-field image/file). Stessa logica condivisa da `Post::savePostAssociations` e `Term::updateAcfFields` |
| `Taxonomy::apply` ([Taxonomy.php:53-104](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Taxonomy.php#L53-L104)) | per termine ≥1 |  |  | | `get_term_by` per slug, eventuale `wpml_object_id`, infine `wp_set_object_terms` |

Per ogni download il sideload può scatenare anche
`normalizeDownloadFileUrl` ([ProductDownloads.php:444-485](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/ProductDownloads.php#L444-L485))
che fa HTTP download (`RemoteMedia::urlToMediaLibrary` con timeout 12s), poi
`get_attached_file`, `wp_get_attachment_url`.

**Conteggio realistico per 1 prodotto "ricco"** (1 immagine, 4 download
remoti, 8 attributi, 6 categorie/tag, 15 campi ACF di cui 2 immagini, no
varianti, no WPML): ~12-25 query SQL, 7 chiamate HTTP esterne, **3 save
diversi dello stesso `WC_Product`** (init, post-image, eventuale variabile-sync).

### 3.2 `POST /woocommerce/products` — moltiplicatori WPML

Con WPML attivo + 3 lingue:

- `getLanguageContext` ([Product.php:426-468](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L426-L468)) cammina
  tutti i campi traducibili per estrarre la lista lingue.
- Pre-check titolo: 1 query in più per OGNI lingua tradotta
  ([Product.php:766-778](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L766-L778)).
- `insertTranslatedProducts` ([Product.php:981-1048](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L981-L1048))
  esegue `persistProductFromParams` UNA VOLTA PER LINGUA → moltiplicatore
  ×N_lingue dei save, dei `applyProductFields`, `applyProductAttributes`,
  `ProductDownloads::apply` (RIIMPORTANDO i file remoti — viene riutilizzato l'attachment
  via `findAttachmentBySourceUrl`, ma il check è una `get_posts`/lingua),
  `applyImage`, `saveAcfFields`, `Taxonomy::apply`. Lo switch lingua è
  `Wpml::runWithLanguage` ([Wpml.php:12-36](wp-content/plugins/wordpress-plugin/src/Services/Wpml.php#L12-L36)).
- L'UPDATE path
  ([Product.php:833-909](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L833-L909))
  rifa lo stesso lavoro: `findDuplicateProductIdByTitle` esegue
  un `get_posts` per ogni lingua presente nelle traduzioni.
- Risoluzione gruppo traduzioni: `getTranslationProductIds`
  ([Product.php:1429-1494](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L1429-L1494))
  chiama 3–4 filtri WPML differenti, ciascuno con query interna.

Risultato realistico con 3 lingue: ~3x query + ~3x save + ~3x scrittura ACF.

### 3.3 `POST /woocommerce/categories` (e tags / attribute-terms)

Per ogni elemento `Term::upsertFromParams`:

1. `requireWpmlForPayloadLanguageMaps` — solo validazione.
2. `validateParsedPayload` → `findByLocalKey` (1 query `wpdb->get_col`
   da [TermRepository.php:38-56](wp-content/plugins/wordpress-plugin/src/Services/TermRepository.php#L38-L56)).
3. `upsertBaseTerm`:
   - `findByLocalKeyForLanguage` (1 query, eventualmente molteplici lookup WPML
     per lingua) ([Term.php:232-259](wp-content/plugins/wordpress-plugin/src/Services/Term.php#L232-L259)).
   - `upsertTermData` ([Term.php:879-923](wp-content/plugins/wordpress-plugin/src/Services/Term.php#L879-L923)):
     - `findTermIdByDataSlug` (almeno 1 query).
     - `wp_update_term` o `wp_insert_term` dentro `Wpml::runWithLanguage`.
     - Recupero da errore `duplicate_term_slug` con `forceUpdateTermWithExistingSlug`
       ([Term.php:759-830](wp-content/plugins/wordpress-plugin/src/Services/Term.php#L759-L830))
       fa direttamente `wpdb->update` su `wp_terms` e `wp_term_taxonomy`.
   - `setTermLocalKey` (`update_term_meta`).
   - `ensureBaseTermLanguage` (WPML action).
   - `updateAcfFields` per ogni campo (può scatenare `RemoteMedia::urlToMediaLibrary`).
4. `syncTranslations` ([Term.php:998-1073](wp-content/plugins/wordpress-plugin/src/Services/Term.php#L998-L1073))
   ripete per ogni lingua: lookup translation, `upsertTermData`,
   `setTermLanguage`, `updateAcfFields`.
5. `WooCommerceTerm::syncThumbnail` ([Services/WooCommerce/Term.php:111-133](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Term.php#L111-L133))
   fa un'altra `findTermIdsByLocalKey` (query) + eventuale `RemoteMedia::urlToMediaLibrary`
   (HTTP) + `update_term_meta` per ogni term ID trovato. Solo per
   `product_cat` e branding.

### 3.4 `POST /woocommerce/attributes`

`Attribute::save` ([Services/WooCommerce/Attribute.php:445-501](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Attribute.php#L445-L501)):

- `findAttributeByLocalKey` → `findExistingAttributeIdsByLocalKey` →
  `findAttributeIdsByLocalKey` ([Services/WooCommerce/Attribute.php:143-172](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Attribute.php#L143-L172))
  esegue **una query con `LIKE %`** su `wp_options` (`option_name LIKE 'onpage_wc_attribute_local_key_%'`).
  Su siti con tante options può diventare costoso.
- `wc_create_attribute`/`wc_update_attribute` internamente chiama
  `flush_rewrite_rules`, invalida transients, ricrea taxonomy.
- `persistLocalKeyForAttribute` ([Services/WooCommerce/Attribute.php:209-213](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Attribute.php#L209-L213))
  rifa `findAttributeIdsByLocalKey` per garantire unicità → un'altra query LIKE.
  ✅ **Ottimizzato (2026-05-27):** `findAttributeIdsByLocalKey` ha una memo
  request-scoped (`self::$localKeyIdsCache`) invalidata su ogni scrittura di
  option local_key (`persistLocalKeyForAttribute`/`deleteLocalKeyForAttribute`),
  così la seconda LIKE per save diventa cache-hit. La singola LIKE resta.

### 3.5 `POST /woocommerce/brands`

Prima del lavoro term-level vero e proprio
([Services/WooCommerce/Brand.php:102-123](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Brand.php#L102-L123)):

- `ensureTaxonomy(true)` può chiamare `Taxonomy::insertFromParams` (crea
  ACF taxonomy) + `register_taxonomy` + **`flush_rewrite_rules()`**. Costoso ma
  pagato solo la prima volta che il brand non esiste.

Successivamente identico a categories. Il `syncThumbnail`
([Services/WooCommerce/Brand.php:182-204](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Brand.php#L182-L204)) ripete il
pattern: query per local_key + HTTP download + meta update.
✅ **Ottimizzato (2026-05-27):** `findTermIdsByLocalKey` non usa più
`get_terms(meta_query)` (che si invalidava a ogni scrittura termine durante
l'import) ma una query `$wpdb` diretta come `WooCommerce/Term` e
`TermRepository`. Effetto collaterale voluto: list/delete-by-local_key dei brand
ora coprono tutte le varianti-lingua (coerente con categories/tags).

### 3.6 `POST /woocommerce/variant-products`

`VariantProduct::saveOneFromParams`
([VariantProduct.php:632-695](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/VariantProduct.php#L632-L695)):

- `findVariationIdByLocalKeyForParent` (1 query) +
  `getAllowedParentIdsForLocalKey` (filtri WPML) +
  `assertLocalKeyAvailableForParentSet` → un'altra `findVariationIdsByLocalKey`
  (1 query). Poi per ogni variation trovata, `wp_get_post_parent_id`.
- Costruisce `\WC_Product_Variation`, applica fields, attributes (con risoluzione
  termine fino a 3 fallback `get_term_by`).
- **`$variation->save()`** (1 save).
- `update_post_meta(onpage_local_key)`.
- `applyImage` con `RemoteMedia::urlToPost` (HTTP) → **`$variation->save()` di nuovo**.
- `Product::syncParentDownloadsToVariation` ([ProductDownloads.php:169-190](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/ProductDownloads.php#L169-L190))
  → `wc_get_product(parent)`, costruisce payload downloads, **`$variation->save()` di nuovo**.
- `syncParentProduct` ([VariantProduct.php:907-920](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/VariantProduct.php#L907-L920))
  → `WC_Product_Variable::sync($parent_id)` che ricalcola prezzi min/max
  iterando TUTTE le variation del parent + `wc_delete_product_transients` +
  `clean_post_cache`. **Costoso: O(num_variazioni) per ogni singola variation salvata.**

In modalità WPML `saveTranslatedFromParams`
([VariantProduct.php:546-629](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/VariantProduct.php#L546-L629))
moltiplica `saveOneFromParams` per ogni traduzione del parent → ognuna richiama
`syncParentProduct` per il parent tradotto.

---

## 4. Bottleneck principali e cause

### 4.1 Save ripetuti dello stesso CRUD object

`$product->save()`/`$variation->save()` viene invocato 2–4 volte per elemento
anche nel caso semplice (init, post-image, syncVariableProductToVariations,
parent sync). Ogni save:

- Esegue `wp_insert_post`/`wp_update_post`.
- Fa partire `save_post`, `transition_post_status`, `clean_post_cache`,
  hooks WooCommerce (`woocommerce_new_product`, `woocommerce_update_product`,
  ricalcolo lookup table `wc_product_meta_lookup`, `wc_delete_product_transients`).
- Riallinea termini, ricontano (deferred ma comunque triggerati).

**Dimezzare i save** dimezza all'incirca il tempo wall-clock di un import "ricco".

### 4.2 Query ripetute con `get_posts` + `meta_query` su `onpage_local_key`/title

Pattern in [Product.php:514-532](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L514-L532),
[Product.php:563-576](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L563-L576),
[VariantProduct.php:179-225](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/VariantProduct.php#L179-L225).
Per ogni elemento del batch:

- 1 lookup per local_key (canonical resolve).
- 1 lookup ripetuto subito dopo per check unicità (RIDONDANTE).
- 1 lookup per title.
- 1 lookup per ogni lingua tradotta.

Tutte queste query passano dal motore generico `WP_Query` con filtri WPML
applicati (cost elevato in WPML siti con `icl_translations` grande).

`TermRepository::findTermIdsByLocalKey`
([TermRepository.php:38-56](wp-content/plugins/wordpress-plugin/src/Services/TermRepository.php#L38-L56))
fa già la versione `wpdb->prepare` diretta, più economica — andrebbe estesa al
contesto post.

### 4.3 `WC_Product_Variable::sync` invocata per ogni variation

`VariantProduct::syncParentProduct` viene chiamata in `saveOneFromParams`
([VariantProduct.php:691](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/VariantProduct.php#L691)).
Se si importano N variation dello stesso parent in un batch, la sync gira N
volte, ognuna O(N) → **O(N²)** sull'insieme di figlie del parent variabile.

### 4.4 Download remoti seriali

`RemoteMedia::downloadRemoteFile` ([RemoteMedia.php:211-223](wp-content/plugins/wordpress-plugin/src/Services/RemoteMedia.php#L211-L223))
è sincrono con timeout 45s (12s per `downloads_*`). Per ogni:

- `image` del prodotto/variation
- `image`/`file` ACF field
- `downloads.*.file` non già locale
- `thumbnail` di category/brand

…fa un HTTP `download_url` + sideload + `wp_generate_attachment_metadata`
(genera thumbnail = risorsa CPU). Tutto bloccante. Un singolo prodotto con
4 immagini + 4 download può consumare > 30s di sola I/O.

`findAttachmentBySourceUrl` ([RemoteMedia.php:37-49](wp-content/plugins/wordpress-plugin/src/Services/RemoteMedia.php#L37-L49))
viene chiamata per ogni URL ma il risultato non è memoizzato a livello di
batch — due prodotti che condividono un'immagine fanno 2 query identiche.

### 4.5 `getWpmlLanguages()` non memoizzato

Chiamato decine di volte per ogni elemento — direttamente o tramite
`MultiLang::getLanguages`. Ogni call esegue
`apply_filters('wpml_active_languages', null, ['skip_missing' => 0])`,
che WPML risolve con una query SQL contro `icl_languages` (a meno della
cache interna WPML che funziona ma costa comunque function-call).

### 4.6 Validazione walking ripetuto del payload

Per ogni prodotto:

- 1° walk: `normalizeProductPayload` (validazione + estrazione).
- 2° walk: `getLanguageContext` ([Product.php:426-468](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L426-L468))
  attraversa di nuovo acf_fields, props, attributes, downloads, name, content, description, image.
- 3° walk: durante l'applicazione per ogni lingua, `resolveFieldsForLanguage`,
  `resolveValue`, ecc. riattraversano i campi.

Lo stesso vale per term: `parseTermPayload` + `validateParsedPayload` + costruzione
`buildTermData` per ogni lingua.

### 4.7 `Taxonomy::apply` con resolution per termine

[Taxonomy.php:53-104](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Taxonomy.php#L53-L104) → per ogni
termine: `findTermForAssignment` → `findTermByLocalKeyForAssignment`
(1 query + filtri WPML) → fallback `findTermBySlugForAssignment`
(`get_term_by` per ciascuna lingua attiva). Su 6 categorie × 3 lingue ⇒
~36 lookup. Nessuna pre-risoluzione batch.

### 4.8 ACF: caching parziale, scrittura sequenziale

`Acf::loadFieldTypeMap` ([Acf.php:148-161](wp-content/plugins/wordpress-plugin/src/Services/Acf.php#L148-L161))
è il punto forte (caricato una volta per request). MA:

- `Acf::getFieldType` ([Acf.php:170-206](wp-content/plugins/wordpress-plugin/src/Services/Acf.php#L170-L206))
  itera "groups" o "group_fields" se non trova nel context scoped — chiamata
  per OGNI campo ACF da scrivere.
- `Acf::updateFieldValue` ([Acf.php:271-301](wp-content/plugins/wordpress-plugin/src/Services/Acf.php#L271-L301))
  chiama `acf_update_value` per campo: scrittura postmeta singola, niente bulk.
  Quando il valore è image/file e arriva URL remoto, scatena `RemoteMedia::urlToPost`
  in linea ([Product.php:1358-1363](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L1358-L1363)) — HTTP sincrono.

### 4.9 `Attribute` LIKE su `wp_options`

`findAttributeIdsByLocalKey` ([Services/WooCommerce/Attribute.php:143-172](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Attribute.php#L143-L172))
fa `option_name LIKE '%onpage_wc_attribute_local_key_%' AND option_value = %s`.
Su sito con migliaia di options, la LIKE non usa l'autoload index. Inoltre la
query girava **2 volte** per ogni save (uniqueness + assert).
✅ **Ottimizzato (2026-05-27):** memo request-scoped → 1 sola LIKE per local_key
per richiesta. La LIKE residua resta non-indicizzata su `option_value`: per
volumi alti di attributi valutare un indice o uno store dedicato (vedi §6).

---

## 5. Stima complessità per batch

Con N elementi nel batch, L lingue attive, D download/immagini per prodotto:

| Endpoint | Query/elemento (no WPML) | Query/elemento (WPML × L) | Save WC per elemento |
|---|---|---|---|
| products INSERT | ~10–15 + ~3·D | ~10–15 + L · (4–6 query) | 2–3 + N_children |
| products UPDATE | ~8–12 | ~8–12 + L · (3 query) | 1–2 + N_children |
| variant-products | ~10 + sync O(N_variations_parent) | ~10 · L_parent · L_variation | 3 + ricreazione |
| categories/tags | ~4–6 | ~4–6 + L · (~3 query) | (term updates) |
| brands (prima volta) | ~6–8 + flush_rewrite | come categories | come categories |
| attributes | ~3 LIKE su options + 1 WC | n/a | n/a |
| attribute-terms | come categories | come categories | n/a |

Per 1000 prodotti, 3 lingue, 4 download ciascuno: vicino a 100k query SQL e
4000–8000 download HTTP sequenziali. È il caso più realistico per un import
massivo On Page® → WooCommerce.

---

## 6. Aree di intervento per velocizzare insert/update

Suddivise per costo/beneficio. **Solo analisi**, nessuna scrittura sul codice.

### 6.1 Quick wins (basso rischio, alto rendimento)

1. **Memoizzare le helper WPML request-scoped**:
   `getWpmlLanguages`, `getWpmlDefaultLanguage`, `getWpmlCurrentLanguage`,
   `isWpmlActive` — chiamate centinaia di volte per batch
   ([helpers.php:72-124](wp-content/plugins/wordpress-plugin/src/helpers.php#L72-L124)).
   Cache statica per request.
2. **Eliminare i lookup ridondanti** subito dopo
   `findCanonicalProductIdByLocalKey`: il check `findProductIdByLocalKey`
   in `insertFromParams` ([Product.php:758](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L758))
   ripete il lavoro appena fatto. Idem per attributi (uniqueness assert dopo
   findExisting).
3. **Cache batch-locale dei `findAttachmentBySourceUrl`** in
   `RemoteMedia::urlToMediaLibrary` ([RemoteMedia.php:340-376](wp-content/plugins/wordpress-plugin/src/Services/RemoteMedia.php#L340-L376)).
   Una map `url → attachment_id` riempita dal primo lookup risparmia tante
   `get_posts` su file condivisi tra prodotti.
4. **Coalescere `WC_Product_Variable::sync`**: invece di chiamarlo dentro
   `saveOneFromParams`, raccogliere i parent_id toccati nel controller e
   chiamarlo UNA volta per parent a fine batch (lo si può fare a livello di
   `VariantProduct::save` Controller con un piccolo helper).
5. **Sospendere `wp_defer_term_counting(true)` + `wp_suspend_cache_invalidation(true)`**
   intorno al loop nei controller. Il ricalcolo viene fatto in coda. Sicuro
   se ricalcolato a fine batch.
6. **Sostituire i `get_posts(meta_query)` su `onpage_local_key`** (postmeta) con
   query `wpdb->prepare` dirette stile `TermRepository::findTermIdsByLocalKey`
   — niente filtri di `WP_Query`/WPML, niente object caching superfluo. Aggiungere
   un indice su `postmeta(meta_key, meta_value)` non aiuta perché WP ce l'ha
   già (`meta_key`), ma la query diretta riduce il path di esecuzione.
7. **Eliminare il secondo `$product->save()` in `applyImage`** ([Product.php:1334-1341](wp-content/plugins/wordpress-plugin/src/Services/WooCommerce/Product.php#L1334-L1341)):
   spostare `set_image_id` prima del primo save in `persistProductFromParams`.
   Stessa cosa per `VariantProduct::applyImage`.

### 6.2 Refactor medio (cambia ordine di operazioni, basso rischio funzionale)

8. **Cache request-scoped del field type map ACF** già esistente — ma
   aggiungere short-circuit per il path globale di `getFieldType` quando
   `context`/`target` sono noti (oggi se non trova nel context scoped fa
   comunque la scansione globale, costoso). Lo si vede al [Acf.php:170-206](wp-content/plugins/wordpress-plugin/src/Services/Acf.php#L170-L206).
9. **Risoluzione termini in batch** per `Taxonomy::apply`: raccogliere prima
   tutti gli slug/local_key richiesti, fare 1 query per taxonomy che ritorna
   tutti i term_id, e poi assegnare. Lo si fa una volta per prodotto se serve.
10. **Riusare la build della validazione + normalizzazione** in unica fase:
    il payload viene attraversato ≥3 volte. Costruire una struttura
    "normalized DTO" e farne il consumer single-pass.
11. **Per le traduzioni** evitare di riapplicare campi `shared` (SKU, prezzi,
    dimensioni, downloads, attributi non localizzati). Oggi
    `persistProductFromParams` applica tutto sempre, anche se il valore non
    è cambiato. Distinguere "shared once" da "per language" già al primo
    save risparmia ×N_lingue scritture di postmeta.
12. **Pre-resolution remote media per batch**: prima del loop, raccogliere
    tutti gli URL univoci nel payload e importarli in un solo passaggio
    (oppure marcarli come "già visti" così il primo elemento li scarica e
    gli altri trovano l'attachment via cache).

### 6.3 Refactor architetturale (richiede testing più ampio)

13. **Sospendere temporaneamente gli hook costosi WooCommerce** (lookup table
    sync, search indexer di HPOS, term recount) dentro un blocco di import e
    rigenerare a fine batch. Esiste API WooCommerce
    (`WC_Background_Process`, `wc_update_product_lookup_tables_column`).
14. **Download asincrono dei media** via Action Scheduler. La risposta REST
    ritorna immediatamente con `attachment_id` pendente, l'import HTTP avviene
    fuori richiesta. Permette di sovrapporre I/O e dare ack al chiamante più
    in fretta. Compatibile con la struttura `RemoteMedia::ACTION_*`.
15. **Sostituire `get_posts` con query `wpdb` mirate** per tutti i lookup
    `local_key` (post type, variations, term). La latenza dei filtri di
    WP_Query è significativa quando si ripetono migliaia di volte.
16. **Introdurre un "session cache" condiviso** tra service (es. `BatchContext`)
    che contiene: lingue WPML, mappa local_key→post_id già risolti, mappa
    URL→attachment_id, mappa term reference→term_id, ACF field type map già
    in `Acf`. Tutti i service lo leggono invece di rifare ogni lookup.

### 6.4 Possibili effetti collaterali da considerare

- Sospendere hook può rompere plugin terzi (SEO, search index, cache CDN).
  Va isolato dietro un flag con default attuale.
- Importare media in background cambia il contratto della risposta REST (oggi
  ritorna `attachment_id` finale).
- Dedup batch dei lookup local_key richiede invalidazione coerente quando un
  elemento del batch CREA un record che un elemento successivo CERCHEREBBE.
- Bypass di `WP_Query` perde i filtri WPML che selezionano la lingua corrente.
  Va valutato per ogni lookup se serve il filtro lingua o no (per local_key
  in genere NO: il local_key è invariante per lingua).

---

## 7. Riepilogo

Il pattern dominante è:

- Validazione lineare ma ripetuta più volte sullo stesso payload.
- Lookup `local_key`/title duplicati per elemento.
- Save ripetuti dello stesso CRUD object dentro la stessa pipeline (init →
  set image → sync).
- Costo amplificato ×N lingue per WPML — anche su campi che NON sono in
  realtà tradotti.
- I/O remoto sincrono come componente dominante del wall-clock per import
  con immagini/download.

Le quick win (6.1) coprono il 70% del guadagno con poco rischio:
memoizzazione WPML, eliminazione di save/lookup ridondanti, deferimento di
`Variable::sync` e term-counting, batch cache per `findAttachmentBySourceUrl`.
I refactor medi (6.2) recuperano l'altro 20%. Le scelte architetturali (6.3)
sono pertinenti solo se il throughput necessario è molto sopra l'attuale.
