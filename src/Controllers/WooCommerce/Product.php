<?php



namespace OnPage\Controllers\WooCommerce;



use OnPage\Services\Acf;
use OnPage\Services\Input;
use OnPage\Services\WooCommerce\Product as WooCommerceProduct;



class Product
{
    /** REST: lists WooCommerce products, optionally filtered by `id` or `local_key`. */
    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        Acf::loadFieldTypeMap(['post']);

        return new \WP_REST_Response(WooCommerceProduct::list($request), 200);
    }

    /** REST: batch create or update WooCommerce products using the ProductDTO payload shape. */
    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        Acf::loadFieldTypeMap(['post']);

        $ids = [];

        // Batch term recounts: each category/tag/brand assignment would otherwise
        // recount its terms immediately. Re-enabling triggers a single recount.
        \wp_defer_term_counting(true);
        try {
            foreach ($request->get_json_params() as $i => $params) {
                if (!is_array($params)) {
                    throw httpException("WooCommerce Product :: Element $i :: Product payload must be an object", 400, 'invalid_param');
                }

                $ids[] = WooCommerceProduct::save($params, $i);
            }
        } finally {
            \wp_defer_term_counting(false);
        }

        return new \WP_REST_Response($ids, 200);
    }

    /** REST: deletes WooCommerce products by local_key; optional `ignore` query. */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $ignore_missing = shouldIgnoreMissing($request);

        foreach ($request->get_json_params() as $i => $value) {
            $local_key = Input::localKey($value);
            if ($local_key !== null) {
                WooCommerceProduct::deleteByLocalKey($local_key, $ignore_missing);
                continue;
            }

            throw httpException("WooCommerce Product :: Element $i :: Invalid delete value; expected a positive integer or non-empty string local_key", 400, 'invalid_param');
        }

        return new \WP_REST_Response(null, 200);
    }
}
