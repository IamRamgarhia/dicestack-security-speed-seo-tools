<?php
/**
 * Under Attack Mode module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Security;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Emergency lockdown for a site under active attack. When enabled it hardens
 * the most-abused public surfaces (XML-RPC, the anonymous REST API, user
 * registration) and optionally rate-limits logged-out visitors by IP — while
 * never touching logged-in users so an admin can always reach wp-admin.
 */
final class Under_Attack_Mode extends Abstract_Module {

	/**
	 * Transient prefix for the per-IP rate-limit counters.
	 */
	const RATE_PREFIX = 'dicestack_uam_rl_';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'under_attack_mode';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Under Attack mode', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'One-switch emergency lockdown for when your site is being attacked. Logged-in users are unaffected.', 'dicestack' );
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
		return 'shield-bolt';
	}

	/**
	 * {@inheritDoc}
	 */
	public function replaces() {
		return 'premium firewall plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 25,
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
				'key'     => 'enabled',
				'label'   => __( 'Under Attack mode is ON', 'dicestack' ),
				'type'    => 'toggle',
				'default' => false,
				'help'    => __( 'Emergency lockdown for when your site is being attacked. Logged-in users are unaffected.', 'dicestack' ),
			),
			array(
				'key'     => 'block_xmlrpc',
				'label'   => __( 'Block XML-RPC', 'dicestack' ),
				'type'    => 'toggle',
				'default' => true,
				'help'    => __( 'Disables xmlrpc.php, a frequent brute-force and DDoS amplification target.', 'dicestack' ),
			),
			array(
				'key'     => 'block_rest_anon',
				'label'   => __( 'Block the REST API for logged-out visitors', 'dicestack' ),
				'type'    => 'toggle',
				'default' => true,
				'help'    => __( 'Anonymous REST requests are refused; logged-in users keep full access.', 'dicestack' ),
			),
			array(
				'key'     => 'block_registration',
				'label'   => __( 'Block new user registration', 'dicestack' ),
				'type'    => 'toggle',
				'default' => true,
				'help'    => __( 'Turns off open registration while the attack is ongoing.', 'dicestack' ),
			),
			array(
				'key'     => 'rate_limit',
				'label'   => __( 'Rate limit (requests per minute per IP)', 'dicestack' ),
				'type'    => 'number',
				'default' => 60,
				'min'     => 0,
				'max'     => 1000,
				'step'    => 1,
				'help'    => __( 'Max requests per minute per IP for logged-out visitors; 0 disables.', 'dicestack' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		$s = $this->get_settings();

		// Do nothing at all unless the emergency switch is on.
		if ( empty( $s['enabled'] ) ) {
			return;
		}

		if ( ! empty( $s['block_xmlrpc'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_action( 'init', array( $this, 'maybe_block_xmlrpc' ), 1 );
		}

		if ( ! empty( $s['block_rest_anon'] ) ) {
			add_filter( 'rest_authentication_errors', array( $this, 'block_rest_anon' ) );
		}

		if ( ! empty( $s['block_registration'] ) ) {
			add_filter( 'option_users_can_register', '__return_zero' );
		}

		if ( (int) $s['rate_limit'] > 0 ) {
			add_action( 'init', array( $this, 'enforce_rate_limit' ), 1 );
		}
	}

	/**
	 * Return a 403 for direct hits to xmlrpc.php.
	 *
	 * @return void
	 */
	public function maybe_block_xmlrpc() {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			status_header( 403 );
			wp_die(
				esc_html__( 'XML-RPC is disabled while the site is under attack.', 'dicestack' ),
				esc_html__( 'Forbidden', 'dicestack' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Refuse anonymous REST API requests.
	 *
	 * @param \WP_Error|null|true $result Current auth result.
	 * @return \WP_Error|null|true
	 */
	public function block_rest_anon( $result ) {
		// Preserve an existing error and don't override a passed auth.
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'dicestack_uam_rest_blocked',
				__( 'The REST API is disabled for logged-out visitors while the site is under attack.', 'dicestack' ),
				array( 'status' => 401 )
			);
		}
		return $result;
	}

	/**
	 * Per-IP, per-minute rate limit for logged-out visitors.
	 *
	 * @return void
	 */
	public function enforce_rate_limit() {
		// Never rate-limit logged-in users, so an admin is always let through.
		if ( is_user_logged_in() ) {
			return;
		}

		$limit = (int) $this->get_setting( 'rate_limit', 60 );
		if ( $limit <= 0 ) {
			return;
		}

		$key   = self::RATE_PREFIX . md5( $this->get_ip() . '|' . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $key );
		++$count;
		set_transient( $key, $count, MINUTE_IN_SECONDS );

		if ( $count > $limit ) {
			status_header( 429 );
			header( 'Retry-After: 60' );
			wp_die(
				esc_html__( 'Too many requests — please slow down and try again in a minute.', 'dicestack' ),
				esc_html__( 'Too many requests', 'dicestack' ),
				array( 'response' => 429 )
			);
		}
	}

	/**
	 * Best-effort visitor IP. Defaults to REMOTE_ADDR (not attacker-spoofable)
	 * so a rate limit can't be dodged with a forged header.
	 *
	 * @return string
	 */
	private function get_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
	}
}
