<?php
/**
 * Checkout Protection — failed-payment limit and strict mode.
 *
 * How it works:
 * 1. Each failed payment increments a counter for each identifier of that
 *    attempt (email, zip, name+zip, IP, browser cookie) plus one site-wide
 *    counter.
 * 2. guard.php refuses a new attempt when any of its identifiers has reached
 *    the limit.
 * 3. When the site-wide counter reaches the spike threshold, strict mode turns
 *    on for a few hours with a lower limit.
 *
 * Counters are transients with a fixed window from the first failure. They
 * expire on their own; with a persistent object cache they move to memory
 * automatically. "Reset all counters" bumps a generation number instead of
 * deleting rows, so it works the same with or without an object cache.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Request identity
 * ---------------------------------------------------------------------- */

/**
 * Client IP. Plesk's nginx → Apache proxy restores REMOTE_ADDR to the real
 * visitor; X-Forwarded-For is not used because visitors can fake it.
 *
 * @return string
 */
function gph_cp_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * Browser ID from the checkout cookie, or '' if none.
 *
 * @return string
 */
function gph_cp_device() {
	$id = isset( $_COOKIE[ GPH_CP_COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ GPH_CP_COOKIE ] ) ) : '';
	return 32 === strlen( $id ) ? $id : '';
}

/**
 * Give checkout visitors a browser cookie. Survives IP/VPN changes unless the
 * visitor clears cookies. Checkout page only.
 */
add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || gph_cp_device() ) {
		return;
	}

	$id = bin2hex( random_bytes( 16 ) );
	setcookie( GPH_CP_COOKIE, $id, array(
		'expires'  => time() + 30 * DAY_IN_SECONDS,
		'path'     => COOKIEPATH ? COOKIEPATH : '/',
		'domain'   => COOKIE_DOMAIN,
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	) );
	$_COOKIE[ GPH_CP_COOKIE ] = $id;
} );

/**
 * Identifier values for the identifier types enabled in settings.
 *
 * @return array type => normalized value (empty values dropped).
 */
function gph_cp_identifiers( $ip, $email, $first, $last, $zip, $device ) {
	$enabled = gph_cp_settings()['identifiers'];
	$zip     = strtoupper( preg_replace( '/\s+/', '', (string) $zip ) );
	$name    = strtolower( trim( trim( (string) $first ) . ' ' . trim( (string) $last ) ) );

	$all = array(
		'email'   => '' !== trim( (string) $email ) ? gph_cp_normalize_email( $email ) : '',
		'zip'     => $zip,
		'namezip' => ( '' !== $name && '' !== $zip ) ? $name . '|' . $zip : '',
		'ip'      => (string) $ip,
		'device'  => (string) $device,
	);

	$out = array();
	foreach ( $all as $type => $value ) {
		if ( '' !== $value && in_array( $type, $enabled, true ) ) {
			$out[ $type ] = $value;
		}
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Counters
 * ---------------------------------------------------------------------- */

function gph_cp_counter_key( $name ) {
	return 'gph_vl_' . absint( get_option( GPH_CP_GEN, 1 ) ) . '_' . $name;
}

function gph_cp_identifier_counter( $type, $value ) {
	return $type . '_' . md5( $value );
}

function gph_cp_count_get( $name ) {
	$d = get_transient( gph_cp_counter_key( $name ) );
	return ( is_array( $d ) && $d['exp'] > time() ) ? (int) $d['n'] : 0;
}

/**
 * Increment a counter. The window is fixed from the first failure so repeated
 * failures don't keep extending it.
 *
 * @return int New count.
 */
function gph_cp_count_bump( $name, $window ) {
	$key = gph_cp_counter_key( $name );
	$now = time();
	$d   = get_transient( $key );

	if ( ! is_array( $d ) || $d['exp'] <= $now ) {
		$d = array( 'n' => 0, 'exp' => $now + $window );
	}
	$d['n']++;

	set_transient( $key, $d, max( 1, $d['exp'] - $now ) );
	return $d['n'];
}

/**
 * Timestamp strict mode ends, or 0 if not active.
 *
 * @return int
 */
function gph_cp_strict_until() {
	$until = (int) get_option( GPH_CP_STRICT, 0 );
	return $until > time() ? $until : 0;
}

/**
 * Clear every counter and end strict mode. Blocked emails are untouched.
 */
function gph_cp_reset_counters() {
	update_option( GPH_CP_GEN, absint( get_option( GPH_CP_GEN, 1 ) ) + 1, false );
	delete_option( GPH_CP_STRICT );
}

/* -------------------------------------------------------------------------
 * Counting failed payments
 * ---------------------------------------------------------------------- */

/**
 * Count a failed payment made by a customer. Status changes made by staff in
 * the dashboard, and cron, are ignored.
 */
add_action( 'woocommerce_order_status_failed', function ( $order_id, $order = null ) {
	if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() ) {
		return;
	}

	$s = gph_cp_settings();
	if ( ! $s['enabled'] ) {
		return;
	}

	$order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$ids = gph_cp_identifiers(
		gph_cp_ip(),
		$order->get_billing_email(),
		$order->get_billing_first_name(),
		$order->get_billing_last_name(),
		$order->get_billing_postcode(),
		gph_cp_device()
	);

	foreach ( $ids as $type => $value ) {
		gph_cp_count_bump( gph_cp_identifier_counter( $type, $value ), $s['window_hours'] * HOUR_IN_SECONDS );
	}

	// Site-wide spike → strict mode (once per period).
	$total = gph_cp_count_bump( 'global', $s['spike_minutes'] * MINUTE_IN_SECONDS );
	if ( $total >= $s['spike_threshold'] && ! gph_cp_strict_until() ) {
		$until = time() + $s['strict_hours'] * HOUR_IN_SECONDS;
		update_option( GPH_CP_STRICT, $until, false );
		error_log( sprintf( '[GPH protection] strict mode ON for %d hour(s) after %d failed payments', $s['strict_hours'], $total ) );

		/**
		 * Strict mode just switched on. Used by alerts.php.
		 *
		 * @param int $total Site-wide failures in the spike window.
		 * @param int $until Timestamp strict mode ends.
		 */
		do_action( 'gph_cp_strict_mode_started', $total, $until );
	}
}, 10, 2 );
