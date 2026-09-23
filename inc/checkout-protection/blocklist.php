<?php
/**
 * Checkout Protection — blocked emails.
 *
 * A staff-managed list of emails that can never check out or retry a payment.
 * Stored as one option (autoload off), read only when checkout is submitted.
 *
 * Matching ignores capitals and "+anything", and ignores dots for Gmail,
 * so j.a.m.e.s.olive+1@Gmail.com matches jamesolive@gmail.com.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize an email for matching (not for display).
 *
 * @param string $email Email address.
 * @return string
 */
function gph_cp_normalize_email( $email ) {
	$email = strtolower( trim( (string) $email ) );
	if ( false === strpos( $email, '@' ) ) {
		return $email;
	}

	list( $local, $domain ) = explode( '@', $email, 2 );
	$local = preg_replace( '/\+.*$/', '', $local );

	if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
		$local  = str_replace( '.', '', $local );
		$domain = 'gmail.com';
	}

	return $local . '@' . $domain;
}

/**
 * Blocked emails as entered (lowercase).
 *
 * @return string[]
 */
function gph_cp_blocklist() {
	$list = get_option( GPH_CP_BLOCKLIST, array() );
	return is_array( $list ) ? $list : array();
}

/**
 * Replace the whole list.
 *
 * @param string[] $emails Valid emails.
 */
function gph_cp_save_blocklist( $emails ) {
	$emails = array_values( array_unique( array_map( 'strtolower', $emails ) ) );
	sort( $emails );
	update_option( GPH_CP_BLOCKLIST, $emails, false );
}

/**
 * Parse pasted text (commas, spaces, semicolons or new lines) into valid emails.
 *
 * @param string $text Raw text.
 * @return string[]
 */
function gph_cp_parse_emails( $text ) {
	$out = array();
	foreach ( preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
		$email = sanitize_email( strtolower( $part ) );
		if ( $email && is_email( $email ) ) {
			$out[] = $email;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Is this email blocked?
 *
 * @param string $email Email address.
 * @return bool
 */
function gph_cp_is_blocked_email( $email ) {
	if ( '' === trim( (string) $email ) ) {
		return false;
	}

	$list = gph_cp_blocklist();
	if ( ! $list ) {
		return false;
	}

	$target = gph_cp_normalize_email( $email );
	foreach ( $list as $blocked ) {
		if ( gph_cp_normalize_email( $blocked ) === $target ) {
			return true;
		}
	}
	return false;
}

/**
 * Add one email.
 *
 * @param string $email Email address.
 * @return bool True if added.
 */
function gph_cp_block_email( $email ) {
	$email = sanitize_email( $email );
	if ( ! is_email( $email ) || gph_cp_is_blocked_email( $email ) ) {
		return false;
	}
	$list   = gph_cp_blocklist();
	$list[] = $email;
	gph_cp_save_blocklist( $list );
	return true;
}

/**
 * Remove an email, including any variation that matches it.
 *
 * @param string $email Email address.
 */
function gph_cp_unblock_email( $email ) {
	$target = gph_cp_normalize_email( $email );
	$list   = array_filter( gph_cp_blocklist(), function ( $blocked ) use ( $target ) {
		return gph_cp_normalize_email( $blocked ) !== $target;
	} );
	gph_cp_save_blocklist( $list );
}
