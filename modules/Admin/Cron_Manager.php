<?php
/**
 * Cron Manager module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Admin;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every scheduled WP-Cron event and lets you run or delete one. Handy for
 * spotting stuck or duplicated jobs without wp-cli.
 */
final class Cron_Manager extends Abstract_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'cron_manager';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Cron events', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'View, run, or delete scheduled WP-Cron events from the dashboard.', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'admin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'clock-bolt';
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
	public function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_post_dicestack_cron_run', array( $this, 'handle_run' ) );
			add_action( 'admin_post_dicestack_cron_delete', array( $this, 'handle_delete' ) );
		}
	}

	/**
	 * Register the submenu page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'dicestack',
			__( 'Cron events', 'dicestack' ),
			__( 'Cron events', 'dicestack' ),
			'manage_options',
			'dicestack-cron',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Look up a single event's args from the cron array by timestamp/hook/signature.
	 *
	 * @param int    $timestamp Event timestamp.
	 * @param string $hook      Hook name.
	 * @param string $sig       The md5(serialize(args)) key WordPress uses.
	 * @return array|null The event args, or null if not found.
	 */
	private function find_args( $timestamp, $hook, $sig ) {
		$cron = _get_cron_array();
		if ( isset( $cron[ $timestamp ][ $hook ][ $sig ]['args'] ) ) {
			return (array) $cron[ $timestamp ][ $hook ][ $sig ]['args'];
		}
		return null;
	}

	/**
	 * Run an event's hook immediately.
	 *
	 * @return void
	 */
	public function handle_run() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dicestack_cron_run' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}
		$timestamp = isset( $_POST['ts'] ) ? absint( $_POST['ts'] ) : 0;
		$hook      = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
		$sig       = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
		$args      = $this->find_args( $timestamp, $hook, $sig );

		if ( '' !== $hook && null !== $args ) {
			// Fire the hook's callbacks right now.
			do_action_ref_array( $hook, $args );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-cron&ran=1' ) );
		exit;
	}

	/**
	 * Delete a scheduled event.
	 *
	 * @return void
	 */
	public function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dicestack_cron_delete' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}
		$timestamp = isset( $_POST['ts'] ) ? absint( $_POST['ts'] ) : 0;
		$hook      = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
		$sig       = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
		$args      = $this->find_args( $timestamp, $hook, $sig );

		if ( $timestamp > 0 && '' !== $hook && null !== $args ) {
			wp_unschedule_event( $timestamp, $hook, $args );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-cron&deleted=1' ) );
		exit;
	}

	/**
	 * Render the cron events page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}

		$cron   = _get_cron_array();
		$cron   = is_array( $cron ) ? $cron : array();
		$now    = time();
		$action = esc_url( admin_url( 'admin-post.php' ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'Cron events', 'dicestack' ) . '</h1>';
		echo '<p>' . esc_html__( 'Every task WordPress has scheduled. Run a job now to test it, or delete a stuck/duplicate one.', 'dicestack' ) . '</p>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash flags.
		if ( isset( $_GET['ran'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Event executed.', 'dicestack' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash flags.
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Event deleted.', 'dicestack' ) . '</p></div>';
		}

		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		echo '<p style="color:#50575e;">' . esc_html(
			$disabled
				? __( 'WP-Cron is disabled (DISABLE_WP_CRON is true) — events run from a real server cron.', 'dicestack' )
				: __( 'WP-Cron runs on site visits.', 'dicestack' )
		) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Hook', 'dicestack' ) . '</th>';
		echo '<th>' . esc_html__( 'Schedule', 'dicestack' ) . '</th>';
		echo '<th>' . esc_html__( 'Next run', 'dicestack' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'dicestack' ) . '</th>';
		echo '</tr></thead><tbody>';

		$count = 0;
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				foreach ( $events as $sig => $event ) {
					$count++;
					$schedule = isset( $event['schedule'] ) && $event['schedule'] ? $event['schedule'] : __( 'one-time', 'dicestack' );
					$when     = (int) $timestamp > $now
						/* translators: %s: human time difference. */
						? sprintf( __( 'in %s', 'dicestack' ), human_time_diff( $now, (int) $timestamp ) )
						: __( 'overdue', 'dicestack' );

					echo '<tr>';
					echo '<td><code>' . esc_html( (string) $hook ) . '</code></td>';
					echo '<td>' . esc_html( (string) $schedule ) . '</td>';
					echo '<td>' . esc_html( $when ) . '</td>';
					echo '<td style="display:flex;gap:6px;">';

					// Run now.
					echo '<form method="post" action="' . $action . '" style="display:inline;">';
					wp_nonce_field( 'dicestack_cron_run' );
					echo '<input type="hidden" name="action" value="dicestack_cron_run" />';
					echo '<input type="hidden" name="ts" value="' . esc_attr( (string) $timestamp ) . '" />';
					echo '<input type="hidden" name="hook" value="' . esc_attr( (string) $hook ) . '" />';
					echo '<input type="hidden" name="sig" value="' . esc_attr( (string) $sig ) . '" />';
					echo '<button type="submit" class="button button-small">' . esc_html__( 'Run now', 'dicestack' ) . '</button>';
					echo '</form>';

					// Delete.
					echo '<form method="post" action="' . $action . '" style="display:inline;" onsubmit="return confirm(\'' . esc_js( __( 'Delete this scheduled event?', 'dicestack' ) ) . '\');">';
					wp_nonce_field( 'dicestack_cron_delete' );
					echo '<input type="hidden" name="action" value="dicestack_cron_delete" />';
					echo '<input type="hidden" name="ts" value="' . esc_attr( (string) $timestamp ) . '" />';
					echo '<input type="hidden" name="hook" value="' . esc_attr( (string) $hook ) . '" />';
					echo '<input type="hidden" name="sig" value="' . esc_attr( (string) $sig ) . '" />';
					echo '<button type="submit" class="button button-small">' . esc_html__( 'Delete', 'dicestack' ) . '</button>';
					echo '</form>';

					echo '</td></tr>';
				}
			}
		}

		if ( 0 === $count ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No scheduled events.', 'dicestack' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
