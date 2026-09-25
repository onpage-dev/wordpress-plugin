# Architettura del plugin On Page®

Questo documento riassume l'**architettura software** scelta per il plugin e **motiva** le decisioni
principali. Non è una guida d'uso (per quello vedi [USER.md](wp-content/plugins/wordpress-plugin/docs/USER.md) e
[API.md](wp-content/plugins/wordpress-plugin/docs/API.md)) né un'analisi di performance (vedi
[Tech.md](wp-content/plugins/wordpress-plugin/docs/Tech.md)): descrive **come è organizzato il codice e perché**.

---

## 1. Contesto e obiettivo

Il plugin è il lato WordPress di una sincronizzazione **On Page® → WordPress**: riceve dati strutturati
dal servizio *Exporter* di On Page® e li materializza come contenuti WordPress (post/CPT, tassonomie,
termini, field group ACF) e come entità WooCommerce (prodotti, varianti, categorie, tag, brand,
attributi globali e loro termini), con supporto opzionale al multilingua **WPML**.

Il chiamante è una macchina, non un umano: il plugin è quindi un **backend di integrazione**, non una
UI. Ne discende il primo principio di design: esporre un **contratto REST idempotente** che possa
essere rieseguito senza duplicare dati, guidato da un identificativo esterno (`local_key`).

---

## 2. Forze e vincoli che hanno guidato le scelte

L'architettura è la risposta a un insieme di vincoli non negoziabili:

- **Runtime WordPress/PHP condiviso.** Il codice gira dentro il ciclo di vita di WordPress, senza
  processo dedicato, senza framework, senza build step. Niente container DI, niente ORM.
- **Nessuna dipendenza esterna.** Non c'è `composer.json`: le classi vengono incluse manualmente
  in [onpage.php](wp-content/plugins/wordpress-plugin/onpage.php). Ridurre la superficie di
  dipendenze evita conflitti di versione con altri plugin nello stesso runtime.
- **Tre integrazioni "opache" e disallineate tra loro.** ACF, WooCommerce e WPML hanno modelli dati
  diversi (postmeta vs CRUD object vs `icl_translations`) e vanno orchestrati, non semplicemente
  chiamati. Gran parte della complessità del plugin vive qui.
- **WPML opzionale.** Lo stesso codice deve funzionare con e senza WPML: il multilingua è una
  dimensione trasversale, non una modalità separata.
- **Import massivi.** Le scritture sono batch da centinaia/migliaia di elementi: il contratto e la
  gestione degli errori devono reggere lo stato parziale.

---

## 3. Vista d'insieme: architettura a livelli

Il plugin adotta una classica **stratificazione a responsabilità crescenti**, con il flusso di una
richiesta che attraversa i livelli dall'alto verso il basso:

```
HTTP (WordPress REST API)
  │
  ▼
Router            ── src/Router.php        registra le rotte, fa da adapter verso register_rest_route
  │
  ▼
Middleware        ── src/Middlewares/      permission_callback (Auth Bearer token)
  │
  ▼
Controller        ── src/Controllers/      sottile: parse del batch, loop, mapping HTTP
  │
  ▼
Service           ── src/Services/         logica di business (upsert, WPML, ACF, WooCommerce)
  │
  ▼
Repository / WP   ── src/Services/*Repository.php + API WordPress/WooCommerce/ACF/WPML
```

La regola di dipendenza è unidirezionale: i Controller conoscono i Service, i Service conoscono i
Repository e le API di piattaforma; **mai il contrario**. Questo tiene i Controller banali e
testabili a occhio, e concentra la complessità in un solo strato (Service).

### 3.1 Perché questa stratificazione

- **Separazione HTTP / dominio.** Il Controller parla HTTP (status code, forma del body, `WP_Error`);
  il Service parla dominio (prodotti, termini, lingue). Cambiare il contratto REST non tocca la
  logica di upsert, e viceversa.
- **Uniformità.** Ogni endpoint di scrittura ha la stessa forma (vedi §6): riduce il carico
  cognitivo e rende prevedibile dove mettere una modifica.

---

## 4. Le scelte architetturali chiave (con giustificazione)

### 4.1 Router custom sopra la REST API di WordPress

Invece di sparpagliare decine di `register_rest_route()` nel codice, tutte le rotte sono dichiarate
in un unico file, [routes.php](wp-content/plugins/wordpress-plugin/routes.php), tramite un piccolo
[Router](wp-content/plugins/wordpress-plugin/src/Router.php) fluente:

```php
$router->bind('POST', '/woocommerce/products', [ProductController::class, 'save'], AuthMiddleware::class);
```

**Perché:**
- **Una sola tabella di routing** leggibile a colpo d'occhio come indice dell'intera API — è la
  documentazione vivente del contratto.
- **Middleware come parametro esplicito.** Il router mappa il middleware sul `permission_callback`
  di WordPress ([Router.php:108](wp-content/plugins/wordpress-plugin/src/Router.php#L108)): l'auth è
  dichiarata alla rotta, impossibile dimenticarla.
- **Dispatch centralizzato e gestione errori unica.** `Router::dispatch()`
  ([Router.php:41-72](wp-content/plugins/wordpress-plugin/src/Router.php#L41-L72)) è l'**unico** punto
  che cattura le `HttpException` e le converte in `WP_Error`. I Service lanciano eccezioni di dominio
  senza sapere nulla di HTTP; la traduzione in risposta avviene in un solo posto.
- **Placeholder `{param}`** convertiti in named capture group
  ([Router.php:30-33](wp-content/plugins/wordpress-plugin/src/Router.php#L30-L33)): sintassi di
  routing familiare senza dipendere dalla verbosità nativa di WordPress.

È volutamente minimale: nessuna feature non usata (gruppi di rotte, middleware multipli, ecc.). Il
costo di questa astrazione è ~100 righe, ripagate dalla leggibilità di `routes.php`.

### 4.2 Controller sottili, Service ricchi

I Controller fanno **solo tre cose**: caricare la ACF field-type map una volta per richiesta,
iterare il batch JSON, delegare al Service e impacchettare la risposta. La
[Category](wp-content/plugins/wordpress-plugin/src/Controllers/WooCommerce/Category.php) è
emblematica: ~60 righe, nessuna logica di dominio.

**Perché:** la logica difficile (risoluzione WPML, upsert idempotente, sideload media, ACF) è
condivisa tra molti endpoint. Concentrarla nei Service permette il **riuso**: ad esempio
`WooCommerce\Term::save` serve categorie, tag, brand e attribute-terms; `Acf::updateFieldValue`
serve post, prodotti, varianti e termini. Se la logica vivesse nei Controller andrebbe duplicata 4-6
volte.

### 4.3 Errori come eccezioni di dominio + `WP_Error` al bordo

Il modello di errore è a **due stadi**:

1. Nel dominio si lancia `httpException($msg, $status, $code)`
   ([helpers.php:168](wp-content/plugins/wordpress-plugin/src/helpers.php#L168)) — una
   [HttpException](wp-content/plugins/wordpress-plugin/src/Exceptions/HttpException.php) che porta con
   sé status HTTP e codice errore.
2. Al bordo, `Router::dispatch` la trasforma nel `WP_Error` che WordPress serializza nella risposta.

**Perché:** i Service non devono propagare valori d'errore lungo tutta la call chain (che sarebbe
rumoroso e facile da dimenticare); lanciano e basta. Il messaggio uniforme
`Service :: Element {i} :: ...` rende ogni errore di batch immediatamente localizzabile all'elemento
che l'ha causato. Le eccezioni non-`HttpException` vengono rilanciate
([Router.php:67-69](wp-content/plugins/wordpress-plugin/src/Router.php#L67-L69)): i bug veri
emergono come 500 con stack trace, non vengono mascherati.

### 4.4 Autenticazione: Bearer token + UI admin, nessun endpoint sul token

L'auth è un [middleware](wp-content/plugins/wordpress-plugin/src/Middlewares/Auth.php) che delega al
[servizio Auth](wp-content/plugins/wordpress-plugin/src/Services/Auth.php): confronto in tempo
costante (`hash_equals`) tra il Bearer token della richiesta e quello salvato in
`wp_options` (`onpage_auth_token`). Il token si genera **solo** dalla pagina admin
([UI.php](wp-content/plugins/wordpress-plugin/src/Views/UI.php)), protetta da capability
`manage_options` e nonce CSRF.

**Perché:**
- **Semplicità operativa.** Un client machine-to-machine non fa OAuth handshake; un Bearer statico è
  il minimo sufficiente, dietro HTTPS.
- **Nessuna superficie d'attacco REST sul segreto.** Non esiste endpoint per leggere/ruotare il
  token: la gestione vive solo nella UI admin, riducendo il rischio.
- **Fail-safe esplicito.** Token non configurato → `500` (non un silenzioso pass-through); token
  mancante → `401`; token errato → `403`. Stati distinti e diagnosticabili.

### 4.5 Il batch come contratto e l'idempotenza via `local_key`

Ogni endpoint di scrittura accetta un **array JSON** e processa un elemento alla volta. La chiave
dell'intero design è che le scritture sono **upsert idempotenti** guidati da `local_key`,
l'identificativo esterno On Page® (intero positivo **o stringa non vuota**; interi e stringhe numeriche
sono equivalenti perché i meta WordPress sono comunque stringhe).

**Perché l'idempotenza:** la sync deve poter essere **rieseguita** (retry, re-import parziale, ripresa
dopo errore) senza creare duplicati. `local_key` disaccoppia l'identità On Page® dall'ID WordPress
(che il chiamante non conosce e che non è stabile tra ambienti diversi, es. dev/staging/produzione).

**Convenzione di storage** — motivata dal fatto che WordPress non ha un posto unico per i metadati:

| Tipo di entità | Storage `local_key` | Meta key |
|---|---|---|
| Post / CPT | `wp_postmeta` | `onpage_local_key` |
| Prodotti e varianti WooCommerce | `wp_postmeta` | `onpage_local_key` |
| Termini, categorie, tag, brand, attribute-terms | `wp_termmeta` | `onpage_local_key` |
| Attributi globali WooCommerce | `wp_options` | `onpage_wc_attribute_local_key_{id}` |

Il `local_key` è memorizzato come **meta tecnica**, non come campo ACF: la persistenza deve
funzionare anche quando il field group non definisce alcun campo dedicato. Le prime versioni
scrivevano la chiave dei post tramite un campo ACF implicito (meta `local_key` / `_local_key`):
quel campo è stato rimosso e `POST /migration` allinea gli installati esistenti (vedi
[DEV.md](wp-content/plugins/wordpress-plugin/docs/DEV.md) per il dettaglio della mappa).

**Trade-off accettato — stato parziale.** In caso di errore su un elemento, la `HttpException`
interrompe il batch e gli elementi già scritti **restano**. È una scelta consapevole: senza
transazioni SQL cross-API (WooCommerce/ACF/WPML scrivono su tabelle diverse con i loro hook), un
rollback vero non è realistico. L'idempotenza è ciò che rende accettabile lo stato parziale: basta
rilanciare il batch.

### 4.6 Modello `shared` / `translated` per WPML

WPML è trattato come una **dimensione trasversale**, non come un percorso di codice separato. Ogni
valore traducibile (`name`, `title`, `content`, `slug`, valori ACF, …) può arrivare come scalare
(condiviso) o come mappa `{ "<lang>": <value> }`. I Service **splittano** il payload in due bucket —
`shared` (valido per tutte le lingue) e `translated` (override per lingua) — e poi risolvono il
valore finale per ciascuna lingua con una catena di fallback.

**Perché:**
- **Un solo modello mentale con o senza WPML.** Senza WPML la lista lingue è vuota e il ramo
  "translated" semplicemente non si attiva: nessun `if (wpml) { ... } else { ... }` duplicato.
- **Fallire esplicitamente.** Se il payload è multilingua ma WPML non è attivo → `500 wpml_required`.
  Il plugin non tenta un degrado silenzioso che produrrebbe dati ambigui.
- **Regole di dominio isolate.** Vincoli come "lo SKU WooCommerce è globalmente univoco → si applica
  solo alla lingua sorgente, non alle traduzioni" (vedi
  [DEV.md](wp-content/plugins/wordpress-plugin/docs/DEV.md)) vivono nei Service, dietro il modello
  shared/translated.

Le helper WPML ([helpers.php:75-155](wp-content/plugins/wordpress-plugin/src/helpers.php#L75-L155))
sono **memoizzate per richiesta**: `isWpmlActive`, `getWpmlDefaultLanguage`, `getWpmlLanguages`
vengono interrogate decine di volte per elemento e il set di lingue è invariante durante un import.
È la memoizzazione più economica e ad alto impatto (vedi
[Tech.md](wp-content/plugins/wordpress-plugin/docs/Tech.md) §4.5).

### 4.7 WooCommerce come specializzazione dei primitivi WordPress

I Service WooCommerce non reimplementano da zero: **categorie, tag, brand e attribute-terms sono
termini** e riusano `WooCommerce\Term::save` (a sua volta sopra il `TermService` generico); i
**prodotti sono post** con in più i CRUD object di WooCommerce (`WC_Product`, `WC_Product_Variation`)
per prezzi, SKU, attributi, downloads.

**Perché:** massimizza il riuso della logica di upsert/WPML/ACF già scritta per i termini e i post
generici, e mantiene coerente la semantica di `local_key` tra mondo "core" e mondo "commerce". Le
specificità WooCommerce (sync varianti, lookup table, sideload downloads) sono aggiunte *sopra*, non
sostituzioni.

### 4.8 ACF centralizzato: un solo punto di scrittura dei campi

Tutta la scrittura dei campi ACF passa da `Acf::updateFieldValue`, condiviso da post, prodotti,
varianti e termini. Gestisce in un punto solo: skip dei `tab`, conversione URL → `attachment_id` per
`image`/`file`, normalizzazione ricorsiva di `repeater`/`group`, e la mappa lingua per campo.

**Perché:** la logica ACF è sottile ma piena di casi particolari; averla in un unico posto garantisce
che tutti gli endpoint si comportino **identicamente** su repeater, immagini e mappe lingua, e che una
correzione valga ovunque. La field-type map è caricata **una volta per richiesta** dal Controller
(`Acf::loadFieldTypeMap`), non per campo.

### 4.9 Media remoti isolati in `RemoteMedia`

L'import di media (immagini prodotto/variante, `image`/`file` ACF, download, thumbnail di
categoria/brand) è concentrato in `RemoteMedia`, che scarica, deduplica per URL sorgente
(`findAttachmentBySourceUrl`) e crea l'attachment.

**Perché:** l'I/O di rete è la parte più fragile e costosa dell'import. Isolarlo dietro un solo
servizio permette di deduplicare i download e lascia aperta la porta a un'evoluzione verso l'import
**asincrono** (Action Scheduler) senza toccare i Service di dominio — oggi il download è sincrono ed è
il principale collo di bottiglia noto (vedi [Tech.md](wp-content/plugins/wordpress-plugin/docs/Tech.md) §4.4).

### 4.10 Repository per i lookup critici

I lookup per `local_key` sui termini usano query `$wpdb` dirette
(`TermRepository`/`PostRepository`) invece di `get_terms(meta_query)` / `get_posts(meta_query)`.

**Perché:** durante un import ogni scrittura invalida le cache di `WP_Query`, e i filtri WPML su
`WP_Query` sono costosi quando ripetuti migliaia di volte. Una query preparata diretta salta quel
percorso ed è invariante per lingua (il `local_key` è lo stesso in tutte le lingue). È un'ottimizzazione
mirata dove il profiling ha indicato il costo, non un bypass generalizzato di WordPress.

### 4.11 Bootstrap con include manuali + `Env` leggero

[onpage.php](wp-content/plugins/wordpress-plugin/onpage.php) include i file in **ordine di dipendenza
esplicito**, senza autoloader. [Env](wp-content/plugins/wordpress-plugin/src/Env.php) legge un `.env`
opzionale (singleton) per la configurazione locale.

**Perché:** senza Composer non c'è autoload PSR-4; l'ordine di include manuale è verboso ma azzera la
dipendenza da tooling di build e rende il grafo delle dipendenze **leggibile in un file**. Coerente
con il vincolo "zero dipendenze esterne" del §2.

---

## 5. Ciclo di vita di una richiesta (esempio: `POST /woocommerce/products`)

1. WordPress invoca la rotta registrata da `Router::resolve()` su `rest_api_init`.
2. `permission_callback` → `AuthMiddleware::handle` → `Auth::check` valida il Bearer token.
3. `callback` → `Router::dispatch` istanzia il `ProductController` e chiama `save`.
4. Il Controller carica la ACF field-type map una volta, poi itera l'array JSON.
5. Per ogni elemento chiama `Product::save`, che:
   - normalizza il payload, splitta shared/translated, risolve le lingue WPML;
   - risolve l'esistente via `local_key` → decide insert o update (upsert);
   - persiste il `WC_Product`, i campi ACF, i termini (categorie/tag/brand), i media remoti;
   - propaga alle traduzioni WPML applicando le regole di dominio (es. SKU solo sulla sorgente).
6. Un errore su un elemento → `HttpException` → `Router::dispatch` la converte in `WP_Error`; il batch
   si ferma, gli elementi precedenti restano scritti.
7. Successo → array di ID → `WP_REST_Response` 200.

---

## 6. Invarianti e convenzioni trasversali

Sono le regole che rendono il sistema prevedibile; violarle è quasi sempre un bug:

- **Direzione delle dipendenze:** Controller → Service → Repository/piattaforma. Mai risalire.
- **Un solo punto per ogni cross-cutting concern:** auth nel middleware, errori nel dispatch, scrittura
  ACF in `Acf::updateFieldValue`, media in `RemoteMedia`, lingue nelle helper WPML memoizzate.
- **Forma uniforme degli endpoint di scrittura:** body = array, upsert per `local_key`, errore con
  prefisso `Service :: Element {i}`, risposta = array di ID.
- **`local_key` come chiave di riconciliazione**, salvato come meta tecnica; l'ID WordPress non è mai
  richiesto al chiamante come identità primaria.
- **WPML trasparente:** stesso codice con/senza WPML; payload multilingua senza WPML → errore, mai
  degrado silenzioso.

---

## 7. Trade-off accettati e non-goal

Scelte deliberate, con il loro razionale:

- **Nessuna transazione / stato parziale su errore.** Non fattibile in modo affidabile across
  WooCommerce/ACF/WPML; mitigato dall'idempotenza (§4.5). *Non-goal:* atomicità del batch.
- **I/O media sincrono.** Semplice e con contratto chiaro (la risposta contiene già gli
  `attachment_id`), ma è il collo di bottiglia dominante negli import ricchi. L'isolamento in
  `RemoteMedia` (§4.9) tiene aperta l'evoluzione asincrona. *Non-goal, per ora:* import in background.
- **Router minimale.** Nessuna feature di routing avanzata: si aggiunge solo ciò che serve.
- **Nessun ORM / nessuna astrazione DB generica.** Si usano le API di WordPress; le query dirette
  compaiono solo nei Repository dei lookup critici (§4.10).
- **Validazione imperativa, non schema dichiarativo.** La validazione è sparsa nei Service come catene
  di controlli; è ripetitiva e ricammina il payload più volte (vedi
  [Tech.md](wp-content/plugins/wordpress-plugin/docs/Tech.md) §6). Un DTO normalizzato single-pass è il
  candidato refactor più naturale se il costo di validazione diventasse dominante.

---

## 8. Riepilogo

L'architettura è una **stratificazione a responsabilità crescenti** (Router → Middleware → Controller →
Service → Repository), progettata attorno a un unico obiettivo: offrire un **contratto REST batch,
idempotente e multilingua** verso una piattaforma — WordPress + ACF + WooCommerce + WPML — che di suo
non è pensata per sincronizzazioni machine-to-machine.

Le decisioni ricorrenti seguono tutte lo stesso principio: **concentrare ogni preoccupazione in un
punto solo** (auth, errori, ACF, media, lingue) e **disaccoppiare l'identità esterna (`local_key`)
dall'identità WordPress**, così che la sync sia ripetibile e la complessità delle integrazioni resti
confinata nello strato Service. I trade-off aperti (stato parziale, I/O sincrono) sono scelte
consapevoli, isolate dietro confini che ne permettono l'evoluzione senza riscrivere il dominio.
