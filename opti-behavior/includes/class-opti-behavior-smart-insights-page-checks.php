<?php
/**
 * Smart Insights Page Checks Class
 *
 * @package    Opti_Behavior
 * @subpackage Smart_Insights
 * @since      1.9.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Things to check" of the Free page cards (OPT-412, lot B1) and of the Free
 * engagement, mobile bounce and traffic spike cards (OPT-413, lot B2).
 *
 * Measured with Free data only, on the card's page and period:
 * - `recent_change`: the card metric against the previous period of the same length;
 * - `no_click`: share of the page's visits with at least one click vs the site;
 * - `short_visit`: average time on page vs the site;
 * - `low_reach`: average scroll depth vs the site (not on the scroll card).
 * The engagement card skips the check its own rule already measured. The
 * mobile bounce card (a device, the whole site) compares mobile with
 * computers: `mobile_short_visit`, `mobile_low_reach`, `mobile_no_click`,
 * `mobile_recent_change`. The traffic spike card compares the page with its
 * previous period: `spike_engagement_held`, `spike_clicks_held`.
 *
 * Rows use the shared `correlation.checks` format (see the correlator's
 * normalize_checks()). Called by the correlator only when no provider
 * answered the `checks` probe. Every verdict needs its two measurements:
 * a missing value is `not_measured`, never a 0.
 *
 * @since 1.9.2
 */
class Opti_Behavior_Smart_Insights_Page_Checks {

	/**
	 * Card signal => metric of the card (the one `recent_change` compares).
	 *
	 * The scroll card's real id is `low_scroll_depth_important_page`
	 * (Signal_Low_Scroll_Depth_Page::SIGNAL_ID); `low_scroll_depth_page` is
	 * kept as an alias for older callers (OPT-531: the scroll card never got
	 * its checks).
	 */
	const SIGNALS = array(
		'basic_bounce_alert'              => 'bounce_rate',
		'high_exit_rate_page'             => 'exit_rate',
		'low_scroll_depth_important_page' => 'avg_scroll_depth',
		'low_scroll_depth_page'           => 'avg_scroll_depth',
		'high_traffic_low_engagement'     => 'bounce_rate',
	);

	/**
	 * Device card compared mobile vs computers (OPT-413).
	 */
	const MOBILE_SIGNAL = 'basic_mobile_bounce_warning';

	/**
	 * Page card compared with its previous period (OPT-413).
	 */
	const SPIKE_SIGNAL = 'traffic_spike_observation';

	/**
	 * Device keys of the device aggregator, as the mobile bounce signal reads them.
	 */
	const MOBILE_KEYS  = array( 'mobile', 'phone', 'smartphone' );
	const DESKTOP_KEYS = array( 'desktop', 'computer' );

	/**
	 * Points of change that make `recent_change` likely (same as the
	 * catalogue's engagement_decay: 10 points of rate, 15 points of scroll).
	 */
	const RECENT_BOUNCE_POINTS = 10;
	const RECENT_EXIT_POINTS   = 10;
	const RECENT_SCROLL_POINTS = 15;

	/**
	 * `no_click` is likely when the page's click share is under this share of
	 * the site's (catalogue CTA rule 0.6).
	 */
	const CLICK_SHARE_RATIO = 0.6;

	/**
	 * `short_visit` is likely when the page's time is under this share of the
	 * site's (catalogue time rule 0.5).
	 */
	const TIME_RATIO = 0.5;

	/**
	 * `low_reach` is likely when the page's average scroll is this many points
	 * (or more) under the site's.
	 */
	const SCROLL_GAP_POINTS = 20;

	/**
	 * Minimum visits on the page (current or previous period) for a verdict.
	 */
	const MIN_SESSIONS = 50;

	/**
	 * Site rows memoized per range + spam scope (one generation run asks for
	 * every page card of the same period).
	 *
	 * @var array
	 */
	private static $site_memo = array();

	/**
	 * Whether a signal gets page checks.
	 *
	 * @param string $signal_id Signal id.
	 * @return bool
	 */
	public static function supports( $signal_id ) {
		return is_string( $signal_id ) && ( isset( self::SIGNALS[ $signal_id ] ) || self::MOBILE_SIGNAL === $signal_id || self::SPIKE_SIGNAL === $signal_id );
	}

	/**
	 * Thresholds, filterable as one array.
	 *
	 * @return array
	 */
	public static function thresholds() {
		$defaults = array(
			'recent_bounce_points' => self::RECENT_BOUNCE_POINTS,
			'recent_exit_points'   => self::RECENT_EXIT_POINTS,
			'recent_scroll_points' => self::RECENT_SCROLL_POINTS,
			'click_share_ratio'    => self::CLICK_SHARE_RATIO,
			'time_ratio'           => self::TIME_RATIO,
			'scroll_gap_points'    => self::SCROLL_GAP_POINTS,
			'min_sessions'         => self::MIN_SESSIONS,
		);

		/**
		 * Filter the thresholds of the Free page checks.
		 *
		 * @since 1.9.2
		 *
		 * @param array $defaults Thresholds.
		 */
		$filtered = apply_filters( 'opti_behavior_smart_insights_page_check_thresholds', $defaults );
		$filtered = is_array( $filtered ) ? $filtered : array();

		$thresholds = array();
		foreach ( $defaults as $key => $value ) {
			$thresholds[ $key ] = isset( $filtered[ $key ] ) && is_numeric( $filtered[ $key ] ) && $filtered[ $key ] >= 0 ? $filtered[ $key ] + 0 : $value;
		}
		$thresholds['min_sessions'] = max( 1, (int) $thresholds['min_sessions'] );

		return $thresholds;
	}

	/**
	 * Build the raw checks of one page card.
	 *
	 * @param string $signal_id  Card signal id.
	 * @param array  $metrics    Raw candidate metric row (aggregate_page_metrics, with trend).
	 * @param array  $date_range Card period { from, to }.
	 * @param array  $args       Generation args (exclude_spam).
	 * @param array  $insight    Card insight (detection), optional.
	 * @return array Raw checks (the correlator normalizes them), empty for other signals.
	 */
	public function build( $signal_id, $metrics, $date_range, $args = array(), $insight = array() ) {
		if ( ! self::supports( $signal_id ) ) {
			return array();
		}

		$metrics    = is_array( $metrics ) ? $metrics : array();
		$date_range = is_array( $date_range ) ? $date_range : array();
		$args       = is_array( $args ) ? $args : array();
		$t          = self::thresholds();

		$from         = isset( $date_range['from'] ) ? (string) $date_range['from'] : '';
		$to           = isset( $date_range['to'] ) ? (string) $date_range['to'] : '';
		$exclude_spam = array_key_exists( 'exclude_spam', $args ) && null !== $args['exclude_spam'] ? (bool) $args['exclude_spam'] : $this->is_spam_excluded();

		if ( self::MOBILE_SIGNAL === $signal_id ) {
			return $this->build_mobile( $from, $to, $exclude_spam, $t );
		}
		if ( self::SPIKE_SIGNAL === $signal_id ) {
			return $this->build_spike( $metrics, $from, $to, $exclude_spam, $t );
		}

		$skip         = self::skipped_checks( $signal_id, $insight );
		$site         = $this->get_site_row( $from, $to, $exclude_spam );
		$page_id      = isset( $metrics['page_id'] ) ? absint( $metrics['page_id'] ) : 0;
		$sessions     = isset( $metrics['sessions'] ) ? max( 0, (int) $metrics['sessions'] ) : 0;
		$metric_key   = self::SIGNALS[ $signal_id ];

		// recent_change: the card metric, now vs the previous period.
		$trend    = isset( $metrics['trend'][ $metric_key ] ) && is_array( $metrics['trend'][ $metric_key ] ) ? $metrics['trend'][ $metric_key ] : array();
		$previous = isset( $metrics['previous_period_metrics'] ) && is_array( $metrics['previous_period_metrics'] ) ? $metrics['previous_period_metrics'] : array();
		$since    = isset( $metrics['previous_period_range']['from'] ) ? (string) $metrics['previous_period_range']['from'] : '';
		$checks   = array(
			self::recent_change_check(
				$metric_key,
				isset( $metrics[ $metric_key ] ) ? $metrics[ $metric_key ] : null,
				$sessions,
				isset( $trend['previous'] ) ? $trend['previous'] : ( isset( $previous[ $metric_key ] ) ? $previous[ $metric_key ] : null ),
				isset( $previous['sessions'] ) ? (int) $previous['sessions'] : 0,
				$since,
				$t,
				$this->report_url( $page_id, 'avg_scroll_depth' === $metric_key ? 'scroll' : 'click', $from, $to, $exclude_spam )
			),
		);

		// no_click: share of visits with a click, page vs site.
		$click_sessions = isset( $metrics['click_sessions'] ) ? max( 0, (int) $metrics['click_sessions'] ) : 0;
		$top_element    = '';
		if ( $sessions >= $t['min_sessions'] && ! empty( $site['click_sessions'] ) && ! empty( $site['sessions'] ) && $page_id > 0
			&& $click_sessions / $sessions < $t['click_share_ratio'] * ( $site['click_sessions'] / $site['sessions'] ) ) {
			$top_element = $this->get_top_clicked_element( $page_id, $from, $to, $exclude_spam );
		}
		$checks[] = self::no_click_check( $click_sessions, $sessions, $site['click_sessions'], $site['sessions'], $top_element, $t, $this->report_url( $page_id, 'click', $from, $to, $exclude_spam ) );

		// short_visit: average time on page (real samples only), page vs site.
		if ( ! in_array( 'short_visit', $skip, true ) ) {
			$time_samples = isset( $metrics['time_on_page_samples'] ) ? max( 0, (int) $metrics['time_on_page_samples'] ) : 0;
			$page_time    = $time_samples > 0 && isset( $metrics['avg_time_on_page'] ) ? $metrics['avg_time_on_page'] : null;
			$checks[]     = self::short_visit_check( $page_time, $time_samples, $site['avg_time_on_page'], $site['time_samples'], $t, $this->report_url( $page_id, 'scroll', $from, $to, $exclude_spam ) );
		}

		// low_reach: not on a card whose rule already says it.
		if ( ! in_array( 'low_reach', $skip, true ) ) {
			$scroll_samples = isset( $metrics['scroll_depth_samples'] ) ? max( 0, (int) $metrics['scroll_depth_samples'] ) : 0;
			$page_scroll    = $scroll_samples > 0 && isset( $metrics['avg_scroll_depth'] ) ? $metrics['avg_scroll_depth'] : null;
			$checks[]       = self::low_reach_check( $page_scroll, $scroll_samples, $site['avg_scroll_depth'], $site['scroll_samples'], $t, $this->report_url( $page_id, 'scroll', $from, $to, $exclude_spam ) );
		}

		return $checks;
	}

	/**
	 * Checks a page card does not repeat because its own rule already measured
	 * them. Pure. The engagement card fires on bounce + scroll (primary rule
	 * and the absolute fallback), bounce + time, or bounce + exit rate.
	 *
	 * @param string $signal_id Card signal id.
	 * @param array  $insight   Card insight (detection.fallback_used).
	 * @return string[] Check ids.
	 */
	public static function skipped_checks( $signal_id, $insight ) {
		if ( 'low_scroll_depth_important_page' === $signal_id || 'low_scroll_depth_page' === $signal_id ) {
			return array( 'low_reach' );
		}
		if ( 'high_traffic_low_engagement' !== $signal_id ) {
			return array();
		}

		$fallback = isset( $insight['detection']['fallback_used'] ) && is_string( $insight['detection']['fallback_used'] ) ? $insight['detection']['fallback_used'] : '';
		if ( 'time_on_page' === $fallback ) {
			return array( 'short_visit' );
		}
		if ( 'exit_rate' === $fallback ) {
			return array();
		}

		return array( 'low_reach' );
	}

	/**
	 * Checks of the traffic spike card: did the extra visitors of the page
	 * behave like the visitors of the period before?
	 *
	 * @param array  $metrics      Raw candidate metric row (with previous_period_metrics).
	 * @param string $from         Period start.
	 * @param string $to           Period end.
	 * @param bool   $exclude_spam Spam scope.
	 * @param array  $t            Thresholds.
	 * @return array
	 */
	private function build_spike( $metrics, $from, $to, $exclude_spam, $t ) {
		$page_id  = isset( $metrics['page_id'] ) ? absint( $metrics['page_id'] ) : 0;
		$sessions = isset( $metrics['sessions'] ) ? max( 0, (int) $metrics['sessions'] ) : 0;
		$previous = isset( $metrics['previous_period_metrics'] ) && is_array( $metrics['previous_period_metrics'] ) ? $metrics['previous_period_metrics'] : array();
		$prev_n   = isset( $previous['sessions'] ) ? max( 0, (int) $previous['sessions'] ) : 0;
		$trend    = isset( $metrics['trend']['bounce_rate'] ) && is_array( $metrics['trend']['bounce_rate'] ) ? $metrics['trend']['bounce_rate'] : array();
		$urls     = array(
			'likely'    => $this->dashboard_url(),
			'ruled_out' => $this->report_url( $page_id, 'click', $from, $to, $exclude_spam ),
		);

		return array(
			self::spike_engagement_check(
				isset( $metrics['bounce_rate'] ) ? $metrics['bounce_rate'] : null,
				$sessions,
				isset( $trend['previous'] ) ? $trend['previous'] : ( isset( $previous['bounce_rate'] ) ? $previous['bounce_rate'] : null ),
				$prev_n,
				$t,
				$urls
			),
			self::spike_clicks_check(
				isset( $metrics['click_sessions'] ) ? $metrics['click_sessions'] : 0,
				$sessions,
				isset( $previous['click_sessions'] ) ? $previous['click_sessions'] : 0,
				$prev_n,
				$t,
				$urls
			),
		);
	}

	/**
	 * `spike_engagement_held` verdict: likely when the page bounce rate rose
	 * by recent_bounce_points or more against the previous period. Pure.
	 *
	 * @param float|null $current    Bounce rate now (%).
	 * @param int        $current_n  Visits now.
	 * @param float|null $previous   Bounce rate before (%).
	 * @param int        $previous_n Visits before.
	 * @param array      $t          Thresholds.
	 * @param array      $urls       Action URL per verdict { likely, ruled_out }.
	 * @return array
	 */
	public static function spike_engagement_check( $current, $current_n, $previous, $previous_n, $t, $urls = array() ) {
		$id         = 'spike_engagement_held';
		$label      = __( 'Did the new visitors engage less?', 'opti-behavior' );
		$current_n  = max( 0, (int) $current_n );
		$previous_n = max( 0, (int) $previous_n );

		if ( $previous_n < $t['min_sessions'] ) {
			return self::previous_too_small( $id, $label, $t );
		}
		if ( ! is_numeric( $current ) || ! is_numeric( $previous ) ) {
			$check            = self::not_measured( $id, $label, 'not_tracked' );
			$check['summary'] = __( 'Not measured: this rate is not recorded for this page in both periods.', 'opti-behavior' );
			return $check;
		}

		$current  = (float) $current;
		$previous = (float) $previous;
		$verdict  = $current - $previous >= $t['recent_bounce_points'] ? 'likely' : 'ruled_out';

		return self::row(
			$id,
			$label,
			$verdict,
			/* translators: 1: bounce rate now, 2: visits now, 3: bounce rate before, 4: visits before. */
			sprintf( __( 'Bounce rate %1$s now (n=%2$s) vs %3$s before (n=%4$s).', 'opti-behavior' ), self::percent( $current ), number_format_i18n( $current_n ), self::percent( $previous ), number_format_i18n( $previous_n ) ),
			array( $current, $current_n, $previous ),
			self::spike_action( $verdict ),
			isset( $urls[ $verdict ] ) ? (string) $urls[ $verdict ] : ''
		);
	}

	/**
	 * `spike_clicks_held` verdict: likely when the share of visits with a
	 * click is under click_share_ratio of the previous period's. Pure.
	 *
	 * @param int   $current_clicked  Visits with a click now.
	 * @param int   $current_n        Visits now.
	 * @param int   $previous_clicked Visits with a click before.
	 * @param int   $previous_n       Visits before.
	 * @param array $t                Thresholds.
	 * @param array $urls             Action URL per verdict { likely, ruled_out }.
	 * @return array
	 */
	public static function spike_clicks_check( $current_clicked, $current_n, $previous_clicked, $previous_n, $t, $urls = array() ) {
		$id               = 'spike_clicks_held';
		$label            = __( 'Did the new visitors click less?', 'opti-behavior' );
		$current_n        = max( 0, (int) $current_n );
		$previous_n       = max( 0, (int) $previous_n );
		$current_clicked  = min( $current_n, max( 0, (int) $current_clicked ) );
		$previous_clicked = min( $previous_n, max( 0, (int) $previous_clicked ) );

		if ( $previous_n < $t['min_sessions'] ) {
			return self::previous_too_small( $id, $label, $t );
		}
		if ( $current_n < 1 || $current_clicked + $previous_clicked < 1 ) {
			$check            = self::not_measured( $id, $label, 'not_tracked' );
			$check['summary'] = __( 'Not measured: no click was recorded on this page in either period. Check that the heatmap tracker runs (Setup check).', 'opti-behavior' );
			return $check;
		}

		$current_share  = $current_clicked / $current_n;
		$previous_share = $previous_clicked / $previous_n;
		$verdict        = $current_share < $t['click_share_ratio'] * $previous_share ? 'likely' : 'ruled_out';

		return self::row(
			$id,
			$label,
			$verdict,
			/* translators: 1: share of visits with a click now, 2: visits now, 3: same share before, 4: visits before. */
			sprintf( __( '%1$s of visits clicked something now (n=%2$s) vs %3$s before (n=%4$s).', 'opti-behavior' ), self::percent( $current_share * 100 ), number_format_i18n( $current_n ), self::percent( $previous_share * 100 ), number_format_i18n( $previous_n ) ),
			array( $current_share * 100, $current_n, $previous_share * 100 ),
			self::spike_action( $verdict ),
			isset( $urls[ $verdict ] ) ? (string) $urls[ $verdict ] : ''
		);
	}

	/**
	 * Action of a measured spike check.
	 *
	 * @param string $verdict likely | ruled_out.
	 * @return string
	 */
	private static function spike_action( $verdict ) {
		return 'likely' === $verdict
			? __( 'The extra visitors are less engaged: check where they come from before changing the page.', 'opti-behavior' )
			: __( 'The new traffic behaves like the usual traffic: make sure the next step is visible.', 'opti-behavior' );
	}

	/**
	 * A spike check whose previous period is too small to compare.
	 *
	 * @param string $id    Check id.
	 * @param string $label Label.
	 * @param array  $t     Thresholds.
	 * @return array
	 */
	private static function previous_too_small( $id, $label, $t ) {
		$check = self::not_measured( $id, $label, 'sample_too_small' );
		/* translators: %s: minimum number of visits. */
		$check['summary'] = sprintf( __( 'Not measured yet: the period before had fewer than %s visits on this page. Compare again after the next period.', 'opti-behavior' ), number_format_i18n( $t['min_sessions'] ) );
		return $check;
	}

	/**
	 * Checks of the mobile bounce card: the whole site, mobile vs computers,
	 * the same comparison the card already shows (device aggregator rows).
	 *
	 * @param string $from         Period start.
	 * @param string $to           Period end.
	 * @param bool   $exclude_spam Spam scope.
	 * @param array  $t            Thresholds.
	 * @return array
	 */
	private function build_mobile( $from, $to, $exclude_spam, $t ) {
		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Device_Aggregator' ) ) {
			return array();
		}

		$aggregator = new Opti_Behavior_Smart_Insights_Device_Aggregator();
		$device_arg = array(
			'exclude_spam' => $exclude_spam,
			'limit'        => 100,
		);
		$now        = self::pick_devices( $aggregator->get_device_metrics( $from, $to, $device_arg ) );
		$before     = array( 'mobile' => array() );
		if ( class_exists( 'Opti_Behavior_Smart_Insights_Trend_Calculator' ) ) {
			$calculator = new Opti_Behavior_Smart_Insights_Trend_Calculator();
			$range      = $calculator->get_previous_period_range( $from, $to );
			if ( ! empty( $range['from'] ) && ! empty( $range['to'] ) ) {
				$before = self::pick_devices( $aggregator->get_device_metrics( $range['from'], $range['to'], $device_arg ) );
			}
		}
		$clicks = $this->get_device_click_sessions( $from, $to, $exclude_spam );

		$m = $now['mobile'];
		$d = $now['desktop'];
		$p = $before['mobile'];

		$checks = array(
			self::mobile_short_visit_check( $m['avg_time_on_page'], $m['time_samples'], $m['sessions'], $d['avg_time_on_page'], $d['time_samples'], $d['sessions'], $t ),
			self::mobile_low_reach_check( $m['avg_scroll_depth'], $m['scroll_samples'], $m['sessions'], $d['avg_scroll_depth'], $d['scroll_samples'], $d['sessions'], $t ),
			self::mobile_no_click_check( null === $clicks ? null : $clicks['mobile'], $m['sessions'], null === $clicks ? null : $clicks['desktop'], $d['sessions'], $t ),
			self::mobile_recent_change_check( $m['bounce_rate'], $m['sessions'], $p['bounce_rate'], $p['sessions'], $t ),
		);

		// One action for every likely row: the mobile click heatmap of the
		// site's most visited page (the card has no page of its own).
		$url = '';
		foreach ( $checks as $index => $check ) {
			if ( 'likely' !== $check['verdict'] ) {
				continue;
			}
			if ( '' === $url ) {
				$url = $this->report_url( $this->get_top_page_id( $from, $to, $exclude_spam ), 'click', $from, $to, $exclude_spam );
				$url = '' !== $url ? add_query_arg( 'device', 'mobile', $url ) : '';
			}
			if ( '' !== $url ) {
				$checks[ $index ]['action'] = array(
					'label' => __( 'Open the mobile click heatmap of your most visited page', 'opti-behavior' ),
					'url'   => $url,
				);
			}
		}

		return $checks;
	}

	/**
	 * `mobile_short_visit` verdict: likely when the mobile average time on
	 * page is under time_ratio of the computers'. Pure.
	 *
	 * @param float|null $m_time    Mobile average time on page (s).
	 * @param int        $m_samples Timed mobile page views.
	 * @param int        $m_n       Mobile visits.
	 * @param float|null $d_time    Computer average time on page (s).
	 * @param int        $d_samples Timed computer page views.
	 * @param int        $d_n       Computer visits.
	 * @param array      $t         Thresholds.
	 * @return array
	 */
	public static function mobile_short_visit_check( $m_time, $m_samples, $m_n, $d_time, $d_samples, $d_n, $t ) {
		$id    = 'mobile_short_visit';
		$label = __( 'Do mobile visitors leave faster?', 'opti-behavior' );
		$small = self::devices_too_small( $id, $label, $m_n, $d_n, $t );
		if ( null !== $small ) {
			return $small;
		}
		if ( ! is_numeric( $m_time ) || ! is_numeric( $d_time ) || (float) $m_time <= 0 || (float) $d_time <= 0 || (int) $m_samples < 1 || (int) $d_samples < 1 ) {
			$check            = self::not_measured( $id, $label, 'not_tracked' );
			$check['summary'] = __( 'Not measured: time on page is not recorded on mobile and on computers. Check that the tracker runs (Setup check).', 'opti-behavior' );
			return $check;
		}

		$m_time = (float) $m_time;
		$d_time = (float) $d_time;

		return self::row(
			$id,
			$label,
			$m_time < $t['time_ratio'] * $d_time ? 'likely' : 'ruled_out',
			/* translators: 1: seconds on mobile, 2: timed mobile views, 3: seconds on computers, 4: timed computer views. */
			sprintf( __( '%1$s s on mobile (n=%2$s) vs %3$s s on computers (n=%4$s).', 'opti-behavior' ), number_format_i18n( $m_time, 1 ), number_format_i18n( (int) $m_samples ), number_format_i18n( $d_time, 1 ), number_format_i18n( (int) $d_samples ) ),
			array( $m_time, (int) $m_samples, $d_time ),
			'',
			''
		);
	}

	/**
	 * `mobile_low_reach` verdict: likely when the mobile average scroll is
	 * scroll_gap_points (or more) under the computers'. Pure.
	 *
	 * @param float|null $m_scroll  Mobile average scroll depth (%).
	 * @param int        $m_samples Mobile scroll samples.
	 * @param int        $m_n       Mobile visits.
	 * @param float|null $d_scroll  Computer average scroll depth (%).
	 * @param int        $d_samples Computer scroll samples.
	 * @param int        $d_n       Computer visits.
	 * @param array      $t         Thresholds.
	 * @return array
	 */
	public static function mobile_low_reach_check( $m_scroll, $m_samples, $m_n, $d_scroll, $d_samples, $d_n, $t ) {
		$id    = 'mobile_low_reach';
		$label = __( 'Do mobile visitors scroll less?', 'opti-behavior' );
		$small = self::devices_too_small( $id, $label, $m_n, $d_n, $t );
		if ( null !== $small ) {
			return $small;
		}
		if ( ! is_numeric( $m_scroll ) || ! is_numeric( $d_scroll ) || (int) $m_samples < 1 || (int) $d_samples < 1 ) {
			$check            = self::not_measured( $id, $label, 'not_tracked' );
			$check['summary'] = __( 'Not measured: scroll depth is not recorded on mobile and on computers. Check that the tracker runs (Setup check).', 'opti-behavior' );
			return $check;
		}

		$m_scroll = (float) $m_scroll;
		$d_scroll = (float) $d_scroll;

		return self::row(
			$id,
			$label,
			$m_scroll <= $d_scroll - $t['scroll_gap_points'] ? 'likely' : 'ruled_out',
			/* translators: 1: average scroll on mobile, 2: measured mobile views, 3: average scroll on computers, 4: measured computer views. */
			sprintf( __( '%1$s average scroll on mobile (n=%2$s) vs %3$s on computers (n=%4$s).', 'opti-behavior' ), self::percent( $m_scroll ), number_format_i18n( (int) $m_samples ), self::percent( $d_scroll ), number_format_i18n( (int) $d_samples ) ),
			array( $m_scroll, (int) $m_samples, $d_scroll ),
			'',
			''
		);
	}

	/**
	 * `mobile_no_click` verdict: likely when the share of mobile visits with
	 * a click is under click_share_ratio of the computers'. Pure.
	 *
	 * @param int|null $m_clicked Mobile visits with a click (null = not read).
	 * @param int      $m_n       Mobile visits.
	 * @param int|null $d_clicked Computer visits with a click (null = not read).
	 * @param int      $d_n       Computer visits.
	 * @param array    $t         Thresholds.
	 * @return array
	 */
	public static function mobile_no_click_check( $m_clicked, $m_n, $d_clicked, $d_n, $t ) {
		$id    = 'mobile_no_click';
		$label = __( 'Do mobile visitors click less?', 'opti-behavior' );
		$small = self::devices_too_small( $id, $label, $m_n, $d_n, $t );
		if ( null !== $small ) {
			return $small;
		}
		if ( ! is_numeric( $m_clicked ) || ! is_numeric( $d_clicked ) || (int) $d_clicked < 1 ) {
			$check            = self::not_measured( $id, $label, 'not_tracked' );
			$check['summary'] = __( 'Not measured: no click was recorded on the site in this period. Check that the heatmap tracker runs (Setup check).', 'opti-behavior' );
			return $check;
		}

		$m_n     = (int) $m_n;
		$d_n     = (int) $d_n;
		$m_share = min( $m_n, max( 0, (int) $m_clicked ) ) / $m_n;
		$d_share = min( $d_n, max( 0, (int) $d_clicked ) ) / $d_n;

		return self::row(
			$id,
			$label,
			$m_share < $t['click_share_ratio'] * $d_share ? 'likely' : 'ruled_out',
			/* translators: 1: share of mobile visits with a click, 2: mobile visits, 3: same share on computers, 4: computer visits. */
			sprintf( __( '%1$s of mobile visits clicked something (n=%2$s) vs %3$s on computers (n=%4$s).', 'opti-behavior' ), self::percent( $m_share * 100 ), number_format_i18n( $m_n ), self::percent( $d_share * 100 ), number_format_i18n( $d_n ) ),
			array( $m_share * 100, $m_n, $d_share * 100 ),
			'',
			''
		);
	}

	/**
	 * `mobile_recent_change` verdict: likely when the mobile bounce rate rose
	 * by recent_bounce_points or more against the previous period. Pure.
	 *
	 * @param float|null $current    Mobile bounce rate now (%).
	 * @param int        $current_n  Mobile visits now.
	 * @param float|null $previous   Mobile bounce rate before (%).
	 * @param int        $previous_n Mobile visits before.
	 * @param array      $t          Thresholds.
	 * @return array
	 */
	public static function mobile_recent_change_check( $current, $current_n, $previous, $previous_n, $t ) {
		$id         = 'mobile_recent_change';
		$label      = __( 'Did mobile get worse recently?', 'opti-behavior' );
		$current_n  = max( 0, (int) $current_n );
		$previous_n = max( 0, (int) $previous_n );

		if ( $previous_n < $t['min_sessions'] ) {
			$check = self::not_measured( $id, $label, 'sample_too_small' );
			/* translators: %s: minimum number of visits. */
			$check['summary'] = sprintf( __( 'Not measured yet: the period before had fewer than %s mobile visits. Compare again after the next period.', 'opti-behavior' ), number_format_i18n( $t['min_sessions'] ) );
			return $check;
		}
		if ( ! is_numeric( $current ) || ! is_numeric( $previous ) ) {
			$check            = self::not_measured( $id, $label, 'not_tracked' );
			$check['summary'] = __( 'Not measured: the mobile bounce rate is not recorded in both periods.', 'opti-behavior' );
			return $check;
		}

		$current  = (float) $current;
		$previous = (float) $previous;

		return self::row(
			$id,
			$label,
			$current - $previous >= $t['recent_bounce_points'] ? 'likely' : 'ruled_out',
			/* translators: 1: mobile bounce rate now, 2: mobile visits now, 3: mobile bounce rate before, 4: mobile visits before. */
			sprintf( __( 'Mobile bounce rate %1$s now (n=%2$s) vs %3$s before (n=%4$s).', 'opti-behavior' ), self::percent( $current ), number_format_i18n( $current_n ), self::percent( $previous ), number_format_i18n( $previous_n ) ),
			array( $current, $current_n, $previous ),
			'',
			''
		);
	}

	/**
	 * Not-measured row when mobile or computers have too few visits.
	 *
	 * @param string $id    Check id.
	 * @param string $label Label.
	 * @param int    $m_n   Mobile visits.
	 * @param int    $d_n   Computer visits.
	 * @param array  $t     Thresholds.
	 * @return array|null Null when both sides are large enough.
	 */
	private static function devices_too_small( $id, $label, $m_n, $d_n, $t ) {
		if ( (int) $m_n >= $t['min_sessions'] && (int) $d_n >= $t['min_sessions'] ) {
			return null;
		}
		$check = self::not_measured( $id, $label, 'sample_too_small' );
		/* translators: %s: minimum number of visits per device. */
		$check['summary'] = sprintf( __( 'Not measured yet: wait for %s visits on mobile and on computers.', 'opti-behavior' ), number_format_i18n( $t['min_sessions'] ) );
		return $check;
	}

	/**
	 * Mobile and computer rows of the device aggregator. Pure.
	 *
	 * @param array $rows Device metric rows.
	 * @return array { mobile, desktop } each { sessions, bounce_rate, avg_time_on_page, time_samples, avg_scroll_depth, scroll_samples }
	 */
	public static function pick_devices( $rows ) {
		$empty = array(
			'sessions'         => 0,
			'bounce_rate'      => null,
			'avg_time_on_page' => null,
			'time_samples'     => 0,
			'avg_scroll_depth' => null,
			'scroll_samples'   => 0,
		);
		$picked = array(
			'mobile'  => $empty,
			'desktop' => $empty,
		);
		$found  = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key  = isset( $row['device_key'] ) ? sanitize_key( (string) $row['device_key'] ) : '';
			$side = in_array( $key, self::MOBILE_KEYS, true ) ? 'mobile' : ( in_array( $key, self::DESKTOP_KEYS, true ) ? 'desktop' : '' );
			if ( '' === $side || isset( $found[ $side ] ) ) {
				continue;
			}
			$found[ $side ]  = true;
			$time_samples    = isset( $row['time_on_page_samples'] ) ? max( 0, (int) $row['time_on_page_samples'] ) : 0;
			$scroll_samples  = isset( $row['scroll_depth_samples'] ) ? max( 0, (int) $row['scroll_depth_samples'] ) : 0;
			$picked[ $side ] = array(
				'sessions'         => isset( $row['sessions'] ) ? max( 0, (int) $row['sessions'] ) : 0,
				'bounce_rate'      => isset( $row['bounce_rate'] ) && is_numeric( $row['bounce_rate'] ) ? (float) $row['bounce_rate'] : null,
				// Real time samples only: the aggregator falls back to session duration.
				'avg_time_on_page' => $time_samples > 0 && isset( $row['avg_time_on_page'] ) && is_numeric( $row['avg_time_on_page'] ) ? (float) $row['avg_time_on_page'] : null,
				'time_samples'     => $time_samples,
				'avg_scroll_depth' => $scroll_samples > 0 && isset( $row['avg_scroll_depth'] ) && is_numeric( $row['avg_scroll_depth'] ) ? (float) $row['avg_scroll_depth'] : null,
				'scroll_samples'   => $scroll_samples,
			);
		}

		return $picked;
	}

	/**
	 * `recent_change` verdict. Pure.
	 *
	 * @param string     $metric_key     bounce_rate | exit_rate | avg_scroll_depth.
	 * @param float|null $current        Current value (%).
	 * @param int        $current_n      Current visits on the page.
	 * @param float|null $previous       Previous-period value (%).
	 * @param int        $previous_n     Previous-period visits on the page.
	 * @param string     $since          First day of the previous period (Y-m-d).
	 * @param array      $t              Thresholds.
	 * @param string     $url            Report URL.
	 * @return array
	 */
	public static function recent_change_check( $metric_key, $current, $current_n, $previous, $previous_n, $since, $t, $url = '' ) {
		$id        = 'recent_change';
		$label     = __( 'Did this page get worse recently?', 'opti-behavior' );
		$current_n = max( 0, (int) $current_n );
		$previous_n = max( 0, (int) $previous_n );

		if ( $previous_n < $t['min_sessions'] ) {
			$check = self::not_measured( $id, $label, 'sample_too_small' );
			/* translators: %s: minimum number of visits. */
			$check['summary'] = sprintf( __( 'Not measured yet: the period before had fewer than %s visits on this page. Compare again after the next period.', 'opti-behavior' ), number_format_i18n( $t['min_sessions'] ) );
			return $check;
		}
		if ( ! is_numeric( $current ) || ! is_numeric( $previous ) ) {
			$check            = self::not_measured( $id, $label, 'not_tracked' );
			$check['summary'] = __( 'Not measured: this rate is not recorded for this page in both periods.', 'opti-behavior' );
			return $check;
		}

		$current  = (float) $current;
		$previous = (float) $previous;
		if ( 'avg_scroll_depth' === $metric_key ) {
			$worse = $previous - $current >= $t['recent_scroll_points'];
		} else {
			$limit = 'exit_rate' === $metric_key ? $t['recent_exit_points'] : $t['recent_bounce_points'];
			$worse = $current - $previous >= $limit;
		}
		$verdict = $worse ? 'likely' : 'ruled_out';

		if ( $worse ) {
			$date   = '' !== $since && false !== strtotime( $since ) ? date_i18n( get_option( 'date_format' ), strtotime( $since ) ) : '';
			/* translators: %s: a date. */
			$action = '' !== $date ? sprintf( __( 'Check what changed on this page since %s: content, theme or plugin updates.', 'opti-behavior' ), $date ) : __( 'Check what changed on this page: content, theme or plugin updates.', 'opti-behavior' );
		} else {
			$action = __( 'The problem is not new: work on the page itself.', 'opti-behavior' );
		}

		return self::row(
			$id,
			$label,
			$verdict,
			/* translators: 1: value now, 2: visits now, 3: value before, 4: visits before. */
			sprintf( __( '%1$s now (n=%2$s) vs %3$s before (n=%4$s).', 'opti-behavior' ), self::percent( $current ), number_format_i18n( $current_n ), self::percent( $previous ), number_format_i18n( $previous_n ) ),
			array( $current, $current_n, $previous ),
			$action,
			$url
		);
	}

	/**
	 * `no_click` verdict. Pure.
	 *
	 * @param int    $page_clicked  Page visits with at least one click.
	 * @param int    $page_n        Page visits.
	 * @param int    $site_clicked  Site visits with at least one click.
	 * @param int    $site_n        Site visits.
	 * @param string $top_element   Most clicked element of the page ('' = unknown).
	 * @param array  $t             Thresholds.
	 * @param string $url           Click heatmap URL.
	 * @return array
	 */
	public static function no_click_check( $page_clicked, $page_n, $site_clicked, $site_n, $top_element, $t, $url = '' ) {
		$id           = 'no_click';
		$label        = __( 'Do visitors click anything before leaving?', 'opti-behavior' );
		$page_n       = max( 0, (int) $page_n );
		$site_n       = max( 0, (int) $site_n );
		$page_clicked = min( $page_n, max( 0, (int) $page_clicked ) );
		$site_clicked = min( $site_n, max( 0, (int) $site_clicked ) );

		if ( $page_n < $t['min_sessions'] ) {
			$check = self::not_measured( $id, $label, 'sample_too_small' );
			/* translators: %s: minimum number of visits. */
			$check['summary'] = sprintf( __( 'Not measured yet: wait for %s visits on this page.', 'opti-behavior' ), number_format_i18n( $t['min_sessions'] ) );
			return $check;
		}
		if ( $site_n < 1 || $site_clicked < 1 ) {
			$check            = self::not_measured( $id, $label, 'not_tracked' );
			$check['summary'] = __( 'Not measured: no click was recorded on the site in this period. Check that the heatmap tracker runs (Setup check).', 'opti-behavior' );
			return $check;
		}

		$page_share = $page_clicked / $page_n;
		$site_share = $site_clicked / $site_n;
		$verdict    = $page_share < $t['click_share_ratio'] * $site_share ? 'likely' : 'ruled_out';

		/* translators: 1: share of visits with a click on this page, 2: visits on this page, 3: same share on the site, 4: visits on the site. */
		$summary = sprintf( __( '%1$s of visits clicked something on this page (n=%2$s) vs %3$s on the site (n=%4$s).', 'opti-behavior' ), self::percent( $page_share * 100 ), number_format_i18n( $page_n ), self::percent( $site_share * 100 ), number_format_i18n( $site_n ) );
		if ( 'likely' === $verdict && '' !== (string) $top_element ) {
			/* translators: %s: label of the most clicked element. */
			$summary .= ' ' . sprintf( __( 'Most clicked: "%s".', 'opti-behavior' ), (string) $top_element );
		}

		return self::row(
			$id,
			$label,
			$verdict,
			$summary,
			array( $page_share * 100, $page_n, $site_share * 100 ),
			'likely' === $verdict ? __( 'Open the click heatmap: visitors do not find a next step.', 'opti-behavior' ) : '',
			$url
		);
	}

	/**
	 * `short_visit` verdict. Pure.
	 *
	 * @param float|null $page_time  Page average time on page (s), null = not measured.
	 * @param int        $page_n     Timed page views of the page.
	 * @param float|null $site_time  Site average time on page (s).
	 * @param int        $site_n     Timed page views of the site.
	 * @param array      $t          Thresholds.
	 * @param string     $url        Scroll heatmap URL.
	 * @return array
	 */
	public static function short_visit_check( $page_time, $page_n, $site_time, $site_n, $t, $url = '' ) {
		$id    = 'short_visit';
		$label = __( 'Do visitors leave before reading?', 'opti-behavior' );

		if ( ! is_numeric( $page_time ) || ! is_numeric( $site_time ) || (float) $page_time <= 0 || (float) $site_time <= 0 || (int) $page_n < 1 || (int) $site_n < 1 ) {
			$check            = self::not_measured( $id, $label, 'not_tracked' );
			$check['summary'] = __( 'Not measured: time on page is not recorded here. Check that the tracker runs on this page (Setup check).', 'opti-behavior' );
			return $check;
		}

		$page_time = (float) $page_time;
		$site_time = (float) $site_time;
		$verdict   = $page_time < $t['time_ratio'] * $site_time ? 'likely' : 'ruled_out';

		return self::row(
			$id,
			$label,
			$verdict,
			/* translators: 1: seconds on this page, 2: timed views of this page, 3: seconds on the site, 4: timed views on the site. */
			sprintf( __( '%1$s s on this page (n=%2$s) vs %3$s s on the site (n=%4$s).', 'opti-behavior' ), number_format_i18n( $page_time, 1 ), number_format_i18n( (int) $page_n ), number_format_i18n( $site_time, 1 ), number_format_i18n( (int) $site_n ) ),
			array( $page_time, (int) $page_n, $site_time ),
			'likely' === $verdict ? __( 'Make the first screen say what the page offers.', 'opti-behavior' ) : '',
			$url
		);
	}

	/**
	 * `low_reach` verdict. Pure.
	 *
	 * @param float|null $page_scroll Page average scroll depth (%), null = not measured.
	 * @param int        $page_n      Scroll samples of the page.
	 * @param float|null $site_scroll Site average scroll depth (%).
	 * @param int        $site_n      Scroll samples of the site.
	 * @param array      $t           Thresholds.
	 * @param string     $url         Scroll heatmap URL.
	 * @return array
	 */
	public static function low_reach_check( $page_scroll, $page_n, $site_scroll, $site_n, $t, $url = '' ) {
		$id    = 'low_reach';
		$label = __( 'Do visitors reach the content below the first screen?', 'opti-behavior' );

		if ( ! is_numeric( $page_scroll ) || ! is_numeric( $site_scroll ) || (int) $page_n < 1 || (int) $site_n < 1 ) {
			$check            = self::not_measured( $id, $label, 'not_tracked' );
			$check['summary'] = __( 'Not measured: scroll depth is not recorded here. Check that the tracker runs on this page (Setup check).', 'opti-behavior' );
			return $check;
		}

		$page_scroll = (float) $page_scroll;
		$site_scroll = (float) $site_scroll;
		$verdict     = $page_scroll <= $site_scroll - $t['scroll_gap_points'] ? 'likely' : 'ruled_out';

		return self::row(
			$id,
			$label,
			$verdict,
			/* translators: 1: average scroll on this page, 2: measured views of this page, 3: average scroll on the site, 4: measured views on the site. */
			sprintf( __( '%1$s average scroll on this page (n=%2$s) vs %3$s on the site (n=%4$s).', 'opti-behavior' ), self::percent( $page_scroll ), number_format_i18n( (int) $page_n ), self::percent( $site_scroll ), number_format_i18n( (int) $site_n ) ),
			array( $page_scroll, (int) $page_n, $site_scroll ),
			'likely' === $verdict ? __( 'Move the key content and the next step higher.', 'opti-behavior' ) : '',
			$url
		);
	}

	/**
	 * One measured row. `compare_base` stays null: the shared sample line
	 * ("lost and continued sessions") does not describe a page vs site
	 * comparison, so both sample sizes live in the summary.
	 *
	 * @param string $id      Check id.
	 * @param string $label   Label.
	 * @param string $verdict likely | ruled_out.
	 * @param string $summary Measured sentence.
	 * @param array  $values  [ value, base, compare_value ].
	 * @param string $action  Action sentence ('' = none).
	 * @param string $url     Report URL.
	 * @return array
	 */
	private static function row( $id, $label, $verdict, $summary, $values, $action, $url ) {
		$row = array(
			'id'       => $id,
			'kind'     => 'check',
			'label'    => $label,
			'verdict'  => $verdict,
			'summary'  => $summary,
			'evidence' => array(
				'value'         => round( (float) $values[0], 2 ),
				'base'          => (int) $values[1],
				'compare_value' => round( (float) $values[2], 2 ),
				'compare_base'  => null,
			),
		);
		if ( '' !== $action && '' !== (string) $url ) {
			$row['action'] = array(
				'label' => $action,
				'url'   => (string) $url,
			);
		}
		return $row;
	}

	/**
	 * A check that could not be measured.
	 *
	 * @param string $id     Check id.
	 * @param string $label  Label.
	 * @param string $reason not_tracked | sample_too_small.
	 * @return array
	 */
	private static function not_measured( $id, $label, $reason ) {
		return array(
			'id'       => $id,
			'kind'     => 'check',
			'label'    => $label,
			'verdict'  => 'not_measured',
			'reason'   => $reason,
			'summary'  => '',
			'evidence' => array(
				'value'         => null,
				'base'          => null,
				'compare_value' => null,
				'compare_base'  => null,
			),
		);
	}

	/**
	 * "62.0%".
	 *
	 * @param float $value Percent value.
	 * @return string
	 */
	private static function percent( $value ) {
		return number_format_i18n( (float) $value, 1 ) . '%';
	}

	/**
	 * Heatmap of the page for the card period.
	 *
	 * @param int    $page_id      Page id.
	 * @param string $type         click | scroll.
	 * @param string $from         Period start.
	 * @param string $to           Period end.
	 * @param bool   $exclude_spam Spam scope.
	 * @return string '' without a page id.
	 */
	private function report_url( $page_id, $type, $from, $to, $exclude_spam ) {
		if ( $page_id < 1 || ! function_exists( 'admin_url' ) ) {
			return '';
		}
		$query = array(
			'page'    => 'opti-behavior-heatmap-detail',
			'page_id' => $page_id,
			'type'    => $type,
		);
		$from = substr( (string) $from, 0, 10 );
		$to   = substr( (string) $to, 0, 10 );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$query['date_range'] = 'custom';
			$query['start_date'] = $from;
			$query['end_date']   = $to;
		}
		$query['exclude_spam'] = $exclude_spam ? '1' : '0';

		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/**
	 * Site totals for the period, same definitions as the page metrics:
	 * visits with a page view, timed / scrolled page views, visits with a click.
	 *
	 * @param string $from         Period start.
	 * @param string $to           Period end.
	 * @param bool   $exclude_spam Spam scope.
	 * @return array { sessions, click_sessions, avg_time_on_page, time_samples, avg_scroll_depth, scroll_samples }
	 */
	private function get_site_row( $from, $to, $exclude_spam ) {
		global $wpdb;

		$empty = array(
			'sessions'         => 0,
			'click_sessions'   => 0,
			'avg_time_on_page' => null,
			'time_samples'     => 0,
			'avg_scroll_depth' => null,
			'scroll_samples'   => 0,
		);

		$start = $this->normalize_datetime( $from, '00:00:00' );
		$end   = $this->normalize_datetime( $to, '23:59:59' );
		if ( '' === $start || '' === $end || ! isset( $wpdb ) ) {
			return $empty;
		}

		$key = md5( $start . '|' . $end . '|' . ( $exclude_spam ? 1 : 0 ) . '|' . $wpdb->prefix );
		if ( isset( self::$site_memo[ $key ] ) ) {
			return self::$site_memo[ $key ];
		}

		$pageviews = $wpdb->prefix . 'optibehavior_pageviews';
		$sessions  = $wpdb->prefix . 'optibehavior_sessions';
		$events    = $wpdb->prefix . 'optibehavior_events';
		if ( ! $this->table_exists( $pageviews ) || ! $this->table_exists( $sessions ) ) {
			self::$site_memo[ $key ] = $empty;
			return $empty;
		}

		$spam        = $exclude_spam ? $this->get_spam_conditions( $sessions, 's' ) : '';
		$spam_sql    = '' !== $spam ? " AND ({$spam})" : '';
		$time_expr   = $this->column_exists( $pageviews, 'time_on_page' ) ? 'AVG(NULLIF(pv.time_on_page, 0))' : 'NULL';
		$time_n      = $this->column_exists( $pageviews, 'time_on_page' ) ? 'COUNT(NULLIF(pv.time_on_page, 0))' : '0';
		$scroll_expr = $this->column_exists( $pageviews, 'scroll_depth' ) ? 'AVG(NULLIF(pv.scroll_depth, 0))' : 'NULL';
		$scroll_n    = $this->column_exists( $pageviews, 'scroll_depth' ) ? 'COUNT(NULLIF(pv.scroll_depth, 0))' : '0';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin tables ($wpdb->prefix + fixed suffix); the interpolated fragments ($spam_sql / $event_spam = stored-verdict filter of Stats_Spam_Filter, column expressions) are fixed SQL built by this class, never request data; dates and ids are bound by prepare(); read-only analytics query, once per Smart Insights generation.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT pv.session_id) AS sessions,
					{$time_expr} AS avg_time_on_page, {$time_n} AS time_samples,
					{$scroll_expr} AS avg_scroll_depth, {$scroll_n} AS scroll_samples
				FROM {$pageviews} pv
				INNER JOIN {$sessions} s ON pv.session_id = s.id
				WHERE pv.view_time BETWEEN %s AND %s{$spam_sql}",
				$start,
				$end
			),
			ARRAY_A
		);

		$clicks = 0;
		if ( $this->table_exists( $events ) ) {
			$event_spam = '' !== $spam ? " AND e.session_id IN (SELECT s.id FROM {$sessions} s WHERE {$spam})" : '';
			$clicks     = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT e.session_id) FROM {$events} e WHERE e.event IN (%d, %d) AND e.insert_at BETWEEN %s AND %s{$event_spam}",
					self::click_code( 'CLICK_PC', 16 ),
					self::click_code( 'CLICK_MOBILE', 17 ),
					$start,
					$end
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$time_samples   = is_array( $row ) ? max( 0, (int) $row['time_samples'] ) : 0;
		$scroll_samples = is_array( $row ) ? max( 0, (int) $row['scroll_samples'] ) : 0;
		$site           = array(
			'sessions'         => is_array( $row ) ? max( 0, (int) $row['sessions'] ) : 0,
			'click_sessions'   => max( 0, $clicks ),
			'avg_time_on_page' => $time_samples > 0 && is_numeric( $row['avg_time_on_page'] ) ? round( (float) $row['avg_time_on_page'], 2 ) : null,
			'time_samples'     => $time_samples,
			'avg_scroll_depth' => $scroll_samples > 0 && is_numeric( $row['avg_scroll_depth'] ) ? round( (float) $row['avg_scroll_depth'], 2 ) : null,
			'scroll_samples'   => $scroll_samples,
		);

		self::$site_memo[ $key ] = $site;
		return $site;
	}

	/**
	 * Label of the most clicked element of the page (text, else <tag>).
	 *
	 * @param int    $page_id      Page id.
	 * @param string $from         Period start.
	 * @param string $to           Period end.
	 * @param bool   $exclude_spam Spam scope.
	 * @return string
	 */
	private function get_top_clicked_element( $page_id, $from, $to, $exclude_spam ) {
		global $wpdb;

		$events   = $wpdb->prefix . 'optibehavior_events';
		$sessions = $wpdb->prefix . 'optibehavior_sessions';
		$start    = $this->normalize_datetime( $from, '00:00:00' );
		$end      = $this->normalize_datetime( $to, '23:59:59' );
		if ( '' === $start || '' === $end || ! $this->table_exists( $events ) || ! $this->column_exists( $events, 'element_tag' ) ) {
			return '';
		}

		$page_col   = $this->column_exists( $events, 'page_id2' ) ? 'page_id2' : 'page_id';
		$text_col   = $this->column_exists( $events, 'element_text' ) ? "NULLIF(TRIM(e.element_text), '')" : 'NULL';
		$spam       = $exclude_spam && $this->table_exists( $sessions ) ? $this->get_spam_conditions( $sessions, 's' ) : '';
		$event_spam = '' !== $spam ? " AND e.session_id IN (SELECT s.id FROM {$sessions} s WHERE {$spam})" : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin tables ($wpdb->prefix + fixed suffix); the interpolated fragments ($spam_sql / $event_spam = stored-verdict filter of Stats_Spam_Filter, column expressions) are fixed SQL built by this class, never request data; dates and ids are bound by prepare(); read-only analytics query, once per Smart Insights generation.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT {$text_col} AS label, LOWER(e.element_tag) AS tag, COUNT(*) AS clicks
				FROM {$events} e
				WHERE e.{$page_col} = %d AND e.event IN (%d, %d) AND e.insert_at BETWEEN %s AND %s{$event_spam}
				GROUP BY label, tag
				ORDER BY clicks DESC
				LIMIT 1",
				$page_id,
				self::click_code( 'CLICK_PC', 16 ),
				self::click_code( 'CLICK_MOBILE', 17 ),
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! is_array( $row ) ) {
			return '';
		}
		$label = isset( $row['label'] ) ? trim( wp_strip_all_tags( (string) $row['label'] ) ) : '';
		if ( '' === $label && ! empty( $row['tag'] ) ) {
			$label = '<' . preg_replace( '/[^a-z0-9-]/', '', (string) $row['tag'] ) . '>';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 60 ) : substr( $label, 0, 60 );
	}

	/**
	 * Visits with at least one click, mobile and computers, for the visits
	 * the device aggregator counts (started in the period, same device column).
	 *
	 * @param string $from         Period start.
	 * @param string $to           Period end.
	 * @param bool   $exclude_spam Spam scope.
	 * @return array|null { mobile, desktop } or null when clicks or devices are not recorded.
	 */
	private function get_device_click_sessions( $from, $to, $exclude_spam ) {
		global $wpdb;

		$events   = $wpdb->prefix . 'optibehavior_events';
		$sessions = $wpdb->prefix . 'optibehavior_sessions';
		$visitors = $wpdb->prefix . 'optibehavior_visitors';
		$start    = $this->normalize_datetime( $from, '00:00:00' );
		$end      = $this->normalize_datetime( $to, '23:59:59' );
		if ( '' === $start || '' === $end || ! $this->table_exists( $events ) || ! $this->table_exists( $sessions ) || ! $this->table_exists( $visitors ) || ! $this->column_exists( $visitors, 'device_type' ) ) {
			return null;
		}

		$spam = $exclude_spam ? $this->get_spam_conditions( $sessions, 's' ) : '';
		$spam_sql = '' !== $spam ? " AND ({$spam})" : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin tables ($wpdb->prefix + fixed suffix); the interpolated fragments ($spam_sql / $event_spam = stored-verdict filter of Stats_Spam_Filter, column expressions) are fixed SQL built by this class, never request data; dates and ids are bound by prepare(); read-only analytics query, once per Smart Insights generation.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(NULLIF(v.device_type, ''), 'unknown') AS device, COUNT(DISTINCT e.session_id) AS clicked
				FROM {$events} e
				INNER JOIN {$sessions} s ON e.session_id = s.id
				LEFT JOIN {$visitors} v ON s.visitor_id = v.id
				WHERE e.event IN (%d, %d) AND e.insert_at BETWEEN %s AND %s AND s.start_time BETWEEN %s AND %s{$spam_sql}
				GROUP BY device",
				self::click_code( 'CLICK_PC', 16 ),
				self::click_code( 'CLICK_MOBILE', 17 ),
				$start,
				$end,
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$clicked = array(
			'mobile'  => 0,
			'desktop' => 0,
		);
		foreach ( (array) $rows as $row ) {
			$key = isset( $row['device'] ) ? sanitize_key( (string) $row['device'] ) : '';
			if ( in_array( $key, self::MOBILE_KEYS, true ) ) {
				$clicked['mobile'] = max( $clicked['mobile'], (int) $row['clicked'] );
			} elseif ( in_array( $key, self::DESKTOP_KEYS, true ) ) {
				$clicked['desktop'] = max( $clicked['desktop'], (int) $row['clicked'] );
			}
		}

		return $clicked;
	}

	/**
	 * The page with the most visits in the period (the mobile card's link).
	 *
	 * @param string $from         Period start.
	 * @param string $to           Period end.
	 * @param bool   $exclude_spam Spam scope.
	 * @return int 0 when unknown.
	 */
	private function get_top_page_id( $from, $to, $exclude_spam ) {
		global $wpdb;

		$pageviews = $wpdb->prefix . 'optibehavior_pageviews';
		$sessions  = $wpdb->prefix . 'optibehavior_sessions';
		$start     = $this->normalize_datetime( $from, '00:00:00' );
		$end       = $this->normalize_datetime( $to, '23:59:59' );
		if ( '' === $start || '' === $end || ! $this->table_exists( $pageviews ) || ! $this->table_exists( $sessions ) ) {
			return 0;
		}

		$spam = $exclude_spam ? $this->get_spam_conditions( $sessions, 's' ) : '';
		$spam_sql = '' !== $spam ? " AND ({$spam})" : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin tables ($wpdb->prefix + fixed suffix); the interpolated fragments ($spam_sql / $event_spam = stored-verdict filter of Stats_Spam_Filter, column expressions) are fixed SQL built by this class, never request data; dates and ids are bound by prepare(); read-only analytics query, once per Smart Insights generation.
		$page_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pv.page_id FROM {$pageviews} pv
				INNER JOIN {$sessions} s ON pv.session_id = s.id
				WHERE pv.page_id > 0 AND pv.view_time BETWEEN %s AND %s{$spam_sql}
				GROUP BY pv.page_id
				ORDER BY COUNT(DISTINCT pv.session_id) DESC
				LIMIT 1",
				$start,
				$end
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return absint( $page_id );
	}

	/**
	 * The dashboard (traffic sources) of the plugin.
	 *
	 * @return string
	 */
	private function dashboard_url() {
		return function_exists( 'admin_url' ) ? add_query_arg( 'page', 'opti-behavior-analytics', admin_url( 'admin.php' ) ) : '';
	}

	/**
	 * Heatmap core click code with a numeric fallback.
	 *
	 * @param string $name     Constant name.
	 * @param int    $fallback Fallback code.
	 * @return int
	 */
	private static function click_code( $name, $fallback ) {
		return class_exists( 'Opti_Behavior_Heatmap_Core' ) && defined( 'Opti_Behavior_Heatmap_Core::' . $name ) ? (int) constant( 'Opti_Behavior_Heatmap_Core::' . $name ) : $fallback;
	}

	/**
	 * Spam conditions of the page metrics (metric aggregator), without the
	 * leading AND, so page and site count the same visits.
	 *
	 * @param string $sessions_table Sessions table.
	 * @param string $alias          Sessions alias.
	 * @return string
	 */
	private function get_spam_conditions( $sessions_table, $alias ) {
		if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
			// One counting universe with Traffic (OPT-588): stored verdict only.
			return (string) preg_replace( '/^\s*AND\s+/i', '', Opti_Behavior_Stats_Spam_Filter::session_sql( $alias, 'AND', true ) );
		}
		$prefix     = $alias . '.';
		$conditions = array();
		if ( $this->column_exists( $sessions_table, 'traffic_type' ) ) {
			$conditions[] = '(' . $prefix . "traffic_type IS NULL OR " . $prefix . "traffic_type = '' OR " . $prefix . "traffic_type NOT IN ('spam', 'bot', 'automated'))";
		}
		if ( $this->column_exists( $sessions_table, 'duration' ) ) {
			$settings = get_option(
				'opti_behavior_traffic_settings',
				array(
					'spam_detection_enabled'  => true,
					'spam_duration_threshold' => 3,
				)
			);
			$settings = is_array( $settings ) ? $settings : array();
			if ( ! array_key_exists( 'spam_detection_enabled', $settings ) || ! empty( $settings['spam_detection_enabled'] ) ) {
				$duration_condition = $prefix . 'duration >= ' . max( 0, isset( $settings['spam_duration_threshold'] ) ? absint( $settings['spam_duration_threshold'] ) : 3 );
				if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) && $this->column_exists( $sessions_table, 'referrer' ) ) {
					// A visit referred by another site passes the duration floor (OPT-562).
					$duration_condition = Opti_Behavior_Stats_Spam_Filter::referrer_or_thresholds_sql( $duration_condition, $alias );
				}
				$conditions[] = $duration_condition;
			}
		}
		return implode( ' AND ', $conditions );
	}

	/**
	 * Same default as the metric aggregator.
	 *
	 * @return bool
	 */
	private function is_spam_excluded() {
		if ( isset( $GLOBALS['opti_behavior_exclude_spam'] ) ) {
			return (bool) $GLOBALS['opti_behavior_exclude_spam'];
		}
		$settings = get_option( 'opti_behavior_traffic_settings', array( 'spam_detection_enabled' => true ) );
		if ( ! is_array( $settings ) ) {
			return true;
		}
		return ! array_key_exists( 'spam_detection_enabled', $settings ) || ! empty( $settings['spam_detection_enabled'] );
	}

	/**
	 * "Y-m-d" or "Y-m-d H:i:s" → "Y-m-d H:i:s".
	 *
	 * @param string $date Date.
	 * @param string $time Time used when the date has none.
	 * @return string '' when invalid.
	 */
	private function normalize_datetime( $date, $time ) {
		$date = trim( (string) $date );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date . ' ' . $time;
		}
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date ) ? $date : '';
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table.
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cache[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		}
		return $cache[ $table ];
	}

	/**
	 * Whether a column exists.
	 *
	 * @param string $table  Table.
	 * @param string $column Column.
	 * @return bool
	 */
	private function column_exists( $table, $column ) {
		global $wpdb;
		static $cache = array();
		$key = $table . '.' . $column;
		if ( ! isset( $cache[ $key ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cache[ $key ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s', DB_NAME, $table, $column ) );
		}
		return $cache[ $key ];
	}
}
