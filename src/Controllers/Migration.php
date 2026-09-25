<?php



namespace OnPage\Controllers;



use OnPage\Services\Migration as MigrationService;



class Migration
{
    /**
     * REST: runs the one-off data migrations of the plugin. Idempotent and safe to re-run.
     *
     * - migrates the On Page® local key meta from the legacy `local_key` key to `onpage_local_key`
     *   on posts and terms;
     * - backfills the On Page® storage segment on media imported before it was indexed.
     */
    public function run(): \WP_REST_Response
    {
        $result = MigrationService::renameLocalKeyMeta();
        $result['media_tokens'] = MigrationService::backfillMediaTokens();

        return new \WP_REST_Response($result, 200);
    }
}
