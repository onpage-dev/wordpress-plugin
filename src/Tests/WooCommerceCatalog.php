<?php



namespace OnPage\Tests;



use OnPage\Env;
use OnPage\Tests\Support\Audit;



require_once dirname(__DIR__) . '/Env.php';
require_once __DIR__ . '/Support/Audit.php';



/**
 * Baseline end-to-end check: ACF structures first, then a whole WooCommerce catalogue
 * written through the On Page® REST API.
 *
 * It is the baseline of the suite: it covers the one flow every import performs —
 * declare the field structures, then publish the data into them. When this fails,
 * whatever the other tests report is almost always the same failure seen through a
 * narrower field.
 *
 * The run has two halves, in this order:
 *
 *   1. **setup**: the custom taxonomy, the global attribute, the `product_brand`
 *      taxonomy and the ACF field groups that give every entity its own fields —
 *      product, variation, `product_cat`, `product_tag`, `product_brand`, the custom
 *      taxonomy and the attribute's own `pa_*` taxonomy.
 *   2. **publish**: brand, categories (parent and child), tag, attribute terms, a term
 *      of the custom taxonomy, a simple product, a variable product and its variants —
 *      each carrying values for the ACF fields declared in step 1.
 *
 * Every value is invented here. Unlike the equivalent test in the `connector-wordpress`
 * repository, nothing is read from On Page®: the test must be able to run against a bare
 * WordPress with only WooCommerce and ACF installed.
 *
 * Create-and-delete flow: everything created here is removed again at the end, including
 * when an assertion fails. Every teardown call uses `?ignore=1`, so a leftover from an
 * interrupted run never masks a real failure; the same teardown also runs before the
 * fixture is built.
 *
 * Configuration comes from the plugin `.env` (see `.env.example`):
 *
 *   WP_TEST_URL     base URL of the test WordPress site, e.g. http://localhost:8040
 *   WP_TEST_TOKEN   Bearer token, the same value stored in the `onpage_auth_token` option
 *
 * The target site must have WooCommerce and ACF active: without WooCommerce the
 * endpoints answer `500 woocommerce_required`, without ACF the plugin registers no
 * routes at all.
 *
 * Run with: php src/Tests/WooCommerceCatalog.php
 */
class WooCommerceCatalog
{
    /** Custom (non-WooCommerce) taxonomy registered on `product` by the setup phase. */
    private const LINE_TAXONOMY = 'onpage_test_linea';

    /** Unprefixed slug of the global WooCommerce attribute; its taxonomy is `pa_` + this. */
    private const ATTRIBUTE_SLUG = 'onpage-test-colore';
    private const ATTRIBUTE_TAXONOMY = 'pa_' . self::ATTRIBUTE_SLUG;

    /**
     * On Page® local keys of every entity the test writes.
     *
     * Strings, not integers, on purpose: a string local_key is the shape the API is
     * least often exercised with, and it reads back verbatim, which keeps the
     * assertions honest.
     */
    private const LK_BRAND = 'onpage-test-brand';
    private const LK_CATEGORY_PARENT = 'onpage-test-categoria-madre';
    private const LK_CATEGORY_CHILD = 'onpage-test-categoria-figlia';
    private const LK_TAG = 'onpage-test-tag';
    private const LK_ATTRIBUTE = 'onpage-test-attributo';
    private const LK_ATTRIBUTE_TERM_RED = 'onpage-test-colore-rosso';
    private const LK_ATTRIBUTE_TERM_BLUE = 'onpage-test-colore-blu';
    private const LK_LINE_TERM = 'onpage-test-linea-pro';
    private const LK_PRODUCT_SIMPLE = 'onpage-test-prodotto-semplice';
    private const LK_PRODUCT_VARIABLE = 'onpage-test-prodotto-variabile';
    private const LK_VARIANT_RED = 'onpage-test-variante-rossa-s';
    private const LK_VARIANT_BLUE = 'onpage-test-variante-blu-m';

    /** Titles of the ACF field groups, which are also how teardown deletes them. */
    private const GROUP_PRODUCT = 'On Page Test :: Campi prodotto';
    private const GROUP_VARIATION = 'On Page Test :: Campi variante';
    private const GROUP_CATEGORY = 'On Page Test :: Campi categoria';
    private const GROUP_TAG = 'On Page Test :: Campi tag';
    private const GROUP_BRAND = 'On Page Test :: Campi brand';
    private const GROUP_LINE = 'On Page Test :: Campi linea';
    private const GROUP_ATTRIBUTE = 'On Page Test :: Campi colore';

    /** The one ACF field every taxonomy in the fixture carries, so terms can be checked too. */
    private const TERM_FIELD = 'onpage_test_sottotitolo';

    private string $base_url;
    private string $token;

    /** @var list<string> Assertion failures collected during the run. */
    private array $failures = [];



    private function __construct(string $base_url, string $token)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->token = $token;
    }



    // ------------------------------------------------------------------ trasporto

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
        curl_setopt($handle, CURLOPT_TIMEOUT, 120);

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
     * Failures are raised, never `exit()`ed: PHP skips `finally` on exit, and skipping
     * it here would leave the whole catalogue behind on the site the run was pointed at.
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



    // -------------------------------------------------------------------- fixture

    /** The ACF field groups the fixture declares, one per entity the catalogue touches. */
    private static function fieldGroupPayloads(): array
    {
        // Every taxonomy gets the same single text field: the taxonomies differ in how
        // the plugin resolves them, not in what an ACF field on them has to do, so one
        // field per taxonomy is enough to prove the value reached the right term.
        $term_group = static fn(string $title, string $key, string $taxonomy): array => [
            'title' => $title,
            'key' => $key,
            'description' => 'Fixture del test src/Tests/WooCommerceCatalog.php',
            'locations' => [
                ['param' => 'taxonomy', 'operator' => '==', 'value' => $taxonomy],
            ],
            'fields' => [
                [
                    'key' => self::TERM_FIELD,
                    'name' => self::TERM_FIELD,
                    'label' => 'Sottotitolo',
                    'type' => 'text',
                ],
            ],
        ];

        return [
            [
                'title' => self::GROUP_PRODUCT,
                'key' => 'group_onpage_test_prodotto',
                'description' => 'Fixture del test src/Tests/WooCommerceCatalog.php',
                'locations' => [
                    ['param' => 'post_type', 'operator' => '==', 'value' => 'product'],
                ],
                // A scalar, a group and a repeater: the three value shapes a product
                // import actually sends, so one product covers all of them.
                'fields' => [
                    [
                        'key' => 'onpage_test_badge',
                        'name' => 'onpage_test_badge',
                        'label' => 'Badge',
                        'type' => 'text',
                    ],
                    [
                        'key' => 'onpage_test_scheda',
                        'name' => 'onpage_test_scheda',
                        'label' => 'Scheda',
                        'type' => 'group',
                        'sub_fields' => [
                            ['key' => 'titolo', 'name' => 'titolo', 'label' => 'Titolo', 'type' => 'text'],
                            ['key' => 'note', 'name' => 'note', 'label' => 'Note', 'type' => 'text'],
                        ],
                    ],
                    [
                        'key' => 'onpage_test_certificazioni',
                        'name' => 'onpage_test_certificazioni',
                        'label' => 'Certificazioni',
                        'type' => 'repeater',
                        'sub_fields' => [
                            ['key' => 'nome', 'name' => 'nome', 'label' => 'Nome', 'type' => 'text'],
                            ['key' => 'anno', 'name' => 'anno', 'label' => 'Anno', 'type' => 'text'],
                        ],
                    ],
                ],
            ],
            [
                'title' => self::GROUP_VARIATION,
                'key' => 'group_onpage_test_variante',
                'description' => 'Fixture del test src/Tests/WooCommerceCatalog.php',
                // Variations are a post type of their own, so their ACF fields are
                // scoped to `product_variation`, not to `product`.
                'locations' => [
                    ['param' => 'post_type', 'operator' => '==', 'value' => 'product_variation'],
                ],
                'fields' => [
                    [
                        'key' => 'onpage_test_materiale',
                        'name' => 'onpage_test_materiale',
                        'label' => 'Materiale',
                        'type' => 'text',
                    ],
                ],
            ],
            $term_group(self::GROUP_CATEGORY, 'group_onpage_test_categoria', 'product_cat'),
            $term_group(self::GROUP_TAG, 'group_onpage_test_tag', 'product_tag'),
            $term_group(self::GROUP_BRAND, 'group_onpage_test_brand', 'product_brand'),
            $term_group(self::GROUP_LINE, 'group_onpage_test_linea', self::LINE_TAXONOMY),
            $term_group(self::GROUP_ATTRIBUTE, 'group_onpage_test_colore', self::ATTRIBUTE_TAXONOMY),
        ];
    }

    /** The ACF values the simple product carries, and the ones read back from it. */
    private static function productFieldValues(): array
    {
        return [
            'onpage_test_badge' => 'Novita',
            'onpage_test_scheda' => [
                'titolo' => 'Scheda tecnica',
                'note' => 'Prodotto di prova, dati inventati',
            ],
            'onpage_test_certificazioni' => [
                ['nome' => 'Marcatura CE', 'anno' => '2024'],
                ['nome' => 'VOC A+', 'anno' => '2023'],
            ],
        ];
    }

    /**
     * Creates the structures the data phase writes into.
     *
     * Order matters and is the reason this is one method: `product_brand` is created
     * lazily by the first brand save and `pa_onpage-test-colore` by the attribute save,
     * so both must exist before the terms of the catalogue can carry ACF values. The
     * brand created here is deliberately bare — name and key only — and gets its ACF
     * value later, in the publish phase, which is also the upsert path every re-import
     * takes.
     */
    private function createStructures(): void
    {
        $this->requireOk('POST', '/taxonomies', [[
            'key' => self::LINE_TAXONOMY,
            'singular_label' => 'Linea',
            'plural_label' => 'Linee',
            'description' => 'Fixture del test src/Tests/WooCommerceCatalog.php',
            'object_type' => ['product'],
            'hierarchical' => true,
        ]]);

        $this->requireOk('POST', '/woocommerce/attributes', [[
            'local_key' => self::LK_ATTRIBUTE,
            'name' => 'On Page Test Colore',
            'slug' => self::ATTRIBUTE_SLUG,
            'type' => 'select',
        ]]);

        $this->requireOk('POST', '/woocommerce/brands', [[
            'local_key' => self::LK_BRAND,
            'name' => 'On Page Test Brand',
        ]]);

        $this->requireOk('POST', '/field-groups', self::fieldGroupPayloads());

        echo "  setup    tassonomia, attributo, brand e 7 field group ACF creati\n";
    }



    // -------------------------------------------------------------------- publish

    /** Writes brand, categories, tag, attribute terms and the custom taxonomy term. */
    private function publishTerms(): void
    {
        $this->requireOk('POST', '/woocommerce/brands', [[
            'local_key' => self::LK_BRAND,
            'name' => 'On Page Test Brand',
            'description' => 'Brand di prova',
            'acf_fields' => [self::TERM_FIELD => 'Sottotitolo del brand'],
        ]]);

        // Parent and child in one batch: the child references the parent by local_key,
        // which only resolves if the parent was already persisted by the same call.
        $this->requireOk('POST', '/woocommerce/categories', [
            [
                'local_key' => self::LK_CATEGORY_PARENT,
                'name' => 'On Page Test Arredo',
                'acf_fields' => [self::TERM_FIELD => 'Sottotitolo della categoria madre'],
            ],
            [
                'local_key' => self::LK_CATEGORY_CHILD,
                'name' => 'On Page Test Sedie',
                'parent' => self::LK_CATEGORY_PARENT,
                'acf_fields' => [self::TERM_FIELD => 'Sottotitolo della categoria figlia'],
            ],
        ]);

        $this->requireOk('POST', '/woocommerce/tags', [[
            'local_key' => self::LK_TAG,
            'name' => 'On Page Test In evidenza',
            'acf_fields' => [self::TERM_FIELD => 'Sottotitolo del tag'],
        ]]);

        $this->requireOk('POST', '/woocommerce/attributes/' . self::ATTRIBUTE_SLUG . '/terms', [
            [
                'local_key' => self::LK_ATTRIBUTE_TERM_RED,
                'name' => 'Rosso',
                'acf_fields' => [self::TERM_FIELD => 'Sottotitolo del rosso'],
            ],
            [
                'local_key' => self::LK_ATTRIBUTE_TERM_BLUE,
                'name' => 'Blu',
                'acf_fields' => [self::TERM_FIELD => 'Sottotitolo del blu'],
            ],
        ]);

        $this->requireOk('POST', '/terms', [[
            'taxonomy' => self::LINE_TAXONOMY,
            'local_key' => self::LK_LINE_TERM,
            'name' => 'On Page Test Linea Pro',
            'acf_fields' => [self::TERM_FIELD => 'Sottotitolo della linea'],
        ]]);

        echo "  dati     brand, 2 categorie, tag, 2 valori attributo e 1 termine di linea\n";
    }

    /**
     * Writes the two products and the two variants.
     *
     * The simple product carries every taxonomy the fixture declares: the dedicated
     * `brand`/`categories`/`tags` fields and, for the custom taxonomy, the generic
     * `terms` map, which is the only way to reach a taxonomy WooCommerce knows nothing
     * about.
     */
    private function publishProducts(): void
    {
        $this->requireOk('POST', '/woocommerce/products', [[
            'local_key' => self::LK_PRODUCT_SIMPLE,
            'name' => 'On Page Test Sedia',
            'status' => 'publish',
            'long_description' => 'Descrizione lunga della sedia di prova.',
            'short_description' => 'Descrizione breve della sedia di prova.',
            'props' => [
                'product_type' => 'simple',
                'sku' => 'ONPAGE-TEST-SEDIA',
                'regular_price' => '49.90',
                'stock_status' => 'instock',
            ],
            'brand' => self::LK_BRAND,
            'categories' => [self::LK_CATEGORY_CHILD],
            'tags' => [self::LK_TAG],
            'terms' => [self::LINE_TAXONOMY => [self::LK_LINE_TERM]],
            'attributes' => [
                'Materiale' => 'Legno',
                'Finitura' => ['Opaca', 'Lucida'],
            ],
            'acf_fields' => self::productFieldValues(),
        ]]);

        // `product_type: variable` makes every attribute sent here a variation
        // attribute, which is what the variants below are validated against.
        $this->requireOk('POST', '/woocommerce/products', [[
            'local_key' => self::LK_PRODUCT_VARIABLE,
            'name' => 'On Page Test Maglietta',
            'status' => 'publish',
            'props' => ['product_type' => 'variable'],
            'brand' => self::LK_BRAND,
            'categories' => [self::LK_CATEGORY_PARENT],
            'attributes' => [
                'Colore' => ['Rosso', 'Blu'],
                'Taglia' => ['S', 'M'],
            ],
        ]]);

        $this->requireOk('POST', '/woocommerce/variant-products', [
            [
                'local_key' => self::LK_VARIANT_RED,
                'parent' => self::LK_PRODUCT_VARIABLE,
                'attributes' => ['Colore' => 'Rosso', 'Taglia' => 'S'],
                'props' => ['sku' => 'ONPAGE-TEST-MAGLIETTA-R-S', 'regular_price' => '29.90'],
                'description' => 'Rosso / S',
                'status' => 'publish',
                'acf_fields' => ['onpage_test_materiale' => 'Cotone'],
            ],
            [
                'local_key' => self::LK_VARIANT_BLUE,
                'parent' => self::LK_PRODUCT_VARIABLE,
                'attributes' => ['Colore' => 'Blu', 'Taglia' => 'M'],
                'props' => ['sku' => 'ONPAGE-TEST-MAGLIETTA-B-M', 'regular_price' => '31.90'],
                'description' => 'Blu / M',
                'status' => 'publish',
                'acf_fields' => ['onpage_test_materiale' => 'Lino'],
            ],
        ]);

        echo "  dati     prodotto semplice, prodotto variabile e 2 varianti\n";
    }



    // ------------------------------------------------------------------ asserzioni

    /** Reads brand, categories, tag, attribute, attribute terms and custom term back. */
    private function assertTermsPersisted(): void
    {
        $brand = $this->requireOne('/woocommerce/brands', 'local_key=' . self::LK_BRAND, 'brand');
        $this->check('brand.name', 'On Page Test Brand', $brand['name'] ?? null);
        $this->check('brand.acf', 'Sottotitolo del brand', $brand['acf_fields'][self::TERM_FIELD] ?? null);

        $parent = $this->requireOne('/woocommerce/categories', 'local_key=' . self::LK_CATEGORY_PARENT, 'categoria madre');
        $child = $this->requireOne('/woocommerce/categories', 'local_key=' . self::LK_CATEGORY_CHILD, 'categoria figlia');
        $this->check('categoria.name', 'On Page Test Sedie', $child['name'] ?? null);
        $this->check('categoria.parent', $parent['id'] ?? null, $child['parent'] ?? null);
        $this->check('categoria.acf', 'Sottotitolo della categoria figlia', $child['acf_fields'][self::TERM_FIELD] ?? null);

        $tag = $this->requireOne('/woocommerce/tags', 'local_key=' . self::LK_TAG, 'tag');
        $this->check('tag.name', 'On Page Test In evidenza', $tag['name'] ?? null);
        $this->check('tag.acf', 'Sottotitolo del tag', $tag['acf_fields'][self::TERM_FIELD] ?? null);

        $attribute = $this->requireOne('/woocommerce/attributes', 'local_key=' . self::LK_ATTRIBUTE, 'attributo');
        $this->check('attributo.name', 'On Page Test Colore', $attribute['name'] ?? null);
        $this->check('attributo.taxonomy', self::ATTRIBUTE_TAXONOMY, $attribute['taxonomy'] ?? null);

        $attribute_terms = '/woocommerce/attributes/' . self::ATTRIBUTE_SLUG . '/terms';
        $red = $this->requireOne($attribute_terms, 'local_key=' . self::LK_ATTRIBUTE_TERM_RED, 'valore attributo rosso');
        $this->check('attributo.rosso.name', 'Rosso', $red['name'] ?? null);
        $this->check('attributo.rosso.acf', 'Sottotitolo del rosso', $red['acf_fields'][self::TERM_FIELD] ?? null);

        $blue = $this->requireOne($attribute_terms, 'local_key=' . self::LK_ATTRIBUTE_TERM_BLUE, 'valore attributo blu');
        $this->check('attributo.blu.name', 'Blu', $blue['name'] ?? null);

        // `GET /terms` has no local_key filter, so the custom taxonomy term is picked
        // out of the taxonomy listing by the key the test wrote.
        $line = $this->findByLocalKey(
            $this->requireOk('GET', '/terms', null, 'taxonomy=' . self::LINE_TAXONOMY),
            self::LK_LINE_TERM
        );
        if ($line === null) {
            throw new \RuntimeException("GET /terms non ha restituito il termine '" . self::LK_LINE_TERM . "'");
        }

        $this->check('linea.name', 'On Page Test Linea Pro', $line['name'] ?? null);
        $this->check('linea.acf', 'Sottotitolo della linea', $line['acf_fields'][self::TERM_FIELD] ?? null);
    }

    /** Reads the simple product back: native fields, ACF values and every taxonomy. */
    private function assertSimpleProductPersisted(): void
    {
        $product = $this->requireOne('/woocommerce/products', 'local_key=' . self::LK_PRODUCT_SIMPLE, 'prodotto semplice');

        $this->check('prodotto.title', 'On Page Test Sedia', $product['title'] ?? null);
        $this->check('prodotto.status', 'publish', $product['status'] ?? null);
        $this->check('prodotto.long_description', 'Descrizione lunga della sedia di prova.', $product['long_description'] ?? null);
        $this->check('prodotto.short_description', 'Descrizione breve della sedia di prova.', $product['short_description'] ?? null);
        $this->check('prodotto.product_type', 'simple', $product['woocommerce']['product_type'] ?? null);
        $this->check('prodotto.sku', 'ONPAGE-TEST-SEDIA', $product['woocommerce']['sku'] ?? null);
        $this->check('prodotto.regular_price', '49.90', $product['woocommerce']['regular_price'] ?? null);
        $this->check('prodotto.stock_status', 'instock', $product['woocommerce']['stock_status'] ?? null);

        foreach (self::productFieldValues() as $field_key => $expected) {
            $this->check("prodotto.acf.$field_key", $expected, $product['acf_fields'][$field_key] ?? null);
        }

        // The product attributes themselves are not part of the GET response; that they
        // were written is what the variants of the variable product below prove, since
        // a variation is rejected unless its parent declares the attribute.
        $terms = is_array($product['terms'] ?? null) ? $product['terms'] : [];
        $expected_terms = [
            'product_brand' => 'On Page Test Brand',
            'product_cat' => 'On Page Test Sedie',
            'product_tag' => 'On Page Test In evidenza',
            self::LINE_TAXONOMY => 'On Page Test Linea Pro',
        ];

        foreach ($expected_terms as $taxonomy => $name) {
            $assigned = array_values(array_filter(
                $terms,
                static fn(mixed $term): bool => is_array($term) && ($term['taxonomy'] ?? null) === $taxonomy
            ));

            $this->check(
                "prodotto.terms.$taxonomy",
                [$name],
                array_map(static fn(array $term): mixed => $term['name'] ?? null, $assigned)
            );
        }
    }

    /** Reads the variable product and its two variants back. */
    private function assertVariantsPersisted(): void
    {
        $variable = $this->requireOne('/woocommerce/products', 'local_key=' . self::LK_PRODUCT_VARIABLE, 'prodotto variabile');
        $this->check('variabile.product_type', 'variable', $variable['woocommerce']['product_type'] ?? null);

        $variants = $this->requireOk('GET', '/woocommerce/variant-products', null, 'parent=' . self::LK_PRODUCT_VARIABLE);
        $variants = is_array($variants) ? $variants : [];
        $this->check('varianti.numero', 2, count($variants));

        $expected = [
            self::LK_VARIANT_RED => [
                'attributes' => ['colore' => 'Rosso', 'taglia' => 'S'],
                'sku' => 'ONPAGE-TEST-MAGLIETTA-R-S',
                'price' => '29.90',
                'materiale' => 'Cotone',
            ],
            self::LK_VARIANT_BLUE => [
                'attributes' => ['colore' => 'Blu', 'taglia' => 'M'],
                'sku' => 'ONPAGE-TEST-MAGLIETTA-B-M',
                'price' => '31.90',
                'materiale' => 'Lino',
            ],
        ];

        foreach ($expected as $local_key => $values) {
            $variant = $this->findByLocalKey($variants, $local_key);
            if ($variant === null) {
                $this->failures[] = "variante '$local_key' non trovata fra quelle del parent";
                continue;
            }

            $this->check("$local_key.parent", self::LK_PRODUCT_VARIABLE, $variant['parent'] ?? null);
            $this->check("$local_key.sku", $values['sku'], $variant['woocommerce']['sku'] ?? null);
            $this->check("$local_key.regular_price", $values['price'], $variant['woocommerce']['regular_price'] ?? null);
            $this->check("$local_key.acf.materiale", $values['materiale'], $variant['acf_fields']['onpage_test_materiale'] ?? null);

            // WooCommerce stores variation attributes under the sanitised attribute
            // name, so the payload key `Colore` reads back as `colore`.
            $attributes = is_array($variant['attributes'] ?? null) ? $variant['attributes'] : [];
            foreach ($values['attributes'] as $attribute_key => $option) {
                $this->check("$local_key.attributes.$attribute_key", $option, $attributes[$attribute_key] ?? null);
            }
        }
    }



    // -------------------------------------------------------------------- supporto

    /**
     * Reads one entity by `local_key` from a listing endpoint.
     *
     * @throws \RuntimeException When the endpoint answers with anything but exactly one item.
     */
    private function requireOne(string $path, string $query, string $label): array
    {
        $items = $this->requireOk('GET', $path, null, $query);
        if (!is_array($items) || count($items) !== 1 || !is_array($items[0] ?? null)) {
            throw new \RuntimeException(
                "GET $path?$query doveva restituire un solo $label: "
                . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $items[0];
    }

    /** Picks the item carrying a given `local_key` out of a listing, or null. */
    private function findByLocalKey(mixed $items, string $local_key): array|null
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && (string) ($item['local_key'] ?? '') === $local_key) {
                return $item;
            }
        }

        return null;
    }

    /** Records one comparison, printing the passing ones and collecting the rest. */
    private function check(string $label, mixed $expected, mixed $actual): void
    {
        if (self::normalize($expected) === self::normalize($actual)) {
            echo "  ok       $label\n";

            return;
        }

        $this->failures[] = sprintf(
            '%s: atteso %s, letto %s',
            $label,
            json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Flattens scalars to strings before comparing.
     *
     * ACF and WooCommerce both normalise types on the way back — a price is a string, a
     * term id an int, an empty ACF value `false` — so a strict comparison against the
     * submitted payload would report type noise instead of the thing under test, which
     * is whether the value reached its field at all.
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
     * Checks the site answers and accepts the token, before anything gets created.
     *
     * Without it a wrong URL or token would surface as a dozen teardown warnings before
     * the first real error, instead of one line saying what is actually wrong.
     *
     * @throws \RuntimeException When the site is unreachable or rejects the token.
     */
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

        // WooCommerce is a hard requirement here and its absence surfaces deep inside
        // the first save, as a 500 on a product, which reads like a plugin bug.
        $response = $this->request('GET', '/woocommerce/products', null, 'local_key=' . self::LK_PRODUCT_SIMPLE);
        if ($response['status'] === 500) {
            throw new \RuntimeException(
                'il sito ha risposto 500 su GET /woocommerce/products: '
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ' (WooCommerce non attivo sul sito di prova?)'
            );
        }
    }

    /**
     * Removes everything the run creates, in the reverse order of creation.
     *
     * `?ignore=1` everywhere, and transport errors are downgraded to a warning: teardown
     * runs from a `finally`, so anything it threw would replace the failure the run is
     * already reporting with a less useful one. Deleting the custom taxonomy also drops
     * its terms, which is why they have no entry of their own here.
     */
    private function teardown(): void
    {
        $calls = [
            ['/woocommerce/variant-products', [self::LK_VARIANT_RED, self::LK_VARIANT_BLUE]],
            ['/woocommerce/products', [self::LK_PRODUCT_SIMPLE, self::LK_PRODUCT_VARIABLE]],
            ['/woocommerce/attributes/' . self::ATTRIBUTE_SLUG . '/terms', [self::LK_ATTRIBUTE_TERM_RED, self::LK_ATTRIBUTE_TERM_BLUE]],
            ['/woocommerce/attributes', [self::LK_ATTRIBUTE]],
            ['/woocommerce/tags', [self::LK_TAG]],
            ['/woocommerce/categories', [self::LK_CATEGORY_CHILD, self::LK_CATEGORY_PARENT]],
            ['/woocommerce/brands', [self::LK_BRAND]],
            ['/taxonomies', [self::LINE_TAXONOMY]],
            ['/field-groups', [
                self::GROUP_PRODUCT,
                self::GROUP_VARIATION,
                self::GROUP_CATEGORY,
                self::GROUP_TAG,
                self::GROUP_BRAND,
                self::GROUP_LINE,
                self::GROUP_ATTRIBUTE,
            ]],
        ];

        foreach ($calls as [$path, $body]) {
            try {
                $this->request('DELETE', $path, $body, 'ignore=1');
            } catch (\RuntimeException $exception) {
                fwrite(STDERR, "  avviso   pulizia di $path non riuscita: {$exception->getMessage()}\n");
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
        fwrite(STDERR, "ERRORE: $message\n");
        exit(1);
    }



    // ------------------------------------------------------------------------ run

    /** Builds the runner from `.env`, refusing to start without a target site. */
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

    /** Runs the whole flow and returns the process exit code. */
    public function run(): int
    {
        echo "test     catalogo WooCommerce di base, strutture ACF e dati\n";
        echo "sito     $this->base_url\n";

        try {
            $this->preflight();

            // A previous interrupted run would otherwise collide with the fixture.
            $this->teardown();

            try {
                $this->createStructures();
                $this->publishTerms();
                $this->publishProducts();

                $this->assertTermsPersisted();
                $this->assertSimpleProductPersisted();
                $this->assertVariantsPersisted();
            } finally {
                $this->teardown();
                echo "  pulizia  prodotti, termini, attributo, tassonomia e field group rimossi\n";
            }
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

        echo "\nPASSATO: strutture ACF create e catalogo WooCommerce scritto e riletto\n";

        return 0;
    }
}



if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(WooCommerceCatalog::fromEnv()->run());
}
