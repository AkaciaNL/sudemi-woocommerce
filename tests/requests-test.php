<?php
/**
 * Return requests: what is kept from the heartbeat, the signed calls' paths and bodies, the
 * required reason on a rejection, and how sudemi's answers are read and recorded.
 *
 * Runs without WordPress: options live in an array and the signed call is recorded.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.NamingConventions.PrefixAllGlobals,WordPress.PHP.DevelopmentFunctions -- a command-line test (plain text output, test doubles of WordPress functions); left out of the release zip.

$options = array();
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
function esc_html( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES );
}
function wp_date( $format, $ts ) {
	return gmdate( 'Y-m-d H:i', $ts );
}
class WP_Error {
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
class Sudemi_Logger {
	public static function info( $message ) {}
}
class Sudemi_Orders {
	const META_ORDER_ID = '_sudemi_order_id';
}
class Sudemi_Client {
	public static $calls = array();
	public static $reply = null;
	public static function send_signed( $method, $path, array $body = array() ) {
		self::$calls[] = array( $method, $path, $body );
		return self::$reply;
	}
}

require __DIR__ . '/../includes/class-sudemi-requests.php';

$failures = 0;
function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

$a = '3f2b8c1e-9a4d-4e5f-8b7a-1c2d3e4f5a6b';
$b = '11111111-2222-4333-8444-555555555555';

// ---- what is kept from the heartbeat ----
$kept = Sudemi_Requests::normalise(
	array(
		array( 'request_id' => 'r1', 'order_id' => strtoupper( $a ), 'kind' => 'RETURN', 'reason' => 'Too <b>small</b>', 'requested_at' => '2026-11-07T10:00:00Z', 'respond_by' => '2026-11-14T10:00:00Z' ),
		array( 'request_id' => 'r2', 'order_id' => $b, 'kind' => 'RETURN', 'reason' => null, 'respond_by' => '2026-11-10T10:00:00Z' ),
		array( 'order_id' => '../../etc', 'reason' => 'x' ),
		array( 'order_id' => array( 'x' ) ),
		'not an entry',
	)
);
check( 'two well-formed requests kept, keyed by lower-case order id', array( $a, $b ) === array_keys( $kept ) );
check( 'the reason is kept as given (escaped when printed, not here)', 'Too <b>small</b>' === $kept[ $a ]['reason'] );
check( 'a missing reason becomes empty', '' === $kept[ $b ]['reason'] );
check( 'no open_requests -> nothing kept', array() === Sudemi_Requests::normalise( null ) );

// ---- the stored copy is replaced on every beat ----
function wc_get_orders( $args ) {
	return array();
}
Sudemi_Requests::replace( array( array( 'request_id' => 'r1', 'order_id' => $a, 'respond_by' => '2026-11-14T10:00:00Z' ), array( 'request_id' => 'r2', 'order_id' => $b, 'respond_by' => '2026-11-10T10:00:00Z' ) ) );
check( 'all() lists the soonest answer-by date first', array( $b, $a ) === array_keys( Sudemi_Requests::all() ) );
check( 'for_order finds one by sudemi order id', 'r1' === Sudemi_Requests::for_order( strtoupper( $a ) )['request_id'] );
Sudemi_Requests::replace( null );
check( 'a beat without open_requests empties the list', array() === Sudemi_Requests::all() );

// ---- building an answer ----
check( 'accept path', '/integration/v1/orders/' . $a . '/request/accept' === Sudemi_Requests::path_for( $a, true ) );
check( 'reject path', '/integration/v1/orders/' . $a . '/request/reject' === Sudemi_Requests::path_for( $a, false ) );
check( 'a rejection without a reason is refused locally', 'reason' === Sudemi_Requests::build_answer( $a, false, "  \n" )['error'] );
check( 'a rejection carries its trimmed reason', array( 'reason' => 'Worn' ) === Sudemi_Requests::build_answer( $a, false, ' Worn ' )['body'] );
check( 'an acceptance needs no reason', array( 'reason' => null ) === Sudemi_Requests::build_answer( $a, true, '' )['body'] );
check( 'a non-uuid order id is refused', 'order' === Sudemi_Requests::build_answer( '1 or 1=1', true, '' )['error'] );
check( 'a long reason is cut to 1000 characters', 1000 === mb_strlen( Sudemi_Requests::build_answer( $a, false, str_repeat( 'é', 1500 ) )['body']['reason'] ) );

// ---- reading sudemi's answer ----
check( '200 ok', 'ok' === Sudemi_Requests::outcome_of( array( 'status' => 200, 'body' => array( 'status' => 'ok' ) ) ) );
check( '200 already_resolved', 'already_resolved' === Sudemi_Requests::outcome_of( array( 'status' => 200, 'body' => array( 'status' => 'already_resolved' ) ) ) );
check( '404 not_found', 'not_found' === Sudemi_Requests::outcome_of( array( 'status' => 404, 'body' => array() ) ) );
check( '400 invalid', 'invalid' === Sudemi_Requests::outcome_of( array( 'status' => 400, 'body' => array( 'error' => 'invalid_request' ) ) ) );
check( '500 error', 'error' === Sudemi_Requests::outcome_of( array( 'status' => 500, 'body' => array() ) ) );
check( 'transport error', 'error' === Sudemi_Requests::outcome_of( new WP_Error( 'http', 'timeout' ) ) );

// ---- answering ----
Sudemi_Requests::replace( array( array( 'request_id' => 'r1', 'order_id' => $a, 'respond_by' => '2026-11-14T10:00:00Z' ) ) );
$r = Sudemi_Requests::answer( $a, false, '' );
check( 'a rejection without a reason sends nothing', 'invalid' === $r['outcome'] && array() === Sudemi_Client::$calls );
check( 'and the request stays listed', null !== Sudemi_Requests::for_order( $a ) );

Sudemi_Client::$reply = new WP_Error( 'http', 'timeout' );
$r = Sudemi_Requests::answer( $a, true, '' );
check( 'transport error: the request stays listed and the message says so', 'error' === $r['outcome'] && null !== Sudemi_Requests::for_order( $a ) && false !== strpos( $r['message'], 'timeout' ) );

Sudemi_Client::$calls = array();
Sudemi_Client::$reply = array( 'status' => 200, 'body' => array( 'status' => 'ok' ) );
$r = Sudemi_Requests::answer( $a, false, 'Worn and washed' );
check( 'reject sends one signed POST to the reject path with the reason',
	array( array( 'POST', '/integration/v1/orders/' . $a . '/request/reject', array( 'reason' => 'Worn and washed' ) ) ) === Sudemi_Client::$calls );
check( 'answered: removed from the list', 'ok' === $r['outcome'] && null === Sudemi_Requests::for_order( $a ) );

Sudemi_Requests::replace( array( array( 'request_id' => 'r2', 'order_id' => $b, 'respond_by' => '2026-11-14T10:00:00Z' ) ) );
Sudemi_Client::$reply = array( 'status' => 404, 'body' => array( 'error' => 'no_such_request' ) );
$r = Sudemi_Requests::answer( $b, true, '' );
check( '404: no longer open, removed from the list', 'not_found' === $r['outcome'] && null === Sudemi_Requests::for_order( $b ) );

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
