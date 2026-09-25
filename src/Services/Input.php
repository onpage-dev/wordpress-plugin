<?php



namespace OnPage\Services;



class Input
{
    /** Returns a positive integer from mixed input or null. */
    public static function positiveInt(mixed $value): int|null
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
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

    /** Returns a required non-empty string param or throws the standard REST error. */
    public static function requireStringParam(array $params, string $key, string $error_prefix, int $element_index): string
    {
        $value = self::stringOrNull($params[$key] ?? null);
        if ($value === null) {
            throw httpException($error_prefix . " :: Element $element_index :: Parameter '$key' is required", 400, 'invalid_param');
        }

        return $value;
    }

    /** Returns a required positive-integer param or throws the standard REST error. */
    public static function requirePositiveIntParam(array $params, string $key, string $error_prefix, int $element_index): int
    {
        $value = self::positiveInt($params[$key] ?? null);
        if ($value === null) {
            throw httpException($error_prefix . " :: Element $element_index :: Parameter '$key' must be a positive integer", 400, 'invalid_param');
        }

        return $value;
    }

    /** Returns a required local_key param (positive integer or non-empty string) or throws the standard REST error. */
    public static function requireLocalKeyParam(array $params, string $key, string $error_prefix, int $element_index): string
    {
        $value = self::localKey($params[$key] ?? null);
        if ($value === null) {
            throw httpException($error_prefix . " :: Element $element_index :: Parameter '$key' must be a positive integer or a non-empty string", 400, 'invalid_param');
        }

        return $value;
    }
}
