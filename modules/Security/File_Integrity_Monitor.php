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
 * flags any that have been modified, added, or are missing — an early sign of a
 * compromise. Replaces the core-scan feature of Wordfence.
 */
final class File_Integrity_Monitor extends Abstract_Module {

	const OPTION = 'dicestack_fim_result';
	const CRON   = 'dicestack_fim_scan';

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
		return __( 'Check WordPress core files against official checksums and warn if any were modified.', 'dicestack' );
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
			'php_memory_kb' => 20,
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
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Compare core files against WordPress.org checksums.
	 *
	 * @return void
	 */
	public function scan() {
		global $wp_version;
		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_core_checksums' ) ) {
			return;
		}
		$locale    = get_locale();
		$checksums = get_core_checksums( $wp_version, empty( $locale ) ? 'en_US' : $locale );
		if ( ! is_array( $checksums ) ) {
			return;
		}

		$modified = array();
		foreach ( $checksums as $file => $sum ) {
			// wp-content is user-owned and legitimately changes — skip it.
			if ( 0 === strpos( $file, 'wp-content/' ) ) {
				continue;
			}
			$path = ABSPATH . $file;
			if ( ! file_exists( $path ) ) {
				$modified[] = $file . ' (' . __( 'missing', 'dicestack' ) . ')';
				continue;
			}
			if ( md5_file( $path ) !== $sum ) {
				$modified[] = $file . ' (' . __( 'changed', 'dicestack' ) . ')';
			}
			if ( count( $modified ) > 50 ) {
				break;
			}
		}

		update_option(
			self::OPTION,
			array(
				'modified' => $modified,
				'checked'  => time(),
				'version'  => $wp_version,
			)
		);
	}

	/**
	 * Warn in the admin when core files have been modified.
	 *
	 * @return void
	 */
	public function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$result = get_option( self::OPTION );
		if ( ! is_array( $result ) || empty( $result['modified'] ) ) {
			return;
		}
		$files = array_map( 'esc_html', array_slice( (array) $result['modified'], 0, 10 ) );
		echo '<div class="notice notice-error"><p><strong>DiceStack:</strong> ';
		printf(
			/* translators: %d: number of modified core files. */
			esc_html( _n( '%d WordPress core file does not match the official checksum:', '%d WordPress core files do not match the official checksums:', count( (array) $result['modified'] ), 'dicestack' ) ),
			count( (array) $result['modified'] )
		);
		echo '</p><p><code>' . wp_kses_post( implode( '</code>, <code>', $files ) ) . '</code></p></div>';
	}
}
