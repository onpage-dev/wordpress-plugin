<?php



namespace OnPage\Services\WooCommerce;



use OnPage\Services\Input;



class Attribute
{
    private const ERROR_PREFIX = 'WooCommerce Attribute';
    private const LOCAL_KEY_OPTION_PREFIX = 'onpage_wc_attribute_local_key_';

    /** Request-scoped cache of local_key => attribute IDs, reset on any local_key option write. */
    private static array $localKeyIdsCache = [];



    /** Ensures WooCommerce attribute CRUD helpers are available. */
    private static function requireWooCommerce(): void
    {
        if (
            !\function_exists('wc_get_attribute_taxonomies') ||
            !\function_exists('wc_create_attribute') ||
            !\function_exists('wc_update_attribute') ||
            !\function_exists('wc_delete_attribute')
        ) {
            throw httpException(self::ERROR_PREFIX . ' :: WooCommerce is required', 500, 'woocommerce_required');
        }
    }

    /** Normalizes prefixed or unprefixed attribute slugs to WooCommerce's stored slug. */
    private static function normalizeSlug(mixed $value): string|null
    {
        if (!is_scalar($value)) {
            return null;
        }

        $slug = trim((string) $value);
        if ($slug === '') {
            return null;
        }

        $slug = \function_exists('wc_sanitize_taxonomy_name')
            ? \wc_sanitize_taxonomy_name($slug)
            : \sanitize_title($slug);

        return (string) preg_replace('/^pa_/', '', $slug);
    }

    /** Returns a scalar string param when present, or throws when the shape is invalid. */
    private static function optionalStringParam(array $params, string $key, int $element_index): string|null
    {
        if (!array_key_exists($key, $params)) {
            return null;
        }

        if (!is_scalar($params[$key])) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter '$key' must be a string", 400, 'invalid_param');
        }

        return trim((string) $params[$key]);
    }

    /** Converts common scalar bool representations to a boolean. */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /** Raw WooCommerce attribute taxonomy rows. */
    private static function getAttributes(): array
    {
        self::requireWooCommerce();

        $attributes = \wc_get_attribute_taxonomies();

        return is_array($attributes) ? array_values($attributes) : [];
    }

    /** Finds a raw WooCommerce attribute by ID. */
    private static function findAttributeById(int $attribute_id): object|null
    {
        foreach (self::getAttributes() as $attribute) {
            if ((int) ($attribute->attribute_id ?? 0) === $attribute_id) {
                return $attribute;
            }
        }

        return null;
    }

    /** Finds a raw WooCommerce attribute by prefixed or unprefixed slug. */
    private static function findAttributeBySlug(string $slug): object|null
    {
        $normalized_slug = self::normalizeSlug($slug);
        if (!$normalized_slug) {
            return null;
        }

        foreach (self::getAttributes() as $attribute) {
            if ((string) ($attribute->attribute_name ?? '') === $normalized_slug) {
                return $attribute;
            }
        }

        return null;
    }

    /** Finds raw WooCommerce attributes by exact name (label). */
    private static function findAttributesByName(string $name): array
    {
        $matches = [];
        foreach (self::getAttributes() as $attribute) {
            if ((string) ($attribute->attribute_label ?? '') === $name) {
                $matches[] = $attribute;
            }
        }

        return $matches;
    }

    /** Option name that stores the external local_key for one WooCommerce attribute ID. */
    private static function localKeyOptionName(int $attribute_id): string
    {
        return self::LOCAL_KEY_OPTION_PREFIX . $attribute_id;
    }

    /** Returns local_key for an attribute ID rendered for output, or null when not set. */
    private static function getLocalKeyById(int $attribute_id): int|string|null
    {
        return Input::localKeyOut(\get_option(self::localKeyOptionName($attribute_id), null));
    }

    /** Returns all attribute IDs mapped to one local_key. */
    private static function findAttributeIdsByLocalKey(string $local_key): array
    {
        if (array_key_exists($local_key, self::$localKeyIdsCache)) {
            return self::$localKeyIdsCache[$local_key];
        }

        global $wpdb;

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name
            FROM {$wpdb->options}
            WHERE option_name LIKE %s
            AND option_value = %s",
            self::LOCAL_KEY_OPTION_PREFIX . '%',
            $local_key
        ));

        if (!is_array($results)) {
            return [];
        }

        $attribute_ids = [];
        foreach ($results as $option_name) {
            $suffix = (string) preg_replace('/^' . preg_quote(self::LOCAL_KEY_OPTION_PREFIX, '/') . '/', '', (string) $option_name);
            $attribute_id = Input::positiveInt($suffix);
            if ($attribute_id !== null) {
                $attribute_ids[] = $attribute_id;
            }
        }

        sort($attribute_ids);

        $attribute_ids = array_values(array_unique($attribute_ids));
        self::$localKeyIdsCache[$local_key] = $attribute_ids;

        return $attribute_ids;
    }

    /** Finds one attribute by local_key. */
    private static function findAttributeByLocalKey(string $local_key): object|null
    {
        foreach (self::findExistingAttributeIdsByLocalKey($local_key) as $attribute_id) {
            $attribute = self::findAttributeById($attribute_id);
            if ($attribute) {
                return $attribute;
            }
        }

        return null;
    }

    /** Ensures local_key is unique across WooCommerce global attributes. */
    private static function assertLocalKeyIsUnique(string $local_key, int $attribute_id, int $element_index): void
    {
        foreach (self::findAttributeIdsByLocalKey($local_key) as $existing_attribute_id) {
            if (!self::findAttributeById($existing_attribute_id)) {
                self::deleteLocalKeyForAttribute($existing_attribute_id);
                continue;
            }

            if ($existing_attribute_id === $attribute_id) {
                continue;
            }

            throw httpException(
                self::ERROR_PREFIX . " :: Element $element_index :: Duplicate local_key '$local_key' already used by attribute $existing_attribute_id",
                409,
                'duplicate_local_key'
            );
        }
    }

    /** Stores local_key for one WooCommerce attribute after uniqueness checks. */
    private static function persistLocalKeyForAttribute(int $attribute_id, string $local_key, int $element_index): void
    {
        self::assertLocalKeyIsUnique($local_key, $attribute_id, $element_index);
        \update_option(self::localKeyOptionName($attribute_id), $local_key, false);
        self::$localKeyIdsCache = [];
    }

    /** Returns existing attribute IDs mapped to local_key, cleaning stale mappings. */
    private static function findExistingAttributeIdsByLocalKey(string $local_key): array
    {
        $attribute_ids = [];

        foreach (self::findAttributeIdsByLocalKey($local_key) as $attribute_id) {
            if (!self::findAttributeById($attribute_id)) {
                self::deleteLocalKeyForAttribute($attribute_id);
                continue;
            }

            $attribute_ids[] = $attribute_id;
        }

        return $attribute_ids;
    }

    /** Removes local_key mapping for one WooCommerce attribute ID. */
    private static function deleteLocalKeyForAttribute(int $attribute_id): void
    {
        \delete_option(self::localKeyOptionName($attribute_id));
        self::$localKeyIdsCache = [];
    }

    /** Returns a required local_key (positive integer or non-empty string) from payload. */
    private static function requireLocalKeyParam(array $params, int $element_index): string
    {
        return Input::requireLocalKeyParam($params, 'local_key', self::ERROR_PREFIX, $element_index);
    }

    /** Returns the local_key from payload (positive integer or non-empty string), or null when absent. */
    private static function optionalLocalKeyParam(array $params): string|null
    {
        return Input::localKey($params['local_key'] ?? null);
    }

    /** Returns a raw WooCommerce attribute by ID or throws. */
    private static function requireAttributeById(int $attribute_id): object
    {
        $attribute = self::findAttributeById($attribute_id);
        if (!$attribute) {
            throw httpException(self::ERROR_PREFIX . " :: Attribute $attribute_id not found", 404, 'not_found');
        }

        return $attribute;
    }

    /** Registers one attribute taxonomy when WooCommerce has not done it yet in the current request. */
    private static function ensureAttributeTaxonomyRegistered(object $attribute, string $taxonomy): void
    {
        if ($taxonomy === '' || \taxonomy_exists($taxonomy)) {
            return;
        }

        if (\class_exists('\WC_Post_Types') && \method_exists('\WC_Post_Types', 'register_taxonomies')) {
            \WC_Post_Types::register_taxonomies();
        }

        if (\taxonomy_exists($taxonomy)) {
            return;
        }

        $label = (string) ($attribute->attribute_label ?? $attribute->attribute_name ?? $taxonomy);
        $is_public = (bool) (int) ($attribute->attribute_public ?? 0);

        \register_taxonomy($taxonomy, ['product'], [
            'hierarchical' => false,
            'update_count_callback' => '_update_post_term_count',
            'labels' => [
                'name' => 'Product ' . $label,
                'singular_name' => $label,
            ],
            'show_ui' => true,
            'show_in_quick_edit' => false,
            'show_in_menu' => false,
            'meta_box_cb' => false,
            'query_var' => $is_public,
            'rewrite' => false,
            'sort' => false,
            'public' => $is_public,
            'show_in_nav_menus' => false,
            'capabilities' => [
                'manage_terms' => 'manage_product_terms',
                'edit_terms' => 'edit_product_terms',
                'delete_terms' => 'delete_product_terms',
                'assign_terms' => 'assign_product_terms',
            ],
        ]);

        global $wc_product_attributes;
        if (is_array($wc_product_attributes)) {
            $wc_product_attributes[$taxonomy] = $attribute;
        }
    }

    /** Resolves an attribute ID/slug (`color` or `pa_color`) to its registered taxonomy. */
    public static function requireAttributeTaxonomy(mixed $value, string $error_prefix = self::ERROR_PREFIX): string
    {
        self::requireWooCommerce();

        $attribute = null;
        $attribute_id = Input::positiveInt($value);
        if ($attribute_id !== null) {
            $attribute = self::findAttributeById($attribute_id);
        } elseif (($slug = Input::stringOrNull($value)) !== null) {
            $attribute = self::findAttributeBySlug($slug);
        } else {
            throw httpException($error_prefix . " :: Attribute ID or slug is required", 400, 'invalid_param');
        }

        if (!$attribute) {
            throw httpException($error_prefix . " :: Attribute '$value' not found", 404, 'not_found');
        }

        $attribute_slug = (string) ($attribute->attribute_name ?? '');
        $taxonomy = \function_exists('wc_attribute_taxonomy_name')
            ? \wc_attribute_taxonomy_name($attribute_slug)
            : 'pa_' . $attribute_slug;

        self::ensureAttributeTaxonomyRegistered($attribute, $taxonomy);

        if ($taxonomy === '' || !\taxonomy_exists($taxonomy)) {
            throw httpException($error_prefix . " :: Attribute taxonomy '$taxonomy' not found", 404, 'not_found');
        }

        return $taxonomy;
    }

    /** API response shape for one WooCommerce global attribute. */
    private static function buildAttributeResponse(object $attribute): array
    {
        $attribute_slug = (string) ($attribute->attribute_name ?? '');
        $taxonomy = \function_exists('wc_attribute_taxonomy_name')
            ? \wc_attribute_taxonomy_name($attribute_slug)
            : 'pa_' . $attribute_slug;
        $attribute_id = (int) ($attribute->attribute_id ?? 0);

        return [
            'id' => $attribute_id,
            'name' => (string) ($attribute->attribute_label ?? ''),
            'slug' => $taxonomy,
            'attribute_slug' => $attribute_slug,
            'local_key' => self::getLocalKeyById($attribute_id),
            'type' => (string) ($attribute->attribute_type ?? 'select'),
            'order_by' => (string) ($attribute->attribute_orderby ?? 'menu_order'),
            'has_archives' => (bool) (int) ($attribute->attribute_public ?? 0),
            'taxonomy' => $taxonomy,
            'translations' => [],
        ];
    }

    /** Converts a WooCommerce WP_Error into this plugin's HttpException shape. */
    private static function throwWooCommerceError(\WP_Error $error, int $element_index): void
    {
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 500;
        if ($status < 100) {
            $status = 500;
        }

        throw httpException(
            self::ERROR_PREFIX . " :: Element $element_index :: " . $error->get_error_message(),
            $status,
            $error->get_error_code() ?: 'request_failed'
        );
    }

    /** Builds the args accepted by WooCommerce attribute CRUD helpers. */
    private static function buildAttributeArgs(array $params, int $element_index, bool $creating): array
    {
        $args = [];

        $name = self::optionalStringParam($params, 'name', $element_index);
        if ($name !== null) {
            if ($name === '') {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'name' is required", 400, 'invalid_param');
            }

            $args['name'] = $name;
        } elseif ($creating) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'name' is required", 400, 'invalid_param');
        }

        $slug = self::optionalStringParam($params, 'slug', $element_index);
        if ($slug !== null) {
            $args['slug'] = $slug;
        }

        $type = self::optionalStringParam($params, 'type', $element_index);
        if ($type !== null && $type !== '') {
            $args['type'] = $type;
        }

        $order_by = self::optionalStringParam($params, 'order_by', $element_index);
        if ($order_by !== null && $order_by !== '') {
            $args['order_by'] = $order_by;
        }

        if (array_key_exists('has_archives', $params)) {
            $args['has_archives'] = self::toBool($params['has_archives']);
        }

        return $args;
    }

    /** Lists WooCommerce global attributes, optionally filtered by id, local_key or slug. */
    public static function list(\WP_REST_Request $request): array
    {
        self::requireWooCommerce();

        $attribute_id = Input::positiveInt($request->get_param('id'));
        if ($attribute_id !== null) {
            return [self::buildAttributeResponse(self::requireAttributeById($attribute_id))];
        }

        $slug = Input::requestString($request, 'slug');
        if ($slug !== null) {
            $attribute = self::findAttributeBySlug($slug);
            return $attribute ? [self::buildAttributeResponse($attribute)] : [];
        }

        $local_key = Input::localKey($request->get_param('local_key'));
        if ($local_key !== null) {
            $attribute = self::findAttributeByLocalKey($local_key);
            return $attribute ? [self::buildAttributeResponse($attribute)] : [];
        }

        $name = Input::requestString($request, 'name');
        if ($name !== null) {
            return array_map(
                fn(object $attribute): array => self::buildAttributeResponse($attribute),
                self::findAttributesByName($name)
            );
        }

        return array_map(
            fn(object $attribute): array => self::buildAttributeResponse($attribute),
            self::getAttributes()
        );
    }

    /** Creates or updates one WooCommerce global attribute. */
    public static function save(array $params, int $element_index): int
    {
        self::requireWooCommerce();

        // Explicit id updates that attribute (local_key optional), matching POST /woocommerce/products.
        $attribute_id = Input::positiveInt($params['id'] ?? null);
        if ($attribute_id !== null) {
            self::requireAttributeById($attribute_id);

            return self::updateAttribute($attribute_id, $params, self::optionalLocalKeyParam($params), $element_index);
        }

        // No explicit id: local_key drives the upsert and stays required.
        $local_key = self::requireLocalKeyParam($params, $element_index);

        $existing_by_local_key = self::findAttributeByLocalKey($local_key);
        if ($existing_by_local_key) {
            return self::updateAttribute((int) $existing_by_local_key->attribute_id, $params, $local_key, $element_index);
        }

        $slug = self::optionalStringParam($params, 'slug', $element_index);
        if ($slug !== null && $slug !== '') {
            $existing_attribute = self::findAttributeBySlug($slug);
            if ($existing_attribute) {
                return self::updateAttribute((int) $existing_attribute->attribute_id, $params, $local_key, $element_index);
            }
        }

        $result = \wc_create_attribute(self::buildAttributeArgs($params, $element_index, true));
        if (\is_wp_error($result)) {
            self::throwWooCommerceError($result, $element_index);
        }

        self::persistLocalKeyForAttribute((int) $result, $local_key, $element_index);

        return (int) $result;
    }

    /** Updates one WooCommerce attribute and persists its local_key when provided. */
    private static function updateAttribute(int $attribute_id, array $params, ?string $local_key, int $element_index): int
    {
        $result = \wc_update_attribute($attribute_id, self::buildAttributeArgs($params, $element_index, false));
        if (\is_wp_error($result)) {
            self::throwWooCommerceError($result, $element_index);
        }

        if ($local_key !== null) {
            self::persistLocalKeyForAttribute((int) $result, $local_key, $element_index);
        }

        return (int) $result;
    }

    /**
     * Deletes unused terms under `$taxonomy` and busts its term-query cache
     * before the attribute itself goes away.
     *
     * `wc_delete_attribute()` only removes the attribute's row from
     * `wp_woocommerce_attribute_taxonomies` and unregisters the taxonomy for
     * this request — it deliberately leaves existing `wp_terms` /
     * `wp_term_taxonomy` rows in place, so products still assigned one of
     * these terms keep that association. Recreating the same attribute later
     * (same slug, e.g. after a connector re-sync) re-registers that taxonomy
     * name, and a term save keyed by the same `local_key` finds and reuses
     * the existing row instead of inserting a fresh one.
     *
     * For a term nobody uses (`count === 0`, e.g. one left behind by an
     * environment reset that never attached it to a product) that reuse is
     * pure downside: it just resurrects a stale row, and
     * `unregister_taxonomy()`/`register_taxonomy()` don't reliably bust
     * `get_terms()`'s term-query cache for it, so the reused term can stay
     * invisible to plain taxonomy listings indefinitely even though it's
     * still resolvable directly by id or local_key. Those are deleted
     * outright. Terms still attached to a product are left in place — their
     * historical association is exactly what WooCommerce's own
     * "don't delete terms on attribute delete" behavior protects — and are
     * instead recovered via cache invalidation: `clean_taxonomy_cache()`
     * bumps the shared `terms` cache group's `last_changed` key, which
     * `get_terms()` mixes into its query cache key, so any cached listing
     * for this taxonomy is invalidated immediately rather than left stale.
     *
     * Doing this while the taxonomy is still registered (i.e. before
     * `wc_delete_attribute()`) is required — `get_terms()` on an
     * unregistered taxonomy doesn't work.
     */
    private static function pruneOrphanedTerms(string $taxonomy): void
    {
        if (!\taxonomy_exists($taxonomy)) return;

        $terms = \get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (!\is_wp_error($terms)) {
            foreach ($terms as $term) {
                if ((int) $term->count === 0) {
                    \wp_delete_term((int) $term->term_id, $taxonomy);
                }
            }
        }

        if (\function_exists('clean_taxonomy_cache')) {
            \clean_taxonomy_cache($taxonomy);
        }
    }

    /** Deletes one WooCommerce global attribute by local_key. */
    public static function deleteValue(mixed $value, bool $ignore_missing, int $element_index): void
    {
        self::requireWooCommerce();

        $local_key = Input::localKey($value);
        if ($local_key === null) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Invalid delete value; expected a positive integer or non-empty string local_key", 400, 'invalid_param');
        }

        $attribute_ids = self::findExistingAttributeIdsByLocalKey($local_key);
        if ($attribute_ids === []) {
            if ($ignore_missing) return;

            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Attribute with local_key '$value' not found", 404, 'not_found');
        }

        foreach ($attribute_ids as $resolved_attribute_id) {
            $attribute = self::findAttributeById($resolved_attribute_id);
            $attribute_slug = (string) ($attribute->attribute_name ?? '');
            $taxonomy = $attribute_slug !== '' && \function_exists('wc_attribute_taxonomy_name')
                ? \wc_attribute_taxonomy_name($attribute_slug)
                : '';

            if ($taxonomy !== '') {
                self::pruneOrphanedTerms($taxonomy);
            }

            if (!\wc_delete_attribute($resolved_attribute_id)) {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Unable to delete attribute with local_key '$value'", 500, 'delete_failed');
            }

            self::deleteLocalKeyForAttribute($resolved_attribute_id);
        }
    }
}
