<?php
/**
 * File Integrity Monitor module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Security;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies WordPress core files against the official WordPress.org checksums and
 * flags any that have been modified, are missing, or are UNKNOWN (extra .php
 * files inside wp-admin / wp-includes that are not part of core — a common place
 * for injected backdoors). Complements the File Scanner, which covers wp-content.
 * Replaces the core-scan feature of premium malware plugins.
 */
final class File_Integrity_Monitor extends Abstract_Module {

	const OPTION = 'dicestack_fim_result';
	const CRON   = 'dicestack_fim_scan';

	/**
	 * Max unknown-file candidates to examine (safety bound).
	 */
	const MAX_FILES = 8000;

	/**
	 * Soft time budget in seconds.
	 */
	const TIME_BUDGET = 15;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'file_integrity_monitor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'File integrity monitor', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Check WordPress core files against official checksums and flag modified, missing, or injected files.', 'dicestack' );
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
		return 'file-shredder';
	}

	/**
	 * {@inheritDoc}
	 */
	public function replaces() {
		return 'premium malware scanners';
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
			'external_http' => 1,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		add_action( self::CRON, array( $this, 'scan' ) );
		add_action( 'init', array( $this, 'ensure_schedule' ), 1 );
		add_action( 'admin_notices', array( $this, 'notice' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_post_dicestack_integrity_scan', array( $this, 'handle_scan' ) );
		}
	}

	/**
	 * Make sure the daily scan is scheduled.
	 *
	 * @return void
	 */
	public function ensure_schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Register the scan page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'dicestack',
			__( 'Core integrity', 'dicestack' ),
			__( 'Core integrity', 'dicestack' ),
			'manage_options',
			'dicestack-integrity',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Compare core files against the official WordPress.org checksums and look for
	 * modified, missing, and unknown (injected) files. Stores the result.
	 *
	 * @return array The result array.
	 */
	public function scan() {
		global $wp_version;
		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$result = array(
			'issues'  => array(),
			'checked' => time(),
			'version' => $wp_version,
			'ok'      => false,
		);

		if ( ! function_exists( 'get_core_checksums' ) ) {
			update_option( self::OPTION, $result, false );
			return $result;
		}

		$locale    = get_locale();
		$checksums = get_core_checksums( $wp_version, empty( $locale ) ? 'en_US' : $locale );
		if ( ! is_array( $checksums ) ) {
			update_option( self::OPTION, $result, false );
			return $result;
		}

		$issues = array();
		$known  = array();

		// Modified / missing core files.
		foreach ( $checksums as $file => $sum ) {
			$file          = str_replace( '\\', '/', $file );
			$known[ $file ] = true;
			// wp-content is user-owned and legitimately changes — skip it.
			if ( 0 === strpos( $file, 'wp-content/' ) ) {
				continue;
			}
			$path = ABSPATH . $file;
			if ( ! file_exists( $path ) ) {
				$issues[] = array(
					'file'   => $file,
					'status' => 'missing',
				);
			} elseif ( md5_file( $path ) !== $sum ) {
				$issues[] = array(
					'file'   => $file,
					'status' => 'modified',
				);
			}
			if ( count( $issues ) > 200 ) {
				break;
			}
		}

		// Unknown / injected .php files inside the two core directories.
		$abs   = str_replace( '\\', '/', ABSPATH );
		$count = 0;
		$start = time();
		foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
			$root = $abs . $dir;
			if ( ! is_dir( $root ) ) {
				continue;
			}
			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
					\RecursiveIteratorIterator::LEAVES_ONLY
				);
			} catch ( \Exception $e ) {
				continue;
			}
			foreach ( $iterator as $file ) {
				if ( $count >= self::MAX_FILES || ( time() - $start ) >= self::TIME_BUDGET ) {
					break;
				}
				if ( ! $file->isFile() ) {
					continue;
				}
				$ext = strtolower( $file->getExtension() );
				if ( 'php' !== $ext && 'phtml' !== $ext ) {
					continue;
				}
				++$count;
				$rel = str_replace( '\\', '/', $file->getPathname() );
				$rel = 0 === strpos( $rel, $abs ) ? substr( $rel, strlen( $abs ) ) : $rel;
				if ( ! isset( $known[ $rel ] ) ) {
					$issues[] = array(
						'file'   => $rel,
						'status' => 'unknown',
					);
				}
				if ( count( $issues ) > 300 ) {
					break 2;
				}
			}
		}

		$result['issues'] = $issues;
		$result['ok']     = true;
		update_option( self::OPTION, $result, false );
		return $result;
	}

	/**
	 * Handle a manual "Scan now".
	 *
	 * @return void
	 */
	public function handle_scan() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dicestack_integrity_scan' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}
		$this->scan();
		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-integrity&scanned=1' ) );
		exit;
	}

	/**
	 * Warn in the admin when core files are modified or unknown.
	 *
	 * @return void
	 */
	public function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$result = get_option( self::OPTION );
		if ( ! is_array( $result ) || empty( $result['issues'] ) ) {
			return;
		}
		$n   = count( (array) $result['issues'] );
		$url = admin_url( 'admin.php?page=dicestack-integrity' );
		echo '<div class="notice notice-error"><p><strong>DiceStack:</strong> ';
		printf(
			/* translators: %d: number of core-file issues. */
			esc_html( _n( '%d WordPress core file looks modified or injected.', '%d WordPress core files look modified or injected.', $n, 'dicestack' ) ),
			(int) $n
		);
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Review', 'dicestack' ) . '</a></p></div>';
	}

	/**
	 * Render the core integrity page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}

		$result = get_option( self::OPTION );
		$result = is_array( $result ) ? $result : array();
		$issues = isset( $result['issues'] ) && is_array( $result['issues'] ) ? $result['issues'] : array();

		echo '<div class="wrap"><h1>' . esc_html__( 'Core file integrity', 'dicestack' ) . '</h1>';
		echo '<p>' . esc_html__( 'Compares your WordPress core files (wp-admin, wp-includes, and the root) against the official WordPress.org checksums. Modified or unknown core files can be a sign of a hack. Your own themes and plugins in wp-content are checked by the File Scanner instead.', 'dicestack' ) . '</p>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		if ( isset( $_GET['scanned'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Scan complete.', 'dicestack' ) . '</p></div>';
		}

		if ( ! empty( $result['checked'] ) ) {
			echo '<p><strong>' . esc_html__( 'Last checked:', 'dicestack' ) . '</strong> ' . esc_html( gmdate( 'Y-m-d H:i', (int) $result['checked'] ) . ' UTC' );
			if ( ! empty( $result['version'] ) ) {
				echo ' · ' . esc_html( sprintf( /* translators: %s: WordPress version. */ __( 'WordPress %s', 'dicestack' ), (string) $result['version'] ) );
			}
			echo '</p>';
		}

		echo '<p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		wp_nonce_field( 'dicestack_integrity_scan' );
		echo '<input type="hidden" name="action" value="dicestack_integrity_scan" />';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Scan now', 'dicestack' ) . '</button>';
		echo '</form></p>';

		if ( isset( $result['ok'] ) && ! $result['ok'] && ! empty( $result['checked'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Could not fetch the official checksums for your WordPress version (no internet access, or a custom/beta build). Try again later.', 'dicestack' ) . '</p></div>';
		}

		if ( empty( $issues ) ) {
			if ( ! empty( $result['ok'] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html__( 'All core files match the official checksums.', 'dicestack' ) . '</p></div>';
			}
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="margin-top:12px;"><thead><tr>';
		echo '<th>' . esc_html__( 'File', 'dicestack' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'dicestack' ) . '</th>';
		echo '</tr></thead><tbody>';

		$labels = array(
			'modified' => __( 'Modified', 'dicestack' ),
			'missing'  => __( 'Missing', 'dicestack' ),
			'unknown'  => __( 'Unknown (not part of core)', 'dicestack' ),
		);
		foreach ( $issues as $row ) {
			$file   = isset( $row['file'] ) ? (string) $row['file'] : '';
			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			$color  = 'unknown' === $status ? '#b32d2e' : ( 'modified' === $status ? '#b32d2e' : '#996800' );
			echo '<tr>';
			echo '<td><code>' . esc_html( $file ) . '</code></td>';
			echo '<td><strong style="color:' . esc_attr( $color ) . ';">' . esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) . '</strong></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p style="color:#50575e;margin-top:8px;">' . esc_html__( 'Tip: re-installing WordPress from Dashboard → Updates restores modified/missing core files. Investigate any "Unknown" file before deleting it.', 'dicestack' ) . '</p>';
		echo '</div>';
	}
}
