<?php

// Namespace con le graffe come in `MultiLangResolveFields.php`: gli stub di WordPress,
// di ACF e di `onpage_http_exception()` devono stare nel namespace globale, perche' e' li' che
// `FieldGroup` li cerca quando non c'e' nessun WordPress intorno.

namespace {

    use OnPage\Exceptions\HttpException;

    require_once dirname(__DIR__) . '/Exceptions/HttpException.php';

    /** Stato del doppio di ACF: i campi che il gruppo gia' contiene, e cosa e' stato scritto. */
    $GLOBALS['acf_double'] = ['esistenti' => [], 'salvati' => [], 'cancellati' => []];

    if (!function_exists('sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
        }
    }

    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(string $value): string
        {
            return trim(strip_tags($value));
        }
    }

    if (!function_exists('wp_generate_uuid4')) {
        function wp_generate_uuid4(): string
        {
            static $n = 0;

            return sprintf('0000-0000-0000-%04d', ++$n);
        }
    }

    if (!function_exists('onpage_is_wpml_active')) {
        /** Sito senza WPML: la preferenza di traduzione non viene applicata. */
        function onpage_is_wpml_active(): bool
        {
            return false;
        }
    }

    if (!function_exists('acf_get_fields')) {
        function acf_get_fields(int|array $parent): array
        {
            return $GLOBALS['acf_double']['esistenti'];
        }
    }

    if (!function_exists('acf_update_field')) {
        function acf_update_field(array $field): array
        {
            $GLOBALS['acf_double']['salvati'][] = $field['name'];

            return $field + ['ID' => count($GLOBALS['acf_double']['salvati']) + 100];
        }
    }

    if (!function_exists('acf_delete_field')) {
        function acf_delete_field(int $field_id): bool
        {
            $GLOBALS['acf_double']['cancellati'][] = $field_id;

            return true;
        }
    }

    if (!function_exists('onpage_http_exception')) {
        function onpage_http_exception(string $message = '', int $status_code = 500, string $error_code = 'onpage_api_error'): HttpException
        {
            return new HttpException($message, $status_code, $error_code);
        }
    }
}

namespace OnPage\Tests {

    use OnPage\Exceptions\HttpException;
    use OnPage\Services\FieldGroup;
    use ReflectionMethod;

    require_once dirname(__DIR__) . '/Services/FieldGroup.php';

    /**
     * Offline check for `FieldGroup::persistFields()`: a malformed `fields` payload must
     * never reach the cleanup pass.
     *
     * The regression this pins down: if entries that are not arrays were skipped in
     * silence, a skipped entry would never land in `$saved_field_names`, and the cleanup
     * at the end of the method would read every existing field as "dropped from the
     * payload" and delete it — answering `200`. Sending
     * `"fields": ["nome", "cognome"]` instead of a list of objects, a plain client mistake,
     * would then wipe the group's schema and leave the values already written in the posts
     * unreadable by ACF.
     *
     * Needs no WordPress, no site and no `.env`: ACF is replaced by the double above.
     *
     * Run with: php src/Tests/FieldGroupMalformedFields.php
     */
    class FieldGroupMalformedFields
    {
        private const GRUPPO_ID = 42;

        /** Invokes the private `persistFields()` on a group holding `$esistenti`. */
        private static function persisti(array $esistenti, array $fields): void
        {
            $GLOBALS['acf_double'] = [
                'esistenti' => $esistenti,
                'salvati' => [],
                'cancellati' => [],
            ];

            $metodo = new ReflectionMethod(FieldGroup::class, 'persistFields');
            $metodo->setAccessible(true);
            $metodo->invoke(null, self::GRUPPO_ID, $fields);
        }

        /** Two fields already stored in the group, in ACF's read shape. */
        private static function campiEsistenti(): array
        {
            return [
                ['ID' => 11, 'key' => 'field_aaa', 'name' => 'nome', 'type' => 'text'],
                ['ID' => 12, 'key' => 'field_bbb', 'name' => 'cognome', 'type' => 'text'],
            ];
        }

        /** A malformed payload must be refused with 400, leaving the group untouched. */
        private static function casoPayloadMalformato(): array
        {
            $falliti = [];

            try {
                self::persisti(self::campiEsistenti(), ['nome', 'cognome']);
                $falliti[] = 'payload malformato: nessuna eccezione, la richiesta e\' passata';
            } catch (HttpException $e) {
                if ($e->status_code !== 400) {
                    $falliti[] = "payload malformato: atteso status 400, ricevuto {$e->status_code}";
                }

                if ($e->error_code !== 'invalid_param') {
                    $falliti[] = "payload malformato: atteso error_code invalid_param, ricevuto {$e->error_code}";
                }
            }

            // Il punto della regressione: il gruppo non deve essere stato toccato.
            if ($GLOBALS['acf_double']['cancellati'] !== []) {
                $falliti[] = 'payload malformato: sono stati cancellati i campi '
                    . implode(', ', $GLOBALS['acf_double']['cancellati']);
            }

            if ($GLOBALS['acf_double']['salvati'] !== []) {
                $falliti[] = 'payload malformato: sono stati scritti i campi '
                    . implode(', ', $GLOBALS['acf_double']['salvati']);
            }

            return $falliti;
        }

        /**
         * The other half of the guarantee: a well-formed payload must still write what it
         * carries and delete what it dropped, otherwise the fix above would be a regression
         * of its own.
         */
        private static function casoPayloadValido(): array
        {
            $falliti = [];

            self::persisti(self::campiEsistenti(), [
                ['key' => 'nome', 'label' => 'Nome', 'type' => 'text'],
            ]);

            if ($GLOBALS['acf_double']['salvati'] !== ['nome']) {
                $falliti[] = 'payload valido: attesa la scrittura di [nome], ricevuto ['
                    . implode(', ', $GLOBALS['acf_double']['salvati']) . ']';
            }

            // `cognome` non e' nel payload: va rimosso, ed e' il campo con ID 12.
            if ($GLOBALS['acf_double']['cancellati'] !== [12]) {
                $falliti[] = 'payload valido: atteso il campo 12 cancellato, ricevuto ['
                    . implode(', ', $GLOBALS['acf_double']['cancellati']) . ']';
            }

            return $falliti;
        }

        /** Runs every case, returning a shell exit code. */
        public static function run(): int
        {
            $casi = [
                'payload malformato: 400 e gruppo intatto' => self::casoPayloadMalformato(...),
                'payload valido: scrive e ripulisce come prima' => self::casoPayloadValido(...),
            ];

            $falliti = [];

            foreach ($casi as $titolo => $caso) {
                $errori = $caso();
                $esito = $errori === [] ? 'ok' : 'FALLITO';
                echo "  [$esito] $titolo\n";

                foreach ($errori as $errore) {
                    echo "         $errore\n";
                }

                $falliti = array_merge($falliti, $errori);
            }

            $totale = count($casi);

            if ($falliti !== []) {
                fwrite(STDERR, "\nFALLITO: " . count($falliti) . " asserzione/i su $totale casi\n");

                return 1;
            }

            echo "\nPASSATO: $totale casi su $totale\n";

            return 0;
        }
    }

    if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
        exit(FieldGroupMalformedFields::run());
    }
}
