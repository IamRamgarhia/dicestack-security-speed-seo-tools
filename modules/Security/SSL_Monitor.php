<?php
/**
 * SSL Certificate Monitor module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Security;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the site's SSL certificate once a day and warns in the admin when it
 * is expiring soon (or has expired). Complements the Force HTTPS / Mixed
 * Content tools — the "is my certificate still valid?" piece of Really Simple SSL.
 */
final class SSL_Monitor extends Abstract_Module {

	const OPTION = 'dicestack_ssl_status';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'ssl_monitor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'SSL certificate monitor', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Warn in the dashboard when your SSL certificate is close to expiring.', 'dicestack' );
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
		return 'certificate';
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
			'external_http' => 1,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema() {
		return array(
			array(
				'key'     => 'warn_days',
				'label'   => __( 'Warn when fewer than this many days remain', 'dicestack' ),
				'type'    => 'number',
				'default' => 14,
				'min'     => 1,
				'max'     => 90,
				'step'    => 1,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'maybe_check' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Run the certificate check at most once per day.
	 *
	 * @return void
	 */
	public function maybe_check() {
		if ( ! current_user_can( 'manage_options' ) || get_transient( 'dicestack_ssl_checked' ) ) {
			return;
		}
		set_transient( 'dicestack_ssl_checked', 1, DAY_IN_SECONDS );
		$this->check_cert();
	}

	/**
	 * Connect to the site over TLS and read the certificate's expiry date.
	 *
	 * @return void
	 */
	public function check_cert() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host || ! function_exists( 'stream_socket_client' ) || ! function_exists( 'openssl_x509_parse' ) ) {
			return;
		}

		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'SNI_enabled'       => true,
				),
			)
		);

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed TLS connect is handled below, not fatal.
		$client = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 7, STREAM_CLIENT_CONNECT, $context );
		if ( ! $client ) {
			update_option( self::OPTION, array( 'error' => true, 'checked' => time() ) );
			return;
		}

		$params = stream_context_get_params( $client );
		fclose( $client );

		if ( empty( $params['options']['ssl']['peer_certificate'] ) ) {
			return;
		}
		$cert = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
		if ( empty( $cert['validTo_time_t'] ) ) {
			return;
		}

		$days = (int) floor( ( (int) $cert['validTo_time_t'] - time() ) / DAY_IN_SECONDS );
		update_option(
			self::OPTION,
			array(
				'days'     => $days,
				'valid_to' => (int) $cert['validTo_time_t'],
				'checked'  => time(),
			)
		);
	}

	/**
	 * Show an admin warning when the certificate is expiring or expired.
	 *
	 * @return void
	 */
	public function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = get_option( self::OPTION );
		if ( ! is_array( $status ) || ! isset( $status['days'] ) ) {
			return;
		}
		$warn = (int) $this->get_setting( 'warn_days', 14 );
		$days = (int) $status['days'];
		if ( $days > $warn ) {
			return;
		}

		if ( $days < 0 ) {
			$message = __( 'Your SSL certificate has expired. Visitors will see security warnings — renew it now.', 'dicestack' );
		} else {
			/* translators: %d: number of days. */
			$message = sprintf( _n( 'Your SSL certificate expires in %d day. Renew it soon.', 'Your SSL certificate expires in %d days. Renew it soon.', $days, 'dicestack' ), $days );
		}
		printf( '<div class="notice notice-error"><p><strong>DiceStack:</strong> %s</p></div>', esc_html( $message ) );
	}
}
