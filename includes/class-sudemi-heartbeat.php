<?php
/**
 * Telling sudemi this shop is still here, and still means its prices.
 *
 * sudemi cannot call this shop: it sits behind this host's firewall and accepts no connection
 * from outside. Everything therefore has to start here, which makes this the only two-way
 * channel the integration has - the request says what this shop believes its catalogue to be,
 * and the reply is where sudemi asks for anything it needs.
 *
 * Why it exists at all: from sudemi's side a shop with nothing to report and a shop whose server
 * died last Tuesday look identical, and only one of them still has prices worth quoting. If
 * silence were read as agreement, a dead shop would keep advertising last week's prices and
 * taking orders at them. So this shop says so, every few minutes, and sudemi stops listing it
 * when it stops saying so. The listings come back on the next beat; nothing is deleted.
 *
 * The revision is the part that catches the quiet failure. A delta push can be lost - sudemi
 * restarting, a DNS blip, a proxy - and no amount of liveness notices that, because the shop is
 * healthy and the beats keep arriving. Sending a cheap statement of what this catalogue is lets
 * sudemi disagree, and a disagreement costs one full sync instead of weeks of a wrong price.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Heartbeat {

	const CRON             = 'sudemi_heartbeat';
	const OPTION_STATUS    = 'sudemi_status';
	const OPTION_INTERVAL  = 'sudemi_heartbeat_seconds';
	const OPTION_CHANGES   = 'sudemi_change_counter';
	const OPTION_LAST_OK   = 'sudemi_last_ok';

	/** What this shop asks for. sudemi may hold it to something slower, and says so if it does. */
	const DEFAULT_INTERVAL = 300;

	public static function boot() {
		add_action( self::CRON, array( __CLASS__, 'run' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 60, 'sudemi_five_minutes', self::CRON );
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON );
			$timestamp = wp_next_scheduled( self::CRON );
		}
	}

	/** What the admin screen shows: sudemi's own answer about this shop, not our guess at it. */
	public static function status() {
		$status = get_option( self::OPTION_STATUS );
		return is_array( $status ) ? $status : array();
	}

	public static function forget() {
		delete_option( self::OPTION_STATUS );
		delete_option( self::OPTION_INTERVAL );
		delete_option( self::OPTION_CHANGES );
		delete_option( self::OPTION_LAST_OK );
	}

	/** When sudemi last answered a heartbeat with 200 (unix time), or 0 when it never has. */
	public static function last_ok() {
		return (int) get_option( self::OPTION_LAST_OK, 0 );
	}

	public static function run() {
		if ( ! Sudemi_Client::is_connected() ) {
			return;
		}

		// Drained first, so the revision this beat reports is the one that holds after the
		// backlog has gone in. Reporting a revision sudemi cannot match yet would ask for a full
		// sync that the queue was about to make unnecessary.
		$drained = Sudemi_Queue::drain();

		$response = Sudemi_Client::post_signed(
			'/integration/v1/heartbeat',
			array(
				'catalogueRevision' => self::revision(),
				'productCount'      => self::product_count(),
				'intervalSeconds'   => self::DEFAULT_INTERVAL,
				'pluginVersion'     => SUDEMI_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::remember(
				array(
					'reachable' => false,
					'message'   => $response->get_error_message(),
					'checked'   => time(),
				)
			);
			return;
		}

		if ( 401 === $response['status'] ) {
			// The credential is no longer recognised: revoked from the portal, or this shop was
			// re-paired elsewhere. Said plainly, because the fix is to connect again and no
			// amount of retrying will do it.
			self::remember(
				array(
					'reachable' => true,
					'connected' => false,
					'message'   => __( 'sudemi no longer recognises this shop. Connect it again.', 'sudemi' ),
					'checked'   => time(),
				)
			);
			return;
		}

		$paired = Sudemi_Client::site_mismatch( $response );
		if ( null !== $paired ) {
			// This site holds the shop's install id and key but is not the shop sudemi knows: a
			// staging copy, a restored backup, or the shop moved. sudemi refuses it on purpose,
			// so retrying cannot help; the owner has to disconnect here or connect again.
			self::remember(
				array(
					'reachable' => true,
					'connected' => false,
					'problem'   => 'site_mismatch',
					'message'   => self::site_mismatch_message( $paired ),
					'checked'   => time(),
				)
			);
			return;
		}

		if ( 200 !== $response['status'] ) {
			self::remember(
				array(
					'reachable' => true,
					/* translators: %d: HTTP status code. */
					'message'   => sprintf( __( 'sudemi answered %d.', 'sudemi' ), $response['status'] ),
					'checked'   => time(),
				)
			);
			return;
		}

		$body = $response['body'];
		update_option( self::OPTION_LAST_OK, time(), false );

		// A fresh ownership token. sudemi re-proves the domain on a schedule because a listing
		// names it to buyers as this shop's identity, and domains change hands.
		if ( ! empty( $body['domain_challenge']['token'] ) ) {
			Sudemi_Challenge::store_token( $body['domain_challenge']['token'] );
		}

		if ( ! empty( $body['heartbeat_seconds'] ) ) {
			update_option( self::OPTION_INTERVAL, (int) $body['heartbeat_seconds'], false );
		}

		self::remember(
			array(
				'reachable'     => true,
				'connected'     => true,
				'listed'        => ! empty( $body['catalogue_listed'] ),
				'reason'        => isset( $body['not_listed_reason'] ) ? (string) $body['not_listed_reason'] : '',
				'trusted_until' => isset( $body['trusted_until'] ) ? (string) $body['trusted_until'] : '',
				'pending'       => Sudemi_Queue::count(),
				'drained'       => (int) $drained,
				'checked'       => time(),
			)
		);

		// Orders whose buyers are waiting on an answer from this shop. Done before the full-sync
		// branch below because each one is money held on someone's card against a deadline,
		// while a catalogue disagreement costs nothing by waiting another five minutes.
		if ( ! empty( $body['pending_orders'] ) ) {
			Sudemi_Orders::handle_pending( $body['pending_orders'] );
		}

		// Orders this shop accepted while the buyer's payment still covered other shops: now
		// paid (ship them) or released after all (cancel them). Not gated on automatic
		// confirmation - these are outcomes to record, not questions to answer.
		if ( ! empty( $body['settled_orders'] ) ) {
			Sudemi_Orders::handle_settled( $body['settled_orders'] );
		}

		// Local orders still on hold that this beat did not mention: an acceptance whose reply
		// was lost, or an order whose window closed. Asked about again until sudemi says how
		// they ended; and calls that did not get through earlier (shipped, delivered, a
		// cancellation) are sent again.
		$handled = array();
		foreach ( array( 'pending_orders', 'settled_orders' ) as $block ) {
			if ( ! empty( $body[ $block ] ) && is_array( $body[ $block ] ) ) {
				foreach ( $body[ $block ] as $entry ) {
					if ( is_array( $entry ) && ! empty( $entry['order_id'] ) ) {
						$handled[] = (string) $entry['order_id'];
					}
				}
			}
		}
		Sudemi_Orders::reconcile( $handled );
		Sudemi_Order_Calls::drain();

		// Return requests this shop has to answer. The full list on every beat, so the stored
		// copy is replaced (and emptied when the beat carries none).
		Sudemi_Requests::replace( isset( $body['open_requests'] ) ? $body['open_requests'] : null );

		// sudemi and this shop disagree about what the catalogue is, so something did not arrive.
		// A full sync is the only answer that does not involve guessing which product it was.
		if ( ! empty( $body['full_sync_requested'] ) ) {
			Sudemi_Logger::info( __( 'sudemi asked for a full sync; scheduling one.', 'sudemi' ) );
			// Not run inline: a full sync walks the whole catalogue, and doing that inside a
			// heartbeat would turn a 200-byte call into a multi-minute one holding a cron slot.
			// WordPress ignores a duplicate single event within ten minutes, so repeated asks on
			// consecutive beats do not pile up.
			wp_schedule_single_event( time() + 30, Sudemi_Sync::CRON_FULL );
		}
	}

	/**
	 * A cheap statement of what this catalogue is.
	 *
	 * Count, newest modification time and a local change counter, hashed. It does not have to
	 * describe the catalogue - it only has to change whenever the catalogue does, so that
	 * sudemi's copy of it can disagree.
	 *
	 * All three are needed. The count alone misses an edited price. The timestamp alone misses a
	 * deletion. And both of those are post fields, which a stock movement does not always touch
	 * so the counter, bumped by the save hook itself, is what makes a lost stock push detectable
	 * rather than invisible.
	 */
	public static function revision() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			"select count(*) as n, coalesce(max(post_modified_gmt), '') as newest
			   from {$wpdb->posts}
			  where post_type = 'product' and post_status = 'publish'"
		);

		if ( ! $row ) {
			return null;
		}

		return hash(
			'sha256',
			(string) $row->n . '|' . (string) $row->newest . '|' . (string) self::changes()
		);
	}

	/**
	 * How many product changes this shop has seen since the plugin was installed.
	 *
	 * Monotonic and never reset: its value is meaningless, only its movement matters. Reset it
	 * and an old revision could be reproduced, which is exactly the agreement we must not fake.
	 */
	public static function changes() {
		$count = get_option( self::OPTION_CHANGES );
		return is_numeric( $count ) ? (int) $count : 0;
	}

	public static function note_change() {
		update_option( self::OPTION_CHANGES, self::changes() + 1, false );
	}

	/** What the admin screen says when sudemi refuses this site as not being the connected shop. */
	public static function site_mismatch_message( $paired ) {
		if ( is_string( $paired ) && '' !== $paired ) {
			return sprintf(
				/* translators: %s: the address the shop is connected to sudemi under, e.g. https://shop.example.com */
				__( 'sudemi refused requests from this site: this shop is connected to sudemi under a different address (%s). If this site is a staging copy or a restored backup, disconnect sudemi here. If the shop has moved to this address, connect it again.', 'sudemi' ),
				$paired
			);
		}
		return __( 'sudemi refused requests from this site: this shop is connected to sudemi under a different address. If this site is a staging copy or a restored backup, disconnect sudemi here. If the shop has moved to this address, connect it again.', 'sudemi' );
	}

	private static function product_count() {
		$counts = wp_count_posts( 'product' );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	private static function remember( array $status ) {
		update_option( self::OPTION_STATUS, $status, false );
	}
}
