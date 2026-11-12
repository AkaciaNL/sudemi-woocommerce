<?php
/**
 * Keeping this shop's WooCommerce orders in step with sudemi (protocol 4d, the change feed).
 *
 * sudemi is the source of truth for its orders. A dispatch, a delivery, a refund or a
 * cancellation made on the sudemi portal (or by sudemi support, or by a deadline that ran out)
 * has to reach the WooCommerce order too, or the shop ships an order that was cancelled and books
 * money it no longer has. sudemi cannot call this shop, so the heartbeat carries the news: every
 * beat sends the cursor of the last answer and gets back the orders changed since
 * (changed_orders), oldest first.
 *
 * Each entry carries the order's revision. It is applied only when it is higher than the one the
 * WooCommerce order holds, so a repeat (the same change twice, an older one after a newer one)
 * changes nothing. Applying is also idempotent on its own: an order already completed is not
 * completed again, a refund already recorded is not recorded twice.
 *
 * What is applied never goes back to sudemi (Sudemi_Orders::quietly): it came from there.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Changes {

	const OPTION_CURSOR      = 'sudemi_changes_cursor';
	const OPTION_SAFETY_NET  = 'sudemi_changes_checked';

	/** Orders whose change could not be applied yet: order id => attempts, first, last, why, cancelled_by. */
	const OPTION_RETRY = 'sudemi_changes_retry';

	/** Asked about again on later beats, at most this many per beat ... */
	const RETRY_PER_BEAT = 10;

	/** ... and given up on (loudly, in the log) after this many tries or this long (3 days). */
	const RETRY_MAX_ATTEMPTS = 100;
	const RETRY_MAX_AGE      = 259200;

	const META_REVISION  = '_sudemi_revision';
	const META_STATUS    = '_sudemi_status';
	const META_CARRIER   = '_sudemi_tracking_carrier';
	const META_CODE      = '_sudemi_tracking_code';
	const META_DELIVERED = '_sudemi_delivered_at';

	/** Extra beats in one run when sudemi says more changes are waiting (changes_more). */
	const MAX_EXTRA_BEATS = 5;

	/** Orders older than this are never created here from the feed (a store that was emptied, an old import). */
	const MATERIALISE_DAYS = 30;

	// ------------------------------------------------------------------- cursor ----

	/** The cursor to send: the last one sudemi gave this install, or '' to start. */
	public static function cursor() {
		$stored  = get_option( self::OPTION_CURSOR );
		$install = Sudemi_Client::install_id();
		if ( ! is_array( $stored ) || ! isset( $stored['install'], $stored['cursor'] ) || $stored['install'] !== $install ) {
			return ''; // Never paired, or paired again since: start over (4d).
		}
		return self::valid_cursor( $stored['cursor'] ) ? $stored['cursor'] : '';
	}

	/** Opaque, but only what sudemi sends: printable, short. */
	public static function valid_cursor( $cursor ) {
		return is_string( $cursor ) && strlen( $cursor ) <= 512 && 1 === preg_match( '/^[A-Za-z0-9._~:=-]*$/', $cursor );
	}

	public static function remember_cursor( $cursor ) {
		if ( ! self::valid_cursor( $cursor ) ) {
			return;
		}
		update_option(
			self::OPTION_CURSOR,
			array(
				'install' => Sudemi_Client::install_id(),
				'cursor'  => $cursor,
			),
			false
		);
	}

	public static function forget() {
		delete_option( self::OPTION_CURSOR );
		delete_option( self::OPTION_SAFETY_NET );
		delete_option( self::OPTION_RETRY );
	}

	// ---------------------------------------------------------------- heartbeat ----

	/**
	 * Applies the change feed of one heartbeat answer and keeps its cursor.
	 *
	 * @param array $body The heartbeat answer.
	 * @return bool|null true when sudemi has more changes waiting; null when the answer has no
	 *                   change feed at all (a core before 2026-11-09: the 1.2 behaviour holds).
	 */
	public static function handle_beat( array $body ) {
		if ( ! isset( $body['changed_orders'] ) || ! is_array( $body['changed_orders'] ) ) {
			return null;
		}
		if ( ! empty( $body['changes_cursor_reset'] ) ) {
			Sudemi_Logger::info( __( 'sudemi started the order change list over (last 7 days); orders are checked again, nothing is applied twice.', 'sudemi' ) );
		}
		$applied = 0;
		foreach ( $body['changed_orders'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$why = '';
			try {
				$result = self::apply_entry( $entry );
				if ( 'applied' === $result ) {
					++$applied;
				} elseif ( 'retry' === $result ) {
					$why = 'unreachable';
				}
			} catch ( Throwable $e ) {
				$why = $e->getMessage();
				$why = '' === $why ? get_class( $e ) : $why;
			}
			if ( '' !== $why && isset( $entry['order_id'] ) && is_string( $entry['order_id'] ) ) {
				// Not applied (sudemi busy, or this shop could not record it): kept per order and
				// read from sudemi again on later beats, so neither the feed nor the order is lost.
				self::queue_retry( strtolower( $entry['order_id'] ), $why, isset( $entry['cancelled_by'] ) ? $entry['cancelled_by'] : null );
			}
		}
		if ( $applied > 0 ) {
			Sudemi_Logger::info(
				sprintf(
					/* translators: %d: number of orders. */
					__( '%d WooCommerce order(s) updated from sudemi.', 'sudemi' ),
					$applied
				)
			);
		}
		// Kept only after the entries were applied (a run that dies halfway reads them again), and
		// never held back for one order that failed: that one is on the retry list instead.
		if ( isset( $body['changes_cursor'] ) ) {
			self::remember_cursor( $body['changes_cursor'] );
		}
		return ! empty( $body['changes_more'] );
	}

	/**
	 * Once a day: the orders placed since yesterday, read from the order table, applied like
	 * feed entries (4d: "a cheap safety net"). Only with a core that sends revisions.
	 */
	public static function safety_net() {
		$last = (int) get_option( self::OPTION_SAFETY_NET, 0 );
		if ( $last > time() - DAY_IN_SECONDS ) {
			return;
		}
		update_option( self::OPTION_SAFETY_NET, time(), false );
		$filters = Sudemi_Manage::filters(
			array(
				'from' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
				'sort' => 'placed',
				'dir'  => 'asc',
			)
		);
		for ( $page = 0; $page < 5; $page++ ) {
			$read = Sudemi_Client::get_signed( Sudemi_Manage::list_path( $filters, 100 ) );
			if ( is_wp_error( $read ) || 200 !== (int) $read['status'] || empty( $read['body']['orders'] ) ) {
				return;
			}
			foreach ( $read['body']['orders'] as $row ) {
				if ( is_array( $row ) && array_key_exists( 'revision', $row ) ) {
					try {
						self::apply_entry( $row );
					} catch ( Throwable $e ) {
						Sudemi_Logger::error( 'Could not apply a sudemi order: ' . $e->getMessage() );
					}
				}
			}
			if ( ! isset( $read['body']['next_offset'] ) || ! is_numeric( $read['body']['next_offset'] ) ) {
				return;
			}
			$filters['offset'] = (int) $read['body']['next_offset'];
		}
	}

	// -------------------------------------------------------------------- retry ----

	/** The orders waiting to be applied again (see OPTION_RETRY). */
	public static function retry_list() {
		$list = get_option( self::OPTION_RETRY, array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Pure: the retry list with one more failed try for an order (added when new).
	 *
	 * @param array       $list The retry list.
	 * @param string      $id   sudemi order id.
	 * @param int         $now  Time.
	 * @param string      $why  What went wrong.
	 * @param string|null $by   cancelled_by from the feed entry, kept for the note (sudemi's order page has none).
	 * @return array
	 */
	public static function retry_add( array $list, $id, $now, $why, $by = null ) {
		$item = isset( $list[ $id ] ) && is_array( $list[ $id ] ) ? $list[ $id ] : array(
			'attempts' => 0,
			'first'    => (int) $now,
		);
		$item['attempts'] = (int) $item['attempts'] + 1;
		$item['last']     = (int) $now;
		$item['why']      = substr( (string) $why, 0, 200 );
		if ( null !== $by && '' !== Sudemi_Orders::cancelled_by( $by ) ) {
			$item['cancelled_by'] = Sudemi_Orders::cancelled_by( $by );
		}
		$list[ $id ] = $item;
		return $list;
	}

	/** Pure: whether an order on the retry list has been tried long or often enough to give up. */
	public static function retry_expired( array $item, $now ) {
		return (int) ( isset( $item['attempts'] ) ? $item['attempts'] : 0 ) >= self::RETRY_MAX_ATTEMPTS
			|| (int) $now - (int) ( isset( $item['first'] ) ? $item['first'] : $now ) >= self::RETRY_MAX_AGE;
	}

	private static function queue_retry( $id, $why, $by = null ) {
		if ( ! Sudemi_Manage::is_order_id( $id ) ) {
			Sudemi_Logger::error( 'Could not apply a sudemi order change: ' . $why );
			return;
		}
		$list = self::retry_add( self::retry_list(), $id, time(), $why, $by );
		update_option( self::OPTION_RETRY, $list, false );
		Sudemi_Logger::warn(
			sprintf(
				/* translators: 1: sudemi order id, 2: what went wrong. */
				__( 'sudemi order %1$s could not be applied here yet (%2$s); it is read from sudemi again on the next heartbeats.', 'sudemi' ),
				$id,
				$list[ $id ]['why']
			)
		);
	}

	/**
	 * Applies the orders on the retry list as sudemi has them now (GET /orders/{id}), a few per
	 * beat. Applied, or nothing to apply: off the list. Still failing: tried again later, and given
	 * up after RETRY_MAX_ATTEMPTS tries or RETRY_MAX_AGE, with an error in the log (never silently).
	 */
	public static function retry_queued() {
		$list = self::retry_list();
		if ( empty( $list ) ) {
			return;
		}
		$now  = time();
		$done = 0;
		foreach ( $list as $id => $item ) {
			if ( $done++ >= self::RETRY_PER_BEAT ) {
				break;
			}
			$id   = (string) $id;
			$item = is_array( $item ) ? $item : array();
			$why  = '';
			try {
				$read = Sudemi_Manage::order( $id );
				if ( $read['ok'] && is_array( $read['body'] ) ) {
					$entry = Sudemi_Manage::entry_from_detail( $read['body'] );
					if ( empty( $entry['cancelled_by'] ) && ! empty( $item['cancelled_by'] ) ) {
						$entry['cancelled_by'] = $item['cancelled_by'];
					}
					if ( 'retry' === self::apply_entry( $entry ) ) {
						$why = 'unreachable';
					}
				} elseif ( ! in_array( $read['error'], array( 'no_such_order', 'previous_connection' ), true ) ) {
					$why = '' === (string) $read['error'] ? 'HTTP ' . (int) $read['status'] : (string) $read['error'];
				} else {
					unset( $list[ $id ] );
					Sudemi_Logger::error(
						sprintf(
							/* translators: 1: sudemi order id, 2: sudemi's answer. */
							__( 'sudemi order %1$s is not applied here: sudemi no longer has it for this shop (%2$s). Check the WooCommerce order by hand.', 'sudemi' ),
							$id,
							(string) $read['error']
						)
					);
					continue;
				}
			} catch ( Throwable $e ) {
				$why = '' === $e->getMessage() ? get_class( $e ) : $e->getMessage();
			}
			if ( '' === $why ) {
				unset( $list[ $id ] );
				continue;
			}
			$list = self::retry_add( $list, $id, $now, $why );
			if ( self::retry_expired( $list[ $id ], $now ) ) {
				Sudemi_Logger::error(
					sprintf(
						/* translators: 1: sudemi order id, 2: number of tries, 3: what went wrong. */
						__( 'Gave up applying sudemi order %1$s here after %2$d tries (%3$s). Check this order under WooCommerce > sudemi > Orders and update the WooCommerce order by hand.', 'sudemi' ),
						$id,
						(int) $list[ $id ]['attempts'],
						$list[ $id ]['why']
					)
				);
				unset( $list[ $id ] );
			}
		}
		update_option( self::OPTION_RETRY, $list, false );
	}

	// -------------------------------------------------------------------- apply ----

	/**
	 * Applies one order as sudemi has it to its WooCommerce order.
	 *
	 * @param array $entry       A changed_orders entry, an order-table row, or entry_from_detail().
	 * @param bool  $materialise Create the WooCommerce order when there is none and sudemi has it
	 *                           accepted or paid (accepted on the portal, or in a bulk action).
	 * @return string 'applied', 'same' (revision not higher), 'skipped', or 'retry' (sudemi could
	 *                not be asked about it just now).
	 */
	public static function apply_entry( array $entry, $materialise = true ) {
		$id = isset( $entry['order_id'] ) && is_string( $entry['order_id'] ) ? strtolower( $entry['order_id'] ) : '';
		if ( ! Sudemi_Manage::is_order_id( $id ) ) {
			return 'skipped';
		}
		$status   = isset( $entry['status'] ) && is_string( $entry['status'] ) ? strtoupper( $entry['status'] ) : '';
		$revision = isset( $entry['revision'] ) && is_numeric( $entry['revision'] ) ? (int) $entry['revision'] : null;

		$order = Sudemi_Orders::find_any_local_order( $id );
		if ( $order && null !== $revision && ! self::is_newer( $order->get_meta( self::META_REVISION ), $revision ) ) {
			return 'same';
		}
		if ( ! $order ) {
			if ( ! $materialise || ! in_array( $status, array( 'ACCEPTED', 'PAID', 'SHIPPED', 'DELIVERED' ), true ) ) {
				return 'skipped';
			}
			$order = self::materialise( $id );
			if ( 'retry' === $order ) {
				return 'retry';
			}
			if ( ! $order ) {
				return 'skipped';
			}
		}

		Sudemi_Orders::quietly(
			function () use ( $id, $order, $status, $entry ) {
				self::apply_to( $id, $order, $status, $entry );
			}
		);

		$order = wc_get_order( $order->get_id() );
		if ( $order ) {
			if ( null !== $revision ) {
				$order->update_meta_data( self::META_REVISION, $revision );
			}
			if ( '' !== $status ) {
				$order->update_meta_data( self::META_STATUS, $status );
			}
			$order->save();
		}
		return 'applied';
	}

	/** Pure: whether an entry's revision is higher than the one the order holds ('' = none yet). */
	public static function is_newer( $held, $revision ) {
		if ( null === $revision ) {
			return true;
		}
		if ( '' === $held || null === $held || false === $held || ! is_numeric( $held ) ) {
			return true;
		}
		return (int) $revision > (int) $held;
	}

	/**
	 * What one status means for a WooCommerce order in a given status. Pure, for the tests.
	 *
	 * @param string $sudemi The sudemi status.
	 * @param string $local  The WooCommerce status (without "wc-").
	 * @return string 'hold', 'processing', 'completed', 'cancel' or '' (nothing to change).
	 */
	public static function transition( $sudemi, $local ) {
		$open = in_array( $local, array( 'on-hold', 'pending' ), true );
		switch ( $sudemi ) {
			case 'ACCEPTED':
				return $open ? 'hold' : '';
			case 'PAID':
				return $open ? 'processing' : '';
			case 'SHIPPED':
			case 'DELIVERED':
				return $open || 'processing' === $local ? 'completed' : '';
			case 'DECLINED':
				return $open ? 'cancel' : '';
			case 'CANCELLED':
				return $open || 'processing' === $local ? 'cancel' : '';
			case 'REFUNDED':
				// Refunded in full before it shipped is a cancellation; after it shipped the refund
				// itself is recorded (WooCommerce then sets the order to Refunded).
				return $open || 'processing' === $local ? 'cancel' : '';
		}
		return '';
	}

	private static function apply_to( $id, $order, $status, array $entry ) {
		$local = $order->get_status();
		switch ( self::transition( $status, $local ) ) {
			case 'hold':
				Sudemi_Orders::hold_for_payment( $id, $order );
				break;
			case 'processing':
				Sudemi_Orders::fill_customer( $order, $id );
				$order->update_status( 'processing', __( 'Paid on sudemi; the buyer has been charged. This order can be shipped.', 'sudemi' ) );
				break;
			case 'completed':
				if ( in_array( $local, array( 'on-hold', 'pending' ), true ) ) {
					Sudemi_Orders::fill_customer( $order, $id );
				}
				$order->update_status( 'completed', __( 'Marked shipped on sudemi.', 'sudemi' ) );
				break;
			case 'cancel':
				$by = isset( $entry['cancelled_by'] ) ? $entry['cancelled_by'] : null;
				if ( 'DECLINED' === $status ) {
					Sudemi_Orders::cancel_locally( $order, Sudemi_Orders::decline_note( $by ) );
					Sudemi_Logger::warn( Sudemi_Orders::decline_log( $by, $id, $order->get_order_number() ) );
				} else {
					Sudemi_Orders::cancel_locally( $order, Sudemi_Orders::cancel_note( $by ) . ' ' . __( 'The stock has been restored.', 'sudemi' ) );
					Sudemi_Logger::warn( Sudemi_Orders::cancel_log( $by, $id, $order->get_order_number() ) );
				}
				break;
		}
		$order = wc_get_order( $order->get_id() );
		if ( ! $order ) {
			return;
		}
		self::apply_tracking( $order, isset( $entry['tracking'] ) ? $entry['tracking'] : null );
		if ( 'DELIVERED' === $status && '' === (string) $order->get_meta( self::META_DELIVERED ) ) {
			$at = isset( $entry['delivered_at'] ) && is_string( $entry['delivered_at'] ) ? $entry['delivered_at'] : gmdate( 'c' );
			$order->update_meta_data( self::META_DELIVERED, $at );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: %s: date and time. */
					__( 'Delivered (recorded on sudemi, %s).', 'sudemi' ),
					Sudemi_Requests::date( $at )
				)
			);
		}
		self::apply_refund( $order, isset( $entry['refunded_minor'] ) && is_numeric( $entry['refunded_minor'] ) ? (int) $entry['refunded_minor'] : 0 );
	}

	/** The carrier and tracking code sudemi has, kept on the order and noted when they change. */
	private static function apply_tracking( $order, $tracking ) {
		if ( ! is_array( $tracking ) ) {
			return;
		}
		$carrier = isset( $tracking['carrier'] ) && is_string( $tracking['carrier'] ) ? $tracking['carrier'] : '';
		$code    = isset( $tracking['code'] ) && is_string( $tracking['code'] ) ? $tracking['code'] : '';
		if ( '' === $carrier && '' === $code ) {
			return;
		}
		if ( (string) $order->get_meta( self::META_CARRIER ) === $carrier && (string) $order->get_meta( self::META_CODE ) === $code ) {
			return;
		}
		$order->update_meta_data( self::META_CARRIER, $carrier );
		$order->update_meta_data( self::META_CODE, $code );
		if ( ! is_array( $order->get_meta( Sudemi_Orders::META_TRACKING_SENT ) ) ) {
			// Shipped on sudemi (portal, this plugin's sudemi page) or before plugin 1.3.0: the
			// shop's own tracking as it is now is the starting point, so only a later change of it
			// here is sent back (never an old code over a newer one from the portal).
			$order->update_meta_data( Sudemi_Orders::META_TRACKING_SENT, Sudemi_Orders::local_tracking( $order ) );
		}
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: 1: carrier, 2: tracking code. */
				__( 'Tracking on sudemi: %1$s %2$s', 'sudemi' ),
				'' === $carrier ? '-' : esc_html( $carrier ),
				'' === $code ? '-' : esc_html( $code )
			)
		);
	}

	/**
	 * Pure: how much of a sudemi refund is not recorded on the WooCommerce order yet, in minor
	 * units, never more than what is left of the order.
	 */
	public static function refund_gap( $sudemi_refunded, $local_refunded, $total ) {
		$gap = (int) $sudemi_refunded - (int) $local_refunded;
		$gap = min( $gap, (int) $total - (int) $local_refunded );
		return max( 0, $gap );
	}

	/**
	 * Records a refund made on sudemi (portal, support, this plugin's sudemi page) as a WooCommerce
	 * refund, so the shop's books show it. No money moves here: sudemi already paid the buyer back,
	 * and the refund is not sent to sudemi again (quietly()). A cancelled order is left as it is.
	 */
	private static function apply_refund( $order, $sudemi_refunded ) {
		if ( $sudemi_refunded <= 0 || $order->has_status( array( 'cancelled', 'failed', 'trash' ) ) ) {
			return;
		}
		// One at a time per order: the heartbeat and an action on the sudemi page (reflect()) can
		// apply the same refund at once. The amount already recorded is read again inside the lock.
		self::locked(
			'refund_' . $order->get_id(),
			function () use ( $order, $sudemi_refunded ) {
				self::record_refund( $order, $sudemi_refunded );
			}
		);
	}

	/**
	 * Runs $fn under a MySQL named lock (GET_LOCK), waiting up to $wait seconds for another
	 * request holding it. Not run at all when the lock cannot be had. Without $wpdb (the tests),
	 * $fn just runs.
	 *
	 * @return mixed What $fn returned, or null when the lock was not had.
	 */
	public static function locked( $name, callable $fn, $wait = 10 ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return $fn();
		}
		$key = substr( 'sudemi_' . $wpdb->prefix . $name, 0, 64 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, (int) $wait ) ) ) {
			Sudemi_Logger::warn( 'Busy: ' . $name . ' is being applied by another request; left to it.' );
			return null;
		}
		try {
			return $fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	private static function record_refund( $order, $sudemi_refunded ) {
		$fresh = wc_get_order( $order->get_id() );
		$order = $fresh ? $fresh : $order;
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			// Another request may have recorded a refund since this one read the order.
			wp_cache_delete( WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'total_refunded' . $order->get_id(), 'orders' );
		}
		$currency = (string) $order->get_meta( Sudemi_Currency::META_ORDER_CURRENCY );
		$currency = '' === $currency ? (string) $order->get_currency() : $currency;
		$gap      = self::refund_gap(
			$sudemi_refunded,
			Sudemi_Currency::to_minor( $order->get_total_refunded(), $currency ),
			Sudemi_Currency::to_minor( $order->get_total(), $currency )
		);
		if ( $gap <= 0 ) {
			return;
		}
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => Sudemi_Currency::to_major( $gap, $currency ),
				'reason'         => __( 'Refund made on sudemi', 'sudemi' ),
				'refund_payment' => false,
				'restock_items'  => false,
			)
		);
		if ( is_wp_error( $refund ) ) {
			Sudemi_Logger::error( 'Could not record the sudemi refund on order ' . $order->get_id() . ': ' . $refund->get_error_message() );
			return;
		}
		$order->add_order_note(
			sprintf(
				/* translators: %s: amount with currency. */
				__( 'A refund of %s made on sudemi is recorded here. The buyer was paid back by sudemi.', 'sudemi' ),
				Sudemi_Currency::to_major( $gap, $currency ) . ' ' . $currency
			)
		);
	}

	/**
	 * Creates the WooCommerce order for a sudemi order this shop accepted elsewhere (on the portal,
	 * or in a bulk action whose answer was lost), so it is packed like any other.
	 *
	 * @return WC_Order|string|null The order, 'retry' when sudemi could not be asked, or null.
	 */
	private static function materialise( $id ) {
		$read = Sudemi_Manage::order( $id );
		if ( ! $read['ok'] ) {
			// Busy or unreachable: ask again. Anything else (no such order here) is final.
			return in_array( $read['error'], array( 'unreachable', 'too_many_requests' ), true ) ? 'retry' : null;
		}
		$detail = $read['body'];
		$placed = isset( $detail['placed_at'] ) ? strtotime( (string) $detail['placed_at'] ) : false;
		if ( ! $placed || $placed < time() - self::MATERIALISE_DAYS * DAY_IN_SECONDS ) {
			return null;
		}
		$pending = Sudemi_Manage::pending_from_detail( $detail );
		$problem = Sudemi_Currency::order_problem( $pending, Sudemi_Currency::shop_currency() );
		if ( null !== $problem ) {
			Sudemi_Logger::error( 'sudemi order ' . $id . ' could not be booked here: ' . $problem );
			return null;
		}
		$got = Sudemi_Orders::obtain_local_order( $pending, $id );
		if ( 'busy' === $got['state'] ) {
			return 'retry'; // Being built right now by another run; read again next time.
		}
		if ( 'existing' === $got['state'] ) {
			return $got['order'];
		}
		$order = $got['order'];
		if ( ! $order ) {
			Sudemi_Logger::error( 'sudemi order ' . $id . ' was accepted on sudemi but could not be booked here (a product in it is no longer in this shop).' );
			return null;
		}
		$order->add_order_note( __( 'Booked from sudemi: this order was accepted on sudemi (on the portal or in a bulk action).', 'sudemi' ) );
		Sudemi_Logger::info(
			sprintf(
				/* translators: 1: sudemi order id, 2: local order number. */
				__( 'sudemi order %1$s was accepted on sudemi; booked here as order #%2$s.', 'sudemi' ),
				$id,
				$order->get_order_number()
			)
		);
		return $order;
	}
}
