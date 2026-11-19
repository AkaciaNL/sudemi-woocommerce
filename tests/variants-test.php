<?php
/**
 * 1.4.4: variations a buyer orders exactly. A variation with an "Any ..." attribute becomes one
 * listing per value the buyer can pick, each with its own external id; a combination another
 * variation defines belongs to the one WooCommerce's storefront picks (the first that matches);
 * the key behind an external id finds the chosen values again for the order line.
 *
 * Runs without WordPress.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.NamingConventions.PrefixAllGlobals,WordPress.PHP.DevelopmentFunctions -- a command-line test (plain text output, test doubles of WordPress functions); left out of the release zip.

function wp_json_encode( $data ) {
	return json_encode( $data );
}

require __DIR__ . '/../includes/class-sudemi-variants.php';

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

// 12 variations, 3 sizes x 4 colours: every attribute set, each variation is itself.
$sizes   = array( 's', 'm', 'l' );
$colours = array( 'Red', 'Green', 'Blue', 'Black' );
$options = array(
	'pa_size' => $sizes,
	'colour'  => $colours,
);
$siblings = array();
$id       = 100;
foreach ( $sizes as $size ) {
	foreach ( $colours as $colour ) {
		$siblings[ ++$id ] = array(
			'pa_size' => $size,
			'colour'  => $colour,
		);
	}
}
expect( '12 variations', count( $siblings ), 12 );
$all = array();
foreach ( $siblings as $vid => $own ) {
	$choices = Sudemi_Variants::choices( $own, $options, $siblings, $vid );
	expect( "variation $vid is one listing, its own attributes", $choices, array( $own ) );
	expect( "variation $vid keeps its plain id", Sudemi_Variants::external_id( $vid, $own, $choices[0] ), (string) $vid );
	$all[] = Sudemi_Variants::external_id( $vid, $own, $choices[0] );
}
expect( '12 distinct external ids', count( array_unique( $all ) ), 12 );

// "Any colour": one listing per colour, each its own id; the order finds the colour again.
$any      = array(
	'pa_size' => 'm',
	'colour'  => '',
);
$siblings = array( 7 => $any );
$choices  = Sudemi_Variants::choices( $any, $options, $siblings, 7 );
expect( '"Any colour" in size M is 4 listings', count( $choices ), 4 );
expect( 'each listing has a colour filled in', array_map( fn( $c ) => $c['colour'], $choices ), $colours );
expect( 'and keeps its size', array_unique( array_map( fn( $c ) => $c['pa_size'], $choices ) ), array( 'm' ) );
$ids = array_map( fn( $c ) => Sudemi_Variants::external_id( 7, $any, $c ), $choices );
expect( 'ids are "<variation>~<key>"', count( array_filter( $ids, fn( $i ) => 1 === preg_match( '/^7~[0-9a-f]{10}$/', $i ) ) ), 4 );
expect( 'ids are distinct', count( array_unique( $ids ) ), 4 );
$parsed = Sudemi_Variants::parse( $ids[1] );
expect( 'parse gives the variation id', $parsed[0], 7 );
expect( 'the key finds Green again', Sudemi_Variants::find( $choices, $parsed[1] ), array( 'pa_size' => 'm', 'colour' => 'Green' ) );
expect( 'a key whose colour was removed finds nothing', Sudemi_Variants::find( array_slice( $choices, 0, 1 ), $parsed[1] ), null );
expect( 'key does not depend on the order of the attributes', Sudemi_Variants::key( array( 'colour' => 'Green', 'pa_size' => 'm' ) ), $parsed[1] );
expect( 'key ignores case of a typed value (as WooCommerce matches)', Sudemi_Variants::key( array( 'colour' => 'green', 'pa_size' => 'm' ) ), $parsed[1] );
expect( 'plain id parses with no key', Sudemi_Variants::parse( '27' ), array( 27, null ) );
expect( 'foreign id refused', Sudemi_Variants::parse( '27-3' ), null );
expect( 'path refused', Sudemi_Variants::parse( '../27' ), null );
expect( 'empty refused', Sudemi_Variants::parse( '' ), null );

// "Any size, any colour": 12 listings.
$both = array(
	'pa_size' => '',
	'colour'  => '',
);
expect( 'any size and any colour is 3 x 4 = 12 listings', count( Sudemi_Variants::choices( $both, $options, array( 9 => $both ), 9 ) ), 12 );

// A specific variation before the "Any" one in the shop's order wins its combination; one after it does not.
$red_m    = array(
	'pa_size' => 'm',
	'colour'  => 'Red',
);
$siblings = array(
	3 => $red_m,
	7 => $any,
);
$choices  = Sudemi_Variants::choices( $any, $options, $siblings, 7 );
expect( 'Red / M defined on its own first: "Any colour" lists the other 3', array_map( fn( $c ) => $c['colour'], $choices ), array( 'Green', 'Blue', 'Black' ) );
expect( 'Red / M itself stays one listing', Sudemi_Variants::choices( $red_m, $options, $siblings, 3 ), array( $red_m ) );
expect( 'storefront picks the first match', Sudemi_Variants::winner( $red_m, $siblings ), 3 );
$siblings = array(
	7 => $any,
	3 => $red_m,
);
expect( '"Any colour" first: it sells all 4 colours', count( Sudemi_Variants::choices( $any, $options, $siblings, 7 ) ), 4 );

// No value to pick (the parent offers none): nothing can be ordered exactly, nothing is listed.
expect( 'no colours offered: no listing', Sudemi_Variants::choices( $any, array( 'pa_size' => $sizes ), array( 7 => $any ), 7 ), array() );

// Very many values: capped.
$many = array( 'colour' => array_map( fn( $i ) => 'c' . $i, range( 1, 400 ) ) );
expect( 'capped at ' . Sudemi_Variants::MAX_PER_VARIATION, count( Sudemi_Variants::choices( array( 'colour' => '' ), $many, array( 1 => array( 'colour' => '' ) ), 1 ) ), Sudemi_Variants::MAX_PER_VARIATION );

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
