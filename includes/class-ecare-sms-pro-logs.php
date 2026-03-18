<?php
/**
 * Logs handler.
 *
 * @package EcareSMSPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles log storage and reporting.
 */
class Ecare_SMS_Pro_Logs {

	/**
	 * Create DB table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . ECARE_SMS_PRO_TABLE_LOGS;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			recipient TEXT NOT NULL,
			message LONGTEXT NOT NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'pending',
			api_response LONGTEXT NULL,
			uid VARCHAR(191) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY status (status),
			KEY uid (uid),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert a log entry.
	 *
	 * @param array $data Log data.
	 * @return int|false
	 */
	public function insert_log( $data ) {
		$settings = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		if ( isset( $settings['enable_logs'] ) && ! (int) $settings['enable_logs'] ) {
			return false;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . ECARE_SMS_PRO_TABLE_LOGS;

		$inserted = $wpdb->insert(
			$table_name,
			array(
				'recipient'    => isset( $data['recipient'] ) ? sanitize_text_field( $data['recipient'] ) : '',
				'message'      => isset( $data['message'] ) ? wp_kses_post( $data['message'] ) : '',
				'status'       => isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'pending',
				'api_response' => isset( $data['api_response'] ) ? wp_json_encode( $data['api_response'] ) : '',
				'uid'          => isset( $data['uid'] ) ? sanitize_text_field( $data['uid'] ) : '',
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get logs with filters and pagination.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function get_logs( $args = array() ) {
		global $wpdb;
		$table_name = $wpdb->prefix . ECARE_SMS_PRO_TABLE_LOGS;

		$defaults = array(
			'page'      => 1,
			'per_page'  => 20,
			'status'    => '',
			'date_from' => '',
			'date_to'   => '',
		);

		$args = wp_parse_args( $args, $defaults );

		$where   = 'WHERE 1=1';
		$prepare = array();

		if ( ! empty( $args['status'] ) ) {
			$where    .= ' AND status = %s';
			$prepare[] = sanitize_text_field( $args['status'] );
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where    .= ' AND DATE(created_at) >= %s';
			$prepare[] = sanitize_text_field( $args['date_from'] );
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where    .= ' AND DATE(created_at) <= %s';
			$prepare[] = sanitize_text_field( $args['date_to'] );
		}

		$total_sql = "SELECT COUNT(*) FROM {$table_name} {$where}";
		if ( ! empty( $prepare ) ) {
			$total_sql = $wpdb->prepare( $total_sql, $prepare );
		}

		$total = (int) $wpdb->get_var( $total_sql );

		$offset     = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
		$results_sql = "SELECT * FROM {$table_name} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$query_args  = array_merge( $prepare, array( (int) $args['per_page'], $offset ) );
		$results_sql = $wpdb->prepare( $results_sql, $query_args );
		$rows        = $wpdb->get_results( $results_sql, ARRAY_A );

		return array(
			'rows'      => $rows,
			'total'     => $total,
			'page'      => (int) $args['page'],
			'per_page'  => (int) $args['per_page'],
			'max_pages' => (int) ceil( $total / max( 1, (int) $args['per_page'] ) ),
		);
	}

	/**
	 * Get dashboard stats.
	 *
	 * @return array
	 */
	public function get_stats() {
		global $wpdb;
		$table_name = $wpdb->prefix . ECARE_SMS_PRO_TABLE_LOGS;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		$success = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE status = %s",
				'success'
			)
		);

		$recent = $wpdb->get_results( "SELECT id, recipient, status, created_at FROM {$table_name} ORDER BY id DESC LIMIT 8", ARRAY_A );

		$rate = 0;
		if ( $total > 0 ) {
			$rate = round( ( $success / $total ) * 100, 2 );
		}

		return array(
			'total'        => $total,
			'success'      => $success,
			'success_rate' => $rate,
			'recent'       => $recent,
		);
	}

	/**
	 * Decode response JSON safely.
	 *
	 * @param string $json JSON string.
	 * @return array
	 */
	public function decode_response( $json ) {
		if ( empty( $json ) ) {
			return array();
		}

		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
