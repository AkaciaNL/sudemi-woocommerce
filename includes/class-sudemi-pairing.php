<?php
/**
 * Connecting this shop to a sudemi seller account.
 *
 * The flow has three parts and this class owns two of them. It generates the keypair and asks
 * sudemi to start a pairing; it serves the challenge token so sudemi can prove this shop
 * consented; and it polls until a human has claimed the shop in a browser. The third part —
 * signing in and confirming — happens on sudemi, not here, which is why this plugin never asks
 * for a sudemi password and could not use one if it had it.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Pairing {

	const OPTION_PUBLIC_CODE = 'sudemi_pairing_code';
	const OPTION_POLL_TOKEN  = 'sudemi_pairing_poll_token';
	const OPTION_CLAIM_URL   = 'sudemi_pairing_claim_url';
	const CRON_HOOK          = 'sudemi_pairing_poll';

	public static function boot() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'poll' ) );
	}

	/**
	 * Starts a pairing and returns the URL the shop owner must open.
	 *
	 * @return array|WP_Error
	 */
	public static function start() {
		if ( ! Sudemi_Keys::is_supported() ) {
			return new WP_Error(
				'sudemi_no_sodium',
				__( 'This server is missing the sodium extension, which the connection needs in order to sign requests. Ask your host to enable it.', 'sudemi' )
			);
		}
		if ( ! Sudemi_Keys::regenerate() ) {
			return new WP_Error( 'sudemi_keygen_failed', __( 'A signing key could not be created on this server.', 'sudemi' ) );
		}

		$result = Sudemi_Client::post_unsigned(
			'/integration/v1/pair',
			array(
				'shopUrl'         => home_url( '/' ),
				'platform'        => 'woocommerce',
				'pluginVersion'   => SUDEMI_VERSION,
				'publicKey'       => Sudemi_Keys::public_key_der(),
				'proposedProfile' => self::proposed_profile(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 200 !== $result['status'] ) {
			$message = isset( $result['body']['message'] )
				? $result['body']['message']
				: __( 'sudemi refused to start the connection.', 'sudemi' );
			return new WP_Error( 'sudemi_pair_refused', $message );
		}

		$body = $result['body'];
		Sudemi_Challenge::store_token( isset( $body['challenge_token'] ) ? $body['challenge_token'] : '' );
		update_option( self::OPTION_PUBLIC_CODE, isset( $body['public_code'] ) ? $body['public_code'] : '', false );
		update_option( self::OPTION_POLL_TOKEN, isset( $body['poll_token'] ) ? $body['poll_token'] : '', false );
		update_option( self::OPTION_CLAIM_URL, isset( $body['claim_url'] ) ? $body['claim_url'] : '', false );

		// Poll in the background so the shop owner can go and claim it without this screen
		// holding a request open.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 30, 'sudemi_minute', self::CRON_HOOK );
		}

		return $body;
	}

	/**
	 * Asks sudemi whether the shop has been claimed yet.
	 *
	 * Runs on cron. Stops rescheduling itself once it succeeds or once the pairing is gone,
	 * because a plugin that polls forever is a plugin that hammers an endpoint forever.
	 */
	public static function poll() {
		$code  = get_option( self::OPTION_PUBLIC_CODE );
		$token = get_option( self::OPTION_POLL_TOKEN );
		if ( ! is_string( $code ) || '' === $code || ! is_string( $token ) || '' === $token ) {
			self::stop_polling();
			return;
		}

		$result = Sudemi_Client::post_unsigned(
			'/integration/v1/pair/poll',
			array(
				'publicCode' => $code,
				'pollToken'  => $token,
			)
		);

		if ( is_wp_error( $result ) ) {
			Sudemi_Logger::warn( 'Pairing poll failed: ' . $result->get_error_message() );
			return;
		}

		// 410 means the pairing expired or was abandoned. Nothing to wait for.
		if ( 410 === $result['status'] ) {
			Sudemi_Logger::warn( 'Pairing is no longer available; start the connection again.' );
			self::stop_polling();
			self::forget_pairing();
			return;
		}

		if ( 200 === $result['status'] && isset( $result['body']['install_id'] ) ) {
			update_option( Sudemi_Client::OPTION_INSTALL_ID, $result['body']['install_id'], false );
			self::stop_polling();
			// The challenge has done its job. Keeping it served would leave a token on a public
			// path for no reason.
			Sudemi_Challenge::forget();
			self::forget_pairing();
			Sudemi_Logger::info( 'Connected to sudemi.' );
			do_action( 'sudemi_connected' );
		}
	}

	public static function stop_polling() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	public static function forget_pairing() {
		delete_option( self::OPTION_PUBLIC_CODE );
		delete_option( self::OPTION_POLL_TOKEN );
		delete_option( self::OPTION_CLAIM_URL );
	}

	public static function claim_url() {
		$url = get_option( self::OPTION_CLAIM_URL );
		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * What this shop knows about itself, offered as a draft for the seller to confirm.
	 *
	 * A draft and nothing more: the legal name and the country decide a buyer's withdrawal
	 * rights, so sudemi shows them on the claim screen for a person to confirm, and locks them
	 * afterwards. This plugin cannot set them and cannot change them later.
	 */
	private static function proposed_profile() {
		$country_state = get_option( 'woocommerce_store_address_country', '' );
		$country       = '';
		if ( is_string( $country_state ) && '' !== $country_state ) {
			$parts   = explode( ':', $country_state );
			$country = strtoupper( substr( (string) $parts[0], 0, 2 ) );
		}
		if ( '' === $country && function_exists( 'wc_get_base_location' ) ) {
			$base    = wc_get_base_location();
			$country = isset( $base['country'] ) ? (string) $base['country'] : '';
		}

		return array(
			'displayName'          => get_bloginfo( 'name' ),
			'legalName'            => get_bloginfo( 'name' ),
			'establishmentCountry' => $country,
			'dispatchCountry'      => $country,
			'traderStatus'         => 'TRADER',
			'vatNumber'            => '',
			'tradeRegisterNumber'  => '',
		);
	}
}
