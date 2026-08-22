<?php
/**
 * Smart Insights Funnel Aggregator Class
 *
 * Aggregates funnel definition and tracking data for Smart Insights.
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
 * Smart Insights Funnel Aggregator Class.
 *
 * Reads only aggregate tracking rows from Free funnel tables. Step labels come
 * from the stored funnel definition, while counts are grouped by max step.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Funnel_Aggregator {

	/** Cached table checks. @var array */
	private $table_exists = array();

	/** Cached column checks. @var array */
	private $column_exists = array();

	/**
	 * Get funnel metrics for a date range.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args: limit, min_entries, exclude_spam.
	 * @return array[]
	 */
	public function get_funnel_metrics( $start_date, $end_date, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'        => 50,
			'min_entries'  => 0,
			'exclude_spam' => $this->is_spam_excluded(),
		);
		$args     = wp_parse_args( $args, $defaults );

		$start_date  = $this->normalize_start_datetime( $start_date );
		$end_date    = $this->normalize_end_datetime( $end_date );
		$limit       = max( 1, min( 200, absint( $args['limit'] ) ) );
		$min_entries = max( 0, absint( $args['min_entries'] ) );

		$funnels_table  = $wpdb->prefix . 'opti_behavior_funnels';
		$tracking_table = $wpdb->prefix . 'opti_behavior_funnel_tracking';
		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';

		if ( ! $this->table_exists( $funnels_table ) || ! $this->table_exists( $tracking_table ) ) {
			return array();
		}

		$spam_clause = ! empty( $args['exclude_spam'] ) ? $this->get_tracking_spam_exclusion_clause( 't', $sessions_table ) : '';

		$status_clause = $this->column_exists( $funnels_table, 'status' ) ? "AND (f.status = 'active' OR f.status IS NULL OR f.status = '')" : '';

		$sql = "SELECT
				f.id AS funnel_id,
				f.name AS funnel_name,
				f.steps AS steps_json,
				COUNT(t.id) AS entries,
				COUNT(DISTINCT t.session_id) AS sessions,
				SUM(CASE WHEN t.completed = 1 THEN 1 ELSE 0 END) AS completions,
				AVG(t.max_step_reached) AS avg_max_step_reached,
				MAX(t.max_step_reached) AS observed_max_step,
				MAX(t.last_activity) AS last_seen_at
			FROM {$funnels_table} f
			LEFT JOIN {$tracking_table} t ON t.funnel_id = f.id AND t.entry_time BETWEEN %s AND %s{$spam_clause}
			WHERE 1=1 {$status_clause}
			GROUP BY f.id, f.name, f.steps
			HAVING entries >= %d
			ORDER BY entries DESC, completions DESC
			LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $start_date, $end_date, $min_entries, $limit ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}

		$step_counts = $this->get_step_counts( wp_list_pluck( $rows, 'funnel_id' ), $start_date, $end_date, $args );
		$metrics     = array();

		foreach ( $rows as $row ) {
			$entries        = max( 0, (int) $row['entries'] );
			$completions    = max( 0, (int) $row['completions'] );
			$steps          = $this->parse_steps( isset( $row['steps_json'] ) ? $row['steps_json'] : '' );
			$funnel_id      = (int) $row['funnel_id'];
			$completion_rate = $entries > 0 ? round( ( $completions / $entries ) * 100, 2 ) : null;
			$dropoff_rate    = null === $completion_rate ? null : round( 100 - $completion_rate, 2 );

			$metrics[] = array(
				'funnel_id'              => $funnel_id,
				'funnel_name'            => sanitize_text_field( (string) $row['funnel_name'] ),
				'entity_type'            => 'funnel',
				'entity_id'              => (string) $funnel_id,
				'entity_label'           => sanitize_text_field( (string) $row['funnel_name'] ),
				'entries'                => $entries,
				'sessions'               => max( 0, (int) $row['sessions'] ),
				'completions'            => $completions,
				'completion_rate'        => $completion_rate,
				'dropoff_rate'           => $dropoff_rate,
				'avg_max_step_reached'   => null !== $row['avg_max_step_reached'] ? round( (float) $row['avg_max_step_reached'], 2 ) : null,
				'observed_max_step'      => max( 0, (int) $row['observed_max_step'] ),
				'step_count'             => count( $steps ),
				'steps'                  => $this->build_step_metrics( $steps, isset( $step_counts[ $funnel_id ] ) ? $step_counts[ $funnel_id ] : array(), $entries ),
				'tracking_data_complete' => $entries > 0,
				'last_seen_at'           => isset( $row['last_seen_at'] ) ? (string) $row['last_seen_at'] : '',
			);
		}

		return apply_filters( 'opti_behavior_smart_insights_funnel_metrics', $metrics, array( 'start' => $start_date, 'end' => $end_date, 'exclude_spam' => (bool) $args['exclude_spam'] ) );
	}

	/** Alias for naming consistency. */
	public function aggregate_funnel_metrics( $start_date, $end_date, $args = array() ) {
		return $this->get_funnel_metrics( $start_date, $end_date, $args );
	}

	/** Get counts by max step reached for all candidate funnels. */
	private function get_step_counts( $funnel_ids, $start_date, $end_date, $args = array() ) {
		global $wpdb;

		$funnel_ids = array_values( array_filter( array_map( 'absint', (array) $funnel_ids ) ) );
		if ( empty( $funnel_ids ) ) {
			return array();
		}

		$tracking_table = $wpdb->prefix . 'opti_behavior_funnel_tracking';
		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		$placeholders   = implode( ',', array_fill( 0, count( $funnel_ids ), '%d' ) );
		$params         = array_merge( $funnel_ids, array( $start_date, $end_date ) );
		$spam_clause    = ! empty( $args['exclude_spam'] ) ? $this->get_tracking_spam_exclusion_clause( 't', $sessions_table ) : '';

		$sql  = "SELECT funnel_id, max_step_reached, COUNT(*) AS sessions
			FROM {$tracking_table} t
			WHERE funnel_id IN ({$placeholders}) AND entry_time BETWEEN %s AND %s{$spam_clause}
			GROUP BY funnel_id, max_step_reached";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$funnel_id = (int) $row['funnel_id'];
			$step      = max( 0, (int) $row['max_step_reached'] );
			if ( ! isset( $counts[ $funnel_id ] ) ) {
				$counts[ $funnel_id ] = array();
			}
			$counts[ $funnel_id ][ $step ] = max( 0, (int) $row['sessions'] );
		}

		return $counts;
	}

	/** Parse stored funnel steps. */
	private function parse_steps( $steps_json ) {
		$decoded = json_decode( (string) $steps_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array_values( $decoded );
	}

	/** Build aggregate step metrics from step definitions and max-step counts. */
	private function build_step_metrics( $steps, $step_counts, $entries ) {
		$metrics = array();
		$count   = count( $steps );

		for ( $index = 0; $index < $count; $index++ ) {
			$step_number = $index + 1;
			$reached     = 0;
			foreach ( $step_counts as $max_step => $sessions ) {
				if ( (int) $max_step >= $step_number ) {
					$reached += (int) $sessions;
				}
			}

			$step = is_array( $steps[ $index ] ) ? $steps[ $index ] : array();
			$name = isset( $step['name'] ) ? $step['name'] : ( isset( $step['label'] ) ? $step['label'] : sprintf( 'Step %d', $step_number ) );

			$metrics[] = array(
				'step_number' => $step_number,
				'label'       => sanitize_text_field( (string) $name ),
				'reached'     => $reached,
				'reach_rate'  => $entries > 0 ? round( ( $reached / $entries ) * 100, 2 ) : null,
			);
		}

		return $metrics;
	}

	/**
	 * Resolve the default spam exclusion state.
	 *
	 * @return bool
	 */
	private function is_spam_excluded() {
		if ( array_key_exists( 'opti_behavior_exclude_spam', $GLOBALS ) ) {
			return (bool) $GLOBALS['opti_behavior_exclude_spam'];
		}

		if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
			return Opti_Behavior_Stats_Spam_Filter::is_enabled();
		}

		return true;
	}

	/**
	 * Build a spam exclusion fragment for funnel tracking rows.
	 *
	 * Funnel tracking stores only session IDs, so spam filtering must be applied
	 * against the canonical session table before entries, sessions, and step
	 * reach counts are calculated.
	 *
	 * @param string $tracking_alias Tracking table alias.
	 * @param string $sessions_table Sessions table name.
	 * @return string SQL fragment.
	 */
	private function get_tracking_spam_exclusion_clause( $tracking_alias, $sessions_table ) {
		$tracking_alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $tracking_alias );
		$tracking_alias = $tracking_alias ? $tracking_alias : 't';

		if ( ! $this->table_exists( $sessions_table ) ) {
			return ' AND 1=0';
		}

		$conditions = array();
		if ( $this->column_exists( $sessions_table, 'traffic_type' ) ) {
			$conditions[] = "(s.traffic_type IS NULL OR s.traffic_type = '' OR s.traffic_type NOT IN ('spam', 'bot', 'automated'))";
		}

		if ( $this->column_exists( $sessions_table, 'duration' ) ) {
			$settings = function_exists( 'get_option' ) ? get_option( 'opti_behavior_traffic_settings', array( 'spam_detection_enabled' => true, 'spam_duration_threshold' => 3 ) ) : array();
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			if ( ! array_key_exists( 'spam_detection_enabled', $settings ) || ! empty( $settings['spam_detection_enabled'] ) ) {
				$conditions[] = 's.duration >= ' . max( 0, isset( $settings['spam_duration_threshold'] ) ? absint( $settings['spam_duration_threshold'] ) : 3 );
			}
		}

		if ( empty( $conditions ) ) {
			return '';
		}

		return " AND {$tracking_alias}.session_id IN (SELECT s.id FROM {$sessions_table} s WHERE " . implode( ' AND ', $conditions ) . ')';
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
