<?php



namespace OnPage\Controllers\WooCommerce;



use OnPage\Services\Acf;
use OnPage\Services\Input;
use OnPage\Services\WooCommerce\Term as WooCommerceTerm;



class Tag
{
    private const TAXONOMY = 'product_tag';
    private const ERROR_PREFIX = 'WooCommerce Tag';



    /** REST: lists WooCommerce product tags. */
    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(
            WooCommerceTerm::list($request, self::TAXONOMY, self::ERROR_PREFIX),
            200
        );
    }

    /** REST: batch create or update WooCommerce product tags. */
    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        Acf::loadFieldTypeMap(['term']);

        $ids = [];
        foreach ($request->get_json_params() as $i => $params) {
            if (!is_array($params) || array_is_list($params)) {
                throw httpException(self::ERROR_PREFIX . " :: Element $i :: Tag payload must be an object", 400, 'invalid_param');
            }

            $ids[] = WooCommerceTerm::save($params, self::TAXONOMY, $i, self::ERROR_PREFIX);
        }

        return new \WP_REST_Response($ids, 200);
    }

    /** REST: deletes WooCommerce product tags by local_key; optional `ignore` query. */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $ignore_missing = shouldIgnoreMissing($request);

        foreach ($request->get_json_params() as $i => $value) {
            $local_key = Input::localKey($value);
            if ($local_key === null) {
                throw httpException(self::ERROR_PREFIX . " :: Element $i :: Invalid delete value; expected a positive integer or non-empty string local_key", 400, 'invalid_param');
            }

            WooCommerceTerm::delete($local_key, self::TAXONOMY, $ignore_missing, $i, self::ERROR_PREFIX);
        }

        return new \WP_REST_Response(null, 200);
    }
}
