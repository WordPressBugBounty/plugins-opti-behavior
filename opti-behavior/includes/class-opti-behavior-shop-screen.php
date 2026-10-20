<?php
/**
 * "Shop" screen: product pages that get views but no add to cart (OPT-1228,
 * OPT-1187 idea 3a; designs OPT-1190 / OPT-1191 in qa/design-opt1187-shop/).
 *
 * Shown only when WooCommerce is active. Reads the daily counters of
 * Opti_Behavior_Shop_Events (no session, no visitor: works in every mode).
 * Pro adds the estimated lost sales (tile + column) and the "Payment errors"
 * block through the filter `opti_behavior_shop_screen_pro`; Free alone prints
 * one Pro band under the table and a locked "Payment errors" head.
 *
 * @package Opti_Behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shop screen (Free).
 */
class Opti_Behavior_Shop_Screen {

	/** Admin page slug. */
	const SLUG = 'opti-behavior-shop';

	/** Periods of the select (same keys as Page X-Ray) => days. */
	const PERIODS = array(
		'last7days'  => 7,
		'last14days' => 14,
		'last30days' => 30,
		'last90days' => 90,
	);

	/** Default period. */
	const DEFAULT_PERIOD = 'last30days';

	/** Rows of the product table at most. */
	const MAX_ROWS = 200;

	/** Add-to-cart rate tones (percent): under FIX = is-fix, under WATCH = is-watch, else is-good. */
	const RATE_FIX   = 3.0;
	const RATE_WATCH = 8.0;

	/**
	 * Page hook of the screen.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hooks registered once.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Register the hooks (admin only). Called once from Opti-Behavior.php.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$hooked || ! is_admin() ) {
			return;
		}
		self::$hooked = true;
		// After the dashboard menu (10) and before the Pro pages (13).
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
	}

	/**
	 * Whether the screen exists on this site (WooCommerce active).
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'Opti_Behavior_Shop_Events' ) && Opti_Behavior_Shop_Events::woocommerce_active();
	}

	/**
	 * Menu row under "Your data" (placed by the dashboard's group_submenu_items()).
	 *
	 * @return void
	 */
	public static function add_menu() {
		if ( ! self::available() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Lucide "shopping-bag" icon (20x20), like the other rows.
		$icon       = '<span style="display:inline-flex;align-items:center;margin-right:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></span>';
		self::$hook = (string) add_submenu_page(
			'opti-behavior-analytics',
			__( 'Shop', 'opti-behavior' ),
			$icon . __( 'Shop', 'opti-behavior' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Styles + script of the screen (this screen only, version = file time).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::SLUG ) ) {
			return;
		}
		$dir = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/';
		$ver = function ( $rel ) use ( $dir ) {
			return OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . (int) @filemtime( $dir . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file only drops the cache buster.
		};
		wp_enqueue_style( 'opti-behavior-dashboard-styles', OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/dashboard_styles.css', array(), $ver( 'css/dashboard_styles.css' ) );
		wp_enqueue_style( 'opti-behavior-shop', OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/shop.css', array( 'opti-behavior-dashboard-styles' ), $ver( 'css/shop.css' ) );
		wp_enqueue_script( 'opti-behavior-shop', OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/shop.js', array(), $ver( 'js/shop.js' ), true );
		wp_localize_script(
			'opti-behavior-shop',
			'optiBehaviorShop',
			array(
				/* translators: %d: number of hidden error codes. */
				'showMore' => __( 'Show %d more error codes', 'opti-behavior' ),
				'showLess' => __( 'Show fewer', 'opti-behavior' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------- */

	/**
	 * Period key from the request, else the default.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	public static function clean_period( $raw ) {
		$raw = sanitize_key( (string) $raw );
		return isset( self::PERIODS[ $raw ] ) ? $raw : self::DEFAULT_PERIOD;
	}

	/**
	 * Site days of a period: [ from, to ] (Y-m-d, to = today).
	 *
	 * @param string $period Period key.
	 * @param int    $now    Timestamp (tests).
	 * @return array
	 */
	public static function period_range( $period, $now = 0 ) {
		$days = self::PERIODS[ self::clean_period( $period ) ];
		$now  = $now > 0 ? $now : time();
		$to   = wp_date( 'Y-m-d', $now );
		$from = wp_date( 'Y-m-d', $now - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
		return array( $from, $to );
	}

	/**
	 * Counter rows of the period: product × type sums (one query).
	 *
	 * @param string $from First day (Y-m-d).
	 * @param string $to   Last day (Y-m-d).
	 * @return array[] { product_id, event_type, count, qty, amount }.
	 */
	public static function query_counts( $from, $to ) {
		global $wpdb;
		if ( ! class_exists( 'Opti_Behavior_Shop_Events' ) || ! Opti_Behavior_Shop_Events::table_ready() ) {
			return array();
		}
		$table = Opti_Behavior_Shop_Events::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table, read once per screen.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, event_type, SUM(count) AS count, SUM(qty) AS qty, SUM(amount) AS amount
				FROM {$table} WHERE day BETWEEN %s AND %s AND event_type IN ('product_view', 'add_to_cart', 'order_paid')
				GROUP BY product_id, event_type",
				$from,
				$to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Totals + product rows from the counter rows (pure).
	 *
	 * Lost visits of a product = its page views minus its adds to cart (an add
	 * made from a list page can exceed the views: never below 0). Paid orders
	 * of the totals = whole-order rows (product 0); of a product = order lines.
	 *
	 * @param array[] $counts Rows of query_counts().
	 * @return array { totals{ views, atc, orders, amount, lost }, rows[] }
	 */
	public static function build_report( $counts ) {
		$by     = array();
		$totals = array(
			'views'  => 0,
			'atc'    => 0,
			'orders' => 0,
			'amount' => 0.0,
			'lost'   => 0,
		);
		foreach ( (array) $counts as $row ) {
			$pid   = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
			$type  = isset( $row['event_type'] ) ? (string) $row['event_type'] : '';
			$count = isset( $row['count'] ) ? max( 0, (int) $row['count'] ) : 0;
			if ( 0 === $pid ) {
				if ( 'order_paid' === $type ) {
					$totals['orders'] += $count;
					$totals['amount'] += isset( $row['amount'] ) ? (float) $row['amount'] : 0.0;
				}
				if ( 'add_to_cart' === $type ) {
					$totals['atc'] += $count;
				}
				continue;
			}
			if ( ! isset( $by[ $pid ] ) ) {
				$by[ $pid ] = array(
					'product_id' => $pid,
					'views'      => 0,
					'atc'        => 0,
					'orders'     => 0,
				);
			}
			if ( 'product_view' === $type ) {
				$by[ $pid ]['views'] += $count;
			} elseif ( 'add_to_cart' === $type ) {
				$by[ $pid ]['atc'] += $count;
			} elseif ( 'order_paid' === $type ) {
				$by[ $pid ]['orders'] += $count;
			}
		}

		$rows       = array();
		$unmeasured = array();
		foreach ( $by as $row ) {
			$totals['views'] += $row['views'];
			$totals['atc']   += $row['atc'];
			if ( $row['views'] < 1 ) {
				// Added to the cart without a product page view (link, list button):
				// "Not measured", never 0 views, no rate, no lost visits (OPT-1735).
				if ( $row['atc'] > 0 ) {
					$unmeasured[] = $row;
				}
				continue; // The table lists product PAGES with views.
			}
			$row['lost']     = max( 0, $row['views'] - $row['atc'] );
			$row['rate']     = round( min( 100, 100 * $row['atc'] / $row['views'] ), 1 );
			$totals['lost'] += $row['lost'];
			$rows[]          = $row;
		}
		usort(
			$rows,
			function ( $a, $b ) {
				if ( $a['lost'] !== $b['lost'] ) {
					return $b['lost'] - $a['lost'];
				}
				if ( $a['views'] !== $b['views'] ) {
					return $b['views'] - $a['views'];
				}
				return $a['product_id'] - $b['product_id'];
			}
		);

		usort(
			$unmeasured,
			function ( $a, $b ) {
				return $a['atc'] !== $b['atc'] ? $b['atc'] - $a['atc'] : $a['product_id'] - $b['product_id'];
			}
		);

		return array(
			'totals'     => $totals,
			'rows'       => array_slice( $rows, 0, self::MAX_ROWS ),
			'count'      => count( $rows ),
			'unmeasured' => array_slice( $unmeasured, 0, self::MAX_ROWS ),
		);
	}

	/**
	 * Views of a product (or of the shop) are "not measured": adds to cart
	 * but no product page view in the period (OPT-1735).
	 *
	 * @param int $views Product page views.
	 * @param int $atc   Adds to cart.
	 * @return bool
	 */
	public static function views_not_measured( $views, $atc ) {
		return (int) $views < 1 && (int) $atc > 0;
	}

	/**
	 * Label of views that are not measured.
	 *
	 * @return string
	 */
	public static function not_measured_label() {
		return __( 'Not measured', 'opti-behavior' );
	}

	/**
	 * Sentence under a "Not measured" figure.
	 *
	 * @return string
	 */
	public static function not_measured_note() {
		return __( 'Product pages were not visited: items were added to the cart from other pages (links or buttons).', 'opti-behavior' );
	}

	/**
	 * Tone of an add-to-cart rate (the number is always written next to it).
	 *
	 * @param float $rate Percent.
	 * @return string fix | watch | good
	 */
	public static function rate_tone( $rate ) {
		if ( $rate < self::RATE_FIX ) {
			return 'fix';
		}
		return $rate < self::RATE_WATCH ? 'watch' : 'good';
	}

	/**
	 * Amount in the shop currency, whole units ("€3,125"). Also used by Pro.
	 *
	 * @param float $amount Amount.
	 * @return string Plain text.
	 */
	public static function money( $amount ) {
		$amount = (float) $amount;
		if ( function_exists( 'wc_price' ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'decimals' => 0 ) ) ), ENT_QUOTES, 'UTF-8' ) );
		}
		return number_format_i18n( $amount, 0 );
	}

	/**
	 * Privacy mode of the plugin: 'full' or 'anonymous' (= Cookieless).
	 *
	 * @return string
	 */
	public static function privacy_mode() {
		$options = array();
		if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) && method_exists( 'Opti_Behavior_Heatmap_Core', 'get_instance' ) ) {
			$core = Opti_Behavior_Heatmap_Core::get_instance();
			if ( $core && method_exists( $core, 'get_options' ) ) {
				$options = (array) $core->get_options();
			}
		}
		return isset( $options['privacy_mode'] ) && 'full' === $options['privacy_mode'] ? 'full' : 'anonymous';
	}

	/**
	 * Label of a period ("Last 30 days").
	 *
	 * @param string $period Period key.
	 * @return string
	 */
	public static function period_label( $period ) {
		$labels = array(
			'last7days'  => __( 'Last 7 days', 'opti-behavior' ),
			'last14days' => __( 'Last 14 days', 'opti-behavior' ),
			'last30days' => __( 'Last 30 days', 'opti-behavior' ),
			'last90days' => __( 'Last 3 months', 'opti-behavior' ),
		);
		return $labels[ self::clean_period( $period ) ];
	}

	/* ---------------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------------- */

	/**
	 * Print the screen.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET period of a report.
		$period = self::clean_period( isset( $_GET['period'] ) ? wp_unslash( $_GET['period'] ) : '' );
		list( $from, $to ) = self::period_range( $period );
		$report            = self::build_report( self::query_counts( $from, $to ) );
		$report['period']  = $period;
		$report['from']    = $from;
		$report['to']      = $to;
		$report['mode']    = self::privacy_mode();
		$report['label']   = self::period_label( $period );

		$pro = null;
		try {
			/**
			 * Pro part of the Shop screen (estimated lost sales, payment errors).
			 *
			 * @param null|array $pro    null = nothing. Array keys: `tile_html` (an
			 *                           escaped `li.ob-shop__tile.is-pro`), `column`
			 *                           (header of the estimate column, '' = none),
			 *                           `cells` (product id => [ range text, sort number ]),
			 *                           `payment_html` (escaped payment block).
			 * @param array      $report Totals, rows, period, from, to, mode.
			 */
			$pro = apply_filters( 'opti_behavior_shop_screen_pro', null, $report );
		} catch ( Throwable $e ) {
			$pro = null;
		}
		$pro = is_array( $pro ) ? $pro : null;

		$empty = 0 === $report['totals']['views'] && 0 === $report['totals']['orders'] && 0 === $report['totals']['atc'];
		?>
		<div class="wrap opti-behavior-dashboard-page ob-shop-page">
			<?php self::render_header( $period ); ?>
			<div class="dashboard-content">
				<div class="ob-shop">
					<?php
					if ( ! Opti_Behavior_Shop_Events::is_enabled() ) {
						self::render_off_box();
					} elseif ( $empty ) {
						self::render_first_day_box();
					} else {
						if ( 'full' !== $report['mode'] ) {
							self::render_mode_line();
						}
						self::render_tiles( $report, $pro );
						self::render_table( $report, $pro );
					}
					?>
					<div class="ob-shop__slot" data-slot="payment-errors"><?php
					if ( ! $empty && Opti_Behavior_Shop_Events::is_enabled() ) {
						if ( null !== $pro && ! empty( $pro['payment_html'] ) ) {
							echo $pro['payment_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the Pro plugin (filter contract).
						} elseif ( null === $pro && self::show_pro_band() ) {
							self::render_payment_locked();
						}
					}
					?></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Free alone (Pro plugin not loaded): show the Pro bands.
	 *
	 * Pro loaded with its licence gate closed shows neither band nor figure,
	 * so this follows the plugin, not the gate (opti_behavior_pro_active()).
	 *
	 * @return bool
	 */
	private static function show_pro_band() {
		return ! defined( 'OPTI_BEHAVIOR_PRO_VERSION' );
	}

	/**
	 * Purple header + period form.
	 *
	 * @param string $period Period key.
	 * @return void
	 */
	private static function render_header( $period ) {
		?>
		<div class="dashboard-header">
			<div class="dashboard-title-section">
				<?php Opti_Behavior_Admin_Brand::render_mark(); ?>
				<div class="dashboard-title-text">
					<?php Opti_Behavior_Admin_Brand::render_name(); ?>
					<h1 class="dashboard-title"><?php esc_html_e( 'Shop', 'opti-behavior' ); ?></h1>
					<div class="dashboard-subtitle"><span class="subtitle-text"><?php esc_html_e( 'Product pages that get views but no add to cart.', 'opti-behavior' ); ?></span></div>
				</div>
			</div>
			<form class="dashboard-controls ob-shop__controls" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label class="screen-reader-text" for="ob-shop-period"><?php esc_html_e( 'Period', 'opti-behavior' ); ?></label>
				<select id="ob-shop-period" name="period">
					<?php foreach ( array_keys( self::PERIODS ) as $key ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $period, $key ); ?>><?php echo esc_html( self::period_label( $key ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Apply', 'opti-behavior' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * State 1: WooCommerce active, nothing counted in the period.
	 *
	 * @return void
	 */
	private static function render_first_day_box() {
		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		?>
		<section class="ob-firstday is-info" aria-labelledby="ob-shop-first">
			<span class="ob-firstday__mark" aria-hidden="true">i</span>
			<div class="ob-firstday__body">
				<p class="ob-firstday__kicker"><?php esc_html_e( 'Shop', 'opti-behavior' ); ?></p>
				<h2 class="ob-firstday__title" id="ob-shop-first"><?php esc_html_e( 'Counting starts now', 'opti-behavior' ); ?></h2>
				<p class="ob-firstday__text"><?php esc_html_e( 'WooCommerce is active. Views of your product pages, adds to cart and paid orders are counted from today: the first products show here after their first visit.', 'opti-behavior' ); ?></p>
			</div>
			<a class="ob-firstday__btn" href="<?php echo esc_url( $shop_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open your shop', 'opti-behavior' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'opti-behavior' ); ?></span></a>
		</section>
		<?php
	}

	/**
	 * Shop counting switched off in Settings.
	 *
	 * @return void
	 */
	private static function render_off_box() {
		?>
		<section class="ob-firstday is-warn" aria-labelledby="ob-shop-off">
			<span class="ob-firstday__mark" aria-hidden="true">!</span>
			<div class="ob-firstday__body">
				<p class="ob-firstday__kicker"><?php esc_html_e( 'Shop', 'opti-behavior' ); ?></p>
				<h2 class="ob-firstday__title" id="ob-shop-off"><?php esc_html_e( 'Shop counting is off', 'opti-behavior' ); ?></h2>
				<p class="ob-firstday__text"><?php esc_html_e( 'Turn it on in Settings to count product page views, adds to cart and paid orders.', 'opti-behavior' ); ?></p>
			</div>
			<a class="ob-firstday__link" href="<?php echo esc_url( Opti_Behavior_Shop_Events::settings_url() ); ?>"><?php esc_html_e( 'Open Settings', 'opti-behavior' ); ?> →</a>
		</section>
		<?php
	}

	/**
	 * State 5: Cookieless line (daily totals only).
	 *
	 * @return void
	 */
	private static function render_mode_line() {
		?>
		<p class="ob-shop__mode"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg><span><strong><?php esc_html_e( 'Cookieless mode', 'opti-behavior' ); ?></strong> · <?php esc_html_e( 'Daily totals only (no sessions).', 'opti-behavior' ); ?></span></p>
		<?php
	}

	/**
	 * Totals of the period (+ the Pro tile).
	 *
	 * @param array      $report Report.
	 * @param array|null $pro    Pro part.
	 * @return void
	 */
	private static function render_tiles( $report, $pro ) {
		$t     = $report['totals'];
		$share = $t['views'] > 0 ? (int) round( 100 * $t['lost'] / $t['views'] ) : 0;
		$tile  = null !== $pro && ! empty( $pro['tile_html'] ) ? (string) $pro['tile_html'] : '';
		$nm    = self::views_not_measured( $t['views'], $t['atc'] );
		?>
		<ul class="ob-shop__tiles<?php echo '' !== $tile ? ' has-pro' : ''; ?>">
			<li class="ob-shop__tile is-lead">
				<span class="ob-shop__tile-label"><?php esc_html_e( 'Lost visits', 'opti-behavior' ); ?></span>
				<span class="ob-shop__tile-value"><?php echo esc_html( $nm ? self::not_measured_label() : number_format_i18n( $t['lost'] ) ); ?></span>
				<span class="ob-shop__tile-note">
					<?php
					/* translators: %d: share of the product page views, in percent. */
					echo esc_html( $nm ? self::not_measured_note() : sprintf( __( '%d%% of product page views, no add to cart', 'opti-behavior' ), $share ) );
					?>
				</span>
			</li>
			<li class="ob-shop__tile is-views<?php echo $nm ? ' is-not-measured' : ''; ?>"><span class="ob-shop__tile-label"><?php esc_html_e( 'Product page views', 'opti-behavior' ); ?></span><span class="ob-shop__tile-value"><?php echo esc_html( $nm ? self::not_measured_label() : number_format_i18n( $t['views'] ) ); ?></span></li>
			<li class="ob-shop__tile"><span class="ob-shop__tile-label"><?php esc_html_e( 'Added to cart', 'opti-behavior' ); ?></span><span class="ob-shop__tile-value"><?php echo esc_html( number_format_i18n( $t['atc'] ) ); ?></span></li>
			<li class="ob-shop__tile"><span class="ob-shop__tile-label"><?php esc_html_e( 'Paid orders', 'opti-behavior' ); ?></span><span class="ob-shop__tile-value"><?php echo esc_html( number_format_i18n( $t['orders'] ) ); ?></span></li>
			<?php echo $tile; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the Pro plugin (filter contract). ?>
		</ul>
		<?php
	}

	/**
	 * Sortable header cell.
	 *
	 * @param string $label  Column name.
	 * @param string $sort   aria-sort value or ''.
	 * @param string $extra  Extra classes.
	 * @param string $title  Title attribute.
	 * @return void
	 */
	private static function th( $label, $sort = '', $extra = '', $title = '' ) {
		?>
		<th scope="col" class="ob-shop__th-num<?php echo esc_attr( $extra ); ?>" data-sortable<?php echo '' !== $sort ? ' aria-sort="' . esc_attr( $sort ) . '"' : ''; ?><?php echo '' !== $title ? ' title="' . esc_attr( $title ) . '"' : ''; ?>><button type="button" class="ob-shop__sort"><?php echo esc_html( $label ); ?><span class="ob-shop__sort-arrow" aria-hidden="true"></span></button></th>
		<?php
	}

	/**
	 * Product table (+ Pro column, or the Pro band for Free alone).
	 *
	 * @param array      $report Report.
	 * @param array|null $pro    Pro part.
	 * @return void
	 */
	private static function render_table( $report, $pro ) {
		$rows   = $report['rows'];
		$column = null !== $pro && ! empty( $pro['column'] ) ? (string) $pro['column'] : '';
		$cells  = null !== $pro && isset( $pro['cells'] ) && is_array( $pro['cells'] ) ? $pro['cells'] : array();
		$max    = 0;
		foreach ( $rows as $row ) {
			$max = max( $max, $row['lost'] );
		}
		$l_views  = __( 'Views', 'opti-behavior' );
		$l_atc    = __( 'Add to cart', 'opti-behavior' );
		$l_orders = __( 'Orders', 'opti-behavior' );
		$l_lost   = __( 'Lost visits', 'opti-behavior' );
		$l_rate   = __( 'Add-to-cart rate', 'opti-behavior' );
		$bag      = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>';
		?>
		<section class="ob-shop__panel" aria-labelledby="ob-shop-title">
			<div class="ob-shop__panel-head">
				<h2 class="ob-shop__panel-title" id="ob-shop-title"><?php esc_html_e( 'Product pages, the most lost visits first', 'opti-behavior' ); ?></h2>
				<span class="ob-shop__panel-sub">
					<?php
					/* translators: 1: number of products, 2: period ("last 30 days"). */
					echo esc_html( sprintf( _n( '%1$s product with views · %2$s', '%1$s products with views · %2$s', $report['count'], 'opti-behavior' ), number_format_i18n( $report['count'] ), function_exists( 'mb_strtolower' ) ? mb_strtolower( $report['label'] ) : strtolower( $report['label'] ) ) );
					?>
				</span>
			</div>
			<?php
			$unmeasured = isset( $report['unmeasured'] ) ? (array) $report['unmeasured'] : array();
			if ( empty( $rows ) && empty( $unmeasured ) ) :
				?>
				<p class="ob-shop__panel-sub" style="padding:0 20px 16px"><?php esc_html_e( 'No product page was viewed in this period.', 'opti-behavior' ); ?></p>
			<?php else : ?>
			<div class="ob-shop__scroll" role="region" tabindex="0" aria-labelledby="ob-shop-title">
				<table class="ob-shop__table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Product', 'opti-behavior' ); ?></th>
						<?php
						self::th( $l_views );
						self::th( $l_atc );
						self::th( $l_orders );
						self::th( $l_lost, 'descending', '', __( 'Views of the product page without add to cart', 'opti-behavior' ) );
						self::th( $l_rate );
						if ( '' !== $column ) {
							self::th( $column, '', ' ob-shop__th-est' );
						}
						?>
					</tr></thead>
					<tbody>
					<?php
					foreach ( $rows as $row ) :
						$pid   = (int) $row['product_id'];
						$title = get_the_title( $pid );
						$url   = get_permalink( $pid );
						$path  = $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
						$thumb = has_post_thumbnail( $pid ) ? get_the_post_thumbnail_url( $pid, 'thumbnail' ) : '';
						$width = $max > 0 ? (int) round( 100 * $row['lost'] / $max ) : 0;
						$est   = isset( $cells[ $pid ] ) ? (array) $cells[ $pid ] : array( '', 0 );
						?>
						<tr>
							<td class="ob-shop__product-cell" data-sort="<?php echo esc_attr( $title ); ?>"><div class="ob-shop__product">
								<?php if ( $thumb ) : ?>
									<img class="ob-shop__thumb" src="<?php echo esc_url( $thumb ); ?>" alt="" width="40" height="40" loading="lazy">
								<?php else : ?>
									<span class="ob-shop__thumb" aria-hidden="true"><?php echo $bag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></span>
								<?php endif; ?>
								<span class="ob-shop__product-text"><a class="ob-shop__product-name" href="<?php echo esc_url( $url ? $url : '#' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '' !== $title ? $title : '#' . $pid ); ?></a><span class="ob-shop__product-url"><?php echo esc_html( $path ); ?></span></span>
							</div></td>
							<td class="ob-shop__num" data-label="<?php echo esc_attr( $l_views ); ?>" data-sort="<?php echo esc_attr( $row['views'] ); ?>"><?php echo esc_html( number_format_i18n( $row['views'] ) ); ?></td>
							<td class="ob-shop__num" data-label="<?php echo esc_attr( $l_atc ); ?>" data-sort="<?php echo esc_attr( $row['atc'] ); ?>"><?php echo esc_html( number_format_i18n( $row['atc'] ) ); ?></td>
							<td class="ob-shop__num" data-label="<?php echo esc_attr( $l_orders ); ?>" data-sort="<?php echo esc_attr( $row['orders'] ); ?>"><?php echo esc_html( number_format_i18n( $row['orders'] ) ); ?></td>
							<td class="ob-shop__lost" data-label="<?php echo esc_attr( $l_lost ); ?>" data-sort="<?php echo esc_attr( $row['lost'] ); ?>"><strong><?php echo esc_html( number_format_i18n( $row['lost'] ) ); ?></strong><span class="ob-shop__bar" aria-hidden="true"><i style="width:<?php echo esc_attr( $width ); ?>%"></i></span></td>
							<td class="ob-shop__rate" data-label="<?php echo esc_attr( $l_rate ); ?>" data-sort="<?php echo esc_attr( $row['rate'] ); ?>"><span class="is-<?php echo esc_attr( self::rate_tone( $row['rate'] ) ); ?>"><?php echo esc_html( number_format_i18n( $row['rate'], 1 ) ); ?>%</span></td>
							<?php if ( '' !== $column ) : ?>
								<td class="ob-shop__est" data-label="<?php echo esc_attr( $column ); ?>" data-sort="<?php echo esc_attr( isset( $est[1] ) ? (float) $est[1] : 0 ); ?>"><?php echo esc_html( isset( $est[0] ) ? (string) $est[0] : '' ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					<?php
					// Added to the cart, no product page view: "Not measured", no rate, no lost visits (OPT-1735).
					foreach ( $unmeasured as $row ) :
						$pid   = (int) $row['product_id'];
						$title = get_the_title( $pid );
						$url   = get_permalink( $pid );
						$path  = $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
						?>
						<tr class="is-not-measured">
							<td class="ob-shop__product-cell" data-sort="<?php echo esc_attr( $title ); ?>"><div class="ob-shop__product">
								<span class="ob-shop__thumb" aria-hidden="true"><?php echo $bag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></span>
								<span class="ob-shop__product-text"><a class="ob-shop__product-name" href="<?php echo esc_url( $url ? $url : '#' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '' !== $title ? $title : '#' . $pid ); ?></a><span class="ob-shop__product-url"><?php echo esc_html( self::not_measured_note() ); ?></span></span>
							</div></td>
							<td class="ob-shop__num" data-label="<?php echo esc_attr( $l_views ); ?>" data-sort="-1"><?php echo esc_html( self::not_measured_label() ); ?></td>
							<td class="ob-shop__num" data-label="<?php echo esc_attr( $l_atc ); ?>" data-sort="<?php echo esc_attr( $row['atc'] ); ?>"><?php echo esc_html( number_format_i18n( $row['atc'] ) ); ?></td>
							<td class="ob-shop__num" data-label="<?php echo esc_attr( $l_orders ); ?>" data-sort="<?php echo esc_attr( $row['orders'] ); ?>"><?php echo esc_html( number_format_i18n( $row['orders'] ) ); ?></td>
							<td class="ob-shop__lost" data-label="<?php echo esc_attr( $l_lost ); ?>" data-sort="-1">—</td>
							<td class="ob-shop__rate" data-label="<?php echo esc_attr( $l_rate ); ?>" data-sort="-1">—</td>
							<?php if ( '' !== $column ) : ?>
								<td class="ob-shop__est" data-label="<?php echo esc_attr( $column ); ?>" data-sort="0"></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
			<?php if ( null === $pro && self::show_pro_band() && $report['totals']['lost'] > 0 ) : ?>
				<p class="ob-shop__pro-band"><em class="ob-shop__chip">Pro</em><span><strong><?php esc_html_e( 'Estimated lost sales', 'opti-behavior' ); ?></strong> — <?php
				/* translators: %s: number of lost visits of the period. */
				echo esc_html( sprintf( __( 'Pro turns your %s lost visits of this period into an estimated range in your currency, with the formula.', 'opti-behavior' ), number_format_i18n( $report['totals']['lost'] ) ) );
				?></span><a href="<?php echo esc_url( self::pro_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See what Pro adds', 'opti-behavior' ); ?></a></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Free alone: the "Payment errors" head with the Pro band (no figure).
	 *
	 * @return void
	 */
	private static function render_payment_locked() {
		?>
		<section class="ob-shop__panel ob-shop__pay" aria-labelledby="ob-shop-pay-title">
			<div class="ob-shop__panel-head">
				<div class="ob-shop__pay-title-line"><h2 class="ob-shop__panel-title" id="ob-shop-pay-title"><?php esc_html_e( 'Payment errors', 'opti-behavior' ); ?></h2><em class="ob-shop__chip">Pro</em></div>
			</div>
			<p class="ob-shop__pro-band"><span><?php esc_html_e( 'Pro groups your failed payments by gateway and error code, shows how many were paid later and what they cost, with a recording of the visit when there is one.', 'opti-behavior' ); ?></span><a href="<?php echo esc_url( self::pro_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See what Pro adds', 'opti-behavior' ); ?></a></p>
		</section>
		<?php
	}

	/**
	 * Target of the Pro links (same as every other "Pro" link of the Free).
	 *
	 * @return string
	 */
	private static function pro_url() {
		return class_exists( 'Opti_Behavior_Trial_Invite' ) ? Opti_Behavior_Trial_Invite::link_url( 'shop' ) : 'https://optiuser.com/opti-behavior/pro-trial/';
	}
}
