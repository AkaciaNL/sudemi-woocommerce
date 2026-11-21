<?php
/**
 * What a product costs to ship to each country, read from the shop's own WooCommerce shipping
 * zones, plus the delivery times the merchant set for sudemi.
 *
 * sudemi shows buyers the total including shipping and refuses a checkout to a country with no
 * shipping price, so a product without rates is never offered. The rates mirror what the shop's
 * own checkout would charge for a single item, and the shop's rule (sent once, see send_rules())
 * what it charges for a parcel of several:
 *
 * - zones are matched the way WooCommerce matches them: in zone order, first zone that names the
 *   country wins; "Locations not covered by your other zones" covers the rest of the countries
 *   the shop sells to;
 * - per zone and country ONE method counts, the same for every product and for the parcel rule:
 *   the cheapest enabled one for an item without a shipping class among flat rates whose cost
 *   can be worked out (a plain amount, a [qty] formula, a fee with a maximum) and unconditional
 *   free shipping. Free shipping over a minimum amount becomes the rule's free-over amount. What
 *   is copied and what is reported: Sudemi_Shipping_Rules. A cost that cannot be worked out is
 *   never guessed: a wrong shipping price is a wrong total shown to a buyer;
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

	/** Per request: zone id|country => plan (Sudemi_Shipping_Rules::plan()). */
	private static $plans = array();

	/** The rules are due to be sent (set when the shipping settings change; cleared once sent). */
	const OPTION_RULES_DUE = 'sudemi_shipping_rules_due';

	/** What was sent last and sudemi's answer, for the Shipping tab. */
	const OPTION_RULES_SENT = 'sudemi_shipping_rules_sent';

	/**
	 * The plugin version the rules were last flagged for. An update (a new zip over the old one runs
	 * no activation hook) flags them once, so a shop that updates sends its rules without waiting
	 * for its next full sync or shipping change (1.4.8).
	 */
	const OPTION_RULES_VERSION = 'sudemi_shipping_rules_version';

	/** Sends the rules shortly after a change, once however many changes come in. */
	const CRON_RULES = 'sudemi_shipping_rules';

	/** WooCommerce options that change what a parcel costs (besides each method's own settings). */
	const WATCHED_OPTIONS = array(
		'woocommerce_shipping_tax_class',
		'woocommerce_tax_display_cart',
		'woocommerce_calc_taxes',
		'woocommerce_ship_to_countries',
		'woocommerce_specific_ship_to_countries',
		'woocommerce_allowed_countries',
		'woocommerce_specific_allowed_countries',
		'woocommerce_all_except_countries',
	);

	public static function boot() {
		add_action( self::CRON_RULES, array( __CLASS__, 'send_rules' ) );
		// Shipping prices and the parcel rule come from the shipping zones: a change there sends
		// the rule again and re-sends the catalogue (each product's one-item price).
		$hooks = array(
			'woocommerce_after_shipping_zone_object_save',
			'woocommerce_shipping_zone_method_added',
			'woocommerce_shipping_zone_method_deleted',
			'woocommerce_shipping_zone_method_status_toggled',
			'woocommerce_update_options_shipping',
			'woocommerce_update_options_tax',
			'woocommerce_update_options_general',
			'woocommerce_shipping_classes_save_class',
			'created_product_shipping_class',
			'edited_product_shipping_class',
			'delete_product_shipping_class',
			'woocommerce_tax_rate_added',
			'woocommerce_tax_rate_updated',
			'woocommerce_tax_rate_deleted',
		);
		foreach ( $hooks as $hook ) {
			add_action( $hook, array( __CLASS__, 'changed' ), 10, 0 );
		}
		// A method's own settings (a flat rate's cost, free shipping's minimum) are saved as the
		// option woocommerce_<method>_<instance>_settings, without a hook of their own.
		add_action( 'updated_option', array( __CLASS__, 'on_option' ), 10, 1 );
		add_action( 'added_option', array( __CLASS__, 'on_option' ), 10, 1 );
		add_action( 'init', array( __CLASS__, 'after_update' ), 20, 0 );
	}

	/** Once per plugin version: flags the rules to be sent (see OPTION_RULES_VERSION). */
	public static function after_update() {
		if ( SUDEMI_VERSION === (string) get_option( self::OPTION_RULES_VERSION, '' ) ) {
			return;
		}
		update_option( self::OPTION_RULES_VERSION, SUDEMI_VERSION, false );
		// Not connected yet: pairing runs a full sync, which sends the rules.
		self::changed();
	}

	/** An option was saved: only a shipping one flags anything. Kept cheap, it runs for every option. */
	public static function on_option( $option ) {
		$option = (string) $option;
		if ( in_array( $option, self::WATCHED_OPTIONS, true ) || preg_match( '/^woocommerce_[a-z0-9_-]+_\d+_settings$/', $option ) ) {
			self::changed();
		}
	}

	/**
	 * The shop's shipping settings changed: flags the rule to be sent and schedules the send and a
	 * catalogue sync. No work in the admin request itself.
	 */
	public static function changed() {
		if ( ! Sudemi_Client::is_connected() ) {
			return;
		}
		self::$zones = null;
		self::$plans = array();
		if ( '1' !== (string) get_option( self::OPTION_RULES_DUE, '' ) ) {
			update_option( self::OPTION_RULES_DUE, '1', false );
		}
		$next = wp_next_scheduled( self::CRON_RULES );
		if ( ! $next || $next > time() + 120 ) {
			wp_schedule_single_event( time() + 60, self::CRON_RULES );
		}
		Sudemi_Sync::schedule_soon();
	}

	/** The heartbeat's backstop: a send that failed is tried again. */
	public static function send_if_due() {
		if ( '1' === (string) get_option( self::OPTION_RULES_DUE, '' ) && ! wp_next_scheduled( self::CRON_RULES ) ) {
			self::send_rules();
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_RULES );
	}

	public static function forget() {
		delete_option( self::OPTION_RULES_DUE );
		delete_option( self::OPTION_RULES_SENT );
	}

	/** What was sent last: {at, rules, issues, ignored}, or null. */
	public static function last_sent() {
		$sent = get_option( self::OPTION_RULES_SENT );
		return is_array( $sent ) ? $sent : null;
	}

	/**
	 * The shop's rule per country, to POST /integration/v1/shipping/rules. Replaces every rule sent
	 * before, so it always carries all of them. Runs from cron (after a committed full sync, or a
	 * minute after the shipping settings changed) and from the heartbeat when a send failed.
	 *
	 * @return bool Whether sudemi took it.
	 */
	public static function send_rules() {
		if ( ! Sudemi_Client::is_connected() || ! function_exists( 'WC' ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return false;
		}
		self::$zones = null;
		self::$plans = array();
		$built       = self::rules();
		$body        = Sudemi_Shipping_Rules::body( Sudemi_Currency::shop_currency(), $built['rules'], $built['issues'] );
		$response    = Sudemi_Client::post_signed( '/integration/v1/shipping/rules', $body );
		$status      = is_wp_error( $response ) ? 0 : (int) $response['status'];
		if ( 200 !== $status ) {
			if ( 0 === $status || 429 === $status || $status >= 500 ) {
				update_option( self::OPTION_RULES_DUE, '1', false );
				Sudemi_Logger::warn( 'The shipping rules could not be sent to sudemi; they are sent again with the next check-in.' );
			} else {
				// Refused: sending the same again cannot help. The next change or full sync tries.
				delete_option( self::OPTION_RULES_DUE );
				Sudemi_Logger::warn( 'sudemi refused the shipping rules (HTTP ' . $status . ').' );
			}
			return false;
		}
		delete_option( self::OPTION_RULES_DUE );
		$answer = is_array( $response['body'] ) ? $response['body'] : array();
		update_option(
			self::OPTION_RULES_SENT,
			array(
				'at'       => time(),
				'currency' => $body['currency'],
				'rules'    => $body['rules'],
				'issues'   => $body['issues'],
				'ignored'  => isset( $answer['ignored'] ) && is_array( $answer['ignored'] ) ? array_slice( $answer['ignored'], 0, 50 ) : array(),
			),
			false
		);
		return true;
	}

	/**
	 * Every country's rule and what could not be copied.
	 *
	 * @return array{rules: array[], issues: array[]}
	 */
	public static function rules() {
		$decimals = Sudemi_Currency::decimals( Sudemi_Currency::shop_currency() );
		$free     = self::free_over_gross();
		$rules    = array();
		$found    = array();
		foreach ( self::country_zones() as $country => $zone ) {
			$plan              = self::plan_for( $zone, $country );
			$found[ $country ] = $plan['issue'];
			$taxes             = self::tax_choices( $plan['taxable'], $country );
			$rule              = Sudemi_Shipping_Rules::rule(
				$country,
				$plan,
				function ( $cost ) use ( $taxes ) {
					return $cost + ( empty( $taxes ) ? 0.0 : $cost * max( $taxes ) );
				},
				count( array_unique( array_map( 'strval', $taxes ) ) ) <= 1,
				$free,
				$decimals
			);
			if ( null !== $rule ) {
				$rules[] = $rule;
			}
		}
		return array(
			'rules'  => $rules,
			'issues' => Sudemi_Shipping_Rules::issues( $found ),
		);
	}

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
		$class_id  = (int) $product->get_shipping_class_id();
		$tax_class = self::tax_class( $product->is_taxable() ? array( $product->get_tax_class() ) : array() );
		$rates     = array();
		foreach ( self::country_zones() as $country => $zone ) {
			$plan = self::plan_for( $zone, $country );
			if ( null === $plan['method'] ) {
				continue;
			}
			$cost  = Sudemi_Shipping_Rules::item_cost( $plan, $class_id );
			$cost += self::shipping_tax( $plan['taxable'], $cost, $country, $tax_class );
			$rates[] = array(
				'country'   => $country,
				'costMinor' => Sudemi_Currency::to_minor( $cost, Sudemi_Currency::shop_currency() ),
			);
		}
		return $rates;
	}

	/** The zone's plan for this country (one method, see the file comment). */
	private static function plan_for( $zone, $country ) {
		$key = $zone->get_id() . '|' . $country;
		if ( isset( self::$plans[ $key ] ) ) {
			return self::$plans[ $key ];
		}
		$classes = self::shipping_classes();
		$methods = array();
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			$keys = array( 'cost', 'type', 'no_class_cost', 'tax_status', 'requires', 'min_amount' );
			foreach ( $classes as $id => $slug ) {
				$keys[] = 'class_cost_' . $id;
				$keys[] = 'class_cost_' . $slug;
			}
			$options = array();
			foreach ( $keys as $option ) {
				$value = $method->get_option( $option, null );
				if ( null !== $value && ! is_array( $value ) ) {
					$options[ $option ] = (string) $value;
				}
			}
			$methods[] = array(
				'id'      => (string) $method->id,
				'options' => $options,
			);
		}
		$plan = Sudemi_Shipping_Rules::plan(
			$methods,
			$classes,
			function ( $cost, $taxable ) use ( $country ) {
				return $cost + self::shipping_tax( $taxable, $cost, $country, self::tax_class( array( '' ) ) );
			}
		);
		self::$plans[ $key ] = $plan;
		return $plan;
	}

	/** The shop's shipping classes: term id => slug. */
	private static function shipping_classes() {
		$out = array();
		foreach ( (array) WC()->shipping()->get_shipping_classes() as $term ) {
			if ( is_object( $term ) && isset( $term->term_id, $term->slug ) ) {
				$out[ (int) $term->term_id ] = (string) $term->slug;
			}
		}
		return $out;
	}

	/**
	 * The shipping VAT rates (0.21 for 21%) that can apply to a parcel to this country: one when
	 * the shop sets the shipping tax class, else one per tax class (WooCommerce then follows the
	 * items). Empty when shipping is not taxed.
	 *
	 * @return float[]
	 */
	private static function tax_choices( $taxable, $country ) {
		if ( ! $taxable || ! wc_tax_enabled() ) {
			return array();
		}
		$setting = get_option( 'woocommerce_shipping_tax_class', 'inherit' );
		$classes = false !== $setting && 'inherit' !== $setting
			? array( (string) $setting )
			: array_merge( array( '' ), WC_Tax::get_tax_class_slugs() );
		$out = array();
		foreach ( $classes as $class ) {
			$out[] = self::shipping_tax( true, 100.0, $country, $class ) / 100.0;
		}
		return $out;
	}

	/**
	 * What a free-shipping minimum entered in the shop is in prices with VAT, as sudemi compares
	 * the goods: WooCommerce compares the cart subtotal as the cart shows it. Shown without VAT,
	 * the minimum is raised by the shop's highest VAT rate, so a parcel never ships free on sudemi
	 * that would not in the shop.
	 */
	private static function free_over_gross() {
		if ( ! wc_tax_enabled() || 'excl' !== get_option( 'woocommerce_tax_display_cart', 'excl' ) ) {
			return 1.0;
		}
		$highest = 0.0;
		foreach ( array_merge( array( '' ), WC_Tax::get_tax_class_slugs() ) as $class ) {
			$rate = 0.0;
			foreach ( (array) WC_Tax::get_base_tax_rates( $class ) as $row ) {
				$rate += isset( $row['rate'] ) ? (float) $row['rate'] : 0.0;
			}
			$highest = max( $highest, $rate );
		}
		return 1.0 + $highest / 100.0;
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

	/**
	 * VAT on a taxable shipping cost for this destination, from WooCommerce's own tax tables:
	 * shipping costs are entered without tax, and buyers see totals with it.
	 *
	 * @param bool        $taxable   Whether the method is taxable (its "Tax status").
	 * @param float       $cost      The cost without tax.
	 * @param string      $country   The destination.
	 * @param string|null $tax_class The shipping tax class (tax_class()); null for none.
	 */
	private static function shipping_tax( $taxable, $cost, $country, $tax_class ) {
		if ( $cost <= 0 || null === $tax_class || ! $taxable || ! wc_tax_enabled() ) {
			return 0.0;
		}
		$rates = WC_Tax::find_shipping_rates(
			array(
				'country'   => $country,
				'state'     => '',
				'postcode'  => '',
				'city'      => '',
				'tax_class' => $tax_class,
			)
		);
		return (float) array_sum( WC_Tax::calc_shipping_tax( $cost, $rates ) );
	}

	/**
	 * The tax class WooCommerce's own checkout taxes shipping with, for goods of these tax classes:
	 * the shop's "Shipping tax class" setting, or with "based on cart items" the standard class when
	 * any item has it, the one class when there is one, else the first in the shop's class order.
	 * Null when no item is taxable (no tax on the shipping either).
	 *
	 * @param string[] $item_classes Tax classes of the taxable items ('' is standard).
	 * @return string|null
	 */
	public static function tax_class( array $item_classes ) {
		$setting = get_option( 'woocommerce_shipping_tax_class', 'inherit' );
		if ( false !== $setting && 'inherit' !== $setting ) {
			return (string) $setting;
		}
		$item_classes = array_values( array_unique( array_map( 'strval', $item_classes ) ) );
		if ( empty( $item_classes ) ) {
			return null;
		}
		if ( in_array( '', $item_classes, true ) ) {
			return '';
		}
		if ( 1 === count( $item_classes ) ) {
			return $item_classes[0];
		}
		foreach ( WC_Tax::get_tax_class_slugs() as $slug ) {
			if ( in_array( $slug, $item_classes, true ) ) {
				return $slug;
			}
		}
		return '';
	}
}
