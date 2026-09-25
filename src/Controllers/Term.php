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
        foreach (Input::requireJsonList($request, 'Term') as $i => $params) {
            $params = Input::requireObjectElement($params, 'Term', $i);

            $taxonomy_param = Input::stringOrNull($params['taxonomy'] ?? null);
            if ($taxonomy_param === null) {
                throw onpage_http_exception("Term :: Element $i :: Parameter 'taxonomy' is required", 400, 'invalid_param');
            }

            $taxonomy = TermService::requireTaxonomySlug($taxonomy_param);
            $ids[] = TermService::save($params, $taxonomy, $i);
        }

        return new \WP_REST_Response($ids, 200);
    }

    /**
     * REST: deletes terms by ID from the JSON body. The taxonomy is optional (`?taxonomy=`):
     * when omitted each term's taxonomy is resolved from the term itself, so a single request
     * can delete terms of different taxonomies. Optional `ignore` query skips missing terms.
     *
     * IDs are validated strictly (a positive int or a digit-only string): a cast would turn
     * `"12abc"` into 12 and any array into 1, deleting a term nobody asked for.
     *
     * @param \WP_REST_Request $request Route request; JSON body is a list of term IDs.
     */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $taxonomy_param = Input::requestString($request, 'taxonomy');
        $taxonomy = $taxonomy_param !== null ? TermService::requireTaxonomySlug($taxonomy_param) : null;
        $ignore_missing = onpage_should_ignore_missing($request);

        foreach (Input::requireJsonList($request, 'Term') as $i => $value) {
            $term_id = Input::strictPositiveInt($value);
            if ($term_id === null) {
                throw onpage_http_exception("Term :: Element $i :: Invalid delete value; expected a term ID (positive integer)", 400, 'input_invalid');
            }

            // Checked here so a missing term (or one outside `?taxonomy=`) answers 404 instead of
            // reaching `wp_delete_term()`, whose `false` for "no such term" reads as a failure.
            $term = \get_term($term_id, $taxonomy ?? '');
            if (!$term instanceof \WP_Term) {
                if ($ignore_missing) continue;

                throw onpage_http_exception("Term :: Element $i :: Term $term_id not found", 404, 'not_found');
            }

            TermService::deleteById($term_id, $taxonomy ?? $term->taxonomy);
        }

        return new \WP_REST_Response(null, 200);
    }
}
