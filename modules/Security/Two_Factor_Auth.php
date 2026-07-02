<?php
/**
 * Two-Factor Authentication (TOTP) module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Security;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Adds app-based two-factor authentication (TOTP, RFC 6238) — compatible with
 * Google Authenticator, Authy, 1Password, etc. Each user enables it on their own
 * profile. Replaces the 2FA feature of Wordfence / Solid Security / RSSSL Pro.
 */
final class Two_Factor_Auth extends Abstract_Module {

	const SECRET_KEY  = 'dicestack_2fa_secret';
	const ENABLED_KEY = 'dicestack_2fa_enabled';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'two_factor_auth';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Two-factor authentication', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'App-based 2FA (Google Authenticator, Authy) — each user turns it on from their profile.', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'security';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'shield-check';
	}

	/**
	 * {@inheritDoc}
	 */
	public function replaces() {
		return 'premium 2FA plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 30,
			'front_js_kb'   => 0,
			'front_css_kb'  => 0,
			'db_queries'    => 0,
			'external_http' => 0,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		add_action( 'login_form', array( $this, 'code_field' ) );
		add_filter( 'authenticate', array( $this, 'verify' ), 40, 1 );
		add_action( 'show_user_profile', array( $this, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
	}

	/**
	 * Add the one-time-code field to the login form.
	 *
	 * @return void
	 */
	public function code_field() {
		echo '<p><label for="dicestack_2fa">' . esc_html__( 'Authentication code (if enabled)', 'dicestack' ) . '<br />';
		echo '<input type="text" name="dicestack_2fa" id="dicestack_2fa" class="input" inputmode="numeric" autocomplete="one-time-code" value="" size="20" /></label></p>';
	}

	/**
	 * Verify the TOTP code for users who have 2FA enabled.
	 *
	 * @param \WP_User|\WP_Error|null $user Auth result so far.
	 * @return \WP_User|\WP_Error|null
	 */
	public function verify( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return $user;
		}
		if ( ! get_user_meta( $user->ID, self::ENABLED_KEY, true ) ) {
			return $user;
		}
		$secret = (string) get_user_meta( $user->ID, self::SECRET_KEY, true );
		if ( '' === $secret ) {
			return $user;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this IS the login handshake; WP core verifies credentials here.
		$code = isset( $_POST['dicestack_2fa'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['dicestack_2fa'] ) ) ) : '';
		if ( '' === $code || ! $this->check_totp( $secret, $code ) ) {
			return new \WP_Error( 'dicestack_2fa', __( '<strong>Error:</strong> Invalid or missing two-factor authentication code.', 'dicestack' ) );
		}
		return $user;
	}

	/**
	 * Render the per-user 2FA setup on the profile screen.
	 *
	 * @param \WP_User $user Profile user.
	 * @return void
	 */
	public function profile_fields( $user ) {
		$enabled = (bool) get_user_meta( $user->ID, self::ENABLED_KEY, true );
		$secret  = (string) get_user_meta( $user->ID, self::SECRET_KEY, true );
		if ( '' === $secret ) {
			$secret = $this->generate_secret();
		}
		$issuer = rawurlencode( get_bloginfo( 'name' ) );
		$label  = rawurlencode( $user->user_login );
		$uri    = 'otpauth://totp/' . $issuer . ':' . $label . '?secret=' . $secret . '&issuer=' . $issuer;

		wp_nonce_field( 'dicestack_2fa_profile', 'dicestack_2fa_nonce' );
		echo '<h2>' . esc_html__( 'Two-factor authentication', 'dicestack' ) . '</h2>';
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Enable 2FA', 'dicestack' ) . '</th><td>';
		echo '<label><input type="checkbox" name="dicestack_2fa_enable" value="1" ' . checked( $enabled, true, false ) . ' /> ' . esc_html__( 'Require an authentication code at login', 'dicestack' ) . '</label>';
		echo '<input type="hidden" name="dicestack_2fa_secret" value="' . esc_attr( $secret ) . '" />';
		echo '<p class="description">' . esc_html__( 'In your authenticator app add a new account and enter this key:', 'dicestack' ) . ' <code>' . esc_html( $secret ) . '</code></p>';
		echo '<p class="description"><a href="' . esc_url( $uri ) . '">' . esc_html__( 'Or open this otpauth link on your phone.', 'dicestack' ) . '</a></p>';
		echo '</td></tr></table>';
	}

	/**
	 * Save the profile 2FA settings.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function save_profile( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$nonce = isset( $_POST['dicestack_2fa_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dicestack_2fa_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'dicestack_2fa_profile' ) ) {
			return;
		}
		$secret = isset( $_POST['dicestack_2fa_secret'] ) ? preg_replace( '/[^A-Z2-7]/', '', sanitize_text_field( wp_unslash( $_POST['dicestack_2fa_secret'] ) ) ) : '';
		if ( '' !== $secret ) {
			update_user_meta( $user_id, self::SECRET_KEY, $secret );
		}
		update_user_meta( $user_id, self::ENABLED_KEY, empty( $_POST['dicestack_2fa_enable'] ) ? 0 : 1 );
	}

	/**
	 * Generate a random base32 secret.
	 *
	 * @return string
	 */
	private function generate_secret() {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$secret   = '';
		$bytes    = function_exists( 'random_bytes' ) ? random_bytes( 20 ) : wp_generate_password( 20, false );
		for ( $i = 0, $len = strlen( $bytes ); $i < $len; $i++ ) {
			$secret .= $alphabet[ ord( $bytes[ $i ] ) & 31 ];
		}
		return $secret;
	}

	/**
	 * Verify a TOTP code against a secret, allowing one step of clock drift.
	 *
	 * @param string $secret Base32 secret.
	 * @param string $code   6-digit code.
	 * @return bool
	 */
	private function check_totp( $secret, $code ) {
		$key = $this->base32_decode( $secret );
		if ( '' === $key ) {
			return false;
		}
		$counter = (int) floor( time() / 30 );
		for ( $offset = -1; $offset <= 1; $offset++ ) {
			if ( hash_equals( $this->hotp( $key, $counter + $offset ), $code ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compute a 6-digit HOTP value.
	 *
	 * @param string $key     Raw secret bytes.
	 * @param int    $counter Time counter.
	 * @return string
	 */
	private function hotp( $key, $counter ) {
		$bin  = pack( 'N*', 0 ) . pack( 'N*', $counter );
		$hash = hash_hmac( 'sha1', $bin, $key, true );
		$off  = ord( $hash[ strlen( $hash ) - 1 ] ) & 0xF;
		$part = ( ( ord( $hash[ $off ] ) & 0x7F ) << 24 ) | ( ( ord( $hash[ $off + 1 ] ) & 0xFF ) << 16 ) | ( ( ord( $hash[ $off + 2 ] ) & 0xFF ) << 8 ) | ( ord( $hash[ $off + 3 ] ) & 0xFF );
		return str_pad( (string) ( $part % 1000000 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Decode a base32 string to raw bytes.
	 *
	 * @param string $b32 Base32 string.
	 * @return string
	 */
	private function base32_decode( $b32 ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$b32      = strtoupper( $b32 );
		$buffer   = 0;
		$bits     = 0;
		$out      = '';
		for ( $i = 0, $len = strlen( $b32 ); $i < $len; $i++ ) {
			$pos = strpos( $alphabet, $b32[ $i ] );
			if ( false === $pos ) {
				continue;
			}
			$buffer = ( $buffer << 5 ) | $pos;
			$bits  += 5;
			if ( $bits >= 8 ) {
				$bits -= 8;
				$out  .= chr( ( $buffer >> $bits ) & 0xFF );
			}
		}
		return $out;
	}
}
