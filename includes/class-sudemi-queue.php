<?php
/**
 * Price changes that have not reached sudemi yet.
 *
 * A delta push happens while someone is saving a product in wp-admin, which means it gets one
 * attempt and a short timeout — holding that screen for a retry would make a sudemi outage look
 * like a WooCommerce fault. So a failed push is not retried in place; it is written down, and
 * the heartbeat drains it a few minutes later.
 *
 * Without this the "no stale prices" promise would rest on every single push succeeding, forever,
 * which is a bet against ordinary network weather: one DNS blip during a price change and this
 * shop advertises the old price on sudemi until the next full sync hours later.
 *
 * The queue is keyed by product id, so twenty saves of one product while sudemi is unreachable
 * leave one entry carrying the newest price rather than twenty carrying a history nobody wants.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Queue {

	const OPTION = 'sudemi_pending_prices';

	/** A cap, because an unbounded option row is a way to make a site unloadable. */
	const MAX_ENTRIES = 2000;

	/** Remembers a change that did not get through. Newest price per product wins. */
	public static function enqueue( array $update ) {
		if ( empty( $update['externalId'] ) ) {
			return;
		}
		$pending = self::all();
		$pending[ (string) $update['externalId'] ] = $update;

		if ( count( $pending ) > self::MAX_ENTRIES ) {
			// Past this point the queue has stopped being a retry buffer and become a backlog,
			// and a full sync will fix the lot more cheaply than draining it. Keep the newest
			// and let the revision mismatch ask for that sync.
			$pending = array_slice( $pending, -self::MAX_ENTRIES, null, true );
		}

		update_option( self::OPTION, $pending, false );
	}

	public static function all() {
		$pending = get_option( self::OPTION );
		return is_array( $pending ) ? $pending : array();
	}

	public static function count() {
		return count( self::all() );
	}

	/**
	 * Sends what is waiting, oldest first, and keeps whatever still does not get through.
	 *
	 * Batched because a shop that was offline for an hour may have a few hundred waiting, and
	 * one request per product would spend the cron slot on handshakes.
	 *
	 * @return int how many updates were accepted
	 */
	public static function drain( $batch_size = 200 ) {
		$pending = self::all();
		if ( empty( $pending ) ) {
			return 0;
		}

		$batch = array_slice( $pending, 0, $batch_size, true );
		$response = Sudemi_Client::post_signed(
			'/integration/v1/products/prices',
			array( 'updates' => array_values( $batch ) )
		);

		if ( is_wp_error( $response ) || 200 !== $response['status'] ) {
			// Still unreachable. Nothing is dropped, and the next heartbeat tries again.
			return 0;
		}

		// Only what was sent is cleared. Anything a save hook added while this request was in
		// flight stays queued rather than being discarded unsent.
		$remaining = array_diff_key( self::all(), $batch );
		if ( empty( $remaining ) ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $remaining, false );
		}

		return isset( $response['body']['applied'] ) ? (int) $response['body']['applied'] : 0;
	}

	public static function forget() {
		delete_option( self::OPTION );
	}
}
