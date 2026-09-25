<?php



namespace OnPage\Services\WooCommerce;



use OnPage\Services\Input;
use OnPage\Services\MultiLang;
use OnPage\Services\PostRepository;
use OnPage\Services\RemoteMedia;
use OnPage\Services\Wpml;



class VariantProduct
{
    private const ERROR_PREFIX = 'WooCommerce Variant Product';
    private const PRODUCT_POST_TYPE = 'product';
    private const VARIATION_POST_TYPE = 'product_variation';
    private const LOCAL_KEY_META = PostRepository::LOCAL_KEY_META;
    private const SUPPORTED_STATUSES = ['publish', 'private'];
    private const STATUS_ALIASES = [
        'draft' => 'private',
        'pending' => 'private',
        'disabled' => 'private',
        'enabled' => 'publish',
    ];

    private const FIELD_KEYS = [
        'sku',
        'regular_price',
        'sale_price',
        'price',
        'manage_stock',
        'stock_quantity',
        'stock_status',
        'backorders',
        'weight',
        'length',
        'width',
        'height',
        'virtual',
        'downloadable',
        'tax_class',
        'menu_order',
        'image_id',
    ];

    /** Variable parent IDs whose aggregates must be resynced once at end of batch. */
    private static array $deferredParentSyncIds = [];



    /** Ensures WooCommerce variation CRUD classes are available. */
    private static function requireWooCommerce(): void
    {
        if (!\function_exists('wc_get_product') || !class_exists('\WC_Product_Variation')) {
            throw httpException(self::ERROR_PREFIX . ' :: WooCommerce is required', 500, 'woocommerce_required');
        }
    }

    /** Normalizes payload status to the statuses WooCommerce loads in the variations UI. */
    private static function normalizeStatus(mixed $value, int $element_index): string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'status' must be a string", 400, 'invalid_param');
        }

        $status = \sanitize_key((string) $value);
        $status = self::STATUS_ALIASES[$status] ?? $status;

        if (!in_array($status, self::SUPPORTED_STATUSES, true)) {
            throw httpException(
                self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'status' must be one of: publish, private, enabled, disabled, draft, pending",
                400,
                'invalid_param'
            );
        }

        return $status;
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
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter '$key' must be an object", 400, 'invalid_param');
        }

        return $params[$key];
    }

    /** Resolves a scalar or language-map payload value for one language. */
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

    /** Returns language resolution context for a variation payload. */
    private static function getLanguageContext(array $params): array
    {
        $attributes = is_array($params['attributes'] ?? null) ? $params['attributes'] : [];
        $acf_fields = is_array($params['acf_fields'] ?? null) ? $params['acf_fields'] : [];

        $languages = array_values(array_unique(array_merge(
            MultiLang::getLanguages($params['name'] ?? ''),
            MultiLang::getLanguages($params['description'] ?? ''),
            MultiLang::getLanguages($params['short_description'] ?? ''),
            MultiLang::getLanguages($params['long_description'] ?? ''),
            MultiLang::getLanguages($params['image'] ?? ''),
            MultiLang::getFieldLanguages($attributes),
            MultiLang::getFieldLanguages($acf_fields)
        )));

        $fallback_language = $languages[0] ?? null;
        $default_language = getWpmlDefaultLanguage() ?: $fallback_language;

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

    /** Resolves a variation attribute value to the single option selected by this variant. */
    private static function resolveVariationAttributePayloadValue(
        mixed $value,
        string $attribute_name,
        int $element_index,
        ?string $language_code = null,
        ?string $fallback_language = null
    ): string
    {
        MultiLang::requireWpmlForLanguageMap($value, self::ERROR_PREFIX, $element_index, 'attributes.' . $attribute_name);

        if (MultiLang::isLanguageMapShape($value)) {
            $fallback_language = $fallback_language ?: getWpmlDefaultLanguage() ?: array_key_first($value);
            $resolved = MultiLang::resolve(
                $value,
                $language_code ?: getWpmlCurrentLanguage() ?: $fallback_language,
                is_string($fallback_language) ? $fallback_language : null
            );

            if (is_scalar($resolved) && trim((string) $resolved) !== '') {
                return trim((string) $resolved);
            }
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }

        throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Variation attribute '$attribute_name' must resolve to one non-empty scalar option", 400, 'invalid_param');
    }

    /** Finds all variation IDs by local_key meta. */
    private static function findVariationIdsByLocalKey(string $local_key): array
    {
        $results = \get_posts([
            'post_type' => self::VARIATION_POST_TYPE,
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

    /** Finds one variation ID by local_key. */
    private static function findVariationIdByLocalKey(string $local_key): int|null
    {
        $variation_ids = self::findVariationIdsByLocalKey($local_key);

        return $variation_ids[0] ?? null;
    }

    /** Finds one variation ID by local_key for a specific parent product. */
    private static function findVariationIdByLocalKeyForParent(string $local_key, int $parent_id): int|null
    {
        $results = \get_posts([
            'post_type' => self::VARIATION_POST_TYPE,
            'post_status' => 'any',
            'post_parent' => $parent_id,
            'fields' => 'ids',
            'numberposts' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [[
                'key' => self::LOCAL_KEY_META,
                'value' => $local_key,
                'compare' => '=',
            ]],
        ]);

        return is_array($results) && isset($results[0]) ? (int) $results[0] : null;
    }

    /** Returns a variation product by ID or throws. */
    private static function requireVariationById(int $variation_id): \WC_Product_Variation
    {
        self::requireWooCommerce();

        $variation = \wc_get_product($variation_id);
        if (!$variation instanceof \WC_Product_Variation || \get_post_type($variation_id) !== self::VARIATION_POST_TYPE) {
            throw httpException(self::ERROR_PREFIX . " :: Variation $variation_id not found", 404, 'not_found');
        }

        return $variation;
    }

    /** Resolves the parent variable product from parent_id or parent local_key. */
    private static function requireParentProduct(array $params, int $element_index): \WC_Product_Variable
    {
        $parent_id = Input::positiveInt($params['parent_id'] ?? null);
        $parent_key = Input::localKey($params['parent'] ?? null);

        if ($parent_id === null && $parent_key === null) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'parent_id' or 'parent' is required", 400, 'invalid_param');
        }

        $parent = $parent_id !== null
            ? Product::requireProductById($parent_id)
            : Product::requireProductByLocalKey($parent_key);

        if (!$parent instanceof \WC_Product_Variable || $parent->get_type() !== 'variable') {
            $parent_type = method_exists($parent, 'get_type') ? (string) $parent->get_type() : 'unknown';
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parent product must be a variable product; current type is '$parent_type'", 400, 'invalid_param');
        }

        if ($parent_id !== null && $parent_key !== null) {
            // The local_key resolves to the group's default-language product, while an
            // explicit parent_id may legitimately point at one of its translations: both
            // refer to the same product, so the group is what must match.
            $resolved_parent_id = (int) Product::requireProductByLocalKey($parent_key)->get_id();
            $group_parent_ids = self::getAllowedParentIdsForLocalKey($resolved_parent_id);

            if (!in_array((int) $parent->get_id(), $group_parent_ids, true)) {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: parent_id and parent refer to different products", 409, 'parent_mismatch');
            }
        }

        return $parent;
    }

    /** Returns a variable parent product by ID or throws the standard parent error. */
    private static function requireVariableParentById(int $parent_id, int $element_index): \WC_Product_Variable
    {
        $parent = Product::requireProductById($parent_id);
        if (!$parent instanceof \WC_Product_Variable || $parent->get_type() !== 'variable') {
            $parent_type = method_exists($parent, 'get_type') ? (string) $parent->get_type() : 'unknown';
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parent product must be a variable product; current type is '$parent_type'", 400, 'invalid_param');
        }

        return $parent;
    }

    /** WPML language details object for a parent product. */
    private static function getProductLanguageDetails(int $product_id): mixed
    {
        if (!isWpmlActive()) return null;

        return \apply_filters('wpml_element_language_details', null, [
            'element_id' => $product_id,
            'element_type' => self::getWpmlProductElementType(),
        ]);
    }

    /** Returns WPML trid for a parent product, when available. */
    private static function getProductTrid(int $product_id): int|null
    {
        if (!isWpmlActive()) return null;

        if (\function_exists('wpml_get_content_trid')) {
            $trid = \wpml_get_content_trid(self::PRODUCT_POST_TYPE, $product_id);
            if ($trid) return (int) $trid;
        }

        $language_details = self::getProductLanguageDetails($product_id);

        return !empty($language_details?->trid) ? (int) $language_details->trid : null;
    }

    /** Returns WPML translations map for a parent product translation group. */
    private static function getProductTranslations(int $trid): array
    {
        if (!isWpmlActive()) return [];

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

    /** Maps language codes to translated parent product IDs. */
    private static function getTranslationProductIds(
        int $product_id,
        ?string $current_language = null,
        ?int $trid = null
    ): array {
        if (!isWpmlActive()) {
            return $current_language ? [$current_language => $product_id] : [];
        }

        $translation_ids = [];

        if (\function_exists('wpml_get_content_translations_filter')) {
            $translations = \wpml_get_content_translations_filter([], $product_id, self::PRODUCT_POST_TYPE);
            if (is_array($translations)) {
                foreach ($translations as $lang => $translation) {
                    $translated_product_id = isset($translation->element_id) ? (int) $translation->element_id : 0;
                    if (postExists($translated_product_id)) {
                        $translation_ids[(string) $lang] = $translated_product_id;
                    }
                }
            }
        }

        if ($trid) {
            foreach (self::getProductTranslations($trid) as $lang => $translation) {
                $translated_product_id = isset($translation->element_id) ? (int) $translation->element_id : 0;
                if (postExists($translated_product_id)) {
                    $translation_ids[(string) $lang] = $translated_product_id;
                }
            }
        }

        if ($translation_ids === [] && $current_language) {
            $translation_ids[$current_language] = $product_id;
        }

        return $translation_ids;
    }

    /** WPML language details object for a variation. */
    private static function getVariationLanguageDetails(int $variation_id): mixed
    {
        if (!isWpmlActive()) return null;

        return \apply_filters('wpml_element_language_details', null, [
            'element_id' => $variation_id,
            'element_type' => self::getWpmlVariationElementType(),
        ]);
    }

    /** Returns WPML translations map for a variation translation group. */
    private static function getVariationTranslations(int $trid): array
    {
        if (!isWpmlActive()) return [];

        $translations = \apply_filters(
            'wpml_get_element_translations',
            [],
            $trid,
            self::getWpmlVariationElementType(),
            false,
            true
        );

        return is_array($translations) ? $translations : [];
    }

    /** Ensures one variation has WPML language details and returns its trid. */
    private static function ensureVariationTranslationGroup(int $variation_id, string $language_code, int $element_index): int
    {
        $details = self::getVariationLanguageDetails($variation_id);
        $trid = isset($details?->trid) ? (int) $details->trid : 0;
        if (!$trid) {
            self::setVariationLanguage($variation_id, $language_code);
            $details = self::getVariationLanguageDetails($variation_id);
            $trid = isset($details?->trid) ? (int) $details->trid : 0;
        }

        if (!$trid) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Unable to resolve variation translation group (trid)", 500, 'wpml_error');
        }

        return $trid;
    }

    /** Sets WPML element language details for a variation. */
    private static function setVariationLanguage(int $variation_id, string $lang, int|false $trid = false, ?string $source_lang = null): void
    {
        if (!isWpmlActive()) return;

        \do_action('wpml_set_element_language_details', [
            'element_id' => $variation_id,
            'element_type' => self::getWpmlVariationElementType(),
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

    /** Returns full WPML element type for WooCommerce variations. */
    private static function getWpmlVariationElementType(): string
    {
        return (string) \apply_filters('wpml_element_type', self::VARIATION_POST_TYPE);
    }

    /** Standard API response for one WooCommerce variation. */
    public static function buildResponse(\WC_Product_Variation $variation): array
    {
        $variation_id = (int) $variation->get_id();
        $parent_id = (int) $variation->get_parent_id();

        return [
            'id' => $variation_id,
            'parent_id' => $parent_id,
            'parent' => Input::localKeyOut(\get_post_meta($parent_id, self::LOCAL_KEY_META, true)),
            'local_key' => Input::localKeyOut(\get_post_meta($variation_id, self::LOCAL_KEY_META, true)),
            'status' => $variation->get_status(),
            'description' => $variation->get_description(),
            'attributes' => $variation->get_attributes(),
            'woocommerce' => [
                'product_type' => $variation->get_type(),
                'sku' => $variation->get_sku(),
                'regular_price' => $variation->get_regular_price(),
                'sale_price' => $variation->get_sale_price(),
                'price' => $variation->get_price(),
                'manage_stock' => $variation->get_manage_stock(),
                'stock_quantity' => $variation->get_stock_quantity(),
                'stock_status' => $variation->get_stock_status(),
                'backorders' => $variation->get_backorders(),
                'weight' => $variation->get_weight(),
                'length' => $variation->get_length(),
                'width' => $variation->get_width(),
                'height' => $variation->get_height(),
                'virtual' => $variation->get_virtual(),
                'downloadable' => $variation->get_downloadable(),
                'tax_class' => $variation->get_tax_class(),
                'menu_order' => $variation->get_menu_order(),
                'image_id' => $variation->get_image_id(),
                'permalink' => $variation->get_permalink(),
            ],
            'acf_fields' => \function_exists('get_fields') ? \get_fields($variation_id) : null,
        ];
    }

    /** Lists variations, optionally filtered by id/local_key/parent_id/parent. */
    public static function list(\WP_REST_Request $request): array
    {
        self::requireWooCommerce();

        $variation_id = Input::positiveInt($request->get_param('id'));
        if ($variation_id !== null) {
            return [self::buildResponse(self::requireVariationById($variation_id))];
        }

        $local_key = Input::localKey($request->get_param('local_key'));
        if ($local_key !== null) {
            $resolved_id = self::findVariationIdByLocalKey($local_key);

            return $resolved_id ? [self::buildResponse(self::requireVariationById($resolved_id))] : [];
        }

        $parent_id = Input::positiveInt($request->get_param('parent_id'));
        $parent = Input::localKey($request->get_param('parent'));
        if ($parent_id === null && $parent !== null) {
            $parent_id = (int) Product::requireProductByLocalKey($parent)->get_id();
        }

        $query = [
            'post_type' => self::VARIATION_POST_TYPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ];
        if ($parent_id !== null) {
            $query['post_parent'] = $parent_id;
        }

        $variation_ids = \get_posts($query);

        return array_map(
            fn(int|string $id): array => self::buildResponse(self::requireVariationById((int) $id)),
            is_array($variation_ids) ? $variation_ids : []
        );
    }

    /** Creates or updates one variant product from params. */
    public static function save(array $params, int $element_index): int
    {
        self::requireWooCommerce();

        if ($params !== [] && array_is_list($params)) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Variant payload must be an object", 400, 'invalid_param');
        }

        if (array_key_exists('image', $params)) {
            MultiLang::requireWpmlForLanguageMap($params['image'], self::ERROR_PREFIX, $element_index, 'image');
        }

        if (array_key_exists('acf_fields', $params)) {
            $acf_fields = self::optionalObjectParam($params, 'acf_fields', $element_index) ?? [];
            MultiLang::requireWpmlForFieldMap($acf_fields, self::ERROR_PREFIX, $element_index, 'acf_fields');
        }

        $local_key = Input::requireLocalKeyParam($params, 'local_key', self::ERROR_PREFIX, $element_index);
        $parent = self::requireParentProduct($params, $element_index);

        $language = self::getLanguageContext($params);
        self::requireWpmlForTranslations($language['translated_languages'], $element_index);

        if (isWpmlActive() && $language['translated_languages'] !== []) {
            return self::saveTranslatedFromParams($params, $local_key, $parent, $language, $element_index);
        }

        return self::saveOneFromParams($params, $local_key, $parent, $element_index);
    }

    /** Creates or updates variation translations for every translated parent product. */
    private static function saveTranslatedFromParams(
        array $params,
        string $local_key,
        \WC_Product_Variable $parent,
        array $language,
        int $element_index
    ): int {
        $parent_id = (int) $parent->get_id();
        $parent_language_details = self::getProductLanguageDetails($parent_id);
        $source_language = !empty($parent_language_details?->language_code)
            ? (string) $parent_language_details->language_code
            : $language['default_language'];

        if (!$source_language) {
            return self::saveOneFromParams($params, $local_key, $parent, $element_index);
        }

        $parent_translation_ids = self::getTranslationProductIds(
            $parent_id,
            $source_language,
            self::getProductTrid($parent_id)
        );
        if (!isset($parent_translation_ids[$source_language])) {
            $parent_translation_ids[$source_language] = $parent_id;
        }

        $allowed_parent_ids = array_values(array_unique(array_map('intval', $parent_translation_ids)));
        $source_parent_id = (int) ($parent_translation_ids[$source_language] ?? $parent_id);
        $source_parent = self::requireVariableParentById($source_parent_id, $element_index);
        $source_variation_id = self::saveOneFromParams(
            $params,
            $local_key,
            $source_parent,
            $element_index,
            $source_language,
            $language['fallback_language'],
            Input::positiveInt($params['id'] ?? null),
            $allowed_parent_ids,
            false
        );

        $trid = self::ensureVariationTranslationGroup($source_variation_id, $source_language, $element_index);

        // A slot left behind by a deleted variation would claim its language in the group
        // read below, so the variation would be recreated (and left unlinked) on every
        // single import instead of being updated in place.
        Wpml::deleteOrphanPostTranslations($trid, self::getWpmlVariationElementType());

        $translations = self::getVariationTranslations($trid);

        $payload_languages = array_flip(array_map('strval', $language['translated_languages']));
        foreach ($parent_translation_ids as $language_code => $translated_parent_id) {
            $language_code = (string) $language_code;
            if ($language_code === $source_language || !isset($payload_languages[$language_code])) {
                continue;
            }

            // A translated variation can only exist below a translated parent.
            // Ignore payload languages whose parent translation does not exist yet.
            $translated_parent_id = (int) $translated_parent_id;
            if ($translated_parent_id <= 0) {
                continue;
            }

            $translation = $translations[$language_code] ?? null;
            $translated_variation_id = isset($translation->element_id)
                ? (int) $translation->element_id
                : null;
            if ($translated_variation_id && (int) \wp_get_post_parent_id($translated_variation_id) !== $translated_parent_id) {
                $translated_variation_id = null;
            }

            $translated_parent = self::requireVariableParentById($translated_parent_id, $element_index);
            $saved_translation_id = self::saveOneFromParams(
                $params,
                $local_key,
                $translated_parent,
                $element_index,
                (string) $language_code,
                $language['fallback_language'],
                $translated_variation_id,
                $allowed_parent_ids,
                true
            );

            self::setVariationLanguage($saved_translation_id, (string) $language_code, $trid, $source_language);
        }

        return $source_variation_id;
    }

    /** Creates or updates one variation under one concrete parent product. */
    private static function saveOneFromParams(
        array $params,
        string $local_key,
        \WC_Product_Variable $parent,
        int $element_index,
        ?string $language_code = null,
        ?string $fallback_language = null,
        ?int $variation_id = null,
        array $allowed_parent_ids = [],
        bool $is_translation = false
    ): int {
        $parent_id = (int) $parent->get_id();

        if ($variation_id === null) {
            $variation_id = Input::positiveInt($params['id'] ?? null);
        }
        if ($variation_id === null) {
            $variation_id = self::findVariationIdByLocalKeyForParent($local_key, $parent_id);
        }

        $variation = $variation_id !== null
            ? self::requireVariationById($variation_id)
            : new \WC_Product_Variation();

        if ($variation_id !== null && (int) $variation->get_parent_id() !== $parent_id) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: local_key '$local_key' already exists for another parent product", 409, 'duplicate_local_key');
        }

        if ($allowed_parent_ids === []) {
            $allowed_parent_ids = self::getAllowedParentIdsForLocalKey($parent_id);
        }
        self::assertLocalKeyAvailableForParentSet($local_key, $variation_id, $parent_id, $allowed_parent_ids, $element_index);

        return Wpml::runWithLanguage($language_code, function () use (
            $variation,
            $parent,
            $params,
            $local_key,
            $element_index,
            $language_code,
            $fallback_language,
            $variation_id,
            $parent_id,
            $is_translation
        ): int {
            $variation->set_parent_id($parent_id);
            try {
                self::applyFields($variation, $params, $element_index, $language_code, $fallback_language, $is_translation);
                self::applyAttributes($variation, $parent, $params, $element_index, $variation_id === null, $language_code, $fallback_language);
                $saved_id = (int) $variation->save();
            } catch (\OnPage\Exceptions\HttpException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Failed to save variation :: " . $e->getMessage(), 500, 'request_failed');
            }

            \update_post_meta($saved_id, self::LOCAL_KEY_META, $local_key);
            self::applyImage($variation, $params, $saved_id, $element_index, $language_code, $fallback_language);
            Product::syncParentDownloadsToVariation($variation, $element_index);

            if (is_array($params['acf_fields'] ?? null)) {
                $acf_fields = MultiLang::resolveFields($params['acf_fields'], $language_code, $fallback_language);
                Product::saveAcfFields($saved_id, $acf_fields, self::VARIATION_POST_TYPE);
            }

            self::syncParentProduct($parent_id, $element_index);

            return $saved_id;
        });
    }

    /** Returns the parent product IDs that may share translated variations with the same local_key. */
    private static function getAllowedParentIdsForLocalKey(int $parent_id): array
    {
        if (!isWpmlActive()) {
            return [$parent_id];
        }

        $details = self::getProductLanguageDetails($parent_id);
        $language_code = !empty($details?->language_code) ? (string) $details->language_code : null;
        $translation_ids = self::getTranslationProductIds($parent_id, $language_code, self::getProductTrid($parent_id));

        $allowed_parent_ids = array_values(array_unique(array_map('intval', $translation_ids)));
        if (!in_array($parent_id, $allowed_parent_ids, true)) {
            $allowed_parent_ids[] = $parent_id;
        }

        return $allowed_parent_ids;
    }

    /** Prevents the same variation local_key from leaking into unrelated parent products. */
    private static function assertLocalKeyAvailableForParentSet(
        string $local_key,
        ?int $variation_id,
        int $parent_id,
        array $allowed_parent_ids,
        int $element_index
    ): void {
        $allowed_parent_ids = $allowed_parent_ids !== []
            ? array_values(array_unique(array_map('intval', $allowed_parent_ids)))
            : [$parent_id];

        foreach (self::findVariationIdsByLocalKey($local_key) as $existing_variation_id) {
            if ($variation_id !== null && (int) $existing_variation_id === $variation_id) {
                continue;
            }

            $existing_parent_id = (int) \wp_get_post_parent_id((int) $existing_variation_id);
            if ($existing_parent_id === $parent_id) {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: local_key '$local_key' already exists", 409, 'duplicate_local_key');
            }

            if (in_array($existing_parent_id, $allowed_parent_ids, true)) {
                continue;
            }

            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: local_key '$local_key' already exists for another parent product", 409, 'duplicate_local_key');
        }
    }

    /** Applies optional variation status, description and native WooCommerce props. */
    private static function applyFields(
        \WC_Product_Variation $variation,
        array $params,
        int $element_index,
        ?string $language_code = null,
        ?string $fallback_language = null,
        bool $is_translation = false
    ): void
    {
        if (array_key_exists('status', $params)) {
            $variation->set_status(self::normalizeStatus($params['status'], $element_index));
        }

        if (array_key_exists('name', $params)) {
            $name = self::resolveValue($params['name'], $language_code, $fallback_language);
            if ($name !== null && !is_scalar($name)) {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'name' must resolve to a string or null", 400, 'invalid_param');
            }

            if (is_scalar($name) && trim((string) $name) !== '') {
                $variation->set_name(trim((string) $name));
            }
        }

        $description_key = array_key_exists('short_description', $params)
            ? 'short_description'
            : (array_key_exists('description', $params) ? 'description' : null);
        if ($description_key !== null) {
            $description = self::resolveValue($params[$description_key], $language_code, $fallback_language);
            if ($description !== null && !is_scalar($description)) {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter '$description_key' must resolve to a string or null", 400, 'invalid_param');
            }

            $variation->set_description($description === null ? '' : (string) $description);
        }

        $props = self::optionalObjectParam($params, 'props', $element_index) ?? [];
        foreach (self::FIELD_KEYS as $field_key) {
            if (array_key_exists($field_key, $props)) {
                // SKU must be globally unique in WooCommerce; translated variations
                // keep the source variation's SKU conceptually, so avoid duplicating it.
                if ($is_translation && $field_key === 'sku') continue;

                self::applyField($variation, $field_key, $props[$field_key]);
            }
        }
    }

    /** Applies one WooCommerce variation field. */
    private static function applyField(\WC_Product_Variation $variation, string $field_key, mixed $value): void
    {
        match ($field_key) {
            'sku' => $variation->set_sku(self::nullableString($value)),
            'regular_price' => $variation->set_regular_price(self::nullableString($value)),
            'sale_price' => $variation->set_sale_price(self::nullableString($value)),
            'price' => $variation->set_price(self::nullableString($value)),
            'manage_stock' => $variation->set_manage_stock(self::toBool($value)),
            'stock_quantity' => $variation->set_stock_quantity(self::nullableInt($value)),
            'stock_status' => $variation->set_stock_status(self::nullableString($value) ?: 'instock'),
            'backorders' => $variation->set_backorders(self::nullableString($value) ?: 'no'),
            'weight' => $variation->set_weight(self::nullableString($value)),
            'length' => $variation->set_length(self::nullableString($value)),
            'width' => $variation->set_width(self::nullableString($value)),
            'height' => $variation->set_height(self::nullableString($value)),
            'virtual' => $variation->set_virtual(self::toBool($value)),
            'downloadable' => $variation->set_downloadable(self::toBool($value)),
            'tax_class' => $variation->set_tax_class(self::nullableString($value)),
            'menu_order' => $variation->set_menu_order((int) $value),
            'image_id' => $variation->set_image_id(self::nullableInt($value) ?: 0),
            default => null,
        };
    }

    /** Applies a top-level image (existing attachment ID or remote URL) or null to clear, after the variation has an ID. */
    private static function applyImage(
        \WC_Product_Variation $variation,
        array $params,
        int $variation_id,
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
            $variation->set_image_id(0);
            $variation->save();
            return;
        }

        if (is_int($image)) {
            if (!RemoteMedia::isAttachmentId($image)) {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'image' must be an existing attachment ID, a valid remote URL, or null", 400, 'invalid_param');
            }

            RemoteMedia::linkMediaToPost($image, $variation_id);
            $variation->set_image_id($image);
            $variation->save();
            return;
        }

        if (!is_string($image)) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'image' must resolve to an attachment ID, a valid remote URL, or null", 400, 'invalid_param');
        }

        $url = RemoteMedia::sanitizeUrl($image);
        if ($url === null) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'image' must be a valid remote URL or null", 400, 'invalid_param');
        }

        $import_result = RemoteMedia::urlToPost($url, $variation_id, 'image');
        $variation->set_image_id((int) $import_result['attachment_id']);
        $variation->save();
    }

    /** Applies variation attributes after validating them against parent variation attributes. */
    private static function applyAttributes(
        \WC_Product_Variation $variation,
        \WC_Product_Variable $parent,
        array $params,
        int $element_index,
        bool $creating,
        ?string $language_code = null,
        ?string $fallback_language = null
    ): void {
        if (!array_key_exists('attributes', $params)) {
            if ($creating) {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'attributes' is required when creating a variant", 400, 'invalid_param');
            }

            return;
        }

        $payload = self::optionalObjectParam($params, 'attributes', $element_index) ?? [];
        if ($payload === []) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'attributes' must not be empty", 400, 'invalid_param');
        }

        $parent_attributes = self::getParentVariationAttributes($parent);
        $attributes = [];

        foreach ($payload as $attribute_name => $value) {
            $attribute_value = self::resolveVariationAttributePayloadValue(
                $value,
                (string) $attribute_name,
                $element_index,
                $language_code,
                $fallback_language
            );

            $attribute_key = self::normalizeAttributeKey((string) $attribute_name);
            if (!isset($parent_attributes[$attribute_key])) {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Attribute '$attribute_name' is not configured as a variation attribute on the parent product", 400, 'invalid_param');
            }

            $attributes[$attribute_key] = self::resolveVariationAttributeValue(
                $parent_attributes[$attribute_key],
                $attribute_value,
                (string) $attribute_name,
                $element_index
            );
        }

        $variation->set_attributes($attributes);
    }

    /**
     * Queues the variable parent for a single resync at end of batch.
     *
     * Syncing inside every variation save is O(num_variations) per call, so a
     * batch of N variations on one parent costs O(N^2). Deferring and
     * de-duplicating collapses it to one sync per unique parent, with an
     * identical final result (sync recomputes aggregates from the saved children).
     */
    private static function syncParentProduct(int $parent_id, int $element_index): void
    {
        if ($parent_id > 0) {
            self::$deferredParentSyncIds[$parent_id] = $parent_id;
        }
    }

    /** Runs the queued variable-parent resyncs once per unique parent, then clears the queue. */
    public static function flushDeferredParentSyncs(): void
    {
        if (self::$deferredParentSyncIds === []) {
            return;
        }

        $parent_ids = self::$deferredParentSyncIds;
        self::$deferredParentSyncIds = [];

        $failures = [];
        foreach ($parent_ids as $parent_id) {
            try {
                \WC_Product_Variable::sync($parent_id);
                if (\function_exists('wc_delete_product_transients')) {
                    \wc_delete_product_transients($parent_id);
                }

                \delete_transient('wc_product_children_' . $parent_id);
                \clean_post_cache($parent_id);
            } catch (\Throwable $e) {
                $failures[] = "parent $parent_id: " . $e->getMessage();
            }
        }

        if ($failures !== []) {
            throw httpException(self::ERROR_PREFIX . ' :: Failed to sync parent product(s) :: ' . implode('; ', $failures), 500, 'request_failed');
        }
    }

    /** Parent attributes that are enabled for variations, keyed as WooCommerce stores variation meta. */
    private static function getParentVariationAttributes(\WC_Product_Variable $parent): array
    {
        $variation_attributes = [];

        foreach ($parent->get_attributes() as $attribute) {
            if (!$attribute instanceof \WC_Product_Attribute || !$attribute->get_variation()) {
                continue;
            }

            $variation_attributes[self::normalizeAttributeKey($attribute->get_name())] = $attribute;
        }

        return $variation_attributes;
    }

    /** Normalizes payload attribute names to WooCommerce variation attribute keys. */
    private static function normalizeAttributeKey(string $attribute_name): string
    {
        return \sanitize_title(preg_replace('/^attribute_/', '', trim($attribute_name)));
    }

    /** Resolves and validates one variation attribute value against parent options. */
    private static function resolveVariationAttributeValue(
        \WC_Product_Attribute $attribute,
        string $value,
        string $payload_attribute_name,
        int $element_index
    ): string {
        $value = trim($value);

        if ($attribute->is_taxonomy()) {
            $taxonomy = $attribute->get_name();
            $term = is_numeric($value)
                ? \get_term((int) $value, $taxonomy)
                : null;
            if (!$term || \is_wp_error($term)) {
                $term = \get_term_by('slug', \sanitize_title($value), $taxonomy);
            }
            if (!$term || \is_wp_error($term)) {
                $term = \get_term_by('name', $value, $taxonomy);
            }

            if (!$term || \is_wp_error($term)) {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Attribute '$payload_attribute_name' option '$value' was not found", 404, 'not_found');
            }

            $allowed_term_ids = array_map('intval', $attribute->get_options());
            if ($allowed_term_ids !== [] && !in_array((int) $term->term_id, $allowed_term_ids, true)) {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Attribute '$payload_attribute_name' option '$value' is not enabled on the parent product", 400, 'invalid_param');
            }

            return (string) $term->slug;
        }

        $options = array_map('strval', $attribute->get_options());
        if ($options !== [] && !in_array($value, $options, true)) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Attribute '$payload_attribute_name' option '$value' is not enabled on the parent product", 400, 'invalid_param');
        }

        return $value;
    }

    /** Deletes a variation by ID. */
    public static function deleteById(int $variation_id, bool $ignore_missing): void
    {
        self::requireWooCommerce();

        try {
            $variation = self::requireVariationById($variation_id);
        } catch (\Throwable $e) {
            if ($ignore_missing) return;

            throw $e;
        }

        $deleted = $variation->delete(true);
        if (!$deleted) {
            throw httpException(self::ERROR_PREFIX . " :: Failed to delete variation $variation_id", 500, 'delete_failed');
        }
    }

    /**
     * Deletes every variation sharing a local_key, i.e. one per language of the parent group.
     *
     * Translated variations carry the same local_key as their source variation, so deleting
     * only the first holder would leave the other languages behind.
     */
    public static function deleteByLocalKey(string $local_key, bool $ignore_missing): void
    {
        self::requireWooCommerce();

        $variation_ids = self::findVariationIdsByLocalKey($local_key);
        if ($variation_ids === []) {
            if ($ignore_missing) return;

            throw httpException(self::ERROR_PREFIX . " :: Variation with local_key '$local_key' not found", 404, 'not_found');
        }

        // Missing IDs are tolerated inside the loop: deleting a variation can cascade to
        // another holder, and the set was proven to exist by the check above.
        foreach ($variation_ids as $variation_id) {
            self::deleteById($variation_id, true);
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
