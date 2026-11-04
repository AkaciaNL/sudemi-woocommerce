<?php
/**
 * The settings screen.
 *
 * Three rules hold on every form here, because an admin screen is where a plugin usually gives
 * away more than it meant to: every action checks a capability, every action checks a nonce, and
 * everything printed is escaped. A reader should be able to confirm all three without leaving
 * this file.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Admin {

	const CAPABILITY = 'manage_woocommerce';
	const SLUG       = 'sudemi';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_sudemi_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_sudemi_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_sudemi_sync_now', array( __CLASS__, 'handle_sync_now' ) );
	}

	public static function add_page() {
		add_submenu_page(
			'woocommerce',
			__( 'sudemi', 'sudemi' ),
			__( 'sudemi', 'sudemi' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		// Checked again here, not only when registering the menu: a page callback is reachable
		// by anyone who knows its slug if the menu check is the only one.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this shop.', 'sudemi' ) );
		}

		$connected  = Sudemi_Client::is_connected();
		$claim_url  = Sudemi_Pairing::claim_url();
		$supported  = Sudemi_Keys::is_supported();

		echo '<div class="wrap"><h1>' . esc_html__( 'sudemi', 'sudemi' ) . '</h1>';

		if ( ! $supported ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'This server is missing the sodium extension. The connection needs it to sign requests, and will not work without it. Ask your host to enable it.', 'sudemi' )
				. '</p></div>';
		}

		if ( $connected ) {
			self::render_connected();
		} elseif ( $claim_url ) {
			self::render_waiting( $claim_url );
		} else {
			self::render_disconnected( $supported );
		}

		self::render_log();
		echo '</div>';
	}

	private static function render_disconnected( $supported ) {
		echo '<p>' . esc_html__( 'Connecting this shop lets an AI assistant find your products and send buyers to you. You confirm the connection on sudemi, signed in to your own account — this plugin never asks for your sudemi password.', 'sudemi' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sudemi_connect' );
		echo '<input type="hidden" name="action" value="sudemi_connect" />';
		submit_button( __( 'Connect this shop', 'sudemi' ), 'primary', 'submit', true, $supported ? array() : array( 'disabled' => 'disabled' ) );
		echo '</form>';
	}

	private static function render_waiting( $claim_url ) {
		echo '<div class="notice notice-info"><p>'
			. esc_html__( 'Almost there. Open the link below, sign in to sudemi and confirm that this shop is yours. This page will pick it up by itself.', 'sudemi' )
			. '</p></div>';
		echo '<p><a class="button button-primary" target="_blank" rel="noopener noreferrer" href="'
			. esc_url( $claim_url ) . '">' . esc_html__( 'Confirm this shop on sudemi', 'sudemi' ) . '</a></p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: the public path the token is served at. */
				__( 'While this is open, the shop answers at %s with a one-time token so sudemi can confirm the request came from here. It stops being served the moment the connection completes.', 'sudemi' ),
				Sudemi_Challenge::PATH
			)
		) . '</p>';
	}

	private static function render_connected() {
		echo '<div class="notice notice-success"><p>' . esc_html__( 'This shop is connected to sudemi.', 'sudemi' ) . '</p></div>';

		echo '<table class="widefat striped" style="max-width:48rem"><tbody>';
		self::row( __( 'Shop address', 'sudemi' ), home_url( '/' ), __( 'Fixed. sudemi checked that this shop answered from this address, and a listing tells a buyer where it came from — so changing it means connecting again from the new address.', 'sudemi' ) );
		self::row( __( 'Install', 'sudemi' ), (string) Sudemi_Client::install_id(), __( 'Which installation this is. Revoke it on sudemi to cut this shop off immediately.', 'sudemi' ) );
		self::row( __( 'Signing key', 'sudemi' ), __( 'held on this server', 'sudemi' ), __( 'The private half never leaves this server and is never sent to sudemi. It cannot be shown here either.', 'sudemi' ) );
		echo '</tbody></table>';

		self::render_listing_status();
		self::render_order_history();

		echo '<h2>' . esc_html__( 'Set on sudemi, not here', 'sudemi' ) . '</h2>';
		echo '<p>' . esc_html__( 'Your legal name, country, trader status and VAT number decide what a buyer is owed, so they are confirmed once on sudemi and changed there. This plugin cannot edit them.', 'sudemi' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'sudemi_sync_now' );
		echo '<input type="hidden" name="action" value="sudemi_sync_now" />';
		submit_button( __( 'Sync the catalogue now', 'sudemi' ), 'secondary', 'submit', false );
		echo '</form> ';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'sudemi_disconnect' );
		echo '<input type="hidden" name="action" value="sudemi_disconnect" />';
		submit_button( __( 'Disconnect', 'sudemi' ), 'delete', 'submit', false );
		echo '</form>';
	}

	/**
	 * Whether sudemi is currently showing this shop's products, in sudemi's own words.
	 *
	 * Worth a panel of its own because the failure it reports is otherwise invisible from here.
	 * Products stop being listed when the heartbeat stops arriving, and from this side everything
	 * looks normal: the plugin is active, the catalogue is intact, the settings are right. The
	 * cause is usually something that has nothing to do with sudemi — WP-Cron disabled on a host
	 * that never set up a real cron, an outbound firewall, a plugin conflict killing scheduled
	 * events — and without this panel the first sign of it is a seller wondering where their
	 * orders went.
	 */
	private static function render_listing_status() {
		$status = Sudemi_Heartbeat::status();

		echo '<h2>' . esc_html__( 'Listing status', 'sudemi' ) . '</h2>';

		if ( empty( $status ) ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'This shop has not reported in to sudemi yet. That happens within a few minutes of connecting, and needs WP-Cron to be running on this site.', 'sudemi' )
				. '</p></div>';
			return;
		}

		$checked = isset( $status['checked'] ) ? (int) $status['checked'] : 0;
		/* translators: %s: human-readable time difference, e.g. "4 mins". */
		$when = $checked
			? sprintf( __( '%s ago', 'sudemi' ), human_time_diff( $checked, time() ) )
			: __( 'never', 'sudemi' );

		if ( empty( $status['reachable'] ) ) {
			echo '<div class="notice notice-error inline"><p>'
				. esc_html__( 'This shop could not reach sudemi. Its products stay listed for a short while and are then paused, because sudemi will not quote a price it cannot confirm.', 'sudemi' )
				. '</p></div>';
		} elseif ( empty( $status['connected'] ) ) {
			echo '<div class="notice notice-error inline"><p>'
				. esc_html( isset( $status['message'] ) ? $status['message'] : __( 'sudemi refused this shop.', 'sudemi' ) )
				. '</p></div>';
		} elseif ( empty( $status['listed'] ) ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html( ! empty( $status['reason'] ) ? $status['reason'] : __( 'This shop is connected but its products are not being shown.', 'sudemi' ) )
				. '</p></div>';
		} else {
			echo '<div class="notice notice-success inline"><p>'
				. esc_html__( 'Products are being shown on sudemi.', 'sudemi' )
				. '</p></div>';
		}

		echo '<table class="widefat striped" style="max-width:48rem"><tbody>';
		self::row( __( 'Last reported in', 'sudemi' ), $when, __( 'This shop tells sudemi every five minutes that it is still here and that its prices still stand. When that stops, the listings are paused rather than left at prices nobody is confirming.', 'sudemi' ) );
		if ( ! empty( $status['trusted_until'] ) ) {
			self::row( __( 'Prices confirmed until', 'sudemi' ), (string) $status['trusted_until'], __( 'After this, sudemi stops showing this shop until it reports in again. Nothing is deleted.', 'sudemi' ) );
		}
		$pending = isset( $status['pending'] ) ? (int) $status['pending'] : 0;
		self::row(
			__( 'Price changes waiting', 'sudemi' ),
			(string) $pending,
			$pending > 0
				? __( 'Price or stock changes that could not be sent yet. They are retried on the next report and are not lost.', 'sudemi' )
				: __( 'Every price change has reached sudemi.', 'sudemi' )
		);
		self::row(
			__( 'Confirming orders', 'sudemi' ),
			Sudemi_Orders::auto_confirm_enabled()
				? __( 'automatically', 'sudemi' )
				: __( 'by hand', 'sudemi' ),
			Sudemi_Orders::auto_confirm_enabled()
				? __( 'A buyer on sudemi is authorised but not charged until this shop confirms it has the item. That check runs against this shop\'s live stock every five minutes: in stock means the order is accepted and the money taken, out of stock means it is declined and the buyer\'s money released. Nothing is ever charged without this shop agreeing to it.', 'sudemi' )
				: __( 'Orders wait for someone here to answer them. If nobody does before the deadline sudemi shows, the buyer is released and charged nothing — an unanswered order is never treated as accepted.', 'sudemi' )
		);
		echo '</tbody></table>';
	}

	private static function row( $label, $value, $note ) {
		echo '<tr><th scope="row" style="width:12rem">' . esc_html( $label ) . '</th><td><code>'
			. esc_html( $value ) . '</code><p class="description">' . esc_html( $note )
			. '</p></td></tr>';
	}

	/**
	 * The orders sudemi holds for this shop, with what each one earned.
	 *
	 * Shown here as well as on the sudemi website because a merchant looking at their shop admin
	 * should not have to go elsewhere to see what sold. Both read the same endpoint, so the two
	 * cannot drift apart and leave a merchant with two numbers and no way to tell which is wrong.
	 */
	private static function render_order_history() {
		if ( ! Sudemi_Client::is_connected() ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Orders through sudemi', 'sudemi' ) . '</h2>';

		$history = Sudemi_Orders::history( '', 20 );
		if ( null === $history ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'Could not reach sudemi to load the order history just now. Nothing is wrong with the orders themselves; try again shortly.', 'sudemi' )
				. '</p></div>';
			return;
		}

		$counts = isset( $history['counts'] ) && is_array( $history['counts'] ) ? $history['counts'] : array();
		$awaiting = isset( $counts['AWAITING_SELLER'] ) ? (int) $counts['AWAITING_SELLER'] : 0;
		if ( $awaiting > 0 ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html(
					sprintf(
						/* translators: %d: number of orders waiting. */
						_n(
							'%d order is waiting to be confirmed. The buyer has not been charged for it yet.',
							'%d orders are waiting to be confirmed. Those buyers have not been charged yet.',
							$awaiting,
							'sudemi'
						),
						$awaiting
					)
				)
				. '</p></div>';
		}

		$orders = isset( $history['orders'] ) && is_array( $history['orders'] ) ? $history['orders'] : array();
		if ( empty( $orders ) ) {
			echo '<p class="description">'
				. esc_html__( 'No orders through sudemi yet.', 'sudemi' )
				. '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:60rem"><thead><tr>'
			. '<th>' . esc_html__( 'Order', 'sudemi' ) . '</th>'
			. '<th>' . esc_html__( 'What sold', 'sudemi' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'sudemi' ) . '</th>'
			. '<th>' . esc_html__( 'You receive', 'sudemi' ) . '</th>'
			. '<th>' . esc_html__( 'Customer', 'sudemi' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $orders as $order ) {
			$items = array();
			if ( ! empty( $order['lines'] ) && is_array( $order['lines'] ) ) {
				foreach ( $order['lines'] as $line ) {
					$title = isset( $line['title'] ) ? (string) $line['title'] : '';
					$qty   = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
					$items[] = $qty > 1 ? $title . ' × ' . $qty : $title;
				}
			}

			$currency = isset( $order['currency_code'] ) ? (string) $order['currency_code'] : 'EUR';
			$net      = isset( $order['your_net_minor'] ) ? (int) $order['your_net_minor'] : 0;

			echo '<tr>';
			echo '<td><code>' . esc_html( substr( (string) $order['order_id'], 0, 8 ) ) . '</code><br />'
				. '<span class="description">' . esc_html( isset( $order['placed_at'] ) ? substr( (string) $order['placed_at'], 0, 10 ) : '' ) . '</span></td>';
			echo '<td>' . esc_html( implode( ', ', $items ) ) . '</td>';
			echo '<td>' . esc_html( self::order_status_label( $order ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $net / 100, 2 ) . ' ' . $currency ) . '</td>';
			// Only present once sudemi has released it, which is only after this shop confirmed.
			echo '<td>' . esc_html( isset( $order['customer_name'] ) ? (string) $order['customer_name'] : '—' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/** A merchant-readable status, including what it means for the money. */
	private static function order_status_label( array $order ) {
		$status = isset( $order['status'] ) ? (string) $order['status'] : '';
		switch ( $status ) {
			case 'AWAITING_SELLER':
				return __( 'Waiting for you — buyer not charged', 'sudemi' );
			case 'ACCEPTED':
				return __( 'Accepted — waiting for the other shops in the buyer\'s payment; buyer not charged yet, do not ship', 'sudemi' );
			case 'PAID':
				return __( 'Confirmed and charged', 'sudemi' );
			case 'DECLINED':
				return isset( $order['declined_reason'] )
					? sprintf(
						/* translators: %s: the reason the order was declined. */
						__( 'Declined (%s) — nothing charged', 'sudemi' ),
						(string) $order['declined_reason']
					)
					: __( 'Declined — nothing charged', 'sudemi' );
			case 'SHIPPED':
				return __( 'Shipped', 'sudemi' );
			case 'DELIVERED':
				return __( 'Delivered', 'sudemi' );
			default:
				return $status;
		}
	}

	private static function render_log() {
		$entries = Sudemi_Logger::entries();
		if ( empty( $entries ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Recent activity', 'sudemi' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:48rem"><tbody>';
		foreach ( $entries as $entry ) {
			$at      = isset( $entry['at'] ) ? $entry['at'] : '';
			$message = isset( $entry['message'] ) ? $entry['message'] : '';
			echo '<tr><td style="width:14rem">' . esc_html( $at ) . '</td><td>' . esc_html( $message ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ------------------------------------------------------------------ actions ----

	public static function handle_connect() {
		self::guard( 'sudemi_connect' );
		$result = Sudemi_Pairing::start();
		if ( is_wp_error( $result ) ) {
			Sudemi_Logger::warn( $result->get_error_message() );
		}
		self::back();
	}

	public static function handle_disconnect() {
		self::guard( 'sudemi_disconnect' );
		Sudemi_Pairing::stop_polling();
		Sudemi_Pairing::forget_pairing();
		Sudemi_Sync::unschedule();
		Sudemi_Heartbeat::unschedule();
		Sudemi_Heartbeat::forget();
		Sudemi_Queue::forget();
		Sudemi_Challenge::forget();
		delete_option( Sudemi_Client::OPTION_INSTALL_ID );
		// The key goes with the connection. Keeping it would leave a usable credential on a
		// shop that believes it has disconnected.
		Sudemi_Keys::forget();
		Sudemi_Logger::info( __( 'Disconnected from sudemi.', 'sudemi' ) );
		self::back();
	}

	public static function handle_sync_now() {
		self::guard( 'sudemi_sync_now' );
		// Scheduled rather than run inline: a catalogue of any size would exceed the time a
		// browser request is given, and a half-finished sync is never committed anyway.
		wp_schedule_single_event( time() + 5, Sudemi_Sync::CRON_FULL );
		Sudemi_Logger::info( __( 'A catalogue sync was queued.', 'sudemi' ) );
		self::back();
	}

	/** Capability and nonce, in that order, on every action without exception. */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sudemi' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function back() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
