<?php
/**
 * Checkout Protection — Settings tab.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small number input.
 */
function gph_cp_number_field( $name, $value, $min, $max ) {
	printf(
		'<input type="number" class="small-text" name="gph_cp[%1$s]" id="gph_cp_%1$s" value="%2$s" min="%3$d" max="%4$d" required>',
		esc_attr( $name ),
		esc_attr( $value ),
		(int) $min,
		(int) $max
	);
}

function gph_cp_view_settings() {
	$s        = gph_cp_settings();
	$labels   = gph_cp_identifier_labels();
	$detected = gph_cp_detect_contact_page();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="gph_cp_save_settings">
		<?php wp_nonce_field( 'gph_cp_save_settings' ); ?>

		<!-- Failed-payment limit -->
		<div class="gph-cp-card">
			<h2><?php esc_html_e( 'Failed-payment limit', 'gph-core' ); ?></h2>
			<p class="gph-cp-intro"><?php esc_html_e( 'When the same customer details fail to pay too many times, their next attempt is paused before it reaches PayTrace. They see a message asking them to contact you. Real customers who make a mistake can still try again within the limit.', 'gph-core' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'gph-core' ); ?></th>
					<td>
						<label class="gph-cp-toggle">
							<input type="checkbox" name="gph_cp[enabled]" value="1" <?php checked( $s['enabled'] ); ?>>
							<?php esc_html_e( 'Pause checkout after repeated failed payments', 'gph-core' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gph_cp_max_fails"><?php esc_html_e( 'Failed payments allowed', 'gph-core' ); ?></label></th>
					<td>
						<?php gph_cp_number_field( 'max_fails', $s['max_fails'], 1, 10 ); ?>
						<?php esc_html_e( 'within', 'gph-core' ); ?>
						<?php gph_cp_number_field( 'window_hours', $s['window_hours'], 1, 72 ); ?>
						<?php esc_html_e( 'hours', 'gph-core' ); ?>
						<p class="description"><?php esc_html_e( 'Example: 2 within 24 hours means the 3rd attempt is paused.', 'gph-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Count failures by', 'gph-core' ); ?></th>
					<td>
						<fieldset class="gph-cp-checklist">
							<?php foreach ( $labels as $key => $label ) : ?>
								<label>
									<input type="checkbox" name="gph_cp[identifiers][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $s['identifiers'], true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'If any one of these reaches the limit, the attempt is paused. Keep all checked: attackers change names, emails and connections, but often reuse the same fake zip code.', 'gph-core' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Strict mode -->
		<div class="gph-cp-card">
			<h2><?php esc_html_e( 'Strict mode', 'gph-core' ); ?></h2>
			<p class="gph-cp-intro"><?php esc_html_e( 'If the whole site suddenly gets many failed payments, the limit tightens for a few hours. This catches attackers who switch details to get around the normal limit.', 'gph-core' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gph_cp_spike_threshold"><?php esc_html_e( 'Switch on after', 'gph-core' ); ?></label></th>
					<td>
						<?php gph_cp_number_field( 'spike_threshold', $s['spike_threshold'], 2, 50 ); ?>
						<?php esc_html_e( 'failed payments site-wide within', 'gph-core' ); ?>
						<?php gph_cp_number_field( 'spike_minutes', $s['spike_minutes'], 10, 1440 ); ?>
						<?php esc_html_e( 'minutes', 'gph-core' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gph_cp_strict_hours"><?php esc_html_e( 'Stay on for', 'gph-core' ); ?></label></th>
					<td><?php gph_cp_number_field( 'strict_hours', $s['strict_hours'], 1, 24 ); ?> <?php esc_html_e( 'hours', 'gph-core' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="gph_cp_strict_max"><?php esc_html_e( 'Failed payments allowed', 'gph-core' ); ?></label></th>
					<td>
						<?php gph_cp_number_field( 'strict_max', $s['strict_max'], 1, 5 ); ?>
						<p class="description"><?php esc_html_e( 'Example: 1 means anyone whose payment fails once is paused until strict mode ends. Can\'t be higher than the normal limit.', 'gph-core' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Alert -->
		<div class="gph-cp-card">
			<h2><?php esc_html_e( 'Alert email', 'gph-core' ); ?></h2>
			<p class="gph-cp-intro"><?php esc_html_e( 'One email when strict mode switches on, so someone knows an attack is happening. At most one email per strict-mode period.', 'gph-core' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'gph-core' ); ?></th>
					<td>
						<label class="gph-cp-toggle">
							<input type="checkbox" name="gph_cp[alert_enabled]" value="1" <?php checked( $s['alert_enabled'] ); ?>>
							<?php esc_html_e( 'Send an alert when strict mode switches on', 'gph-core' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gph_cp_alert_email"><?php esc_html_e( 'Send to', 'gph-core' ); ?></label></th>
					<td>
						<input type="email" class="regular-text" name="gph_cp[alert_email]" id="gph_cp_alert_email" value="<?php echo esc_attr( $s['alert_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Leave empty to use the site admin email shown.', 'gph-core' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Message -->
		<div class="gph-cp-card">
			<h2><?php esc_html_e( 'Message customers see', 'gph-core' ); ?></h2>
			<p class="gph-cp-intro"><?php esc_html_e( 'Shown at the top of checkout when an attempt is stopped. The same message is used for every reason, so attackers can\'t tell which rule stopped them. A reference code is added automatically.', 'gph-core' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gph_cp_message"><?php esc_html_e( 'Message', 'gph-core' ); ?></label></th>
					<td>
						<textarea name="gph_cp[message]" id="gph_cp_message" rows="3" class="large-text"><?php echo esc_textarea( $s['message'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Plain text. {contact} is replaced by the contact link. Leave empty to restore the default.', 'gph-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gph_cp_link_text"><?php esc_html_e( 'Link text', 'gph-core' ); ?></label></th>
					<td><input type="text" class="regular-text" name="gph_cp[link_text]" id="gph_cp_link_text" value="<?php echo esc_attr( $s['link_text'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gph_cp_contact_page"><?php esc_html_e( 'Link to', 'gph-core' ); ?></label></th>
					<td>
						<select name="gph_cp[contact_page]" id="gph_cp_contact_page">
							<option value="0" <?php selected( (int) $s['contact_page'], 0 ); ?>>
								<?php
								echo esc_html( $detected
									/* translators: %s: page title */
									? sprintf( __( 'Automatic: %s', 'gph-core' ), get_the_title( $detected ) )
									: __( 'Automatic (no Contact page found)', 'gph-core' ) );
								?>
							</option>
							<option value="-1" <?php selected( (int) $s['contact_page'], -1 ); ?>><?php esc_html_e( '— No link —', 'gph-core' ); ?></option>
							<?php foreach ( get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title' ) ) as $p ) : ?>
								<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( (int) $s['contact_page'], (int) $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Opens in a new tab so the customer keeps their checkout. If the page is removed, the message shows without a link.', 'gph-core' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Preview', 'gph-core' ); ?></th>
					<td>
						<div class="gph-cp-preview"><?php echo wp_kses_post( gph_cp_message( 'GPH-EXAMPLE' ) ); ?></div>
						<p class="description"><?php esc_html_e( 'Updates after you save.', 'gph-core' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Log -->
		<div class="gph-cp-card">
			<h2><?php esc_html_e( 'Blocked attempts log', 'gph-core' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gph_cp_log_days"><?php esc_html_e( 'Keep entries for', 'gph-core' ); ?></label></th>
					<td>
						<?php gph_cp_number_field( 'log_days', $s['log_days'], 7, 365 ); ?> <?php esc_html_e( 'days', 'gph-core' ); ?>
						<p class="description"><?php esc_html_e( 'Older entries are deleted automatically once a day. The log holds names, emails, phones and addresses, so keep it only as long as needed. Card details are never stored.', 'gph-core' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="gph-cp-actions">
			<?php submit_button( __( 'Save settings', 'gph-core' ), 'primary', 'submit', false ); ?>
		</div>
	</form>

	<!-- Delete everything -->
	<div class="gph-cp-card gph-cp-danger">
		<h2><?php esc_html_e( 'Delete all data', 'gph-core' ); ?></h2>
		<p><?php esc_html_e( 'Removes the blocked attempts log, blocked emails, counters and all settings. Use this before removing the Checkout Protection feature from the plugin, so nothing is left in the database. This can\'t be undone.', 'gph-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gph_cp_delete_all">
			<?php wp_nonce_field( 'gph_cp_delete_all' ); ?>
			<label class="gph-cp-confirm">
				<input type="checkbox" name="gph_cp_confirm_delete" value="1" required>
				<?php esc_html_e( 'I understand this permanently deletes all Checkout Protection data.', 'gph-core' ); ?>
			</label>
			<?php submit_button( __( 'Delete all Checkout Protection data', 'gph-core' ), 'delete', 'submit', false ); ?>
		</form>
	</div>
	<?php
}
