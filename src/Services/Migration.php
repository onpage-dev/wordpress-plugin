<?php



namespace OnPage\Services;



class Migration
{
    /** Legacy meta key previously used for the On Page® local key (implicitly written via ACF). */
    private const LEGACY_META = 'local_key';

    /** ACF internal reference meta for the legacy field, orphaned now that the field is gone. */
    private const LEGACY_ACF_REFERENCE_META = '_local_key';

    /** Attachments scanned per round while backfilling storage segments. */
    private const MEDIA_TOKEN_BATCH_SIZE = 500;



    /**
     * Renames the On Page® local key post meta from the legacy `local_key` key to `onpage_local_key`.
     *
     * Only posts need this: terms already store the local key under `onpage_local_key`.
     * Idempotent — a second run matches no rows and is a no-op.
     */
    public static function renameLocalKeyMeta(): array
    {
        global $wpdb;

        // Capture the affected rows before renaming so they can be returned for debugging.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::LEGACY_META
        ), ARRAY_A);

        if ($rows === null) {
            throw httpException("Migration :: Failed to read post meta", 500, 'migration_failed');
        }

        $renamed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
            PostRepository::LOCAL_KEY_META,
            self::LEGACY_META
        ));

        if ($renamed === false) {
            throw httpException("Migration :: Failed to rename post meta key", 500, 'migration_failed');
        }

        // Drop the orphaned ACF reference meta (no longer read by anything).
        $acf_reference_removed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::LEGACY_ACF_REFERENCE_META
        ));

        if ($acf_reference_removed === false) {
            throw httpException("Migration :: Failed to remove ACF reference meta", 500, 'migration_failed');
        }

        // Terms already store the local key under the target key; the legacy `local_key` and its
        // ACF reference in termmeta are redundant copies left by the removed implicit ACF field.
        $term_legacy_removed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->termmeta} WHERE meta_key IN (%s, %s)",
            self::LEGACY_META,
            self::LEGACY_ACF_REFERENCE_META
        ));

        if ($term_legacy_removed === false) {
            throw httpException("Migration :: Failed to remove legacy term meta", 500, 'migration_failed');
        }

        // Raw SQL bypasses WP's meta caches; flush so subsequent reads see the new key.
        \wp_cache_flush();

        return [
            'renamed' => (int) $renamed,
            'acf_reference_removed' => (int) $acf_reference_removed,
            'term_legacy_removed' => (int) $term_legacy_removed,
            'items' => array_map(static fn(array $row): array => [
                'post_id' => (int) $row['post_id'],
                'local_key' => $row['meta_value'],
            ], $rows),
        ];
    }

    /**
     * Writes the On Page® storage segment on the attachments that were imported before it was indexed.
     *
     * Those attachments carry their source URL (`_onpage_source_url`) but no segment, so nothing can
     * recognize them as "the file already here" when the same file arrives through `POST /media`.
     * The segment is derived from the URL itself, which is where it comes from in the first place.
     *
     * Rows are walked by `meta_id` and attachments that already have a segment are joined out, so
     * a URL that carries none (a media imported from somewhere other than On Page®) is simply skipped
     * without holding up the scan. Idempotent — a second run finds nothing left to write.
     */
    public static function backfillMediaTokens(): array
    {
        global $wpdb;

        $last_meta_id = 0;
        $scanned = 0;
        $written = 0;

        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT source.meta_id, source.post_id, source.meta_value
                 FROM {$wpdb->postmeta} source
                 LEFT JOIN {$wpdb->postmeta} token
                        ON token.post_id = source.post_id AND token.meta_key = %s
                 WHERE source.meta_key = %s
                   AND source.meta_id > %d
                   AND token.meta_id IS NULL
                 ORDER BY source.meta_id
                 LIMIT %d",
                RemoteMedia::TOKEN_META,
                RemoteMedia::SOURCE_URL_META,
                $last_meta_id,
                self::MEDIA_TOKEN_BATCH_SIZE
            ), ARRAY_A);

            if ($rows === null) {
                throw httpException("Migration :: Failed to read media source URLs", 500, 'migration_failed');
            }

            if ($rows === []) {
                break;
            }

            $scanned += count($rows);
            $last_meta_id = (int) $rows[array_key_last($rows)]['meta_id'];
            $written += self::writeMediaTokens($rows);
        }

        // Raw SQL bypasses WP's meta caches; flush so subsequent reads see the new segments.
        \wp_cache_flush();

        return [
            'scanned' => $scanned,
            'written' => $written,
        ];
    }

    /**
     * Inserts the storage segments of one scanned batch, in a single statement.
     *
     * @param array<int, array{meta_id:string,post_id:string,meta_value:string}> $rows
     * @return int Number of attachments that got a segment.
     */
    private static function writeMediaTokens(array $rows): int
    {
        global $wpdb;

        $values = [];
        $seen_post_ids = [];

        foreach ($rows as $row) {
            $post_id = (int) $row['post_id'];
            if (isset($seen_post_ids[$post_id])) {
                continue;
            }

            $token = RemoteMedia::tokenFromUrl((string) $row['meta_value']);
            if ($token === null) {
                continue;
            }

            $seen_post_ids[$post_id] = true;
            $values[] = $post_id;
            $values[] = RemoteMedia::TOKEN_META;
            $values[] = $token;
        }

        if ($values === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, intdiv(count($values), 3), '(%d, %s, %s)'));

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES $placeholders",
            ...$values
        ));

        if ($inserted === false) {
            throw httpException("Migration :: Failed to write media storage tokens", 500, 'migration_failed');
        }

        return intdiv(count($values), 3);
    }
}
