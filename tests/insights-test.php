<?php
/**
 * 1.4.0: statistics, the shop's products on sudemi and the shop profile (protocol 4e): periods
 * and signed paths, the products filters (only known values reach sudemi), the reasons in words,
 * the profile checks, and the inline chart (escaped, no outside resources).
 *
 * Runs without WordPress.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

function __( $text, $domain = 'default' ) {
	return $text;
}
function _n( $single, $plural, $n, $domain = 'default' ) {
	return 1 === $n ? $single : $plural;
}
class WP_Error {
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

require __DIR__ . '/../includes/class-sudemi-currency.php';
require __DIR__ . '/../includes/class-sudemi-support.php';
require __DIR__ . '/../includes/class-sudemi-manage.php';
require __DIR__ . '/../includes/class-sudemi-insights.php';

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

// Periods.
expect( 'period 7 kept', Sudemi_Insights::period( '7' ), 7 );
expect( 'period 90 kept', Sudemi_Insights::period( 90 ), 90 );
expect( 'unknown period: 30', Sudemi_Insights::period( '365' ), 30 );
expect( 'garbage period: 30', Sudemi_Insights::period( '7; drop' ), 30 );

// Stats path: inclusive UTC days, across a month end.
expect( 'stats path, 7 days to 2026-11-13', Sudemi_Insights::stats_path( 7, '2026-11-13' ), '/integration/v1/stats?from=2026-11-07&to=2026-11-13' );
expect( 'stats path, 30 days over a month end', Sudemi_Insights::stats_path( 30, '2026-03-02' ), '/integration/v1/stats?from=2026-02-01&to=2026-03-02' );
expect( 'stats path, unknown period is 30 days', Sudemi_Insights::stats_path( 5, '2026-11-13' ), '/integration/v1/stats?from=2026-10-15&to=2026-11-13' );

// Products filters and path.
$f = Sudemi_Insights::listing_filters( array( 'status' => 'NOT_LISTED', 'q' => " blue mug\x01 ", 'paged' => '2' ) );
expect( 'status lower-cased and kept', $f['status'], 'not_listed' );
expect( 'search trimmed, control characters gone', $f['q'], 'blue mug' );
expect( 'page kept', $f['page'], 2 );
expect( 'unknown status: all', Sudemi_Insights::listing_filters( array( 'status' => 'listed OR 1=1' ) )['status'], 'all' );
expect( 'negative page: 0', Sudemi_Insights::listing_filters( array( 'paged' => '-3' ) )['page'], 0 );
expect( 'search capped at 100 characters', strlen( Sudemi_Insights::listing_filters( array( 'q' => str_repeat( 'x', 300 ) ) )['q'] ), 100 );
expect(
	'listings path, RFC 3986, q only when set',
	Sudemi_Insights::listings_path( $f ),
	'/integration/v1/listings?status=not_listed&page=2&size=25&q=blue%20mug'
);
expect(
	'listings path without a search',
	Sudemi_Insights::listings_path( Sudemi_Insights::listing_filters( array() ) ),
	'/integration/v1/listings?status=all&page=0&size=25'
);
expect( 'size capped at 200', strpos( Sudemi_Insights::listings_path( Sudemi_Insights::listing_filters( array() ), 5000 ), 'size=200' ) !== false, true );

// External ids: this plugin's are product or variation ids.
expect( 'numeric id ok', Sudemi_Insights::is_external_id( '27' ), true );
expect( 'path traversal refused', Sudemi_Insights::is_external_id( '../shop' ), false );
expect( 'empty refused', Sudemi_Insights::is_external_id( '' ), false );
expect( 'enabled path', Sudemi_Insights::enabled_path( '27' ), '/integration/v1/listings/27/enabled' );
$read = Sudemi_Insights::set_enabled( '1/../2', true );
expect( 'a bad id is refused here, nothing sent', $read['error'], 'not_found' );

// Reasons in words: every contract reason has its own sentence, unknown ones say "not listed".
foreach ( array( 'disabled_by_shop', 'not_in_shop', 'out_of_stock', 'no_shipping', 'suspended', 'payouts_not_ready', 'shop_not_reporting' ) as $code ) {
	expect( "reason $code has its own words", Sudemi_Insights::reason_label( $code ) !== 'Not listed', true );
}
expect( 'unknown reason: not listed', Sudemi_Insights::reason_label( 'something_new' ), 'Not listed' );

// Numbers.
expect( 'conversion 643 bps is 6.4%', Sudemi_Insights::percent( 643 ), '6.4%' );
expect( 'money in the currency\'s own decimals', Sudemi_Insights::money( 24550, 'EUR' ), '245.50 EUR' );
expect( 'money in a currency without decimals', Sudemi_Insights::money( 1500, 'JPY' ), '1,500 JPY' );
expect( 'missing money: dash', Sudemi_Insights::money( null, 'EUR' ), '-' );
expect( 'nice axis top for 7', Sudemi_Insights::nice_max( 7 ), 10 );
expect( 'nice axis top for 130', Sudemi_Insights::nice_max( 130 ), 200 );
expect( 'nice axis top for 0', Sudemi_Insights::nice_max( 0 ), 4 );

// Shop profile input.
$ok = Sudemi_Insights::profile_input( "  Teapots and cups.\x07 ", 'shop@example.com', '30' );
expect( 'profile ok', $ok['ok'], true );
expect( 'description trimmed, control characters gone', $ok['description'], 'Teapots and cups.' );
expect( 'return days as a number', $ok['returns_days'], 30 );
expect( 'bad e-mail refused', Sudemi_Insights::profile_input( 'x', 'not-an-address', 30 )['error'], 'invalid_email' );
expect( 'empty e-mail refused', Sudemi_Insights::profile_input( 'x', '', 30 )['error'], 'invalid_email' );
expect( 'fewer than 14 days refused (the law)', Sudemi_Insights::profile_input( 'x', 'a@b.co', 13 )['error'], 'invalid_returns_days' );
expect( 'more than a year refused', Sudemi_Insights::profile_input( 'x', 'a@b.co', 366 )['error'], 'invalid_returns_days' );
expect( 'half days refused', Sudemi_Insights::profile_input( 'x', 'a@b.co', '20.5' )['error'], 'invalid_returns_days' );
expect( 'too long description refused', Sudemi_Insights::profile_input( str_repeat( 'a', 2001 ), 'a@b.co', 14 )['error'], 'description_too_long' );
expect( 'an empty description is allowed', Sudemi_Insights::profile_input( '', 'a@b.co', 14 )['ok'], true );

// The chart: one polyline per series, the first filled, text escaped, nothing loaded.
$daily = array(
	array( 'day' => '2026-11-11', 'impressions' => 4, 'detail_views' => 1, 'sales_minor' => 0 ),
	array( 'day' => '2026-11-12', 'impressions' => 9, 'detail_views' => 3, 'sales_minor' => 3495 ),
	array( 'day' => '2026-11-13', 'impressions' => 2, 'detail_views' => 0, 'sales_minor' => 1295 ),
);
$svg = Sudemi_Insights::chart_svg(
	$daily,
	array(
		'impressions'  => array( 'class' => 'sudemi-series-a' ),
		'detail_views' => array( 'class' => 'sudemi-series-b' ),
	),
	'Views <per> day'
);
expect( 'two lines', substr_count( $svg, '<polyline' ), 2 );
expect( 'one filled area', substr_count( $svg, '<polygon' ), 1 );
expect( 'title escaped', strpos( $svg, 'Views &lt;per&gt; day' ) !== false && strpos( $svg, '<per>' ) === false, true );
expect( 'nothing loaded from outside', preg_match( '/href|src=|url\(/', $svg ), 0 );
expect( 'the peak touches the top of the axis (9 of 10)', strpos( $svg, '10' ) !== false, true );
$bars = Sudemi_Insights::bars_svg( $daily, 'sales_minor', 'Sales' );
expect( 'a bar per day with sales, none for 0', substr_count( $bars, '<rect' ), 2 );
expect( 'an empty period draws an empty chart', substr_count( Sudemi_Insights::chart_svg( array(), array( 'impressions' => array() ), 'x' ), '<polyline' ), 0 );
expect( 'rows that are not arrays are skipped', substr_count( Sudemi_Insights::bars_svg( array( 'x', array( 'day' => '2026-11-13', 'sales_minor' => 5 ) ), 'sales_minor', 'S' ), '<rect' ), 1 );

// 1.4.1: the checkout mode banner (docs/seller-modes.md) from GET /integration/v1/shop.
$b = Sudemi_Insights::checkout_banner( array( 'checkout_mode' => 'SHOP', 'checkout_mode_reason' => 'stripe_requirements_due', 'stripe_connect_url' => 'https://sudemi.com/seller/payouts' ) );
expect( 'mode B with reason and connect address', $b, array( 'mode' => 'SHOP', 'reason' => 'stripe_requirements_due', 'url' => 'https://sudemi.com/seller/payouts' ) );
expect( 'mode A, no reason', Sudemi_Insights::checkout_banner( array( 'checkout_mode' => 'SUDEMI', 'checkout_mode_reason' => null, 'stripe_connect_url' => 'https://sudemi.com/seller/payouts' ) )['reason'], '' );
expect( 'suspended', Sudemi_Insights::checkout_banner( array( 'checkout_mode' => 'SUSPENDED', 'checkout_mode_reason' => 'stripe_restricted' ) )['mode'], 'SUSPENDED' );
expect( 'an older sudemi without the fields: no banner', Sudemi_Insights::checkout_banner( array( 'shop_name' => 'x' ) ), null );
expect( 'an unknown mode: no banner', Sudemi_Insights::checkout_banner( array( 'checkout_mode' => 'MAYBE' ) ), null );
expect( 'not an answer: no banner', Sudemi_Insights::checkout_banner( 'oops' ), null );
expect( 'a mode that is not a string: no banner', Sudemi_Insights::checkout_banner( array( 'checkout_mode' => array( 'SHOP' ) ) ), null );
expect( 'an unknown reason is dropped', Sudemi_Insights::checkout_banner( array( 'checkout_mode' => 'SHOP', 'checkout_mode_reason' => '<b>' ) )['reason'], '' );
expect( 'a javascript: address gives no button', Sudemi_Insights::checkout_banner( array( 'checkout_mode' => 'SHOP', 'stripe_connect_url' => 'javascript:alert(1)' ) )['url'], '' );
expect( 'a relative address gives no button', Sudemi_Insights::checkout_banner( array( 'checkout_mode' => 'SHOP', 'stripe_connect_url' => '//evil.example/x' ) )['url'], '' );
expect( 'an address with a user part gives no button', Sudemi_Insights::checkout_banner( array( 'checkout_mode' => 'SHOP', 'stripe_connect_url' => 'https://sudemi.com@evil.example/' ) )['url'], '' );
expect( 'an address with spaces gives no button', Sudemi_Insights::checkout_banner( array( 'checkout_mode' => 'SHOP', 'stripe_connect_url' => "https://sudemi.com/a b" ) )['url'], '' );
expect( 'http on a dev host is kept', Sudemi_Insights::checkout_banner( array( 'checkout_mode' => 'SHOP', 'stripe_connect_url' => 'http://localhost:18083/seller/payouts' ) )['url'], 'http://localhost:18083/seller/payouts' );

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
