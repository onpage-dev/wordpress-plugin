# WooCommerce

Questo documento descrive come il plugin On Page® integra WooCommerce tramite le API REST interne e, in particolare, come vengono gestiti e salvati i file scaricabili (`downloads`) di un prodotto.

## Base REST

Tutte le rotte WooCommerce sono registrate nel namespace:

```text
/wp-json/onpage/v1
```

Le rotte sono protette dal middleware di autenticazione del plugin, come le altre API On Page®.

## Risorse WooCommerce gestite

Il plugin espone endpoint dedicati alle principali entita' WooCommerce:

- `GET|POST|DELETE /woocommerce/products` per prodotti semplici e variabili
- `GET|POST|DELETE /woocommerce/variant-products` per variazioni di prodotto
- `GET|POST|DELETE /woocommerce/categories` per categorie prodotto (`product_cat`)
- `GET|POST|DELETE /woocommerce/tags` per tag prodotto (`product_tag`)
- `GET|POST|DELETE /woocommerce/brands` per brand prodotto (`product_brand`)
- `GET|POST|DELETE /woocommerce/attributes` per attributi globali WooCommerce
- `GET|POST|DELETE /woocommerce/attributes/{attribute}/terms` per i termini degli attributi globali

Le operazioni WooCommerce usano le classi CRUD native di WooCommerce (`WC_Product_Simple`, `WC_Product_Variable`, `WC_Product_Variation`, `WC_Product_Download`) invece di scrivere direttamente nei meta quando WooCommerce offre un metodo ufficiale.

**Paginazione:** nessuno degli endpoint `GET` WooCommerce sopra elencati pagina — ogni chiamata restituisce sempre **tutti** gli elementi che soddisfano i filtri passati, senza `per_page`/`page`. Su cataloghi molto grandi (in particolare `products`/`variant-products`) la risposta puo' essere pesante. Vedi [PAGINATION.md](PAGINATION.md) per la tabella completa di tutti gli endpoint GET del plugin (WooCommerce e non).

## Categorie e tag

`POST /woocommerce/categories` e `POST /woocommerce/tags` usano il payload dei termini con `local_key`, `name`, `slug`, `description` e `acf_fields`.

Quando `name` e' una stringa e altri campi sono mappe lingua WPML, lo stesso nome viene riusato invariato su tutte le traduzioni create. Se non viene passato uno `slug` per lingua, le traduzioni ricevono uno slug tecnico distinto, mentre il nome visibile resta quello condiviso.

Un termine gia' esistente con lo stesso nome sotto lo stesso parent ma con un `local_key` diverso non viene toccato (per non sovrascrivere una categoria di un'altra origine): poiche' in WordPress due termini fratelli non possono avere lo stesso nome se non con uno slug esplicito libero, viene creato un termine distinto con slug tecnico (`<slug-base>-<lingua>`, con suffisso numerico se occupato). Ritrovarsi due termini omonimi e' il sintomo tipico di `local_key` disallineate tra sorgente e destinazione: si risolve con `DELETE /indexes` seguito da un re-import top-down (vedi API.md).

## Flusso di salvataggio di un Product

`POST /woocommerce/products` riceve una lista JSON di prodotti. Ogni elemento viene normalizzato e poi creato o aggiornato.

La risoluzione del prodotto avviene cosi':

1. se nel payload e' presente `id`, viene aggiornato quel prodotto;
2. se manca `id` ma esiste gia' un prodotto con lo stesso `local_key`, viene aggiornato quello;
3. altrimenti viene creato un nuovo prodotto WooCommerce.

`local_key` e' l'identificativo esterno usato da On Page®; puo' essere un **intero positivo o una stringa non vuota** (interi e stringhe numeriche sono equivalenti). Per i prodotti viene salvato come post meta `onpage_local_key` e deve restare unico tra prodotti non appartenenti allo stesso gruppo di traduzioni WPML.

Il payload di un prodotto puo' contenere:

- `local_key`, obbligatorio (intero o stringa non vuota);
- `name`, obbligatorio, come stringa o mappa lingua WPML;
- quando `name` e' una stringa, viene riusato invariato su tutte le traduzioni create da altri campi multilingua;
- `status`, opzionale, con default `publish`;
- `long_description` e `short_description` per descrizione lunga e breve WooCommerce;
- `props` per i campi nativi WooCommerce, ad esempio prezzi, stock, SKU, peso, dimensioni, visibilita', `product_type`;
- `image` per impostare o rimuovere l'immagine principale: accetta un URL remoto da importare in Media Library, oppure l'`attachment_id` (intero) di un file gia' presente in Media Library (es. caricato prima con `POST /media`), oppure `null` per rimuoverla;
- `gallery` per sostituire la galleria immagini del prodotto, come lista di URL remoti e/o `attachment_id` (o mappa lingua WPML di liste); l'ordine delle immagini nella galleria rispetta l'ordine degli elementi nell'array (elementi duplicati nella lista vengono ignorati, conta la prima occorrenza); lista vuota o `null` svuota la galleria;
- `attributes` per sostituire gli attributi custom del prodotto;
- `acf_fields` per aggiornare campi ACF;
- `brand`, `categories`, `tags` per assegnare le tassonomie prodotto;
- `downloads` per i file scaricabili nativi WooCommerce.

Durante il salvataggio il service:

1. crea o carica l'oggetto WooCommerce corretto (`simple` o `variable`);
2. applica titolo, descrizioni e stato;
3. applica i campi nativi da `props`;
4. applica attributi custom e downloads;
5. salva il prodotto con `$product->save()`;
6. aggiorna `local_key`, immagine, galleria, ACF e tassonomie;
7. se WPML e' attivo, crea o aggiorna anche le traduzioni: su un prodotto gia' esistente le traduzioni presenti vengono aggiornate e quelle che il payload porta ma che mancano nel gruppo WPML vengono create in quel momento;
8. se il prodotto e' `variable`, sincronizza i downloads del parent sulle variazioni esistenti.

## Downloads di un Product

`downloads` gestisce i file scaricabili nativi WooCommerce del prodotto. Non e' la stessa cosa di un campo ACF di tipo file: i valori inviati in `downloads` finiscono nella struttura download di WooCommerce, mentre i file in `acf_fields` vengono salvati nei rispettivi campi ACF.

Esempio:

```json
[
  {
    "local_key": 1001,
    "name": "Sedia rossa",
    "props": {
      "product_type": "simple",
      "regular_price": "49.90"
    },
    "downloads": [
      {
        "id": "scheda_tecnica",
        "name": "Scheda tecnica",
        "url": "https://cdn.example.com/prod-001/scheda-tecnica.pdf"
      },
      {
        "id": "scheda_sicurezza",
        "name": "Scheda di sicurezza",
        "file": "https://cdn.example.com/prod-001/scheda-sicurezza.pdf",
        "refresh": true
      }
    ]
  }
]
```

Ogni elemento di `downloads` accetta:

- `file` oppure `url`: URL del file scaricabile, oppure l'`attachment_id` (intero JSON) di un file gia' presente in Media Library (es. caricato con `POST /media`); `url` e' un alias accettato e viene normalizzato internamente come `file`; il valore puo' essere `null` o stringa vuota per saltare quel download nella lingua risolta;
- `name`: nome mostrato da WooCommerce e dal frontend; se omesso viene usato il nome del file ricavato dall'URL;
- `id`: identificativo stabile del download; se omesso viene generato un UUID WordPress;
- `refresh`: boolean opzionale; se `true`, forza il refresh del file importato in Media Library quando l'URL remoto e' invariato ma il contenuto e' cambiato.

`file`, `url` e `name` possono essere anche mappe lingua WPML. In quel caso ogni traduzione del prodotto riceve il valore risolto per la propria lingua, con fallback quando previsto dal service multilingua. Valori `null` o vuoti in `file`/`url` vengono ignorati per quella lingua.

## Come vengono salvati i downloads

Il salvataggio dei downloads avviene nel service `ProductDownloads`, chiamato dal salvataggio del prodotto.

Per ogni download:

1. il valore `file`/`url` viene risolto per la lingua corrente, senza perdere il tipo (un `attachment_id` resta un intero anche dopo la risoluzione lingua);
2. se il valore risolto e' un `attachment_id`, viene verificato che corrisponda a un attachment esistente e se ne usa direttamente l'URL locale, senza download; altrimenti l'URL viene validato e normalizzato;
3. se l'URL non punta gia' alla directory uploads del sito, il file viene importato o riusato in Media Library tramite `RemoteMedia::urlToMediaLibrary()`;
4. WooCommerce riceve sempre un URL compatibile con le sue directory approvate di download;
5. viene creato un oggetto `WC_Product_Download`;
6. sull'oggetto vengono impostati `id`, `name` e `file`;
7. la lista completa viene assegnata al prodotto con `$product->set_downloads($downloads)`;
8. se la lista non e' vuota, il prodotto viene marcato come scaricabile con `$product->set_downloadable(true)`;
9. il prodotto viene poi salvato con `$product->save()`.

WooCommerce persiste questi download nel proprio storage nativo del prodotto, cioe' nel post meta `_downloadable_files` gestito dal data store WooCommerce. In pratica il plugin non salva i downloads come ACF e non mantiene una tabella custom: delega a WooCommerce tramite `set_downloads()` e `save()`.

La struttura salvata da WooCommerce contiene, per ogni file, almeno:

- identificativo del download;
- nome del download;
- URL/path del file;
- stato di abilitazione del download.

WooCommerce indicizza internamente la lista per `download_id`; per questo e' importante usare `id` stabili quando un documento deve essere aggiornato nel tempo senza cambiare identita' logica.

La response `GET /woocommerce/products` espone questi valori in:

```json
{
  "woocommerce": {
    "downloads": [
      {
        "id": "scheda_tecnica",
        "name": "Scheda tecnica",
        "file": "https://example.com/wp-content/uploads/2026/05/scheda-tecnica.pdf",
        "enabled": true
      }
    ]
  }
}
```

## Import in Media Library

Quando il file remoto non e' gia' dentro `wp-content/uploads`, il plugin lo importa nella Media Library prima di assegnarlo al prodotto.

Questo comportamento serve a:

- avere un file locale gestito da WordPress;
- riusare attachment gia' importati dallo stesso URL;
- ottenere un URL compatibile con WooCommerce;
- agganciare l'attachment al prodotto parent nella Media Library;
- rispettare la logica delle approved download directories di WooCommerce.

Se il file remoto e' gia' stato importato, viene riusato l'attachment esistente. Se il contenuto remoto cambia ma l'URL resta uguale, usare `refresh: true` per forzare la sostituzione del file importato quando possibile.

I campi immagine/file che accettano un URL remoto accettano anche, in alternativa, l'`attachment_id` (intero JSON, non stringa) di un file gia' presente in Media Library — ad esempio caricato in precedenza con `POST /media`. In questo caso il plugin non scarica nulla: verifica solo che l'ID corrisponda a un attachment esistente e lo assegna direttamente (dove il campo ha un post/prodotto/variazione parent, l'attachment viene anche agganciato come parent, coerentemente con l'import da URL). Vale per:

- `image` e `gallery` di `POST /woocommerce/products` e `POST /woocommerce/variant-products`;
- `thumbnail` di `POST /woocommerce/brands` e `POST /woocommerce/categories` (tassonomia `product_cat`);
- `downloads[].file`/`downloads[].url` di `POST /woocommerce/products`;
- `files` di `POST /posts` e `POST /media/link`;
- ogni campo ACF di tipo `image`/`file` dentro `acf_fields`, su qualsiasi endpoint (top-level, dentro `repeater` o `group`).

## Rimozione downloads

Per rimuovere tutti i file scaricabili nativi WooCommerce di un prodotto:

```json
[
  {
    "local_key": 1001,
    "name": "Sedia rossa",
    "downloads": []
  }
]
```

oppure:

```json
[
  {
    "local_key": 1001,
    "name": "Sedia rossa",
    "downloads": null
  }
]
```

In entrambi i casi la lista viene normalizzata a vuota e passata a WooCommerce con `set_downloads([])`.

Se `downloads` e' omesso dal payload, i downloads esistenti non vengono modificati.

## Downloads pubblici nella pagina prodotto

Il plugin registra un hook frontend su `woocommerce_single_product_summary`. Questo hook mostra una sezione "Documenti" nella pagina prodotto WooCommerce.

Per evitare di esporre download non gestiti dall'API On Page®, il plugin salva anche il meta:

```text
_onpage_public_download_ids
```

Questo meta contiene gli ID dei downloads inviati tramite l'endpoint. In frontend vengono mostrati solo i download:

- presenti nel prodotto WooCommerce;
- inclusi in `_onpage_public_download_ids`;
- abilitati da WooCommerce;
- con un file valorizzato.

Quindi WooCommerce resta il sistema che salva i file scaricabili, mentre `_onpage_public_download_ids` serve solo come lista di sicurezza per decidere quali link rendere pubblici nella pagina prodotto.

## Prodotti variabili e variazioni

WooCommerce gestisce i downloads dei prodotti variabili in modo particolare: nell'admin i file scaricabili sono normalmente visibili sulle singole variazioni.

Per questo motivo, quando un parent `variable` riceve `downloads`, il plugin:

- salva i downloads sul prodotto parent;
- costruisce un payload compatibile con WooCommerce;
- copia la stessa lista sulle variazioni esistenti;
- marca le variazioni come scaricabili se la lista non e' vuota.

Quando viene salvata una nuova variazione con `/woocommerce/variant-products`, questa eredita automaticamente i downloads del parent variable product, cosi' la UI WooCommerce resta coerente.

## Note operative

- Usare `id` stabili nei downloads quando il file rappresenta sempre lo stesso documento logico, ad esempio `scheda_tecnica` o `scheda_sicurezza`.
- Inviare `downloads` solo quando si vuole sostituire l'intera lista dei file scaricabili WooCommerce.
- Omettere `downloads` quando si vuole aggiornare il prodotto lasciando invariati i file scaricabili.
- Usare `refresh: true` quando un PDF remoto e' stato rigenerato mantenendo lo stesso URL.
- Salvare documenti commerciali o tecnici in `downloads`; salvare file puramente editoriali o dati custom in `acf_fields` solo se devono restare campi ACF.
