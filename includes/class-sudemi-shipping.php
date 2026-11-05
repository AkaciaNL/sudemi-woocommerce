<?php
/**
 * What a product costs to ship to each country, read from the shop's own WooCommerce shipping
 * zones, plus the delivery times the merchant set for sudemi.
 *
 * sudemi shows buyers the total including shipping and refuses a checkout to a country with no
 * shipping price, so a product without rates is never offered. The rates mirror what the shop's
 * own checkout would charge for a single item:
 *
 * - zones are matched the way WooCommerce matches them: in zone order, first zone that names the
 *   country wins; "Locations not covered by your other zones" covers the rest of the countries
 *   the shop sells to;
 * - per zone the cheapest enabled method that has a fixed price counts: a flat rate whose cost is
 *   a plain number (plus the product's shipping-class cost if one is set), or free shipping that
 *   has no condition. Cost formulas ([qty], [fee ...]) and conditional free shipping are skipped
 *   rather than guessed: a wrong shipping price is a wrong total shown to a buyer;
 * - local pickup is not delivery and is ignored.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

class Sudemi_Shipping {

	const OPTION_HANDLING_DAYS = 'sudemi_handling_days';
	const OPTION_TRANSIT_DAYS  = 'sudemi_transit_days';

	/** Conservative until the merchant sets their own (shown and editable on the sudemi screen). */
	const DEFAULT_HANDLING_DAYS = 2;
	const DEFAULT_TRANSIT_DAYS  = 5;

	/** sudemi's buyer markets: EU, EEA, Switzerland, United Kingdom, Türkiye. */
	const MARKETS = array(
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV',
		'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'NO', 'IS', 'LI', 'CH', 'GB', 'TR',
	);

	/** Per request: zone resolution is the same for every product of a sync. */
	private static $zones = null;

	public static function handling_days() {
		return self::days( self::OPTION_HANDLING_DAYS, self::DEFAULT_HANDLING_DAYS );
	}

	public static function transit_days() {
		return self::days( self::OPTION_TRANSIT_DAYS, self::DEFAULT_TRANSIT_DAYS );
	}

	private static function days( $option, $default ) {
		$value = get_option( $option, '' );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return $default;
		}
		return max( 0, min( 60, (int) $value ) );
	}

	/**
	 * Shipping prices for one product, as sudemi's catalogue expects them.
	 *
	 * @param WC_Product $product The product.
	 * @return array<int, array{country: string, costMinor: int}>
	 */
	public static function rates_for( $product ) {
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}
		if ( $product->is_virtual() || ! $product->needs_shipping() ) {
			return array();
		}
		$class_id = (int) $product->get_shipping_class_id();
		$rates    = array();
		foreach ( self::country_zones() as $country => $zone ) {
			$cost = self::cheapest( $zone, $class_id, $country );
			if ( null !== $cost ) {
				$rates[] = array(
					'country'   => $country,
					'costMinor' => (int) round( $cost * 100 ),
				);
			}
		}
		return $rates;
	}

	/**
	 * Country => the zone WooCommerce would use for it.
	 *
	 * @return array<string, WC_Shipping_Zone>
	 */
	private static function country_zones() {
		if ( null !== self::$zones ) {
			return self::$zones;
		}
		$allowed = array_keys( (array) WC()->countries->get_shipping_countries() );
		$targets = array_values( array_intersect( self::MARKETS, $allowed ) );
		$continents = WC()->countries->get_continents();

		$map = array();
		// get_zones() is already in the shop's zone order.
		foreach ( WC_Shipping_Zones::get_zones() as $data ) {
			$zone = new WC_Shipping_Zone( $data['id'] );
			foreach ( $zone->get_zone_locations() as $location ) {
				$codes = array();
				if ( 'country' === $location->type ) {
					$codes[] = $location->code;
				} elseif ( 'state' === $location->type ) {
					// A state-level zone does not cover the whole country; WooCommerce would use
					// another zone elsewhere in it. Not a fixed price for the country: skipped.
					continue;
				} elseif ( 'continent' === $location->type && isset( $continents[ $location->code ]['countries'] ) ) {
					$codes = $continents[ $location->code ]['countries'];
				}
				foreach ( $codes as $code ) {
					if ( in_array( $code, $targets, true ) && ! isset( $map[ $code ] ) ) {
						// A zone narrowed by postcode only covers part of the country.
						if ( self::has_postcodes( $zone ) ) {
							continue;
						}
						$map[ $code ] = $zone;
					}
				}
			}
		}
		$rest = new WC_Shipping_Zone( 0 );
		foreach ( $targets as $code ) {
			if ( ! isset( $map[ $code ] ) ) {
				$map[ $code ] = $rest;
			}
		}
		self::$zones = $map;
		return $map;
	}

	private static function has_postcodes( $zone ) {
		foreach ( $zone->get_zone_locations() as $location ) {
			if ( 'postcode' === $location->type ) {
				return true;
			}
		}
		return false;
	}

	/** Cheapest fixed price of the zone's enabled methods for this shipping class, or null. */
	private static function cheapest( $zone, $class_id, $country ) {
		$best = null;
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			$cost = null;
			if ( 'flat_rate' === $method->id ) {
				$base = trim( (string) $method->get_option( 'cost', '' ) );
				$base = '' === $base ? '0' : str_replace( ',', '.', $base );
				if ( ! is_numeric( $base ) ) {
					continue;
				}
				$cost = (float) $base;
				$class_cost = trim( (string) $method->get_option(
					$class_id ? 'class_cost_' . $class_id : 'no_class_cost',
					''
				) );
				if ( '' !== $class_cost ) {
					$class_cost = str_replace( ',', '.', $class_cost );
					if ( ! is_numeric( $class_cost ) ) {
						continue;
					}
					$cost += (float) $class_cost;
				}
			} elseif ( 'free_shipping' === $method->id ) {
				$requires = (string) $method->get_option( 'requires', '' );
				if ( '' !== $requires ) {
					continue;
				}
				$cost = 0.0;
			} else {
				continue;
			}
			$cost += self::shipping_tax( $method, $cost, $country );
			if ( null === $best || $cost < $best ) {
				$best = $cost;
			}
		}
		return $best;
	}

	/**
	 * VAT on a taxable shipping cost for this destination, from WooCommerce's own tax tables:
	 * shipping costs are entered without tax, and buyers see totals with it.
	 */
	private static function shipping_tax( $method, $cost, $country ) {
		if ( $cost <= 0 || ! wc_tax_enabled() || 'taxable' !== $method->get_option( 'tax_status', 'taxable' ) ) {
			return 0.0;
		}
		$rates = WC_Tax::find_shipping_rates(
			array(
				'country'   => $country,
				'state'     => '',
				'postcode'  => '',
				'city'      => '',
				'tax_class' => '',
			)
		);
		return (float) array_sum( WC_Tax::calc_shipping_tax( $cost, $rates ) );
	}
}
