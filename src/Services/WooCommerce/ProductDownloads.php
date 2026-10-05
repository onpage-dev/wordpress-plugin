<?php



namespace OnPage\Services\WooCommerce;



use OnPage\Services\RemoteMedia;



class ProductDownloads
{
    private const ERROR_PREFIX = 'WooCommerce Product';
    private const PUBLIC_DOWNLOAD_IDS_META = '_onpage_public_download_ids';
    private const DOWNLOAD_IMPORT_TIMEOUT_SECONDS = 12;
    /** Fingerprint of the downloads the plugin last copied from the parent onto a variation. */
    private const INHERITED_DOWNLOADS_META = '_onpage_inherited_downloads';

    /** Whether the uploads base URL was already approved during this request. */
    private static bool $uploads_directory_approved = false;

    /**
     * Ids of the downloads the last apply() marked `public: false`, per product object, for
     * syncPublicIdsMeta() once the product is saved.
     */
    private static ?\WeakMap $private_download_ids = null;



    /** Registers frontend WooCommerce hooks managed by the plugin. */
    public static function boot(): void
    {
        \add_action('woocommerce_single_product_summary', [self::class, 'renderProductDownloadLinks'], 35);
    }

    /** API response shape for native WooCommerce downloadable files. */
    public static function buildResponse(mixed $download, int $product_id = 0): array
    {
        $download_id = (string) $download->get_id();

        return [
            'id' => $download_id,
            'name' => (string) $download->get_name(),
            'file' => (string) $download->get_file(),
            'enabled' => (bool) $download->get_enabled(),
            'public' => $product_id > 0 && in_array($download_id, self::getPublicDownloadIds($product_id), true),
        ];
    }

    /** Applies native WooCommerce downloadable files. */
    public static function apply(
        mixed $product,
        array $params,
        int $element_index,
        ?string $language_code,
        ?string $fallback_language
    ): void {
        if (!array_key_exists('downloads', $params)) {
            return;
        }

        $existing_ids_by_file = self::getExistingDownloadIdsByFile($product);
        $used_ids = [];
        $private_ids = [];

        $downloads = [];
        foreach ($params['downloads'] as $download_index => $download_payload) {
            if (!is_array($download_payload)) continue;

            // `public` (default true) lists the file in the product page's documents section.
            // `false` keeps it a regular WooCommerce download, delivered only to buyers.
            $is_public = $download_payload['public'] ?? true;
            if (!is_bool($is_public)) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'downloads.$download_index.public' must be a boolean", 400, 'invalid_param');
            }

            $raw_file = self::resolveDownloadFileRawValue($download_payload['file'] ?? null, $language_code, $fallback_language);
            if ($raw_file === null || $raw_file === '') {
                continue;
            }

            $force_refresh = self::toBool($download_payload['refresh'] ?? false);
            $file_url = self::resolveDownloadFileUrl($raw_file, $element_index, (int) $download_index, $force_refresh);
            $file = (string) \apply_filters('woocommerce_file_download_path', $file_url, $product, $download_index);

            $name = self::resolveDownloadValue($download_payload['name'] ?? null, $language_code, $fallback_language)
                ?: self::getDownloadNameFromUrl($file_url);
            $download_id = self::resolveDownloadId($download_payload['id'] ?? null, $file, $raw_file, $existing_ids_by_file, $used_ids);
            $used_ids[] = $download_id;
            if (!$is_public) {
                $private_ids[] = $download_id;
            }

            $download = new \WC_Product_Download();
            $download->set_id($download_id);
            $download->set_name($name);
            $download->set_file($file);
            $downloads[] = $download;
        }

        self::$private_download_ids ??= new \WeakMap();
        self::$private_download_ids[$product] = $private_ids;

        try {
            $product->set_downloads($downloads);
            if ($downloads !== []) {
                $product->set_downloadable(true);
            }
        } catch (\Throwable $e) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Failed to set downloadable files :: " . $e->getMessage(), 400, 'invalid_param');
        }
    }

    /**
     * Download id for one `downloads[]` entry, stable across syncs and languages.
     *
     * WooCommerce keys customers' download permissions by (product, download id): a new id on
     * every sync would cut buyers off from files they paid for. So, in order:
     *
     * 1. the `id` sent in the payload;
     * 2. the id of the download the product already has for the same file URL (this also keeps
     *    ids created by earlier plugin versions, which were random);
     * 3. an id derived from the file's source: the On Page® storage segment when the URL carries
     *    one, otherwise the source URL, or the attachment ID. The same source gives the same id
     *    on every sync and on every translation.
     *
     * An id already taken by an earlier entry of the same payload is never reused, so two entries
     * pointing at the same file stay two downloads.
     *
     * @param array<string, string> $existing_ids_by_file
     * @param string[] $used_ids
     */
    private static function resolveDownloadId(
        mixed $payload_id,
        string $file,
        mixed $raw_file,
        array $existing_ids_by_file,
        array $used_ids
    ): string {
        $payload_id = is_scalar($payload_id) ? trim((string) $payload_id) : '';
        if ($payload_id !== '') {
            return $payload_id;
        }

        $existing_id = $existing_ids_by_file[$file] ?? null;
        if ($existing_id !== null && !in_array($existing_id, $used_ids, true)) {
            return $existing_id;
        }

        $source = is_int($raw_file)
            ? 'attachment:' . $raw_file
            : (RemoteMedia::tokenFromUrl((string) $raw_file) ?? trim((string) $raw_file));

        $download_id = self::deterministicDownloadId($source);
        for ($occurrence = 2; in_array($download_id, $used_ids, true); $occurrence++) {
            $download_id = self::deterministicDownloadId($source . '#' . $occurrence);
        }

        return $download_id;
    }

    /** UUID-shaped id derived from a download source. */
    private static function deterministicDownloadId(string $source): string
    {
        $hash = md5('onpage-download|' . $source);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    /**
     * Ids of the product's current downloads, keyed by file URL (the first one wins).
     *
     * @return array<string, string>
     */
    private static function getExistingDownloadIdsByFile(mixed $product): array
    {
        if (!is_object($product) || !\method_exists($product, 'get_downloads')) {
            return [];
        }

        $ids = [];
        foreach ($product->get_downloads() as $download) {
            if (!is_object($download) || !\method_exists($download, 'get_file') || !\method_exists($download, 'get_id')) {
                continue;
            }

            $file = (string) $download->get_file();
            $download_id = (string) $download->get_id();
            if ($file !== '' && $download_id !== '' && !array_key_exists($file, $ids)) {
                $ids[$file] = $download_id;
            }
        }

        return $ids;
    }

    /** Links imported download attachments to the parent product in Media Library. */
    public static function linkAttachmentsToProduct(mixed $product, int $product_id): void
    {
        if ($product_id <= 0 || !is_object($product) || !\method_exists($product, 'get_downloads')) {
            return;
        }

        foreach ($product->get_downloads() as $download) {
            if (!is_object($download) || !\method_exists($download, 'get_file')) {
                continue;
            }

            $file = (string) $download->get_file();
            if ($file === '' || !\function_exists('attachment_url_to_postid')) {
                continue;
            }

            $attachment_id = (int) \attachment_url_to_postid($file);
            if ($attachment_id > 0) {
                RemoteMedia::linkMediaToPost($attachment_id, $product_id);
            }
        }
    }

    /**
     * Stores which API-managed downloads are public on the product page: every download sent,
     * except those apply() saw with `public: false`.
     */
    public static function syncPublicIdsMeta(mixed $product, array $params, int $product_id): void
    {
        if ($product_id <= 0 || !array_key_exists('downloads', $params)) {
            return;
        }

        if (!is_object($product) || !\method_exists($product, 'get_downloads')) {
            \delete_post_meta($product_id, self::PUBLIC_DOWNLOAD_IDS_META);
            return;
        }

        $private_ids = self::$private_download_ids[$product] ?? [];
        $download_ids = [];
        foreach ($product->get_downloads() as $download) {
            if (is_object($download) && \method_exists($download, 'get_id')) {
                $download_id = (string) $download->get_id();
                if ($download_id !== '' && !in_array($download_id, $private_ids, true)) {
                    $download_ids[] = $download_id;
                }
            }
        }

        if ($download_ids === []) {
            \delete_post_meta($product_id, self::PUBLIC_DOWNLOAD_IDS_META);
            return;
        }

        \update_post_meta($product_id, self::PUBLIC_DOWNLOAD_IDS_META, array_values(array_unique($download_ids)));
    }

    /**
     * Syncs parent variable-product downloads to its existing variations when downloads were submitted.
     *
     * The API has no per-variation `downloads`: a variation's downloads are either copies of the
     * parent's made by the plugin, or files a site admin set on that variation in WooCommerce.
     * Only the first kind is overwritten (see applyPayloadsToVariation()).
     */
    public static function syncVariableProductToVariations(mixed $product, array $params, int $element_index): void
    {
        if (!array_key_exists('downloads', $params)) {
            return;
        }

        if (!$product instanceof \WC_Product_Variable && (!\method_exists($product, 'get_type') || $product->get_type() !== 'variable')) {
            return;
        }

        // The list as saved, read back: after save() the object still returns the downloads the new
        // list dropped, because WC_Data::apply_changes() merges with array_replace_recursive().
        $saved = \wc_get_product((int) $product->get_id());
        $download_payloads = array_values(array_map(
            [self::class, 'buildCrudPayload'],
            ($saved instanceof \WC_Product ? $saved : $product)->get_downloads()
        ));

        foreach ($product->get_children() as $variation_id) {
            $variation = \wc_get_product((int) $variation_id);
            if (!$variation instanceof \WC_Product_Variation) {
                continue;
            }

            self::applyPayloadsToVariation($variation, $download_payloads, $element_index);
        }
    }

    /** Lets newly saved variations inherit parent product downloads for WooCommerce admin visibility. */
    public static function syncParentDownloadsToVariation(\WC_Product_Variation $variation, int $element_index = 0): void
    {
        $parent_id = (int) $variation->get_parent_id();
        if ($parent_id <= 0) {
            return;
        }

        $parent = \wc_get_product($parent_id);
        if (!$parent instanceof \WC_Product_Variable && (!\method_exists($parent, 'get_type') || $parent->get_type() !== 'variable')) {
            return;
        }

        $download_payloads = array_values(array_map(
            [self::class, 'buildCrudPayload'],
            $parent->get_downloads()
        ));
        if ($download_payloads === []) {
            return;
        }

        self::applyPayloadsToVariation($variation, $download_payloads, $element_index);
    }

    /** Renders product PDF/document downloads on the public WooCommerce product page. */
    public static function renderProductDownloadLinks(): void
    {
        global $product;

        $links = self::getProductDownloadLinks($product ?? null);
        if ($links === []) {
            return;
        }

        echo '<section class="onpage-product-downloads">';
        echo '<h2 class="onpage-product-downloads__title">Documents</h2>';
        echo '<ul class="onpage-product-downloads__list">';

        foreach ($links as $link) {
            echo '<li class="onpage-product-downloads__item">';
            echo '<a class="onpage-product-downloads__link" href="' . \esc_url($link['file']) . '" target="_blank" rel="noopener">';
            echo \esc_html($link['name']);
            echo '</a>';
            echo '</li>';
        }

        echo '</ul>';
        echo '</section>';
    }

    /** Returns public product download links for the product page. */
    private static function getProductDownloadLinks(mixed $product): array
    {
        if (!is_object($product) || !\method_exists($product, 'get_downloads')) {
            return [];
        }

        $product_id = \method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        $public_download_ids = self::getPublicDownloadIds($product_id);
        if ($public_download_ids === []) {
            return [];
        }

        $links = [];
        foreach ($product->get_downloads() as $download) {
            if (!is_object($download) || !\method_exists($download, 'get_file')) {
                continue;
            }

            $download_id = \method_exists($download, 'get_id') ? (string) $download->get_id() : '';
            if ($download_id === '' || !in_array($download_id, $public_download_ids, true)) {
                continue;
            }

            if (\method_exists($download, 'get_enabled') && !$download->get_enabled()) {
                continue;
            }

            $file = (string) $download->get_file();
            if ($file === '') {
                continue;
            }

            $name = \method_exists($download, 'get_name') ? trim((string) $download->get_name()) : '';
            $links[] = [
                'name' => $name !== '' ? $name : self::getDownloadNameFromUrl($file),
                'file' => $file,
            ];
        }

        return $links;
    }

    /** Returns download IDs that the On Page® API is allowed to show publicly. */
    private static function getPublicDownloadIds(int $product_id): array
    {
        if ($product_id <= 0) {
            return [];
        }

        $ids = \get_post_meta($product_id, self::PUBLIC_DOWNLOAD_IDS_META, true);
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $id): string => is_scalar($id) ? (string) $id : '',
            $ids
        )));
    }

    /** WooCommerce CRUD payload shape for copying downloads between products. */
    private static function buildCrudPayload(mixed $download): array
    {
        return [
            'download_id' => (string) $download->get_id(),
            'name' => (string) $download->get_name(),
            'file' => (string) $download->get_file(),
            'enabled' => (bool) $download->get_enabled(),
        ];
    }

    /**
     * Copies a list of parent downloads onto one variation, unless the variation has its own.
     *
     * After each copy the fingerprint of what was written is stored in INHERITED_DOWNLOADS_META.
     * A variation whose current downloads no longer match that fingerprint was edited by someone
     * else (a site admin in WooCommerce), so it is left alone. A variation with no downloads, or
     * with no fingerprint yet (copied by an earlier plugin version, which always overwrote), is
     * treated as inheriting. Nothing is saved when the variation already has the parent's
     * downloads, which keeps repeated syncs (one per language) idempotent.
     */
    private static function applyPayloadsToVariation(
        \WC_Product_Variation $variation,
        array $download_payloads,
        int $element_index
    ): void {
        $variation_id = (int) $variation->get_id();
        $current_fingerprint = self::fingerprintDownloads(array_values(array_map(
            [self::class, 'buildCrudPayload'],
            $variation->get_downloads()
        )));
        $target_fingerprint = self::fingerprintDownloads($download_payloads);
        $inherited_fingerprint = $variation_id > 0
            ? \get_post_meta($variation_id, self::INHERITED_DOWNLOADS_META, true)
            : '';

        $has_own_downloads = $current_fingerprint !== self::fingerprintDownloads([])
            && is_string($inherited_fingerprint)
            && $inherited_fingerprint !== ''
            && $inherited_fingerprint !== $current_fingerprint;
        if ($has_own_downloads) {
            return;
        }

        if ($current_fingerprint === $target_fingerprint && $variation->get_downloadable() === ($download_payloads !== [])) {
            if ($variation_id > 0 && $inherited_fingerprint !== $target_fingerprint) {
                \update_post_meta($variation_id, self::INHERITED_DOWNLOADS_META, $target_fingerprint);
            }

            return;
        }

        try {
            $variation->set_downloads($download_payloads);
            $variation->set_downloadable($download_payloads !== []);
            $variation->save();

            $variation_id = (int) $variation->get_id();
            if ($variation_id > 0) {
                // Fingerprint what WooCommerce actually stored, which is what the next sync reads back.
                // Read back, as in syncVariableProductToVariations(): the object still holds dropped ones.
                $stored = \wc_get_product($variation_id);
                \update_post_meta($variation_id, self::INHERITED_DOWNLOADS_META, self::fingerprintDownloads(array_values(array_map(
                    [self::class, 'buildCrudPayload'],
                    ($stored instanceof \WC_Product ? $stored : $variation)->get_downloads()
                ))));
            }
        } catch (\Throwable $e) {
            throw onpage_http_exception(
                self::ERROR_PREFIX . " :: Element $element_index :: Failed to sync downloadable files to variation " . (int) $variation->get_id() . " :: " . $e->getMessage(),
                400,
                'invalid_param'
            );
        }
    }

    /** Order-independent fingerprint of a list of CRUD download payloads (id, name, file). */
    private static function fingerprintDownloads(array $download_payloads): string
    {
        $entries = array_map(
            static fn(array $payload): string => implode("\0", [
                (string) ($payload['download_id'] ?? ''),
                (string) ($payload['name'] ?? ''),
                (string) ($payload['file'] ?? ''),
            ]),
            $download_payloads
        );
        sort($entries, \SORT_STRING);

        return md5(implode("\n", $entries));
    }

    /** Resolves a downloadable file payload value for one product language. */
    private static function resolveDownloadValue(mixed $value, ?string $language_code, ?string $fallback_language): string|null
    {
        if (is_array($value)) {
            if ($language_code && array_key_exists($language_code, $value)) {
                $value = $value[$language_code];
            } elseif ($fallback_language && array_key_exists($fallback_language, $value)) {
                $value = $value[$fallback_language];
            } else {
                return null;
            }
        }

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * Resolves a `downloads[].file` payload value for one product language, unwrapping the
     * WPML language map without casting to string, so an attachment ID keeps its int type.
     */
    private static function resolveDownloadFileRawValue(mixed $value, ?string $language_code, ?string $fallback_language): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($language_code && array_key_exists($language_code, $value)) {
            return $value[$language_code];
        }

        if ($fallback_language && array_key_exists($fallback_language, $value)) {
            return $value[$fallback_language];
        }

        return null;
    }

    /** Resolves a `downloads[].file` value to a usable WooCommerce file URL, accepting an existing attachment ID or a remote URL. */
    private static function resolveDownloadFileUrl(mixed $raw_file, int $element_index, int $download_index, bool $force_refresh): string
    {
        if (is_int($raw_file)) {
            if (!RemoteMedia::isAttachmentId($raw_file)) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'downloads.$download_index.file' must be an existing attachment ID or a valid URL", 400, 'invalid_param');
            }

            $attachment_url = \wp_get_attachment_url($raw_file);
            if (!is_string($attachment_url) || $attachment_url === '') {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Failed to resolve attachment URL for 'downloads.$download_index.file'", 500, 'request_failed');
            }

            self::ensureWooApprovedDownloadDirectory($attachment_url);

            return $attachment_url;
        }

        if (!is_scalar($raw_file)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'downloads.$download_index.file' must be an existing attachment ID or a valid URL", 400, 'invalid_param');
        }

        $file_url = RemoteMedia::sanitizeUrl((string) $raw_file);
        if ($file_url === null) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'downloads.$download_index.file' must resolve to an existing attachment ID or a valid URL", 400, 'invalid_param');
        }

        return self::normalizeDownloadFileUrl($file_url, $element_index, $download_index, $force_refresh);
    }

    /** Filename fallback for a WooCommerce downloadable file URL. */
    private static function getDownloadNameFromUrl(string $url): string
    {
        if (\function_exists('wc_get_filename_from_url')) {
            $filename = \wc_get_filename_from_url($url);
            if (is_string($filename) && $filename !== '') {
                return $filename;
            }
        }

        $path = (string) \wp_parse_url($url, \PHP_URL_PATH);
        $filename = basename($path);

        return $filename !== '' && $filename !== '.' && $filename !== '/'
            ? \sanitize_file_name($filename)
            : 'download';
    }

    /** Whether the download URL should be imported into Media Library for WooCommerce compatibility. */
    private static function shouldImportDownloadToMediaLibrary(string $url): bool
    {
        $uploads_base_url = self::getUploadsBaseUrl();

        if ($uploads_base_url !== '' && str_starts_with($url, $uploads_base_url . '/')) {
            return false;
        }

        return true;
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

    /** Returns local filesystem path for an attachment when available and existing. */
    private static function getExistingAttachmentPath(int $attachment_id): ?string
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

        return \file_exists($path) ? $path : null;
    }

    /**
     * Ensures WooCommerce's approved download directories cover a local file URL the product uses.
     *
     * Only URLs WordPress produced for Media Library files reach this method, never the remote
     * URL a client sent. A file under the uploads base URL is covered by a single rule for that
     * base (WooCommerce matches a download against its parent directories, so a per-file rule is
     * not possible), added once instead of one rule per `uploads/YYYY/MM` folder. A URL outside
     * it (uploads offloaded to a CDN by another plugin) falls back to its own directory.
     */
    private static function ensureWooApprovedDownloadDirectory(string $file_url): void
    {
        if (!\function_exists('wc_get_container')) {
            return;
        }

        $uploads_base_url = self::getUploadsBaseUrl();
        $is_under_uploads = $uploads_base_url !== '' && str_starts_with($file_url, $uploads_base_url . '/');
        if ($is_under_uploads && self::$uploads_directory_approved) {
            return;
        }

        $register_class = '\\Automattic\\WooCommerce\\Internal\\ProductDownloads\\ApprovedDirectories\\Register';
        if (!\class_exists($register_class)) {
            return;
        }

        try {
            $register = \wc_get_container()->get($register_class);
            if (!is_object($register) || !\method_exists($register, 'get_mode') || !\method_exists($register, 'add_approved_directory')) {
                return;
            }

            $enabled_mode = \defined($register_class . '::MODE_ENABLED')
                ? \constant($register_class . '::MODE_ENABLED')
                : 'enabled';
            if ($register->get_mode() !== $enabled_mode) {
                return;
            }

            $directory = $is_under_uploads
                ? $uploads_base_url
                : \untrailingslashit((string) \dirname($file_url));
            if ($directory === '' || $directory === '.' || $directory === '/') {
                return;
            }

            $directory_id = $register->add_approved_directory($directory, true);
            if (is_int($directory_id) && $directory_id > 0 && \method_exists($register, 'enable_by_id')) {
                $register->enable_by_id($directory_id);
            }

            if ($is_under_uploads) {
                self::$uploads_directory_approved = true;
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /** Uploads base URL without trailing slash, or an empty string when unavailable. */
    private static function getUploadsBaseUrl(): string
    {
        $uploads = \wp_get_upload_dir();

        return is_string($uploads['baseurl'] ?? null)
            ? \untrailingslashit((string) $uploads['baseurl'])
            : '';
    }

    /** Returns a WooCommerce-safe downloadable file reference. */
    private static function normalizeDownloadFileUrl(string $url, int $element_index, int $download_index, bool $force_refresh = false): string
    {
        if (!self::shouldImportDownloadToMediaLibrary($url)) {
            self::ensureWooApprovedDownloadDirectory($url);
            return $url;
        }

        // The remote URL is not approved: WooCommerce only ever receives the imported local URL,
        // approved below once the import succeeded.
        try {
            $media = RemoteMedia::urlToMediaLibrary($url, 'downloads_' . $download_index, self::DOWNLOAD_IMPORT_TIMEOUT_SECONDS, $force_refresh);
        } catch (\Throwable $e) {
            throw onpage_http_exception(
                self::ERROR_PREFIX . " :: Element $element_index :: Failed to import downloadable file for 'downloads.$download_index.file' :: " . $e->getMessage(),
                500,
                'request_failed'
            );
        }

        $attachment_id = (int) ($media['attachment_id'] ?? 0);
        $attachment_path = self::getExistingAttachmentPath($attachment_id);
        if ($attachment_path === null) {
            throw onpage_http_exception(
                self::ERROR_PREFIX . " :: Element $element_index :: Imported downloadable file for 'downloads.$download_index.file' is missing on disk",
                500,
                'request_failed'
            );
        }

        $attachment_url = \wp_get_attachment_url($attachment_id);
        if (!is_string($attachment_url) || $attachment_url === '') {
            throw onpage_http_exception(
                self::ERROR_PREFIX . " :: Element $element_index :: Failed to resolve imported downloadable file URL for 'downloads.$download_index.file'",
                500,
                'request_failed'
            );
        }

        self::ensureWooApprovedDownloadDirectory($attachment_url);

        return $attachment_url;
    }

    private static function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}