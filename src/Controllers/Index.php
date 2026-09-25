<?php



namespace OnPage\Controllers;



use OnPage\Services\Index as IndexService;



class Index
{
    /**
     * REST: removes every On Page® local key association (the "indexes") from posts and terms,
     * so the next import can re-establish them. Call when the source system regenerates its
     * local keys. Idempotent and safe to re-run.
     */
    public function delete(): \WP_REST_Response
    {
        return new \WP_REST_Response(IndexService::clearLocalKeys(), 200);
    }
}
