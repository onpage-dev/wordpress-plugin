<?php



namespace OnPage\Services;



class Wpml
{
    /** Runs a callback while temporarily switching WPML and ACF current language. */
    public static function runWithLanguage(?string $language_code, callable $callback): mixed
    {
        if (!onpage_is_wpml_active() || !$language_code) {
            return $callback();
        }

        $current_language = \apply_filters('wpml_current_language', null);
        $current_acf_language = \function_exists('acf_get_setting')
            ? \acf_get_setting('current_language')
            : null;

        \do_action('wpml_switch_language', $language_code);
        if (\function_exists('acf_update_setting')) {
            \acf_update_setting('current_language', $language_code);
        }

        try {
            return $callback();
        } finally {
            \do_action('wpml_switch_language', $current_language ?: null);
            if (\function_exists('acf_update_setting')) {
                \acf_update_setting('current_language', $current_acf_language);
            }
        }
    }

    /**
     * Removes from a translation group the rows whose post no longer exists.
     *
     * WPML tracks translations in `icl_translations`, not in `wp_posts`: a post removed
     * without firing WordPress' `delete_post` (direct SQL, a partial DB restore, a
     * plugin deleting rows on its own) leaves its language slot in the group pointing at
     * an ID nobody can load. Left there, that slot occupies the language forever: it can
     * never be written and it hides the fact that the translation is missing. Live
     * members of the group are untouched.
     */
    public static function deleteOrphanPostTranslations(int $trid, string $element_type): void
    {
        if (!onpage_is_wpml_active() || $trid <= 0) return;

        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE translations FROM {$wpdb->prefix}icl_translations translations
                LEFT JOIN {$wpdb->posts} posts ON posts.ID = translations.element_id
             WHERE translations.trid = %d
                AND translations.element_type = %s
                AND posts.ID IS NULL",
            $trid,
            $element_type
        ));
    }
}
