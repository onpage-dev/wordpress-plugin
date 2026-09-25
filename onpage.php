<?php



/**
 * Plugin Name:       On Page®
 * Description:       Riceve e sincronizza in WordPress i dati strutturati provenienti da On Page®, esponendo le API REST sotto /wp-json/onpage/v1.
 * Version:           1.0.0
 * Requires at least: 7.1
 * Requires PHP:      8.2
 * Author:            On Page®
 * Author URI:        https://www.onpage.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        false
 */



if (!defined('ABSPATH')) exit;



define('ONPAGE_PLUGIN_DIR', __DIR__);



include 'src/Env.php';



include 'src/Exceptions/HttpException.php';



include 'src/helpers.php';



include 'src/Services/Auth.php';
include 'src/Services/Acf.php';
include 'src/Services/FieldGroup.php';
include 'src/Services/Input.php';
include 'src/Services/MultiLang.php';
include 'src/Services/Wpml.php';
include 'src/Services/Media.php';
include 'src/Services/PostRepository.php';
include 'src/Services/Post.php';
include 'src/Services/PostType.php';
include 'src/Services/Svg.php';
include 'src/Services/TermRepository.php';
include 'src/Services/Term.php';
include 'src/Services/Taxonomy.php';
include 'src/Services/RemoteMedia.php';
include 'src/Services/Migration.php';
include 'src/Services/Index.php';
include 'src/Services/WooCommerce/Attribute.php';
include 'src/Services/WooCommerce/Brand.php';
include 'src/Services/WooCommerce/Term.php';
include 'src/Services/WooCommerce/Taxonomy.php';
include 'src/Services/WooCommerce/ProductDownloads.php';
include 'src/Services/WooCommerce/Product.php';
include 'src/Services/WooCommerce/VariantProduct.php';



include 'src/Views/UI.php';



include 'src/Controllers/FieldGroup.php';
include 'src/Controllers/PostType.php';
include 'src/Controllers/Post.php';
include 'src/Controllers/Taxonomy.php';
include 'src/Controllers/Term.php';
include 'src/Controllers/Media.php';
include 'src/Controllers/Migration.php';
include 'src/Controllers/Index.php';
include 'src/Controllers/WooCommerce/Attribute.php';
include 'src/Controllers/WooCommerce/AttributeTerm.php';
include 'src/Controllers/WooCommerce/Brand.php';
include 'src/Controllers/WooCommerce/Category.php';
include 'src/Controllers/WooCommerce/Product.php';
include 'src/Controllers/WooCommerce/VariantProduct.php';
include 'src/Controllers/WooCommerce/Tag.php';



\OnPage\Services\Taxonomy::boot();
\OnPage\Services\WooCommerce\Product::boot();
\OnPage\Views\UI::boot();



include 'src/Middlewares/Auth.php';



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
        include ONPAGE_PLUGIN_DIR . '/routes.php';

        return;
    }

    \add_action('admin_notices', function (): void {
        if (!\current_user_can('activate_plugins')) return;

        echo '<div class="notice notice-error"><p><strong>On Page®</strong> richiede il plugin <strong>Advanced Custom Fields</strong>. Finché ACF non è installato e attivo, le API REST di On Page® restano disattivate.</p></div>';
    });
});
