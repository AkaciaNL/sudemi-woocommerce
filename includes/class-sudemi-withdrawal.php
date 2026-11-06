<?php
/**
 * Products excluded from the buyer's right of withdrawal (Directive 2011/83/EU, art. 16).
 *
 * The merchant marks a product with the legal reason it is excluded; sudemi tells the buyer
 * before they pay. Nothing is excluded by default, and only the reasons the directive itself
 * lists can be chosen: a free-text reason would let anything through, and a buyer who was told
 * "no withdrawal" without a lawful reason keeps the right anyway.
 *
 * A variation has no setting of its own: it is the same product in another size or colour, so
 * it carries its parent's.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Withdrawal {

	const META  = '_sudemi_withdrawal_exclusion';
	const FIELD = 'sudemi_withdrawal_exclusion';

	/** The art. 16 exceptions sudemi accepts, as sent in the product payload. */
	const REASONS = array( 'hygiene', 'custom', 'perishable', 'media', 'press', 'mixed', 'alcohol' );

	public static function boot() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ), 20, 1 );
	}

	/**
	 * One of REASONS, or null for "normal right of withdrawal". Anything else is null: an unknown
	 * value must never turn into an exclusion.
	 *
	 * @param mixed $value raw input.
	 * @return string|null
	 */
	public static function sanitize( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = strtolower( trim( $value ) );
		return in_array( $value, self::REASONS, true ) ? $value : null;
	}

	/**
	 * The product payload with the exclusion added when there is one; without the key otherwise.
	 *
	 * @param array       $payload one product, as sent to /integration/v1/products.
	 * @param string|null $reason  the stored value (sanitised again here).
	 * @return array
	 */
	public static function apply( array $payload, $reason ) {
		$reason = self::sanitize( $reason );
		if ( null !== $reason ) {
			$payload['withdrawalExclusion'] = $reason;
		}
		return $payload;
	}

	/** The stored exclusion of a product (for a variation: of its parent). */
	public static function for_product( $product_id ) {
		return self::sanitize( get_post_meta( (int) $product_id, self::META, true ) );
	}

	/** reason => label, translated. */
	public static function labels() {
		return array(
			''           => __( 'None — normal right of withdrawal applies', 'sudemi' ),
			'hygiene'    => __( 'Sealed for health or hygiene reasons (not returnable once unsealed)', 'sudemi' ),
			'custom'     => __( 'Made to the buyer’s specifications or personalised', 'sudemi' ),
			'perishable' => __( 'Perishable or quickly expiring', 'sudemi' ),
			'media'      => __( 'Sealed audio, video or software (not returnable once unsealed)', 'sudemi' ),
			'press'      => __( 'Newspaper, periodical or magazine', 'sudemi' ),
			'mixed'      => __( 'Inseparably mixed with other items after delivery', 'sudemi' ),
			'alcohol'    => __( 'Alcohol with a market-dependent price, delivered after 30+ days', 'sudemi' ),
		);
	}

	public static function add_tab( $tabs ) {
		$tabs['sudemi'] = array(
			'label'    => __( 'sudemi', 'sudemi' ),
			'target'   => 'sudemi_product_data',
			// Grouped and external products are never sent to sudemi.
			'class'    => array( 'hide_if_grouped', 'hide_if_external' ),
			'priority' => 85,
		);
		return $tabs;
	}

	public static function render_panel() {
		global $post;
		$current = $post ? (string) self::for_product( $post->ID ) : '';
		echo '<div id="sudemi_product_data" class="panel woocommerce_options_panel hidden"><div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'          => self::FIELD,
				'label'       => __( 'Right of withdrawal', 'sudemi' ),
				'options'     => self::labels(),
				'value'       => $current,
				'description' => __( 'Only for products that legally fall under an exception. Buyers see it before paying; misuse is not allowed.', 'sudemi' ),
				'desc_tip'    => false,
			)
		);
		echo '</div></div>';
	}

	/**
	 * Runs inside WooCommerce's own product save. Checked again here rather than trusted: the
	 * same nonce and capability WooCommerce's meta boxes require.
	 *
	 * @param int $post_id the product being saved.
	 */
	public static function save( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! isset( $_POST['woocommerce_meta_nonce'], $_POST[ self::FIELD ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' )
			|| ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		$reason   = self::sanitize( sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) );
		$previous = self::for_product( $post_id );
		if ( null === $reason ) {
			delete_post_meta( $post_id, self::META );
		} else {
			update_post_meta( $post_id, self::META, $reason );
		}
		// The price push that runs on every save carries price and stock only, so a changed
		// exclusion goes out with a full sync, scheduled a minute ahead (one for many saves).
		if ( $previous !== $reason && Sudemi_Client::is_connected() ) {
			Sudemi_Sync::schedule_soon();
		}
	}
}
