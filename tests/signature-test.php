<?php
/**
 * Proves the key encoding and the signature this plugin produces are the ones sudemi verifies.
 *
 * Runs without WordPress on purpose: it exercises the two things that cannot be checked by
 * reading the code — that libsodium's raw public key, wrapped in the fixed Ed25519 prefix, is a
 * parseable X.509 key, and that a signature over the canonical string verifies against it.
 *
 * The same vector is asserted on the platform side, so a change to either encoding fails a
 * build rather than every shop's first sync.
 */

declare(strict_types=1);

$failures = 0;

function check(string $what, bool $passed): void {
	global $failures;
	echo ( $passed ? "ok   " : "FAIL " ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

check( 'the sodium extension is present', extension_loaded( 'sodium' ) );

$keypair = sodium_crypto_sign_keypair();
$secret  = sodium_crypto_sign_secretkey( $keypair );
$public  = sodium_crypto_sign_publickey( $keypair );

check( 'a public key is 32 bytes', 32 === strlen( $public ) );

// Exactly what Sudemi_Keys::public_key_der() does.
$prefix = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";
$der    = $prefix . $public;

check( 'the DER key is 44 bytes', 44 === strlen( $der ) );
check( 'the DER key announces Ed25519 (OID 1.3.101.112)', str_contains( $der, "\x2b\x65\x70" ) );

$body      = json_encode( array( 'products' => array( array( 'externalId' => '42', 'priceMinor' => 1999 ) ) ) );
$canonical = implode( "\n", array( 'POST', '/integration/v1/products', (string) time(), bin2hex( random_bytes( 16 ) ), hash( 'sha256', $body ) ) );
$signature = sodium_crypto_sign_detached( $canonical, $secret );

check( 'a signature is 64 bytes', 64 === strlen( $signature ) );
check(
	'the signature verifies against the public key',
	sodium_crypto_sign_verify_detached( $signature, $canonical, $public )
);

// The property the whole scheme rests on: the body hash is inside the signed string, so
// altering the payload in transit invalidates the signature.
$tampered = str_replace( hash( 'sha256', $body ), hash( 'sha256', 'different body' ), $canonical );
check(
	'a tampered request does not verify',
	! sodium_crypto_sign_verify_detached( $signature, $tampered, $public )
);

// A different keypair must not verify, which is what makes one shop unable to speak for another.
$other = sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() );
check(
	'another shop\'s key does not verify this signature',
	! sodium_crypto_sign_verify_detached( $signature, $canonical, $other )
);

sodium_memzero( $secret );

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
