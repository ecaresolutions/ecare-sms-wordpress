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
		add_action( 'wp_ajax_ecare_sms_pro_bulk_send_sms', array( $this, 'ajax_bulk_send_sms' ) );
		add_action( 'wp_ajax_ecare_sms_pro_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_ecare_sms_pro_check_balance', array( $this, 'ajax_check_balance' ) );
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
			__( 'Ecare SMS', 'ecare-sms-pro' ),
			__( 'Ecare SMS', 'ecare-sms-pro' ),
			'manage_options',
			$this->menu->get_slug( 'send' ),
			array( $this, 'render_send_sms_page' ),
			'dashicons-email-alt2',
			56
		);

		add_submenu_page( $this->menu->get_slug( 'send' ), __( 'Send SMS', 'ecare-sms-pro' ), __( 'Send SMS', 'ecare-sms-pro' ), 'manage_options', $this->menu->get_slug( 'send' ), array( $this, 'render_send_sms_page' ) );
		add_submenu_page( $this->menu->get_slug( 'send' ), __( 'Logs', 'ecare-sms-pro' ), __( 'Logs', 'ecare-sms-pro' ), 'manage_options', $this->menu->get_slug( 'logs' ), array( $this, 'render_logs_page' ) );
		add_submenu_page( $this->menu->get_slug( 'send' ), __( 'Settings', 'ecare-sms-pro' ), __( 'Settings', 'ecare-sms-pro' ), 'manage_options', $this->menu->get_slug( 'settings' ), array( $this, 'render_settings_page' ) );
	}

	/**
	 * Enqueue CSS/JS.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$page  = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$slugs = $this->menu->get_slugs();

		if ( ! in_array( $page, $slugs, true ) && 'toplevel_page_' . $this->menu->get_slug( 'send' ) !== $hook ) {
			return;
		}

		wp_enqueue_style( 'ecare-sms-pro-font-bn', 'https://fonts.googleapis.com/css2?family=Noto+Serif+Bengali:wght@400;500;600;700&display=swap', array(), null );
		wp_enqueue_style( 'ecare-sms-pro-admin', ECARE_SMS_PRO_URL . 'assets/css/admin.css', array(), ECARE_SMS_PRO_VERSION );
		wp_enqueue_script( 'ecare-sms-pro-admin', ECARE_SMS_PRO_URL . 'assets/js/admin.js', array( 'jquery' ), ECARE_SMS_PRO_VERSION, true );

		wp_localize_script(
			'ecare-sms-pro-admin',
			'EcareSMSPro',
			array(
				'ajaxUrl'                  => admin_url( 'admin-ajax.php' ),
				'nonce'                    => wp_create_nonce( 'ecare_sms_pro_ajax_nonce' ),
				'toggleShowApiLabel'       => __( 'Show API Token', 'ecare-sms-pro' ),
				'toggleHideApiLabel'       => __( 'Hide API Token', 'ecare-sms-pro' ),
				'testConnectionChecking'   => __( 'Checking...', 'ecare-sms-pro' ),
				'testConnectionButtonText' => __( 'Test Connection', 'ecare-sms-pro' ),
				'balanceChecking'          => __( 'Checking Balance...', 'ecare-sms-pro' ),
				'balanceButtonText'        => __( 'Check SMS Balance', 'ecare-sms-pro' ),
				'bulkSendingText'          => __( 'Sending Bulk SMS...', 'ecare-sms-pro' ),
				'bulkButtonText'           => __( 'Send Bulk SMS', 'ecare-sms-pro' ),
			)
		);
	}

	/**
	 * Render send SMS page.
	 *
	 * @return void
	 */
	public function render_send_sms_page() {
		$this->assert_capability();
		$settings       = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		$default_sender = isset( $settings['default_sender_id'] ) ? sanitize_text_field( (string) $settings['default_sender_id'] ) : '';
		$locale         = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$is_bangla      = 0 === strpos( strtolower( (string) $locale ), 'bn' );
		$lang_class     = $is_bangla ? 'ecare-lang-bn' : 'ecare-lang-en';
		$stats          = $this->logs->get_stats();
		$today          = current_time( 'Y-m-d' );
		$today_result   = $this->logs->get_logs(
			array(
				'page'      => 1,
				'per_page'  => 1,
				'date_from' => $today,
				'date_to'   => $today,
			)
		);
		$today_total    = isset( $today_result['total'] ) ? (int) $today_result['total'] : 0;
		$success_rate   = isset( $stats['success_rate'] ) ? (float) $stats['success_rate'] : 0;
		$is_api_ready   = ! empty( $settings['api_token'] );

		echo '<div class="wrap ecare-sms-pro-shell ' . esc_attr( $lang_class ) . '">';
		echo '<h1>' . esc_html__( 'Ecare SMS Gateway', 'ecare-sms-pro' ) . '</h1>';
		$this->render_tabs( 'send' );

		echo '<section class="ecare-sms-pro-hero">';
		echo '<div class="ecare-sms-pro-hero-left">';
		echo '<h2>' . esc_html__( 'SMS Automation in One Place', 'ecare-sms-pro' ) . '</h2>';
		echo '<p>' . esc_html__( 'Quick test, bulk send, and delivery monitoring with a custom control center experience.', 'ecare-sms-pro' ) . '</p>';
		echo '</div>';
		echo '<div class="ecare-sms-pro-hero-actions">';
		echo '<a class="ecare-btn ecare-btn-ghost" href="' . esc_url( 'https://send.ecaresms.com/developers' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'ecare-sms-pro' ) . '</a>';
		echo '<a class="ecare-btn ecare-btn-solid" href="' . esc_url( admin_url( 'admin.php?page=' . $this->menu->get_slug( 'settings' ) ) ) . '">' . esc_html__( 'Settings', 'ecare-sms-pro' ) . '</a>';
		echo '</div>';
		echo '</section>';

		echo '<div class="ecare-sms-pro-cta-box">';
		echo '<p><strong>' . esc_html__( 'No ecaresms.com account yet?', 'ecare-sms-pro' ) . '</strong> ' . esc_html__( 'Create an account to start sending SMS from this plugin.', 'ecare-sms-pro' ) . '</p>';
		echo '<a class="ecare-btn ecare-btn-accent" href="' . esc_url( 'https://ecaresms.com' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Create Account', 'ecare-sms-pro' ) . '</a>';
		echo '</div>';

		echo '<div class="ecare-sms-pro-stats-grid">';
		echo '<div class="ecare-sms-pro-stat-card ecare-sms-pro-stat-blue"><p>' . esc_html__( 'Sender ID', 'ecare-sms-pro' ) . '</p><strong>' . esc_html( '' !== $default_sender ? $default_sender : __( 'Not Set', 'ecare-sms-pro' ) ) . '</strong></div>';
		echo '<div class="ecare-sms-pro-stat-card ecare-sms-pro-stat-green"><p>' . esc_html__( 'Sent Today', 'ecare-sms-pro' ) . '</p><strong>' . esc_html( number_format_i18n( $today_total ) ) . '</strong></div>';
		echo '<div class="ecare-sms-pro-stat-card ecare-sms-pro-stat-orange"><p>' . esc_html__( 'Success Rate', 'ecare-sms-pro' ) . '</p><strong>' . esc_html( number_format_i18n( $success_rate, 2 ) ) . '%</strong></div>';
		echo '<div class="ecare-sms-pro-stat-card ecare-sms-pro-stat-slate"><p>' . esc_html__( 'API Status', 'ecare-sms-pro' ) . '</p><strong>' . esc_html( $is_api_ready ? __( 'Ready', 'ecare-sms-pro' ) : __( 'Token Missing', 'ecare-sms-pro' ) ) . '</strong></div>';
		echo '</div>';

		echo '<div id="ecare-sms-pro-ajax-notice"></div>';
		echo '<div class="ecare-sms-pro-send-grid">';

		echo '<div class="ecare-sms-pro-card">';
		echo '<div class="ecare-sms-pro-card-head"><h2>' . esc_html__( 'Quick SMS Test', 'ecare-sms-pro' ) . '</h2><span>' . esc_html__( 'Single / multiple recipients', 'ecare-sms-pro' ) . '</span></div>';
		echo '<form id="ecare-send-sms-form">';
		echo '<input type="hidden" name="action" value="ecare_sms_pro_send_sms" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="ecare_recipient">' . esc_html__( 'Recipient Number', 'ecare-sms-pro' ) . '</label></th><td>';
		echo '<input type="text" id="ecare_recipient" name="recipient" class="regular-text" placeholder="8801XXXXXXXXX" />';
		echo '<p class="description">' . esc_html__( 'For multiple numbers, separate with comma or newline.', 'ecare-sms-pro' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ecare_message">' . esc_html__( 'Message', 'ecare-sms-pro' ) . '</label></th><td>';
		echo '<textarea id="ecare_message" name="message" class="large-text" rows="6"></textarea>';
		if ( '' !== $default_sender ) {
			echo '<p class="description">' . sprintf( esc_html__( 'Using default Sender ID from settings: %s', 'ecare-sms-pro' ), esc_html( $default_sender ) ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Default Sender ID is not set. Add it from Settings to send SMS.', 'ecare-sms-pro' ) . '</p>';
		}
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Send Test SMS', 'ecare-sms-pro' ), 'primary', 'ecare_send_submit', false, array( 'id' => 'ecare-send-submit' ) );
		echo '<span class="spinner" id="ecare-send-spinner"></span>';
		echo '</form>';
		echo '</div>';

		echo '<div class="ecare-sms-pro-card">';
		echo '<div class="ecare-sms-pro-card-head"><h2>' . esc_html__( 'Bulk SMS Sending', 'ecare-sms-pro' ) . '</h2><span>' . esc_html__( 'Manual list + CSV/XLSX upload', 'ecare-sms-pro' ) . '</span></div>';
		echo '<form id="ecare-bulk-sms-form" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="ecare_sms_pro_bulk_send_sms" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="ecare_bulk_numbers">' . esc_html__( 'Manual Numbers', 'ecare-sms-pro' ) . '</label></th><td>';
		echo '<textarea id="ecare_bulk_numbers" name="manual_numbers" class="large-text" rows="5" placeholder="8801XXXXXXXXX, 8801YYYYYYYY"></textarea>';
		echo '<p class="description">' . esc_html__( 'Add numbers manually (comma, space, or new line separated).', 'ecare-sms-pro' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ecare_bulk_file">' . esc_html__( 'CSV / Excel File', 'ecare-sms-pro' ) . '</label></th><td>';
		echo '<input type="file" id="ecare_bulk_file" name="contacts_file" accept=".csv,.txt,.xlsx" />';
		echo '<p class="description">' . esc_html__( 'Supported formats: CSV, TXT, XLSX. Use a phone/mobile/number column in the first row for best results.', 'ecare-sms-pro' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ecare_bulk_message">' . esc_html__( 'Dynamic Message', 'ecare-sms-pro' ) . '</label></th><td>';
		echo '<textarea id="ecare_bulk_message" name="message" class="large-text" rows="6"></textarea>';
		echo '<p class="description">' . esc_html__( 'Supported placeholders: {number}, {index}, {site_name}.', 'ecare-sms-pro' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Send Bulk SMS', 'ecare-sms-pro' ), 'secondary', 'ecare_bulk_send_submit', false, array( 'id' => 'ecare-bulk-send-submit' ) );
		echo '<span class="spinner" id="ecare-bulk-send-spinner"></span>';
		echo '</form>';
		echo '<div id="ecare-sms-pro-bulk-summary" class="ecare-sms-pro-hidden"><h3>' . esc_html__( 'Bulk Result', 'ecare-sms-pro' ) . '</h3><pre></pre></div>';
		echo '</div>';
		echo '</div>';

		echo '<div id="ecare-sms-pro-response" class="ecare-sms-pro-hidden"><h2>' . esc_html__( 'API Response', 'ecare-sms-pro' ) . '</h2><pre></pre></div>';
		echo '<div id="ecare-sms-pro-debug" class="ecare-sms-pro-hidden"><h2>' . esc_html__( 'Debug Trace', 'ecare-sms-pro' ) . '</h2><pre></pre></div>';
		echo '<div class="ecare-sms-pro-alert-box"><p><strong>' . esc_html__( 'Before sending:', 'ecare-sms-pro' ) . '</strong> ' . esc_html__( 'Make sure your Sender ID is approved and numbers are in valid international format.', 'ecare-sms-pro' ) . '</p></div>';
		echo '</div>';
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

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'SMS Logs', 'ecare-sms-pro' ) . '</h1>';
		$this->render_tabs( 'logs' );

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $this->menu->get_slug( 'logs' ) ) . '" />';
		echo '<p class="search-box">';
		echo '<select name="status"><option value="">' . esc_html__( 'All Status', 'ecare-sms-pro' ) . '</option><option value="success" ' . selected( $status, 'success', false ) . '>Success</option><option value="failed" ' . selected( $status, 'failed', false ) . '>Failed</option></select> ';
		echo '<input type="date" name="date_from" value="' . esc_attr( $date_from ) . '" /> ';
		echo '<input type="date" name="date_to" value="' . esc_attr( $date_to ) . '" /> ';
		submit_button( __( 'Filter', 'ecare-sms-pro' ), 'secondary', '', false );
		echo '</p>';
		echo '</form>';

		echo '<table class="widefat striped">';
		echo '<thead><tr><th>' . esc_html__( 'ID', 'ecare-sms-pro' ) . '</th><th>' . esc_html__( 'Recipient', 'ecare-sms-pro' ) . '</th><th>' . esc_html__( 'Message', 'ecare-sms-pro' ) . '</th><th>' . esc_html__( 'Status', 'ecare-sms-pro' ) . '</th><th>' . esc_html__( 'UID', 'ecare-sms-pro' ) . '</th><th>' . esc_html__( 'Date', 'ecare-sms-pro' ) . '</th><th>' . esc_html__( 'Response', 'ecare-sms-pro' ) . '</th></tr></thead><tbody>';

		if ( empty( $result['rows'] ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No logs found.', 'ecare-sms-pro' ) . '</td></tr>';
		} else {
			foreach ( $result['rows'] as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( $row['id'] ) . '</td>';
				echo '<td>' . esc_html( $row['recipient'] ) . '</td>';
				echo '<td>' . esc_html( wp_trim_words( $row['message'], 14, '...' ) ) . '</td>';
				echo '<td>' . esc_html( ucfirst( $row['status'] ) ) . '</td>';
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

		echo '</div>';
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		$this->assert_capability();
		$settings        = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		$has_saved_token = ! empty( $settings['api_token'] );
		$saved_token     = $has_saved_token ? $this->api->decrypt_token( $settings['api_token'] ) : '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Ecare SMS Settings', 'ecare-sms-pro' ) . '</h1>';
		$this->render_tabs( 'settings' );
		echo '<div id="ecare-sms-pro-test-connection-notice"></div>';
		echo '<div id="ecare-sms-pro-balance-notice"></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ecare_sms_pro_save_settings" />';
		wp_nonce_field( 'ecare_sms_pro_save_settings', 'ecare_sms_pro_settings_nonce' );

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="ecare_api_token">' . esc_html__( 'API Token', 'ecare-sms-pro' ) . '</label></th><td>';
		echo '<div class="ecare-sms-pro-secret-wrap">';
		echo '<input type="password" id="ecare_api_token" name="api_token" class="regular-text" autocomplete="new-password" value="' . esc_attr( $saved_token ) . '" />';
		echo '<button type="button" class="button button-secondary ecare-sms-pro-toggle-secret" data-target="ecare_api_token" aria-label="' . esc_attr__( 'Show API Token', 'ecare-sms-pro' ) . '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Show API Token', 'ecare-sms-pro' ) . '</span></button>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'API token stays hidden by default. Use Show to view it.', 'ecare-sms-pro' ) . '</p>';
		echo '<p class="description"><strong>' . esc_html__( 'Token status:', 'ecare-sms-pro' ) . '</strong> ' . ( $has_saved_token ? esc_html__( 'Saved', 'ecare-sms-pro' ) : esc_html__( 'Not set', 'ecare-sms-pro' ) ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ecare_default_sender">' . esc_html__( 'Default Sender ID', 'ecare-sms-pro' ) . '</label></th><td>';
		echo '<input type="text" id="ecare_default_sender" name="default_sender_id" class="regular-text" maxlength="16" value="' . esc_attr( isset( $settings['default_sender_id'] ) ? $settings['default_sender_id'] : '' ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ecare_sms_type">' . esc_html__( 'SMS Type', 'ecare-sms-pro' ) . '</label></th><td>';
		echo '<select id="ecare_sms_type" name="sms_type"><option value="plain" ' . selected( isset( $settings['sms_type'] ) ? $settings['sms_type'] : 'plain', 'plain', false ) . '>plain</option></select>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Enable Logs', 'ecare-sms-pro' ) . '</th><td>';
		echo '<label><input type="checkbox" name="enable_logs" value="1" ' . checked( isset( $settings['enable_logs'] ) ? (int) $settings['enable_logs'] : 1, 1, false ) . ' /> ' . esc_html__( 'Store SMS requests and responses in logs.', 'ecare-sms-pro' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Enable Debug Mode', 'ecare-sms-pro' ) . '</th><td>';
		echo '<label><input type="checkbox" name="enable_debug" value="1" ' . checked( isset( $settings['enable_debug'] ) ? (int) $settings['enable_debug'] : 1, 1, false ) . ' /> ' . esc_html__( 'Capture step-by-step request debug data.', 'ecare-sms-pro' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Debug file path: wp-content/uploads/ecare-sms-pro/debug.log', 'ecare-sms-pro' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Woo: Order Placed SMS', 'ecare-sms-pro' ) . '</th><td>';
		echo '<label><input type="checkbox" name="wc_order_placed_enabled" value="1" ' . checked( isset( $settings['wc_order_placed_enabled'] ) ? (int) $settings['wc_order_placed_enabled'] : 0, 1, false ) . ' /> ' . esc_html__( 'Send SMS when a new WooCommerce order is placed.', 'ecare-sms-pro' ) . '</label>';
		echo '<p><textarea name="wc_order_placed_template" class="large-text" rows="3">' . esc_textarea( isset( $settings['wc_order_placed_template'] ) ? (string) $settings['wc_order_placed_template'] : '' ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'Available placeholders: {site_name}, {order_id}, {order_number}, {customer_name}, {first_name}, {last_name}, {phone}, {total}, {order_status}, {order_date}', 'ecare-sms-pro' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Woo: Status Change SMS', 'ecare-sms-pro' ) . '</th><td>';
		echo '<label><input type="checkbox" name="wc_status_changed_enabled" value="1" ' . checked( isset( $settings['wc_status_changed_enabled'] ) ? (int) $settings['wc_status_changed_enabled'] : 0, 1, false ) . ' /> ' . esc_html__( 'Send SMS when selected order statuses are changed.', 'ecare-sms-pro' ) . '</label>';
		echo '<p><textarea name="wc_status_changed_template" class="large-text" rows="3">' . esc_textarea( isset( $settings['wc_status_changed_template'] ) ? (string) $settings['wc_status_changed_template'] : '' ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'Template supports the same placeholders and uses {order_status} as the new status name.', 'ecare-sms-pro' ) . '</p>';

		$saved_targets = isset( $settings['wc_status_targets'] ) && is_array( $settings['wc_status_targets'] ) ? array_map( 'sanitize_key', $settings['wc_status_targets'] ) : array();
		if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order_statuses' ) ) {
			$status_labels = wc_get_order_statuses();
			echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Target statuses', 'ecare-sms-pro' ) . '</legend>';
			foreach ( $status_labels as $status_key => $status_label ) {
				$clean_status = sanitize_key( str_replace( 'wc-', '', (string) $status_key ) );
				echo '<label style="display:inline-block;margin-right:14px;">';
				echo '<input type="checkbox" name="wc_status_targets[]" value="' . esc_attr( $clean_status ) . '" ' . checked( in_array( $clean_status, $saved_targets, true ), true, false ) . ' /> ';
				echo esc_html( $status_label );
				echo '</label>';
			}
			echo '</fieldset>';
		} else {
			echo '<p class="description">' . esc_html__( 'WooCommerce is not active. Status options will appear after activation.', 'ecare-sms-pro' ) . '</p>';
		}

		echo '</td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save Settings', 'ecare-sms-pro' ) );
		echo ' <button type="button" class="button button-secondary" id="ecare-test-connection">' . esc_html__( 'Test Connection', 'ecare-sms-pro' ) . '</button>';
		echo '<span class="spinner" id="ecare-test-connection-spinner"></span>';
		echo ' <button type="button" class="button button-secondary" id="ecare-check-balance">' . esc_html__( 'Check SMS Balance', 'ecare-sms-pro' ) . '</button>';
		echo '<span class="spinner" id="ecare-check-balance-spinner"></span>';
		echo '</form>';
		echo '</div>';
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
		$wc_status_targets = array();
		if ( isset( $_POST['wc_status_targets'] ) && is_array( $_POST['wc_status_targets'] ) ) {
			$wc_status_targets = array_values(
				array_unique(
					array_filter(
						array_map(
							'sanitize_key',
							wp_unslash( $_POST['wc_status_targets'] )
						)
					)
				)
			);
		}

		$updated = array(
			'api_token'         => isset( $current['api_token'] ) ? $current['api_token'] : '',
			'default_sender_id' => isset( $_POST['default_sender_id'] ) ? sanitize_text_field( wp_unslash( $_POST['default_sender_id'] ) ) : '',
			'sms_type'          => isset( $_POST['sms_type'] ) ? sanitize_text_field( wp_unslash( $_POST['sms_type'] ) ) : 'plain',
			'enable_logs'       => isset( $_POST['enable_logs'] ) ? 1 : 0,
			'enable_debug'      => isset( $_POST['enable_debug'] ) ? 1 : 0,
			'wc_order_placed_enabled'    => isset( $_POST['wc_order_placed_enabled'] ) ? 1 : 0,
			'wc_order_placed_template'   => isset( $_POST['wc_order_placed_template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wc_order_placed_template'] ) ) : '',
			'wc_status_changed_enabled'  => isset( $_POST['wc_status_changed_enabled'] ) ? 1 : 0,
			'wc_status_changed_template' => isset( $_POST['wc_status_changed_template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wc_status_changed_template'] ) ) : '',
			'wc_status_targets'          => $wc_status_targets,
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
	 * AJAX SMS sender.
	 *
	 * @return void
	 */
	public function ajax_send_sms() {
		$this->ajax_guard();

		$recipient = isset( $_POST['recipient'] ) ? wp_unslash( $_POST['recipient'] ) : '';
		$message = isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '';
		$sender  = isset( $_POST['sender_id'] ) ? wp_unslash( $_POST['sender_id'] ) : '';
		$settings = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		$default_sender = isset( $settings['default_sender_id'] ) ? (string) $settings['default_sender_id'] : '';

		if ( '' === trim( $recipient ) ) {
			wp_send_json_error( array( 'message' => __( 'Recipient is required.', 'ecare-sms-pro' ) ), 400 );
		}

		if ( '' === trim( $message ) ) {
			wp_send_json_error( array( 'message' => __( 'Message is required.', 'ecare-sms-pro' ) ), 400 );
		}

		if ( '' === trim( (string) $sender ) && '' === trim( $default_sender ) ) {
			wp_send_json_error( array( 'message' => __( 'Sender ID is required. Please set a default Sender ID in settings.', 'ecare-sms-pro' ) ), 400 );
		}

		$response = $this->sms->send_sms(
			array(
				'recipient' => $recipient,
				'sender_id' => $sender,
				'message'   => $message,
			)
		);

		$this->send_ajax_response( $response );
	}

	/**
	 * AJAX bulk SMS sender.
	 *
	 * @return void
	 */
	public function ajax_bulk_send_sms() {
		$this->ajax_guard();

		$manual_numbers = isset( $_POST['manual_numbers'] ) ? wp_unslash( $_POST['manual_numbers'] ) : '';
		$message        = isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '';
		$settings       = get_option( ECARE_SMS_PRO_OPTION_KEY, array() );
		$default_sender = isset( $settings['default_sender_id'] ) ? (string) $settings['default_sender_id'] : '';

		if ( '' === trim( $message ) ) {
			wp_send_json_error( array( 'message' => __( 'Message is required for bulk send.', 'ecare-sms-pro' ) ), 400 );
		}

		if ( '' === trim( $default_sender ) ) {
			wp_send_json_error( array( 'message' => __( 'Default Sender ID is required. Please set it in settings.', 'ecare-sms-pro' ) ), 400 );
		}

		$numbers = $this->extract_numbers_from_text( $manual_numbers );
		$file_numbers = array();

		if ( isset( $_FILES['contacts_file'] ) && ! empty( $_FILES['contacts_file']['name'] ) ) {
			$file_numbers = $this->extract_numbers_from_uploaded_file( $_FILES['contacts_file'] );
			if ( is_wp_error( $file_numbers ) ) {
				wp_send_json_error(
					array(
						'message' => $file_numbers->get_error_message(),
						'data'    => $file_numbers->get_error_data(),
					),
					400
				);
			}
		}

		$numbers = array_values( array_unique( array_merge( $numbers, $file_numbers ) ) );
		if ( empty( $numbers ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid numbers found from manual input or uploaded file.', 'ecare-sms-pro' ) ), 400 );
		}

		$success = 0;
		$failed  = 0;
		$errors  = array();
		$count   = 0;

		foreach ( $numbers as $number ) {
			++$count;

			$body = $this->sms->parse_dynamic_template(
				$message,
				array(
					'{number}'    => $number,
					'{index}'     => $count,
					'{site_name}' => get_bloginfo( 'name' ),
				)
			);

			$response = $this->sms->send_sms(
				array(
					'recipient' => $number,
					'message'   => $body,
				)
			);

			if ( is_wp_error( $response ) ) {
				++$failed;
				$errors[] = array(
					'number'  => $number,
					'message' => $response->get_error_message(),
				);
			} else {
				++$success;
			}
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: total numbers, 2: success count, 3: failed count */
					__( 'Bulk SMS completed. Total: %1$d, Success: %2$d, Failed: %3$d', 'ecare-sms-pro' ),
					count( $numbers ),
					$success,
					$failed
				),
				'summary' => array(
					'total'   => count( $numbers ),
					'success' => $success,
					'failed'  => $failed,
					'errors'  => $errors,
				),
			)
		);
	}

	/**
	 * AJAX test API connection.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		$this->ajax_guard();

		$response = $this->api->test_connection();

		if ( is_wp_error( $response ) ) {
			$error_data = $response->get_error_data();
			wp_send_json_error(
				array(
					'message' => $response->get_error_message(),
					'data'    => $error_data,
				),
				400
			);
		}

		$message = isset( $response['message'] ) ? $response['message'] : __( 'Connection successful.', 'ecare-sms-pro' );
		wp_send_json_success(
			array(
				'message' => $message,
				'data'    => $response,
			)
		);
	}

	/**
	 * AJAX check SMS balance.
	 *
	 * @return void
	 */
	public function ajax_check_balance() {
		$this->ajax_guard();

		$response = $this->api->get_balance();
		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message' => $response->get_error_message(),
					'data'    => $response->get_error_data(),
				),
				400
			);
		}

		$balance = isset( $response['balance'] ) ? (string) $response['balance'] : '';
		$message = ( '' !== $balance )
			? sprintf( __( 'Current SMS Balance: %s', 'ecare-sms-pro' ), $balance )
			: __( 'Balance check successful.', 'ecare-sms-pro' );

		wp_send_json_success(
			array(
				'message' => $message,
				'data'    => $response,
			)
		);
	}

	/**
	 * Extract numbers from raw text.
	 *
	 * @param string $text Raw text.
	 * @return array
	 */
	private function extract_numbers_from_text( $text ) {
		$text  = (string) $text;
		$items = preg_split( '/[\s,;]+/', $text );
		$clean = array();

		foreach ( (array) $items as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}

			$item = preg_replace( '/[^0-9+]/', '', $item );
			if ( '' === $item ) {
				continue;
			}

			$digits_only = preg_replace( '/\D+/', '', $item );
			if ( strlen( $digits_only ) < 10 ) {
				continue;
			}

			$clean[] = $item;
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Extract numbers from uploaded CSV/TXT/XLSX file.
	 *
	 * @param array $file Uploaded file array.
	 * @return array|WP_Error
	 */
	private function extract_numbers_from_uploaded_file( $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'ecare_sms_invalid_file', __( 'Uploaded file is invalid.', 'ecare-sms-pro' ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'csv', 'txt', 'xlsx' ), true ) ) {
			return new WP_Error( 'ecare_sms_file_type', __( 'Only CSV, TXT, and XLSX files are supported.', 'ecare-sms-pro' ) );
		}

		if ( 'xlsx' === $ext ) {
			return $this->extract_numbers_from_xlsx( $file['tmp_name'] );
		}

		return $this->extract_numbers_from_csv( $file['tmp_name'] );
	}

	/**
	 * Extract numbers from a CSV/TXT file.
	 *
	 * @param string $path File path.
	 * @return array|WP_Error
	 */
	private function extract_numbers_from_csv( $path ) {
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'ecare_sms_file_open_failed', __( 'Could not read uploaded CSV/TXT file.', 'ecare-sms-pro' ) );
		}

		$rows = array();
		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			$rows[] = $row;
		}
		fclose( $handle );

		return $this->extract_numbers_from_rows( $rows );
	}

	/**
	 * Extract numbers from XLSX first worksheet.
	 *
	 * @param string $path XLSX file path.
	 * @return array|WP_Error
	 */
	private function extract_numbers_from_xlsx( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ecare_sms_zip_missing', __( 'ZipArchive is required to parse XLSX files on this server.', 'ecare-sms-pro' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'ecare_sms_xlsx_open_failed', __( 'Could not open uploaded XLSX file.', 'ecare-sms-pro' ) );
		}

		$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		$zip->close();

		if ( false === $sheet_xml ) {
			return new WP_Error( 'ecare_sms_xlsx_sheet_missing', __( 'Sheet1 not found in XLSX file.', 'ecare-sms-pro' ) );
		}

		libxml_use_internal_errors( true );
		$sheet = simplexml_load_string( $sheet_xml );
		if ( false === $sheet ) {
			return new WP_Error( 'ecare_sms_xlsx_parse_failed', __( 'Could not parse worksheet from XLSX file.', 'ecare-sms-pro' ) );
		}

		$shared_strings = array();
		if ( false !== $shared_xml ) {
			$shared = simplexml_load_string( $shared_xml );
			if ( $shared && isset( $shared->si ) ) {
				foreach ( $shared->si as $si ) {
					$texts = $si->xpath( './/t' );
					$value = '';
					if ( $texts ) {
						foreach ( $texts as $part ) {
							$value .= (string) $part;
						}
					}
					$shared_strings[] = $value;
				}
			}
		}

		$rows = array();
		$sheet_data = isset( $sheet->sheetData ) ? $sheet->sheetData : null;
		if ( $sheet_data && isset( $sheet_data->row ) ) {
			foreach ( $sheet_data->row as $row ) {
				$items = array();
				foreach ( $row->c as $cell ) {
					$type = isset( $cell['t'] ) ? (string) $cell['t'] : '';
					$raw  = isset( $cell->v ) ? (string) $cell->v : '';
					if ( 's' === $type ) {
						$index = (int) $raw;
						$items[] = isset( $shared_strings[ $index ] ) ? $shared_strings[ $index ] : '';
					} else {
						$items[] = $raw;
					}
				}
				$rows[] = $items;
			}
		}

		return $this->extract_numbers_from_rows( $rows );
	}

	/**
	 * Extract phone numbers from spreadsheet-like rows.
	 *
	 * @param array $rows Rows array.
	 * @return array
	 */
	private function extract_numbers_from_rows( $rows ) {
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$phone_columns = array();
		$first_row = isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : array();
		foreach ( $first_row as $col_index => $header ) {
			$label = strtolower( trim( (string) $header ) );
			if ( '' === $label ) {
				continue;
			}

			if ( preg_match( '/phone|mobile|number|contact|msisdn/', $label ) ) {
				$phone_columns[] = (int) $col_index;
			}
		}

		$numbers = array();
		$start_i = empty( $phone_columns ) ? 0 : 1;
		for ( $i = $start_i; $i < count( $rows ); $i++ ) {
			$row = isset( $rows[ $i ] ) && is_array( $rows[ $i ] ) ? $rows[ $i ] : array();
			if ( empty( $row ) ) {
				continue;
			}

			if ( ! empty( $phone_columns ) ) {
				foreach ( $phone_columns as $column_index ) {
					if ( isset( $row[ $column_index ] ) ) {
						$numbers = array_merge( $numbers, $this->extract_numbers_from_text( (string) $row[ $column_index ] ) );
					}
				}
			} else {
				foreach ( $row as $cell ) {
					$numbers = array_merge( $numbers, $this->extract_numbers_from_text( (string) $cell ) );
				}
			}
		}

		return array_values( array_unique( $numbers ) );
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
			$data = $response->get_error_data();
			$debug = ( is_array( $data ) && isset( $data['debug'] ) ) ? $data['debug'] : array();

			wp_send_json_error(
				array(
					'message' => $response->get_error_message(),
					'data'    => $data,
					'debug'   => $debug,
				),
				400
			);
		}

		$message = isset( $response['message'] ) ? $response['message'] : __( 'SMS request submitted successfully.', 'ecare-sms-pro' );
		$debug   = isset( $response['_debug'] ) && is_array( $response['_debug'] ) ? $response['_debug'] : array();
		wp_send_json_success(
			array(
				'message'  => $message,
				'response' => $response,
				'debug'    => $debug,
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
	 * Render tabs.
	 *
	 * @param string $active Active tab.
	 * @return void
	 */
	private function render_tabs( $active ) {
		$tabs = array(
			'send'     => __( 'Send SMS', 'ecare-sms-pro' ),
			'logs'     => __( 'Logs', 'ecare-sms-pro' ),
			'settings' => __( 'Settings', 'ecare-sms-pro' ),
		);

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$class = ( $active === $key ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $this->menu->get_slug( $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';
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
