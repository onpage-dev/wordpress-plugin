<?php



namespace OnPage\Services;



use ACFML\Strings\Factory as AcfmlStringsFactory;
use ACFML\Strings\Package as AcfmlStringsPackage;
use ACFML\Strings\Translator as AcfmlStringsTranslator;



class Taxonomy
{
    private const TRANSLATION_OPTION_KEY = 'onpage_taxonomy_label_translations';
    private const DEFAULT_REST_NAMESPACE = 'wp/v2';
    private const DEFAULT_REST_CONTROLLER = 'WP_REST_Terms_Controller';

    /** Resolves a translated taxonomy label for the active or default language. */
    private static function getLocalizedLabelValue(string $taxonomy_key, string $label_key, string $default_value): string
    {
        $translations = self::getTranslationStore();
        $label_map = $translations[$taxonomy_key][$label_key] ?? null;
        if (!is_array($label_map) || $label_map === []) {
            return $default_value;
        }

        $current_language = getWpmlCurrentLanguage();
        if ($current_language && isset($label_map[$current_language]) && is_scalar($label_map[$current_language])) {
            return (string) $label_map[$current_language];
        }

        $default_language = getWpmlDefaultLanguage();
        if ($default_language && isset($label_map[$default_language]) && is_scalar($label_map[$default_language])) {
            return (string) $label_map[$default_language];
        }

        foreach ($label_map as $translated_value) {
            if (is_scalar($translated_value)) {
                return (string) $translated_value;
            }
        }

        return $default_value;
    }

    /** True when the label value is an associative map of language => string. */
    private static function isMultilingualLabelValue(mixed $value): bool
    {
        return MultiLang::isLanguageMapShape($value);
    }

    /** Picks a single string label from a string or per-language map. */
    private static function normalizeLabelValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return '';
        }

        $default_language = getWpmlDefaultLanguage();
        if ($default_language && isset($value[$default_language]) && is_scalar($value[$default_language])) {
            return (string) $value[$default_language];
        }

        foreach ($value as $translated_value) {
            if (is_scalar($translated_value)) {
                return (string) $translated_value;
            }
        }

        return '';
    }

    /** Loads stored per-taxonomy label translations from options. */
    private static function getTranslationStore(): array
    {
        $translations = \get_option(self::TRANSLATION_OPTION_KEY, []);

        return is_array($translations) ? $translations : [];
    }

    /** Persists the full label translation map to options. */
    private static function setTranslationStore(array $translations): void
    {
        \update_option(self::TRANSLATION_OPTION_KEY, $translations, false);
    }

    /** Saves multilingual label maps for one taxonomy key. */
    private static function storeLabelTranslations(string $taxonomy_key, array $labels): void
    {
        $translations = self::getTranslationStore();
        $translations[$taxonomy_key] = $labels;
        self::setTranslationStore($translations);
    }

    /** Removes stored label translations for a taxonomy. */
    private static function deleteLabelTranslations(string $taxonomy_key): void
    {
        $translations = self::getTranslationStore();
        if (!array_key_exists($taxonomy_key, $translations)) {
            return;
        }

        unset($translations[$taxonomy_key]);
        self::setTranslationStore($translations);
    }

    /** Marks a taxonomy as translatable in WPML sync settings. */
    private static function setWpmlTaxonomyTranslatable(string $taxonomy_key): void
    {
        if (!isWpmlActive()) return;

        global $sitepress;

        if (!is_object($sitepress) || !method_exists($sitepress, 'get_setting') || !method_exists($sitepress, 'set_setting')) {
            return;
        }

        $sync_settings = $sitepress->get_setting('taxonomies_sync_option', []);
        if (!is_array($sync_settings)) {
            $sync_settings = [];
        }

        $sync_settings[$taxonomy_key] = 1;
        $sitepress->set_setting('taxonomies_sync_option', $sync_settings, true);

        if (method_exists($sitepress, 'verify_taxonomy_translations')) {
            $sitepress->verify_taxonomy_translations($taxonomy_key);
        }
    }

    /** Removes a taxonomy from WPML translatable taxonomies. */
    private static function setWpmlTaxonomyNotTranslatable(string $taxonomy_key): void
    {
        if (!isWpmlActive()) return;

        global $sitepress;

        if (!is_object($sitepress) || !method_exists($sitepress, 'get_setting') || !method_exists($sitepress, 'set_setting')) {
            return;
        }

        $sync_settings = $sitepress->get_setting('taxonomies_sync_option', []);
        if (!is_array($sync_settings) || !array_key_exists($taxonomy_key, $sync_settings)) {
            return;
        }

        unset($sync_settings[$taxonomy_key]);
        $sitepress->set_setting('taxonomies_sync_option', $sync_settings, true);
    }

    /** Registers taxonomy strings with ACF Multilingual when available. */
    private static function registerAcfmlTaxonomyLabels(array $taxonomy_data): void
    {
        if (!class_exists(AcfmlStringsTranslator::class) || !class_exists(AcfmlStringsFactory::class)) {
            return;
        }

        $translator = new AcfmlStringsTranslator(new AcfmlStringsFactory());
        $translator->registerTaxonomy($taxonomy_data);
    }

    /** Pushes per-language label strings into ACFML string translation. */
    private static function setAcfmlTaxonomyLabelTranslations(string $taxonomy_key, array $label_maps): void
    {
        if (!class_exists(AcfmlStringsPackage::class) || !defined('ICL_STRING_TRANSLATION_COMPLETE')) {
            return;
        }

        $translations_queue = [];

        foreach ($label_maps as $label_key => $label_map) {
            if (!is_array($label_map) || $label_map === []) {
                continue;
            }

            $default_value = self::normalizeLabelValue($label_map);
            if ($default_value === '') {
                continue;
            }

            $string_name = AcfmlStringsPackage::getStringName($default_value, [
                'namespace' => 'taxonomy',
                'id' => $taxonomy_key,
                'key' => $label_key,
            ]);

            foreach ($label_map as $language_code => $translated_value) {
                if (!is_scalar($translated_value)) {
                    continue;
                }

                $translations_queue[$string_name][(string) $language_code] = [
                    'value' => (string) $translated_value,
                    'status' => ICL_STRING_TRANSLATION_COMPLETE,
                ];
            }
        }

        if ($translations_queue === []) {
            return;
        }

        $package = AcfmlStringsPackage::create($taxonomy_key, AcfmlStringsPackage::TAXONOMY_PACKAGE_KIND_SLUG);
        $package->setStringTranslations($translations_queue);
        $package->flushCache();
    }

    /** Hooks taxonomy registration to apply stored translated labels. */
    public static function boot(): void
    {
        \add_action('registered_taxonomy', [self::class, 'applyStaticTranslatedLabels'], 20, 3);
    }

    /** Overrides registered taxonomy label objects with values from the translation store. */
    public static function applyStaticTranslatedLabels(string $taxonomy, array|string $object_type, array $args): void
    {
        $translations = self::getTranslationStore();
        if (!isset($translations[$taxonomy])) {
            return;
        }

        global $wp_taxonomies;

        if (!isset($wp_taxonomies[$taxonomy])) {
            return;
        }

        $labels = $wp_taxonomies[$taxonomy]->labels ?? null;
        if (!$labels) {
            return;
        }

        $labels->singular_name = self::getLocalizedLabelValue($taxonomy, 'singular_name', (string) ($labels->singular_name ?? ''));
        $labels->name = self::getLocalizedLabelValue($taxonomy, 'name', (string) ($labels->name ?? ''));
        $labels->menu_name = self::getLocalizedLabelValue($taxonomy, 'menu_name', (string) ($labels->menu_name ?? $labels->singular_name));
    }

    /** Finds an ACF taxonomy by its key. */
    private static function findByKey(string $key): array|null
    {
        foreach (\acf_get_acf_taxonomies() as $taxonomy) {
            if ($taxonomy['key'] === $key) return $taxonomy;
        }

        return null;
    }

    /** Finds an ACF taxonomy by its slug. */
    private static function findBySlug(string $slug): array|null
    {
        foreach (\acf_get_acf_taxonomies() as $taxonomy) {
            if (($taxonomy['taxonomy'] ?? null) === $slug) return $taxonomy;
        }

        return null;
    }

    /** Whether a field group has a taxonomy term location rule for the given taxonomy slug. */
    private static function fieldGroupTargetsTaxonomy(array $field_group, string $taxonomy_slug): bool
    {
        foreach ($field_group['location'] ?? [] as $rule_group) {
            if (!is_array($rule_group)) continue;

            foreach ($rule_group as $rule) {
                if (!is_array($rule)) continue;

                if (($rule['param'] ?? null) !== 'taxonomy') continue;
                if (($rule['operator'] ?? '==') !== '==') continue;

                $value = $rule['value'] ?? null;
                if (is_scalar($value) && (string) $value === $taxonomy_slug) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Deletes ACF field groups attached to terms of the deleted taxonomy. */
    private static function deleteFieldGroupsForTaxonomy(string $taxonomy_slug): void
    {
        foreach (\acf_get_field_groups() as $field_group) {
            if (!self::fieldGroupTargetsTaxonomy($field_group, $taxonomy_slug)) {
                continue;
            }

            $field_group_id = $field_group['ID'] ?? null;
            if (!$field_group_id) {
                continue;
            }

            if (!\acf_delete_field_group((int) $field_group_id)) {
                throw httpException("Taxonomy :: Failed to delete FieldGroup for taxonomy '$taxonomy_slug'", 500, 'delete_failed');
            }
        }
    }

    /** Deletes all terms for a taxonomy. */
    private static function deleteTerms(string $taxonomy): void
    {
        global $wpdb;

        $term_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT t.term_id
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE tt.taxonomy = %s",
                $taxonomy
            )
        );
        if (!is_array($term_ids)) {
            throw httpException("Unable to list terms for taxonomy '$taxonomy'", 500, 'request_failed');
        }

        $term_ids = array_values(array_unique(array_map('intval', $term_ids)));
        foreach ($term_ids as $term_id) {
            if (!\wp_delete_term((int) $term_id, $taxonomy)) {
                throw httpException("Unable to delete term $term_id for taxonomy '$taxonomy'", 500, 'delete_failed');
            }
        }
    }

    /** Normalized capabilities payload for taxonomy registration. */
    private static function buildCapabilities(array $params): array
    {
        $capabilities = $params['capabilities'] ?? [];

        return [
            'manage_terms' => !empty($capabilities['manage_terms']) ? \sanitize_key($capabilities['manage_terms']) : 'manage_categories',
            'edit_terms' => !empty($capabilities['edit_terms']) ? \sanitize_key($capabilities['edit_terms']) : 'manage_categories',
            'deleteTerms' => !empty($capabilities['deleteTerms']) ? \sanitize_key($capabilities['deleteTerms']) : 'manage_categories',
            'assign_terms' => !empty($capabilities['assign_terms']) ? \sanitize_key($capabilities['assign_terms']) : 'edit_posts',
        ];
    }

    /** Normalized rewrite payload for taxonomy registration. */
    private static function buildRewrite(array $params): array
    {
        $rewrite = $params['rewrite'] ?? [];

        return [
            'permalink_rewrite' => isset($rewrite['permalink_rewrite']) ? \sanitize_key($rewrite['permalink_rewrite']) : 'taxonomy_key',
            'slug' => isset($rewrite['slug']) ? \sanitize_title($rewrite['slug']) : '',
            'with_front' => array_key_exists('with_front', $rewrite) ? (bool) $rewrite['with_front'] : true,
            'rewrite_hierarchical' => array_key_exists('rewrite_hierarchical', $rewrite) ? (bool) $rewrite['rewrite_hierarchical'] : false,
        ];
    }

    /** Normalized default-term payload for taxonomy registration. */
    private static function buildDefaultTerm(array $params): array
    {
        $default_term = $params['default_term'] ?? [];

        return [
            'default_term_enabled' => array_key_exists('default_term_enabled', $default_term) ? (bool) $default_term['default_term_enabled'] : false,
            'default_term_name' => isset($default_term['default_term_name']) ? \sanitize_text_field($default_term['default_term_name']) : '',
            'default_term_slug' => isset($default_term['default_term_slug']) ? \sanitize_title($default_term['default_term_slug']) : '',
            'default_term_description' => isset($default_term['default_term_description']) ? \sanitize_textarea_field($default_term['default_term_description']) : '',
        ];
    }

    /** Base label maps saved for multilingual taxonomy labels. */
    private static function buildLabelTranslationMaps(mixed $raw_singular_label, mixed $raw_plural_label): array
    {
        return [
            'singular_name' => self::isMultilingualLabelValue($raw_singular_label) ? $raw_singular_label : [],
            'name' => self::isMultilingualLabelValue($raw_plural_label) ? $raw_plural_label : [],
            'menu_name' => self::isMultilingualLabelValue($raw_singular_label) ? $raw_singular_label : [],
        ];
    }

    /** Saves or clears multilingual label metadata for one taxonomy. */
    private static function syncLabelTranslations(string $taxonomy_key, mixed $raw_singular_label, mixed $raw_plural_label): void
    {
        if (!self::isMultilingualLabelValue($raw_singular_label) && !self::isMultilingualLabelValue($raw_plural_label)) {
            self::deleteLabelTranslations($taxonomy_key);
            return;
        }

        $label_maps = self::buildLabelTranslationMaps($raw_singular_label, $raw_plural_label);
        self::storeLabelTranslations($taxonomy_key, $label_maps);
        self::setAcfmlTaxonomyLabelTranslations($taxonomy_key, $label_maps);
    }

    /** Normalized payload for `acf_update_taxonomy()`. */
    private static function buildTaxonomyData(array $params, string $key, string $singular_label, string $plural_label): array
    {
        return [
            'key' => $key,
            'title' => $params['title'] ?? $key,
            'menu_order' => $params['menu_order'] ?? 0,
            'active' => array_key_exists('active', $params) ? (bool) $params['active'] : true,
            'taxonomy' => $key,
            'object_type' => $params['object_type'] ?? [],
            'advanced_configuration' => array_key_exists('advanced_configuration', $params) ? (bool) $params['advanced_configuration'] : false,
            'labels' => [
                'singular_name' => $singular_label,
                'name' => $plural_label,
                'menu_name' => $singular_label,
            ],
            'description' => $params['description'] ?? '',
            'capabilities' => self::buildCapabilities($params),
            'public' => array_key_exists('public', $params) ? (bool) $params['public'] : true,
            'publicly_queryable' => array_key_exists('publicly_queryable', $params) ? (bool) $params['publicly_queryable'] : true,
            'hierarchical' => array_key_exists('hierarchical', $params) ? (bool) $params['hierarchical'] : false,
            'show_ui' => array_key_exists('show_ui', $params) ? (bool) $params['show_ui'] : true,
            'show_in_menu' => array_key_exists('show_in_menu', $params) ? (bool) $params['show_in_menu'] : true,
            'show_in_nav_menus' => array_key_exists('show_in_nav_menus', $params) ? (bool) $params['show_in_nav_menus'] : true,
            'show_in_rest' => array_key_exists('show_in_rest', $params) ? (bool) $params['show_in_rest'] : true,
            'rest_base' => isset($params['rest_base']) ? \sanitize_key($params['rest_base']) : '',
            'rest_namespace' => isset($params['rest_namespace']) ? \sanitize_text_field($params['rest_namespace']) : self::DEFAULT_REST_NAMESPACE,
            'rest_controller_class' => isset($params['rest_controller_class']) ? \sanitize_text_field($params['rest_controller_class']) : self::DEFAULT_REST_CONTROLLER,
            'show_tagcloud' => array_key_exists('show_tagcloud', $params) ? (bool) $params['show_tagcloud'] : true,
            'show_in_quick_edit' => array_key_exists('show_in_quick_edit', $params) ? (bool) $params['show_in_quick_edit'] : true,
            'show_admin_column' => array_key_exists('show_admin_column', $params) ? (bool) $params['show_admin_column'] : false,
            'rewrite' => self::buildRewrite($params),
            'query_var' => isset($params['query_var']) ? \sanitize_key($params['query_var']) : 'taxonomy_key',
            'query_var_name' => isset($params['query_var_name']) ? \sanitize_key($params['query_var_name']) : '',
            'default_term' => self::buildDefaultTerm($params),
            'meta_box' => isset($params['meta_box']) ? \sanitize_key($params['meta_box']) : 'default',
            'meta_box_cb' => isset($params['meta_box_cb']) ? \sanitize_text_field($params['meta_box_cb']) : '',
            'meta_box_sanitize_cb' => isset($params['meta_box_sanitize_cb']) ? \sanitize_text_field($params['meta_box_sanitize_cb']) : '',
        ];
    }

    /** Deletes translation metadata and WPML taxonomy state for one slug. */
    private static function cleanupDeletedTaxonomy(string $taxonomy_slug): void
    {
        self::deleteLabelTranslations($taxonomy_slug);
        self::setWpmlTaxonomyNotTranslatable($taxonomy_slug);
    }

    /** Deletes a taxonomy by ACF numeric ID. */
    public static function deleteById(int $id, bool $ignore_missing): void
    {
        $taxonomy = \acf_get_taxonomy($id);
        if (!$taxonomy) {
            if ($ignore_missing) {
                return;
            }

            throw httpException("Taxonomy :: ID $id not found", 404, 'not_found');
        }

        try {
            self::deleteTerms($taxonomy['taxonomy']);
        } catch (\OnPage\Exceptions\HttpException $e) {
            throw httpException($e->getMessage(), $e->status_code, $e->error_code);
        }

        if (!\acf_delete_taxonomy($id)) {
            throw httpException("Taxonomy :: Failed to delete ID $id", 500, 'delete_failed');
        }

        self::deleteFieldGroupsForTaxonomy($taxonomy['taxonomy']);
        self::cleanupDeletedTaxonomy($taxonomy['taxonomy']);
    }

    /** Deletes a taxonomy by slug. */
    public static function deleteBySlug(string $value, bool $ignore_missing): void
    {
        $taxonomy_slug = \sanitize_key($value);
        $taxonomy = self::findBySlug($taxonomy_slug);
        if (!$taxonomy) {
            if ($ignore_missing) {
                self::deleteFieldGroupsForTaxonomy($taxonomy_slug);
                self::cleanupDeletedTaxonomy($taxonomy_slug);

                return;
            }

            throw httpException("Taxonomy :: Taxonomy '$taxonomy_slug' not found", 404, 'not_found');
        }

        try {
            self::deleteTerms($taxonomy_slug);
        } catch (\OnPage\Exceptions\HttpException $e) {
            throw httpException($e->getMessage(), $e->status_code, $e->error_code);
        }

        if (!\acf_delete_taxonomy($taxonomy['ID'])) {
            throw httpException("Taxonomy :: Unable to delete Taxonomy with slug '$taxonomy_slug'", 500, 'delete_failed');
        }

        self::deleteFieldGroupsForTaxonomy($taxonomy_slug);
        self::cleanupDeletedTaxonomy($taxonomy_slug);
    }

    /** Stored translated labels for one taxonomy key, when available. */
    private static function getStoredLabelTranslations(array $translations, string $taxonomy_key): ?array
    {
        $label_translations = $translations[$taxonomy_key] ?? null;

        return is_array($label_translations) ? $label_translations : null;
    }

    /** API response payload for one taxonomy list item. */
    private static function buildListItem(array $taxonomy, array $translations): array
    {
        $label_translations = self::getStoredLabelTranslations($translations, (string) $taxonomy['key']);

        return [
            'id' => $taxonomy['ID'],
            'key' => $taxonomy['key'],
            'singular_label' => $label_translations['singular_name'] ?? $taxonomy['labels']['singular_name'],
            'plural_label' => $label_translations['name'] ?? $taxonomy['labels']['name'],
            'description' => $taxonomy['description'],
            'hierarchical' => $taxonomy['hierarchical'],
        ];
    }

    /** Returns all ACF taxonomies merged with stored label translations. */
    public static function listItems(): array
    {
        $translations = self::getTranslationStore();

        return array_map(
            fn(array $taxonomy): array => self::buildListItem($taxonomy, $translations),
            \acf_get_acf_taxonomies()
        );
    }

    /**
     * Creates or updates one taxonomy (idempotent upsert by ACF `key`) from the raw controller
     * payload and returns its ACF ID. Re-posting the same `key` updates that taxonomy in place
     * instead of failing, so repeated calls converge to the same record.
     *
     * Rewrite rules are NOT flushed here: the controller flushes once after the whole batch.
     */
    public static function saveFromParams(array $params, int $element_index = 0): int
    {
        $key = $params['key'];

        $raw_singular_label = $params['singular_label'] ?? '';
        $raw_plural_label = $params['plural_label'] ?? '';
        foreach (['singular_label' => $raw_singular_label, 'plural_label' => $raw_plural_label] as $label_key => $label_value) {
            MultiLang::requireWpmlForLanguageMap($label_value, 'Taxonomy', $element_index, $label_key);
        }

        $singular_label = self::normalizeLabelValue($raw_singular_label);
        $plural_label = self::normalizeLabelValue($raw_plural_label);
        $data = self::buildTaxonomyData($params, $key, $singular_label, $plural_label);

        $existing = self::findByKey($key);
        if ($existing !== null) {
            $data['ID'] = $existing['ID'];
        }

        $result = \acf_update_taxonomy($data);
        if (!$result) {
            throw httpException("Taxonomy :: Failed to save Taxonomy with key $key", 500, 'acf_error');
        }

        self::setWpmlTaxonomyTranslatable($key);
        self::registerAcfmlTaxonomyLabels($data);
        self::syncLabelTranslations($key, $raw_singular_label, $raw_plural_label);

        return (int) $result['ID'];
    }
}
