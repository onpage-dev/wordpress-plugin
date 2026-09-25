# Guida Sviluppo

Questa guida riassume il ruolo dei controller del plugin e, soprattutto, la logica di business applicata da `Post.php` e `Term.php` per decidere quando creare, aggiornare e collegare contenuti multilingua.

## Obiettivo del plugin

Il plugin espone endpoint REST che permettono di sincronizzare contenuti WordPress, tassonomie e configurazioni ACF da un sistema esterno.

La logica non si limita a fare una chiamata diretta alle API di WordPress:

- normalizza i payload in ingresso
- rileva dati condivisi e dati tradotti per lingua
- applica vincoli di unicita'
- risolve la lingua base e le traduzioni WPML
- propaga ACF e tassonomie
- restituisce errori applicativi coerenti (`WP_Error`)

## Panoramica controller

### `Post.php`

Gestisce i post e custom post type.

Responsabilita' principali:

- leggere un post singolo
- creare o aggiornare post partendo da un payload batch
- gestire contenuti multilingua con WPML
- gestire ACF fields condivisi e tradotti
- assegnare tassonomie ai post, risolvendo i termini tradotti
- cancellare singoli post o tutti i post di un post type

### `Term.php`

Gestisce i termini di una tassonomia.

Responsabilita' principali:

- elencare i termini di una tassonomia
- creare o aggiornare termini multilingua
- salvare `local_key` su term meta
- collegare le traduzioni tramite WPML
- propagare i campi ACF taxonomy-based

### `FieldGroup.php`

Gestisce i field group ACF.

Responsabilita' principali:

- elencare i field group
- creare field group e relativi campi
- aggiungere sempre il campo `local_key`
- impostare `acfml_field_group_mode = advanced` (modalita' "Expert" di ACFML) quando WPML e' attivo, e la preferenza di traduzione WPML `wpml_cf_preferences = 2` ("Translate") su ogni campo e sottocampo: l'importer scrive tutte le lingue, quindi WPML non deve mai copiare i valori dalla lingua di default sulle traduzioni
- cancellare field group per ID o titolo

## Convenzioni trasversali

### 1. Payload monolingua o multilingua

Per molti campi il plugin supporta due forme:

- valore semplice
- mappa per lingua

Esempi:

```json
{
  "title": "Titolo condiviso"
}
```

oppure:

```json
{
  "title": {
    "en": "Title",
    "it": "Titolo"
  }
}
```

La stessa logica vale per:

- `title`
- `content`
- `name`
- `slug`
- `description`
- valori ACF

### 2. Shared vs translated

I controller dividono il payload in due bucket:

- `shared`: valore unico usato da tutte le lingue
- `translated`: override per lingua

Questo permette di combinare:

- campi comuni a tutte le lingue
- campi specifici per singola lingua

Un valore e' riconosciuto come **per lingua** dalla sua forma: array associativo le cui chiavi sembrano tutte codici lingua (`MultiLang::isLanguageMapShape()`). Tutto il resto e' `shared` e deve arrivare **intatto** alla scrittura del campo, in particolare i valori strutturati di ACF — repeater (lista di righe), group (oggetto), checkbox multipli, gallery, relazioni. Applicare la risoluzione per lingua a un valore che non e' una mappa lingua produce `null`, e un `null` e' indistinguibile dalla richiesta legittima di svuotare un campo: la scrittura non fallisce, la risposta resta `200` e il dato sparisce senza segnalazione.

La regola vive in un punto solo, `MultiLang::resolveFields()`, usata dal percorso dei post e da quello WooCommerce. I termini seguono la stessa logica da `MultiLang::splitAcfFieldsByLanguage()`. Se serve risolvere per lingua altrove, riusare queste funzioni invece di riscriverne una copia: sono state tre copie divergenti a produrre il difetto.

### 3. Lingua di fallback

Quando un valore manca nella lingua corrente, il controller usa:

- la lingua richiesta se disponibile
- altrimenti la prima lingua tradotta trovata nel payload
- altrimenti il valore condiviso

### 4. WPML opzionale ma obbligatorio per payload multilingua

Se il payload contiene valori per lingua ma WPML non e' attivo, i controller non provano a degradare il comportamento: ritornano errore.

### 5. `local_key`

`local_key` e' l'identificativo esterno usato dal sistema chiamante per riconciliare gli oggetti. Puo' essere un **intero positivo o una stringa non vuota**: viene normalizzato con `trim` da `Input::localKey()` (rifiuta `""`, `"0"` e i non scalari), e interi e stringhe numeriche sono equivalenti perche' i meta WordPress sono comunque stringhe. In output `Input::localKeyOut()` restituisce un intero per le chiavi intero-canoniche (retrocompatibilita') e una stringa per quelle testuali.

Non e' un ID WordPress: serve a ritrovare un contenuto gia' sincronizzato quando l'ID interno non e' noto o non e' stabile per il chiamante.

La persistenza cambia in base al tipo di endpoint:

- endpoint basati su post: usano `wp_postmeta`
- endpoint basati su termini: usano `wp_termmeta`
- endpoint attributi globali WooCommerce: usano `wp_options`

Mappa pratica:

- `/posts`: salva e cerca sempre `local_key` in `wp_postmeta` con meta key `onpage_local_key`, sul post base e su tutte le sue traduzioni
- `/woocommerce/products`: salva e cerca sempre `local_key` in `wp_postmeta` con meta key `onpage_local_key`
- `/woocommerce/variant-products`: salva e cerca sempre `local_key` in `wp_postmeta` con meta key `onpage_local_key`
- `/terms`: salva e cerca `local_key` in `wp_termmeta` con meta key `onpage_local_key`
- `/woocommerce/categories`, `/woocommerce/tags`, `/woocommerce/brands`: trattano categorie, tag e brand come termini e usano `wp_termmeta` con meta key `onpage_local_key`
- `/woocommerce/attributes/{attribute}/terms`: tratta i valori degli attributi globali come termini della tassonomia `pa_*` e usa `wp_termmeta` con meta key `onpage_local_key`
- `/woocommerce/attributes`: salva e cerca `local_key` in `wp_options` con option name `onpage_wc_attribute_local_key_{attribute_id}`

Regola importante:

- la meta key tecnica è sempre `onpage_local_key`, sia negli endpoint basati su post (post, prodotti, varianti) sia in quelli basati su termini (termini, categorie, tag, brand, attribute terms)
- negli attributi globali WooCommerce la chiave tecnica e' l'option name `onpage_wc_attribute_local_key_{attribute_id}`
- `local_key` e `_local_key` sono meta legacy scritte da una versione precedente del plugin tramite un campo ACF ora rimosso: nessun endpoint le legge più, e `POST /migration` le rinomina/ripulisce
- nelle traduzioni WPML lo stesso `local_key` puo' comparire su piu' record dello stesso gruppo traduzioni, perche' rappresenta lo stesso oggetto esterno in lingue diverse

## `Post.php`: logica di business

### Scopo

`Post.php` gestisce batch di post in create/update/delete e prova a mantenere coerenti:

- post base
- post tradotti
- ACF fields
- tassonomie
- mapping WPML

### Helper principali

Prima di arrivare a `insert()` o `update()`, il service usa una serie di helper:

- `splitAcfFieldsByLanguage()`: separa ACF condivisi e tradotti
- `splitValueByLanguage()`: separa `title`/`content` in shared vs translated
- `getFieldsForLanguage()`: costruisce il set finale ACF per una lingua
- `getValueForLanguage()`: risolve il valore corretto per una lingua
- `resolvePostTitle()`: decide il titolo finale per una lingua
- `getPostLanguageDetails()`: legge lingua e `trid` WPML di un post
- `getPostTrid()`: recupera il `trid`
- `getTranslationPostIds()`: costruisce la mappa `language_code => post_id`
- `setPostLanguage()`: collega un post a un gruppo WPML
- `runWithWpmlLanguage()`: esegue codice forzando temporaneamente lingua WPML e lingua ACF
- `setTerms()`: assegna i termini, traducendoli per lingua se necessario

### Come decide tra insert e update

L’entrypoint REST e' `save()`.

Per ogni elemento del payload:

- se `id` e' valorizzato chiama `update()`
- altrimenti chiama `insert()`

Questa e' la regola principale di business per i post: l’ID esplicito decide se siamo in update o create.

## Flusso `Post::update()`

### 1. Validazioni iniziali

Il metodo:

- richiede `id`
- carica il post esistente
- risolve il post type reale con `norm_post_type()`
- verifica che il post type esista

Se uno di questi passaggi fallisce, il metodo termina con `WP_Error`.

### 2. Normalizzazione del payload

Il payload viene letto in modo conservativo.

Per `type`, `title`, `content`, `description`, `acf_fields`, `files`, `term` e `status` vale questa regola:

- se il campo e' presente e non `null`, viene trattato come modifica esplicita
- se il campo manca oppure vale `null`, il service lo interpreta come "nessuna modifica"

Solo i campi testuali realmente presenti vengono trasformati in:

- `title_map`
- `content_map`
- `description_map`

Poi costruisce:

- `translated_languages`
- `fallback_language`
- `current_language` del post
- `trid`
- `translation_ids`

`translation_ids` e' la mappa completa delle traduzioni gia' esistenti del post corrente.

### 3. Regola WPML

Se esistono dati tradotti nel payload ma WPML non e' attivo:

- ritorna errore `wpml_required`

### 4. Vincolo di unicita' sul titolo

Se il payload contiene `title`, il service verifica che il titolo finale di ogni lingua non sia gia' usato da un altro post dello stesso tipo.

La verifica non e' fatta solo sulla lingua corrente:

- se il post ha traduzioni, controlla ogni titolo risolto per ogni lingua
- se non ha traduzioni, controlla solo il titolo finale del post corrente

### 5. Update del post principale

Il service prepara `$data` con:

- `ID`
- `post_type`
- `post_status`

e aggiunge `post_title`, `post_content`, `post_excerpt` solo se quei campi sono stati davvero passati nel payload.

Poi esegue `wp_update_post()`.

Il post principale viene aggiornato nella sua lingua corrente, non automaticamente nella default language.

### 6. Update ACF del post principale

Se `acf_fields` e' presente:

- costruisce i campi corretti per `current_language`
- li salva con `update_field()`

### 7. Update termini del post principale

Se `term` e' presente:

- risolve gli slug ricevuti
- assegna i termini con `wp_set_object_terms()`

Per il post principale non viene forzata una lingua se non necessario.

### 8. Update delle traduzioni

Per ogni post tradotto in `translation_ids` diverso dal post corrente:

- esegue il blocco dentro `runWithWpmlLanguage($language_code, ...)`
- aggiorna solo i campi testuali e di stato effettivamente presenti nel payload
- salva gli ACF della lingua corretta
- assegna i termini traducendoli nella lingua target

Questa parte e' il cuore della logica business: l’update di un post non riguarda solo il record corrente, ma l’intero gruppo traduzioni quando il payload contiene dati multilingua.

### Risultato

`update()` ritorna:

- l’ID del post aggiornato in caso di successo
- `WP_Error` in caso di errore

## Flusso `Post::insert()`

### 1. Risoluzione del contesto

Il metodo:

- legge `type`
- lo normalizza con `norm_post_type()`
- costruisce `title_map`, `content_map`, `description_map`
- estrae `translated_languages`
- risolve `default_language`
- calcola `fallback_language`

La lingua base usata per creare l’originale e':

- `getWpmlDefaultLanguage()`
- oppure la prima lingua tradotta disponibile se la default manca

### 2. Regole di validazione

Prima di creare:

- se esistono traduzioni ma WPML non e' attivo, errore
- se il titolo dell’originale esiste gia' su un post **senza `local_key`**, errore `duplicate_title`; un post che porta una `local_key` diversa e' un altro elemento On Page® e non fa conflitto
- se `local_key` esiste gia' sullo stesso post type, errore `duplicate_local_key`

### 3. Creazione del post originale

Il post originale viene creato con:

- titolo risolto per la lingua base
- contenuto risolto per la lingua base
- `post_type`
- `post_status`

Poi:

- se esiste una lingua base, gli viene assegnata la lingua WPML
- vengono salvati gli ACF della lingua base
- se presente, `local_key` viene salvato in `wp_postmeta` con meta key `onpage_local_key` sul post e su tutte le sue traduzioni
- vengono assegnati i termini passati nel payload `term`

### 4. Inizializzazione del gruppo WPML

Se il payload contiene lingue tradotte:

- legge i `language_details` del post originale
- recupera `trid` e lingua sorgente
- se il `trid` non e' disponibile, fallisce

Questa e' la base per creare il gruppo di traduzione.

### 5. Creazione delle traduzioni

Per ogni lingua tradotta diversa dalla lingua base:

- risolve titolo e contenuto per quella lingua
- controlla che il titolo non sia duplicato
- crea un nuovo post
- collega il post al `trid` dell’originale con `source_language_code = lingua base`
- salva ACF della lingua target
- propaga `local_key` se il campo ACF esiste
- assegna i termini usando le traduzioni corrette

### 6. Pulizia in caso di errore

Se qualcosa fallisce durante la creazione:

- cancella il post appena creato
- in diversi punti cancella anche l’originale appena inserito

Quindi `insert()` prova a comportarsi come una pseudo-operazione atomica, anche se non usa vere transazioni SQL.

### Risultato

`insert()` ritorna:

- l’ID del post originale
- `WP_Error` in caso di errore

## Business rules importanti in `Post.php`

### Titolo come chiave di unicita' pratica

Il service considera il titolo una chiave quasi-univoca per il post type.

Questo vuol dire che payload diversi che producono lo stesso titolo finale entrano in conflitto.

### `local_key` come appoggio esterno, non chiave primaria interna

Per i post `local_key` viene usato per lookup e persistenza in `wp_postmeta` (meta key `onpage_local_key`), ma la scelta tra create e update dipende comunque soprattutto da `id`.

### `term` sempre riallineato quando presente

Quando `term` e' presente, il service non fa merge incrementale:

- risolve gli slug ricevuti
- assegna quel set al post

Quando `term` manca del tutto in update, le assegnazioni esistenti vengono conservate.

### ACF per lingua

Un campo ACF puo' essere:

- shared
- specifico per una lingua

Il controller costruisce per ogni post tradotto il payload ACF finale combinando shared + override della lingua.

## `Term.php` / `TermService`: logica di business

### Scopo

`TermService` gestisce termini tassonomici con supporto multilingua e ACF.

A differenza di `Post.php`, qui non esistono metodi separati `insert()` e `update()`: il metodo `insert()` fa upsert.

In pratica:

- se il termine esiste, aggiorna
- se non esiste, crea

## Flusso `Term::insert()`

### 1. Risoluzione tassonomia

La tassonomia arriva dalla route e puo' essere:

- slug
- ID ACF taxonomy

Il service la risolve in uno slug WordPress reale.

### 2. Normalizzazione del payload

Per ogni elemento batch costruisce:

- `name_map`
- `slug_map`
- `description_map`
- `acf_field_map`
- `local_key`

Poi estrae:

- `translated_languages`
- `fallback_language`
- `default_language`
- `base_language`

La `base_language` e':

- la default WPML, se esiste
- altrimenti la prima lingua tradotta del payload

### 3. Validazioni iniziali

Le regole principali sono:

- se ci sono traduzioni ma WPML non e' attivo, errore
- se c’e' contenuto tradotto ma non si riesce a stabilire la lingua base, errore
- il nome nella lingua base e' obbligatorio
- se arriva un `id`, il termine deve esistere nella tassonomia

### 4. Regola su `local_key`

Il service usa `local_key` come chiave esterna di riconciliazione.

Flusso:

- cerca un termine esistente con la stessa `local_key`
- se esiste anche un `id` richiesto e i due termini non appartengono allo stesso gruppo traduzioni, ritorna `duplicate_local_key`

Questa e' una regola business importante: la stessa `local_key` puo' rappresentare traduzioni dello stesso concetto, ma non due termini scollegati.

### 5. Decisione create vs update

Il termine base viene scelto cosi':

- usa `id` se presente
- altrimenti usa il termine trovato tramite `local_key`
- se esiste una lingua base multilingua, converte l’ID nel termine corrispondente di quella lingua

Se alla fine esiste un `term_id`:

- chiama `wp_update_term()`

Altrimenti:

- chiama `wp_insert_term()`

Questa e' la vera logica di business di `TermService`: upsert guidato da `id` o `local_key`.

### 6. Salvataggio del termine base

Dopo la create/update del termine base:

- salva `local_key` come term meta `onpage_local_key`
- salva i campi ACF della lingua base
- per i campi ACF di tipo `image`, se il valore e' un URL valido, importa o riusa il media e salva l'`attachment_id`
- gli SVG remoti vengono sanificati e il MIME `image/svg+xml` viene abilitato solo durante il sideload del singolo file

### 7. Inizializzazione WPML

Se il payload contiene traduzioni:

- legge i dettagli lingua del termine base
- se il termine non ha ancora `trid`, gli assegna la lingua base
- rilegge i dettagli WPML
- se ancora non trova un `trid`, ritorna errore

### 8. Upsert delle traduzioni

Per ogni lingua diversa dalla base:

- risolve `name`, `slug`, `description`
- se il nome tradotto manca, salta quella lingua
- legge la traduzione esistente dal gruppo WPML
- converte eventuale `term_taxonomy_id` in `term_id`
- se il termine tradotto esiste, lo aggiorna
- altrimenti lo crea
- se lo crea, lo collega allo stesso `trid` del termine base
- salva `local_key`
- salva gli ACF della lingua corretta
- per i campi ACF di tipo `image`, ogni lingua puo' importare e salvare il proprio media

### Risultato

`Term::insert()` ritorna:

- l’ID del termine base per ogni elemento del batch
- `WP_Error` in caso di errore

## Business rules importanti in `Term.php`

### `insert()` e' un upsert

Il nome del metodo puo' confondere: non fa solo insert, ma decide dinamicamente tra insert e update.

### `local_key` e' la chiave di riconciliazione principale

Nei termini il flusso e' molto piu' guidato da `local_key` che da `id`.

Il valore viene salvato in `wp_termmeta` con meta key `onpage_local_key` e propagato con lo stesso valore a tutte le traduzioni WPML del termine.

### Il gruppo traduzioni e' sul `term_taxonomy_id`

Per WPML, i termini non vengono collegati usando direttamente `term_id`, ma `term_taxonomy_id`.

Per questo il service ha helper dedicati:

- `getTermTaxonomyId()`
- `getTermIdByTaxonomyId()`

### Le lingue senza `name` vengono ignorate

Se per una lingua tradotta manca il nome, quella traduzione non viene creata o aggiornata.

## Differenze principali tra `Post.php` e `Term.php`

### Strategia di update/create

`Post.php`:

- `save()` sceglie tra `insert()` e `update()` in base alla presenza di `id`

`Term.php`:

- `insert()` fa gia' da upsert usando `id` o `local_key`

### Chiave esterna

Post:

- `local_key` e' di supporto, ma non guida in prima battuta il branch create/update
- viene letto da `wp_postmeta` con meta key `onpage_local_key`

Term:

- `local_key` e' una chiave di riconciliazione fondamentale
- viene letto da `wp_termmeta` con meta key `onpage_local_key`

### Aggiornamento gruppo traduzioni

Post:

- aggiorna il post corrente
- poi itera tutte le traduzioni note e le riallinea

Term:

- upserta il termine base
- poi upserta le traduzioni una per una

## Note operative per chi modifica questi controller

### Quando toccare la logica lingue

Bisogna verificare sempre:

- cosa succede con WPML disattivo
- come viene risolta la lingua base
- quale fallback viene usato
- se ACF segue correttamente la lingua corrente

### Quando toccare la logica titoli o slug

Attenzione a:

- controlli di unicita'
- comportamento su payload parziali
- propagazione alle traduzioni esistenti

### Quando toccare `wpml_get_element_translations`

Per i post, il controller usa l’`element_type` WPML completo e chiede `all_statuses = true`, altrimenti i post in `draft` possono essere esclusi dalla risposta del filtro.

### Quando toccare `local_key`

Ricordare che:

- nei post generici e nei prodotti/varianti WooCommerce è salvato sempre in `wp_postmeta` come `onpage_local_key`
- nei termini/categorie/tag/brand/attribute terms e' salvato in `wp_termmeta` come `onpage_local_key`
- le meta legacy `local_key` / `_local_key` non vengono più scritte: `POST /migration` rinomina la prima in `onpage_local_key` sui post e cancella le copie ridondanti
- cambiare questa logica impatta deduplica, lookup e sincronizzazione esterna

### Quando toccare SKU WooCommerce con WPML

WooCommerce richiede SKU globalmente univoci.

Per questo:

- `Product.php` applica `props.sku` al prodotto sorgente, ma non alle traduzioni
- `VariantProduct.php` applica `props.sku` alla variazione sorgente, ma non alle variazioni tradotte
- prezzi e altri campi WooCommerce possono invece essere propagati anche alle traduzioni

Se questa regola viene rimossa, WooCommerce puo' lanciare `WC_Data_Exception` durante `set_sku()` per SKU duplicato.

## Mappa mentale rapida

### Post

1. normalizza payload
2. scopre lingue e traduzioni esistenti
3. valida titolo e precondizioni
4. aggiorna o crea originale
5. salva ACF
6. assegna tassonomie
7. crea/aggiorna traduzioni WPML

### Term

1. risolve tassonomia
2. normalizza payload
3. determina lingua base
4. trova termine tramite `id` o `local_key`
5. insert/update del termine base
6. salva meta e ACF
7. inizializza gruppo WPML
8. insert/update delle traduzioni

### Taxonomy Delete

Quando una tassonomia viene eliminata, il service rimuove anche i field group ACF con location `taxonomy == <slug>`. Con `ignore=1`, questa pulizia viene tentata anche se la tassonomia non esiste piu', cosi' una sync puo' ripulire field group rimasti da una precedente cancellazione parziale.

## Test

I test stanno in `src/Tests/` e non fanno parte del plugin: non vengono inclusi da `onpage.php` e restano fuori dal pacchetto di distribuzione. Sono script PHP da riga di comando che parlano con un sito WordPress vero attraverso le API REST del plugin.

Configurazione nel file `.env` della root (vedi `.env.example`):

- `WP_TEST_URL` URL base del sito di prova, senza slash finale
- `WP_TEST_TOKEN` token Bearer, lo stesso valore dell'opzione WordPress `onpage_auth_token`

```
./bin/test-launcher              tutti i test
./bin/test-launcher AcfShared    solo quelli il cui nome contiene "AcfShared"
```

Il launcher esegue i test uno per uno mostrando l'esito di ciascuno e **si ferma al primo che fallisce**, restituendone il codice di uscita: i test condividono lo stesso sito, quindi proseguire su uno stato gia' sporco produrrebbe solo errori derivati. Un singolo test resta comunque eseguibile da solo con `php src/Tests/<Nome>.php`.

### Attenzione ai commenti nel `.env`

Il `.env` ha due lettori che non concordano sulla sintassi dei commenti: docker compose vuole `#`, PHP vuole `;` e tollera `#` solo finche' il commento resta prosa semplice. Se un commento `#` contiene uno tra `( ) " ! & | $ { } [ ] ~`, `parse_ini_file()` scarta **l'intero file** e `OnPage\Env` legge ogni variabile come vuota, senza che nulla lo segnali: il sintomo e' un token che risulta non valorizzato pur essendo scritto nel file. `./bin/test-launcher` verifica questa condizione prima di partire e la riporta esplicitamente.

### Log di audit

Ogni corsa del launcher riscrive `logs/audit.log` con la conversazione HTTP completa: per ogni chiamata una riga `[req]` con metodo, URL e corpo, e una `[res]` con stato HTTP, durata e corpo della risposta. Le chiamate stanno sotto l'intestazione del test che le ha fatte, e ogni test si chiude con una riga `[esito]`. I corpi JSON sono formattati su piu' righe, perche' un repeater annidato letto su una riga sola e' cio' che rende lenta una sessione di debug; una risposta che JSON non e' — un fatal di PHP, una pagina di errore HTML — viene riportata tale e quale, che e' esattamente quello che serve vedere.

Il token non viene mai scritto: l'header Authorization compare come `Bearer <nascosto>`, cosi' il file si puo' allegare a una segnalazione senza pensarci.

Il file riparte da zero a ogni corsa del launcher, quindi contiene sempre e solo l'ultima esecuzione. `logs/` e' in `.gitignore` e non entra nel pacchetto di distribuzione. Un test eseguito da solo con `php src/Tests/<Nome>.php` scrive nello stesso file, in coda.

La scrittura del log non puo' far fallire un test: la destinazione viene risolta una volta sola e, se non e' scrivibile, il log si disattiva in silenzio.

Ogni test segue un flusso **crea-cancella**: costruisce da se' la propria fixture (post type, field group, contenuti), verifica, e rimuove tutto quello che ha creato anche quando un'asserzione fallisce. La pulizia gira anche **prima** della fixture, cosi' una corsa interrotta non blocca quella successiva, e usa sempre `?ignore=1` per non trasformare un residuo mancante in un secondo errore. Puntare i test a un sito di prova, mai alla produzione.

- `WooCommerceCatalog.php` e' il test di base. Fa in un giro solo quello che fa ogni import: prima dichiara le strutture — la tassonomia custom, l'attributo globale WooCommerce e sette field group ACF, uno per prodotto, variante, `product_cat`, `product_tag`, `product_brand`, tassonomia custom e tassonomia `pa_*` dell'attributo — poi ci pubblica dentro i dati: brand, categoria madre e figlia, tag, i due valori dell'attributo, un termine della tassonomia custom, un prodotto semplice, un prodotto variabile e due varianti, ciascuno con i propri valori ACF. Rilegge tutto dagli endpoint `GET` e alla fine cancella. I dati sono **inventati nel file**: a differenza del test equivalente nella repo `connector-wordpress`, non viene letto niente da On Page®, cosi' il test gira anche contro un WordPress spoglio. Richiede un sito con WooCommerce e ACF attivi.
- `AcfSharedStructuredFields.php` copre end to end la regola dei valori condivisi: valori ACF strutturati inviati come condivisi su `POST /posts` devono sopravvivere al giro di andata e ritorno. Richiede un sito.
- `MultiLangResolveFields.php` copre la stessa regola offline, direttamente su `MultiLang::resolveFields()`: non richiede ne' sito ne' `.env`, e arriva alle forme che l'endpoint non puo' raggiungere, in particolare il valore associativo. Contiene anche un caso etichettato come *comportamento attuale, non quello desiderato*, che fissa il falso positivo descritto sotto: serve a far fallire il test se qualcuno cambia il predicato, cosi' la modifica e' una decisione e non una svista.

### Come ACF persiste i sotto-campi

Vale la pena saperlo, perche' fraintenderlo fa perdere in silenzio i sotto-campi dei `group`.

ACF salva **ogni** campo, sotto-campi compresi, come un proprio post `acf-field`, con `post_parent` che punta al campo di sopra. `acf_update_field()` ne salva uno e si ferma: non scende nei `sub_fields` annidati. Passargli un campo con i sotto-campi dentro creava quindi il padre e nessun figlio, lasciando una copia serializzata dei sotto-campi dentro il `post_content` del padre, dove ACF non la cerca — un `group` si ricarica i sotto-campi con `acf_get_fields()`, che legge i post figli.

Il sintomo era un gruppo che rispondeva `200` e poi si rileggeva con tutti i sotto-campi schiacciati sulla chiave vuota, perche' `format_value()` del group indicizza il risultato con `$sub_field['_name']`, che in quella copia serializzata non esiste. Un repeater definito allo stesso modo sembrava funzionare, ed e' quello che ha tenuto nascosto il problema.

`FieldGroup::persistFields()` salva ora un livello alla volta, il padre prima dei figli, ricorrendo nei `sub_fields`: e' lo stesso appiattimento che fa l'import di ACF. Chi aggiunge un tipo di campo con figli deve passare di li'.

Non coperto: il **flexible content**, i cui `layouts` contengono a loro volta `sub_fields` e continuano a essere serializzati inline come prima. Nessuna verifica e' stata fatta su quel tipo.
