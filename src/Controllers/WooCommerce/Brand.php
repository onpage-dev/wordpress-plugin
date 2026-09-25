<?php



namespace OnPage\Controllers\WooCommerce;



use OnPage\Services\Acf;
use OnPage\Services\Input;
use OnPage\Services\WooCommerce\Brand as BrandService;



class Brand
{
    /** REST: lists WooCommerce brand terms. */
    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(BrandService::list($request), 200);
    }

    /** REST: batch create or update WooCommerce brand terms. */
    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        Acf::loadFieldTypeMap(['term']);

        $ids = [];
        foreach (Input::requireJsonList($request, 'WooCommerce Brand') as $i => $params) {
            $ids[] = BrandService::save(Input::requireObjectElement($params, 'WooCommerce Brand', $i), $i);
        }

        return new \WP_REST_Response($ids, 200);
    }

    /** REST: deletes WooCommerce brand terms by local_key; optional `ignore` query. */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $ignore_missing = onpage_should_ignore_missing($request);

        foreach (Input::requireJsonList($request, 'WooCommerce Brand') as $i => $value) {
            $local_key = Input::localKey($value);
            if ($local_key === null) {
                throw onpage_http_exception("WooCommerce Brand :: Element $i :: Invalid delete value; expected a positive integer or non-empty string local_key", 400, 'invalid_param');
            }

            BrandService::delete($local_key, $ignore_missing, $i);
        }

        return new \WP_REST_Response(null, 200);
    }
}
