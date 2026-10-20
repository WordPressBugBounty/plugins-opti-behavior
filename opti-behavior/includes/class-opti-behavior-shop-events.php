<?php
/**
 * Shop events: daily counters per product and event type (WooCommerce).
 *
 * One row per day x product x event type x code, counted on the server by the
 * WooCommerce hooks (add to cart, removal, coupons, failed payment, paid order)
 * and, for product views and checkout starts, by the public tracker (works
 * with a page cache). Aggregates only: no visitor, no session, no order
 * number, no customer, no e-mail, no address, no coupon code, never the text
 * of a payment notice (legal review OPT-1187, 2026-10-14). Pro links events to
 * a session through the `opti_behavior_shop_event` action (Full Tracking with
 * consent only, Pro side).
 *
 * Nothing is hooked when WooCommerce is not active or when the site owner
 * switched shop tracking off (Settings > Traffic & Behavior).
 *
 * @package Opti_Behavior
 * @since   1.10.0.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shop event counters.
 */
class Opti_Behavior_Shop_Events {

	/** Table name without the WordPress prefix. */
	const TABLE = 'optibehavior_shop_daily';

	/** Schema version of the table; bump on any schema change. */
	const DB_VERSION = '1';

	/** Option holding the installed schema version (autoloaded: read on every event). */
	const DB_VERSION_OPTION = 'opti_behavior_shop_db_version';

	/** On / off switch of the site owner ('1' default, '0' = off). */
	const OPTION = 'opti_behavior_shop_tracking';

	/** admin-post action of the settings block. */
	const ACTION_NAME = 'opti_behavior_shop_tracking';

	/** Query argument carrying the result of the settings save. */
	const RESULT_ARG = 'ob_shop_tracking';

	/** Order meta: the paid order was counted once (a flag, no number is stored by us). */
	const COUNTED_META = '_ob_shop_counted';

	/** Longest stored code. */
	const CODE_MAX = 40;

	/** Every event type the table accepts. */
	const TYPES = array(
		'product_view',
		'add_to_cart',
		'remove_from_cart',
		'checkout_start',
		'coupon_applied',
		'coupon_refused',
		'payment_failed',
		'order_paid',
		'payment_recovered',
	);

	/**
	 * Order meta: gateway:code of the FIRST failed payment of this order
	 * (OPT-1228). Lets the paid order be counted as "paid later" for that code
	 * and its amount be counted once; nothing of it reaches the counters table.
	 */
	const FAILED_META = '_ob_shop_failed_code';

	/** Event types sent by the tracker (cache-safe page events). */
	const TRACKER_TYPES = array( 'product_view', 'checkout_start' );

	/**
	 * Hooks registered once.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Refused coupon errors already counted in this request (one validation
	 * runs several times per request).
	 *
	 * @var array
	 */
	private static $seen_refusals = array();

	/**
	 * Register the hooks. Called once from Opti-Behavior.php.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		add_action( 'plugins_loaded', array( __CLASS__, 'register_shop_hooks' ), 20 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_create_table' ) );
			add_action( 'admin_post_' . self::ACTION_NAME, array( __CLASS__, 'handle_settings_save' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------- */

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether shop events are counted on this site.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$enabled = self::woocommerce_active() && '0' !== (string) get_option( self::OPTION, '1' );

		/**
		 * Filter whether shop events are counted.
		 *
		 * @param bool $enabled WooCommerce active and the setting on.
		 */
		return (bool) apply_filters( 'opti_behavior_shop_tracking_enabled', $enabled );
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Whether the table was created by this schema version.
	 *
	 * @return bool
	 */
	public static function table_ready() {
		return self::DB_VERSION === (string) get_option( self::DB_VERSION_OPTION, '' );
	}

	/**
	 * Create / upgrade the table (dbDelta-safe). Called by the database setup.
	 *
	 * @param string $charset_collate Optional charset collation.
	 * @return void
	 */
	public static function create_table( $charset_collate = '' ) {
		global $wpdb;

		if ( '' === $charset_collate ) {
			$charset_collate = $wpdb->get_charset_collate();
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$engine = class_exists( 'Opti_Behavior_Heatmap_Database' ) ? Opti_Behavior_Heatmap_Database::engine_clause() : 'ENGINE=InnoDB';

		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id         bigint(20)    UNSIGNED NOT NULL AUTO_INCREMENT,
			day        date                   NOT NULL,
			product_id bigint(20)    UNSIGNED NOT NULL DEFAULT 0,
			event_type varchar(20)            NOT NULL,
			code       varchar(40)            NOT NULL DEFAULT '',
			count      int(10)       UNSIGNED NOT NULL DEFAULT 0,
			qty        int(10)       UNSIGNED NOT NULL DEFAULT 0,
			amount     decimal(14,4)          NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY day_product_type_code (day, product_id, event_type, code),
			KEY type_day (event_type, day)
			) " . $engine . ' ' . $charset_collate
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Create the table when this schema version is not installed yet
	 * (a plugin update does not always run the full database setup).
	 * Never on AJAX / cron requests.
	 *
	 * @return void
	 */
	public static function maybe_create_table() {
		if ( self::table_ready() ) {
			return;
		}
		if ( ( wp_doing_ajax() || wp_doing_cron() ) && 'cli' !== PHP_SAPI ) {
			return;
		}
		self::create_table();
	}

	/* ---------------------------------------------------------------------
	 * WooCommerce hooks
	 * ------------------------------------------------------------------- */

	/**
	 * Hook the WooCommerce events (plugins_loaded 20).
	 *
	 * @return void
	 */
	public static function register_shop_hooks() {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 10, 4 );
		add_action( 'woocommerce_remove_cart_item', array( __CLASS__, 'on_remove_cart_item' ), 10, 2 );
		add_action( 'woocommerce_applied_coupon', array( __CLASS__, 'on_applied_coupon' ), 10, 1 );
		add_filter( 'woocommerce_coupon_error', array( __CLASS__, 'on_coupon_error' ), 10, 3 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_order_paid' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_paid' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_paid' ), 10, 1 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_order_failed' ), 10, 2 );
	}

	/**
	 * Whether the visitor of this request may be counted: same rules as the
	 * page views (bots, automation tools and excluded IPs ignored; administrators
	 * only when "Track admin users" is on).
	 *
	 * @return bool
	 */
	public static function visitor_counted() {
		if ( class_exists( 'Opti_Behavior_Ingest_Gate' ) && Opti_Behavior_Ingest_Gate::should_reject( 'shop_event' ) ) {
			return false;
		}
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			$settings    = get_option( 'opti_behavior_traffic_settings', array() );
			$track_admin = is_array( $settings ) && isset( $settings['track_admin_users'] ) ? (bool) $settings['track_admin_users'] : true;
			if ( ! $track_admin ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Product added to the cart (classic form, AJAX button, Store API / blocks).
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product id (parent of a variation).
	 * @param int    $quantity      Quantity added.
	 * @param int    $variation_id  Variation id.
	 * @return void
	 */
	public static function on_add_to_cart( $cart_item_key, $product_id, $quantity = 1, $variation_id = 0 ) {
		unset( $cart_item_key, $variation_id );
		if ( ! self::visitor_counted() ) {
			return;
		}
		// A plain link `?add-to-cart=ID` counts only when the visitor comes from a
		// page of this site: robots and mail clients follow such links (OPT-1735).
		if ( self::is_direct_link_request() && ! self::same_site_referer( self::request_referer() ) ) {
			return;
		}
		self::record( 'add_to_cart', absint( $product_id ), max( 1, absint( $quantity ) ) );
	}

	/**
	 * Whether this request is a plain add-to-cart link: GET with `add-to-cart`
	 * in the query, not the AJAX button (wc-ajax / admin-ajax) nor the Store
	 * API (blocks). The product form posts, so it is not a link either.
	 *
	 * @return bool
	 */
	public static function is_direct_link_request() {
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only test of the WooCommerce query argument.
		return 'GET' === $method && isset( $_GET['add-to-cart'] ) && ! isset( $_GET['wc-ajax'] );
	}

	/**
	 * Referer header of the request ('' = none). Never stored.
	 *
	 * @return string
	 */
	public static function request_referer() {
		return isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
	}

	/**
	 * Whether a referer is a page of this site (host of home_url() or
	 * site_url(), "www." ignored). Pure but for the two URLs.
	 *
	 * @param string $referer Referer address.
	 * @return bool
	 */
	public static function same_site_referer( $referer ) {
		$host = strtolower( (string) wp_parse_url( (string) $referer, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		$host = preg_replace( '/^www\./', '', $host );
		foreach ( array( home_url(), site_url() ) as $own ) {
			$own_host = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $own, PHP_URL_HOST ) ) );
			if ( '' !== $own_host && $own_host === $host ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Product removed from the cart (fired before the line leaves the cart).
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param object $cart          WC_Cart.
	 * @return void
	 */
	public static function on_remove_cart_item( $cart_item_key, $cart ) {
		if ( ! self::visitor_counted() ) {
			return;
		}
		$product_id = 0;
		$quantity   = 1;
		if ( is_object( $cart ) && isset( $cart->cart_contents ) && is_array( $cart->cart_contents ) && isset( $cart->cart_contents[ $cart_item_key ] ) ) {
			$item       = $cart->cart_contents[ $cart_item_key ];
			$product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
			$quantity   = isset( $item['quantity'] ) ? max( 1, absint( $item['quantity'] ) ) : 1;
		}
		self::record( 'remove_from_cart', $product_id, $quantity );
	}

	/**
	 * Coupon accepted. The coupon code itself is never stored.
	 *
	 * @param string $coupon_code Coupon code (not stored).
	 * @return void
	 */
	public static function on_applied_coupon( $coupon_code ) {
		unset( $coupon_code );
		if ( ! self::visitor_counted() ) {
			return;
		}
		self::record( 'coupon_applied', 0, 1 );
	}

	/**
	 * Coupon refused: the WooCommerce error number only (e.g. 105 = does not
	 * exist), never the coupon code nor the message. Filter: returns $err.
	 *
	 * @param string $err      Error message (returned unchanged, not stored).
	 * @param int    $err_code WooCommerce coupon error number.
	 * @param mixed  $coupon   WC_Coupon or null.
	 * @return string
	 */
	public static function on_coupon_error( $err, $err_code = 0, $coupon = null ) {
		$code = self::normalize_code( (string) absint( $err_code ) );
		$who  = ( is_object( $coupon ) && method_exists( $coupon, 'get_code' ) ) ? md5( (string) $coupon->get_code() ) : '';
		$key  = $code . '|' . $who;
		if ( isset( self::$seen_refusals[ $key ] ) ) {
			return $err;
		}
		self::$seen_refusals[ $key ] = true;

		if ( self::visitor_counted() ) {
			self::record( 'coupon_refused', 0, 1, 0.0, $code );
		}
		return $err;
	}

	/**
	 * Paid order: counted ONCE per order (flag in the order meta), one row for
	 * the whole order (product 0) + one row per product line. code = currency.
	 * Not filtered by visitor: a payment gateway calls back from its own server.
	 *
	 * @param int $order_id Order id (never stored by us).
	 * @return void
	 */
	public static function on_order_paid( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::COUNTED_META ) ) {
			return;
		}
		if ( ! self::table_ready() ) {
			return;
		}
		// Flag first: the next status hook of the same request sees it.
		$order->update_meta_data( self::COUNTED_META, '1' );
		$order->save_meta_data();

		$currency = self::normalize_code( (string) $order->get_currency() );
		$qty_all  = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$qty      = max( 0, (int) $item->get_quantity() );
			$qty_all += $qty;
			self::record( 'order_paid', absint( $item->get_product_id() ), $qty, (float) $item->get_total(), $currency, array( 'order' => $order ) );
		}
		self::record( 'order_paid', 0, $qty_all, (float) $order->get_total(), $currency, array( 'order' => $order ) );

		// Paid after a failed payment (OPT-1228): "paid later" of that error code.
		$failed_code = self::normalize_code( (string) $order->get_meta( self::FAILED_META ) );
		if ( '' !== $failed_code ) {
			self::record( 'payment_recovered', 0, 1, (float) $order->get_total(), $failed_code, array( 'order' => $order ) );
		}
	}

	/**
	 * Failed payment: gateway id + technical error code, never the text of the
	 * notice. The code comes from filter `opti_behavior_shop_payment_error_code`
	 * (gateways that know one); without it the gateway id alone is stored.
	 *
	 * @param int   $order_id Order id (never stored by us).
	 * @param mixed $order    WC_Order.
	 * @return void
	 */
	public static function on_order_failed( $order_id, $order = null ) {
		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_payment_method' ) ) {
			return;
		}

		/**
		 * Technical error code of a failed payment (a-z, 0-9, _ ; never a message).
		 *
		 * @param string $code  '' by default.
		 * @param object $order WC_Order.
		 */
		$error = (string) apply_filters( 'opti_behavior_shop_payment_error_code', '', $order );
		$code  = self::payment_code( (string) $order->get_payment_method(), $error );

		// Every failed attempt is counted; the order amount and the "first
		// failure" flag only once per order (a retried order is one sale).
		$first = method_exists( $order, 'get_meta' ) && '' === (string) $order->get_meta( self::FAILED_META );
		if ( $first && method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( self::FAILED_META, $code );
			$order->save_meta_data();
		}

		self::record( 'payment_failed', 0, $first ? 1 : 0, $first ? (float) $order->get_total() : 0.0, $code, array( 'order' => $order, 'notice_type' => 'error' ) );
	}

	/* ---------------------------------------------------------------------
	 * Tracker (cache-safe page events)
	 * ------------------------------------------------------------------- */

	/**
	 * What the tracker must send on this page, printed in its config. Depends
	 * on the page only (never on the visitor), so it stays true under a page cache.
	 *
	 * @return array {t:'product_view', p:<id>} | {t:'checkout_start'} | empty.
	 */
	public static function page_context() {
		if ( ! self::is_enabled() ) {
			return array();
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			$id = absint( get_queried_object_id() );
			return $id > 0 ? array( 't' => 'product_view', 'p' => $id ) : array();
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
				return array();
			}
			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
				return array();
			}
			return array( 't' => 'checkout_start' );
		}
		return array();
	}

	/**
	 * Event sent by the tracker (record endpoint, action `shop_event`). The
	 * endpoint already checked the nonce, bots, excluded IPs and the admin rule.
	 *
	 * @param array  $data       Decoded payload ({event, product}).
	 * @param string $session_id Session id of the tracker (passed to Pro only).
	 * @return bool True when counted.
	 */
	public static function handle_tracker_event( $data, $session_id = '' ) {
		if ( ! is_array( $data ) || ! self::is_enabled() ) {
			return false;
		}
		$type = isset( $data['event'] ) ? sanitize_key( (string) $data['event'] ) : '';
		if ( ! in_array( $type, self::TRACKER_TYPES, true ) ) {
			return false;
		}

		$product_id = 0;
		if ( 'product_view' === $type ) {
			$product_id = isset( $data['product'] ) ? absint( $data['product'] ) : 0;
			if ( $product_id < 1 || 'product' !== get_post_type( $product_id ) || 'publish' !== get_post_status( $product_id ) ) {
				return false;
			}
		} elseif ( function_exists( 'WC' ) && WC() && isset( WC()->cart ) && is_object( WC()->cart ) && method_exists( WC()->cart, 'is_empty' ) && WC()->cart->is_empty() ) {
			// A checkout page opened with an empty cart is not a checkout start.
			return false;
		}

		return self::record(
			$type,
			$product_id,
			'product_view' === $type ? 0 : 1,
			0.0,
			'',
			array( 'session_id' => sanitize_text_field( (string) $session_id ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------- */

	/**
	 * Add one event to today's counter (site time zone). One short query.
	 *
	 * @param string $type       One of self::TYPES.
	 * @param int    $product_id Product id, 0 = whole order / no product.
	 * @param int    $qty        Quantity.
	 * @param float  $amount     Amount in the shop currency (paid order / failed payment).
	 * @param string $code       Currency (order_paid), gateway:code (payment_failed), error number (coupon_refused).
	 * @param array  $context    Passed to the `opti_behavior_shop_event` action only (never stored here).
	 * @return bool
	 */
	public static function record( $type, $product_id = 0, $qty = 0, $amount = 0.0, $code = '', $context = array() ) {
		global $wpdb;

		if ( ! in_array( $type, self::TYPES, true ) || ! self::table_ready() ) {
			return false;
		}

		$code   = self::normalize_code( $code );
		$amount = round( (float) $amount, 4 );
		$day    = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$table  = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counter upsert on the plugin's own table.
		$done = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, product_id, event_type, code, count, qty, amount) VALUES (%s, %d, %s, %s, 1, %d, %f)
				ON DUPLICATE KEY UPDATE count = count + 1, qty = qty + VALUES(qty), amount = amount + VALUES(amount)",
				$day,
				absint( $product_id ),
				$type,
				$code,
				max( 0, (int) $qty ),
				$amount
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/**
		 * A shop event was counted. Pro links it to the session (Full Tracking +
		 * consent only). $args never reaches the Free table.
		 *
		 * @param string $type Event type.
		 * @param array  $args product_id, qty, amount, code + the context (session_id from the tracker, order object, notice_type).
		 */
		do_action(
			'opti_behavior_shop_event',
			$type,
			array_merge(
				is_array( $context ) ? $context : array(),
				array(
					'product_id' => absint( $product_id ),
					'qty'        => max( 0, (int) $qty ),
					'amount'     => $amount,
					'code'       => $code,
				)
			)
		);

		return false !== $done;
	}

	/**
	 * Technical code: lower case a-z, 0-9, _ , : and - only, 40 characters at most.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	public static function normalize_code( $code ) {
		$code = strtolower( trim( (string) $code ) );
		$code = preg_replace( '/[^a-z0-9_:\-]+/', '_', $code );
		$code = trim( (string) $code, '_' );
		return substr( $code, 0, self::CODE_MAX );
	}

	/**
	 * Code of a failed payment: "<gateway>" or "<gateway>:<error>". A value that
	 * looks like a sentence (spaces, more than 40 characters) is refused: never
	 * the text of a notice.
	 *
	 * @param string $gateway Gateway id.
	 * @param string $error   Error code from the gateway filter.
	 * @return string
	 */
	public static function payment_code( $gateway, $error = '' ) {
		$gateway = self::normalize_code( sanitize_key( (string) $gateway ) );
		$error   = trim( (string) $error );
		if ( '' === $error || preg_match( '/\s/', $error ) || strlen( $error ) > self::CODE_MAX ) {
			$error = '';
		}
		$error = self::normalize_code( $error );
		if ( '' === $gateway ) {
			$gateway = 'unknown';
		}
		return '' === $error ? $gateway : self::normalize_code( $gateway . ':' . $error );
	}

	/**
	 * Delete counters older than the raw data retention (0 = kept, like the
	 * other raw tables). Called by the daily retention pass.
	 *
	 * @param int $raw_days Raw data retention in days.
	 * @return int Rows deleted.
	 */
	public static function prune( $raw_days ) {
		global $wpdb;

		$raw_days = (int) $raw_days;
		$deleted  = 0;
		if ( $raw_days > 0 && self::table_ready() ) {
			$cutoff = gmdate( 'Y-m-d', ( function_exists( 'current_time' ) ? current_time( 'timestamp' ) : time() ) - ( $raw_days * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Site-local day, same as the stored `day`.
			$table  = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Retention on the plugin's own table.
			$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $cutoff ) );
		}

		/**
		 * Shop event retention ran (Pro purges its session-linked rows with the same window).
		 *
		 * @param int $raw_days Raw data retention in days (0 = kept).
		 */
		do_action( 'opti_behavior_shop_events_prune', $raw_days );

		return $deleted;
	}

	/* ---------------------------------------------------------------------
	 * Settings block (Settings > Traffic & Behavior), WooCommerce sites only
	 * ------------------------------------------------------------------- */

	/**
	 * URL of the settings block.
	 *
	 * @param string $result Optional result flag.
	 * @return string
	 */
	public static function settings_url( $result = '' ) {
		$url = admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=traffic-behavior' );
		if ( '' !== $result ) {
			$url = add_query_arg( self::RESULT_ARG, sanitize_key( $result ), $url );
		}
		return $url . '#ob-shop-tracking-setting';
	}

	/**
	 * Save the switch (nonce + manage_options).
	 *
	 * @return void
	 */
	public static function handle_settings_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
		}
		check_admin_referer( self::ACTION_NAME );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$on = isset( $_POST['ob_shop_tracking'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['ob_shop_tracking'] ) );
		update_option( self::OPTION, $on ? '1' : '0', true );
		// The weekly shop e-mail stopped (OPT-1735): an old form's `ob_shop_report` is ignored.

		wp_safe_redirect( self::settings_url( 'saved' ) );
		exit;
	}

	/**
	 * Settings block, printed at the bottom of Settings > Traffic & Behavior,
	 * outside every settings form. Nothing without WooCommerce.
	 *
	 * @return void
	 */
	public static function render_settings_block() {
		if ( ! current_user_can( 'manage_options' ) || ! self::woocommerce_active() ) {
			return;
		}

		$on = '0' !== (string) get_option( self::OPTION, '1' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result flag of a nonce-checked action.
		$result = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : '';
		?>
		<div class="settings-section" id="ob-shop-tracking-setting">
			<div class="ob-geo-db-card is-<?php echo $on ? 'ok' : 'warn'; ?>">
				<div class="ob-geo-db-head">
					<span class="ob-geo-db-icon" aria-hidden="true"><i data-lucide="shopping-cart"></i></span>
					<h3 class="ob-geo-db-title"><?php esc_html_e( 'Shop events', 'opti-behavior' ); ?></h3>
					<p class="ob-geo-db-state">
						<?php
						/* translators: %s: "On" or "Off" */
						printf( esc_html__( 'Status: %s', 'opti-behavior' ), '<b>' . ( $on ? esc_html__( 'On', 'opti-behavior' ) : esc_html__( 'Off', 'opti-behavior' ) ) . '</b>' );
						?>
					</p>
				</div>

				<?php if ( 'saved' === $result ) : ?>
					<p class="ob-geo-db-result is-ok" role="status"><b><?php esc_html_e( 'Saved.', 'opti-behavior' ); ?></b></p>
				<?php endif; ?>

				<p class="description ob-geo-db-text">
					<?php esc_html_e( 'Counts product views, add to cart, removals from the cart, checkout starts, coupons, failed payments and paid orders of your WooCommerce shop, per day and per product.', 'opti-behavior' ); ?>
					<?php esc_html_e( 'Totals only: no order number, customer, e-mail address, postal address or coupon code is stored. Kept as long as the raw data (Data retention setting).', 'opti-behavior' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ob-geo-db-actions">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_NAME ); ?>" />
					<?php wp_nonce_field( self::ACTION_NAME ); ?>
					<label class="ob-geo-db-monthly" for="ob-shop-tracking">
						<input type="checkbox" id="ob-shop-tracking" name="ob_shop_tracking" value="1" <?php checked( $on ); ?> />
						<span class="ob-geo-db-monthly-text"><?php esc_html_e( 'Count shop events', 'opti-behavior' ); ?></span>
					</label>
					<?php
					if ( class_exists( 'Opti_Behavior_Shop_Report' ) ) {
						Opti_Behavior_Shop_Report::render_setting_row();
					}
					?>
					<button type="submit" class="button button-secondary" id="ob-shop-tracking-save"><?php esc_html_e( 'Save', 'opti-behavior' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}
}
