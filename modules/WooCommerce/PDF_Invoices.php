<?php
/**
 * PDF / printable Invoices module.
 *
 * @package DiceStack
 */

namespace DiceStack\Modules\WooCommerce;

use DiceStack\Modules\Abstract_Module;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a clean, printable invoice (save-as-PDF from the browser) to every order,
 * linked from the customer's account, the order emails, and the admin order
 * screen. Replaces premium PDF-invoice plugins for the common case.
 */
final class PDF_Invoices extends Abstract_Module {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'pdf_invoices';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'PDF invoices', 'dicestack' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Add a printable / save-as-PDF invoice to orders, order emails, and the customer account.', 'dicestack' );
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
		return 'file-invoice';
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
	public function init() {
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'account_button' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_link' ), 10, 1 );
	}

	/**
	 * Build the access-controlled invoice URL for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function invoice_url( $order ) {
		return add_query_arg(
			array(
				'dicestack_invoice' => $order->get_id(),
				'key'               => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	/**
	 * Show the invoice button on the customer's order-details view.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function account_button( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		echo '<p><a class="button" href="' . esc_url( $this->invoice_url( $order ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Download invoice (PDF)', 'dicestack' ) . '</a></p>';
	}

	/**
	 * Add an invoice link to order emails.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function email_link( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		echo '<p><a href="' . esc_url( $this->invoice_url( $order ) ) . '">' . esc_html__( 'Download your invoice (PDF)', 'dicestack' ) . '</a></p>';
	}

	/**
	 * Render the printable invoice when the invoice URL is requested.
	 *
	 * @return void
	 */
	public function maybe_render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- access is authorised by the order key below.
		if ( empty( $_GET['dicestack_invoice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = absint( $_GET['dicestack_invoice'] );
		$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Invoice not found.', 'dicestack' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! current_user_can( 'manage_woocommerce' ) && ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			wp_die( esc_html__( 'You are not allowed to view this invoice.', 'dicestack' ) );
		}

		$this->output( $order );
		exit;
	}

	/**
	 * Output the invoice as a self-contained, printable HTML document.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function output( $order ) {
		nocache_headers();
		$store = get_bloginfo( 'name' );

		// A standalone document — inline styles are appropriate here (like wp_die pages).
		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html( sprintf( /* translators: %s: order number. */ __( 'Invoice #%s', 'dicestack' ), $order->get_order_number() ) ) . '</title>';
		echo '<style>body{font-family:Arial,Helvetica,sans-serif;color:#222;max-width:780px;margin:24px auto;padding:0 20px}h1{font-size:24px}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{text-align:left;padding:8px;border-bottom:1px solid #e5e7eb}tfoot td{border:0;font-weight:bold}.tot{text-align:right}.muted{color:#6b7280}.bar{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px}.btn{display:inline-block;margin:16px 0;padding:8px 16px;background:#1b2a4a;color:#fff;text-decoration:none;border-radius:6px}@media print{.btn{display:none}}</style></head><body>';
		echo '<a href="#" class="btn" onclick="window.print();return false;">' . esc_html__( 'Print / Save as PDF', 'dicestack' ) . '</a>';
		echo '<div class="bar"><div><h1>' . esc_html( $store ) . '</h1></div><div class="tot"><strong>' . esc_html__( 'Invoice', 'dicestack' ) . '</strong><br>#' . esc_html( $order->get_order_number() ) . '<br><span class="muted">' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</span></div></div>';

		echo '<div class="muted">' . esc_html__( 'Billed to', 'dicestack' ) . '</div><strong>' . esc_html( $order->get_formatted_billing_full_name() ) . '</strong><br>' . wp_kses_post( $order->get_formatted_billing_address() );

		echo '<table><thead><tr><th>' . esc_html__( 'Item', 'dicestack' ) . '</th><th>' . esc_html__( 'Qty', 'dicestack' ) . '</th><th class="tot">' . esc_html__( 'Total', 'dicestack' ) . '</th></tr></thead><tbody>';
		foreach ( $order->get_items() as $item ) {
			echo '<tr><td>' . esc_html( $item->get_name() ) . '</td><td>' . esc_html( $item->get_quantity() ) . '</td><td class="tot">' . wp_kses_post( wc_price( $order->get_line_total( $item, true ), array( 'currency' => $order->get_currency() ) ) ) . '</td></tr>';
		}
		echo '</tbody><tfoot>';
		echo '<tr><td colspan="2" class="tot">' . esc_html__( 'Total', 'dicestack' ) . '</td><td class="tot">' . wp_kses_post( $order->get_formatted_order_total() ) . '</td></tr>';
		echo '</tfoot></table>';
		echo '<p class="muted">' . esc_html( sprintf( /* translators: %s: store name. */ __( 'Thank you for your order at %s.', 'dicestack' ), $store ) ) . '</p>';
		echo '</body></html>';
	}
}
