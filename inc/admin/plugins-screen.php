<?php
/**
 * Plugins screen (wp-admin/plugins.php) additions for GPH Core.
 *
 * 1. Action links: "Checkout Protection" and "Store Notices" beside Deactivate,
 *    so admins can find the settings (they live in two places, not one page).
 * 2. Deactivate warning: a confirm box before GPH Core is deactivated, from
 *    either the row link or Bulk actions → Deactivate.
 *
 * Why no delete warning here: WordPress only allows deleting a plugin after it
 * is deactivated, and a deactivated plugin runs no code. Deletion is covered
 * by the watchdog must-use plugin (see /mu-plugin/ and the root README.md).
 *
 * Cost: loaded in wp-admin only; the script is enqueued on plugins.php only.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * 1. Action links
 * ---------------------------------------------------------------------- */

add_filter( 'plugin_action_links_' . GPH_CORE_BASENAME, 'gph_core_plugin_action_links' );

/**
 * Add settings links in front of the default links (Deactivate).
 *
 * Each link shows only when its screen exists and the user can open it.
 *
 * @param array $links Existing action links.
 * @return array
 */
function gph_core_plugin_action_links( $links ) {
	$extra = array();

	// Defined only when the Checkout Protection module is loaded (WooCommerce active).
	if ( defined( 'GPH_CP_PAGE' ) && current_user_can( 'manage_woocommerce' ) ) {
		$extra['gph_checkout_protection'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . GPH_CP_PAGE ) ),
			esc_html__( 'Checkout Protection', 'gph-core' )
		);
	}

	// Customizer section registered in inc/customizer.php (lives in the WooCommerce panel).
	if ( class_exists( 'WooCommerce' ) && current_user_can( 'customize' ) ) {
		$extra['gph_store_notices'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( 'autofocus[section]', 'gph_shipping_notice', admin_url( 'customize.php' ) ) ),
			esc_html__( 'Store Notices', 'gph-core' )
		);
	}

	return array_merge( $extra, $links );
}

/* -------------------------------------------------------------------------
 * 2. Deactivate warning
 * ---------------------------------------------------------------------- */

add_action( 'admin_enqueue_scripts', 'gph_core_plugins_screen_assets' );

/**
 * Enqueue the confirm script on the Plugins screen only.
 *
 * @param string $hook_suffix Current admin screen.
 */
function gph_core_plugins_screen_assets( $hook_suffix ) {
	if ( 'plugins.php' !== $hook_suffix || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	wp_enqueue_script(
		'gph-core-plugins-screen',
		GPH_CORE_URL . 'inc/admin/plugins-screen.js',
		array(),
		GPH_CORE_VERSION,
		true
	);

	$data = array(
		'basename' => GPH_CORE_BASENAME,
		'message'  => implode(
			"\n",
			array(
				__( 'Deactivating GPH Core immediately turns off:', 'gph-core' ),
				'',
				'• ' . __( 'Checkout fraud protection (reCAPTCHA and failed-payment limits)', 'gph-core' ),
				'• ' . __( 'Product sorting and category display rules', 'gph-core' ),
				'• ' . __( 'SKU display, shipping and cart notices', 'gph-core' ),
				'• ' . __( 'SEO crawl rules and schema cleanup', 'gph-core' ),
				'',
				__( 'The site stays online, so the damage is easy to miss. Only continue if the developer asked you to.', 'gph-core' ),
				'',
				__( 'Deactivate GPH Core?', 'gph-core' ),
			)
		),
	);

	wp_add_inline_script(
		'gph-core-plugins-screen',
		'window.gphCorePluginsScreen = ' . wp_json_encode( $data ) . ';',
		'before'
	);
}
