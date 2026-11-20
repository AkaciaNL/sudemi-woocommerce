<?php
/**
 * 1.4.4 (owner 2026-11-19: "we need to know what we are failing"): what the plugin left out is
 * sent to sudemi as the push's top-level `issues` [{externalId, code, severity, field, message,
 * details}] and kept with sudemi's answer for the shop's page.
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
$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d;
}
function update_option( $k, $v, $a = null ) {
	$GLOBALS['opts'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['opts'][ $k ] );
	return true;
}
function is_wp_error( $x ) {
	return false;
}
class Sudemi_Logger {
	public static function warn( $m ) {}
}
class Sudemi_Client {
	public static $calls = array();
	public static $status = 200;
	public static function post_signed( $path, $body ) {
		self::$calls[] = array( $path, $body );
		return array( 'status' => self::$status, 'body' => array() );
	}
}
require __DIR__ . '/../includes/class-sudemi-addons.php';
require __DIR__ . '/../includes/class-sudemi-issues.php';

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

$i = Sudemi_Issues::make( 42, Sudemi_Issues::ADDON_FLAT_FEE, 'warning', 'Gift box', '"Gift box" is not offered: flat fee', array( 'plugin' => 'pao' ) );
expect( 'the wire shape uses the contract names', array_keys( $i ), array( 'externalId', 'code', 'severity', 'field', 'message', 'details' ) );
expect( 'externalId is a string', $i['externalId'], '42' );
expect( 'details is a JSON object', json_encode( $i['details'] ), '{"plugin":"pao"}' );
expect( 'an unknown severity becomes warning', Sudemi_Issues::make( 1, 'x', 'fatal', '', '' )['severity'], 'warning' );
expect( 'empty details is {} not []', json_encode( Sudemi_Issues::make( 1, 'x', 'error', '', '' )['details'] ), '{}' );

expect( 'reasons map to codes: discount', Sudemi_Issues::code_for_reason( Sudemi_Addons::REASON_DISCOUNT ), 'addon_negative_price' );
expect( 'reasons map to codes: flat fee', Sudemi_Issues::code_for_reason( Sudemi_Addons::REASON_FLAT ), 'addon_flat_fee' );
expect( 'reasons map to codes: per character / formula', Sudemi_Issues::code_for_reason( Sudemi_Addons::REASON_PRICING ), 'addon_per_character_price' );
expect( 'reasons map to codes: ThemeComplete', Sudemi_Issues::code_for_reason( Sudemi_Addons::REASON_PLUGIN ), 'addon_unsupported_plugin' );
expect( 'reasons map to codes: an unknown reason', Sudemi_Issues::code_for_reason( 'whatever' ), 'unreadable_addon' );

Sudemi_Issues::add( 7, 'addon_flat_fee', 'warning', 'Box', 'm' );
Sudemi_Issues::add( 7, 'addon_flat_fee', 'warning', 'Box', 'm again' );
Sudemi_Issues::add( 8, 'too_many_required_texts', 'error', 'personalisation', 'Not listed' );
$taken = Sudemi_Issues::take();
expect( 'the same issue twice is sent once', count( $taken ), 2 );
expect( 'taken once: nothing pending afterwards', Sudemi_Issues::take(), array() );

$body = Sudemi_Issues::attach( array( 'products' => array( array( 'externalId' => '7' ) ) ), $taken );
expect( 'the push carries issues at the top level', isset( $body['issues'] ) && 2 === count( $body['issues'] ), true );
expect( 'no issues: no field (the push is as before)', array_key_exists( 'issues', Sudemi_Issues::attach( array( 'products' => array() ), array() ) ), false );
expect( 'JSON of an issue', json_encode( $body['issues'][1] ), '{"externalId":"8","code":"too_many_required_texts","severity":"error","field":"personalisation","message":"Not listed","details":{}}' );

$kept = Sudemi_Issues::merge( array(), array( array( 'externalId' => '7' ) ), $taken, array( 'rejected' => array( array( 'externalId' => '9', 'reason' => 'no price' ) ), 'issues' => array( array( 'externalId' => '7', 'code' => 'image_not_on_shop', 'severity' => 'warning', 'message' => 'x' ) ) ), 100 );
expect( 'kept: the plugin\'s two and sudemi\'s two', count( $kept ), 4 );
expect( 'kept: sudemi\'s refusal as an error with its reason', array( $kept[2]['source'], $kept[2]['code'], $kept[2]['severity'], $kept[2]['message'] ), array( 'sudemi', 'rejected', 'error', 'no price' ) );
$kept = Sudemi_Issues::merge( $kept, array( array( 'externalId' => '7' ) ), array(), array(), 200 );
expect( 'a later push of product 7 without issues clears its entries, others stay', array_map( function ( $k ) { return $k['externalId']; }, $kept ), array( '8', '9' ) );
expect( 'plain words for every code', Sudemi_Issues::label( 'too_many_required_texts' ) !== Sudemi_Issues::label( 'something_new' ), true );

// Issues of a product not listed: queued, no request while saving; sent once by drain().
Sudemi_Issues::queue( array( Sudemi_Issues::make( 11, 'addon_flat_fee', 'error', 'Setup', 'Not listed' ) ) );
Sudemi_Issues::queue( array( Sudemi_Issues::make( 12, 'missing_price', 'error', 'price', 'No price' ), Sudemi_Issues::make( 12, 'variant_cap', 'warning', 'variants', 'cap' ) ) );
Sudemi_Issues::queue( array( Sudemi_Issues::make( 11, 'addon_negative_price', 'error', 'Setup', 'newer' ) ) );
expect( 'queue: no request while saving', count( Sudemi_Client::$calls ), 0 );
expect( 'queue: newest issues per product, shown on the page at once', array( count( Sudemi_Issues::flat( Sudemi_Issues::queued() ) ), count( Sudemi_Issues::all() ) ), array( 3, 3 ) );
expect( 'queued with no room: nothing', Sudemi_Issues::queued( 0 ), array() );
expect( 'queued: whole products up to the limit', array_map( 'strval', array_keys( Sudemi_Issues::queued( 2 ) ) ), array( '12' ) );
Sudemi_Client::$status = 503;
expect( 'drain on failure: one request, kept', array( Sudemi_Issues::drain(), count( Sudemi_Client::$calls ), count( Sudemi_Issues::flat( Sudemi_Issues::queued() ) ) ), array( false, 1, 3 ) );
Sudemi_Client::$status = 200;
expect( 'drain: one request with every waiting issue and no products', array( Sudemi_Issues::drain(), count( Sudemi_Client::$calls[1][1]['issues'] ), Sudemi_Client::$calls[1][1]['products'] ), array( true, 3, array() ) );
expect( 'drain: nothing left, nothing more sent', array( Sudemi_Issues::queued(), Sudemi_Issues::drain(), count( Sudemi_Client::$calls ) ), array( array(), false, 2 ) );
Sudemi_Issues::queue( array( Sudemi_Issues::make( 13, 'missing_price', 'error', 'price', 'No price' ) ) );
Sudemi_Issues::unqueue( array( '13' ) );
expect( 'unqueue: a newer push of the product drops what waited', Sudemi_Issues::queued(), array() );
Sudemi_Issues::queue( array( Sudemi_Issues::make( 14, 'missing_price', 'error', 'price', 'x' ) ) );
Sudemi_Client::$status = 422;
Sudemi_Issues::drain();
expect( 'drain refused for good: dropped (a full sync sends them again)', Sudemi_Issues::queued(), array() );

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
