<?php
/**
 * 1.4.7 (protocol "The shop's own shipping rules", core 2026-11-21): the shop's WooCommerce
 * shipping settings become one rule per country (baseMinor, extraItemMinor, freeOverMinor), so a
 * parcel costs on sudemi what the shop's own checkout charges. Never less (the shop is never
 * undercharged); what cannot be copied exactly is reported.
 *
 * Covers the flat rate cost parser, the one-method-per-zone plan, the wire rule, the grouped
 * shop-wide issues, and a brute-force check that sudemi's parcel price is never below
 * WooCommerce's flat rate for the same parcel. Destinations in several EU countries.
 *
 * Runs without WordPress.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.NamingConventions.PrefixAllGlobals,WordPress.PHP.DevelopmentFunctions -- a command-line test (plain text output); left out of the release zip.

require __DIR__ . '/../includes/class-sudemi-shipping-rules.php';

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
function near( $a, $b ) {
	return abs( (float) $a - (float) $b ) < 1e-9;
}

// ------------------------------------------------------------------ parse_cost ----

$p = Sudemi_Shipping_Rules::parse_cost( '5' );
expect( 'plain amount: charged once', array( near( $p['fixed'], 5 ), near( $p['perQty'], 0 ), $p['approx'] ), array( true, true, false ) );
$p = Sudemi_Shipping_Rules::parse_cost( '4,95 + 1.5 * [qty]' );
expect( 'a + b * [qty] with a comma decimal and spaces', array( near( $p['fixed'], 4.95 ), near( $p['perQty'], 1.5 ) ), array( true, true ) );
$p = Sudemi_Shipping_Rules::parse_cost( '[qty] * 2' );
expect( '[qty] * b', array( near( $p['fixed'], 0 ), near( $p['perQty'], 2 ) ), array( true, true ) );
$p = Sudemi_Shipping_Rules::parse_cost( '2,50*[QTY]' );
expect( 'b*[qty] without spaces, any case', near( $p['perQty'], 2.5 ), true );
$p = Sudemi_Shipping_Rules::parse_cost( '[qty]' );
expect( '[qty] alone: 1 per item', near( $p['perQty'], 1 ), true );
$p = Sudemi_Shipping_Rules::parse_cost( '' );
expect( 'empty cost is 0 (as WooCommerce)', array( near( $p['fixed'], 0 ), near( $p['perQty'], 0 ) ), array( true, true ) );
$p = Sudemi_Shipping_Rules::parse_cost( '+5+' );
expect( 'stray operators at the ends trimmed (as WooCommerce)', near( $p['fixed'], 5 ), true );
$p = Sudemi_Shipping_Rules::parse_cost( '10 + [fee percent="10" min_fee="2" max_fee="20"]' );
expect( 'percentage fee with a maximum: the maximum, approximated', array( near( $p['fixed'], 30 ), $p['approx'] ), array( true, true ) );
$p = Sudemi_Shipping_Rules::parse_cost( '[fee min_fee=3]' );
expect( 'fee without a percentage is a plain amount (exact)', array( near( $p['fixed'], 3 ), $p['approx'] ), array( true, false ) );
expect( 'percentage fee without a maximum: no price', Sudemi_Shipping_Rules::parse_cost( '5 + [fee percent="10" min_fee="2"]' ), null );
expect( '[cost]: no price', Sudemi_Shipping_Rules::parse_cost( '[cost] * 0.1' ), null );
expect( 'minus: not copied', Sudemi_Shipping_Rules::parse_cost( '10 - 1' ), null );
expect( 'division: not copied', Sudemi_Shipping_Rules::parse_cost( '10 / 2' ), null );
expect( 'brackets: not copied', Sudemi_Shipping_Rules::parse_cost( '(2 + [qty]) * 3' ), null );
expect( '[qty] * [qty]: not copied', Sudemi_Shipping_Rules::parse_cost( '[qty] * [qty]' ), null );
expect( 'thousand separators: not copied', Sudemi_Shipping_Rules::parse_cost( '1.000,50' ), null );
expect( 'another shortcode: not copied', Sudemi_Shipping_Rules::parse_cost( '[weight] * 2' ), null );

// ------------------------------------------------------------------------ plan ----

function flat( array $options ) {
	return array(
		'id'      => 'flat_rate',
		'options' => $options,
	);
}
function free( $requires = '', $min = '' ) {
	return array(
		'id'      => 'free_shipping',
		'options' => array(
			'requires'   => $requires,
			'min_amount' => $min,
		),
	);
}

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '5 + 1.5 * [qty]' ) ) ), array() );
expect( '[qty] flat rate: one item a + b', array( $plan['method'], near( $plan['costs'][0], 6.5 ), near( $plan['extra'], 1.5 ), $plan['uniform'], $plan['issue'] ), array( 'flat_rate', true, true, true, null ) );

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '4.95' ) ), free( 'min_amount', '50' ) ), array() );
expect( 'free shipping over a minimum: freeOver on the flat rate', array( $plan['method'], $plan['freeOver'] ), array( 'flat_rate', 50.0 ) );

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '4.95' ) ), free( 'either', '40,5' ), free( 'min_amount', '60' ) ), array() );
expect( '"minimum OR coupon" counts; the lowest minimum wins', $plan['freeOver'], 40.5 );

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '4.95' ) ), free( 'coupon' ), free( 'both', '30' ) ), array() );
expect( 'coupon and "minimum AND coupon" never apply on sudemi', array( $plan['freeOver'], $plan['issue'] ), array( null, null ) );

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '4.95' ) ), free() ), array() );
expect( 'unconditional free shipping is the cheapest method', array( $plan['method'], $plan['costs'][0], $plan['freeOver'] ), array( 'free_shipping', 0.0, null ) );

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '4.95' ) ), free( 'min_amount', '0' ) ), array() );
expect( 'a minimum of 0 is unconditional free shipping', $plan['method'], 'free_shipping' );

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '7' ) ), flat( array( 'cost' => '3 + 1 * [qty]' ) ) ), array() );
expect( 'two flat rates: the cheaper one for one item', array( near( $plan['costs'][0], 4 ), near( $plan['extra'], 1 ) ), array( true, true ) );

$vat  = function ( $cost, $taxable ) {
	return $taxable ? $cost * 1.21 : $cost;
};
$plan = Sudemi_Shipping_Rules::plan(
	array(
		flat( array( 'cost' => '9' ) ),
		flat(
			array(
				'cost'       => '10',
				'tax_status' => 'none',
			)
		),
	),
	array(),
	$vat
);
expect( 'the cheapest as the buyer pays it, VAT included', array( near( $plan['costs'][0], 10 ), $plan['taxable'] ), array( true, false ) );

$classes = array(
	7 => 'bulky',
	8 => 'small',
);
$plan    = Sudemi_Shipping_Rules::plan(
	array(
		flat(
			array(
				'cost'          => '5',
				'type'          => 'order',
				'class_cost_7'  => '10',
				'no_class_cost' => '',
			)
		),
	),
	$classes
);
expect( 'class cost per order: per class one-item cost', array( near( $plan['costs'][0], 5 ), near( $plan['costs'][7], 15 ), near( $plan['costs'][8], 5 ) ), array( true, true, true ) );
expect( 'class cost per order: exact (the highest item), not uniform', array( near( $plan['extra'], 0 ), $plan['uniform'], $plan['issue'] ), array( true, false, null ) );
expect( 'an unknown class costs as no class', near( Sudemi_Shipping_Rules::item_cost( $plan, 99 ), 5 ), true );

$plan = Sudemi_Shipping_Rules::plan(
	array(
		flat(
			array(
				'cost'         => '5',
				'type'         => 'class',
				'class_cost_7' => '10',
			)
		),
	),
	$classes
);
expect( 'per class with one class charged: same as per order, exact', array( near( $plan['extra'], 0 ), $plan['issue'] ), array( true, null ) );

$plan = Sudemi_Shipping_Rules::plan(
	array(
		flat(
			array(
				'cost'         => '5',
				'class_cost_7' => '10',
				'class_cost_8' => '3',
			)
		),
	),
	$classes
);
expect( 'per class (the default) with two classes charged: each extra item may add the most a class adds', near( $plan['extra'], 10 ), true );
expect( 'per class with two classes: reported approximated', array( $plan['issue']['code'], $plan['issue']['severity'], $plan['issue']['method'] ), array( 'shipping_rule_approximated', 'warning', 'flat_rate' ) );

$plan = Sudemi_Shipping_Rules::plan(
	array(
		flat(
			array(
				'cost'         => '5',
				'type'         => 'order',
				'class_cost_7' => '2 * [qty]',
			)
		),
	),
	$classes
);
expect( 'class cost per item: every extra item pays it, reported', array( near( $plan['extra'], 2 ), $plan['issue']['code'] ), array( true, 'shipping_rule_approximated' ) );

$plan = Sudemi_Shipping_Rules::plan(
	array(
		flat(
			array(
				'cost'            => '5',
				'class_cost_7'    => '',
				'class_cost_bulky' => '4',
			)
		),
	),
	$classes
);
expect( 'an older class cost saved by slug is read (as WooCommerce)', near( $plan['costs'][7], 9 ), true );

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'no_class_cost' => '3', 'cost' => '5' ) ) ), array() );
expect( 'no shipping classes in the shop: "no class" cost not added (as WooCommerce)', near( $plan['costs'][0], 5 ), true );

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '5 + [fee percent="10" max_fee="4"]' ) ) ), array() );
expect( 'a fee with a maximum: charged at the maximum, reported', array( near( $plan['costs'][0], 9 ), $plan['issue']['code'] ), array( true, 'shipping_rule_approximated' ) );

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '[cost] * 0.1' ) ) ), array() );
expect( 'a cost that cannot be worked out: not offered', $plan['method'], null );
expect( '... and reported as an error with the formula', array( $plan['issue']['code'], $plan['issue']['severity'], false !== strpos( $plan['issue']['message'], '[cost] * 0.1' ) ), array( 'shipping_rule_approximated', 'error', true ) );

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '[cost] * 0.1' ) ), flat( array( 'cost' => '6' ) ) ), array() );
expect( 'another flat rate delivers: used, no issue', array( $plan['method'], near( $plan['costs'][0], 6 ), $plan['issue'] ), array( 'flat_rate', true, null ) );

$plan = Sudemi_Shipping_Rules::plan( array( array( 'id' => 'ups' ) ), array() );
expect( 'a live carrier as the only method: shipping_rate_calculated, not priced', array( $plan['method'], $plan['issue']['code'], $plan['issue']['method'] ), array( null, 'shipping_rate_calculated', 'ups' ) );
$plan = Sudemi_Shipping_Rules::plan( array( array( 'id' => 'table_rate' ) ), array() );
expect( 'a table rate as the only method: approximated, not priced', array( $plan['method'], $plan['issue']['code'] ), array( null, 'shipping_rule_approximated' ) );
$plan = Sudemi_Shipping_Rules::plan( array( array( 'id' => 'ups' ), flat( array( 'cost' => '5' ) ) ), array() );
expect( 'a live carrier next to a flat rate: the flat rate, no issue', array( $plan['method'], $plan['issue'] ), array( 'flat_rate', null ) );
$plan = Sudemi_Shipping_Rules::plan( array( array( 'id' => 'local_pickup' ), array( 'id' => 'pickup_location' ) ), array() );
expect( 'pickup only: not offered, nothing to report', array( $plan['method'], $plan['issue'] ), array( null, null ) );
$plan = Sudemi_Shipping_Rules::plan( array( free( 'min_amount', '50' ) ), array() );
expect( 'only free shipping over a minimum: not offered, reported', array( $plan['method'], $plan['issue']['method'] ), array( null, 'free_shipping' ) );
$plan = Sudemi_Shipping_Rules::plan( array(), array() );
expect( 'no method: not offered, nothing to report', array( $plan['method'], $plan['issue'] ), array( null, null ) );

// ------------------------------------------------------------------------ rule ----

$same = function ( $cost ) {
	return $cost;
};
$nl   = function ( $cost ) {
	return $cost * 1.21;
};

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '4 + 1 * [qty]' ) ), free( 'min_amount', '50' ) ), array() );
expect(
	'DE rule: parcel price, extra item, free over, VAT included',
	Sudemi_Shipping_Rules::rule( 'DE', $plan, $nl, true, 1.0, 2 ),
	array(
		'country'        => 'DE',
		'baseMinor'      => 605,
		'extraItemMinor' => 121,
		'freeOverMinor'  => 5000,
	)
);
expect( 'VAT depends on the items: no parcel price (the items\' own prices count)', array_key_exists( 'baseMinor', Sudemi_Shipping_Rules::rule( 'FR', $plan, $nl, false, 1.0, 2 ) ), false );
expect( 'minimum compared without VAT in the shop: raised by the highest VAT rate', Sudemi_Shipping_Rules::rule( 'AT', $plan, $same, true, 1.21, 2 )['freeOverMinor'], 6050 );

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '4.95' ) ), free( 'min_amount', '49.99' ) ), array() );
expect( 'freeOver in minor units, rounded up', Sudemi_Shipping_Rules::rule( 'BE', $plan, $same, true, 1.0, 2 )['freeOverMinor'], 4999 );
expect( 'zero-decimal currency (JPY)', Sudemi_Shipping_Rules::rule( 'IT', $plan, $same, true, 1.0, 0 ), array( 'country' => 'IT', 'baseMinor' => 5, 'freeOverMinor' => 50 ) );

$plan = Sudemi_Shipping_Rules::plan(
	array(
		flat(
			array(
				'cost'         => '5',
				'type'         => 'order',
				'class_cost_7' => '10',
			)
		),
	),
	$classes
);
expect( 'class costs, no extras, no minimum: nothing to say (highest item, once)', Sudemi_Shipping_Rules::rule( 'PL', $plan, $same, true, 1.0, 2 ), null );

$plan = Sudemi_Shipping_Rules::plan( array( flat( array( 'cost' => '5' ) ), free() ), array() );
expect( 'free shipping: parcel price 0', Sudemi_Shipping_Rules::rule( 'ES', $plan, $nl, true, 1.0, 2 ), array( 'country' => 'ES', 'baseMinor' => 0 ) );

$plan = Sudemi_Shipping_Rules::plan( array( array( 'id' => 'ups' ) ), array() );
expect( 'not offered: no rule', Sudemi_Shipping_Rules::rule( 'SE', $plan, $same, true, 1.0, 2 ), null );

// ---------------------------------------------------------------- issues, body ----

$issue  = array(
	'code'     => 'shipping_rule_approximated',
	'method'   => 'flat_rate',
	'severity' => 'warning',
	'message'  => 'flat_rate class costs are charged per class; highest rate used',
);
$live   = array(
	'code'     => 'shipping_rate_calculated',
	'method'   => 'ups',
	'severity' => 'error',
	'message'  => "shipping method 'ups' calculates its rate live; country not offered",
);
$issues = Sudemi_Shipping_Rules::issues(
	array(
		'DE' => $issue,
		'NL' => null,
		'AT' => $issue,
		'PL' => $live,
	)
);
expect( 'one issue per problem, sorted by code', array( count( $issues ), $issues[0]['code'] ), array( 2, 'shipping_rate_calculated' ) );
expect( 'countries sorted in details, with the method', $issues[1]['details'], array( 'countries' => array( 'AT', 'DE' ), 'method' => 'flat_rate' ) );
expect( 'shop-wide: no externalId', array_key_exists( 'externalId', $issues[1] ), false );
expect( 'contract field names', array_keys( $issues[0] ), array( 'code', 'severity', 'field', 'message', 'details' ) );
expect( 'the live carrier issue', array( $issues[0]['code'], $issues[0]['details']['countries'] ), array( 'shipping_rate_calculated', array( 'PL' ) ) );
expect( 'messages without a long dash', false === strpos( json_encode( $issues, JSON_UNESCAPED_UNICODE ), "\u{2014}" ), true );

$body = Sudemi_Shipping_Rules::body( 'EUR', array( array( 'country' => 'NL', 'baseMinor' => 495 ), array( 'country' => 'DE', 'baseMinor' => 695 ) ), array() );
expect( 'body: currency, rules by country, issues always there', array( $body['currency'], $body['rules'][0]['country'], $body['issues'] ), array( 'EUR', 'DE', array() ) );
expect( 'body as JSON: issues an empty list', json_encode( Sudemi_Shipping_Rules::body( 'EUR', array(), array() ) ), '{"currency":"EUR","rules":[],"issues":[]}' );

// --------------------------------------- never below WooCommerce's own flat rate ----

/** WooCommerce's flat rate for a parcel: item class ids => quantities. */
function woo_flat( array $cost, array $class_costs, $type, array $parcel ) {
	$n     = array_sum( $parcel );
	$total = $cost[0] + $cost[1] * $n;
	$most  = 0.0;
	foreach ( $parcel as $class => $qty ) {
		if ( ! isset( $class_costs[ $class ] ) ) {
			continue;
		}
		$c = $class_costs[ $class ][0] + $class_costs[ $class ][1] * $qty;
		if ( 'class' === $type ) {
			$total += $c;
		} else {
			$most = max( $most, $c );
		}
	}
	return $total + $most;
}

/** sudemi's parcel price (core 2026-11-21) from the products' one-item prices and the rule. */
function sudemi_parcel( array $plan, $rule, array $parcel ) {
	$n    = array_sum( $parcel );
	$base = 0.0;
	if ( null !== $rule && isset( $rule['baseMinor'] ) ) {
		$base = $rule['baseMinor'] / 100;
	} else {
		foreach ( $parcel as $class => $qty ) {
			$base = max( $base, round( Sudemi_Shipping_Rules::item_cost( $plan, $class ) * 100 ) / 100 );
		}
	}
	$extra = null !== $rule && isset( $rule['extraItemMinor'] ) ? $rule['extraItemMinor'] / 100 : 0.0;
	return $base + $extra * ( $n - 1 );
}

mt_srand( 2611 );
$checked = 0;
$under   = 0;
$wrong   = 0;
$classes = array(
	1 => 'a',
	2 => 'b',
	3 => 'c',
);
for ( $i = 0; $i < 400; ++$i ) {
	$cost    = array( mt_rand( 0, 800 ) / 100, mt_rand( 0, 2 ) ? 0.0 : mt_rand( 0, 300 ) / 100 );
	$type    = mt_rand( 0, 1 ) ? 'class' : 'order';
	$options = array(
		'cost' => $cost[0] . ' + ' . $cost[1] . ' * [qty]',
		'type' => $type,
	);
	$cc      = array();
	foreach ( array( 0, 1, 2, 3 ) as $class ) {
		if ( mt_rand( 0, 2 ) ) {
			continue;
		}
		$cc[ $class ] = array( mt_rand( 0, 1000 ) / 100, mt_rand( 0, 3 ) ? 0.0 : mt_rand( 0, 200 ) / 100 );
		$options[ 0 === $class ? 'no_class_cost' : 'class_cost_' . $class ] = $cc[ $class ][0] . ' + ' . $cc[ $class ][1] . ' * [qty]';
	}
	$plan = Sudemi_Shipping_Rules::plan( array( flat( $options ) ), $classes );
	$rule = Sudemi_Shipping_Rules::rule( 'DE', $plan, $same, true, 1.0, 2 );
	for ( $j = 0; $j < 20; ++$j ) {
		$parcel = array();
		foreach ( array( 0, 1, 2, 3 ) as $class ) {
			$qty = mt_rand( 0, 3 );
			if ( $qty > 0 ) {
				$parcel[ $class ] = $qty;
			}
		}
		if ( empty( $parcel ) ) {
			continue;
		}
		++$checked;
		$woo = woo_flat( $cost, $cc, $type, $parcel );
		$sud = sudemi_parcel( $plan, $rule, $parcel );
		if ( $sud < $woo - 0.011 ) {
			++$under;
		}
		if ( null === $plan['issue'] && abs( $sud - $woo ) > 0.011 ) {
			++$wrong;
		}
	}
}
expect( "never below WooCommerce's flat rate ($checked random parcels)", $under, 0 );
expect( 'equal to it wherever nothing was reported as approximated', $wrong, 0 );

if ( $failures ) {
	echo "\n$failures failure(s)\n";
	exit( 1 );
}
echo "\nall ok\n";
