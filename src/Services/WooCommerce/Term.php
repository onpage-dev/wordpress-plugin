<?php



namespace OnPage\Services\WooCommerce;



use OnPage\Services\Input;
use OnPage\Services\RemoteMedia;
use OnPage\Services\Term as TermService;
use OnPage\Services\TermRepository;



class Term
{
    private const CATEGORY_TAXONOMY = 'product_cat';
    private const TAG_TAXONOMY = 'product_tag';
    private const THUMBNAIL_META = 'thumbnail_id';
    private const THUMBNAIL_TAXONOMIES = [
        'product_cat',
    ];
    private const LOCAL_KEY_UPSERT_TAXONOMIES = [
        self::CATEGORY_TAXONOMY,
        self::TAG_TAXONOMY,
    ];



    /** Ensures WooCommerce helpers are available. */
    private static function requireWooCommerce(string $error_prefix): void
    {
        if (!\function_exists('wc_get_product')) {
            throw onpage_http_exception($error_prefix . ' :: WooCommerce is required', 500, 'woocommerce_required');
        }
    }

    /** Ensures a WooCommerce taxonomy is registered for the current request. */
    private static function requireTaxonomy(string $taxonomy, string $error_prefix): void
    {
        self::requireWooCommerce($error_prefix);

        if (!\taxonomy_exists($taxonomy)) {
            throw onpage_http_exception($error_prefix . " :: Taxonomy '$taxonomy' not found", 404, 'not_found');
        }
    }

    /** Finds term IDs by local_key meta for a WooCommerce taxonomy. */
    private static function findTermIdsByLocalKey(string $taxonomy, string $local_key): array
    {
        return TermRepository::findTermIdsByLocalKey($local_key, $taxonomy);
    }

    /** Finds one term ID by slug for a WooCommerce taxonomy. */
    private static function findTermIdBySlug(string $taxonomy, string $slug): int|null
    {
        $term = \get_term_by('slug', $slug, $taxonomy);
        if (!$term || \is_wp_error($term)) {
            return null;
        }

        return (int) $term->term_id;
    }

    /** Resolves term IDs from a local_key value. */
    private static function resolveTermIdsByLocalKey(string $taxonomy, string $value): array
    {
        return self::findTermIdsByLocalKey($taxonomy, $value);
    }

    /** Whether this WooCommerce taxonomy supports the top-level `thumbnail` payload field. */
    private static function supportsThumbnail(string $taxonomy): bool
    {
        return in_array($taxonomy, self::THUMBNAIL_TAXONOMIES, true);
    }

    /** Resolves a taxonomy term thumbnail (existing attachment ID or remote URL to import), or returns null to clear it. */
    private static function resolveThumbnailAttachmentId(mixed $value, int $element_index, string $error_prefix): int|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            if (!RemoteMedia::isAttachmentId($value)) {
                throw onpage_http_exception($error_prefix . " :: Element $element_index :: Parameter 'thumbnail' must be an existing attachment ID, a valid remote URL, or null", 400, 'invalid_param');
            }

            return $value;
        }

        $url = RemoteMedia::sanitizeUrl($value);
        if ($url === null) {
            throw onpage_http_exception($error_prefix . " :: Element $element_index :: Parameter 'thumbnail' must be an existing attachment ID, a valid remote URL, or null", 400, 'invalid_param');
        }

        $import_result = RemoteMedia::urlToMediaLibrary($url, 'thumbnail');

        return (int) $import_result['attachment_id'];
    }

    /** Saves or clears the WooCommerce thumbnail term meta for supported taxonomies and local_key translations. */
    private static function syncThumbnail(array $params, string $taxonomy, int $term_id, int $element_index, string $error_prefix): void
    {
        if (!self::supportsThumbnail($taxonomy) || !array_key_exists('thumbnail', $params)) {
            return;
        }

        $attachment_id = self::resolveThumbnailAttachmentId($params['thumbnail'], $element_index, $error_prefix);
        $term_ids = [$term_id];

        $local_key = Input::localKey($params['local_key'] ?? null);
        if ($local_key !== null) {
            $term_ids = array_merge($term_ids, self::findTermIdsByLocalKey($taxonomy, $local_key));
        }

        foreach (array_unique(array_map('intval', $term_ids)) as $resolved_term_id) {
            if ($attachment_id === null) {
                \delete_term_meta($resolved_term_id, self::THUMBNAIL_META);
                continue;
            }

            \update_term_meta($resolved_term_id, self::THUMBNAIL_META, $attachment_id);
        }
    }

    /** Returns the local_key from a term payload (positive integer or non-empty string), or null when absent. */
    private static function getPayloadLocalKey(array $params): string|null
    {
        return Input::localKey($params['local_key'] ?? null);
    }

    /** Whether local_key should win over stale IDs for this WooCommerce taxonomy. */
    private static function shouldUseLocalKeyUpsert(string $taxonomy): bool
    {
        return in_array($taxonomy, self::LOCAL_KEY_UPSERT_TAXONOMIES, true)
            || str_starts_with($taxonomy, 'pa_');
    }

    /** Lets local_key drive upserts, ignoring stale IDs without deleting existing relationships. */
    private static function prepareLocalKeyUpsertPayload(array $params, string $taxonomy): array
    {
        if (!self::shouldUseLocalKeyUpsert($taxonomy)) {
            return $params;
        }

        $local_key = self::getPayloadLocalKey($params);
        if ($local_key === null) {
            return $params;
        }

        // An explicit id that resolves to a real term wins (update by id + set local_key,
        // like POST /woocommerce/products). Only stale ids that don't resolve in this
        // environment are dropped, so local_key can still drive the upsert. An id that is not
        // a positive integer is kept, so Term::save() rejects it instead of creating a new term.
        $requested_id = Input::positiveInt($params['id'] ?? null);
        if (!in_array($params['id'] ?? null, [null, 0, '0'], true) && $requested_id === null) {
            return $params;
        }
        if ($requested_id !== null && \get_term($requested_id, $taxonomy) instanceof \WP_Term) {
            return $params;
        }

        unset($params['id']);

        return $params;
    }

    /** Allows WooCommerce category parents to be referenced by On Page® local_key or cleared with null. */
    private static function prepareParentPayload(array $params, string $taxonomy, int $element_index, string $error_prefix): array
    {
        // Internal key: never taken from the client payload.
        unset($params['__parent_local_key']);

        if ($taxonomy !== self::CATEGORY_TAXONOMY || !array_key_exists('parent', $params)) {
            return $params;
        }

        $parent = $params['parent'];
        if ($parent === null) {
            $params['parent'] = 0;

            return $params;
        }

        $parent_local_key = Input::localKey($parent);
        if ($parent_local_key === null) {
            throw onpage_http_exception($error_prefix . " :: Element $element_index :: Parameter 'parent' must be null or the parent category local_key (positive integer or non-empty string)", 400, 'invalid_param');
        }

        if (!TermService::findIdByLocalKey($parent_local_key, $taxonomy)) {
            throw onpage_http_exception($error_prefix . " :: Element $element_index :: Parent term with local_key '$parent_local_key' not found", 404, 'not_found');
        }

        $params['parent'] = 0;
        $params['__parent_local_key'] = $parent_local_key;

        return $params;
    }

    /** Lists WooCommerce taxonomy terms, optionally filtered by id/local_key/slug/name/parent. */
    public static function list(\WP_REST_Request $request, string $taxonomy, string $error_prefix): array
    {
        self::requireTaxonomy($taxonomy, $error_prefix);

        $term_id = Input::positiveInt($request->get_param('id'));
        if ($term_id !== null) {
            return [TermService::buildTermResponse(TermService::requireTermById($term_id, $taxonomy))];
        }

        $local_key = Input::localKey($request->get_param('local_key'));
        if ($local_key !== null) {
            return array_map(
                fn(int $id): array => TermService::buildTermResponse(TermService::requireTermById($id, $taxonomy)),
                self::findTermIdsByLocalKey($taxonomy, $local_key)
            );
        }

        $slug = Input::requestString($request, 'slug');
        if ($slug !== null) {
            $term_id = self::findTermIdBySlug($taxonomy, $slug);
            return array_map(
                fn(int $id): array => TermService::buildTermResponse(TermService::requireTermById($id, $taxonomy)),
                $term_id ? [$term_id] : []
            );
        }

        $name_queries = Input::langValueQueries($request->get_param('name'));
        if ($name_queries !== []) {
            return TermService::searchByName($name_queries, $taxonomy);
        }

        $parent_ids = TermService::resolveParentFilterIds(
            Input::positiveInt($request->get_param('parent_id')),
            Input::localKey($request->get_param('parent_lk')),
            $taxonomy,
            $error_prefix
        );

        return array_map([TermService::class, 'buildTermResponse'], TermService::listByTaxonomy($taxonomy, $parent_ids));
    }

    /** Creates or updates one WooCommerce taxonomy term. */
    public static function save(array $params, string $taxonomy, int $element_index, string $error_prefix): int
    {
        self::requireTaxonomy($taxonomy, $error_prefix);
        $params = self::prepareLocalKeyUpsertPayload($params, $taxonomy);
        $params = self::prepareParentPayload($params, $taxonomy, $element_index, $error_prefix);

        $term_id = TermService::save($params, $taxonomy, $element_index);
        self::syncThumbnail($params, $taxonomy, $term_id, $element_index, $error_prefix);

        return $term_id;
    }

    /** Deletes WooCommerce taxonomy terms by local_key. */
    public static function delete(string $local_key, string $taxonomy, bool $ignore_missing, int $element_index, string $error_prefix): void
    {
        self::requireTaxonomy($taxonomy, $error_prefix);

        $term_ids = self::resolveTermIdsByLocalKey($taxonomy, $local_key);
        if ($term_ids === []) {
            if ($ignore_missing) return;

            throw onpage_http_exception($error_prefix . " :: Element $element_index :: Term with local_key '$local_key' not found", 404, 'not_found');
        }

        // Members already removed with their original (WPML "delete translations") are skipped.
        foreach ($term_ids as $resolved_term_id) {
            TermService::deleteById($resolved_term_id, $taxonomy, true);
        }
    }
}