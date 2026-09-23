<?php
/**
 * GPH Checkout Protection
 *
 * 1. Failed-payment limit — pauses checkout after repeated failed payments from
 *    the same customer details, with a stricter mode during spikes.
 * 2. Blocked emails — staff-managed list.
 *
 * Both run before any order is created or PayTrace is called, on checkout AND
 * on the "pay again" link. All settings: WooCommerce → Checkout Protection.
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

define( 'GPH_CP_SETTINGS', 'gph_cp_settings' );
define( 'GPH_CP_BLOCKLIST', 'gph_blocked_emails' );
define( 'GPH_CP_LOG', 'gph_cp_log' );
define( 'GPH_CP_GEN', 'gph_cp_gen' );
define( 'GPH_CP_STRICT', 'gph_cp_strict_until' );
define( 'GPH_CP_COOKIE', 'gph_dvc' );

/* =========================================================================
 * Settings
 * ====================================================================== */

function gph_cp_identifier_labels() {
	return array(
		'email'   => __( 'Email address', 'gph-core' ),
		'zip'     => __( 'Billing zip code', 'gph-core' ),
		'namezip' => __( 'Name + zip code together', 'gph-core' ),
		'ip'      => __( 'Internet connection (IP address)', 'gph-core' ),
		'device'  => __( 'Browser (cookie)', 'gph-core' ),
	);
}

function gph_cp_defaults() {
	return array(
		'enabled'         => 0,
		'max_fails'       => 2,
		'window_hours'    => 24,
		'identifiers'     => array_keys( gph_cp_identifier_labels() ),
		'spike_threshold' => 3,
		'spike_minutes'   => 60,
		'strict_hours'    => 3,
		'strict_max'      => 1,
	);
}

function gph_cp_settings() {
	$saved = get_option( GPH_CP_SETTINGS, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), gph_cp_defaults() );
}

function gph_cp_clamp( $value, $min, $max ) {
	return max( $min, min( $max, absint( $value ) ) );
}

function gph_cp_sanitize_settings( $in ) {
	$labels      = gph_cp_identifier_labels();
	$identifiers = isset( $in['identifiers'] ) && is_array( $in['identifiers'] )
		? array_values( array_intersect( array_map( 'sanitize_key', $in['identifiers'] ), array_keys( $labels ) ) )
		: array();

	$out = array(
		'enabled'         => empty( $in['enabled'] ) ? 0 : 1,
		'max_fails'       => gph_cp_clamp( isset( $in['max_fails'] ) ? $in['max_fails'] : 2, 1, 10 ),
		'window_hours'    => gph_cp_clamp( isset( $in['window_hours'] ) ? $in['window_hours'] : 24, 1, 72 ),
		'identifiers'     => $identifiers,
		'spike_threshold' => gph_cp_clamp( isset( $in['spike_threshold'] ) ? $in['spike_threshold'] : 3, 2, 50 ),
		'spike_minutes'   => gph_cp_clamp( isset( $in['spike_minutes'] ) ? $in['spike_minutes'] : 60, 10, 1440 ),
		'strict_hours'    => gph_cp_clamp( isset( $in['strict_hours'] ) ? $in['strict_hours'] : 3, 1, 24 ),
		'strict_max'      => gph_cp_clamp( isset( $in['strict_max'] ) ? $in['strict_max'] : 1, 1, 5 ),
	);

	// Strict mode must actually be stricter than normal.
	$out['strict_max'] = min( $out['strict_max'], $out['max_fails'] );

	return $out;
}

/* =========================================================================
 * Helpers
 * ====================================================================== */

function gph_cp_message() {
	return __( 'We couldn\'t process this order online. Please contact us and we\'ll help you complete your purchase.', 'gph-core' );
}

/** Plesk's nginx → Apache proxy restores REMOTE_ADDR; X-Forwarded-For is spoofable. */
function gph_cp_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

function gph_cp_device() {
	$id = isset( $_COOKIE[ GPH_CP_COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ GPH_CP_COOKIE ] ) ) : '';
	return 32 === strlen( $id ) ? $id : '';
}

function gph_cp_is_frontend_request() {
	return ! ( is_admin() && ! wp_doing_ajax() ) && ! wp_doing_cron();
}

/** Lowercase, strip "+anything", strip dots for Gmail. */
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

function gph_cp_normalize_zip( $zip ) {
	return strtoupper( preg_replace( '/\s+/', '', (string) $zip ) );
}

/**
 * Identifier values for the enabled identifier types.
 */
function gph_cp_identifiers( $ip, $email, $first, $last, $zip, $device ) {
	$enabled = gph_cp_settings()['identifiers'];
	$zip     = gph_cp_normalize_zip( $zip );
	$name    = strtolower( trim( $first ) . ' ' . trim( $last ) );

	$all = array(
		'email'   => $email ? gph_cp_normalize_email( $email ) : '',
		'zip'     => $zip,
		'namezip' => ( trim( $name ) && $zip ) ? $name . '|' . $zip : '',
		'ip'      => $ip,
		'device'  => $device,
	);

	$out = array();
	foreach ( $all as $type => $value ) {
		if ( '' !== $value && in_array( $type, $enabled, true ) ) {
			$out[ $type ] = $value;
		}
	}
	return $out;
}

/* ---- Counters (generation number lets "reset" work without DB queries) ---- */

function gph_cp_counter_key( $name ) {
	return 'gph_vl_' . absint( get_option( GPH_CP_GEN, 1 ) ) . '_' . $name;
}

function gph_cp_count_get( $name ) {
	$d = get_transient( gph_cp_counter_key( $name ) );
	return ( is_array( $d ) && $d['exp'] > time() ) ? (int) $d['n'] : 0;
}

/** Fixed window from the first failure, so repeat failures don't extend it. */
function gph_cp_count_bump( $name, $window ) {
	$key = gph_cp_counter_key( $name );
	$now = time();
	$d   = get_transient( $key );

	if ( ! is_array( $d ) || $d['exp'] <= $now ) {
		$d = array( 'n' => 0, 'exp' => $now + $window );
	}
	$d['n']++;

	set_transient( $key, $d, max( 1, $d['exp'] - $now ) );
	return $d['n'];
}

function gph_cp_identifier_counter( $type, $value ) {
	return $type . '_' . md5( $value );
}

function gph_cp_strict_until() {
	$until = (int) get_option( GPH_CP_STRICT, 0 );
	return $until > time() ? $until : 0;
}

function gph_cp_reset_counters() {
	update_option( GPH_CP_GEN, absint( get_option( GPH_CP_GEN, 1 ) ) + 1, false );
	delete_option( GPH_CP_STRICT );
}

/* ---- Blocklist ---- */

function gph_cp_blocklist() {
	$list = get_option( GPH_CP_BLOCKLIST, null );
	if ( null === $list ) {
		$list = array( 'james.olive@gmail.com' ); // Orders #84248, #84249 — Sep 22 2026.
		update_option( GPH_CP_BLOCKLIST, $list, false );
	}
	return is_array( $list ) ? $list : array();
}

function gph_cp_parse_emails( $text ) {
	$parts = preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
	$out   = array();
	foreach ( $parts as $p ) {
		$email = sanitize_email( strtolower( $p ) );
		if ( $email && is_email( $email ) ) {
			$out[] = $email;
		}
	}
	return array_values( array_unique( $out ) );
}

function gph_cp_is_blocked_email( $email ) {
	if ( ! $email ) {
		return false;
	}
	$blocked = array_map( 'gph_cp_normalize_email', gph_cp_blocklist() );
	return in_array( gph_cp_normalize_email( $email ), $blocked, true );
}

/* ---- Log (last 50 blocks) ---- */

function gph_cp_log( $where, $reason, $email ) {
	$log = get_option( GPH_CP_LOG, array() );
	$log = is_array( $log ) ? $log : array();
	array_unshift( $log, array(
		'time'   => time(),
		'where'  => $where,
		'reason' => $reason,
		'email'  => sanitize_email( $email ),
		'ip'     => gph_cp_ip(),
	) );
	update_option( GPH_CP_LOG, array_slice( $log, 0, 50 ), false );
	error_log( sprintf( '[GPH protection] %s blocked: %s (%s, ip=%s)', $where, $reason, $email, gph_cp_ip() ) );
}

/* =========================================================================
 * The check (shared by checkout and "pay again")
 * ====================================================================== */

/**
 * Returns a reason string if this attempt should be refused, or '' to allow.
 */
function gph_cp_should_block( $email, $first, $last, $zip ) {
	if ( current_user_can( 'manage_woocommerce' ) ) {
		return '';
	}

	if ( gph_cp_is_blocked_email( $email ) ) {
		return 'blocked email';
	}

	$s = gph_cp_settings();
	if ( ! $s['enabled'] ) {
		return '';
	}

	$strict = (bool) gph_cp_strict_until();
	$limit  = $strict ? $s['strict_max'] : $s['max_fails'];

	$ids = gph_cp_identifiers( gph_cp_ip(), $email, $first, $last, $zip, gph_cp_device() );
	$labels = gph_cp_identifier_labels();

	foreach ( $ids as $type => $value ) {
		$count = gph_cp_count_get( gph_cp_identifier_counter( $type, $value ) );
		if ( $count >= $limit ) {
			return sprintf(
				'%s had %d failed payment(s), limit %d%s',
				$labels[ $type ],
				$count,
				$limit,
				$strict ? ' (strict mode)' : ''
			);
		}
	}
	return '';
}

/* =========================================================================
 * Hooks
 * ====================================================================== */

// Browser cookie on checkout (survives IP/VPN changes unless cleared).
add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || gph_cp_device() ) {
		return;
	}
	$id = bin2hex( random_bytes( 16 ) );
	setcookie( GPH_CP_COOKIE, $id, array(
		'expires'  => time() + 30 * DAY_IN_SECONDS,
		'path'     => COOKIEPATH ? COOKIEPATH : '/',
		'domain'   => COOKIE_DOMAIN,
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	) );
	$_COOKIE[ GPH_CP_COOKIE ] = $id;
} );

// Remember the customer's IP + browser on the order.
add_action( 'woocommerce_checkout_create_order', function ( $order ) {
	$order->update_meta_data( '_gph_cp_ip', gph_cp_ip() );
	$order->update_meta_data( '_gph_cp_device', gph_cp_device() );
}, 10, 1 );

// Checkout: refuse before the order is created.
add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	if ( $errors->has_errors() ) {
		return;
	}
	$email  = isset( $data['billing_email'] ) ? $data['billing_email'] : '';
	$reason = gph_cp_should_block(
		$email,
		isset( $data['billing_first_name'] ) ? $data['billing_first_name'] : '',
		isset( $data['billing_last_name'] ) ? $data['billing_last_name'] : '',
		isset( $data['billing_postcode'] ) ? $data['billing_postcode'] : ''
	);
	if ( $reason ) {
		gph_cp_log( 'Checkout', $reason, $email );
		$errors->add( 'gph_checkout_protection', gph_cp_message() );
	}
}, 20, 2 );

// "Pay again" link: refuse before PayTrace is called.
add_action( 'woocommerce_before_pay_action', function ( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$reason = gph_cp_should_block(
		$order->get_billing_email(),
		$order->get_billing_first_name(),
		$order->get_billing_last_name(),
		$order->get_billing_postcode()
	);
	if ( $reason ) {
		gph_cp_log( 'Pay again #' . $order->get_id(), $reason, $order->get_billing_email() );
		wc_add_notice( gph_cp_message(), 'error' );
		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}
} );

// Count every failed payment made by a customer (not manual status changes).
add_action( 'woocommerce_order_status_failed', function ( $order_id, $order = null ) {
	if ( ! gph_cp_is_frontend_request() ) {
		return;
	}
	$s = gph_cp_settings();
	if ( ! $s['enabled'] ) {
		return;
	}
	$order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$ip     = gph_cp_ip() ? gph_cp_ip() : $order->get_meta( '_gph_cp_ip' );
	$device = gph_cp_device() ? gph_cp_device() : $order->get_meta( '_gph_cp_device' );

	$ids = gph_cp_identifiers(
		$ip,
		$order->get_billing_email(),
		$order->get_billing_first_name(),
		$order->get_billing_last_name(),
		$order->get_billing_postcode(),
		$device
	);

	foreach ( $ids as $type => $value ) {
		gph_cp_count_bump( gph_cp_identifier_counter( $type, $value ), $s['window_hours'] * HOUR_IN_SECONDS );
	}

	// Site-wide spike → strict mode.
	$total = gph_cp_count_bump( 'global', $s['spike_minutes'] * MINUTE_IN_SECONDS );
	if ( $total >= $s['spike_threshold'] && ! gph_cp_strict_until() ) {
		update_option( GPH_CP_STRICT, time() + $s['strict_hours'] * HOUR_IN_SECONDS, false );
		error_log( sprintf( '[GPH protection] strict mode ON for %d hour(s) after %d failed payments', $s['strict_hours'], $total ) );
	}
}, 10, 2 );

/* =========================================================================
 * Admin page: WooCommerce → Checkout Protection
 * ====================================================================== */

add_action( 'admin_menu', function () {
	add_submenu_page(
		'woocommerce',
		__( 'Checkout Protection', 'gph-core' ),
		__( 'Checkout Protection', 'gph-core' ),
		'manage_woocommerce',
		'gph-checkout-protection',
		'gph_cp_render_page'
	);
}, 99 );

function gph_cp_admin_redirect( $notice ) {
	wp_safe_redirect( add_query_arg(
		array( 'page' => 'gph-checkout-protection', 'gph_notice' => $notice ),
		admin_url( 'admin.php' )
	) );
	exit;
}

add_action( 'admin_post_gph_cp_save', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'gph-core' ) );
	}
	check_admin_referer( 'gph_cp_save' );

	$form = isset( $_POST['gph_cp'] ) && is_array( $_POST['gph_cp'] ) ? wp_unslash( $_POST['gph_cp'] ) : array();
	update_option( GPH_CP_SETTINGS, gph_cp_sanitize_settings( $form ), false );

	$emails = isset( $_POST['gph_blocked_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['gph_blocked_emails'] ) ) : '';
	update_option( GPH_CP_BLOCKLIST, gph_cp_parse_emails( $emails ), false );

	gph_cp_admin_redirect( 'saved' );
} );

add_action( 'admin_post_gph_cp_reset', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'gph-core' ) );
	}
	check_admin_referer( 'gph_cp_reset' );
	gph_cp_reset_counters();
	gph_cp_admin_redirect( 'reset' );
} );

function gph_cp_render_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$s      = gph_cp_settings();
	$labels = gph_cp_identifier_labels();
	$strict = gph_cp_strict_until();
	$log    = get_option( GPH_CP_LOG, array() );
	$notice = isset( $_GET['gph_notice'] ) ? sanitize_key( $_GET['gph_notice'] ) : '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Checkout Protection', 'gph-core' ); ?></h1>

		<?php if ( 'saved' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'gph-core' ); ?></p></div>
		<?php elseif ( 'reset' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All counters cleared. Everyone can check out again.', 'gph-core' ); ?></p></div>
		<?php endif; ?>

		<!-- Status -->
		<div class="card" style="max-width:none">
			<h2><?php esc_html_e( 'Status', 'gph-core' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Failed-payment limit:', 'gph-core' ); ?></strong>
				<?php echo $s['enabled'] ? '<span style="color:#008a20">' . esc_html__( 'On', 'gph-core' ) . '</span>' : '<span style="color:#b32d2e">' . esc_html__( 'Off', 'gph-core' ) . '</span>'; ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Strict mode:', 'gph-core' ); ?></strong>
				<?php
				echo $strict
					? esc_html( sprintf( __( 'Active until %s', 'gph-core' ), wp_date( 'M j, g:i a', $strict ) ) )
					: esc_html__( 'Not active', 'gph-core' );
				?>
			</p>
			<p><strong><?php esc_html_e( 'Blocked emails:', 'gph-core' ); ?></strong> <?php echo esc_html( count( gph_cp_blocklist() ) ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gph_cp_reset">
				<?php wp_nonce_field( 'gph_cp_reset' ); ?>
				<?php submit_button( __( 'Reset all counters', 'gph-core' ), 'secondary', 'submit', false ); ?>
				<span class="description"><?php esc_html_e( 'Use this if a real customer contacts you because they were paused. Clears every failed-payment count and ends strict mode. Blocked emails stay blocked.', 'gph-core' ); ?></span>
			</form>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gph_cp_save">
			<?php wp_nonce_field( 'gph_cp_save' ); ?>

			<!-- Failed-payment limit -->
			<h2><?php esc_html_e( 'Failed-payment limit', 'gph-core' ); ?></h2>
			<p class="description" style="max-width:800px">
				<?php esc_html_e( 'Stops people testing stolen cards. When the same customer details fail to pay too many times, their next attempt is paused before it reaches PayTrace, and they see a message asking them to contact you. Real customers who make a mistake can still try again within the limit.', 'gph-core' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Turn on', 'gph-core' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="gph_cp[enabled]" value="1" <?php checked( $s['enabled'] ); ?>>
							<?php esc_html_e( 'Pause checkout after repeated failed payments', 'gph-core' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gph_max_fails"><?php esc_html_e( 'Failed payments allowed', 'gph-core' ); ?></label></th>
					<td>
						<input id="gph_max_fails" type="number" min="1" max="10" name="gph_cp[max_fails]" value="<?php echo esc_attr( $s['max_fails'] ); ?>" class="small-text">
						<?php esc_html_e( 'within', 'gph-core' ); ?>
						<input type="number" min="1" max="72" name="gph_cp[window_hours]" value="<?php echo esc_attr( $s['window_hours'] ); ?>" class="small-text">
						<?php esc_html_e( 'hours', 'gph-core' ); ?>
						<p class="description"><?php esc_html_e( 'Example: 2 within 24 hours means the 3rd attempt is paused. Allowed: 1–10 failures, 1–72 hours.', 'gph-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Count failures by', 'gph-core' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( $labels as $key => $label ) : ?>
								<label style="display:block;margin-bottom:4px">
									<input type="checkbox" name="gph_cp[identifiers][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $s['identifiers'], true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'If any one of these reaches the limit, the attempt is paused. More boxes checked = harder to get around. Billing zip code catches attackers who change name, email and connection but reuse the same fake address.', 'gph-core' ); ?></p>
						</fieldset>
					</td>
				</tr>
			</table>

			<!-- Strict mode -->
			<h2><?php esc_html_e( 'Strict mode during a spike', 'gph-core' ); ?></h2>
			<p class="description" style="max-width:800px">
				<?php esc_html_e( 'If the whole site suddenly gets many failed payments, the limit tightens for a few hours. This catches attackers who switch connections or details to get around the normal limit.', 'gph-core' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Turn on strict mode after', 'gph-core' ); ?></th>
					<td>
						<input type="number" min="2" max="50" name="gph_cp[spike_threshold]" value="<?php echo esc_attr( $s['spike_threshold'] ); ?>" class="small-text">
						<?php esc_html_e( 'failed payments site-wide within', 'gph-core' ); ?>
						<input type="number" min="10" max="1440" name="gph_cp[spike_minutes]" value="<?php echo esc_attr( $s['spike_minutes'] ); ?>" class="small-text">
						<?php esc_html_e( 'minutes', 'gph-core' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Keep strict mode on for', 'gph-core' ); ?></th>
					<td>
						<input type="number" min="1" max="24" name="gph_cp[strict_hours]" value="<?php echo esc_attr( $s['strict_hours'] ); ?>" class="small-text">
						<?php esc_html_e( 'hours', 'gph-core' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Failed payments allowed in strict mode', 'gph-core' ); ?></th>
					<td>
						<input type="number" min="1" max="5" name="gph_cp[strict_max]" value="<?php echo esc_attr( $s['strict_max'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Example: 1 means anyone whose payment fails once is paused until strict mode ends. Can\'t be higher than the normal limit.', 'gph-core' ); ?></p>
					</td>
				</tr>
			</table>

			<!-- Blocked emails -->
			<h2><?php esc_html_e( 'Blocked emails', 'gph-core' ); ?></h2>
			<p class="description" style="max-width:800px">
				<?php esc_html_e( 'These emails can never place an order or retry a payment. Paste emails separated by commas or one per line. To unblock, delete the email and save. You can also block an email from any order: Order actions → "Block this customer\'s email".', 'gph-core' ); ?>
			</p>
			<textarea name="gph_blocked_emails" rows="8" class="large-text code"><?php echo esc_textarea( implode( "\n", gph_cp_blocklist() ) ); ?></textarea>

			<?php submit_button( __( 'Save settings', 'gph-core' ) ); ?>
		</form>

		<!-- Log -->
		<h2><?php esc_html_e( 'Recent blocks (last 50)', 'gph-core' ); ?></h2>
		<?php if ( empty( $log ) ) : ?>
			<p><?php esc_html_e( 'Nothing blocked yet.', 'gph-core' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'gph-core' ); ?></th>
						<th><?php esc_html_e( 'Where', 'gph-core' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'gph-core' ); ?></th>
						<th><?php esc_html_e( 'Email', 'gph-core' ); ?></th>
						<th><?php esc_html_e( 'IP', 'gph-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'M j, g:i a', $row['time'] ) ); ?></td>
							<td><?php echo esc_html( $row['where'] ); ?></td>
							<td><?php echo esc_html( $row['reason'] ); ?></td>
							<td><?php echo esc_html( $row['email'] ); ?></td>
							<td><?php echo esc_html( $row['ip'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/* =========================================================================
 * Order screen action: "Block this customer's email"
 * ====================================================================== */

add_filter( 'woocommerce_order_actions', function ( $actions ) {
	if ( current_user_can( 'manage_woocommerce' ) ) {
		$actions['gph_block_email'] = __( 'Block this customer\'s email', 'gph-core' );
	}
	return $actions;
} );

add_action( 'woocommerce_order_action_gph_block_email', function ( $order ) {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! $order instanceof WC_Order ) {
		return;
	}
	$email = strtolower( trim( $order->get_billing_email() ) );
	if ( $email && is_email( $email ) ) {
		$list   = gph_cp_blocklist();
		$list[] = $email;
		update_option( GPH_CP_BLOCKLIST, array_values( array_unique( $list ) ), false );

		$order->add_order_note( sprintf(
			/* translators: 1: email, 2: staff name */
			__( 'Email %1$s added to the checkout blocklist by %2$s.', 'gph-core' ),
			$email,
			wp_get_current_user()->display_name
		) );
	}
} );
