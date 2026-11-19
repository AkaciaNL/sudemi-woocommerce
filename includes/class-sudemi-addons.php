<?php
/**
 * Texts a buyer types, as the shop's add-on plugin defines them.
 *
 * Many shops ask the buyer for a text with an add-on plugin (a name printed on a shirt, an
 * engraving). sudemi has to ask the same, with the same price, or it sells something the shop
 * cannot make. The field definitions are read straight from where each plugin stores them, so
 * none of them has to be installed or loaded for this to work, and none is required:
 *
 * - WooCommerce Product Add-Ons (official): product meta `_product_addons`, and the global
 *   add-on groups (post type `global_product_addon`) for all products or the product's
 *   categories, unless the product excludes them (`_product_addons_exclude_global`).
 * - Advanced Product Fields for WooCommerce (StudioWombat): product meta `_wapf_fieldgroup`.
 * - PPOM (Product Addons & Fields): the field group in the `nm_personalized` table named by
 *   the product meta `_product_meta_id`.
 * - YITH WooCommerce Product Add-ons (4.x, read from its source): the add-on blocks YITH itself
 *   picks for the product (YITH_WAPO_DB()->yith_wapo_get_blocks_by_product), and their text
 *   add-ons in the `yith_wapo_addons` table: `settings` (serialized: type, title) and `options`
 *   (serialized, one list per property: label, required, price, price_sale, price_method,
 *   price_type, characters_limit, characters_limit_max), one text input per option index.
 * - ThemeHigh Extra Product Options (free 3.x, read from its source): the fields its own
 *   THWEPOF_Utils_Section::get_product_fields() shows for the product (sections in the option
 *   `thwepof_custom_sections`, with their product and category rules). The free version has no
 *   prices; a `price` property (the paid version) is read when present.
 *
 * On an order from sudemi the text is written on the order line the way the plugin writes it
 * itself (see order_meta()), so the shop sees it as for any other order.
 *
 * Text fields (type text) and choices from the shop's list (select, radio: type choice;
 * checkboxes: multi_choice), each with its price (with tax, as the shop's prices are shown):
 * fixed and per-item prices as they are, a percentage of the product's price worked out. A
 * price this contract cannot carry (per character, a formula) leaves that field out.
 *
 * The parsers are pure, so they can be tested without WordPress.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Addons {

	/** Field types per plugin: text, one choice, several choices. */
	const PAO_TEXT     = array( 'custom_text', 'custom_textarea', 'custom', 'custom_letters_only', 'custom_digits_only', 'custom_letters_or_digits', 'custom_email' );
	const PAO_CHOICE   = array( 'multiple_choice', 'select', 'radiobutton' );
	const PAO_MULTI    = array( 'checkbox' );
	const WAPF_TEXT    = array( 'text', 'textarea', 'email', 'url', 'number' );
	const WAPF_CHOICE  = array( 'select', 'radio', 'image-swatch', 'color-swatch', 'text-swatch' );
	const WAPF_MULTI   = array( 'checkboxes', 'multi-image-swatch', 'multi-color-swatch', 'multi-text-swatch' );
	const PPOM_TEXT    = array( 'text', 'textarea', 'email', 'number' );
	const PPOM_CHOICE  = array( 'select', 'radio', 'image', 'palettes' );
	const PPOM_MULTI   = array( 'checkbox' );
	const YITH_TEXT    = array( 'text', 'textarea' );
	const YITH_CHOICE  = array( 'select', 'radio', 'color', 'label' );
	const YITH_MULTI   = array( 'checkbox' );
	const THWEPOF_TEXT   = array( 'inputtext', 'textarea', 'email', 'tel', 'url', 'number' );
	const THWEPOF_CHOICE = array( 'select', 'radio' );
	const THWEPOF_MULTI  = array( 'checkboxgroup' );

	/** sudemi takes at most 100 choices per field. */
	const MAX_CHOICES = 100;

	/** One field as the parsers return it. Pure. */
	private static function make( $key, $label, $type, $required, $max, $price, $percent, array $choices, array $store ) {
		$field = array(
			'key'         => $key,
			'label'       => (string) $label,
			'type'        => $type,
			'required'    => (bool) $required,
			'max'         => (int) $max,
			'price'       => (float) $price,
			'percent'     => (float) $percent,
			'choices'     => $choices,
			'store'       => $store,
			'unofferable' => null,
		);
		// A discount (a negative price) cannot be charged through sudemi yet: never made free
		// (the buyer would pay too much) and never left at full price. The field is not offered,
		// with the reason, so the gap stays visible (Sudemi_Personalisation).
		$negative = $field['price'] < 0 || $field['percent'] < 0;
		foreach ( $choices as $c ) {
			$negative = $negative || $c['price'] < 0 || $c['percent'] < 0;
		}
		if ( $negative ) {
			$field['unofferable'] = self::REASON_DISCOUNT;
		}
		return $field;
	}

	/** Why a field cannot be offered on sudemi yet. */
	const REASON_DISCOUNT = 'it lowers the price (a discount), which sudemi cannot charge yet';
	const REASON_PRICING  = 'it is priced per character or by a formula, which sudemi cannot charge yet';
	const REASON_FLAT     = 'its price is charged once per order line (a flat fee), which sudemi cannot charge yet';
	const REASON_PLUGIN   = 'it comes from an add-on plugin sudemi cannot read (ThemeComplete Extra Product Options)';

	/**
	 * A field whose price is charged once per order line, not per item (Product Add-Ons
	 * "flat fee", Advanced Product Fields "fixed", PPOM "fixed fee", YITH "sell individually"):
	 * sent as a price per item it overcharges a buyer of two or more, so it is not offered (unless
	 * already left out for another reason). Pure.
	 */
	private static function flat( array $field, $is_flat ) {
		return $is_flat && null === $field['unofferable'] ? self::unofferable( $field, self::REASON_FLAT ) : $field;
	}

	/** A field whose price this contract cannot carry: kept, marked, never offered. Pure. */
	private static function unofferable( array $field, $reason ) {
		$field['unofferable'] = $reason;
		return $field;
	}

	/** One choice: key (no commas), label, price, percent. Labels unique; null when unusable. Pure. */
	private static function choice( $key, $label, $price, $percent ) {
		$label = trim( self::plain( (string) $label ) );
		if ( '' === $label ) {
			return null;
		}
		return array(
			'key'     => str_replace( ',', '-', (string) $key ),
			'label'   => $label,
			'price'   => (float) $price,
			'percent' => (float) $percent,
		);
	}

	/** Drops repeated keys or labels and caps the list. Pure. */
	private static function unique_choices( array $choices ) {
		$out    = array();
		$keys   = array();
		$labels = array();
		foreach ( $choices as $c ) {
			if ( null === $c || isset( $keys[ $c['key'] ] ) || isset( $labels[ strtolower( $c['label'] ) ] ) ) {
				continue;
			}
			$keys[ $c['key'] ]                 = true;
			$labels[ strtolower( $c['label'] ) ] = true;
			$out[]                             = $c;
			if ( count( $out ) >= self::MAX_CHOICES ) {
				break;
			}
		}
		return $out;
	}

	private static function num( $value ) {
		$value = str_replace( ',', '.', trim( (string) $value ) );
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	public static function truthy( $value ) {
		return true === $value || 1 === $value || in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * WooCommerce Product Add-Ons: text add-ons and choices (multiple choice, checkboxes) of one
	 * `_product_addons` list. A flat fee is sent as a price per item.
	 *
	 * @param mixed  $addons The stored list.
	 * @param string $scope  'p' for the product's own, 'g<id>' for a global group.
	 */
	public static function from_pao( $addons, $scope = 'p' ) {
		$out = array();
		if ( ! is_array( $addons ) ) {
			return $out;
		}
		foreach ( $addons as $i => $addon ) {
			if ( ! is_array( $addon ) || empty( $addon['name'] ) ) {
				continue;
			}
			$type    = isset( $addon['type'] ) ? (string) $addon['type'] : '';
			$key     = 'pao:' . $scope . ':' . self::slug( (string) $addon['name'] . '-' . $i );
			$options = isset( $addon['options'] ) && is_array( $addon['options'] ) ? array_values( $addon['options'] ) : array();
			if ( in_array( $type, self::PAO_TEXT, true ) ) {
				// Before Product Add-Ons 3.0 a text field was type "custom" with one option.
				$option = isset( $options[0] ) && is_array( $options[0] ) ? $options[0] : array();
				$adjust = ! isset( $addon['adjust_price'] ) || self::truthy( $addon['adjust_price'] );
				$price  = $adjust && isset( $addon['price'] ) && '' !== (string) $addon['price'] ? self::num( $addon['price'] ) : self::num( isset( $option['price'] ) ? $option['price'] : 0 );
				$ptype  = isset( $addon['price_type'] ) ? (string) $addon['price_type'] : ( isset( $option['price_type'] ) ? (string) $option['price_type'] : 'flat_fee' );
				$max    = isset( $addon['max'] ) && (int) $addon['max'] > 0 ? (int) $addon['max'] : ( isset( $option['max'] ) ? (int) $option['max'] : 0 );
				$pct    = 'percentage_based' === $ptype;
				$out[]  = self::flat( self::make( $key, $addon['name'], 'text', ! empty( $addon['required'] ), $max, $pct ? 0 : $price, $pct ? $price : 0, array(), array( 'kind' => 'label' ) ), 'flat_fee' === $ptype && 0.0 !== $price );
			} elseif ( in_array( $type, self::PAO_CHOICE, true ) || in_array( $type, self::PAO_MULTI, true ) ) {
				$choices = array();
				$is_flat = false;
				foreach ( $options as $j => $option ) {
					if ( ! is_array( $option ) ) {
						continue;
					}
					$pct       = isset( $option['price_type'] ) && 'percentage_based' === $option['price_type'];
					$price     = self::num( isset( $option['price'] ) ? $option['price'] : 0 );
					$is_flat   = $is_flat || ( ( ! isset( $option['price_type'] ) || 'flat_fee' === $option['price_type'] ) && 0.0 !== $price );
					$choices[] = self::choice( 'o' . $j, isset( $option['label'] ) ? $option['label'] : '', $pct ? 0 : $price, $pct ? $price : 0 );
				}
				$choices = self::unique_choices( $choices );
				if ( ! empty( $choices ) ) {
					$out[] = self::flat( self::make( $key, $addon['name'], in_array( $type, self::PAO_MULTI, true ) ? 'multi_choice' : 'choice', ! empty( $addon['required'] ), 0, 0, 0, $choices, array( 'kind' => 'label' ) ), $is_flat );
				}
			}
		}
		return $out;
	}

	/**
	 * Advanced Product Fields: the fields of one field group, as stored in `_wapf_fieldgroup`
	 * (strings "true"/"false") or as its objects give them. Prices: fixed (flat fee) and per item
	 * as they are, percent worked out; a formula or a price per character leaves the field out.
	 *
	 * @param mixed $group The stored field group.
	 */
	public static function from_wapf( $group ) {
		$out = array();
		if ( ! is_array( $group ) || empty( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
			return $out;
		}
		foreach ( $group['fields'] as $field ) {
			if ( ! is_array( $field ) || empty( $field['label'] ) ) {
				continue;
			}
			$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
			$id    = isset( $field['id'] ) ? (string) $field['id'] : (string) $field['label'];
			$key   = 'wapf:' . self::slug( $id );
			$label = trim( self::plain( (string) $field['label'] ) );
			$store = array(
				'kind' => 'wapf',
				'id'   => $id,
			);
			$req   = isset( $field['required'] ) && self::truthy( $field['required'] );
			if ( in_array( $type, self::WAPF_TEXT, true ) ) {
				$pricing = isset( $field['pricing'] ) && is_array( $field['pricing'] ) ? $field['pricing'] : array();
				$on      = isset( $pricing['enabled'] ) && self::truthy( $pricing['enabled'] );
				$ptype   = isset( $pricing['type'] ) ? (string) $pricing['type'] : 'fixed';
				if ( $on && ! in_array( $ptype, array( 'fixed', 'qt', 'percent', 'p' ), true ) ) {
					$out[] = self::unofferable( self::make( $key, $label, 'text', $req, 0, 0, 0, array(), $store ), self::REASON_PRICING );
					continue;
				}
				$amount = $on ? self::num( isset( $pricing['amount'] ) ? $pricing['amount'] : 0 ) : 0.0;
				$pct    = in_array( $ptype, array( 'p', 'percent' ), true );
				$max    = 0;
				foreach ( array( array( 'attributes', 'maxlength' ), array( 'options', 'maxlength' ), array( 'maxlength', null ) ) as $path ) {
					$v = null === $path[1] ? ( isset( $field[ $path[0] ] ) ? $field[ $path[0] ] : null ) : ( isset( $field[ $path[0] ][ $path[1] ] ) ? $field[ $path[0] ][ $path[1] ] : null );
					if ( is_numeric( $v ) && (int) $v > 0 ) {
						$max = (int) $v;
						break;
					}
				}
				// "fixed" is a flat fee (not quantity-based, APF's own label); "qt" is per item.
				$out[] = self::flat( self::make( $key, $label, 'text', $req, $max, $pct ? 0 : $amount, $pct ? $amount : 0, array(), $store ), $on && 'fixed' === $ptype && 0.0 !== $amount );
			} elseif ( in_array( $type, self::WAPF_CHOICE, true ) || in_array( $type, self::WAPF_MULTI, true ) ) {
				$raw     = isset( $field['choices'] ) ? $field['choices'] : ( isset( $field['options']['choices'] ) ? $field['options']['choices'] : array() );
				$choices = array();
				$skip    = false;
				$is_flat = false;
				foreach ( (array) $raw as $c ) {
					if ( ! is_array( $c ) ) {
						continue;
					}
					$ptype = isset( $c['pricing_type'] ) ? (string) $c['pricing_type'] : 'none';
					if ( ! in_array( $ptype, array( 'none', 'fixed', 'qt', 'percent', 'p' ), true ) ) {
						$skip = true;
						break;
					}
					$amount    = 'none' === $ptype ? 0.0 : self::num( isset( $c['pricing_amount'] ) ? $c['pricing_amount'] : 0 );
					$is_flat   = $is_flat || ( 'fixed' === $ptype && 0.0 !== $amount );
					$pct       = in_array( $ptype, array( 'p', 'percent' ), true );
					$choices[] = self::choice( isset( $c['slug'] ) ? $c['slug'] : self::slug( isset( $c['label'] ) ? $c['label'] : '' ), isset( $c['label'] ) ? $c['label'] : '', $pct ? 0 : $amount, $pct ? $amount : 0 );
				}
				$choices = self::unique_choices( $choices );
				$field   = self::flat( self::make( $key, $label, in_array( $type, self::WAPF_MULTI, true ) ? 'multi_choice' : 'choice', $req, 0, 0, 0, $choices, $store ), $is_flat );
				if ( $skip ) {
					$out[] = self::unofferable( $field, self::REASON_PRICING );
				} elseif ( ! empty( $choices ) ) {
					$out[] = $field;
				}
			}
		}
		return $out;
	}

	/**
	 * PPOM: the fields of one field group (`the_meta`, JSON). Options are [{option, price, id}];
	 * a price with "%" is a percentage of the product's price.
	 *
	 * @param mixed $meta The stored JSON or list.
	 */
	public static function from_ppom( $meta ) {
		$fields = is_string( $meta ) ? json_decode( $meta, true ) : $meta;
		$out    = array();
		if ( ! is_array( $fields ) ) {
			return $out;
		}
		foreach ( $fields as $field ) {
			$type = is_array( $field ) && isset( $field['type'] ) ? (string) $field['type'] : '';
			if ( ! is_array( $field ) || ( isset( $field['status'] ) && 'off' === $field['status'] ) ) {
				continue;
			}
			$name  = isset( $field['data_name'] ) ? (string) $field['data_name'] : '';
			$label = isset( $field['title'] ) && '' !== (string) $field['title'] ? (string) $field['title'] : $name;
			if ( '' === $label || '' === $name ) {
				continue;
			}
			$key   = 'ppom:' . self::slug( $name );
			$req   = isset( $field['required'] ) && self::truthy( $field['required'] );
			$store = array(
				'kind' => 'ppom',
				'name' => $name,
			);
			if ( in_array( $type, self::PPOM_TEXT, true ) ) {
				$price = isset( $field['price'] ) ? (string) $field['price'] : '';
				$pct   = false !== strpos( $price, '%' );
				// PPOM's "fixed fee" (onetime) is charged once per cart line: not per item.
				$onetime = isset( $field['onetime'] ) && self::truthy( $field['onetime'] );
				$out[]   = self::flat( self::make( $key, $label, 'text', $req, isset( $field['max_length'] ) ? (int) $field['max_length'] : 0, $pct ? 0 : self::num( $price ), $pct ? self::num( str_replace( '%', '', $price ) ) : 0, array(), $store ), $onetime && 0.0 !== self::num( str_replace( '%', '', $price ) ) );
			} elseif ( in_array( $type, self::PPOM_CHOICE, true ) || in_array( $type, self::PPOM_MULTI, true ) ) {
				$raw     = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : ( isset( $field['images'] ) && is_array( $field['images'] ) ? $field['images'] : array() );
				$choices = array();
				foreach ( $raw as $o ) {
					if ( ! is_array( $o ) ) {
						continue;
					}
					$text      = isset( $o['option'] ) ? (string) $o['option'] : ( isset( $o['title'] ) ? (string) $o['title'] : '' );
					$price     = isset( $o['price'] ) ? (string) $o['price'] : '';
					$pct       = false !== strpos( $price, '%' );
					$id        = isset( $o['option_id'] ) && '' !== (string) $o['option_id'] ? (string) $o['option_id'] : ( isset( $o['id'] ) && '' !== (string) $o['id'] ? (string) $o['id'] : self::slug( $text ) );
					$choices[] = self::choice( $id, $text, $pct ? 0 : self::num( $price ), $pct ? self::num( str_replace( '%', '', $price ) ) : 0 );
				}
				$choices = self::unique_choices( $choices );
				if ( ! empty( $choices ) ) {
					$priced = false;
					foreach ( $choices as $c ) {
						$priced = $priced || 0.0 !== $c['price'] || 0.0 !== $c['percent'];
					}
					$out[] = self::flat( self::make( $key, $label, in_array( $type, self::PPOM_MULTI, true ) ? 'multi_choice' : 'choice', $req, 0, 0, 0, $choices, $store ), isset( $field['onetime'] ) && self::truthy( $field['onetime'] ) && $priced );
				}
			}
		}
		return $out;
	}

	/**
	 * YITH Product Add-ons: one add-on row (`yith_wapo_addons`). A text add-on is one text input
	 * per option index; a select, radio or checkbox add-on is one field whose options are the
	 * choices. A price per character cannot be offered as one price (left out); a discount
	 * ("decrease") is not an extra price and counts as 0.
	 *
	 * @param int   $addon_id The add-on row's id.
	 * @param mixed $settings Its `settings`, unserialized.
	 * @param mixed $options  Its `options`, unserialized.
	 */
	public static function from_yith( $addon_id, $settings, $options ) {
		$out = array();
		if ( ! is_array( $settings ) || ! is_array( $options ) ) {
			return $out;
		}
		$type  = isset( $settings['type'] ) ? (string) $settings['type'] : '';
		$get   = function ( $name, $i, $default = '' ) use ( $options ) {
			return isset( $options[ $name ] ) && is_array( $options[ $name ] ) && isset( $options[ $name ][ $i ] ) ? $options[ $name ][ $i ] : $default;
		};
		$price = function ( $i ) use ( $get ) {
			$method = (string) $get( 'price_method', $i, 'free' );
			$ptype  = (string) $get( 'price_type', $i, 'fixed' );
			if ( 'free' !== $method && 'characters' === $ptype ) {
				return null;
			}
			$amount = 0.0;
			if ( 'increase' === $method || 'decrease' === $method ) {
				$sale   = trim( (string) $get( 'price_sale', $i ) );
				$amount = '' !== $sale && is_numeric( str_replace( ',', '.', $sale ) ) ? self::num( $sale ) : self::num( $get( 'price', $i ) );
				// A decrease is a discount: negative, so the field is not offered (make()).
				$amount = 'decrease' === $method ? -abs( $amount ) : $amount;
			}
			return 'percentage' === $ptype ? array( 0.0, $amount ) : array( $amount, 0.0 );
		};
		$count = 0;
		foreach ( array( 'label', 'price', 'required' ) as $column ) {
			if ( isset( $options[ $column ] ) && is_array( $options[ $column ] ) ) {
				$count = max( $count, count( $options[ $column ] ) );
			}
		}
		$title = isset( $settings['title'] ) ? trim( self::plain( (string) $settings['title'] ) ) : '';
		if ( in_array( $type, self::YITH_TEXT, true ) ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$p     = $price( $i );
				$label = trim( self::plain( (string) $get( 'label', $i ) ) );
				$label = '' !== $label ? $label : $title;
				if ( '' === $label ) {
					continue;
				}
				if ( null === $p ) {
					$out[] = self::unofferable( self::make( 'yith:' . (int) $addon_id . '-' . $i, $label, 'text', 'yes' === $get( 'required', $i, 'no' ), 0, 0, 0, array(), array( 'kind' => 'yith', 'addon' => (int) $addon_id, 'option' => $i ) ), self::REASON_PRICING );
					continue;
				}
				$out[] = self::make(
					'yith:' . (int) $addon_id . '-' . $i,
					$label,
					'text',
					'yes' === $get( 'required', $i, 'no' ),
					'yes' === $get( 'characters_limit', $i, 'no' ) ? (int) $get( 'characters_limit_max', $i, 0 ) : 0,
					$p[0],
					$p[1],
					array(),
					array(
						'kind'   => 'yith',
						'addon'  => (int) $addon_id,
						'option' => $i,
					)
				);
			}
		} elseif ( in_array( $type, self::YITH_CHOICE, true ) || in_array( $type, self::YITH_MULTI, true ) ) {
			$choices = array();
			$per_char = false;
			for ( $i = 0; $i < $count; $i++ ) {
				$p = $price( $i );
				if ( null === $p ) {
					$per_char = true;
					continue;
				}
				$choices[] = self::choice( (string) $i, $get( 'label', $i ), $p[0], $p[1] );
			}
			$choices = self::unique_choices( $choices );
			if ( '' !== $title && ( ! empty( $choices ) || $per_char ) ) {
				$field = self::make(
					'yith:' . (int) $addon_id,
					$title,
					in_array( $type, self::YITH_MULTI, true ) ? 'multi_choice' : 'choice',
					isset( $settings['required'] ) && 'yes' === $settings['required'],
					0,
					0,
					0,
					$choices,
					array(
						'kind'  => 'yith',
						'addon' => (int) $addon_id,
					)
				);
				$out[] = $per_char ? self::unofferable( $field, self::REASON_PRICING ) : $field;
			}
		}
		// "Sell individually": YITH puts the add-on in the cart once, whatever the quantity (its
		// own setting, read from its source); a price on it is charged once per order line.
		if ( isset( $settings['sell_individually'] ) && 'yes' === $settings['sell_individually'] ) {
			foreach ( $out as $n => $field ) {
				$priced = 0.0 !== $field['price'] || 0.0 !== $field['percent'];
				foreach ( $field['choices'] as $c ) {
					$priced = $priced || 0.0 !== $c['price'] || 0.0 !== $c['percent'];
				}
				$out[ $n ] = self::flat( $field, $priced );
			}
		}
		return $out;
	}

	/**
	 * ThemeHigh Extra Product Options: its fields for a product, as property lists (type, name,
	 * title, required, enabled, maxlength, options; price in the paid version). The free version
	 * has no prices; its options are plain texts.
	 *
	 * @param array $fields Field name => properties.
	 */
	public static function from_thwepof( array $fields ) {
		$out = array();
		foreach ( $fields as $name => $props ) {
			$type = is_array( $props ) && isset( $props['type'] ) ? (string) $props['type'] : '';
			if ( ! is_array( $props ) || ( isset( $props['enabled'] ) && ! self::truthy( $props['enabled'] ) ) ) {
				continue;
			}
			$name  = isset( $props['name'] ) && '' !== (string) $props['name'] ? (string) $props['name'] : (string) $name;
			$label = isset( $props['title'] ) && '' !== trim( (string) $props['title'] ) ? trim( self::plain( (string) $props['title'] ) ) : $name;
			if ( '' === $name ) {
				continue;
			}
			$ptype = isset( $props['price_type'] ) ? (string) $props['price_type'] : '';
			$price = isset( $props['price'] ) ? self::num( $props['price'] ) : 0.0;
			$pct   = 'percentage' === $ptype;
			$req   = isset( $props['required'] ) && self::truthy( $props['required'] );
			$store = array(
				'kind' => 'name',
				'name' => $name,
			);
			$key   = 'thwepof:' . self::slug( $name );
			if ( in_array( $type, self::THWEPOF_TEXT, true ) ) {
				$out[] = self::make( $key, $label, 'text', $req, isset( $props['maxlength'] ) ? (int) $props['maxlength'] : 0, $pct ? 0 : $price, $pct ? $price : 0, array(), $store );
			} elseif ( in_array( $type, self::THWEPOF_CHOICE, true ) || in_array( $type, self::THWEPOF_MULTI, true ) ) {
				$choices = array();
				foreach ( isset( $props['options'] ) && is_array( $props['options'] ) ? $props['options'] : array() as $option ) {
					$text      = is_array( $option ) ? ( isset( $option['text'] ) ? $option['text'] : ( isset( $option['key'] ) ? $option['key'] : '' ) ) : (string) $option;
					$choices[] = self::choice( self::slug( $text ), $text, 0, 0 );
				}
				$choices = self::unique_choices( $choices );
				if ( ! empty( $choices ) ) {
					$out[] = self::make( $key, $label, in_array( $type, self::THWEPOF_MULTI, true ) ? 'multi_choice' : 'choice', $req, 0, $pct ? 0 : $price, $pct ? $price : 0, $choices, $store );
				}
			}
		}
		return $out;
	}

	/**
	 * The order item meta for what the buyer typed or chose, the way each plugin writes it (read
	 * from their source):
	 * - Product Add-Ons and this plugin's own setting: the field's label with the text, or with
	 *   each chosen option.
	 * - Advanced Product Fields: the label with the value, and the hidden `_wapf_meta` list
	 *   (id => id, label, value, raw).
	 * - PPOM: the field's data name with the value (PPOM shows it under the field's title), and
	 *   the hidden `_ppom_fields` (fields: data name => value).
	 * - YITH: a visible `ywapo-addon-<addon>-<option>` per text or chosen option ("value
	 *   (+price)", shown under the add-on's label) and the hidden `_ywapo_meta_data` list.
	 * - ThemeHigh: the field's name with the value (choices joined by ","; shown under the title).
	 * Pure.
	 *
	 * @param array    $fields What the listing asks for, with 'store' (Sudemi_Personalisation::for_listing( ..., true )).
	 * @param array    $typed  key => [label, value, choice_keys] (Sudemi_Personalisation::from_line()).
	 * @param callable $price  Formats a price (minor units) for display, e.g. "€20.00".
	 * @return array List of [meta key, meta value].
	 */
	public static function order_meta( array $fields, array $typed, $price ) {
		$out   = array();
		$yith  = array();
		$wapf  = array();
		$ppom  = array();
		$known = array();
		foreach ( $fields as $field ) {
			$known[ $field['key'] ] = true;
			if ( ! isset( $typed[ $field['key'] ] ) ) {
				continue;
			}
			$entry  = $typed[ $field['key'] ];
			$store  = isset( $field['store'] ) && is_array( $field['store'] ) ? $field['store'] : array( 'kind' => 'label' );
			$type   = isset( $field['type'] ) ? $field['type'] : 'text';
			$chosen = array();
			if ( 'text' !== $type && ! empty( $field['choices'] ) ) {
				$keys = isset( $entry['choice_keys'] ) ? (array) $entry['choice_keys'] : array();
				foreach ( $field['choices'] as $c ) {
					if ( in_array( (string) $c['key'], array_map( 'strval', $keys ), true ) ) {
						$chosen[] = $c;
					}
				}
				if ( empty( $chosen ) ) {
					// Older answer without keys: match the labels sudemi joined with ", ".
					foreach ( $field['choices'] as $c ) {
						if ( in_array( strtolower( $c['label'] ), array_map( 'strtolower', array_map( 'trim', explode( ',', (string) $entry['value'] ) ) ), true ) ) {
							$chosen[] = $c;
						}
					}
				}
			}
			$value = 'text' === $type || empty( $chosen ) ? (string) $entry['value'] : implode( ', ', self::labels_of( $chosen ) );
			switch ( $store['kind'] ) {
				case 'yith':
					$parts = 'text' === $type
						? array( array( (string) $store['option'], $entry['value'], isset( $field['priceMinor'] ) ? (int) $field['priceMinor'] : 0 ) )
						: array_map(
							function ( $c ) {
								return array( (string) $c['key'], $c['label'], isset( $c['priceMinor'] ) ? (int) $c['priceMinor'] : 0 );
							},
							$chosen
						);
					foreach ( $parts as $part ) {
						$id      = $store['addon'] . '-' . $part[0];
						$display = $part[1] . ( $part[2] > 0 ? ' (+' . call_user_func( $price, $part[2] ) . ')' : '' );
						$out[]   = array( 'ywapo-addon-' . $id, $display );
						$yith[]  = array(
							$id => array(
								0               => $part[1],
								'addon_id'      => (string) $store['addon'],
								'option_id'     => $part[0],
								'addon_value'   => $part[1],
								'display_label' => $field['label'],
								'display_value' => $display,
							),
						);
					}
					break;
				case 'wapf':
					$out[]                 = array( $field['label'], $value );
					$wapf[ $store['id'] ] = array(
						'id'    => $store['id'],
						'label' => $field['label'],
						'value' => $value,
						'raw'   => 'text' === $type ? $value : implode( ',', array_map( 'strval', self::keys_of( $chosen ) ) ),
					);
					break;
				case 'ppom':
					$out[]                   = array( $store['name'], $value );
					$ppom[ $store['name'] ] = 'multi_choice' === $type ? self::labels_of( $chosen ) : $value;
					break;
				case 'name':
					$out[] = array( $store['name'], 'text' === $type || empty( $chosen ) ? $value : implode( ',', self::labels_of( $chosen ) ) );
					break;
				default:
					if ( 'multi_choice' === $type && ! empty( $chosen ) ) {
						foreach ( $chosen as $c ) {
							$out[] = array( $field['label'], $c['label'] );
						}
					} else {
						$out[] = array( $field['label'], $value );
					}
			}
		}
		if ( ! empty( $yith ) ) {
			$out[] = array( '_ywapo_meta_data', $yith );
		}
		if ( ! empty( $wapf ) ) {
			$out[] = array( '_wapf_meta', $wapf );
		}
		if ( ! empty( $ppom ) ) {
			$out[] = array( '_ppom_fields', array( 'fields' => $ppom ) );
		}
		// A value whose field is gone since sudemi listed it: under the label sudemi sent.
		foreach ( $typed as $key => $entry ) {
			if ( ! isset( $known[ $key ] ) ) {
				$out[] = array( $entry['label'], $entry['value'] );
			}
		}
		return $out;
	}

	/** The fields read per product in this request (every variation shares its parent's). */
	private static $read = array();

	/** Forgets what was read (a new sync or push: the shop may have changed its add-ons). */
	public static function reset() {
		self::$read = array();
	}

	public static function for_product( $base ) {
		$id = (int) $base->get_id();
		if ( ! isset( self::$read[ $id ] ) ) {
			if ( count( self::$read ) > 200 ) {
				self::$read = array();
			}
			self::$read[ $id ] = self::read_product( $base );
		}
		return self::$read[ $id ];
	}

	private static function read_product( $base ) {
		$id     = (int) $base->get_id();
		$fields = array();
		if ( self::active( 'woocommerce-product-addons/woocommerce-product-addons.php' ) ) {
			$fields = array_merge( $fields, self::from_pao( get_post_meta( $id, '_product_addons', true ) ) );
			if ( ! get_post_meta( $id, '_product_addons_exclude_global', true ) ) {
				foreach ( self::pao_global_groups( $id ) as $group_id ) {
					$fields = array_merge( $fields, self::from_pao( get_post_meta( $group_id, '_product_addons', true ), 'g' . $group_id ) );
				}
			}
		}
		if ( self::active( 'advanced-product-fields-for-woocommerce/advanced-product-fields-for-woocommerce.php' )
			|| self::active( 'advanced-product-fields-for-woocommerce-pro/advanced-product-fields-for-woocommerce-pro.php' ) ) {
			$groups = class_exists( '\\SW_WAPF\\Includes\\Classes\\Field_Groups' )
				? self::wapf_groups( $base )
				: array( get_post_meta( $id, '_wapf_fieldgroup', true ) );
			foreach ( $groups as $group ) {
				$fields = array_merge( $fields, self::from_wapf( $group ) );
			}
		}
		if ( self::active( 'woocommerce-product-addon/woocommerce-product-addon.php' ) ) {
			foreach ( self::ppom_groups( $id ) as $meta ) {
				$fields = array_merge( $fields, self::from_ppom( $meta ) );
			}
		}
		// YITH and ThemeHigh: their own code decides which blocks / sections apply (product,
		// category, exclusions), so only when they are loaded.
		if ( function_exists( 'YITH_WAPO_DB' ) ) {
			foreach ( self::yith_addons( $base ) as $row ) {
				$fields = array_merge( $fields, self::from_yith( $row['id'], $row['settings'], $row['options'] ) );
			}
		}
		// ThemeComplete Extra Product Options: no free version to read its format from (owner
		// 2026-11-19: no licence). Its options on a product are reported, never guessed.
		if ( self::active( 'woocommerce-tm-extra-product-options/tm-woo-extra-product-options.php' )
			&& ( get_post_meta( $id, 'tm_meta', true ) || get_post_meta( $id, 'tm_meta_cpf', true ) ) ) {
			$fields[] = self::unofferable( self::make( 'tc:' . $id, 'ThemeComplete Extra Product Options', 'text', false, 0, 0, 0, array(), array( 'kind' => 'label' ) ), self::REASON_PLUGIN );
		}
		if ( class_exists( 'THWEPOF_Utils_Section' ) && is_callable( array( 'THWEPOF_Utils_Section', 'get_product_fields' ) ) ) {
			$props = array();
			foreach ( (array) THWEPOF_Utils_Section::get_product_fields( $base, false ) as $name => $field ) {
				if ( is_object( $field ) && is_callable( array( $field, 'get_property' ) ) ) {
					$props[ $name ] = array();
					foreach ( array( 'type', 'name', 'title', 'required', 'enabled', 'maxlength', 'options', 'price', 'price_type' ) as $p ) {
						$props[ $name ][ $p ] = $field->get_property( $p );
					}
				}
			}
			$fields = array_merge( $fields, self::from_thwepof( $props ) );
		}
		return $fields;
	}

	/**
	 * Advanced Product Fields' own field groups for the product (on the product and global
	 * groups whose rules match), as arrays from_wapf() reads.
	 */
	private static function wapf_groups( $product ) {
		$out = array();
		foreach ( (array) \SW_WAPF\Includes\Classes\Field_Groups::get_field_groups_of_product( $product ) as $group ) {
			if ( ! is_object( $group ) || empty( $group->fields ) ) {
				continue;
			}
			$fields = array();
			foreach ( (array) $group->fields as $f ) {
				if ( ! is_object( $f ) ) {
					continue;
				}
				$options  = isset( $f->options ) && is_array( $f->options ) ? $f->options : array();
				$pricing  = isset( $f->pricing ) && is_object( $f->pricing ) ? $f->pricing : null;
				$fields[] = array(
					'id'       => isset( $f->id ) ? (string) $f->id : '',
					'label'    => isset( $f->label ) ? (string) $f->label : '',
					'type'     => isset( $f->type ) ? (string) $f->type : '',
					'required' => ! empty( $f->required ),
					'choices'  => isset( $options['choices'] ) ? $options['choices'] : array(),
					'options'  => $options,
					'pricing'  => $pricing ? array(
						'enabled' => ! empty( $pricing->enabled ),
						'amount'  => isset( $pricing->amount ) ? $pricing->amount : 0,
						'type'    => isset( $pricing->type ) ? $pricing->type : 'fixed',
					) : array(),
				);
			}
			$out[] = array( 'fields' => $fields );
		}
		return $out;
	}

	private static function active( $plugin ) {
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( $plugin, $active, true ) ) {
			return true;
		}
		$network = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
		return isset( $network[ $plugin ] );
	}

	/** Global add-on groups for all products or for one of the product's categories. */
	private static function pao_global_groups( $product_id ) {
		$groups = get_posts(
			array(
				'post_type'        => 'global_product_addon',
				'post_status'      => 'publish',
				'numberposts'      => 50,
				'fields'           => 'ids',
				'orderby'          => 'meta_value_num',
				'meta_key'         => '_priority', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the add-on groups only, a handful of posts.
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		$cats = is_array( $cats ) ? array_map( 'intval', $cats ) : array();
		$out  = array();
		foreach ( (array) $groups as $group_id ) {
			if ( get_post_meta( $group_id, '_all_products', true ) ) {
				$out[] = (int) $group_id;
				continue;
			}
			$for = wp_get_post_terms( $group_id, 'product_cat', array( 'fields' => 'ids' ) );
			if ( is_array( $for ) && array_intersect( $cats, array_map( 'intval', $for ) ) ) {
				$out[] = (int) $group_id;
			}
		}
		return $out;
	}

	/** The YITH add-on rows of the blocks YITH shows on this product: id, settings, options. */
	private static function yith_addons( $product ) {
		global $wpdb;
		$db = YITH_WAPO_DB();
		if ( ! is_object( $db ) || ! is_callable( array( $db, 'yith_wapo_get_blocks_by_product' ) ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $db->yith_wapo_get_blocks_by_product( $product, null, 'yes' ) as $block_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, settings, options FROM %i WHERE block_id = %d AND visibility = 1 ORDER BY priority ASC', $wpdb->prefix . 'yith_wapo_addons', (int) $block_id ) );
			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'id'       => (int) $row->id,
					'settings' => self::unserialize_plain( $row->settings ),
					'options'  => self::unserialize_plain( $row->options ),
				);
			}
		}
		return $out;
	}

	/** A serialized array, never an object (allowed_classes false). Pure. */
	public static function unserialize_plain( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		// YITH stores its add-ons serialized; objects are refused (allowed_classes false).
		$value = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		return is_array( $value ) ? $value : array();
	}

	/** PPOM field groups (their stored JSON) attached to a product. */
	private static function ppom_groups( $product_id ) {
		global $wpdb;
		$ids = get_post_meta( $product_id, '_product_meta_id', true );
		$ids = array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array( $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'nm_personalized';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $meta_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_var( $wpdb->prepare( 'SELECT the_meta FROM %i WHERE productmeta_id = %d', $table, $meta_id ) );
			if ( null !== $row ) {
				$out[] = (string) $row;
			}
		}
		return $out;
	}

	/** A stable, short key part (sudemi keys are at most 64 long): lower case letters, digits and dashes. Pure. */
	public static function slug( $text ) {
		$key = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $text ) );
		$key = trim( $key, '-' );
		return '' === $key ? substr( md5( (string) $text ), 0, 8 ) : substr( $key, 0, 40 );
	}

	/** Text without markup (labels may hold a little HTML in some plugins). Pure. */
	private static function plain( $text ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( (string) $text ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- pure, also outside WordPress.
	}

	private static function labels_of( array $choices ) {
		return array_map(
			function ( $c ) {
				return $c['label'];
			},
			$choices
		);
	}

	private static function keys_of( array $choices ) {
		return array_map(
			function ( $c ) {
				return $c['key'];
			},
			$choices
		);
	}
}
