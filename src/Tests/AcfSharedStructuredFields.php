<?php



namespace OnPage\Tests;



use OnPage\Env;
use OnPage\Tests\Support\Audit;
use OnPage\Tests\Support\Keep;



require_once dirname(__DIR__) . '/Env.php';
require_once __DIR__ . '/Support/Audit.php';
require_once __DIR__ . '/Support/Keep.php';



/**
 * End-to-end check for structured ACF values sent as shared on `POST /posts`.
 *
 * A repeater, a group or a multi-checkbox sent as a plain list/object — that is, not
 * wrapped in a `{"<lang>": …}` map — used to reach the field write as `null` and clear
 * the field while the request still answered `200`, so the data was lost in silence.
 * This test writes one post with exactly those values, reads it back through
 * `GET /posts/{id}` and asserts every value survived the round trip.
 *
 * Create-and-delete flow: the post type, the ACF field group and the post are created
 * here and removed again at the end, including when an assertion fails. Every teardown
 * call uses `?ignore=1`, so a leftover from an interrupted run never masks a real
 * failure; the same teardown also runs before the fixture is built. With
 * `ONPAGE_TEST_KEEP=1` the final teardown is skipped and the data stays on the site (see
 * `Support/Keep.php`).
 *
 * Configuration comes from the plugin `.env` (see `.env.example`):
 *
 *   WP_TEST_URL     base URL of the test WordPress site, e.g. http://localhost:8040
 *   WP_TEST_TOKEN   Bearer token, the same value stored in the `onpage_auth_token` option
 *
 * Run with: php src/Tests/AcfSharedStructuredFields.php
 */
class AcfSharedStructuredFields
{
    private const POST_TYPE = 'onpage_test_acf';
    private const FIELD_GROUP_TITLE = 'On Page Test :: shared ACF';
    private const FIELD_GROUP_KEY = 'group_onpage_test_acf';
    private const LOCAL_KEY = 'onpage-test-acf-shared';

    private string $base_url;
    private string $token;

    /** @var list<string> Assertion failures collected during the run. */
    private array $failures = [];



    private function __construct(string $base_url, string $token)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->token = $token;
    }



    /**
     * The structured ACF values under test, each sent as a shared value.
     *
     * None of these is a language map: that is the whole point of the test.
     *
     * The `group` covers the one shape the others do not, an associative object rather
     * than a list, and it doubles as the regression test for the sub-field persistence:
     * if it breaks, a `group` created through `POST /field-groups` reads back with every
     * sub-field collapsed onto an empty key.
     */
    private static function sharedFieldValues(): array
    {
        return [
            'certifications' => [
                ['name' => 'CE marking', 'year' => '2024'],
                ['name' => 'VOC A+', 'year' => '2023'],
            ],
            'datasheet' => [
                'title' => 'Technical datasheet',
                'note' => 'Shared note',
            ],
            'labels' => ['indoor', 'outdoor'],
        ];
    }

    /** The ACF field group backing the values above. */
    private static function fieldGroupPayload(): array
    {
        return [
            'title' => self::FIELD_GROUP_TITLE,
            'key' => self::FIELD_GROUP_KEY,
            'description' => 'Fixture of test src/Tests/AcfSharedStructuredFields.php',
            'locations' => [
                ['param' => 'post_type', 'operator' => '==', 'value' => self::POST_TYPE],
            ],
            'fields' => [
                [
                    'key' => 'certifications',
                    'name' => 'certifications',
                    'label' => 'Certifications',
                    'type' => 'repeater',
                    'sub_fields' => [
                        ['key' => 'name', 'name' => 'name', 'label' => 'Name', 'type' => 'text'],
                        ['key' => 'year', 'name' => 'year', 'label' => 'Year', 'type' => 'text'],
                    ],
                ],
                [
                    'key' => 'datasheet',
                    'name' => 'datasheet',
                    'label' => 'Datasheet',
                    'type' => 'group',
                    'sub_fields' => [
                        ['key' => 'title', 'name' => 'title', 'label' => 'Title', 'type' => 'text'],
                        ['key' => 'note', 'name' => 'note', 'label' => 'Note', 'type' => 'text'],
                    ],
                ],
                [
                    'key' => 'labels',
                    'name' => 'labels',
                    'label' => 'Labels',
                    'type' => 'checkbox',
                    'choices' => ['indoor' => 'Indoor', 'outdoor' => 'Outdoor'],
                ],
            ],
        ];
    }



    /**
     * Performs one authenticated REST call against the test site.
     *
     * Every call, teardown included, is mirrored into the audit log: the point of that
     * file is to show the whole conversation, and a cleanup that answered 500 is often
     * the thing that explains the run.
     *
     * @return array{status: int, body: mixed} Decoded response, `body` null when not JSON.
     */
    private function request(string $method, string $path, mixed $body = null, string $query = ''): array
    {
        $url = $this->base_url . '/wp-json/onpage/v1' . $path . ($query === '' ? '' : '?' . $query);

        $handle = curl_init($url);
        $headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
        $payload = $body === null
            ? null
            : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 60);

        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

        Audit::request($method, $url, $payload);

        $started = microtime(true);
        $response = curl_exec($handle);
        $elapsed = microtime(true) - $started;
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            Audit::failure($error, $elapsed);

            throw new \RuntimeException("$method $url unreachable: $error");
        }

        Audit::response($status, (string) $response, $elapsed);

        return ['status' => $status, 'body' => json_decode((string) $response, true)];
    }

    /**
     * Performs a call that must answer `200`.
     *
     * Failures are raised, never `exit()`ed: PHP skips `finally` on exit, and skipping
     * it here would leave the fixture behind on the site the run was pointed at.
     *
     * @throws \RuntimeException When the endpoint answers anything but `200`.
     */
    private function requireOk(string $method, string $path, mixed $body = null, string $query = ''): mixed
    {
        $response = $this->request($method, $path, $body, $query);
        if ($response['status'] !== 200) {
            throw new \RuntimeException(
                "$method $path answered {$response['status']}: "
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $response['body'];
    }



    /** Creates the post type and the ACF field group the test writes into. */
    private function createFixture(): void
    {
        $this->requireOk('POST', '/post-types', [[
            'post_type' => self::POST_TYPE,
            'singular_label' => 'On Page Test',
            'plural_label' => 'On Page Test',
            'supports' => ['title', 'editor'],
        ]]);

        $this->requireOk('POST', '/field-groups', [self::fieldGroupPayload()]);

        echo "  fixture  post type '" . self::POST_TYPE . "' and field group created\n";
    }

    /** Creates the post carrying the shared structured values and returns its WordPress ID. */
    private function createPost(): int
    {
        $ids = $this->requireOk('POST', '/posts', [[
            'type' => self::POST_TYPE,
            'title' => 'On Page Test :: shared structured values',
            'status' => 'draft',
            'local_key' => self::LOCAL_KEY,
            'acf_fields' => self::sharedFieldValues(),
        ]]);

        $post_id = is_array($ids) ? (int) ($ids[0] ?? 0) : 0;
        if ($post_id <= 0) {
            throw new \RuntimeException('POST /posts did not return an id: ' . json_encode($ids));
        }

        echo "  post     created with id $post_id\n";

        return $post_id;
    }

    /** Reads the post back and asserts every shared value survived the round trip. */
    private function assertFieldsPersisted(int $post_id): void
    {
        $post = $this->requireOk('GET', "/posts/$post_id");
        $acf_fields = is_array($post) && is_array($post['acf_fields'] ?? null) ? $post['acf_fields'] : [];

        foreach (self::sharedFieldValues() as $field_key => $expected) {
            $stored = $acf_fields[$field_key] ?? null;

            if (self::normalize($stored) === self::normalize($expected)) {
                echo "  ok       $field_key\n";
                continue;
            }

            $this->failures[] = sprintf(
                "%s: expected %s, got %s",
                $field_key,
                json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }
    }

    /**
     * Checks the site answers and accepts the token, before anything gets created.
     *
     * Without it a wrong URL or token would surface as three teardown warnings before
     * the first real error, instead of one line saying what is actually wrong.
     *
     * @throws \RuntimeException When the site is unreachable or rejects the token.
     */
    private function preflight(): void
    {
        $response = $this->request('GET', '/post-types');
        if ($response['status'] !== 200) {
            throw new \RuntimeException(
                "the site answered {$response['status']} on GET /post-types: "
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ' (wrong WP_TEST_URL or WP_TEST_TOKEN?)'
            );
        }
    }

    /**
     * Removes the post, the field group and the post type, in that order.
     *
     * `?ignore=1` everywhere, and transport errors are downgraded to a warning: teardown
     * runs from a `finally`, so anything it threw would replace the failure the run is
     * already reporting with a less useful one.
     */
    private function teardown(): void
    {
        $calls = [
            ['/posts', [['local_key' => self::LOCAL_KEY, 'type' => self::POST_TYPE]]],
            ['/field-groups', [self::FIELD_GROUP_TITLE]],
            ['/post-types', [self::POST_TYPE]],
        ];

        foreach ($calls as [$path, $body]) {
            try {
                $this->request('DELETE', $path, $body, 'ignore=1');
            } catch (\RuntimeException $exception) {
                fwrite(STDERR, "  warning  cleanup of $path failed: {$exception->getMessage()}\n");
            }
        }
    }



    /**
     * Flattens scalars to strings before comparing.
     *
     * ACF normalises types on the way back — a number can return as a string, a `text`
     * sub-field always does — so a strict comparison against the submitted payload
     * would report type noise instead of the thing under test, which is whether the
     * value reached the field at all.
     */
    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'normalize'], $value);
        }

        if ($value === null || $value === false) {
            return '';
        }

        return is_bool($value) ? '1' : (string) $value;
    }

    /**
     * Prints the message on stderr and stops the run with a failing exit code.
     *
     * Only for pre-flight problems, before anything has been created on the site.
     */
    private static function fatal(string $message): never
    {
        fwrite(STDERR, "ERROR: $message\n");
        exit(1);
    }



    /** Builds the runner from `.env`, refusing to start without a target site. */
    public static function fromEnv(): self
    {
        if (!function_exists('curl_init')) {
            self::fatal("the PHP curl extension is not available");
        }

        $base_url = Env::get('WP_TEST_URL');
        $token = Env::get('WP_TEST_TOKEN');

        if (!is_string($base_url) || $base_url === '' || !is_string($token) || $token === '') {
            self::fatal("WP_TEST_URL and WP_TEST_TOKEN must be set in the .env file (see .env.example)");
        }

        return new self($base_url, $token);
    }

    /** Runs the whole flow and returns the process exit code. */
    public function run(): int
    {
        echo "test     shared structured ACF values on POST /posts\n";
        echo "site     $this->base_url\n";

        try {
            $this->preflight();

            // A previous interrupted run would otherwise collide with the fixture.
            $this->teardown();

            try {
                $this->createFixture();
                $this->assertFieldsPersisted($this->createPost());
            } finally {
                if (Keep::enabled()) {
                    echo "  cleanup  skipped, ONPAGE_TEST_KEEP is on: the data stays on the site\n";
                } else {
                    $this->teardown();
                    echo "  cleanup  post, field group and post type removed\n";
                }
            }
        } catch (\RuntimeException $exception) {
            $this->failures[] = $exception->getMessage();
        }

        if ($this->failures !== []) {
            fwrite(STDERR, "\nFAILED: " . count($this->failures) . " problem(s)\n");
            foreach ($this->failures as $failure) {
                fwrite(STDERR, "  - $failure\n");
            }

            return 1;
        }

        echo "\nPASSED: every shared structured value was written and read back\n";

        return 0;
    }
}



if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(AcfSharedStructuredFields::fromEnv()->run());
}
