<?php
/**
 * API Client.
 *
 * @package EcareSMSPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles requests to Ecare SMS API.
 */
class Ecare_SMS_Pro_API {

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	private $base_url = 'https://send.ecaresms.com/api/v3';

	/**
	 * Send SMS API call.
	 *
	 * @param array $payload Request payload.
	 * @return array|WP_Error
	 */
	public function send_sms( $payload ) {
		return $this->request( 'POST', '/sms/send', $payload );
	}

	/**
	 * Send campaign API call.
	 *
	 * @param array $payload Request payload.
	 * @return array|WP_Error
	 */
	public function send_campaign( $payload ) {
		return $this->request( 'POST', '/sms/campaign', $payload );
	}

	/**
	 * Check SMS status.
	 *
	 * @param string $uid SMS uid.
	 * @return array|WP_Error
	 */
	public function get_sms_status( $uid ) {
		$uid = sanitize_text_field( $uid );
		if ( empty( $uid ) ) {
			return new WP_Error( 'ecare_sms_uid_missing', __( 'UID is required.', 'ecare-sms-pro' ) );
		}

		return $this->request( 'GET', '/sms/' . rawurlencode( $uid ) );
	}

	/**
	 * Fire a minimal test API call.
	 *
	 * @return array|WP_Error
	 */
	public function test_api() {
		$payload = array(
			'recipient' => '8801000000000',
			'sender_id' => 'TEST',
			'type'      => 'plain',
			'message'   => 'Ecare SMS Pro API Test',
		);

		return $this->request( 'POST', '/sms/send', $payload );
	}

	/**
	 * Generic request wrapper.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Endpoint path.
	 * @param array  $body Request body.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body = array() ) {
		$settings = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		$token    = $this->decrypt_token( isset( $settings['api_token'] ) ? $settings['api_token'] : '' );

		if ( empty( $token ) ) {
			return new WP_Error( 'ecare_sms_token_missing', __( 'API token is missing in plugin settings.', 'ecare-sms-pro' ) );
		}

		$url = trailingslashit( $this->base_url ) . ltrim( $path, '/' );

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ( 'POST' === strtoupper( $method ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$args = apply_filters( 'ecare_sms_pro_api_request_args', $args, $method, $path, $body );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( null === $data && ! empty( $raw ) ) {
			$data = array(
				'status'  => 'error',
				'message' => __( 'Invalid JSON response from API.', 'ecare-sms-pro' ),
				'raw'     => $raw,
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['message'] ) ? (string) $data['message'] : __( 'API request failed.', 'ecare-sms-pro' );
			return new WP_Error( 'ecare_sms_api_error', $message, array( 'http_code' => $code, 'response' => $data ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Encrypt API token for storage.
	 *
	 * @param string $token Plain token.
	 * @return string
	 */
	public function encrypt_token( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return '';
		}

		$key = hash( 'sha256', wp_salt( 'auth' ) );
		$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );

		if ( function_exists( 'openssl_encrypt' ) ) {
			$encrypted = openssl_encrypt( $token, 'AES-256-CBC', $key, 0, $iv );
			if ( false !== $encrypted ) {
				return 'enc:' . base64_encode( $encrypted );
			}
		}

		return 'plain:' . base64_encode( $token );
	}

	/**
	 * Decrypt API token from storage.
	 *
	 * @param string $stored Stored token.
	 * @return string
	 */
	public function decrypt_token( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, 'enc:' ) ) {
			$payload = base64_decode( substr( $stored, 4 ) );
			$key     = hash( 'sha256', wp_salt( 'auth' ) );
			$iv      = substr( hash( 'sha256', wp_salt( 'secure_auth' ) ), 0, 16 );

			if ( function_exists( 'openssl_decrypt' ) ) {
				$decrypted = openssl_decrypt( $payload, 'AES-256-CBC', $key, 0, $iv );
				if ( false !== $decrypted ) {
					return (string) $decrypted;
				}
			}
		}

		if ( 0 === strpos( $stored, 'plain:' ) ) {
			return (string) base64_decode( substr( $stored, 6 ) );
		}

		return $stored;
	}
}
