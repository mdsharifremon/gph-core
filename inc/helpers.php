<?php
/**
 * GPH_CORE : Helpers
 * Utility functions for WooCommerce context detection.
 *
 * @package GPH_Core
 */

defined('ABSPATH') || exit;

/**
 * Check if WooCommerce is active.
 *
 * @since 1.0.0
 * @return bool True if WooCommerce is loaded.
 */
if (!function_exists('gph_core_is_woocommerce_active')) {
    function gph_core_is_woocommerce_active()
    {
        static $is_active = null;
        if (null === $is_active) {
            $is_active = class_exists('WooCommerce');
        }
        return $is_active;
    }
}

/**
 * Check if current request is within WooCommerce context.
 *
 * @since 1.0.0
 * @return bool True if on any WooCommerce page.
 */
if (!function_exists('gph_core_is_woo_context')) {
    function gph_core_is_woo_context()
    {
        if (!gph_core_is_woocommerce_active()) {
            return false;
        }

        return (
            is_shop() ||
            is_product_category() ||
            is_product_tag() ||
            is_product_taxonomy() ||
            is_product() ||
            is_cart() ||
            is_checkout() ||
            is_account_page()
        );
    }
}

/**
 * Check if current page is catalog context (excludes cart/checkout/account).
 *
 * @since 1.0.0
 * @return bool True if on catalog pages.
 */
if (!function_exists('gph_core_is_catalog_context')) {
    function gph_core_is_catalog_context()
    {
        if (!gph_core_is_woocommerce_active() || !function_exists('is_woocommerce')) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_product_search = is_search() && (
            'product' === get_query_var('post_type') ||
            (isset($_GET['post_type']) && 'product' === sanitize_text_field(wp_unslash($_GET['post_type'])))
        );

        return (
            (is_woocommerce() || is_product_taxonomy() || $is_product_search)
            && !is_cart()
            && !is_checkout()
            && !is_account_page()
        );
    }
}


