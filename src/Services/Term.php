<?php



namespace OnPage\Services;



class Term
{
    private const LOCAL_KEY_META = PostRepository::LOCAL_KEY_META;



    /** ACF object identifier for taxonomy term fields. */
    private static function getTermAcfObjectId(int $term_id, string $taxonomy): string
    {
        return $taxonomy . '_' . $term_id;
    }

    /**
     * Writes ACF field values for a term (taxonomy-scoped field keys).
     *
     * `Acf::updateFieldValue` centralizes URL → attachment resolution for `image`/`file`
     * (top-level and inside repeaters), repeater normalization and `tab` skip.
     */
    private static function updateAcfFields(int $term_id, string $taxonomy, array $fields, int $element_index, ?string $language_code = null): void
    {
        foreach ($fields as $field_key => $value) {
            Acf::updateFieldValue(
                self::getTermAcfObjectId($term_id, $taxonomy),
                $field_key,
                $value,
                'term',
                $taxonomy
            );
        }
    }

    /** Assigns WPML language metadata to a term (via term_taxonomy_id). */
    private static function setTermLanguage(int $term_id, string $taxonomy, string $language_code, int|false $trid = false, ?string $source_language_code = null): void
    {
        if (!onpage_is_wpml_active()) {
            return;
        }

        $term_taxonomy_id = self::getTermTaxonomyId($term_id, $taxonomy);
        if (!$term_taxonomy_id) {
            return;
        }

        $element_type = \apply_filters('wpml_element_type', 'tax_' . $taxonomy);

        \do_action('wpml_set_element_language_details', [
            'element_id' => $term_taxonomy_id,
            'element_type' => $element_type,
            'trid' => $trid,
            'language_code' => $language_code,
            'source_language_code' => $source_language_code,
        ]);
    }

    /** WPML element language details for a term. */
    private static function getTermLanguageDetails(int $term_id, string $taxonomy): mixed
    {
        if (!onpage_is_wpml_active()) {
            return null;
        }

        $term_taxonomy_id = self::getTermTaxonomyId($term_id, $taxonomy);
        if (!$term_taxonomy_id) {
            return null;
        }

        $element_type = \apply_filters('wpml_element_type', 'tax_' . $taxonomy);

        return \apply_filters('wpml_element_language_details', null, [
            'element_id' => $term_taxonomy_id,
            'element_type' => $element_type,
        ]);
    }

    /** Map of language code to translation row for a WPML trid. */
    private static function getTermTranslations(int $trid, string $taxonomy): array
    {
        if (!onpage_is_wpml_active()) {
            return [];
        }

        $element_type = \apply_filters('wpml_element_type', 'tax_' . $taxonomy);
        $translations = \apply_filters('wpml_get_element_translations', [], $trid, $element_type);

        return is_array($translations) ? $translations : [];
    }

    /** term_taxonomy_id for a term in a taxonomy. */
    private static function getTermTaxonomyId(int $term_id, string $taxonomy): int
    {
        return TermRepository::getTermTaxonomyId($term_id, $taxonomy);
    }

    /** Resolves term_id from a term_taxonomy_id. */
    private static function getTermIdByTaxonomyId(int $term_taxonomy_id, string $taxonomy): int
    {
        return TermRepository::getTermIdByTaxonomyId($term_taxonomy_id, $taxonomy);
    }

    /** Builds a WPML `language_code => term_id` map for a term (empty when WPML is inactive). */
    public static function buildTranslationsMap(int $term_id, string $taxonomy): array
    {
        if (!onpage_is_wpml_active()) {
            return [];
        }

        $details = self::getTermLanguageDetails($term_id, $taxonomy);
        $trid = isset($details?->trid) ? (int) $details->trid : 0;
        if ($trid <= 0) {
            return [];
        }

        $map = [];
        foreach (self::getTermTranslations($trid, $taxonomy) as $language_code => $translation) {
            $term_taxonomy_id = isset($translation->element_id) ? (int) $translation->element_id : 0;
            if ($term_taxonomy_id <= 0) {
                continue;
            }

            $translated_term_id = self::getTermIdByTaxonomyId($term_taxonomy_id, $taxonomy);
            if ($translated_term_id > 0) {
                $map[(string) $language_code] = $translated_term_id;
            }
        }

        return $map;
    }

    /** Persists the On Page® local_key term meta when non-empty. */
    private static function setTermLocalKey(int $term_id, ?string $local_key): void
    {
        if (!$local_key) {
            return;
        }

        \update_term_meta($term_id, self::LOCAL_KEY_META, $local_key);
    }

    /** Reads the local_key meta for a term in its canonical form, or null. */
    private static function getTermLocalKey(int $term_id): ?string
    {
        $value = \get_term_meta($term_id, self::LOCAL_KEY_META, true);

        return Input::localKey($value);
    }

    /**
     * Whether a slug-matched term may be reused for the incoming payload.
     *
     * Guards against a slug collision overwriting a term that belongs to a *different*
     * `local_key`. Reuse is allowed when:
     * - the payload carries no `local_key` (nothing to disambiguate by → slug match), or
     * - the existing term has no `local_key` (unowned, e.g. auto-created by a product
     *   import via its `categories`/`tags`/`brand` fields) → the import takes ownership, or
     * - the existing term carries the same `local_key`.
     *
     * Only a term already owned by another `local_key` is refused, so a distinct term
     * is created instead of hijacking it.
     */
    private static function termMatchesLocalKey(int $term_id, ?string $local_key): bool
    {
        if ($local_key === null) {
            return true;
        }

        $existing_local_key = self::getTermLocalKey($term_id);

        return $existing_local_key === null
            || $existing_local_key === $local_key;
    }

    /**
     * Whether a slug-matched term is the element already being written (`$term_id`).
     *
     * Stricter than termMatchesLocalKey(): the element already has its own term, so the
     * slug holder may only stand in for it when it is the same term, a member of the same
     * WPML translation group, or a term carrying the same local_key. An unowned term or one
     * of another element is a different term, and writing the payload on it would hijack it.
     */
    private static function slugTermBelongsToElement(int $slug_term_id, int $term_id, string $taxonomy, ?string $local_key): bool
    {
        if (self::areSameTranslationGroup($slug_term_id, $term_id, $taxonomy)) {
            return true;
        }

        return $local_key !== null && self::getTermLocalKey($slug_term_id) === $local_key;
    }

    /** Finds term IDs by local_key within a taxonomy, bypassing WPML term filters. */
    private static function findTermIdsByLocalKey(string $local_key, string $taxonomy_slug): array
    {
        return TermRepository::findTermIdsByLocalKey($local_key, $taxonomy_slug);
    }

    /** Finds a term ID by local_key within a taxonomy. */
    private static function findByLocalKey(string $local_key, string $taxonomy_slug): int|null
    {
        $term_ids = self::findTermIdsByLocalKey($local_key, $taxonomy_slug);

        return $term_ids[0] ?? null;
    }

    /** Finds term IDs by slug within a taxonomy, bypassing WPML term filters. */
    private static function findTermIdsBySlug(string $slug, string $taxonomy_slug): array
    {
        return TermRepository::findTermIdsBySlug($slug, $taxonomy_slug);
    }

    /** Returns the first candidate term already assigned to the target WPML language. */
    private static function findTermIdWithLanguage(array $term_ids, string $taxonomy, string $language_code): int|null
    {
        foreach ($term_ids as $term_id) {
            $details = self::getTermLanguageDetails((int) $term_id, $taxonomy);
            if (!empty($details?->language_code) && (string) $details->language_code === $language_code) {
                return (int) $term_id;
            }
        }

        return null;
    }

    /** Returns the first candidate term that has no WPML language metadata yet. */
    private static function findTermIdWithoutLanguage(array $term_ids, string $taxonomy): int|null
    {
        foreach ($term_ids as $term_id) {
            $details = self::getTermLanguageDetails((int) $term_id, $taxonomy);
            if (empty($details?->language_code)) {
                return (int) $term_id;
            }
        }

        return null;
    }

    /** Returns the first candidate term with the expected local_key meta. */
    private static function findTermIdWithLocalKey(array $term_ids, string $local_key): int|null
    {
        foreach ($term_ids as $term_id) {
            if (self::getTermLocalKey((int) $term_id) === $local_key) {
                return (int) $term_id;
            }
        }

        return null;
    }

    /** Resolves a WPML translated term ID from candidate source terms. */
    private static function findTranslatedTermIdFromCandidates(array $term_ids, string $taxonomy, string $language_code): int|null
    {
        foreach ($term_ids as $term_id) {
            $translated_id = \apply_filters('wpml_object_id', (int) $term_id, $taxonomy, false, $language_code);
            if ($translated_id) {
                return (int) $translated_id;
            }
        }

        return null;
    }

    /** Finds the local_key term in a target WPML language, optionally falling back to the first local_key match. */
    private static function findByLocalKeyForLanguage(
        string $local_key,
        string $taxonomy_slug,
        ?string $language_code,
        bool $allow_fallback = true
    ): int|null
    {
        $term_ids = self::findTermIdsByLocalKey($local_key, $taxonomy_slug);
        if ($term_ids === []) {
            return null;
        }

        if (!$language_code || !onpage_is_wpml_active()) {
            return $term_ids[0];
        }

        $language_term_id = self::findTermIdWithLanguage($term_ids, $taxonomy_slug, $language_code);
        if ($language_term_id) {
            return $language_term_id;
        }

        $translated_term_id = self::findTranslatedTermIdFromCandidates($term_ids, $taxonomy_slug, $language_code);
        if ($translated_term_id) {
            return $translated_term_id;
        }

        return $allow_fallback ? $term_ids[0] : null;
    }

    /** Finds a term ID by local_key, optionally resolving the WPML language-specific term. */
    public static function findIdByLocalKey(string $local_key, string $taxonomy_slug, ?string $language_code = null): int|null
    {
        return self::findByLocalKeyForLanguage($local_key, $taxonomy_slug, $language_code);
    }

    /** Finds a term ID by slug within a taxonomy. */
    private static function findBySlug(string $slug, string $taxonomy_slug): int|null
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $term = self::getTermBySlug($slug, $taxonomy_slug);
        if ($term) {
            return (int) $term->term_id;
        }

        if (!onpage_is_wpml_active()) {
            return null;
        }

        $languages = array_values(array_unique(array_filter([
            onpage_get_wpml_current_language(),
            onpage_get_wpml_default_language(),
            ...onpage_get_wpml_languages(),
        ], fn(mixed $lang): bool => is_string($lang) && $lang !== '')));

        foreach ($languages as $language_code) {
            $term = Wpml::runWithLanguage(
                (string) $language_code,
                fn(): \WP_Term|null => self::getTermBySlug($slug, $taxonomy_slug)
            );

            if ($term) {
                return (int) $term->term_id;
            }
        }

        $term_ids = self::findTermIdsBySlug($slug, $taxonomy_slug);

        return $term_ids[0] ?? null;
    }

    /** Returns a term by slug without leaking WordPress false/error return values. */
    private static function getTermBySlug(string $slug, string $taxonomy_slug): \WP_Term|null
    {
        $term = \get_term_by('slug', \sanitize_title($slug), $taxonomy_slug);

        return $term instanceof \WP_Term ? $term : null;
    }

    /** Finds the term visible to WordPress for a slug in the target WPML language. */
    private static function findVisibleTermIdBySlug(string $slug, string $taxonomy, ?string $language_code): int|null
    {
        $term = Wpml::runWithLanguage(
            $language_code,
            fn(): \WP_Term|null => self::getTermBySlug($slug, $taxonomy)
        );

        return $term instanceof \WP_Term ? (int) $term->term_id : null;
    }

    /** Maps a term to its translation in the given language when WPML is active. */
    private static function getTermIdForLanguage(int $term_id, string $taxonomy, ?string $language_code): int
    {
        if (!$language_code || !onpage_is_wpml_active()) {
            return $term_id;
        }

        $details = self::getTermLanguageDetails($term_id, $taxonomy);
        if (!empty($details?->language_code) && (string) $details->language_code === $language_code) {
            return $term_id;
        }

        $translated_id = \apply_filters('wpml_object_id', $term_id, $taxonomy, false, $language_code);
        if ($translated_id) {
            $translated_details = self::getTermLanguageDetails((int) $translated_id, $taxonomy);
            if (empty($translated_details?->language_code) || (string) $translated_details->language_code === $language_code) {
                return (int) $translated_id;
            }
        }

        return empty($details?->language_code) ? $term_id : 0;
    }

    /** True when a term can be safely updated as the target language. */
    private static function canUpdateTermForLanguage(int $term_id, string $taxonomy, ?string $language_code): bool
    {
        if (!$language_code || !onpage_is_wpml_active()) {
            return true;
        }

        $details = self::getTermLanguageDetails($term_id, $taxonomy);

        return empty($details?->language_code) || (string) $details->language_code === $language_code;
    }

    /** True when an existing term may be reused for a language upsert. */
    private static function canUseTermForLanguage(int $term_id, string $taxonomy, ?string $language_code): bool
    {
        return self::canUpdateTermForLanguage($term_id, $taxonomy, $language_code);
    }

    /** Whether two terms share the same WPML translation group (trid). */
    private static function areSameTranslationGroup(int $term_id_a, int $term_id_b, string $taxonomy): bool
    {
        if ($term_id_a === $term_id_b) {
            return true;
        }

        if (!onpage_is_wpml_active()) {
            return false;
        }

        $details_a = self::getTermLanguageDetails($term_id_a, $taxonomy);
        $details_b = self::getTermLanguageDetails($term_id_b, $taxonomy);

        $trid_a = isset($details_a?->trid) ? (int) $details_a->trid : 0;
        $trid_b = isset($details_b?->trid) ? (int) $details_b->trid : 0;

        return $trid_a > 0 && $trid_b > 0 && $trid_a === $trid_b;
    }

    /** Loads ACF taxonomy data by numeric ID. */
    private static function findTaxonomy(int $id): array|null
    {
        $acf_taxonomy = \acf_get_taxonomy($id);
        if ($acf_taxonomy) return $acf_taxonomy;

        return null;
    }

    /**
     * Resolves a WordPress taxonomy slug from either a taxonomy slug or a numeric
     * ACF taxonomy post ID.
     *
     * An existing WordPress taxonomy slug is preferred: it is stable across
     * environments (unlike the ACF post ID, which depends on creation order) and
     * also covers non-ACF taxonomies such as WooCommerce `product_cat`/`pa_*`. A
     * numeric ACF taxonomy ID is still accepted for backward compatibility.
     */
    private static function getTaxonomySlug(int|string $identifier): string|null
    {
        $raw = trim((string) $identifier);
        if ($raw === '') return null;

        if (\taxonomy_exists($raw)) {
            return $raw;
        }

        if (ctype_digit($raw)) {
            $taxonomy = self::findTaxonomy((int) $raw);
            if ($taxonomy) return $taxonomy['taxonomy'];
        }

        return null;
    }

    /** Resolves and validates the taxonomy slug from a slug or numeric taxonomy ID. */
    public static function requireTaxonomySlug(int|string $identifier): string
    {
        $taxonomy = self::getTaxonomySlug($identifier);
        if (!$taxonomy) {
            throw onpage_http_exception("Term :: Taxonomy '$identifier' not found", 404, 'not_found');
        }

        return $taxonomy;
    }

    /** API response shape for one listed term. */
    public static function buildTermResponse(\WP_Term $term, ?array $translations = null): array
    {
        $term_id = (int) $term->term_id;
        $local_key = Input::localKeyOut(\get_term_meta($term_id, self::LOCAL_KEY_META, true));

        return [
            'id' => $term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'local_key' => $local_key,
            'description' => $term->description,
            'parent' => (int) $term->parent,
            'count' => (int) $term->count,
            'taxonomy' => $term->taxonomy,
            'translations' => $translations ?? self::buildTranslationsMap($term_id, $term->taxonomy),
            'acf_fields' => function_exists('get_fields')
                ? \get_fields(self::getTermAcfObjectId($term_id, $term->taxonomy))
                : null,
        ];
    }

    /** Returns one term by taxonomy and id or throws when missing. */
    public static function requireTermById(int $term_id, string $taxonomy): \WP_Term
    {
        $term = \get_term($term_id, $taxonomy);
        if (!$term || \is_wp_error($term)) {
            throw onpage_http_exception("Term $term_id not found for taxonomy '$taxonomy'", 404, 'not_found');
        }

        return $term;
    }

    /**
     * Returns all terms for a taxonomy, or across all taxonomies when null, or throws on errors.
     *
     * When $parent_ids is a list of parent term IDs, only the direct children of those parents
     * are returned (de-duplicated, preserving order); an empty list yields no terms. `null`
     * applies no parent filter.
     */
    public static function listByTaxonomy(?string $taxonomy = null, ?array $parent_ids = null): array
    {
        $args = ['hide_empty' => false];
        if ($taxonomy !== null) {
            $args['taxonomy'] = $taxonomy;
        }

        if ($parent_ids === null) {
            $terms = \get_terms($args);
            if (\is_wp_error($terms)) {
                throw onpage_http_exception($terms->get_error_message(), 500, 'request_failed');
            }

            return $terms;
        }

        $terms = [];
        $seen = [];
        foreach ($parent_ids as $parent_id) {
            $children = \get_terms($args + ['parent' => (int) $parent_id]);
            if (\is_wp_error($children)) {
                throw onpage_http_exception($children->get_error_message(), 500, 'request_failed');
            }

            foreach ($children as $child) {
                if (!isset($seen[$child->term_id])) {
                    $seen[$child->term_id] = true;
                    $terms[] = $child;
                }
            }
        }

        return $terms;
    }

    /**
     * Resolves the `parent_id` (WP term ID) / `parent_lk` (parent local_key) list filter to
     * the parent term IDs to match children against, or `null` when neither is present.
     *
     * `parent_lk` expands to every WPML translation of the parent (so children in any language
     * are matched) and requires a taxonomy to scope the local_key lookup. The two parameters are
     * mutually exclusive. A `parent_lk` that resolves to no term yields an empty list (no children).
     */
    public static function resolveParentFilterIds(
        ?int $parent_id,
        ?string $parent_lk,
        ?string $taxonomy,
        string $error_prefix
    ): ?array {
        if ($parent_id !== null && $parent_lk !== null) {
            throw onpage_http_exception("$error_prefix :: Use only one of 'parent_id' and 'parent_lk'", 400, 'invalid_param');
        }

        if ($parent_id !== null) {
            return [$parent_id];
        }

        if ($parent_lk !== null) {
            if ($taxonomy === null) {
                throw onpage_http_exception("$error_prefix :: 'parent_lk' requires 'taxonomy'", 400, 'invalid_param');
            }

            return self::findTermIdsByLocalKey($parent_lk, $taxonomy);
        }

        return null;
    }

    /** Finds term IDs by exact name within a taxonomy, or across all taxonomies when null. */
    private static function findTermIdsByName(string $name, ?string $taxonomy = null): array
    {
        $args = [
            'name' => $name,
            'hide_empty' => false,
            'fields' => 'ids',
        ];
        if ($taxonomy !== null) {
            $args['taxonomy'] = $taxonomy;
        }

        $results = \get_terms($args);

        return is_array($results) ? array_values(array_map('intval', $results)) : [];
    }

    /** Resolves a term's taxonomy slug from its ID, or null when the term does not exist. */
    private static function getTermTaxonomy(int $term_id): ?string
    {
        $term = \get_term($term_id);

        return $term instanceof \WP_Term ? $term->taxonomy : null;
    }

    /** Prefers the default-language member of a translation group as the representative term. */
    private static function pickGroupRepresentative(int $term_id, array $translations): int
    {
        $default_language = onpage_get_wpml_default_language();
        if ($default_language !== null && !empty($translations[$default_language])) {
            return (int) $translations[$default_language];
        }

        return $term_id;
    }

    /**
     * Searches terms by exact name, returning one response per WPML translation group
     * (de-duplicated): the default language is preferred as the representative and each
     * object carries the full multilang id map under `translations`.
     *
     * Each query is a [language_code|null, name] pair: a null language searches the current
     * language; a language code searches that name within its WPML language context. Results
     * across queries are unioned and de-duplicated by translation group.
     *
     * When $taxonomy is null the search spans all taxonomies and each term's taxonomy is
     * resolved from the term itself.
     */
    public static function searchByName(array $queries, ?string $taxonomy = null): array
    {
        $responses = [];
        $seen_ids = [];

        foreach ($queries as [$language_code, $name]) {
            $term_ids = $language_code === null
                ? self::findTermIdsByName($name, $taxonomy)
                : Wpml::runWithLanguage($language_code, fn(): array => self::findTermIdsByName($name, $taxonomy));

            foreach ($term_ids as $term_id) {
                $term_id = (int) $term_id;
                if (isset($seen_ids[$term_id])) {
                    continue;
                }

                $term_taxonomy = $taxonomy ?? self::getTermTaxonomy($term_id);
                if ($term_taxonomy === null) {
                    continue;
                }

                $translations = self::buildTranslationsMap($term_id, $term_taxonomy);
                $member_ids = $translations !== [] ? array_values($translations) : [$term_id];
                foreach ($member_ids as $member_id) {
                    $seen_ids[(int) $member_id] = true;
                }

                $representative_id = self::pickGroupRepresentative($term_id, $translations);
                $responses[] = self::buildTermResponse(
                    self::requireTermById($representative_id, $term_taxonomy),
                    $translations
                );
            }
        }

        return $responses;
    }

    /** Per-request parsed term payload split into shared/translatable buckets. */
    private static function parseTermPayload(array $params): array
    {
        $name_map = MultiLang::splitValueByLanguage($params['name'] ?? null);
        $slug_map = MultiLang::splitValueByLanguage($params['slug'] ?? '');
        $description_map = MultiLang::splitValueByLanguage($params['description'] ?? '');
        $acf_field_map = MultiLang::splitAcfFieldsByLanguage(is_array($params['acf_fields'] ?? null) ? $params['acf_fields'] : []);

        $translated_languages = array_values(array_unique(array_merge(
            array_keys($name_map['translated']),
            array_keys($slug_map['translated']),
            array_keys($description_map['translated']),
            array_keys($acf_field_map['translated'])
        )));

        // Only languages that actually carry a name can be the base language; a
        // present-but-null entry (e.g. {"it":"...","en":null}) is not a usable name.
        $named_languages = array_keys(array_filter(
            $name_map['translated'],
            static fn($value): bool => is_scalar($value) && (string) $value !== ''
        ));
        $fallback_language = $named_languages[0] ?? ($translated_languages[0] ?? null);
        $default_language = onpage_get_wpml_default_language();
        $base_language = $default_language ?: $fallback_language;
        $has_shared_name = array_key_exists('shared', $name_map) && $name_map['shared'] !== null;

        if (!$has_shared_name && !empty($translated_languages)) {
            $base_language_candidates = $named_languages ?: $translated_languages;
            if (!$default_language || !in_array($default_language, $base_language_candidates, true)) {
                $base_language = $fallback_language;
            }
        }

        return [
            'name_map' => $name_map,
            'slug_map' => $slug_map,
            'description_map' => $description_map,
            'acf_field_map' => $acf_field_map,
            'translated_languages' => $translated_languages,
            'fallback_language' => $fallback_language,
            'base_language' => $base_language,
            'local_key' => Input::localKey($params['local_key'] ?? null),
            'requested_term_id' => !empty($params['id']) ? (int) $params['id'] : 0,
            'parent' => isset($params['parent']) ? (int) $params['parent'] : 0,
            'parent_local_key' => Input::localKey($params['__parent_local_key'] ?? null),
        ];
    }

    /** Rejects a present `local_key` that is neither a positive integer nor a non-empty string. */
    private static function requireValidLocalKey(array $params, int $element_index): void
    {
        if (array_key_exists('local_key', $params) && $params['local_key'] !== null
            && Input::localKey($params['local_key']) === null) {
            throw onpage_http_exception("Term :: Element $element_index :: Parameter 'local_key' must be a positive integer or a non-empty string", 400, 'invalid_param');
        }
    }

    /** Checks every term payload field that supports language maps. */
    private static function requireWpmlForPayloadLanguageMaps(array $params, int $element_index = 0): void
    {
        foreach (['name', 'slug', 'description'] as $key) {
            if (array_key_exists($key, $params)) {
                MultiLang::requireWpmlForLanguageMap($params[$key], 'Term', $element_index, $key);
            }
        }

        MultiLang::requireWpmlForFieldMap($params['acf_fields'] ?? null, 'Term', $element_index, 'acf_fields');
    }

    /** Validates multilingual requirements and base term name. */
    private static function validateParsedPayload(array $payload, string $taxonomy, int $element_index): string
    {
        MultiLang::requireWpmlForDetectedLanguages($payload['translated_languages'], 'Term', $element_index);

        if (!$payload['base_language'] && !empty($payload['translated_languages'])) {
            throw onpage_http_exception("Term :: Element $element_index :: Unable to resolve default language", 500, 'wpml_error');
        }

        $base_name = self::resolveBaseName($payload['name_map'], $payload['base_language']);
        if ($base_name === null) {
            throw onpage_http_exception("Term :: Element $element_index :: Name is required", 400, 'invalid_param');
        }

        if (!empty($payload['requested_term_id'])) {
            $existing_term = \get_term($payload['requested_term_id'], $taxonomy);
            if (!$existing_term || \is_wp_error($existing_term)) {
                throw onpage_http_exception("Term :: Element $element_index :: Term '{$payload['requested_term_id']}' not found for taxonomy '$taxonomy'", 404, 'not_found');
            }
        }

        $term_id_from_local_key = $payload['local_key'] ? self::findByLocalKey($payload['local_key'], $taxonomy) : null;
        if ($payload['local_key'] && !empty($payload['requested_term_id']) && $term_id_from_local_key && !self::areSameTranslationGroup($payload['requested_term_id'], $term_id_from_local_key, $taxonomy)) {
            throw onpage_http_exception("Term :: Element $element_index :: local_key '{$payload['local_key']}' already exists for taxonomy '$taxonomy'", 409, 'duplicate_local_key');
        }

        if ($payload['parent_local_key'] && !self::findByLocalKeyForLanguage($payload['parent_local_key'], $taxonomy, $payload['base_language'])) {
            throw onpage_http_exception("Term :: Element $element_index :: Parent local_key '{$payload['parent_local_key']}' not found for taxonomy '$taxonomy'", 404, 'not_found');
        }

        return (string) $base_name;
    }

    /**
     * Resolves the term's base name as a non-empty scalar, or null when none exists.
     *
     * Prefers the base language, then falls back to any language that actually carries a
     * name. Also unwraps a language-map that ended up in the `shared` bucket because WPML
     * did not recognise every language code in the payload (e.g. only `it` is active but
     * the payload sends {"it":"…","en":null,"es":null}). A present-but-null per-language
     * entry is treated as "no name", not as a value.
     */
    private static function resolveBaseName(array $name_map, ?string $base_language): ?string
    {
        $direct = self::getDirectValueForLanguage($name_map, $base_language);
        if (is_scalar($direct) && (string) $direct !== '') {
            return (string) $direct;
        }

        $candidates = $name_map['translated'] ?? [];
        $shared = $name_map['shared'] ?? null;
        if (is_array($shared)) {
            $candidates = array_merge($candidates, $shared);
        } elseif (is_scalar($shared) && (string) $shared !== '') {
            return (string) $shared;
        }

        if ($base_language !== null && isset($candidates[$base_language])
            && is_scalar($candidates[$base_language]) && (string) $candidates[$base_language] !== '') {
            return (string) $candidates[$base_language];
        }

        foreach ($candidates as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /** Resolves only the shared or exact-language value, without borrowing another language. */
    private static function getDirectValueForLanguage(array $value_map, ?string $language_code): mixed
    {
        if ($language_code && array_key_exists($language_code, $value_map['translated'] ?? [])) {
            return $value_map['translated'][$language_code];
        }

        return $value_map['shared'] ?? null;
    }

    /** Base term data shared by insert/update operations. */
    private static function buildTermData(array $payload, string $taxonomy, ?string $language_code = null): array
    {
        $slug = self::getSlugForLanguage($payload, $language_code);
        $description = MultiLang::getValueForLanguage($payload['description_map'], $language_code, $payload['fallback_language']);

        return [
            'description' => is_scalar($description) ? (string) $description : '',
            'slug' => is_scalar($slug) ? (string) $slug : '',
            'parent' => self::getParentForLanguage($payload, $taxonomy, $language_code),
        ];
    }

    /** Resolves an internal parent local_key to the language-specific term ID when possible. */
    private static function getParentForLanguage(array $payload, string $taxonomy, ?string $language_code): int
    {
        $parent_local_key = $payload['parent_local_key'] ?? null;
        if (!$parent_local_key) {
            return (int) $payload['parent'];
        }

        return self::findByLocalKeyForLanguage($parent_local_key, $taxonomy, $language_code)
            ?: (int) $payload['parent'];
    }

    /** Resolves slugs without reusing the exact base/shared slug for translated terms. */
    private static function getSlugForLanguage(array $payload, ?string $language_code): mixed
    {
        $is_translation = $language_code
            && !empty($payload['translated_languages'])
            && $language_code !== $payload['base_language'];

        if ($language_code && array_key_exists($language_code, $payload['slug_map']['translated'] ?? [])) {
            $explicit_slug = $payload['slug_map']['translated'][$language_code];

            if (!$is_translation || !self::slugCollidesWithBaseLanguage($payload, $explicit_slug)) {
                return $explicit_slug;
            }
            // Explicit slug matches the base-language term's slug (e.g. the same slug repeated
            // for every language); fall through to the same disambiguation used when no explicit
            // translated slug was given at all, instead of handing WordPress a slug that already
            // belongs to the base-language term.
        } elseif (!$is_translation) {
            return $payload['slug_map']['shared'] ?? null;
        }

        if ($is_translation) {
            return self::buildDuplicateNameTranslationSlug($payload, (string) $language_code);
        }

        return $payload['slug_map']['shared'] ?? null;
    }

    /** Whether an explicit slug would collide with the slug already used by the base-language term. */
    private static function slugCollidesWithBaseLanguage(array $payload, mixed $explicit_slug): bool
    {
        if (!is_scalar($explicit_slug)) {
            return false;
        }

        $base_slug = self::resolveBaseLanguageSlug($payload);

        return $base_slug !== null && \sanitize_title((string) $explicit_slug) === $base_slug;
    }

    /**
     * Builds a distinct technical slug when a translated term would otherwise land on the very
     * same slug as its base-language term, which happens both with a shared name and with a
     * language map repeating the same name (e.g. {"it":"Legno","en":"Legno"}).
     *
     * WordPress refuses same-named siblings ("A term with the name provided already exists with
     * this parent") before WPML can scope the insert to the translation, and only steps aside
     * when a unique slug is explicitly provided. A translated name that already yields its own
     * slug is left alone so WordPress can derive it as usual.
     */
    private static function buildDuplicateNameTranslationSlug(array $payload, string $language_code): string|null
    {
        $base_slug = self::resolveBaseLanguageSlug($payload);
        if ($base_slug === null) {
            return null;
        }

        $translated_name = self::getDirectValueForLanguage($payload['name_map'], $language_code);
        $translated_slug = is_scalar($translated_name) ? \sanitize_title((string) $translated_name) : '';
        if ($translated_slug !== '' && $translated_slug !== $base_slug) {
            return null;
        }

        $language_slug = \sanitize_title($language_code);
        if ($language_slug === '') {
            return null;
        }

        return \sanitize_title($base_slug . '-' . $language_slug);
    }

    /** The slug the base-language term is created with: its explicit slug, or its name. */
    private static function resolveBaseLanguageSlug(array $payload): string|null
    {
        $base_language = $payload['base_language'] ?? null;
        $explicit_slug = ($base_language && array_key_exists($base_language, $payload['slug_map']['translated'] ?? []))
            ? $payload['slug_map']['translated'][$base_language]
            : ($payload['slug_map']['shared'] ?? null);

        $slug_source = is_scalar($explicit_slug) && trim((string) $explicit_slug) !== ''
            ? (string) $explicit_slug
            : (string) (self::resolveBaseName($payload['name_map'], $base_language) ?? '');

        $base_slug = \sanitize_title($slug_source);

        return $base_slug !== '' ? $base_slug : null;
    }

    /** Extracts a non-empty slug from normalized term data. */
    private static function getDataSlug(array $data): string|null
    {
        if (!is_scalar($data['slug'] ?? null)) {
            return null;
        }

        $slug = trim((string) $data['slug']);

        return $slug !== '' ? $slug : null;
    }

    /** WordPress term update args, including the required term name. */
    private static function buildWordPressTermArgs(array $data, string $name): array
    {
        return array_merge($data, [
            'name' => $name,
        ]);
    }

    /** Updates a term while scoped to the target WPML language. */
    private static function updateWordPressTerm(
        int $term_id,
        string $taxonomy,
        array $data,
        string $name,
        ?string $language_code = null
    ): array|\WP_Error {
        return Wpml::runWithLanguage(
            $language_code,
            fn(): array|\WP_Error => \wp_update_term(
                $term_id,
                $taxonomy,
                self::buildWordPressTermArgs($data, $name)
            )
        );
    }

    /** Inserts a term while scoped to the target WPML language. */
    private static function insertWordPressTerm(
        string $taxonomy,
        array $data,
        string $name,
        ?string $language_code = null
    ): array|\WP_Error {
        return Wpml::runWithLanguage(
            $language_code,
            fn(): array|\WP_Error => \wp_insert_term($name, $taxonomy, $data)
        );
    }

    /** Extracts the existing term ID returned by WordPress duplicate-term errors. */
    private static function getExistingTermIdFromError(\WP_Error $error): int
    {
        if ($error->get_error_code() !== 'term_exists') {
            return 0;
        }

        $error_data = $error->get_error_data();
        if (is_array($error_data) && isset($error_data['term_id'])) {
            return (int) $error_data['term_id'];
        }

        return is_numeric($error_data) ? (int) $error_data : 0;
    }

    /** Updates the existing term returned by a duplicate insert error, or returns the original error. */
    private static function updateExistingTermFromInsertError(
        \WP_Error $error,
        string $taxonomy,
        array $data,
        string $name,
        ?string $language_code = null,
        ?string $local_key = null
    ): array|\WP_Error {
        $existing_term_id = self::findTermIdByDataSlug($data, $taxonomy, $language_code, $local_key)
            ?: self::getExistingTermIdFromError($error);

        // WordPress refused the insert but the blocking term cannot be identified: the payload
        // still has to land, so retry with an explicitly unique slug.
        if ($existing_term_id <= 0) {
            return self::insertTermWithUniqueSlug($error, $taxonomy, $data, $name, $language_code);
        }

        // The blocking term belongs to another language: it must not be overwritten, and the
        // translation still has to be created, so retry with a language-specific unique slug.
        if (!self::canUseTermForLanguage($existing_term_id, $taxonomy, $language_code)) {
            return self::insertTermWithUniqueSlug($error, $taxonomy, $data, $name, $language_code);
        }

        // A collision with a term owned by a different local_key must not overwrite that term:
        // drop the explicit slug and let WordPress generate a unique one so a distinct term is
        // created instead. A same-named sibling is refused by WordPress before any slug is
        // derived, so that retry is followed by one carrying an explicitly unique slug.
        if (!self::termMatchesLocalKey($existing_term_id, $local_key)) {
            $insert_data = $data;
            unset($insert_data['slug']);

            $inserted = self::insertWordPressTerm($taxonomy, $insert_data, $name, $language_code);

            return \is_wp_error($inserted)
                ? self::insertTermWithUniqueSlug($inserted, $taxonomy, $data, $name, $language_code)
                : $inserted;
        }

        $result = self::updateWordPressTerm($existing_term_id, $taxonomy, $data, $name, $language_code);

        return \is_wp_error($result)
            ? self::recoverDuplicateSlugUpdate($result, $existing_term_id, $taxonomy, $data, $name, $language_code, $local_key)
            : $result;
    }

    /**
     * Retries a duplicate insert with an explicitly unique slug.
     *
     * WordPress refuses same-named siblings (`term_exists`) before it derives any slug, and the
     * only exception the core allows is an explicit slug that is free: the retry therefore
     * carries `<slug>-<language>` (the same shape WPML itself uses for duplicated term slugs),
     * falling back to numeric suffixes when that one is taken too. Dropping the slug instead
     * cannot help here, because the collision is on the name.
     */
    private static function insertTermWithUniqueSlug(
        \WP_Error $error,
        string $taxonomy,
        array $data,
        string $name,
        ?string $language_code
    ): array|\WP_Error {
        if ($error->get_error_code() !== 'term_exists') {
            return $error;
        }

        foreach (self::buildFreeSlugCandidates($data, $taxonomy, $name, $language_code) as $slug) {
            $retry = self::insertWordPressTerm(
                $taxonomy,
                array_merge($data, ['slug' => $slug]),
                $name,
                $language_code
            );

            if (!\is_wp_error($retry)) {
                return $retry;
            }
        }

        return $error;
    }

    /**
     * Slugs that no term of the taxonomy owns yet, in the order they should be attempted:
     * the payload slug (usable when only the name collided), then `<slug>-<language>`, then
     * numeric suffixes. The lookup goes through the repository, which reads the tables directly:
     * slug uniqueness is global to the taxonomy, while WPML would scope the check to one language.
     */
    private static function buildFreeSlugCandidates(
        array $data,
        string $taxonomy,
        string $name,
        ?string $language_code,
        int $limit = 3
    ): array {
        $base_slug = \sanitize_title(self::getDataSlug($data) ?? $name);
        if ($base_slug === '') {
            return [];
        }

        $language_slug = $language_code ? \sanitize_title($language_code) : '';
        $prefix = ($language_slug !== '' && $language_slug !== $base_slug)
            ? \sanitize_title($base_slug . '-' . $language_slug)
            : $base_slug;

        $attempts = [$base_slug, $prefix];
        for ($suffix = 2; $suffix <= 12; $suffix++) {
            $attempts[] = \sanitize_title($prefix . '-' . $suffix);
        }

        $candidates = [];
        foreach ($attempts as $candidate) {
            if ($candidate === '' || in_array($candidate, $candidates, true)) {
                continue;
            }

            if (self::findTermIdsBySlug($candidate, $taxonomy) !== []) {
                continue;
            }

            $candidates[] = $candidate;
            if (count($candidates) >= $limit) {
                break;
            }
        }

        return $candidates;
    }

    /** Re-routes a duplicate-slug update to the term WordPress considers the slug owner. */
    private static function recoverDuplicateSlugUpdate(
        \WP_Error $error,
        int $term_id,
        string $taxonomy,
        array $data,
        string $name,
        ?string $language_code = null,
        ?string $local_key = null,
        int $owner_term_id = 0
    ): array|\WP_Error {
        if ($error->get_error_code() !== 'duplicate_term_slug') {
            return $error;
        }

        $data_slug = self::getDataSlug($data);
        if ($data_slug === null) {
            return $error;
        }

        $forced = self::forceUpdateTermWithExistingSlug($error, $term_id, $taxonomy, $data, $name);
        if (!\is_wp_error($forced)) {
            return $forced;
        }

        $candidate_ids = array_values(array_unique(array_filter([
            self::findVisibleTermIdBySlug($data_slug, $taxonomy, $language_code),
            ...self::findTermIdsBySlug($data_slug, $taxonomy),
        ], fn(mixed $id): bool => is_int($id) && $id > 0 && $id !== $term_id)));

        foreach ($candidate_ids as $candidate_id) {
            if (!self::canUseTermForLanguage((int) $candidate_id, $taxonomy, $language_code)) {
                continue;
            }

            // Another element's term keeps its content: with an owner term (the element is
            // updating its own term) only a term of that element qualifies, otherwise any
            // term the local_key may adopt.
            $is_same_element = $owner_term_id > 0
                ? self::slugTermBelongsToElement((int) $candidate_id, $owner_term_id, $taxonomy, $local_key)
                : self::termMatchesLocalKey((int) $candidate_id, $local_key);
            if (!$is_same_element) {
                continue;
            }

            $retry = self::updateWordPressTerm((int) $candidate_id, $taxonomy, $data, $name, $language_code);

            if (!\is_wp_error($retry)) {
                return $retry;
            }

            $forced = self::forceUpdateTermWithExistingSlug($retry, (int) $candidate_id, $taxonomy, $data, $name);
            if (!\is_wp_error($forced)) {
                return $forced;
            }
        }

        return $error;
    }

    /** Last-resort update when WPML duplicate checks block a term that already owns the requested slug. */
    private static function forceUpdateTermWithExistingSlug(
        \WP_Error $error,
        int $term_id,
        string $taxonomy,
        array $data,
        string $name
    ): array|\WP_Error {
        if ($error->get_error_code() !== 'duplicate_term_slug') {
            return $error;
        }

        $data_slug = self::getDataSlug($data);
        if ($data_slug === null) {
            return $error;
        }

        global $wpdb;

        $slug = \sanitize_title($data_slug);
        $current_slug = $wpdb->get_var($wpdb->prepare(
            "SELECT slug FROM {$wpdb->terms} WHERE term_id = %d",
            $term_id
        ));
        if (!is_string($current_slug) || $current_slug !== $slug) {
            return $error;
        }

        $term_taxonomy_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
            $term_id,
            $taxonomy
        ));
        if (!$term_taxonomy_id) {
            return $error;
        }

        $term_updated = $wpdb->update(
            $wpdb->terms,
            [
                'name' => $name,
                'slug' => $slug,
            ],
            ['term_id' => $term_id],
            ['%s', '%s'],
            ['%d']
        );
        if ($term_updated === false) {
            return $error;
        }

        $taxonomy_updated = $wpdb->update(
            $wpdb->term_taxonomy,
            [
                'description' => is_scalar($data['description'] ?? null) ? (string) $data['description'] : '',
                'parent' => isset($data['parent']) ? (int) $data['parent'] : 0,
            ],
            ['term_taxonomy_id' => $term_taxonomy_id],
            ['%s', '%d'],
            ['%d']
        );
        if ($taxonomy_updated === false) {
            return $error;
        }

        \clean_term_cache($term_id, $taxonomy);

        return [
            'term_id' => $term_id,
            'term_taxonomy_id' => $term_taxonomy_id,
        ];
    }

    /** Finds an existing term by the explicit slug provided in term data. */
    private static function findTermIdByDataSlug(
        array $data,
        string $taxonomy,
        ?string $language_code = null,
        ?string $local_key = null
    ): int|null
    {
        $data_slug = self::getDataSlug($data);
        if ($data_slug === null) {
            return null;
        }

        $term_ids = self::findTermIdsBySlug($data_slug, $taxonomy);
        if ($term_ids === []) {
            $term_id = self::findBySlug($data_slug, $taxonomy);

            return ($term_id && self::canUseTermForLanguage($term_id, $taxonomy, $language_code))
                ? $term_id
                : null;
        }

        if (!$language_code || !onpage_is_wpml_active()) {
            return $term_ids[0];
        }

        $language_term_id = self::findTermIdWithLanguage($term_ids, $taxonomy, $language_code);
        if ($language_term_id) {
            return $language_term_id;
        }

        $untranslated_term_id = self::findTermIdWithoutLanguage($term_ids, $taxonomy);
        if ($untranslated_term_id) {
            return $untranslated_term_id;
        }

        if ($local_key !== null) {
            $local_key_term_id = self::findTermIdWithLocalKey($term_ids, $local_key);
            if ($local_key_term_id && self::canUseTermForLanguage($local_key_term_id, $taxonomy, $language_code)) {
                return $local_key_term_id;
            }
        }

        return null;
    }

    /** Creates or updates a term, preferring explicit slug matches over duplicate-name matches. */
    private static function upsertTermData(
        int $term_id,
        string $taxonomy,
        array $data,
        string $name,
        ?string $language_code = null,
        ?string $local_key = null
    ): array|\WP_Error {
        if ($term_id && $language_code) {
            $term_id = self::getTermIdForLanguage($term_id, $taxonomy, $language_code);
        }

        // With a term already resolved (by id or local_key), the slug holder replaces it only
        // when it is part of the same element: under WPML a same-language term of another
        // element may own the payload slug, and taking it would overwrite that element.
        $slug_term_id = self::findTermIdByDataSlug($data, $taxonomy, $language_code, $local_key);
        if ($slug_term_id && (
            $term_id
                ? self::slugTermBelongsToElement($slug_term_id, $term_id, $taxonomy, $local_key)
                : self::termMatchesLocalKey($slug_term_id, $local_key)
        )) {
            $term_id = $slug_term_id;
        }

        if ($term_id) {
            // A slug holder of the same element has already replaced $term_id above, so a
            // duplicate slug here is owned by a different term: recovery may only move the
            // write to another term of this element.
            $result = self::updateWordPressTerm($term_id, $taxonomy, $data, $name, $language_code);
            if (\is_wp_error($result)) {
                $result = self::recoverDuplicateSlugUpdate($result, $term_id, $taxonomy, $data, $name, $language_code, $local_key, $term_id);
            }

            // The payload slug is still owned by another element: update this term but keep
            // its current slug, the same way an insert falls back to a unique slug instead of
            // overwriting the owner (see updateExistingTermFromInsertError()).
            if (\is_wp_error($result) && $result->get_error_code() === 'duplicate_term_slug') {
                $data_without_slug = $data;
                unset($data_without_slug['slug']);

                return self::updateWordPressTerm($term_id, $taxonomy, $data_without_slug, $name, $language_code);
            }

            return $result;
        }

        $result = self::insertWordPressTerm($taxonomy, $data, $name, $language_code);
        if (!\is_wp_error($result)) {
            return $result;
        }

        return self::updateExistingTermFromInsertError($result, $taxonomy, $data, $name, $language_code, $local_key);
    }

    /** Creates or updates the base-language term. */
    private static function upsertBaseTerm(array $payload, string $taxonomy, string $base_name, int $element_index): array
    {
        $term_id_from_local_key = $payload['local_key']
            ? self::findByLocalKeyForLanguage($payload['local_key'], $taxonomy, $payload['base_language'], empty($payload['translated_languages']))
            : null;
        $term_id = $payload['requested_term_id'] ?: ($term_id_from_local_key ?: 0);

        if ($term_id && !empty($payload['translated_languages']) && $payload['base_language']) {
            $term_id = self::getTermIdForLanguage($term_id, $taxonomy, $payload['base_language']);
        }

        $data = self::buildTermData($payload, $taxonomy, $payload['base_language']);

        $result = self::upsertTermData($term_id, $taxonomy, $data, $base_name, $payload['base_language'], $payload['local_key']);

        if (\is_wp_error($result)) {
            throw onpage_http_exception("Term :: Element $element_index :: " . $result->get_error_message(), 500, 'request_failed');
        }

        $resolved_term_id = (int) $result['term_id'];
        self::setTermLocalKey($resolved_term_id, $payload['local_key']);
        self::ensureBaseTermLanguage($resolved_term_id, $taxonomy, $payload['base_language']);

        $base_fields = MultiLang::getFieldsForLanguage($payload['acf_field_map'], $payload['base_language'], $payload['fallback_language']);
        if (!empty($base_fields)) {
            self::updateAcfFields($resolved_term_id, $taxonomy, $base_fields, $element_index, $payload['base_language']);
        }

        return [
            'term_id' => $resolved_term_id,
            'trid' => 0,
        ];
    }

    /** Assigns the base WPML language to scalar/single-language term payloads when missing. */
    private static function ensureBaseTermLanguage(int $term_id, string $taxonomy, ?string $base_language): void
    {
        if (!$base_language || !onpage_is_wpml_active()) {
            return;
        }

        $existing_details = self::getTermLanguageDetails($term_id, $taxonomy);
        if (!empty($existing_details?->language_code)) {
            return;
        }

        self::setTermLanguage($term_id, $taxonomy, $base_language);
    }

    /** Ensures the base term belongs to a WPML translation group and returns its trid. */
    private static function ensureTranslationGroup(int $term_id, string $taxonomy, string $base_language, int $element_index): int
    {
        $existing_details = self::getTermLanguageDetails($term_id, $taxonomy);
        $existing_trid = isset($existing_details?->trid) ? (int) $existing_details->trid : 0;
        if (!$existing_trid) {
            self::setTermLanguage($term_id, $taxonomy, $base_language);
        }

        $language_details = self::getTermLanguageDetails($term_id, $taxonomy);
        $trid = isset($language_details?->trid) ? (int) $language_details->trid : 0;
        if (!$trid) {
            throw onpage_http_exception("Term :: Element $element_index :: Unable to resolve translation group (trid)", 500, 'wpml_error');
        }

        return $trid;
    }

    /** Creates or updates translated terms for the payload languages. */
    private static function syncTranslations(array $payload, string $taxonomy, int $term_id, int $element_index): void
    {
        if (empty($payload['translated_languages']) || !onpage_is_wpml_active() || !$payload['base_language']) {
            return;
        }

        $trid = self::ensureTranslationGroup($term_id, $taxonomy, $payload['base_language'], $element_index);

        $translations = self::getTermTranslations($trid, $taxonomy);

        foreach ($payload['translated_languages'] as $language_code) {
            if ($language_code === $payload['base_language']) {
                continue;
            }

            $translated_name = self::getDirectValueForLanguage($payload['name_map'], $language_code);
            if (!is_scalar($translated_name) || (string) $translated_name === '') {
                continue;
            }

            $translated_data = self::buildTermData($payload, $taxonomy, $language_code);

            $existing_translation = $translations[$language_code] ?? null;
            $translated_term_taxonomy_id = isset($existing_translation->element_id) ? (int) $existing_translation->element_id : 0;
            $local_key_term_id = $payload['local_key']
                ? (self::findByLocalKeyForLanguage($payload['local_key'], $taxonomy, (string) $language_code, false) ?: 0)
                : 0;
            $translated_term_id = $local_key_term_id
                ?: ($translated_term_taxonomy_id ? self::getTermIdByTaxonomyId($translated_term_taxonomy_id, $taxonomy) : 0);

            if (!$translated_term_id) {
                // A slug match may be adopted only when no other element owns it (see
                // termMatchesLocalKey()); otherwise a distinct translation is created.
                $slug_term_id = self::findTermIdByDataSlug($translated_data, $taxonomy, (string) $language_code, $payload['local_key']) ?: 0;
                $translated_term_id = ($slug_term_id && self::termMatchesLocalKey($slug_term_id, $payload['local_key'])) ? $slug_term_id : 0;
            }

            $translated_result = self::upsertTermData(
                $translated_term_id,
                $taxonomy,
                $translated_data,
                (string) $translated_name,
                (string) $language_code,
                $payload['local_key']
            );

            if (!\is_wp_error($translated_result)) {
                $result_term_id = (int) $translated_result['term_id'];
                $should_set_language = !$translated_term_taxonomy_id
                    || $local_key_term_id
                    || $result_term_id !== $translated_term_id;
                $translated_term_id = $result_term_id;

                if ($should_set_language) {
                    self::setTermLanguage($translated_term_id, $taxonomy, $language_code, $trid, $payload['base_language']);
                    $translations = self::getTermTranslations($trid, $taxonomy);
                }
            }

            if (\is_wp_error($translated_result)) {
                throw onpage_http_exception(
                    "Term :: Element $element_index :: Unable to upsert translation '$language_code' :: " . $translated_result->get_error_message(),
                    500,
                    'request_failed'
                );
            }

            self::setTermLocalKey($translated_term_id, $payload['local_key']);

            $translated_fields = MultiLang::getFieldsForLanguage($payload['acf_field_map'], $language_code, $payload['fallback_language']);
            if (!empty($translated_fields)) {
                self::updateAcfFields($translated_term_id, $taxonomy, $translated_fields, $element_index, $language_code);
            }
        }
    }

    /** Upserts one term payload and returns the base term ID. */
    public static function save(array $params, string $taxonomy, int $element_index): int
    {
        self::requireWpmlForPayloadLanguageMaps($params, $element_index);
        self::requireValidLocalKey($params, $element_index);

        $payload = self::parseTermPayload($params);
        $base_name = self::validateParsedPayload($payload, $taxonomy, $element_index);

        $base_term = self::upsertBaseTerm($payload, $taxonomy, $base_name, $element_index);

        self::syncTranslations($payload, $taxonomy, $base_term['term_id'], $element_index);
        self::propagateLocalKeyToTranslations($base_term['term_id'], $taxonomy, $payload['local_key']);

        return $base_term['term_id'];
    }

    /**
     * Writes the shared local_key onto every member of the term's WPML translation group,
     * so that updating one term (e.g. by id) also tags translations not present in the payload.
     */
    private static function propagateLocalKeyToTranslations(int $term_id, string $taxonomy, ?string $local_key): void
    {
        if (!$local_key) {
            return;
        }

        foreach (self::buildTranslationsMap($term_id, $taxonomy) as $translated_term_id) {
            self::setTermLocalKey($translated_term_id, $local_key);
        }
    }

    /** Deletes a single term by ID; the taxonomy is resolved from the term itself when null. */
    public static function deleteById(int $term_id, ?string $taxonomy = null): void
    {
        $taxonomy = $taxonomy ?? self::getTermTaxonomy($term_id);
        if ($taxonomy === null) {
            throw onpage_http_exception("Term :: Term $term_id not found", 404, 'not_found');
        }

        // wp_delete_term() reports some failures (e.g. an unknown taxonomy) with a WP_Error,
        // which is truthy, so it must be checked explicitly.
        $deleted = \wp_delete_term($term_id, $taxonomy);
        if (!$deleted || \is_wp_error($deleted)) {
            $reason = \is_wp_error($deleted) ? ' :: ' . $deleted->get_error_message() : '';

            throw onpage_http_exception("Term :: Unable to delete$reason", 500, 'delete_failed');
        }
    }
}
