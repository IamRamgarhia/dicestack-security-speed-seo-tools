<?php
/**
 * Database Tools module (Full Edition only).
 *
 * A developer/admin utility: browse tables, optimize them, and run SQL — in the
 * spirit of Adminer / phpMyAdmin / `wp db query`. It runs admin-authored SQL, so
 * it is gated behind manage_options + nonces, defaults to read-only, and requires
 * an explicit per-query opt-in to run write queries. Not shipped on WordPress.org.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Admin;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Table browser + SQL runner.
 */
final class Database_Tools extends Abstract_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'database_tools';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Database tools', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Browse and optimize tables and run SQL queries. For developers.', 'dicestack' );
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
			'php_memory_kb' => 35,
			'front_js_kb'   => 0,
			'front_css_kb'  => 0,
			'db_queries'    => 2,
			'external_http' => 0,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_post_dicestack_db_optimize', array( $this, 'handle_optimize' ) );
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
			__( 'Database tools', 'dicestack' ),
			__( 'Database tools', 'dicestack' ),
			'manage_options',
			'dicestack-db-tools',
			array( $this, 'render_page' )
		);
	}

	/**
	 * The list of real table names in this database — used to validate any table
	 * identifier before it is placed in a query (table names can't be bound).
	 *
	 * @return string[]
	 */
	private function table_names() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- listing tables; no user input.
		$names = $wpdb->get_col( 'SHOW TABLES' );
		return is_array( $names ) ? $names : array();
	}

	/**
	 * Optimize a single, validated table.
	 *
	 * @return void
	 */
	public function handle_optimize() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dicestack_db_optimize' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}
		global $wpdb;
		$table = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';

		// Only proceed if the name is an EXACT match for a real table in this DB.
		if ( '' !== $table && in_array( $table, $this->table_names(), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- table name validated against SHOW TABLES; identifiers cannot be bound.
			$wpdb->query( 'OPTIMIZE TABLE `' . str_replace( '`', '', $table ) . '`' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-db-tools&optimized=1' ) );
		exit;
	}

	/**
	 * Render the tools page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}
		global $wpdb;
		$action = esc_url( admin_url( 'admin-post.php' ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'Database tools', 'dicestack' ) . '</h1>';
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Careful.', 'dicestack' ) . '</strong> ' . esc_html__( 'This runs SQL directly against your database. A wrong query can lose data — back up first.', 'dicestack' ) . '</p></div>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash flag.
		if ( isset( $_GET['optimized'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Table optimized.', 'dicestack' ) . '</p></div>';
		}

		// --- Table browser ---
		echo '<h2>' . esc_html__( 'Tables', 'dicestack' ) . '</h2>';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- table status; no user input.
		$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Table', 'dicestack' ) . '</th><th>' . esc_html__( 'Rows', 'dicestack' ) . '</th><th>' . esc_html__( 'Size', 'dicestack' ) . '</th><th>' . esc_html__( 'Engine', 'dicestack' ) . '</th><th></th></tr></thead><tbody>';
		if ( is_array( $tables ) ) {
			foreach ( $tables as $t ) {
				$size = (int) ( isset( $t['Data_length'] ) ? $t['Data_length'] : 0 ) + (int) ( isset( $t['Index_length'] ) ? $t['Index_length'] : 0 );
				echo '<tr>';
				echo '<td><code>' . esc_html( isset( $t['Name'] ) ? $t['Name'] : '' ) . '</code></td>';
				echo '<td>' . esc_html( number_format_i18n( (int) ( isset( $t['Rows'] ) ? $t['Rows'] : 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( size_format( $size ) ) . '</td>';
				echo '<td>' . esc_html( isset( $t['Engine'] ) ? (string) $t['Engine'] : '' ) . '</td>';
				echo '<td><form method="post" action="' . $action . '" style="margin:0;">';
				wp_nonce_field( 'dicestack_db_optimize' );
				echo '<input type="hidden" name="action" value="dicestack_db_optimize" />';
				echo '<input type="hidden" name="table" value="' . esc_attr( isset( $t['Name'] ) ? $t['Name'] : '' ) . '" />';
				echo '<button class="button button-small">' . esc_html__( 'Optimize', 'dicestack' ) . '</button>';
				echo '</form></td></tr>';
			}
		}
		echo '</tbody></table>';

		// --- SQL runner ---
		echo '<h2 style="margin-top:24px;">' . esc_html__( 'Run SQL', 'dicestack' ) . '</h2>';

		// This form posts to the same page (self) and is handled inline below so
		// results render on the page. Nonce-checked before anything runs.
		$sql        = '';
		$allow_write = false;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified immediately below before any use.
		if ( isset( $_POST['dicestack_sql'] ) && check_admin_referer( 'dicestack_db_query' ) ) {
			$sql         = trim( (string) wp_unslash( $_POST['dicestack_sql'] ) );
			$allow_write = ! empty( $_POST['allow_write'] );
		}

		echo '<form method="post">';
		wp_nonce_field( 'dicestack_db_query' );
		echo '<textarea name="dicestack_sql" rows="5" style="width:100%;max-width:820px;font-family:monospace;">' . esc_textarea( $sql ) . '</textarea>';
		echo '<p><label><input type="checkbox" name="allow_write" value="1" ' . checked( $allow_write, true, false ) . ' /> ' . esc_html__( 'Allow write queries (INSERT / UPDATE / DELETE / ALTER …). Off = read-only.', 'dicestack' ) . '</label></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Run query', 'dicestack' ) . '</button></p>';
		echo '</form>';

		if ( '' !== $sql ) {
			$this->run_sql( $sql, $allow_write );
		}

		echo '</div>';
	}

	/**
	 * Execute a query and render the result. Read-only unless $allow_write.
	 *
	 * @param string $sql          The SQL entered by the admin.
	 * @param bool   $allow_write  Whether non-read statements are permitted.
	 * @return void
	 */
	private function run_sql( $sql, $allow_write ) {
		global $wpdb;
		$first = strtoupper( strtok( ltrim( $sql ), " \t\n(" ) );
		$read  = in_array( $first, array( 'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN' ), true );

		if ( ! $read && ! $allow_write ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That looks like a write query. Tick "Allow write queries" to run it.', 'dicestack' ) . '</p></div>';
			return;
		}

		if ( $read ) {
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery -- admin-authored ad-hoc query tool (Full Edition, manage_options-gated).
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( $wpdb->last_error ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $wpdb->last_error ) . '</p></div>';
				return;
			}
			if ( empty( $rows ) ) {
				echo '<p>' . esc_html__( 'Query ran. No rows returned.', 'dicestack' ) . '</p>';
				return;
			}
			echo '<table class="widefat striped" style="margin-top:12px;"><thead><tr>';
			foreach ( array_keys( $rows[0] ) as $col ) {
				echo '<th>' . esc_html( (string) $col ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr>';
				foreach ( $row as $cell ) {
					echo '<td>' . esc_html( (string) $cell ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
			return;
		}

		// Write query (explicitly opted in).
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery -- admin-authored ad-hoc query tool (Full Edition, manage_options-gated).
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $wpdb->last_error ? $wpdb->last_error : __( 'Query failed.', 'dicestack' ) ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-success"><p>' . esc_html(
			sprintf(
				/* translators: %d: number of affected rows. */
				__( 'Query ran. %d row(s) affected.', 'dicestack' ),
				(int) $result
			)
		) . '</p></div>';
	}
}
