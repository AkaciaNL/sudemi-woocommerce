<?php
/**
 * Every call this shop makes to sudemi, signed.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Client {

	const OPTION_INSTALL_ID = 'sudemi_install_id';
	const OPTION_API_BASE   = 'sudemi_api_base';

	const DEFAULT_API_BASE = 'https://api.sudemi.com';

	/** Long enough for a slow upstream, short enough that a stalled call cannot hold a cron slot. */
	const TIMEOUT_SECONDS = 20;

	/**
	 * How many times to wait when sudemi says this shop is pushing too fast.
	 *
	 * Two, because the window is a minute: a shop that is still being throttled after two waits
	 * is sending far more than the limit and should stop and resume on its next run rather than
	 * hold a cron slot open.
	 */
	const RATE_LIMIT_RETRIES = 2;

	/** A cap, so a strange Retry-After cannot park a cron run for an hour. */
	const RATE_LIMIT_MAX_WAIT = 90;

	public static function api_base() {
		$configured = get_option( self::OPTION_API_BASE );
		$base       = is_string( $configured ) && '' !== $configured ? $configured : self::DEFAULT_API_BASE;
		return untrailingslashit( $base );
	}

	public static function install_id() {
		$id = get_option( self::OPTION_INSTALL_ID );
		return is_string( $id ) && '' !== $id ? $id : null;
	}

	/** This site's own base URL, as WordPress sees it. Sent as Sudemi-Site on every signed call. */
	public static function site_url() {
		$url = home_url( '/' );
		// A header value: no line breaks, whatever a filter on home_url returned.
		return is_string( $url ) ? trim( preg_replace( '/[\r\n]+/', '', $url ) ) : '';
	}

	/**
	 * Whether sudemi refused a call because it came from another site than the connected shop.
	 *
	 * @return string|null null when it did not; otherwise the address the shop is connected
	 *                     under, as sudemi named it, or '' when it could not be read.
	 */
	public static function site_mismatch( $response ) {
		if ( ! is_array( $response ) || 409 !== (int) $response['status']
			|| ! isset( $response['body']['error'] ) || 'site_mismatch' !== $response['body']['error'] ) {
			return null;
		}
		$message = isset( $response['body']['message'] ) ? (string) $response['body']['message'] : '';
		if ( preg_match( '#connected to sudemi as (https?://[A-Za-z0-9.:\[\]-]+)#', $message, $match ) ) {
			return rtrim( $match[1], '.:' );
		}
		return '';
	}

	public static function is_connected() {
		return null !== self::install_id() && Sudemi_Keys::has_key();
	}

	/**
	 * An unsigned call. Used only by pairing, which happens before this shop has an identity.
	 */
	public static function post_unsigned( $path, array $body ) {
		return self::request( $path, $body, false );
	}

	/**
	 * A signed call.
	 *
	 * The signature covers the method, the path, the timestamp, a nonce and a hash of the body,
	 * so neither the path nor a single price can be altered between here and sudemi without the
	 * signature failing.
	 */
	public static function post_signed( $path, array $body ) {
		return self::request_with_backoff( $path, $body, true );
	}

	/**
	 * Sends, and waits if sudemi says this shop is going too fast.
	 *
	 * sudemi caps how much catalogue one shop may push per minute, so that a large import cannot
	 * take the service away from every other shop. A 429 is therefore an ordinary part of a full
	 * sync rather than a failure: it means "not yet", and it carries how long to wait.
	 *
	 * Treating it as a failure is what makes a large catalogue impossible to sync at all - the
	 * batch aborts, the sync is abandoned, and the next attempt gets exactly as far. So it is
	 * retried, bounded, and only then given up on.
	 */
	/**
	 * A signed GET.
	 *
	 * The canonical string carries the method, so a GET cannot be replayed as a POST or the
	 * other way round. Used for reading a confirmed order's delivery address, which is the one
	 * thing sudemi hands back rather than receives.
	 */
	public static function get_signed( $path ) {
		return self::request_with_backoff( $path, array(), true, 'GET' );
	}

	/**
	 * A signed call made exactly once, for a person waiting on an admin screen.
	 *
	 * No backoff: on the support endpoints a 429 means "too many tickets", a limit that a few
	 * seconds of waiting does not lift, and an admin page must not sit for minutes in sleep().
	 * Signed exactly like every other call (a GET signs an empty body, the query string is part
	 * of the signed path).
	 */
	public static function send_signed( $method, $path, array $body = array(), array $extra_headers = array() ) {
		return self::request( $path, $body, true, 'GET' === $method ? 'GET' : 'POST', $extra_headers );
	}

	/**
	 * A signed GET whose answer is not JSON (the order export, a CSV file), made once.
	 *
	 * @return array{status:int,raw:string,headers:array,body:array}|WP_Error The body as sent,
	 *         its headers, and the decoded JSON when it was an error answer.
	 */
	public static function get_signed_raw( $path ) {
		return self::request( $path, array(), true, 'GET', array(), true );
	}

	private static function request_with_backoff( $path, array $body, $signed, $method = 'POST' ) {
		$attempt = 0;
		while ( true ) {
			$response = self::request( $path, $body, $signed, $method );
			if ( is_wp_error( $response ) || 429 !== (int) $response['status'] ) {
				return $response;
			}
			++$attempt;
			if ( $attempt > self::RATE_LIMIT_RETRIES ) {
				// Give up rather than sit in a cron slot indefinitely. The caller abandons the
				// sync, which delists nothing, and the next scheduled run starts again.
				Sudemi_Logger::warn(
					__( 'sudemi is still asking this shop to slow down. Stopping for now; this will continue on the next scheduled run and nothing has been lost.', 'sudemi-connector' )
				);
				return $response;
			}
			$wait = isset( $response['retry_after'] ) ? (int) $response['retry_after'] : 60;
			// Capped: a relay or proxy could return an absurd value, and a cron run that sleeps
			// for an hour is worse than one that gives up and retries later.
			$wait = max( 1, min( self::RATE_LIMIT_MAX_WAIT, $wait ) );
			Sudemi_Logger::info(
				sprintf(
					/* translators: %d: seconds to wait. */
					__( 'sudemi asked this shop to slow down; waiting %d seconds before continuing.', 'sudemi-connector' ),
					$wait
				)
			);
			sleep( $wait );
		}
	}

	private static function request( $path, array $body, $signed, $method = 'POST', array $extra_headers = array(), $raw = false ) {
		// A GET carries no body, and the signature covers the hash of the body actually sent --
		// so it has to be the hash of nothing, not the hash of "{}".
		if ( 'GET' === $method ) {
			$json = '';
		} else {
			$json = wp_json_encode( $body );
			if ( false === $json ) {
				return new WP_Error( 'sudemi_encode_failed', __( 'The payload could not be encoded.', 'sudemi-connector' ) );
			}
		}

		$headers = array( 'Content-Type' => 'application/json' );
		// Only the ones sudemi reads besides the signature (Idempotency-Key): nothing here may
		// replace a signing header.
		foreach ( $extra_headers as $name => $value ) {
			if ( 'Idempotency-Key' === $name && is_string( $value ) && preg_match( '/^[A-Za-z0-9._:-]{1,255}$/', $value ) ) {
				$headers[ $name ] = $value;
			}
		}

		if ( $signed ) {
			$install_id = self::install_id();
			if ( null === $install_id ) {
				return new WP_Error( 'sudemi_not_connected', __( 'This shop is not connected to sudemi.', 'sudemi-connector' ) );
			}
			$timestamp = (string) time();
			$nonce     = bin2hex( random_bytes( 16 ) );
			$canonical = implode(
				"\n",
				array( $method, $path, $timestamp, $nonce, hash( 'sha256', $json ) )
			);
			$signature = Sudemi_Keys::sign( $canonical );
			if ( null === $signature ) {
				return new WP_Error( 'sudemi_cannot_sign', __( 'This site has no signing key.', 'sudemi-connector' ) );
			}
			$headers['Sudemi-Install']   = $install_id;
			$headers['Sudemi-Timestamp'] = $timestamp;
			$headers['Sudemi-Nonce']     = $nonce;
			$headers['Sudemi-Signature'] = $signature;
			// Not signed, and not a secret: the address this WordPress believes it lives at. A
			// staging copy or a restored backup carries this shop's install id and key, and this
			// is how sudemi tells it apart from the live shop and refuses it (409 site_mismatch).
			$site = self::site_url();
			if ( '' !== $site ) {
				$headers['Sudemi-Site'] = $site;
			}
		}

		$response = wp_remote_request(
			self::api_base() . $path,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => $json,
				'timeout' => self::TIMEOUT_SECONDS,
				// Never disabled, and deliberately not configurable. A shop that cannot verify
				// our certificate is a shop whose catalogue could be rewritten in transit.
				'sslverify' => true,
				'user-agent' => 'sudemi-woocommerce/' . SUDEMI_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );
		$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );

		if ( $raw ) {
			$headers_out = array();
			foreach ( array( 'content-type', 'content-disposition', 'x-total-count', 'x-export-truncated' ) as $name ) {
				$headers_out[ $name ] = (string) wp_remote_retrieve_header( $response, $name );
			}
			return array(
				'status'      => $status,
				'raw'         => (string) wp_remote_retrieve_body( $response ),
				'headers'     => $headers_out,
				'body'        => is_array( $parsed ) ? $parsed : array(),
				'retry_after' => $retry_after,
			);
		}

		// Fail closed: a response that does not parse is not treated as success whatever its
		// status code said.
		if ( null === $parsed && 204 !== $status ) {
			return new WP_Error(
				'sudemi_bad_response',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'sudemi answered %d with something that was not JSON.', 'sudemi-connector' ), $status )
			);
		}

		return array(
			'status'      => $status,
			'body'        => is_array( $parsed ) ? $parsed : array(),
			// Carried out so the backoff above knows how long sudemi asked this shop to wait.
			'retry_after' => $retry_after,
		);
	}
}
