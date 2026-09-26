<?php
/**
 * Smart Insights Baseline Calculator Class
 *
 * Calculates site-level baselines for deterministic insight rules.
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are built from $wpdb->prefix and hardcoded identifiers.

/**
 * Smart Insights Baseline Calculator Class
 *
 * Uses daily_stats where it is safe and available, then falls back to direct or
 * sampled raw-table queries. Missing tables/columns return neutral baselines
 * instead of fatal errors during upgrades.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Baseline_Calculator {

	/**
	 * Cached table checks.
	 *
	 * @var array
	 */
	private $table_exists = array();

	/**
	 * Cached column checks.
	 *
	 * @var array
	 */
	private $column_exists = array();

	/**
	 * Calculate site baselines for a date range.
	 *
	 * Percent values are returned on a 0-100 scale.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args: exclude_spam, sample_limit.
	 * @return array
	 */
	public function calculate_site_baselines( $start_date, $end_date, $args = array() ) {
		$defaults = array(
			'exclude_spam' => $this->is_spam_excluded(),
			'sample_limit' => 10000,
		);
		$args     = wp_parse_args( $args, $defaults );

		$start_date = $this->normalize_start_datetime( $start_date );
		$end_date   = $this->normalize_end_datetime( $end_date );
		$baseline   = null;

		if ( empty( $args['exclude_spam'] ) ) {
			$baseline = $this->calculate_from_daily_stats( $start_date, $end_date );
		}

		if ( ! $baseline || empty( $baseline['site_sessions'] ) ) {
			$baseline = $this->calculate_from_raw_tables( $start_date, $end_date, $args );
		} else {
			$baseline = $this->enrich_daily_stats_baseline_with_raw_metrics( $baseline, $start_date, $end_date, $args );
		}

		$baseline = $this->normalize_baseline( $baseline, $start_date, $end_date );

		/**
		 * Filter the computed site baselines.
		 *
		 * Neutral extension point (Bug 1): the Free plugin owns no site-wide
		 * conversion definition, so `site_avg_conversion_rate` stays null here and
		 * layers that DO own one (Pro) populate it. Single choke point covering every
		 * source path (daily_stats, raw tables, backfilled) so a consumer cannot see
		 * an un-filtered baseline. Values are re-normalized afterwards, so a filter
		 * may return raw floats.
		 *
		 * @since 1.0.9
		 * @param array  $baseline   Normalized baseline.
		 * @param string $start_date Start datetime.
		 * @param string $end_date   End datetime.
		 * @param array  $args       Calculation args (carries exclude_spam scope).
		 */
		$baseline = apply_filters( 'opti_behavior_smart_insights_baselines', $baseline, $start_date, $end_date, $args );

		return $this->normalize_baseline( $baseline, $start_date, $end_date );
	}

	/**
	 * Alias for shorter service code.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args.
	 * @return array
	 */
	public function calculate( $start_date, $end_date, $args = array() ) {
		return $this->calculate_site_baselines( $start_date, $end_date, $args );
	}

	/**
	 * Get baselines for a supported Smart Insights entity type.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $date_range  Date range with from/to keys.
	 * @param array  $args        Optional args.
	 * @return array
	 */
	public function get_baselines( $entity_type, $date_range, $args = array() ) {
		$entity_type = sanitize_key( $entity_type );
		$from        = isset( $date_range['from'] ) ? $date_range['from'] : '';
		$to          = isset( $date_range['to'] ) ? $date_range['to'] : '';

		switch ( $entity_type ) {
			case 'source':
			case 'campaign':
				return $this->get_source_baselines( $from, $to, $args );
			case 'device':
				return $this->get_device_baselines( $from, $to, $args );
			case 'funnel':
				return $this->get_funnel_baselines( $from, $to, $args );
			case 'cta':
			case 'heatmap':
				return $this->get_cta_baselines( $from, $to, $args );
			case 'site':
			case 'page':
			default:
				return $this->calculate_site_baselines( $from, $to, $args );
		}
	}

	/**
	 * Calculate site baselines for the previous equivalent period.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args.
	 * @return array
	 */
	public function calculate_previous_period_baseline( $start_date, $end_date, $args = array() ) {
		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Trend_Calculator' ) ) {
			return array();
		}

		$trend_calculator = new Opti_Behavior_Smart_Insights_Trend_Calculator();
		$previous_range   = $trend_calculator->get_previous_period_range( $start_date, $end_date );
		if ( empty( $previous_range['from'] ) || empty( $previous_range['to'] ) ) {
			return array();
		}

		$baseline                    = $this->calculate_site_baselines( $previous_range['from'], $previous_range['to'], $args );
		$baseline['baseline_source'] = isset( $baseline['baseline_source'] ) ? $baseline['baseline_source'] . ':previous_period' : 'previous_period';
		$baseline['period_role']     = 'previous';

		return $baseline;
	}

	/**
	 * Get a previous-period baseline for a specific entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $entity_id   Entity ID.
	 * @param string $start_date  Current start date.
	 * @param string $end_date    Current end date.
	 * @param array  $args        Optional args.
	 * @return array
	 */
	public function get_entity_previous_period_baseline( $entity_type, $entity_id, $start_date, $end_date, $args = array() ) {
		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Trend_Calculator' ) ) {
			return array();
		}

		$trend_calculator = new Opti_Behavior_Smart_Insights_Trend_Calculator();
		$previous_range   = $trend_calculator->get_previous_period_range( $start_date, $end_date );
		if ( empty( $previous_range['from'] ) || empty( $previous_range['to'] ) ) {
			return array();
		}

		$args['limit'] = isset( $args['limit'] ) ? $args['limit'] : 500;

		switch ( sanitize_key( $entity_type ) ) {
			case 'source':
				$rows = class_exists( 'Opti_Behavior_Smart_Insights_Source_Aggregator' ) ? ( new Opti_Behavior_Smart_Insights_Source_Aggregator() )->get_source_metrics( $previous_range['from'], $previous_range['to'], $args ) : array();
				$key  = 'entity_id';
				break;
			case 'campaign':
				$rows = class_exists( 'Opti_Behavior_Smart_Insights_Source_Aggregator' ) ? ( new Opti_Behavior_Smart_Insights_Source_Aggregator() )->get_campaign_metrics( $previous_range['from'], $previous_range['to'], $args ) : array();
				$key  = 'entity_id';
				break;
			case 'device':
				$rows = class_exists( 'Opti_Behavior_Smart_Insights_Device_Aggregator' ) ? ( new Opti_Behavior_Smart_Insights_Device_Aggregator() )->get_device_metrics( $previous_range['from'], $previous_range['to'], $args ) : array();
				$key  = 'entity_id';
				break;
			case 'funnel':
				$rows = class_exists( 'Opti_Behavior_Smart_Insights_Funnel_Aggregator' ) ? ( new Opti_Behavior_Smart_Insights_Funnel_Aggregator() )->get_funnel_metrics( $previous_range['from'], $previous_range['to'], $args ) : array();
				$key  = 'entity_id';
				break;
			case 'cta':
			case 'heatmap':
				$rows = class_exists( 'Opti_Behavior_Smart_Insights_Heatmap_Aggregator' ) ? ( new Opti_Behavior_Smart_Insights_Heatmap_Aggregator() )->get_heatmap_metrics( $previous_range['from'], $previous_range['to'], $args ) : array();
				$key  = 'entity_id';
				break;
			case 'page':
			default:
				$args['include_previous'] = false;
				$rows = class_exists( 'Opti_Behavior_Smart_Insights_Metric_Aggregator' ) ? ( new Opti_Behavior_Smart_Insights_Metric_Aggregator() )->get_page_metrics( $previous_range['from'], $previous_range['to'], $args ) : array();
				$key  = 'entity_id';
				break;
		}

		foreach ( $rows as $row ) {
			if ( isset( $row[ $key ] ) && (string) $row[ $key ] === (string) $entity_id ) {
				$row['previous_period_range'] = $previous_range;
				return $row;
			}
		}

		return array(
			'entity_type'           => sanitize_key( $entity_type ),
			'entity_id'             => (string) $entity_id,
			'previous_period_range' => $previous_range,
			'available'             => false,
		);
	}

	/**
	 * Calculate source/campaign average baselines.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args.
	 * @return array
	 */
	public function get_source_baselines( $start_date, $end_date, $args = array() ) {
		$rows = class_exists( 'Opti_Behavior_Smart_Insights_Source_Aggregator' ) ? ( new Opti_Behavior_Smart_Insights_Source_Aggregator() )->get_source_metrics( $start_date, $end_date, wp_parse_args( $args, array( 'limit' => 500 ) ) ) : array();
		return $this->average_entity_rows( $rows, 'source' );
	}

	/**
	 * Calculate device average baselines.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args.
	 * @return array
	 */
	public function get_device_baselines( $start_date, $end_date, $args = array() ) {
		$rows = class_exists( 'Opti_Behavior_Smart_Insights_Device_Aggregator' ) ? ( new Opti_Behavior_Smart_Insights_Device_Aggregator() )->get_device_metrics( $start_date, $end_date, wp_parse_args( $args, array( 'limit' => 100 ) ) ) : array();
		return $this->average_entity_rows( $rows, 'device' );
	}

	/**
	 * Calculate funnel average baselines.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args.
	 * @return array
	 */
	public function get_funnel_baselines( $start_date, $end_date, $args = array() ) {
		$rows     = class_exists( 'Opti_Behavior_Smart_Insights_Funnel_Aggregator' ) ? ( new Opti_Behavior_Smart_Insights_Funnel_Aggregator() )->get_funnel_metrics( $start_date, $end_date, wp_parse_args( $args, array( 'limit' => 200 ) ) ) : array();
		$baseline = $this->average_entity_rows( $rows, 'funnel', array( 'completion_rate', 'dropoff_rate', 'entries', 'completions' ) );
		$baseline['avg_funnel_completion_rate'] = isset( $baseline['avg_completion_rate'] ) ? $baseline['avg_completion_rate'] : null;
		$baseline['avg_funnel_dropoff_rate']    = isset( $baseline['avg_dropoff_rate'] ) ? $baseline['avg_dropoff_rate'] : null;
		// Average drop between two consecutive steps (the drop-off signal compares its worst step to it).
		$baseline['avg_funnel_step_dropoff_rate'] = class_exists( 'Opti_Behavior_Smart_Insights_Funnel_Aggregator' ) ? Opti_Behavior_Smart_Insights_Funnel_Aggregator::average_step_dropoff( $rows ) : null;
		return $baseline;
	}

	/**
	 * Calculate CTA/click average baselines.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args.
	 * @return array
	 */
	public function get_cta_baselines( $start_date, $end_date, $args = array() ) {
		$rows     = class_exists( 'Opti_Behavior_Smart_Insights_Heatmap_Aggregator' ) ? ( new Opti_Behavior_Smart_Insights_Heatmap_Aggregator() )->get_heatmap_metrics( $start_date, $end_date, wp_parse_args( $args, array( 'limit' => 500 ) ) ) : array();
		$baseline = $this->average_entity_rows( $rows, 'cta', array( 'cta_click_rate', 'click_count', 'cta_click_count', 'click_sessions' ) );
		$baseline['site_avg_cta_click_rate'] = isset( $baseline['avg_cta_click_rate'] ) ? $baseline['avg_cta_click_rate'] : null;
		return $baseline;
	}

	/**
	 * Average common metrics across entity rows.
	 *
	 * @param array  $rows        Entity metric rows.
	 * @param string $entity_type Entity type.
	 * @param array  $metric_keys Optional metric keys.
	 * @return array
	 */
	private function average_entity_rows( $rows, $entity_type, $metric_keys = array() ) {
		$metric_keys = $metric_keys ? $metric_keys : array( 'sessions', 'users', 'pageviews', 'bounce_rate', 'avg_scroll_depth', 'avg_time_on_page', 'conversion_rate' );
		$counts      = array();
		$sums        = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			foreach ( $metric_keys as $key ) {
				if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
					if ( ! isset( $sums[ $key ] ) ) {
						$sums[ $key ]   = 0;
						$counts[ $key ] = 0;
					}
					$sums[ $key ]   += (float) $row[ $key ];
					$counts[ $key ] += 1;
				}
			}
		}

		$baseline = array(
			'baseline_source' => $entity_type . '_aggregates',
			'entity_type'     => $entity_type,
			'entity_count'    => count( (array) $rows ),
			'available'       => ! empty( $rows ),
		);

		foreach ( $sums as $key => $sum ) {
			$baseline[ 'avg_' . $key ] = $counts[ $key ] > 0 ? round( $sum / $counts[ $key ], 2 ) : null;
		}

		return $baseline;
	}

	/**
	 * Calculate from pre-aggregated daily stats.
	 *
	 * @param string $start_date Start datetime.
	 * @param string $end_date   End datetime.
	 * @return array|null
	 */
	private function calculate_from_daily_stats( $start_date, $end_date ) {
		global $wpdb;

		$daily_stats_table = $wpdb->prefix . 'optibehavior_daily_stats';
		if ( ! $this->table_exists( $daily_stats_table ) ) {
			return null;
		}

		$start_day = substr( $start_date, 0, 10 );
		$end_day   = substr( $end_date, 0, 10 );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(sessions) AS sessions,
					SUM(visitors) AS visitors,
					SUM(pageviews) AS pageviews,
					SUM(bounce_sessions) AS bounce_sessions,
					SUM(total_duration) AS total_duration,
					SUM(total_scroll_depth) AS total_scroll_depth,
					SUM(scroll_depth_count) AS scroll_depth_count
				FROM {$daily_stats_table}
				WHERE stat_date BETWEEN %s AND %s",
				$start_day,
				$end_day
			),
			ARRAY_A
		);

		if ( ! $row || empty( $row['sessions'] ) ) {
			return null;
		}

		$sessions           = max( 0, (int) $row['sessions'] );
		$pageviews          = max( 0, (int) $row['pageviews'] );
		$scroll_depth_count = max( 0, (int) $row['scroll_depth_count'] );

		return array(
			'baseline_source'              => 'daily_stats',
			'site_sessions'                => $sessions,
			'site_users'                   => max( 0, (int) $row['visitors'] ),
			'site_pageviews'               => $pageviews,
			'site_bounce_sessions'         => max( 0, (int) $row['bounce_sessions'] ),
			'site_avg_bounce_rate'         => $sessions > 0 ? round( ( (int) $row['bounce_sessions'] / $sessions ) * 100, 2 ) : null,
			'site_avg_scroll_depth'        => $scroll_depth_count > 0 ? round( ( (int) $row['total_scroll_depth'] / $scroll_depth_count ), 2 ) : null,
			'site_avg_time_on_page'        => $sessions > 0 ? round( ( (int) $row['total_duration'] / $sessions ), 2 ) : null,
			'site_avg_exit_rate'           => null,
			'site_avg_conversion_rate'     => null,
			'site_avg_cta_click_rate'      => null,
			'scroll_depth_samples'         => $scroll_depth_count,
			'time_on_page_samples'         => 0,
			'tracking_data_complete'       => $pageviews > 0 && ( $scroll_depth_count / $pageviews ) >= 0.5,
			'time_on_page_fallback_source' => 'session_duration',
		);
	}

	/**
	 * Calculate from raw sessions and pageviews.
	 *
	 * @param string $start_date Start datetime.
	 * @param string $end_date   End datetime.
	 * @param array  $args       Args.
	 * @return array
	 */
	private function calculate_from_raw_tables( $start_date, $end_date, $args ) {
		global $wpdb;

		$sessions_table  = $wpdb->prefix . 'optibehavior_sessions';
		$pageviews_table = $wpdb->prefix . 'optibehavior_pageviews';

		$baseline = array(
			'baseline_source'          => 'raw_tables',
			'site_sessions'            => 0,
			'site_users'               => 0,
			'site_pageviews'           => 0,
			'site_bounce_sessions'     => 0,
			'site_avg_bounce_rate'     => null,
			'site_avg_scroll_depth'    => null,
			'site_avg_time_on_page'    => null,
			'site_avg_exit_rate'       => null,
			'site_avg_conversion_rate' => null,
			'site_avg_cta_click_rate'  => null,
			'scroll_depth_samples'     => 0,
			'time_on_page_samples'     => 0,
			'tracking_data_complete'   => false,
		);

		if ( ! $this->table_exists( $sessions_table ) ) {
			return $baseline;
		}

		$spam_clause          = ! empty( $args['exclude_spam'] ) ? $this->get_spam_exclusion_clause( 's' ) : '';
		$bounce_sessions_expr = $this->column_exists( $sessions_table, 'is_bounce' ) ? 'SUM(CASE WHEN s.is_bounce = 1 THEN 1 ELSE 0 END)' : '0';
		$avg_duration_expr    = $this->column_exists( $sessions_table, 'duration' ) ? 'AVG(NULLIF(s.duration, 0))' : 'NULL';

		$session_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS sessions,
					COUNT(DISTINCT s.visitor_id) AS users,
					{$bounce_sessions_expr} AS bounce_sessions,
					{$avg_duration_expr} AS avg_session_duration
				FROM {$sessions_table} s
				WHERE s.start_time BETWEEN %s AND %s{$spam_clause}",
				$start_date,
				$end_date
			),
			ARRAY_A
		);

		if ( $session_row ) {
			$sessions = max( 0, (int) $session_row['sessions'] );

			$baseline['site_sessions']                 = $sessions;
			$baseline['site_users']                    = max( 0, (int) $session_row['users'] );
			$baseline['site_bounce_sessions']          = max( 0, (int) $session_row['bounce_sessions'] );
			$baseline['site_avg_bounce_rate']          = $sessions > 0 ? round( ( (int) $session_row['bounce_sessions'] / $sessions ) * 100, 2 ) : null;
			$baseline['site_avg_time_on_page']         = ! empty( $session_row['avg_session_duration'] ) ? round( (float) $session_row['avg_session_duration'], 2 ) : null;
			$baseline['time_on_page_fallback_source'] = 'session_duration';
		}

		if ( ! $this->table_exists( $pageviews_table ) ) {
			return $baseline;
		}

		$sample_limit = max( 100, min( 100000, absint( $args['sample_limit'] ) ) );

		$pageview_spam_join  = '';
		$pageview_spam_where = '';
		if ( ! empty( $args['exclude_spam'] ) ) {
			$pageview_spam_join  = "INNER JOIN {$sessions_table} s ON sampled.session_id = s.id";
			$pageview_spam_where = $this->get_spam_exclusion_clause( 's' );
		}

		$has_exit_page    = $this->column_exists( $pageviews_table, 'exit_page' );
		$has_scroll_depth = $this->column_exists( $pageviews_table, 'scroll_depth' );
		$has_time_on_page = $this->column_exists( $pageviews_table, 'time_on_page' );
		$exit_select      = $has_exit_page ? 'exit_page' : '0 AS exit_page';
		$scroll_select    = $has_scroll_depth ? 'scroll_depth' : '0 AS scroll_depth';
		$time_select      = $has_time_on_page ? 'time_on_page' : '0 AS time_on_page';

		$pageview_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS pageviews,
					SUM(CASE WHEN sampled.exit_page = 1 THEN 1 ELSE 0 END) AS exit_pageviews,
					AVG(NULLIF(sampled.scroll_depth, 0)) AS avg_scroll_depth,
					COUNT(NULLIF(sampled.scroll_depth, 0)) AS scroll_depth_samples,
					AVG(NULLIF(sampled.time_on_page, 0)) AS avg_time_on_page,
					COUNT(NULLIF(sampled.time_on_page, 0)) AS time_on_page_samples
				FROM (
					SELECT session_id, {$exit_select}, {$scroll_select}, {$time_select}
					FROM {$pageviews_table}
					WHERE view_time BETWEEN %s AND %s
					ORDER BY view_time DESC
					LIMIT %d
				) sampled
				{$pageview_spam_join}
				WHERE 1=1{$pageview_spam_where}",
				$start_date,
				$end_date,
				$sample_limit
			),
			ARRAY_A
		);

		if ( $pageview_row ) {
			$pageviews      = max( 0, (int) $pageview_row['pageviews'] );
			$scroll_samples = max( 0, (int) $pageview_row['scroll_depth_samples'] );
			$time_samples   = max( 0, (int) $pageview_row['time_on_page_samples'] );

			$baseline['site_pageviews']        = $pageviews;
			$baseline['site_avg_exit_rate']    = $pageviews > 0 ? round( ( (int) $pageview_row['exit_pageviews'] / $pageviews ) * 100, 2 ) : null;
			$baseline['site_avg_scroll_depth'] = $scroll_samples > 0 ? round( (float) $pageview_row['avg_scroll_depth'], 2 ) : null;
			$baseline['scroll_depth_samples']  = $scroll_samples;
			$baseline['time_on_page_samples']  = $time_samples;

			if ( $time_samples > 0 && ! empty( $pageview_row['avg_time_on_page'] ) ) {
				$baseline['site_avg_time_on_page']        = round( (float) $pageview_row['avg_time_on_page'], 2 );
				$baseline['time_on_page_fallback_source'] = 'pageview_time_on_page';
			}

			$baseline['tracking_data_complete'] = $this->is_tracking_data_complete( $pageviews, $scroll_samples, $time_samples );
		}

		return $baseline;
	}

	/**
	 * Fill daily_stats baseline gaps from raw tables when needed.
	 *
	 * Daily stats does not currently expose every Smart Insights baseline metric
	 * (notably exit rate), which can suppress otherwise valid detections.
	 * This keeps daily_stats as the primary source while safely backfilling only
	 * missing values.
	 *
	 * @param array  $baseline   Baseline from daily_stats.
	 * @param string $start_date Start datetime.
	 * @param string $end_date   End datetime.
	 * @param array  $args       Calculation args.
	 * @return array
	 */
	private function enrich_daily_stats_baseline_with_raw_metrics( $baseline, $start_date, $end_date, $args ) {
		$needs_backfill = false;
		foreach ( array( 'site_avg_exit_rate', 'site_avg_conversion_rate', 'site_avg_cta_click_rate' ) as $metric_key ) {
			if ( ! isset( $baseline[ $metric_key ] ) || null === $baseline[ $metric_key ] ) {
				$needs_backfill = true;
				break;
			}
		}

		if ( ! $needs_backfill ) {
			return $baseline;
		}

		$raw_baseline = $this->calculate_from_raw_tables( $start_date, $end_date, $args );
		if ( empty( $raw_baseline ) || ! is_array( $raw_baseline ) ) {
			return $baseline;
		}

		foreach ( array( 'site_avg_exit_rate', 'site_avg_conversion_rate', 'site_avg_cta_click_rate' ) as $metric_key ) {
			if ( ( ! isset( $baseline[ $metric_key ] ) || null === $baseline[ $metric_key ] ) && isset( $raw_baseline[ $metric_key ] ) && null !== $raw_baseline[ $metric_key ] ) {
				$baseline[ $metric_key ] = $raw_baseline[ $metric_key ];
			}
		}

		if ( ! empty( $baseline['site_avg_time_on_page'] ) && empty( $baseline['time_on_page_samples'] ) && ! empty( $raw_baseline['time_on_page_samples'] ) ) {
			$baseline['time_on_page_samples'] = $raw_baseline['time_on_page_samples'];
		}

		if ( ! empty( $baseline['site_pageviews'] ) && ! empty( $baseline['scroll_depth_samples'] ) ) {
			$baseline['tracking_data_complete'] = ( (int) $baseline['site_pageviews'] > 0 ) && ( ( (int) $baseline['scroll_depth_samples'] / max( 1, (int) $baseline['site_pageviews'] ) ) >= 0.5 );
		}

		$baseline['baseline_source'] = 'daily_stats+raw_backfill';

		return $baseline;
	}

	/**
	 * Normalize baseline keys and apply safe defaults.
	 *
	 * @param array|null $baseline   Baseline data.
	 * @param string     $start_date Start datetime.
	 * @param string     $end_date   End datetime.
	 * @return array
	 */
	private function normalize_baseline( $baseline, $start_date, $end_date ) {
		$baseline = is_array( $baseline ) ? $baseline : array();
		$defaults = array(
			'baseline_source'              => 'none',
			'site_sessions'                => 0,
			'site_users'                   => 0,
			'site_pageviews'               => 0,
			'site_bounce_sessions'         => 0,
			'site_avg_bounce_rate'         => null,
			'site_avg_scroll_depth'        => null,
			'site_avg_time_on_page'        => null,
			'site_avg_exit_rate'           => null,
			'site_avg_conversion_rate'     => null,
			'site_avg_cta_click_rate'      => null,
			'scroll_depth_samples'         => 0,
			'time_on_page_samples'         => 0,
			'tracking_data_complete'       => false,
			'time_on_page_fallback_source' => '',
		);

		$baseline = wp_parse_args( $baseline, $defaults );

		foreach ( array( 'site_sessions', 'site_users', 'site_pageviews', 'site_bounce_sessions', 'scroll_depth_samples', 'time_on_page_samples' ) as $key ) {
			$baseline[ $key ] = max( 0, (int) $baseline[ $key ] );
		}

		foreach ( array( 'site_avg_bounce_rate', 'site_avg_scroll_depth', 'site_avg_time_on_page', 'site_avg_exit_rate', 'site_avg_conversion_rate', 'site_avg_cta_click_rate' ) as $key ) {
			$baseline[ $key ] = null === $baseline[ $key ] ? null : round( max( 0, (float) $baseline[ $key ] ), 2 );
		}

		$baseline['date_range'] = array(
			'from' => substr( $start_date, 0, 10 ),
			'to'   => substr( $end_date, 0, 10 ),
		);

		return $baseline;
	}

	/**
	 * Check whether a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;

		if ( isset( $this->table_exists[ $table ] ) ) {
			return $this->table_exists[ $table ];
		}

		$this->table_exists[ $table ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $this->table_exists[ $table ];
	}

	/**
	 * Check whether a column exists before referencing it.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private function column_exists( $table, $column ) {
		global $wpdb;

		$key = $table . '.' . $column;
		if ( isset( $this->column_exists[ $key ] ) ) {
			return $this->column_exists[ $key ];
		}

		$this->column_exists[ $key ] = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				DB_NAME,
				$table,
				$column
			)
		);

		return $this->column_exists[ $key ];
	}

	/**
	 * Spam exclusion SQL matching dashboard behavior where possible.
	 *
	 * @param string $table_alias Sessions table alias.
	 * @return string
	 */
	private function get_spam_exclusion_clause( $table_alias = 's' ) {
		global $wpdb;

		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		$prefix         = $table_alias ? $table_alias . '.' : '';
		$conditions     = array();

		if ( $this->column_exists( $sessions_table, 'traffic_type' ) ) {
			$conditions[] = '(' . $prefix . "traffic_type IS NULL OR " . $prefix . "traffic_type = '' OR " . $prefix . "traffic_type NOT IN ('spam', 'bot', 'automated'))";
		}

		if ( $this->column_exists( $sessions_table, 'duration' ) ) {
			$traffic_settings = function_exists( 'get_option' ) ? get_option(
				'opti_behavior_traffic_settings',
				array(
					'spam_detection_enabled'  => true,
					'spam_duration_threshold' => 3,
				)
			) : array();
			if ( ! array_key_exists( 'spam_detection_enabled', $traffic_settings ) || ! empty( $traffic_settings['spam_detection_enabled'] ) ) {
				$spam_duration = isset( $traffic_settings['spam_duration_threshold'] ) ? absint( $traffic_settings['spam_duration_threshold'] ) : 3;
				$conditions[]  = $prefix . 'duration >= ' . max( 0, $spam_duration );
			}
		}

		return $conditions ? ' AND (' . implode( ' AND ', $conditions ) . ')' : '';
	}

	/**
	 * Whether admin metrics currently exclude spam.
	 *
	 * @return bool
	 */
	private function is_spam_excluded() {
		if ( isset( $GLOBALS['opti_behavior_exclude_spam'] ) ) {
			return (bool) $GLOBALS['opti_behavior_exclude_spam'];
		}

		$traffic_settings = function_exists( 'get_option' ) ? get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_detection_enabled' => true,
			)
		) : array();

		if ( ! is_array( $traffic_settings ) ) {
			return true;
		}

		return ! array_key_exists( 'spam_detection_enabled', $traffic_settings ) || ! empty( $traffic_settings['spam_detection_enabled'] );
	}

	/**
	 * Determine whether tracking coverage is strong enough for confidence bonus.
	 *
	 * @param int $pageviews      Pageviews.
	 * @param int $scroll_samples Scroll samples.
	 * @param int $time_samples   Time samples.
	 * @return bool
	 */
	private function is_tracking_data_complete( $pageviews, $scroll_samples, $time_samples ) {
		if ( $pageviews <= 0 ) {
			return false;
		}

		return ( $scroll_samples / $pageviews ) >= 0.5 || ( $time_samples / $pageviews ) >= 0.5;
	}

	/**
	 * Normalize a start date to MySQL datetime.
	 *
	 * @param string $date Date value.
	 * @return string
	 */
	private function normalize_start_datetime( $date ) {
		$date = trim( (string) $date );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date .= ' 00:00:00';
		}

		$timestamp = strtotime( $date );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : gmdate( 'Y-m-d 00:00:00' );
	}

	/**
	 * Normalize an end date to MySQL datetime.
	 *
	 * @param string $date Date value.
	 * @return string
	 */
	private function normalize_end_datetime( $date ) {
		$date = trim( (string) $date );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date .= ' 23:59:59';
		}

		$timestamp = strtotime( $date );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : gmdate( 'Y-m-d 23:59:59' );
	}
}
