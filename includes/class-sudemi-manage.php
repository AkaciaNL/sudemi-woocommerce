<?php
/**
 * Managing this shop's sudemi orders, shipping and payouts from WooCommerce (protocol 4d).
 *
 * The sudemi portal is the source of truth: everything here is read from sudemi, every action is
 * sent to sudemi, and only sudemi's answer is then applied to the WooCommerce order (through
 * Sudemi_Changes, the same code the heartbeat's change feed uses). Nothing is decided here that
 * sudemi did not confirm.
 *
 * The pure helpers (filters, query strings, request bodies, error texts) take no WordPress state
 * beyond translation, so tests/manage-test.php runs them without WordPress.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Manage {

	const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	const STATUSES = array( 'AWAITING_SELLER', 'ACCEPTED', 'PAID', 'DECLINED', 'SHIPPED', 'DELIVERED', 'CANCELLED', 'REFUNDED' );

	const SORTS = array( 'placed', 'total', 'status' );

	/** What a single order can be asked to do here (allowed_actions names). */
	const ACTIONS = array( 'accept', 'reject', 'shipped', 'update_tracking', 'delivered', 'refund', 'cancel' );

	/** What the bulk call takes. Refunds and cancellations are never offered in bulk (4d). */
	const BULK_ACTIONS = array( 'accept', 'reject', 'shipped', 'delivered' );

	/** Orders per page in the order table. */
	const PAGE_SIZE = 25;

	/** At most this many orders per bulk call (4d). */
	const BULK_MAX = 100;

	/** Longest carrier name and tracking code sudemi takes. */
	const TRACKING_MAX = 100;

	/** Longest reason sent (decline, refund, cancel). */
	const REASON_MAX = 500;

	// ------------------------------------------------------------------ pure helpers ----

	public static function is_order_id( $id ) {
		return is_string( $id ) && 1 === preg_match( self::UUID, strtolower( $id ) );
	}

	/**
	 * The order table's filters from a request, cleaned: anything unknown is dropped, never sent.
	 *
	 * @param array $in Usually $_GET (already unslashed).
	 * @return array{status:string,from:string,to:string,q:string,sort:string,dir:string,offset:int}
	 */
	public static function filters( array $in ) {
		$status = isset( $in['status'] ) && is_string( $in['status'] ) ? strtoupper( trim( $in['status'] ) ) : '';
		$sort   = isset( $in['sort'] ) && is_string( $in['sort'] ) ? strtolower( trim( $in['sort'] ) ) : '';
		$dir    = isset( $in['dir'] ) && is_string( $in['dir'] ) ? strtolower( trim( $in['dir'] ) ) : '';
		$q      = isset( $in['q'] ) && is_string( $in['q'] ) ? trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $in['q'] ) ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			$q = mb_substr( $q, 0, 100 );
		} else {
			$q = substr( $q, 0, 100 );
		}
		return array(
			'status' => in_array( $status, self::STATUSES, true ) ? $status : '',
			'from'   => self::date_or_empty( isset( $in['from'] ) ? $in['from'] : '' ),
			'to'     => self::date_or_empty( isset( $in['to'] ) ? $in['to'] : '' ),
			'q'      => $q,
			'sort'   => in_array( $sort, self::SORTS, true ) ? $sort : 'placed',
			'dir'    => 'asc' === $dir ? 'asc' : 'desc',
			'offset' => isset( $in['offset'] ) && is_numeric( $in['offset'] ) ? max( 0, (int) $in['offset'] ) : 0,
		);
	}

	/** A YYYY-MM-DD date that exists, or ''. */
	public static function date_or_empty( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $value ), $m ) ) {
			return '';
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? trim( $value ) : '';
	}

	/**
	 * The signed path of the order table call. Always carries offset, so sudemi answers with the
	 * portal's table (total, next_offset) and not the 1.2 history.
	 */
	public static function list_path( array $f, $limit = self::PAGE_SIZE ) {
		$query = array(
			'limit'  => max( 1, min( 100, (int) $limit ) ),
			'offset' => (int) $f['offset'],
			'sort'   => $f['sort'],
			'dir'    => $f['dir'],
		);
		foreach ( array( 'status', 'from', 'to', 'q' ) as $key ) {
			if ( '' !== $f[ $key ] ) {
				$query[ $key ] = $f[ $key ];
			}
		}
		return '/integration/v1/orders?' . self::query( $query );
	}

	/** The signed path of the CSV export: the same filters, every page. */
	public static function export_path( array $f, $lang, $decimal_comma ) {
		$query = array(
			'sort' => $f['sort'],
			'dir'  => $f['dir'],
		);
		foreach ( array( 'status', 'from', 'to', 'q' ) as $key ) {
			if ( '' !== $f[ $key ] ) {
				$query[ $key ] = $f[ $key ];
			}
		}
		$query['lang'] = in_array( $lang, Sudemi_Support::LOCALES, true ) ? $lang : 'en';
		if ( $decimal_comma ) {
			$query['decimal'] = 'comma';
		}
		return '/integration/v1/orders/export.csv?' . self::query( $query );
	}

	/** RFC 3986 encoding, so the path that is signed is exactly the one sent. */
	private static function query( array $query ) {
		return http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * A sudemi order page (GET /orders/{id}) in the shape of a pending_orders entry, so a local
	 * order is built from it by exactly the code that builds one from the heartbeat.
	 */
	public static function pending_from_detail( array $d ) {
		$money = isset( $d['money'] ) && is_array( $d['money'] ) ? $d['money'] : array();
		$lines = array();
		foreach ( isset( $d['lines'] ) && is_array( $d['lines'] ) ? $d['lines'] : array() as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$lines[] = array(
				'product_id'       => isset( $line['product_id'] ) ? (string) $line['product_id'] : '',
				'external_id'      => isset( $line['external_id'] ) ? (string) $line['external_id'] : '',
				'title'            => isset( $line['title'] ) ? (string) $line['title'] : '',
				'quantity'         => isset( $line['quantity'] ) ? (int) $line['quantity'] : 1,
				'unit_price_minor' => isset( $line['unit_price_minor'] ) ? $line['unit_price_minor'] : null,
			);
		}
		$pending = array(
			'order_id'            => isset( $d['order_id'] ) ? (string) $d['order_id'] : ( isset( $d['id'] ) ? (string) $d['id'] : '' ),
			'currency_code'       => isset( $money['currency_code'] ) ? (string) $money['currency_code'] : '',
			'destination_country' => isset( $d['destination_country'] ) ? (string) $d['destination_country'] : '',
			'lines'               => $lines,
		);
		if ( isset( $money['total_minor'] ) ) {
			$pending['total_minor'] = $money['total_minor'];
		}
		if ( isset( $money['shipping_minor'] ) ) {
			$pending['shipping_minor'] = $money['shipping_minor'];
		}
		return $pending;
	}

	/** A sudemi order page as a change-feed entry (the fields Sudemi_Changes applies). */
	public static function entry_from_detail( array $d ) {
		$money = isset( $d['money'] ) && is_array( $d['money'] ) ? $d['money'] : array();
		return array(
			'order_id'       => isset( $d['order_id'] ) ? (string) $d['order_id'] : ( isset( $d['id'] ) ? (string) $d['id'] : '' ),
			'status'         => isset( $d['status'] ) ? (string) $d['status'] : '',
			'revision'       => isset( $d['revision'] ) ? $d['revision'] : null,
			'refunded_minor' => isset( $money['refunded_minor'] ) ? $money['refunded_minor'] : ( isset( $d['refunded_minor'] ) ? $d['refunded_minor'] : 0 ),
			'tracking'       => isset( $d['tracking'] ) && is_array( $d['tracking'] ) ? $d['tracking'] : null,
			'shipped_at'     => isset( $d['shipped_at'] ) ? $d['shipped_at'] : null,
			'delivered_at'   => isset( $d['delivered_at'] ) ? $d['delivered_at'] : null,
		);
	}

	/** Text as sudemi takes it: one line, no control characters, at most $max characters. */
	public static function clean_text( $value, $max ) {
		$value = is_string( $value ) ? trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	/**
	 * The body of POST /orders/bulk, or the reason it cannot be sent (an error code).
	 *
	 * @param string   $action   accept, reject, shipped or delivered.
	 * @param string[] $ids      sudemi order ids.
	 * @param string   $reason   reject: shown to the buyers, required.
	 * @param string   $carrier  shipped: one carrier for all (optional).
	 * @param array    $tracking shipped: order id => tracking code (optional each).
	 * @return array|string
	 */
	public static function bulk_body( $action, array $ids, $reason = '', $carrier = '', array $tracking = array() ) {
		if ( ! in_array( $action, self::BULK_ACTIONS, true ) ) {
			return 'invalid_action';
		}
		$items = array();
		foreach ( $ids as $id ) {
			$id = strtolower( (string) $id );
			if ( ! self::is_order_id( $id ) || isset( $items[ $id ] ) ) {
				continue;
			}
			$item = array( 'orderId' => $id );
			if ( 'shipped' === $action ) {
				$c    = self::clean_text( $carrier, self::TRACKING_MAX );
				$code = self::clean_text( isset( $tracking[ $id ] ) ? $tracking[ $id ] : '', self::TRACKING_MAX );
				if ( '' !== $c ) {
					$item['carrier'] = $c;
				}
				if ( '' !== $code ) {
					$item['trackingCode'] = $code;
				}
			}
			$items[ $id ] = $item;
		}
		if ( empty( $items ) ) {
			return 'no_orders';
		}
		if ( count( $items ) > self::BULK_MAX ) {
			return 'too_many';
		}
		$body = array(
			'action' => $action,
			'items'  => array_values( $items ),
		);
		if ( 'reject' === $action ) {
			$reason = self::clean_text( $reason, self::REASON_MAX );
			if ( '' === $reason ) {
				return 'reason_required';
			}
			$body['reason'] = $reason;
		}
		return $body;
	}

	/**
	 * The body of POST /shipping from the shipping form, or an error code.
	 *
	 * @param string $mode     shop or custom.
	 * @param string $currency The shop's currency.
	 * @param array  $rows     Country => array( 'enabled' => bool, 'price' => string in major units, '' for the shop's own ).
	 * @param array  $known    Countries sudemi listed (GET /shipping); anything else is dropped.
	 */
	public static function shipping_body( $mode, $currency, array $rows, array $known ) {
		if ( 'shop' === $mode ) {
			return array( 'mode' => 'shop' );
		}
		if ( 'custom' !== $mode ) {
			return 'invalid_mode';
		}
		$countries = array();
		foreach ( $rows as $country => $row ) {
			$country = strtoupper( (string) $country );
			if ( ! in_array( $country, $known, true ) || ! is_array( $row ) ) {
				continue;
			}
			$entry = array(
				'country' => $country,
				'enabled' => ! empty( $row['enabled'] ),
			);
			$price = isset( $row['price'] ) ? trim( str_replace( ',', '.', (string) $row['price'] ) ) : '';
			if ( '' !== $price ) {
				if ( ! is_numeric( $price ) || (float) $price < 0 ) {
					return 'invalid_price';
				}
				$entry['priceMinor'] = Sudemi_Currency::to_minor( $price, $currency );
			}
			$countries[] = $entry;
		}
		return array(
			'mode'      => 'custom',
			'currency'  => $currency,
			'countries' => $countries,
		);
	}

	/**
	 * A merchant-readable sentence for an error code sudemi (or this plugin) gave.
	 *
	 * @param string $code    The error code.
	 * @param string $message sudemi's own message, shown when the code is not known here.
	 */
	public static function error_message( $code, $message = '' ) {
		switch ( (string) $code ) {
			case 'no_such_order':
			case 'not_found':
				return __( 'sudemi has no such order for this shop.', 'sudemi' );
			case 'previous_connection':
				return __( 'This order belongs to an earlier sudemi connection of this shop. Leave it as it is; sudemi support can help.', 'sudemi' );
			case 'already_resolved':
				return __( 'This order was already answered.', 'sudemi' );
			case 'authorisation_lapsed':
				return __( 'The buyer\'s payment authorisation ran out, so the order can no longer be accepted.', 'sudemi' );
			case 'wrong_state':
			case 'not_paid':
			case 'refused':
				return __( 'The order is not in a state that allows this (it may have changed in the meantime). Reload the order.', 'sudemi' );
			case 'provider_refused':
				return __( 'The payment provider refused this. Nothing changed; try again later or contact sudemi support.', 'sudemi' );
			case 'currency_mismatch':
				return __( 'The amount is not in the currency the buyer paid in.', 'sudemi' );
			case 'seller_suspended':
				return __( 'This shop is suspended on sudemi: it cannot accept orders. Paid orders can still be shipped and refunded. Contact sudemi support.', 'sudemi' );
			case 'invalid_date':
				return __( 'Dates must be in the form YYYY-MM-DD.', 'sudemi' );
			case 'too_many_requests':
				return __( 'sudemi asked this shop to slow down. Wait a minute and try again.', 'sudemi' );
			case 'reason_required':
				return __( 'Give a reason. The buyer is shown it.', 'sudemi' );
			case 'no_orders':
				return __( 'Select at least one order.', 'sudemi' );
			case 'too_many':
				return __( 'Select at most 100 orders at once.', 'sudemi' );
			case 'invalid_action':
				return __( 'That action is not available.', 'sudemi' );
			case 'invalid_price':
				return __( 'Prices must be numbers of 0 or more.', 'sudemi' );
			case 'invalid_amount':
				return __( 'Enter an amount greater than 0, or leave it empty to refund the rest.', 'sudemi' );
			case 'unreachable':
				return __( 'Could not reach sudemi. Nothing was changed; try again shortly.', 'sudemi' );
			case 'not_connected':
				return __( 'This shop is not connected to sudemi.', 'sudemi' );
			case 'not_shipped':
				return __( 'The tracking can no longer be changed: on sudemi this order is already delivered, cancelled or refunded.', 'sudemi' );
			case 'tracking_code_required':
				return __( 'Enter a tracking code.', 'sudemi' );
		}
		if ( '' !== (string) $message ) {
			return (string) $message;
		}
		return __( 'sudemi refused this.', 'sudemi' );
	}

	// ----------------------------------------------------------------- reading ----

	/**
	 * One signed call, read into ok/body/error.
	 *
	 * @return array{ok:bool,status:int,body:array,error:string,message:string}
	 */
	private static function call( $method, $path, array $body = array(), array $headers = array() ) {
		if ( ! Sudemi_Client::is_connected() ) {
			return array( 'ok' => false, 'status' => 0, 'body' => array(), 'error' => 'not_connected', 'message' => '' );
		}
		$response = Sudemi_Client::send_signed( $method, $path, $body, $headers );
		return self::read( $response );
	}

	/** A Sudemi_Client answer as ok/body/error. Public for the tests. */
	public static function read( $response ) {
		if ( is_wp_error( $response ) || ! is_array( $response ) || ! isset( $response['status'] ) ) {
			return array( 'ok' => false, 'status' => 0, 'body' => array(), 'error' => 'unreachable', 'message' => '' );
		}
		$status = (int) $response['status'];
		$body   = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
		if ( $status >= 200 && $status < 300 ) {
			return array( 'ok' => true, 'status' => $status, 'body' => $body, 'error' => '', 'message' => '' );
		}
		$error = isset( $body['error'] ) && is_string( $body['error'] ) ? $body['error'] : '';
		if ( 429 === $status ) {
			$error = 'too_many_requests';
		} elseif ( '' === $error || $status >= 500 && 'provider_refused' !== $error ) {
			$error = $status >= 500 ? 'unreachable' : ( '' === $error ? 'refused' : $error );
		}
		return array(
			'ok'      => false,
			'status'  => $status,
			'body'    => $body,
			'error'   => $error,
			'message' => isset( $body['message'] ) && is_string( $body['message'] ) ? $body['message'] : '',
		);
	}

	public static function list_orders( array $f ) {
		return self::call( 'GET', self::list_path( $f ) );
	}

	public static function order( $id ) {
		if ( ! self::is_order_id( $id ) ) {
			return array( 'ok' => false, 'status' => 0, 'body' => array(), 'error' => 'no_such_order', 'message' => '' );
		}
		return self::call( 'GET', '/integration/v1/orders/' . rawurlencode( strtolower( $id ) ) );
	}

	public static function shipping() {
		return self::call( 'GET', '/integration/v1/shipping' );
	}

	public static function save_shipping( array $body ) {
		return self::call( 'POST', '/integration/v1/shipping', $body );
	}

	public static function payouts() {
		return self::call( 'GET', '/integration/v1/payouts' );
	}

	/**
	 * The CSV export, as sudemi sent it.
	 *
	 * @return array{ok:bool,csv?:string,filename?:string,truncated?:bool,total?:int,error?:string,message?:string}
	 */
	public static function export( array $f, $lang, $decimal_comma ) {
		if ( ! Sudemi_Client::is_connected() ) {
			return array( 'ok' => false, 'error' => 'not_connected', 'message' => '' );
		}
		$response = Sudemi_Client::get_signed_raw( self::export_path( $f, $lang, $decimal_comma ) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'error' => 'unreachable', 'message' => '' );
		}
		if ( 200 !== (int) $response['status'] || false === stripos( $response['headers']['content-type'], 'text/csv' ) ) {
			$read = self::read( $response );
			return array( 'ok' => false, 'error' => $read['ok'] ? 'unreachable' : $read['error'], 'message' => $read['message'] );
		}
		$filename = 'sudemi-orders.csv';
		if ( preg_match( '/filename="?([A-Za-z0-9._-]+\.csv)"?/', $response['headers']['content-disposition'], $m ) ) {
			$filename = $m[1];
		}
		return array(
			'ok'        => true,
			'csv'       => $response['raw'],
			'filename'  => $filename,
			'truncated' => 'true' === strtolower( $response['headers']['x-export-truncated'] ),
			'total'     => (int) $response['headers']['x-total-count'],
		);
	}

	// ----------------------------------------------------------------- actions ----

	/**
	 * One action on one order, then sudemi's view of the order applied to the WooCommerce order.
	 *
	 * @param string $id     The sudemi order id.
	 * @param string $action One of ACTIONS.
	 * @param array  $data   reason, carrier, tracking, amount (major units), currency, key (Idempotency-Key).
	 * @return array{ok:bool,message:string}
	 */
	public static function act( $id, $action, array $data = array() ) {
		$id = strtolower( (string) $id );
		if ( ! self::is_order_id( $id ) ) {
			return self::outcome( false, self::error_message( 'no_such_order' ) );
		}
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return self::outcome( false, self::error_message( 'invalid_action' ) );
		}
		if ( 'accept' === $action ) {
			return self::accept( $id );
		}
		if ( 'update_tracking' === $action || ( 'shipped' === $action && '' !== self::clean_text( isset( $data['tracking'] ) ? $data['tracking'] : '', self::TRACKING_MAX ) ) ) {
			return self::act_tracking( $id, $action, $data );
		}
		$reason  = self::clean_text( isset( $data['reason'] ) ? $data['reason'] : '', self::REASON_MAX );
		$path    = '/integration/v1/orders/' . rawurlencode( $id ) . '/' . $action;
		$headers = array();
		$body    = array();
		switch ( $action ) {
			case 'reject':
				if ( '' === $reason ) {
					return self::outcome( false, self::error_message( 'reason_required' ) );
				}
				$body = array( 'reason' => $reason );
				break;
			case 'shipped':
				$carrier = self::clean_text( isset( $data['carrier'] ) ? $data['carrier'] : '', self::TRACKING_MAX );
				$code    = self::clean_text( isset( $data['tracking'] ) ? $data['tracking'] : '', self::TRACKING_MAX );
				$body    = array(
					'carrier'      => '' === $carrier ? null : $carrier,
					'trackingCode' => '' === $code ? null : $code,
				);
				break;
			case 'refund':
				$currency = Sudemi_Currency::normalise( isset( $data['currency'] ) ? $data['currency'] : '' );
				$amount   = isset( $data['amount'] ) ? trim( str_replace( ',', '.', (string) $data['amount'] ) ) : '';
				$body     = array( 'reason' => '' === $reason ? null : $reason );
				if ( '' !== $amount ) {
					if ( ! is_numeric( $amount ) || (float) $amount <= 0 || '' === $currency ) {
						return self::outcome( false, self::error_message( 'invalid_amount' ) );
					}
					$body['amountMinor'] = Sudemi_Currency::to_minor( $amount, $currency );
					$body['currency']    = $currency;
				}
				$headers['Idempotency-Key'] = self::key( $data );
				break;
			case 'cancel':
				$body                       = array( 'reason' => '' === $reason ? 'Cancelled by the shop' : $reason );
				$headers['Idempotency-Key'] = self::key( $data );
				break;
		}
		$read = self::call( 'POST', $path, $body, $headers );
		if ( 'reject' === $action && $read['ok'] && isset( $read['body']['status'] ) && 'already_resolved' === $read['body']['status'] ) {
			self::reflect( $id );
			return self::outcome( false, self::error_message( 'already_resolved' ) );
		}
		if ( ! $read['ok'] ) {
			if ( in_array( $read['error'], array( 'wrong_state', 'not_paid', 'refused', 'already_resolved' ), true ) ) {
				self::reflect( $id );
			}
			return self::outcome( false, self::error_message( $read['error'], $read['message'] ) );
		}
		// Cancelled from here: by this shop, whatever the order page of sudemi says (it has no
		// cancelled_by), so the WooCommerce order note says so.
		self::reflect( $id, 'cancel' === $action ? array( 'cancelled_by' => 'shop' ) : array() );
		Sudemi_Logger::info( 'sudemi order ' . $id . ': ' . $action . ' sent from the sudemi page.' );
		switch ( $action ) {
			case 'reject':
				return self::outcome( true, __( 'Declined on sudemi. The buyer is not charged.', 'sudemi' ) );
			case 'shipped':
				return self::outcome( true, __( 'Marked shipped on sudemi. The buyer is told.', 'sudemi' ) );
			case 'delivered':
				return self::outcome( true, __( 'Marked delivered on sudemi.', 'sudemi' ) );
			case 'refund':
				return self::outcome( true, __( 'Refunded through sudemi. The refund is recorded on the WooCommerce order.', 'sudemi' ) );
			default:
				return self::outcome( true, __( 'Cancelled on sudemi and refunded in full. The WooCommerce order is cancelled and the stock restored.', 'sudemi' ) );
		}
	}

	/**
	 * Shipped with a tracking code, or a new tracking code for a shipped order. Whichever the
	 * merchant picked, it lands: sudemi gets `shipped` while the order is PAID and `tracking`
	 * once it is SHIPPED (it may have been shipped elsewhere a moment ago).
	 *
	 * @return array{ok:bool,message:string}
	 */
	private static function act_tracking( $id, $action, array $data ) {
		$carrier = self::clean_text( isset( $data['carrier'] ) ? $data['carrier'] : '', self::TRACKING_MAX );
		$code    = self::clean_text( isset( $data['tracking'] ) ? $data['tracking'] : '', self::TRACKING_MAX );
		if ( 'update_tracking' === $action && '' === $code ) {
			return self::outcome( false, self::error_message( 'tracking_code_required' ) );
		}
		$result = Sudemi_Orders::push_tracking(
			$id,
			array(
				'carrier'      => '' === $carrier ? null : $carrier,
				'trackingCode' => '' === $code ? null : $code,
			),
			'shipped' === $action ? 'shipped' : 'tracking'
		);
		switch ( $result['verdict'] ) {
			case 'done':
				Sudemi_Order_Calls::remove( $id, 'tracking' );
				if ( 'shipped' === $result['call'] ) {
					Sudemi_Order_Calls::remove( $id, 'shipped' );
				}
				self::reflect( $id );
				Sudemi_Logger::info( 'sudemi order ' . $id . ': ' . $result['call'] . ' sent from the sudemi page.' );
				return 'shipped' === $result['call']
					? self::outcome( true, __( 'Marked shipped on sudemi. The buyer is told.', 'sudemi' ) )
					: self::outcome( true, __( 'Tracking changed on sudemi. sudemi e-mails the buyer the new code.', 'sudemi' ) );
			case 'same':
				self::reflect( $id );
				return self::outcome( true, __( 'sudemi already has this tracking. Nothing was changed.', 'sudemi' ) );
			case 'final':
				self::reflect( $id );
				return self::outcome( false, self::error_message( 'not_shipped' ) );
		}
		$read = self::read( $result['response'] );
		if ( in_array( $read['error'], array( 'wrong_state', 'not_paid', 'refused', 'already_resolved' ), true ) ) {
			self::reflect( $id );
		}
		return self::outcome( false, self::error_message( $read['ok'] ? 'refused' : $read['error'], $read['message'] ) );
	}

	/** An Idempotency-Key from the form (one per rendered form), or a fresh one. */
	private static function key( array $data ) {
		$key = isset( $data['key'] ) && is_string( $data['key'] ) ? $data['key'] : '';
		return preg_match( '/^[A-Za-z0-9-]{16,64}$/', $key ) ? 'wc-' . $key : 'wc-' . wp_generate_uuid4();
	}

	private static function outcome( $ok, $message ) {
		return array(
			'ok'      => (bool) $ok,
			'message' => (string) $message,
		);
	}

	/**
	 * Accepts a waiting order by hand (automatic confirmation off, or the merchant chose to).
	 *
	 * Like the automatic answer, the WooCommerce order is created first (stock reserved) and only
	 * then is sudemi told yes, so a buyer is never charged for an order this shop has no record
	 * of. Stock is not checked: the merchant decided. A currency other than the shop's is refused
	 * here, and the merchant can decline.
	 */
	public static function accept( $id ) {
		$read = self::order( $id );
		if ( ! $read['ok'] ) {
			return self::outcome( false, self::error_message( $read['error'], $read['message'] ) );
		}
		$detail = $read['body'];
		$status = isset( $detail['status'] ) ? (string) $detail['status'] : '';
		if ( 'AWAITING_SELLER' !== $status ) {
			Sudemi_Changes::apply_entry( self::entry_from_detail( $detail ) );
			return self::outcome( false, self::error_message( 'already_resolved' ) );
		}
		$local = Sudemi_Orders::find_any_local_order( $id );
		if ( $local && $local->has_status( Sudemi_Orders::closed_statuses() ) ) {
			return self::outcome( false, __( 'The WooCommerce order for this sudemi order is cancelled. Decline it instead.', 'sudemi' ) );
		}
		if ( ! $local ) {
			$pending = self::pending_from_detail( $detail );
			$problem = Sudemi_Currency::order_problem( $pending, Sudemi_Currency::shop_currency() );
			if ( null !== $problem ) {
				return self::outcome( false, $problem );
			}
			$local = Sudemi_Orders::create_local_order( $pending, $id );
			if ( ! $local ) {
				return self::outcome( false, __( 'The order could not be recorded in WooCommerce (a product in it is no longer in this shop). Decline it instead.', 'sudemi' ) );
			}
		}
		$result = Sudemi_Orders::accept( $id, $local );
		switch ( $result['outcome'] ) {
			case 'paid':
				return self::outcome( true, __( 'Accepted. The buyer has been charged; the WooCommerce order is ready to ship.', 'sudemi' ) );
			case 'waiting':
				return self::outcome( true, __( 'Accepted. sudemi takes the money once the other shops in the buyer\'s payment have answered; do not ship until the order moves to Processing.', 'sudemi' ) );
			case 'retry':
				return self::outcome( false, __( 'sudemi did not answer. The WooCommerce order stays on hold and the acceptance is sent again on the next heartbeat.', 'sudemi' ) );
			default:
				$read = self::read( $result['response'] );
				return self::outcome( false, self::error_message( $read['ok'] ? 'wrong_state' : $read['error'], $read['message'] ) );
		}
	}

	/**
	 * Reads the order from sudemi again and applies it to the WooCommerce order.
	 *
	 * @param string $id    The sudemi order id.
	 * @param array  $extra Known here but not on sudemi's order page (cancelled_by).
	 */
	public static function reflect( $id, array $extra = array() ) {
		$read = self::order( $id );
		if ( $read['ok'] ) {
			Sudemi_Changes::apply_entry( array_merge( self::entry_from_detail( $read['body'] ), $extra ) );
		}
		return $read;
	}

	/**
	 * Several orders at once, through sudemi's bulk call, then each answer applied here.
	 *
	 * @return array{ok:bool,message:string,results?:array}
	 */
	public static function bulk( $action, array $ids, $reason = '', $carrier = '', array $tracking = array() ) {
		$body = self::bulk_body( $action, $ids, $reason, $carrier, $tracking );
		if ( is_string( $body ) ) {
			return self::outcome( false, self::error_message( $body ) );
		}
		$created  = array();
		$skipped  = array();
		if ( 'accept' === $action ) {
			// Each WooCommerce order first, as for a single acceptance.
			$items = array();
			foreach ( $body['items'] as $item ) {
				$prepared = self::prepare_accept( $item['orderId'] );
				if ( true === $prepared['ok'] ) {
					$items[] = $item;
					if ( ! empty( $prepared['created'] ) ) {
						$created[ $item['orderId'] ] = true;
					}
				} else {
					$skipped[ $item['orderId'] ] = $prepared['message'];
				}
			}
			if ( empty( $items ) ) {
				return self::outcome( false, implode( ' ', array_unique( $skipped ) ) );
			}
			$body['items'] = $items;
		}
		$read = self::call( 'POST', '/integration/v1/orders/bulk', $body );
		if ( ! $read['ok'] ) {
			if ( ! in_array( $read['error'], array( 'unreachable', 'too_many_requests' ), true ) ) {
				// Refused as a whole (a suspended shop, a malformed call): the orders made a moment
				// ago go again and their stock is put back. Without an answer they stay on hold and
				// the acceptance is sent again on the next heartbeat, as for a single one.
				self::discard( array_keys( $created ) );
			}
			return self::outcome( false, self::error_message( $read['error'], $read['message'] ) );
		}
		$results = isset( $read['body']['results'] ) && is_array( $read['body']['results'] ) ? $read['body']['results'] : array();
		$done    = 0;
		$refused = count( $skipped );
		$notes   = array();
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || empty( $result['order_id'] ) ) {
				continue;
			}
			$oid = strtolower( (string) $result['order_id'] );
			$ok  = isset( $result['result'] ) && 'done' === $result['result'];
			if ( ! $ok && 'shipped' === $action ) {
				$ok = self::bulk_tracking( $oid, $result, $body['items'] );
				if ( $ok ) {
					$result = array( 'order_id' => $oid, 'result' => 'done' );
				}
			}
			if ( $ok ) {
				++$done;
			} else {
				++$refused;
				$notes[] = substr( $oid, 0, 8 ) . ': ' . self::error_message( isset( $result['error'] ) ? $result['error'] : '', isset( $result['message'] ) ? $result['message'] : '' );
			}
			try {
				self::apply_bulk_result( $action, $oid, $ok, $result, isset( $created[ $oid ] ) );
			} catch ( Exception $e ) {
				Sudemi_Logger::error( 'Could not apply the bulk answer for sudemi order ' . $oid . ': ' . $e->getMessage() );
			}
		}
		foreach ( $skipped as $oid => $why ) {
			$notes[] = substr( $oid, 0, 8 ) . ': ' . $why;
		}
		Sudemi_Logger::info( 'Bulk ' . $action . ' from the sudemi page: ' . $done . ' done, ' . $refused . ' refused.' );
		$message = sprintf(
			/* translators: 1: orders done, 2: orders refused. */
			__( '%1$d done, %2$d refused.', 'sudemi' ),
			$done,
			$refused
		);
		if ( ! empty( $notes ) ) {
			$message .= ' ' . implode( ' ', $notes );
		}
		return self::outcome( 0 === $refused, $message );
	}

	/**
	 * Bulk shipped refused for an order sudemi has as SHIPPED already: the tracking code given
	 * for it is sent as a tracking change instead, so it reaches the buyer all the same.
	 *
	 * @return bool true when sudemi has that tracking now.
	 */
	private static function bulk_tracking( $oid, array $result, array $items ) {
		if ( ! isset( $result['error'] ) || 'wrong_state' !== $result['error'] ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( isset( $item['orderId'], $item['trackingCode'] ) && $item['orderId'] === $oid && '' !== (string) $item['trackingCode'] ) {
				$sent = Sudemi_Orders::push_tracking(
					$oid,
					array(
						'carrier'      => isset( $item['carrier'] ) ? $item['carrier'] : null,
						'trackingCode' => $item['trackingCode'],
					),
					'tracking'
				);
				return in_array( $sent['verdict'], array( 'done', 'same' ), true );
			}
		}
		return false;
	}

	/** Removes WooCommerce orders made for an acceptance sudemi refused, with their stock put back. */
	private static function discard( array $ids ) {
		foreach ( $ids as $id ) {
			$local = Sudemi_Orders::find_any_local_order( $id );
			if ( $local && $local->has_status( array( 'on-hold', 'pending' ) ) ) {
				wc_maybe_increase_stock_levels( $local->get_id() );
				$local->delete( true );
			}
		}
	}

	/** Creates the WooCommerce order for an acceptance, unless there is one already. */
	private static function prepare_accept( $id ) {
		$local = Sudemi_Orders::find_any_local_order( $id );
		if ( $local ) {
			return $local->has_status( Sudemi_Orders::closed_statuses() )
				? array( 'ok' => false, 'message' => __( 'The WooCommerce order for this sudemi order is cancelled. Decline it instead.', 'sudemi' ) )
				: array( 'ok' => true );
		}
		$read = self::order( $id );
		if ( ! $read['ok'] ) {
			return array( 'ok' => false, 'message' => self::error_message( $read['error'], $read['message'] ) );
		}
		if ( ! isset( $read['body']['status'] ) || 'AWAITING_SELLER' !== $read['body']['status'] ) {
			return array( 'ok' => true ); // sudemi answers already_resolved; applied afterwards.
		}
		$pending = self::pending_from_detail( $read['body'] );
		$problem = Sudemi_Currency::order_problem( $pending, Sudemi_Currency::shop_currency() );
		if ( null !== $problem ) {
			return array( 'ok' => false, 'message' => $problem );
		}
		$local = Sudemi_Orders::create_local_order( $pending, $id );
		if ( ! $local ) {
			return array( 'ok' => false, 'message' => __( 'The order could not be recorded in WooCommerce (a product in it is no longer in this shop). Decline it instead.', 'sudemi' ) );
		}
		return array( 'ok' => true, 'created' => true );
	}

	/** Applies one answer of a bulk call to the WooCommerce order. */
	private static function apply_bulk_result( $action, $oid, $ok, array $result, $created ) {
		$error = isset( $result['error'] ) ? (string) $result['error'] : '';
		if ( 'accept' === $action ) {
			$local = Sudemi_Orders::find_any_local_order( $oid );
			if ( $ok && $local ) {
				$waiting = isset( $result['payment'] ) && 'waiting_for_other_shops' === $result['payment'];
				Sudemi_Orders::apply_outcome( $oid, $local, $waiting ? 'waiting' : 'paid', array( 'status' => 200, 'body' => $result ) );
				return;
			}
			if ( ! $ok && $local && $created && in_array( $error, array( 'no_such_order', 'wrong_state', 'authorisation_lapsed', 'previous_connection' ), true ) ) {
				// sudemi will never take it: the order made a moment ago must not keep the stock.
				Sudemi_Orders::apply_outcome( $oid, $local, 'declined', array( 'status' => 409, 'body' => $result ) );
				return;
			}
			if ( ! $ok && 'already_resolved' === $error ) {
				self::reflect( $oid );
			}
			return;
		}
		if ( $ok || 'already_resolved' === $error || 'wrong_state' === $error ) {
			self::reflect( $oid );
		}
	}

	// ---------------------------------------------------------- order screen action ----

	public static function boot() {
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_sudemi_mark_delivered', array( __CLASS__, 'mark_delivered' ) );
	}

	/**
	 * "Mark delivered on sudemi" in the order screen's action list, for a sudemi order that has
	 * shipped. WooCommerce has no delivered status of its own; no other plugin is needed for this.
	 */
	public static function order_actions( $actions, $order = null ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		if ( ! $order && isset( $GLOBALS['theorder'] ) ) {
			$order = $GLOBALS['theorder'];
		}
		if ( ! $order instanceof WC_Order || '' === (string) $order->get_meta( Sudemi_Orders::META_ORDER_ID ) ) {
			return $actions;
		}
		if ( $order->has_status( array( 'processing', 'completed' ) ) && '' === (string) $order->get_meta( Sudemi_Changes::META_DELIVERED ) ) {
			$actions['sudemi_mark_delivered'] = __( 'Mark delivered on sudemi', 'sudemi' );
		}
		return $actions;
	}

	/** @param WC_Order $order */
	public static function mark_delivered( $order ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$id = strtolower( (string) $order->get_meta( Sudemi_Orders::META_ORDER_ID ) );
		if ( ! self::is_order_id( $id ) ) {
			return;
		}
		$sent = Sudemi_Orders::send_report( $id, 'delivered', array(), $order->get_id() );
		$read = $sent ? self::reflect( $id ) : array( 'ok' => false );
		$now  = wc_get_order( $order->get_id() );
		if ( $now && $read['ok'] && isset( $read['body']['status'] ) && 'DELIVERED' === $read['body']['status'] ) {
			return; // The delivery note is written by Sudemi_Changes.
		}
		if ( $now ) {
			$now->add_order_note(
				$sent
					? __( 'sudemi did not record the delivery (the order may not be shipped there yet). Check the order under WooCommerce > sudemi > Orders.', 'sudemi' )
					: __( 'Could not reach sudemi to report the delivery. It is sent again on the next heartbeat.', 'sudemi' )
			);
		}
	}
}
