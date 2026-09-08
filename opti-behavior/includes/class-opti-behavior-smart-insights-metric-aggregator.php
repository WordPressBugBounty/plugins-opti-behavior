<?php
/**
 * Smart Insights Metric Aggregator Class
 *
 * Builds page-level aggregate metrics used by Smart Insights signals.
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
 * Smart Insights Metric Aggregator Class
 *
 * Calculates page-level metrics from existing session/pageview storage. Queries
 * are guarded by table/column existence checks so partially upgraded installs do
 * not crash admin requests before dbDelta self-healing finishes.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Metric_Aggregator {

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
	 * Get page-level metrics for a date range.
	 *
	 * Percent values are returned on a 0-100 scale.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args: limit, min_sessions, exclude_spam.
	 * @return array[]
	 */
	public function get_page_metrics( $start_date, $end_date, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'        => 50,
			'min_sessions' => 0,
			'exclude_spam' => $this->is_spam_excluded(),
			'include_previous' => false,
			'include_event_counts' => true,
		);
		$args     = wp_parse_args( $args, $defaults );

		$start_date   = $this->normalize_start_datetime( $start_date );
		$end_date     = $this->normalize_end_datetime( $end_date );
		$limit        = max( 1, min( 500, absint( $args['limit'] ) ) );
		$min_sessions = max( 0, absint( $args['min_sessions'] ) );

		$pageviews_table = $wpdb->prefix . 'optibehavior_pageviews';
		$sessions_table  = $wpdb->prefix . 'optibehavior_sessions';
		$pages_table     = $wpdb->prefix . 'optibehavior_pages';

		if ( ! $this->table_exists( $pageviews_table ) || ! $this->table_exists( $sessions_table ) ) {
			return array();
		}

		$has_pages_table = $this->table_exists( $pages_table );
		$page_join       = $has_pages_table ? "LEFT JOIN {$pages_table} p ON pv.page_id = p.id" : '';
		$page_url_parts  = array( 'pv.url' );
		if ( $has_pages_table && $this->column_exists( $pages_table, 'url' ) ) {
			array_unshift( $page_url_parts, "NULLIF(p.url, '')" );
		}
		if ( $has_pages_table && $this->column_exists( $pages_table, 'url2' ) ) {
			array_unshift( $page_url_parts, "NULLIF(p.url2, '')" );
		}
		$url_expr     = 'COALESCE(' . implode( ', ', $page_url_parts ) . ')';
		$title_expr   = $has_pages_table && $this->column_exists( $pages_table, 'title' ) ? "COALESCE(NULLIF(p.title, ''), NULLIF(pv.title, ''), {$url_expr})" : "COALESCE(NULLIF(pv.title, ''), {$url_expr})";
		$page_id_expr = $has_pages_table ? 'COALESCE(p.id, pv.page_id)' : 'pv.page_id';
		$page_key_expr = "CASE WHEN pv.page_id > 0 THEN CONCAT('id:', pv.page_id) ELSE CONCAT('url:', pv.url) END";

		$has_exit_page    = $this->column_exists( $pageviews_table, 'exit_page' );
		$has_scroll_depth = $this->column_exists( $pageviews_table, 'scroll_depth' );
		$has_time_on_page = $this->column_exists( $pageviews_table, 'time_on_page' );
		$has_duration     = $this->column_exists( $sessions_table, 'duration' );

		$exit_sessions_expr       = $has_exit_page ? 'COUNT(DISTINCT CASE WHEN pv.exit_page = 1 THEN pv.session_id END)' : '0';
		$avg_scroll_expr          = $has_scroll_depth ? 'AVG(NULLIF(pv.scroll_depth, 0))' : 'NULL';
		$scroll_samples_expr      = $has_scroll_depth ? 'COUNT(NULLIF(pv.scroll_depth, 0))' : '0';
		$avg_time_expr            = $has_time_on_page ? 'AVG(NULLIF(pv.time_on_page, 0))' : 'NULL';
		$time_samples_expr        = $has_time_on_page ? 'COUNT(NULLIF(pv.time_on_page, 0))' : '0';
		// Dispersion for the average metrics: sum and sum of squares over the same
		// non-zero samples AVG()/COUNT() already use. They cost nothing extra (same
		// grouped scan) and are what a Welch t test needs to say whether a
		// before/after change in scroll depth or time on page is real.
		$scroll_sum_expr          = $has_scroll_depth ? 'SUM(NULLIF(pv.scroll_depth, 0))' : 'NULL';
		$scroll_sum_sq_expr       = $has_scroll_depth ? 'SUM(POW(NULLIF(pv.scroll_depth, 0), 2))' : 'NULL';
		$time_sum_expr            = $has_time_on_page ? 'SUM(NULLIF(pv.time_on_page, 0))' : 'NULL';
		$time_sum_sq_expr         = $has_time_on_page ? 'SUM(POW(NULLIF(pv.time_on_page, 0), 2))' : 'NULL';
		$session_duration_expr    = $has_duration ? 'AVG(NULLIF(s.duration, 0))' : 'NULL';
		$spam_clause              = ! empty( $args['exclude_spam'] ) ? $this->get_spam_exclusion_clause( 's' ) : '';

		$sql = "SELECT
				{$page_key_expr} AS page_key,
				MAX({$page_id_expr}) AS page_id,
				MIN({$url_expr}) AS page_url,
				MIN({$title_expr}) AS page_title,
				COUNT(DISTINCT pv.session_id) AS sessions,
				COUNT(DISTINCT pv.visitor_id) AS users,
				COUNT(*) AS pageviews,
				COUNT(DISTINCT CASE WHEN s.is_bounce = 1 THEN pv.session_id END) AS bounce_sessions,
				{$exit_sessions_expr} AS exit_sessions,
				{$avg_scroll_expr} AS avg_scroll_depth,
				{$scroll_samples_expr} AS scroll_depth_samples,
				{$scroll_sum_expr} AS scroll_depth_sum,
				{$scroll_sum_sq_expr} AS scroll_depth_sum_squares,
				{$avg_time_expr} AS avg_time_on_page,
				{$time_samples_expr} AS time_on_page_samples,
				{$time_sum_expr} AS time_on_page_sum,
				{$time_sum_sq_expr} AS time_on_page_sum_squares,
				{$session_duration_expr} AS avg_session_duration_fallback,
				MAX(pv.view_time) AS last_seen_at
			FROM {$pageviews_table} pv
			INNER JOIN {$sessions_table} s ON pv.session_id = s.id
			{$page_join}
			WHERE pv.view_time BETWEEN %s AND %s{$spam_clause}
			GROUP BY page_key
			HAVING sessions >= %d
			ORDER BY sessions DESC, pageviews DESC
			LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $start_date, $end_date, $min_sessions, $limit ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}

		$top_page_sessions = 0;
		foreach ( $rows as $row ) {
			$top_page_sessions = max( $top_page_sessions, (int) $row['sessions'] );
		}

		$metrics = array();
		foreach ( $rows as $row ) {
			$sessions             = max( 0, (int) $row['sessions'] );
			$pageviews            = max( 0, (int) $row['pageviews'] );
			$scroll_depth_samples = max( 0, (int) $row['scroll_depth_samples'] );
			$time_samples         = max( 0, (int) $row['time_on_page_samples'] );
			$avg_time_on_page     = $time_samples > 0 ? (float) $row['avg_time_on_page'] : (float) $row['avg_session_duration_fallback'];

			$scroll_coverage = $pageviews > 0 ? round( ( $scroll_depth_samples / $pageviews ) * 100, 2 ) : 0;
			$time_coverage   = $pageviews > 0 ? round( ( $time_samples / $pageviews ) * 100, 2 ) : 0;
			$exit_sessions   = max( 0, (int) $row['exit_sessions'] );

			$metrics[] = array(
				'page_key'                   => (string) $row['page_key'],
				'page_id'                    => isset( $row['page_id'] ) ? (int) $row['page_id'] : 0,
				'page_url'                   => (string) $row['page_url'],
				'page_title'                 => (string) $row['page_title'],
				'entity_type'                => 'page',
				'entity_id'                  => (string) $row['page_url'],
				'entity_label'               => '' !== (string) $row['page_title'] ? (string) $row['page_title'] : (string) $row['page_url'],
				'canonical_label'            => '' !== (string) $row['page_title'] ? (string) $row['page_title'] : (string) $row['page_url'],
				'sessions'                   => $sessions,
				'users'                      => max( 0, (int) $row['users'] ),
				'pageviews'                  => $pageviews,
				'bounce_sessions'            => max( 0, (int) $row['bounce_sessions'] ),
				'bounce_rate'                => $sessions > 0 ? round( ( (int) $row['bounce_sessions'] / $sessions ) * 100, 2 ) : null,
				'exit_sessions'              => $exit_sessions,
				'exit_rate'                  => $has_exit_page && $pageviews > 0 ? round( ( $exit_sessions / $pageviews ) * 100, 2 ) : null,
				'exit_rate_denominator'      => 'pageviews',
				'exit_rate_reliability'      => $this->get_exit_rate_reliability( $has_exit_page, $pageviews, $exit_sessions ),
				'avg_scroll_depth'           => $scroll_depth_samples > 0 ? round( (float) $row['avg_scroll_depth'], 2 ) : null,
				'scroll_depth_samples'       => $scroll_depth_samples,
				// Dispersion pair (sum, sum of squares) over the same samples the
				// average was computed on. Null when the column or the samples are
				// missing, which is what the outcome loop reads as "no Welch t".
				'scroll_depth_sum'           => $scroll_depth_samples > 0 && isset( $row['scroll_depth_sum'] ) ? (float) $row['scroll_depth_sum'] : null,
				'scroll_depth_sum_squares'   => $scroll_depth_samples > 0 && isset( $row['scroll_depth_sum_squares'] ) ? (float) $row['scroll_depth_sum_squares'] : null,
				'scroll_depth_coverage'      => $scroll_coverage,
				'scroll_depth_complete'      => $scroll_coverage >= 50,
				'avg_time_on_page'           => $avg_time_on_page > 0 ? round( $avg_time_on_page, 2 ) : null,
				'time_on_page_samples'       => $time_samples,
				// Only real time-on-page samples carry dispersion; the session-duration
				// fallback used when $time_samples is 0 has none.
				'time_on_page_sum'           => $time_samples > 0 && isset( $row['time_on_page_sum'] ) ? (float) $row['time_on_page_sum'] : null,
				'time_on_page_sum_squares'   => $time_samples > 0 && isset( $row['time_on_page_sum_squares'] ) ? (float) $row['time_on_page_sum_squares'] : null,
				'time_on_page_coverage'      => $time_coverage,
				'time_on_page_complete'      => $time_coverage >= 50,
				'time_on_page_fallback_used' => 0 === $time_samples && $avg_time_on_page > 0,
				'top_page_sessions'          => $top_page_sessions,
				// Bug 1 — neutral placeholders. The Free plugin owns no conversion
				// definition, so both stay null here and the layer that does own one
				// (Pro, via `opti_behavior_smart_insights_page_metrics`) fills them.
				// null means "no conversion source at all"; a layer that HAS a source
				// must emit 0 / 0.0 for zero-conversion pages, never null, or the
				// worst pages stay invisible to detect_poor_conversion_rate().
				'conversions'                => null,
				'conversion_rate'            => null,
				'cta_click_rate'             => null,
				'click_count'                => 0,
				'cta_click_count'            => 0,
				'click_sessions'             => 0,
				'tracking_data_complete'     => $this->is_tracking_data_complete( $pageviews, $scroll_depth_samples, $time_samples ),
				'last_seen_at'               => isset( $row['last_seen_at'] ) ? (string) $row['last_seen_at'] : '',
			);
		}

		if ( ! empty( $args['include_event_counts'] ) ) {
			$metrics = $this->append_page_event_metrics( $metrics, $start_date, $end_date, $args );
		}

		// The enrichment filter runs BEFORE the trend snapshot, never after.
		// attach_entity_trends() freezes current-vs-previous pairs for every metric
		// key it is given; the previous rows are already enriched (they come from the
		// recursive get_page_metrics() call below, which re-applies this filter), so
		// applying it here is what makes the CURRENT side of every pair enriched too.
		// Applying it afterwards left trend[<filtered key>]['current'] permanently
		// null for every key a filtering layer owns — conversions/conversion_rate in
		// practice, but the ordering is wrong for any such key.
		$metrics = apply_filters(
			'opti_behavior_smart_insights_page_metrics',
			$metrics,
			array(
				'start' => $start_date,
				'end'   => $end_date,
			),
			$args
		);

		if ( ! empty( $args['include_previous'] ) ) {
			$previous_args = $args;
			$previous_args['include_previous']    = false;
			$previous_args['include_event_counts'] = ! empty( $args['include_event_counts'] );
			$metrics = $this->append_previous_period_metrics( $metrics, $start_date, $end_date, $previous_args );
		}

		return $metrics;
	}

	/**
	 * Alias retained for generator readability.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args.
	 * @return array[]
	 */
	public function aggregate_page_metrics( $start_date, $end_date, $args = array() ) {
		return $this->get_page_metrics( $start_date, $end_date, $args );
	}

	/**
	 * Append CTA-safe heatmap counts to page metrics.
	 *
	 * @param array  $metrics    Page metrics.
	 * @param string $start_date Start datetime.
	 * @param string $end_date   End datetime.
	 * @param array  $args       Aggregation args.
	 * @return array
	 */
	private function append_page_event_metrics( $metrics, $start_date, $end_date, $args = array() ) {
		if ( empty( $metrics ) || ! class_exists( 'Opti_Behavior_Smart_Insights_Heatmap_Aggregator' ) ) {
			return $metrics;
		}

		$page_ids = array();
		foreach ( $metrics as $row ) {
			if ( ! empty( $row['page_id'] ) ) {
				$page_ids[] = (int) $row['page_id'];
			}
		}

		$page_ids = array_values( array_unique( array_filter( $page_ids ) ) );
		if ( empty( $page_ids ) ) {
			return $metrics;
		}

		$heatmap_aggregator = new Opti_Behavior_Smart_Insights_Heatmap_Aggregator();
		$event_counts       = $heatmap_aggregator->get_page_event_counts(
			$start_date,
			$end_date,
			$page_ids,
			array(
				'exclude_spam' => isset( $args['exclude_spam'] ) ? $args['exclude_spam'] : $this->is_spam_excluded(),
			)
		);

		foreach ( $metrics as $index => $row ) {
			$page_id = isset( $row['page_id'] ) ? (int) $row['page_id'] : 0;
			if ( ! $page_id || empty( $event_counts[ $page_id ] ) ) {
				continue;
			}

			$counts                                      = $event_counts[ $page_id ];
			$metrics[ $index ]['click_count']           = isset( $counts['click_count'] ) ? (int) $counts['click_count'] : 0;
			$metrics[ $index ]['cta_click_count']       = isset( $counts['cta_click_count'] ) ? (int) $counts['cta_click_count'] : 0;
			$metrics[ $index ]['click_sessions']        = isset( $counts['click_sessions'] ) ? (int) $counts['click_sessions'] : 0;
			$metrics[ $index ]['cta_click_rate']        = $row['sessions'] > 0 ? round( ( $metrics[ $index ]['cta_click_count'] / (int) $row['sessions'] ) * 100, 2 ) : null;
			$metrics[ $index ]['event_tracking_source'] = 'optibehavior_events';
		}

		return $metrics;
	}

	/**
	 * Append previous-period rows and trend deltas to page metrics.
	 *
	 * @param array  $metrics    Current page metrics.
	 * @param string $start_date Start datetime.
	 * @param string $end_date   End datetime.
	 * @param array  $args       Aggregation args for the previous period.
	 * @return array
	 */
	private function append_previous_period_metrics( $metrics, $start_date, $end_date, $args ) {
		if ( empty( $metrics ) || ! class_exists( 'Opti_Behavior_Smart_Insights_Trend_Calculator' ) ) {
			return $metrics;
		}

		$trend_calculator = new Opti_Behavior_Smart_Insights_Trend_Calculator();
		$previous_range   = $trend_calculator->get_previous_period_range( $start_date, $end_date );
		if ( empty( $previous_range['from'] ) || empty( $previous_range['to'] ) ) {
			return $metrics;
		}

		// The current period's candidate filters must not be re-applied to the
		// previous period. `min_sessions` is a *detection* threshold ("is this page
		// worth evaluating now"), and `limit` is a candidate cap; carrying either
		// into the lookup window silently dropped the previous row of every page
		// whose traffic was below the threshold back then, which is exactly the
		// page a trend is interesting for. The result was `previous: null` on every
		// metric and "Previous-period trend is not available yet" in the modal.
		// The current period already decided WHICH pages matter; the previous
		// period only has to answer WHAT they measured.
		$previous_args                 = $args;
		$previous_args['min_sessions'] = 0;
		$previous_args['limit']        = max( 1, min( 500, absint( $args['limit'] ) * 2 ) );

		$previous_metrics = $this->get_page_metrics( $previous_range['from'], $previous_range['to'], $previous_args );

		foreach ( $metrics as $index => $row ) {
			$metrics[ $index ]['previous_period_range'] = $previous_range;
		}

		return $trend_calculator->attach_entity_trends(
			$metrics,
			$previous_metrics,
			'page_key',
			// Bug 1 — 'conversions'/'conversion_rate' are listed so trend_pair() takes
			// the fast path instead of falling back to previous_period_metrics. Both
			// sides are enriched by the time this runs: the previous rows by the
			// recursive get_page_metrics() call above, the current rows by the
			// page-metrics filter that get_page_metrics() now applies before calling
			// this method.
			array( 'sessions', 'users', 'pageviews', 'bounce_rate', 'exit_rate', 'avg_scroll_depth', 'avg_time_on_page', 'click_count', 'cta_click_count', 'cta_click_rate', 'conversions', 'conversion_rate' ),
			array( 'bounce_rate', 'exit_rate', 'avg_scroll_depth', 'cta_click_rate' )
		);
	}

	/**
	 * Classify exit-rate reliability for downstream signals.
	 *
	 * @param bool $has_exit_page Whether the source column exists.
	 * @param int  $pageviews     Pageview count.
	 * @param int  $exit_sessions Exit sessions.
	 * @return string
	 */
	private function get_exit_rate_reliability( $has_exit_page, $pageviews, $exit_sessions ) {
		if ( ! $has_exit_page ) {
			return 'unavailable';
		}

		if ( $pageviews < 30 ) {
			return 'low_volume';
		}

		if ( $pageviews >= 100 && $exit_sessions > 0 ) {
			return 'high';
		}

		return 'medium';
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
	 * Check whether a column exists before using migration-sensitive fields.
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
	 * Get spam exclusion SQL matching dashboard behavior when requested.
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
	 * Determine whether enough tracking fields are present for high confidence.
	 *
	 * @param int $pageviews            Pageview count.
	 * @param int $scroll_depth_samples Scroll samples.
	 * @param int $time_samples         Time samples.
	 * @return bool
	 */
	private function is_tracking_data_complete( $pageviews, $scroll_depth_samples, $time_samples ) {
		if ( $pageviews <= 0 ) {
			return false;
		}

		$scroll_ratio = $scroll_depth_samples / $pageviews;
		$time_ratio   = $time_samples / $pageviews;

		return $scroll_ratio >= 0.5 || $time_ratio >= 0.5;
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
