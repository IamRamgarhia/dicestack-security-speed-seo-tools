<?php
/**
 * Login IP Allowlist module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Security;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Restricts the WordPress login page (and, optionally, wp-admin) to a list of
 * trusted IPv4 addresses or CIDR ranges.
 *
 * ANTI-LOCKOUT DESIGN: the allowlist is fail-open. If the list is EMPTY the
 * guard does nothing and nobody is ever blocked. Logged-in users are never
 * blocked either, so an admin who is already signed in cannot lock themselves
 * out by mistake.
 */
final class Login_IP_Allowlist extends Abstract_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'login_ip_allowlist';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Login IP allowlist', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Restrict the login page (and optionally wp-admin) to trusted IP addresses or CIDR ranges.', 'dicestack' );
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
		return 'lock-access';
	}

	/**
	 * {@inheritDoc}
	 */
	public function replaces() {
		return 'premium security plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 15,
			'front_js_kb'   => 0,
			'front_css_kb'  => 0,
			'db_queries'    => 1,
			'external_http' => 0,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The allowed_ips help text dynamically shows the admin their current IP so
	 * they can add it before enabling the restriction (anti-lockout aid).
	 */
	public function settings_schema() {
		$current_ip = $this->current_ip();

		$help = __( 'One IPv4 address or CIDR range per line. If this is EMPTY the allowlist is OFF (nobody is locked out). Your current IP is shown below.', 'dicestack' );
		if ( '' !== $current_ip ) {
			/* translators: %s: the visitor's current IP address. */
			$help .= ' ' . sprintf( __( 'Your current IP address is %s.', 'dicestack' ), $current_ip );
		}

		return array(
			array(
				'key'     => 'allowed_ips',
				'label'   => __( 'Allowed IP addresses', 'dicestack' ),
				'type'    => 'textarea',
				'default' => '',
				'help'    => $help,
			),
			array(
				'key'     => 'protect_admin',
				'label'   => __( 'Also restrict wp-admin, not just the login page', 'dicestack' ),
				'type'    => 'toggle',
				'default' => false,
				'help'    => __( 'Applies the same allowlist to wp-admin. Logged-in users are never blocked.', 'dicestack' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		add_action( 'login_init', array( $this, 'guard' ) );

		if ( ! empty( $this->get_setting( 'protect_admin', false ) ) ) {
			// admin_init fires after login; guard() bails for logged-in users so
			// this only affects direct wp-admin hits by unauthenticated visitors.
			add_action( 'admin_init', array( $this, 'guard' ) );
		}
	}

	/**
	 * Enforce the allowlist. Fail-open: empty list or a logged-in user is never
	 * blocked.
	 *
	 * @return void
	 */
	public function guard() {
		// Never block someone who is already authenticated (anti-lockout).
		if ( is_user_logged_in() ) {
			return;
		}

		$entries = $this->allowed_entries();

		// Empty allowlist = feature disabled = nobody is locked out.
		if ( empty( $entries ) ) {
			return;
		}

		$ip = $this->current_ip();
		if ( '' !== $ip && $this->ip_is_allowed( $ip, $entries ) ) {
			return;
		}

		status_header( 403 );
		wp_die(
			esc_html__( 'Access to this page is restricted.', 'dicestack' ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Parse the configured allowlist into an array of trimmed, non-empty lines.
	 *
	 * @return string[]
	 */
	private function allowed_entries() {
		$raw   = (string) $this->get_setting( 'allowed_ips', '' );
		$lines = preg_split( '/[\r\n]+/', $raw );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * The visitor's IP address from REMOTE_ADDR (the only spoof-resistant source).
	 *
	 * @return string
	 */
	private function current_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		return (string) $ip;
	}

	/**
	 * Does the IP match any allowlist entry (single IPv4 or CIDR)?
	 *
	 * @param string   $ip      Visitor IP.
	 * @param string[] $entries Allowlist entries.
	 * @return bool
	 */
	private function ip_is_allowed( $ip, array $entries ) {
		foreach ( $entries as $entry ) {
			if ( false !== strpos( $entry, '/' ) ) {
				if ( $this->ip_in_cidr( $ip, $entry ) ) {
					return true;
				}
			} elseif ( $ip === $entry ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Test whether an IPv4 address falls inside a CIDR range.
	 *
	 * @param string $ip   IPv4 address.
	 * @param string $cidr CIDR range, e.g. 203.0.113.0/24.
	 * @return bool
	 */
	private function ip_in_cidr( $ip, $cidr ) {
		$parts = explode( '/', $cidr, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		list( $subnet, $bits ) = $parts;
		$bits = (int) $bits;
		if ( $bits < 0 || $bits > 32 ) {
			return false;
		}

		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( trim( $subnet ) );
		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}

		// /0 matches everything; avoid an undefined 32-bit shift.
		if ( 0 === $bits ) {
			return true;
		}

		$mask = -1 << ( 32 - $bits );
		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}
}
