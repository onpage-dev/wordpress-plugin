<?php

// Braced namespaces for the same reason as MultiLangResolveFields.php: the
// `onpage_get_wpml_languages()` stub must live in the global namespace.

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
     * Offline check for the rule that keeps an existing translation untouched when a
     * language map leaves its language out.
     *
     * On update, `{"title": {"en": "Red Chair"}}` must rewrite the English translation
     * only. Before, the Italian translation resolved the map through the fallback
     * language and got the English title too.
     *
     * Run with: php src/Tests/MultiLangUnsentLanguages.php
     */
    class MultiLangUnsentLanguages
    {
        /** @return array<string, array{bool, bool}> case => [actual, expected] */
        private static function cases(): array
        {
            $fields = [
                'subtitle' => ['en' => 'Subtitle'],
                'notes' => ['it' => 'Note', 'en' => 'Notes'],
                'sku' => 'CHAIR-RED',
                'specs' => ['lat' => 45.1, 'lng' => 9.2],
                'cleared' => ['it' => null],
            ];

            $italian = MultiLang::withoutMapsMissingLanguage($fields, 'it');
            $english = MultiLang::withoutMapsMissingLanguage($fields, 'en');

            return [
                'map without the language' => [MultiLang::isMapWithoutLanguage(['en' => 'Red Chair'], 'it'), true],
                'map with the language' => [MultiLang::isMapWithoutLanguage(['en' => 'Red Chair', 'it' => 'Sedia'], 'it'), false],
                'map clearing the language' => [MultiLang::isMapWithoutLanguage(['it' => null], 'it'), false],
                'map with an inactive language only' => [MultiLang::isMapWithoutLanguage(['es' => 'Silla'], 'it'), true],
                'scalar value' => [MultiLang::isMapWithoutLanguage('Red Chair', 'it'), false],
                'group that is not a map' => [MultiLang::isMapWithoutLanguage(['lat' => 1, 'lng' => 2], 'it'), false],
                'no language to check' => [MultiLang::isMapWithoutLanguage(['en' => 'Red Chair'], null), false],

                'it: drops the English-only field' => [array_key_exists('subtitle', $italian), false],
                'it: keeps the field with both languages' => [array_key_exists('notes', $italian), true],
                'it: keeps the shared scalar' => [array_key_exists('sku', $italian), true],
                'it: keeps the group' => [array_key_exists('specs', $italian), true],
                'it: keeps the explicit clear' => [array_key_exists('cleared', $italian), true],
                'en: keeps the English-only field' => [array_key_exists('subtitle', $english), true],
                'en: drops the Italian-only clear' => [array_key_exists('cleared', $english), false],
                'null fields stay null' => [MultiLang::withoutMapsMissingLanguage(null, 'it') === null, true],
                'no language keeps every field' => [MultiLang::withoutMapsMissingLanguage($fields, null) === $fields, true],

                'split: shared value covers it' => [MultiLang::hasValueForLanguage(MultiLang::splitValueByLanguage('Red Chair'), 'it'), true],
                'split: English-only map skips it' => [MultiLang::hasValueForLanguage(MultiLang::splitValueByLanguage(['en' => 'Red Chair']), 'it'), false],
                'split: English-only map covers en' => [MultiLang::hasValueForLanguage(MultiLang::splitValueByLanguage(['en' => 'Red Chair']), 'en'), true],
                'split: inactive language is not a value for it' => [MultiLang::hasValueForLanguage(MultiLang::splitValueByLanguage(['en' => 'Red Chair', 'es' => 'Silla']), 'it'), false],
            ];
        }

        /** Runs every case and returns the process exit code. */
        public static function run(): int
        {
            echo "test     MultiLang, language maps that leave a language out\n";
            echo "site     none, offline check\n\n";

            $failures = [];
            $cases = self::cases();
            foreach ($cases as $name => [$actual, $expected]) {
                if ($actual === $expected) {
                    echo "  ok       $name\n";
                    continue;
                }

                $failures[] = sprintf('%s: expected %s', $name, $expected ? 'true' : 'false');
                echo "  FAILED   $name\n";
            }

            $total = count($cases);
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
        exit(MultiLangUnsentLanguages::run());
    }
}
