<?php
/**
 * Return requests from sudemi buyers, and this shop's answer to them.
 *
 * After delivery a buyer may ask to send an order back. The shop has seven days to accept, or to
 * reject with a reason that the buyer is shown; with no answer by then, sudemi decides. Accepting
 * moves no money: the merchant refunds once the item is back, through the ordinary WooCommerce
 * refund (which this plugin already passes to sudemi), and that refund closes the request.
 *
 * sudemi cannot call this shop, so the open requests arrive on the heartbeat: the full list on
 * every beat. It is kept here as a copy, replaced each time, and shown where the merchant works
 * with orders.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Requests {

	const OPTION = 'sudemi_open_requests';

	/** Order note already written for a request, so a request is announced on the order once. */
	const META_NOTED = '_sudemi_request_noted';

	const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	/** The longest reason sent to sudemi; it is shown to the buyer, not a place for an essay. */
	const MAX_REASON = 1000;

	/**
	 * Cleans the heartbeat's open_requests block into what is stored: keyed by sudemi order id,
	 * only well-formed entries, strings only.
	 *
	 * @param mixed $list The open_requests block (or null when the beat did not carry one).
	 * @return array
	 */
	public static function normalise( $list ) {
		$out = array();
		if ( ! is_array( $list ) ) {
			return $out;
		}
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['order_id'] ) || ! is_string( $entry['order_id'] ) ) {
				continue;
			}
			$order_id = strtolower( $entry['order_id'] );
			if ( ! preg_match( self::UUID, $order_id ) ) {
				continue;
			}
			$out[ $order_id ] = array(
				'order_id'     => $order_id,
				'request_id'   => isset( $entry['request_id'] ) && is_string( $entry['request_id'] ) ? $entry['request_id'] : '',
				'kind'         => isset( $entry['kind'] ) && is_string( $entry['kind'] ) ? $entry['kind'] : 'RETURN',
				'reason'       => isset( $entry['reason'] ) && is_string( $entry['reason'] ) ? $entry['reason'] : '',
				'requested_at' => isset( $entry['requested_at'] ) && is_string( $entry['requested_at'] ) ? $entry['requested_at'] : '',
				'respond_by'   => isset( $entry['respond_by'] ) && is_string( $entry['respond_by'] ) ? $entry['respond_by'] : '',
			);
		}
		return $out;
	}

	/** The signed path for an answer. */
	public static function path_for( $order_id, $accept ) {
		return '/integration/v1/orders/' . rawurlencode( $order_id ) . '/request/' . ( $accept ? 'accept' : 'reject' );
	}

	/**
	 * Checks an answer before anything is sent.
	 *
	 * @return array{ok:bool,error?:string,body?:array}
	 */
	public static function build_answer( $order_id, $accept, $reason ) {
		if ( ! is_string( $order_id ) || ! preg_match( self::UUID, strtolower( $order_id ) ) ) {
			return array( 'ok' => false, 'error' => 'order' );
		}
		$reason = is_string( $reason ) ? trim( $reason ) : '';
		if ( ! $accept && '' === $reason ) {
			return array( 'ok' => false, 'error' => 'reason' );
		}
		if ( function_exists( 'mb_substr' ) ) {
			$reason = mb_substr( $reason, 0, self::MAX_REASON );
		} else {
			$reason = substr( $reason, 0, self::MAX_REASON );
		}
		return array( 'ok' => true, 'body' => array( 'reason' => '' === $reason ? null : $reason ) );
	}

	/**
	 * What sudemi's answer means: ok, already_resolved, not_found, invalid or error.
	 *
	 * @param array|WP_Error $response
	 */
	public static function outcome_of( $response ) {
		if ( is_wp_error( $response ) || ! is_array( $response ) || ! isset( $response['status'] ) ) {
			return 'error';
		}
		$status = (int) $response['status'];
		$body   = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
		if ( 200 === $status ) {
			return isset( $body['status'] ) && 'already_resolved' === $body['status'] ? 'already_resolved' : 'ok';
		}
		if ( 404 === $status ) {
			return 'not_found';
		}
		if ( 400 === $status ) {
			return 'invalid';
		}
		return 'error';
	}

	// ------------------------------------------------------------------ storage ----

	/** Every open request, soonest answer-by date first. */
	public static function all() {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		uasort(
			$stored,
			function ( $a, $b ) {
				return strcmp( (string) $a['respond_by'], (string) $b['respond_by'] );
			}
		);
		return $stored;
	}

	public static function for_order( $sudemi_id ) {
		$all = self::all();
		$key = strtolower( (string) $sudemi_id );
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Replaces the stored copy with what the heartbeat carried, and notes each new request on its
	 * WooCommerce order, where the merchant looks.
	 *
	 * @param mixed $list The open_requests block, or null when the beat carried none.
	 */
	public static function replace( $list ) {
		$fresh = self::normalise( $list );
		update_option( self::OPTION, $fresh, false );
		foreach ( $fresh as $request ) {
			self::note_once( $request );
		}
	}

	public static function forget( $sudemi_id = null ) {
		if ( null === $sudemi_id ) {
			delete_option( self::OPTION );
			return;
		}
		$all = self::all();
		unset( $all[ strtolower( (string) $sudemi_id ) ] );
		update_option( self::OPTION, $all, false );
	}

	/** The WooCommerce order for a sudemi order, whatever its status. */
	public static function local_order( $sudemi_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => Sudemi_Orders::META_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the one link from a sudemi order to its WooCommerce order; limit 1.
				'meta_value' => (string) $sudemi_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see meta_key above.
			)
		);
		return empty( $orders ) ? null : $orders[0];
	}

	private static function note_once( array $request ) {
		$order = self::local_order( $request['order_id'] );
		if ( ! $order || $order->get_meta( self::META_NOTED ) === $request['request_id'] ) {
			return;
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: the buyer's reason, 2: the date the shop has to answer by. */
				__( 'The buyer asked on sudemi to return this order. Reason: %1$s. Accept or reject (with a reason) by %2$s on this order or under WooCommerce > sudemi.', 'sudemi-connector' ),
				'' === $request['reason'] ? __( 'none given', 'sudemi-connector' ) : esc_html( $request['reason'] ),
				self::date( $request['respond_by'] )
			)
		);
		$order->update_meta_data( self::META_NOTED, $request['request_id'] );
		$order->save();
	}

	/** A sudemi timestamp in this site's date and time format. */
	public static function date( $iso ) {
		$ts = is_string( $iso ) && '' !== $iso ? strtotime( $iso ) : false;
		return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
	}

	// ------------------------------------------------------------------- answer ----

	/**
	 * Sends the shop's answer and records it.
	 *
	 * @return array{outcome:string,message:string}
	 */
	public static function answer( $sudemi_id, $accept, $reason ) {
		$built = self::build_answer( $sudemi_id, $accept, $reason );
		if ( ! $built['ok'] ) {
			return array(
				'outcome' => 'invalid',
				'message' => 'reason' === $built['error']
					? __( 'A rejection needs a reason. The buyer is shown it.', 'sudemi-connector' )
					: __( 'That is not a sudemi order.', 'sudemi-connector' ),
			);
		}
		$sudemi_id = strtolower( $sudemi_id );
		$response  = Sudemi_Client::send_signed( 'POST', self::path_for( $sudemi_id, $accept ), $built['body'] );
		$outcome   = self::outcome_of( $response );

		if ( in_array( $outcome, array( 'ok', 'already_resolved', 'not_found' ), true ) ) {
			// Answered now or before, or no longer open: either way nothing is left to answer here.
			self::forget( $sudemi_id );
		}

		$order = self::local_order( $sudemi_id );
		if ( 'ok' === $outcome && $order ) {
			$order->add_order_note(
				$accept
					? __( 'Return request accepted on sudemi. Refund the buyer here (WooCommerce refund) once the item is back; the refund goes through sudemi and closes the request.', 'sudemi-connector' )
					: sprintf(
						/* translators: %s: the reason the shop gave, shown to the buyer. */
						__( 'Return request rejected on sudemi. Reason shown to the buyer: %s', 'sudemi-connector' ),
						esc_html( (string) $built['body']['reason'] )
					)
			);
		}

		switch ( $outcome ) {
			case 'ok':
				$message = $accept
					? __( 'Return accepted. Refund the buyer once the item is back, with a WooCommerce refund on the order.', 'sudemi-connector' )
					: __( 'Return rejected. The buyer is shown your reason.', 'sudemi-connector' );
				break;
			case 'already_resolved':
				$message = __( 'This request was already answered.', 'sudemi-connector' );
				break;
			case 'not_found':
				$message = __( 'This order has no open return request on sudemi any more.', 'sudemi-connector' );
				break;
			case 'invalid':
				$message = __( 'A rejection needs a reason. The buyer is shown it.', 'sudemi-connector' );
				break;
			default:
				$message = is_wp_error( $response )
					? sprintf(
						/* translators: %s: error message. */
						__( 'Could not reach sudemi: %s. Nothing was sent; try again.', 'sudemi-connector' ),
						$response->get_error_message()
					)
					: sprintf(
						/* translators: %d: HTTP status code. */
						__( 'sudemi answered %d.', 'sudemi-connector' ),
						(int) $response['status']
					);
		}
		Sudemi_Logger::info( 'Return request for sudemi order ' . $sudemi_id . ': ' . ( $accept ? 'accept' : 'reject' ) . ' -> ' . $outcome );
		return array( 'outcome' => $outcome, 'message' => $message );
	}
}
