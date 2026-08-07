<?php
/**
 * CDN Rewrite module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Performance;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites the host of local static assets (CSS, JS, images, fonts) to a CDN
 * URL so they are served from the edge instead of the origin server. Replaces
 * the CDN-rewrite feature of premium performance plugins.
 */
final class CDN_Rewrite extends Abstract_Module {

	/**
	 * Resolved CDN base URL (scheme + host, no trailing slash). Empty = disabled.
	 *
	 * @var string
	 */
	private $cdn_base = '';

	/**
	 * The site's own host, lower-cased.
	 *
	 * @var string
	 */
	private $site_host = '';

	/**
	 * Allowed lower-case file extensions (without the dot).
	 *
	 * @var string[]
	 */
	private $allowed_ext = array();

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'cdn_rewrite';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'CDN rewrite', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Serve CSS, JS, images, and fonts from your CDN by rewriting their URLs.', 'dicestack' );
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
		return 'world-upload';
	}

	/**
	 * {@inheritDoc}
	 */
	public function replaces() {
		return 'CDN rewrite plugins';
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
	 * {@inheritDoc}
	 */
	public function settings_schema() {
		return array(
			array(
				'key'     => 'cdn_url',
				'label'   => __( 'CDN URL', 'dicestack' ),
				'type'    => 'url',
				'default' => '',
				'help'    => __( 'The base URL of your CDN, e.g. https://cdn.example.com. Leave empty to disable.', 'dicestack' ),
			),
			array(
				'key'     => 'extensions',
				'label'   => __( 'File extensions', 'dicestack' ),
				'type'    => 'text',
				'default' => 'css,js,png,jpg,jpeg,gif,webp,svg,ico,woff,woff2',
				'help'    => __( 'Comma-separated file extensions to serve from the CDN.', 'dicestack' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		if ( is_admin() ) {
			return;
		}

		$cdn = trim( (string) $this->get_setting( 'cdn_url', '' ) );
		if ( '' === $cdn ) {
			return;
		}

		// Normalise the CDN base to scheme + host, no trailing slash.
		$cdn_parts = wp_parse_url( esc_url_raw( $cdn ) );
		if ( empty( $cdn_parts['scheme'] ) || empty( $cdn_parts['host'] ) ) {
			return;
		}
		$this->cdn_base = $cdn_parts['scheme'] . '://' . $cdn_parts['host'];
		if ( ! empty( $cdn_parts['port'] ) ) {
			$this->cdn_base .= ':' . $cdn_parts['port'];
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( empty( $home_host ) ) {
			return;
		}
		$this->site_host = strtolower( (string) $home_host );

		$ext_raw = (string) $this->get_setting( 'extensions', '' );
		foreach ( explode( ',', $ext_raw ) as $ext ) {
			$ext = strtolower( trim( $ext ) );
			$ext = ltrim( $ext, '.' );
			if ( '' !== $ext ) {
				$this->allowed_ext[] = $ext;
			}
		}
		if ( empty( $this->allowed_ext ) ) {
			return;
		}

		add_filter( 'style_loader_src', array( $this, 'rewrite' ) );
		add_filter( 'script_loader_src', array( $this, 'rewrite' ) );
		add_filter( 'wp_get_attachment_url', array( $this, 'rewrite' ) );
	}

	/**
	 * Rewrite a local asset URL onto the CDN host when its extension is allowed.
	 *
	 * @param string $url The original URL.
	 * @return string The rewritten URL, or the original if it does not qualify.
	 */
	public function rewrite( $url ) {
		if ( ! is_string( $url ) || '' === $url || '' === $this->cdn_base ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return $url;
		}

		// Only touch assets on our own host.
		if ( strtolower( $parts['host'] ) !== $this->site_host ) {
			return $url;
		}

		// Extension gate.
		$ext = strtolower( (string) pathinfo( $parts['path'], PATHINFO_EXTENSION ) );
		if ( '' === $ext || ! in_array( $ext, $this->allowed_ext, true ) ) {
			return $url;
		}

		$path  = $parts['path'];
		$path .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return $this->cdn_base . $path;
	}
}
