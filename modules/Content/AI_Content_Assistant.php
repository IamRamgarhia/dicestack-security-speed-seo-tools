<?php
/**
 * AI Content Assistant module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\Content;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * A post-editor helper that drafts excerpts, meta descriptions, and title ideas
 * using your own OpenAI or Google Gemini API key. Nothing is sent anywhere until
 * you click a button, and only to the provider you configured.
 */
final class AI_Content_Assistant extends Abstract_Module {

	const NONCE = 'dicestack_ai';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'ai_content_assistant';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'AI content assistant', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Draft excerpts, meta descriptions, and title ideas with your own OpenAI or Gemini key.', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'sparkles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function external_service() {
		return array(
			'service' => __( 'OpenAI / Google Gemini', 'dicestack' ),
			'url'     => 'https://dicecodes.com/dicestack/docs/',
		);
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
			'external_http' => 1,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema() {
		return array(
			array(
				'key'     => 'provider',
				'label'   => __( 'Provider', 'dicestack' ),
				'type'    => 'select',
				'default' => 'openai',
				'options' => array(
					'openai' => 'OpenAI',
					'gemini' => 'Google Gemini',
				),
			),
			array(
				'key'     => 'api_key',
				'label'   => __( 'API key', 'dicestack' ),
				'type'    => 'password',
				'default' => '',
			),
			array(
				'key'     => 'model',
				'label'   => __( 'Model', 'dicestack' ),
				'type'    => 'text',
				'default' => 'gpt-4o-mini',
				'help'    => __( 'e.g. gpt-4o-mini for OpenAI, or gemini-1.5-flash for Gemini.', 'dicestack' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_dicestack_ai_generate', array( $this, 'generate' ) );
	}

	/**
	 * Register the editor meta box.
	 *
	 * @return void
	 */
	public function add_box() {
		add_meta_box( 'dicestack-ai', __( 'AI assistant', 'dicestack' ), array( $this, 'render_box' ), array( 'post', 'page' ), 'side', 'default' );
	}

	/**
	 * Render the meta box.
	 *
	 * @return void
	 */
	public function render_box() {
		if ( '' === trim( (string) $this->get_setting( 'api_key', '' ) ) ) {
			echo '<p>' . esc_html__( 'Add your API key in the DiceStack AI assistant settings to use this.', 'dicestack' ) . '</p>';
			return;
		}
		echo '<p><button type="button" class="button dicestack-ai-btn" data-task="excerpt">' . esc_html__( 'Draft excerpt', 'dicestack' ) . '</button> ';
		echo '<button type="button" class="button dicestack-ai-btn" data-task="meta">' . esc_html__( 'Meta description', 'dicestack' ) . '</button> ';
		echo '<button type="button" class="button dicestack-ai-btn" data-task="titles">' . esc_html__( 'Title ideas', 'dicestack' ) . '</button></p>';
		echo '<textarea id="dicestack-ai-out" rows="6" style="width:100%;" readonly placeholder="' . esc_attr__( 'Results appear here…', 'dicestack' ) . '"></textarea>';
	}

	/**
	 * Enqueue the meta-box script on the editor.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$cfg = wp_json_encode(
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::NONCE ),
				'wait'  => __( 'Thinking…', 'dicestack' ),
				'err'   => __( 'Request failed. Check your API key and model.', 'dicestack' ),
			)
		);
		wp_register_script( 'dicestack-ai', false, array( 'jquery' ), DICESTACK_VERSION, true );
		wp_enqueue_script( 'dicestack-ai' );
		wp_add_inline_script( 'dicestack-ai', 'var DICESTACK_AI=' . $cfg . ';document.addEventListener("click",function(e){var b=e.target;if(!b.classList||!b.classList.contains("dicestack-ai-btn"))return;e.preventDefault();var out=document.getElementById("dicestack-ai-out");out.value=DICESTACK_AI.wait;var id=document.getElementById("post_ID");var body=new URLSearchParams();body.append("action","dicestack_ai_generate");body.append("nonce",DICESTACK_AI.nonce);body.append("task",b.getAttribute("data-task"));body.append("post_id",id?id.value:0);fetch(DICESTACK_AI.ajax,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:body.toString()}).then(function(r){return r.json();}).then(function(res){out.value=(res&&res.success)?res.data:DICESTACK_AI.err;}).catch(function(){out.value=DICESTACK_AI.err;});});' );
	}

	/**
	 * AJAX: call the configured AI provider for the requested task.
	 *
	 * @return void
	 */
	public function generate() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'dicestack' ) );
		}
		$key = trim( (string) $this->get_setting( 'api_key', '' ) );
		if ( '' === $key ) {
			wp_send_json_error( __( 'No API key configured.', 'dicestack' ) );
		}
		$task    = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;
		$content = $post ? wp_strip_all_tags( $post->post_content ) : '';
		$content = mb_substr( $content, 0, 4000 );
		if ( '' === trim( $content ) ) {
			wp_send_json_error( __( 'Add some content to the post first.', 'dicestack' ) );
		}

		$prompts = array(
			'excerpt' => 'Write a concise, engaging excerpt (max 55 words) for this article:',
			'meta'    => 'Write an SEO meta description (max 155 characters) for this article:',
			'titles'  => 'Suggest 5 compelling SEO title options (one per line) for this article:',
		);
		$prompt = isset( $prompts[ $task ] ) ? $prompts[ $task ] : $prompts['excerpt'];

		$result = 'gemini' === $this->get_setting( 'provider', 'openai' )
			? $this->call_gemini( $key, $prompt . "\n\n" . $content )
			: $this->call_openai( $key, $prompt . "\n\n" . $content );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Call the OpenAI chat completions API.
	 *
	 * @param string $key    API key.
	 * @param string $prompt Prompt text.
	 * @return string|\WP_Error
	 */
	private function call_openai( $key, $prompt ) {
		$model    = trim( (string) $this->get_setting( 'model', 'gpt-4o-mini' ) );
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
						'model'    => $model ? $model : 'gpt-4o-mini',
						'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['error']['message'] ) ) {
			return new \WP_Error( 'ai', $data['error']['message'] );
		}
		$text = isset( $data['choices'][0]['message']['content'] ) ? trim( $data['choices'][0]['message']['content'] ) : '';
		return '' !== $text ? $text : new \WP_Error( 'ai', __( 'No response from the AI provider.', 'dicestack' ) );
	}

	/**
	 * Call the Google Gemini API.
	 *
	 * @param string $key    API key.
	 * @param string $prompt Prompt text.
	 * @return string|\WP_Error
	 */
	private function call_gemini( $key, $prompt ) {
		$model    = trim( (string) $this->get_setting( 'model', 'gemini-1.5-flash' ) );
		$model    = $model ? $model : 'gemini-1.5-flash';
		$url      = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array( 'contents' => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ) )
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( isset( $data['error']['message'] ) ) {
			return new \WP_Error( 'ai', $data['error']['message'] );
		}
		$text = isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ? trim( $data['candidates'][0]['content']['parts'][0]['text'] ) : '';
		return '' !== $text ? $text : new \WP_Error( 'ai', __( 'No response from the AI provider.', 'dicestack' ) );
	}
}
