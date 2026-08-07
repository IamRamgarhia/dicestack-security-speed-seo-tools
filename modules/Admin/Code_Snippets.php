<?php
/**
 * Code Snippets module (Full Edition only).
 *
 * A developer tool that stores and runs small PHP snippets, in the same spirit
 * as the popular "Code Snippets" plugin. It executes admin-authored PHP, so it
 * is deliberately gated:
 *   - only users with BOTH manage_options AND unfiltered_html may create/edit
 *     snippets (on multisite, unfiltered_html is super-admin only);
 *   - every snippet runs inside try/catch and is auto-disabled if it throws, so
 *     a bad snippet can never white-screen the site;
 *   - the feature is not shipped on the WordPress.org build.
 * Define DICESTACK_DISABLE_SNIPPETS as true in wp-config.php to hard-off execution.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Admin;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Store, manage, and run admin-authored PHP snippets.
 */
final class Code_Snippets extends Abstract_Module {

	const OPTION = 'dicestack_snippets';
	const ERRORS = 'dicestack_snippet_errors';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'code_snippets';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Code snippets', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Run small PHP snippets from the dashboard instead of editing theme files. For developers.', 'dicestack' );
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
		return 'code';
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 40,
			'front_js_kb'   => 0,
			'front_css_kb'  => 0,
			'db_queries'    => 1,
			'external_http' => 0,
		);
	}

	/**
	 * Can the current user manage snippets? Requires the two highest content caps.
	 *
	 * @return bool
	 */
	private function user_can_manage() {
		return current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		// Run active snippets (unless hard-disabled via constant).
		if ( ! ( defined( 'DICESTACK_DISABLE_SNIPPETS' ) && DICESTACK_DISABLE_SNIPPETS ) ) {
			add_action( 'init', array( $this, 'run_active' ), 5 );
		}

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_post_dicestack_snippet_save', array( $this, 'handle_save' ) );
			add_action( 'admin_post_dicestack_snippet_delete', array( $this, 'handle_delete' ) );
			add_action( 'admin_post_dicestack_snippet_toggle', array( $this, 'handle_toggle' ) );
		}
	}

	/**
	 * All stored snippets.
	 *
	 * @return array[]
	 */
	private function snippets() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Execute every active snippet whose scope matches the current context. Each
	 * runs inside try/catch; one that throws is auto-disabled so it can't recur.
	 *
	 * @return void
	 */
	public function run_active() {
		$snippets = $this->snippets();
		if ( empty( $snippets ) ) {
			return;
		}
		$is_admin = is_admin();
		$changed  = false;

		foreach ( $snippets as $index => $snippet ) {
			if ( empty( $snippet['active'] ) || empty( $snippet['code'] ) ) {
				continue;
			}
			$scope = isset( $snippet['scope'] ) ? $snippet['scope'] : 'everywhere';
			if ( ( 'admin' === $scope && ! $is_admin ) || ( 'front' === $scope && $is_admin ) ) {
				continue;
			}

			try {
				// Executes admin-authored PHP (gated by unfiltered_html at save time),
				// exactly like the Code Snippets plugin. Wrapped so a fatal in a
				// snippet auto-disables it instead of taking down the site.
				eval( $snippet['code'] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- intentional snippet runner, admin-gated.
			} catch ( \Throwable $e ) {
				$snippets[ $index ]['active'] = false;
				$changed                      = true;
				$errors                       = get_option( self::ERRORS, array() );
				$errors                       = is_array( $errors ) ? $errors : array();
				$errors[ $snippet['id'] ]     = $e->getMessage();
				update_option( self::ERRORS, $errors, false );
			}
		}

		if ( $changed ) {
			update_option( self::OPTION, $snippets, false );
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
			__( 'Code snippets', 'dicestack' ),
			__( 'Code snippets', 'dicestack' ),
			'manage_options',
			'dicestack-snippets',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Save (add or update) a snippet.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! $this->user_can_manage() || ! check_admin_referer( 'dicestack_snippet_save' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}
		$snippets = $this->snippets();
		$id       = isset( $_POST['snippet_id'] ) ? sanitize_key( wp_unslash( $_POST['snippet_id'] ) ) : '';
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		// Raw PHP body is intentionally NOT sanitised (it IS code); only unslashed.
		$code   = isset( $_POST['code'] ) ? (string) wp_unslash( $_POST['code'] ) : '';
		$code   = preg_replace( '/^\s*<\?php/', '', trim( $code ) ); // Tolerate a pasted opening tag.
		$scope  = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'everywhere';
		$scope  = in_array( $scope, array( 'everywhere', 'admin', 'front' ), true ) ? $scope : 'everywhere';
		$active = ! empty( $_POST['active'] );

		$entry = array(
			'id'     => '' !== $id ? $id : uniqid( 's', false ),
			'title'  => '' !== $title ? $title : __( 'Untitled snippet', 'dicestack' ),
			'code'   => $code,
			'scope'  => $scope,
			'active' => $active,
		);

		$found = false;
		foreach ( $snippets as $i => $s ) {
			if ( isset( $s['id'] ) && $s['id'] === $entry['id'] ) {
				$snippets[ $i ] = $entry;
				$found          = true;
				break;
			}
		}
		if ( ! $found ) {
			$snippets[] = $entry;
		}
		update_option( self::OPTION, $snippets, false );
		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-snippets&saved=1' ) );
		exit;
	}

	/**
	 * Delete a snippet.
	 *
	 * @return void
	 */
	public function handle_delete() {
		if ( ! $this->user_can_manage() || ! check_admin_referer( 'dicestack_snippet_delete' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}
		$id       = isset( $_POST['snippet_id'] ) ? sanitize_key( wp_unslash( $_POST['snippet_id'] ) ) : '';
		$snippets = array_values(
			array_filter(
				$this->snippets(),
				static function ( $s ) use ( $id ) {
					return ! ( isset( $s['id'] ) && $s['id'] === $id );
				}
			)
		);
		update_option( self::OPTION, $snippets, false );
		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-snippets&deleted=1' ) );
		exit;
	}

	/**
	 * Toggle a snippet active/inactive.
	 *
	 * @return void
	 */
	public function handle_toggle() {
		if ( ! $this->user_can_manage() || ! check_admin_referer( 'dicestack_snippet_toggle' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}
		$id       = isset( $_POST['snippet_id'] ) ? sanitize_key( wp_unslash( $_POST['snippet_id'] ) ) : '';
		$snippets = $this->snippets();
		foreach ( $snippets as $i => $s ) {
			if ( isset( $s['id'] ) && $s['id'] === $id ) {
				$snippets[ $i ]['active'] = empty( $s['active'] );
				break;
			}
		}
		update_option( self::OPTION, $snippets, false );
		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-snippets&toggled=1' ) );
		exit;
	}

	/**
	 * Render the snippets page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}

		$can_manage = $this->user_can_manage();
		$snippets   = $this->snippets();
		$action     = esc_url( admin_url( 'admin-post.php' ) );

		echo '<div class="wrap"><h1>' . esc_html__( 'Code snippets', 'dicestack' ) . '</h1>';

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Danger zone.', 'dicestack' ) . '</strong> ' . esc_html__( 'Snippets run real PHP on your site. A mistake can break it. Only site owners should use this; each snippet is auto-disabled if it errors.', 'dicestack' ) . '</p></div>';

		if ( ! $can_manage ) {
			echo '<p>' . esc_html__( 'You need the unfiltered_html and manage_options capabilities to add or edit snippets.', 'dicestack' ) . '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash flag.
		if ( isset( $_GET['saved'] ) || isset( $_GET['deleted'] ) || isset( $_GET['toggled'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Snippets updated.', 'dicestack' ) . '</p></div>';
		}

		// Existing snippets.
		echo '<h2>' . esc_html__( 'Your snippets', 'dicestack' ) . '</h2>';
		if ( empty( $snippets ) ) {
			echo '<p>' . esc_html__( 'No snippets yet.', 'dicestack' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Title', 'dicestack' ) . '</th><th>' . esc_html__( 'Scope', 'dicestack' ) . '</th><th>' . esc_html__( 'Status', 'dicestack' ) . '</th><th>' . esc_html__( 'Actions', 'dicestack' ) . '</th></tr></thead><tbody>';
			foreach ( $snippets as $s ) {
				$sid = isset( $s['id'] ) ? $s['id'] : '';
				echo '<tr>';
				echo '<td>' . esc_html( isset( $s['title'] ) ? $s['title'] : '' ) . '</td>';
				echo '<td>' . esc_html( isset( $s['scope'] ) ? $s['scope'] : 'everywhere' ) . '</td>';
				echo '<td>' . ( ! empty( $s['active'] ) ? esc_html__( 'Active', 'dicestack' ) : esc_html__( 'Inactive', 'dicestack' ) ) . '</td>';
				echo '<td style="display:flex;gap:6px;">';
				// Toggle.
				echo '<form method="post" action="' . $action . '" style="display:inline;">';
				wp_nonce_field( 'dicestack_snippet_toggle' );
				echo '<input type="hidden" name="action" value="dicestack_snippet_toggle" />';
				echo '<input type="hidden" name="snippet_id" value="' . esc_attr( $sid ) . '" />';
				echo '<button class="button button-small">' . ( ! empty( $s['active'] ) ? esc_html__( 'Disable', 'dicestack' ) : esc_html__( 'Enable', 'dicestack' ) ) . '</button>';
				echo '</form>';
				// Delete.
				echo '<form method="post" action="' . $action . '" style="display:inline;" onsubmit="return confirm(\'' . esc_js( __( 'Delete this snippet?', 'dicestack' ) ) . '\');">';
				wp_nonce_field( 'dicestack_snippet_delete' );
				echo '<input type="hidden" name="action" value="dicestack_snippet_delete" />';
				echo '<input type="hidden" name="snippet_id" value="' . esc_attr( $sid ) . '" />';
				echo '<button class="button button-small">' . esc_html__( 'Delete', 'dicestack' ) . '</button>';
				echo '</form>';
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// Add new.
		echo '<h2 style="margin-top:24px;">' . esc_html__( 'Add a snippet', 'dicestack' ) . '</h2>';
		echo '<form method="post" action="' . $action . '">';
		wp_nonce_field( 'dicestack_snippet_save' );
		echo '<input type="hidden" name="action" value="dicestack_snippet_save" />';
		echo '<p><label>' . esc_html__( 'Title', 'dicestack' ) . '<br><input type="text" name="title" class="regular-text" /></label></p>';
		echo '<p><label>' . esc_html__( 'PHP code (no opening <?php tag needed)', 'dicestack' ) . '<br><textarea name="code" rows="10" style="width:100%;max-width:820px;font-family:monospace;"></textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Run', 'dicestack' ) . ' <select name="scope"><option value="everywhere">' . esc_html__( 'Everywhere', 'dicestack' ) . '</option><option value="admin">' . esc_html__( 'Admin only', 'dicestack' ) . '</option><option value="front">' . esc_html__( 'Front-end only', 'dicestack' ) . '</option></select></label></p>';
		echo '<p><label><input type="checkbox" name="active" value="1" /> ' . esc_html__( 'Active', 'dicestack' ) . '</label></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save snippet', 'dicestack' ) . '</button></p>';
		echo '</form>';
		echo '</div>';
	}
}
