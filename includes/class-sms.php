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
		$debug     = array();
		$debug[]   = array(
			'stage'   => 'sms_send_start',
			'payload' => array(
				'recipient' => isset( $args['recipient'] ) ? (string) $args['recipient'] : '',
				'sender_id' => isset( $args['sender_id'] ) ? (string) $args['sender_id'] : '',
				'has_msg'   => ! empty( $args['message'] ),
			),
		);
		$recipients = $this->normalize_recipient_list( isset( $args['recipient'] ) ? $args['recipient'] : '' );
		$message    = isset( $args['message'] ) ? wp_strip_all_tags( $args['message'] ) : '';
		$type       = ! empty( $args['type'] ) ? sanitize_text_field( $args['type'] ) : ( isset( $settings['sms_type'] ) ? sanitize_text_field( $settings['sms_type'] ) : 'plain' );
		$provided_sender = isset( $args['sender_id'] ) ? sanitize_text_field( $args['sender_id'] ) : '';
		$default_sender  = isset( $settings['default_sender_id'] ) ? sanitize_text_field( $settings['default_sender_id'] ) : '';
		$sender_id       = $this->resolve_sender_id( $provided_sender, $default_sender );
		$schedule   = isset( $args['schedule_time'] ) ? sanitize_text_field( $args['schedule_time'] ) : '';
		$debug[]    = array(
			'stage'            => 'sms_input_normalized',
			'recipient_count'  => count( $recipients ),
			'resolved_sender'  => $sender_id,
			'schedule_enabled' => ! empty( $schedule ),
		);

		if ( empty( $recipients ) ) {
			$error = new WP_Error(
				'ecare_sms_recipients_missing',
				__( 'At least one valid recipient is required.', 'ecare-sms-pro' ),
				array( 'debug' => $debug )
			);
			$this->write_debug_log( array(), $error );
			return $error;
		}

		if ( empty( $sender_id ) ) {
			$error = new WP_Error(
				'ecare_sms_sender_missing',
				__( 'Sender ID is required. Please set a valid default Sender ID in settings.', 'ecare-sms-pro' ),
				array( 'debug' => $debug )
			);
			$this->write_debug_log( array(), $error );
			return $error;
		}

		if ( empty( $message ) ) {
			$error = new WP_Error(
				'ecare_sms_message_missing',
				__( 'Message cannot be empty.', 'ecare-sms-pro' ),
				array( 'debug' => $debug )
			);
			$this->write_debug_log( array(), $error );
			return $error;
		}

		$payload = array(
			'recipient' => implode( ',', $recipients ),
			'sender_id' => $sender_id,
			'type'      => $type,
			'message'   => $message,
		);

		if ( ! empty( $schedule ) ) {
			if ( ! $this->is_valid_schedule( $schedule ) ) {
				$error = new WP_Error(
					'ecare_sms_invalid_schedule',
					__( 'Schedule time format must be YYYY-MM-DD HH:MM.', 'ecare-sms-pro' ),
					array( 'debug' => $debug )
				);
				$this->write_debug_log( $payload, $error );
				return $error;
			}
			$payload['schedule_time'] = $schedule;
		}

		$payload  = apply_filters( 'ecare_sms_pro_send_payload', $payload, $args );
		$debug[]  = array(
			'stage'   => 'sms_payload_ready',
			'payload' => $payload,
		);
		$response = $this->api->send_sms( $payload, $debug );

		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			if ( empty( $data['debug'] ) || ! is_array( $data['debug'] ) ) {
				$data['debug'] = $debug;
			}
			$response->add_data( $data );
		}

		$this->log_result( $payload['recipient'], $message, $response );
		$this->write_debug_log( $payload, $response );

		do_action( 'ecare_sms_pro_sms_sent', $payload, $response );

		return $response;
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
	 * Resolve sender ID with fallback.
	 *
	 * @param string $provided_sender Sender from request.
	 * @param string $default_sender Sender from settings.
	 * @return string
	 */
	private function resolve_sender_id( $provided_sender, $default_sender ) {
		$provided_sender = $this->sanitize_sender_id( $provided_sender );
		$default_sender  = $this->sanitize_sender_id( $default_sender );

		if ( $this->is_valid_sender_id( $provided_sender ) ) {
			return $provided_sender;
		}

		if ( $this->is_valid_sender_id( $default_sender ) ) {
			return $default_sender;
		}

		return '';
	}

	/**
	 * Sanitize sender ID.
	 *
	 * @param string $sender_id Sender ID.
	 * @return string
	 */
	private function sanitize_sender_id( $sender_id ) {
		$sender_id = (string) $sender_id;
		$sender_id = trim( $sender_id );
		return preg_replace( '/[^A-Za-z0-9+]/', '', $sender_id );
	}

	/**
	 * Validate sender ID format.
	 *
	 * @param string $sender_id Sender ID.
	 * @return bool
	 */
	private function is_valid_sender_id( $sender_id ) {
		if ( '' === $sender_id ) {
			return false;
		}

		return 1 === preg_match( '/^[A-Za-z0-9+]{1,16}$/', $sender_id );
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

	/**
	 * Write debug information to file.
	 *
	 * @param array          $payload Request payload.
	 * @param array|WP_Error $response API response.
	 * @return void
	 */
	private function write_debug_log( $payload, $response ) {
		$settings = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		if ( empty( $settings['enable_debug'] ) ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = isset( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '';
		if ( empty( $base_dir ) ) {
			return;
		}

		$dir = trailingslashit( $base_dir ) . 'ecare-sms-pro';
		wp_mkdir_p( $dir );

		$entry = array(
			'time'    => current_time( 'mysql' ),
			'payload' => $payload,
		);

		if ( is_wp_error( $response ) ) {
			$entry['result'] = array(
				'type'    => 'error',
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
				'data'    => $response->get_error_data(),
			);
		} else {
			$entry['result'] = array(
				'type' => 'success',
				'data' => $response,
			);
		}

		error_log( wp_json_encode( $entry ) . PHP_EOL, 3, trailingslashit( $dir ) . 'debug.log' );
	}
}
