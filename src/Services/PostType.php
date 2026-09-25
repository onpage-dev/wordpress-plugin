<?php



namespace OnPage\Services;



class PostType
{
    private const DEFAULT_MENU_ICON = 'dashicons-admin-post';
    private const DEFAULT_SUPPORTS = ['title', 'editor', 'thumbnail', 'revisions'];



    /** Finds an ACF post type by its key. */
    private static function findByKey(string $key): array|null
    {
        foreach (\acf_get_acf_post_types() as $post_type) {
            if ($post_type['key'] === $key) return $post_type;
        }

        return null;
    }

    /** Sanitized ACF key for a post type payload value. */
    private static function normalizeKey(string $post_type): string
    {
        return \sanitize_key($post_type);
    }

    /**
     * Returns the payload `post_type` when it is already a valid key, or throws.
     *
     * The ACF key is `sanitize_key(post_type)` while the post type is registered under the
     * value as sent: a slug that sanitizing changes (uppercase letters, spaces, accents) was
     * saved under two different names, and posts written with the original one no longer
     * matched the registered type. Rejecting it keeps the two identical.
     */
    private static function requireValidPostTypeSlug(mixed $value): string
    {
        $post_type = is_scalar($value) ? (string) $value : '';
        if ($post_type === '' || self::normalizeKey($post_type) !== $post_type) {
            throw onpage_http_exception(
                "PostType :: Parameter 'post_type' must be a non-empty key of lowercase letters, digits, '_' or '-'"
                    . (is_scalar($value) ? ", got '$post_type'" : ''),
                400,
                'invalid_param'
            );
        }

        return $post_type;
    }

    /** Normalized payload for `acf_update_post_type()`. */
    private static function buildPostTypeData(array $params): array
    {
        $post_type = (string) $params['post_type'];

        $data = [
            'key' => self::normalizeKey($post_type),
            'title' => $params['singular_label'],
            'labels' => [
                'singular_name' => $params['singular_label'],
                'name' => $params['plural_label'],
            ],
            'menu_order' => 0,
            'active' => true,
            'post_type' => $post_type,
            'hierarchical' => $params['hierarchical'] ?? false,
            'public' => true,
            'show_ui' => true,
            'show_in_rest' => true,
            'menu_icon' => $params['icon'] ?? self::DEFAULT_MENU_ICON,
            'supports' => $params['supports'] ?? self::DEFAULT_SUPPORTS,
            'taxonomies' => $params['taxonomies'] ?? [],
        ];

        $rewrite_slug = $params['rewrite_slug'] ?? null;
        if (is_string($rewrite_slug) && $rewrite_slug !== '') {
            $data['rewrite'] = [
                'permalink_rewrite' => 'custom_permalink',
                'slug' => $rewrite_slug,
                'with_front' => 1,
                'feeds' => 1,
                'pages' => 1,
            ];
        }

        return $data;
    }

    /** Returns all ACF post types. */
    public static function listItems(): array
    {
        return \acf_get_acf_post_types();
    }

    /**
     * Creates or updates one post type (upsert by `post_type` key) and returns its ACF ID.
     *
     * Rewrite rules are NOT flushed here: flushing is expensive and the controller
     * flushes once after the whole batch (mirrors the Taxonomy controller).
     */
    public static function saveFromParams(array $params, int $element_index = 0): int
    {
        $post_type = self::requireValidPostTypeSlug($params['post_type'] ?? null);
        Input::requireStringParam($params, 'singular_label', 'PostType', $element_index);
        Input::requireStringParam($params, 'plural_label', 'PostType', $element_index);
        $key = self::normalizeKey($post_type);

        $data = self::buildPostTypeData($params);

        $existing = self::findByKey($key);
        if ($existing !== null) {
            $data['ID'] = $existing['ID'];
        }

        $result = \acf_update_post_type($data);
        if (!$result) {
            throw onpage_http_exception("PostType :: Failed to save PostType '$post_type'", 500, 'acf_error');
        }

        return (int) $result['ID'];
    }

    /** Deletes a post type by numeric ACF ID. */
    public static function deleteById(int $id, bool $ignore_missing): void
    {
        if (!\acf_get_post_type($id)) {
            if ($ignore_missing) return;

            throw onpage_http_exception("PostType :: ID $id not found", 404, 'not_found');
        }

        if (!\acf_delete_post_type($id)) {
            throw onpage_http_exception("PostType :: Failed to delete ID $id", 500, 'delete_failed');
        }
    }

    /** Deletes a post type by string key. */
    public static function deleteByKey(string $value, bool $ignore_missing): void
    {
        $key = self::normalizeKey($value);
        $post_type_data = self::findByKey($key);

        if ($post_type_data === null) {
            if ($ignore_missing) return;

            throw onpage_http_exception("PostType :: Key '$key' not found", 404, 'not_found');
        }

        if (!\acf_delete_post_type($post_type_data['ID'])) {
            throw onpage_http_exception("PostType :: Failed to delete PostType with key '$key'", 500, 'delete_failed');
        }
    }
}
