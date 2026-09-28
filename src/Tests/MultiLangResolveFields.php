<?php

// The file uses braced namespaces because the `onpage_get_wpml_languages()` stub must live
// in the global namespace: `MultiLang` calls it unqualified, and without WordPress around
// nothing else defines it.

namespace {

    if (!function_exists('onpage_get_wpml_languages')) {
        /** The languages an it/en WPML site would expose. */
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
         * @return array<string, array{mixed, mixed}> case => [sent value, expected]
         */
        private static function casesToResolve(): array
        {
            return [
                'language map, requested language' => [
                    ['it' => 'Ciao', 'en' => 'Hello'],
                    'Ciao',
                ],
                'language map, fallback only' => [
                    ['en' => 'Hello'],
                    'Hello',
                ],
                'repeater language map' => [
                    ['it' => [['name' => 'CE']], 'en' => [['name' => 'CE marking']]],
                    [['name' => 'CE']],
                ],
                'group language map' => [
                    ['it' => ['title' => 'Scheda'], 'en' => ['title' => 'Datasheet']],
                    ['title' => 'Scheda'],
                ],
                'language map with a language not active on the site' => [
                    ['es' => 'Hola', 'it' => 'Ciao'],
                    'Ciao',
                ],
                'language map with neither the requested nor the fallback language' => [
                    ['es' => 'Hola'],
                    null,
                ],
                'language map that clears the field' => [
                    ['it' => null, 'en' => 'Hello'],
                    null,
                ],
                'language map with a region subtag not active on the site' => [
                    ['pt-br' => 'Olá', 'it' => 'Ciao'],
                    'Ciao',
                ],
            ];
        }

        /**
         * Everything else: these must reach the field write untouched.
         *
         * If the rule regresses, every array here resolves to `null` and the field is
         * silently cleared while the request answers 200.
         *
         * @return array<string, array{mixed, mixed}> case => [sent value, expected]
         */
        private static function casesToLeaveUntouched(): array
        {
            $repeater = [['name' => 'CE marking', 'year' => 2024], ['name' => 'VOC A+']];
            $group = ['title' => 'Datasheet', 'notes' => 'Shared note'];

            return [
                'shared repeater' => [$repeater, $repeater],
                'shared group' => [$group, $group],
                'nested group' => [
                    ['sheet' => ['title' => 'T', 'attachments' => ['a.pdf']]],
                    ['sheet' => ['title' => 'T', 'attachments' => ['a.pdf']]],
                ],
                'multiple checkbox' => [['indoor', 'outdoor'], ['indoor', 'outdoor']],
                'gallery' => [['https://a.jpg', 'https://b.jpg'], ['https://a.jpg', 'https://b.jpg']],
                'relationship, list of ids' => [[12, 34], [12, 34]],
                'empty list' => [[], []],
                'string' => ['CHAIR-RED', 'CHAIR-RED'],
                'integer' => [42, 42],
                'float' => [49.9, 49.9],
                'boolean' => [true, true],
                'null' => [null, null],
                'repeater row with keys that look like languages' => [
                    [['it' => 'value of a sub-field named it']],
                    [['it' => 'value of a sub-field named it']],
                ],
                // Groups whose sub-field names are short or snake_case but are not language
                // codes: before, any 2-3 letter key counted as one and the group was cleared.
                'group with 3-letter sub-fields' => [
                    ['sku' => 'ABC', 'alt' => 'text'],
                    ['sku' => 'ABC', 'alt' => 'text'],
                ],
                'google map group' => [
                    ['lat' => 45.1, 'lng' => 9.2],
                    ['lat' => 45.1, 'lng' => 9.2],
                ],
                'group with snake_case sub-fields' => [
                    ['cta_url' => 'https://example.com', 'cta_text' => 'Buy'],
                    ['cta_url' => 'https://example.com', 'cta_text' => 'Buy'],
                ],
                'group mixing a language-like key with another key' => [
                    ['id' => 5, 'url' => 'https://example.com'],
                    ['id' => 5, 'url' => 'https://example.com'],
                ],
            ];
        }

        /** Runs one group of cases, returning the failures it found. */
        private static function runGroup(string $title, array $cases): array
        {
            $failures = [];

            echo "\n$title\n";
            foreach ($cases as $name => [$sent, $expected]) {
                $resolved = MultiLang::resolveFields(['field' => $sent], self::LANG, self::FALLBACK)['field'];

                if ($resolved === $expected) {
                    echo "  ok       $name\n";
                    continue;
                }

                $failures[] = sprintf(
                    '%s: expected %s, got %s',
                    $name,
                    json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
                echo "  FAILED   $name\n";
            }

            return $failures;
        }

        /** Runs every case and returns the process exit code. */
        public static function run(): int
        {
            echo "test     MultiLang::resolveFields, language maps versus shared values\n";
            echo "site     none, offline check\n";

            $failures = array_merge(
                self::runGroup('values to resolve per language', self::casesToResolve()),
                self::runGroup('values that must reach the write untouched', self::casesToLeaveUntouched())
            );

            $total = count(self::casesToResolve())
                + count(self::casesToLeaveUntouched());

            if ($failures !== []) {
                fwrite(STDERR, "\nFAILED: " . count($failures) . " of $total case(s)\n");
                foreach ($failures as $failure) {
                    fwrite(STDERR, "  - $failure\n");
                }

                return 1;
            }

            echo "\nPASSED: $total of $total cases\n";

            return 0;
        }
    }

    if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
        exit(MultiLangResolveFields::run());
    }
}
