<?php
/**
 * Smart Insights Device Aggregator Class
 *
 * Aggregates Free-safe device metrics from visitor/session data.
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
 * Smart Insights Device Aggregator Class.
 *
 * Builds device-segment aggregates while preserving Free/Pro boundaries: the
 * Free provider returns counts and aggregate rates only, not visitor details.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Device_Aggregator {

	/** Cached table checks. @var array */
	private $table_exists = array();

	/** Cached column checks. @var array */
	private $column_exists = array();

	/**
	 * Get device metrics for a date range.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args: limit, min_sessions, exclude_spam.
	 * @return array[]
	 */
	public function get_device_metrics( $start_date, $end_date, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'        => 20,
			'min_sessions' => 0,
			'exclude_spam' => $this->is_spam_excluded(),
		);
		$args     = wp_parse_args( $args, $defaults );

		$start_date   = $this->normalize_start_datetime( $start_date );
		$end_date     = $this->normalize_end_datetime( $end_date );
		$limit        = max( 1, min( 100, absint( $args['limit'] ) ) );
		$min_sessions = max( 0, absint( $args['min_sessions'] ) );

		$sessions_table  = $wpdb->prefix . 'optibehavior_sessions';
		$visitors_table  = $wpdb->prefix . 'optibehavior_visitors';
		$pageviews_table = $wpdb->prefix . 'optibehavior_pageviews';

		if ( ! $this->table_exists( $sessions_table ) ) {
			return array();
		}

		$has_visitors       = $this->table_exists( $visitors_table );
		$has_pageviews      = $this->table_exists( $pageviews_table );
		$visitor_join       = $has_visitors ? "LEFT JOIN {$visitors_table} v ON s.visitor_id = v.id" : '';
		$device_expr        = $has_visitors && $this->column_exists( $visitors_table, 'device_type' ) ? "COALESCE(NULLIF(v.device_type, ''), 'unknown')" : "'unknown'";
		$pageviews_expr     = $this->column_exists( $sessions_table, 'page_views' ) ? 'SUM(COALESCE(s.page_views, 0))' : '0';
		$duration_expr      = $this->column_exists( $sessions_table, 'duration' ) ? 'AVG(NULLIF(s.duration, 0))' : 'NULL';
		$bounce_expr        = $this->column_exists( $sessions_table, 'is_bounce' ) ? 'COUNT(DISTINCT CASE WHEN s.is_bounce = 1 THEN s.id END)' : '0';
		$spam_clause        = ! empty( $args['exclude_spam'] ) ? $this->get_spam_exclusion_clause( 's' ) : '';
		$scroll_select      = 'NULL AS avg_scroll_depth, 0 AS scroll_depth_samples, NULL AS avg_time_on_page, 0 AS time_on_page_samples';
		$pageview_join      = '';

		if ( $has_pageviews ) {
			$has_scroll_depth = $this->column_exists( $pageviews_table, 'scroll_depth' );
			$has_time_on_page = $this->column_exists( $pageviews_table, 'time_on_page' );
			$scroll_expr      = $has_scroll_depth ? 'AVG(NULLIF(pv.scroll_depth, 0))' : 'NULL';
			$scroll_count     = $has_scroll_depth ? 'COUNT(NULLIF(pv.scroll_depth, 0))' : '0';
			$time_expr        = $has_time_on_page ? 'AVG(NULLIF(pv.time_on_page, 0))' : 'NULL';
			$time_count       = $has_time_on_page ? 'COUNT(NULLIF(pv.time_on_page, 0))' : '0';
			$pageview_join    = "LEFT JOIN {$pageviews_table} pv ON pv.session_id = s.id AND pv.view_time BETWEEN %s AND %s";
			$pageviews_expr   = 'COUNT(pv.id)';
			$scroll_select    = "{$scroll_expr} AS avg_scroll_depth, {$scroll_count} AS scroll_depth_samples, {$time_expr} AS avg_time_on_page, {$time_count} AS time_on_page_samples";
		}

		$sql = "SELECT
				{$device_expr} AS device_key,
				{$device_expr} AS device_label,
				COUNT(DISTINCT s.id) AS sessions,
				COUNT(DISTINCT s.visitor_id) AS users,
				{$pageviews_expr} AS pageviews,
				{$bounce_expr} AS bounce_sessions,
				{$duration_expr} AS avg_session_duration,
				{$scroll_select},
				MAX(s.start_time) AS last_seen_at
			FROM {$sessions_table} s
			{$visitor_join}
			{$pageview_join}
			WHERE s.start_time BETWEEN %s AND %s{$spam_clause}
			GROUP BY device_key
			HAVING sessions >= %d
			ORDER BY sessions DESC
			LIMIT %d";

		if ( $has_pageviews ) {
			$prepared = $wpdb->prepare( $sql, $start_date, $end_date, $start_date, $end_date, $min_sessions, $limit );
		} else {
			$prepared = $wpdb->prepare( $sql, $start_date, $end_date, $min_sessions, $limit );
		}

		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		if ( ! $rows ) {
			return array();
		}

		$metrics = array();
		foreach ( $rows as $row ) {
			$sessions       = max( 0, (int) $row['sessions'] );
			$pageviews      = max( 0, (int) $row['pageviews'] );
			$scroll_samples = max( 0, (int) $row['scroll_depth_samples'] );
			$time_samples   = max( 0, (int) $row['time_on_page_samples'] );
			$avg_time       = ! empty( $row['avg_time_on_page'] ) ? (float) $row['avg_time_on_page'] : (float) $row['avg_session_duration'];
			$device         = sanitize_key( (string) $row['device_key'] );

			$metrics[] = array(
				'device_key'                  => $device,
				'device_label'                => sanitize_text_field( (string) $row['device_label'] ),
				'entity_type'                 => 'device',
				'entity_id'                   => $device,
				'entity_label'                => ucfirst( sanitize_text_field( (string) $row['device_label'] ) ),
				'sessions'                    => $sessions,
				'users'                       => max( 0, (int) $row['users'] ),
				'pageviews'                   => $pageviews,
				'bounce_sessions'             => max( 0, (int) $row['bounce_sessions'] ),
				'bounce_rate'                 => $sessions > 0 ? round( ( (int) $row['bounce_sessions'] / $sessions ) * 100, 2 ) : null,
				'avg_scroll_depth'            => $scroll_samples > 0 ? round( (float) $row['avg_scroll_depth'], 2 ) : null,
				'scroll_depth_samples'        => $scroll_samples,
				'avg_time_on_page'            => $avg_time > 0 ? round( $avg_time, 2 ) : null,
				'time_on_page_samples'        => $time_samples,
				'time_on_page_fallback_used'  => 0 === $time_samples && $avg_time > 0,
				'conversion_rate'             => null,
				'tracking_data_complete'      => $this->is_tracking_data_complete( $pageviews, $scroll_samples, $time_samples ),
				'last_seen_at'                => isset( $row['last_seen_at'] ) ? (string) $row['last_seen_at'] : '',
			);
		}

		return apply_filters( 'opti_behavior_smart_insights_device_metrics', $metrics, array( 'start' => $start_date, 'end' => $end_date ), $args );
	}

	/** Alias for naming consistency. */
	public function aggregate_device_metrics( $start_date, $end_date, $args = array() ) {
		return $this->get_device_metrics( $start_date, $end_date, $args );
	}

	/** Check whether a table exists. */
	private function table_exists( $table ) {
		global $wpdb;
		if ( isset( $this->table_exists[ $table ] ) ) {
			return $this->table_exists[ $table ];
		}
		$this->table_exists[ $table ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $this->table_exists[ $table ];
	}

	/** Check whether a column exists. */
	private function column_exists( $table, $column ) {
		global $wpdb;
		$key = $table . '.' . $column;
		if ( isset( $this->column_exists[ $key ] ) ) {
			return $this->column_exists[ $key ];
		}
		$this->column_exists[ $key ] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s", DB_NAME, $table, $column ) );
		return $this->column_exists[ $key ];
	}

	/** Spam exclusion SQL matching dashboard behavior. */
	private function get_spam_exclusion_clause( $table_alias = 's' ) {
		global $wpdb;
		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		$prefix         = $table_alias ? $table_alias . '.' : '';
		$conditions     = array();

		if ( $this->column_exists( $sessions_table, 'traffic_type' ) ) {
			$conditions[] = '(' . $prefix . "traffic_type IS NULL OR " . $prefix . "traffic_type = '' OR " . $prefix . "traffic_type NOT IN ('spam', 'bot', 'automated'))";
		}

		if ( $this->column_exists( $sessions_table, 'duration' ) ) {
			$settings = function_exists( 'get_option' ) ? get_option( 'opti_behavior_traffic_settings', array( 'spam_detection_enabled' => true, 'spam_duration_threshold' => 3 ) ) : array();
			if ( ! array_key_exists( 'spam_detection_enabled', $settings ) || ! empty( $settings['spam_detection_enabled'] ) ) {
				$conditions[] = $prefix . 'duration >= ' . max( 0, isset( $settings['spam_duration_threshold'] ) ? absint( $settings['spam_duration_threshold'] ) : 3 );
			}
		}

		return $conditions ? ' AND (' . implode( ' AND ', $conditions ) . ')' : '';
	}

	/** Whether admin metrics currently exclude spam. */
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

	/** Determine tracking coverage. */
	private function is_tracking_data_complete( $pageviews, $scroll_samples, $time_samples ) {
		if ( $pageviews <= 0 ) {
			return false;
		}
		return ( $scroll_samples / $pageviews ) >= 0.5 || ( $time_samples / $pageviews ) >= 0.5;
	}

	/** Normalize a start date to MySQL datetime. */
	private function normalize_start_datetime( $date ) {
		$date = trim( (string) $date );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date .= ' 00:00:00';
		}
		$timestamp = strtotime( $date );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : gmdate( 'Y-m-d 00:00:00' );
	}

	/** Normalize an end date to MySQL datetime. */
	private function normalize_end_datetime( $date ) {
		$date = trim( (string) $date );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date .= ' 23:59:59';
		}
		$timestamp = strtotime( $date );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : gmdate( 'Y-m-d 23:59:59' );
	}
}
