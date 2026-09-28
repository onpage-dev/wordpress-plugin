<?php



namespace OnPage\Tests;



use OnPage\Env;
use OnPage\Tests\Support\Audit;
use OnPage\Tests\Support\Keep;



require_once dirname(__DIR__) . '/Env.php';
require_once __DIR__ . '/Support/Audit.php';
require_once __DIR__ . '/Support/Keep.php';



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
 * WordPress with only WooCommerce, ACF and WPML installed.
 *
 * The data is multilingual, as an On Page® import is: names, descriptions, ACF values,
 * product attributes and variation attributes go out as WPML language maps for the
 * languages in `LANGUAGES`, while prices, stock and taxonomy references stay shared.
 * Every translation is then read back on its own and checked against the value of its
 * language, so a value that reached only the default language fails the run. One ACF
 * group (`onpage_test_datasheet`) is sent shared on purpose, to prove a shared value still
 * reaches every translation.
 *
 * Create-and-delete flow: everything created here is removed again at the end, including
 * when an assertion fails. Every teardown call uses `?ignore=1`, so a leftover from an
 * interrupted run never masks a real failure; the same teardown also runs before the
 * fixture is built. With `ONPAGE_TEST_KEEP=1` the final teardown is skipped and the data
 * stays on the site (see `Support/Keep.php`).
 *
 * Configuration comes from the plugin `.env` (see `.env.example`):
 *
 *   WP_TEST_URL     base URL of the test WordPress site, e.g. http://localhost:8040
 *   WP_TEST_TOKEN   Bearer token, the same value stored in the `onpage_auth_token` option
 *
 * The target site must have WooCommerce, ACF and WPML active: without WooCommerce the
 * endpoints answer `500 woocommerce_required`, without ACF the plugin registers no
 * routes at all, without WPML the first language map answers `500 wpml_required`. Every
 * language in `LANGUAGES` must be active in WPML.
 *
 * Run with: php src/Tests/WooCommerceCatalog.php
 */
class WooCommerceCatalog
{
    /** Custom (non-WooCommerce) taxonomy registered on `product` by the setup phase. */
    private const LINE_TAXONOMY = 'onpage_test_line';

    /** Unprefixed slug of the global WooCommerce attribute; its taxonomy is `pa_` + this. */
    private const ATTRIBUTE_SLUG = 'onpage-test-color';
    private const ATTRIBUTE_TAXONOMY = 'pa_' . self::ATTRIBUTE_SLUG;

    /**
     * On Page® local keys of every entity the test writes.
     *
     * Strings, not integers, on purpose: a string local_key is the shape the API is
     * least often exercised with, and it reads back verbatim, which keeps the
     * assertions honest.
     */
    private const LK_BRAND = 'onpage-test-brand';
    private const LK_CATEGORY_PARENT = 'onpage-test-category-parent';
    private const LK_CATEGORY_CHILD = 'onpage-test-category-child';
    private const LK_TAG = 'onpage-test-tag';
    private const LK_ATTRIBUTE = 'onpage-test-attribute';
    private const LK_ATTRIBUTE_TERM_RED = 'onpage-test-color-red';
    private const LK_ATTRIBUTE_TERM_BLUE = 'onpage-test-color-blue';
    private const LK_LINE_TERM = 'onpage-test-line-pro';
    private const LK_PRODUCT_SIMPLE = 'onpage-test-product-simple';
    private const LK_PRODUCT_VARIABLE = 'onpage-test-product-variable';
    private const LK_VARIANT_RED = 'onpage-test-variant-red-s';
    private const LK_VARIANT_BLUE = 'onpage-test-variant-blue-m';

    /** Titles of the ACF field groups, which are also how teardown deletes them. */
    private const GROUP_PRODUCT = 'On Page Test :: Product fields';
    private const GROUP_VARIATION = 'On Page Test :: Variation fields';
    private const GROUP_CATEGORY = 'On Page Test :: Category fields';
    private const GROUP_TAG = 'On Page Test :: Tag fields';
    private const GROUP_BRAND = 'On Page Test :: Brand fields';
    private const GROUP_LINE = 'On Page Test :: Line fields';
    private const GROUP_ATTRIBUTE = 'On Page Test :: Color fields';

    /** The one ACF field every taxonomy in the fixture carries, so terms can be checked too. */
    private const TERM_FIELD = 'onpage_test_subtitle';

    /**
     * Languages of every WPML map the fixture sends.
     *
     * Two are enough: the second one is what proves the translation branch ran, and each
     * further language only repeats the same path.
     */
    private const LANGUAGES = ['en', 'it'];

    private string $base_url;
    private string $token;

    /** @var list<string> Assertion failures collected during the run. */
    private array $failures = [];



    private function __construct(string $base_url, string $token)
    {
        $this->base_url = rtrim($base_url, '/');
        $this->token = $token;
    }



    // ------------------------------------------------------------------ transport

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

            throw new \RuntimeException("$method $url unreachable: $error");
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
                "$method $path answered {$response['status']}: "
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
            'description' => 'Fixture of test src/Tests/WooCommerceCatalog.php',
            'locations' => [
                ['param' => 'taxonomy', 'operator' => '==', 'value' => $taxonomy],
            ],
            'fields' => [
                [
                    'key' => self::TERM_FIELD,
                    'name' => self::TERM_FIELD,
                    'label' => 'Subtitle',
                    'type' => 'text',
                ],
            ],
        ];

        return [
            [
                'title' => self::GROUP_PRODUCT,
                'key' => 'group_onpage_test_product',
                'description' => 'Fixture of test src/Tests/WooCommerceCatalog.php',
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
                        'key' => 'onpage_test_datasheet',
                        'name' => 'onpage_test_datasheet',
                        'label' => 'Datasheet',
                        'type' => 'group',
                        'sub_fields' => [
                            ['key' => 'title', 'name' => 'title', 'label' => 'Title', 'type' => 'text'],
                            ['key' => 'notes', 'name' => 'notes', 'label' => 'Notes', 'type' => 'text'],
                        ],
                    ],
                    [
                        'key' => 'onpage_test_certifications',
                        'name' => 'onpage_test_certifications',
                        'label' => 'Certifications',
                        'type' => 'repeater',
                        'sub_fields' => [
                            ['key' => 'name', 'name' => 'name', 'label' => 'Name', 'type' => 'text'],
                            ['key' => 'year', 'name' => 'year', 'label' => 'Year', 'type' => 'text'],
                        ],
                    ],
                ],
            ],
            [
                'title' => self::GROUP_VARIATION,
                'key' => 'group_onpage_test_variation',
                'description' => 'Fixture of test src/Tests/WooCommerceCatalog.php',
                // Variations are a post type of their own, so their ACF fields are
                // scoped to `product_variation`, not to `product`.
                'locations' => [
                    ['param' => 'post_type', 'operator' => '==', 'value' => 'product_variation'],
                ],
                'fields' => [
                    [
                        'key' => 'onpage_test_material',
                        'name' => 'onpage_test_material',
                        'label' => 'Material',
                        'type' => 'text',
                    ],
                ],
            ],
            $term_group(self::GROUP_CATEGORY, 'group_onpage_test_category', 'product_cat'),
            $term_group(self::GROUP_TAG, 'group_onpage_test_tag', 'product_tag'),
            $term_group(self::GROUP_BRAND, 'group_onpage_test_brand', 'product_brand'),
            $term_group(self::GROUP_LINE, 'group_onpage_test_line', self::LINE_TAXONOMY),
            $term_group(self::GROUP_ATTRIBUTE, 'group_onpage_test_color', self::ATTRIBUTE_TAXONOMY),
        ];
    }

    /** A WPML language map over `LANGUAGES`, in the same order. */
    private static function translated(mixed $en, mixed $it): array
    {
        return array_combine(self::LANGUAGES, [$en, $it]);
    }

    /**
     * The value a translation must read back: its own entry for a language map, the value
     * itself for a shared one.
     */
    private static function inLanguage(mixed $value, string $language): mixed
    {
        if (is_array($value) && array_keys($value) === self::LANGUAGES) {
            return $value[$language];
        }

        return $value;
    }

    /**
     * Name and ACF subtitle of every term the fixture publishes, keyed by local_key.
     *
     * Names differ per language on purpose: a translation that kept the default name
     * would otherwise pass the check.
     */
    private static function termValues(): array
    {
        return [
            self::LK_BRAND => [
                'name' => self::translated('On Page Test Brand', 'On Page Test Marchio'),
                'subtitle' => self::translated('Brand subtitle', 'Sottotitolo del brand'),
            ],
            self::LK_CATEGORY_PARENT => [
                'name' => self::translated('On Page Test Furniture', 'On Page Test Arredo'),
                'subtitle' => self::translated('Parent category subtitle', 'Sottotitolo della categoria madre'),
            ],
            self::LK_CATEGORY_CHILD => [
                'name' => self::translated('On Page Test Chairs', 'On Page Test Sedie'),
                'subtitle' => self::translated('Child category subtitle', 'Sottotitolo della categoria figlia'),
            ],
            self::LK_TAG => [
                'name' => self::translated('On Page Test Featured', 'On Page Test In evidenza'),
                'subtitle' => self::translated('Tag subtitle', 'Sottotitolo del tag'),
            ],
            self::LK_ATTRIBUTE_TERM_RED => [
                'name' => self::translated('Red', 'Rosso'),
                'subtitle' => self::translated('Red subtitle', 'Sottotitolo del rosso'),
            ],
            self::LK_ATTRIBUTE_TERM_BLUE => [
                'name' => self::translated('Blue', 'Blu'),
                'subtitle' => self::translated('Blue subtitle', 'Sottotitolo del blu'),
            ],
            self::LK_LINE_TERM => [
                'name' => self::translated('On Page Test Pro Line', 'On Page Test Linea Pro'),
                'subtitle' => self::translated('Line subtitle', 'Sottotitolo della linea'),
            ],
        ];
    }

    /** The payload fragment of one term: its name and its ACF subtitle. */
    private static function termPayload(string $local_key): array
    {
        $values = self::termValues()[$local_key];

        return [
            'local_key' => $local_key,
            'name' => $values['name'],
            'acf_fields' => [self::TERM_FIELD => $values['subtitle']],
        ];
    }

    /** The translated text fields of the simple product, and the ones read back from it. */
    private static function productTextValues(): array
    {
        return [
            'title' => self::translated('On Page Test Chair', 'On Page Test Sedia'),
            'long_description' => self::translated(
                'Long description of the test chair.',
                'Descrizione lunga della sedia di prova.'
            ),
            'short_description' => self::translated(
                'Short description of the test chair.',
                'Descrizione breve della sedia di prova.'
            ),
        ];
    }

    /**
     * The ACF values the simple product carries, and the ones read back from it.
     *
     * A scalar and a repeater sent as language maps, and a group sent shared: the map
     * must be unwrapped per translation, the shared group copied to all of them.
     */
    private static function productFieldValues(): array
    {
        return [
            'onpage_test_badge' => self::translated('New', 'Novita'),
            'onpage_test_datasheet' => [
                'title' => 'Technical datasheet',
                'notes' => 'Test product, invented data',
            ],
            'onpage_test_certifications' => self::translated(
                [
                    ['name' => 'CE marking', 'year' => '2024'],
                    ['name' => 'VOC A+', 'year' => '2023'],
                ],
                [
                    ['name' => 'Marcatura CE', 'year' => '2024'],
                    ['name' => 'VOC A+', 'year' => '2023'],
                ]
            ),
        ];
    }

    /**
     * The two variants of the variable product, keyed by local_key.
     *
     * `attributes` is the payload; `read_attributes` is the same set as WooCommerce stores
     * it, under the sanitised attribute name (`Color` reads back as `color`).
     */
    private static function variantValues(): array
    {
        return [
            self::LK_VARIANT_RED => [
                'attributes' => ['Color' => self::translated('Red', 'Rosso'), 'Size' => 'S'],
                'read_attributes' => ['color' => self::translated('Red', 'Rosso'), 'size' => 'S'],
                'sku' => 'ONPAGE-TEST-TSHIRT-R-S',
                'price' => '29.90',
                'description' => self::translated('Red / S', 'Rosso / S'),
                'material' => self::translated('Cotton', 'Cotone'),
            ],
            self::LK_VARIANT_BLUE => [
                'attributes' => ['Color' => self::translated('Blue', 'Blu'), 'Size' => 'M'],
                'read_attributes' => ['color' => self::translated('Blue', 'Blu'), 'size' => 'M'],
                'sku' => 'ONPAGE-TEST-TSHIRT-B-M',
                'price' => '31.90',
                'description' => self::translated('Blue / M', 'Blu / M'),
                'material' => self::translated('Linen', 'Lino'),
            ],
        ];
    }

    /**
     * Creates the structures the data phase writes into.
     *
     * Order matters and is the reason this is one method: `product_brand` is created
     * lazily by the first brand save and `pa_onpage-test-color` by the attribute save,
     * so both must exist before the terms of the catalogue can carry ACF values. The
     * brand created here is deliberately bare — name and key only — and gets its ACF
     * value later, in the publish phase, which is also the upsert path every re-import
     * takes.
     */
    private function createStructures(): void
    {
        $this->requireOk('POST', '/taxonomies', [[
            'key' => self::LINE_TAXONOMY,
            'singular_label' => 'Line',
            'plural_label' => 'Lines',
            'description' => 'Fixture of test src/Tests/WooCommerceCatalog.php',
            'object_type' => ['product'],
            'hierarchical' => true,
        ]]);

        $this->requireOk('POST', '/woocommerce/attributes', [[
            'local_key' => self::LK_ATTRIBUTE,
            'name' => 'On Page Test Color',
            'slug' => self::ATTRIBUTE_SLUG,
            'type' => 'select',
        ]]);

        $this->requireOk('POST', '/woocommerce/brands', [[
            'local_key' => self::LK_BRAND,
            'name' => self::termValues()[self::LK_BRAND]['name'][self::LANGUAGES[0]],
        ]]);

        $this->requireOk('POST', '/field-groups', self::fieldGroupPayloads());

        echo "  setup    taxonomy, attribute, brand and 7 ACF field groups created\n";
    }



    // -------------------------------------------------------------------- publish

    /**
     * Writes brand, categories, tag, attribute terms and the custom taxonomy term.
     *
     * Every term goes out with a translated name and subtitle, so each save creates the
     * translations as well. The brand already exists, bare and in one language only: its
     * save here is the upsert that has to add the missing translation.
     */
    private function publishTerms(): void
    {
        $this->requireOk('POST', '/woocommerce/brands', [
            self::termPayload(self::LK_BRAND)
                + ['description' => self::translated('Test brand', 'Brand di prova')],
        ]);

        // Parent and child in one batch: the child references the parent by local_key,
        // which only resolves if the parent was already persisted by the same call. With
        // WPML each child translation must also land under the parent of its language.
        $this->requireOk('POST', '/woocommerce/categories', [
            self::termPayload(self::LK_CATEGORY_PARENT),
            self::termPayload(self::LK_CATEGORY_CHILD) + ['parent' => self::LK_CATEGORY_PARENT],
        ]);

        $this->requireOk('POST', '/woocommerce/tags', [self::termPayload(self::LK_TAG)]);

        $this->requireOk('POST', '/woocommerce/attributes/' . self::ATTRIBUTE_SLUG . '/terms', [
            self::termPayload(self::LK_ATTRIBUTE_TERM_RED),
            self::termPayload(self::LK_ATTRIBUTE_TERM_BLUE),
        ]);

        $this->requireOk('POST', '/terms', [
            ['taxonomy' => self::LINE_TAXONOMY] + self::termPayload(self::LK_LINE_TERM),
        ]);

        echo "  data     brand, 2 categories, tag, 2 attribute values and 1 line term, in "
            . implode('/', self::LANGUAGES) . "\n";
    }

    /**
     * Writes the two products and the two variants.
     *
     * The simple product carries every taxonomy the fixture declares: the dedicated
     * `brand`/`categories`/`tags` fields and, for the custom taxonomy, the generic
     * `terms` map, which is the only way to reach a taxonomy WooCommerce knows nothing
     * about. The references are shared local_keys: each translation must resolve them to
     * the term of its own language.
     */
    private function publishProducts(): void
    {
        $text = self::productTextValues();

        $this->requireOk('POST', '/woocommerce/products', [[
            'local_key' => self::LK_PRODUCT_SIMPLE,
            'name' => $text['title'],
            'status' => 'publish',
            'long_description' => $text['long_description'],
            'short_description' => $text['short_description'],
            'props' => [
                'product_type' => 'simple',
                'sku' => 'ONPAGE-TEST-CHAIR',
                'regular_price' => '49.90',
                'stock_status' => 'instock',
            ],
            'brand' => self::LK_BRAND,
            'categories' => [self::LK_CATEGORY_CHILD],
            'tags' => [self::LK_TAG],
            'terms' => [self::LINE_TAXONOMY => [self::LK_LINE_TERM]],
            'attributes' => [
                'Material' => self::translated('Wood', 'Legno'),
                'Finish' => self::translated(['Matt', 'Glossy'], ['Opaca', 'Lucida']),
            ],
            'acf_fields' => self::productFieldValues(),
        ]]);

        // `product_type: variable` makes every attribute sent here a variation
        // attribute, which is what the variants below are validated against. `Color`
        // is translated, so each translation of the parent offers the options of its own
        // language; `Size` is shared.
        $this->requireOk('POST', '/woocommerce/products', [[
            'local_key' => self::LK_PRODUCT_VARIABLE,
            'name' => self::translated('On Page Test T-shirt', 'On Page Test Maglietta'),
            'status' => 'publish',
            'props' => ['product_type' => 'variable'],
            'brand' => self::LK_BRAND,
            'categories' => [self::LK_CATEGORY_PARENT],
            'attributes' => [
                'Color' => self::translated(['Red', 'Blue'], ['Rosso', 'Blu']),
                'Size' => ['S', 'M'],
            ],
        ]]);

        $variants = [];
        foreach (self::variantValues() as $local_key => $values) {
            $variants[] = [
                'local_key' => $local_key,
                'parent' => self::LK_PRODUCT_VARIABLE,
                'attributes' => $values['attributes'],
                'props' => ['sku' => $values['sku'], 'regular_price' => $values['price']],
                'description' => $values['description'],
                'status' => 'publish',
                'acf_fields' => ['onpage_test_material' => $values['material']],
            ];
        }

        $this->requireOk('POST', '/woocommerce/variant-products', $variants);

        echo "  data     simple product, variable product and 2 variants, in "
            . implode('/', self::LANGUAGES) . "\n";
    }



    // ------------------------------------------------------------------ assertions

    /**
     * Reads back every translation of brand, categories, tag, attribute terms and custom
     * term, and the attribute itself.
     */
    private function assertTermsPersisted(): void
    {
        $attribute_terms = '/woocommerce/attributes/' . self::ATTRIBUTE_SLUG . '/terms';
        $sources = [
            self::LK_BRAND => ['/woocommerce/brands', 'brand'],
            self::LK_CATEGORY_PARENT => ['/woocommerce/categories', 'parent category'],
            self::LK_CATEGORY_CHILD => ['/woocommerce/categories', 'child category'],
            self::LK_TAG => ['/woocommerce/tags', 'tag'],
            self::LK_ATTRIBUTE_TERM_RED => [$attribute_terms, 'red attribute'],
            self::LK_ATTRIBUTE_TERM_BLUE => [$attribute_terms, 'blue attribute'],
        ];

        $read = [];
        foreach ($sources as $local_key => [$path, $label]) {
            $read[$local_key] = $this->requireTranslations($path, 'local_key=' . $local_key, $local_key, $label);
        }

        // `GET /terms` has no local_key filter, so the custom taxonomy term is picked
        // out of the taxonomy listing by the key the test wrote.
        $read[self::LK_LINE_TERM] = $this->requireTranslations(
            '/terms',
            'taxonomy=' . self::LINE_TAXONOMY,
            self::LK_LINE_TERM,
            'line'
        );
        $sources[self::LK_LINE_TERM] = ['/terms', 'line'];

        foreach ($read as $local_key => $translations) {
            $label = $sources[$local_key][1];
            $values = self::termValues()[$local_key];

            foreach ($translations as $language => $term) {
                $this->check("$label.$language.name", $values['name'][$language], $term['name'] ?? null);
                $this->check("$label.$language.acf", $values['subtitle'][$language], $term['acf_fields'][self::TERM_FIELD] ?? null);
            }
        }

        foreach ($read[self::LK_BRAND] as $language => $brand) {
            $this->check("brand.$language.description", self::translated('Test brand', 'Brand di prova')[$language], $brand['description'] ?? null);
        }

        // Each child translation must hang under the parent of its own language, not
        // under the parent of the default language.
        foreach ($read[self::LK_CATEGORY_CHILD] as $language => $child) {
            $this->check(
                "child category.$language.parent",
                $read[self::LK_CATEGORY_PARENT][$language]['id'] ?? null,
                $child['parent'] ?? null
            );
        }

        // Global attributes cannot be translated with WPML, so there is one to read.
        $attribute = $this->requireOne('/woocommerce/attributes', 'local_key=' . self::LK_ATTRIBUTE, 'attribute');
        $this->check('attribute.name', 'On Page Test Color', $attribute['name'] ?? null);
        $this->check('attribute.taxonomy', self::ATTRIBUTE_TAXONOMY, $attribute['taxonomy'] ?? null);
    }

    /**
     * Reads every translation of the simple product back: native fields, ACF values and
     * every taxonomy, each in the language of the translation.
     */
    private function assertSimpleProductPersisted(): void
    {
        $translations = $this->requireTranslations(
            '/woocommerce/products',
            'local_key=' . self::LK_PRODUCT_SIMPLE,
            self::LK_PRODUCT_SIMPLE,
            'simple product'
        );
        $term_values = self::termValues();
        $skus = [];

        foreach ($translations as $language => $product) {
            $prefix = "product.$language";

            foreach (self::productTextValues() as $field => $value) {
                $this->check("$prefix.$field", $value[$language], $product[$field] ?? null);
            }

            $this->check("$prefix.status", 'publish', $product['status'] ?? null);
            $this->check("$prefix.product_type", 'simple', $product['woocommerce']['product_type'] ?? null);
            $this->check("$prefix.regular_price", '49.90', $product['woocommerce']['regular_price'] ?? null);
            $this->check("$prefix.stock_status", 'instock', $product['woocommerce']['stock_status'] ?? null);

            foreach (self::productFieldValues() as $field_key => $expected) {
                $this->check(
                    "$prefix.acf.$field_key",
                    self::inLanguage($expected, $language),
                    $product['acf_fields'][$field_key] ?? null
                );
            }

            // The product attributes themselves are not part of the GET response; that
            // they were written is what the variants of the variable product below
            // prove, since a variation is rejected unless its parent declares the
            // attribute.
            $terms = is_array($product['terms'] ?? null) ? $product['terms'] : [];
            $expected_terms = [
                'product_brand' => $term_values[self::LK_BRAND]['name'][$language],
                'product_cat' => $term_values[self::LK_CATEGORY_CHILD]['name'][$language],
                'product_tag' => $term_values[self::LK_TAG]['name'][$language],
                self::LINE_TAXONOMY => $term_values[self::LK_LINE_TERM]['name'][$language],
            ];

            foreach ($expected_terms as $taxonomy => $name) {
                $assigned = array_values(array_filter(
                    $terms,
                    static fn(mixed $term): bool => is_array($term) && ($term['taxonomy'] ?? null) === $taxonomy
                ));

                $this->check(
                    "$prefix.terms.$taxonomy",
                    [$name],
                    array_map(static fn(array $term): mixed => $term['name'] ?? null, $assigned)
                );
            }

            $sku = (string) ($product['woocommerce']['sku'] ?? '');
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        // WooCommerce wants SKUs unique site-wide, so the plugin writes the SKU on the
        // source product only: exactly one translation must carry it.
        $this->check('product.sku', ['ONPAGE-TEST-CHAIR'], $skus);
    }

    /** Reads every translation of the variable product and of its two variants back. */
    private function assertVariantsPersisted(): void
    {
        $parents = $this->requireTranslations(
            '/woocommerce/products',
            'local_key=' . self::LK_PRODUCT_VARIABLE,
            self::LK_PRODUCT_VARIABLE,
            'variable product'
        );

        /** @var array<string, list<string>> $skus local_key => SKUs found across languages */
        $skus = [];

        foreach ($parents as $language => $parent) {
            $this->check("variable.$language.product_type", 'variable', $parent['woocommerce']['product_type'] ?? null);

            // Each translated parent has its own variations, so they are listed by the
            // parent's id and not by its local_key, which the whole group shares.
            $variants = $this->requireOk('GET', '/woocommerce/variant-products', null, 'parent_id=' . ($parent['id'] ?? ''));
            $variants = is_array($variants) ? $variants : [];
            $this->check("variants.$language.count", 2, count($variants));

            foreach (self::variantValues() as $local_key => $values) {
                $variant = $this->findByLocalKey($variants, $local_key);
                if ($variant === null) {
                    $this->failures[] = "variant '$local_key' not found among the parent's variants in '$language'";
                    continue;
                }

                $prefix = "$local_key.$language";
                $this->check("$prefix.parent", self::LK_PRODUCT_VARIABLE, $variant['parent'] ?? null);
                $this->check("$prefix.regular_price", $values['price'], $variant['woocommerce']['regular_price'] ?? null);
                $this->check("$prefix.description", self::inLanguage($values['description'], $language), $variant['description'] ?? null);
                $this->check(
                    "$prefix.acf.material",
                    self::inLanguage($values['material'], $language),
                    $variant['acf_fields']['onpage_test_material'] ?? null
                );

                $attributes = is_array($variant['attributes'] ?? null) ? $variant['attributes'] : [];
                foreach ($values['read_attributes'] as $attribute_key => $option) {
                    $this->check(
                        "$prefix.attributes.$attribute_key",
                        self::inLanguage($option, $language),
                        $attributes[$attribute_key] ?? null
                    );
                }

                $sku = (string) ($variant['woocommerce']['sku'] ?? '');
                if ($sku !== '') {
                    $skus[$local_key][] = $sku;
                }
            }
        }

        // WooCommerce wants SKUs unique site-wide, so the plugin writes a variation SKU on
        // the source variation only: exactly one translation must carry it.
        foreach (self::variantValues() as $local_key => $values) {
            $this->check("$local_key.sku", [$values['sku']], $skus[$local_key] ?? []);
        }
    }



    // --------------------------------------------------------------------- support

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
                "GET $path?$query should have returned exactly one $label: "
                . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        return $items[0];
    }

    /**
     * Reads every translation of one element, keyed by language.
     *
     * The ids come from the `translations` map of the listing. Term listings already
     * return every translation; product listings return only the representative, so a
     * translation missing from the listing is read on its own through `?id=`. A missing
     * language is recorded as a failure and left out, so the other languages are still
     * checked.
     *
     * @return array<string, array> language => item, over `LANGUAGES`
     *
     * @throws \RuntimeException When the listing holds no item with that `local_key`.
     */
    private function requireTranslations(string $path, string $query, string $local_key, string $label): array
    {
        $items = $this->requireOk('GET', $path, null, $query);
        $matching = array_values(array_filter(
            is_array($items) ? $items : [],
            static fn(mixed $item): bool => is_array($item) && (string) ($item['local_key'] ?? '') === $local_key
        ));

        if ($matching === []) {
            throw new \RuntimeException("GET $path?$query did not return $label '$local_key'");
        }

        $ids = is_array($matching[0]['translations'] ?? null) ? $matching[0]['translations'] : [];
        $by_id = array_column($matching, null, 'id');

        $translations = [];
        foreach (self::LANGUAGES as $language) {
            $id = $ids[$language] ?? null;
            if ($id === null) {
                $this->failures[] = "$label: no translation in '$language', translations = "
                    . json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                continue;
            }

            $item = $by_id[$id] ?? null;
            if ($item === null) {
                $found = $this->requireOk('GET', $path, null, 'id=' . $id);
                $found = array_values(array_filter(
                    is_array($found) ? $found : [],
                    static fn(mixed $candidate): bool => is_array($candidate) && (int) ($candidate['id'] ?? 0) === (int) $id
                ));
                $item = $found[0] ?? null;
            }

            if ($item === null) {
                $this->failures[] = "$label: translation '$language' (id $id) cannot be read back from GET $path";
                continue;
            }

            $translations[$language] = $item;
        }

        return $translations;
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
            '%s: expected %s, got %s',
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
                "the site answered {$response['status']} on GET /post-types: "
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ' (wrong WP_TEST_URL or WP_TEST_TOKEN?)'
            );
        }

        // WooCommerce is a hard requirement here and its absence surfaces deep inside
        // the first save, as a 500 on a product, which reads like a plugin bug.
        $response = $this->request('GET', '/woocommerce/products', null, 'local_key=' . self::LK_PRODUCT_SIMPLE);
        if ($response['status'] === 500) {
            throw new \RuntimeException(
                'the site answered 500 on GET /woocommerce/products: '
                . json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . ' (WooCommerce not active on the test site?)'
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



    // ------------------------------------------------------------------------ run

    /** Builds the runner from `.env`, refusing to start without a target site. */
    public static function fromEnv(): self
    {
        if (!function_exists('curl_init')) {
            self::fatal("the PHP curl extension is not available");
        }

        $base_url = Env::get('WP_TEST_URL');
        $token = Env::get('WP_TEST_TOKEN');

        if (!is_string($base_url) || $base_url === '' || !is_string($token) || $token === '') {
            self::fatal('WP_TEST_URL and WP_TEST_TOKEN must be set in the .env file (see .env.example)');
        }

        return new self($base_url, $token);
    }

    /** Runs the whole flow and returns the process exit code. */
    public function run(): int
    {
        echo "test     baseline WooCommerce catalogue, ACF structures and data\n";
        echo "site     $this->base_url\n";

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
                if (Keep::enabled()) {
                    echo "  cleanup  skipped, ONPAGE_TEST_KEEP enabled: the data stays on the site\n";
                } else {
                    $this->teardown();
                    echo "  cleanup  products, terms, attribute, taxonomy and field groups removed\n";
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

        echo "\nPASSED: ACF structures created and WooCommerce catalogue written and read back\n";

        return 0;
    }
}



if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(WooCommerceCatalog::fromEnv()->run());
}
