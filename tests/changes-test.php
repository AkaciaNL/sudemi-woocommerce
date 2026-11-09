<?php
/**
 * The change feed when an order cannot be applied (protocol 4d):
 *
 * - the cursor always moves on; the order goes on a per-order retry list instead of holding the
 *   whole feed back (a repeating 5xx used to block it for good);
 * - an Error (Throwable), not only an Exception, is caught and the order kept for later;
 * - later beats read the order from sudemi again (GET /orders/{id}) and take it off the list
 *   once applied; a gone order and one that keeps failing (tries or age) are logged, never
 *   dropped silently;
 * - locked(): a refund is recorded under a named lock, not at all when the lock is held.
 *
 * Runs without WordPress: sudemi's answers are scripted.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );

function __( $text, $domain = 'default' ) {
	return $text;
}
class WP_Error {
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

final class Sudemi_Logger {
	public static $lines = array();
	public static function info( $m ) {
		self::$lines[] = 'info ' . $m;
	}
	public static function warn( $m ) {
		self::$lines[] = 'warn ' . $m;
	}
	public static function error( $m ) {
		self::$lines[] = 'error ' . $m;
	}
	public static function has( $level, $part ) {
		foreach ( self::$lines as $l ) {
			if ( 0 === strpos( $l, $level . ' ' ) && false !== strpos( $l, $part ) ) {
				return true;
			}
		}
		return false;
	}
}

final class Sudemi_Client {
	public static function install_id() {
		return 'install-1';
	}
}

/** sudemi's order page, scripted per order id: each GET takes the next answer (the last one repeats). */
final class Sudemi_Manage {
	public static $answers = array();
	public static $gets    = 0;
	public static function is_order_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id );
	}
	public static function order( $id ) {
		++self::$gets;
		$list = isset( self::$answers[ $id ] ) ? self::$answers[ $id ] : array();
		if ( empty( $list ) ) {
			return array( 'ok' => false, 'status' => 0, 'body' => array(), 'error' => 'unreachable', 'message' => '' );
		}
		return count( $list ) > 1 ? array_shift( self::$answers[ $id ] ) : $list[0];
	}
	public static function entry_from_detail( array $d ) {
		return array(
			'order_id' => $d['order_id'],
			'status'   => $d['status'],
			'revision' => $d['revision'],
		);
	}
}

/** WooCommerce has no local order for any of these; one id makes the lookup throw an Error. */
final class Sudemi_Orders {
	public static $broken = '';
	public static function find_any_local_order( $id ) {
		if ( $id === self::$broken ) {
			throw new TypeError( 'broken order data' );
		}
		return null;
	}
	public static function cancelled_by( $value ) {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return in_array( $value, array( 'buyer', 'shop', 'sudemi' ), true ) ? $value : '';
	}
}

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

$a = '0f6c1c3e-1111-4222-8333-444455556666';   // accepted on the portal: booking it needs sudemi, which is down
$b = '7a1d0000-1111-4222-8333-444455556666';   // the local lookup throws an Error
$c = '9b2e0000-1111-4222-8333-444455556666';   // applies fine (nothing to do here)
$ok_detail = function ( $id, $status ) {
	return array( 'ok' => true, 'status' => 200, 'error' => '', 'message' => '', 'body' => array(
		'order_id'  => $id,
		'status'    => $status,
		'revision'  => 3,
		'placed_at' => '2001-01-01T00:00:00Z', // too old to be booked here: applying it does nothing
	) );
};

// Pure: the retry list.
$l = Sudemi_Changes::retry_add( array(), $a, 1000, 'unreachable', 'SHOP' );
expect( 'retry_add: new order, one try, first seen now', array( $l[ $a ]['attempts'], $l[ $a ]['first'], $l[ $a ]['why'], $l[ $a ]['cancelled_by'] ), array( 1, 1000, 'unreachable', 'shop' ) );
$l = Sudemi_Changes::retry_add( $l, $a, 2000, 'HTTP 503' );
expect( 'retry_add: again: tries counted, first kept, cancelled_by kept', array( $l[ $a ]['attempts'], $l[ $a ]['first'], $l[ $a ]['last'], $l[ $a ]['why'], $l[ $a ]['cancelled_by'] ), array( 2, 1000, 2000, 'HTTP 503', 'shop' ) );
expect( 'retry_add: an unknown cancelled_by is not kept', isset( Sudemi_Changes::retry_add( array(), $a, 1, 'x', 'robot' )[ $a ]['cancelled_by'] ), false );
expect( 'retry_expired: fresh', Sudemi_Changes::retry_expired( array( 'attempts' => 3, 'first' => 1000 ), 1000 + 3600 ), false );
expect( 'retry_expired: 3 days on', Sudemi_Changes::retry_expired( array( 'attempts' => 3, 'first' => 1000 ), 1000 + 3 * 86400 ), true );
expect( 'retry_expired: too many tries', Sudemi_Changes::retry_expired( array( 'attempts' => Sudemi_Changes::RETRY_MAX_ATTEMPTS, 'first' => 1000 ), 1001 ), true );

// A beat whose feed has an order that cannot be booked (sudemi down) and one whose data breaks.
Sudemi_Orders::$broken = $b;
$more = Sudemi_Changes::handle_beat( array(
	'changed_orders' => array(
		array( 'order_id' => $a, 'status' => 'ACCEPTED', 'revision' => 2 ),
		array( 'order_id' => $b, 'status' => 'DECLINED', 'revision' => 4, 'cancelled_by' => 'sudemi' ),
		array( 'order_id' => $c, 'status' => 'AWAITING_SELLER', 'revision' => 1 ),
		'not an entry',
	),
	'changes_cursor' => 'c1.next',
	'changes_more'   => true,
) );
expect( 'beat: the cursor moves on although two orders failed', Sudemi_Changes::cursor(), 'c1.next' );
expect( 'beat: changes_more still answered', $more, true );
$l = Sudemi_Changes::retry_list();
expect( 'beat: both failed orders are on the retry list, the applied one is not', array( isset( $l[ $a ] ), isset( $l[ $b ] ), isset( $l[ $c ] ) ), array( true, true, false ) );
expect( 'beat: an Error (Throwable) is caught and named', $l[ $b ]['why'], 'broken order data' );
expect( 'beat: the decline\'s cancelled_by is kept for the note', $l[ $b ]['cancelled_by'], 'sudemi' );
expect( 'beat: each failure is logged with the order', Sudemi_Logger::has( 'warn', $a ) && Sudemi_Logger::has( 'warn', $b ), true );

// Next beat: sudemi still down for A; B now reads fine and applies.
Sudemi_Orders::$broken = '';
Sudemi_Manage::$answers[ $b ] = array( $ok_detail( $b, 'DECLINED' ) );
Sudemi_Changes::retry_queued();
$l = Sudemi_Changes::retry_list();
expect( 'retry: B read from sudemi again and applied: off the list', isset( $l[ $b ] ), false );
expect( 'retry: A still down: kept, one more try', array( isset( $l[ $a ] ), $l[ $a ]['attempts'] ), array( true, 2 ) );

// A: sudemi no longer has it for this shop: off the list, said loudly.
Sudemi_Manage::$answers[ $a ] = array( array( 'ok' => false, 'status' => 404, 'body' => array(), 'error' => 'no_such_order', 'message' => '' ) );
Sudemi_Changes::retry_queued();
expect( 'retry: an order sudemi no longer has is dropped with an error in the log', array( isset( Sudemi_Changes::retry_list()[ $a ] ), Sudemi_Logger::has( 'error', $a ) ), array( false, true ) );

// An order that keeps failing for 3 days is given up on, with an error naming it.
Sudemi_Logger::$lines = array();
update_option( Sudemi_Changes::OPTION_RETRY, array( $a => array( 'attempts' => 7, 'first' => time() - 3 * 86400 - 5, 'last' => time() - 60, 'why' => 'unreachable' ) ) );
unset( Sudemi_Manage::$answers[ $a ] );
Sudemi_Changes::retry_queued();
expect( 'retry: given up after 3 days: off the list, error in the log with the tries', array( Sudemi_Changes::retry_list(), Sudemi_Logger::has( 'error', 'Gave up applying sudemi order ' . $a . ' here after 8 tries' ) ), array( array(), true ) );

// At most RETRY_PER_BEAT orders are asked about per beat.
$many = array();
for ( $i = 0; $i < 15; $i++ ) {
	$many[ sprintf( '%08x-1111-4222-8333-444455556666', $i ) ] = array( 'attempts' => 1, 'first' => time(), 'last' => time(), 'why' => 'unreachable' );
}
update_option( Sudemi_Changes::OPTION_RETRY, $many );
Sudemi_Manage::$gets = 0;
Sudemi_Changes::retry_queued();
expect( 'retry: at most 10 orders asked per beat, the rest kept', array( Sudemi_Manage::$gets, count( Sudemi_Changes::retry_list() ) ), array( 10, 15 ) );

// An empty feed still keeps sudemi's cursor.
Sudemi_Changes::handle_beat( array( 'changed_orders' => array(), 'changes_cursor' => 'c1.x' ) );
expect( 'empty feed: cursor kept', Sudemi_Changes::cursor(), 'c1.x' );

// locked(): runs without $wpdb; with it, only when GET_LOCK answers 1, and the lock is released.
expect( 'locked: no $wpdb (tests): runs', Sudemi_Changes::locked( 'refund_1', function () {
	return 'ran';
} ), 'ran' );
final class Fake_Wpdb {
	public $prefix = 'wp_';
	public $free   = true;
	public $sql    = array();
	public function prepare( $q, ...$args ) {
		return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $q ), $args );
	}
	public function get_var( $q ) {
		$this->sql[] = $q;
		return $this->free ? '1' : '0';
	}
	public function query( $q ) {
		$this->sql[] = $q;
		return 1;
	}
}
$GLOBALS['wpdb'] = new Fake_Wpdb();
$ran            = Sudemi_Changes::locked( 'refund_42', function () {
	return 'ran';
} );
expect( 'locked: lock had: runs, then released', array( $ran, $GLOBALS['wpdb']->sql ), array( 'ran', array( "SELECT GET_LOCK('sudemi_wp_refund_42', 10)", "SELECT RELEASE_LOCK('sudemi_wp_refund_42')" ) ) );
$GLOBALS['wpdb']->free = false;
$GLOBALS['wpdb']->sql  = array();
$ran                   = Sudemi_Changes::locked( 'refund_42', function () {
	return 'ran';
} );
expect( 'locked: held by another request: not run, nothing released', array( $ran, count( $GLOBALS['wpdb']->sql ) ), array( null, 1 ) );
$GLOBALS['wpdb']->free = true;
$GLOBALS['wpdb']->sql  = array();
try {
	Sudemi_Changes::locked( 'refund_42', function () {
		throw new RuntimeException( 'boom' );
	} );
} catch ( RuntimeException $e ) {
	// expected
}
expect( 'locked: released when $fn throws', end( $GLOBALS['wpdb']->sql ), "SELECT RELEASE_LOCK('sudemi_wp_refund_42')" );

echo $failures ? "\n$failures FAILED\n" : "\nALL PASS\n";
exit( $failures ? 1 : 0 );
