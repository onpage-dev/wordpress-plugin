<?php



namespace OnPage\Controllers;



use OnPage\Services\FieldGroup as FieldGroupService;



class FieldGroup
{
    /**
     * REST: lists all of ACF field groups.
     */
    public function list(): \WP_REST_Response
    {
        return new \WP_REST_Response(FieldGroupService::listItems(), 200);
    }

    /**
     * REST: creates a list of ACF field groups with its fields from the JSON body.
     *
     * @param \WP_REST_Request $request Request whose JSON body is a list of field group payloads.
     */
    public function insert(\WP_REST_Request $request): \WP_REST_Response
    {
        $ids = [];
        foreach ($request->get_json_params() as $params) {
            $ids[] = FieldGroupService::insertFromParams($params);
        }

        return new \WP_REST_Response($ids, 200);
    }

    /**
     * REST: deletes a list of ACF field groups by ID or title; optional `ignore` query skips missing items checks.
     *
     * @param \WP_REST_Request $request Request with JSON body of IDs or titles.
     */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $ignore_missing = shouldIgnoreMissing($request);

        foreach ($request->get_json_params() as $value) {
            if (is_int($value)) {
                FieldGroupService::deleteById($value, $ignore_missing);
            } elseif (is_string($value)) {
                FieldGroupService::deleteByTitle($value, $ignore_missing);
            }
        }

        return new \WP_REST_Response(null, 200);
    }
}