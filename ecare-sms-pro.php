<?php
/**
 * Plugin Name: Ecare SMS
 * Plugin URI: https://ecarehost.com
 * Description: Production-ready SMS automation plugin for WordPress and WooCommerce using Ecare SMS API.
 * Version: 1.0.0
 * Author: Sakif Istiak | Ecare Host
 * Author URI: https://ecarehost.com
 * Text Domain: ecare-sms-pro
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ECARE_SMS_PRO_VERSION', '1.0.0' );
define( 'ECARE_SMS_PRO_FILE', __FILE__ );
define( 'ECARE_SMS_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'ECARE_SMS_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'ECARE_SMS_PRO_OPTION_KEY', 'ecare_sms_pro_settings' );
define( 'ECARE_SMS_PRO_TABLE_LOGS', 'ecare_sms_pro_logs' );

require_once ECARE_SMS_PRO_PATH . 'includes/class-api.php';
require_once ECARE_SMS_PRO_PATH . 'includes/class-logs.php';
require_once ECARE_SMS_PRO_PATH . 'includes/class-sms.php';
require_once ECARE_SMS_PRO_PATH . 'includes/class-woocommerce.php';
require_once ECARE_SMS_PRO_PATH . 'admin/class-admin-menu.php';
require_once ECARE_SMS_PRO_PATH . 'admin/class-admin-pages.php';

/**
 * Main plugin bootstrap.
 */
class Ecare_SMS_Pro {

	/**
	 * API instance.
	 *
	 * @var Ecare_SMS_Pro_API
	 */
	private $api;

	/**
	 * Logs instance.
	 *
	 * @var Ecare_SMS_Pro_Logs
	 */
	private $logs;

	/**
	 * SMS service instance.
	 *
	 * @var Ecare_SMS_Pro_SMS
	 */
	private $sms;

	/**
	 * Admin pages instance.
	 *
	 * @var Ecare_SMS_Pro_Admin_Pages
	 */
	private $admin_pages;

	/**
	 * Init plugin.
	 */
	public function __construct() {
		$this->api  = new Ecare_SMS_Pro_API();
		$this->logs = new Ecare_SMS_Pro_Logs();
		$this->sms  = new Ecare_SMS_Pro_SMS( $this->api, $this->logs );

		if ( is_admin() ) {
			$admin_menu        = new Ecare_SMS_Pro_Admin_Menu();
			$this->admin_pages = new Ecare_SMS_Pro_Admin_Pages( $this->api, $this->sms, $this->logs, $admin_menu );
		}

		// Keep the plugin in manual test mode by default for easier debugging.

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'ecare-sms-pro', false, dirname( plugin_basename( ECARE_SMS_PRO_FILE ) ) . '/languages' );
	}

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public static function activate() {
		Ecare_SMS_Pro_Logs::create_table();

		$defaults = array(
			'api_token'         => '',
			'default_sender_id' => '',
			'sms_type'          => 'plain',
			'enable_logs'       => 1,
			'enable_debug'      => 1,
		);

		$current = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		$merged  = wp_parse_args( $current, $defaults );

		update_option( ECARE_SMS_PRO_OPTION_KEY, $merged );
	}
}

register_activation_hook( __FILE__, array( 'Ecare_SMS_Pro', 'activate' ) );

$GLOBALS['ecare_sms_pro'] = new Ecare_SMS_Pro();
