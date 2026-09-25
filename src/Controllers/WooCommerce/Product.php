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
        $body = Input::requireJsonList($request, 'WooCommerce Product');

        // Batch term recounts: each category/tag/brand assignment would otherwise
        // recount its terms immediately. Re-enabling triggers a single recount.
        \wp_defer_term_counting(true);
        try {
            foreach ($body as $i => $params) {
                $ids[] = WooCommerceProduct::save(Input::requireObjectElement($params, 'WooCommerce Product', $i), $i);
            }
        } finally {
            \wp_defer_term_counting(false);
        }

        return new \WP_REST_Response($ids, 200);
    }

    /** REST: deletes WooCommerce products by local_key; optional `ignore` query. */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $ignore_missing = onpage_should_ignore_missing($request);

        foreach (Input::requireJsonList($request, 'WooCommerce Product') as $i => $value) {
            $local_key = Input::localKey($value);
            if ($local_key !== null) {
                WooCommerceProduct::deleteByLocalKey($local_key, $ignore_missing);
                continue;
            }

            throw onpage_http_exception("WooCommerce Product :: Element $i :: Invalid delete value; expected a positive integer or non-empty string local_key", 400, 'invalid_param');
        }

        return new \WP_REST_Response(null, 200);
    }
}
