# Guida Utente

## Cos'è On Page®

Il plugin **On Page®** ha lo scopo di sincronizzare dati provenienti dal PIM di On Page® verso WordPress.

In pratica:

- **On Page®** contiene i dati sorgente
- l'**Integrazione custom per il cliente** trasferisce i dati a WordPress
- il plugin **On Page®** riceve questi dati tramite **API REST**
- WordPress li salva come contenuti strutturati

Questo permette di usare WordPress come sito di pubblicazione, mantenendo i dati gestiti centralmente su On Page®.

## A cosa serve

Il plugin è utile quando si vogliono importare e aggiornare in WordPress raccolte di contenuti strutturati, per esempio:

- auto
- immobili
- prodotti
- sedi
- cataloghi

Esempio pratico:

- una raccolta On Page® chiamata **Car**
- in WordPress diventa un **Post Type**
- ogni singolo oggetto della raccolta diventa un contenuto del sito

## Dipendenze e integrazioni

Il plugin si appoggia ad alcuni plugin WordPress esterni:

- **Advanced Custom Fields (ACF)** — **obbligatorio**  
  Serve per creare e gestire campi personalizzati strutturati collegati ai contenuti importati: permette di esporre e organizzare quei dati nel backend WordPress e nei template del sito. Il plugin lo interroga a ogni chiamata delle API, quindi deve essere attivo anche quando non servono campi personalizzati.

- **WPML**  
  Serve se il sito deve gestire contenuti multilingua sincronizzati. Permette di associare contenuti, termini, label e dati tradotti alle lingue configurate in WordPress.

- **WooCommerce**  
  Serve solo se l'integrazione deve sincronizzare prodotti, categorie, brand o altri dati e-commerce.

Nota importante:

- **ACF è obbligatorio, sempre**: senza ACF attivo le API del plugin rispondono con un errore critico (`500`). Vale anche per la sola sincronizzazione WooCommerce e anche se non servono campi personalizzati sui prodotti
- **ACF PRO** serve solo per i tipi di campo avanzati (repeater, flexible content, gallery)
- **WPML** è richiesto solo per le sincronizzazioni multilingua, ed è un **plugin a pagamento**
- senza WPML le funzioni multilingua non saranno disponibili

## Come funziona la sincronizzazione

Il flusso generale è questo:

1. I dati vengono preparati in **On Page®**
2. l'**Integrazione custom per il cliente** chiama le API REST del plugin su WordPress
3. Il plugin crea o aggiorna:
   - **Post Type**
   - **campi ACF**, se previsti dalla configurazione
   - **tassonomie**
   - **termini**
   - **contenuti**
   - **prodotti e dati WooCommerce**, se l'integrazione e-commerce è attiva
4. I dati diventano disponibili nel backend WordPress e nel sito

## Corrispondenza tra On Page® e WordPress

Per capire meglio come vengono trasformati i dati:

- una **raccolta** di On Page® corrisponde a un **Post Type** in WordPress
- un **oggetto** di On Page® corrisponde a un **contenuto** del Post Type
- un **campo** di On Page® può corrispondere a un **campo personalizzato ACF**, quando ACF è usato nel progetto
- una **classificazione** di On Page® può corrispondere a una **tassonomia**
- un **prodotto** di On Page® può corrispondere a un **prodotto WooCommerce**, quando l'integrazione e-commerce è attiva

Esempio:

- Raccolta On Page®: `Car`
- Post Type WordPress: `Car`
- Campo On Page®: `model`
- Campo ACF WordPress: `model`
- Campo On Page®: `year`
- Campo ACF WordPress: `year`

## Cosa vede l'utente in WordPress

Dopo la sincronizzazione, nel pannello WordPress l’utente vedrà:

- nuovi **tipi di contenuto**
- nuovi **campi personalizzati**
- eventuali **tassonomie** e **categorie custom**
- contenuti già compilati con i dati arrivati da On Page®

L’utente può quindi:

- visualizzare i contenuti nel backend
- usarli nei template del sito
- filtrarli tramite tassonomie
- gestirli anche in più lingue, se WPML è configurato

## Requisiti per l'uso corretto

> **Attenzione:** per usare correttamente il plugin **On Page®** e le sue API REST, in WordPress le impostazioni dei **Permalink** devono essere configurate su **Post name**.  
> Non usare l'impostazione **Plain**, perché può impedire il corretto funzionamento degli endpoint del plugin.

Per un utilizzo corretto è consigliato che:

- ACF sia installato e attivo se il progetto sincronizza Post Type e campi personalizzati ACF
- WPML sia installato e attivo se il progetto usa più lingue
- WooCommerce sia installato e attivo se il progetto sincronizza dati e-commerce
- il plugin On Page® sia installato e attivo
- l'**Integrazione custom per il cliente** sia configurata per chiamare le API REST del plugin
- WordPress sia raggiungibile dall'**Integrazione custom per il cliente**

## Sicurezza delle API

Le API REST del plugin sono protette con un **token di autenticazione**.

Questo significa che:

- solo l'**Integrazione custom per il cliente** autorizzata può inviare dati a WordPress
- utenti esterni non possono usare le API senza token valido

Il token viene gestito dal plugin direttamente nel pannello WordPress.

La pagina **On Page®** e la generazione/rigenerazione del token sono accessibili **solo agli utenti amministratori** (capability WordPress `manage_options`). Gli altri ruoli (editor, autori, collaboratori, sottoscrittori) non vedono la voce di menu **On Page®** e non possono creare, vedere o rigenerare il token, nemmeno accedendo direttamente all'URL della pagina.

Flusso operativo:

1. installare e attivare il plugin **On Page®** (caricando file .zip nella sezione Plugins di Wordpress)
2. aprire la voce di menu **On Page®** nel backend WordPress
3. cliccare sul pulsante **Genera token**
4. copiare il token e fornirlo al team di On Page® che svilupperà l'**Integrazione custom per il cliente**
5. inviare le chiamate REST con header `Authorization: Bearer <token>`

Nota:

- il token viene salvato nella configurazione di WordPress
- se si rigenera il token, il precedente smette di funzionare

## Gestione multilingua

Se il sito usa più lingue:

- WPML permette di rendere i contenuti sincronizzati disponibili in più lingue
- il plugin può gestire dati multilingua per contenuti e alcune label
- le traduzioni non vengono generate automaticamente: vengono usati i valori passati dal sistema sorgente o dalla configurazione prevista

## Cosa non fa il plugin

Questo plugin non è pensato per:

- sostituire la normale gestione editoriale di WordPress
- creare manualmente contenuti dalla UI per flussi complessi di sincronizzazione
- tradurre automaticamente i contenuti con crediti WPML

Il suo scopo principale è:

- **ricevere dati strutturati**
- **mappare quei dati in WordPress**
- **mantenerli sincronizzati con On Page® tramite Integrazione custom per il cliente**

## In sintesi

Il plugin **On Page®** collega On Page® e WordPress.

Permette di:

- ricevere dati dall'**Integrazione custom per il cliente**
- trasformare le raccolte in **Post Type**
- trasformare i campi in **ACF custom fields**, se ACF è configurato
- sincronizzare dati e-commerce con **WooCommerce**, senza rendere obbligatori ACF o WPML
- supportare strutture dati complesse e multilingua, se WPML è configurato

È quindi una soluzione pensata per siti WordPress che devono pubblicare dati strutturati provenienti da un sistema esterno.
