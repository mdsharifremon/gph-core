<?php
/**
 * Checkout Protection — the message customers see when stopped.
 *
 * One message for every reason (blocked email, limit, strict mode), so an
 * attacker can't tell which rule stopped them. Each block gets a reference
 * code that is shown to the customer, added to the contact link (?ref=) and
 * saved in the log, so staff can look up what happened.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Find the Contact page by common slugs.
 *
 * @return int Page ID or 0.
 */
function gph_cp_detect_contact_page() {
	foreach ( array( 'contact', 'contact-us', 'contactus', 'get-in-touch' ) as $slug ) {
		$page = get_page_by_path( $slug );
		if ( $page && 'publish' === $page->post_status ) {
			return (int) $page->ID;
		}
	}
	return 0;
}

/**
 * Contact link URL, or '' for no link (setting is "No link", or the chosen
 * page was deleted/unpublished — never a broken link).
 *
 * @param string $ref Reference code to append.
 * @return string
 */
function gph_cp_contact_url( $ref = '' ) {
	$choice = (int) gph_cp_settings()['contact_page'];
	if ( -1 === $choice ) {
		return '';
	}

	$page_id = $choice > 0 ? $choice : gph_cp_detect_contact_page();
	if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
		return '';
	}

	$url = get_permalink( $page_id );
	return $ref ? add_query_arg( 'ref', rawurlencode( $ref ), $url ) : $url;
}

/**
 * New reference code, e.g. GPH-3F9A1C.
 *
 * @return string
 */
function gph_cp_new_ref() {
	return 'GPH-' . strtoupper( bin2hex( random_bytes( 3 ) ) );
}

/**
 * Customer-facing message (safe HTML). {contact} becomes the link; if the
 * admin removed {contact}, the link is added at the end.
 *
 * @param string $ref Reference code.
 * @return string
 */
function gph_cp_message( $ref = '' ) {
	$s    = gph_cp_settings();
	$url  = gph_cp_contact_url( $ref );
	$text = esc_html( $s['link_text'] );
	$link = $url
		? sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $url ), $text )
		: $text;

	$message = esc_html( $s['message'] );
	if ( false !== strpos( $message, '{contact}' ) ) {
		$message = str_replace( '{contact}', $link, $message );
	} elseif ( $url ) {
		$message .= ' ' . $link . '.';
	}

	if ( $ref ) {
		/* translators: %s: reference code */
		$message .= ' ' . esc_html( sprintf( __( 'Reference: %s', 'gph-core' ), $ref ) );
	}

	return $message;
}
