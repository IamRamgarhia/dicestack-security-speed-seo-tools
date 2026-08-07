<?php
/**
 * File Scanner module — malware & hacked-file finder.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Security;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Scans wp-content for PHP files that contain signatures commonly seen in
 * malware, web shells, and compromised files, so the owner can quickly find
 * and review anything suspicious after a breach.
 *
 * Signature matches are heuristics — legitimate code can use the same
 * functions — so every result must be reviewed by a human before deleting.
 */
final class File_Scanner extends Abstract_Module {

	/**
	 * Option storing the last scan results and timestamp.
	 */
	const OPTION = 'dicestack_file_scan';

	/**
	 * Maximum number of PHP files to examine in a single scan.
	 */
	const MAX_FILES = 3000;

	/**
	 * Soft time budget (seconds) after which the scan stops early.
	 */
	const TIME_BUDGET = 20;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'file_scanner';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'File scanner', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Scan wp-content for hacked or malware PHP files. Matches are hints, not proof — review flagged files manually.', 'dicestack' );
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
		return 'shield-search';
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
			'php_memory_kb' => 60,
			'front_js_kb'   => 0,
			'front_css_kb'  => 0,
			'db_queries'    => 0,
			'external_http' => 0,
		);
	}

	/**
	 * High-severity signatures: signature name => regular expression.
	 *
	 * @return array<string,string>
	 */
	private function high_signatures() {
		return array(
			'eval()'                 => '/eval\s*\(/i',
			'base64_decode()'        => '/base64_decode\s*\(/i',
			'gzinflate()'            => '/gzinflate\s*\(/i',
			'gzuncompress()'         => '/gzuncompress\s*\(/i',
			'str_rot13()'            => '/str_rot13\s*\(/i',
			'shell_exec()'           => '/shell_exec\s*\(/i',
			'system()'               => '/\bsystem\s*\(/i',
			'passthru()'             => '/passthru\s*\(/i',
			'exec()'                 => '/\bexec\s*\(/i',
			'preg_replace /e'        => '/preg_replace\s*\(\s*[\'"].*?\/e/i',
			'assert()'               => '/assert\s*\(/i',
			'create_function()'      => '/create_function\s*\(/i',
			'superglobal call'       => '/\$_(POST|GET|REQUEST|COOKIE)\s*\[[^\]]*\]\s*\(/i',
			'move_uploaded_file()'   => '/move_uploaded_file/i',
			'known shell marker'     => '/FilesMan|WSO |b374k|c99shell/i',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_post_dicestack_file_scan', array( $this, 'handle_file_scan' ) );
			add_action( 'admin_post_dicestack_file_scan_clear', array( $this, 'handle_clear' ) );
		}
	}

	/**
	 * Register the scanner page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'dicestack',
			__( 'File scanner', 'dicestack' ),
			__( 'File scanner', 'dicestack' ),
			'manage_options',
			'dicestack-file-scanner',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Absolute path to the DiceStack plugin directory, so we can skip our own
	 * files (which legitimately contain scanner signatures) and avoid
	 * self-flagging.
	 *
	 * @return string
	 */
	private function self_dir() {
		if ( defined( 'DICESTACK_PATH' ) ) {
			$dir = rtrim( str_replace( '\\', '/', dirname( DICESTACK_PATH ) ), '/' );
			if ( '' !== $dir ) {
				return $dir . '/';
			}
		}
		return rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/plugins/dicestack/';
	}

	/**
	 * Run the scan and return the results array.
	 *
	 * @return array{files:array<int,array<string,mixed>>,scanned:int,truncated:bool,time:int}
	 */
	private function run_scan() {
		$results   = array();
		$scanned   = 0;
		$truncated = false;
		$self      = $this->self_dir();
		$start     = time();

		$root    = str_replace( '\\', '/', WP_CONTENT_DIR );
		$high    = $this->high_signatures();
		$uploads = wp_get_upload_dir();
		$up_dir  = isset( $uploads['basedir'] ) ? rtrim( str_replace( '\\', '/', $uploads['basedir'] ), '/' ) . '/' : '';

		if ( ! is_dir( $root ) ) {
			return array(
				'files'     => array(),
				'scanned'   => 0,
				'truncated' => false,
				'time'      => time(),
			);
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
		} catch ( \Exception $e ) {
			return array(
				'files'     => array(),
				'scanned'   => 0,
				'truncated' => false,
				'time'      => time(),
			);
		}

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$path = str_replace( '\\', '/', $file->getPathname() );

			// Skip DiceStack's own files (they contain the scanner signatures).
			if ( 0 === strpos( $path, $self ) ) {
				continue;
			}

			if ( $scanned >= self::MAX_FILES || ( time() - $start ) >= self::TIME_BUDGET ) {
				$truncated = true;
				break;
			}
			++$scanned;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local file to scan its contents for malware signatures; WP_Filesystem is unnecessary for read-only local reads here.
			$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable files are simply skipped.
			if ( false === $contents || '' === $contents ) {
				continue;
			}

			$matched  = array();
			$severity = '';

			foreach ( $high as $label => $pattern ) {
				if ( preg_match( $pattern, $contents ) ) {
					$matched[] = $label;
					$severity  = 'high';
				}
			}

			// Medium: any .php file living inside the uploads directory is
			// suspicious regardless of contents (uploads should not run PHP).
			if ( '' !== $up_dir && 0 === strpos( $path, $up_dir ) ) {
				if ( '' === $severity ) {
					$severity = 'medium';
				}
				$matched[] = __( 'PHP file inside uploads directory', 'dicestack' );
			}

			if ( empty( $matched ) ) {
				continue;
			}

			$results[] = array(
				'path'      => $this->relative_path( $path ),
				'matches'   => $matched,
				'severity'  => $severity,
				'modified'  => (int) $file->getMTime(),
			);
		}

		// Sort high severity first.
		usort(
			$results,
			static function ( $a, $b ) {
				$rank = array(
					'high'   => 0,
					'medium' => 1,
				);
				$ra = isset( $rank[ $a['severity'] ] ) ? $rank[ $a['severity'] ] : 2;
				$rb = isset( $rank[ $b['severity'] ] ) ? $rank[ $b['severity'] ] : 2;
				if ( $ra === $rb ) {
					return $b['modified'] <=> $a['modified'];
				}
				return $ra <=> $rb;
			}
		);

		return array(
			'files'     => $results,
			'scanned'   => $scanned,
			'truncated' => $truncated,
			'time'      => time(),
		);
	}

	/**
	 * Make an absolute path relative to ABSPATH for display.
	 *
	 * @param string $path Absolute path (forward slashes).
	 * @return string
	 */
	private function relative_path( $path ) {
		$base = str_replace( '\\', '/', ABSPATH );
		if ( 0 === strpos( $path, $base ) ) {
			return substr( $path, strlen( $base ) );
		}
		return $path;
	}

	/**
	 * Handle the "Scan now" action.
	 *
	 * @return void
	 */
	public function handle_file_scan() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dicestack_file_scan' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}
		$results = $this->run_scan();
		update_option( self::OPTION, $results, false );
		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-file-scanner&scanned=1' ) );
		exit;
	}

	/**
	 * Handle the "Clear results" action.
	 *
	 * @return void
	 */
	public function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dicestack_file_scan_clear' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}
		delete_option( self::OPTION );
		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-file-scanner&cleared=1' ) );
		exit;
	}

	/**
	 * Render the scanner admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$files  = isset( $stored['files'] ) && is_array( $stored['files'] ) ? $stored['files'] : array();

		echo '<div class="wrap"><h1>' . esc_html__( 'File scanner', 'dicestack' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		if ( isset( $_GET['scanned'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Scan complete.', 'dicestack' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		if ( isset( $_GET['cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Results cleared.', 'dicestack' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Scans wp-content for PHP files containing signatures often found in malware and web shells. These matches are hints only — legitimate plugins and themes can use the same functions. Always review a flagged file yourself before deleting it.', 'dicestack' ) . '</p>';

		if ( ! empty( $stored['time'] ) ) {
			echo '<p><strong>' . esc_html__( 'Last scan:', 'dicestack' ) . '</strong> ' . esc_html( gmdate( 'Y-m-d H:i', (int) $stored['time'] ) . ' UTC' );
			if ( isset( $stored['scanned'] ) ) {
				echo ' · ' . esc_html( sprintf( /* translators: %d: file count. */ __( '%d PHP files scanned', 'dicestack' ), (int) $stored['scanned'] ) );
			}
			echo '</p>';
			if ( ! empty( $stored['truncated'] ) ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( /* translators: 1: file cap, 2: seconds. */ __( 'Scan stopped early after reaching the safety limit (%1$d files or %2$d seconds). Some files were not scanned.', 'dicestack' ), self::MAX_FILES, self::TIME_BUDGET ) ) . '</p></div>';
			}
		}

		// Action buttons.
		echo '<p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
		wp_nonce_field( 'dicestack_file_scan' );
		echo '<input type="hidden" name="action" value="dicestack_file_scan" />';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Scan now', 'dicestack' ) . '</button>';
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
		wp_nonce_field( 'dicestack_file_scan_clear' );
		echo '<input type="hidden" name="action" value="dicestack_file_scan_clear" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Clear results', 'dicestack' ) . '</button>';
		echo '</form>';
		echo '</p>';

		if ( empty( $files ) ) {
			if ( ! empty( $stored['time'] ) ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html__( 'No suspicious files were found in the last scan.', 'dicestack' ) . '</p></div>';
			}
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="margin-top:12px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'File', 'dicestack' ) . '</th>';
		echo '<th>' . esc_html__( 'Matched signatures', 'dicestack' ) . '</th>';
		echo '<th>' . esc_html__( 'Severity', 'dicestack' ) . '</th>';
		echo '<th>' . esc_html__( 'Last modified', 'dicestack' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $files as $row ) {
			$path     = isset( $row['path'] ) ? (string) $row['path'] : '';
			$matches  = isset( $row['matches'] ) && is_array( $row['matches'] ) ? $row['matches'] : array();
			$severity = isset( $row['severity'] ) ? (string) $row['severity'] : '';
			$modified = isset( $row['modified'] ) ? (int) $row['modified'] : 0;

			$color = 'high' === $severity ? '#b32d2e' : '#996800';

			echo '<tr>';
			echo '<td><code>' . esc_html( $path ) . '</code></td>';
			echo '<td>' . esc_html( implode( ', ', array_map( 'strval', $matches ) ) ) . '</td>';
			echo '<td><strong style="color:' . esc_attr( $color ) . ';">' . esc_html( strtoupper( $severity ) ) . '</strong></td>';
			echo '<td>' . esc_html( $modified ? gmdate( 'Y-m-d H:i', $modified ) : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
