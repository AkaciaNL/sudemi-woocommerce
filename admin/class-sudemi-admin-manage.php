<?php
/**
 * The Orders, Shipping and Payouts tabs of the sudemi page: what the shop sees on the sudemi
 * portal, inside wp-admin (protocol 4d).
 *
 * Everything shown is read from sudemi on each view; every button sends the action to sudemi and
 * only sudemi's answer changes the WooCommerce order. The same three rules as the rest of the
 * admin hold on every form here: a capability check, a nonce, and escaped output.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Admin_Manage {

	const CAPABILITY = 'manage_woocommerce';

	public static function boot() {
		add_action( 'admin_post_sudemi_order_action', array( __CLASS__, 'handle_order_action' ) );
		add_action( 'admin_post_sudemi_orders_bulk', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_sudemi_orders_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_sudemi_shipping_save', array( __CLASS__, 'handle_shipping' ) );
		add_action( 'admin_post_sudemi_auto_confirm', array( __CLASS__, 'handle_auto_confirm' ) );
	}

	// ------------------------------------------------------------------- labels ----

	public static function status_label( $status ) {
		switch ( (string) $status ) {
			case 'AWAITING_SELLER':
				return __( 'Waiting for you', 'sudemi-connector' );
			case 'ACCEPTED':
				return __( 'Accepted, waiting for payment', 'sudemi-connector' );
			case 'PAID':
				return __( 'Paid, ready to ship', 'sudemi-connector' );
			case 'DECLINED':
				return __( 'Declined', 'sudemi-connector' );
			case 'SHIPPED':
				return __( 'Shipped', 'sudemi-connector' );
			case 'DELIVERED':
				return __( 'Delivered', 'sudemi-connector' );
			case 'CANCELLED':
				return __( 'Cancelled', 'sudemi-connector' );
			case 'REFUNDED':
				return __( 'Refunded', 'sudemi-connector' );
		}
		return (string) $status;
	}

	public static function payout_label( $state ) {
		switch ( (string) $state ) {
			case 'not_charged':
				return __( 'Nothing charged', 'sudemi-connector' );
			case 'waiting_for_shipment':
				return __( 'Due once shipped', 'sudemi-connector' );
			case 'scheduled':
				return __( 'Scheduled', 'sudemi-connector' );
			case 'processing':
				return __( 'On its way to you', 'sudemi-connector' );
			case 'held':
				return __( 'Held (chargeback)', 'sudemi-connector' );
			case 'partly_paid_out':
				return __( 'Partly paid out', 'sudemi-connector' );
			case 'paid_out':
				return __( 'Paid out', 'sudemi-connector' );
			case 'nothing_due':
				return __( 'Nothing due (refunded)', 'sudemi-connector' );
		}
		return (string) $state;
	}

	private static function event_label( $type ) {
		switch ( (string) $type ) {
			case 'PLACED':
				return __( 'Placed by the buyer', 'sudemi-connector' );
			case 'ACCEPTED':
				return __( 'Accepted', 'sudemi-connector' );
			case 'PAID':
				return __( 'Paid', 'sudemi-connector' );
			case 'DECLINED':
				return __( 'Declined', 'sudemi-connector' );
			case 'SHIPPED':
				return __( 'Shipped', 'sudemi-connector' );
			case 'DELIVERED':
				return __( 'Delivered', 'sudemi-connector' );
			case 'REFUND':
				return __( 'Refund', 'sudemi-connector' );
			case 'CANCELLED':
				return __( 'Cancelled', 'sudemi-connector' );
			case 'REQUEST':
				return __( 'Buyer asked to return or cancel', 'sudemi-connector' );
			case 'REQUEST_DECIDED':
				return __( 'Request answered', 'sudemi-connector' );
		}
		return (string) $type;
	}

	private static function account_label( $state ) {
		switch ( (string) $state ) {
			case 'NOT_STARTED':
				return __( 'Not set up yet', 'sudemi-connector' );
			case 'INCOMPLETE':
				return __( 'Details missing', 'sudemi-connector' );
			case 'UNDER_REVIEW':
				return __( 'Under review', 'sudemi-connector' );
			case 'ACTIVE':
				return __( 'Active', 'sudemi-connector' );
			case 'ACTION_REQUIRED':
				return __( 'Action required', 'sudemi-connector' );
		}
		return (string) $state;
	}

	private static function money( $minor, $currency ) {
		if ( null === $minor || ! is_numeric( $minor ) ) {
			return '-';
		}
		$currency = Sudemi_Currency::normalise( (string) $currency );
		$places   = Sudemi_Currency::decimals( $currency );
		return number_format_i18n( (int) $minor / ( 10 ** $places ), $places ) . ' ' . $currency;
	}

	private static function country( $code ) {
		$code  = strtoupper( (string) $code );
		$names = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
		return isset( $names[ $code ] ) ? $names[ $code ] . ' (' . $code . ')' : $code;
	}

	private static function date( $iso ) {
		$text = Sudemi_Requests::date( $iso );
		return '' === $text ? '-' : $text;
	}

	private static function str( array $a, $key ) {
		return isset( $a[ $key ] ) && ( is_string( $a[ $key ] ) || is_numeric( $a[ $key ] ) ) ? (string) $a[ $key ] : '';
	}

	private static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => Sudemi_Admin::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	// -------------------------------------------------------------------- flash ----

	private static function flash_key() {
		return 'sudemi_manage_flash_' . get_current_user_id();
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
			wp_die( esc_html__( 'You do not have permission to do that.', 'sudemi-connector' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function not_connected() {
		Sudemi_Admin::empty_state(
			__( 'Not connected yet', 'sudemi-connector' ),
			__( 'This is available once this shop is connected to sudemi.', 'sudemi-connector' ),
			'<a class="button button-primary" href="' . esc_url( Sudemi_Admin::page_url() ) . '">' . esc_html__( 'Connect this shop', 'sudemi-connector' ) . '</a>'
		);
	}

	// ------------------------------------------------------------------- orders ----

	public static function render_orders() {
		if ( ! Sudemi_Client::is_connected() ) {
			self::not_connected();
			return;
		}
		self::render_flash();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, nothing changes.
		$id = isset( $_GET['order'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) : '';
		if ( '' !== $id ) {
			self::render_order( $id );
			return;
		}
		self::render_order_list();
	}

	private static function current_filters() {
		$in = array();
		foreach ( array( 'status', 'from', 'to', 'q', 'sort', 'dir', 'offset' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, nothing changes.
			if ( isset( $_GET[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$in[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		return Sudemi_Manage::filters( $in );
	}

	private static function filter_args( array $f, array $override = array() ) {
		$args = array( 'tab' => 'orders' );
		foreach ( array( 'status', 'from', 'to', 'q' ) as $key ) {
			if ( '' !== $f[ $key ] ) {
				$args[ $key ] = $f[ $key ];
			}
		}
		if ( 'placed' !== $f['sort'] ) {
			$args['sort'] = $f['sort'];
		}
		if ( 'desc' !== $f['dir'] ) {
			$args['dir'] = $f['dir'];
		}
		return array_merge( $args, $override );
	}

	private static function render_order_list() {
		$f    = self::current_filters();
		$read = Sudemi_Manage::list_orders( $f );

		self::render_auto_confirm();

		if ( ! $read['ok'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Sudemi_Manage::error_message( $read['error'], $read['message'] ) ) . '</p></div>';
			self::render_filters( $f );
			return;
		}
		$body   = $read['body'];
		$counts = isset( $body['counts'] ) && is_array( $body['counts'] ) ? $body['counts'] : array();
		$orders = isset( $body['orders'] ) && is_array( $body['orders'] ) ? $body['orders'] : array();
		$total  = isset( $body['total'] ) ? (int) $body['total'] : count( $orders );

		// Status links, with sudemi's counts (all statuses, not only this page).
		$all = array_sum( array_map( 'intval', $counts ) );
		echo '<section class="sudemi-card sudemi-card-flush">';
		echo '<ul class="subsubsub sudemi-subsub">';
		echo '<li><a href="' . esc_url( self::url( self::filter_args( $f, array( 'status' => null, 'offset' => null ) ) ) ) . '"' . ( '' === $f['status'] ? ' class="current"' : '' ) . '>'
			. esc_html__( 'All', 'sudemi-connector' ) . ' <span class="count">(' . esc_html( number_format_i18n( $all ) ) . ')</span></a></li>';
		foreach ( Sudemi_Manage::STATUSES as $status ) {
			if ( empty( $counts[ $status ] ) ) {
				continue;
			}
			echo '<li> | <a href="' . esc_url( self::url( self::filter_args( $f, array( 'status' => $status, 'offset' => null ) ) ) ) . '"' . ( $status === $f['status'] ? ' class="current"' : '' ) . '>'
				. esc_html( self::status_label( $status ) ) . ' <span class="count">(' . esc_html( number_format_i18n( (int) $counts[ $status ] ) ) . ')</span></a></li>';
		}
		echo '</ul>';

		self::render_filters( $f );
		self::render_export_form( $f );

		if ( empty( $orders ) ) {
			echo '<div class="sudemi-empty-inline"><strong>' . esc_html__( 'No orders match.', 'sudemi-connector' ) . '</strong><br />'
				. esc_html__( 'Orders appear here as soon as a buyer orders one of your products through their AI assistant.', 'sudemi-connector' ) . '</div>';
			echo '</section>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="sudemi-orders-bulk">';
		wp_nonce_field( 'sudemi_orders_bulk' );
		echo '<input type="hidden" name="action" value="sudemi_orders_bulk" />';
		echo '<div class="sudemi-table-scroll"><table class="sudemi-table sudemi-orders"><thead><tr>'
			. '<td class="check-column"><span class="screen-reader-text">' . esc_html__( 'Select', 'sudemi-connector' ) . '</span></td>'
			. '<th>' . esc_html__( 'Order', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'What sold', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Ship to', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Total', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'You receive', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Payout', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Tracking', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'WooCommerce order', 'sudemi-connector' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $orders as $order ) {
			if ( ! is_array( $order ) || ! Sudemi_Manage::is_order_id( self::str( $order, 'order_id' ) ) ) {
				continue;
			}
			self::render_order_row( $order );
		}
		echo '</tbody></table></div>';

		echo '<p class="sudemi-bulk">';
		echo '<label>' . esc_html__( 'With the selected orders', 'sudemi-connector' ) . ' <select name="bulk_action">'
			. '<option value="">' . esc_html__( 'Choose an action', 'sudemi-connector' ) . '</option>'
			. '<option value="accept">' . esc_html__( 'Accept', 'sudemi-connector' ) . '</option>'
			. '<option value="reject">' . esc_html__( 'Decline', 'sudemi-connector' ) . '</option>'
			. '<option value="shipped">' . esc_html__( 'Mark shipped', 'sudemi-connector' ) . '</option>'
			. '<option value="delivered">' . esc_html__( 'Mark delivered', 'sudemi-connector' ) . '</option>'
			. '</select></label> ';
		echo '<label>' . esc_html__( 'Reason for declining (shown to the buyers)', 'sudemi-connector' ) . ' <input type="text" name="reason" maxlength="500" class="regular-text" /></label> ';
		echo '<label>' . esc_html__( 'Carrier', 'sudemi-connector' ) . ' <input type="text" name="carrier" maxlength="100" /></label> ';
		submit_button( __( 'Apply', 'sudemi-connector' ), 'secondary', 'submit', false );
		echo '</p><p class="description">' . esc_html__( 'Refunds and cancellations are made one order at a time, on the order\'s own page: each moves a different amount of money.', 'sudemi-connector' ) . '</p>';
		echo '</form>';

		// Paging.
		$offset = (int) $f['offset'];
		$next   = isset( $body['next_offset'] ) && is_numeric( $body['next_offset'] ) ? (int) $body['next_offset'] : null;
		echo '<p class="sudemi-paging">' . esc_html(
			sprintf(
				/* translators: 1: first row shown, 2: last row shown, 3: number of orders. */
				__( 'Orders %1$d to %2$d of %3$d', 'sudemi-connector' ),
				$offset + 1,
				$offset + count( $orders ),
				$total
			)
		) . ' ';
		if ( $offset > 0 ) {
			echo '<a class="button" href="' . esc_url( self::url( self::filter_args( $f, array( 'offset' => max( 0, $offset - Sudemi_Manage::PAGE_SIZE ) ) ) ) ) . '">' . esc_html__( 'Previous', 'sudemi-connector' ) . '</a> ';
		}
		if ( null !== $next ) {
			echo '<a class="button" href="' . esc_url( self::url( self::filter_args( $f, array( 'offset' => $next ) ) ) ) . '">' . esc_html__( 'Next', 'sudemi-connector' ) . '</a>';
		}
		echo '</p></section>';
	}

	private static function render_order_row( array $order ) {
		$id       = strtolower( self::str( $order, 'order_id' ) );
		$status   = self::str( $order, 'status' );
		$currency = self::str( $order, 'currency_code' );
		$items    = array();
		foreach ( isset( $order['lines'] ) && is_array( $order['lines'] ) ? $order['lines'] : array() as $line ) {
			if ( is_array( $line ) ) {
				$qty     = isset( $line['quantity'] ) ? (int) $line['quantity'] : 1;
				$items[] = self::str( $line, 'title' ) . ( $qty > 1 ? ' x ' . $qty : '' );
			}
		}
		$payout   = isset( $order['payout'] ) && is_array( $order['payout'] ) ? $order['payout'] : array();
		$tracking = isset( $order['tracking'] ) && is_array( $order['tracking'] ) ? $order['tracking'] : array();
		$local    = Sudemi_Orders::find_any_local_order( $id );

		echo '<tr>';
		echo '<th scope="row" class="check-column"><input type="checkbox" name="orders[]" value="' . esc_attr( $id ) . '" aria-label="'
			. esc_attr( substr( $id, 0, 8 ) ) . '" /></th>';
		echo '<td><a href="' . esc_url( self::url( array( 'tab' => 'orders', 'order' => $id ) ) ) . '"><code>' . esc_html( substr( $id, 0, 8 ) ) . '</code></a><br />'
			. '<span class="description">' . esc_html( self::date( self::str( $order, 'placed_at' ) ) ) . '</span></td>';
		echo '<td>' . esc_html( implode( ', ', $items ) );
		if ( '' !== self::str( $order, 'customer_name' ) ) {
			echo '<br /><span class="description">' . esc_html( self::str( $order, 'customer_name' ) ) . '</span>';
		}
		echo '</td>';
		echo '<td>' . esc_html( self::str( $order, 'destination_country' ) ) . '</td>';
		$extra = '';
		if ( 'AWAITING_SELLER' === $status && '' !== self::str( $order, 'respond_by' ) ) {
			/* translators: %s: date and time. */
			$extra = sprintf( __( 'answer by %s', 'sudemi-connector' ), self::date( self::str( $order, 'respond_by' ) ) );
		} elseif ( ! empty( $order['customer_request'] ) ) {
			$extra = __( 'return requested', 'sudemi-connector' );
		} elseif ( isset( $order['refunded_minor'] ) && (int) $order['refunded_minor'] > 0 ) {
			/* translators: %s: amount. */
			$extra = sprintf( __( 'refunded %s', 'sudemi-connector' ), self::money( $order['refunded_minor'], $currency ) );
		}
		echo '<td><span class="sudemi-badge sudemi-status sudemi-status-' . esc_attr( strtolower( $status ) ) . '">' . esc_html( self::status_label( $status ) ) . '</span>'
			. ( '' !== $extra ? '<br /><span class="description">' . esc_html( $extra ) . '</span>' : '' ) . '</td>';
		echo '<td>' . esc_html( self::money( isset( $order['total_minor'] ) ? $order['total_minor'] : null, $currency ) ) . '</td>';
		echo '<td>' . esc_html( self::money( isset( $order['your_net_minor'] ) ? $order['your_net_minor'] : null, $currency ) ) . '</td>';
		echo '<td>' . esc_html( isset( $payout['state'] ) ? self::payout_label( $payout['state'] ) : '-' );
		if ( ! empty( $payout['due_at'] ) && 'scheduled' === self::str( $payout, 'state' ) ) {
			echo '<br /><span class="description">' . esc_html( self::date( self::str( $payout, 'due_at' ) ) ) . '</span>';
		}
		echo '</td><td>';
		if ( 'PAID' === $status ) {
			echo '<input type="text" name="tracking[' . esc_attr( $id ) . ']" maxlength="100" size="14" aria-label="'
				. esc_attr__( 'Tracking code', 'sudemi-connector' ) . '" placeholder="' . esc_attr__( 'Tracking code', 'sudemi-connector' ) . '" />';
		} elseif ( ! empty( $tracking ) ) {
			echo esc_html( trim( self::str( $tracking, 'carrier' ) . ' ' . self::str( $tracking, 'code' ) ) );
		} else {
			echo '-';
		}
		echo '</td><td>';
		if ( $local ) {
			echo '<a href="' . esc_url( $local->get_edit_order_url() ) . '">#' . esc_html( $local->get_order_number() ) . '</a>';
		} else {
			echo '-';
		}
		echo '</td></tr>';
	}

	private static function render_filters( array $f ) {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="sudemi-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Sudemi_Admin::SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="orders" />';
		echo '<label>' . esc_html__( 'Status', 'sudemi-connector' ) . ' <select name="status"><option value="">' . esc_html__( 'All', 'sudemi-connector' ) . '</option>';
		foreach ( Sudemi_Manage::STATUSES as $status ) {
			echo '<option value="' . esc_attr( $status ) . '"' . selected( $f['status'], $status, false ) . '>' . esc_html( self::status_label( $status ) ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'From', 'sudemi-connector' ) . ' <input type="date" name="from" value="' . esc_attr( $f['from'] ) . '" /></label> ';
		echo '<label>' . esc_html__( 'To', 'sudemi-connector' ) . ' <input type="date" name="to" value="' . esc_attr( $f['to'] ) . '" /></label> ';
		echo '<label>' . esc_html__( 'Search', 'sudemi-connector' ) . ' <input type="search" name="q" value="' . esc_attr( $f['q'] ) . '" maxlength="100" placeholder="'
			. esc_attr__( 'Order number or product', 'sudemi-connector' ) . '" /></label> ';
		echo '<label>' . esc_html__( 'Sort by', 'sudemi-connector' ) . ' <select name="sort">'
			. '<option value="placed"' . selected( $f['sort'], 'placed', false ) . '>' . esc_html__( 'Date', 'sudemi-connector' ) . '</option>'
			. '<option value="total"' . selected( $f['sort'], 'total', false ) . '>' . esc_html__( 'Total', 'sudemi-connector' ) . '</option>'
			. '<option value="status"' . selected( $f['sort'], 'status', false ) . '>' . esc_html__( 'Status', 'sudemi-connector' ) . '</option>'
			. '</select></label> ';
		echo '<select name="dir" aria-label="' . esc_attr__( 'Sort by', 'sudemi-connector' ) . '">'
			. '<option value="desc"' . selected( $f['dir'], 'desc', false ) . '>' . esc_html__( 'Newest or highest first', 'sudemi-connector' ) . '</option>'
			. '<option value="asc"' . selected( $f['dir'], 'asc', false ) . '>' . esc_html__( 'Oldest or lowest first', 'sudemi-connector' ) . '</option>'
			. '</select> ';
		submit_button( __( 'Filter', 'sudemi-connector' ), 'secondary', '', false );
		echo '</form>';
	}

	private static function render_export_form( array $f ) {
		$lang  = Sudemi_Support::locale_for( get_user_locale() );
		$lang  = null === $lang ? 'en' : $lang;
		$comma = function_exists( 'wc_get_price_decimal_separator' ) && ',' === wc_get_price_decimal_separator();
		echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sudemi-export">';
		echo '<input type="hidden" name="action" value="sudemi_orders_export" />';
		wp_nonce_field( 'sudemi_orders_export', '_wpnonce', false );
		foreach ( array( 'status', 'from', 'to', 'q', 'sort', 'dir' ) as $key ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $f[ $key ] ) . '" />';
		}
		echo '<label>' . esc_html__( 'Language of the file', 'sudemi-connector' ) . ' <select name="lang">';
		foreach ( Sudemi_Support::LOCALES as $code ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $lang, $code, false ) . '>' . esc_html( $code ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Numbers', 'sudemi-connector' ) . ' <select name="decimal">'
			. '<option value="point"' . selected( $comma, false, false ) . '>' . esc_html__( '1234.50, comma separated', 'sudemi-connector' ) . '</option>'
			. '<option value="comma"' . selected( $comma, true, false ) . '>' . esc_html__( '1234,50, semicolon separated', 'sudemi-connector' ) . '</option>'
			. '</select></label> ';
		submit_button( __( 'Download these orders (CSV)', 'sudemi-connector' ), 'secondary', '', false );
		echo '<p class="description">' . esc_html__( 'Every order that matches the filters, up to 10,000, without buyer names or addresses.', 'sudemi-connector' ) . '</p>';
		echo '</form>';
	}

	/** Automatic confirmation on or off: with it off, waiting orders are answered here by hand. */
	private static function render_auto_confirm() {
		$auto = Sudemi_Orders::auto_confirm_enabled();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sudemi-auto">';
		wp_nonce_field( 'sudemi_auto_confirm' );
		echo '<input type="hidden" name="action" value="sudemi_auto_confirm" />';
		echo '<label><input type="checkbox" name="auto_confirm" value="yes"' . checked( $auto, true, false ) . ' /> '
			. esc_html__( 'Confirm orders automatically when the items are in stock', 'sudemi-connector' ) . '</label> ';
		submit_button( __( 'Save', 'sudemi-connector' ), 'secondary', 'submit', false );
		echo '<p class="description">' . esc_html(
			$auto
				? __( 'On: every five minutes this shop accepts orders whose items are in stock and declines the others.', 'sudemi-connector' )
				: __( 'Off: orders wait for you. Accept or decline each one here before the date shown; without an answer the buyer is released and charged nothing.', 'sudemi-connector' )
		) . '</p>';
		echo '</form>';
	}

	// ------------------------------------------------------------- order detail ----

	private static function render_order( $id ) {
		echo '<p><a class="sudemi-back" href="' . esc_url( self::url( array( 'tab' => 'orders' ) ) ) . '">&larr; ' . esc_html__( 'Back to the orders', 'sudemi-connector' ) . '</a></p>';
		$read = Sudemi_Manage::order( $id );
		if ( ! $read['ok'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( Sudemi_Manage::error_message( $read['error'], $read['message'] ) ) . '</p></div>';
			return;
		}
		$d = $read['body'];
		// What is shown here is what sudemi has: the WooCommerce order follows it.
		Sudemi_Changes::apply_entry( Sudemi_Manage::entry_from_detail( $d ), false );

		$status   = self::str( $d, 'status' );
		$money    = isset( $d['money'] ) && is_array( $d['money'] ) ? $d['money'] : array();
		$currency = self::str( $money, 'currency_code' );
		$allowed  = isset( $d['allowed_actions'] ) && is_array( $d['allowed_actions'] ) ? array_map( 'strval', $d['allowed_actions'] ) : array();
		$local    = Sudemi_Orders::find_any_local_order( $id );

		echo '<section class="sudemi-card"><h2 class="sudemi-card-title">' . esc_html(
			sprintf(
				/* translators: 1: short order number, 2: status. */
				__( 'Order %1$s: %2$s', 'sudemi-connector' ),
				substr( $id, 0, 8 ),
				self::status_label( $status )
			)
		) . '</h2>';

		if ( 'AWAITING_SELLER' === $status ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'Waiting for your answer. The buyer is not charged until you accept. Answer by %s; without an answer the buyer is released.', 'sudemi-connector' ),
					self::date( self::str( $d, 'respond_by' ) )
				)
			) . '</p>';
			$short = Sudemi_Orders::first_shortfall( Sudemi_Manage::pending_from_detail( $d ) );
			if ( null !== $short ) {
				echo '<p><strong>' . esc_html(
					sprintf(
						/* translators: %s: what is missing, e.g. "Mug" is out of stock. */
						__( 'Stock check: %s.', 'sudemi-connector' ),
						$short
					)
				) . '</strong></p>';
			}
			echo '</div>';
		}

		echo '<table class="sudemi-table"><tbody>';
		self::detail_row( __( 'sudemi order', 'sudemi-connector' ), '<code>' . esc_html( $id ) . '</code>' );
		self::detail_row( __( 'Placed', 'sudemi-connector' ), esc_html( self::date( self::str( $d, 'placed_at' ) ) ) );
		self::detail_row( __( 'Ship to', 'sudemi-connector' ), esc_html( self::country( self::str( $d, 'destination_country' ) ) ) );
		if ( '' !== self::str( $d, 'customer_name' ) ) {
			self::detail_row( __( 'Customer', 'sudemi-connector' ), esc_html( self::str( $d, 'customer_name' ) ) );
		}
		self::detail_row(
			__( 'WooCommerce order', 'sudemi-connector' ),
			$local
				? '<a href="' . esc_url( $local->get_edit_order_url() ) . '">#' . esc_html( $local->get_order_number() ) . '</a> (' . esc_html( wc_get_order_status_name( $local->get_status() ) ) . ')'
				: esc_html__( 'none yet (it is created when the order is accepted)', 'sudemi-connector' )
		);
		$tracking = isset( $d['tracking'] ) && is_array( $d['tracking'] ) ? $d['tracking'] : array();
		if ( ! empty( $tracking ) ) {
			self::detail_row( __( 'Tracking', 'sudemi-connector' ), esc_html( trim( self::str( $tracking, 'carrier' ) . ' ' . self::str( $tracking, 'code' ) ) ) );
		}
		foreach ( array(
			'shipped_at'          => __( 'Shipped', 'sudemi-connector' ),
			'delivered_at'        => __( 'Delivered', 'sudemi-connector' ),
			'withdrawal_deadline' => __( 'Buyer may withdraw until', 'sudemi-connector' ),
		) as $key => $label ) {
			if ( '' !== self::str( $d, $key ) ) {
				self::detail_row( $label, esc_html( self::date( self::str( $d, $key ) ) ) );
			}
		}
		if ( '' !== self::str( $d, 'declined_reason' ) ) {
			self::detail_row( __( 'Reason declined', 'sudemi-connector' ), esc_html( self::str( $d, 'declined_reason' ) ) );
		}
		echo '</tbody></table>';

		// Lines.
		echo '<h3 class="sudemi-section-title">' . esc_html__( 'Items', 'sudemi-connector' ) . '</h3>';
		echo '<table class="sudemi-table"><thead><tr>'
			. '<th>' . esc_html__( 'Product', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Quantity', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Price', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Amount', 'sudemi-connector' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( isset( $d['lines'] ) && is_array( $d['lines'] ) ? $d['lines'] : array() as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$product = '' !== self::str( $line, 'external_id' ) && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $line['external_id'] ) : null;
			echo '<tr><td>';
			if ( $product ) {
				echo '<a href="' . esc_url( (string) get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) ) . '">' . esc_html( self::str( $line, 'title' ) ) . '</a>';
			} else {
				echo esc_html( self::str( $line, 'title' ) );
			}
			if ( '' !== self::str( $line, 'withdrawal_exclusion' ) ) {
				echo '<br /><span class="description">' . esc_html__( 'No right of withdrawal', 'sudemi-connector' ) . '</span>';
			}
			echo '</td><td>' . esc_html( self::str( $line, 'quantity' ) ) . '</td>';
			echo '<td>' . esc_html( self::money( isset( $line['unit_price_minor'] ) ? $line['unit_price_minor'] : null, $currency ) ) . '</td>';
			echo '<td>' . esc_html( self::money( isset( $line['goods_minor'] ) ? $line['goods_minor'] : null, $currency ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// Money and payout.
		echo '<h3 class="sudemi-section-title">' . esc_html__( 'Money', 'sudemi-connector' ) . '</h3>';
		echo '<table class="sudemi-table"><tbody>';
		foreach ( array(
			'goods_minor'      => __( 'Goods', 'sudemi-connector' ),
			'shipping_minor'   => __( 'Shipping', 'sudemi-connector' ),
			'total_minor'      => __( 'Buyer paid', 'sudemi-connector' ),
			'commission_minor' => __( 'sudemi commission', 'sudemi-connector' ),
			'your_net_minor'   => __( 'You receive', 'sudemi-connector' ),
			'refunded_minor'   => __( 'Refunded to the buyer', 'sudemi-connector' ),
		) as $key => $label ) {
			self::detail_row( $label, esc_html( self::money( isset( $money[ $key ] ) ? $money[ $key ] : null, $currency ) ) );
		}
		$payout = isset( $d['payout'] ) && is_array( $d['payout'] ) ? $d['payout'] : array();
		if ( ! empty( $payout ) ) {
			$pc = '' !== self::str( $payout, 'currency_code' ) ? self::str( $payout, 'currency_code' ) : $currency;
			self::detail_row( __( 'Payout', 'sudemi-connector' ), esc_html( self::payout_label( self::str( $payout, 'state' ) ) ) );
			self::detail_row( __( 'Payout expected', 'sudemi-connector' ), esc_html( self::money( isset( $payout['expected_minor'] ) ? $payout['expected_minor'] : null, $pc ) ) );
			self::detail_row( __( 'Paid out', 'sudemi-connector' ), esc_html( self::money( isset( $payout['paid_out_minor'] ) ? $payout['paid_out_minor'] : 0, $pc ) ) );
			if ( '' !== self::str( $payout, 'due_at' ) ) {
				self::detail_row( __( 'Payout due', 'sudemi-connector' ), esc_html( self::date( self::str( $payout, 'due_at' ) ) ) );
			}
		}
		echo '</tbody></table>';

		// The buyer's open request.
		$request = isset( $d['customer_request'] ) && is_array( $d['customer_request'] ) ? $d['customer_request'] : null;
		if ( $request ) {
			echo '<h3 class="sudemi-section-title" id="sudemi-request">' . esc_html__( 'The buyer asked to return this order', 'sudemi-connector' ) . '</h3>';
			echo '<p>' . esc_html__( 'Buyer\'s reason', 'sudemi-connector' ) . ': ' . esc_html( '' === self::str( $request, 'reason' ) ? __( 'none given', 'sudemi-connector' ) : self::str( $request, 'reason' ) ) . '<br />'
				. esc_html__( 'Answer by', 'sudemi-connector' ) . ': ' . esc_html( self::date( self::str( $request, 'respond_by' ) ) ) . '</p>';
			if ( in_array( 'request_accept', $allowed, true ) || in_array( 'request_reject', $allowed, true ) ) {
				Sudemi_Admin::render_request_forms( array( 'order_id' => $id ) );
			}
		}

		self::render_actions( $id, $allowed, $currency, $money, $tracking );

		// Timeline.
		echo '<h3 class="sudemi-section-title">' . esc_html__( 'History', 'sudemi-connector' ) . '</h3><ol class="sudemi-timeline">';
		foreach ( isset( $d['timeline'] ) && is_array( $d['timeline'] ) ? $d['timeline'] : array() as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$parts = array();
			if ( isset( $event['amount_minor'] ) ) {
				$parts[] = self::money( $event['amount_minor'], $currency );
			}
			if ( '' !== self::str( $event, 'carrier' ) || '' !== self::str( $event, 'tracking_code' ) ) {
				$parts[] = trim( self::str( $event, 'carrier' ) . ' ' . self::str( $event, 'tracking_code' ) );
			}
			if ( '' !== self::str( $event, 'outcome' ) ) {
				$parts[] = self::str( $event, 'outcome' );
			}
			if ( '' !== self::str( $event, 'reason' ) ) {
				$parts[] = '"' . self::str( $event, 'reason' ) . '"';
			}
			echo '<li><strong>' . esc_html( self::event_label( self::str( $event, 'type' ) ) ) . '</strong> '
				. '<span class="description">' . esc_html( self::date( self::str( $event, 'at' ) ) ) . '</span>'
				. ( empty( $parts ) ? '' : ': ' . esc_html( implode( ', ', $parts ) ) ) . '</li>';
		}
		echo '</ol></section>';
	}

	private static function detail_row( $label, $html ) {
		// $html is built escaped by every caller.
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** One form per action sudemi allows now (allowed_actions); sudemi still decides. */
	private static function render_actions( $id, array $allowed, $currency, array $money, array $tracking = array() ) {
		$shown = array_values( array_intersect( Sudemi_Manage::ACTIONS, $allowed ) );
		if ( empty( $shown ) ) {
			return;
		}
		echo '<h3 class="sudemi-section-title">' . esc_html__( 'Actions', 'sudemi-connector' ) . '</h3><div class="sudemi-actions">';
		foreach ( $shown as $action ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sudemi-action sudemi-action-' . esc_attr( $action ) . '">';
			wp_nonce_field( 'sudemi_order_action' );
			echo '<input type="hidden" name="action" value="sudemi_order_action" />';
			echo '<input type="hidden" name="order_id" value="' . esc_attr( $id ) . '" />';
			echo '<input type="hidden" name="do" value="' . esc_attr( $action ) . '" />';
			switch ( $action ) {
				case 'accept':
					echo '<p>' . esc_html__( 'Accept: the buyer is charged and you ship the order.', 'sudemi-connector' ) . '</p>';
					submit_button( __( 'Accept the order', 'sudemi-connector' ), 'primary', 'submit', false );
					break;
				case 'reject':
					echo '<p><label>' . esc_html__( 'Reason for declining (shown to the buyer)', 'sudemi-connector' ) . '<br /><input type="text" name="reason" maxlength="500" class="regular-text" required="required" /></label></p>';
					submit_button( __( 'Decline the order', 'sudemi-connector' ), 'secondary', 'submit', false );
					break;
				case 'shipped':
					echo '<p><label>' . esc_html__( 'Carrier', 'sudemi-connector' ) . ' <input type="text" name="carrier" maxlength="100" /></label> '
						. '<label>' . esc_html__( 'Tracking code', 'sudemi-connector' ) . ' <input type="text" name="tracking" maxlength="100" /></label></p>';
					submit_button( __( 'Mark shipped', 'sudemi-connector' ), 'primary', 'submit', false );
					break;
				case 'update_tracking':
					echo '<p>' . esc_html__( 'Shipped already. Wrong or missing tracking? Enter the right one: sudemi e-mails the buyer the new code.', 'sudemi-connector' ) . '</p>';
					echo '<p><label>' . esc_html__( 'Carrier', 'sudemi-connector' ) . ' <input type="text" name="carrier" maxlength="100" value="' . esc_attr( self::str( $tracking, 'carrier' ) ) . '" /></label> '
						. '<label>' . esc_html__( 'Tracking code', 'sudemi-connector' ) . ' <input type="text" name="tracking" maxlength="100" required="required" value="' . esc_attr( self::str( $tracking, 'code' ) ) . '" /></label></p>';
					submit_button( __( 'Change tracking', 'sudemi-connector' ), 'secondary', 'submit', false );
					break;
				case 'delivered':
					submit_button( __( 'Mark delivered', 'sudemi-connector' ), 'secondary', 'submit', false );
					break;
				case 'refund':
					$left = isset( $money['total_minor'] ) ? (int) $money['total_minor'] - ( isset( $money['refunded_minor'] ) ? (int) $money['refunded_minor'] : 0 ) : null;
					echo '<input type="hidden" name="currency" value="' . esc_attr( $currency ) . '" />';
					echo '<input type="hidden" name="key" value="' . esc_attr( wp_generate_uuid4() ) . '" />';
					echo '<p><label>' . esc_html(
						sprintf(
							/* translators: %s: currency code. */
							__( 'Amount to refund (%s)', 'sudemi-connector' ),
							$currency
						)
					) . ' <input type="text" name="amount" inputmode="decimal" size="10" /></label> '
						. '<span class="description">' . esc_html(
							sprintf(
								/* translators: %s: amount. */
								__( 'Leave empty to refund everything not refunded yet (%s).', 'sudemi-connector' ),
								self::money( $left, $currency )
							)
						) . '</span></p>';
					echo '<p><label>' . esc_html__( 'Reason', 'sudemi-connector' ) . ' <input type="text" name="reason" maxlength="500" class="regular-text" /></label></p>';
					submit_button( __( 'Refund through sudemi', 'sudemi-connector' ), 'secondary', 'submit', false );
					break;
				case 'cancel':
					echo '<input type="hidden" name="key" value="' . esc_attr( wp_generate_uuid4() ) . '" />';
					echo '<p>' . esc_html__( 'Cancel: you cannot fulfil this paid order. The buyer is refunded in full.', 'sudemi-connector' ) . '</p>';
					echo '<p><label>' . esc_html__( 'Reason', 'sudemi-connector' ) . ' <input type="text" name="reason" maxlength="500" class="regular-text" /></label></p>';
					submit_button( __( 'Cancel and refund in full', 'sudemi-connector' ), 'delete', 'submit', false );
					break;
			}
			echo '</form>';
		}
		echo '</div>';
	}

	public static function handle_order_action() {
		self::guard( 'sudemi_order_action' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
		$id     = isset( $_POST['order_id'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) ) : '';
		$action = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$data   = array();
		foreach ( array( 'amount', 'currency', 'key' ) as $field ) {
			$data[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		foreach ( array( 'reason', 'carrier', 'tracking' ) as $field ) {
			$data[ $field ] = self::posted_text( $field );
		}
		// phpcs:enable
		$result = Sudemi_Manage::act( $id, $action, $data );
		self::put_flash( $result['ok'], $result['message'] );
		wp_safe_redirect( Sudemi_Manage::is_order_id( $id ) ? self::url( array( 'tab' => 'orders', 'order' => $id ) ) : self::url( array( 'tab' => 'orders' ) ) );
		exit;
	}

	/**
	 * A one-line text field as typed (a reason the buyer is shown, a carrier, a tracking code).
	 * Not run through sanitize_text_field(), which drops anything that looks like a tag and so
	 * changes what the merchant wrote: it is sent as JSON and escaped wherever it is shown, here
	 * and on sudemi. Invalid UTF-8 and control characters are removed.
	 */
	private static function posted_text( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in guard(); cleaned below.
		$raw = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? (string) wp_unslash( $_POST[ $field ] ) : '';
		return Sudemi_Manage::clean_text( wp_check_invalid_utf8( $raw ), Sudemi_Manage::REASON_MAX );
	}

	public static function handle_bulk() {
		self::guard( 'sudemi_orders_bulk' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
		$action   = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids      = isset( $_POST['orders'] ) && is_array( $_POST['orders'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['orders'] ) ) : array();
		$reason   = self::posted_text( 'reason' );
		$carrier  = self::posted_text( 'carrier' );
		$tracking = array();
		if ( isset( $_POST['tracking'] ) && is_array( $_POST['tracking'] ) ) {
			foreach ( wp_unslash( $_POST['tracking'] ) as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned below.
				$tracking[ strtolower( sanitize_key( (string) $key ) ) ] = Sudemi_Manage::clean_text( wp_check_invalid_utf8( (string) $value ), Sudemi_Manage::TRACKING_MAX );
			}
		}
		// phpcs:enable
		$result = Sudemi_Manage::bulk( $action, $ids, $reason, $carrier, $tracking );
		self::put_flash( $result['ok'], $result['message'] );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : self::url( array( 'tab' => 'orders' ) ) );
		exit;
	}

	/** The CSV export through this site: signed here, so the private key never leaves the server. */
	public static function handle_export() {
		self::guard( 'sudemi_orders_export' );
		$in = array();
		foreach ( array( 'status', 'from', 'to', 'q', 'sort', 'dir' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked in guard().
				$in[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		$lang  = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : 'en'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$comma = isset( $_GET['decimal'] ) && 'comma' === sanitize_key( wp_unslash( $_GET['decimal'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f     = Sudemi_Manage::filters( $in );
		$out   = Sudemi_Manage::export( $f, $lang, $comma );
		if ( ! $out['ok'] ) {
			self::put_flash( false, Sudemi_Manage::error_message( $out['error'], $out['message'] ) );
			wp_safe_redirect( self::url( self::filter_args( $f ) ) );
			exit;
		}
		if ( $out['truncated'] ) {
			Sudemi_Logger::warn(
				sprintf(
					/* translators: %d: number of orders. */
					__( 'The order export was cut off at 10,000 of %d orders. Narrow the dates to get the rest.', 'sudemi-connector' ),
					$out['total']
				)
			);
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $out['filename'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Total-Count: ' . (int) $out['total'] );
		echo $out['csv']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a file download (text/csv attachment, nosniff), not HTML.
		exit;
	}

	public static function handle_auto_confirm() {
		self::guard( 'sudemi_auto_confirm' );
		$on = isset( $_POST['auto_confirm'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['auto_confirm'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		update_option( Sudemi_Orders::OPTION_AUTO_CONFIRM, $on ? 'yes' : 'no', false );
		self::put_flash(
			true,
			$on ? __( 'Orders are confirmed automatically.', 'sudemi-connector' ) : __( 'Orders now wait for you to accept or decline them.', 'sudemi-connector' )
		);
		wp_safe_redirect( self::url( array( 'tab' => 'orders' ) ) );
		exit;
	}

	// ----------------------------------------------------------------- shipping ----

	public static function render_shipping() {
		if ( ! Sudemi_Client::is_connected() ) {
			self::not_connected();
			return;
		}
		self::render_flash();
		$read = Sudemi_Manage::shipping();
		if ( ! $read['ok'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Sudemi_Manage::error_message( $read['error'], $read['message'] ) ) . '</p></div>';
			return;
		}
		$s         = $read['body'];
		$currency  = self::str( $s, 'currency' );
		$currency  = '' === $currency ? Sudemi_Currency::shop_currency() : $currency;
		$countries = isset( $s['countries'] ) && is_array( $s['countries'] ) ? $s['countries'] : array();
		$effective = array();
		foreach ( isset( $s['effective'] ) && is_array( $s['effective'] ) ? $s['effective'] : array() as $row ) {
			if ( is_array( $row ) && '' !== self::str( $row, 'country' ) ) {
				$effective[ self::str( $row, 'country' ) ] = $row;
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, nothing changes.
		$prefill = isset( $_GET['prefill'] ) && '1' === $_GET['prefill'];
		$zones   = $prefill ? self::zone_prices() : array();
		$mode    = $prefill ? 'custom' : ( 'custom' === self::str( $s, 'mode' ) ? 'custom' : 'shop' );

		echo '<section class="sudemi-card">';
		echo '<p>' . esc_html__( 'What sudemi buyers pay for shipping, per country. By default the prices this plugin sends from your WooCommerce shipping zones apply. You can instead set your own price per country on sudemi, and choose the countries you deliver to. One charge per parcel, never per item.', 'sudemi-connector' ) . '</p>';
		if ( $prefill ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Prices filled in from your WooCommerce shipping zones. Check them and save; nothing is changed on sudemi until you do.', 'sudemi-connector' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sudemi-shipping">';
		wp_nonce_field( 'sudemi_shipping_save' );
		echo '<input type="hidden" name="action" value="sudemi_shipping_save" />';
		echo '<input type="hidden" name="currency" value="' . esc_attr( $currency ) . '" />';
		echo '<fieldset class="sudemi-fieldset"><legend class="screen-reader-text">' . esc_html__( 'Shipping prices', 'sudemi-connector' ) . '</legend>';
		echo '<label><input type="radio" name="mode" value="shop"' . checked( $mode, 'shop', false ) . ' /> '
			. esc_html__( 'Use the prices from my WooCommerce shipping zones', 'sudemi-connector' ) . '</label><br />';
		echo '<label><input type="radio" name="mode" value="custom"' . checked( $mode, 'custom', false ) . ' /> '
			. esc_html__( 'Set my own countries and prices on sudemi', 'sudemi-connector' ) . '</label></fieldset>';

		echo '<div class="sudemi-table-scroll"><table class="sudemi-table"><thead><tr>'
			. '<th>' . esc_html__( 'Country', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Deliver there', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html(
				sprintf(
					/* translators: %s: currency code. */
					__( 'Your price on sudemi (%s)', 'sudemi-connector' ),
					$currency
				)
			) . '</th>'
			. '<th>' . esc_html__( 'Prices from your shipping zones', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Buyers pay now', 'sudemi-connector' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $countries as $row ) {
			$code = strtoupper( self::str( $row, 'country' ) );
			if ( ! preg_match( '/^[A-Z]{2}$/', $code ) ) {
				continue;
			}
			$enabled = ! empty( $row['enabled'] );
			$price   = isset( $row['price_minor'] ) && is_numeric( $row['price_minor'] ) ? Sudemi_Currency::to_major( (int) $row['price_minor'], $currency ) : '';
			if ( $prefill ) {
				$enabled = isset( $zones[ $code ] );
				$price   = isset( $zones[ $code ] ) ? Sudemi_Currency::to_major( $zones[ $code ], $currency ) : '';
			}
			$range = isset( $row['shop_price_min_minor'] ) && null !== $row['shop_price_min_minor']
				? self::range( $row['shop_price_min_minor'], isset( $row['shop_price_max_minor'] ) ? $row['shop_price_max_minor'] : null, $currency )
				: '-';
			$eff = isset( $effective[ $code ] ) ? $effective[ $code ] : array();
			if ( empty( $eff['offered'] ) ) {
				$now = __( 'not offered', 'sudemi-connector' );
			} else {
				$now = self::range( isset( $eff['min_minor'] ) ? $eff['min_minor'] : null, isset( $eff['max_minor'] ) ? $eff['max_minor'] : null, $currency )
					. ' (' . ( 'sudemi_price' === self::str( $eff, 'source' ) ? __( 'your price on sudemi', 'sudemi-connector' ) : __( 'from your shop', 'sudemi-connector' ) ) . ')';
			}
			echo '<tr><td>' . esc_html( self::country( $code ) ) . '</td>';
			echo '<td><input type="checkbox" name="countries[' . esc_attr( $code ) . '][enabled]" value="1"' . checked( $enabled, true, false ) . ' aria-label="'
				. esc_attr( $code ) . '" /></td>';
			echo '<td><input type="text" inputmode="decimal" size="8" name="countries[' . esc_attr( $code ) . '][price]" value="' . esc_attr( $price ) . '" aria-label="'
				. esc_attr( $code ) . '" /></td>';
			echo '<td>' . esc_html( $range ) . '</td>';
			echo '<td class="sudemi-effective-' . esc_attr( strtolower( $code ) ) . '">' . esc_html( $now ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="description">' . esc_html__( 'With your own countries and prices: only the ticked countries are offered. A country with an empty price uses the price from your shipping zones.', 'sudemi-connector' ) . '</p>';
		submit_button( __( 'Save shipping on sudemi', 'sudemi-connector' ), 'primary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( self::url( array( 'tab' => 'shipping', 'prefill' => '1' ) ) ) . '">' . esc_html__( 'Fill in from my WooCommerce shipping zones', 'sudemi-connector' ) . '</a>';
		echo '</form></section>';
	}

	private static function range( $min, $max, $currency ) {
		if ( null === $min || ! is_numeric( $min ) ) {
			return '-';
		}
		if ( null === $max || ! is_numeric( $max ) || (int) $max === (int) $min ) {
			return self::money( $min, $currency );
		}
		return self::money( $min, $currency ) . ' - ' . self::money( $max, $currency );
	}

	/** Country => price in minor units, from the shop's zones (one item of its first product). */
	private static function zone_prices() {
		$sample = function_exists( 'wc_get_products' ) ? wc_get_products( array( 'limit' => 1, 'status' => 'publish', 'virtual' => false ) ) : array();
		$out    = array();
		foreach ( $sample ? Sudemi_Shipping::rates_for( $sample[0] ) : array() as $rate ) {
			$out[ $rate['country'] ] = (int) $rate['costMinor'];
		}
		return $out;
	}

	public static function handle_shipping() {
		self::guard( 'sudemi_shipping_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
		$mode     = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$currency = isset( $_POST['currency'] ) ? Sudemi_Currency::normalise( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : '';
		$rows     = array();
		if ( isset( $_POST['countries'] ) && is_array( $_POST['countries'] ) ) {
			foreach ( wp_unslash( $_POST['countries'] ) as $code => $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field of a row is cleaned below.
				$code = strtoupper( sanitize_key( (string) $code ) );
				if ( ! is_array( $row ) ) {
					continue;
				}
				$rows[ $code ] = array(
					'enabled' => ! empty( $row['enabled'] ),
					'price'   => isset( $row['price'] ) ? sanitize_text_field( (string) $row['price'] ) : '',
				);
			}
		}
		// phpcs:enable
		$known = array();
		$read  = Sudemi_Manage::shipping();
		if ( $read['ok'] && isset( $read['body']['countries'] ) && is_array( $read['body']['countries'] ) ) {
			foreach ( $read['body']['countries'] as $row ) {
				if ( is_array( $row ) && isset( $row['country'] ) ) {
					$known[] = strtoupper( (string) $row['country'] );
				}
			}
			if ( '' !== self::str( $read['body'], 'currency' ) ) {
				$currency = Sudemi_Currency::normalise( self::str( $read['body'], 'currency' ) );
			}
		}
		$body = Sudemi_Manage::shipping_body( $mode, '' === $currency ? Sudemi_Currency::shop_currency() : $currency, $rows, $known );
		if ( is_string( $body ) ) {
			self::put_flash( false, Sudemi_Manage::error_message( $body ) );
		} else {
			$saved = Sudemi_Manage::save_shipping( $body );
			if ( $saved['ok'] ) {
				Sudemi_Logger::info( __( 'Shipping settings saved on sudemi.', 'sudemi-connector' ) );
				self::put_flash( true, __( 'Saved on sudemi. Buyers see these shipping prices from now on.', 'sudemi-connector' ) );
			} else {
				self::put_flash( false, Sudemi_Manage::error_message( $saved['error'], $saved['message'] ) );
			}
		}
		wp_safe_redirect( self::url( array( 'tab' => 'shipping' ) ) );
		exit;
	}

	// ------------------------------------------------------------------ payouts ----

	public static function render_payouts() {
		if ( ! Sudemi_Client::is_connected() ) {
			self::not_connected();
			return;
		}
		$read = Sudemi_Manage::payouts();
		if ( ! $read['ok'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( Sudemi_Manage::error_message( $read['error'], $read['message'] ) ) . '</p></div>';
			return;
		}
		$p       = $read['body'];
		$account = isset( $p['account'] ) && is_array( $p['account'] ) ? $p['account'] : array();
		$state   = self::str( $account, 'state' );

		echo '<section class="sudemi-card"><h2 class="sudemi-card-title">' . esc_html__( 'Payout account', 'sudemi-connector' ) . '</h2>';
		echo '<table class="sudemi-table"><tbody>';
		self::detail_row( __( 'State', 'sudemi-connector' ), esc_html( '' === $state ? '-' : self::account_label( $state ) ) );
		if ( '' !== self::str( $account, 'action_deadline' ) ) {
			self::detail_row( __( 'Act before', 'sudemi-connector' ), esc_html( self::date( self::str( $account, 'action_deadline' ) ) ) );
		}
		// Core pays this many days after delivery, or 14 days more after shipping when delivery is never reported.
		$delay = isset( $p['payout_delay_days'] ) ? (int) $p['payout_delay_days'] : 14;
		self::detail_row(
			__( 'When you are paid', 'sudemi-connector' ),
			esc_html(
				sprintf(
					/* translators: 1: days after delivery, 2: days after shipping when delivery is never reported. */
					__( '%1$d days after the buyer received the order, or %2$d days after shipping if delivery is never reported; never while a chargeback is open.', 'sudemi-connector' ),
					$delay,
					$delay + 14
				)
			)
		);
		echo '</tbody></table>';
		if ( in_array( $state, array( 'NOT_STARTED', 'INCOMPLETE', 'ACTION_REQUIRED' ), true ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Your payout account needs attention. It is managed on the sudemi seller portal (Payouts), not here.', 'sudemi-connector' ) . '</p></div>';
		} else {
			echo '<p class="description">' . esc_html__( 'The payout account is managed on the sudemi seller portal (Payouts).', 'sudemi-connector' ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( Sudemi_Admin::portal_url( '/seller/payouts' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open payouts on the seller portal', 'sudemi-connector' ) . '</a></p>';
		echo '</section>';

		echo '<section class="sudemi-card"><h2 class="sudemi-card-title">' . esc_html__( 'This shop\'s payouts', 'sudemi-connector' ) . '</h2>';
		$currencies = isset( $p['currencies'] ) && is_array( $p['currencies'] ) ? $p['currencies'] : array();
		if ( empty( $currencies ) ) {
			echo '<p class="sudemi-muted">' . esc_html__( 'No payouts yet.', 'sudemi-connector' ) . '</p></section>';
			return;
		}
		echo '<div class="sudemi-table-scroll"><table class="sudemi-table sudemi-payouts"><thead><tr>'
			. '<th>' . esc_html__( 'Currency', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Orders', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Paid out', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Still to come', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Due once shipped', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Scheduled', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'On its way to you', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Held (chargeback)', 'sudemi-connector' ) . '</th>'
			. '<th>' . esc_html__( 'Next payout', 'sudemi-connector' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $currencies as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$c = self::str( $row, 'currency_code' );
			echo '<tr><td>' . esc_html( $c ) . '</td><td>' . esc_html( self::str( $row, 'orders' ) ) . '</td>';
			foreach ( array( 'paid_out_minor', 'outstanding_minor', 'waiting_for_shipment_minor', 'scheduled_minor', 'processing_minor', 'held_minor' ) as $key ) {
				echo '<td>' . esc_html( self::money( isset( $row[ $key ] ) ? $row[ $key ] : 0, $c ) ) . '</td>';
			}
			echo '<td>' . esc_html( self::date( self::str( $row, 'next_due_at' ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}
}
