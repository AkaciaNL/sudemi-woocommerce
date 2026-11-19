<?php
/**
 * 1.4.4: texts the buyer types (a name printed on a shirt). The field definitions of the add-on
 * plugins shops use are read from their stored data (WooCommerce Product Add-Ons, Advanced
 * Product Fields, PPOM): only text fields, with their price, length and whether required; what
 * sudemi sends back on an order line is written under the field's name; a required text that
 * did not come is named. YITH and ThemeHigh: their stored formats (from their source) and the
 * order item meta they write themselves.
 *
 * Runs without WordPress.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.NamingConventions.PrefixAllGlobals,WordPress.PHP.DevelopmentFunctions -- a command-line test (plain text output, test doubles of WordPress functions); left out of the release zip.

require __DIR__ . '/../includes/class-sudemi-addons.php';
require __DIR__ . '/../includes/class-sudemi-personalisation.php';

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

// WooCommerce Product Add-Ons (3.x format): a priced, required text; a choice add-on is not a text.
$pao = array(
	array(
		'name'         => 'Custom name',
		'type'         => 'custom_text',
		'required'     => 1,
		'adjust_price' => 1,
		'price_type'   => 'flat_fee',
		'price'        => '20',
		'max'          => '12',
		'options'      => array(),
	),
	array(
		'name'    => 'Gift wrap',
		'type'    => 'multiple_choice',
		'options' => array( array( 'label' => 'Yes', 'price' => '3' ) ),
	),
	array(
		'name'         => 'Note',
		'type'         => 'custom_textarea',
		'required'     => 0,
		'adjust_price' => 1,
		'price_type'   => 'percentage_based',
		'price'        => '10',
	),
);
$f   = Sudemi_Addons::from_pao( $pao );
expect( 'Product Add-Ons: two text fields, the choice left out', count( $f ), 2 );
expect( 'Product Add-Ons: label', $f[0]['label'], 'Custom name' );
expect( 'Product Add-Ons: required', $f[0]['required'], true );
expect( 'Product Add-Ons: price 20', $f[0]['price'], 20.0 );
expect( 'Product Add-Ons: longest 12', $f[0]['max'], 12 );
expect( 'Product Add-Ons: stable key', $f[0]['key'], 'pao:p:custom-name-0' );
expect( 'Product Add-Ons: percentage price kept as percent', array( $f[1]['price'], $f[1]['percent'] ), array( 0.0, 10.0 ) );
expect( 'Product Add-Ons: a global group has its own keys', Sudemi_Addons::from_pao( $pao, 'g9' )[0]['key'], 'pao:g9:custom-name-0' );
// Before 3.0: type "custom", the price on the one option.
$old = Sudemi_Addons::from_pao( array( array( 'name' => 'Engraving', 'type' => 'custom', 'required' => 1, 'options' => array( array( 'label' => '', 'price' => '5.5', 'max' => '20' ) ) ) ) );
expect( 'Product Add-Ons before 3.0: price and length from the option', array( $old[0]['price'], $old[0]['max'] ), array( 5.5, 20 ) );
expect( 'Product Add-Ons: nothing stored', Sudemi_Addons::from_pao( '' ), array() );

// Advanced Product Fields.
$w = Sudemi_Addons::from_wapf(
	array(
		'fields' => array(
			array( 'id' => '5f1a', 'label' => 'Name on shirt', 'type' => 'text', 'required' => true, 'pricing' => array( 'enabled' => true, 'type' => 'fixed', 'amount' => 20 ), 'attributes' => array( 'maxlength' => 15 ) ),
			array( 'id' => '5f1b', 'label' => 'Size guide', 'type' => 'select' ),
		),
	)
);
expect( 'Advanced Product Fields: the text field only', count( $w ), 1 );
expect( 'Advanced Product Fields: fields', array( $w[0]['key'], $w[0]['label'], $w[0]['required'], $w[0]['max'], $w[0]['price'] ), array( 'wapf:5f1a', 'Name on shirt', true, 15, 20.0 ) );

// PPOM (JSON).
$p = Sudemi_Addons::from_ppom( '[{"type":"text","data_name":"club_name","title":"Club name","required":"on","max_length":"18","price":"7.5"},{"type":"select","title":"Font"},{"type":"text","title":"Off","status":"off"}]' );
expect( 'PPOM: the active text field only', count( $p ), 1 );
expect( 'PPOM: fields', array( $p[0]['key'], $p[0]['label'], $p[0]['required'], $p[0]['max'], $p[0]['price'] ), array( 'ppom:club-name', 'Club name', true, 18, 7.5 ) );
expect( 'PPOM: broken JSON', Sudemi_Addons::from_ppom( '{' ), array() );

// The listing's fields as sudemi takes them.
$field = Sudemi_Personalisation::field( 'text', 'Name to print', '', true, 0 );
expect( 'own setting: key, required, default longest 40, price 0', $field, array( 'key' => 'text', 'label' => 'Name to print', 'required' => true, 'maxLength' => 40, 'priceMinor' => 0 ) );
expect( 'longest capped', Sudemi_Personalisation::max_length( 100000 ), Sudemi_Personalisation::LIMIT_MAX );

// An order line from sudemi.
$typed = Sudemi_Personalisation::from_line(
	array(
		'personalisation' => array(
			array( 'key' => 'pao:p:custom-name-0', 'label' => 'Custom name', 'value' => "AJAX\nAMSTERDAM" ),
			array( 'key' => 'note', 'label' => 'Note', 'value' => '   ' ),
		),
	)
);
expect( 'typed: control characters become spaces, empty left out', $typed, array( 'pao:p:custom-name-0' => array( 'label' => 'Custom name', 'value' => 'AJAX AMSTERDAM' ) ) );
$fields = array( Sudemi_Personalisation::field( 'pao:p:custom-name-0', 'Custom name', 12, true, 2000 ) );
$euro   = function ( $minor ) {
	return 'EUR ' . number_format( $minor / 100, 2, '.', '' );
};
expect( 'order item meta under the field name', Sudemi_Addons::order_meta( $fields, $typed, $euro ), array( array( 'Custom name', 'AJAX AMSTERDAM' ) ) );
expect( 'nothing missing', Sudemi_Personalisation::missing( $fields, $typed ), null );
expect( 'a required text missing is named', Sudemi_Personalisation::missing( $fields, array() ), 'Custom name' );
expect( 'no personalisation on the line', Sudemi_Personalisation::from_line( array() ), array() );

// YITH WooCommerce Product Add-ons 4.x (fixture in its stored format: settings and options as
// serialized in yith_wapo_addons; options are one list per property, one text input per index).
$settings = serialize( array( 'type' => 'text', 'title' => 'Shirt print' ) );
$options  = serialize(
	array(
		'label'                => array( 'Name on the back', 'Number', 'Per letter' ),
		'required'             => array( 'yes', 'no', 'no' ),
		'price_method'         => array( 'increase', 'free', 'increase' ),
		'price_type'           => array( 'fixed', 'fixed', 'characters' ),
		'price'                => array( '20', '', '1' ),
		'price_sale'           => array( '', '', '' ),
		'characters_limit'     => array( 'yes', 'no', 'no' ),
		'characters_limit_max' => array( '18', '', '' ),
	)
);
$y = Sudemi_Addons::from_yith( 31, Sudemi_Addons::unserialize_plain( $settings ), Sudemi_Addons::unserialize_plain( $options ) );
expect( 'YITH: two text inputs; the per-character priced one left out', count( $y ), 2 );
expect( 'YITH: first input', array( $y[0]['key'], $y[0]['label'], $y[0]['required'], $y[0]['max'], $y[0]['price'] ), array( 'yith:31-0', 'Name on the back', true, 18, 20.0 ) );
expect( 'YITH: free input', array( $y[1]['key'], $y[1]['required'], $y[1]['price'] ), array( 'yith:31-1', false, 0.0 ) );
expect( 'YITH: a sale price wins', Sudemi_Addons::from_yith( 5, array( 'type' => 'text' ), array( 'label' => array( 'Name' ), 'price_method' => array( 'increase' ), 'price' => array( '20' ), 'price_sale' => array( '15' ) ) )[0]['price'], 15.0 );
expect( 'YITH: a select add-on is not a text', Sudemi_Addons::from_yith( 6, array( 'type' => 'select' ), array( 'label' => array( 'Red' ) ) ), array() );
expect( 'YITH: objects in its data are refused', Sudemi_Addons::unserialize_plain( 'O:8:"stdClass":0:{}' ), array() );
$yf  = array( Sudemi_Personalisation::field( 'yith:31-0', 'Name on the back', 18, true, 2000 ) + array( 'store' => $y[0]['store'] ) );
$ym  = Sudemi_Addons::order_meta( $yf, array( 'yith:31-0' => array( 'label' => 'Name on the back', 'value' => 'AJAX AMSTERDAM' ) ), $euro );
expect( 'YITH order line: visible ywapo-addon-<addon>-<option> with the price, as YITH writes it', $ym[0], array( 'ywapo-addon-31-0', 'AJAX AMSTERDAM (+EUR 20.00)' ) );
expect( 'YITH order line: _ywapo_meta_data as YITH reads it (label, value, ids)', $ym[1], array( '_ywapo_meta_data', array( array( '31-0' => array( 0 => 'AJAX AMSTERDAM', 'addon_id' => '31', 'option_id' => '0', 'addon_value' => 'AJAX AMSTERDAM', 'display_label' => 'Name on the back', 'display_value' => 'AJAX AMSTERDAM (+EUR 20.00)' ) ) ) ) );

// ThemeHigh Extra Product Options 3.x (fixture: the properties of its field objects).
$t = Sudemi_Addons::from_thwepof(
	array(
		'club_name' => array( 'type' => 'inputtext', 'name' => 'club_name', 'title' => 'Club name', 'required' => 1, 'enabled' => 1, 'maxlength' => '16' ),
		'notes'     => array( 'type' => 'textarea', 'name' => 'notes', 'title' => '', 'required' => false, 'enabled' => true, 'maxlength' => '' ),
		'size_pick' => array( 'type' => 'select', 'name' => 'size_pick', 'title' => 'Size', 'enabled' => 1 ),
		'off'       => array( 'type' => 'inputtext', 'name' => 'off', 'title' => 'Off', 'enabled' => 0 ),
	)
);
expect( 'ThemeHigh: the two enabled text fields', count( $t ), 2 );
expect( 'ThemeHigh: fields', array( $t[0]['key'], $t[0]['label'], $t[0]['required'], $t[0]['max'], $t[0]['price'], $t[1]['label'] ), array( 'thwepof:club-name', 'Club name', true, 16, 0.0, 'notes' ) );
$tf = array( Sudemi_Personalisation::field( 'thwepof:club-name', 'Club name', 16, true, 0 ) + array( 'store' => $t[0]['store'] ) );
expect( 'ThemeHigh order line: the field name with the text, as ThemeHigh writes it', Sudemi_Addons::order_meta( $tf, array( 'thwepof:club-name' => array( 'label' => 'Club name', 'value' => 'AJAX' ) ), $euro ), array( array( 'club_name', 'AJAX' ) ) );
expect( 'a text whose field is gone: under the label sudemi sent', Sudemi_Addons::order_meta( array(), array( 'x' => array( 'label' => 'Old', 'value' => 'v' ) ), $euro ), array( array( 'Old', 'v' ) ) );

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
