<?php



namespace OnPage\Services;



class Acf
{
    private const FIELD_TYPE_CONTEXTS = ['post', 'term'];

    /** Cached ACF field types indexed globally and by field group location scope. */
    private static ?array $fieldTypeMap = null;

    /** Field type contexts already loaded into the cache. */
    private static array $loadedFieldTypeContexts = [];



    /** Returns the post/term scopes declared by an ACF field group's location rules. */
    private static function getFieldGroupScopes(array $field_group): array
    {
        $scopes = [];

        foreach ($field_group['location'] ?? [] as $rule_group) {
            if (!is_array($rule_group)) continue;

            foreach ($rule_group as $rule) {
                if (!is_array($rule)) continue;

                $operator = $rule['operator'] ?? '==';
                if ($operator !== '==') continue;

                $param = $rule['param'] ?? null;
                $value = $rule['value'] ?? null;
                if (!is_scalar($value) || (string) $value === '') continue;

                if ($param === 'post_type') {
                    $scopes[] = ['context' => 'post', 'target' => (string) $value];
                }

                if ($param === 'taxonomy') {
                    $scopes[] = ['context' => 'term', 'target' => (string) $value];
                }
            }
        }

        return $scopes;
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

            $scoped_type = self::$fieldTypeMap[$context][$target][$field_name] ?? null;
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
            if (array_key_exists($field_name, $field_group_map)) {
                return $field_group_map[$field_name];
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
            if (isset($field_map[$field_name]) && is_array($field_map[$field_name])) {
                return $field_map[$field_name];
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
            if (isset($field_group_map[$field_name]) && is_array($field_group_map[$field_name])) {
                return $field_group_map[$field_name];
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

            $scoped_key = self::$fieldTypeMap[$context . '_keys'][$target][$field_name] ?? null;
            return is_string($scoped_key) ? $scoped_key : null;
        }

        self::loadFieldTypeMap();

        foreach (self::$fieldTypeMap['group_keys'] ?? [] as $field_group_map) {
            if (array_key_exists($field_name, $field_group_map)) {
                return $field_group_map[$field_name];
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
     *  - `image`/`file` with an int value: treated as an existing attachment ID. For
     *    `post` context it is also linked to the parent post, same as the URL case.
     *  - `repeater`: validated (must be a list of row objects), recursed into; sub-field
     *    `image`/`file` URLs/attachment IDs resolved the same way, nested repeaters supported.
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
            if (in_array($field_type, ['image', 'file'], true) && (is_string($value) || is_int($value))) {
                $attachment_id = self::resolveMediaValueToAttachment($value, $object_id, $field_key, $context);
                if ($attachment_id !== null) {
                    $value = $attachment_id;
                }
            } elseif ($field_type === 'repeater' && is_array($field)) {
                $value = self::resolveRepeaterValue($field, $value, $object_id, $field_key, $context);
            }
        }

        if ($field !== null && \function_exists('acf_update_value')) {
            if (\acf_update_value($value, $object_id, $field) !== false) return;
            if (self::valuePersisted($value, (string) $field['name'], $object_id)) return;

            throw httpException("Failed to assign ACF field '$field_key'", 500, 'acf_error');
        }

        if ($context !== null && $target !== null) {
            throw httpException("ACF field '$field_key' not found for $context '$target'", 400, 'invalid_param');
        }

        $selector = self::getFieldKey($field_key, $context, $target) ?? $field_key;

        if (\update_field($selector, $value, $object_id) !== false) return;
        if (self::valuePersisted($value, $selector, $object_id)) return;

        throw httpException("Failed to assign ACF field '$field_key'", 500, 'acf_error');
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
     * - Skips `tab` sub-fields (UI separators).
     * - Recurses into nested repeater sub-fields.
     * - Resolves `image`/`file` sub-field URLs/attachment IDs into Media Library attachment
     *   IDs, reusing `resolveMediaValueToAttachment` so post-context attachments stay linked
     *   to their parent post.
     * - Passes all other sub-field values through untouched.
     */
    private static function resolveRepeaterValue(
        array $field_def,
        mixed $value,
        int|string $object_id,
        string $path,
        ?string $context
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw httpException(
                "Repeater '$path' must be a list of rows",
                400,
                'invalid_param'
            );
        }

        $sub_fields_by_name = [];
        foreach (is_array($field_def['sub_fields'] ?? null) ? $field_def['sub_fields'] : [] as $sub_field) {
            if (!is_array($sub_field)) continue;

            $sub_name = $sub_field['name'] ?? null;
            if (is_string($sub_name) && $sub_name !== '') {
                $sub_fields_by_name[$sub_name] = $sub_field;
            }
        }

        $resolved_rows = [];
        foreach ($value as $row_index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw httpException(
                    "Repeater '$path' row $row_index must be an object",
                    400,
                    'invalid_param'
                );
            }

            $resolved_row = [];
            foreach ($row as $sub_name => $sub_value) {
                if (!is_string($sub_name)) continue;

                $sub_def = $sub_fields_by_name[$sub_name] ?? null;
                $sub_type = is_array($sub_def) ? ($sub_def['type'] ?? null) : null;
                $sub_path = "$path[$row_index][$sub_name]";

                if ($sub_type === 'tab') continue;

                if ($sub_type === 'repeater' && is_array($sub_def)) {
                    $resolved_row[$sub_name] = self::resolveRepeaterValue($sub_def, $sub_value, $object_id, $sub_path, $context);
                    continue;
                }

                if (in_array($sub_type, ['image', 'file'], true) && (is_string($sub_value) || is_int($sub_value)) && $sub_value !== '') {
                    $attachment_id = self::resolveMediaValueToAttachment($sub_value, $object_id, $sub_name, $context);
                    if ($attachment_id !== null) {
                        $resolved_row[$sub_name] = $attachment_id;
                        continue;
                    }
                }

                $resolved_row[$sub_name] = $sub_value;
            }

            $resolved_rows[] = $resolved_row;
        }

        return $resolved_rows;
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
