<?php
/**
 * The shop's signing key.
 *
 * The private key is generated here, on this host, and never leaves it. sudemi receives only
 * the public half, which is why a breach of their database cannot be used to push a price of
 * zero into this shop's listings - and why there is no secret to lose in transit, in a log, or
 * in a support export.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Keys {

	/**
	 * Autoloaded is deliberately off. The key is read on the few requests that talk to sudemi
	 * and should not sit in memory for every page view of the shop.
	 */
	const OPTION_SECRET = 'sudemi_signing_secret';
	const OPTION_PUBLIC = 'sudemi_signing_public';

	/**
	 * Whether this host can sign at all.
	 *
	 * Checked before anything is offered to the user, so a host without libsodium is told
	 * plainly at the top of the settings screen rather than failing on the first sync with a
	 * fatal error in a cron job nobody reads.
	 */
	public static function is_supported() {
		return function_exists( 'sodium_crypto_sign_keypair' )
			&& function_exists( 'sodium_crypto_sign_detached' );
	}

	/**
	 * Creates a keypair, replacing any existing one.
	 *
	 * Called when a shop pairs. Re-pairing means a new key, which is also the simplest way to
	 * recover from a key you suspect has been read: pair again and the old one is dead.
	 */
	public static function regenerate() {
		if ( ! self::is_supported() ) {
			return false;
		}
		$keypair = sodium_crypto_sign_keypair();
		$secret  = sodium_crypto_sign_secretkey( $keypair );
		$public  = sodium_crypto_sign_publickey( $keypair );

		// Stored base64 because an option is text. The raw bytes are wiped from memory below.
		update_option( self::OPTION_SECRET, base64_encode( $secret ), false );
		update_option( self::OPTION_PUBLIC, base64_encode( $public ), false );

		sodium_memzero( $keypair );
		sodium_memzero( $secret );

		return true;
	}

	/**
	 * The public key, in the DER form sudemi expects.
	 *
	 * libsodium hands back 32 raw bytes; the platform verifies against an X.509
	 * SubjectPublicKeyInfo, so the fixed 12-byte Ed25519 prefix is prepended here. Doing it on
	 * this side keeps the wire format the same for every plugin, whatever its language gives it.
	 */
	public static function public_key_der() {
		$raw = self::raw_public();
		if ( null === $raw ) {
			return null;
		}
		$prefix = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";
		return base64_encode( $prefix . $raw );
	}

	/**
	 * Signs a canonical request string.
	 *
	 * @param string $message The canonical string; see Sudemi_Client.
	 * @return string|null Base64 detached signature, or null when this host cannot sign.
	 */
	public static function sign( $message ) {
		$secret = self::raw_secret();
		if ( null === $secret ) {
			return null;
		}
		$signature = sodium_crypto_sign_detached( $message, $secret );
		sodium_memzero( $secret );
		return base64_encode( $signature );
	}

	public static function has_key() {
		return null !== self::raw_secret();
	}

	/** Removes the key material. Used on uninstall and when a shop disconnects. */
	public static function forget() {
		delete_option( self::OPTION_SECRET );
		delete_option( self::OPTION_PUBLIC );
	}

	private static function raw_secret() {
		$stored = get_option( self::OPTION_SECRET );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		$raw = base64_decode( $stored, true );
		return ( false === $raw || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $raw ) ) ? null : $raw;
	}

	private static function raw_public() {
		$stored = get_option( self::OPTION_PUBLIC );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		$raw = base64_decode( $stored, true );
		return ( false === $raw || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $raw ) ) ? null : $raw;
	}
}
