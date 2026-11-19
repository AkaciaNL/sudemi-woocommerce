<?php
/**
 * Product data beyond title, price and stock (sudemi contract v2, 2026-11-19): the sale and its
 * dates, stock status and backorders, dimensions, the quantities the shop sells in (minimum,
 * step, maximum) and quantity prices.
 *
 * Sources: WooCommerce itself (sale price and schedule, stock status, backorders, dimensions,
 * "sold individually"), every min/max/step plugin that sets WooCommerce's own quantity input
 * (woocommerce_quantity_input_args and the _min/_max/_step filters: WPC Product Quantity, Min Max
 * Quantity & Step Control, WooCommerce Min/Max Quantities and others), and Tiered Pricing Table
 * (its PriceManager / PricingRule, read from its source) for quantity prices.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Product_Data {

	/** sudemi's limits. */
	const MAX_QUANTITY    = 100000;
	const MAX_TIERS       = 20;
	const MM_PER_UNIT     = array(
		'mm' => 1,
		'cm' => 10,
		'm'  => 1000,
		'in' => 25.4,
		'yd' => 914.4,
	);

	/** The fields for one product or variation; only what the shop says. */
	public static function for_product( $product ) {
		$out = array();
		$out = array_merge( $out, self::sale( $product ) );
		$out = array_merge( $out, self::availability( $product ) );
		$out = array_merge( $out, self::dimensions( $product->get_length(), $product->get_width(), $product->get_height(), get_option( 'woocommerce_dimension_unit', 'cm' ) ) );
		$out = array_merge( $out, self::quantities( $product ) );
		$tiers = self::tier_prices( $product );
		if ( ! empty( $tiers ) ) {
			$out['quantityPrices'] = $tiers;
		}
		return $out;
	}

	/**
	 * A sale the shop set (sale price below the regular price): regular and sale price with VAT,
	 * and its dates. WooCommerce ends a sale at the end of its last day (23:59:59), sudemi's
	 * "until" is exclusive: one second later. An ended sale is not sent.
	 */
	public static function sale( $product ) {
		$regular = $product->get_regular_price();
		$sale    = $product->get_sale_price();
		if ( '' === $regular || '' === $sale || null === $sale || (float) $sale >= (float) $regular ) {
			return array();
		}
		$from = $product->get_date_on_sale_from();
		$to   = $product->get_date_on_sale_to();
		return self::sale_fields(
			self::minor_with_tax( $product, $regular ),
			self::minor_with_tax( $product, $sale ),
			$from ? $from->getTimestamp() : null,
			$to ? $to->getTimestamp() : null,
			time()
		);
	}

	/** The sale fields from prices (minor, with VAT) and Unix times; empty when none applies. Pure. */
	public static function sale_fields( $regular_minor, $sale_minor, $from, $to, $now ) {
		if ( null === $regular_minor || null === $sale_minor || $sale_minor < 1 || $sale_minor >= $regular_minor ) {
			return array();
		}
		if ( null !== $to && $to + 1 <= $now ) {
			return array();
		}
		$out = array(
			'regularPriceMinor' => (int) $regular_minor,
			'salePriceMinor'    => (int) $sale_minor,
		);
		if ( null !== $from ) {
			$out['salePriceFrom'] = gmdate( 'Y-m-d\TH:i:s\Z', (int) $from );
		}
		if ( null !== $to ) {
			$out['salePriceUntil'] = gmdate( 'Y-m-d\TH:i:s\Z', (int) $to + 1 );
		}
		return $out;
	}

	private static function minor_with_tax( $product, $price ) {
		$with = wc_get_price_including_tax(
			$product,
			array(
				'qty'   => 1,
				'price' => $price,
			)
		);
		return Sudemi_Currency::to_minor( $with, Sudemi_Currency::shop_currency() );
	}

	/** Stock status and backorders, as WooCommerce says them. */
	public static function availability( $product ) {
		return self::availability_fields( (string) $product->get_stock_status(), (bool) $product->backorders_allowed() );
	}

	/** Pure. */
	public static function availability_fields( $status, $backorders ) {
		$map = array(
			'instock'     => 'in_stock',
			'outofstock'  => 'out_of_stock',
			'onbackorder' => 'backorder',
		);
		$out = array( 'allowBackorders' => (bool) $backorders );
		if ( isset( $map[ $status ] ) ) {
			$out['availability'] = $map[ $status ];
		}
		return $out;
	}

	/** Length, width, height in whole millimetres; 0 or empty is not said. Pure. */
	public static function dimensions( $length, $width, $height, $unit ) {
		$factor = isset( self::MM_PER_UNIT[ $unit ] ) ? self::MM_PER_UNIT[ $unit ] : 10;
		$out    = array();
		foreach ( array(
			'lengthMm' => $length,
			'widthMm'  => $width,
			'heightMm' => $height,
		) as $name => $value ) {
			$mm = is_numeric( $value ) ? (int) round( (float) $value * $factor ) : 0;
			if ( $mm >= 1 && $mm <= self::MAX_QUANTITY ) {
				$out[ $name ] = $mm;
			}
		}
		return $out;
	}

	/**
	 * The quantities the shop sells in, as its own quantity box takes them: WooCommerce's
	 * filters, which every min/max/step plugin sets. A maximum that only follows the stock is not
	 * a rule (the stock is sent anyway).
	 */
	public static function quantities( $product ) {
		// WooCommerce's own quantity hooks (the plugins set these), read as its quantity box does.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		try {
			$min  = apply_filters( 'woocommerce_quantity_input_min', $product->get_min_purchase_quantity(), $product );
			$max  = apply_filters( 'woocommerce_quantity_input_max', $product->get_max_purchase_quantity(), $product );
			$step = apply_filters( 'woocommerce_quantity_input_step', 1, $product );
			$args = apply_filters(
				'woocommerce_quantity_input_args',
				array(
					'input_value' => $min,
					'min_value'   => $min,
					'max_value'   => $max,
					'step'        => $step,
				),
				$product
			);
		} catch ( \Throwable $e ) {
			return array();
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$stock = $product->managing_stock() && ! $product->backorders_allowed() ? (int) $product->get_stock_quantity() : null;
		return self::quantity_fields(
			isset( $args['min_value'] ) ? $args['min_value'] : 1,
			isset( $args['step'] ) ? $args['step'] : 1,
			isset( $args['max_value'] ) ? $args['max_value'] : -1,
			$stock,
			$product->is_sold_individually()
		);
	}

	/** Pure. */
	public static function quantity_fields( $min, $step, $max, $stock, $sold_individually ) {
		$out  = array();
		$min  = is_numeric( $min ) ? (int) $min : 1;
		$step = is_numeric( $step ) ? (int) $step : 1;
		$max  = is_numeric( $max ) ? (int) $max : -1;
		if ( $min > 1 && $min <= self::MAX_QUANTITY ) {
			$out['minQuantity'] = $min;
		}
		if ( $step > 1 && $step <= self::MAX_QUANTITY ) {
			$out['quantityStep'] = $step;
		}
		if ( $sold_individually ) {
			$max = 1;
		} elseif ( null !== $stock && $max === $stock ) {
			$max = -1;
		}
		if ( $max >= max( 1, $min ) && $max < self::MAX_QUANTITY ) {
			$out['maxQuantity'] = $max;
		}
		return $out;
	}

	/**
	 * Quantity prices from Tiered Pricing Table (its own PricingRule: fixed, percentage or
	 * amount tiers, with tax as the shop shows prices), at most 20, from 2 items up.
	 */
	public static function tier_prices( $product ) {
		if ( ! class_exists( '\\TierPricingTable\\PriceManager' ) || ! is_callable( array( '\\TierPricingTable\\PriceManager', 'getPricingRule' ) ) ) {
			return array();
		}
		try {
			$rule = \TierPricingTable\PriceManager::getPricingRule( $product->get_id() );
			if ( ! is_object( $rule ) || ! is_callable( array( $rule, 'getRules' ) ) ) {
				return array();
			}
			$prices = array();
			foreach ( array_keys( (array) $rule->getRules() ) as $quantity ) {
				$price = $rule->getTierPrice( (int) $quantity, true );
				if ( is_numeric( $price ) ) {
					$prices[ (int) $quantity ] = Sudemi_Currency::to_minor( $price, Sudemi_Currency::shop_currency() );
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		return self::tier_fields( $prices );
	}

	/** [{minQuantity, priceMinor}] from quantity => price minor: 2 and up, sorted, at most 20. Pure. */
	public static function tier_fields( array $prices ) {
		ksort( $prices );
		$out = array();
		foreach ( $prices as $quantity => $minor ) {
			if ( (int) $quantity < 2 || (int) $quantity > self::MAX_QUANTITY || null === $minor || (int) $minor < 1 ) {
				continue;
			}
			$out[] = array(
				'minQuantity' => (int) $quantity,
				'priceMinor'  => (int) $minor,
			);
			if ( count( $out ) >= self::MAX_TIERS ) {
				break;
			}
		}
		return $out;
	}

	/** The fields a price-only update carries along with the price (so it never ends a sale). */
	public static function price_update_fields( array $listing ) {
		$out = array();
		foreach ( array( 'regularPriceMinor', 'salePriceMinor', 'salePriceFrom', 'salePriceUntil', 'availability', 'allowBackorders' ) as $name ) {
			if ( array_key_exists( $name, $listing ) ) {
				$out[ $name ] = $listing[ $name ];
			}
		}
		return $out;
	}
}
