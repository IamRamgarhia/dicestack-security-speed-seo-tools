<?php
/**
 * Cache Warmup module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Performance;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Periodically requests a list of URLs on a schedule so the page cache stays
 * warm and visitors rarely hit a cold, uncached page. Replaces the cache
 * preload feature of premium performance plugins.
 */
final class Cache_Warmup extends Abstract_Module {

	const CRON        = 'dicestack_cache_warmup';
	const LAST_OPTION = 'dicestack_cache_warmup_last';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'cache_warmup';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Cache warmup', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Preload your pages on a schedule so visitors always hit a warm cache.', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'performance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'flame';
	}

	/**
	 * {@inheritDoc}
	 */
	public function replaces() {
		return 'cache preload plugins';
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
			'external_http' => 5,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema() {
		return array(
			array(
				'key'     => 'urls',
				'label'   => __( 'URLs to warm', 'dicestack' ),
				'type'    => 'textarea',
				'default' => '',
				'help'    => __( 'One URL per line. Leave empty to warm the homepage and, if present, the WordPress sitemap.', 'dicestack' ),
			),
			array(
				'key'     => 'frequency',
				'label'   => __( 'Frequency', 'dicestack' ),
				'type'    => 'select',
				'default' => 'daily',
				'options' => array(
					'hourly'     => __( 'Hourly', 'dicestack' ),
					'twicedaily' => __( 'Twice daily', 'dicestack' ),
					'daily'      => __( 'Daily', 'dicestack' ),
				),
				'help'    => __( 'How often the warmup runs in the background.', 'dicestack' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		add_action( self::CRON, array( $this, 'warm' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ), 1 );
		add_action( 'dicestack_module_disabled_' . $this->id(), array( $this, 'clear_schedule' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_post_dicestack_warm_now', array( $this, 'handle_warm_now' ) );
		}
	}

	/**
	 * Ensure the cron event is scheduled at the chosen frequency, rescheduling
	 * if the frequency has changed since the last run.
	 *
	 * @return void
	 */
	public function ensure_schedule() {
		$allowed   = array( 'hourly', 'twicedaily', 'daily' );
		$frequency = (string) $this->get_setting( 'frequency', 'daily' );
		if ( ! in_array( $frequency, $allowed, true ) ) {
			$frequency = 'daily';
		}

		$scheduled = wp_get_schedule( self::CRON );
		if ( $scheduled === $frequency ) {
			return;
		}

		// Frequency changed (or never scheduled) — clear then re-add.
		$this->clear_schedule();
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $frequency, self::CRON );
	}

	/**
	 * Remove the cron event.
	 *
	 * @return void
	 */
	public function clear_schedule() {
		$ts = wp_next_scheduled( self::CRON );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON );
			$ts = wp_next_scheduled( self::CRON );
		}
	}

	/**
	 * Register the tool page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'dicestack',
			__( 'Cache warmup', 'dicestack' ),
			__( 'Cache warmup', 'dicestack' ),
			'manage_options',
			'dicestack-cache-warmup',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the tool page: description, warm-now button, and last-run time.
	 *
	 * @return void
	 */
	public function render_page() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Cache warmup', 'dicestack' ) . '</h1>';
		echo '<p>' . esc_html__( 'Requests your pages in the background so the cache is rebuilt before visitors arrive. This runs automatically on your chosen schedule; use the button below to run it right now.', 'dicestack' ) . '</p>';

		$last = (int) get_option( self::LAST_OPTION, 0 );
		if ( $last > 0 ) {
			echo '<p>' . esc_html__( 'Last run:', 'dicestack' ) . ' <strong>' . esc_html(
				sprintf(
					/* translators: %s: human-readable time difference, e.g. "5 minutes". */
					__( '%s ago', 'dicestack' ),
					human_time_diff( $last, time() )
				)
			) . '</strong></p>';
		} else {
			echo '<p><em>' . esc_html__( 'The warmup has not run yet.', 'dicestack' ) . '</em></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dicestack_warm_now' );
		echo '<input type="hidden" name="action" value="dicestack_warm_now" />';
		submit_button( __( 'Warm now', 'dicestack' ) );
		echo '</form></div>';
	}

	/**
	 * Handle the "Warm now" button.
	 *
	 * @return void
	 */
	public function handle_warm_now() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dicestack_warm_now' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}

		$this->warm();

		wp_safe_redirect( add_query_arg( array( 'page' => 'dicestack-cache-warmup', 'warmed' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Build the URL list and request each one to prime the cache.
	 *
	 * @return void
	 */
	public function warm() {
		$urls = $this->build_url_list();
		if ( empty( $urls ) ) {
			return;
		}

		foreach ( $urls as $url ) {
			wp_remote_get(
				$url,
				array(
					'timeout'     => 5,
					'blocking'    => false,
					'redirection' => 2,
					'sslverify'   => false,
					'user-agent'  => 'DiceStack-CacheWarmup/1.0',
				)
			);
		}

		update_option( self::LAST_OPTION, time() );
	}

	/**
	 * Compute the list of URLs to warm.
	 *
	 * @return string[]
	 */
	private function build_url_list() {
		$raw   = (string) $this->get_setting( 'urls', '' );
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$urls  = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$clean = esc_url_raw( $line );
			if ( '' !== $clean ) {
				$urls[] = $clean;
			}
		}

		if ( empty( $urls ) ) {
			$urls[] = home_url( '/' );
			if ( function_exists( 'wp_sitemaps_get_server' ) ) {
				$urls[] = home_url( '/wp-sitemap.xml' );
			}
		}

		return array_values( array_unique( $urls ) );
	}
}
