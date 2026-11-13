<?php
/**
 * Managing orders, shipping and payouts from the plugin (protocol 4d): the filters and signed
 * paths of the order table and export, the bulk and shipping bodies, how sudemi's answers are
 * read, and the rules of the change feed (revisions, status transitions, refund gaps, cursors).
 *
 * Runs without WordPress.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.NamingConventions.PrefixAllGlobals,WordPress.PHP.DevelopmentFunctions -- a command-line test (plain text output, test doubles of WordPress functions); left out of the release zip.

function __( $text, $domain = 'default' ) {
	return $text;
}
function _x( $text, $context, $domain = 'default' ) {
	return $text;
}
class WP_Error {
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

require __DIR__ . '/../includes/class-sudemi-currency.php';
require __DIR__ . '/../includes/class-sudemi-support.php';
require __DIR__ . '/../includes/class-sudemi-manage.php';
require __DIR__ . '/../includes/class-sudemi-changes.php';

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

$id  = '0f6c1c3e-1111-4222-8333-444455556666';
$id2 = '7a1d0000-1111-4222-8333-444455556666';

// Filters: unknown values dropped, never sent.
$f = Sudemi_Manage::filters( array( 'status' => 'paid', 'from' => '2026-11-01', 'to' => '2026-02-30', 'q' => " kettle\x01 ", 'sort' => 'TOTAL', 'dir' => 'up', 'offset' => '-5' ) );
expect( 'status upper-cased and kept', $f['status'], 'PAID' );
expect( 'a real date kept', $f['from'], '2026-11-01' );
expect( 'an impossible date dropped', $f['to'], '' );
expect( 'search trimmed, control characters gone', $f['q'], 'kettle' );
expect( 'sort lower-cased', $f['sort'], 'total' );
expect( 'unknown direction: desc', $f['dir'], 'desc' );
expect( 'negative offset: 0', $f['offset'], 0 );
expect( 'unknown status dropped', Sudemi_Manage::filters( array( 'status' => 'PAID OR 1=1' ) )['status'], '' );
expect( 'unknown sort: placed', Sudemi_Manage::filters( array( 'sort' => 'id; drop' ) )['sort'], 'placed' );

// The table call always carries offset (the 4d table, not the 1.2 history).
expect(
	'list path: offset, sort, dir and the set filters, RFC 3986',
	Sudemi_Manage::list_path( Sudemi_Manage::filters( array( 'status' => 'PAID', 'q' => 'blue mug', 'from' => '2026-11-01' ) ) ),
	'/integration/v1/orders?limit=25&offset=0&sort=placed&dir=desc&status=PAID&from=2026-11-01&q=blue%20mug'
);
expect( 'list path without filters still has offset', Sudemi_Manage::list_path( Sudemi_Manage::filters( array() ), 100 ), '/integration/v1/orders?limit=100&offset=0&sort=placed&dir=desc' );
expect( 'limit capped at 100', strpos( Sudemi_Manage::list_path( Sudemi_Manage::filters( array() ), 500 ), 'limit=100&' ) !== false, true );
expect(
	'export path: filters, language, decimal comma',
	Sudemi_Manage::export_path( Sudemi_Manage::filters( array( 'status' => 'SHIPPED', 'to' => '2026-11-09' ) ), 'de', true ),
	'/integration/v1/orders/export.csv?sort=placed&dir=desc&status=SHIPPED&to=2026-11-09&lang=de&decimal=comma'
);
expect( 'export path: an unknown language is en, no decimal', Sudemi_Manage::export_path( Sudemi_Manage::filters( array() ), 'xx', false ), '/integration/v1/orders/export.csv?sort=placed&dir=desc&lang=en' );
foreach ( array( 'en', 'nl', 'tr', 'de', 'fr', 'es', 'it', 'pl', 'ru', 'zh', 'ar', 'he' ) as $lang ) {
	expect( "export language $lang passed through", strpos( Sudemi_Manage::export_path( Sudemi_Manage::filters( array() ), $lang, false ), 'lang=' . $lang ) !== false, true );
}

// An order page as a pending order (to build the WooCommerce order) and as a feed entry.
$detail = array(
	'order_id'            => $id,
	'status'              => 'PAID',
	'destination_country' => 'PL',
	'lines'               => array( array( 'product_id' => 'p1', 'external_id' => '52', 'title' => 'Mug', 'quantity' => 2, 'unit_price_minor' => 1995, 'goods_minor' => 3990 ) ),
	'money'               => array( 'currency_code' => 'PLN', 'goods_minor' => 3990, 'shipping_minor' => 1500, 'total_minor' => 5490, 'refunded_minor' => 300 ),
	'tracking'            => array( 'carrier' => 'InPost', 'code' => 'X1' ),
	'shipped_at'          => '2026-11-09T10:00:00Z',
	'revision'            => 4,
);
$pending = Sudemi_Manage::pending_from_detail( $detail );
expect( 'pending: currency from money', $pending['currency_code'], 'PLN' );
expect( 'pending: destination', $pending['destination_country'], 'PL' );
expect( 'pending: totals', array( $pending['total_minor'], $pending['shipping_minor'] ), array( 5490, 1500 ) );
expect( 'pending: line with the shop\'s own product id and paid price', $pending['lines'][0]['external_id'] . '|' . $pending['lines'][0]['unit_price_minor'] . '|' . $pending['lines'][0]['quantity'], '52|1995|2' );
expect( 'pending: paid amounts readable by the booking code', Sudemi_Currency::order_problem( $pending, 'PLN' ), null );
$entry = Sudemi_Manage::entry_from_detail( $detail );
expect( 'entry: revision, refunded, tracking', array( $entry['revision'], $entry['refunded_minor'], $entry['tracking']['code'] ), array( 4, 300, 'X1' ) );

// Bulk bodies.
$b = Sudemi_Manage::bulk_body( 'shipped', array( $id, strtoupper( $id2 ), 'nope', $id ), '', ' DHL ', array( $id => 'JJD1', $id2 => '' ) );
expect( 'bulk shipped: valid ids only, once each, lower-cased', count( $b['items'] ), 2 );
expect( 'bulk shipped: carrier for all, tracking per order', $b['items'][0], array( 'orderId' => $id, 'carrier' => 'DHL', 'trackingCode' => 'JJD1' ) );
expect( 'bulk shipped: no empty tracking code sent', $b['items'][1], array( 'orderId' => $id2, 'carrier' => 'DHL' ) );
expect( 'bulk reject needs a reason', Sudemi_Manage::bulk_body( 'reject', array( $id ), '  ' ), 'reason_required' );
expect( 'bulk reject carries it', Sudemi_Manage::bulk_body( 'reject', array( $id ), 'Out of stock' )['reason'], 'Out of stock' );
expect( 'bulk refund is not offered', Sudemi_Manage::bulk_body( 'refund', array( $id ) ), 'invalid_action' );
expect( 'bulk cancel is not offered', Sudemi_Manage::bulk_body( 'cancel', array( $id ) ), 'invalid_action' );
expect( 'bulk without orders', Sudemi_Manage::bulk_body( 'accept', array( 'x' ) ), 'no_orders' );
$many = array();
for ( $i = 0; $i < 101; $i++ ) {
	$many[] = sprintf( '%08x-1111-4222-8333-444455556666', $i );
}
expect( 'bulk: at most 100', Sudemi_Manage::bulk_body( 'delivered', $many ), 'too_many' );
expect( 'bulk accept body', Sudemi_Manage::bulk_body( 'accept', array( $id ) ), array( 'action' => 'accept', 'items' => array( array( 'orderId' => $id ) ) ) );

// Shipping bodies.
$known = array( 'DE', 'FR', 'PL', 'AT' );
expect( 'shipping: shop mode clears', Sudemi_Manage::shipping_body( 'shop', 'EUR', array(), $known ), array( 'mode' => 'shop' ) );
$s = Sudemi_Manage::shipping_body( 'custom', 'EUR', array( 'DE' => array( 'enabled' => true, 'price' => '4,95' ), 'FR' => array( 'enabled' => true, 'price' => '' ), 'AT' => array( 'enabled' => false, 'price' => '' ), 'XX' => array( 'enabled' => true, 'price' => '1' ) ), $known );
expect( 'shipping: custom, currency', array( $s['mode'], $s['currency'] ), array( 'custom', 'EUR' ) );
expect( 'shipping: DE at 4.95 (decimal comma read)', $s['countries'][0], array( 'country' => 'DE', 'enabled' => true, 'priceMinor' => 495 ) );
expect( 'shipping: FR without a price (the shop\'s own)', $s['countries'][1], array( 'country' => 'FR', 'enabled' => true ) );
expect( 'shipping: AT switched off', $s['countries'][2], array( 'country' => 'AT', 'enabled' => false ) );
expect( 'shipping: an unknown country dropped', count( $s['countries'] ), 3 );
expect( 'shipping: PLN prices in grosze', Sudemi_Manage::shipping_body( 'custom', 'PLN', array( 'PL' => array( 'enabled' => true, 'price' => '12.5' ) ), $known )['countries'][0]['priceMinor'], 1250 );
expect( 'shipping: a negative price refused', Sudemi_Manage::shipping_body( 'custom', 'EUR', array( 'DE' => array( 'enabled' => true, 'price' => '-1' ) ), $known ), 'invalid_price' );
expect( 'shipping: text refused', Sudemi_Manage::shipping_body( 'custom', 'EUR', array( 'DE' => array( 'enabled' => true, 'price' => 'free' ) ), $known ), 'invalid_price' );
expect( 'shipping: unknown mode', Sudemi_Manage::shipping_body( 'all', 'EUR', array(), $known ), 'invalid_mode' );

// Reading answers.
expect( 'read: 200 ok', Sudemi_Manage::read( array( 'status' => 200, 'body' => array( 'a' => 1 ) ) )['ok'], true );
expect( 'read: transport error is unreachable', Sudemi_Manage::read( new WP_Error() )['error'], 'unreachable' );
expect( 'read: 429 is too_many_requests', Sudemi_Manage::read( array( 'status' => 429, 'body' => array() ) )['error'], 'too_many_requests' );
expect( 'read: 503 is unreachable', Sudemi_Manage::read( array( 'status' => 503, 'body' => array( 'error' => 'x' ) ) )['error'], 'unreachable' );
expect( 'read: 502 provider_refused kept', Sudemi_Manage::read( array( 'status' => 502, 'body' => array( 'error' => 'provider_refused' ) ) )['error'], 'provider_refused' );
expect( 'read: 409 code kept', Sudemi_Manage::read( array( 'status' => 409, 'body' => array( 'error' => 'not_paid', 'message' => 'm' ) ) )['error'], 'not_paid' );
expect( 'read: 4xx without a code is refused', Sudemi_Manage::read( array( 'status' => 400, 'body' => array() ) )['error'], 'refused' );
expect( 'error text for a known code', Sudemi_Manage::error_message( 'no_such_order' ), 'sudemi has no such order for this shop.' );
expect( 'error text: sudemi\'s own for an unknown code', Sudemi_Manage::error_message( 'new_code', 'Something new.' ), 'Something new.' );

// Change feed rules.
expect( 'revision: first one applies', Sudemi_Changes::is_newer( '', 0 ), true );
expect( 'revision: higher applies', Sudemi_Changes::is_newer( '3', 4 ), true );
expect( 'revision: equal is a repeat', Sudemi_Changes::is_newer( '4', 4 ), false );
expect( 'revision: older is ignored', Sudemi_Changes::is_newer( '5', 4 ), false );
expect( 'revision: none (a bulk answer) applies', Sudemi_Changes::is_newer( '5', null ), true );

expect( 'PAID on hold -> processing', Sudemi_Changes::transition( 'PAID', 'on-hold' ), 'processing' );
expect( 'PAID processing -> nothing', Sudemi_Changes::transition( 'PAID', 'processing' ), '' );
expect( 'ACCEPTED on hold -> hold', Sudemi_Changes::transition( 'ACCEPTED', 'on-hold' ), 'hold' );
expect( 'SHIPPED processing -> completed', Sudemi_Changes::transition( 'SHIPPED', 'processing' ), 'completed' );
expect( 'SHIPPED completed -> nothing', Sudemi_Changes::transition( 'SHIPPED', 'completed' ), '' );
expect( 'DELIVERED processing -> completed', Sudemi_Changes::transition( 'DELIVERED', 'processing' ), 'completed' );
expect( 'DECLINED on hold -> cancel', Sudemi_Changes::transition( 'DECLINED', 'on-hold' ), 'cancel' );
expect( 'DECLINED processing -> nothing (never a paid order)', Sudemi_Changes::transition( 'DECLINED', 'processing' ), '' );
expect( 'CANCELLED processing -> cancel', Sudemi_Changes::transition( 'CANCELLED', 'processing' ), 'cancel' );
expect( 'CANCELLED completed -> nothing (already shipped)', Sudemi_Changes::transition( 'CANCELLED', 'completed' ), '' );
expect( 'CANCELLED cancelled -> nothing (repeat)', Sudemi_Changes::transition( 'CANCELLED', 'cancelled' ), '' );
expect( 'REFUNDED completed -> nothing (the refund is recorded)', Sudemi_Changes::transition( 'REFUNDED', 'completed' ), '' );
expect( 'AWAITING_SELLER -> nothing', Sudemi_Changes::transition( 'AWAITING_SELLER', 'on-hold' ), '' );

expect( 'refund gap: partial on sudemi', Sudemi_Changes::refund_gap( 300, 0, 5490 ), 300 );
expect( 'refund gap: already recorded', Sudemi_Changes::refund_gap( 300, 300, 5490 ), 0 );
expect( 'refund gap: more recorded here than on sudemi: nothing', Sudemi_Changes::refund_gap( 300, 500, 5490 ), 0 );
expect( 'refund gap: never more than the order', Sudemi_Changes::refund_gap( 9000, 100, 5490 ), 5390 );

expect( 'cursor: sudemi\'s shape', Sudemi_Changes::valid_cursor( 'c1.MTc2MjY4NjAwMDAwMDAwMDowMDAw-_' ), true );
expect( 'cursor: empty start', Sudemi_Changes::valid_cursor( '' ), true );
expect( 'cursor: no markup', Sudemi_Changes::valid_cursor( 'c1.<script>' ), false );
expect( 'cursor: not too long', Sudemi_Changes::valid_cursor( str_repeat( 'a', 600 ) ), false );

echo $failures ? "\n$failures FAILED\n" : "\nALL PASS\n";
exit( $failures ? 1 : 0 );
