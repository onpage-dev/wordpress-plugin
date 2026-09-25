# On Page® WordPress Plugin

Questo plugin permette a WordPress di ricevere e sincronizzare dati strutturati provenienti da **On Page®** tramite il servizio **Exporter**.

In sintesi, il plugin:

- espone API REST per ricevere dati da Exporter
- crea e aggiorna Post Type, campi ACF, tassonomie, termini e contenuti
- supporta la pubblicazione in WordPress di raccolte gestite centralmente su On Page®
- puo' integrarsi con WPML per progetti multilingua

## Versione

Versione corrente: **1.0.0**. Il progetto segue il [Semantic Versioning](https://semver.org/lang/it/):
le modifiche di ogni versione sono in [CHANGELOG.md](CHANGELOG.md), la procedura di rilascio in
[RELEASE.md](RELEASE.md). Le versioni pubblicate sono nelle
[Release della repo GitHub](https://github.com/onpage-dev/wordpress-plugin/releases).

## Requisiti

- WordPress 7.1 o superiore
- PHP 8.2 o superiore
- [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/) attivo: senza ACF
  le API REST restano disattivate
- WooCommerce e WPML sono opzionali, necessari solo per i rispettivi endpoint e per il multilingua

## Documentazione

Per scrivere una integrazione parti da **[docs/DEVELOPER.md](docs/DEVELOPER.md)**: contratto REST,
ordine delle chiamate, esempi completi in PHP e riferimento degli errori.

| Documento | A chi serve |
| --- | --- |
| [docs/DEVELOPER.md](docs/DEVELOPER.md) | chi scrive una integrazione: esempi, ordine delle chiamate, errori |
| [docs/API.md](docs/API.md) | riferimento esaustivo endpoint per endpoint |
| [docs/USER.md](docs/USER.md) | installazione e configurazione lato admin del sito |
| [docs/DEV.md](docs/DEV.md) | interni del plugin, service per service |
| [docs/design.md](docs/design.md) | decisioni architetturali e trade-off |
| [docs/Tech.md](docs/Tech.md) | note tecniche |
| [docs/WooCommerce.md](docs/WooCommerce.md) | specificita' WooCommerce |
| [docs/PAGINATION.md](docs/PAGINATION.md) | paginazione |

## Licenza

[GPL-2.0-or-later](LICENSE), come WordPress.

## Ambiente locale

Il progetto include un ambiente Docker Compose con servizi MySQL, WordPress e Adminer.

### WordPress

Accesso alla webapp:

http://localhost:8040

### Adminer

Accesso al database:

http://localhost:8041/?server=mysql&username=wp_user&db=wordpress
