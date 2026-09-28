<?php



namespace OnPage\Services;



class MultiLang
{
    /** ISO 639-1 language codes (plus WPML's `mo`), keyed for O(1) lookup. */
    private const ISO_639_1_CODES = [
        'aa' => true, 'ab' => true, 'ae' => true, 'af' => true, 'ak' => true, 'am' => true,
        'an' => true, 'ar' => true, 'as' => true, 'av' => true, 'ay' => true, 'az' => true,
        'ba' => true, 'be' => true, 'bg' => true, 'bh' => true, 'bi' => true, 'bm' => true,
        'bn' => true, 'bo' => true, 'br' => true, 'bs' => true, 'ca' => true, 'ce' => true,
        'ch' => true, 'co' => true, 'cr' => true, 'cs' => true, 'cu' => true, 'cv' => true,
        'cy' => true, 'da' => true, 'de' => true, 'dv' => true, 'dz' => true, 'ee' => true,
        'el' => true, 'en' => true, 'eo' => true, 'es' => true, 'et' => true, 'eu' => true,
        'fa' => true, 'ff' => true, 'fi' => true, 'fj' => true, 'fo' => true, 'fr' => true,
        'fy' => true, 'ga' => true, 'gd' => true, 'gl' => true, 'gn' => true, 'gu' => true,
        'gv' => true, 'ha' => true, 'he' => true, 'hi' => true, 'ho' => true, 'hr' => true,
        'ht' => true, 'hu' => true, 'hy' => true, 'hz' => true, 'ia' => true, 'id' => true,
        'ie' => true, 'ig' => true, 'ii' => true, 'ik' => true, 'io' => true, 'is' => true,
        'it' => true, 'iu' => true, 'ja' => true, 'jv' => true, 'ka' => true, 'kg' => true,
        'ki' => true, 'kj' => true, 'kk' => true, 'kl' => true, 'km' => true, 'kn' => true,
        'ko' => true, 'kr' => true, 'ks' => true, 'ku' => true, 'kv' => true, 'kw' => true,
        'ky' => true, 'la' => true, 'lb' => true, 'lg' => true, 'li' => true, 'ln' => true,
        'lo' => true, 'lt' => true, 'lu' => true, 'lv' => true, 'mg' => true, 'mh' => true,
        'mi' => true, 'mk' => true, 'ml' => true, 'mn' => true, 'mo' => true, 'mr' => true,
        'ms' => true, 'mt' => true, 'my' => true, 'na' => true, 'nb' => true, 'nd' => true,
        'ne' => true, 'ng' => true, 'nl' => true, 'nn' => true, 'no' => true, 'nr' => true,
        'nv' => true, 'ny' => true, 'oc' => true, 'oj' => true, 'om' => true, 'or' => true,
        'os' => true, 'pa' => true, 'pi' => true, 'pl' => true, 'ps' => true, 'pt' => true,
        'qu' => true, 'rm' => true, 'rn' => true, 'ro' => true, 'ru' => true, 'rw' => true,
        'sa' => true, 'sc' => true, 'sd' => true, 'se' => true, 'sg' => true, 'si' => true,
        'sk' => true, 'sl' => true, 'sm' => true, 'sn' => true, 'so' => true, 'sq' => true,
        'sr' => true, 'ss' => true, 'st' => true, 'su' => true, 'sv' => true, 'sw' => true,
        'ta' => true, 'te' => true, 'tg' => true, 'th' => true, 'ti' => true, 'tk' => true,
        'tl' => true, 'tn' => true, 'to' => true, 'tr' => true, 'ts' => true, 'tt' => true,
        'tw' => true, 'ty' => true, 'ug' => true, 'uk' => true, 'ur' => true, 'uz' => true,
        've' => true, 'vi' => true, 'vo' => true, 'wa' => true, 'wo' => true, 'xh' => true,
        'yi' => true, 'yo' => true, 'za' => true, 'zh' => true, 'zu' => true,
    ];

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

        return array_values(array_intersect(array_keys($value), onpage_get_wpml_languages()));
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
        if (onpage_is_wpml_active() || !self::isLanguageMapShape($value)) {
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
        if (onpage_is_wpml_active()) {
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
        if ($translated_languages === [] || onpage_is_wpml_active()) {
            return;
        }

        self::throwWpmlRequired($error_prefix, $element_index, $path);
    }

    /** Standard error shape for unsupported language-map payloads. */
    private static function throwWpmlRequired(string $error_prefix, int $element_index, string $path): void
    {
        throw onpage_http_exception(
            "$error_prefix :: Element $element_index :: WPML plugin is not installed or active; field '$path' contains multilingual values that cannot be handled until WPML is installed and active",
            500,
            'wpml_required'
        );
    }

    /**
     * Whether an array key is a language code: an active WPML language, or an ISO 639-1
     * code with optional region or script subtags (`en`, `it`, `pt-br`, `zh-hans-cn`).
     *
     * A shape-only rule (any 2-3 letter key) mistook groups such as `{"lat": …, "lng": …}`,
     * `{"sku": …, "alt": …}` or `{"cta_url": …}` for language maps and cleared them. The
     * ISO list still recognizes a language the site has not activated (`es` on an it/en
     * site), so such a map keeps being treated as a map, not written raw into the field,
     * and without WPML a real language map still triggers `wpml_required`.
     */
    private static function isLanguageCodeKey(mixed $key): bool
    {
        if (!is_string($key)) {
            return false;
        }

        if (in_array($key, onpage_get_wpml_languages(), true)) {
            return true;
        }

        if (preg_match('/^([a-z]{2})(?:[-_][a-z0-9]{2,8})*$/i', $key, $matches) !== 1) {
            return false;
        }

        return isset(self::ISO_639_1_CODES[strtolower($matches[1])]);
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
