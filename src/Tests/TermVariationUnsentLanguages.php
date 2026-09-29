<?php



namespace OnPage\Tests;



use OnPage\Env;
use OnPage\Tests\Support\Audit;
use OnPage\Tests\Support\Keep;



require_once dirname(__DIR__) . '/Env.php';
require_once __DIR__ . '/Support/Audit.php';
require_once __DIR__ . '/Support/Keep.php';



/**
 * End-to-end check that an update of a term or a variation writes only the languages each
 * language map contains, like posts and products do.
 *
 *   term         on `POST /woocommerce/categories` (saved by `Term::save()`, like
 *                `POST /terms`), a `description` map without `it` rewrites the English
 *                description and keeps the Italian one. An omitted `description` keeps both.
 *   variation    on `POST /woocommerce/variant-products`, a `description` or `attributes`
 *                map without `it` keeps the Italian values, and a map without `en` keeps the
 *                variation in the parent's language (the default one) as it is. A shared
 *                price is still written to both.
 *
 * Needs WooCommerce and WPML with `en` (the default language) and `it` active on the test
 * site.
 *
 * Create-and-delete flow: the category, product and variation are created here and removed
 * again at the end, also when an assertion fails. With `ONPAGE_TEST_KEEP=1` the final cleanup
 * is skipped (see `Support/Keep.php`).
 *
 * Configuration comes from the plugin `.env` (see `.env.example`):
 *
 *   WP_TEST_URL     base URL of the test WordPress site, e.g. http://localhost:8040
 *   WP_TEST_TOKEN   Bearer token, the same value stored in the `onpage_auth_token` option
 *
 * Run with: php src/Tests/TermVariationUnsentLanguages.php
 */
class TermVariationUnsentLanguages
{
    private const DEFAULT_LANGUAGE = 'en';
    private const LANGUAGES = ['en', 'it'];

    private const CATEGORY_KEY = 'onpage-test-unsent-category';
    private const PRODUCT_KEY = 'onpage-test-unsent-product';
    private const VARIATION_KEY = 'onpage-test-unsent-variation';

    private const CATEGORY_NAME = ['en' => 'On Page Test Unsent Chairs', 'it' => 'On Page Test Unsent Sedie'];

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
     * Reads every translation of an item that a listing returns by `?local_key=`, keyed by
     * language. The translations the listing leaves out are read one by one through `?id=`.
     *
     * @return array<string, array> language => item
     *
     * @throws \RuntimeException When the item or one of its translations cannot be read.
     */
    private function readTranslations(string $path, string $local_key): array
    {
        $items = $this->requireOk('GET', $path, null, 'local_key=' . rawurlencode($local_key));
        $representative = self::findByLocalKey(is_array($items) ? $items : [], $local_key);
        if ($representative === null) {
            throw new \RuntimeException("GET $path?local_key=$local_key did not return the item");
        }

        $ids = is_array($representative['translations'] ?? null) ? $representative['translations'] : [];

        $translations = [];
        foreach ($ids as $language => $id) {
            if ((int) $id === (int) ($representative['id'] ?? 0)) {
                $translations[$language] = $representative;
                continue;
            }

            $found = $this->requireOk('GET', $path, null, 'id=' . (int) $id);
            $item = null;
            foreach (is_array($found) ? $found : [] as $candidate) {
                if (is_array($candidate) && (int) ($candidate['id'] ?? 0) === (int) $id) {
                    $item = $candidate;
                }
            }
            if ($item === null) {
                throw new \RuntimeException("translation '$language' (id $id) of '$local_key' cannot be read back from GET $path");
            }

            $translations[$language] = $item;
        }

        return $translations;
    }

    /**
     * Reads the test variation below each translation of the parent product, keyed by language.
     *
     * @return array<string, array> language => variation
     */
    private function readVariations(array $parents): array
    {
        $variations = [];
        foreach ($parents as $language => $parent) {
            $items = $this->requireOk('GET', '/woocommerce/variant-products', null, 'parent_id=' . (int) ($parent['id'] ?? 0));
            $variation = self::findByLocalKey(is_array($items) ? $items : [], self::VARIATION_KEY);
            if ($variation !== null) {
                $variations[$language] = $variation;
            }
        }

        return $variations;
    }



    // ------------------------------------------------------------------ scenarios

    /** A term description map without `it` keeps the Italian description; an omitted one keeps both. */
    private function assertTermKeepsUnsentLanguages(): void
    {
        $this->requireOk('POST', '/woocommerce/categories', [[
            'local_key' => self::CATEGORY_KEY,
            'name' => self::CATEGORY_NAME,
            'description' => ['en' => 'English description', 'it' => 'Descrizione italiana'],
        ]]);

        $created = $this->readTranslations('/woocommerce/categories', self::CATEGORY_KEY);
        $this->check('term.create.languages', self::LANGUAGES, self::sortedKeys($created));

        $this->requireOk('POST', '/woocommerce/categories', [[
            'local_key' => self::CATEGORY_KEY,
            'name' => self::CATEGORY_NAME,
            'description' => ['en' => 'English description, updated'],
        ]]);

        $updated = $this->readTranslations('/woocommerce/categories', self::CATEGORY_KEY);
        $this->check('term.partial.languages', self::LANGUAGES, self::sortedKeys($updated));
        $this->check('term.partial.en.description', 'English description, updated', $updated['en']['description'] ?? null);
        $this->check('term.partial.it.description.kept', 'Descrizione italiana', $updated['it']['description'] ?? null);
        foreach (self::LANGUAGES as $language) {
            $this->check("term.partial.$language.same.term", $created[$language]['id'] ?? null, $updated[$language]['id'] ?? null);
        }

        $this->requireOk('POST', '/woocommerce/categories', [[
            'local_key' => self::CATEGORY_KEY,
            'name' => self::CATEGORY_NAME,
        ]]);

        $omitted = $this->readTranslations('/woocommerce/categories', self::CATEGORY_KEY);
        $this->check('term.omitted.en.description.kept', 'English description, updated', $omitted['en']['description'] ?? null);
        $this->check('term.omitted.it.description.kept', 'Descrizione italiana', $omitted['it']['description'] ?? null);
    }

    /**
     * Variation maps without a language keep that language's values, the parent's language
     * included, while a shared price is written to both.
     */
    private function assertVariationKeepsUnsentLanguages(): void
    {
        $this->requireOk('POST', '/woocommerce/products', [[
            'local_key' => self::PRODUCT_KEY,
            'name' => ['en' => 'On Page Test Unsent Chair', 'it' => 'On Page Test Unsent Sedia'],
            'status' => 'publish',
            'props' => ['product_type' => 'variable'],
            'attributes' => ['Finish' => ['en' => ['Glossy', 'Matte'], 'it' => ['Lucido', 'Opaco']]],
        ]]);

        $parents = $this->readTranslations('/woocommerce/products', self::PRODUCT_KEY);
        $this->check('variation.parent.languages', self::LANGUAGES, self::sortedKeys($parents));

        $this->requireOk('POST', '/woocommerce/variant-products', [[
            'local_key' => self::VARIATION_KEY,
            'parent' => self::PRODUCT_KEY,
            'status' => 'publish',
            'description' => ['en' => 'English variation', 'it' => 'Variante italiana'],
            'attributes' => ['Finish' => ['en' => 'Glossy', 'it' => 'Lucido']],
            'props' => ['regular_price' => '10'],
        ]]);

        $created = $this->readVariations($parents);
        $this->check('variation.create.languages', self::LANGUAGES, self::sortedKeys($created));

        // Without `it`: the Italian description and attribute stay, the shared price does not.
        $this->requireOk('POST', '/woocommerce/variant-products', [[
            'local_key' => self::VARIATION_KEY,
            'parent' => self::PRODUCT_KEY,
            'description' => ['en' => 'English variation, updated'],
            'attributes' => ['Finish' => ['en' => 'Matte']],
            'props' => ['regular_price' => '12'],
        ]]);

        $partial = $this->readVariations($parents);
        $this->check('variation.partial.en.description', 'English variation, updated', $partial['en']['description'] ?? null);
        $this->check('variation.partial.it.description.kept', 'Variante italiana', $partial['it']['description'] ?? null);
        $this->check('variation.partial.en.attribute', ['Matte'], array_values((array) ($partial['en']['attributes'] ?? [])));
        $this->check('variation.partial.it.attribute.kept', ['Lucido'], array_values((array) ($partial['it']['attributes'] ?? [])));
        foreach (self::LANGUAGES as $language) {
            $this->check("variation.partial.$language.same.variation", $created[$language]['id'] ?? null, $partial[$language]['id'] ?? null);
            $this->check("variation.partial.$language.regular_price", '12', $partial[$language]['woocommerce']['regular_price'] ?? null);
        }

        // Without `en`, the language of the parent: the English variation is still saved, for
        // the shared price, but keeps its description.
        $this->requireOk('POST', '/woocommerce/variant-products', [[
            'local_key' => self::VARIATION_KEY,
            'parent' => self::PRODUCT_KEY,
            'description' => ['it' => 'Variante italiana, aggiornata'],
            'props' => ['regular_price' => '14'],
        ]]);

        $source = $this->readVariations($parents);
        $this->check('variation.source.' . self::DEFAULT_LANGUAGE . '.description.kept', 'English variation, updated', $source[self::DEFAULT_LANGUAGE]['description'] ?? null);
        $this->check('variation.source.it.description', 'Variante italiana, aggiornata', $source['it']['description'] ?? null);
        foreach (self::LANGUAGES as $language) {
            $this->check("variation.source.$language.regular_price", '14', $source[$language]['woocommerce']['regular_price'] ?? null);
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
     * Removes the variation, then the product and the category.
     *
     * `?ignore=1` everywhere, and transport errors are downgraded to a warning: teardown runs
     * from a `finally`, so anything it threw would hide the failure the run is reporting.
     */
    private function teardown(): void
    {
        $calls = [
            ['/woocommerce/variant-products', [self::VARIATION_KEY]],
            ['/woocommerce/products', [self::PRODUCT_KEY]],
            ['/woocommerce/categories', [self::CATEGORY_KEY]],
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
        echo "test     term and variation updates: language maps that leave a language out\n";
        echo "site     $this->base_url\n";

        try {
            $this->preflight();

            // A previous interrupted run would otherwise collide with the fixture.
            $this->teardown();

            try {
                // Independent scenarios: one failing must not hide the others.
                foreach (['assertTermKeepsUnsentLanguages', 'assertVariationKeepsUnsentLanguages'] as $scenario) {
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
                    echo "  cleanup  variation, product and category removed\n";
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

        echo "\nPASSED: terms and variations keep the languages an update leaves out\n";

        return 0;
    }
}



if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(TermVariationUnsentLanguages::fromEnv()->run());
}
