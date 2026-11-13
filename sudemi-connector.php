<?php
/**
 * Plugin Name:       sudemi Connector
 * Plugin URI:        https://sudemi.com/integrations/woocommerce
 * Description:       Lists this shop's products on sudemi, so an AI assistant can find them and send buyers to you. You stay the merchant of record.
 * Version:           1.4.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Akacia
 * Author URI:        https://akacia.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sudemi
 * Domain Path:       /languages
 * WC requires at least: 8.0
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

define( 'SUDEMI_VERSION', '1.4.0' );
define( 'SUDEMI_PLUGIN_FILE', __FILE__ );
define( 'SUDEMI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-logger.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-currency.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-keys.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-client.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-challenge.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-pairing.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-queue.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-shipping.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-withdrawal.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-sync.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-claims.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-orders.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-order-calls.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-manage.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-changes.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-heartbeat.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-support.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-requests.php';
require_once SUDEMI_PLUGIN_DIR . 'includes/class-sudemi-insights.php';

if ( is_admin() ) {
	require_once SUDEMI_PLUGIN_DIR . 'admin/class-sudemi-admin.php';
	require_once SUDEMI_PLUGIN_DIR . 'admin/class-sudemi-admin-manage.php';
	require_once SUDEMI_PLUGIN_DIR . 'admin/class-sudemi-admin-insights.php';
}

// The admin screens ship in twelve languages; the files live in languages/.
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'sudemi', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

/**
 * A one-minute schedule, for the short window while a shop owner is claiming the shop in
 * another tab. Nothing else uses it, and the polling stops as soon as the pairing resolves.
 */
add_filter(
	'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
	function ( $schedules ) {
		$schedules['sudemi_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (sudemi pairing)', 'sudemi' ),
		);
		// The heartbeat. sudemi stops listing this shop's products when the beats stop, because
		// from its side a quiet shop and a dead one are the same thing and only one of them still
		// has prices worth quoting. Five minutes is well inside the window it allows.
		$schedules['sudemi_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes (sudemi heartbeat)', 'sudemi' ),
		);
		return $schedules;
	}
);

add_action(
	'plugins_loaded',
	function () {
		// WooCommerce is a hard requirement: without it there is no catalogue to list.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>'
						. esc_html__( 'sudemi Connector needs WooCommerce to be active.', 'sudemi' )
						. '</p></div>';
				}
			);
			return;
		}

		// Review 2026-11-12: the claim table exists before any order can be accepted (updates do
		// not run the activation hook).
		Sudemi_Claims::maybe_install();
		Sudemi_Challenge::boot();
		Sudemi_Pairing::boot();
		Sudemi_Sync::boot();
		Sudemi_Heartbeat::boot();
		Sudemi_Orders::boot();
		Sudemi_Manage::boot();
		if ( is_admin() ) {
			Sudemi_Admin::boot();
			Sudemi_Withdrawal::boot();
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		Sudemi_Claims::install();
		Sudemi_Sync::schedule();
		Sudemi_Heartbeat::schedule();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		// Deactivation stops the work but keeps the connection: a shop that deactivates to debug
		// a theme should not have to pair again afterwards. Uninstall is what removes it.
		Sudemi_Sync::unschedule();
		Sudemi_Heartbeat::unschedule();
		Sudemi_Pairing::stop_polling();
	}
);
