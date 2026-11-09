<?php
/**
 * The Sudemi-Site header and sudemi's 409 site_mismatch answer.
 *
 * A staging copy or a restored backup holds the live shop's install id and key. Every signed call
 * therefore says which site it comes from (Sudemi-Site, outside the signature), and sudemi refuses
 * a call from any other site than the connected shop with 409 site_mismatch. This proves the
 * header is sent on signed calls only, the signature is unchanged by it, and the heartbeat turns
 * the refusal into "not connected" with a message that names the connected address.
 *
 * Runs without WordPress: the HTTP call is replaced by a recorder.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'SUDEMI_VERSION', 'test' );

$options   = array( 'sudemi_install_id' => 'inst-1', 'sudemi_api_base' => 'https://api.example.test' );
$sent      = array();
$reply     = array( 'code' => 200, 'body' => '{}' );
$home      = 'https://staging.shop.example/';

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
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}
function home_url( $path = '' ) {
	global $home;
	return $home;
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function wp_remote_request( $url, $args ) {
	global $sent, $reply;
	$sent[] = array( 'url' => $url, 'args' => $args );
	return $reply;
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['code'];
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}
function wp_remote_retrieve_header( $response, $name ) {
	return '';
}
function wp_count_posts( $type ) {
	return (object) array( 'publish' => 3 );
}
class WP_Error {
	private $code;
	public function __construct( $code = '', $message = '' ) {
		$this->code = $code;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->code;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// The collaborators, reduced to what the client and the heartbeat touch.
class Sudemi_Keys {
	public static $signed = array();
	public static function has_key() {
		return true;
	}
	public static function sign( $canonical ) {
		self::$signed[] = $canonical;
		return 'sig';
	}
}
class Sudemi_Logger {
	public static function info( $message ) {}
	public static function warn( $message ) {}
}
class Sudemi_Queue {
	public static function drain() {
		return 0;
	}
	public static function count() {
		return 0;
	}
}
class Sudemi_Requests {
	public static $replaced = array();
	public static function replace( $list ) {
		self::$replaced[] = $list;
	}
}
class Sudemi_Orders {
	public static function reconcile( array $handled ) {}
}
class Sudemi_Order_Calls {
	public static function drain() {}
}
// The change feed (4d): the beat carries the cursor; with more waiting, more beats follow.
class Sudemi_Changes {
	const MAX_EXTRA_BEATS = 5;
	public static $handled = array();
	public static $more    = 0;
	public static $net     = 0;
	public static function cursor() {
		return 'c1.abc';
	}
	public static function handle_beat( array $body ) {
		self::$handled[] = $body;
		if ( ! isset( $body['changed_orders'] ) ) {
			return null;
		}
		return self::$more-- > 0;
	}
	public static function safety_net() {
		++self::$net;
	}
	public static $retried = 0;
	public static function retry_queued() {
		++self::$retried;
	}
}
$wpdb = new class() {
	public $posts = 'wp_posts';
	public function get_row( $sql ) {
		return (object) array( 'n' => 3, 'newest' => '2026-11-07 00:00:00' );
	}
};

require __DIR__ . '/../includes/class-sudemi-client.php';
require __DIR__ . '/../includes/class-sudemi-heartbeat.php';

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

// ---- the header ----
Sudemi_Client::post_signed( '/integration/v1/products', array( 'products' => array() ) );
$headers = end( $sent )['args']['headers'];
check( 'a signed POST carries Sudemi-Site', isset( $headers['Sudemi-Site'] ) && 'https://staging.shop.example/' === $headers['Sudemi-Site'] );
check( 'next to the four signing headers', isset( $headers['Sudemi-Install'], $headers['Sudemi-Timestamp'], $headers['Sudemi-Nonce'], $headers['Sudemi-Signature'] ) );
$canonical = end( Sudemi_Keys::$signed );
check( 'the signed string is still five lines', 5 === count( explode( "\n", $canonical ) ) );
check( 'the site is not part of the signed string', false === strpos( $canonical, 'staging.shop.example' ) );

Sudemi_Client::get_signed( '/integration/v1/orders/x' );
check( 'a signed GET carries Sudemi-Site', isset( end( $sent )['args']['headers']['Sudemi-Site'] ) );

Sudemi_Client::send_signed( 'POST', '/integration/v1/support/tickets', array( 'subject' => 'x' ) );
check( 'a one-shot signed call carries Sudemi-Site', isset( end( $sent )['args']['headers']['Sudemi-Site'] ) );

Sudemi_Client::post_unsigned( '/integration/v1/pairing', array( 'shopUrl' => 'x' ) );
check( 'an unsigned call (pairing) does not', ! isset( end( $sent )['args']['headers']['Sudemi-Site'] ) );

$home = "https://shop.example/\r\nX-Evil: 1";
Sudemi_Client::post_signed( '/integration/v1/products', array() );
check( 'line breaks never reach the header', false === strpbrk( end( $sent )['args']['headers']['Sudemi-Site'], "\r\n" ) );
$home = 'https://staging.shop.example/';

// ---- reading the refusal ----
$message = 'This shop is connected to sudemi as https://shop.example.com, but this request came from https://staging.example.com. Requests from other sites (a staging copy, a restored backup) are refused. If this is a copy, disconnect sudemi here. If the shop moved, connect it again.';
$refusal = array( 'status' => 409, 'body' => array( 'error' => 'site_mismatch', 'message' => $message ) );
check( '409 site_mismatch names the connected address', 'https://shop.example.com' === Sudemi_Client::site_mismatch( $refusal ) );
check( 'with a port', 'https://shop.example.com:8443' === Sudemi_Client::site_mismatch( array( 'status' => 409, 'body' => array( 'error' => 'site_mismatch', 'message' => 'This shop is connected to sudemi as https://shop.example.com:8443, but' ) ) ) );
check( 'an unreadable message still counts, without an address', '' === Sudemi_Client::site_mismatch( array( 'status' => 409, 'body' => array( 'error' => 'site_mismatch' ) ) ) );
check( 'another 409 is not a site mismatch', null === Sudemi_Client::site_mismatch( array( 'status' => 409, 'body' => array( 'error' => 'conflict' ) ) ) );
check( 'a 200 is not a site mismatch', null === Sudemi_Client::site_mismatch( array( 'status' => 200, 'body' => array() ) ) );
check( 'a transport error is not a site mismatch', null === Sudemi_Client::site_mismatch( new WP_Error( 'http_request_failed' ) ) );

// ---- the heartbeat ----
$reply = array( 'code' => 409, 'body' => json_encode( $refusal['body'] ) );
Sudemi_Heartbeat::run();
$status = Sudemi_Heartbeat::status();
check( 'heartbeat: the beat carried Sudemi-Site', isset( end( $sent )['args']['headers']['Sudemi-Site'] ) );
check( 'heartbeat: reachable but not connected', true === $status['reachable'] && false === $status['connected'] );
check( 'heartbeat: the problem is recorded', 'site_mismatch' === $status['problem'] );
check( 'heartbeat: the message names the connected address', false !== strpos( $status['message'], '(https://shop.example.com)' ) );
check( 'heartbeat: the message says to disconnect a copy', false !== strpos( $status['message'], 'disconnect sudemi here' ) );

$reply = array( 'code' => 409, 'body' => '{"error":"site_mismatch"}' );
Sudemi_Heartbeat::run();
$status = Sudemi_Heartbeat::status();
check( 'heartbeat: without an address the message has none', false === $status['connected'] && false === strpos( $status['message'], '(' ) );

$reply = array( 'code' => 401, 'body' => '{"error":"not_authenticated"}' );
Sudemi_Heartbeat::run();
$status = Sudemi_Heartbeat::status();
check( 'heartbeat: a 401 is still "not recognised", not a site mismatch', false === $status['connected'] && ! isset( $status['problem'] ) );

$reply = array( 'code' => 200, 'body' => '{"catalogue_listed":true}' );
Sudemi_Heartbeat::run();
$status = Sudemi_Heartbeat::status();
check( 'heartbeat: the next good beat clears it', true === $status['connected'] && ! isset( $status['problem'] ) );
check( 'heartbeat: a good beat without open_requests empties the stored list', array( null ) === Sudemi_Requests::$replaced );

$reply = array( 'code' => 200, 'body' => '{"catalogue_listed":true,"open_requests":[{"order_id":"x"}]}' );
Sudemi_Heartbeat::run();
check( 'heartbeat: open_requests are handed on as they came', array( array( 'order_id' => 'x' ) ) === end( Sudemi_Requests::$replaced ) );

// ---- the change feed ----
$before = count( $sent );
Sudemi_Changes::$handled = array();
$reply = array( 'code' => 200, 'body' => '{"catalogue_listed":true}' );
Sudemi_Heartbeat::run();
$beat = json_decode( $sent[ count( $sent ) - 1 ]['args']['body'], true );
check( 'heartbeat: the beat carries the change cursor', 'c1.abc' === $beat['changesCursor'] && 'test' === $beat['pluginVersion'] );
check( 'heartbeat: an answer without changed_orders (older core): one beat, no safety net', 1 === count( $sent ) - $before && 1 === count( Sudemi_Changes::$handled ) && 0 === Sudemi_Changes::$net );
$before = count( $sent );
Sudemi_Changes::$more = 2;
$reply = array( 'code' => 200, 'body' => '{"catalogue_listed":true,"changed_orders":[],"changes_cursor":"c1.n","changes_more":true}' );
Sudemi_Heartbeat::run();
check( 'heartbeat: changes_more asks again right away until no more are waiting', 3 === count( $sent ) - $before && 3 === count( Sudemi_Changes::$handled ) - 1 && 1 === Sudemi_Changes::$net );
check( 'heartbeat: the retry list is worked through once per run (with the feed), not per extra beat', 1 === Sudemi_Changes::$retried );
$before = count( $sent );
Sudemi_Changes::$more = 50;
Sudemi_Heartbeat::run();
check( 'heartbeat: at most 5 extra beats in one run', 6 === count( $sent ) - $before );

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
