<?php
/**
 * Critical CSS module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Performance;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Inlines above-the-fold "critical" CSS in the head and loads the rest of the
 * stylesheets asynchronously, so the page paints faster (better LCP / PageSpeed).
 * Replaces the critical-CSS feature of premium optimizers.
 */
final class Critical_CSS extends Abstract_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'critical_css';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Critical CSS', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Inline above-the-fold CSS and load the rest async for a faster first paint.', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'performance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'brand-css3';
	}

	/**
	 * {@inheritDoc}
	 */
	public function replaces() {
		return 'premium CSS optimizers';
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
	public function settings_schema() {
		return array(
			array(
				'key'     => 'css',
				'label'   => __( 'Critical (above-the-fold) CSS', 'dicestack' ),
				'type'    => 'textarea',
				'default' => '',
				'help'    => __( 'Paste the minimal CSS needed to render the top of the page. Generate it with a tool like criticalcss.com.', 'dicestack' ),
			),
			array(
				'key'     => 'async',
				'label'   => __( 'Load remaining stylesheets asynchronously', 'dicestack' ),
				'type'    => 'toggle',
				'default' => true,
				'help'    => __( 'Removes render-blocking CSS. Only turn on once your critical CSS is in place, or the page may briefly appear unstyled.', 'dicestack' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		add_action( 'wp_head', array( $this, 'print_critical' ), 2 );
		if ( ! empty( $this->get_setting( 'async', true ) ) ) {
			add_filter( 'style_loader_tag', array( $this, 'defer_style' ), 20, 2 );
		}
	}

	/**
	 * Inline the critical CSS at the very top of <head>.
	 *
	 * @return void
	 */
	public function print_critical() {
		if ( is_admin() ) {
			return;
		}
		$css = trim( (string) $this->get_setting( 'css', '' ) );
		if ( '' === $css ) {
			return;
		}
		// Neutralise any attempt to break out of the <style> element.
		$css = str_ireplace( '</style', '', $css );
		echo '<style id="dicestack-critical-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-provided CSS; </style> break-out removed above.
	}

	/**
	 * Make non-critical stylesheets load without blocking render.
	 *
	 * @param string $tag    The <link> tag.
	 * @param string $handle Stylesheet handle.
	 * @return string
	 */
	public function defer_style( $tag, $handle ) {
		if ( is_admin() || is_customize_preview() ) {
			return $tag;
		}
		// Leave already-async or preload tags alone.
		if ( false !== strpos( $tag, "media='print'" ) || false !== strpos( $tag, 'rel="preload"' ) ) {
			return $tag;
		}
		$async = str_replace( "media='all'", "media='print' onload=\"this.media='all'\"", $tag );
		if ( $async === $tag ) {
			$async = str_replace( '<link ', '<link media="print" onload="this.media=\'all\'" ', $tag );
		}
		// Provide a no-JS fallback so the stylesheet still applies.
		$async .= '<noscript>' . $tag . '</noscript>';
		return $async;
	}
}
