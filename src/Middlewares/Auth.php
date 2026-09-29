<?php



namespace OnPage\Middlewares;



use OnPage\Services\Acf;
use OnPage\Services\Auth as AuthService;



class Auth
{
    /**
     * Permission callback: validates the configured Bearer token for this REST request, then
     * that the active ACF is supported. The token comes first, so a caller without it
     * never learns which ACF version the site runs.
     */
    public function handle(\WP_REST_Request $request): bool
    {
        (new AuthService($request))->check();
        Acf::requireSupportedVersion();

        return true;
    }
}
