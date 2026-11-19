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
 * Only text fields are read (single line and multi line): sudemi asks the buyer for a text. A
 * price on the field is sent as priceMinor (with tax, as the shop's prices are shown): fixed
 * and per-item prices as they are, a percentage of the product's price worked out.
 *
 * The parsers are pure, so they can be tested without WordPress.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Addons {

	/** Field types that are a text the buyer types, per plugin. */
	const PAO_TEXT  = array( 'custom_text', 'custom_textarea', 'custom', 'custom_letters_only', 'custom_digits_only', 'custom_letters_or_digits' );
	const WAPF_TEXT = array( 'text', 'textarea', 'email', 'url', 'number' );
	const PPOM_TEXT = array( 'text', 'textarea', 'email', 'number' );
	const YITH_TEXT = array( 'text', 'textarea' );
	const THWEPOF_TEXT = array( 'inputtext', 'textarea', 'email', 'tel', 'url', 'number' );

	/**
	 * YITH Product Add-ons: the text inputs of one add-on row (`yith_wapo_addons`), one per
	 * option index. A price per character cannot be offered as one price, so such an input is
	 * left out; a discount (price_method "decrease") is not an extra price and counts as 0.
	 *
	 * @param int   $addon_id The add-on row's id.
	 * @param mixed $settings Its `settings`, unserialized.
	 * @param mixed $options  Its `options`, unserialized.
	 */
	public static function from_yith( $addon_id, $settings, $options ) {
		$out = array();
		if ( ! is_array( $settings ) || ! is_array( $options ) || ! in_array( isset( $settings['type'] ) ? (string) $settings['type'] : '', self::YITH_TEXT, true ) ) {
			return $out;
		}
		$get   = function ( $name, $i, $default = '' ) use ( $options ) {
			return isset( $options[ $name ] ) && is_array( $options[ $name ] ) && isset( $options[ $name ][ $i ] ) ? $options[ $name ][ $i ] : $default;
		};
		$count = 0;
		foreach ( array( 'label', 'price', 'required' ) as $column ) {
			if ( isset( $options[ $column ] ) && is_array( $options[ $column ] ) ) {
				$count = max( $count, count( $options[ $column ] ) );
			}
		}
		$title = isset( $settings['title'] ) ? trim( (string) $settings['title'] ) : '';
		for ( $i = 0; $i < $count; $i++ ) {
			$method = (string) $get( 'price_method', $i, 'free' );
			$type   = (string) $get( 'price_type', $i, 'fixed' );
			if ( 'free' !== $method && 'characters' === $type ) {
				continue;
			}
			$price = 0.0;
			if ( 'increase' === $method ) {
				$sale  = str_replace( ',', '.', (string) $get( 'price_sale', $i ) );
				$base  = str_replace( ',', '.', (string) $get( 'price', $i ) );
				$price = is_numeric( $sale ) && (float) $sale >= 0 && '' !== trim( $sale ) ? (float) $sale : ( is_numeric( $base ) ? (float) $base : 0.0 );
			}
			$label = trim( (string) $get( 'label', $i ) );
			$label = '' !== $label ? $label : $title;
			if ( '' === $label ) {
				continue;
			}
			$out[] = array(
				'key'      => 'yith:' . (int) $addon_id . '-' . $i,
				'label'    => $label,
				'required' => 'yes' === $get( 'required', $i, 'no' ),
				'max'      => 'yes' === $get( 'characters_limit', $i, 'no' ) && is_numeric( $get( 'characters_limit_max', $i ) ) ? (int) $get( 'characters_limit_max', $i ) : 0,
				'price'    => 'percentage' === $type ? 0.0 : max( 0.0, $price ),
				'percent'  => 'percentage' === $type ? max( 0.0, $price ) : 0.0,
				'store'    => array(
					'kind'   => 'yith',
					'addon'  => (int) $addon_id,
					'option' => $i,
				),
			);
		}
		return $out;
	}

	/**
	 * ThemeHigh Extra Product Options: its text fields for a product, as property lists
	 * (type, name, title, required, enabled, maxlength, and price in the paid version).
	 *
	 * @param array $fields Field name => properties.
	 */
	public static function from_thwepof( array $fields ) {
		$out = array();
		foreach ( $fields as $name => $props ) {
			if ( ! is_array( $props ) || ! in_array( isset( $props['type'] ) ? (string) $props['type'] : '', self::THWEPOF_TEXT, true ) ) {
				continue;
			}
			if ( isset( $props['enabled'] ) && ! self::truthy( $props['enabled'] ) ) {
				continue;
			}
			$name  = isset( $props['name'] ) && '' !== (string) $props['name'] ? (string) $props['name'] : (string) $name;
			$label = isset( $props['title'] ) && '' !== trim( (string) $props['title'] ) ? trim( (string) $props['title'] ) : $name;
			if ( '' === $name ) {
				continue;
			}
			$ptype = isset( $props['price_type'] ) ? (string) $props['price_type'] : '';
			$price = isset( $props['price'] ) && is_numeric( $props['price'] ) ? max( 0.0, (float) $props['price'] ) : 0.0;
			$out[] = array(
				'key'      => 'thwepof:' . self::slug( $name ),
				'label'    => $label,
				'required' => isset( $props['required'] ) && self::truthy( $props['required'] ),
				'max'      => isset( $props['maxlength'] ) && is_numeric( $props['maxlength'] ) ? (int) $props['maxlength'] : 0,
				'price'    => 'percentage' === $ptype ? 0.0 : $price,
				'percent'  => 'percentage' === $ptype ? $price : 0.0,
				'store'    => array(
					'kind' => 'name',
					'name' => $name,
				),
			);
		}
		return $out;
	}

	/**
	 * The order item meta for what the buyer typed, the way each plugin writes it (read from
	 * their source): YITH a visible `ywapo-addon-<addon>-<option>` entry ("text (+price)", shown
	 * under the add-on's label) and the hidden `_ywapo_meta_data` list its order screens read;
	 * ThemeHigh the field's name with the text (shown under the field's title); the others and
	 * this plugin's own setting the field's label with the text. Pure.
	 *
	 * @param array    $fields What the listing asks for, with 'store' (Sudemi_Personalisation::for_listing( ..., true )).
	 * @param array    $typed  key => [label, value] (Sudemi_Personalisation::from_line()).
	 * @param callable $price  Formats a field's price (minor units) for display, e.g. "€20.00".
	 * @return array List of [meta key, meta value].
	 */
	public static function order_meta( array $fields, array $typed, $price ) {
		$out  = array();
		$yith = array();
		foreach ( $fields as $field ) {
			if ( ! isset( $typed[ $field['key'] ] ) ) {
				continue;
			}
			$value = $typed[ $field['key'] ]['value'];
			$store = isset( $field['store'] ) && is_array( $field['store'] ) ? $field['store'] : array( 'kind' => 'label' );
			if ( 'yith' === $store['kind'] ) {
				$id      = $store['addon'] . '-' . $store['option'];
				$display = $value . ( ! empty( $field['priceMinor'] ) ? ' (+' . call_user_func( $price, (int) $field['priceMinor'] ) . ')' : '' );
				$out[]   = array( 'ywapo-addon-' . $id, $display );
				$yith[]  = array(
					$id => array(
						0               => $value,
						'addon_id'      => (string) $store['addon'],
						'option_id'     => (string) $store['option'],
						'addon_value'   => $value,
						'display_label' => $field['label'],
						'display_value' => $display,
					),
				);
			} elseif ( 'name' === $store['kind'] ) {
				$out[] = array( $store['name'], $value );
			} else {
				$out[] = array( $field['label'], $value );
			}
		}
		if ( ! empty( $yith ) ) {
			$out[] = array( '_ywapo_meta_data', $yith );
		}
		// A text whose field is gone since sudemi listed it: under the label sudemi sent.
		$known = array();
		foreach ( $fields as $field ) {
			$known[ $field['key'] ] = true;
		}
		foreach ( $typed as $key => $entry ) {
			if ( ! isset( $known[ $key ] ) ) {
				$out[] = array( $entry['label'], $entry['value'] );
			}
		}
		return $out;
	}

	private static function truthy( $value ) {
		return true === $value || 1 === $value || in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * WooCommerce Product Add-Ons: the text add-ons of one `_product_addons` list.
	 *
	 * @param mixed $addons The stored list.
	 * @return array List of [key, label, required, max, price, percent].
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
			$type = isset( $addon['type'] ) ? (string) $addon['type'] : '';
			// Before Product Add-Ons 3.0 a text field was type "custom" with one option.
			if ( ! in_array( $type, self::PAO_TEXT, true ) ) {
				continue;
			}
			$option = isset( $addon['options'][0] ) && is_array( $addon['options'][0] ) ? $addon['options'][0] : array();
			$adjust = ! isset( $addon['adjust_price'] ) || ! empty( $addon['adjust_price'] );
			$price  = $adjust && isset( $addon['price'] ) && is_numeric( $addon['price'] ) ? (float) $addon['price']
				: ( isset( $option['price'] ) && is_numeric( $option['price'] ) ? (float) $option['price'] : 0.0 );
			$ptype  = isset( $addon['price_type'] ) ? (string) $addon['price_type'] : ( isset( $option['price_type'] ) ? (string) $option['price_type'] : 'flat_fee' );
			$max    = isset( $addon['max'] ) && is_numeric( $addon['max'] ) && (int) $addon['max'] > 0 ? (int) $addon['max']
				: ( isset( $option['max'] ) && is_numeric( $option['max'] ) ? (int) $option['max'] : 0 );
			$out[]  = array(
				'key'      => 'pao:' . $scope . ':' . self::slug( (string) $addon['name'] . '-' . $i ),
				'label'    => (string) $addon['name'],
				'required' => ! empty( $addon['required'] ),
				'max'      => $max,
				'price'    => 'percentage_based' === $ptype ? 0.0 : $price,
				'percent'  => 'percentage_based' === $ptype ? $price : 0.0,
			);
		}
		return $out;
	}

	/**
	 * Advanced Product Fields: the text fields of one `_wapf_fieldgroup`.
	 *
	 * @param mixed $group The stored field group.
	 */
	public static function from_wapf( $group ) {
		$out = array();
		if ( ! is_array( $group ) || empty( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
			return $out;
		}
		foreach ( $group['fields'] as $field ) {
			if ( ! is_array( $field ) || empty( $field['label'] ) || ! in_array( isset( $field['type'] ) ? (string) $field['type'] : '', self::WAPF_TEXT, true ) ) {
				continue;
			}
			$pricing = isset( $field['pricing'] ) && is_array( $field['pricing'] ) ? $field['pricing'] : array();
			$on      = ! empty( $pricing['enabled'] ) && isset( $pricing['amount'] ) && is_numeric( $pricing['amount'] );
			$ptype   = isset( $pricing['type'] ) ? (string) $pricing['type'] : 'fixed';
			$amount  = $on ? (float) $pricing['amount'] : 0.0;
			$max     = isset( $field['attributes']['maxlength'] ) && is_numeric( $field['attributes']['maxlength'] ) ? (int) $field['attributes']['maxlength'] : 0;
			$out[]   = array(
				'key'      => 'wapf:' . ( isset( $field['id'] ) ? self::slug( (string) $field['id'] ) : self::slug( (string) $field['label'] ) ),
				'label'    => (string) $field['label'],
				'required' => ! empty( $field['required'] ),
				'max'      => $max,
				'price'    => in_array( $ptype, array( 'p', 'percent' ), true ) ? 0.0 : $amount,
				'percent'  => in_array( $ptype, array( 'p', 'percent' ), true ) ? $amount : 0.0,
			);
		}
		return $out;
	}

	/**
	 * PPOM: the text fields of one field group (`the_meta`, JSON).
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
			if ( ! is_array( $field ) || ! in_array( isset( $field['type'] ) ? (string) $field['type'] : '', self::PPOM_TEXT, true ) ) {
				continue;
			}
			if ( isset( $field['status'] ) && 'off' === $field['status'] ) {
				continue;
			}
			$label = isset( $field['title'] ) && '' !== (string) $field['title'] ? (string) $field['title'] : ( isset( $field['data_name'] ) ? (string) $field['data_name'] : '' );
			if ( '' === $label ) {
				continue;
			}
			$out[] = array(
				'key'      => 'ppom:' . self::slug( isset( $field['data_name'] ) ? (string) $field['data_name'] : $label ),
				'label'    => $label,
				'required' => isset( $field['required'] ) && in_array( (string) $field['required'], array( 'on', '1', 'yes', 'true' ), true ),
				'max'      => isset( $field['max_length'] ) && is_numeric( $field['max_length'] ) ? (int) $field['max_length'] : 0,
				'price'    => isset( $field['price'] ) && is_numeric( $field['price'] ) ? (float) $field['price'] : 0.0,
				'percent'  => 0.0,
			);
		}
		return $out;
	}

	/**
	 * Every text add-on of a product, from whichever of the plugins above the shop uses (their
	 * stored data, whether the plugin is active or not; a plugin removed leaves its data behind,
	 * so only an active one counts).
	 *
	 * @param WC_Product $base The product (the parent of a variation).
	 */
	public static function for_product( $base ) {
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
			$fields = array_merge( $fields, self::from_wapf( get_post_meta( $id, '_wapf_fieldgroup', true ) ) );
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
		if ( class_exists( 'THWEPOF_Utils_Section' ) && is_callable( array( 'THWEPOF_Utils_Section', 'get_product_fields' ) ) ) {
			$props = array();
			foreach ( (array) THWEPOF_Utils_Section::get_product_fields( $base, false ) as $name => $field ) {
				if ( is_object( $field ) && is_callable( array( $field, 'get_property' ) ) ) {
					$props[ $name ] = array();
					foreach ( array( 'type', 'name', 'title', 'required', 'enabled', 'maxlength', 'price', 'price_type' ) as $p ) {
						$props[ $name ][ $p ] = $field->get_property( $p );
					}
				}
			}
			$fields = array_merge( $fields, self::from_thwepof( $props ) );
		}
		return $fields;
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
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- YITH stores its add-ons serialized; objects are refused.
		$value = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
}
