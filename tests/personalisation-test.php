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
		'price_type'   => 'quantity_based',
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
expect( 'Product Add-Ons: two texts and the multiple choice', count( $f ), 3 );
expect( 'Product Add-Ons: the multiple choice is a choice with its priced option', array( $f[1]['type'], $f[1]['label'], $f[1]['choices'] ), array( 'choice', 'Gift wrap', array( array( 'key' => 'o0', 'label' => 'Yes', 'price' => 3.0, 'percent' => 0.0 ) ) ) );
$f = array( $f[0], $f[2] );
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
			array( 'id' => '5f1a', 'label' => 'Name on shirt', 'type' => 'text', 'required' => true, 'pricing' => array( 'enabled' => true, 'type' => 'qt', 'amount' => 20 ), 'attributes' => array( 'maxlength' => 15 ) ),
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
expect( 'typed: control characters become spaces, empty left out', $typed, array( 'pao:p:custom-name-0' => array( 'label' => 'Custom name', 'value' => 'AJAX AMSTERDAM', 'type' => 'text', 'choice_keys' => array() ) ) );
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
expect( 'YITH: three text inputs, the per-character priced one marked not offerable (never free)', array( count( $y ), $y[0]['unofferable'], $y[1]['unofferable'], $y[2]['unofferable'] ), array( 3, null, null, Sudemi_Addons::REASON_PRICING ) );
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

// Choices (contract v2): every plugin's select / radio / checkbox options, each with its price,
// and written on the order line as that plugin writes a chosen option.
$pao_multi = Sudemi_Addons::from_pao( array( array( 'name' => 'Extras', 'type' => 'checkbox', 'required' => 0, 'options' => array( array( 'label' => 'Gift wrap', 'price' => '3', 'price_type' => 'quantity_based' ), array( 'label' => 'Card', 'price' => '1.5', 'price_type' => 'quantity_based' ) ) ) ) );
expect( 'Product Add-Ons: checkboxes are a multi_choice', array( $pao_multi[0]['type'], count( $pao_multi[0]['choices'] ), $pao_multi[0]['choices'][1]['price'] ), array( 'multi_choice', 2, 1.5 ) );
$paof   = array( Sudemi_Personalisation::field( $pao_multi[0]['key'], 'Extras', 0, false, 0 ) + array( 'type' => 'multi_choice', 'choices' => array( array( 'key' => 'o0', 'label' => 'Gift wrap', 'priceMinor' => 300 ), array( 'key' => 'o1', 'label' => 'Card', 'priceMinor' => 150 ) ), 'store' => array( 'kind' => 'label' ) ) );
$pao_ty = Sudemi_Personalisation::from_line( array( 'personalisation' => array( array( 'key' => $pao_multi[0]['key'], 'label' => 'Extras', 'value' => 'Gift wrap, Card', 'type' => 'multi_choice', 'choice_keys' => array( 'o0', 'o1' ) ) ) ) );
expect( 'Product Add-Ons order line: one entry per chosen option under the add-on name', Sudemi_Addons::order_meta( $paof, $pao_ty, $euro ), array( array( 'Extras', 'Gift wrap' ), array( 'Extras', 'Card' ) ) );

$wc = Sudemi_Addons::from_wapf( array( 'fields' => array(
	array( 'id' => 'a1', 'label' => 'Colour', 'type' => 'radio', 'required' => 'true', 'choices' => array(
		array( 'slug' => 'red', 'label' => 'Red', 'selected' => 'false', 'pricing_type' => 'none', 'pricing_amount' => 0 ),
		array( 'slug' => 'gold', 'label' => 'Gold', 'selected' => 'false', 'pricing_type' => 'qt', 'pricing_amount' => 4 ),
	) ),
	array( 'id' => 'a2', 'label' => 'Note', 'type' => 'text', 'required' => 'false', 'pricing' => array( 'enabled' => 'false', 'amount' => '0', 'type' => 'fixed' ) ),
	array( 'id' => 'a3', 'label' => 'Engrave', 'type' => 'text', 'required' => 'false', 'pricing' => array( 'enabled' => 'true', 'amount' => '2', 'type' => 'char' ) ),
) ) );
expect( 'Advanced Product Fields: the stored "false" is not required; a per-character price makes that field not offerable', array( count( $wc ), $wc[0]['required'], $wc[1]['required'], $wc[2]['unofferable'] ), array( 3, true, false, Sudemi_Addons::REASON_PRICING ) );
expect( 'Advanced Product Fields: radio is a choice with slugs as keys', array( $wc[0]['type'], $wc[0]['choices'][1]['key'], $wc[0]['choices'][1]['price'] ), array( 'choice', 'gold', 4.0 ) );
$wf = array( Sudemi_Personalisation::field( 'wapf:a1', 'Colour', 0, true, 0 ) + array( 'type' => 'choice', 'choices' => array( array( 'key' => 'red', 'label' => 'Red', 'priceMinor' => 0 ), array( 'key' => 'gold', 'label' => 'Gold', 'priceMinor' => 400 ) ), 'store' => array( 'kind' => 'wapf', 'id' => 'a1' ) ) );
$wt = Sudemi_Personalisation::from_line( array( 'personalisation' => array( array( 'key' => 'wapf:a1', 'label' => 'Colour', 'value' => 'Gold', 'type' => 'choice', 'choice_key' => 'gold' ) ) ) );
expect( 'Advanced Product Fields order line: label and value, and _wapf_meta as APF writes it', Sudemi_Addons::order_meta( $wf, $wt, $euro ), array( array( 'Colour', 'Gold' ), array( '_wapf_meta', array( 'a1' => array( 'id' => 'a1', 'label' => 'Colour', 'value' => 'Gold', 'raw' => 'gold' ) ) ) ) );

$pc = Sudemi_Addons::from_ppom( '[{"type":"select","data_name":"font","title":"Font","required":"on","options":[{"option":"Serif","price":"","id":"serif"},{"option":"Gothic","price":"10%","id":"gothic"}]},{"type":"checkbox","data_name":"extras","title":"Extras","options":[{"option":"Box","price":"2"}]}]' );
expect( 'PPOM: select is a choice, a "10%" price a percentage; checkbox a multi_choice', array( $pc[0]['type'], $pc[0]['choices'][1]['percent'], $pc[1]['type'], $pc[1]['choices'][0]['key'] ), array( 'choice', 10.0, 'multi_choice', 'box' ) );
$pf = array( Sudemi_Personalisation::field( 'ppom:extras', 'Extras', 0, false, 0 ) + array( 'type' => 'multi_choice', 'choices' => array( array( 'key' => 'box', 'label' => 'Box', 'priceMinor' => 200 ) ), 'store' => array( 'kind' => 'ppom', 'name' => 'extras' ) ) );
$pt = Sudemi_Personalisation::from_line( array( 'personalisation' => array( array( 'key' => 'ppom:extras', 'label' => 'Extras', 'value' => 'Box', 'type' => 'multi_choice', 'choice_keys' => array( 'box' ) ) ) ) );
expect( 'PPOM order line: data name with the value, and _ppom_fields as PPOM writes it', Sudemi_Addons::order_meta( $pf, $pt, $euro ), array( array( 'extras', 'Box' ), array( '_ppom_fields', array( 'fields' => array( 'extras' => array( 'Box' ) ) ) ) ) );

$yc = Sudemi_Addons::from_yith( 40, array( 'type' => 'select', 'title' => 'Gift wrap', 'required' => 'yes' ), array( 'label' => array( 'None', 'Paper', 'Box' ), 'price_method' => array( 'free', 'increase', 'increase' ), 'price' => array( '', '2', '5' ), 'price_type' => array( 'fixed', 'fixed', 'fixed' ) ) );
expect( 'YITH: a select add-on is one choice field, options as choices with their price', array( $yc[0]['key'], $yc[0]['type'], $yc[0]['required'], count( $yc[0]['choices'] ), $yc[0]['choices'][2]['price'] ), array( 'yith:40', 'choice', true, 3, 5.0 ) );
$ycf = array( Sudemi_Personalisation::field( 'yith:40', 'Gift wrap', 0, true, 0 ) + array( 'type' => 'choice', 'choices' => array( array( 'key' => '2', 'label' => 'Box', 'priceMinor' => 500 ) ), 'store' => $yc[0]['store'] ) );
$yct = Sudemi_Personalisation::from_line( array( 'personalisation' => array( array( 'key' => 'yith:40', 'label' => 'Gift wrap', 'value' => 'Box', 'type' => 'choice', 'choice_key' => '2' ) ) ) );
$ycm = Sudemi_Addons::order_meta( $ycf, $yct, $euro );
expect( 'YITH order line: the chosen option as ywapo-addon-<addon>-<option index> with its price', $ycm[0], array( 'ywapo-addon-40-2', 'Box (+EUR 5.00)' ) );

$tc = Sudemi_Addons::from_thwepof( array( 'wrap' => array( 'type' => 'checkboxgroup', 'name' => 'wrap', 'title' => 'Wrapping', 'enabled' => 1, 'options' => array( 'Paper', 'Ribbon' ) ) ) );
expect( 'ThemeHigh: a checkbox group is a multi_choice of its options (no prices in the free version)', array( $tc[0]['type'], $tc[0]['choices'][1]['label'], $tc[0]['choices'][1]['price'] ), array( 'multi_choice', 'Ribbon', 0.0 ) );
$tcf = array( Sudemi_Personalisation::field( $tc[0]['key'], 'Wrapping', 0, false, 0 ) + array( 'type' => 'multi_choice', 'choices' => array( array( 'key' => 'paper', 'label' => 'Paper', 'priceMinor' => 0 ), array( 'key' => 'ribbon', 'label' => 'Ribbon', 'priceMinor' => 0 ) ), 'store' => $tc[0]['store'] ) );
$tct = Sudemi_Personalisation::from_line( array( 'personalisation' => array( array( 'key' => $tc[0]['key'], 'label' => 'Wrapping', 'value' => 'Paper, Ribbon', 'type' => 'multi_choice', 'choice_keys' => array( 'paper', 'ribbon' ) ) ) ) );
expect( 'ThemeHigh order line: the field name with the options joined by "," as ThemeHigh writes them', Sudemi_Addons::order_meta( $tcf, $tct, $euro ), array( array( 'wrap', 'Paper,Ribbon' ) ) );
expect( 'choice keys never hold a comma; repeated labels are dropped', count( Sudemi_Addons::from_pao( array( array( 'name' => 'X', 'type' => 'multiple_choice', 'options' => array( array( 'label' => 'A' ), array( 'label' => 'a' ) ) ) ) )[0]['choices'] ), 1 );

// Review 2026-11-19: a discount add-on is never made free (the buyer would overpay) nor charged
// in full: it is not offered, with the reason; a required one blocks the listing.
$neg = Sudemi_Addons::from_pao( array( array( 'name' => 'Loyalty discount', 'type' => 'custom_text', 'required' => 0, 'adjust_price' => 1, 'price_type' => 'flat_fee', 'price' => '-5' ) ) );
expect( 'Product Add-Ons: a negative price is kept as it is and marked not offerable', array( $neg[0]['price'], $neg[0]['unofferable'] ), array( -5.0, Sudemi_Addons::REASON_DISCOUNT ) );
$negc = Sudemi_Addons::from_pao( array( array( 'name' => 'Pick-up', 'type' => 'multiple_choice', 'options' => array( array( 'label' => 'In store', 'price' => '-2' ), array( 'label' => 'Courier', 'price' => '0' ) ) ) ) );
expect( 'Product Add-Ons: a choice with a negative price makes the field not offerable', $negc[0]['unofferable'], Sudemi_Addons::REASON_DISCOUNT );
$yd = Sudemi_Addons::from_yith( 50, array( 'type' => 'text', 'title' => 'T' ), array( 'label' => array( 'Coupon text' ), 'price_method' => array( 'decrease' ), 'price' => array( '3' ), 'required' => array( 'yes' ) ) );
expect( 'YITH: a "decrease" is a negative price (a discount), not offerable, never 0', array( $yd[0]['price'], $yd[0]['unofferable'], $yd[0]['required'] ), array( -3.0, Sudemi_Addons::REASON_DISCOUNT, true ) );

// The five-text cap keeps required ones first; more than five required ones block the listing.
$mk  = function ( $n, $req ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$out[] = Sudemi_Personalisation::field( 'k' . $i, 'Line ' . $i, 10, $req, 0 );
	}
	return $out;
};
$cap = Sudemi_Personalisation::cap( array_merge( $mk( 5, false ), array( Sudemi_Personalisation::field( 'r', 'Required sixth', 10, true, 0 ) ) ) );
expect( 'cap: a required sixth is kept (first), an optional one left out instead', array( count( $cap['fields'] ), $cap['fields'][0]['key'], $cap['left_out'], $cap['blocked'] ), array( 5, 'r', array( 'Line 5' ), null ) );
$cap = Sudemi_Personalisation::cap( $mk( 6, true ) );
expect( 'cap: six required texts block the listing (not offered at all)', array( $cap['fields'], null !== $cap['blocked'] ), array( array(), true ) );

// Review 2026-11-19 (MONEY): a price charged once per order line (a flat fee) sent as a price
// per item would overcharge a buyer of two: not offered, with the reason; a required one blocks.
$flat = Sudemi_Addons::from_pao( array(
	array( 'name' => 'Setup', 'type' => 'custom_text', 'required' => 1, 'adjust_price' => 1, 'price_type' => 'flat_fee', 'price' => '5' ),
	array( 'name' => 'Box', 'type' => 'multiple_choice', 'options' => array( array( 'label' => 'Gift box', 'price' => '4', 'price_type' => 'flat_fee' ) ) ),
	array( 'name' => 'Free note', 'type' => 'custom_text', 'adjust_price' => 1, 'price_type' => 'flat_fee', 'price' => '0' ),
) );
expect( 'Product Add-Ons: a flat fee text and a flat fee choice are not offered; a free one (0) is', array( $flat[0]['unofferable'], $flat[1]['unofferable'], $flat[2]['unofferable'] ), array( Sudemi_Addons::REASON_FLAT, Sudemi_Addons::REASON_FLAT, null ) );
$wflat = Sudemi_Addons::from_wapf( array( 'fields' => array(
	array( 'id' => 'f1', 'label' => 'Engraving', 'type' => 'text', 'required' => 'false', 'pricing' => array( 'enabled' => 'true', 'amount' => '6', 'type' => 'fixed' ) ),
	array( 'id' => 'f2', 'label' => 'Wrap', 'type' => 'radio', 'choices' => array( array( 'slug' => 'paper', 'label' => 'Paper', 'selected' => 'false', 'pricing_type' => 'fixed', 'pricing_amount' => 2 ) ) ),
) ) );
expect( 'Advanced Product Fields: "fixed" (flat fee, not quantity-based) is not offered, text and choice', array( $wflat[0]['unofferable'], $wflat[1]['unofferable'] ), array( Sudemi_Addons::REASON_FLAT, Sudemi_Addons::REASON_FLAT ) );
$pflat = Sudemi_Addons::from_ppom( '[{"type":"text","data_name":"logo","title":"Logo text","price":"8","onetime":"on"},{"type":"select","data_name":"setup","title":"Setup","onetime":"on","options":[{"option":"Yes","price":"3"}]},{"type":"text","data_name":"note","title":"Note","price":"2"}]' );
expect( 'PPOM: a "fixed fee" (onetime) field is not offered, text and choice; a per-item price is', array( $pflat[0]['unofferable'], $pflat[1]['unofferable'], $pflat[2]['unofferable'] ), array( Sudemi_Addons::REASON_FLAT, Sudemi_Addons::REASON_FLAT, null ) );
$yflat = Sudemi_Addons::from_yith( 60, array( 'type' => 'checkbox', 'title' => 'Setup', 'sell_individually' => 'yes' ), array( 'label' => array( 'Yes' ), 'price_method' => array( 'increase' ), 'price' => array( '9' ), 'price_type' => array( 'fixed' ) ) );
expect( 'YITH: "sell individually" with a price is charged once per line: not offered', $yflat[0]['unofferable'], Sudemi_Addons::REASON_FLAT );
$capf = Sudemi_Personalisation::cap( array() );
expect( 'cap of nothing: nothing blocked', $capf['blocked'], null );

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
