<?php



namespace OnPage\Services\WooCommerce;



use OnPage\Services\Input;
use OnPage\Services\Taxonomy;
use OnPage\Services\Term;
use OnPage\Services\TermRepository;
use OnPage\Services\RemoteMedia;



class Brand
{
    private const ERROR_PREFIX = 'WooCommerce Brand';
    private const TAXONOMY = 'product_brand';
    private const PRODUCT_POST_TYPE = 'product';
    private const THUMBNAIL_META = 'thumbnail_id';



    /** Ensures WooCommerce helpers are available. */
    private static function requireWooCommerce(): void
    {
        if (!\function_exists('wc_get_product')) {
            throw onpage_http_exception(self::ERROR_PREFIX . ' :: WooCommerce is required', 500, 'woocommerce_required');
        }
    }

    /** Whether the persisted ACF taxonomy definition exists. */
    private static function acfTaxonomyExists(): bool
    {
        return \function_exists('acf_get_taxonomy') && (bool) \acf_get_taxonomy(self::TAXONOMY);
    }

    /** Runtime registration args for the WooCommerce brand taxonomy. */
    private static function getRuntimeTaxonomyArgs(): array
    {
        return [
            'labels' => [
                'name' => 'Brands',
                'singular_name' => 'Brand',
                'menu_name' => 'Brands',
            ],
            'public' => true,
            'publicly_queryable' => true,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_admin_column' => true,
            'rewrite' => [
                'slug' => 'brand',
                'with_front' => true,
                'hierarchical' => true,
            ],
        ];
    }

    /** Persisted ACF taxonomy payload for WooCommerce brands. */
    private static function getTaxonomyPayload(): array
    {
        return [
            'key' => self::TAXONOMY,
            'title' => 'Brands',
            'singular_label' => 'Brand',
            'plural_label' => 'Brands',
            'description' => 'WooCommerce product brands',
            'object_type' => [self::PRODUCT_POST_TYPE],
            'hierarchical' => true,
            'show_admin_column' => true,
            'rewrite' => [
                'slug' => 'brand',
                'with_front' => true,
                'rewrite_hierarchical' => true,
            ],
        ];
    }

    /** Registers the taxonomy for the current request when WordPress does not know it yet. */
    private static function ensureRuntimeTaxonomy(): void
    {
        if (\taxonomy_exists(self::TAXONOMY)) {
            return;
        }

        \register_taxonomy(self::TAXONOMY, [self::PRODUCT_POST_TYPE], self::getRuntimeTaxonomyArgs());
    }

    /** True when the brand taxonomy is available either in runtime or in ACF storage. */
    private static function hasTaxonomy(): bool
    {
        return \taxonomy_exists(self::TAXONOMY) || self::acfTaxonomyExists();
    }

    /** Ensures the WooCommerce brand taxonomy exists and is usable in the current request. */
    private static function ensureTaxonomy(bool $persist): void
    {
        self::requireWooCommerce();

        $created = false;
        if ($persist && !\taxonomy_exists(self::TAXONOMY) && !self::acfTaxonomyExists()) {
            try {
                Taxonomy::saveFromParams(self::getTaxonomyPayload());
                $created = true;
            } catch (\OnPage\Exceptions\HttpException $e) {
                if ($e->error_code !== 'already_exists') {
                    throw $e;
                }
            }
        }

        self::ensureRuntimeTaxonomy();

        if ($created) {
            \flush_rewrite_rules();
        }
    }

    /** Finds brand term IDs by local_key meta (direct query, bypassing WPML term filters). */
    private static function findTermIdsByLocalKey(string $local_key): array
    {
        return TermRepository::findTermIdsByLocalKey($local_key, self::TAXONOMY);
    }

    /** Resolves brand term IDs from a local_key value. */
    private static function resolveTermIdsByLocalKey(string $local_key): array
    {
        return self::findTermIdsByLocalKey($local_key);
    }

    /** Finds one brand term ID by slug. */
    private static function findTermIdBySlug(string $slug): int|null
    {
        $term = \get_term_by('slug', $slug, self::TAXONOMY);
        if (!$term || \is_wp_error($term)) {
            return null;
        }

        return (int) $term->term_id;
    }

    /** Resolves a brand thumbnail (existing attachment ID or remote URL to import), or returns null to clear it. */
    private static function resolveThumbnailAttachmentId(mixed $value, int $element_index): int|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            if (!RemoteMedia::isAttachmentId($value)) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'thumbnail' must be an existing attachment ID, a valid remote URL, or null", 400, 'invalid_param');
            }

            return $value;
        }

        $url = RemoteMedia::sanitizeUrl($value);
        if ($url === null) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'thumbnail' must be an existing attachment ID, a valid remote URL, or null", 400, 'invalid_param');
        }

        $import_result = RemoteMedia::urlToMediaLibrary($url, 'thumbnail');

        return (int) $import_result['attachment_id'];
    }

    /** Saves or clears the WooCommerce thumbnail term meta for the base brand and its local_key translations. */
    private static function syncThumbnail(array $params, int $term_id, int $element_index): void
    {
        if (!array_key_exists('thumbnail', $params)) {
            return;
        }

        $attachment_id = self::resolveThumbnailAttachmentId($params['thumbnail'], $element_index);
        $term_ids = [$term_id];

        $local_key = Input::localKey($params['local_key'] ?? null);
        if ($local_key !== null) {
            $term_ids = array_merge($term_ids, self::findTermIdsByLocalKey($local_key));
        }

        foreach (array_unique(array_map('intval', $term_ids)) as $resolved_term_id) {
            if ($attachment_id === null) {
                \delete_term_meta($resolved_term_id, self::THUMBNAIL_META);
                continue;
            }

            \update_term_meta($resolved_term_id, self::THUMBNAIL_META, $attachment_id);
        }
    }

    /** Allows brand parents to be referenced by On Page® local_key or cleared with null. */
    private static function prepareParentPayload(array $params, int $element_index): array
    {
        // Internal key: never taken from the client payload.
        unset($params['__parent_local_key']);

        if (!array_key_exists('parent', $params)) {
            return $params;
        }

        $parent = $params['parent'];
        if ($parent === null) {
            $params['parent'] = 0;

            return $params;
        }

        $parent_local_key = Input::localKey($parent);
        if ($parent_local_key === null) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'parent' must be null or the parent brand local_key (positive integer or non-empty string)", 400, 'invalid_param');
        }

        if (!Term::findIdByLocalKey($parent_local_key, self::TAXONOMY)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parent term with local_key '$parent_local_key' not found", 404, 'not_found');
        }

        $params['parent'] = 0;
        $params['__parent_local_key'] = $parent_local_key;

        return $params;
    }

    /** Lists WooCommerce brands, optionally filtered by id/local_key/slug/name/parent. */
    public static function list(\WP_REST_Request $request): array
    {
        self::requireWooCommerce();
        if (!self::hasTaxonomy()) {
            return [];
        }

        self::ensureRuntimeTaxonomy();

        $term_id = Input::positiveInt($request->get_param('id'));
        if ($term_id !== null) {
            return [Term::buildTermResponse(Term::requireTermById($term_id, self::TAXONOMY))];
        }

        $local_key = Input::localKey($request->get_param('local_key'));
        if ($local_key !== null) {
            return array_map(
                fn(int $id): array => Term::buildTermResponse(Term::requireTermById($id, self::TAXONOMY)),
                self::findTermIdsByLocalKey($local_key)
            );
        }

        $slug = Input::requestString($request, 'slug');
        if ($slug !== null) {
            $term_id = self::findTermIdBySlug($slug);
            return array_map(
                fn(int $id): array => Term::buildTermResponse(Term::requireTermById($id, self::TAXONOMY)),
                $term_id ? [$term_id] : []
            );
        }

        $name_queries = Input::langValueQueries($request->get_param('name'));
        if ($name_queries !== []) {
            return Term::searchByName($name_queries, self::TAXONOMY);
        }

        $parent_ids = Term::resolveParentFilterIds(
            Input::positiveInt($request->get_param('parent_id')),
            Input::localKey($request->get_param('parent_lk')),
            self::TAXONOMY,
            self::ERROR_PREFIX
        );

        return array_map([Term::class, 'buildTermResponse'], Term::listByTaxonomy(self::TAXONOMY, $parent_ids));
    }

    /** Creates or updates one WooCommerce brand term. */
    public static function save(array $params, int $element_index): int
    {
        self::ensureTaxonomy(true);

        $params = self::prepareParentPayload($params, $element_index);

        $term_id = Term::save($params, self::TAXONOMY, $element_index);
        self::syncThumbnail($params, $term_id, $element_index);

        return $term_id;
    }

    /** Deletes WooCommerce brand terms by local_key. */
    public static function delete(string $local_key, bool $ignore_missing, int $element_index): void
    {
        self::requireWooCommerce();
        if (!self::hasTaxonomy()) {
            if ($ignore_missing) return;

            throw onpage_http_exception(self::ERROR_PREFIX . ' :: Brand taxonomy not found', 404, 'not_found');
        }

        self::ensureRuntimeTaxonomy();

        $term_ids = self::resolveTermIdsByLocalKey($local_key);
        if ($term_ids === []) {
            if ($ignore_missing) return;

            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Brand with local_key '$local_key' not found", 404, 'not_found');
        }

        // Members already removed with their original (WPML "delete translations") are skipped.
        foreach ($term_ids as $resolved_term_id) {
            Term::deleteById($resolved_term_id, self::TAXONOMY, true);
        }
    }
}
