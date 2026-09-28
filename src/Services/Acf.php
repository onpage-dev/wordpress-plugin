<?php



namespace OnPage\Services;



class Acf
{
    private const FIELD_TYPE_CONTEXTS = ['post', 'term'];
    /** ACF location rules that match posts of any type. */
    private const POST_LOCATION_PARAMS = ['post', 'post_template', 'post_status', 'post_format', 'post_category', 'post_taxonomy'];
    /** ACF location rules that only match pages. */
    private const PAGE_LOCATION_PARAMS = ['page', 'page_template', 'page_type', 'page_parent'];

    /** Cached ACF field types indexed globally and by field group location scope. */
    private static ?array $fieldTypeMap = null;

    /** Field type contexts already loaded into the cache. */
    private static array $loadedFieldTypeContexts = [];



    /**
     * Returns the post/term scopes declared by an ACF field group's location rules.
     *
     * ACF shows a group when any rule group matches, and a rule group matches when all its
     * rules do. Each rule group is therefore resolved on its own to the post types and the
     * taxonomies it can match: `==` narrows the set, `!=` removes one, `all` keeps every
     * registered one. A rule group without a `post_type` rule but with a post rule
     * (`post_template`, `post_category`…) matches every post type, and a page rule
     * (`page_template`…) matches `page`. Rules this API cannot target (options pages,
     * users, menus…) add no scope.
     */
    private static function getFieldGroupScopes(array $field_group): array
    {
        $scopes = [];

        foreach ($field_group['location'] ?? [] as $rule_group) {
            if (!is_array($rule_group)) continue;

            foreach (self::getRuleGroupTargets($rule_group, 'post') as $target) {
                $scopes[] = ['context' => 'post', 'target' => $target];
            }

            foreach (self::getRuleGroupTargets($rule_group, 'term') as $target) {
                $scopes[] = ['context' => 'term', 'target' => $target];
            }
        }

        return array_values(array_unique($scopes, SORT_REGULAR));
    }

    /**
     * The post types (`post` context) or taxonomies (`term` context) one ACF rule group can match.
     *
     * @return string[]
     */
    private static function getRuleGroupTargets(array $rule_group, string $context): array
    {
        $target_param = $context === 'post' ? 'post_type' : 'taxonomy';
        $applies = false;
        $included = null; // null: not narrowed yet, every registered target
        $excluded = [];

        foreach ($rule_group as $rule) {
            if (!is_array($rule)) continue;

            $param = $rule['param'] ?? null;
            $operator = $rule['operator'] ?? '==';
            $value = $rule['value'] ?? null;
            if (!is_scalar($value) || (string) $value === '') continue;
            $value = (string) $value;

            if ($param === $target_param) {
                $applies = true;
                if ($operator === '==') {
                    if ($value !== 'all') {
                        $included = $included === null ? [$value] : array_intersect($included, [$value]);
                    }
                } elseif ($operator === '!=') {
                    if ($value === 'all') {
                        $included = [];
                    } else {
                        $excluded[] = $value;
                    }
                }
            } elseif ($context === 'post' && in_array($param, self::PAGE_LOCATION_PARAMS, true)) {
                $applies = true;
                $included = $included === null ? ['page'] : array_intersect($included, ['page']);
            } elseif ($context === 'post' && in_array($param, self::POST_LOCATION_PARAMS, true)) {
                $applies = true;
            }
        }

        if (!$applies) {
            return [];
        }

        $candidates = $included ?? array_values($context === 'post' ? \get_post_types() : \get_taxonomies());

        return array_values(array_diff($candidates, $excluded));
    }

    /** Empty field type cache shape. */
    private static function createEmptyFieldTypeMap(): array
    {
        return [
            'groups' => [],
            'post' => [],
            'term' => [],
            'group_keys' => [],
            'post_keys' => [],
            'term_keys' => [],
            'group_fields' => [],
            'post_fields' => [],
            'term_fields' => [],
        ];
    }

    /** Keeps only supported field type contexts. */
    private static function normalizeFieldTypeContexts(array $contexts): array
    {
        $normalized = [];

        foreach ($contexts as $context) {
            if (!is_scalar($context)) continue;

            $context = (string) $context;
            if (!in_array($context, self::FIELD_TYPE_CONTEXTS, true)) continue;

            $normalized[] = $context;
        }

        return array_values(array_unique($normalized));
    }

    /** Builds the ACF field type map for the requested post/taxonomy contexts. */
    private static function buildFieldTypeMap(array $contexts): array
    {
        $map = self::createEmptyFieldTypeMap();
        $context_lookup = array_fill_keys($contexts, true);

        foreach (\acf_get_field_groups() as $field_group) {
            $group_id = $field_group['ID'] ?? $field_group['key'] ?? null;
            if ($group_id === null) continue;

            $scopes = array_values(array_filter(
                self::getFieldGroupScopes($field_group),
                fn(array $scope): bool => isset($context_lookup[$scope['context']])
            ));
            if ($scopes === []) continue;

            $fields = \acf_get_fields($field_group);
            if (!is_array($fields)) continue;

            foreach ($fields as $field) {
                $field_name = $field['name'] ?? null;
                $field_type = $field['type'] ?? null;
                $field_key = $field['key'] ?? null;

                if (!is_string($field_name) || $field_name === '' || !is_string($field_type)) {
                    continue;
                }

                $map['groups'][$group_id][$field_name] = $field_type;
                $map['group_fields'][$group_id][$field_name] = $field;
                if (is_string($field_key) && $field_key !== '') {
                    $map['group_keys'][$group_id][$field_name] = $field_key;
                }

                foreach ($scopes as $scope) {
                    $map[$scope['context']][$scope['target']][$field_name] = $field_type;
                    $map[$scope['context'] . '_fields'][$scope['target']][$field_name] = $field;
                    if (is_string($field_key) && $field_key !== '') {
                        $map[$scope['context'] . '_keys'][$scope['target']][$field_name] = $field_key;
                    }
                }
            }
        }

        return $map;
    }

    /** Merges a partial field type map into the request cache. */
    private static function mergeFieldTypeMap(array $map): void
    {
        self::$fieldTypeMap ??= self::createEmptyFieldTypeMap();

        foreach ($map as $context => $context_map) {
            foreach ($context_map as $target => $field_map) {
                self::$fieldTypeMap[$context][$target] = array_merge(
                    self::$fieldTypeMap[$context][$target] ?? [],
                    $field_map
                );
            }
        }
    }

    /** Preloads the requested ACF field type contexts once per request. */
    public static function loadFieldTypeMap(array $contexts = ['post', 'term']): void
    {
        $contexts = self::normalizeFieldTypeContexts($contexts);
        if ($contexts === []) return;

        $missing_contexts = array_values(array_diff($contexts, self::$loadedFieldTypeContexts));
        if ($missing_contexts === []) return;

        self::mergeFieldTypeMap(self::buildFieldTypeMap($missing_contexts));
        self::$loadedFieldTypeContexts = array_values(array_unique(array_merge(
            self::$loadedFieldTypeContexts,
            $missing_contexts
        )));
    }

    /**
     * Returns the entry stored under a payload field name, or null.
     *
     * FieldGroup saves field names through `sanitize_key()`, which lowercases them: a field
     * declared as `MyField` is stored as `myfield`. The exact name is tried first, then its
     * sanitized form, so `acf_fields` payloads resolve whichever case they use.
     */
    private static function lookupByFieldName(array $map, string $field_name): mixed
    {
        if (array_key_exists($field_name, $map)) {
            return $map[$field_name];
        }

        $normalized_name = \sanitize_key($field_name);

        return $normalized_name !== $field_name ? ($map[$normalized_name] ?? null) : null;
    }

    /** Whether any loaded ACF field group defines the given field name, optionally scoped by entity type. */
    public static function hasField(string $field_name, ?string $context = null, ?string $target = null): bool
    {
        return self::getFieldType($field_name, $context, $target) !== null;
    }

    /** Returns the ACF field type for a loaded field name or null if not found. */
    public static function getFieldType(string $field_name, ?string $context = null, ?string $target = null): string|null
    {
        if ($context !== null && $target !== null) {
            self::loadFieldTypeMap([$context]);

            $scoped_type = self::lookupByFieldName(self::$fieldTypeMap[$context][$target] ?? [], $field_name);
            if (is_string($scoped_type)) {
                return $scoped_type;
            }

            foreach (self::$fieldTypeMap[$context . '_fields'][$target] ?? [] as $field) {
                if (is_array($field) && ($field['key'] ?? null) === $field_name && is_string($field['type'] ?? null)) {
                    return $field['type'];
                }
            }

            return null;
        }

        self::loadFieldTypeMap();

        foreach (self::$fieldTypeMap['groups'] ?? [] as $field_group_map) {
            $field_type = self::lookupByFieldName($field_group_map, $field_name);
            if (is_string($field_type)) {
                return $field_type;
            }
        }

        foreach (self::$fieldTypeMap['group_fields'] ?? [] as $field_group_map) {
            foreach ($field_group_map as $field) {
                if (is_array($field) && ($field['key'] ?? null) === $field_name && is_string($field['type'] ?? null)) {
                    return $field['type'];
                }
            }
        }

        return null;
    }

    /** Returns the scoped ACF field object for a field name/key, avoiding global key collisions. */
    public static function getFieldObject(string $field_name, ?string $context = null, ?string $target = null): array|null
    {
        if ($context !== null && $target !== null) {
            self::loadFieldTypeMap([$context]);

            $field_map = self::$fieldTypeMap[$context . '_fields'][$target] ?? [];
            $field = self::lookupByFieldName($field_map, $field_name);
            if (is_array($field)) {
                return $field;
            }

            foreach ($field_map as $field) {
                if (is_array($field) && ($field['key'] ?? null) === $field_name) {
                    return $field;
                }
            }

            return null;
        }

        self::loadFieldTypeMap();

        foreach (self::$fieldTypeMap['group_fields'] ?? [] as $field_group_map) {
            $field = self::lookupByFieldName($field_group_map, $field_name);
            if (is_array($field)) {
                return $field;
            }

            foreach ($field_group_map as $field) {
                if (is_array($field) && ($field['key'] ?? null) === $field_name) {
                    return $field;
                }
            }
        }

        return null;
    }

    /** Returns the ACF field key (`field_...`) for a loaded field name or null if not found. */
    public static function getFieldKey(string $field_name, ?string $context = null, ?string $target = null): string|null
    {
        if (str_starts_with($field_name, 'field_')) {
            return $field_name;
        }

        if ($context !== null && $target !== null) {
            self::loadFieldTypeMap([$context]);

            $scoped_key = self::lookupByFieldName(self::$fieldTypeMap[$context . '_keys'][$target] ?? [], $field_name);
            return is_string($scoped_key) ? $scoped_key : null;
        }

        self::loadFieldTypeMap();

        foreach (self::$fieldTypeMap['group_keys'] ?? [] as $field_group_map) {
            $field_key = self::lookupByFieldName($field_group_map, $field_name);
            if (is_string($field_key)) {
                return $field_key;
            }
        }

        return null;
    }

    /**
     * Saves a value for an ACF field on a post, term, user, option or other ACF object.
     *
     * Complex field-type handling (shared by every endpoint that writes `acf_fields`):
     *  - `tab`:     UI separator, no stored value → silently skipped.
     *  - `image`/`file` with a string URL value: imported into Media Library and replaced
     *    with its `attachment_id`. For `post` context the attachment is also linked to
     *    the parent post (`RemoteMedia::urlToPost`); for other contexts it is just
     *    imported (`RemoteMedia::urlToMediaLibrary`).
     *  - `image`/`file` with an int (or numeric string) value: treated as an existing
     *    attachment ID. For `post` context it is also linked to the parent post, same as
     *    the URL case.
     *  - `image`/`file` with a value that is neither an existing attachment ID nor a valid
     *    URL: rejected with `400 input_invalid`, like the `files` payload.
     *  - `repeater`: validated (must be a list of row objects), recursed into; sub-field
     *    `image`/`file` URLs/attachment IDs resolved the same way, nested repeaters and
     *    groups supported.
     *  - `group`: validated (must be an object), its sub-fields resolved like a repeater row.
     *  - `flexible_content`: validated (a list of rows naming a layout in `acf_fc_layout`),
     *    each row's sub-fields resolved against its layout like a repeater row.
     */
    public static function updateFieldValue(
        int|string $object_id,
        string $field_key,
        mixed $value,
        ?string $context = null,
        ?string $target = null
    ): void
    {
        $field = self::getFieldObject($field_key, $context, $target);
        $field_type = is_array($field) ? ($field['type'] ?? null) : null;

        if ($field_type === 'tab') return;

        if ($value !== null && $value !== '') {
            // `0` / `"0"` clear the field, as ACF stores an empty media field.
            if (in_array($field_type, ['image', 'file'], true) && (is_string($value) || is_int($value)) && !self::isMediaClearValue($value)) {
                $value = self::requireMediaAttachment($value, $object_id, $field_key, $context);
            } elseif ($field_type === 'repeater' && is_array($field)) {
                $value = self::resolveRepeaterValue($field, $value, $object_id, $field_key, $context);
            } elseif ($field_type === 'group' && is_array($field)) {
                $value = self::resolveGroupValue($field, $value, $object_id, $field_key, $context);
            } elseif ($field_type === 'flexible_content' && is_array($field)) {
                $value = self::resolveFlexibleValue($field, $value, $object_id, $field_key, $context);
            }
        }

        if ($field !== null && \function_exists('acf_update_value')) {
            if (\acf_update_value($value, $object_id, $field) !== false) return;
            if (self::valuePersisted($value, (string) $field['name'], $object_id)) return;

            throw onpage_http_exception("Failed to assign ACF field '$field_key'", 500, 'acf_error');
        }

        if ($context !== null && $target !== null) {
            throw onpage_http_exception("ACF field '$field_key' not found for $context '$target'", 400, 'invalid_param');
        }

        $selector = self::getFieldKey($field_key, $context, $target) ?? $field_key;

        if (\update_field($selector, $value, $object_id) !== false) return;
        if (self::valuePersisted($value, $selector, $object_id)) return;

        throw onpage_http_exception("Failed to assign ACF field '$field_key'", 500, 'acf_error');
    }

    /** Whether an `image`/`file` value is ACF's "no attachment" value (`0` or `"0"`). */
    private static function isMediaClearValue(string|int $value): bool
    {
        return $value === 0 || (is_string($value) && trim($value) === '0');
    }

    /**
     * Resolves an `image`/`file` value to an attachment ID, or throws `400 input_invalid`.
     *
     * Writing an unresolvable value (a malformed URL, the ID of a deleted attachment) raw
     * left the field pointing at nothing while the request answered 200. Same rule and
     * message as the `files` payload (Post::resolveRemoteFiles()).
     *
     * @param string      $path       Field path used in the error message.
     * @param string|null $import_key Key the imported file is named after (defaults to $path).
     */
    private static function requireMediaAttachment(
        string|int $value,
        int|string $object_id,
        string $path,
        ?string $context,
        ?string $import_key = null
    ): int {
        // A numeric string is an attachment ID sent as text, as ACF itself stores it.
        if (is_string($value) && ctype_digit(trim($value))) {
            $value = (int) trim($value);
        }

        $attachment_id = self::resolveMediaValueToAttachment($value, $object_id, $import_key ?? $path, $context);
        if ($attachment_id === null) {
            throw onpage_http_exception("Field '$path' in acf_fields must be an existing attachment ID or a valid URL", 400, 'input_invalid');
        }

        return $attachment_id;
    }

    /**
     * Resolves an `image`/`file` field value (existing attachment ID or remote URL to
     * import) to a Media Library attachment ID, or returns null when unresolvable.
     *
     * For `post` context with an int object id the attachment is also linked to that
     * post; for any other context (term, option, …) it is just imported/verified.
     */
    private static function resolveMediaValueToAttachment(
        string|int $value,
        int|string $object_id,
        string $field_key,
        ?string $context
    ): int|null {
        if (is_int($value)) {
            if (!RemoteMedia::isAttachmentId($value)) return null;

            if ($context === 'post' && is_int($object_id)) {
                RemoteMedia::linkMediaToPost($value, $object_id);
            }

            return $value;
        }

        $url = RemoteMedia::sanitizeUrl($value);
        if ($url === null) return null;

        if ($context === 'post' && is_int($object_id)) {
            return (int) RemoteMedia::urlToPost($url, $object_id, $field_key)['attachment_id'];
        }

        return (int) RemoteMedia::urlToMediaLibrary($url, $field_key)['attachment_id'];
    }

    /**
     * Normalizes a repeater payload before handing it to `acf_update_value()`.
     *
     * - Validates the outer shape (must be a list of row objects).
     * - Resolves each row's sub-fields with `resolveSubFieldValues()`.
     */
    private static function resolveRepeaterValue(
        array $field_def,
        mixed $value,
        int|string $object_id,
        string $path,
        ?string $context
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw onpage_http_exception(
                "Repeater '$path' must be a list of rows",
                400,
                'invalid_param'
            );
        }

        $resolved_rows = [];
        foreach ($value as $row_index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw onpage_http_exception(
                    "Repeater '$path' row $row_index must be an object",
                    400,
                    'invalid_param'
                );
            }

            $resolved_rows[] = self::resolveSubFieldValues($field_def, $row, $object_id, "{$path}[$row_index]", $context);
        }

        return $resolved_rows;
    }

    /**
     * Normalizes a flexible content payload before handing it to `acf_update_value()`.
     *
     * - Validates the outer shape: a list of rows, each an object whose `acf_fc_layout`
     *   names one of the field's layouts.
     * - Resolves each row's sub-fields against that layout with `resolveSubFieldValues()`,
     *   so `image`/`file` URLs and nested repeaters/groups work as in a repeater row.
     */
    private static function resolveFlexibleValue(
        array $field_def,
        mixed $value,
        int|string $object_id,
        string $path,
        ?string $context
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw onpage_http_exception("Flexible content '$path' must be a list of rows", 400, 'invalid_param');
        }

        $layouts_by_name = [];
        foreach (is_array($field_def['layouts'] ?? null) ? $field_def['layouts'] : [] as $layout) {
            if (is_array($layout) && is_string($layout['name'] ?? null) && $layout['name'] !== '') {
                $layouts_by_name[$layout['name']] = $layout;
            }
        }

        $resolved_rows = [];
        foreach ($value as $row_index => $row) {
            $layout_name = is_array($row) && !array_is_list($row) ? ($row['acf_fc_layout'] ?? null) : null;
            if (!is_string($layout_name) || $layout_name === '') {
                throw onpage_http_exception("Flexible content '$path' row $row_index must be an object with an 'acf_fc_layout'", 400, 'invalid_param');
            }

            $layout = self::lookupByFieldName($layouts_by_name, $layout_name);
            if (!is_array($layout)) {
                throw onpage_http_exception("Flexible content '$path' row $row_index has unknown layout '$layout_name'", 400, 'invalid_param');
            }

            unset($row['acf_fc_layout']);
            $resolved_rows[] = ['acf_fc_layout' => (string) $layout['name']]
                + self::resolveSubFieldValues($layout, $row, $object_id, "{$path}[$row_index]", $context);
        }

        return $resolved_rows;
    }

    /**
     * Normalizes a group payload before handing it to `acf_update_value()`.
     *
     * - Validates the outer shape (must be an object keyed by sub-field name; `{}` is allowed).
     * - Resolves its sub-fields with `resolveSubFieldValues()`, exactly like a repeater row.
     */
    private static function resolveGroupValue(
        array $field_def,
        mixed $value,
        int|string $object_id,
        string $path,
        ?string $context
    ): array {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw onpage_http_exception(
                "Group '$path' must be an object",
                400,
                'invalid_param'
            );
        }

        return self::resolveSubFieldValues($field_def, $value, $object_id, $path, $context);
    }

    /**
     * Resolves the sub-field values of one repeater row or one group.
     *
     * - Skips `tab` sub-fields (UI separators).
     * - Recurses into nested `repeater` and `group` sub-fields.
     * - Resolves `image`/`file` sub-field URLs/attachment IDs into Media Library attachment
     *   IDs with `requireMediaAttachment()` (post-context attachments stay linked to their
     *   parent post; unresolvable values are rejected with `400 input_invalid`).
     * - Passes all other sub-field values through untouched.
     *
     * @param string $path Path of the row or group; sub-field paths are `{$path}[name]`.
     */
    private static function resolveSubFieldValues(
        array $field_def,
        array $row,
        int|string $object_id,
        string $path,
        ?string $context
    ): array {
        $sub_fields_by_name = [];
        foreach (is_array($field_def['sub_fields'] ?? null) ? $field_def['sub_fields'] : [] as $sub_field) {
            if (!is_array($sub_field)) continue;

            $sub_name = $sub_field['name'] ?? null;
            if (is_string($sub_name) && $sub_name !== '') {
                $sub_fields_by_name[$sub_name] = $sub_field;
            }
        }

        $resolved_row = [];
        foreach ($row as $sub_name => $sub_value) {
            if (!is_string($sub_name)) continue;

            // Same case-insensitive match as top-level fields; the row is keyed by the
            // stored name, which is the one ACF resolves sub-fields by.
            $sub_def = self::lookupByFieldName($sub_fields_by_name, $sub_name);
            if (is_array($sub_def) && is_string($sub_def['name'] ?? null)) {
                $sub_name = $sub_def['name'];
            }
            $sub_type = is_array($sub_def) ? ($sub_def['type'] ?? null) : null;
            $sub_path = "{$path}[$sub_name]";

            if ($sub_type === 'tab') continue;

            $has_value = $sub_value !== null && $sub_value !== '';

            if ($sub_type === 'repeater' && is_array($sub_def) && $has_value) {
                $resolved_row[$sub_name] = self::resolveRepeaterValue($sub_def, $sub_value, $object_id, $sub_path, $context);
                continue;
            }

            if ($sub_type === 'group' && is_array($sub_def) && $has_value) {
                $resolved_row[$sub_name] = self::resolveGroupValue($sub_def, $sub_value, $object_id, $sub_path, $context);
                continue;
            }

            if ($sub_type === 'flexible_content' && is_array($sub_def) && $has_value) {
                $resolved_row[$sub_name] = self::resolveFlexibleValue($sub_def, $sub_value, $object_id, $sub_path, $context);
                continue;
            }

            if (in_array($sub_type, ['image', 'file'], true) && (is_string($sub_value) || is_int($sub_value)) && $sub_value !== '' && !self::isMediaClearValue($sub_value)) {
                $resolved_row[$sub_name] = self::requireMediaAttachment($sub_value, $object_id, $sub_path, $context, $sub_name);
                continue;
            }

            $resolved_row[$sub_name] = $sub_value;
        }

        return $resolved_row;
    }

    /**
     * Confirms an ACF write whose update function returned `false` actually left the
     * field populated.
     *
     * `acf_update_value()`/`update_field()` return `false` not only on failure but
     * also for no-op meta updates (WordPress returns false when the value is
     * unchanged), so the return value alone cannot tell success from a benign no-op.
     *
     * The check is presence-based rather than value-equality: re-reading complex
     * fields (repeater/group/flexible) through ACF normalises keys (sub-fields come
     * back keyed by field key, not name), types and formatting, so a deep comparison
     * against the submitted payload yields false mismatches. We only flag a genuine
     * failure when a non-empty value we tried to write left the field empty.
     */
    private static function valuePersisted(mixed $intended, string $selector, int|string $object_id): bool
    {
        // Clearing a field, or nothing meaningful to write, never needs confirmation.
        if ($intended === null || $intended === '' || $intended === []) {
            return true;
        }

        $stored = \get_field($selector, $object_id, false);

        return $stored !== null && $stored !== '' && $stored !== false && $stored !== [];
    }
}
