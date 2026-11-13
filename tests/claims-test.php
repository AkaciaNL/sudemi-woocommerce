<?php
/**
 * Review 2026-11-12: exactly one caller builds the WooCommerce order for a sudemi order.
 *
 * The claim is a row keyed on the sudemi order id, inserted before anything is built. This test
 * runs the claim logic against an in-memory table that behaves like MySQL for the three statements
 * used (INSERT IGNORE on the primary key, SELECT, conditional UPDATE). That the database lets only
 * one insert in under real concurrency is checked end to end on a dev shop (e2e).
 *
 * Runs without WordPress.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.NamingConventions.PrefixAllGlobals,WordPress.PHP.DevelopmentFunctions -- a command-line test (plain text output, test doubles of WordPress functions); left out of the release zip.
define( 'ARRAY_A', 'ARRAY_A' );

/** The rows of wp_sudemi_order_claim, keyed by sudemi order id. */
$rows = array();
/** WooCommerce orders that exist. */
$existing_orders = array( 501 => true );

class Fake_Wpdb {
	public $prefix = 'wp_';

	public function prepare( $sql, ...$args ) {
		return array( $sql, $args );
	}

	public function query( $prepared ) {
		global $rows;
		list( $sql, $args ) = $prepared;
		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			if ( isset( $rows[ $args[0] ] ) ) {
				return 0;
			}
			$rows[ $args[0] ] = array( 'local_order_id' => 0, 'claimed_at' => $args[1] );
			return 1;
		}
		if ( false !== strpos( $sql, 'SET local_order_id = 0' ) ) {
			list( $now, $id, $local ) = $args;
			if ( isset( $rows[ $id ] ) && (int) $rows[ $id ]['local_order_id'] === (int) $local ) {
				$rows[ $id ] = array( 'local_order_id' => 0, 'claimed_at' => $now );
				return 1;
			}
			return 0;
		}
		if ( false !== strpos( $sql, 'SET claimed_at' ) ) {
			list( $now, $id, $was ) = $args;
			if ( isset( $rows[ $id ] ) && 0 === (int) $rows[ $id ]['local_order_id'] && $rows[ $id ]['claimed_at'] === $was ) {
				$rows[ $id ]['claimed_at'] = $now;
				return 1;
			}
			return 0;
		}
		throw new RuntimeException( 'unexpected SQL ' . $sql );
	}

	public function get_row( $prepared, $output ) {
		global $rows;
		$id = $prepared[1][0];
		return isset( $rows[ $id ] ) ? $rows[ $id ] : null;
	}

	public function update( $table, $data, $where ) {
		global $rows;
		$rows[ $where['sudemi_order_id'] ]['local_order_id'] = $data['local_order_id'];
	}

	public function delete( $table, $where ) {
		global $rows;
		unset( $rows[ $where['sudemi_order_id'] ] );
	}
}
$wpdb = new Fake_Wpdb();

function wc_get_order( $id ) {
	global $existing_orders;
	return isset( $existing_orders[ $id ] ) ? (object) array( 'id' => $id ) : false;
}

require __DIR__ . '/../includes/class-sudemi-claims.php';

$failures = 0;
function expect( $label, $actual, $expected ) {
	global $failures;
	if ( $actual === $expected ) {
		echo "ok   $label\n";
		return;
	}
	++$failures;
	echo "FAIL $label: expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

$a = 'aaaaaaaa-0000-0000-0000-000000000001';

// A double click: the first claims, the second is told someone is building it.
expect( 'first caller wins', Sudemi_Claims::claim( $a ), Sudemi_Claims::WON );
expect( 'second caller at the same moment builds nothing', Sudemi_Claims::claim( $a ), Sudemi_Claims::BUSY );
expect( 'third caller too', Sudemi_Claims::claim( $a ), Sudemi_Claims::BUSY );

// Built and linked: everyone after gets that order, nobody builds another.
Sudemi_Claims::link( $a, 501 );
expect( 'after the build, callers get the built order', Sudemi_Claims::claim( $a ), 501 );

// The order was deleted for good while sudemi still waits: exactly one caller may rebuild it.
unset( $existing_orders[501] );
expect( 'deleted order: one caller takes it over', Sudemi_Claims::claim( $a ), Sudemi_Claims::WON );
expect( 'deleted order: the next caller does not', Sudemi_Claims::claim( $a ), Sudemi_Claims::BUSY );

// A caller died after claiming: after the stale time, exactly one caller takes over.
$b          = 'bbbbbbbb-0000-0000-0000-000000000002';
$rows[ $b ] = array(
	'local_order_id' => 0,
	'claimed_at'     => gmdate( 'Y-m-d H:i:s', time() - Sudemi_Claims::STALE_SECONDS - 5 ),
);
expect( 'stale claim: one caller takes it over', Sudemi_Claims::claim( $b ), Sudemi_Claims::WON );
expect( 'stale claim: the next caller is busy', Sudemi_Claims::claim( $b ), Sudemi_Claims::BUSY );

// A claim younger than the stale time is never taken over.
$c          = 'cccccccc-0000-0000-0000-000000000003';
$rows[ $c ] = array(
	'local_order_id' => 0,
	'claimed_at'     => gmdate( 'Y-m-d H:i:s', time() - 60 ),
);
expect( 'fresh claim is not taken over', Sudemi_Claims::claim( $c ), Sudemi_Claims::BUSY );

// Nothing could be built: the claim is given up and a later caller may try again.
Sudemi_Claims::release( $c );
expect( 'released claim can be claimed again', Sudemi_Claims::claim( $c ), Sudemi_Claims::WON );

if ( $failures > 0 ) {
	echo "$failures failure(s)\n";
	exit( 1 );
}
echo "all passed\n";
