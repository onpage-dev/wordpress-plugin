<?php



use OnPage\Env;
use OnPage\Exceptions\HttpException;



if (!function_exists('env')) {
    /**
     * Returns a string value from the plugin `.env` via `Env::get()`.
     *
     * @param string $key     Environment key.
     * @param mixed  $default Fallback when the key is absent (coerced to string).
     */
    function env(string $key, $default = ''): string
    {
        $value = Env::get($key, $default);

        return is_string($value) ? $value : (string) $value;
    }
}

if (!function_exists('array_is_list')) {
    /**
     * Polyfill for PHP 8.0, matching PHP 8.1's array_is_list().
     */
    function array_is_list(array $array): bool
    {
        $expected_key = 0;

        foreach ($array as $key => $_) {
            if ($key !== $expected_key) {
                return false;
            }

            $expected_key++;
        }

        return true;
    }
}

/**
 * Prefixes a post type slug with `post_` when missing, matching plugin conventions.
 */
function norm_post_type(string $post_type): string
{
    $prefix = 'post_';

    if (str_starts_with($post_type, $prefix)) {
        return $post_type;
    }

    return $prefix . $post_type;
}

/**
 * Whether a post row is still loadable by WordPress.
 *
 * WPML translation groups live in their own table, so they can outlive the posts they
 * point at: this is the guard that keeps a dead member out of a language => id map.
 */
function postExists(int $post_id): bool
{
    return $post_id > 0 && \get_post($post_id) instanceof \WP_Post;
}

/**
 * Whether the `ignore` query flag is present on the current REST request.
 */
function shouldIgnoreMissing(\WP_REST_Request $request): bool
{
    return array_key_exists('ignore', $request->get_query_params());
}

/**
 * Sets `X-WP-Total`/`X-WP-TotalPages` headers on a paginated list response, mirroring
 * the WordPress core REST API convention so clients can detect the last page without
 * guessing from an empty result.
 */
function setPaginationHeaders(\WP_REST_Response $response, int $total, int $per_page): void
{
    $total_pages = $per_page > 0 ? (int) \ceil($total / $per_page) : 0;

    $response->header('X-WP-Total', (string) $total);
    $response->header('X-WP-TotalPages', (string) $total_pages);
}



/**
 * Whether WPML (or ACFML-related) multilingual filters are active.
 *
 * Memoized per request: the active multilingual plugin set cannot change while a
 * single REST import runs, and this is queried dozens of times per batch element.
 */
function isWpmlActive(): bool
{
    static $is_active = null;

    if ($is_active === null) {
        $is_active = \has_filter('wpml_default_language') || \defined('ICL_SITEPRESS_VERSION');
    }

    return $is_active;
}

/**
 * Get the default WPML language code, if configured.
 *
 * Memoized per request; the configured default language is invariant for the
 * duration of an import request (unlike the *current* language, which switches).
 */
function getWpmlDefaultLanguage(): string|null
{
    static $default_language = false;

    if ($default_language !== false) {
        return $default_language;
    }

    if (!isWpmlActive()) {
        return $default_language = null;
    }

    $language = \apply_filters('wpml_default_language', null);

    return $default_language = (is_string($language) && $language !== '' ? $language : null);
}

/**
 * Get the current WPML language code, if configured.
 */
function getWpmlCurrentLanguage(): string|null
{
    if (!isWpmlActive()) return null;

    $language = \apply_filters('wpml_current_language', null);

    return is_string($language) && $language !== '' ? $language : null;
}

/**
 * Returns active WPML language codes plus default when missing from the list.
 *
 * Memoized per request: the active language set is stable during an import, and
 * this is on the hot path of validation/resolution (called per field, per
 * element). Each cache miss otherwise runs the full `wpml_active_languages`
 * filter chain, which WPML resolves with internal queries.
 */
function getWpmlLanguages(): array
{
    static $cached_languages = null;

    if ($cached_languages !== null) {
        return $cached_languages;
    }

    $languages = [];
    if (!isWpmlActive()) return $cached_languages = $languages;

    $active_languages = \apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
    if (is_array($active_languages)) {
        foreach ($active_languages as $language) {
            if (!empty($language['language_code'])) {
                $languages[] = (string) $language['language_code'];
            }
        }
    }

    $default_language = getWpmlDefaultLanguage();
    if ($default_language && !in_array($default_language, $languages, true)) {
        $languages[] = $default_language;
    }

    return $cached_languages = array_values(array_unique($languages));
}



/**
 * Helper to create an HttpException with a consistent error code prefix.
 *
 * @param string $message     Error message.
 * @param int    $status_code HTTP status code (default 500).
 * @param string $error_code  Custom error code suffix (default 'onpage_api_error').
 *
 * @return HttpException
 */
function httpException(string $message = '', int $status_code = 500, string $error_code = 'onpage_api_error'): HttpException
{
    return new HttpException($message, $status_code, $error_code);
}
