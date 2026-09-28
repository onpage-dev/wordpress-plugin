<?php



namespace OnPage\Controllers;



use OnPage\Services\FieldGroup as FieldGroupService;
use OnPage\Services\Input;



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
        foreach (Input::requireJsonList($request, 'FieldGroup') as $i => $params) {
            $ids[] = FieldGroupService::insertFromParams(Input::requireObjectElement($params, 'FieldGroup', $i));
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
        $ignore_missing = onpage_should_ignore_missing($request);

        foreach (Input::requireJsonList($request, 'FieldGroup') as $i => $value) {
            if (is_int($value)) {
                FieldGroupService::deleteById($value, $ignore_missing);
            } elseif (is_string($value)) {
                FieldGroupService::deleteByTitle($value, $ignore_missing);
            } else {
                throw onpage_http_exception("FieldGroup :: Element $i :: Invalid delete value; expected field group ID (int) or title (string)", 400, 'input_invalid');
            }
        }

        return new \WP_REST_Response(null, 200);
    }
}