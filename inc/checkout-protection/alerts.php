<?php
/**
 * Checkout Protection — alert email.
 *
 * Staff don't watch the log daily, so the module tells them when something
 * is happening: one email when strict mode switches on. Strict mode can only
 * start once per period, so this can't flood an inbox.
 *
 * Sent with wp_mail(), so delivery depends on the site's mail setup.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where alerts go: the setting, or the site admin email.
 *
 * @return string
 */
function gph_cp_alert_recipient() {
	$email = gph_cp_settings()['alert_email'];
	return is_email( $email ) ? $email : get_option( 'admin_email' );
}

add_action( 'gph_cp_strict_mode_started', function ( $total, $until ) {
	$s = gph_cp_settings();
	if ( ! $s['alert_enabled'] ) {
		return;
	}

	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$url  = admin_url( 'admin.php?page=gph-checkout-protection' );

	/* translators: %s: site name */
	$subject = sprintf( __( '[%s] Unusual number of failed payments', 'gph-core' ), $site );

	$lines = array(
		sprintf(
			/* translators: 1: number of failed payments, 2: minutes */
			__( 'The site had %1$d failed payments within %2$d minutes. This usually means someone is testing stolen cards.', 'gph-core' ),
			$total,
			$s['spike_minutes']
		),
		'',
		sprintf(
			/* translators: 1: end time, 2: failures allowed */
			__( 'Strict mode is now on until %1$s. During this time, anyone whose payment fails %2$d time(s) is paused and asked to contact you.', 'gph-core' ),
			wp_date( get_option( 'time_format' ) . ', ' . get_option( 'date_format' ), $until ),
			$s['strict_max']
		),
		'',
		__( 'No action is needed. To see what is being blocked:', 'gph-core' ),
		$url,
		'',
		__( 'If a real customer contacts you because they were paused, open the link above and click "Reset all counters" on the Overview tab.', 'gph-core' ),
	);

	wp_mail( gph_cp_alert_recipient(), $subject, implode( "\n", $lines ) );
}, 10, 2 );
