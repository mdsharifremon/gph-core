<?php
/**
 * Plugin Name:       GPH Core
 * Plugin URI:        https://gaspumpheaven.com
 * Description:       Core business logic, SEO rules, and WooCommerce behavior for Gas Pump Heaven. <strong>WARNING:</strong> Deactivating this plugin will break critical site functionality including product sorting, SEO crawl control, and schema markup. Only disable for troubleshooting under expert supervision.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
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
define('GPH_CORE_VERSION', '1.0.0');
define('GPH_CORE_PATH', trailingslashit(plugin_dir_path(__FILE__)));
define('GPH_CORE_URL', trailingslashit(plugin_dir_url(__FILE__)));

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

	// ---- Woo modules (only if WooCommerce is active) ----
	if (class_exists('WooCommerce')) {
		$woo_files = array(
			'inc/woo-logic.php',
			'inc/woo-loop-sku.php',
			'inc/woo-shipping-notice.php',
			'inc/order-notices.php'
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