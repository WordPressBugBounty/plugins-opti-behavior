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
			$same_window        = isset( $existing['date_from'], $existing['date_to'] ) && $existing['date_from'] === $data['date_from'] && $existing['date_to'] === $data['date_to'];
			$recurrence_count   = isset( $existing_detection['recurrence_count'] ) ? max( 1, (int) $existing_detection['recurrence_count'] ) : 1;
			if ( ! $same_window ) {
				$recurrence_count++;
			}

			$detection['same_issue_previous_period'] = true;
			$detection['recurrence_count']           = $recurrence_count;
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
				$this->get_format_list(),
				array( '%d' )
			);

			if ( false === $result ) {
				return new WP_Error( 'opti_behavior_smart_insights_update_failed', __( 'Unable to update the Smart Insight.', 'opti-behavior' ) );
			}

			$this->auto_resolve_superseded_insights( (int) $existing['id'], $data );

			return (int) $existing['id'];
		}

		$result = $wpdb->insert( $this->table, $data, $this->get_format_list() );
		if ( false === $result ) {
			return new WP_Error( 'opti_behavior_smart_insights_insert_failed', __( 'Unable to save the Smart Insight.', 'opti-behavior' ) );
		}

		$insert_id = (int) $wpdb->insert_id;
		$this->auto_resolve_superseded_insights( $insert_id, $data );

		return $insert_id;
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

				$latest['recurrence_count'] = count( $history );
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
				$latest['detection']['recurrence_count'] = count( $history );
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

		return true;
	}

	/**
	 * Auto-resolve open insights that have not been detected recently.
	 *
	 * Generators can call this after a successful run once they know a previous
	 * open insight did not re-trigger across the configured consecutive periods.
	 * Manually ignored insights are preserved.
	 *
	 * @param string $last_seen_before MySQL datetime cutoff.
	 * @param array  $args Optional signal/entity filters.
	 * @return int|WP_Error Number of rows updated.
	 */
	public function auto_resolve_stale_insights( $last_seen_before, $args = array() ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return $this->missing_table_error();
		}

		$defaults = array(
			'signal_id'   => '',
			'entity_type' => '',
			'spam_scope'  => null,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( 'last_seen_at < %s', "status IN ('new','viewed','in_progress')" );
		$values = array( $last_seen_before );

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

		return $wpdb->query( "TRUNCATE TABLE {$this->table}" );
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
		$row['date_range']          = array(
			'from' => $row['date_from'],
			'to'   => $row['date_to'],
		);

		foreach ( array( 'metrics_json', 'detection_json', 'scores_json', 'segment_json', 'trend_json', 'likely_causes_json', 'recommended_actions_json', 'related_reports_json' ) as $field ) {
			unset( $row[ $field ] );
		}

		$row['id'] = isset( $row['id'] ) ? (int) $row['id'] : 0;

		return $row;
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
	 * @return array
	 */
	private function get_format_list() {
		return array_fill( 0, 29, '%s' );
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
