<?php
/**
 * Checkout Protection — the checkpoints.
 *
 * The one place that decides "block or allow". Runs at the two points where a
 * payment can be attempted, before an order is created or PayTrace is called:
 *
 * 1. Checkout submission (classic [woocommerce_checkout]).
 * 2. The "pay again" link on an existing order.
 *
 * Order of checks: staff bypass → blocked email → failed-payment limit.
 * To add a rule, add it to gph_cp_should_block() only.
 *
 * Note: if the store moves to the block-based Checkout, these hooks don't
 * fire — see README.md.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Should this attempt be refused?
 *
 * @return string Reason (for the log), or '' to allow.
 */
function gph_cp_should_block( $email, $first, $last, $zip ) {
	if ( current_user_can( 'manage_woocommerce' ) ) {
		return '';
	}

	if ( gph_cp_is_blocked_email( $email ) ) {
		return __( 'Blocked email', 'gph-core' );
	}

	$s = gph_cp_settings();
	if ( ! $s['enabled'] ) {
		return '';
	}

	$strict = (bool) gph_cp_strict_until();
	$limit  = $strict ? $s['strict_max'] : $s['max_fails'];
	$labels = gph_cp_identifier_labels();

	foreach ( gph_cp_identifiers( gph_cp_ip(), $email, $first, $last, $zip, gph_cp_device() ) as $type => $value ) {
		$count = gph_cp_count_get( gph_cp_identifier_counter( $type, $value ) );
		if ( $count >= $limit ) {
			return sprintf(
				/* translators: 1: identifier, 2: failures, 3: limit, 4: " (strict mode)" or "" */
				__( '%1$s had %2$d failed payment(s), limit %3$d%4$s', 'gph-core' ),
				$labels[ $type ],
				$count,
				$limit,
				$strict ? __( ' (strict mode)', 'gph-core' ) : ''
			);
		}
	}

	return '';
}

/**
 * Checkpoint 1: checkout. Runs after WooCommerce's own validation and
 * reCAPTCHA; skipped if either already rejected the submission.
 */
add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	if ( $errors->has_errors() || wc_notice_count( 'error' ) > 0 ) {
		return;
	}

	$reason = gph_cp_should_block(
		isset( $data['billing_email'] ) ? $data['billing_email'] : '',
		isset( $data['billing_first_name'] ) ? $data['billing_first_name'] : '',
		isset( $data['billing_last_name'] ) ? $data['billing_last_name'] : '',
		isset( $data['billing_postcode'] ) ? $data['billing_postcode'] : ''
	);

	if ( $reason ) {
		$ref = gph_cp_log( __( 'Checkout', 'gph-core' ), $reason, gph_cp_details_from_checkout( $data ) );
		$errors->add( 'gph_checkout_protection', gph_cp_message( $ref ) );
	}
}, 20, 2 );

/**
 * Checkpoint 2: "pay again" link. This path skips checkout validation
 * (including reCAPTCHA), so it needs its own check.
 */
add_action( 'woocommerce_before_pay_action', function ( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$reason = gph_cp_should_block(
		$order->get_billing_email(),
		$order->get_billing_first_name(),
		$order->get_billing_last_name(),
		$order->get_billing_postcode()
	);

	if ( $reason ) {
		$ref = gph_cp_log( __( 'Pay again', 'gph-core' ), $reason, gph_cp_details_from_order( $order ) );
		wc_add_notice( gph_cp_message( $ref ), 'error' );
		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}
} );
