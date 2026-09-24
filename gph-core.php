<?php
/**
 * Plugin Name:       GPH Core
 * Plugin URI:        https://gaspumpheaven.com
 * Description:       Runs Gas Pump Heaven's store rules: checkout fraud protection (reCAPTCHA and failed-payment limits), product sorting, SKU display, shipping and cart notices, SEO crawl rules and schema. <strong>Do not deactivate or delete.</strong> The site stays online but silently loses all of these. Contact the developer first.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      8.0
 * Author:            Sharif Uddin
 * Author URI:        mailto:sharifwds@gmail.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gph-core
 * Domain Path:       /languages
 *
 * @package GPH_Core
 */

defined('ABSPATH') || exit;

/**
 * Constants.
 */
define('GPH_CORE_VERSION', '1.1.0');
define('GPH_CORE_PATH', trailingslashit(plugin_dir_path(__FILE__)));
define('GPH_CORE_URL', trailingslashit(plugin_dir_url(__FILE__)));
define('GPH_CORE_BASENAME', plugin_basename(__FILE__));

/**
 * Internal logger (debug only).
 */
function gph_core_log($message) {
	if (defined('WP_DEBUG') && WP_DEBUG) {
		error_log('[GPH Core] ' . $message);
	}
}

/**
 * Load translations.
 */
add_action('init', function () {
	load_plugin_textdomain('gph-core', false, dirname(plugin_basename(__FILE__)) . '/languages');
}, 1);

/**
 * WooCommerce feature compatibility.
 *
 * - HPOS (custom_order_tables): compatible. Orders are only read/written via
 *   WooCommerce's order API (wc_get_order, WC_Order methods, order notes);
 *   cleanup.php handles both the posts and HPOS meta tables.
 * - Cart/Checkout blocks: NOT compatible. Checkout protection (reCAPTCHA,
 *   failed-payment limits) hooks the classic [woocommerce_checkout] flow only.
 *   Declaring this makes WooCommerce warn anyone who switches to the block
 *   checkout, instead of protection silently turning off.
 */
add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, false);
	}
});

/**
 * Plugin bootstrap.
 */
add_action('plugins_loaded', 'gph_core_init', 5);

function gph_core_init() {

	// ---- Base modules (always loaded) ----
	$base_files = array(
		'inc/helpers.php',
		'inc/seo-logic.php',
		'inc/schema-logic.php',
	);

	foreach ($base_files as $file) {
		gph_core_require($file);
	}

	// ---- Plugins screen: action links + deactivate warning (admin only) ----
	if (is_admin()) {
		gph_core_require('inc/admin/plugins-screen.php');
	}

	// ---- Woo modules (only if WooCommerce is active) ----
	if (class_exists('WooCommerce')) {
		$woo_files = array(
			'inc/woo-logic.php',
			'inc/woo-loop-sku.php',
			'inc/woo-shipping-notice.php',
			'inc/checkout-protection/bootstrap.php',
		);

		foreach ($woo_files as $file) {
			gph_core_require($file);
		}
	}

	// ---- Customizer module (only when Customizer is running) ----
	add_action('customize_register', function () {
		gph_core_require('inc/customizer.php');
	}, 0);
}

/**
 * Require helper with safe logging.
 */
function gph_core_require($relative_path) {
	$path = GPH_CORE_PATH . ltrim($relative_path, '/');
	if (file_exists($path)) {
		require_once $path;
		return true;
	}

	gph_core_log('Missing file: ' . $relative_path);
	return false;
}

/**
 * Admin notice if WooCommerce is not active.
 */
add_action('admin_notices', function () {
	if (!current_user_can('activate_plugins')) return;
	if (class_exists('WooCommerce')) return;

	echo '<div class="notice notice-error"><p><strong>GPH Core:</strong> WooCommerce is required. Please install and activate WooCommerce.</p></div>';
});

/**
 * Activation / deactivation markers.
 */
register_activation_hook(__FILE__, function () {
	update_option('gph_core_activated', current_time('mysql'));
});

register_deactivation_hook(__FILE__, function () {
	update_option('gph_core_deactivated', current_time('mysql'));
});

/**
 * Self-check (debug only).
 */
add_action('init', function () {
	if (!(defined('WP_DEBUG') && WP_DEBUG)) return;

	if (!function_exists('gph_core_is_woocommerce_active')) {
		gph_core_log('Helper functions missing: gph_core_is_woocommerce_active() not found.');
	}

	// No spam logs
	if (!class_exists('WooCommerce')) {
		gph_core_log('WooCommerce not active.');
	}
}, 20);
