<?php
/**
 * The sudemi demo T-shirt: one variable product with 100 variations and a text the buyer types.
 *
 *   Size        S, M, L, XL, XXL                         (same price)
 *   Colour      White +0, Black +2, Red +3, Navy +4, Green +5
 *   Material    Lycra +0, Cotton +10.00
 *   Custom name No +0, Yes +20.00 (the buyer types the name to print, e.g. a football club)
 *   Base price  19.95, so White / Lycra / No is 19.95 and Green / Cotton / Yes is 54.95.
 *
 * Idempotent: run it again and it finds the product by its SKU and puts every variation back to
 * these prices (stock is set only on variations it creates). The text the buyer types is this
 * plugin's own setting (the product's sudemi tab, the "Buyer types a text" box on the Yes
 * variations), so the shop needs no add-on plugin.
 *
 * Run inside the WordPress container:
 *   wp eval-file wp-content/plugins/sudemi-connector/tests/demo/tshirt.php ["<name>"] ["<sku>"] [<stock>]
 * Prints JSON: parent id, variation count, and variation ids keyed "S|White|Lycra|No".
 *
 * Not part of the plugin (tests/ is left out of the release zip).
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals,WordPress.Security.EscapeOutput -- a wp-cli script, left out of the release zip.

$args  = isset( $args ) && is_array( $args ) ? $args : array();
$name  = isset( $args[0] ) && '' !== $args[0] ? (string) $args[0] : 'sudemi demo T-shirt';
$sku   = isset( $args[1] ) && '' !== $args[1] ? (string) $args[1] : 'SUDEMI-DEMO-TSHIRT';
$stock = isset( $args[2] ) && is_numeric( $args[2] ) ? (int) $args[2] : 10;

$base   = 19.95;
$axes   = array(
	'Size'        => array( 'S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0, 'XXL' => 0 ),
	'Colour'      => array( 'White' => 0, 'Black' => 2, 'Red' => 3, 'Navy' => 4, 'Green' => 5 ),
	'Material'    => array( 'Lycra' => 0, 'Cotton' => 10 ),
	'Custom name' => array( 'No' => 0, 'Yes' => 20 ),
);

$id      = wc_get_product_id_by_sku( $sku );
$product = $id ? wc_get_product( $id ) : null;
if ( $product && ! $product->is_type( 'variable' ) ) {
	WP_CLI::error( "SKU $sku belongs to a product that is not variable (#$id)." );
}
if ( ! $product ) {
	$product = new WC_Product_Variable();
	$product->set_sku( $sku );
}
$product->set_name( $name );
$product->set_status( 'publish' );
$product->set_catalog_visibility( 'visible' );
$product->set_description( 'Sports T-shirt in five sizes and five colours, in Lycra or cotton. With "Custom name" we print the name you type (for example your football club) on the back.' );
$product->set_weight( '0.2' );
$attributes = array();
$position   = 0;
foreach ( $axes as $label => $values ) {
	$a = new WC_Product_Attribute();
	$a->set_name( $label );
	$a->set_options( array_keys( $values ) );
	$a->set_position( $position++ );
	$a->set_visible( true );
	$a->set_variation( true );
	$attributes[] = $a;
}
$product->set_attributes( $attributes );
$parent_id = $product->save();
update_post_meta( $parent_id, Sudemi_Personalisation::META_LABEL, 'Name to print' );
update_post_meta( $parent_id, Sudemi_Personalisation::META_MAX, 20 );
update_post_meta( $parent_id, Sudemi_Personalisation::META_REQUIRED, 'yes' );

// Existing variations by their choice.
$existing = array();
foreach ( $product->get_children() as $child_id ) {
	$child = wc_get_product( $child_id );
	if ( ! $child ) {
		continue;
	}
	$a   = $child->get_attributes();
	$key = implode( '|', array( $a['size'] ?? '', $a['colour'] ?? '', $a['material'] ?? '', $a['custom-name'] ?? '' ) );
	if ( isset( $existing[ $key ] ) ) {
		$child->delete( true ); // a duplicate of a choice
		continue;
	}
	$existing[ $key ] = $child;
}

$ids   = array();
$order = 0;
foreach ( $axes['Size'] as $size => $p1 ) {
	foreach ( $axes['Colour'] as $colour => $p2 ) {
		foreach ( $axes['Material'] as $material => $p3 ) {
			foreach ( $axes['Custom name'] as $custom => $p4 ) {
				$key       = "$size|$colour|$material|$custom";
				$variation = isset( $existing[ $key ] ) ? $existing[ $key ] : null;
				unset( $existing[ $key ] );
				if ( ! $variation ) {
					$variation = new WC_Product_Variation();
					$variation->set_parent_id( $parent_id );
					$variation->set_manage_stock( true );
					$variation->set_stock_quantity( $stock );
				}
				$variation->set_attributes(
					array(
						'size'        => $size,
						'colour'      => $colour,
						'material'    => $material,
						'custom-name' => $custom,
					)
				);
				$variation->set_regular_price( number_format( $base + $p1 + $p2 + $p3 + $p4, 2, '.', '' ) );
				$variation->set_sale_price( '' );
				$variation->set_status( 'publish' );
				$variation->set_menu_order( $order++ );
				$variation->set_sku( $sku . '-' . strtoupper( $size . '-' . $colour . '-' . $material . '-' . ( 'Yes' === $custom ? 'NAME' : 'PLAIN' ) ) );
				$vid = $variation->save();
				update_post_meta( $vid, Sudemi_Personalisation::META_VARIATION, 'Yes' === $custom ? 'yes' : 'no' );
				$ids[ $key ] = $vid;
			}
		}
	}
}
foreach ( $existing as $left ) {
	$left->delete( true ); // a choice that is no longer offered
}
WC_Product_Variable::sync( $parent_id );
wc_delete_product_transients( $parent_id );

echo wp_json_encode(
	array(
		'parent'     => $parent_id,
		'count'      => count( $ids ),
		'variations' => $ids,
	)
);
