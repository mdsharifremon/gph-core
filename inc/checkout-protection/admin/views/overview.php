<?php
/**
 * Checkout Protection — Overview tab.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

function gph_cp_view_overview() {
	$s         = gph_cp_settings();
	$strict    = gph_cp_strict_until();
	$recaptcha = gph_cp_recaptcha_status();
	$day       = gph_cp_log_count_since( 24 );
	$week      = gph_cp_log_count_since( 24 * 7 );
	$recent    = gph_cp_log_recent( 10 );
	?>
	<div class="gph-cp-stats">

		<div class="gph-cp-stat">
			<span class="gph-cp-stat-label"><?php esc_html_e( 'Failed-payment limit', 'gph-core' ); ?></span>
			<?php $s['enabled'] ? gph_cp_pill( 'good', __( 'On', 'gph-core' ) ) : gph_cp_pill( 'bad', __( 'Off', 'gph-core' ) ); ?>
			<span class="gph-cp-stat-detail">
				<?php
				echo esc_html( $s['enabled']
					/* translators: 1: failures, 2: hours */
					? sprintf( __( '%1$d failures within %2$d hours, then paused.', 'gph-core' ), $s['max_fails'], $s['window_hours'] )
					: __( 'Not checking failed payments.', 'gph-core' ) );
				?>
				<a href="<?php echo esc_url( gph_cp_admin_url( 'settings' ) ); ?>"><?php esc_html_e( 'Change', 'gph-core' ); ?></a>
			</span>
		</div>

		<div class="gph-cp-stat">
			<span class="gph-cp-stat-label"><?php esc_html_e( 'Strict mode', 'gph-core' ); ?></span>
			<?php $strict ? gph_cp_pill( 'warn', __( 'Active', 'gph-core' ) ) : gph_cp_pill( 'neutral', __( 'Not active', 'gph-core' ) ); ?>
			<span class="gph-cp-stat-detail">
				<?php
				echo esc_html( $strict
					/* translators: %s: time */
					? sprintf( __( 'Until %s — an unusual number of payments failed.', 'gph-core' ), wp_date( get_option( 'time_format' ) . ', M j', $strict ) )
					: __( 'Switches on automatically during a spike.', 'gph-core' ) );
				?>
			</span>
		</div>

		<div class="gph-cp-stat">
			<span class="gph-cp-stat-label"><?php esc_html_e( 'reCAPTCHA', 'gph-core' ); ?></span>
			<?php gph_cp_pill( $recaptcha[0], $recaptcha[1] ); ?>
			<span class="gph-cp-stat-detail"><?php echo esc_html( $recaptcha[2] ); ?></span>
		</div>

		<div class="gph-cp-stat">
			<span class="gph-cp-stat-label"><?php esc_html_e( 'Blocked attempts', 'gph-core' ); ?></span>
			<span class="gph-cp-stat-number"><?php echo esc_html( number_format_i18n( $day ) ); ?></span>
			<span class="gph-cp-stat-detail">
				<?php
				/* translators: %s: count */
				echo esc_html( sprintf( __( 'last 24 hours · %s in 7 days', 'gph-core' ), number_format_i18n( $week ) ) );
				?>
			</span>
		</div>

		<div class="gph-cp-stat">
			<span class="gph-cp-stat-label"><?php esc_html_e( 'Blocked emails', 'gph-core' ); ?></span>
			<span class="gph-cp-stat-number"><?php echo esc_html( number_format_i18n( count( gph_cp_blocklist() ) ) ); ?></span>
			<span class="gph-cp-stat-detail"><a href="<?php echo esc_url( gph_cp_admin_url( 'emails' ) ); ?>"><?php esc_html_e( 'Manage list', 'gph-core' ); ?></a></span>
		</div>

	</div>

	<div class="gph-cp-card">
		<div class="gph-cp-card-head">
			<h2><?php esc_html_e( 'Recent blocked attempts', 'gph-core' ); ?></h2>
			<a class="button" href="<?php echo esc_url( gph_cp_admin_url( 'log' ) ); ?>"><?php esc_html_e( 'View all', 'gph-core' ); ?></a>
		</div>

		<?php if ( ! $recent ) : ?>
			<p class="gph-cp-empty"><?php esc_html_e( 'Nothing blocked yet.', 'gph-core' ); ?></p>
		<?php else : ?>
			<div class="gph-cp-table-wrap"><table class="widefat striped gph-cp-mini-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Reference', 'gph-core' ); ?></th>
						<th><?php esc_html_e( 'When', 'gph-core' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'gph-core' ); ?></th>
						<th><?php esc_html_e( 'Why', 'gph-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent as $r ) : ?>
						<tr>
							<td><code><?php echo esc_html( $r->ref ); ?></code></td>
							<td><?php echo esc_html( get_date_from_gmt( $r->created_at, 'M j, g:i a' ) ); ?></td>
							<td>
								<?php echo esc_html( trim( $r->first_name . ' ' . $r->last_name ) ); ?>
								<span class="gph-cp-muted"><?php echo esc_html( $r->email ); ?></span>
							</td>
							<td><?php echo esc_html( $r->reason ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>
	</div>

	<div class="gph-cp-card">
		<h2><?php esc_html_e( 'A real customer says they can\'t check out?', 'gph-core' ); ?></h2>
		<p><?php esc_html_e( 'Find their reference code under Blocked attempts to see why. Then reset the counters so they can try again. This ends strict mode too. Blocked emails stay blocked.', 'gph-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gph_cp_reset">
			<?php wp_nonce_field( 'gph_cp_reset' ); ?>
			<?php submit_button( __( 'Reset all counters', 'gph-core' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}
