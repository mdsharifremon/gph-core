<?php
/**
 * Checkout Protection — "Block this customer's email" on the order screen.
 *
 * Order actions dropdown → "Block this customer's email" → Update.
 * Adds a note to the order recording who blocked it.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_order_actions', function ( $actions ) {
	if ( current_user_can( 'manage_woocommerce' ) ) {
		$actions['gph_block_email'] = __( 'Block this customer\'s email', 'gph-core' );
	}
	return $actions;
} );

add_action( 'woocommerce_order_action_gph_block_email', function ( $order ) {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! $order instanceof WC_Order ) {
		return;
	}

	$email = $order->get_billing_email();
	if ( ! is_email( $email ) ) {
		return;
	}

	$added = gph_cp_block_email( $email );

	$order->add_order_note( sprintf(
		/* translators: 1: email, 2: staff name */
		$added ? __( 'Email %1$s blocked from checkout by %2$s.', 'gph-core' ) : __( 'Email %1$s was already blocked (checked by %2$s).', 'gph-core' ),
		$email,
		wp_get_current_user()->display_name
	) );
} );
