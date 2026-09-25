<?php



namespace OnPage\Controllers;



use OnPage\Services\Taxonomy as TaxonomyService;



class Taxonomy
{
    /**
     * REST: lists ACF taxonomies, merging stored label translations into each item.
     */
    public function list(): \WP_REST_Response
    {
        return new \WP_REST_Response(TaxonomyService::listItems(), 200);
    }

    /**
     * REST: creates or updates a list of taxonomies (upsert by `key`) from the JSON body and
     * syncs label translation metadata.
     *
     * @param \WP_REST_Request $request Request whose JSON body is a list of taxonomy payloads.
     */
    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        $ids = [];
        foreach ($request->get_json_params() as $i => $params) {
            $ids[] = TaxonomyService::saveFromParams($params, $i);
        }

        \flush_rewrite_rules();

        return new \WP_REST_Response($ids, 200);
    }

    /**
     * REST: deletes taxonomies by ACF ID or slug; clears terms and translation data first.
     *
     * @param \WP_REST_Request $request Request with JSON body of IDs or slugs; optional `ignore` query.
     */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $ignore_missing = shouldIgnoreMissing($request);

        foreach ($request->get_json_params() as $i => $value) {
            if (is_int($value)) {
                TaxonomyService::deleteById($value, $ignore_missing);
            } elseif (is_string($value)) {
                TaxonomyService::deleteBySlug($value, $ignore_missing);
            }
        }

        \flush_rewrite_rules();

        return new \WP_REST_Response(null, 200);
    }
}
