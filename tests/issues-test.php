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

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
