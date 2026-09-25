<?php



namespace OnPage\Controllers;



use OnPage\Services\Acf;
use OnPage\Services\Input;
use OnPage\Services\Term as TermService;



class Term
{
    /**
     * REST: lists or searches terms with the taxonomy given in the `taxonomy` query string.
     *
     * Mirrors GET /taxonomies/{id}/terms but takes the taxonomy as a query param, like
     * GET /posts?type=. `taxonomy` is optional: when omitted the listing/`name` search span
     * all taxonomies. Supports the same `name` exact-search (string or WPML language map), and a
     * direct-children filter via `parent_id` (WP term ID) or `parent_lk` (parent local_key;
     * requires `taxonomy`).
     *
     * @param \WP_REST_Request $request Route request; optional `taxonomy`, `name`, `parent_id`/`parent_lk`.
     */
    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        $taxonomy_param = Input::requestString($request, 'taxonomy');
        $taxonomy = $taxonomy_param !== null ? TermService::requireTaxonomySlug($taxonomy_param) : null;

        return $this->respondTerms($request, $taxonomy);
    }

    /**
     * Lists terms for a resolved taxonomy (or all taxonomies when null), or performs an
     * exact-name search (one object per WPML translation group) when `name` is present.
     */
    private function respondTerms(\WP_REST_Request $request, ?string $taxonomy = null): \WP_REST_Response
    {
        $name_queries = Input::langValueQueries($request->get_param('name'));
        if ($name_queries !== []) {
            return new \WP_REST_Response(TermService::searchByName($name_queries, $taxonomy), 200);
        }

        $parent_ids = TermService::resolveParentFilterIds(
            Input::positiveInt($request->get_param('parent_id')),
            Input::localKey($request->get_param('parent_lk')),
            $taxonomy,
            'Term'
        );

        $response = array_map([TermService::class, 'buildTermResponse'], TermService::listByTaxonomy($taxonomy, $parent_ids));

        return new \WP_REST_Response($response, 200);
    }

    /**
     * REST: creates or updates terms (multilingual-aware) in batch; each payload element
     * carries its own `taxonomy` (slug or numeric ACF taxonomy id) instead of a URL path.
     *
     * @param \WP_REST_Request $request Route request; JSON body is a list of term payloads.
     */
    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        Acf::loadFieldTypeMap(['term']);

        $ids = [];
        foreach ($request->get_json_params() as $i => $params) {
            if (!is_array($params)) {
                throw httpException("Term :: Element $i :: Invalid payload; expected an object", 400, 'invalid_param');
            }

            $taxonomy_param = Input::stringOrNull($params['taxonomy'] ?? null);
            if ($taxonomy_param === null) {
                throw httpException("Term :: Element $i :: Parameter 'taxonomy' is required", 400, 'invalid_param');
            }

            $taxonomy = TermService::requireTaxonomySlug($taxonomy_param);
            $ids[] = TermService::save($params, $taxonomy, $i);
        }

        return new \WP_REST_Response($ids, 200);
    }

    /**
     * REST: deletes terms by ID from the JSON body. The taxonomy is optional (`?taxonomy=`):
     * when omitted each term's taxonomy is resolved from the term itself, so a single request
     * can delete terms of different taxonomies.
     *
     * @param \WP_REST_Request $request Route request; JSON body is a list of term IDs.
     */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $taxonomy_param = Input::requestString($request, 'taxonomy');
        $taxonomy = $taxonomy_param !== null ? TermService::requireTaxonomySlug($taxonomy_param) : null;

        foreach ($request->get_json_params() as $term_id) {
            TermService::deleteById((int) $term_id, $taxonomy);
        }

        return new \WP_REST_Response(null, 200);
    }
}
