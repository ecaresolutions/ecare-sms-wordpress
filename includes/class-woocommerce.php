<?php
/**
 * WooCommerce integration.
 *
 * @package EcareSMSPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends WooCommerce transactional SMS.
 */
class Ecare_SMS_Pro_WooCommerce {

	/**
	 * SMS service.
	 *
	 * @var Ecare_SMS_Pro_SMS
	 */
	private $sms;

	/**
	 * Constructor.
	 *
	 * @param Ecare_SMS_Pro_SMS $sms SMS service.
	 */
	public function __construct( Ecare_SMS_Pro_SMS $sms ) {
		$this->sms = $sms;

		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_thankyou', array( $this, 'handle_order_placed' ), 20, 1 );
			add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_changed' ), 20, 4 );
		}
	}

	/**
	 * Handle new order SMS.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function handle_order_placed( $order_id ) {
		$settings = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		if ( empty( $settings['wc_order_placed_enabled'] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $order->get_meta( '_ecare_sms_order_placed_sent', true ) ) {
			return;
		}

		$phone = $order->get_billing_phone();
		if ( empty( $phone ) ) {
			return;
		}

		$template = isset( $settings['wc_order_placed_template'] ) ? $settings['wc_order_placed_template'] : '';
		$message  = $this->sms->parse_template( $template, $order );

		$this->sms->send_sms(
			array(
				'recipient' => $phone,
				'message'   => $message,
			)
		);

		$order->update_meta_data( '_ecare_sms_order_placed_sent', current_time( 'mysql' ) );
		$order->save_meta_data();
	}

	/**
	 * Handle order status change SMS.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $old_status Old status.
	 * @param string   $new_status New status.
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function handle_status_changed( $order_id, $old_status, $new_status, $order ) {
		$settings = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		if ( empty( $settings['wc_status_changed_enabled'] ) ) {
			return;
		}

		$target_statuses = isset( $settings['wc_status_targets'] ) && is_array( $settings['wc_status_targets'] ) ? array_map( 'sanitize_key', $settings['wc_status_targets'] ) : array();
		if ( ! empty( $target_statuses ) && ! in_array( sanitize_key( $new_status ), $target_statuses, true ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		$phone = $order->get_billing_phone();
		if ( empty( $phone ) ) {
			return;
		}

		$template = isset( $settings['wc_status_changed_template'] ) ? $settings['wc_status_changed_template'] : '';
		$status_templates = isset( $settings['wc_status_templates'] ) && is_array( $settings['wc_status_templates'] ) ? $settings['wc_status_templates'] : array();
		$status_key = sanitize_key( $new_status );
		if ( isset( $status_templates[ $status_key ] ) && '' !== trim( (string) $status_templates[ $status_key ] ) ) {
			$template = (string) $status_templates[ $status_key ];
		}
		$message  = $this->sms->parse_template( $template, $order, wc_get_order_status_name( $new_status ) );

		$this->sms->send_sms(
			array(
				'recipient' => $phone,
				'message'   => $message,
			)
		);
	}
}
