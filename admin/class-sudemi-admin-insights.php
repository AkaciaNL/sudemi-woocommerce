<?php
/**
 * The dashboard, the shop's products as sudemi holds them, and the shop profile (protocol 4e).
 *
 * The same three rules as every admin screen here: a capability check and a nonce on every
 * action, and everything printed escaped.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Admin_Insights {

	const CAPABILITY = 'manage_woocommerce';

	public static function boot() {
		add_action( 'admin_post_sudemi_listing_enabled', array( __CLASS__, 'handle_enabled' ) );
		add_action( 'admin_post_sudemi_profile_save', array( __CLASS__, 'handle_profile' ) );
	}

	// -------------------------------------------------------------------- flash ----

	private static function flash_key() {
		return 'sudemi_insights_flash_' . get_current_user_id();
	}

	private static function put_flash( $ok, $message ) {
		set_transient( self::flash_key(), array( 'ok' => (bool) $ok, 'message' => (string) $message ), MINUTE_IN_SECONDS );
	}

	private static function render_flash() {
		$flash = get_transient( self::flash_key() );
		if ( false === $flash ) {
			return;
		}
		delete_transient( self::flash_key() );
		if ( is_array( $flash ) && isset( $flash['message'] ) ) {
			echo '<div class="notice notice-' . esc_attr( ! empty( $flash['ok'] ) ? 'success' : 'error' ) . ' inline sudemi-flash"><p>'
				. esc_html( $flash['message'] ) . '</p></div>';
		}
	}

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sudemi' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function not_connected() {
		Sudemi_Admin::empty_state(
			__( 'Not connected yet', 'sudemi' ),
			__( 'This is available once this shop is connected to sudemi.', 'sudemi' ),
			'<a class="button button-primary" href="' . esc_url( Sudemi_Admin::page_url() ) . '">' . esc_html__( 'Connect this shop', 'sudemi' ) . '</a>'
		);
	}

	private static function unreachable( array $read ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html( Sudemi_Insights::error_message( $read['error'], $read['message'] ) ) . '</p></div>';
	}

	/** The WooCommerce product (or variation) behind an external id, if it still exists. */
	private static function local_product( $external_id ) {
		if ( ! Sudemi_Insights::is_external_id( (string) $external_id ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( (int) $external_id );
		return $product ? $product : null;
	}

	private static function thumb( $product ) {
		$image = $product ? $product->get_image_id() : 0;
		if ( ! $image && $product && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$image  = $parent ? $parent->get_image_id() : 0;
		}
		$url = $image ? wp_get_attachment_image_url( $image, 'thumbnail' ) : '';
		return $url
			? '<img class="sudemi-thumb" src="' . esc_url( $url ) . '" alt="" loading="lazy" width="40" height="40" />'
			: '<span class="sudemi-thumb sudemi-thumb-empty" aria-hidden="true"></span>';
	}

	private static function edit_link( $product, $title ) {
		if ( ! $product ) {
			return esc_html( $title );
		}
		$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		return '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( $title ) . '</a>';
	}

	// ---------------------------------------------------------------- dashboard ----

	public static function render_dashboard() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, nothing changes.
		$days = Sudemi_Insights::period( isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : '' );

		$switch = '<div class="sudemi-segmented" role="group" aria-label="' . esc_attr__( 'Period', 'sudemi' ) . '">';
		foreach ( Sudemi_Insights::PERIODS as $p ) {
			$switch .= '<a class="' . ( $p === $days ? 'is-active' : '' ) . '" href="' . esc_url( Sudemi_Admin::page_url( 'dashboard', array( 'period' => $p ) ) ) . '"'
				. ( $p === $days ? ' aria-current="true"' : '' ) . '>' . esc_html(
					sprintf(
						/* translators: %d: number of days. */
						__( '%d days', 'sudemi' ),
						$p
					)
				) . '</a>';
		}
		$switch .= '</div>';
		Sudemi_Admin::page_title(
			__( 'Dashboard', 'sudemi' ),
			sprintf(
				/* translators: %d: number of days. */
				__( 'How your products did on sudemi in the last %d days.', 'sudemi' ),
				$days
			),
			$switch
		);

		self::render_attention();

		$read = Sudemi_Insights::stats( $days );
		if ( ! $read['ok'] ) {
			self::unreachable( $read );
			return;
		}
		$s        = $read['body'];
		$totals   = isset( $s['totals'] ) && is_array( $s['totals'] ) ? $s['totals'] : array();
		$daily    = isset( $s['daily'] ) && is_array( $s['daily'] ) ? array_values( array_filter( $s['daily'], 'is_array' ) ) : array();
		$currency = Sudemi_Insights::str( $s, 'currency' );
		$currency = '' === $currency ? Sudemi_Currency::shop_currency() : $currency;

		// Money first, then reach.
		$cards = array(
			array( __( 'Sales', 'sudemi' ), Sudemi_Insights::money( Sudemi_Insights::int( $totals, 'sales_minor' ), $currency ), __( 'What buyers paid, shipping and VAT included', 'sudemi' ), 'money' ),
			array( __( 'Orders', 'sudemi' ), Sudemi_Insights::number( Sudemi_Insights::int( $totals, 'orders' ) ), sprintf( /* translators: %s: number of items. */ _n( '%s item sold', '%s items sold', Sudemi_Insights::int( $totals, 'units' ), 'sudemi' ), Sudemi_Insights::number( Sudemi_Insights::int( $totals, 'units' ) ) ), 'money' ),
			array( __( 'You receive', 'sudemi' ), Sudemi_Insights::money( Sudemi_Insights::int( $totals, 'net_minor' ), $currency ), sprintf( /* translators: 1: refunds, 2: commission. */ __( 'After %1$s refunds and %2$s commission', 'sudemi' ), Sudemi_Insights::money( Sudemi_Insights::int( $totals, 'refunded_minor' ), $currency ), Sudemi_Insights::money( Sudemi_Insights::int( $totals, 'commission_minor' ), $currency ) ), 'money' ),
			array( __( 'Average order', 'sudemi' ), Sudemi_Insights::money( Sudemi_Insights::int( $totals, 'average_order_minor' ), $currency ), __( 'Per order, shipping included', 'sudemi' ), 'money' ),
			array( __( 'Shown in search results', 'sudemi' ), Sudemi_Insights::number( Sudemi_Insights::int( $totals, 'impressions' ) ), __( 'Times an AI assistant got one of your products back from a search', 'sudemi' ), 'reach' ),
			array( __( 'Product views', 'sudemi' ), Sudemi_Insights::number( Sudemi_Insights::int( $totals, 'detail_views' ) ), __( 'Times an assistant opened a product\'s details for a buyer', 'sudemi' ), 'reach' ),
			array( __( 'Added to a cart', 'sudemi' ), Sudemi_Insights::number( Sudemi_Insights::int( $totals, 'cart_adds' ) ), __( 'Times a buyer put one of your products in a cart', 'sudemi' ), 'reach' ),
			array( __( 'Conversion', 'sudemi' ), Sudemi_Insights::percent( Sudemi_Insights::int( $totals, 'conversion_bps' ) ), __( 'Orders per product view', 'sudemi' ), 'reach' ),
		);
		echo '<div class="sudemi-kpis">';
		foreach ( $cards as $card ) {
			echo '<div class="sudemi-kpi sudemi-kpi-' . esc_attr( $card[3] ) . '"><span class="sudemi-kpi-label">' . esc_html( $card[0] ) . '</span>'
				. '<span class="sudemi-kpi-value">' . esc_html( $card[1] ) . '</span>'
				. '<span class="sudemi-kpi-note">' . esc_html( $card[2] ) . '</span></div>';
		}
		echo '</div>';

		echo '<div class="sudemi-grid sudemi-grid-2">';
		echo '<section class="sudemi-card"><div class="sudemi-card-head"><h2 class="sudemi-card-title">' . esc_html__( 'Seen by buyers', 'sudemi' ) . '</h2>'
			. '<ul class="sudemi-legend"><li><span class="sudemi-swatch sudemi-series-a"></span>' . esc_html__( 'Shown in search results', 'sudemi' ) . '</li>'
			. '<li><span class="sudemi-swatch sudemi-series-b"></span>' . esc_html__( 'Product views', 'sudemi' ) . '</li></ul></div>';
		echo Sudemi_Insights::chart_svg( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in Sudemi_Insights::chart_svg().
			$daily,
			array(
				'impressions'  => array( 'class' => 'sudemi-series-a' ),
				'detail_views' => array( 'class' => 'sudemi-series-b' ),
			),
			__( 'Searches and product views per day', 'sudemi' )
		);
		echo '</section>';
		echo '<section class="sudemi-card"><div class="sudemi-card-head"><h2 class="sudemi-card-title">' . esc_html__( 'Sales per day', 'sudemi' ) . '</h2>'
			. '<span class="sudemi-muted">' . esc_html( $currency ) . '</span></div>';
		echo Sudemi_Insights::bars_svg( $daily, 'sales_minor', __( 'Sales per day', 'sudemi' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped in Sudemi_Insights::bars_svg().
		echo '</section></div>';

		echo '<div class="sudemi-grid sudemi-grid-main">';
		self::render_top_products( isset( $s['top_products'] ) && is_array( $s['top_products'] ) ? $s['top_products'] : array(), $currency );
		echo '<div class="sudemi-stack">';
		self::render_listings_summary( isset( $s['listings'] ) && is_array( $s['listings'] ) ? $s['listings'] : array() );
		self::render_status_breakdown( isset( $s['orders_by_status'] ) && is_array( $s['orders_by_status'] ) ? $s['orders_by_status'] : array() );
		self::render_countries( isset( $s['countries'] ) && is_array( $s['countries'] ) ? $s['countries'] : array(), $currency );
		echo '</div></div>';
	}

	/** Orders waiting for an answer and open return requests, above everything else. */
	private static function render_attention() {
		$requests = Sudemi_Requests::all();
		$read     = Sudemi_Manage::list_orders( Sudemi_Manage::filters( array( 'status' => 'AWAITING_SELLER' ) ) );
		$waiting  = 0;
		if ( $read['ok'] && isset( $read['body']['counts']['AWAITING_SELLER'] ) ) {
			$waiting = (int) $read['body']['counts']['AWAITING_SELLER'];
		}
		if ( $waiting > 0 ) {
			echo '<div class="sudemi-alert sudemi-alert-warning"><div><strong>' . esc_html(
				sprintf(
					/* translators: %d: number of orders. */
					_n( '%d order is waiting for your answer', '%d orders are waiting for your answer', $waiting, 'sudemi' ),
					$waiting
				)
			) . '</strong><br />' . esc_html__( 'The buyer is not charged until you accept. Without an answer by the deadline the order lapses.', 'sudemi' )
				. '</div><a class="button button-primary" href="' . esc_url( Sudemi_Admin::page_url( 'orders', array( 'status' => 'AWAITING_SELLER' ) ) ) . '">' . esc_html__( 'Answer now', 'sudemi' ) . '</a></div>';
		}
		if ( ! empty( $requests ) ) {
			echo '<div class="sudemi-alert sudemi-alert-info"><div><strong>' . esc_html(
				sprintf(
					/* translators: %d: number of return requests. */
					_n( '%d return request is waiting for your answer', '%d return requests are waiting for your answer', count( $requests ), 'sudemi' ),
					count( $requests )
				)
			) . '</strong></div><a class="button" href="' . esc_url( Sudemi_Admin::page_url( 'orders' ) ) . '#sudemi-requests">' . esc_html__( 'Answer now', 'sudemi' ) . '</a></div>';
		}
		list( $state, $label ) = Sudemi_Admin::connection_state();
		if ( in_array( $state, array( 'warning', 'error' ), true ) ) {
			echo '<div class="sudemi-alert sudemi-alert-error"><div><strong>' . esc_html( $label ) . '</strong><br />'
				. esc_html__( 'Buyers cannot see your products right now. The Settings page shows why and what to do.', 'sudemi' )
				. '</div><a class="button" href="' . esc_url( Sudemi_Admin::page_url( 'settings' ) ) . '">' . esc_html__( 'Open settings', 'sudemi' ) . '</a></div>';
		}
	}

	private static function render_top_products( array $rows, $currency ) {
		echo '<section class="sudemi-card sudemi-card-flush"><div class="sudemi-card-head sudemi-pad"><h2 class="sudemi-card-title">' . esc_html__( 'Top products', 'sudemi' ) . '</h2>'
			. '<a href="' . esc_url( Sudemi_Admin::page_url( 'products' ) ) . '">' . esc_html__( 'All products on sudemi', 'sudemi' ) . ' &rarr;</a></div>';
		$rows = array_values( array_filter( $rows, 'is_array' ) );
		if ( empty( $rows ) ) {
			echo '<div class="sudemi-empty-inline">' . esc_html__( 'No activity in this period yet. Buyers find your products by asking their AI assistant; the numbers show up here day by day.', 'sudemi' ) . '</div></section>';
			return;
		}
		echo '<div class="sudemi-table-scroll"><table class="sudemi-table"><thead><tr>'
			. '<th>' . esc_html__( 'Product', 'sudemi' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Shown', 'sudemi' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Views', 'sudemi' ) . '</th>'
			. '<th class="num">' . esc_html__( 'In cart', 'sudemi' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Orders', 'sudemi' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Sales', 'sudemi' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$product = self::local_product( Sudemi_Insights::str( $row, 'external_id' ) );
			echo '<tr><td><div class="sudemi-product">' . self::thumb( $product ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in thumb().
				. '<span>' . self::edit_link( $product, Sudemi_Insights::str( $row, 'title' ) ) . '</span></div></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in edit_link().
			foreach ( array( 'impressions', 'detail_views', 'cart_adds', 'orders' ) as $key ) {
				echo '<td class="num">' . esc_html( Sudemi_Insights::number( Sudemi_Insights::int( $row, $key ) ) ) . '</td>';
			}
			echo '<td class="num"><strong>' . esc_html( Sudemi_Insights::money( Sudemi_Insights::int( $row, 'sales_minor' ), $currency ) ) . '</strong></td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private static function render_listings_summary( array $l ) {
		$total  = Sudemi_Insights::int( $l, 'total' );
		$listed = Sudemi_Insights::int( $l, 'listed' );
		$not    = Sudemi_Insights::int( $l, 'not_listed' );
		$share  = $total > 0 ? round( 100 * $listed / $total ) : 0;
		echo '<section class="sudemi-card"><h2 class="sudemi-card-title">' . esc_html__( 'Your products on sudemi', 'sudemi' ) . '</h2>';
		echo '<div class="sudemi-big">' . esc_html( Sudemi_Insights::number( $listed ) ) . ' <span>/ ' . esc_html( Sudemi_Insights::number( $total ) ) . '</span></div>';
		echo '<p class="sudemi-muted">' . esc_html__( 'shown to buyers', 'sudemi' ) . '</p>';
		echo '<div class="sudemi-meter" role="img" aria-label="' . esc_attr( $share . '%' ) . '"><span style="width:' . esc_attr( (string) $share ) . '%"></span></div>';
		if ( $not > 0 ) {
			echo '<p><a href="' . esc_url( Sudemi_Admin::page_url( 'products', array( 'status' => 'not_listed' ) ) ) . '">' . esc_html(
				sprintf(
					/* translators: %d: number of products. */
					_n( '%d product is not shown: see why', '%d products are not shown: see why', $not, 'sudemi' ),
					$not
				)
			) . ' &rarr;</a></p>';
		}
		echo '</section>';
	}

	private static function render_status_breakdown( array $by ) {
		echo '<section class="sudemi-card"><h2 class="sudemi-card-title">' . esc_html__( 'Orders by status', 'sudemi' ) . '</h2>';
		$by = array_filter( array_map( 'intval', $by ) );
		if ( empty( $by ) ) {
			echo '<p class="sudemi-muted">' . esc_html__( 'No orders in this period.', 'sudemi' ) . '</p></section>';
			return;
		}
		echo '<ul class="sudemi-list">';
		foreach ( $by as $status => $count ) {
			echo '<li><span class="sudemi-badge sudemi-status sudemi-status-' . esc_attr( strtolower( (string) $status ) ) . '">' . esc_html( Sudemi_Admin_Manage::status_label( (string) $status ) ) . '</span>'
				. '<span class="sudemi-list-value">' . esc_html( Sudemi_Insights::number( $count ) ) . '</span></li>';
		}
		echo '</ul></section>';
	}

	private static function render_countries( array $rows, $currency ) {
		echo '<section class="sudemi-card"><h2 class="sudemi-card-title">' . esc_html__( 'Where buyers are', 'sudemi' ) . '</h2>';
		$rows = array_values( array_filter( $rows, 'is_array' ) );
		if ( empty( $rows ) ) {
			echo '<p class="sudemi-muted">' . esc_html__( 'No orders in this period.', 'sudemi' ) . '</p></section>';
			return;
		}
		$max   = max( 1, max( array_map( function ( $r ) { return Sudemi_Insights::int( $r, 'orders' ); }, $rows ) ) );
		$names = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
		echo '<ul class="sudemi-bars">';
		foreach ( $rows as $row ) {
			$code   = strtoupper( Sudemi_Insights::str( $row, 'country' ) );
			$orders = Sudemi_Insights::int( $row, 'orders' );
			echo '<li><span class="sudemi-bars-label">' . esc_html( isset( $names[ $code ] ) ? $names[ $code ] : $code ) . '</span>'
				. '<span class="sudemi-bars-track"><span style="width:' . esc_attr( (string) round( 100 * $orders / $max ) ) . '%"></span></span>'
				. '<span class="sudemi-bars-value">' . esc_html( Sudemi_Insights::number( $orders ) ) . ' &middot; ' . esc_html( Sudemi_Insights::money( Sudemi_Insights::int( $row, 'sales_minor' ), $currency ) ) . '</span></li>';
		}
		echo '</ul></section>';
	}

	// ----------------------------------------------------------------- products ----

	public static function render_products() {
		Sudemi_Admin::page_title(
			__( 'Products on sudemi', 'sudemi' ),
			__( 'Your products as sudemi has them: whether buyers can see each one, why not, and how often it was found in the last 30 days.', 'sudemi' )
		);
		if ( ! Sudemi_Client::is_connected() ) {
			self::not_connected();
			return;
		}
		self::render_flash();

		$in = array();
		foreach ( array( 'status', 'q', 'paged' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, nothing changes.
			if ( isset( $_GET[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$in[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		$f    = Sudemi_Insights::listing_filters( $in );
		$read = Sudemi_Insights::listings( $f );
		if ( ! $read['ok'] ) {
			self::unreachable( $read );
			return;
		}
		$body  = $read['body'];
		$items = isset( $body['items'] ) && is_array( $body['items'] ) ? array_values( array_filter( $body['items'], 'is_array' ) ) : array();
		$total = Sudemi_Insights::int( $body, 'total' );

		// Counts per filter from the dashboard numbers (one call).
		$stats  = Sudemi_Insights::stats( 7 );
		$counts = $stats['ok'] && isset( $stats['body']['listings'] ) && is_array( $stats['body']['listings'] ) ? $stats['body']['listings'] : array();

		echo '<section class="sudemi-card sudemi-card-flush">';
		echo '<div class="sudemi-toolbar"><ul class="subsubsub sudemi-subsub">';
		$tabs = array(
			'all'        => array( __( 'All', 'sudemi' ), 'total' ),
			'listed'     => array( __( 'Shown to buyers', 'sudemi' ), 'listed' ),
			'not_listed' => array( __( 'Not shown', 'sudemi' ), 'not_listed' ),
		);
		$sep = '';
		foreach ( $tabs as $status => $tab ) {
			$count = isset( $counts[ $tab[1] ] ) ? ' <span class="count">(' . esc_html( Sudemi_Insights::number( (int) $counts[ $tab[1] ] ) ) . ')</span>' : '';
			echo '<li>' . $sep . '<a href="' . esc_url( Sudemi_Admin::page_url( 'products', array_filter( array( 'status' => 'all' === $status ? null : $status, 'q' => '' === $f['q'] ? null : $f['q'] ) ) ) ) . '"' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $sep is a literal.
				. ( $status === $f['status'] ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $tab[0] ) . $count . '</a></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $count is built escaped.
			$sep = ' | ';
		}
		echo '</ul>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="sudemi-search">'
			. '<input type="hidden" name="page" value="' . esc_attr( Sudemi_Admin::SLUG ) . '" />'
			. '<input type="hidden" name="tab" value="products" />'
			. ( 'all' !== $f['status'] ? '<input type="hidden" name="status" value="' . esc_attr( $f['status'] ) . '" />' : '' )
			. '<label class="screen-reader-text" for="sudemi-product-search">' . esc_html__( 'Search products', 'sudemi' ) . '</label>'
			. '<input type="search" id="sudemi-product-search" name="q" value="' . esc_attr( $f['q'] ) . '" maxlength="100" placeholder="' . esc_attr__( 'Name, SKU or ID', 'sudemi' ) . '" /> ';
		submit_button( __( 'Search products', 'sudemi' ), 'secondary', '', false );
		echo '</form></div>';

		if ( empty( $items ) ) {
			echo '<div class="sudemi-empty-inline"><strong>' . esc_html__( 'No products match.', 'sudemi' ) . '</strong><br />'
				. esc_html__( 'Products reach sudemi with the catalogue sync, a few minutes after you publish them.', 'sudemi' ) . '</div></section>';
			return;
		}

		echo '<div class="sudemi-table-scroll"><table class="sudemi-table sudemi-products"><thead><tr>'
			. '<th>' . esc_html__( 'Product', 'sudemi' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Price', 'sudemi' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Stock', 'sudemi' ) . '</th>'
			. '<th>' . esc_html__( 'Buyers see it', 'sudemi' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Shown (30 days)', 'sudemi' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Views (30 days)', 'sudemi' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Orders (30 days)', 'sudemi' ) . '</th>'
			. '<th>' . esc_html__( 'Show on sudemi', 'sudemi' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $items as $item ) {
			$id      = Sudemi_Insights::str( $item, 'external_id' );
			$product = self::local_product( $id );
			$enabled = ! empty( $item['enabled'] );
			$listed  = ! empty( $item['listed'] );
			$reasons = isset( $item['not_listed_reasons'] ) && is_array( $item['not_listed_reasons'] ) ? $item['not_listed_reasons'] : array();
			$meta    = array();
			if ( '' !== Sudemi_Insights::str( $item, 'sku' ) ) {
				/* translators: %s: SKU. */
				$meta[] = sprintf( __( 'SKU %s', 'sudemi' ), Sudemi_Insights::str( $item, 'sku' ) );
			}
			/* translators: %s: the product's id in this shop. */
			$meta[] = sprintf( __( 'ID %s', 'sudemi' ), $id );
			/* translators: %d: number of photos. */
			$meta[] = sprintf( _n( '%d photo', '%d photos', Sudemi_Insights::int( $item, 'images' ), 'sudemi' ), Sudemi_Insights::int( $item, 'images' ) );
			/* translators: %d: number of countries. */
			$meta[] = sprintf( _n( 'ships to %d country', 'ships to %d countries', Sudemi_Insights::int( $item, 'shipping_countries' ), 'sudemi' ), Sudemi_Insights::int( $item, 'shipping_countries' ) );

			echo '<tr><td><div class="sudemi-product">' . self::thumb( $product ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in thumb().
				. '<span><strong>' . self::edit_link( $product, Sudemi_Insights::str( $item, 'title' ) ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in edit_link().
				. '<br /><span class="sudemi-muted">' . esc_html( implode( ' · ', $meta ) ) . '</span></span></div></td>';
			echo '<td class="num">' . esc_html( Sudemi_Insights::money( isset( $item['price_minor'] ) ? $item['price_minor'] : null, Sudemi_Insights::str( $item, 'currency' ) ) ) . '</td>';
			echo '<td class="num">' . esc_html( isset( $item['quantity'] ) && is_numeric( $item['quantity'] ) ? Sudemi_Insights::number( (int) $item['quantity'] ) : ( ! empty( $item['in_stock'] ) ? __( 'in stock', 'sudemi' ) : '0' ) ) . '</td>';
			echo '<td>';
			if ( $listed ) {
				echo '<span class="sudemi-badge sudemi-badge-success">' . esc_html__( 'Shown', 'sudemi' ) . '</span>';
			} else {
				echo '<span class="sudemi-badge sudemi-badge-warning">' . esc_html__( 'Not shown', 'sudemi' ) . '</span>';
				$words = array();
				foreach ( $reasons as $reason ) {
					$words[] = Sudemi_Insights::reason_label( (string) $reason );
				}
				if ( empty( $words ) ) {
					$words[] = Sudemi_Insights::reason_label( '' );
				}
				echo '<ul class="sudemi-reasons"><li>' . implode( '</li><li>', array_map( 'esc_html', array_unique( $words ) ) ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item escaped.
			}
			echo '</td>';
			foreach ( array( 'impressions_30d', 'detail_views_30d', 'orders_30d' ) as $key ) {
				echo '<td class="num">' . esc_html( Sudemi_Insights::number( Sudemi_Insights::int( $item, $key ) ) ) . '</td>';
			}
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sudemi-switch-form">';
			wp_nonce_field( 'sudemi_listing_enabled' );
			echo '<input type="hidden" name="action" value="sudemi_listing_enabled" />'
				. '<input type="hidden" name="external_id" value="' . esc_attr( $id ) . '" />'
				. '<input type="hidden" name="enabled" value="' . ( $enabled ? '0' : '1' ) . '" />'
				. '<button type="submit" class="sudemi-switch' . ( $enabled ? ' is-on' : '' ) . '" role="switch" aria-checked="' . ( $enabled ? 'true' : 'false' ) . '" aria-label="'
				. esc_attr(
					sprintf(
						/* translators: %s: product name. */
						__( 'Show %s on sudemi', 'sudemi' ),
						Sudemi_Insights::str( $item, 'title' )
					)
				) . '"><span class="sudemi-switch-knob"></span></button>'
				. '<span class="sudemi-switch-text">' . esc_html( $enabled ? __( 'On', 'sudemi' ) : __( 'Off', 'sudemi' ) ) . '</span></form></td></tr>';
		}
		echo '</tbody></table></div>';

		$page  = (int) $f['page'];
		$pages = max( 1, (int) ceil( $total / Sudemi_Insights::LISTING_PAGE_SIZE ) );
		echo '<div class="sudemi-paging"><span>' . esc_html(
			sprintf(
				/* translators: 1: first row shown, 2: last row shown, 3: number of products. */
				__( 'Products %1$d to %2$d of %3$d', 'sudemi' ),
				$page * Sudemi_Insights::LISTING_PAGE_SIZE + 1,
				$page * Sudemi_Insights::LISTING_PAGE_SIZE + count( $items ),
				$total
			)
		) . '</span> ';
		$args = array_filter(
			array(
				'status' => 'all' === $f['status'] ? null : $f['status'],
				'q'      => '' === $f['q'] ? null : $f['q'],
			)
		);
		if ( $page > 0 ) {
			echo '<a class="button" href="' . esc_url( Sudemi_Admin::page_url( 'products', array_merge( $args, array( 'paged' => $page - 1 ) ) ) ) . '">' . esc_html__( 'Previous', 'sudemi' ) . '</a> ';
		}
		if ( $page + 1 < $pages ) {
			echo '<a class="button" href="' . esc_url( Sudemi_Admin::page_url( 'products', array_merge( $args, array( 'paged' => $page + 1 ) ) ) ) . '">' . esc_html__( 'Next', 'sudemi' ) . '</a>';
		}
		echo '</div></section>';
		echo '<p class="sudemi-muted">' . esc_html__( 'Prices, stock, photos and texts come from your WooCommerce products and are sent by themselves when you change them. The switch only decides whether sudemi shows a product; it does not change anything in your shop.', 'sudemi' ) . '</p>';
	}

	public static function handle_enabled() {
		self::guard( 'sudemi_listing_enabled' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
		$id = isset( $_POST['external_id'] ) ? sanitize_text_field( wp_unslash( $_POST['external_id'] ) ) : '';
		$on = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );
		// phpcs:enable
		$read = Sudemi_Insights::set_enabled( $id, $on );
		if ( $read['ok'] ) {
			Sudemi_Logger::info(
				sprintf(
					/* translators: %s: product id. */
					$on ? __( 'Product %s switched on for sudemi.', 'sudemi' ) : __( 'Product %s switched off for sudemi.', 'sudemi' ),
					$id
				)
			);
			self::put_flash(
				true,
				$on ? __( 'Switched on. sudemi shows this product again (when it is in stock and can be shipped).', 'sudemi' ) : __( 'Switched off. sudemi no longer shows this product to buyers. Your shop is not changed.', 'sudemi' )
			);
		} else {
			self::put_flash( false, Sudemi_Insights::error_message( $read['error'], $read['message'] ) );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : Sudemi_Admin::page_url( 'products' ) );
		exit;
	}

	// ------------------------------------------------------------------ profile ----

	public static function render_profile() {
		Sudemi_Admin::page_title(
			__( 'Shop profile', 'sudemi' ),
			__( 'What buyers and their AI assistant are told about your shop.', 'sudemi' )
		);
		if ( ! Sudemi_Client::is_connected() ) {
			self::not_connected();
			return;
		}
		self::render_flash();
		$read = Sudemi_Insights::shop();
		$shop = $read['ok'] ? $read['body'] : array();
		if ( ! $read['ok'] ) {
			self::unreachable( $read );
		}
		$local = Sudemi_Insights::local_profile();
		$name  = Sudemi_Insights::str( $shop, 'shop_name' );
		$desc  = isset( $shop['shop_description'] ) && is_string( $shop['shop_description'] ) ? $shop['shop_description'] : $local['description'];
		$email = '' !== Sudemi_Insights::str( $shop, 'support_email' ) ? Sudemi_Insights::str( $shop, 'support_email' ) : $local['support_email'];
		$days  = isset( $shop['returns_window_days'] ) && is_numeric( $shop['returns_window_days'] ) ? (int) $shop['returns_window_days']
			: ( null !== $local['returns_days'] ? $local['returns_days'] : Sudemi_Insights::RETURNS_MIN );

		echo '<div class="sudemi-grid sudemi-grid-main">';
		echo '<section class="sudemi-card"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sudemi-form">';
		wp_nonce_field( 'sudemi_profile_save' );
		echo '<input type="hidden" name="action" value="sudemi_profile_save" />';

		echo '<div class="sudemi-field"><label>' . esc_html__( 'Shop name buyers see', 'sudemi' ) . '</label>'
			. '<div class="sudemi-readonly">' . esc_html( '' === $name ? get_bloginfo( 'name' ) : $name ) . '</div>'
			. '<p class="description">' . esc_html__( 'The name is checked by sudemi, so it is changed through support.', 'sudemi' ) . ' '
			. '<a href="' . esc_url(
				Sudemi_Admin::page_url(
					'support',
					array(
						'category' => 'ACCOUNT',
						'subject'  => __( 'Please change my shop name', 'sudemi' ),
					)
				)
			) . '">' . esc_html__( 'Ask for a new name', 'sudemi' ) . '</a></p></div>';

		echo '<div class="sudemi-field"><label for="sudemi-profile-description">' . esc_html__( 'About your shop', 'sudemi' ) . '</label>'
			. '<textarea id="sudemi-profile-description" name="description" rows="5" class="large-text" maxlength="' . esc_attr( (string) Sudemi_Insights::DESCRIPTION_MAX ) . '">'
			. esc_textarea( $desc ) . '</textarea>'
			. '<p class="description">' . esc_html__( 'A few sentences: what you sell and what makes your shop worth buying from. AI assistants may quote it to buyers.', 'sudemi' ) . '</p></div>';

		echo '<div class="sudemi-field"><label for="sudemi-profile-email">' . esc_html__( 'E-mail for buyers\' questions', 'sudemi' ) . '</label>'
			. '<input type="email" id="sudemi-profile-email" name="support_email" class="regular-text" required="required" value="' . esc_attr( $email ) . '" />'
			. '<p class="description">' . esc_html__( 'Shown to buyers after they ordered, for questions about delivery and returns.', 'sudemi' ) . '</p></div>';

		echo '<div class="sudemi-field"><label for="sudemi-profile-returns">' . esc_html__( 'Return period (days)', 'sudemi' ) . '</label>'
			. '<input type="number" id="sudemi-profile-returns" name="returns_days" class="small-text" min="' . esc_attr( (string) Sudemi_Insights::RETURNS_MIN ) . '" max="'
			. esc_attr( (string) Sudemi_Insights::RETURNS_MAX ) . '" required="required" value="' . esc_attr( (string) $days ) . '" />'
			. '<p class="description">' . esc_html__( 'How long after delivery a buyer may change their mind. The law gives at least 14 days; you may give more.', 'sudemi' ) . '</p></div>';

		submit_button( __( 'Save on sudemi', 'sudemi' ), 'primary', 'submit', false );
		echo '</form></section>';

		echo '<div class="sudemi-stack"><section class="sudemi-card"><h2 class="sudemi-card-title">' . esc_html__( 'Fixed details', 'sudemi' ) . '</h2><table class="sudemi-kv"><tbody>';
		$fixed = array(
			__( 'Shop address', 'sudemi' ) => '' !== Sudemi_Insights::str( $shop, 'shop_origin' ) ? Sudemi_Insights::str( $shop, 'shop_origin' ) : home_url( '/' ),
			__( 'Currency', 'sudemi' )     => '' !== Sudemi_Insights::str( $shop, 'currency' ) ? Sudemi_Insights::str( $shop, 'currency' ) : Sudemi_Currency::shop_currency(),
			__( 'Install', 'sudemi' )      => (string) Sudemi_Client::install_id(),
		);
		foreach ( $fixed as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><span class="sudemi-kv-value">' . esc_html( $value ) . '</span></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Your legal name, country, trader status and VAT number decide what a buyer is owed, so they are confirmed once on sudemi and changed there. This plugin cannot edit them.', 'sudemi' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( Sudemi_Admin::portal_url( '/seller' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Company details on the seller portal', 'sudemi' ) . '</a></p>';
		echo '</section></div></div>';
	}

	public static function handle_profile() {
		self::guard( 'sudemi_profile_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$email       = isset( $_POST['support_email'] ) ? sanitize_email( wp_unslash( $_POST['support_email'] ) ) : '';
		$days        = isset( $_POST['returns_days'] ) ? sanitize_text_field( wp_unslash( $_POST['returns_days'] ) ) : '';
		// phpcs:enable
		$result = Sudemi_Insights::save_profile( $description, $email, $days );
		if ( $result['ok'] ) {
			Sudemi_Logger::info( __( 'Shop profile saved on sudemi.', 'sudemi' ) );
			self::put_flash( true, __( 'Saved on sudemi. Buyers see the new details from now on.', 'sudemi' ) );
		} else {
			self::put_flash( false, Sudemi_Insights::error_message( $result['error'], $result['message'] ) );
		}
		wp_safe_redirect( Sudemi_Admin::page_url( 'profile' ) );
		exit;
	}
}
