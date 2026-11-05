<?php



namespace OnPage\Tests;



use OnPage\Env;
use OnPage\Tests\Support\Audit;
use OnPage\Tests\Support\Keep;



require_once dirname(__DIR__) . '/Env.php';
require_once __DIR__ . '/Support/Audit.php';
require_once __DIR__ . '/Support/Keep.php';



/**
 * End-to-end check for `thumbnail`, the featured image of `POST /posts`.
 *
 * Writes a post with a featured image, replaces it, leaves it out, sends it again unchanged,
 * removes it with `null`, and reads `thumbnail_id` back through `GET /posts/{id}` after each
 * step. It also checks the two errors: an attachment that does not exist answers
 * `400 input_invalid`, and a post type without featured images answers `400 invalid_param`
 * without creating the post.
 *
 * The images are uploaded with `POST /media` and sent as attachment IDs, so the run needs no
 * internet access (a URL of the test site itself would be refused as a private address).
 *
 * Create-and-delete flow: the post types, the posts and the images are created here and
 * removed again at the end, including when an assertion fails. With `ONPAGE_TEST_KEEP=1` the
 * final teardown is skipped (see `Support/Keep.php`).
 *
 * Configuration comes from the plugin `.env` (see `.env.example`):
 *
 *   WP_TEST_URL     base URL of the test WordPress site, e.g. http://localhost:8040
 *   WP_TEST_TOKEN   Bearer token, the same value stored in the `onpage_auth_token` option
 *
 * Run with: php src/Tests/PostThumbnail.php
 */
class PostThumbnail
{
    private const POST_TYPE = 'onpage_test_thumb';
    private const POST_TYPE_WITHOUT_THUMBNAIL = 'onpage_test_nothumb';
    private const LOCAL_KEY = 'onpage-test-thumbnail';
    private const LOCAL_KEY_WITHOUT_THUMBNAIL = 'onpage-test-thumbnail-unsupported';

    /** A 1x1 transparent PNG. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $base_url;
    private string $token;

    /** @var list<int> Attachments uploaded by this run, deleted in the teardown. */
    private array $attachment_ids = [];

    /** @var list<string> Assertion failures collected during the run. */
    private array $failures = [];



    private function __construct(string $base_url, string $token)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->token = $token;
    }



    /**
     * Performs one authenticated REST call against the test site, mirrored into the audit log.
     *
     * @param array<string, \CURLFile>|null $multipart Form files: sent as multipart instead of a JSON body.
     * @return array{status: int, body: mixed} Decoded response, `body` null when not JSON.
     */
    private function request(string $method, string $path, mixed $body = null, string $query = '', ?array $multipart = null): array
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

        if ($multipart !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $multipart);
            $payload = '(multipart: ' . implode(', ', array_keys($multipart)) . ')';
        } elseif ($payload !== null) {
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
     * Performs a call that must answer `200`. Failures are raised, never `exit()`ed, so the
     * `finally` teardown always runs.
     *
     * @throws \RuntimeException When the endpoint answers anything but `200`.
     */
    private function requireOk(string $method, string $path, mixed $body = null, string $query = '', ?array $multipart = null): mixed
    {
        $response = $this->request($method, $path, $body, $query, $multipart);
        if ($response['status'] !== 200) {
            throw new \RuntimeException(
                "$method $path answered {$response['status']}: "
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $response['body'];
    }



    /** Creates the two post types: one with featured images, one without. */
    private function createFixture(): void
    {
        $this->requireOk('POST', '/post-types', [
            [
                'post_type' => self::POST_TYPE,
                'singular_label' => 'On Page Test Thumbnail',
                'plural_label' => 'On Page Test Thumbnail',
                'supports' => ['title', 'editor', 'thumbnail'],
            ],
            [
                'post_type' => self::POST_TYPE_WITHOUT_THUMBNAIL,
                'singular_label' => 'On Page Test No Thumbnail',
                'plural_label' => 'On Page Test No Thumbnail',
                'supports' => ['title'],
            ],
        ]);

        echo "  fixture  post types '" . self::POST_TYPE . "' and '" . self::POST_TYPE_WITHOUT_THUMBNAIL . "' created\n";
    }

    /** Uploads one image with `POST /media` and returns its attachment ID. */
    private function uploadImage(string $name): int
    {
        $path = tempnam(sys_get_temp_dir(), 'onpage-thumb-');
        file_put_contents($path, base64_decode(self::PNG_BASE64));

        try {
            $items = $this->requireOk('POST', '/media', null, '', ['file' => new \CURLFile($path, 'image/png', "$name.png")]);
        } finally {
            @unlink($path);
        }

        $attachment_id = (int) ($items[0]['attachment_id'] ?? 0);
        if ($attachment_id <= 0) {
            throw new \RuntimeException('POST /media did not return an attachment_id: ' . json_encode($items));
        }

        $this->attachment_ids[] = $attachment_id;

        return $attachment_id;
    }

    /** Saves the test post with the given extra fields and returns its ID. */
    private function savePost(array $fields): int
    {
        $ids = $this->requireOk('POST', '/posts', [[
            'type' => self::POST_TYPE,
            'title' => 'On Page Test :: featured image',
            'status' => 'draft',
            'local_key' => self::LOCAL_KEY,
        ] + $fields]);

        $post_id = is_array($ids) ? (int) ($ids[0] ?? 0) : 0;
        if ($post_id <= 0) {
            throw new \RuntimeException('POST /posts did not return an id: ' . json_encode($ids));
        }

        return $post_id;
    }

    /** Reads the post back and checks its featured image. */
    private function assertThumbnail(string $step, int $post_id, ?int $expected): void
    {
        $post = $this->requireOk('GET', "/posts/$post_id");
        if (!is_array($post) || !array_key_exists('thumbnail_id', $post)) {
            $this->failures[] = "$step: GET /posts/$post_id has no thumbnail_id";
            return;
        }

        if ($post['thumbnail_id'] === $expected) {
            echo "  ok       $step\n";
            return;
        }

        $this->failures[] = "$step: expected thumbnail_id " . json_encode($expected) . ', got ' . json_encode($post['thumbnail_id']);
    }

    /** Sends a payload that must be rejected, and checks the status and the error code. */
    private function assertRejected(string $step, array $element, int $status, string $code): void
    {
        $response = $this->request('POST', '/posts', [$element]);
        $error_code = is_array($response['body']) ? ($response['body']['code'] ?? null) : null;

        if ($response['status'] === $status && $error_code === $code) {
            echo "  ok       $step\n";
            return;
        }

        $this->failures[] = "$step: expected $status $code, got {$response['status']} "
            . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The whole flow on a post type with featured images, then the unsupported type. */
    private function runSteps(): void
    {
        $first = $this->uploadImage('onpage-test-thumbnail-1');
        $second = $this->uploadImage('onpage-test-thumbnail-2');
        echo "  media    uploaded $first and $second\n";

        $post_id = $this->savePost(['thumbnail' => $first]);
        $this->assertThumbnail('insert with an attachment ID', $post_id, $first);

        $this->savePost(['thumbnail' => $second]);
        $this->assertThumbnail('update replaces it', $post_id, $second);

        $this->savePost(['content' => 'No thumbnail key']);
        $this->assertThumbnail('update without thumbnail keeps it', $post_id, $second);

        $this->savePost(['thumbnail' => $second]);
        $this->assertThumbnail('same image again is accepted', $post_id, $second);

        $this->savePost(['thumbnail' => null]);
        $this->assertThumbnail('null removes it', $post_id, null);

        $this->assertRejected(
            'unknown attachment ID: 400 input_invalid',
            ['type' => self::POST_TYPE, 'title' => 'On Page Test :: featured image', 'local_key' => self::LOCAL_KEY, 'thumbnail' => 999999999],
            400,
            'input_invalid'
        );

        $this->assertRejected(
            'post type without featured images: 400 invalid_param',
            ['type' => self::POST_TYPE_WITHOUT_THUMBNAIL, 'title' => 'On Page Test :: unsupported', 'local_key' => self::LOCAL_KEY_WITHOUT_THUMBNAIL, 'thumbnail' => $first],
            400,
            'invalid_param'
        );

        $lookup = $this->request('GET', '/posts', null, http_build_query(['local_key' => self::LOCAL_KEY_WITHOUT_THUMBNAIL, 'type' => self::POST_TYPE_WITHOUT_THUMBNAIL]));
        if ($lookup['status'] === 200 && is_array($lookup['body']) && $lookup['body'] !== []) {
            $this->failures[] = 'post type without featured images: the post was created although the request was rejected';
        }
    }

    /** Checks the site answers and accepts the token, before anything gets created. */
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
     * Removes the posts, the images and the post types. `?ignore=1` everywhere, and transport
     * errors are only a warning: teardown runs from a `finally`.
     */
    private function teardown(): void
    {
        $calls = [
            ['/posts', [
                ['local_key' => self::LOCAL_KEY, 'type' => self::POST_TYPE],
                ['local_key' => self::LOCAL_KEY_WITHOUT_THUMBNAIL, 'type' => self::POST_TYPE_WITHOUT_THUMBNAIL],
            ]],
            ['/media', $this->attachment_ids],
            ['/post-types', [self::POST_TYPE, self::POST_TYPE_WITHOUT_THUMBNAIL]],
        ];

        foreach ($calls as [$path, $body]) {
            if ($body === []) continue;

            try {
                $this->request('DELETE', $path, $body, 'ignore=1');
            } catch (\RuntimeException $exception) {
                fwrite(STDERR, "  warning  cleanup of $path failed: {$exception->getMessage()}\n");
            }
        }
    }

    /** Prints the message on stderr and stops the run, before anything is created. */
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
        echo "test     featured image (thumbnail) on POST /posts\n";
        echo "site     $this->base_url\n";

        try {
            $this->preflight();

            // A previous interrupted run would otherwise collide with the fixture.
            $this->teardown();

            try {
                $this->createFixture();
                $this->runSteps();
            } finally {
                if (Keep::enabled()) {
                    echo "  cleanup  skipped, ONPAGE_TEST_KEEP is on: the data stays on the site\n";
                } else {
                    $this->teardown();
                    echo "  cleanup  posts, images and post types removed\n";
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

        echo "\nPASSED: the featured image was set, kept, replaced and removed\n";

        return 0;
    }
}



if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(PostThumbnail::fromEnv()->run());
}
