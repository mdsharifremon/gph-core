<?php
/**
 * SEO Crawl Control & Canonical Management
 *
 * Handles noindex directives for WooCommerce cart, sorting, and filter URLs,
 * and keeps canonical URLs clean.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Identify if the current request requires a noindex directive.
 *
 * @return string Reason key, or empty string when no noindex rule applies.
 */
function gph_seo_get_noindex_reason() {
	if ( is_admin() || wp_doing_ajax() || ! gph_core_is_woocommerce_active() ) {
		return '';
	}

	// phpcs:ignore WordPress.Security.NonceVerification
	$query_keys = array_keys( (array) $_GET );

	 // Add-to-cart URLs.

	
	if ( gph_seo_query_key_exists( 'add-to-cart', $query_keys ) ) {
		return 'add_to_cart';
	}


	if ( gph_seo_query_key_exists( 'orderby', $query_keys ) ) {
		return 'sorting';
	}


	if ( gph_seo_query_key_exists( 'product-page', $query_keys ) ) {
		return 'product_pagination';
	}

	
	$filter_prefixes = array(
		'filter_',     
		'query_type_',
		'pa_',        
		'attribute_', 
	);

	$filter_exact_keys = array(
		'min_price',
		'max_price',
		'rating_filter',
	);

	foreach ( $query_keys as $key ) {
		$key = (string) $key;

		if ( in_array( $key, $filter_exact_keys, true ) ) {
			return 'filters';
		}

		foreach ( $filter_prefixes as $prefix ) {
			if ( strpos( $key, $prefix ) === 0 ) {
				return 'filters';
			}
		}
	}

	return '';
}

/**
 * Check whether a specific query key exists.
 
 * @param string $needle     Query key to find.
 * @param array  $query_keys Existing query keys.
 *
 * @return bool
 */
function gph_seo_query_key_exists( $needle, $query_keys ) {
	return in_array( $needle, array_map( 'strval', (array) $query_keys ), true );
}

/**
 * Send X-Robots-Tag headers and prevent caching of action URLs.
 */
add_action(
	'send_headers',
	function() {
		if ( headers_sent() ) {
			return;
		}

		$reason = gph_seo_get_noindex_reason();

		if ( ! $reason ) {
			return;
		}

		$directive = ( 'add_to_cart' === $reason ) ? 'noindex, nofollow' : 'noindex, follow';

		header( "X-Robots-Tag: {$directive}", true );

		if ( 'add_to_cart' === $reason ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}

			nocache_headers();
		}
	},
	20
);

/**
 * HTML meta robots fallback.
 * protects against CDN/cache layers stripping or ignoring X-Robots headers.
 */
add_filter( 'wp_robots', 'gph_seo_apply_robots_logic', 999 );
add_filter( 'wpseo_robots', 'gph_seo_apply_robots_logic', 999 );

function gph_seo_apply_robots_logic( $robots ) {
	$reason = gph_seo_get_noindex_reason();

	if ( ! $reason ) {
		return $robots;
	}

	/*
	 * WordPress core format.
	 */
	if ( is_array( $robots ) ) {
		unset( $robots['index'], $robots['follow'] );

		$robots['noindex'] = true;

		if ( 'add_to_cart' === $reason ) {
			$robots['nofollow'] = true;
		} else {
			$robots['follow'] = true;
		}

		return $robots;
	}

	/*
	 * Yoast format.
	 */
	return ( 'add_to_cart' === $reason ) ? 'noindex, nofollow' : 'noindex, follow';
}

/**
 * Rank Math specific robots integration.
 *
 * Kept for portability. Harmless if Rank Math is not active.
 */
add_filter(
	'rank_math/frontend/robots',
	function( $robots ) {
		$reason = gph_seo_get_noindex_reason();

		if ( ! $reason ) {
			return $robots;
		}

		$robots['index'] = 'noindex';

		if ( 'add_to_cart' === $reason ) {
			$robots['follow'] = 'nofollow';
		} else {
			$robots['follow'] = 'follow';
		}

		return $robots;
	},
	999
);

/**
 * Strip query strings from canonical URLs on WooCommerce content pages.
 */
add_filter( 'wpseo_canonical', 'gph_seo_clean_canonical', 50 );
add_filter( 'rank_math/frontend/canonical', 'gph_seo_clean_canonical', 50 );

function gph_seo_clean_canonical( $canonical ) {
	if ( ! is_string( $canonical ) || ! gph_core_is_woocommerce_active() ) {
		return $canonical;
	}

	if ( is_shop() || is_product() || is_product_taxonomy() ) {
		return strtok( $canonical, '?' );
	}

	return $canonical;
}