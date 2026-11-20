<?php
/**
 * The photos of one listing, in the shop's order (the main photo first).
 *
 * Images are sent as absolute URLs, never as bytes. Any public https address is accepted, a CDN
 * included: sudemi fetches each one through its egress guard (public addresses only) and shows
 * it to buyers through its own signed image proxy.
 *
 * A variation's own gallery is read from WooCommerce itself (its variation gallery, WooCommerce
 * 11.1+) and from the popular add-ons, each read from its source:
 * - WooCommerce Additional Variation Images: meta _wc_additional_variation_images, attachment
 *   ids separated by commas;
 * - Variation Images Gallery for WooCommerce (Emran Ahmed, Woo Variation Gallery): meta
 *   woo_variation_gallery_images, an array of attachment ids (WooCommerce's own gallery
 *   from 11.1, where the meta is no longer kept up to date);
 * - Variation Images Gallery for WooCommerce (RadiusTheme): meta rtwpvg_images, an array of
 *   attachment ids (WooCommerce's own gallery once _rtwpvg_gallery_migrated is set);
 * - WooThumbs (Iconic): meta variation_image_gallery, ids separated by commas;
 * - Smart Variations Images: meta _product_image_gallery on the variation, ids separated by
 *   commas.
 *
 * A variation with its own gallery shows only its own photos, as these add-ons do on the
 * storefront (a black pot is not shown with the white pot's photos); one without keeps its own
 * main photo (or the product's) and the product's gallery.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Images {

	/** At most this many photos per listing. */
	const MAX = 50;

	/** Add-on gallery meta on a variation, in the order they are read. */
	const GALLERY_META = array(
		'_wc_additional_variation_images',
		'woo_variation_gallery_images',
		'rtwpvg_images',
		'variation_image_gallery',
		'_product_image_gallery',
	);

	/** Set by RadiusTheme when WooCommerce's own gallery holds the variation's photos. */
	const RTWPVG_MIGRATED = '_rtwpvg_gallery_migrated';

	/** WooCommerce keeps a gallery per variation itself from this version. */
	const NATIVE_GALLERY_WC_VERSION = '11.1.0';

	/** Attachment ids from a meta value in any form: "1,2, 3", array(1, '2'), 4. */
	public static function ids( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			$value = array( $value );
		}
		$out = array();
		foreach ( $value as $id ) {
			if ( is_int( $id ) || ( is_string( $id ) && ctype_digit( trim( $id ) ) ) ) {
				$id = (int) $id;
				if ( $id > 0 && ! in_array( $id, $out, true ) ) {
					$out[] = $id;
				}
			}
		}
		return $out;
	}

	/**
	 * A variation's own gallery: WooCommerce's own when it holds photos, otherwise the add-ons'
	 * (their meta that WooCommerce's gallery has replaced is left out, so a photo the shop
	 * removed is not shown again).
	 *
	 * @param array $native           WooCommerce's own variation gallery (ids).
	 * @param array $meta             Meta key => stored value, for GALLERY_META.
	 * @param bool  $native_supported WooCommerce keeps variation galleries itself (11.1+).
	 * @param bool  $rtwpvg_migrated  RadiusTheme moved this variation to WooCommerce's gallery.
	 */
	public static function gallery_ids( array $native, array $meta, $native_supported, $rtwpvg_migrated ) {
		$native = self::ids( $native );
		if ( ! empty( $native ) ) {
			return $native;
		}
		$out = array();
		foreach ( self::GALLERY_META as $key ) {
			if ( ! isset( $meta[ $key ] ) ) {
				continue;
			}
			if ( 'woo_variation_gallery_images' === $key && $native_supported ) {
				continue;
			}
			if ( 'rtwpvg_images' === $key && $rtwpvg_migrated ) {
				continue;
			}
			$out = array_merge( $out, self::ids( $meta[ $key ] ) );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The photo ids of one listing, the main photo first.
	 *
	 * @param int   $own_main          The variation's own main photo (0 = none), or the product's.
	 * @param int   $parent_main       The product's main photo (0 for a simple product).
	 * @param array $variation_gallery The variation's own gallery (gallery_ids()).
	 * @param array $parent_gallery    The product's gallery.
	 */
	public static function listing_ids( $own_main, $parent_main, array $variation_gallery, array $parent_gallery ) {
		if ( ! empty( $variation_gallery ) ) {
			$ids = array_merge( array( $own_main ), $variation_gallery );
		} else {
			$ids = array_merge( array( $own_main ? $own_main : $parent_main ), $parent_gallery );
		}
		return self::ids( $ids );
	}

	/**
	 * The URLs sudemi gets: in order, each once, http upgraded to https on a shop served over
	 * https, at most MAX.
	 *
	 * @param array    $ids        Attachment ids.
	 * @param callable $url_of     Id => URL (or false).
	 * @param bool     $site_https The shop's own address is https.
	 * @return array [urls, number left out by the cap]
	 */
	public static function urls( array $ids, $url_of, $site_https ) {
		$out = array();
		foreach ( $ids as $id ) {
			$url = call_user_func( $url_of, $id );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			if ( $site_https && 0 === stripos( $url, 'http://' ) ) {
				$url = 'https://' . substr( $url, 7 );
			}
			if ( ! in_array( $url, $out, true ) ) {
				$out[] = $url;
			}
		}
		return array( array_slice( $out, 0, self::MAX ), max( 0, count( $out ) - self::MAX ) );
	}

	/**
	 * The photos of a product or variation as sudemi takes them.
	 *
	 * @param WC_Product      $product        The product or variation.
	 * @param WC_Product|null $parent_product The variable product of a variation.
	 * @return array [urls, number left out by the cap]
	 */
	public static function for_product( $product, $parent_product ) {
		$base = $parent_product ? $parent_product : $product;
		$own_gallery = array();
		if ( $parent_product ) {
			$variation_id = (int) $product->get_id();
			$meta         = array();
			foreach ( self::GALLERY_META as $key ) {
				$meta[ $key ] = get_post_meta( $variation_id, $key, true );
			}
			$native_supported = defined( 'WC_VERSION' ) && version_compare( WC_VERSION, self::NATIVE_GALLERY_WC_VERSION, '>=' );
			// The stored gallery ('edit'): RadiusTheme empties the 'view' one on the storefront.
			$native      = method_exists( $product, 'get_gallery_image_ids' ) ? (array) $product->get_gallery_image_ids( 'edit' ) : array();
			$own_gallery = self::gallery_ids( $native, $meta, $native_supported, metadata_exists( 'post', $variation_id, self::RTWPVG_MIGRATED ) );
		}
		// A variation's own main photo as stored ('edit'): in 'view' WooCommerce gives the product's.
		$ids = self::listing_ids(
			(int) ( $parent_product ? $product->get_image_id( 'edit' ) : $product->get_image_id() ),
			$parent_product ? (int) $parent_product->get_image_id() : 0,
			$own_gallery,
			(array) $base->get_gallery_image_ids()
		);
		$site_https = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
		return self::urls( $ids, 'wp_get_attachment_url', $site_https );
	}
}
