<?php
/**
 * wp-config.php Editor module (Full Edition only).
 *
 * Safely manages a CURATED set of well-known wp-config.php constants (not raw
 * file editing). It writes a backup copy before every change and only ever
 * rewrites the specific define() lines it manages. Gated behind manage_options +
 * nonces. Not shipped on WordPress.org.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Admin;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Toggle known wp-config.php constants from the dashboard.
 */
final class WP_Config_Editor extends Abstract_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'wp_config_editor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'wp-config editor', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Toggle common wp-config.php settings (debug, memory, revisions) safely. For developers.', 'dicestack' );
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
		return 'adjustments';
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 20,
			'front_js_kb'   => 0,
			'front_css_kb'  => 0,
			'db_queries'    => 0,
			'external_http' => 0,
		);
	}

	/**
	 * The curated constants this module manages: key => type (bool|int|string).
	 *
	 * @return array<string,string>
	 */
	private function managed() {
		return array(
			'WP_DEBUG'           => 'bool',
			'WP_DEBUG_LOG'       => 'bool',
			'WP_DEBUG_DISPLAY'   => 'bool',
			'DISALLOW_FILE_EDIT' => 'bool',
			'WP_POST_REVISIONS'  => 'int',
			'AUTOSAVE_INTERVAL'  => 'int',
			'WP_MEMORY_LIMIT'    => 'string',
			'EMPTY_TRASH_DAYS'   => 'int',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_post_dicestack_wpconfig_save', array( $this, 'handle_save' ) );
		}
	}

	/**
	 * Register the page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'dicestack',
			__( 'wp-config editor', 'dicestack' ),
			__( 'wp-config editor', 'dicestack' ),
			'manage_options',
			'dicestack-wp-config',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Locate wp-config.php (standard location, then one level up).
	 *
	 * @return string Path, or '' if not found.
	 */
	private function config_path() {
		$candidates = array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' );
		foreach ( $candidates as $path ) {
			if ( file_exists( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Initialise WP_Filesystem and return it.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private function fs() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem ? $wp_filesystem : null;
	}

	/**
	 * Read the raw wp-config.php contents.
	 *
	 * @return string|null
	 */
	private function read() {
		$path = $this->config_path();
		$fs   = $this->fs();
		if ( '' === $path || ! $fs ) {
			return null;
		}
		$contents = $fs->get_contents( $path );
		return is_string( $contents ) ? $contents : null;
	}

	/**
	 * Parse the current value of a managed constant from the file contents.
	 *
	 * @param string $contents wp-config.php contents.
	 * @param string $const    Constant name.
	 * @param string $type     Value type.
	 * @return mixed Current value, or null if not defined.
	 */
	private function current_value( $contents, $const, $type ) {
		if ( ! preg_match( '/define\(\s*[\'"]' . preg_quote( $const, '/' ) . '[\'"]\s*,\s*(.+?)\s*\)\s*;/', $contents, $m ) ) {
			return null;
		}
		$raw = trim( $m[1] );
		if ( 'bool' === $type ) {
			return ( 'true' === strtolower( $raw ) );
		}
		if ( 'int' === $type ) {
			return (int) $raw;
		}
		return trim( $raw, '"\'' );
	}

	/**
	 * Save the submitted constant values back into wp-config.php.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dicestack_wpconfig_save' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}

		$path     = $this->config_path();
		$fs       = $this->fs();
		$contents = $this->read();
		if ( '' === $path || ! $fs || null === $contents || ! $fs->is_writable( $path ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=dicestack-wp-config&error=1' ) );
			exit;
		}

		// Back up first.
		$fs->put_contents( $path . '.dicestack-bak', $contents, FS_CHMOD_FILE );

		foreach ( $this->managed() as $const => $type ) {
			$field = strtolower( $const );
			if ( 'bool' === $type ) {
				$literal = ! empty( $_POST[ $field ] ) ? 'true' : 'false';
			} elseif ( 'int' === $type ) {
				$literal = (string) ( isset( $_POST[ $field ] ) ? absint( wp_unslash( $_POST[ $field ] ) ) : 0 );
			} else {
				$val     = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
				$literal = "'" . str_replace( "'", '', $val ) . "'";
			}

			$define  = "define( '" . $const . "', " . $literal . ' );';
			$pattern = '/define\(\s*[\'"]' . preg_quote( $const, '/' ) . '[\'"]\s*,\s*.+?\s*\)\s*;/';

			if ( preg_match( $pattern, $contents ) ) {
				$contents = preg_replace( $pattern, $define, $contents, 1 );
			} else {
				// Insert before the "stop editing" marker, else after the <?php line.
				if ( false !== strpos( $contents, "That's all, stop editing" ) ) {
					$contents = preg_replace( "/(\/\* That's all, stop editing)/", $define . "\n\n$1", $contents, 1 );
				} else {
					$contents = preg_replace( '/^(<\?php\s*)/', "$1\n" . $define . "\n", $contents, 1 );
				}
			}
		}

		$fs->put_contents( $path, $contents, FS_CHMOD_FILE );
		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-wp-config&saved=1' ) );
		exit;
	}

	/**
	 * Render the editor page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'wp-config editor', 'dicestack' ) . '</h1>';
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Careful.', 'dicestack' ) . '</strong> ' . esc_html__( 'These settings live in wp-config.php. A backup (wp-config.php.dicestack-bak) is written before every save.', 'dicestack' ) . '</p></div>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash flags.
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved to wp-config.php.', 'dicestack' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash flags.
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Could not write to wp-config.php (not found or not writable).', 'dicestack' ) . '</p></div>';
		}

		$contents = $this->read();
		if ( null === $contents ) {
			echo '<p>' . esc_html__( 'wp-config.php could not be read on this server.', 'dicestack' ) . '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dicestack_wpconfig_save' );
		echo '<input type="hidden" name="action" value="dicestack_wpconfig_save" />';
		echo '<table class="form-table"><tbody>';

		foreach ( $this->managed() as $const => $type ) {
			$field   = strtolower( $const );
			$current = $this->current_value( $contents, $const, $type );
			echo '<tr><th scope="row"><code>' . esc_html( $const ) . '</code></th><td>';
			if ( 'bool' === $type ) {
				echo '<label><input type="checkbox" name="' . esc_attr( $field ) . '" value="1" ' . checked( (bool) $current, true, false ) . ' /> ' . esc_html__( 'Enabled', 'dicestack' ) . '</label>';
			} elseif ( 'int' === $type ) {
				echo '<input type="number" name="' . esc_attr( $field ) . '" value="' . esc_attr( null === $current ? '' : (string) $current ) . '" class="small-text" />';
			} else {
				echo '<input type="text" name="' . esc_attr( $field ) . '" value="' . esc_attr( null === $current ? '' : (string) $current ) . '" class="regular-text" placeholder="256M" />';
			}
			if ( null === $current ) {
				echo ' <span style="color:#8a8a8a;">' . esc_html__( '(not currently defined)', 'dicestack' ) . '</span>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save to wp-config.php', 'dicestack' ) . '</button></p>';
		echo '</form></div>';
	}
}
