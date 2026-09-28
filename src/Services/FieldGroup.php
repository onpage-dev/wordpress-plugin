<?php



namespace OnPage\Services;



class FieldGroup
{
    /**
     * ACFML field group mode used for every managed group.
     *
     * `translation` (and `localization`) let ACFML overwrite the WPML translation
     * preference of every field with its own per-type defaults on each
     * `acf/update_field_group`, and those defaults set repeater, group, flexible
     * content, image, file, gallery, number and select fields to *copy*. WPML then
     * overwrites the translations' values with the default language ones on every
     * save of the source post, which corrupts languages the importer wrote
     * explicitly. `advanced` (ACFML's "Expert" mode) leaves the per-field
     * preferences this class assigns untouched.
     */
    private const ACFML_FIELD_GROUP_MODE = 'advanced';

    /** Fallback for `WPML_TRANSLATE_CUSTOM_FIELD` when WPML's constants are not loaded. */
    private const WPML_TRANSLATE_CUSTOM_FIELD = 2;

    /** ACF field types that store no value and therefore need no WPML preference. */
    private const LAYOUT_ONLY_FIELD_TYPES = ['tab', 'message', 'accordion'];

    /** Creates a globally unique ACF field key. */
    private static function buildFieldKey(): string
    {
        return 'field_' . str_replace('-', '', \wp_generate_uuid4());
    }

    /** Finds an ACF field group by its title. */
    private static function findByTitle(string $title): array|null
    {
        foreach (\acf_get_field_groups() as $field_group) {
            if ($field_group['title'] === $title) {
                return $field_group;
            }
        }

        return null;
    }

    /** Builds an ACF-style field group key from a title (mirrors ACF's auto-generation). */
    private static function generateFieldGroupKey(string $title): string
    {
        $key = 'group_' . \acf_slugify($title, '_');
        if ($key === 'group_') {
            $key = 'group_' . md5($title);
        }

        return $key;
    }

    /** Finds an ACF field group by its key. */
    private static function findByKey(string $key): array|null
    {
        foreach (\acf_get_field_groups() as $field_group) {
            if (($field_group['key'] ?? null) === $key) {
                return $field_group;
            }
        }

        return null;
    }

    /**
     * Returns the existing ACF fields directly under a parent, indexed by field name.
     *
     * The parent is the field group ID at the top level, and the parent field's own
     * array further down: `acf_get_fields()` accepts both, and sub-fields are stored as
     * ordinary `acf-field` posts whose `post_parent` is the field above them.
     */
    private static function getExistingFieldsByName(int|array $parent, ?string $parent_layout = null): array
    {
        $fields_by_name = [];
        $fields = \acf_get_fields($parent);
        if (!is_array($fields)) return $fields_by_name;

        foreach ($fields as $field) {
            $field_name = $field['name'] ?? null;
            if (!is_string($field_name) || $field_name === '') continue;
            // Sub-fields of a flexible content field all hang under the field itself; the
            // layout they belong to is their `parent_layout` setting.
            if ($parent_layout !== null && ($field['parent_layout'] ?? null) !== $parent_layout) continue;

            $fields_by_name[$field_name] = $field;
        }

        return $fields_by_name;
    }

    /** Returns an existing field's internal ACF key, if available. */
    private static function getExistingFieldKey(?array $field): string|null
    {
        $field_key = $field['key'] ?? null;

        return is_string($field_key) && $field_key !== '' ? $field_key : null;
    }

    /** Adds an existing field ID to an ACF update payload when available. */
    private static function attachExistingFieldId(array $field_data, ?array $existing_field): array
    {
        $field_id = $existing_field['ID'] ?? null;
        if (is_numeric($field_id) && (int) $field_id > 0) {
            $field_data['ID'] = (int) $field_id;
        }

        return $field_data;
    }

    /**
     * The WPML translation preference every managed field must carry: "translate".
     *
     * The importer writes each active language explicitly, so WPML must never copy a
     * value from the default language over a translation.
     */
    private static function fieldTranslationPreference(): int
    {
        return \defined('WPML_TRANSLATE_CUSTOM_FIELD')
            ? (int) \WPML_TRANSLATE_CUSTOM_FIELD
            : self::WPML_TRANSLATE_CUSTOM_FIELD;
    }

    /** Whether a field type stores a value that WPML can copy across translations. */
    private static function holdsTranslatableValue(?string $field_type): bool
    {
        return $field_type === null || !in_array($field_type, self::LAYOUT_ONLY_FIELD_TYPES, true);
    }

    /**
     * Sets the WPML "translate" preference on a field payload, sub-fields included.
     *
     * An explicit preference coming from the payload always wins, so a source can
     * still opt a single field into copy/copy-once behaviour.
     */
    private static function applyTranslationPreference(array $field_data): array
    {
        $field_type = is_string($field_data['type'] ?? null) ? $field_data['type'] : null;

        if (!isset($field_data['wpml_cf_preferences']) && self::holdsTranslatableValue($field_type)) {
            $field_data['wpml_cf_preferences'] = self::fieldTranslationPreference();
        }

        if (is_array($field_data['sub_fields'] ?? null)) {
            $field_data['sub_fields'] = array_map(
                fn(mixed $sub_field): mixed => is_array($sub_field)
                    ? self::applyTranslationPreference($sub_field)
                    : $sub_field,
                $field_data['sub_fields']
            );
        }

        if (is_array($field_data['layouts'] ?? null)) {
            $field_data['layouts'] = array_map(
                fn(mixed $layout): mixed => is_array($layout)
                    ? self::applyTranslationPreference($layout)
                    : $layout,
                $field_data['layouts']
            );
        }

        return $field_data;
    }

    /**
     * Rewrites the WPML preference of every already persisted field of a group.
     *
     * `acf_update_field()` only stores the fields handed to it, so sub-fields of
     * repeaters/flexible content — and every field of a group that existed before
     * this plugin managed it — keep the preference they were given earlier,
     * including ACFML's "copy" defaults. Rewriting them here is what lets WPML stop
     * overwriting translated values: saving a field makes ACFML mirror the
     * preference into WPML's `custom_fields_translation` settings, expanding
     * sub-field name patterns to the concrete `repeater_0_subfield` meta keys.
     */
    private static function syncTranslationPreferences(int $group_id): void
    {
        if (!\function_exists('acf_update_field')) return;

        $fields = \acf_get_fields($group_id);
        if (!is_array($fields)) return;

        self::forceTranslationPreference($fields);
    }

    /** Recursively applies the "translate" preference to persisted ACF fields. */
    private static function forceTranslationPreference(array $fields): void
    {
        $preference = self::fieldTranslationPreference();

        foreach ($fields as $field) {
            if (!is_array($field)) continue;

            if (is_array($field['sub_fields'] ?? null)) {
                self::forceTranslationPreference($field['sub_fields']);
            }

            foreach (is_array($field['layouts'] ?? null) ? $field['layouts'] : [] as $layout) {
                if (is_array($layout['sub_fields'] ?? null)) {
                    self::forceTranslationPreference($layout['sub_fields']);
                }
            }

            $field_type = is_string($field['type'] ?? null) ? $field['type'] : null;
            if (!self::holdsTranslatableValue($field_type)) continue;

            if (isset($field['wpml_cf_preferences']) && (int) $field['wpml_cf_preferences'] === $preference) {
                continue;
            }

            $field['wpml_cf_preferences'] = $preference;
            \acf_update_field(\wp_slash($field));
        }
    }

    /**
     * Returns normalized ACF field payload ready for `acf_update_field()`.
     *
     * `sub_fields` are stripped here and persisted separately by `persistFields()`.
     * `acf_update_field()` writes exactly one `acf-field` post and never descends into
     * nested sub-fields: anything left in `sub_fields` would simply be serialized inside
     * the parent's `post_content`, where ACF does not look for it. See the note on
     * `persistFields()` for what that used to cause.
     */
    private static function buildCustomField(
        int $parent,
        array $field,
        int $menu_order,
        ?array $existing_field = null,
        ?string $parent_layout = null
    ): array
    {
        $field_name = $field['key'] ?? $field['name'] ?? null;
        if (!is_scalar($field_name) || trim((string) $field_name) === '') {
            throw onpage_http_exception("FieldGroup :: Field key is required", 400, 'invalid_param');
        }

        $field_data = $field;
        unset($field_data['sub_fields'], $field_data['layouts']);

        $field_data['key'] = self::getExistingFieldKey($existing_field) ?? self::buildFieldKey();
        $field_data['label'] = \sanitize_text_field((string) ($field['label'] ?? $field['name'] ?? $field_name));
        $field_data['name'] = \sanitize_key((string) $field_name);
        $field_data['type'] = \sanitize_key((string) ($field['type'] ?? 'text'));
        $field_data['parent'] = $parent;
        $field_data['menu_order'] = $menu_order;
        if ($parent_layout !== null) {
            $field_data['parent_layout'] = $parent_layout;
        }
        if ($field_data['type'] === 'flexible_content') {
            $field_data['layouts'] = self::buildLayouts($field['layouts'] ?? [], (string) $field_name, $existing_field);
        }

        if (onpage_is_wpml_active()) {
            $field_data = self::applyTranslationPreference($field_data);
        }

        return self::attachExistingFieldId($field_data, $existing_field);
    }

    /**
     * Normalizes the `layouts` of a flexible content field, keyed by layout key as ACF stores them.
     *
     * Each layout keeps the key it already has (matched by name), so the values saved with
     * it stay attached. Its `sub_fields` are left out here: like any sub-field they are
     * persisted as their own `acf-field` posts (see persistLayouts()).
     */
    private static function buildLayouts(mixed $layouts, string $field_name, ?array $existing_field): array
    {
        if (!is_array($layouts) || ($layouts !== [] && !array_is_list($layouts))) {
            throw onpage_http_exception("FieldGroup :: Flexible content field '$field_name' must have 'layouts' as a list", 400, 'invalid_param');
        }

        $existing_keys_by_name = [];
        foreach (is_array($existing_field['layouts'] ?? null) ? $existing_field['layouts'] : [] as $layout_key => $existing_layout) {
            $existing_name = is_array($existing_layout) ? ($existing_layout['name'] ?? null) : null;
            if (is_string($existing_name) && $existing_name !== '') {
                $existing_keys_by_name[$existing_name] = (string) ($existing_layout['key'] ?? $layout_key);
            }
        }

        $normalized = [];
        foreach ($layouts as $index => $layout) {
            $layout_name = is_array($layout) ? \sanitize_key((string) ($layout['name'] ?? $layout['key'] ?? '')) : '';
            if ($layout_name === '') {
                throw onpage_http_exception("FieldGroup :: Flexible content field '$field_name' layout $index must be an object with a 'name'", 400, 'invalid_param');
            }

            $layout_key = $existing_keys_by_name[$layout_name] ?? 'layout_' . substr(md5(\wp_generate_uuid4()), 0, 13);
            $normalized[$layout_key] = [
                'key' => $layout_key,
                'name' => $layout_name,
                'label' => \sanitize_text_field((string) ($layout['label'] ?? $layout_name)),
                'display' => in_array($layout['display'] ?? null, ['block', 'table', 'row'], true) ? $layout['display'] : 'block',
                'min' => $layout['min'] ?? '',
                'max' => $layout['max'] ?? '',
            ];
        }

        return $normalized;
    }

    /**
     * Persists the sub-fields of each layout of a saved flexible content field.
     *
     * ACF keeps them all under the flexible field, each tagged with its `parent_layout`.
     * Each layout is persisted like any level of fields, and the sub-fields of a layout the
     * payload dropped are removed with it.
     */
    private static function persistLayouts(array $flexible_field, array $payload_layouts): void
    {
        $layout_keys_by_name = [];
        foreach (is_array($flexible_field['layouts'] ?? null) ? $flexible_field['layouts'] : [] as $layout_key => $layout) {
            if (is_array($layout) && is_string($layout['name'] ?? null)) {
                $layout_keys_by_name[$layout['name']] = (string) ($layout['key'] ?? $layout_key);
            }
        }

        $kept_layout_keys = [];
        foreach ($payload_layouts as $layout) {
            $layout_name = \sanitize_key((string) ($layout['name'] ?? $layout['key'] ?? ''));
            $layout_key = $layout_keys_by_name[$layout_name] ?? null;
            if ($layout_key === null) continue;

            $kept_layout_keys[$layout_key] = true;
            $sub_fields = is_array($layout['sub_fields'] ?? null) ? $layout['sub_fields'] : [];
            self::persistFields($flexible_field, $sub_fields, $layout_key);
        }

        foreach (\acf_get_fields($flexible_field) ?: [] as $sub_field) {
            $parent_layout = $sub_field['parent_layout'] ?? null;
            if (!is_string($parent_layout) || isset($kept_layout_keys[$parent_layout])) continue;

            $field_id = $sub_field['ID'] ?? null;
            if (is_numeric($field_id) && (int) $field_id > 0 && !\acf_delete_field((int) $field_id)) {
                throw onpage_http_exception("FieldGroup :: Failed to delete field with name '" . ($sub_field['name'] ?? '') . "'", 500, 'delete_failed');
            }
        }
    }

    /**
     * Persists one level of fields under a parent, then recurses into their sub-fields.
     *
     * ACF stores every field, sub-fields included, as its own `acf-field` post whose
     * `post_parent` is the field above it; `acf_update_field()` saves one such post and
     * stops there. Handing it a field with nested `sub_fields` therefore created the
     * parent and no children at all, leaving a serialized copy of the sub-fields inside
     * the parent's `post_content` — which ACF ignores, because a `group` reloads its
     * sub-fields with `acf_get_fields()`. The symptom was a group that answered `200`
     * and then read back with every sub-field collapsed onto an empty key, while a
     * repeater defined the same way appeared to work. Saving each level explicitly, the
     * way ACF's own import does, is what makes a `group` usable.
     *
     * Order matters: the parent is saved before its children, because `acf_update_field()`
     * resolves a parent reference against a field that must already exist.
     *
     * @param int|array   $parent        The field group ID at the top level, the parent field below it.
     * @param string|null $parent_layout The layout key when the level is one layout of a flexible content field.
     */
    private static function persistFields(int|array $parent, array $fields, ?string $parent_layout = null): void
    {
        $existing_fields_by_name = self::getExistingFieldsByName($parent, $parent_layout);
        $parent_id = is_array($parent) ? (int) ($parent['ID'] ?? 0) : $parent;

        $saved_field_names = [];
        $menu_order = 0;

        // The whole level is normalized before anything is written. A malformed entry has
        // to stop the request while the group is still intact: writing as we validate would
        // leave the fields before it already rewritten, and — worse — a skipped entry never
        // reaches `$saved_field_names`, so the cleanup below would delete its field as if
        // the payload had dropped it on purpose.
        $prepared = [];

        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                throw onpage_http_exception(
                    "FieldGroup :: Field $index must be an object",
                    400,
                    'invalid_param'
                );
            }

            $field_name = \sanitize_key((string) ($field['key'] ?? $field['name'] ?? ''));
            $prepared[] = [
                'name' => $field_name,
                'data' => self::buildCustomField(
                    $parent_id,
                    $field,
                    $menu_order,
                    $existing_fields_by_name[$field_name] ?? null,
                    $parent_layout
                ),
                'sub_fields' => $field['sub_fields'] ?? null,
                'layouts' => $field['layouts'] ?? null,
            ];

            $menu_order++;
        }

        foreach ($prepared as $entry) {
            $saved_field = \acf_update_field($entry['data']);
            if (!$saved_field) {
                throw onpage_http_exception(
                    "FieldGroup :: Failed to save field with name '{$entry['data']['name']}'",
                    500,
                    'acf_error'
                );
            }

            $saved_field_names[$entry['name']] = true;

            if (is_array($entry['sub_fields']) && $entry['sub_fields'] !== []) {
                self::persistFields($saved_field, $entry['sub_fields']);
            }

            if ($entry['data']['type'] === 'flexible_content') {
                self::persistLayouts($saved_field, is_array($entry['layouts']) ? $entry['layouts'] : []);
            }
        }

        // Fields dropped from the payload are removed. Deleting a parent takes its
        // sub-fields with it, so there is no need to descend into what is being deleted.
        foreach ($existing_fields_by_name as $field_name => $field) {
            if (isset($saved_field_names[$field_name])) continue;

            $field_id = $field['ID'] ?? null;
            if (!is_numeric($field_id) || (int) $field_id <= 0) continue;

            if (!\acf_delete_field((int) $field_id)) {
                throw onpage_http_exception(
                    "FieldGroup :: Failed to delete field with name '$field_name'",
                    500,
                    'delete_failed'
                );
            }
        }
    }

    /** Returns normalized payload for `acf_update_field_group()`. */
    private static function buildFieldGroup(array $params, string $title, string $key, ?array $existing_field_group = null): array
    {
        $data = [
            'key' => $key,
            'title' => $title,
            'active' => array_key_exists('active', $params) ? (bool) $params['active'] : true,
            'location' => empty($params['locations']) ? [] : [$params['locations']],
            'position' => $params['position'] ?? 'normal',
            'style' => $params['style'] ?? 'default',
            'label_placement' => $params['label_placement'] ?? 'top',
            'instruction_placement' => $params['instruction_placement'] ?? 'label',
            'hide_on_screen' => $params['hide_on_screen'] ?? [],
            'show_in_rest' => true,
            'description' => $params['description'] ?? '',
            'menu_order' => isset($params['menu_order']) ? (int) $params['menu_order'] : 0,
        ];

        if (!empty($existing_field_group['ID'])) {
            $data['ID'] = (int) $existing_field_group['ID'];
        }

        if (onpage_is_wpml_active()) {
            $data['acfml_field_group_mode'] = self::ACFML_FIELD_GROUP_MODE;
        }

        return $data;
    }

    /** Returns all of ACF field groups. */
    public static function listItems(): array
    {
        return \acf_get_field_groups();
    }

    /** Creates or updates one field group from the raw controller payload and returns its ID. */
    public static function insertFromParams(array $params): int
    {
        $title = $params['title'] ?? null;
        if (!is_string($title) || $title === '') {
            throw onpage_http_exception("FieldGroup :: 'title' is required", 400, 'missing_title');
        }

        // `fields` missing or null leaves the group's fields as they are, so a call that only
        // updates the title or the description cannot wipe them; `[]` removes them all.
        $fields = $params['fields'] ?? null;
        if ($fields !== null && (!is_array($fields) || ($fields !== [] && !array_is_list($fields)))) {
            throw onpage_http_exception("FieldGroup :: Parameter 'fields' must be a list", 400, 'invalid_param');
        }

        $key = $params['key'] ?? null;

        // An existing group matching either the provided key or the title is updated in place.
        $existing_field_group = ($key !== null ? self::findByKey($key) : null) ?? self::findByTitle($title);

        if ($key === null) {
            // Reuse the matched group's key, otherwise derive one from the title.
            $key = $existing_field_group['key'] ?? self::generateFieldGroupKey($title);
            // A derived key may itself collide with another group — update that one rather than duplicate.
            $existing_field_group ??= self::findByKey($key);
        }

        $result = \acf_update_field_group(self::buildFieldGroup($params, $title, $key, $existing_field_group));
        if (!$result) {
            throw onpage_http_exception("FieldGroup :: Failed to save Field Group", 500, 'acf_error');
        }

        $group_id = (int) $result['ID'];
        if ($fields !== null) {
            self::persistFields($group_id, $fields);
        }

        if (onpage_is_wpml_active()) {
            self::syncTranslationPreferences($group_id);
        }

        return $group_id;
    }

    /** Deletes a field group by numeric ID. */
    public static function deleteById(int $id, bool $ignore_missing): void
    {
        if (!\acf_get_field_group($id)) {
            if ($ignore_missing) return;

            throw onpage_http_exception("FieldGroup :: ID $id not found", 404, 'not_found');
        }

        if (!\acf_delete_field_group($id)) {
            throw onpage_http_exception("FieldGroup :: Failed to delete ID $id", 500, 'delete_failed');
        }
    }

    /** Deletes a field group by title. */
    public static function deleteByTitle(string $title, bool $ignore_missing): void
    {
        $field_group = self::findByTitle($title);
        if (!$field_group) {
            if ($ignore_missing) return;

            throw onpage_http_exception("FieldGroup :: Title '$title' not found", 404, 'not_found');
        }

        if (!\acf_delete_field_group($field_group['ID'])) {
            throw onpage_http_exception("FieldGroup :: Failed to delete FieldGroup with title '$title'", 500, 'delete_failed');
        }
    }
}
