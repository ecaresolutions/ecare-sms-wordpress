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
	public function send_sms( $payload, $debug = array() ) {
		return $this->request( 'POST', '/sms/send', $payload, $debug );
	}

	/**
	 * Test API connectivity and token validity without sending SMS.
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		$response = $this->request( 'POST', '/sms/send', array() );

		if ( ! is_wp_error( $response ) ) {
			return array(
				'status'  => 'success',
				'message' => __( 'Connection successful.', 'ecare-sms-pro' ),
				'details' => $response,
			);
		}

		$error_code = $response->get_error_code();
		$error_data = $response->get_error_data();
		$http_code  = ( is_array( $error_data ) && isset( $error_data['http_code'] ) ) ? (int) $error_data['http_code'] : 0;

		// 4xx validation responses from this probe request confirm API reachability.
		if (
			'ecare_sms_api_error' === $error_code &&
			in_array( $http_code, array( 400, 404, 405, 422 ), true )
		) {
			return array(
				'status'  => 'success',
				'message' => __( 'Connection successful. API token appears valid.', 'ecare-sms-pro' ),
				'details' => $error_data,
			);
		}

		return $response;
	}

	/**
	 * Generic request wrapper.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Endpoint path.
	 * @param array  $body Request body.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body = array(), $debug = array() ) {
		$debug[] = array(
			'stage' => 'api_request_prepare',
			'path'  => $path,
		);

		$settings = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		$token    = $this->decrypt_token( isset( $settings['api_token'] ) ? $settings['api_token'] : '' );

		if ( empty( $token ) ) {
			$debug[] = array(
				'stage'   => 'api_token_missing',
				'message' => 'API token is empty in settings.',
			);

			return new WP_Error(
				'ecare_sms_token_missing',
				__( 'API token is missing in plugin settings.', 'ecare-sms-pro' ),
				array(
					'debug' => $debug,
				)
			);
		}

		$url = trailingslashit( $this->base_url ) . ltrim( $path, '/' );
		$debug[] = array(
			'stage' => 'api_url_ready',
			'url'   => $url,
		);

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
		$debug[] = array(
			'stage'   => 'api_request_dispatch',
			'method'  => strtoupper( $method ),
			'timeout' => isset( $args['timeout'] ) ? (int) $args['timeout'] : 30,
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$debug[] = array(
				'stage'   => 'api_transport_error',
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
			);

			return new WP_Error(
				'ecare_sms_http_error',
				$response->get_error_message(),
				array(
					'source' => 'wp_remote_request',
					'debug'  => $debug,
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		$debug[] = array(
			'stage'     => 'api_response_received',
			'http_code' => $code,
			'raw_body'  => $raw,
		);

		if ( null === $data && ! empty( $raw ) ) {
			$data = array(
				'status'  => 'error',
				'message' => __( 'Invalid JSON response from API.', 'ecare-sms-pro' ),
				'raw'     => $raw,
			);
			$debug[] = array(
				'stage'   => 'api_json_decode_failed',
				'message' => 'Response is not valid JSON.',
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['message'] ) ? (string) $data['message'] : __( 'API request failed.', 'ecare-sms-pro' );
			if ( false !== stripos( $message, 'Originator is not authorized' ) ) {
				return new WP_Error(
					'ecare_sms_sender_not_authorized',
					__( 'Sender ID is not authorized. Please update Sender ID in Ecare SMS settings.', 'ecare-sms-pro' ),
					array(
						'http_code' => $code,
						'response'  => $data,
						'debug'     => $debug,
					)
				);
			}

			return new WP_Error(
				'ecare_sms_api_error',
				$message,
				array(
					'http_code' => $code,
					'response'  => $data,
					'debug'     => $debug,
				)
			);
		}

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data['_debug'] = $debug;
		return $data;
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
