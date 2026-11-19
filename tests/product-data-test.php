<?php
/**
 * 1.4.4, contract v2: the sale and its dates (WooCommerce ends a sale at 23:59:59 of its last
 * day, sudemi's "until" is exclusive), stock status and backorders, dimensions in millimetres,
 * the quantities the shop sells in (a maximum that only follows the stock is not a rule) and
 * quantity prices.
 *
 * Runs without WordPress.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.NamingConventions.PrefixAllGlobals,WordPress.PHP.DevelopmentFunctions -- a command-line test (plain text output); left out of the release zip.

require __DIR__ . '/../includes/class-sudemi-product-data.php';

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

$now  = gmmktime( 12, 0, 0, 11, 19, 2026 );
$from = gmmktime( 0, 0, 0, 11, 20, 2026 );
$to   = gmmktime( 23, 59, 59, 11, 30, 2026 );
expect( 'a scheduled sale: regular, sale and its window (until = the second after 23:59:59)', Sudemi_Product_Data::sale_fields( 2995, 1995, $from, $to, $now ), array(
	'regularPriceMinor' => 2995,
	'salePriceMinor'    => 1995,
	'salePriceFrom'     => '2026-11-20T00:00:00Z',
	'salePriceUntil'    => '2026-12-01T00:00:00Z',
) );
expect( 'a sale without dates: running, open-ended', Sudemi_Product_Data::sale_fields( 2995, 1995, null, null, $now ), array( 'regularPriceMinor' => 2995, 'salePriceMinor' => 1995 ) );
expect( 'an ended sale is not sent', Sudemi_Product_Data::sale_fields( 2995, 1995, null, gmmktime( 23, 59, 59, 11, 18, 2026 ), $now ), array() );
expect( 'a "sale" not below the regular price is not a sale', Sudemi_Product_Data::sale_fields( 1995, 1995, null, null, $now ), array() );

expect( 'in stock, no backorders', Sudemi_Product_Data::availability_fields( 'instock', false ), array( 'allowBackorders' => false, 'availability' => 'in_stock' ) );
expect( 'on backorder', Sudemi_Product_Data::availability_fields( 'onbackorder', true ), array( 'allowBackorders' => true, 'availability' => 'backorder' ) );
expect( 'out of stock', Sudemi_Product_Data::availability_fields( 'outofstock', false ), array( 'allowBackorders' => false, 'availability' => 'out_of_stock' ) );

expect( 'dimensions in cm to mm', Sudemi_Product_Data::dimensions( '30', '20.5', '2', 'cm' ), array( 'lengthMm' => 300, 'widthMm' => 205, 'heightMm' => 20 ) );
expect( 'dimensions in inches; an empty one is not said', Sudemi_Product_Data::dimensions( '10', '', '0', 'in' ), array( 'lengthMm' => 254 ) );

expect( 'sold per 6, from 6 up to 60', Sudemi_Product_Data::quantity_fields( 6, 6, 60, null, false ), array( 'minQuantity' => 6, 'quantityStep' => 6, 'maxQuantity' => 60 ) );
expect( 'a maximum that is only the stock is not a rule', Sudemi_Product_Data::quantity_fields( 1, 1, 7, 7, false ), array() );
expect( 'sold individually: at most 1', Sudemi_Product_Data::quantity_fields( 1, 1, -1, null, true ), array( 'maxQuantity' => 1 ) );
expect( 'no rules: nothing sent', Sudemi_Product_Data::quantity_fields( 1, 1, -1, null, false ), array() );

expect( 'quantity prices: from 2 up, sorted', Sudemi_Product_Data::tier_fields( array( 10 => 1500, 1 => 2000, 5 => 1800 ) ), array( array( 'minQuantity' => 5, 'priceMinor' => 1800 ), array( 'minQuantity' => 10, 'priceMinor' => 1500 ) ) );
$many = array();
for ( $q = 2; $q < 40; $q++ ) {
	$many[ $q ] = 1000 - $q;
}
expect( 'at most 20 quantity prices', count( Sudemi_Product_Data::tier_fields( $many ) ), 20 );

expect( 'a price update carries the sale and availability (a price alone would end the sale)', Sudemi_Product_Data::price_update_fields( array( 'priceMinor' => 1995, 'regularPriceMinor' => 2995, 'salePriceMinor' => 1995, 'salePriceUntil' => '2026-12-01T00:00:00Z', 'availability' => 'in_stock', 'title' => 'x' ) ), array( 'regularPriceMinor' => 2995, 'salePriceMinor' => 1995, 'salePriceUntil' => '2026-12-01T00:00:00Z', 'availability' => 'in_stock' ) );

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
