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
    private static function getExistingFieldsByName(int|array $parent): array
    {
        $fields_by_name = [];
        $fields = \acf_get_fields($parent);
        if (!is_array($fields)) return $fields_by_name;

        foreach ($fields as $field) {
            $field_name = $field['name'] ?? null;
            if (!is_string($field_name) || $field_name === '') continue;

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
        ?array $existing_field = null
    ): array
    {
        $field_name = $field['key'] ?? $field['name'] ?? null;
        if (!is_scalar($field_name) || trim((string) $field_name) === '') {
            throw httpException("FieldGroup :: Field key is required", 400, 'invalid_param');
        }

        $field_data = $field;
        unset($field_data['sub_fields']);

        $field_data['key'] = self::getExistingFieldKey($existing_field) ?? self::buildFieldKey();
        $field_data['label'] = \sanitize_text_field((string) ($field['label'] ?? $field['name'] ?? $field_name));
        $field_data['name'] = \sanitize_key((string) $field_name);
        $field_data['type'] = \sanitize_key((string) ($field['type'] ?? 'text'));
        $field_data['parent'] = $parent;
        $field_data['menu_order'] = $menu_order;

        if (isWpmlActive()) {
            $field_data = self::applyTranslationPreference($field_data);
        }

        return self::attachExistingFieldId($field_data, $existing_field);
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
     * @param int|array $parent The field group ID at the top level, the parent field below it.
     */
    private static function persistFields(int|array $parent, array $fields): void
    {
        $existing_fields_by_name = self::getExistingFieldsByName($parent);
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
                throw httpException(
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
                    $existing_fields_by_name[$field_name] ?? null
                ),
                'sub_fields' => $field['sub_fields'] ?? null,
            ];

            $menu_order++;
        }

        foreach ($prepared as $entry) {
            $saved_field = \acf_update_field($entry['data']);
            if (!$saved_field) {
                throw httpException(
                    "FieldGroup :: Failed to save field with name '{$entry['data']['name']}'",
                    500,
                    'acf_error'
                );
            }

            $saved_field_names[$entry['name']] = true;

            if (is_array($entry['sub_fields']) && $entry['sub_fields'] !== []) {
                self::persistFields($saved_field, $entry['sub_fields']);
            }
        }

        // Fields dropped from the payload are removed. Deleting a parent takes its
        // sub-fields with it, so there is no need to descend into what is being deleted.
        foreach ($existing_fields_by_name as $field_name => $field) {
            if (isset($saved_field_names[$field_name])) continue;

            $field_id = $field['ID'] ?? null;
            if (!is_numeric($field_id) || (int) $field_id <= 0) continue;

            if (!\acf_delete_field((int) $field_id)) {
                throw httpException(
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

        if (isWpmlActive()) {
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
            throw httpException("FieldGroup :: 'title' is required", 400, 'missing_title');
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
            throw httpException("FieldGroup :: Failed to save Field Group", 500, 'acf_error');
        }

        $group_id = (int) $result['ID'];
        self::persistFields($group_id, is_array($params['fields'] ?? null) ? $params['fields'] : []);

        if (isWpmlActive()) {
            self::syncTranslationPreferences($group_id);
        }

        return $group_id;
    }

    /** Deletes a field group by numeric ID. */
    public static function deleteById(int $id, bool $ignore_missing): void
    {
        if (!$ignore_missing) {
            $field_group = \acf_get_field_group($id);
            if (!$field_group) {
                throw httpException("FieldGroup :: ID $id not found", 404, 'not_found');
            }
        }

        if (!\acf_delete_field_group($id)) {
            throw httpException("FieldGroup :: Failed to delete ID $id", 500, 'delete_failed');
        }
    }

    /** Deletes a field group by title. */
    public static function deleteByTitle(string $title, bool $ignore_missing): void
    {
        $field_group = self::findByTitle($title);
        if (!$field_group) {
            if ($ignore_missing) return;

            throw httpException("FieldGroup :: Title '$title' not found", 404, 'not_found');
        }

        if (!\acf_delete_field_group($field_group['ID'])) {
            throw httpException("FieldGroup :: Failed to delete FieldGroup with title '$title'", 500, 'delete_failed');
        }
    }
}
