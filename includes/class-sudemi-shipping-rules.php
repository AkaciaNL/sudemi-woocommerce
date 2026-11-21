<?php
/**
 * The shop's own shipping rule per country, so sudemi charges a parcel of several items the way
 * this shop's WooCommerce checkout would (protocol "The shop's own shipping rules", core
 * 2026-11-21; owner: never undercharge the shop, never overcharge the buyer).
 *
 * Each product still sends what ONE item costs on its own (Sudemi_Shipping::rates_for()); the rule
 * says what a parcel costs: `baseMinor` (the parcel price), `extraItemMinor` (added for every item
 * after the first) and `freeOverMinor` (free once the goods reach it).
 *
 * How WooCommerce settings map (one method per country, see plan()):
 *
 * - flat_rate cost `a`, `a + b * [qty]`, `b * [qty]`, `[qty] * b` (spaces, comma decimals): one item
 *   costs a + b, every further item b (`extraItemMinor`). WooCommerce counts [qty] over the whole
 *   parcel, as sudemi counts its items.
 * - flat_rate shipping class costs: calculation type "per order" charges the highest class cost
 *   once, which is sudemi's "highest item" rule: exact while the class costs are plain amounts.
 *   "Per class" adds every class's cost; with one class that has a cost it is the same, with more
 *   sudemi cannot say it, so every extra item pays the most a new item could add (reported).
 * - [fee percent=.. max_fee=..]: at most max_fee, which is used (reported). Without max_fee, [cost],
 *   or any other sum (minus, division, brackets) no price can be worked out: the method is not
 *   used and the country is not offered unless another method delivers (reported).
 * - free_shipping "A minimum order amount" (or "... OR a coupon"): `freeOverMinor`, attached to the
 *   method used. "A coupon" or "... AND a coupon" never applies on sudemi (no coupons): ignored.
 *   Without a condition it is a method that costs 0.
 * - local pickup is not delivery; other methods (table rates, carrier plugins with live rates)
 *   cannot be read: reported when nothing else delivers to the country, never priced.
 *
 * Pure: plain arrays in, plain arrays out, so the tests run without WordPress.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Shipping_Rules {

	/** Issue codes (protocol "Reporting problems"; shop-wide, no externalId). */
	const APPROXIMATED = 'shipping_rule_approximated';
	const CALCULATED   = 'shipping_rate_calculated';

	/** Methods that are not delivery to the buyer. */
	const NOT_DELIVERY = array( 'local_pickup', 'pickup_location' );

	/**
	 * Method ids (parts of them) of table-rate plugins: their rates come from a table this plugin
	 * cannot read. Any other unknown method is taken for a carrier plugin with live rates.
	 */
	const TABLE_HINTS = array( 'table', 'flexible_shipping', 'betrs', 'weight', 'advanced_shipping', 'conditional', 'distance', 'per_product' );

	/**
	 * A flat rate cost as WooCommerce evaluates it, split into what is charged once and what is
	 * charged per item ([qty]).
	 *
	 * @param string $formula The cost as entered ("5", "4,95 + 1.5 * [qty]", "[qty] * 2").
	 * @return array{fixed: float, perQty: float, approx: bool}|null Null when no price can be worked out.
	 */
	public static function parse_cost( $formula ) {
		$formula = (string) $formula;
		$approx  = false;
		$broken  = false;
		// [fee percent="10" min_fee="2" max_fee="20"]: a share of the goods, so no fixed amount,
		// but never more than max_fee. Attribute values may be quoted or not.
		$formula = preg_replace_callback(
			'/\[fee\b([^\]]*)\]/i',
			function ( $m ) use ( &$approx, &$broken ) {
				$atts = array();
				preg_match_all( '/(percent|min_fee|max_fee)\s*=\s*["\']?([0-9.,]*)["\']?/i', $m[1], $pairs, PREG_SET_ORDER );
				foreach ( $pairs as $pair ) {
					$atts[ strtolower( $pair[1] ) ] = (float) str_replace( ',', '.', $pair[2] );
				}
				$percent = isset( $atts['percent'] ) ? $atts['percent'] : 0.0;
				$min     = isset( $atts['min_fee'] ) ? $atts['min_fee'] : 0.0;
				$max     = isset( $atts['max_fee'] ) ? $atts['max_fee'] : 0.0;
				if ( $percent <= 0 ) {
					// No percentage: the fee is min_fee (capped by max_fee), a plain amount.
					return (string) ( $max > 0 ? min( $min, $max ) : $min );
				}
				if ( $max <= 0 ) {
					$broken = true;
					return '0';
				}
				$approx = true;
				return (string) $max;
			},
			$formula
		);
		if ( $broken ) {
			return null;
		}
		$formula = str_ireplace( '[qty]', 'Q', $formula );
		if ( false !== strpos( $formula, '[' ) ) {
			return null; // [cost] or another shortcode: depends on the goods, no upper limit.
		}
		// As WooCommerce does: no whitespace, comma decimals, stray operators at the ends trimmed.
		$formula = (string) preg_replace( '/\s+/', '', $formula );
		$formula = str_replace( ',', '.', $formula );
		$formula = rtrim( ltrim( $formula, '+*/' ), '+-*/' );
		if ( '' === $formula ) {
			return array(
				'fixed'  => 0.0,
				'perQty' => 0.0,
				'approx' => $approx,
			);
		}
		if ( ! preg_match( '/^[0-9.Q+*]+$/', $formula ) ) {
			return null; // Minus, division, brackets: not copied.
		}
		$fixed    = 0.0;
		$per_item = 0.0;
		foreach ( explode( '+', $formula ) as $term ) {
			if ( '' === $term ) {
				return null;
			}
			$value = 1.0;
			$qty   = 0;
			foreach ( explode( '*', $term ) as $factor ) {
				if ( 'Q' === $factor ) {
					++$qty;
				} elseif ( preg_match( '/^(\d+\.?\d*|\.\d+)$/', $factor ) ) {
					$value *= (float) $factor;
				} else {
					return null;
				}
			}
			if ( $qty > 1 ) {
				return null;
			}
			if ( 1 === $qty ) {
				$per_item += $value;
			} else {
				$fixed += $value;
			}
		}
		return array(
			'fixed'  => $fixed,
			'perQty' => $per_item,
			'approx' => $approx,
		);
	}

	/**
	 * How one zone charges, as one method: what one item of each shipping class costs, what every
	 * further item adds, and the free-shipping amount.
	 *
	 * @param array         $methods The zone's enabled methods in zone order: [['id' => 'flat_rate',
	 *                               'options' => ['cost' => '5', 'type' => 'class', ...]], ...].
	 * @param array         $classes The shop's shipping classes: term id => slug.
	 * @param callable|null $rank    fn(float $cost, bool $taxable): float, what a buyer pays for
	 *                               $cost (with VAT); picks the cheapest method. Default: $cost.
	 * @return array{method: string|null, costs: array<int, float>, extra: float, uniform: bool,
	 *               taxable: bool, freeOver: float|null, issue: array|null}
	 *               costs: class id => one item, without VAT (0 = no class or an unknown class).
	 */
	public static function plan( array $methods, array $classes, $rank = null ) {
		$rank       = is_callable( $rank ) ? $rank : function ( $cost ) {
			return $cost;
		};
		$candidates = array();
		$broken     = array();
		$unknown    = array();
		$free_over  = null;
		$free_only  = false;
		foreach ( $methods as $method ) {
			$id      = isset( $method['id'] ) ? (string) $method['id'] : '';
			$options = isset( $method['options'] ) && is_array( $method['options'] ) ? $method['options'] : array();
			if ( 'flat_rate' === $id ) {
				$flat = self::flat_rate( $options, $classes );
				if ( isset( $flat['broken'] ) ) {
					$broken[] = $flat['broken'];
				} else {
					$candidates[] = $flat;
				}
			} elseif ( 'free_shipping' === $id ) {
				$requires = isset( $options['requires'] ) ? (string) $options['requires'] : '';
				$min      = isset( $options['min_amount'] ) ? (float) str_replace( ',', '.', (string) $options['min_amount'] ) : 0.0;
				if ( '' === $requires || ( in_array( $requires, array( 'min_amount', 'either' ), true ) && $min <= 0 ) ) {
					$candidates[] = self::free();
				} elseif ( in_array( $requires, array( 'min_amount', 'either' ), true ) ) {
					$free_over = null === $free_over ? $min : min( $free_over, $min );
					$free_only = true;
				}
				// "coupon" and "both": a coupon is needed, and sudemi has none.
			} elseif ( '' !== $id && ! in_array( $id, self::NOT_DELIVERY, true ) ) {
				$unknown[] = $id;
			}
		}

		$best = null;
		foreach ( $candidates as $candidate ) {
			$price = (float) call_user_func( $rank, $candidate['costs'][0], $candidate['taxable'] );
			if ( null === $best || $price < $best[0] - 1e-9 ) {
				$best = array( $price, $candidate );
			}
		}
		if ( null === $best ) {
			$plan = self::none();
			if ( ! empty( $broken ) ) {
				$plan['issue'] = array(
					'code'     => self::APPROXIMATED,
					'method'   => 'flat_rate',
					'severity' => 'error',
					'message'  => "flat_rate cost '" . $broken[0] . "' cannot be copied (no upper limit); country not offered",
				);
			} elseif ( ! empty( $unknown ) ) {
				$table         = self::is_table( $unknown[0] );
				$plan['issue'] = array(
					'code'     => $table ? self::APPROXIMATED : self::CALCULATED,
					'method'   => $unknown[0],
					'severity' => 'error',
					'message'  => $table
						? "shipping method '" . $unknown[0] . "' takes its rates from a table that cannot be read; country not offered"
						: "shipping method '" . $unknown[0] . "' calculates its rate live; country not offered",
				);
			} elseif ( $free_only ) {
				$plan['issue'] = array(
					'code'     => self::APPROXIMATED,
					'method'   => 'free_shipping',
					'severity' => 'error',
					'message'  => 'free_shipping over a minimum amount is the only method; parcels below it cannot be charged, country not offered',
				);
			}
			return $plan;
		}
		$plan = $best[1];
		if ( 'free_shipping' !== $plan['method'] ) {
			$plan['freeOver'] = $free_over;
		}
		if ( ! empty( $plan['notes'] ) ) {
			$plan['issue'] = array(
				'code'     => self::APPROXIMATED,
				'method'   => $plan['method'],
				'severity' => 'warning',
				'message'  => $plan['method'] . ' ' . implode( '; ', $plan['notes'] ) . '; highest rate used',
			);
		}
		unset( $plan['notes'] );
		return $plan;
	}

	/** A flat rate's plan, or ['broken' => the cost that cannot be worked out]. */
	private static function flat_rate( array $options, array $classes ) {
		$cost = isset( $options['cost'] ) ? trim( (string) $options['cost'] ) : '';
		$base = self::parse_cost( $cost );
		if ( null === $base ) {
			return array( 'broken' => $cost );
		}
		$notes = array();
		if ( $base['approx'] ) {
			$notes[] = "cost '" . $cost . "' has a percentage fee";
		}
		$one = $base['fixed'] + $base['perQty'];

		// WooCommerce adds class costs only when the shop has shipping classes.
		$entries = array();
		if ( ! empty( $classes ) ) {
			$entries[0] = isset( $options['no_class_cost'] ) ? (string) $options['no_class_cost'] : '';
			foreach ( $classes as $id => $slug ) {
				// As WooCommerce reads it: the class id's cost, else the older cost by slug.
				$by_id                = isset( $options[ 'class_cost_' . $id ] ) ? trim( (string) $options[ 'class_cost_' . $id ] ) : '';
				$by_slug              = isset( $options[ 'class_cost_' . $slug ] ) ? trim( (string) $options[ 'class_cost_' . $slug ] ) : '';
				$entries[ (int) $id ] = '' !== $by_id ? $by_id : $by_slug;
			}
		}
		$costs   = array( 0 => $one );
		$charged = array();
		foreach ( $entries as $id => $string ) {
			$string = trim( $string );
			$parsed = '' === $string ? null : self::parse_cost( $string );
			if ( '' !== $string && null === $parsed ) {
				return array( 'broken' => $string );
			}
			if ( null !== $parsed && $parsed['approx'] ) {
				$notes[] = "class cost '" . $string . "' has a percentage fee";
			}
			$own          = null === $parsed ? 0.0 : $parsed['fixed'] + $parsed['perQty'];
			$costs[ $id ] = $one + $own;
			if ( null !== $parsed && ( $parsed['fixed'] > 0 || $parsed['perQty'] > 0 ) ) {
				$charged[ $id ] = $parsed;
			}
		}

		$per_class = ! isset( $options['type'] ) || 'order' !== (string) $options['type'];
		$extra     = $base['perQty'];
		if ( $per_class && count( $charged ) > 1 ) {
			// Every class in the parcel adds its own cost: an extra item may start a new class.
			$most = 0.0;
			foreach ( $charged as $parsed ) {
				$most = max( $most, $parsed['fixed'] + $parsed['perQty'] );
			}
			$extra  += $most;
			$notes[] = 'class costs are charged per class';
		} else {
			// One class cost per parcel (the highest), which is sudemi's highest-item rule; a class
			// cost per item of the class counts as if every item were of that class.
			$most = 0.0;
			foreach ( $charged as $parsed ) {
				$most = max( $most, $parsed['perQty'] );
			}
			if ( $most > 0 ) {
				$extra  += $most;
				$notes[] = 'class cost per item ([qty]) applied to every item';
			}
		}
		$uniform = count( array_unique( array_map( 'strval', $costs ) ) ) === 1;
		return array(
			'method'   => 'flat_rate',
			'costs'    => $costs,
			'extra'    => $extra,
			'uniform'  => $uniform,
			'taxable'  => ! isset( $options['tax_status'] ) || 'none' !== (string) $options['tax_status'],
			'freeOver' => null,
			'issue'    => null,
			'notes'    => $notes,
		);
	}

	private static function free() {
		return array(
			'method'   => 'free_shipping',
			'costs'    => array( 0 => 0.0 ),
			'extra'    => 0.0,
			'uniform'  => true,
			'taxable'  => false,
			'freeOver' => null,
			'issue'    => null,
			'notes'    => array(),
		);
	}

	private static function none() {
		return array(
			'method'   => null,
			'costs'    => array(),
			'extra'    => 0.0,
			'uniform'  => false,
			'taxable'  => false,
			'freeOver' => null,
			'issue'    => null,
		);
	}

	/** Whether an unknown method id looks like a table-rate plugin (else: a live carrier rate). */
	public static function is_table( $id ) {
		$id = strtolower( (string) $id );
		foreach ( self::TABLE_HINTS as $hint ) {
			if ( false !== strpos( $id, $hint ) ) {
				return true;
			}
		}
		return false;
	}

	/** What one item of this shipping class costs under the plan, without VAT. */
	public static function item_cost( array $plan, $class_id ) {
		$class_id = (int) $class_id;
		return isset( $plan['costs'][ $class_id ] ) ? $plan['costs'][ $class_id ] : $plan['costs'][0];
	}

	/**
	 * The rule for one country in the wire shape, or null when nothing needs saying (the old
	 * "highest item price, once" rule is then exactly right) or the country is not offered.
	 *
	 * @param string   $country    ISO code.
	 * @param array    $plan       From plan().
	 * @param callable $gross      fn(float $cost): float, the cost with the VAT a buyer pays on it,
	 *                             at the highest shipping VAT rate that can apply.
	 * @param bool     $vat_fixed  Whether the shipping VAT does not depend on the items' tax classes
	 *                             (then a parcel price can be sent: it is the same for every item).
	 * @param float    $free_gross What a shop-side minimum amount is in prices with VAT (1 when the
	 *                             shop's cart shows prices with VAT, else 1 + the highest VAT rate).
	 * @param int      $decimals   Minor-unit decimals of the shop currency.
	 * @return array|null
	 */
	public static function rule( $country, array $plan, $gross, $vat_fixed, $free_gross, $decimals ) {
		if ( null === $plan['method'] ) {
			return null;
		}
		$factor = 10 ** (int) $decimals;
		$rule   = array( 'country' => (string) $country );
		if ( $plan['uniform'] && $vat_fixed ) {
			$rule['baseMinor'] = (int) round( (float) call_user_func( $gross, $plan['costs'][0] ) * $factor );
		}
		if ( $plan['extra'] > 0 ) {
			$rule['extraItemMinor'] = (int) round( (float) call_user_func( $gross, $plan['extra'] ) * $factor );
		}
		if ( null !== $plan['freeOver'] ) {
			// Rounded up: a parcel just under the shop's own minimum never ships free.
			$rule['freeOverMinor'] = (int) ceil( $plan['freeOver'] * max( 1.0, (float) $free_gross ) * $factor - 1e-6 );
		}
		return count( $rule ) > 1 ? $rule : null;
	}

	/**
	 * Issues per country merged into one per problem, with the countries in details.
	 *
	 * @param array $found country => issue from plan().
	 * @return array[] [{code, severity, field, message, details: {countries, method}}]
	 */
	public static function issues( array $found ) {
		$grouped = array();
		foreach ( $found as $country => $issue ) {
			if ( empty( $issue ) ) {
				continue;
			}
			$key = $issue['code'] . '|' . $issue['method'] . '|' . $issue['severity'] . '|' . $issue['message'];
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'code'      => $issue['code'],
					'severity'  => $issue['severity'],
					'field'     => 'shipping',
					'message'   => $issue['message'],
					'countries' => array(),
					'method'    => $issue['method'],
				);
			}
			$grouped[ $key ]['countries'][] = (string) $country;
		}
		$out = array();
		foreach ( $grouped as $entry ) {
			sort( $entry['countries'] );
			$out[] = array(
				'code'     => $entry['code'],
				'severity' => $entry['severity'],
				'field'    => $entry['field'],
				'message'  => $entry['message'],
				'details'  => array(
					'countries' => $entry['countries'],
					'method'    => $entry['method'],
				),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['code'] . $a['details']['countries'][0], $b['code'] . $b['details']['countries'][0] );
			}
		);
		return $out;
	}

	/** The request body of POST /integration/v1/shipping/rules. */
	public static function body( $currency, array $rules, array $issues ) {
		usort(
			$rules,
			function ( $a, $b ) {
				return strcmp( $a['country'], $b['country'] );
			}
		);
		// Issues always go along, empty too: a rule fixed in the shop resolves its issue.
		return array(
			'currency' => (string) $currency,
			'rules'    => array_values( $rules ),
			'issues'   => array_values( $issues ),
		);
	}
}
