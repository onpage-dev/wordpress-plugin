<?php

// Braced namespaces as in `MultiLangResolveFields.php`: the WordPress, ACF and
// `onpage_http_exception()` stubs must live in the global namespace, because that is where
// `FieldGroup` looks them up when there is no WordPress around.

namespace {

    use OnPage\Exceptions\HttpException;

    require_once dirname(__DIR__) . '/Exceptions/HttpException.php';

    /** State of the ACF double: the fields the group already holds, and what was written. */
    $GLOBALS['acf_double'] = ['existing' => [], 'saved' => [], 'deleted' => []];

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
        /** Site without WPML: the translation preference is not applied. */
        function onpage_is_wpml_active(): bool
        {
            return false;
        }
    }

    if (!function_exists('acf_get_fields')) {
        function acf_get_fields(int|array $parent): array
        {
            return $GLOBALS['acf_double']['existing'];
        }
    }

    if (!function_exists('acf_update_field')) {
        function acf_update_field(array $field): array
        {
            $GLOBALS['acf_double']['saved'][] = $field['name'];

            return $field + ['ID' => count($GLOBALS['acf_double']['saved']) + 100];
        }
    }

    if (!function_exists('acf_delete_field')) {
        function acf_delete_field(int $field_id): bool
        {
            $GLOBALS['acf_double']['deleted'][] = $field_id;

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
     * `"fields": ["name", "surname"]` instead of a list of objects, a plain client mistake,
     * would then wipe the group's schema and leave the values already written in the posts
     * unreadable by ACF.
     *
     * Needs no WordPress, no site and no `.env`: ACF is replaced by the double above.
     *
     * Run with: php src/Tests/FieldGroupMalformedFields.php
     */
    class FieldGroupMalformedFields
    {
        private const GROUP_ID = 42;

        /** Invokes the private `persistFields()` on a group holding `$existing`. */
        private static function persist(array $existing, array $fields): void
        {
            $GLOBALS['acf_double'] = [
                'existing' => $existing,
                'saved' => [],
                'deleted' => [],
            ];

            $method = new ReflectionMethod(FieldGroup::class, 'persistFields');
            $method->setAccessible(true);
            $method->invoke(null, self::GROUP_ID, $fields);
        }

        /** Two fields already stored in the group, in ACF's read shape. */
        private static function existingFields(): array
        {
            return [
                ['ID' => 11, 'key' => 'field_aaa', 'name' => 'name', 'type' => 'text'],
                ['ID' => 12, 'key' => 'field_bbb', 'name' => 'surname', 'type' => 'text'],
            ];
        }

        /** A malformed payload must be refused with 400, leaving the group untouched. */
        private static function caseMalformedPayload(): array
        {
            $failures = [];

            try {
                self::persist(self::existingFields(), ['name', 'surname']);
                $failures[] = 'malformed payload: no exception, the request went through';
            } catch (HttpException $e) {
                if ($e->status_code !== 400) {
                    $failures[] = "malformed payload: expected status 400, got {$e->status_code}";
                }

                if ($e->error_code !== 'invalid_param') {
                    $failures[] = "malformed payload: expected error_code invalid_param, got {$e->error_code}";
                }
            }

            // The point of the regression: the group must not have been touched.
            if ($GLOBALS['acf_double']['deleted'] !== []) {
                $failures[] = 'malformed payload: fields were deleted: '
                    . implode(', ', $GLOBALS['acf_double']['deleted']);
            }

            if ($GLOBALS['acf_double']['saved'] !== []) {
                $failures[] = 'malformed payload: fields were written: '
                    . implode(', ', $GLOBALS['acf_double']['saved']);
            }

            return $failures;
        }

        /**
         * The other half of the guarantee: a well-formed payload must still write what it
         * carries and delete what it dropped, otherwise the fix above would be a regression
         * of its own.
         */
        private static function caseValidPayload(): array
        {
            $failures = [];

            self::persist(self::existingFields(), [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
            ]);

            if ($GLOBALS['acf_double']['saved'] !== ['name']) {
                $failures[] = 'valid payload: expected [name] to be written, got ['
                    . implode(', ', $GLOBALS['acf_double']['saved']) . ']';
            }

            // `surname` is not in the payload: it must be removed, and it is the field with ID 12.
            if ($GLOBALS['acf_double']['deleted'] !== [12]) {
                $failures[] = 'valid payload: expected field 12 to be deleted, got ['
                    . implode(', ', $GLOBALS['acf_double']['deleted']) . ']';
            }

            return $failures;
        }

        /** Runs every case, returning a shell exit code. */
        public static function run(): int
        {
            $cases = [
                'malformed payload: 400 and group untouched' => self::caseMalformedPayload(...),
                'valid payload: writes and cleans up as before' => self::caseValidPayload(...),
            ];

            $failures = [];

            foreach ($cases as $title => $case) {
                $errors = $case();
                $outcome = $errors === [] ? 'ok' : 'FAILED';
                echo "  [$outcome] $title\n";

                foreach ($errors as $error) {
                    echo "         $error\n";
                }

                $failures = array_merge($failures, $errors);
            }

            $total = count($cases);

            if ($failures !== []) {
                fwrite(STDERR, "\nFAILED: " . count($failures) . " assertion(s) across $total cases\n");

                return 1;
            }

            echo "\nPASSED: $total of $total cases\n";

            return 0;
        }
    }

    if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
        exit(FieldGroupMalformedFields::run());
    }
}
