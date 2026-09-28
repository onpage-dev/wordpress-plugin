<?php



require_once __DIR__ . '/Router.php';



use OnPage\Router;

use OnPage\Middlewares\Auth as AuthMiddleware;

use OnPage\Controllers\FieldGroup as FieldGroupController;
use OnPage\Controllers\PostType as PostTypeController;
use OnPage\Controllers\Post as PostController;
use OnPage\Controllers\Taxonomy as TaxonomyController;
use OnPage\Controllers\Term as TermController;
use OnPage\Controllers\Media as MediaController;
use OnPage\Controllers\Migration as MigrationController;
use OnPage\Controllers\Index as IndexController;
use OnPage\Controllers\Language as LanguageController;
use OnPage\Controllers\WooCommerce\Attribute as WooCommerceAttributeController;
use OnPage\Controllers\WooCommerce\AttributeTerm as WooCommerceAttributeTermController;
use OnPage\Controllers\WooCommerce\Brand as WooCommerceBrandController;
use OnPage\Controllers\WooCommerce\Category as WooCommerceCategoryController;
use OnPage\Controllers\WooCommerce\Product as WooCommerceProductController;
use OnPage\Controllers\WooCommerce\VariantProduct as WooCommerceVariantProductController;
use OnPage\Controllers\WooCommerce\Tag as WooCommerceTagController;



$router = new Router( 'onpage', 'v1' );

$router->bind('GET', '/field-groups', [FieldGroupController::class, 'list'], AuthMiddleware::class);
$router->bind('POST', '/field-groups', [FieldGroupController::class, 'insert'], AuthMiddleware::class);
$router->bind('DELETE', '/field-groups', [FieldGroupController::class, 'delete'], AuthMiddleware::class);

$router->bind('GET', '/post-types', [PostTypeController::class, 'list'], AuthMiddleware::class);
$router->bind('POST', '/post-types', [PostTypeController::class, 'save'], AuthMiddleware::class);
$router->bind('DELETE', '/post-types', [PostTypeController::class, 'delete'], AuthMiddleware::class);

$router->bind('GET', '/posts', [PostController::class, 'list'], AuthMiddleware::class);
$router->bind('GET', '/posts/{id}', [PostController::class, 'find'], AuthMiddleware::class);
$router->bind('POST', '/posts', [PostController::class, 'save'], AuthMiddleware::class);
$router->bind('DELETE', '/posts', [PostController::class, 'delete'], AuthMiddleware::class);

$router->bind('GET', '/taxonomies', [TaxonomyController::class, 'list'], AuthMiddleware::class);
$router->bind('POST', '/taxonomies', [TaxonomyController::class, 'save'], AuthMiddleware::class);
$router->bind('DELETE', '/taxonomies', [TaxonomyController::class, 'delete'], AuthMiddleware::class);

$router->bind('GET', '/terms', [TermController::class, 'list'], AuthMiddleware::class);
$router->bind('POST', '/terms', [TermController::class, 'save'], AuthMiddleware::class);
$router->bind('DELETE', '/terms', [TermController::class, 'delete'], AuthMiddleware::class);

$router->bind('GET', '/media', [MediaController::class, 'list'], AuthMiddleware::class);
$router->bind('POST', '/media', [MediaController::class, 'upload'], AuthMiddleware::class);
$router->bind('DELETE', '/media', [MediaController::class, 'delete'], AuthMiddleware::class);

$router->bind('POST', '/media/link', [MediaController::class, 'link'], AuthMiddleware::class);

$router->bind('POST', '/migration', [MigrationController::class, 'run'], AuthMiddleware::class);

$router->bind('DELETE', '/indexes', [IndexController::class, 'delete'], AuthMiddleware::class);

$router->bind('GET', '/languages', [LanguageController::class, 'list'], AuthMiddleware::class);

$router->bind('GET', '/woocommerce/brands', [WooCommerceBrandController::class, 'list'], AuthMiddleware::class);
$router->bind('POST', '/woocommerce/brands', [WooCommerceBrandController::class, 'save'], AuthMiddleware::class);
$router->bind('DELETE', '/woocommerce/brands', [WooCommerceBrandController::class, 'delete'], AuthMiddleware::class);

$router->bind('GET', '/woocommerce/attributes', [WooCommerceAttributeController::class, 'list'], AuthMiddleware::class);
$router->bind('POST', '/woocommerce/attributes', [WooCommerceAttributeController::class, 'save'], AuthMiddleware::class);
$router->bind('DELETE', '/woocommerce/attributes', [WooCommerceAttributeController::class, 'delete'], AuthMiddleware::class);

$router->bind('GET', '/woocommerce/attributes/{attribute}/terms', [WooCommerceAttributeTermController::class, 'list'], AuthMiddleware::class);
$router->bind('POST', '/woocommerce/attributes/{attribute}/terms', [WooCommerceAttributeTermController::class, 'save'], AuthMiddleware::class);
$router->bind('DELETE', '/woocommerce/attributes/{attribute}/terms', [WooCommerceAttributeTermController::class, 'delete'], AuthMiddleware::class);

$router->bind('GET', '/woocommerce/categories', [WooCommerceCategoryController::class, 'list'], AuthMiddleware::class);
$router->bind('POST', '/woocommerce/categories', [WooCommerceCategoryController::class, 'save'], AuthMiddleware::class);
$router->bind('DELETE', '/woocommerce/categories', [WooCommerceCategoryController::class, 'delete'], AuthMiddleware::class);

$router->bind('GET', '/woocommerce/tags', [WooCommerceTagController::class, 'list'], AuthMiddleware::class);
$router->bind('POST', '/woocommerce/tags', [WooCommerceTagController::class, 'save'], AuthMiddleware::class);
$router->bind('DELETE', '/woocommerce/tags', [WooCommerceTagController::class, 'delete'], AuthMiddleware::class);

$router->bind('GET', '/woocommerce/products', [WooCommerceProductController::class, 'list'], AuthMiddleware::class);
$router->bind('POST', '/woocommerce/products', [WooCommerceProductController::class, 'save'], AuthMiddleware::class);
$router->bind('DELETE', '/woocommerce/products', [WooCommerceProductController::class, 'delete'], AuthMiddleware::class);

$router->bind('GET', '/woocommerce/variant-products', [WooCommerceVariantProductController::class, 'list'], AuthMiddleware::class);
$router->bind('POST', '/woocommerce/variant-products', [WooCommerceVariantProductController::class, 'save'], AuthMiddleware::class);
$router->bind('DELETE', '/woocommerce/variant-products', [WooCommerceVariantProductController::class, 'delete'], AuthMiddleware::class);

$router->resolve();
