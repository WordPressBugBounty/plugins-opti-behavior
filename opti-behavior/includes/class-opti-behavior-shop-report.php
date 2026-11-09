<?php
/**
 * "Shop" numbers of the scheduled report e-mail (OPT-1735, HX 19/10: no
 * separate shop e-mail any more; was the weekly "Shop" e-mail of OPT-1229,
 * design OPT-1192 in qa/design-opt1187-shop/email/).
 *
 * `period_report()` = the numbers of the report period compared with the
 * period before (same length), the 3 products losing the most visits, the
 * products added to the cart without a product page view ("Not measured");
 * `render_block_html()` / `render_block_text()` print the "Shop" block of the
 * scheduled report (views/emails/report-email-{html,text}.php). Pro adds the
 * "Money at stake" block through the filter `opti_behavior_shop_report_pro`.
 * The weekly cron is stopped: `stop_weekly_email()` clears it at update /
 * activation and `run()` sends nothing. Settings are kept.
 *
 * Reads only the daily counters of Opti_Behavior_Shop_Events: totals and
 * product names, never a session, a visitor or an order (legal review 14/10).
 *
 * @package Opti_Behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekly shop report (Free).
 */
class Opti_Behavior_Shop_Report {

	/** On / off switch ('1' default, '0' = off). */
	const OPTION = 'opti_behavior_shop_report';

	/** Weekly cron event. */
	const CRON_HOOK = 'opti_behavior_shop_weekly_report';

	/** Last run { week, at, sent, reason } (non-autoloaded). */
	const STATE_OPTION = 'opti_behavior_shop_report_state';

	/** Products listed at most. */
	const TOP_PRODUCTS = 3;

	/** Colours of the e-mail (AA at 12 px). */
	const GOOD  = '#15803d';
	const BAD   = '#b91c1c';
	const MUTED = '#6b7280';
	const INK   = '#18181b';
	const LINE  = '#e5e7eb';
	const BRAND = '#4f46e5';

	// Adds to cart "to check": from this many add events, above this many per visit.
	const SUSPECT_MIN_ADDS       = 50;
	const SUSPECT_ADDS_PER_VISIT = 3;

	/**
	 * Hooks registered once.
	 *
	 * @var bool
	 */
	private static $hooked = false;

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
		// An event left by an older version: run() clears it and sends nothing.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	/* ---------------------------------------------------------------------
	 * State + schedule
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the "Shop" block of the scheduled report is built: WooCommerce
	 * active and shop counting on (OPT-1735). The old switch of the weekly
	 * e-mail (`OPTION`) is kept but no longer read.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$on = class_exists( 'Opti_Behavior_Shop_Events' ) && Opti_Behavior_Shop_Events::is_enabled();

		/**
		 * Filter whether the "Shop" block of the scheduled report is built.
		 *
		 * @param bool $on WooCommerce active and shop counting on.
		 */
		return (bool) apply_filters( 'opti_behavior_shop_report_enabled', $on );
	}

	/**
	 * Stop the weekly shop e-mail (OPT-1735): clear its cron event. Called at
	 * the Free version update (heatmap-core admin_init) and at activation.
	 * Options `OPTION` / `STATE_OPTION` are kept (uninstall deletes them).
	 *
	 * @return void
	 */
	public static function stop_weekly_email() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Site time of "now" (tests pass a timestamp).
	 *
	 * @param int $now Timestamp, 0 = now.
	 * @return DateTimeImmutable
	 */
	private static function site_now( $now = 0 ) {
		$date = new DateTimeImmutable( '@' . ( $now > 0 ? (int) $now : time() ) );
		return $date->setTimezone( wp_timezone() );
	}

	/**
	 * First day of the week of the site (Settings > General), 0 = Sunday.
	 *
	 * @return int
	 */
	private static function week_start() {
		return max( 0, min( 6, (int) get_option( 'start_of_week', 1 ) ) );
	}

	/**
	 * The last full week and the week before it (site days, Y-m-d).
	 *
	 * @param int $now Timestamp, 0 = now.
	 * @return string[] [ from, to, prev_from, prev_to ].
	 */
	public static function weeks( $now = 0 ) {
		$date  = self::site_now( $now )->setTime( 0, 0 );
		$back  = ( (int) $date->format( 'w' ) - self::week_start() + 7 ) % 7;
		$start = $date->modify( '-' . $back . ' days' );
		return array(
			$start->modify( '-7 days' )->format( 'Y-m-d' ),
			$start->modify( '-1 day' )->format( 'Y-m-d' ),
			$start->modify( '-14 days' )->format( 'Y-m-d' ),
			$start->modify( '-8 days' )->format( 'Y-m-d' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------- */

	/**
	 * Counter rows of a week: product × type sums (one query).
	 *
	 * @param string        $from  First day.
	 * @param string        $to    Last day.
	 * @param string[]|null $types Event types (known types only), null = those of the weekly e-mail.
	 * @return array[]
	 */
	public static function query_counts( $from, $to, $types = null ) {
		global $wpdb;
		if ( ! Opti_Behavior_Shop_Events::table_ready() ) {
			return array();
		}
		$types = null === $types
			? array( 'product_view', 'add_to_cart', 'add_to_cart_refused', 'order_paid', 'payment_failed' )
			: array_values( array_intersect( Opti_Behavior_Shop_Events::TYPES, (array) $types ) );
		if ( empty( $types ) ) {
			return array();
		}
		// Whitelisted constant names only: no request value reaches the IN list.
		$in    = "'" . implode( "', '", $types ) . "'";
		$table = Opti_Behavior_Shop_Events::table();
		// Adds written before the origin guard went live are grouped apart, like
		// on the Shop screen (OPT-2999).
		$since      = Opti_Behavior_Shop_Events::guard_since();
		$unverified = '' !== $since
			? $wpdb->prepare( 'CASE WHEN day < %s THEN 1 ELSE 0 END', $since )
			: '0';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table, read once a week.
		$rows = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own table name; IN list = whitelisted constants.
			$wpdb->prepare(
				"SELECT product_id, event_type, {$unverified} AS unverified, SUM(count) AS count, SUM(qty) AS qty, SUM(amount) AS amount
				FROM {$table} WHERE day BETWEEN %s AND %s AND event_type IN ({$in})
				GROUP BY product_id, event_type, unverified",
				$from,
				$to
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * First counted day ('' = nothing counted yet).
	 *
	 * @return string
	 */
	public static function first_day() {
		global $wpdb;
		if ( ! Opti_Behavior_Shop_Events::table_ready() ) {
			return '';
		}
		$table = Opti_Behavior_Shop_Events::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own table, indexed column.
		return (string) $wpdb->get_var( "SELECT MIN(day) FROM {$table}" );
	}

	/**
	 * Paid orders + checkouts started of a period, for the "Shop" line of the
	 * scheduled report (OPT-1516). Same counters as this e-mail: query_counts()
	 * + summarize() orders (whole-order rows); checkouts = checkout_start rows.
	 *
	 * @param string $from First day (Y-m-d).
	 * @param string $to   Last day (Y-m-d).
	 * @return int[] { orders, checkout }
	 */
	public static function line_counts( $from, $to ) {
		$counts   = self::query_counts( $from, $to, array( 'order_paid', 'checkout_start' ) );
		$checkout = 0;
		foreach ( $counts as $row ) {
			if ( isset( $row['event_type'] ) && 'checkout_start' === $row['event_type'] ) {
				$checkout += max( 0, (int) $row['count'] );
			}
		}
		$report = self::summarize( $counts );
		return array(
			'orders'   => (int) $report['totals']['orders'],
			'checkout' => $checkout,
		);
	}

	/**
	 * Totals + products of one week (pure).
	 *
	 * @param array[] $counts Rows of query_counts().
	 * @param string  $to     Last day of the period (Y-m-d, '' = read it from the rows; OPT-3004).
	 * @return array { totals{ views, views_covered, atc, orders, amount, lost, failed }, rows[], lost_measured }
	 */
	public static function summarize( $counts, $to = '' ) {
		$report = Opti_Behavior_Shop_Screen::build_report( $counts, Opti_Behavior_Shop_Events::guard_since(), $to );
		$failed = 0;
		foreach ( (array) $counts as $row ) {
			if ( isset( $row['event_type'] ) && 'payment_failed' === $row['event_type'] ) {
				$failed += max( 0, (int) $row['count'] );
			}
		}
		$report['totals']['failed'] = $failed;
		return $report;
	}

	/**
	 * The whole report of a week (pure: names are passed in).
	 *
	 * @param array    $cur   summarize() of the week.
	 * @param array    $prev  summarize() of the week before.
	 * @param bool     $first No day counted before the week (no comparison).
	 * @param string[] $names product id => name.
	 * @return array|null null = nothing counted this week (no e-mail).
	 */
	public static function build( $cur, $prev, $first, $names = array() ) {
		$t = $cur['totals'];
		if ( 0 === $t['views'] + $t['atc'] + $t['orders'] + $t['failed'] ) {
			return null;
		}
		// Lost visits of the period compared: unmeasurable there = no arrow at all,
		// never a fall from 0 (OPT-3004).
		$prev_measured = Opti_Behavior_Shop_Screen::lost_shown( $prev );
		$prev_lost     = array();
		if ( $prev_measured ) {
			foreach ( (array) $prev['rows'] as $row ) {
				if ( isset( $row['lost_measured'] ) && empty( $row['lost_measured'] ) ) {
					continue;
				}
				$prev_lost[ (int) $row['product_id'] ] = (int) $row['lost'];
			}
		}
		$products = array();
		foreach ( (array) $cur['rows'] as $row ) {
			if ( $row['lost'] < 1 || count( $products ) >= self::TOP_PRODUCTS ) {
				continue;
			}
			$pid        = (int) $row['product_id'];
			$products[] = array(
				'product_id' => $pid,
				'name'       => isset( $names[ $pid ] ) && '' !== $names[ $pid ] ? (string) $names[ $pid ] : '#' . $pid,
				'views'      => (int) $row['views'],
				'lost'       => (int) $row['lost'],
				'prev_lost'  => $prev_measured ? ( isset( $prev_lost[ $pid ] ) ? $prev_lost[ $pid ] : 0 ) : null,
			);
		}
		// Added to the cart without a product page view: "Not measured" (OPT-1735).
		$unmeasured = array();
		foreach ( isset( $cur['unmeasured'] ) ? (array) $cur['unmeasured'] : array() as $row ) {
			if ( count( $unmeasured ) >= self::TOP_PRODUCTS ) {
				break;
			}
			$pid          = (int) $row['product_id'];
			$unmeasured[] = array(
				'product_id' => $pid,
				'name'       => isset( $names[ $pid ] ) && '' !== $names[ $pid ] ? (string) $names[ $pid ] : '#' . $pid,
				'atc'        => (int) $row['atc'],
			);
		}
		return array(
			'first'      => (bool) $first,
			'totals'     => $t,
			'prev'       => $prev['totals'],
			'products'   => $products,
			'unmeasured' => $unmeasured,
			// Adds nobody could check + adds refused by the origin guard (OPT-2999)
			// + the day lost visits are counted from (OPT-3004).
			'add_notes'  => Opti_Behavior_Shop_Screen::add_notes( $cur ),
			// OPT-3004: the period holds a day the origin guard covers.
			'lost_measured' => Opti_Behavior_Shop_Screen::lost_shown( $cur ),
		);
	}

	/**
	 * Change line of a figure (pure).
	 *
	 * @param int  $now        This week.
	 * @param int  $prev       The week before.
	 * @param bool $up_is_good A rise is good news.
	 * @param bool $short      Product rows: "▲ 18%" without "vs last week".
	 * @param bool $period     Words of a report period ("the previous period"), not of a week.
	 * @return array { text, tone good|bad|same } ('' text = nothing to say).
	 */
	public static function change( $now, $prev, $up_is_good, $short = false, $period = false ) {
		$now  = (int) $now;
		$prev = (int) $prev;
		if ( $now === $prev ) {
			return array(
				'text' => $short ? '' : ( $period ? __( 'Same as the previous period', 'opti-behavior' ) : __( 'Same as last week', 'opti-behavior' ) ),
				'tone' => 'same',
			);
		}
		$up   = $now > $prev;
		$tone = $up === (bool) $up_is_good ? 'good' : 'bad';
		$mark = $up ? "\u{25B2}" : "\u{25BC}";
		if ( 0 === $now ) {
			$text = $period
				/* translators: %s: number of the previous period. */
				? sprintf( __( 'was %s in the previous period', 'opti-behavior' ), number_format_i18n( $prev ) )
				/* translators: %s: last week's number. */
				: sprintf( __( 'was %s last week', 'opti-behavior' ), number_format_i18n( $prev ) );
		} elseif ( 0 === $prev ) {
			$text = $period ? __( 'new in this period', 'opti-behavior' ) : __( 'new this week', 'opti-behavior' );
		} else {
			$pct  = (int) round( 100 * abs( $now - $prev ) / $prev );
			$pct  = $pct < 1 ? '<1%' : number_format_i18n( $pct ) . '%';
			$text = $short ? $pct : ( $period
				/* translators: %s: change in percent ("12%"). */
				? sprintf( __( '%s vs the previous period', 'opti-behavior' ), $pct )
				/* translators: %s: change in percent ("12%"). */
				: sprintf( __( '%s vs last week', 'opti-behavior' ), $pct ) );
		}
		return array(
			'text' => $mark . ' ' . $text,
			'tone' => $tone,
		);
	}

	/**
	 * Collect the report of the last full week.
	 *
	 * @param int $now Timestamp, 0 = now.
	 * @return array|null
	 */
	public static function collect( $now = 0 ) {
		list( $from, $to, $prev_from, $prev_to ) = self::weeks( $now );
		return self::assemble( $from, $to, $prev_from, $prev_to );
	}

	/**
	 * Report of the scheduled report period (OPT-1735): `$from` → `$to`
	 * compared with the period right before, same number of days.
	 *
	 * @param string $from First day (Y-m-d, site day).
	 * @param string $to   Last day (Y-m-d).
	 * @return array|null null = shop counting off / no WooCommerce / nothing counted.
	 */
	public static function period_report( $from, $to ) {
		if ( ! self::enabled() ) {
			return null;
		}
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $from );
		$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $to );
		if ( ! $start || ! $end || $end < $start ) {
			return null;
		}
		$days      = (int) $start->diff( $end )->days + 1;
		$prev_to   = $start->modify( '-1 day' );
		$prev_from = $prev_to->modify( '-' . ( $days - 1 ) . ' days' );
		$report    = self::assemble( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), $prev_from->format( 'Y-m-d' ), $prev_to->format( 'Y-m-d' ) );
		if ( null !== $report ) {
			$report['days']   = $days;
			$report['period'] = true;
		}
		return $report;
	}

	/**
	 * Report of a period compared with another (one grouped read each).
	 *
	 * @param string $from      First day.
	 * @param string $to        Last day.
	 * @param string $prev_from First day of the period compared.
	 * @param string $prev_to   Its last day.
	 * @return array|null
	 */
	private static function assemble( $from, $to, $prev_from, $prev_to ) {
		$cur   = self::summarize( self::query_counts( $from, $to ), $to );
		$prev  = self::summarize( self::query_counts( $prev_from, $prev_to ), $prev_to );
		$first = self::first_day();
		$names = array();
		$named = array_merge( array_slice( (array) $cur['rows'], 0, self::TOP_PRODUCTS * 2 ), array_slice( isset( $cur['unmeasured'] ) ? (array) $cur['unmeasured'] : array(), 0, self::TOP_PRODUCTS ) );
		foreach ( $named as $row ) {
			$pid           = (int) $row['product_id'];
			$names[ $pid ] = html_entity_decode( wp_strip_all_tags( get_the_title( $pid ) ), ENT_QUOTES, 'UTF-8' );
		}
		$report = self::build( $cur, $prev, '' === $first || $first >= $from, $names );
		if ( null === $report ) {
			return null;
		}
		$report['from']      = $from;
		$report['to']        = $to;
		$report['prev_from'] = $prev_from;
		$report['prev_to']   = $prev_to;
		$report['site']      = html_entity_decode( wp_strip_all_tags( get_bloginfo( 'name' ) ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $report['site'] ) {
			$report['site'] = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		$report['mode'] = Opti_Behavior_Shop_Screen::privacy_mode();
		return $report;
	}

	/* ---------------------------------------------------------------------
	 * Weekly e-mail stopped (OPT-1735)
	 * ------------------------------------------------------------------- */

	/**
	 * Cron event of an older version: clear it, send nothing (the numbers are
	 * in the scheduled report e-mail now).
	 *
	 * @param int $now Unused (old signature).
	 * @return bool Always false.
	 */
	public static function run( $now = 0 ) {
		unset( $now );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		return false;
	}

	/**
	 * Recipient: the administration e-mail address of the site.
	 *
	 * @return string
	 */
	public static function recipient() {
		return sanitize_email( (string) get_option( 'admin_email', '' ) );
	}

	/**
	 * Pro block (Money at stake), from the Pro plugin.
	 *
	 * @param array $report Report.
	 * @return array|null { html (escaped), text }.
	 */
	public static function pro_part( $report ) {
		$pro = null;
		try {
			/**
			 * Pro block of the weekly shop e-mail.
			 *
			 * @param null|array $pro    null = nothing. Array: `html` (escaped
			 *                           e-mail rows, inline styles) and `text`
			 *                           (plain-text lines).
			 * @param array      $report from, to, totals, prev, products, mode.
			 */
			$pro = apply_filters( 'opti_behavior_shop_report_pro', null, $report );
		} catch ( Throwable $e ) {
			$pro = null;
		}
		return is_array( $pro ) && isset( $pro['html'] ) ? $pro : null;
	}

	/* ---------------------------------------------------------------------
	 * Render (also used by the Pro block)
	 * ------------------------------------------------------------------- */

	/**
	 * Inline style with the font stack.
	 *
	 * @param string $css Style.
	 * @return string style="…" attribute.
	 */
	public static function style( $css ) {
		return 'style="' . esc_attr( $css . " font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;" ) . '"';
	}

	/**
	 * Section title (dot + text, optional chip).
	 *
	 * @param string $title Title.
	 * @param string $chip  Chip text ('' = none).
	 * @return string HTML.
	 */
	public static function section_head( $title, $chip = '' ) {
		$html = '<table cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin-bottom: 16px;"><tr><td ' . self::style( 'font-size: 16px; font-weight: 600; color: ' . self::INK . ';' ) . '><span style="display: inline-block; width: 8px; height: 8px; margin-right: 10px; border-radius: 50%; background-color: ' . self::BRAND . '; vertical-align: middle;"></span>' . esc_html( $title ) . '</td>';
		if ( '' !== $chip ) {
			$html .= '<td style="padding-left: 8px;"><span ' . self::style( 'display: inline-block; padding: 2px 8px; border-radius: 999px; background-color: #ede9fe; color: #5b21b6; font-size: 11px; font-weight: 600; letter-spacing: 0.3px;' ) . '>' . esc_html( $chip ) . '</span></td>';
		}
		return $html . '</tr></table>';
	}

	/**
	 * Colour of a tone.
	 *
	 * @param string $tone good | bad | same.
	 * @return string
	 */
	private static function tone_color( $tone ) {
		if ( 'good' === $tone ) {
			return self::GOOD;
		}
		return 'bad' === $tone ? self::BAD : self::MUTED;
	}

	/**
	 * Header period text.
	 *
	 * @param array $report Report.
	 * @param bool  $vs     Add "vs <week before>" (not in the first week).
	 * @return string
	 */
	public static function period_text( $report, $vs = true ) {
		$tz    = wp_timezone();
		/* translators: PHP date format of a short day in the weekly shop report ("Oct 6"). */
		$short = __( 'M j', 'opti-behavior' );
		/* translators: PHP date format of a day with its year in the weekly shop report ("Oct 12, 2026"). */
		$long = __( 'M j, Y', 'opti-behavior' );
		$day   = function ( $ymd, $format ) use ( $tz ) {
			$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $ymd . ' 12:00:00', $tz );
			return $date ? wp_date( $format, $date->getTimestamp(), $tz ) : $ymd;
		};
		if ( ! $vs || ! empty( $report['first'] ) ) {
			/* translators: 1: first day, 2: last day. */
			return sprintf( __( '%1$s – %2$s', 'opti-behavior' ), $day( $report['from'], $short ), $day( $report['to'], $long ) );
		}
		/* translators: 1: first day, 2: last day, 3: first day of the week before, 4: its last day. */
		return sprintf( __( '%1$s – %2$s • vs %3$s – %4$s', 'opti-behavior' ), $day( $report['from'], $short ), $day( $report['to'], $long ), $day( $report['prev_from'], $short ), $day( $report['prev_to'], $short ) );
	}

	/**
	 * Address of the Shop screen.
	 *
	 * @param bool $how  Open the "How is this calculated?" block.
	 * @param int  $days Days of the report: nearest period of the screen (7 / 14 / 30 / 90).
	 * @return string
	 */
	public static function shop_url( $how = false, $days = 7 ) {
		$days   = (int) $days;
		$period = $days <= 7 ? 'last7days' : ( $days <= 14 ? 'last14days' : ( $days <= 30 ? 'last30days' : 'last90days' ) );
		$url    = admin_url( 'admin.php?page=' . Opti_Behavior_Shop_Screen::SLUG . '&period=' . $period );
		return $how ? $url . '&how=1#ob-shop-how' : $url;
	}

	/**
	 * Address of the setting that stops the report.
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=traffic-behavior' ) . '#ob-shop-report';
	}

	/**
	 * The four tiles of the week.
	 *
	 * @param array $report Report.
	 * @return array[] { label, value, text ('' = the number, else "Not measured"), line{ text, tone }, red }.
	 */
	public static function tiles( $report ) {
		$t      = $report['totals'];
		$p      = $report['prev'];
		$period = ! empty( $report['period'] );
		$nm     = Opti_Behavior_Shop_Screen::views_not_measured( $t['views'], $t['atc'] );
		$tiles  = array(
			array( __( 'Product views', 'opti-behavior' ), $t['views'], $p['views'], true ),
			array( __( 'Add to cart', 'opti-behavior' ), $t['atc'], $p['atc'], true ),
			array( __( 'Paid orders', 'opti-behavior' ), $t['orders'], $p['orders'], true ),
			array( __( 'Failed payments', 'opti-behavior' ), $t['failed'], $p['failed'], false ),
		);
		$out    = array();
		foreach ( $tiles as $i => $tile ) {
			$off   = 0 === $i && $nm;
			$out[] = array(
				'label' => $tile[0],
				'value' => (int) $tile[1],
				'text'  => $off ? Opti_Behavior_Shop_Screen::not_measured_label() : '',
				'line'  => empty( $report['first'] ) && ! $off ? self::change( $tile[1], $tile[2], $tile[3], false, $period ) : array(
					'text' => '',
					'tone' => 'same',
				),
				'red'   => 3 === $i && $tile[1] > 0,
			);
		}
		return $out;
	}

	/**
	 * Products added to the cart without a product page view, as one line
	 * ('' = none): "Not measured: A (3 adds to cart), B (1 add to cart)."
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public static function unmeasured_line( $report ) {
		$parts = array();
		foreach ( isset( $report['unmeasured'] ) ? (array) $report['unmeasured'] : array() as $p ) {
			$parts[] = sprintf(
				/* translators: 1: product name, 2: number of adds to cart. */
				_n( '%1$s (%2$s add to cart)', '%1$s (%2$s adds to cart)', (int) $p['atc'], 'opti-behavior' ),
				$p['name'],
				number_format_i18n( (int) $p['atc'] )
			);
		}
		if ( empty( $parts ) ) {
			return '';
		}
		/* translators: 1: "Not measured", 2: list of products with their adds to cart. */
		return sprintf( __( '%1$s: %2$s.', 'opti-behavior' ), Opti_Behavior_Shop_Screen::not_measured_label(), implode( ', ', $parts ) ) . ' ' . Opti_Behavior_Shop_Screen::not_measured_note();
	}

	/**
	 * Whether the adds to cart of a period are too many to act on (pure but for
	 * the filter). Add events, not quantities, against all visits of the period:
	 * more adds than product views is normal (pricing page, list button) and is
	 * not a criterion. The figure stays printed; advice and estimates built on
	 * it stop. Filter `opti_behavior_shop_adds_per_visit_limit` moves the ratio.
	 *
	 * @param int $atc    Add events of the period.
	 * @param int $visits Visits of the period.
	 * @return bool
	 */
	public static function adds_look_suspect( $atc, $visits ) {
		$atc = max( 0, (int) $atc );
		if ( $atc < self::SUSPECT_MIN_ADDS ) {
			return false;
		}
		$limit = (float) apply_filters( 'opti_behavior_shop_adds_per_visit_limit', self::SUSPECT_ADDS_PER_VISIT );

		return $atc > $limit * max( 0, (int) $visits );
	}

	/**
	 * Sentence of adds to cart "to check": says what is known, no proven cause.
	 *
	 * @param int $atc    Add events of the period.
	 * @param int $visits Visits of the period.
	 * @return string
	 */
	public static function suspect_sentence( $atc, $visits ) {
		return sprintf(
			/* translators: 1: adds to cart, 2: visits of the period. */
			_n(
				'%1$s adds to cart for %2$s visit is unusually high: the count may include robots or repeated requests. Compare it with your WooCommerce orders before changing the cart or the checkout.',
				'%1$s adds to cart for %2$s visits is unusually high: the count may include robots or repeated requests. Compare it with your WooCommerce orders before changing the cart or the checkout.',
				(int) $visits,
				'opti-behavior'
			),
			number_format_i18n( (int) $atc ),
			number_format_i18n( (int) $visits )
		);
	}

	/**
	 * Sentence of the "no paid order" box.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	private static function no_orders_sentence( $report ) {
		return sprintf(
			/* translators: 1: product page views, 2: adds to cart. */
			__( 'Your product pages still got %1$s views and %2$s adds to cart: the Shop screen shows where visitors stopped.', 'opti-behavior' ),
			number_format_i18n( $report['totals']['views'] ),
			number_format_i18n( $report['totals']['atc'] )
		);
	}

	/**
	 * HTML e-mail (frame of views/emails/report-email-html.php, design OPT-1192).
	 *
	 * @param array      $report Report.
	 * @param array|null $pro    Pro block.
	 * @return string
	 */
	public static function render_html( $report, $pro = null ) {
		$site  = $report['site'];
		$first = ! empty( $report['first'] );
		$title = __( 'Weekly shop report', 'opti-behavior' );

		$h  = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
		$h .= '<html xmlns="http://www.w3.org/1999/xhtml" lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head>';
		$h .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1.0" /><meta http-equiv="X-UA-Compatible" content="IE=edge" />';
		$h .= '<title>' . esc_html( $title . ' • ' . $site ) . '</title>';
		$h .= '<!--[if mso]><style type="text/css">table { border-collapse: collapse; }</style><![endif]-->';
		$h .= '<style type="text/css">@media only screen and (max-width: 480px) { .ob-m-outer { padding: 16px 8px !important; } .ob-m-head { padding: 28px 20px !important; } .ob-m-head h1 { font-size: 22px !important; } .ob-m-pad { padding-left: 16px !important; padding-right: 16px !important; } .ob-m-tile { padding: 4px !important; } .ob-m-cell { display: block !important; width: auto !important; } .ob-m-val { text-align: left !important; padding-top: 0 !important; border-top: 0 !important; } }</style>';
		$h .= '</head><body ' . self::style( 'margin: 0; padding: 0; background-color: #f4f4f5; -webkit-font-smoothing: antialiased;' ) . '>';
		$h .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="background-color: #f4f4f5;"><tr><td class="ob-m-outer" align="center" style="padding: 40px 20px;">';
		$h .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden;">';

		// Header.
		$h .= '<tr><td class="ob-m-head" bgcolor="' . self::BRAND . '" style="background-color: ' . self::BRAND . '; background-image: linear-gradient(135deg, ' . self::BRAND . ' 0%, #7c3aed 100%); padding: 32px 40px 36px; text-align: center;">';
		$h .= Opti_Behavior_Report_Mailer::brand_header_html();
		$h .= '<h1 ' . self::style( 'color: #ffffff; margin: 0 0 8px 0; font-size: 26px; font-weight: 700; letter-spacing: -0.5px;' ) . '>' . esc_html( $title ) . '</h1>';
		$h .= '<p ' . self::style( 'color: #e0e7ff; margin: 0; font-size: 14px; line-height: 1.5;' ) . '>' . esc_html( self::period_text( $report ) ) . '<br />' . esc_html( $site ) . '</p></td></tr>';

		// Tiles.
		$h .= '<tr><td class="ob-m-pad" style="padding: 32px 34px 20px;">' . self::section_head( __( 'This week in your shop', 'opti-behavior' ) );
		$h .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">';
		foreach ( array_chunk( self::tiles( $report ), 2 ) as $pair ) {
			$h .= '<tr>';
			foreach ( $pair as $tile ) {
				$h .= '<td class="ob-m-tile" width="50%" valign="top" style="padding: 6px;"><table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="background-color: #f9fafb; border: 1px solid ' . self::LINE . '; border-radius: 8px;"><tr><td style="padding: 18px 20px;">';
				$h .= '<div ' . self::style( 'font-size: 11px; font-weight: 600; color: ' . self::MUTED . '; text-transform: uppercase; letter-spacing: 0.5px;' ) . '>' . esc_html( $tile['label'] ) . '</div>';
				$h .= '<div ' . self::style( 'font-size: 30px; font-weight: 700; color: ' . ( $tile['red'] ? self::BAD : self::INK ) . '; line-height: 1.2; margin-top: 6px;' ) . '>' . esc_html( '' !== $tile['text'] ? $tile['text'] : number_format_i18n( $tile['value'] ) ) . '</div>';
				if ( '' !== $tile['line']['text'] ) {
					$h .= '<div ' . self::style( 'font-size: 12px; color: ' . self::tone_color( $tile['line']['tone'] ) . '; margin-top: 6px; white-space: nowrap;' ) . '>' . esc_html( $tile['line']['text'] ) . '</div>';
				}
				$h .= '</td></tr></table></td>';
			}
			$h .= '</tr>';
		}
		$h .= '</table>';
		if ( $first ) {
			$h .= '<p ' . self::style( 'margin: 10px 0 0 6px; font-size: 12px; line-height: 1.5; color: ' . self::MUTED . ';' ) . '>' . esc_html__( "First week of counting: the comparison with the previous week starts in next week's report.", 'opti-behavior' ) . '</p>';
		} elseif ( 0 === $report['totals']['orders'] ) {
			$h .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin-top: 12px;"><tr><td style="padding: 14px 16px; background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px;">';
			$h .= '<p ' . self::style( 'margin: 0; font-size: 14px; line-height: 1.5; color: #1e3a8a;' ) . '><strong>' . esc_html__( 'No paid order this week.', 'opti-behavior' ) . '</strong> ' . esc_html( self::no_orders_sentence( $report ) ) . '</p>';
			$h .= '</td></tr></table>';
		}
		$unmeasured = self::unmeasured_line( $report );
		if ( '' !== $unmeasured ) {
			$h .= '<p ' . self::style( 'margin: 10px 0 0 6px; font-size: 12px; line-height: 1.5; color: ' . self::MUTED . ';' ) . '>' . esc_html( $unmeasured ) . '</p>';
		}
		$h .= '</td></tr>';

		// Pro block.
		if ( null !== $pro ) {
			$h .= $pro['html']; // Escaped by the Pro plugin (filter contract).
		}

		// Products losing the most visits.
		if ( ! empty( $report['products'] ) ) {
			$th = 'padding: 12px 14px; font-size: 11px; font-weight: 600; color: ' . self::MUTED . '; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid ' . self::LINE . ';';
			$h .= '<tr><td class="ob-m-pad" style="padding: ' . ( null !== $pro ? '0' : '12px' ) . ' 40px 32px;">' . self::section_head( __( 'Products losing the most visits', 'opti-behavior' ) );
			$h .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="border: 1px solid ' . self::LINE . '; border-radius: 8px; border-collapse: separate;">';
			$h .= '<tr style="background-color: #f9fafb;"><th align="left" ' . self::style( $th . ' text-align: left; padding-left: 16px;' ) . '>' . esc_html__( 'Product', 'opti-behavior' ) . '</th><th align="right" ' . self::style( $th . ' text-align: right; padding-right: 16px; width: 96px;' ) . '>' . esc_html__( 'Lost visits', 'opti-behavior' ) . '</th></tr>';
			$last = count( $report['products'] ) - 1;
			foreach ( $report['products'] as $i => $p ) {
				$line   = $i < $last ? ' border-bottom: 1px solid ' . self::LINE . ';' : '';
				$change = ( $first || null === $p['prev_lost'] ) ? array( 'text' => '' ) : self::change( $p['lost'], (int) $p['prev_lost'], false, true );
				$h     .= '<tr><td style="padding: 14px 16px;' . $line . '"><table cellpadding="0" cellspacing="0" border="0" role="presentation"><tr>';
				$h     .= '<td valign="top" ' . self::style( 'width: 22px; font-size: 13px; line-height: 1.4; font-weight: 700; color: ' . self::MUTED . ';' ) . '>' . esc_html( (string) ( $i + 1 ) ) . '</td>';
				$h     .= '<td ' . self::style( 'font-size: 14px; line-height: 1.4; color: ' . self::INK . ';' ) . '>' . esc_html( $p['name'] ) . '<div ' . self::style( 'font-size: 12px; color: ' . self::MUTED . '; margin-top: 2px;' ) . '>' . esc_html(
					sprintf(
						/* translators: %s: number of product page views. */
						_n( '%s view', '%s views', $p['views'], 'opti-behavior' ),
						number_format_i18n( $p['views'] )
					)
				) . '</div></td></tr></table></td>';
				$h .= '<td align="right" valign="top" ' . self::style( 'padding: 14px 16px; text-align: right; font-size: 14px; line-height: 1.4; font-weight: 700; color: ' . self::INK . ';' . $line ) . '>' . esc_html( number_format_i18n( $p['lost'] ) );
				if ( '' !== $change['text'] ) {
					$h .= '<div ' . self::style( 'font-size: 12px; color: ' . self::tone_color( $change['tone'] ) . '; margin-top: 4px; white-space: nowrap;' ) . '>' . esc_html( $change['text'] ) . '</div>';
				}
				$h .= '</td></tr>';
			}
			$h .= '</table>';
			$note = __( 'Lost visits: views of the product page without an add to cart.', 'opti-behavior' );
			if ( ! $first ) {
				$note .= ' ' . __( 'Arrows compare with last week.', 'opti-behavior' );
			}
			$h .= '<p ' . self::style( 'margin: 10px 0 0 0; font-size: 12px; line-height: 1.5; color: ' . self::MUTED . ';' ) . '>' . esc_html( $note ) . '</p></td></tr>';
		}

		// Button.
		$h .= '<tr><td style="padding: 0 40px 32px; text-align: center;"><table cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin: 0 auto;"><tr><td bgcolor="' . self::BRAND . '" style="background-color: ' . self::BRAND . '; border-radius: 8px;">';
		$h .= '<a href="' . esc_url( self::shop_url() ) . '" ' . self::style( 'display: inline-block; padding: 16px 32px; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 600;' ) . '>' . esc_html__( 'Open the Shop screen', 'opti-behavior' ) . '</a>';
		$h .= '</td></tr></table></td></tr>';

		// Footer.
		$h .= '<tr><td class="ob-m-pad" bgcolor="#f9fafb" style="background-color: #f9fafb; padding: 24px 40px; text-align: center; border-top: 1px solid ' . self::LINE . ';">';
		$h .= '<p ' . self::style( 'margin: 0 0 8px 0; font-size: 13px; line-height: 1.5; color: ' . self::MUTED . ';' ) . '>' . esc_html(
			sprintf(
				/* translators: %s: site name. */
				__( 'Sent to the administrator of %s by Opti-Behavior. Totals only: no visitor details.', 'opti-behavior' ),
				$site
			)
		) . '</p>';
		$h .= '<p ' . self::style( 'margin: 0 0 8px 0; font-size: 12px; line-height: 1.5; color: ' . self::MUTED . ';' ) . '>' . Opti_Behavior_Report_Mailer::brand_footer_line( 'shop-report', self::BRAND ) . '</p>';
		$h .= '<p ' . self::style( 'margin: 0; font-size: 13px;' ) . '><a href="' . esc_url( self::settings_url() ) . '" style="color: ' . self::BRAND . '; text-decoration: underline; font-weight: 500;">' . esc_html__( 'Turn off this report', 'opti-behavior' ) . '</a></p>';
		$h .= '</td></tr></table></td></tr></table></body></html>';
		return $h;
	}

	/**
	 * Plain-text part.
	 *
	 * @param array      $report Report.
	 * @param array|null $pro    Pro block.
	 * @return string
	 */
	public static function render_text( $report, $pro = null ) {
		$first = ! empty( $report['first'] );
		$lines = array(
			__( 'Weekly shop report', 'opti-behavior' ) . ' - ' . $report['site'],
			self::period_text( $report ),
			'',
			__( 'This week in your shop', 'opti-behavior' ),
		);
		foreach ( self::tiles( $report ) as $tile ) {
			$lines[] = '- ' . $tile['label'] . ': ' . ( '' !== $tile['text'] ? $tile['text'] : number_format_i18n( $tile['value'] ) ) . ( '' !== $tile['line']['text'] ? ' (' . $tile['line']['text'] . ')' : '' );
		}
		if ( $first ) {
			$lines[] = __( "First week of counting: the comparison with the previous week starts in next week's report.", 'opti-behavior' );
		} elseif ( 0 === $report['totals']['orders'] ) {
			$lines[] = __( 'No paid order this week.', 'opti-behavior' ) . ' ' . self::no_orders_sentence( $report );
		}
		$unmeasured = self::unmeasured_line( $report );
		if ( '' !== $unmeasured ) {
			$lines[] = $unmeasured;
		}
		if ( null !== $pro && ! empty( $pro['text'] ) ) {
			$lines[] = '';
			$lines[] = (string) $pro['text'];
		}
		if ( ! empty( $report['products'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Products losing the most visits', 'opti-behavior' );
			foreach ( $report['products'] as $i => $p ) {
				$change  = ( $first || null === $p['prev_lost'] ) ? array( 'text' => '' ) : self::change( $p['lost'], (int) $p['prev_lost'], false, true );
				$lines[] = ( $i + 1 ) . '. ' . $p['name'] . ': ' . __( 'Lost visits', 'opti-behavior' ) . ' ' . number_format_i18n( $p['lost'] ) . ( '' !== $change['text'] ? ' (' . $change['text'] . ')' : '' ) . ', ' . sprintf(
					/* translators: %s: number of product page views. */
					_n( '%s view', '%s views', $p['views'], 'opti-behavior' ),
					number_format_i18n( $p['views'] )
				);
			}
			$lines[] = __( 'Lost visits: views of the product page without an add to cart.', 'opti-behavior' );
		}
		$lines[] = '';
		$lines[] = __( 'Open the Shop screen', 'opti-behavior' ) . ': ' . self::shop_url();
		$lines[] = '';
		/* translators: %s: site name. */
		$lines[] = sprintf( __( 'Sent to the administrator of %s by Opti-Behavior. Totals only: no visitor details.', 'opti-behavior' ), $report['site'] );
		$lines[] = Opti_Behavior_Report::brand_footer_text( 'shop-report' );
		$lines[] = __( 'Turn off this report', 'opti-behavior' ) . ': ' . self::settings_url();
		return implode( "\n", $lines );
	}

	/* ---------------------------------------------------------------------
	 * "Shop" block of the scheduled report e-mail (OPT-1735)
	 * ------------------------------------------------------------------- */

	/** Colours of the report e-mail (views/emails/report-email-html.php, dark-mode map). */
	const R_GOOD  = '#16a34a';
	const R_BAD   = '#dc2626';
	const R_MUTED = '#6b7280';

	/**
	 * Colour of a tone in the report e-mail.
	 *
	 * @param string $tone good | bad | same.
	 * @return string
	 */
	private static function block_tone( $tone ) {
		if ( 'good' === $tone ) {
			return self::R_GOOD;
		}
		return 'bad' === $tone ? self::R_BAD : self::R_MUTED;
	}

	/**
	 * HTML rows (`<tr>`) of the "Shop" block: 4 tiles of the period, the
	 * "Not measured" line, Pro "Money at stake" (or the grey Pro line for Free
	 * alone), the products losing the most visits, the Shop screen button and
	 * the "What to do" line. Everything escaped here or by the Pro plugin.
	 *
	 * @param array      $report   period_report().
	 * @param array|null $pro      pro_part() ( null = no Pro block ).
	 * @param bool       $pro_line Print the grey "available with Pro" line (Free alone).
	 * @return string
	 */
	public static function render_block_html( $report, $pro = null, $pro_line = false ) {
		$first = ! empty( $report['first'] );
		$days  = isset( $report['days'] ) ? (int) $report['days'] : 7;

		$h  = '<!-- Shop (OPT-1735) -->';
		$h .= '<tr><td style="padding: 0 40px 24px;">';
		$h .= '<h2 ' . self::style( 'margin: 0 0 16px 0; font-size: 16px; font-weight: 600; color: ' . self::INK . ';' ) . '>' . esc_html__( 'Shop', 'opti-behavior' ) . '</h2>';
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
		foreach ( array_chunk( self::tiles( $report ), 2 ) as $pair ) {
			$h .= '<tr>';
			foreach ( $pair as $tile ) {
				$off   = '' !== $tile['text'];
				$value = $off ? $tile['text'] : number_format_i18n( $tile['value'] );
				// "Not measured" = an absence, not a figure: muted, same line box as a number so the tiles of a row stay level.
				$size  = $off ? '18px' : '24px';
				$color = $off ? self::R_MUTED : ( $tile['red'] ? self::R_BAD : self::INK );
				$h    .= '<td class="ob-col" width="50%" valign="top" style="padding: 4px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;"><tr><td style="padding: 14px 16px;">';
				$h    .= '<div ' . self::style( 'font-size: 11px; font-weight: 600; color: ' . self::R_MUTED . '; text-transform: uppercase; letter-spacing: 0.5px;' ) . '>' . esc_html( $tile['label'] ) . '</div>';
				$h    .= '<div ' . self::style( 'font-size: ' . $size . '; font-weight: 700; color: ' . $color . '; line-height: 32px; margin-top: 4px;' ) . '>' . esc_html( $value ) . '</div>';
				if ( '' !== $tile['line']['text'] ) {
					$h .= '<div ' . self::style( 'font-size: 12px; line-height: 18px; color: ' . self::block_tone( $tile['line']['tone'] ) . '; margin-top: 4px;' ) . '>' . esc_html( $tile['line']['text'] ) . '</div>';
				} elseif ( ! $first ) {
					$h .= '<div style="font-size: 12px; line-height: 18px; margin-top: 4px;">&nbsp;</div>';
				}
				$h .= '</td></tr></table></td>';
			}
			$h .= '</tr>';
		}
		$h         .= '</table>';
		$unmeasured = self::unmeasured_line( $report );
		if ( '' !== $unmeasured ) {
			$h .= '<p ' . self::style( 'margin: 10px 0 0 4px; font-size: 12px; line-height: 1.5; color: ' . self::R_MUTED . ';' ) . '>' . esc_html( $unmeasured ) . '</p>';
		}
		foreach ( isset( $report['add_notes'] ) ? (array) $report['add_notes'] : array() as $note ) {
			$h .= '<p ' . self::style( 'margin: 10px 0 0 4px; font-size: 12px; line-height: 1.5; color: ' . self::R_MUTED . ';' ) . '>' . esc_html( $note ) . '</p>';
		}
		if ( $first ) {
			$h .= '<p ' . self::style( 'margin: 10px 0 0 4px; font-size: 12px; line-height: 1.5; color: ' . self::R_MUTED . ';' ) . '>' . esc_html__( 'First period of counting: the comparison starts with the next report.', 'opti-behavior' ) . '</p>';
		}
		$h .= '</td></tr>';

		if ( null !== $pro && ! empty( $pro['html'] ) ) {
			$h .= $pro['html']; // Escaped by the Pro plugin (filter contract): `<tr>` rows.
		} elseif ( $pro_line && Opti_Behavior_Shop_Screen::lost_shown( $report ) ) {
			// OPT-3004: no money line while the lost visits it prices are not measured.
			$h .= '<tr><td style="padding: 0 40px 24px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px;"><tr><td ' . self::style( 'padding: 12px 16px;' ) . '>';
			$h .= '<span ' . self::style( 'font-size: 14px; font-weight: 700; color: ' . self::INK . ';' ) . '>' . esc_html__( 'Money at stake', 'opti-behavior' ) . '</span>';
			$h .= ' <span style="display: inline-block; margin-left: 4px; padding: 1px 6px; font-size: 10px; font-weight: 700; letter-spacing: 0.4px; color: #4338ca; background-color: #eef2ff; border: 1px solid #c7d2fe; border-radius: 999px; vertical-align: 1px;">PRO</span>';
			$h .= '<div ' . self::style( 'margin-top: 4px; font-size: 13px; color: #52525b;' ) . '>' . esc_html__( 'Available with Opti-Behavior Pro', 'opti-behavior' ) . ' &#183; <a href="' . esc_url( self::trial_url() ) . '" style="color: #4f46e5; text-decoration: none; font-weight: 600;">' . esc_html__( 'See the Pro trial', 'opti-behavior' ) . '</a></div>';
			$h .= '</td></tr></table></td></tr>';
		}

		$h .= '<tr><td style="padding: 0 40px 32px;">';
		if ( ! empty( $report['products'] ) ) {
			$th = 'padding: 10px 14px; font-size: 11px; font-weight: 600; color: ' . self::R_MUTED . '; text-transform: uppercase; letter-spacing: 0.5px;';
			$h .= '<div ' . self::style( 'margin: 0 0 10px 0; font-size: 14px; font-weight: 600; color: ' . self::INK . ';' ) . '>' . esc_html__( 'Products losing the most visits', 'opti-behavior' ) . '</div>';
			$h .= '<table class="ob-t" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e7eb; border-radius: 8px; border-collapse: separate;">';
			$h .= '<tr style="background-color: #f9fafb;"><td ' . self::style( $th ) . '>' . esc_html__( 'Product', 'opti-behavior' ) . '</td><td ' . self::style( $th . ' text-align: right;' ) . '>' . esc_html__( 'Lost visits', 'opti-behavior' ) . '</td></tr>';
			foreach ( $report['products'] as $i => $p ) {
				$change = ( $first || null === $p['prev_lost'] ) ? array( 'text' => '' ) : self::change( $p['lost'], (int) $p['prev_lost'], false, true, true );
				$h     .= '<tr><td ' . self::style( 'padding: 12px 14px; font-size: 14px; line-height: 1.4; color: ' . self::INK . '; border-top: 1px solid #e5e7eb; word-break: break-word;' ) . '>' . esc_html( ( $i + 1 ) . '. ' . $p['name'] );
				$h     .= '<div ' . self::style( 'font-size: 12px; color: ' . self::R_MUTED . '; margin-top: 2px;' ) . '>' . esc_html(
					sprintf(
						/* translators: %s: number of product page views. */
						_n( '%s view', '%s views', $p['views'], 'opti-behavior' ),
						number_format_i18n( $p['views'] )
					)
				) . '</div></td>';
				$h .= '<td ' . self::style( 'padding: 12px 14px; text-align: right; font-size: 14px; font-weight: 700; color: ' . self::INK . '; border-top: 1px solid #e5e7eb; vertical-align: top;' ) . '>' . esc_html( number_format_i18n( $p['lost'] ) );
				if ( '' !== $change['text'] ) {
					$h .= '<div ' . self::style( 'font-size: 12px; font-weight: 400; color: ' . self::block_tone( $change['tone'] ) . '; margin-top: 2px; white-space: nowrap;' ) . '>' . esc_html( $change['text'] ) . '</div>';
				}
				$h .= '</td></tr>';
			}
			$h .= '</table>';
			$h .= '<p ' . self::style( 'margin: 8px 0 0 0; font-size: 12px; line-height: 1.5; color: ' . self::R_MUTED . ';' ) . '>' . esc_html__( 'Lost visits: views of the product page without an add to cart.', 'opti-behavior' ) . '</p>';
		}
		if ( class_exists( 'Opti_Behavior_Report_Mailer' ) ) {
			$h .= wp_kses_post( Opti_Behavior_Report_Mailer::hint_html( Opti_Behavior_Report_Mailer::block_hint( 'shop', $report ) ) );
		}
		$h .= '<p ' . self::style( 'margin: 14px 0 0 0; font-size: 13px;' ) . '><a href="' . esc_url( self::shop_url( false, $days ) ) . '" style="color: #4f46e5; text-decoration: none; font-weight: 600;">' . esc_html__( 'Open the Shop screen', 'opti-behavior' ) . '</a></p>';
		$h .= '</td></tr>';
		return $h;
	}

	/**
	 * Plain-text lines of the "Shop" block.
	 *
	 * @param array      $report   period_report().
	 * @param array|null $pro      pro_part().
	 * @param bool       $pro_line Free alone: the "available with Pro" line.
	 * @return string Ends with a blank line.
	 */
	public static function render_block_text( $report, $pro = null, $pro_line = false ) {
		$first = ! empty( $report['first'] );
		$days  = isset( $report['days'] ) ? (int) $report['days'] : 7;
		$lines = array( strtoupper( __( 'Shop', 'opti-behavior' ) ) );
		foreach ( self::tiles( $report ) as $tile ) {
			$lines[] = '- ' . $tile['label'] . ': ' . ( '' !== $tile['text'] ? $tile['text'] : number_format_i18n( $tile['value'] ) ) . ( '' !== $tile['line']['text'] ? ' (' . $tile['line']['text'] . ')' : '' );
		}
		$unmeasured = self::unmeasured_line( $report );
		if ( '' !== $unmeasured ) {
			$lines[] = $unmeasured;
		}
		foreach ( isset( $report['add_notes'] ) ? (array) $report['add_notes'] : array() as $note ) {
			$lines[] = $note;
		}
		if ( $first ) {
			$lines[] = __( 'First period of counting: the comparison starts with the next report.', 'opti-behavior' );
		}
		if ( null !== $pro && ! empty( $pro['text'] ) ) {
			$lines[] = (string) $pro['text'];
		} elseif ( $pro_line && Opti_Behavior_Shop_Screen::lost_shown( $report ) ) {
			// OPT-3004: no money line while the lost visits it prices are not measured.
			$lines[] = __( 'Money at stake', 'opti-behavior' ) . ': ' . __( 'Available with Opti-Behavior Pro', 'opti-behavior' ) . ' - ' . self::trial_url();
		}
		if ( ! empty( $report['products'] ) ) {
			$lines[] = __( 'Products losing the most visits', 'opti-behavior' );
			foreach ( $report['products'] as $i => $p ) {
				$change  = ( $first || null === $p['prev_lost'] ) ? array( 'text' => '' ) : self::change( $p['lost'], (int) $p['prev_lost'], false, true, true );
				$lines[] = ( $i + 1 ) . '. ' . $p['name'] . ': ' . __( 'Lost visits', 'opti-behavior' ) . ' ' . number_format_i18n( $p['lost'] ) . ( '' !== $change['text'] ? ' (' . $change['text'] . ')' : '' ) . ', ' . sprintf(
					/* translators: %s: number of product page views. */
					_n( '%s view', '%s views', $p['views'], 'opti-behavior' ),
					number_format_i18n( $p['views'] )
				);
			}
			$lines[] = __( 'Lost visits: views of the product page without an add to cart.', 'opti-behavior' );
		}
		$out = implode( "\n", $lines ) . "\n";
		if ( class_exists( 'Opti_Behavior_Report_Mailer' ) ) {
			$out .= Opti_Behavior_Report_Mailer::hint_text( Opti_Behavior_Report_Mailer::block_hint( 'shop', $report ) );
		}
		return $out . __( 'Open the Shop screen', 'opti-behavior' ) . ': ' . self::shop_url( false, $days ) . "\n\n";
	}

	/**
	 * Pro trial page of the "Money at stake" line (Free alone).
	 *
	 * @return string
	 */
	private static function trial_url() {
		return class_exists( 'Opti_Behavior_Report_Signals' ) ? Opti_Behavior_Report_Signals::trial_url( 'shop' ) : 'https://optiuser.com/opti-behavior/pro-trial/';
	}

	/* ---------------------------------------------------------------------
	 * Setting (inside the "Shop events" block of Settings)
	 * ------------------------------------------------------------------- */

	/**
	 * Line printed in the Shop events settings form, where the "Weekly shop
	 * e-mail" checkbox was (OPT-1735): the numbers are in the scheduled report.
	 *
	 * @return void
	 */
	public static function render_setting_row() {
		$url = admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=scheduled-reports' );
		?>
		<p class="description ob-geo-db-text" id="ob-shop-report">
			<?php esc_html_e( 'Shop numbers are included in your scheduled report e-mail.', 'opti-behavior' ); ?>
			<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Scheduled reports', 'opti-behavior' ); ?></a>
		</p>
		<?php
	}
}
