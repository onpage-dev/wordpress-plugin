<?php

// Braced namespaces as in `FieldGroupMalformedFields.php`: the WordPress, ACF and
// `onpage_http_exception()` stubs must live in the global namespace, and the `RemoteMedia`
// double in `OnPage\Services`, because that is where `Acf` looks them up when there is no
// WordPress around.

namespace {

    use OnPage\Exceptions\HttpException;

    require_once dirname(__DIR__) . '/Exceptions/HttpException.php';

    /**
     * State of the doubles: the attachments that exist, what was imported, linked and
     * written. Reset before every case.
     */
    $GLOBALS['acf_gallery_double'] = [];

    if (!function_exists('sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
        }
    }

    if (!function_exists('acf_get_field_groups')) {
        /** One field group, shown on the post type `onpage_test`. */
        function acf_get_field_groups(): array
        {
            return [[
                'ID' => 7,
                'key' => 'group_onpage_test_gallery',
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'onpage_test']]],
            ]];
        }
    }

    if (!function_exists('acf_get_fields')) {
        function acf_get_fields(array $field_group): array
        {
            $gallery = fn(string $name): array => ['key' => "field_$name", 'name' => $name, 'type' => 'gallery'];

            return [
                $gallery('photos'),
                ['key' => 'field_cover', 'name' => 'cover', 'type' => 'image'],
                [
                    'key' => 'field_rows',
                    'name' => 'rows',
                    'type' => 'repeater',
                    'sub_fields' => [
                        ['key' => 'field_rows_title', 'name' => 'title', 'type' => 'text'],
                        $gallery('shots'),
                    ],
                ],
                [
                    'key' => 'field_sheet',
                    'name' => 'sheet',
                    'type' => 'group',
                    'sub_fields' => [$gallery('shots')],
                ],
            ];
        }
    }

    if (!function_exists('acf_update_value')) {
        function acf_update_value(mixed $value, int|string $object_id, array $field): bool
        {
            $GLOBALS['acf_gallery_double']['written'][$field['name']] = $value;

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

namespace OnPage\Services {

    /**
     * Double of `RemoteMedia`: a URL is "imported" as attachment 500, 501, … (the same URL
     * twice gives the same ID, as the real deduplication does), and only the attachments in
     * `existing` or imported count as existing.
     */
    class RemoteMedia
    {
        public static function sanitizeUrl(mixed $value): string|null
        {
            return is_string($value) && filter_var(trim($value), FILTER_VALIDATE_URL) ? trim($value) : null;
        }

        public static function isAttachmentId(int $attachment_id): bool
        {
            return in_array($attachment_id, $GLOBALS['acf_gallery_double']['existing'], true)
                || in_array($attachment_id, $GLOBALS['acf_gallery_double']['imported'], true);
        }

        public static function linkMediaToPost(int $attachment_id, int $post_id): array
        {
            $GLOBALS['acf_gallery_double']['linked'][] = [$attachment_id, $post_id];

            return ['attachment_id' => $attachment_id];
        }

        public static function urlToMediaLibrary(string $url, string $field_key, ?int $timeout_seconds = null, bool $force_refresh = false): array
        {
            $double = &$GLOBALS['acf_gallery_double'];
            $double['import_keys'][] = $field_key;
            $double['imported'][$url] ??= 500 + count($double['imported']);

            return ['attachment_id' => $double['imported'][$url]];
        }

        public static function urlToPost(string $url, int $post_id, string $field_key, ?int $timeout_seconds = null): array
        {
            $media = self::urlToMediaLibrary($url, $field_key, $timeout_seconds);

            return self::linkMediaToPost($media['attachment_id'], $post_id);
        }
    }
}

namespace OnPage\Tests {

    use OnPage\Exceptions\HttpException;
    use OnPage\Services\Acf;

    require_once dirname(__DIR__) . '/Services/Acf.php';

    /**
     * Offline check for ACF `gallery` values in `acf_fields` (`Acf::updateFieldValue()`).
     *
     * A gallery takes a list of remote URLs and/or attachment IDs. Every item must be
     * resolved like an `image` value, so the field stores attachment IDs in the order sent,
     * and an item that cannot be resolved must answer `400 input_invalid` naming its
     * position, without writing the field. Before, a gallery was written as sent, so a list
     * of URLs reached ACF as text and the field stayed empty.
     *
     * Needs no WordPress, no site and no `.env`: ACF and `RemoteMedia` are replaced by the
     * doubles above.
     *
     * Run with: php src/Tests/AcfGalleryValues.php
     */
    class AcfGalleryValues
    {
        private const POST_ID = 42;
        private const POST_TYPE = 'onpage_test';
        private const URL_A = 'https://cdn.example.com/a.jpg';
        private const URL_B = 'https://cdn.example.com/b.jpg';

        /** Attachments that already exist on the site. */
        private const EXISTING = [812, 813];

        /** Writes one field and returns the error, or null when it was written. */
        private static function write(string $field, mixed $value): ?HttpException
        {
            $GLOBALS['acf_gallery_double'] = [
                'existing' => self::EXISTING,
                'imported' => [],
                'import_keys' => [],
                'linked' => [],
                'written' => [],
            ];

            try {
                Acf::updateFieldValue(self::POST_ID, $field, $value, 'post', self::POST_TYPE);
            } catch (HttpException $exception) {
                return $exception;
            }

            return null;
        }

        private static function written(string $field): mixed
        {
            $written = $GLOBALS['acf_gallery_double']['written'];

            return array_key_exists($field, $written) ? $written[$field] : '(not written)';
        }

        /** @return list<string> */
        private static function expectWritten(string $field, mixed $value, mixed $expected): array
        {
            $error = self::write($field, $value);
            if ($error !== null) {
                return ["$field: unexpected {$error->status_code} {$error->error_code}: {$error->getMessage()}"];
            }

            $written = self::written($field);

            return $written === $expected
                ? []
                : ["$field: expected " . json_encode($expected, JSON_UNESCAPED_SLASHES) . ', got ' . json_encode($written, JSON_UNESCAPED_SLASHES)];
        }

        /** @return list<string> */
        private static function expectRejected(string $field, mixed $value, string $path): array
        {
            $error = self::write($field, $value);
            if ($error === null) {
                return ["$field: expected 400 input_invalid, got written " . json_encode(self::written($field), JSON_UNESCAPED_SLASHES)];
            }

            $errors = [];
            $message = "Field '$path' in acf_fields must be an existing attachment ID or a valid URL";
            if ($error->status_code !== 400 || $error->error_code !== 'input_invalid' || $error->getMessage() !== $message) {
                $errors[] = "$field: expected 400 input_invalid \"$message\", got {$error->status_code} {$error->error_code} \"{$error->getMessage()}\"";
            }
            if (self::written($field) !== '(not written)') {
                $errors[] = "$field: written although an item was rejected";
            }

            return $errors;
        }



        /** URLs are imported, IDs checked, the order kept; every attachment ends up on the post. */
        private static function caseUrlsAndIds(): array
        {
            $errors = self::expectWritten('photos', [self::URL_A, 813, '812', self::URL_B], [500, 813, 812, 501]);

            $double = $GLOBALS['acf_gallery_double'];
            $linked = array_map(fn(array $link) => $link[0], array_filter($double['linked'], fn(array $link) => $link[1] === self::POST_ID));
            sort($linked);
            if ($linked !== [500, 501, 812, 813]) {
                $errors[] = 'photos: attachments linked to the post ' . json_encode($linked) . ', expected [500,501,812,813]';
            }
            if ($double['import_keys'] !== ['photos', 'photos']) {
                $errors[] = 'photos: files imported under ' . json_encode($double['import_keys']) . ', expected the field name';
            }

            return $errors;
        }

        /** The same URL twice is imported once, and both items point at it. */
        private static function caseSameUrlTwice(): array
        {
            return self::expectWritten('photos', [self::URL_A, self::URL_A], [500, 500]);
        }

        /** `[]` clears the gallery; `null` and `""` pass through as before. */
        private static function caseClear(): array
        {
            return array_merge(
                self::expectWritten('photos', [], []),
                self::expectWritten('photos', null, null),
                self::expectWritten('photos', '', ''),
            );
        }

        /** Items that are not an existing attachment nor a URL: 400 with their position. */
        private static function caseInvalidItems(): array
        {
            return array_merge(
                self::expectRejected('photos', [self::URL_A, 'not a url'], 'photos[1]'),
                self::expectRejected('photos', [999], 'photos[0]'),
                self::expectRejected('photos', [812, 0], 'photos[1]'),
                self::expectRejected('photos', ['  '], 'photos[0]'),
                self::expectRejected('photos', [812, null], 'photos[1]'),
                self::expectRejected('photos', [['url' => self::URL_A]], 'photos[0]'),
            );
        }

        /** A value that is not a list is still passed to ACF unchanged. */
        private static function caseNotAList(): array
        {
            return array_merge(
                self::expectWritten('photos', '812,813', '812,813'),
                self::expectWritten('photos', ['a' => 812], ['a' => 812]),
            );
        }

        /** Gallery sub-fields of a repeater row and of a group are resolved the same way. */
        private static function caseSubFields(): array
        {
            return array_merge(
                self::expectWritten(
                    'rows',
                    [['title' => 'One', 'shots' => [self::URL_A, 813]], ['title' => 'Two', 'shots' => []]],
                    [['title' => 'One', 'shots' => [500, 813]], ['title' => 'Two', 'shots' => []]]
                ),
                self::expectRejected('rows', [['title' => 'One', 'shots' => [812, 'nope']]], 'rows[0][shots][1]'),
                self::expectWritten('sheet', ['shots' => [self::URL_B]], ['shots' => [500]]),
                self::expectRejected('sheet', ['shots' => [0]], 'sheet[shots][0]'),
            );
        }

        /** `image` fields behave as before. */
        private static function caseImageUnchanged(): array
        {
            return array_merge(
                self::expectWritten('cover', self::URL_A, 500),
                self::expectWritten('cover', 0, 0),
                self::expectRejected('cover', 'not a url', 'cover'),
            );
        }



        /** Runs every case, returning a shell exit code. */
        public static function run(): int
        {
            $cases = [
                'URLs and IDs: imported, checked, order kept, linked to the post' => self::caseUrlsAndIds(...),
                'same URL twice: one import' => self::caseSameUrlTwice(...),
                'clearing: [], null and "" written as sent' => self::caseClear(...),
                'invalid items: 400 input_invalid with the position, nothing written' => self::caseInvalidItems(...),
                'not a list: passed to ACF unchanged' => self::caseNotAList(...),
                'repeater and group sub-fields' => self::caseSubFields(...),
                'image fields unchanged' => self::caseImageUnchanged(...),
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
        exit(AcfGalleryValues::run());
    }
}
