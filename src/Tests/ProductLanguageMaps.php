<?php



namespace OnPage\Tests;



use OnPage\Env;
use OnPage\Tests\Support\Audit;
use OnPage\Tests\Support\Keep;



require_once dirname(__DIR__) . '/Env.php';
require_once __DIR__ . '/Support/Audit.php';
require_once __DIR__ . '/Support/Keep.php';



/**
 * End-to-end check of how `POST /woocommerce/products` spreads values over the WPML
 * translations: language maps that leave a language out, and payloads without any map.
 *
 *   partial attribute map  on update, `{"Finish": {"en": [...]}}` rewrites the English
 *                          attribute and keeps the Italian one, instead of giving it the
 *                          English options through the fallback language.
 *                          Product attributes are not in the GET response, so the test
 *                          reads them through variations: a translated variation is
 *                          checked against the options of its own translated parent.
 *   scalars only           a create with no language map makes one product, in the
 *                          default language, and no translation. An update keeps it so.
 *   scalar name, map field a scalar `name` next to a `short_description` map is the name
 *                          of every translation the map creates.
 *   scalar update          a scalar-only update of a translated product writes the value
 *                          on every existing translation and creates none.
 *
 * Needs WooCommerce and WPML with `en` (the default language) and `it` active on the test
 * site. Other active languages are fine: the test checks that none of them gets a product.
 *
 * Create-and-delete flow: the products and variations are created here and removed again
 * at the end, also when an assertion fails. With `ONPAGE_TEST_KEEP=1` the final cleanup is
 * skipped (see `Support/Keep.php`).
 *
 * Configuration comes from the plugin `.env` (see `.env.example`):
 *
 *   WP_TEST_URL     base URL of the test WordPress site, e.g. http://localhost:8040
 *   WP_TEST_TOKEN   Bearer token, the same value stored in the `onpage_auth_token` option
 *
 * Run with: php src/Tests/ProductLanguageMaps.php
 */
class ProductLanguageMaps
{
    private const DEFAULT_LANGUAGE = 'en';
    private const LANGUAGES = ['en', 'it'];

    private const PARTIAL_PRODUCT_KEY = 'onpage-test-maps-partial';
    private const KEPT_VARIATION_KEY = 'onpage-test-maps-variation-kept';
    private const LEAK_VARIATION_KEY = 'onpage-test-maps-variation-leak';
    private const SCALAR_PRODUCT_KEY = 'onpage-test-maps-scalar';
    private const MIXED_PRODUCT_KEY = 'onpage-test-maps-mixed';

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

    /** Saves one product element. */
    private function saveProduct(array $element): void
    {
        $this->requireOk('POST', '/woocommerce/products', [$element]);
    }

    /**
     * Reads every translation of a product, keyed by language.
     *
     * `?local_key=` returns the default-language representative with the `translations`
     * map; the other languages are read one by one through `?id=`.
     *
     * @return array<string, array> language => product
     *
     * @throws \RuntimeException When the product or one of its translations cannot be read.
     */
    private function readTranslations(string $local_key): array
    {
        $items = $this->requireOk('GET', '/woocommerce/products', null, 'local_key=' . rawurlencode($local_key));
        if (!is_array($items) || count($items) !== 1 || !is_array($items[0] ?? null)) {
            throw new \RuntimeException("GET /woocommerce/products?local_key=$local_key should have returned exactly one product: " . json_encode($items));
        }

        $representative = $items[0];
        $ids = is_array($representative['translations'] ?? null) ? $representative['translations'] : [];

        $translations = [];
        foreach ($ids as $language => $id) {
            if ((int) $id === (int) ($representative['id'] ?? 0)) {
                $translations[$language] = $representative;
                continue;
            }

            $found = $this->requireOk('GET', '/woocommerce/products', null, 'id=' . (int) $id);
            if (!is_array($found) || !is_array($found[0] ?? null)) {
                throw new \RuntimeException("translation '$language' (id $id) of '$local_key' cannot be read back");
            }

            $translations[$language] = $found[0];
        }

        return $translations;
    }



    // ------------------------------------------------------------------ scenarios

    /**
     * An attribute map without `it` rewrites `en` and keeps the Italian attribute.
     *
     * Two variations probe the Italian parent: `Opaco` must still be an option there, and
     * `Satin`, sent in English only, must not have leaked into it.
     */
    private function assertPartialAttributeMap(): void
    {
        $this->saveProduct([
            'local_key' => self::PARTIAL_PRODUCT_KEY,
            'name' => ['en' => 'On Page Test Maps Chair', 'it' => 'On Page Test Maps Sedia'],
            'status' => 'publish',
            'props' => ['product_type' => 'variable'],
            'attributes' => ['Finish' => ['en' => ['Glossy', 'Matte'], 'it' => ['Lucido', 'Opaco']]],
        ]);

        $created = $this->readTranslations(self::PARTIAL_PRODUCT_KEY);
        $this->check('partial.created.languages', self::LANGUAGES, self::sortedKeys($created));

        // `name` is required on every save; an English-only map keeps the Italian name too.
        $this->saveProduct([
            'local_key' => self::PARTIAL_PRODUCT_KEY,
            'name' => ['en' => 'On Page Test Maps Chair'],
            'attributes' => ['Finish' => ['en' => ['Glossy', 'Matte', 'Satin']]],
        ]);

        $updated = $this->readTranslations(self::PARTIAL_PRODUCT_KEY);
        $this->check('partial.update.languages', self::LANGUAGES, self::sortedKeys($updated));
        $this->check('partial.update.it.title.kept', 'On Page Test Maps Sedia', $updated['it']['title'] ?? null);

        $response = $this->request('POST', '/woocommerce/variant-products', [[
            'local_key' => self::KEPT_VARIATION_KEY,
            'parent' => self::PARTIAL_PRODUCT_KEY,
            'attributes' => ['Finish' => ['en' => 'Satin', 'it' => 'Opaco']],
            'props' => ['regular_price' => '10'],
            'status' => 'publish',
        ]]);
        $this->check(
            'partial.en.updated.it.kept',
            200,
            $response['status'],
            $response['status'] === 200 ? null : json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $it_parent_id = (int) ($created['it']['id'] ?? 0);
        $variations = $this->requireOk('GET', '/woocommerce/variant-products', null, 'parent_id=' . $it_parent_id);
        $variation = self::findByLocalKey(is_array($variations) ? $variations : [], self::KEPT_VARIATION_KEY);
        $this->check(
            'partial.it.variation.option',
            ['Opaco'],
            array_values(is_array($variation['attributes'] ?? null) ? $variation['attributes'] : [])
        );

        $response = $this->request('POST', '/woocommerce/variant-products', [[
            'local_key' => self::LEAK_VARIATION_KEY,
            'parent' => self::PARTIAL_PRODUCT_KEY,
            'attributes' => ['Finish' => ['en' => 'Glossy', 'it' => 'Satin']],
            'props' => ['regular_price' => '10'],
            'status' => 'publish',
        ]]);
        $message = is_array($response['body']) ? (string) ($response['body']['message'] ?? '') : '';
        $this->check(
            'partial.en.value.not.in.it',
            [400, true],
            [$response['status'], str_contains($message, "option 'Satin' is not enabled on the parent product")],
            json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /** A payload without language maps makes one default-language product, on create and update. */
    private function assertScalarsOnly(): void
    {
        $this->saveProduct([
            'local_key' => self::SCALAR_PRODUCT_KEY,
            'name' => 'On Page Test Maps Scalar Lamp',
            'short_description' => 'Scalar short',
            'long_description' => 'Scalar long',
            'status' => 'publish',
            'props' => ['product_type' => 'simple', 'sku' => 'ONPAGE-TEST-MAPS-SCALAR', 'regular_price' => '10'],
            'attributes' => ['Material' => ['Steel']],
        ]);

        $created = $this->readTranslations(self::SCALAR_PRODUCT_KEY);
        $this->check('scalar.create.languages', [self::DEFAULT_LANGUAGE], self::sortedKeys($created));

        $product = $created[self::DEFAULT_LANGUAGE] ?? [];
        $this->check('scalar.create.title', 'On Page Test Maps Scalar Lamp', $product['title'] ?? null);
        $this->check('scalar.create.short_description', 'Scalar short', $product['short_description'] ?? null);
        $this->check('scalar.create.long_description', 'Scalar long', $product['long_description'] ?? null);
        $this->check('scalar.create.sku', 'ONPAGE-TEST-MAPS-SCALAR', $product['woocommerce']['sku'] ?? null);
        $this->check('scalar.create.regular_price', '10', $product['woocommerce']['regular_price'] ?? null);

        $this->saveProduct([
            'local_key' => self::SCALAR_PRODUCT_KEY,
            'name' => 'On Page Test Maps Scalar Lamp, renamed',
            'props' => ['regular_price' => '11'],
        ]);

        $updated = $this->readTranslations(self::SCALAR_PRODUCT_KEY);
        $this->check('scalar.update.languages', [self::DEFAULT_LANGUAGE], self::sortedKeys($updated));

        $product = $updated[self::DEFAULT_LANGUAGE] ?? [];
        $this->check('scalar.update.same.product', $created[self::DEFAULT_LANGUAGE]['id'] ?? null, $product['id'] ?? null);
        $this->check('scalar.update.title', 'On Page Test Maps Scalar Lamp, renamed', $product['title'] ?? null);
        $this->check('scalar.update.regular_price', '11', $product['woocommerce']['regular_price'] ?? null);
        $this->check('scalar.update.short_description.kept', 'Scalar short', $product['short_description'] ?? null);
    }

    /**
     * A scalar name beside a map is shared by the translations the map creates. A later
     * scalar-only update writes every existing translation and creates none.
     */
    private function assertScalarsBesideMaps(): void
    {
        $short = ['en' => 'Mixed short', 'it' => 'Breve misto'];
        $this->saveProduct([
            'local_key' => self::MIXED_PRODUCT_KEY,
            'name' => 'On Page Test Maps Mixed Lamp',
            'short_description' => $short,
            'status' => 'publish',
            'props' => ['product_type' => 'simple', 'regular_price' => '20'],
        ]);

        $created = $this->readTranslations(self::MIXED_PRODUCT_KEY);
        $this->check('mixed.create.languages', self::LANGUAGES, self::sortedKeys($created));
        foreach (self::LANGUAGES as $language) {
            $product = $created[$language] ?? [];
            $this->check("mixed.create.$language.title", 'On Page Test Maps Mixed Lamp', $product['title'] ?? null);
            $this->check("mixed.create.$language.short_description", $short[$language], $product['short_description'] ?? null);
            $this->check("mixed.create.$language.regular_price", '20', $product['woocommerce']['regular_price'] ?? null);
        }

        $this->saveProduct([
            'local_key' => self::MIXED_PRODUCT_KEY,
            'name' => 'On Page Test Maps Mixed Lamp, renamed',
            'props' => ['regular_price' => '21'],
        ]);

        $updated = $this->readTranslations(self::MIXED_PRODUCT_KEY);
        $this->check('mixed.update.languages', self::LANGUAGES, self::sortedKeys($updated));
        foreach (self::LANGUAGES as $language) {
            $product = $updated[$language] ?? [];
            $this->check("mixed.update.$language.same.product", $created[$language]['id'] ?? null, $product['id'] ?? null);
            $this->check("mixed.update.$language.title", 'On Page Test Maps Mixed Lamp, renamed', $product['title'] ?? null);
            $this->check("mixed.update.$language.regular_price", '21', $product['woocommerce']['regular_price'] ?? null);
            $this->check("mixed.update.$language.short_description.kept", $short[$language], $product['short_description'] ?? null);
        }
    }



    // --------------------------------------------------------------------- support

    /** Records one comparison, printing the passing ones and collecting the rest. */
    private function check(string $label, mixed $expected, mixed $actual, ?string $detail = null): void
    {
        if (self::normalize($expected) === self::normalize($actual)) {
            echo "  ok       $label\n";

            return;
        }

        $this->failures[] = sprintf(
            '%s: expected %s, got %s%s',
            $label,
            json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $detail === null ? '' : " ($detail)"
        );
        echo "  FAILED   $label\n";
    }

    /**
     * Flattens scalars to strings before comparing: WooCommerce returns prices and ids in
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

    /** @return list<string> The languages of a translation map, sorted to compare as a set. */
    private static function sortedKeys(array $translations): array
    {
        $languages = array_map('strval', array_keys($translations));
        sort($languages);

        return $languages;
    }

    /** Picks the item carrying a given `local_key` out of a listing, or null. */
    private static function findByLocalKey(array $items, string $local_key): ?array
    {
        foreach ($items as $item) {
            if (is_array($item) && (string) ($item['local_key'] ?? '') === $local_key) {
                return $item;
            }
        }

        return null;
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
     * Removes the variations, then the products.
     *
     * `?ignore=1` everywhere, and transport errors are downgraded to a warning: teardown runs
     * from a `finally`, so anything it threw would hide the failure the run is reporting.
     */
    private function teardown(): void
    {
        $calls = [
            ['/woocommerce/variant-products', [self::KEPT_VARIATION_KEY, self::LEAK_VARIATION_KEY]],
            ['/woocommerce/products', [self::PARTIAL_PRODUCT_KEY, self::SCALAR_PRODUCT_KEY, self::MIXED_PRODUCT_KEY]],
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
        echo "test     product translations: partial language maps and scalar payloads\n";
        echo "site     $this->base_url\n";

        try {
            $this->preflight();

            // A previous interrupted run would otherwise collide with the fixture.
            $this->teardown();

            try {
                // Independent scenarios: one failing must not hide the others.
                foreach (['assertPartialAttributeMap', 'assertScalarsOnly', 'assertScalarsBesideMaps'] as $scenario) {
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
                    echo "  cleanup  variations and products removed\n";
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

        echo "\nPASSED: partial maps keep the unsent languages, scalar payloads create no translation\n";

        return 0;
    }
}



if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(ProductLanguageMaps::fromEnv()->run());
}
