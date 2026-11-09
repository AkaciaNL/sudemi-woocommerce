<?php
/**
 * Currencies (currency step 3): minor units per currency, an order in another currency than the
 * shop's is declined and never booked, a refund says its currency and is not sent in another one,
 * and what sudemi answered on POST /shop is kept for the settings page.
 *
 * Runs without WordPress: options live in an array, the signed calls are recorded.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$options        = array();
$shop_currency  = 'EUR';
$posted         = array();
$orders_created = 0;
$logged         = array();

function __( $text, $domain = 'default' ) {
	return $text;
}
function get_option( $name, $default = false ) {
	global $options;
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	global $options;
	$options[ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	global $options;
	unset( $options[ $name ] );
	return true;
}
function get_woocommerce_currency() {
	global $shop_currency;
	return $shop_currency;
}
function wc_get_orders( $args ) {
	return array();
}
function wc_get_order_statuses() {
	return array( 'wc-on-hold' => 'On hold' );
}
function wc_create_order() {
	global $orders_created;
	++$orders_created;
	throw new RuntimeException( 'an order was built' );
}
function wc_get_product( $id ) {
	return new WC_Product();
}
class WC_Product {
	public function is_in_stock() {
		return true;
	}
	public function has_enough_stock( $q ) {
		return true;
	}
	public function is_purchasable() {
		return true;
	}
	public function get_name() {
		return 'Mug';
	}
}
class WP_Error {
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
class Sudemi_Client {
	public static function post_signed( $path, $body ) {
		global $posted;
		$posted[] = array( $path, $body );
		return array( 'status' => 200, 'body' => array( 'status' => 'ok' ) );
	}
}
class Sudemi_Logger {
	public static function info( $m ) {
		global $logged;
		$logged[] = $m;
	}
	public static function warn( $m ) {
		self::info( $m );
	}
	public static function error( $m ) {
		self::info( $m );
	}
}

require __DIR__ . '/../includes/class-sudemi-currency.php';
require __DIR__ . '/../includes/class-sudemi-orders.php';

$failures = 0;
function expect( $label, $actual, $expected ) {
	global $failures;
	if ( $actual === $expected ) {
		echo "ok   $label\n";
		return;
	}
	++$failures;
	echo "FAIL $label: got " . var_export( $actual, true ) . ', expected ' . var_export( $expected, true ) . "\n";
}

// minor units: the currency's own, never a blind x100
expect( 'EUR 2 decimals', Sudemi_Currency::decimals( 'EUR' ), 2 );
expect( 'HUF 2 decimals (ISO 4217, as the contract says)', Sudemi_Currency::decimals( 'HUF' ), 2 );
expect( 'JPY 0 decimals', Sudemi_Currency::decimals( 'jpy' ), 0 );
expect( 'KWD 3 decimals', Sudemi_Currency::decimals( 'KWD' ), 3 );
expect( 'PLN 13.45 -> 1345', Sudemi_Currency::to_minor( '13.45', 'PLN' ), 1345 );
expect( 'JPY 1200 -> 1200', Sudemi_Currency::to_minor( '1200', 'JPY' ), 1200 );
expect( 'KWD 1.234 -> 1234', Sudemi_Currency::to_minor( 1.234, 'KWD' ), 1234 );
expect( 'float 8.5 + 4.95 SEK -> 1345', Sudemi_Currency::to_minor( 8.5 + 4.95, 'SEK' ), 1345 );
expect( 'major PLN 5', Sudemi_Currency::to_major( 5, 'PLN' ), '0.05' );
expect( 'major JPY 850', Sudemi_Currency::to_major( 850, 'JPY' ), '850' );
expect( 'major KWD 1234', Sudemi_Currency::to_major( 1234, 'KWD' ), '1.234' );
expect( 'major negative CZK', Sudemi_Currency::to_major( -1999, 'CZK' ), '-19.99' );
expect( 'orders major follows the shop currency', Sudemi_Orders::major( 1345 ), '13.45' );
$shop_currency = 'JPY';
expect( 'orders minor_of in a zero-decimal shop', Sudemi_Orders::minor_of( '1200' ), 1200 );
$shop_currency = 'EUR';

// supported shop currencies (contract 4c)
foreach ( array( 'EUR', 'PLN', 'CZK', 'HUF', 'RON', 'SEK', 'DKK', 'CHF', 'GBP', 'NOK', 'TRY' ) as $c ) {
	expect( "$c supported", Sudemi_Currency::is_supported( $c ), true );
}
expect( 'USD not supported', Sudemi_Currency::is_supported( 'USD' ), false );
expect( 'nonsense not supported', Sudemi_Currency::is_supported( 'euro' ), false );

// an incoming order
$order = array(
	'order_id'       => 'o-pln',
	'total_minor'    => 5495,
	'shipping_minor' => 495,
	'currency_code'  => 'PLN',
	'lines'          => array( array( 'external_id' => '14', 'title' => 'Mug', 'quantity' => 1, 'unit_price_minor' => 5000 ) ),
);
expect( 'order in the shop currency is fine', Sudemi_Currency::order_problem( $order, 'PLN' ), null );
expect( 'lower-case code is fine', Sudemi_Currency::order_problem( array( 'currency_code' => 'pln' ), 'PLN' ), null );
expect( 'older core without currency_code is fine', Sudemi_Currency::order_problem( array( 'order_id' => 'x' ), 'EUR' ), null );
expect(
	'PLN order in a EUR shop is refused',
	Sudemi_Currency::order_problem( $order, 'EUR' ),
	'the order was paid in PLN but this shop sells in EUR, so it cannot be booked here'
);
expect( 'empty code is refused', null !== Sudemi_Currency::order_problem( array( 'currency_code' => '' ), 'EUR' ), true );

// the decline path: rejected on sudemi, nothing built here, logged
$answer = new ReflectionMethod( 'Sudemi_Orders', 'answer' );
$answer->setAccessible( true );
$answer->invoke( null, $order );
expect( 'mismatch: one call to sudemi', count( $posted ), 1 );
expect( 'mismatch: it is the reject', $posted[0][0], '/integration/v1/orders/o-pln/reject' );
expect( 'mismatch: the reason names both currencies', $posted[0][1]['reason'], 'the order was paid in PLN but this shop sells in EUR, so it cannot be booked here' );
expect( 'mismatch: no local order built', $orders_created, 0 );
expect( 'mismatch: logged', (bool) preg_grep( '/could not be booked/', $logged ), true );

// same order in a PLN shop gets past the currency check and is built in PLN
$posted        = array();
$shop_currency = 'PLN';
try {
	$answer->invoke( null, $order );
} catch ( RuntimeException $e ) {
	// The stub stops at building the order.
}
expect( 'match: the order is built', $orders_created, 1 );
expect( 'match: nothing rejected', $posted, array() );

// refunds
class Fake_Order {
	public $currency;
	public $meta;
	public function __construct( $currency, $meta ) {
		$this->currency = $currency;
		$this->meta     = $meta;
	}
	public function get_currency() {
		return $this->currency;
	}
	public function get_meta( $key ) {
		return Sudemi_Currency::META_ORDER_CURRENCY === $key ? $this->meta : '';
	}
}
class Fake_Refund {
	public $currency;
	public $amount;
	public function __construct( $currency, $amount ) {
		$this->currency = $currency;
		$this->amount   = $amount;
	}
	public function get_currency() {
		return $this->currency;
	}
	public function get_amount() {
		return $this->amount;
	}
	public function get_reason() {
		return 'broken';
	}
}
expect(
	'refund body carries the currency and its minor units',
	Sudemi_Orders::refund_body( new Fake_Refund( 'PLN', '12.50' ), new Fake_Order( 'PLN', 'PLN' ) ),
	array( 'amountMinor' => 1250, 'currency' => 'PLN', 'reason' => 'broken' )
);
expect(
	'order booked before 1.2.12 (no meta): its own currency',
	Sudemi_Orders::refund_body( new Fake_Refund( 'CZK', '100' ), new Fake_Order( 'CZK', '' ) ),
	array( 'amountMinor' => 10000, 'currency' => 'CZK', 'reason' => 'broken' )
);
expect(
	'refund in another currency than the buyer paid in is refused',
	Sudemi_Orders::refund_body( new Fake_Refund( 'EUR', '12.50' ), new Fake_Order( 'EUR', 'PLN' ) ),
	'this refund is in EUR but the buyer paid in PLN; change the order back to PLN and refund again'
);
expect( 'refund_problem match', Sudemi_Currency::refund_problem( 'pln', 'PLN' ), null );

// what sudemi answered on POST /shop
Sudemi_Currency::remember_shop_answer( array( 'status' => 200, 'body' => array( 'status' => 'ok', 'currency' => 'pln' ) ) );
expect( 'confirmed currency kept', Sudemi_Currency::confirmed(), 'PLN' );
Sudemi_Currency::remember_shop_answer( array( 'status' => 400, 'body' => array( 'error' => 'bad_request', 'message' => 'currency USD is not supported for shops; supported: EUR, PLN' ) ) );
expect( 'refusal kept', get_option( Sudemi_Currency::OPTION_REFUSED ), 'currency USD is not supported for shops; supported: EUR, PLN' );
Sudemi_Currency::remember_shop_answer( array( 'status' => 400, 'body' => array( 'message' => 'displayName too long' ) ) );
expect( 'another refusal leaves it alone', get_option( Sudemi_Currency::OPTION_REFUSED ), 'currency USD is not supported for shops; supported: EUR, PLN' );
Sudemi_Currency::remember_shop_answer( array( 'status' => 200, 'body' => array( 'status' => 'ok', 'currency' => 'EUR' ) ) );
expect( 'accepted again clears the refusal', get_option( Sudemi_Currency::OPTION_REFUSED, 'none' ), 'none' );
Sudemi_Currency::remember_shop_answer( new WP_Error() );
expect( 'network error changes nothing', Sudemi_Currency::confirmed(), 'EUR' );

if ( $failures ) {
	echo "$failures failure(s)\n";
	exit( 1 );
}
echo "all passed\n";
