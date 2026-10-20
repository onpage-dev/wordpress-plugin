<?php



namespace OnPage\Tests;



use OnPage\Env;
use OnPage\Tests\Support\Audit;
use OnPage\Tests\Support\Keep;



require_once dirname(__DIR__) . '/Env.php';
require_once __DIR__ . '/Support/Audit.php';
require_once __DIR__ . '/Support/Keep.php';



/**
 * End-to-end check that a translated product goes to the trash and comes back as one WPML
 * group, by `local_key`, on `POST /woocommerce/products`.
 *
 *   trash     `{local_key, status: "trash"}`                    → every translation `trash`,
 *                                                                  the default-language id answered,
 *                                                                  `?local_key=` read `404`
 *   again     the same trash request                            → `200`, nothing changes
 *   update    re-import with both names, no `status`            → both translations still `trash`
 *   restore   re-import with a name map holding `en` only and
 *             `status: "publish"`                               → both translations back to
 *                                                                  `publish`, same ids, no new
 *                                                                  product, Italian name kept
 *   trash     `{id: <Italian id>, local_key, status: "trash"}`  → the whole group again
 *   restore   re-import with both names and `status: "draft"`  → both translations `draft`
 *
 * The partial map matters: the restore must reach every trashed member of the group, not only
 * the languages the payload writes. Otherwise the Italian product would stay in the trash
 * while the English one is live, and the next import would see a broken group.
 *
 * Needs WooCommerce and WPML with `en` (the default language) and `it` active on the test site.
 *
 * Create-and-delete flow: the product is created here and removed again at the end, also when
 * an assertion fails. `DELETE /woocommerce/products` reaches the trash too, so a product left
 * trashed by a failed run is removed as well. With `ONPAGE_TEST_KEEP=1` the final cleanup is
 * skipped (see `Support/Keep.php`).
 *
 * Configuration comes from the plugin `.env` (see `.env.example`):
 *
 *   WP_TEST_URL     base URL of the test WordPress site, e.g. http://localhost:8040
 *   WP_TEST_TOKEN   Bearer token, the same value stored in the `onpage_auth_token` option
 *
 * Run with: php src/Tests/ProductTrashRestoreMultiLang.php
 */
class ProductTrashRestoreMultiLang
{
    private const DEFAULT_LANGUAGE = 'en';
    private const LANGUAGES = ['en', 'it'];

    private const PRODUCT_KEY = 'onpage-test-trash-multilang';

    private const NAMES = [
        'en' => 'On Page Test :: Trashed Chair',
        'it' => 'On Page Test :: Sedia Cestinata',
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

    /** Posts one product and returns the single id of the `200` answer. */
    private function saveOne(array $element, string $label): int
    {
        $body = $this->requireOk('POST', '/woocommerce/products', [$element]);
        $id = is_array($body) ? (int) ($body[0] ?? 0) : 0;
        if ($id <= 0) {
            throw new \RuntimeException(
                "$label :: POST /woocommerce/products did not return an id: "
                . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $id;
    }

    /** One product read by id: `?id=` returns trashed products too. */
    private function readById(int $id): array
    {
        $items = $this->requireOk('GET', '/woocommerce/products', null, 'id=' . $id);
        if (!is_array($items) || !is_array($items[0] ?? null)) {
            throw new \RuntimeException("GET /woocommerce/products?id=$id returned no product: " . json_encode($items));
        }

        return $items[0];
    }

    /**
     * The `language => id` map of the live product holding the key.
     *
     * `?local_key=` must find exactly one product, the default-language one, with every
     * translation in its `translations` map. A second product created next to the restored
     * one would show up here as a different id.
     */
    private function readTranslationIds(): array
    {
        $items = $this->requireOk('GET', '/woocommerce/products', null, 'local_key=' . self::PRODUCT_KEY);
        if (!is_array($items) || count($items) !== 1 || !is_array($items[0] ?? null)) {
            throw new \RuntimeException(
                'GET /woocommerce/products?local_key=' . self::PRODUCT_KEY . ' should have returned exactly one product: '
                . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $ids = is_array($items[0]['translations'] ?? null) ? $items[0]['translations'] : [];
        ksort($ids);

        return array_map('intval', $ids);
    }

    /** Asserts the status of every translation, read by id. */
    private function checkStatuses(string $label, array $ids, string $expected): void
    {
        foreach ($ids as $language => $id) {
            $this->check("$label.$language", $expected, $this->readById($id)['status'] ?? null);
        }
    }



    // -------------------------------------------------------------------- scenario

    private function assertTrashAndRestoreGroup(): void
    {
        $trash = ['local_key' => self::PRODUCT_KEY, 'status' => 'trash'];
        $product = ['local_key' => self::PRODUCT_KEY, 'props' => ['product_type' => 'simple']];

        $id = $this->saveOne($product + ['name' => self::NAMES, 'status' => 'publish'], 'create');
        $ids = $this->readTranslationIds();
        echo "  product  created: " . json_encode($ids) . "\n";

        $this->check('create.languages', self::LANGUAGES, array_keys($ids));
        $this->check('create.default_id', $id, $ids[self::DEFAULT_LANGUAGE] ?? null);
        $this->checkStatuses('create.status', $ids, 'publish');

        // Trash by local_key: the whole group, and the default-language id is answered.
        $this->check('trash.id', $id, $this->saveOne($trash, 'trash'));
        $this->checkStatuses('trash.status', $ids, 'trash');
        $this->check(
            'trash.read_by_local_key',
            404,
            $this->request('GET', '/woocommerce/products', null, 'local_key=' . self::PRODUCT_KEY)['status']
        );

        // `status=trashed` reads the trashed group: the default-language product, with every
        // translation in its map.
        $trashed = $this->requireOk('GET', '/woocommerce/products', null, 'local_key=' . self::PRODUCT_KEY . '&status=trashed');
        $trashed = is_array($trashed) && is_array($trashed[0] ?? null) ? $trashed[0] : [];
        $this->check('trash.read_trashed.id', $id, $trashed['id'] ?? null);
        $trashed_ids = is_array($trashed['translations'] ?? null) ? array_map('intval', $trashed['translations']) : [];
        ksort($trashed_ids);
        $this->check('trash.read_trashed.translations', $ids, $trashed_ids);

        $this->check('trash.again.id', $id, $this->saveOne($trash, 'trash.again'));
        $this->checkStatuses('trash.again.status', $ids, 'trash');

        // An omitted status keeps the current one, trash included, in every language.
        $this->check('update.trashed.id', $id, $this->saveOne($product + ['name' => self::NAMES], 'update.trashed'));
        $this->checkStatuses('update.trashed.status', $ids, 'trash');

        // Restore with a map that writes English only: Italian must come back too.
        $this->check(
            'restore.partial.id',
            $id,
            $this->saveOne($product + ['name' => ['en' => self::NAMES['en']], 'status' => 'publish'], 'restore.partial')
        );
        $this->check('restore.partial.ids', $ids, $this->readTranslationIds());
        $this->checkStatuses('restore.partial.status', $ids, 'publish');
        $this->check('restore.partial.name.it', self::NAMES['it'], $this->readById($ids['it'])['title'] ?? null);

        // Trash by the Italian id: the group follows its translation.
        $this->check(
            'trash.by_translation_id.id',
            $ids['it'],
            $this->saveOne($trash + ['id' => $ids['it']], 'trash.by_translation_id')
        );
        $this->checkStatuses('trash.by_translation_id.status', $ids, 'trash');

        // Restore with an explicit status: the payload wins over the status before the trash.
        $this->check(
            'restore.draft.id',
            $id,
            $this->saveOne($product + ['name' => self::NAMES, 'status' => 'draft'], 'restore.draft')
        );
        $this->check('restore.draft.ids', $ids, $this->readTranslationIds());
        $this->checkStatuses('restore.draft.status', $ids, 'draft');
    }



    // --------------------------------------------------------------------- support

    /** Records one comparison, printing the passing ones and collecting the rest. */
    private function check(string $label, mixed $expected, mixed $actual): void
    {
        if (self::normalize($expected) === self::normalize($actual)) {
            echo "  ok       $label\n";

            return;
        }

        $this->failures[] = sprintf(
            '%s: expected %s, got %s',
            $label,
            json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Flattens scalars to strings before comparing.
     *
     * WordPress and WooCommerce normalise types on the way back — an id can return as a
     * string — so a strict comparison would report type noise instead of the thing under
     * test, which is which row the endpoint landed on.
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
     * Checks the site answers, accepts the token and has WPML with the test languages,
     * before anything is created.
     *
     * @throws \RuntimeException When the site is unreachable, rejects the token or lacks the languages.
     */
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

        $body = is_array($response['body']) ? $response['body'] : [];
        $languages = (array) ($body['languages'] ?? []);
        $has_languages = array_diff(self::LANGUAGES, $languages) === [];
        if (!($body['wpml_active'] ?? false) || !$has_languages || ($body['default'] ?? null) !== self::DEFAULT_LANGUAGE) {
            throw new \RuntimeException('the test needs WPML with en (default) and it active: ' . json_encode($body));
        }
    }

    /**
     * Removes the product, whether live or in the trash.
     *
     * `?ignore=1`, and a transport error is downgraded to a warning: teardown runs from a
     * `finally`, so anything it threw would hide the failure the run is reporting.
     */
    private function teardown(): void
    {
        try {
            $this->request('DELETE', '/woocommerce/products', [self::PRODUCT_KEY], 'ignore=1');
        } catch (\RuntimeException $exception) {
            fwrite(STDERR, "  warning  cleanup of /woocommerce/products failed: {$exception->getMessage()}\n");
        }
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
        echo "test     translated product to the trash and back by local_key on POST /woocommerce/products\n";
        echo "site     $this->base_url\n";

        try {
            $this->preflight();

            // A previous interrupted run would otherwise collide with the fixture.
            $this->teardown();

            try {
                $this->assertTrashAndRestoreGroup();
            } catch (\RuntimeException $exception) {
                $this->failures[] = $exception->getMessage();
            } finally {
                if (Keep::enabled()) {
                    echo "  cleanup  skipped, ONPAGE_TEST_KEEP is on: the data stays on the site\n";
                } else {
                    $this->teardown();
                    echo "  cleanup  product removed\n";
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

        echo "\nPASSED: a translated product goes to the trash and comes back as one WPML group, with the same ids\n";

        return 0;
    }
}



if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(ProductTrashRestoreMultiLang::fromEnv()->run());
}
