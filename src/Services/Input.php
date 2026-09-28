<?php



namespace OnPage\Services;



class Input
{
    /**
     * Returns a positive integer from mixed input or null.
     *
     * Accepts an int, a float with no fractional part, or a string of digits (surrounding
     * spaces are tolerated). Booleans, `"1.9"`, `"1e3"` and other numeric notations are
     * rejected instead of being cast, so `true` never turns into ID 1. `strictPositiveInt()`
     * is stricter still: no floats and no spaces.
     */
    public static function positiveInt(mixed $value): int|null
    {
        if (is_float($value)) {
            $value = floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : null;
        } elseif (is_string($value)) {
            $value = trim($value);
        }

        return self::strictPositiveInt($value);
    }

    /**
     * Normalizes an On Page® local_key to its canonical internal form, or null.
     *
     * A local_key may be a positive integer or a non-empty string (source systems are
     * no longer required to key objects by integer). The canonical form is the trimmed
     * scalar string. `''` and the string `'0'` are rejected so that every valid key is a
     * PHP-truthy string: this keeps the codebase's `if (!$local_key)` / `if ($local_key)`
     * "has a key" checks correct without a `!== null` audit. A numeric key and its string
     * form collapse to the same value (`123` and `'123'` both become `'123'`), matching how
     * WordPress stores meta values, so lookups stay consistent whichever type is sent.
     */
    public static function localKey(mixed $value): string|null
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return ($value !== '' && $value !== '0') ? $value : null;
    }

    /**
     * Renders a stored local_key meta value for API output.
     *
     * A canonical positive-integer key is returned as an int (so existing integer-keyed
     * consumers keep receiving numbers); any other non-empty key is returned as a string.
     * Absent/invalid values yield null. Values above PHP_INT_MAX stay strings (their int
     * cast would not round-trip), so no precision is lost.
     */
    public static function localKeyOut(mixed $value): int|string|null
    {
        $key = self::localKey($value);
        if ($key === null) {
            return null;
        }

        return (ctype_digit($key) && $key === (string) (int) $key) ? (int) $key : $key;
    }

    /** Returns a trimmed scalar string, or null when absent/empty. */
    public static function stringOrNull(mixed $value): string|null
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /** Returns a scalar query parameter as a trimmed string, or null when absent/empty. */
    public static function requestString(\WP_REST_Request $request, string $key): string|null
    {
        return self::stringOrNull($request->get_param($key));
    }

    /**
     * Normalizes a `string | lang-map` value into a list of `[language_code|null, value]` queries.
     *
     * A scalar string yields one query in the current language (`null`); a map
     * `{it: "Ciao", en: "Hello"}` yields one query per language. Empty values and language
     * codes are skipped. Language-code keys are trimmed of surrounding quotes/spaces so both
     * `name[it]=…` and `name['it']=…` work.
     */
    public static function langValueQueries(mixed $value): array
    {
        if (is_array($value)) {
            $queries = [];
            foreach ($value as $language_code => $entry) {
                $string = self::stringOrNull($entry);
                $language_code = is_string($language_code) ? trim($language_code, " '\"") : (string) $language_code;
                if ($string !== null && $language_code !== '') {
                    $queries[] = [$language_code, $string];
                }
            }

            return $queries;
        }

        $string = self::stringOrNull($value);

        return $string !== null ? [[null, $string]] : [];
    }

    /**
     * Returns the JSON request body as a list, or throws `400 invalid_param`.
     *
     * Every batch endpoint takes a JSON array. A missing or non-JSON body (`null`), a scalar
     * or an object with named keys is rejected here, so controllers can iterate the result
     * and pass each integer index on as the element index.
     *
     * The empty object `{}` decodes to the same `[]` as the empty list, so the raw body is
     * checked too: only a literal JSON array counts as an (empty) batch.
     */
    public static function requireJsonList(\WP_REST_Request $request, string $error_prefix): array
    {
        $body = $request->get_json_params();
        $is_empty_object = $body === [] && str_starts_with(ltrim((string) $request->get_body()), '{');
        if (!is_array($body) || !array_is_list($body) || $is_empty_object) {
            throw onpage_http_exception($error_prefix . ' :: Request body must be a JSON array', 400, 'invalid_param');
        }

        return $body;
    }

    /**
     * Returns a batch element as an array, or throws `400 invalid_param` when it is not a
     * JSON object (scalars, `null`, lists and the empty object `{}` are all rejected).
     */
    public static function requireObjectElement(mixed $value, string $error_prefix, int $element_index): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw onpage_http_exception($error_prefix . " :: Element $element_index :: Invalid payload; expected a non-empty JSON object", 400, 'invalid_param');
        }

        return $value;
    }

    /**
     * Returns a strictly positive integer ID (an int, or a string of digits only), or null.
     *
     * Unlike `positiveInt()`, which also accepts whole-number floats and surrounding spaces,
     * values such as `2.0`, `" 12"`, `"12abc"`, `"1.5"`, `true` or arrays are rejected.
     */
    public static function strictPositiveInt(mixed $value): int|null
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        // Leading zeros are tolerated; digit strings beyond PHP_INT_MAX are not (their cast would clamp).
        $digits = is_string($value) && ctype_digit($value) ? ltrim($value, '0') : '';
        if ($digits !== '' && $digits === (string) (int) $digits) {
            return (int) $digits;
        }

        return null;
    }

    /** Returns a required non-empty string param or throws the standard REST error. */
    public static function requireStringParam(array $params, string $key, string $error_prefix, int $element_index): string
    {
        $value = self::stringOrNull($params[$key] ?? null);
        if ($value === null) {
            throw onpage_http_exception($error_prefix . " :: Element $element_index :: Parameter '$key' is required", 400, 'invalid_param');
        }

        return $value;
    }

    /** Returns a required positive-integer param or throws the standard REST error. */
    public static function requirePositiveIntParam(array $params, string $key, string $error_prefix, int $element_index): int
    {
        $value = self::positiveInt($params[$key] ?? null);
        if ($value === null) {
            throw onpage_http_exception($error_prefix . " :: Element $element_index :: Parameter '$key' must be a positive integer", 400, 'invalid_param');
        }

        return $value;
    }

    /**
     * Returns an optional positive-integer param, or null when it is absent, `null` or `0`.
     *
     * `0` and `"0"` keep meaning "no ID", as they always have. Any other value that is not a
     * positive integer (`true`, `"1.9"`, `-3`) throws the standard REST error instead of being
     * dropped, so an update never turns into an insert.
     */
    public static function optionalPositiveIntParam(array $params, string $key, string $error_prefix, int $element_index): int|null
    {
        if (in_array($params[$key] ?? null, [null, 0, '0'], true)) {
            return null;
        }

        $value = self::positiveInt($params[$key]);
        if ($value === null) {
            throw onpage_http_exception($error_prefix . " :: Element $element_index :: Parameter '$key' must be a positive integer or null", 400, 'invalid_param');
        }

        return $value;
    }

    /** Returns a required local_key param (positive integer or non-empty string) or throws the standard REST error. */
    public static function requireLocalKeyParam(array $params, string $key, string $error_prefix, int $element_index): string
    {
        $value = self::localKey($params[$key] ?? null);
        if ($value === null) {
            throw onpage_http_exception($error_prefix . " :: Element $element_index :: Parameter '$key' must be a positive integer or a non-empty string", 400, 'invalid_param');
        }

        return $value;
    }
}
