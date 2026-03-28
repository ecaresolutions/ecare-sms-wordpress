<?php
/**
 * Uninstall routine for Ecare SMS.
 *
 * @package EcareSMSPro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$option_key = 'ecare_sms_pro_settings';
$table_key  = 'ecare_sms_pro_logs';

delete_option( $option_key );

global $wpdb;
if ( isset( $wpdb->prefix ) ) {
	$table_name = $wpdb->prefix . $table_key;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
}
