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
expect( 'Product Add-Ons: the multiple choice is a choice with its priced option (no price type = flat fee: once per line)', array( $f[1]['type'], $f[1]['label'], $f[1]['choices'] ), array( 'choice', 'Gift wrap', array( array( 'key' => 'o0', 'label' => 'Yes', 'price' => 3.0, 'percent' => 0.0, 'mode' => 'per_line' ) ) ) );
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
expect( 'a discount per item keeps its negative price', Sudemi_Personalisation::field( 'd', 'No box', 0, false, -150 )['priceMinor'], -150 );
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
$tp = Sudemi_Addons::from_thwepof(
	array(
		'size'  => array( 'type' => 'select', 'name' => 'size', 'title' => 'Box', 'enabled' => 1, 'options' => array( 'small' => array( 'key' => 'small', 'text' => 'Small', 'price' => '2.50', 'price_type' => '' ), 'big' => array( 'key' => 'big', 'text' => 'Big', 'price' => '10', 'price_type' => 'percentage' ) ) ),
		'odd'   => array( 'type' => 'radio', 'name' => 'odd', 'title' => 'Odd', 'enabled' => 1, 'options' => array( array( 'key' => 'a', 'text' => 'A', 'price' => '3', 'price_type' => 'dynamic' ) ) ),
		'chars' => array( 'type' => 'inputtext', 'name' => 'chars', 'title' => 'Chars', 'enabled' => 1, 'price' => '0.5', 'price_type' => 'char-count' ),
		'fixed' => array( 'type' => 'inputtext', 'name' => 'fixed', 'title' => 'Fixed', 'enabled' => 1, 'price' => '4', 'price_type' => 'normal' ),
	)
);
expect( 'ThemeHigh paid: option prices read (fixed and percentage), never 0', array( $tp[0]['choices'][0]['price'], $tp[0]['choices'][1]['percent'], $tp[0]['unofferable'] ), array( 2.5, 10.0, null ) );
expect( 'ThemeHigh paid: an option price type sudemi cannot read leaves the field not offered', $tp[1]['unofferable'], Sudemi_Addons::REASON_PRICING );
expect( 'ThemeHigh paid: a per-character field price is not offered', $tp[2]['unofferable'], Sudemi_Addons::REASON_PRICING );
expect( 'ThemeHigh paid: a normal field price is read', array( $tp[3]['price'], $tp[3]['unofferable'] ), array( 4.0, null ) );
$tcf = array( Sudemi_Personalisation::field( $tc[0]['key'], 'Wrapping', 0, false, 0 ) + array( 'type' => 'multi_choice', 'choices' => array( array( 'key' => 'paper', 'label' => 'Paper', 'priceMinor' => 0 ), array( 'key' => 'ribbon', 'label' => 'Ribbon', 'priceMinor' => 0 ) ), 'store' => $tc[0]['store'] ) );
$tct = Sudemi_Personalisation::from_line( array( 'personalisation' => array( array( 'key' => $tc[0]['key'], 'label' => 'Wrapping', 'value' => 'Paper, Ribbon', 'type' => 'multi_choice', 'choice_keys' => array( 'paper', 'ribbon' ) ) ) ) );
expect( 'ThemeHigh order line: the field name with the options joined by "," as ThemeHigh writes them', Sudemi_Addons::order_meta( $tcf, $tct, $euro ), array( array( 'wrap', 'Paper,Ribbon' ) ) );
expect( 'choice keys never hold a comma; repeated labels are dropped', count( Sudemi_Addons::from_pao( array( array( 'name' => 'X', 'type' => 'multiple_choice', 'options' => array( array( 'label' => 'A' ), array( 'label' => 'a' ) ) ) ) )[0]['choices'] ), 1 );

// 1.4.10 (core 2026-11-21): a discount per item (a negative price) is offered as it is (sudemi lowers
// the unit price, never below 0); a discount charged once per order line cannot be: not offered,
// with the reason, and a required one blocks the listing.
$neg = Sudemi_Addons::from_pao( array( array( 'name' => 'Loyalty discount', 'type' => 'custom_text', 'required' => 0, 'adjust_price' => 1, 'price_type' => 'quantity_based', 'price' => '-5' ) ) );
expect( 'Product Add-Ons: a negative price per item is offered as a discount', array( $neg[0]['price'], $neg[0]['mode'], $neg[0]['unofferable'] ), array( -5.0, 'per_item', null ) );
$negc = Sudemi_Addons::from_pao( array( array( 'name' => 'Pick-up', 'type' => 'multiple_choice', 'options' => array( array( 'label' => 'In store', 'price' => '-2', 'price_type' => 'quantity_based' ), array( 'label' => 'Courier', 'price' => '0' ) ) ) ) );
expect( 'Product Add-Ons: a choice with a negative price per item is offered', array( $negc[0]['unofferable'], $negc[0]['choices'][0]['price'], $negc[0]['choices'][0]['mode'] ), array( null, -2.0, null ) );
$negp = Sudemi_Addons::from_pao( array( array( 'name' => 'Member', 'type' => 'multiple_choice', 'options' => array( array( 'label' => 'Yes', 'price' => '-10', 'price_type' => 'percentage_based' ) ) ) ) );
expect( 'Product Add-Ons: a negative percentage is a discount per item', array( $negp[0]['unofferable'], $negp[0]['choices'][0]['percent'] ), array( null, -10.0 ) );
$negl = Sudemi_Addons::from_pao( array( array( 'name' => 'Setup discount', 'type' => 'custom_text', 'required' => 0, 'adjust_price' => 1, 'price_type' => 'flat_fee', 'price' => '-5' ) ) );
expect( 'Product Add-Ons: a negative flat fee (a discount once per line) is not offered', array( $negl[0]['mode'], $negl[0]['unofferable'] ), array( 'per_line', Sudemi_Addons::REASON_DISCOUNT ) );
$neglc = Sudemi_Addons::from_pao( array( array( 'name' => 'Pick-up', 'type' => 'multiple_choice', 'options' => array( array( 'label' => 'In store', 'price' => '-2', 'price_type' => 'flat_fee' ), array( 'label' => 'Courier', 'price' => '1', 'price_type' => 'quantity_based' ) ) ) ) );
expect( 'Product Add-Ons: one choice with a negative flat fee makes the field not offered', $neglc[0]['unofferable'], Sudemi_Addons::REASON_DISCOUNT );
$yd = Sudemi_Addons::from_yith( 50, array( 'type' => 'text', 'title' => 'T' ), array( 'label' => array( 'Coupon text' ), 'price_method' => array( 'decrease' ), 'price' => array( '3' ), 'required' => array( 'yes' ) ) );
expect( 'YITH: a "decrease" is a negative price per item, offered', array( $yd[0]['price'], $yd[0]['unofferable'], $yd[0]['required'] ), array( -3.0, null, true ) );
$ydl = Sudemi_Addons::from_yith( 51, array( 'type' => 'checkbox', 'title' => 'Coupon', 'sell_individually' => 'yes' ), array( 'label' => array( 'Yes' ), 'price_method' => array( 'decrease' ), 'price' => array( '3' ), 'price_type' => array( 'fixed' ) ) );
expect( 'YITH: a "decrease" sold individually (once per line) is not offered', $ydl[0]['unofferable'], Sudemi_Addons::REASON_DISCOUNT );
$wneg = Sudemi_Addons::from_wapf( array( 'fields' => array( array( 'id' => 'n1', 'label' => 'Trade-in', 'type' => 'radio', 'choices' => array( array( 'slug' => 'old', 'label' => 'Old phone', 'pricing_type' => 'qt', 'pricing_amount' => -20 ) ) ) ) ) );
expect( 'Advanced Product Fields: a negative quantity-based price is a discount per item, offered', array( $wneg[0]['unofferable'], $wneg[0]['choices'][0]['price'] ), array( null, -20.0 ) );

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

// 1.4.10 (core 2026-11-21): a price charged once per order line (a flat fee) is sent with price mode
// per_line: sudemi charges it once for the whole line, whatever the quantity.
$flat = Sudemi_Addons::from_pao( array(
	array( 'name' => 'Setup', 'type' => 'custom_text', 'required' => 1, 'adjust_price' => 1, 'price_type' => 'flat_fee', 'price' => '5' ),
	array( 'name' => 'Box', 'type' => 'multiple_choice', 'options' => array( array( 'label' => 'Gift box', 'price' => '4', 'price_type' => 'flat_fee' ), array( 'label' => 'Card', 'price' => '1', 'price_type' => 'quantity_based' ) ) ),
	array( 'name' => 'Free note', 'type' => 'custom_text', 'adjust_price' => 1, 'price_type' => 'flat_fee', 'price' => '0' ),
) );
expect( 'Product Add-Ons: a flat fee text is offered once per line; a free one (0) stays per item', array( $flat[0]['unofferable'], $flat[0]['mode'], $flat[0]['price'], $flat[2]['unofferable'], $flat[2]['mode'] ), array( null, 'per_line', 5.0, null, 'per_item' ) );
expect( 'Product Add-Ons: per option: the flat fee option once per line, the quantity-based one per item', array( $flat[1]['unofferable'], $flat[1]['mode'], $flat[1]['choices'][0]['mode'], $flat[1]['choices'][1]['mode'] ), array( null, 'per_item', 'per_line', null ) );
$wflat = Sudemi_Addons::from_wapf( array( 'fields' => array(
	array( 'id' => 'f1', 'label' => 'Engraving', 'type' => 'text', 'required' => 'false', 'pricing' => array( 'enabled' => 'true', 'amount' => '6', 'type' => 'fixed' ) ),
	array( 'id' => 'f2', 'label' => 'Wrap', 'type' => 'radio', 'choices' => array( array( 'slug' => 'paper', 'label' => 'Paper', 'selected' => 'false', 'pricing_type' => 'fixed', 'pricing_amount' => 2 ) ) ),
) ) );
expect( 'Advanced Product Fields: "fixed" (flat fee, not quantity-based) is offered once per line, text and choice', array( $wflat[0]['unofferable'], $wflat[0]['mode'], $wflat[1]['unofferable'], $wflat[1]['choices'][0]['mode'] ), array( null, 'per_line', null, 'per_line' ) );
$pflat = Sudemi_Addons::from_ppom( '[{"type":"text","data_name":"logo","title":"Logo text","price":"8","onetime":"on"},{"type":"select","data_name":"setup","title":"Setup","onetime":"on","options":[{"option":"Yes","price":"3"}]},{"type":"text","data_name":"note","title":"Note","price":"2"}]' );
expect( 'PPOM: a "fixed fee" (onetime) field is once per line, text and choice; a plain price per item', array( $pflat[0]['mode'], $pflat[1]['mode'], $pflat[1]['choices'][0]['mode'], $pflat[2]['mode'], $pflat[0]['unofferable'], $pflat[1]['unofferable'] ), array( 'per_line', 'per_line', null, 'per_item', null, null ) );
$yflat = Sudemi_Addons::from_yith( 60, array( 'type' => 'checkbox', 'title' => 'Setup', 'sell_individually' => 'yes' ), array( 'label' => array( 'Yes' ), 'price_method' => array( 'increase' ), 'price' => array( '9' ), 'price_type' => array( 'fixed' ) ) );
expect( 'YITH: "sell individually" with a price is charged once per line', array( $yflat[0]['unofferable'], $yflat[0]['mode'] ), array( null, 'per_line' ) );
$capf = Sudemi_Personalisation::cap( array() );
expect( 'cap of nothing: nothing blocked', $capf['blocked'], null );

// Owner rule 2026-11-19: a REQUIRED add-on sudemi cannot price keeps the product off sudemi (an
// error issue); an OPTIONAL one is left out (a warning issue). Since 1.4.10 a flat fee and a
// discount per item are priced: offered, required or not.
$req_flat = Sudemi_Addons::from_pao( array( array( 'name' => 'Setup fee', 'type' => 'custom_text', 'required' => 1, 'adjust_price' => 1, 'price_type' => 'flat_fee', 'price' => '5' ) ) );
$scr      = Sudemi_Personalisation::screen( $req_flat );
expect( 'required flat fee: offered once per line, nothing blocked', array( $scr['blocked'], count( $scr['offered'] ), $scr['left_out'] ), array( null, 1, array() ) );
$req_neg = Sudemi_Addons::from_pao( array( array( 'name' => 'Club discount', 'type' => 'multiple_choice', 'required' => 1, 'options' => array( array( 'label' => 'Member', 'price' => '-3', 'price_type' => 'quantity_based' ) ) ) ) );
$scr     = Sudemi_Personalisation::screen( $req_neg );
expect( 'required discount per item: offered, nothing blocked', array( $scr['blocked'], count( $scr['offered'] ) ), array( null, 1 ) );
$req_negl = Sudemi_Addons::from_pao( array( array( 'name' => 'Club discount', 'type' => 'custom_text', 'required' => 1, 'adjust_price' => 1, 'price_type' => 'flat_fee', 'price' => '-3' ) ) );
$scr      = Sudemi_Personalisation::screen( $req_negl );
expect( 'required discount once per line: the product is blocked (not listed), the reason is the line discount', array( null !== $scr['blocked'], $scr['blocked_why'], $scr['offered'] ), array( true, Sudemi_Addons::REASON_DISCOUNT, array() ) );
$opt_mix = array_merge(
	Sudemi_Addons::from_pao( array( array( 'name' => 'Gift box', 'type' => 'multiple_choice', 'required' => 0, 'options' => array( array( 'label' => 'Box', 'price' => '-4', 'price_type' => 'flat_fee' ) ) ) ) ),
	Sudemi_Addons::from_pao( array( array( 'name' => 'Name', 'type' => 'custom_text', 'required' => 1, 'adjust_price' => 1, 'price_type' => 'quantity_based', 'price' => '2' ) ) )
);
$scr     = Sudemi_Personalisation::screen( $opt_mix );
expect( 'optional discount once per line: left out (warning), the product stays listed with the rest', array( $scr['blocked'], array_keys( $scr['left_out'] ), count( $scr['offered'] ) ), array( null, array( 'Gift box' ), 1 ) );
$req_yd = Sudemi_Addons::from_yith( 70, array( 'type' => 'text', 'title' => 'T' ), array( 'label' => array( 'Loyalty code' ), 'price_method' => array( 'decrease' ), 'price' => array( '2' ), 'required' => array( 'yes' ) ) );
expect( 'YITH required "decrease" (a discount per item): offered', Sudemi_Personalisation::screen( $req_yd )['blocked'], null );
$req_per_char = Sudemi_Addons::from_yith( 71, array( 'type' => 'text', 'title' => 'T' ), array( 'label' => array( 'Per letter' ), 'price_method' => array( 'increase' ), 'price_type' => array( 'characters' ), 'price' => array( '1' ), 'required' => array( 'yes' ) ) );
expect( 'still reported: a required price per character blocks the listing', Sudemi_Personalisation::screen( $req_per_char )['blocked_why'], Sudemi_Addons::REASON_PRICING );

// Order line: a once-per-line part is read (line_fee_minor), never part of the unit price.
$lf = Sudemi_Personalisation::from_line( array( 'personalisation' => array( array( 'key' => 'pao:p:box-1', 'label' => 'Box', 'value' => 'Gift box', 'price_minor' => 0, 'line_fee_minor' => 400, 'type' => 'choice', 'choice_key' => 'o0', 'choice_keys' => array( 'o0' ) ) ) ) );
expect( 'typed: the once-per-line fee is kept with the value', array( $lf['pao:p:box-1']['value'], $lf['pao:p:box-1']['line_fee_minor'], $lf['pao:p:box-1']['choice_keys'] ), array( 'Gift box', 400, array( 'o0' ) ) );
expect( 'YITH order line: a discount shows as "(-price)"', Sudemi_Addons::order_meta( array( Sudemi_Personalisation::field( 'yith:52', 'Box', 0, false, 0 ) + array( 'type' => 'choice', 'choices' => array( array( 'key' => '1', 'label' => 'No box', 'priceMinor' => -150 ) ), 'store' => array( 'kind' => 'yith', 'addon' => 52 ) ) ), array( 'yith:52' => array( 'label' => 'Box', 'value' => 'No box', 'choice_keys' => array( '1' ) ) ), $euro )[0], array( 'ywapo-addon-52-1', 'No box (-EUR 1.50)' ) );

// Add-on plugins sudemi does not read: never ignored (final review 2026-11-19).
$un = Sudemi_Addons::unread_plugins(
	array( 'woocommerce', 'woocommerce-product-addons', 'woocommerce-product-options', 'woo-custom-product-addons', 'custom-product-tabs', 'woocommerce-gravityforms-product-addons' ),
	array( 'woocommerce-product-addons' ),
	array( '_wcpa_product_meta' => array( array( 'fields' => array( array( 'type' => 'text', 'required' => true ) ) ) ), '_gravity_form_data' => '' )
);
expect( 'unread add-on plugins: by folder name (warning) and by product meta (required: error); read ones and others skipped', array_map( function ( $f ) { return array( $f['label'], $f['required'], $f['unofferable'] ); }, $un ), array( array( 'Add-on plugin woocommerce-product-options', false, Sudemi_Addons::REASON_PLUGIN ), array( 'Acowebs Custom Product Addons', true, Sudemi_Addons::REASON_PLUGIN ) ) );
expect( 'unread add-on plugin with meta but no required option: a warning', Sudemi_Addons::unread_plugins( array( 'woo-custom-product-addons' ), array(), array( '_wcpa_product_meta' => '[{"type":"text","required":false}]' ) )[0]['required'], false );
$scr = Sudemi_Personalisation::screen( $un );
expect( 'a required unread plugin: not listed, the message says the options could not be read', array( $scr['blocked_why'], false !== strpos( (string) $scr['blocked'], 'options could not be read; product not listed' ) ), array( Sudemi_Addons::REASON_PLUGIN, true ) );
$scr = Sudemi_Personalisation::screen( array( $un[0] ) );
expect( 'an optional unread plugin: a warning, still listed', array( $scr['blocked'], array_keys( $scr['left_out'] ) ), array( null, array( 'Add-on plugin woocommerce-product-options' ) ) );

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
