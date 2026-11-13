<?php
/**
 * What sudemi knows about this shop (protocol 4e): statistics, the shop's products as sudemi
 * holds them, and the shop profile buyers see.
 *
 * Everything here is read from sudemi on each view (signed calls, this shop only); nothing is
 * cached except the shop profile values this shop chose, which the catalogue sync sends again so
 * the next sync never overwrites them with WordPress defaults.
 *
 * The pure parts (periods, paths, labels, the chart) run without WordPress, for the tests.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Insights {

	/** Periods the dashboard offers, in days. */
	const PERIODS = array( 7, 30, 90 );

	const DEFAULT_PERIOD = 30;

	/** Listing filters (protocol 4e `status`). */
	const LISTING_STATUSES = array( 'all', 'listed', 'not_listed' );

	const LISTING_PAGE_SIZE = 25;

	const OPTION_DESCRIPTION   = 'sudemi_profile_description';
	const OPTION_SUPPORT_EMAIL = 'sudemi_profile_support_email';
	const OPTION_RETURNS_DAYS  = 'sudemi_profile_returns_days';

	/** The law gives buyers at least 14 days; a shop may give more, up to a year. */
	const RETURNS_MIN = 14;
	const RETURNS_MAX = 365;

	const DESCRIPTION_MAX = 2000;

	// ------------------------------------------------------------------ pure ----

	/** A period from the query string, one of PERIODS. */
	public static function period( $value ) {
		$days = is_numeric( $value ) ? (int) $value : 0;
		return in_array( $days, self::PERIODS, true ) ? $days : self::DEFAULT_PERIOD;
	}

	/**
	 * The stats path for the last $days days up to $today (UTC, inclusive).
	 *
	 * @param int    $days  One of PERIODS.
	 * @param string $today YYYY-MM-DD.
	 */
	public static function stats_path( $days, $today ) {
		$to   = new DateTimeImmutable( $today . ' 00:00:00', new DateTimeZone( 'UTC' ) );
		$from = $to->modify( '-' . ( self::period( $days ) - 1 ) . ' days' );
		return '/integration/v1/stats?' . http_build_query(
			array(
				'from' => $from->format( 'Y-m-d' ),
				'to'   => $to->format( 'Y-m-d' ),
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/** Filters for the products list, cleaned: only known values reach sudemi's query string. */
	public static function listing_filters( array $in ) {
		$status = isset( $in['status'] ) ? strtolower( (string) $in['status'] ) : 'all';
		$q      = isset( $in['q'] ) ? (string) $in['q'] : '';
		$q      = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $q ) );
		if ( function_exists( 'mb_substr' ) ) {
			$q = mb_substr( $q, 0, 100 );
		}
		$page = isset( $in['paged'] ) && is_numeric( $in['paged'] ) ? max( 0, (int) $in['paged'] ) : 0;
		return array(
			'status' => in_array( $status, self::LISTING_STATUSES, true ) ? $status : 'all',
			'q'      => $q,
			'page'   => min( $page, 10000 ),
		);
	}

	public static function listings_path( array $f, $size = self::LISTING_PAGE_SIZE ) {
		$query = array(
			'status' => $f['status'],
			'page'   => (int) $f['page'],
			'size'   => max( 1, min( 200, (int) $size ) ),
		);
		if ( '' !== $f['q'] ) {
			$query['q'] = $f['q'];
		}
		return '/integration/v1/listings?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/** An external id as this plugin sends them (WooCommerce product or variation id). */
	public static function is_external_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[0-9]{1,20}$/', $id );
	}

	public static function enabled_path( $external_id ) {
		return '/integration/v1/listings/' . rawurlencode( (string) $external_id ) . '/enabled';
	}

	/** Why a product is not shown to buyers, in the shop owner's words. */
	public static function reason_label( $code ) {
		switch ( (string) $code ) {
			case 'disabled_by_shop':
				return __( 'Switched off by you', 'sudemi' );
			case 'not_in_shop':
				return __( 'No longer in your shop (deleted, hidden or not published)', 'sudemi' );
			case 'out_of_stock':
				return __( 'Out of stock', 'sudemi' );
			case 'no_shipping':
				return __( 'No shipping price for any country', 'sudemi' );
			case 'suspended':
				return __( 'Taken down by sudemi (see your e-mail or contact support)', 'sudemi' );
			case 'payouts_not_ready':
				return __( 'Your payout account is not active yet', 'sudemi' );
			case 'shop_not_reporting':
				return __( 'This shop stopped reporting in to sudemi', 'sudemi' );
		}
		return __( 'Not listed', 'sudemi' );
	}

	/** Basis points as a percentage with one decimal ("6.4%"). */
	public static function percent( $bps ) {
		$value = is_numeric( $bps ) ? (int) $bps / 100 : 0;
		return self::number( $value, 1 ) . '%';
	}

	public static function number( $value, $decimals = 0 ) {
		if ( function_exists( 'number_format_i18n' ) ) {
			return number_format_i18n( (float) $value, $decimals );
		}
		return number_format( (float) $value, $decimals );
	}

	public static function money( $minor, $currency ) {
		if ( null === $minor || ! is_numeric( $minor ) ) {
			return '-';
		}
		$currency = Sudemi_Currency::normalise( (string) $currency );
		$places   = Sudemi_Currency::decimals( $currency );
		return self::number( (int) $minor / ( 10 ** $places ), $places ) . ' ' . $currency;
	}

	/** A number from an answer, 0 when missing. */
	public static function int( array $a, $key ) {
		return isset( $a[ $key ] ) && is_numeric( $a[ $key ] ) ? (int) $a[ $key ] : 0;
	}

	public static function str( array $a, $key ) {
		return isset( $a[ $key ] ) && ( is_string( $a[ $key ] ) || is_numeric( $a[ $key ] ) ) ? (string) $a[ $key ] : '';
	}

	/**
	 * A daily chart as inline SVG: one or two lines (with the first filled) over the days.
	 *
	 * Drawn here rather than with a chart library so the admin loads nothing from outside. The
	 * numbers are also in the table and the cards; the chart has a text alternative.
	 *
	 * @param array  $daily  Rows with 'day' and the keys of $series.
	 * @param array  $series key => array( 'label' => string, 'class' => css class ).
	 * @param string $title  Text alternative.
	 */
	public static function chart_svg( array $daily, array $series, $title ) {
		$w     = 720;
		$h     = 220;
		$left  = 44;
		$right = 12;
		$top   = 14;
		$bot   = 28;
		$n     = count( $daily );
		$max   = 0;
		foreach ( $daily as $row ) {
			foreach ( array_keys( $series ) as $key ) {
				$max = max( $max, is_array( $row ) ? self::int( $row, $key ) : 0 );
			}
		}
		$max   = self::nice_max( $max );
		$plotw = $w - $left - $right;
		$ploth = $h - $top - $bot;
		$x     = function ( $i ) use ( $n, $left, $plotw ) {
			return $n <= 1 ? $left + $plotw / 2 : $left + $plotw * $i / ( $n - 1 );
		};
		$y     = function ( $v ) use ( $max, $top, $ploth ) {
			return $top + $ploth - ( $max > 0 ? $ploth * $v / $max : 0 );
		};

		$svg  = '<svg class="sudemi-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" preserveAspectRatio="none" aria-label="' . self::attr( $title ) . '">';
		$svg .= '<title>' . self::text( $title ) . '</title>';
		// Grid: 4 lines with their values.
		for ( $g = 0; $g <= 4; $g++ ) {
			$v    = $max * $g / 4;
			$gy   = round( $y( $v ), 1 );
			$svg .= '<line class="sudemi-chart-grid" x1="' . $left . '" x2="' . ( $w - $right ) . '" y1="' . $gy . '" y2="' . $gy . '" />';
			$svg .= '<text class="sudemi-chart-axis" x="' . ( $left - 6 ) . '" y="' . ( $gy + 4 ) . '" text-anchor="end">' . self::text( self::short_number( $v ) ) . '</text>';
		}
		// Day labels: first, middle, last.
		$marks = array_unique( array( 0, (int) floor( ( $n - 1 ) / 2 ), $n - 1 ) );
		foreach ( $marks as $i ) {
			if ( $i < 0 || ! isset( $daily[ $i ] ) || ! is_array( $daily[ $i ] ) ) {
				continue;
			}
			$anchor = 0 === $i ? 'start' : ( $n - 1 === $i ? 'end' : 'middle' );
			$svg   .= '<text class="sudemi-chart-axis" x="' . round( $x( $i ), 1 ) . '" y="' . ( $h - 8 ) . '" text-anchor="' . $anchor . '">'
				. self::text( self::short_day( self::str( $daily[ $i ], 'day' ) ) ) . '</text>';
		}
		$first = true;
		foreach ( $series as $key => $meta ) {
			$points = array();
			foreach ( array_values( $daily ) as $i => $row ) {
				$points[] = round( $x( $i ), 1 ) . ',' . round( $y( is_array( $row ) ? self::int( $row, $key ) : 0 ), 1 );
			}
			if ( empty( $points ) ) {
				continue;
			}
			$class = isset( $meta['class'] ) ? (string) $meta['class'] : 'sudemi-series';
			if ( $first ) {
				$area = $points;
				$area[] = round( $x( max( 0, $n - 1 ) ), 1 ) . ',' . round( $y( 0 ), 1 );
				$area[] = round( $x( 0 ), 1 ) . ',' . round( $y( 0 ), 1 );
				$svg   .= '<polygon class="' . self::attr( $class ) . ' sudemi-chart-area" points="' . implode( ' ', $area ) . '" />';
			}
			$svg  .= '<polyline class="' . self::attr( $class ) . ' sudemi-chart-line" points="' . implode( ' ', $points ) . '" />';
			$first = false;
		}
		$svg .= '</svg>';
		return $svg;
	}

	/** Bars for one money series per day (sales). */
	public static function bars_svg( array $daily, $key, $title ) {
		$w     = 720;
		$h     = 220;
		$left  = 44;
		$right = 12;
		$top   = 14;
		$bot   = 28;
		$n     = max( 1, count( $daily ) );
		$max   = 0;
		foreach ( $daily as $row ) {
			$max = max( $max, is_array( $row ) ? self::int( $row, $key ) : 0 );
		}
		$max   = self::nice_max( $max );
		$plotw = $w - $left - $right;
		$ploth = $h - $top - $bot;
		$slot  = $plotw / $n;
		$bw    = max( 1.5, $slot * 0.7 );

		$svg  = '<svg class="sudemi-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" preserveAspectRatio="none" aria-label="' . self::attr( $title ) . '">';
		$svg .= '<title>' . self::text( $title ) . '</title>';
		for ( $g = 0; $g <= 4; $g++ ) {
			$v    = $max * $g / 4;
			$gy   = round( $top + $ploth - ( $max > 0 ? $ploth * $v / $max : 0 ), 1 );
			$svg .= '<line class="sudemi-chart-grid" x1="' . $left . '" x2="' . ( $w - $right ) . '" y1="' . $gy . '" y2="' . $gy . '" />';
			$svg .= '<text class="sudemi-chart-axis" x="' . ( $left - 6 ) . '" y="' . ( $gy + 4 ) . '" text-anchor="end">' . self::text( self::short_number( $v / 100 ) ) . '</text>';
		}
		foreach ( array_values( $daily ) as $i => $row ) {
			$v = is_array( $row ) ? self::int( $row, $key ) : 0;
			if ( $v <= 0 ) {
				continue;
			}
			$bh   = $max > 0 ? $ploth * $v / $max : 0;
			$svg .= '<rect class="sudemi-chart-bar" x="' . round( $left + $slot * $i + ( $slot - $bw ) / 2, 1 ) . '" y="' . round( $top + $ploth - $bh, 1 )
				. '" width="' . round( $bw, 1 ) . '" height="' . round( $bh, 1 ) . '" rx="2"><title>'
				. self::text( self::short_day( self::str( $row, 'day' ) ) . ': ' . self::number( $v / 100, 2 ) ) . '</title></rect>';
		}
		$marks = array_unique( array( 0, (int) floor( ( $n - 1 ) / 2 ), $n - 1 ) );
		foreach ( $marks as $i ) {
			if ( ! isset( $daily[ $i ] ) || ! is_array( $daily[ $i ] ) ) {
				continue;
			}
			$anchor = 0 === $i ? 'start' : ( $n - 1 === $i ? 'end' : 'middle' );
			$cx     = 0 === $i ? $left : ( $n - 1 === $i ? $w - $right : $left + $slot * ( $i + 0.5 ) );
			$svg   .= '<text class="sudemi-chart-axis" x="' . round( $cx, 1 ) . '" y="' . ( $h - 8 ) . '" text-anchor="' . $anchor . '">'
				. self::text( self::short_day( self::str( $daily[ $i ], 'day' ) ) ) . '</text>';
		}
		$svg .= '</svg>';
		return $svg;
	}

	/** A round axis top: 1, 2, 5 times a power of ten, at least the largest value. */
	public static function nice_max( $max ) {
		if ( $max <= 0 ) {
			return 4;
		}
		$power = 10 ** floor( log10( $max ) );
		foreach ( array( 1, 2, 2.5, 5, 10 ) as $step ) {
			if ( $max <= $step * $power ) {
				$top = $step * $power;
				// Whole numbers: 2.5 only occurs from 25 up, below 4 the top is 4.
				return $top < 4 ? 4 : (int) round( $top );
			}
		}
		return (int) round( 10 * $power );
	}

	private static function short_number( $v ) {
		if ( $v >= 1000000 ) {
			return self::number( $v / 1000000, 1 ) . 'M';
		}
		if ( $v >= 10000 ) {
			return self::number( $v / 1000, 0 ) . 'k';
		}
		return self::number( $v, $v < 10 && floor( $v ) != $v ? 1 : 0 ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- float vs int on purpose.
	}

	/** "2026-11-13" as "13 Nov" in the site's language. */
	private static function short_day( $day ) {
		$ts = strtotime( $day . ' 12:00:00 UTC' );
		if ( ! $ts ) {
			return (string) $day;
		}
		return function_exists( 'wp_date' ) ? (string) wp_date( 'j M', $ts, new DateTimeZone( 'UTC' ) ) : gmdate( 'j M', $ts );
	}

	private static function attr( $value ) {
		return function_exists( 'esc_attr' ) ? esc_attr( $value ) : htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	private static function text( $value ) {
		return function_exists( 'esc_html' ) ? esc_html( $value ) : htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * The profile form, checked. Returns the clean values or an error code.
	 *
	 * @return array{ok:bool,error?:string,description?:string,support_email?:string,returns_days?:int}
	 */
	public static function profile_input( $description, $email, $days ) {
		$description = trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $description ) );
		$len         = function_exists( 'mb_strlen' ) ? mb_strlen( $description ) : strlen( $description );
		if ( $len > self::DESCRIPTION_MAX ) {
			return array( 'ok' => false, 'error' => 'description_too_long' );
		}
		$email = trim( (string) $email );
		if ( '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return array( 'ok' => false, 'error' => 'invalid_email' );
		}
		if ( ! is_numeric( $days ) || (int) $days != $days || (int) $days < self::RETURNS_MIN || (int) $days > self::RETURNS_MAX ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- "30" equals 30.
			return array( 'ok' => false, 'error' => 'invalid_returns_days' );
		}
		return array(
			'ok'            => true,
			'description'   => $description,
			'support_email' => $email,
			'returns_days'  => (int) $days,
		);
	}

	public static function error_message( $code, $message = '' ) {
		switch ( (string) $code ) {
			case 'description_too_long':
				return sprintf(
					/* translators: %d: maximum number of characters. */
					__( 'The description can be at most %d characters.', 'sudemi' ),
					self::DESCRIPTION_MAX
				);
			case 'invalid_email':
				return __( 'Enter a valid e-mail address for buyers\' questions.', 'sudemi' );
			case 'invalid_returns_days':
				return sprintf(
					/* translators: 1: minimum days, 2: maximum days. */
					__( 'The return period must be a whole number of days from %1$d to %2$d.', 'sudemi' ),
					self::RETURNS_MIN,
					self::RETURNS_MAX
				);
			case 'bad_period':
				return __( 'That period cannot be shown.', 'sudemi' );
			case 'not_found':
				return __( 'sudemi does not have this product for this shop (yet). It arrives with the next sync.', 'sudemi' );
		}
		return Sudemi_Manage::error_message( $code, $message );
	}

	// ------------------------------------------------------------ with sudemi ----

	private static function call( $method, $path, array $body = array() ) {
		if ( ! Sudemi_Client::is_connected() ) {
			return array( 'ok' => false, 'status' => 0, 'body' => array(), 'error' => 'not_connected', 'message' => '' );
		}
		return Sudemi_Manage::read( Sudemi_Client::send_signed( $method, $path, $body ) );
	}

	public static function stats( $days ) {
		return self::call( 'GET', self::stats_path( $days, gmdate( 'Y-m-d' ) ) );
	}

	public static function listings( array $f, $size = self::LISTING_PAGE_SIZE ) {
		return self::call( 'GET', self::listings_path( $f, $size ) );
	}

	public static function set_enabled( $external_id, $enabled ) {
		if ( ! self::is_external_id( (string) $external_id ) ) {
			return array( 'ok' => false, 'status' => 0, 'body' => array(), 'error' => 'not_found', 'message' => '' );
		}
		return self::call( 'POST', self::enabled_path( $external_id ), array( 'enabled' => (bool) $enabled ) );
	}

	public static function shop() {
		return self::call( 'GET', '/integration/v1/shop' );
	}

	/**
	 * The profile values this shop sends: the ones chosen on the profile page, else WordPress's.
	 * Used by the catalogue sync, so a sync never puts back the WordPress tagline over them.
	 */
	public static function local_profile() {
		$description = get_option( self::OPTION_DESCRIPTION, '' );
		$email       = get_option( self::OPTION_SUPPORT_EMAIL, '' );
		$days        = get_option( self::OPTION_RETURNS_DAYS, '' );
		return array(
			'description'   => '' !== (string) $description ? (string) $description : (string) get_bloginfo( 'description' ),
			'support_email' => '' !== (string) $email ? (string) $email : (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ),
			'returns_days'  => is_numeric( $days ) ? (int) $days : null,
		);
	}

	/** Saves the profile here and on sudemi; the options are kept only when sudemi took it. */
	public static function save_profile( $description, $email, $days ) {
		$in = self::profile_input( $description, $email, $days );
		if ( ! $in['ok'] ) {
			return array( 'ok' => false, 'error' => $in['error'], 'message' => '' );
		}
		$read = self::call(
			'POST',
			'/integration/v1/shop',
			array(
				'description'       => $in['description'],
				'supportEmail'      => $in['support_email'],
				'returnsWindowDays' => $in['returns_days'],
			)
		);
		if ( ! $read['ok'] ) {
			return array( 'ok' => false, 'error' => $read['error'], 'message' => $read['message'] );
		}
		update_option( self::OPTION_DESCRIPTION, $in['description'], false );
		update_option( self::OPTION_SUPPORT_EMAIL, $in['support_email'], false );
		update_option( self::OPTION_RETURNS_DAYS, (string) $in['returns_days'], false );
		return array( 'ok' => true, 'error' => '', 'message' => '' );
	}

	public static function forget() {
		delete_option( self::OPTION_DESCRIPTION );
		delete_option( self::OPTION_SUPPORT_EMAIL );
		delete_option( self::OPTION_RETURNS_DAYS );
	}
}
