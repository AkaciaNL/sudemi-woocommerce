<?php
/**
 * A short log a shop owner can read, and which never contains a credential.
 *
 * Bounded on purpose: an unbounded log in an option is a way to fill a database, and a log that
 * holds a signing key is a way to lose one. Nothing here ever receives key material - the key
 * class does not expose it - and messages are the plugin's own text rather than raw responses.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Logger {

	const OPTION = 'sudemi_log';
	const LIMIT  = 50;

	public static function info( $message ) {
		self::write( 'info', $message );
	}

	public static function warn( $message ) {
		self::write( 'warn', $message );
	}

	public static function error( $message ) {
		self::write( 'error', $message );
	}

	public static function entries() {
		$log = get_option( self::OPTION );
		return is_array( $log ) ? $log : array();
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	private static function write( $level, $message ) {
		$log = self::entries();
		array_unshift(
			$log,
			array(
				'at'      => gmdate( 'c' ),
				'level'   => $level,
				'message' => sanitize_text_field( (string) $message ),
			)
		);
		update_option( self::OPTION, array_slice( $log, 0, self::LIMIT ), false );
	}
}
