<?php
/**
 * Smart Cleanup Service.
 *
 * Centralizes Smart Data Cleanup condition handling, session matching,
 * session-child relationship maps, file deletion, cache clearing, and logs.
 *
 * @package Opti_Behavior
 * @copyright 2025 OptiUser
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter

/**
 * Smart Cleanup Service.
 *
 * @since 1.2.7
 */
class Opti_Behavior_Smart_Cleanup_Service {

	/**
	 * Default AJAX/cron cleanup batch size.
	 *
	 * @since 1.2.7
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 500;

	/**
	 * Default maximum number of sessions scheduled cleanup can delete per cron run.
	 *
	 * @since 1.2.7
	 * @var int
	 */
	const DEFAULT_SCHEDULED_MAX_ROWS = 50000;

	/**
	 * Hard upper bound for scheduled cleanup row caps.
	 *
	 * @since 1.2.7
	 * @var int
	 */
	const MAX_SCHEDULED_ROWS_PER_RUN = 50000;

	/**
	 * Last cascade-deletion detail report.
	 *
	 * @since 1.2.7
	 * @var array
	 */
	private $last_cascade_result = array();

	/**
	 * Minimum reclaimed/free bytes before a table is flagged as an optimization candidate.
	 *
	 * @since 1.2.7
	 * @var int
	 */
	const OPTIMIZATION_MIN_FREE_BYTES = 10485760;

	/**
	 * Return the canonical session-child cleanup map.
	 *
	 * These rows are low-level analytics facts linked directly to a session. Master
	 * or configuration rows must not be listed here.
	 *
	 * @since 1.2.7
	 * @return array<string,string> Table suffix => session id column.
	 */
	public static function get_session_child_table_map() {
		return array(
			'optibehavior_events'            => 'session_id',
			'optibehavior_pageviews'         => 'session_id',
			'optibehavior_recordings'        => 'session_id',
			'optibehavior_session_pages'     => 'session_id',
			'optibehavior_referrers'         => 'session_id',
			'optibehavior_outbound_clicks'   => 'session_id',
			'optibehavior_friction'          => 'session_id',
			'optibehavior_errors'            => 'session_id',
			'optibehavior_performance'       => 'session_id',
			'optibehavior_form_interactions' => 'session_id',
			'optibehavior_form_submissions'  => 'session_id',
			'opti_behavior_funnel_tracking'  => 'session_id',
			'optibehavior_ab_impressions'    => 'session_id',
			'optibehavior_ab_conversions'    => 'session_id',
		);
	}

	/**
	 * Return traffic types removed by bot/spam cleanup.
	 *
	 * Use the shared Traffic Behavior reporting policy so cleanup and analytics
	 * agree on which session traffic is low-value.
	 *
	 * @since 1.2.7
	 * @return array<int,string>
	 */
	public function get_bot_cleanup_traffic_types() {
		if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
			$traffic_types = Opti_Behavior_Stats_Spam_Filter::excluded_traffic_types();
		} else {
			$traffic_types = array( 'spam', 'bot', 'automated' );
		}

		$traffic_types = array_values(
			array_filter(
				array_unique( array_map( 'sanitize_key', (array) $traffic_types ) )
			)
		);

		return ! empty( $traffic_types ) ? $traffic_types : array( 'spam', 'bot', 'automated' );
	}

	/**
	 * Normalize Smart Cleanup conditions before SQL construction.
	 *
	 * @since 1.2.7
	 * @param array $conditions Raw cleanup conditions.
	 * @return array Normalized conditions.
	 */
	public function normalize_conditions( $conditions ) {
		if ( ! is_array( $conditions ) ) {
			return array();
		}

		$numeric_keys = array(
			'older_than_days',
			'inactive_visitor_days',
			'min_duration',
			'min_duration_age_days',
			'max_duration',
			'max_duration_age_days',
			'min_events',
			'min_events_age_days',
			'max_events',
			'min_page_views',
			'bounce_age_days',
			'single_visit_age_days',
		);
		$boolean_keys = array(
			'bounce_only',
			'single_visit_only',
			'delete_orphaned_visitors',
		);

		$normalized = array();
		foreach ( $numeric_keys as $key ) {
			if ( isset( $conditions[ $key ] ) ) {
				$normalized[ $key ] = absint( $conditions[ $key ] );
			}
		}

		foreach ( $boolean_keys as $key ) {
			if ( isset( $conditions[ $key ] ) ) {
				$normalized[ $key ] = (bool) $conditions[ $key ];
			}
		}

		$has_spam_traffic = array_key_exists( 'include_spam_traffic', $conditions );
		$has_legacy_bots  = array_key_exists( 'include_bots', $conditions );
		if ( $has_spam_traffic || $has_legacy_bots ) {
			$include_spam_traffic = $has_spam_traffic ? (bool) $conditions['include_spam_traffic'] : (bool) $conditions['include_bots'];

			// Keep both keys populated so saved legacy settings and new canonical
			// settings remain interchangeable during the compatibility window.
			$normalized['include_spam_traffic'] = $include_spam_traffic;
			$normalized['include_bots']         = $include_spam_traffic;
		}

		return $normalized;
	}

	/**
	 * Normalize a known legacy default-like scheduled cleanup shape.
	 *
	 * Older defaults stored the short-session rule as max_duration = 2, which
	 * now means "longer than 2 seconds". Only the exact default-like thresholds
	 * are converted; customized "duration more than N" rules are left intact.
	 *
	 * @since 1.2.7
	 * @param array $settings Raw scheduled cleanup settings.
	 * @return array Settings with safe canonical default conditions when applicable.
	 */
	private function normalize_legacy_default_auto_cleanup_settings( $settings ) {
		if ( ! $this->is_legacy_default_auto_cleanup_settings( $settings ) ) {
			return $settings;
		}

		$settings['conditions'] = array(
			'older_than_days'       => 360,
			'include_bots'          => true,
			'include_spam_traffic'  => true,
			'min_duration'          => 5,
			'min_duration_age_days' => 1,
		);

		return $settings;
	}

	/**
	 * Persistently migrate saved legacy default-like auto-cleanup settings.
	 *
	 * Runtime normalization protects cleanup execution, but leaving the raw
	 * option in the old max_duration = 2 shape means later direct reads can
	 * still see the unsafe duration-more-than rule. Only known default-like
	 * shapes are updated; customized duration-more-than rules are preserved.
	 *
	 * @since 1.2.7
	 * @return array Current saved settings after any migration.
	 */
	public function maybe_migrate_saved_auto_cleanup_settings() {
		$saved_settings = get_option( 'opti_behavior_auto_cleanup_settings', null );

		// 2026-09: the scheduled per-run session cap default moved from 5000 to
		// 50000. Installs still carrying the exact old default are bumped once;
		// the one-shot flag guarantees a user who deliberately sets 5000 AFTER
		// this migration keeps their choice.
		if ( ! get_option( 'opti_behavior_sc_max_rows_50k_migrated' ) ) {
			if ( is_array( $saved_settings )
				&& isset( $saved_settings['max_rows_per_run'] )
				&& 5000 === absint( $saved_settings['max_rows_per_run'] )
			) {
				$saved_settings['max_rows_per_run'] = self::DEFAULT_SCHEDULED_MAX_ROWS;
				update_option( 'opti_behavior_auto_cleanup_settings', $saved_settings );
			}
			update_option( 'opti_behavior_sc_max_rows_50k_migrated', '1', false );
		}

		if ( ! $this->is_legacy_default_auto_cleanup_settings( $saved_settings ) ) {
			return is_array( $saved_settings ) ? $saved_settings : array();
		}

		$migrated_settings = $this->normalize_auto_cleanup_settings( $saved_settings );
		update_option( 'opti_behavior_auto_cleanup_settings', $migrated_settings );

		return $migrated_settings;
	}

	/**
	 * Determine whether saved scheduled cleanup settings are safe to auto-convert.
	 *
	 * @since 1.2.7
	 * @param mixed $settings Saved settings.
	 * @return bool True when the settings match known broken defaults.
	 */
	private function is_legacy_default_auto_cleanup_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return false;
		}

		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		$allowed_keys = array( 'enabled', 'frequency', 'delete_orphaned_visitors', 'conditions', 'max_rows_per_run', 'optimize_after_cleanup', 'recalculate_spam_before_cleanup', 'last_run', 'last_result' );
		if ( array_diff( array_keys( $settings ), $allowed_keys ) ) {
			return false;
		}

		// 2026-08-16 incident fix: the unsafe legacy default shape
		// (max_duration = 2 / max_duration_age_days = 30, i.e. "duration MORE
		// than 2 s and older than 30 days" — matching nearly all legitimate
		// sessions) must be migrated on EVERY supported cadence. The guard
		// previously accepted only 'weekly', so daily/monthly installs kept
		// executing the destructive clause forever.
		if ( isset( $settings['frequency'] ) && ! in_array( $settings['frequency'], array( 'daily', 'weekly', 'monthly' ), true ) ) {
			return false;
		}

		if ( array_key_exists( 'delete_orphaned_visitors', $settings ) && empty( $settings['delete_orphaned_visitors'] ) ) {
			return false;
		}

		if ( ! isset( $settings['conditions'] ) || ! is_array( $settings['conditions'] ) ) {
			return false;
		}

		$conditions             = $settings['conditions'];
		$allowed_condition_keys = array(
			'older_than_days',
			'include_bots',
			'include_spam_traffic',
			'max_duration',
			'max_duration_age_days',
			'delete_orphaned_visitors',
		);

		if ( array_diff( array_keys( $conditions ), $allowed_condition_keys ) ) {
			return false;
		}

		if ( array_key_exists( 'delete_orphaned_visitors', $conditions ) && empty( $conditions['delete_orphaned_visitors'] ) ) {
			return false;
		}

		if ( ! isset( $conditions['older_than_days'] ) || ! in_array( absint( $conditions['older_than_days'] ), array( 90, 360 ), true ) ) {
			return false;
		}

		if ( ! isset( $conditions['max_duration'], $conditions['max_duration_age_days'] ) ) {
			return false;
		}

		if ( 2 !== absint( $conditions['max_duration'] ) || 30 !== absint( $conditions['max_duration_age_days'] ) ) {
			return false;
		}

		return ! empty( $conditions['include_bots'] ) || ! empty( $conditions['include_spam_traffic'] );
	}

	/**
	 * Count sessions matching the given conditions.
	 *
	 * @since 1.2.7
	 * @param array $conditions Cleanup conditions.
	 * @return int Number of matching sessions.
	 */
	public function count_sessions_by_conditions( $conditions ) {
		global $wpdb;
		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );

		$where_clauses = $this->build_session_condition_clauses( $conditions );
		if ( empty( $where_clauses ) ) {
			return 0;
		}

		$where_sql = implode( ' OR ', $where_clauses );
		$count     = $wpdb->get_var( "SELECT COUNT(*) FROM " . $sessions_table . " WHERE " . $where_sql );

		return absint( $count );
	}

	/**
	 * Count bot sessions plus bot visit log rows.
	 *
	 * @since 1.2.7
	 * @return int Number of bot cleanup records.
	 */
	public function count_bot_sessions() {
		global $wpdb;
		$sessions_table   = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$bot_visits_table = esc_sql( $wpdb->prefix . 'optibehavior_bot_visits' );
		$traffic_types    = $this->get_bot_cleanup_traffic_types();
		$placeholders     = implode( ',', array_fill( 0, count( $traffic_types ), '%s' ) );

		$session_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . $sessions_table . " WHERE traffic_type IN (" . $placeholders . ")",
				...$traffic_types
			)
		);

		$bot_count = 0;
		if ( $this->table_exists( 'optibehavior_bot_visits' ) ) {
			$bot_count = $wpdb->get_var( "SELECT COUNT(*) FROM " . $bot_visits_table );
		}

		return absint( $session_count ) + absint( $bot_count );
	}

	/**
	 * Get session IDs matching conditions in batches.
	 *
	 * @since 1.2.7
	 * @param array $conditions Cleanup conditions.
	 * @param int   $limit      Batch size.
	 * @param int   $offset     Offset for pagination.
	 * @return array<int,string|int> Session IDs.
	 */
	public function get_matching_session_ids( $conditions, $limit = self::DEFAULT_BATCH_SIZE, $offset = 0 ) {
		global $wpdb;
		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );

		$where_clauses = $this->build_session_condition_clauses( $conditions );
		if ( empty( $where_clauses ) ) {
			return array();
		}

		$where_sql = implode( ' OR ', $where_clauses );
		$ids       = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM " . $sessions_table . " WHERE " . $where_sql . " LIMIT %d OFFSET %d",
				absint( $limit ),
				absint( $offset )
			)
		);

		return $ids ? $ids : array();
	}

	/**
	 * Count sessions matching the given conditions, excluding spam traffic.
	 *
	 * Used as the mass-delete circuit-breaker numerator once spam became an
	 * exempt class: the breaker must only weigh the legitimate sessions a run
	 * would remove. The OR-combined condition clauses are wrapped and ANDed
	 * with the negated spam clause, which compares COALESCE(traffic_type, '')
	 * so NULL/'' rows stay in the non-spam population.
	 *
	 * @since 2026-09-01
	 * @param array $conditions Cleanup conditions (spam keys are ignored).
	 * @return int Number of matching non-spam sessions.
	 */
	public function count_non_spam_sessions_by_conditions( $conditions ) {
		global $wpdb;
		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );

		$where_clauses = $this->build_session_condition_clauses( $conditions );
		if ( empty( $where_clauses ) ) {
			return 0;
		}

		$where_sql = '(' . implode( ' OR ', $where_clauses ) . ') AND ' . $this->build_spam_traffic_clause( true );

		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $sessions_table . ' WHERE ' . $where_sql );

		return absint( $count );
	}

	/**
	 * Get non-spam session IDs matching conditions in batches.
	 *
	 * @since 2026-09-01
	 * @param array $conditions Cleanup conditions (spam keys are ignored).
	 * @param int   $limit      Batch size.
	 * @param int   $offset     Offset for pagination.
	 * @return array<int,string|int> Session IDs.
	 */
	public function get_matching_non_spam_session_ids( $conditions, $limit = self::DEFAULT_BATCH_SIZE, $offset = 0 ) {
		global $wpdb;
		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );

		$where_clauses = $this->build_session_condition_clauses( $conditions );
		if ( empty( $where_clauses ) ) {
			return array();
		}

		$where_sql = '(' . implode( ' OR ', $where_clauses ) . ') AND ' . $this->build_spam_traffic_clause( true );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . $sessions_table . ' WHERE ' . $where_sql . ' LIMIT %d OFFSET %d',
				absint( $limit ),
				absint( $offset )
			)
		);

		return $ids ? $ids : array();
	}

	/**
	 * Build WHERE clauses from conditions array.
	 *
	 * Conditions use OR logic between groups but AND within each condition.
	 *
	 * @since 1.2.7
	 * @param array $conditions Conditions array.
	 * @return array<int,string> SQL WHERE clause strings.
	 */
	public function build_session_condition_clauses( $conditions ) {
		return array_values( $this->build_labeled_session_condition_clauses( $conditions ) );
	}

	/**
	 * Build WHERE clauses keyed by condition identifier.
	 *
	 * Conditions use OR logic between groups but AND within each condition.
	 * Keys match the condition keys used in the settings UI so per-rule
	 * match counts can be reported back to the preview.
	 *
	 * @since 1.2.8
	 * @param array $conditions Conditions array.
	 * @return array<string,string> Condition key => SQL WHERE clause string.
	 */
	public function build_labeled_session_condition_clauses( $conditions ) {
		global $wpdb;
		$conditions = $this->normalize_conditions( $conditions );
		$clauses    = array();

		$older_than_days = isset( $conditions['older_than_days'] ) ? $conditions['older_than_days'] : 0;
		if ( $older_than_days > 0 ) {
			$cutoff                      = gmdate( 'Y-m-d H:i:s', time() - ( $older_than_days * DAY_IN_SECONDS ) );
			$clauses['older_than_days'] = $wpdb->prepare( 'start_time < %s', $cutoff );
		}

		$inactive_days = isset( $conditions['inactive_visitor_days'] ) ? $conditions['inactive_visitor_days'] : 0;
		if ( $inactive_days > 0 ) {
			$cutoff         = gmdate( 'Y-m-d H:i:s', time() - ( $inactive_days * DAY_IN_SECONDS ) );
			$visitors_table = esc_sql( $wpdb->prefix . 'optibehavior_visitors' );
			$clauses['inactive_visitor_days'] = $wpdb->prepare(
				"visitor_id IN (SELECT id FROM " . $visitors_table . " WHERE last_visit < %s)",
				$cutoff
			);
		}

		$min_duration = isset( $conditions['min_duration'] ) ? $conditions['min_duration'] : 0;
		if ( $min_duration > 0 ) {
			$age_days = isset( $conditions['min_duration_age_days'] ) ? $conditions['min_duration_age_days'] : 0;
			$clause   = $wpdb->prepare( 'duration < %d', $min_duration );
			if ( $age_days > 0 ) {
				$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) );
				$clause .= $wpdb->prepare( ' AND start_time < %s', $cutoff );
			}
			$clauses['min_duration'] = '(' . $clause . ')';
		}

		$max_duration = isset( $conditions['max_duration'] ) ? $conditions['max_duration'] : 0;
		if ( $max_duration > 0 ) {
			$age_days = isset( $conditions['max_duration_age_days'] ) ? $conditions['max_duration_age_days'] : 0;
			$clause   = $wpdb->prepare( 'duration > %d', $max_duration );
			if ( $age_days > 0 ) {
				$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) );
				$clause .= $wpdb->prepare( ' AND start_time < %s', $cutoff );
			}
			$clauses['max_duration'] = '(' . $clause . ')';
		}

		$min_events = isset( $conditions['min_events'] ) ? $conditions['min_events'] : 0;
		if ( $min_events > 0 ) {
			$age_days = isset( $conditions['min_events_age_days'] ) ? $conditions['min_events_age_days'] : 0;
			$clause   = $wpdb->prepare( 'events_count < %d', $min_events );
			if ( $age_days > 0 ) {
				$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) );
				$clause .= $wpdb->prepare( ' AND start_time < %s', $cutoff );
			}
			$clauses['min_events'] = '(' . $clause . ')';
		}

		$max_events = isset( $conditions['max_events'] ) ? $conditions['max_events'] : 0;
		if ( $max_events > 0 ) {
			$clauses['max_events'] = $wpdb->prepare( 'events_count > %d', $max_events );
		}

		$min_page_views = isset( $conditions['min_page_views'] ) ? $conditions['min_page_views'] : 0;
		if ( $min_page_views > 0 ) {
			$clauses['min_page_views'] = $wpdb->prepare( 'page_views < %d', $min_page_views );
		}

		$bounce_only = isset( $conditions['bounce_only'] ) ? $conditions['bounce_only'] : false;
		if ( $bounce_only ) {
			$age_days = isset( $conditions['bounce_age_days'] ) ? $conditions['bounce_age_days'] : 0;
			$clause   = 'is_bounce = 1 AND page_views <= 1';
			if ( $age_days > 0 ) {
				$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) );
				$clause .= $wpdb->prepare( ' AND start_time < %s', $cutoff );
			}
			$clauses['bounce_only'] = '(' . $clause . ')';
		}

		$include_spam_traffic = isset( $conditions['include_spam_traffic'] ) ? $conditions['include_spam_traffic'] : false;
		if ( $include_spam_traffic ) {
			$traffic_types = $this->get_bot_cleanup_traffic_types();
			$placeholders  = implode( ',', array_fill( 0, count( $traffic_types ), '%s' ) );
			$clauses['include_spam_traffic'] = $wpdb->prepare( 'traffic_type IN (' . $placeholders . ')', ...$traffic_types );
		}

		$single_visit = isset( $conditions['single_visit_only'] ) ? $conditions['single_visit_only'] : false;
		if ( $single_visit ) {
			$age_days       = isset( $conditions['single_visit_age_days'] ) ? $conditions['single_visit_age_days'] : 30;
			$cutoff         = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) );
			$visitors_table = esc_sql( $wpdb->prefix . 'optibehavior_visitors' );
			$clauses['single_visit_only'] = $wpdb->prepare(
				"visitor_id IN (SELECT id FROM " . $visitors_table . " WHERE visit_count = 1 AND last_visit < %s)",
				$cutoff
			);
		}

		return $clauses;
	}

	/**
	 * Human-readable labels for cleanup condition keys.
	 *
	 * @since 1.2.8
	 * @return array<string,string> Condition key => translated label.
	 */
	public static function get_condition_labels() {
		return array(
			'older_than_days'       => __( 'Sessions older than X days', 'opti-behavior' ),
			'inactive_visitor_days' => __( 'Inactive visitors', 'opti-behavior' ),
			'min_duration'          => __( 'Session duration less than', 'opti-behavior' ),
			'max_duration'          => __( 'Session duration more than', 'opti-behavior' ),
			'min_events'            => __( 'Event count less than', 'opti-behavior' ),
			'max_events'            => __( 'Event count more than', 'opti-behavior' ),
			'min_page_views'        => __( 'Page views less than', 'opti-behavior' ),
			'bounce_only'           => __( 'Single page bounced visits', 'opti-behavior' ),
			'include_spam_traffic'  => __( 'Spam, bot, and automated sessions', 'opti-behavior' ),
			'single_visit_only'     => __( 'Single-visit visitors', 'opti-behavior' ),
		);
	}

	/**
	 * Count matching sessions per individual condition (OR rule).
	 *
	 * Counts overlap: one session can match several conditions, so the sum
	 * can exceed the combined total.
	 *
	 * @since 1.2.8
	 * @param array $conditions Cleanup conditions.
	 * @return array<string,int> Translated condition label => match count.
	 */
	public function count_sessions_per_condition( $conditions ) {
		global $wpdb;
		$sessions_table  = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$labeled_clauses = $this->build_labeled_session_condition_clauses( $conditions );
		$labels          = self::get_condition_labels();
		$counts          = array();

		foreach ( $labeled_clauses as $key => $clause ) {
			$label            = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
			$counts[ $label ] = absint(
				$wpdb->get_var( "SELECT COUNT(*) FROM " . $sessions_table . " WHERE " . $clause )
			);
		}

		return $counts;
	}

	/**
	 * Build a reusable cleanup impact summary for matching sessions.
	 *
	 * @since 1.2.7
	 * @param array $conditions Cleanup conditions.
	 * @return array Impact summary.
	 */
	public function build_impact_summary( $conditions ) {
		global $wpdb;

		$conditions = $this->normalize_conditions( $conditions );
		$where_clauses = $this->build_session_condition_clauses( $conditions );
		if ( empty( $where_clauses ) ) {
			return array(
				'sessions'                => 0,
				'condition_matches'       => array(),
				'traffic_breakdown'       => array(),
				'rows_by_table'           => array(),
				'recording_files'         => 0,
				'orphaned_visitors'       => 0,
				'estimated_bytes'         => 0,
				'estimated_bytes_by_table'=> array(),
				'table_size_bytes'        => array(),
				'optimization_candidates' => array(),
				'warnings'                => array(
					__( 'No active cleanup conditions were provided.', 'opti-behavior' ),
				),
			);
		}

		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$where_sql      = implode( ' OR ', $where_clauses );
		$session_sql    = "SELECT id FROM " . $sessions_table . " WHERE " . $where_sql;
		$sessions_count = $this->count_sessions_by_conditions( $conditions );
		$rows_by_table  = array(
			'optibehavior_sessions' => $sessions_count,
		);

		foreach ( self::get_session_child_table_map() as $table_suffix => $column ) {
			if ( ! $this->table_exists( $table_suffix ) ) {
				continue;
			}

			$table = esc_sql( $wpdb->prefix . $table_suffix );
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM " . $table . " WHERE " . esc_sql( $column ) . " IN (" . $session_sql . ")" );
			$rows_by_table[ $table_suffix ] = absint( $count );
		}

		$recording_files = 0;
		if ( $this->table_exists( 'optibehavior_recordings' ) ) {
			$recordings_table = esc_sql( $wpdb->prefix . 'optibehavior_recordings' );
			$recording_files  = absint(
				$wpdb->get_var(
					"SELECT COUNT(*) FROM " . $recordings_table . " WHERE session_id IN (" . $session_sql . ") AND file_path IS NOT NULL AND file_path != ''"
				)
			);
		}

		$traffic_breakdown = $this->get_traffic_breakdown_for_session_sql( $session_sql );
		$orphaned_visitors = $this->estimate_orphaned_visitors_for_session_sql( $session_sql );
		$table_sizes       = $this->get_table_size_metadata( array_keys( $rows_by_table ) );
		$bytes_estimate    = $this->estimate_affected_bytes( $rows_by_table, $table_sizes );
		$warnings          = $this->build_preview_warnings( $conditions, $rows_by_table, $recording_files, $orphaned_visitors );

		return array(
			'sessions'                => $rows_by_table['optibehavior_sessions'],
			'condition_matches'       => $this->count_sessions_per_condition( $conditions ),
			'traffic_breakdown'       => $traffic_breakdown,
			'rows_by_table'           => $rows_by_table,
			'recording_files'         => $recording_files,
			'orphaned_visitors'       => $orphaned_visitors,
			'estimated_bytes'         => $bytes_estimate['total'],
			'estimated_bytes_by_table'=> $bytes_estimate['by_table'],
			'table_size_bytes'        => $this->pluck_table_size_bytes( $table_sizes ),
			'optimization_candidates' => $this->get_optimization_candidates( $table_sizes, $rows_by_table ),
			'warnings'                => $warnings,
		);
	}

	/**
	 * Delete bot sessions with cascading deletion.
	 *
	 * @since 1.2.7
	 * @param int $step Current step.
	 * @return array Result with status and progress.
	 */
	public function delete_bot_sessions( $step = 0 ) {
		global $wpdb;
		$sessions_table   = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$bot_visits_table = esc_sql( $wpdb->prefix . 'optibehavior_bot_visits' );
		$batch_size       = self::DEFAULT_BATCH_SIZE;
		$traffic_types    = $this->get_bot_cleanup_traffic_types();
		$placeholders     = implode( ',', array_fill( 0, count( $traffic_types ), '%s' ) );

		if ( 0 === absint( $step ) ) {
			$total = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM " . $sessions_table . " WHERE traffic_type IN (" . $placeholders . ")",
					...$traffic_types
				)
			);
			update_option( 'optibehavior_bot_cleanup_total', absint( $total ), false );
			update_option( 'optibehavior_bot_cleanup_deleted', 0, false );
			update_option( 'optibehavior_bot_cleanup_rows_deleted_by_table', array(), false );
			update_option( 'optibehavior_bot_cleanup_files_deleted', 0, false );
			update_option( 'optibehavior_bot_cleanup_orphaned_visitors_deleted', 0, false );
		}

		$args        = array_merge( $traffic_types, array( $batch_size ) );
		$session_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM " . $sessions_table . " WHERE traffic_type IN (" . $placeholders . ") LIMIT %d",
				...$args
			)
		);

		if ( empty( $session_ids ) ) {
			$bot_visit_rows = 0;
			if ( $this->table_exists( 'optibehavior_bot_visits' ) ) {
				$bot_visit_rows = absint( $wpdb->get_var( "SELECT COUNT(*) FROM " . $bot_visits_table ) );
				$wpdb->query( "TRUNCATE TABLE " . $bot_visits_table );
			}

			$orphaned_visitors_deleted = $this->delete_orphaned_visitors();
			update_option( 'optibehavior_bot_cleanup_orphaned_visitors_deleted', $orphaned_visitors_deleted, false );
			$this->clear_analytics_caches();

			$total                 = get_option( 'optibehavior_bot_cleanup_total', 0 );
			$deleted               = get_option( 'optibehavior_bot_cleanup_deleted', 0 );
			$rows_deleted_by_table = (array) get_option( 'optibehavior_bot_cleanup_rows_deleted_by_table', array() );
			$files_deleted         = absint( get_option( 'optibehavior_bot_cleanup_files_deleted', 0 ) );
			if ( $bot_visit_rows > 0 ) {
				$rows_deleted_by_table['optibehavior_bot_visits'] = $bot_visit_rows;
			}

			$details = array(
				'rows_by_table'              => $rows_deleted_by_table,
				'files_deleted'              => $files_deleted,
				'orphaned_visitors_deleted'  => $orphaned_visitors_deleted,
				'optimized_tables'           => array(),
				'estimated_bytes_reclaimed'  => 0,
				'warnings'                   => array(),
				'status'                     => 'completed',
			);

			$this->add_cleanup_log( 'manual', $deleted, 0, $files_deleted, $details );

			delete_option( 'optibehavior_bot_cleanup_total' );
			delete_option( 'optibehavior_bot_cleanup_deleted' );
			delete_option( 'optibehavior_bot_cleanup_rows_deleted_by_table' );
			delete_option( 'optibehavior_bot_cleanup_files_deleted' );
			delete_option( 'optibehavior_bot_cleanup_orphaned_visitors_deleted' );

			return array(
				'status'                    => 'completed',
				'total'                     => $total,
				'deleted'                   => $deleted,
				'progress'                  => 100,
				'rows_deleted_by_table'     => $rows_deleted_by_table,
				'files_deleted'             => $files_deleted,
				'orphaned_visitors_deleted' => $orphaned_visitors_deleted,
				'optimized_tables'          => array(),
				'warnings'                  => array(),
				'estimated_bytes_reclaimed' => 0,
			);
		}

		$deleted_count = $this->cascade_delete_sessions( $session_ids );
		$this->accumulate_cleanup_batch_result( 'optibehavior_bot_cleanup', $this->get_last_cascade_result() );
		$prev_deleted  = get_option( 'optibehavior_bot_cleanup_deleted', 0 );
		update_option( 'optibehavior_bot_cleanup_deleted', $prev_deleted + $deleted_count, false );

		$total           = get_option( 'optibehavior_bot_cleanup_total', 1 );
		$current_deleted = $prev_deleted + $deleted_count;
		$progress        = $total > 0 ? min( 95, round( ( $current_deleted / $total ) * 100 ) ) : 50;

		return array(
			'status'   => 'processing',
			'step'     => absint( $step ) + 1,
			'deleted'  => $current_deleted,
			'total'    => $total,
			'progress' => $progress,
			'message'  => sprintf(
				/* translators: 1: number of deleted sessions, 2: total sessions queued for deletion. */
				__( 'Deleted %1$d of %2$d bot/spam sessions...', 'opti-behavior' ),
				$current_deleted,
				$total
			),
		);
	}

	/**
	 * Tiered Retention spam/bot tier: ONE bounded daily purge pass.
	 *
	 * Deletes raw spam/bot/automated sessions (same Traffic Behavior policy as
	 * the manual Bot & Spam Cleanup) through the canonical session cascade, so
	 * child tables AND files stay in sync. Bounded: loops small batches up to
	 * a per-run session cap; leftovers are picked up by the next daily tick.
	 * `bot_visits` log rows and orphaned visitors are NOT touched here — the
	 * manual Bot & Spam Cleanup keeps those extras; this daily pass only ages
	 * out raw spam sessions (dashboard bot-traffic % survives via the
	 * `daily_stats.spam_sessions`/`automated_sessions` aggregates).
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @since 2026-08-16 Mass-delete circuit breaker: aborts when spam-typed
	 *                   sessions exceed the safety share of the whole table.
	 * @since 2026-09-01 Spam is a first-class exempt class: the mass-delete
	 *                   circuit breaker no longer applies to this tier, so a
	 *                   spam-heavy install drains every night instead of
	 *                   accumulating behind a permanently-tripped breaker. The
	 *                   aged-spam trickle fallback is gone with it. Filtering
	 *                   `opti_behavior_cleanup_spam_bypasses_safety_limit` to
	 *                   false restores the 2026-08-16 hard-abort behavior.
	 * @param int $max_sessions Per-run session cap (0 = use filtered default).
	 * @return array{sessions_deleted:int,batches:int,capped:bool,aborted:bool,warning?:string}
	 */
	public function run_daily_spam_cleanup( $max_sessions = 0 ) {
		global $wpdb;

		$result = array(
			'sessions_deleted' => 0,
			'batches'          => 0,
			'capped'           => false,
			'aborted'          => false,
		);

		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return $result;
		}

		$max_sessions = absint( $max_sessions );
		if ( $max_sessions < 1 ) {
			/**
			 * Bound the daily spam-tier purge (sessions per run).
			 *
			 * @since 1.9.1
			 * @param int $max_sessions Default cap per daily run.
			 */
			$max_sessions = absint( apply_filters( 'opti_behavior_spam_daily_max_sessions_per_run', self::DEFAULT_SCHEDULED_MAX_ROWS ) );
		}
		$max_sessions = min( self::MAX_SCHEDULED_ROWS_PER_RUN, max( self::DEFAULT_BATCH_SIZE, $max_sessions ) );

		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$traffic_types  = $this->get_bot_cleanup_traffic_types();
		$placeholders   = implode( ',', array_fill( 0, count( $traffic_types ), '%s' ) );
		$rows_by_table  = array();

		$matched_spam = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $sessions_table . ' WHERE traffic_type IN (' . $placeholders . ')',
				...$traffic_types
			)
		);

		if ( $matched_spam < 1 ) {
			return $result;
		}

		// Spam is exempt from the mass-delete circuit breaker: this tier only
		// ever matches spam/bot/automated rows, so measuring it against the
		// whole sessions table permanently tripped the breaker on spam-heavy
		// installs and let flagged rows accumulate without bound. The legacy
		// hard-abort remains reachable through the escape-hatch filter.
		if ( ! $this->is_spam_bypass_enabled( 'spam_daily_tier' ) ) {
			$breaker = $this->evaluate_mass_delete_circuit_breaker( $matched_spam, 'spam_daily_tier' );
			if ( $breaker['tripped'] ) {
				$result['warning'] = $breaker['message'];
				$result['aborted'] = true;

				$this->add_cleanup_log(
					'auto',
					0,
					0,
					0,
					array(
						'status'   => 'aborted',
						'warnings' => array( $breaker['message'] ),
					)
				);

				return $result;
			}
		}

		while ( $result['sessions_deleted'] < $max_sessions ) {
			$batch_size = min( self::DEFAULT_BATCH_SIZE, $max_sessions - $result['sessions_deleted'] );
			$args       = array_merge( $traffic_types, array( $batch_size ) );
			$session_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM ' . $sessions_table . ' WHERE traffic_type IN (' . $placeholders . ') LIMIT %d',
					...$args
				)
			);

			if ( empty( $session_ids ) ) {
				break;
			}

			$deleted = $this->cascade_delete_sessions( $session_ids );
			++$result['batches'];
			$result['sessions_deleted'] += $deleted;

			$cascade       = $this->get_last_cascade_result();
			$rows_by_table = $this->merge_row_count_maps( $rows_by_table, isset( $cascade['rows_deleted_by_table'] ) ? (array) $cascade['rows_deleted_by_table'] : array() );

			if ( $deleted < 1 ) {
				break; // Defensive: avoid a spin when nothing is deletable.
			}
		}

		if ( $result['sessions_deleted'] >= $max_sessions ) {
			$remaining        = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $sessions_table . ' WHERE traffic_type IN (' . $placeholders . ')',
					...$traffic_types
				)
			);
			$result['capped'] = $remaining > 0;
		}

		if ( $result['sessions_deleted'] > 0 ) {
			$this->clear_analytics_caches();
			$this->add_cleanup_log(
				'auto',
				$result['sessions_deleted'],
				0,
				0,
				array(
					'rows_by_table' => $rows_by_table,
					'trigger'       => 'spam_daily_tier',
					'capped'        => $result['capped'],
					'status'        => 'completed',
				)
			);
		}

		return $result;
	}

	/**
	 * Delete sessions matching conditions with cascading deletion.
	 *
	 * @since 1.2.7
	 * @param array $conditions Cleanup conditions.
	 * @param int   $step       Current step.
	 * @return array Result with status and progress.
	 */
	public function delete_sessions_by_conditions( $conditions, $step = 0, $options = array() ) {
		$conditions = $this->normalize_conditions( $conditions );
		$batch_size = self::DEFAULT_BATCH_SIZE;
		$options    = $this->normalize_cleanup_execution_options( $options );

		if ( 0 === absint( $step ) ) {
			$total = $this->count_sessions_by_conditions( $conditions );
			$impact = $this->build_impact_summary( $conditions );
			update_option( 'optibehavior_smart_cleanup_total', $total, false );
			update_option( 'optibehavior_smart_cleanup_deleted', 0, false );
			update_option( 'optibehavior_smart_cleanup_rows_deleted_by_table', array(), false );
			update_option( 'optibehavior_smart_cleanup_files_deleted', 0, false );
			update_option( 'optibehavior_smart_cleanup_orphaned_visitors_deleted', 0, false );
			update_option( 'optibehavior_smart_cleanup_estimated_bytes_reclaimed', absint( $impact['estimated_bytes'] ), false );
			update_option( 'optibehavior_smart_cleanup_warnings', isset( $impact['warnings'] ) ? (array) $impact['warnings'] : array(), false );
			update_option( 'optibehavior_smart_cleanup_optimize_after_cleanup', ! empty( $options['optimize_after_cleanup'] ), false );
		}

		$session_ids = $this->get_matching_session_ids( $conditions, $batch_size, 0 );

		if ( empty( $session_ids ) ) {
			$delete_orphans = isset( $conditions['delete_orphaned_visitors'] ) ? (bool) $conditions['delete_orphaned_visitors'] : true;
			$orphaned_visitors_deleted = 0;
			if ( $delete_orphans ) {
				$orphaned_visitors_deleted = $this->delete_orphaned_visitors();
				update_option( 'optibehavior_smart_cleanup_orphaned_visitors_deleted', $orphaned_visitors_deleted, false );
			}

			$total                       = get_option( 'optibehavior_smart_cleanup_total', 0 );
			$deleted                     = get_option( 'optibehavior_smart_cleanup_deleted', 0 );
			$rows_deleted_by_table       = (array) get_option( 'optibehavior_smart_cleanup_rows_deleted_by_table', array() );
			$files_deleted               = absint( get_option( 'optibehavior_smart_cleanup_files_deleted', 0 ) );
			$estimated_bytes_reclaimed   = absint( get_option( 'optibehavior_smart_cleanup_estimated_bytes_reclaimed', 0 ) );
			$warnings                    = (array) get_option( 'optibehavior_smart_cleanup_warnings', array() );
			$optimized_tables            = array();
			if ( get_option( 'optibehavior_smart_cleanup_optimize_after_cleanup', true ) ) {
				$optimization_result = $this->optimize_touched_tables( $rows_deleted_by_table );
				$optimized_tables    = isset( $optimization_result['optimized_tables'] ) ? $optimization_result['optimized_tables'] : array();
				if ( ! empty( $optimization_result['warnings'] ) ) {
					$warnings = array_merge( $warnings, $optimization_result['warnings'] );
				}
			}

			$this->clear_analytics_caches();

			$details = array(
				'rows_by_table'              => $rows_deleted_by_table,
				'files_deleted'              => $files_deleted,
				'orphaned_visitors_deleted'  => $orphaned_visitors_deleted,
				'optimized_tables'           => $optimized_tables,
				'estimated_bytes_reclaimed'  => $estimated_bytes_reclaimed,
				'warnings'                   => $warnings,
				'status'                     => 'completed',
			);

			$this->add_cleanup_log( 'manual', $deleted, 0, $files_deleted, $details );

			delete_option( 'optibehavior_smart_cleanup_total' );
			delete_option( 'optibehavior_smart_cleanup_deleted' );
			delete_option( 'optibehavior_smart_cleanup_rows_deleted_by_table' );
			delete_option( 'optibehavior_smart_cleanup_files_deleted' );
			delete_option( 'optibehavior_smart_cleanup_orphaned_visitors_deleted' );
			delete_option( 'optibehavior_smart_cleanup_estimated_bytes_reclaimed' );
			delete_option( 'optibehavior_smart_cleanup_warnings' );
			delete_option( 'optibehavior_smart_cleanup_optimize_after_cleanup' );

			return array(
				'status'                    => 'completed',
				'total'                     => $total,
				'deleted'                   => $deleted,
				'progress'                  => 100,
				'rows_deleted_by_table'     => $rows_deleted_by_table,
				'files_deleted'             => $files_deleted,
				'orphaned_visitors_deleted' => $orphaned_visitors_deleted,
				'optimized_tables'          => $optimized_tables,
				'warnings'                  => $warnings,
				'estimated_bytes_reclaimed' => $estimated_bytes_reclaimed,
			);
		}

		$deleted_count = $this->cascade_delete_sessions( $session_ids );
		$this->accumulate_cleanup_batch_result( 'optibehavior_smart_cleanup', $this->get_last_cascade_result() );
		$prev_deleted  = get_option( 'optibehavior_smart_cleanup_deleted', 0 );
		update_option( 'optibehavior_smart_cleanup_deleted', $prev_deleted + $deleted_count, false );

		$total           = get_option( 'optibehavior_smart_cleanup_total', 1 );
		$current_deleted = $prev_deleted + $deleted_count;
		$progress        = $total > 0 ? min( 95, round( ( $current_deleted / $total ) * 100 ) ) : 50;

		return array(
			'status'   => 'processing',
			'step'     => absint( $step ) + 1,
			'deleted'  => $current_deleted,
			'total'    => $total,
			'progress' => $progress,
			'message'  => sprintf(
				/* translators: 1: number of deleted sessions, 2: total sessions queued for deletion. */
				__( 'Deleted %1$d of %2$d sessions...', 'opti-behavior' ),
				$current_deleted,
				$total
			),
		);
	}

	/**
	 * Cascade delete sessions and all related data.
	 *
	 * @since 1.2.7
	 * @param array $session_ids Session IDs.
	 * @return int Number of sessions deleted.
	 */
	public function cascade_delete_sessions( $session_ids ) {
		global $wpdb;

		$session_ids = $this->normalize_session_ids( $session_ids );
		if ( empty( $session_ids ) ) {
			$this->last_cascade_result = array(
				'sessions_deleted'       => 0,
				'rows_deleted_by_table'  => array(),
				'files_deleted'          => 0,
				'heatmap_files_deleted'  => 0,
				'affected_heatmap_pages' => array(),
			);
			return 0;
		}

		$placeholders          = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );
		$rows_deleted_by_table = array();
		$files_deleted         = $this->delete_recording_files_for_sessions( $session_ids );
		// Must run before the session_pages rows below are deleted: resolving a
		// session's heatmap files to a directory requires its page_id(s).
		$heatmap_result         = $this->delete_heatmap_files_for_sessions( $session_ids );
		$heatmap_files_deleted  = $heatmap_result['deleted'];
		$affected_heatmap_pages = $heatmap_result['page_ids'];

		foreach ( self::get_session_child_table_map() as $table_suffix => $column ) {
			if ( ! $this->table_exists( $table_suffix ) ) {
				continue;
			}

			$table   = esc_sql( $wpdb->prefix . $table_suffix );
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM " . $table . " WHERE " . esc_sql( $column ) . " IN (" . $placeholders . ")",
					...$session_ids
				)
			);

			$rows_deleted_by_table[ $table_suffix ] = false !== $deleted ? absint( $deleted ) : 0;
		}

		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$deleted        = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . $sessions_table . " WHERE id IN (" . $placeholders . ")",
				...$session_ids
			)
		);
		$session_count  = false !== $deleted ? absint( $deleted ) : 0;

		// The JSON files/rows backing a page's heatmap data may now be gone,
		// but optibehavior_heatmap_pages.agg_* and the transient session/device
		// count caches still hold pre-deletion values. Mark the affected pages
		// stale (so the next admin load recomputes from the files that actually
		// remain) and drop the transient caches immediately, mirroring the
		// manual per-page delete path (Opti_Behavior_Heatmap_Database::delete_data()).
		if ( $session_count > 0 ) {
			if ( ! empty( $affected_heatmap_pages ) && class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
				$heatmap_storage = Opti_Behavior_Heatmap_Storage::get_instance();
				if ( $heatmap_files_deleted > 0 ) {
					$heatmap_storage->note_heatmap_files_deleted( $heatmap_files_deleted, $affected_heatmap_pages );
				} else {
					// Deliberately conservative: a page associated with a deleted
					// session is marked stale even when zero of its files were
					// actually removed (e.g. the path-containment guard blocked
					// the delete) — the next recompute from unchanged files is
					// harmless, whereas a page never marked stale could serve
					// pre-deletion aggregates indefinitely.
					// (note_heatmap_files_deleted() is a no-op for count 0.)
					$heatmap_storage->mark_pages_stale( $affected_heatmap_pages );
				}

				// Orphan-file leak closure: when this deletion removed a page's
				// LAST surviving session, ARCHIVE the page's whole on-disk hash
				// directory tree to `_orphaned/` so its now-orphaned JSON files
				// stop feeding file-based surfaces — but stay recoverable.
				// Guardrail policy (2026-08-15, Option B): never hard-delete raw
				// heatmap files from a cleanup pass; a retention bug once wiped
				// DB sessions and the old hard delete would have destroyed the
				// only surviving copy of the data.
				$this->archive_orphaned_page_hash_dirs( $affected_heatmap_pages );
			}

			if ( function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
				opti_behavior_flush_heatmap_caches();
			}

			// Heatmaps-list durability (2026-08-16 incident): marking pages
			// stale alone left the list collapsed to a tiny count (53 of ~3500
			// heatmaps in production) after a scheduled cleanup until an admin
			// manually ran "Repair Heatmap Sync". Resync the affected pages'
			// aggregate columns immediately — batched, advisory-locked, and
			// time-budgeted so a cron pass can never hang here.
			if ( ! empty( $affected_heatmap_pages ) ) {
				$this->resync_heatmap_aggregates_after_cleanup( $affected_heatmap_pages );
			}
		}

		$rows_deleted_by_table['optibehavior_sessions'] = $session_count;
		$this->last_cascade_result = array(
			'sessions_deleted'       => $session_count,
			'rows_deleted_by_table'  => $rows_deleted_by_table,
			'files_deleted'          => $files_deleted,
			'heatmap_files_deleted'  => $heatmap_files_deleted,
			'affected_heatmap_pages' => array_values( $affected_heatmap_pages ),
		);

		return $session_count;
	}

	/**
	 * Immediately resync the heatmap aggregate columns of pages affected by a
	 * cleanup cascade (2026-08-16 incident fix).
	 *
	 * Delegates to {@see Opti_Behavior_Heatmap_Dashboard::resync_heatmap_aggregates_for_pages()},
	 * which reuses the single-flight 'opti_behavior_agg_sync' advisory lock,
	 * the 1000-page batch cap, and a wall-time budget (filterable via
	 * 'opti_behavior_cleanup_agg_resync_time_budget', default 10 s per cascade
	 * batch) so a scheduled cron run stays bounded. When no dashboard instance
	 * exists in the current context (neither admin nor DOING_CRON), the
	 * self-rescheduling 'opti_behavior_heatmap_agg_sync' cron worker is armed
	 * instead so the stale pages still drain on the next cron tick.
	 *
	 * @since 2026-08-16
	 * @param array<int,int> $page_ids Heatmap page IDs affected by the deletion.
	 * @return int Number of pages resynced immediately (0 when deferred to cron).
	 */
	private function resync_heatmap_aggregates_after_cleanup( $page_ids ) {
		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return 0;
		}

		$dashboard = null;
		if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			$core = Opti_Behavior_Heatmap_Core::get_instance();
			if ( $core && method_exists( $core, 'get_dashboard' ) ) {
				$dashboard = $core->get_dashboard();
			}
		}

		if ( $dashboard && method_exists( $dashboard, 'resync_heatmap_aggregates_for_pages' ) ) {
			/**
			 * Filter the wall-time budget (seconds) for the immediate
			 * post-cleanup heatmap aggregate resync of one cascade batch.
			 *
			 * @since 2026-08-16
			 * @param float $time_budget Budget in seconds. Default 10.0.
			 */
			$budget = (float) apply_filters( 'opti_behavior_cleanup_agg_resync_time_budget', 10.0 );

			return (int) $dashboard->resync_heatmap_aggregates_for_pages( $page_ids, $budget );
		}

		// No dashboard in this context — arm the background drain worker so
		// the stale pages are still resynced without an admin visit.
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' )
			&& ! wp_next_scheduled( 'opti_behavior_heatmap_agg_sync' ) ) {
			wp_schedule_single_event( time() + 30, 'opti_behavior_heatmap_agg_sync' );
		}

		return 0;
	}

	/**
	 * ARCHIVE the on-disk hash directories of pages that no longer have any
	 * surviving session after a cascade deletion (orphan-file leak closure).
	 *
	 * A page still counts as "live" while ANY row references it in
	 * optibehavior_session_pages (page_id) or optibehavior_events (page_id2) —
	 * both of which are cleared for deleted sessions by cascade_delete_sessions()
	 * BEFORE this runs. So a page whose counts are now both zero has lost its
	 * last session and its heatmap files are orphans that must stop feeding the
	 * file-derived surfaces.
	 *
	 * Guardrail policy (2026-08-15, Option B): the dirs are MOVED under
	 * `_orphaned/{hash}/` via {@see Opti_Behavior_Heatmap_Storage::archive_page_hash_directories()}
	 * (atomic rename, O(1) per dir) — never hard-deleted — so data removed by a
	 * buggy/over-eager cleanup remains recoverable.
	 *
	 * CRON-CONFLICT GUARDRAIL (product auto-rebuild, 2026-08-15): archiving is
	 * SKIPPED entirely while the file-first registry rebuild reports an active
	 * pass ({@see Opti_Behavior_Heatmap_Registry_Rebuild::is_rebuild_active()},
	 * one autoload-off option read). This cleanup step runs inside the 4 AM
	 * opti_behavior_heatmap_cron_daily OUTSIDE the shared HEATMAP_SYNC_LOCK_NAME
	 * lock, so without the skip it could move a hash dir to `_orphaned/` BEFORE
	 * the rebuild scans it — silently shrinking the recoverable set. Deferring
	 * is harmless: the dirs stay in place, the rebuild registers them, and the
	 * NEXT cleanup pass archives whatever then still qualifies (the
	 * no-surviving-session condition is re-evaluated every pass).
	 *
	 * @since 1.9.x
	 * @since 2026-08-15 Archives to `_orphaned/` instead of hard-deleting.
	 * @since 2026-08-15 Skips/defer while a registry rebuild pass is active.
	 * @param array<int,int> $page_ids Heatmap page IDs affected by the deletion.
	 * @return int Number of pages whose hash directories were archived.
	 */
	private function archive_orphaned_page_hash_dirs( $page_ids ) {
		global $wpdb;

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $page_ids ) || ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			return 0;
		}

		if ( class_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild' )
			&& Opti_Behavior_Heatmap_Registry_Rebuild::is_rebuild_active() ) {
			// Rebuild mid-pass: defer archival so it can still scan these dirs.
			return 0;
		}

		$has_session_pages = $this->table_exists( 'optibehavior_session_pages' );
		$has_events        = $this->table_exists( 'optibehavior_events' );
		$storage           = Opti_Behavior_Heatmap_Storage::get_instance();
		$removed           = 0;

		foreach ( $page_ids as $page_id ) {
			$live = 0;

			if ( $has_session_pages ) {
				$live += (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}optibehavior_session_pages WHERE page_id = %d",
						$page_id
					)
				);
			}

			if ( 0 === $live && $has_events ) {
				$live += (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}optibehavior_events WHERE page_id2 = %d LIMIT 1",
						$page_id
					)
				);
			}

			if ( 0 === $live ) {
				$removed += $storage->archive_page_hash_directories( $page_id ) > 0 ? 1 : 0;
			}
		}

		return $removed;
	}

	/**
	 * Return the last cascade-deletion detail report.
	 *
	 * @since 1.2.7
	 * @return array
	 */
	public function get_last_cascade_result() {
		return $this->last_cascade_result;
	}

	/**
	 * File/DB synchro entry point for session deletions that DON'T go through
	 * {@see cascade_delete_sessions()} (e.g. the date-range log purge in
	 * trait-opti-behavior-maintenance.php). Deletes the heatmap interaction
	 * files (clicks/moves/scrolls JSON) that belong to the given sessions, then
	 * marks the affected heatmap pages stale and flushes the heatmap caches so
	 * the file store and the canonical DB session universe never diverge
	 * (2026-08-13 unification).
	 *
	 * MUST be called BEFORE the caller deletes the sessions'
	 * `optibehavior_session_pages` rows — file resolution needs page_id.
	 *
	 * @since 2026-08-13 (file/DB reconciliation)
	 * @param array<int,string> $session_ids Session IDs about to be deleted.
	 * @return array{deleted:int,page_ids:array<int,int>} Heatmap files deleted + affected page IDs.
	 */
	public function sync_heatmap_files_for_deleted_sessions( $session_ids ) {
		$result = $this->delete_heatmap_files_for_sessions( $session_ids );

		if ( ! empty( $result['page_ids'] ) && class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			Opti_Behavior_Heatmap_Storage::get_instance()->note_heatmap_files_deleted( (int) $result['deleted'], $result['page_ids'] );
		}

		if ( function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
			opti_behavior_flush_heatmap_caches();
		}

		return $result;
	}

	/**
	 * Delete orphaned visitors.
	 *
	 * @since 1.2.7
	 * @return int Number of visitors deleted.
	 */
	public function delete_orphaned_visitors() {
		global $wpdb;

		if ( ! $this->table_exists( 'optibehavior_visitors' ) || ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return 0;
		}

		$visitors_table = esc_sql( $wpdb->prefix . 'optibehavior_visitors' );
		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$deleted        = $wpdb->query(
			"DELETE v FROM " . $visitors_table . " v
			 LEFT JOIN " . $sessions_table . " s ON s.visitor_id = v.id
			 WHERE s.id IS NULL"
		);

		return false !== $deleted ? absint( $deleted ) : 0;
	}

	/**
	 * Clear analytics-related transient caches and orphaned visitor summaries.
	 *
	 * @since 1.2.7
	 */
	public function clear_analytics_caches() {
		global $wpdb;
		$options_table = esc_sql( $wpdb->prefix . 'options' );

		$wpdb->query( "DELETE FROM " . $options_table . " WHERE option_name LIKE '_transient_optibehavior_%'" );
		$wpdb->query( "DELETE FROM " . $options_table . " WHERE option_name LIKE '_transient_timeout_optibehavior_%'" );
		$wpdb->query( "DELETE FROM " . $options_table . " WHERE option_name LIKE '_transient_opti_behavior_%'" );
		$wpdb->query( "DELETE FROM " . $options_table . " WHERE option_name LIKE '_transient_timeout_opti_behavior_%'" );

		if ( $this->table_exists( 'optibehavior_visitor_daily_stats' ) && $this->table_exists( 'optibehavior_sessions' ) ) {
			$summary_table  = esc_sql( $wpdb->prefix . 'optibehavior_visitor_daily_stats' );
			$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
			$wpdb->query(
				"DELETE vds FROM " . $summary_table . " vds
				 LEFT JOIN " . $sessions_table . " s
				   ON s.visitor_id = vds.visitor_id
				   AND DATE(s.start_time) = vds.stat_date
				 WHERE s.id IS NULL"
			);
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * Normalize manual cleanup execution options.
	 *
	 * @since 1.2.7
	 * @param array $options Raw options.
	 * @return array Normalized options.
	 */
	private function normalize_cleanup_execution_options( $options ) {
		$options = is_array( $options ) ? $options : array();

		return array(
			'optimize_after_cleanup' => array_key_exists( 'optimize_after_cleanup', $options ) ? (bool) $options['optimize_after_cleanup'] : true,
		);
	}

	/**
	 * Count all rows in the sessions table (circuit-breaker denominator).
	 *
	 * @since 2026-08-16 (mass-deletion incident guardrails)
	 * @return int Total session rows (0 when the table is missing).
	 */
	private function count_total_sessions() {
		global $wpdb;

		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return 0;
		}

		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );

		return absint( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $sessions_table ) );
	}

	/**
	 * Whether spam/bot/automated sessions bypass the mass-delete safety limit.
	 *
	 * Spam-typed rows are a first-class exempt class: automated purges that
	 * only ever match them must not be gated by the mass-delete circuit
	 * breaker, otherwise a spam-heavy install trips it permanently and grows
	 * without bound. Returning false restores the pre-2026-09-01 behavior —
	 * the breaker counts spam rows again and aborts the whole automated run
	 * (note that the aged-spam trickle fallback no longer exists).
	 *
	 * @since 2026-09-01
	 * @param string $context Cleanup context ('spam_daily_tier'|'scheduled').
	 * @return bool True when spam bypasses the safety limit (default).
	 */
	private function is_spam_bypass_enabled( $context ) {
		/**
		 * Filter whether spam/bot/automated sessions bypass the mass-delete
		 * safety limit.
		 *
		 * @since 2026-09-01
		 * @param bool   $bypass  Default true.
		 * @param string $context Cleanup context ('spam_daily_tier'|'scheduled').
		 */
		return (bool) apply_filters( 'opti_behavior_cleanup_spam_bypasses_safety_limit', true, $context );
	}

	/**
	 * Build a prepared traffic_type clause for the spam/bot/automated set.
	 *
	 * `traffic_type` may be NULL or an empty string on unclassified rows, so
	 * the clause always compares COALESCE(traffic_type, '') — otherwise a
	 * negated clause would silently drop those rows from the non-spam
	 * population.
	 *
	 * @since 2026-09-01
	 * @param bool $negate When true, build the NOT IN (non-spam) variant.
	 * @return string Prepared SQL fragment.
	 */
	private function build_spam_traffic_clause( $negate = false ) {
		global $wpdb;

		$traffic_types = $this->get_bot_cleanup_traffic_types();
		$placeholders  = implode( ',', array_fill( 0, count( $traffic_types ), '%s' ) );
		$operator      = $negate ? 'NOT IN' : 'IN';

		return $wpdb->prepare(
			"COALESCE(traffic_type, '') " . $operator . ' (' . $placeholders . ')',
			...$traffic_types
		);
	}

	/**
	 * Split cleanup conditions into their spam and non-spam halves.
	 *
	 * Scheduled cleanup OR-combines every condition clause, so a single
	 * `include_spam_traffic` rule used to drag the whole run past the
	 * mass-delete safety limit. Splitting the conditions lets the spam subset
	 * be purged unconditionally while the breaker is evaluated on the non-spam
	 * rules alone.
	 *
	 * The legacy `include_bots` key is honoured alongside the canonical
	 * `include_spam_traffic` key, and `delete_orphaned_visitors` is preserved
	 * on both halves because it is a run flag, not a matching rule.
	 *
	 * @since 2026-09-01
	 * @param array $conditions Cleanup conditions.
	 * @return array{spam:array|null,non_spam:array} Spam conditions (null when
	 *                                               spam traffic is not
	 *                                               included) and the
	 *                                               remaining conditions.
	 */
	private function split_conditions_by_spam( $conditions ) {
		$conditions = $this->normalize_conditions( $conditions );

		$includes_spam = ! empty( $conditions['include_spam_traffic'] ) || ! empty( $conditions['include_bots'] );

		$non_spam = $conditions;
		unset( $non_spam['include_spam_traffic'], $non_spam['include_bots'] );

		$spam = null;
		if ( $includes_spam ) {
			$spam = array(
				'include_spam_traffic' => true,
				'include_bots'         => true,
			);

			if ( array_key_exists( 'delete_orphaned_visitors', $conditions ) ) {
				$spam['delete_orphaned_visitors'] = (bool) $conditions['delete_orphaned_visitors'];
			}
		}

		return array(
			'spam'     => $spam,
			'non_spam' => $non_spam,
		);
	}

	/**
	 * Count non-spam rows in the sessions table (circuit-breaker denominator).
	 *
	 * @since 2026-09-01
	 * @return int Total non-spam session rows (0 when the table is missing).
	 */
	private function count_total_non_spam_sessions() {
		global $wpdb;

		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return 0;
		}

		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$non_spam_where = $this->build_spam_traffic_clause( true );

		return absint( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $sessions_table . ' WHERE ' . $non_spam_where ) );
	}

	/**
	 * Mass-delete circuit breaker (2026-08-16 incident guardrail).
	 *
	 * Automated cleanup passes (scheduled conditional cleanup, daily spam
	 * tier) must never silently wipe out most of the sessions table — the
	 * 2026-08-15 incident matched 23k+ of ~23k sessions and ground the data
	 * set down nightly. Before deleting anything, the automated entry points
	 * call this with their matched count; when the matched share of the
	 * whole sessions table exceeds the threshold the run is ABORTED and a
	 * warning is logged instead. Manual cleanups (Danger Zone, explicit
	 * admin confirmation) are intentionally NOT gated.
	 *
	 * @since 2026-08-16
	 * @since 2026-09-01 Optional $total so callers can measure a matched
	 *                   subset against its own population (the spam-exempt
	 *                   non-spam denominator).
	 * @param int      $matched Number of sessions the pass would delete.
	 * @param string   $context Short context label for the warning message.
	 * @param int|null $total   Denominator to measure against; null uses the
	 *                          whole sessions table (legacy behavior).
	 * @return array{tripped:bool,matched:int,total:int,share:float,threshold:float,min_sessions:int,message:string}
	 */
	private function evaluate_mass_delete_circuit_breaker( $matched, $context, $total = null ) {
		$matched = absint( $matched );
		$total   = null === $total ? $this->count_total_sessions() : absint( $total );

		/**
		 * Maximum share of the sessions table an AUTOMATED cleanup run may
		 * delete before the mass-delete circuit breaker aborts it.
		 *
		 * @since 2026-08-16
		 * @param float  $threshold Share in (0,1]; default 0.5 (50%).
		 * @param string $context   Cleanup context ('scheduled'|'spam_daily_tier').
		 */
		$threshold = (float) apply_filters( 'opti_behavior_cleanup_mass_delete_max_share', 0.5, $context );
		if ( $threshold <= 0 || $threshold > 1 ) {
			$threshold = 0.5;
		}

		/**
		 * Minimum sessions-table size before the mass-delete circuit breaker
		 * applies. On very small installs deleting most sessions is a normal,
		 * low-risk event (e.g. purging a handful of test sessions).
		 *
		 * @since 2026-08-16
		 * @param int    $min_sessions Floor; default 100.
		 * @param string $context      Cleanup context ('scheduled'|'spam_daily_tier').
		 */
		$min_sessions = absint( apply_filters( 'opti_behavior_cleanup_mass_delete_min_sessions', 100, $context ) );

		$share   = $total > 0 ? $matched / $total : 0.0;
		$tripped = $total >= $min_sessions && $matched > 0 && $share > $threshold;

		$message = '';
		if ( $tripped ) {
			$message = sprintf(
				/* translators: 1: cleanup context, 2: matched sessions, 3: total sessions, 4: matched share percent, 5: threshold percent. */
				__( 'Mass-delete circuit breaker: %1$s cleanup aborted — it matched %2$d of %3$d sessions (%4$s%%, above the %5$s%% safety threshold). Review the cleanup conditions and, if the deletion is intended, run it manually from the Danger Zone.', 'opti-behavior' ),
				sanitize_key( $context ),
				$matched,
				$total,
				number_format_i18n( $share * 100, 1 ),
				number_format_i18n( $threshold * 100, 1 )
			);
		}

		return array(
			'tripped'      => $tripped,
			'matched'      => $matched,
			'total'        => $total,
			'share'        => $share,
			'threshold'    => $threshold,
			'min_sessions' => $min_sessions,
			'message'      => $message,
		);
	}

	/**
	 * Run scheduled cleanup using saved conditions.
	 *
	 * @since 1.2.7
	 * @since 2026-09-01 When the saved conditions include spam traffic, the
	 *                   mass-delete circuit breaker is evaluated on the
	 *                   non-spam rules against the non-spam population only. A
	 *                   trip no longer aborts the whole run: the spam subset is
	 *                   still purged and the run is reported as 'partial' with
	 *                   trigger 'scheduled_spam_only'. Filtering
	 *                   `opti_behavior_cleanup_spam_bypasses_safety_limit` to
	 *                   false restores the 2026-08-16 wholesale abort.
	 */
	public function run_scheduled_cleanup() {
		$this->maybe_migrate_saved_auto_cleanup_settings();

		$settings = $this->normalize_auto_cleanup_settings( get_option( 'opti_behavior_auto_cleanup_settings', array() ) );

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( $this->should_skip_scheduled_cleanup_for_frequency( $settings ) ) {
			return;
		}

		$conditions = isset( $settings['conditions'] ) ? $settings['conditions'] : array();
		if ( empty( $conditions ) ) {
			return;
		}

		$warnings      = array();
		$spam_result   = array(
			'processed' => 0,
			'updated'   => 0,
		);
		$max_rows      = $this->sanitize_scheduled_max_rows( $settings['max_rows_per_run'] );
		if ( ! empty( $settings['recalculate_spam_before_cleanup'] ) ) {
			$spam_result = $this->recalculate_spam_traffic_for_scheduled_cleanup( $max_rows );
			if ( ! empty( $spam_result['warnings'] ) ) {
				$warnings = array_merge( $warnings, $spam_result['warnings'] );
			}
		}

		$conditions['delete_orphaned_visitors'] = ! empty( $settings['delete_orphaned_visitors'] );
		$conditions                            = $this->normalize_conditions( $conditions );
		$total_to_delete                       = $this->count_sessions_by_conditions( $conditions );

		if ( 0 === $total_to_delete ) {
			if ( ! empty( $spam_result['updated'] ) ) {
				$this->clear_analytics_caches();
			}

			$settings['last_run']    = current_time( 'timestamp' );
			$settings['last_result'] = array(
				'status'                        => 'completed',
				'matched_before'                => 0,
				'sessions_deleted'              => 0,
				'files_deleted'                 => 0,
				'orphaned_visitors_deleted'     => 0,
				'optimized_tables'              => array(),
				'max_rows_per_run'              => $max_rows,
				'more_rows_remain'              => false,
				'remaining_sessions'            => 0,
				'spam_recalculation_processed'  => absint( $spam_result['processed'] ),
				'spam_recalculation_updated'    => absint( $spam_result['updated'] ),
				'warnings'                      => $warnings,
			);
			update_option( 'opti_behavior_auto_cleanup_settings', $settings );
			return;
		}

		// Mass-delete circuit breaker (2026-08-16 incident guardrail): abort
		// the automated run when it would wipe most of the sessions table.
		//
		// Since 2026-09-01 spam/bot/automated rows are an exempt class. When
		// the saved conditions include them, the breaker is measured on the
		// non-spam rules against the non-spam population only, and a trip
		// downgrades the run to a PARTIAL run: the spam subset is still purged
		// and only the non-spam rules are held for review.
		$split       = $this->split_conditions_by_spam( $conditions );
		$spam_bypass = $this->is_spam_bypass_enabled( 'scheduled' );

		if ( $spam_bypass && null !== $split['spam'] ) {
			$non_spam_matched = $this->count_non_spam_sessions_by_conditions( $split['non_spam'] );
			$breaker          = $this->evaluate_mass_delete_circuit_breaker(
				$non_spam_matched,
				'scheduled',
				$this->count_total_non_spam_sessions()
			);

			if ( $breaker['tripped'] ) {
				$partial = $this->run_scheduled_spam_only_cleanup(
					$split['spam'],
					$max_rows,
					! empty( $settings['optimize_after_cleanup'] )
				);

				$warnings[] = sprintf(
					/* translators: 1: spam sessions deleted, 2: matched non-spam sessions, 3: total non-spam sessions, 4: matched share percent, 5: threshold percent. */
					__( 'Mass-delete safety limit: %1$d spam/bot session(s) were purged as usual, but the non-spam cleanup rules matched %2$d of %3$d non-spam sessions (%4$s%%, above the %5$s%% safety threshold) and were held for review. Adjust the cleanup conditions, or run the deletion manually from the Danger Zone.', 'opti-behavior' ),
					absint( $partial['sessions_deleted'] ),
					$breaker['matched'],
					$breaker['total'],
					number_format_i18n( $breaker['share'] * 100, 1 ),
					number_format_i18n( $breaker['threshold'] * 100, 1 )
				);

				if ( ! empty( $partial['warnings'] ) ) {
					$warnings = array_merge( $warnings, $partial['warnings'] );
				}

				$this->clear_analytics_caches();

				$remaining_sessions = $this->count_sessions_by_conditions( $conditions );

				$settings['last_run']    = current_time( 'timestamp' );
				$settings['last_result'] = array(
					'status'                       => 'partial',
					'matched_before'               => $total_to_delete,
					'sessions_deleted'             => absint( $partial['sessions_deleted'] ),
					'files_deleted'                => absint( $partial['files_deleted'] ),
					'orphaned_visitors_deleted'    => absint( $partial['orphaned_visitors_deleted'] ),
					'rows_by_table'                => $partial['rows_by_table'],
					'optimized_tables'             => $partial['optimized_tables'],
					'max_rows_per_run'             => $max_rows,
					'more_rows_remain'             => $remaining_sessions > 0,
					'remaining_sessions'           => $remaining_sessions,
					'non_spam_held'                => $breaker['matched'],
					'spam_recalculation_processed' => absint( $spam_result['processed'] ),
					'spam_recalculation_updated'   => absint( $spam_result['updated'] ),
					'circuit_breaker'              => array(
						'matched'   => $breaker['matched'],
						'total'     => $breaker['total'],
						'threshold' => $breaker['threshold'],
					),
					'warnings'                     => $warnings,
				);
				update_option( 'opti_behavior_auto_cleanup_settings', $settings );

				$this->add_cleanup_log(
					'scheduled',
					absint( $partial['sessions_deleted'] ),
					0,
					absint( $partial['files_deleted'] ),
					array(
						'rows_by_table'             => $partial['rows_by_table'],
						'files_deleted'             => absint( $partial['files_deleted'] ),
						'orphaned_visitors_deleted' => absint( $partial['orphaned_visitors_deleted'] ),
						'optimized_tables'          => $partial['optimized_tables'],
						'trigger'                   => 'scheduled_spam_only',
						'status'                    => 'partial',
						'warnings'                  => $warnings,
					)
				);
				return;
			}
		} else {
			$breaker = $this->evaluate_mass_delete_circuit_breaker( $total_to_delete, 'scheduled' );
		}

		if ( $breaker['tripped'] ) {
			$warnings[] = $breaker['message'];

			$settings['last_run']    = current_time( 'timestamp' );
			$settings['last_result'] = array(
				'status'                       => 'aborted',
				'matched_before'               => $total_to_delete,
				'sessions_deleted'             => 0,
				'files_deleted'                => 0,
				'orphaned_visitors_deleted'    => 0,
				'optimized_tables'             => array(),
				'max_rows_per_run'             => $max_rows,
				'more_rows_remain'             => true,
				'remaining_sessions'           => $total_to_delete,
				'spam_recalculation_processed' => absint( $spam_result['processed'] ),
				'spam_recalculation_updated'   => absint( $spam_result['updated'] ),
				'circuit_breaker'              => array(
					'matched'   => $breaker['matched'],
					'total'     => $breaker['total'],
					'threshold' => $breaker['threshold'],
				),
				'warnings'                     => $warnings,
			);
			update_option( 'opti_behavior_auto_cleanup_settings', $settings );

			$this->add_cleanup_log(
				'scheduled',
				0,
				0,
				0,
				array(
					'status'   => 'aborted',
					'warnings' => $warnings,
				)
			);
			return;
		}

		$total_deleted         = 0;
		$rows_deleted_by_table = array();
		$files_deleted         = 0;
		$batch_size            = self::DEFAULT_BATCH_SIZE;

		while ( $total_deleted < $max_rows ) {
			$remaining_capacity = $max_rows - $total_deleted;
			$current_batch_size = min( $batch_size, $remaining_capacity );
			$session_ids        = $this->get_matching_session_ids( $conditions, $current_batch_size, 0 );
			if ( empty( $session_ids ) ) {
				break;
			}

			$deleted        = $this->cascade_delete_sessions( $session_ids );
			$cascade_result = $this->get_last_cascade_result();
			$rows_deleted_by_table = $this->merge_row_count_maps(
				$rows_deleted_by_table,
				isset( $cascade_result['rows_deleted_by_table'] ) ? $cascade_result['rows_deleted_by_table'] : array()
			);
			$files_deleted += isset( $cascade_result['files_deleted'] ) ? absint( $cascade_result['files_deleted'] ) : 0;
			$total_deleted += $deleted;

			if ( 0 === $deleted ) {
				break;
			}
		}

		if ( ! empty( $conditions['delete_orphaned_visitors'] ) ) {
			$orphaned_visitors_deleted = $this->delete_orphaned_visitors();
		} else {
			$orphaned_visitors_deleted = 0;
		}

		$optimized_tables = array();
		if ( ! empty( $settings['optimize_after_cleanup'] ) ) {
			$optimization_result = $this->optimize_touched_tables( $rows_deleted_by_table );
			$optimized_tables    = isset( $optimization_result['optimized_tables'] ) ? $optimization_result['optimized_tables'] : array();
			if ( ! empty( $optimization_result['warnings'] ) ) {
				$warnings = array_merge( $warnings, $optimization_result['warnings'] );
			}
		}

		$this->clear_analytics_caches();

		$remaining_sessions = $this->count_sessions_by_conditions( $conditions );
		$more_rows_remain   = $remaining_sessions > 0;
		$status             = $more_rows_remain ? 'partial' : 'completed';

		$settings['last_run']    = current_time( 'timestamp' );
		$settings['last_result'] = array(
			'status'                       => $status,
			'matched_before'               => $total_to_delete,
			'sessions_deleted'             => $total_deleted,
			'files_deleted'                => $files_deleted,
			'orphaned_visitors_deleted'    => $orphaned_visitors_deleted,
			'rows_by_table'                => $rows_deleted_by_table,
			'optimized_tables'             => $optimized_tables,
			'max_rows_per_run'             => $max_rows,
			'more_rows_remain'             => $more_rows_remain,
			'remaining_sessions'           => $remaining_sessions,
			'spam_recalculation_processed' => absint( $spam_result['processed'] ),
			'spam_recalculation_updated'   => absint( $spam_result['updated'] ),
			'warnings'                     => $warnings,
		);
		update_option( 'opti_behavior_auto_cleanup_settings', $settings );

		$this->add_cleanup_log(
			'scheduled',
			$total_deleted,
			0,
			$files_deleted,
			array(
				'rows_by_table'             => $rows_deleted_by_table,
				'files_deleted'             => $files_deleted,
				'orphaned_visitors_deleted' => $orphaned_visitors_deleted,
				'optimized_tables'          => $optimized_tables,
				'warnings'                  => $warnings,
				'status'                    => $status,
			)
		);
	}

	/**
	 * Delete only the spam subset of a scheduled cleanup (partial run).
	 *
	 * Used when the mass-delete circuit breaker trips on the non-spam rules:
	 * spam/bot/automated rows are exempt, so they are still purged through the
	 * regular bounded batch loop while the non-spam rules are held for review.
	 *
	 * @since 2026-09-01
	 * @param array $spam_conditions Spam-only cleanup conditions.
	 * @param int   $max_rows        Per-run session cap.
	 * @param bool  $optimize        Whether to optimize the touched tables.
	 * @return array{sessions_deleted:int,files_deleted:int,orphaned_visitors_deleted:int,rows_by_table:array,optimized_tables:array,warnings:array}
	 */
	private function run_scheduled_spam_only_cleanup( $spam_conditions, $max_rows, $optimize = false ) {
		$result = array(
			'sessions_deleted'          => 0,
			'files_deleted'             => 0,
			'orphaned_visitors_deleted' => 0,
			'rows_by_table'             => array(),
			'optimized_tables'          => array(),
			'warnings'                  => array(),
		);

		$max_rows   = $this->sanitize_scheduled_max_rows( $max_rows );
		$batch_size = self::DEFAULT_BATCH_SIZE;

		while ( $result['sessions_deleted'] < $max_rows ) {
			$current_batch_size = min( $batch_size, $max_rows - $result['sessions_deleted'] );
			$session_ids        = $this->get_matching_session_ids( $spam_conditions, $current_batch_size, 0 );
			if ( empty( $session_ids ) ) {
				break;
			}

			$deleted        = $this->cascade_delete_sessions( $session_ids );
			$cascade_result = $this->get_last_cascade_result();

			$result['rows_by_table'] = $this->merge_row_count_maps(
				$result['rows_by_table'],
				isset( $cascade_result['rows_deleted_by_table'] ) ? (array) $cascade_result['rows_deleted_by_table'] : array()
			);
			$result['files_deleted']    += isset( $cascade_result['files_deleted'] ) ? absint( $cascade_result['files_deleted'] ) : 0;
			$result['sessions_deleted'] += $deleted;

			if ( 0 === $deleted ) {
				break;
			}
		}

		// Orphan removal stays enabled on a held run: it only deletes visitors
		// that no longer have any session at all, so it can never remove data
		// belonging to the non-spam sessions the breaker just protected.
		if ( ! empty( $spam_conditions['delete_orphaned_visitors'] ) ) {
			$result['orphaned_visitors_deleted'] = $this->delete_orphaned_visitors();
		}

		if ( $optimize && ! empty( $result['rows_by_table'] ) ) {
			$optimization_result         = $this->optimize_touched_tables( $result['rows_by_table'] );
			$result['optimized_tables']  = isset( $optimization_result['optimized_tables'] ) ? $optimization_result['optimized_tables'] : array();
			if ( ! empty( $optimization_result['warnings'] ) ) {
				$result['warnings'] = array_merge( $result['warnings'], $optimization_result['warnings'] );
			}
		}

		return $result;
	}

	/**
	 * Normalize saved scheduled cleanup settings.
	 *
	 * @since 1.2.7
	 * @param mixed $settings Raw option value.
	 * @return array Normalized settings.
	 */
	public function normalize_auto_cleanup_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$settings = $this->normalize_legacy_default_auto_cleanup_settings( $settings );

		return array(
			'enabled'                         => ! empty( $settings['enabled'] ),
			'frequency'                       => isset( $settings['frequency'] ) && in_array( $settings['frequency'], array( 'daily', 'weekly', 'monthly' ), true ) ? $settings['frequency'] : 'daily',
			'delete_orphaned_visitors'        => array_key_exists( 'delete_orphaned_visitors', $settings ) ? (bool) $settings['delete_orphaned_visitors'] : true,
			'conditions'                      => isset( $settings['conditions'] ) ? $this->normalize_conditions( $settings['conditions'] ) : array(),
			'max_rows_per_run'                => isset( $settings['max_rows_per_run'] ) ? $this->sanitize_scheduled_max_rows( $settings['max_rows_per_run'] ) : self::DEFAULT_SCHEDULED_MAX_ROWS,
			'optimize_after_cleanup'          => ! empty( $settings['optimize_after_cleanup'] ),
			'recalculate_spam_before_cleanup' => ! empty( $settings['recalculate_spam_before_cleanup'] ),
			'last_run'                        => isset( $settings['last_run'] ) ? $settings['last_run'] : null,
			'last_result'                     => isset( $settings['last_result'] ) && is_array( $settings['last_result'] ) ? $settings['last_result'] : array(),
		);
	}

	/**
	 * Determine whether a scheduled cleanup call is too early for its cadence.
	 *
	 * @since 1.2.7
	 * @param array $settings Normalized scheduled cleanup settings.
	 * @return bool True when the run should be skipped.
	 */
	public function should_skip_scheduled_cleanup_for_frequency( $settings ) {
		$last_run = isset( $settings['last_run'] ) ? intval( $settings['last_run'] ) : 0;
		if ( $last_run <= 0 ) {
			return false;
		}

		$frequency = isset( $settings['frequency'] ) ? $settings['frequency'] : 'daily';
		$intervals = array(
			'daily'   => DAY_IN_SECONDS,
			'weekly'  => 7 * DAY_IN_SECONDS,
			'monthly' => 30 * DAY_IN_SECONDS,
		);
		$interval = isset( $intervals[ $frequency ] ) ? $intervals[ $frequency ] : $intervals['daily'];

		return ( time() - $last_run ) < $interval;
	}

	/**
	 * Clamp scheduled cleanup per-run row cap.
	 *
	 * @since 1.2.7
	 * @param mixed $value Raw value.
	 * @return int Sanitized row cap.
	 */
	public function sanitize_scheduled_max_rows( $value ) {
		$value = absint( $value );
		if ( $value < 1 ) {
			return self::DEFAULT_SCHEDULED_MAX_ROWS;
		}

		return min( $value, self::MAX_SCHEDULED_ROWS_PER_RUN );
	}

	/**
	 * Recalculate spam flags for a bounded scheduled-cleanup batch.
	 *
	 * Unlike the manual AJAX recalculation, cron does not reset all existing spam
	 * flags because that can be expensive and unsafe in a partial run. It only
	 * marks currently human/unknown sessions that fail the active thresholds.
	 *
	 * @since 1.2.7
	 * @param int $limit Maximum sessions to inspect.
	 * @return array Result counts and warnings.
	 */
	private function recalculate_spam_traffic_for_scheduled_cleanup( $limit ) {
		global $wpdb;

		$result = array(
			'processed' => 0,
			'updated'   => 0,
			'warnings'  => array(),
		);

		if ( ! $this->table_exists( 'optibehavior_sessions' ) || ! $this->table_exists( 'optibehavior_events' ) ) {
			return $result;
		}

		$limit          = $this->sanitize_scheduled_max_rows( $limit );
		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$events_table   = esc_sql( $wpdb->prefix . 'optibehavior_events' );
		$settings       = get_option( 'opti_behavior_traffic_settings', array() );

		$spam_duration_threshold    = isset( $settings['spam_duration_threshold'] ) ? max( 0, intval( $settings['spam_duration_threshold'] ) ) : 3;
		$spam_min_scrolls_threshold = isset( $settings['spam_min_scrolls_threshold'] ) ? max( 0, intval( $settings['spam_min_scrolls_threshold'] ) ) : 0;
		$spam_min_clicks_threshold  = isset( $settings['spam_min_clicks_threshold'] ) ? max( 0, intval( $settings['spam_min_clicks_threshold'] ) ) : 1;

		$sessions = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM " . $sessions_table . "
				 WHERE (traffic_type = 'human' OR traffic_type IS NULL OR traffic_type = '')
				 ORDER BY id ASC
				 LIMIT %d",
				$limit
			)
		);

		if ( empty( $sessions ) ) {
			return $result;
		}

		$result['processed'] = count( $sessions );
		$session_ids_placeholder = implode( ',', array_fill( 0, count( $sessions ), '%s' ) );
		$query = $wpdb->prepare(
			"UPDATE " . $sessions_table . " s
			 LEFT JOIN (
				 SELECT session_id, COUNT(*) as scroll_count
				 FROM " . $events_table . "
				 WHERE session_id IN (" . $session_ids_placeholder . ")
				 AND event IN (32, 33)
				 GROUP BY session_id
			 ) sc ON s.id = sc.session_id
			 LEFT JOIN (
				 SELECT session_id, COUNT(*) as click_count
				 FROM " . $events_table . "
				 WHERE session_id IN (" . $session_ids_placeholder . ")
				 AND event IN (16, 17)
				 GROUP BY session_id
			 ) cc ON s.id = cc.session_id
			 SET s.traffic_type = 'spam',
			     s.spam_reason = CONCAT_WS(',',
				     CASE WHEN (
					     CASE WHEN s.duration > 0 THEN s.duration
					          ELSE TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time))
					     END
				     ) < %d THEN 'short_duration' ELSE NULL END,
				     CASE WHEN COALESCE(sc.scroll_count, 0) < %d THEN 'few_scrolls' ELSE NULL END,
				     CASE WHEN COALESCE(cc.click_count, 0) < %d THEN 'few_clicks' ELSE NULL END
			     )
			 WHERE s.id IN (" . $session_ids_placeholder . ")
			   AND (
			       (
				       CASE
					       WHEN s.duration > 0 THEN s.duration
					       ELSE TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time))
				       END
			       ) < %d
			       OR COALESCE(sc.scroll_count, 0) < %d
			       OR COALESCE(cc.click_count, 0) < %d
			   )",
			...array_merge(
				$sessions,
				$sessions,
				array( $spam_duration_threshold, $spam_min_scrolls_threshold, $spam_min_clicks_threshold ),
				$sessions,
				array( $spam_duration_threshold, $spam_min_scrolls_threshold, $spam_min_clicks_threshold )
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom analytics table bulk update; $query already bound via $wpdb->prepare() above, per-request caching not applicable.
		$updated = $wpdb->query( $query );
		$result['updated'] = false !== $updated ? absint( $updated ) : 0;

		if ( count( $sessions ) >= $limit ) {
			$result['warnings'][] = __( 'Scheduled spam recalculation reached the per-run row cap; remaining sessions will be evaluated in later runs.', 'opti-behavior' );
		}

		return $result;
	}

	/**
	 * Add a cleanup log entry.
	 *
	 * Consecutive identical entries are collapsed into the newest one: instead of
	 * unshifting a duplicate, the existing entry keeps its `first_timestamp`,
	 * increments `repeat_count` and refreshes its timestamp and numeric fields.
	 * Only the newest entry is compared, so any differing run breaks the streak.
	 *
	 * @since 1.2.7
	 * @since 2026-09-01 Collapses consecutive identical entries via `signature` / `repeat_count`.
	 * @param string $type             Cleanup type.
	 * @param int    $sessions_deleted Number of sessions deleted.
	 * @param int    $events_deleted   Number of events deleted.
	 * @param int    $files_deleted    Number of files deleted.
	 * @param array  $details          Optional detailed cleanup result.
	 */
	public function add_cleanup_log( $type, $sessions_deleted, $events_deleted = 0, $files_deleted = 0, $details = array() ) {
		$logs = get_option( 'opti_behavior_cleanup_logs', array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		$details = is_array( $details ) ? $details : array();
		$entry   = array(
			'timestamp'                 => current_time( 'mysql' ),
			'type'                      => sanitize_text_field( $type ),
			'sessions_deleted'          => absint( $sessions_deleted ),
			'events_deleted'            => absint( $events_deleted ),
			'files_deleted'             => isset( $details['files_deleted'] ) ? absint( $details['files_deleted'] ) : absint( $files_deleted ),
			'orphaned_visitors_deleted' => isset( $details['orphaned_visitors_deleted'] ) ? absint( $details['orphaned_visitors_deleted'] ) : 0,
			'rows_by_table'             => isset( $details['rows_by_table'] ) && is_array( $details['rows_by_table'] ) ? array_map( 'absint', $details['rows_by_table'] ) : array(),
			'optimized_tables'          => isset( $details['optimized_tables'] ) && is_array( $details['optimized_tables'] ) ? array_values( array_map( 'sanitize_text_field', $details['optimized_tables'] ) ) : array(),
			'estimated_bytes_reclaimed' => isset( $details['estimated_bytes_reclaimed'] ) ? absint( $details['estimated_bytes_reclaimed'] ) : 0,
			'status'                    => isset( $details['status'] ) ? sanitize_key( $details['status'] ) : 'completed',
			'trigger'                   => isset( $details['trigger'] ) ? sanitize_key( $details['trigger'] ) : '',
			'warnings'                  => isset( $details['warnings'] ) && is_array( $details['warnings'] ) ? array_values( array_map( 'sanitize_text_field', $details['warnings'] ) ) : array(),
		);

		$entry['signature']       = $this->build_cleanup_log_signature( $entry );
		$entry['repeat_count']    = 1;
		$entry['first_timestamp'] = $entry['timestamp'];

		$previous = isset( $logs[0] ) && is_array( $logs[0] ) ? $logs[0] : array();

		if ( ! empty( $previous['signature'] ) && $previous['signature'] === $entry['signature'] ) {
			// Same run as the newest entry: collapse instead of appending a duplicate.
			$entry['repeat_count'] = max( 1, absint( isset( $previous['repeat_count'] ) ? $previous['repeat_count'] : 1 ) ) + 1;

			if ( ! empty( $previous['first_timestamp'] ) ) {
				$entry['first_timestamp'] = $previous['first_timestamp'];
			} elseif ( ! empty( $previous['timestamp'] ) ) {
				$entry['first_timestamp'] = $previous['timestamp'];
			}

			$logs[0] = $entry;
			$logs    = array_slice( $logs, 0, 20 );
			update_option( 'opti_behavior_cleanup_logs', $logs, false );
			return;
		}

		array_unshift(
			$logs,
			$entry
		);

		$logs = array_slice( $logs, 0, 20 );
		update_option( 'opti_behavior_cleanup_logs', $logs, false );
	}

	/**
	 * Build the collapse signature for a cleanup log entry.
	 *
	 * Numbers inside warnings are normalized away so a message whose embedded
	 * counts drift between otherwise identical runs (for example the mass-delete
	 * circuit breaker report) still collapses into a single history entry.
	 *
	 * @since 2026-09-01
	 * @param array $entry Normalized log entry.
	 * @return string 32-character hexadecimal signature.
	 */
	private function build_cleanup_log_signature( $entry ) {
		$warnings = isset( $entry['warnings'] ) && is_array( $entry['warnings'] ) ? $entry['warnings'] : array();

		$normalized_warnings = array_values(
			array_map(
				static function ( $warning ) {
					return preg_replace( '/\d[\d\s.,%]*/', '#', (string) $warning );
				},
				$warnings
			)
		);

		$payload = array(
			isset( $entry['type'] ) ? (string) $entry['type'] : '',
			isset( $entry['status'] ) ? (string) $entry['status'] : '',
			isset( $entry['trigger'] ) ? (string) $entry['trigger'] : '',
			! empty( $entry['sessions_deleted'] ) ? 'deleted' : 'none',
			$normalized_warnings,
		);

		return md5( (string) wp_json_encode( $payload ) );
	}

	/**
	 * Get cleanup log entries.
	 *
	 * Entries written before the de-duplication keys existed are back-filled with
	 * defaults so the Danger Zone renders legacy option data without notices.
	 *
	 * @since 1.2.7
	 * @since 2026-09-01 Back-fills `repeat_count`, `first_timestamp` and `signature`.
	 * @param int $limit Maximum entries.
	 * @return array Log entries.
	 */
	public function get_cleanup_logs( $limit = 10 ) {
		$logs = get_option( 'opti_behavior_cleanup_logs', array() );
		if ( ! is_array( $logs ) ) {
			return array();
		}

		$logs = array_slice( $logs, 0, absint( $limit ) );

		foreach ( $logs as $index => $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}

			$log['repeat_count'] = isset( $log['repeat_count'] ) ? max( 1, absint( $log['repeat_count'] ) ) : 1;

			if ( empty( $log['first_timestamp'] ) ) {
				$log['first_timestamp'] = isset( $log['timestamp'] ) ? $log['timestamp'] : '';
			}

			if ( ! isset( $log['signature'] ) ) {
				$log['signature'] = '';
			}

			$logs[ $index ] = $log;
		}

		return $logs;
	}

	/**
	 * Return traffic counts for a matching-session subquery.
	 *
	 * @since 1.2.7
	 * @param string $session_sql SQL that selects matching session IDs.
	 * @return array<string,int> Traffic type => count.
	 */
	private function get_traffic_breakdown_for_session_sql( $session_sql ) {
		global $wpdb;

		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$breakdown      = array();
		$rows           = $wpdb->get_results(
			"SELECT COALESCE(NULLIF(traffic_type, ''), 'unknown') AS traffic_type, COUNT(*) AS total
			 FROM " . $sessions_table . "
			 WHERE id IN (" . $session_sql . ")
			 GROUP BY COALESCE(NULLIF(traffic_type, ''), 'unknown')",
			defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A'
		);

		foreach ( (array) $rows as $row ) {
			$key               = isset( $row['traffic_type'] ) ? sanitize_key( $row['traffic_type'] ) : 'unknown';
			$breakdown[ $key ] = isset( $row['total'] ) ? absint( $row['total'] ) : 0;
		}

		return $breakdown;
	}

	/**
	 * Estimate how many visitors will become orphaned if matching sessions are removed.
	 *
	 * @since 1.2.7
	 * @param string $session_sql SQL that selects matching session IDs.
	 * @return int Orphan visitor estimate.
	 */
	private function estimate_orphaned_visitors_for_session_sql( $session_sql ) {
		global $wpdb;

		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return 0;
		}

		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$count          = $wpdb->get_var(
			"SELECT COUNT(DISTINCT deleting.visitor_id)
			 FROM " . $sessions_table . " deleting
			 WHERE deleting.id IN (" . $session_sql . ")
			   AND deleting.visitor_id IS NOT NULL
			   AND deleting.visitor_id != ''
			   AND NOT EXISTS (
				   SELECT 1
				   FROM " . $sessions_table . " remaining
				   WHERE remaining.visitor_id = deleting.visitor_id
				     AND remaining.id NOT IN (" . $session_sql . ")
			   )"
		);

		return absint( $count );
	}

	/**
	 * Build preview warnings from affected objects.
	 *
	 * @since 1.2.7
	 * @param array $conditions        Cleanup conditions.
	 * @param array $rows_by_table     Rows estimated by table.
	 * @param int   $recording_files   Recording file count.
	 * @param int   $orphaned_visitors Orphan visitor count.
	 * @return array<int,string> Warning messages.
	 */
	private function build_preview_warnings( $conditions, $rows_by_table, $recording_files, $orphaned_visitors ) {
		$warnings = array();

		if ( empty( $rows_by_table['optibehavior_sessions'] ) ) {
			$warnings[] = __( 'No matching sessions were found for the selected conditions.', 'opti-behavior' );
		}

		if ( ! empty( $rows_by_table['optibehavior_ab_impressions'] ) || ! empty( $rows_by_table['optibehavior_ab_conversions'] ) ) {
			$warnings[] = __( 'A/B raw impressions and conversions may be removed, but test configuration, variants, goals, targeting, and schedules remain intact.', 'opti-behavior' );
		}

		if ( ! empty( $rows_by_table['opti_behavior_funnel_tracking'] ) ) {
			$warnings[] = __( 'Funnel tracking rows may be removed, but funnel definitions remain intact.', 'opti-behavior' );
		}

		if ( $recording_files > 0 ) {
			$warnings[] = __( 'Recording files for deleted sessions will be removed from the validated Opti-Behavior uploads directory.', 'opti-behavior' );
		}

		if ( $orphaned_visitors > 0 && empty( $conditions['delete_orphaned_visitors'] ) ) {
			$warnings[] = __( 'Some visitor rows may become orphaned because orphan cleanup is disabled.', 'opti-behavior' );
		}

		return $warnings;
	}

	/**
	 * Get table-size and fragmentation metadata for touched Opti-Behavior tables.
	 *
	 * @since 1.2.7
	 * @param array<int,string> $table_suffixes Unprefixed table suffixes.
	 * @return array Fragmentation report.
	 */
	public function get_table_fragmentation_report( $table_suffixes ) {
		$table_suffixes = $this->sanitize_optibehavior_table_suffixes( $table_suffixes );
		$table_sizes    = $this->get_table_size_metadata( $table_suffixes );
		$rows_by_table  = array();

		foreach ( $table_sizes as $table_suffix => $metadata ) {
			$rows_by_table[ $table_suffix ] = isset( $metadata['total_rows'] ) ? absint( $metadata['total_rows'] ) : 0;
		}

		return array(
			'tables'                  => $table_sizes,
			'optimization_candidates' => $this->get_optimization_candidates( $table_sizes, $rows_by_table ),
		);
	}

	/**
	 * Safely optimize touched Opti-Behavior tables when fragmentation is high.
	 *
	 * Tables are optimized only when they exceed the configured free-space
	 * threshold or fragmentation ratio, and table names are accepted only from
	 * Opti-Behavior-prefixed suffixes.
	 *
	 * @since 1.2.7
	 * @param array<string,int>|array<int,string> $rows_by_table Touched table rows or suffix list.
	 * @return array Result with optimized tables and warnings.
	 */
	public function optimize_touched_tables( $rows_by_table ) {
		global $wpdb;

		$table_suffixes = array();
		$row_map        = array();

		foreach ( (array) $rows_by_table as $key => $value ) {
			if ( is_string( $key ) ) {
				$table_suffix = $key;
				$row_count    = absint( $value );
			} else {
				$table_suffix = $value;
				$row_count    = 1;
			}

			if ( $row_count <= 0 ) {
				continue;
			}

			$table_suffixes[] = $table_suffix;
		}

		$table_suffixes = $this->sanitize_optibehavior_table_suffixes( $table_suffixes );
		foreach ( $table_suffixes as $table_suffix ) {
			$row_map[ $table_suffix ] = isset( $rows_by_table[ $table_suffix ] ) ? absint( $rows_by_table[ $table_suffix ] ) : 1;
		}

		if ( empty( $table_suffixes ) ) {
			return array(
				'optimized_tables'          => array(),
				'optimization_candidates'   => array(),
				'estimated_bytes_reclaimed' => 0,
				'warnings'                  => array(),
			);
		}

		$table_sizes = $this->get_table_size_metadata( $table_suffixes );
		$candidates  = $this->get_optimization_candidates( $table_sizes, $row_map );
		$optimized   = array();
		$warnings    = array();
		$bytes       = 0;

		foreach ( $candidates as $candidate ) {
			$table_suffix = isset( $candidate['table'] ) ? (string) $candidate['table'] : '';
			if ( '' === $table_suffix || ! $this->table_exists( $table_suffix ) ) {
				continue;
			}

			$full_table = esc_sql( $wpdb->prefix . $table_suffix );
			$ok         = $wpdb->query( "OPTIMIZE TABLE `" . $full_table . "`" );
			if ( false === $ok ) {
				$warnings[] = sprintf(
					/* translators: %s: table name */
					__( 'Could not optimize table %s.', 'opti-behavior' ),
					$table_suffix
				);
				continue;
			}

			$optimized[] = $table_suffix;
			$bytes      += isset( $candidate['data_free'] ) ? absint( $candidate['data_free'] ) : 0;
		}

		return array(
			'optimized_tables'          => $optimized,
			'optimization_candidates'   => $candidates,
			'estimated_bytes_reclaimed' => $bytes,
			'warnings'                  => $warnings,
		);
	}

	/**
	 * Sanitize table suffixes and keep only Opti-Behavior-owned names.
	 *
	 * @since 1.2.7
	 * @param array<int,string> $table_suffixes Raw suffixes.
	 * @return array<int,string> Sanitized suffixes.
	 */
	private function sanitize_optibehavior_table_suffixes( $table_suffixes ) {
		$sanitized = array();

		foreach ( (array) $table_suffixes as $table_suffix ) {
			$table_suffix = preg_replace( '/[^a-z0-9_]/', '', (string) $table_suffix );
			if ( '' === $table_suffix ) {
				continue;
			}

			if ( 0 !== strpos( $table_suffix, 'optibehavior_' ) && 0 !== strpos( $table_suffix, 'opti_behavior_' ) ) {
				continue;
			}

			$sanitized[ $table_suffix ] = $table_suffix;
		}

		return array_values( $sanitized );
	}

	/**
	 * Get table size metadata for known Opti-Behavior tables.
	 *
	 * @since 1.2.7
	 * @param array<int,string> $table_suffixes Unprefixed table suffixes.
	 * @return array<string,array<string,int>> Size metadata keyed by suffix.
	 */
	private function get_table_size_metadata( $table_suffixes ) {
		global $wpdb;

		$table_suffixes = array_values(
			array_filter(
				array_unique(
					array_map(
						static function ( $table_suffix ) {
							return preg_replace( '/[^a-z0-9_]/', '', (string) $table_suffix );
						},
						(array) $table_suffixes
					)
				)
			)
		);

		if ( empty( $table_suffixes ) ) {
			return array();
		}

		$full_names    = array();
		$suffix_lookup = array();
		foreach ( $table_suffixes as $table_suffix ) {
			$full_name                   = $wpdb->prefix . $table_suffix;
			$full_names[]                = $full_name;
			$suffix_lookup[ $full_name ] = $table_suffix;
		}

		$placeholders = implode( ',', array_fill( 0, count( $full_names ), '%s' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME, COALESCE(DATA_LENGTH, 0) AS data_length, COALESCE(INDEX_LENGTH, 0) AS index_length, COALESCE(DATA_FREE, 0) AS data_free
				 FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = DATABASE()
				   AND TABLE_NAME IN (" . $placeholders . ")",
				...$full_names
			),
			defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A'
		);

		$metadata = array();
		foreach ( (array) $rows as $row ) {
			if ( empty( $row['TABLE_NAME'] ) || ! isset( $suffix_lookup[ $row['TABLE_NAME'] ] ) ) {
				continue;
			}

			$table_suffix = $suffix_lookup[ $row['TABLE_NAME'] ];
			$data_length  = isset( $row['data_length'] ) ? absint( $row['data_length'] ) : 0;
			$index_length = isset( $row['index_length'] ) ? absint( $row['index_length'] ) : 0;
			$data_free    = isset( $row['data_free'] ) ? absint( $row['data_free'] ) : 0;

			$metadata[ $table_suffix ] = array(
				'table_bytes' => $data_length + $index_length,
				'data_free'   => $data_free,
				'total_rows'  => $this->count_table_rows( $table_suffix ),
			);
		}

		foreach ( $table_suffixes as $table_suffix ) {
			if ( ! isset( $metadata[ $table_suffix ] ) ) {
				$metadata[ $table_suffix ] = array(
					'table_bytes' => 0,
					'data_free'   => 0,
					'total_rows'  => $this->count_table_rows( $table_suffix ),
				);
			}
		}

		return $metadata;
	}

	/**
	 * Count total rows in a known table.
	 *
	 * @since 1.2.7
	 * @param string $table_suffix Unprefixed table suffix.
	 * @return int Total rows.
	 */
	private function count_table_rows( $table_suffix ) {
		global $wpdb;

		if ( ! $this->table_exists( $table_suffix ) ) {
			return 0;
		}

		$table = esc_sql( $wpdb->prefix . $table_suffix );

		return absint( $wpdb->get_var( "SELECT COUNT(*) FROM " . $table ) );
	}

	/**
	 * Estimate bytes represented by affected rows in each table.
	 *
	 * @since 1.2.7
	 * @param array $rows_by_table Rows by table suffix.
	 * @param array $table_sizes   Table size metadata.
	 * @return array{total:int,by_table:array}
	 */
	private function estimate_affected_bytes( $rows_by_table, $table_sizes ) {
		$total    = 0;
		$by_table = array();

		foreach ( $rows_by_table as $table_suffix => $affected_rows ) {
			$affected_rows = absint( $affected_rows );
			$table_bytes   = isset( $table_sizes[ $table_suffix ]['table_bytes'] ) ? absint( $table_sizes[ $table_suffix ]['table_bytes'] ) : 0;
			$total_rows    = isset( $table_sizes[ $table_suffix ]['total_rows'] ) ? absint( $table_sizes[ $table_suffix ]['total_rows'] ) : 0;

			if ( $affected_rows <= 0 || $table_bytes <= 0 || $total_rows <= 0 ) {
				$by_table[ $table_suffix ] = 0;
				continue;
			}

			$estimated = absint( floor( $table_bytes * min( 1, $affected_rows / $total_rows ) ) );
			$by_table[ $table_suffix ] = $estimated;
			$total += $estimated;
		}

		return array(
			'total'    => $total,
			'by_table' => $by_table,
		);
	}

	/**
	 * Return table size bytes keyed by suffix.
	 *
	 * @since 1.2.7
	 * @param array $table_sizes Table size metadata.
	 * @return array<string,int>
	 */
	private function pluck_table_size_bytes( $table_sizes ) {
		$bytes = array();

		foreach ( $table_sizes as $table_suffix => $metadata ) {
			$bytes[ $table_suffix ] = isset( $metadata['table_bytes'] ) ? absint( $metadata['table_bytes'] ) : 0;
		}

		return $bytes;
	}

	/**
	 * Find likely candidates for a later optimization pass.
	 *
	 * @since 1.2.7
	 * @param array $table_sizes   Table size metadata.
	 * @param array $rows_by_table Rows by table suffix.
	 * @return array<int,array<string,int|string>>
	 */
	private function get_optimization_candidates( $table_sizes, $rows_by_table ) {
		$candidates = array();

		foreach ( $table_sizes as $table_suffix => $metadata ) {
			if ( empty( $rows_by_table[ $table_suffix ] ) ) {
				continue;
			}

			$table_bytes = isset( $metadata['table_bytes'] ) ? absint( $metadata['table_bytes'] ) : 0;
			$data_free   = isset( $metadata['data_free'] ) ? absint( $metadata['data_free'] ) : 0;
			$ratio       = $table_bytes > 0 ? $data_free / $table_bytes : 0;

			if ( $data_free >= self::OPTIMIZATION_MIN_FREE_BYTES || $ratio >= 0.10 ) {
				$candidates[] = array(
					'table'       => $table_suffix,
					'data_free'   => $data_free,
					'table_bytes' => $table_bytes,
				);
			}
		}

		return $candidates;
	}

	/**
	 * Accumulate a cascade batch report in transient options.
	 *
	 * @since 1.2.7
	 * @param string $option_prefix  Option prefix.
	 * @param array  $cascade_result Batch cascade result.
	 */
	private function accumulate_cleanup_batch_result( $option_prefix, $cascade_result ) {
		$rows_option  = $option_prefix . '_rows_deleted_by_table';
		$files_option = $option_prefix . '_files_deleted';
		$rows         = (array) get_option( $rows_option, array() );
		$batch_rows   = isset( $cascade_result['rows_deleted_by_table'] ) && is_array( $cascade_result['rows_deleted_by_table'] ) ? $cascade_result['rows_deleted_by_table'] : array();
		$files        = absint( get_option( $files_option, 0 ) );
		$batch_files  = isset( $cascade_result['files_deleted'] ) ? absint( $cascade_result['files_deleted'] ) : 0;

		update_option( $rows_option, $this->merge_row_count_maps( $rows, $batch_rows ), false );
		update_option( $files_option, $files + $batch_files, false );
	}

	/**
	 * Merge row-count maps.
	 *
	 * @since 1.2.7
	 * @param array $base Base counts.
	 * @param array $add  Counts to add.
	 * @return array<string,int> Merged counts.
	 */
	private function merge_row_count_maps( $base, $add ) {
		foreach ( (array) $add as $table_suffix => $count ) {
			$table_suffix = preg_replace( '/[^a-z0-9_]/', '', (string) $table_suffix );
			if ( '' === $table_suffix ) {
				continue;
			}

			if ( ! isset( $base[ $table_suffix ] ) ) {
				$base[ $table_suffix ] = 0;
			}
			$base[ $table_suffix ] = absint( $base[ $table_suffix ] ) + absint( $count );
		}

		return $base;
	}

	/**
	 * Delete recording files for sessions before database rows are removed.
	 *
	 * @since 1.2.7
	 * @param array<int,int> $session_ids Session IDs.
	 * @return int Deleted file count.
	 */
	private function delete_recording_files_for_sessions( $session_ids ) {
		global $wpdb;

		if ( ! $this->table_exists( 'optibehavior_recordings' ) ) {
			return 0;
		}

		$session_ids      = $this->normalize_session_ids( $session_ids );
		if ( empty( $session_ids ) ) {
			return 0;
		}

		$placeholders     = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );
		$recordings_table = esc_sql( $wpdb->prefix . 'optibehavior_recordings' );
		$file_paths       = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT file_path FROM " . $recordings_table . " WHERE session_id IN (" . $placeholders . ") AND file_path IS NOT NULL AND file_path != ''",
				...$session_ids
			)
		);

		return $this->delete_recording_files( $file_paths );
	}

	/**
	 * Delete recording files after validating they are inside uploads/opti-behavior-data.
	 *
	 * @since 1.2.7
	 * @param array $file_paths Stored recording paths.
	 * @return int Deleted file count.
	 */
	public function delete_recording_files( $file_paths ) {
		if ( empty( $file_paths ) || ! is_array( $file_paths ) ) {
			return 0;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';
		$base_real  = realpath( $base_dir );
		$base_norm  = $this->normalize_path_for_compare( false !== $base_real ? $base_real : $base_dir );
		$base_norm  = rtrim( $base_norm, '/\\' ) . '/';
		$deleted    = 0;

		foreach ( $file_paths as $path ) {
			$path = (string) $path;
			if ( '' === trim( $path ) ) {
				continue;
			}

			$candidate = $this->is_absolute_path( $path ) ? $path : $base_dir . ltrim( $path, '/\\' );
			$real      = realpath( $candidate );
			if ( false === $real || ! is_file( $real ) ) {
				continue;
			}

			$real_norm = $this->normalize_path_for_compare( $real );
			if ( 0 !== strpos( $real_norm, $base_norm ) ) {
				continue;
			}

			wp_delete_file( $real );
			if ( ! is_file( $real ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * ARCHIVE (never hard-delete — Option B guardrail) the heatmap interaction
	 * files (clicks/moves/scrolls JSON) of sessions about to be cascade-deleted.
	 *
	 * Matched files are MOVED to `_orphaned/{hash}/{folder}/` via
	 * {@see Opti_Behavior_Heatmap_Storage::archive_heatmap_session_file()} so
	 * the raw data stays recoverable; permanent deletion happens ONLY later
	 * via the Archived Heatmap Data Retention purge of `_orphaned/`
	 * (2026-08-16 mass-deletion incident guardrail — a cleanup pass must
	 * never make heatmap raw data unrecoverable).
	 *
	 * Must be called before the caller deletes `optibehavior_session_pages`
	 * rows: page_id resolution (and therefore the URL-hash directory lookup)
	 * depends on those rows still existing.
	 *
	 * Filenames only carry the last 8 chars of the session ID (see
	 * `Opti_Behavior_Heatmap_Storage::build_filename()`), so a suffix match
	 * alone is not guaranteed unique. Matches are additionally required to
	 * fall inside the candidate session's [start_time, end_time or
	 * start_time+duration] window before archival, and the storage archive
	 * helper enforces an opti-behavior-data containment guard on every path.
	 *
	 * @since 1.2.7
	 * @since 2026-08-16 Archives matched JSON to `_orphaned/` instead of wp_delete_file.
	 * @param array<int,string> $session_ids Session IDs about to be deleted (already normalized).
	 * @return array{deleted:int,archived:int,page_ids:array<int,int>} Number of heatmap files
	 *                                                     removed from the live tree (archived),
	 *                                                     and the page IDs those sessions were
	 *                                                     associated with (for aggregate-cache
	 *                                                     invalidation).
	 */
	private function delete_heatmap_files_for_sessions( $session_ids ) {
		global $wpdb;

		$empty_result = array(
			'deleted'  => 0,
			'archived' => 0,
			'page_ids' => array(),
		);

		if ( ! $this->table_exists( 'optibehavior_session_pages' ) || ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			return $empty_result;
		}

		$session_ids = $this->normalize_session_ids( $session_ids );
		if ( empty( $session_ids ) ) {
			return $empty_result;
		}

		$placeholders  = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );
		$session_pages = esc_sql( $wpdb->prefix . 'optibehavior_session_pages' );
		$rows          = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT session_id, page_id FROM " . $session_pages . " WHERE session_id IN (" . $placeholders . ')',
				...$session_ids
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return $empty_result;
		}

		$page_ids = array();
		foreach ( $rows as $row ) {
			$page_id = isset( $row['page_id'] ) ? absint( $row['page_id'] ) : 0;
			if ( $page_id > 0 ) {
				$page_ids[ $page_id ] = $page_id;
			}
		}

		if ( empty( $page_ids ) ) {
			return $empty_result;
		}

		// Group deletion candidates by their filename token so a file only
		// needs one lookup regardless of how many sessions share a suffix.
		$sessions_by_token = array();
		foreach ( $session_ids as $session_id ) {
			$token = substr( preg_replace( '/[^a-zA-Z0-9]/', '', $session_id ), -8 );
			if ( '' !== $token ) {
				$sessions_by_token[ $token ][] = $session_id;
			}
		}

		if ( empty( $sessions_by_token ) ) {
			return array(
				'deleted'  => 0,
				'archived' => 0,
				'page_ids' => array_values( $page_ids ),
			);
		}

		$windows = $this->get_session_time_windows( $session_ids );
		$storage = Opti_Behavior_Heatmap_Storage::get_instance();
		$matched_files = array();

		foreach ( $page_ids as $page_id ) {
			$url_hash = $storage->get_url_hash_for_page( $page_id );
			if ( ! $url_hash ) {
				continue;
			}

			foreach ( array( 'clicks', 'moves', 'scrolls' ) as $type ) {
				$dir = $storage->get_heatmap_dir( $url_hash, $type );
				if ( ! is_dir( $dir ) ) {
					continue;
				}

				$files = glob( $dir . '*.json' );
				if ( empty( $files ) ) {
					continue;
				}

				foreach ( $files as $file ) {
					$parts = explode( '_', str_replace( '.json', '', basename( $file ) ) );
					$token = Opti_Behavior_Heatmap_Storage::parse_session_token( $parts );

					if ( '' === (string) $token || ! isset( $sessions_by_token[ $token ] ) ) {
						continue;
					}

					$file_timestamp = isset( $parts[0] ) ? (int) $parts[0] : 0;

					if ( $this->file_timestamp_matches_a_session_window( $file_timestamp, $sessions_by_token[ $token ], $windows ) ) {
						$matched_files[] = array(
							'hash'   => $url_hash,
							'folder' => $type,
							'path'   => $file,
						);
					}
				}
			}
		}

		// Option B guardrail: ARCHIVE matched files to `_orphaned/{hash}/{folder}/`
		// (recoverable) instead of hard-deleting them.
		$archived = 0;
		foreach ( $matched_files as $match ) {
			if ( $storage->archive_heatmap_session_file( $match['hash'], $match['folder'], $match['path'] ) ) {
				++$archived;
			}
		}

		return array(
			'deleted'  => $archived,
			'archived' => $archived,
			'page_ids' => array_values( $page_ids ),
		);
	}

	/**
	 * Check whether a heatmap file's embedded timestamp falls inside the
	 * time window of at least one of the candidate sessions sharing its
	 * filename token (collision guard for the 8-char session suffix).
	 *
	 * @since 1.2.7
	 * @param int                                   $file_timestamp     Unix timestamp parsed from the filename.
	 * @param array<int,string>                     $candidate_sessions Session IDs sharing this filename token.
	 * @param array<string,array{start:int,end:int}> $windows            Session ID => time window, from get_session_time_windows().
	 * @return bool True when the file timestamp matches a candidate session's window.
	 */
	private function file_timestamp_matches_a_session_window( $file_timestamp, array $candidate_sessions, array $windows ) {
		foreach ( $candidate_sessions as $session_id ) {
			if ( ! isset( $windows[ $session_id ] ) ) {
				continue;
			}

			$window = $windows[ $session_id ];
			if ( $file_timestamp >= $window['start'] && $file_timestamp <= $window['end'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Load [start_time, end_time or start_time+duration] unix-timestamp
	 * windows for the given sessions, used as a collision guard when matching
	 * heatmap filenames by their 8-char session-suffix token.
	 *
	 * @since 1.2.7
	 * @param array<int,string> $session_ids Session IDs (already normalized).
	 * @return array<string,array{start:int,end:int}> Session ID => time window.
	 */
	private function get_session_time_windows( $session_ids ) {
		global $wpdb;

		if ( ! $this->table_exists( 'optibehavior_sessions' ) || empty( $session_ids ) ) {
			return array();
		}

		$placeholders   = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );
		$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
		$rows           = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, start_time, end_time, duration FROM " . $sessions_table . ' WHERE id IN (' . $placeholders . ')',
				...$session_ids
			),
			ARRAY_A
		);

		$windows = array();
		foreach ( (array) $rows as $row ) {
			$start = isset( $row['start_time'] ) ? strtotime( (string) $row['start_time'] ) : false;
			if ( false === $start ) {
				continue;
			}

			$end = ! empty( $row['end_time'] ) ? strtotime( (string) $row['end_time'] ) : false;
			if ( false === $end || $end < $start ) {
				$end = $start + max( 0, (int) ( isset( $row['duration'] ) ? $row['duration'] : 0 ) );
			}

			$windows[ (string) $row['id'] ] = array(
				'start' => $start,
				'end'   => $end,
			);
		}

		return $windows;
	}

	/**
	 * Normalize session IDs for prepared string placeholders.
	 *
	 * Opti-Behavior session IDs are varchar values such as
	 * "session_<timestamp>_<random>". Do not cast them to integers or cascade
	 * deletion will miss real production sessions and their linked rows.
	 *
	 * @since 1.2.7
	 * @param array $session_ids Session IDs.
	 * @return array<int,string> Unique non-empty session IDs.
	 */
	private function normalize_session_ids( $session_ids ) {
		$normalized = array();

		foreach ( (array) $session_ids as $session_id ) {
			$session_id = sanitize_text_field( (string) $session_id );
			if ( '' === $session_id ) {
				continue;
			}

			$normalized[ $session_id ] = $session_id;
		}

		return array_values( $normalized );
	}

	/**
	 * Check whether an Opti-Behavior table exists.
	 *
	 * @since 1.2.7
	 * @param string $table_suffix Unprefixed table suffix.
	 * @return bool True when table exists.
	 */
	private function table_exists( $table_suffix ) {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table_suffix ) );

		return ! empty( $exists );
	}

	/**
	 * Normalize a filesystem path for prefix comparison.
	 *
	 * @since 1.2.7
	 * @param string $path Path.
	 * @return string Normalized path.
	 */
	private function normalize_path_for_compare( $path ) {
		if ( function_exists( 'wp_normalize_path' ) ) {
			return wp_normalize_path( $path );
		}

		return str_replace( '\\', '/', $path );
	}

	/**
	 * Determine if a path is absolute on Windows or Unix-like systems.
	 *
	 * @since 1.2.7
	 * @param string $path Path.
	 * @return bool True when absolute.
	 */
	private function is_absolute_path( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return false;
		}

		if ( '/' === $path[0] || '\\' === $path[0] ) {
			return true;
		}

		// Windows drive-letter absolute path, e.g. "C:\" or "C:/".
		return isset( $path[2] ) && ctype_alpha( $path[0] ) && ':' === $path[1] && ( '\\' === $path[2] || '/' === $path[2] );
	}
}
