<?php



namespace OnPage\Services;



class Auth
{
    const OPTION_TOKEN = 'onpage_auth_token';



    /**
     * @param \WP_REST_Request $request The current REST request.
     */
    public function __construct(public \WP_REST_Request $request) {}



    /**
     * Retrieves the configured API token from WordPress options.
     *
     * @return string|null The configured API token, or null if not set.
     */
    private function getConfiguredToken(): string|null
    {
        $token = \get_option(self::OPTION_TOKEN);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Extracts the Bearer token from the Authorization header in the request.
     *
     * @return string|null The Bearer token if present, or null.
     */
    private function getRequestToken(): string|null
    {
        $authorization = $this->request->get_header('authorization');

        return is_string($authorization) && preg_match('/^\s*Bearer\s+(.+)\s*$/i', $authorization, $matches) ? trim((string) $matches[1]) : null;
    }



    /**
     * Checks if the request is authorized based on the configured token and the request's token.
     */
    public function check(): void
    {
        $configured_token = $this->getConfiguredToken();
        if (!$configured_token) {
            throw httpException('On Page® API token is not configured', 500, 'onpage_auth_not_configured');
        }

        $request_token = $this->getRequestToken();
        if (!$request_token) {
            throw httpException('Missing API token', 401, 'onpage_auth_missing_token');
        }

        if (!hash_equals($configured_token, $request_token)) {
            throw httpException('Invalid API token', 403, 'onpage_auth_invalid_token');
        }
    }
}
