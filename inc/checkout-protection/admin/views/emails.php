<?php
/**
 * Checkout Protection — Blocked emails tab.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

function gph_cp_view_emails() {
	$list = gph_cp_blocklist();
	?>
	<div class="gph-cp-card">
		<h2>
			<?php esc_html_e( 'Blocked emails', 'gph-core' ); ?>
			<span class="gph-cp-count"><?php echo esc_html( number_format_i18n( count( $list ) ) ); ?></span>
		</h2>
		<p class="gph-cp-intro"><?php esc_html_e( 'These emails can never place an order or retry a payment, even when the failed-payment limit is off. Capitals, dots in Gmail addresses and "+anything" are ignored, so simple variations are caught too.', 'gph-core' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gph_cp_save_emails">
			<?php wp_nonce_field( 'gph_cp_save_emails' ); ?>
			<textarea name="gph_blocked_emails" rows="12" class="large-text code" placeholder="someone@example.com"><?php echo esc_textarea( implode( "\n", $list ) ); ?></textarea>
			<p class="description"><?php esc_html_e( 'One per line, or separated by commas. To unblock, delete the line and save. Invalid entries and duplicates are removed automatically.', 'gph-core' ); ?></p>
			<div class="gph-cp-actions">
				<?php submit_button( __( 'Save blocked emails', 'gph-core' ), 'primary', 'submit', false ); ?>
			</div>
		</form>
	</div>

	<div class="gph-cp-card gph-cp-tip">
		<h2><?php esc_html_e( 'Faster ways to block', 'gph-core' ); ?></h2>
		<ul>
			<li><?php esc_html_e( 'From an order: Order actions → "Block this customer\'s email" → Update.', 'gph-core' ); ?></li>
			<li><?php esc_html_e( 'From Blocked attempts: hover a customer → "Block email".', 'gph-core' ); ?></li>
		</ul>
		<p class="gph-cp-muted"><?php esc_html_e( 'Blocking an email only helps when an attacker reuses it. Most change email every attempt — the failed-payment limit handles those.', 'gph-core' ); ?></p>
	</div>
	<?php
}
