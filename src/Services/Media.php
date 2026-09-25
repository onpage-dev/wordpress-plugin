<?php



namespace OnPage\Services;



class Media
{
    private const ACTION_CREATED = 'created';
    private const ACTION_REPLACED = 'replaced';
    private const ACTION_LINKED = 'linked';
    private const ACTION_CLEARED = 'cleared';

    /** Cached SHA-256 of the attachment file, with the path/size/mtime it was computed for. */
    public const FILE_HASH_META = '_onpage_file_hash';



    /** Loads WordPress admin media helpers used by upload and sideload flows. */
    public static function loadDependencies(): void
    {
        if (!\function_exists('download_url') || !\function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!\function_exists('media_handle_sideload') || !\function_exists('wp_insert_attachment')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        if (!\function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        if (!\function_exists('wp_delete_attachment_files')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }
    }

    /** Resolves a post ID from arbitrary input and validates its existence. */
    private static function requirePostId(mixed $value, string $param_name = 'post_id'): int
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            throw onpage_http_exception("Media :: Parameter '$param_name' must be a positive integer", 400, 'invalid_param');
        }

        $post_id = (int) $value;
        if ($post_id < 1) {
            throw onpage_http_exception("Media :: Parameter '$param_name' must be a positive integer", 400, 'invalid_param');
        }

        if (!\get_post($post_id)) {
            throw onpage_http_exception("Media :: Post $post_id not found", 404, 'not_found');
        }

        return $post_id;
    }

    /** Standard API response payload for an attachment-related operation. */
    private static function buildAttachmentResult(
        string $action,
        int $attachment_id,
        string $filename,
        int $post_id,
        ?string $source_url = null
    ): array {
        $result = [
            'action' => $action,
            'attachment_id' => $attachment_id,
            'filename' => $filename,
            'title' => \get_the_title($attachment_id),
            'url' => \wp_get_attachment_url($attachment_id),
            'mime_type' => \get_post_mime_type($attachment_id),
            'post_id' => $post_id,
        ];

        if ($source_url !== null) {
            $result['source_url'] = $source_url;
        }

        return $result;
    }

    /**
     * SHA-256 checksum of the attachment's physical file, or null if the file is missing/unreadable.
     *
     * Hashing every file on every GET /media page is expensive, so the checksum is cached in
     * attachment meta together with the path, size and mtime it was computed for. Any change to
     * one of them (a replaced or edited file) makes the cache stale and the file is hashed again.
     */
    private static function hashAttachmentFile(int $attachment_id, string $file_path): ?string
    {
        if ($file_path === '' || !\is_file($file_path)) {
            return null;
        }

        $size = @\filesize($file_path);
        $mtime = @\filemtime($file_path);
        $fingerprint = [
            'file' => $file_path,
            'size' => $size !== false ? (int) $size : null,
            'mtime' => $mtime !== false ? (int) $mtime : null,
        ];

        $cached = \get_post_meta($attachment_id, self::FILE_HASH_META, true);
        if (
            is_array($cached) &&
            is_string($cached['hash'] ?? null) &&
            ($cached['file'] ?? null) === $fingerprint['file'] &&
            ($cached['size'] ?? null) === $fingerprint['size'] &&
            ($cached['mtime'] ?? null) === $fingerprint['mtime'] &&
            $fingerprint['size'] !== null &&
            $fingerprint['mtime'] !== null
        ) {
            return $cached['hash'];
        }

        $hash = \hash_file('sha256', $file_path);
        if ($hash === false) {
            return null;
        }

        if ($fingerprint['size'] !== null && $fingerprint['mtime'] !== null) {
            \update_post_meta($attachment_id, self::FILE_HASH_META, $fingerprint + ['hash' => $hash]);
        }

        return $hash;
    }

    /** Non-empty post meta value as a string, or null when absent. */
    private static function getMetaString(int $attachment_id, string $meta_key): string|null
    {
        return Input::stringOrNull(\get_post_meta($attachment_id, $meta_key, true));
    }

    /** Response payload for a media item in a list result. */
    private static function buildListResult(\WP_Post $attachment): array
    {
        $file_path = \get_attached_file($attachment->ID);
        $file_path = is_string($file_path) ? $file_path : '';

        return [
            'attachment_id' => $attachment->ID,
            'filename' => $file_path !== '' ? \wp_basename($file_path) : '',
            'title' => \get_the_title($attachment->ID),
            'url' => \wp_get_attachment_url($attachment->ID),
            'mime_type' => $attachment->post_mime_type,
            'post_id' => (int) $attachment->post_parent,
            'hash' => self::hashAttachmentFile($attachment->ID, $file_path),
            'token' => self::getMetaString($attachment->ID, RemoteMedia::TOKEN_META),
            'source_url' => self::getMetaString($attachment->ID, RemoteMedia::SOURCE_URL_META),
        ];
    }

    /** Response payload for a cleared ACF media field. */
    private static function buildClearedLinkResult(string $field_key): array
    {
        return [
            'field' => $field_key,
            'action' => self::ACTION_CLEARED,
            'attachment' => null,
        ];
    }

    /** Response payload for a linked remote media field. */
    private static function buildLinkedFieldResult(string $field_key, int $post_id, array $import_result): array
    {
        $action = $import_result['action'] === RemoteMedia::ACTION_LINKED
            ? self::ACTION_LINKED
            : self::ACTION_CREATED;

        return [
            'field' => $field_key,
            'action' => $action,
            'attachment' => self::buildAttachmentResult(
                $action,
                $import_result['attachment_id'],
                $import_result['filename'],
                $post_id,
                $import_result['source_url']
            ),
        ];
    }

    /** Response payload for an existing attachment linked directly by ID. */
    private static function buildAttachmentIdLinkResult(string $field_key, int $post_id, int $attachment_id): array
    {
        return [
            'field' => $field_key,
            'action' => self::ACTION_LINKED,
            'attachment' => self::buildAttachmentResult(
                self::ACTION_LINKED,
                $attachment_id,
                RemoteMedia::getAttachmentFilename($attachment_id),
                $post_id
            ),
        ];
    }

    /**
     * Links a media field to an ACF field, or clears it when the value is null.
     *
     * The value can be an existing attachment ID (linked directly, no download) or a
     * remote URL (imported into Media Library, or reused if already imported).
     */
    private static function urlToField(int $post_id, string $field_key, mixed $value): array
    {
        if ($value === null) {
            Acf::updateFieldValue($post_id, $field_key, null);

            return self::buildClearedLinkResult($field_key);
        }

        if (is_int($value)) {
            if (!RemoteMedia::isAttachmentId($value)) {
                throw onpage_http_exception("Media :: Field '$field_key' in files must be an existing attachment ID or a valid URL", 400, 'invalid_param');
            }

            RemoteMedia::linkMediaToPost($value, $post_id);
            Acf::updateFieldValue($post_id, $field_key, $value);

            return self::buildAttachmentIdLinkResult($field_key, $post_id, $value);
        }

        $url = RemoteMedia::sanitizeUrl($value);
        if ($url === null) {
            throw onpage_http_exception("Media :: Field '$field_key' in files must be an existing attachment ID or a valid URL", 400, 'invalid_param');
        }

        $import_result = RemoteMedia::urlToField($url, $post_id, $field_key);

        return self::buildLinkedFieldResult($field_key, $post_id, $import_result);
    }

    /**
     * Normalizes request files into a flat list, supporting single and multi-file inputs.
     *
     * @return array<int, array{field:string,key:string,file:array{name:mixed,type:mixed,tmp_name:mixed,error:mixed,size:mixed}}>
     */
    private static function normalizeFiles(array $file_params): array
    {
        $normalized = [];

        foreach ($file_params as $field => $file) {
            if (!is_array($file)) {
                continue;
            }

            $normalized = array_merge($normalized, self::flattenFileSpec($field, $file));
        }

        return $normalized;
    }

    /**
     * Recursively flattens a PHP upload structure into single-file specs.
     *
     * @return array<int, array{field:string,key:string,file:array{name:mixed,type:mixed,tmp_name:mixed,error:mixed,size:mixed}}>
     */
    private static function flattenFileSpec(string $field, array $file, string $path = ''): array
    {
        if (!array_key_exists('name', $file)) {
            return [];
        }

        if (!is_array($file['name'])) {
            return [[
                'field' => $field,
                'key' => $path,
                'file' => [
                    'name' => $file['name'] ?? '',
                    'type' => $file['type'] ?? '',
                    'tmp_name' => $file['tmp_name'] ?? '',
                    'error' => $file['error'] ?? \UPLOAD_ERR_NO_FILE,
                    'size' => $file['size'] ?? 0,
                ],
            ]];
        }

        $flattened = [];

        foreach (array_keys($file['name']) as $key) {
            $child_path = $path === '' ? (string) $key : $path . '.' . $key;
            $child = [
                'name' => $file['name'][$key] ?? '',
                'type' => $file['type'][$key] ?? '',
                'tmp_name' => $file['tmp_name'][$key] ?? '',
                'error' => $file['error'][$key] ?? \UPLOAD_ERR_NO_FILE,
                'size' => $file['size'][$key] ?? 0,
            ];

            $flattened = array_merge($flattened, self::flattenFileSpec($field, $child, $child_path));
        }

        return $flattened;
    }

    /** Validates and resolves the optional parent post ID from the request. */
    private static function resolveParentPostId(\WP_REST_Request $request): int|null
    {
        $post_id = $request->get_param('post_id');

        if ($post_id === null || $post_id === '') {
            return null;
        }

        return self::requirePostId($post_id);
    }

    /** Human-readable message for PHP upload errors. */
    private static function getUploadErrorMessage(int $error_code): string
    {
        return match ($error_code) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'File exceeds the upload size limit',
            \UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            \UPLOAD_ERR_NO_FILE => 'No file uploaded',
            \UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory',
            \UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            \UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload',
            default => 'Unknown upload error',
        };
    }

    /** Sanitized attachment title derived from the original filename. */
    private static function buildAttachmentTitle(string $filename): string
    {
        return \sanitize_text_field(\pathinfo($filename, \PATHINFO_FILENAME));
    }

    /** Original filename from an uploaded file spec. */
    private static function getFilename(array $file): string
    {
        return isset($file['name']) && is_scalar($file['name']) ? (string) $file['name'] : '';
    }

    /** Validates the PHP upload status for a file entry. */
    private static function validateUploadedFile(array $file, int $element_index): string
    {
        $filename = self::getFilename($file);
        $error_code = isset($file['error']) ? (int) $file['error'] : \UPLOAD_ERR_NO_FILE;

        if ($error_code !== \UPLOAD_ERR_OK) {
            throw onpage_http_exception(
                "Media :: Element $element_index :: Failed to upload file '$filename' :: " . self::getUploadErrorMessage($error_code),
                400,
                'upload_failed'
            );
        }

        return $filename;
    }

    /** Normalizes an attachment identifier to a positive integer. */
    private static function normalizeAttachmentId(mixed $attachment_id): int|null
    {
        if (!is_scalar($attachment_id) || !is_numeric((string) $attachment_id)) {
            return null;
        }

        $resolved_attachment_id = (int) $attachment_id;

        return $resolved_attachment_id > 0 ? $resolved_attachment_id : null;
    }

    /** Whether a REST parameter value was provided and is not an empty string. */
    private static function hasParamValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    /** Normalizes an attachment ID parameter or throws with the provided message. */
    private static function requireAttachmentIdParam(mixed $value, string $message): int
    {
        $attachment_id = self::normalizeAttachmentId($value);
        if ($attachment_id === null) {
            throw onpage_http_exception($message, 400, 'invalid_param');
        }

        return $attachment_id;
    }

    /** Whether the given post exists and is a media attachment. */
    private static function isAttachment(int $attachment_id): bool
    {
        $post = \get_post($attachment_id);

        return $post !== null && $post->post_type === 'attachment';
    }

    /** Returns the attachment post object or throws when the ID is missing/invalid. */
    private static function requireAttachment(int $attachment_id, string $context): \WP_Post
    {
        $attachment = \get_post($attachment_id);

        if (!$attachment instanceof \WP_Post || $attachment->post_type !== 'attachment') {
            throw onpage_http_exception("Media :: $context :: Attachment $attachment_id not found", 404, 'not_found');
        }

        return $attachment;
    }

    /** Normalizes the raw `attachment_ids` request value into an array. */
    private static function normalizeAttachmentIdsParam(mixed $attachment_ids_param): array
    {
        if (is_string($attachment_ids_param)) {
            $decoded = \json_decode($attachment_ids_param, true);
            if (\json_last_error() === \JSON_ERROR_NONE) {
                $attachment_ids_param = $decoded;
            }
        }

        if (!is_array($attachment_ids_param)) {
            throw onpage_http_exception("Media :: Parameter 'attachment_ids' must be an array", 400, 'invalid_param');
        }

        return $attachment_ids_param;
    }

    /** Resolves the single-file `attachment_id` replacement parameter. */
    private static function resolveSingleReplacementAttachmentId(mixed $attachment_id_param, int $file_count): array
    {
        if ($file_count !== 1) {
            throw onpage_http_exception("Media :: Parameter 'attachment_id' can only be used with a single uploaded file", 400, 'invalid_param');
        }

        return [
            0 => self::requireAttachmentIdParam(
                $attachment_id_param,
                "Media :: Parameter 'attachment_id' must be a positive integer"
            ),
        ];
    }

    /** Resolves the multi-file `attachment_ids` replacement parameter. */
    private static function resolveMultipleReplacementAttachmentIds(mixed $attachment_ids_param): array
    {
        $attachment_ids_param = self::normalizeAttachmentIdsParam($attachment_ids_param);

        $resolved_attachment_ids = [];

        foreach ($attachment_ids_param as $i => $value) {
            if ($value === null || $value === '') {
                $resolved_attachment_ids[(int) $i] = null;
                continue;
            }

            $resolved_attachment_ids[(int) $i] = self::requireAttachmentIdParam(
                $value,
                "Media :: attachment_ids[$i] must be a positive integer"
            );
        }

        return $resolved_attachment_ids;
    }

    /**
     * Resolves optional replacement attachment IDs for uploaded files.
     *
     * Supports:
     * - `attachment_id=<id>` for single-file requests
     * - `attachment_ids[]=<id>` for multi-file requests
     * - `attachment_ids` as JSON array string
     *
     * @return array<int, int|null> Replacement attachment IDs keyed by upload index.
     */
    private static function resolveReplacementAttachmentIds(\WP_REST_Request $request, int $file_count): array
    {
        $single_attachment_id = $request->get_param('attachment_id');
        $attachment_ids_param = $request->get_param('attachment_ids');
        $has_single_attachment_id = self::hasParamValue($single_attachment_id);
        $has_attachment_ids = self::hasParamValue($attachment_ids_param);

        if ($has_single_attachment_id && $has_attachment_ids) {
            throw onpage_http_exception("Media :: Use either 'attachment_id' or 'attachment_ids', not both", 400, 'invalid_param');
        }

        if ($has_single_attachment_id) {
            return self::resolveSingleReplacementAttachmentId($single_attachment_id, $file_count);
        }

        if (!$has_attachment_ids) {
            return [];
        }

        return self::resolveMultipleReplacementAttachmentIds($attachment_ids_param);
    }

    /** Normalizes a storage token parameter or throws with the provided message. */
    private static function requireTokenParam(mixed $value, string $message): string
    {
        $token = Input::stringOrNull($value);
        if ($token === null) {
            throw onpage_http_exception($message, 400, 'invalid_param');
        }

        return $token;
    }

    /** Normalizes the raw `tokens` request value into an array. */
    private static function normalizeTokensParam(mixed $tokens_param): array
    {
        if (is_string($tokens_param)) {
            $decoded = \json_decode($tokens_param, true);
            if (\json_last_error() === \JSON_ERROR_NONE) {
                $tokens_param = $decoded;
            }
        }

        if (!is_array($tokens_param)) {
            throw onpage_http_exception("Media :: Parameter 'tokens' must be an array", 400, 'invalid_param');
        }

        return $tokens_param;
    }

    /** Resolves the single-file `token` parameter. */
    private static function resolveSingleToken(mixed $token_param, int $file_count): array
    {
        if ($file_count !== 1) {
            throw onpage_http_exception("Media :: Parameter 'token' can only be used with a single uploaded file", 400, 'invalid_param');
        }

        return [
            0 => self::requireTokenParam(
                $token_param,
                "Media :: Parameter 'token' must be a non-empty string"
            ),
        ];
    }

    /** Resolves the multi-file `tokens` parameter. */
    private static function resolveMultipleTokens(mixed $tokens_param): array
    {
        $tokens_param = self::normalizeTokensParam($tokens_param);

        $resolved_tokens = [];

        foreach ($tokens_param as $i => $value) {
            if ($value === null || $value === '') {
                $resolved_tokens[(int) $i] = null;
                continue;
            }

            $resolved_tokens[(int) $i] = self::requireTokenParam(
                $value,
                "Media :: tokens[$i] must be a non-empty string"
            );
        }

        return $resolved_tokens;
    }

    /**
     * Resolves the optional On Page® storage tokens for uploaded files.
     *
     * Same shape as the replacement attachment IDs:
     * - `token=<segment>` for single-file requests
     * - `tokens[]=<segment>` for multi-file requests
     * - `tokens` as JSON array string
     *
     * The value is the storage segment `<token>[.<format>]` the file was downloaded from; it is
     * stored verbatim on the attachment so the same file can be found again without re-uploading it.
     *
     * @return array<int, string|null> Storage tokens keyed by upload index.
     */
    private static function resolveUploadTokens(\WP_REST_Request $request, int $file_count): array
    {
        $single_token = $request->get_param('token');
        $tokens_param = $request->get_param('tokens');
        $has_single_token = self::hasParamValue($single_token);
        $has_tokens = self::hasParamValue($tokens_param);

        if ($has_single_token && $has_tokens) {
            throw onpage_http_exception("Media :: Use either 'token' or 'tokens', not both", 400, 'invalid_param');
        }

        if ($has_single_token) {
            return self::resolveSingleToken($single_token, $file_count);
        }

        if (!$has_tokens) {
            return [];
        }

        return self::resolveMultipleTokens($tokens_param);
    }

    /** Effective parent post ID after create/replace operations. */
    private static function resolveEffectiveParentPostId(?int $requested_post_id, ?\WP_Post $attachment = null): int
    {
        if ($requested_post_id !== null) {
            return $requested_post_id;
        }

        if ($attachment instanceof \WP_Post) {
            return (int) $attachment->post_parent;
        }

        return 0;
    }

    /** Persists attachment metadata after file creation or replacement. */
    private static function updateAttachmentMetadata(int $attachment_id, string $file_path): void
    {
        $metadata = \wp_generate_attachment_metadata($attachment_id, $file_path);
        if (is_array($metadata)) {
            \wp_update_attachment_metadata($attachment_id, $metadata);
        }
    }

    /** Standard API response payload for an uploaded/replaced attachment. */
    private static function buildUploadResult(string $action, int $attachment_id, string $filename, int $post_id, ?string $token = null): array
    {
        $result = self::buildAttachmentResult($action, $attachment_id, $filename, $post_id);

        if ($token !== null) {
            $result['token'] = $token;
        }

        return $result;
    }

    /** Moves one uploaded file into WordPress uploads. */
    private static function handleUpload(array $file, int $element_index, string $filename, string $operation): array
    {
        // WordPress rejects SVG by default; when another plugin allows it, never store it unsanitized.
        if (Svg::isFilename($filename) && isset($file['tmp_name']) && is_string($file['tmp_name'])) {
            Svg::sanitizeFile($file['tmp_name'], "Media :: Element $element_index :: Failed to $operation file '$filename'");
        }

        $uploaded_file = \wp_handle_upload($file, ['test_form' => false]);
        if (!empty($uploaded_file['error'])) {
            throw onpage_http_exception(
                "Media :: Element $element_index :: Failed to $operation file '$filename' :: {$uploaded_file['error']}",
                500,
                'upload_failed'
            );
        }

        return $uploaded_file;
    }

    /** Creates a new media attachment from an uploaded file. */
    private static function createAttachment(array $uploaded_file, string $filename, int $element_index, int $post_id, ?string $token = null): array
    {
        $attachment_id = \wp_insert_attachment([
            'post_mime_type' => $uploaded_file['type'] ?? '',
            'post_title' => self::buildAttachmentTitle($filename),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $post_id,
        ], $uploaded_file['file'], $post_id);

        if (\is_wp_error($attachment_id)) {
            throw onpage_http_exception(
                "Media :: Element $element_index :: Failed to create attachment for file '$filename' :: " . $attachment_id->get_error_message(),
                500,
                'request_failed'
            );
        }

        self::updateAttachmentMetadata($attachment_id, $uploaded_file['file']);

        if ($token !== null) {
            RemoteMedia::setAttachmentToken($attachment_id, $token);
        }

        return self::buildUploadResult(self::ACTION_CREATED, $attachment_id, $filename, $post_id, $token);
    }

    /** Updates attachment fields after a file replacement. */
    private static function updateAttachmentRecord(int $attachment_id, array $uploaded_file, string $filename, int $post_id, int $element_index, string $existing_mime_type, ?string $token = null): void
    {
        if (!\update_attached_file($attachment_id, $uploaded_file['file'])) {
            throw onpage_http_exception("Media :: Element $element_index :: Failed to update attachment file for attachment $attachment_id", 500, 'request_failed');
        }

        $updated_attachment = \wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => $uploaded_file['type'] ?? $existing_mime_type,
            'post_title' => self::buildAttachmentTitle($filename),
            'post_parent' => $post_id,
        ], true);

        if (\is_wp_error($updated_attachment)) {
            throw onpage_http_exception(
                "Media :: Element $element_index :: Failed to update attachment $attachment_id :: " . $updated_attachment->get_error_message(),
                500,
                'request_failed'
            );
        }

        self::updateAttachmentMetadata($attachment_id, $uploaded_file['file']);

        // The recorded storage segment describes the file that was just overwritten: keep it only
        // when the request says which segment the new bytes come from, drop it otherwise, so a
        // stale token never makes another client adopt this attachment for the wrong content.
        if ($token !== null) {
            RemoteMedia::setAttachmentToken($attachment_id, $token);
        } else {
            RemoteMedia::clearAttachmentToken($attachment_id);
        }
    }

    /** Deletes the previously attached file and generated sizes after a successful replacement. */
    private static function cleanupReplacedAttachmentFiles(int $attachment_id, string $old_file, array $old_metadata): void
    {
        if ($old_file === '') {
            return;
        }

        $backup_sizes = \get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
        if (!is_array($backup_sizes)) {
            $backup_sizes = [];
        }

        if (\function_exists('wp_delete_attachment_files')) {
            \wp_delete_attachment_files($attachment_id, $old_metadata, $backup_sizes, $old_file);
        }
    }

    /** Replaces the file content of an existing attachment while keeping the same attachment ID. */
    private static function replaceAttachmentFile(array $file, int $element_index, int $attachment_id, ?int $post_id, ?string $token = null): array
    {
        $filename = self::getFilename($file);
        $attachment = self::requireAttachment($attachment_id, "Element $element_index");

        $old_file = \get_attached_file($attachment_id);
        $old_metadata = \wp_get_attachment_metadata($attachment_id);
        if (!is_array($old_metadata)) {
            $old_metadata = [];
        }

        $effective_post_id = self::resolveEffectiveParentPostId($post_id, $attachment);
        $uploaded_file = self::handleUpload($file, $element_index, $filename, 'replace');

        self::updateAttachmentRecord(
            $attachment_id,
            $uploaded_file,
            $filename,
            $effective_post_id,
            $element_index,
            $attachment->post_mime_type,
            $token
        );

        if (is_string($old_file) && $old_file !== '' && $old_file !== $uploaded_file['file']) {
            self::cleanupReplacedAttachmentFiles($attachment_id, $old_file, $old_metadata);
        }

        return self::buildUploadResult(self::ACTION_REPLACED, $attachment_id, $filename, $effective_post_id, $token);
    }

    /**
     * Reuses the attachment already indexed under the same storage token, when there is one.
     *
     * The token identifies the file *content*, so a second upload of the same segment carries the
     * same bytes: they are dropped and the existing attachment is returned as `linked`, which makes
     * POST /media idempotent on the token instead of leaving deduplication to the client's cache.
     * The attachment is still reassigned when the request asks for a parent post.
     *
     * @return array|null The upload result, or null when nothing usable is indexed under the token.
     */
    private static function linkAttachmentByToken(string $token, ?int $post_id): array|null
    {
        $attachment_id = RemoteMedia::findUsableAttachmentByToken($token);
        if ($attachment_id === null) {
            return null;
        }

        $attachment = \get_post($attachment_id);
        $attachment = $attachment instanceof \WP_Post ? $attachment : null;

        if ($post_id !== null && $attachment !== null && (int) $attachment->post_parent !== $post_id) {
            RemoteMedia::relinkAttachmentToPost($attachment_id, $post_id);
        }

        return self::buildUploadResult(
            self::ACTION_LINKED,
            $attachment_id,
            RemoteMedia::getAttachmentFilename($attachment_id),
            self::resolveEffectiveParentPostId($post_id, $attachment),
            $token
        );
    }

    /** Uploads one file through the native WordPress media pipeline. */
    private static function uploadSingleFile(array $file, int $element_index, ?int $post_id, ?int $attachment_id = null, ?string $token = null): array
    {
        $filename = self::validateUploadedFile($file, $element_index);

        if ($attachment_id !== null) {
            return self::replaceAttachmentFile($file, $element_index, $attachment_id, $post_id, $token);
        }

        if ($token !== null) {
            $linked = self::linkAttachmentByToken($token, $post_id);
            if ($linked !== null) {
                return $linked;
            }
        }

        $uploaded_file = self::handleUpload($file, $element_index, $filename, 'upload');

        return self::createAttachment($uploaded_file, $filename, $element_index, $post_id ?? 0, $token);
    }

    /** Standard upload response entry with request context. */
    private static function buildUploadedEntry(array $entry, array $result): array
    {
        return [
            'field' => $entry['field'],
            'key' => $entry['key'],
            ...$result,
        ];
    }

    /** Processes all uploaded files in the request. */
    private static function processUploads(array $files, array $replacement_attachment_ids, array $tokens, ?int $post_id): array
    {
        $uploaded = [];

        foreach ($files as $i => $entry) {
            $replacement_attachment_id = $replacement_attachment_ids[$i] ?? null;
            $token = $tokens[$i] ?? null;
            $result = self::uploadSingleFile($entry['file'], $i, $post_id, $replacement_attachment_id, $token);
            $uploaded[] = self::buildUploadedEntry($entry, $result);
        }

        return $uploaded;
    }

    /** Validates the JSON body for remote media linking. */
    private static function resolveLinkPayload(\WP_REST_Request $request): array
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            throw onpage_http_exception('Media :: Request body must be a JSON object', 400, 'invalid_param');
        }

        $resolved_post_id = self::requirePostId($payload['post_id'] ?? null);

        $files = $payload['files'] ?? null;
        if (!is_array($files) || $files === []) {
            throw onpage_http_exception("Media :: Parameter 'files' must be a non-empty object", 400, 'invalid_param');
        }

        return [
            'post_id' => $resolved_post_id,
            'files' => $files,
        ];
    }

    /** Removes the On Page® index meta of an attachment: its storage segment and its source URL. */
    private static function clearAttachmentIndexMeta(int $attachment_id): void
    {
        \delete_post_meta($attachment_id, RemoteMedia::TOKEN_META);
        \delete_post_meta($attachment_id, RemoteMedia::SOURCE_URL_META);
    }

    /** Deletes a single attachment by ID. */
    private static function deleteAttachment(int $attachment_id, int $element_index, bool $ignore_missing): void
    {
        if (!self::isAttachment($attachment_id)) {
            if ($ignore_missing) {
                // The attachment is already gone, but its index meta can outlive it when the post
                // was removed outside WordPress (direct SQL, a partial restore): drop it here too,
                // so no client re-adopts a media that is not in the library any more.
                self::clearAttachmentIndexMeta($attachment_id);

                return;
            }

            throw onpage_http_exception("Media :: Element $element_index :: Attachment $attachment_id not found", 404, 'not_found');
        }

        // wp_delete_attachment() deletes every postmeta row of the attachment, index meta included.
        if (!\wp_delete_attachment($attachment_id, true)) {
            throw onpage_http_exception("Media :: Element $element_index :: Failed to delete attachment $attachment_id", 500, 'delete_failed');
        }
    }

    /**
     * Storage segments to filter a listing by, or null when the filter is absent.
     *
     * Comma-separated: a client holds one segment per file and re-adopts a whole batch in a single
     * request, instead of one request per file.
     *
     * @return array<int, string>|null
     */
    private static function resolveTokenFilter(\WP_REST_Request $request): array|null
    {
        $token_param = Input::requestString($request, 'token');
        if ($token_param === null) {
            return null;
        }

        $tokens = [];
        foreach (explode(',', $token_param) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $tokens[$token] = $token;
            }
        }

        if ($tokens === []) {
            throw onpage_http_exception("Media :: Parameter 'token' must contain at least one non-empty value", 400, 'invalid_param');
        }

        return array_values($tokens);
    }

    /**
     * Handles the GET /media endpoint.
     *
     * @return array{items: array, total: int, per_page: int} List page plus pagination totals.
     */
    public static function listFromRequest(\WP_REST_Request $request): array
    {
        $per_page = Input::positiveInt($request->get_param('per_page')) ?? 100;
        $per_page = min($per_page, 100);

        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $per_page,
            'paged' => Input::positiveInt($request->get_param('page')) ?? 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => false,
        ];

        $post_id = Input::positiveInt($request->get_param('post_id'));
        if ($post_id !== null) {
            $args['post_parent'] = $post_id;
        }

        $mime_type = Input::requestString($request, 'mime_type');
        if ($mime_type !== null) {
            $args['post_mime_type'] = $mime_type;
        }

        $tokens = self::resolveTokenFilter($request);
        if ($tokens !== null) {
            $args['meta_query'][] = [
                'key' => RemoteMedia::TOKEN_META,
                'value' => $tokens,
                'compare' => 'IN',
            ];
        }

        $source_url = Input::requestString($request, 'source_url');
        if ($source_url !== null) {
            $args['meta_query'][] = [
                'key' => RemoteMedia::SOURCE_URL_META,
                'value' => $source_url,
            ];
        }

        // WP_Query (rather than get_posts()) so `found_posts` is available for X-WP-Total/X-WP-TotalPages.
        $query = new \WP_Query($args);
        if (!is_array($query->posts)) {
            throw onpage_http_exception('Media :: Failed to list media', 500, 'request_failed');
        }

        return [
            'items' => array_map([self::class, 'buildListResult'], $query->posts),
            'total' => (int) $query->found_posts,
            'per_page' => $per_page,
        ];
    }

    /** Handles the POST /media endpoint. */
    public static function uploadFromRequest(\WP_REST_Request $request): array
    {
        self::loadDependencies();

        $post_id = self::resolveParentPostId($request);

        $files = self::normalizeFiles($request->get_file_params());
        if ($files === []) {
            throw onpage_http_exception('Media :: No uploaded files found in request', 400, 'invalid_param');
        }

        $replacement_attachment_ids = self::resolveReplacementAttachmentIds($request, count($files));
        $tokens = self::resolveUploadTokens($request, count($files));

        return self::processUploads($files, $replacement_attachment_ids, $tokens, $post_id);
    }

    /** Handles the DELETE /media endpoint. */
    public static function deleteFromRequest(\WP_REST_Request $request): void
    {
        $ignore_missing = onpage_should_ignore_missing($request);
        // Same body rule as every other batch DELETE: a JSON array, where `[]` is a no-op.
        $payload = Input::requireJsonList($request, 'Media');

        foreach ($payload as $i => $value) {
            $attachment_id = self::normalizeAttachmentId($value);
            if ($attachment_id === null) {
                throw onpage_http_exception("Media :: Element $i :: Attachment ID must be a positive integer", 400, 'invalid_param');
            }

            self::deleteAttachment($attachment_id, $i, $ignore_missing);
        }
    }

    /** Handles the POST /media/link endpoint. */
    public static function linkFromRequest(\WP_REST_Request $request): array
    {
        $payload = self::resolveLinkPayload($request);

        $linked = [];
        foreach ($payload['files'] as $field_key => $url) {
            $linked[] = self::urlToField($payload['post_id'], $field_key, $url);
        }

        return $linked;
    }
}
