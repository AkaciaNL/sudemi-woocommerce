<?php
/**
 * Removing the plugin removes the connection with it.
 *
 * A signing key left behind on a site that believes it has removed the plugin is a credential
 * nobody is watching. So the key goes, the install id goes, and sudemi is told - best effort,
 * because an uninstall must finish whether or not the network is up.
 *
 * @package Sudemi
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-sudemi-logger.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-sudemi-keys.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-sudemi-client.php';

if ( Sudemi_Client::is_connected() ) {
	Sudemi_Client::post_signed( '/integration/v1/credentials/revoke', array( 'reason' => 'uninstalled' ) );
}

Sudemi_Keys::forget();

delete_option( 'sudemi_install_id' );
delete_option( 'sudemi_api_base' );
delete_option( 'sudemi_challenge_token' );
delete_option( 'sudemi_pairing_code' );
delete_option( 'sudemi_pairing_poll_token' );
delete_option( 'sudemi_pairing_claim_url' );
delete_option( 'sudemi_log' );
delete_option( 'sudemi_status' );
delete_option( 'sudemi_open_requests' );
delete_option( 'sudemi_auto_confirm' );
delete_option( 'sudemi_heartbeat_seconds' );
delete_option( 'sudemi_change_counter' );
// Price changes that never got through. They are worthless without the credential that would
// have signed them, and leaving them behind would mean a reinstall pushing stale prices.
delete_option( 'sudemi_pending_prices' );
delete_option( 'sudemi_categories_sent' );
delete_option( 'sudemi_removals' );
// The shop profile values chosen on the sudemi Shop profile page.
delete_option( 'sudemi_profile_description' );
delete_option( 'sudemi_profile_support_email' );
delete_option( 'sudemi_profile_returns_days' );
delete_option( 'sudemi_sync_state' );
delete_option( 'sudemi_removals_synced' );
delete_option( 'sudemi_pending_order_calls' );
delete_option( 'sudemi_last_ok' );
delete_option( 'sudemi_changes_cursor' );
delete_option( 'sudemi_changes_checked' );
delete_option( 'sudemi_changes_retry' );
delete_option( 'sudemi_tracking_since' );
delete_option( 'sudemi_db_version' );

// Which sudemi order belongs to which WooCommerce order is also on the orders themselves.
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'sudemi_order_claim' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own table.

wp_clear_scheduled_hook( 'sudemi_full_sync' );
wp_clear_scheduled_hook( 'sudemi_full_sync', array( 'continue' ) );
wp_clear_scheduled_hook( 'sudemi_pairing_poll' );
wp_clear_scheduled_hook( 'sudemi_heartbeat' );

// The per-product right-of-withdrawal setting only means something to sudemi.
delete_post_meta_by_key( '_sudemi_withdrawal_exclusion' );
delete_post_meta_by_key( '_sudemi_unlisted' );
