<?php
/**
 * What the plugin left out of the catalogue or could not read, reported to sudemi and to the shop
 * (owner 2026-11-19: "a product or a sync issue occurs, we need to report it to shop and to
 * sudemi as well").
 *
 * Every catalogue push (full sync and change push) carries the issues found while building it as
 * a top-level `issues` list: [{externalId, code, severity, field, message, details}]. sudemi's
 * answer (its `rejected` items and its own `issues`) is kept next to them, and the "Products on
 * sudemi" page shows both, in the shop's language.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Issues {

	/** Stable codes (snake_case), as sent. */
	const ADDON_NEGATIVE_PRICE      = 'addon_negative_price';
	const ADDON_FLAT_FEE            = 'addon_flat_fee';
	const ADDON_PER_CHARACTER_PRICE = 'addon_per_character_price';
	const ADDON_FORMULA_PRICE       = 'addon_formula_price';
	const ADDON_UNSUPPORTED_PLUGIN  = 'addon_unsupported_plugin';
	const TOO_MANY_REQUIRED_TEXTS   = 'too_many_required_texts';
	const TOO_MANY_OPTIONAL_TEXTS   = 'too_many_optional_texts';
	const VARIANT_CAP               = 'variant_cap';
	const UNREADABLE_ADDON          = 'unreadable_addon';
	const MISSING_PRICE             = 'missing_price';
	const IMAGE_SKIPPED             = 'image_skipped';
	const NOT_ORDERABLE             = 'not_orderable';

	/** Where the issues sent last and sudemi's answers to them are kept (for the admin page). */
	const OPTION = 'sudemi_catalogue_issues';

	/** The most entries kept for the admin page. */
	const MAX_KEPT = 500;

	/** Issues found while building the current push, not sent yet. */
	private static $pending = array();

	/**
	 * Notes one issue. Repeats (the same item, code and field) are kept once.
	 *
	 * @param string $external_id The listing's external id (a product or variation id).
	 * @param string $code        One of the codes above.
	 * @param string $severity    "error" (not offered) or "warning" (offered, something left out).
	 * @param string $field       What it is about (an add-on's name, "price", "options").
	 * @param string $message     In English, for sudemi.
	 * @param array  $details     Anything that helps (counts, the plugin).
	 */
	public static function add( $external_id, $code, $severity, $field, $message, array $details = array() ) {
		$issue = self::make( $external_id, $code, $severity, $field, $message, $details );
		self::$pending[ $issue['externalId'] . '|' . $issue['code'] . '|' . $issue['field'] ] = $issue;
	}

	/** One issue in the wire shape. Pure. */
	public static function make( $external_id, $code, $severity, $field, $message, array $details = array() ) {
		return array(
			'externalId' => (string) $external_id,
			'code'       => (string) $code,
			'severity'   => 'error' === $severity ? 'error' : 'warning',
			'field'      => (string) $field,
			'message'    => (string) $message,
			'details'    => (object) $details,
		);
	}

	/** The pending issues, emptied: they go with the push being sent. */
	public static function take() {
		$out           = array_values( self::$pending );
		self::$pending = array();
		return $out;
	}

	/** Issues not sent yet (no listing push carried them, or it failed): external id => issues. */
	const OPTION_QUEUE = 'sudemi_pending_issues';

	/** The most products kept waiting; a full sync sends every issue again. */
	const MAX_QUEUED = 2000;

	/** The most issues in one request. */
	const MAX_PER_REQUEST = 500;

	/**
	 * Keeps issues for the next listing push or the heartbeat, never a request of their own while
	 * a product is saved. The newest issues of a product replace older ones. They show on the
	 * shop's page at once.
	 */
	public static function queue( array $issues ) {
		if ( empty( $issues ) ) {
			return;
		}
		$queued = self::queued_map();
		foreach ( self::by_product( $issues ) as $id => $list ) {
			unset( $queued[ $id ] );
			$queued[ $id ] = $list;
		}
		if ( count( $queued ) > self::MAX_QUEUED ) {
			$queued = array_slice( $queued, -self::MAX_QUEUED, null, true );
		}
		update_option( self::OPTION_QUEUE, $queued, false );
		self::remember( array(), $issues, null );
	}

	/** Issues grouped by external id. Pure. */
	public static function by_product( array $issues ) {
		$out = array();
		foreach ( $issues as $issue ) {
			$out[ (string) $issue['externalId'] ][] = $issue;
		}
		return $out;
	}

	/** Drops what is waiting for these products: a newer push carries their current state. */
	public static function unqueue( array $external_ids ) {
		$queued = self::queued_map();
		$before = count( $queued );
		foreach ( $external_ids as $id ) {
			unset( $queued[ (string) $id ] );
		}
		if ( count( $queued ) !== $before ) {
			self::store_queue( $queued );
		}
	}

	/** Waiting issues, oldest first, at most one request's worth: [external id => issues]. */
	public static function queued( $limit = self::MAX_PER_REQUEST ) {
		$out   = array();
		$count = 0;
		if ( $limit <= 0 ) {
			return $out;
		}
		foreach ( self::queued_map() as $id => $list ) {
			if ( $count > 0 && $count + count( $list ) > $limit ) {
				break;
			}
			$out[ $id ] = $list;
			$count     += count( $list );
		}
		return $out;
	}

	/** The issues of queued() as one list. Pure. */
	public static function flat( array $by_product ) {
		$out = array();
		foreach ( $by_product as $list ) {
			foreach ( $list as $issue ) {
				$out[] = $issue;
			}
		}
		return $out;
	}

	/** Removes what was sent, unless a newer list for the product came in meanwhile. */
	public static function sent( array $by_product ) {
		$queued = self::queued_map();
		foreach ( $by_product as $id => $list ) {
			if ( isset( $queued[ $id ] ) && $queued[ $id ] == $list ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- arrays compared by content.
				unset( $queued[ $id ] );
			}
		}
		self::store_queue( $queued );
	}

	/**
	 * Sends waiting issues on their own (from the heartbeat): one request of at most
	 * MAX_PER_REQUEST, no products (sudemi removes no listing for that). Kept on failure; dropped
	 * when sudemi refuses them for good (the next full sync sends them again).
	 *
	 * @return bool Whether something was sent.
	 */
	public static function drain() {
		$batch = self::queued();
		if ( empty( $batch ) ) {
			return false;
		}
		$issues = self::flat( $batch );
		$answer = Sudemi_Client::post_signed( '/integration/v1/products', self::attach( array( 'products' => array() ), $issues ) );
		if ( ! is_wp_error( $answer ) && 200 === $answer['status'] ) {
			self::sent( $batch );
			self::remember( array(), $issues, isset( $answer['body'] ) ? $answer['body'] : null );
			return true;
		}
		if ( ! is_wp_error( $answer ) && in_array( (int) $answer['status'], array( 400, 413, 422 ), true ) ) {
			self::sent( $batch );
			Sudemi_Logger::warn( 'sudemi refused catalogue issues (HTTP ' . (int) $answer['status'] . '); the next full sync sends them again.' );
		}
		return false;
	}

	public static function forget_queue() {
		delete_option( self::OPTION_QUEUE );
	}

	private static function queued_map() {
		$queued = get_option( self::OPTION_QUEUE, array() );
		return is_array( $queued ) ? $queued : array();
	}

	private static function store_queue( array $queued ) {
		if ( empty( $queued ) ) {
			delete_option( self::OPTION_QUEUE );
		} else {
			update_option( self::OPTION_QUEUE, $queued, false );
		}
	}

	/** The code for a reason a field was left out (Sudemi_Addons::REASON_*). Pure. */
	public static function code_for_reason( $reason ) {
		$map = array(
			Sudemi_Addons::REASON_DISCOUNT => self::ADDON_NEGATIVE_PRICE,
			Sudemi_Addons::REASON_FLAT     => self::ADDON_FLAT_FEE,
			Sudemi_Addons::REASON_PRICING  => self::ADDON_PER_CHARACTER_PRICE,
			Sudemi_Addons::REASON_PLUGIN   => self::ADDON_UNSUPPORTED_PLUGIN,
			'sudemi asks at most five'     => self::TOO_MANY_OPTIONAL_TEXTS,
		);
		return isset( $map[ $reason ] ) ? $map[ $reason ] : self::UNREADABLE_ADDON;
	}

	/**
	 * The body of a push with its issues (only when there are any: the field is optional, and
	 * sudemi ignores a field it does not know). Pure.
	 */
	public static function attach( array $body, array $issues ) {
		if ( ! empty( $issues ) ) {
			$body['issues'] = array_values( $issues );
		}
		return $body;
	}

	/**
	 * Keeps what was sent and what sudemi answered, per listing, for the admin page: the entries
	 * of the products in this push are replaced; a full sync starts the list afresh.
	 *
	 * @param array      $listings The listings sent (their external ids).
	 * @param array      $issues   The issues sent.
	 * @param array|null $answer   sudemi's answer body (rejected[], issues[]), or null.
	 * @param bool       $fresh    The first push of a full sync.
	 */
	public static function remember( array $listings, array $issues, $answer, $fresh = false ) {
		$kept = $fresh ? array() : get_option( self::OPTION, array() );
		$kept = self::merge( is_array( $kept ) ? $kept : array(), $listings, $issues, is_array( $answer ) ? $answer : array(), time() );
		update_option( self::OPTION, $kept, false );
	}

	/** The kept list after one push. Pure. */
	public static function merge( array $kept, array $listings, array $issues, array $answer, $now ) {
		$touched = array();
		foreach ( $listings as $listing ) {
			if ( isset( $listing['externalId'] ) ) {
				$touched[ (string) $listing['externalId'] ] = true;
			}
		}
		foreach ( $issues as $issue ) {
			$touched[ (string) $issue['externalId'] ] = true;
		}
		$kept = array_values(
			array_filter(
				$kept,
				function ( $entry ) use ( $touched ) {
					return ! isset( $touched[ (string) $entry['externalId'] ] );
				}
			)
		);
		foreach ( $issues as $issue ) {
			$kept[] = array(
				'externalId' => (string) $issue['externalId'],
				'source'     => 'plugin',
				'code'       => (string) $issue['code'],
				'severity'   => (string) $issue['severity'],
				'field'      => (string) $issue['field'],
				'message'    => (string) $issue['message'],
				'at'         => (int) $now,
			);
		}
		foreach ( array( 'rejected', 'issues' ) as $list ) {
			foreach ( isset( $answer[ $list ] ) && is_array( $answer[ $list ] ) ? $answer[ $list ] : array() as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['externalId'] ) ) {
					continue;
				}
				$kept[] = array(
					'externalId' => (string) $item['externalId'],
					'source'     => 'sudemi',
					'code'       => isset( $item['code'] ) ? (string) $item['code'] : ( 'rejected' === $list ? 'rejected' : 'issue' ),
					'severity'   => 'rejected' === $list ? 'error' : ( isset( $item['severity'] ) ? (string) $item['severity'] : 'warning' ),
					'field'      => isset( $item['field'] ) ? (string) $item['field'] : '',
					'message'    => isset( $item['reason'] ) ? (string) $item['reason'] : ( isset( $item['message'] ) ? (string) $item['message'] : '' ),
					'at'         => (int) $now,
				);
			}
		}
		return array_slice( $kept, -self::MAX_KEPT );
	}

	/** What the shop sees: the kept entries. */
	public static function all() {
		$kept = get_option( self::OPTION, array() );
		return is_array( $kept ) ? $kept : array();
	}

	/** The plain words for a code, in the shop's language. */
	public static function label( $code ) {
		switch ( (string) $code ) {
			case self::ADDON_NEGATIVE_PRICE:
				return __( 'An add-on lowers the price (a discount); sudemi cannot charge that yet, so it is not offered.', 'sudemi-connector' );
			case self::ADDON_FLAT_FEE:
				return __( 'An add-on is charged once per order line (a flat fee); sudemi cannot charge that yet, so it is not offered.', 'sudemi-connector' );
			case self::ADDON_PER_CHARACTER_PRICE:
			case self::ADDON_FORMULA_PRICE:
				return __( 'An add-on is priced per character or by a formula; sudemi cannot charge that yet, so it is not offered.', 'sudemi-connector' );
			case self::ADDON_UNSUPPORTED_PLUGIN:
				return __( 'The product uses an add-on plugin sudemi cannot read; its options are not offered.', 'sudemi-connector' );
			case self::TOO_MANY_REQUIRED_TEXTS:
				return __( 'The product needs more than five texts or choices from the buyer; sudemi can ask five, so it is not listed.', 'sudemi-connector' );
			case self::TOO_MANY_OPTIONAL_TEXTS:
				return __( 'sudemi asks the buyer at most five texts or choices; an optional one is not asked.', 'sudemi-connector' );
			case self::VARIANT_CAP:
				return __( 'The product has more combinations than sudemi lists; only the first are listed.', 'sudemi-connector' );
			case self::MISSING_PRICE:
				return __( 'The product has no price, so it is not listed.', 'sudemi-connector' );
			case self::NOT_ORDERABLE:
				return __( 'A required add-on cannot be offered on sudemi, so the product is not listed.', 'sudemi-connector' );
			case 'rejected':
				return __( 'sudemi refused this product.', 'sudemi-connector' );
		}
		return __( 'Something about this product could not be sent to sudemi.', 'sudemi-connector' );
	}
}
