<?php
/**
 * Tracking after shipping (protocol 4d) and who cancelled an order.
 *
 * - push_tracking() / send_tracking(): `tracking` while sudemi has the order SHIPPED, `shipped`
 *   with the tracking while it is still PAID, a switch when sudemi says the order is in the other
 *   state, a final answer for a delivered or closed order, the retry queue when sudemi is silent.
 * - tracking_is_news(): only a change made in the shop is sent, never sudemi's own tracking back.
 * - _wc_shipment_tracking_items (Shipment Tracking / Advanced Shipment Tracking) is read.
 * - cancel_note(): the order note says who cancelled (buyer, the shop, sudemi), neutral when
 *   sudemi did not say. decline_note() / decline_log(): the same for a declined order.
 *
 * Runs without WordPress: sudemi's answers are scripted.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

function __( $text, $domain = 'default' ) {
	return $text;
}
class WP_Error {
	public function get_error_message() {
		return 'down';
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
$GLOBALS['options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['options'] ) ? $GLOBALS['options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['options'][ $name ] );
	return true;
}
function sanitize_text_field( $text ) {
	return trim( (string) $text );
}

/** sudemi, scripted: each call takes the next answer for its path ending. */
final class Sudemi_Client {
	public static $answers = array();
	public static $calls   = array();

	public static function post_signed( $path, array $body = array() ) {
		$call            = substr( $path, strrpos( $path, '/' ) + 1 );
		self::$calls[]   = $call . ' ' . json_encode( $body );
		if ( empty( self::$answers[ $call ] ) ) {
			return new WP_Error();
		}
		return array_shift( self::$answers[ $call ] );
	}
}

require __DIR__ . '/../includes/class-sudemi-logger.php';
require __DIR__ . '/../includes/class-sudemi-order-calls.php';
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
function r( $status, array $body = array() ) {
	return array( 'status' => $status, 'body' => $body, 'retry_after' => 0 );
}
function script( array $answers ) {
	Sudemi_Client::$answers = $answers;
	Sudemi_Client::$calls   = array();
}
function calls() {
	return array_map(
		function ( $c ) {
			return strtok( $c, ' ' );
		},
		Sudemi_Client::$calls
	);
}

$id   = '0f6c1c3e-1111-4222-8333-444455556666';
$body = array( 'carrier' => 'DHL', 'trackingCode' => 'JJD0001' );
$ok   = r( 200, array( 'status' => 'ok' ) );
$same = r( 200, array( 'status' => 'already_resolved' ) );
$ns   = r( 409, array( 'error' => 'not_shipped' ) );

// Reading the answers.
expect( 'tracking ok -> done', Sudemi_Orders::tracking_answer( 'tracking', $ok ), 'done' );
expect( 'tracking already_resolved -> same', Sudemi_Orders::tracking_answer( 'tracking', $same ), 'same' );
expect( 'tracking 409 not_shipped -> not_shipped', Sudemi_Orders::tracking_answer( 'tracking', $ns ), 'not_shipped' );
expect( 'tracking 409 site_mismatch -> retry', Sudemi_Orders::tracking_answer( 'tracking', r( 409, array( 'error' => 'site_mismatch' ) ) ), 'retry' );
expect( 'tracking 400 tracking_code_required -> refused', Sudemi_Orders::tracking_answer( 'tracking', r( 400, array( 'error' => 'tracking_code_required' ) ) ), 'refused' );
expect( 'tracking 404 -> refused', Sudemi_Orders::tracking_answer( 'tracking', r( 404, array( 'error' => 'no_such_order' ) ) ), 'refused' );
expect( 'tracking 503 -> retry', Sudemi_Orders::tracking_answer( 'tracking', r( 503 ) ), 'retry' );
expect( 'tracking network -> retry', Sudemi_Orders::tracking_answer( 'tracking', new WP_Error() ), 'retry' );
expect( 'shipped ok -> done', Sudemi_Orders::tracking_answer( 'shipped', $ok ), 'done' );
expect( 'shipped already_resolved -> already (past PAID)', Sudemi_Orders::tracking_answer( 'shipped', $same ), 'already' );
expect( 'shipped 409 not_paid -> retry', Sudemi_Orders::tracking_answer( 'shipped', r( 409, array( 'error' => 'not_paid' ) ) ), 'retry' );

// SHIPPED on sudemi: one tracking call.
script( array( 'tracking' => array( $ok ) ) );
$res = Sudemi_Orders::push_tracking( $id, $body, 'tracking' );
expect( 'SHIPPED: tracking lands', array( $res['verdict'], $res['call'], calls() ), array( 'done', 'tracking', array( 'tracking' ) ) );
expect( 'SHIPPED: carrier and code sent as given', Sudemi_Client::$calls[0], 'tracking {"carrier":"DHL","trackingCode":"JJD0001"}' );

// Still PAID (the dispatch report is in the retry queue): tracking says not_shipped, shipped carries it.
script( array( 'tracking' => array( $ns ), 'shipped' => array( $ok ) ) );
$res = Sudemi_Orders::push_tracking( $id, $body, 'tracking' );
expect( 'PAID race: not_shipped, then shipped with the tracking', array( $res['verdict'], $res['call'], calls() ), array( 'done', 'shipped', array( 'tracking', 'shipped' ) ) );

// Delivered (or cancelled, refunded): tracking not_shipped, shipped already_resolved: final, two calls.
script( array( 'tracking' => array( $ns ), 'shipped' => array( $same ) ) );
$res = Sudemi_Orders::push_tracking( $id, $body, 'tracking' );
expect( 'DELIVERED: final after tracking + shipped', array( $res['verdict'], calls() ), array( 'final', array( 'tracking', 'shipped' ) ) );

// Manage screen "Mark shipped" with tracking on an order shipped elsewhere: shipped already, then tracking.
script( array( 'shipped' => array( $same ), 'tracking' => array( $ok ) ) );
$res = Sudemi_Orders::push_tracking( $id, $body, 'shipped' );
expect( 'shipped first, already shipped: tracking changes it', array( $res['verdict'], $res['call'], calls() ), array( 'done', 'tracking', array( 'shipped', 'tracking' ) ) );
script( array( 'shipped' => array( $same ), 'tracking' => array( $ns ) ) );
$res = Sudemi_Orders::push_tracking( $id, $body, 'shipped' );
expect( 'shipped first on a delivered order: final', array( $res['verdict'], calls() ), array( 'final', array( 'shipped', 'tracking' ) ) );
script( array( 'shipped' => array( $same ) ) );
$res = Sudemi_Orders::push_tracking( $id, array( 'carrier' => null, 'trackingCode' => null ), 'shipped' );
expect( 'shipped without a code, already shipped: same, one call', array( $res['verdict'], calls() ), array( 'same', array( 'shipped' ) ) );
script( array( 'tracking' => array( $same ) ) );
expect( 'the same tracking again: same', Sudemi_Orders::push_tracking( $id, $body )['verdict'], 'same' );

// send_tracking: the retry queue and the log.
Sudemi_Order_Calls::forget();
script( array( 'tracking' => array( r( 503 ) ) ) );
expect( 'no answer -> retry', Sudemi_Orders::send_tracking( $id, $body, 42 ), 'retry' );
$queued = Sudemi_Order_Calls::all();
expect( 'queued as a tracking call with its body and order', isset( $queued[ $id . '|tracking' ] ) ? array( $queued[ $id . '|tracking' ]['body'], $queued[ $id . '|tracking' ]['local_id'] ) : null, array( $body, 42 ) );
script( array( 'tracking' => array( $ok ) ) );
expect( 'the heartbeat sends it again and it lands', Sudemi_Order_Calls::drain(), 1 );
expect( 'and it leaves the queue', Sudemi_Order_Calls::count(), 0 );

// A queued dispatch report and a later tracking change: the tracking one ships it, both leave.
Sudemi_Order_Calls::enqueue( $id, 'shipped', array( 'carrier' => null, 'trackingCode' => null ), 42 );
script( array( 'tracking' => array( $ns ), 'shipped' => array( $ok ) ) );
expect( 'tracking change while still PAID ships the order with it', Sudemi_Orders::send_tracking( $id, $body, 42 ), 'done' );
expect( 'the queued shipped report is not sent again', Sudemi_Order_Calls::count(), 0 );

// Delivered: final, never retried forever.
script( array( 'tracking' => array( $ns ), 'shipped' => array( $same ) ) );
Sudemi_Order_Calls::enqueue( $id, 'tracking', $body, 42 );
expect( 'delivered: final', Sudemi_Orders::send_tracking( $id, $body, 42 ), 'final' );
expect( 'delivered: out of the queue', Sudemi_Order_Calls::count(), 0 );
script( array( 'tracking' => array( r( 404, array( 'error' => 'no_such_order' ) ) ) ) );
expect( 'no such order: refused, not queued', array( Sudemi_Orders::send_tracking( $id, $body, 42 ), Sudemi_Order_Calls::count() ), array( 'refused', 0 ) );
script( array( 'tracking' => array( $ok ) ) );
expect( 'send_report routes tracking (queue drain)', Sudemi_Orders::send_report( $id, 'tracking', $body, 42 ), true );
expect( 'send_report tracking without an answer is not settled', ( function () use ( $id, $body ) {
	script( array() );
	return Sudemi_Orders::send_report( $id, 'tracking', $body, 42 );
} )(), false );
expect( 'the log says what happened', (bool) array_filter(
	Sudemi_Logger::entries(),
	function ( $e ) {
		return false !== strpos( $e['message'], 'Sent the new tracking' );
	}
), true );

// What is news for sudemi.
$a  = array( 'carrier' => 'DHL', 'code' => 'A1' );
$b  = array( 'carrier' => 'DHL', 'code' => 'B2' );
$no = array( 'carrier' => '', 'code' => '' );
expect( 'shipped without tracking, tracking added here: news', Sudemi_Orders::tracking_is_news( $a, $no, $no ), true );
expect( 'tracking changed here after it was sent: news', Sudemi_Orders::tracking_is_news( $b, $a, $a ), true );
expect( 'carrier changed here: news', Sudemi_Orders::tracking_is_news( array( 'carrier' => 'DPD', 'code' => 'A1' ), $a, $a ), true );
expect( 'unchanged since it was sent: not news', Sudemi_Orders::tracking_is_news( $a, $a, $no ), false );
expect( 'unchanged here, sudemi has a newer one from the portal: not sent back', Sudemi_Orders::tracking_is_news( $a, $a, $b ), false );
expect( 'sudemi has exactly this already: not news', Sudemi_Orders::tracking_is_news( $a, $no, $a ), false );
expect( 'blank carrier and sudemi has the code: not news', Sudemi_Orders::tracking_is_news( array( 'carrier' => '', 'code' => 'A1' ), null, $a ), false );
expect( 'no code here: nothing to send', Sudemi_Orders::tracking_is_news( array( 'carrier' => 'DHL', 'code' => '' ), $no, $no ), false );
expect( 'nothing recorded (older plugin), differs from sudemi: news', Sudemi_Orders::tracking_is_news( $b, null, $a ), true );

// Shipment Tracking / Advanced Shipment Tracking items: the last parcel.
$items = array(
	array( 'tracking_provider' => 'dhl', 'tracking_number' => 'OLD1' ),
	array( 'tracking_provider' => '', 'custom_tracking_provider' => 'Colissimo', 'tracking_number' => ' 6A123 ' ),
);
expect( 'items: last parcel code', Sudemi_Orders::shipment_item_value( $items, 'code' ), '6A123' );
expect( 'items: custom carrier first', Sudemi_Orders::shipment_item_value( $items, 'carrier' ), 'Colissimo' );
expect( 'items: provider when no custom one', Sudemi_Orders::shipment_item_value( array( $items[0] ), 'carrier' ), 'dhl' );
expect( 'items: none', Sudemi_Orders::shipment_item_value( '', 'code' ), null );

// Who cancelled.
expect( 'buyer', Sudemi_Orders::cancel_note( 'buyer' ), 'Cancelled by the buyer on sudemi before shipping; the buyer has been refunded in full. Do not ship this order.' );
expect( 'shop', strpos( Sudemi_Orders::cancel_note( 'shop' ), 'Cancelled by your shop on sudemi' ), 0 );
expect( 'sudemi', strpos( Sudemi_Orders::cancel_note( 'SUDEMI' ), 'Cancelled by sudemi' ), 0 );
expect( 'not said (older core): neutral, never "the buyer"', strpos( Sudemi_Orders::cancel_note( null ), 'Cancelled on sudemi before shipping' ) === 0 && false === strpos( Sudemi_Orders::cancel_note( null ), 'by the buyer' ), true );
expect( 'unknown value: neutral', Sudemi_Orders::cancel_note( 'robot' ), Sudemi_Orders::cancel_note( null ) );
expect( 'log line: shop', strpos( Sudemi_Orders::cancel_log( 'shop', $id, '77' ), 'cancelled by your shop' ) !== false && strpos( Sudemi_Orders::cancel_log( 'shop', $id, '77' ), '#77' ) !== false, true );
expect( 'log line: buyer', strpos( Sudemi_Orders::cancel_log( 'buyer', $id, '77' ), 'cancelled by the buyer' ) !== false, true );
expect( 'log line: neutral', strpos( Sudemi_Orders::cancel_log( '', $id, '77' ), 'cancelled on sudemi' ) !== false, true );
foreach ( array( 'buyer', 'shop', 'sudemi', null ) as $by ) {
	expect( 'no em dash: ' . var_export( $by, true ), false === strpos( Sudemi_Orders::cancel_note( $by ) . Sudemi_Orders::cancel_log( $by, $id, '1' ), "\u{2014}" ), true );
}

// Who declined (DECLINED: never charged).
expect( 'decline: shop', Sudemi_Orders::decline_note( 'shop' ), 'Declined by your shop on sudemi; the buyer was not charged. The stock has been restored.' );
expect( 'decline: sudemi', Sudemi_Orders::decline_note( 'Sudemi' ), 'Declined automatically by sudemi (for example because the order was not answered in time); the buyer was not charged. The stock has been restored.' );
expect( 'decline: buyer', Sudemi_Orders::decline_note( 'buyer' ), 'Cancelled by the buyer on sudemi before the order was confirmed; the buyer was not charged. The stock has been restored.' );
expect( 'decline: not said (older core), feed: the neutral note of before', Sudemi_Orders::decline_note( null ), 'Declined on sudemi: the buyer was not charged. The stock has been restored.' );
expect( 'decline: unknown value: neutral', Sudemi_Orders::decline_note( 'robot' ), Sudemi_Orders::decline_note( null ) );
expect( 'decline: not said, settled with a reason: the note of before', Sudemi_Orders::decline_note( null, 'authorisation lapsed' ), 'sudemi released the buyer\'s payment: authorisation lapsed. Nothing was charged and the stock has been restored.' );
expect( 'decline: not said, settled without a reason: the note of before', Sudemi_Orders::decline_note( '', '' ), 'sudemi released the buyer\'s payment: reason not given. Nothing was charged and the stock has been restored.' );
expect( 'decline: shop with a reason: kept', Sudemi_Orders::decline_note( 'shop', 'Out of stock.' ), 'Declined by your shop on sudemi; the buyer was not charged. The stock has been restored. Reason: Out of stock.' );
expect( 'decline: sudemi with an empty reason: none added', Sudemi_Orders::decline_note( 'sudemi', ' ' ), Sudemi_Orders::decline_note( 'sudemi' ) );
expect( 'decline: never "your shop" unless sudemi said so', false === strpos( Sudemi_Orders::decline_note( 'sudemi' ) . Sudemi_Orders::decline_note( 'buyer' ) . Sudemi_Orders::decline_note( null ), 'your shop' ), true );
expect( 'decline log: shop', Sudemi_Orders::decline_log( 'shop', $id, '77' ), "sudemi order $id was declined by your shop on sudemi; order #77 was cancelled here and the stock restored." );
expect( 'decline log: sudemi', strpos( Sudemi_Orders::decline_log( 'sudemi', $id, '77' ), 'declined automatically by sudemi' ) !== false && strpos( Sudemi_Orders::decline_log( 'sudemi', $id, '77' ), '#77' ) !== false, true );
expect( 'decline log: buyer', strpos( Sudemi_Orders::decline_log( 'buyer', $id, '77' ), 'cancelled by the buyer before it was confirmed' ) !== false, true );
expect( 'decline log: neutral (feed)', Sudemi_Orders::decline_log( null, $id, '77' ), "sudemi order $id was declined on sudemi; order #77 was cancelled here and the stock restored." );
expect( 'decline log: neutral (settled): the line of before', Sudemi_Orders::decline_log( null, $id, '77', 'lapsed' ), "sudemi order $id was released after all: lapsed. The local order was cancelled and stock restored." );
expect( 'decline log: shop with a reason', substr( Sudemi_Orders::decline_log( 'shop', $id, '77', 'Sold out' ), -18 ), ' Reason: Sold out.' );
foreach ( array( 'buyer', 'shop', 'sudemi', null ) as $by ) {
	expect( 'decline, no em dash: ' . var_export( $by, true ), false === strpos( Sudemi_Orders::decline_note( $by, 'x' ) . Sudemi_Orders::decline_log( $by, $id, '1', 'x' ), "\u{2014}" ), true );
}

echo $failures ? "\n$failures FAILED\n" : "\nALL PASS\n";
exit( $failures ? 1 : 0 );
