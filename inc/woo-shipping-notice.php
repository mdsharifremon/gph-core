<?php
defined('ABSPATH') || exit;

/**
 * Shared Shipping Notice
 * - Cart: inline notice (before cart totals)
 * - Checkout: auto-opening modal (once per session)
 *
 * Uses Customizer theme_mods:
 * - gph_shipping_notice_content (shared message)
 * - gph_cart_notice_enabled (bool)
 * - gph_checkout_modal_enabled (bool)
 */

/**
 * Central default message (kept in one place).
 */
function gph_shipping_notice_default_message(): string {
	return "We are unable to offer free shipping. Since each order is unique, shipping costs vary based on dimensional weight and box size. We do our best to select the most affordable option. Thank you for understanding — please call if you have questions prior to placing your order.";
}

/**
 * Helper: get sanitized notice HTML. Returns empty string if nothing configured.
 */
function gph_get_shipping_notice_html(): string {
	$default = gph_shipping_notice_default_message();

	$content = get_theme_mod('gph_shipping_notice_content', $default);
	$content = is_string($content) ? trim($content) : '';

	if ($content === '') {
		return '';
	}

	// Allow basic formatting + links
	return wp_kses_post(wpautop($content));
}

/**
 * CART: Inline notice (before cart totals).
 */
add_action('woocommerce_before_cart_totals', 'gph_cart_shipping_notice', 10);

function gph_cart_shipping_notice(): void {

	// Safer than is_cart(); only show when cart context exists.
	if ( ! function_exists('WC') || ! WC()->cart ) {
		return;
	}

	if ( ! get_theme_mod('gph_cart_notice_enabled', true) ) {
		return;
	}

	$html = gph_get_shipping_notice_html();
	if ($html === '') {
		return;
	}
	?>
	<div class="gph-cart-shipping-notice" role="note" aria-live="polite">
		<h2 class="gph-cart-shipping-notice__title">
			<?php esc_html_e('Why is shipping not included in my cost?', 'gph-core'); ?>
		</h2>

		<div class="gph-cart-shipping-notice__description">
			<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>
	<?php
}

/**
 * CHECKOUT: Auto-opening modal (no Bootstrap).
 * Opens once per browser session (sessionStorage).
 */
add_action('wp_footer', 'gph_checkout_shipping_notice_modal', 20);

function gph_checkout_shipping_notice_modal(): void {

	if ( ! function_exists('is_checkout') ) {
		return;
	}

	// Checkout only; never show on thankyou/order-received.
	if ( ! is_checkout() || is_order_received_page() ) {
		return;
	}

	if ( ! get_theme_mod('gph_checkout_modal_enabled', true) ) {
		return;
	}

	$html = gph_get_shipping_notice_html();
	if ($html === '') {
		return;
	}

	?>
	<div id="gph-checkout-modal-overlay" class="gph-modal-overlay" aria-hidden="true" style="display:none;">
		<div class="gph-modal" role="dialog" aria-modal="true" aria-labelledby="gph-modal-title" tabindex="-1">
			<button type="button" class="gph-modal-close" aria-label="<?php esc_attr_e('Close modal', 'gph-core'); ?>">&times;</button>

			<h3 id="gph-modal-title"><?php esc_html_e('Why is shipping not included in my cost?', 'gph-core'); ?></h3>

			<div class="gph-modal-body">
				<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
	</div>

	<script>
	(function(){
		var overlay = document.getElementById('gph-checkout-modal-overlay');
		if(!overlay) return;

		var modal = overlay.querySelector('.gph-modal');
		var closeBtn = overlay.querySelector('.gph-modal-close');
		var lastFocus = null;

		function openModal(){
			lastFocus = document.activeElement;

			overlay.style.display = 'flex';
			overlay.setAttribute('aria-hidden','false');

			// Move focus into modal for accessibility.
			if (modal) modal.focus();
		}

		function closeModal(){
			overlay.style.display = 'none';
			overlay.setAttribute('aria-hidden','true');

			// Restore focus.
			if (lastFocus && typeof lastFocus.focus === 'function') {
				lastFocus.focus();
			}
		}

		// Open only once per session to avoid annoying users.
		/* 
		  try {
			var key = 'gphShippingNoticeModalSeen';
			if (!sessionStorage.getItem(key)) {
				window.addEventListener('load', function () {
					openModal();
					sessionStorage.setItem(key, '1');
				});
			}
		} catch(e) {
			// If sessionStorage blocked, fall back to current behavior.
			window.addEventListener('load', openModal);
		}
		*/
		// Always open on every checkout load (legacy behavior)
		window.addEventListener('load', openModal);

		if (closeBtn) closeBtn.addEventListener('click', closeModal);

		overlay.addEventListener('click', function(e){
			if(e.target === overlay) closeModal();
		});

		document.addEventListener('keydown', function(e){
			if(e.key === 'Escape') closeModal();
		});
	})();
	</script>
	<?php
}


/**
 * Shipping messaging parity with legacy site:
 * - Cart/Checkout: hide "Free shipping — $0.00" and show "Calculated after checkout"
 * - Cart totals: show heading as "Shipping"
 * - Order views/emails: show "Calculated after checkout" when shipping total is 0
 * - Disable shipping calculator UI (change address / calculator form)
 *
 * Display-only. Does NOT change rates/totals.
 */

/**
 * Central text (easy to change later).
 */
function gph_shipping_calculated_after_checkout_text(): string {
	return __('Calculated after checkout', 'gph-core');
}

/**
 * CART + CHECKOUT: replace shipping method full label.
 * Targets Free Shipping (cost 0) so customers never see "Free shipping — $0.00".
 */
add_filter('woocommerce_cart_shipping_method_full_label', 'gph_shipping_method_full_label_replace', 10, 2);

function gph_shipping_method_full_label_replace($label, $method) {
	if (!is_object($method)) {
		return $label;
	}

	$method_id = method_exists($method, 'get_method_id')
		? $method->get_method_id()
		: (property_exists($method, 'method_id') ? $method->method_id : '');

	$cost = method_exists($method, 'get_cost')
		? (float) $method->get_cost()
		: (property_exists($method, 'cost') ? (float) $method->cost : null);

	if ($method_id === 'free_shipping' && ($cost === 0.0 || $cost === null)) {
		return esc_html(gph_shipping_calculated_after_checkout_text());
	}

	return $label;
}

/**
 * ORDER VIEWS + EMAILS: replace shipping display when shipping total is 0.
 */
add_filter('woocommerce_order_shipping_to_display', 'gph_order_shipping_to_display_replace', 10, 2);

function gph_order_shipping_to_display_replace($shipping_html, $order) {
	if (!$order instanceof WC_Order) {
		return $shipping_html;
	}

	if ((float) $order->get_shipping_total() === 0.0) {
		return esc_html(gph_shipping_calculated_after_checkout_text());
	}

	return $shipping_html;
}

/**
 * CART/CKO TOTALS: force package name label.
 */
add_filter('woocommerce_shipping_package_name', 'gph_shipping_package_name_cart_totals', 10, 3);

function gph_shipping_package_name_cart_totals($name, $index, $package) {
	// Scope to cart/checkout to avoid unintended global effects.
	if (!(is_cart() || is_checkout())) {
		return $name;
	}
	return esc_html__('Shipping', 'gph-core');
}

/**
 * Disable shipping calculator UI.
 */
add_filter('woocommerce_shipping_show_shipping_calculator', '__return_false');