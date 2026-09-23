<?php
/**
 * Checkout Protection — Blocked attempts tab.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

function gph_cp_view_log() {
	require_once GPH_CP_PATH . 'admin/class-log-table.php';

	$table = new GPH_CP_Log_Table();
	$table->prepare_items();

	$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$export = wp_nonce_url(
		add_query_arg( array( 'action' => 'gph_cp_export', 's' => $search ), admin_url( 'admin-post.php' ) ),
		'gph_cp_export'
	);
	$days   = (int) gph_cp_settings()['log_days'];
	?>
	<div class="gph-cp-card">
		<div class="gph-cp-card-head">
			<h2><?php esc_html_e( 'Blocked attempts', 'gph-core' ); ?></h2>
			<a class="button" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'gph-core' ); ?></a>
		</div>
		<p class="gph-cp-intro">
			<?php
			/* translators: %d: days */
			echo esc_html( sprintf( __( 'Every checkout that was stopped, with what the customer entered. Kept for %d days. Most entries are fraud attempts with fake details — only contact someone if the details look real. If a customer quotes a reference code, search for it here.', 'gph-core' ), $days ) );
			?>
		</p>

		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( GPH_CP_PAGE ); ?>">
			<input type="hidden" name="tab" value="log">
			<?php $table->search_box( __( 'Search', 'gph-core' ), 'gph-cp-search' ); ?>
			<?php $table->display(); ?>
		</form>
	</div>
	<?php
}
