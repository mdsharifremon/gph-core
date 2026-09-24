<?php
/**
 * Plugin Name: GPH Core Watchdog
 * Description: Shows a warning on every admin screen while GPH Core is deactivated, deleted or renamed. Must-use plugin: it cannot be deactivated from the dashboard.
 * Version:     1.0.0
 * Author:      Sharif Uddin
 *
 * INSTALL: copy this single file to wp-content/mu-plugins/ (create the folder
 * if it doesn't exist). It is NOT loaded from inside the gph-core plugin folder.
 *
 * Why it exists: GPH Core can warn before it is deactivated, but once it is
 * off it runs no code, so it cannot warn about deletion, a renamed folder, or
 * the host disabling it. This file can, because WordPress always loads
 * must-use plugins.
 *
 * Cost: one hook in wp-admin, nothing on the frontend. While GPH Core is
 * active the check is a single constant lookup.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

if ( is_admin() ) {
	add_action( 'admin_notices', 'gph_core_watchdog_notice' );
}

/**
 * Print a non-dismissible error notice while GPH Core is not running.
 */
function gph_core_watchdog_notice() {
	// Defined by gph-core.php — present means GPH Core is loaded and fine.
	if ( defined( 'GPH_CORE_VERSION' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	// Find GPH Core by its main file name, whatever its folder is called.
	$plugin_file = '';
	foreach ( array_keys( get_plugins() ) as $file ) {
		if ( 'gph-core.php' === basename( $file ) ) {
			$plugin_file = $file;
			break;
		}
	}

	if ( $plugin_file ) {
		$action = sprintf(
			'<a href="%s" class="button button-primary">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $plugin_file ) ), 'activate-plugin_' . $plugin_file ) ),
			esc_html__( 'Activate GPH Core now', 'gph-core' )
		);
		$status = __( 'GPH Core is deactivated.', 'gph-core' );
		$detail = __( 'Checkout fraud protection, product sorting, store notices and SEO rules are all off right now.', 'gph-core' );
	} else {
		$action = '';
		$status = __( 'GPH Core is missing.', 'gph-core' );
		$detail = __( 'It was deleted or its folder was renamed. Checkout fraud protection, product sorting, store notices and SEO rules are all off. Contact the developer to reinstall it — settings and logs are kept in the database and come back on reinstall.', 'gph-core' );
	}

	printf(
		'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p>%3$s</div>',
		esc_html( $status ),
		esc_html( $detail ),
		$action ? '<p>' . $action . '</p>' : '' // Escaped above.
	);
}
