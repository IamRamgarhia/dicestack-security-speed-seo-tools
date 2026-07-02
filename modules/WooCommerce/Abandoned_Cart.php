<?php
/**
 * Abandoned Cart Recovery module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\WooCommerce;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Captures the email of shoppers who start checkout, and — if they don't finish
 * within a set delay — emails them a link that restores their cart. Marks the
 * record recovered once they buy. Replaces premium abandoned-cart plugins.
 */
final class Abandoned_Cart extends Abstract_Module {

	const CRON       = 'dicestack_ac_cron';
	const DB_VERSION = '1';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'abandoned_cart';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Abandoned cart recovery', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Email shoppers who leave checkout a link to restore their cart and finish buying.', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'woocommerce';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'shopping-cart-x';
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependencies() {
		return array( 'woocommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function performance_profile() {
		return array(
			'php_memory_kb' => 30,
			'front_js_kb'   => 0.4,
			'front_css_kb'  => 0,
			'db_queries'    => 1,
			'external_http' => 0,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_schema() {
		return array(
			array(
				'key'     => 'delay',
				'label'   => __( 'Send the reminder after (minutes)', 'dicestack' ),
				'type'    => 'number',
				'default' => 60,
				'min'     => 15,
				'max'     => 10080,
				'step'    => 5,
			),
			array(
				'key'     => 'subject',
				'label'   => __( 'Email subject', 'dicestack' ),
				'type'    => 'text',
				'default' => __( 'You left something in your cart', 'dicestack' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		$this->maybe_create_table();
		add_action( 'wp_enqueue_scripts', array( $this, 'capture_script' ) );
		add_action( 'wp_ajax_dicestack_ac_capture', array( $this, 'capture' ) );
		add_action( 'wp_ajax_nopriv_dicestack_ac_capture', array( $this, 'capture' ) );
		add_action( self::CRON, array( $this, 'send_reminders' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'hourly', self::CRON );
		}
		add_action( 'woocommerce_thankyou', array( $this, 'mark_recovered' ) );
		add_action( 'template_redirect', array( $this, 'maybe_restore' ) );
	}

	/**
	 * Create the storage table once.
	 *
	 * @return void
	 */
	private function maybe_create_table() {
		if ( get_option( 'dicestack_ac_db' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'dicestack_abandoned_carts';
		$collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				email VARCHAR(191) NOT NULL,
				cart LONGTEXT NOT NULL,
				token VARCHAR(64) NOT NULL,
				created INT UNSIGNED NOT NULL,
				notified TINYINT(1) NOT NULL DEFAULT 0,
				recovered TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY email (email),
				KEY token (token)
			) {$collate};"
		);
		update_option( 'dicestack_ac_db', self::DB_VERSION );
	}

	/**
	 * Enqueue the tiny email-capture script on the checkout page.
	 *
	 * @return void
	 */
	public function capture_script() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		$data = wp_json_encode(
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'dicestack_ac' ),
			)
		);
		wp_register_script( 'dicestack-ac', false, array(), DICESTACK_VERSION, true );
		wp_enqueue_script( 'dicestack-ac' );
		wp_add_inline_script( 'dicestack-ac', 'document.addEventListener("change",function(e){if(e.target&&e.target.id==="billing_email"&&e.target.value.indexOf("@")>0){var c=' . $data . ';var b=new URLSearchParams();b.append("action","dicestack_ac_capture");b.append("nonce",c.nonce);b.append("email",e.target.value);fetch(c.ajax,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:b.toString()});}});' );
	}

	/**
	 * AJAX: store the shopper's email + current cart.
	 *
	 * @return void
	 */
	public function capture() {
		if ( ! check_ajax_referer( 'dicestack_ac', 'nonce', false ) ) {
			wp_send_json_error();
		}
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			wp_send_json_error();
		}

		$items = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$items[] = array( 'id' => (int) $item['product_id'], 'qty' => (int) $item['quantity'] );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'dicestack_abandoned_carts';
		// Replace any earlier un-recovered row for this email.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'email' => $email, 'recovered' => 0 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'email'   => $email,
				'cart'    => wp_json_encode( $items ),
				'token'   => wp_generate_password( 32, false ),
				'created' => time(),
			)
		);
		wp_send_json_success();
	}

	/**
	 * Cron: email reminders for carts past the delay.
	 *
	 * @return void
	 */
	public function send_reminders() {
		global $wpdb;
		$table  = $wpdb->prefix . 'dicestack_abandoned_carts';
		$delay  = (int) $this->get_setting( 'delay', 60 ) * MINUTE_IN_SECONDS;
		$cutoff = time() - $delay;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, token FROM {$table} WHERE notified = 0 AND recovered = 0 AND created < %d LIMIT 50", $cutoff ) );

		foreach ( (array) $rows as $row ) {
			$link    = add_query_arg( 'dicestack_ac', $row->token, wc_get_cart_url() );
			$subject = (string) $this->get_setting( 'subject', __( 'You left something in your cart', 'dicestack' ) );
			$body    = sprintf(
				/* translators: %s: recovery link. */
				__( "Hi,\n\nYou left some items in your cart at %1\$s. Pick up where you left off:\n%2\$s\n\nThanks!", 'dicestack' ),
				get_bloginfo( 'name' ),
				esc_url_raw( $link )
			);
			wp_mail( $row->email, $subject, $body );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, array( 'notified' => 1 ), array( 'id' => $row->id ) );
		}
	}

	/**
	 * Restore a cart from a recovery link.
	 *
	 * @return void
	 */
	public function maybe_restore() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token is the capability here (a random 32-char secret).
		$token = isset( $_GET['dicestack_ac'] ) ? sanitize_text_field( wp_unslash( $_GET['dicestack_ac'] ) ) : '';
		if ( '' === $token || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'dicestack_abandoned_carts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cart = $wpdb->get_var( $wpdb->prepare( "SELECT cart FROM {$table} WHERE token = %s AND recovered = 0 LIMIT 1", $token ) );
		if ( null === $cart ) {
			return;
		}
		$items = json_decode( (string) $cart, true );
		if ( is_array( $items ) ) {
			WC()->cart->empty_cart();
			foreach ( $items as $item ) {
				if ( ! empty( $item['id'] ) ) {
					WC()->cart->add_to_cart( (int) $item['id'], max( 1, (int) $item['qty'] ) );
				}
			}
		}
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Mark rows recovered once the shopper completes an order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function mark_recovered( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'dicestack_abandoned_carts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'recovered' => 1 ), array( 'email' => $email ) );
	}
}
