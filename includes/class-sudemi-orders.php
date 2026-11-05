<?php
/**
 * Answering sudemi's question: can this shop actually fulfil the order?
 *
 * Nobody but this shop can answer it. sudemi holds no stock and never did — it carries a figure
 * this plugin pushed, which was true when it was pushed and may not be now, because the same
 * units are on sale in this storefront at the same time. So sudemi does not decide. It authorises
 * the buyer's card, holds the money without taking it, and asks.
 *
 * Until this plugin answers, nothing has been charged. Answering yes captures the money and
 * splits it; answering no releases the hold; saying nothing releases it too, once the window
 * closes. Silence is never taken as agreement — a shop that has stopped answering is usually a
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
					__( '%d order(s) are waiting for confirmation. Automatic confirmation is off, so they must be answered by hand before their window closes.', 'sudemi' ),
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
	 * behind it — which is recoverable here, and is: it is cancelled and the stock put back.
	 *
	 * @param array $pending One entry of the pending_orders block.
	 */
	private static function answer( array $pending ) {
		$sudemi_id = (string) $pending['order_id'];

		$existing = self::find_local_order( $sudemi_id );
		if ( $existing ) {
			// Already built, so the previous run got this far and the answer was lost in transit.
			// Re-sending is safe: sudemi answers "already_resolved" if it landed the first time.
			self::accept( $sudemi_id, $existing );
			return;
		}

		$shortfall = self::first_shortfall( $pending );
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
	 * Sends the acceptance, and undoes the local order if it is refused.
	 *
	 * @param string   $sudemi_id The sudemi order id.
	 * @param WC_Order $order     The local order already created for it.
	 */
	private static function accept( $sudemi_id, $order ) {
		$response = Sudemi_Client::post_signed(
			'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/accept',
			array()
		);

		if ( is_wp_error( $response ) ) {
			// Unreachable rather than refused. The local order stays as it is and the next
			// heartbeat will carry the same pending order again, at which point the branch above
			// finds this order and simply re-sends. Nothing is lost and nothing is duplicated.
			Sudemi_Logger::warn(
				sprintf(
					/* translators: 1: sudemi order id, 2: error message. */
					__( 'Could not reach sudemi to confirm order %1$s (%2$s). Will try again on the next heartbeat.', 'sudemi' ),
					$sudemi_id,
					$response->get_error_message()
				)
			);
			return;
		}

		$status = isset( $response['status'] ) ? (int) $response['status'] : 0;
		$body   = isset( $response['body'] ) ? $response['body'] : array();

		if ( 200 === $status && isset( $body['status'] ) && 'ok' === $body['status']
			&& isset( $body['payment'] ) && 'waiting_for_other_shops' === $body['payment'] ) {
			// Accepted, but not paid. The buyer paid several shops in one payment, and a card
			// authorisation can be captured only once — so sudemi takes the money when the last
			// of them has answered, and says so in settled_orders on a later heartbeat. Until
			// then the order stays held and has no address: shipping it now could mean shipping
			// goods nobody ever pays for.
			self::hold_for_payment( $sudemi_id, $order );
			return;
		}

		if ( 200 === $status && isset( $body['status'] ) && 'ok' === $body['status'] ) {
			// The address arrives only now. sudemi withholds it while the order is still being
			// decided, because answering "do I have stock" needs nobody's address — so this is
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

		// Refused: the window closed, or the buyer's authorisation lapsed before this shop got
		// round to answering. No money was taken, so the local order must not survive — and the
		// stock it reserved has to go back on the shelf or this shop slowly loses inventory to
		// orders that never happened.
		$reason = isset( $body['message'] ) ? (string) $body['message'] : sprintf( 'HTTP %d', $status );
		wc_maybe_increase_stock_levels( $order->get_id() ); // guarded: once per order, whatever copy is in memory
		$order->update_status(
			'cancelled',
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
	 * Keeps an accepted order on hold while the rest of the buyer's payment is still being decided.
	 *
	 * Said on the order itself, because that is where the merchant deciding what to pack looks.
	 *
	 * @param string   $sudemi_id The sudemi order id.
	 * @param WC_Order $order     The local order.
	 */
	private static function hold_for_payment( $sudemi_id, $order ) {
		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold' );
		}
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

		// Only finds an order still on hold. One already processing, completed or cancelled has
		// been settled before — by an earlier beat, or by an accept that captured straight away —
		// and is left exactly as it is.
		$order = self::find_local_order( $sudemi_id );
		if ( ! $order ) {
			return;
		}

		if ( 'DECLINED' === $state ) {
			// Released without being charged: the capture failed, or the authorisation lapsed
			// before the last shop answered. Same as a refused acceptance — the local order must
			// not survive, and the stock it reserved goes back on the shelf.
			$reason = ! empty( $settled['declined_reason'] )
				? (string) $settled['declined_reason']
				: __( 'reason not given', 'sudemi' );
			wc_maybe_increase_stock_levels( $order->get_id() ); // guarded: once per order, whatever copy is in memory
			$order->update_status(
				'cancelled',
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
	private static function fill_customer( $order, $sudemi_id ) {
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
	private static function first_shortfall( array $pending ) {
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
	private static function create_local_order( array $pending, $sudemi_id ) {
		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			return null;
		}

		foreach ( $pending['lines'] as $line ) {
			$product = self::resolve_product( $line );
			if ( ! $product ) {
				$order->delete( true );
				return null;
			}
			$order->add_product( $product, isset( $line['quantity'] ) ? (int) $line['quantity'] : 1 );
		}

		$order->update_meta_data( self::META_ORDER_ID, $sudemi_id );
		$order->set_created_via( 'sudemi' );
		if ( ! empty( $pending['destination_country'] ) ) {
			$order->set_shipping_country( (string) $pending['destination_country'] );
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
		$order->calculate_totals();
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
	 * The WooCommerce product behind a line.
	 *
	 * external_id is this shop's own product id — it is what the sync pushes as the identifier,
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
	}

	/**
	 * A refund made in the WooCommerce admin (full or partial) is passed to sudemi, which pays it
	 * back to the buyer from the payment it holds and takes it from this shop's payout.
	 *
	 * @param int   $refund_id The WooCommerce refund.
	 * @param array $args      Its arguments (order_id, amount, reason...).
	 */
	public static function on_refund_created( $refund_id, $args ) {
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
		$amount   = (int) round( (float) $refund->get_amount() * 100 );
		$reason   = (string) $refund->get_reason();
		$response = Sudemi_Client::post_signed(
			'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/refund',
			array(
				'amountMinor' => $amount,
				'reason'      => '' === $reason ? null : $reason,
			)
		);
		self::note_money_result( $order, $response, wc_price( $amount / 100 ) );
	}

	/**
	 * Cancelling an order the buyer has already paid for refunds it in full on sudemi. Orders
	 * this plugin cancels itself (a released payment) never were paid, so they are left alone.
	 */
	public static function on_status_changed( $order_id, $from, $to ) {
		if ( 'cancelled' !== $to || ! in_array( $from, array( 'processing', 'completed' ), true ) ) {
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
		$response = Sudemi_Client::post_signed(
			'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/cancel',
			array( 'reason' => 'Cancelled by the shop' )
		);
		self::note_money_result( $order, $response, __( 'the full amount', 'sudemi' ) );
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
		}

		$response = Sudemi_Client::post_signed(
			'/integration/v1/orders/' . rawurlencode( $sudemi_id ) . '/' . $event,
			$body
		);
		if ( is_wp_error( $response ) ) {
			Sudemi_Logger::warn(
				sprintf(
					/* translators: 1: event name, 2: sudemi order id, 3: error message. */
					__( 'Could not report %1$s for sudemi order %2$s: %3$s', 'sudemi' ),
					$event,
					$sudemi_id,
					$response->get_error_message()
				)
			);
			return;
		}
		Sudemi_Logger::info(
			sprintf(
				/* translators: 1: event name, 2: sudemi order id. */
				__( 'Reported %1$s for sudemi order %2$s.', 'sudemi' ),
				$event,
				$sudemi_id
			)
		);
	}

	/**
	 * Tracking details as the common shipment plugins store them.
	 *
	 * There is no standard field for this in WooCommerce, so the usual meta keys are tried in
	 * turn and a filter is offered for anything else. Returning null is fine.
	 *
	 * @param WC_Order $order
	 * @param string   $part 'carrier' or 'code'
	 * @return string|null
	 */
	private static function tracking_value( $order, $part ) {
		$keys = 'carrier' === $part
			? array( '_tracking_provider', '_wc_shipment_tracking_provider', '_shipping_carrier' )
			: array( '_tracking_number', '_wc_shipment_tracking_number', '_shipping_tracking_code' );

		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key );
			if ( ! empty( $value ) ) {
				return (string) $value;
			}
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
}
