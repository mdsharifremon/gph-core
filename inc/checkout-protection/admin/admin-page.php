<?php
/**
 * Checkout Protection — admin screen: WooCommerce → Checkout Protection.
 *
 * One menu item with four tabs (views/*.php). All form submissions go through
 * admin-post.php handlers below, each checking capability + nonce, then
 * redirecting back with a notice code.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

define( 'GPH_CP_PAGE', 'gph-checkout-protection' );

/**
 * Tabs: slug => label.
 *
 * @return array
 */
function gph_cp_tabs() {
	return array(
		'overview' => __( 'Overview', 'gph-core' ),
		'settings' => __( 'Settings', 'gph-core' ),
		'emails'   => __( 'Blocked emails', 'gph-core' ),
		'log'      => __( 'Blocked attempts', 'gph-core' ),
	);
}

function gph_cp_current_tab() {
	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return array_key_exists( $tab, gph_cp_tabs() ) ? $tab : 'overview';
}

function gph_cp_admin_url( $tab = 'overview', $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => GPH_CP_PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
}

/* -------------------------------------------------------------------------
 * Menu + assets (assets load on this screen only)
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	$hook = add_submenu_page(
		'woocommerce',
		__( 'Checkout Protection', 'gph-core' ),
		__( 'Checkout Protection', 'gph-core' ),
		'manage_woocommerce',
		GPH_CP_PAGE,
		'gph_cp_render_admin_page'
	);

	add_action( 'load-' . $hook, function () {
		// Migrate v1 data / upgrade the table if needed. Never creates it
		// from nothing, so "Delete all data" stays deleted.
		$needs_upgrade = gph_cp_log_ready() && GPH_CP_DB_VERSION !== get_option( GPH_CP_DB_VERSION_OPT );
		if ( $needs_upgrade || false !== get_option( GPH_CP_LEGACY_LOG ) ) {
			gph_cp_maybe_install();
		}
		if ( gph_cp_log_ready() ) {
			gph_cp_schedule_cleanup(); // Re-schedule after a plugin deactivate/activate.
		}

		add_action( 'admin_enqueue_scripts', function () {
			wp_enqueue_style( 'gph-cp-admin', GPH_CP_URL . 'admin/admin.css', array(), GPH_CP_VERSION );
		} );
	} );
}, 99 );

/* -------------------------------------------------------------------------
 * Page shell
 * ---------------------------------------------------------------------- */

function gph_cp_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$tab = gph_cp_current_tab();
	?>
	<div class="wrap gph-cp">
		<h1 class="gph-cp-title"><?php esc_html_e( 'Checkout Protection', 'gph-core' ); ?></h1>
		<p class="gph-cp-subtitle"><?php esc_html_e( 'Stops people testing stolen cards at checkout, without getting in the way of real customers.', 'gph-core' ); ?></p>

		<?php gph_cp_render_notice(); ?>

		<nav class="nav-tab-wrapper gph-cp-tabs">
			<?php foreach ( gph_cp_tabs() as $slug => $label ) : ?>
				<a href="<?php echo esc_url( gph_cp_admin_url( $slug ) ); ?>" class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<div class="gph-cp-body">
			<?php
			require_once GPH_CP_PATH . 'admin/views/' . $tab . '.php';
			call_user_func( 'gph_cp_view_' . $tab );
			?>
		</div>
	</div>
	<?php
}

/**
 * Success/error notice after a form action (?gph_notice=code).
 */
function gph_cp_render_notice() {
	$code     = isset( $_GET['gph_notice'] ) ? sanitize_key( $_GET['gph_notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$messages = array(
		'saved'     => array( 'success', __( 'Settings saved.', 'gph-core' ) ),
		'emails'    => array( 'success', __( 'Blocked emails saved.', 'gph-core' ) ),
		'reset'     => array( 'success', __( 'All counters cleared and strict mode ended. Everyone can check out again. Blocked emails stay blocked.', 'gph-core' ) ),
		'blocked'   => array( 'success', __( 'Email blocked.', 'gph-core' ) ),
		'unblocked' => array( 'success', __( 'Email unblocked.', 'gph-core' ) ),
		'deleted'   => array( 'success', __( 'All Checkout Protection data was deleted. Settings are back to defaults (limit off).', 'gph-core' ) ),
		'confirm'   => array( 'error', __( 'Nothing was deleted. Tick the confirmation box first.', 'gph-core' ) ),
	);

	if ( isset( $messages[ $code ] ) ) {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}
}

/* -------------------------------------------------------------------------
 * Small view helpers
 * ---------------------------------------------------------------------- */

/**
 * Status pill.
 *
 * @param string $state good|warn|bad|neutral.
 * @param string $label Text.
 */
function gph_cp_pill( $state, $label ) {
	printf( '<span class="gph-cp-pill is-%1$s">%2$s</span>', esc_attr( $state ), esc_html( $label ) );
}

/**
 * reCAPTCHA state from its wp-config constants (read-only).
 *
 * @return array { state, label, detail }
 */
function gph_cp_recaptcha_status() {
	$configured = defined( 'GPH_RECAPTCHA_SITE_KEY' ) && '' !== GPH_RECAPTCHA_SITE_KEY
		&& defined( 'GPH_RECAPTCHA_SECRET_KEY' ) && '' !== GPH_RECAPTCHA_SECRET_KEY;

	if ( ! $configured ) {
		return array( 'bad', __( 'Not configured', 'gph-core' ), __( 'Keys missing in wp-config.php — checkout is not checked for bots.', 'gph-core' ) );
	}
	if ( defined( 'GPH_RECAPTCHA_ENFORCE' ) && ! GPH_RECAPTCHA_ENFORCE ) {
		return array( 'warn', __( 'Log only', 'gph-core' ), __( 'Scores are logged but nothing is blocked.', 'gph-core' ) );
	}
	/* translators: %s: score threshold */
	return array( 'good', __( 'Enforcing', 'gph-core' ), sprintf( __( 'Blocks bot-like checkouts (score below %s).', 'gph-core' ), GPH_RECAPTCHA_THRESHOLD ) );
}

/* -------------------------------------------------------------------------
 * Form handlers (admin-post.php)
 * ---------------------------------------------------------------------- */

/**
 * Capability + nonce check shared by every handler.
 *
 * @param string $action Nonce action.
 */
function gph_cp_verify_request( $action ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'gph-core' ), 403 );
	}
	check_admin_referer( $action );
}

function gph_cp_redirect( $tab, $notice, $args = array() ) {
	wp_safe_redirect( gph_cp_admin_url( $tab, array_merge( array( 'gph_notice' => $notice ), $args ) ) );
	exit;
}

add_action( 'admin_post_gph_cp_save_settings', function () {
	gph_cp_verify_request( 'gph_cp_save_settings' );
	$form = isset( $_POST['gph_cp'] ) && is_array( $_POST['gph_cp'] ) ? wp_unslash( $_POST['gph_cp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in gph_cp_sanitize_settings().
	gph_cp_save_settings( gph_cp_sanitize_settings( $form ) );
	gph_cp_redirect( 'settings', 'saved' );
} );

add_action( 'admin_post_gph_cp_save_emails', function () {
	gph_cp_verify_request( 'gph_cp_save_emails' );
	$text = isset( $_POST['gph_blocked_emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['gph_blocked_emails'] ) ) : '';
	gph_cp_save_blocklist( gph_cp_parse_emails( $text ) );
	gph_cp_redirect( 'emails', 'emails' );
} );

add_action( 'admin_post_gph_cp_reset', function () {
	gph_cp_verify_request( 'gph_cp_reset' );
	gph_cp_reset_counters();
	gph_cp_redirect( 'overview', 'reset' );
} );

add_action( 'admin_post_gph_cp_toggle_block', function () {
	gph_cp_verify_request( 'gph_cp_toggle_block' );
	$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
	$do    = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';

	if ( is_email( $email ) ) {
		'block' === $do ? gph_cp_block_email( $email ) : gph_cp_unblock_email( $email );
	}

	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	gph_cp_redirect( 'log', 'block' === $do ? 'blocked' : 'unblocked', $search ? array( 's' => $search ) : array() );
} );

add_action( 'admin_post_gph_cp_delete_all', function () {
	gph_cp_verify_request( 'gph_cp_delete_all' );
	if ( empty( $_POST['gph_cp_confirm_delete'] ) ) {
		gph_cp_redirect( 'settings', 'confirm' );
	}
	gph_cp_delete_all_data();
	gph_cp_redirect( 'settings', 'deleted' );
} );

/**
 * CSV export of the log (respects the current search).
 */
add_action( 'admin_post_gph_cp_export', function () {
	gph_cp_verify_request( 'gph_cp_export' );
	global $wpdb;

	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$table  = gph_cp_table();
	$where  = gph_cp_log_where( $search );
	$rows   = ! gph_cp_log_ready() ? array() : $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared.

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=blocked-attempts-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Reference', 'Time (UTC)', 'Where', 'Reason', 'First name', 'Last name', 'Email', 'Phone', 'Address', 'Cart', 'Total', 'Order ID', 'IP', 'Browser' ) );

	foreach ( $rows as $r ) {
		$line = array( $r['ref'], $r['created_at'], $r['source'], $r['reason'], $r['first_name'], $r['last_name'], $r['email'], $r['phone'], $r['address'], $r['items'], $r['total'], $r['order_id'], $r['ip'], $r['user_agent'] );

		// Attacker-typed text must not run as a spreadsheet formula.
		$line = array_map( function ( $v ) {
			$v = (string) $v;
			return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
		}, $line );

		fputcsv( $out, $line );
	}

	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
} );
