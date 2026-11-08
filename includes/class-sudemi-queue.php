<?php
/**
 * Price changes that have not reached sudemi yet.
 *
 * A delta push happens while someone is saving a product in wp-admin, which means it gets one
 * attempt and a short timeout - holding that screen for a retry would make a sudemi outage look
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

	/** Drops what is waiting for one product: a newer push for it carries its current state. */
	public static function discard( $external_id ) {
		$pending = self::all();
		if ( array_key_exists( (string) $external_id, $pending ) ) {
			unset( $pending[ (string) $external_id ] );
			self::store( $pending );
		}
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
			if ( ! is_wp_error( $response ) && in_array( (int) $response['status'], array( 400, 403, 413, 422 ), true ) ) {
				// Refused for good (a malformed batch, or a suspended shop): sending the same
				// batch on every beat forever changes nothing. Dropped, and the change counter
				// moves so the catalogue revision disagrees and sudemi asks for a full sync,
				// which carries these prices with everything else.
				self::store( self::without_sent( self::all(), $batch ) );
				Sudemi_Heartbeat::note_change();
				Sudemi_Logger::error( 'sudemi refused queued price changes (HTTP ' . (int) $response['status'] . '); they were dropped and a full sync will carry them instead.' );
				return 0;
			}
			// Still unreachable. Nothing is dropped, and the next heartbeat tries again.
			return 0;
		}

		// Only what was sent is cleared. A newer change a save hook queued while this request was
		// in flight, for the same product or another, stays queued rather than being discarded.
		self::store( self::without_sent( self::all(), $batch ) );

		return isset( $response['body']['applied'] ) ? (int) $response['body']['applied'] : 0;
	}

	/**
	 * The queue minus the entries that were sent, compared by value: an entry replaced by a newer
	 * price while the request was in flight is kept.
	 */
	public static function without_sent( array $current, array $sent ) {
		foreach ( $sent as $key => $value ) {
			if ( array_key_exists( $key, $current ) && $current[ $key ] == $value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- arrays compared by content.
				unset( $current[ $key ] );
			}
		}
		return $current;
	}

	private static function store( array $remaining ) {
		if ( empty( $remaining ) ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $remaining, false );
		}
	}

	public static function forget() {
		delete_option( self::OPTION );
	}
}
