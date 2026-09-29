<?php



namespace OnPage\Tests;



use OnPage\Env;
use OnPage\Tests\Support\Audit;
use OnPage\Tests\Support\Keep;



require_once dirname(__DIR__) . '/Env.php';
require_once __DIR__ . '/Support/Audit.php';
require_once __DIR__ . '/Support/Keep.php';



/**
 * End-to-end check that `DELETE /terms` deletes by ID or by `local_key`, like `DELETE /posts`.
 *
 * An ID must delete that term only, and leave its WPML translations in place. A `local_key`
 * must delete every term holding it: the whole translation group, since the key is written
 * on each translation.
 *
 *   {"id": <it>}                       → the Italian term goes, the English one stays
 *   {"local_key": B, "taxonomy": …}    → every translation of B goes
 *   ?keyfield=local_key, plain A       → what is left of A goes
 *   a key in two taxonomies            → `409 ambiguous_local_key` without a taxonomy
 *   malformed elements                 → `400`, before anything is deleted
 *
 * Needs WPML with `en` and `it` active on the test site, and the `category` taxonomy
 * translatable (the WPML default).
 *
 * Create-and-delete flow: the terms are created here and removed again at the end, also
 * when an assertion fails. With `ONPAGE_TEST_KEEP=1` the final cleanup is skipped (see
 * `Support/Keep.php`).
 *
 * Configuration comes from the plugin `.env` (see `.env.example`):
 *
 *   WP_TEST_URL     base URL of the test WordPress site, e.g. http://localhost:8040
 *   WP_TEST_TOKEN   Bearer token, the same value stored in the `onpage_auth_token` option
 *
 * Run with: php src/Tests/TermDeleteByLocalKey.php
 */
class TermDeleteByLocalKey
{
    private const TAXONOMY = 'category';

    /** Holds the same key as TAXONOMY, to make an unscoped lookup ambiguous. */
    private const OTHER_TAXONOMY = 'post_tag';

    private const KEYS = [
        'single' => 'onpage-test-termdel-1',
        'group' => 'onpage-test-termdel-2',
        'shared' => 'onpage-test-termdel-3',
    ];

    private string $base_url;
    private string $token;

    /** @var list<string> Assertion failures collected during the run. */
    private array $failures = [];



    private function __construct(string $base_url, string $token)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->token = $token;
    }



    // ------------------------------------------------------------------- transport

    /**
     * Performs one authenticated REST call against the test site, mirrored into the audit log.
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
     * Failures are raised, never `exit()`ed: PHP skips `finally` on exit, and skipping it
     * here would leave the fixture behind on the site.
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



    // -------------------------------------------------------------------- fixture

    /** Creates the three terms: two translated into Italian, one in two taxonomies. */
    private function createFixture(): void
    {
        foreach (['single', 'group'] as $name) {
            $this->requireOk('POST', '/terms', [[
                'taxonomy' => self::TAXONOMY,
                'local_key' => self::KEYS[$name],
                'name' => ['en' => "On Page Test Delete $name", 'it' => "On Page Test Elimina $name"],
            ]]);
        }

        foreach ([self::TAXONOMY, self::OTHER_TAXONOMY] as $taxonomy) {
            $this->requireOk('POST', '/terms', [[
                'taxonomy' => $taxonomy,
                'local_key' => self::KEYS['shared'],
                'name' => 'On Page Test Delete shared',
            ]]);
        }

        echo "  fixture  terms created in '" . self::TAXONOMY . "' and '" . self::OTHER_TAXONOMY . "'\n";
    }

    /**
     * The `translations` map (language => term ID) of the term holding a local_key, or null
     * when no term holds it.
     *
     * @return array<string, int>|null
     */
    private function translations(string $local_key, string $taxonomy = self::TAXONOMY): ?array
    {
        $terms = $this->requireOk('GET', '/terms', null, 'taxonomy=' . $taxonomy);
        foreach (is_array($terms) ? $terms : [] as $term) {
            if ((string) ($term['local_key'] ?? '') === $local_key) {
                return array_map('intval', (array) ($term['translations'] ?? []));
            }
        }

        return null;
    }

    /** Records a failure unless the condition holds. */
    private function check(bool $condition, string $name): void
    {
        if ($condition) {
            echo "  ok       $name\n";
            return;
        }

        $this->failures[] = $name;
        echo "  FAILED   $name\n";
    }

    /** Records a failure unless the call answers the expected status and error code. */
    private function checkError(mixed $body, string $query, int $status, string $code, string $name): void
    {
        $response = $this->request('DELETE', '/terms', $body, $query);
        $actual_code = is_array($response['body']) ? (string) ($response['body']['code'] ?? '') : '';

        $this->check($response['status'] === $status && $actual_code === $code, "$name → $status $code (got {$response['status']} $actual_code)");
    }



    // ------------------------------------------------------------------ scenarios

    private function assertDeleteById(): void
    {
        $before = $this->translations(self::KEYS['single']);
        if ($before === null || !isset($before['en'], $before['it'])) {
            throw new \RuntimeException('the fixture term has no en/it translations: ' . json_encode($before) . ' (WPML not active, or category not translatable?)');
        }

        $this->requireOk('DELETE', '/terms', [['id' => $before['it']]]);

        $after = $this->translations(self::KEYS['single']) ?? [];
        $this->check(($after['en'] ?? null) === $before['en'], 'id: the English translation is kept');
        $this->check(!isset($after['it']), 'id: the Italian translation is deleted');
        $this->check($this->request('DELETE', '/terms', [$before['it']])['status'] === 404, 'id: the deleted ID answers 404');

        // What is left of the group goes with a plain value in keyfield mode.
        $this->requireOk('DELETE', '/terms', [self::KEYS['single']], 'keyfield=local_key&taxonomy=' . self::TAXONOMY);
        $this->check($this->translations(self::KEYS['single']) === null, 'keyfield=local_key: the remaining translation is deleted');
        $this->check($this->request('DELETE', '/terms', [$before['en']])['status'] === 404, 'keyfield=local_key: the English ID answers 404');
    }

    private function assertDeleteByLocalKey(): void
    {
        $before = $this->translations(self::KEYS['group']);
        if ($before === null || count($before) < 2) {
            throw new \RuntimeException('the fixture group has fewer than two translations: ' . json_encode($before));
        }

        $this->requireOk('DELETE', '/terms', [['local_key' => self::KEYS['group'], 'taxonomy' => self::TAXONOMY]]);

        $this->check($this->translations(self::KEYS['group']) === null, 'local_key: the group is no longer listed');
        foreach ($before as $language => $term_id) {
            $this->check($this->request('DELETE', '/terms', [$term_id])['status'] === 404, "local_key: the $language translation answers 404");
        }
    }

    private function assertAmbiguousLocalKey(): void
    {
        $this->checkError([['local_key' => self::KEYS['shared']]], '', 409, 'ambiguous_local_key', 'a key in two taxonomies, no taxonomy');
        $this->check($this->translations(self::KEYS['shared']) !== null, 'ambiguous: nothing is deleted');

        $this->requireOk('DELETE', '/terms', [['local_key' => self::KEYS['shared']]], 'taxonomy=' . self::TAXONOMY);
        $this->check($this->translations(self::KEYS['shared']) === null, 'ambiguous: ?taxonomy= deletes the category term');
        $this->check($this->translations(self::KEYS['shared'], self::OTHER_TAXONOMY) !== null, 'ambiguous: the post_tag term is kept');

        // Now the key lives in one taxonomy only, so no taxonomy is needed.
        $this->requireOk('DELETE', '/terms', [['local_key' => self::KEYS['shared']]]);
        $this->check($this->translations(self::KEYS['shared'], self::OTHER_TAXONOMY) === null, 'unscoped: the single remaining taxonomy is used');
    }

    private function assertValidation(): void
    {
        $this->checkError([], 'keyfield=slug', 400, 'invalid_keyfield', 'unknown keyfield');
        $this->checkError([['local_key' => '']], '', 400, 'input_invalid', 'empty local_key');
        $this->checkError([['id' => '12abc']], '', 400, 'input_invalid', 'malformed id');
        $this->checkError([['taxonomy' => self::TAXONOMY]], '', 400, 'input_invalid', 'object without id or local_key');
        $this->checkError([['id' => 1]], 'keyfield=local_key', 400, 'input_invalid', 'id object in keyfield=local_key mode');
        $this->checkError([['local_key' => 'onpage-test-termdel-missing']], '', 404, 'not_found', 'missing local_key');

        $response = $this->request('DELETE', '/terms', [['local_key' => 'onpage-test-termdel-missing']], 'ignore=1');
        $this->check($response['status'] === 200, "missing local_key with ?ignore → 200 (got {$response['status']})");
    }



    // ------------------------------------------------------------------- lifecycle

    private function preflight(): void
    {
        $response = $this->request('GET', '/languages');
        if ($response['status'] !== 200) {
            throw new \RuntimeException(
                "the site answered {$response['status']} on GET /languages: "
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ' (wrong WP_TEST_URL or WP_TEST_TOKEN?)'
            );
        }

        $languages = (array) ($response['body']['languages'] ?? []);
        if (!($response['body']['wpml_active'] ?? false) || !in_array('en', $languages, true) || !in_array('it', $languages, true)) {
            throw new \RuntimeException('the test needs WPML with en and it active: ' . json_encode($response['body']));
        }
    }

    /** Removes every fixture term, in both taxonomies. Missing terms are skipped. */
    private function teardown(): void
    {
        $body = [];
        foreach (self::KEYS as $local_key) {
            foreach ([self::TAXONOMY, self::OTHER_TAXONOMY] as $taxonomy) {
                $body[] = ['local_key' => $local_key, 'taxonomy' => $taxonomy];
            }
        }

        try {
            $response = $this->request('DELETE', '/terms', $body, 'ignore=1');
            if ($response['status'] !== 200) {
                fwrite(STDERR, "  warning  cleanup answered {$response['status']}\n");
            }
        } catch (\RuntimeException $exception) {
            fwrite(STDERR, "  warning  cleanup failed: {$exception->getMessage()}\n");
        }
    }

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
        echo "test     DELETE /terms by ID and by local_key\n";
        echo "site     $this->base_url\n";

        try {
            $this->preflight();
            $this->teardown();

            try {
                $this->createFixture();

                foreach (['assertDeleteById', 'assertDeleteByLocalKey', 'assertAmbiguousLocalKey', 'assertValidation'] as $scenario) {
                    try {
                        $this->$scenario();
                    } catch (\RuntimeException $exception) {
                        $this->failures[] = $exception->getMessage();
                    }
                }
            } finally {
                if (Keep::enabled()) {
                    echo "  cleanup  skipped, ONPAGE_TEST_KEEP is on: the data stays on the site\n";
                } else {
                    $this->teardown();
                    echo "  cleanup  terms removed\n";
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

        echo "\nPASSED: an ID deletes one term, a local_key deletes the whole translation group\n";

        return 0;
    }
}



if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(TermDeleteByLocalKey::fromEnv()->run());
}
