<?php
/**
 * SVG Upload module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Admin;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Allows SVG uploads with a sanitisation pass that strips scripts and event
 * handlers. Replaces Safe SVG. Only users who can already upload files benefit.
 */
final class SVG_Upload extends Abstract_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'svg_upload';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'SVG upload', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Safely allow SVG uploads with automatic sanitisation of dangerous code.', 'dicestack' );
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
		return 'photo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function replaces() {
		return 'premium SVG plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 45,
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
		add_filter( 'upload_mimes', array( $this, 'allow_svg' ) );
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'sanitize_on_upload' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'fix_filetype_check' ), 10, 4 );
	}

	/**
	 * Add SVG to allowed mime types (admins/editors only).
	 *
	 * @param array $mimes Allowed mimes.
	 * @return array
	 */
	public function allow_svg( $mimes ) {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'upload_files' ) ) {
			$mimes['svg']  = 'image/svg+xml';
			$mimes['svgz'] = 'image/svg+xml';
		}
		return $mimes;
	}

	/**
	 * Let WordPress accept the SVG mime during its filetype check.
	 *
	 * @param array  $data     File data.
	 * @param string $file     File path.
	 * @param string $filename File name.
	 * @param array  $mimes    Allowed mimes.
	 * @return array
	 */
	public function fix_filetype_check( $data, $file, $filename, $mimes ) {
		if ( substr( strtolower( $filename ), -4 ) === '.svg' ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
		}
		return $data;
	}

	/**
	 * Sanitise an SVG file before it is stored.
	 *
	 * @param array $file Upload array (name, type, tmp_name, ...).
	 * @return array
	 */
	public function sanitize_on_upload( $file ) {
		// Decide by extension, not MIME — a .svgz or a spoofed MIME must not slip past.
		$name = isset( $file['name'] ) ? strtolower( $file['name'] ) : '';
		$ext  = substr( $name, -4 );
		$is_svg  = ( '.svg' === $ext );
		$is_svgz = ( '.svgz' === substr( $name, -5 ) );
		if ( ! $is_svg && ! $is_svgz ) {
			return $file;
		}

		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a just-uploaded temp file.
		if ( false === $contents ) {
			$file['error'] = __( 'Could not read the SVG file.', 'dicestack' );
			return $file;
		}

		// SVGZ is gzip-compressed — decompress, sanitise, recompress.
		if ( $is_svgz ) {
			$decoded = function_exists( 'gzdecode' ) ? @gzdecode( $contents ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $decoded ) {
				$file['error'] = __( 'This SVGZ could not be read and was blocked.', 'dicestack' );
				return $file;
			}
			$contents = $decoded;
		}

		$clean = $this->sanitize_svg( $contents );
		if ( null === $clean ) {
			$file['error'] = __( 'This SVG could not be safely sanitised and was blocked.', 'dicestack' );
			return $file;
		}

		if ( $is_svgz && function_exists( 'gzencode' ) ) {
			$clean = gzencode( $clean );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing back the sanitised temp file.
		file_put_contents( $file['tmp_name'], $clean );
		return $file;
	}

	/**
	 * Strip scripts, event handlers, and external references from SVG markup.
	 *
	 * @param string $svg Raw SVG.
	 * @return string|null Sanitised SVG, or null if it can't be parsed.
	 */
	private function sanitize_svg( $svg ) {
		if ( '' === trim( $svg ) || ! class_exists( '\DOMDocument' ) ) {
			return null;
		}

		// Trim to the <svg> root.
		$start = stripos( $svg, '<svg' );
		if ( false === $start ) {
			return null;
		}
		$svg = substr( $svg, $start );
		$end = strripos( $svg, '</svg>' );
		if ( false !== $end ) {
			$svg = substr( $svg, 0, $end + 6 );
		}

		// Refuse (do not try to strip) anything carrying a DOCTYPE, ENTITY, or
		// processing instruction — these are XXE / entity-expansion vectors.
		if ( preg_match( '/<!DOCTYPE|<!ENTITY|<\?/i', $svg ) ) {
			return null;
		}

		// Parse with a real DOM parser — regex cannot safely parse XML.
		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		// LIBXML_NONET blocks network access; entities are NOT substituted (no LIBXML_NOENT).
		$ok = $dom->loadXML( $svg, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok || ! $dom->documentElement || 'svg' !== strtolower( (string) $dom->documentElement->localName ) ) {
			return null;
		}

		$this->scrub_node( $dom->documentElement );

		$out = $dom->saveXML( $dom->documentElement );
		return ( is_string( $out ) && '' !== $out ) ? $out : null;
	}

	/**
	 * Recursively remove disallowed elements/attributes from an SVG DOM node.
	 *
	 * @param \DOMNode $node Node to scrub.
	 * @return void
	 */
	private function scrub_node( $node ) {
		static $allowed = array(
			'svg', 'g', 'defs', 'symbol', 'use', 'switch', 'title', 'desc', 'metadata',
			'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
			'text', 'tspan', 'textpath', 'tref',
			'clippath', 'mask', 'pattern', 'image',
			'lineargradient', 'radialgradient', 'stop',
			'marker', 'view', 'a', 'style',
			'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix', 'fecomposite',
			'femerge', 'femergenode', 'feflood', 'femorphology', 'fetile', 'feturbulence',
			'fedisplacementmap', 'fecomponenttransfer', 'fefunca', 'fefuncr', 'fefuncg', 'fefuncb',
		);

		if ( ! $node->hasChildNodes() ) {
			return;
		}
		// Iterate in reverse so removals don't skip siblings.
		for ( $i = $node->childNodes->length - 1; $i >= 0; $i-- ) {
			$child = $node->childNodes->item( $i );
			if ( $child instanceof \DOMElement ) {
				$tag = strtolower( (string) $child->localName );
				if ( ! in_array( $tag, $allowed, true ) ) {
					$node->removeChild( $child );
					continue;
				}
				// A <style> block can smuggle expression()/@import/url(javascript:).
				if ( 'style' === $tag && preg_match( '/@import|expression\s*\(|javascript:|behavior\s*:|url\(\s*["\']?\s*(javascript|data:text|https?:)/i', (string) $child->textContent ) ) {
					$node->removeChild( $child );
					continue;
				}
				$this->scrub_attributes( $child );
				$this->scrub_node( $child );
			} elseif ( $child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction ) {
				$node->removeChild( $child );
			}
		}
	}

	/**
	 * Remove event-handler and unsafe-URL attributes from an SVG element.
	 *
	 * @param \DOMElement $el Element.
	 * @return void
	 */
	private function scrub_attributes( $el ) {
		if ( ! $el->hasAttributes() ) {
			return;
		}
		$remove = array();
		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			$name  = strtolower( (string) $attr->nodeName );
			$value = preg_replace( '/\s+/', '', strtolower( (string) $attr->nodeValue ) );

			// Any inline event handler (onload, onbegin, onclick, …).
			if ( 0 === strpos( $name, 'on' ) ) {
				$remove[] = $attr->nodeName;
				continue;
			}
			// javascript: or non-image data: URIs in any reference attribute.
			if ( in_array( $name, array( 'href', 'xlink:href', 'src', 'from', 'to', 'by', 'values', 'begin', 'end' ), true )
				&& ( 0 === strpos( $value, 'javascript:' ) || ( 0 === strpos( $value, 'data:' ) && 0 !== strpos( $value, 'data:image/' ) ) ) ) {
				$remove[] = $attr->nodeName;
				continue;
			}
			// style attributes carrying expression()/javascript:/dangerous url().
			if ( 'style' === $name && preg_match( '/expression\s*\(|javascript:|behavior\s*:|url\(\s*["\']?\s*(javascript|data:text)/i', (string) $attr->nodeValue ) ) {
				$remove[] = $attr->nodeName;
			}
		}
		foreach ( $remove as $attr_name ) {
			$el->removeAttribute( $attr_name );
		}
	}
}
