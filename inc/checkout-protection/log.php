<?php
/**
 * Checkout Protection — blocked-attempts log.
 *
 * One row per blocked attempt in its own table ({prefix}gph_blocked_attempts),
 * with what the customer entered, so staff can investigate an incident or
 * look up a reference code. Card details are never received, so never stored.
 *
 * Size is bounded two ways: rows older than the retention setting (default
 * 90 days) and anything beyond GPH_CP_LOG_MAX_ROWS are deleted daily.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

define( 'GPH_CP_LOG_MAX_ROWS', 10000 );

function gph_cp_table() {
	global $wpdb;
	return $wpdb->prefix . 'gph_blocked_attempts';
}

/**
 * Does the log table exist? Read from the autoloaded version option, so it
 * costs no query. The table is created on the first block (or when v1 data
 * needs migrating), not before — so after "Delete all data" nothing is
 * recreated until the module actually blocks someone again.
 *
 * @return bool
 */
function gph_cp_log_ready() {
	return (bool) get_option( GPH_CP_DB_VERSION_OPT );
}

/* -------------------------------------------------------------------------
 * Install / upgrade
 * ---------------------------------------------------------------------- */

/**
 * Create or upgrade the table when the stored version differs. Called before
 * the first write, and from our admin screen when v1 data needs migrating or
 * the table needs upgrading — never on normal page views.
 */
function gph_cp_maybe_install() {
	if ( GPH_CP_DB_VERSION === get_option( GPH_CP_DB_VERSION_OPT ) ) {
		return;
	}

	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = gph_cp_table();
	$charset = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		ref varchar(20) NOT NULL,
		created_at datetime NOT NULL,
		source varchar(40) NOT NULL,
		reason varchar(191) NOT NULL,
		first_name varchar(100) NOT NULL DEFAULT '',
		last_name varchar(100) NOT NULL DEFAULT '',
		email varchar(191) NOT NULL DEFAULT '',
		phone varchar(40) NOT NULL DEFAULT '',
		address text NOT NULL,
		items text NOT NULL,
		total decimal(12,2) NOT NULL DEFAULT 0,
		order_id bigint(20) unsigned NOT NULL DEFAULT 0,
		ip varchar(45) NOT NULL DEFAULT '',
		user_agent varchar(255) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY ref (ref),
		KEY email (email),
		KEY created_at (created_at)
	) {$charset};" );

	gph_cp_migrate_legacy_log();
	gph_cp_schedule_cleanup();

	update_option( GPH_CP_DB_VERSION_OPT, GPH_CP_DB_VERSION, true );
}

/**
 * v1 kept the last 50 blocks in an option. Move them into the table once,
 * then delete the option.
 */
function gph_cp_migrate_legacy_log() {
	global $wpdb;

	$legacy = get_option( GPH_CP_LEGACY_LOG, null );
	if ( null === $legacy ) {
		return;
	}

	if ( is_array( $legacy ) ) {
		foreach ( array_reverse( $legacy ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['time'] ) ) {
				continue;
			}
			$wpdb->insert( gph_cp_table(), array(
				'ref'        => ! empty( $row['ref'] ) ? $row['ref'] : gph_cp_new_ref(),
				'created_at' => gmdate( 'Y-m-d H:i:s', (int) $row['time'] ),
				'source'     => isset( $row['where'] ) ? mb_substr( $row['where'], 0, 40 ) : '',
				'reason'     => isset( $row['reason'] ) ? mb_substr( ucfirst( $row['reason'] ), 0, 191 ) : '',
				'email'      => isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '',
				'ip'         => isset( $row['ip'] ) ? mb_substr( $row['ip'], 0, 45 ) : '',
				'address'    => '',
				'items'      => '',
			) );
		}
	}

	delete_option( GPH_CP_LEGACY_LOG );
}

/* -------------------------------------------------------------------------
 * Collecting details
 * ---------------------------------------------------------------------- */

function gph_cp_format_address( $a1, $a2, $city, $state, $zip, $country ) {
	$line2 = trim( implode( ', ', array_filter( array( $city, trim( $state . ' ' . $zip ) ) ) ) );
	return trim( implode( "\n", array_filter( array( trim( $a1 . ' ' . $a2 ), $line2, $country ) ) ) );
}

/**
 * Details from the checkout form + cart.
 *
 * @param array $data WooCommerce posted checkout data.
 * @return array
 */
function gph_cp_details_from_checkout( $data ) {
	$g = function ( $key ) use ( $data ) {
		return isset( $data[ $key ] ) ? (string) $data[ $key ] : '';
	};

	$items = array();
	$total = 0;
	if ( function_exists( 'WC' ) && WC()->cart ) {
		foreach ( WC()->cart->get_cart() as $line ) {
			$product = isset( $line['data'] ) ? $line['data'] : null;
			if ( $product instanceof WC_Product ) {
				$sku     = $product->get_sku();
				$items[] = ( $sku ? $sku . ' — ' : '' ) . $product->get_name() . ' × ' . (int) $line['quantity'];
			}
		}
		$total = (float) WC()->cart->get_total( 'edit' );
	}

	return array(
		'first_name' => $g( 'billing_first_name' ),
		'last_name'  => $g( 'billing_last_name' ),
		'email'      => $g( 'billing_email' ),
		'phone'      => $g( 'billing_phone' ),
		'address'    => gph_cp_format_address( $g( 'billing_address_1' ), $g( 'billing_address_2' ), $g( 'billing_city' ), $g( 'billing_state' ), $g( 'billing_postcode' ), $g( 'billing_country' ) ),
		'items'      => implode( "\n", $items ),
		'total'      => $total,
		'order_id'   => 0,
	);
}

/**
 * Details from an existing order ("pay again" link).
 *
 * @param WC_Order $order Order.
 * @return array
 */
function gph_cp_details_from_order( WC_Order $order ) {
	$items = array();
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		$sku     = $product ? $product->get_sku() : '';
		$items[] = ( $sku ? $sku . ' — ' : '' ) . $item->get_name() . ' × ' . (int) $item->get_quantity();
	}

	return array(
		'first_name' => $order->get_billing_first_name(),
		'last_name'  => $order->get_billing_last_name(),
		'email'      => $order->get_billing_email(),
		'phone'      => $order->get_billing_phone(),
		'address'    => gph_cp_format_address( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city(), $order->get_billing_state(), $order->get_billing_postcode(), $order->get_billing_country() ),
		'items'      => implode( "\n", $items ),
		'total'      => (float) $order->get_total(),
		'order_id'   => $order->get_id(),
	);
}

/* -------------------------------------------------------------------------
 * Writing
 * ---------------------------------------------------------------------- */

/**
 * Record a blocked attempt.
 *
 * @param string $source  Where it was stopped ("Checkout", "Pay again").
 * @param string $reason  Why.
 * @param array  $d       From gph_cp_details_from_*().
 * @return string Reference code.
 */
function gph_cp_log( $source, $reason, $d ) {
	global $wpdb;

	gph_cp_maybe_install(); // Table may not exist yet if no admin has visited since deploy.

	$ref = gph_cp_new_ref();
	$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	$wpdb->insert(
		gph_cp_table(),
		array(
			'ref'        => $ref,
			'created_at' => current_time( 'mysql', true ),
			'source'     => mb_substr( $source, 0, 40 ),
			'reason'     => mb_substr( $reason, 0, 191 ),
			'first_name' => mb_substr( sanitize_text_field( $d['first_name'] ), 0, 100 ),
			'last_name'  => mb_substr( sanitize_text_field( $d['last_name'] ), 0, 100 ),
			'email'      => mb_substr( sanitize_email( $d['email'] ), 0, 191 ),
			'phone'      => mb_substr( sanitize_text_field( $d['phone'] ), 0, 40 ),
			'address'    => sanitize_textarea_field( $d['address'] ),
			'items'      => sanitize_textarea_field( $d['items'] ),
			'total'      => (float) $d['total'],
			'order_id'   => absint( $d['order_id'] ),
			'ip'         => mb_substr( gph_cp_ip(), 0, 45 ),
			'user_agent' => mb_substr( $ua, 0, 255 ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%s' )
	);

	error_log( sprintf( '[GPH protection] %s %s blocked: %s (%s, ip=%s)', $ref, $source, $reason, $d['email'], gph_cp_ip() ) );

	return $ref;
}

/* -------------------------------------------------------------------------
 * Reading (admin)
 * ---------------------------------------------------------------------- */

/**
 * SQL WHERE for the search box: reference, name, email, phone or IP.
 *
 * @param string $search Search text.
 * @return string
 */
function gph_cp_log_where( $search ) {
	global $wpdb;
	$search = trim( (string) $search );
	if ( '' === $search ) {
		return '1=1';
	}
	$like = '%' . $wpdb->esc_like( $search ) . '%';
	return $wpdb->prepare(
		"(ref LIKE %s OR email LIKE %s OR phone LIKE %s OR ip LIKE %s OR CONCAT(first_name, ' ', last_name) LIKE %s)",
		$like, $like, $like, $like, $like
	);
}

/**
 * Number of blocks in the last N hours.
 *
 * @param int $hours Hours.
 * @return int
 */
function gph_cp_log_count_since( $hours ) {
	global $wpdb;
	if ( ! gph_cp_log_ready() ) {
		return 0;
	}
	$table = gph_cp_table();
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
		gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS )
	) );
}

/**
 * Most recent rows.
 *
 * @param int $limit Rows.
 * @return object[]
 */
function gph_cp_log_recent( $limit = 10 ) {
	global $wpdb;
	if ( ! gph_cp_log_ready() ) {
		return array();
	}
	$table = gph_cp_table();
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
}

/* -------------------------------------------------------------------------
 * Daily cleanup
 * ---------------------------------------------------------------------- */

/**
 * Schedule the daily cleanup. Called on install and when our admin screen
 * opens, never on a customer's page view.
 */
function gph_cp_schedule_cleanup() {
	if ( ! wp_next_scheduled( GPH_CP_CRON ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', GPH_CP_CRON );
	}
}

add_action( GPH_CP_CRON, function () {
	global $wpdb;
	if ( ! gph_cp_log_ready() ) {
		return;
	}
	$table = gph_cp_table();
	$days  = (int) gph_cp_settings()['log_days'];

	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$table} WHERE created_at < %s",
		gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
	) );

	$cutoff = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
		GPH_CP_LOG_MAX_ROWS
	) );
	if ( $cutoff ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) );
	}
} );
