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

	/** The pages of the sudemi menu, in order (the `tab` of admin.php?page=sudemi). */
	const PAGES = array( 'dashboard', 'products', 'orders', 'shipping', 'payouts', 'profile', 'support', 'settings' );

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'current_submenu' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SUDEMI_PLUGIN_FILE ), array( __CLASS__, 'plugin_links' ) );
		add_action( 'admin_post_sudemi_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_sudemi_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_sudemi_sync_now', array( __CLASS__, 'handle_sync_now' ) );
		add_action( 'admin_post_sudemi_delivery', array( __CLASS__, 'handle_delivery' ) );
		add_action( 'admin_post_sudemi_support_open', array( __CLASS__, 'handle_support_open' ) );
		add_action( 'admin_post_sudemi_support_reply', array( __CLASS__, 'handle_support_reply' ) );
		add_action( 'admin_post_sudemi_request_answer', array( __CLASS__, 'handle_request_answer' ) );
		// On the WooCommerce order screen, above the order form (forms cannot be nested in it).
		add_action( 'admin_notices', array( __CLASS__, 'render_order_screen_request' ) );
		Sudemi_Admin_Manage::boot();
		Sudemi_Admin_Insights::boot();
	}

	/** The label of each page in the menu and the page navigation. */
	public static function page_label( $page ) {
		switch ( $page ) {
			case 'dashboard':
				return __( 'Dashboard', 'sudemi' );
			case 'products':
				return __( 'Products on sudemi', 'sudemi' );
			case 'orders':
				return __( 'Orders', 'sudemi' );
			case 'shipping':
				return __( 'Shipping', 'sudemi' );
			case 'payouts':
				return __( 'Payouts', 'sudemi' );
			case 'profile':
				return __( 'Shop profile', 'sudemi' );
			case 'support':
				return __( 'Support', 'sudemi' );
			case 'settings':
				return __( 'Settings', 'sudemi' );
		}
		return '';
	}

	/** admin.php?page=sudemi&tab=<page> (the dashboard without a tab). */
	public static function page_url( $page = 'dashboard', array $args = array() ) {
		$base = array( 'page' => self::SLUG );
		if ( 'dashboard' !== $page ) {
			$base['tab'] = $page;
		}
		return add_query_arg( array_merge( $base, $args ), admin_url( 'admin.php' ) );
	}

	/** The page asked for; the old "overview" is the dashboard. */
	public static function current_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, nothing changes.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		return in_array( $tab, self::PAGES, true ) ? $tab : 'dashboard';
	}

	/**
	 * A top-level sudemi menu with one entry per page. The entries are links to
	 * admin.php?page=sudemi&tab=..., one screen with one capability check, so every page keeps
	 * the same address it had under WooCommerce.
	 */
	public static function add_page() {
		add_menu_page(
			__( 'sudemi', 'sudemi' ),
			__( 'sudemi', 'sudemi' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' ),
			self::menu_icon(),
			56
		);
		foreach ( self::PAGES as $page ) {
			add_submenu_page(
				self::SLUG,
				self::page_label( $page ),
				self::page_label( $page ),
				self::CAPABILITY,
				'dashboard' === $page ? self::SLUG : 'admin.php?page=' . self::SLUG . '&tab=' . $page,
				'dashboard' === $page ? array( __CLASS__, 'render' ) : null
			);
		}
	}

	/** Highlights the menu entry of the page being shown. */
	public static function current_submenu( $submenu_file, $parent_file = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, nothing changes.
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return $submenu_file;
		}
		$page = self::current_page();
		return 'dashboard' === $page ? self::SLUG : 'admin.php?page=' . self::SLUG . '&tab=' . $page;
	}

	public static function plugin_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Dashboard', 'sudemi' ) . '</a>'
		);
		return $links;
	}

	/** The sudemi mark for the admin menu (WordPress colours it like the other menu icons). */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17Zm3.2 11.6c-.6 1.1-1.9 1.7-3.6 1.7-1.5 0-2.9-.5-3.8-1.3l.9-1.5c.8.6 1.9 1 2.9 1 1 0 1.6-.4 1.6-1 0-.7-.7-.9-2-1.3-1.6-.4-3.1-1-3.1-2.8 0-1.7 1.5-2.9 3.6-2.9 1.3 0 2.5.4 3.3 1l-.8 1.5c-.7-.5-1.6-.8-2.5-.8-.9 0-1.4.4-1.4.9 0 .6.7.9 1.9 1.2 1.7.5 3.2 1.1 3.2 2.9 0 .5-.1.9-.2 1.4Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- an SVG data URI, the format WordPress takes for menu icons.
	}

	/** The sudemi screens' own stylesheet, only on them. */
	public static function enqueue( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, nothing changes.
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		wp_enqueue_style( 'sudemi-admin', plugins_url( 'assets/admin.css', SUDEMI_PLUGIN_FILE ), array(), SUDEMI_VERSION );
	}

	/** sudemi's seller portal, for what is managed there (company details, payout account). */
	public static function portal_url( $path = '/seller' ) {
		$parts = wp_parse_url( Sudemi_Client::api_base() );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return 'https://sudemi.com' . $path;
		}
		$host = preg_replace( '/^api\./', '', (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $host . $port . $path;
	}

	/**
	 * The connection in one word, for the header: live, connected but not shown, cannot reach
	 * sudemi, waiting for the claim, or not connected.
	 *
	 * @return array{0:string,1:string} css state, label.
	 */
	public static function connection_state() {
		if ( ! Sudemi_Client::is_connected() ) {
			return Sudemi_Pairing::claim_url()
				? array( 'waiting', __( 'Waiting for your confirmation', 'sudemi' ) )
				: array( 'off', __( 'Not connected', 'sudemi' ) );
		}
		$status = Sudemi_Heartbeat::status();
		if ( empty( $status ) ) {
			return array( 'waiting', __( 'Connected, first report pending', 'sudemi' ) );
		}
		if ( empty( $status['reachable'] ) ) {
			return array( 'error', __( 'Cannot reach sudemi', 'sudemi' ) );
		}
		if ( empty( $status['connected'] ) ) {
			return array( 'error', __( 'Refused by sudemi', 'sudemi' ) );
		}
		if ( empty( $status['listed'] ) ) {
			return array( 'warning', __( 'Connected, products not shown', 'sudemi' ) );
		}
		// The last answer said "listed", but sudemi pauses a shop that stops reporting in.
		if ( self::reporting_stale() ) {
			return array( 'warning', __( 'Not reporting in to sudemi', 'sudemi' ) );
		}
		return array( 'live', __( 'Live on sudemi', 'sudemi' ) );
	}

	/** No successful report for 15 minutes: sudemi has paused this shop's products by now. */
	public static function reporting_stale() {
		$last_ok = Sudemi_Heartbeat::last_ok();
		return ! $last_ok || $last_ok < time() - 15 * MINUTE_IN_SECONDS;
	}

	/** Brand bar, connection state and the page navigation, on every sudemi page. */
	private static function render_header( $current ) {
		list( $state, $label ) = self::connection_state();
		echo '<div class="sudemi-header">';
		echo '<div class="sudemi-brand"><span class="sudemi-logo" aria-hidden="true">s</span>'
			. '<span class="sudemi-brand-text"><span class="sudemi-wordmark">sudemi</span>'
			. '<span class="sudemi-brand-shop">' . esc_html( get_bloginfo( 'name' ) ) . '</span></span></div>';
		echo '<div class="sudemi-header-meta">'
			. '<span class="sudemi-pill sudemi-pill-' . esc_attr( $state ) . '"><span class="sudemi-dot" aria-hidden="true"></span>' . esc_html( $label ) . '</span>'
			. '<a class="sudemi-header-link" href="' . esc_url( self::portal_url() ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Seller portal', 'sudemi' ) . ' <span aria-hidden="true">&#8599;</span></a></div>';
		echo '</div>';
		echo '<nav class="sudemi-nav" aria-label="' . esc_attr__( 'sudemi', 'sudemi' ) . '">';
		foreach ( self::PAGES as $page ) {
			echo '<a class="sudemi-nav-item' . ( $current === $page ? ' is-active' : '' ) . '" href="' . esc_url( self::page_url( $page ) ) . '"'
				. ( $current === $page ? ' aria-current="page"' : '' ) . '>' . esc_html( self::page_label( $page ) ) . '</a>';
		}
		echo '</nav>';
		self::render_checkout_mode();
	}

	/**
	 * How buyers pay this shop (docs/seller-modes.md), on every sudemi page: through sudemi
	 * (green), on the shop's own website until Stripe is connected (red, with the button to
	 * connect it), or paused over a payment provider restriction (red, with support). Nothing
	 * when sudemi did not say: the page never depends on it.
	 */
	private static function render_checkout_mode() {
		try {
			$mode = Sudemi_Insights::checkout_mode();
		} catch ( Throwable $e ) {
			$mode = null;
		}
		if ( null === $mode ) {
			return;
		}
		$lines  = array();
		$action = '';
		switch ( $mode['mode'] ) {
			case 'SHOP':
				$tone    = 'error';
				$icon    = '!';
				$title   = __( 'Stripe is not connected. Your products are listed on sudemi, Connect Stripe', 'sudemi' );
				$lines[] = __( 'Until then buyers pay on your own website: free listing, no commission.', 'sudemi' );
				if ( 'stripe_requirements_due' === $mode['reason'] ) {
					$lines[] = __( 'Stripe needs more information from you.', 'sudemi' );
				}
				if ( '' !== $mode['url'] ) {
					$action = '<a class="button button-primary" href="' . esc_url( $mode['url'] ) . '" target="_blank" rel="noopener noreferrer">'
						. esc_html__( 'Connect Stripe', 'sudemi' ) . ' <span aria-hidden="true">&#8599;</span></a>';
				}
				break;
			case 'SUSPENDED':
				$tone    = 'error';
				$icon    = '!';
				$title   = __( 'Your shop is paused on sudemi while we review a payment provider restriction', 'sudemi' );
				$lines[] = __( 'Your products are not shown for now. We e-mail you what is needed.', 'sudemi' );
				$action  = '<a class="button" href="' . esc_url( self::page_url( 'support' ) ) . '">' . esc_html__( 'Contact support', 'sudemi' ) . '</a>';
				break;
			default:
				$tone    = 'success';
				$icon    = '&#10003;';
				$title   = __( 'Connect Stripe is done', 'sudemi' );
				$lines[] = __( 'Buyers pay through sudemi, and you get your orders and payouts here.', 'sudemi' );
		}
		echo '<div class="sudemi-alert sudemi-alert-' . esc_attr( $tone ) . ' sudemi-mode" data-sudemi-checkout="' . esc_attr( $mode['mode'] ) . '"'
			. ' role="' . ( 'success' === $tone ? 'status' : 'alert' ) . '">'
			. '<div class="sudemi-mode-body"><span class="sudemi-mode-icon" aria-hidden="true">' . $icon . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed entity.
			. '<div class="sudemi-mode-text"><strong>' . esc_html( $title ) . '</strong>';
		foreach ( $lines as $line ) {
			echo '<span>' . esc_html( $line ) . '</span>';
		}
		echo '</div></div>' . $action . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped above.
	}

	/** The page title row, with optional actions on the right. */
	public static function page_title( $title, $subtitle = '', $actions_html = '' ) {
		echo '<div class="sudemi-titlebar"><div><h1 class="sudemi-title">' . esc_html( $title ) . '</h1>';
		if ( '' !== $subtitle ) {
			echo '<p class="sudemi-subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div>';
		if ( '' !== $actions_html ) {
			echo '<div class="sudemi-title-actions">' . $actions_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped by the callers.
		}
		echo '</div><hr class="wp-header-end" />';
	}

	/** An empty or "not connected yet" state with a call to action. */
	public static function empty_state( $title, $text, $action_html = '' ) {
		echo '<div class="sudemi-card sudemi-empty"><span class="sudemi-empty-mark" aria-hidden="true">s</span><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p>';
		if ( '' !== $action_html ) {
			echo '<p>' . $action_html . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped by the callers.
		}
		echo '</div>';
	}

	public static function render() {
		// Checked again here, not only when registering the menu: a page callback is reachable
		// by anyone who knows its slug if the menu check is the only one.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this shop.', 'sudemi' ) );
		}

		$connected = Sudemi_Client::is_connected();
		$page      = self::current_page();

		echo '<div class="wrap sudemi-admin">';
		self::render_header( $page );
		echo '<div class="sudemi-page sudemi-page-' . esc_attr( $page ) . '">';

		if ( ! Sudemi_Keys::is_supported() ) {
			echo '<div class="notice notice-error inline"><p>'
				. esc_html__( 'This server is missing the sodium extension. The connection needs it to sign requests, and will not work without it. Ask your host to enable it.', 'sudemi' )
				. '</p></div>';
		}

		switch ( $page ) {
			case 'products':
				Sudemi_Admin_Insights::render_products();
				break;
			case 'orders':
				self::page_title( self::page_label( 'orders' ), __( 'Orders buyers placed through their AI assistant. Accept, ship, refund and export them here.', 'sudemi' ) );
				if ( $connected ) {
					self::render_requests();
				}
				Sudemi_Admin_Manage::render_orders();
				break;
			case 'shipping':
				self::page_title( self::page_label( 'shipping' ), __( 'What sudemi buyers pay for delivery, per country.', 'sudemi' ) );
				Sudemi_Admin_Manage::render_shipping();
				break;
			case 'payouts':
				self::page_title( self::page_label( 'payouts' ), __( 'What sudemi has paid you and what is still to come.', 'sudemi' ) );
				Sudemi_Admin_Manage::render_payouts();
				break;
			case 'profile':
				Sudemi_Admin_Insights::render_profile();
				break;
			case 'support':
				self::page_title( self::page_label( 'support' ), __( 'Questions about an order, a payment, payouts or this plugin? sudemi support answers here.', 'sudemi' ) );
				self::render_support( $connected );
				break;
			case 'settings':
				self::page_title( self::page_label( 'settings' ), __( 'The connection with sudemi, delivery times, currency and the shop\'s reporting.', 'sudemi' ) );
				self::render_settings();
				break;
			default:
				if ( $connected ) {
					Sudemi_Admin_Insights::render_dashboard();
				} else {
					self::page_title( __( 'Sell through AI assistants', 'sudemi' ) );
					self::render_onboarding();
				}
		}

		echo '</div></div>';
	}

	/** The first screen of a shop that is not connected: what sudemi is and the one button. */
	private static function render_onboarding() {
		$claim_url = Sudemi_Pairing::claim_url();
		echo '<div class="sudemi-card sudemi-hero">';
		echo '<div class="sudemi-hero-text"><h2>' . esc_html__( 'Let ChatGPT, Claude and other AI assistants sell your products', 'sudemi' ) . '</h2>';
		echo '<p>' . esc_html__( 'Buyers ask their AI assistant for a product. sudemi shows them your products with the price and delivery to their country, and the order arrives here as a normal WooCommerce order. You stay the seller; sudemi takes a commission only on what sells.', 'sudemi' ) . '</p>';
		echo '<ol class="sudemi-steps">'
			. '<li><strong>' . esc_html__( 'Connect', 'sudemi' ) . '</strong> ' . esc_html__( 'Click the button and confirm on sudemi that this shop is yours.', 'sudemi' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Your products go live', 'sudemi' ) . '</strong> ' . esc_html__( 'Your catalogue, prices, stock and shipping are sent and kept up to date by themselves.', 'sudemi' ) . '</li>'
			. '<li><strong>' . esc_html__( 'Orders come in', 'sudemi' ) . '</strong> ' . esc_html__( 'You confirm, ship, and are paid out by sudemi.', 'sudemi' ) . '</li>'
			. '</ol></div>';
		echo '<div class="sudemi-hero-action">';
		if ( Sudemi_Client::is_connected() ) {
			echo '<p>' . esc_html__( 'This shop is connected to sudemi.', 'sudemi' ) . '</p>';
		} elseif ( $claim_url ) {
			self::render_waiting( $claim_url );
		} else {
			self::render_disconnected( Sudemi_Keys::is_supported() );
		}
		echo '</div></div>';
	}

	/** The old overview: connection, listing status, currency, delivery times, cron, log. */
	private static function render_settings() {
		if ( ! Sudemi_Client::is_connected() ) {
			self::render_onboarding();
			self::render_log();
			return;
		}
		self::render_connected();
		self::render_log();
	}

	private static function render_disconnected( $supported ) {
		echo '<p class="description">' . esc_html__( 'You confirm the connection on sudemi, signed in to your own account. This plugin never asks for your sudemi password.', 'sudemi' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sudemi_connect' );
		echo '<input type="hidden" name="action" value="sudemi_connect" />';
		submit_button( __( 'Connect this shop', 'sudemi' ), 'primary large', 'submit', false, $supported ? array() : array( 'disabled' => 'disabled' ) );
		echo '</form>';
	}

	private static function render_waiting( $claim_url ) {
		echo '<div class="notice notice-info"><p>'
			. esc_html__( 'Almost there. Open the link below, sign in to sudemi and confirm that this shop is yours. This page will pick it up by itself.', 'sudemi' )
			. '</p></div>';
		echo '<p><a class="button button-primary button-large" target="_blank" rel="noopener noreferrer" href="'
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
		echo '<div class="sudemi-grid sudemi-grid-2">';
		echo '<section class="sudemi-card"><h2 class="sudemi-card-title">' . esc_html__( 'Connection', 'sudemi' ) . '</h2>';
		echo '<table class="sudemi-kv"><tbody>';
		self::row( __( 'Shop address', 'sudemi' ), home_url( '/' ), __( 'Fixed. sudemi checked that this shop answered from this address, and a listing tells a buyer where it came from - so changing it means connecting again from the new address.', 'sudemi' ) );
		self::row( __( 'Install', 'sudemi' ), (string) Sudemi_Client::install_id(), __( 'Which installation this is. Revoke it on sudemi to cut this shop off immediately.', 'sudemi' ) );
		self::row( __( 'Signing key', 'sudemi' ), __( 'held on this server', 'sudemi' ), __( 'The private half never leaves this server and is never sent to sudemi. It cannot be shown here either.', 'sudemi' ) );
		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Set on sudemi, not here', 'sudemi' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Your legal name, country, trader status and VAT number decide what a buyer is owed, so they are confirmed once on sudemi and changed there. This plugin cannot edit them.', 'sudemi' ) . '</p>';
		echo '<div class="sudemi-actions-row">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sudemi_sync_now' );
		echo '<input type="hidden" name="action" value="sudemi_sync_now" />';
		submit_button( __( 'Sync the catalogue now', 'sudemi' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(this.dataset.confirm);" data-confirm="'
			. esc_attr__( 'Disconnect this shop from sudemi? Its products stop being shown to buyers right away.', 'sudemi' ) . '">';
		wp_nonce_field( 'sudemi_disconnect' );
		echo '<input type="hidden" name="action" value="sudemi_disconnect" />';
		submit_button( __( 'Disconnect', 'sudemi' ), 'delete', 'submit', false );
		echo '</form></div>';
		echo '</section>';

		echo '<section class="sudemi-card">';
		self::render_listing_status();
		echo '</section></div>';

		echo '<div class="sudemi-grid sudemi-grid-2">';
		echo '<section class="sudemi-card">';
		self::render_delivery();
		echo '</section>';
		echo '<section class="sudemi-card">';
		self::render_currency();
		echo '</section></div>';

		echo '<section class="sudemi-card">';
		self::render_cron_advice();
		echo '</section>';
	}

	/**
	 * The shop's currency as sudemi sees it: what buyers' cards are charged in and what orders
	 * arrive in. A currency sudemi does not support means nothing can be listed or sold.
	 */
	private static function render_currency() {
		$shop      = Sudemi_Currency::shop_currency();
		$confirmed = Sudemi_Currency::confirmed();
		$refused   = (string) get_option( Sudemi_Currency::OPTION_REFUSED, '' );

		echo '<h2 class="sudemi-card-title">' . esc_html__( 'Currency', 'sudemi' ) . '</h2>';
		echo '<table class="sudemi-kv"><tbody>';
		self::row(
			__( 'Your shop currency', 'sudemi' ),
			$shop,
			__( 'From your WooCommerce settings. Your prices are sent to sudemi in it, buyers pay in it, and orders arrive in it.', 'sudemi' )
		);
		self::row(
			__( 'Currency sudemi has for your shop', 'sudemi' ),
			'' === $confirmed ? __( 'not confirmed yet', 'sudemi' ) : $confirmed,
			__( 'What sudemi answered the last time this shop sent its details. It updates after the next sync.', 'sudemi' )
		);
		echo '</tbody></table>';

		if ( ! Sudemi_Currency::is_supported( $shop ) || '' !== $refused ) {
			echo '<div class="notice notice-error inline"><p>'
				. esc_html(
					sprintf(
						/* translators: 1: the shop's currency, 2: the list of supported currencies. */
						__( 'sudemi cannot sell in %1$s yet, so your products are not shown to buyers and no orders can come in. Currencies sudemi supports: %2$s.', 'sudemi' ),
						$shop,
						implode( ', ', Sudemi_Currency::SUPPORTED )
					)
				)
				. '</p></div>';
		} elseif ( '' !== $confirmed && $confirmed !== $shop ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html(
					sprintf(
						/* translators: 1: the currency sudemi has, 2: the shop's currency. */
						__( 'sudemi still has %1$s for your shop, but your shop sells in %2$s. Orders in %1$s are declined until this matches. Click "Sync the catalogue now" to send the new currency.', 'sudemi' ),
						$confirmed,
						$shop
					)
				)
				. '</p></div>';
		}
		if ( 'EUR' !== $shop && Sudemi_Currency::is_supported( $shop ) ) {
			echo '<p class="description">'
				. esc_html__( 'Buyers pay in your currency. sudemi\'s payouts to you are in euros: the payment provider\'s currency conversion fee (about 2%) is taken from your payout, as the terms say.', 'sudemi' )
				. '</p>';
		}
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

		echo '<h2 class="sudemi-card-title">' . esc_html__( 'Listing status', 'sudemi' ) . '</h2>';

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
		} elseif ( self::reporting_stale() ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'This shop has not reported in to sudemi for more than 15 minutes, so its products are paused. Set up the cron job below.', 'sudemi' )
				. '</p></div>';
		} else {
			echo '<div class="notice notice-success inline"><p>'
				. esc_html__( 'Products are being shown on sudemi.', 'sudemi' )
				. '</p></div>';
		}

		$last_ok = Sudemi_Heartbeat::last_ok();
		/* translators: %s: human-readable time difference, e.g. "4 mins". */
		$ok_when = $last_ok ? sprintf( __( '%s ago', 'sudemi' ), human_time_diff( $last_ok, time() ) ) : __( 'never', 'sudemi' );

		echo '<table class="sudemi-kv"><tbody>';
		self::row( __( 'Last reported in', 'sudemi' ), $when, __( 'This shop tells sudemi every five minutes that it is still here and that its prices still stand. When that stops, the listings are paused rather than left at prices nobody is confirming.', 'sudemi' ) );
		self::row(
			__( 'Last successful report', 'sudemi' ),
			$ok_when,
			( ! $last_ok || $last_ok < time() - 15 * MINUTE_IN_SECONDS )
				? __( 'More than 15 minutes ago: sudemi pauses this shop\'s products until it reports in again. Set up the cron job below.', 'sudemi' )
				: __( 'sudemi answered the last report in time.', 'sudemi' )
		);
		$calls = Sudemi_Order_Calls::count();
		if ( $calls > 0 ) {
			self::row( __( 'Order updates waiting', 'sudemi' ), (string) $calls, __( 'Shipped, delivered or cancelled orders that sudemi has not heard about yet. They are sent again on every report until sudemi answers.', 'sudemi' ) );
		}
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

	/**
	 * WP-Cron only runs when someone visits the site, so a quiet shop stops reporting in and
	 * sudemi pauses its products. A real cron entry fixes that; shown with the exact lines.
	 */
	private static function render_cron_advice() {
		$cron_url = site_url( 'wp-cron.php?doing_wp_cron' );
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		echo '<h2 class="sudemi-card-title">' . esc_html__( 'Keep this shop reporting in (cron)', 'sudemi' ) . '</h2>';
		echo '<p>' . esc_html__( 'WordPress runs its scheduled tasks (WP-Cron) only when someone visits the site. In a quiet hour nobody does, this shop stops reporting in, and sudemi pauses its products and cannot ask it about new orders. Let the server call WP-Cron every 5 minutes instead.', 'sudemi' ) . '</p>';
		echo '<p>' . esc_html__( '1. Add this line to the server\'s crontab (crontab -e), or as a cron job in your hosting panel:', 'sudemi' ) . '</p>';
		echo '<p><code>' . esc_html( '*/5 * * * * wget -q -O - "' . $cron_url . '" >/dev/null 2>&1' ) . '</code></p>';
		echo '<p>' . esc_html__( '2. Then switch off the visit-driven WP-Cron by adding this line to wp-config.php, above the line that says to stop editing:', 'sudemi' ) . '</p>';
		echo '<p><code>' . esc_html( "define( 'DISABLE_WP_CRON', true );" ) . '</code></p>';
		echo '<p class="description">' . esc_html(
			$disabled
				? __( 'WP-Cron is switched off on this site (DISABLE_WP_CRON), so the cron job in step 1 is required: without it this shop never reports in.', 'sudemi' )
				: __( 'WP-Cron is still visit-driven on this site. It works on busy shops, but a real cron job is more reliable.', 'sudemi' )
		) . '</p>';
	}

	private static function row( $label, $value, $note ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><span class="sudemi-kv-value">'
			. esc_html( $value ) . '</span><p class="description">' . esc_html( $note )
			. '</p></td></tr>';
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
			case 'CANCELLED':
				return __( 'Cancelled', 'sudemi' );
			case 'REFUNDED':
				return __( 'Refunded', 'sudemi' );
			default:
				return $status;
		}
	}

	private static function render_log() {
		$entries = Sudemi_Logger::entries();
		if ( empty( $entries ) ) {
			return;
		}
		echo '<section class="sudemi-card"><h2 class="sudemi-card-title">' . esc_html__( 'Recent activity', 'sudemi' ) . '</h2>';
		echo '<table class="sudemi-table sudemi-log"><tbody>';
		foreach ( array_slice( $entries, 0, 20 ) as $entry ) {
			$at      = isset( $entry['at'] ) ? $entry['at'] : '';
			$message = isset( $entry['message'] ) ? $entry['message'] : '';
			$level   = isset( $entry['level'] ) ? (string) $entry['level'] : 'info';
			$ts      = strtotime( (string) $at );
			echo '<tr><td class="sudemi-log-at">' . esc_html( $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : $at ) . '</td>'
				. '<td><span class="sudemi-badge sudemi-badge-' . esc_attr( 'warn' === $level || 'error' === $level ? 'warning' : 'neutral' ) . '">' . esc_html( $level ) . '</span> ' . esc_html( $message ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	// ---------------------------------------------------------- return requests ----

	/**
	 * Every return request this shop still has to answer, with the answer forms.
	 *
	 * From the copy the heartbeat keeps (the full list on every beat), so this page does not
	 * have to ask sudemi before it can show anything.
	 */
	private static function render_requests() {
		$requests = Sudemi_Requests::all();
		self::render_request_flash();
		if ( empty( $requests ) ) {
			return;
		}
		echo '<section class="sudemi-card sudemi-card-attention"><h2 class="sudemi-card-title" id="sudemi-requests">' . esc_html__( 'Return requests', 'sudemi' ) . '</h2>';
		echo '<p>' . esc_html__( 'Buyers asked to return these delivered orders. Answer each by the date shown: accept, or reject with a reason (the buyer is shown it). Without an answer, sudemi decides. After accepting, refund the buyer once the item is back, with a WooCommerce refund on the order.', 'sudemi' ) . '</p>';
		echo '<table class="sudemi-table"><thead><tr>'
			. '<th>' . esc_html__( 'Order', 'sudemi' ) . '</th>'
			. '<th>' . esc_html__( 'Buyer\'s reason', 'sudemi' ) . '</th>'
			. '<th>' . esc_html__( 'Answer by', 'sudemi' ) . '</th>'
			. '<th>' . esc_html__( 'Your answer', 'sudemi' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $requests as $request ) {
			$order = Sudemi_Requests::local_order( $request['order_id'] );
			echo '<tr><td>';
			if ( $order ) {
				echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a><br />';
			}
			echo '<code>' . esc_html( substr( $request['order_id'], 0, 8 ) ) . '</code></td>';
			echo '<td>' . esc_html( '' === $request['reason'] ? __( 'none given', 'sudemi' ) : $request['reason'] ) . '</td>';
			echo '<td>' . esc_html( Sudemi_Requests::date( $request['respond_by'] ) ) . '</td><td>';
			self::render_request_forms( $request );
			echo '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/** Accept, and reject with a required reason: two forms, so the reason is required only for one. */
	public static function render_request_forms( array $request ) {
		$order_id = $request['order_id'];
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sudemi-inline-form">';
		wp_nonce_field( 'sudemi_request_answer' );
		echo '<input type="hidden" name="action" value="sudemi_request_answer" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '" />';
		echo '<input type="hidden" name="decision" value="accept" />';
		submit_button( __( 'Accept the return', 'sudemi' ), 'primary', 'submit', false );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sudemi_request_answer' );
		echo '<input type="hidden" name="action" value="sudemi_request_answer" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '" />';
		echo '<input type="hidden" name="decision" value="reject" />';
		echo '<label>' . esc_html__( 'Reason for rejecting (shown to the buyer)', 'sudemi' )
			. '<br /><textarea name="reason" rows="2" cols="40" maxlength="1000" required="required"></textarea></label><br />';
		submit_button( __( 'Reject the return', 'sudemi' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/** On the order edit screen (classic and HPOS), when this order has an open return request. */
	public static function render_order_screen_request() {
		if ( ! current_user_can( self::CAPABILITY ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, nothing changes.
		$local_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0 );
		$order    = $local_id ? wc_get_order( $local_id ) : null;
		if ( ! $order ) {
			return;
		}
		$request = Sudemi_Requests::for_order( (string) $order->get_meta( Sudemi_Orders::META_ORDER_ID ) );
		$flash   = self::take_request_flash();
		if ( ! $request && ! $flash ) {
			return;
		}
		if ( $flash ) {
			echo '<div class="notice notice-' . esc_attr( 'success' === $flash['type'] ? 'success' : 'error' ) . '"><p>'
				. esc_html( $flash['message'] ) . '</p></div>';
		}
		if ( ! $request ) {
			return;
		}
		echo '<div class="notice notice-warning sudemi-request"><p><strong>'
			. esc_html__( 'sudemi: the buyer asked to return this order', 'sudemi' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Buyer\'s reason', 'sudemi' ) . ': ' . esc_html( '' === $request['reason'] ? __( 'none given', 'sudemi' ) : $request['reason'] ) . '<br />'
			. esc_html__( 'Answer by', 'sudemi' ) . ': ' . esc_html( Sudemi_Requests::date( $request['respond_by'] ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Accept, or reject with a reason (the buyer is shown it). Without an answer, sudemi decides. After accepting, refund the buyer once the item is back, with a WooCommerce refund on this order.', 'sudemi' ) . '</p>';
		self::render_request_forms( $request );
		echo '</div>';
	}

	public static function handle_request_answer() {
		self::guard( 'sudemi_request_answer' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
		$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
		$accept   = isset( $_POST['decision'] ) && 'accept' === sanitize_key( wp_unslash( $_POST['decision'] ) );
		$reason   = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		// phpcs:enable
		$result = Sudemi_Requests::answer( $order_id, $accept, $reason );
		set_transient(
			self::request_flash_key(),
			array(
				'type'    => in_array( $result['outcome'], array( 'ok', 'already_resolved' ), true ) ? 'success' : 'error',
				'message' => $result['message'],
			),
			MINUTE_IN_SECONDS
		);
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	private static function request_flash_key() {
		return 'sudemi_request_flash_' . get_current_user_id();
	}

	private static function take_request_flash() {
		$flash = get_transient( self::request_flash_key() );
		if ( false === $flash ) {
			return null;
		}
		delete_transient( self::request_flash_key() );
		return is_array( $flash ) && isset( $flash['type'], $flash['message'] ) ? $flash : null;
	}

	private static function render_request_flash() {
		$flash = self::take_request_flash();
		if ( $flash ) {
			echo '<div class="notice notice-' . esc_attr( 'success' === $flash['type'] ? 'success' : 'error' ) . ' inline"><p>'
				. esc_html( $flash['message'] ) . '</p></div>';
		}
	}

	// ------------------------------------------------------------------ support ----

	/**
	 * Tickets between this shop and sudemi support.
	 *
	 * Everything comes from sudemi on each view; nothing is stored here except a one-minute
	 * notice (and the unsent draft) after a form is submitted.
	 */
	private static function render_support( $connected ) {
		if ( ! $connected ) {
			self::empty_state(
				__( 'Not connected yet', 'sudemi' ),
				__( 'Support is available once this shop is connected to sudemi.', 'sudemi' ),
				'<a class="button button-primary" href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Connect this shop', 'sudemi' ) . '</a>'
			);
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

		// A ticket started from another page (asking for a new shop name): category and subject filled in.
		if ( empty( $draft ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a prefill, nothing changes.
			if ( isset( $_GET['category'] ) && in_array( strtoupper( sanitize_key( wp_unslash( $_GET['category'] ) ) ), Sudemi_Support::CATEGORIES, true ) ) {
				$draft['category'] = strtoupper( sanitize_key( wp_unslash( $_GET['category'] ) ) );
			}
			if ( isset( $_GET['subject'] ) ) {
				$draft['subject'] = sanitize_text_field( wp_unslash( $_GET['subject'] ) );
			}
			// phpcs:enable
		}

		echo '<div class="sudemi-grid sudemi-grid-support">';
		echo '<section class="sudemi-card"><h2 class="sudemi-card-title">' . esc_html__( 'Your tickets', 'sudemi' ) . '</h2>';
		$list = Sudemi_Support::list_tickets();
		if ( ! $list['ok'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Sudemi_Support::error_message( $list['error'] ) ) . '</p></div>';
		} elseif ( empty( $list['tickets'] ) ) {
			echo '<p class="sudemi-muted">' . esc_html__( 'No tickets yet.', 'sudemi' ) . '</p>';
		} else {
			echo '<table class="sudemi-table"><thead><tr>'
				. '<th>' . esc_html__( 'Number', 'sudemi' ) . '</th>'
				. '<th>' . esc_html__( 'Subject', 'sudemi' ) . '</th>'
				. '<th>' . esc_html__( 'Status', 'sudemi' ) . '</th>'
				. '<th>' . esc_html__( 'Last updated', 'sudemi' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $list['tickets'] as $ticket ) {
				if ( ! is_array( $ticket ) || empty( $ticket['id'] ) ) {
					continue;
				}
				$url    = self::support_url( (string) $ticket['id'] );
				$status = isset( $ticket['status'] ) ? (string) $ticket['status'] : '';
				echo '<tr>';
				echo '<td><a href="' . esc_url( $url ) . '"><code>' . esc_html( isset( $ticket['number'] ) ? (string) $ticket['number'] : '' ) . '</code></a></td>';
				echo '<td><a href="' . esc_url( $url ) . '"><strong>' . esc_html( isset( $ticket['subject'] ) ? (string) $ticket['subject'] : '' ) . '</strong></a><br />'
					. '<span class="sudemi-muted">' . esc_html( Sudemi_Support::category_label( isset( $ticket['category'] ) ? (string) $ticket['category'] : '' ) ) . '</span></td>';
				echo '<td><span class="sudemi-badge sudemi-badge-' . esc_attr( 'CLOSED' === $status ? 'neutral' : 'info' ) . '">' . esc_html( Sudemi_Support::status_label( $status ) ) . '</span></td>';
				echo '<td>' . esc_html( self::support_time( isset( $ticket['updatedAt'] ) ? $ticket['updatedAt'] : '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';

		echo '<section class="sudemi-card">';
		self::render_support_open_form( $draft );
		echo '</section></div>';
	}

	private static function render_support_open_form( array $draft ) {
		$value = function ( $key ) use ( $draft ) {
			return isset( $draft[ $key ] ) && is_string( $draft[ $key ] ) ? $draft[ $key ] : '';
		};

		echo '<h2 class="sudemi-card-title">' . esc_html__( 'Open a ticket', 'sudemi' ) . '</h2>';
		echo '<p class="sudemi-muted">' . esc_html__( 'sudemi support answers here, and by e-mail.', 'sudemi' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sudemi-form">';
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
		echo '<p><a class="sudemi-back" href="' . esc_url( self::support_url() ) . '">&larr; ' . esc_html__( 'Back to your tickets', 'sudemi' ) . '</a></p>';

		$result = Sudemi_Support::get_ticket( $ticket_id );
		if ( ! $result['ok'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( Sudemi_Support::error_message( $result['error'] ) ) . '</p></div>';
			return;
		}
		$ticket = $result['ticket'];

		echo '<section class="sudemi-card"><h2 class="sudemi-card-title">' . esc_html(
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
		echo '<div class="sudemi-thread">';
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$from_support = isset( $message['author'] ) && 'SUPPORT' === $message['author'];
			echo '<div class="sudemi-message ' . ( $from_support ? 'sudemi-message-support' : 'sudemi-message-you' ) . '">';
			echo '<p class="sudemi-message-meta"><strong>'
				. esc_html( $from_support ? __( 'sudemi support', 'sudemi' ) : __( 'You', 'sudemi' ) )
				. '</strong> <span class="description">'
				. esc_html( self::support_time( isset( $message['createdAt'] ) ? $message['createdAt'] : '' ) )
				. '</span></p>';
			// Plain text from either side: escaped, never rendered as HTML; line breaks kept.
			echo '<div class="sudemi-message-body">' . esc_html( isset( $message['body'] ) ? (string) $message['body'] : '' ) . '</div>';
			echo '</div>';
		}
		echo '</div>';

		echo '<h3 class="sudemi-section-title">' . esc_html__( 'Reply', 'sudemi' ) . '</h3>';
		if ( isset( $ticket['status'] ) && 'CLOSED' === $ticket['status'] ) {
			echo '<p class="description">' . esc_html__( 'This ticket is closed. Replying opens it again.', 'sudemi' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sudemi-form">';
		wp_nonce_field( 'sudemi_support_reply' );
		echo '<input type="hidden" name="action" value="sudemi_support_reply" />';
		echo '<input type="hidden" name="ticket" value="' . esc_attr( $ticket_id ) . '" />';
		echo '<p><textarea name="message" rows="6" class="large-text" required maxlength="' . esc_attr( Sudemi_Support::MESSAGE_MAX ) . '" aria-label="'
			. esc_attr__( 'Reply', 'sudemi' ) . '">' . esc_textarea( isset( $draft['message'] ) && is_string( $draft['message'] ) ? $draft['message'] : '' ) . '</textarea></p>';
		submit_button( __( 'Send reply', 'sudemi' ), 'primary', 'submit', false );
		echo '</form></section>';
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
		self::back( 'dashboard' );
	}

	public static function handle_disconnect() {
		self::guard( 'sudemi_disconnect' );
		Sudemi_Pairing::stop_polling();
		Sudemi_Pairing::forget_pairing();
		Sudemi_Sync::unschedule();
		Sudemi_Heartbeat::unschedule();
		Sudemi_Heartbeat::forget();
		Sudemi_Queue::forget();
		Sudemi_Sync::forget();
		Sudemi_Order_Calls::forget();
		Sudemi_Challenge::forget();
		Sudemi_Requests::forget();
		Sudemi_Changes::forget();
		delete_transient( Sudemi_Insights::CHECKOUT_CACHE );
		delete_option( Sudemi_Client::OPTION_INSTALL_ID );
		// The key goes with the connection. Keeping it would leave a usable credential on a
		// shop that believes it has disconnected.
		Sudemi_Keys::forget();
		Sudemi_Logger::info( __( 'Disconnected from sudemi.', 'sudemi' ) );
		self::back( 'dashboard' );
	}

	/**
	 * Shipping prices and delivery times as sudemi buyers see them.
	 *
	 * Prices come from this shop's WooCommerce shipping zones (a country with no fixed price is
	 * not offered to buyers there); the delivery times are this plugin's own two settings.
	 */
	private static function render_delivery() {
		echo '<h2 class="sudemi-card-title">' . esc_html__( 'Delivery to sudemi buyers', 'sudemi' ) . '</h2>';
		$sample = wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'virtual' => false ) );
		$rates  = $sample ? Sudemi_Shipping::rates_for( $sample[0] ) : array();
		if ( empty( $rates ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No shipping price could be read from your WooCommerce shipping zones, so sudemi buyers cannot order. Add a zone with a flat rate (a fixed amount) or unconditional free shipping for the countries you ship to.', 'sudemi' ) . '</p></div>';
		} else {
			$list = array();
			foreach ( $rates as $rate ) {
				$list[] = $rate['country'] . ' ' . wc_price( Sudemi_Currency::to_major( $rate['costMinor'], Sudemi_Currency::shop_currency() ) );
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

	private static function back( $page = 'settings' ) {
		wp_safe_redirect( self::page_url( $page ) );
		exit;
	}
}
