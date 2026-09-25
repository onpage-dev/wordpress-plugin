<?php



namespace OnPage\Controllers\WooCommerce;



use OnPage\Services\Input;
use OnPage\Services\WooCommerce\Attribute as WooCommerceAttribute;



class Attribute
{
    /** REST: lists WooCommerce global product attributes. */
    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(WooCommerceAttribute::list($request), 200);
    }

    /** REST: batch create or update WooCommerce global product attributes. */
    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        $ids = [];
        foreach (Input::requireJsonList($request, 'WooCommerce Attribute') as $i => $params) {
            $ids[] = WooCommerceAttribute::save(Input::requireObjectElement($params, 'WooCommerce Attribute', $i), $i);
        }

        return new \WP_REST_Response($ids, 200);
    }

    /** REST: deletes WooCommerce global product attributes by local_key; optional `ignore` query. */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $ignore_missing = onpage_should_ignore_missing($request);

        foreach (Input::requireJsonList($request, 'WooCommerce Attribute') as $i => $value) {
            WooCommerceAttribute::deleteValue($value, $ignore_missing, $i);
        }

        return new \WP_REST_Response(null, 200);
    }
}
