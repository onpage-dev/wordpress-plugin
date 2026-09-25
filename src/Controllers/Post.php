<?php



namespace OnPage\Controllers;



use OnPage\Services\Acf;
use OnPage\Services\Input;
use OnPage\Services\Post as PostService;



class Post
{
    /**
     * REST: returns one post with ACF fields and taxonomy terms.
     *
     * @param \WP_REST_Request $request Route request; `id` is the post ID.
     */
    public function find(\WP_REST_Request $request): \WP_REST_Response
    {
        $keyfield = (string) ($request->get_query_params()['keyfield'] ?? 'id');
        if ($keyfield !== 'id' && $keyfield !== 'local_key') {
            throw onpage_http_exception("Invalid keyfield '$keyfield'", 400, 'invalid_keyfield');
        }

        $type = Input::requestString($request, 'type');

        $post = match ($keyfield) {
            'id' => PostService::requirePostById((int) $request['id']),
            'local_key' => PostService::requirePostByLocalKey(self::requireLocalKeyPathParam($request), $type),
        };

        return new \WP_REST_Response(PostService::buildFindResponse($post), 200);
    }

    /** Reads the `{id}` route segment as a required local_key (positive integer or non-empty string). */
    private static function requireLocalKeyPathParam(\WP_REST_Request $request): string
    {
        $local_key = Input::localKey($request['id']);
        if ($local_key === null) {
            throw onpage_http_exception("Invalid local_key '{$request['id']}'", 400, 'invalid_param');
        }

        return $local_key;
    }

    /**
     * REST: lists posts, optionally filtered by type, local_key, status or updated_after.
     *
     * Sets `X-WP-Total`/`X-WP-TotalPages` headers on the paginated listing (not on the
     * `id`/`title` lookup branches, which return every match with no pagination).
     */
    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        Acf::loadFieldTypeMap(['post']);

        $result = PostService::list($request);

        $response = new \WP_REST_Response($result['items'], 200);
        if ($result['total'] !== null && $result['per_page'] !== null) {
            onpage_set_pagination_headers($response, $result['total'], $result['per_page']);
        }

        return $response;
    }

    /**
     * REST: batch create or update posts from the JSON body.
     *
     * @param \WP_REST_Request $request Request whose JSON body is a list of post payloads.
     */
    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        Acf::loadFieldTypeMap(['post']);

        $ids = [];
        $body = Input::requireJsonList($request, 'Post');

        // Batch term recounts: each post's taxonomy assignment would otherwise
        // recount its terms immediately. Re-enabling triggers a single recount.
        \wp_defer_term_counting(true);
        try {
            foreach ($body as $i => $params) {
                $ids[] = PostService::save(Input::requireObjectElement($params, 'Post', $i), $i);
            }
        } finally {
            \wp_defer_term_counting(false);
        }

        return new \WP_REST_Response($ids, 200);
    }

    /**
     * REST: deletes posts by ID or all posts of a post type (string slug in JSON); optional `ignore` query.
     *
     * @param \WP_REST_Request $request Request with JSON body of post IDs (int) or type slug (string).
     */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $query_params = $request->get_query_params();
        $ignore_missing = onpage_should_ignore_missing($request);
        $keyfield = (string) ($query_params['keyfield'] ?? 'id_or_post_type');
        if (!in_array($keyfield, ['id_or_post_type', 'local_key'], true)) {
            throw onpage_http_exception("Invalid keyfield '$keyfield'", 400, 'invalid_keyfield');
        }

        $query_type = Input::stringOrNull($query_params['type'] ?? null);

        foreach (Input::requireJsonList($request, 'Post') as $i => $value) {
            if (is_array($value) && !array_is_list($value)) {
                $payload_local_key = Input::localKey($value['local_key'] ?? null);
                if ($payload_local_key !== null) {
                    $payload_type = Input::stringOrNull($value['type'] ?? null) ?? $query_type;

                    PostService::deleteByLocalKey($payload_local_key, $ignore_missing, $payload_type);
                    continue;
                }

                if ($keyfield !== 'local_key') {
                    if (is_int($value['id'] ?? null)) {
                        PostService::deleteById((int) $value['id'], $ignore_missing);
                        continue;
                    }

                    $payload_type = Input::stringOrNull($value['type'] ?? null);
                    if ($payload_type !== null) {
                        PostService::deleteByPostType($payload_type, $ignore_missing);
                        continue;
                    }
                }
            }

            if ($keyfield === 'local_key') {
                $local_key = Input::localKey($value);
                if ($local_key !== null) {
                    PostService::deleteByLocalKey($local_key, $ignore_missing, $query_type);
                    continue;
                }

                throw onpage_http_exception("Post :: Element $i :: Invalid delete value; expected a positive integer or non-empty string local_key", 400, 'input_invalid');
            }

            if (is_int($value)) {
                PostService::deleteById($value, $ignore_missing);
            } elseif (is_string($value)) {
                PostService::deleteByPostType($value, $ignore_missing);
            } else {
                throw onpage_http_exception("Post :: Element $i :: Invalid delete value; expected post ID (int) or post type slug (string)", 400, 'input_invalid');
            }
        }

        return new \WP_REST_Response(null, 200);
    }
}
