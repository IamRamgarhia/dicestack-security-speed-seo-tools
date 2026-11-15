<?php
/**
 * Login Math Captcha module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Security;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a simple math question to the wp-login form to stop automated login
 * attempts — no external CAPTCHA service. The answer is verified via a salted
 * hash so it works statelessly.
 */
final class Login_Captcha extends Abstract_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'login_captcha';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Login math captcha', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Require a simple sum to log in, blocking automated login bots — no external service.', 'dicestack' );
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
		return 'lock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 14,
			'front_js_kb'   => 0,
			'front_css_kb'  => 0,
			'db_queries'    => 0,
			'external_http' => 0,
		);
	}

	/**
	 * Hash an answer with WordPress's salt.
	 *
	 * @param int $answer Numeric answer.
	 * @return string
	 */
	private function hash( $answer, $expires ) {
		return wp_hash( 'dicestack_login_math|' . $answer . '|' . $expires );
	}

	/**
	 * How long a rendered challenge stays valid.
	 *
	 * The old token was wp_hash() of the answer alone. The answer is a sum of
	 * two digits between 1 and 9, so there were seventeen possible tokens, they
	 * never changed for the life of the site, and they never expired. Fetching
	 * the login page once gave a bot a question-and-token pair it could replay
	 * forever -- which is to say the challenge cost an attacker one request,
	 * once.
	 */
	const CHALLENGE_TTL = 1800;

	/**
	 * Build the signed token that travels with the form.
	 *
	 * @param int $answer  Expected answer.
	 * @param int $expires Unix time the challenge stops being valid.
	 * @return string
	 */
	private function token( $answer, $expires ) {
		return $expires . '.' . $this->hash( $answer, $expires );
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		add_action( 'login_form', array( $this, 'field' ) );
		add_filter( 'authenticate', array( $this, 'verify' ), 25 );
	}

	/**
	 * Output the captcha field on the login form.
	 *
	 * @return void
	 */
	public function field() {
		$a   = wp_rand( 1, 9 );
		$b   = wp_rand( 1, 9 );
		echo '<p><label for="dicestack_login_math">' . sprintf( /* translators: 1: first number, 2: second number. */ esc_html__( 'What is %1$d + %2$d?', 'dicestack' ), (int) $a, (int) $b ) . '<br/>';
		echo '<input type="text" name="dicestack_login_math" id="dicestack_login_math" class="input" size="20" autocomplete="off" /></label>';
		$expires = time() + self::CHALLENGE_TTL;
		echo '<input type="hidden" name="dicestack_login_math_h" value="' . esc_attr( $this->token( $a + $b, $expires ) ) . '" /></p>';
	}

	/**
	 * Verify the captcha answer during authentication.
	 *
	 * @param mixed $user Auth result.
	 * @return mixed
	 */
	public function verify( $user ) {
		// Don't override an error from an earlier filter (e.g. brute-force lockout).
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- core login flow; reading our captcha fields only.
		if ( empty( $_POST['log'] ) ) {
			return $user;
		}

		$supplied = isset( $_POST['dicestack_login_math_h'] )
			? sanitize_text_field( wp_unslash( $_POST['dicestack_login_math_h'] ) )
			: '';

		if ( '' === $supplied ) {
			// Missing token. This used to return $user unconditionally, which
			// meant a bot that simply did not send the field skipped the
			// challenge altogether -- and a bot posting straight to
			// wp-login.php is exactly what this module exists to stop.
			//
			// Still open where the field genuinely was not rendered: XML-RPC,
			// the REST API, application passwords and any plugin calling
			// wp_signon() do not go through a wp-login.php POST and never see
			// login_form. Closed where it was.
			if ( $this->is_login_form_post() ) {
				return new \WP_Error(
					'dicestack_login_captcha',
					__( '<strong>Error:</strong> Please answer the math question on the login form.', 'dicestack' )
				);
			}

			return $user;
		}

		$answer = isset( $_POST['dicestack_login_math'] ) ? absint( wp_unslash( $_POST['dicestack_login_math'] ) ) : -1;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		list( $expires, $hash ) = array_pad( explode( '.', $supplied, 2 ), 2, '' );
		$expires                = (int) $expires;

		if ( $expires < time() ) {
			return new \WP_Error(
				'dicestack_login_captcha',
				__( '<strong>Error:</strong> That login form had been open too long. Please try again.', 'dicestack' )
			);
		}

		if ( ! hash_equals( $hash, $this->hash( $answer, $expires ) ) ) {
			return new \WP_Error( 'dicestack_login_captcha', __( '<strong>Error:</strong> Incorrect answer to the math question.', 'dicestack' ) );
		}
		return $user;
	}

	/**
	 * Is this a POST to wp-login.php, where login_form ran and our field was
	 * therefore on the page?
	 *
	 * @return bool
	 */
	private function is_login_form_post() {
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: '';

		if ( 'POST' !== $method ) {
			return false;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) )
			: '';

		return false !== strpos( $script, 'wp-login.php' );
	}
}
