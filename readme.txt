=== Ecare SMS ===
Contributors: sakifistiak
Tags: sms, woocommerce, order notification, transactional sms, bulk sms
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send transactional and bulk SMS from WordPress and WooCommerce using the Ecare SMS API.

== Description ==

Ecare SMS helps site admins send SMS from the WordPress dashboard and automate WooCommerce order notifications.

Support email: gocares@gmail.com
Developer: Sakif Istiak

Features:

* Send test SMS to one or multiple recipients.
* Send bulk SMS from manual numbers or CSV/TXT/XLSX upload.
* WooCommerce template-based SMS on order placed and status change.
* Per-status WooCommerce templates with placeholder support.
* Logs page with status/date filters and response viewer.
* API connectivity test and SMS balance check from settings.

Supported placeholders include:

* `{site_name}`
* `{order_id}`
* `{order_number}`
* `{customer_name}`
* `{first_name}`
* `{last_name}`
* `{phone}`
* `{total}`
* `{order_status}`
* `{order_date}`
* `{number}`
* `{index}`

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the zip from Plugins > Add New.
2. Activate the plugin through the Plugins screen.
3. Go to `Ecare SMS > Settings`.
4. Add your API token and default sender ID.
5. Save settings and test the connection.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

No. Core SMS sending works without WooCommerce. WooCommerce templates are optional and only work when WooCommerce is active.

= Where are logs stored? =

SMS logs are stored in a dedicated custom database table.

= Is debug mode enabled by default? =

No. Debug mode is off by default and can be enabled from plugin settings.

== Changelog ==

= 1.0.0 =

* Initial release.
* Added SMS sending, bulk sending, logs, and WooCommerce templates.
* Added security hardening for file uploads and XML parsing.
