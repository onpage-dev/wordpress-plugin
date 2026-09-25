# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-25

### Added
- API REST sotto `/wp-json/onpage/v1` per ricevere i dati strutturati di On Page® tramite Exporter, con autenticazione a token generato dalla pagina impostazioni del plugin.
- Creazione e aggiornamento di Post Type, field group ACF, tassonomie, termini, post e media, con upsert idempotente per `local_key`.
- Integrazione WooCommerce: prodotti, varianti, attributi globali e relativi termini, categorie, tag e brand.
- Integrazione WPML per i progetti multilingua, con valori per lingua espressi come mappe `{"<lang>": …}`.
- Import dei media da URL con deduplica sul segmento di storage On Page®.
- `POST /migration` per adeguare i dati di un sito che usava un'installazione precedente del plugin.
- Requisiti: WordPress 7.1, PHP 8.2 e Advanced Custom Fields attivo; senza ACF il plugin non registra le rotte e lo segnala in amministrazione.

[1.0.0]: https://github.com/onpage-dev/wordpress-plugin/releases/tag/v1.0.0
