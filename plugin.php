<?php



/**
 * Plugin Name:       On Page®
 * Description:       Receives and syncs structured data from On Page® into WordPress, exposing a REST API under /wp-json/onpage/v1.
 * Version:           1.0.3
 * Requires at least: 7.1
 * Requires PHP:      8.2
 * Author:            On Page®
 * Author URI:        https://www.onpage.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/onpage-dev/wordpress-plugin
 */



if (!defined('ABSPATH')) exit;



define('ONPAGE_PLUGIN_DIR', __DIR__);



// `src/Env.php` is deliberately not loaded: it reads the `.env` file, which only the tests in
// `src/Tests` use. A stray `.env` in the plugin folder is therefore never read in production.



require_once __DIR__ . '/src/Exceptions/HttpException.php';



require_once __DIR__ . '/src/helpers.php';



require_once __DIR__ . '/src/Services/Auth.php';
require_once __DIR__ . '/src/Services/Acf.php';
require_once __DIR__ . '/src/Services/FieldGroup.php';
require_once __DIR__ . '/src/Services/Input.php';
require_once __DIR__ . '/src/Services/MultiLang.php';
require_once __DIR__ . '/src/Services/Wpml.php';
require_once __DIR__ . '/src/Services/Media.php';
require_once __DIR__ . '/src/Services/PostRepository.php';
require_once __DIR__ . '/src/Services/Post.php';
require_once __DIR__ . '/src/Services/PostType.php';
require_once __DIR__ . '/src/Services/Svg.php';
require_once __DIR__ . '/src/Services/TermRepository.php';
require_once __DIR__ . '/src/Services/Term.php';
require_once __DIR__ . '/src/Services/Taxonomy.php';
require_once __DIR__ . '/src/Services/RemoteMedia.php';
require_once __DIR__ . '/src/Services/Migration.php';
require_once __DIR__ . '/src/Services/Index.php';
require_once __DIR__ . '/src/Services/Language.php';
require_once __DIR__ . '/src/Services/Updater.php';
require_once __DIR__ . '/src/Services/WooCommerce/Attribute.php';
require_once __DIR__ . '/src/Services/WooCommerce/Brand.php';
require_once __DIR__ . '/src/Services/WooCommerce/Term.php';
require_once __DIR__ . '/src/Services/WooCommerce/Taxonomy.php';
require_once __DIR__ . '/src/Services/WooCommerce/ProductDownloads.php';
require_once __DIR__ . '/src/Services/WooCommerce/Product.php';
require_once __DIR__ . '/src/Services/WooCommerce/VariantProduct.php';



require_once __DIR__ . '/src/Views/UI.php';



require_once __DIR__ . '/src/Controllers/FieldGroup.php';
require_once __DIR__ . '/src/Controllers/PostType.php';
require_once __DIR__ . '/src/Controllers/Post.php';
require_once __DIR__ . '/src/Controllers/Taxonomy.php';
require_once __DIR__ . '/src/Controllers/Term.php';
require_once __DIR__ . '/src/Controllers/Media.php';
require_once __DIR__ . '/src/Controllers/Migration.php';
require_once __DIR__ . '/src/Controllers/Index.php';
require_once __DIR__ . '/src/Controllers/Language.php';
require_once __DIR__ . '/src/Controllers/WooCommerce/Attribute.php';
require_once __DIR__ . '/src/Controllers/WooCommerce/AttributeTerm.php';
require_once __DIR__ . '/src/Controllers/WooCommerce/Brand.php';
require_once __DIR__ . '/src/Controllers/WooCommerce/Category.php';
require_once __DIR__ . '/src/Controllers/WooCommerce/Product.php';
require_once __DIR__ . '/src/Controllers/WooCommerce/VariantProduct.php';
require_once __DIR__ . '/src/Controllers/WooCommerce/Tag.php';



\OnPage\Services\Taxonomy::boot();
\OnPage\Services\WooCommerce\Product::boot();
\OnPage\Views\UI::boot();
// Outside the ACF check below: a site missing ACF can still update the plugin.
\OnPage\Services\Updater::boot();



require_once __DIR__ . '/src/Middlewares/Auth.php';



/**
 * ACF is a hard dependency: every controller opens with `Acf::loadFieldTypeMap()`,
 * which reaches `acf_get_field_groups()` with no guard, so without ACF the routes
 * would answer `500 critical error` instead of a usable error. Rather than register
 * routes that cannot work, the plugin registers none and says why. The On Page®
 * settings page stays available: it only generates the auth token and never
 * touches ACF.
 *
 * Checked on `plugins_loaded` — after every plugin is loaded — because a check at
 * include time would depend on plugin load order.
 */
\add_action('plugins_loaded', function (): void {
    if (\function_exists('acf_get_field_groups')) {
        require_once ONPAGE_PLUGIN_DIR . '/src/routes.php';

        // An ACF older than 6.1 still gets the routes, so every call answers the specific
        // `500 acf_version_unsupported` (see Middlewares\Auth) rather than a bare 404.
        if (!\OnPage\Services\Acf::isSupportedVersion()) {
            \add_action('admin_notices', function (): void {
                if (!\current_user_can('activate_plugins')) return;

                echo '<div class="notice notice-error"><p><strong>On Page®</strong> requires <strong>Advanced Custom Fields</strong> ' . \esc_html(\OnPage\Services\Acf::MIN_VERSION) . ' or later. Version ' . \esc_html((string) \OnPage\Services\Acf::installedVersion()) . ' is active. The On Page® REST API answers every call with an error until ACF is updated.</p></div>';
            });
        }

        return;
    }

    \add_action('admin_notices', function (): void {
        if (!\current_user_can('activate_plugins')) return;

        echo '<div class="notice notice-error"><p><strong>On Page®</strong> requires the <strong>Advanced Custom Fields</strong> plugin. The On Page® REST API stays disabled until ACF is installed and active.</p></div>';
    });
});
