<?php



namespace OnPage\Services\WooCommerce;



use OnPage\Services\Input;
use OnPage\Services\MultiLang;
use OnPage\Services\TermRepository;



class Taxonomy
{
    private const ERROR_PREFIX = 'WooCommerce Product';
    private const BRAND_TAXONOMY = 'product_brand';
    private const CATEGORY_TAXONOMY = 'product_cat';
    private const TAG_TAXONOMY = 'product_tag';



    /** Builds the taxonomy assignment payload from DTO brand/categories/tags fields. */
    public static function buildFromParams(array $params, int $element_index): array|null
    {
        $taxonomies = [];

        $categories = self::normalizeTermList($params, 'categories', $element_index);
        if ($categories !== null) {
            $taxonomies[self::CATEGORY_TAXONOMY] = $categories;
        }

        $tags = self::normalizeTermList($params, 'tags', $element_index);
        if ($tags !== null) {
            $taxonomies[self::TAG_TAXONOMY] = $tags;
        }

        $brand_terms = self::normalizeBrandTerms($params, $element_index);
        if ($brand_terms !== null) {
            $taxonomies[self::BRAND_TAXONOMY] = $brand_terms;
        }

        // Generic per-taxonomy assignment for any taxonomy registered on the product
        // (e.g. custom ACF taxonomies). Dedicated brand/categories/tags fields win on
        // their own taxonomy.
        foreach (self::buildGenericTerms($params, $element_index) as $taxonomy_key => $term_references) {
            if (!array_key_exists($taxonomy_key, $taxonomies)) {
                $taxonomies[$taxonomy_key] = $term_references;
            }
        }

        return $taxonomies !== [] ? $taxonomies : null;
    }

    /** Builds taxonomy => term references from the generic `terms` map (taxonomy_slug => refs). */
    private static function buildGenericTerms(array $params, int $element_index): array
    {
        if (!array_key_exists('terms', $params) || $params['terms'] === null) {
            return [];
        }

        if (!is_array($params['terms']) || array_is_list($params['terms'])) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'terms' must be an object mapping taxonomy slug => term references", 400, 'invalid_param');
        }

        $result = [];
        foreach ($params['terms'] as $taxonomy_key => $value) {
            if (!is_string($taxonomy_key) || trim($taxonomy_key) === '') {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'terms' has an invalid taxonomy key", 400, 'invalid_param');
            }

            $result[$taxonomy_key] = self::normalizeTermList(
                ["terms.$taxonomy_key" => $value],
                "terms.$taxonomy_key",
                $element_index
            ) ?? [];
        }

        return $result;
    }

    /** Replaces product taxonomy terms with the provided term references. */
    public static function apply(
        int $product_id,
        array $taxonomies,
        ?string $language_code = null,
        ?string $fallback_language = null
    ): void
    {
        foreach ($taxonomies as $taxonomy_key => $terms) {
            $ids = [];
            $term_references = self::getTermsForLanguage($terms, $language_code, $fallback_language);

            if (!\taxonomy_exists($taxonomy_key)) {
                if ($term_references === []) {
                    continue;
                }

                throw httpException(self::ERROR_PREFIX . " :: Taxonomy '$taxonomy_key' not found", 404, 'not_found');
            }

            if ($term_references === []) {
                self::clearTerms($product_id, $taxonomy_key);
                continue;
            }

            foreach ($term_references as $term_reference) {
                $term = self::findTermForAssignment($term_reference, $taxonomy_key, $language_code, $fallback_language);
                if (!$term) {
                    throw httpException(self::ERROR_PREFIX . " :: Term with local_key '$term_reference' not found in taxonomy '$taxonomy_key'", 404, 'input_invalid');
                }

                $term_id = (int) $term->term_id;

                if (isWpmlActive() && $language_code) {
                    $translated_term_id = \apply_filters('wpml_object_id', $term_id, $taxonomy_key, true, $language_code);
                    if ($translated_term_id) {
                        $term_id = (int) $translated_term_id;
                    }
                }

                $ids[] = $term_id;
            }

            $result = \wp_set_object_terms($product_id, $ids, $taxonomy_key, false);
            if (\is_wp_error($result)) {
                throw httpException(
                    self::ERROR_PREFIX . " :: Failed to assign terms of taxonomy '$taxonomy_key' to product $product_id :: " . $result->get_error_message(),
                    500,
                    'request_failed'
                );
            }
        }
    }

    /** Clears all assigned terms without triggering WooCommerce's default product category fallback. */
    private static function clearTerms(int $product_id, string $taxonomy_key): void
    {
        $term_ids = \wp_get_object_terms($product_id, $taxonomy_key, ['fields' => 'ids']);
        if (\is_wp_error($term_ids)) {
            throw httpException(
                self::ERROR_PREFIX . " :: Failed to read terms of taxonomy '$taxonomy_key' for product $product_id :: " . $term_ids->get_error_message(),
                500,
                'request_failed'
            );
        }

        $term_ids = is_array($term_ids)
            ? array_values(array_map('intval', $term_ids))
            : [];

        if ($term_ids === []) {
            \wp_cache_delete($product_id, $taxonomy_key . '_relationships');

            return;
        }

        $result = \wp_remove_object_terms($product_id, $term_ids, $taxonomy_key);
        if (\is_wp_error($result)) {
            throw httpException(
                self::ERROR_PREFIX . " :: Failed to clear terms of taxonomy '$taxonomy_key' for product $product_id :: " . $result->get_error_message(),
                500,
                'request_failed'
            );
        }

        if ($result === false) {
            throw httpException(
                self::ERROR_PREFIX . " :: Failed to clear terms of taxonomy '$taxonomy_key' for product $product_id",
                500,
                'request_failed'
            );
        }
    }

    /** Normalizes one term reference payload value: a local_key (positive integer or non-empty string). */
    private static function normalizeTermReferenceValue(mixed $term, string $path, int $element_index): string
    {
        $local_key = Input::localKey($term);
        if ($local_key === null) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter '$path' must be a positive integer or non-empty string local_key", 400, 'invalid_param');
        }

        return $local_key;
    }

    /** Normalizes scalar/list values inside a WPML language => term reference(s) map. */
    private static function normalizeMultilingualTermMap(array $terms, string $key, int $element_index): array
    {
        $normalized = [];

        foreach ($terms as $language_code => $term_value) {
            $path = $key . '.' . $language_code;

            if ($term_value === null) {
                $normalized[$language_code] = [];
                continue;
            }

            if (is_scalar($term_value)) {
                $normalized[$language_code] = self::normalizeTermReferenceValue($term_value, $path, $element_index);
                continue;
            }

            if (is_array($term_value) && array_is_list($term_value)) {
                $normalized[$language_code] = [];
                foreach ($term_value as $term_index => $localized_term) {
                    $normalized[$language_code][] = self::normalizeTermReferenceValue($localized_term, $path . '.' . $term_index, $element_index);
                }

                continue;
            }

            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter '$path' must be a term reference, list of term references or null", 400, 'invalid_param');
        }

        return $normalized;
    }

    /** Returns a term reference payload from DTO category/tag payloads. */
    private static function normalizeTermList(array $params, string $key, int $element_index): array|null
    {
        if (!array_key_exists($key, $params)) {
            return null;
        }

        if ($params[$key] === null) {
            return [];
        }

        if (!is_array($params[$key])) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter '$key' must be a list of term references, WPML language map or null", 400, 'invalid_param');
        }

        $languages = getWpmlLanguages();
        MultiLang::requireWpmlForLanguageMap($params[$key], self::ERROR_PREFIX, $element_index, $key);

        if (self::isMultilingualTaxonomyValue($params[$key], $languages)) {
            return self::normalizeMultilingualTermMap($params[$key], $key, $element_index);
        }

        if (!array_is_list($params[$key])) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter '$key' must be a list of term references, WPML language map or null", 400, 'invalid_param');
        }

        $terms = [];
        foreach ($params[$key] as $term_index => $term) {
            MultiLang::requireWpmlForLanguageMap($term, self::ERROR_PREFIX, $element_index, $key . '.' . $term_index);

            if (self::isMultilingualTaxonomyValue($term, $languages)) {
                $terms[] = self::normalizeMultilingualTermMap($term, $key . '.' . $term_index, $element_index);
                continue;
            }

            $terms[] = self::normalizeTermReferenceValue($term, $key . '.' . $term_index, $element_index);
        }

        return $terms;
    }

    /** Returns a brand term reference list from the DTO brand payload (single integer local_key). */
    private static function normalizeBrandTerms(array $params, int $element_index): array|null
    {
        if (!array_key_exists('brand', $params)) {
            return null;
        }

        if ($params['brand'] === null) {
            return [];
        }

        $local_key = Input::localKey($params['brand']);
        if ($local_key === null) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'brand' must be a positive integer or non-empty string local_key, or null", 400, 'invalid_param');
        }

        return [$local_key];
    }

    /** Finds a term by On Page® local_key for the taxonomy; slug references are not supported. */
    private static function findTermForAssignment(
        string $local_key,
        string $taxonomy_key,
        ?string $language_code,
        ?string $fallback_language
    ): \WP_Term|null {
        return self::findTermByLocalKeyForAssignment($local_key, $taxonomy_key, $language_code, $fallback_language);
    }

    /** Finds a term by On Page® local_key in any taxonomy, resolving to the requested WPML language when possible. */
    private static function findTermByLocalKeyForAssignment(
        string $local_key,
        string $taxonomy_key,
        ?string $language_code,
        ?string $fallback_language
    ): \WP_Term|null {
        $term_ids = TermRepository::findTermIdsByLocalKey($local_key, $taxonomy_key);
        if ($term_ids === []) {
            return null;
        }

        $term_id = self::resolveLocalKeyTermIdForLanguage($term_ids, $taxonomy_key, $language_code, $fallback_language);

        return $term_id ? self::getTermById($term_id, $taxonomy_key) : null;
    }

    /** Chooses the local_key term matching the requested language, falling back to a linked/default term. */
    private static function resolveLocalKeyTermIdForLanguage(
        array $term_ids,
        string $taxonomy_key,
        ?string $language_code,
        ?string $fallback_language
    ): int {
        if (!isWpmlActive()) {
            return (int) $term_ids[0];
        }

        $languages = array_values(array_unique(array_filter([
            $language_code,
            $fallback_language,
            getWpmlDefaultLanguage(),
        ], fn(mixed $lang): bool => is_string($lang) && $lang !== '')));

        foreach ($languages as $lang) {
            $term_id = self::findTermIdWithLanguage($term_ids, $taxonomy_key, (string) $lang)
                ?: self::findTranslatedTermIdFromCandidates($term_ids, $taxonomy_key, (string) $lang);
            if ($term_id) {
                return $term_id;
            }
        }

        return (int) $term_ids[0];
    }

    /** Returns the first candidate term already assigned to the target WPML language. */
    private static function findTermIdWithLanguage(array $term_ids, string $taxonomy_key, string $language_code): int|null
    {
        foreach ($term_ids as $term_id) {
            $details = self::getTermLanguageDetails((int) $term_id, $taxonomy_key);
            if (!empty($details?->language_code) && (string) $details->language_code === $language_code) {
                return (int) $term_id;
            }
        }

        return null;
    }

    /** Resolves a WPML translated term ID from candidate source terms. */
    private static function findTranslatedTermIdFromCandidates(array $term_ids, string $taxonomy_key, string $language_code): int|null
    {
        foreach ($term_ids as $term_id) {
            $translated_id = \apply_filters('wpml_object_id', (int) $term_id, $taxonomy_key, false, $language_code);
            if ($translated_id) {
                return (int) $translated_id;
            }
        }

        return null;
    }

    /** WPML element language details for a term. */
    private static function getTermLanguageDetails(int $term_id, string $taxonomy_key): mixed
    {
        if (!isWpmlActive()) {
            return null;
        }

        $term = self::getTermById($term_id, $taxonomy_key);
        if (!$term || empty($term->term_taxonomy_id)) {
            return null;
        }

        $element_type = \apply_filters('wpml_element_type', 'tax_' . $taxonomy_key);

        return \apply_filters('wpml_element_language_details', null, [
            'element_id' => (int) $term->term_taxonomy_id,
            'element_type' => $element_type,
        ]);
    }

    /** Returns a term by ID without leaking WordPress false/error return values. */
    private static function getTermById(int $term_id, string $taxonomy_key): \WP_Term|null
    {
        $term = \get_term($term_id, $taxonomy_key);

        return $term instanceof \WP_Term ? $term : null;
    }

    /** Whether a taxonomy term payload is a language => reference(s) map. */
    private static function isMultilingualTaxonomyValue(mixed $value, array $languages): bool
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return false;
        }

        foreach (array_keys($value) as $language_code) {
            if (!is_string($language_code) || !in_array($language_code, $languages, true)) {
                return false;
            }
        }

        return true;
    }

    /** Normalizes scalar-or-list term reference payloads. */
    private static function normalizeTermReferences(mixed $terms): array
    {
        if (is_string($terms) || is_numeric($terms)) {
            return [(string) $terms];
        }

        if (!is_array($terms)) {
            return [];
        }

        $normalized = [];
        foreach ($terms as $term) {
            if (is_string($term) || is_numeric($term)) {
                $normalized[] = (string) $term;
            }
        }

        return $normalized;
    }

    /** Picks the most appropriate term payload from a language => term(s) map. */
    private static function resolveMultilingualTerms(mixed $terms, array $languages, ?string $language_code, ?string $fallback_language): mixed
    {
        if (!self::isMultilingualTaxonomyValue($terms, $languages)) {
            return $terms;
        }

        if ($language_code && array_key_exists($language_code, $terms)) {
            return $terms[$language_code];
        }

        if ($fallback_language && array_key_exists($fallback_language, $terms)) {
            return $terms[$fallback_language];
        }

        $first_language = array_key_first($terms);

        return $first_language !== null ? $terms[$first_language] : [];
    }

    /** Flattens a term payload list, resolving multilingual entries recursively. */
    private static function flattenTerms(array $terms, array $languages, ?string $language_code, ?string $fallback_language): array
    {
        $normalized = [];

        foreach ($terms as $term) {
            $resolved_term = self::resolveMultilingualTerms($term, $languages, $language_code, $fallback_language);

            if (is_array($resolved_term)) {
                $normalized = array_merge(
                    $normalized,
                    self::flattenTerms($resolved_term, $languages, $language_code, $fallback_language)
                );
                continue;
            }

            $normalized = array_merge($normalized, self::normalizeTermReferences($resolved_term));
        }

        return $normalized;
    }

    /** Normalizes a taxonomy payload to the reference list for the requested language. */
    private static function getTermsForLanguage(mixed $terms, ?string $language_code, ?string $fallback_language = null): array
    {
        $languages = getWpmlLanguages();
        $resolved_terms = self::resolveMultilingualTerms($terms, $languages, $language_code, $fallback_language);

        if (!is_array($resolved_terms)) {
            return self::normalizeTermReferences($resolved_terms);
        }

        return self::flattenTerms($resolved_terms, $languages, $language_code, $fallback_language);
    }

}