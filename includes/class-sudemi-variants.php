<?php
/**
 * Variations a buyer can order exactly, including those with an "Any ..." attribute.
 *
 * A WooCommerce variation may leave an attribute open ("Any colour"): the storefront then lets
 * the buyer pick the colour and writes it on the order line. Sent as one listing, sudemi would
 * sell "the shirt in some colour" and the shop's order would say no colour at all. So such a
 * variation becomes one listing per value the buyer can pick, each with its own external id
 * "<variation id>~<key>", where the key is a short hash of the chosen values. Orders coming back
 * name that id; the chosen values are found again from the key and written on the order line,
 * exactly as WooCommerce's own checkout writes them.
 *
 * A combination another variation defines too (Red / M on its own, next to "Any colour / M")
 * belongs to the variation WooCommerce's own storefront would pick for it: the first one, in the
 * shop's variation order, whose attributes match.
 *
 * Pure functions, so they can be tested without WordPress.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Variants {

	/** At most this many listings come out of one variation with "Any" attributes. */
	const MAX_PER_VARIATION = 250;

	/** The cartesian product is built up to this many choices, never more (memory and time). */
	const MAX_BUILD = 5000;

	/**
	 * The full choices one variation is sold as.
	 *
	 * @param array $own      This variation's attributes: name => value ('' = any).
	 * @param array $options  The parent's variation attributes: name => list of values.
	 * @param array $siblings Every variation of the parent, in the shop's order: id => attributes.
	 * @param int   $own_id   This variation's id.
	 * @return array List of name => value arrays, every value filled in. One entry (its own
	 *               attributes) for a variation without "Any"; empty when nothing can be ordered.
	 */
	public static function choices( array $own, array $options, array $siblings, $own_id ) {
		$open = array();
		foreach ( $own as $name => $value ) {
			if ( '' === (string) $value ) {
				$open[] = $name;
			}
		}
		if ( empty( $open ) ) {
			return array( $own );
		}
		$combos = array( $own );
		foreach ( $open as $name ) {
			$values = isset( $options[ $name ] ) && is_array( $options[ $name ] ) ? array_values( array_unique( array_map( 'strval', $options[ $name ] ) ) ) : array();
			$next   = array();
			foreach ( $combos as $combo ) {
				foreach ( $values as $value ) {
					if ( '' === $value ) {
						continue;
					}
					$combo[ $name ] = $value;
					$next[]         = $combo;
					if ( count( $next ) >= self::MAX_BUILD ) {
						break 2;
					}
				}
			}
			$combos = $next;
		}
		$out = array();
		foreach ( $combos as $combo ) {
			if ( (int) self::winner( $combo, $siblings ) !== (int) $own_id ) {
				continue;
			}
			$out[] = $combo;
			if ( count( $out ) >= self::MAX_PER_VARIATION ) {
				break;
			}
		}
		return $out;
	}

	/** The variation WooCommerce's storefront picks for a full choice: the first that matches. */
	public static function winner( array $choice, array $siblings ) {
		foreach ( $siblings as $id => $attributes ) {
			$match = true;
			foreach ( (array) $attributes as $name => $value ) {
				if ( '' === (string) $value ) {
					continue;
				}
				if ( ! isset( $choice[ $name ] ) || self::norm( $choice[ $name ] ) !== self::norm( $value ) ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				return (int) $id;
			}
		}
		return null;
	}

	/** Whether a variation leaves an attribute for the buyer to choose ("Any ..."). */
	public static function has_open( array $own ) {
		foreach ( $own as $value ) {
			if ( '' === (string) $value ) {
				return true;
			}
		}
		return false;
	}

	/** The short key of one full choice: the same values give the same key, in any order. */
	public static function key( array $choice ) {
		$pairs = array();
		foreach ( $choice as $name => $value ) {
			$pairs[ (string) $name ] = self::norm( $value );
		}
		ksort( $pairs );
		return substr( md5( (string) wp_json_encode( $pairs ) ), 0, 10 );
	}

	/**
	 * The external id of a variation sold as one choice: the plain id when nothing was left
	 * open, "<id>~<key>" for one value picked for an "Any" attribute.
	 */
	public static function external_id( $variation_id, array $own, array $choice ) {
		return self::has_open( $own ) ? $variation_id . '~' . self::key( $choice ) : (string) $variation_id;
	}

	/**
	 * [product or variation id, key or null] from an external id this plugin sent, or null when
	 * it is not one.
	 */
	public static function parse( $external_id ) {
		if ( ! is_string( $external_id ) && ! is_int( $external_id ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^([0-9]{1,20})(?:~([0-9a-f]{10}))?$/', (string) $external_id, $m ) ) {
			return null;
		}
		return array( (int) $m[1], isset( $m[2] ) && '' !== $m[2] ? $m[2] : null );
	}

	/** The choice behind a key, from the choices the variation is sold as; null when gone. */
	public static function find( array $choices, $key ) {
		foreach ( $choices as $choice ) {
			if ( self::key( $choice ) === $key ) {
				return $choice;
			}
		}
		return null;
	}

	/** WooCommerce compares attribute values case-insensitively for custom attributes. */
	private static function norm( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $value ) ) : strtolower( trim( (string) $value ) );
	}
}
