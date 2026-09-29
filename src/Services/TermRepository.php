<?php



namespace OnPage\Services;



class TermRepository
{
    private const LOCAL_KEY_META = PostRepository::LOCAL_KEY_META;



    /**
     * term_taxonomy_id for a term in a taxonomy.
     *
     * Queries the DB directly instead of get_term(): under WPML get_term() is filtered to the
     * current language and would return the current-language translation's id for a term that
     * lives in another language. The term_id ↔ term_taxonomy_id mapping is immutable and
     * language-neutral, so a direct lookup is the correct source.
     */
    public static function getTermTaxonomyId(int $term_id, string $taxonomy): int
    {
        global $wpdb;

        $term_taxonomy_id = $wpdb->get_var($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
            $term_id,
            $taxonomy
        ));

        return $term_taxonomy_id ? (int) $term_taxonomy_id : 0;
    }

    /** Resolves term_id from a term_taxonomy_id, bypassing WPML term filters (see getTermTaxonomyId). */
    public static function getTermIdByTaxonomyId(int $term_taxonomy_id, string $taxonomy): int
    {
        global $wpdb;

        $term_id = $wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d AND taxonomy = %s",
            $term_taxonomy_id,
            $taxonomy
        ));

        return $term_id ? (int) $term_id : 0;
    }

    /** Finds term IDs by local_key within a taxonomy, bypassing WPML term filters. */
    public static function findTermIdsByLocalKey(string $local_key, string $taxonomy_slug): array
    {
        global $wpdb;

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT tm.term_id
            FROM {$wpdb->termmeta} tm
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
            WHERE tm.meta_key = %s
            AND tm.meta_value = %s
            AND tt.taxonomy = %s
            ORDER BY tm.term_id ASC",
            self::LOCAL_KEY_META,
            $local_key,
            $taxonomy_slug
        ));

        return is_array($results) ? array_values(array_map('intval', $results)) : [];
    }

    /**
     * Finds terms by local_key in every taxonomy, bypassing WPML term filters.
     *
     * @return list<array{term_id: int, taxonomy: string}>
     */
    public static function findTermsByLocalKeyInAnyTaxonomy(string $local_key): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT tm.term_id, tt.taxonomy
            FROM {$wpdb->termmeta} tm
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
            WHERE tm.meta_key = %s
            AND tm.meta_value = %s
            ORDER BY tm.term_id ASC",
            self::LOCAL_KEY_META,
            $local_key
        ), ARRAY_A);

        return is_array($rows)
            ? array_map(fn(array $row): array => ['term_id' => (int) $row['term_id'], 'taxonomy' => (string) $row['taxonomy']], $rows)
            : [];
    }

    /** Finds term IDs by slug within a taxonomy, bypassing WPML term filters. */
    public static function findTermIdsBySlug(string $slug, string $taxonomy_slug): array
    {
        global $wpdb;

        $slug = \sanitize_title($slug);
        if ($slug === '') {
            return [];
        }

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT t.term_id
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
            WHERE t.slug = %s
            AND tt.taxonomy = %s
            ORDER BY t.term_id ASC",
            $slug,
            $taxonomy_slug
        ));

        return is_array($results) ? array_values(array_map('intval', $results)) : [];
    }
}
