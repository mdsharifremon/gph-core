<?php
/**
 * Checkout Protection — settings.
 *
 * All admin-editable values live in one option (GPH_CP_SETTINGS), stored with
 * autoload off so normal page loads never read it. Every value is clamped to a
 * safe range so no setting can accidentally block all customers.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Identifier types failures can be counted by. Key => admin label.
 *
 * @return array
 */
function gph_cp_identifier_labels() {
	return array(
		'email'   => __( 'Email address', 'gph-core' ),
		'zip'     => __( 'Billing zip code', 'gph-core' ),
		'namezip' => __( 'Name + zip code together', 'gph-core' ),
		'ip'      => __( 'Internet connection (IP address)', 'gph-core' ),
		'device'  => __( 'Browser (cookie)', 'gph-core' ),
	);
}

/**
 * Default settings. Also the reference for every setting's meaning.
 *
 * @return array
 */
function gph_cp_defaults() {
	return array(
		// Failed-payment limit.
		'enabled'         => 0,  // Off until an admin turns it on.
		'max_fails'       => 2,  // Failures allowed per identifier…
		'window_hours'    => 24, // …within this many hours.
		'identifiers'     => array_keys( gph_cp_identifier_labels() ),

		// Strict mode (site-wide spike).
		'spike_threshold' => 3,  // Site-wide failures…
		'spike_minutes'   => 60, // …within this many minutes switch strict mode on.
		'strict_hours'    => 3,  // How long strict mode lasts.
		'strict_max'      => 1,  // Failures allowed per identifier during strict mode.

		// Alert.
		'alert_enabled'   => 1,
		'alert_email'     => '', // Empty = the site's admin email.

		// Customer message.
		'message'         => 'We\'re unable to complete this order online. Please {contact} and our team will help you finish your purchase.',
		'link_text'       => 'contact us',
		'contact_page'    => 0,  // 0 = find the Contact page automatically, -1 = no link, >0 = page ID.

		// Log.
		'log_days'        => 90,
	);
}

/**
 * Current settings merged over defaults. Cached for the request.
 *
 * @param bool $refresh Re-read from the database (after saving).
 * @return array
 */
function gph_cp_settings( $refresh = false ) {
	static $settings = null;

	if ( null === $settings || $refresh ) {
		$saved    = get_option( GPH_CP_SETTINGS, array() );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), gph_cp_defaults() );
	}

	return $settings;
}

/**
 * Save settings (already sanitized).
 *
 * @param array $settings Output of gph_cp_sanitize_settings().
 */
function gph_cp_save_settings( $settings ) {
	update_option( GPH_CP_SETTINGS, $settings, false );
	gph_cp_settings( true );
}

/**
 * Clamp a number into a range.
 */
function gph_cp_clamp( $value, $min, $max ) {
	return max( $min, min( $max, absint( $value ) ) );
}

/**
 * Validate the settings form. Anything missing or out of range falls back to
 * a safe value; text is stored as plain text only.
 *
 * @param array $in Raw form input (unslashed).
 * @return array
 */
function gph_cp_sanitize_settings( $in ) {
	$d   = gph_cp_defaults();
	$get = function ( $key ) use ( $in, $d ) {
		return isset( $in[ $key ] ) ? $in[ $key ] : $d[ $key ];
	};

	$identifiers = isset( $in['identifiers'] ) && is_array( $in['identifiers'] )
		? array_values( array_intersect( array_map( 'sanitize_key', $in['identifiers'] ), array_keys( gph_cp_identifier_labels() ) ) )
		: array();

	$out = array(
		'enabled'         => empty( $in['enabled'] ) ? 0 : 1,
		'max_fails'       => gph_cp_clamp( $get( 'max_fails' ), 1, 10 ),
		'window_hours'    => gph_cp_clamp( $get( 'window_hours' ), 1, 72 ),
		'identifiers'     => $identifiers,
		'spike_threshold' => gph_cp_clamp( $get( 'spike_threshold' ), 2, 50 ),
		'spike_minutes'   => gph_cp_clamp( $get( 'spike_minutes' ), 10, 1440 ),
		'strict_hours'    => gph_cp_clamp( $get( 'strict_hours' ), 1, 24 ),
		'strict_max'      => gph_cp_clamp( $get( 'strict_max' ), 1, 5 ),
		'alert_enabled'   => empty( $in['alert_enabled'] ) ? 0 : 1,
		'alert_email'     => '',
		'message'         => '',
		'link_text'       => '',
		'contact_page'    => 0,
		'log_days'        => gph_cp_clamp( $get( 'log_days' ), 7, 365 ),
	);

	// Strict mode can never be looser than the normal limit.
	$out['strict_max'] = min( $out['strict_max'], $out['max_fails'] );

	$email              = sanitize_email( (string) $get( 'alert_email' ) );
	$out['alert_email'] = is_email( $email ) ? $email : '';

	$message          = sanitize_text_field( (string) $get( 'message' ) );
	$link_text        = sanitize_text_field( (string) $get( 'link_text' ) );
	$out['message']   = '' !== $message ? mb_substr( $message, 0, 300 ) : $d['message'];
	$out['link_text'] = '' !== $link_text ? mb_substr( $link_text, 0, 60 ) : $d['link_text'];

	$page = (int) $get( 'contact_page' );
	if ( $page > 0 && 'page' !== get_post_type( $page ) ) {
		$page = 0;
	}
	$out['contact_page'] = max( -1, $page );

	return $out;
}
