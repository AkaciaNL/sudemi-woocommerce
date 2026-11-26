<?php
/**
 * 1.4.12 (sudemi owner decision 2026-11-26): the product description is sent as HTML, so sudemi
 * keeps its paragraphs, lists and headings (sudemi removes everything outside its safe subset
 * itself). Shortcodes and block editor comments are taken out; the classic editor's blank lines
 * become paragraphs through WordPress's own wpautop.
 *
 * Runs without WordPress: strip_shortcodes and wpautop are stand-ins that record their calls.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.NamingConventions.PrefixAllGlobals,WordPress.PHP.DevelopmentFunctions -- a command-line test (plain text output); left out of the release zip.

$calls = array();
function strip_shortcodes( $content ) {
	global $calls;
	$calls[] = 'strip_shortcodes';
	return preg_replace( '/\[\/?gallery[^\]]*\]/', '', $content );
}
function wpautop( $content ) {
	global $calls;
	$calls[] = 'wpautop';
	if ( preg_match( '/<p[\s>]/', $content ) ) {
		return $content;
	}
	$paragraphs = preg_split( '/\n\s*\n/', trim( $content ) );
	return '<p>' . implode( "</p>\n<p>", array_map( static function ( $p ) { return str_replace( "\n", "<br />\n", $p ); }, $paragraphs ) ) . "</p>\n";
}

require __DIR__ . '/../includes/class-sudemi-product-data.php';

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

$sudemi_version = '';
foreach ( file( __DIR__ . '/../sudemi-connector.php' ) as $line ) {
	if ( preg_match( "/define\\( 'SUDEMI_VERSION', '([0-9.]+)' \\)/", $line, $m ) ) {
		$sudemi_version = $m[1];
	}
}
expect( 'the plugin version is 1.4.12', $sudemi_version, '1.4.12' );
$readme = (string) file_get_contents( __DIR__ . '/../readme.txt' );
expect( 'readme.txt: stable tag 1.4.12', 1 === preg_match( '/^Stable tag: 1\.4\.12$/m', $readme ), true );
expect( 'readme.txt: an upgrade notice and a changelog entry for 1.4.12', 2 === substr_count( $readme, '= 1.4.12 =' ), true );
$header = (string) file_get_contents( __DIR__ . '/../sudemi-connector.php' );
expect( 'plugin header: Version 1.4.12', 1 === preg_match( '/^ \* Version:\s+1\.4\.12$/m', $header ), true );

$html = '<h2>Care</h2><p>Wash <strong>cold</strong>.</p><ul><li>Wool</li><li>Nylon</li></ul>';
expect( 'HTML is sent as HTML (lists and headings are no longer stripped)', Sudemi_Product_Data::description( $html ), $html );
expect( 'block editor comments are taken out', Sudemi_Product_Data::description( "<!-- wp:paragraph -->\n<p>Warm socks.</p>\n<!-- /wp:paragraph -->" ), '<p>Warm socks.</p>' );
expect( 'classic editor text: blank lines become paragraphs (wpautop)', Sudemi_Product_Data::description( "Warm socks.\n\nThis pair\nlasts." ), "<p>Warm socks.</p>\n<p>This pair<br />\nlasts.</p>" );
expect( 'shortcodes are taken out', Sudemi_Product_Data::description( '<p>Look[gallery ids="1,2"]</p>' ), '<p>Look</p>' );
expect( 'strip_shortcodes then wpautop', array_slice( $calls, -2 ), array( 'strip_shortcodes', 'wpautop' ) );
expect( 'an empty description stays empty', Sudemi_Product_Data::description( "  \n " ), '' );
expect( 'null is empty', Sudemi_Product_Data::description( null ), '' );
// What sudemi removes (scripts, attributes, links) it removes itself; the plugin does not hide it.
expect( 'nothing else is changed here (sudemi sanitises)', Sudemi_Product_Data::description( '<p onclick="x">a <a href="https://x">b</a></p>' ), '<p onclick="x">a <a href="https://x">b</a></p>' );

$sync = (string) file_get_contents( __DIR__ . '/../includes/class-sudemi-sync.php' );
expect( 'the listing sends the description through description() (no wp_strip_all_tags)',
	1 === preg_match( "/'description' => Sudemi_Product_Data::description\\(/", $sync ) && false === strpos( $sync, "'description' => wp_strip_all_tags" ), true );

echo 0 === $failures ? "\nALL PASS\n" : "\n$failures FAILED\n";
exit( 0 === $failures ? 0 : 1 );
