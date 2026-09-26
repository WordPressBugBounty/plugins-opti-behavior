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

	/**
	 * A transition is a candidate for "the step where visitors leave" only
	 * from this many visits entering it (fewer = noise).
	 */
	const TRANSITION_MIN_ENTERED = 20;

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
				COUNT(DISTINCT t.session_id) AS entries,
				COUNT(DISTINCT t.session_id) AS sessions,
				COUNT(t.id) AS entry_rows,
				COUNT(DISTINCT CASE WHEN t.completed = 1 THEN t.session_id END) AS completions,
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

			$step_metrics = self::build_step_metrics( $steps, isset( $step_counts[ $funnel_id ] ) ? $step_counts[ $funnel_id ] : array(), $entries );
			$flow         = self::build_transitions( $step_metrics );

			$metrics[] = array(
				'funnel_id'              => $funnel_id,
				'funnel_name'            => sanitize_text_field( (string) $row['funnel_name'] ),
				'entity_type'            => 'funnel',
				'entity_id'              => (string) $funnel_id,
				'entity_label'           => sanitize_text_field( (string) $row['funnel_name'] ),
				// Visits (distinct sessions), never tracking rows: entry_rows is diagnostics only.
				'entries'                => $entries,
				'entry_rows'             => max( 0, (int) $row['entry_rows'] ),
				'sessions'               => max( 0, (int) $row['sessions'] ),
				'completions'            => $completions,
				'completion_rate'        => $completion_rate,
				'dropoff_rate'           => $dropoff_rate,
				'avg_max_step_reached'   => null !== $row['avg_max_step_reached'] ? round( (float) $row['avg_max_step_reached'], 2 ) : null,
				'observed_max_step'      => max( 0, (int) $row['observed_max_step'] ),
				'step_count'             => count( $steps ),
				'steps'                  => $step_metrics,
				'transitions'            => $flow['transitions'],
				'worst_transition'       => $flow['worst_transition'],
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

	/**
	 * Visits per deepest step reached, for all candidate funnels: first ONE
	 * row per (funnel, session) with its deepest step, then a count per step,
	 * so a session with several tracking rows counts once.
	 */
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

		$sql  = "SELECT x.funnel_id, x.max_step_reached, COUNT(*) AS sessions
			FROM (
				SELECT t.funnel_id, t.session_id, MAX(t.max_step_reached) AS max_step_reached
				FROM {$tracking_table} t
				WHERE t.funnel_id IN ({$placeholders}) AND t.entry_time BETWEEN %s AND %s{$spam_clause}
				GROUP BY t.funnel_id, t.session_id
			) x
			GROUP BY x.funnel_id, x.max_step_reached";
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

	/**
	 * Step metrics from the step definitions and the visits per deepest step
	 * reached. Pure.
	 *
	 * @param array $steps       Step definitions (name / label, url_pattern, match_type).
	 * @param array $step_counts Deepest step => visits.
	 * @param int   $entries     Funnel visits.
	 * @return array[] { step_number, label, reached, reach_rate, url_pattern, match_type }
	 */
	public static function build_step_metrics( $steps, $step_counts, $entries ) {
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
				'url_pattern' => isset( $step['url_pattern'] ) ? sanitize_text_field( (string) $step['url_pattern'] ) : '',
				'match_type'  => isset( $step['match_type'] ) ? sanitize_key( (string) $step['match_type'] ) : '',
			);
		}

		return $metrics;
	}

	/**
	 * Every step-to-next-step transition of a funnel, and the one that loses
	 * the most visits (among transitions entered by at least
	 * TRANSITION_MIN_ENTERED visits; ties: highest drop rate). Pure.
	 *
	 * @param array[] $step_metrics build_step_metrics() output.
	 * @return array { transitions: [ { from_step, from_label, from_url, from_match, to_step, to_label, to_url, to_match, entered, continued, lost, drop_rate } ], worst_transition: array|null }
	 */
	public static function build_transitions( $step_metrics ) {
		$step_metrics = array_values( (array) $step_metrics );
		$transitions  = array();
		$worst        = null;
		$count        = count( $step_metrics );
		for ( $i = 0; $i + 1 < $count; $i++ ) {
			$from      = $step_metrics[ $i ];
			$to        = $step_metrics[ $i + 1 ];
			$entered   = max( 0, (int) $from['reached'] );
			$continued = min( $entered, max( 0, (int) $to['reached'] ) );
			$lost      = $entered - $continued;
			$row       = array(
				'from_step'  => (int) $from['step_number'],
				'from_label' => (string) $from['label'],
				'from_url'   => isset( $from['url_pattern'] ) ? (string) $from['url_pattern'] : '',
				'from_match' => isset( $from['match_type'] ) ? (string) $from['match_type'] : '',
				'to_step'    => (int) $to['step_number'],
				'to_label'   => (string) $to['label'],
				'to_url'     => isset( $to['url_pattern'] ) ? (string) $to['url_pattern'] : '',
				'to_match'   => isset( $to['match_type'] ) ? (string) $to['match_type'] : '',
				'entered'    => $entered,
				'continued'  => $continued,
				'lost'       => $lost,
				'drop_rate'  => $entered > 0 ? round( $lost / $entered * 100, 2 ) : null,
			);
			$transitions[] = $row;
			if ( $entered < self::TRANSITION_MIN_ENTERED ) {
				continue;
			}
			if ( null === $worst || $lost > $worst['lost'] || ( $lost === $worst['lost'] && $row['drop_rate'] > $worst['drop_rate'] ) ) {
				$worst = $row;
			}
		}

		return array(
			'transitions'      => $transitions,
			'worst_transition' => $worst,
		);
	}

	/**
	 * Normalised step definitions of a funnel (match type + URL pattern per
	 * step, case and trailing slash ignored): ONE definition of "the same
	 * steps" for the insight de-duplication signature and the Funnels page
	 * notice. Pure.
	 *
	 * @param array $steps Step definitions or build_step_metrics() rows.
	 * @return array[] [ [ match_type, url_pattern ], ... ]
	 */
	public static function normalize_step_definitions( $steps ) {
		$out = array();
		foreach ( array_values( (array) $steps ) as $step ) {
			$step  = is_array( $step ) ? $step : array();
			$match = isset( $step['match_type'] ) ? strtolower( trim( (string) $step['match_type'] ) ) : '';
			$url   = isset( $step['url_pattern'] ) ? strtolower( trim( (string) $step['url_pattern'] ) ) : '';
			$out[] = array( '' === $match ? 'contains' : $match, '/' === $url ? '/' : rtrim( $url, '/' ) );
		}

		return $out;
	}

	/**
	 * Groups of active funnels that track the same steps (2+ funnels each).
	 * Pure.
	 *
	 * @param array[] $funnels Rows { id, name, steps (JSON or array) }.
	 * @return array[] [ [ { id, name }, ... ], ... ]
	 */
	public static function same_step_groups( $funnels ) {
		$groups = array();
		foreach ( (array) $funnels as $funnel ) {
			$steps = isset( $funnel['steps'] ) ? $funnel['steps'] : array();
			$steps = is_array( $steps ) ? $steps : json_decode( (string) $steps, true );
			if ( ! is_array( $steps ) || count( $steps ) < 1 ) {
				continue;
			}
			$key              = md5( wp_json_encode( self::normalize_step_definitions( $steps ) ) );
			$groups[ $key ][] = array(
				'id'   => isset( $funnel['id'] ) ? (int) $funnel['id'] : 0,
				'name' => isset( $funnel['name'] ) ? sanitize_text_field( (string) $funnel['name'] ) : '',
			);
		}

		return array_values(
			array_filter(
				$groups,
				function ( $group ) {
					return count( $group ) > 1;
				}
			)
		);
	}

	/**
	 * Site average of the step drop rate: mean drop rate of every transition
	 * entered by at least TRANSITION_MIN_ENTERED visits, over the funnels of
	 * the period (the baseline of the funnel drop-off signal). Pure.
	 *
	 * @param array[] $funnel_rows get_funnel_metrics() rows.
	 * @return float|null
	 */
	public static function average_step_dropoff( $funnel_rows ) {
		$sum = 0.0;
		$n   = 0;
		foreach ( (array) $funnel_rows as $row ) {
			foreach ( isset( $row['transitions'] ) && is_array( $row['transitions'] ) ? $row['transitions'] : array() as $t ) {
				if ( (int) $t['entered'] >= self::TRANSITION_MIN_ENTERED && null !== $t['drop_rate'] ) {
					$sum += (float) $t['drop_rate'];
					++$n;
				}
			}
		}

		return $n > 0 ? round( $sum / $n, 2 ) : null;
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
