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
            // Internal key set only by the WooCommerce term services: never taken from the client.
            unset($params['__parent_local_key']);

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
     * REST: deletes terms by ID or by local_key from the JSON body. An ID deletes that term
     * only; a local_key deletes every term holding it, so the whole WPML translation group.
     *
     * Elements: a term ID, `{"id": …}`, or `{"local_key": …, "taxonomy": …}`. With
     * `?keyfield=local_key` plain values are read as local_keys. The taxonomy is optional
     * (`?taxonomy=`, or `taxonomy` in an object element): when omitted an ID's taxonomy is
     * resolved from the term itself, and a local_key must resolve to a single taxonomy.
     * Optional `ignore` query skips missing terms.
     *
     * IDs are validated strictly (a positive int or a digit-only string): a cast would turn
     * `"12abc"` into 12 and any array into 1, deleting a term nobody asked for.
     *
     * @param \WP_REST_Request $request Route request; JSON body is a list of term IDs or local_key objects.
     */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $keyfield = Input::requestString($request, 'keyfield') ?? 'id';
        if (!in_array($keyfield, ['id', 'local_key'], true)) {
            throw onpage_http_exception("Invalid keyfield '$keyfield'", 400, 'invalid_keyfield');
        }

        $taxonomy_param = Input::requestString($request, 'taxonomy');
        $taxonomy = $taxonomy_param !== null ? TermService::requireTaxonomySlug($taxonomy_param) : null;
        $ignore_missing = onpage_should_ignore_missing($request);

        foreach (Input::requireJsonList($request, 'Term') as $i => $value) {
            if (is_array($value) && !array_is_list($value)) {
                $this->deleteElement($value, $i, $keyfield, $taxonomy, $ignore_missing);
                continue;
            }

            if ($keyfield === 'local_key') {
                // `true` would otherwise become the key "1".
                $local_key = is_bool($value) ? null : Input::localKey($value);
                if ($local_key === null) {
                    throw onpage_http_exception("Term :: Element $i :: Invalid delete value; expected a positive integer or non-empty string local_key", 400, 'input_invalid');
                }

                TermService::deleteByLocalKey($local_key, $taxonomy, $ignore_missing, $i);
                continue;
            }

            $term_id = Input::strictPositiveInt($value);
            if ($term_id === null) {
                throw onpage_http_exception("Term :: Element $i :: Invalid delete value; expected a term ID (positive integer) or a local_key object", 400, 'input_invalid');
            }

            $this->deleteOne($term_id, $taxonomy, $ignore_missing, $i);
        }

        return new \WP_REST_Response(null, 200);
    }

    /**
     * Deletes one object element: `{"local_key": …}` (the whole translation group) or
     * `{"id": …}` (that term only), optionally scoped by its own `taxonomy`.
     *
     * A present but unusable identifier is an error, never skipped: an object must not fall
     * through to another identifier because one was malformed.
     */
    private function deleteElement(array $value, int $i, string $keyfield, ?string $taxonomy, bool $ignore_missing): void
    {
        if (($value['taxonomy'] ?? null) !== null) {
            $element_taxonomy = Input::stringOrNull($value['taxonomy']);
            if ($element_taxonomy === null) {
                throw onpage_http_exception("Term :: Element $i :: Parameter 'taxonomy' must be a taxonomy slug or numeric ACF ID", 400, 'input_invalid');
            }

            $taxonomy = TermService::requireTaxonomySlug($element_taxonomy);
        }

        if (($value['local_key'] ?? null) !== null) {
            $local_key = is_bool($value['local_key']) ? null : Input::localKey($value['local_key']);
            if ($local_key === null) {
                throw onpage_http_exception("Term :: Element $i :: Parameter 'local_key' must be a positive integer or a non-empty string", 400, 'input_invalid');
            }

            TermService::deleteByLocalKey($local_key, $taxonomy, $ignore_missing, $i);
            return;
        }

        if ($keyfield !== 'local_key' && ($value['id'] ?? null) !== null) {
            $term_id = Input::strictPositiveInt($value['id']);
            if ($term_id === null) {
                throw onpage_http_exception("Term :: Element $i :: Parameter 'id' must be a term ID (positive integer)", 400, 'input_invalid');
            }

            $this->deleteOne($term_id, $taxonomy, $ignore_missing, $i);
            return;
        }

        $expected = $keyfield === 'local_key' ? "a 'local_key'" : "an 'id' or a 'local_key'";
        throw onpage_http_exception("Term :: Element $i :: Invalid delete value; expected an object with $expected", 400, 'input_invalid');
    }

    /** Deletes a single term by ID, leaving its WPML translations in place. */
    private function deleteOne(int $term_id, ?string $taxonomy, bool $ignore_missing, int $i): void
    {
        // Checked here so a missing term (or one outside the taxonomy) answers 404 instead of
        // reaching `wp_delete_term()`, whose `false` for "no such term" reads as a failure.
        $term = \get_term($term_id, $taxonomy ?? '');
        if (!$term instanceof \WP_Term) {
            if ($ignore_missing) return;

            throw onpage_http_exception("Term :: Element $i :: Term $term_id not found", 404, 'not_found');
        }

        TermService::deleteById($term_id, $taxonomy ?? $term->taxonomy);
    }
}
