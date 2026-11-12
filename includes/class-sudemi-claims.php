<?php
/**
 * Who builds the WooCommerce order for a sudemi order: exactly one caller, decided by the
 * database before anything is built.
 *
 * Review 2026-11-12: the order (and the stock it takes) used to be created first and only then
 * linked to its sudemi order, so two callers at the same moment (a double click on Accept, a bulk
 * accept, the heartbeat's automatic answer) both saw no order and both built one. The fix is the
 * order of steps, not a lock: a row keyed on the sudemi order id is inserted first, the primary
 * key lets exactly one insert in, and only that caller builds the order and then writes its id
 * into the row. Everyone else is told the order is built (or being built) and builds nothing.
 *
 * A caller that dies after claiming leaves a row with no order behind it. Such a row is taken over
 * after {@see self::STALE_SECONDS} by a conditional update, which again lets exactly one caller in.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

class Sudemi_Claims {

	const DB_VERSION    = 1;
	const OPTION_DB     = 'sudemi_db_version';
	const STALE_SECONDS = 600;

	/** The claim was won: this caller builds the order. */
	const WON = 'won';
	/** Another caller claimed it moments ago and is building it now. */
	const BUSY = 'busy';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sudemi_order_claim';
	}

	/** Creates the table on activation and after an update (plugins are updated without activation). */
	public static function maybe_install() {
		if ( (int) get_option( self::OPTION_DB, 0 ) >= self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				sudemi_order_id char(36) NOT NULL,
				local_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				claimed_at datetime NOT NULL,
				PRIMARY KEY  (sudemi_order_id)
			) $charset;"
		);
		update_option( self::OPTION_DB, self::DB_VERSION, false );
	}

	/**
	 * Claims the right to build the order for a sudemi order.
	 *
	 * @param string $sudemi_id The sudemi order id.
	 * @return string|int self::WON, self::BUSY, or the id of the WooCommerce order already built.
	 */
	public static function claim( $sudemi_id ) {
		global $wpdb;
		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; the insert's key is the claim.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (sudemi_order_id, local_order_id, claimed_at) VALUES (%s, 0, %s)", $sudemi_id, $now ) );
		if ( 1 === (int) $inserted ) {
			return self::WON;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT local_order_id, claimed_at FROM {$table} WHERE sudemi_order_id = %s", $sudemi_id ), ARRAY_A );
		if ( ! $row ) {
			// The row went between the insert and the read (released after a failed build): the
			// next caller claims it afresh.
			return self::BUSY;
		}
		$local = (int) $row['local_order_id'];
		if ( $local > 0 ) {
			if ( function_exists( 'wc_get_order' ) && ! wc_get_order( $local ) ) {
				// The order was deleted for good while sudemi still waits for an answer: taken over
				// from exactly that order id, so only one caller rebuilds it.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
				$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET local_order_id = 0, claimed_at = %s WHERE sudemi_order_id = %s AND local_order_id = %d", $now, $sudemi_id, $local ) );
				return 1 === (int) $taken ? self::WON : self::BUSY;
			}
			return $local;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_SECONDS );
		if ( $row['claimed_at'] >= $cutoff ) {
			return self::BUSY;
		}
		// The caller that claimed it died before the order was built. Taken over from exactly the
		// time it was claimed, so of several callers arriving now only one gets it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET claimed_at = %s WHERE sudemi_order_id = %s AND local_order_id = 0 AND claimed_at = %s", $now, $sudemi_id, $row['claimed_at'] ) );
		return 1 === (int) $taken ? self::WON : self::BUSY;
	}

	/** Records the order the winner built. */
	public static function link( $sudemi_id, $local_order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->update( self::table(), array( 'local_order_id' => (int) $local_order_id ), array( 'sudemi_order_id' => $sudemi_id ), array( '%d' ), array( '%s' ) );
	}

	/** Gives the claim up: nothing was built (or what was built was removed again). */
	public static function release( $sudemi_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->delete( self::table(), array( 'sudemi_order_id' => $sudemi_id ), array( '%s' ) );
	}
}
