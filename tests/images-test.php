<?php
/**
 * 1.4.6: the photos of a listing. A variation's own gallery from WooCommerce (11.1+) or a
 * variation gallery add-on (Additional Variation Images, Woo Variation Gallery, RadiusTheme,
 * WooThumbs, Smart Variations Images) in any stored form; a variation with its own gallery shows
 * only its own photos, one without keeps the product's; each URL once, http to https on an
 * https shop, at most 50.
 *
 * Runs without WordPress.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.NamingConventions.PrefixAllGlobals,WordPress.PHP.DevelopmentFunctions -- a command-line test (plain text output); left out of the release zip.

require __DIR__ . '/../includes/class-sudemi-images.php';

$failures = 0;
function expect( $label, $actual, $expected ) {
	global $failures;
	if ( $actual === $expected ) {
		echo "ok   $label\n";
		return;
	}
	++$failures;
	echo "FAIL $label: got " . var_export( $actual, true ) . ', expected ' . var_export( $expected, true ) . "\n";
}

// Stored forms.
expect( 'comma-separated ids (Additional Variation Images)', Sudemi_Images::ids( '12,13, 14,' ), array( 12, 13, 14 ) );
expect( 'an array of ids, strings and ints (Woo Variation Gallery, RadiusTheme)', Sudemi_Images::ids( array( '21', 22, '21', 0, 'x', '' ) ), array( 21, 22 ) );
expect( 'one id', Sudemi_Images::ids( 7 ), array( 7 ) );
expect( 'nothing stored', Sudemi_Images::ids( '' ), array() );
expect( 'not stored at all (get_post_meta false)', Sudemi_Images::ids( false ), array() );

// A variation's own gallery.
expect( 'Additional Variation Images', Sudemi_Images::gallery_ids( array(), array( '_wc_additional_variation_images' => '31,32' ), false, false ), array( 31, 32 ) );
expect( 'Woo Variation Gallery before WooCommerce 11.1', Sudemi_Images::gallery_ids( array(), array( 'woo_variation_gallery_images' => array( 41, 42 ) ), false, false ), array( 41, 42 ) );
expect( 'Woo Variation Gallery meta is stale from WooCommerce 11.1', Sudemi_Images::gallery_ids( array(), array( 'woo_variation_gallery_images' => array( 41, 42 ) ), true, false ), array() );
expect( 'RadiusTheme', Sudemi_Images::gallery_ids( array(), array( 'rtwpvg_images' => array( '51', '52' ) ), true, false ), array( 51, 52 ) );
expect( 'RadiusTheme meta is stale once migrated', Sudemi_Images::gallery_ids( array(), array( 'rtwpvg_images' => array( 51 ) ), true, true ), array() );
expect( 'WooThumbs and Smart Variations Images', Sudemi_Images::gallery_ids( array(), array( 'variation_image_gallery' => '61', '_product_image_gallery' => '62,61' ), false, false ), array( 61, 62 ) );
expect( 'several add-ons: in order, each once', Sudemi_Images::gallery_ids( array(), array( '_wc_additional_variation_images' => '31,41', 'woo_variation_gallery_images' => array( 41, 42 ), 'rtwpvg_images' => '' ), false, false ), array( 31, 41, 42 ) );
expect( "WooCommerce's own gallery wins", Sudemi_Images::gallery_ids( array( 71, 72 ), array( '_wc_additional_variation_images' => '31' ), true, false ), array( 71, 72 ) );

// The listing's photo ids.
expect( 'simple product: main, then gallery', Sudemi_Images::listing_ids( 1, 0, array(), array( 2, 3, 1 ) ), array( 1, 2, 3 ) );
expect( 'variation without own photo: product main and gallery', Sudemi_Images::listing_ids( 0, 1, array(), array( 2, 3 ) ), array( 1, 2, 3 ) );
expect( 'variation with own main photo only: it first, then the product gallery', Sudemi_Images::listing_ids( 9, 1, array(), array( 2, 3 ) ), array( 9, 2, 3 ) );
expect( 'variation with own gallery: only its own photos', Sudemi_Images::listing_ids( 9, 1, array( 31, 32, 9 ), array( 2, 3 ) ), array( 9, 31, 32 ) );
expect( 'variation with own gallery, no own main photo: not the product photos', Sudemi_Images::listing_ids( 0, 1, array( 31 ), array( 2 ) ), array( 31 ) );

// URLs.
$url_of = function ( $id ) {
	$map = array(
		1 => 'http://shop.example.eu/wp-content/uploads/a.jpg',
		2 => 'https://cdn.example.net/b.jpg',
		3 => false,
		4 => 'https://cdn.example.net/b.jpg',
		5 => 'HTTP://shop.example.eu/c.jpg',
	);
	return isset( $map[ $id ] ) ? $map[ $id ] : 'https://cdn.example.net/' . $id . '.jpg';
};
expect( 'https shop: http upgraded, CDN kept, missing skipped, each once', Sudemi_Images::urls( array( 1, 2, 3, 4, 5 ), $url_of, true ), array( array( 'https://shop.example.eu/wp-content/uploads/a.jpg', 'https://cdn.example.net/b.jpg', 'https://shop.example.eu/c.jpg' ), 0 ) );
expect( 'http shop: left as it is', Sudemi_Images::urls( array( 1 ), $url_of, false ), array( array( 'http://shop.example.eu/wp-content/uploads/a.jpg' ), 0 ) );
list( $urls, $left ) = Sudemi_Images::urls( range( 100, 160 ), $url_of, true );
expect( 'at most 50', count( $urls ), 50 );
expect( 'the first 50, in order', $urls[49], 'https://cdn.example.net/149.jpg' );
expect( 'the number left out', $left, 11 );

echo 0 === $failures ? "\nall passed\n" : "\n$failures failed\n";
exit( $failures ? 1 : 0 );
