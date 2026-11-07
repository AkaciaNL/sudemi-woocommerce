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

if ( $failures ) {
	echo "$failures failure(s)\n";
	exit( 1 );
}
echo "all passed\n";
