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
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_product_object_changed' ), 10, 1 );
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
			'priceMinor' => self::minor( $product->get_price() ),
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
				'post_type'              => 'product',
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
		if ( ! $product || ! $product->is_visible() ) {
			return null;
		}

		$images = array();
		$image_ids = array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() );
		foreach ( array_filter( $image_ids ) as $image_id ) {
			$url = wp_get_attachment_url( $image_id );
			if ( $url ) {
				$images[] = $url;
			}
		}

		$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

		$specs = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			$name = wc_attribute_label( $attribute->get_name() );
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product_id, $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			if ( ! empty( $values ) ) {
				$specs[] = array(
					'name'   => $name,
					'values' => array_values( array_map( 'strval', $values ) ),
				);
			}
		}

		return array(
			'externalId'  => (string) $product->get_id(),
			'sku'         => $product->get_sku(),
			'title'       => $product->get_name(),
			'description' => wp_strip_all_tags( $product->get_description() ),
			'url'         => get_permalink( $product_id ),
			'priceMinor'  => self::minor( $product->get_price() ),
			'currency'    => get_woocommerce_currency(),
			'inStock'     => (bool) $product->is_in_stock(),
			'quantity'    => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			'weightGrams' => self::weight_grams( $product->get_weight() ),
			'categoryExternalIds' => array_map( 'strval', is_array( $categories ) ? $categories : array() ),
			'images'      => $images,
			'specs'       => $specs,
		);
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
