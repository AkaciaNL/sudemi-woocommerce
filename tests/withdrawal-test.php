<?php
/**
 * The right-of-withdrawal exclusion: only the seven art. 16 reasons get through, anything else
 * means "normal right of withdrawal", and the product payload carries the field only when set.
 *
 * Runs without WordPress: the two functions under test are pure.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.NamingConventions.PrefixAllGlobals,WordPress.PHP.DevelopmentFunctions -- a command-line test (plain text output, test doubles of WordPress functions); left out of the release zip.
$meta = array();
function get_post_meta( $id, $key, $single = false ) {
	global $meta;
	return isset( $meta[ $id ][ $key ] ) ? $meta[ $id ][ $key ] : '';
}
require __DIR__ . '/../includes/class-sudemi-withdrawal.php';

$failures = 0;

function check( string $what, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ), $what, "\n";
	if ( ! $passed ) {
		$failures++;
	}
}

foreach ( array( 'hygiene', 'custom', 'perishable', 'media', 'press', 'mixed', 'alcohol' ) as $reason ) {
	check( "'$reason' is accepted", $reason === Sudemi_Withdrawal::sanitize( $reason ) );
}
check( 'case and surrounding spaces are normalised', 'custom' === Sudemi_Withdrawal::sanitize( '  Custom ' ) );
foreach ( array( '', 'none', 'other', 'custom; DROP TABLE', '<script>', 'hygiene ', null, 1, array( 'custom' ), true ) as $bad ) {
	$label = is_string( $bad ) ? "'" . $bad . "'" : gettype( $bad );
	if ( 'hygiene ' === $bad ) {
		check( "$label (trailing space) is trimmed to hygiene", 'hygiene' === Sudemi_Withdrawal::sanitize( $bad ) );
		continue;
	}
	check( "$label is not an exclusion", null === Sudemi_Withdrawal::sanitize( $bad ) );
}

$product = array( 'externalId' => '42', 'priceMinor' => 1999, 'maxHandlingTimeDays' => 2 );
$with    = Sudemi_Withdrawal::apply( $product, 'custom' );
check( 'an exclusion is sent as withdrawalExclusion', isset( $with['withdrawalExclusion'] ) && 'custom' === $with['withdrawalExclusion'] );
check( 'the rest of the payload is untouched', 1999 === $with['priceMinor'] && 2 === $with['maxHandlingTimeDays'] );
check( 'no exclusion: the field is omitted', ! array_key_exists( 'withdrawalExclusion', Sudemi_Withdrawal::apply( $product, null ) ) );
check( 'an empty stored value: the field is omitted', ! array_key_exists( 'withdrawalExclusion', Sudemi_Withdrawal::apply( $product, '' ) ) );
check( 'an unknown stored value is never sent', ! array_key_exists( 'withdrawalExclusion', Sudemi_Withdrawal::apply( $product, 'whatever' ) ) );
check( 'the JSON carries the field', false !== strpos( (string) json_encode( $with ), '"withdrawalExclusion":"custom"' ) );

// Owner decision 2: accepting returns of personalised items is the shop's choice, off by default.
check( 'personalised items: returns not accepted by default', false === Sudemi_Withdrawal::personalisation_returnable( 7 ) );
$meta[7][ Sudemi_Withdrawal::META_RETURNABLE ] = 'yes';
check( 'personalised items: returns accepted when the shop ticked it', true === Sudemi_Withdrawal::personalisation_returnable( 7 ) );
$meta[7][ Sudemi_Withdrawal::META_RETURNABLE ] = 'no';
check( 'personalised items: unticked again is off', false === Sudemi_Withdrawal::personalisation_returnable( 7 ) );

echo $failures ? "\n$failures check(s) failed\n" : "\nall checks passed\n";
exit( $failures ? 1 : 0 );
