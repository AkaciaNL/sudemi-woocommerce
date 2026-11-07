<?php
/**
 * Turning this shop's catalogue into what sudemi stores.
 *
 * Two paths, for two different costs. A **full sync** walks every published product and ends
 * with a commit, which is the only thing that may delist: a sync that dies halfway through is
 * simply never committed, and nothing disappears from the shop's listings because a cron job
 * timed out. A **delta** carries a price or a stock change for one product and runs from
 * WooCommerce's own save hook, which is what makes the shop's prices and sudemi's agree within
 * seconds rather than within an import cycle.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Sync {

	const CRON_FULL   = 'sudemi_full_sync';
	const BATCH_SIZE  = 100;

	public static function boot() {
		add_action( self::CRON_FULL, array( __CLASS__, 'run_full' ) );
		// Price and stock, as they change. Guarded inside so an unconnected shop does nothing.
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_changed' ), 10, 1 );
		// Shipping prices come from the shipping zones: a change there re-sends the catalogue.
		foreach ( array( 'woocommerce_after_shipping_zone_object_save', 'woocommerce_shipping_zone_method_added', 'woocommerce_shipping_zone_method_deleted', 'woocommerce_shipping_zone_method_status_toggled', 'woocommerce_update_options_shipping' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'schedule_soon' ) );
		}
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_product_object_changed' ), 10, 1 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_product_changed' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_product_object_changed' ), 10, 1 );
	}

	/** A full sync shortly, once, however many changes trigger it. */
	public static function schedule_soon() {
		if ( ! wp_next_scheduled( self::CRON_FULL ) || wp_next_scheduled( self::CRON_FULL ) > time() + 120 ) {
			wp_schedule_single_event( time() + 60, self::CRON_FULL );
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_FULL ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_FULL );
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_FULL );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_FULL );
			$timestamp = wp_next_scheduled( self::CRON_FULL );
		}
	}

	/** A full pass: shop metadata, the category tree, then every product, then commit. */
	public static function run_full() {
		if ( ! Sudemi_Client::is_connected() ) {
			return;
		}

		$started = Sudemi_Client::post_signed( '/integration/v1/sync', array() );
		if ( is_wp_error( $started ) || 200 !== $started['status'] || empty( $started['body']['sync_id'] ) ) {
			Sudemi_Logger::warn( 'Could not start a catalogue sync.' );
			return;
		}
		$sync_id = (string) $started['body']['sync_id'];

		self::push_shop();
		self::push_categories();

		$page  = 1;
		$count = 0;
		do {
			$ids = self::product_ids( $page );
			if ( empty( $ids ) ) {
				break;
			}
			$products = array();
			foreach ( $ids as $id ) {
				$payload = self::product_payload( $id );
				if ( null !== $payload ) {
					$products[] = $payload;
				}
			}
			if ( ! empty( $products ) ) {
				$sent = Sudemi_Client::post_signed(
					'/integration/v1/products',
					array(
						'syncId'   => $sync_id,
						'products' => $products,
					)
				);
				if ( is_wp_error( $sent ) || 200 !== $sent['status'] ) {
					// Abandon rather than commit. An uncommitted sync delists nothing.
					Sudemi_Logger::warn( 'A batch failed; the sync was abandoned and nothing was delisted.' );
					return;
				}
				$count += count( $products );
			}
			++$page;
		} while ( true );

		$committed = Sudemi_Client::post_signed( '/integration/v1/sync/' . rawurlencode( $sync_id ) . '/commit', array() );
		if ( is_wp_error( $committed ) || 200 !== $committed['status'] ) {
			Sudemi_Logger::warn( 'The catalogue was sent but the sync could not be committed.' );
			return;
		}
		if ( isset( $committed['body']['status'] ) && 'held' === $committed['body']['status'] ) {
			// Not applied: it would take a large share of this shop's live products off sudemi,
			// which sudemi does not do without a person confirming it.
			Sudemi_Logger::warn(
				sprintf(
					/* translators: 1: products that would be removed, 2: products listed now. */
					__( 'The catalogue sync is on hold: it would remove %1$d of %2$d listed products from sudemi, so nothing was changed. If those products really were removed from this shop, contact sudemi support (support@sudemi.com).', 'sudemi' ),
					isset( $committed['body']['would_delist'] ) ? (int) $committed['body']['would_delist'] : 0,
					isset( $committed['body']['live_products'] ) ? (int) $committed['body']['live_products'] : 0
				)
			);
			return;
		}
		/* translators: %d: number of products. */
		Sudemi_Logger::info( sprintf( __( 'Synced %d products.', 'sudemi' ), $count ) );
	}

	public static function on_product_changed( $product_id ) {
		if ( ! Sudemi_Client::is_connected() ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		self::push_delta( $product );
	}

	public static function on_product_object_changed( $product ) {
		if ( ! Sudemi_Client::is_connected() || ! $product ) {
			return;
		}
		self::push_delta( $product );
	}

	/**
	 * One product's price and stock, on its way out now rather than at the next import.
	 *
	 * This is the call that decides whether sudemi's prices are true, so the failure path matters
	 * more than the success path. It gets one attempt and a short timeout, because it runs while
	 * someone is saving a product in wp-admin and a sudemi outage must not slow that screen or
	 * put an error on it. What it must not do is give up: a change that is dropped here is a
	 * wrong price on a marketplace until the next full sync. So a failure is written to the queue
	 * and the heartbeat sends it a few minutes later.
	 */
	private static function push_delta( $product ) {
		$update = array(
			'externalId' => (string) $product->get_id(),
			'priceMinor' => self::minor( self::price_with_tax( $product ) ),
			'currency'   => get_woocommerce_currency(),
			'inStock'    => (bool) $product->is_in_stock(),
			'quantity'   => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
		);

		// Counted before the attempt, not after a success: the counter is what lets sudemi
		// notice a change that never arrived, so it has to move even when the push fails.
		Sudemi_Heartbeat::note_change();

		$response = Sudemi_Client::post_signed(
			'/integration/v1/products/prices',
			array( 'updates' => array( $update ) )
		);

		if ( is_wp_error( $response ) || 200 !== $response['status'] ) {
			Sudemi_Queue::enqueue( $update );
			Sudemi_Logger::warn( 'A price update could not be sent; it is queued for retry.' );
			return;
		}

		// Accepted, but for a product sudemi has never been sent. The full sync it asks for is
		// scheduled rather than run here, for the same reason as above.
		if ( ! empty( $response['body']['full_sync_requested'] ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_FULL );
		}
	}

	private static function push_shop() {
		Sudemi_Client::post_signed(
			'/integration/v1/shop',
			array(
				'displayName'   => get_bloginfo( 'name' ),
				'description'   => get_bloginfo( 'description' ),
				'languages'     => array( substr( get_locale(), 0, 2 ) ),
				'currency'      => get_woocommerce_currency(),
				'supportEmail'  => get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ),
				'shipsTo'       => self::shipping_countries(),
			)
		);
	}

	private static function push_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return;
		}
		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'externalId'       => (string) $term->term_id,
				'parentExternalId' => $term->parent ? (string) $term->parent : null,
				'name'             => $term->name,
				'description'      => $term->description,
			);
		}
		if ( ! empty( $categories ) ) {
			Sudemi_Client::post_signed( '/integration/v1/categories', array( 'categories' => $categories ) );
		}
	}

	private static function product_ids( $page ) {
		$query = new WP_Query(
			array(
				// Variations are listings of their own (size, colour...): a buyer buys one of them,
				// never the variable "parent", which has no single price, stock or barcode.
				'post_type'              => array( 'product', 'product_variation' ),
				'post_status'            => 'publish',
				'posts_per_page'         => self::BATCH_SIZE,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return $query->posts;
	}

	/**
	 * One product, as sudemi stores it.
	 *
	 * Images are sent as URLs on this shop's own origin, never as bytes: sudemi fetches them
	 * through its egress guard and refuses anything that is not on the verified shop, which is
	 * what stops a payload walking it around an internal network one image at a time.
	 */
	private static function product_payload( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}
		// Not sellable as one item through sudemi: a variable parent (its variations are sent
		// instead), a grouped product (a set of other products) and an external/affiliate link.
		if ( $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
			return null;
		}
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
		if ( $parent ) {
			if ( ! $parent->is_visible() || 'publish' !== $parent->get_status() || ! $product->variation_is_visible() ) {
				return null;
			}
		} elseif ( ! $product->is_visible() ) {
			return null;
		}
		// Categories, brand and the shared attributes live on the parent of a variation.
		$base_id = $parent ? $parent->get_id() : $product_id;
		$base    = $parent ? $parent : $product;

		$images = array();
		$image_ids = array_merge(
			array( $product->get_image_id() ? $product->get_image_id() : $base->get_image_id() ),
			$base->get_gallery_image_ids()
		);
		foreach ( array_filter( $image_ids ) as $image_id ) {
			$url = wp_get_attachment_url( $image_id );
			if ( $url ) {
				$images[] = $url;
			}
		}

		$categories = wp_get_post_terms( $base_id, 'product_cat', array( 'fields' => 'ids' ) );

		$specs = array();
		if ( $parent ) {
			// This variation's own choices (Colour: Red, Size: L) first.
			foreach ( $product->get_variation_attributes( false ) as $taxonomy => $value ) {
				if ( '' === $value ) {
					continue;
				}
				$term = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : null;
				$specs[] = array(
					'name'   => wc_attribute_label( $taxonomy, $parent ),
					'values' => array( $term ? $term->name : (string) $value ),
				);
			}
		}
		foreach ( $base->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ( $parent && $attribute->get_variation() ) ) {
				continue;
			}
			$name = wc_attribute_label( $attribute->get_name() );
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $base_id, $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			if ( ! empty( $values ) ) {
				$specs[] = array(
					'name'   => $name,
					'values' => array_values( array_map( 'strval', $values ) ),
				);
			}
		}

		$payload = array(
			'externalId'  => (string) $product->get_id(),
			'sku'         => $product->get_sku(),
			'title'       => self::title( $product, $parent ),
			'description' => wp_strip_all_tags( $product->get_description() ? $product->get_description() : $base->get_description() ),
			'url'         => $product->get_permalink(),
			'priceMinor'  => self::minor( self::price_with_tax( $product ) ),
			'currency'    => get_woocommerce_currency(),
			'inStock'     => (bool) $product->is_in_stock(),
			'quantity'    => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			'weightGrams' => self::weight_grams( $product->get_weight() ),
			'categoryExternalIds' => array_map( 'strval', is_array( $categories ) ? $categories : array() ),
			'images'      => $images,
			'specs'       => $specs,
			// Without a shipping price for the buyer's country sudemi cannot offer the product.
			'shipping'    => Sudemi_Shipping::rates_for( $product ),
			'maxHandlingTimeDays' => Sudemi_Shipping::handling_days(),
			'maxTransitTimeDays'  => Sudemi_Shipping::transit_days(),
			// WooCommerce's own barcode field (GTIN/EAN/UPC/ISBN, WooCommerce 9.2+): lets sudemi
			// show this shop's offer next to other shops selling the same item.
			'gtin'        => self::gtin( $product ),
			'brand'       => self::brand( $base_id ),
		);
		// Excluded from the right of withdrawal (EU 2011/83 art. 16), with the reason; a
		// variation carries its parent's setting. Omitted when the normal right applies.
		return Sudemi_Withdrawal::apply( $payload, Sudemi_Withdrawal::for_product( $base_id ) );
	}

	/** A variation's title names its options ("T-shirt - Red, L"), so each size reads as itself. */
	private static function title( $product, $parent ) {
		$name = $product->get_name();
		if ( ! $parent || $name !== $parent->get_name() ) {
			return $name;
		}
		$values = array();
		foreach ( $product->get_variation_attributes( false ) as $taxonomy => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : null;
			$values[] = $term ? $term->name : (string) $value;
		}
		return empty( $values ) ? $name : $name . ' - ' . implode( ', ', $values );
	}

	private static function gtin( $product ) {
		if ( ! is_callable( array( $product, 'get_global_unique_id' ) ) ) {
			return null;
		}
		$value = preg_replace( '/[^0-9]/', '', (string) $product->get_global_unique_id() );
		return '' === $value ? null : $value;
	}

	/** WooCommerce's brand taxonomy (9.6+), when the shop uses it. */
	private static function brand( $product_id ) {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return null;
		}
		$names = wp_get_post_terms( $product_id, 'product_brand', array( 'fields' => 'names' ) );
		return is_array( $names ) && ! empty( $names ) ? (string) $names[0] : null;
	}

	/**
	 * The price a buyer pays, with VAT. Shops that enter prices without tax ("prices exclusive of
	 * tax") let WooCommerce add it at checkout; sending get_price() as-is sold their products on
	 * sudemi without the VAT. wc_get_price_including_tax() gives the price for the shop's own
	 * country, as the storefront shows it; for shops that enter prices with tax it is unchanged.
	 */
	private static function price_with_tax( $product ) {
		$price = $product->get_price();
		if ( '' === $price || null === $price ) {
			return $price;
		}
		return wc_get_price_including_tax( $product, array( 'qty' => 1, 'price' => $price ) );
	}

	/** Money in minor units, because a float price is a rounding error waiting for a checkout. */
	private static function minor( $price ) {
		if ( '' === $price || null === $price ) {
			return null;
		}
		return (int) round( (float) $price * 100 );
	}

	private static function weight_grams( $weight ) {
		if ( '' === $weight || null === $weight ) {
			return null;
		}
		$unit   = get_option( 'woocommerce_weight_unit', 'kg' );
		$factor = array(
			'kg'  => 1000,
			'g'   => 1,
			'lbs' => 453.59237,
			'oz'  => 28.349523125,
		);
		$multiplier = isset( $factor[ $unit ] ) ? $factor[ $unit ] : 1000;
		return (int) round( (float) $weight * $multiplier );
	}

	private static function shipping_countries() {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return array();
		}
		$countries = WC()->countries->get_shipping_countries();
		return is_array( $countries ) ? array_keys( $countries ) : array();
	}
}
