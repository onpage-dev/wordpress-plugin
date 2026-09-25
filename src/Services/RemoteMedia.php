<?php



namespace OnPage\Services;



class RemoteMedia
{
    public const SOURCE_URL_META = '_onpage_source_url';
    public const TOKEN_META = '_onpage_file_token';
    private const DEFAULT_DOWNLOAD_TIMEOUT_SECONDS = 45;
    private const ONPAGE_DOMAIN = 'onpage.it';
    private const ONPAGE_STORAGE_HOST = 'storage.onpage.it';

    /**
     * Extensions a remote import may force into the upload allowlist (see withUploadableMime()).
     *
     * Media and documents only: anything that a browser could run on the site origin (HTML, JS,
     * XML, executables...) is deliberately absent. SVG is not listed either: it goes through its
     * own sanitized path (see sideloadWithUploadRules()).
     */
    private const FORCE_ALLOWED_EXTENSIONS = [
        // Images
        'jpg', 'jpeg', 'jpe', 'gif', 'png', 'bmp', 'tif', 'tiff', 'webp', 'avif', 'heic', 'heif', 'ico',
        // Video
        'mp4', 'm4v', 'mov', 'qt', 'wmv', 'avi', 'mpeg', 'mpg', 'mpe', 'ogv', 'webm', '3gp', '3gpp', '3g2', '3gp2',
        // Audio
        'mp3', 'm4a', 'm4b', 'aac', 'wav', 'ogg', 'oga', 'flac', 'wma', 'mka',
        // PDF and office / OpenDocument documents
        'pdf', 'rtf',
        'doc', 'docx', 'docm', 'dot', 'dotx', 'dotm',
        'xls', 'xlsx', 'xlsm', 'xlsb', 'xlt', 'xltx', 'xltm',
        'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'ppsm', 'pot', 'potx', 'potm',
        'odt', 'ods', 'odp', 'odg', 'odc', 'odb', 'odf',
        'key', 'numbers', 'pages',
        // Plain text and CSV
        'txt', 'csv', 'tsv',
        // Archives
        'zip', '7z', 'rar', 'tar', 'gz', 'gzip',
    ];

    public const ACTION_CREATED = 'created';
    public const ACTION_LINKED = 'linked';
    public const ACTION_REPLACED = 'replaced';



    /** Loads the WordPress helpers required to sideload remote media. */
    public static function loadDependencies(): void
    {
        Media::loadDependencies();
    }

    /** Normalizes a remote file URL or returns null when invalid. */
    public static function sanitizeUrl(mixed $value): string|null
    {
        if (!is_string($value)) return null;

        $url = \esc_url_raw(trim($value));

        return $url !== '' && \wp_http_validate_url($url) ? $url : null;
    }

    /** Returns the id of the attachment carrying the given index meta value, or null. */
    private static function findAttachmentByMeta(string $meta_key, string $meta_value): int|null
    {
        $attachments = \get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => $meta_key,
            'meta_value' => $meta_value,
        ]);

        return !empty($attachments[0]) ? (int) $attachments[0] : null;
    }

    /**
     * Returns the id of the attachment already imported from the same remote file, or null.
     *
     * The storage segment is tried first when the URL carries one: it identifies the *content*, so
     * the attachment is found again even after the file was renamed on On Page®, which changes the
     * URL but not the segment. The exact source URL is the fallback, for attachments imported from
     * somewhere else or before segments were indexed.
     */
    public static function findAttachmentBySourceUrl(string $url): int|null
    {
        $token = self::tokenFromUrl($url);
        if ($token !== null) {
            $attachment_id = self::findAttachmentByToken($token);
            if ($attachment_id !== null) {
                return $attachment_id;
            }
        }

        return self::findAttachmentByMeta(self::SOURCE_URL_META, $url);
    }

    /** Returns the id of the attachment indexed under the same storage segment, if any, or null. */
    public static function findAttachmentByToken(string $token): int|null
    {
        return self::findAttachmentByMeta(self::TOKEN_META, $token);
    }

    /**
     * Same as findAttachmentByToken(), but skipping attachments whose physical file is gone.
     *
     * Such a row cannot be reused, and leaving it indexed would make every later lookup return a
     * broken attachment: its segment is dropped so the caller can import the file again.
     */
    public static function findUsableAttachmentByToken(string $token): int|null
    {
        $attachment_id = self::findAttachmentByToken($token);

        while ($attachment_id !== null) {
            if (self::hasExistingAttachmentFile($attachment_id)) {
                return $attachment_id;
            }

            \delete_post_meta($attachment_id, self::TOKEN_META, $token);
            $attachment_id = self::findAttachmentByToken($token);
        }

        return null;
    }

    /**
     * Records the On Page® storage segment the attachment's file comes from.
     *
     * The indexed value is the whole segment `<token>[.<format>]` (e.g.
     * `aaa111bbb222.1920x1920-contain.jpg`), not the bare token: the same file requested in
     * different formats yields distinct attachments, so the format has to stay part of it.
     */
    public static function setAttachmentToken(int $attachment_id, string $token): void
    {
        \update_post_meta($attachment_id, self::TOKEN_META, $token);
    }

    /** Drops the recorded storage segment, when the attachment's file no longer comes from it. */
    public static function clearAttachmentToken(int $attachment_id): void
    {
        \delete_post_meta($attachment_id, self::TOKEN_META);
    }

    /**
     * Extracts the On Page® storage segment from a file URL, or null when the URL does not carry one.
     *
     * On Page® serves files as `https://storage.onpage.it/<segment>/<name>` and
     * `https://app.onpage.it/api/storage/<segment>/<name>`, where `<segment>` is `<token>[.<format>]`
     * and is the identity of the content: the trailing name is cosmetic, the same segment with
     * another name (or with no name at all) serves the same bytes.
     *
     * Only those two shapes, on an On Page® host (`onpage.it` or one of its subdomains), are
     * recognized, deliberately. Reading a path segment of *any* URL would index unrelated files
     * under the same value (`storage.googleapis.com/bucket/a.jpg` and `/bucket/b.jpg` would both
     * give `bucket`) and collapse them onto a single attachment. Other URLs return null, so
     * deduplication falls back to the exact source URL.
     */
    public static function tokenFromUrl(string $url): string|null
    {
        $host = \wp_parse_url($url, \PHP_URL_HOST);
        if (!is_string($host) || !self::isOnPageHost($host)) {
            return null;
        }

        $path = \wp_parse_url($url, \PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', $path),
            static fn(string $segment): bool => $segment !== ''
        ));

        // `storage.onpage.it` serves the segment straight off the path root.
        if (strtolower($host) === self::ONPAGE_STORAGE_HOST) {
            $segment = $segments[0] ?? '';
        } elseif (($segments[0] ?? null) === 'api' && ($segments[1] ?? null) === 'storage') {
            $segment = $segments[2] ?? '';
        } else {
            return null;
        }

        return $segment !== '' ? \rawurldecode($segment) : null;
    }

    /** True when $host is On Page® (`onpage.it` or one of its subdomains). */
    private static function isOnPageHost(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        return $host === self::ONPAGE_DOMAIN || \str_ends_with($host, '.' . self::ONPAGE_DOMAIN);
    }

    /** True when $attachment_id refers to an existing Media Library attachment. */
    public static function isAttachmentId(int $attachment_id): bool
    {
        return $attachment_id > 0 && \get_post_type($attachment_id) === 'attachment';
    }

    /** Stable filename fallback for remote files. */
    public static function getRemoteFilename(string $url, string $field_key): string
    {
        $path = (string) \wp_parse_url($url, \PHP_URL_PATH);
        $filename = basename($path);

        if ($filename !== '' && $filename !== '.' && $filename !== '/') {
            return \sanitize_file_name($filename);
        }

        return sprintf('remote-file-%s', \sanitize_key($field_key));
    }

    /** Returns a safe timeout for remote file downloads. */
    private static function normalizeDownloadTimeout(?int $timeout_seconds): int
    {
        if ($timeout_seconds === null) {
            return self::DEFAULT_DOWNLOAD_TIMEOUT_SECONDS;
        }

        return max(3, $timeout_seconds);
    }

    /** Backward-compatible absolute path check (WP core helper may be unavailable). */
    private static function isAbsolutePath(string $path): bool
    {
        if (\function_exists('wp_is_absolute_path')) {
            return \wp_is_absolute_path($path);
        }

        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return (bool) \preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    /** Returns absolute attachment file path when available, or null otherwise. */
    private static function getAttachmentFilePath(int $attachment_id): ?string
    {
        $path = \get_attached_file($attachment_id);
        if (!is_string($path) || $path === '') {
            return null;
        }

        if (!self::isAbsolutePath($path)) {
            $uploads = \wp_get_upload_dir();
            $uploads_base_dir = is_string($uploads['basedir'] ?? null)
                ? \untrailingslashit((string) $uploads['basedir'])
                : '';

            if ($uploads_base_dir !== '') {
                $path = $uploads_base_dir . '/' . ltrim($path, '/');
            }
        }

        return $path;
    }

    /** True when attachment points to a file that exists on local filesystem. */
    private static function hasExistingAttachmentFile(int $attachment_id): bool
    {
        $path = self::getAttachmentFilePath($attachment_id);

        return is_string($path) && $path !== '' && \file_exists($path);
    }

    /** Builds a consistent remote import failure. */
    private static function importFailed(string $field_key, string $message): \Throwable
    {
        return onpage_http_exception("Failed to import remote file for field '$field_key' :: $message", 500, 'request_failed');
    }

    /** Standard response payload for a remote media import or reuse. */
    private static function buildMediaLibraryResult(string $action, int $attachment_id, string $source_url): array
    {
        return [
            'action' => $action,
            'attachment_id' => $attachment_id,
            'filename' => self::getAttachmentFilename($attachment_id),
            'source_url' => $source_url,
        ];
    }

    /** Removes a downloaded temporary file when WordPress did not move it into uploads. */
    private static function cleanupTemporaryFile(string $temporary_file): void
    {
        if ($temporary_file !== '') {
            @unlink($temporary_file);
        }
    }

    /**
     * Standard mime type for a filename extension, or null when the extension may not be forced.
     *
     * Only extensions in FORCE_ALLOWED_EXTENSIONS are considered; their mime is looked up in
     * core's full `wp_get_mime_types()` map. That map also contains types that would run on the
     * site origin (`htm|html`, `js`, `class`...), which is why it is never used unfiltered.
     */
    private static function standardMimeForFilename(string $filename): ?string
    {
        $ext = strtolower((string) pathinfo($filename, \PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::FORCE_ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        foreach (\wp_get_mime_types() as $ext_pattern => $mime) {
            if (in_array($ext, explode('|', (string) $ext_pattern), true)) {
                return (string) $mime;
            }
        }

        return null;
    }

    /**
     * Runs a sideload with the imported file's own type ensured in the upload allowlist.
     *
     * On Page® imports are authenticated server-to-server via a Bearer token, so the REST
     * request has no logged-in WordPress user. Hosts and security plugins that narrow
     * `get_allowed_mime_types()` (globally or for anonymous callers) make
     * `wp_check_filetype_and_ext()` resolve an empty ext/type even for legitimate files
     * such as PNG; core then rejects the sideload with "Sorry, you are not allowed to
     * upload this file type." — and `unfiltered_upload` cannot rescue it because core maps
     * that capability to `do_not_allow` unless `ALLOW_UNFILTERED_UPLOADS` is defined.
     *
     * Only the file's standard mime is re-added, and only for the media and document extensions
     * in FORCE_ALLOWED_EXTENSIONS: core's real-byte mime verification stays intact. Any other
     * extension is left to WordPress' normal rules, which usually reject it. The filter runs last
     * (highest priority number) so it wins against restrictive `upload_mimes` hooks registered
     * elsewhere.
     */
    private static function withUploadableMime(string $filename, callable $sideload): mixed
    {
        $mime = self::standardMimeForFilename($filename);
        if ($mime === null) {
            return $sideload();
        }

        $ext = strtolower((string) pathinfo($filename, \PATHINFO_EXTENSION));

        $mime_filter = static function (array $mimes) use ($ext, $mime): array {
            $mimes[$ext] = $mime;

            return $mimes;
        };

        \add_filter('upload_mimes', $mime_filter, \PHP_INT_MAX);

        try {
            return $sideload();
        } finally {
            \remove_filter('upload_mimes', $mime_filter, \PHP_INT_MAX);
        }
    }

    /**
     * Runs a sideload of a downloaded file under the plugin's upload rules.
     *
     * SVG files are sanitized in place first and temporarily allowed; any other file goes
     * through withUploadableMime(). Used both for new imports and for `refresh` replacements, so
     * a replaced SVG is never stored unsanitized.
     */
    private static function sideloadWithUploadRules(array $file_array, string $field_key, callable $sideload): mixed
    {
        $filename = isset($file_array['name']) && is_scalar($file_array['name']) ? (string) $file_array['name'] : '';
        if (!Svg::isFilename($filename)) {
            return self::withUploadableMime($filename, $sideload);
        }

        Svg::sanitizeFile((string) $file_array['tmp_name'], "Failed to import remote file for field '$field_key'");

        $mime_filter = static function (array $mimes): array {
            $mimes['svg'] = Svg::MIME_TYPE;

            return $mimes;
        };

        $type_filter = static function (array $data, string $file, string $filename): array {
            if (!Svg::isFilename($filename)) {
                return $data;
            }

            $data['ext'] = 'svg';
            $data['type'] = Svg::MIME_TYPE;
            $data['proper_filename'] = false;

            return $data;
        };

        \add_filter('upload_mimes', $mime_filter);
        \add_filter('wp_check_filetype_and_ext', $type_filter, 10, 3);

        try {
            return $sideload();
        } finally {
            \remove_filter('wp_check_filetype_and_ext', $type_filter, 10);
            \remove_filter('upload_mimes', $mime_filter);
        }
    }

    /** Sideloads a file into a new attachment, under the plugin's upload rules. */
    private static function sideloadFile(array $file_array, string $field_key): int|\WP_Error
    {
        return self::sideloadWithUploadRules(
            $file_array,
            $field_key,
            static fn (): int|\WP_Error => \media_handle_sideload($file_array, 0)
        );
    }

    /** Uploads a downloaded file and guarantees temp-file cleanup on failed imports. */
    private static function sideloadDownloadedFile(string $temporary_file, string $remote_filename, string $field_key): int
    {
        $file_array = [
            'name' => $remote_filename,
            'tmp_name' => $temporary_file,
        ];

        try {
            $attachment_id = self::sideloadFile($file_array, $field_key);
        } catch (\Throwable $e) {
            self::cleanupTemporaryFile($temporary_file);
            throw $e;
        }

        if (\is_wp_error($attachment_id)) {
            self::cleanupTemporaryFile($temporary_file);

            throw self::importFailed($field_key, $attachment_id->get_error_message());
        }

        return (int) $attachment_id;
    }

    /** Downloads a remote file or throws a normalized request failure. */
    private static function downloadRemoteFile(string $url, string $field_key, int $timeout_seconds): string
    {
        $temporary_file = \download_url($url, $timeout_seconds);
        if (\is_wp_error($temporary_file)) {
            throw onpage_http_exception(
                "Failed to download remote file for field '$field_key' :: " . $temporary_file->get_error_message(),
                500,
                'request_failed'
            );
        }

        return (string) $temporary_file;
    }

    /** Moves a downloaded file into uploads and returns WordPress upload metadata. */
    private static function moveDownloadedFileToUploads(string $temporary_file, string $remote_filename, string $field_key): array
    {
        $file_array = [
            'name' => $remote_filename,
            'tmp_name' => $temporary_file,
        ];

        try {
            $uploaded_file = self::sideloadWithUploadRules(
                $file_array,
                $field_key,
                static fn (): array => \wp_handle_sideload($file_array, ['test_form' => false])
            );
        } catch (\Throwable $e) {
            self::cleanupTemporaryFile($temporary_file);
            throw $e;
        }

        if (isset($uploaded_file['error'])) {
            self::cleanupTemporaryFile($temporary_file);

            throw self::importFailed($field_key, (string) $uploaded_file['error']);
        }

        return $uploaded_file;
    }

    /** Deletes old attachment files after replacing the file behind an attachment ID. */
    private static function cleanupReplacedAttachmentFiles(int $attachment_id, string $old_file, array $old_metadata): void
    {
        if ($old_file === '') {
            return;
        }

        $backup_sizes = \get_post_meta($attachment_id, '_wp_attachment_backup_sizes', true);
        if (!is_array($backup_sizes)) {
            $backup_sizes = [];
        }

        if (\function_exists('wp_delete_attachment_files') && isset($old_metadata['file'])) {
            \wp_delete_attachment_files($attachment_id, $old_metadata, $backup_sizes, $old_file);
            return;
        }

        if (\function_exists('wp_delete_file') && \file_exists($old_file)) {
            \wp_delete_file($old_file);
        }
    }

    /** Replaces an existing attachment file with freshly downloaded remote content. */
    private static function replaceAttachmentFromDownloadedFile(
        int $attachment_id,
        string $temporary_file,
        string $remote_filename,
        string $field_key
    ): void {
        $old_file = \get_attached_file($attachment_id);
        $old_metadata = \wp_get_attachment_metadata($attachment_id);
        if (!is_array($old_metadata)) {
            $old_metadata = [];
        }

        $uploaded_file = self::moveDownloadedFileToUploads($temporary_file, $remote_filename, $field_key);

        if (!\update_attached_file($attachment_id, $uploaded_file['file'])) {
            throw self::importFailed($field_key, "failed to update attachment file for attachment $attachment_id");
        }

        $updated_attachment = \wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => $uploaded_file['type'] ?? \get_post_mime_type($attachment_id),
            'post_title' => self::buildAttachmentTitle($remote_filename),
        ], true);

        if (\is_wp_error($updated_attachment)) {
            throw self::importFailed($field_key, $updated_attachment->get_error_message());
        }

        $metadata = \wp_generate_attachment_metadata($attachment_id, $uploaded_file['file']);
        if (is_array($metadata)) {
            \wp_update_attachment_metadata($attachment_id, $metadata);
        }

        if (is_string($old_file) && $old_file !== '' && $old_file !== $uploaded_file['file']) {
            self::cleanupReplacedAttachmentFiles($attachment_id, $old_file, $old_metadata);
        }
    }

    /** Returns filename for an existing attachment. */
    public static function getAttachmentFilename(int $attachment_id): string
    {
        $path = \get_attached_file($attachment_id);
        if (is_string($path) && $path !== '') {
            return basename($path);
        }

        return (string) \get_the_title($attachment_id);
    }

    /** Sanitized attachment title derived from a filename. */
    private static function buildAttachmentTitle(string $filename): string
    {
        $title = (string) preg_replace('/\.[^.]+$/', '', $filename);
        $title = $title ? $title : $filename;

        return \sanitize_text_field($title);
    }

    /** Reassigns an existing attachment to the requested parent post. */
    public static function relinkAttachmentToPost(int $attachment_id, int $post_id): void
    {
        $updated_attachment = \wp_update_post([
            'ID' => $attachment_id,
            'post_parent' => $post_id,
        ], true);

        if (\is_wp_error($updated_attachment)) {
            throw onpage_http_exception("Failed to relink attachment $attachment_id", 500, 'request_failed');
        }
    }

    /** Records where an imported attachment's file came from: its source URL and, when the URL is an On Page® one, its storage segment. */
    private static function indexAttachmentSource(int $attachment_id, string $url, ?string $token): void
    {
        \update_post_meta($attachment_id, self::SOURCE_URL_META, $url);

        if ($token !== null) {
            self::setAttachmentToken($attachment_id, $token);
        }
    }

    /** Drops the index entries that led to an attachment whose file is gone, so the lookup can move past it. */
    private static function forgetAttachmentSource(int $attachment_id, string $url, ?string $token): void
    {
        \delete_post_meta($attachment_id, self::SOURCE_URL_META, $url);

        // Both entries have to go: leaving the segment behind would hand the same dead attachment
        // back to the very next lookup, which resolves the segment first.
        if ($token !== null) {
            \delete_post_meta($attachment_id, self::TOKEN_META, $token);
        }
    }

    /**
     * Imports a remote file into Media Library, or reuses/replaces an attachment imported from the same URL.
     */
    public static function urlToMediaLibrary(string $url, string $field_key, ?int $timeout_seconds = null, bool $force_refresh = false): array
    {
        self::loadDependencies();

        $download_timeout_seconds = self::normalizeDownloadTimeout($timeout_seconds);
        $remote_filename = self::getRemoteFilename($url, $field_key);
        $token = self::tokenFromUrl($url);

        $existing_attachment_id = self::findAttachmentBySourceUrl($url);
        while ($existing_attachment_id !== null) {
            if (self::hasExistingAttachmentFile($existing_attachment_id)) {
                if ($force_refresh) {
                    $temporary_file = self::downloadRemoteFile($url, $field_key, $download_timeout_seconds);
                    self::replaceAttachmentFromDownloadedFile($existing_attachment_id, $temporary_file, $remote_filename, $field_key);
                    self::indexAttachmentSource($existing_attachment_id, $url, $token);

                    return self::buildMediaLibraryResult(self::ACTION_REPLACED, $existing_attachment_id, $url);
                }

                return self::buildMediaLibraryResult(self::ACTION_LINKED, $existing_attachment_id, $url);
            }

            self::forgetAttachmentSource($existing_attachment_id, $url, $token);
            $existing_attachment_id = self::findAttachmentBySourceUrl($url);
        }

        $temporary_file = self::downloadRemoteFile($url, $field_key, $download_timeout_seconds);

        $attachment_id = self::sideloadDownloadedFile(
            $temporary_file,
            $remote_filename,
            $field_key
        );

        self::indexAttachmentSource($attachment_id, $url, $token);

        return self::buildMediaLibraryResult(self::ACTION_CREATED, $attachment_id, $url);
    }

    /**
     * Links an attachment from Media Library to the given post.
     */
    public static function linkMediaToPost(int $attachment_id, int $post_id): array
    {
        self::relinkAttachmentToPost($attachment_id, $post_id);

        return [
            'attachment_id' => $attachment_id,
            'post_id' => $post_id,
        ];
    }

    /**
     * Imports a remote file into Media Library and links it to the given post.
     */
    public static function urlToPost(string $url, int $post_id, string $field_key, ?int $timeout_seconds = null): array
    {
        $media = self::urlToMediaLibrary($url, $field_key, $timeout_seconds);
        self::linkMediaToPost($media['attachment_id'], $post_id);

        return $media;
    }

    /**
     * Imports a remote file, links it to the given post and assigns it to the requested ACF field.
     */
    public static function urlToField(string $url, int $post_id, string $field_key, ?int $timeout_seconds = null): array
    {
        $media = self::urlToPost($url, $post_id, $field_key, $timeout_seconds);
        Acf::updateFieldValue($post_id, $field_key, $media['attachment_id']);

        return $media;
    }
}
