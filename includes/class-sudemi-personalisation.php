<?php
/**
 * A text the buyer types for the product (a name printed on a shirt, an engraving).
 *
 * Read from the shop's add-on plugin when it has one (Sudemi_Addons). For a shop without one,
 * WooCommerce itself has no field for it, so this plugin's own setting lives on the product's sudemi tab: the
 * label the buyer is asked ("Name to print"), the longest text and whether it is required. On a
 * variable product it applies to the variations ticked "Buyer types a text" (for example the
 * "Custom name: Yes" variations, which carry the extra price themselves). sudemi asks the buyer
 * for the text and sends it with the order; it is written on the order line, where the shop
 * reads it when making the item.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Personalisation {

	const META_LABEL     = '_sudemi_personalisation_label';
	const META_MAX       = '_sudemi_personalisation_max';
	const META_REQUIRED  = '_sudemi_personalisation_required';
	const META_VARIATION = '_sudemi_personalisation';

	const FIELD_LABEL     = 'sudemi_personalisation_label';
	const FIELD_MAX       = 'sudemi_personalisation_max';
	const FIELD_REQUIRED  = 'sudemi_personalisation_required';
	const FIELD_VARIATION = 'sudemi_personalisation';

	/** The key of the one text this plugin asks for. */
	const KEY = 'text';

	const DEFAULT_MAX = 40;
	const LIMIT_MAX   = 500;

	public static function boot() {
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ), 20, 1 );
		add_action( 'woocommerce_variation_options', array( __CLASS__, 'render_variation' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
	}

	/**
	 * What sudemi asks the buyer for, for one listing: [{key, label, required, maxLength,
	 * priceMinor}], or an empty list. From the shop's add-on plugin (Sudemi_Addons: every listing
	 * of the product, each with its add-on price) and from this plugin's own setting (for a
	 * variation only when it is ticked; its price is 0, because the extra price is the price of
	 * the variation that carries it).
	 *
	 * @param WC_Product      $product The product or variation.
	 * @param WC_Product|null $parent_product  The variable product of a variation.
	 */
	public static function for_listing( $product, $parent_product ) {
		$base   = $parent_product ? $parent_product : $product;
		$fields = array();
		foreach ( Sudemi_Addons::for_product( $base ) as $addon ) {
			$fields[] = self::field( $addon['key'], $addon['label'], $addon['max'], $addon['required'], self::addon_minor( $product, $addon ) );
		}
		$label = trim( (string) get_post_meta( (int) $base->get_id(), self::META_LABEL, true ) );
		if ( '' !== $label && ( ! $parent_product || 'yes' === get_post_meta( (int) $product->get_id(), self::META_VARIATION, true ) ) ) {
			$fields[] = self::field(
				self::KEY,
				$label,
				get_post_meta( (int) $base->get_id(), self::META_MAX, true ),
				'yes' === get_post_meta( (int) $base->get_id(), self::META_REQUIRED, true ),
				0
			);
		}
		return $fields;
	}

	/** An add-on's price in minor units, with tax as the shop shows its prices. */
	private static function addon_minor( $product, array $addon ) {
		$amount = (float) $addon['price'];
		if ( $addon['percent'] > 0 ) {
			$amount = (float) $product->get_price() * (float) $addon['percent'] / 100;
		}
		if ( $amount <= 0 ) {
			return 0;
		}
		$with_tax = wc_get_price_including_tax(
			$product,
			array(
				'qty'   => 1,
				'price' => $amount,
			)
		);
		return (int) Sudemi_Currency::to_minor( $with_tax, Sudemi_Currency::shop_currency() );
	}

	/** One entry of a listing's personalisation, as sudemi takes it. Pure. */
	public static function field( $key, $label, $max, $required, $price_minor ) {
		return array(
			'key'        => (string) $key,
			'label'      => (string) $label,
			'required'   => (bool) $required,
			'maxLength'  => self::max_length( $max ),
			'priceMinor' => max( 0, (int) $price_minor ),
		);
	}

	/** A stored or typed longest length, within 1 and LIMIT_MAX; DEFAULT_MAX when unset. Pure. */
	public static function max_length( $value ) {
		$n = is_numeric( $value ) ? (int) $value : 0;
		if ( $n <= 0 ) {
			return self::DEFAULT_MAX;
		}
		return min( self::LIMIT_MAX, $n );
	}

	/**
	 * The texts the buyer typed for an order line, key => [label, value], from sudemi's line
	 * personalisation [{key, label, value}]. Empty values are left out; control characters
	 * become spaces. Pure.
	 */
	public static function from_line( array $line ) {
		$out = array();
		if ( empty( $line['personalisation'] ) || ! is_array( $line['personalisation'] ) ) {
			return $out;
		}
		foreach ( $line['personalisation'] as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['value'] ) || ! is_scalar( $entry['value'] ) ) {
				continue;
			}
			$value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $entry['value'] ) );
			if ( '' === $value ) {
				continue;
			}
			$key   = isset( $entry['key'] ) && is_scalar( $entry['key'] ) && '' !== trim( (string) $entry['key'] ) ? trim( (string) $entry['key'] ) : self::KEY;
			$label = isset( $entry['label'] ) && is_scalar( $entry['label'] ) && '' !== trim( (string) $entry['label'] ) ? trim( (string) $entry['label'] ) : $key;
			$label = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $label ) );
			if ( function_exists( 'mb_substr' ) ) {
				$value = mb_substr( $value, 0, self::LIMIT_MAX );
				$label = mb_substr( $label, 0, 100 );
			}
			$out[ $key ] = array(
				'label' => $label,
				'value' => $value,
			);
		}
		return $out;
	}

	/**
	 * The order item meta for what the buyer typed: label => value, the label as the shop named
	 * the field (the add-on plugins show a typed text on the order line under the field's name
	 * the same way). Pure.
	 *
	 * @param array $fields What the listing asks for (for_listing()).
	 * @param array $typed  What the buyer typed (from_line()).
	 */
	public static function item_meta( array $fields, array $typed ) {
		$labels = array();
		foreach ( $fields as $field ) {
			$labels[ $field['key'] ] = $field['label'];
		}
		$out = array();
		foreach ( $typed as $key => $entry ) {
			$out[ isset( $labels[ $key ] ) && '' !== $labels[ $key ] ? $labels[ $key ] : $entry['label'] ] = $entry['value'];
		}
		return $out;
	}

	/**
	 * The first required text a line lacks (its label), or null. Pure.
	 *
	 * @param array $fields What the listing asks for (for_listing()).
	 * @param array $typed  What the buyer typed (from_line()).
	 */
	public static function missing( array $fields, array $typed ) {
		foreach ( $fields as $field ) {
			if ( ! empty( $field['required'] ) && ! isset( $typed[ $field['key'] ] ) ) {
				return $field['label'];
			}
		}
		return null;
	}

	/** The fields on the product's sudemi tab (Sudemi_Withdrawal::render_panel()). */
	public static function render_fields( $post_id ) {
		echo '</div><div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'          => self::FIELD_LABEL,
				'label'       => __( 'Text the buyer types', 'sudemi-connector' ),
				'value'       => (string) get_post_meta( (int) $post_id, self::META_LABEL, true ),
				'placeholder' => __( 'For example: Name to print', 'sudemi-connector' ),
				'description' => __( 'What sudemi asks the buyer to type, for example a name printed on a shirt. Leave empty when the buyer types nothing. On a variable product it applies to the variations ticked "Buyer types a text".', 'sudemi-connector' ),
				'desc_tip'    => false,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::FIELD_MAX,
				'label'             => __( 'Longest text (characters)', 'sudemi-connector' ),
				'type'              => 'number',
				'value'             => (string) self::max_length( get_post_meta( (int) $post_id, self::META_MAX, true ) ),
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => (string) self::LIMIT_MAX,
					'step' => '1',
				),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => self::FIELD_REQUIRED,
				'label'       => __( 'Text required', 'sudemi-connector' ),
				'value'       => 'yes' === get_post_meta( (int) $post_id, self::META_REQUIRED, true ) ? 'yes' : 'no',
				'description' => __( 'The buyer cannot order without typing it.', 'sudemi-connector' ),
			)
		);
	}

	/**
	 * Runs inside WooCommerce's own product save, with the same nonce and capability checks as
	 * its meta boxes.
	 *
	 * @param int $post_id The product being saved.
	 */
	public static function save( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! isset( $_POST['woocommerce_meta_nonce'], $_POST[ self::FIELD_LABEL ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' )
			|| ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		$before = self::stored( $post_id );
		$label  = sanitize_text_field( wp_unslash( $_POST[ self::FIELD_LABEL ] ) );
		$max    = isset( $_POST[ self::FIELD_MAX ] ) ? self::max_length( sanitize_text_field( wp_unslash( $_POST[ self::FIELD_MAX ] ) ) ) : self::DEFAULT_MAX;
		if ( '' === $label ) {
			delete_post_meta( $post_id, self::META_LABEL );
		} else {
			update_post_meta( $post_id, self::META_LABEL, $label );
		}
		update_post_meta( $post_id, self::META_MAX, $max );
		update_post_meta( $post_id, self::META_REQUIRED, isset( $_POST[ self::FIELD_REQUIRED ] ) ? 'yes' : 'no' );
		$after = self::stored( $post_id );
		if ( $before !== $after && Sudemi_Client::is_connected() ) {
			// The price push on a save carries price and stock only: a full sync carries this.
			Sudemi_Sync::schedule_soon();
		}
	}

	private static function stored( $post_id ) {
		return array(
			(string) get_post_meta( $post_id, self::META_LABEL, true ),
			(string) get_post_meta( $post_id, self::META_MAX, true ),
			(string) get_post_meta( $post_id, self::META_REQUIRED, true ),
		);
	}

	/** The "Buyer types a text" box among a variation's options (Enabled, Virtual ...). */
	public static function render_variation( $loop, $variation_data, $variation ) {
		$checked = isset( $variation->ID ) && 'yes' === get_post_meta( (int) $variation->ID, self::META_VARIATION, true );
		echo '<label class="tips" data-tip="' . esc_attr__( 'sudemi asks the buyer for the text set on the product\'s sudemi tab.', 'sudemi-connector' ) . '">'
			. esc_html__( 'Buyer types a text', 'sudemi-connector' )
			. ' <input type="checkbox" class="checkbox" name="' . esc_attr( self::FIELD_VARIATION . '[' . (int) $loop . ']' ) . '" ' . checked( $checked, true, false ) . ' /></label>';
	}

	/**
	 * Saved with the variations: by WooCommerce's "Save changes" (its save-variations nonce) or
	 * with the product (its meta box nonce).
	 *
	 * @param int $variation_id The variation.
	 * @param int $i            Its row in the form.
	 */
	public static function save_variation( $variation_id, $i ) {
		$nonce_ok = ( isset( $_POST['security'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['security'] ) ), 'save-variations' ) )
			|| ( isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) );
		if ( ! $nonce_ok || ! current_user_can( 'edit_product', (int) $variation_id ) ) {
			return;
		}
		$on = isset( $_POST[ self::FIELD_VARIATION ][ (int) $i ] );
		update_post_meta( (int) $variation_id, self::META_VARIATION, $on ? 'yes' : 'no' );
	}
}
