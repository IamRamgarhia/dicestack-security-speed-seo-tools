<?php
/**
 * Broken Link Checker module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\SEO;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Scans your most recent posts and pages for links that return an error (404,
 * server errors, dead domains) so you can fix them before visitors and search
 * engines hit them. Broken links hurt user experience and SEO.
 */
final class Broken_Link_Checker extends Abstract_Module {

	/**
	 * Option storing the last scan results and timestamp.
	 */
	const OPTION = 'dicestack_broken_links';

	/**
	 * Maximum posts/pages inspected per scan.
	 */
	const MAX_POSTS = 50;

	/**
	 * Hard cap on the number of unique URLs actually requested per scan.
	 */
	const MAX_URLS = 150;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'broken_link_checker';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Broken link checker', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Scan recent posts and pages for dead links (404s and errors) that hurt SEO and visitors.', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'seo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'unlink';
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 45,
			'front_js_kb'   => 0,
			'front_css_kb'  => 0,
			'db_queries'    => 2,
			'external_http' => 150,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_post_dicestack_scan_links', array( $this, 'handle_scan' ) );
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
			__( 'Broken links', 'dicestack' ),
			__( 'Broken links', 'dicestack' ),
			'manage_options',
			'dicestack-broken-links',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Run a bounded scan across recent posts and pages.
	 *
	 * @return void
	 */
	public function handle_scan() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dicestack_scan_links' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}

		$posts = get_posts(
			array(
				'numberposts' => self::MAX_POSTS,
				'post_type'   => array( 'post', 'page' ),
				'post_status' => 'publish',
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$broken    = array();
		$checked   = array();
		$truncated = false;

		foreach ( $posts as $post ) {
			$urls = $this->extract_urls( $post->post_content );
			if ( empty( $urls ) ) {
				continue;
			}

			foreach ( $urls as $url ) {
				if ( isset( $checked[ $url ] ) ) {
					// Already requested this URL; reuse the verdict.
					if ( false !== $checked[ $url ] ) {
						$broken[] = array(
							'post_id'    => (int) $post->ID,
							'post_title' => $post->post_title,
							'url'        => $url,
							'status'     => $checked[ $url ],
						);
					}
					continue;
				}

				if ( count( $checked ) >= self::MAX_URLS ) {
					$truncated = true;
					break 2;
				}

				$status            = $this->check_url( $url );
				$checked[ $url ]   = $status; // false = OK, otherwise a status label/code.
				if ( false !== $status ) {
					$broken[] = array(
						'post_id'    => (int) $post->ID,
						'post_title' => $post->post_title,
						'url'        => $url,
						'status'     => $status,
					);
				}
			}
		}

		update_option(
			self::OPTION,
			array(
				'time'      => time(),
				'checked'   => count( $checked ),
				'truncated' => $truncated,
				'results'   => $broken,
			),
			false
		);

		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-broken-links&scanned=' . count( $broken ) ) );
		exit;
	}

	/**
	 * Extract checkable absolute http(s) URLs from post content.
	 *
	 * @param string $content Post content.
	 * @return string[] Unique URLs.
	 */
	private function extract_urls( $content ) {
		$urls = array();
		if ( ! preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\']#i', (string) $content, $m ) ) {
			return $urls;
		}

		foreach ( $m[1] as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES ) );
			if ( '' === $href ) {
				continue;
			}
			// Skip non-navigational or non-HTTP schemes and in-page anchors.
			if ( preg_match( '#^(mailto:|tel:|javascript:|#|data:)#i', $href ) ) {
				continue;
			}
			if ( ! preg_match( '#^https?://#i', $href ) ) {
				continue;
			}
			$urls[ $href ] = true;
		}

		return array_keys( $urls );
	}

	/**
	 * Check a single URL. Returns false if reachable, otherwise a short status
	 * label to display (HTTP code or an error tag).
	 *
	 * @param string $url URL to check.
	 * @return string|false
	 */
	private function check_url( $url ) {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 5,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return __( 'No response', 'dicestack' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return (string) $code;
		}

		return false;
	}

	/**
	 * Render the scan page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}

		$data      = get_option( self::OPTION, array() );
		$data      = is_array( $data ) ? $data : array();
		$results   = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();
		$last_time = isset( $data['time'] ) ? (int) $data['time'] : 0;
		$truncated = ! empty( $data['truncated'] );

		echo '<div class="wrap"><h1>' . esc_html__( 'Broken link checker', 'dicestack' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		if ( isset( $_GET['scanned'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
			$count = (int) $_GET['scanned'];
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of broken links found. */
					_n( 'Scan complete — %d broken link found.', 'Scan complete — %d broken links found.', $count, 'dicestack' ),
					$count
				)
			) . '</p></div>';
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of posts/pages scanned. */
				__( 'Checks links in your %d most recent published posts and pages.', 'dicestack' ),
				self::MAX_POSTS
			)
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:16px;">';
		wp_nonce_field( 'dicestack_scan_links' );
		echo '<input type="hidden" name="action" value="dicestack_scan_links" />';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Scan now', 'dicestack' ) . '</button>';
		echo '</form>';

		if ( $last_time ) {
			$ago = sprintf( /* translators: %s: human time difference. */ __( '%s ago', 'dicestack' ), human_time_diff( $last_time, time() ) );
			echo '<p><strong>' . esc_html(
				sprintf(
					/* translators: 1: time since last scan, 2: number of broken links. */
					_n( 'Last scanned %1$s — %2$d broken link.', 'Last scanned %1$s — %2$d broken links.', count( $results ), 'dicestack' ),
					$ago,
					count( $results )
				)
			) . '</strong></p>';
		} else {
			echo '<p>' . esc_html__( 'No scan has run yet.', 'dicestack' ) . '</p>';
		}

		if ( $truncated ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: maximum number of URLs checked. */
					__( 'Scan was capped at %d unique URLs for speed — some links may not have been checked.', 'dicestack' ),
					self::MAX_URLS
				)
			) . '</p>';
		}

		if ( empty( $results ) ) {
			if ( $last_time ) {
				echo '<p>' . esc_html__( 'No broken links found. Nice and tidy.', 'dicestack' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'dicestack' ) . '</th>';
		echo '<th>' . esc_html__( 'Broken URL', 'dicestack' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'dicestack' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $results as $row ) {
			$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
			$title   = isset( $row['post_title'] ) && '' !== $row['post_title'] ? $row['post_title'] : __( '(no title)', 'dicestack' );
			$edit    = $post_id ? get_edit_post_link( $post_id ) : '';
			$url     = isset( $row['url'] ) ? (string) $row['url'] : '';
			$status  = isset( $row['status'] ) ? (string) $row['status'] : '';

			echo '<tr>';
			if ( $edit ) {
				echo '<td><a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a></td>';
			} else {
				echo '<td>' . esc_html( $title ) . '</td>';
			}
			echo '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '</a></td>';
			echo '<td><strong style="color:#b32d2e;">' . esc_html( $status ) . '</strong></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}
}
