<?php



namespace OnPage\Tests;



use OnPage\Env;
use OnPage\Tests\Support\Audit;
use OnPage\Tests\Support\Keep;



require_once dirname(__DIR__) . '/Env.php';
require_once __DIR__ . '/Support/Audit.php';
require_once __DIR__ . '/Support/Keep.php';



/**
 * End-to-end check that `status: "trash"` on `POST /posts` and `POST /woocommerce/products`
 * moves an element to the trash, and that the next import of its `local_key` with a `status`
 * restores it. The trash is a status like any other: an import without `status` keeps it.
 *
 * This is how an integration retires an element that left its On Page® view and brings it
 * back when it returns, without losing the WordPress ID, the URL or the variations.
 *
 *   trash     `{local_key, status: "trash"}` with no title/name    → same id, status `trash`,
 *                                                                     gone from local_key reads
 *   again     the same trash request                               → `200`, still trashed
 *   update    re-import without `status`                           → same id, still `trash`
 *   restore   re-import with `status: "publish"`                   → same id, `publish`, back in
 *                                                                     local_key reads
 *   restore   re-import with `status: "draft"`                     → same id, `draft`
 *   missing   trash of a local_key nobody holds                    → `404 not_found`
 *   variations  trashed with their parent, back with it            → variation local_key read `[]`,
 *                                                                     then `publish` again
 *
 * Create-and-delete flow: the post type, the posts and the products are created here and
 * removed again at the end, including when an assertion fails. Every teardown call uses
 * `?ignore=1`, and the teardown also runs before the fixture is built. Permanent deletes by
 * `local_key` reach the trash too, so a trashed leftover is removed as well. With
 * `ONPAGE_TEST_KEEP=1` the final teardown is skipped (see `Support/Keep.php`).
 *
 * Configuration comes from the plugin `.env` (see `.env.example`):
 *
 *   WP_TEST_URL     base URL of the test WordPress site, e.g. http://localhost:8040
 *   WP_TEST_TOKEN   Bearer token, the same value stored in the `onpage_auth_token` option
 *
 * Run with: php src/Tests/TrashRestore.php
 */
class TrashRestore
{
    private const POST_TYPE = 'onpage_test_trash';

    private const POST_KEY = 'onpage-test-trash-post';
    private const PRODUCT_KEY = 'onpage-test-trash-product';
    private const VARIATION_KEY = 'onpage-test-trash-variation';
    private const MISSING_KEY = 'onpage-test-trash-missing';

    private const POST_TITLE = 'On Page Test :: Trashed Post';
    private const PRODUCT_NAME = 'On Page Test :: Trashed Product';

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

    /** Posts one element and returns the single id of the `200` answer. */
    private function saveOne(string $path, array $element, string $label): int
    {
        $body = $this->requireOk('POST', $path, [$element]);
        $id = is_array($body) ? (int) ($body[0] ?? 0) : 0;
        if ($id <= 0) {
            throw new \RuntimeException(
                "$label :: POST $path did not return an id: "
                . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $id;
    }

    /** Status of a post read by id (`GET /posts/{id}` returns trashed posts too). */
    private function postStatus(int $id): mixed
    {
        $post = $this->requireOk('GET', '/posts/' . $id);

        return is_array($post) ? ($post['status'] ?? null) : null;
    }

    /** Status of a product read by id (`GET /woocommerce/products?id=` returns trashed ones too). */
    private function productStatus(int $id): mixed
    {
        $items = $this->requireOk('GET', '/woocommerce/products', null, 'id=' . $id);

        return is_array($items) && is_array($items[0] ?? null) ? ($items[0]['status'] ?? null) : null;
    }

    /** HTTP status of a read by local_key: `404` while the element is in the trash. */
    private function readStatus(string $path, string $query): int
    {
        return $this->request('GET', $path, null, $query)['status'];
    }

    /** Error code of a call that must fail, or the HTTP status when the body has none. */
    private function errorOf(string $method, string $path, mixed $body): string
    {
        $response = $this->request($method, $path, $body);
        $code = is_array($response['body']) ? (string) ($response['body']['code'] ?? '') : '';

        return $response['status'] . ($code === '' ? '' : " $code");
    }



    // -------------------------------------------------------------------- contents

    /** Runs the whole cycle on `POST /posts`. */
    private function assertPostsTrashAndRestore(): void
    {
        $this->requireOk('POST', '/post-types', [[
            'post_type' => self::POST_TYPE,
            'singular_label' => 'On Page Test Trash',
            'plural_label' => 'On Page Test Trash',
            'supports' => ['title', 'editor'],
        ]]);

        $live = ['type' => self::POST_TYPE, 'local_key' => self::POST_KEY, 'title' => self::POST_TITLE];
        $trash = ['type' => self::POST_TYPE, 'local_key' => self::POST_KEY, 'status' => 'trash'];
        $read = 'keyfield=local_key&type=' . self::POST_TYPE;

        $id = $this->saveOne('/posts', $live + ['status' => 'publish'], 'post.create');
        echo "  post     created with id $id\n";

        $this->check('post.trash.id', $id, $this->saveOne('/posts', $trash, 'post.trash'));
        $this->check('post.trash.status', 'trash', $this->postStatus($id));
        $this->check('post.trash.read_by_local_key', 404, $this->readStatus('/posts/' . self::POST_KEY, $read));

        $this->check('post.trash.again.id', $id, $this->saveOne('/posts', $trash, 'post.trash.again'));
        $this->check('post.trash.again.status', 'trash', $this->postStatus($id));

        // An omitted status keeps the current one, trash included.
        $this->check('post.update.trashed.id', $id, $this->saveOne('/posts', $live, 'post.update.trashed'));
        $this->check('post.update.trashed.status', 'trash', $this->postStatus($id));

        $this->check('post.restore.id', $id, $this->saveOne('/posts', $live + ['status' => 'publish'], 'post.restore'));
        $this->check('post.restore.status', 'publish', $this->postStatus($id));
        $this->check('post.restore.read_by_local_key', 200, $this->readStatus('/posts/' . self::POST_KEY, $read));

        $this->saveOne('/posts', $trash, 'post.trash.second');
        $this->check('post.restore.draft.id', $id, $this->saveOne('/posts', $live + ['status' => 'draft'], 'post.restore.draft'));
        $this->check('post.restore.draft.status', 'draft', $this->postStatus($id));

        $this->check(
            'post.trash.missing',
            '404 not_found',
            $this->errorOf('POST', '/posts', [['type' => self::POST_TYPE, 'local_key' => self::MISSING_KEY, 'status' => 'trash']])
        );
    }

    /** Runs the whole cycle on `POST /woocommerce/products`, with a variation. */
    private function assertProductsTrashAndRestore(): void
    {
        $live = [
            'local_key' => self::PRODUCT_KEY,
            'name' => self::PRODUCT_NAME,
            'props' => ['product_type' => 'variable'],
            'attributes' => ['Size' => ['S']],
        ];
        $trash = ['local_key' => self::PRODUCT_KEY, 'status' => 'trash'];

        $id = $this->saveOne('/woocommerce/products', $live + ['status' => 'publish'], 'product.create');
        echo "  product  created with id $id\n";
        $this->requireOk('POST', '/woocommerce/variant-products', [[
            'local_key' => self::VARIATION_KEY,
            'parent' => self::PRODUCT_KEY,
            'attributes' => ['Size' => 'S'],
            'props' => ['regular_price' => '10'],
            'status' => 'publish',
        ]]);

        $this->check('product.trash.id', $id, $this->saveOne('/woocommerce/products', $trash, 'product.trash'));
        $this->check('product.trash.status', 'trash', $this->productStatus($id));
        $this->check('product.trash.read_by_local_key', 404, $this->readStatus('/woocommerce/products', 'local_key=' . self::PRODUCT_KEY));
        $this->check('product.trash.variation', [], $this->requireOk('GET', '/woocommerce/variant-products', null, 'local_key=' . self::VARIATION_KEY));

        $this->check('product.trash.again.id', $id, $this->saveOne('/woocommerce/products', $trash, 'product.trash.again'));

        $this->check('product.update.trashed.id', $id, $this->saveOne('/woocommerce/products', $live, 'product.update.trashed'));
        $this->check('product.update.trashed.status', 'trash', $this->productStatus($id));
        $this->check('product.update.trashed.variation', [], $this->requireOk('GET', '/woocommerce/variant-products', null, 'local_key=' . self::VARIATION_KEY));

        $this->check('product.restore.id', $id, $this->saveOne('/woocommerce/products', $live + ['status' => 'publish'], 'product.restore'));
        $this->check('product.restore.status', 'publish', $this->productStatus($id));
        $this->check('product.restore.read_by_local_key', 200, $this->readStatus('/woocommerce/products', 'local_key=' . self::PRODUCT_KEY));

        $variations = $this->requireOk('GET', '/woocommerce/variant-products', null, 'local_key=' . self::VARIATION_KEY);
        $variation = is_array($variations) && is_array($variations[0] ?? null) ? $variations[0] : [];
        $this->check('product.restore.variation.status', 'publish', $variation['status'] ?? null);

        $this->saveOne('/woocommerce/products', $trash, 'product.trash.second');
        $this->check('product.restore.draft.id', $id, $this->saveOne('/woocommerce/products', $live + ['status' => 'draft'], 'product.restore.draft'));
        $this->check('product.restore.draft.status', 'draft', $this->productStatus($id));

        $this->check(
            'product.trash.missing',
            '404 not_found',
            $this->errorOf('POST', '/woocommerce/products', [['local_key' => self::MISSING_KEY, 'status' => 'trash']])
        );
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
     * Removes the variation, the product, the post and the post type, in that order.
     *
     * `?ignore=1` everywhere, and transport errors are downgraded to a warning: teardown
     * runs from a `finally`, so anything it threw would replace the real failure.
     */
    private function teardown(): void
    {
        $calls = [
            ['/woocommerce/variant-products', [self::VARIATION_KEY]],
            ['/woocommerce/products', [self::PRODUCT_KEY]],
            ['/posts', [['local_key' => self::POST_KEY, 'type' => self::POST_TYPE]]],
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
        echo "test     status trash and restore on POST /posts and POST /woocommerce/products\n";
        echo "site     $this->base_url\n";

        try {
            // A previous interrupted run would otherwise collide with the fixture.
            $this->teardown();

            try {
                // Independent halves: a failure on posts must not hide the product result.
                foreach (['assertPostsTrashAndRestore', 'assertProductsTrashAndRestore'] as $scenario) {
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
                    echo "  cleanup  variation, product, post and post type removed\n";
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

        echo "\nPASSED: status trash moves posts and products to the trash, an import keeps them there without a status and restores them with one\n";

        return 0;
    }
}



if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(TrashRestore::fromEnv()->run());
}
