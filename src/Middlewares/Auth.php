<?php



namespace OnPage\Middlewares;



use OnPage\Services\Auth as AuthService;



class Auth
{
    /**
     * Permission callback: validates the configured Bearer token for this REST request.
     */
    public function handle(\WP_REST_Request $request): bool
    {
        (new AuthService($request))->check();

        return true;
    }
}
