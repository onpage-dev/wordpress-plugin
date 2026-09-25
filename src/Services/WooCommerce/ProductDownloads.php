<?php



namespace OnPage\Services\WooCommerce;



use OnPage\Services\RemoteMedia;



class ProductDownloads
{
    private const ERROR_PREFIX = 'WooCommerce Product';
    private const PUBLIC_DOWNLOAD_IDS_META = '_onpage_public_download_ids';
    private const DOWNLOAD_IMPORT_TIMEOUT_SECONDS = 12;



    /** Registers frontend WooCommerce hooks managed by the plugin. */
    public static function boot(): void
    {
        \add_action('woocommerce_single_product_summary', [self::class, 'renderProductDownloadLinks'], 35);
    }

    /** API response shape for native WooCommerce downloadable files. */
    public static function buildResponse(mixed $download): array
    {
        return [
            'id' => (string) $download->get_id(),
            'name' => (string) $download->get_name(),
            'file' => (string) $download->get_file(),
            'enabled' => (bool) $download->get_enabled(),
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

        $downloads = [];
        foreach ($params['downloads'] as $download_index => $download_payload) {
            if (!is_array($download_payload)) continue;

            $raw_file = self::resolveDownloadFileRawValue($download_payload['file'] ?? null, $language_code, $fallback_language);
            if ($raw_file === null || $raw_file === '') {
                continue;
            }

            $force_refresh = self::toBool($download_payload['refresh'] ?? false);
            $file_url = self::resolveDownloadFileUrl($raw_file, $element_index, (int) $download_index, $force_refresh);

            $name = self::resolveDownloadValue($download_payload['name'] ?? null, $language_code, $fallback_language)
                ?: self::getDownloadNameFromUrl($file_url);
            $download_id = $download_payload['id'] ?: \wp_generate_uuid4();

            $download = new \WC_Product_Download();
            $download->set_id((string) $download_id);
            $download->set_name($name);
            $download->set_file(\apply_filters('woocommerce_file_download_path', $file_url, $product, $download_index));
            $downloads[] = $download;
        }

        try {
            $product->set_downloads($downloads);
            if ($downloads !== []) {
                $product->set_downloadable(true);
            }
        } catch (\Throwable $e) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Failed to set downloadable files :: " . $e->getMessage(), 400, 'invalid_param');
        }
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

    /** Stores which API-managed downloads should be public on the product page. */
    public static function syncPublicIdsMeta(mixed $product, array $params, int $product_id): void
    {
        if ($product_id <= 0 || !array_key_exists('downloads', $params)) {
            return;
        }

        if (!is_object($product) || !\method_exists($product, 'get_downloads')) {
            \delete_post_meta($product_id, self::PUBLIC_DOWNLOAD_IDS_META);
            return;
        }

        $download_ids = [];
        foreach ($product->get_downloads() as $download) {
            if (is_object($download) && \method_exists($download, 'get_id')) {
                $download_id = (string) $download->get_id();
                if ($download_id !== '') {
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

    /** Syncs parent variable-product downloads to all existing variations when downloads were submitted. */
    public static function syncVariableProductToVariations(mixed $product, array $params, int $element_index): void
    {
        if (!array_key_exists('downloads', $params)) {
            return;
        }

        if (!$product instanceof \WC_Product_Variable && (!\method_exists($product, 'get_type') || $product->get_type() !== 'variable')) {
            return;
        }

        $download_payloads = array_values(array_map(
            [self::class, 'buildCrudPayload'],
            $product->get_downloads()
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
        echo '<h2 class="onpage-product-downloads__title">Documenti</h2>';
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

    /** Copies a list of WooCommerce downloads onto one variation. */
    private static function applyPayloadsToVariation(
        \WC_Product_Variation $variation,
        array $download_payloads,
        int $element_index
    ): void {
        try {
            $variation->set_downloads($download_payloads);
            $variation->set_downloadable($download_payloads !== []);
            $variation->save();
        } catch (\Throwable $e) {
            throw httpException(
                self::ERROR_PREFIX . " :: Element $element_index :: Failed to sync downloadable files to variation " . (int) $variation->get_id() . " :: " . $e->getMessage(),
                400,
                'invalid_param'
            );
        }
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
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'downloads.$download_index.file' must be an existing attachment ID or a valid URL", 400, 'invalid_param');
            }

            $attachment_url = \wp_get_attachment_url($raw_file);
            if (!is_string($attachment_url) || $attachment_url === '') {
                throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Failed to resolve attachment URL for 'downloads.$download_index.file'", 500, 'request_failed');
            }

            self::ensureWooApprovedDownloadDirectory($attachment_url);

            return $attachment_url;
        }

        if (!is_scalar($raw_file)) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'downloads.$download_index.file' must be an existing attachment ID or a valid URL", 400, 'invalid_param');
        }

        $file_url = RemoteMedia::sanitizeUrl((string) $raw_file);
        if ($file_url === null) {
            throw httpException(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'downloads.$download_index.file' must resolve to an existing attachment ID or a valid URL", 400, 'invalid_param');
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
        $uploads = \wp_get_upload_dir();
        $uploads_base_url = is_string($uploads['baseurl'] ?? null)
            ? \untrailingslashit((string) $uploads['baseurl'])
            : '';

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

    /** Ensures WooCommerce approved download directories include the parent of a file reference. */
    private static function ensureWooApprovedDownloadDirectory(string $file_reference): void
    {
        if (!\function_exists('wc_get_container')) {
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

            $parent = \untrailingslashit((string) \dirname($file_reference));
            if ($parent === '' || $parent === '.' || $parent === '/') {
                return;
            }

            $directory_id = $register->add_approved_directory($parent, true);
            if (is_int($directory_id) && $directory_id > 0 && \method_exists($register, 'enable_by_id')) {
                $register->enable_by_id($directory_id);
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /** Returns a WooCommerce-safe downloadable file reference. */
    private static function normalizeDownloadFileUrl(string $url, int $element_index, int $download_index, bool $force_refresh = false): string
    {
        if (!self::shouldImportDownloadToMediaLibrary($url)) {
            self::ensureWooApprovedDownloadDirectory($url);
            return $url;
        }

        self::ensureWooApprovedDownloadDirectory($url);

        try {
            $media = RemoteMedia::urlToMediaLibrary($url, 'downloads_' . $download_index, self::DOWNLOAD_IMPORT_TIMEOUT_SECONDS, $force_refresh);
        } catch (\Throwable $e) {
            throw httpException(
                self::ERROR_PREFIX . " :: Element $element_index :: Failed to import downloadable file for 'downloads.$download_index.file' :: " . $e->getMessage(),
                500,
                'request_failed'
            );
        }

        $attachment_id = (int) ($media['attachment_id'] ?? 0);
        $attachment_path = self::getExistingAttachmentPath($attachment_id);
        if ($attachment_path === null) {
            throw httpException(
                self::ERROR_PREFIX . " :: Element $element_index :: Imported downloadable file for 'downloads.$download_index.file' is missing on disk",
                500,
                'request_failed'
            );
        }

        $attachment_url = \wp_get_attachment_url($attachment_id);
        if (!is_string($attachment_url) || $attachment_url === '') {
            throw httpException(
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