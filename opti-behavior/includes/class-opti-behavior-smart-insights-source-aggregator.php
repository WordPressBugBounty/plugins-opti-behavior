<?php
/**
 * Smart Insights Source Aggregator Class
 *
 * Aggregates Free-safe source and campaign metrics from session data.
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
 * Smart Insights Source Aggregator Class.
 *
 * Builds deterministic source/campaign metrics without reading visitor-level
 * replay payloads. Missing marketing columns gracefully degrade to Direct.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Source_Aggregator {

	/** Cached table checks. @var array */
	private $table_exists = array();

	/** Cached column checks. @var array */
	private $column_exists = array();

	/**
	 * Get traffic-source metrics for a date range.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args: limit, min_sessions, exclude_spam.
	 * @return array[]
	 */
	public function get_source_metrics( $start_date, $end_date, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'        => 50,
			'min_sessions' => 0,
			'exclude_spam' => $this->is_spam_excluded(),
		);
		$args     = wp_parse_args( $args, $defaults );

		// Resolve the spam scope to an explicit boolean before it is used, so the
		// value handed to the enrichment filter is exactly the scope this
		// aggregation applied (callers may pass an explicit null). A layer that
		// enriches these rows must not widen or narrow that scope, or its numerator
		// would no longer match the `sessions` denominator computed below.
		$args['exclude_spam'] = ! empty( $args['exclude_spam'] );

		$start_date   = $this->normalize_start_datetime( $start_date );
		$end_date     = $this->normalize_end_datetime( $end_date );
		$limit        = max( 1, min( 500, absint( $args['limit'] ) ) );
		$min_sessions = max( 0, absint( $args['min_sessions'] ) );

		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		if ( ! $this->table_exists( $sessions_table ) ) {
			return array();
		}

		$source_expr       = $this->get_source_expr( 's' );
		$source_label_expr = $this->get_source_label_expr( 's' );
		$source_type_expr  = $this->get_source_type_expr( 's' );
		$pageviews_expr    = $this->column_exists( $sessions_table, 'page_views' ) ? 'SUM(COALESCE(s.page_views, 0))' : '0';
		$duration_expr     = $this->column_exists( $sessions_table, 'duration' ) ? 'AVG(NULLIF(s.duration, 0))' : 'NULL';
		$bounce_expr       = $this->column_exists( $sessions_table, 'is_bounce' ) ? 'SUM(CASE WHEN s.is_bounce = 1 THEN 1 ELSE 0 END)' : '0';
		$spam_clause       = ! empty( $args['exclude_spam'] ) ? $this->get_spam_exclusion_clause( 's' ) : '';

		$sql = "SELECT
				{$source_expr} AS source_key,
				MIN({$source_label_expr}) AS source_label,
				MIN({$source_type_expr}) AS source_type,
				COUNT(*) AS sessions,
				COUNT(DISTINCT s.visitor_id) AS users,
				{$pageviews_expr} AS pageviews,
				{$bounce_expr} AS bounce_sessions,
				{$duration_expr} AS avg_session_duration,
				MAX(s.start_time) AS last_seen_at
			FROM {$sessions_table} s
			WHERE s.start_time BETWEEN %s AND %s{$spam_clause}
			GROUP BY source_key
			HAVING sessions >= %d
			ORDER BY sessions DESC
			LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $start_date, $end_date, $min_sessions, $limit ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}

		// `conversions` and `conversion_rate` are NEUTRAL placeholders, mirroring the
		// page aggregator. The Free plugin owns no conversion definition, so both
		// stay null here and the layer that does own one fills them through the
		// filter below. null means "no conversion source at all"; a layer that HAS a
		// source must emit 0 / 0.0 for zero-conversion sources, never null, or the
		// worst-performing sources stay invisible to the detectors' has_number().
		$metrics = array();
		foreach ( $rows as $row ) {
			$sessions = max( 0, (int) $row['sessions'] );
			$metrics[] = array(
				'source_key'             => sanitize_text_field( (string) $row['source_key'] ),
				'source_label'           => sanitize_text_field( (string) $row['source_label'] ),
				'source_type'            => sanitize_key( (string) $row['source_type'] ),
				'entity_type'            => 'source',
				'entity_id'              => sanitize_text_field( (string) $row['source_key'] ),
				'entity_label'           => sanitize_text_field( (string) $row['source_label'] ),
				'sessions'               => $sessions,
				'users'                  => max( 0, (int) $row['users'] ),
				'pageviews'              => max( 0, (int) $row['pageviews'] ),
				'bounce_sessions'        => max( 0, (int) $row['bounce_sessions'] ),
				'bounce_rate'            => $sessions > 0 ? round( ( (int) $row['bounce_sessions'] / $sessions ) * 100, 2 ) : null,
				'avg_session_duration'   => ! empty( $row['avg_session_duration'] ) ? round( (float) $row['avg_session_duration'], 2 ) : null,
				'conversions'            => null,
				'conversion_rate'        => null,
				'avg_scroll_depth'       => null,
				'tracking_data_complete' => $sessions > 0,
				'last_seen_at'           => isset( $row['last_seen_at'] ) ? (string) $row['last_seen_at'] : '',
			);
		}

		// $args is passed through so an enriching layer can reuse the exact spam
		// scope this aggregation used; mixing scopes would make a source rate and
		// the site baseline incomparable.
		return apply_filters(
			'opti_behavior_smart_insights_source_metrics',
			$metrics,
			array(
				'start' => $start_date,
				'end'   => $end_date,
			),
			$args
		);
	}

	/**
	 * Map session ids to the source key this aggregator groups them under.
	 *
	 * Source classification (utm / referrer host / direct) is a Free concept and
	 * lives in exactly one place — the SQL expression used by get_source_metrics().
	 * This helper exposes that same expression per session so a layer enriching
	 * source rows through `opti_behavior_smart_insights_source_metrics` can key its
	 * own aggregates identically instead of re-implementing the classification.
	 *
	 * @since 1.3.4
	 * @param string[] $session_ids Session ids.
	 * @return array<string,string> Session id => source key.
	 */
	public function get_source_keys_for_sessions( $session_ids ) {
		global $wpdb;

		$session_ids = array_values( array_unique( array_filter( array_map( 'strval', (array) $session_ids ) ) ) );
		if ( empty( $session_ids ) ) {
			return array();
		}

		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		if ( ! $this->table_exists( $sessions_table ) ) {
			return array();
		}

		$source_expr = $this->get_source_expr( 's' );
		$map         = array();

		foreach ( array_chunk( $session_ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a generated %s list matching $chunk one-for-one.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id AS session_id, {$source_expr} AS source_key FROM {$sessions_table} s WHERE s.id IN ({$placeholders})",
					$chunk
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			foreach ( (array) $rows as $row ) {
				$map[ (string) $row['session_id'] ] = (string) $row['source_key'];
			}
		}

		return $map;
	}

	/**
	 * Get campaign metrics from UTM campaign/source/medium combinations.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args.
	 * @return array[]
	 */
	public function get_campaign_metrics( $start_date, $end_date, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'        => 50,
			'min_sessions' => 0,
			'exclude_spam' => $this->is_spam_excluded(),
		);
		$args     = wp_parse_args( $args, $defaults );

		$start_date   = $this->normalize_start_datetime( $start_date );
		$end_date     = $this->normalize_end_datetime( $end_date );
		$limit        = max( 1, min( 500, absint( $args['limit'] ) ) );
		$min_sessions = max( 0, absint( $args['min_sessions'] ) );

		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		if ( ! $this->table_exists( $sessions_table ) || ! $this->column_exists( $sessions_table, 'utm_campaign' ) ) {
			return array();
		}

		$utm_source_expr = $this->column_exists( $sessions_table, 'utm_source' ) ? "COALESCE(NULLIF(s.utm_source, ''), '(not set)')" : "'(not set)'";
		$utm_medium_expr = $this->column_exists( $sessions_table, 'utm_medium' ) ? "COALESCE(NULLIF(s.utm_medium, ''), '(not set)')" : "'(not set)'";
		$pageviews_expr  = $this->column_exists( $sessions_table, 'page_views' ) ? 'SUM(COALESCE(s.page_views, 0))' : '0';
		$duration_expr   = $this->column_exists( $sessions_table, 'duration' ) ? 'AVG(NULLIF(s.duration, 0))' : 'NULL';
		$bounce_expr     = $this->column_exists( $sessions_table, 'is_bounce' ) ? 'SUM(CASE WHEN s.is_bounce = 1 THEN 1 ELSE 0 END)' : '0';
		$spam_clause     = ! empty( $args['exclude_spam'] ) ? $this->get_spam_exclusion_clause( 's' ) : '';

		$sql = "SELECT
				CONCAT('campaign:', COALESCE(NULLIF(s.utm_campaign, ''), '(not set)'), '|', {$utm_source_expr}, '|', {$utm_medium_expr}) AS campaign_key,
				COALESCE(NULLIF(s.utm_campaign, ''), '(not set)') AS campaign_name,
				{$utm_source_expr} AS utm_source,
				{$utm_medium_expr} AS utm_medium,
				COUNT(*) AS sessions,
				COUNT(DISTINCT s.visitor_id) AS users,
				{$pageviews_expr} AS pageviews,
				{$bounce_expr} AS bounce_sessions,
				{$duration_expr} AS avg_session_duration,
				MAX(s.start_time) AS last_seen_at
			FROM {$sessions_table} s
			WHERE s.start_time BETWEEN %s AND %s{$spam_clause}
				AND s.utm_campaign IS NOT NULL AND s.utm_campaign != ''
			GROUP BY campaign_key, campaign_name, utm_source, utm_medium
			HAVING sessions >= %d
			ORDER BY sessions DESC
			LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $start_date, $end_date, $min_sessions, $limit ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}

		$metrics = array();
		foreach ( $rows as $row ) {
			$sessions = max( 0, (int) $row['sessions'] );
			$label    = sprintf( '%s (%s / %s)', (string) $row['campaign_name'], (string) $row['utm_source'], (string) $row['utm_medium'] );
			$metrics[] = array(
				'campaign_key'           => sanitize_text_field( (string) $row['campaign_key'] ),
				'campaign_name'          => sanitize_text_field( (string) $row['campaign_name'] ),
				'utm_source'             => sanitize_text_field( (string) $row['utm_source'] ),
				'utm_medium'             => sanitize_text_field( (string) $row['utm_medium'] ),
				'entity_type'            => 'campaign',
				'entity_id'              => sanitize_text_field( (string) $row['campaign_key'] ),
				'entity_label'           => sanitize_text_field( $label ),
				'sessions'               => $sessions,
				'users'                  => max( 0, (int) $row['users'] ),
				'pageviews'              => max( 0, (int) $row['pageviews'] ),
				'bounce_sessions'        => max( 0, (int) $row['bounce_sessions'] ),
				'bounce_rate'            => $sessions > 0 ? round( ( (int) $row['bounce_sessions'] / $sessions ) * 100, 2 ) : null,
				'avg_session_duration'   => ! empty( $row['avg_session_duration'] ) ? round( (float) $row['avg_session_duration'], 2 ) : null,
				'conversion_rate'        => null,
				'tracking_data_complete' => $sessions > 0,
				'last_seen_at'           => isset( $row['last_seen_at'] ) ? (string) $row['last_seen_at'] : '',
			);
		}

		return apply_filters( 'opti_behavior_smart_insights_campaign_metrics', $metrics, array( 'start' => $start_date, 'end' => $end_date ) );
	}

	/** Alias for naming consistency. */
	public function aggregate_source_metrics( $start_date, $end_date, $args = array() ) {
		return $this->get_source_metrics( $start_date, $end_date, $args );
	}

	/** Alias for naming consistency. */
	public function aggregate_campaign_metrics( $start_date, $end_date, $args = array() ) {
		return $this->get_campaign_metrics( $start_date, $end_date, $args );
	}

	/** Get SQL expression for a source key. */
	private function get_source_expr( $alias ) {
		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_sessions';
		$pre   = $alias ? $alias . '.' : '';
		$host  = "LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE({$pre}referrer, 'https://', ''), 'http://', ''), '/', 1), ':', 1))";
		$medium_expr = $this->column_exists( $table, 'utm_medium' ) ? "COALESCE(NULLIF(LOWER({$pre}utm_medium), ''), '(not set)')" : "'(not set)'";

		if ( $this->column_exists( $table, 'utm_source' ) && $this->column_exists( $table, 'referrer' ) ) {
			return "CASE WHEN {$pre}utm_source IS NOT NULL AND {$pre}utm_source != '' THEN CONCAT('utm:', LOWER({$pre}utm_source), '/', {$medium_expr}) WHEN {$pre}referrer IS NOT NULL AND {$pre}referrer != '' THEN CONCAT('referrer:', {$host}) ELSE 'direct' END";
		}

		if ( $this->column_exists( $table, 'referrer' ) ) {
			return "CASE WHEN {$pre}referrer IS NOT NULL AND {$pre}referrer != '' THEN CONCAT('referrer:', {$host}) ELSE 'direct' END";
		}

		return "'direct'";
	}

	/** Get SQL expression for a source label. */
	private function get_source_label_expr( $alias ) {
		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_sessions';
		$pre   = $alias ? $alias . '.' : '';
		$host  = "LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE({$pre}referrer, 'https://', ''), 'http://', ''), '/', 1), ':', 1))";
		$medium_expr = $this->column_exists( $table, 'utm_medium' ) ? "COALESCE(NULLIF({$pre}utm_medium, ''), '(not set)')" : "'(not set)'";

		if ( $this->column_exists( $table, 'utm_source' ) && $this->column_exists( $table, 'referrer' ) ) {
			return "CASE WHEN {$pre}utm_source IS NOT NULL AND {$pre}utm_source != '' THEN CONCAT({$pre}utm_source, ' / ', {$medium_expr}) WHEN {$pre}referrer IS NOT NULL AND {$pre}referrer != '' THEN {$host} ELSE 'Direct' END";
		}

		if ( $this->column_exists( $table, 'referrer' ) ) {
			return "CASE WHEN {$pre}referrer IS NOT NULL AND {$pre}referrer != '' THEN {$host} ELSE 'Direct' END";
		}

		return "'Direct'";
	}

	/** Get SQL expression for source type. */
	private function get_source_type_expr( $alias ) {
		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_sessions';
		$pre   = $alias ? $alias . '.' : '';

		if ( $this->column_exists( $table, 'utm_source' ) && $this->column_exists( $table, 'referrer' ) ) {
			return "CASE WHEN {$pre}utm_source IS NOT NULL AND {$pre}utm_source != '' THEN 'utm' WHEN {$pre}referrer IS NOT NULL AND {$pre}referrer != '' THEN 'referrer' ELSE 'direct' END";
		}

		if ( $this->column_exists( $table, 'referrer' ) ) {
			return "CASE WHEN {$pre}referrer IS NOT NULL AND {$pre}referrer != '' THEN 'referrer' ELSE 'direct' END";
		}

		return "'direct'";
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
