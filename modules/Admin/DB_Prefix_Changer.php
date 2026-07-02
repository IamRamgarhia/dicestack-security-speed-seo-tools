<?php
/**
 * Database Table Prefix Changer module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Admin;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Changes the database table prefix (e.g. wp_ → something custom) for a little
 * extra security-by-obscurity. Renames the tables, rewrites wp-config.php, and
 * fixes the role/capability option and user-meta keys that embed the prefix.
 */
final class DB_Prefix_Changer extends Abstract_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'db_prefix_changer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'DB prefix changer', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Change the database table prefix (wp_) for extra security. Back up first.', 'dicestack' );
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
		return 'database-cog';
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 15,
			'front_js_kb'   => 0,
			'front_css_kb'  => 0,
			'db_queries'    => 0,
			'external_http' => 0,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_dicestack_change_prefix', array( $this, 'handle' ) );
	}

	/**
	 * Register the tool page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page( 'dicestack', __( 'DB prefix', 'dicestack' ), __( 'DB prefix', 'dicestack' ), 'manage_options', 'dicestack-db-prefix', array( $this, 'render_page' ) );
	}

	/**
	 * Render the change-prefix form.
	 *
	 * @return void
	 */
	public function render_page() {
		global $wpdb;
		echo '<div class="wrap"><h1>' . esc_html__( 'Change database prefix', 'dicestack' ) . '</h1>';
		echo '<p>' . esc_html__( 'Current prefix:', 'dicestack' ) . ' <code>' . esc_html( $wpdb->prefix ) . '</code></p>';
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This rewrites wp-config.php and renames every table. Take a full backup first. Do not close the tab while it runs.', 'dicestack' ) . '</p></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'dicestack_change_prefix' );
		echo '<input type="hidden" name="action" value="dicestack_change_prefix" />';
		echo '<table class="form-table"><tr><th>' . esc_html__( 'New prefix', 'dicestack' ) . '</th><td>';
		echo '<input type="text" name="new_prefix" class="regular-text" placeholder="e.g. dsk_" pattern="[A-Za-z0-9_]+" required />';
		echo '<p class="description">' . esc_html__( 'Letters, numbers, and underscores only. A trailing underscore is added automatically.', 'dicestack' ) . '</p></td></tr>';
		echo '<tr><th></th><td><label><input type="checkbox" name="confirm" value="1" required /> ' . esc_html__( 'I have a current backup and understand the risk.', 'dicestack' ) . '</label></td></tr></table>';
		submit_button( __( 'Change prefix', 'dicestack' ) );
		echo '</form></div>';
	}

	/**
	 * Perform the prefix change.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dicestack_change_prefix' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}
		global $wpdb;

		$new = isset( $_POST['new_prefix'] ) ? preg_replace( '/[^A-Za-z0-9_]/', '', sanitize_text_field( wp_unslash( $_POST['new_prefix'] ) ) ) : '';
		$new = rtrim( $new, '_' ) . '_';
		$old = $wpdb->prefix;

		if ( empty( $_POST['confirm'] ) || strlen( $new ) < 2 || $new === $old ) {
			$this->back( 'invalid' );
		}

		// Locate a writable wp-config.php BEFORE touching any tables.
		$config = ABSPATH . 'wp-config.php';
		if ( ! file_exists( $config ) ) {
			$config = dirname( ABSPATH ) . '/wp-config.php';
		}
		if ( ! file_exists( $config ) || ! is_writable( $config ) ) {
			$this->back( 'config' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) . '%' ) );
		if ( empty( $tables ) ) {
			$this->back( 'notables' );
		}

		foreach ( (array) $tables as $table ) {
			$new_table = $new . substr( $table, strlen( $old ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- identifiers validated to [A-Za-z0-9_].
			$wpdb->query( "RENAME TABLE `{$table}` TO `{$new_table}`" );
		}

		// Rewrite wp-config.php's $table_prefix.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $config );
		$contents = preg_replace( '/(\$table_prefix\s*=\s*)([\'"]).*?\2(\s*;)/', '${1}\'' . $new . '\'${3}', $contents, 1 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $config, $contents );

		// Fix the role/capability keys that embed the prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE `{$new}options` SET option_name = %s WHERE option_name = %s", $new . 'user_roles', $old . 'user_roles' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE `{$new}usermeta` SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s", $new, strlen( $old ) + 1, $wpdb->esc_like( $old ) . '%' ) );

		$this->back( 'done' );
	}

	/**
	 * Redirect back to the tool page with a status flag.
	 *
	 * @param string $status Status slug.
	 * @return void
	 */
	private function back( $status ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'dicestack-db-prefix', 'ds_status' => $status ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
