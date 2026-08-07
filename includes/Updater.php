<?php
/**
 * GitHub self-updater for the DiceStack Full Edition.
 *
 * The Full Edition is distributed from GitHub, not WordPress.org, and carries an
 * "Update URI: https://github.com/..." header. WordPress therefore never offers
 * .org updates for it and instead fires the update_plugins_github.com filter,
 * which this class answers using the repository's GitHub Releases.
 *
 * @package DiceStack
 */

namespace DiceStack;

defined( 'ABSPATH' ) || exit;

/**
 * Checks GitHub Releases for a newer version and feeds it into WordPress' native
 * plugin-update flow (update notice + one-click update + "View details" popup).
 */
final class Updater {

	/**
	 * owner/repo on GitHub. Must match the Update URI header host + path.
	 */
	const REPO = 'IamRamgarhia/DiceStack-free-all-in-one-WordPress-plugin';

	/**
	 * Transient key for the cached latest-release lookup.
	 */
	const CACHE_KEY = 'dicestack_gh_latest';

	/**
	 * Register update hooks.
	 *
	 * @return void
	 */
	public function init() {
		// WordPress calls this filter for plugins whose Update URI host is github.com.
		add_filter( 'update_plugins_github.com', array( $this, 'check_for_update' ), 10, 3 );
		// "View details" / "View version x.y.z details" popup.
		add_filter( 'plugins_api', array( $this, 'plugin_details' ), 10, 3 );
		// GitHub zips extract to "owner-repo-<sha>/"; rename to the real plugin folder.
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		// Refresh the cache when the user clicks "Check again".
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 0 );
		// On the Updates screen's "Check Again" (force-check), drop our cached
		// GitHub result so the check that follows hits GitHub fresh.
		add_action( 'load-update-core.php', array( $this, 'maybe_force_check' ) );
	}

	/**
	 * Clear the cached release when WordPress is doing a forced update check.
	 *
	 * @return void
	 */
	public function maybe_force_check() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core's own read-only force-check flag.
		if ( isset( $_GET['force-check'] ) ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Answer WordPress' update check with data from the latest GitHub release.
	 *
	 * @param array|false $update      The update offer (false if none).
	 * @param array       $plugin_data The plugin's header data.
	 * @param string      $plugin_file The plugin basename (e.g. dicestack/dicestack.php).
	 * @return array|false
	 */
	public function check_for_update( $update, $plugin_data, $plugin_file ) {
		if ( DICESTACK_BASENAME !== $plugin_file ) {
			return $update;
		}

		$release = $this->latest_release();
		if ( empty( $release['version'] ) ) {
			return $update;
		}

		if ( version_compare( $release['version'], DICESTACK_VERSION, '<=' ) ) {
			return $update;
		}

		$icon = DICESTACK_URL . 'assets/img/icon-256x256.png';

		return array(
			'slug'        => dirname( DICESTACK_BASENAME ),
			'version'     => $release['version'],
			'url'         => isset( $plugin_data['PluginURI'] ) ? $plugin_data['PluginURI'] : '',
			'package'     => $release['package'],
			'tested'      => isset( $plugin_data['Tested up to'] ) ? $plugin_data['Tested up to'] : '',
			'requires'    => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
			'icons'       => array(
				'1x'      => $icon,
				'2x'      => $icon,
				'default' => $icon,
			),
		);
	}

	/**
	 * Provide the "View details" modal content.
	 *
	 * @param false|object|array $result The result object/array.
	 * @param string             $action The API action.
	 * @param object             $args   Request args.
	 * @return false|object
	 */
	public function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( DICESTACK_BASENAME ) !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( empty( $release['version'] ) ) {
			return $result;
		}

		$info               = new \stdClass();
		$info->name         = 'DiceStack (Full Edition)';
		$info->slug         = dirname( DICESTACK_BASENAME );
		$info->version      = $release['version'];
		$info->author       = '<a href="https://dicecodes.com">Dice Codes</a>';
		$info->homepage     = 'https://dicecodes.com/dicestack-wordpress-plugin/';
		$info->download_link = $release['package'];
		$icon               = DICESTACK_URL . 'assets/img/icon-256x256.png';
		$info->icons        = array(
			'1x'      => $icon,
			'2x'      => $icon,
			'default' => $icon,
		);
		$info->sections     = array(
			'description' => esc_html__( 'The full GitHub edition of DiceStack, including the power tools not available on WordPress.org.', 'dicestack' ),
			'changelog'   => '<pre style="white-space:pre-wrap;">' . esc_html( (string) $release['body'] ) . '</pre>',
		);
		return $info;
	}

	/**
	 * GitHub source zips extract to "owner-repo-<sha>/". Rename that to the real
	 * plugin directory so WordPress updates in place instead of creating a copy.
	 *
	 * @param string $source        Extracted source path.
	 * @param string $remote_source Remote source path.
	 * @param object $upgrader       The upgrader instance.
	 * @param array  $hook_extra     Extra args (contains the plugin file).
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || DICESTACK_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . dirname( DICESTACK_BASENAME );
		if ( untrailingslashit( $source ) === $desired ) {
			return $source;
		}

		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), $desired, true ) ) {
			return trailingslashit( $desired );
		}
		return $source;
	}

	/**
	 * Flush the cached release so the next check hits GitHub again.
	 *
	 * @return void
	 */
	public function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Fetch (and cache) the latest GitHub release for this repository.
	 *
	 * @return array{version:string,package:string,body:string}|array
	 */
	private function latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'DiceStack-Updater',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the miss briefly so a broken/rate-limited API doesn't hammer.
			set_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
			return array();
		}

		// Prefer an uploaded .zip asset; fall back to the auto-generated source zip.
		$package = isset( $data['zipball_url'] ) ? $data['zipball_url'] : '';
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( isset( $asset['browser_download_url'] ) && substr( $asset['browser_download_url'], -4 ) === '.zip' ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
		}

		$release = array(
			'version' => ltrim( (string) $data['tag_name'], 'vV' ),
			'package' => (string) $package,
			'body'    => isset( $data['body'] ) ? (string) $data['body'] : '',
		);

		set_transient( self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}
}
