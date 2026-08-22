<?php
/**
 * Smart Insights Heatmap Aggregator Class
 *
 * Aggregates heatmap/CTA event counts without storing raw coordinates.
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
 * Smart Insights Heatmap Aggregator Class.
 *
 * Provides page-level click and CTA-safe aggregates. It intentionally avoids
 * exposing raw x/y coordinates, selectors, or recording payloads in insights.
 *
 * @since 1.3.3
 */
class Opti_Behavior_Smart_Insights_Heatmap_Aggregator {

	/** Cached table checks. @var array */
	private $table_exists = array();

	/** Cached column checks. @var array */
	private $column_exists = array();

	/**
	 * Get heatmap/CTA aggregates for a date range.
	 *
	 * @param string $start_date Start date/datetime.
	 * @param string $end_date   End date/datetime.
	 * @param array  $args       Optional args: limit, page_ids, min_clicks, exclude_spam.
	 * @return array[]
	 */
	public function get_heatmap_metrics( $start_date, $end_date, $args = array() ) {
		global $wpdb;

		$defaults = array(
			'limit'        => 100,
			'page_ids'     => array(),
			'min_clicks'   => 0,
			'exclude_spam' => $this->is_spam_excluded(),
		);
		$args     = wp_parse_args( $args, $defaults );

		$start_date = $this->normalize_start_datetime( $start_date );
		$end_date   = $this->normalize_end_datetime( $end_date );
		$limit      = max( 1, min( 500, absint( $args['limit'] ) ) );
		$min_clicks = max( 0, absint( $args['min_clicks'] ) );

		$events_table = $wpdb->prefix . 'optibehavior_events';
		$pages_table  = $wpdb->prefix . 'optibehavior_pages';
		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		if ( ! $this->table_exists( $events_table ) ) {
			return array();
		}

		$page_id_column = $this->column_exists( $events_table, 'page_id2' ) ? 'page_id2' : 'page_id';
		$has_pages      = $this->table_exists( $pages_table );
		$page_join      = $has_pages ? "LEFT JOIN {$pages_table} p ON e.{$page_id_column} = p.id" : '';
		$url_expr       = $has_pages && $this->column_exists( $pages_table, 'url2' ) ? "COALESCE(NULLIF(p.url2, ''), NULLIF(p.url, ''), CONCAT('page:', e.{$page_id_column}))" : ( $has_pages && $this->column_exists( $pages_table, 'url' ) ? "COALESCE(NULLIF(p.url, ''), CONCAT('page:', e.{$page_id_column}))" : "CONCAT('page:', e.{$page_id_column})" );
		$title_expr     = $has_pages && $this->column_exists( $pages_table, 'title' ) ? "COALESCE(NULLIF(p.title, ''), {$url_expr})" : $url_expr;
		$click_events   = $this->get_click_event_codes();
		$placeholders   = implode( ',', array_fill( 0, count( $click_events ), '%d' ) );
		$where_page_ids = '';
		$spam_clause    = ! empty( $args['exclude_spam'] ) ? $this->get_event_spam_exclusion_clause( 'e', $sessions_table ) : '';
		$params         = array_merge( $click_events, array( $start_date, $end_date ) );

		$page_ids = array_values( array_filter( array_map( 'absint', (array) $args['page_ids'] ) ) );
		if ( ! empty( $page_ids ) ) {
			$where_page_ids = " AND e.{$page_id_column} IN (" . implode( ',', array_fill( 0, count( $page_ids ), '%d' ) ) . ')';
			$params         = array_merge( $params, $page_ids );
		}
		$params[] = $min_clicks;
		$params[] = $limit;

		$cta_expr = $this->get_cta_case_expr( 'e' );

		$sql = "SELECT
				e.{$page_id_column} AS page_id,
				MIN({$url_expr}) AS page_url,
				MIN({$title_expr}) AS page_title,
				COUNT(*) AS click_count,
				SUM({$cta_expr}) AS cta_click_count,
				COUNT(DISTINCT e.session_id) AS click_sessions,
				MAX(e.insert_at) AS last_seen_at
			FROM {$events_table} e
			{$page_join}
			WHERE e.event IN ({$placeholders})
				AND e.insert_at BETWEEN %s AND %s{$where_page_ids}{$spam_clause}
			GROUP BY e.{$page_id_column}
			HAVING click_count >= %d
			ORDER BY click_count DESC
			LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}

		$metrics = array();
		foreach ( $rows as $row ) {
			$page_id    = max( 0, (int) $row['page_id'] );
			$page_url   = (string) $row['page_url'];
			$page_title = (string) $row['page_title'];

			$metrics[] = array(
				'page_id'                => $page_id,
				'page_url'               => $page_url,
				'page_title'             => $page_title,
				'entity_type'            => 'cta',
				'entity_id'              => $page_url ? $page_url : (string) $page_id,
				'entity_label'           => $page_title ? $page_title : $page_url,
				'click_count'            => max( 0, (int) $row['click_count'] ),
				'cta_click_count'        => max( 0, (int) $row['cta_click_count'] ),
				'click_sessions'         => max( 0, (int) $row['click_sessions'] ),
				'cta_click_rate'         => (int) $row['click_count'] > 0 ? round( ( (int) $row['cta_click_count'] / (int) $row['click_count'] ) * 100, 2 ) : null,
				'tracking_data_complete' => (int) $row['click_count'] > 0,
				'last_seen_at'           => isset( $row['last_seen_at'] ) ? (string) $row['last_seen_at'] : '',
			);
		}

		return apply_filters( 'opti_behavior_smart_insights_heatmap_metrics', $metrics, array( 'start' => $start_date, 'end' => $end_date, 'exclude_spam' => (bool) $args['exclude_spam'] ) );
	}

	/** Alias for naming consistency. */
	public function aggregate_heatmap_metrics( $start_date, $end_date, $args = array() ) {
		return $this->get_heatmap_metrics( $start_date, $end_date, $args );
	}

	/** Get safe click/CTA metrics keyed by numeric page ID. */
	public function get_page_event_counts( $start_date, $end_date, $page_ids = array(), $args = array() ) {
		$args   = wp_parse_args( $args, array( 'exclude_spam' => $this->is_spam_excluded() ) );
		$rows   = $this->get_heatmap_metrics( $start_date, $end_date, array( 'page_ids' => $page_ids, 'limit' => max( 1, count( (array) $page_ids ) ), 'exclude_spam' => $args['exclude_spam'] ) );
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row['page_id'] ] = $row;
		}
		return $counts;
	}

	/** Click event constants, with a numeric fallback if Core is not loaded yet. */
	private function get_click_event_codes() {
		if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			return array( Opti_Behavior_Heatmap_Core::CLICK_PC, Opti_Behavior_Heatmap_Core::CLICK_MOBILE );
		}
		return array( 16, 17 );
	}

	/** Build a CTA-safe CASE expression without returning selectors. */
	private function get_cta_case_expr( $alias ) {
		$pre        = $alias ? $alias . '.' : '';
		$conditions = array();

		foreach ( array( 'element_tag', 'element_id', 'element_class' ) as $column ) {
			global $wpdb;
			$table = $wpdb->prefix . 'optibehavior_events';
			if ( ! $this->column_exists( $table, $column ) ) {
				continue;
			}

			if ( 'element_tag' === $column ) {
				$conditions[] = "LOWER({$pre}{$column}) IN ('a', 'button', 'input')";
			} else {
				// This expression is used inside a wpdb->prepare() SQL statement.
				// Percent symbols must be escaped as %% to avoid placeholder parsing.
				$conditions[] = "LOWER({$pre}{$column}) LIKE '%%cta%%'";
				$conditions[] = "LOWER({$pre}{$column}) LIKE '%%button%%'";
				$conditions[] = "LOWER({$pre}{$column}) LIKE '%%submit%%'";
			}
		}

		if ( empty( $conditions ) ) {
			return '0';
		}

		return 'CASE WHEN ' . implode( ' OR ', $conditions ) . ' THEN 1 ELSE 0 END';
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
	 * Build a spam exclusion fragment for event rows.
	 *
	 * @param string $event_alias    Events table alias.
	 * @param string $sessions_table Sessions table name.
	 * @return string SQL fragment.
	 */
	private function get_event_spam_exclusion_clause( $event_alias, $sessions_table ) {
		$event_alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $event_alias );
		$event_alias = $event_alias ? $event_alias : 'e';

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

		return " AND {$event_alias}.session_id IN (SELECT s.id FROM {$sessions_table} s WHERE " . implode( ' AND ', $conditions ) . ')';
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
