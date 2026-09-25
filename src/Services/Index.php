<?php



namespace OnPage\Services;



class Index
{
    /** Legacy meta key previously used for the On Page® local key (implicitly written via ACF). */
    private const LEGACY_META = 'local_key';

    /** ACF internal reference meta for the legacy field. */
    private const LEGACY_ACF_REFERENCE_META = '_local_key';



    /**
     * Removes every On Page® local key association from posts and terms.
     *
     * Call this when the source system regenerates its local keys, so the next import
     * can re-establish them from scratch. Terms become "unowned" and are re-adopted by
     * structural identity (name/slug + parent) on the next upsert; posts must be
     * re-imported afterwards (a same-titled product with no local_key is rejected with
     * `409 duplicate_title`, so re-run the product import to re-key them).
     *
     * Wipes the canonical `onpage_local_key` plus the legacy `local_key` / `_local_key`
     * copies, on both post meta and term meta. Idempotent — re-running matches no rows.
     */
    public static function clearLocalKeys(): array
    {
        global $wpdb;

        $meta_keys = [
            PostRepository::LOCAL_KEY_META,
            self::LEGACY_META,
            self::LEGACY_ACF_REFERENCE_META,
        ];
        $placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));

        $posts_removed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)",
            ...$meta_keys
        ));

        if ($posts_removed === false) {
            throw httpException("Indexes :: Failed to remove post meta", 500, 'delete_failed');
        }

        $terms_removed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->termmeta} WHERE meta_key IN ($placeholders)",
            ...$meta_keys
        ));

        if ($terms_removed === false) {
            throw httpException("Indexes :: Failed to remove term meta", 500, 'delete_failed');
        }

        // Raw SQL bypasses WP's meta caches; flush so subsequent reads don't see stale keys.
        \wp_cache_flush();

        return [
            'posts_removed' => (int) $posts_removed,
            'terms_removed' => (int) $terms_removed,
        ];
    }
}
