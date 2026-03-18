<?php
/**
 * SMS service.
 *
 * @package EcareSMSPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates SMS sending and log writes.
 */
class Ecare_SMS_Pro_SMS {

	/**
	 * API client.
	 *
	 * @var Ecare_SMS_Pro_API
	 */
	private $api;

	/**
	 * Log service.
	 *
	 * @var Ecare_SMS_Pro_Logs
	 */
	private $logs;

	/**
	 * Constructor.
	 *
	 * @param Ecare_SMS_Pro_API  $api API class.
	 * @param Ecare_SMS_Pro_Logs $logs Logs class.
	 */
	public function __construct( Ecare_SMS_Pro_API $api, Ecare_SMS_Pro_Logs $logs ) {
		$this->api  = $api;
		$this->logs = $logs;
	}

	/**
	 * Send SMS to one or many recipients.
	 *
	 * @param array $args Send args.
	 * @return array|WP_Error
	 */
	public function send_sms( $args ) {
		$settings  = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		$recipients = $this->normalize_recipient_list( isset( $args['recipient'] ) ? $args['recipient'] : '' );
		$message    = isset( $args['message'] ) ? wp_strip_all_tags( $args['message'] ) : '';
		$type       = ! empty( $args['type'] ) ? sanitize_text_field( $args['type'] ) : ( isset( $settings['sms_type'] ) ? sanitize_text_field( $settings['sms_type'] ) : 'plain' );
		$sender_id  = ! empty( $args['sender_id'] ) ? sanitize_text_field( $args['sender_id'] ) : ( isset( $settings['default_sender_id'] ) ? sanitize_text_field( $settings['default_sender_id'] ) : '' );
		$schedule   = isset( $args['schedule_time'] ) ? sanitize_text_field( $args['schedule_time'] ) : '';

		if ( empty( $recipients ) ) {
			return new WP_Error( 'ecare_sms_recipients_missing', __( 'At least one valid recipient is required.', 'ecare-sms-pro' ) );
		}

		if ( empty( $sender_id ) ) {
			return new WP_Error( 'ecare_sms_sender_missing', __( 'Sender ID is required.', 'ecare-sms-pro' ) );
		}

		if ( empty( $message ) ) {
			return new WP_Error( 'ecare_sms_message_missing', __( 'Message cannot be empty.', 'ecare-sms-pro' ) );
		}

		$payload = array(
			'recipient' => implode( ',', $recipients ),
			'sender_id' => $sender_id,
			'type'      => $type,
			'message'   => $message,
		);

		if ( ! empty( $schedule ) ) {
			if ( ! $this->is_valid_schedule( $schedule ) ) {
				return new WP_Error( 'ecare_sms_invalid_schedule', __( 'Schedule time format must be YYYY-MM-DD HH:MM.', 'ecare-sms-pro' ) );
			}
			$payload['schedule_time'] = $schedule;
		}

		$payload  = apply_filters( 'ecare_sms_pro_send_payload', $payload, $args );
		$response = $this->api->send_sms( $payload );

		$this->log_result( $payload['recipient'], $message, $response );

		do_action( 'ecare_sms_pro_sms_sent', $payload, $response );

		return $response;
	}

	/**
	 * Send campaign SMS.
	 *
	 * @param array $args Campaign args.
	 * @return array|WP_Error
	 */
	public function send_campaign( $args ) {
		$settings        = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		$contact_list_id = $this->normalize_recipient_list( isset( $args['contact_list_id'] ) ? $args['contact_list_id'] : '' );
		$message         = isset( $args['message'] ) ? wp_strip_all_tags( $args['message'] ) : '';
		$type            = ! empty( $args['type'] ) ? sanitize_text_field( $args['type'] ) : ( isset( $settings['sms_type'] ) ? sanitize_text_field( $settings['sms_type'] ) : 'plain' );
		$sender_id       = ! empty( $args['sender_id'] ) ? sanitize_text_field( $args['sender_id'] ) : ( isset( $settings['default_sender_id'] ) ? sanitize_text_field( $settings['default_sender_id'] ) : '' );
		$schedule        = isset( $args['schedule_time'] ) ? sanitize_text_field( $args['schedule_time'] ) : '';

		if ( empty( $contact_list_id ) ) {
			return new WP_Error( 'ecare_sms_contact_list_missing', __( 'Contact list ID is required.', 'ecare-sms-pro' ) );
		}

		if ( empty( $sender_id ) || empty( $message ) ) {
			return new WP_Error( 'ecare_sms_campaign_missing', __( 'Sender ID and message are required for campaign.', 'ecare-sms-pro' ) );
		}

		$payload = array(
			'contact_list_id' => implode( ',', $contact_list_id ),
			'sender_id'       => $sender_id,
			'type'            => $type,
			'message'         => $message,
		);

		if ( ! empty( $schedule ) ) {
			if ( ! $this->is_valid_schedule( $schedule ) ) {
				return new WP_Error( 'ecare_sms_invalid_schedule', __( 'Schedule time format must be YYYY-MM-DD HH:MM.', 'ecare-sms-pro' ) );
			}
			$payload['schedule_time'] = $schedule;
		}

		$payload  = apply_filters( 'ecare_sms_pro_campaign_payload', $payload, $args );
		$response = $this->api->send_campaign( $payload );

		$this->log_result( $payload['contact_list_id'], $message, $response );
		do_action( 'ecare_sms_pro_campaign_sent', $payload, $response );

		return $response;
	}

	/**
	 * Check SMS status by UID.
	 *
	 * @param string $uid UID.
	 * @return array|WP_Error
	 */
	public function check_status( $uid ) {
		return $this->api->get_sms_status( $uid );
	}

	/**
	 * Get contact numbers from source.
	 *
	 * @param string $source users|customers.
	 * @param string $role Optional role.
	 * @return array
	 */
	public function get_contacts_by_source( $source, $role = '' ) {
		$source = sanitize_key( $source );
		$role   = sanitize_text_field( $role );
		$query  = array(
			'number'  => 500,
			'orderby' => 'ID',
			'order'   => 'DESC',
		);

		if ( ! empty( $role ) ) {
			$query['role'] = $role;
		}

		if ( 'customers' === $source ) {
			if ( class_exists( 'WooCommerce' ) ) {
				$query['meta_query'] = array(
					array(
						'key'     => 'billing_phone',
						'compare' => 'EXISTS',
					),
				);
			}
		}

		$users   = get_users( $query );
		$numbers = array();

		foreach ( $users as $user ) {
			$phone = get_user_meta( $user->ID, 'billing_phone', true );
			if ( empty( $phone ) ) {
				$phone = get_user_meta( $user->ID, 'phone', true );
			}
			if ( empty( $phone ) ) {
				$phone = get_user_meta( $user->ID, 'mobile', true );
			}

			if ( ! empty( $phone ) ) {
				$numbers[] = $phone;
			}
		}

		return $this->normalize_recipient_list( implode( ',', $numbers ) );
	}

	/**
	 * Replace supported variables in templates.
	 *
	 * @param string   $template Template string.
	 * @param WC_Order $order Woo order.
	 * @param string   $status Optional status.
	 * @return string
	 */
	public function parse_template( $template, $order, $status = '' ) {
		$map = array(
			'{order_id}'      => $order ? $order->get_id() : '',
			'{customer_name}' => $order ? trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) : '',
			'{total}'         => $order ? $order->get_formatted_order_total() : '',
			'{order_status}'  => $status,
		);

		return strtr( (string) $template, $map );
	}

	/**
	 * Validate schedule format.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function is_valid_schedule( $date ) {
		$dt = DateTime::createFromFormat( 'Y-m-d H:i', $date );
		return $dt && $dt->format( 'Y-m-d H:i' ) === $date;
	}

	/**
	 * Normalize recipients list.
	 *
	 * @param string $raw Raw input.
	 * @return array
	 */
	private function normalize_recipient_list( $raw ) {
		$raw    = (string) $raw;
		$items  = preg_split( '/[\s,]+/', $raw );
		$clean  = array();

		foreach ( (array) $items as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}

			$item = preg_replace( '/[^0-9a-zA-Z+_-]/', '', $item );
			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Save SMS attempt in logs.
	 *
	 * @param string         $recipient Recipient(s).
	 * @param string         $message Message.
	 * @param array|WP_Error $response API response.
	 * @return void
	 */
	private function log_result( $recipient, $message, $response ) {
		$status = 'failed';
		$uid    = '';
		$data   = array();

		if ( is_wp_error( $response ) ) {
			$data = array(
				'error'   => $response->get_error_code(),
				'message' => $response->get_error_message(),
				'context' => $response->get_error_data(),
			);
		} else {
			$data   = $response;
			$status = ( isset( $response['status'] ) && 'success' === strtolower( (string) $response['status'] ) ) ? 'success' : 'failed';
			$uid    = $this->find_uid( $response );
		}

		$this->logs->insert_log(
			array(
				'recipient'    => $recipient,
				'message'      => $message,
				'status'       => $status,
				'api_response' => $data,
				'uid'          => $uid,
			)
		);
	}

	/**
	 * Try finding UID recursively in response.
	 *
	 * @param mixed $response Response value.
	 * @return string
	 */
	private function find_uid( $response ) {
		if ( ! is_array( $response ) ) {
			return '';
		}

		if ( isset( $response['uid'] ) ) {
			return sanitize_text_field( (string) $response['uid'] );
		}

		foreach ( $response as $value ) {
			if ( is_array( $value ) ) {
				$uid = $this->find_uid( $value );
				if ( ! empty( $uid ) ) {
					return $uid;
				}
			}
		}

		return '';
	}
}
