<?php
/**
 * Answering sudemi's question: can this shop actually fulfil the order?
 *
 * Nobody but this shop can answer it. sudemi holds no stock and never did - it carries a figure
 * this plugin pushed, which was true when it was pushed and may not be now, because the same
 * units are on sale in this storefront at the same time. So sudemi does not decide. It authorises
 * the buyer's card, holds the money without taking it, and asks.
 *
 * Until this plugin answers, nothing has been charged. Answering yes captures the money and
 * splits it; answering no releases the hold; saying nothing releases it too, once the window
 * closes. Silence is never taken as agreement - a shop that has stopped answering is usually a
 * shop that is gone, and charging a buyer because nobody objected is exactly the failure this
 * mechanism exists to prevent.
 *
 * @package sudemi
 */

defined( 'ABSPATH' ) || exit;

class Sudemi_Orders {

	/** Set on the WooCommerce order so one sudemi order can never produce two local ones. */
	const META_ORDER_ID = '_sudemi_order_id';

	/** When off, orders are left for a human to answer in the sudemi screen rather than confirmed. */
	const OPTION_AUTO_CONFIRM = 'sudemi_auto_confirm';

	/**
	 * Works through what arrived on the heartbeat.
	 *
	 * One order at a time, and a failure on one does not stop the rest: each is a buyer whose
	 * money is on hold, and leaving the others unanswered because the first one broke would
	 * punish them for someone else's problem.
	 *
	 * @param array $pending The pending_orders block of a heartbeat response.
	 */
	public static function handle_pending( $pending ) {
		if ( empty( $pending ) || ! is_array( $pending ) ) {
			return;
		}
		if ( ! self::auto_confirm_enabled() ) {
			Sudemi_Logger::info(
				sprintf(
					/* translators: %d: number of orders waiting. */
					__( '%d order(s) are waiting for your answer under WooCommerce > sudemi > Orders. Automatic confirmation is off, so they must be answered by hand before their window closes.', 'sudemi' ),
					count( $pending )
				)
			);
			return;
		}

		foreach ( $pending as $order ) {
			if ( empty( $order['order_id'] ) ) {
				continue;
			}
			try {
				self::answer( $order );
			} catch ( Exception $e ) {
				Sudemi_Logger::error(
					sprintf(
						/* translators: 1: sudemi order id, 2: error message. */
						__( 'Could not answer sudemi order %1$s: %2$s', 'sudemi' ),
						$order['order_id'],
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Decides one order and tells sudemi.
	 *
	 * The local order is created *before* the acceptance is sent, not after. If it were the other
	 * way round, a failure to build the order would leave the buyer charged for something this
	 * shop has no record of. This way a failure to accept leaves a local order with no money
	 * behind it - which is recoverable here, and is: it is cancelled and the stock put back.
	 *
	 * @param array $pending One entry of the pending_orders block.
	 */
	private static function answer( array $pending ) {
		$sudemi_id = (string) $pending['order_id'];

		$existing = self::find_any_local_order( $sudemi_id );
		if ( $existing && $existing->has_status( self::closed_statuses() ) ) {
			// Built earlier and cancelled here since (by the merchant, or by this plugin). Its stock
			// went back on the shelf when it was cancelled, so accepting it now would take the
			// buyer's money for goods this shop no longer holds for them and will never ship.
			self::reject_closed( $sudemi_id, $existing );
			return;
		}
		if ( $existing ) {
			// Already built, so the previous run got this far and the answer was lost in transit.
			// Re-sending is safe: sudemi answers "already_resolved" if it landed the first time.
			self::accept( $sudemi_id, $existing );
			return;
		}

		// Booked in the currency the buyer's card was charged in, or not at all: an order of
		// 100 PLN booked here as 100 EUR is a different order. Declined at once, so the buyer's
		// hold is released instead of the order waiting out its window.
		$shortfall = Sudemi_Currency::order_problem( $pending, Sudemi_Currency::shop_currency() );
		if ( null !== $shortfall ) {
			Sudemi_Logger::error(
				sprintf(
					/* translators: 1: sudemi order id, 2: reason. */
					__( 'sudemi order %1$s could not be booked: %2$s. Check the shop currency on the sudemi settings page.', 'sudemi' ),
					$sudemi_id,
					$shortfall
				)
			);
		} else {
			$shortfall = self::first_shortfall( $pending );
		}
		if ( null !== $shortfall ) {
			// The ordinary case, and not a failure: a shop that says so promptly is behaving
			// exactly as intended. The buyer's hold is released within the minute.
			Sudemi_Client::post_signed(
				'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/reject',
				array( 'reason' => $shortfall )
			);
			Sudemi_Logger::info(
				sprintf(
					/* translators: 1: sudemi order id, 2: reason. */
					__( 'Declined sudemi order %1$s: %2$s. Nothing was charged.', 'sudemi' ),
					$sudemi_id,
					$shortfall
				)
			);
			return;
		}

		$order = self::create_local_order( $pending, $sudemi_id );
		if ( ! $order ) {
			Sudemi_Client::post_signed(
				'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/reject',
				array( 'reason' => __( 'this shop could not record the order', 'sudemi' ) )
			);
			return;
		}
		self::accept( $sudemi_id, $order );
	}

	/**
	 * Sends the acceptance and applies sudemi's answer to the local order.
	 *
	 * Only a definitive answer changes the local order. "Already answered, and it is PAID" means
	 * an earlier acceptance landed and its reply was lost: the buyer has been charged, so the
	 * order is kept and shipped. Network trouble, a 5xx, a 429 or a 409 is no answer at all: the
	 * buyer may already have been charged, so the order is kept as it is (held, stock taken) and
	 * asked about again on the next heartbeat. Cancelling it there and then is how a paid order
	 * used to end up cancelled and restocked here, and never shipped.
	 *
	 * @param string   $sudemi_id The sudemi order id.
	 * @param WC_Order $order     The local order already created for it.
	 */
	public static function accept( $sudemi_id, $order ) {
		$response = Sudemi_Client::post_signed(
			'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/accept',
			array()
		);
		$outcome = self::accept_outcome( $response );
		self::apply_outcome( $sudemi_id, $order, $outcome, $response );
		return array(
			'outcome'  => $outcome,
			'response' => $response,
		);
	}

	/** Local statuses of an order that is over here: never accepted, shipped or restocked again. */
	public static function closed_statuses() {
		return array( 'cancelled', 'refunded', 'failed', 'trash' );
	}

	/**
	 * What sudemi's answer to an accept means for the local order.
	 *
	 * Pure (no WordPress calls beyond reading the response), so it is unit-tested.
	 *
	 * @param array|WP_Error|null $response What Sudemi_Client returned.
	 * @return string 'paid', 'waiting', 'declined', 'cancelled' or 'retry'.
	 */
	public static function accept_outcome( $response ) {
		if ( ! is_array( $response ) || ! isset( $response['status'] ) ) {
			return 'retry';
		}
		$status = (int) $response['status'];
		$body   = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
		$said   = isset( $body['status'] ) ? (string) $body['status'] : '';

		if ( 200 === $status && 'ok' === $said ) {
			return isset( $body['payment'] ) && 'waiting_for_other_shops' === $body['payment'] ? 'waiting' : 'paid';
		}
		if ( 200 === $status && 'already_resolved' === $said ) {
			return self::outcome_of_status( isset( $body['order_status'] ) ? (string) $body['order_status'] : '' );
		}
		if ( 404 === $status && isset( $body['error'] ) && 'no_such_order' === $body['error'] ) {
			// sudemi has no such order for this shop, so nobody was charged for it here.
			return 'declined';
		}
		return 'retry';
	}

	/**
	 * What sudemi's answer to a reject means. "ok" released the buyer; "already_resolved" says
	 * how the order ended, which may well be PAID (an earlier acceptance landed after all).
	 *
	 * @param array|WP_Error|null $response What Sudemi_Client returned.
	 * @return string 'paid', 'waiting', 'declined', 'cancelled' or 'retry'.
	 */
	public static function reject_outcome( $response ) {
		if ( ! is_array( $response ) || ! isset( $response['status'] ) ) {
			return 'retry';
		}
		$status = (int) $response['status'];
		$body   = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
		$said   = isset( $body['status'] ) ? (string) $body['status'] : '';
		if ( 200 === $status && 'ok' === $said ) {
			return 'declined';
		}
		if ( 200 === $status && 'already_resolved' === $said ) {
			return self::outcome_of_status( isset( $body['order_status'] ) ? (string) $body['order_status'] : '' );
		}
		if ( 404 === $status && isset( $body['error'] ) && 'no_such_order' === $body['error'] ) {
			return 'declined';
		}
		return 'retry';
	}

	/** An order's status on sudemi, as an outcome. Unknown or still open means: ask again later. */
	private static function outcome_of_status( $order_status ) {
		switch ( $order_status ) {
			case 'PAID':
			case 'SHIPPED':
			case 'DELIVERED':
				return 'paid';
			case 'ACCEPTED':
				return 'waiting';
			case 'DECLINED':
				return 'declined';
			case 'CANCELLED':
			case 'REFUNDED':
				return 'cancelled';
			default:
				// AWAITING_SELLER, missing (an older sudemi) or anything new: not an answer.
				return 'retry';
		}
	}

	/**
	 * Applies a definitive outcome to a local order that has not been paid here yet.
	 *
	 * @param string              $sudemi_id The sudemi order id.
	 * @param WC_Order            $order     The local order.
	 * @param string              $outcome   From accept_outcome() or reject_outcome().
	 * @param array|WP_Error|null $response  The raw response, for the log.
	 */
	public static function apply_outcome( $sudemi_id, $order, $outcome, $response ) {
		$body = is_array( $response ) && isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();

		if ( 'retry' === $outcome ) {
			// No answer. The buyer may or may not have been charged; only sudemi knows, so the
			// order stays exactly as it is (held, stock taken) until sudemi says.
			$why = is_wp_error( $response )
				? $response->get_error_message()
				: ( is_array( $response ) && isset( $response['status'] ) ? 'HTTP ' . (int) $response['status']
					. ( isset( $body['error'] ) ? ' ' . (string) $body['error'] : '' ) : __( 'no answer', 'sudemi' ) );
			Sudemi_Logger::warn(
				sprintf(
					/* translators: 1: sudemi order id, 2: error message. */
					__( 'Could not reach sudemi to confirm order %1$s (%2$s). Will try again on the next heartbeat.', 'sudemi' ),
					$sudemi_id,
					$why
				)
			);
			if ( ! $order->get_meta( '_sudemi_retry_noted' ) ) {
				$order->update_meta_data( '_sudemi_retry_noted', time() );
				$order->save();
				$order->add_order_note(
					__( 'sudemi has not answered the confirmation yet. The order stays on hold with its stock reserved and is asked about again on the next heartbeat. Do not ship it until it moves to Processing.', 'sudemi' )
				);
			}
			return;
		}

		if ( 'waiting' === $outcome ) {
			// Accepted, but not paid. The buyer paid several shops in one payment, and a card
			// authorisation can be captured only once - so sudemi takes the money when the last
			// of them has answered, and says so in settled_orders on a later heartbeat. Until
			// then the order stays held and has no address: shipping it now could mean shipping
			// goods nobody ever pays for.
			self::hold_for_payment( $sudemi_id, $order );
			return;
		}

		if ( 'paid' === $outcome ) {
			if ( ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
				return; // Already moved on here (processing, completed...): nothing to change.
			}
			// The address arrives only now. sudemi withholds it while the order is still being
			// decided, because answering "do I have stock" needs nobody's address - so this is
			// the first moment there is a customer to put on the order at all.
			self::fill_customer( $order, $sudemi_id );
			$order->update_status(
				'processing',
				__( 'Confirmed with sudemi; the buyer has been charged.', 'sudemi' )
			);
			Sudemi_Logger::info(
				sprintf(
					/* translators: 1: sudemi order id, 2: local order number. */
					__( 'Confirmed sudemi order %1$s as order #%2$s.', 'sudemi' ),
					$sudemi_id,
					$order->get_order_number()
				)
			);
			return;
		}

		if ( 'cancelled' === $outcome ) {
			// Paid and refunded on sudemi already (the buyer cancelled): do not ship it.
			self::cancel_from_sudemi( $sudemi_id );
			return;
		}

		// Declined: released without being charged (the window closed, the authorisation lapsed,
		// or the buyer withdrew). No money was taken, so the local order must not survive - and
		// the stock it reserved has to go back on the shelf.
		if ( ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return;
		}
		$reason = isset( $body['message'] ) ? (string) $body['message'] : __( 'reason not given', 'sudemi' );
		self::cancel_locally(
			$order,
			sprintf(
				/* translators: %s: the reason sudemi gave. */
				__( 'sudemi did not accept the confirmation: %s. Nothing was charged and the stock has been restored.', 'sudemi' ),
				$reason
			)
		);
		Sudemi_Logger::warn(
			sprintf(
				/* translators: 1: sudemi order id, 2: reason. */
				__( 'sudemi refused the confirmation of order %1$s: %2$s. The local order was cancelled and stock restored.', 'sudemi' ),
				$sudemi_id,
				$reason
			)
		);
	}

	/**
	 * Cancels a local order on this plugin's own initiative (sudemi released it), restoring stock
	 * once. Marked as the plugin's own change, so the status hook does not send it back.
	 */
	public static function cancel_locally( $order, $note ) {
		$before                       = self::$applying_sudemi_cancel;
		self::$applying_sudemi_cancel = true;
		try {
			wc_maybe_increase_stock_levels( $order->get_id() ); // guarded: once per order, whatever copy is in memory
			$order->update_status( 'cancelled', $note );
		} finally {
			self::$applying_sudemi_cancel = $before;
		}
	}

	/**
	 * A sudemi order arrived again (or is being checked) while its local order is cancelled
	 * here. Tells sudemi no, so the buyer is released instead of charged for goods that were put
	 * back on the shelf.
	 *
	 * @param string   $sudemi_id The sudemi order id.
	 * @param WC_Order $order     The cancelled local order.
	 */
	private static function reject_closed( $sudemi_id, $order ) {
		$response = Sudemi_Client::post_signed(
			'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/reject',
			array( 'reason' => 'cancelled in the shop' )
		);
		$outcome = self::reject_outcome( $response );
		if ( 'paid' === $outcome ) {
			// Paid on sudemi although cancelled here: the merchant has to decide (ship it, or
			// cancel it on sudemi so the buyer is refunded). Said loudly, never guessed.
			$order->add_order_note(
				__( 'sudemi says this order is PAID, but it is cancelled here. Either ship it, or refund it to the buyer (set it to Processing and then Cancelled, or contact support@sudemi.com).', 'sudemi' )
			);
			Sudemi_Logger::error( 'sudemi order ' . $sudemi_id . ' is paid on sudemi but cancelled in WooCommerce (order #' . $order->get_order_number() . ').' );
			return;
		}
		if ( 'retry' === $outcome ) {
			Sudemi_Logger::warn( 'Could not tell sudemi that order ' . $sudemi_id . ' was cancelled here; will try again on the next heartbeat.' );
			return;
		}
		Sudemi_Logger::info(
			sprintf(
				/* translators: 1: sudemi order id, 2: reason. */
				__( 'Declined sudemi order %1$s: %2$s. Nothing was charged.', 'sudemi' ),
				$sudemi_id,
				__( 'the order was cancelled in this shop', 'sudemi' )
			)
		);
	}

	/**
	 * Asks again about local orders still on hold that this beat did not cover.
	 *
	 * Needed because sudemi only repeats an order in pending_orders while it is waiting, and in
	 * settled_orders only after an acceptance landed. An acceptance whose reply was lost (now
	 * PAID) and an order the window closed on (now DECLINED) appear in neither, and would sit on
	 * hold here forever with their stock taken. Re-sending is safe: sudemi answers
	 * "already_resolved" with how the order ended. An order still without an answer three days
	 * on (sudemi's answer window is about two) is asked with a reject, which a suspended shop may
	 * still send and which also returns how the order ended.
	 *
	 * @param string[] $handled sudemi order ids this beat already dealt with.
	 */
	public static function reconcile( array $handled ) {
		$orders = wc_get_orders(
			array(
				'limit'        => 25,
				'status'       => array( 'on-hold', 'pending' ),
				'created_via'  => 'sudemi',
				'date_created' => '>' . ( time() - 14 * DAY_IN_SECONDS ),
				'orderby'      => 'date',
				'order'        => 'ASC',
			)
		);
		foreach ( $orders as $order ) {
			$sudemi_id = (string) $order->get_meta( self::META_ORDER_ID );
			if ( '' === $sudemi_id || in_array( $sudemi_id, $handled, true ) ) {
				continue;
			}
			try {
				$response = Sudemi_Client::post_signed(
					'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/accept',
					array()
				);
				$outcome = self::accept_outcome( $response );
				$created = $order->get_date_created();
				if ( 'retry' === $outcome && $created && $created->getTimestamp() < time() - 3 * DAY_IN_SECONDS ) {
					// Still no answer three days on (a suspended shop gets 403 on every accept).
					// A reject is allowed even then and says how the order ended.
					$response = Sudemi_Client::post_signed(
						'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/reject',
						array( 'reason' => 'no confirmation from sudemi in time' )
					);
					$outcome = self::reject_outcome( $response );
				}
				self::apply_outcome( $sudemi_id, $order, $outcome, $response );
			} catch ( Exception $e ) {
				Sudemi_Logger::error( 'Could not check sudemi order ' . $sudemi_id . ': ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Keeps an accepted order on hold while the rest of the buyer's payment is still being decided.
	 *
	 * Said on the order itself, because that is where the merchant deciding what to pack looks.
	 *
	 * @param string   $sudemi_id The sudemi order id.
	 * @param WC_Order $order     The local order.
	 */
	public static function hold_for_payment( $sudemi_id, $order ) {
		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold' );
		}
		if ( $order->get_meta( '_sudemi_waiting_noted' ) ) {
			return; // Said once; the heartbeat checks again on every beat.
		}
		$order->update_meta_data( '_sudemi_waiting_noted', time() );
		$order->save();
		$order->add_order_note(
			__( 'Accepted on sudemi. The buyer paid several shops in one payment; sudemi takes the money once every shop has answered. Do NOT ship until this order moves to Processing.', 'sudemi' )
		);
		Sudemi_Logger::info(
			sprintf(
				/* translators: 1: sudemi order id, 2: local order number. */
				__( 'Accepted sudemi order %1$s (order #%2$s). Not paid yet: waiting for the other shops in the same payment.', 'sudemi' ),
				$sudemi_id,
				$order->get_order_number()
			)
		);
	}

	/**
	 * Works through the settled_orders block of a heartbeat.
	 *
	 * These are orders this shop accepted while the buyer's payment still had other shops to hear
	 * from. sudemi repeats each one for a week, so applying one must change nothing the second
	 * time: only an order still on hold here is touched, and every outcome moves it off hold.
	 *
	 * @param array $settled The settled_orders block of a heartbeat response.
	 */
	public static function handle_settled( $settled ) {
		if ( empty( $settled ) || ! is_array( $settled ) ) {
			return;
		}
		foreach ( $settled as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['order_id'] ) || empty( $entry['status'] ) ) {
				continue;
			}
			try {
				self::settle( $entry );
			} catch ( Exception $e ) {
				Sudemi_Logger::error(
					sprintf(
						/* translators: 1: sudemi order id, 2: error message. */
						__( 'Could not apply the outcome of sudemi order %1$s: %2$s', 'sudemi' ),
						$entry['order_id'],
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Applies one settled order: paid means ship it, declined means it never happened.
	 *
	 * @param array $settled One entry of the settled_orders block.
	 */
	private static function settle( array $settled ) {
		$sudemi_id = (string) $settled['order_id'];
		$state     = (string) $settled['status'];

		if ( 'ACCEPTED' === $state || 'AWAITING_SELLER' === $state ) {
			// Still undecided. sudemi does not list these, and guessing would be worse than waiting.
			return;
		}

		if ( 'CANCELLED' === $state ) {
			// Cancelled on sudemi before it was shipped: by the buyer (free of charge before
			// shipping), by this shop (portal, seller API, this plugin's sudemi page) or by sudemi.
			// The buyer has their money back, so the local order must not be shipped. Handled for
			// an order still on hold and for one already paid.
			self::cancel_from_sudemi( $sudemi_id, isset( $settled['cancelled_by'] ) ? $settled['cancelled_by'] : null );
			return;
		}

		// Only finds an order still on hold. One already processing, completed or cancelled has
		// been settled before - by an earlier beat, or by an accept that captured straight away
		// and is left exactly as it is.
		$order = self::find_local_order( $sudemi_id );
		if ( ! $order ) {
			return;
		}

		if ( 'DECLINED' === $state ) {
			// Released without being charged: the capture failed, or the authorisation lapsed
			// before the last shop answered. Same as a refused acceptance - the local order must
			// not survive, and the stock it reserved goes back on the shelf.
			$reason = ! empty( $settled['declined_reason'] )
				? (string) $settled['declined_reason']
				: __( 'reason not given', 'sudemi' );
			self::cancel_locally(
				$order,
				sprintf(
					/* translators: %s: the reason sudemi gave. */
					__( 'sudemi released the buyer\'s payment: %s. Nothing was charged and the stock has been restored.', 'sudemi' ),
					$reason
				)
			);
			Sudemi_Logger::warn(
				sprintf(
					/* translators: 1: sudemi order id, 2: reason. */
					__( 'sudemi order %1$s was released after all: %2$s. The local order was cancelled and stock restored.', 'sudemi' ),
					$sudemi_id,
					$reason
				)
			);
			return;
		}

		// PAID, or already further along (SHIPPED, DELIVERED…): either way the money was taken,
		// and only now does sudemi release the address.
		self::fill_customer( $order, $sudemi_id );
		$order->update_status(
			'processing',
			__( 'Paid on sudemi: every shop in the buyer\'s payment has answered and the buyer has been charged. This order can be shipped.', 'sudemi' )
		);
		Sudemi_Logger::info(
			sprintf(
				/* translators: 1: sudemi order id, 2: local order number. */
				__( 'sudemi order %1$s is paid; order #%2$s moved to Processing.', 'sudemi' ),
				$sudemi_id,
				$order->get_order_number()
			)
		);
	}

	/**
	 * Set while this plugin applies a change that came from sudemi (a cancellation, a refund, a
	 * dispatch or delivery made on the portal), so the shop's own hooks do not send it back.
	 */
	private static $applying_sudemi_cancel = false;

	/**
	 * Runs $fn with the shop's own order hooks (status changes, refunds, shipped and delivered)
	 * quiet: what it changes came from sudemi and must not be sent there again.
	 *
	 * @param callable $fn What to run.
	 * @return mixed What $fn returned.
	 */
	public static function quietly( callable $fn ) {
		$before                       = self::$applying_sudemi_cancel;
		self::$applying_sudemi_cancel = true;
		try {
			return $fn();
		} finally {
			self::$applying_sudemi_cancel = $before;
		}
	}

	/** Whether a change from sudemi is being applied right now (see quietly()). */
	public static function applying_sudemi() {
		return self::$applying_sudemi_cancel;
	}

	/**
	 * Who ended a cancelled or declined sudemi order, as sudemi says it (cancelled_by, core
	 * 2026-11-09): 'buyer', 'shop' or 'sudemi'; '' when sudemi did not say (an older core).
	 *
	 * @param mixed $value The cancelled_by field.
	 * @return string
	 */
	public static function cancelled_by( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return in_array( $value, array( 'buyer', 'shop', 'sudemi' ), true ) ? $value : '';
	}

	/**
	 * The order note for an order cancelled on sudemi before it shipped, saying who cancelled it.
	 * Never "the buyer" unless sudemi said so: a shop that cancelled an order itself must not read
	 * that its buyer did.
	 *
	 * @param mixed $by The cancelled_by field (see cancelled_by()).
	 * @return string
	 */
	public static function cancel_note( $by ) {
		switch ( self::cancelled_by( $by ) ) {
			case 'buyer':
				return __( 'Cancelled by the buyer on sudemi before shipping; the buyer has been refunded in full. Do not ship this order.', 'sudemi' );
			case 'shop':
				return __( 'Cancelled by your shop on sudemi before shipping; the buyer has been refunded in full. Do not ship this order.', 'sudemi' );
			case 'sudemi':
				return __( 'Cancelled by sudemi before shipping (for example because the order was not answered in time); the buyer has been refunded in full. Do not ship this order.', 'sudemi' );
		}
		return __( 'Cancelled on sudemi before shipping; the buyer has been refunded in full. Do not ship this order.', 'sudemi' );
	}

	/**
	 * The log line for the same, with who cancelled it.
	 *
	 * @param mixed  $by        The cancelled_by field.
	 * @param string $sudemi_id The sudemi order id.
	 * @param string $number    The local order number.
	 * @return string
	 */
	public static function cancel_log( $by, $sudemi_id, $number ) {
		switch ( self::cancelled_by( $by ) ) {
			case 'buyer':
				/* translators: 1: sudemi order id, 2: local order number. */
				$text = __( 'sudemi order %1$s was cancelled by the buyer before shipping; order #%2$s was cancelled here and the stock restored.', 'sudemi' );
				break;
			case 'shop':
				/* translators: 1: sudemi order id, 2: local order number. */
				$text = __( 'sudemi order %1$s was cancelled by your shop on sudemi before shipping; order #%2$s was cancelled here and the stock restored.', 'sudemi' );
				break;
			case 'sudemi':
				/* translators: 1: sudemi order id, 2: local order number. */
				$text = __( 'sudemi order %1$s was cancelled by sudemi before shipping; order #%2$s was cancelled here and the stock restored.', 'sudemi' );
				break;
			default:
				/* translators: 1: sudemi order id, 2: local order number. */
				$text = __( 'sudemi order %1$s was cancelled on sudemi before shipping; order #%2$s was cancelled here and the stock restored.', 'sudemi' );
		}
		return sprintf( $text, $sudemi_id, $number );
	}

	/**
	 * Cancels the local order for a sudemi order that was cancelled there before shipping (by
	 * the buyer, by this shop, or by sudemi). Only an order that has not left the shop (on hold,
	 * pending or processing) is touched; one already cancelled, refunded or completed is left as
	 * it is, so applying the same news on every beat changes nothing after the first time.
	 *
	 * @param string $sudemi_id The sudemi order id.
	 * @param mixed  $by        Who cancelled it (cancelled_by), null when sudemi did not say.
	 */
	private static function cancel_from_sudemi( $sudemi_id, $by = null ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => self::META_ORDER_ID,
				'meta_value' => $sudemi_id,
				'status'     => array( 'on-hold', 'pending', 'processing' ),
			)
		);
		if ( empty( $orders ) ) {
			return;
		}
		$order = $orders[0];
		$before                       = self::$applying_sudemi_cancel;
		self::$applying_sudemi_cancel = true;
		try {
			// WooCommerce puts the stock back when an order is cancelled; this guard makes sure
			// it happens once whichever copy of the order is in memory.
			wc_maybe_increase_stock_levels( $order->get_id() );
			$order->update_status( 'cancelled', self::cancel_note( $by ) );
		} finally {
			self::$applying_sudemi_cancel = $before;
		}
		Sudemi_Logger::warn( self::cancel_log( $by, $sudemi_id, $order->get_order_number() ) );
	}

	/**
	 * Puts the buyer's delivery address on the order, now that sudemi will release it.
	 *
	 * Without this a merchant has a paid order and nowhere to send it. The address is written to
	 * the shipping fields WooCommerce already uses, so packing slips, shipping extensions and
	 * label printers all find it where they expect to.
	 *
	 * No billing address is set: sudemi is the merchant of record for the payment, so there is
	 * no invoice for this shop to issue to the buyer and no reason for it to hold more of their
	 * data than the parcel needs.
	 *
	 * @param WC_Order $order
	 * @param string   $sudemi_id
	 */
	public static function fill_customer( $order, $sudemi_id ) {
		$response = Sudemi_Client::get_signed(
			'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/fulfilment'
		);
		if ( is_wp_error( $response ) || 200 !== (int) $response['status'] ) {
			// Not fatal: the order is paid and real, and the address can be fetched again. Said
			// loudly on the order itself rather than only in a log, because a merchant about to
			// pack this needs to know it has no destination yet.
			$order->add_order_note(
				__( 'Could not fetch the delivery address from sudemi yet. The order is paid; open it again shortly or contact support before shipping.', 'sudemi' )
			);
			return;
		}

		$body = isset( $response['body'] ) ? $response['body'] : array();
		if ( empty( $body['deliverable'] ) || empty( $body['ship_to'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: the explanation sudemi gave. */
					__( 'sudemi has no delivery address for this order: %s', 'sudemi' ),
					isset( $body['message'] ) ? (string) $body['message'] : __( 'reason not given', 'sudemi' )
				)
			);
			return;
		}

		$to = $body['ship_to'];
		$name = isset( $to['recipient_name'] ) ? trim( (string) $to['recipient_name'] ) : '';
		// WooCommerce wants the name split, and a recipient line is not reliably "first last" in
		// every locale. Everything before the last space is the first name, which is wrong for
		// some names and never loses any part of one.
		$space = strrpos( $name, ' ' );
		$order->set_shipping_first_name( false === $space ? $name : substr( $name, 0, $space ) );
		$order->set_shipping_last_name( false === $space ? '' : substr( $name, $space + 1 ) );
		$order->set_shipping_address_1( isset( $to['line1'] ) ? (string) $to['line1'] : '' );
		$order->set_shipping_address_2( isset( $to['line2'] ) ? (string) $to['line2'] : '' );
		$order->set_shipping_postcode( isset( $to['postal_code'] ) ? (string) $to['postal_code'] : '' );
		$order->set_shipping_city( isset( $to['city'] ) ? (string) $to['city'] : '' );
		$order->set_shipping_state( isset( $to['region'] ) ? (string) $to['region'] : '' );
		$order->set_shipping_country( isset( $to['country'] ) ? (string) $to['country'] : '' );
		if ( ! empty( $to['phone'] ) && is_callable( array( $order, 'set_shipping_phone' ) ) ) {
			$order->set_shipping_phone( (string) $to['phone'] );
		}
		$order->save();
	}

	/**
	 * The first line this shop cannot supply, or null when it can supply all of them.
	 *
	 * Checked against live WooCommerce stock rather than against anything this plugin last
	 * pushed: what was true at the last sync is precisely what cannot be relied on here.
	 *
	 * @param array $pending One entry of the pending_orders block.
	 * @return string|null A reason to decline, or null to go ahead.
	 */
	public static function first_shortfall( array $pending ) {
		$lines = isset( $pending['lines'] ) && is_array( $pending['lines'] ) ? $pending['lines'] : array();
		if ( empty( $lines ) ) {
			return __( 'the order had no recognisable items', 'sudemi' );
		}

		foreach ( $lines as $line ) {
			$product = self::resolve_product( $line );
			if ( ! $product ) {
				return sprintf(
					/* translators: %s: the product title as sudemi knows it. */
					__( '"%s" is no longer sold here', 'sudemi' ),
					isset( $line['title'] ) ? (string) $line['title'] : __( 'an item', 'sudemi' )
				);
			}
			$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
			if ( ! $product->is_in_stock() || ! $product->has_enough_stock( $quantity ) ) {
				return sprintf(
					/* translators: %s: the product name. */
					__( '"%s" is out of stock', 'sudemi' ),
					$product->get_name()
				);
			}
			if ( ! $product->is_purchasable() ) {
				return sprintf(
					/* translators: %s: the product name. */
					__( '"%s" cannot currently be bought', 'sudemi' ),
					$product->get_name()
				);
			}
		}
		return null;
	}

	/**
	 * Builds the WooCommerce order, so the merchant fulfils it through their normal workflow.
	 *
	 * Creating a real order rather than recording the sale somewhere of our own is what makes
	 * stock come down, the order appear where the merchant already looks, and picking, packing
	 * and shipping work exactly as they do for any other sale.
	 *
	 * @param array  $pending   One entry of the pending_orders block.
	 * @param string $sudemi_id The sudemi order id.
	 * @return WC_Order|null
	 */
	public static function create_local_order( array $pending, $sudemi_id ) {
		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			return null;
		}

		// What the buyer paid, from sudemi. Null from a core that does not send it yet, and then
		// the order is priced from the catalogue as before.
		$paid    = self::paid_amounts( $pending );
		$country = isset( $pending['destination_country'] ) ? (string) $pending['destination_country'] : '';
		// The currency the buyer's card was charged in (answer() declined any other); a core
		// that does not send one charged in the shop's currency.
		$currency = ! empty( $pending['currency_code'] )
			? Sudemi_Currency::normalise( $pending['currency_code'] )
			: Sudemi_Currency::shop_currency();
		$order->set_currency( $currency );
		// The tax classes of the taxable goods: the shop's shipping tax class can follow them.
		$item_classes = array();
		$order->update_meta_data( Sudemi_Currency::META_ORDER_CURRENCY, $currency );

		foreach ( $pending['lines'] as $index => $line ) {
			$product = self::resolve_product( $line );
			if ( ! $product ) {
				$order->delete( true );
				return null;
			}
			$quantity = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
			if ( null === $paid ) {
				$order->add_product( $product, $quantity );
				continue;
			}
			// The buyer's price wins over today's catalogue price: the price may have changed
			// since the last sync, and the shop's order has to say what was actually paid.
			$gross   = $paid['lines'][ $index ] * $quantity;
			if ( $product->is_taxable() ) {
				$item_classes[] = $product->get_tax_class();
			}
			$rates   = self::tax_rates( $country, $product->is_taxable() ? $product->get_tax_class() : null, false );
			$taxes   = self::taxes_within( $gross, $rates, $currency );
			$net     = self::major( $gross - array_sum( $taxes ), $currency );
			$item_id = $order->add_product(
				$product,
				$quantity,
				array(
					'subtotal' => $net,
					'total'    => $net,
				)
			);
			if ( ! $item_id ) {
				$order->delete( true );
				return null;
			}
			// The order's own copy (not a fresh one from the database): the order saves its cached
			// items later, and taxes set on another copy would be overwritten by that save.
			$item = $order->get_item( $item_id, false );
			if ( $item ) {
				$item->set_taxes( self::tax_array( $taxes, true, $currency ) );
				$item->save();
			}
		}

		if ( null !== $paid ) {
			$shipping = new WC_Order_Item_Shipping();
			$shipping->set_method_title( __( 'sudemi shipping', 'sudemi' ) );
			$shipping->set_method_id( 'sudemi' );
			// Taxed the way the shop's own checkout taxes shipping (its shipping tax class setting).
			$taxes = self::taxes_within( $paid['shipping'], self::tax_rates( $country, Sudemi_Shipping::tax_class( $item_classes ), true ), $currency );
			$shipping->set_total( self::major( $paid['shipping'] - array_sum( $taxes ), $currency ) );
			$shipping->set_taxes( self::tax_array( $taxes, false, $currency ) );
			$order->add_item( $shipping );
		}

		// The buyer paid on sudemi, so the order says so; a blank payment method reads in the
		// shop's books as an order nobody has paid for.
		$order->set_payment_method( 'sudemi' );
		$order->set_payment_method_title( __( 'sudemi', 'sudemi' ) );
		$order->update_meta_data( self::META_ORDER_ID, $sudemi_id );
		$order->set_created_via( 'sudemi' );
		if ( '' !== $country ) {
			$order->set_shipping_country( $country );
		}
		// Deliberately no buyer name, address or contact: sudemi does not send them with the
		// question, because a shop deciding whether it has stock does not need to know who is
		// asking. They arrive once the order is confirmed.
		$order->add_order_note(
			sprintf(
				/* translators: %s: the sudemi order id. */
				__( 'Placed through sudemi (order %s). The buyer has been authorised but not yet charged.', 'sudemi' ),
				$sudemi_id
			)
		);
		if ( null === $paid ) {
			$order->calculate_totals();
		} else {
			// The VAT was split out of the paid amounts above; recalculating it here from the
			// shop's own settings could move the total away from what the buyer paid.
			$order->update_taxes();
			$order->calculate_totals( false );
			if ( self::minor_of( $order->get_total(), $currency ) !== $paid['total'] ) {
				// Only reachable through rounding in a shop's tax set-up; the total is what the
				// buyer paid, whatever the parts add up to.
				$order->set_total( self::major( $paid['total'], $currency ) );
			}
		}
		// Held rather than processing until sudemi confirms the capture: an order that looks
		// payable before the money is taken is one somebody ships too early.
		$order->update_status( 'on-hold', __( 'Awaiting confirmation with sudemi.', 'sudemi' ) );
		$order->save();

		// Taken now, with the order, so this shop's storefront cannot sell the same unit while
		// the confirmation is in flight. Put back by accept() if sudemi refuses.
		// The guarded call: the on-hold status above already fired WooCommerce's own
		// wc_maybe_reduce_stock_levels on a freshly loaded copy of this order, and an unguarded
		// wc_reduce_stock_levels on this (stale) object took the stock a second time.
		wc_maybe_reduce_stock_levels( $order->get_id() );

		return $order;
	}

	/**
	 * What the buyer paid for a pending order, in minor units: the shipping, each line's unit
	 * price (keyed like the lines) and the total.
	 *
	 * Null when sudemi did not send them (a core older than this plugin) or sent something that
	 * is not money; the order is then priced from the catalogue, as before.
	 *
	 * @param array $pending One entry of the pending_orders block.
	 * @return array|null
	 */
	public static function paid_amounts( array $pending ) {
		if ( ! isset( $pending['shipping_minor'], $pending['total_minor'] ) || empty( $pending['lines'] )
			|| ! is_array( $pending['lines'] ) || ! is_numeric( $pending['shipping_minor'] )
			|| ! is_numeric( $pending['total_minor'] ) || (int) $pending['shipping_minor'] < 0 ) {
			return null;
		}
		$lines = array();
		foreach ( $pending['lines'] as $index => $line ) {
			if ( ! is_array( $line ) || ! isset( $line['unit_price_minor'] ) || ! is_numeric( $line['unit_price_minor'] )
				|| (int) $line['unit_price_minor'] < 0 ) {
				return null;
			}
			$lines[ $index ] = (int) $line['unit_price_minor'];
		}
		return array(
			'shipping' => (int) $pending['shipping_minor'],
			'lines'    => $lines,
			'total'    => (int) $pending['total_minor'],
		);
	}

	/**
	 * The VAT inside a gross amount, per tax rate id, in minor units.
	 *
	 * The amounts sudemi sends include VAT (the sync sends prices with tax), so the tax is taken
	 * out of them rather than added on top: net plus tax is always exactly what was paid.
	 *
	 * @param int    $gross_minor The amount with VAT.
	 * @param array  $rates       WC_Tax rates, as find_rates() returns them.
	 * @param string $currency    The order's currency (decides the minor unit).
	 * @return array<int,int> Rate id to tax.
	 */
	private static function taxes_within( $gross_minor, array $rates, $currency ) {
		if ( $gross_minor <= 0 || empty( $rates ) ) {
			return array();
		}
		$out = array();
		foreach ( WC_Tax::calc_tax( self::major( $gross_minor, $currency ), $rates, true ) as $rate_id => $tax ) {
			$out[ $rate_id ] = self::minor_of( $tax, $currency );
		}
		if ( array_sum( $out ) >= $gross_minor ) {
			return array();
		}
		return $out;
	}

	/**
	 * The shop's tax rates for the destination, or none when the shop does not charge tax.
	 *
	 * @param string      $country   ISO country code of the destination.
	 * @param string|null $tax_class The product's tax class; null for a product that is not taxable.
	 * @param bool        $shipping  Rates for shipping rather than goods.
	 * @return array
	 */
	private static function tax_rates( $country, $tax_class, $shipping ) {
		if ( null === $tax_class || '' === $country || ! wc_tax_enabled() ) {
			return array();
		}
		$args = array(
			'country'   => $country,
			'tax_class' => $tax_class,
		);
		return $shipping ? WC_Tax::find_shipping_rates( $args ) : WC_Tax::find_rates( $args );
	}

	/**
	 * Taxes in the shape an order item stores them.
	 *
	 * @param array<int,int> $taxes        Rate id to tax, in minor units.
	 * @param bool           $with_subtotal Line items carry a subtotal tax as well.
	 * @param string         $currency      The order's currency.
	 * @return array
	 */
	private static function tax_array( array $taxes, $with_subtotal, $currency ) {
		$total = array();
		foreach ( $taxes as $rate_id => $tax ) {
			$total[ $rate_id ] = self::major( $tax, $currency );
		}
		return $with_subtotal ? array(
			'total'    => $total,
			'subtotal' => $total,
		) : array( 'total' => $total );
	}

	/** Minor units as the decimal string WooCommerce stores ("8.50"), in the currency's own minor unit. */
	public static function major( $minor, $currency = null ) {
		return Sudemi_Currency::to_major( $minor, null === $currency ? Sudemi_Currency::shop_currency() : $currency );
	}

	/** A WooCommerce amount in the currency's minor units. */
	public static function minor_of( $amount, $currency = null ) {
		return Sudemi_Currency::to_minor( $amount, null === $currency ? Sudemi_Currency::shop_currency() : $currency );
	}

	/**
	 * The WooCommerce product behind a line.
	 *
	 * external_id is this shop's own product id - it is what the sync pushes as the identifier,
	 * which is the whole reason sudemi stores it.
	 *
	 * @param array $line One entry of a pending order's lines.
	 * @return WC_Product|null
	 */
	private static function resolve_product( array $line ) {
		if ( empty( $line['external_id'] ) ) {
			return null;
		}
		$product = wc_get_product( (int) $line['external_id'] );
		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * The local order already built for a sudemi order, if there is one.
	 *
	 * @param string $sudemi_id The sudemi order id.
	 * @return WC_Order|null
	 */
	private static function find_local_order( $sudemi_id ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => self::META_ORDER_ID,
				'meta_value' => $sudemi_id,
				'status'     => array( 'on-hold' ),
			)
		);
		return empty( $orders ) ? null : $orders[0];
	}

	/**
	 * The local order for a sudemi order in any status, cancelled ones included, so a cancelled
	 * order is recognised (and refused) rather than built a second time.
	 *
	 * @param string $sudemi_id The sudemi order id.
	 * @return WC_Order|null
	 */
	public static function find_any_local_order( $sudemi_id ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => self::META_ORDER_ID,
				'meta_value' => $sudemi_id,
				'status'     => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ),
			)
		);
		return empty( $orders ) ? null : $orders[0];
	}

	public static function auto_confirm_enabled() {
		return 'no' !== get_option( self::OPTION_AUTO_CONFIRM, 'yes' );
	}

	/**
	 * The shop's order history as sudemi holds it.
	 *
	 * Read from sudemi rather than from this shop's own orders, because the two can differ and
	 * the difference is the interesting part: an order sudemi declined on this shop's behalf
	 * because nobody answered in time leaves no WooCommerce order at all, and a merchant looking
	 * only at their own list would never know it happened.
	 *
	 * @param string $status Optional filter: AWAITING_SELLER, PAID, DECLINED, SHIPPED, DELIVERED.
	 * @param int    $limit  How many to fetch.
	 * @return array|null The decoded response, or null when sudemi could not be reached.
	 */
	public static function history( $status = '', $limit = 25 ) {
		$path = '/integration/v1/orders?limit=' . (int) $limit;
		if ( ! empty( $status ) ) {
			$path .= '&status=' . rawurlencode( $status );
		}
		$response = Sudemi_Client::get_signed( $path );
		if ( is_wp_error( $response ) || 200 !== (int) $response['status'] ) {
			return null;
		}
		return isset( $response['body'] ) ? $response['body'] : null;
	}

	/**
	 * Reports dispatch and delivery back to sudemi when the merchant moves the order on.
	 *
	 * Hooked to the shop's own status changes rather than asking the merchant to do anything
	 * extra: they already mark orders complete as part of packing, and a step that has to be
	 * remembered is a step that gets skipped. The buyer is told by sudemi as soon as this lands,
	 * and a buyer who is not told their order shipped assumes it did not.
	 */
	public static function boot() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'report_shipped' ), 10, 1 );
		// WooCommerce has no 'delivered' status of its own. Several shipping plugins add one, and
		// this catches it when present without depending on any of them being installed.
		add_action( 'woocommerce_order_status_delivered', array( __CLASS__, 'report_delivered' ), 10, 1 );
		// sudemi holds the buyer's payment, so a refund or cancellation made here has to reach it:
		// otherwise the shop records a refund and the buyer never gets the money back.
		add_action( 'woocommerce_refund_created', array( __CLASS__, 'on_refund_created' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
		// Tracking typed (or corrected) after the order was reported shipped: sent to sudemi, which
		// tells the buyer the new code. Every way a shop or a shipment plugin saves it: an order
		// save (HPOS and posts storage, the admin screen, REST, $order->save()) and post meta
		// written directly (posts storage). Checked once per order at the end of the request.
		add_action( 'woocommerce_update_order', array( __CLASS__, 'on_order_saved' ), 10, 1 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'on_order_saved' ), 100, 1 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_post_meta' ), 10, 3 );
	}

	/**
	 * A refund made in the WooCommerce admin (full or partial) is passed to sudemi, which pays it
	 * back to the buyer from the payment it holds and takes it from this shop's payout.
	 *
	 * @param int   $refund_id The WooCommerce refund.
	 * @param array $args      Its arguments (order_id, amount, reason...).
	 */
	public static function on_refund_created( $refund_id, $args ) {
		if ( self::$applying_sudemi_cancel ) {
			// A refund made on sudemi (portal or this plugin's sudemi page), recorded here: the
			// money already went back to the buyer, so it is not sent a second time.
			return;
		}
		$refund = wc_get_order( $refund_id );
		if ( ! $refund ) {
			return;
		}
		$order = wc_get_order( $refund->get_parent_id() );
		if ( ! $order ) {
			return;
		}
		$sudemi_id = $order->get_meta( self::META_ORDER_ID );
		if ( empty( $sudemi_id ) ) {
			return;
		}
		$body = self::refund_body( $refund, $order );
		if ( is_string( $body ) ) {
			// Not sent: a refund in another currency than the buyer paid in would pay back a
			// different amount than the shop meant. Said on the order, where the merchant looks.
			$order->add_order_note(
				sprintf(
					/* translators: %s: why the refund was not sent. */
					__( 'This refund was NOT sent to sudemi: %s. The buyer has not been paid back.', 'sudemi' ),
					$body
				)
			);
			Sudemi_Logger::error( 'Refund for order ' . $order->get_id() . ' not sent to sudemi: ' . $body );
			return;
		}
		$response = Sudemi_Client::post_signed(
			'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/refund',
			$body
		);
		self::note_money_result(
			$order,
			$response,
			wc_price( self::major( $body['amountMinor'], $body['currency'] ), array( 'currency' => $body['currency'] ) )
		);
	}

	/**
	 * The body of POST /orders/{id}/refund for a WooCommerce refund, or the reason it cannot be
	 * sent. The amount is in the currency the buyer was charged in, and says which one that is.
	 *
	 * @param WC_Order_Refund $refund The refund.
	 * @param WC_Order        $order  The order it belongs to.
	 * @return array|string
	 */
	public static function refund_body( $refund, $order ) {
		$charged = (string) $order->get_meta( Sudemi_Currency::META_ORDER_CURRENCY );
		if ( '' === $charged ) {
			// Booked before this plugin recorded the currency: the order's own is what was charged.
			$charged = (string) $order->get_currency();
		}
		$charged  = Sudemi_Currency::normalise( $charged );
		$currency = (string) $refund->get_currency();
		if ( '' === $currency ) {
			$currency = (string) $order->get_currency();
		}
		$problem = Sudemi_Currency::refund_problem( $currency, $charged );
		if ( null === $problem ) {
			$problem = Sudemi_Currency::refund_problem( $order->get_currency(), $charged );
		}
		if ( null !== $problem ) {
			return $problem;
		}
		$reason = (string) $refund->get_reason();
		return array(
			'amountMinor' => Sudemi_Currency::to_minor( $refund->get_amount(), $charged ),
			'currency'    => $charged,
			'reason'      => '' === $reason ? null : $reason,
		);
	}

	/**
	 * Cancelling an order the buyer has already paid for refunds it in full on sudemi. Orders
	 * this plugin cancels itself (a released payment) never were paid, so they are left alone.
	 */
	public static function on_status_changed( $order_id, $from, $to ) {
		if ( 'cancelled' !== $to || ! in_array( $from, array( 'processing', 'completed', 'on-hold', 'pending' ), true ) ) {
			return;
		}
		if ( self::$applying_sudemi_cancel ) {
			// sudemi cancelled or released it already; sending it back would ask twice.
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$sudemi_id = $order->get_meta( self::META_ORDER_ID );
		if ( empty( $sudemi_id ) ) {
			return;
		}
		if ( in_array( $from, array( 'on-hold', 'pending' ), true ) ) {
			// Not paid yet: still waiting for this shop's answer, or accepted and waiting for the
			// other shops in the buyer's payment. sudemi refuses /cancel for those (there is
			// nothing to refund yet) and would take the money later; /reject releases the buyer.
			self::reject_cancelled( (string) $sudemi_id, $order );
			return;
		}
		self::cancel_paid( (string) $sudemi_id, $order );
	}

	/** A paid order cancelled here: refunded in full and cancelled on sudemi. */
	private static function cancel_paid( $sudemi_id, $order ) {
		$response = Sudemi_Client::post_signed(
			'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/cancel',
			array( 'reason' => 'Cancelled by the shop' )
		);
		self::note_money_result( $order, $response, __( 'the full amount', 'sudemi' ) );
	}

	/**
	 * An unpaid order cancelled here: the buyer is released on sudemi. If sudemi has taken the
	 * money in the meantime (the last shop in the payment answered), the order is cancelled
	 * there with a full refund instead. Without an answer the call is queued and retried on the
	 * next heartbeat.
	 *
	 * @param string   $sudemi_id The sudemi order id.
	 * @param WC_Order $order     The local order.
	 * @return bool true when sudemi gave a definitive answer.
	 */
	public static function reject_cancelled( $sudemi_id, $order, $retrying = false ) {
		$response = Sudemi_Client::post_signed(
			'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/reject',
			array( 'reason' => 'Cancelled by the shop' )
		);
		$outcome = self::reject_outcome( $response );
		if ( 'retry' === $outcome || 'waiting' === $outcome ) {
			Sudemi_Order_Calls::enqueue( $sudemi_id, 'reject', array( 'reason' => 'Cancelled by the shop' ), $order->get_id() );
			if ( $retrying ) {
				return false;
			}
			$order->add_order_note(
				__( 'Could not tell sudemi about this cancellation yet. It is retried on the next heartbeat; the buyer is not charged for it.', 'sudemi' )
			);
			return false;
		}
		if ( 'paid' === $outcome ) {
			self::cancel_paid( $sudemi_id, $order );
			return true;
		}
		$order->add_order_note( __( 'Cancelled on sudemi as well: the buyer was not charged for this order.', 'sudemi' ) );
		return true;
	}

	/** Puts the outcome on the order, where the merchant looks, and in the sudemi log. */
	private static function note_money_result( $order, $response, $what ) {
		if ( ! is_wp_error( $response ) && 200 === (int) $response['status'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: the amount refunded, e.g. "€12.00" or "the full amount". */
					__( 'Refunded %s to the buyer through sudemi.', 'sudemi' ),
					$what
				)
			);
			return;
		}
		$message = is_wp_error( $response )
			? $response->get_error_message()
			: ( isset( $response['body']['message'] ) ? (string) $response['body']['message'] : (string) $response['status'] );
		$order->add_order_note(
			sprintf(
				/* translators: %s: the reason sudemi gave. */
				__( 'sudemi did not refund this: %s. The buyer has NOT been paid back; contact sudemi support (support@sudemi.com).', 'sudemi' ),
				$message
			)
		);
		Sudemi_Logger::error( 'Refund for order ' . $order->get_id() . ' refused by sudemi: ' . $message );
	}

	public static function report_shipped( $order_id ) {
		self::report( $order_id, 'shipped' );
	}

	public static function report_delivered( $order_id ) {
		self::report( $order_id, 'delivered' );
	}

	/**
	 * @param int    $order_id A WooCommerce order id.
	 * @param string $event    'shipped' or 'delivered'.
	 */
	private static function report( $order_id, $event ) {
		if ( self::$applying_sudemi_cancel ) {
			// Shipped or delivered on sudemi already (portal, or this plugin's sudemi page).
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$sudemi_id = $order->get_meta( self::META_ORDER_ID );
		if ( empty( $sudemi_id ) ) {
			// Not a sudemi order. Most of a shop's orders are not, and this hook sees all of them.
			return;
		}

		$body = array();
		if ( 'shipped' === $event ) {
			// Read from whatever the merchant already uses. No tracking is not an error: sudemi
			// says "not given" rather than withholding the dispatch notice over a missing field.
			$body = array(
				'carrier'      => self::tracking_value( $order, 'carrier' ),
				'trackingCode' => self::tracking_value( $order, 'code' ),
			);
			// What went with the dispatch, so a later change of the tracking here is told apart
			// from an unrelated save of the order (see check_tracking()).
			self::remember_tracking( $order, self::local_tracking( $order ) );
		}

		self::send_report( (string) $sudemi_id, $event, $body, $order->get_id() );
	}

	/**
	 * Sends shipped/delivered and says honestly whether it landed. A failure is queued and sent
	 * again on the next heartbeat: a dispatch nobody hears about leaves the buyer without
	 * tracking, and a delivery nobody hears about never starts the withdrawal period.
	 *
	 * @return bool true when the call is settled (landed, or refused for good).
	 */
	public static function send_report( $sudemi_id, $event, array $body, $local_id ) {
		if ( 'tracking' === $event ) {
			return 'retry' !== self::send_tracking( $sudemi_id, $body, $local_id );
		}
		$response = Sudemi_Client::post_signed(
			'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/' . $event,
			$body
		);
		$verdict = self::report_verdict( $response );
		if ( 'done' === $verdict ) {
			Sudemi_Order_Calls::remove( $sudemi_id, $event );
			Sudemi_Logger::info(
				sprintf(
					/* translators: 1: event name, 2: sudemi order id. */
					__( 'Reported %1$s for sudemi order %2$s.', 'sudemi' ),
					$event,
					$sudemi_id
				)
			);
			return true;
		}
		$message = is_wp_error( $response ) ? $response->get_error_message()
			: ( is_array( $response ) ? 'HTTP ' . (int) $response['status']
				. ( isset( $response['body']['error'] ) ? ' ' . (string) $response['body']['error'] : '' ) : '' );
		if ( 'refused' === $verdict ) {
			Sudemi_Order_Calls::remove( $sudemi_id, $event );
			Sudemi_Logger::error(
				sprintf(
					/* translators: 1: event name, 2: sudemi order id, 3: error message. */
					__( 'Could not report %1$s for sudemi order %2$s: %3$s', 'sudemi' ),
					$event,
					$sudemi_id,
					$message
				)
			);
			return true;
		}
		Sudemi_Order_Calls::enqueue( $sudemi_id, $event, $body, $local_id );
		Sudemi_Logger::warn(
			sprintf(
				/* translators: 1: event name, 2: sudemi order id, 3: error message. */
				__( 'Could not report %1$s for sudemi order %2$s yet (%3$s). It is sent again on the next heartbeat.', 'sudemi' ),
				$event,
				$sudemi_id,
				$message
			)
		);
		return false;
	}

	/**
	 * 'done' (ok or already recorded), 'refused' (sudemi will never take it: no such order, a
	 * malformed call) or 'retry' (no answer, 5xx, 429, 401/403, 409 site_mismatch, and 409
	 * not_paid: the order may still be paid, for instance when the other shops answer).
	 */
	public static function report_verdict( $response ) {
		if ( ! is_array( $response ) || ! isset( $response['status'] ) ) {
			return 'retry';
		}
		$status = (int) $response['status'];
		if ( 200 === $status || 204 === $status ) {
			return 'done';
		}
		if ( in_array( $status, array( 400, 404, 413, 422 ), true ) ) {
			return 'refused';
		}
		return 'retry';
	}

	/**
	 * Tracking details as the common shipment plugins store them.
	 *
	 * There is no standard field for this in WooCommerce, so the usual meta keys are tried in
	 * turn, then the last parcel of WooCommerce Shipment Tracking and Advanced Shipment Tracking
	 * (_wc_shipment_tracking_items), and a filter is offered for anything else. Returning null is
	 * fine.
	 *
	 * @param WC_Order $order
	 * @param string   $part 'carrier' or 'code'
	 * @return string|null
	 */
	private static function tracking_value( $order, $part ) {
		$keys = 'carrier' === $part ? self::CARRIER_KEYS : self::CODE_KEYS;

		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}
		$value = self::shipment_item_value( $order->get_meta( self::ITEMS_KEY ), $part );
		if ( null !== $value ) {
			return $value;
		}
		/**
		 * Filters the tracking value sudemi reports, for shops whose shipment plugin stores it
		 * somewhere not listed above.
		 *
		 * @param string|null $value
		 * @param WC_Order    $order
		 * @param string      $part 'carrier' or 'code'
		 */
		return apply_filters( 'sudemi_tracking_value', null, $order, $part );
	}

	/** Meta keys read for the carrier, in turn. */
	const CARRIER_KEYS = array( '_tracking_provider', '_wc_shipment_tracking_provider', '_shipping_carrier' );

	/** Meta keys read for the tracking code, in turn. */
	const CODE_KEYS = array( '_tracking_number', '_wc_shipment_tracking_number', '_shipping_tracking_code' );

	/** WooCommerce Shipment Tracking and Advanced Shipment Tracking: a list of parcels. */
	const ITEMS_KEY = '_wc_shipment_tracking_items';

	/** The tracking last sent to sudemi from this shop (carrier, code), on the order. */
	const META_TRACKING_SENT = '_sudemi_tracking_sent';

	/**
	 * Pure: the carrier or code of the last parcel in a _wc_shipment_tracking_items list.
	 *
	 * @param mixed  $items The meta value.
	 * @param string $part  'carrier' or 'code'.
	 * @return string|null
	 */
	public static function shipment_item_value( $items, $part ) {
		if ( ! is_array( $items ) || empty( $items ) ) {
			return null;
		}
		$last = end( $items );
		if ( ! is_array( $last ) ) {
			return null;
		}
		$fields = 'carrier' === $part ? array( 'custom_tracking_provider', 'tracking_provider', 'formatted_tracking_provider' ) : array( 'tracking_number' );
		foreach ( $fields as $field ) {
			if ( isset( $last[ $field ] ) && is_scalar( $last[ $field ] ) && '' !== trim( (string) $last[ $field ] ) ) {
				return trim( (string) $last[ $field ] );
			}
		}
		return null;
	}

	/**
	 * The tracking this shop has for an order, wherever it keeps it.
	 *
	 * @param WC_Order $order
	 * @return array{carrier:string,code:string}
	 */
	public static function local_tracking( $order ) {
		return array(
			'carrier' => Sudemi_Manage::clean_text( (string) self::tracking_value( $order, 'carrier' ), Sudemi_Manage::TRACKING_MAX ),
			'code'    => Sudemi_Manage::clean_text( (string) self::tracking_value( $order, 'code' ), Sudemi_Manage::TRACKING_MAX ),
		);
	}

	/** Keeps the tracking just sent (or found already on sudemi) on the order. */
	public static function remember_tracking( $order, array $tracking ) {
		$before                = self::$saving_tracking;
		self::$saving_tracking = true;
		try {
			$order->update_meta_data(
				self::META_TRACKING_SENT,
				array(
					'carrier' => isset( $tracking['carrier'] ) ? (string) $tracking['carrier'] : '',
					'code'    => isset( $tracking['code'] ) ? (string) $tracking['code'] : '',
				)
			);
			$order->save();
		} finally {
			self::$saving_tracking = $before;
		}
	}

	/**
	 * Pure: whether the shop's tracking for a shipped order is news for sudemi.
	 *
	 * Only a change made here is sent: not an order saved for some other reason while sudemi has
	 * a newer tracking (changed on the portal), and never what came from sudemi itself.
	 *
	 * @param array      $local    carrier, code: what the shop has now.
	 * @param array|null $reported carrier, code: what was last sent from here; null when nothing
	 *                             was recorded (shipped before plugin 1.3.0, or on sudemi).
	 * @param array      $sudemi   carrier, code: what sudemi has, as far as this shop knows.
	 * @return bool
	 */
	public static function tracking_is_news( array $local, $reported, array $sudemi ) {
		$code    = isset( $local['code'] ) ? (string) $local['code'] : '';
		$carrier = isset( $local['carrier'] ) ? (string) $local['carrier'] : '';
		if ( '' === $code ) {
			return false; // sudemi needs a code; a carrier alone changes nothing for the buyer.
		}
		if ( is_array( $reported ) && $code === (string) ( isset( $reported['code'] ) ? $reported['code'] : '' )
			&& $carrier === (string) ( isset( $reported['carrier'] ) ? $reported['carrier'] : '' ) ) {
			return false; // Unchanged here since it was sent.
		}
		$sudemi_code    = isset( $sudemi['code'] ) ? (string) $sudemi['code'] : '';
		$sudemi_carrier = isset( $sudemi['carrier'] ) ? (string) $sudemi['carrier'] : '';
		if ( $code === $sudemi_code && ( '' === $carrier || $carrier === $sudemi_carrier ) ) {
			return false; // sudemi has it already (a blank carrier keeps sudemi's).
		}
		return true;
	}

	/** Set while this plugin saves its own record of the tracking, so that save is not checked. */
	private static $saving_tracking = false;

	/** Orders saved in this request, checked for new tracking at its end. */
	private static $tracking_checks = array();

	/** @param int $order_id A WooCommerce order saved. */
	public static function on_order_saved( $order_id ) {
		if ( self::$applying_sudemi_cancel || self::$saving_tracking ) {
			return; // A change from sudemi, or this plugin's own note of what it sent.
		}
		$order_id = is_object( $order_id ) && method_exists( $order_id, 'get_id' ) ? $order_id->get_id() : (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		if ( empty( self::$tracking_checks ) ) {
			add_action( 'shutdown', array( __CLASS__, 'check_saved_orders' ), 5 );
		}
		self::$tracking_checks[ $order_id ] = true;
	}

	/** Post meta written straight to an order (posts storage): only the tracking keys matter. */
	public static function on_post_meta( $meta_id, $object_id, $meta_key ) {
		if ( in_array( $meta_key, array_merge( self::CARRIER_KEYS, self::CODE_KEYS, array( self::ITEMS_KEY ) ), true ) ) {
			self::on_order_saved( (int) $object_id );
		}
	}

	/** End of the request: every order saved in it is checked once. */
	public static function check_saved_orders() {
		$ids                   = array_keys( self::$tracking_checks );
		self::$tracking_checks = array();
		foreach ( $ids as $order_id ) {
			try {
				self::check_tracking( $order_id );
			} catch ( Exception $e ) {
				Sudemi_Logger::error( 'Could not check the tracking of order ' . $order_id . ': ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Sends tracking added or changed on an order that was already reported shipped (WooCommerce
	 * order Completed). sudemi e-mails the buyer the new code. The answer is noted on the order;
	 * without an answer the call is retried on the next heartbeat.
	 *
	 * @param int $order_id A WooCommerce order id.
	 * @return string|null The verdict (see send_tracking()), or null when nothing was sent.
	 */
	public static function check_tracking( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) || 'shop_order_refund' === $order->get_type() ) {
			return null;
		}
		$sudemi_id = strtolower( (string) $order->get_meta( self::META_ORDER_ID ) );
		if ( '' === $sudemi_id || ! $order->has_status( 'completed' ) ) {
			return null; // Not a sudemi order, or not shipped yet: the dispatch report carries it.
		}
		if ( in_array( (string) $order->get_meta( Sudemi_Changes::META_STATUS ), array( 'DELIVERED', 'CANCELLED', 'REFUNDED', 'DECLINED' ), true ) ) {
			return null; // Delivered or closed on sudemi: tracking can no longer change there.
		}
		$local    = self::local_tracking( $order );
		$reported = $order->get_meta( self::META_TRACKING_SENT );
		$sudemi   = array(
			'carrier' => (string) $order->get_meta( Sudemi_Changes::META_CARRIER ),
			'code'    => (string) $order->get_meta( Sudemi_Changes::META_CODE ),
		);
		if ( ! self::tracking_is_news( $local, is_array( $reported ) ? $reported : null, $sudemi ) ) {
			return null;
		}
		self::remember_tracking( $order, $local );
		$body    = array(
			'carrier'      => '' === $local['carrier'] ? null : $local['carrier'],
			'trackingCode' => $local['code'],
		);
		$verdict = self::send_tracking( $sudemi_id, $body, $order->get_id() );
		$shown   = esc_html( trim( $local['carrier'] . ' ' . $local['code'] ) );
		switch ( $verdict ) {
			case 'done':
				$note = sprintf(
					/* translators: %s: carrier and tracking code. */
					__( 'New tracking sent to sudemi: %s. sudemi e-mails the buyer the new code.', 'sudemi' ),
					$shown
				);
				break;
			case 'same':
				$note = null; // sudemi had it already.
				break;
			case 'retry':
				$note = sprintf(
					/* translators: %s: carrier and tracking code. */
					__( 'Could not send the new tracking (%s) to sudemi yet. It is sent again on the next heartbeat.', 'sudemi' ),
					$shown
				);
				break;
			case 'final':
				$note = sprintf(
					/* translators: %s: carrier and tracking code. */
					__( 'The new tracking (%s) was not sent: on sudemi this order is already delivered, cancelled or refunded, so its tracking can no longer change.', 'sudemi' ),
					$shown
				);
				break;
			default:
				$note = sprintf(
					/* translators: %s: carrier and tracking code. */
					__( 'sudemi did not take the new tracking (%s). Check the order under WooCommerce > sudemi > Orders.', 'sudemi' ),
					$shown
				);
		}
		if ( null !== $note ) {
			$fresh = wc_get_order( $order->get_id() );
			if ( $fresh ) {
				$fresh->add_order_note( $note );
			}
		}
		return $verdict;
	}

	/**
	 * Pure: how sudemi answered a tracking or shipped call carrying tracking.
	 *
	 * @param string $call     'tracking' or 'shipped'.
	 * @param mixed  $response The Sudemi_Client answer.
	 * @return string 'done' (changed), 'same' (sudemi has this tracking already), 'already' (shipped:
	 *                the order is past PAID), 'not_shipped' (tracking: the order is not SHIPPED),
	 *                'refused' or 'retry'.
	 */
	public static function tracking_answer( $call, $response ) {
		if ( ! is_array( $response ) || ! isset( $response['status'] ) ) {
			return 'retry';
		}
		$status = (int) $response['status'];
		$body   = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
		if ( 200 === $status || 204 === $status ) {
			if ( isset( $body['status'] ) && 'already_resolved' === $body['status'] ) {
				return 'shipped' === $call ? 'already' : 'same';
			}
			return 'done';
		}
		if ( 'tracking' === $call && 409 === $status && isset( $body['error'] ) && 'not_shipped' === $body['error'] ) {
			return 'not_shipped';
		}
		return 'refused' === self::report_verdict( $response ) ? 'refused' : 'retry';
	}

	/**
	 * Sends tracking to sudemi whichever state it has the order in: `tracking` while SHIPPED,
	 * `shipped` with the tracking while still PAID (4d). Starts with the call the caller expects
	 * and switches once when sudemi says the order is in the other state, so a race (the order
	 * shipped elsewhere a moment ago, or a dispatch report still waiting in the retry queue) ends
	 * well. At most three calls.
	 *
	 * @param string $sudemi_id The sudemi order id.
	 * @param array  $body      carrier (null keeps sudemi's), trackingCode.
	 * @param string $first     'tracking' or 'shipped'.
	 * @return array{verdict:string,call:string,response:mixed} verdict: 'done', 'same', 'final'
	 *               (delivered, cancelled or refunded on sudemi), 'refused' or 'retry'.
	 */
	public static function push_tracking( $sudemi_id, array $body, $first = 'tracking' ) {
		$code     = isset( $body['trackingCode'] ) ? (string) $body['trackingCode'] : '';
		$call     = 'shipped' === $first ? 'shipped' : 'tracking';
		$tried    = array();
		$response = null;
		for ( $i = 0; $i < 3; $i++ ) {
			$tried[ $call ] = true;
			$response       = Sudemi_Client::post_signed(
				'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/' . $call,
				$body
			);
			$answer         = self::tracking_answer( $call, $response );
			if ( 'not_shipped' === $answer ) {
				if ( empty( $tried['shipped'] ) ) {
					$call = 'shipped'; // Still PAID: the dispatch itself carries the tracking.
					continue;
				}
				return array( 'verdict' => 'final', 'call' => $call, 'response' => $response );
			}
			if ( 'already' === $answer ) {
				if ( '' !== $code && empty( $tried['tracking'] ) ) {
					$call = 'tracking'; // Shipped already: the tracking call changes it.
					continue;
				}
				return array( 'verdict' => '' === $code ? 'same' : 'final', 'call' => $call, 'response' => $response );
			}
			return array( 'verdict' => $answer, 'call' => $call, 'response' => $response );
		}
		return array( 'verdict' => 'retry', 'call' => $call, 'response' => $response );
	}

	/**
	 * New tracking for an order reported shipped, sent now; queued and retried on the next
	 * heartbeat when sudemi does not answer.
	 *
	 * @return string 'done', 'same', 'final', 'refused' or 'retry'.
	 */
	public static function send_tracking( $sudemi_id, array $body, $local_id ) {
		$result  = self::push_tracking( $sudemi_id, $body, 'tracking' );
		$verdict = $result['verdict'];
		if ( 'retry' === $verdict ) {
			Sudemi_Order_Calls::enqueue( $sudemi_id, 'tracking', $body, $local_id );
			Sudemi_Logger::warn(
				sprintf(
					/* translators: 1: sudemi order id. */
					__( 'Could not send the new tracking for sudemi order %1$s yet. It is sent again on the next heartbeat.', 'sudemi' ),
					$sudemi_id
				)
			);
			return $verdict;
		}
		Sudemi_Order_Calls::remove( $sudemi_id, 'tracking' );
		if ( 'shipped' === $result['call'] && 'done' === $verdict ) {
			// The dispatch went with this tracking: a queued shipped report is not needed any more.
			Sudemi_Order_Calls::remove( $sudemi_id, 'shipped' );
		}
		if ( 'done' === $verdict || 'same' === $verdict ) {
			Sudemi_Logger::info(
				sprintf(
					/* translators: 1: sudemi order id. */
					__( 'Sent the new tracking for sudemi order %1$s.', 'sudemi' ),
					$sudemi_id
				)
			);
		} elseif ( 'final' === $verdict ) {
			Sudemi_Logger::warn(
				sprintf(
					/* translators: 1: sudemi order id. */
					__( 'The new tracking for sudemi order %1$s was not sent: the order is already delivered, cancelled or refunded on sudemi.', 'sudemi' ),
					$sudemi_id
				)
			);
		} else {
			$response = $result['response'];
			Sudemi_Logger::error(
				sprintf(
					/* translators: 1: event name, 2: sudemi order id, 3: error message. */
					__( 'Could not report %1$s for sudemi order %2$s: %3$s', 'sudemi' ),
					'tracking',
					$sudemi_id,
					is_array( $response ) ? 'HTTP ' . (int) $response['status'] . ( isset( $response['body']['error'] ) ? ' ' . (string) $response['body']['error'] : '' ) : ''
				)
			);
		}
		return $verdict;
	}
}
