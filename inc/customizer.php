<?php
defined('ABSPATH') || exit;

/**
 * Register GPH Customizer settings.
 */
add_action('customize_register', 'gph_core_register_customizer_settings');

function gph_core_register_customizer_settings( WP_Customize_Manager $wp_customize ) {

    /*
     * 1) SKU toggle (lives where users expect it: WooCommerce > Product Catalog)
     * Do NOT return early if the section doesn't exist. Just skip that control.
     */
    if ( $wp_customize->get_section('woocommerce_product_catalog') ) {

        $wp_customize->add_setting('gph_show_loop_sku', array(
            'default'           => true,
            'type'              => 'theme_mod',
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => 'gph_core_sanitize_checkbox',
            'transport'         => 'refresh',
        ));

        $wp_customize->add_control('gph_show_loop_sku', array(
            'label'       => __('Show Item Number (SKU) on product cards', 'gph-core'),
            'description' => __('Displays the SKU under product cards on shop, category, search, related, and upsell sections.', 'gph-core'),
            'section'     => 'woocommerce_product_catalog',
            'type'        => 'checkbox',
            'priority'    => 60,
        ));
    }

    /*
     * 2) Shipping Notice section (one place for cart + checkout messaging)
     */
    $wp_customize->add_section('gph_shipping_notice', array(
        'title'       => __('Shipping Notice', 'gph-core'),
        'description' => __('Configure the shipping notice shown on Cart (inline) and Checkout (popup modal).', 'gph-core'),
        'panel'       => 'woocommerce',
        'priority'    => 45,
    ));

    // Shared message content used in BOTH cart + checkout
    $wp_customize->add_setting('gph_shipping_notice_content', array(
        'default'           => "We are unable to offer free shipping. Since each order is unique, shipping costs vary based on dimensional weight and box size. We do our best to select the most affordable option. Thank you for understanding — please call if you have questions prior to placing your order.",
        'type'              => 'theme_mod',
        'capability'        => 'edit_theme_options',
        'sanitize_callback' => 'wp_kses_post',
        'transport'         => 'refresh',
    ));

    $wp_customize->add_control('gph_shipping_notice_content', array(
        'label'       => __('Notice message', 'gph-core'),
        'description' => __('This message is reused in Cart (inline) and Checkout (popup).', 'gph-core'),
        'section'     => 'gph_shipping_notice',
        'type'        => 'textarea',
        'priority'    => 10,
    ));

    // Toggle: show inline on cart
    $wp_customize->add_setting('gph_cart_notice_enabled', array(
        'default'           => true,
        'type'              => 'theme_mod',
        'capability'        => 'edit_theme_options',
        'sanitize_callback' => 'gph_core_sanitize_checkbox',
        'transport'         => 'refresh',
    ));

    $wp_customize->add_control('gph_cart_notice_enabled', array(
        'label'    => __('Show notice on Cart page', 'gph-core'),
        'section'  => 'gph_shipping_notice',
        'type'     => 'checkbox',
        'priority' => 20,
    ));

    // Toggle: show popup on checkout
    $wp_customize->add_setting('gph_checkout_modal_enabled', array(
        'default'           => true,
        'type'              => 'theme_mod',
        'capability'        => 'edit_theme_options',
        'sanitize_callback' => 'gph_core_sanitize_checkbox',
        'transport'         => 'refresh',
    ));

    $wp_customize->add_control('gph_checkout_modal_enabled', array(
        'label'    => __('Show popup on Checkout', 'gph-core'),
        'section'  => 'gph_shipping_notice',
        'type'     => 'checkbox',
        'priority' => 30,
    ));
}

/**
 * Sanitize checkbox value.
 */
function gph_core_sanitize_checkbox( $checked ) {
    return ( isset($checked) && true == $checked );
}