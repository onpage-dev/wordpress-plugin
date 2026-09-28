<?php



namespace OnPage\Controllers\WooCommerce;



use OnPage\Services\Acf;
use OnPage\Services\Input;
use OnPage\Services\WooCommerce\Term as WooCommerceTerm;



class Category
{
    private const TAXONOMY = 'product_cat';
    private const ERROR_PREFIX = 'WooCommerce Category';



    /** REST: lists WooCommerce product categories. */
    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(
            WooCommerceTerm::list($request, self::TAXONOMY, self::ERROR_PREFIX),
            200
        );
    }

    /** REST: batch create or update WooCommerce product categories. */
    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        Acf::loadFieldTypeMap(['term']);

        $ids = [];
        foreach (Input::requireJsonList($request, self::ERROR_PREFIX) as $i => $params) {
            $params = Input::requireObjectElement($params, self::ERROR_PREFIX, $i);
            $ids[] = WooCommerceTerm::save($params, self::TAXONOMY, $i, self::ERROR_PREFIX);
        }

        return new \WP_REST_Response($ids, 200);
    }

    /** REST: deletes WooCommerce product categories by local_key; optional `ignore` query. */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $ignore_missing = onpage_should_ignore_missing($request);

        foreach (Input::requireJsonList($request, self::ERROR_PREFIX) as $i => $value) {
            $local_key = Input::localKey($value);
            if ($local_key === null) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $i :: Invalid delete value; expected a positive integer or non-empty string local_key", 400, 'invalid_param');
            }

            WooCommerceTerm::delete($local_key, self::TAXONOMY, $ignore_missing, $i, self::ERROR_PREFIX);
        }

        return new \WP_REST_Response(null, 200);
    }
}
