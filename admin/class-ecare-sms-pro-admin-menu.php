<?php
/**
 * Admin menu registration.
 *
 * @package EcareSMSPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers plugin admin menus.
 */
class Ecare_SMS_Pro_Admin_Menu {

	/**
	 * Menu slug map.
	 *
	 * @var array
	 */
	private $slugs = array(
		'dashboard' => 'ecare-sms-pro-dashboard',
		'send'      => 'ecare-sms-pro-send',
		'bulk'      => 'ecare-sms-pro-bulk',
		'logs'      => 'ecare-sms-pro-logs',
		'status'    => 'ecare-sms-pro-status',
		'settings'  => 'ecare-sms-pro-settings',
	);

	/**
	 * Return slug by key.
	 *
	 * @param string $key Slug key.
	 * @return string
	 */
	public function get_slug( $key ) {
		return isset( $this->slugs[ $key ] ) ? $this->slugs[ $key ] : $this->slugs['dashboard'];
	}

	/**
	 * Get all slugs.
	 *
	 * @return array
	 */
	public function get_slugs() {
		return $this->slugs;
	}
}
