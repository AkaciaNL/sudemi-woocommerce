<?php
/**
 * The shop's currency as sudemi sees it, and money in each currency's own minor units.
 *
 * A shop sells in its own currency and the buyer's card is charged in it (owner decision
 * 2026-11-09). sudemi sends every amount in the minor units ISO 4217 defines for that currency:
 * cents for EUR, groszy for PLN, but whole yen for JPY. Multiplying by 100 for every currency is
 * right for most of Europe and wrong by a factor of a hundred for the rest, so every conversion
 * in this plugin goes through here.
 *
 * Runs without WordPress where it can, so the tests can call it directly.
 *
 * @package sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Currency {

	/** The currency sudemi confirmed for this shop on the last POST /shop. */
	const OPTION_CONFIRMED = 'sudemi_shop_currency';

	/** What sudemi refused on the last POST /shop, if anything (shown on the settings page). */
	const OPTION_REFUSED = 'sudemi_shop_currency_refused';

	/** On the local order: the currency the buyer's card was charged in, as sudemi sent it. */
	const META_ORDER_CURRENCY = '_sudemi_currency';

	/** Shop currencies sudemi accepts: EUR and every ECB currency except ISK (owner 2026-11-09, contract 4c). */
	const SUPPORTED = array( 'EUR', 'AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'GBP', 'HKD', 'HUF', 'IDR', 'ILS', 'INR', 'JPY', 'KRW', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RON', 'SEK', 'SGD', 'THB', 'TRY', 'USD', 'ZAR' );

	/** ISO 4217 currencies without minor units. */
	const ZERO_DECIMALS = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/** ISO 4217 currencies with three decimals. */
	const THREE_DECIMALS = array( 'BHD', 'JOD', 'KWD', 'OMR', 'TND' );

	/** A currency code as sudemi writes it (three upper-case letters), or '' when it is not one. */
	public static function normalise( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		return 1 === preg_match( '/^[A-Z]{3}$/', $code ) ? $code : '';
	}

	/** How many decimals the currency's minor unit has (2 unless ISO 4217 says otherwise). */
	public static function decimals( $code ) {
		$code = self::normalise( $code );
		if ( in_array( $code, self::ZERO_DECIMALS, true ) ) {
			return 0;
		}
		if ( in_array( $code, self::THREE_DECIMALS, true ) ) {
			return 3;
		}
		return 2;
	}

	/** An amount ("13.45", 13.45) in the currency's minor units (1345). */
	public static function to_minor( $amount, $code ) {
		return (int) round( (float) $amount * ( 10 ** self::decimals( $code ) ) );
	}

	/** Minor units as the decimal string WooCommerce stores ("8.50"; "850" for JPY). */
	public static function to_major( $minor, $code ) {
		$minor    = (int) $minor;
		$decimals = self::decimals( $code );
		$sign     = $minor < 0 ? '-' : '';
		$minor    = abs( $minor );
		if ( 0 === $decimals ) {
			return $sign . $minor;
		}
		$factor = 10 ** $decimals;
		return $sign . intdiv( $minor, $factor ) . '.' . str_pad( (string) ( $minor % $factor ), $decimals, '0', STR_PAD_LEFT );
	}

	/** Whether sudemi takes this as a shop currency. */
	public static function is_supported( $code ) {
		return in_array( self::normalise( $code ), self::SUPPORTED, true );
	}

	/** The shop's own currency, from WooCommerce's settings. */
	public static function shop_currency() {
		return function_exists( 'get_woocommerce_currency' ) ? self::normalise( get_woocommerce_currency() ) : 'EUR';
	}

	/** The currency sudemi confirmed for this shop, or '' before the first answer. */
	public static function confirmed() {
		return function_exists( 'get_option' ) ? self::normalise( get_option( self::OPTION_CONFIRMED, '' ) ) : '';
	}

	/**
	 * Remembers what sudemi answered to POST /shop, for the settings page.
	 *
	 * @param array|WP_Error|null $response The signed client's response.
	 */
	public static function remember_shop_answer( $response ) {
		if ( ! is_array( $response ) || ! isset( $response['status'] ) ) {
			return;
		}
		$body = isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array();
		if ( 200 === (int) $response['status'] ) {
			if ( ! empty( $body['currency'] ) ) {
				update_option( self::OPTION_CONFIRMED, self::normalise( $body['currency'] ), false );
			}
			delete_option( self::OPTION_REFUSED );
			return;
		}
		if ( 400 === (int) $response['status'] || ( isset( $body['status'] ) && 'rejected' === $body['status'] ) ) {
			$message = isset( $body['message'] ) ? (string) $body['message'] : ( isset( $body['error'] ) ? (string) $body['error'] : '' );
			if ( false !== stripos( $message, 'currency' ) ) {
				update_option( self::OPTION_REFUSED, substr( $message, 0, 500 ), false );
			}
		}
	}

	/**
	 * Why an incoming order cannot be booked in this shop, or null when it can.
	 *
	 * The order is booked in the currency the buyer's card was charged in, never quietly in
	 * another one: an order of 100 PLN booked as 100 EUR is a different order.
	 *
	 * @param array  $pending One entry of the pending_orders block.
	 * @param string $shop    This shop's currency.
	 * @return string|null
	 */
	public static function order_problem( array $pending, $shop ) {
		if ( ! array_key_exists( 'currency_code', $pending ) || null === $pending['currency_code'] ) {
			// A core older than currency support: its orders are in the shop's currency.
			return null;
		}
		$code = self::normalise( $pending['currency_code'] );
		$shop = self::normalise( $shop );
		if ( '' === $code ) {
			return __( 'the order did not say which currency it was paid in', 'sudemi-connector' );
		}
		if ( $code !== $shop ) {
			return sprintf(
				/* translators: 1: the order's currency, e.g. PLN, 2: the shop's currency, e.g. EUR. */
				__( 'the order was paid in %1$s but this shop sells in %2$s, so it cannot be booked here', 'sudemi-connector' ),
				$code,
				$shop
			);
		}
		return null;
	}

	/**
	 * Why a refund cannot be sent to sudemi, or null when it can.
	 *
	 * The buyer was charged in the currency the order was booked in; a refund in another one
	 * would pay back a different amount than the shop meant.
	 *
	 * @param string $refund_currency The WooCommerce refund's (order's) currency.
	 * @param string $charged         The currency the sudemi order was charged in.
	 * @return string|null
	 */
	public static function refund_problem( $refund_currency, $charged ) {
		$refund_currency = self::normalise( $refund_currency );
		$charged         = self::normalise( $charged );
		if ( '' === $charged || $refund_currency === $charged ) {
			return null;
		}
		return sprintf(
			/* translators: 1: the refund's currency, 2: the currency the buyer paid in. */
			__( 'this refund is in %1$s but the buyer paid in %2$s; change the order back to %2$s and refund again', 'sudemi-connector' ),
			'' === $refund_currency ? '?' : $refund_currency,
			$charged
		);
	}
}
