<?php
/**
 * Checkout reCAPTCHA v3 verification.
 *
 * Verifies a reCAPTCHA v3 token on every checkout submission and rejects
 * submissions scoring below the configured threshold. Applies to the
 * classic WooCommerce checkout ('form.checkout' / wc-ajax=checkout).
 *
 * If this store moves to WooCommerce Blocks checkout, this must be
 * reimplemented against the Store API's checkout validation hooks —
 * 'woocommerce_checkout_process' does not fire in that flow.
 *
 * @package GPH_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Configuration.
 *
 * GPH_RECAPTCHA_SITE_KEY and GPH_RECAPTCHA_SECRET_KEY must be defined in
 * wp-config.php, not in plugin source, so the secret key is never
 * committed to version control.
 */
if ( ! defined( 'GPH_RECAPTCHA_SITE_KEY' ) ) {
	define( 'GPH_RECAPTCHA_SITE_KEY', '' );
}

if ( ! defined( 'GPH_RECAPTCHA_SECRET_KEY' ) ) {
	define( 'GPH_RECAPTCHA_SECRET_KEY', '' );
}

/**
 * Minimum acceptable reCAPTCHA v3 score (0.0–1.0). Submissions scoring
 * below this are rejected when enforcement is enabled.
 */
if ( ! defined( 'GPH_RECAPTCHA_THRESHOLD' ) ) {
	define( 'GPH_RECAPTCHA_THRESHOLD', 0.5 );
}

/**
 * Enforcement toggle. When false, verification still runs and is logged,
 * but failing submissions are not blocked. Can be overridden in
 * wp-config.php to disable enforcement without a code deploy.
 */
if ( ! defined( 'GPH_RECAPTCHA_ENFORCE' ) ) {
	define( 'GPH_RECAPTCHA_ENFORCE', true );
}

/**
 * Registers the reCAPTCHA v3 script on the checkout page, verifies the
 * resulting token server-side on checkout submission, and blocks or logs
 * the result depending on GPH_RECAPTCHA_ENFORCE.
 */
class GPH_Recaptcha_Checkout {

	/**
	 * Register hooks, or show an admin notice if credentials are missing.
	 */
	public function __construct() {
		if ( '' === GPH_RECAPTCHA_SITE_KEY || '' === GPH_RECAPTCHA_SECRET_KEY ) {
			add_action( 'admin_notices', array( $this, 'missing_keys_notice' ) );
			return;
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_recaptcha' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'verify_checkout_token' ) );
	}

	/**
	 * Admin notice shown when site/secret keys are not configured.
	 * Verification does not run at all in this state — it does not fail closed.
	 */
	public function missing_keys_notice() {
		echo '<div class="notice notice-error"><p><strong>GPH reCAPTCHA:</strong> ' .
			esc_html__( 'Site key or secret key is not configured. Checkout is not protected. Define GPH_RECAPTCHA_SITE_KEY and GPH_RECAPTCHA_SECRET_KEY in wp-config.php.', 'gph-core' ) .
			'</p></div>';
	}

	/**
	 * Enqueue the reCAPTCHA v3 script and inline token handler on the checkout page.
	 */
	public function enqueue_recaptcha() {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'gph-recaptcha-v3',
			'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( GPH_RECAPTCHA_SITE_KEY ),
			array(),
			null,
			true
		);

		wp_add_inline_script( 'gph-recaptcha-v3', $this->get_inline_script() );
	}

	/**
	 * Build the inline script that requests a token and writes it into a
	 * hidden field on the checkout form. Tokens expire after roughly two
	 * minutes, so the token is refreshed on checkout update and again
	 * immediately before submission.
	 *
	 * @return string
	 */
	private function get_inline_script() {
		$site_key = esc_js( GPH_RECAPTCHA_SITE_KEY );

		return "
			function gphRefreshRecaptchaToken() {
				if ( typeof grecaptcha === 'undefined' ) {
					return;
				}
				grecaptcha.ready( function() {
					grecaptcha.execute( '{$site_key}', { action: 'checkout' } ).then( function( token ) {
						var field = document.getElementById( 'gph_recaptcha_token' );
						if ( ! field ) {
							field = document.createElement( 'input' );
							field.type = 'hidden';
							field.name = 'gph_recaptcha_token';
							field.id = 'gph_recaptcha_token';
							var form = document.querySelector( 'form.checkout' );
							if ( form ) {
								form.appendChild( field );
							}
						}
						field.value = token;
					} );
				} );
			}
			document.addEventListener( 'DOMContentLoaded', gphRefreshRecaptchaToken );
			jQuery( document.body ).on( 'click', '#place_order', gphRefreshRecaptchaToken );
			jQuery( document.body ).on( 'updated_checkout', gphRefreshRecaptchaToken );
		";
	}

	/**
	 * Verify the submitted token during WooCommerce checkout processing.
	 * Adds a checkout error when enforcement is enabled and verification fails.
	 */
	public function verify_checkout_token() {
		$token  = isset( $_POST['gph_recaptcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['gph_recaptcha_token'] ) ) : '';
		$result = $this->verify_token( $token );

		$this->log_result( $result );

		if ( GPH_RECAPTCHA_ENFORCE && ! $result['success'] ) {
			wc_add_notice( __( 'We were unable to verify your checkout request. Please refresh the page and try again.', 'gph-core' ), 'error' );
		}
	}

	/**
	 * Verify a reCAPTCHA v3 token against Google's siteverify endpoint.
	 *
	 * Fails open on a Google API error — a third-party outage must not
	 * block checkout.
	 *
	 * @param string $token Token submitted by the client.
	 * @return array {
	 *     @type bool       $success Whether the token passed verification.
	 *     @type float|null $score   reCAPTCHA score, or null if unavailable.
	 *     @type string     $reason  Machine-readable result reason.
	 *     @type array      $errors  Error codes from the API, if any.
	 * }
	 */
	private function verify_token( $token ) {
		if ( empty( $token ) ) {
			return array(
				'success' => false,
				'score'   => 0,
				'reason'  => 'missing_token',
			);
		}

		$response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
			'timeout' => 10,
			'body'    => array(
				'secret'   => GPH_RECAPTCHA_SECRET_KEY,
				'response' => $token,
				'remoteip' => $this->get_client_ip(),
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => true,
				'score'   => null,
				'reason'  => 'verification_unavailable',
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['success'] ) ) {
			return array(
				'success' => false,
				'score'   => 0,
				'reason'  => 'rejected_by_google',
				'errors'  => isset( $body['error-codes'] ) ? $body['error-codes'] : array(),
			);
		}

		$score = isset( $body['score'] ) ? (float) $body['score'] : 0;

		return array(
			'success' => $score >= GPH_RECAPTCHA_THRESHOLD,
			'score'   => $score,
			'reason'  => $score >= GPH_RECAPTCHA_THRESHOLD ? 'ok' : 'below_threshold',
		);
	}

	/**
	 * Resolve the client IP address, preferring the Cloudflare header
	 * where present.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
				return trim( $parts[0] );
			}
		}

		return '';
	}

	/**
	 * Write a verification result to the PHP error log.
	 *
	 * Logged unconditionally, independent of WP_DEBUG — this is a
	 * security-relevant record and must not depend on debug mode being on.
	 *
	 * @param array $result Result array from verify_token().
	 */
	private function log_result( $result ) {
		error_log( sprintf(
			'[GPH reCAPTCHA] enforce=%s success=%s score=%s reason=%s ip=%s',
			GPH_RECAPTCHA_ENFORCE ? 'true' : 'false',
			$result['success'] ? 'true' : 'false',
			isset( $result['score'] ) && null !== $result['score'] ? $result['score'] : 'n/a',
			$result['reason'],
			$this->get_client_ip()
		) );
	}
}

new GPH_Recaptcha_Checkout();