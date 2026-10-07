<?php



namespace OnPage\Services;



class Language
{
    /**
     * The site's language set, as the importer needs to see it.
     *
     * Every write endpoint decides which translations to create by *sniffing* the
     * language codes out of the payload's language maps, and `MultiLang::getLanguages()`
     * then intersects them with the active WPML languages — a code the site does not
     * have is dropped without an error, on purpose (a payload may legitimately carry a
     * language this site has not activated). That makes a misconfigured language code on
     * the caller's side indistinguishable from "this element has no translation": the
     * language simply never appears, silently.
     *
     * This endpoint is the missing half of that contract: it lets the caller check the
     * codes it is about to send against the ones that can actually be written, before
     * anything is imported.
     *
     * `wpml_active` tells apart the two reasons `languages` can hold a single entry:
     * a single-language WPML install, and a site with no WPML at all (where language
     * maps are rejected with `wpml_required` rather than ignored).
     *
     * `plugin_version` (since 1.0.3) rides along because this is the call every client
     * makes first: it lets the client refuse a plugin older than the one it needs. A
     * response without it comes from 1.0.2 or earlier.
     */
    public static function listItems(): array
    {
        $default = onpage_get_wpml_default_language();

        return [
            'plugin_version' => onpage_plugin_version(),
            'wpml_active' => onpage_is_wpml_active(),
            'default' => $default,
            'languages' => self::defaultFirst(onpage_get_wpml_languages(), $default),
        ];
    }

    /**
     * The same codes with the site's default language first.
     *
     * `wpml_active_languages` orders by the site's own display order, which has
     * nothing to do with which language is the default — and the default is the one
     * the importer writes as the source element, every other one being a translation
     * of it. Putting it first lets a caller read the list positionally.
     */
    private static function defaultFirst(array $languages, ?string $default): array
    {
        if ($default === null || !in_array($default, $languages, true)) {
            return $languages;
        }

        return array_values(array_merge(
            [$default],
            array_filter($languages, fn(string $code): bool => $code !== $default)
        ));
    }
}
