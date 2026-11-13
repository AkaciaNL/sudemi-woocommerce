<?php
/**
 * Support tickets: validation, the locale sent, the paths and bodies of the signed calls, and how
 * sudemi's answers (400/404/429, transport errors) are turned into something a shop owner can read.
 *
 * Runs without WordPress: the HTTP call is replaced by a recording transport.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.NamingConventions.PrefixAllGlobals,WordPress.PHP.DevelopmentFunctions -- a command-line test (plain text output, test doubles of WordPress functions); left out of the release zip.

// The few WordPress functions the class touches.
function __( $text, $domain = 'default' ) {
	return $text;
}
function _x( $text, $context, $domain = 'default' ) {
	return $text;
}
class WP_Error {
	private $code;
	public function __construct( $code = '', $message = '' ) {
		$this->code = $code;
	}
	public function get_error_code() {
		return $this->code;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

require __DIR__ . '/../includes/class-sudemi-support.php';

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

$uuid = '3f2b8c1e-9a4d-4e5f-8b7a-1c2d3e4f5a6b';

// ---- locale ----
check( 'nl_NL -> nl', 'nl' === Sudemi_Support::locale_for( 'nl_NL' ) );
check( 'he_IL -> he', 'he' === Sudemi_Support::locale_for( 'he_IL' ) );
check( 'zh_CN -> zh', 'zh' === Sudemi_Support::locale_for( 'zh_CN' ) );
check( 'ar -> ar', 'ar' === Sudemi_Support::locale_for( 'ar' ) );
check( 'en_US -> en', 'en' === Sudemi_Support::locale_for( 'en_US' ) );
check( 'ja (not offered) -> none', null === Sudemi_Support::locale_for( 'ja' ) );
check( 'empty -> none', null === Sudemi_Support::locale_for( '' ) );

// ---- building a ticket ----
check( 'seven categories', 7 === count( Sudemi_Support::CATEGORIES ) );
$ok = Sudemi_Support::build_ticket( 'payouts', '  Payout missing ', " Where is it?\n", '', 'de_DE' );
check( 'valid ticket builds a body', isset( $ok['body'] ) );
check( 'category upper-cased, subject and message trimmed',
	isset( $ok['body'] ) && 'PAYOUTS' === $ok['body']['category'] && 'Payout missing' === $ok['body']['subject'] && 'Where is it?' === $ok['body']['message'] );
check( 'locale sent as two letters', isset( $ok['body']['locale'] ) && 'de' === $ok['body']['locale'] );
check( 'no order id: field omitted', isset( $ok['body'] ) && ! array_key_exists( 'orderId', $ok['body'] ) );
$with_order = Sudemi_Support::build_ticket( 'ORDER', 'Late order', 'Hi', strtoupper( $uuid ), 'ja' );
check( 'order id sent lower-case', isset( $with_order['body']['orderId'] ) && $uuid === $with_order['body']['orderId'] );
check( 'unsupported locale: field omitted', isset( $with_order['body'] ) && ! array_key_exists( 'locale', $with_order['body'] ) );
check( 'unknown category refused', array( 'error' => 'invalid_category' ) === Sudemi_Support::build_ticket( 'BILLING', 'Subject', 'Hi', '', null ) );
check( 'subject of 2 characters refused', array( 'error' => 'invalid_subject' ) === Sudemi_Support::build_ticket( 'OTHER', ' ab ', 'Hi', '', null ) );
check( 'subject of 201 characters refused', array( 'error' => 'invalid_subject' ) === Sudemi_Support::build_ticket( 'OTHER', str_repeat( 'x', 201 ), 'Hi', '', null ) );
check( 'subject of 200 multibyte characters accepted', isset( Sudemi_Support::build_ticket( 'OTHER', str_repeat( 'ü', 200 ), 'Hi', '', null )['body'] ) );
check( 'empty message refused', array( 'error' => 'invalid_message' ) === Sudemi_Support::build_ticket( 'OTHER', 'Subject', "  \n ", '', null ) );
check( 'message over 10000 characters refused', array( 'error' => 'invalid_message' ) === Sudemi_Support::build_ticket( 'OTHER', 'Subject', str_repeat( 'x', 10001 ), '', null ) );
check( 'malformed order id refused', array( 'error' => 'invalid_order' ) === Sudemi_Support::build_ticket( 'ORDER', 'Subject', 'Hi', '3f2b8c1e', null ) );

// ---- calls ----
$calls  = array();
$answer = null;
Sudemi_Support::set_transport(
	function ( $method, $path, $body ) use ( &$calls, &$answer ) {
		$calls[] = array( $method, $path, $body );
		return $answer;
	}
);

$detail = array( 'id' => $uuid, 'number' => 'S-000001', 'status' => 'OPEN', 'messages' => array() );

$answer = array( 'status' => 200, 'body' => array( 'tickets' => array( $detail ) ) );
$list   = Sudemi_Support::list_tickets();
check( 'list: GET /integration/v1/support/tickets', array( 'GET', '/integration/v1/support/tickets', array() ) === end( $calls ) );
check( 'list: tickets returned', $list['ok'] && 1 === count( $list['tickets'] ) );

$answer = array( 'status' => 201, 'body' => array( 'ticket' => $detail ) );
$opened = Sudemi_Support::open_ticket( 'SHOP_PLUGIN', 'Sync stalls', 'Since yesterday', '', 'tr_TR' );
$last   = end( $calls );
check( 'open: POST to the tickets path', 'POST' === $last[0] && '/integration/v1/support/tickets' === $last[1] );
check( 'open: body carries category, subject, message, locale',
	array( 'category' => 'SHOP_PLUGIN', 'subject' => 'Sync stalls', 'message' => 'Since yesterday', 'locale' => 'tr' ) === $last[2] );
check( 'open: 201 returns the ticket', $opened['ok'] && $uuid === $opened['ticket']['id'] );

$before  = count( $calls );
$refused = Sudemi_Support::open_ticket( 'OTHER', 'x', 'Hi' );
check( 'open: invalid input never reaches sudemi', $before === count( $calls ) && 'invalid_subject' === $refused['error'] );

$answer = array( 'status' => 200, 'body' => array( 'ticket' => $detail ) );
Sudemi_Support::get_ticket( $uuid );
check( 'view: GET /integration/v1/support/tickets/{id}', array( 'GET', '/integration/v1/support/tickets/' . $uuid, array() ) === end( $calls ) );

$before = count( $calls );
$bad    = Sudemi_Support::get_ticket( '../orders' );
check( 'view: a non-UUID id is not sent anywhere', $before === count( $calls ) && 'not_found' === $bad['error'] );

$replied = Sudemi_Support::reply( $uuid, " Thanks!\n" );
check( 'reply: POST .../{id}/messages with the trimmed message',
	array( 'POST', '/integration/v1/support/tickets/' . $uuid . '/messages', array( 'message' => 'Thanks!' ) ) === end( $calls ) && $replied['ok'] );

$before = count( $calls );
$empty  = Sudemi_Support::reply( $uuid, '   ' );
check( 'reply: an empty reply is not sent', $before === count( $calls ) && 'invalid_message' === $empty['error'] );

// ---- answers that are not success ----
$cases = array(
	400 => array( 'error' => 'invalid_request', 'message' => 'orderId is not an order of this shop' ),
	404 => array( 'error' => 'not_found' ),
	429 => array( 'error' => 'too_many_tickets', 'message' => 'slow down' ),
	500 => array(),
	403 => array(),
	409 => array( 'error' => 'site_mismatch', 'message' => 'This shop is connected to sudemi as https://shop.example.com, but ...' ),
);
$expect = array( 400 => 'invalid_request', 404 => 'not_found', 429 => 'too_many_tickets', 500 => 'unavailable', 403 => 'refused', 409 => 'refused' );
foreach ( $cases as $status => $body ) {
	$answer = array( 'status' => $status, 'body' => $body );
	$result = Sudemi_Support::open_ticket( 'OTHER', 'Subject', 'Hi' );
	check( "HTTP $status -> {$expect[$status]}", ! $result['ok'] && $expect[ $status ] === $result['error'] );
	check( "HTTP $status has a message of its own", '' !== Sudemi_Support::error_message( $result['error'] ) );
}
$answer = new WP_Error( 'sudemi_not_connected', 'x' );
check( 'not connected is reported as such', 'not_connected' === Sudemi_Support::list_tickets()['error'] );
$answer = new WP_Error( 'http_request_failed', 'timeout' );
check( 'a network failure is "unavailable"', 'unavailable' === Sudemi_Support::list_tickets()['error'] );
$answer = array( 'status' => 200, 'body' => array() );
check( 'a 200 without a ticket is not treated as success', ! Sudemi_Support::get_ticket( $uuid )['ok'] );

// ---- labels ----
foreach ( Sudemi_Support::CATEGORIES as $category ) {
	check( "category $category has a label", $category !== Sudemi_Support::category_label( $category ) );
}
foreach ( array( 'OPEN', 'WAITING_FOR_CUSTOMER', 'ANSWERED', 'CLOSED' ) as $status ) {
	check( "status $status has a label", $status !== Sudemi_Support::status_label( $status ) );
}

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
