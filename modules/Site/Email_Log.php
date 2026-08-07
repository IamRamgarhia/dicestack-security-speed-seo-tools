<?php
/**
 * Email Log module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Site;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Records every email WordPress sends (recipient, subject, time and whether it
 * succeeded or failed) so you can confirm password resets, order emails and
 * form notifications are actually going out — invaluable when debugging mail.
 */
final class Email_Log extends Abstract_Module {

	/**
	 * Option storing the log entries.
	 */
	const OPTION = 'dicestack_email_log';

	/**
	 * Max stored entries (newest kept).
	 */
	const CAP = 200;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'email_log';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Email log', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Record every email the site sends so you can confirm delivery and debug mail problems.', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'site';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'mail-opened';
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 20,
			'front_js_kb'   => 0,
			'front_css_kb'  => 0,
			'db_queries'    => 1,
			'external_http' => 0,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		add_filter( 'wp_mail', array( $this, 'capture' ) );
		add_action( 'wp_mail_failed', array( $this, 'capture_failure' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_post_dicestack_email_log_clear', array( $this, 'handle_clear' ) );
		}
	}

	/**
	 * Capture a sent email. Runs on the wp_mail filter — must return $args intact.
	 *
	 * @param array $args wp_mail arguments ( to, subject, message, headers, attachments ).
	 * @return array The unchanged arguments.
	 */
	public function capture( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$to = isset( $args['to'] ) ? $args['to'] : '';
		if ( is_array( $to ) ) {
			$to = implode( ', ', array_map( 'sanitize_text_field', $to ) );
		} else {
			$to = sanitize_text_field( (string) $to );
		}

		$this->append(
			array(
				'time'    => time(),
				'to'      => $to,
				'subject' => isset( $args['subject'] ) ? sanitize_text_field( (string) $args['subject'] ) : '',
				'status'  => 'sent',
				'error'   => '',
			)
		);

		return $args;
	}

	/**
	 * Capture a failed email reported by WordPress.
	 *
	 * @param \WP_Error $e The mail error. Its data carries the original mail array.
	 * @return void
	 */
	public function capture_failure( \WP_Error $e ) {
		$data = $e->get_error_data();
		$to   = '';
		$subj = '';
		if ( is_array( $data ) ) {
			$raw = isset( $data['to'] ) ? $data['to'] : '';
			if ( is_array( $raw ) ) {
				$to = implode( ', ', array_map( 'sanitize_text_field', $raw ) );
			} else {
				$to = sanitize_text_field( (string) $raw );
			}
			$subj = isset( $data['subject'] ) ? sanitize_text_field( (string) $data['subject'] ) : '';
		}

		$this->append(
			array(
				'time'    => time(),
				'to'      => $to,
				'subject' => $subj,
				'status'  => 'failed',
				'error'   => sanitize_text_field( $e->get_error_message() ),
			)
		);
	}

	/**
	 * Append an entry and cap the log at the newest self::CAP rows.
	 *
	 * @param array $entry Log entry.
	 * @return void
	 */
	private function append( array $entry ) {
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::CAP );
		update_option( self::OPTION, $log, false );
	}

	/**
	 * Register the log page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'dicestack',
			__( 'Email log', 'dicestack' ),
			__( 'Email log', 'dicestack' ),
			'manage_options',
			'dicestack-email-log',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Clear the log.
	 *
	 * @return void
	 */
	public function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dicestack_email_log_clear' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}
		delete_option( self::OPTION );
		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-email-log&cleared=1' ) );
		exit;
	}

	/**
	 * Render the log table.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}

		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		echo '<div class="wrap"><h1>' . esc_html__( 'Email log', 'dicestack' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		if ( isset( $_GET['cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Email log cleared.', 'dicestack' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'The most recent emails your site tried to send. The newest 200 are kept.', 'dicestack' ) . '</p>';

		if ( ! empty( $log ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:16px;">';
			wp_nonce_field( 'dicestack_email_log_clear' );
			echo '<input type="hidden" name="action" value="dicestack_email_log_clear" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Clear log', 'dicestack' ) . '</button>';
			echo '</form>';
		}

		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'No emails recorded yet.', 'dicestack' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'dicestack' ) . '</th>';
		echo '<th>' . esc_html__( 'To', 'dicestack' ) . '</th>';
		echo '<th>' . esc_html__( 'Subject', 'dicestack' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'dicestack' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $log as $e ) {
			$time    = isset( $e['time'] ) ? (int) $e['time'] : 0;
			$when    = $time ? wp_date( 'Y-m-d H:i', $time ) : '';
			$ago     = $time ? sprintf( /* translators: %s: human time difference, e.g. "2 hours". */ __( '%s ago', 'dicestack' ), human_time_diff( $time, time() ) ) : '';
			$status  = isset( $e['status'] ) && 'failed' === $e['status'] ? 'failed' : 'sent';
			$is_fail = 'failed' === $status;
			$label   = $is_fail ? __( 'Failed', 'dicestack' ) : __( 'Sent', 'dicestack' );
			$color   = $is_fail ? '#b32d2e' : '#116149';
			$error   = isset( $e['error'] ) ? (string) $e['error'] : '';

			echo '<tr>';
			echo '<td>' . esc_html( $when ) . '<br /><span style="color:#787c82;font-size:11px;">' . esc_html( $ago ) . '</span></td>';
			echo '<td>' . esc_html( isset( $e['to'] ) ? (string) $e['to'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $e['subject'] ) ? (string) $e['subject'] : '' ) . '</td>';
			echo '<td><strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $label ) . '</strong>';
			if ( $is_fail && '' !== $error ) {
				echo '<br /><span style="color:#787c82;font-size:11px;">' . esc_html( $error ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}
}
