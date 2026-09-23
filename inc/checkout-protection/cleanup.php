<?php
/**
 * Checkout Protection — removing data.
 *
 * - Plugin deactivated: gph_cp_on_deactivate() stops the scheduled cleanup.
 *   Data is kept (deactivation is often temporary).
 * - "Delete all Checkout Protection data" button, or a future plugin-level
 *   uninstall.php: gph_cp_delete_all_data() removes everything this module
 *   ever stored. Call it from uninstall.php rather than duplicating it.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin deactivation.
 */
function gph_cp_on_deactivate() {
	wp_clear_scheduled_hook( GPH_CP_CRON );
}

/**
 * Remove every piece of data this module stores. Irreversible.
 */
function gph_cp_delete_all_data() {
	global $wpdb;

	wp_clear_scheduled_hook( GPH_CP_CRON );

	// Log table.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . gph_cp_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// Options.
	foreach ( array( GPH_CP_SETTINGS, GPH_CP_BLOCKLIST, GPH_CP_GEN, GPH_CP_STRICT, GPH_CP_DB_VERSION_OPT, GPH_CP_LEGACY_LOG ) as $option ) {
		delete_option( $option );
	}

	// Counters (transients). With an object cache they live in memory and
	// simply expire; without one, remove the rows now.
	if ( ! wp_using_ext_object_cache() ) {
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_gph_vl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_gph_vl_' ) . '%'
		) );
	}

	// Order meta written by v1 (no longer written in v2).
	$meta_keys = array( '_gph_cp_ip', '_gph_cp_device' );
	$in        = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($in)", $meta_keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta ) ) === $hpos_meta ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$hpos_meta} WHERE meta_key IN ($in)", $meta_keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	gph_cp_settings( true );
}
