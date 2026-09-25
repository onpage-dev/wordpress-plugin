<?php



namespace OnPage\Services;



class PostRepository
{
    public const LOCAL_KEY_META = 'onpage_local_key';



    /**
     * All post IDs for a post type (trashed ones included), or null when the query does not
     * return an array.
     *
     * @return int[]|null
     */
    public static function listIdsByPostType(string $wp_post_type): array|null
    {
        $ids = \get_posts([
            'post_type' => $wp_post_type,
            // 'trash' next to 'any' keeps the bin in: 'any' alone excludes it.
            'post_status' => ['any', 'trash'],
            'fields' => 'ids',
            'numberposts' => -1,
            'suppress_filters' => false,
        ]);

        if (!is_array($ids)) return null;

        return array_map('intval', $ids);
    }

    /** Returns post IDs matching exact title within a post type. */
    public static function findIdsByTitle(string $wp_post_type, string $title): array
    {
        // WP_Query drops the post_title filter when the title is an empty string
        // (see wp-includes/class-wp-query.php: `'' !== $query_vars['title']`), so an
        // empty title would otherwise match every post of this type. An empty title
        // is never a meaningful duplicate, so report no matches.
        if ($title === '') {
            return [];
        }

        $results = \get_posts([
            'post_type' => $wp_post_type,
            'title' => $title,
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        return is_array($results) ? array_map('intval', $results) : [];
    }

    /**
     * True when the post already carries an On Page® local_key other than the given one.
     *
     * Such a post is a *different* On Page® element that happens to share a title, never an
     * accidental duplicate of the incoming one, so the duplicate-title guards must skip it.
     * What those guards exist to catch is the unkeyed post: one created by hand on the site,
     * or left behind by an import that died before writing the key — importing over it would
     * silently produce two posts with the same title, one of them invisible to later imports.
     */
    public static function carriesForeignLocalKey(int $post_id, string $local_key): bool
    {
        $existing_local_key = \get_post_meta($post_id, self::LOCAL_KEY_META, true);

        return is_string($existing_local_key)
            && $existing_local_key !== ''
            && $existing_local_key !== $local_key;
    }

    /**
     * Returns the post (as array) whose exact title would be a genuine duplicate of the
     * incoming element, or null. Posts holding a different local_key are not duplicates —
     * see carriesForeignLocalKey().
     */
    public static function findDuplicateByTitle(string $wp_post_type, string $title, string $local_key): array|null
    {
        foreach (self::findIdsByTitle($wp_post_type, $title) as $post_id) {
            if (!self::carriesForeignLocalKey($post_id, $local_key)) {
                return \get_post($post_id, \ARRAY_A);
            }
        }

        return null;
    }

    /**
     * Returns posts matching the `local_key` meta value.
     *
     * Ordered by ID: WPML translations share the local_key, and the default `post_date DESC`
     * has no tiebreaker, so translations created within the same second came back in a
     * non-deterministic order — and callers that take the first match would pick a random
     * language. Use Post::pickCanonicalPost() to choose a language-aware representative.
     *
     * Trashed posts are included by default: a trashed element still owns its local_key, and
     * leaving it out made a re-import create a second post with the same key. Post::update()
     * restores the trashed post before writing it, and a delete by local_key removes it too.
     * Reads pass `$include_trashed = false`: a trashed post is not live content, so
     * `GET /posts/{local_key}?keyfield=local_key` answers 404 for it, like `GET /posts?local_key=`.
     */
    public static function findPostsByLocalKey(string $local_key, string $wp_post_type = 'any', bool $include_trashed = true): array
    {
        $posts = \get_posts([
            'post_type' => $wp_post_type,
            'post_status' => $include_trashed ? ['any', 'trash'] : 'any',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [[
                'key' => self::LOCAL_KEY_META,
                'value' => $local_key,
                'compare' => '=',
            ]],
            'suppress_filters' => true,
        ]);

        return is_array($posts)
            ? array_values(array_filter($posts, fn($post): bool => $post instanceof \WP_Post))
            : [];
    }

    /**
     * Returns the lowest-ID post (as array) matching the `local_key` meta value, or null.
     *
     * Existence check only: with WPML every translation shares the key, so callers that
     * need a specific post must go through Post::pickCanonicalPost() instead.
     */
    public static function findByLocalKey(string $local_key, string $wp_post_type = 'any'): array|null
    {
        $posts = self::findPostsByLocalKey($local_key, $wp_post_type);
        if ($posts === []) return null;

        return \get_post($posts[0]->ID, \ARRAY_A);
    }
}
