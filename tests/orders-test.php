<?php
/**
 * Order safety: how sudemi's answers to accept, reject, shipped and delivered are read, and
 * that the price queue keeps a newer change queued while an older one was in flight.
 *
 * The rule under test: only a definitive answer changes a local order. "already_resolved" with
 * PAID keeps it (an earlier accept landed), DECLINED cancels it, and no answer, a 5xx, a 429 or
 * a 409 changes nothing, because the buyer may already have been charged.
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
class WP_Error {
	public function get_error_message() {
		return 'down';
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
$GLOBALS['wp_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['wp_options'] ) ? $GLOBALS['wp_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['wp_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['wp_options'][ $key ] );
	return true;
}
function sanitize_text_field( $text ) {
	return trim( (string) $text );
}

require __DIR__ . '/../includes/class-sudemi-currency.php';
require __DIR__ . '/../includes/class-sudemi-variants.php';
require __DIR__ . '/../includes/class-sudemi-product-data.php';
require __DIR__ . '/../includes/class-sudemi-addons.php';
require __DIR__ . '/../includes/class-sudemi-personalisation.php';
require __DIR__ . '/../includes/class-sudemi-orders.php';
require __DIR__ . '/../includes/class-sudemi-queue.php';

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

// accept
expect( 'ok captured -> paid', Sudemi_Orders::accept_outcome( r( 200, array( 'status' => 'ok', 'payment' => 'captured' ) ) ), 'paid' );
expect( 'ok (older core, no payment field) -> paid', Sudemi_Orders::accept_outcome( r( 200, array( 'status' => 'ok' ) ) ), 'paid' );
expect( 'ok waiting -> waiting', Sudemi_Orders::accept_outcome( r( 200, array( 'status' => 'ok', 'payment' => 'waiting_for_other_shops' ) ) ), 'waiting' );
foreach ( array( 'PAID', 'SHIPPED', 'DELIVERED' ) as $s ) {
	expect( "already_resolved $s -> paid (keep the order)", Sudemi_Orders::accept_outcome( r( 200, array( 'status' => 'already_resolved', 'order_status' => $s ) ) ), 'paid' );
}
expect( 'already_resolved ACCEPTED -> waiting', Sudemi_Orders::accept_outcome( r( 200, array( 'status' => 'already_resolved', 'order_status' => 'ACCEPTED' ) ) ), 'waiting' );
expect( 'already_resolved DECLINED -> declined', Sudemi_Orders::accept_outcome( r( 200, array( 'status' => 'already_resolved', 'order_status' => 'DECLINED' ) ) ), 'declined' );
expect( 'already_resolved CANCELLED -> cancelled', Sudemi_Orders::accept_outcome( r( 200, array( 'status' => 'already_resolved', 'order_status' => 'CANCELLED' ) ) ), 'cancelled' );
expect( 'already_resolved REFUNDED -> cancelled', Sudemi_Orders::accept_outcome( r( 200, array( 'status' => 'already_resolved', 'order_status' => 'REFUNDED' ) ) ), 'cancelled' );
expect( 'already_resolved without order_status -> retry (never cancel on that alone)', Sudemi_Orders::accept_outcome( r( 200, array( 'status' => 'already_resolved' ) ) ), 'retry' );
expect( 'already_resolved AWAITING_SELLER -> retry', Sudemi_Orders::accept_outcome( r( 200, array( 'status' => 'already_resolved', 'order_status' => 'AWAITING_SELLER' ) ) ), 'retry' );
expect( '404 no_such_order -> declined', Sudemi_Orders::accept_outcome( r( 404, array( 'error' => 'no_such_order' ) ) ), 'declined' );
expect( '404 from something else -> retry', Sudemi_Orders::accept_outcome( r( 404, array( 'error' => 'not_found' ) ) ), 'retry' );
foreach ( array( 500, 502, 503, 429, 401, 403, 400 ) as $code ) {
	expect( "HTTP $code -> retry", Sudemi_Orders::accept_outcome( r( $code, array( 'error' => 'x' ) ) ), 'retry' );
}
expect( '409 site_mismatch -> retry', Sudemi_Orders::accept_outcome( r( 409, array( 'error' => 'site_mismatch' ) ) ), 'retry' );
expect( '409 authorisation_lapsed -> retry (sudemi declines it; the next check says so)', Sudemi_Orders::accept_outcome( r( 409, array( 'error' => 'authorisation_lapsed' ) ) ), 'retry' );
expect( 'network error -> retry', Sudemi_Orders::accept_outcome( new WP_Error() ), 'retry' );
expect( 'nothing -> retry', Sudemi_Orders::accept_outcome( null ), 'retry' );

// reject
expect( 'reject ok -> declined', Sudemi_Orders::reject_outcome( r( 200, array( 'status' => 'ok' ) ) ), 'declined' );
expect( 'reject already_resolved PAID -> paid', Sudemi_Orders::reject_outcome( r( 200, array( 'status' => 'already_resolved', 'order_status' => 'PAID' ) ) ), 'paid' );
expect( 'reject already_resolved DECLINED -> declined', Sudemi_Orders::reject_outcome( r( 200, array( 'status' => 'already_resolved', 'order_status' => 'DECLINED' ) ) ), 'declined' );
expect( 'reject 503 -> retry', Sudemi_Orders::reject_outcome( r( 503 ) ), 'retry' );
expect( 'reject network -> retry', Sudemi_Orders::reject_outcome( new WP_Error() ), 'retry' );

// shipped / delivered
expect( 'shipped ok -> done', Sudemi_Orders::report_verdict( r( 200, array( 'status' => 'ok' ) ) ), 'done' );
expect( 'shipped already_resolved -> done', Sudemi_Orders::report_verdict( r( 200, array( 'status' => 'already_resolved' ) ) ), 'done' );
expect( 'shipped 503 -> retry', Sudemi_Orders::report_verdict( r( 503 ) ), 'retry' );
expect( 'shipped 429 -> retry', Sudemi_Orders::report_verdict( r( 429 ) ), 'retry' );
expect( 'shipped 409 not_paid -> retry', Sudemi_Orders::report_verdict( r( 409, array( 'error' => 'not_paid' ) ) ), 'retry' );
expect( 'shipped 404 -> refused', Sudemi_Orders::report_verdict( r( 404, array( 'error' => 'no_such_order' ) ) ), 'refused' );
expect( 'shipped network -> retry', Sudemi_Orders::report_verdict( new WP_Error() ), 'retry' );

// price queue: a newer change queued while the older one was in flight survives
$sent    = array( '7' => array( 'externalId' => '7', 'priceMinor' => 1000 ), '8' => array( 'externalId' => '8', 'priceMinor' => 500 ) );
$current = array( '7' => array( 'externalId' => '7', 'priceMinor' => 1200 ), '8' => array( 'externalId' => '8', 'priceMinor' => 500 ), '9' => array( 'externalId' => '9', 'priceMinor' => 1 ) );
expect( 'queue keeps the newer price and the new product, clears the sent one', array_keys( Sudemi_Queue::without_sent( $current, $sent ) ), array( 7, 9 ) );

// what the buyer paid: the shop's order is booked at it, shipping included
$mug = array(
	'total_minor'    => 1345,
	'shipping_minor' => 495,
	'currency_code'  => 'EUR',
	'lines'          => array( array( 'external_id' => '14', 'quantity' => 1, 'unit_price_minor' => 850 ) ),
);
expect( 'paid amounts read', Sudemi_Orders::paid_amounts( $mug ), array( 'shipping' => 495, 'lines' => array( 0 => 850 ), 'total' => 1345 ) );
$older = $mug;
unset( $older['shipping_minor'] );
expect( 'older core without shipping -> catalogue prices', Sudemi_Orders::paid_amounts( $older ), null );
$older = $mug;
unset( $older['lines'][0]['unit_price_minor'] );
expect( 'older core without line prices -> catalogue prices', Sudemi_Orders::paid_amounts( $older ), null );
$bad = $mug;
$bad['shipping_minor'] = 'x';
expect( 'not money -> catalogue prices', Sudemi_Orders::paid_amounts( $bad ), null );
expect( 'major 850', Sudemi_Orders::major( 850 ), '8.50' );
expect( 'major 5', Sudemi_Orders::major( 5 ), '0.05' );
expect( 'major 1345', Sudemi_Orders::major( 1345 ), '13.45' );
expect( 'minor_of 13.45', Sudemi_Orders::minor_of( '13.45' ), 1345 );
expect( 'minor_of float 8.5 + 4.95', Sudemi_Orders::minor_of( 8.5 + 4.95 ), 1345 );

// 1.4.5 (owner 2026-11-20): automatic confirmation is off unless the shop turned it on, and the
// orders waiting for an answer are kept for the admin notice with their dates.
$GLOBALS['wp_options'] = array();
expect( 'auto-confirm: a shop that never saved the setting confirms by hand', Sudemi_Orders::auto_confirm_enabled(), false );
$GLOBALS['wp_options']['sudemi_auto_confirm'] = 'yes';
expect( 'auto-confirm: on when the shop turned it on', Sudemi_Orders::auto_confirm_enabled(), true );
$GLOBALS['wp_options']['sudemi_auto_confirm'] = 'no';
expect( 'auto-confirm: off when the shop turned it off', Sudemi_Orders::auto_confirm_enabled(), false );
$wait = Sudemi_Orders::waiting_entries(
	array(
		array( 'order_id' => 'b', 'respond_by' => '2026-11-22T10:00:00Z', 'total_minor' => 2490, 'currency_code' => 'EUR', 'lines' => array( array( 'title' => 'Mug', 'quantity' => 2 ) ) ),
		array( 'order_id' => 'a', 'respond_by' => '2026-11-21T09:00:00Z', 'total_minor' => 900, 'currency_code' => 'PLN', 'lines' => array( array( 'title' => 'Tea', 'quantity' => 1 ), array( 'title' => 'Cup', 'quantity' => 3 ) ) ),
		array( 'respond_by' => 'x' ),
	)
);
expect( 'waiting orders: soonest deadline first, with items and total', array( count( $wait ), $wait[0]['order_id'], $wait[0]['items'], $wait[1]['total_minor'], $wait[1]['currency'] ), array( 2, 'a', '1 x Tea, 3 x Cup', 2490, 'EUR' ) );
Sudemi_Orders::remember_waiting( array( array( 'order_id' => 'c', 'respond_by' => '2026-11-21T09:00:00Z' ) ) );
expect( 'waiting orders kept while confirming by hand', count( Sudemi_Orders::waiting() ), 1 );
Sudemi_Orders::remember_waiting( array() );
expect( 'a beat without waiting orders empties the list', Sudemi_Orders::waiting(), array() );
$GLOBALS['wp_options']['sudemi_auto_confirm'] = 'yes';
Sudemi_Orders::remember_waiting( array( array( 'order_id' => 'c' ) ) );
expect( 'with automatic confirmation nothing is kept (the shop answers them itself)', Sudemi_Orders::waiting(), array() );

// 1.4.10 (owner 2026-11-21): a once-per-line add-on fee is a sudemi line of its own (addon_fee),
// booked on its item (matched by position), never an item of its own. Mug x 3 at 17.95, gift wrap
// 3.00 once; positions need not start at 0.
$fee_order = array(
	'total_minor'    => 6180,
	'shipping_minor' => 495,
	'lines'          => array(
		array( 'line_id' => 'L1', 'position' => 4, 'external_id' => '14', 'title' => 'Mug', 'quantity' => 3, 'unit_price_minor' => 1795, 'addon_fee' => null ),
		array( 'line_id' => 'F1', 'position' => 5, 'external_id' => '14', 'title' => 'Gift wrap: Red paper (once)', 'quantity' => 1, 'unit_price_minor' => 300,
			'addon_fee' => array( 'parent_position' => 4, 'key' => 'pao:p:wrap-1', 'label' => 'Gift wrap', 'value' => 'Red paper', 'type' => 'choice', 'choice_keys' => array( 'o0' ), 'price_mode' => 'per_line', 'name' => 'Gift wrap: Red paper' ) ),
		array( 'line_id' => 'F2', 'position' => 6, 'external_id' => '99', 'title' => 'Lost (once)', 'quantity' => 1, 'unit_price_minor' => 100,
			'addon_fee' => array( 'parent_position' => 9, 'name' => 'Lost' ) ),
	),
);
expect( 'fee line: told apart from an item line', array( Sudemi_Orders::is_fee_line( $fee_order['lines'][0] ), Sudemi_Orders::is_fee_line( $fee_order['lines'][1] ) ), array( false, true ) );
$fees = Sudemi_Orders::fees_by_item( $fee_order['lines'] );
expect( 'fee line: matched to its item by position, with its name and id', $fees['items'], array( 0 => array( array( 'index' => 1, 'line_id' => 'F1', 'name' => 'Gift wrap: Red paper', 'parent_position' => 4 ) ) ) );
expect( 'fee line whose item is not in the order: an orphan (booked as an order fee, never a product)', array( count( $fees['orphans'] ), $fees['orphans'][0]['name'] ), array( 1, 'Lost' ) );
expect( 'paid amounts: total = 3 x 17.95 + 3.00 + 1.00 + shipping, every line read', Sudemi_Orders::paid_amounts( $fee_order )['lines'], array( 0 => 1795, 1 => 300, 2 => 100 ) );
$wait = Sudemi_Orders::waiting_entries( array( array( 'order_id' => 'f', 'respond_by' => 'x' ) + $fee_order ) );
expect( 'waiting orders: a fee line is not listed as an item', $wait[0]['items'], '3 x Mug' );
function wc_price( $amount, $args = array() ) {
	return '<span>' . ( isset( $args['currency'] ) ? $args['currency'] : '' ) . '&nbsp;' . number_format( (float) $amount, 2, '.', '' ) . '</span>';
}
function wp_strip_all_tags( $text ) {
	return strip_tags( $text );
}
$meta = Sudemi_Orders::item_money_meta( $fee_order['lines'][0], 1795, $fees['items'][0], Sudemi_Orders::paid_amounts( $fee_order )['lines'], 'PLN' );
expect( 'item meta: line id and unit, the fee named on the item (visible) and kept (hidden)', $meta, array(
	array( '_sudemi_line_id', 'L1' ),
	array( '_sudemi_unit_minor', 1795 ),
	array( 'Gift wrap: Red paper', "+PLN\u{a0}3.00, once for the whole line" ),
	array( '_sudemi_line_fee_minor', 300 ),
	array( '_sudemi_fee_lines', array( array( 'line_id' => 'F1', 'name' => 'Gift wrap: Red paper', 'minor' => 300 ) ) ),
) );
expect( 'item meta without fees: line id and unit only', Sudemi_Orders::item_money_meta( array( 'line_id' => 'L9' ), 850, array(), array(), 'EUR' ), array( array( '_sudemi_line_id', 'L9' ), array( '_sudemi_unit_minor', 850 ) ) );

// Refund by line: 1 of 3 mugs at exactly its price goes by line (the fee stays); the last 2 with
// the fee too; any other amount goes as the amount the shop entered.
$item = array( 'line_id' => 'L1', 'unit_minor' => 1795, 'fee_minor' => 300, 'quantity' => 3, 'units_sent' => 0, 'refund_qty' => 1 );
expect( 'refund 1 of 3 at 17.95: by line, the fee kept', Sudemi_Orders::refund_lines_for( array( $item ), 1795 ), array( array( 'lineId' => 'L1', 'quantity' => 1 ) ) );
expect( 'refund 1 of 3 at 18.95 (WooCommerce spread the fee): as an amount', Sudemi_Orders::refund_lines_for( array( $item ), 1895 ), null );
expect( 'refund the last 2 of 3 with the fee (2 x 17.95 + 3.00): by line', Sudemi_Orders::refund_lines_for( array( array( 'units_sent' => 1, 'refund_qty' => 2 ) + $item ), 3890 ), array( array( 'lineId' => 'L1', 'quantity' => 2 ) ) );
expect( 'refund the last 2 without the fee: as an amount (sudemi would give the fee back too)', Sudemi_Orders::refund_lines_for( array( array( 'units_sent' => 1, 'refund_qty' => 2 ) + $item ), 3590 ), null );
expect( 'refund more units than are left: as an amount', Sudemi_Orders::refund_lines_for( array( array( 'units_sent' => 3 ) + $item ), 1795 ), null );
expect( 'refund an item without a sudemi line: as an amount', Sudemi_Orders::refund_lines_for( array( array( 'line_id' => '' ) + $item ), 1795 ), null );
expect( 'refund of nothing by line: as an amount', Sudemi_Orders::refund_lines_for( array(), 0 ), null );

// 1.4.11: a refund that names units of an item with a once-per-line fee is set to what sudemi gives
// back, whatever WooCommerce's form filled in (line total / quantity, a share of the fee included).
$plan = Sudemi_Orders::refund_plan( array( $item ), 0 );
expect( 'refund plan: 1 of 3 (form proposed 11.95): set to 10.50, the fee kept', array( $plan['action'], $plan['expected'], $plan['gross'] ), array( 'set', 1795, array( 0 => 1795 ) ) );
$plan = Sudemi_Orders::refund_plan( array( array( 'units_sent' => 1, 'refund_qty' => 2 ) + $item ), 0 );
expect( 'refund plan: the last 2 of 3: the units and the fee', array( $plan['action'], $plan['expected'] ), array( 'set', 3890 ) );
$plan = Sudemi_Orders::refund_plan( array( array( 'refund_qty' => 3 ) + $item ), 0 );
expect( 'refund plan: all 3 at once: the units and the fee (the whole line)', $plan['expected'], 5685 );
$plan = Sudemi_Orders::refund_plan( array( $item ), 650 );
expect( 'refund plan: units with shipping in the same refund: refused, with what sudemi gives back for the units', array( $plan['action'], $plan['expected'] ), array( 'refuse', 1795 ) );
$plan = Sudemi_Orders::refund_plan( array( $item, array( 'line_id' => '' ) + $item ), 0 );
expect( 'refund plan: an item without a sudemi line next to it: refused', $plan['action'], 'refuse' );
expect( 'refund plan: no fee on the refunded item: as before (null)', Sudemi_Orders::refund_plan( array( array( 'fee_minor' => 0 ) + $item ), 0 ), null );
expect( 'refund plan: no quantities: as before (null)', Sudemi_Orders::refund_plan( array( array( 'refund_qty' => 0 ) + $item ), 0 ), null );
expect( 'split gross: no tax', Sudemi_Orders::split_gross( 1050, 3450, array() ), array( 1050, array() ) );
expect( 'split gross: 21% VAT in the item proportion, net + tax exact', Sudemi_Orders::split_gross( 1210, 3000, array( 7 => 630 ) ), array( 1000, array( 7 => 210 ) ) );
expect( 'split gross: two rates, rounding left in the net', Sudemi_Orders::split_gross( 1001, 900, array( 1 => 90, 2 => 10 ) ), array( 901, array( 1 => 90, 2 => 10 ) ) );

if ( $failures ) {
	echo "$failures failure(s)\n";
	exit( 1 );
}
echo "all passed\n";
