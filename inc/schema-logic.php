<?php
/**
 * Structured Data Cleanup 
 * Removes incomplete merchant listing fields to prevent GSC warnings.
 *
 * @package GPH_Core
 */

defined('ABSPATH') || exit;

/**
 * Recursively check if a value contains meaningful data.
 */
function gph_has_meaningful_data( $value ) {
    if ( is_null( $value ) || $value === '' ) {
        return false;
    }
    
    if ( is_array( $value ) ) {
        foreach ( $value as $item ) {
            if ( gph_has_meaningful_data( $item ) ) {
                return true;
            }
        }
        return false;
    }
    
    return true; // Strings, numbers (including 0), booleans are meaningful
}

/**
 * Remove shipping/return schema only if completely empty.
 */
add_filter( 'woocommerce_structured_data_product_offer', function( $offer, $product ) {
    if ( ! is_array( $offer ) ) {
        return $offer;
    }

    if ( isset( $offer['shippingDetails'] ) && ! gph_has_meaningful_data( $offer['shippingDetails'] ) ) {
        unset( $offer['shippingDetails'] );
    }

    if ( isset( $offer['hasMerchantReturnPolicy'] ) && ! gph_has_meaningful_data( $offer['hasMerchantReturnPolicy'] ) ) {
        unset( $offer['hasMerchantReturnPolicy'] );
    }

    return $offer;
}, 10, 2 );