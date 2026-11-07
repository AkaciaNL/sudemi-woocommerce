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
		add_action( 'admin_post_sudemi_delivery', array( __CLASS__, 'handle_delivery' ) );
		add_action( 'admin_post_sudemi_support_open', array( __CLASS__, 'handle_support_open' ) );
		add_action( 'admin_post_sudemi_support_reply', array( __CLASS__, 'handle_support_reply' ) );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, nothing changes.
		$tab = isset( $_GET['tab'] ) && 'support' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'support' : 'overview';
		self::render_tabs( $tab );
		if ( 'support' === $tab ) {
			self::render_support( $connected );
			echo '</div>';
			return;
		}

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
		echo '<p>' . esc_html__( 'Connecting this shop lets an AI assistant find your products and send buyers to you. You confirm the connection on sudemi, signed in to your own account - this plugin never asks for your sudemi password.', 'sudemi' ) . '</p>';
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
		self::row( __( 'Shop address', 'sudemi' ), home_url( '/' ), __( 'Fixed. sudemi checked that this shop answered from this address, and a listing tells a buyer where it came from - so changing it means connecting again from the new address.', 'sudemi' ) );
		self::row( __( 'Install', 'sudemi' ), (string) Sudemi_Client::install_id(), __( 'Which installation this is. Revoke it on sudemi to cut this shop off immediately.', 'sudemi' ) );
		self::row( __( 'Signing key', 'sudemi' ), __( 'held on this server', 'sudemi' ), __( 'The private half never leaves this server and is never sent to sudemi. It cannot be shown here either.', 'sudemi' ) );
		echo '</tbody></table>';

		self::render_listing_status();
		self::render_delivery();
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
	 * cause is usually something that has nothing to do with sudemi - WP-Cron disabled on a host
	 * that never set up a real cron, an outbound firewall, a plugin conflict killing scheduled
	 * events - and without this panel the first sign of it is a seller wondering where their
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
				: __( 'Orders wait for someone here to answer them. If nobody does before the deadline sudemi shows, the buyer is released and charged nothing - an unanswered order is never treated as accepted.', 'sudemi' )
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
			echo '<td>' . esc_html( isset( $order['customer_name'] ) ? (string) $order['customer_name'] : ' - ' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/** A merchant-readable status, including what it means for the money. */
	private static function order_status_label( array $order ) {
		$status = isset( $order['status'] ) ? (string) $order['status'] : '';
		switch ( $status ) {
			case 'AWAITING_SELLER':
				return __( 'Waiting for you - buyer not charged', 'sudemi' );
			case 'ACCEPTED':
				return __( 'Accepted - waiting for the other shops in the buyer\'s payment; buyer not charged yet, do not ship', 'sudemi' );
			case 'PAID':
				return __( 'Confirmed and charged', 'sudemi' );
			case 'DECLINED':
				return isset( $order['declined_reason'] )
					? sprintf(
						/* translators: %s: the reason the order was declined. */
						__( 'Declined (%s) - nothing charged', 'sudemi' ),
						(string) $order['declined_reason']
					)
					: __( 'Declined - nothing charged', 'sudemi' );
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

	// ------------------------------------------------------------------ support ----

	private static function render_tabs( $current ) {
		$tabs = array(
			'overview' => __( 'Overview', 'sudemi' ),
			'support'  => __( 'Support', 'sudemi' ),
		);
		echo '<nav class="nav-tab-wrapper" style="margin-bottom:1em">';
		foreach ( $tabs as $key => $label ) {
			$url = admin_url( 'admin.php?page=' . self::SLUG . ( 'overview' === $key ? '' : '&tab=' . $key ) );
			echo '<a class="nav-tab' . ( $current === $key ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">'
				. esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Tickets between this shop and sudemi support.
	 *
	 * Everything comes from sudemi on each view; nothing is stored here except a one-minute
	 * notice (and the unsent draft) after a form is submitted.
	 */
	private static function render_support( $connected ) {
		if ( ! $connected ) {
			echo '<div class="notice notice-info inline"><p>'
				. esc_html__( 'Support is available once this shop is connected to sudemi.', 'sudemi' )
				. '</p></div>';
			return;
		}

		$flash = self::take_support_flash();
		if ( $flash ) {
			echo '<div class="notice ' . ( 'success' === $flash['type'] ? 'notice-success' : 'notice-error' ) . ' inline"><p>'
				. esc_html( $flash['message'] ) . '</p></div>';
		}
		$draft = $flash && isset( $flash['draft'] ) && is_array( $flash['draft'] ) ? $flash['draft'] : array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, nothing changes.
		$ticket_id = isset( $_GET['ticket'] ) ? sanitize_text_field( wp_unslash( $_GET['ticket'] ) ) : '';
		if ( '' !== $ticket_id ) {
			self::render_support_ticket( $ticket_id, $draft );
			return;
		}

		echo '<p>' . esc_html__( 'Questions about an order, a payment, payouts or this plugin? Open a ticket and sudemi support answers here. You also get an e-mail when they reply.', 'sudemi' ) . '</p>';

		echo '<h2>' . esc_html__( 'Your tickets', 'sudemi' ) . '</h2>';
		$list = Sudemi_Support::list_tickets();
		if ( ! $list['ok'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Sudemi_Support::error_message( $list['error'] ) ) . '</p></div>';
		} elseif ( empty( $list['tickets'] ) ) {
			echo '<p class="description">' . esc_html__( 'No tickets yet.', 'sudemi' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:60rem"><thead><tr>'
				. '<th>' . esc_html__( 'Number', 'sudemi' ) . '</th>'
				. '<th>' . esc_html__( 'Subject', 'sudemi' ) . '</th>'
				. '<th>' . esc_html__( 'Status', 'sudemi' ) . '</th>'
				. '<th>' . esc_html__( 'Last updated', 'sudemi' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $list['tickets'] as $ticket ) {
				if ( ! is_array( $ticket ) || empty( $ticket['id'] ) ) {
					continue;
				}
				$url = self::support_url( (string) $ticket['id'] );
				echo '<tr>';
				echo '<td><a href="' . esc_url( $url ) . '"><code>' . esc_html( isset( $ticket['number'] ) ? (string) $ticket['number'] : '' ) . '</code></a></td>';
				echo '<td><a href="' . esc_url( $url ) . '">' . esc_html( isset( $ticket['subject'] ) ? (string) $ticket['subject'] : '' ) . '</a><br />'
					. '<span class="description">' . esc_html( Sudemi_Support::category_label( isset( $ticket['category'] ) ? (string) $ticket['category'] : '' ) ) . '</span></td>';
				echo '<td>' . esc_html( Sudemi_Support::status_label( isset( $ticket['status'] ) ? (string) $ticket['status'] : '' ) ) . '</td>';
				echo '<td>' . esc_html( self::support_time( isset( $ticket['updatedAt'] ) ? $ticket['updatedAt'] : '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		self::render_support_open_form( $draft );
	}

	private static function render_support_open_form( array $draft ) {
		$value = function ( $key ) use ( $draft ) {
			return isset( $draft[ $key ] ) && is_string( $draft[ $key ] ) ? $draft[ $key ] : '';
		};

		echo '<h2>' . esc_html__( 'Open a ticket', 'sudemi' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:48rem">';
		wp_nonce_field( 'sudemi_support_open' );
		echo '<input type="hidden" name="action" value="sudemi_support_open" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="sudemi-support-category">' . esc_html__( 'Category', 'sudemi' ) . '</label></th><td>'
			. '<select id="sudemi-support-category" name="category" required>';
		foreach ( Sudemi_Support::CATEGORIES as $category ) {
			echo '<option value="' . esc_attr( $category ) . '"' . selected( $value( 'category' ), $category, false ) . '>'
				. esc_html( Sudemi_Support::category_label( $category ) ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="sudemi-support-subject">' . esc_html__( 'Subject', 'sudemi' ) . '</label></th><td>'
			. '<input type="text" id="sudemi-support-subject" name="subject" class="regular-text" required minlength="'
			. esc_attr( Sudemi_Support::SUBJECT_MIN ) . '" maxlength="' . esc_attr( Sudemi_Support::SUBJECT_MAX ) . '" value="'
			. esc_attr( $value( 'subject' ) ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="sudemi-support-message">' . esc_html__( 'Message', 'sudemi' ) . '</label></th><td>'
			. '<textarea id="sudemi-support-message" name="message" rows="8" class="large-text" required maxlength="'
			. esc_attr( Sudemi_Support::MESSAGE_MAX ) . '">' . esc_textarea( $value( 'message' ) ) . '</textarea></td></tr>';

		echo '<tr><th scope="row"><label for="sudemi-support-order">' . esc_html__( 'sudemi order ID (optional)', 'sudemi' ) . '</label></th><td>'
			. '<input type="text" id="sudemi-support-order" name="order_id" class="regular-text code" maxlength="36" value="'
			. esc_attr( $value( 'order_id' ) ) . '" />'
			. '<p class="description">' . esc_html__( 'The full order ID as sudemi shows it, if your question is about one order.', 'sudemi' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Send ticket', 'sudemi' ), 'primary', 'submit', false );
		echo '</form>';
	}

	private static function render_support_ticket( $ticket_id, array $draft ) {
		echo '<p><a href="' . esc_url( self::support_url() ) . '">&larr; ' . esc_html__( 'Back to your tickets', 'sudemi' ) . '</a></p>';

		$result = Sudemi_Support::get_ticket( $ticket_id );
		if ( ! $result['ok'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( Sudemi_Support::error_message( $result['error'] ) ) . '</p></div>';
			return;
		}
		$ticket = $result['ticket'];

		echo '<h2>' . esc_html(
			sprintf(
				/* translators: 1: ticket number, e.g. S-000123; 2: ticket subject. */
				__( 'Ticket %1$s: %2$s', 'sudemi' ),
				isset( $ticket['number'] ) ? (string) $ticket['number'] : '',
				isset( $ticket['subject'] ) ? (string) $ticket['subject'] : ''
			)
		) . '</h2>';
		echo '<p class="description">'
			. esc_html( Sudemi_Support::category_label( isset( $ticket['category'] ) ? (string) $ticket['category'] : '' ) )
			. ' &middot; ' . esc_html( Sudemi_Support::status_label( isset( $ticket['status'] ) ? (string) $ticket['status'] : '' ) )
			. ( ! empty( $ticket['orderId'] ) ? ' &middot; ' . esc_html__( 'Order', 'sudemi' ) . ' <code>' . esc_html( (string) $ticket['orderId'] ) . '</code>' : '' )
			. '</p>';

		$messages = isset( $ticket['messages'] ) && is_array( $ticket['messages'] ) ? $ticket['messages'] : array();
		echo '<div style="max-width:48rem">';
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$from_support = isset( $message['author'] ) && 'SUPPORT' === $message['author'];
			$style        = $from_support
				? 'background:#f0f6fc;border-left:4px solid #2271b1;margin:0 3rem 1em 0;'
				: 'background:#f6f7f7;border-left:4px solid #8c8f94;margin:0 0 1em 3rem;';
			echo '<div style="' . esc_attr( $style . 'padding:.75em 1em' ) . '">';
			echo '<p style="margin:0 0 .5em"><strong>'
				. esc_html( $from_support ? __( 'sudemi support', 'sudemi' ) : __( 'You', 'sudemi' ) )
				. '</strong> <span class="description">'
				. esc_html( self::support_time( isset( $message['createdAt'] ) ? $message['createdAt'] : '' ) )
				. '</span></p>';
			// Plain text from either side: escaped, never rendered as HTML; line breaks kept.
			echo '<div style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( isset( $message['body'] ) ? (string) $message['body'] : '' ) . '</div>';
			echo '</div>';
		}
		echo '</div>';

		echo '<h3>' . esc_html__( 'Reply', 'sudemi' ) . '</h3>';
		if ( isset( $ticket['status'] ) && 'CLOSED' === $ticket['status'] ) {
			echo '<p class="description">' . esc_html__( 'This ticket is closed. Replying opens it again.', 'sudemi' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:48rem">';
		wp_nonce_field( 'sudemi_support_reply' );
		echo '<input type="hidden" name="action" value="sudemi_support_reply" />';
		echo '<input type="hidden" name="ticket" value="' . esc_attr( $ticket_id ) . '" />';
		echo '<p><textarea name="message" rows="6" class="large-text" required maxlength="' . esc_attr( Sudemi_Support::MESSAGE_MAX ) . '" aria-label="'
			. esc_attr__( 'Reply', 'sudemi' ) . '">' . esc_textarea( isset( $draft['message'] ) && is_string( $draft['message'] ) ? $draft['message'] : '' ) . '</textarea></p>';
		submit_button( __( 'Send reply', 'sudemi' ), 'primary', 'submit', false );
		echo '</form>';
	}

	public static function handle_support_open() {
		self::guard( 'sudemi_support_open' );
		$draft = array(
			'category' => isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '',
			'subject'  => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
			'message'  => self::posted_message(),
			'order_id' => isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '',
		);
		$draft['category'] = strtoupper( $draft['category'] );
		$result = Sudemi_Support::open_ticket( $draft['category'], $draft['subject'], $draft['message'], $draft['order_id'], get_user_locale() );
		if ( $result['ok'] ) {
			self::put_support_flash( 'success', __( 'Your ticket was sent. sudemi support answers here, and by e-mail.', 'sudemi' ) );
			self::support_back( isset( $result['ticket']['id'] ) && Sudemi_Support::is_ticket_id( $result['ticket']['id'] ) ? $result['ticket']['id'] : '' );
		}
		self::put_support_flash( 'error', Sudemi_Support::error_message( $result['error'] ), $draft );
		self::support_back();
	}

	public static function handle_support_reply() {
		self::guard( 'sudemi_support_reply' );
		$ticket  = isset( $_POST['ticket'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket'] ) ) : '';
		$message = self::posted_message();
		if ( ! Sudemi_Support::is_ticket_id( $ticket ) ) {
			self::put_support_flash( 'error', Sudemi_Support::error_message( 'not_found' ) );
			self::support_back();
		}
		$result = Sudemi_Support::reply( $ticket, $message );
		if ( $result['ok'] ) {
			self::put_support_flash( 'success', __( 'Your reply was sent.', 'sudemi' ) );
		} else {
			self::put_support_flash( 'error', Sudemi_Support::error_message( $result['error'] ), array( 'message' => $message ) );
		}
		self::support_back( $ticket );
	}

	/**
	 * The message as typed. Not run through sanitize_textarea_field(), which would silently drop
	 * anything that looks like a tag -- and a pasted error message often does. It is sent as JSON
	 * and is escaped wherever it is shown, here and on sudemi; only invalid UTF-8 and control
	 * characters other than line breaks and tabs are removed.
	 */
	private static function posted_message() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in guard(); cleaned below.
		$raw = isset( $_POST['message'] ) ? (string) wp_unslash( $_POST['message'] ) : '';
		$raw = wp_check_invalid_utf8( $raw );
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw );
	}

	private static function support_url( $ticket_id = '' ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG . '&tab=support' );
		return '' === $ticket_id ? $url : add_query_arg( 'ticket', rawurlencode( $ticket_id ), $url );
	}

	private static function support_back( $ticket_id = '' ) {
		wp_safe_redirect( self::support_url( $ticket_id ) );
		exit;
	}

	private static function support_flash_key() {
		return 'sudemi_support_flash_' . get_current_user_id();
	}

	/** Per admin user, one minute: the result of the form just sent, and the draft if it failed. */
	private static function put_support_flash( $type, $message, array $draft = array() ) {
		set_transient( self::support_flash_key(), array( 'type' => $type, 'message' => $message, 'draft' => $draft ), MINUTE_IN_SECONDS );
	}

	private static function take_support_flash() {
		$flash = get_transient( self::support_flash_key() );
		if ( false === $flash ) {
			return null;
		}
		delete_transient( self::support_flash_key() );
		return is_array( $flash ) && isset( $flash['type'], $flash['message'] ) ? $flash : null;
	}

	private static function support_time( $iso ) {
		$ts = is_string( $iso ) && '' !== $iso ? strtotime( $iso ) : false;
		return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
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

	/**
	 * Shipping prices and delivery times as sudemi buyers see them.
	 *
	 * Prices come from this shop's WooCommerce shipping zones (a country with no fixed price is
	 * not offered to buyers there); the delivery times are this plugin's own two settings.
	 */
	private static function render_delivery() {
		echo '<h2>' . esc_html__( 'Delivery to sudemi buyers', 'sudemi' ) . '</h2>';
		$sample = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'virtual' => false ) );
		$rates  = $sample ? Sudemi_Shipping::rates_for( $sample[0] ) : array();
		if ( empty( $rates ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No shipping price could be read from your WooCommerce shipping zones, so sudemi buyers cannot order. Add a zone with a flat rate (a fixed amount) or unconditional free shipping for the countries you ship to.', 'sudemi' ) . '</p></div>';
		} else {
			$list = array();
			foreach ( $rates as $rate ) {
				$list[] = $rate['country'] . ' ' . wc_price( $rate['costMinor'] / 100 );
			}
			echo '<p>' . esc_html__( 'Shipping prices sent to sudemi (from your shipping zones; one item, your first product):', 'sudemi' ) . ' ' . wp_kses_post( implode( ', ', $list ) ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Countries without a fixed price (cost formulas, free shipping with a minimum, postcode-only zones) are left out rather than guessed.', 'sudemi' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sudemi_delivery' );
		echo '<input type="hidden" name="action" value="sudemi_delivery" />';
		echo '<p><label>' . esc_html__( 'Days until you dispatch an order (at most)', 'sudemi' ) . ' <input type="number" min="0" max="60" name="handling_days" value="' . esc_attr( Sudemi_Shipping::handling_days() ) . '" class="small-text" /></label></p>';
		echo '<p><label>' . esc_html__( 'Days the carrier takes (at most)', 'sudemi' ) . ' <input type="number" min="0" max="60" name="transit_days" value="' . esc_attr( Sudemi_Shipping::transit_days() ) . '" class="small-text" /></label></p>';
		echo '<p class="description">' . esc_html__( 'Buyers see the sum as the latest delivery estimate. Set your real times; the defaults are deliberately cautious.', 'sudemi' ) . '</p>';
		submit_button( __( 'Save delivery times', 'sudemi' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	public static function handle_delivery() {
		self::guard( 'sudemi_delivery' );
		foreach ( array( 'handling_days' => Sudemi_Shipping::OPTION_HANDLING_DAYS, 'transit_days' => Sudemi_Shipping::OPTION_TRANSIT_DAYS ) as $field => $option ) {
			if ( isset( $_POST[ $field ] ) && is_numeric( wp_unslash( $_POST[ $field ] ) ) ) {
				update_option( $option, max( 0, min( 60, (int) wp_unslash( $_POST[ $field ] ) ) ), false );
			}
		}
		// Every product carries the times, so the catalogue is sent again.
		wp_schedule_single_event( time() + 5, Sudemi_Sync::CRON_FULL );
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
