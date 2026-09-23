<?php
defined('ABSPATH') || exit;

/**
 * Register SKU hook for product cards.
 */
add_action('after_setup_theme', 'gph_register_loop_sku_hook', 20);

function gph_register_loop_sku_hook() {

	/*
	 * Astra product-card summary area.
	 * Places SKU inside .astra-shop-summary-wrap,
	 * before the product title/content.
	 *
	 * Do not use woocommerce_shop_loop_item_title here because
	 * Astra may still render it inside the thumbnail/card media area
	 * depending on its loop template structure.
	 */
	add_action('astra_woo_shop_summary_wrap_top', 'gph_loop_item_sku', 5);
}

/**
 * Output SKU in product cards.
 */
function gph_loop_item_sku() {

	if ( ! get_theme_mod('gph_show_loop_sku', true) ) {
		return;
	}

	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$sku = $product->get_sku();

	// If variation SKU is empty, fall back to parent SKU.
	if ( ! $sku && $product->is_type('variation') ) {
		$parent_id = $product->get_parent_id();

		if ( $parent_id ) {
			$parent = wc_get_product($parent_id);

			if ( $parent instanceof WC_Product ) {
				$sku = $parent->get_sku();
			}
		}
	}

	if ( ! $sku ) {
		return;
	}

	echo '<div class="gph-loop-sku">Item Number: <span class="gph-loop-sku__value">' . esc_html($sku) . '</span></div>';
}

/**
 * Body class for styling differences when SKU is toggled on/off.
 */
add_filter('body_class', 'gph_body_class_loop_sku');

function gph_body_class_loop_sku( $classes ) {
	$enabled   = (bool) get_theme_mod('gph_show_loop_sku', true);
	$classes[] = $enabled ? 'gph-loop-sku-on' : 'gph-loop-sku-off';

	return $classes;
}