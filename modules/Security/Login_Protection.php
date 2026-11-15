<?php
/**
 * Login Protection module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Security;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Rate-limits failed logins per IP and locks out abusers. Replaces the core
 * brute-force protection sold in Wordfence / Loginizer Pro.
 */
final class Login_Protection extends Abstract_Module {

	/**
	 * Transient prefix for attempt counters.
	 */
	const PREFIX = 'dicestack_login_';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'login_protection';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Login protection', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Limit failed login attempts per IP and lock out brute-force bots.', 'dicestack' );
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
	public function replaces() {
		return 'premium login-security plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 90,
			'front_js_kb'   => 0,
			'front_css_kb'  => 0,
			'db_queries'    => 1,
			'external_http' => 0,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema() {
		return array(
			array(
				'key'     => 'max_attempts',
				'label'   => __( 'Max failed attempts before lockout', 'dicestack' ),
				'type'    => 'number',
				'default' => 5,
				'min'     => 2,
				'max'     => 20,
				'step'    => 1,
			),
			array(
				'key'     => 'lockout_minutes',
				'label'   => __( 'Lockout duration (minutes)', 'dicestack' ),
				'type'    => 'number',
				'default' => 15,
				'min'     => 1,
				'max'     => 1440,
				'step'    => 1,
			),
			array(
				'key'     => 'trust_proxy',
				'label'   => __( 'Site is behind a proxy / CDN (Cloudflare, etc.)', 'dicestack' ),
				'type'    => 'toggle',
				'default' => false,
				'help'    => __( 'Use the real visitor IP from proxy headers instead of the connecting IP. This also needs the proxy addresses below — without them the headers are ignored, because anyone can send them.', 'dicestack' ),
			),
			array(
				'key'     => 'trusted_proxies',
				'label'   => __( 'Trusted proxy addresses', 'dicestack' ),
				'type'    => 'textarea',
				'default' => '',
				'help'    => __( 'One IP or CIDR range per line, for example 173.245.48.0/20. Proxy headers are only believed when the request actually arrives from one of these. Cloudflare publishes its ranges at cloudflare.com/ips. Leave empty and the connecting IP is used instead — safe, but everyone behind the proxy shares one lockout.', 'dicestack' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		add_filter( 'authenticate', array( $this, 'check_lockout' ), 30 );
		add_action( 'wp_login_failed', array( $this, 'record_failure' ) );
		add_action( 'wp_login', array( $this, 'clear_failures' ) );
	}

	/**
	 * Block authentication if the IP is currently locked out.
	 *
	 * @param \WP_User|\WP_Error|null $user Auth result so far.
	 * @return \WP_User|\WP_Error|null
	 */
	public function check_lockout( $user ) {
		$data = get_transient( $this->key() );
		if ( is_array( $data ) && isset( $data['locked_until'] ) && time() < $data['locked_until'] ) {
			$minutes = max( 1, (int) ceil( ( $data['locked_until'] - time() ) / 60 ) );
			return new \WP_Error(
				'dicestack_locked',
				sprintf(
					/* translators: %d: minutes remaining. */
					esc_html__( 'Too many failed attempts. Try again in %d minutes.', 'dicestack' ),
					$minutes
				)
			);
		}
		return $user;
	}

	/**
	 * Increment the failure counter and lock out if the threshold is hit.
	 *
	 * @return void
	 */
	public function record_failure() {
		$max     = (int) $this->get_setting( 'max_attempts', 5 );
		$minutes = (int) $this->get_setting( 'lockout_minutes', 15 );
		$data    = get_transient( $this->key() );
		$data    = is_array( $data ) ? $data : array( 'count' => 0 );

		$data['count'] = (int) $data['count'] + 1;

		if ( $data['count'] >= $max ) {
			$data['locked_until'] = time() + ( $minutes * MINUTE_IN_SECONDS );
		}

		set_transient( $this->key(), $data, $minutes * MINUTE_IN_SECONDS );
	}

	/**
	 * Reset the counter on a successful login.
	 *
	 * @return void
	 */
	public function clear_failures() {
		delete_transient( $this->key() );
	}

	/**
	 * Transient key for the current visitor's IP.
	 *
	 * @return string
	 */
	private function key() {
		return self::PREFIX . md5( $this->get_ip() );
	}

	/**
	 * Best-effort client IP detection.
	 *
	 * @return string
	 */
	private function get_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';

		if ( empty( $this->get_setting( 'trust_proxy', false ) ) ) {
			return $remote;
		}

		// The setting alone is not enough. A proxy header is just a request
		// header: anyone can send one, including straight to the origin. Unless
		// the request actually arrived from a proxy we were told to trust, the
		// headers are ignored -- otherwise an attacker picks their own identity
		// and either dodges a lockout or pins one on somebody else.
		$trusted = $this->trusted_proxies();

		if ( empty( $trusted ) || ! $this->ip_in_list( $remote, $trusted ) ) {
			return $remote;
		}

		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP' ) as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$candidate = trim( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );

			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$raw  = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$hops = array_map( 'trim', explode( ',', $raw ) );

			// Right to left, not left to right. X-Forwarded-For reads
			// "client, proxy1, proxy2": each hop appends the address it heard
			// from, so the entries nearest the end were written by
			// infrastructure and the leftmost is whatever the client sent --
			// which is to say, whatever an attacker typed. Walk back from the
			// end and take the first hop that is not itself a trusted proxy.
			foreach ( array_reverse( $hops ) as $hop ) {
				if ( ! filter_var( $hop, FILTER_VALIDATE_IP ) ) {
					continue;
				}

				if ( ! $this->ip_in_list( $hop, $trusted ) ) {
					return $hop;
				}
			}
		}

		return $remote;
	}

	/**
	 * Proxy addresses the administrator has vouched for.
	 *
	 * @return string[] IPs or CIDR ranges.
	 */
	private function trusted_proxies() {
		$raw = (string) $this->get_setting( 'trusted_proxies', '' );

		if ( '' === trim( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $entry ) {
			$entry = trim( $entry );

			if ( '' !== $entry ) {
				$out[] = $entry;
			}
		}

		return $out;
	}

	/**
	 * Is $ip inside any of $list (plain addresses or CIDR ranges)?
	 *
	 * @param string   $ip   Address to test.
	 * @param string[] $list Addresses and CIDR ranges.
	 * @return bool
	 */
	private function ip_in_list( $ip, $list ) {
		foreach ( $list as $entry ) {
			if ( false === strpos( $entry, '/' ) ) {
				if ( $entry === $ip ) {
					return true;
				}
				continue;
			}

			list( $subnet, $bits ) = array_pad( explode( '/', $entry, 2 ), 2, '' );

			$packed_ip     = @inet_pton( $ip );     // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed entry is simply not a match.
			$packed_subnet = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			$bits          = (int) $bits;

			// Mixing families never matches, and comparing them would read past
			// the shorter of the two.
			if ( false === $packed_ip || false === $packed_subnet
				|| strlen( $packed_ip ) !== strlen( $packed_subnet ) ) {
				continue;
			}

			$max = strlen( $packed_ip ) * 8;

			if ( $bits < 0 || $bits > $max ) {
				continue;
			}

			$whole_bytes = intdiv( $bits, 8 );
			$spare_bits  = $bits % 8;

			if ( $whole_bytes > 0
				&& 0 !== substr_compare( $packed_ip, substr( $packed_subnet, 0, $whole_bytes ), 0, $whole_bytes ) ) {
				continue;
			}

			if ( 0 === $spare_bits ) {
				return true;
			}

			$mask = ~( ( 1 << ( 8 - $spare_bits ) ) - 1 ) & 0xFF;

			if ( ( ord( $packed_ip[ $whole_bytes ] ) & $mask ) === ( ord( $packed_subnet[ $whole_bytes ] ) & $mask ) ) {
				return true;
			}
		}

		return false;
	}
}
