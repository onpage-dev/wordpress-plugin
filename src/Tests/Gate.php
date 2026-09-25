<?php



namespace OnPage\Tests;



use OnPage\Env;
use OnPage\Tests\Support\Audit;



require_once dirname(__DIR__) . '/Env.php';
require_once __DIR__ . '/Support/Audit.php';



/**
 * Gate of the suite: wipes every WooCommerce entity from the test site before any test runs.
 *
 * `bin/test-launcher` runs it first, whatever filter it was given, and stops the whole run
 * when it fails: a test started on a catalogue that could not be emptied would only report
 * collisions with data it never created.
 *
 * It removes **everything**, not only what the tests create:
 *
 *   1. products and variations, of any status, trash included;
 *   2. global attributes, and with them the terms of their `pa_*` taxonomies;
 *   3. every term of `product_tag`, `product_brand` and `product_cat`.
 *
 * The only survivor is WooCommerce's default product category (`Uncategorized`), which
 * WordPress refuses to delete: it is reported and left in place.
 *
 * Everything goes through the On Page® REST API, because the WooCommerce `DELETE`
 * endpoints only accept `local_key`s and a manually created element has none:
 *
 *   - products and variations are deleted by post type through `DELETE /posts`, plus
 *     every ID `GET /woocommerce/products` lists in its `translations` maps;
 *   - attributes without a `local_key` get a temporary one (`POST /woocommerce/attributes`
 *     with `id`), so that `DELETE /woocommerce/attributes` can reach them;
 *   - terms are deleted by ID through `DELETE /terms`, translations included.
 *
 * After the wipe every listing is read again: anything still there fails the gate.
 *
 * Configuration comes from the plugin `.env` (see `.env.example`):
 *
 *   WP_TEST_URL     base URL of the test WordPress site, e.g. http://localhost:8040
 *   WP_TEST_TOKEN   Bearer token, the same value stored in the `onpage_auth_token` option
 *
 * Destructive by design: point it at a test site, never at production.
 *
 * Run with: php src/Tests/Gate.php
 */
class Gate
{
    /** Post types removed wholesale; variations first, so no parent is deleted under them. */
    private const POST_TYPES = ['product_variation', 'product'];

    /** Term taxonomies emptied through `DELETE /terms`; `product_cat` last, see wipeCategories(). */
    private const FLAT_TAXONOMIES = ['product_tag', 'product_brand'];

    /** Prefix of the temporary `local_key` given to attributes that have none. */
    private const ATTRIBUTE_KEY_PREFIX = 'onpage-gate-attribute-';

    private string $base_url;
    private string $token;

    /** @var list<int> `product_cat` terms WordPress refused to delete (the default category). */
    private array $kept_categories = [];

    /** @var list<string> Problems found during the run. */
    private array $failures = [];



    private function __construct(string $base_url, string $token)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->token = $token;
    }



    // ------------------------------------------------------------------ trasporto

    /**
     * Performs one authenticated REST call against the test site, mirrored in the audit log.
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
        // Deleting a whole catalogue in one call can take a while on a large test site.
        curl_setopt($handle, CURLOPT_TIMEOUT, 600);

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

            throw new \RuntimeException("$method $url non raggiungibile: $error");
        }

        Audit::response($status, (string) $response, $elapsed);

        return ['status' => $status, 'body' => json_decode((string) $response, true)];
    }

    /**
     * Performs a call that must answer `200`.
     *
     * @throws \RuntimeException When the endpoint answers anything but `200`.
     */
    private function requireOk(string $method, string $path, mixed $body = null, string $query = ''): mixed
    {
        $response = $this->request($method, $path, $body, $query);
        if ($response['status'] !== 200) {
            throw new \RuntimeException(
                "$method $path ha risposto {$response['status']}: "
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $response['body'];
    }

    /** Performs a `GET` that must answer `200` with a JSON list. */
    private function requireList(string $path, string $query = ''): array
    {
        $body = $this->requireOk('GET', $path, null, $query);
        if (!is_array($body) || !array_is_list($body)) {
            throw new \RuntimeException("GET $path non ha risposto con una lista");
        }

        return $body;
    }

    /**
     * Lists every term of a taxonomy, or null when the taxonomy is not registered.
     *
     * `product_brand` only exists on WooCommerce versions that ship brands, so its absence
     * means there is nothing to wipe, not that the site is broken.
     */
    private function listTerms(string $taxonomy): ?array
    {
        $response = $this->request('GET', '/terms', null, 'taxonomy=' . rawurlencode($taxonomy));
        if ($response['status'] === 404) {
            return null;
        }

        if ($response['status'] !== 200 || !is_array($response['body'])) {
            throw new \RuntimeException(
                "GET /terms?taxonomy=$taxonomy ha risposto {$response['status']}: "
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $response['body'];
    }

    /**
     * IDs of every listed element and of all its WPML translations.
     *
     * The listings return one object per translation group, so without the `translations`
     * map the other languages would survive the wipe.
     *
     * @return list<int>
     */
    private static function idsWithTranslations(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = (int) ($item['id'] ?? 0);
            foreach ((array) ($item['translations'] ?? []) as $translation_id) {
                $ids[] = (int) $translation_id;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }



    // ---------------------------------------------------------------------- wipe

    /** Checks the site is reachable, the token valid and WooCommerce active. */
    private function preflight(): void
    {
        $response = $this->request('GET', '/post-types');
        if ($response['status'] !== 200) {
            throw new \RuntimeException(
                "il sito ha risposto {$response['status']} su GET /post-types: "
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ' (WP_TEST_URL o WP_TEST_TOKEN errati?)'
            );
        }

        $response = $this->request('GET', '/woocommerce/attributes');
        if ($response['status'] === 500) {
            throw new \RuntimeException(
                'il sito ha risposto 500 su GET /woocommerce/attributes: '
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ' (WooCommerce non attivo sul sito di prova?)'
            );
        }
    }

    /**
     * Deletes every product and variation.
     *
     * The post type strings catch every status, trash included; the listed IDs catch the
     * WPML translations the post type query, filtered on the current language, would miss.
     * Deleting a product also deletes its variations, hence `?ignore=1`.
     */
    private function wipeProducts(): void
    {
        $ids = array_merge(
            self::idsWithTranslations($this->requireList('/woocommerce/variant-products')),
            self::idsWithTranslations($this->requireList('/woocommerce/products'))
        );

        $this->requireOk('DELETE', '/posts', array_merge(self::POST_TYPES, $ids), 'ignore=1');
        echo "  prodotti   prodotti e varianti rimossi\n";
    }

    /**
     * Deletes every global attribute, and with it the terms of its `pa_*` taxonomy.
     *
     * `DELETE /woocommerce/attributes` only takes `local_key`s: an attribute created by
     * hand gets a temporary one first, through the explicit-`id` upsert.
     */
    private function wipeAttributes(): void
    {
        $attributes = $this->requireList('/woocommerce/attributes');
        if ($attributes === []) {
            echo "  attributi  nessun attributo da rimuovere\n";

            return;
        }

        $local_keys = [];
        $to_key = [];
        foreach ($attributes as $attribute) {
            $local_key = $attribute['local_key'] ?? null;
            if ($local_key !== null) {
                $local_keys[] = $local_key;

                continue;
            }

            $local_key = self::ATTRIBUTE_KEY_PREFIX . (int) $attribute['id'];
            $to_key[] = ['id' => (int) $attribute['id'], 'local_key' => $local_key];
            $local_keys[] = $local_key;
        }

        if ($to_key !== []) {
            $this->requireOk('POST', '/woocommerce/attributes', $to_key);
        }

        $this->requireOk('DELETE', '/woocommerce/attributes', array_values(array_unique($local_keys)), 'ignore=1');
        echo '  attributi  ' . count($attributes) . " attributo/i rimosso/i con i loro termini\n";
    }

    /** Deletes every term of the taxonomies that need no special handling. */
    private function wipeFlatTaxonomies(): void
    {
        foreach (self::FLAT_TAXONOMIES as $taxonomy) {
            $terms = $this->listTerms($taxonomy);
            if ($terms === null) {
                echo "  $taxonomy  tassonomia non registrata, saltata\n";

                continue;
            }

            $ids = self::idsWithTranslations($terms);
            if ($ids !== []) {
                $this->requireOk('DELETE', '/terms', $ids, 'taxonomy=' . rawurlencode($taxonomy) . '&ignore=1');
            }

            echo "  $taxonomy  " . count($ids) . " termine/i rimosso/i\n";
        }
    }

    /**
     * Deletes every product category except the default one.
     *
     * WordPress refuses to delete the default category and `DELETE /terms` stops at the
     * first failure, so categories go one call each: a `500 delete_failed` is recorded as
     * kept and the wipe moves on. verify() then checks that only those survived.
     */
    private function wipeCategories(): void
    {
        $terms = $this->listTerms('product_cat') ?? [];
        $ids = self::idsWithTranslations($terms);

        foreach ($ids as $id) {
            $response = $this->request('DELETE', '/terms', [$id], 'taxonomy=product_cat&ignore=1');
            if ($response['status'] === 200) {
                continue;
            }

            if ($response['status'] === 500 && ($response['body']['code'] ?? null) === 'delete_failed') {
                $this->kept_categories[] = $id;

                continue;
            }

            throw new \RuntimeException(
                "DELETE /terms [$id] ha risposto {$response['status']}: "
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $deleted = count($ids) - count($this->kept_categories);
        $kept = $this->kept_categories === [] ? '' : ', tenuta/e ' . implode(', ', $this->kept_categories) . ' (predefinita)';
        echo "  product_cat  $deleted categoria/e rimossa/e$kept\n";
    }

    /** Reads every listing again and records whatever survived the wipe. */
    private function verify(): void
    {
        $leftovers = [
            'prodotti' => $this->requireList('/woocommerce/products'),
            'varianti' => $this->requireList('/woocommerce/variant-products'),
            'attributi' => $this->requireList('/woocommerce/attributes'),
        ];

        foreach (self::FLAT_TAXONOMIES as $taxonomy) {
            $leftovers[$taxonomy] = $this->listTerms($taxonomy) ?? [];
        }

        $leftovers['product_cat'] = array_values(array_filter(
            $this->listTerms('product_cat') ?? [],
            fn(array $term): bool => !in_array((int) $term['id'], $this->kept_categories, true)
        ));

        foreach ($leftovers as $label => $items) {
            if ($items !== []) {
                $ids = implode(', ', array_map(static fn(array $item): string => (string) ($item['id'] ?? '?'), $items));
                $this->failures[] = "$label ancora presenti dopo la pulizia: $ids";
            }
        }
    }

    /** Prints the message on stderr and stops the run with a failing exit code. */
    private static function fatal(string $message): never
    {
        fwrite(STDERR, "ERRORE: $message\n");
        exit(1);
    }



    // ------------------------------------------------------------------------ run

    /** Builds the gate from `.env`, refusing to start without a target site. */
    public static function fromEnv(): self
    {
        if (!function_exists('curl_init')) {
            self::fatal("l'estensione PHP curl non e' disponibile");
        }

        $base_url = Env::get('WP_TEST_URL');
        $token = Env::get('WP_TEST_TOKEN');

        if (!is_string($base_url) || $base_url === '' || !is_string($token) || $token === '') {
            self::fatal('WP_TEST_URL e WP_TEST_TOKEN vanno valorizzati nel file .env (vedi .env.example)');
        }

        return new self($base_url, $token);
    }

    /** Runs the whole wipe and returns the process exit code. */
    public function run(): int
    {
        echo "gate     pulizia di tutte le entita' WooCommerce\n";
        echo "sito     $this->base_url\n";

        try {
            $this->preflight();

            // Products first: attributes and terms still attached to a product would
            // otherwise be deleted out from under it, one relationship at a time.
            $this->wipeProducts();
            $this->wipeAttributes();
            $this->wipeFlatTaxonomies();
            $this->wipeCategories();

            $this->verify();
        } catch (\RuntimeException $exception) {
            $this->failures[] = $exception->getMessage();
        }

        if ($this->failures !== []) {
            fwrite(STDERR, "\nFALLITO: " . count($this->failures) . " problema/i\n");
            foreach ($this->failures as $failure) {
                fwrite(STDERR, "  - $failure\n");
            }

            return 1;
        }

        echo "\nPASSATO: ambiente WooCommerce ripulito\n";

        return 0;
    }
}



if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(Gate::fromEnv()->run());
}
