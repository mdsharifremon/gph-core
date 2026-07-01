

<?php
/**
 * WooCommerce catalog behavior and product sorting rules.
 *
 * @package GPH_Core
 */

defined('ABSPATH') || exit;

/**
 * Enforce manual catalog sorting order with alphabetical fallback.
 * Respects user-selected sorting from frontend dropdown.
 *
 * @since 1.0.0
 * @param array $args WooCommerce ordering arguments.
 * @return array Modified ordering arguments.
 */
add_filter('woocommerce_get_catalog_ordering_args', 'gph_core_enforce_manual_order', 999);
function gph_core_enforce_manual_order($args)
{
    if (!is_array($args) || !gph_core_is_woocommerce_active()) {
        return $args;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (!empty($_GET['orderby'])) {
        return $args;
    }

    $args['orderby'] = 'menu_order title';
    $args['order'] = 'ASC';

    return $args;
}

/**
 * IMPORTANT — do not remove the above menu_order enforcement.
 * The "Rearrange Products" plugin writes its drag-drop order directly to
 * each product's menu_order field. Without this filter forcing
 * orderby=menu_order as the default, that plugin's reordering would be
 * invisible on the frontend (WooCommerce would fall back to whatever
 * "Default sorting" is set under WooCommerce > Settings > Products).
 */

/**
 * Exclude products from a parent category's product grid if that product
 * is also assigned to one of the parent's subcategories.
 *
 * Problem this solves: WooCommerce's "Show subcategories and products"
 * display type has no native concept of "only show a product on the
 * deepest category it belongs to." A product checked into both a parent
 * and a child category renders in both places. This filter makes the
 * parent-level grid show only products that belong exclusively to the
 * parent (standalone items), while subcategory tiles still link through
 * to their full, correctly-grouped product lists.
 *
 * Compatibility note: this filter only modifies tax_query (which products
 * qualify). It never touches orderby/order, so it has no effect on the
 * "Rearrange Products" plugin's manual ordering above, and it never runs
 * a term query, so it has no effect on the "Taxonomy Order" plugin's
 * subcategory tile ordering. The two concerns (which products show up,
 * and what order they appear in) stay fully independent.
 *
 * @since 1.0.0
 * @param WP_Query $query The main query object.
 */
add_action('pre_get_posts', 'gph_core_exclude_subcategory_products_from_parent');
function gph_core_exclude_subcategory_products_from_parent($query)
{
    if (!gph_core_is_woocommerce_active() || is_admin() || !$query->is_main_query()) {
        return;
    }

    if (!is_product_category()) {
        return;
    }

    $term = get_queried_object();
    if (empty($term->term_id)) {
        return;
    }

    $children = get_term_children($term->term_id, 'product_cat');
    if (empty($children) || is_wp_error($children)) {
        return;
    }

    $tax_query = $query->get('tax_query');
    if (!is_array($tax_query)) {
        $tax_query = [];
    }

    $tax_query[] = [
        'taxonomy' => 'product_cat',
        'field'    => 'term_id',
        'terms'    => $children,
        'operator' => 'NOT IN',
    ];

    $query->set('tax_query', $tax_query);
}


// Old version of the manual order enforcement filter, kept here for reference. The new version above is more robust and respects user-selected sorting from the frontend dropdown.

// add_filter('woocommerce_get_catalog_ordering_args', 'gph_core_enforce_manual_order', 999);
// function gph_core_enforce_manual_order($args)
// {
//     if (!is_array($args) || !gph_core_is_woocommerce_active()) {
//         return $args;
//     }

//     // phpcs:ignore WordPress.Security.NonceVerification.Recommended
//     if (!empty($_GET['orderby'])) {
//         return $args;
//     }

//     $args['orderby'] = 'menu_order title';
//     $args['order'] = 'ASC';

//     return $args;
// }
