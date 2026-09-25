<?php

// Il file usa i namespace con le graffe perche' lo stub di `onpage_get_wpml_languages()` deve
// stare nel namespace globale: `MultiLang` lo chiama senza qualificarlo, e senza
// WordPress intorno non esiste nessuno che lo definisca.

namespace {

    if (!function_exists('onpage_get_wpml_languages')) {
        /** Le lingue che un sito WPML it/en esporrebbe. */
        function onpage_get_wpml_languages(): array
        {
            return ['it', 'en'];
        }
    }
}

namespace OnPage\Tests {

    use OnPage\Services\MultiLang;

    require_once dirname(__DIR__) . '/Services/MultiLang.php';

    /**
     * Offline check for `MultiLang::resolveFields()`, the single rule that decides what
     * is a language map and what is a plain value.
     *
     * Needs no WordPress, no site and no `.env`: the code paths under test touch neither
     * WPML nor the database. It is the companion of `AcfSharedStructuredFields.php`,
     * which exercises the same rule end to end against a real site, and covers here the
     * shapes and edge cases that would need a field type per case over there.
     *
     * Run with: php src/Tests/MultiLangResolveFields.php
     */
    class MultiLangResolveFields
    {
        private const LANG = 'it';
        private const FALLBACK = 'en';

        /**
         * Language maps: these must be unwrapped for the requested language.
         *
         * @return array<string, array{mixed, mixed}> caso => [valore inviato, atteso]
         */
        private static function casiDaRisolvere(): array
        {
            return [
                'mappa lingua, lingua richiesta' => [
                    ['it' => 'Ciao', 'en' => 'Hello'],
                    'Ciao',
                ],
                'mappa lingua, solo fallback' => [
                    ['en' => 'Hello'],
                    'Hello',
                ],
                'mappa lingua di repeater' => [
                    ['it' => [['nome' => 'CE']], 'en' => [['nome' => 'CE marking']]],
                    [['nome' => 'CE']],
                ],
                'mappa lingua di group' => [
                    ['it' => ['titolo' => 'Scheda'], 'en' => ['titolo' => 'Datasheet']],
                    ['titolo' => 'Scheda'],
                ],
                'mappa lingua con lingua non attiva sul sito' => [
                    ['es' => 'Hola', 'it' => 'Ciao'],
                    'Ciao',
                ],
                'mappa lingua senza la lingua richiesta ne la fallback' => [
                    ['es' => 'Hola'],
                    null,
                ],
                'mappa lingua che svuota il campo' => [
                    ['it' => null, 'en' => 'Hello'],
                    null,
                ],
            ];
        }

        /**
         * Everything else: these must reach the field write untouched.
         *
         * If the rule regresses, every array here resolves to `null` and the field is
         * silently cleared while the request answers 200.
         *
         * @return array<string, array{mixed, mixed}> caso => [valore inviato, atteso]
         */
        private static function casiDaLasciareIntatti(): array
        {
            $repeater = [['nome' => 'Marcatura CE', 'anno' => 2024], ['nome' => 'VOC A+']];
            $group = ['titolo' => 'Scheda tecnica', 'note' => 'Nota condivisa'];

            return [
                'repeater condiviso' => [$repeater, $repeater],
                'group condiviso' => [$group, $group],
                'group annidato' => [
                    ['scheda' => ['titolo' => 'T', 'allegati' => ['a.pdf']]],
                    ['scheda' => ['titolo' => 'T', 'allegati' => ['a.pdf']]],
                ],
                'checkbox multiplo' => [['interno', 'esterno'], ['interno', 'esterno']],
                'gallery' => [['https://a.jpg', 'https://b.jpg'], ['https://a.jpg', 'https://b.jpg']],
                'relazione, lista di id' => [[12, 34], [12, 34]],
                'lista vuota' => [[], []],
                'stringa' => ['CHAIR-RED', 'CHAIR-RED'],
                'intero' => [42, 42],
                'decimale' => [49.9, 49.9],
                'booleano' => [true, true],
                'null' => [null, null],
                'riga di repeater con chiavi che sembrano lingue' => [
                    [['it' => 'valore di un sub-campo che si chiama it']],
                    [['it' => 'valore di un sub-campo che si chiama it']],
                ],
            ];
        }

        /**
         * Current behaviour that is not the desired one, pinned down on purpose.
         *
         * `isLanguageMapShape()` decides by shape alone, and a language code is any key
         * of two or three letters, so a group whose sub-fields happen to be named that
         * way is mistaken for a language map and resolved to `null`. Changing the rule
         * is a real trade-off — requiring an active WPML language among the keys would
         * make a map carrying only languages the site has not activated be written raw
         * into the field instead of clearing it — so the behaviour is recorded here
         * rather than asserted as correct. A change to the predicate must make this case
         * fail, so that it is decided and not slipped in.
         *
         * @return array<string, array{mixed, mixed}> caso => [valore inviato, atteso]
         */
        private static function casiDaComportamentoAttuale(): array
        {
            return [
                'group con sotto-campi di 2-3 lettere, scambiato per mappa lingua' => [
                    ['sku' => 'ABC', 'alt' => 'testo'],
                    null,
                ],
            ];
        }

        /** Runs one group of cases, returning the failures it found. */
        private static function eseguiGruppo(string $titolo, array $casi): array
        {
            $falliti = [];

            echo "\n$titolo\n";
            foreach ($casi as $nome => [$inviato, $atteso]) {
                $risolto = MultiLang::resolveFields(['campo' => $inviato], self::LANG, self::FALLBACK)['campo'];

                if ($risolto === $atteso) {
                    echo "  ok       $nome\n";
                    continue;
                }

                $falliti[] = sprintf(
                    '%s: atteso %s, ottenuto %s',
                    $nome,
                    json_encode($atteso, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($risolto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
                echo "  FALLITO  $nome\n";
            }

            return $falliti;
        }

        /** Runs every case and returns the process exit code. */
        public static function run(): int
        {
            echo "test     MultiLang::resolveFields, mappe lingua contro valori condivisi\n";
            echo "sito     nessuno, controllo offline\n";

            $falliti = array_merge(
                self::eseguiGruppo('valori da risolvere per lingua', self::casiDaRisolvere()),
                self::eseguiGruppo('valori che devono arrivare intatti alla scrittura', self::casiDaLasciareIntatti()),
                self::eseguiGruppo('comportamento attuale, non quello desiderato', self::casiDaComportamentoAttuale())
            );

            $totale = count(self::casiDaRisolvere())
                + count(self::casiDaLasciareIntatti())
                + count(self::casiDaComportamentoAttuale());

            if ($falliti !== []) {
                fwrite(STDERR, "\nFALLITO: " . count($falliti) . " caso/i su $totale\n");
                foreach ($falliti as $fallito) {
                    fwrite(STDERR, "  - $fallito\n");
                }

                return 1;
            }

            echo "\nPASSATO: $totale casi su $totale\n";

            return 0;
        }
    }

    if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
        exit(MultiLangResolveFields::run());
    }
}
