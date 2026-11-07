<?php
/**
 * Order calls that did not reach sudemi yet: shipped, delivered, and the release of an unpaid
 * order cancelled here.
 *
 * Each of these happens while the merchant is clicking through an order screen, so it gets one
 * attempt. Losing it is not harmless: a dispatch sudemi never hears of leaves the buyer without
 * tracking, a delivery never starts the withdrawal period, and a cancellation sudemi never hears
 * of lets it take the buyer's money for goods that went back on the shelf. So a call without an
 * answer is written down here and the heartbeat sends it again.
 *
 * Keyed by sudemi order id and call, so repeating one changes nothing. Entries older than two
 * weeks are dropped with an error in the log: by then sudemi has settled the order on its own.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Order_Calls {

	const OPTION = 'sudemi_pending_order_calls';

	/** A cap, because an unbounded option row is a way to make a site unloadable. */
	const MAX_ENTRIES = 500;

	/** How long a call is retried. */
	const MAX_AGE = 1209600; // 14 days

	/** Per heartbeat, so a long outage does not turn one beat into hundreds of calls. */
	const PER_BEAT = 25;

	public static function enqueue( $sudemi_id, $call, array $body, $local_id ) {
		$all = self::all();
		$key = $sudemi_id . '|' . $call;
		$all[ $key ] = array(
			'order_id' => (string) $sudemi_id,
			'call'     => (string) $call,
			'body'     => $body,
			'local_id' => (int) $local_id,
			'since'    => isset( $all[ $key ]['since'] ) ? (int) $all[ $key ]['since'] : time(),
		);
		if ( count( $all ) > self::MAX_ENTRIES ) {
			$all = array_slice( $all, -self::MAX_ENTRIES, null, true );
		}
		update_option( self::OPTION, $all, false );
	}

	public static function remove( $sudemi_id, $call ) {
		$all = self::all();
		$key = $sudemi_id . '|' . $call;
		if ( ! isset( $all[ $key ] ) ) {
			return;
		}
		unset( $all[ $key ] );
		if ( empty( $all ) ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $all, false );
		}
	}

	public static function all() {
		$all = get_option( self::OPTION );
		return is_array( $all ) ? $all : array();
	}

	public static function count() {
		return count( self::all() );
	}

	/** Sends what is waiting, oldest first. Called by the heartbeat. */
	public static function drain() {
		$done = 0;
		foreach ( array_slice( self::all(), 0, self::PER_BEAT, true ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['order_id'] ) || empty( $entry['call'] ) ) {
				continue;
			}
			$sudemi_id = (string) $entry['order_id'];
			$call      = (string) $entry['call'];
			if ( (int) $entry['since'] < time() - self::MAX_AGE ) {
				self::remove( $sudemi_id, $call );
				Sudemi_Logger::error( 'Gave up sending ' . $call . ' for sudemi order ' . $sudemi_id . ' after two weeks without an answer. Check the order on sudemi.' );
				continue;
			}
			try {
				if ( 'reject' === $call ) {
					$order = wc_get_order( (int) $entry['local_id'] );
					if ( ! $order || Sudemi_Orders::reject_cancelled( $sudemi_id, $order, true ) ) {
						self::remove( $sudemi_id, $call );
						++$done;
					}
					continue;
				}
				if ( Sudemi_Orders::send_report( $sudemi_id, $call, isset( $entry['body'] ) && is_array( $entry['body'] ) ? $entry['body'] : array(), (int) $entry['local_id'] ) ) {
					++$done;
				}
			} catch ( Exception $e ) {
				Sudemi_Logger::error( 'Could not send ' . $call . ' for sudemi order ' . $sudemi_id . ': ' . $e->getMessage() );
			}
		}
		return $done;
	}

	public static function forget() {
		delete_option( self::OPTION );
	}
}
