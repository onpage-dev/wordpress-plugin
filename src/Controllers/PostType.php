<?php



namespace OnPage\Controllers;



use OnPage\Services\PostType as PostTypeService;



class PostType
{
    /**
     * REST: lists all ACF post types.
     */
    public function list(): \WP_REST_Response
    {
        return new \WP_REST_Response(PostTypeService::listItems(), 200);
    }

    /**
     * REST: batch upsert ACF post types from the JSON body (create new or update existing by `post_type` key).
     *
     * @param \WP_REST_Request $request Request whose JSON body is a list of post type payloads.
     */
    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        $ids = [];

        // Rewrite rules are flushed once for the whole batch instead of per element.
        try {
            foreach ($request->get_json_params() as $params) {
                $ids[] = PostTypeService::saveFromParams($params);
            }
        } finally {
            \flush_rewrite_rules();
        }

        return new \WP_REST_Response($ids, 200);
    }

    /**
     * REST: deletes a list of post types by numeric ID or key from the JSON body; optional `ignore` query skips missing items checks.
     *
     * @param \WP_REST_Request $request Request with JSON body of IDs (int) or keys (string).
     */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $ignore_missing = shouldIgnoreMissing($request);

        // Rewrite rules are flushed once for the whole batch instead of per element.
        try {
            foreach ($request->get_json_params() as $value) {
                if (is_int($value)) {
                    PostTypeService::deleteById($value, $ignore_missing);
                } elseif (is_string($value)) {
                    PostTypeService::deleteByKey($value, $ignore_missing);
                }
            }
        } finally {
            \flush_rewrite_rules();
        }

        return new \WP_REST_Response(null, 200);
    }
}
