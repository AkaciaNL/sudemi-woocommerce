<?php
/**
 * Turning this shop's catalogue into what sudemi stores.
 *
 * Two paths, for two different costs. A **full sync** walks every published product and ends
 * with a commit, which is the only thing that may delist: a sync that dies halfway through is
 * simply never committed, and nothing disappears from the shop's listings because a cron job
 * timed out. A **delta** carries a price or a stock change for one product and runs from
 * WooCommerce's own save hook, which is what makes the shop's prices and sudemi's agree within
 * seconds rather than within an import cycle.
 *
 * @package Sudemi
 */

defined( 'ABSPATH' ) || exit;

final class Sudemi_Sync {

	const CRON_FULL   = 'sudemi_full_sync';
	const BATCH_SIZE  = 100;

	public static function boot() {
		add_action( self::CRON_FULL, array( __CLASS__, 'cron_full' ), 10, 1 );
		// Price and stock, as they change. Guarded inside so an unconnected shop does nothing.
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_changed' ), 10, 1 );
		// Shipping prices come from the shipping zones: a change there re-sends the catalogue.
		foreach ( array( 'woocommerce_after_shipping_zone_object_save', 'woocommerce_shipping_zone_method_added', 'woocommerce_shipping_zone_method_deleted', 'woocommerce_shipping_zone_method_status_toggled', 'woocommerce_update_options_shipping' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'schedule_soon' ) );
		}
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_product_object_changed' ), 10, 1 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_product_changed' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_product_object_changed' ), 10, 1 );
		// A listing gone from the shop (unpublished, trashed, deleted) stays on sudemi until a
		// full sync's commit removes it; see note_removal().
		add_action( 'transition_post_status', array( __CLASS__, 'on_status_changed' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_deleted' ), 10, 1 );
	}

	/** A full sync shortly, once, however many changes trigger it. */
	public static function schedule_soon() {
		if ( ! wp_next_scheduled( self::CRON_FULL ) || wp_next_scheduled( self::CRON_FULL ) > time() + 120 ) {
			wp_schedule_single_event( time() + 60, self::CRON_FULL );
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_FULL ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_FULL );
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_FULL );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_FULL );
			$timestamp = wp_next_scheduled( self::CRON_FULL );
		}
		// Follow-up steps of a sync that ran inside page requests (see cron_full()).
		wp_clear_scheduled_hook( self::CRON_FULL, array( 'continue' ) );
	}

	/** The full sync in progress: its id, where it stopped, the revision it started from. */
	const OPTION_STATE = 'sudemi_sync_state';

	/** A cron run inside a visitor's page request sends at most this many products / seconds. */
	const STEP_PRODUCTS = 25;
	const STEP_SECONDS  = 2;

	/** sudemi drops an uncommitted sync after 6 hours; one older than this is started again. */
	const MAX_SYNC_AGE = 19800;

	/**
	 * The full-sync event. WP-Cron normally runs it in its own background request (wp-cron.php),
	 * where the whole sync runs. With ALTERNATE_WP_CRON, WordPress runs due events inside the
	 * visitor's own page request; a full sync there kept that PHP request busy for minutes, so
	 * then only a small step is sent and the rest is scheduled as a follow-up event.
	 *
	 * @param string|null $mode 'continue' for a follow-up event: it never starts a new sync.
	 */
	public static function cron_full( $mode = null ) {
		$inline = self::inside_page_request();
		$result = self::work( $inline ? self::STEP_PRODUCTS : 0, $inline ? self::STEP_SECONDS : 0, 'continue' !== $mode );
		if ( 'more' === $result ) {
			wp_schedule_single_event( time(), self::CRON_FULL, array( 'continue' ) );
		}
	}

	/** Whether this cron run is inside a visitor's page request (ALTERNATE_WP_CRON). */
	private static function inside_page_request() {
		$inline = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& ( empty( $_SERVER['SCRIPT_FILENAME'] ) || 'wp-cron.php' !== basename( (string) $_SERVER['SCRIPT_FILENAME'] ) );
		/**
		 * Whether the full sync is running inside a page request, and so sends only a step.
		 *
		 * @param bool $inline What the plugin determined.
		 */
		return (bool) apply_filters( 'sudemi_full_sync_in_page_request', $inline );
	}

	/** A whole full sync, now: for WP-CLI and scripts. Continues one already in progress. */
	public static function run_full() {
		do {
			$result = self::work( 0, 0, true );
		} while ( 'more' === $result );
		return 'done' === $result;
	}

	/**
	 * Sends the full sync in progress, or starts one, for at most $max_products products and
	 * $max_seconds seconds (0 = to the end), and remembers where it stopped. A MySQL named lock
	 * keeps two requests from sending it at once.
	 *
	 * @return string 'idle', 'busy', 'more', 'done', 'held' or 'failed'.
	 */
	private static function work( $max_products, $max_seconds, $start_new ) {
		if ( ! Sudemi_Client::is_connected() ) {
			return 'idle';
		}
		if ( ! self::lock() ) {
			return 'busy';
		}
		try {
			$state = self::state();
			if ( null !== $state && time() - (int) $state['started'] > self::MAX_SYNC_AGE ) {
				Sudemi_Logger::warn( 'An unfinished catalogue sync was too old and is started again.' );
				delete_option( self::OPTION_STATE );
				$state     = null;
				$start_new = true;
			}
			if ( null === $state ) {
				if ( ! $start_new ) {
					return 'idle';
				}
				$state = self::start();
				if ( null === $state ) {
					return 'failed';
				}
			}
			return self::advance( $state, (int) $max_products, (int) $max_seconds );
		} finally {
			self::unlock();
		}
	}

	/** Opens a sync on sudemi and sends the shop and its categories; null when sudemi refused. */
	private static function start() {
		$started = Sudemi_Client::post_signed( '/integration/v1/sync', array() );
		if ( is_wp_error( $started ) || 200 !== $started['status'] || empty( $started['body']['sync_id'] ) ) {
			Sudemi_Logger::warn( 'Could not start a catalogue sync.' );
			return null;
		}
		$state = array(
			'id'       => (string) $started['body']['sync_id'],
			// What the catalogue was when this sync started reading it. Sent with the commit and
			// in a heartbeat right after it, so sudemi holds the revision this sync describes;
			// see Sudemi_Heartbeat::anchor().
			'anchor'   => Sudemi_Heartbeat::revision(),
			// Listings removed in the shop so far; this sync's commit takes them off sudemi.
			'removals' => self::removals(),
			'cursor'   => 0,
			'sent'     => 0,
			'started'  => time(),
		);
		update_option( self::OPTION_STATE, $state, false );
		self::push_shop();
		self::push_categories();
		return $state;
	}

	/** Sends products after the cursor, page by page, then commits; see work(). */
	private static function advance( array $state, $max_products, $max_seconds ) {
		$began = microtime( true );
		$done  = 0;
		while ( true ) {
			$size = $max_products > 0 ? min( self::BATCH_SIZE, $max_products - $done ) : self::BATCH_SIZE;
			$ids  = self::product_ids_after( (int) $state['cursor'], $size );
			if ( empty( $ids ) ) {
				return self::commit( $state );
			}
			$products = array();
			foreach ( $ids as $id ) {
				$payload = self::product_payload( $id );
				if ( null !== $payload ) {
					$products[] = $payload;
				}
			}
			if ( ! empty( $products ) ) {
				$sent = Sudemi_Client::post_signed(
					'/integration/v1/products',
					array(
						'syncId'   => $state['id'],
						'products' => $products,
					)
				);
				if ( is_wp_error( $sent ) || 200 !== $sent['status'] ) {
					return self::failed( $sent, 'A batch failed' );
				}
			}
			// Moved on only once the page is in: a step stopped halfway sends it again.
			$state['cursor'] = (int) end( $ids );
			$state['sent']   = (int) $state['sent'] + count( $products );
			update_option( self::OPTION_STATE, $state, false );
			$done += count( $ids );
			if ( ( $max_products > 0 && $done >= $max_products ) || ( $max_seconds > 0 && microtime( true ) - $began >= $max_seconds ) ) {
				return 'more';
			}
		}
	}

	private static function commit( array $state ) {
		// The commit carries the revision the sync started from: sudemi adopts it, so anything
		// changed while the sync ran makes the next heartbeat disagree and is synced next time.
		$anchor    = isset( $state['anchor'] ) ? $state['anchor'] : null;
		$committed = Sudemi_Client::post_signed(
			'/integration/v1/sync/' . rawurlencode( (string) $state['id'] ) . '/commit',
			null === $anchor ? array() : array( 'catalogueRevision' => $anchor )
		);
		if ( is_wp_error( $committed ) || 200 !== $committed['status'] ) {
			return self::failed( $committed, 'The catalogue was sent but the sync could not be committed' );
		}
		delete_option( self::OPTION_STATE );
		if ( isset( $committed['body']['status'] ) && 'held' === $committed['body']['status'] ) {
			// Not applied: it would take a large share of this shop's live products off sudemi,
			// which sudemi does not do without a person confirming it.
			Sudemi_Logger::warn(
				sprintf(
					/* translators: 1: products that would be removed, 2: products listed now. */
					__( 'The catalogue sync is on hold: it would remove %1$d of %2$d listed products from sudemi, so nothing was changed. If those products really were removed from this shop, contact sudemi support (support@sudemi.com).', 'sudemi' ),
					isset( $committed['body']['would_delist'] ) ? (int) $committed['body']['would_delist'] : 0,
					isset( $committed['body']['live_products'] ) ? (int) $committed['body']['live_products'] : 0
				)
			);
			return 'held';
		}
		if ( (int) $state['removals'] > (int) get_option( self::OPTION_REMOVALS_SYNCED, 0 ) ) {
			update_option( self::OPTION_REMOVALS_SYNCED, (int) $state['removals'], false );
		}
		Sudemi_Heartbeat::anchor( $anchor );
		/* translators: %d: number of products. */
		Sudemi_Logger::info( sprintf( __( 'Synced %d products.', 'sudemi' ), (int) $state['sent'] ) );
		return 'done';
	}

	/**
	 * A request of the sync that did not get through. Unanswered, throttled or a server error:
	 * kept for the next run. Refused (the sync expired or closed, the shop is suspended):
	 * abandoned, which delists nothing.
	 */
	private static function failed( $response, $what ) {
		$status = is_wp_error( $response ) ? 0 : (int) $response['status'];
		if ( 0 === $status || 429 === $status || $status >= 500 ) {
			Sudemi_Logger::warn( $what . '; it is retried on the next run.' );
			return 'failed';
		}
		delete_option( self::OPTION_STATE );
		Sudemi_Logger::warn( $what . ' (HTTP ' . $status . '); the sync was abandoned and nothing was delisted.' );
		return 'failed';
	}

	/** The state as the database holds it now (another request may have moved it on). */
	private static function state() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_STATE ) );
		$state = null === $raw ? null : maybe_unserialize( $raw );
		return is_array( $state ) && ! empty( $state['id'] ) ? $state : null;
	}

	/** Whether a full sync was started and has not finished. */
	public static function in_progress() {
		return null !== self::state();
	}

	private static function lock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) );
	}

	private static function unlock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}

	private static function lock_name() {
		global $wpdb;
		return 'sudemi_sync_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 16 );
	}

	public static function on_product_changed( $product_id ) {
		if ( ! Sudemi_Client::is_connected() ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		self::defer( $product );
	}

	public static function on_product_object_changed( $product ) {
		if ( ! Sudemi_Client::is_connected() || ! $product ) {
			return;
		}
		self::defer( $product );
	}

	/** A product or variation that was published is not any more (draft, private, trash). */
	public static function on_status_changed( $new_status, $old_status, $post ) {
		if ( 'publish' === $old_status && 'publish' !== $new_status && isset( $post->post_type )
			&& in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			self::note_removal();
		}
	}

	public static function on_post_deleted( $post_id ) {
		if ( in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			self::note_removal();
		}
	}

	/** Products changed in this request, pushed once when it ends; see defer(). */
	private static $changed = array();

	/**
	 * Queues a changed product (or the variable product a variation belongs to) for a push when
	 * this request ends.
	 *
	 * Once per product and at the end, not from each hook: saving a variable product saves every
	 * variation and then the parent (WooCommerce syncs the parent on shutdown too), and the
	 * catalogue revision a push carries has to be the one that holds after all of them.
	 */
	private static function defer( $product ) {
		$family = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		if ( $family <= 0 ) {
			return;
		}
		// Counted now, not after a push: the counter is what lets sudemi notice a change that
		// never arrived, so it has to move even when the push fails or never runs.
		Sudemi_Heartbeat::note_change();
		if ( empty( self::$changed ) ) {
			// After WooCommerce's own deferred parent sync (shutdown, priority 10).
			add_action( 'shutdown', array( __CLASS__, 'push_changed' ), 100 );
		}
		self::$changed[ $family ] = true;
	}

	/** Sends what defer() collected. Runs when the request ends. */
	public static function push_changed() {
		$ids           = array_keys( self::$changed );
		self::$changed = array();
		foreach ( $ids as $id ) {
			try {
				self::push_delta( $id );
			} catch ( \Throwable $e ) {
				Sudemi_Logger::warn( 'A product change could not be sent: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * One product's listings, on their way out now rather than at the next import: a simple
	 * product's own, or every variation of a variable product. Sent whole, they can carry the
	 * catalogue revision (see push_listings).
	 *
	 * This is the call that decides whether sudemi's prices are true, so the failure path matters
	 * more than the success path. It gets one attempt and a short timeout, because it runs while
	 * someone is saving a product in wp-admin and a sudemi outage must not slow that screen or
	 * put an error on it. What it must not do is give up: a change that is dropped here is a
	 * wrong price on a marketplace until the next full sync. So a failure is written to the queue
	 * and the heartbeat sends it a few minutes later.
	 */
	private static function push_delta( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			// Deleted while this request ran: its listings wait for a full sync to remove them.
			self::note_removal();
			return;
		}

		if ( $product->is_type( 'variable' ) ) {
			// Every variation, sellable or not: one that is not (disabled, no price, its parent
			// hidden) has no listing, and if it had one, only a full sync can remove it.
			$listings = array();
			foreach ( $product->get_children() as $child_id ) {
				$listing = self::product_payload( $child_id );
				self::mark_listed( $child_id, null !== $listing );
				if ( null !== $listing ) {
					$listings[] = $listing;
				}
			}
			if ( ! empty( $listings ) ) {
				self::push_listings( $listings );
			}
			// Without a sellable variation nothing is sent; the change counter already moved,
			// so the next heartbeat disagrees and sudemi asks for a full sync.
			return;
		}

		if ( $product->is_type( 'simple' ) ) {
			// A product that is no longer sellable (hidden, a draft) has no listing: its removal
			// is a full sync's job.
			$listing = 'publish' === $product->get_status() ? self::product_payload( $product->get_id() ) : null;
			self::mark_listed( $product->get_id(), null !== $listing );
			if ( null !== $listing ) {
				self::push_listings( array( $listing ) );
				return;
			}
		}

		$update = array(
			'externalId' => (string) $product->get_id(),
			'priceMinor' => self::minor( self::price_with_tax( $product ) ),
			'currency'   => get_woocommerce_currency(),
			'inStock'    => (bool) $product->is_in_stock(),
			'quantity'   => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
		);

		$response = Sudemi_Client::post_signed(
			'/integration/v1/products/prices',
			array( 'updates' => array( $update ) )
		);

		if ( is_wp_error( $response ) || 200 !== $response['status'] ) {
			Sudemi_Queue::enqueue( $update );
			Sudemi_Logger::warn( 'A price update could not be sent; it is queued for retry.' );
			return;
		}

		// Accepted, but for a product sudemi has never been sent. The full sync it asks for is
		// scheduled rather than run here, for the same reason as above.
		if ( ! empty( $response['body']['full_sync_requested'] ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_FULL );
		}
	}

	/** Listings removed in the shop, counted, and the count the last committed full sync covered. */
	const OPTION_REMOVALS        = 'sudemi_removals';
	const OPTION_REMOVALS_SYNCED = 'sudemi_removals_synced';

	/** Set on a product or variation found without a listing, so it is counted as removed once. */
	const META_UNLISTED = '_sudemi_unlisted';

	/**
	 * A listing stopped being listed here. Only a full sync's commit can take a listing off
	 * sudemi, so until one has, no push carries the catalogue revision: sudemi would adopt it
	 * and the gone listing would stay listed.
	 */
	public static function note_removal() {
		update_option( self::OPTION_REMOVALS, self::removals() + 1, false );
	}

	private static function removals() {
		return (int) get_option( self::OPTION_REMOVALS, 0 );
	}

	private static function removals_synced() {
		return self::removals() <= (int) get_option( self::OPTION_REMOVALS_SYNCED, 0 );
	}

	/** Remembers whether a product has a listing; losing it counts as a removal, once. */
	private static function mark_listed( $id, $listed ) {
		$unlisted = (bool) get_post_meta( $id, self::META_UNLISTED, true );
		if ( $listed && $unlisted ) {
			delete_post_meta( $id, self::META_UNLISTED );
		} elseif ( ! $listed && ! $unlisted ) {
			self::note_removal();
			update_post_meta( $id, self::META_UNLISTED, 1 );
		}
	}

	/**
	 * Sends one product's listings (a simple product's own, or every variation) as a change push,
	 * with the catalogue revision when nothing else is waiting to be sent.
	 *
	 * A price push carries price and stock and nothing else, so sending the revision with it
	 * would tell sudemi a renamed product or a changed description had arrived when it had not.
	 * The whole listings carry everything about the product.
	 *
	 * The revision is computed here, after WooCommerce has saved the change and after the change
	 * counter moved, exactly as the next heartbeat computes it, and only sent when: no price
	 * change is queued, no listing removed in the shop is still waiting for a full sync to take
	 * it off sudemi, the category tree sudemi holds is current, and every earlier request of this
	 * push was accepted whole. If every item applied, sudemi adopts it (catalogue_revision_agreed)
	 * and no full sync follows the edit; otherwise the heartbeat's disagreement asks for one.
	 */
	private static function push_listings( array $listings ) {
		foreach ( $listings as $listing ) {
			// This push carries the newest state of the product; an older queued price for it
			// would otherwise be sent after it and put the old price back.
			Sudemi_Queue::discard( $listing['externalId'] );
		}

		$batches = array_chunk( $listings, 500 );
		$clean   = true;
		$last    = count( $batches ) - 1;
		foreach ( $batches as $i => $batch ) {
			$body = array( 'products' => $batch );
			// A product moved into a category sudemi has not been sent would get past sudemi's
			// category checks until a full sync, which an agreed revision makes rare. So the
			// category tree is sent first when it changed, and without it there is no revision.
			if ( $i === $last && $clean && 0 === Sudemi_Queue::count() && self::removals_synced() && self::categories_current() ) {
				$revision = Sudemi_Heartbeat::revision();
				if ( null !== $revision ) {
					$body['catalogueRevision'] = $revision;
				}
			}

			$response = Sudemi_Client::post_signed( '/integration/v1/products', $body );
			if ( is_wp_error( $response ) || 200 !== $response['status'] ) {
				$clean = false;
				foreach ( $batch as $listing ) {
					Sudemi_Queue::enqueue(
						array(
							'externalId' => $listing['externalId'],
							'priceMinor' => $listing['priceMinor'],
							'currency'   => $listing['currency'],
							'inStock'    => $listing['inStock'],
							'quantity'   => $listing['quantity'],
						)
					);
				}
				Sudemi_Logger::warn( 'A price update could not be sent; it is queued for retry.' );
			} elseif ( ! empty( $response['body']['rejected'] ) ) {
				$clean = false;
			}
		}
	}

	/** What the category tree sent to sudemi is (a hash of it), so a change can be noticed. */
	const OPTION_CATEGORIES = 'sudemi_categories_sent';

	/** Whether sudemi holds this shop's category tree as it is now; sends it when not. */
	private static function categories_current() {
		$categories = self::categories();
		if ( null === $categories ) {
			return false;
		}
		if ( get_option( self::OPTION_CATEGORIES ) === self::categories_hash( $categories ) ) {
			return true;
		}
		return self::send_categories( $categories );
	}

	/** Tied to the connection: a shop connected again has sent its new connection nothing. */
	private static function categories_hash( array $categories ) {
		return hash( 'sha256', (string) Sudemi_Client::install_id() . '|' . wp_json_encode( $categories ) );
	}

	private static function send_categories( array $categories ) {
		if ( empty( $categories ) ) {
			// sudemi takes at least one; a shop without categories has nothing to be out of date.
			return true;
		}
		$response = Sudemi_Client::post_signed( '/integration/v1/categories', array( 'categories' => $categories ) );
		if ( is_wp_error( $response ) || 200 !== $response['status'] ) {
			return false;
		}
		update_option( self::OPTION_CATEGORIES, self::categories_hash( $categories ), false );
		return true;
	}

	public static function forget() {
		delete_option( self::OPTION_CATEGORIES );
		delete_option( self::OPTION_STATE );
		delete_option( self::OPTION_REMOVALS );
		delete_option( self::OPTION_REMOVALS_SYNCED );
	}

	private static function push_shop() {
		Sudemi_Client::post_signed(
			'/integration/v1/shop',
			array(
				'displayName'   => get_bloginfo( 'name' ),
				'description'   => get_bloginfo( 'description' ),
				'languages'     => array( substr( get_locale(), 0, 2 ) ),
				'currency'      => get_woocommerce_currency(),
				'supportEmail'  => get_option( 'woocommerce_email_from_address', get_option( 'admin_email' ) ),
				'shipsTo'       => self::shipping_countries(),
			)
		);
	}

	private static function push_categories() {
		$categories = self::categories();
		if ( null !== $categories ) {
			self::send_categories( $categories );
		}
	}

	/** The category tree as sudemi takes it, or null when WordPress cannot read it. */
	private static function categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'term_id',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return null;
		}
		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'externalId'       => (string) $term->term_id,
				'parentExternalId' => $term->parent ? (string) $term->parent : null,
				'name'             => $term->name,
				'description'      => $term->description,
			);
		}
		return $categories;
	}

	/**
	 * Published products and variations after $after, in id order. Variations are listings of
	 * their own (size, colour...): a buyer buys one of them, never the variable "parent", which
	 * has no single price, stock or barcode (product_payload() skips it).
	 */
	private static function product_ids_after( $after, $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation') AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
				(int) $after,
				max( 1, (int) $limit )
			)
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * One product, as sudemi stores it.
	 *
	 * Images are sent as URLs on this shop's own origin, never as bytes: sudemi fetches them
	 * through its egress guard and refuses anything that is not on the verified shop, which is
	 * what stops a payload walking it around an internal network one image at a time.
	 */
	private static function product_payload( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}
		// Not sellable as one item through sudemi: a variable parent (its variations are sent
		// instead), a grouped product (a set of other products) and an external/affiliate link.
		if ( $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
			return null;
		}
		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
		if ( $parent ) {
			if ( ! $parent->is_visible() || 'publish' !== $parent->get_status() || ! $product->variation_is_visible() ) {
				return null;
			}
		} elseif ( ! $product->is_visible() ) {
			return null;
		}
		// Categories, brand and the shared attributes live on the parent of a variation.
		$base_id = $parent ? $parent->get_id() : $product_id;
		$base    = $parent ? $parent : $product;

		$images = array();
		$image_ids = array_merge(
			array( $product->get_image_id() ? $product->get_image_id() : $base->get_image_id() ),
			$base->get_gallery_image_ids()
		);
		foreach ( array_filter( $image_ids ) as $image_id ) {
			$url = wp_get_attachment_url( $image_id );
			if ( $url ) {
				$images[] = $url;
			}
		}

		$categories = wp_get_post_terms( $base_id, 'product_cat', array( 'fields' => 'ids' ) );

		$specs = array();
		if ( $parent ) {
			// This variation's own choices (Colour: Red, Size: L) first.
			foreach ( $product->get_variation_attributes( false ) as $taxonomy => $value ) {
				if ( '' === $value ) {
					continue;
				}
				$term = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : null;
				$specs[] = array(
					'name'   => wc_attribute_label( $taxonomy, $parent ),
					'values' => array( $term ? $term->name : (string) $value ),
				);
			}
		}
		foreach ( $base->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ( $parent && $attribute->get_variation() ) ) {
				continue;
			}
			$name = wc_attribute_label( $attribute->get_name() );
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $base_id, $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			if ( ! empty( $values ) ) {
				$specs[] = array(
					'name'   => $name,
					'values' => array_values( array_map( 'strval', $values ) ),
				);
			}
		}

		$payload = array(
			'externalId'  => (string) $product->get_id(),
			'sku'         => $product->get_sku(),
			'title'       => self::title( $product, $parent ),
			'description' => wp_strip_all_tags( $product->get_description() ? $product->get_description() : $base->get_description() ),
			'url'         => $product->get_permalink(),
			'priceMinor'  => self::minor( self::price_with_tax( $product ) ),
			'currency'    => get_woocommerce_currency(),
			'inStock'     => (bool) $product->is_in_stock(),
			'quantity'    => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			'weightGrams' => self::weight_grams( $product->get_weight() ),
			'categoryExternalIds' => array_map( 'strval', is_array( $categories ) ? $categories : array() ),
			'images'      => $images,
			'specs'       => $specs,
			// Without a shipping price for the buyer's country sudemi cannot offer the product.
			'shipping'    => Sudemi_Shipping::rates_for( $product ),
			'maxHandlingTimeDays' => Sudemi_Shipping::handling_days(),
			'maxTransitTimeDays'  => Sudemi_Shipping::transit_days(),
			// WooCommerce's own barcode field (GTIN/EAN/UPC/ISBN, WooCommerce 9.2+): lets sudemi
			// show this shop's offer next to other shops selling the same item.
			'gtin'        => self::gtin( $product ),
			'brand'       => self::brand( $base_id ),
		);
		// Excluded from the right of withdrawal (EU 2011/83 art. 16), with the reason; a
		// variation carries its parent's setting. Omitted when the normal right applies.
		return Sudemi_Withdrawal::apply( $payload, Sudemi_Withdrawal::for_product( $base_id ) );
	}

	/** A variation's title names its options ("T-shirt - Red, L"), so each size reads as itself. */
	private static function title( $product, $parent ) {
		$name = $product->get_name();
		if ( ! $parent || $name !== $parent->get_name() ) {
			return $name;
		}
		$values = array();
		foreach ( $product->get_variation_attributes( false ) as $taxonomy => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : null;
			$values[] = $term ? $term->name : (string) $value;
		}
		return empty( $values ) ? $name : $name . ' - ' . implode( ', ', $values );
	}

	private static function gtin( $product ) {
		if ( ! is_callable( array( $product, 'get_global_unique_id' ) ) ) {
			return null;
		}
		$value = preg_replace( '/[^0-9]/', '', (string) $product->get_global_unique_id() );
		return '' === $value ? null : $value;
	}

	/** WooCommerce's brand taxonomy (9.6+), when the shop uses it. */
	private static function brand( $product_id ) {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return null;
		}
		$names = wp_get_post_terms( $product_id, 'product_brand', array( 'fields' => 'names' ) );
		return is_array( $names ) && ! empty( $names ) ? (string) $names[0] : null;
	}

	/**
	 * The price a buyer pays, with VAT. Shops that enter prices without tax ("prices exclusive of
	 * tax") let WooCommerce add it at checkout; sending get_price() as-is sold their products on
	 * sudemi without the VAT. wc_get_price_including_tax() gives the price for the shop's own
	 * country, as the storefront shows it; for shops that enter prices with tax it is unchanged.
	 */
	private static function price_with_tax( $product ) {
		$price = $product->get_price();
		if ( '' === $price || null === $price ) {
			return $price;
		}
		return wc_get_price_including_tax( $product, array( 'qty' => 1, 'price' => $price ) );
	}

	/** Money in minor units, because a float price is a rounding error waiting for a checkout. */
	private static function minor( $price ) {
		if ( '' === $price || null === $price ) {
			return null;
		}
		return (int) round( (float) $price * 100 );
	}

	private static function weight_grams( $weight ) {
		if ( '' === $weight || null === $weight ) {
			return null;
		}
		$unit   = get_option( 'woocommerce_weight_unit', 'kg' );
		$factor = array(
			'kg'  => 1000,
			'g'   => 1,
			'lbs' => 453.59237,
			'oz'  => 28.349523125,
		);
		$multiplier = isset( $factor[ $unit ] ) ? $factor[ $unit ] : 1000;
		return (int) round( (float) $weight * $multiplier );
	}

	private static function shipping_countries() {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return array();
		}
		$countries = WC()->countries->get_shipping_countries();
		return is_array( $countries ) ? array_keys( $countries ) : array();
	}
}
