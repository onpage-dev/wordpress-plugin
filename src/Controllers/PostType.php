<?php



namespace OnPage\Controllers;



use OnPage\Services\Input;
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
        $body = Input::requireJsonList($request, 'PostType');

        // Rewrite rules are flushed once for the whole batch instead of per element.
        try {
            foreach ($body as $i => $params) {
                $ids[] = PostTypeService::saveFromParams(Input::requireObjectElement($params, 'PostType', $i), $i);
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
        $ignore_missing = onpage_should_ignore_missing($request);
        $body = Input::requireJsonList($request, 'PostType');

        // Rewrite rules are flushed once for the whole batch instead of per element.
        try {
            foreach ($body as $i => $value) {
                if (is_int($value)) {
                    PostTypeService::deleteById($value, $ignore_missing);
                } elseif (is_string($value)) {
                    PostTypeService::deleteByKey($value, $ignore_missing);
                } else {
                    throw onpage_http_exception("PostType :: Element $i :: Invalid delete value; expected post type ID (int) or key (string)", 400, 'input_invalid');
                }
            }
        } finally {
            \flush_rewrite_rules();
        }

        return new \WP_REST_Response(null, 200);
    }
}
