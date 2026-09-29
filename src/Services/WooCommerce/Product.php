<?php



namespace OnPage\Services\WooCommerce;



use OnPage\Services\Acf;
use OnPage\Services\Input;
use OnPage\Services\PostRepository;
use OnPage\Services\MultiLang;
use OnPage\Services\RemoteMedia;
use OnPage\Services\Wpml;



class Product
{
    private const ERROR_PREFIX = 'WooCommerce Product';
    private const PRODUCT_POST_TYPE = 'product';
    private const DEFAULT_PRODUCT_TYPE = 'simple';
    private const LOCAL_KEY_META = PostRepository::LOCAL_KEY_META;
    private const SUPPORTED_PRODUCT_TYPES = ['simple', 'variable'];

    private const PRODUCT_FIELD_KEYS = [
        'sku',
        'regular_price',
        'sale_price',
        'price',
        'manage_stock',
        'stock_quantity',
        'stock_status',
        'backorders',
        'sold_individually',
        'weight',
        'length',
        'width',
        'height',
        'virtual',
        'downloadable',
        'featured',
        'catalog_visibility',
        'tax_status',
        'tax_class',
        'purchase_note',
        'menu_order',
        'reviews_allowed',
        'product_type',
    ];

    /**
     * Props that WooCommerce cannot leave empty: enums and booleans. A null value leaves
     * them as they are, instead of silently resetting them to a default or to false.
     */
    private const NULL_KEEPS_VALUE_FIELD_KEYS = [
        'manage_stock',
        'stock_status',
        'backorders',
        'sold_individually',
        'virtual',
        'downloadable',
        'featured',
        'catalog_visibility',
        'tax_status',
        'reviews_allowed',
    ];



    /** Ensures WooCommerce CRUD classes and helpers are available. */
    private static function requireWooCommerce(): void
    {
        if (!\function_exists('wc_get_product') || !class_exists('\WC_Product_Simple') || !class_exists('\WC_Product_Variable')) {
            throw onpage_http_exception(self::ERROR_PREFIX . ' :: WooCommerce is required', 500, 'woocommerce_required');
        }
    }

    /** Registers frontend WooCommerce hooks managed by the plugin. */
    public static function boot(): void
    {
        ProductDownloads::boot();
    }

    /** Backward-compatible proxy for the frontend download renderer. */
    public static function renderProductDownloadLinks(): void
    {
        ProductDownloads::renderProductDownloadLinks();
    }

    /** Returns a supported WooCommerce product type from payload input. */
    private static function normalizeProductType(mixed $value, int $element_index): string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'props.product_type' must be a string", 400, 'invalid_param');
        }

        $product_type = \sanitize_key((string) $value);
        if (!in_array($product_type, self::SUPPORTED_PRODUCT_TYPES, true)) {
            throw onpage_http_exception(
                self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'props.product_type' must be one of: " . implode(', ', self::SUPPORTED_PRODUCT_TYPES),
                400,
                'invalid_param'
            );
        }

        return $product_type;
    }

    /** Returns requested product_type from props, or null when omitted. */
    private static function getRequestedProductType(array $params, int $element_index): string|null
    {
        $props = self::getProductProps($params, $element_index);
        if (!array_key_exists('product_type', $props)) {
            return null;
        }

        return self::normalizeProductType($props['product_type'], $element_index);
    }

    /** Creates the WooCommerce CRUD object for a supported product type. */
    private static function createProductObject(string $product_type, int $product_id = 0): mixed
    {
        return match ($product_type) {
            'variable' => $product_id > 0 ? new \WC_Product_Variable($product_id) : new \WC_Product_Variable(),
            default => $product_id > 0 ? new \WC_Product_Simple($product_id) : new \WC_Product_Simple(),
        };
    }

    /** Converts the CRUD object class when props.product_type requests another supported type. */
    private static function ensureProductObjectType(mixed $product, array $params, int $element_index): mixed
    {
        $requested_product_type = self::getRequestedProductType($params, $element_index);
        if ($requested_product_type === null || $product->get_type() === $requested_product_type) {
            return $product;
        }

        return self::createProductObject($requested_product_type, (int) $product->get_id());
    }

    /** Whether an array came from a JSON object rather than a JSON list. */
    private static function isObjectArray(array $value): bool
    {
        return $value === [] || !array_is_list($value);
    }

    /** Returns a JSON object param, or null when omitted/null. */
    private static function optionalObjectParam(array $params, string $key, int $element_index): array|null
    {
        if (!array_key_exists($key, $params) || $params[$key] === null) {
            return null;
        }

        if (!is_array($params[$key]) || !self::isObjectArray($params[$key])) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter '$key' must be an object", 400, 'invalid_param');
        }

        return $params[$key];
    }

    /** Checks WooCommerce native props that support per-language values. */
    private static function requireWpmlForProductPropLanguageMaps(array $props, int $element_index): void
    {
        foreach (self::PRODUCT_FIELD_KEYS as $field_key) {
            if ($field_key === 'product_type' || !array_key_exists($field_key, $props)) {
                continue;
            }

            MultiLang::requireWpmlForLanguageMap($props[$field_key], self::ERROR_PREFIX, $element_index, 'props.' . $field_key);
        }
    }

    /** Validates one product attribute value, optionally as a WPML language map. */
    private static function validateAttributeValue(mixed $value, string $attribute_name, int $element_index): void
    {
        if ($value === null) {
            return;
        }

        if (is_scalar($value)) {
            if (trim((string) $value) === '') {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Attribute '$attribute_name' must be a non-empty value or null", 400, 'invalid_param');
            }

            return;
        }

        if (!is_array($value)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Attribute '$attribute_name' must be a scalar, list, WPML language map or null", 400, 'invalid_param');
        }

        MultiLang::requireWpmlForLanguageMap($value, self::ERROR_PREFIX, $element_index, 'attributes.' . $attribute_name);

        if (MultiLang::isLanguageMapShape($value)) {
            foreach ($value as $language_code => $localized_value) {
                self::validateAttributeValue($localized_value, $attribute_name . '.' . $language_code, $element_index);
            }

            return;
        }

        if (!array_is_list($value)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Attribute '$attribute_name' must be a list or WPML language map", 400, 'invalid_param');
        }

        foreach ($value as $option_index => $option) {
            if (!is_scalar($option) || trim((string) $option) === '') {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Attribute '$attribute_name.$option_index' must be a non-empty value", 400, 'invalid_param');
            }
        }
    }

    /** Returns product custom attributes from the DTO payload. */
    private static function optionalAttributesParam(array $params, int $element_index): array|null
    {
        $attributes = self::optionalObjectParam($params, 'attributes', $element_index);
        if ($attributes === null) {
            return null;
        }

        foreach ($attributes as $attribute_name => $value) {
            $attribute_name = trim((string) $attribute_name);
            if ($attribute_name === '') {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Attribute name must be non-empty", 400, 'invalid_param');
            }

            self::validateAttributeValue($value, $attribute_name, $element_index);
        }

        return $attributes;
    }

    /** Validates one downloadable product file field, optionally as a WPML language map. */
    private static function validateDownloadValue(mixed $value, string $path, int $element_index, bool $required): void
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter '$path' is required", 400, 'invalid_param');
            }

            return;
        }

        if (is_scalar($value)) {
            if (trim((string) $value) === '' && $required) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter '$path' is required", 400, 'invalid_param');
            }

            return;
        }

        MultiLang::requireWpmlForLanguageMap($value, self::ERROR_PREFIX, $element_index, $path);
        if (MultiLang::isLanguageMapShape($value)) {
            foreach ($value as $language_code => $localized_value) {
                self::validateDownloadValue($localized_value, $path . '.' . (string) $language_code, $element_index, $required);
            }

            return;
        }

        throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter '$path' must be a string, language map or null", 400, 'invalid_param');
    }

    /** Normalizes the native WooCommerce downloadable files payload. */
    private static function normalizeDownloadsParam(array $params, int $element_index): array|null
    {
        if (!array_key_exists('downloads', $params)) {
            return null;
        }

        if ($params['downloads'] === null) {
            return [];
        }

        if (!is_array($params['downloads']) || !array_is_list($params['downloads'])) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'downloads' must be a list or null", 400, 'invalid_param');
        }

        $downloads = [];
        foreach ($params['downloads'] as $download_index => $download) {
            if (!is_array($download) || array_is_list($download)) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'downloads.$download_index' must be an object", 400, 'invalid_param');
            }

            $has_file = array_key_exists('file', $download);
            $has_url = array_key_exists('url', $download);
            if (!$has_file && !$has_url) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'downloads.$download_index.file' is required", 400, 'invalid_param');
            }

            $file = $has_file ? $download['file'] : $download['url'];
            self::validateDownloadValue($file, 'downloads.' . $download_index . '.file', $element_index, false);

            if (array_key_exists('name', $download)) {
                self::validateDownloadValue($download['name'], 'downloads.' . $download_index . '.name', $element_index, false);
            }

            $id = Input::stringOrNull($download['id'] ?? null);
            if (array_key_exists('id', $download) && $download['id'] !== null && $id === null) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'downloads.$download_index.id' must be a non-empty string or null", 400, 'invalid_param');
            }

            if (array_key_exists('public', $download) && !is_bool($download['public'])) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'downloads.$download_index.public' must be a boolean", 400, 'invalid_param');
            }

            $downloads[] = [
                'id' => $id,
                'name' => $download['name'] ?? null,
                'file' => $file,
                'refresh' => $download['refresh'] ?? false,
                'public' => $download['public'] ?? true,
            ];
        }

        return $downloads;
    }

    /** Returns the required DTO product name, as a string or WPML language map. */
    private static function requireName(array $params, int $element_index): string|array
    {
        if (!array_key_exists('name', $params)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'name' is required", 400, 'invalid_param');
        }

        if (is_scalar($params['name']) && trim((string) $params['name']) !== '') {
            return trim((string) $params['name']);
        }

        MultiLang::requireWpmlForLanguageMap($params['name'] ?? null, self::ERROR_PREFIX, $element_index, 'name');

        if (MultiLang::isLanguageMapShape($params['name'] ?? null)) {
            foreach ($params['name'] as $language_code => $name) {
                if (!is_scalar($name) || trim((string) $name) === '') {
                    throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'name.$language_code' must be a non-empty string", 400, 'invalid_param');
                }
            }

            return $params['name'];
        }

        throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'name' must be a non-empty string or a WPML language map", 400, 'invalid_param');
    }

    /**
     * Returns the DTO status value, or null when the payload sends none.
     *
     * A missing status leaves an existing product's status as it is; only a new product
     * falls back to publish (see insert()).
     */
    private static function normalizeStatus(array $params, int $element_index): ?string
    {
        if (!array_key_exists('status', $params)) {
            return null;
        }

        if (!is_scalar($params['status']) || trim((string) $params['status']) === '') {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'status' must be a string", 400, 'invalid_param');
        }

        return trim((string) $params['status']);
    }

    /** Normalizes the ProductDTO-shaped payload to the internal persistence shape. */
    private static function normalizeProductPayload(array $params, int $element_index): array
    {
        if ($params !== [] && array_is_list($params)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Product payload must be an object", 400, 'invalid_param');
        }

        $normalized = [
            'local_key' => Input::requireLocalKeyParam($params, 'local_key', self::ERROR_PREFIX, $element_index),
            'title' => self::requireName($params, $element_index),
        ];

        $status = self::normalizeStatus($params, $element_index);
        if ($status !== null) {
            $normalized['status'] = $status;
        }

        $acf_fields = self::optionalObjectParam($params, 'acf_fields', $element_index) ?? [];
        MultiLang::requireWpmlForFieldMap($acf_fields, self::ERROR_PREFIX, $element_index, 'acf_fields');
        $normalized['acf_fields'] = $acf_fields;

        if (array_key_exists('long_description', $params)) {
            $normalized['content'] = $params['long_description'];
            MultiLang::requireWpmlForLanguageMap($normalized['content'], self::ERROR_PREFIX, $element_index, 'long_description');
        } elseif (array_key_exists('content', $params)) {
            $normalized['content'] = $params['content'];
            MultiLang::requireWpmlForLanguageMap($normalized['content'], self::ERROR_PREFIX, $element_index, 'content');
        }

        if (array_key_exists('short_description', $params)) {
            $normalized['description'] = $params['short_description'];
            MultiLang::requireWpmlForLanguageMap($normalized['description'], self::ERROR_PREFIX, $element_index, 'short_description');
        } elseif (array_key_exists('description', $params)) {
            $normalized['description'] = $params['description'];
            MultiLang::requireWpmlForLanguageMap($normalized['description'], self::ERROR_PREFIX, $element_index, 'description');
        }

        $props = self::optionalObjectParam($params, 'props', $element_index);
        if ($props !== null) {
            self::requireWpmlForProductPropLanguageMaps($props, $element_index);
            $normalized['props'] = $props;
        }

        if (array_key_exists('slug', $params)) {
            if ($params['slug'] !== null && !is_scalar($params['slug']) && !is_array($params['slug'])) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'slug' must be a string or a WPML language map", 400, 'invalid_param');
            }
            MultiLang::requireWpmlForLanguageMap($params['slug'], self::ERROR_PREFIX, $element_index, 'slug');
            $normalized['slug'] = $params['slug'];
        }

        if (array_key_exists('update_slug', $params)) {
            if (!is_bool($params['update_slug'])) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'update_slug' must be a boolean", 400, 'invalid_param');
            }
            $normalized['update_slug'] = $params['update_slug'];
        }

        if (array_key_exists('image', $params)) {
            MultiLang::requireWpmlForLanguageMap($params['image'], self::ERROR_PREFIX, $element_index, 'image');
            $normalized['image'] = $params['image'];
        }

        if (array_key_exists('gallery', $params)) {
            // The gallery can be a language map of lists, or a list whose entries are
            // per-language maps, so detect language maps at any depth for the WPML guard.
            MultiLang::requireWpmlForNestedLanguageMaps($params['gallery'], self::ERROR_PREFIX, $element_index, 'gallery');
            $normalized['gallery'] = $params['gallery'];
        }

        if (array_key_exists('attributes', $params)) {
            $normalized['attributes'] = self::optionalAttributesParam($params, $element_index) ?? [];
        }

        $downloads = self::normalizeDownloadsParam($params, $element_index);
        if ($downloads !== null) {
            $normalized['downloads'] = $downloads;
        }

        if (array_key_exists('id', $params)) {
            $product_id = Input::positiveInt($params['id']);
            if ($params['id'] !== null && $product_id === null) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'id' must be a positive integer or null", 400, 'invalid_param');
            }

            if ($product_id !== null) {
                $normalized['id'] = $product_id;
            }
        }

        $taxonomies = Taxonomy::buildFromParams($params, $element_index);
        if ($taxonomies !== null) {
            $normalized['terms'] = $taxonomies;
        }

        return $normalized;
    }

    /** Resolves the scalar-or-language-map value used for WooCommerce product fields. */
    private static function resolveValue(mixed $value, ?string $language_code = null, ?string $fallback_language = null): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (MultiLang::isLanguageMapShape($value)) {
            return MultiLang::resolve($value, $language_code, $fallback_language);
        }

        return $value;
    }

    /** Returns language resolution context for a product payload. */
    private static function getLanguageContext(array $params): array
    {
        $acf_fields = is_array($params['acf_fields'] ?? null) ? $params['acf_fields'] : [];
        $props = is_array($params['props'] ?? null) ? $params['props'] : [];
        $attributes = is_array($params['attributes'] ?? null) ? $params['attributes'] : [];
        $downloads = is_array($params['downloads'] ?? null) ? $params['downloads'] : [];
        $field_values = [];

        foreach (self::PRODUCT_FIELD_KEYS as $field_key) {
            if (array_key_exists($field_key, $props)) {
                $field_values[] = $props[$field_key];
            }
        }

        foreach ($downloads as $download) {
            if (!is_array($download)) continue;

            foreach (['name', 'file'] as $download_field) {
                if (array_key_exists($download_field, $download)) {
                    $field_values[] = $download[$download_field];
                }
            }
        }

        $languages = array_values(array_unique(array_merge(
            MultiLang::getLanguages($params['title'] ?? ''),
            MultiLang::getLanguages($params['content'] ?? ''),
            MultiLang::getLanguages($params['description'] ?? ''),
            MultiLang::getLanguages($params['slug'] ?? ''),
            MultiLang::getLanguages($params['image'] ?? ''),
            MultiLang::getLanguages($params['gallery'] ?? ''),
            MultiLang::getFieldLanguages($acf_fields),
            MultiLang::getFieldLanguages($attributes),
            ...array_map(fn(mixed $value): array => MultiLang::getLanguages($value), $field_values)
        )));

        // Same rule as posts and terms: the fallback is the first language with a name, and a
        // name sent only per language, without the site default, puts the base product in
        // that fallback language instead of creating the default one with borrowed content.
        $title_map = MultiLang::splitValueByLanguage($params['title'] ?? null);
        $titled_languages = array_keys(array_filter(
            $title_map['translated'],
            static fn($value): bool => is_scalar($value) && (string) $value !== ''
        ));
        $fallback_language = $titled_languages[0] ?? ($languages[0] ?? null);
        $default_language = onpage_get_wpml_default_language() ?: $fallback_language;

        $has_title_map = $titled_languages !== [] && $title_map['shared'] === null;
        if ($has_title_map && !in_array($default_language, $titled_languages, true)) {
            $default_language = $fallback_language;
        }

        return [
            'default_language' => $default_language,
            'translated_languages' => $languages,
            'fallback_language' => $fallback_language,
        ];
    }

    /** Throws when multilingual payloads are submitted without WPML. */
    private static function requireWpmlForTranslations(array $translated_languages, int $element_index): void
    {
        MultiLang::requireWpmlForDetectedLanguages($translated_languages, self::ERROR_PREFIX, $element_index);
    }

    /**
     * Language codes whose title must be checked for duplicates during an update:
     * the existing translation group plus the payload languages not yet in it.
     * Falls back to the single source language when neither is known.
     */
    private static function getTitleCheckLanguages(
        array $translation_ids,
        array $translated_languages,
        ?string $source_language
    ): array {
        $language_codes = array_map('strval', array_keys($translation_ids));

        foreach ($translated_languages as $language_code) {
            if (!in_array((string) $language_code, $language_codes, true)) {
                $language_codes[] = (string) $language_code;
            }
        }

        return $language_codes !== [] ? $language_codes : [$source_language];
    }

    /** Resolves a product title string from a split shared/translated map. */
    private static function resolveProductTitle(
        array $title_map,
        ?string $language_code,
        ?string $fallback_language
    ): string
    {
        $title = MultiLang::getValueForLanguage($title_map, $language_code, $fallback_language);
        if (is_scalar($title) && (string) $title !== '') {
            return (string) $title;
        }

        return '';
    }

    /** Finds all WooCommerce product IDs by local_key meta. */
    private static function findProductIdsByLocalKey(string $local_key): array
    {
        $results = \get_posts([
            'post_type' => self::PRODUCT_POST_TYPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [[
                'key' => self::LOCAL_KEY_META,
                'value' => $local_key,
                'compare' => '=',
            ]],
        ]);

        return is_array($results) ? array_map('intval', $results) : [];
    }

    /** Picks the best product ID to update when resolving by local_key. */
    private static function findCanonicalProductIdByLocalKey(string $local_key): int|null
    {
        $product_ids = self::findProductIdsByLocalKey($local_key);
        if ($product_ids === []) return null;

        if (!onpage_is_wpml_active()) {
            return $product_ids[0];
        }

        $default_language = onpage_get_wpml_default_language();
        foreach ($product_ids as $product_id) {
            $language_details = self::getProductLanguageDetails($product_id);
            if ($default_language && !empty($language_details?->language_code) && (string) $language_details->language_code === $default_language) {
                return $product_id;
            }
        }

        foreach ($product_ids as $product_id) {
            $language_details = self::getProductLanguageDetails($product_id);
            if (empty($language_details?->source_language_code)) {
                return $product_id;
            }
        }

        return $product_ids[0];
    }

    /** Finds a WooCommerce product ID by exact title. */
    private static function findProductIdsByTitle(string $title): array
    {
        $results = \get_posts([
            'post_type' => self::PRODUCT_POST_TYPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'title' => $title,
        ]);

        return is_array($results) ? array_map('intval', $results) : [];
    }

    /**
     * Finds the product with the same title that is a genuine duplicate of the element being
     * written: neither a translation of it, nor a different On Page® element that merely
     * shares the title (see PostRepository::carriesForeignLocalKey()).
     *
     * $product_id is 0 on insert, where there is no product of our own to exclude yet.
     */
    private static function findDuplicateProductIdByTitle(string $title, string $local_key, int $product_id = 0): int|null
    {
        foreach (self::findProductIdsByTitle($title) as $matched_product_id) {
            if ($product_id > 0 && self::areSameTranslationGroup($matched_product_id, $product_id)) continue;
            if (PostRepository::carriesForeignLocalKey($matched_product_id, $local_key)) continue;

            return $matched_product_id;
        }

        return null;
    }

    /** Returns a product object by ID or throws. */
    public static function requireProductById(int $product_id): mixed
    {
        self::requireWooCommerce();

        $product = \wc_get_product($product_id);
        if (!$product || \get_post_type($product_id) !== self::PRODUCT_POST_TYPE) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Product $product_id not found", 404, 'not_found');
        }

        return $product;
    }

    /** Returns a product object by local_key or throws. */
    public static function requireProductByLocalKey(string $local_key): mixed
    {
        // Every translation carries the same local_key, so resolve to the group's
        // default-language product instead of whichever holder has the lowest ID —
        // that one is a translation whenever the default-language product was
        // recreated after its translations.
        $product_id = self::findCanonicalProductIdByLocalKey($local_key);
        if (!$product_id) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Product with local_key '$local_key' not found", 404, 'not_found');
        }

        return self::requireProductById($product_id);
    }

    /** Backward-compatible proxy for variation download inheritance. */
    public static function syncParentDownloadsToVariation(\WC_Product_Variation $variation, int $element_index = 0): void
    {
        ProductDownloads::syncParentDownloadsToVariation($variation, $element_index);
    }

    /**
     * Standard API response for one WooCommerce product.
     *
     * When $translations is provided (a language_code => product_id map) it is
     * exposed under the 'translations' key so callers get every multilang id.
     */
    public static function buildProductResponse(mixed $product, ?array $translations = null): array
    {
        $product_id = (int) $product->get_id();
        $long_description = $product->get_description();
        $short_description = $product->get_short_description();

        return [
            'id' => $product_id,
            'title' => $product->get_name(),
            'long_description' => $long_description,
            'short_description' => $short_description,
            'content' => $long_description,
            'description' => $short_description,
            'type' => self::PRODUCT_POST_TYPE,
            'status' => $product->get_status(),
            'local_key' => Input::localKeyOut(\get_post_meta($product_id, self::LOCAL_KEY_META, true)),
            'translations' => onpage_json_map($translations ?? self::buildTranslationsMap($product_id)),
            'woocommerce' => [
                'product_type' => $product->get_type(),
                'sku' => $product->get_sku(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'price' => $product->get_price(),
                'manage_stock' => $product->get_manage_stock(),
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'backorders' => $product->get_backorders(),
                'sold_individually' => $product->get_sold_individually(),
                'weight' => $product->get_weight(),
                'length' => $product->get_length(),
                'width' => $product->get_width(),
                'height' => $product->get_height(),
                'virtual' => $product->get_virtual(),
                'downloadable' => $product->get_downloadable(),
                'featured' => $product->get_featured(),
                'catalog_visibility' => $product->get_catalog_visibility(),
                'tax_status' => $product->get_tax_status(),
                'tax_class' => $product->get_tax_class(),
                'purchase_note' => $product->get_purchase_note(),
                'menu_order' => $product->get_menu_order(),
                'reviews_allowed' => $product->get_reviews_allowed(),
                'image_id' => $product->get_image_id(),
                'gallery_image_ids' => $product->get_gallery_image_ids(),
                'downloads' => array_values(array_map(
                    fn(mixed $download): array => ProductDownloads::buildResponse($download, $product_id),
                    $product->get_downloads()
                )),
                'permalink' => $product->get_permalink(),
            ],
            'acf_fields' => onpage_json_map(\function_exists('get_fields') ? \get_fields($product_id) : null),
            'terms' => \wp_get_post_terms($product_id, \get_object_taxonomies(self::PRODUCT_POST_TYPE), ['fields' => 'all']),
        ];
    }

    /**
     * Searches products by exact title, returning one compact response per WPML translation
     * group (de-duplicated): the default language is preferred as the representative and each
     * object carries the full multilang id map under 'translations'.
     *
     * Each query is a [language_code|null, title] pair: a null language searches the current
     * language; a language code searches that title within its WPML language context. Results
     * across queries are unioned and de-duplicated by translation group.
     */
    private static function searchByName(array $queries): array
    {
        $responses = [];
        $seen_ids = [];

        foreach ($queries as [$language_code, $title]) {
            $product_ids = $language_code === null
                ? self::findProductIdsByTitle($title)
                : Wpml::runWithLanguage($language_code, fn(): array => self::findProductIdsByTitle($title));

            foreach ($product_ids as $product_id) {
                $product_id = (int) $product_id;
                if (isset($seen_ids[$product_id])) {
                    continue;
                }

                $translations = self::buildTranslationsMap($product_id);
                $member_ids = $translations !== [] ? array_values($translations) : [$product_id];
                foreach ($member_ids as $member_id) {
                    $seen_ids[(int) $member_id] = true;
                }

                $representative_id = self::pickGroupRepresentative($product_id, $translations);
                $responses[] = self::buildProductResponse(
                    self::requireProductById($representative_id),
                    $translations
                );
            }
        }

        return $responses;
    }

    /** Picks the default-language product of a group, falling back to the given id. */
    private static function pickGroupRepresentative(int $product_id, array $translations): int
    {
        $default_language = onpage_get_wpml_default_language();
        if ($default_language !== null && !empty($translations[$default_language])) {
            return (int) $translations[$default_language];
        }

        return $product_id;
    }

    /** Lists products, or returns one product when filtered by id/local_key. */
    public static function list(\WP_REST_Request $request): array
    {
        self::requireWooCommerce();

        $product_id = Input::positiveInt($request->get_param('id'));
        if ($product_id !== null) {
            return [self::buildProductResponse(self::requireProductById($product_id))];
        }

        $local_key = Input::localKey($request->get_param('local_key'));
        if ($local_key !== null) {
            return [self::buildProductResponse(self::requireProductByLocalKey($local_key))];
        }

        $name_queries = Input::langValueQueries($request->get_param('name'));
        if ($name_queries !== []) {
            return self::searchByName($name_queries);
        }

        $product_ids = \get_posts([
            'post_type' => self::PRODUCT_POST_TYPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => -1,
            'suppress_filters' => false,
        ]);

        return array_map(
            fn(int|string $id): array => self::buildProductResponse(self::requireProductById((int) $id)),
            is_array($product_ids) ? $product_ids : []
        );
    }

    /** Creates or updates one product from the ProductDTO-shaped payload. */
    public static function save(array $params, int $element_index): int
    {
        self::requireWooCommerce();
        $params = self::normalizeProductPayload($params, $element_index);

        $product_id = Input::positiveInt($params['id'] ?? null);
        if ($product_id !== null) {
            $params['id'] = $product_id;

            return self::updateFromParams($params, $element_index);
        }

        $local_key = Input::localKey($params['local_key'] ?? null);
        if ($local_key !== null) {
            $existing_product_id = self::findCanonicalProductIdByLocalKey($local_key);
            if ($existing_product_id !== null) {
                $params['id'] = $existing_product_id;

                // The id comes from the local_key itself, so any other holder of that key
                // is the same On Page® element and gets reconciled instead of rejected.
                return self::updateFromParams($params, $element_index, true);
            }
        }

        return self::insertFromParams($params, $element_index);
    }

    /** Creates a WooCommerce product. */
    private static function insertFromParams(array $params, int $element_index): int
    {
        $language = self::getLanguageContext($params);
        self::requireWpmlForTranslations($language['translated_languages'], $element_index);

        $local_key = Input::requireLocalKeyParam($params, 'local_key', self::ERROR_PREFIX, $element_index);

        if (!array_key_exists('title', $params)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'name' is required", 400, 'invalid_param');
        }

        $title_map = MultiLang::splitValueByLanguage($params['title']);
        $source_language = $language['default_language'];
        $title = trim(self::resolveProductTitle(
            $title_map,
            $source_language,
            $language['fallback_language']
        ));

        if ($title === '') {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'name' is required", 400, 'invalid_param');
        }

        // No local_key uniqueness re-check here: save() only reaches insertFromParams
        // after findCanonicalProductIdByLocalKey() (same lookup) found no match, so
        // re-querying would always return null. Updates are routed away earlier.

        if (self::findDuplicateProductIdByTitle($title, $local_key)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Name '$title' already exists", 409, 'duplicate_title');
        }

        foreach ($language['translated_languages'] as $language_code) {
            if ($language_code === $source_language) continue;

            $translated_title = trim(self::resolveProductTitle(
                $title_map,
                (string) $language_code,
                $language['fallback_language']
            ));

            if ($translated_title !== '' && self::findDuplicateProductIdByTitle($translated_title, $local_key)) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Name '$translated_title' already exists", 409, 'duplicate_title');
            }
        }

        $product_type = self::getRequestedProductType($params, $element_index) ?? self::DEFAULT_PRODUCT_TYPE;
        $product = self::createProductObject($product_type);
        $product->set_status((string) ($params['status'] ?? 'publish'));
        $created_product_ids = [];

        try {
            $product_id = self::persistProductFromParams(
                $product,
                $params,
                $local_key,
                $element_index,
                $source_language,
                $language['fallback_language'],
                $source_language !== null
            );
            $created_product_ids[] = $product_id;

            if ($language['translated_languages'] !== [] && onpage_is_wpml_active()) {
                self::insertTranslatedProducts(
                    $product_id,
                    $params,
                    $local_key,
                    $element_index,
                    $language,
                    $created_product_ids
                );
            }

            return $product_id;
        } catch (\Throwable $e) {
            $product_id = (int) $product->get_id();
            if ($product_id > 0 && !in_array($product_id, $created_product_ids, true)) {
                $created_product_ids[] = $product_id;
            }

            foreach (array_reverse($created_product_ids) as $created_product_id) {
                try {
                    $created_product = \wc_get_product((int) $created_product_id);
                    if ($created_product) {
                        $created_product->delete(true);
                    } else {
                        \wp_delete_post((int) $created_product_id, true);
                    }
                } catch (\Throwable) {
                    // Preserve the original creation failure.
                }
            }

            throw $e;
        }
    }

    /**
     * Updates an existing WooCommerce product.
     *
     * $resolved_by_local_key tells whether the target id was supplied by the caller or
     * derived from the payload's local_key: only in the first case can another holder of
     * that key be a real conflict (see the reconciliation below).
     */
    private static function updateFromParams(array $params, int $element_index, bool $resolved_by_local_key = false): int
    {
        $product_id = Input::positiveInt($params['id'] ?? null);
        if ($product_id === null) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'id' must be a positive integer", 400, 'invalid_param');
        }

        $language = self::getLanguageContext($params);
        self::requireWpmlForTranslations($language['translated_languages'], $element_index);

        $local_key = Input::requireLocalKeyParam($params, 'local_key', self::ERROR_PREFIX, $element_index);
        $product = self::requireProductById($product_id);
        $language_details = self::getProductLanguageDetails($product_id);
        $current_language = !empty($language_details?->language_code)
            ? (string) $language_details->language_code
            : $language['default_language'];

        // A language slot still listed by the WPML group but whose product is gone (deleted
        // outside WordPress) cannot be written and would keep the language from being
        // recreated; clearing it lets insertMissingProductTranslations() adopt it.
        self::pruneOrphanProductTranslations($product_id);

        $translation_ids = self::getTranslationProductIds(
            $product_id,
            $current_language,
            self::getProductTrid($product_id)
        );

        // Every translation of this product holds the same local_key, so only a holder
        // *outside* the translation group is remarkable — and all holders must be checked,
        // not just the first: that one both misfires (an out-of-group leftover with a
        // lower ID) and misses (a foreign holder that is not the first).
        $foreign_product_ids = array_values(array_filter(
            self::findProductIdsByLocalKey($local_key),
            fn(int $existing_product_id): bool => !self::areSameTranslationGroup($existing_product_id, $product_id)
        ));

        if ($foreign_product_ids !== []) {
            if (!$resolved_by_local_key) {
                // Explicit id + someone else's local_key: a genuine collision the caller
                // must resolve, since the two products are different On Page® elements.
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: local_key '$local_key' already exists", 409, 'duplicate_local_key');
            }

            $translation_ids = self::reconcileForeignLocalKeyHolders(
                $product_id,
                $foreign_product_ids,
                $current_language,
                $translation_ids
            );
        }

        // Key every language of the group before touching anything else. The local_key is
        // the element's identity, so it must not depend on the per-language persistence
        // below: that path downloads media and writes ACF, and a failure part-way through
        // (or on a later language) used to leave translations unkeyed — and an unkeyed
        // translation is invisible to every later import.
        self::persistLocalKeyOnTranslationGroup($product_id, $translation_ids, $local_key);

        if (array_key_exists('title', $params)) {
            $title_map = MultiLang::splitValueByLanguage($params['title']);
            $source_language = $current_language ?: $language['default_language'];

            // Languages already in the group *plus* the payload languages still missing
            // from it: those get a product created below, so their title must be checked
            // for conflicts too. Translations of the same group never conflict with
            // each other, so the check is always scoped outside the group.
            foreach (self::getTitleCheckLanguages($translation_ids, $language['translated_languages'], $source_language) as $language_code) {
                // An existing language the title map leaves out keeps its name: nothing to check.
                $is_existing_language = isset($translation_ids[$language_code]) || $language_code === $current_language;
                if ($is_existing_language && !MultiLang::hasValueForLanguage($title_map, $language_code)) {
                    continue;
                }

                $title = trim(self::resolveProductTitle(
                    $title_map,
                    $language_code,
                    $language['fallback_language']
                ));

                $group_product_id = $language_code !== null && isset($translation_ids[$language_code])
                    ? (int) $translation_ids[$language_code]
                    : $product_id;

                if ($title !== '' && self::findDuplicateProductIdByTitle($title, $local_key, $group_product_id)) {
                    throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Name '$title' already exists", 409, 'duplicate_title');
                }
            }
        }

        $updated_product_id = self::persistProductFromParams(
            $product,
            $params,
            $local_key,
            $element_index,
            $current_language,
            $language['fallback_language'],
            keep_unsent_languages: true
        );

        self::updateProductTranslations(
            $product_id,
            $params,
            $local_key,
            $element_index,
            $current_language,
            $language['fallback_language'],
            $translation_ids,
            $language['translated_languages']
        );

        return $updated_product_id;
    }

    /** Writes the local_key on the product and on every translation of its WPML group. */
    private static function persistLocalKeyOnTranslationGroup(int $product_id, array $translation_ids, string $local_key): void
    {
        $product_ids = array_unique(array_merge(
            [$product_id],
            array_map('intval', array_values($translation_ids))
        ));

        foreach ($product_ids as $group_product_id) {
            \update_post_meta($group_product_id, self::LOCAL_KEY_META, $local_key);
        }
    }

    /**
     * Repairs the products that still carry this local_key while sitting outside the
     * translation group being updated, and returns the group's translation map.
     *
     * The key is the plugin's own identifier, so every holder is the *same* On Page®
     * element: a leftover of an import that died between creating a translation and
     * linking it to the group (PHP timeout, OOM) or of two imports of the same element
     * running at once. Rejecting them with 409 made that element un-importable forever —
     * every retry resolved the same canonical product and hit the same leftover, so the
     * languages that were still missing the key never got it.
     *
     * A leftover whose language slot is free joins the group and is then updated like any
     * other translation; one that duplicates a slot the group already fills is deleted,
     * being a duplicate of a product the importer itself owns (keeping it would only move
     * the deadlock to `duplicate_title`).
     */
    private static function reconcileForeignLocalKeyHolders(
        int $product_id,
        array $foreign_product_ids,
        ?string $source_language,
        array $translation_ids
    ): array {
        $trid = self::getProductTrid($product_id);

        foreach ($foreign_product_ids as $foreign_product_id) {
            $foreign_product_id = (int) $foreign_product_id;
            $language_details = self::getProductLanguageDetails($foreign_product_id);
            $language_code = !empty($language_details?->language_code)
                ? (string) $language_details->language_code
                : null;

            $is_free_slot = $trid !== null
                && $language_code !== null
                && $language_code !== $source_language
                && !array_key_exists($language_code, $translation_ids);

            if ($is_free_slot) {
                self::setProductLanguage($foreign_product_id, $language_code, $trid, $source_language);
                $translation_ids[$language_code] = $foreign_product_id;

                continue;
            }

            self::deleteLeftoverProduct($foreign_product_id);
        }

        return $translation_ids;
    }

    /**
     * Removes a leftover product that duplicates a language of its own translation group.
     *
     * Best effort on purpose: the deletion repairs state the import did not ask about, so
     * it must not abort the request — a leftover that survives is simply retried by the
     * next import. wc_get_product() is not enough either, since it returns false for the
     * kind of half-written row this repairs, and such a row must still go.
     */
    private static function deleteLeftoverProduct(int $product_id): void
    {
        try {
            self::deleteById($product_id, true);
        } catch (\Throwable) {
            // Falls through to wp_delete_post() below.
        }

        if (onpage_post_exists($product_id)) {
            \wp_delete_post($product_id, true);
        }
    }

    /**
     * Applies product/post fields, media, ACF and taxonomy assignments, then saves.
     *
     * $keep_unsent_languages is set when the product already exists in $language_code:
     * a language map without that language then leaves the field as it is, instead of
     * borrowing the fallback language's value. A product created by this request keeps
     * the fallback, so it never starts with an empty name.
     */
    private static function persistProductFromParams(
        mixed $product,
        array $params,
        string $local_key,
        int $element_index,
        ?string $language_code = null,
        ?string $fallback_language = null,
        bool $set_language_details = false,
        int|false $trid = false,
        ?string $source_language = null,
        bool $keep_unsent_languages = false
    ): int
    {
        if ($language_code === null && $fallback_language === null) {
            $language = self::getLanguageContext($params);
            $language_code = $language['default_language'];
            $fallback_language = $language['fallback_language'];
        }

        if ($keep_unsent_languages) {
            $params = self::withoutMapsMissingLanguage($params, $language_code);
        }

        return Wpml::runWithLanguage($language_code, function () use (
            $product,
            $params,
            $local_key,
            $element_index,
            $language_code,
            $fallback_language,
            $set_language_details,
            $trid,
            $source_language
        ): int {
            $product = self::ensureProductObjectType($product, $params, $element_index);
            $title_source_language = $source_language ?? $language_code;

            self::applyPostFields($product, $params, $language_code, $fallback_language, $title_source_language);

            $acf_fields = is_array($params['acf_fields'] ?? null)
                ? MultiLang::resolveFields($params['acf_fields'], $language_code, $fallback_language)
                : [];

            $is_translation = $source_language !== null && $source_language !== $language_code;
            self::applyProductFields($product, $params, $element_index, $language_code, $fallback_language, $is_translation);
            self::applyProductAttributes($product, $params, $element_index, $language_code, $fallback_language, $keep_unsent_languages);
            ProductDownloads::apply($product, $params, $element_index, $language_code, $fallback_language);

            try {
                $product_id = (int) $product->save();
            } catch (\Throwable $e) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Failed to save product :: " . $e->getMessage(), 500, 'request_failed');
            }

            if ($set_language_details && $language_code) {
                self::setProductLanguage($product_id, $language_code, $trid, $source_language);
            }

            \update_post_meta($product_id, self::LOCAL_KEY_META, $local_key);
            ProductDownloads::linkAttachmentsToProduct($product, $product_id);
            ProductDownloads::syncPublicIdsMeta($product, $params, $product_id);
            self::applyImage($product, $params, $product_id, $element_index, $language_code, $fallback_language);
            self::applyGallery($product, $params, $product_id, $element_index, $language_code, $fallback_language);
            ProductDownloads::syncVariableProductToVariations($product, $params, $element_index);
            if (array_key_exists('attributes', $params) && $product instanceof \WC_Product_Variable) {
                VariantProduct::disableVariationsWithUnofferedAttributes($product, $element_index);
            }
            self::saveAcfFields($product_id, $acf_fields);

            if (isset($params['terms']) && is_array($params['terms'])) {
                Taxonomy::apply($product_id, $params['terms'], $language_code, $fallback_language);
            }

            return $product_id;
        });
    }

    /** Creates translated WooCommerce products linked to the freshly inserted source product. */
    private static function insertTranslatedProducts(
        int $source_product_id,
        array $params,
        string $local_key,
        int $element_index,
        array $language,
        array &$created_product_ids
    ): void {
        $language_details = self::requireInsertedProductLanguageDetails($source_product_id);
        $source_language = !empty($language_details->language_code)
            ? (string) $language_details->language_code
            : $language['default_language'];

        foreach ($language['translated_languages'] as $language_code) {
            if ($language_code === $source_language) continue;

            self::createTranslatedProduct(
                $params,
                $local_key,
                $element_index,
                (string) $language_code,
                $language['fallback_language'],
                $source_language,
                (int) $language_details->trid,
                $created_product_ids
            );
        }
    }

    /**
     * Creates one translated product and links it to the given WPML translation group.
     *
     * The product is saved twice on purpose: once with just the post fields, so WPML
     * has an element to attach language details to, then again through the full
     * persistence path (fields, media, ACF, terms) now that it belongs to the group.
     */
    private static function createTranslatedProduct(
        array $params,
        string $local_key,
        int $element_index,
        string $language_code,
        ?string $fallback_language,
        ?string $source_language,
        int $trid,
        ?array &$created_product_ids = null
    ): int {
        $product_type = self::getRequestedProductType($params, $element_index) ?? self::DEFAULT_PRODUCT_TYPE;
        $translated_product = self::createProductObject($product_type);
        $translated_product->set_status((string) ($params['status'] ?? 'publish'));

        $translated_product_id = Wpml::runWithLanguage($language_code, function () use (
            $translated_product,
            $params,
            $element_index,
            $language_code,
            $fallback_language,
            $source_language
        ): int {
            self::applyPostFields(
                $translated_product,
                $params,
                $language_code,
                $fallback_language,
                $source_language
            );

            try {
                return (int) $translated_product->save();
            } catch (\Throwable $e) {
                throw onpage_http_exception(
                    self::ERROR_PREFIX . " :: Element $element_index :: Failed to save product translation '$language_code' :: " . $e->getMessage(),
                    500,
                    'request_failed'
                );
            }
        });

        if ($created_product_ids !== null) {
            $created_product_ids[] = $translated_product_id;
        }

        // Key the row as soon as it exists. Everything below (WPML linking, media
        // downloads, ACF) can die on a failure the rollback cannot catch — a PHP
        // timeout or OOM — and an unkeyed product with the same title then blocks
        // every later import with 409 duplicate_title instead of being resolved by
        // local_key and updated. persistProductFromParams() writes it again.
        \update_post_meta($translated_product_id, self::LOCAL_KEY_META, $local_key);

        self::setProductLanguage(
            $translated_product_id,
            $language_code,
            $trid,
            $source_language
        );

        self::persistProductFromParams(
            $translated_product,
            $params,
            $local_key,
            $element_index,
            $language_code,
            $fallback_language,
            false,
            false,
            $source_language
        );

        return $translated_product_id;
    }

    /**
     * Updates the translated products linked through WPML and creates the ones the
     * payload carries but the translation group is still missing.
     *
     * The backfill matters for products imported before a language was added to the
     * payload (or to the site): without it, save() resolves the existing product by
     * local_key, takes the update path forever, and the missing translations would
     * never be created no matter how many times the product is re-imported.
     */
    private static function updateProductTranslations(
        int $source_product_id,
        array $params,
        string $local_key,
        int $element_index,
        ?string $source_language,
        ?string $fallback_language,
        array $translation_ids,
        array $translated_languages = []
    ): void {
        foreach ($translation_ids as $language_code => $translated_product_id) {
            $translated_product_id = (int) $translated_product_id;
            if ($translated_product_id === $source_product_id) {
                continue;
            }

            self::persistProductFromParams(
                self::requireProductById($translated_product_id),
                $params,
                $local_key,
                $element_index,
                (string) $language_code,
                $fallback_language,
                false,
                false,
                $source_language,
                keep_unsent_languages: true
            );
        }

        self::insertMissingProductTranslations(
            $source_product_id,
            $params,
            $local_key,
            $element_index,
            $source_language,
            $fallback_language,
            $translation_ids,
            $translated_languages
        );
    }

    /** Creates the payload translations that are not part of the product's WPML group yet. */
    private static function insertMissingProductTranslations(
        int $source_product_id,
        array $params,
        string $local_key,
        int $element_index,
        ?string $source_language,
        ?string $fallback_language,
        array $translation_ids,
        array $translated_languages
    ): void {
        if ($translated_languages === [] || !onpage_is_wpml_active() || $source_language === null) {
            return;
        }

        $missing_languages = array_values(array_filter(
            array_map('strval', $translated_languages),
            fn(string $language_code): bool => $language_code !== $source_language
                && !array_key_exists($language_code, $translation_ids)
        ));

        if ($missing_languages === []) {
            return;
        }

        $trid = self::getProductTrid($source_product_id);
        if (!$trid) {
            // A product imported before WPML was configured has no translation group
            // yet; declaring its own language creates the trid the translations join.
            self::setProductLanguage($source_product_id, $source_language);
            $trid = self::getProductTrid($source_product_id);
        }

        if (!$trid) {
            throw onpage_http_exception(
                self::ERROR_PREFIX . " :: Element $element_index :: Unable to resolve translation group (trid) for Product $source_product_id",
                500,
                'wpml_error'
            );
        }

        if (!array_key_exists('status', $params)) {
            // A new translation of an existing product starts with the product's own status,
            // not with the publish default of a brand-new product.
            $source_product = \wc_get_product($source_product_id);
            if ($source_product) {
                $params['status'] = (string) $source_product->get_status();
            }
        }

        foreach ($missing_languages as $language_code) {
            self::createTranslatedProduct(
                $params,
                $local_key,
                $element_index,
                $language_code,
                $fallback_language,
                $source_language,
                (int) $trid
            );
        }
    }

    /**
     * Drops from the payload the language maps with no entry for the language.
     *
     * Covers the top-level text and media values, `props` and `acf_fields`. Attributes
     * are handled by applyProductAttributes(), because the attribute set is replaced as a
     * whole and a dropped key would delete the attribute. Lists whose entries carry their
     * own maps (`gallery` entries, `downloads`) and `terms` keep the fallback rule.
     */
    private static function withoutMapsMissingLanguage(array $params, ?string $language_code): array
    {
        foreach (['title', 'slug', 'content', 'description', 'image', 'gallery'] as $key) {
            if (array_key_exists($key, $params) && MultiLang::isMapWithoutLanguage($params[$key], $language_code)) {
                unset($params[$key]);
            }
        }

        foreach (['props', 'acf_fields'] as $key) {
            if (is_array($params[$key] ?? null) && !array_is_list($params[$key])) {
                $params[$key] = MultiLang::withoutMapsMissingLanguage($params[$key], $language_code);
            }
        }

        return $params;
    }

    /** Applies product name, optional legacy text fields, and status. */
    private static function applyPostFields(
        mixed $product,
        array $params,
        ?string $language_code,
        ?string $fallback_language,
        ?string $source_language = null
    ): void
    {
        if (array_key_exists('title', $params)) {
            $title = self::resolveProductTitle(
                MultiLang::splitValueByLanguage($params['title']),
                $language_code,
                $fallback_language
            );
            if ($title !== '') {
                $product->set_name($title);
            }
        }

        if (array_key_exists('slug', $params)) {
            $is_insert = (int) $product->get_id() === 0;
            $update_slug = ($params['update_slug'] ?? false) === true;
            if ($is_insert || $update_slug) {
                $slug = self::resolveValue($params['slug'], $language_code, $fallback_language);
                if (is_scalar($slug) && (string) $slug !== '') {
                    $product->set_slug(\sanitize_title((string) $slug));
                }
            }
        }

        if (array_key_exists('content', $params)) {
            $content = self::resolveValue($params['content'], $language_code, $fallback_language);
            $product->set_description(is_scalar($content) ? (string) $content : '');
        }

        if (array_key_exists('description', $params)) {
            $description = self::resolveValue($params['description'], $language_code, $fallback_language);
            $product->set_short_description(is_scalar($description) ? (string) $description : '');
        }

        if (array_key_exists('status', $params) && is_scalar($params['status'])) {
            $product->set_status((string) $params['status']);
        }
    }

    /** Applies WooCommerce-native product fields from the `props` payload. */
    private static function applyProductFields(
        mixed $product,
        array $params,
        int $element_index,
        ?string $language_code,
        ?string $fallback_language,
        bool $is_translation = false
    ): void
    {
        $product_fields = self::extractProductFields($params, $element_index, $language_code, $fallback_language);
        if (array_key_exists('product_type', $product_fields)) {
            self::normalizeProductType($product_fields['product_type'], $element_index);
        }

        foreach ($product_fields as $field_key => $value) {
            if ($field_key === 'product_type') continue;
            // SKU must be unique in WooCommerce's lookup table; translations share the
            // source product's SKU implicitly, so avoid re-setting it on translations.
            if ($is_translation && $field_key === 'sku') continue;
            if ($value === null && in_array($field_key, self::NULL_KEEPS_VALUE_FIELD_KEYS, true)) continue;

            if ($value !== null && !is_scalar($value)) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'props.$field_key' must resolve to a scalar or null", 400, 'invalid_param');
            }

            try {
                self::applyProductField($product, $field_key, $value);
            } catch (\WC_Data_Exception $e) {
                // WooCommerce setters reject a duplicate SKU or an unknown enum value.
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Invalid value for 'props.$field_key' :: " . $e->getMessage(), 400, 'invalid_param');
            }
        }
    }

    /** Returns the WooCommerce-native props payload. */
    private static function getProductProps(array $params, int $element_index): array
    {
        if (!array_key_exists('props', $params)) {
            return [];
        }

        if (!is_array($params['props']) || ($params['props'] !== [] && array_is_list($params['props']))) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'props' must be an object", 400, 'invalid_param');
        }

        return $params['props'];
    }

    /** Extracts known WooCommerce fields from props. */
    private static function extractProductFields(
        array $params,
        int $element_index,
        ?string $language_code,
        ?string $fallback_language
    ): array
    {
        $product_fields = [];
        $props = self::getProductProps($params, $element_index);

        foreach (self::PRODUCT_FIELD_KEYS as $field_key) {
            if (array_key_exists($field_key, $props)) {
                $product_fields[$field_key] = self::resolveValue($props[$field_key], $language_code, $fallback_language);
            }
        }

        return $product_fields;
    }

    /** Applies one WooCommerce product field to the product object. */
    private static function applyProductField(mixed $product, string $field_key, mixed $value): void
    {
        match ($field_key) {
            'sku' => $product->set_sku(self::nullableString($value)),
            'regular_price' => $product->set_regular_price(self::nullableString($value)),
            'sale_price' => $product->set_sale_price(self::nullableString($value)),
            'price' => $product->set_price(self::nullableString($value)),
            'manage_stock' => $product->set_manage_stock(self::toBool($value)),
            'stock_quantity' => $product->set_stock_quantity(self::nullableInt($value)),
            'stock_status' => $product->set_stock_status(self::nullableString($value) ?: 'instock'),
            'backorders' => $product->set_backorders(self::nullableString($value) ?: 'no'),
            'sold_individually' => $product->set_sold_individually(self::toBool($value)),
            'weight' => $product->set_weight(self::nullableString($value)),
            'length' => $product->set_length(self::nullableString($value)),
            'width' => $product->set_width(self::nullableString($value)),
            'height' => $product->set_height(self::nullableString($value)),
            'virtual' => $product->set_virtual(self::toBool($value)),
            'downloadable' => $product->set_downloadable(self::toBool($value)),
            'featured' => $product->set_featured(self::toBool($value)),
            'catalog_visibility' => $product->set_catalog_visibility(self::nullableString($value) ?: 'visible'),
            'tax_status' => $product->set_tax_status(self::nullableString($value) ?: 'taxable'),
            'tax_class' => $product->set_tax_class(self::nullableString($value)),
            'purchase_note' => $product->set_purchase_note(self::nullableString($value)),
            'menu_order' => $product->set_menu_order((int) $value),
            'reviews_allowed' => $product->set_reviews_allowed(self::toBool($value)),
            default => null,
        };
    }

    /** Stable key for custom WooCommerce product attributes. */
    private static function getAttributeLookupKey(string $attribute_name): string
    {
        return \sanitize_title($attribute_name);
    }

    /** Normalizes a product attribute value to WooCommerce custom attribute options. */
    private static function normalizeAttributeOptions(mixed $value, string $attribute_name, int $element_index): array|null
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            $option = trim((string) $value);
            return $option !== '' ? [$option] : null;
        }

        if (!is_array($value) || !array_is_list($value)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Attribute '$attribute_name' must resolve to a scalar, list or null", 400, 'invalid_param');
        }

        $options = [];
        foreach ($value as $option) {
            if (!is_scalar($option) || trim((string) $option) === '') {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Attribute '$attribute_name' contains an invalid option", 400, 'invalid_param');
            }

            $options[] = trim((string) $option);
        }

        return $options !== [] ? $options : null;
    }

    /** Builds one WooCommerce product attribute object (custom, or global when given an id). */
    private static function buildProductAttribute(
        string $attribute_name,
        array $options,
        int $position,
        bool $used_for_variations = false,
        int $attribute_id = 0
    ): \WC_Product_Attribute
    {
        // A non-zero id makes it a global (taxonomy) attribute: `$options` are then term IDs.
        $attribute = new \WC_Product_Attribute();
        $attribute->set_id($attribute_id);
        $attribute->set_name($attribute_name);
        $attribute->set_options($options);
        $attribute->set_position($position);
        $attribute->set_visible(true);
        $attribute->set_variation($used_for_variations);

        return $attribute;
    }

    /** WooCommerce id of the global attribute behind a registered `pa_*` taxonomy name, or null. */
    private static function getGlobalAttributeId(string $attribute_name): int|null
    {
        if (!str_starts_with($attribute_name, 'pa_') || !\taxonomy_exists($attribute_name)) {
            return null;
        }

        $attribute_id = (int) \wc_attribute_taxonomy_id_by_name($attribute_name);

        return $attribute_id > 0 ? $attribute_id : null;
    }

    /**
     * Resolves the options sent for a global attribute to term IDs of its taxonomy.
     *
     * Each option can be a term ID, slug or name. The terms must already exist (see
     * `POST /woocommerce/attributes/{attribute}/terms`). With WPML the term is mapped to the
     * product's language, falling back to the original when it has no translation.
     *
     * This runs inside the product's language, where WPML filters term queries by language.
     * An option that matches no term of that language (a shared `"red"` whose Italian term
     * is `rosso`, or a term not translated yet) is looked up again across all languages
     * before answering `404`.
     *
     * @param string[] $options
     * @return int[]
     */
    private static function resolveGlobalAttributeTermIds(
        string $taxonomy,
        array $options,
        int $element_index,
        ?string $language_code
    ): array
    {
        $term_ids = [];
        foreach ($options as $option) {
            $term = self::findAttributeTerm($taxonomy, $option);
            if (!$term instanceof \WP_Term && onpage_is_wpml_active()) {
                $term = Wpml::runWithLanguage('all', fn(): ?\WP_Term => self::findAttributeTerm($taxonomy, $option));
            }
            if (!$term instanceof \WP_Term) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Attribute '$taxonomy' option '$option' was not found", 404, 'not_found');
            }

            $term_id = (int) $term->term_id;
            if ($language_code !== null && onpage_is_wpml_active()) {
                $term_id = (int) \apply_filters('wpml_object_id', $term_id, $taxonomy, true, $language_code);
            }

            $term_ids[$term_id] = $term_id;
        }

        return array_values($term_ids);
    }

    /** Finds a term of an attribute taxonomy by ID, slug or name, in the current WPML language. */
    private static function findAttributeTerm(string $taxonomy, string $option): ?\WP_Term
    {
        $term = ctype_digit($option) ? \get_term((int) $option, $taxonomy) : null;
        if (!$term instanceof \WP_Term) {
            $term = \get_term_by('slug', \sanitize_title($option), $taxonomy);
        }
        if (!$term instanceof \WP_Term) {
            $term = \get_term_by('name', $option, $taxonomy);
        }

        return $term instanceof \WP_Term ? $term : null;
    }

    /**
     * Replaces custom attributes when the payload includes `attributes`.
     *
     * An empty set (`null` or `{}`) removes every attribute, global ones included.
     *
     * With $keep_unsent_languages, an attribute sent as a language map without this
     * language keeps the product's current attribute of that name, custom or global.
     */
    private static function applyProductAttributes(
        mixed $product,
        array $params,
        int $element_index,
        ?string $language_code,
        ?string $fallback_language,
        bool $keep_unsent_languages = false
    ): void
    {
        if (!array_key_exists('attributes', $params)) {
            return;
        }

        $attributes_payload = $params['attributes'];

        // `attributes: null` or `{}` (normalized to []) clears the whole set, global `pa_*`
        // attributes included. Checked on the sent payload, before the per-language filter
        // below, so a map that only lacks this language never reads as "clear".
        if ($attributes_payload === []) {
            $product->set_attributes([]);
            return;
        }

        $kept_keys = [];
        if ($keep_unsent_languages && is_array($attributes_payload)) {
            foreach ($attributes_payload as $attribute_name => $value) {
                if (MultiLang::isMapWithoutLanguage($value, $language_code)) {
                    $kept_keys[self::getAttributeLookupKey(trim((string) $attribute_name))] = true;
                    unset($attributes_payload[$attribute_name]);
                }
            }
        }

        $payload = MultiLang::resolveFields($attributes_payload, $language_code, $fallback_language);
        $attributes = [];
        $used_for_variations = $product instanceof \WC_Product_Variable || $product->get_type() === 'variable';

        // Global `pa_*` attributes already on the product (for example added by a site admin in
        // WooCommerce, with the variations built on them) are kept as they are, unless the
        // payload sends a key with the same name. A payload key naming a registered `pa_*`
        // taxonomy is saved as that global attribute; any other key is a custom attribute.
        $payload_keys = [];
        foreach (array_keys($payload) as $attribute_name) {
            $attribute_name = trim((string) $attribute_name);
            if ($attribute_name !== '') {
                $payload_keys[self::getAttributeLookupKey($attribute_name)] = true;
            }
        }

        foreach ($product->get_attributes() as $existing_key => $existing_attribute) {
            if (!$existing_attribute instanceof \WC_Product_Attribute) {
                continue;
            }

            $existing_key = self::getAttributeLookupKey((string) $existing_key);
            if (!$existing_attribute->is_taxonomy() && !isset($kept_keys[$existing_key])) {
                continue;
            }

            if (isset($payload_keys[$existing_key])) {
                continue;
            }

            $existing_attribute->set_position(count($attributes));
            $attributes[$existing_key] = $existing_attribute;
        }

        foreach ($payload as $attribute_name => $value) {
            $attribute_name = trim((string) $attribute_name);
            if ($attribute_name === '') {
                continue;
            }

            $attribute_key = self::getAttributeLookupKey($attribute_name);
            $options = self::normalizeAttributeOptions($value, $attribute_name, $element_index);

            if ($options === null) {
                continue;
            }

            $attribute_id = self::getGlobalAttributeId($attribute_name);
            $attributes[$attribute_key] = $attribute_id !== null
                ? self::buildProductAttribute(
                    $attribute_name,
                    self::resolveGlobalAttributeTermIds($attribute_name, $options, $element_index, $language_code),
                    count($attributes),
                    $used_for_variations,
                    $attribute_id
                )
                : self::buildProductAttribute(
                    $attribute_name,
                    $options,
                    count($attributes),
                    $used_for_variations
                );
        }

        $product->set_attributes($attributes);
    }

    /** Applies a top-level image (existing attachment ID or remote URL) or null to clear, after the product has an ID. */
    private static function applyImage(
        mixed $product,
        array $params,
        int $product_id,
        int $element_index,
        ?string $language_code = null,
        ?string $fallback_language = null
    ): void
    {
        if (!array_key_exists('image', $params)) {
            return;
        }

        $image = self::resolveValue($params['image'], $language_code, $fallback_language);
        if ($image === null || $image === '') {
            $product->set_image_id(0);
            self::saveProductImage($product, $element_index);
            return;
        }

        if (is_int($image)) {
            if (!RemoteMedia::isAttachmentId($image)) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'image' must be an existing attachment ID, a valid remote URL, or null", 400, 'invalid_param');
            }

            RemoteMedia::linkMediaToPost($image, $product_id);
            $product->set_image_id($image);
            self::saveProductImage($product, $element_index);
            return;
        }

        if (!is_string($image)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'image' must resolve to an attachment ID, a valid remote URL, or null", 400, 'invalid_param');
        }

        $url = RemoteMedia::sanitizeUrl($image);
        if ($url === null) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'image' must be a valid remote URL or null", 400, 'invalid_param');
        }

        $import_result = RemoteMedia::urlToPost($url, $product_id, 'image');
        $product->set_image_id((int) $import_result['attachment_id']);
        self::saveProductImage($product, $element_index);
    }

    /**
     * Applies the product gallery after the product has an ID.
     *
     * Accepts a list of remote image URLs and/or existing attachment IDs, a WPML language
     * map of lists, or a list whose entries are per-language maps — each entry is resolved
     * to this language's value, and entries with no value for the language are skipped.
     */
    private static function applyGallery(
        mixed $product,
        array $params,
        int $product_id,
        int $element_index,
        ?string $language_code = null,
        ?string $fallback_language = null
    ): void
    {
        if (!array_key_exists('gallery', $params)) {
            return;
        }

        $gallery = self::resolveValue($params['gallery'], $language_code, $fallback_language);
        if ($gallery === null || $gallery === '' || $gallery === []) {
            $product->set_gallery_image_ids([]);
            self::saveProductImage($product, $element_index);
            return;
        }

        if (!is_array($gallery) || !array_is_list($gallery)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'gallery' must resolve to a list of remote URLs or null", 400, 'invalid_param');
        }

        $attachment_ids = [];
        foreach ($gallery as $position => $entry) {
            // Each entry may itself be a per-language map (e.g. `{"it": url, "en": null}`);
            // resolve it to this language's URL. A null/empty resolution means the language
            // simply has no image at this position, so skip it rather than error.
            $entry = self::resolveValue($entry, $language_code, $fallback_language);
            if ($entry === null || $entry === '') {
                continue;
            }

            if (is_int($entry)) {
                if (!RemoteMedia::isAttachmentId($entry)) {
                    throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'gallery' entry $position must be an existing attachment ID", 400, 'invalid_param');
                }

                RemoteMedia::linkMediaToPost($entry, $product_id);
                if (!in_array($entry, $attachment_ids, true)) {
                    $attachment_ids[] = $entry;
                }
                continue;
            }

            if (!is_string($entry)) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'gallery' entry $position must be an attachment ID or a remote URL string", 400, 'invalid_param');
            }

            $url = RemoteMedia::sanitizeUrl($entry);
            if ($url === null) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'gallery' entry $position must be a valid remote URL", 400, 'invalid_param');
            }

            $import_result = RemoteMedia::urlToPost($url, $product_id, 'image');
            $attachment_id = (int) $import_result['attachment_id'];
            if ($attachment_id > 0 && !in_array($attachment_id, $attachment_ids, true)) {
                $attachment_ids[] = $attachment_id;
            }
        }

        $product->set_gallery_image_ids($attachment_ids);
        self::saveProductImage($product, $element_index);
    }

    /** Persists the product after changing its image assignment. */
    private static function saveProductImage(mixed $product, int $element_index): void
    {
        try {
            $product->save();
        } catch (\Throwable $e) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Failed to save product image :: " . $e->getMessage(), 500, 'request_failed');
        }
    }

    /**
     * Saves remaining ACF fields.
     *
     * Reusable by VariantProduct via $post_type='product_variation'; the
     * image/file URL resolution behaves identically for variations.
     */
    public static function saveAcfFields(int $object_id, array $acf_fields, string $post_type = self::PRODUCT_POST_TYPE): void
    {
        foreach ($acf_fields as $field_key => $value) {
            if (!is_string($field_key)) continue;

            Acf::updateFieldValue($object_id, $field_key, $value, 'post', $post_type);
        }
    }

    /** Loads required WPML details for a newly created source product. */
    private static function requireInsertedProductLanguageDetails(int $product_id): object
    {
        $language_details = \apply_filters('wpml_element_language_details', null, [
            'element_id' => $product_id,
            'element_type' => self::getWpmlProductElementType(),
        ]);

        if (empty($language_details->trid) || empty($language_details->language_code)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Failed to initialize WPML language details to Product $product_id", 500, 'request_failed');
        }

        return $language_details;
    }

    /** WPML language details object for a product. */
    private static function getProductLanguageDetails(int $product_id): mixed
    {
        if (!onpage_is_wpml_active()) return null;

        return \apply_filters('wpml_element_language_details', null, [
            'element_id' => $product_id,
            'element_type' => self::getWpmlProductElementType(),
        ]);
    }

    /** Returns WPML translations map for a product translation group. */
    private static function getProductTranslations(int $trid): array
    {
        if (!onpage_is_wpml_active()) return [];

        $translations = \apply_filters(
            'wpml_get_element_translations',
            [],
            $trid,
            self::getWpmlProductElementType(),
            false,
            true
        );

        return is_array($translations) ? $translations : [];
    }

    /** Drops from the product's WPML group the language slots whose product no longer exists. */
    private static function pruneOrphanProductTranslations(int $product_id): void
    {
        if (!onpage_is_wpml_active()) return;

        $trid = self::getProductTrid($product_id);
        if (!$trid) return;

        Wpml::deleteOrphanPostTranslations($trid, self::getWpmlProductElementType());
    }

    /** Returns WPML trid for a product, when available. */
    private static function getProductTrid(int $product_id): int|null
    {
        if (!onpage_is_wpml_active()) return null;

        if (\function_exists('wpml_get_content_trid')) {
            $trid = \wpml_get_content_trid(self::PRODUCT_POST_TYPE, $product_id);
            if ($trid) return (int) $trid;
        }

        $language_details = self::getProductLanguageDetails($product_id);

        return !empty($language_details?->trid) ? (int) $language_details->trid : null;
    }

    /**
     * Full language_code => product_id map for a product, resolving its own
     * language and translation group. Falls back to just the product itself.
     */
    public static function buildTranslationsMap(int $product_id): array
    {
        $language_details = self::getProductLanguageDetails($product_id);
        $current_language = !empty($language_details?->language_code)
            ? (string) $language_details->language_code
            : null;

        $translations = self::getTranslationProductIds(
            $product_id,
            $current_language,
            self::getProductTrid($product_id)
        );

        return array_map('intval', $translations);
    }

    /** Maps language codes to product IDs for translations of a product. */
    private static function getTranslationProductIds(
        int $product_id,
        ?string $current_language = null,
        ?int $trid = null
    ): array {
        if (!onpage_is_wpml_active()) {
            return $current_language ? [$current_language => $product_id] : [];
        }

        $translation_ids = [];

        if (\function_exists('wpml_get_content_translations_filter')) {
            $translations = \wpml_get_content_translations_filter([], $product_id, self::PRODUCT_POST_TYPE);
            if (is_array($translations)) {
                foreach ($translations as $lang => $translation) {
                    $translated_product_id = isset($translation->element_id) ? (int) $translation->element_id : 0;
                    if (onpage_post_exists($translated_product_id)) {
                        $translation_ids[(string) $lang] = $translated_product_id;
                    }
                }
            }
        }

        if (count($translation_ids) <= 1) {
            $master_product_id = \apply_filters('wpml_master_post_from_duplicate', $product_id);
            $duplicate_source_id = $master_product_id ? (int) $master_product_id : $product_id;
            $duplicates = \apply_filters('wpml_post_duplicates', $duplicate_source_id);

            if (is_array($duplicates)) {
                foreach ($duplicates as $lang => $duplicate_product_id) {
                    $duplicate_product_id = (int) $duplicate_product_id;
                    if (onpage_post_exists($duplicate_product_id)) {
                        $translation_ids[(string) $lang] = $duplicate_product_id;
                    }
                }
            }

            if ($master_product_id) {
                $master_language_details = self::getProductLanguageDetails((int) $master_product_id);
                $master_language_code = !empty($master_language_details?->language_code)
                    ? (string) $master_language_details->language_code
                    : $current_language;

                if ($master_language_code) {
                    $translation_ids[$master_language_code] = (int) $master_product_id;
                }
            } elseif ($current_language) {
                $translation_ids[$current_language] = $product_id;
            }
        }

        if ($trid) {
            foreach (self::getProductTranslations($trid) as $lang => $translation) {
                $translated_product_id = isset($translation->element_id) ? (int) $translation->element_id : 0;
                if (onpage_post_exists($translated_product_id)) {
                    $translation_ids[(string) $lang] = $translated_product_id;
                }
            }
        }

        if ($translation_ids === [] && $current_language) {
            $translation_ids[$current_language] = $product_id;
        }

        return $translation_ids;
    }

    /** Sets WPML element language details for a product. */
    private static function setProductLanguage(int $product_id, string $lang, int|false $trid = false, ?string $source_lang = null): void
    {
        if (!onpage_is_wpml_active()) return;

        \do_action('wpml_set_element_language_details', [
            'element_id' => $product_id,
            'element_type' => self::getWpmlProductElementType(),
            'trid' => $trid,
            'language_code' => $lang,
            'source_language_code' => $source_lang,
        ]);
    }

    /** Returns full WPML element type for WooCommerce products. */
    private static function getWpmlProductElementType(): string
    {
        return (string) \apply_filters('wpml_element_type', self::PRODUCT_POST_TYPE);
    }

    /** Whether two products belong to the same WPML translation group. */
    private static function areSameTranslationGroup(int $left_product_id, int $right_product_id): bool
    {
        if ($left_product_id === $right_product_id) return true;
        if (!onpage_is_wpml_active()) return false;

        $left_trid = self::getProductTrid($left_product_id);
        $right_trid = self::getProductTrid($right_product_id);

        return $left_trid !== null && $left_trid === $right_trid;
    }

    /** Deletes a product by ID. */
    public static function deleteById(int $product_id, bool $ignore_missing): void
    {
        self::requireWooCommerce();

        $product = \wc_get_product($product_id);
        if (!$product || \get_post_type($product_id) !== self::PRODUCT_POST_TYPE) {
            if ($ignore_missing) return;

            throw onpage_http_exception(self::ERROR_PREFIX . " :: Product $product_id not found", 404, 'not_found');
        }

        // WC_Data::delete() answers true whenever a data store exists, whatever
        // wp_delete_post() did: whether the post is gone is the real outcome.
        $product->delete(true);
        \clean_post_cache($product_id);
        if (\get_post($product_id)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Failed to delete product $product_id", 500, 'delete_failed');
        }
    }

    /**
     * Deletes every product sharing a local_key, i.e. the whole WPML translation group.
     *
     * All translations carry the same local_key, so deleting only the first holder would
     * leave the other languages behind — same semantics as Post::deleteByLocalKey().
     */
    public static function deleteByLocalKey(string $local_key, bool $ignore_missing): void
    {
        self::requireWooCommerce();

        $product_ids = self::findProductIdsByLocalKey($local_key);
        if ($product_ids === []) {
            if ($ignore_missing) return;

            throw onpage_http_exception(self::ERROR_PREFIX . " :: Product with local_key '$local_key' not found", 404, 'not_found');
        }

        // Missing IDs are tolerated inside the loop: one delete can cascade to another
        // holder, and the group was proven to exist by the check above.
        foreach ($product_ids as $product_id) {
            self::deleteById($product_id, true);
        }
    }

    private static function nullableString(mixed $value): string
    {
        if ($value === null) return '';

        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableInt(mixed $value): int|null
    {
        if ($value === null || $value === '') return null;

        return is_numeric((string) $value) ? (int) $value : null;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (int) $value === 1;
        if (!is_string($value)) return false;

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
