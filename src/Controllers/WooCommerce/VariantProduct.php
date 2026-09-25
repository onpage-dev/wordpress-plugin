<?php



namespace OnPage\Controllers\WooCommerce;



use OnPage\Services\Input;
use OnPage\Services\WooCommerce\VariantProduct as WooCommerceVariantProduct;



class VariantProduct
{
    /** REST: lists WooCommerce product variations. */
    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(WooCommerceVariantProduct::list($request), 200);
    }

    /** REST: batch create or update WooCommerce product variations. */
    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        $ids = [];
        $body = Input::requireJsonList($request, 'WooCommerce Variant Product');

        try {
            foreach ($body as $i => $params) {
                $ids[] = WooCommerceVariantProduct::save(Input::requireObjectElement($params, 'WooCommerce Variant Product', $i), $i);
            }

            // Resync each touched variable parent once, instead of per variation.
            WooCommerceVariantProduct::flushDeferredParentSyncs();
        } catch (\Throwable $e) {
            // Best-effort sync of parents touched before the failure, preserving the original error.
            try {
                WooCommerceVariantProduct::flushDeferredParentSyncs();
            } catch (\Throwable) {
                // The original error is the meaningful one to surface.
            }

            throw $e;
        }

        return new \WP_REST_Response($ids, 200);
    }

    /** REST: deletes WooCommerce product variations by local_key; optional `ignore` query. */
    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        $ignore_missing = onpage_should_ignore_missing($request);

        foreach (Input::requireJsonList($request, 'WooCommerce Variant Product') as $i => $value) {
            $local_key = Input::localKey($value);
            if ($local_key !== null) {
                WooCommerceVariantProduct::deleteByLocalKey($local_key, $ignore_missing);
                continue;
            }

            throw onpage_http_exception("WooCommerce Variant Product :: Element $i :: Invalid delete value; expected a positive integer or non-empty string local_key", 400, 'invalid_param');
        }

        return new \WP_REST_Response(null, 200);
    }
}
