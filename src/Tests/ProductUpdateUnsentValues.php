<?php



namespace OnPage\Tests;



use OnPage\Env;
use OnPage\Tests\Support\Audit;
use OnPage\Tests\Support\Keep;



require_once dirname(__DIR__) . '/Env.php';
require_once __DIR__ . '/Support/Audit.php';
require_once __DIR__ . '/Support/Keep.php';



/**
 * End-to-end check of what a product update does with values the payload leaves out, sends
 * as null or shortens.
 *
 *   status      an update without `status` keeps the product's status. It used to fall
 *               back to `publish` on every save, so a draft was published again.
 *   props null  `null` on an enum or boolean prop (`stock_status`, `backorders`,
 *               `catalog_visibility`, `tax_status`, `featured`, `reviews_allowed`, …)
 *               keeps the stored value. It used to reset it to a default or to false.
 *               `null` on a plain prop such as `sku` still clears it.
 *   attributes  `attributes: null` removes every attribute, global `pa_*` ones included.
 *               It used to remove only the custom ones. Product attributes are not in the
 *               GET response, so the test reads the effect the plugin documents instead: a
 *               variation built on the removed global attribute is made private.
 *   downloads   a parent's shorter `downloads` list, down to `[]`, reaches the variations
 *               that inherited the old one. They used to keep the dropped downloads. The
 *               variation response has no downloads, so the test reads `downloadable`, which
 *               the copy sets from the list.
 *
 * Create-and-delete flow: the attribute, its term, the products and the variation are
 * created here and removed again at the end, including when an assertion fails. Every
 * teardown call uses `?ignore=1`; the same teardown also runs before the fixture is built.
 * With `ONPAGE_TEST_KEEP=1` the final teardown is skipped (see `Support/Keep.php`).
 *
 * Configuration comes from the plugin `.env` (see `.env.example`):
 *
 *   WP_TEST_URL     base URL of the test WordPress site, e.g. http://localhost:8040
 *   WP_TEST_TOKEN   Bearer token, the same value stored in the `onpage_auth_token` option
 *
 * Run with: php src/Tests/ProductUpdateUnsentValues.php
 */
class ProductUpdateUnsentValues
{
    private const ATTRIBUTE_KEY = 'onpage-test-unsent-attr';
    private const ATTRIBUTE_SLUG = 'onpage-test-finish';
    private const ATTRIBUTE_TERM_KEY = 'onpage-test-unsent-attr-matte';
    private const ATTRIBUTE_TERM_SLUG = 'matte';

    private const DRAFT_PRODUCT_KEY = 'onpage-test-unsent-draft';
    private const PROPS_PRODUCT_KEY = 'onpage-test-unsent-props';
    private const VARIABLE_PRODUCT_KEY = 'onpage-test-unsent-variable';
    private const VARIATION_KEY = 'onpage-test-unsent-variation';
    private const DOWNLOADS_PRODUCT_KEY = 'onpage-test-unsent-downloads';
    private const DOWNLOADS_VARIATION_KEY = 'onpage-test-unsent-downloads-variation';

    /** Values sent on create for every prop where null now keeps the stored value. */
    private const KEPT_PROPS = [
        'manage_stock' => true,
        'stock_status' => 'outofstock',
        'backorders' => 'notify',
        'sold_individually' => true,
        'virtual' => true,
        'downloadable' => true,
        'featured' => true,
        'catalog_visibility' => 'hidden',
        'tax_status' => 'none',
        'reviews_allowed' => true,
    ];

    /**
     * What null used to set each prop to. The stored values must differ from these, or the
     * test would pass against the old code too.
     */
    private const OLD_NULL_RESULT = [
        'manage_stock' => false,
        'stock_status' => 'instock',
        'backorders' => 'no',
        'sold_individually' => false,
        'virtual' => false,
        'downloadable' => false,
        'featured' => false,
        'catalog_visibility' => 'visible',
        'tax_status' => 'taxable',
        'reviews_allowed' => false,
    ];

    /** @var array<string, mixed> Props as WooCommerce stored them on create. */
    private array $stored_props = [];

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

    /**
     * Reads the single item a `local_key` lookup returns.
     *
     * @throws \RuntimeException When the lookup does not return exactly one item.
     */
    private function requireOne(string $path, string $local_key): array
    {
        $items = $this->requireOk('GET', $path, null, 'local_key=' . rawurlencode($local_key));
        if (!is_array($items) || count($items) !== 1 || !is_array($items[0] ?? null)) {
            throw new \RuntimeException(
                "GET $path?local_key=$local_key should have returned exactly one item: "
                . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $items[0];
    }

    /** Saves one product element. */
    private function saveProduct(array $element): void
    {
        $this->requireOk('POST', '/woocommerce/products', [$element]);
    }



    // ------------------------------------------------------------------ scenarios

    /** An update without `status` keeps a draft a draft. */
    private function assertStatusIsKept(): void
    {
        $this->saveProduct([
            'local_key' => self::DRAFT_PRODUCT_KEY,
            'name' => 'On Page Test Unsent Draft',
            'status' => 'draft',
        ]);
        $this->check('status.created', 'draft', $this->requireOne('/woocommerce/products', self::DRAFT_PRODUCT_KEY)['status'] ?? null);

        $this->saveProduct([
            'local_key' => self::DRAFT_PRODUCT_KEY,
            'name' => 'On Page Test Unsent Draft, renamed',
        ]);
        $product = $this->requireOne('/woocommerce/products', self::DRAFT_PRODUCT_KEY);
        $this->check('status.kept', 'draft', $product['status'] ?? null);
        $this->check('status.update.applied', 'On Page Test Unsent Draft, renamed', $product['title'] ?? null);

        // A new product still defaults to publish.
        $this->saveProduct([
            'local_key' => self::PROPS_PRODUCT_KEY,
            'name' => 'On Page Test Unsent Props',
            'props' => self::KEPT_PROPS + ['sku' => 'ONPAGE-TEST-UNSENT'],
        ]);
        $created = $this->requireOne('/woocommerce/products', self::PROPS_PRODUCT_KEY);
        $this->check('status.create.default', 'publish', $created['status'] ?? null);

        // WooCommerce derives some props from others (with stock managed, no quantity and
        // backorders allowed, stock_status becomes onbackorder), so the reference is what
        // it stored, not what was sent.
        $woocommerce = is_array($created['woocommerce'] ?? null) ? $created['woocommerce'] : [];
        foreach (array_keys(self::KEPT_PROPS) as $key) {
            $this->stored_props[$key] = $woocommerce[$key] ?? null;
            $this->check(
                "props.created.$key.differs.from.old.null",
                true,
                self::normalize($this->stored_props[$key]) !== self::normalize(self::OLD_NULL_RESULT[$key])
            );
        }
    }

    /** `null` keeps enum and boolean props, and still clears a plain prop. */
    private function assertNullPropsAreKept(): void
    {
        $props = array_fill_keys(array_keys(self::KEPT_PROPS), null) + ['sku' => null];
        $this->saveProduct([
            'local_key' => self::PROPS_PRODUCT_KEY,
            'name' => 'On Page Test Unsent Props',
            'props' => $props,
        ]);

        $woocommerce = $this->requireOne('/woocommerce/products', self::PROPS_PRODUCT_KEY)['woocommerce'] ?? [];
        foreach ($this->stored_props as $key => $value) {
            $this->check("props.null.kept.$key", $value, $woocommerce[$key] ?? null);
        }
        $this->check('props.null.clears.sku', '', $woocommerce['sku'] ?? null);
    }

    /** `attributes: null` removes the global attribute too, so its variation goes private. */
    private function assertNullAttributesClearGlobals(): void
    {
        $this->requireOk('POST', '/woocommerce/attributes', [[
            'local_key' => self::ATTRIBUTE_KEY,
            'name' => 'On Page Test Finish',
            'slug' => self::ATTRIBUTE_SLUG,
            'type' => 'select',
        ]]);
        $this->requireOk('POST', '/woocommerce/attributes/' . self::ATTRIBUTE_SLUG . '/terms', [[
            'local_key' => self::ATTRIBUTE_TERM_KEY,
            'name' => 'Matte',
            'slug' => self::ATTRIBUTE_TERM_SLUG,
        ]]);

        $global_key = 'pa_' . self::ATTRIBUTE_SLUG;
        $this->saveProduct([
            'local_key' => self::VARIABLE_PRODUCT_KEY,
            'name' => 'On Page Test Unsent Variable',
            'status' => 'publish',
            'props' => ['product_type' => 'variable'],
            'attributes' => [$global_key => [self::ATTRIBUTE_TERM_SLUG], 'Size' => ['S']],
        ]);
        $this->requireOk('POST', '/woocommerce/variant-products', [[
            'local_key' => self::VARIATION_KEY,
            'parent' => self::VARIABLE_PRODUCT_KEY,
            'attributes' => [$global_key => self::ATTRIBUTE_TERM_SLUG],
            'props' => ['regular_price' => '10'],
            'status' => 'publish',
        ]]);
        $this->check('attributes.variation.created', 'publish', $this->requireOne('/woocommerce/variant-products', self::VARIATION_KEY)['status'] ?? null);

        $this->saveProduct([
            'local_key' => self::VARIABLE_PRODUCT_KEY,
            'name' => 'On Page Test Unsent Variable',
            'attributes' => null,
        ]);

        $this->check('attributes.null.variation.private', 'private', $this->requireOne('/woocommerce/variant-products', self::VARIATION_KEY)['status'] ?? null);

        $product = $this->requireOne('/woocommerce/products', self::VARIABLE_PRODUCT_KEY);
        $global_terms = array_filter(
            is_array($product['terms'] ?? null) ? $product['terms'] : [],
            fn(mixed $term): bool => is_array($term) && ($term['taxonomy'] ?? null) === $global_key
        );
        $this->check('attributes.null.global.terms', 0, count($global_terms));
    }

    /**
     * A shorter parent `downloads` list reaches the variation. Both steps matter: [a, b] then
     * [a] used to leave a fingerprint of [a, b] on the variation, so the final [] took it for
     * one a site admin had edited and skipped it.
     */
    private function assertShorterDownloadsReachVariations(): void
    {
        // Under the site's uploads URL, so the plugin links the file instead of importing it.
        $download = fn(string $id): array => [
            'id' => $id,
            'file' => $this->base_url . "/wp-content/uploads/onpage-test-$id.pdf",
            'name' => "Datasheet $id",
        ];
        $save = fn(array $downloads) => $this->saveProduct([
            'local_key' => self::DOWNLOADS_PRODUCT_KEY,
            'name' => 'On Page Test Unsent Downloads',
            'props' => ['product_type' => 'variable'],
            'attributes' => ['Size' => ['S']],
            'downloads' => $downloads,
        ]);
        $downloadable = fn(): mixed => $this->requireOne('/woocommerce/variant-products', self::DOWNLOADS_VARIATION_KEY)['woocommerce']['downloadable'] ?? null;

        $save([$download('a'), $download('b')]);
        $this->requireOk('POST', '/woocommerce/variant-products', [[
            'local_key' => self::DOWNLOADS_VARIATION_KEY,
            'parent' => self::DOWNLOADS_PRODUCT_KEY,
            'attributes' => ['Size' => 'S'],
            'props' => ['regular_price' => '10'],
        ]]);
        $this->check('downloads.variation.inherits', true, $downloadable());

        $save([$download('a')]);
        $save([]);
        $this->check('downloads.emptied.variation.cleared', false, $downloadable());
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
     * Flattens scalars to strings before comparing: WooCommerce returns booleans and ids in
     * more than one type, and the test is about the value, not its JSON type.
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
     * Checks the site answers, accepts the token and has WooCommerce, before anything is created.
     *
     * @throws \RuntimeException When the site is unreachable, rejects the token or has no WooCommerce.
     */
    private function preflight(): void
    {
        // The lookup answers 404 when the product does not exist yet, which is the normal case.
        $response = $this->request('GET', '/woocommerce/products', null, 'local_key=' . self::DRAFT_PRODUCT_KEY);
        if ($response['status'] !== 200 && $response['status'] !== 404) {
            throw new \RuntimeException(
                "the site answered {$response['status']} on GET /woocommerce/products: "
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ' (wrong WP_TEST_URL or WP_TEST_TOKEN, or WooCommerce not active?)'
            );
        }
    }

    /**
     * Removes the variation, the products, the attribute term and the attribute, in that order.
     *
     * `?ignore=1` everywhere, and transport errors are downgraded to a warning: teardown runs
     * from a `finally`, so anything it threw would hide the failure the run is reporting.
     */
    private function teardown(): void
    {
        $calls = [
            ['/woocommerce/variant-products', [self::VARIATION_KEY, self::DOWNLOADS_VARIATION_KEY]],
            ['/woocommerce/products', [self::DRAFT_PRODUCT_KEY, self::PROPS_PRODUCT_KEY, self::VARIABLE_PRODUCT_KEY, self::DOWNLOADS_PRODUCT_KEY]],
            ['/woocommerce/attributes/' . self::ATTRIBUTE_SLUG . '/terms', [self::ATTRIBUTE_TERM_KEY]],
            ['/woocommerce/attributes', [self::ATTRIBUTE_KEY]],
        ];

        foreach ($calls as [$path, $body]) {
            try {
                $this->request('DELETE', $path, $body, 'ignore=1');
            } catch (\RuntimeException $exception) {
                fwrite(STDERR, "  warning  cleanup of $path failed: {$exception->getMessage()}\n");
            }
        }
    }

    /** Prints the message on stderr and stops the run. Only before anything is created. */
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
        echo "test     product update: omitted status, null props, null attributes and shorter downloads\n";
        echo "site     $this->base_url\n";

        try {
            $this->preflight();

            // A previous interrupted run would otherwise collide with the fixture.
            $this->teardown();

            try {
                // Independent scenarios: one failing must not hide the others.
                foreach (['assertStatusIsKept', 'assertNullPropsAreKept', 'assertNullAttributesClearGlobals', 'assertShorterDownloadsReachVariations'] as $scenario) {
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
                    echo "  cleanup  variations, products and attribute removed\n";
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

        echo "\nPASSED: omitted status and null enum/boolean props are kept, null attributes clear every attribute, a shorter downloads list reaches the variations\n";

        return 0;
    }
}



if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(ProductUpdateUnsentValues::fromEnv()->run());
}
