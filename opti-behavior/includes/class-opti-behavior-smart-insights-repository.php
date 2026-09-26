<?php
/**
 * Smart Insights Repository Class
 *
 * Handles local storage, retrieval, deduplication, and lifecycle updates for
 * aggregated Smart Insights.
 *
 * @package opti-behavior
 * @since   1.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are built from $wpdb->prefix and hardcoded identifiers.

/**
 * Smart Insights Repository Class
 *
 * Stores deterministic insight objects in the Free plugin database and exposes
 * safe methods for future generators, AJAX handlers, and dashboard views.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Repository {

	const STATUS_NEW           = 'new';
	const STATUS_VIEWED        = 'viewed';
	const STATUS_IN_PROGRESS   = 'in_progress';
	const STATUS_RESOLVED      = 'resolved';
	const STATUS_IGNORED       = 'ignored';
	const STATUS_AUTO_RESOLVED = 'auto_resolved';
	// Closed by us, never by the site owner, and never a win: the rule that
	// raised the card changed and no longer confirms it (a false positive of
	// the older rule), or the card was merged into a twin with the same data.
	const STATUS_RULE_UPDATED     = 'rule_updated';
	const STATUS_MERGED_DUPLICATE = 'merged_duplicate';

	/** Cap on the stored recurrence audit trail so the payload stays bounded. */
	const MAX_RECURRENCE_WINDOWS = 12;

	/**
	 * Admin-menu lightbulb colour: non-autoloaded option written only when an
	 * insight changes (never loaded on the public site), recomputed at most
	 * once after a change, or after MENU_SEVERITY_TTL as a safety net.
	 */
	const MENU_SEVERITY_OPTION = 'opti_behavior_si_menu_severity';

	/** Safety net for write paths outside this class (seconds). */
	const MENU_SEVERITY_TTL = 43200;

	/**
	 * Whether this request already dropped the stored menu severity.
	 *
	 * @var bool
	 */
	private static $menu_severity_flushed = false;

	/**
	 * Database table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Cached table existence check.
	 *
	 * @var bool|null
	 */
	private $table_exists = null;

	/**
	 * Whether optional Phase 0 schema columns have been checked.
	 *
	 * @var bool
	 */
	private $schema_checked = false;

	/**
	 * Constructor.
	 *
	 * @param string $table Optional table override for tests.
	 */
	public function __construct( $table = '' ) {
		global $wpdb;

		$this->table = $table ? $table : $wpdb->prefix . 'optibehavior_insights';
	}

	/**
	 * Get the repository table name.
	 *
	 * @return string
	 */
	public function get_table_name() {
		return $this->table;
	}

	/**
	 * Return allowed insight statuses.
	 *
	 * @return array
	 */
	public static function get_allowed_statuses() {
		return array(
			self::STATUS_NEW,
			self::STATUS_VIEWED,
			self::STATUS_IN_PROGRESS,
			self::STATUS_RESOLVED,
			self::STATUS_IGNORED,
			self::STATUS_AUTO_RESOLVED,
			self::STATUS_RULE_UPDATED,
			self::STATUS_MERGED_DUPLICATE,
		);
	}

	/**
	 * Closed statuses that are never a result of the site owner's work: they
	 * never count as resolved, fixed or won (counters, weekly summary, outcome).
	 *
	 * @return array
	 */
	public static function get_retired_statuses() {
		return array(
			self::STATUS_RULE_UPDATED,
			self::STATUS_MERGED_DUPLICATE,
		);
	}

	/**
	 * Return statuses considered open for deduplication.
	 *
	 * @return array
	 */
	public static function get_open_statuses() {
		return array(
			self::STATUS_NEW,
			self::STATUS_VIEWED,
			self::STATUS_IN_PROGRESS,
			self::STATUS_IGNORED,
		);
	}

	/**
	 * Return statuses considered closed.
	 *
	 * @return array
	 */
	public static function get_closed_statuses() {
		return array(
			self::STATUS_RESOLVED,
			self::STATUS_AUTO_RESOLVED,
			self::STATUS_RULE_UPDATED,
			self::STATUS_MERGED_DUPLICATE,
		);
	}

	/**
	 * Permanently delete closed (resolved / auto-resolved) insights older than
	 * the configured prune window.
	 *
	 * Part of the Unified Retention Protocol: closed insights are historical
	 * records, not raw visitor data, so they use their own months-based clock
	 * (default 12 months, 0 = keep forever). Open and ignored insights are
	 * never touched — ignored rows participate in deduplication, so deleting
	 * them would let the same insight regenerate.
	 *
	 * @param int|null $months Optional override; defaults to the policy setting.
	 * @return int Number of rows deleted (0 when disabled or unavailable).
	 */
	public function prune_stale_insights( $months = null ) {
		global $wpdb;

		if ( null === $months ) {
			$months = class_exists( 'Opti_Behavior_Retention_Policy' )
				? Opti_Behavior_Retention_Policy::get_insights_prune_months()
				: 12;
		}

		$months = (int) $months;
		if ( $months < 1 ) {
			return 0; // 0 = never prune.
		}

		if ( ! $this->table_exists() ) {
			return 0;
		}

		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months', current_time( 'timestamp', true ) ) );
		$statuses = self::get_closed_statuses();
		$holders  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = "DELETE FROM {$this->table}
			WHERE status IN ({$holders})
			AND COALESCE(resolved_at, updated_at) < %s";

		$params   = $statuses;
		$params[] = $cutoff;

		$deleted = $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Check whether the Smart Insights table exists.
	 *
	 * Repository methods use this guard so an interrupted plugin upgrade cannot
	 * crash admin pages before admin_init self-healing has a chance to run.
	 *
	 * @param bool $refresh Whether to bypass the cached result.
	 * @return bool
	 */
	public function table_exists( $refresh = false ) {
		global $wpdb;

		if ( null !== $this->table_exists && ! $refresh ) {
			return $this->table_exists;
		}

		$this->table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) );
		if ( $this->table_exists && ! $this->schema_checked ) {
			$this->ensure_optional_schema();
		}

		return $this->table_exists;
	}

	/**
	 * Insert or update an insight.
	 *
	 * Deduplicates open insights by logical group across overlapping rolling
	 * windows. If the new priority is higher than the stored priority, the
	 * detection JSON is marked with `worsened = true` for UI explanations.
	 * Ignored insights stay ignored on recurring detections unless priority
	 * materially worsens beyond the configured reopen threshold.
	 *
	 * @param array $insight Insight object data.
	 * @return int|WP_Error Insight ID on success.
	 */
	public function upsert_insight( $insight ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return $this->missing_table_error();
		}

		$data = $this->prepare_insight_data( $insight );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$existing = $this->find_existing_open_insight(
			$data['signal_id'],
			$data['entity_type'],
			$data['entity_id'],
			$data['date_from'],
			$data['date_to'],
			$data['group_key']
		);

		if ( $existing ) {
			$old_scores   = $this->decode_json_field( $existing['scores_json'] );
			$new_scores   = $this->decode_json_field( $data['scores_json'] );
			$old_priority = isset( $old_scores['priority_score'] ) ? (float) $old_scores['priority_score'] : 0;
			$new_priority = isset( $new_scores['priority_score'] ) ? (float) $new_scores['priority_score'] : 0;
			$priority_delta         = $new_priority - $old_priority;
			$existing_status        = isset( $existing['status'] ) ? sanitize_key( $existing['status'] ) : self::STATUS_NEW;
			$is_ignored             = self::STATUS_IGNORED === $existing_status;
			$is_material_worsening = $this->is_material_priority_worsening( $old_priority, $new_priority );

			$detection          = $this->decode_json_field( $data['detection_json'] );
			$existing_detection = $this->decode_json_field( $existing['detection_json'] );
			// An issue recurs when it is detected in two *non-overlapping* analysis
			// windows. Counting every changed window instead turned the sliding
			// 30-day range plus a daily scheduler into "Recurring: N days".
			$recurrence = $this->resolve_recurrence(
				$existing_detection,
				isset( $existing['date_from'] ) ? (string) $existing['date_from'] : '',
				isset( $existing['date_to'] ) ? (string) $existing['date_to'] : '',
				(string) $data['date_from'],
				(string) $data['date_to']
			);
			$recurrence_count = $recurrence['count'];

			$detection['same_issue_previous_period'] = true;
			$detection['recurrence_count']           = $recurrence_count;
			$detection['recurrence_windows']         = $recurrence['windows'];
			$detection['previous_status']            = $existing_status;
			$detection['previous_priority_score']    = $old_priority;
			$detection['priority_delta']             = $priority_delta;
			$detection['previous_date_range']        = array(
				'from' => isset( $existing['date_from'] ) ? $existing['date_from'] : '',
				'to'   => isset( $existing['date_to'] ) ? $existing['date_to'] : '',
			);
			$detection['matched_existing_id']        = isset( $existing['id'] ) ? (int) $existing['id'] : 0;
			$detection['matched_by']                 = $this->did_group_key_match_existing( $data, $existing ) ? 'group_key' : 'signal_entity';

			if ( $priority_delta > 0 && ( ! $is_ignored || $is_material_worsening ) ) {
				$detection['worsened'] = true;
			}

			if ( $is_ignored ) {
				$detection['ignored_reopen_threshold'] = $this->get_ignored_reopen_priority_delta();
				if ( $is_material_worsening ) {
					$detection['reopened_from_ignored']   = true;
					$detection['ignored_state_preserved'] = false;
				} else {
					$detection['ignored_state_preserved']      = true;
					$detection['suppressed_recurring_ignored'] = true;
				}
			}

			$data['detection_json'] = $this->encode_json_field( $detection, array() );
			$this->preserve_existing_story_blocks( $data, $existing );
			$data['created_at'] = $existing['created_at'];
			$data['status']     = $is_ignored && $is_material_worsening ? self::STATUS_NEW : $existing_status;

			if ( $is_ignored && $is_material_worsening ) {
				$data['resolved_at'] = null;
			} elseif ( in_array( $existing_status, self::get_closed_statuses(), true ) ) {
				$data['resolved_at'] = $existing['resolved_at'];
			}

			$result = $wpdb->update(
				$this->table,
				$data,
				array( 'id' => (int) $existing['id'] ),
				$this->get_format_list( $data ),
				array( '%d' )
			);

			if ( false === $result ) {
				return new WP_Error( 'opti_behavior_smart_insights_update_failed', __( 'Unable to update the Smart Insight.', 'opti-behavior' ) );
			}

			self::flush_menu_severity();
			$this->auto_resolve_superseded_insights( (int) $existing['id'], $data );

			return (int) $existing['id'];
		}

		$result = $wpdb->insert( $this->table, $data, $this->get_format_list( $data ) );
		if ( false === $result ) {
			return new WP_Error( 'opti_behavior_smart_insights_insert_failed', __( 'Unable to save the Smart Insight.', 'opti-behavior' ) );
		}

		$insert_id = (int) $wpdb->insert_id;
		self::flush_menu_severity();
		$this->auto_resolve_superseded_insights( $insert_id, $data );

		return $insert_id;
	}

	/**
	 * Count newly detected primary insights the given user has not seen yet.
	 *
	 * "Seen" is defined by the shared notification watermark
	 * (`opti_behavior_si_notifications_last_seen` user meta, a Unix epoch):
	 * an insight is unseen when its row was created after that watermark and
	 * is still in the `new` status. Story children and suppressed rows are
	 * excluded so the count matches the deduplicated card list.
	 *
	 * @since 1.8.6
	 * @param int $last_seen_timestamp Unix epoch of the user's last-seen watermark (0 = never seen).
	 * @return int
	 */
	public function count_unseen_new_insights( $last_seen_timestamp = 0 ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 0;
		}

		$now    = $this->current_mysql_time();
		$where  = array(
			'status = %s',
			'( parent_insight_id IS NULL OR parent_insight_id = 0 )',
			'( suppressed_until IS NULL OR suppressed_until <= %s )',
		);
		$values = array( self::STATUS_NEW, $now );

		if ( $last_seen_timestamp > 0 ) {
			// `created_at` is written with current_time( 'mysql' ) (site-local),
			// so convert the epoch watermark into the same clock before comparing.
			$where[]  = 'created_at > %s';
			$values[] = function_exists( 'wp_date' )
				? wp_date( 'Y-m-d H:i:s', (int) $last_seen_timestamp )
				: gmdate( 'Y-m-d H:i:s', (int) $last_seen_timestamp );
		}

		$sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is internal; placeholders prepared below.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Get one insight by ID with JSON fields decoded.
	 *
	 * @param int $id Insight ID.
	 * @return array|null
	 */
	public function get_insight( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id || ! $this->table_exists() ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->decode_insight_row( $row ) : null;
	}

	/**
	 * Earliest creation time recorded for one group key.
	 *
	 * Rows are keyed by `group_key`, so the oldest row of a group is the first
	 * time the plugin ever detected this problem on this entity - the "first
	 * detected" marker on the daily chart.
	 *
	 * @since 1.4.0
	 *
	 * @param string $group_key Insight group key.
	 * @return string Empty string when unknown.
	 */
	public function get_first_detected_at( $group_key ) {
		global $wpdb;

		$group_key = is_scalar( $group_key ) ? (string) $group_key : '';
		if ( '' === $group_key || ! $this->table_exists() ) {
			return '';
		}

		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT MIN(created_at) FROM {$this->table} WHERE group_key = %s", $group_key )
		);

		return $value ? (string) $value : '';
	}

	/**
	 * Last stored run of the same issue group before a given row.
	 *
	 * Rows are keyed by `group_key`: a generation run either updates the open row
	 * of the current window or stores a new row for a new window, so the newest
	 * other row of the same group is the previous run of the same problem. Used
	 * by the weekly brief to say whether an issue is new, worse or better.
	 *
	 * @since 1.4.0
	 *
	 * @param string $group_key  Insight group key.
	 * @param int    $exclude_id Row to exclude (the current run).
	 * @param string $before_date Optional `date_from` upper bound (exclusive).
	 * @return array|null Decoded row, or null when the group has no earlier run.
	 */
	public function get_previous_run_by_group_key( $group_key, $exclude_id = 0, $before_date = '' ) {
		global $wpdb;

		$group_key = is_scalar( $group_key ) ? (string) $group_key : '';
		if ( '' === $group_key || ! $this->table_exists() ) {
			return null;
		}

		$where  = array( 'group_key = %s', 'id <> %d' );
		$values = array( $group_key, absint( $exclude_id ) );

		$before_date = $this->normalize_date( $before_date );
		if ( '' !== $before_date ) {
			$where[]  = 'date_from < %s';
			$values[] = $before_date;
		}

		$sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY date_to DESC, id DESC LIMIT 1';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $values ), ARRAY_A );

		return $row ? $this->decode_insight_row( $row ) : null;
	}

	/**
	 * Resolved insights carrying a measured outcome verdict.
	 *
	 * Feeds the weekly brief's "Wins this month" block and the CSV export. The
	 * verdict is matched on the encoded JSON so no extra column or index is
	 * needed; the query stays bounded by `resolved_at` and `LIMIT`.
	 *
	 * @since 1.4.0
	 *
	 * @param array $args Query args (`verdict`, `resolved_since`, `limit`).
	 * @return array Decoded rows.
	 */
	public function get_outcomes( $args = array() ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		if ( ! $this->schema_checked ) {
			$this->ensure_optional_schema();
		}

		if ( ! $this->column_exists( 'outcome_json' ) ) {
			return array();
		}

		$defaults = array(
			'verdict'        => '',
			'resolved_since' => '',
			'limit'          => 10,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( 'outcome_json IS NOT NULL' );
		$values = array();

		$verdict = sanitize_key( (string) $args['verdict'] );
		if ( '' !== $verdict ) {
			$where[]  = 'outcome_json LIKE %s';
			$values[] = '%' . $wpdb->esc_like( '"verdict":"' . $verdict . '"' ) . '%';
		}

		$since = $this->normalize_datetime_or_null( $args['resolved_since'] );
		if ( $since ) {
			$where[]  = 'resolved_at >= %s';
			$values[] = $since;
		}

		$values[] = max( 1, min( 100, absint( $args['limit'] ) ) );

		$sql  = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY resolved_at DESC, id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		return $rows ? array_map( array( $this, 'decode_insight_row' ), $rows ) : array();
	}

	/**
	 * Get insights with basic filters.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function get_insights( $args = array() ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		$defaults = array(
			'status'      => '',
			'status__in'  => array(),
			'signal_id'   => '',
			'category'    => '',
			'entity_type' => '',
			'source_plugin' => '',
			'visibility_tier' => '',
			'date_from'   => '',
			'date_to'     => '',
			'date_overlap_from' => '',
			'date_overlap_to' => '',
			'spam_scope'  => null,
			'include_suppressed' => false,
			'limit'       => 50,
			'offset'      => 0,
			'orderby'     => 'updated_at',
			'order'       => 'DESC',
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status__in'] ) && is_array( $args['status__in'] ) ) {
			$statuses = array_values( array_intersect( array_map( 'sanitize_key', $args['status__in'] ), self::get_allowed_statuses() ) );
			if ( $statuses ) {
				$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
				$values  = array_merge( $values, $statuses );
			}
		} elseif ( '' !== $args['status'] ) {
			$status = sanitize_key( $args['status'] );
			if ( in_array( $status, self::get_allowed_statuses(), true ) ) {
				$where[]  = 'status = %s';
				$values[] = $status;
			}
		}

		foreach ( array( 'signal_id', 'category', 'entity_type', 'source_plugin', 'visibility_tier' ) as $field ) {
			if ( '' !== $args[ $field ] ) {
				$where[]  = $field . ' = %s';
				$values[] = sanitize_text_field( $args[ $field ] );
			}
		}

		if ( '' !== $args['date_from'] ) {
			$where[]  = 'date_from >= %s';
			$values[] = $this->normalize_date( $args['date_from'] );
		}

		if ( '' !== $args['date_to'] ) {
			$where[]  = 'date_to <= %s';
			$values[] = $this->normalize_date( $args['date_to'] );
		}

		if ( '' !== $args['date_overlap_from'] ) {
			$where[]  = 'date_to >= %s';
			$values[] = $this->normalize_date( $args['date_overlap_from'] );
		}

		if ( '' !== $args['date_overlap_to'] ) {
			$where[]  = 'date_from <= %s';
			$values[] = $this->normalize_date( $args['date_overlap_to'] );
		}

		$this->append_spam_scope_where( $args['spam_scope'], $where, $values );

		if ( empty( $args['include_suppressed'] ) ) {
			$where[]  = '(suppressed_until IS NULL OR suppressed_until < %s)';
			$values[] = $this->current_mysql_time();
		}

		$orderby = $this->sanitize_orderby( $args['orderby'] );
		$order   = strtoupper( $args['order'] );
		$order   = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';
		$limit   = max( 1, min( 500, absint( $args['limit'] ) ) );
		$offset  = max( 0, absint( $args['offset'] ) );

		$sql      = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$values[] = $limit;
		$values[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}

		return array_map( array( $this, 'decode_insight_row' ), $rows );
	}

	/**
	 * Count insights with the same basic filters used by get_insights().
	 *
	 * This supports AJAX response count contracts without fetching a paginated
	 * slice first. When collapse_groups is true, active rows are counted by
	 * logical issue group while closed/ignored rows are counted individually to
	 * mirror collapse_open_insights_by_group().
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public function count_insights( $args = array() ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 0;
		}

		$defaults = array(
			'status'             => '',
			'status__in'         => array(),
			'signal_id'          => '',
			'category'           => '',
			'entity_type'        => '',
			'source_plugin'      => '',
			'visibility_tier'    => '',
			'date_from'          => '',
			'date_to'            => '',
			'date_overlap_from'  => '',
			'date_overlap_to'    => '',
			'spam_scope'         => null,
			'include_suppressed' => false,
			'collapse_groups'    => false,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status__in'] ) && is_array( $args['status__in'] ) ) {
			$statuses = array_values( array_intersect( array_map( 'sanitize_key', $args['status__in'] ), self::get_allowed_statuses() ) );
			if ( $statuses ) {
				$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
				$values  = array_merge( $values, $statuses );
			}
		} elseif ( '' !== $args['status'] ) {
			$status = sanitize_key( $args['status'] );
			if ( in_array( $status, self::get_allowed_statuses(), true ) ) {
				$where[]  = 'status = %s';
				$values[] = $status;
			}
		}

		foreach ( array( 'signal_id', 'category', 'entity_type', 'source_plugin', 'visibility_tier' ) as $field ) {
			if ( '' !== $args[ $field ] ) {
				$where[]  = $field . ' = %s';
				$values[] = sanitize_text_field( $args[ $field ] );
			}
		}

		if ( '' !== $args['date_from'] ) {
			$where[]  = 'date_from >= %s';
			$values[] = $this->normalize_date( $args['date_from'] );
		}

		if ( '' !== $args['date_to'] ) {
			$where[]  = 'date_to <= %s';
			$values[] = $this->normalize_date( $args['date_to'] );
		}

		if ( '' !== $args['date_overlap_from'] ) {
			$where[]  = 'date_to >= %s';
			$values[] = $this->normalize_date( $args['date_overlap_from'] );
		}

		if ( '' !== $args['date_overlap_to'] ) {
			$where[]  = 'date_from <= %s';
			$values[] = $this->normalize_date( $args['date_overlap_to'] );
		}

		$this->append_spam_scope_where( $args['spam_scope'], $where, $values );

		if ( empty( $args['include_suppressed'] ) ) {
			$where[]  = '(suppressed_until IS NULL OR suppressed_until < %s)';
			$values[] = $this->current_mysql_time();
		}

		$count_expression = 'COUNT(*)';
		if ( ! empty( $args['collapse_groups'] ) ) {
			$active_statuses   = array( self::STATUS_NEW, self::STATUS_VIEWED, self::STATUS_IN_PROGRESS );
			$status_list       = "'" . implode( "','", array_map( 'esc_sql', $active_statuses ) ) . "'";
			$count_expression = "COUNT(DISTINCT CASE WHEN status IN ({$status_list}) THEN COALESCE(NULLIF(group_key,''), CONCAT(signal_id,'|',entity_type,'|',entity_id)) ELSE CONCAT('row:', id) END)";
		}

		$sql = "SELECT {$count_expression} FROM {$this->table} WHERE " . implode( ' AND ', $where );
		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, $values );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Add a Smart Insights spam-scope group-key predicate.
	 *
	 * @param mixed $spam_scope Requested scope; null means no scope filter.
	 * @param array $where      SQL where fragments.
	 * @param array $values     Prepared values.
	 * @return void
	 */
	private function append_spam_scope_where( $spam_scope, &$where, &$values ) {
		global $wpdb;

		if ( null === $spam_scope || '' === $spam_scope ) {
			return;
		}

		$scope_key = is_bool( $spam_scope ) ? ( $spam_scope ? 'exclude_spam_1' : 'exclude_spam_0' ) : sanitize_key( (string) $spam_scope );
		if ( ! in_array( $scope_key, array( 'exclude_spam_1', 'exclude_spam_0', 'default' ), true ) ) {
			return;
		}

		$where[]  = '(group_key NOT LIKE %s OR group_key LIKE %s)';
		$values[] = '%' . $wpdb->esc_like( '|si_spam_scope:' ) . '%';
		$values[] = '%' . $wpdb->esc_like( '|si_spam_scope:' . $scope_key ) . '%';
	}

	/**
	 * Get top dashboard insights, sorted by decoded priority score.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public function get_top_insights( $args = array() ) {
		$defaults = array(
			'limit'           => 5,
			'include_ignored' => false,
			'include_closed'  => false,
		);
		$args     = wp_parse_args( $args, $defaults );

		if ( empty( $args['include_closed'] ) ) {
			$args['status__in'] = empty( $args['include_ignored'] )
				? array( self::STATUS_NEW, self::STATUS_VIEWED, self::STATUS_IN_PROGRESS )
				: self::get_open_statuses();
		}

		$fetch_args          = $args;
		$fetch_args['limit'] = max( 25, absint( $args['limit'] ) * 5 );
		$insights            = $this->get_insights( $fetch_args );

		usort(
			$insights,
			function( $a, $b ) {
				// Largest leak first: an insight that costs more actual visitors
				// outranks a higher relative percentage on a small population.
				// Rows without a stored impact block (legacy rows, signals whose
				// affected population is not measurable) stay on the original
				// priority ordering, so nothing regresses for existing installs.
				if ( class_exists( 'Opti_Behavior_Smart_Insights_Impact_Calculator' ) ) {
					$impact_delta = Opti_Behavior_Smart_Insights_Impact_Calculator::compare_by_impact( $a, $b );
					if ( 0 !== $impact_delta ) {
						return $impact_delta;
					}
				}

				$a_priority = isset( $a['scores']['priority_score'] ) ? (float) $a['scores']['priority_score'] : 0;
				$b_priority = isset( $b['scores']['priority_score'] ) ? (float) $b['scores']['priority_score'] : 0;

				if ( $a_priority === $b_priority ) {
					return strcmp( $b['updated_at'], $a['updated_at'] );
				}

				return ( $a_priority < $b_priority ) ? 1 : -1;
			}
		);

		return array_slice( $insights, 0, max( 1, absint( $args['limit'] ) ) );
	}

	/**
	 * Collapse duplicate open insights to the latest row for each logical issue.
	 *
	 * Existing installs can contain multiple active snapshots for the same
	 * signal/entity across overlapping date windows. The UI should show one
	 * trusted alert and keep recurrence/history on the surviving payload.
	 *
	 * @param array $insights Decoded insight rows.
	 * @return array
	 */
	public function collapse_open_insights_by_group( $insights ) {
		if ( ! is_array( $insights ) || empty( $insights ) ) {
			return array();
		}

		$groups = array();
		$output = array();

		foreach ( $insights as $insight ) {
			if ( ! is_array( $insight ) ) {
				continue;
			}

			$status = isset( $insight['status'] ) ? sanitize_key( $insight['status'] ) : '';
			if ( ! in_array( $status, array( self::STATUS_NEW, self::STATUS_VIEWED, self::STATUS_IN_PROGRESS ), true ) ) {
				$output[] = $insight;
				continue;
			}

			$key = ! empty( $insight['group_key'] ) ? (string) $insight['group_key'] : $this->build_default_group_key( $insight );
			if ( '' === $key ) {
				$key = md5( wp_json_encode( array( $insight['signal_id'] ?? '', $insight['entity_type'] ?? '', $insight['entity_id'] ?? '' ) ) );
			}

			if ( empty( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'latest'  => $insight,
					'history' => array( $insight ),
				);
				continue;
			}

			$groups[ $key ]['history'][] = $insight;
			if ( $this->compare_insight_recency( $insight, $groups[ $key ]['latest'] ) > 0 ) {
				$groups[ $key ]['latest'] = $insight;
			}
		}

		foreach ( $groups as $group ) {
			$latest  = $group['latest'];
			$history = $group['history'];

			if ( count( $history ) > 1 ) {
				usort(
					$history,
					function( $a, $b ) {
						return $this->compare_insight_recency( $b, $a );
					}
				);

				// The stored count is the window-based one; the in-set history is
				// only the rows this query happened to return. Taking the larger of
				// the two keeps the collapsed row from erasing measured recurrence.
				$stored_recurrence = isset( $latest['detection']['recurrence_count'] ) ? (int) $latest['detection']['recurrence_count'] : 0;
				$collapsed_count   = max( count( $history ), $stored_recurrence );

				$latest['recurrence_count'] = $collapsed_count;
				$latest['superseded_ids']   = array_values(
					array_filter(
						array_map(
							function( $row ) use ( $latest ) {
								$id        = isset( $row['id'] ) ? (int) $row['id'] : 0;
								$latest_id = isset( $latest['id'] ) ? (int) $latest['id'] : 0;
								return $id && $id !== $latest_id ? $id : 0;
							},
							$history
						)
					)
				);
				$latest['first_seen_at']    = $this->oldest_timestamp_from_history( $history );
				$latest['detection']        = isset( $latest['detection'] ) && is_array( $latest['detection'] ) ? $latest['detection'] : array();
				$latest['detection']['recurrence_count'] = $collapsed_count;
			}

			$output[] = $latest;
		}

		usort(
			$output,
			function( $a, $b ) {
				return $this->compare_insight_recency( $b, $a );
			}
		);

		return array_values( $output );
	}

	/**
	 * Drop story children whose primary is present in the same set.
	 *
	 * A story is one finding: the primary plus the child signals that explain it.
	 * Counting the children as separate stories makes a briefing repeat the same
	 * issue N times with the identical `users_lost`. Children whose primary is not
	 * in the set (resolved, ignored, or filtered out) are kept, because they are
	 * the only remaining representation of that issue.
	 *
	 * @param array $insights Insight rows.
	 * @return array
	 */
	public function collapse_story_children( $insights ) {
		if ( ! is_array( $insights ) || count( $insights ) < 2 ) {
			return is_array( $insights ) ? array_values( $insights ) : array();
		}

		$present = array();
		foreach ( $insights as $insight ) {
			$id = isset( $insight['id'] ) ? (int) $insight['id'] : 0;
			if ( $id > 0 ) {
				$present[ $id ] = true;
			}
		}

		$output = array();
		foreach ( $insights as $insight ) {
			$parent_id = isset( $insight['parent_insight_id'] ) ? (int) $insight['parent_insight_id'] : 0;
			if ( $parent_id > 0 && isset( $present[ $parent_id ] ) ) {
				continue;
			}
			$output[] = $insight;
		}

		return array_values( $output );
	}

	/**
	 * Resolve the recurrence count and audit trail for an upserted insight.
	 *
	 * Recurrence means "seen again in a later, non-overlapping analysis window".
	 * A sliding window that merely shifted by a day describes the same occurrence,
	 * so it must not increment anything.
	 *
	 * @param array  $existing_detection Stored detection payload.
	 * @param string $existing_from      Stored window start.
	 * @param string $existing_to        Stored window end.
	 * @param string $new_from           Incoming window start.
	 * @param string $new_to             Incoming window end.
	 * @return array `array( count, windows )`.
	 */
	private function resolve_recurrence( $existing_detection, $existing_from, $existing_to, $new_from, $new_to ) {
		$count = isset( $existing_detection['recurrence_count'] ) ? max( 1, (int) $existing_detection['recurrence_count'] ) : 1;

		$windows = isset( $existing_detection['recurrence_windows'] ) && is_array( $existing_detection['recurrence_windows'] )
			? array_values( $existing_detection['recurrence_windows'] )
			: array();

		if ( empty( $windows ) && '' !== $existing_from && '' !== $existing_to ) {
			$windows[] = array( $existing_from, $existing_to );
		}

		$last = ! empty( $windows ) ? end( $windows ) : array( $existing_from, $existing_to );
		reset( $windows );
		$last_to = isset( $last[1] ) ? (string) $last[1] : (string) $existing_to;

		$is_new_window = '' !== $new_from && '' !== $last_to && strtotime( $new_from ) > strtotime( $last_to );
		if ( $is_new_window ) {
			$count++;
			$windows[] = array( $new_from, $new_to );
		} elseif ( empty( $windows ) && '' !== $new_from && '' !== $new_to ) {
			$windows[] = array( $new_from, $new_to );
		}

		if ( count( $windows ) > self::MAX_RECURRENCE_WINDOWS ) {
			$windows = array_slice( $windows, -self::MAX_RECURRENCE_WINDOWS );
		}

		return array(
			'count'   => $count,
			'windows' => array_values( $windows ),
		);
	}

	/**
	 * Auto-resolve active insights that were not re-detected in this generation.
	 *
	 * @param string $date_from         Current range start.
	 * @param string $date_to           Current range end.
	 * @param array  $active_group_keys Group keys detected in this run.
	 * @param array  $args              Optional entity/signal filters.
	 * @return int|WP_Error
	 */
	public function auto_resolve_unseen_insights( $date_from, $date_to, $active_group_keys = array(), $args = array() ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return $this->missing_table_error();
		}

		$date_from = $this->normalize_date( $date_from );
		$date_to   = $this->normalize_date( $date_to );
		$statuses  = array( self::STATUS_NEW, self::STATUS_VIEWED, self::STATUS_IN_PROGRESS );
		$where     = array(
			'date_from <= %s',
			'date_to >= %s',
			'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')',
		);
		$values    = array_merge( array( $date_to, $date_from ), $statuses );

		$active_group_keys = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $active_group_keys ) ) ) );
		if ( ! empty( $active_group_keys ) ) {
			$where[] = 'group_key NOT IN (' . implode( ',', array_fill( 0, count( $active_group_keys ), '%s' ) ) . ')';
			$values  = array_merge( $values, $active_group_keys );
		}

		if ( ! empty( $args['signal_ids'] ) && is_array( $args['signal_ids'] ) ) {
			$signal_ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', $args['signal_ids'] ) ) ) );
			if ( $signal_ids ) {
				$where[] = 'signal_id IN (' . implode( ',', array_fill( 0, count( $signal_ids ), '%s' ) ) . ')';
				$values  = array_merge( $values, $signal_ids );
			}
		}

		if ( ! empty( $args['entity_types'] ) && is_array( $args['entity_types'] ) ) {
			$entity_types = array_values( array_unique( array_filter( array_map( 'sanitize_key', $args['entity_types'] ) ) ) );
			if ( $entity_types ) {
				$where[] = 'entity_type IN (' . implode( ',', array_fill( 0, count( $entity_types ), '%s' ) ) . ')';
				$values  = array_merge( $values, $entity_types );
			}
		}

		$this->append_spam_scope_where( $args['spam_scope'] ?? null, $where, $values );

		$now      = $this->current_mysql_time();
		$sql      = "UPDATE {$this->table} SET status = %s, updated_at = %s, resolved_at = %s WHERE " . implode( ' AND ', $where );
		$prepared = $wpdb->prepare( $sql, array_merge( array( self::STATUS_AUTO_RESOLVED, $now, $now ), $values ) );
		$result   = $wpdb->query( $prepared );

		if ( false === $result ) {
			return new WP_Error( 'opti_behavior_smart_insights_auto_resolve_unseen_failed', __( 'Unable to auto-resolve Smart Insights that no longer trigger.', 'opti-behavior' ) );
		}

		if ( $result > 0 ) {
			self::flush_menu_severity();
		}

		return (int) $result;
	}

	/**
	 * Whether a plugin table exists (memoized per request).
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public function table_exists_named( $table ) {
		global $wpdb;
		static $seen = array();
		if ( ! isset( $seen[ $table ] ) ) {
			$seen[ $table ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		}

		return $seen[ $table ];
	}

	/**
	 * Close the open cards of candidates this run merged into a data twin
	 * (status `merged_duplicate`, never counted as resolved). Cards the site
	 * owner ignored stay as they are.
	 *
	 * @param array $group_keys Group keys of the merged candidates.
	 * @return int|WP_Error Rows closed.
	 */
	public function close_merged_duplicates( $group_keys ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return $this->missing_table_error();
		}

		$group_keys = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $group_keys ) ) ) );
		if ( empty( $group_keys ) ) {
			return 0;
		}

		$statuses = array( self::STATUS_NEW, self::STATUS_VIEWED, self::STATUS_IN_PROGRESS );
		$now      = $this->current_mysql_time();
		$sql      = "UPDATE {$this->table} SET status = %s, updated_at = %s, resolved_at = %s WHERE group_key IN (" . implode( ',', array_fill( 0, count( $group_keys ), '%s' ) ) . ') AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
		$result   = $wpdb->query( $wpdb->prepare( $sql, array_merge( array( self::STATUS_MERGED_DUPLICATE, $now, $now ), $group_keys, $statuses ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.

		if ( false === $result ) {
			return new WP_Error( 'opti_behavior_smart_insights_merge_failed', __( 'Unable to close Smart Insights merged into a twin card.', 'opti-behavior' ) );
		}

		if ( $result > 0 ) {
			self::flush_menu_severity();
		}

		return (int) $result;
	}

	/**
	 * Close the open cards a changed rule no longer confirms (status
	 * `rule_updated`, never counted as resolved): cards of the evaluated
	 * signals, stored under an older `rule_version`, overlapping the run's
	 * range, not re-detected by this run, in the run's spam scope. Cards the
	 * site owner ignored stay as they are. Runs before
	 * auto_resolve_unseen_insights(), so an upgrade never turns the old
	 * rule's false positives into "resolved" wins.
	 *
	 * @param string $date_from         Range start.
	 * @param string $date_to           Range end.
	 * @param array  $rule_versions     signal_id => current rule_version.
	 * @param array  $active_group_keys Group keys this run stored.
	 * @param array  $args              Optional: spam_scope.
	 * @return int|WP_Error Rows closed.
	 */
	public function close_outdated_rule_insights( $date_from, $date_to, $rule_versions, $active_group_keys = array(), $args = array() ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return $this->missing_table_error();
		}

		$pairs  = array();
		$values = array();
		foreach ( (array) $rule_versions as $signal_id => $version ) {
			$signal_id = sanitize_key( (string) $signal_id );
			$version   = sanitize_text_field( (string) $version );
			if ( '' === $signal_id || '' === $version ) {
				continue;
			}
			// A card stored without a version (very old rows) keeps the usual path.
			$pairs[]  = "(signal_id = %s AND rule_version <> %s AND rule_version <> '')";
			$values[] = $signal_id;
			$values[] = $version;
		}
		if ( empty( $pairs ) ) {
			return 0;
		}

		$statuses = array( self::STATUS_NEW, self::STATUS_VIEWED, self::STATUS_IN_PROGRESS );
		$where    = array(
			'(' . implode( ' OR ', $pairs ) . ')',
			'date_from <= %s',
			'date_to >= %s',
			'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')',
		);
		$values   = array_merge( $values, array( $this->normalize_date( $date_to ), $this->normalize_date( $date_from ) ), $statuses );

		$active_group_keys = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $active_group_keys ) ) ) );
		if ( ! empty( $active_group_keys ) ) {
			$where[] = 'group_key NOT IN (' . implode( ',', array_fill( 0, count( $active_group_keys ), '%s' ) ) . ')';
			$values  = array_merge( $values, $active_group_keys );
		}

		$this->append_spam_scope_where( isset( $args['spam_scope'] ) ? $args['spam_scope'] : null, $where, $values );

		$now      = $this->current_mysql_time();
		$sql      = "UPDATE {$this->table} SET status = %s, updated_at = %s, resolved_at = %s WHERE " . implode( ' AND ', $where );
		$result   = $wpdb->query( $wpdb->prepare( $sql, array_merge( array( self::STATUS_RULE_UPDATED, $now, $now ), $values ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.

		if ( false === $result ) {
			return new WP_Error( 'opti_behavior_smart_insights_rule_updated_failed', __( 'Unable to close Smart Insights raised by an older rule.', 'opti-behavior' ) );
		}

		if ( $result > 0 ) {
			self::flush_menu_severity();
		}

		return (int) $result;
	}

	/**
	 * Get recent related detections for historical monitoring and persistence scoring.
	 *
	 * This returns aggregate insight rows only. It does not read raw visitor,
	 * recording, or event payloads.
	 *
	 * @param array $insight Insight payload.
	 * @param array $args    Optional args: before_date, limit.
	 * @return array
	 */
	public function get_recent_related_insights( $insight, $args = array() ) {
		global $wpdb;

		if ( ! is_array( $insight ) || ! $this->table_exists() ) {
			return array();
		}

		$defaults = array(
			'before_date' => isset( $insight['date_from'] ) ? $insight['date_from'] : '',
			'limit'       => 6,
		);
		$args     = wp_parse_args( $args, $defaults );
		$limit    = max( 1, min( 24, absint( $args['limit'] ) ) );
		$before   = $this->normalize_date( $args['before_date'] );
		$group_key = isset( $insight['group_key'] ) ? sanitize_text_field( $insight['group_key'] ) : $this->build_default_group_key( $insight );

		$where  = array( 'date_to < %s' );
		$values = array( $before );

		if ( '' !== $group_key ) {
			if ( false !== strpos( $group_key, '|si_spam_scope:' ) ) {
				$where[]  = 'group_key = %s';
				$values[] = $group_key;
			} else {
				$where[]  = '(group_key = %s OR (signal_id = %s AND entity_type = %s AND entity_id = %s))';
				$values[] = $group_key;
				$values[] = isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : '';
				$values[] = isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '';
				$values[] = isset( $insight['entity_id'] ) ? sanitize_textarea_field( $insight['entity_id'] ) : '';
			}
		} else {
			$where[]  = 'signal_id = %s';
			$where[]  = 'entity_type = %s';
			$where[]  = 'entity_id = %s';
			$values[] = isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : '';
			$values[] = isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '';
			$values[] = isset( $insight['entity_id'] ) ? sanitize_textarea_field( $insight['entity_id'] ) : '';
		}

		$values[] = $limit;
		$sql      = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY date_to DESC, updated_at DESC LIMIT %d';
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		return $rows ? array_map( array( $this, 'decode_insight_row' ), $rows ) : array();
	}

	/**
	 * Update an insight lifecycle status.
	 *
	 * @param int    $id     Insight ID.
	 * @param string $status New status.
	 * @return bool|WP_Error
	 */
	public function update_status( $id, $status ) {
		global $wpdb;

		$id     = absint( $id );
		$status = sanitize_key( $status );

		if ( ! $id ) {
			return new WP_Error( 'opti_behavior_smart_insights_invalid_id', __( 'Invalid Smart Insight ID.', 'opti-behavior' ) );
		}

		if ( ! in_array( $status, self::get_allowed_statuses(), true ) ) {
			return new WP_Error( 'opti_behavior_smart_insights_invalid_status', __( 'Invalid Smart Insight status.', 'opti-behavior' ) );
		}

		if ( ! $this->table_exists() ) {
			return $this->missing_table_error();
		}

		$now  = $this->current_mysql_time();
		$data = array(
			'status'     => $status,
			'updated_at' => $now,
		);

		$formats = array( '%s', '%s' );

		if ( in_array( $status, self::get_closed_statuses(), true ) ) {
			$data['resolved_at'] = $now;
			$formats[]           = '%s';
		} else {
			$data['resolved_at'] = null;
			$formats[]           = null;
		}

		$result = $wpdb->update( $this->table, $data, array( 'id' => $id ), $formats, array( '%d' ) );

		if ( false === $result ) {
			return new WP_Error( 'opti_behavior_smart_insights_status_failed', __( 'Unable to update Smart Insight status.', 'opti-behavior' ) );
		}

		// Outcome loop: freeze the "before" measurement the moment a human says
		// the problem is fixed. Without this snapshot the later cron check has
		// nothing to compare against, because the insight row keeps being
		// refreshed by generation runs.
		if ( self::STATUS_RESOLVED === $status ) {
			$this->maybe_snapshot_outcome( $id );
		}

		self::flush_menu_severity();

		return true;
	}

	/**
	 * Store the "before" half of the outcome measurement for a resolved insight.
	 *
	 * Never fatal: a missing evaluator, a missing column, or an unusable signal
	 * leaves the row exactly as it was and the status change still succeeds.
	 *
	 * @since 1.4.0
	 *
	 * @param int $id Insight ID.
	 * @return bool True when a snapshot was written.
	 */
	private function maybe_snapshot_outcome( $id ) {
		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Outcome_Evaluator' ) ) {
			return false;
		}

		try {
			$insight = $this->get_insight( $id );
			if ( ! is_array( $insight ) ) {
				return false;
			}

			$existing = isset( $insight['outcome'] ) && is_array( $insight['outcome'] ) ? $insight['outcome'] : array();
			if ( ! empty( $existing['verdict'] ) || isset( $existing['after_value'] ) || ! empty( $existing['metric_key'] ) ) {
				// A snapshot already exists. Re-resolving must neither erase a
				// stored verdict nor re-freeze `before_value` from the CURRENT
				// metrics — that would silently rewrite the baseline the later
				// cron check compares against (QA-B-SI-003).
				return false;
			}

			$snapshot = Opti_Behavior_Smart_Insights_Outcome_Evaluator::build_snapshot( $insight );
			if ( empty( $snapshot ) ) {
				return false;
			}

			return true === $this->update_outcome( $id, $snapshot );
		} catch ( Exception $e ) {
			return false;
		} catch ( Error $e ) {
			return false;
		}
	}

	/**
	 * Write the outcome block of one insight row in place.
	 *
	 * Mirrors update_story_block(): one whitelisted column, no re-run of the
	 * upsert path, every other field untouched.
	 *
	 * @since 1.4.0
	 *
	 * @param int   $id      Insight ID.
	 * @param mixed $outcome Outcome payload. Empty/null clears the column.
	 * @return bool|WP_Error
	 */
	public function update_outcome( $id, $outcome ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return new WP_Error( 'opti_behavior_smart_insights_invalid_id', __( 'Invalid Smart Insight ID.', 'opti-behavior' ) );
		}

		if ( ! $this->table_exists() ) {
			return $this->missing_table_error();
		}

		if ( ! $this->schema_checked ) {
			$this->ensure_optional_schema();
		}

		if ( ! $this->column_exists( 'outcome_json' ) ) {
			return new WP_Error( 'opti_behavior_smart_insights_outcome_column_missing', __( 'The Smart Insights outcome column is not available yet.', 'opti-behavior' ) );
		}

		$result = $wpdb->update(
			$this->table,
			array(
				'outcome_json' => $this->encode_optional_json_field( $outcome ),
				'updated_at'   => $this->current_mysql_time(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'opti_behavior_smart_insights_outcome_failed', __( 'Unable to store the Smart Insight outcome.', 'opti-behavior' ) );
		}

		return true;
	}

	/**
	 * Resolved insights that are ready for their after-the-fix measurement.
	 *
	 * "Ready" means resolved long enough ago that a full post-fix window exists,
	 * but not so long ago that the check would keep re-running forever. Rows that
	 * already carry a verdict (including `inconclusive`) are skipped, so one row
	 * is measured at most once.
	 *
	 * @since 1.4.0
	 *
	 * @param int $min_days Minimum age in days of `resolved_at`.
	 * @param int $max_days Maximum age in days of `resolved_at`.
	 * @param int $limit    Maximum rows returned.
	 * @return array Decoded rows.
	 */
	public function get_outcome_check_candidates( $min_days = 14, $max_days = 20, $limit = 25 ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		if ( ! $this->schema_checked ) {
			$this->ensure_optional_schema();
		}

		if ( ! $this->column_exists( 'outcome_json' ) ) {
			return array();
		}

		$min_days = max( 0, absint( $min_days ) );
		$max_days = max( $min_days, absint( $max_days ) );
		$limit    = max( 1, min( 100, absint( $limit ) ) );

		$now     = $this->current_mysql_time();
		$newest  = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' -' . $min_days . ' days' ) );
		$oldest  = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' -' . $max_days . ' days' ) );
		// `_` is the single-character LIKE wildcard: the pattern matches a verdict
		// with at least one character, so a row carrying an empty verdict is still
		// treated as unmeasured instead of silently skipped forever.
		$pending = '%' . $wpdb->esc_like( '"verdict":"' ) . '_%';

		$sql = "SELECT * FROM {$this->table}
			WHERE status = %s
			AND resolved_at IS NOT NULL
			AND resolved_at <= %s
			AND resolved_at >= %s
			AND ( outcome_json IS NULL OR outcome_json NOT LIKE %s )
			ORDER BY resolved_at ASC
			LIMIT %d";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, self::STATUS_RESOLVED, $newest, $oldest, $pending, $limit ),
			ARRAY_A
		);

		return $rows ? array_map( array( $this, 'decode_insight_row' ), $rows ) : array();
	}

	/**
	 * Write a single story block on an existing insight row.
	 *
	 * The generator owns the story blocks it computes during a detection pass,
	 * but two of them (`hypothesis`, `experiment`) are also written outside a
	 * generation run: the hypothesis when a viewer opens the story, the
	 * experiment link the moment an A/B test is created from the insight. Those
	 * writers must not re-run the whole upsert path, so this method updates one
	 * whitelisted column in place and leaves every other field untouched.
	 *
	 * @since 1.3.9
	 *
	 * @param int    $id    Insight ID.
	 * @param string $block Story block key (`hypothesis`, `experiment`, ...).
	 * @param mixed  $value Block payload. Empty/null clears the column.
	 * @return bool|WP_Error True on success.
	 */
	public function update_story_block( $id, $block, $value ) {
		global $wpdb;

		$id    = absint( $id );
		$block = sanitize_key( $block );

		if ( ! $id ) {
			return new WP_Error( 'opti_behavior_smart_insights_invalid_id', __( 'Invalid Smart Insight ID.', 'opti-behavior' ) );
		}

		$column = array_search( $block, self::get_story_block_columns(), true );
		if ( false === $column ) {
			return new WP_Error( 'opti_behavior_smart_insights_invalid_story_block', __( 'Unknown Smart Insight story block.', 'opti-behavior' ) );
		}

		if ( ! $this->table_exists() ) {
			return $this->missing_table_error();
		}

		if ( ! $this->schema_checked ) {
			$this->ensure_optional_schema();
		}

		$result = $wpdb->update(
			$this->table,
			array(
				$column      => $this->encode_optional_json_field( $value ),
				'updated_at' => $this->current_mysql_time(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return new WP_Error( 'opti_behavior_smart_insights_story_block_failed', __( 'Unable to update the Smart Insight story block.', 'opti-behavior' ) );
		}

		return true;
	}

	/**
	 * Auto-resolve open insights that have not been detected recently.
	 *
	 * Generators can call this after a successful run once they know a previous
	 * open insight did not re-trigger across the configured consecutive periods.
	 * Manually ignored insights are preserved.
	 *
	 * Hardening (Bug 5): rows written or refreshed by the CURRENT run are excluded
	 * via `active_group_keys`, mirroring the protection auto_resolve_unseen_insights()
	 * already had. Without it this method resolves purely on `last_seen_at < cutoff`,
	 * and `last_seen_at` is not always "now" — the funnel aggregator sets it from
	 * MAX(funnel_tracking.last_activity), i.e. real historical data. A run could
	 * therefore write an insight and immediately auto-resolve it in the same pass,
	 * making it vanish from a UI that lists open statuses only. That path was not
	 * observed on the current corpus (cutoff sat a month before the funnel
	 * last_activity values), so this is defence-in-depth rather than an observed
	 * defect — but it is the one mechanism that could produce the reported symptom.
	 *
	 * @param string $last_seen_before MySQL datetime cutoff.
	 * @param array  $args Optional signal/entity filters plus active_group_keys.
	 * @return int|WP_Error Number of rows updated.
	 */
	public function auto_resolve_stale_insights( $last_seen_before, $args = array() ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return $this->missing_table_error();
		}

		$defaults = array(
			'signal_id'         => '',
			'entity_type'       => '',
			'spam_scope'        => null,
			'active_group_keys' => array(),
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( 'last_seen_at < %s', "status IN ('new','viewed','in_progress')" );
		$values = array( $last_seen_before );

		$active_group_keys = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_text_field', (array) $args['active_group_keys'] )
				)
			)
		);
		if ( ! empty( $active_group_keys ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $active_group_keys ), '%s' ) );
			$where[]      = "( group_key IS NULL OR group_key = '' OR group_key NOT IN ( {$placeholders} ) )";
			$values       = array_merge( $values, $active_group_keys );
		}

		if ( '' !== $args['signal_id'] ) {
			$where[]  = 'signal_id = %s';
			$values[] = sanitize_text_field( $args['signal_id'] );
		}

		if ( '' !== $args['entity_type'] ) {
			$where[]  = 'entity_type = %s';
			$values[] = sanitize_text_field( $args['entity_type'] );
		}

		$this->append_spam_scope_where( $args['spam_scope'], $where, $values );

		$now      = $this->current_mysql_time();
		$sql      = "UPDATE {$this->table} SET status = %s, updated_at = %s, resolved_at = %s WHERE " . implode( ' AND ', $where );
		$prepared = $wpdb->prepare( $sql, array_merge( array( self::STATUS_AUTO_RESOLVED, $now, $now ), $values ) );
		$result   = $wpdb->query( $prepared );

		if ( false === $result ) {
			return new WP_Error( 'opti_behavior_smart_insights_auto_resolve_failed', __( 'Unable to auto-resolve stale Smart Insights.', 'opti-behavior' ) );
		}

		if ( $result > 0 ) {
			self::flush_menu_severity();
		}

		return (int) $result;
	}

	/**
	 * Auto-resolve older active snapshots for the same logical issue.
	 *
	 * @param int   $current_id Current insight ID to keep active.
	 * @param array $data       Prepared insight data for the current row.
	 * @return int|WP_Error
	 */
	public function auto_resolve_superseded_insights( $current_id, $data ) {
		global $wpdb;

		$current_id = absint( $current_id );
		if ( ! $current_id || ! is_array( $data ) || ! $this->table_exists() ) {
			return 0;
		}

		$group_key   = isset( $data['group_key'] ) ? sanitize_text_field( $data['group_key'] ) : '';
		$signal_id   = isset( $data['signal_id'] ) ? sanitize_key( $data['signal_id'] ) : '';
		$entity_type = isset( $data['entity_type'] ) ? sanitize_key( $data['entity_type'] ) : '';
		$entity_id   = isset( $data['entity_id'] ) ? sanitize_textarea_field( $data['entity_id'] ) : '';
		$date_from   = isset( $data['date_from'] ) ? $this->normalize_date( $data['date_from'] ) : '';
		$date_to     = isset( $data['date_to'] ) ? $this->normalize_date( $data['date_to'] ) : '';

		if ( '' === $date_from || '' === $date_to || ( '' === $group_key && ( '' === $signal_id || '' === $entity_type || '' === $entity_id ) ) ) {
			return 0;
		}

		$statuses = array( self::STATUS_NEW, self::STATUS_VIEWED, self::STATUS_IN_PROGRESS );
		$where    = array(
			'id <> %d',
			'date_from <= %s',
			'date_to >= %s',
			'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')',
		);
		$values   = array_merge( array( $current_id, $date_to, $date_from ), $statuses );

		if ( '' !== $group_key ) {
			$where[]  = 'group_key = %s';
			$values[] = $group_key;
		} else {
			$where[]  = 'signal_id = %s';
			$where[]  = 'entity_type = %s';
			$where[]  = 'entity_id = %s';
			$values[] = $signal_id;
			$values[] = $entity_type;
			$values[] = $entity_id;
		}

		$now      = $this->current_mysql_time();
		$sql      = "UPDATE {$this->table} SET status = %s, updated_at = %s, resolved_at = %s WHERE " . implode( ' AND ', $where );
		$prepared = $wpdb->prepare( $sql, array_merge( array( self::STATUS_AUTO_RESOLVED, $now, $now ), $values ) );
		$result   = $wpdb->query( $prepared );

		if ( false === $result ) {
			return new WP_Error( 'opti_behavior_smart_insights_supersede_failed', __( 'Unable to supersede older Smart Insight snapshots.', 'opti-behavior' ) );
		}

		if ( $result > 0 ) {
			self::flush_menu_severity();
		}

		return (int) $result;
	}

	/**
	 * Delete all Smart Insights.
	 *
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public function delete_all() {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 0;
		}

		self::flush_menu_severity();

		return $wpdb->query( "TRUNCATE TABLE {$this->table}" );
	}

	/**
	 * Drop the stored admin-menu severity (once per request).
	 *
	 * Called by every write that can open, close or re-rank an insight. The
	 * next admin screen recomputes it with one query; the public site never
	 * reads it.
	 *
	 * @return void
	 */
	public static function flush_menu_severity() {
		if ( self::$menu_severity_flushed ) {
			return;
		}
		self::$menu_severity_flushed = true;
		delete_option( self::MENU_SEVERITY_OPTION );
	}

	/**
	 * Colour of the Smart Insights lightbulb in the admin menu.
	 *
	 * red = at least one open Critical insight, yellow = at least one open High
	 * or Medium, green = nothing open (or Low only). Open = new / viewed /
	 * in progress, top-level, not suppressed, default (spam excluded) scope:
	 * the insight list's "Active" definition. The priority key is the final
	 * label the generator stores in scores_json (`priority_label_key`).
	 *
	 * @return array{level:string,critical:int,warning:int,computed_at:int}
	 */
	public function get_menu_severity() {
		$stored = get_option( self::MENU_SEVERITY_OPTION, null );
		if ( is_array( $stored ) && isset( $stored['level'], $stored['computed_at'] )
			&& ( time() - (int) $stored['computed_at'] ) < self::MENU_SEVERITY_TTL ) {
			return $stored;
		}

		$result = array(
			'level'       => 'green',
			'critical'    => 0,
			'warning'     => 0,
			'computed_at' => time(),
		);

		if ( $this->table_exists() ) {
			global $wpdb;

			$statuses = array( self::STATUS_NEW, self::STATUS_VIEWED, self::STATUS_IN_PROGRESS );
			$where    = array(
				'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')',
				'( parent_insight_id IS NULL OR parent_insight_id = 0 )',
				'( suppressed_until IS NULL OR suppressed_until <= %s )',
			);
			$values   = array_merge( $statuses, array( $this->current_mysql_time() ) );
			$this->append_spam_scope_where( true, $where, $values );

			$like = function ( $key ) use ( $wpdb ) {
				return '%' . $wpdb->esc_like( '"priority_label_key":"' . $key . '"' ) . '%';
			};
			$sql  = "SELECT SUM( scores_json LIKE %s ) AS critical, SUM( scores_json LIKE %s OR scores_json LIKE %s ) AS warning FROM {$this->table} WHERE " . implode( ' AND ', $where );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name is internal; placeholders prepared here.
			$row = $wpdb->get_row( $wpdb->prepare( $sql, array_merge( array( $like( 'critical' ), $like( 'high' ), $like( 'medium' ) ), $values ) ), ARRAY_A );

			$result['critical'] = isset( $row['critical'] ) ? (int) $row['critical'] : 0;
			$result['warning']  = isset( $row['warning'] ) ? (int) $row['warning'] : 0;
			if ( $result['critical'] > 0 ) {
				$result['level'] = 'red';
			} elseif ( $result['warning'] > 0 ) {
				$result['level'] = 'yellow';
			}
		}

		update_option( self::MENU_SEVERITY_OPTION, $result, false );
		self::$menu_severity_flushed = false;

		return $result;
	}

	/**
	 * Find an existing open insight for deduplication.
	 *
	 * @param string $signal_id   Signal ID.
	 * @param string $entity_type Entity type.
	 * @param string $entity_id   Entity ID.
	 * @param string $date_from   Start date.
	 * @param string $date_to     End date.
	 * @param string $group_key   Logical group key.
	 * @return array|null
	 */
	private function find_existing_open_insight( $signal_id, $entity_type, $entity_id, $date_from, $date_to, $group_key = '' ) {
		global $wpdb;

		$open_statuses = self::get_open_statuses();
		$group_key     = sanitize_text_field( $group_key );
		$signal_id     = sanitize_key( $signal_id );
		$entity_type   = sanitize_key( $entity_type );
		$entity_id     = sanitize_textarea_field( $entity_id );
		$date_from     = $this->normalize_date( $date_from );
		$date_to       = $this->normalize_date( $date_to );

		$where  = array(
			'status IN (' . implode( ',', array_fill( 0, count( $open_statuses ), '%s' ) ) . ')',
			'date_from <= %s',
			'date_to >= %s',
		);
		$values = array_merge( $open_statuses, array( $date_to, $date_from ) );

		if ( '' !== $group_key ) {
			if ( false !== strpos( $group_key, '|si_spam_scope:' ) ) {
				$where[]  = 'group_key = %s';
				$values[] = $group_key;
			} else {
				$where[]  = '(group_key = %s OR (signal_id = %s AND entity_type = %s AND entity_id = %s))';
				$values[] = $group_key;
				$values[] = $signal_id;
				$values[] = $entity_type;
				$values[] = $entity_id;
			}
		} else {
			$where[]  = 'signal_id = %s';
			$where[]  = 'entity_type = %s';
			$where[]  = 'entity_id = %s';
			$values[] = $signal_id;
			$values[] = $entity_type;
			$values[] = $entity_id;
		}

		$values[] = $date_from;
		$values[] = $date_to;

		$sql = "SELECT * FROM {$this->table}
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY (date_from = %s AND date_to = %s) DESC, updated_at DESC, id DESC
			LIMIT 1';

		$row    = $wpdb->get_row( $wpdb->prepare( $sql, $values ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Prepare an insight row for insert/update.
	 *
	 * @param array $insight Raw insight object.
	 * @return array|WP_Error
	 */
	private function prepare_insight_data( $insight ) {
		if ( ! is_array( $insight ) ) {
			return new WP_Error( 'opti_behavior_smart_insights_invalid_payload', __( 'Smart Insight payload must be an array.', 'opti-behavior' ) );
		}

		$date_range = isset( $insight['date_range'] ) && is_array( $insight['date_range'] ) ? $insight['date_range'] : array();
		$date_from  = isset( $insight['date_from'] ) ? $insight['date_from'] : ( isset( $date_range['from'] ) ? $date_range['from'] : '' );
		$date_to    = isset( $insight['date_to'] ) ? $insight['date_to'] : ( isset( $date_range['to'] ) ? $date_range['to'] : '' );

		$required = array(
			'signal_id'   => isset( $insight['signal_id'] ) ? $insight['signal_id'] : '',
			'signal_name' => isset( $insight['signal_name'] ) ? $insight['signal_name'] : '',
			'entity_type' => isset( $insight['entity_type'] ) ? $insight['entity_type'] : '',
			'entity_id'   => isset( $insight['entity_id'] ) ? $insight['entity_id'] : '',
			'date_from'   => $date_from,
			'date_to'     => $date_to,
		);

		foreach ( $required as $field => $value ) {
			if ( '' === $value || null === $value ) {
				return new WP_Error( 'opti_behavior_smart_insights_missing_' . $field, sprintf( /* translators: %s: Field name. */ __( 'Smart Insight is missing required field: %s.', 'opti-behavior' ), $field ) );
			}
		}

		$now    = $this->current_mysql_time();
		$status = isset( $insight['status'] ) ? sanitize_key( $insight['status'] ) : self::STATUS_NEW;
		if ( ! in_array( $status, self::get_allowed_statuses(), true ) ) {
			$status = self::STATUS_NEW;
		}

		$resolved_at = isset( $insight['resolved_at'] ) ? $this->normalize_datetime_or_null( $insight['resolved_at'] ) : null;
		if ( in_array( $status, self::get_closed_statuses(), true ) && ! $resolved_at ) {
			$resolved_at = $now;
		}

		$recommended_actions = isset( $insight['recommended_actions'] ) ? $insight['recommended_actions'] : array();
		$recommended_actions = $this->normalize_recommended_actions_for_row(
			array(
				'signal_id'            => $insight['signal_id'],
				'recommended_actions'  => $recommended_actions,
			)
		);

		return array(
			'signal_id'                => sanitize_key( $insight['signal_id'] ),
			'signal_name'              => sanitize_text_field( $insight['signal_name'] ),
			'category'                 => isset( $insight['category'] ) ? sanitize_text_field( $insight['category'] ) : '',
			'entity_type'              => sanitize_key( $insight['entity_type'] ),
			'entity_id'                => sanitize_textarea_field( $insight['entity_id'] ),
			'entity_label'             => isset( $insight['entity_label'] ) ? sanitize_text_field( $insight['entity_label'] ) : sanitize_text_field( $insight['entity_id'] ),
			'date_from'                => $this->normalize_date( $date_from ),
			'date_to'                  => $this->normalize_date( $date_to ),
			'metrics_json'             => $this->encode_json_field( isset( $insight['metrics'] ) ? $insight['metrics'] : array(), array() ),
			'detection_json'           => $this->encode_json_field( isset( $insight['detection'] ) ? $insight['detection'] : array(), array() ),
			'scores_json'              => $this->encode_json_field( isset( $insight['scores'] ) ? $insight['scores'] : array(), array() ),
			'segment_json'             => $this->encode_json_field( isset( $insight['segment'] ) ? $insight['segment'] : ( isset( $insight['segments'] ) ? $insight['segments'] : array() ), array() ),
			'trend_json'               => $this->encode_json_field( isset( $insight['trend'] ) ? $insight['trend'] : array(), array() ),
			'parent_insight_id'        => isset( $insight['parent_insight_id'] ) && absint( $insight['parent_insight_id'] ) ? absint( $insight['parent_insight_id'] ) : null,
			'correlation_json'         => $this->encode_optional_json_field( isset( $insight['correlation'] ) ? $insight['correlation'] : null ),
			'evidence_refs_json'       => $this->encode_optional_json_field( isset( $insight['evidence_refs'] ) ? $insight['evidence_refs'] : null ),
			'hypothesis_json'          => $this->encode_optional_json_field( isset( $insight['hypothesis'] ) ? $insight['hypothesis'] : null ),
			'experiment_json'          => $this->encode_optional_json_field( isset( $insight['experiment'] ) ? $insight['experiment'] : null ),
			'impact_json'              => $this->encode_optional_json_field( isset( $insight['impact'] ) ? $insight['impact'] : null ),
			'interpretation'           => isset( $insight['interpretation'] ) ? wp_kses_post( $insight['interpretation'] ) : '',
			'why_it_matters'           => isset( $insight['why_it_matters'] ) ? wp_kses_post( $insight['why_it_matters'] ) : '',
			'likely_causes_json'       => $this->encode_json_field( isset( $insight['likely_causes'] ) ? $insight['likely_causes'] : array(), array() ),
			'recommended_actions_json' => $this->encode_json_field( $recommended_actions, array() ),
			'related_reports_json'     => $this->encode_json_field( isset( $insight['related_reports'] ) ? $insight['related_reports'] : array(), array() ),
			'availability'             => isset( $insight['availability'] ) ? sanitize_key( $insight['availability'] ) : 'free',
			'group_key'                => isset( $insight['group_key'] ) ? sanitize_text_field( $insight['group_key'] ) : $this->build_default_group_key( $insight ),
			'suppressed_until'         => isset( $insight['suppressed_until'] ) ? $this->normalize_datetime_or_null( $insight['suppressed_until'] ) : null,
			'source_plugin'            => isset( $insight['source_plugin'] ) ? sanitize_key( $insight['source_plugin'] ) : 'free',
			'visibility_tier'          => isset( $insight['visibility_tier'] ) ? $this->normalize_visibility_tier( $insight['visibility_tier'] ) : $this->derive_visibility_tier( $insight ),
			'status'                   => $status,
			'rule_version'             => isset( $insight['rule_version'] ) ? sanitize_text_field( $insight['rule_version'] ) : '',
			'created_at'               => isset( $insight['created_at'] ) ? $this->normalize_datetime( $insight['created_at'] ) : $now,
			'updated_at'               => $now,
			'last_seen_at'             => isset( $insight['last_seen_at'] ) ? $this->normalize_datetime( $insight['last_seen_at'] ) : $now,
			'resolved_at'              => $resolved_at,
		);
	}

	/**
	 * Decode an insight DB row into API-friendly keys.
	 *
	 * @param array $row Raw DB row.
	 * @return array
	 */
	private function decode_insight_row( $row ) {
		$row['metrics']             = $this->decode_json_field( $row['metrics_json'] );
		$row['detection']           = $this->decode_json_field( $row['detection_json'] );
		$row['scores']              = $this->decode_json_field( $row['scores_json'] );
		$row['segment']             = $this->decode_json_field( isset( $row['segment_json'] ) ? $row['segment_json'] : '[]' );
		$row['trend']               = $this->decode_json_field( isset( $row['trend_json'] ) ? $row['trend_json'] : '[]' );
		$row['likely_causes']       = $this->decode_json_field( $row['likely_causes_json'] );
		$row['recommended_actions'] = $this->decode_json_field( $row['recommended_actions_json'] );
		$row['recommended_actions'] = $this->normalize_recommended_actions_for_row( $row );
		$row['related_reports']     = $this->decode_json_field( $row['related_reports_json'] );
		// Every card about one page opens its Page X-Ray dossier (the dossier
		// already lists the page's insights; this is the way back). Added at
		// read time so cards stored before the tab existed get it too.
		// A funnel card opens the dossier of the page visitors leave from (the
		// generator resolved it once per run: detection.worst_transition.page_id).
		$xray_page = 0;
		if ( isset( $row['entity_type'], $row['entity_id'] ) && 'page' === $row['entity_type'] && ctype_digit( (string) $row['entity_id'] ) ) {
			$xray_page = (int) $row['entity_id'];
		} elseif ( isset( $row['entity_type'] ) && 'funnel' === $row['entity_type'] && ! empty( $row['detection']['worst_transition']['page_id'] ) ) {
			$xray_page = (int) $row['detection']['worst_transition']['page_id'];
		}
		// Page X-Ray is a Pro tab: the link only exists where the tab can open.
		if ( $xray_page > 0 && function_exists( 'opti_behavior_page_xray_available' ) && opti_behavior_page_xray_available() ) {
			$reports    = is_array( $row['related_reports'] ) ? $row['related_reports'] : array();
			$has_xray   = false;
			foreach ( $reports as $report ) {
				if ( is_array( $report ) && isset( $report['type'] ) && 'page_xray' === $report['type'] ) {
					$has_xray = true;
				}
			}
			if ( ! $has_xray ) {
				array_unshift(
					$reports,
					array(
						'label'   => __( 'Open the Page X-Ray dossier', 'opti-behavior' ),
						'type'    => 'page_xray',
						'page_id' => (string) $xray_page,
					)
				);
			}
			$row['related_reports'] = $reports;
		}
		$row['date_range']          = array(
			'from' => $row['date_from'],
			'to'   => $row['date_to'],
		);

		// Story blocks (correlation engine). Legacy rows predate these columns, so
		// every lookup is guarded and decodes to an empty array, which keeps the
		// uncorrelated single-signal card path byte-for-byte unchanged.
		$row['parent_insight_id'] = isset( $row['parent_insight_id'] ) ? (int) $row['parent_insight_id'] : 0;
		$row['correlation']       = $this->decode_json_field( isset( $row['correlation_json'] ) ? $row['correlation_json'] : '' );
		$row['evidence_refs']     = $this->decode_json_field( isset( $row['evidence_refs_json'] ) ? $row['evidence_refs_json'] : '' );
		$row['hypothesis']        = $this->decode_json_field( isset( $row['hypothesis_json'] ) ? $row['hypothesis_json'] : '' );
		$row['experiment']        = $this->decode_json_field( isset( $row['experiment_json'] ) ? $row['experiment_json'] : '' );
		$row['impact']            = $this->decode_json_field( isset( $row['impact_json'] ) ? $row['impact_json'] : '' );
		// Money computed by an older rule is never shown again (see Impact_Calculator::REVENUE_RULE).
		if ( ! empty( $row['impact']['revenue'] ) && class_exists( 'Opti_Behavior_Smart_Insights_Impact_Calculator' ) ) {
			$row['impact']['revenue'] = Opti_Behavior_Smart_Insights_Impact_Calculator::current_revenue( $row['impact']['revenue'] );
		}

		// Outcome loop (Workstream F). Legacy rows and rows that were never
		// resolved decode to an empty array, so every consumer keeps the exact
		// "no outcome" path it had before the column existed. The metric label is
		// resolved on read so a stored row never carries a frozen translation.
		$row['outcome'] = $this->decode_json_field( isset( $row['outcome_json'] ) ? $row['outcome_json'] : '' );
		if ( ! empty( $row['outcome'] ) && is_array( $row['outcome'] ) && class_exists( 'Opti_Behavior_Smart_Insights_Outcome_Evaluator' ) ) {
			$row['outcome'] = Opti_Behavior_Smart_Insights_Outcome_Evaluator::decorate( $row['outcome'] );
		}

		foreach ( array( 'metrics_json', 'detection_json', 'scores_json', 'segment_json', 'trend_json', 'likely_causes_json', 'recommended_actions_json', 'related_reports_json', 'correlation_json', 'evidence_refs_json', 'hypothesis_json', 'experiment_json', 'impact_json', 'outcome_json' ) as $field ) {
			unset( $row[ $field ] );
		}

		$row['id'] = isset( $row['id'] ) ? (int) $row['id'] : 0;

		return $row;
	}

	/**
	 * Return the nullable story-block columns added by the correlation engine.
	 *
	 * @since 1.3.8
	 *
	 * @return array Column name => payload key on the decoded insight array.
	 */
	public static function get_story_block_columns() {
		return array(
			'correlation_json'   => 'correlation',
			'evidence_refs_json' => 'evidence_refs',
			'hypothesis_json'    => 'hypothesis',
			'experiment_json'    => 'experiment',
			'impact_json'        => 'impact',
		);
	}

	/**
	 * Normalize recommended actions for current default Free signal copy.
	 *
	 * Existing rows persist the action checklist as JSON. The recommendation
	 * library owns the current first-action copy, so decoded rows pass through
	 * this compatibility layer before AJAX/list/detail/summary consumers render
	 * them.
	 *
	 * @since 1.3.7
	 *
	 * @param array $row Decoded or partially decoded insight row.
	 * @return array
	 */
	private function normalize_recommended_actions_for_row( $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}

		$actions = isset( $row['recommended_actions'] ) && is_array( $row['recommended_actions'] ) ? $row['recommended_actions'] : array();
		if ( empty( $row['signal_id'] ) || ! class_exists( 'Opti_Behavior_Smart_Insights_Recommendations' ) ) {
			return $actions;
		}

		static $recommendations = null;
		if ( null === $recommendations ) {
			$recommendations = new Opti_Behavior_Smart_Insights_Recommendations();
		}

		return $recommendations->normalize_recommended_actions_for_signal( $row['signal_id'], $actions );
	}

	/**
	 * JSON-encode a field safely.
	 *
	 * @param mixed $value    Field value.
	 * @param mixed $fallback Fallback value if encoding fails.
	 * @return string
	 */
	private function encode_json_field( $value, $fallback ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$value = $decoded;
			}
		}

		$json = wp_json_encode( $value );
		if ( false === $json ) {
			$json = wp_json_encode( $fallback );
		}

		return false === $json ? '[]' : $json;
	}

	/**
	 * JSON-encode a nullable story block.
	 *
	 * Story columns (`correlation_json`, `evidence_refs_json`, `hypothesis_json`,
	 * `experiment_json`, `impact_json`) stay SQL NULL until the correlation
	 * engine actually produces content. That keeps "no story yet" distinguishable
	 * from "empty story" and leaves legacy rows untouched.
	 *
	 * @since 1.3.8
	 *
	 * @param mixed $value Field value.
	 * @return string|null
	 */
	private function encode_optional_json_field( $value ) {
		if ( null === $value || '' === $value || array() === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || array() === $decoded ) {
				return null;
			}
			$value = $decoded;
		}

		$json = wp_json_encode( $value );

		return ( false === $json || 'null' === $json ) ? null : $json;
	}

	/**
	 * Decode a JSON field safely.
	 *
	 * @param string $value JSON string.
	 * @return array
	 */
	private function decode_json_field( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Get insert/update formats matching prepare_insight_data().
	 *
	 * $wpdb only consumes formats positionally, so the list length must track the
	 * prepared column count. Deriving it from the prepared row keeps the two in
	 * sync when story columns are added. NULL values bypass the format entirely
	 * in $wpdb, so '%s' is safe for the nullable integer column too.
	 *
	 * @param array|null $data Prepared insight row.
	 * @return array
	 */
	private function get_format_list( $data = null ) {
		$count = is_array( $data ) ? count( $data ) : 35;

		return array_fill( 0, $count, '%s' );
	}

	/**
	 * Build a conservative grouping key for dedupe/suppression features.
	 *
	 * @param array $insight Raw insight object.
	 * @return string
	 */
	private function build_default_group_key( $insight ) {
		$parts = array(
			isset( $insight['signal_id'] ) ? sanitize_key( $insight['signal_id'] ) : '',
			isset( $insight['entity_type'] ) ? sanitize_key( $insight['entity_type'] ) : '',
			isset( $insight['entity_id'] ) ? sanitize_text_field( $insight['entity_id'] ) : '',
		);

		return substr( implode( ':', array_filter( $parts ) ), 0, 191 );
	}

	/**
	 * Keep already-stored story blocks when a refresh does not supply them.
	 *
	 * A plain signal re-detection (scheduler tick, spam-scope refresh) rebuilds
	 * only the classic insight payload. Without this guard it would overwrite a
	 * previously computed correlation, hypothesis, experiment link, or impact
	 * block with NULL. Incoming non-empty blocks always win.
	 *
	 * @since 1.3.8
	 *
	 * @param array $data     Prepared incoming insight data (by reference).
	 * @param array $existing Existing database row.
	 * @return void
	 */
	private function preserve_existing_story_blocks( &$data, $existing ) {
		foreach ( array_keys( self::get_story_block_columns() ) as $column ) {
			if ( ! empty( $data[ $column ] ) ) {
				continue;
			}

			if ( isset( $existing[ $column ] ) && null !== $existing[ $column ] && '' !== $existing[ $column ] ) {
				$data[ $column ] = $existing[ $column ];
			}
		}

		if ( empty( $data['parent_insight_id'] ) && ! empty( $existing['parent_insight_id'] ) ) {
			$data['parent_insight_id'] = (int) $existing['parent_insight_id'];
		}
	}

	/**
	 * Check whether an existing row was matched by the logical group key.
	 *
	 * @param array $data     Prepared incoming insight data.
	 * @param array $existing Existing database row.
	 * @return bool
	 */
	private function did_group_key_match_existing( $data, $existing ) {
		$new_group_key      = isset( $data['group_key'] ) ? sanitize_text_field( $data['group_key'] ) : '';
		$existing_group_key = isset( $existing['group_key'] ) ? sanitize_text_field( $existing['group_key'] ) : '';

		return '' !== $new_group_key && '' !== $existing_group_key && $new_group_key === $existing_group_key;
	}

	/**
	 * Determine whether a repeated ignored insight has materially worsened.
	 *
	 * @param float $old_priority Stored priority score.
	 * @param float $new_priority Incoming priority score.
	 * @return bool
	 */
	private function is_material_priority_worsening( $old_priority, $new_priority ) {
		$delta = (float) $new_priority - (float) $old_priority;

		return $delta >= $this->get_ignored_reopen_priority_delta();
	}

	/**
	 * Get the priority delta required to reopen an ignored recurring insight.
	 *
	 * @return int
	 */
	private function get_ignored_reopen_priority_delta() {
		$threshold = (int) apply_filters( 'opti_behavior_smart_insights_ignored_reopen_priority_delta', 15 );

		return max( 1, min( 100, $threshold ) );
	}

	/**
	 * Compare two decoded insight rows by analyst-facing recency.
	 *
	 * @param array $left  Left row.
	 * @param array $right Right row.
	 * @return int Positive when left is newer.
	 */
	private function compare_insight_recency( $left, $right ) {
		$left_time  = $this->insight_recency_timestamp( $left );
		$right_time = $this->insight_recency_timestamp( $right );

		if ( $left_time === $right_time ) {
			$left_id  = isset( $left['id'] ) ? (int) $left['id'] : 0;
			$right_id = isset( $right['id'] ) ? (int) $right['id'] : 0;
			return $left_id <=> $right_id;
		}

		return $left_time <=> $right_time;
	}

	/**
	 * Resolve a row timestamp for timeline sorting and collapse decisions.
	 *
	 * @param array $insight Insight row.
	 * @return int
	 */
	private function insight_recency_timestamp( $insight ) {
		foreach ( array( 'updated_at', 'last_seen_at', 'created_at', 'date_to' ) as $field ) {
			if ( ! empty( $insight[ $field ] ) ) {
				$timestamp = strtotime( (string) $insight[ $field ] );
				if ( $timestamp ) {
					return (int) $timestamp;
				}
			}
		}

		return 0;
	}

	/**
	 * Find the oldest created/seen timestamp from a collapsed history group.
	 *
	 * @param array $history History rows.
	 * @return string
	 */
	private function oldest_timestamp_from_history( $history ) {
		$oldest = 0;
		foreach ( (array) $history as $row ) {
			foreach ( array( 'created_at', 'last_seen_at', 'updated_at' ) as $field ) {
				if ( empty( $row[ $field ] ) ) {
					continue;
				}
				$timestamp = strtotime( (string) $row[ $field ] );
				if ( $timestamp && ( 0 === $oldest || $timestamp < $oldest ) ) {
					$oldest = $timestamp;
				}
			}
		}

		return $oldest ? gmdate( 'Y-m-d H:i:s', $oldest ) : '';
	}

	/**
	 * Derive the visibility tier from legacy availability values.
	 *
	 * @param array $insight Raw insight object.
	 * @return string
	 */
	private function derive_visibility_tier( $insight ) {
		$availability = isset( $insight['availability'] ) ? sanitize_key( $insight['availability'] ) : 'free';

		if ( 'pro' === $availability ) {
			return 'pro';
		}

		if ( in_array( $availability, array( 'pro_locked', 'locked' ), true ) ) {
			return 'pro_locked';
		}

		return 'free';
	}

	/**
	 * Normalize visibility tier.
	 *
	 * @param string $tier Tier value.
	 * @return string
	 */
	private function normalize_visibility_tier( $tier ) {
		$tier = sanitize_key( $tier );

		return in_array( $tier, array( 'free', 'pro_locked', 'pro' ), true ) ? $tier : 'free';
	}

	/**
	 * Normalize a date to Y-m-d.
	 *
	 * @param string $date Date value.
	 * @return string
	 */
	private function normalize_date( $date ) {
		$timestamp = strtotime( (string) $date );

		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : gmdate( 'Y-m-d' );
	}

	/**
	 * Normalize a datetime to MySQL format.
	 *
	 * @param string $datetime Datetime value.
	 * @return string
	 */
	private function normalize_datetime( $datetime ) {
		$timestamp = strtotime( (string) $datetime );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : $this->current_mysql_time();
	}

	/**
	 * Normalize a nullable datetime to MySQL format.
	 *
	 * @param string|null $datetime Datetime value.
	 * @return string|null
	 */
	private function normalize_datetime_or_null( $datetime ) {
		if ( empty( $datetime ) ) {
			return null;
		}

		$timestamp = strtotime( (string) $datetime );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;
	}

	/**
	 * Get current local MySQL datetime.
	 *
	 * @return string
	 */
	private function current_mysql_time() {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Restrict ORDER BY to known columns.
	 *
	 * @param string $orderby Requested orderby.
	 * @return string
	 */
	private function sanitize_orderby( $orderby ) {
		$allowed = array(
			'id'         => 'id',
			'created_at' => 'created_at',
			'updated_at' => 'updated_at',
			'last_seen_at' => 'last_seen_at',
			'date_from'  => 'date_from',
			'date_to'    => 'date_to',
			'status'     => 'status',
		);

		$orderby = sanitize_key( $orderby );

		return isset( $allowed[ $orderby ] ) ? $allowed[ $orderby ] : 'updated_at';
	}

	/**
	 * Build a missing-table error.
	 *
	 * @return WP_Error
	 */
	private function missing_table_error() {
		return new WP_Error(
			'opti_behavior_smart_insights_table_missing',
			__( 'The Smart Insights table is not available yet. Please reload the admin page to finish the upgrade.', 'opti-behavior' )
		);
	}

	/**
	 * Ensure optional Phase 0 columns exist for interrupted upgrades.
	 *
	 * The main migration remains in dbDelta(), but repository writes also guard
	 * themselves so existing rows survive partial uploads or same-version dev
	 * installs where activation has not re-run yet.
	 *
	 * @return void
	 */
	private function ensure_optional_schema() {
		global $wpdb;

		$this->schema_checked = true;

		$columns = array(
			'segment_json'     => 'ADD COLUMN segment_json longtext DEFAULT NULL AFTER scores_json',
			'trend_json'       => 'ADD COLUMN trend_json longtext DEFAULT NULL AFTER segment_json',
			'group_key'        => "ADD COLUMN group_key varchar(191) NOT NULL DEFAULT '' AFTER availability",
			'suppressed_until' => 'ADD COLUMN suppressed_until datetime DEFAULT NULL AFTER group_key',
			'source_plugin'    => "ADD COLUMN source_plugin varchar(20) NOT NULL DEFAULT 'free' AFTER suppressed_until",
			'visibility_tier'  => "ADD COLUMN visibility_tier varchar(50) NOT NULL DEFAULT 'free' AFTER source_plugin",
			// Story columns (correlation engine). Added AFTER trend_json to match
			// the dbDelta layout; all nullable so existing rows keep rendering
			// through the uncorrelated single-signal card path.
			'parent_insight_id'  => 'ADD COLUMN parent_insight_id bigint(20) UNSIGNED DEFAULT NULL AFTER trend_json',
			'correlation_json'   => 'ADD COLUMN correlation_json longtext DEFAULT NULL AFTER parent_insight_id',
			'evidence_refs_json' => 'ADD COLUMN evidence_refs_json longtext DEFAULT NULL AFTER correlation_json',
			'hypothesis_json'    => 'ADD COLUMN hypothesis_json longtext DEFAULT NULL AFTER evidence_refs_json',
			'experiment_json'    => 'ADD COLUMN experiment_json longtext DEFAULT NULL AFTER hypothesis_json',
			'impact_json'        => 'ADD COLUMN impact_json longtext DEFAULT NULL AFTER experiment_json',
			// Outcome loop: the before/after measurement of a resolved insight.
			// Written only by update_outcome(); a generation run never touches it.
			'outcome_json'       => 'ADD COLUMN outcome_json longtext DEFAULT NULL AFTER impact_json',
		);

		foreach ( $columns as $column => $alter_sql ) {
			if ( ! $this->column_exists( $column ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Internal allowlisted migration after column-existence check.
				$wpdb->query( "ALTER TABLE {$this->table} {$alter_sql}" );
			}
		}
	}

	/**
	 * Check whether a repository table column exists.
	 *
	 * @param string $column Column name.
	 * @return bool
	 */
	private function column_exists( $column ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				DB_NAME,
				$this->table,
				$column
			)
		);
	}
}
