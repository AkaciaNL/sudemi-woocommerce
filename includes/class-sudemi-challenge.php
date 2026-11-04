<?php
/**
 * Serves the ownership challenge at /.well-known/sudemi-challenge.
 *
 * This is how sudemi learns that whoever started the pairing actually controls this shop. It is
 * the only thing standing between a stranger typing someone else's domain and listing products
 * under their name, so it answers with the token and nothing else: no HTML, no theme, no
 * redirect that could be pointed somewhere friendlier.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Challenge {

	const OPTION_TOKEN = 'sudemi_challenge_token';
	const PATH         = '/.well-known/sudemi-challenge';

	public static function boot() {
		// `parse_request` rather than a rewrite rule: a rewrite needs flushing on activation and
		// silently stops working when another plugin flushes afterwards. This path is checked
		// before WordPress decides the request is a 404.
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve' ) );
	}

	public static function store_token( $token ) {
		update_option( self::OPTION_TOKEN, (string) $token, false );
	}

	public static function forget() {
		delete_option( self::OPTION_TOKEN );
	}

	public static function maybe_serve() {
		$requested = isset( $_SERVER['REQUEST_URI'] )
			? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH )
			: '';

		if ( self::PATH !== rtrim( (string) $requested, '/' ) ) {
			return;
		}

		$token = get_option( self::OPTION_TOKEN );
		if ( ! is_string( $token ) || '' === $token ) {
			// Nothing to prove. A 404 rather than an empty 200, so a half-configured shop does
			// not read as a shop that failed verification.
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'no pairing in progress';
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		// Not cacheable: the token changes with each pairing, and a cached old one fails the
		// check in a way that looks like the shop is unreachable.
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( $token );
		exit;
	}
}
