<?php



namespace OnPage\Tests;



use OnPage\Env;
use OnPage\Tests\Support\Audit;
use OnPage\Tests\Support\Keep;



require_once dirname(__DIR__) . '/Env.php';
require_once __DIR__ . '/Support/Audit.php';
require_once __DIR__ . '/Support/Keep.php';



/**
 * End-to-end check that two *distinct* On Page® elements sharing a name import fine.
 *
 * The duplicate-title guard used to search by title across the whole post type without
 * ever looking at the `local_key` of what it found, so two different elements that
 * happened to carry the same name were not both importable: the second one was rejected
 * with `409 duplicate_title`, permanently. The guard exists for a different and
 * legitimate case — a post created by hand on the site, with no `local_key`, that a
 * blind import would duplicate — and that case is recognised precisely by the *absence*
 * of the key.
 *
 * The test covers both branches, because fixing only the insert would have made the
 * second element importable exactly once: every later import resolves it by `local_key`
 * and takes the update branch, which carried the same defect.
 *
 *   insert   two elements, different `local_key`, same name          → both created
 *   update   re-import of both, and a rename onto a name already
 *            held by another element                                 → no `409`
 *
 * The two branches are reached differently on the two endpoints. `POST /posts` only runs
 * its update-time title check when the title actually changes, so the rename is what
 * exercises it there; `POST /woocommerce/products` re-checks on every payload carrying a
 * name, so the plain re-import reaches it too. Both are asserted on both endpoints.
 *
 * NOT covered: that the guard still rejects a same-named post with *no* `local_key`,
 * which is what it is for. No endpoint of this plugin can create an unkeyed post —
 * `local_key` is required on every write — and the only thing that strips keys,
 * `DELETE /indexes`, wipes them site-wide. That half stays a code-level invariant
 * (`PostRepository::carriesForeignLocalKey()`).
 *
 * Everything is created with status `publish` on purpose. The lookups behind the guard
 * query `post_status => any`, which in WP_Query is not "every status": it excludes the
 * ones registered with `exclude_from_search`, `trash` and `auto-draft` among them. A
 * fixture the guard cannot see would make this test pass against the broken code too, so
 * it uses the one status that is unambiguously inside that set.
 *
 * Create-and-delete flow: the post type, the posts and the products are created here and
 * removed again at the end, including when an assertion fails. Every teardown call uses
 * `?ignore=1`, so a leftover from an interrupted run never masks a real failure; the same
 * teardown also runs before the fixture is built. With `ONPAGE_TEST_KEEP=1` the final
 * teardown is skipped and the data stays on the site (see `Support/Keep.php`).
 *
 * Configuration comes from the plugin `.env` (see `.env.example`):
 *
 *   WP_TEST_URL     base URL of the test WordPress site, e.g. http://localhost:8040
 *   WP_TEST_TOKEN   Bearer token, the same value stored in the `onpage_auth_token` option
 *
 * Run with: php src/Tests/DuplicateTitleDistinctLocalKeys.php
 */
class DuplicateTitleDistinctLocalKeys
{
    private const POST_TYPE = 'onpage_test_dupkey';

    /** The name deliberately shared by two unrelated elements. */
    private const SHARED_TITLE = 'On Page Test :: Milan Chair';

    /** A name held by a third element until it gets renamed onto the shared one. */
    private const OTHER_TITLE = 'On Page Test :: Turin Chair';

    private const POST_KEYS = [
        'first' => 'onpage-test-dupkey-post-1',
        'namesake' => 'onpage-test-dupkey-post-2',
        'third' => 'onpage-test-dupkey-post-3',
    ];

    private const PRODUCT_KEYS = [
        'first' => 'onpage-test-dupkey-prod-1',
        'namesake' => 'onpage-test-dupkey-prod-2',
        'third' => 'onpage-test-dupkey-prod-3',
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

    /**
     * Writes one element and returns the WordPress id the endpoint answered with.
     *
     * A `409` is reported as what it is here — the regression under test — instead of as
     * a generic bad status, because that is the single line someone reads first when the
     * run goes red.
     *
     * @throws \RuntimeException When the endpoint does not answer `200` with one id.
     */
    private function saveOne(string $path, array $element, string $label): int
    {
        $response = $this->request('POST', $path, [$element]);
        $body = $response['body'];

        if ($response['status'] === 409) {
            $code = is_array($body) ? (string) ($body['code'] ?? '') : '';
            $message = is_array($body) ? (string) ($body['message'] ?? '') : '';

            throw new \RuntimeException(
                "$label :: POST $path answered 409 $code -- two elements with different "
                . "local_key values and the same name must be able to coexist: $message"
            );
        }

        if ($response['status'] !== 200) {
            throw new \RuntimeException(
                "$label :: POST $path answered {$response['status']}: "
                . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $id = is_array($body) ? (int) ($body[0] ?? 0) : 0;
        if ($id <= 0) {
            throw new \RuntimeException(
                "$label :: POST $path did not return an id: "
                . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $id;
    }



    // -------------------------------------------------------------------- fixture

    /** Creates the post type the content half of the test writes into. */
    private function createFixture(): void
    {
        $this->requireOk('POST', '/post-types', [[
            'post_type' => self::POST_TYPE,
            'singular_label' => 'On Page Test Same Name',
            'plural_label' => 'On Page Test Same Name',
            'supports' => ['title', 'editor'],
        ]]);

        echo "  fixture  post type '" . self::POST_TYPE . "' created\n";
    }

    /** One `POST /posts` element: only what the title guard needs. */
    private static function postPayload(string $local_key, string $title): array
    {
        return [
            'type' => self::POST_TYPE,
            'local_key' => $local_key,
            'title' => $title,
            'status' => 'publish',
        ];
    }

    /** One `POST /woocommerce/products` element: only what the title guard needs. */
    private static function productPayload(string $local_key, string $name): array
    {
        return [
            'local_key' => $local_key,
            'name' => $name,
            'status' => 'publish',
            'props' => ['product_type' => 'simple'],
        ];
    }



    // -------------------------------------------------------------------- contents

    /** Runs the whole scenario against `POST /posts`. */
    private function assertPostsAcceptSameTitle(): void
    {
        $first_id = $this->saveOne('/posts', self::postPayload(self::POST_KEYS['first'], self::SHARED_TITLE), 'post.insert.first');
        echo "  post     first created with id $first_id\n";

        // The regression: a second, unrelated element carrying the same name.
        $second_id = $this->saveOne('/posts', self::postPayload(self::POST_KEYS['namesake'], self::SHARED_TITLE), 'post.insert.namesake');
        echo "  post     namesake created with id $second_id\n";

        // Two rows, not one: the fix must let the second element in, never make it adopt
        // the first one, which would silently merge two distinct elements.
        $this->check('post.id.distinct', true, $first_id !== $second_id);

        $this->checkPost('post.reread.first', self::POST_KEYS['first'], $first_id, self::SHARED_TITLE);
        $this->checkPost('post.reread.namesake', self::POST_KEYS['namesake'], $second_id, self::SHARED_TITLE);

        // Re-import, the shape every recurring import takes: both resolve by local_key and
        // must land on their own row, without creating a third one.
        $this->check(
            'post.reimport.first',
            $first_id,
            $this->saveOne('/posts', self::postPayload(self::POST_KEYS['first'], self::SHARED_TITLE), 'post.reimport.first')
        );
        $this->check(
            'post.reimport.namesake',
            $second_id,
            $this->saveOne('/posts', self::postPayload(self::POST_KEYS['namesake'], self::SHARED_TITLE), 'post.reimport.namesake')
        );

        // The update branch only re-checks the title when it changes, so renaming a third
        // element onto the shared name is what reaches it.
        $third_id = $this->saveOne('/posts', self::postPayload(self::POST_KEYS['third'], self::OTHER_TITLE), 'post.insert.third');
        $this->check('post.id.third.distinct', true, $third_id !== $first_id && $third_id !== $second_id);

        $this->check(
            'post.rename.onto.namesake',
            $third_id,
            $this->saveOne('/posts', self::postPayload(self::POST_KEYS['third'], self::SHARED_TITLE), 'post.rename.onto.namesake')
        );
        $this->checkPost('post.reread.renamed', self::POST_KEYS['third'], $third_id, self::SHARED_TITLE);
    }

    /** Reads one post back by `local_key` and asserts its id and title. */
    private function checkPost(string $label, string $local_key, int $expected_id, string $expected_title): void
    {
        $post = $this->requireOk(
            'GET',
            '/posts/' . rawurlencode($local_key),
            null,
            'keyfield=local_key&type=' . self::POST_TYPE
        );

        $post = is_array($post) ? $post : [];

        $this->check("$label.id", $expected_id, $post['id'] ?? null);
        $this->check("$label.title", $expected_title, $post['title'] ?? null);
        $this->check("$label.local_key", $local_key, $post['local_key'] ?? null);
    }



    // ------------------------------------------------------------------- products

    /** Runs the whole scenario against `POST /woocommerce/products`. */
    private function assertProductsAcceptSameTitle(): void
    {
        $first_id = $this->saveOne('/woocommerce/products', self::productPayload(self::PRODUCT_KEYS['first'], self::SHARED_TITLE), 'product.insert.first');
        echo "  product  first created with id $first_id\n";

        $second_id = $this->saveOne('/woocommerce/products', self::productPayload(self::PRODUCT_KEYS['namesake'], self::SHARED_TITLE), 'product.insert.namesake');
        echo "  product  namesake created with id $second_id\n";

        $this->check('product.id.distinct', true, $first_id !== $second_id);

        $this->checkProduct('product.reread.first', self::PRODUCT_KEYS['first'], $first_id, self::SHARED_TITLE);
        $this->checkProduct('product.reread.namesake', self::PRODUCT_KEYS['namesake'], $second_id, self::SHARED_TITLE);

        // Unlike posts, the product update re-checks the title on every payload that
        // carries a name, so this plain re-import already reaches the update branch.
        $this->check(
            'product.reimport.first',
            $first_id,
            $this->saveOne('/woocommerce/products', self::productPayload(self::PRODUCT_KEYS['first'], self::SHARED_TITLE), 'product.reimport.first')
        );
        $this->check(
            'product.reimport.namesake',
            $second_id,
            $this->saveOne('/woocommerce/products', self::productPayload(self::PRODUCT_KEYS['namesake'], self::SHARED_TITLE), 'product.reimport.namesake')
        );

        $third_id = $this->saveOne('/woocommerce/products', self::productPayload(self::PRODUCT_KEYS['third'], self::OTHER_TITLE), 'product.insert.third');
        $this->check('product.id.third.distinct', true, $third_id !== $first_id && $third_id !== $second_id);

        $this->check(
            'product.rename.onto.namesake',
            $third_id,
            $this->saveOne('/woocommerce/products', self::productPayload(self::PRODUCT_KEYS['third'], self::SHARED_TITLE), 'product.rename.onto.namesake')
        );
        $this->checkProduct('product.reread.renamed', self::PRODUCT_KEYS['third'], $third_id, self::SHARED_TITLE);
    }

    /** Reads one product back by `local_key` and asserts its id and title. */
    private function checkProduct(string $label, string $local_key, int $expected_id, string $expected_title): void
    {
        $items = $this->requireOk('GET', '/woocommerce/products', null, 'local_key=' . rawurlencode($local_key));
        if (!is_array($items) || count($items) !== 1 || !is_array($items[0] ?? null)) {
            throw new \RuntimeException(
                "GET /woocommerce/products?local_key=$local_key should have returned exactly one product: "
                . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $product = $items[0];

        $this->check("$label.id", $expected_id, $product['id'] ?? null);
        $this->check("$label.title", $expected_title, $product['title'] ?? null);
        $this->check("$label.local_key", $local_key, $product['local_key'] ?? null);
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
     * Checks the site answers, accepts the token and has WooCommerce, before anything
     * gets created.
     *
     * Without it a wrong URL or token would surface as several teardown warnings before
     * the first real error, instead of one line saying what is actually wrong.
     *
     * @throws \RuntimeException When the site is unreachable, rejects the token or has no WooCommerce.
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

        // Half the test is on products, and WooCommerce's absence would otherwise surface
        // deep inside the first save, as a 500 that reads like a plugin bug.
        $response = $this->request('GET', '/woocommerce/products', null, 'local_key=' . self::PRODUCT_KEYS['first']);
        if ($response['status'] === 500) {
            throw new \RuntimeException(
                'the site answered 500 on GET /woocommerce/products: '
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ' (WooCommerce not active on the test site?)'
            );
        }
    }

    /**
     * Removes the products, the posts and the post type, in that order.
     *
     * `?ignore=1` everywhere, and transport errors are downgraded to a warning: teardown
     * runs from a `finally`, so anything it threw would replace the failure the run is
     * already reporting with a less useful one.
     */
    private function teardown(): void
    {
        $post_elements = array_map(
            fn(string $local_key): array => ['local_key' => $local_key, 'type' => self::POST_TYPE],
            array_values(self::POST_KEYS)
        );

        $calls = [
            ['/woocommerce/products', array_values(self::PRODUCT_KEYS)],
            ['/posts', $post_elements],
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
        echo "test     distinct elements with the same name on POST /posts and POST /woocommerce/products\n";
        echo "site     $this->base_url\n";

        try {
            $this->preflight();

            // A previous interrupted run would otherwise collide with the fixture.
            $this->teardown();

            try {
                $this->createFixture();

                // The two halves are independent: a 409 on the content side must not hide
                // whether the product side is fixed too, and vice versa.
                foreach (['assertPostsAcceptSameTitle', 'assertProductsAcceptSameTitle'] as $scenario) {
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
                    echo "  cleanup  products, posts and post type removed\n";
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

        echo "\nPASSED: two same-named elements with different local_key values coexist, on insert and on update\n";

        return 0;
    }
}



if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(DuplicateTitleDistinctLocalKeys::fromEnv()->run());
}
