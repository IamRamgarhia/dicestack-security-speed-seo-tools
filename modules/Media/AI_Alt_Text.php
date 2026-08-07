<?php
/**
 * AI Alt Text module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Media;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Generates descriptive alt text for image attachments that are missing it,
 * using your own OpenAI API key. The image URL and your key are only sent to
 * OpenAI when you click "Generate alt text" for a specific image.
 */
final class AI_Alt_Text extends Abstract_Module {

	const NONCE = 'dicestack_ai_alt';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'ai_alt_text';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'AI alt text', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Generate descriptive alt text for images that lack it, using your own OpenAI key.', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'photo-ai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function external_service() {
		return array(
			'service' => 'OpenAI',
			'url'     => 'https://api.openai.com',
			'data'    => __( 'the image URL and your API key are sent to OpenAI to generate alt text', 'dicestack' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 40,
			'front_js_kb'   => 0,
			'front_css_kb'  => 0,
			'db_queries'    => 0,
			'external_http' => 1,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema() {
		return array(
			array(
				'key'     => 'api_key',
				'label'   => __( 'OpenAI API key', 'dicestack' ),
				'type'    => 'password',
				'default' => '',
				'help'    => __( 'Your OpenAI API key (sk-...). Stored on your server; sent to OpenAI only when you generate alt text.', 'dicestack' ),
			),
			array(
				'key'     => 'model',
				'label'   => __( 'Model', 'dicestack' ),
				'type'    => 'text',
				'default' => 'gpt-4o-mini',
				'help'    => __( 'A vision-capable OpenAI model, e.g. gpt-4o-mini.', 'dicestack' ),
			),
			array(
				'key'     => 'overwrite',
				'label'   => __( 'Overwrite existing alt text', 'dicestack' ),
				'type'    => 'toggle',
				'default' => false,
				'help'    => __( 'When on, the generator will replace alt text that is already set.', 'dicestack' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_post_dicestack_ai_alt_generate', array( $this, 'handle_generate' ) );
		}
	}

	/**
	 * Register the tool page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'dicestack',
			__( 'AI alt text', 'dicestack' ),
			__( 'AI alt text', 'dicestack' ),
			'manage_options',
			'dicestack-ai-alt',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Fetch up to ~40 image attachments that are missing alt text.
	 *
	 * @return \WP_Post[]
	 */
	private function images_missing_alt() {
		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
				'posts_per_page' => 40,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only tool listing, run on demand.
					'relation' => 'OR',
					array(
						'key'     => '_wp_attachment_image_alt',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_wp_attachment_image_alt',
						'value'   => '',
						'compare' => '=',
					),
				),
			)
		);
		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * Render the tool page: settings form + a grid of images needing alt text.
	 *
	 * @return void
	 */
	public function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only status flags.
		$done   = isset( $_GET['done'] ) ? sanitize_text_field( wp_unslash( $_GET['done'] ) ) : '';
		$err    = isset( $_GET['err'] ) ? sanitize_text_field( wp_unslash( $_GET['err'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap"><h1>' . esc_html__( 'AI alt text', 'dicestack' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		if ( isset( $_GET['settings-saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'dicestack' ) . '</p></div>';
		}
		if ( '1' === $done ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Alt text generated and saved.', 'dicestack' ) . '</p></div>';
		}
		if ( '' !== $err ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Settings', 'dicestack' ) . '</h2>';
		echo \DiceStack\Admin\Settings_Renderer::page_form( $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally.

		$has_key = '' !== trim( (string) $this->get_setting( 'api_key', '' ) );

		echo '<h2>' . esc_html__( 'Images missing alt text', 'dicestack' ) . '</h2>';

		if ( ! $has_key ) {
			echo '<p>' . esc_html__( 'Add your OpenAI API key above to generate alt text.', 'dicestack' ) . '</p>';
		}

		$images = $this->images_missing_alt();
		if ( empty( $images ) ) {
			echo '<p>' . esc_html__( 'No images are missing alt text. Nice work!', 'dicestack' ) . '</p></div>';
			return;
		}

		echo '<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px;">';
		foreach ( $images as $img ) {
			$id    = (int) $img->ID;
			$thumb = wp_get_attachment_image( $id, 'thumbnail', false, array( 'style' => 'display:block;max-width:150px;height:auto;border-radius:6px;' ) );
			$title = get_the_title( $id );

			echo '<div style="width:170px;border:1px solid #e4e7ec;border-radius:8px;padding:10px;box-sizing:border-box;">';
			echo $thumb ? wp_kses_post( $thumb ) : '';
			echo '<p style="font-size:12px;color:#555;margin:8px 0;word-break:break-word;">' . esc_html( $title ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::NONCE . '_' . $id );
			echo '<input type="hidden" name="action" value="dicestack_ai_alt_generate" />';
			echo '<input type="hidden" name="attachment_id" value="' . esc_attr( (string) $id ) . '" />';
			echo '<button type="submit" class="button button-small"' . ( $has_key ? '' : ' disabled' ) . '>' . esc_html__( 'Generate alt text', 'dicestack' ) . '</button>';
			echo '</form>';
			echo '</div>';
		}
		echo '</div></div>';
	}

	/**
	 * Handle a "Generate alt text" submission for one attachment.
	 *
	 * @return void
	 */
	public function handle_generate() {
		$id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;

		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::NONCE . '_' . $id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dicestack' ) );
		}

		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			$this->redirect_back( __( 'That image could not be found.', 'dicestack' ) );
		}

		$key = trim( (string) $this->get_setting( 'api_key', '' ) );
		if ( '' === $key ) {
			$this->redirect_back( __( 'Add your OpenAI API key first.', 'dicestack' ) );
		}

		// Respect the overwrite setting.
		$existing = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( '' !== trim( $existing ) && empty( $this->get_setting( 'overwrite', false ) ) ) {
			$this->redirect_back( __( 'This image already has alt text (enable "Overwrite" to replace it).', 'dicestack' ) );
		}

		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			$this->redirect_back( __( 'That image has no accessible URL.', 'dicestack' ) );
		}

		$text = $this->call_openai( $key, $url );
		if ( is_wp_error( $text ) ) {
			$this->redirect_back( $text->get_error_message() );
		}

		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $text ) );
		wp_safe_redirect( admin_url( 'admin.php?page=dicestack-ai-alt&done=1' ) );
		exit;
	}

	/**
	 * Redirect back to the tool page carrying an error message.
	 *
	 * @param string $message Error message to display.
	 * @return void
	 */
	private function redirect_back( $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'dicestack-ai-alt',
					'err'  => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Ask OpenAI to describe the image and return concise alt text.
	 *
	 * @param string $key API key.
	 * @param string $url Publicly reachable image URL.
	 * @return string|\WP_Error
	 */
	private function call_openai( $key, $url ) {
		$model = trim( (string) $this->get_setting( 'model', 'gpt-4o-mini' ) );
		$model = $model ? $model : 'gpt-4o-mini';

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'    => $model,
						'messages' => array(
							array(
								'role'    => 'user',
								'content' => array(
									array(
										'type' => 'text',
										'text' => 'Write concise, descriptive alt text for this image for accessibility. Return only the alt text, no quotes, under 125 characters.',
									),
									array(
										'type'      => 'image_url',
										'image_url' => array( 'url' => $url ),
									),
								),
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['error']['message'] ) ) {
			return new \WP_Error( 'ai_alt', (string) $data['error']['message'] );
		}

		$text = isset( $data['choices'][0]['message']['content'] ) ? trim( (string) $data['choices'][0]['message']['content'] ) : '';
		// Strip any wrapping quotes the model may add.
		$text = trim( $text, "\"' \t\n\r" );

		return '' !== $text ? $text : new \WP_Error( 'ai_alt', __( 'No response from OpenAI.', 'dicestack' ) );
	}
}
