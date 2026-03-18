<?php
/**
 * Admin pages and handlers.
 *
 * @package EcareSMSPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin UI and actions.
 */
class Ecare_SMS_Pro_Admin_Pages {

	/**
	 * API class.
	 *
	 * @var Ecare_SMS_Pro_API
	 */
	private $api;

	/**
	 * SMS class.
	 *
	 * @var Ecare_SMS_Pro_SMS
	 */
	private $sms;

	/**
	 * Log class.
	 *
	 * @var Ecare_SMS_Pro_Logs
	 */
	private $logs;

	/**
	 * Menu class.
	 *
	 * @var Ecare_SMS_Pro_Admin_Menu
	 */
	private $menu;

	/**
	 * Constructor.
	 *
	 * @param Ecare_SMS_Pro_API        $api API class.
	 * @param Ecare_SMS_Pro_SMS        $sms SMS class.
	 * @param Ecare_SMS_Pro_Logs       $logs Logs class.
	 * @param Ecare_SMS_Pro_Admin_Menu $menu Menu class.
	 */
	public function __construct( Ecare_SMS_Pro_API $api, Ecare_SMS_Pro_SMS $sms, Ecare_SMS_Pro_Logs $logs, Ecare_SMS_Pro_Admin_Menu $menu ) {
		$this->api  = $api;
		$this->sms  = $sms;
		$this->logs = $logs;
		$this->menu = $menu;

		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_ecare_sms_pro_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );

		add_action( 'wp_ajax_ecare_sms_pro_send_sms', array( $this, 'ajax_send_sms' ) );
		add_action( 'wp_ajax_ecare_sms_pro_bulk_sms', array( $this, 'ajax_bulk_sms' ) );
		add_action( 'wp_ajax_ecare_sms_pro_send_campaign', array( $this, 'ajax_send_campaign' ) );
		add_action( 'wp_ajax_ecare_sms_pro_check_status', array( $this, 'ajax_check_status' ) );
		add_action( 'wp_ajax_ecare_sms_pro_test_api', array( $this, 'ajax_test_api' ) );
	}

	/**
	 * Register menus.
	 *
	 * @return void
	 */
	public function register_menus() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_menu_page(
			__( 'Ecare SMS Pro', 'ecare-sms-pro' ),
			__( 'Ecare SMS Pro', 'ecare-sms-pro' ),
			'manage_options',
			$this->menu->get_slug( 'dashboard' ),
			array( $this, 'render_dashboard_page' ),
			'dashicons-email-alt2',
			56
		);

		add_submenu_page( $this->menu->get_slug( 'dashboard' ), __( 'Dashboard', 'ecare-sms-pro' ), __( 'Dashboard', 'ecare-sms-pro' ), 'manage_options', $this->menu->get_slug( 'dashboard' ), array( $this, 'render_dashboard_page' ) );
		add_submenu_page( $this->menu->get_slug( 'dashboard' ), __( 'Send SMS', 'ecare-sms-pro' ), __( 'Send SMS', 'ecare-sms-pro' ), 'manage_options', $this->menu->get_slug( 'send' ), array( $this, 'render_send_sms_page' ) );
		add_submenu_page( $this->menu->get_slug( 'dashboard' ), __( 'Bulk SMS', 'ecare-sms-pro' ), __( 'Bulk SMS', 'ecare-sms-pro' ), 'manage_options', $this->menu->get_slug( 'bulk' ), array( $this, 'render_bulk_sms_page' ) );
		add_submenu_page( $this->menu->get_slug( 'dashboard' ), __( 'Logs', 'ecare-sms-pro' ), __( 'Logs', 'ecare-sms-pro' ), 'manage_options', $this->menu->get_slug( 'logs' ), array( $this, 'render_logs_page' ) );
		add_submenu_page( $this->menu->get_slug( 'dashboard' ), __( 'Status Checker', 'ecare-sms-pro' ), __( 'Status Checker', 'ecare-sms-pro' ), 'manage_options', $this->menu->get_slug( 'status' ), array( $this, 'render_status_page' ) );
		add_submenu_page( $this->menu->get_slug( 'dashboard' ), __( 'Settings', 'ecare-sms-pro' ), __( 'Settings', 'ecare-sms-pro' ), 'manage_options', $this->menu->get_slug( 'settings' ), array( $this, 'render_settings_page' ) );
	}

	/**
	 * Enqueue CSS/JS.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$allowed = array(
			'toplevel_page_' . $this->menu->get_slug( 'dashboard' ),
			'ecare-sms-pro_page_' . $this->menu->get_slug( 'send' ),
			'ecare-sms-pro_page_' . $this->menu->get_slug( 'bulk' ),
			'ecare-sms-pro_page_' . $this->menu->get_slug( 'logs' ),
			'ecare-sms-pro_page_' . $this->menu->get_slug( 'status' ),
			'ecare-sms-pro_page_' . $this->menu->get_slug( 'settings' ),
		);

		if ( ! in_array( $hook, $allowed, true ) ) {
			return;
		}

		wp_enqueue_style( 'ecare-sms-pro-admin', ECARE_SMS_PRO_URL . 'assets/css/admin.css', array(), ECARE_SMS_PRO_VERSION );
		wp_enqueue_script( 'ecare-sms-pro-admin', ECARE_SMS_PRO_URL . 'assets/js/admin.js', array( 'jquery' ), ECARE_SMS_PRO_VERSION, true );

		wp_localize_script(
			'ecare-sms-pro-admin',
			'EcareSMSPro',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ecare_sms_pro_ajax_nonce' ),
			)
		);
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard_page() {
		$this->assert_capability();
		$stats = $this->logs->get_stats();

		echo '<div class="wrap ecare-sms-pro-wrap">';
		$this->render_header( 'dashboard', __( 'Dashboard', 'ecare-sms-pro' ) );

		echo '<div class="ecare-grid">';
		echo '<div class="ecare-card"><h3>' . esc_html__( 'Total SMS Sent', 'ecare-sms-pro' ) . '</h3><strong>' . esc_html( number_format_i18n( $stats['total'] ) ) . '</strong></div>';
		echo '<div class="ecare-card"><h3>' . esc_html__( 'Successful SMS', 'ecare-sms-pro' ) . '</h3><strong>' . esc_html( number_format_i18n( $stats['success'] ) ) . '</strong></div>';
		echo '<div class="ecare-card"><h3>' . esc_html__( 'Success Rate', 'ecare-sms-pro' ) . '</h3><strong>' . esc_html( $stats['success_rate'] ) . '%</strong></div>';
		echo '</div>';

		echo '<div class="ecare-panel">';
		echo '<h2>' . esc_html__( 'Recent Activity', 'ecare-sms-pro' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Recipient', 'ecare-sms-pro' ) . '</th><th>' . esc_html__( 'Status', 'ecare-sms-pro' ) . '</th><th>' . esc_html__( 'Date', 'ecare-sms-pro' ) . '</th></tr></thead><tbody>';

		if ( empty( $stats['recent'] ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No logs yet.', 'ecare-sms-pro' ) . '</td></tr>';
		} else {
			foreach ( $stats['recent'] as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( $row['id'] ) . '</td>';
				echo '<td>' . esc_html( $row['recipient'] ) . '</td>';
				echo '<td><span class="ecare-badge status-' . esc_attr( $row['status'] ) . '">' . esc_html( ucfirst( $row['status'] ) ) . '</span></td>';
				echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table></div></div>';
	}

	/**
	 * Render send SMS page.
	 *
	 * @return void
	 */
	public function render_send_sms_page() {
		$this->assert_capability();

		echo '<div class="wrap ecare-sms-pro-wrap">';
		$this->render_header( 'send', __( 'Send Single SMS', 'ecare-sms-pro' ) );
		echo '<div class="ecare-panel">';
		echo '<form id="ecare-send-sms-form" class="ecare-form">';
		echo '<input type="hidden" name="action" value="ecare_sms_pro_send_sms" />';
		echo '<label>' . esc_html__( 'Recipient Number', 'ecare-sms-pro' ) . '</label><input type="text" name="recipient" required placeholder="8801XXXXXXXXX" />';
		echo '<label>' . esc_html__( 'Sender ID (optional)', 'ecare-sms-pro' ) . '</label><input type="text" name="sender_id" maxlength="11" />';
		echo '<label>' . esc_html__( 'Message', 'ecare-sms-pro' ) . '</label><textarea name="message" rows="5" required></textarea>';
		echo '<label>' . esc_html__( 'Schedule Time (YYYY-MM-DD HH:MM)', 'ecare-sms-pro' ) . '</label><input type="text" name="schedule_time" placeholder="2026-03-19 18:30" />';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Send SMS', 'ecare-sms-pro' ) . '</button>';
		echo '<div class="ecare-ajax-result" aria-live="polite"></div>';
		echo '</form></div></div>';
	}

	/**
	 * Render bulk SMS page.
	 *
	 * @return void
	 */
	public function render_bulk_sms_page() {
		$this->assert_capability();
		$roles = wp_roles()->roles;

		echo '<div class="wrap ecare-sms-pro-wrap">';
		$this->render_header( 'bulk', __( 'Bulk SMS & Campaigns', 'ecare-sms-pro' ) );

		echo '<div class="ecare-grid ecare-grid-2">';
		echo '<div class="ecare-panel"><h2>' . esc_html__( 'Bulk SMS', 'ecare-sms-pro' ) . '</h2>';
		echo '<form id="ecare-bulk-sms-form" class="ecare-form">';
		echo '<input type="hidden" name="action" value="ecare_sms_pro_bulk_sms" />';
		echo '<label>' . esc_html__( 'Manual Numbers', 'ecare-sms-pro' ) . '</label><textarea name="numbers" rows="4" placeholder="8801...,8801..."></textarea>';
		echo '<label>' . esc_html__( 'Contact Source', 'ecare-sms-pro' ) . '</label>';
		echo '<select name="source"><option value="manual">' . esc_html__( 'Manual only', 'ecare-sms-pro' ) . '</option><option value="users">' . esc_html__( 'WordPress users', 'ecare-sms-pro' ) . '</option><option value="customers">' . esc_html__( 'WooCommerce customers', 'ecare-sms-pro' ) . '</option></select>';
		echo '<label>' . esc_html__( 'Role Filter (optional)', 'ecare-sms-pro' ) . '</label>';
		echo '<select name="role"><option value="">' . esc_html__( 'All roles', 'ecare-sms-pro' ) . '</option>';
		foreach ( $roles as $key => $role ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $role['name'] ) . '</option>';
		}
		echo '</select>';
		echo '<label>' . esc_html__( 'Message', 'ecare-sms-pro' ) . '</label><textarea name="message" rows="5" required></textarea>';
		echo '<label>' . esc_html__( 'Schedule Time (YYYY-MM-DD HH:MM)', 'ecare-sms-pro' ) . '</label><input type="text" name="schedule_time" placeholder="2026-03-19 18:30" />';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Send Bulk SMS', 'ecare-sms-pro' ) . '</button>';
		echo '<div class="ecare-ajax-result" aria-live="polite"></div>';
		echo '</form></div>';

		echo '<div class="ecare-panel"><h2>' . esc_html__( 'Campaign by Contact List', 'ecare-sms-pro' ) . '</h2>';
		echo '<form id="ecare-campaign-form" class="ecare-form">';
		echo '<input type="hidden" name="action" value="ecare_sms_pro_send_campaign" />';
		echo '<label>' . esc_html__( 'Contact List ID(s)', 'ecare-sms-pro' ) . '</label><input type="text" name="contact_list_id" required placeholder="6415907d0d37a,6415907d0d7a6" />';
		echo '<label>' . esc_html__( 'Message', 'ecare-sms-pro' ) . '</label><textarea name="message" rows="5" required></textarea>';
		echo '<label>' . esc_html__( 'Schedule Time (YYYY-MM-DD HH:MM)', 'ecare-sms-pro' ) . '</label><input type="text" name="schedule_time" placeholder="2026-03-19 18:30" />';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Send Campaign', 'ecare-sms-pro' ) . '</button>';
		echo '<div class="ecare-ajax-result" aria-live="polite"></div>';
		echo '</form></div>';
		echo '</div></div>';
	}

	/**
	 * Render logs page.
	 *
	 * @return void
	 */
	public function render_logs_page() {
		$this->assert_capability();

		$page      = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$status    = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';

		$result = $this->logs->get_logs(
			array(
				'page'      => $page,
				'per_page'  => 20,
				'status'    => $status,
				'date_from' => $date_from,
				'date_to'   => $date_to,
			)
		);

		echo '<div class="wrap ecare-sms-pro-wrap">';
		$this->render_header( 'logs', __( 'SMS Logs', 'ecare-sms-pro' ) );

		echo '<div class="ecare-panel">';
		echo '<form method="get" class="ecare-filter-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $this->menu->get_slug( 'logs' ) ) . '" />';
		echo '<select name="status"><option value="">' . esc_html__( 'All Status', 'ecare-sms-pro' ) . '</option><option value="success" ' . selected( $status, 'success', false ) . '>Success</option><option value="failed" ' . selected( $status, 'failed', false ) . '>Failed</option></select>';
		echo '<input type="date" name="date_from" value="' . esc_attr( $date_from ) . '" />';
		echo '<input type="date" name="date_to" value="' . esc_attr( $date_to ) . '" />';
		echo '<button class="button">' . esc_html__( 'Filter', 'ecare-sms-pro' ) . '</button>';
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Recipient', 'ecare-sms-pro' ) . '</th><th>' . esc_html__( 'Message', 'ecare-sms-pro' ) . '</th><th>' . esc_html__( 'Status', 'ecare-sms-pro' ) . '</th><th>UID</th><th>' . esc_html__( 'Date', 'ecare-sms-pro' ) . '</th><th>' . esc_html__( 'Response', 'ecare-sms-pro' ) . '</th></tr></thead><tbody>';

		if ( empty( $result['rows'] ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No logs found.', 'ecare-sms-pro' ) . '</td></tr>';
		} else {
			foreach ( $result['rows'] as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( $row['id'] ) . '</td>';
				echo '<td>' . esc_html( $row['recipient'] ) . '</td>';
				echo '<td>' . esc_html( wp_trim_words( $row['message'], 14, '...' ) ) . '</td>';
				echo '<td><span class="ecare-badge status-' . esc_attr( $row['status'] ) . '">' . esc_html( ucfirst( $row['status'] ) ) . '</span></td>';
				echo '<td>' . esc_html( $row['uid'] ) . '</td>';
				echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
				echo '<td><details><summary>' . esc_html__( 'View', 'ecare-sms-pro' ) . '</summary><pre>' . esc_html( wp_json_encode( $this->logs->decode_response( $row['api_response'] ), JSON_PRETTY_PRINT ) ) . '</pre></details></td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		if ( $result['max_pages'] > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links(
				array(
					'base'      => add_query_arg(
						array(
							'page'      => $this->menu->get_slug( 'logs' ),
							'status'    => $status,
							'date_from' => $date_from,
							'date_to'   => $date_to,
							'paged'     => '%#%',
						),
						admin_url( 'admin.php' )
					),
					'current'   => $result['page'],
					'total'     => $result['max_pages'],
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			);
			echo '</div></div>';
		}

		echo '</div></div>';
	}

	/**
	 * Render status checker page.
	 *
	 * @return void
	 */
	public function render_status_page() {
		$this->assert_capability();

		echo '<div class="wrap ecare-sms-pro-wrap">';
		$this->render_header( 'status', __( 'SMS Status Checker', 'ecare-sms-pro' ) );
		echo '<div class="ecare-panel">';
		echo '<form id="ecare-status-form" class="ecare-form">';
		echo '<input type="hidden" name="action" value="ecare_sms_pro_check_status" />';
		echo '<label>' . esc_html__( 'Message UID', 'ecare-sms-pro' ) . '</label><input type="text" name="uid" required />';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Check Status', 'ecare-sms-pro' ) . '</button>';
		echo '<div class="ecare-ajax-result" aria-live="polite"></div>';
		echo '</form></div></div>';
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$this->assert_capability();

		$settings = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );

		echo '<div class="wrap ecare-sms-pro-wrap">';
		$this->render_header( 'settings', __( 'Settings', 'ecare-sms-pro' ) );
		echo '<div class="ecare-panel">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ecare-form">';
		echo '<input type="hidden" name="action" value="ecare_sms_pro_save_settings" />';
		wp_nonce_field( 'ecare_sms_pro_save_settings', 'ecare_sms_pro_settings_nonce' );

		echo '<h2>' . esc_html__( 'API Settings', 'ecare-sms-pro' ) . '</h2>';
		echo '<label>' . esc_html__( 'API Token', 'ecare-sms-pro' ) . '</label>';
		echo '<input type="password" name="api_token" autocomplete="new-password" placeholder="••••••••" />';
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the existing token.', 'ecare-sms-pro' ) . '</p>';
		echo '<label>' . esc_html__( 'Default Sender ID', 'ecare-sms-pro' ) . '</label><input type="text" name="default_sender_id" maxlength="11" value="' . esc_attr( isset( $settings['default_sender_id'] ) ? $settings['default_sender_id'] : '' ) . '" />';
		echo '<label>' . esc_html__( 'SMS Type', 'ecare-sms-pro' ) . '</label><select name="sms_type"><option value="plain" ' . selected( isset( $settings['sms_type'] ) ? $settings['sms_type'] : 'plain', 'plain', false ) . '>plain</option></select>';
		echo '<label><input type="checkbox" name="enable_logs" value="1" ' . checked( isset( $settings['enable_logs'] ) ? (int) $settings['enable_logs'] : 1, 1, false ) . ' /> ' . esc_html__( 'Enable Logs', 'ecare-sms-pro' ) . '</label>';
		echo '<label><input type="checkbox" name="dark_mode" value="1" ' . checked( isset( $settings['dark_mode'] ) ? (int) $settings['dark_mode'] : 0, 1, false ) . ' /> ' . esc_html__( 'Enable Dark Mode', 'ecare-sms-pro' ) . '</label>';

		echo '<h2>' . esc_html__( 'WooCommerce Automation', 'ecare-sms-pro' ) . '</h2>';
		echo '<label><input type="checkbox" name="wc_order_placed_enabled" value="1" ' . checked( isset( $settings['wc_order_placed_enabled'] ) ? (int) $settings['wc_order_placed_enabled'] : 1, 1, false ) . ' /> ' . esc_html__( 'Send SMS on Order Placed', 'ecare-sms-pro' ) . '</label>';
		echo '<label>' . esc_html__( 'Order Placed Template', 'ecare-sms-pro' ) . '</label><textarea name="wc_order_placed_template" rows="3">' . esc_textarea( isset( $settings['wc_order_placed_template'] ) ? $settings['wc_order_placed_template'] : '' ) . '</textarea>';
		echo '<label><input type="checkbox" name="wc_status_changed_enabled" value="1" ' . checked( isset( $settings['wc_status_changed_enabled'] ) ? (int) $settings['wc_status_changed_enabled'] : 1, 1, false ) . ' /> ' . esc_html__( 'Send SMS on Order Status Change', 'ecare-sms-pro' ) . '</label>';
		echo '<label>' . esc_html__( 'Status Changed Template', 'ecare-sms-pro' ) . '</label><textarea name="wc_status_changed_template" rows="3">' . esc_textarea( isset( $settings['wc_status_changed_template'] ) ? $settings['wc_status_changed_template'] : '' ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Supported placeholders: {order_id}, {customer_name}, {total}, {order_status}', 'ecare-sms-pro' ) . '</p>';

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save Settings', 'ecare-sms-pro' ) . '</button>';
		echo '<button type="button" class="button button-secondary" id="ecare-test-api">' . esc_html__( 'Test API', 'ecare-sms-pro' ) . '</button>';
		echo '<div class="ecare-ajax-result" id="ecare-test-result" aria-live="polite"></div>';
		echo '</form></div></div>';
	}

	/**
	 * Save plugin settings.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		$this->assert_capability();

		check_admin_referer( 'ecare_sms_pro_save_settings', 'ecare_sms_pro_settings_nonce' );

		$current = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );

		$updated = array(
			'api_token'                 => isset( $current['api_token'] ) ? $current['api_token'] : '',
			'default_sender_id'         => isset( $_POST['default_sender_id'] ) ? sanitize_text_field( wp_unslash( $_POST['default_sender_id'] ) ) : '',
			'sms_type'                  => isset( $_POST['sms_type'] ) ? sanitize_text_field( wp_unslash( $_POST['sms_type'] ) ) : 'plain',
			'enable_logs'               => isset( $_POST['enable_logs'] ) ? 1 : 0,
			'wc_order_placed_enabled'   => isset( $_POST['wc_order_placed_enabled'] ) ? 1 : 0,
			'wc_status_changed_enabled' => isset( $_POST['wc_status_changed_enabled'] ) ? 1 : 0,
			'wc_order_placed_template'  => isset( $_POST['wc_order_placed_template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wc_order_placed_template'] ) ) : '',
			'wc_status_changed_template'=> isset( $_POST['wc_status_changed_template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wc_status_changed_template'] ) ) : '',
			'dark_mode'                 => isset( $_POST['dark_mode'] ) ? 1 : 0,
		);

		if ( isset( $_POST['api_token'] ) ) {
			$api_token = trim( (string) wp_unslash( $_POST['api_token'] ) );
			if ( '' !== $api_token ) {
				$updated['api_token'] = $this->api->encrypt_token( $api_token );
			}
		}

		$updated = apply_filters( 'ecare_sms_pro_settings_before_save', $updated, $current );

		update_option( ECARE_SMS_PRO_OPTION_KEY, $updated );
		$this->set_notice( __( 'Settings saved successfully.', 'ecare-sms-pro' ), 'success' );

		wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu->get_slug( 'settings' ) ) );
		exit;
	}

	/**
	 * AJAX send single SMS.
	 *
	 * @return void
	 */
	public function ajax_send_sms() {
		$this->ajax_guard();

		$response = $this->sms->send_sms(
			array(
				'recipient'     => isset( $_POST['recipient'] ) ? wp_unslash( $_POST['recipient'] ) : '',
				'sender_id'     => isset( $_POST['sender_id'] ) ? wp_unslash( $_POST['sender_id'] ) : '',
				'message'       => isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '',
				'schedule_time' => isset( $_POST['schedule_time'] ) ? wp_unslash( $_POST['schedule_time'] ) : '',
			)
		);

		$this->send_ajax_response( $response );
	}

	/**
	 * AJAX bulk SMS.
	 *
	 * @return void
	 */
	public function ajax_bulk_sms() {
		$this->ajax_guard();

		$numbers = isset( $_POST['numbers'] ) ? wp_unslash( $_POST['numbers'] ) : '';
		$source  = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'manual';
		$role    = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';

		if ( 'manual' !== $source ) {
			$auto_numbers = $this->sms->get_contacts_by_source( $source, $role );
			$numbers     .= ',' . implode( ',', $auto_numbers );
		}

		$response = $this->sms->send_sms(
			array(
				'recipient'     => $numbers,
				'message'       => isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '',
				'schedule_time' => isset( $_POST['schedule_time'] ) ? wp_unslash( $_POST['schedule_time'] ) : '',
			)
		);

		$this->send_ajax_response( $response );
	}

	/**
	 * AJAX campaign sender.
	 *
	 * @return void
	 */
	public function ajax_send_campaign() {
		$this->ajax_guard();

		$response = $this->sms->send_campaign(
			array(
				'contact_list_id' => isset( $_POST['contact_list_id'] ) ? wp_unslash( $_POST['contact_list_id'] ) : '',
				'message'         => isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '',
				'schedule_time'   => isset( $_POST['schedule_time'] ) ? wp_unslash( $_POST['schedule_time'] ) : '',
			)
		);

		$this->send_ajax_response( $response );
	}

	/**
	 * AJAX status checker.
	 *
	 * @return void
	 */
	public function ajax_check_status() {
		$this->ajax_guard();

		$uid      = isset( $_POST['uid'] ) ? wp_unslash( $_POST['uid'] ) : '';
		$response = $this->sms->check_status( $uid );

		$this->send_ajax_response( $response );
	}

	/**
	 * AJAX test API.
	 *
	 * @return void
	 */
	public function ajax_test_api() {
		$this->ajax_guard();

		$response = $this->api->test_api();
		$this->send_ajax_response( $response );
	}

	/**
	 * Common AJAX security guard.
	 *
	 * @return void
	 */
	private function ajax_guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ecare-sms-pro' ) ), 403 );
		}

		check_ajax_referer( 'ecare_sms_pro_ajax_nonce', 'nonce' );
	}

	/**
	 * Standard response wrapper for AJAX.
	 *
	 * @param array|WP_Error $response API response.
	 * @return void
	 */
	private function send_ajax_response( $response ) {
		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message' => $response->get_error_message(),
					'data'    => $response->get_error_data(),
				),
				400
			);
		}

		$message = isset( $response['message'] ) ? $response['message'] : __( 'Request completed successfully.', 'ecare-sms-pro' );
		wp_send_json_success(
			array(
				'message'  => $message,
				'response' => $response,
			)
		);
	}

	/**
	 * Ensure access.
	 *
	 * @return void
	 */
	private function assert_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ecare-sms-pro' ) );
		}
	}

	/**
	 * Render top header and nav tabs.
	 *
	 * @param string $active Active key.
	 * @param string $title Title.
	 * @return void
	 */
	private function render_header( $active, $title ) {
		$slugs = $this->menu->get_slugs();
		$tabs  = array(
			'dashboard' => __( 'Dashboard', 'ecare-sms-pro' ),
			'send'      => __( 'Send SMS', 'ecare-sms-pro' ),
			'bulk'      => __( 'Bulk SMS', 'ecare-sms-pro' ),
			'logs'      => __( 'Logs', 'ecare-sms-pro' ),
			'status'    => __( 'Status Checker', 'ecare-sms-pro' ),
			'settings'  => __( 'Settings', 'ecare-sms-pro' ),
		);

		$settings = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		$mode     = ! empty( $settings['dark_mode'] ) ? ' ecare-dark' : '';

		echo '<div class="ecare-header' . esc_attr( $mode ) . '">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$cls = ( $active === $key ) ? ' nav-tab nav-tab-active' : ' nav-tab';
			echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slugs[ $key ] ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav></div>';
	}

	/**
	 * Save admin notice.
	 *
	 * @param string $message Message.
	 * @param string $type success|error|warning|info.
	 * @return void
	 */
	private function set_notice( $message, $type = 'success' ) {
		$user_id = get_current_user_id();
		set_transient( 'ecare_sms_pro_notice_' . $user_id, array( 'message' => $message, 'type' => $type ), 30 );
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$notice  = get_transient( 'ecare_sms_pro_notice_' . $user_id );
		if ( empty( $notice ) || ! is_array( $notice ) ) {
			return;
		}

		delete_transient( 'ecare_sms_pro_notice_' . $user_id );
		$type = isset( $notice['type'] ) ? sanitize_html_class( $notice['type'] ) : 'info';

		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}
}
