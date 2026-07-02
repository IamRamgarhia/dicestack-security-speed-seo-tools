<?php
/**
 * Mixed Content Fixer module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Security;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites insecure http:// references to the site's own domain into https://
 * in the page output, so browsers stop reporting "mixed content" / "not fully
 * secure". Replaces the core job of Really Simple SSL. Only the site's own
 * domain is upgraded — external http URLs are left alone (they may lack HTTPS).
 */
final class Mixed_Content_Fixer extends Abstract_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'mixed_content_fixer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Mixed content fixer', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Rewrite insecure http:// asset URLs on your domain to https:// so browsers stop showing "not fully secure".', 'dicestack' );
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
		return 'shield-lock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function replaces() {
		return 'premium SSL plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 25,
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
		add_action( 'template_redirect', array( $this, 'start' ), 2 );
	}

	/**
	 * Start buffering the front-end output (HTTPS pages only).
	 *
	 * @return void
	 */
	public function start() {
		if ( is_admin() || ! is_ssl() ) {
			return;
		}
		ob_start( array( $this, 'fix' ) );
	}

	/**
	 * Output-buffer callback: upgrade the site's own http:// URLs to https://.
	 *
	 * @param string $html Buffered page HTML.
	 * @return string
	 */
	public function fix( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return $html;
		}

		// Cover both www and non-www forms of the registered domain.
		$base     = preg_replace( '/^www\./i', '', $host );
		$variants = array( $base, 'www.' . $base );

		$search  = array();
		$replace = array();
		foreach ( array_unique( $variants ) as $h ) {
			$search[]  = 'http://' . $h;
			$replace[] = 'https://' . $h;
			// Escaped-slash form used inside JSON / inline scripts (http:\/\/host).
			$search[]  = 'http:\/\/' . $h;
			$replace[] = 'https:\/\/' . $h;
		}

		return str_replace( $search, $replace, $html );
	}
}
