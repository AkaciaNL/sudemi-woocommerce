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

	/** A stable, short key part: lower case letters, digits and dashes. Pure. */
	public static function slug( $text ) {
		$key = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $text ) );
		$key = trim( $key, '-' );
		return '' === $key ? substr( md5( (string) $text ), 0, 8 ) : substr( $key, 0, 60 );
	}
}
