<?php
/**
 * Direct Checkout module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\WooCommerce;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Sends shoppers straight to checkout after adding to cart (optionally clearing
 * the cart first so it's a single-product buy), skipping the cart page to lift
 * conversion. Replaces premium "direct checkout" plugins.
 */
final class Direct_Checkout extends Abstract_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'direct_checkout';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Direct checkout', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Skip the cart page — send customers straight to checkout after adding a product.', 'dicestack' );
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
		return 'shopping-cart-check';
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
			'php_memory_kb' => 12,
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
				'key'     => 'single_product',
				'label'   => __( 'Only one product at a time', 'dicestack' ),
				'type'    => 'toggle',
				'default' => false,
				'help'    => __( 'Empty the cart before each add, so checkout always has just the latest product.', 'dicestack' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function init() {
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'redirect_to_checkout' ) );
		if ( ! empty( $this->get_setting( 'single_product', false ) ) ) {
			add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'empty_cart_first' ), 10, 1 );
		}
	}

	/**
	 * Redirect the add-to-cart action to the checkout page.
	 *
	 * @param string $url Default redirect URL.
	 * @return string
	 */
	public function redirect_to_checkout( $url ) {
		return function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $url;
	}

	/**
	 * Empty the cart before a new item is validated (single-product mode).
	 *
	 * @param bool $passed Whether validation passed.
	 * @return bool
	 */
	public function empty_cart_first( $passed ) {
		if ( $passed && function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		return $passed;
	}
}
