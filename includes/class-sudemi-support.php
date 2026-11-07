<?php
/**
 * Support tickets between this shop and sudemi support.
 *
 * The shop owner opens and follows tickets from the plugin's own screen; every call is signed
 * with this install's key, so sudemi knows which shop is asking without a password. The plugin
 * sends no e-mail: sudemi mails the shop's contact address when support answers.
 *
 * The validation and payload helpers are pure so they can be tested without WordPress.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Support {

	const PATH = '/integration/v1/support/tickets';

	const CATEGORIES = array( 'ORDER', 'PAYMENT', 'REFUND_RETURN', 'ACCOUNT', 'SHOP_PLUGIN', 'PAYOUTS', 'OTHER' );

	/** The languages sudemi writes its support e-mails in. */
	const LOCALES = array( 'en', 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he' );

	const SUBJECT_MIN = 3;
	const SUBJECT_MAX = 200;
	const MESSAGE_MAX = 10000;

	const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Replaces the signed HTTP call, for tests only. Receives (method, path, body).
	 *
	 * @var callable|null
	 */
	private static $transport = null;

	public static function set_transport( $transport ) {
		self::$transport = $transport;
	}

	// ------------------------------------------------------------------ pure helpers ----

	/** 'nl_NL' -> 'nl', 'he_IL' -> 'he'; null when sudemi does not write that language. */
	public static function locale_for( $wp_locale ) {
		if ( ! is_string( $wp_locale ) || '' === $wp_locale ) {
			return null;
		}
		$lang = strtolower( substr( $wp_locale, 0, 2 ) );
		return in_array( $lang, self::LOCALES, true ) ? $lang : null;
	}

	public static function is_ticket_id( $id ) {
		return is_string( $id ) && 1 === preg_match( self::UUID, $id );
	}

	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/** Null when the message is acceptable, else an error code. */
	public static function validate_message( $message ) {
		$message = is_string( $message ) ? trim( $message ) : '';
		$len     = self::length( $message );
		return ( $len < 1 || $len > self::MESSAGE_MAX ) ? 'invalid_message' : null;
	}

	/**
	 * Checks a new ticket and builds the JSON body sudemi expects.
	 *
	 * @return array Either array( 'error' => code ) or array( 'body' => array ).
	 */
	public static function build_ticket( $category, $subject, $message, $order_id, $locale ) {
		$category = is_string( $category ) ? strtoupper( trim( $category ) ) : '';
		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			return array( 'error' => 'invalid_category' );
		}
		$subject = is_string( $subject ) ? trim( $subject ) : '';
		$len     = self::length( $subject );
		if ( $len < self::SUBJECT_MIN || $len > self::SUBJECT_MAX ) {
			return array( 'error' => 'invalid_subject' );
		}
		$error = self::validate_message( $message );
		if ( null !== $error ) {
			return array( 'error' => $error );
		}
		$body = array(
			'category' => $category,
			'subject'  => $subject,
			'message'  => trim( $message ),
		);
		$order_id = is_string( $order_id ) ? trim( $order_id ) : '';
		if ( '' !== $order_id ) {
			if ( ! self::is_ticket_id( $order_id ) ) {
				return array( 'error' => 'invalid_order' );
			}
			$body['orderId'] = strtolower( $order_id );
		}
		$locale = self::locale_for( $locale );
		if ( null !== $locale ) {
			$body['locale'] = $locale;
		}
		return array( 'body' => $body );
	}

	/** Maps a sudemi answer to a result code the screen can explain. */
	public static function error_code( $status, array $body ) {
		switch ( (int) $status ) {
			case 400:
				return 'invalid_request';
			case 401:
			case 403:
				return 'refused';
			case 404:
				return 'not_found';
			case 429:
				return 'too_many_tickets';
			default:
				return 'unavailable';
		}
	}

	// ------------------------------------------------------------------ calls ----

	/** @return array array( 'ok' => true, 'tickets' => array ) or array( 'ok' => false, 'error' => code ). */
	public static function list_tickets() {
		$result = self::call( 'GET', self::PATH, array() );
		if ( ! $result['ok'] ) {
			return $result;
		}
		$tickets = isset( $result['body']['tickets'] ) && is_array( $result['body']['tickets'] ) ? $result['body']['tickets'] : array();
		return array( 'ok' => true, 'tickets' => $tickets );
	}

	/** @return array array( 'ok' => true, 'ticket' => array ) or array( 'ok' => false, 'error' => code ). */
	public static function get_ticket( $id ) {
		if ( ! self::is_ticket_id( $id ) ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		return self::ticket_result( self::call( 'GET', self::PATH . '/' . rawurlencode( $id ), array() ) );
	}

	public static function open_ticket( $category, $subject, $message, $order_id = '', $locale = null ) {
		$built = self::build_ticket( $category, $subject, $message, $order_id, $locale );
		if ( isset( $built['error'] ) ) {
			return array( 'ok' => false, 'error' => $built['error'] );
		}
		return self::ticket_result( self::call( 'POST', self::PATH, $built['body'] ) );
	}

	public static function reply( $id, $message ) {
		if ( ! self::is_ticket_id( $id ) ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}
		$error = self::validate_message( $message );
		if ( null !== $error ) {
			return array( 'ok' => false, 'error' => $error );
		}
		return self::ticket_result(
			self::call( 'POST', self::PATH . '/' . rawurlencode( $id ) . '/messages', array( 'message' => trim( $message ) ) )
		);
	}

	private static function ticket_result( array $result ) {
		if ( ! $result['ok'] ) {
			return $result;
		}
		if ( empty( $result['body']['ticket'] ) || ! is_array( $result['body']['ticket'] ) ) {
			return array( 'ok' => false, 'error' => 'unavailable' );
		}
		return array( 'ok' => true, 'ticket' => $result['body']['ticket'] );
	}

	private static function call( $method, $path, array $body ) {
		$response = null !== self::$transport
			? call_user_func( self::$transport, $method, $path, $body )
			: Sudemi_Client::send_signed( $method, $path, $body );
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => 'sudemi_not_connected' === $response->get_error_code() ? 'not_connected' : 'unavailable',
			);
		}
		$status = isset( $response['status'] ) ? (int) $response['status'] : 0;
		$parsed = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
		if ( $status < 200 || $status >= 300 ) {
			return array( 'ok' => false, 'error' => self::error_code( $status, $parsed ) );
		}
		return array( 'ok' => true, 'body' => $parsed );
	}

	// ------------------------------------------------------------------ labels ----

	/** A friendly, translated sentence for a result code. */
	public static function error_message( $code ) {
		switch ( $code ) {
			case 'invalid_category':
				return __( 'Choose a category.', 'sudemi' );
			case 'invalid_subject':
				return __( 'The subject must be between 3 and 200 characters.', 'sudemi' );
			case 'invalid_message':
				return __( 'The message cannot be empty or longer than 10,000 characters.', 'sudemi' );
			case 'invalid_order':
				return __( 'That does not look like a sudemi order ID. Copy the full ID, or leave the field empty.', 'sudemi' );
			case 'invalid_request':
				return __( 'sudemi could not accept this. Check the subject and the message, and that the order ID belongs to an order of this shop.', 'sudemi' );
			case 'not_found':
				return __( 'This ticket was not found. It may belong to another shop or to an earlier connection.', 'sudemi' );
			case 'too_many_tickets':
				return __( 'This shop has too many open tickets, or opened several in the last hour. Please wait a while, or reply to an existing ticket instead.', 'sudemi' );
			case 'not_connected':
				return __( 'This shop is not connected to sudemi.', 'sudemi' );
			case 'refused':
				return __( 'sudemi refused this shop. Check the connection on the overview tab.', 'sudemi' );
			default:
				return __( 'Could not reach sudemi just now. Please try again shortly.', 'sudemi' );
		}
	}

	public static function category_label( $category ) {
		switch ( $category ) {
			case 'ORDER':
				return __( 'Order', 'sudemi' );
			case 'PAYMENT':
				return __( 'Payment', 'sudemi' );
			case 'REFUND_RETURN':
				return __( 'Refund or return', 'sudemi' );
			case 'ACCOUNT':
				return __( 'Account', 'sudemi' );
			case 'SHOP_PLUGIN':
				return __( 'Shop plugin or connection', 'sudemi' );
			case 'PAYOUTS':
				return __( 'Payouts', 'sudemi' );
			case 'OTHER':
				return __( 'Other', 'sudemi' );
			default:
				return (string) $category;
		}
	}

	public static function status_label( $status ) {
		switch ( $status ) {
			case 'OPEN':
				return _x( 'Open', 'support ticket status', 'sudemi' );
			case 'WAITING_FOR_CUSTOMER':
				return __( 'Waiting for your reply', 'sudemi' );
			case 'ANSWERED':
				return __( 'Answered', 'sudemi' );
			case 'CLOSED':
				return __( 'Closed', 'sudemi' );
			default:
				return (string) $status;
		}
	}
}
