<?php



namespace OnPage\Services;



class MultiLang
{
    /**
     * Active language codes present in a raw multilingual value map.
     *
     * Detection is by language-map *shape*, then intersected with the site's active
     * WPML languages: a payload may carry a language the site hasn't activated (e.g.
     * `es` on an it/en site) and that extra language must simply be ignored, not
     * invalidate the whole map. Requiring *every* key to be an active language would
     * make a single unknown code hide the it/en translations from the caller, so no
     * translated post/term would be created at all.
     */
    public static function getLanguages(mixed $value): array
    {
        if (!self::isLanguageMapShape($value)) {
            return [];
        }

        return array_values(array_intersect(array_keys($value), getWpmlLanguages()));
    }

    /** Language codes present anywhere in a field => value map. */
    public static function getFieldLanguages(array $fields): array
    {
        $languages = [];
        foreach ($fields as $value) {
            $languages = array_merge($languages, self::getLanguages($value));
        }

        return array_values(array_unique($languages));
    }

    /** True when value looks like a raw language => value map, even before WPML exposes active languages. */
    public static function isLanguageMapShape(mixed $value): bool
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return false;
        }

        foreach (array_keys($value) as $language_code) {
            if (!self::isLanguageCodeKey($language_code)) {
                return false;
            }
        }

        return true;
    }

    /** Returns the first nested path whose value looks like a raw language => value map. */
    public static function findLanguageMapPath(mixed $value, string $path): string|null
    {
        if (self::isLanguageMapShape($value)) {
            return $path;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $key => $nested_value) {
            $nested_path = $path === '' ? (string) $key : $path . '.' . (string) $key;
            $match = self::findLanguageMapPath($nested_value, $nested_path);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /** Throws the standard WPML-required error when a single value is a language map. */
    public static function requireWpmlForLanguageMap(
        mixed $value,
        string $error_prefix,
        int $element_index,
        string $path
    ): void {
        if (isWpmlActive() || !self::isLanguageMapShape($value)) {
            return;
        }

        self::throwWpmlRequired($error_prefix, $element_index, $path);
    }

    /** Checks a field => value map for direct language-map values. */
    public static function requireWpmlForFieldMap(
        mixed $fields,
        string $error_prefix,
        int $element_index,
        string $path
    ): void {
        if (!is_array($fields)) {
            return;
        }

        foreach ($fields as $field_key => $value) {
            self::requireWpmlForLanguageMap(
                $value,
                $error_prefix,
                $element_index,
                $path . '.' . (string) $field_key
            );
        }
    }

    /** Checks nested payloads and reports the first language-map path found. */
    public static function requireWpmlForNestedLanguageMaps(
        mixed $value,
        string $error_prefix,
        int $element_index,
        string $path
    ): void {
        if (isWpmlActive()) {
            return;
        }

        $language_map_path = self::findLanguageMapPath($value, $path);
        if ($language_map_path !== null) {
            self::throwWpmlRequired($error_prefix, $element_index, $language_map_path);
        }
    }

    /** Throws the standard WPML-required error when translated languages were detected. */
    public static function requireWpmlForDetectedLanguages(
        array $translated_languages,
        string $error_prefix,
        int $element_index,
        string $path = 'payload'
    ): void {
        if ($translated_languages === [] || isWpmlActive()) {
            return;
        }

        self::throwWpmlRequired($error_prefix, $element_index, $path);
    }

    /** Standard error shape for unsupported language-map payloads. */
    private static function throwWpmlRequired(string $error_prefix, int $element_index, string $path): void
    {
        throw httpException(
            "$error_prefix :: Element $element_index :: WPML plugin is not installed or active; field '$path' contains multilingual values that cannot be handled until WPML is installed and active",
            500,
            'wpml_required'
        );
    }

    /** Whether an array key has the usual WPML language-code shape, e.g. en, it, pt-br. */
    private static function isLanguageCodeKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})*$/i', $key) === 1;
    }

    /**
     * Splits a scalar or per-language map into shared and translated buckets.
     *
     * Only the site's active languages land in `translated`: languages the payload
     * carries but the site hasn't activated are dropped here rather than kept, so
     * they can never be picked as the fallback language downstream.
     */
    public static function splitValueByLanguage(mixed $value): array
    {
        $language_codes = self::getLanguages($value);

        if ($language_codes !== []) {
            return [
                'shared' => null,
                'translated' => array_intersect_key($value, array_flip($language_codes)),
            ];
        }

        return [
            'shared' => $value,
            'translated' => [],
        ];
    }

    /** Resolves a value from a shared/translated split for a language. */
    public static function getValueForLanguage(array $value_map, ?string $language_code, ?string $fallback_language = null): mixed
    {
        if ($language_code && array_key_exists($language_code, $value_map['translated'] ?? [])) {
            return $value_map['translated'][$language_code];
        }

        if ($fallback_language && array_key_exists($fallback_language, $value_map['translated'] ?? [])) {
            return $value_map['translated'][$fallback_language];
        }

        return $value_map['shared'] ?? null;
    }

    /**
     * Resolves a localized value for a language.
     * Supports both raw language maps like `['it' => 'Ciao', 'en' => 'Hello']`
     * and the internal `['shared' => ..., 'translated' => ...]` structure.
     * The per-language value may be a scalar or a structured value (e.g. repeater
     * rows or a group object), so the resolved value is returned as-is.
     * Returns null when no value is found for the language or fallback.
     */
    public static function resolve(string|array $value, ?string $lang, ?string $fallback_lang = null): mixed
    {
        if (is_string($value)) return $value;

        if (array_key_exists('shared', $value) || array_key_exists('translated', $value)) {
            return self::getValueForLanguage($value, $lang, $fallback_lang);
        }

        if ($lang && array_key_exists($lang, $value)) {
            return $value[$lang];
        }

        if ($fallback_lang && array_key_exists($fallback_lang, $value)) {
            return $value[$fallback_lang];
        }

        return null;
    }

    /**
     * Resolves a field => value map for one language, keeping non-multilingual values unchanged.
     *
     * Only values that have language-map *shape* are unwrapped. A structured ACF value
     * sent as shared — a repeater list, a group object, a gallery, a multi-checkbox —
     * carries no language keys, so resolving it would find neither the requested
     * language nor the fallback and yield `null`: the field would be written empty
     * while the request still answered 200 and the data would be lost in silence.
     * Such a value is not multilingual and must reach the field write untouched.
     *
     * Detection is by shape rather than by membership in the site's active WPML
     * languages, for the reason spelled out on `getLanguages()`: a payload may carry a
     * language the site hasn't activated, and that extra language must be ignored
     * during resolution instead of leaving the whole map unresolved.
     */
    public static function resolveFields(array $fields, ?string $language_code, ?string $fallback_language = null): array
    {
        $resolved = [];

        foreach ($fields as $field_key => $value) {
            $resolved[$field_key] = self::isLanguageMapShape($value)
                ? self::resolve($value, $language_code, $fallback_language)
                : $value;
        }

        return $resolved;
    }

    /** Splits ACF field payloads into shared and per-language field sets (inactive languages dropped). */
    public static function splitAcfFieldsByLanguage(array $fields): array
    {
        $shared_fields = [];
        $translated_fields = [];

        foreach ($fields as $field_key => $value) {
            $language_codes = self::getLanguages($value);
            if ($language_codes !== []) {
                foreach ($language_codes as $language_code) {
                    $translated_fields[$language_code][$field_key] = $value[$language_code];
                }

                continue;
            }

            $shared_fields[$field_key] = $value;
        }

        return [
            'shared' => $shared_fields,
            'translated' => $translated_fields,
        ];
    }

    /** Merges shared and translated ACF fields for a target language. */
    public static function getFieldsForLanguage(array $field_map, ?string $language_code, ?string $fallback_language = null): array
    {
        $fields = $field_map['shared'] ?? [];

        if ($language_code && !empty($field_map['translated'][$language_code])) {
            return array_merge($fields, $field_map['translated'][$language_code]);
        }

        if ($fallback_language && !empty($field_map['translated'][$fallback_language])) {
            return array_merge($fields, $field_map['translated'][$fallback_language]);
        }

        return $fields;
    }
}
