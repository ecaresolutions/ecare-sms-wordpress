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
		'send'      => 'ecare-sms-pro-send',
		'logs'      => 'ecare-sms-pro-logs',
		'settings'  => 'ecare-sms-pro-settings',
		'templates' => 'ecare-sms-pro-templates',
	);

	/**
	 * Return slug by key.
	 *
	 * @param string $key Slug key.
	 * @return string
	 */
	public function get_slug( $key ) {
		return isset( $this->slugs[ $key ] ) ? $this->slugs[ $key ] : $this->slugs['send'];
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
