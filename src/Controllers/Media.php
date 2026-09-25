<?php



namespace OnPage\Controllers;



use OnPage\Services\Media as MediaService;



class Media
{
    /**
     * REST: lists media attachments, optionally filtered by `post_id` (parent) and `mime_type`.
     *
     * Sets `X-WP-Total`/`X-WP-TotalPages` headers so clients can detect the last page.
     *
     * @param \WP_REST_Request $request Request with optional `post_id`, `mime_type`, `per_page`, `page` query params.
     */
    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = MediaService::listFromRequest($request);

        $response = new \WP_REST_Response($result['items'], 200);
        onpage_set_pagination_headers($response, $result['total'], $result['per_page']);

        return $response;
    }

    /**
     * REST: uploads one or more media files from multipart/form-data.
     *
     * Accepts both single-file fields and array-style payloads such as `files[]`.
     *
     * @param \WP_REST_Request $request Request with file data.
     */
    public function upload(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(MediaService::uploadFromRequest($request), 200);
    }

    /**
     * REST: deletes one or more media attachments by numeric ID from the JSON body.
     *
     * @param \WP_REST_Request $request Request with a JSON array of attachment IDs.
     */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        MediaService::deleteFromRequest($request);

        return new \WP_REST_Response(null, 200);
    }

    /**
     * REST: imports and links remote media files from URLs to ACF fields.
     */
    public function link(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(MediaService::linkFromRequest($request), 200);
    }
}