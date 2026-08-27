<?php
/**
 * Heatmap AJAX Handler
 *
 * Handles AJAX requests for heatmap detail page data.
 * Processes session recordings to generate heatmap coordinates and statistics.
 *
 * @package OptiBehavior
 * @since 1.5.0
 * @modified 2025-12-16 - Added ajax_get_filter_options endpoint
 * @modified 2026-01-20 - FIX: count_sessions_for_stats now treats unknown devices as desktop (BUGFIX-SESSION-COUNT-V1)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Opti_Behavior_Heatmap_Ajax', false ) ) {
	return;
}

/**
 * Class Opti_Behavior_Heatmap_Ajax
 *
 * Handles AJAX endpoints for heatmap data retrieval and processing.
 */
class Opti_Behavior_Heatmap_Ajax {

	/**
	 * Parser instance.
	 *
	 * @var Opti_Behavior_Heatmap_Parser
	 */
	private $parser;

	/**
	 * Cache instance.
	 *
	 * @var Opti_Behavior_Heatmap_Cache
	 */
	private $cache;

	/**
	 * Shared counting-only instance for cross-plugin session-count queries.
	 *
	 * The list page (Free plugin) needs the same canonical session counts the
	 * detail page produces, but must not re-register the AJAX hooks that the
	 * live instance already owns. This lazily-built instance skips hook
	 * registration and is safe to reuse for pure count queries.
	 *
	 * @var Opti_Behavior_Heatmap_Ajax|null
	 */
	private static $count_helper = null;

	/**
	 * Per-request memo for get_orphan_session_tokens_via_bridge() (RC-C, spec
	 * §3.2) — avoids re-scanning the same page group's file dirs when several
	 * consumers (fast-path render, filter options, variant file count) run in
	 * the same request.
	 *
	 * @var array
	 */
	private $hm_orphan_tokens_memo = array();

	/**
	 * Per-request memo for get_orphan_session_metadata_via_bridge() — same
	 * scan cost class as $hm_orphan_tokens_memo but keyed to the full
	 * parsed filename metadata (device / visitor marker / country / browser
	 * / A-B variant) instead of bare tokens, so device badges, the visitor
	 * type dropdown, and the country/browser dropdowns can each bucket
	 * orphan sessions into the same "N" counters the DB canonical queries
	 * use without re-globbing the storage dirs per consumer.
	 *
	 * @var array
	 */
	private $hm_orphan_meta_memo = array();

	/**
	 * Per-request memo for get_admissible_file_session_metadata_via_bridge()
	 * — full-parity follow-up (dropdown/badges must partition the same
	 * file_total_all universe the header pill counts, not just the narrow
	 * "zero DB trace" orphan subset). Keyed separately from
	 * $hm_orphan_meta_memo because the admission gate is broader (spam
	 * allow-list OR unknown-anywhere, matching
	 * count_heatmap_file_sessions_for_page_by_device()) and also varies by
	 * the caller's exclude_spam filter state.
	 *
	 * @var array
	 */
	private $hm_admissible_meta_memo = array();

	/**
	 * Per-request memo for get_covered_session_lookup_via_scope() — the exact
	 * session_pages.session_id set a given DB scope query already returns,
	 * so the admissible file-session merge never double-counts a session the
	 * DB canonical query already has evidence for under that scope.
	 *
	 * @var array
	 */
	private $hm_covered_lookup_memo = array();

	/**
	 * Get a hook-free shared instance for canonical session-count queries.
	 *
	 * @return Opti_Behavior_Heatmap_Ajax
	 */
	public static function get_count_helper() {
		if ( null === self::$count_helper ) {
			self::$count_helper = new self( false );
		}

		return self::$count_helper;
	}

	/**
	 * Constructor.
	 *
	 * @param bool $register_hooks Whether to register AJAX hooks. False builds a
	 *                             counting-only helper used by the list page.
	 */
	public function __construct( $register_hooks = true ) {
		$this->parser = new Opti_Behavior_Heatmap_Parser();
		$this->cache  = new Opti_Behavior_Heatmap_Cache();

		if ( ! $register_hooks ) {
			return;
		}

		// Register AJAX handlers.
		add_action( 'wp_ajax_opti_behavior_get_heatmap_data', array( $this, 'ajax_get_heatmap_data' ) );
		add_action( 'wp_ajax_opti_behavior_get_heatmap_stats', array( $this, 'ajax_get_heatmap_stats' ) );
		add_action( 'wp_ajax_opti_behavior_refresh_heatmap_cache', array( $this, 'ajax_refresh_cache' ) );
		add_action( 'wp_ajax_opti_behavior_get_device_counts', array( $this, 'ajax_get_device_counts' ) );
		add_action( 'wp_ajax_opti_behavior_get_total_recordings', array( $this, 'ajax_get_total_recordings' ) );
		add_action( 'wp_ajax_opti_behavior_get_heatmap_batch', array( $this, 'ajax_get_heatmap_batch' ) );
		add_action( 'wp_ajax_opti_behavior_get_filter_options', array( $this, 'ajax_get_filter_options' ) );
		add_action( 'wp_ajax_opti_behavior_proxy_page', array( $this, 'ajax_proxy_page' ) );
	}

	/**
	 * Debug logging helper - only logs if debug mode is enabled.
	 *
	 * @param string $message Message to log.
	 * @return void
	 */
	private function debug_log( $message, $level = 'debug' ) {
		$core = Opti_Behavior_Heatmap_Core::get_instance();
		if ( $core ) {
			$debug_manager = $core->get_debug_manager();
			if ( $debug_manager ) {
				$debug_manager->log( $message, $level, 'heatmap-ajax-prox' );
			}
		}
	}

	/**
	 * Normalize heatmap detail multi-select filters.
	 *
	 * @param string|array $raw_value Raw filter value.
	 * @param string       $case      Case normalization: upper, lower, or preserve.
	 * @return array Unique normalized values.
	 */
	private function normalize_heatmap_multi_select_filter( $raw_value, $case = 'preserve' ) {
		$parts = array();

		foreach ( (array) $raw_value as $value ) {
			foreach ( explode( ',', (string) $value ) as $part ) {
				$part = trim( $part );
				if ( '' === $part ) {
					continue;
				}

				if ( 'upper' === $case ) {
					$part = strtoupper( $part );
				} elseif ( 'lower' === $case ) {
					$part = strtolower( $part );
				}

				$parts[ $part ] = $part;
			}
		}

		return array_values( $parts );
	}

	/**
	 * Append an OR-within-filter SQL predicate for a heatmap multi-select field.
	 *
	 * @param string $where     WHERE fragment to append to.
	 * @param array  $params    Prepared-statement parameters to append to.
	 * @param string $column    SQL column/expression.
	 * @param array  $values    Normalized filter values.
	 * @return void
	 */
	private function append_heatmap_multi_select_sql_filter( &$where, &$params, $column, $values ) {
		if ( empty( $values ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		$where       .= " AND {$column} IN ({$placeholders})";
		$params       = array_merge( $params, $values );
	}

	/**
	 * Parse canonical page IDs from an AJAX request.
	 *
	 * @param array $source     Request source.
	 * @param int   $primary_id Primary page ID fallback.
	 * @return array Positive unique page IDs.
	 */
	private function get_request_page_ids( $source, $primary_id = 0 ) {
		$page_ids = array();

		if ( isset( $source['page_ids'] ) ) {
			$raw_page_ids = sanitize_text_field( wp_unslash( $source['page_ids'] ) );
			$page_ids     = array_map( 'absint', explode( ',', $raw_page_ids ) );
		}

		if ( $primary_id ) {
			array_unshift( $page_ids, absint( $primary_id ) );
		}

		$page_ids = array_values( array_unique( array_filter( $page_ids ) ) );

		return array_slice( $page_ids, 0, 100 );
	}

	/**
	 * Get canonical page IDs from filters with a primary fallback.
	 *
	 * @param int   $page_id Primary page ID.
	 * @param array $filters Filter values.
	 * @return array Positive unique page IDs.
	 */
	private function get_filter_page_ids( $page_id, $filters = array() ) {
		$page_ids = ! empty( $filters['page_ids'] ) && is_array( $filters['page_ids'] ) ? $filters['page_ids'] : array();
		array_unshift( $page_ids, absint( $page_id ) );

		return array_values( array_unique( array_filter( array_map( 'absint', $page_ids ) ) ) );
	}

	/**
	 * Build a prepared IN clause for canonical page IDs.
	 *
	 * @param string $column   SQL column/expression.
	 * @param array  $page_ids Page IDs.
	 * @return string
	 */
	private function build_page_ids_clause( $column, $page_ids ) {
		$page_ids = array_values( array_filter( array_map( 'absint', (array) $page_ids ) ) );
		if ( empty( $page_ids ) ) {
			return '1=0';
		}

		return $column . ' IN (' . implode( ',', array_fill( 0, count( $page_ids ), '%d' ) ) . ')';
	}

	/**
	 * Global default for excluding spam, controlled by Traffic Behavior settings.
	 *
	 * @return bool True when spam should be excluded by default.
	 */
	private function get_global_spam_exclusion_default() {
		$traffic_settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_detection_enabled' => true,
			)
		);

		if ( ! is_array( $traffic_settings ) ) {
			return true;
		}

		return ! array_key_exists( 'spam_detection_enabled', $traffic_settings ) || ! empty( $traffic_settings['spam_detection_enabled'] );
	}

	/**
	 * Resolve spam exclusion from a request payload, falling back to the global default.
	 *
	 * @param array $source Request source.
	 * @return bool True when spam should be excluded.
	 */
	private function resolve_spam_exclusion_from_request( $source ) {
		if ( is_array( $source ) && array_key_exists( 'exclude_spam', $source ) ) {
			return '1' === (string) sanitize_text_field( wp_unslash( $source['exclude_spam'] ) );
		}

		return $this->get_global_spam_exclusion_default();
	}

	/**
	 * Add custom Smart Insights date bounds to heatmap filters when present.
	 *
	 * @param array  $filters    Existing filters.
	 * @param string $date_range Date range key.
	 * @param array  $source     Request source.
	 * @return array Filters with optional start/end date keys.
	 */
	private function add_custom_date_filters( $filters, $date_range, $source ) {
		$start_date = '';
		$end_date   = '';

		if ( is_array( $source ) ) {
			if ( isset( $source['start_date'] ) ) {
				$start_date = sanitize_text_field( wp_unslash( $source['start_date'] ) );
			} elseif ( isset( $source['date_from'] ) ) {
				$start_date = sanitize_text_field( wp_unslash( $source['date_from'] ) );
			}

			if ( isset( $source['end_date'] ) ) {
				$end_date = sanitize_text_field( wp_unslash( $source['end_date'] ) );
			} elseif ( isset( $source['date_to'] ) ) {
				$end_date = sanitize_text_field( wp_unslash( $source['date_to'] ) );
			}
		}

		if ( 'custom' === $date_range && $start_date && $end_date ) {
			$filters['start_date'] = $start_date;
			$filters['end_date']   = $end_date;
		}

		return $filters;
	}

	/**
	 * Build SQL fragments matching the recordings-page spam filter.
	 *
	 * @param string $recording_alias Recordings table alias.
	 * @param string $session_alias   Sessions table alias.
	 * @param string $scroll_alias    Scroll-count derived table alias.
	 * @return array SQL fragments.
	 */
	private function build_recordings_spam_filter_sql( $recording_alias = 'r', $session_alias = 's', $scroll_alias = 'hm_spam_scrolls' ) {
		global $wpdb;

		if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) && method_exists( 'Opti_Behavior_Stats_Spam_Filter', 'recordings_threshold_sql' ) ) {
			$filter = Opti_Behavior_Stats_Spam_Filter::recordings_threshold_sql( $recording_alias, $session_alias, $scroll_alias, true );
			if ( ! empty( $filter['where'] ) ) {
				$filter['where'] = ' AND ' . $filter['where'];
			}

			return $filter;
		}

		$traffic_settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_detection_enabled'      => true,
				'spam_duration_threshold'     => 3,
				'spam_min_scrolls_threshold'  => 0,
				'spam_min_clicks_threshold'   => 1,
			)
		);

		if ( is_array( $traffic_settings ) && array_key_exists( 'spam_detection_enabled', $traffic_settings ) && empty( $traffic_settings['spam_detection_enabled'] ) ) {
			return array(
				'join'      => '',
				'where'     => " AND ({$session_alias}.traffic_type IS NULL OR {$session_alias}.traffic_type != 'spam')",
				'values'    => array(),
				'cache_key' => 'disabled',
			);
		}

		$spam_duration = isset( $traffic_settings['spam_duration_threshold'] ) ? max( 0, intval( $traffic_settings['spam_duration_threshold'] ) ) : 3;
		$spam_scrolls  = isset( $traffic_settings['spam_min_scrolls_threshold'] ) ? max( 0, intval( $traffic_settings['spam_min_scrolls_threshold'] ) ) : 0;
		$spam_clicks   = isset( $traffic_settings['spam_min_clicks_threshold'] ) ? max( 0, intval( $traffic_settings['spam_min_clicks_threshold'] ) ) : 1;
		$duration_sql  = "GREATEST(COALESCE({$recording_alias}.duration, 0), COALESCE({$session_alias}.duration, 0), COALESCE(TIMESTAMPDIFF(SECOND, {$session_alias}.start_time, COALESCE({$session_alias}.end_time, {$session_alias}.start_time)), 0), COALESCE({$scroll_alias}.duration, 0))";

		return array(
			'join'      => " LEFT JOIN (
					SELECT session_id, COUNT(*) AS page_count, MAX(COALESCE(duration, 0)) AS duration, SUM(CASE WHEN COALESCE(scroll_depth, 0) > 0 THEN 1 ELSE 0 END) AS scroll_count, SUM(COALESCE(clicks_count, 0)) AS click_count
					FROM {$wpdb->prefix}optibehavior_session_pages
					GROUP BY session_id
				) {$scroll_alias} ON {$recording_alias}.session_id = {$scroll_alias}.session_id
				LEFT JOIN (
					SELECT session_id, COUNT(*) AS scroll_count
					FROM {$wpdb->prefix}optibehavior_events
					WHERE event IN (32,33)
					GROUP BY session_id
				) {$scroll_alias}_events ON {$recording_alias}.session_id = {$scroll_alias}_events.session_id",
			// Ghost-session tolerance: do not require a sessions-table row here.
			// Missing session rows (cleanup/retention/ingestion gaps) must fall
			// through to the recording/session_pages engagement fallbacks below
			// instead of zeroing the detail page. Traffic-type clause is
			// NULL-tolerant, so stored spam verdicts still exclude when present.
			'where'     => " AND (({$session_alias}.traffic_type IS NULL OR {$session_alias}.traffic_type != 'spam')
				AND {$duration_sql} >= %d
				AND (CASE WHEN COALESCE({$scroll_alias}.page_count, 0) > 0 THEN COALESCE({$scroll_alias}.scroll_count, 0) ELSE COALESCE({$scroll_alias}_events.scroll_count, 0) END) >= %d
				AND (CASE WHEN COALESCE({$scroll_alias}.page_count, 0) > 0 THEN COALESCE({$scroll_alias}.click_count, 0) ELSE COALESCE({$recording_alias}.click_count, 0) END) >= %d)",
			'values'    => array( $spam_duration, $spam_scrolls, $spam_clicks ),
			'cache_key' => 'enabled_' . $spam_duration . '_' . $spam_scrolls . '_' . $spam_clicks,
		);
	}

	/**
	 * Return recording session IDs allowed by the global spam filter.
	 *
	 * @param array $page_ids Canonical page IDs.
	 * @return array Session IDs.
	 */
	private function get_spam_allowed_session_ids( $page_ids ) {
		global $wpdb;

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return array();
		}

		$traffic_settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_duration_threshold'    => 3,
				'spam_min_scrolls_threshold' => 0,
				'spam_min_clicks_threshold'  => 1,
			)
		);

		$spam_duration = isset( $traffic_settings['spam_duration_threshold'] ) ? max( 0, intval( $traffic_settings['spam_duration_threshold'] ) ) : 3;
		$spam_scrolls  = isset( $traffic_settings['spam_min_scrolls_threshold'] ) ? max( 0, intval( $traffic_settings['spam_min_scrolls_threshold'] ) ) : 0;
		$spam_clicks   = isset( $traffic_settings['spam_min_clicks_threshold'] ) ? max( 0, intval( $traffic_settings['spam_min_clicks_threshold'] ) ) : 1;

		$sessions_table      = $wpdb->prefix . 'optibehavior_sessions';
		$session_pages_table = $wpdb->prefix . 'optibehavior_session_pages';
		$recordings_table    = $wpdb->prefix . 'optibehavior_recordings';
		$page_clause         = $this->build_page_ids_clause( 'sp.page_id', $page_ids );

		$allowed = array();

		// Path A: recording-backed sessions passing the Pro spam thresholds (Pro only; no rows on free).
		if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) && method_exists( 'Opti_Behavior_Stats_Spam_Filter', 'recordings_threshold_sql' ) && $this->heatmap_table_exists( $recordings_table ) ) {
			$spam_filter = Opti_Behavior_Stats_Spam_Filter::recordings_threshold_sql( 'r', 's', 'hm_allowed_spam_counts', true );
			$spam_where  = ! empty( $spam_filter['where'] ) ? ' AND ' . $spam_filter['where'] : '';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names from $wpdb->prefix; WHERE built from prepared placeholders bound via array.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$allowed = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT r.session_id
					FROM {$recordings_table} r
					INNER JOIN {$session_pages_table} sp ON sp.session_id = r.session_id
					LEFT JOIN {$sessions_table} s ON s.id = r.session_id
					{$spam_filter['join']}
					WHERE {$page_clause}{$spam_where}",
					array_merge( $page_ids, $spam_filter['values'] )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// Path B: session_pages-backed sessions passing duration/scroll/click thresholds.
		// Always run so legit human sessions without a Pro recording are admitted; union with Path A.
		$params = array_merge( $page_ids, array( $spam_duration, $spam_scrolls, $spam_clicks ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$allowed_pages = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT sp.session_id
				FROM {$session_pages_table} sp
				LEFT JOIN {$sessions_table} s ON s.id = sp.session_id
				WHERE {$page_clause}
					AND (s.traffic_type IS NULL OR s.traffic_type = '' OR s.traffic_type NOT IN ('spam','bot','automated'))
				GROUP BY sp.session_id
				HAVING GREATEST(
						MAX(COALESCE(s.duration, 0)),
						MAX(COALESCE(TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time)), 0)),
						MAX(COALESCE(sp.duration, 0))
					) >= %d
					AND MAX(COALESCE(sp.scroll_depth, 0)) >= %d
					AND SUM(COALESCE(sp.clicks_count, 0)) >= %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// Path C (Free fallback): the Free build records rows in the `sessions`
		// table but writes NO `session_pages` rows, so Path A/B both come back
		// empty and the file-only heatmap gets wrongly nuked by the spam gate.
		// When both prior paths are empty, derive the allow-list straight from
		// the `sessions` table (every session not classified spam/bot/automated).
		// The downstream gate still intersects this against the page's actual
		// files, so admitting non-spam session IDs here can't leak other pages.
		$allowed_sessions = array();
		if ( empty( $allowed ) && empty( $allowed_pages ) && $this->heatmap_table_exists( $sessions_table ) ) {
			// phpcs:disable
			$allowed_sessions = $wpdb->get_col(
				"SELECT id FROM {$sessions_table}
				WHERE (traffic_type IS NULL OR traffic_type = '' OR traffic_type NOT IN ('spam','bot','automated'))"
			);
			// phpcs:enable
		}

		return array_values( array_unique( array_merge( (array) $allowed, (array) $allowed_pages, (array) $allowed_sessions ) ) );
	}

	/**
	 * Build a quick lookup map for allowed session IDs, including filename suffixes.
	 *
	 * @param array $session_ids Session IDs.
	 * @return array Lookup map.
	 */
	private function build_session_lookup_map( $session_ids ) {
		$map = array();
		foreach ( (array) $session_ids as $session_id ) {
			$session_id = (string) $session_id;
			if ( '' === $session_id ) {
				continue;
			}

			$clean = preg_replace( '/[^a-zA-Z0-9]/', '', $session_id );
			$map[ $session_id ] = true;
			$map[ $clean ]      = true;
			$map[ substr( $clean, -8 ) ] = true;
		}

		return $map;
	}

	/**
	 * Check whether a file-storage session passes the spam lookup.
	 *
	 * @param string $session_id Session ID parsed from filename.
	 * @param array  $lookup     Allowed lookup map.
	 * @return bool
	 */
	private function is_session_allowed_by_lookup( $session_id, $lookup ) {
		if ( empty( $lookup ) ) {
			return true;
		}

		return $this->is_session_in_lookup_strict( $session_id, $lookup );
	}

	/**
	 * Raw 3-form membership test against a session lookup map, with NO "empty
	 * lookup means unrestricted" shortcut (unlike is_session_allowed_by_lookup()).
	 * An empty lookup here always means "not present" — mirrors Free's
	 * is_heatmap_session_in_lookup() (RC-C, spec §3.2).
	 *
	 * @param string $session_id Session ID parsed from filename.
	 * @param array  $lookup     Lookup map (spam allow-list or known-session shape).
	 * @return bool
	 */
	private function is_session_in_lookup_strict( $session_id, $lookup ) {
		if ( empty( $lookup ) ) {
			return false;
		}

		$session_id = (string) $session_id;
		$clean      = preg_replace( '/[^a-zA-Z0-9]/', '', $session_id );

		return isset( $lookup[ $session_id ] ) || isset( $lookup[ $clean ] ) || isset( $lookup[ substr( $clean, -8 ) ] );
	}

	/**
	 * Orphan-aware spam gate (RC-C, spec §3.2):
	 *
	 *   allowed(token) = in_spam_allowlist(token) OR NOT known_db_session(token)
	 *
	 * A session already vouched for by the spam allow-list is always admitted.
	 * Otherwise, when the DB has NO trace of the token at all (per the known
	 * lookup from the Free bridge), it cannot be evidenced as spam — its JSON
	 * file is the only evidence and it must count. A session the DB DOES know
	 * about but that failed the allow-list gate is genuinely filtered spam.
	 *
	 * @param string     $session_id   Session ID parsed from filename.
	 * @param array      $spam_lookup  Spam allow-list lookup map.
	 * @param array|true $known_lookup Known-DB-session lookup map (bridge
	 *                                 result), or `true` fail-safe (every
	 *                                 token treated as known — no orphans).
	 * @return bool
	 */
	private function is_session_allowed_or_orphan_by_lookup( $session_id, $spam_lookup, $known_lookup ) {
		if ( $this->is_session_in_lookup_strict( $session_id, $spam_lookup ) ) {
			return true;
		}

		if ( true === $known_lookup ) {
			return false;
		}

		return ! $this->is_session_in_lookup_strict( $session_id, (array) $known_lookup );
	}

	/**
	 * Fetch the "known DB session" lookup for a set of page IDs via the Free
	 * dashboard bridge (Pro -> Free, mirrors the spam allow-list bridge in
	 * get_recordings_by_device()). Merges per-page results across $page_ids;
	 * if ANY page's resolver could not restrict safely, the merged result
	 * fails safe to `true` (conservative: no orphan admission for the whole
	 * group rather than risk admitting a real spam session).
	 *
	 * @param array $page_ids Canonical page IDs.
	 * @return array|true|null 3-form lookup map, boolean `true` fail-safe, or
	 *                         `null` when the bridge itself is unavailable
	 *                         (Free inactive / older Free version) — callers
	 *                         must keep their pre-orphan-gate fallback behavior.
	 */
	private function get_known_db_session_lookup_via_bridge( $page_ids ) {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			return null;
		}

		$free_core      = Opti_Behavior_Heatmap_Core::get_instance();
		$free_dashboard = ( $free_core && method_exists( $free_core, 'get_dashboard' ) ) ? $free_core->get_dashboard() : null;
		if ( ! $free_dashboard || ! method_exists( $free_dashboard, 'get_heatmap_known_db_session_lookup_for_page_bridge' ) ) {
			return null;
		}

		$merged = array();
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) ) as $bridge_page_id ) {
			$lookup = $free_dashboard->get_heatmap_known_db_session_lookup_for_page_bridge( $bridge_page_id );
			if ( true === $lookup ) {
				// Fail-safe for this page: cannot restrict safely — do not risk
				// admitting a real spam session from a different page in the
				// group, fail safe for the whole batch.
				return true;
			}
			if ( is_array( $lookup ) ) {
				$merged += $lookup;
			}
		}

		return $merged;
	}

	/**
	 * Direct DB "known session" lookup, used when the Pro->Free bridge is
	 * unavailable (bridge-less Free build: get_known_db_session_lookup_via_bridge()
	 * returns null). Reads session tokens straight from the session_pages table
	 * for the given pages so orphan admission still works without the bridge.
	 * A session with a DB row is "known"; a file token absent here is an orphan
	 * (its JSON file is the only evidence, so it must count).
	 *
	 * @param array $page_ids Canonical page IDs.
	 * @return array|true 3-form lookup map (session_id => true), or `true`
	 *                    fail-safe when the table is missing / query fails.
	 */
	private function get_known_db_session_lookup_direct( $page_ids ) {
		global $wpdb;

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return array();
		}

		$session_pages_table = $wpdb->prefix . 'optibehavior_session_pages';
		if ( ! $this->heatmap_table_exists( $session_pages_table ) ) {
			// Cannot restrict safely -> fail safe (no orphan admission).
			return true;
		}

		$page_clause = $this->build_page_ids_clause( 'page_id', $page_ids );

		// phpcs:disable
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT session_id FROM {$session_pages_table} WHERE {$page_clause}",
				$page_ids
			)
		);
		// phpcs:enable

		if ( null === $ids ) {
			// Query error -> fail safe.
			return true;
		}

		return $this->build_session_lookup_map( $ids );
	}

	/**
	 * Orphan file-session tokens for a page group's file universe (RC-C, spec
	 * §3.2): tokens found in the heatmap file storage with NO trace in the DB
	 * at all, per the known-session bridge. Used to admit orphan sessions into
	 * the session_ids/allowed_session_lookup filters that gate the fast-path
	 * render and filter-option scanners (those consult a pre-built allow-list
	 * rather than testing each session inline like count_events_from_files()/
	 * get_recordings_by_device() do).
	 *
	 * Filename-only scan (no JSON body reads) across all 3 event-type folders
	 * of every given url_hash dir — same cost class as the filter-options scan
	 * that already runs on every detail page load.
	 *
	 * @param array  $page_ids        Canonical page IDs (for the bridge lookup).
	 * @param array  $url_hashes      URL hash dirs to scan (raw hashes, not paths).
	 * @param object $heatmap_storage Opti_Behavior_Heatmap_Storage instance
	 *                                (for parse_filename_metadata()).
	 * @return array Orphan session id tokens (raw filename form). Empty when
	 *               the bridge is unavailable or fails safe — callers then add
	 *               nothing to their allow-list, i.e. current (no orphan
	 *               admission) behavior for that page group.
	 */
	private function get_orphan_session_tokens_via_bridge( $page_ids, $url_hashes, $heatmap_storage ) {
		$memo_key = implode( ',', array_map( 'absint', (array) $page_ids ) ) . '|' . implode( ',', (array) $url_hashes );
		if ( isset( $this->hm_orphan_tokens_memo[ $memo_key ] ) ) {
			return $this->hm_orphan_tokens_memo[ $memo_key ];
		}

		$orphans = array_keys( $this->get_orphan_session_metadata_via_bridge( $page_ids, $url_hashes, $heatmap_storage ) );

		$this->hm_orphan_tokens_memo[ $memo_key ] = $orphans;
		return $orphans;
	}

	/**
	 * Orphan file-session metadata for a page group's file universe (RC-C,
	 * spec §3.2 + dropdown/badge follow-up): parsed filename metadata
	 * (device, visitor marker, country, browser, timestamp, A-B variant) for
	 * every file-session token with NO trace in the DB at all, keyed by
	 * session token. Superset data source behind
	 * {@see get_orphan_session_tokens_via_bridge()} — this version keeps the
	 * per-session facts so callers can bucket orphan sessions into
	 * device/visitor-type/country/browser counters (the JSON filename
	 * already carries the device part and the G|L guest/logged marker, per
	 * spec) instead of only admitting them past a spam gate.
	 *
	 * @param array  $page_ids        Canonical page IDs (for the bridge lookup).
	 * @param array  $url_hashes      URL hash dirs to scan (raw hashes, not paths).
	 * @param object $heatmap_storage Opti_Behavior_Heatmap_Storage instance
	 *                                (for parse_filename_metadata()).
	 * @return array session_id => metadata array (see
	 *               Opti_Behavior_Heatmap_Storage::parse_filename_metadata()).
	 *               Empty when the bridge is unavailable or fails safe.
	 */
	private function get_orphan_session_metadata_via_bridge( $page_ids, $url_hashes, $heatmap_storage ) {
		if ( empty( $url_hashes ) || ! $heatmap_storage || ! method_exists( $heatmap_storage, 'parse_filename_metadata' ) ) {
			return array();
		}

		$known_lookup = $this->get_known_db_session_lookup_via_bridge( $page_ids );
		if ( null === $known_lookup ) {
			// Bridge-less Free build: consult the DB tables directly so orphan
			// admission still works (else every file session with no DB row is
			// wrongly dropped by the spam gate, e.g. Free file-only heatmaps).
			$known_lookup = $this->get_known_db_session_lookup_direct( $page_ids );
		}
		if ( true === $known_lookup ) {
			return array();
		}

		$memo_key = implode( ',', array_map( 'absint', (array) $page_ids ) ) . '|' . implode( ',', (array) $url_hashes );
		if ( isset( $this->hm_orphan_meta_memo[ $memo_key ] ) ) {
			return $this->hm_orphan_meta_memo[ $memo_key ];
		}

		$upload_dir = wp_upload_dir();
		$meta_map   = array();
		foreach ( (array) $url_hashes as $url_hash ) {
			if ( ! $url_hash ) {
				continue;
			}
			foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder_name ) {
				$dir = $upload_dir['basedir'] . '/opti-behavior-data/' . $url_hash . '/' . $folder_name;
				if ( ! is_dir( $dir ) ) {
					continue;
				}
				foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
					$meta = $heatmap_storage->parse_filename_metadata( basename( $file ) );
					if ( ! $meta || empty( $meta['session_id'] ) ) {
						continue;
					}
					$token = (string) $meta['session_id'];
					if ( ! isset( $meta_map[ $token ] ) ) {
						// First file wins; device/visitor/country/browser tokens
						// are the same across a session's click/move/scroll files
						// in practice, and an exact match isn't required here —
						// only "roughly the same session" for bucketing purposes.
						$meta_map[ $token ] = $meta;
					}
				}
			}
		}

		$orphans = array();
		foreach ( $meta_map as $token => $meta ) {
			if ( ! $this->is_session_in_lookup_strict( $token, (array) $known_lookup ) ) {
				$orphans[ $token ] = $meta;
			}
		}

		$this->hm_orphan_meta_memo[ $memo_key ] = $orphans;
		return $orphans;
	}

	/**
	 * Orphan sessions matching the caller's active detail-page filters, for
	 * merging into a DB canonical count (device badges / visitor-type or
	 * country/browser dropdown "(N)" counts). Applies the same predicates
	 * the DB scope builders and the get_recordings_by_device() file-scan
	 * fallback use (date range, country/browser multi-select, visitor type,
	 * A-B variant, device, "Delete Heatmap Data" reset), so an orphan
	 * session is only added to a bucket it would actually belong to under
	 * the current view — never a shortcut that inflates every bucket by the
	 * same orphan count.
	 *
	 * @param array  $page_ids   Canonical page IDs.
	 * @param string $date_range Date range.
	 * @param array  $filters    Filters (country/browser/visitor_type/ab_test_id/variant_id/start_date/end_date).
	 * @param string $device     Device scope to filter on ('' = every device, used by device-bucket callers).
	 * @param string $skip_dim   Dimension key whose own filter must NOT be applied
	 *                           ('visitor_type', 'country', or 'browser' — the
	 *                           dropdown must keep showing every option's count).
	 * @return array session_id => metadata array, sessions passing every
	 *               other active filter.
	 */
	private function get_matching_orphan_sessions( $page_ids, $date_range, $filters, $device = '', $skip_dim = '' ) {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			return array();
		}
		$heatmap_storage = Opti_Behavior_Heatmap_Storage::get_instance();
		if ( ! $heatmap_storage ) {
			return array();
		}

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return array();
		}

		$url_hashes = array();
		foreach ( $page_ids as $pid ) {
			foreach ( $heatmap_storage->get_url_hashes_for_page( $pid ) as $url_hash ) {
				if ( $url_hash ) {
					$url_hashes[] = $url_hash;
				}
			}
		}
		$url_hashes = array_values( array_unique( $url_hashes ) );
		if ( empty( $url_hashes ) ) {
			return array();
		}

		$orphan_meta = $this->get_orphan_session_metadata_via_bridge( $page_ids, $url_hashes, $heatmap_storage );
		if ( empty( $orphan_meta ) ) {
			return array();
		}

		$date_boundaries  = $this->get_date_boundaries( $date_range, $filters );
		$filter_countries = ( 'country' === $skip_dim ) ? array() : $this->normalize_heatmap_multi_select_filter( $filters['country'] ?? '', 'upper' );
		$filter_browsers  = ( 'browser' === $skip_dim ) ? array() : $this->normalize_heatmap_multi_select_filter( $filters['browser'] ?? '', 'lower' );
		$filter_visitor_type = ( 'visitor_type' === $skip_dim || empty( $filters['visitor_type'] ) ) ? '' : strtolower( $filters['visitor_type'] );
		$filter_ab_test_id   = ! empty( $filters['ab_test_id'] ) ? (int) $filters['ab_test_id'] : 0;
		$filter_variant_id   = ! empty( $filters['variant_id'] ) ? (int) $filters['variant_id'] : 0;

		// "Delete Heatmap Data" reset: drop orphan events captured before the
		// page's reset timestamp, same as build_session_page_reset_clause().
		$resets    = get_option( 'opti_behavior_heatmap_page_resets', array() );
		$reset_min = null;
		if ( is_array( $resets ) ) {
			foreach ( $page_ids as $pid ) {
				if ( ! empty( $resets[ $pid ] ) ) {
					$reset_ts  = strtotime( $resets[ $pid ] );
					$reset_min = ( null === $reset_min ) ? $reset_ts : min( $reset_min, $reset_ts );
				}
			}
		}

		$matched = array();
		foreach ( $orphan_meta as $session_id => $meta ) {
			$timestamp = isset( $meta['timestamp'] ) ? (int) $meta['timestamp'] : 0;

			if ( $date_boundaries['start'] && $timestamp < $date_boundaries['start'] ) {
				continue;
			}
			if ( $date_boundaries['end'] && $timestamp > $date_boundaries['end'] ) {
				continue;
			}
			if ( null !== $reset_min && $timestamp < $reset_min ) {
				continue;
			}

			if ( ! empty( $filter_countries ) && ! in_array( strtoupper( (string) ( $meta['country'] ?? '' ) ), $filter_countries, true ) ) {
				continue;
			}
			if ( ! empty( $filter_browsers ) && ! in_array( strtolower( (string) ( $meta['browser'] ?? '' ) ), $filter_browsers, true ) ) {
				continue;
			}

			if ( $filter_visitor_type ) {
				$is_logged_in = ! empty( $meta['is_logged_in'] );
				if ( 'logged_in' === $filter_visitor_type && ! $is_logged_in ) {
					continue;
				}
				if ( 'guest' === $filter_visitor_type && $is_logged_in ) {
					continue;
				}
			}

			if ( $filter_ab_test_id > 0 && $filter_variant_id > 0 ) {
				if ( (int) ( $meta['ab_test_id'] ?? 0 ) !== $filter_ab_test_id || (int) ( $meta['variant_id'] ?? 0 ) !== $filter_variant_id ) {
					continue;
				}
			}

			if ( ! $this->is_all_device_scope( $device ) ) {
				if ( $this->normalize_heatmap_device_bucket( $meta['device'] ?? '' ) !== strtolower( $device ) ) {
					continue;
				}
			}

			$matched[ $session_id ] = $meta;
		}

		return $matched;
	}

	/**
	 * Admissible file-session metadata for a page group's file universe —
	 * full-parity follow-up: the same broader gate
	 * count_heatmap_file_sessions_for_page_by_device() (the header pill's own
	 * file scan, ground truth for file_total_all) uses, not just the narrow
	 * "zero DB trace" orphan subset {@see get_orphan_session_metadata_via_bridge()}.
	 * A file-session token is admitted when it is spam-allow-listed (Pro's own
	 * recordings-threshold query unioned with Free's session_pages+pageviews
	 * bridge allow-list) OR has no confirmed-spam trace in the DB at all —
	 * this also readmits sessions that ARE known in wp_optibehavior_sessions
	 * but lack a session_pages row for the page/query in question (cascade
	 * gaps), which the narrow orphan gate structurally excludes because it
	 * requires zero DB trace anywhere.
	 *
	 * When $exclude_spam is false the caller's scope itself does not filter
	 * spam (mirrors build_session_page_scope_sql()'s own spam-blind
	 * behavior in that case), so every file-session token is admitted
	 * unconditionally — same "no gate" semantics as the DB scope.
	 *
	 * @param array  $page_ids        Canonical page IDs (for the bridge/spam lookups).
	 * @param array  $url_hashes      URL hash dirs to scan (raw hashes, not paths).
	 * @param object $heatmap_storage Opti_Behavior_Heatmap_Storage instance
	 *                                (for parse_filename_metadata()).
	 * @param bool   $exclude_spam    Whether the caller's active filters exclude spam.
	 * @return array session_id => metadata array, admitted tokens only.
	 */
	private function get_admissible_file_session_metadata_via_bridge( $page_ids, $url_hashes, $heatmap_storage, $exclude_spam ) {
		if ( empty( $url_hashes ) || ! $heatmap_storage || ! method_exists( $heatmap_storage, 'parse_filename_metadata' ) ) {
			return array();
		}

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		sort( $page_ids );
		$hash_key = array_values( (array) $url_hashes );
		sort( $hash_key );
		$memo_key = implode( ',', $page_ids ) . '|' . implode( ',', $hash_key ) . '|' . ( $exclude_spam ? '1' : '0' );
		if ( isset( $this->hm_admissible_meta_memo[ $memo_key ] ) ) {
			return $this->hm_admissible_meta_memo[ $memo_key ];
		}

		$spam_lookup  = array();
		$known_lookup = null;
		if ( $exclude_spam ) {
			$spam_session_ids = $this->get_spam_allowed_session_ids( $page_ids );

			// Bridge (Pro -> Free): same allow-list union the file-scan
			// fallback in get_recordings_by_device() uses, so the admission
			// gate here matches the pill's own count exactly.
			if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
				$free_core      = Opti_Behavior_Heatmap_Core::get_instance();
				$free_dashboard = ( $free_core && method_exists( $free_core, 'get_dashboard' ) ) ? $free_core->get_dashboard() : null;
				if ( $free_dashboard && method_exists( $free_dashboard, 'get_heatmap_session_allowlist_for_page_bridge' ) ) {
					foreach ( $page_ids as $bridge_page_id ) {
						$bridge_lookup = $free_dashboard->get_heatmap_session_allowlist_for_page_bridge( $bridge_page_id );
						if ( ! empty( $bridge_lookup ) && is_array( $bridge_lookup ) ) {
							$spam_session_ids = array_merge( $spam_session_ids, array_keys( $bridge_lookup ) );
						}
					}
				}
			}

			$spam_lookup  = $this->build_session_lookup_map( array_values( array_unique( $spam_session_ids ) ) );
			$known_lookup = $this->get_known_db_session_lookup_via_bridge( $page_ids );
		}

		$upload_dir = wp_upload_dir();
		$meta_map   = array();
		foreach ( (array) $url_hashes as $url_hash ) {
			if ( ! $url_hash ) {
				continue;
			}
			foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder_name ) {
				$dir = $upload_dir['basedir'] . '/opti-behavior-data/' . $url_hash . '/' . $folder_name;
				if ( ! is_dir( $dir ) ) {
					continue;
				}
				foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
					$meta = $heatmap_storage->parse_filename_metadata( basename( $file ) );
					if ( ! $meta || empty( $meta['session_id'] ) ) {
						continue;
					}
					$token = (string) $meta['session_id'];
					if ( isset( $meta_map[ $token ] ) ) {
						continue;
					}
					if ( $exclude_spam && null !== $known_lookup
						&& ! $this->is_session_allowed_or_orphan_by_lookup( $token, $spam_lookup, $known_lookup ) ) {
						continue;
					}
					$meta_map[ $token ] = $meta;
				}
			}
		}

		$this->hm_admissible_meta_memo[ $memo_key ] = $meta_map;
		return $meta_map;
	}

	/**
	 * Session IDs a specific DB scope query already returns — full-parity
	 * follow-up: reuses build_session_page_scope_sql() (the exact scope the
	 * canonical visitor-type/dimension/device counters query) so the
	 * admissible file-session merge can skip any token the DB query already
	 * counted for THIS page/date/filter/device combination, preventing
	 * double-counting for sessions that are DB-known under a broader scope
	 * but still uncovered here (or vice versa).
	 *
	 * @param array  $page_ids   Canonical page IDs.
	 * @param string $date_range Date range.
	 * @param array  $filters    Active filters (same shape build_session_page_scope_sql() takes).
	 * @param string $device     Device scope ('' = every device).
	 * @return array 3-form session-id lookup map (raw/cleaned/last-8-chars),
	 *               same shape as build_session_lookup_map().
	 */
	private function get_covered_session_lookup_via_scope( array $page_ids, $date_range, $filters, $device = '' ) {
		global $wpdb;

		$table_session_pages = $wpdb->prefix . 'optibehavior_session_pages';
		$table_sessions      = $wpdb->prefix . 'optibehavior_sessions';
		$table_visitors      = $wpdb->prefix . 'optibehavior_visitors';

		if ( empty( $page_ids ) || ! $this->heatmap_table_exists( $table_session_pages ) ) {
			return array();
		}

		$memo_key = implode( ',', $page_ids ) . '|' . $date_range . '|' . wp_json_encode( $filters ) . '|' . $device;
		if ( isset( $this->hm_covered_lookup_memo[ $memo_key ] ) ) {
			return $this->hm_covered_lookup_memo[ $memo_key ];
		}

		$scope = $this->build_session_page_scope_sql( $page_ids, $date_range, $filters, $device );
		$query = "
			SELECT DISTINCT sp.session_id
			FROM {$table_session_pages} sp
			{$scope['recording_join']}
			LEFT JOIN {$table_sessions} s ON s.id = sp.session_id
			LEFT JOIN {$table_visitors} v ON v.id = s.visitor_id
			WHERE {$scope['where']}
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fragments built from $wpdb->prefix + prepared placeholders; params supplied below.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$ids    = $wpdb->get_col( $wpdb->prepare( $query, $scope['params'] ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$lookup = is_array( $ids ) ? $this->build_session_lookup_map( $ids ) : array();

		$this->hm_covered_lookup_memo[ $memo_key ] = $lookup;
		return $lookup;
	}

	/**
	 * Admissible file sessions matching the caller's active detail-page
	 * filters AND not already covered by the DB scope for this exact
	 * page/date/filter/device combination — full-parity follow-up (dropdown
	 * + badges must partition the same file_total_all universe the header
	 * pill counts). Superset of {@see get_matching_orphan_sessions()}: also
	 * readmits sessions that are DB-known elsewhere but structurally
	 * invisible to THIS scope's session_pages join (cascade gaps), while
	 * still excluding confirmed-spam sessions and never double-counting a
	 * session the DB query already returned.
	 *
	 * @param array  $page_ids   Canonical page IDs.
	 * @param string $date_range Date range.
	 * @param array  $filters    Filters (country/browser/visitor_type/ab_test_id/variant_id/start_date/end_date/exclude_spam).
	 * @param string $device     Device scope to filter on ('' = every device, used by device-bucket callers).
	 * @param string $skip_dim   Dimension key whose own filter must NOT be applied
	 *                           ('visitor_type', 'country', or 'browser' — the
	 *                           dropdown must keep showing every option's count).
	 * @return array session_id => metadata array, sessions passing every
	 *               other active filter and not already DB-covered.
	 */
	private function get_matching_admissible_file_sessions( $page_ids, $date_range, $filters, $device = '', $skip_dim = '' ) {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			return array();
		}
		$heatmap_storage = Opti_Behavior_Heatmap_Storage::get_instance();
		if ( ! $heatmap_storage ) {
			return array();
		}

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return array();
		}

		$url_hashes = array();
		foreach ( $page_ids as $pid ) {
			foreach ( $heatmap_storage->get_url_hashes_for_page( $pid ) as $url_hash ) {
				if ( $url_hash ) {
					$url_hashes[] = $url_hash;
				}
			}
		}
		$url_hashes = array_values( array_unique( $url_hashes ) );
		if ( empty( $url_hashes ) ) {
			return array();
		}

		$admissible_meta = $this->get_admissible_file_session_metadata_via_bridge(
			$page_ids,
			$url_hashes,
			$heatmap_storage,
			! empty( $filters['exclude_spam'] )
		);
		if ( empty( $admissible_meta ) ) {
			return array();
		}

		$covered_lookup = $this->get_covered_session_lookup_via_scope( $page_ids, $date_range, $filters, $device );

		$date_boundaries      = $this->get_date_boundaries( $date_range, $filters );
		$filter_countries     = ( 'country' === $skip_dim ) ? array() : $this->normalize_heatmap_multi_select_filter( $filters['country'] ?? '', 'upper' );
		$filter_browsers      = ( 'browser' === $skip_dim ) ? array() : $this->normalize_heatmap_multi_select_filter( $filters['browser'] ?? '', 'lower' );
		$filter_visitor_type  = ( 'visitor_type' === $skip_dim || empty( $filters['visitor_type'] ) ) ? '' : strtolower( $filters['visitor_type'] );
		$filter_ab_test_id    = ! empty( $filters['ab_test_id'] ) ? (int) $filters['ab_test_id'] : 0;
		$filter_variant_id    = ! empty( $filters['variant_id'] ) ? (int) $filters['variant_id'] : 0;

		// "Delete Heatmap Data" reset: drop file-session events captured
		// before the page's reset timestamp, same as
		// build_session_page_reset_clause().
		$resets    = get_option( 'opti_behavior_heatmap_page_resets', array() );
		$reset_min = null;
		if ( is_array( $resets ) ) {
			foreach ( $page_ids as $pid ) {
				if ( ! empty( $resets[ $pid ] ) ) {
					$reset_ts  = strtotime( $resets[ $pid ] );
					$reset_min = ( null === $reset_min ) ? $reset_ts : min( $reset_min, $reset_ts );
				}
			}
		}

		$matched = array();
		foreach ( $admissible_meta as $session_id => $meta ) {
			// Never double-count: skip any token the DB scope for this exact
			// query already returned a session_pages row for.
			if ( ! empty( $covered_lookup ) && $this->is_session_in_lookup_strict( $session_id, $covered_lookup ) ) {
				continue;
			}

			$timestamp = isset( $meta['timestamp'] ) ? (int) $meta['timestamp'] : 0;

			if ( $date_boundaries['start'] && $timestamp < $date_boundaries['start'] ) {
				continue;
			}
			if ( $date_boundaries['end'] && $timestamp > $date_boundaries['end'] ) {
				continue;
			}
			if ( null !== $reset_min && $timestamp < $reset_min ) {
				continue;
			}

			if ( ! empty( $filter_countries ) && ! in_array( strtoupper( (string) ( $meta['country'] ?? '' ) ), $filter_countries, true ) ) {
				continue;
			}
			if ( ! empty( $filter_browsers ) && ! in_array( strtolower( (string) ( $meta['browser'] ?? '' ) ), $filter_browsers, true ) ) {
				continue;
			}

			if ( $filter_visitor_type ) {
				$is_logged_in = ! empty( $meta['is_logged_in'] );
				if ( 'logged_in' === $filter_visitor_type && ! $is_logged_in ) {
					continue;
				}
				if ( 'guest' === $filter_visitor_type && $is_logged_in ) {
					continue;
				}
			}

			if ( $filter_ab_test_id > 0 && $filter_variant_id > 0 ) {
				if ( (int) ( $meta['ab_test_id'] ?? 0 ) !== $filter_ab_test_id || (int) ( $meta['variant_id'] ?? 0 ) !== $filter_variant_id ) {
					continue;
				}
			}

			if ( ! $this->is_all_device_scope( $device ) ) {
				if ( $this->normalize_heatmap_device_bucket( $meta['device'] ?? '' ) !== strtolower( $device ) ) {
					continue;
				}
			}

			$matched[ $session_id ] = $meta;
		}

		return $matched;
	}

	/**
	 * Device-bucketed count of HEATMAP-captured sessions (the header pill's
	 * universe) under the active detail-page filters — the source for the
	 * device chips.
	 *
	 * Master decision: every heatmap surface counts ONE universe — guest
	 * spam-excluded sessions that actually produced heatmap event files. The
	 * pill shows that universe all-time; the chips show the SAME universe
	 * reactive to the active filters (date range / country / browser /
	 * visitor type / A-B / device). This makes chips a strict subset of the
	 * pill (chips <= pill always), unlike the old recordings-universe
	 * (session_pages join) counter which could exceed the all-time pill.
	 *
	 * Counts every device bucket regardless of the active device toggle (the
	 * toggle only highlights a chip); date/country/browser/visitor/A-B do
	 * filter the set. No DB-covered exclusion — this is the pure file
	 * universe, not a DB union.
	 *
	 * @param int|int[] $page_ids   Canonical page IDs (multi-variant group).
	 * @param string    $date_range Date range key.
	 * @param array     $filters    Active filters.
	 * @return array{desktop:int,mobile:int,tablet:int,total:int}
	 */
	private function get_heatmap_session_device_counts( $page_ids, $date_range, $filters ) {
		$empty = array(
			'desktop' => 0,
			'mobile'  => 0,
			'tablet'  => 0,
			'total'   => 0,
		);

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return $empty;
		}

		// CANONICAL SESSION UNIVERSE (master decision 2026-08-13, plan step 216):
		// the device chips split the SAME canonical human pageview-session
		// universe as the header pill, reactive to the FULL active filter set
		// (period + advanced Filters panel: visitor type / browser / country / OS
		// / duration / entry & exit page / referrer / traffic channel / UTM). The
		// bottom bar is the filter-reactive surface; the header pill stays the
		// period total. With no filter active the bridge delegates to the
		// unfiltered counter so chips == pill (default state). Filtered: chips sum
		// == Views == filtered session count <= pill. Resolved via the Free
		// dashboard bridge (Pro -> Free).
		$period = in_array( $date_range, array( 'all', 'today', 'last7days', 'last30days', 'custom' ), true ) ? $date_range : 'all';
		$start  = isset( $filters['start_date'] ) ? (string) $filters['start_date'] : '';
		$end    = isset( $filters['end_date'] ) ? (string) $filters['end_date'] : '';

		$counts = $this->get_canonical_filtered_device_counts_via_bridge( $page_ids, $filters, $period, $start, $end );

		return is_array( $counts ) ? $counts : $empty;
	}

	/**
	 * Canonical device split for a heatmap page group via the Free dashboard
	 * bridge (Pro -> Free), same accessor pattern as
	 * get_heatmap_session_coverage_via_bridge(). Returns the human
	 * pageview-session universe split by device (desktop/mobile/tablet + total),
	 * reconciled so the buckets sum to the pill session total.
	 *
	 * @param int[]  $page_ids   Canonical page IDs.
	 * @param string $period     Standard period slug.
	 * @param string $start_date Custom start.
	 * @param string $end_date   Custom end.
	 * @return array{desktop:int,mobile:int,tablet:int,total:int}|null Null when
	 *                  the Free bridge is unavailable.
	 */
	private function get_canonical_device_counts_via_bridge( $page_ids, $period = 'all', $start_date = '', $end_date = '' ) {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			return null;
		}

		$free_core      = Opti_Behavior_Heatmap_Core::get_instance();
		$free_dashboard = ( $free_core && method_exists( $free_core, 'get_dashboard' ) ) ? $free_core->get_dashboard() : null;

		if ( $free_dashboard && method_exists( $free_dashboard, 'get_heatmap_canonical_device_counts' ) ) {
			$counts = $free_dashboard->get_heatmap_canonical_device_counts( $page_ids, $period, $start_date, $end_date );
			return is_array( $counts ) ? $counts : null;
		}

		return null;
	}

	/**
	 * Canonical device split under the FULL active filter set (period + advanced
	 * Filters panel) via the Free dashboard bridge (Pro -> Free) — the bottom
	 * bar's device chips + Views. Delegates to the unfiltered counter when no
	 * filter is active (chips == pill), so default state is unchanged. Falls back
	 * to the period-only counter when the filtered bridge is unavailable (older
	 * Free version).
	 *
	 * @param int[]  $page_ids   Canonical page IDs.
	 * @param array  $filters    Active filters (raw dropdown/panel values).
	 * @param string $period     Standard period slug.
	 * @param string $start_date Custom start.
	 * @param string $end_date   Custom end.
	 * @return array{desktop:int,mobile:int,tablet:int,total:int}|null Null when
	 *                  the Free bridge is unavailable.
	 */
	private function get_canonical_filtered_device_counts_via_bridge( $page_ids, $filters = array(), $period = 'all', $start_date = '', $end_date = '' ) {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			return null;
		}

		$free_core      = Opti_Behavior_Heatmap_Core::get_instance();
		$free_dashboard = ( $free_core && method_exists( $free_core, 'get_dashboard' ) ) ? $free_core->get_dashboard() : null;

		if ( $free_dashboard && method_exists( $free_dashboard, 'get_heatmap_canonical_filtered_device_counts' ) ) {
			$counts = $free_dashboard->get_heatmap_canonical_filtered_device_counts( $page_ids, $filters, $period, $start_date, $end_date );
			return is_array( $counts ) ? $counts : null;
		}

		// Legacy Free without the filtered bridge: fall back to period-only.
		return $this->get_canonical_device_counts_via_bridge( $page_ids, $period, $start_date, $end_date );
	}

	/**
	 * Canonical visitor-type split (guest / logged-in) for a heatmap page group
	 * via the Free dashboard bridge (Pro -> Free), same accessor pattern as
	 * get_canonical_device_counts_via_bridge(). Returns the human pageview-session
	 * universe split by visitor type, summing to the pill session total.
	 *
	 * @param int[]  $page_ids   Canonical page IDs.
	 * @param string $period     Standard period slug.
	 * @param string $start_date Custom start.
	 * @param string $end_date   Custom end.
	 * @return array{guest:int,logged_in:int,total:int}|null Null when the Free
	 *                  bridge is unavailable.
	 */
	private function get_canonical_visitor_type_counts_via_bridge( $page_ids, $period = 'all', $start_date = '', $end_date = '' ) {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			return null;
		}

		$free_core      = Opti_Behavior_Heatmap_Core::get_instance();
		$free_dashboard = ( $free_core && method_exists( $free_core, 'get_dashboard' ) ) ? $free_core->get_dashboard() : null;

		if ( $free_dashboard && method_exists( $free_dashboard, 'get_heatmap_canonical_visitor_type_counts' ) ) {
			$counts = $free_dashboard->get_heatmap_canonical_visitor_type_counts( $page_ids, $period, $start_date, $end_date );
			return is_array( $counts ) ? $counts : null;
		}

		return null;
	}

	/**
	 * Canonical per-value session counts for one filter dimension (country /
	 * browser / os / entry_page / exit_page / traffic_channel / referrer) via the
	 * Free dashboard bridge (Pro -> Free). Same canonical human pageview-session
	 * universe as the header pill, device chips and Visitor Type dropdown, so a
	 * dropdown option's count matches the pill instead of the legacy
	 * session_pages / heatmap-file token universe.
	 *
	 * @param int[]  $page_ids   Canonical page IDs (heatmap detail passes the group).
	 * @param string $dimension  country|browser|os|entry_page|exit_page|traffic_channel|referrer.
	 * @param string $period     Standard period slug.
	 * @param string $start_date Custom start.
	 * @param string $end_date   Custom end.
	 * @return array<string,int>|null Value => session-count map (may include an
	 *                  'Unknown' bucket for closed dimensions), or null when the
	 *                  Free bridge is unavailable.
	 */
	private function get_canonical_dimension_counts_via_bridge( $page_ids, $dimension, $period = 'all', $start_date = '', $end_date = '' ) {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			return null;
		}

		$free_core      = Opti_Behavior_Heatmap_Core::get_instance();
		$free_dashboard = ( $free_core && method_exists( $free_core, 'get_dashboard' ) ) ? $free_core->get_dashboard() : null;

		if ( $free_dashboard && method_exists( $free_dashboard, 'get_heatmap_canonical_dimension_counts' ) ) {
			$counts = $free_dashboard->get_heatmap_canonical_dimension_counts( $page_ids, $dimension, $period, $start_date, $end_date );
			return is_array( $counts ) ? $counts : null;
		}

		return null;
	}

	/**
	 * Canonical "Views" count for the stats strip: the human pageview-session
	 * count for the current device (or the whole group for an all-device scope),
	 * from the same canonical universe as the header pill and device chips. Keeps
	 * the "Views" KPI consistent with the pill instead of the hybrid file scan.
	 *
	 * @param int    $page_id    Primary page ID.
	 * @param string $device     Device scope ('', 'all', 'desktop', 'mobile', 'tablet').
	 * @param string $date_range Date range key.
	 * @param array  $filters    Active filters (page_ids, start_date, end_date).
	 * @return int|null Canonical view/session count, or null when the bridge is
	 *                  unavailable (caller keeps the file-scan view count).
	 */
	private function get_canonical_views_for_device( $page_id, $device, $date_range, $filters ) {
		$page_ids = $this->get_filter_page_ids( $page_id, $filters );
		if ( empty( $page_ids ) ) {
			return null;
		}

		$period = in_array( $date_range, array( 'all', 'today', 'last7days', 'last30days', 'custom' ), true ) ? $date_range : 'all';
		$start  = isset( $filters['start_date'] ) ? (string) $filters['start_date'] : '';
		$end    = isset( $filters['end_date'] ) ? (string) $filters['end_date'] : '';

		// Views follows the FULL active filter set (plan step 216) so the stats
		// strip's Views == the current device chip == the filtered session count.
		$counts = $this->get_canonical_filtered_device_counts_via_bridge( $page_ids, $filters, $period, $start, $end );
		if ( ! is_array( $counts ) ) {
			return null;
		}

		$device_lower = strtolower( (string) $device );
		if ( $this->is_all_device_scope( $device_lower ) ) {
			return isset( $counts['total'] ) ? (int) $counts['total'] : 0;
		}
		if ( 'phone' === $device_lower ) {
			$device_lower = 'mobile';
		}
		if ( isset( $counts[ $device_lower ] ) ) {
			return (int) $counts[ $device_lower ];
		}

		return isset( $counts['total'] ) ? (int) $counts['total'] : 0;
	}

	/**
	 * Normalize a raw filename device token to one of desktop/mobile/tablet,
	 * matching the bucketing rule used throughout this file (get_recordings_by_device()
	 * / count_events_from_files(): 'phone' => mobile, anything else unknown => desktop).
	 *
	 * @param string $raw_device Raw device token from a heatmap filename.
	 * @return string 'desktop'|'mobile'|'tablet'.
	 */
	private function normalize_heatmap_device_bucket( $raw_device ) {
		$device_lower = strtolower( (string) $raw_device );
		if ( in_array( $device_lower, array( 'desktop', 'mobile', 'tablet' ), true ) ) {
			return $device_lower;
		}
		if ( 'phone' === $device_lower ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * Get heatmap data AJAX handler.
	 */
	public function ajax_get_heatmap_data() {
		// Verify nonce.
		check_ajax_referer( 'opti_heatmap_detail', 'nonce' );

		// Verify permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'opti-behavior' ) ) );
		}

		if ( function_exists( 'opti_behavior_pro_require_access' ) ) {
			opti_behavior_pro_require_access( 'heatmap_detail' );
		}

		// Get parameters.
		$page_id      = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$page_ids     = $this->get_request_page_ids( $_POST, $page_id );
		$device       = isset( $_POST['device'] ) ? sanitize_text_field( wp_unslash( $_POST['device'] ) ) : 'desktop';
		$type         = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'click';
		$date_range   = isset( $_POST['date_range'] ) ? sanitize_text_field( wp_unslash( $_POST['date_range'] ) ) : 'all';
		$country      = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';
		$browser      = isset( $_POST['browser'] ) ? sanitize_text_field( wp_unslash( $_POST['browser'] ) ) : '';
		$visitor_type = isset( $_POST['visitor_type'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_type'] ) ) : '';

		// A/B test variant filtering.
		$ab_test_id = isset( $_POST['ab_test_id'] ) ? absint( $_POST['ab_test_id'] ) : 0;
		$variant_id = isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0;

		// Build filters array
		$filters = array(
			'country'      => $country,
			'browser'      => $browser,
			'visitor_type' => $visitor_type,
			'page_ids'     => $page_ids,
			'exclude_spam' => $this->resolve_spam_exclusion_from_request( $_POST ) ? 1 : 0,
		);
		$filters = $this->add_custom_date_filters( $filters, $date_range, $_POST );

		// Filter by A/B variant using direct filename metadata matching.
		if ( $ab_test_id && $variant_id ) {
			$filters['ab_test_id'] = $ab_test_id;
			$filters['variant_id'] = $variant_id;
		}

		if ( ! $page_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid page ID', 'opti-behavior' ) ) );
		}

		// Advanced filters (OS / UTM / duration / pages & traffic).
		$filters = array_merge( $filters, $this->collect_advanced_filter_params() );
		$filters = $this->apply_advanced_session_filters( $filters );

		// TEMP DEBUG: gated by ob_debug=1. Runs BEFORE cache so never served stale. Remove after diagnosis.
		if ( ! empty( $_POST['ob_debug'] ) ) {
			global $wpdb;
			$dbg = array( 'ob_debug' => 'v7-filters', 'page_ids' => $page_ids, 'exclude_spam' => $filters['exclude_spam'] );
			$dbg['filter_keys']       = array_keys( $filters );
			$dbg['pre_session_ids']   = isset( $filters['session_ids'] ) ? $filters['session_ids'] : '(unset)';
			$dbg['adv_dim_suffixes']  = isset( $filters['adv_dim_suffixes'] ) ? $filters['adv_dim_suffixes'] : '(unset)';

			// (1) DB allow-list (Path A/B/C union).
			$dbg_allowed          = $this->get_spam_allowed_session_ids( $page_ids );
			$dbg['allowed_count'] = count( (array) $dbg_allowed );
			$dbg['allowed_sample'] = array_slice( (array) $dbg_allowed, 0, 10 );
			// Dump full sessions table id+token columns to see the real key shape.
			// phpcs:disable
			$dbg['sessions_dump'] = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}optibehavior_sessions LIMIT 5", ARRAY_A );
			// phpcs:enable

			// (2) session_pages raw rows for these pages.
			$sessions_table      = $wpdb->prefix . 'optibehavior_sessions';
			$session_pages_table = $wpdb->prefix . 'optibehavior_session_pages';
			$sp_clause           = $this->build_page_ids_clause( 'page_id', $page_ids );
			// phpcs:disable
			$dbg['sp_row_count']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$session_pages_table} WHERE {$sp_clause}", $page_ids ) );
			$dbg['sp_total_rows'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$session_pages_table}" );
			$dbg['sess_total']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sessions_table}" );
			// phpcs:enable

			// (3) heatmap storage + url hashes + files + parsed tokens.
			$hs = class_exists( 'Opti_Behavior_Heatmap_Storage' ) ? Opti_Behavior_Heatmap_Storage::get_instance() : null;
			$dbg['has_storage'] = (bool) $hs;
			$url_hashes = array();
			if ( $hs ) {
				foreach ( $page_ids as $pid ) {
					foreach ( (array) $hs->get_url_hashes_for_page( $pid ) as $h ) {
						if ( $h ) { $url_hashes[] = $h; }
					}
				}
			}
			$url_hashes            = array_values( array_unique( $url_hashes ) );
			$dbg['url_hashes']     = $url_hashes;
			$upload_dir            = wp_upload_dir();
			$files_found           = array();
			$parsed_tokens         = array();
			if ( $hs && method_exists( $hs, 'parse_filename_metadata' ) ) {
				foreach ( $url_hashes as $h ) {
					foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder ) {
						$dir = $upload_dir['basedir'] . '/opti-behavior-data/' . $h . '/' . $folder;
						foreach ( (array) glob( $dir . '/*.json' ) as $f ) {
							$files_found[] = $folder . '/' . basename( $f );
							$m = $hs->parse_filename_metadata( basename( $f ) );
							if ( $m && ! empty( $m['session_id'] ) ) { $parsed_tokens[ (string) $m['session_id'] ] = true; }
						}
					}
				}
			}
			$dbg['files_found']    = array_slice( $files_found, 0, 20 );
			$dbg['file_count']     = count( $files_found );
			$dbg['parsed_tokens']  = array_keys( $parsed_tokens );

			// (4) bridge + direct known-lookup + orphan tokens.
			$bridge = $this->get_known_db_session_lookup_via_bridge( $page_ids );
			$dbg['bridge_type']    = ( null === $bridge ) ? 'null' : ( ( true === $bridge ) ? 'true(failsafe)' : 'array(' . count( (array) $bridge ) . ')' );
			$direct = $this->get_known_db_session_lookup_direct( $page_ids );
			$dbg['direct_type']    = ( true === $direct ) ? 'true(failsafe)' : 'array(' . count( (array) $direct ) . ')';
			if ( $hs ) {
				$orphan_tokens = $this->get_orphan_session_tokens_via_bridge( $page_ids, $url_hashes, $hs );
				$dbg['orphan_token_count'] = count( (array) $orphan_tokens );
				$dbg['orphan_tokens']      = array_slice( (array) $orphan_tokens, 0, 20 );
			}

			// (5) match test: does the allow-list lookup admit the file tokens?
			$lookup = $this->build_session_lookup_map( (array) $dbg_allowed );
			$match  = array();
			foreach ( array_keys( $parsed_tokens ) as $tok ) {
				$match[ (string) $tok ] = $this->is_session_allowed_by_lookup( $tok, $lookup ) ? 'ADMIT' : 'DROP';
			}
			$dbg['token_match'] = $match;

			// (6) run the actual fast path with the real filters and report.
			$fast_res = $this->generate_heatmap_data_fast( $page_id, $device, $type, $date_range, $filters );
			if ( false === $fast_res ) {
				$dbg['fast_result'] = 'FALSE (falls back to recordings)';
			} elseif ( is_array( $fast_res ) ) {
				$dbg['fast_result']        = 'array';
				$dbg['fast_coords']        = count( $fast_res['coordinates'] ?? array() );
				$dbg['fast_total_files']   = $fast_res['total_files'] ?? null;
				$dbg['fast_session_ids']   = $fast_res['_debug_session_ids'] ?? '(n/a)';
			} else {
				$dbg['fast_result'] = var_export( $fast_res, true );
			}

			wp_send_json_success( $dbg );
		}

		// Create cache key that includes filters
		$cache_key = $this->build_cache_key( $page_id, $device, $type, $date_range, $filters );

		// Check cache first, but validate it's not stale.
		$cached_data = $this->cache->get( $page_id, $device, $type, $date_range, $cache_key );

		// Validate cache freshness: check if recording count has changed.
		$current_recording_count = $this->get_recording_count( $page_id, $device, $date_range, $page_ids, $filters, $type );

		if ( false !== $cached_data ) {
			$cached_recording_count = isset( $cached_data['_cache_metadata']['recording_count'] ) ? $cached_data['_cache_metadata']['recording_count'] : 0;

			// If recording count has changed, invalidate the cache.
			if ( $cached_recording_count !== $current_recording_count ) {
				$this->debug_log( "[Opti-Behavior-Heatmap] Cache invalidated - recording count changed from {$cached_recording_count} to {$current_recording_count}" );
				$this->cache->clear( $page_id, $device, $type );
				$cached_data = false;
			}
		}

		if ( false !== $cached_data ) {
			// Remove metadata before sending to client.
			unset( $cached_data['_cache_metadata'] );
			wp_send_json_success( $cached_data );
		}

		// Generate heatmap data with filters.
		$data = $this->generate_heatmap_data( $page_id, $device, $type, $date_range, $filters );

		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		// Add cache metadata including recording count for validation.
		$data['_cache_metadata'] = array(
			'recording_count' => $current_recording_count,
			'cached_at'       => time(),
		);

		// Cache the data.
		$this->cache->set( $page_id, $device, $type, $date_range, $data, $cache_key );

		// Remove metadata before sending to client.
		unset( $data['_cache_metadata'] );

		wp_send_json_success( $data );
	}

	/**
	 * Whether the current request should aggregate every device.
	 *
	 * @param string $device Device query value.
	 * @return bool
	 */
	private function is_all_device_scope( $device ) {
		$device = strtolower( trim( (string) $device ) );
		return '' === $device || 'all' === $device || 'any' === $device;
	}

	/**
	 * Build a cache key that includes filter parameters.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $device Device type.
	 * @param string $type Heatmap type.
	 * @param string $date_range Date range.
	 * @param array  $filters Filter parameters.
	 * @return string Cache key.
	 */
	private function build_cache_key( $page_id, $device, $type, $date_range, $filters ) {
		$key_parts = array(
			'page_' . $page_id,
			'device_' . $device,
			'type_' . $type,
			'range_' . $date_range,
			'spam_' . ( ! empty( $filters['exclude_spam'] ) ? 'on' : 'off' ),
		);

		if ( ! empty( $filters['country'] ) ) {
			$key_parts[] = 'country_' . $filters['country'];
		}
		if ( ! empty( $filters['browser'] ) ) {
			$key_parts[] = 'browser_' . sanitize_title( $filters['browser'] );
		}
		if ( ! empty( $filters['visitor_type'] ) ) {
			$key_parts[] = 'visitor_' . $filters['visitor_type'];
		}
		if ( ! empty( $filters['session_ids'] ) ) {
			$key_parts[] = 'sessions_' . md5( implode( ',', $filters['session_ids'] ) );
		}
		if ( ! empty( $filters['page_ids'] ) && is_array( $filters['page_ids'] ) ) {
			$page_ids = array_values( array_unique( array_filter( array_map( 'absint', $filters['page_ids'] ) ) ) );
			$key_parts[] = 'pages_' . md5( implode( ',', $page_ids ) );
		}
		if ( ! empty( $filters['ab_test_id'] ) ) {
			$key_parts[] = 'ab_' . $filters['ab_test_id'];
		}
		if ( ! empty( $filters['variant_id'] ) ) {
			$key_parts[] = 'var_' . $filters['variant_id'];
		}
		if ( ! empty( $filters['start_date'] ) && ! empty( $filters['end_date'] ) ) {
			$key_parts[] = 'dates_' . sanitize_title( $filters['start_date'] . '_' . $filters['end_date'] );
		}

		// Advanced filters (OS / UTM / duration / pages & traffic).
		$adv_keys  = array( 'os', 'utm_campaign', 'utm_source', 'utm_medium', 'duration_min', 'duration_max', 'entry_page', 'exit_page', 'referrer', 'traffic_channel' );
		$adv_parts = array();
		foreach ( $adv_keys as $adv_key ) {
			if ( isset( $filters[ $adv_key ] ) && '' !== $filters[ $adv_key ] ) {
				$adv_parts[] = $adv_key . '=' . ( is_array( $filters[ $adv_key ] ) ? implode( ',', $filters[ $adv_key ] ) : $filters[ $adv_key ] );
			}
		}
		if ( ! empty( $adv_parts ) ) {
			$key_parts[] = 'adv_' . md5( implode( '|', $adv_parts ) );
		}

		return implode( '_', $key_parts );
	}

	/**
	 * Collect the advanced filter parameters from the current AJAX request.
	 *
	 * These are the fields added by the dashboard-style advanced filters panel
	 * on the heatmap detail page: OS, UTM campaign/source/medium (multi-select,
	 * comma-separated), session duration bounds and Pages & Traffic fields.
	 *
	 * Nonce is verified by every calling AJAX handler before this runs.
	 *
	 * @return array Advanced filters (only keys that carry a value).
	 */
	private function collect_advanced_filter_params() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by calling AJAX handler.
		$adv = array();

		foreach ( array( 'os', 'utm_campaign', 'utm_source', 'utm_medium', 'entry_page', 'exit_page', 'referrer', 'traffic_channel' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				if ( '' !== $value ) {
					$adv[ $key ] = $value;
				}
			}
		}

		foreach ( array( 'duration_min', 'duration_max' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ) {
				$adv[ $key ] = absint( $_POST[ $key ] );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $adv;
	}

	/**
	 * Resolve Pages & Traffic advanced filters to a session-ID allow-list and
	 * intersect it with any pre-existing session allow-list on $filters.
	 *
	 * entry_page / exit_page / referrer live on the sessions table and
	 * traffic_channel on session_pages, so they cannot be matched against
	 * filename metadata. The bridge resolves them to session IDs which the
	 * file readers match against the 8-char session suffix in each filename.
	 *
	 * @param array $filters Current filters array.
	 * @return array Filters with 'session_ids' set/intersected when needed.
	 */
	private function apply_advanced_session_filters( $filters ) {
		// DB fallback sets for OS / UTM: legacy filenames carry no os/utm tokens,
		// so sessions matching those filters in the DB are resolved to filename
		// session suffixes and OR-ed with the filename token match.
		$filters = $this->attach_advanced_dimension_suffix_sets( $filters );

		$bridge_ids = $this->get_advanced_session_ids( $filters );

		if ( null === $bridge_ids ) {
			return $filters; // No Pages & Traffic filter active.
		}

		if ( ! empty( $filters['session_ids'] ) && is_array( $filters['session_ids'] ) ) {
			// Intersect with the existing allow-list instead of overwriting it.
			$filters['session_ids'] = array_values( array_intersect( $filters['session_ids'], $bridge_ids ) );
		} else {
			$filters['session_ids'] = $bridge_ids;
		}

		if ( empty( $filters['session_ids'] ) ) {
			// Sentinel that matches no filename suffix — result must be empty.
			$filters['session_ids'] = array( 'opti-no-session-match' );
		}

		return $filters;
	}

	/**
	 * Query session IDs matching the Pages & Traffic advanced filters.
	 *
	 * @param array $filters Filters possibly containing entry_page, exit_page,
	 *                       referrer (LIKE match) and traffic_channel (exact,
	 *                       comma-separated multi-select).
	 * @return array|null Session ID list, or null when none of these filters are set.
	 */
	private function get_advanced_session_ids( $filters ) {
		global $wpdb;

		$where  = array();
		$params = array();

		foreach ( array( 'entry_page', 'exit_page', 'referrer' ) as $col ) {
			if ( ! empty( $filters[ $col ] ) ) {
				$where[]  = "s.{$col} LIKE %s";
				$params[] = '%' . $wpdb->esc_like( $filters[ $col ] ) . '%';
			}
		}

		if ( ! empty( $filters['traffic_channel'] ) ) {
			$channels = array_filter( array_map( 'trim', explode( ',', $filters['traffic_channel'] ) ) );
			if ( ! empty( $channels ) ) {
				// Channel is DERIVED from referrer + UTM (the stored
				// session_pages.traffic_channel column is never populated), using
				// the same SQL CASE buckets as the Analytics Dashboard.
				$placeholders = implode( ',', array_fill( 0, count( $channels ), '%s' ) );
				$where[]      = '(' . $this->get_traffic_channel_case_sql() . ") IN ( {$placeholders} )";
				$params       = array_merge( $params, $channels );
			}
		}

		if ( empty( $where ) ) {
			return null;
		}

		$sql = "SELECT DISTINCT s.id FROM {$wpdb->prefix}optibehavior_sessions s WHERE " . implode( ' AND ', $where ) . ' LIMIT 20000';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; prepared below.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * Extract the session suffix from exploded filename parts, supporting all
	 * filename formats (14-part extended, 10-part AB, 8/7/6-part legacy).
	 *
	 * @param array $parts Filename parts (without .json).
	 * @return string Session suffix ('' when unparseable).
	 */
	private function extract_session_from_filename_parts( $parts ) {
		$count = count( $parts );
		if ( $count >= 14 && is_numeric( $parts[7] ) && is_numeric( $parts[8] ) ) {
			return $parts[13]; // Extended format with os + utm tokens.
		}
		if ( $count >= 10 && is_numeric( $parts[7] ) && is_numeric( $parts[8] ) ) {
			return $parts[9]; // AB format.
		}
		if ( $count >= 8 ) {
			return $parts[7];
		}
		if ( $count >= 7 ) {
			return $parts[6];
		}
		return isset( $parts[5] ) ? $parts[5] : '';
	}

	/**
	 * Check whether a filename token (OS / UTM) matches a comma-separated
	 * multi-select filter value. Comparison mirrors the storage sanitizer:
	 * non-alphanumeric runs become dashes, case-insensitive.
	 *
	 * @param string $token        Token from filename ('' or 'na' = unknown).
	 * @param string $selected_csv Selected values, comma-separated.
	 * @return bool True when token matches one of the selected values.
	 */
	private function heatmap_token_matches( $token, $selected_csv ) {
		$token = strtolower( (string) $token );
		if ( '' === $token || 'na' === $token ) {
			return false; // Files without the token are excluded when the filter is active.
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $selected_csv ) ) ) as $value ) {
			if ( $this->normalize_heatmap_token( $value ) === $token ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize a raw value the same way the storage filename sanitizer does
	 * (non-alphanumeric runs to dashes, 24-char cap, lowercased for compare).
	 *
	 * @param string $value Raw value.
	 * @return string Normalized token.
	 */
	private function normalize_heatmap_token( $value ) {
		$normalized = strtolower( trim( preg_replace( '/[^a-zA-Z0-9\-]+/', '-', (string) $value ), '-' ) );
		return substr( $normalized, 0, 24 );
	}

	/**
	 * Whether any advanced result-scoping filter is active. These filters are
	 * only honored by the file-based fast path; DB/legacy fallbacks must be
	 * skipped when one is set or they would return unfiltered data.
	 *
	 * @param array $filters Current filters array.
	 * @return bool True when an advanced filter is active.
	 */
	private function has_advanced_result_filters( $filters ) {
		foreach ( array( 'session_ids', 'os', 'utm_campaign', 'utm_source', 'utm_medium', 'adv_dim_suffixes', 'ab_test_id', 'variant_id' ) as $advanced_key ) {
			if ( ! empty( $filters[ $advanced_key ] ) ) {
				return true;
			}
		}
		if ( ( isset( $filters['duration_min'] ) && '' !== $filters['duration_min'] )
			|| ( isset( $filters['duration_max'] ) && '' !== $filters['duration_max'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Resolve active OS / UTM filters to filename session-suffix sets from the
	 * sessions + visitors tables and attach them as
	 * $filters['adv_dim_suffixes'][dim] = array( suffix => true ).
	 *
	 * Legacy filenames (pre-extended format) carry no OS/UTM tokens, so token
	 * matching alone would exclude every old file. These DB-derived sets give
	 * the file scanners a second match source: a file passes an OS/UTM filter
	 * when its filename token matches OR its session suffix is in the set.
	 *
	 * @param array $filters Current filters array.
	 * @return array Filters with 'adv_dim_suffixes' attached when relevant.
	 */
	private function attach_advanced_dimension_suffix_sets( $filters ) {
		global $wpdb;

		$dim_columns = array(
			'os'           => 'v.os',
			'utm_campaign' => 's.utm_campaign',
			'utm_source'   => 's.utm_source',
			'utm_medium'   => 's.utm_medium',
		);

		// Normalized wanted-value sets per active dimension.
		$wanted = array();
		foreach ( $dim_columns as $dim => $column ) {
			if ( empty( $filters[ $dim ] ) || ! is_string( $filters[ $dim ] ) ) {
				continue;
			}
			$values = array_filter( array_map( 'trim', explode( ',', $filters[ $dim ] ) ) );
			if ( empty( $values ) ) {
				continue;
			}
			$wanted[ $dim ] = array();
			foreach ( $values as $value ) {
				$normalized = $this->normalize_heatmap_token( $value );
				if ( '' !== $normalized ) {
					$wanted[ $dim ][ $normalized ] = true;
				}
			}
		}

		if ( empty( $wanted ) ) {
			return $filters;
		}

		// One pass over sessions covering every active dimension. Values are
		// normalized in PHP because the filename-token sanitizer cannot be
		// expressed in SQL.
		$non_empty = array();
		foreach ( array_keys( $wanted ) as $dim ) {
			$col         = $dim_columns[ $dim ];
			$non_empty[] = "( {$col} IS NOT NULL AND {$col} != '' )";
		}
		$sql = "SELECT s.id, v.os AS dim_os, s.utm_campaign AS dim_utm_campaign, s.utm_source AS dim_utm_source, s.utm_medium AS dim_utm_medium
			FROM {$wpdb->prefix}optibehavior_sessions s
			LEFT JOIN {$wpdb->prefix}optibehavior_visitors v ON s.visitor_id = v.id
			WHERE " . implode( ' OR ', $non_empty ) . ' LIMIT 20000';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static column/table names from $wpdb->prefix; no user input interpolated.
		$rows = $wpdb->get_results( $sql );

		$sets = array_fill_keys( array_keys( $wanted ), array() );
		foreach ( (array) $rows as $row ) {
			$suffix = substr( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $row->id ), -8 );
			if ( '' === $suffix ) {
				continue;
			}
			foreach ( array_keys( $wanted ) as $dim ) {
				$prop  = 'dim_' . $dim;
				$value = isset( $row->{$prop} ) ? (string) $row->{$prop} : '';
				if ( '' === $value ) {
					continue;
				}
				if ( isset( $wanted[ $dim ][ $this->normalize_heatmap_token( $value ) ] ) ) {
					$sets[ $dim ][ $suffix ] = true;
				}
			}
		}

		$filters['adv_dim_suffixes'] = $sets;

		return $filters;
	}

	/**
	 * Apply the filename-scan advanced filters (OS / UTM / duration) to parsed
	 * filename metadata during ad-hoc directory scans.
	 *
	 * @param array $filters  Active filters.
	 * @param array $parts    Filename parts.
	 * @param int   $duration Parsed duration (seconds).
	 * @return bool True when the file passes all active advanced filters.
	 */
	private function passes_advanced_filename_filters( $filters, $parts, $duration ) {
		$is_extended = ( count( $parts ) >= 14 && is_numeric( $parts[7] ) && is_numeric( $parts[8] ) );
		$file_os     = $is_extended ? $parts[9] : '';
		$file_utm_c  = $is_extended ? $parts[10] : '';
		$file_utm_s  = $is_extended ? $parts[11] : '';
		$file_utm_m  = $is_extended ? $parts[12] : '';

		$tokens = array(
			'os'           => $file_os,
			'utm_campaign' => $file_utm_c,
			'utm_source'   => $file_utm_s,
			'utm_medium'   => $file_utm_m,
		);

		// A file passes an OS/UTM filter when its filename token matches OR its
		// session suffix is in the DB-derived fallback set (legacy files carry
		// no tokens, so the DB set is their only match source).
		$suffix_sets  = isset( $filters['adv_dim_suffixes'] ) && is_array( $filters['adv_dim_suffixes'] ) ? $filters['adv_dim_suffixes'] : array();
		$file_session = $this->extract_session_from_filename_parts( $parts );
		foreach ( $tokens as $dim => $token ) {
			if ( empty( $filters[ $dim ] ) ) {
				continue;
			}
			if ( $this->heatmap_token_matches( $token, $filters[ $dim ] ) ) {
				continue;
			}
			if ( '' !== $file_session && isset( $suffix_sets[ $dim ][ $file_session ] ) ) {
				continue;
			}
			return false;
		}

		if ( isset( $filters['duration_min'] ) && '' !== $filters['duration_min'] && (int) $duration < (int) $filters['duration_min'] ) {
			return false;
		}
		if ( isset( $filters['duration_max'] ) && '' !== $filters['duration_max'] && (int) $duration > (int) $filters['duration_max'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Get heatmap statistics AJAX handler.
	 */
	public function ajax_get_heatmap_stats() {
		// Verify nonce.
		check_ajax_referer( 'opti_heatmap_detail', 'nonce' );

		// Verify permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'opti-behavior' ) ) );
		}

		if ( function_exists( 'opti_behavior_pro_require_access' ) ) {
			opti_behavior_pro_require_access( 'heatmap_detail' );
		}

		// Get parameters.
		$page_id      = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$page_ids     = $this->get_request_page_ids( $_POST, $page_id );
		$device       = isset( $_POST['device'] ) ? sanitize_text_field( wp_unslash( $_POST['device'] ) ) : 'desktop';
		$type         = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'click';
		$date_range   = isset( $_POST['date_range'] ) ? sanitize_text_field( wp_unslash( $_POST['date_range'] ) ) : 'all';
		$country      = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';
		$browser      = isset( $_POST['browser'] ) ? sanitize_text_field( wp_unslash( $_POST['browser'] ) ) : '';
		$visitor_type = isset( $_POST['visitor_type'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_type'] ) ) : '';

		// A/B test variant filtering.
		$ab_test_id = isset( $_POST['ab_test_id'] ) ? absint( $_POST['ab_test_id'] ) : 0;
		$variant_id = isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0;

		// Build filters array
		$filters = array(
			'country'      => $country,
			'browser'      => $browser,
			'visitor_type' => $visitor_type,
			'page_ids'     => $page_ids,
			'exclude_spam' => $this->resolve_spam_exclusion_from_request( $_POST ) ? 1 : 0,
		);
		$filters = $this->add_custom_date_filters( $filters, $date_range, $_POST );

		// Filter by A/B variant using direct filename metadata matching.
		if ( $ab_test_id && $variant_id ) {
			$filters['ab_test_id'] = $ab_test_id;
			$filters['variant_id'] = $variant_id;
		}

		if ( ! $page_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid page ID', 'opti-behavior' ) ) );
		}

		// Advanced filters (OS / UTM / duration / pages & traffic).
		$filters = array_merge( $filters, $this->collect_advanced_filter_params() );
		$filters = $this->apply_advanced_session_filters( $filters );

		$this->debug_log( "[ajax_get_heatmap_stats] Generating stats for page_id=$page_id device=$device type=$type date_range=$date_range visitor_type=$visitor_type", 'info' );

		// Generate statistics with filters. Wrapped in error handler to catch any PHP errors
		// that would otherwise kill the AJAX response silently (e.g., foreach(null) TypeError in PHP 8.0+).
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped, restored immediately after the try/catch below; converts PHP warnings into catchable exceptions so a stats-generation error returns a clean JSON error instead of a silent 500.
		set_error_handler( function( $errno, $errstr, $errfile, $errline ) {
			throw new \ErrorException( esc_html( $errstr ), 0, (int) $errno, esc_html( $errfile ), (int) $errline );
		} );

		try {
			$stats = $this->generate_statistics( $page_id, $device, $date_range, $type, $filters );
		} catch ( \Throwable $e ) {
			restore_error_handler();
			$this->debug_log( "[ajax_get_heatmap_stats] FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine(), 'error' );
			wp_send_json_error( array( 'message' => 'Stats generation failed: ' . $e->getMessage() ) );
		}

		restore_error_handler();

		if ( is_wp_error( $stats ) ) {
			wp_send_json_error( array( 'message' => $stats->get_error_message() ) );
		}

		// Top Clicked Elements (AI Insights panel) — click heatmaps only.
		// Pro feature: served only with a valid paid/trial license. Soft gate
		// (locked flag instead of 403) because this endpoint also returns the
		// base stats every tier is entitled to.
		if ( 'click' === $type && is_array( $stats ) ) {
			if ( $this->top_elements_access_allowed() ) {
				$stats['top_elements'] = $this->get_top_clicked_elements( $page_id, $device, $date_range, $type, $filters );
			} else {
				$stats['top_elements_locked'] = true;
			}
		}

		$this->debug_log( "[ajax_get_heatmap_stats] SUCCESS - sending stats response", 'info' );
		wp_send_json_success( $stats );
	}

	/**
	 * Whether the current install may see Top Clicked Elements.
	 *
	 * Server-side license gate for the Pro-only element stats. Allowed for
	 * paid/trial tiers with no revocation; the grace-period state keeps Pro
	 * features active by design. Fails closed when the access helper is
	 * unavailable.
	 *
	 * @return bool
	 */
	private function top_elements_access_allowed() {
		if ( ! function_exists( 'opti_behavior_pro_get_access_context' ) ) {
			return false;
		}

		$context = opti_behavior_pro_get_access_context();
		if ( ! is_array( $context ) ) {
			return false;
		}

		$revocation = isset( $context['revocation_state'] ) ? (string) $context['revocation_state'] : 'none';
		$revoked    = ! in_array( $revocation, array( 'none', 'grace_period' ), true );

		return ! empty( $context['is_paid'] ) && empty( $context['trial_expired'] ) && ! $revoked;
	}

	/**
	 * Aggregate the top clicked elements for the AI Insights panel.
	 *
	 * Groups raw click events (event codes 16 = desktop, 17 = mobile/tablet)
	 * by the stable element selector captured by the frontend tracker. Element
	 * data exists only for clicks recorded after the element-identification
	 * feature shipped; older rows have NULL selectors and are excluded.
	 *
	 * Filters honored: date range (incl. custom), visitor type, country and
	 * browser (via sessions/visitors joins added only when needed), the
	 * Pages & Traffic session-id list, and the filename-only filters
	 * (OS / UTM / duration / A/B variant) bridged to a DB session-id
	 * allow-list by get_element_scope_session_ids(). Spam sessions are
	 * always excluded.
	 *
	 * @param int    $page_id    Primary (canonical) page ID; the group is
	 *                           resolved internally via get_filter_page_ids().
	 * @param string $device     Device: desktop|mobile|tablet (or all-scope).
	 * @param string $date_range Date range key (all, today, yesterday, last_7_days, last_30_days, custom).
	 * @param string $type       Heatmap type (only 'click' produces elements).
	 * @param array  $filters    Active filters (country, browser, visitor_type, OS/UTM/duration, dates, ...).
	 * @param int    $limit      Maximum number of elements to return.
	 * @return array[] Rows: { selector, label, type, builder, tag, href, clicks, percentage }.
	 */
	private function get_top_clicked_elements( $page_id, $device, $date_range, $type = 'click', $filters = array(), $limit = 10 ) {
		// Aggregate strictly from the SAME filtered heatmap FILE set that feeds
		// the Clicks strip and the rendered heatmap. count_events_from_files()
		// applies the entire active scope (period ∩ device ∩ visitor type ∩
		// country ∩ browser ∩ OS/UTM/duration ∩ A/B ∩ spam/orphan gate) and, via
		// the opt-in tally, buckets every admitted click point by its element
		// XPath anchor. This is the fix for the AI-Insights drift: the old source
		// (wp_optibehavior_events) counted archived-orphan sessions whose files
		// were already pruned (QA fixtures) and ignored the filename-scan filters,
		// so the widget showed more clicks than the strip. Now the two share one
		// source of truth and can never diverge.
		$primary_page_id = absint( $page_id );
		if ( $primary_page_id <= 0 || 'click' !== $type ) {
			return array();
		}

		$element_tally = array();
		$file_stats    = $this->count_events_from_files( $primary_page_id, $device, $date_range, $type, $filters, $element_tally );

		$total_clicks = isset( $file_stats['total_clicks'] ) ? (int) $file_stats['total_clicks'] : 0;
		if ( $total_clicks <= 0 || empty( $element_tally ) ) {
			return array();
		}

		// Drop the unanchored residual bucket ('' key): those clicks hit no
		// stable element and are not "top clicked elements". They stay part of
		// the strip total (the percentage denominator) so the listed percentages
		// read against the full click volume the strip reports.
		unset( $element_tally[''] );
		if ( empty( $element_tally ) ) {
			return array();
		}

		arsort( $element_tally );
		$limit      = max( 1, (int) $limit );
		$top_xpaths = array_slice( $element_tally, 0, $limit, true );

		// Enrich the file-derived XPath buckets with the human-readable element
		// identity (selector / text / type / builder) captured in the events
		// table. This is static per-element metadata, so it needs no scope
		// filtering — only the label lookup. XPaths with no events row fall back
		// to a tag derived from the path.
		$metadata = $this->get_element_metadata_by_xpath( $primary_page_id, $filters, array_keys( $top_xpaths ) );

		$top_elements = array();
		foreach ( $top_xpaths as $xpath => $clicks ) {
			$meta     = isset( $metadata[ (string) $xpath ] ) ? $metadata[ (string) $xpath ] : array();
			$selector = ! empty( $meta['selector'] ) ? (string) $meta['selector'] : $this->derive_selector_from_xpath( (string) $xpath );
			$tag      = ! empty( $meta['tag'] ) ? (string) $meta['tag'] : $this->derive_tag_from_xpath( (string) $xpath );

			$top_elements[] = array(
				'selector'   => $selector,
				'label'      => (string) ( $meta['label'] ?? '' ),
				'type'       => '' !== (string) ( $meta['type'] ?? '' ) ? (string) $meta['type'] : 'element',
				'builder'    => (string) ( $meta['builder'] ?? '' ),
				'tag'        => $tag,
				'href'       => (string) ( $meta['href'] ?? '' ),
				'clicks'     => (int) $clicks,
				'percentage' => round( ( (int) $clicks / $total_clicks ) * 100, 1 ),
			);
		}

		// This widget lists ONLY identified top clicked elements. It is not a
		// reconciliation view: the visible click sum will be LESS than the Clicks
		// strip total whenever unanchored clicks or below-cutoff elements exist.
		// Percentages stay against $total_clicks (share of ALL page clicks), so
		// the listed rows may sum to under 100%. That is intended, not a bug.

		return $top_elements;
	}

	/**
	 * Tally the click points of one heatmap click FILE by element XPath anchor.
	 *
	 * Reads the file body (the filename alone carries no per-click element data)
	 * and increments $tally[ xp ] for every click point. Points with no anchor
	 * land in the '' residual bucket so the full tally always sums to the file's
	 * click count — the same number count_events_from_files() adds to the strip.
	 *
	 * @param string $file  Absolute path to a clicks/*.json file.
	 * @param array  $tally By-ref accumulator: xp string => click count.
	 * @return void
	 */
	private function accumulate_click_file_element_tally( $file, &$tally ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local heatmap data file, not a remote URL.
		$raw = @file_get_contents( $file );
		if ( false === $raw || '' === $raw ) {
			return;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return;
		}
		foreach ( $decoded['data'] as $point ) {
			$xp = ( is_array( $point ) && isset( $point['xp'] ) ) ? (string) $point['xp'] : '';
			if ( ! isset( $tally[ $xp ] ) ) {
				$tally[ $xp ] = 0;
			}
			++$tally[ $xp ];
		}
	}

	/**
	 * Resolve element identity metadata (selector / label / type / builder /
	 * tag / href) for a set of element XPaths across the canonical page group.
	 *
	 * The XPath is the stable per-click anchor stored in both the heatmap files
	 * (point.xp) and the events table (element_xpath), so it is the join key
	 * between the file-derived click tally and the rich element labels. Metadata
	 * is element identity, not scoped analytics, so no period/visitor filtering
	 * is applied — only the label is looked up.
	 *
	 * @param int   $page_id Primary (canonical) page ID.
	 * @param array $filters Active filters (used only to resolve the page group).
	 * @param array $xpaths  Element XPaths to resolve.
	 * @return array Map: xpath => { selector, label, type, builder, tag, href }.
	 */
	private function get_element_metadata_by_xpath( $page_id, $filters, $xpaths ) {
		global $wpdb;

		$xpaths = array_values( array_unique( array_filter( array_map( 'strval', (array) $xpaths ), static function ( $x ) {
			return '' !== $x;
		} ) ) );
		if ( empty( $xpaths ) ) {
			return array();
		}

		$page_ids = array_values( array_filter( array_map( 'absint', (array) $this->get_filter_page_ids( $page_id, $filters ) ) ) );
		if ( empty( $page_ids ) ) {
			return array();
		}

		$events_table = $wpdb->prefix . 'optibehavior_events';
		$page_ph      = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		$xp_ph        = implode( ',', array_fill( 0, count( $xpaths ), '%s' ) );
		$params       = array_merge( $page_ids, $xpaths );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; WHERE built from prepared placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.element_xpath AS xp,
						MAX(NULLIF(e.element_selector, '')) AS selector,
						MAX(NULLIF(e.element_text, ''))     AS label,
						MAX(NULLIF(e.element_type, ''))     AS type,
						MAX(NULLIF(e.element_builder, ''))  AS builder,
						MAX(NULLIF(e.element_tag, ''))      AS tag,
						MAX(NULLIF(e.element_href, ''))     AS href
				 FROM {$events_table} e
				 WHERE e.page_id2 IN ({$page_ph})
				   AND e.event IN (16, 17)
				   AND e.element_xpath IN ({$xp_ph})
				 GROUP BY e.element_xpath",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['xp'] ] = array(
				'selector' => (string) ( $row['selector'] ?? '' ),
				'label'    => (string) ( $row['label'] ?? '' ),
				'type'     => (string) ( $row['type'] ?? '' ),
				'builder'  => (string) ( $row['builder'] ?? '' ),
				'tag'      => (string) ( $row['tag'] ?? '' ),
				'href'     => (string) ( $row['href'] ?? '' ),
			);
		}

		return $map;
	}

	/**
	 * Extract the leaf tag name from an absolute XPath (e.g. ".../button[2]" =>
	 * "button"). Used as a label fallback when no events metadata row exists.
	 *
	 * @param string $xpath Absolute element XPath.
	 * @return string Lower-case tag name, or '' when not derivable.
	 */
	private function derive_tag_from_xpath( $xpath ) {
		$xpath = (string) $xpath;
		if ( '' === $xpath ) {
			return '';
		}
		$segments = array_values( array_filter( explode( '/', $xpath ) ) );
		if ( empty( $segments ) ) {
			return '';
		}
		$last = (string) end( $segments );
		$tag  = preg_replace( '/\[\d+\]$/', '', $last );

		return strtolower( trim( (string) $tag ) );
	}

	/**
	 * Fallback CSS-ish selector when an XPath has no events metadata row: the
	 * leaf tag name, or the raw XPath when even the tag cannot be derived.
	 *
	 * @param string $xpath Absolute element XPath.
	 * @return string
	 */
	private function derive_selector_from_xpath( $xpath ) {
		$tag = $this->derive_tag_from_xpath( $xpath );

		return '' !== $tag ? $tag : (string) $xpath;
	}

	/**
	 * Resolve filename-only filters (OS / UTM / duration / A/B variant) to a
	 * DB session-id allow-list for the Top Clicked Elements query.
	 *
	 * Click events carry no OS/UTM/duration/variant columns, so these filters
	 * are bridged through session metadata: OS/UTM values match the
	 * sessions/visitors tables (normalized like filename tokens), duration
	 * matches the sessions.duration column, and the A/B variant matches click
	 * filenames whose session suffix is mapped back to full DB session ids.
	 *
	 * @param array $page_ids Canonical page ID group.
	 * @param array $filters  Active filters.
	 * @return array|null Session-id allow-list, or null when no bridged filter
	 *                    is active (no restriction needed). An empty array
	 *                    means the active filters match no session at all.
	 */
	private function get_element_scope_session_ids( $page_ids, $filters ) {
		global $wpdb;

		$dim_columns = array(
			'os'           => 'v.os',
			'utm_campaign' => 's.utm_campaign',
			'utm_source'   => 's.utm_source',
			'utm_medium'   => 's.utm_medium',
		);

		// Normalized wanted-value sets per active OS/UTM dimension.
		$wanted = array();
		foreach ( array_keys( $dim_columns ) as $dim ) {
			if ( empty( $filters[ $dim ] ) || ! is_string( $filters[ $dim ] ) ) {
				continue;
			}
			foreach ( array_filter( array_map( 'trim', explode( ',', $filters[ $dim ] ) ) ) as $value ) {
				$normalized = $this->normalize_heatmap_token( $value );
				if ( '' !== $normalized ) {
					$wanted[ $dim ][ $normalized ] = true;
				}
			}
		}

		$has_duration = ( isset( $filters['duration_min'] ) && '' !== $filters['duration_min'] )
			|| ( isset( $filters['duration_max'] ) && '' !== $filters['duration_max'] );
		$has_variant  = ! empty( $filters['ab_test_id'] ) && ! empty( $filters['variant_id'] );

		if ( empty( $wanted ) && ! $has_duration && ! $has_variant ) {
			return null;
		}

		$allowed = null; // null = unrestricted so far.

		// OS / UTM / duration → session metadata match. OS/UTM values are
		// normalized in PHP because the filename-token sanitizer cannot be
		// expressed in SQL; duration bounds are pushed into the query.
		if ( ! empty( $wanted ) || $has_duration ) {
			$where  = array();
			$params = array();
			if ( isset( $filters['duration_min'] ) && '' !== $filters['duration_min'] ) {
				$where[]  = 's.duration >= %d';
				$params[] = (int) $filters['duration_min'];
			}
			if ( isset( $filters['duration_max'] ) && '' !== $filters['duration_max'] ) {
				$where[]  = 's.duration <= %d';
				$params[] = (int) $filters['duration_max'];
			}
			$non_empty = array();
			foreach ( array_keys( $wanted ) as $dim ) {
				$col         = $dim_columns[ $dim ];
				$non_empty[] = "( {$col} IS NOT NULL AND {$col} != '' )";
			}
			if ( ! empty( $non_empty ) ) {
				$where[] = '( ' . implode( ' OR ', $non_empty ) . ' )';
			}
			$where_sql = ! empty( $where ) ? ' WHERE ' . implode( ' AND ', $where ) : '';
			$sql       = "SELECT s.id, v.os AS dim_os, s.utm_campaign AS dim_utm_campaign, s.utm_source AS dim_utm_source, s.utm_medium AS dim_utm_medium
				FROM {$wpdb->prefix}optibehavior_sessions s
				LEFT JOIN {$wpdb->prefix}optibehavior_visitors v ON s.visitor_id = v.id{$where_sql} LIMIT 20000";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static column/table names from $wpdb->prefix; WHERE built from prepared placeholders.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$rows = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			$allowed = array();
			foreach ( (array) $rows as $row ) {
				$matches = true;
				foreach ( $wanted as $dim => $tokens ) {
					$prop  = 'dim_' . $dim;
					$value = isset( $row->{$prop} ) ? (string) $row->{$prop} : '';
					if ( '' === $value || ! isset( $tokens[ $this->normalize_heatmap_token( $value ) ] ) ) {
						$matches = false;
						break;
					}
				}
				if ( $matches ) {
					$allowed[ (string) $row->id ] = true;
				}
			}
		}

		// A/B variant → click filenames carry the only variant record. Collect
		// the session suffixes of files tagged with this variant, then keep the
		// DB session ids whose cleaned 8-char suffix is in that set.
		if ( $has_variant ) {
			$suffixes   = $this->get_variant_session_suffixes( $page_ids, (int) $filters['ab_test_id'], (int) $filters['variant_id'] );
			$candidates = ( null !== $allowed ) ? array_keys( $allowed ) : $this->get_element_candidate_session_ids( $page_ids );
			$allowed    = array();
			foreach ( $candidates as $session_id ) {
				$suffix = substr( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $session_id ), -8 );
				if ( '' !== $suffix && isset( $suffixes[ $suffix ] ) ) {
					$allowed[ (string) $session_id ] = true;
				}
			}
		}

		return array_keys( (array) $allowed );
	}

	/**
	 * Collect the session suffixes of click files tagged with a specific A/B
	 * test variant across every url_hash of the canonical page group.
	 *
	 * Only AB (10-part) and extended (14-part) filenames carry variant
	 * metadata; older formats are skipped (they can never match a variant).
	 *
	 * @param array $page_ids   Canonical page ID group.
	 * @param int   $ab_test_id A/B test ID.
	 * @param int   $variant_id Variant ID.
	 * @return array Map: session suffix => true.
	 */
	private function get_variant_session_suffixes( $page_ids, $ab_test_id, $variant_id ) {
		$suffixes = array();
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			return $suffixes;
		}

		$storage    = Opti_Behavior_Heatmap_Storage::get_instance();
		$upload_dir = wp_upload_dir();

		$hashes = array();
		foreach ( (array) $page_ids as $pid ) {
			foreach ( $storage->get_url_hashes_for_page( (int) $pid ) as $hash ) {
				if ( $hash ) {
					$hashes[ $hash ] = true;
				}
			}
		}

		foreach ( array_keys( $hashes ) as $hash ) {
			$dir = $upload_dir['basedir'] . '/opti-behavior-data/' . $hash . '/clicks';
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			foreach ( (array) glob( $dir . '/*.json' ) as $file ) {
				$parts = explode( '_', basename( $file, '.json' ) );
				if ( count( $parts ) < 10 || ! isset( $parts[5] ) || ! in_array( $parts[5], array( 'L', 'G' ), true )
					|| ! is_numeric( $parts[7] ) || ! is_numeric( $parts[8] ) ) {
					continue;
				}
				if ( (int) $parts[7] !== $ab_test_id || (int) $parts[8] !== $variant_id ) {
					continue;
				}
				$suffix = $this->extract_session_from_filename_parts( $parts );
				if ( '' !== $suffix ) {
					$suffixes[ $suffix ] = true;
				}
			}
		}

		return $suffixes;
	}

	/**
	 * List the distinct DB session ids that produced events on the page group.
	 * Used as the candidate pool when the A/B variant bridge runs without a
	 * preceding metadata restriction.
	 *
	 * @param array $page_ids Canonical page ID group.
	 * @return array Session id list.
	 */
	private function get_element_candidate_session_ids( $page_ids ) {
		global $wpdb;

		$page_ids = array_values( array_filter( array_map( 'absint', (array) $page_ids ) ) );
		if ( empty( $page_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; IN() built from prepared placeholders.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT e.session_id FROM {$wpdb->prefix}optibehavior_events e
				 WHERE e.page_id2 IN ({$placeholders}) AND e.session_id IS NOT NULL AND e.session_id <> '' LIMIT 20000",
				$page_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * Refresh heatmap cache AJAX handler.
	 */
	public function ajax_refresh_cache() {
		// Verify nonce.
		check_ajax_referer( 'opti_heatmap_detail', 'nonce' );

		// Verify permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'opti-behavior' ) ) );
		}

		if ( function_exists( 'opti_behavior_pro_require_access' ) ) {
			opti_behavior_pro_require_access( 'heatmap_detail' );
		}

		// Get parameters.
		$page_id  = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$page_ids = $this->get_request_page_ids( $_POST, $page_id );

		if ( ! $page_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid page ID', 'opti-behavior' ) ) );
		}

		// Clear cache for every page in the canonical group.
		foreach ( $page_ids as $canonical_page_id ) {
			$this->cache->clear( $canonical_page_id );
		}

		wp_send_json_success( array( 'message' => __( 'Cache refreshed', 'opti-behavior' ) ) );
	}

	/**
	 * Get device counts AJAX handler.
	 * Accepts filters to ensure counts match the statistics (single source of truth).
	 */
	public function ajax_get_device_counts() {
		// Verify nonce.
		check_ajax_referer( 'opti_heatmap_detail', 'nonce' );

		// Verify permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'opti-behavior' ) ) );
		}

		if ( function_exists( 'opti_behavior_pro_require_access' ) ) {
			opti_behavior_pro_require_access( 'heatmap_detail' );
		}

		// Get parameters.
		$page_id      = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$page_ids     = $this->get_request_page_ids( $_POST, $page_id );
		$date_range   = isset( $_POST['date_range'] ) ? sanitize_text_field( wp_unslash( $_POST['date_range'] ) ) : 'all';
		$country      = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';
		$browser      = isset( $_POST['browser'] ) ? sanitize_text_field( wp_unslash( $_POST['browser'] ) ) : '';
		// All Visitors is the default visitor scope (plan step 216): the bottom
		// bar's default state must equal the period-total pill (guest + logged-in
		// == 46), and Guest becomes an EXPLICIT filter (-> 36). An empty / absent
		// value therefore means "All Visitors" (no re-scope); 'guest'/'logged_in'
		// are explicit filters applied by the user.
		$visitor_type = isset( $_POST['visitor_type'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_type'] ) ) : '';
		$ab_test_id   = isset( $_POST['ab_test_id'] ) ? absint( $_POST['ab_test_id'] ) : 0;
		$variant_id   = isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0;

		if ( ! $page_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid page ID', 'opti-behavior' ) ) );
		}

		// Build filters array.
		$filters = array(
			'country'      => $country,
			'browser'      => $browser,
			'visitor_type' => $visitor_type,
			'page_ids'     => $page_ids,
			'ab_test_id'   => $ab_test_id,
			'variant_id'   => $variant_id,
			'exclude_spam' => $this->resolve_spam_exclusion_from_request( $_POST ) ? 1 : 0,
		);
		$filters = $this->add_custom_date_filters( $filters, $date_range, $_POST );

		// Advanced filters (OS / UTM / duration / pages & traffic).
		$filters = array_merge( $filters, $this->collect_advanced_filter_params() );
		$filters = $this->apply_advanced_session_filters( $filters );

		// Heatmap-universe parity (master decision): the device chips count the
		// SAME universe as the header pill — guest spam-excluded sessions that
		// produced heatmap event files — but reactive to the active filters.
		// The old session_pages + recordings DB join counted a DIFFERENT
		// (recordings) universe, letting a 30-day chip exceed the all-time
		// pill (visibly illogical). Counting the pill's file universe under the
		// filters makes chips a strict subset of the pill (chips <= pill).
		// Type is never scoped here (all of clicks/moves/scrolls, deduped by
		// session token in the admissible-meta bridge).
		unset( $filters['storage_type'] );

		// Get counts with filters applied.
		$counts = $this->get_heatmap_session_device_counts( $page_ids, $date_range, $filters );

		wp_send_json_success( $counts );
	}

	/**
	 * Get total recordings count AJAX handler — the detail header pill.
	 *
	 * The pill is a GLOBAL, ALL-VISITORS event-coverage metric:
	 * "(X / Y sessions)" where X = sessions with recorded heatmap events
	 * (clicks/moves/scrolls files, 'sessions_with_interactions') and Y = ALL
	 * detected sessions for the page (with or without events), both counted
	 * across every visitor type (guest + logged in) over all time.
	 *
	 * It intentionally IGNORES the page's date-range / visitor-type /
	 * country / advanced filters — the device badges and the bottom stats
	 * bar are the filter-reactive surfaces. Only page identity (page_id /
	 * page_ids canonical group) and the product-wide default spam scope
	 * apply, keeping X and Y apples-to-apples with each other and with the
	 * background reconciliation cache.
	 *
	 * The Heatmaps LIST sessions cell shows this SAME all-visitors pair
	 * (unified metric — the list reads file_total_all from the reconciliation
	 * cache and the all-visitors DB total through the same batched query
	 * scope), so list and detail render identical numbers for a page.
	 */
	public function ajax_get_total_recordings() {
		// Verify nonce.
		check_ajax_referer( 'opti_heatmap_detail', 'nonce' );

		// Verify permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'opti-behavior' ) ) );
		}

		if ( function_exists( 'opti_behavior_pro_require_access' ) ) {
			opti_behavior_pro_require_access( 'heatmap_detail' );
		}

		// Get parameters. The pill respects the page's Period selector (master
		// decision 2026-08-13: default All time) so it stays equal to the
		// frontend bar / metabox at the same period; the OTHER filters
		// (visitor_type / country / browser / A-B / advanced) are still NOT read
		// (the canonical session universe is not scoped by them).
		$page_id    = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$page_ids   = $this->get_request_page_ids( $_POST, $page_id );
		$date_range = isset( $_POST['date_range'] ) ? sanitize_text_field( wp_unslash( $_POST['date_range'] ) ) : 'all';
		if ( ! in_array( $date_range, array( 'all', 'today', 'last7days', 'last30days', 'custom' ), true ) ) {
			$date_range = 'all';
		}
		$custom_dates = $this->add_custom_date_filters( array(), $date_range, $_POST );
		$period_start = isset( $custom_dates['start_date'] ) ? $custom_dates['start_date'] : '';
		$period_end   = isset( $custom_dates['end_date'] ) ? $custom_dates['end_date'] : '';

		if ( ! $page_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid page ID', 'opti-behavior' ) ) );
		}

		// X — sessions with recorded heatmap events (all visitor types), and
		// Y — the HONEST detected-sessions total (DB sessions UNION orphan
		// file sessions, spec §3.3 / RC-D), both from the Free-side
		// reconciliation cache via the coverage-pair bridge (cached;
		// computed on demand for this single page when the cache has no
		// entry yet). X is null when the Free bridge is unavailable at all
		// (the UI then hides the first number); Y is null when the pair
		// bridge is unavailable/has no DB side yet (older Free version /
		// Free-only install with no Pro DB-total helper) — falls back to
		// the raw DB total below, same as the pre-Honest-Y behavior.
		$coverage    = $this->get_heatmap_session_coverage_via_bridge( $page_ids, $date_range, $period_start, $period_end );
		$with_events = ( is_array( $coverage ) && isset( $coverage['file_total_all'] ) ) ? $coverage['file_total_all'] : null;
		$detected    = ( is_array( $coverage ) && isset( $coverage['detected_total_all'] ) ) ? $coverage['detected_total_all'] : null;

		if ( null !== $detected ) {
			// Y >= X is guaranteed by construction on a fresh entry (spec
			// §3.3's max() clamp) — no more "43 / 5" impossible pill.
			$all_sessions = $detected;
		} else {
			// Legacy fallback: raw DB total, product-wide default spam scope
			// (not the request toggle) so the number matches the
			// reconciliation cache's counting rules.
			$global_filters = array(
				'visitor_type' => '',
				'page_ids'     => $page_ids,
				'exclude_spam' => $this->resolve_spam_exclusion_from_request( array() ) ? 1 : 0,
			);
			$counts       = $this->get_session_page_device_counts( $page_id, 'all', $global_filters );
			$all_sessions = ( is_array( $counts ) && isset( $counts['total'] ) ) ? (int) $counts['total'] : 0;

			// Orphaned-files fallback: the DB knows no sessions but event
			// files exist (post-cleanup desync) — surface the file-derived
			// count as the session total instead of "0 sessions".
			if ( 0 === $all_sessions && null !== $with_events && $with_events > 0 ) {
				$all_sessions = $with_events;
			}
		}

		// Desync: more sessions carry event files than are detected at all.
		// False by construction against a fresh $detected; can only fire
		// against the legacy fallback Y — a genuinely repairable state.
		$desynced = ( null !== $with_events && $with_events > $all_sessions );

		// "Delete Heatmap Data" dual display: the bridge exposes the all-time
		// total when the page group has a reset floor and the since-reset count
		// differs — the pill then renders "(45 / 108 sessions)" with a tooltip.
		$reset_all_time     = ( is_array( $coverage ) && isset( $coverage['reset_all_time'] ) ) ? (int) $coverage['reset_all_time'] : null;
		$reset_scope_tooltip = '';
		if ( null !== $reset_all_time ) {
			// translators: %1$s: sessions counted since the heatmap data deletion, %2$s: total sessions recorded all time.
			$reset_scope_tooltip = sprintf( __( '%1$s sessions with heatmap data (counted since this page\'s heatmap data was deleted) / %2$s total sessions recorded all time. Analytics keeps all sessions; heatmaps restart from the deletion.', 'opti-behavior' ), number_format_i18n( (int) $all_sessions ), number_format_i18n( $reset_all_time ) );
		}

		wp_send_json_success(
			array(
				'total'                      => $all_sessions,
				'sessions_with_interactions' => $with_events,
				'desynced'                   => $desynced,
				'sessions_all_time'          => $reset_all_time,
				'reset_scope_tooltip'        => $reset_scope_tooltip,
			)
		);
	}

	/**
	 * Honest session-coverage pair for one page (spec §3.3, RC-D), via the
	 * Free dashboard's coverage bridge — X = 'file_total_all' (sessions with
	 * recorded heatmap events, all visitor types) and Y = 'detected_total_all'
	 * (DB sessions UNION orphan file sessions). Falls back to the older
	 * single-int bridge (X only, Y null) when the pair bridge is
	 * unavailable (Free inactive / older Free version without it) — never
	 * fatals.
	 *
	 * @param int|int[] $page_ids Page ID, or the canonical page_ids group of a
	 *                            multi-variant page. The pill sums agg_sessions
	 *                            across the whole group, so pass the full group.
	 * @return array{file_total_all:int,detected_total_all:int|null}|null Null
	 *                    when even the X-only bridge is unavailable.
	 */
	private function get_heatmap_session_coverage_via_bridge( $page_ids, $period = 'all', $start_date = '', $end_date = '' ) {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			return null;
		}

		$free_core      = Opti_Behavior_Heatmap_Core::get_instance();
		$free_dashboard = ( $free_core && method_exists( $free_core, 'get_dashboard' ) ) ? $free_core->get_dashboard() : null;

		if ( $free_dashboard && method_exists( $free_dashboard, 'get_heatmap_session_coverage_bridge' ) ) {
			// Newer Free bridge resolves the page's canonical content group and
			// returns the canonical human pageview-session total for the period.
			$pair = $free_dashboard->get_heatmap_session_coverage_bridge( $page_ids, $period, $start_date, $end_date );
			return is_array( $pair ) ? $pair : null;
		}

		// Older Free version without the pair bridge: degrade to X-only via the
		// original single-int bridge (primary page only — best available).
		$primary_id  = is_array( $page_ids ) ? (int) reset( $page_ids ) : (int) $page_ids;
		$with_events = $this->get_all_visitors_file_session_count_via_bridge( $primary_id );

		return null === $with_events ? null : array(
			'file_total_all'     => $with_events,
			'detected_total_all' => null,
		);
	}

	/**
	 * All-visitors file-derived session count for one page, via the Free
	 * dashboard bridge (Pro -> Free, same accessor pattern as the spam
	 * allow-list bridge in get_recordings_by_device()).
	 *
	 * @param int $page_id Page ID.
	 * @return int|null Count, or null when the bridge is unavailable (Free
	 *                  inactive / older Free version) — never fatals.
	 */
	private function get_all_visitors_file_session_count_via_bridge( $page_id ) {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			return null;
		}

		$free_core      = Opti_Behavior_Heatmap_Core::get_instance();
		$free_dashboard = ( $free_core && method_exists( $free_core, 'get_dashboard' ) ) ? $free_core->get_dashboard() : null;
		if ( ! $free_dashboard || ! method_exists( $free_dashboard, 'get_heatmap_all_visitors_file_total_bridge' ) ) {
			return null;
		}

		$count = $free_dashboard->get_heatmap_all_visitors_file_total_bridge( $page_id );

		return null === $count ? null : (int) $count;
	}

	/**
	 * Map a heatmap type to its file-storage folder name.
	 *
	 * Same mapping the filter-options endpoint uses (attention heatmaps are
	 * rendered from scroll/viewposition data).
	 *
	 * @param string $type Heatmap type (click|move|scroll|attention).
	 * @return string Storage folder (clicks|moves|scrolls) or '' when unknown.
	 */
	private function map_heatmap_type_to_storage( $type ) {
		$map = array(
			'click'     => 'clicks',
			'move'      => 'moves',
			'scroll'    => 'scrolls',
			'attention' => 'scrolls',
		);
		$type = strtolower( (string) $type );
		return isset( $map[ $type ] ) ? $map[ $type ] : '';
	}

	/**
	 * Generate heatmap data from recordings.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $device Device type.
	 * @param string $type Heatmap type.
	 * @param string $date_range Date range.
	 * @param array  $filters Optional filters (country, browser, visitor_type).
	 * @return array|WP_Error Heatmap data or error.
	 */
	private function generate_heatmap_data( $page_id, $device, $type, $date_range, $filters = array() ) {
		global $wpdb;

		// Log the request for debugging
		$this->debug_log( "[Opti-Behavior-Heatmap] Generating heatmap - Page: $page_id, Device: $device, Type: $type" );

		// Try fast heatmap storage first (pre-extracted data)
		$fast_result = $this->generate_heatmap_data_fast( $page_id, $device, $type, $date_range, $filters );
		if ( $fast_result !== false && ! empty( $fast_result['coordinates'] ) ) {
			$this->debug_log( "[Opti-Behavior-Heatmap] Using fast heatmap storage - found " . count( $fast_result['coordinates'] ) . " coordinates" );
			return $fast_result;
		}

		// Advanced filters (session allow-list, OS, UTM, duration, suffix sets,
		// A/B variant) are only honored by the file-based fast path. The legacy recordings
		// fallback below would silently ignore them and render UNFILTERED data,
		// so when any of them is active an empty fast result is the correct
		// answer ("no sessions match"), not a fallback.
		if ( $this->has_advanced_result_filters( $filters ) ) {
			if ( false !== $fast_result ) {
				return $fast_result;
			}
			return array(
				'coordinates'          => array(),
				'viewport'             => array( 'width' => 1920, 'height' => 1080 ),
				'reference_width'      => 1920,
				'reference_height'     => 1080,
				'recordings_processed' => 0,
				'fast_storage'         => true,
			);
		}

		// Fall back to traditional method (parsing recordings)
		$this->debug_log( "[Opti-Behavior-Heatmap] Fast storage empty or unavailable, falling back to traditional method" );

		// Get recordings for this page and device with filters.
		$recordings = $this->get_recordings( $page_id, $device, $date_range, $filters );

		if ( empty( $recordings ) ) {
			$this->debug_log( "[Opti-Behavior-Heatmap] No recordings found for page $page_id and device $device" );
			return array(
				'coordinates'         => array(),
				'viewport'            => array( 'width' => 1920, 'height' => 1080 ),
				'recordings_processed' => 0,
				'message'             => __( 'No recordings found for this page and device combination', 'opti-behavior' ),
			);
		}

		$this->debug_log( "[Opti-Behavior-Heatmap] Found " . count( $recordings ) . " recordings to process" );
		$this->debug_log( "[Opti-Behavior-Heatmap] Recording IDs: " . implode( ', ', array_map( function( $r ) { return $r->id; }, $recordings ) ) );

		// Get the page URL for filtering multi-page recordings
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$page_url = $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$wpdb->prefix}optibehavior_pages WHERE id = %d", $page_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$this->debug_log( "[Opti-Behavior-Heatmap] Filtering events for page URL: {$page_url}" );

		$all_parsed_data = array();
		$viewport        = array( 'width' => 1920, 'height' => 1080 );

		// Process each recording.
		$events_loaded = 0;
		$events_parsed = 0;
		foreach ( $recordings as $recording ) {
			$events = $this->get_recording_events( $recording );

			if ( empty( $events ) ) {
				$this->debug_log( "[Opti-Behavior-Heatmap] No events found for recording ID: {$recording->id}" );
				continue;
			}

			// Filter events to only include those from the target page
			// For multi-page recordings, we need to find navigation events and filter subsequent events
			$filtered_events = $this->filter_events_by_page( $events, $page_url );

			if ( empty( $filtered_events ) ) {
				$this->debug_log( "[Opti-Behavior-Heatmap] No events for page {$page_url} in recording ID: {$recording->id}" );
				continue;
			}

			$events_loaded++;
			$this->debug_log( "[Opti-Behavior-Heatmap] Processing recording {$recording->id} with " . count( $filtered_events ) . " filtered events (from " . count( $events ) . " total)" );

			$parsed = $this->parser->parse_events( $filtered_events, $type );

			if ( ! empty( $parsed ) ) {
				$all_parsed_data[] = $parsed;
				$events_parsed++;

				// Update viewport if available.
				if ( ! empty( $parsed['viewport'] ) ) {
					$viewport = $parsed['viewport'];
				}

				// Log parsed data stats
				$clicks_count = isset( $parsed['clicks'] ) ? count( $parsed['clicks'] ) : 0;
				$moves_count = isset( $parsed['moves'] ) ? count( $parsed['moves'] ) : 0;
				$scrolls_count = isset( $parsed['scrolls'] ) ? count( $parsed['scrolls'] ) : 0;
				$this->debug_log( "[Opti-Behavior-Heatmap] Parsed - Clicks: $clicks_count, Moves: $moves_count, Scrolls: $scrolls_count" );
			}
		}

		$this->debug_log( "[Opti-Behavior-Heatmap] Events loaded: $events_loaded, Events parsed: $events_parsed" );
		$this->debug_log( "[Opti-Behavior-Heatmap] Using viewport: " . $viewport['width'] . 'x' . $viewport['height'] );

		// IMPORTANT: For scroll heatmaps, if someone viewed the page but never scrolled,
		// we need to show that they viewed the TOP of the page (Y=0).
		// Add synthetic initial scroll event at Y=0 for each recording that has no scroll events.
		if ( $type === 'scroll' ) {
			$total_scrolls = 0;
			foreach ( $all_parsed_data as $parsed_data ) {
				if ( isset( $parsed_data['scrolls'] ) ) {
					$total_scrolls += count( $parsed_data['scrolls'] );
				}
			}

			// If there are NO scroll events across ALL recordings, but we have parsed data (page was viewed),
			// inject a synthetic initial scroll event at Y=0 for EACH parsed recording.
			// Use $events_loaded to check if we actually have events for this page (not just recordings in general)
			if ( $total_scrolls === 0 && $events_loaded > 0 ) {
				$this->debug_log( "[Opti-Behavior-Heatmap] No scroll events found, but page was viewed ($events_loaded recordings with events). Adding synthetic scroll at Y=0" );

				// Add one synthetic scroll event per parsed recording to represent initial page load position
				foreach ( $all_parsed_data as &$parsed_data ) {
					if ( ! isset( $parsed_data['scrolls'] ) ) {
						$parsed_data['scrolls'] = array();
					}

					// Add initial scroll position at top of page (Y=0)
					$parsed_data['scrolls'][] = array(
						'x'         => 0,
						'y'         => 0,
						'timestamp' => 0,
					);
				}
				unset( $parsed_data ); // Break reference

				$this->debug_log( "[Opti-Behavior-Heatmap] Added " . count( $all_parsed_data ) . " synthetic scroll events at Y=0" );
			}
		}

		// Aggregate all parsed data with viewport information.
		$aggregated_result = $this->aggregate_all_data( $all_parsed_data, $type, $viewport );

		// Extract coordinates and reference dimensions if using normalized system
		if ( is_array( $aggregated_result ) && isset( $aggregated_result['coordinates'] ) ) {
			// New normalized system returns coordinates + reference dimensions
			$aggregated = $aggregated_result['coordinates'];
			$reference_width = isset( $aggregated_result['reference_width'] ) ? $aggregated_result['reference_width'] : $viewport['width'];
			$reference_height = isset( $aggregated_result['reference_height'] ) ? $aggregated_result['reference_height'] : $viewport['height'];
		} else {
			// Legacy system returns coordinates only
			$aggregated = $aggregated_result;
			$reference_width = $viewport['width'];
			$reference_height = $viewport['height'];
		}

		$this->debug_log( "[Opti-Behavior-Heatmap] Aggregated " . count( $aggregated ) . " coordinates for type: $type" );
		$this->debug_log( "[Opti-Behavior-Heatmap] Reference dimensions: {$reference_width}x{$reference_height}" );

		return array(
			'coordinates'         => $aggregated,
			'viewport'            => $viewport,
			'reference_width'     => $reference_width,
			'reference_height'    => $reference_height,
			'recordings_processed' => count( $recordings ),
			'events_loaded'       => $events_loaded,
			'events_parsed'       => $events_parsed,
		);
	}

	/**
	 * Generate heatmap data using fast file storage.
	 *
	 * This method reads pre-extracted heatmap data from files instead of
	 * parsing recordings on every request. Much faster for pages with many recordings.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $device Device type (desktop, mobile).
	 * @param string $type Heatmap type (click, move, scroll).
	 * @param string $date_range Date range.
	 * @param array  $filters Optional filters (country, browser, visitor_type, batch, batch_size).
	 * @return array|false Heatmap data or false if fast storage is not available.
	 */
	private function generate_heatmap_data_fast( $page_id, $device, $type, $date_range, $filters = array() ) {
		// Check if heatmap storage is available (from Free version)
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			$this->debug_log( "[Opti-Behavior-Heatmap-Fast] Heatmap storage class not available" );
			return false;
		}

		$heatmap_storage = Opti_Behavior_Heatmap_Storage::get_instance();
		if ( ! $heatmap_storage ) {
			return false;
		}

		$page_ids = $this->get_filter_page_ids( $page_id, $filters );
		if ( count( $page_ids ) > 1 && empty( $filters['_single_page_fast'] ) ) {
			return $this->generate_heatmap_data_fast_for_page_ids( $page_ids, $device, $type, $date_range, $filters, $heatmap_storage );
		}

		// Get URL hash(es) for this page - a page can have more than one
		// mapping-table row (e.g. a stale QA URL hash alongside the live
		// one); scan every dir, never just the first (spec §3.1, RC-A/RC-B).
		$page_url_hashes = $heatmap_storage->get_url_hashes_for_page( $page_id );
		if ( empty( $page_url_hashes ) ) {
			$this->debug_log( "[Opti-Behavior-Heatmap-Fast] URL hash not found for page $page_id" );
			return false;
		}
		// Kept for logging / "no files found" messages below; downstream reads loop over all hashes.
		$url_hash = $page_url_hashes[0];

		$this->debug_log( '[Opti-Behavior-Heatmap-Fast] Found URL hash(es): ' . implode( ',', $page_url_hashes ) . " for page $page_id" );

		// Map type to storage folder name
		$type_map = array(
			'click'     => 'clicks',
			'move'      => 'moves',
			'scroll'    => 'scrolls',
			'attention' => 'scrolls', // Attention heatmap uses scroll/viewposition data to show dwell time zones
		);
		$storage_type = isset( $type_map[ $type ] ) ? $type_map[ $type ] : 'clicks';

		// Build filters for file reading
		$file_filters = array(
			'device'     => $device,
			'date_range' => $date_range,
		);
		if ( $this->is_all_device_scope( $device ) ) {
			unset( $file_filters['device'] );
		}

		if ( ! empty( $filters['exclude_spam'] ) ) {
			$spam_session_ids = $this->get_spam_allowed_session_ids( $page_ids );

			// Orphan-aware admission (RC-C, spec §3.2): file sessions with NO
			// DB trace at all can't be evidenced as spam by the allow-list
			// query above — admit them too, so the rendered heatmap matches
			// the file universe the header pill / quick-stats bar count.
			$orphan_tokens = $this->get_orphan_session_tokens_via_bridge( $page_ids, $page_url_hashes, $heatmap_storage );
			if ( ! empty( $orphan_tokens ) ) {
				$spam_session_ids = array_values( array_unique( array_merge( $spam_session_ids, $orphan_tokens ) ) );
			}

			if ( empty( $spam_session_ids ) ) {
				$spam_session_ids = array( '__opti_no_spam_sessions__' );
			}
			// Intersect with any pre-existing allow-list (Pages & Traffic bridge).
			if ( ! empty( $filters['session_ids'] ) && is_array( $filters['session_ids'] ) ) {
				$spam_session_ids = array_values( array_intersect( $filters['session_ids'], $spam_session_ids ) );
				if ( empty( $spam_session_ids ) ) {
					$spam_session_ids = array( '__opti_no_spam_sessions__' );
				}
			}
			$file_filters['session_ids'] = $spam_session_ids;
			$filters['session_ids']      = $spam_session_ids;
		}

		// Add optional filters
		if ( ! empty( $filters['country'] ) ) {
			$file_filters['country'] = $filters['country'];
		}
		if ( ! empty( $filters['browser'] ) ) {
			$file_filters['browser'] = $filters['browser'];
		}
		if ( ! empty( $filters['visitor_type'] ) ) {
			$file_filters['visitor_type'] = $filters['visitor_type'];
		}
		if ( ! empty( $filters['session_ids'] ) ) {
			$file_filters['session_ids'] = $filters['session_ids'];
		}
		if ( ! empty( $filters['ab_test_id'] ) ) {
			$file_filters['ab_test_id'] = $filters['ab_test_id'];
		}
		if ( ! empty( $filters['variant_id'] ) ) {
			$file_filters['variant_id'] = $filters['variant_id'];
		}

		// Advanced filename-scan filters (OS / UTM / duration bounds) + the
		// DB-derived OS/UTM session-suffix fallback sets.
		foreach ( array( 'os', 'utm_campaign', 'utm_source', 'utm_medium', 'duration_min', 'duration_max', 'adv_dim_suffixes' ) as $adv_key ) {
			if ( isset( $filters[ $adv_key ] ) && '' !== $filters[ $adv_key ] ) {
				$file_filters[ $adv_key ] = $filters[ $adv_key ];
			}
		}

		// Handle date range
		if ( $date_range === 'custom' && ! empty( $filters['start_date'] ) && ! empty( $filters['end_date'] ) ) {
			$file_filters['start_date'] = $filters['start_date'];
			$file_filters['end_date'] = $filters['end_date'];
		}

		// Add batching support
		$batch = isset( $filters['batch'] ) ? (int) $filters['batch'] : 1;
		$batch_size = isset( $filters['batch_size'] ) ? (int) $filters['batch_size'] : 100;

		// Scroll-reach is session-aware: the percentage denominator must be the
		// GLOBAL session count, not a per-batch slice. Force a single full read
		// (batch 1, all files) so reach % is computed over every session at once.
		// Scroll files are tiny (a few breakaway coords), so a full read is cheap.
		// Guarded on Free exposing aggregate_scroll_reach_data(); otherwise fall
		// back to the legacy batched coordinate aggregator (existing behavior).
		$can_scroll_reach = ( 'scroll' === $type )
			&& method_exists( $heatmap_storage, 'aggregate_scroll_reach_data' );
		if ( $can_scroll_reach ) {
			$batch      = 1;
			$batch_size = PHP_INT_MAX;
		}

		$file_filters['batch'] = $batch;
		$file_filters['batch_size'] = $batch_size;

		// Read pre-extracted heatmap files (FAST!) - with batching, merged across
		// every url_hash dir belonging to this page (union, spec §3.1).
		// read_heatmap_files() returns array('files' => ..., 'total_files' => ...) so we get
		// the total count in the same scandir pass, avoiding a redundant get_heatmap_file_count() call.
		$files_data  = array();
		$total_files = 0;
		foreach ( $page_url_hashes as $hash_dir ) {
			$read_result  = $heatmap_storage->read_heatmap_files( $hash_dir, $storage_type, $file_filters );
			$hash_files   = isset( $read_result['files'] ) ? $read_result['files'] : array();
			$hash_total   = isset( $read_result['total_files'] ) ? absint( $read_result['total_files'] ) : count( $hash_files );
			$files_data   = array_merge( $files_data, $hash_files );
			$total_files += $hash_total;
		}
		$total_batches = $can_scroll_reach ? 1 : ceil( $total_files / $batch_size );

		$this->debug_log( "[Opti-Behavior-Heatmap-Fast] Total files: $total_files, Batch: $batch/$total_batches, Batch size: $batch_size" );

		if ( empty( $files_data ) ) {
			$this->debug_log( "[Opti-Behavior-Heatmap-Fast] No heatmap files found for $url_hash/$storage_type (batch $batch)" );

			// Return empty result with pagination info if this is a subsequent batch
			if ( $batch > 1 ) {
				return array(
					'coordinates'          => array(),
					'viewport'             => array( 'width' => 1920, 'height' => 1080 ),
					'reference_width'      => 1920,
					'reference_height'     => 1080,
					'recordings_processed' => 0,
					'fast_storage'         => true,
					'batch'                => $batch,
					'total_batches'        => $total_batches,
					'total_files'          => $total_files,
					'has_more'             => false,
				);
			}
			return false;
		}

		$this->debug_log( "[Opti-Behavior-Heatmap-Fast] Found " . count( $files_data ) . " heatmap files for batch $batch" );

		// Aggregate data from this batch.
		// Scroll uses the session-aware reach aggregator (cumulative % per depth);
		// attention/click/move keep the generic coordinate aggregator unchanged.
		if ( $can_scroll_reach ) {
			$aggregated = $heatmap_storage->aggregate_scroll_reach_data( $files_data );
		} else {
			$aggregated = $heatmap_storage->aggregate_heatmap_data( $files_data, $storage_type );
		}

		// For move heatmaps, we also need to generate trajectories
		$trajectories = array();
		if ( $type === 'move' && ! empty( $files_data ) ) {
			$this->debug_log( "[Move Heatmap] Type is move, generating trajectories from " . count($files_data) . " files" );

			// Collect all move data from files
			$all_move_data = array();
			foreach ( $files_data as $file_data ) {
				if ( isset( $file_data['data'] ) && is_array( $file_data['data'] ) ) {
					foreach ( $file_data['data'] as $coord ) {
						$all_move_data[] = $coord;
					}
				}
			}

			$this->debug_log( "[Move Heatmap] Collected " . count($all_move_data) . " move coordinates" );

			// Use parser to generate trajectories from move data
			if ( ! empty( $all_move_data ) ) {
				$move_aggregated = $this->parser->aggregate_move_data( $all_move_data, $aggregated['viewport'] );
				$this->debug_log( "[Move Heatmap] Parser returned " . count($move_aggregated['trajectories']) . " trajectories" );
				if ( isset( $move_aggregated['trajectories'] ) ) {
					$trajectories = $move_aggregated['trajectories'];
				}
			}
		}

		$result = array(
			'coordinates'          => $aggregated['coordinates'],
			'viewport'             => $aggregated['viewport'],
			'reference_width'      => $aggregated['reference_width'],
			'reference_height'     => isset( $aggregated['reference_height'] ) ? $aggregated['reference_height'] : $aggregated['viewport']['height'],
			'recordings_processed' => count( $files_data ),
			'fast_storage'         => true,
			'batch'                => $batch,
			'total_batches'        => $total_batches,
			'total_files'          => $total_files,
			'has_more'             => $can_scroll_reach ? false : ( $batch < $total_batches ),
		);

		// Scroll-reach pass-through: expose metric marker + global session count.
		if ( $can_scroll_reach ) {
			$result['scroll_metric']  = isset( $aggregated['scroll_metric'] ) ? $aggregated['scroll_metric'] : 'reach';
			$result['total_sessions'] = isset( $aggregated['total_sessions'] ) ? $aggregated['total_sessions'] : count( $files_data );
		}

		// Add trajectories for move heatmap
		if ( $type === 'move' ) {
			$result['trajectories'] = $trajectories;
			$this->debug_log( "[Move Heatmap] Returning " . count($trajectories) . " trajectories to frontend" );
		}

		return $result;
	}

	/**
	 * Generate fast heatmap data for a canonical page group.
	 *
	 * @param array  $page_ids        Canonical page IDs.
	 * @param string $device          Device type.
	 * @param string $type            Heatmap type.
	 * @param string $date_range      Date range.
	 * @param array  $filters         Filters.
	 * @param object $heatmap_storage Heatmap storage instance.
	 * @return array|false Heatmap data or false.
	 */
	private function generate_heatmap_data_fast_for_page_ids( $page_ids, $device, $type, $date_range, $filters, $heatmap_storage ) {
		$type_map = array(
			'click'     => 'clicks',
			'move'      => 'moves',
			'scroll'    => 'scrolls',
			'attention' => 'scrolls',
		);
		$storage_type = isset( $type_map[ $type ] ) ? $type_map[ $type ] : 'clicks';

		$file_filters = array(
			'device'     => $device,
			'date_range' => $date_range,
		);
		if ( $this->is_all_device_scope( $device ) ) {
			unset( $file_filters['device'] );
		}

		if ( ! empty( $filters['exclude_spam'] ) ) {
			$spam_session_ids = $this->get_spam_allowed_session_ids( $page_ids );

			// Orphan-aware admission (RC-C, spec §3.2): file sessions with NO
			// DB trace at all can't be evidenced as spam — admit them too, so
			// the rendered heatmap matches the file universe the header pill /
			// quick-stats bar count.
			$group_url_hashes = array();
			foreach ( $page_ids as $canonical_page_id ) {
				foreach ( $heatmap_storage->get_url_hashes_for_page( $canonical_page_id ) as $url_hash ) {
					if ( $url_hash ) {
						$group_url_hashes[] = $url_hash;
					}
				}
			}
			$group_url_hashes = array_values( array_unique( $group_url_hashes ) );
			$orphan_tokens    = $this->get_orphan_session_tokens_via_bridge( $page_ids, $group_url_hashes, $heatmap_storage );
			if ( ! empty( $orphan_tokens ) ) {
				$spam_session_ids = array_values( array_unique( array_merge( $spam_session_ids, $orphan_tokens ) ) );
			}

			if ( empty( $spam_session_ids ) ) {
				$spam_session_ids = array( '__opti_no_spam_sessions__' );
			}
			// Intersect with any pre-existing allow-list (Pages & Traffic bridge).
			if ( ! empty( $filters['session_ids'] ) && is_array( $filters['session_ids'] ) ) {
				$spam_session_ids = array_values( array_intersect( $filters['session_ids'], $spam_session_ids ) );
				if ( empty( $spam_session_ids ) ) {
					$spam_session_ids = array( '__opti_no_spam_sessions__' );
				}
			}
			$file_filters['session_ids'] = $spam_session_ids;
			$filters['session_ids']      = $spam_session_ids;
		}

		foreach ( array( 'country', 'browser', 'visitor_type', 'session_ids', 'ab_test_id', 'variant_id', 'start_date', 'end_date', 'os', 'utm_campaign', 'utm_source', 'utm_medium', 'duration_min', 'duration_max', 'adv_dim_suffixes' ) as $filter_key ) {
			if ( isset( $filters[ $filter_key ] ) && '' !== $filters[ $filter_key ] && array() !== $filters[ $filter_key ] ) {
				$file_filters[ $filter_key ] = $filters[ $filter_key ];
			}
		}

		$batch      = isset( $filters['batch'] ) ? max( 1, (int) $filters['batch'] ) : 1;
		$batch_size = isset( $filters['batch_size'] ) ? max( 1, (int) $filters['batch_size'] ) : 100;

		// Scroll-reach needs the global session count as denominator: force a single
		// full read (all files, batch 1) so reach % spans every session, not a batch.
		// Guarded on Free exposing aggregate_scroll_reach_data(); else legacy behavior.
		$can_scroll_reach = ( 'scroll' === $type )
			&& method_exists( $heatmap_storage, 'aggregate_scroll_reach_data' );
		if ( $can_scroll_reach ) {
			$batch      = 1;
			$batch_size = PHP_INT_MAX;
		}

		$file_filters['batch']      = $batch;
		$file_filters['batch_size'] = $batch_size;

		$files_data          = array();
		$total_files         = 0;
		$total_batches       = 0;
		$page_hashes_scanned = 0;
		$scanned_hashes      = array();

		foreach ( $page_ids as $canonical_page_id ) {
			foreach ( $heatmap_storage->get_url_hashes_for_page( $canonical_page_id ) as $url_hash ) {
				if ( ! $url_hash || isset( $scanned_hashes[ $url_hash ] ) ) {
					continue;
				}
				$scanned_hashes[ $url_hash ] = true;

				$page_hashes_scanned++;
				$read_result    = $heatmap_storage->read_heatmap_files( $url_hash, $storage_type, $file_filters );
				$page_files     = isset( $read_result['files'] ) ? $read_result['files'] : array();
				$page_total     = isset( $read_result['total_files'] ) ? absint( $read_result['total_files'] ) : count( $page_files );
				$files_data     = array_merge( $files_data, $page_files );
				$total_files   += $page_total;
				$total_batches  = max( $total_batches, (int) ceil( $page_total / $batch_size ) );
			}
		}

		if ( empty( $files_data ) ) {
			if ( $batch > 1 ) {
				return array(
					'coordinates'          => array(),
					'viewport'             => array( 'width' => 1920, 'height' => 1080 ),
					'reference_width'      => 1920,
					'reference_height'     => 1080,
					'recordings_processed' => 0,
					'fast_storage'         => true,
					'canonical_page_ids'   => $page_ids,
					'batch'                => $batch,
					'total_batches'        => $total_batches,
					'total_files'          => $total_files,
					'has_more'             => false,
				);
			}
			return false;
		}

		// Scroll uses the session-aware reach aggregator; other types unchanged.
		if ( $can_scroll_reach ) {
			$aggregated    = $heatmap_storage->aggregate_scroll_reach_data( $files_data );
			$total_batches = 1;
		} else {
			$aggregated = $heatmap_storage->aggregate_heatmap_data( $files_data, $storage_type );
		}

		$trajectories = array();
		if ( 'move' === $type && ! empty( $files_data ) ) {
			$all_move_data = array();
			foreach ( $files_data as $file_data ) {
				if ( isset( $file_data['data'] ) && is_array( $file_data['data'] ) ) {
					foreach ( $file_data['data'] as $coord ) {
						$all_move_data[] = $coord;
					}
				}
			}
			if ( ! empty( $all_move_data ) ) {
				$move_aggregated = $this->parser->aggregate_move_data( $all_move_data, $aggregated['viewport'] );
				if ( isset( $move_aggregated['trajectories'] ) ) {
					$trajectories = $move_aggregated['trajectories'];
				}
			}
		}

		$result = array(
			'coordinates'          => $aggregated['coordinates'],
			'viewport'             => $aggregated['viewport'],
			'reference_width'      => $aggregated['reference_width'],
			'reference_height'     => isset( $aggregated['reference_height'] ) ? $aggregated['reference_height'] : $aggregated['viewport']['height'],
			'recordings_processed' => count( $files_data ),
			'fast_storage'         => true,
			'canonical_page_ids'   => $page_ids,
			'page_hashes_scanned'  => $page_hashes_scanned,
			'batch'                => $batch,
			'total_batches'        => $total_batches,
			'total_files'          => $total_files,
			'has_more'             => $can_scroll_reach ? false : ( $batch < $total_batches ),
		);

		// Scroll-reach pass-through: expose metric marker + global session count.
		if ( $can_scroll_reach ) {
			$result['scroll_metric']  = isset( $aggregated['scroll_metric'] ) ? $aggregated['scroll_metric'] : 'reach';
			$result['total_sessions'] = isset( $aggregated['total_sessions'] ) ? $aggregated['total_sessions'] : count( $files_data );
		}

		if ( 'move' === $type ) {
			$result['trajectories'] = $trajectories;
		}

		return $result;
	}

	/**
	 * AJAX handler for batched heatmap data loading.
	 *
	 * This endpoint is called repeatedly by JavaScript to load heatmap data
	 * in batches of 100 files at a time, preventing timeouts with large datasets.
	 *
	 * Supports multi-select filters for country and browser:
	 * - country: single value or comma-separated (e.g., "US" or "US,FR,DE")
	 * - browser: single value or comma-separated (e.g., "Chrome" or "Chrome,Firefox")
	 * - visitor_type: 'logged_in' or 'guest' to filter by user login status
	 */
	public function ajax_get_heatmap_batch() {
		// Verify nonce.
		check_ajax_referer( 'opti_heatmap_detail', 'nonce' );

		// Verify permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'opti-behavior' ) ) );
		}

		if ( function_exists( 'opti_behavior_pro_require_access' ) ) {
			opti_behavior_pro_require_access( 'heatmap_detail' );
		}

		// Get parameters.
		$page_id      = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$page_ids     = $this->get_request_page_ids( $_POST, $page_id );
		$device       = isset( $_POST['device'] ) ? sanitize_text_field( wp_unslash( $_POST['device'] ) ) : 'desktop';
		$type         = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'click';
		$date_range   = isset( $_POST['date_range'] ) ? sanitize_text_field( wp_unslash( $_POST['date_range'] ) ) : 'all';
		$batch        = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 1;
		$batch_size   = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 100;

		// Handle multi-select filters (can be single value or comma-separated)
		$country      = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';
		$browser      = isset( $_POST['browser'] ) ? sanitize_text_field( wp_unslash( $_POST['browser'] ) ) : '';
		$visitor_type = isset( $_POST['visitor_type'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_type'] ) ) : '';

		// A/B test variant filtering (Pro feature).
		$ab_test_id = isset( $_POST['ab_test_id'] ) ? absint( $_POST['ab_test_id'] ) : 0;
		$variant_id = isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0;

		if ( ! $page_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid page ID', 'opti-behavior' ) ) );
		}

		// Build filters - country and browser can be comma-separated for multi-select
		// The storage class will parse these into arrays
		$filters = array(
			'country'      => $country,
			'browser'      => $browser,
			'visitor_type' => $visitor_type,
			'page_ids'     => $page_ids,
			'batch'        => $batch,
			'batch_size'   => $batch_size,
			'exclude_spam' => $this->resolve_spam_exclusion_from_request( $_POST ) ? 1 : 0,
		);
		$filters = $this->add_custom_date_filters( $filters, $date_range, $_POST );

		// Filter by A/B variant using direct filename metadata matching.
		if ( $ab_test_id && $variant_id ) {
			$filters['ab_test_id'] = $ab_test_id;
			$filters['variant_id'] = $variant_id;
		}

		// Advanced filters (OS / UTM / duration / pages & traffic).
		$filters = array_merge( $filters, $this->collect_advanced_filter_params() );
		$filters = $this->apply_advanced_session_filters( $filters );

		// Generate batched heatmap data
		$data = $this->generate_heatmap_data_fast( $page_id, $device, $type, $date_range, $filters );

		if ( $data === false ) {
			wp_send_json_error( array(
				'message'   => __( 'No heatmap data found', 'opti-behavior' ),
				'batch'     => $batch,
				'has_more'  => false,
			) );
		}

		wp_send_json_success( $data );
	}

	/**
	 * AJAX handler for fetching filter options (countries, browsers) from filenames.
	 *
	 * This endpoint is designed to be FAST - it only scans filenames, not file contents.
	 * Called immediately on page load to populate filter dropdowns while heatmap data
	 * loads in background (two-thread approach).
	 */
	public function ajax_get_filter_options() {
		// Verify nonce.
		check_ajax_referer( 'opti_heatmap_detail', 'nonce' );

		// Verify permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'opti-behavior' ) ) );
		}

		if ( function_exists( 'opti_behavior_pro_require_access' ) ) {
			opti_behavior_pro_require_access( 'heatmap_detail' );
		}

		// Get parameters.
		$page_id    = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$page_ids   = $this->get_request_page_ids( $_POST, $page_id );
		$device     = isset( $_POST['device'] ) ? sanitize_text_field( wp_unslash( $_POST['device'] ) ) : '';
		$type       = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'click';
		$date_range = isset( $_POST['date_range'] ) ? sanitize_text_field( wp_unslash( $_POST['date_range'] ) ) : 'all';
		// Interdependent filtering: country_filter filters browsers, browser_filter filters countries
		$country_filter = isset( $_POST['country_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['country_filter'] ) ) : '';
		$browser_filter = isset( $_POST['browser_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['browser_filter'] ) ) : '';
		// A/B variant scoping: when the page is viewed for one variant, the
		// per-option session counts must be limited to that variant's files.
		$ab_test_id = isset( $_POST['ab_test_id'] ) ? absint( $_POST['ab_test_id'] ) : 0;
		$variant_id = isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0;
		// Visitor-type scoping: hero session count honors it, so option counts must too.
		$visitor_type = isset( $_POST['visitor_type'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_type'] ) ) : '';

		if ( ! $page_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid page ID', 'opti-behavior' ) ) );
		}

		// Check if heatmap storage is available.
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			wp_send_json_error( array( 'message' => __( 'Heatmap storage not available', 'opti-behavior' ) ) );
		}

		$heatmap_storage = Opti_Behavior_Heatmap_Storage::get_instance();

		// Get URL hashes for the canonical page group.
		$url_hashes = array();
		foreach ( $page_ids as $canonical_page_id ) {
			foreach ( $heatmap_storage->get_url_hashes_for_page( $canonical_page_id ) as $url_hash ) {
				if ( $url_hash ) {
					$url_hashes[] = $url_hash;
				}
			}
		}
		$url_hashes = array_values( array_unique( $url_hashes ) );
		if ( empty( $url_hashes ) ) {
			// Return empty options if no URL hash found (no heatmap data yet).
			wp_send_json_success( array(
				'countries'        => array(),
				'browsers'         => array(),
				'devices'          => array(),
				'os'               => array(),
				'utm_campaigns'    => array(),
				'utm_sources'      => array(),
				'utm_mediums'      => array(),
				'counts'           => array(),
				'traffic_channels' => array(),
				'entry_pages'      => array(),
				'exit_pages'       => array(),
				'referrers'        => array(),
			) );
		}

		// Map type to storage folder name.
		$type_map = array(
			'click'     => 'clicks',
			'move'      => 'moves',
			'scroll'    => 'scrolls',
			'attention' => 'scrolls', // Attention heatmap uses scroll/viewposition data to show dwell time zones
		);
		$storage_type = isset( $type_map[ $type ] ) ? $type_map[ $type ] : 'clicks';

		// Build filters (with interdependent filtering support).
		$filters = array();
		if ( ! empty( $date_range ) && $date_range !== 'all' ) {
			$filters['date_range'] = $date_range;
		}
		$filters = $this->add_custom_date_filters( $filters, $date_range, $_POST );
		// Pass device filter to show only countries/browsers for the selected device.
		if ( ! $this->is_all_device_scope( $device ) ) {
			$filters['device'] = $device;
		}
		// Pass interdependent filters only if they have values
		if ( ! empty( $country_filter ) ) {
			$filters['country_filter'] = $country_filter;
		}
		if ( ! empty( $browser_filter ) ) {
			$filters['browser_filter'] = $browser_filter;
		}
		// Scope every option count to the active A/B variant. Matched against
		// filename metadata (parts 7/8 of the extended format) in the scan.
		$variant_scoped = ( $ab_test_id > 0 && $variant_id > 0 );
		if ( $variant_scoped ) {
			$filters['ab_test_id'] = $ab_test_id;
			$filters['variant_id'] = $variant_id;
		}
		// Visitor-type scoping (L|G filename marker). The visitor-type sets
		// themselves stay unfiltered so the select can still show both counts.
		if ( in_array( strtolower( $visitor_type ), array( 'guest', 'visitor', 'logged_in' ), true ) ) {
			$filters['visitor_type'] = strtolower( $visitor_type );
		}
		// Spam scoping: hero session count excludes spam-flagged sessions, so
		// option counts must match. Resolve the same allow-list the data path
		// uses and let the scan drop files whose session suffix is not in it.
		if ( $this->resolve_spam_exclusion_from_request( $_POST ) ) {
			$spam_session_ids = $this->get_spam_allowed_session_ids( $page_ids );

			// Orphan-aware admission (RC-C, spec §3.2): file sessions with no
			// DB trace at all can't be evidenced as spam — admit them too, so
			// option counts match the pill's / quick-stats' file universe.
			$orphan_tokens = $this->get_orphan_session_tokens_via_bridge( $page_ids, $url_hashes, $heatmap_storage );
			if ( ! empty( $orphan_tokens ) ) {
				$spam_session_ids = array_values( array_unique( array_merge( $spam_session_ids, $orphan_tokens ) ) );
			}

			$filters['allowed_session_lookup'] = $this->build_session_lookup_map( $spam_session_ids );
		}

		// Scan filenames to get unique filter options (FAST!).
		// Session sets (value => set of session suffixes) are merged across URL
		// hashes so per-option counts reflect unique sessions in the page group.
		$set_keys = array( 'countries', 'browsers', 'devices', 'os', 'utm_campaigns', 'utm_sources', 'utm_mediums' );
		$session_sets = array_fill_keys( $set_keys, array() );
		$session_sets['visitor_types'] = array( 'guest' => array(), 'logged_in' => array() );

		// A/B variant scope: the header pill and device badges count variant
		// sessions across ALL heatmap type folders (a variant session may have
		// recorded only a move/scroll file, not one of the currently viewed
		// type), so option counts must scan the same 3-folder universe to
		// match them. Without variant scoping the single-type scan is kept —
		// its visitor/country/browser counts are replaced by the canonical DB
		// overlay below, and the Pages & Traffic scope stays view-scoped.
		$scan_storage_types = $variant_scoped ? array( 'clicks', 'moves', 'scrolls' ) : array( $storage_type );

		foreach ( $url_hashes as $url_hash ) {
			foreach ( $scan_storage_types as $scan_storage_type ) {
				$hash_options = $heatmap_storage->scan_filter_options( $url_hash, $scan_storage_type, $filters );
				$hash_sets    = isset( $hash_options['session_sets'] ) ? $hash_options['session_sets'] : array();
				foreach ( array_merge( $set_keys, array( 'visitor_types' ) ) as $opt_key ) {
					$hash_dim = isset( $hash_sets[ $opt_key ] ) ? $hash_sets[ $opt_key ] : array();
					foreach ( $hash_dim as $value => $sessions ) {
						if ( isset( $session_sets[ $opt_key ][ $value ] ) ) {
							$session_sets[ $opt_key ][ $value ] += $sessions; // Union (keys are session suffixes).
						} else {
							$session_sets[ $opt_key ][ $value ] = $sessions;
						}
					}
				}
			}
		}

		// Value lists + per-value unique-session counts.
		$options = array();
		$counts  = array();
		foreach ( array_merge( $set_keys, array( 'visitor_types' ) ) as $opt_key ) {
			$options[ $opt_key ] = array_keys( $session_sets[ $opt_key ] );
			$counts[ $opt_key ]  = array();
			foreach ( $session_sets[ $opt_key ] as $value => $sessions ) {
				$counts[ $opt_key ][ $value ] = count( $sessions );
			}
		}
		$start_date = isset( $filters['start_date'] ) ? $filters['start_date'] : '';
		$end_date   = isset( $filters['end_date'] ) ? $filters['end_date'] : '';

		// Canonical parity: the Visitor Type / Country / Browser / OS / UTM
		// "(N)" counts must come from the same session_pages + recordings DB
		// scope (plus the admissible-file-session union, spec §3.2/§3.3 +
		// full-parity follow-up) as the header pill and device badges, not
		// from the single-type file scan or a DB-only query — either alone
		// can disagree with the pill's file_total_all. Variant scoping stays
		// file-based (variant IDs only exist in filenames); Free-only installs
		// without the Pro tables keep the scan counts (their pills are
		// file-based too, via get_canonical_*() returning null).
		//
		// Bugfix (sum-closure follow-up, spec step "visitor-type counts still
		// leak 2 sessions"): these canonical counters must NOT be scoped by
		// the currently selected device. The header pill and device badges
		// (ajax_get_total_recordings() / get_recordings_by_device()) are both
		// device-unscoped — the pill ignores every filter, and the device
		// badges endpoint never sends a 'device' filter at all (device IS the
		// dimension badges show). But loadFilterOptions() in heatmap-detail.js
		// always sends the live `device` param, and it was being forwarded
		// into get_canonical_visitor_type_counts()/get_canonical_session_dimension_counts(),
		// silently narrowing the Visitor Type / Country / Browser / OS / UTM
		// dropdown counts to the active device (e.g. 93 guest + 7 logged_in =
		// 100 instead of 95 + 7 = 102 with device=desktop active on page_id=3)
		// while the pill/badges stayed device-unscoped — a 2-session gap
		// (exactly the sessions on other devices) between surfaces that must
		// agree. Passing '' here keeps every list summing to the SAME
		// device-unscoped universe as the pill and the badges/dropdown parity
		// rule (badges guest total == guest dropdown count); device narrowing
		// stays limited to the raw filename scan's 'devices' option list and
		// the rendered heatmap/quick-stats, which are intentionally
		// device-reactive.
		$dim_device_scope = '';
		if ( ! $variant_scoped ) {
			$vt_filters = array( 'page_ids' => $page_ids );
			if ( isset( $filters['start_date'] ) ) {
				$vt_filters['start_date'] = $filters['start_date'];
			}
			if ( isset( $filters['end_date'] ) ) {
				$vt_filters['end_date'] = $filters['end_date'];
			}
			if ( ! empty( $country_filter ) ) {
				$vt_filters['country'] = $country_filter;
			}
			if ( ! empty( $browser_filter ) ) {
				$vt_filters['browser'] = $browser_filter;
			}
			if ( $this->resolve_spam_exclusion_from_request( $_POST ) ) {
				$vt_filters['exclude_spam'] = true;
			}

			$canonical_visitor_counts = $this->get_canonical_visitor_type_counts( $page_id, $date_range, $vt_filters, $dim_device_scope );
			if ( is_array( $canonical_visitor_counts ) ) {
				$counts['visitor_types'] = $canonical_visitor_counts;
			}

			// Countries / Browsers option counts on the same canonical scope.
			// Interdependent filtering preserved: the browser filter narrows
			// country counts and vice versa, but never the dimension itself.
			// Visitor-type filter applies to both (matches the scan behavior).
			$dim_base = $vt_filters;
			unset( $dim_base['country'], $dim_base['browser'] );
			if ( isset( $filters['visitor_type'] ) ) {
				$dim_base['visitor_type'] = $filters['visitor_type'];
			}

			$country_dim_filters = $dim_base;
			if ( ! empty( $browser_filter ) ) {
				$country_dim_filters['browser'] = $browser_filter;
			}
			$canonical_countries = $this->get_canonical_session_dimension_counts( $page_id, $date_range, $country_dim_filters, $dim_device_scope, 'country' );
			if ( is_array( $canonical_countries ) ) {
				$options['countries'] = array_keys( $canonical_countries );
				$counts['countries']  = $canonical_countries;
			}

			$browser_dim_filters = $dim_base;
			if ( ! empty( $country_filter ) ) {
				$browser_dim_filters['country'] = $country_filter;
			}
			$canonical_browsers = $this->get_canonical_session_dimension_counts( $page_id, $date_range, $browser_dim_filters, $dim_device_scope, 'browser' );
			if ( is_array( $canonical_browsers ) ) {
				$options['browsers'] = array_keys( $canonical_browsers );
				$counts['browsers']  = $canonical_browsers;
			}

			// OS / UTM option counts on the same canonical scope (bugfix
			// follow-up: the previous "DB row wins when a value is present"
			// merge discarded the file-scan count entirely for any value the
			// DB also knew about — e.g. DB "Windows: 5" replacing a real file
			// universe of 100 Windows sessions — so the dropdown sum fell far
			// short of the pill total. Same canonical DB + admissible-file
			// union as Countries/Browsers now applies to OS/UTM too, with an
			// explicit 'Unknown' bucket for sessions carrying no value for the
			// dimension (legacy filenames / DB NULLs), so every list keeps
			// summing to the pill's file_total_all.
			$other_dim_filters = $dim_base;
			if ( isset( $filters['visitor_type'] ) ) {
				$other_dim_filters['visitor_type'] = $filters['visitor_type'];
			}
			if ( ! empty( $country_filter ) ) {
				$other_dim_filters['country'] = $country_filter;
			}
			if ( ! empty( $browser_filter ) ) {
				$other_dim_filters['browser'] = $browser_filter;
			}

			$dim_key_map = array(
				'os'           => 'os',
				'utm_campaign' => 'utm_campaigns',
				'utm_source'   => 'utm_sources',
				'utm_medium'   => 'utm_mediums',
			);
			foreach ( $dim_key_map as $dimension => $option_key ) {
				$canonical_dim = $this->get_canonical_session_dimension_counts( $page_id, $date_range, $other_dim_filters, $dim_device_scope, $dimension );
				if ( is_array( $canonical_dim ) ) {
					$options[ $option_key ] = array_keys( $canonical_dim );
					$counts[ $option_key ]  = $canonical_dim;
				}
			}
		}

		sort( $options['os'] );
		sort( $options['utm_campaigns'] );
		sort( $options['utm_sources'] );
		sort( $options['utm_mediums'] );

		// Map country codes to country names for display.
		$country_names = $this->get_country_names();
		$countries_with_names = array();
		foreach ( $options['countries'] as $code ) {
			$countries_with_names[ $code ] = isset( $country_names[ $code ] ) ? $country_names[ $code ] : $code;
		}

		// File-universe session scope for the DB-backed Pages & Traffic counts:
		// union of the per-country suffix sets intersected with the union of
		// the per-browser suffix sets. The scan applies browser_filter to the
		// country sets and country_filter to the browser sets, so the
		// intersection equals the current view scope (type folder + device +
		// date + visitor + spam + variant + interdependent filters). Without
		// this scope the DB counts would include sessions that never produced
		// a heatmap file and disagree with the hero session count.
		$scope_suffix_lookup = array();
		foreach ( $session_sets['countries'] as $suffix_set ) {
			$scope_suffix_lookup += $suffix_set;
		}
		$browser_suffix_lookup = array();
		foreach ( $session_sets['browsers'] as $suffix_set ) {
			$browser_suffix_lookup += $suffix_set;
		}
		$scope_suffix_lookup = array_intersect_key( $scope_suffix_lookup, $browser_suffix_lookup );

		// DB-backed Pages & Traffic options (entry/exit/referrer/traffic channel)
		// with per-option session counts, scoped to the page group + date range
		// + the file-universe session scope above.
		$db_options = $this->get_db_filter_option_counts( $page_ids, $date_range, $start_date, $end_date, $scope_suffix_lookup );

		wp_send_json_success( array(
			'countries'        => $countries_with_names,
			'browsers'         => $options['browsers'],
			'devices'          => $options['devices'],
			'os'               => $options['os'],
			'utm_campaigns'    => $options['utm_campaigns'],
			'utm_sources'      => $options['utm_sources'],
			'utm_mediums'      => $options['utm_mediums'],
			'counts'           => $counts,
			'traffic_channels' => $db_options['traffic_channels'],
			'entry_pages'      => $db_options['entry_pages'],
			'exit_pages'       => $db_options['exit_pages'],
			'referrers'        => $db_options['referrers'],
		) );
	}

	/**
	 * Session-scoped date WHERE clause for filter-option count queries.
	 *
	 * Accepts the date-range aliases the heatmap detail page sends
	 * (today / yesterday / 7days / 30days / custom + legacy spellings).
	 *
	 * @param string $date_range Date range keyword.
	 * @param string $start_date Custom range start (Y-m-d).
	 * @param string $end_date   Custom range end (Y-m-d).
	 * @param string $column     Fully qualified datetime column (e.g. 's.start_time').
	 * @return string SQL fragment beginning with ' AND ' or ''.
	 */
	private function get_session_date_where_clause( $date_range, $start_date, $end_date, $column ) {
		if ( 'custom' === $date_range && $start_date && $end_date ) {
			$start = gmdate( 'Y-m-d 00:00:00', strtotime( $start_date ) );
			$end   = gmdate( 'Y-m-d 23:59:59', strtotime( $end_date ) );
			return " AND {$column} BETWEEN '" . esc_sql( $start ) . "' AND '" . esc_sql( $end ) . "'";
		}

		switch ( $date_range ) {
			case 'today':
				return " AND DATE({$column}) = CURDATE()";
			case 'yesterday':
				return " AND DATE({$column}) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
			case '7days':
			case 'last7days':
			case 'last_7_days':
				return " AND {$column} >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
			case '30days':
			case 'last30days':
			case 'last_30_days':
				return " AND {$column} >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
		}

		return '';
	}

	/**
	 * DB-backed filter options with per-option session counts for the Pages &
	 * Traffic fields (entry page, exit page, referrer, traffic channel).
	 *
	 * These dimensions only exist in the sessions / session_pages tables, so
	 * counts are computed there, scoped to sessions that visited the canonical
	 * page group within the selected date range. Referrers are domain-normalized
	 * (scheme/path/query/www. stripped) and the site's own host is excluded,
	 * matching the Analytics Dashboard suggestions.
	 *
	 * @param array      $page_ids         Canonical page IDs.
	 * @param string     $date_range       Date range keyword.
	 * @param string     $start_date       Custom range start.
	 * @param string     $end_date         Custom range end.
	 * @param array|null $allowed_suffixes Optional lookup of 8-char session
	 *                                     suffixes (suffix => set) limiting the
	 *                                     counts to the heatmap file universe.
	 *                                     null = no restriction; empty array =
	 *                                     no sessions in scope (all lists empty).
	 * @return array { traffic_channels, entry_pages, exit_pages, referrers } as {value,count} lists.
	 */
	private function get_db_filter_option_counts( $page_ids, $date_range, $start_date = '', $end_date = '', $allowed_suffixes = null ) {
		global $wpdb;

		$result = array(
			'traffic_channels' => array(),
			'entry_pages'      => array(),
			'exit_pages'       => array(),
			'referrers'        => array(),
		);

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return $result;
		}

		// CANONICAL UNIVERSE (master decision 2026-08-13, plan step 198): Traffic
		// Channel / Entry Page / Exit Page / Referrer option counts come from the
		// SAME canonical human pageview-session universe as the header pill (Free
		// repository, distinct optibehavior_pageviews.session_id, spam-excluded,
		// group-resolved), so an option's "(N)" matches the pill instead of the
		// heatmap-file / session-suffix universe (was Direct 4, entry 3/1, exit 4).
		// traffic_channel is a closed dimension (sums to the pill, incl. an
		// 'Unknown' bucket); entry / exit / referrer are open typeahead suggestions
		// (blank / same-site values dropped, so their sum may be <= the pill). Only
		// the page's Period selector scopes it (device / other filters do NOT
		// re-scope — same rule as the chips & Visitor Type). The legacy
		// session_pages / suffix path below stays ONLY as a pre-bridge fallback.
		$cd_period   = in_array( $date_range, array( 'all', 'today', 'last7days', 'last30days', 'custom' ), true ) ? $date_range : 'all';
		$bridge_dims = array(
			'traffic_channel' => 'traffic_channels',
			'entry_page'      => 'entry_pages',
			'exit_page'       => 'exit_pages',
			'referrer'        => 'referrers',
		);
		$bridge_result = array();
		$bridge_ok     = true;
		foreach ( $bridge_dims as $dimension => $result_key ) {
			$counts = $this->get_canonical_dimension_counts_via_bridge( $page_ids, $dimension, $cd_period, $start_date, $end_date );
			if ( ! is_array( $counts ) ) {
				$bridge_ok = false;
				break;
			}
			$list = array();
			foreach ( $counts as $value => $count ) {
				$list[] = array(
					'value' => (string) $value,
					'count' => (int) $count,
				);
			}
			$bridge_result[ $result_key ] = $list;
		}
		if ( $bridge_ok ) {
			return array_merge( $result, $bridge_result );
		}

		$scoped = is_array( $allowed_suffixes );
		if ( $scoped && empty( $allowed_suffixes ) ) {
			return $result;
		}

		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		$pages_table    = $wpdb->prefix . 'optibehavior_session_pages';

		$page_placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		$page_scope        = "EXISTS ( SELECT 1 FROM {$pages_table} spx WHERE spx.session_id = s.id AND spx.page_id IN ( {$page_placeholders} ) )";
		$session_date      = $this->get_session_date_where_clause( $date_range, $start_date, $end_date, 's.start_time' );
		$session_base      = "FROM {$sessions_table} s WHERE {$page_scope}{$session_date}";

		$to_value_count = function ( $rows ) {
			$out = array();
			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'value' => (string) $row->value,
					'count' => (int) $row->count,
				);
			}
			return $out;
		};

		// Session-suffix check against the file-universe scope (last 8 chars of
		// the cleaned session id — same derivation the filename scanners use).
		$suffix_allowed = function ( $session_id ) use ( $scoped, $allowed_suffixes ) {
			if ( ! $scoped ) {
				return true;
			}
			$clean = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $session_id );
			return isset( $allowed_suffixes[ substr( $clean, -8 ) ] );
		};

		// PHP-side aggregation of per-session rows (sid, value) into a sorted
		// {value,count} list limited to the scope. Rows are one-per-session, so
		// a plain increment is a unique-session count.
		$aggregate = function ( $rows, $limit = 50 ) use ( $suffix_allowed ) {
			$agg = array();
			foreach ( (array) $rows as $row ) {
				if ( '' === (string) $row->value || ! $suffix_allowed( $row->sid ) ) {
					continue;
				}
				$key = (string) $row->value;
				$agg[ $key ] = isset( $agg[ $key ] ) ? $agg[ $key ] + 1 : 1;
			}
			arsort( $agg );
			$out = array();
			foreach ( array_slice( $agg, 0, $limit, true ) as $value => $count ) {
				$out[] = array(
					'value' => (string) $value,
					'count' => (int) $count,
				);
			}
			return $out;
		};

		// Entry / exit page suggestions (session-count basis).
		foreach ( array( 'entry_page' => 'entry_pages', 'exit_page' => 'exit_pages' ) as $column => $result_key ) {
			if ( $scoped ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded column/table names from $wpdb->prefix; page IDs prepared below.
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT s.id AS sid, s.{$column} AS value {$session_base} AND s.{$column} IS NOT NULL AND s.{$column} != '' LIMIT 20000",
					$page_ids
				) );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$result[ $result_key ] = $aggregate( $rows );
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded column/table names from $wpdb->prefix; page IDs prepared below.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.{$column} AS value, COUNT(*) AS count {$session_base} AND s.{$column} IS NOT NULL AND s.{$column} != '' GROUP BY s.{$column} ORDER BY count DESC LIMIT 50",
				$page_ids
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$result[ $result_key ] = $to_value_count( $rows );
		}

		// Referrer suggestions: domain-normalized, own host + empty excluded
		// (own host is "Direct", not a typeable referrer). The suggested domain
		// feeds the LIKE-based referrer filter, so it always matches its own URLs.
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$site_host = $site_host ? preg_replace( '/^www\./i', '', $site_host ) : '';
		if ( $scoped ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables from $wpdb->prefix; page IDs prepared below.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.id AS sid, s.referrer AS value {$session_base} AND s.referrer IS NOT NULL AND s.referrer != '' LIMIT 20000",
				$page_ids
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// Normalize to a bare domain (PHP mirror of the SQL expression below).
			foreach ( (array) $rows as $row ) {
				$domain = str_ireplace( array( 'https://', 'http://' ), '', trim( (string) $row->value ) );
				$domain = explode( '/', $domain, 2 )[0];
				$domain = explode( '?', $domain, 2 )[0];
				$domain = preg_replace( '/^www\./i', '', $domain );
				$row->value = ( '' !== $site_host && strcasecmp( $domain, $site_host ) === 0 ) ? '' : $domain;
			}
			$result['referrers'] = $aggregate( $rows );
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables from $wpdb->prefix, host esc_sql()-escaped; page IDs prepared below.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(s.referrer, 'https://', ''), 'http://', ''), '/', 1), '?', 1), 'www.', '') AS value,
						COUNT(*) AS count
				 {$session_base}
				 AND s.referrer IS NOT NULL AND s.referrer != ''
				 GROUP BY value
				 HAVING value != '' AND value != '" . esc_sql( $site_host ) . "'
				 ORDER BY count DESC
				 LIMIT 50",
				$page_ids
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$result['referrers'] = $to_value_count( $rows );
		}

		// Traffic channels: DERIVED per-session from referrer + UTM with the same
		// SQL CASE the traffic_channel filter uses (the stored
		// session_pages.traffic_channel column is never populated), so counts
		// and filter results can never disagree. Unique sessions per channel.
		$channel_case = $this->get_traffic_channel_case_sql();
		if ( $scoped ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded column/table names from $wpdb->prefix; page IDs prepared below.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.id AS sid, ( {$channel_case} ) AS value {$session_base} LIMIT 20000",
				$page_ids
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$result['traffic_channels'] = $aggregate( $rows );
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded column/table names from $wpdb->prefix; page IDs prepared below.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT ( {$channel_case} ) AS value, COUNT(DISTINCT s.id) AS count
				 {$session_base}
				 GROUP BY value
				 ORDER BY count DESC",
				$page_ids
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$result['traffic_channels'] = $to_value_count( $rows );
		}

		return $result;
	}

	/**
	 * SQL CASE expression deriving the six Traffic Channel buckets (Direct /
	 * Organic Search / Paid Ads / Social Media / Email / Referral) from
	 * s.referrer + s.utm_source + s.utm_medium.
	 *
	 * Port of the Analytics Dashboard's build_traffic_channel_case_sql(): the
	 * stored session_pages.traffic_channel column is never written, so the
	 * channel must be derived per-row. Percent signs are doubled ("%%") because
	 * every caller passes the final query through $wpdb->prepare().
	 *
	 * @return string SQL CASE expression (no trailing alias).
	 */
	private function get_traffic_channel_case_sql() {
		$search_needles = array(
			'google.', 'bing.com', 'duckduckgo.com', 'yahoo.', 'yandex.', 'baidu.', 'naver.com',
			'ecosia.org', 'startpage.com', 'qwant.com', 'search.brave.com', 'kagi.com',
			'seznam.cz', 'sogou.com',
			// AI assistants / answer engines classified as Organic Search.
			'chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'claude.ai', 'copilot.microsoft.com',
		);
		$social_needles = array(
			'facebook.com', '=fb.com', '=fb.me', 'instagram.com',
			'twitter.com', '=x.com', '=t.co', 'linkedin.com', '=lnkd.in',
			'pinterest.', '=pin.it', 'reddit.com', '=redd.it',
			'tiktok.com', 'snapchat.com', 'youtube.com', '=youtu.be',
			'threads.net', 'threads.com', 'bsky.app', 'mastodon.',
			'whatsapp.com', '=wa.me', 'telegram.org', '=t.me',
			'discord.com', 'discord.gg', 'twitch.tv', '=vk.com', 'weibo.com',
			'quora.com', 'nextdoor.com',
		);

		$needle_likes = function ( $needle ) {
			if ( 0 === strpos( $needle, '=' ) ) {
				// Exact-host needle: anchor on "://host" so short domains cannot
				// match inside longer ones ("x.com" inside gmx.com etc.).
				$host = esc_sql( substr( $needle, 1 ) );
				return "(s.referrer LIKE '%%://" . $host . "/%%'"
					. " OR s.referrer LIKE '%%://www." . $host . "/%%'"
					. " OR s.referrer LIKE '%%://" . $host . "'"
					. " OR s.referrer LIKE '%%://www." . $host . "')";
			}
			return "s.referrer LIKE '%%" . esc_sql( $needle ) . "%%'";
		};

		$search_likes = array_map( $needle_likes, $search_needles );
		$social_likes = array_map( $needle_likes, $social_needles );

		return "CASE
				WHEN s.referrer IS NOT NULL AND s.referrer <> '' AND (" . implode( ' OR ', $search_likes ) . ") THEN 'Organic Search'
				WHEN s.referrer IS NOT NULL AND s.referrer <> '' AND (" . implode( ' OR ', $social_likes ) . ") THEN 'Social Media'
				WHEN s.referrer IS NOT NULL AND s.referrer <> '' THEN 'Referral'
				WHEN LOWER(TRIM(s.utm_medium)) IN ('cpc','ppc','paid','paid_search') OR LOWER(TRIM(s.utm_source)) IN ('cpc','ppc','paid','paid_search') THEN 'Paid Ads'
				WHEN LOWER(TRIM(s.utm_medium)) IN ('email','newsletter') OR LOWER(TRIM(s.utm_source)) IN ('email','newsletter') THEN 'Email'
				WHEN LOWER(TRIM(s.utm_medium)) IN ('social','social_media') OR LOWER(TRIM(s.utm_source)) IN ('social','social_media') THEN 'Social Media'
				WHEN LOWER(TRIM(s.utm_medium)) = 'organic' OR LOWER(TRIM(s.utm_source)) = 'organic' THEN 'Organic Search'
				WHEN (s.utm_source IS NOT NULL AND s.utm_source <> '') OR (s.utm_medium IS NOT NULL AND s.utm_medium <> '') THEN 'Referral'
				ELSE 'Direct'
			END";
	}

	/**
	 * DB-backed OS / UTM option values with unique-session counts, scoped to the
	 * canonical page group + date range.
	 *
	 * Legacy heatmap filenames carry no OS/UTM tokens, so the filename scan
	 * alone shows "no data" on established sites. These DB values (v.os and
	 * s.utm_*) are merged with the filename-scan values by the filter-options
	 * endpoint.
	 *
	 * @param array  $page_ids   Canonical page IDs.
	 * @param string $date_range Date range keyword.
	 * @param string $start_date Custom range start.
	 * @param string $end_date   Custom range end.
	 * @return array { os, utm_campaigns, utm_sources, utm_mediums } as value => count maps.
	 */
	private function get_db_visitor_dimension_counts( $page_ids, $date_range, $start_date = '', $end_date = '' ) {
		global $wpdb;

		$result = array(
			'os'            => array(),
			'utm_campaigns' => array(),
			'utm_sources'   => array(),
			'utm_mediums'   => array(),
		);

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return $result;
		}

		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		$visitors_table = $wpdb->prefix . 'optibehavior_visitors';
		$pages_table    = $wpdb->prefix . 'optibehavior_session_pages';

		$page_placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		$page_scope        = "EXISTS ( SELECT 1 FROM {$pages_table} spx WHERE spx.session_id = s.id AND spx.page_id IN ( {$page_placeholders} ) )";
		$session_date      = $this->get_session_date_where_clause( $date_range, $start_date, $end_date, 's.start_time' );

		$dimensions = array(
			'os'            => array( 'v.os', "LEFT JOIN {$visitors_table} v ON s.visitor_id = v.id" ),
			'utm_campaigns' => array( 's.utm_campaign', '' ),
			'utm_sources'   => array( 's.utm_source', '' ),
			'utm_mediums'   => array( 's.utm_medium', '' ),
		);

		foreach ( $dimensions as $result_key => $dim ) {
			list( $column, $join ) = $dim;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded column/table names from $wpdb->prefix; page IDs prepared below.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT TRIM({$column}) AS value, COUNT(DISTINCT s.id) AS count
				 FROM {$sessions_table} s {$join}
				 WHERE {$page_scope}{$session_date}
				 AND {$column} IS NOT NULL AND TRIM({$column}) != ''
				 GROUP BY value
				 ORDER BY count DESC
				 LIMIT 100",
				$page_ids
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
			foreach ( (array) $rows as $row ) {
				$value = trim( (string) $row->value );
				if ( '' !== $value && strtolower( $value ) !== 'unknown' ) {
					$result[ $result_key ][ $value ] = (int) $row->count;
				}
			}
		}

		return $result;
	}

	/**
	 * Get country code to name mapping.
	 *
	 * @return array Country code => name mapping.
	 */
	private function get_country_names() {
		return array(
			'AF' => 'Afghanistan',
			'AL' => 'Albania',
			'DZ' => 'Algeria',
			'AR' => 'Argentina',
			'AU' => 'Australia',
			'AT' => 'Austria',
			'BE' => 'Belgium',
			'BR' => 'Brazil',
			'CA' => 'Canada',
			'CL' => 'Chile',
			'CN' => 'China',
			'CO' => 'Colombia',
			'CZ' => 'Czech Republic',
			'DK' => 'Denmark',
			'EG' => 'Egypt',
			'FI' => 'Finland',
			'FR' => 'France',
			'DE' => 'Germany',
			'GR' => 'Greece',
			'HK' => 'Hong Kong',
			'HU' => 'Hungary',
			'IN' => 'India',
			'ID' => 'Indonesia',
			'IE' => 'Ireland',
			'IL' => 'Israel',
			'IT' => 'Italy',
			'JP' => 'Japan',
			'KR' => 'South Korea',
			'MY' => 'Malaysia',
			'MX' => 'Mexico',
			'NL' => 'Netherlands',
			'NZ' => 'New Zealand',
			'NO' => 'Norway',
			'PK' => 'Pakistan',
			'PH' => 'Philippines',
			'PL' => 'Poland',
			'PT' => 'Portugal',
			'RO' => 'Romania',
			'RU' => 'Russia',
			'SA' => 'Saudi Arabia',
			'SG' => 'Singapore',
			'ZA' => 'South Africa',
			'ES' => 'Spain',
			'SE' => 'Sweden',
			'CH' => 'Switzerland',
			'TW' => 'Taiwan',
			'TH' => 'Thailand',
			'TR' => 'Turkey',
			'UA' => 'Ukraine',
			'AE' => 'United Arab Emirates',
			'GB' => 'United Kingdom',
			'US' => 'United States',
			'VN' => 'Vietnam',
			'XX' => 'Unknown',
		);
	}

	/**
	 * Generate statistics from recordings.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $device Device type.
	 * @param string $date_range Date range.
	 * @param string $type Heatmap type ('click', 'move', 'scroll').
	 * @param array  $filters Optional filters (country, browser, visitor_type).
	 * @return array|WP_Error Statistics or error.
	 */
	private function generate_statistics( $page_id, $device, $date_range, $type = 'click', $filters = array() ) {
		$this->debug_log( "[generate_statistics] START page_id=$page_id device=$device date_range=$date_range type=$type", 'info' );

		// =====================================================================
		// PAGE-LEVEL STATS
		// The heatmap detail page shows ONE universe: sessions that produced a
		// heatmap file of the current type. Views / Avg Time / event counts all
		// come from the file scan, so the stats bar always matches the hero
		// session count, the device badges and the rendered data. The
		// session_pages DB stats (which also count sessions that never produced
		// a heatmap file) are only used to fill avg_scroll — not derivable from
		// filenames — and as a full fallback when no files exist at all.
		// =====================================================================

		$file_stats = $this->count_events_from_files( $page_id, $device, $date_range, $type, $filters );

		// CANONICAL VIEWS (master decision 2026-08-13, plan step 185 req 2): the
		// "Views" KPI shows the SAME canonical human pageview-session universe as
		// the header pill / device chips for the current device, NOT the hybrid
		// file-scan session count (which drifted to 55 vs a pill of 46 because it
		// admitted orphan file sessions the DB no longer knows). Clicks / Moves /
		// Scrolls / Avg Time stay file-derived (interactions only exist in files)
		// and are relabeled in the UI as heatmap-interaction metrics. Falls back
		// to the file view count only when the Free bridge is unavailable.
		$canonical_views = $this->get_canonical_views_for_device( $page_id, $device, $date_range, $filters );
		if ( null !== $canonical_views ) {
			$file_stats['total_views'] = (int) $canonical_views;
		}

		if ( ! empty( $file_stats['total_views'] ) ) {
			if ( empty( $file_stats['avg_scroll'] ) ) {
				$session_page_stats = $this->get_session_page_quick_stats( $page_id, $device, $date_range, $filters );
				if ( null !== $session_page_stats && ! empty( $session_page_stats['avg_scroll'] ) ) {
					$file_stats['avg_scroll'] = $session_page_stats['avg_scroll'];
				}
			}
			$this->debug_log( '[generate_statistics] RESULT (file-based, canonical views overlay): ' . wp_json_encode( $file_stats ), 'info' );
			return $file_stats;
		}

		$session_page_stats = $this->get_session_page_quick_stats( $page_id, $device, $date_range, $filters );
		if ( null !== $session_page_stats && ! empty( $session_page_stats['total_views'] ) ) {
			$this->debug_log( '[generate_statistics] RESULT (session_pages fallback): ' . wp_json_encode( $session_page_stats ), 'info' );
			return $session_page_stats;
		}

		$this->debug_log( '[generate_statistics] RESULT (file-based): ' . wp_json_encode( $file_stats ), 'info' );
		return $file_stats;
	}

	/**
	 * Count heatmap interaction events (clicks / moves / scrolls) plus unique
	 * sessions and avg time by scanning the heatmap FILE STORAGE ? the same
	 * source generate_heatmap_data_fast() renders from. This is the single
	 * source of truth for the header KPI counts.
	 *
	 * Filename format: timestamp_country_browser_count_device_L/G_duration_session.json
	 *
	 * @param int    $page_id    Page ID.
	 * @param string $device     Device type.
	 * @param string $date_range Date range.
	 * @param string $type       Heatmap type (click/move/scroll) ? engagement basis.
	 * @param array  $filters    Optional filters (country, browser, visitor_type, ...).
	 * @param array|null $element_click_tally Opt-in by-ref accumulator. When an
	 *                   array is passed, every click point of every admitted
	 *                   click file is tallied by its element XPath anchor
	 *                   (key = xp string, '' = unanchored residual). This lets
	 *                   the Top Clicked Elements panel aggregate from the EXACT
	 *                   same filtered file set as the Clicks strip, so the two
	 *                   can never diverge. Left null on the hot stats path.
	 * @return array Stats array (total_views/clicks/moves/scrolls, avg_time, engagement_rate, avg_scroll, scroll_sessions).
	 */
	private function count_events_from_files( $page_id, $device, $date_range, $type = 'click', $filters = array(), &$element_click_tally = null ) {
		global $wpdb;

		$empty_result = array(
			'total_views'     => 0,
			'total_clicks'    => 0,
			'total_moves'     => 0,
			'total_scrolls'   => 0,
			'avg_time'        => 0,
			'engagement_rate' => 0,
			'avg_scroll'      => 0,
			'scroll_sessions' => 0,
		);

		// Get URL hashes for the canonical page group ? use heatmap_pages mapping table first (like fast path),
		// which stores the normalised hash (query-params stripped, no trailing slash).
		$upload_dir = wp_upload_dir();
		$page_ids   = $this->get_filter_page_ids( $page_id, $filters );
		$base_dirs  = array();

		foreach ( $page_ids as $canonical_page_id ) {
			// A page's file universe is the union of ALL its heatmap_pages
			// mapping-table rows (a page can have more than one - e.g. a stale
			// QA URL hash alongside the live one) plus the 3 md5 URL-variant
			// hashes (legacy data). Scan every dir found - never stop at the
			// first hit, or a stale row can shadow the real data. See spec
			// §3.1 (RC-A/RC-B).
			$page_url_hashes = array();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$mapped_hashes = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT url_hash FROM {$wpdb->prefix}optibehavior_heatmap_pages WHERE page_id = %d ORDER BY id ASC",
					$canonical_page_id
				)
			);
			foreach ( (array) $mapped_hashes as $mapped_hash ) {
				if ( $mapped_hash ) {
					$page_url_hashes[] = (string) $mapped_hash;
				}
			}

			// Fallback / legacy: compute from raw URL with variations.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$url = $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$wpdb->prefix}optibehavior_pages WHERE id = %d", $canonical_page_id ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( $url ) {
				$page_url_hashes[] = md5( $url );
				$page_url_hashes[] = md5( rtrim( $url, '/' ) );
				$page_url_hashes[] = md5( $url . '/' );
			} elseif ( empty( $mapped_hashes ) ) {
				$this->debug_log( "[generate_statistics] No URL found for page_id: $canonical_page_id", 'info' );
			}

			foreach ( array_unique( $page_url_hashes ) as $url_hash ) {
				$test_dir = $upload_dir['basedir'] . '/opti-behavior-data/' . $url_hash;
				if ( is_dir( $test_dir ) ) {
					$base_dirs[] = $test_dir;
				}
			}
		}

		$base_dirs = array_values( array_unique( $base_dirs ) );
		if ( empty( $base_dirs ) ) {
			$this->debug_log( "[generate_statistics] No base_dir found for page_ids: " . implode( ',', $page_ids ), 'info' );
			return $empty_result;
		}

		// Calculate date range boundaries
		$date_boundaries = $this->get_date_boundaries( $date_range, $filters );
		$start_timestamp = $date_boundaries['start'];
		$end_timestamp   = $date_boundaries['end'];

		// Normalize device for comparison
		$device_lower = strtolower( $device );

		// Extract filter values. Country and browser are multi-select lists.
		$filter_countries    = $this->normalize_heatmap_multi_select_filter( $filters['country'] ?? '', 'upper' );
		$filter_browsers     = $this->normalize_heatmap_multi_select_filter( $filters['browser'] ?? '', 'lower' );
		$filter_visitor_type = ! empty( $filters['visitor_type'] ) ? strtolower( $filters['visitor_type'] ) : '';

		// Direct A/B variant filtering by test_id and variant_id (preferred over session_ids).
		$opti_ab_filter_test_id    = ! empty( $filters['ab_test_id'] ) ? (int) $filters['ab_test_id'] : 0;
		$opti_ab_filter_variant_id = ! empty( $filters['variant_id'] ) ? (int) $filters['variant_id'] : 0;

		$spam_lookup  = array();
		$known_lookup = null; // null = orphan gate not applicable (spam filtering off, or bridge unavailable).
		if ( ! empty( $filters['exclude_spam'] ) ) {
			$spam_lookup  = $this->build_session_lookup_map( $this->get_spam_allowed_session_ids( $page_ids ) );
			$known_lookup = $this->get_known_db_session_lookup_via_bridge( $page_ids );
			// Orphan-aware gate (RC-C, spec §3.2): when the Free bridge can tell
			// which file tokens have NO DB trace at all, an empty spam allow-list
			// no longer means "zero views" — orphan sessions may still exist and
			// must be scanned/counted. Only fall back to the old "empty = zero"
			// behavior when the bridge itself is unavailable.
			if ( null === $known_lookup && empty( $spam_lookup ) ) {
				return $empty_result;
			}
		}

		// Pre-compute short session ID suffixes for A/B variant filtering (legacy fallback).
		$short_session_ids = null;
		if ( ! empty( $filters['session_ids'] ) && is_array( $filters['session_ids'] ) ) {
			$short_session_ids = array();
			foreach ( $filters['session_ids'] as $opti_behavior_sid ) {
				$opti_behavior_sid_clean = preg_replace( '/[^a-zA-Z0-9]/', '', $opti_behavior_sid );
				$short_session_ids[]     = substr( $opti_behavior_sid_clean, -8 );
			}
		}

		// The "Views" KPI must count the same sessions the hero counter and the
		// rendered heatmap use: sessions with a file in the CURRENT type's
		// folder. The other folders are still scanned for the per-type event
		// totals and the engagement denominator.
		$view_folder = 'clicks';
		if ( 'move' === $type ) {
			$view_folder = 'moves';
		} elseif ( in_array( $type, array( 'scroll', 'attention' ), true ) ) {
			$view_folder = 'scrolls';
		}

		// Accumulators
		$sessions          = array(); // session_id => true across ALL folders (engagement denominator)
		$view_sessions     = array(); // session_id => true in the current type's folder (Views KPI)
		$session_durations = array(); // session_id => max duration in the view folder (for avg time)
		$total_clicks      = 0;
		$total_moves       = 0;
		$total_scrolls     = 0;
		$scroll_sessions   = 0; // sessions that have scroll data (for engagement)

		// Single pass through all event type folders
		$type_folders = array( 'clicks', 'moves', 'scrolls' );
		foreach ( $base_dirs as $base_dir ) {
			foreach ( $type_folders as $folder_name ) {
				$dir = $base_dir . '/' . $folder_name;
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$files = glob( $dir . '/*.json' );
			if ( empty( $files ) ) {
				continue;
			}

			foreach ( $files as $file ) {
				$filename = basename( $file );
				// Parse filename: timestamp_country_browser_count_device_L/G_duration_session.json
				$parts = explode( '_', str_replace( '.json', '', $filename ) );
				if ( count( $parts ) < 6 ) {
					continue;
				}

				$timestamp   = $parts[0];
				$country     = $parts[1];
				$browser     = $parts[2];
				$count       = intval( $parts[3] );
				$file_device = $parts[4];

				// Determine format and extract visitor type marker, duration, session, and AB info
				$logged_in_marker    = null;
				$session             = null;
				$duration            = 0;
				$file_ab_test_id     = 0;
				$file_ab_variant_id  = 0;

				// Extended format (14 parts): ..._L/G_duration_abTestId_variantId_os_utmC_utmS_utmM_session
				if ( count( $parts ) >= 14 && in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true ) && is_numeric( $parts[7] ) && is_numeric( $parts[8] ) ) {
					$logged_in_marker   = strtoupper( $parts[5] );
					$duration           = intval( $parts[6] );
					$file_ab_test_id    = intval( $parts[7] );
					$file_ab_variant_id = intval( $parts[8] );
					$session            = $parts[13];
				}
				// AB format (10 parts): timestamp_country_browser_count_device_L/G_duration_abTestId_variantId_session
				elseif ( count( $parts ) === 10 && in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true ) ) {
					$logged_in_marker   = strtoupper( $parts[5] );
					$duration           = intval( $parts[6] );
					$file_ab_test_id    = intval( $parts[7] );
					$file_ab_variant_id = intval( $parts[8] );
					$session            = $parts[9];
				}
				// Newest format (8 parts): timestamp_country_browser_count_device_L/G_duration_session
				elseif ( count( $parts ) >= 8 && in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true ) ) {
					$logged_in_marker = strtoupper( $parts[5] );
					$duration         = intval( $parts[6] );
					$session          = $parts[7];
				}
				// New format (7 parts): timestamp_country_browser_count_device_L/G_session
				elseif ( count( $parts ) >= 7 && in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true ) ) {
					$logged_in_marker = strtoupper( $parts[5] );
					$session          = $parts[6];
				}
				// Old format (6 parts): timestamp_country_browser_count_device_session
				else {
					$logged_in_marker = 'G';
					$session          = $parts[5];
				}

				if ( null !== $known_lookup ) {
					if ( ! $this->is_session_allowed_or_orphan_by_lookup( $session, $spam_lookup, $known_lookup ) ) {
						continue;
					}
				} elseif ( ! $this->is_session_allowed_by_lookup( $session, $spam_lookup ) ) {
					continue;
				}

				// Apply device filter
				$file_device_lower = strtolower( $file_device );
				if ( $file_device_lower === 'phone' ) {
					$file_device_lower = 'mobile';
				}
				if ( ! in_array( $file_device_lower, array( 'desktop', 'mobile', 'tablet' ), true ) ) {
					$file_device_lower = 'desktop';
				}
				if ( ! $this->is_all_device_scope( $device_lower ) && $file_device_lower !== $device_lower ) {
					continue;
				}

				// Apply date range filter
				$file_timestamp = intval( $timestamp );
				if ( $start_timestamp && $file_timestamp < $start_timestamp ) {
					continue;
				}
				if ( $end_timestamp && $file_timestamp > $end_timestamp ) {
					continue;
				}

				// Apply country filter (OR within the selected country list).
				if ( ! empty( $filter_countries ) && ! in_array( strtoupper( $country ), $filter_countries, true ) ) {
					continue;
				}

				// Apply browser filter (OR within the selected browser list).
				if ( ! empty( $filter_browsers ) && ! in_array( strtolower( $browser ), $filter_browsers, true ) ) {
					continue;
				}

				// Apply visitor type filter (L=Logged in, G=Guest)
				if ( $filter_visitor_type ) {
					if ( $filter_visitor_type === 'logged_in' && $logged_in_marker !== 'L' ) {
						continue;
					}
					if ( $filter_visitor_type === 'guest' && $logged_in_marker !== 'G' ) {
						continue;
					}
				}

				// Direct A/B variant filter: match ab_test_id and variant_id from filename.
				if ( $opti_ab_filter_test_id > 0 && $opti_ab_filter_variant_id > 0 ) {
					if ( $file_ab_test_id !== $opti_ab_filter_test_id || $file_ab_variant_id !== $opti_ab_filter_variant_id ) {
						continue;
					}
				}

				// A/B variant session filter (legacy fallback): match short session suffix.
				if ( null !== $short_session_ids && $session ) {
					if ( ! in_array( $session, $short_session_ids, true ) ) {
						continue;
					}
				}

				// Advanced filename-scan filters (OS / UTM / duration bounds).
				if ( ! $this->passes_advanced_filename_filters( $filters, $parts, $duration ) ) {
					continue;
				}

				// Accumulate interaction counts by folder type
				switch ( $folder_name ) {
					case 'clicks':
						// Count the DEDUPED click universe — the same points the
						// renderer/tooltip draw — instead of the raw filename
						// `count` token, which double-counts Pro session-recording
						// (rrweb) "twin" copies of anchored tracker clicks. The
						// storage helper reads the body once, drops the twins via
						// the exact routine aggregate_heatmap_data() uses, and (when
						// opted in) buckets the surviving points by XPath for the
						// Top Clicked Elements panel — so KPI, tooltip and Top
						// Elements share one deduped source of truth.
						$deduped_clicks = null;
						if ( class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
							$deduped_clicks = Opti_Behavior_Heatmap_Storage::get_instance()
								->count_click_file_deduped( $file, $element_click_tally );
						}
						if ( null === $deduped_clicks ) {
							// Body unreadable/corrupt or storage unavailable: keep
							// today's behavior (filename token + legacy tally).
							$total_clicks += $count;
							if ( is_array( $element_click_tally ) ) {
								$this->accumulate_click_file_element_tally( $file, $element_click_tally );
							}
						} else {
							$total_clicks += $deduped_clicks;
						}
						break;
					case 'moves':
						$total_moves += $count;
						break;
					case 'scrolls':
						$total_scrolls += $count;
						// Track sessions with scroll data for engagement rate
						if ( $session ) {
							$scroll_session_key = $session . '__scroll';
							if ( ! isset( $sessions[ $scroll_session_key ] ) ) {
								$scroll_sessions++;
								$sessions[ $scroll_session_key ] = true;
							}
						}
						break;
				}

				// Track unique sessions: all folders feed the engagement
				// denominator; the current type's folder feeds the Views KPI
				// and avg time (max duration per session).
				if ( $session ) {
					$sessions[ $session ] = true;
					if ( $folder_name === $view_folder ) {
						$view_sessions[ $session ] = true;
						if ( $duration > 0 ) {
							if ( ! isset( $session_durations[ $session ] ) || $duration > $session_durations[ $session ] ) {
								$session_durations[ $session ] = $duration;
							}
						}
					}
				}
			}
			}
		}

		// Views = sessions with a file of the current heatmap type (same
		// universe as the hero counter and the render).
		$total_views = count( $view_sessions );

		// Engagement denominator = unique sessions across all folders
		// (exclude scroll tracking keys).
		$total_sessions_all = 0;
		foreach ( $sessions as $key => $val ) {
			if ( substr( $key, -8 ) !== '__scroll' ) {
				$total_sessions_all++;
			}
		}

		$total_time = ! empty( $session_durations ) ? array_sum( $session_durations ) : 0;
		$avg_time   = $total_views > 0 ? round( $total_time / $total_views ) : 0;

		// Calculate engagement rate based on heatmap type
		if ( $type === 'scroll' ) {
			$engagement_rate = $total_sessions_all > 0 ? min( 100, round( ( $scroll_sessions / $total_sessions_all ) * 100, 1 ) ) : 0;
		} elseif ( $type === 'move' ) {
			$engagement_rate = $total_sessions_all > 0 ? min( 100, round( ( $total_moves / $total_sessions_all ) * 100, 1 ) ) : 0;
		} else {
			$engagement_rate = $total_sessions_all > 0 ? min( 100, round( ( $total_clicks / $total_sessions_all ) * 100, 1 ) ) : 0;
		}

		$result = array(
			'total_views'     => $total_views,
			'total_clicks'    => $total_clicks,
			'total_moves'     => $total_moves,
			'total_scrolls'   => $total_scrolls,
			'avg_time'        => $avg_time,
			'engagement_rate' => $engagement_rate,
			'avg_scroll'      => 0, // Scroll depth requires reading file content; not critical for stats display
			'scroll_sessions' => $scroll_sessions,
		);

		$this->debug_log( '[count_events_from_files] RESULT: ' . wp_json_encode( $result ), 'info' );

		return $result;
	}

	/**
	 * Count unique sessions for statistics from FILE STORAGE (single source of truth).
	 * Scans heatmap data files and extracts metadata from filenames.
	 * Applies device, date range, and other filters.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $device Device type.
	 * @param string $date_range Date range.
	 * @param array  $filters Optional filters (country, browser, visitor_type).
	 * @return int Session count.
	 */
	private function count_sessions_for_stats( $page_id, $device, $date_range, $filters = array() ) {
		global $wpdb;

		// DEBUG: Log input parameters
		$this->debug_log( "[DEBUG count_sessions_for_stats] START - page_id: $page_id, device: $device, date_range: $date_range", 'info' );
		$this->debug_log( "[DEBUG count_sessions_for_stats] Filters: " . wp_json_encode( $filters ), 'info' );

		// Get URL hash for this page
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$url = $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$wpdb->prefix}optibehavior_pages WHERE id = %d", $page_id ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! $url ) {
			$this->debug_log( "[DEBUG count_sessions_for_stats] No URL found for page_id: $page_id", 'info' );
			return 0;
		}

		// Try both URL variations (with and without trailing slash) for backwards compatibility
		$url_variations = array(
			md5( $url ),
			md5( rtrim( $url, '/' ) ),
			md5( $url . '/' ),
		);

		$upload_dir = wp_upload_dir();
		$base_dir = null;

		foreach ( $url_variations as $url_hash ) {
			$test_dir = $upload_dir['basedir'] . '/opti-behavior-data/' . $url_hash;
			if ( is_dir( $test_dir ) ) {
				$base_dir = $test_dir;
				break;
			}
		}

		if ( ! $base_dir ) {
			$this->debug_log( "[DEBUG count_sessions_for_stats] No base_dir found for URL: $url", 'info' );
			return 0;
		}

		$this->debug_log( "[DEBUG count_sessions_for_stats] base_dir: $base_dir", 'info' );

		// Calculate date range boundaries
		$date_boundaries = $this->get_date_boundaries( $date_range, $filters );
		$start_timestamp = $date_boundaries['start'];
		$end_timestamp   = $date_boundaries['end'];

		// Normalize device for comparison
		$device_lower = strtolower( $device );

		// Extract filter values. Country and browser are multi-select lists.
		$filter_countries    = $this->normalize_heatmap_multi_select_filter( $filters['country'] ?? '', 'upper' );
		$filter_browsers     = $this->normalize_heatmap_multi_select_filter( $filters['browser'] ?? '', 'lower' );
		$filter_visitor_type = ! empty( $filters['visitor_type'] ) ? strtolower( $filters['visitor_type'] ) : '';

		$this->debug_log( "[DEBUG count_sessions_for_stats] device_lower: $device_lower, filter_visitor_type: $filter_visitor_type", 'info' );

		$sessions = array();

		// DEBUG: Track filtering statistics
		$debug_stats = array(
			'total_files' => 0,
			'skipped_parts_count' => 0,
			'skipped_device' => 0,
			'skipped_date' => 0,
			'skipped_country' => 0,
			'skipped_browser' => 0,
			'skipped_visitor_type' => 0,
			'matched' => 0,
			'device_values_found' => array(),
			'visitor_type_values_found' => array(),
		);

		// Check all event type folders
		$type_folders = array( 'clicks', 'moves', 'scrolls' );
		foreach ( $type_folders as $folder_name ) {
			$dir = $base_dir . '/' . $folder_name;
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$files = glob( $dir . '/*.json' );
			if ( empty( $files ) ) {
				continue;
			}

			$this->debug_log( "[DEBUG count_sessions_for_stats] Folder: $folder_name, files count: " . count( $files ), 'info' );

			foreach ( $files as $file ) {
				$debug_stats['total_files']++;
				$filename = basename( $file );
				// Parse filename format: timestamp_country_browser_count_device_L/G_duration_session.json
				// Or older format: timestamp_country_browser_count_device_L/G_session.json
				// Or oldest format: timestamp_country_browser_count_device_session.json
				$parts = explode( '_', str_replace( '.json', '', $filename ) );
				if ( count( $parts ) < 6 ) {
					$debug_stats['skipped_parts_count']++;
					continue;
				}

				$timestamp = $parts[0];
				$country = $parts[1];
				$browser = $parts[2];
				// $count = $parts[3];
				$file_device = $parts[4];

				// Determine format and extract visitor type marker and session
				$logged_in_marker = null;
				$session = null;
				$duration = 0;

				// Extended format (14 parts): ..._L/G_duration_abTestId_variantId_os_utmC_utmS_utmM_session
				if ( count( $parts ) >= 14 && in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true ) && is_numeric( $parts[7] ) && is_numeric( $parts[8] ) ) {
					$logged_in_marker = strtoupper( $parts[5] );
					$duration = intval( $parts[6] );
					$session = $parts[13];
				}
				// AB format (10 parts): timestamp_country_browser_count_device_L/G_duration_abTestId_variantId_session
				elseif ( count( $parts ) >= 10 && in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true ) && is_numeric( $parts[7] ) && is_numeric( $parts[8] ) ) {
					$logged_in_marker = strtoupper( $parts[5] );
					$duration = intval( $parts[6] );
					$session = $parts[9];
				}
				// Check for newest format (8 parts): timestamp_country_browser_count_device_L/G_duration_session
				elseif ( count( $parts ) >= 8 && in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true ) ) {
					$logged_in_marker = strtoupper( $parts[5] );
					$duration = intval( $parts[6] );
					$session = $parts[7];
				}
				// Check for new format (7 parts): timestamp_country_browser_count_device_L/G_session
				elseif ( count( $parts ) >= 7 && in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true ) ) {
					$logged_in_marker = strtoupper( $parts[5] );
					$session = $parts[6];
				}
				// Old format (6 parts): timestamp_country_browser_count_device_session
				else {
					$logged_in_marker = 'G'; // Default to guest for old files
					$session = $parts[5];
				}

				// DEBUG: Track device and visitor type values found
				$debug_stats['device_values_found'][ $file_device ] = ( $debug_stats['device_values_found'][ $file_device ] ?? 0 ) + 1;
				$debug_stats['visitor_type_values_found'][ $logged_in_marker ] = ( $debug_stats['visitor_type_values_found'][ $logged_in_marker ] ?? 0 ) + 1;

				// Apply device filter - with fallback for unknown devices (consistent with get_recordings_by_device)
				$file_device_lower = strtolower( $file_device );
				if ( $file_device_lower === 'phone' ) {
					$file_device_lower = 'mobile';
				}
				// Treat unknown device types as 'desktop' to match get_recordings_by_device() behavior
				if ( ! in_array( $file_device_lower, array( 'desktop', 'mobile', 'tablet' ), true ) ) {
					$file_device_lower = 'desktop';
				}
				if ( ! $this->is_all_device_scope( $device_lower ) && $file_device_lower !== $device_lower ) {
					$debug_stats['skipped_device']++;
					continue;
				}

				// Apply date range filter
				$file_timestamp = intval( $timestamp );
				if ( $start_timestamp && $file_timestamp < $start_timestamp ) {
					$debug_stats['skipped_date']++;
					continue;
				}
				if ( $end_timestamp && $file_timestamp > $end_timestamp ) {
					$debug_stats['skipped_date']++;
					continue;
				}

				// Apply country filter (OR within the selected country list).
				if ( ! empty( $filter_countries ) && ! in_array( strtoupper( $country ), $filter_countries, true ) ) {
					$debug_stats['skipped_country']++;
					continue;
				}

				// Apply browser filter (OR within the selected browser list).
				if ( ! empty( $filter_browsers ) && ! in_array( strtolower( $browser ), $filter_browsers, true ) ) {
					$debug_stats['skipped_browser']++;
					continue;
				}

				// Apply visitor type filter (L=Logged in, G=Guest)
				if ( $filter_visitor_type ) {
					if ( $filter_visitor_type === 'logged_in' && $logged_in_marker !== 'L' ) {
						$debug_stats['skipped_visitor_type']++;
						continue;
					}
					if ( $filter_visitor_type === 'guest' && $logged_in_marker !== 'G' ) {
						$debug_stats['skipped_visitor_type']++;
						continue;
					}
				}

				// Advanced filename-scan filters (OS / UTM / duration bounds).
				if ( ! $this->passes_advanced_filename_filters( $filters, $parts, $duration ) ) {
					continue;
				}

				// Track unique session
				$debug_stats['matched']++;
				$sessions[ $session ] = true;
			}
		}

		// DEBUG: Log final statistics
		$this->debug_log( "[DEBUG count_sessions_for_stats] STATS: " . wp_json_encode( $debug_stats ), 'info' );
		$this->debug_log( "[DEBUG count_sessions_for_stats] RESULT: unique sessions = " . count( $sessions ), 'info' );

		return count( $sessions );
	}

	/**
	 * Get date boundaries for filtering.
	 *
	 * @param string $date_range Date range string.
	 * @return array Start and end timestamps.
	 */
	private function get_date_boundaries( $date_range, $filters = array() ) {
		$start = null;
		$end   = null;
		$now   = time();

		if ( 'custom' === $date_range && ! empty( $filters['start_date'] ) && ! empty( $filters['end_date'] ) ) {
			return array(
				'start' => strtotime( $filters['start_date'] . ' 00:00:00' ),
				'end'   => strtotime( $filters['end_date'] . ' 23:59:59' ),
			);
		}

		switch ( $date_range ) {
			case 'today':
				$start = strtotime( 'today midnight' );
				$end   = $now;
				break;
			case 'yesterday':
				$start = strtotime( 'yesterday midnight' );
				$end   = strtotime( 'today midnight' ) - 1;
				break;
			case '7days':
			case 'last7days':
			case 'last_7_days':
				$start = strtotime( '-7 days midnight' );
				$end   = $now;
				break;
			case '30days':
			case 'last30days':
			case 'last_30_days':
				$start = strtotime( '-30 days midnight' );
				$end   = $now;
				break;
			case '90days':
			case 'last_90_days':
				$start = strtotime( '-90 days midnight' );
				$end   = $now;
				break;
			case 'this_month':
				$start = strtotime( 'first day of this month midnight' );
				$end   = $now;
				break;
			case 'last_month':
				$start = strtotime( 'first day of last month midnight' );
				$end   = strtotime( 'last day of last month 23:59:59' );
				break;
			case 'all':
			default:
				// No filtering
				break;
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Get recordings for page and device.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $device Device type.
	 * @param string $date_range Date range.
	 * @param array  $filters Optional filters (country, browser, visitor_type).
	 * @return array Recordings.
	 */
	private function get_recordings( $page_id, $device, $date_range, $filters = array() ) {
		global $wpdb;

		$page_ids = $this->get_filter_page_ids( $page_id, $filters );

		$table_recordings   = $wpdb->prefix . 'optibehavior_recordings';
		$table_sessions     = $wpdb->prefix . 'optibehavior_sessions';
		$table_visitors     = $wpdb->prefix . 'optibehavior_visitors';
		$table_session_pages = $wpdb->prefix . 'optibehavior_session_pages';

		// Calculate date range.
		$where_date  = $this->get_date_where_clause( $date_range, $filters['start_date'] ?? '', $filters['end_date'] ?? '' );
		$reset_where = $this->get_heatmap_reset_where_clause( $page_id );
		$page_clause = $this->build_page_ids_clause( 'sp.page_id', $page_ids );

		// Build additional filter conditions.
		$where_filters = '';
		$filter_params = array();
		$spam_filter   = ! empty( $filters['exclude_spam'] ) ? $this->build_recordings_spam_filter_sql( 'r', 's', 'hm_get_recordings_spam_scrolls' ) : null;
		$spam_join     = $spam_filter ? $spam_filter['join'] : '';
		$spam_where    = $spam_filter ? $spam_filter['where'] : '';
		$spam_values   = $spam_filter ? $spam_filter['values'] : array();

		$country_filters = $this->normalize_heatmap_multi_select_filter( $filters['country'] ?? '', 'upper' );
		$browser_filters = $this->normalize_heatmap_multi_select_filter( $filters['browser'] ?? '', 'lower' );
		$this->append_heatmap_multi_select_sql_filter( $where_filters, $filter_params, 'UPPER(sp.country)', $country_filters );
		$this->append_heatmap_multi_select_sql_filter( $where_filters, $filter_params, 'LOWER(sp.browser)', $browser_filters );

		if ( ! empty( $filters['visitor_type'] ) ) {
			if ( $filters['visitor_type'] === 'logged_in' ) {
				$where_filters .= ' AND s.user_id IS NOT NULL AND s.user_id > 0';
			} elseif ( $filters['visitor_type'] === 'guest' ) {
				$where_filters .= ' AND (s.user_id IS NULL OR s.user_id = 0)';
			}
		}

		// Build query - join with session_pages to find recordings where the session visited this page
		// This handles multi-page sessions where page_id might not be the entry page.
		$device_where  = '';
		$device_params = array();
		if ( ! $this->is_all_device_scope( $device ) ) {
			// Use LOWER() for case-insensitive device comparison (FREE plugin uses mixed casing).
			$device_where    = 'AND LOWER(v.device_type) = LOWER(%s)';
			$device_params[] = $device;
		}

		$query = "
			SELECT DISTINCT r.*, v.device_type
			FROM {$table_recordings} r
			INNER JOIN {$table_sessions} s ON r.session_id = s.id
			INNER JOIN {$table_visitors} v ON s.visitor_id = v.id
			INNER JOIN {$table_session_pages} sp ON sp.session_id = r.session_id
			{$spam_join}
			WHERE {$page_clause}
			{$device_where}
			{$where_date}
			{$reset_where}
			{$spam_where}
			{$where_filters}
			LIMIT 100
		";

		// Build the list of parameters for the prepared query.
		$query_params = array_merge( $page_ids, $device_params );
		$query_params = array_merge( $query_params, $spam_values );
		$query_params = array_merge( $query_params, $filter_params );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is built dynamically with proper placeholders
		$prepared_query = $wpdb->prepare( $query, $query_params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Already prepared above with proper placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$results = $wpdb->get_results( $prepared_query );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $results;
	}

	/**
	 * Canonical DB visitor-type session counts for the filter-options panel.
	 *
	 * The "(N)" suffixes on the Visitor Type select previously came from the
	 * single-type heatmap-file scan, so they disagreed with the header pill and
	 * device badges (canonical session_pages + recordings DB scope) whenever a
	 * session recorded without producing a file of the currently viewed type.
	 * This helper counts guest / logged-in sessions from the exact same scope
	 * builder the device counts use, honoring the current device / date /
	 * country / browser / spam filters but NOT the visitor-type filter itself
	 * (the select must show both options' counts).
	 *
	 * @param int    $page_id    Page ID.
	 * @param string $date_range Date range.
	 * @param array  $filters    Filters (page_ids, exclude_spam, country, browser, start/end date).
	 * @param string $device     Device scope ('', 'all', 'desktop', 'mobile', 'tablet').
	 * @return array|null array('guest' => int, 'logged_in' => int) or null when unavailable.
	 */
	private function get_canonical_visitor_type_counts( $page_id, $date_range = 'all', $filters = array(), $device = '' ) {
		global $wpdb;

		if ( ! empty( $filters['ab_test_id'] ) || ! empty( $filters['variant_id'] ) ) {
			return null;
		}

		$page_ids            = $this->get_filter_page_ids( $page_id, $filters );
		$table_session_pages = $wpdb->prefix . 'optibehavior_session_pages';
		$table_sessions      = $wpdb->prefix . 'optibehavior_sessions';
		$table_visitors      = $wpdb->prefix . 'optibehavior_visitors';

		if ( empty( $page_ids ) || ! $this->heatmap_table_exists( $table_session_pages ) ) {
			return null;
		}

		// CANONICAL UNIVERSE (master decision 2026-08-13, plan step 185): the
		// Visitor Type dropdown counts come from the SAME canonical human
		// pageview-session universe as the header pill / device chips (Free
		// repository, distinct optibehavior_pageviews.session_id, spam-excluded),
		// split guest vs logged-in. Guest + logged_in == pill total, so the
		// dropdown can no longer contradict the headline (was 92/11 vs pill 46).
		// Only the page's Period selector scopes it (country/browser/device do NOT
		// re-scope — same rule as the device chips, keeping the sum == pill). The
		// old session_pages + orphan-file union below is kept ONLY as a fallback
		// for installs whose Free build predates the bridge.
		$vt_period = in_array( $date_range, array( 'all', 'today', 'last7days', 'last30days', 'custom' ), true ) ? $date_range : 'all';
		$vt_start  = isset( $filters['start_date'] ) ? (string) $filters['start_date'] : '';
		$vt_end    = isset( $filters['end_date'] ) ? (string) $filters['end_date'] : '';
		$canonical = $this->get_canonical_visitor_type_counts_via_bridge( $page_ids, $vt_period, $vt_start, $vt_end );
		if ( is_array( $canonical ) ) {
			return array(
				'guest'     => isset( $canonical['guest'] ) ? absint( $canonical['guest'] ) : 0,
				'logged_in' => isset( $canonical['logged_in'] ) ? absint( $canonical['logged_in'] ) : 0,
			);
		}

		// The visitor-type select shows both options' counts, so the scope must
		// never be narrowed by the currently selected visitor type.
		unset( $filters['visitor_type'] );

		$scope       = $this->build_session_page_scope_sql( $page_ids, $date_range, $filters, $device );
		$visitor_sql = "CASE WHEN s.user_id IS NOT NULL AND s.user_id > 0 THEN 'logged_in' ELSE 'guest' END";
		$query       = "
			SELECT {$visitor_sql} AS visitor_type, COUNT(DISTINCT sp.session_id) AS sessions
			FROM {$table_session_pages} sp
			{$scope['recording_join']}
			LEFT JOIN {$table_sessions} s ON s.id = sp.session_id
			LEFT JOIN {$table_visitors} v ON v.id = s.visitor_id
			WHERE {$scope['where']}
			GROUP BY {$visitor_sql}
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fragments built from $wpdb->prefix + prepared placeholders; params supplied below.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $scope['params'] ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! is_array( $rows ) ) {
			return null;
		}

		$counts = array(
			'guest'     => 0,
			'logged_in' => 0,
		);

		foreach ( $rows as $row ) {
			$key = isset( $row['visitor_type'] ) && 'logged_in' === $row['visitor_type'] ? 'logged_in' : 'guest';
			$counts[ $key ] += isset( $row['sessions'] ) ? absint( $row['sessions'] ) : 0;
		}

		// Orphan-aware union (bugfix follow-up to spec §3.2/§3.3): sessions
		// whose JSON files carry real events but have NO trace at all in
		// wp_optibehavior_sessions are invisible to the DB query above, yet
		// they ARE part of the pill's/footer's file universe. Their filename
		// carries the G|L visitor marker, so bucket them the same way and add
		// them on top of the DB counts (never double-counted — orphans by
		// definition match no session_pages row).
		foreach ( $this->get_matching_admissible_file_sessions( $page_ids, $date_range, $filters, $device, 'visitor_type' ) as $meta ) {
			$key = ! empty( $meta['is_logged_in'] ) ? 'logged_in' : 'guest';
			++$counts[ $key ];
		}

		return $counts;
	}

	/**
	 * Canonical DB per-value session counts for one session_pages dimension.
	 *
	 * Backs the Countries / Browsers "(N)" option counts in the filter panel
	 * with the same session_pages + recordings scope as the header pill and
	 * device badges, replacing the single-type heatmap-file scan counts.
	 * Interdependent scoping is the caller's responsibility (pass the browser
	 * filter when counting countries and vice versa, never the dimension's own
	 * filter so the select keeps showing every option's count).
	 *
	 * @param int    $page_id    Page ID.
	 * @param string $date_range Date range.
	 * @param array  $filters    Filters (page_ids, exclude_spam, country, browser, visitor_type, start/end date).
	 * @param string $device     Device scope ('', 'all', 'desktop', 'mobile', 'tablet').
	 * @param string $dimension  'country', 'browser', 'os', 'utm_campaign', 'utm_source', or 'utm_medium'.
	 * @return array|null Map of value => session count (including an explicit
	 *                     'Unknown' bucket for sessions with no value for this
	 *                     dimension, so the sum always equals the pill's file
	 *                     universe — never a silent drop), or null when unavailable.
	 */
	private function get_canonical_session_dimension_counts( $page_id, $date_range, $filters, $device, $dimension ) {
		global $wpdb;

		if ( ! empty( $filters['ab_test_id'] ) || ! empty( $filters['variant_id'] ) ) {
			return null;
		}

		$page_ids            = $this->get_filter_page_ids( $page_id, $filters );
		$table_session_pages = $wpdb->prefix . 'optibehavior_session_pages';
		$table_sessions      = $wpdb->prefix . 'optibehavior_sessions';
		$table_visitors      = $wpdb->prefix . 'optibehavior_visitors';

		if ( empty( $page_ids ) || ! $this->heatmap_table_exists( $table_session_pages ) ) {
			return null;
		}

		// CANONICAL UNIVERSE (master decision 2026-08-13, plan step 198): Country /
		// Browser / OS option counts come from the SAME canonical human
		// pageview-session universe as the header pill / device chips / Visitor
		// Type dropdown (Free repository, distinct optibehavior_pageviews.session_id,
		// spam-excluded), so an option's "(N)" can no longer contradict the pill
		// (was Chrome 41 / France 41 / Windows 41 vs pill 46). Closed dimensions
		// get an explicit 'Unknown' bucket from the bridge so the sum == pill. Only
		// the page's Period selector scopes it (device / other-dimension filters do
		// NOT re-scope — same rule as the chips & Visitor Type). The legacy
		// session_pages + orphan-file union below stays ONLY as a fallback for
		// installs whose Free build predates the bridge. UTM dimensions have no
		// bridge yet, so they keep the legacy path.
		if ( in_array( $dimension, array( 'country', 'browser', 'os' ), true ) ) {
			$cd_period = in_array( $date_range, array( 'all', 'today', 'last7days', 'last30days', 'custom' ), true ) ? $date_range : 'all';
			$cd_start  = isset( $filters['start_date'] ) ? (string) $filters['start_date'] : '';
			$cd_end    = isset( $filters['end_date'] ) ? (string) $filters['end_date'] : '';
			$canonical = $this->get_canonical_dimension_counts_via_bridge( $page_ids, $dimension, $cd_period, $cd_start, $cd_end );
			if ( is_array( $canonical ) ) {
				return $canonical;
			}
		}

		// DB column per dimension. 'os' lives on the visitors table (already
		// LEFT JOINed below); UTM params live on the sessions table.
		$column_map = array(
			'country'      => 'UPPER(sp.country)',
			'browser'      => 'sp.browser',
			'os'           => 'v.os',
			'utm_campaign' => 's.utm_campaign',
			'utm_source'   => 's.utm_source',
			'utm_medium'   => 's.utm_medium',
		);
		$column = isset( $column_map[ $dimension ] ) ? $column_map[ $dimension ] : 'sp.browser';

		$scope = $this->build_session_page_scope_sql( $page_ids, $date_range, $filters, $device );
		$query = "
			SELECT {$column} AS dim_value, COUNT(DISTINCT sp.session_id) AS sessions
			FROM {$table_session_pages} sp
			{$scope['recording_join']}
			LEFT JOIN {$table_sessions} s ON s.id = sp.session_id
			LEFT JOIN {$table_visitors} v ON v.id = s.visitor_id
			WHERE {$scope['where']}
			GROUP BY {$column}
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fragments built from $wpdb->prefix + prepared placeholders; params supplied below.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $scope['params'] ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! is_array( $rows ) ) {
			return null;
		}

		// Merge case-insensitively so 'chrome'/'Chrome' rows collapse into one
		// display value (first non-empty spelling wins). Sessions with no
		// resolvable value for this dimension go in an explicit 'Unknown'
		// bucket instead of being silently dropped, so option counts always
		// sum to the same session universe as every other list and the pill.
		$counts  = array();
		$display = array();
		$unknown = 0;
		foreach ( $rows as $row ) {
			$value = isset( $row['dim_value'] ) ? trim( (string) $row['dim_value'] ) : '';
			$count = isset( $row['sessions'] ) ? absint( $row['sessions'] ) : 0;
			if ( '' === $value || 'unknown' === strtolower( $value ) || ( 'country' === $dimension && 'XX' === strtoupper( $value ) ) ) {
				$unknown += $count;
				continue;
			}
			$key = strtolower( $value );
			if ( ! isset( $display[ $key ] ) ) {
				$display[ $key ]            = $value;
				$counts[ $display[ $key ] ] = 0;
			}
			$counts[ $display[ $key ] ] += $count;
		}

		// Orphan-aware union (bugfix follow-up to spec §3.2/§3.3, and the
		// full-parity dropdown/badge follow-up): file sessions admitted into
		// the pill's file universe but structurally invisible to the DB scope
		// above (orphans + cascade-gap sessions) carry a raw dimension token
		// in their filename (country/browser/os/utm_*, extended-format files
		// only) — fold them into the same case-insensitive buckets so every
		// dropdown sums to exactly the pill's file_total_all, never less.
		$meta_field_map = array(
			'country'      => 'country',
			'browser'      => 'browser',
			'os'           => 'os',
			'utm_campaign' => 'utm_campaign',
			'utm_source'   => 'utm_source',
			'utm_medium'   => 'utm_medium',
		);
		$meta_field = isset( $meta_field_map[ $dimension ] ) ? $meta_field_map[ $dimension ] : $dimension;

		foreach ( $this->get_matching_admissible_file_sessions( $page_ids, $date_range, $filters, $device, $dimension ) as $meta ) {
			$raw_value = trim( (string) ( $meta[ $meta_field ] ?? '' ) );
			$value     = 'country' === $dimension ? strtoupper( $raw_value ) : $raw_value;
			if ( '' === $value || 'unknown' === strtolower( $value ) || 'na' === strtolower( $value )
				|| ( 'country' === $dimension && 'XX' === $value ) ) {
				++$unknown;
				continue;
			}
			$key = strtolower( $value );
			if ( ! isset( $display[ $key ] ) ) {
				$display[ $key ]            = $value;
				$counts[ $display[ $key ] ] = 0;
			}
			++$counts[ $display[ $key ] ];
		}

		if ( $unknown > 0 ) {
			$counts['Unknown'] = ( isset( $counts['Unknown'] ) ? $counts['Unknown'] : 0 ) + $unknown;
		}

		return $counts;
	}

	/**
	 * Return session-page backed device counts for the heatmap detail chrome.
	 *
	 * The frontend stats bar and edit-post metabox use session_pages/recordings
	 * as their page-level contract. Heatmap detail previously counted only
	 * optimized heatmap JSON files, so valid recorded sessions without a
	 * generated click/move/scroll file were missing from the header/device
	 * counters. This helper keeps the visible counters on the same session
	 * basis while the heatmap renderer can still use file storage for pixels.
	 *
	 * @param int    $page_id    Page ID.
	 * @param string $date_range Date range.
	 * @param array  $filters    Filters.
	 * @return array|null Counts or null when session_pages is unavailable.
	 */
	private function get_session_page_device_counts( $page_id, $date_range = 'all', $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters['ab_test_id'] ) || ! empty( $filters['variant_id'] ) ) {
			return null;
		}

		$page_ids            = $this->get_filter_page_ids( $page_id, $filters );
		$table_session_pages = $wpdb->prefix . 'optibehavior_session_pages';
		$table_sessions      = $wpdb->prefix . 'optibehavior_sessions';
		$table_visitors      = $wpdb->prefix . 'optibehavior_visitors';

		if ( empty( $page_ids ) || ! $this->heatmap_table_exists( $table_session_pages ) ) {
			return null;
		}

		$scope       = $this->build_session_page_scope_sql( $page_ids, $date_range, $filters, '' );
		$device_sql  = "COALESCE(NULLIF(LOWER(sp.device_type), ''), NULLIF(LOWER(v.device_type), ''), 'unknown')";
		$query       = "
			SELECT {$device_sql} AS device_type, COUNT(DISTINCT sp.session_id) AS sessions
			FROM {$table_session_pages} sp
			{$scope['recording_join']}
			LEFT JOIN {$table_sessions} s ON s.id = sp.session_id
			LEFT JOIN {$table_visitors} v ON v.id = s.visitor_id
			WHERE {$scope['where']}
			GROUP BY {$device_sql}
		";

		// If the recordings table exists, require the same recording-backed
		// session universe used by the frontend stats bar. If not, the scope
		// falls back to session_pages date filtering.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Table/column identifiers from $wpdb->prefix; values bound via $scope['params'] placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $scope['params'] ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! is_array( $rows ) ) {
			return null;
		}

		$counts = array(
			'desktop' => 0,
			'mobile'  => 0,
			'tablet'  => 0,
			'total'   => 0,
		);

		foreach ( $rows as $row ) {
			$device = isset( $row['device_type'] ) ? strtolower( $row['device_type'] ) : 'unknown';
			if ( 'phone' === $device ) {
				$device = 'mobile';
			}
			if ( ! in_array( $device, array( 'desktop', 'mobile', 'tablet' ), true ) ) {
				$device = 'desktop';
			}

			$count = isset( $row['sessions'] ) ? absint( $row['sessions'] ) : 0;
			$counts[ $device ] += $count;
			$counts['total']   += $count;
		}

		return $counts;
	}

	/**
	 * Batched canonical device counts for many pages in a single query.
	 *
	 * Mirrors {@see get_session_page_device_counts()} but groups by page_id so
	 * the Heatmaps list page can display counts identical to the detail page's
	 * default filtered view (recordings scope + visitor-type + reset exclusion)
	 * without running one query per row. Returns null when the session_pages
	 * table is unavailable (Free-only installs), letting the caller fall back to
	 * file-based counting.
	 *
	 * @param array  $page_ids   Page IDs to count.
	 * @param string $date_range Date range key ('all', 'custom', ...).
	 * @param array  $filters    Filters (visitor_type, exclude_spam, country, browser, start/end date).
	 * @return array|null Map of page_id => array('desktop','mobile','tablet','total'), or null.
	 */
	public function get_batch_session_page_device_counts( array $page_ids, $date_range = 'all', array $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters['ab_test_id'] ) || ! empty( $filters['variant_id'] ) ) {
			return null;
		}

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return null;
		}

		$table_session_pages = $wpdb->prefix . 'optibehavior_session_pages';
		$table_sessions      = $wpdb->prefix . 'optibehavior_sessions';
		$table_visitors      = $wpdb->prefix . 'optibehavior_visitors';

		if ( ! $this->heatmap_table_exists( $table_session_pages ) ) {
			return null;
		}

		// Do not let a page_ids filter narrow the scope inside the shared scope
		// builder; the batch already passes the full set explicitly.
		unset( $filters['page_ids'] );

		$scope      = $this->build_session_page_scope_sql( $page_ids, $date_range, $filters, '' );
		$device_sql = "COALESCE(NULLIF(LOWER(sp.device_type), ''), NULLIF(LOWER(v.device_type), ''), 'unknown')";
		$query      = "
			SELECT sp.page_id AS page_id, {$device_sql} AS device_type, COUNT(DISTINCT sp.session_id) AS sessions
			FROM {$table_session_pages} sp
			{$scope['recording_join']}
			LEFT JOIN {$table_sessions} s ON s.id = sp.session_id
			LEFT JOIN {$table_visitors} v ON v.id = s.visitor_id
			WHERE {$scope['where']}
			GROUP BY sp.page_id, {$device_sql}
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fragments built from $wpdb->prefix + prepared placeholders; params supplied below.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $scope['params'] ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! is_array( $rows ) ) {
			return null;
		}

		$map = array();
		foreach ( $page_ids as $pid ) {
			$map[ $pid ] = array(
				'desktop' => 0,
				'mobile'  => 0,
				'tablet'  => 0,
				'total'   => 0,
			);
		}

		foreach ( $rows as $row ) {
			$pid = isset( $row['page_id'] ) ? absint( $row['page_id'] ) : 0;
			if ( ! isset( $map[ $pid ] ) ) {
				continue;
			}

			$device = isset( $row['device_type'] ) ? strtolower( $row['device_type'] ) : 'unknown';
			if ( 'phone' === $device ) {
				$device = 'mobile';
			}
			if ( ! in_array( $device, array( 'desktop', 'mobile', 'tablet' ), true ) ) {
				$device = 'desktop';
			}

			$count = isset( $row['sessions'] ) ? absint( $row['sessions'] ) : 0;
			$map[ $pid ][ $device ] += $count;
			$map[ $pid ]['total']   += $count;
		}

		return $map;
	}

	/**
	 * Canonical + interaction-file session counts for one or many pages.
	 *
	 * This is the single source of truth both the Heatmaps list and the
	 * detail page header/device badges must read through, fixing the
	 * "13 (list) vs 0 (detail)" desync that comes from the two surfaces
	 * disagreeing on how to treat a DB total of 0.
	 *
	 * - DB total > 0 -> DB is authoritative for 'total'; 'desynced' is only
	 *   true if the cached file-derived count exceeds it.
	 * - DB total == 0 (or unavailable) -> the cached file-derived count
	 *   becomes 'total' when it is > 0 (mirrors the list's existing
	 *   file-fallback behavior), and 'desynced' is true in that case since
	 *   the DB should be >= files except during rare capture race windows.
	 *
	 * 'file_total' is read from the background reconciliation cache only
	 * (see spec §3b) — this method never scans the filesystem itself, so it
	 * stays safe to call on the count hot path. Until that cache exists (or
	 * has an entry for a given page), 'file_total' is null and 'desynced'
	 * is false, i.e. the DB number is shown as-is with no badge.
	 *
	 * @param array  $page_ids     Page IDs to resolve.
	 * @param string $date_range   Date range key ('all', 'custom', ...).
	 * @param array  $filters      Filters (visitor_type, exclude_spam, country, browser, ...).
	 * @param array  $known_db_map Optional pre-fetched db counts keyed by page_id
	 *                             (each a 'desktop'/'mobile'/'tablet'/'total' array, as
	 *                             returned by {@see get_session_page_device_counts()} or
	 *                             {@see get_batch_session_page_device_counts()}). Pass this
	 *                             when the caller already ran that query, to avoid a
	 *                             redundant second DB round-trip.
	 * @return array<int,array{db_total:int|null,file_total:int|null,total:int,desynced:bool}>
	 */
	public function get_session_counts_with_sync_status( array $page_ids, $date_range = 'all', array $filters = array(), ?array $known_db_map = null ) {
		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', $page_ids ) ) ) );

		$result = array();
		if ( empty( $page_ids ) ) {
			return $result;
		}

		if ( is_array( $known_db_map ) ) {
			$db_map = $known_db_map;
		} elseif ( 1 === count( $page_ids ) ) {
			$single = $this->get_session_page_device_counts( $page_ids[0], $date_range, $filters );
			$db_map = null === $single ? null : array( $page_ids[0] => $single );
		} else {
			$db_map = $this->get_batch_session_page_device_counts( $page_ids, $date_range, $filters );
		}

		// The reconciliation cache's file_total is computed for the DEFAULT
		// comparable view only (date_range 'all' + guest visitors + default spam
		// exclusion — see compute_heatmap_sync_status_for_pages() on the Free
		// side). When the caller's view is narrower (custom dates, country,
		// browser, "All Visitors", advanced session filters, ...), comparing a
		// filtered db_total against the unfiltered cached file_total produces a
		// bogus "N / M" pill and a spurious desync badge — so the file side is
		// withheld entirely and the DB number is shown as-is.
		$comparable = $this->is_sync_cache_comparable_view( $date_range, $filters );

		foreach ( $page_ids as $page_id ) {
			$db_total = null;
			if ( is_array( $db_map ) ) {
				$db_total = isset( $db_map[ $page_id ]['total'] ) ? (int) $db_map[ $page_id ]['total'] : 0;
			}

			if ( $comparable ) {
				$result[ $page_id ] = $this->build_heatmap_sync_status_entry( $page_id, $db_total );
			} else {
				$result[ $page_id ] = array(
					'db_total'   => $db_total,
					'file_total' => null,
					'total'      => (int) ( null !== $db_total ? $db_total : 0 ),
					'desynced'   => false,
				);
			}
		}

		return $result;
	}

	/**
	 * Build a single page's sync-status entry from an already-known DB total.
	 *
	 * Split out from {@see get_session_counts_with_sync_status()} so call
	 * sites that already have a DB total in hand (e.g. get_recordings_by_device())
	 * can reuse the exact same total/desynced decision without paying for a
	 * second query.
	 *
	 * @param int      $page_id  Page ID (used to look up the cached file total).
	 * @param int|null $db_total DB-derived session total for the page, or null when
	 *                           no canonical DB source is available (Free-only installs).
	 * @return array{db_total:int|null,file_total:int|null,total:int,desynced:bool}
	 */
	private function build_heatmap_sync_status_entry( $page_id, $db_total ) {
		$file_total = $this->get_cached_heatmap_file_total( $page_id );

		$desynced = false;
		if ( null !== $file_total ) {
			if ( null !== $db_total && $db_total > 0 ) {
				$desynced = $file_total > $db_total;
			} elseif ( ( null === $db_total || 0 === $db_total ) && $file_total > 0 ) {
				$desynced = true;
			}
		}

		if ( null !== $db_total && $db_total > 0 ) {
			$total = $db_total;
		} elseif ( null !== $file_total ) {
			$total = $file_total;
		} else {
			$total = null !== $db_total ? $db_total : 0;
		}

		return array(
			'db_total'   => $db_total,
			'file_total' => $file_total,
			'total'      => (int) $total,
			'desynced'   => $desynced,
		);
	}

	/**
	 * Decide whether a requested view is comparable to the reconciliation
	 * cache's default scope (date_range 'all', guest visitors, no per-session
	 * narrowing), i.e. whether the cached file_total may be shown next to /
	 * compared against the DB total for this request.
	 *
	 * Whitelist approach so future advanced filters fail safe (hidden pill)
	 * instead of producing a mixed-scope comparison:
	 * - date_range must be '' or 'all' (custom/relative ranges narrow the view).
	 * - 'visitor_type' must be absent or 'guest' (the cache counts guest
	 *   sessions only; explicit '' means "All Visitors" and is NOT comparable).
	 * - 'exclude_spam' and 'page_ids' are pass-through: both surfaces send them
	 *   on every request with their default-view values.
	 * - Any other filter key with a non-empty / non-zero value narrows the view.
	 *
	 * @param string $date_range Date range key.
	 * @param array  $filters    Request filters.
	 * @return bool
	 */
	private function is_sync_cache_comparable_view( $date_range, array $filters ) {
		if ( ! in_array( (string) $date_range, array( '', 'all' ), true ) ) {
			return false;
		}

		foreach ( $filters as $key => $value ) {
			if ( 'visitor_type' === $key ) {
				if ( 'guest' !== $value ) {
					return false;
				}
				continue;
			}

			if ( 'exclude_spam' === $key || 'page_ids' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				if ( ! empty( $value ) ) {
					return false;
				}
				continue;
			}

			$string_value = (string) $value;
			if ( '' !== $string_value && '0' !== $string_value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read a page's file-derived session count from the background
	 * reconciliation cache (spec §3b). Returns null when the cache hasn't
	 * been populated yet for this page — callers must treat that as "unknown",
	 * not "zero".
	 *
	 * @param int $page_id Page ID.
	 * @return int|null
	 */
	private function get_cached_heatmap_file_total( $page_id ) {
		$sync_status = get_transient( 'opti_behavior_heatmap_sync_status' );
		if ( ! is_array( $sync_status ) || ! isset( $sync_status[ $page_id ]['file_total'] ) ) {
			return null;
		}

		return (int) $sync_status[ $page_id ]['file_total'];
	}

	/**
	 * Return quick stats from the same session_pages scope as the frontend bar.
	 *
	 * @param int    $page_id    Page ID.
	 * @param string $device     Device.
	 * @param string $date_range Date range.
	 * @param array  $filters    Filters.
	 * @return array|null Stats or null when unavailable.
	 */
	private function get_session_page_quick_stats( $page_id, $device, $date_range, $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters['ab_test_id'] ) || ! empty( $filters['variant_id'] ) ) {
			return null;
		}

		// Advanced filters (session allow-list from Pages & Traffic, OS / UTM,
		// duration bounds) are only honored by the file scanners. Bail to the
		// file-based stats so Views / Avg Time reflect the active filters too.
		$advanced_keys = array( 'session_ids', 'os', 'utm_campaign', 'utm_source', 'utm_medium' );
		foreach ( $advanced_keys as $advanced_key ) {
			if ( ! empty( $filters[ $advanced_key ] ) ) {
				return null;
			}
		}
		if ( ( isset( $filters['duration_min'] ) && '' !== $filters['duration_min'] )
			|| ( isset( $filters['duration_max'] ) && '' !== $filters['duration_max'] ) ) {
			return null;
		}

		$page_ids            = $this->get_filter_page_ids( $page_id, $filters );
		$table_session_pages = $wpdb->prefix . 'optibehavior_session_pages';
		$table_sessions      = $wpdb->prefix . 'optibehavior_sessions';
		$table_visitors      = $wpdb->prefix . 'optibehavior_visitors';

		if ( empty( $page_ids ) || ! $this->heatmap_table_exists( $table_session_pages ) ) {
			return null;
		}

		$scope = $this->build_session_page_scope_sql( $page_ids, $date_range, $filters, $device );
		$duration_sql = "CASE
			WHEN sp.entry_time IS NOT NULL AND sp.exit_time IS NOT NULL AND sp.exit_time > sp.entry_time
				THEN LEAST(TIMESTAMPDIFF(SECOND, sp.entry_time, sp.exit_time), 1800)
			WHEN sp.duration IS NOT NULL AND sp.duration > 0
				THEN LEAST(sp.duration, 1800)
			ELSE NULL
		END";

		// The per-page beacon counter (session_pages.clicks_count) reads 0 for a
		// heatmap-only session whose clicks were recorded solely through the heatmap
		// tracker, while the real click events (16/17) prove the interaction. Mirror the
		// shared spam filter's GREATEST(counter, real event count) contract so the
		// "Clicks" KPI reflects the true click volume. The event counts are pulled from a
		// subquery pre-aggregated by (session_id, page_id) and LEFT JOINed on both keys,
		// so it attaches at most one row per session_pages row: no fan-out, total_events /
		// avg_time / total_views are byte-identical. GREATEST is monotonic upward, so
		// pages whose beacon counter already meets or exceeds the event count are
		// unchanged. This is a read-side derivation only; no counters are mutated.
		$table_events         = $wpdb->prefix . 'optibehavior_events';
		$click_events_join    = '';
		$real_click_sum_sql   = '0';
		if ( $this->heatmap_table_exists( $table_events ) ) {
			$event_page_ids     = implode( ',', array_map( 'absint', $page_ids ) );
			$click_events_join  = "LEFT JOIN (
				SELECT session_id, page_id, COUNT(*) AS click_count
				FROM {$table_events}
				WHERE event IN (16,17) AND page_id IN ({$event_page_ids})
				GROUP BY session_id, page_id
			) hm_click_events ON hm_click_events.session_id = sp.session_id AND hm_click_events.page_id = sp.page_id";
			$real_click_sum_sql = 'COALESCE(SUM(COALESCE(hm_click_events.click_count, 0)), 0)';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table/column identifiers from $wpdb->prefix; values bound via $scope['params'] placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(DISTINCT sp.session_id) AS total_views,
					GREATEST(
						COALESCE(SUM(COALESCE(sp.clicks_count, 0)), 0),
						{$real_click_sum_sql}
					) AS total_clicks,
					COALESCE(SUM(COALESCE(sp.events_count, 0)), 0) AS total_events,
					COUNT(DISTINCT CASE WHEN COALESCE(sp.scroll_depth, 0) > 0 THEN sp.session_id END) AS total_scrolls,
					COALESCE(AVG(NULLIF({$duration_sql}, 0)), 0) AS avg_time,
					COALESCE(AVG(NULLIF(sp.scroll_depth, 0)), 0) AS avg_scroll
				FROM {$table_session_pages} sp
				{$scope['recording_join']}
				{$click_events_join}
				LEFT JOIN {$table_sessions} s ON s.id = sp.session_id
				LEFT JOIN {$table_visitors} v ON v.id = s.visitor_id
				WHERE {$scope['where']}",
				$scope['params']
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( ! is_array( $row ) ) {
			return null;
		}

		$total_views  = isset( $row['total_views'] ) ? absint( $row['total_views'] ) : 0;
		$total_clicks = isset( $row['total_clicks'] ) ? absint( $row['total_clicks'] ) : 0;
		$total_events = isset( $row['total_events'] ) ? absint( $row['total_events'] ) : 0;

		return array(
			'total_views'     => $total_views,
			'total_clicks'    => $total_clicks,
			'total_moves'     => $total_events,
			'total_scrolls'   => isset( $row['total_scrolls'] ) ? absint( $row['total_scrolls'] ) : 0,
			'avg_time'        => isset( $row['avg_time'] ) ? absint( round( (float) $row['avg_time'] ) ) : 0,
			'engagement_rate' => $total_views > 0 ? min( 100, round( ( $total_clicks / $total_views ) * 100, 1 ) ) : 0,
			'avg_scroll'      => isset( $row['avg_scroll'] ) ? round( (float) $row['avg_scroll'], 1 ) : 0,
		);
	}

	/**
	 * Build the session_pages scope shared by heatmap detail counters.
	 *
	 * @param array  $page_ids   Page IDs.
	 * @param string $date_range Date range.
	 * @param array  $filters    Filters.
	 * @param string $device     Optional device filter.
	 * @return array SQL fragments and prepared params.
	 */
	private function build_session_page_scope_sql( array $page_ids, $date_range, $filters, $device = '' ) {
		global $wpdb;

		$table_recordings = $wpdb->prefix . 'optibehavior_recordings';
		$table_sessions   = $wpdb->prefix . 'optibehavior_sessions';
		$page_clause      = $this->build_page_ids_clause( 'sp.page_id', $page_ids );
		$where            = $page_clause;
		$params           = $page_ids;
		$recording_join   = '';

		if ( $this->heatmap_table_exists( $table_recordings ) ) {
			$spam_filter   = ! empty( $filters['exclude_spam'] ) ? $this->build_recordings_spam_filter_sql( 'r', 's_filter', 'hm_detail_scope_spam_scrolls' ) : null;
			$spam_join     = $spam_filter ? $spam_filter['join'] : '';
			$spam_where    = $spam_filter ? $spam_filter['where'] : '';
			$spam_values   = $spam_filter ? $spam_filter['values'] : array();
			$recording_date = $this->get_date_where_clause( $date_range, $filters['start_date'] ?? '', $filters['end_date'] ?? '' );

			$recording_join = "INNER JOIN (
				SELECT DISTINCT r.session_id
				FROM {$table_recordings} r
				LEFT JOIN {$table_sessions} s_filter ON s_filter.id = r.session_id
				{$spam_join}
				WHERE 1=1 {$recording_date} {$spam_where}
			) recording_scope ON recording_scope.session_id = sp.session_id";
			$params = array_merge( $spam_values, $params );
		} else {
			$where .= $this->get_date_where_clause_for_column( $date_range, 'sp.entry_time', $filters['start_date'] ?? '', $filters['end_date'] ?? '' );
		}

		$country_filters = $this->normalize_heatmap_multi_select_filter( $filters['country'] ?? '', 'upper' );
		$browser_filters = $this->normalize_heatmap_multi_select_filter( $filters['browser'] ?? '', 'lower' );
		$this->append_heatmap_multi_select_sql_filter( $where, $params, 'UPPER(sp.country)', $country_filters );
		$this->append_heatmap_multi_select_sql_filter( $where, $params, 'LOWER(sp.browser)', $browser_filters );

		if ( ! empty( $filters['visitor_type'] ) ) {
			if ( 'logged_in' === $filters['visitor_type'] ) {
				$where .= ' AND s.user_id IS NOT NULL AND s.user_id > 0';
			} elseif ( 'guest' === $filters['visitor_type'] ) {
				$where .= ' AND (s.user_id IS NULL OR s.user_id = 0)';
			}
		}

		if ( ! $this->is_all_device_scope( $device ) ) {
			$device_sql = "COALESCE(NULLIF(LOWER(sp.device_type), ''), NULLIF(LOWER(v.device_type), ''), 'unknown')";
			$where     .= " AND (CASE WHEN {$device_sql} = 'phone' THEN 'mobile' WHEN {$device_sql} IN ('desktop', 'mobile', 'tablet') THEN {$device_sql} ELSE 'desktop' END) = LOWER(%s)";
			$params[] = $device;
		}

		// Honor "Delete Heatmap Data": exclude sessions captured before a page's
		// reset timestamp so the heatmap stats bar starts fresh after a reset,
		// while the underlying analytics rows are preserved.
		$where .= $this->build_session_page_reset_clause( $page_ids, $params );

		return array(
			'recording_join' => $recording_join,
			'where'          => $where,
			'params'         => $params,
		);
	}

	/**
	 * Build a WHERE fragment that drops session_pages rows captured before a
	 * page's heatmap reset timestamp. Pages without a reset are unaffected.
	 *
	 * @param array $page_ids Page IDs in scope.
	 * @param array $params   Prepared params (appended by reference, in placeholder order).
	 * @return string SQL fragment (leading AND) or empty string.
	 */
	private function build_session_page_reset_clause( array $page_ids, array &$params ) {
		$resets = get_option( 'opti_behavior_heatmap_page_resets', array() );
		if ( ! is_array( $resets ) || empty( $resets ) ) {
			return '';
		}

		$conds       = array();
		$reset_added = false;

		foreach ( $page_ids as $pid ) {
			$pid = absint( $pid );
			if ( ! empty( $resets[ $pid ] ) ) {
				$conds[]     = '(sp.page_id = %d AND sp.entry_time >= %s)';
				$params[]    = $pid;
				$params[]    = $resets[ $pid ];
				$reset_added = true;
			}
		}

		if ( ! $reset_added ) {
			return '';
		}

		// Pages that have no reset timestamp must remain fully visible.
		$reset_pids   = array_map( 'absint', array_keys( $resets ) );
		$placeholders = implode( ',', array_fill( 0, count( $reset_pids ), '%d' ) );
		$conds[]      = "sp.page_id NOT IN ({$placeholders})";
		$params       = array_merge( $params, $reset_pids );

		return ' AND ( ' . implode( ' OR ', $conds ) . ' )';
	}

	/**
	 * Build a date-range WHERE clause for a generic datetime column.
	 *
	 * @param string $date_range Date range.
	 * @param string $column     Safe SQL column expression.
	 * @return string SQL fragment.
	 */
	private function get_date_where_clause_for_column( $date_range, $column, $start_date = '', $end_date = '' ) {
		$column = preg_replace( '/[^A-Za-z0-9_\.]/', '', (string) $column );

		if ( 'custom' === $date_range && $start_date && $end_date ) {
			$start = gmdate( 'Y-m-d 00:00:00', strtotime( $start_date ) );
			$end   = gmdate( 'Y-m-d 23:59:59', strtotime( $end_date ) );
			return " AND {$column} BETWEEN '" . esc_sql( $start ) . "' AND '" . esc_sql( $end ) . "'";
		}

		switch ( $date_range ) {
			case 'today':
				return " AND DATE({$column}) = CURDATE()";
			case 'yesterday':
				return " AND DATE({$column}) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
			case 'last7days':
			case 'last_7_days':
				return " AND {$column} >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
			case 'last30days':
			case 'last_30_days':
				return " AND {$column} >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
			case 'all':
			default:
				return '';
		}
	}

	/**
	 * Check whether an analytics table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function heatmap_table_exists( $table ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get device counts for page from FILE STORAGE (single source of truth).
	 * Scans heatmap data files and extracts metadata from filenames.
	 * Format: timestamp_country_browser_count_device_visitortype_session.json
	 *
	 * IMPORTANT: This function now accepts filters to ensure device counts
	 * match the Views statistic exactly (unified counting from file storage).
	 *
	 * @param int    $page_id Page ID.
	 * @param string $date_range Date range filter (optional).
	 * @param array  $filters Optional filters (country, browser, visitor_type).
	 * @return array Device counts including total.
	 */
	private function get_recordings_by_device( $page_id, $date_range = 'all', $filters = array() ) {
		global $wpdb;

		// DEBUG: Log input parameters
		$this->debug_log( "[DEBUG get_recordings_by_device] START - page_id: $page_id, date_range: $date_range", 'info' );
		$this->debug_log( "[DEBUG get_recordings_by_device] Filters: " . wp_json_encode( $filters ), 'info' );

		// File-universe requests (storage_type set) must not use the DB
		// session_pages fast path: the heatmap view, the filter option counts
		// and the hero total all count sessions with actual heatmap files of
		// the current type, so the DB universe (which includes sessions that
		// never produced a file) would disagree with every other number shown.
		$storage_type_scope = isset( $filters['storage_type'] ) && in_array( $filters['storage_type'], array( 'clicks', 'moves', 'scrolls' ), true )
			? $filters['storage_type']
			: '';

		if ( '' === $storage_type_scope ) {
			// Canonical DB path (session_pages + recordings indexed join).
			$session_page_counts = $this->get_session_page_device_counts( $page_id, $date_range, $filters );
			if ( null !== $session_page_counts ) {
				// Route through the shared resolver (spec §1) instead of trusting
				// a non-null DB result outright: a DB total of 0 used to return
				// immediately here even when interaction files exist for the
				// page, silently showing "0 sessions" while the Heatmaps list
				// (which already falls back to its file-derived count in that
				// case) showed the real number. Reusing $session_page_counts as
				// the known DB map avoids a second query for the common case.
				$sync_status = $this->get_session_counts_with_sync_status(
					array( $page_id ),
					$date_range,
					$filters,
					array( $page_id => $session_page_counts )
				);
				$sync_entry = isset( $sync_status[ $page_id ] ) ? $sync_status[ $page_id ] : null;

				$needs_file_scan_fallback = $sync_entry
					&& 0 === (int) $session_page_counts['total']
					&& null !== $sync_entry['file_total']
					&& $sync_entry['file_total'] > 0;

				if ( ! $needs_file_scan_fallback ) {
					// DB result stays authoritative (the common case, and the
					// only case until the background reconciliation cache in
					// spec §3b exists): return it immediately as before, just
					// carrying the extra dual-metric fields when available.
					$session_page_counts['sessions_with_interactions'] = $sync_entry ? $sync_entry['file_total'] : null;
					$session_page_counts['desynced']                   = $sync_entry ? $sync_entry['desynced'] : false;

					// Orphan-aware union (bugfix follow-up to spec §3.2/§3.3):
					// device badges must share the pill's/footer's file
					// universe, so file sessions with NO DB trace at all
					// (post-cleanup desync) are added on top of the DB device
					// split instead of staying invisible. Never subtracts from
					// the DB precision — only unions in sessions the DB query
					// structurally cannot see. skip_dim '' keeps every other
					// active filter (country/browser/visitor_type/A-B) applied,
					// device '' so each orphan is bucketed by its own filename
					// device token instead of being pre-filtered to one bucket.
					$orphan_page_ids = $this->get_filter_page_ids( $page_id, $filters );
					$orphan_sessions = $this->get_matching_admissible_file_sessions( $orphan_page_ids, $date_range, $filters, '', '' );
					if ( ! empty( $orphan_sessions ) ) {
						foreach ( $orphan_sessions as $meta ) {
							$bucket = $this->normalize_heatmap_device_bucket( $meta['device'] ?? '' );
							$session_page_counts[ $bucket ] = isset( $session_page_counts[ $bucket ] ) ? $session_page_counts[ $bucket ] + 1 : 1;
						}
						$session_page_counts['total'] = (int) $session_page_counts['desktop'] + (int) $session_page_counts['mobile'] + (int) $session_page_counts['tablet'];
					}

					$this->debug_log( "[DEBUG get_recordings_by_device] RESULT (session_pages): " . wp_json_encode( $session_page_counts ), 'info' );
					return $session_page_counts;
				}

				// Desynced 0-DB / cached-files case: fall through to the
				// file-scan below (already reachable today when the DB path is
				// unavailable) so the header/device breakdown reflects real
				// interaction data instead of "0 sessions". This branch is a
				// no-op today because the reconciliation cache does not exist
				// yet, so $sync_entry['file_total'] is always null until it does.
				$this->debug_log( "[DEBUG get_recordings_by_device] session_pages total=0 but cached file_total>0, falling back to file scan for page_id: $page_id", 'info' );
			}
		}

		// Get URL hash for this page using heatmap_pages table first (same as generate_statistics)
		$heatmap_storage = Opti_Behavior_Heatmap_Storage::get_instance();
		$page_ids = $this->get_filter_page_ids( $page_id, $filters );
		$url_hashes = array();
		if ( $heatmap_storage ) {
			foreach ( $page_ids as $canonical_page_id ) {
				foreach ( $heatmap_storage->get_url_hashes_for_page( $canonical_page_id ) as $url_hash ) {
					if ( $url_hash ) {
						$url_hashes[] = $url_hash;
					}
				}
			}
		}
		$url_hashes = array_values( array_unique( $url_hashes ) );

		if ( empty( $url_hashes ) ) {
			$this->debug_log( "[DEBUG get_recordings_by_device] No URL hash found for page_ids: " . implode( ',', $page_ids ), 'info' );
			return array(
				'desktop' => 0,
				'mobile'  => 0,
				'tablet'  => 0,
				'total'   => 0,
			);
		}

		$upload_dir = wp_upload_dir();
		$base_dirs  = array();
		foreach ( $url_hashes as $url_hash ) {
			$base_dir = $upload_dir['basedir'] . '/opti-behavior-data/' . $url_hash;
			if ( is_dir( $base_dir ) ) {
				$base_dirs[] = $base_dir;
			}
		}

		if ( empty( $base_dirs ) ) {
			$this->debug_log( "[DEBUG get_recordings_by_device] No base_dir found for url_hashes: " . implode( ',', $url_hashes ), 'info' );
			return array(
				'desktop' => 0,
				'mobile'  => 0,
				'tablet'  => 0,
				'total'   => 0,
			);
		}

		$this->debug_log( "[DEBUG get_recordings_by_device] base_dirs: " . implode( ',', $base_dirs ), 'info' );

		// Calculate date range boundaries
		$date_boundaries = $this->get_date_boundaries( $date_range, $filters );
		$start_timestamp = $date_boundaries['start'];
		$end_timestamp   = $date_boundaries['end'];

		// Extract filter values. Country and browser are multi-select lists.
		$filter_countries    = $this->normalize_heatmap_multi_select_filter( $filters['country'] ?? '', 'upper' );
		$filter_browsers     = $this->normalize_heatmap_multi_select_filter( $filters['browser'] ?? '', 'lower' );
		$filter_visitor_type = ! empty( $filters['visitor_type'] ) ? strtolower( $filters['visitor_type'] ) : '';

		$filter_ab_test_id   = ! empty( $filters['ab_test_id'] ) ? (int) $filters['ab_test_id'] : 0;
		$filter_variant_id   = ! empty( $filters['variant_id'] ) ? (int) $filters['variant_id'] : 0;
		$spam_lookup         = array();
		$known_lookup        = null; // null = orphan gate not applicable (spam filtering off, or bridge unavailable).

		if ( ! empty( $filters['exclude_spam'] ) ) {
			$spam_session_ids = $this->get_spam_allowed_session_ids( $page_ids );

			// Bridge (Pro -> Free, spec §6 follow-up): the Heatmaps list resolves
			// its allow-list with two extra evidence branches this query lacks —
			// a Free-only pageviews fallback and a base-URL complement match for
			// duplicate page_id rows sharing the same normalized URL (see
			// Opti_Behavior_Heatmap_Dashboard::get_allowed_heatmap_session_lookup_for_pages()).
			// Without it, a page whose session_pages evidence was cascade-deleted
			// (the orphaned-file desync scenario) shows "0 sessions" here while
			// the list still shows the real file-derived count. Union only —
			// never narrows this query's own result. No fatal when Free's
			// dashboard bridge is unavailable (defensive; Free is always active
			// alongside Pro in practice).
			if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
				$free_core = Opti_Behavior_Heatmap_Core::get_instance();
				$free_dashboard = ( $free_core && method_exists( $free_core, 'get_dashboard' ) ) ? $free_core->get_dashboard() : null;
				if ( $free_dashboard && method_exists( $free_dashboard, 'get_heatmap_session_allowlist_for_page_bridge' ) ) {
					foreach ( $page_ids as $bridge_page_id ) {
						$bridge_lookup = $free_dashboard->get_heatmap_session_allowlist_for_page_bridge( $bridge_page_id );
						if ( ! empty( $bridge_lookup ) && is_array( $bridge_lookup ) ) {
							$spam_session_ids = array_merge( $spam_session_ids, array_keys( $bridge_lookup ) );
						}
					}
				}
			}

			$spam_lookup  = $this->build_session_lookup_map( array_values( array_unique( $spam_session_ids ) ) );
			$known_lookup = $this->get_known_db_session_lookup_via_bridge( $page_ids );
			// Orphan-aware gate (RC-C, spec §3.2): only fall back to the old
			// "empty allow-list = zero sessions" behavior when the Free bridge
			// itself is unavailable; otherwise orphan file sessions may still
			// exist and must be scanned/counted.
			if ( null === $known_lookup && empty( $spam_lookup ) ) {
				return array(
					'desktop' => 0,
					'mobile'  => 0,
					'tablet'  => 0,
					'total'   => 0,
				);
			}
		}

		// Pre-compute short session ID suffixes for the session allow-list.
		$short_session_ids = null;
		if ( ! empty( $filters['session_ids'] ) && is_array( $filters['session_ids'] ) ) {
			$short_session_ids = array();
			foreach ( $filters['session_ids'] as $opti_behavior_sid ) {
				$opti_behavior_sid_clean = preg_replace( '/[^a-zA-Z0-9]/', '', $opti_behavior_sid );
				$short_session_ids[]     = substr( $opti_behavior_sid_clean, -8 );
			}
		}

		$this->debug_log( "[DEBUG get_recordings_by_device] filter_visitor_type: $filter_visitor_type, ab_test_id: $filter_ab_test_id, variant_id: $filter_variant_id", 'info' );

		$counts = array(
			'desktop' => array(),
			'mobile'  => array(),
			'tablet'  => array(),
		);

		// DEBUG: Track filtering statistics
		$debug_stats = array(
			'total_files' => 0,
			'skipped_parts_count' => 0,
			'skipped_no_session' => 0,
			'skipped_date' => 0,
			'skipped_country' => 0,
			'skipped_browser' => 0,
			'skipped_visitor_type' => 0,
			'matched' => 0,
			'device_values_found' => array(),
			'visitor_type_values_found' => array(),
			'unknown_devices_to_desktop' => 0,
		);

		// Check event type folders — restricted to the current heatmap type
		// when the caller asked for the file-universe scope.
		$type_folders = ( '' !== $storage_type_scope ) ? array( $storage_type_scope ) : array( 'clicks', 'moves', 'scrolls' );
		foreach ( $base_dirs as $base_dir ) {
			foreach ( $type_folders as $folder_name ) {
				$dir = $base_dir . '/' . $folder_name;
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$files = glob( $dir . '/*.json' );
			if ( empty( $files ) ) {
				continue;
			}

			$this->debug_log( "[DEBUG get_recordings_by_device] Folder: $folder_name, files count: " . count( $files ), 'info' );

			foreach ( $files as $file ) {
				$debug_stats['total_files']++;
				$filename = basename( $file );
				// Parse filename format: timestamp_country_browser_count_device_L/G_duration_session.json
				// Or older format: timestamp_country_browser_count_device_L/G_session.json
				// Or oldest format: timestamp_country_browser_count_device_session.json
				$parts = explode( '_', str_replace( '.json', '', $filename ) );
				if ( count( $parts ) < 6 ) {
					$debug_stats['skipped_parts_count']++;
					continue;
				}

				$timestamp = $parts[0];
				$country = $parts[1];
				$browser = $parts[2];
				// $count = $parts[3];
				$file_device = $parts[4];
				$session = null;
				$logged_in_marker = null;

				$file_ab_test_id    = 0;
				$file_ab_variant_id = 0;
				$duration           = 0;

				// Session ID and visitor-type marker parsing is shared across all
				// historical filename formats via Opti_Behavior_Heatmap_Storage
				// (moved here from near-duplicate dashboard/inline copies).
				$session          = Opti_Behavior_Heatmap_Storage::parse_session_token( $parts );
				$logged_in_marker = Opti_Behavior_Heatmap_Storage::parse_visitor_marker( $parts );

				// Duration / A-B test metadata still needs the same per-format
				// detection (fields not covered by the shared token parser).
				// Extended format (14 parts): ..._L/G_duration_abtestid_variantid_os_utmC_utmS_utmM_session
				if ( count( $parts ) >= 14
					&& in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true )
					&& is_numeric( $parts[7] ) && is_numeric( $parts[8] ) ) {
					$duration           = intval( $parts[6] );
					$file_ab_test_id    = intval( $parts[7] );
					$file_ab_variant_id = intval( $parts[8] );
				}
				// AB format (10 parts): timestamp_country_browser_count_device_L/G_duration_abtestid_variantid_session
				elseif ( count( $parts ) >= 10
					&& in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true )
					&& is_numeric( $parts[7] ) && is_numeric( $parts[8] ) ) {
					$duration           = intval( $parts[6] );
					$file_ab_test_id    = intval( $parts[7] );
					$file_ab_variant_id = intval( $parts[8] );
				}
				// 8-part format: timestamp_country_browser_count_device_L/G_duration_session
				elseif ( count( $parts ) >= 8 && in_array( strtoupper( $parts[5] ), array( 'L', 'G' ), true ) ) {
					$duration = intval( $parts[6] );
				}
				// 7-part and old 6-part formats carry no duration/A-B fields;
				// $duration/$file_ab_test_id/$file_ab_variant_id keep their
				// initialized defaults (0).

				// DEBUG: Track device and visitor type values found
				$debug_stats['device_values_found'][ $file_device ] = ( $debug_stats['device_values_found'][ $file_device ] ?? 0 ) + 1;
				$debug_stats['visitor_type_values_found'][ $logged_in_marker ] = ( $debug_stats['visitor_type_values_found'][ $logged_in_marker ] ?? 0 ) + 1;

				if ( ! $session ) {
					$debug_stats['skipped_no_session']++;
					continue;
				}

				if ( null !== $known_lookup ) {
					if ( ! $this->is_session_allowed_or_orphan_by_lookup( $session, $spam_lookup, $known_lookup ) ) {
						continue;
					}
				} elseif ( ! $this->is_session_allowed_by_lookup( $session, $spam_lookup ) ) {
					continue;
				}

				// Apply date range filter
				$file_timestamp = intval( $timestamp );
				if ( $start_timestamp && $file_timestamp < $start_timestamp ) {
					$debug_stats['skipped_date']++;
					continue;
				}
				if ( $end_timestamp && $file_timestamp > $end_timestamp ) {
					$debug_stats['skipped_date']++;
					continue;
				}

				// Apply country filter (OR within the selected country list).
				if ( ! empty( $filter_countries ) && ! in_array( strtoupper( $country ), $filter_countries, true ) ) {
					$debug_stats['skipped_country']++;
					continue;
				}

				// Apply browser filter (OR within the selected browser list).
				if ( ! empty( $filter_browsers ) && ! in_array( strtolower( $browser ), $filter_browsers, true ) ) {
					$debug_stats['skipped_browser']++;
					continue;
				}

				// Apply visitor type filter (L=Logged in, G=Guest)
				if ( $filter_visitor_type ) {
					if ( $filter_visitor_type === 'logged_in' && $logged_in_marker !== 'L' ) {
						$debug_stats['skipped_visitor_type']++;
						continue;
					}
					if ( $filter_visitor_type === 'guest' && $logged_in_marker !== 'G' ) {
						$debug_stats['skipped_visitor_type']++;
						continue;
					}
				}

				// Apply A/B variant filter
				if ( $filter_ab_test_id > 0 && $filter_variant_id > 0 ) {
					if ( $file_ab_test_id !== $filter_ab_test_id || $file_ab_variant_id !== $filter_variant_id ) {
						continue;
					}
				}

				// Session allow-list (Pages & Traffic bridge / legacy A/B fallback).
				if ( null !== $short_session_ids && ! in_array( $session, $short_session_ids, true ) ) {
					continue;
				}

				// Advanced filename-scan filters (OS / UTM / duration bounds).
				if ( ! $this->passes_advanced_filename_filters( $filters, $parts, $duration ) ) {
					continue;
				}

				$debug_stats['matched']++;

				// Track unique sessions per device
				$device_lower = strtolower( $file_device );
				if ( isset( $counts[ $device_lower ] ) ) {
					$counts[ $device_lower ][ $session ] = true;
				} elseif ( $device_lower === 'phone' ) {
					$counts['mobile'][ $session ] = true;
				} else {
					// DEBUG: Track unknown devices being mapped to desktop
					$debug_stats['unknown_devices_to_desktop']++;
					$counts['desktop'][ $session ] = true;
				}
			}
		}
		}

		// DEBUG: Log final statistics
		$this->debug_log( "[DEBUG get_recordings_by_device] STATS: " . wp_json_encode( $debug_stats ), 'info' );

		// Calculate totals
		$desktop_count = count( $counts['desktop'] );
		$mobile_count  = count( $counts['mobile'] );
		$tablet_count  = count( $counts['tablet'] );

		$this->debug_log( "[DEBUG get_recordings_by_device] RESULT: desktop=$desktop_count, mobile=$mobile_count, tablet=$tablet_count", 'info' );

		$file_scan_result = array(
			'desktop' => $desktop_count,
			'mobile'  => $mobile_count,
			'tablet'  => $tablet_count,
			'total'   => $desktop_count + $mobile_count + $tablet_count,
		);

		// Dual-metric fields (spec §1/§2/§6 follow-up): this file-scan path is
		// only reached for the desynced 0-DB / cached-files fallback above (or
		// when a storage_type scope skips the DB path entirely), so $sync_entry
		// carries the already-computed sync-status entry for the common case —
		// isset-guarded since the storage_type-scope branch never sets it.
		// Additive: absent on Free-only installs or pages with no cached
		// sync-status entry, matching the DB-path return above.
		if ( isset( $sync_entry ) && $sync_entry ) {
			$file_scan_result['sessions_with_interactions'] = $sync_entry['file_total'];
			$file_scan_result['desynced']                   = (bool) $sync_entry['desynced'];
		}

		return $file_scan_result;
	}

	/**
	 * Get recording events (decrypted).
	 *
	 * @param object $recording Recording object.
	 * @return array Events array.
	 */
	private function get_recording_events( $recording ) {
		// Check if recording is stored in file.
		if ( ! empty( $recording->file_path ) && $recording->storage_type === 'file' ) {
			// Load from file storage.
			$upload_dir = wp_upload_dir();
			$full_file_path = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/' . $recording->file_path;

			if ( ! file_exists( $full_file_path ) ) {
				$this->debug_log( '[Opti-Behavior-Heatmap] File not found: ' . $full_file_path );
				return array();
			}

			// Read and decompress the file.
			$compressed_data = file_get_contents( $full_file_path );
			if ( $compressed_data === false ) {
				$this->debug_log( '[Opti-Behavior-Heatmap] Failed to read file: ' . $full_file_path );
				return array();
			}

			// Decompress if file is compressed (has .gz extension).
			$json_data = $compressed_data;
			if ( substr( $recording->file_path, -3 ) === '.gz' ) {
				$json_data = gzuncompress( $compressed_data );
				if ( $json_data === false ) {
					$this->debug_log( '[Opti-Behavior-Heatmap] Failed to decompress file: ' . $full_file_path );
					return array();
				}
			}

			$file_data = json_decode( $json_data, true );
			if ( ! $file_data || ! isset( $file_data['events'] ) ) {
				$this->debug_log( '[Opti-Behavior-Heatmap] Invalid file format: ' . $full_file_path );
				return array();
			}

			$this->debug_log( '[Opti-Behavior-Heatmap] Loaded ' . count( $file_data['events'] ) . ' events from file: ' . $recording->file_path );
			return $file_data['events'];
		}

		// Otherwise, load from database.
		if ( empty( $recording->recording_data ) ) {
			return array();
		}

		// Check if data is encrypted.
		$data = json_decode( $recording->recording_data, true );

		if ( is_array( $data ) && isset( $data['ciphertext'] ) ) {
			// SECURITY: Verify centralized guard allows recordings before decrypting.
			if ( ! class_exists( 'Opti_Behavior_Pro_Feature_Guard' ) || ! Opti_Behavior_Pro_Feature_Guard::can_access( 'recordings' ) ) {
				$this->debug_log( '[Opti-Behavior-Heatmap] Playback blocked: recordings denied by centralized feature guard' );
				return array();
			}

			// Data is encrypted, decrypt it.
			if ( ! class_exists( 'Opti_Behavior_Session_Encryption' ) ) {
				$this->debug_log( '[Opti-Behavior-Heatmap] Encryption class not found' );
				return array();
			}

			$encryption = Opti_Behavior_Session_Encryption::get_instance();
			$decrypted  = $encryption->decrypt_session( $data );

			if ( $decrypted === false ) {
				$this->debug_log( '[Opti-Behavior-Heatmap] Failed to decrypt recording data for recording ID: ' . ( isset( $recording->id ) ? $recording->id : 'unknown' ) );
				return array();
			}

			$data = json_decode( $decrypted, true );

			if ( ! is_array( $data ) ) {
				$this->debug_log( '[Opti-Behavior-Heatmap] Decrypted data is not valid JSON' );
				return array();
			}
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Filter events to only include those from a specific page URL.
	 *
	 * For multi-page recordings, this finds the navigation event to the target page
	 * and returns all subsequent events until the next navigation.
	 *
	 * @param array  $events   Array of rrweb events.
	 * @param string $page_url Target page URL.
	 * @return array Filtered events.
	 */
	private function filter_events_by_page( $events, $page_url ) {
		if ( empty( $events ) || empty( $page_url ) ) {
			return $events;
		}

		$filtered = array();
		$on_target_page = false;
		$current_url = '';

		foreach ( $events as $event ) {
			// Meta events (type 4) contain the href (current page URL)
			if ( isset( $event['type'] ) && $event['type'] === 4 && isset( $event['data']['href'] ) ) {
				$event_url = $event['data']['href'];

				// Normalize URLs for comparison (remove trailing slashes and query strings)
				$normalized_event_url = rtrim( preg_replace( '/\?.*$/', '', $event_url ), '/' );
				$normalized_page_url = rtrim( preg_replace( '/\?.*$/', '', $page_url ), '/' );

				// Check if we're on the target page
				if ( $normalized_event_url === $normalized_page_url ||
				     strpos( $normalized_event_url, $normalized_page_url ) !== false ) {
					$on_target_page = true;
					$current_url = $event_url;
					$this->debug_log( "[Opti-Behavior-Heatmap] Found target page in meta event: {$event_url}" );
				} else {
					// Navigated away from target page
					if ( $on_target_page ) {
						$this->debug_log( "[Opti-Behavior-Heatmap] Navigated away from target page to: {$event_url}" );
					}
					$on_target_page = false;
					$current_url = $event_url;
				}
			}

			// Custom page_navigation events (type 5) - added to support explicit page navigation tracking
			if ( isset( $event['type'] ) && $event['type'] === 5 &&
			     isset( $event['data']['tag'] ) && $event['data']['tag'] === 'page_navigation' &&
			     isset( $event['data']['payload']['url'] ) ) {
				$event_url = $event['data']['payload']['url'];

				// Normalize URLs for comparison (remove trailing slashes and query strings)
				$normalized_event_url = rtrim( preg_replace( '/\?.*$/', '', $event_url ), '/' );
				$normalized_page_url = rtrim( preg_replace( '/\?.*$/', '', $page_url ), '/' );

				// Check if we're on the target page
				if ( $normalized_event_url === $normalized_page_url ||
				     strpos( $normalized_event_url, $normalized_page_url ) !== false ) {
					$on_target_page = true;
					$current_url = $event_url;
					$this->debug_log( "[Opti-Behavior-Heatmap] Found target page in page_navigation event: {$event_url}" );
				} else {
					// Navigated away from target page
					if ( $on_target_page ) {
						$this->debug_log( "[Opti-Behavior-Heatmap] Navigated away from target page (page_navigation) to: {$event_url}" );
					}
					$on_target_page = false;
					$current_url = $event_url;
				}
			}

			// For the first full snapshot (type 2), check if it contains the target URL in the data
			if ( empty( $current_url ) && isset( $event['type'] ) && $event['type'] === 2 ) {
				// Full snapshot event - might contain the initial URL
				if ( isset( $event['data']['href'] ) ) {
					$event_url = $event['data']['href'];
					$normalized_event_url = rtrim( preg_replace( '/\?.*$/', '', $event_url ), '/' );
					$normalized_page_url = rtrim( preg_replace( '/\?.*$/', '', $page_url ), '/' );

					if ( $normalized_event_url === $normalized_page_url ||
					     strpos( $normalized_event_url, $normalized_page_url ) !== false ) {
						$on_target_page = true;
						$current_url = $event_url;
						$this->debug_log( "[Opti-Behavior-Heatmap] Found target page in full snapshot: {$event_url}" );
					}
				}
			}

			// Include this event if we're on the target page
			if ( $on_target_page ) {
				$filtered[] = $event;
			}
		}

		// If we never detected the page (no meta/snapshot events with href), return all events
		// This handles single-page recordings where the page_id already matches
		if ( empty( $filtered ) && ! empty( $events ) ) {
			$this->debug_log( "[Opti-Behavior-Heatmap] No page detection events found, assuming single-page recording" );
			return $events;
		}

		// Log filtering results
		$click_events = array_filter( $filtered, function( $e ) {
			return isset( $e['type'] ) && $e['type'] === 3 &&
			       isset( $e['data']['source'] ) && $e['data']['source'] === 2;
		} );
		$this->debug_log( "[Opti-Behavior-Heatmap] Filtered " . count( $filtered ) . " events for target page (including " . count( $click_events ) . " click events)" );

		return $filtered;
	}

	/**
	 * Aggregate data from multiple recordings.
	 *
	 * @param array  $all_parsed_data Array of parsed data from multiple recordings.
	 * @param string $type Heatmap type.
	 * @param array  $viewport Viewport dimensions array with 'width' and 'height'.
	 * @return array Aggregated coordinates.
	 */
	private function aggregate_all_data( $all_parsed_data, $type, $viewport = array() ) {
		if ( empty( $all_parsed_data ) ) {
			$this->debug_log( "[Opti-Behavior-Heatmap] No parsed data to aggregate" );
			return array();
		}

		// Default viewport if not provided
		if ( empty( $viewport ) ) {
			$viewport = array( 'width' => 1920, 'height' => 1080 );
		}

		// Combine all coordinates.
		$combined = array(
			'clicks'  => array(),
			'moves'   => array(),
			'scrolls' => array(),
		);

		foreach ( $all_parsed_data as $parsed ) {
			if ( isset( $parsed['clicks'] ) && is_array( $parsed['clicks'] ) ) {
				$combined['clicks'] = array_merge( $combined['clicks'], $parsed['clicks'] );
			}
			if ( isset( $parsed['moves'] ) && is_array( $parsed['moves'] ) ) {
				$combined['moves'] = array_merge( $combined['moves'], $parsed['moves'] );
			}
			if ( isset( $parsed['scrolls'] ) && is_array( $parsed['scrolls'] ) ) {
				$combined['scrolls'] = array_merge( $combined['scrolls'], $parsed['scrolls'] );
			}
		}

		// Log combined data stats
		$this->debug_log( "[Opti-Behavior-Heatmap] Combined data - Clicks: " . count( $combined['clicks'] ) .
				", Moves: " . count( $combined['moves'] ) .
				", Scrolls: " . count( $combined['scrolls'] ) );

		// Aggregate based on type, passing viewport information.
		$result = $this->parser->aggregate_data( $combined, $type, $viewport );

		$this->debug_log( "[Opti-Behavior-Heatmap] Final aggregated points: " . count( $result ) );

		return $result;
	}

	/**
	 * Get a SQL fragment that excludes recordings captured before a page heatmap reset.
	 *
	 * @param int $page_id Page ID from optibehavior_pages.
	 * @return string Prepared SQL fragment or an empty string.
	 */
	private function get_heatmap_reset_where_clause( $page_id ) {
		global $wpdb;

		$resets = get_option( 'opti_behavior_heatmap_page_resets', array() );
		if ( ! is_array( $resets ) || empty( $resets[ $page_id ] ) ) {
			return '';
		}

		$reset_at = sanitize_text_field( $resets[ $page_id ] );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $reset_at ) ) {
			return '';
		}

		return $wpdb->prepare( 'AND r.start_time >= %s', $reset_at );
	}

	/**
	 * Get date where clause for SQL query.
	 *
	 * @param string $date_range Date range.
	 * @return string WHERE clause.
	 */
	private function get_date_where_clause( $date_range, $start_date = '', $end_date = '' ) {
		$now = current_time( 'mysql' );

		if ( 'custom' === $date_range && $start_date && $end_date ) {
			$start = gmdate( 'Y-m-d 00:00:00', strtotime( $start_date ) );
			$end   = gmdate( 'Y-m-d 23:59:59', strtotime( $end_date ) );
			return "AND r.start_time BETWEEN '" . esc_sql( $start ) . "' AND '" . esc_sql( $end ) . "'";
		}

		switch ( $date_range ) {
			case 'today':
				return "AND DATE(r.start_time) = CURDATE()";

			case 'yesterday':
				return "AND DATE(r.start_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";

			case 'last7days':
			case 'last_7_days':
				return "AND r.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";

			case 'last30days':
			case 'last_30_days':
				return "AND r.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

			case 'all':
			default:
				// No date filter - return all data
				return "";
		}
	}

	/**
	 * Count variant heatmap files for cache validation.
	 *
	 * Variant heatmap views use file metadata instead of the recordings SQL
	 * scope, so cache freshness must use the same A/B-specific file filters.
	 *
	 * @param array  $page_ids   Heatmap page IDs.
	 * @param string $device     Device scope.
	 * @param string $date_range Date range.
	 * @param array  $filters    Active filters.
	 * @param string $type       Heatmap type.
	 * @return int Matching file count.
	 */
	private function get_variant_heatmap_file_count( $page_ids, $device, $date_range, $filters, $type ) {
		$type_map = array(
			'click'     => 'clicks',
			'move'      => 'moves',
			'scroll'    => 'scrolls',
			'attention' => 'scrolls',
		);

		$storage_type    = isset( $type_map[ $type ] ) ? $type_map[ $type ] : 'clicks';
		$heatmap_storage = Opti_Behavior_Heatmap_Storage::get_instance();
		$file_filters    = array(
			'date_range' => $date_range,
		);

		if ( ! $this->is_all_device_scope( $device ) ) {
			$file_filters['device'] = $device;
		}

		foreach ( array( 'ab_test_id', 'variant_id', 'start_date', 'end_date' ) as $filter_key ) {
			if ( ! empty( $filters[ $filter_key ] ) ) {
				$file_filters[ $filter_key ] = $filters[ $filter_key ];
			}
		}

		$group_url_hashes = array();
		foreach ( $page_ids as $canonical_page_id ) {
			foreach ( $heatmap_storage->get_url_hashes_for_page( $canonical_page_id ) as $url_hash ) {
				if ( $url_hash ) {
					$group_url_hashes[] = $url_hash;
				}
			}
		}
		$group_url_hashes = array_values( array_unique( $group_url_hashes ) );

		if ( ! empty( $filters['exclude_spam'] ) ) {
			$spam_session_ids = $this->get_spam_allowed_session_ids( $page_ids );

			// Orphan-aware admission (RC-C, spec §3.2) — keep this cache-validation
			// count on the same file universe as the actual variant render.
			$orphan_tokens = $this->get_orphan_session_tokens_via_bridge( $page_ids, $group_url_hashes, $heatmap_storage );
			if ( ! empty( $orphan_tokens ) ) {
				$spam_session_ids = array_values( array_unique( array_merge( $spam_session_ids, $orphan_tokens ) ) );
			}

			if ( empty( $spam_session_ids ) ) {
				$spam_session_ids = array( '__opti_no_spam_sessions__' );
			}
			$file_filters['session_ids'] = $spam_session_ids;
		}

		$total_files = 0;
		foreach ( $group_url_hashes as $url_hash ) {
			$file_result  = $heatmap_storage->read_heatmap_files( $url_hash, $storage_type, $file_filters );
			$total_files += isset( $file_result['total_files'] ) ? (int) $file_result['total_files'] : 0;
		}

		return $total_files;
	}

	/**
	 * Get recording count for cache validation.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $device Device type.
	 * @param string $date_range Date range.
	 * @return int Number of recordings.
	 */
	private function get_recording_count( $page_id, $device, $date_range, $page_ids = array(), $filters = array(), $type = 'click' ) {
		global $wpdb;

		$page_ids    = ! empty( $page_ids ) ? $page_ids : array( $page_id );
		$page_ids    = array_values( array_unique( array_filter( array_map( 'absint', $page_ids ) ) ) );
		$date_where  = $this->get_date_where_clause( $date_range, $filters['start_date'] ?? '', $filters['end_date'] ?? '' );
		$reset_where = $this->get_heatmap_reset_where_clause( $page_id );
		$page_clause = $this->build_page_ids_clause( 'sp.page_id', $page_ids );

		$table_recordings    = $wpdb->prefix . 'optibehavior_recordings';
		$table_sessions      = $wpdb->prefix . 'optibehavior_sessions';
		$table_visitors      = $wpdb->prefix . 'optibehavior_visitors';
		$table_session_pages = $wpdb->prefix . 'optibehavior_session_pages';
		$spam_filter         = ! empty( $filters['exclude_spam'] ) ? $this->build_recordings_spam_filter_sql( 'r', 's', 'hm_recording_count_spam_scrolls' ) : null;
		$spam_join           = $spam_filter ? $spam_filter['join'] : '';
		$spam_where          = $spam_filter ? $spam_filter['where'] : '';
		$spam_values         = $spam_filter ? $spam_filter['values'] : array();

		if ( ( ! empty( $filters['ab_test_id'] ) || ! empty( $filters['variant_id'] ) ) && class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			return $this->get_variant_heatmap_file_count( $page_ids, $device, $date_range, $filters, $type );
		}

		$device_where  = '';
		$device_params = array();
		if ( ! $this->is_all_device_scope( $device ) ) {
			$device_where    = 'AND LOWER(v.device_type) = LOWER(%s)';
			$device_params[] = $device;
		}

		// Recording still has playable data (file on disk or inline DB payload).
		// The Pro archiver clears file_path/recording_data once a recording's
		// file is pruned by retention cleanup, so exclude those rows here too.
		$playable_where = " AND ( ( r.file_path IS NOT NULL AND r.file_path != '' ) OR ( r.recording_data IS NOT NULL AND r.recording_data != '' ) )";

		// Use same logic as get_recordings() - join with visitors table for device info.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table identifiers from $wpdb->prefix; values bound via prepared placeholders array.
		$query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT r.id)
			FROM {$table_recordings} r
			INNER JOIN {$table_sessions} s ON r.session_id = s.id
			INNER JOIN {$table_visitors} v ON s.visitor_id = v.id
			INNER JOIN {$table_session_pages} sp ON sp.session_id = r.session_id
			{$spam_join}
			WHERE {$page_clause}
			{$device_where}
			{$date_where}
			{$reset_where}
			{$spam_where}
			{$playable_where}",
			array_merge( $page_ids, $device_params, $spam_values )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		return (int) $wpdb->get_var( $query );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * AJAX handler: proxy a frontend page for heatmap iframe display.
	 *
	 * Some hosting providers (e.g., Hostinger CDN) add X-Frame-Options: DENY
	 * at the infrastructure level, which cannot be overridden from PHP.
	 * This proxy fetches the page HTML server-side via wp_remote_get() and
	 * returns it so the JS can inject it into the iframe via srcdoc.
	 *
	 * SECURITY: Only admins can use this endpoint, and only same-site URLs
	 * are allowed (must match the WordPress site URL).
	 *
	 * @since 1.5.17
	 */
	public function ajax_proxy_page() {
		// Verify nonce.
		if ( ! check_ajax_referer( 'opti_heatmap_detail', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
		}

		// Verify permissions - admin only.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		if ( function_exists( 'opti_behavior_pro_require_access' ) ) {
			opti_behavior_pro_require_access( 'heatmap_detail' );
		}

		// Get and validate the URL.
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( empty( $url ) ) {
			wp_send_json_error( array( 'message' => 'No URL provided' ) );
		}

		// SECURITY: Only allow same-site URLs to prevent SSRF attacks.
		$site_url  = wp_parse_url( home_url(), PHP_URL_HOST );
		$requested = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $site_url || ! $requested || strtolower( $site_url ) !== strtolower( $requested ) ) {
			wp_send_json_error( array( 'message' => 'Only same-site URLs are allowed' ) );
		}

		// Fetch the page HTML server-side (bypasses CDN X-Frame-Options).
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'sslverify'  => false,
				'user-agent' => 'OptiBehavior-HeatmapProxy/1.0',
				'cookies'    => array(), // Fetch as guest (no cookies).
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => 'Failed to fetch page: ' . $response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 400 ) {
			wp_send_json_error( array( 'message' => 'Page returned HTTP ' . $status_code ) );
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			wp_send_json_error( array( 'message' => 'Empty page response' ) );
		}

		// Strip ALL script tags from the proxy HTML.
		// The proxy injects HTML via iframe.srcdoc which has a null origin.
		// Scripts fail to execute properly in this context (jQuery not defined,
		// Elementor errors, etc.), causing visual rendering differences.
		// For a heatmap background we only need the CSS visual layout,
		// not interactive JavaScript.
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<script[^>]*\/>/i', '', $html );
		// Also strip noscript tags to avoid duplicate or misplaced elements.
		$html = preg_replace( '/<noscript[^>]*>.*?<\/noscript>/is', '', $html );

		// Inject a <base> tag so relative URLs (CSS, images) resolve correctly.
		// Also inject CSS to hide admin bar and disable pointer events/scrolling.
		$base_url    = trailingslashit( home_url() );
		$inject_head = '<base href="' . esc_url( $base_url ) . '">' . "\n"
			. '<style>html{margin-top:0!important}body{margin-top:0!important;overflow:hidden!important}'
			. '#wpadminbar{display:none!important}</style>' . "\n";

		// Insert after <head> tag (case-insensitive).
		$html = preg_replace( '/(<head[^>]*>)/i', '$1' . "\n" . $inject_head, $html, 1 );

		// Return the HTML as a JSON response.
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Get session IDs for a specific A/B test variant.
	 *
	 * Used to filter heatmap data to only show events from sessions belonging to a variant.
	 *
	 * @param int $ab_test_id A/B test ID.
	 * @param int $variant_id Variant ID.
	 * @return array Array of session ID strings.
	 */
	private function get_variant_session_ids( $ab_test_id, $variant_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_ab_impressions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix; values bound via %d placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT session_id FROM " . $table . " WHERE test_id = %d AND variant_id = %d AND session_id IS NOT NULL AND session_id != ''",
				$ab_test_id,
				$variant_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
	}
}
