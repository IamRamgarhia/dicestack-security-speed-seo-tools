<?php
/**
 * Plugin Name:       DiceStack (Full Edition)
 * Plugin URI:        https://dicecodes.com/dicestack-wordpress-plugin/
 * Update URI:        https://github.com/IamRamgarhia/DiceStack-free-all-in-one-WordPress-plugin
 * Description:       One plugin. Every tool. Always free. The full GitHub edition — 200+ modular tools for security, performance, SEO, forms, WooCommerce, and site management, including the power tools not on WordPress.org. By Dice Codes.
 * Version:           1.6.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Dice Codes
 * Author URI:        https://dicecodes.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dicestack
 * Domain Path:       /languages
 *
 * @package DiceStack
 * @author  Dice Codes <Contact@dicecodes.com>
 * @link    https://dicecodes.com
 */

namespace DiceStack;

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------------
 */
define( 'DICESTACK_VERSION', '1.6.4' );
define( 'DICESTACK_FILE', __FILE__ );
define( 'DICESTACK_PATH', plugin_dir_path( __FILE__ ) );
define( 'DICESTACK_URL', plugin_dir_url( __FILE__ ) );
define( 'DICESTACK_BASENAME', plugin_basename( __FILE__ ) );
// Identifies this as the full GitHub build in the UI. The WordPress.org build
// does not define this, so shared views can show the edition only where it applies.
define( 'DICESTACK_EDITION', 'Full Edition' );

/*
 * ---------------------------------------------------------------------------
 * Autoloader
 *
 * Maps the DiceStack\ namespace to /includes and /modules. We intentionally avoid
 * Composer's vendor autoloader so the directory reviewers see only first-party,
 * human-readable code (WordPress.org guideline: no bundled libraries we don't
 * need, no obfuscation).
 * ---------------------------------------------------------------------------
 */
spl_autoload_register(
	static function ( $class ) {
		if ( strpos( $class, 'DiceStack\\' ) !== 0 ) {
			return;
		}

		// Strip the root namespace.
		$relative = substr( $class, strlen( 'DiceStack\\' ) );

		// Modules live under /modules; everything else under /includes.
		if ( strpos( $relative, 'Modules\\' ) === 0 ) {
			$relative = substr( $relative, strlen( 'Modules\\' ) );
			$base     = DICESTACK_PATH . 'modules/';
		} else {
			$base = DICESTACK_PATH . 'includes/';
		}

		$path = $base . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/*
 * ---------------------------------------------------------------------------
 * Lifecycle hooks
 * ---------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, array( '\DiceStack\Core', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( '\DiceStack\Core', 'on_deactivate' ) );

/*
 * ---------------------------------------------------------------------------
 * Boot
 *
 * Priority 1 on plugins_loaded so security/performance modules can hook early.
 * ---------------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	static function () {
		Core::instance()->boot();
	},
	1
);
