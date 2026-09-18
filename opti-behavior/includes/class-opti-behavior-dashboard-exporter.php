<?php
/**
 * Dashboard Exporter.
 *
 * Builds safe dashboard-level CSV/JSON exports.
 *
 * @package opti-behavior
 * @since 1.2.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard SQL uses internal table/fragments with runtime values still prepared; WP 5.8 support prevents %i identifiers.

/**
 * Dashboard report exporter.
 *
 * This class exports aggregated dashboard report data only. It intentionally
 * excludes raw IPs, rrweb/recording payloads, SQL dumps, and raw stack traces.
 */
class Opti_Behavior_Dashboard_Exporter {

	/**
	 * Row limit per dataset.
	 *
	 * @var int
	 */
	private $row_limit = 100000;

	/**
	 * WordPress database object.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Table existence cache.
	 *
	 * @var array
	 */
	private $table_exists = array();

	/**
	 * Column cache.
	 *
	 * @var array
	 */
	private $columns = array();

	/**
	 * Truncated datasets.
	 *
	 * @var array
	 */
	private $truncated = array();

	/**
	 * Internal table suffixes used by dashboard export SQL.
	 *
	 * @var array
	 */
	private $allowed_table_suffixes = array(
		'opti_behavior_funnel_tracking',
		'opti_behavior_funnels',
		'optibehavior_ab_conversions',
		'optibehavior_ab_goals',
		'optibehavior_ab_impressions',
		'optibehavior_ab_tests',
		'optibehavior_ab_variants',
		'optibehavior_bot_visits',
		'optibehavior_broken_links',
		'optibehavior_daily_stats',
		'optibehavior_error_types',
		'optibehavior_events',
		'optibehavior_form_interactions',
		'optibehavior_form_submissions',
		'optibehavior_friction',
		'optibehavior_pages',
		'optibehavior_pageviews',
		'optibehavior_performance',
		'optibehavior_recordings',
		'optibehavior_session_pages',
		'optibehavior_sessions',
		'optibehavior_visitors',
	);

	/**
	 * Constructor.
	 *
	 * @param array $args Optional arguments.
	 */
	public function __construct( $args = array() ) {
		global $wpdb;

		$this->wpdb = $wpdb;
		if ( isset( $args['row_limit'] ) ) {
			$this->row_limit = max( 1, absint( $args['row_limit'] ) );
		}
	}

	/**
	 * Get exact dataset headers.
	 *
	 * @return array
	 */
	public static function get_dataset_headers() {
		$headers = array(
			'summary'                => array( 'metric', 'label', 'current_value', 'previous_value', 'change_percent', 'unit' ),
			'daily_history'          => array( 'date', 'sessions', 'visitors', 'pageviews', 'bounce_sessions', 'bounce_rate', 'avg_session_time_seconds', 'avg_scroll_depth_percent', 'human_sessions', 'spam_sessions', 'automated_sessions', 'new_visitors', 'returning_visitors' ),
			'top_pages'              => array( 'rank', 'page_id', 'title', 'url', 'sessions', 'pageviews', 'visitors', 'clicks', 'scroll_events', 'avg_scroll_depth_percent', 'avg_time_on_page_seconds', 'bounce_rate_percent', 'last_seen_at' ),
			'top_users'              => array( 'rank', 'visitor_id', 'user_id', 'display_name', 'daily_frequency', 'avg_session_seconds', 'total_time_seconds', 'sessions', 'pages_per_session', 'country', 'country_name', 'last_seen_at' ),
			'referrers'              => array( 'rank', 'referrer_type', 'referrer_url', 'utm_source', 'utm_medium', 'utm_campaign', 'sessions', 'percentage' ),
			'countries'              => array( 'rank', 'country_code', 'country_name', 'sessions', 'visitors', 'percentage' ),
			'browsers'               => array( 'rank', 'browser', 'sessions', 'percentage' ),
			'device_types'           => array( 'device_type', 'sessions', 'percentage' ),
			'operating_systems'      => array( 'rank', 'os', 'sessions', 'percentage' ),
			'screen_resolutions'     => array( 'rank', 'screen_resolution', 'width', 'height', 'sessions', 'percentage' ),
			'traffic_classification' => array( 'traffic_type', 'sessions', 'percentage', 'spam_reason' ),
			'new_vs_returning'       => array( 'visitor_type', 'visitors', 'percentage' ),
			'visited_directories'    => array( 'directory_label', 'directory_path', 'pageviews', 'percentage' ),
			'user_intent'            => array( 'intent_level', 'sessions', 'percentage' ),
			'bot_traffic'            => array( 'bot_type', 'visits', 'percentage', 'last_seen_at' ),
			'realtime_snapshot'      => array( 'snapshot_at', 'active_visitors', 'recent_session_id', 'visitor_id', 'current_url', 'country', 'device_type', 'browser', 'last_activity_at' ),
			'heatmap_summary'        => array( 'page_id', 'title', 'url', 'total_clicks', 'total_scroll_events', 'click_sessions', 'scroll_sessions', 'last_event_at' ),
			'funnels'                => array( 'funnel_id', 'name', 'status', 'steps_count', 'started_sessions', 'completed_sessions', 'conversion_rate_percent', 'avg_completion_seconds', 'created_at', 'updated_at' ),
			'funnel_steps'           => array( 'funnel_id', 'step_index', 'step_name', 'url_pattern', 'match_type', 'sessions_reached', 'dropoffs', 'step_conversion_rate_percent' ),
			'ab_tests'               => array( 'test_id', 'name', 'status', 'test_type', 'stat_engine', 'optimization', 'target_url', 'traffic_percent', 'started_at', 'ended_at', 'winner_variant_id', 'created_at', 'updated_at' ),
			'ab_variants'            => array( 'test_id', 'variant_id', 'name', 'is_control', 'traffic_weight', 'impressions', 'unique_visitors', 'conversions', 'conversion_rate_percent', 'total_revenue', 'created_at' ),
			'ab_goals'               => array( 'test_id', 'goal_id', 'name', 'goal_type', 'is_primary', 'conversions', 'total_revenue', 'created_at' ),
			'recordings_summary'     => array( 'recording_id', 'session_id', 'page_id', 'page_title', 'page_url', 'start_time', 'duration_seconds', 'file_size', 'storage_type', 'watched', 'device_type', 'browser', 'os', 'country', 'country_name', 'pages_count', 'events_count' ),
			'errors_summary'         => array( 'error_type_id', 'error_hash', 'error_type', 'error_message', 'error_source', 'severity', 'status', 'occurrence_count', 'affected_sessions', 'affected_users', 'affected_pages', 'priority', 'first_seen', 'last_seen' ),
			'friction_events'        => array( 'friction_id', 'session_id', 'recording_id', 'page_id', 'friction_type', 'element_selector', 'element_tag', 'element_id', 'element_class', 'element_text', 'click_count', 'time_window', 'x_position', 'y_position', 'viewport_width', 'viewport_height', 'url', 'browser', 'device_type', 'visitor_id', 'occurred_at' ),
			'performance'            => array( 'id', 'session_id', 'page_id', 'url', 'dns_lookup', 'tcp_connection', 'ssl_handshake', 'ttfb', 'response_time', 'dom_interactive', 'dom_complete', 'load_complete', 'lcp', 'fid', 'cls', 'fcp', 'inp', 'resource_count', 'total_transfer_size', 'performance_score', 'is_slow', 'browser', 'device_type', 'connection_type', 'visitor_id', 'measured_at' ),
			'broken_links'           => array( 'id', 'url', 'source_page_id', 'source_url', 'link_text', 'http_status', 'error_type', 'occurrence_count', 'affected_sessions', 'status', 'first_detected', 'last_detected', 'fixed_at' ),
			'form_analytics_forms'   => array( 'form_id', 'form_name', 'form_action', 'form_plugin', 'page_id', 'url', 'sessions', 'submissions', 'abandons', 'conversion_rate_percent', 'avg_time_to_complete_seconds', 'total_fields', 'avg_fields_interacted', 'avg_fields_completed', 'last_activity_at' ),
			'form_analytics_fields'  => array( 'form_id', 'field_name', 'field_type', 'field_label', 'field_order', 'interactions', 'avg_time_spent_seconds', 'changed_count', 'error_count', 'refill_count', 'left_blank_count', 'abandonment_count' ),
			'user_journeys'          => array( 'rank', 'path', 'sessions', 'percentage', 'avg_duration_seconds', 'entry_page', 'exit_page', 'completed_funnel_count' ),
		);

		return function_exists( 'apply_filters' ) ? apply_filters( 'opti_behavior_dashboard_export_dataset_headers', $headers, 'dashboard' ) : $headers;
	}

	/**
	 * Get exact dataset headers for the Pro-only full raw traffic export.
	 *
	 * @return array
	 */
	public static function get_raw_traffic_headers() {
		return array(
			'raw_visitors'      => array( 'visitor_id', 'first_visit', 'last_visit', 'visit_count', 'total_sessions', 'total_pageviews', 'device_type', 'browser', 'browser_version', 'os', 'os_version', 'screen_width', 'screen_height', 'country', 'country_name', 'region', 'city', 'timezone', 'language' ),
			'raw_sessions'      => array( 'session_id', 'visitor_id', 'user_id', 'start_time', 'end_time', 'duration_seconds', 'page_views', 'events_count', 'is_bounce', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'entry_page', 'exit_page', 'ip', 'traffic_type', 'bot_type', 'spam_reason', 'device_type', 'browser', 'os', 'country', 'country_name' ),
			'raw_pageviews'     => array( 'pageview_id', 'session_id', 'visitor_id', 'page_id', 'url', 'title', 'view_time', 'time_on_page_seconds', 'scroll_depth_percent', 'exit_page', 'session_start_time', 'traffic_type' ),
			'raw_journey_steps' => array( 'journey_step_id', 'recording_id', 'session_id', 'visitor_id', 'page_id', 'url', 'title', 'entry_time', 'exit_time', 'duration_seconds', 'events_count', 'clicks_count', 'scroll_depth_percent', 'page_order', 'device_type', 'browser', 'os', 'country', 'country_name', 'screen_width', 'screen_height', 'referrer', 'visitor_type', 'utm_campaign', 'utm_source', 'utm_medium', 'traffic_channel', 'watched', 'storage_type', 'file_path' ),
			'raw_journeys'      => array( 'session_id', 'visitor_id', 'user_id', 'start_time', 'end_time', 'duration_seconds', 'page_views', 'events_count', 'entry_page', 'exit_page', 'path', 'page_ids', 'countries', 'device_types', 'browsers', 'traffic_type' ),
		);
	}

	/**
	 * Normalize export filters.
	 *
	 * @param array|null $raw Raw request values.
	 * @return array
	 */
	public function normalize_filters( $raw = null ) {
		$raw = is_array( $raw ) ? $raw : array();

		$format = isset( $raw['format'] ) ? sanitize_key( wp_unslash( $raw['format'] ) ) : 'csv';
		$format = in_array( $format, array( 'csv', 'json' ), true ) ? $format : 'csv';

		$period = isset( $raw['period'] ) ? sanitize_key( wp_unslash( $raw['period'] ) ) : 'last30days';
		$period = in_array( $period, array( 'today', 'yesterday', 'last7days', 'last14days', 'last30days', 'thismonth', 'custom' ), true ) ? $period : 'last30days';

		$start = isset( $raw['start_date'] ) ? sanitize_text_field( wp_unslash( $raw['start_date'] ) ) : '';
		$end   = isset( $raw['end_date'] ) ? sanitize_text_field( wp_unslash( $raw['end_date'] ) ) : '';
		$range = $this->resolve_date_range( $period, $start, $end );

		$scope = isset( $raw['scope'] ) ? sanitize_key( wp_unslash( $raw['scope'] ) ) : 'dashboard';
		if ( in_array( $scope, array( 'raw_traffic', 'full_raw_traffic' ), true ) ) {
			$scope = 'raw_traffic';
		} else {
			$scope = 'dashboard';
		}

		return array(
			'format'       => $format,
			'period'       => $period,
			'start_date'   => $range['start'],
			'end_date'     => $range['end'],
			'exclude_spam' => ! empty( $raw['exclude_spam'] ) && '0' !== (string) $raw['exclude_spam'],
			'scope'        => $scope,
		);
	}

	/**
	 * Build the dashboard report array.
	 *
	 * @param array|null $raw_filters Raw request values.
	 * @return array
	 */
	public function build_report( $raw_filters = null ) {
		$this->truncated = array();
		$filters         = $this->normalize_filters( $raw_filters );
		$raw_gate_valid  = false;

		if ( 'raw_traffic' === $filters['scope'] ) {
			if ( ! $this->can_export_full_raw_traffic() ) {
				return new WP_Error(
					'opti_behavior_raw_traffic_pro_required',
					__( 'Full raw traffic export requires an active and valid Opti-Behavior Pro license.', 'opti-behavior' ),
					array( 'status' => 403 )
				);
			}

			$raw_gate_valid = true;
			$datasets = $this->normalize_raw_traffic_datasets( $this->build_raw_traffic_datasets( $filters ) );
		} else {
			$datasets = $this->normalize_datasets( $this->build_datasets( $filters ) );
		}

		return array(
			'meta'        => array(
				'plugin'             => 'opti-behavior',
				'plugin_version'     => defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '',
				'site_url'           => home_url(),
				'site_name'          => get_bloginfo( 'name' ),
				'exported_at'        => current_time( 'mysql' ),
				'exported_at_utc'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'timezone'           => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : get_option( 'timezone_string', 'UTC' ),
				'format_version'     => 1,
				'scope'              => $filters['scope'],
				'row_limit'          => $this->row_limit,
				'truncated'          => ! empty( $this->truncated ),
				'truncated_datasets' => array_values( $this->truncated ),
			),
			'filters'     => array(
				'period'       => $filters['period'],
				'start_date'   => $filters['start_date'],
				'end_date'     => $filters['end_date'],
				'exclude_spam' => (bool) $filters['exclude_spam'],
			),
			'environment' => array(
				'free_active'        => true,
				'pro_active'         => $this->is_pro_active(),
				'pro_gate_valid'     => $raw_gate_valid,
				'available_sections' => array_keys( $datasets ),
			),
			'datasets'    => $datasets,
		);
	}

	/**
	 * Stream report based on requested format.
	 *
	 * @param array|null $raw_filters Raw request values.
	 * @return void
	 */
	public function stream( $raw_filters = null ) {
		$filters = $this->normalize_filters( $raw_filters );
		$report  = $this->build_report( $raw_filters );

		if ( is_wp_error( $report ) ) {
			$this->stream_error( $report );
		}

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		if ( 'json' === $filters['format'] ) {
			$this->stream_json( $report );
		}

		$this->stream_csv( $report );
	}

	/**
	 * Build report datasets.
	 *
	 * @param array $filters Normalized filters.
	 * @return array
	 */
	private function build_datasets( $filters ) {
		$datasets = array(
			'summary'                => $this->summary_rows( $filters ),
			'daily_history'          => $this->daily_history_rows( $filters ),
			'top_pages'              => $this->top_pages_rows( $filters ),
			'top_users'              => $this->top_users_rows( $filters ),
			'referrers'              => $this->referrer_rows( $filters ),
			'countries'              => $this->visitor_dimension_rows( $filters, 'countries', 'v.country', 'country_code', 'v.country_name', 'country_name' ),
			'browsers'               => $this->visitor_dimension_rows( $filters, 'browsers', 'v.browser', 'browser' ),
			'device_types'           => $this->device_type_rows( $filters ),
			'operating_systems'      => $this->visitor_dimension_rows( $filters, 'operating_systems', 'v.os', 'os' ),
			'screen_resolutions'     => $this->screen_resolution_rows( $filters ),
			'traffic_classification' => $this->traffic_classification_rows( $filters ),
			'new_vs_returning'       => $this->new_vs_returning_rows( $filters ),
			'visited_directories'    => $this->visited_directory_rows( $filters ),
			'user_intent'            => $this->user_intent_rows( $filters ),
			'bot_traffic'            => $this->bot_traffic_rows( $filters ),
			'realtime_snapshot'      => $this->realtime_snapshot_rows(),
			'heatmap_summary'        => $this->heatmap_summary_rows( $filters ),
		);

		if ( $this->table_exists( 'opti_behavior_funnels' ) && $this->table_exists( 'opti_behavior_funnel_tracking' ) ) {
			$datasets['funnels']      = $this->funnel_rows( $filters );
			$datasets['funnel_steps'] = $this->funnel_step_rows( $filters );
		}

		if ( $this->table_exists( 'optibehavior_ab_tests' ) ) {
			$datasets['ab_tests']    = $this->ab_test_rows();
			$datasets['ab_variants'] = $this->ab_variant_rows( $filters );
			$datasets['ab_goals']    = $this->ab_goal_rows( $filters );
		}

		if ( $this->pro_feature_allowed( 'recordings' ) && $this->table_exists( 'optibehavior_recordings' ) ) {
			$datasets['recordings_summary'] = $this->recordings_summary_rows( $filters );
		}

		if ( $this->pro_feature_allowed( 'error_tracking' ) ) {
			$this->add_pro_error_tracking_datasets( $datasets, $filters );
		}

		if ( $this->pro_feature_allowed( 'form_analytics' ) ) {
			$this->add_form_analytics_datasets( $datasets, $filters );
		}

		if ( $this->pro_feature_allowed( 'user_journey' ) ) {
			$journeys = $this->user_journey_rows( $filters );
			if ( ! empty( $journeys ) ) {
				$datasets['user_journeys'] = $journeys;
			}
		}

		return apply_filters( 'opti_behavior_dashboard_export_datasets', $datasets, $filters, $this );
	}

	/**
	 * Build Pro-gated full raw traffic datasets.
	 *
	 * The raw traffic scope is intentionally separate from the safe dashboard
	 * report scope. It includes granular traffic rows, including session IP
	 * values, and is therefore only available after the strict Pro gate passes.
	 *
	 * @param array $filters Normalized filters.
	 * @return array
	 */
	private function build_raw_traffic_datasets( $filters ) {
		return array(
			'raw_visitors'      => $this->raw_visitor_rows( $filters ),
			'raw_sessions'      => $this->raw_session_rows( $filters ),
			'raw_pageviews'     => $this->raw_pageview_rows( $filters ),
			'raw_journey_steps' => $this->raw_journey_step_rows( $filters ),
			'raw_journeys'      => $this->raw_journey_rows( $filters ),
		);
	}

	/**
	 * Raw visitor-level traffic rows.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function raw_visitor_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_visitors' ) ) {
			return array();
		}

		$v = $this->table( 'optibehavior_visitors' );

		return $this->limit_rows(
			'raw_visitors',
			$this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT id visitor_id, first_visit, last_visit, visit_count, total_sessions, total_pageviews, device_type, browser, browser_version, os, os_version, screen_width, screen_height, country, country_name, region, city, timezone, language
					FROM {$v}
					WHERE (last_visit BETWEEN %s AND %s OR first_visit BETWEEN %s AND %s)
					ORDER BY last_visit DESC
					LIMIT %d",
					$filters['start_date'],
					$filters['end_date'],
					$filters['start_date'],
					$filters['end_date'],
					$this->row_limit + 1
				),
				ARRAY_A
			)
		);
	}

	/**
	 * Raw session-level traffic rows.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function raw_session_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return array();
		}

		$s          = $this->table( 'optibehavior_sessions' );
		$visitor_sql = array(
			"'' device_type",
			"'' browser",
			"'' os",
			"'' country",
			"'' country_name",
		);
		$join       = '';
		if ( $this->table_exists( 'optibehavior_visitors' ) ) {
			$v           = $this->table( 'optibehavior_visitors' );
			$join        = " LEFT JOIN {$v} v ON v.id = s.visitor_id ";
			$visitor_sql = array(
				'v.device_type device_type',
				'v.browser browser',
				'v.os os',
				'v.country country',
				'v.country_name country_name',
			);
		}

		$spam = $this->spam_clause( 's', $filters['exclude_spam'] );

		return $this->limit_rows(
			'raw_sessions',
			$this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT s.id session_id, s.visitor_id, s.user_id, s.start_time, s.end_time, s.duration duration_seconds, s.page_views, s.events_count, s.is_bounce, s.referrer, s.utm_source, s.utm_medium, s.utm_campaign, s.utm_term, s.utm_content, s.entry_page, s.exit_page, s.ip, s.traffic_type, s.bot_type, s.spam_reason, ' . implode( ', ', $visitor_sql ) . "
					FROM {$s} s
					{$join}
					WHERE s.start_time BETWEEN %s AND %s {$spam}
					ORDER BY s.start_time DESC
					LIMIT %d",
					$filters['start_date'],
					$filters['end_date'],
					$this->row_limit + 1
				),
				ARRAY_A
			)
		);
	}

	/**
	 * Raw pageview-level traffic rows.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function raw_pageview_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_pageviews' ) ) {
			return array();
		}

		$pv          = $this->table( 'optibehavior_pageviews' );
		$session_sql = array(
			"'' session_start_time",
			"'' traffic_type",
		);
		$join        = '';
		$spam        = '';
		if ( $this->table_exists( 'optibehavior_sessions' ) ) {
			$s           = $this->table( 'optibehavior_sessions' );
			$join        = " LEFT JOIN {$s} s ON s.id = pv.session_id ";
			$session_sql = array(
				's.start_time session_start_time',
				's.traffic_type traffic_type',
			);
			$spam        = $this->spam_clause( 's', $filters['exclude_spam'] );
		}

		return $this->limit_rows(
			'raw_pageviews',
			$this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT pv.id pageview_id, pv.session_id, pv.visitor_id, pv.page_id, pv.url, pv.title, pv.view_time, pv.time_on_page time_on_page_seconds, pv.scroll_depth scroll_depth_percent, pv.exit_page, ' . implode( ', ', $session_sql ) . "
					FROM {$pv} pv
					{$join}
					WHERE pv.view_time BETWEEN %s AND %s {$spam}
					ORDER BY pv.view_time DESC, pv.id DESC
					LIMIT %d",
					$filters['start_date'],
					$filters['end_date'],
					$this->row_limit + 1
				),
				ARRAY_A
			)
		);
	}

	/**
	 * Raw journey-step rows from session page detail data.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function raw_journey_step_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_session_pages' ) ) {
			return array();
		}

		$sp           = $this->table( 'optibehavior_session_pages' );
		$visitor_expr = "'' visitor_id";
		$join         = '';
		$spam         = '';
		if ( $this->table_exists( 'optibehavior_sessions' ) ) {
			$s            = $this->table( 'optibehavior_sessions' );
			$join         = " LEFT JOIN {$s} s ON s.id = sp.session_id ";
			$visitor_expr = 's.visitor_id visitor_id';
			$spam         = $this->spam_clause( 's', $filters['exclude_spam'] );
		}

		return $this->limit_rows(
			'raw_journey_steps',
			$this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT sp.id journey_step_id, sp.recording_id, sp.session_id, {$visitor_expr}, sp.page_id, sp.url, sp.title, sp.entry_time, sp.exit_time, sp.duration duration_seconds, sp.events_count, sp.clicks_count, sp.scroll_depth scroll_depth_percent, sp.page_order, sp.device_type, sp.browser, sp.os, sp.country, sp.country_name, sp.screen_width, sp.screen_height, sp.referrer, sp.visitor_type, sp.utm_campaign, sp.utm_source, sp.utm_medium, sp.traffic_channel, sp.watched, sp.storage_type, sp.file_path
					FROM {$sp} sp
					{$join}
					WHERE sp.entry_time BETWEEN %s AND %s {$spam}
					ORDER BY sp.entry_time DESC, sp.session_id ASC, sp.page_order ASC
					LIMIT %d",
					$filters['start_date'],
					$filters['end_date'],
					$this->row_limit + 1
				),
				ARRAY_A
			)
		);
	}

	/**
	 * Raw journey rows derived from step data when present, otherwise pageviews.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function raw_journey_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return array();
		}

		$s    = $this->table( 'optibehavior_sessions' );
		$spam = $this->spam_clause( 's', $filters['exclude_spam'] );

		if ( $this->table_exists( 'optibehavior_session_pages' ) ) {
			$sp = $this->table( 'optibehavior_session_pages' );
			return $this->limit_rows(
				'raw_journeys',
				$this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT s.id session_id, s.visitor_id, s.user_id, s.start_time, s.end_time, s.duration duration_seconds, s.page_views, s.events_count, s.entry_page, s.exit_page,
							GROUP_CONCAT(COALESCE(NULLIF(sp.title,''), sp.url) ORDER BY sp.page_order ASC, sp.entry_time ASC SEPARATOR ' > ') path,
							GROUP_CONCAT(sp.page_id ORDER BY sp.page_order ASC, sp.entry_time ASC SEPARATOR ',') page_ids,
							GROUP_CONCAT(DISTINCT sp.country ORDER BY sp.country SEPARATOR ',') countries,
							GROUP_CONCAT(DISTINCT sp.device_type ORDER BY sp.device_type SEPARATOR ',') device_types,
							GROUP_CONCAT(DISTINCT sp.browser ORDER BY sp.browser SEPARATOR ',') browsers,
							s.traffic_type
						FROM {$s} s
						LEFT JOIN {$sp} sp ON sp.session_id = s.id
						WHERE s.start_time BETWEEN %s AND %s {$spam}
						GROUP BY s.id, s.visitor_id, s.user_id, s.start_time, s.end_time, s.duration, s.page_views, s.events_count, s.entry_page, s.exit_page, s.traffic_type
						ORDER BY s.start_time DESC
						LIMIT %d",
						$filters['start_date'],
						$filters['end_date'],
						$this->row_limit + 1
					),
					ARRAY_A
				)
			);
		}

		if ( ! $this->table_exists( 'optibehavior_pageviews' ) ) {
			return array();
		}

		$pv = $this->table( 'optibehavior_pageviews' );
		return $this->limit_rows(
			'raw_journeys',
			$this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT s.id session_id, s.visitor_id, s.user_id, s.start_time, s.end_time, s.duration duration_seconds, s.page_views, s.events_count, s.entry_page, s.exit_page,
						GROUP_CONCAT(COALESCE(NULLIF(pv.title,''), pv.url) ORDER BY pv.view_time ASC, pv.id ASC SEPARATOR ' > ') path,
						GROUP_CONCAT(pv.page_id ORDER BY pv.view_time ASC, pv.id ASC SEPARATOR ',') page_ids,
						'' countries,
						'' device_types,
						'' browsers,
						s.traffic_type
					FROM {$s} s
					LEFT JOIN {$pv} pv ON pv.session_id = s.id
					WHERE s.start_time BETWEEN %s AND %s {$spam}
					GROUP BY s.id, s.visitor_id, s.user_id, s.start_time, s.end_time, s.duration, s.page_views, s.events_count, s.entry_page, s.exit_page, s.traffic_type
					ORDER BY s.start_time DESC
					LIMIT %d",
					$filters['start_date'],
					$filters['end_date'],
					$this->row_limit + 1
				),
				ARRAY_A
			)
		);
	}

	/**
	 * Resolve date range using dashboard semantics.
	 *
	 * @param string $period Period key.
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array
	 */
	private function resolve_date_range( $period, $start_date = '', $end_date = '' ) {
		$now = current_time( 'mysql' );

		if ( 'custom' === $period && $this->valid_ymd( $start_date ) && $this->valid_ymd( $end_date ) ) {
			return array(
				'start' => $start_date . ' 00:00:00',
				'end'   => $end_date . ' 23:59:59',
			);
		}

		switch ( $period ) {
			case 'today':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( $now ) );
				$end   = gmdate( 'Y-m-d 23:59:59', strtotime( $now ) );
				break;
			case 'yesterday':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day', strtotime( $now ) ) );
				$end   = gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day', strtotime( $now ) ) );
				break;
			case 'last7days':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days', strtotime( $now ) ) );
				$end   = gmdate( 'Y-m-d 23:59:59', strtotime( $now ) );
				break;
			case 'last14days':
				// Inclusive 14-day window, matching Opti_Behavior_Stats_Date_Range.
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-13 days', strtotime( $now ) ) );
				$end   = gmdate( 'Y-m-d 23:59:59', strtotime( $now ) );
				break;
			case 'thismonth':
				$start = gmdate( 'Y-m-01 00:00:00', strtotime( $now ) );
				$end   = gmdate( 'Y-m-d 23:59:59', strtotime( $now ) );
				break;
			case 'last30days':
			default:
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days', strtotime( $now ) ) );
				$end   = gmdate( 'Y-m-d 23:59:59', strtotime( $now ) );
				break;
		}

		$first = $this->first_data_date();
		if ( $first && strtotime( $start ) < strtotime( $first ) && in_array( $period, array( 'today', 'last7days', 'last14days', 'last30days', 'thismonth' ), true ) ) {
			$start = gmdate( 'Y-m-d 00:00:00', strtotime( $first ) );
		}

		return array( 'start' => $start, 'end' => $end );
	}

	/**
	 * Validate YYYY-MM-DD.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function valid_ymd( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		$parts = array_map( 'intval', explode( '-', $date ) );
		return checkdate( $parts[1], $parts[2], $parts[0] );
	}

	/**
	 * Get first available analytics date.
	 *
	 * @return string|null
	 */
	private function first_data_date() {
		$dates = array();
		if ( $this->table_exists( 'optibehavior_sessions' ) ) {
			$dates[] = $this->wpdb->get_var( 'SELECT MIN(start_time) FROM ' . $this->table( 'optibehavior_sessions' ) );
		}
		if ( $this->table_exists( 'optibehavior_pageviews' ) ) {
			$dates[] = $this->wpdb->get_var( 'SELECT MIN(view_time) FROM ' . $this->table( 'optibehavior_pageviews' ) );
		}
		$dates = array_filter( $dates );
		if ( empty( $dates ) ) {
			return null;
		}
		sort( $dates );
		return reset( $dates );
	}

	/**
	 * Summary dataset.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function summary_rows( $filters ) {
		$current    = $this->session_stats( $filters['start_date'], $filters['end_date'], $filters['exclude_spam'] );
		$duration   = max( 1, strtotime( $filters['end_date'] ) - strtotime( $filters['start_date'] ) + 1 );
		$prev_end   = gmdate( 'Y-m-d H:i:s', strtotime( $filters['start_date'] ) - 1 );
		$prev_start = gmdate( 'Y-m-d H:i:s', strtotime( $filters['start_date'] ) - $duration );
		$previous   = $this->session_stats( $prev_start, $prev_end, $filters['exclude_spam'] );
		$labels     = array(
			'visitors'         => array( 'Visitors', 'count' ),
			'sessions'         => array( 'Sessions', 'count' ),
			'pageviews'        => array( 'Page Views', 'count' ),
			'avg_session_time' => array( 'Avg. Session Time', 'seconds' ),
			'avg_scroll_depth' => array( 'Avg. Scroll Depth', 'percent' ),
			'bounce_rate'      => array( 'Bounce Rate', 'percent' ),
		);
		$rows       = array();
		foreach ( $labels as $metric => $label ) {
			$rows[] = array(
				'metric'         => $metric,
				'label'          => $label[0],
				'current_value'  => $current[ $metric ],
				'previous_value' => $previous[ $metric ],
				'change_percent' => $this->percent_change( $current[ $metric ], $previous[ $metric ] ),
				'unit'           => $label[1],
			);
		}
		return $rows;
	}

	/**
	 * Session aggregate stats.
	 *
	 * @param string $start Start datetime.
	 * @param string $end End datetime.
	 * @param bool   $exclude_spam Exclude spam.
	 * @return array
	 */
	private function session_stats( $start, $end, $exclude_spam ) {
		$default = array(
			'sessions'         => 0,
			'visitors'         => 0,
			'pageviews'        => 0,
			'avg_session_time' => 0,
			'avg_scroll_depth' => 0,
			'bounce_rate'      => 0,
		);
		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return $default;
		}

		$s     = $this->table( 'optibehavior_sessions' );
		$spam  = $this->spam_clause( '', $exclude_spam );
		$stats = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT COUNT(*) sessions, COUNT(DISTINCT visitor_id) visitors, COALESCE(SUM(page_views),0) pageviews, COALESCE(AVG(NULLIF(duration,0)),0) avg_duration, COALESCE(SUM(is_bounce),0) bounces FROM {$s} WHERE start_time BETWEEN %s AND %s {$spam}",
				$start,
				$end
			),
			ARRAY_A
		);

		if ( ! $stats ) {
			return $default;
		}

		$sessions   = (int) $stats['sessions'];
		$avg_scroll = $this->avg_scroll_depth( $start, $end, $exclude_spam );

		return array(
			'sessions'         => $sessions,
			'visitors'         => (int) $stats['visitors'],
			'pageviews'        => (int) $stats['pageviews'],
			'avg_session_time' => round( (float) $stats['avg_duration'], 2 ),
			'avg_scroll_depth' => round( (float) $avg_scroll, 2 ),
			'bounce_rate'      => $sessions > 0 ? round( ( (int) $stats['bounces'] / $sessions ) * 100, 2 ) : 0,
		);
	}

	/**
	 * Average scroll depth.
	 *
	 * @param string $start Start datetime.
	 * @param string $end End datetime.
	 * @param bool   $exclude_spam Exclude spam.
	 * @return float
	 */
	private function avg_scroll_depth( $start, $end, $exclude_spam ) {
		if ( ! $this->table_exists( 'optibehavior_pageviews' ) ) {
			return 0;
		}
		$pv = $this->table( 'optibehavior_pageviews' );
		if ( $exclude_spam && $this->table_exists( 'optibehavior_sessions' ) ) {
			$s = $this->table( 'optibehavior_sessions' );
			return (float) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COALESCE(AVG(pv.scroll_depth),0) FROM {$pv} pv INNER JOIN {$s} s ON pv.session_id = s.id WHERE pv.view_time BETWEEN %s AND %s AND pv.scroll_depth > 0 " . $this->spam_clause( 's', true ), $start, $end ) );
		}
		return (float) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COALESCE(AVG(scroll_depth),0) FROM {$pv} WHERE view_time BETWEEN %s AND %s AND scroll_depth > 0", $start, $end ) );
	}

	/**
	 * Daily history dataset.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function daily_history_rows( $filters ) {
		if ( $this->table_exists( 'optibehavior_daily_stats' ) ) {
			$t    = $this->table( 'optibehavior_daily_stats' );
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT stat_date date, sessions, visitors, pageviews, bounce_sessions, total_duration, total_scroll_depth, scroll_depth_count, human_sessions, spam_sessions, automated_sessions, new_visitors, returning_visitors FROM {$t} WHERE stat_date BETWEEN %s AND %s ORDER BY stat_date ASC LIMIT %d",
					gmdate( 'Y-m-d', strtotime( $filters['start_date'] ) ),
					gmdate( 'Y-m-d', strtotime( $filters['end_date'] ) ),
					$this->row_limit + 1
				),
				ARRAY_A
			);
			$rows = $this->limit_rows( 'daily_history', $rows );

			// Bounce-rate correction when spam is excluded — see get_daily_stats_from_aggregated().
			// `bounce_sessions` counts all traffic; with spam excluded the denominator is
			// human-only, so an all-traffic numerator can exceed 100%. Recompute per day
			// from sessions with the same spam filter on both numerator and denominator.
			$human_bounce_rate = array();
			if ( ! empty( $filters['exclude_spam'] ) && $this->table_exists( 'optibehavior_sessions' ) ) {
				$s          = $this->table( 'optibehavior_sessions' );
				$spam       = $this->spam_clause( '', true );
				$bounce_out = $this->wpdb->get_results(
					$this->wpdb->prepare(
						"SELECT DATE(start_time) date, COALESCE(SUM(is_bounce),0) bounces, COUNT(*) total FROM {$s} WHERE start_time BETWEEN %s AND %s {$spam} GROUP BY DATE(start_time)",
						$filters['start_date'],
						$filters['end_date']
					),
					ARRAY_A
				);
				if ( is_array( $bounce_out ) ) {
					foreach ( $bounce_out as $brow ) {
						$btotal = (int) $brow['total'];
						$human_bounce_rate[ $brow['date'] ] = $btotal > 0 ? min( 100.0, round( ( (int) $brow['bounces'] / $btotal ) * 100, 2 ) ) : 0;
					}
				}
			}

			$out  = array();
			foreach ( $rows as $row ) {
				$sessions = $filters['exclude_spam'] ? (int) $row['human_sessions'] : (int) $row['sessions'];
				// When spam is excluded the sessions-table recompute is authoritative.
				// Days archived out of the sessions table (aggregate retained) read 0 rather
				// than the stale all-traffic aggregate, matching the dashboard fix and
				// avoiding impossible >100% rows on 0-session days.
				if ( $filters['exclude_spam'] ) {
					$bounce_rate = isset( $human_bounce_rate[ $row['date'] ] ) ? $human_bounce_rate[ $row['date'] ] : 0;
				} else {
					$bounce_rate = $sessions > 0 ? min( 100.0, round( ( (int) $row['bounce_sessions'] / $sessions ) * 100, 2 ) ) : 0;
				}
				$out[]    = array(
					'date'                         => $row['date'],
					'sessions'                     => $sessions,
					'visitors'                     => (int) $row['visitors'],
					'pageviews'                    => (int) $row['pageviews'],
					'bounce_sessions'              => (int) $row['bounce_sessions'],
					'bounce_rate'                  => $bounce_rate,
					'avg_session_time_seconds'     => $sessions > 0 ? round( (int) $row['total_duration'] / $sessions, 2 ) : 0,
					'avg_scroll_depth_percent'     => (int) $row['scroll_depth_count'] > 0 ? round( (int) $row['total_scroll_depth'] / (int) $row['scroll_depth_count'], 2 ) : 0,
					'human_sessions'               => (int) $row['human_sessions'],
					'spam_sessions'                => (int) $row['spam_sessions'],
					'automated_sessions'           => (int) $row['automated_sessions'],
					'new_visitors'                 => (int) $row['new_visitors'],
					'returning_visitors'           => (int) $row['returning_visitors'],
				);
			}
			return $out;
		}

		return $this->daily_history_realtime_rows( $filters );
	}

	/**
	 * Realtime daily history fallback.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function daily_history_realtime_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return array();
		}
		$s    = $this->table( 'optibehavior_sessions' );
		$spam = $this->spam_clause( '', $filters['exclude_spam'] );
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT DATE(start_time) date, COUNT(*) sessions, COUNT(DISTINCT visitor_id) visitors, COALESCE(SUM(page_views),0) pageviews, COALESCE(SUM(is_bounce),0) bounce_sessions, COALESCE(AVG(NULLIF(duration,0)),0) avg_session_time_seconds, SUM(CASE WHEN traffic_type = 'spam' THEN 1 ELSE 0 END) spam_sessions, SUM(CASE WHEN traffic_type = 'automated' THEN 1 ELSE 0 END) automated_sessions FROM {$s} WHERE start_time BETWEEN %s AND %s {$spam} GROUP BY DATE(start_time) ORDER BY date ASC LIMIT %d",
				$filters['start_date'],
				$filters['end_date'],
				$this->row_limit + 1
			),
			ARRAY_A
		);
		$rows = $this->limit_rows( 'daily_history', $rows );
		$out  = array();
		foreach ( $rows as $row ) {
			$sessions = (int) $row['sessions'];
			$out[]    = array(
				'date'                         => $row['date'],
				'sessions'                     => $sessions,
				'visitors'                     => (int) $row['visitors'],
				'pageviews'                    => (int) $row['pageviews'],
				'bounce_sessions'              => (int) $row['bounce_sessions'],
				'bounce_rate'                  => $sessions > 0 ? round( ( (int) $row['bounce_sessions'] / $sessions ) * 100, 2 ) : 0,
				'avg_session_time_seconds'     => round( (float) $row['avg_session_time_seconds'], 2 ),
				'avg_scroll_depth_percent'     => 0,
				'human_sessions'               => $sessions - (int) $row['spam_sessions'] - (int) $row['automated_sessions'],
				'spam_sessions'                => (int) $row['spam_sessions'],
				'automated_sessions'           => (int) $row['automated_sessions'],
				'new_visitors'                 => 0,
				'returning_visitors'           => 0,
			);
		}
		return $out;
	}

	/**
	 * Top pages.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function top_pages_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_pageviews' ) || ! $this->table_exists( 'optibehavior_pages' ) ) {
			return array();
		}

		$pv    = $this->table( 'optibehavior_pageviews' );
		$p     = $this->table( 'optibehavior_pages' );
		$join  = '';
		$where = '';
		if ( $filters['exclude_spam'] && $this->table_exists( 'optibehavior_sessions' ) ) {
			$s     = $this->table( 'optibehavior_sessions' );
			$join  = " INNER JOIN {$s} s ON pv.session_id = s.id ";
			$where = $this->spam_clause( 's', true );
		}

		$events_join = '';
		$clicks_sql  = '0';
		$scroll_sql  = '0';
		if ( $this->table_exists( 'optibehavior_events' ) ) {
			$e           = $this->table( 'optibehavior_events' );
			$scroll_like = $this->wpdb->esc_like( 'scroll' ) . '%';
			$events_join = $this->wpdb->prepare(
				" LEFT JOIN (SELECT COALESCE(NULLIF(page_id2,0), page_id) page_id, SUM(CASE WHEN event = 'click' THEN 1 ELSE 0 END) clicks, SUM(CASE WHEN event LIKE %s THEN 1 ELSE 0 END) scroll_events FROM {$e} WHERE insert_at BETWEEN %s AND %s GROUP BY COALESCE(NULLIF(page_id2,0), page_id)) ev ON ev.page_id = p.id ",
				$scroll_like,
				$filters['start_date'],
				$filters['end_date']
			);
			$clicks_sql  = 'COALESCE(MAX(ev.clicks),0)';
			$scroll_sql  = 'COALESCE(MAX(ev.scroll_events),0)';
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT pv.page_id, MAX(COALESCE(NULLIF(p.title,''), pv.title)) title, MAX(COALESCE(NULLIF(p.url,''), pv.url)) url, COUNT(DISTINCT pv.session_id) sessions, COUNT(*) pageviews, COUNT(DISTINCT pv.visitor_id) visitors, {$clicks_sql} clicks, {$scroll_sql} scroll_events, COALESCE(AVG(NULLIF(pv.scroll_depth,0)),0) avg_scroll_depth_percent, COALESCE(AVG(NULLIF(pv.time_on_page,0)),0) avg_time_on_page_seconds, MAX(pv.view_time) last_seen_at FROM {$pv} pv INNER JOIN {$p} p ON pv.page_id = p.id {$join} {$events_join} WHERE pv.view_time BETWEEN %s AND %s {$where} GROUP BY pv.page_id ORDER BY pageviews DESC LIMIT %d",
				$filters['start_date'],
				$filters['end_date'],
				$this->row_limit + 1
			),
			ARRAY_A
		);

		$rows = $this->limit_rows( 'top_pages', $rows );
		$out  = array();
		$rank = 1;
		foreach ( $rows as $row ) {
			$out[] = array(
				'rank'                     => $rank++,
				'page_id'                  => (int) $row['page_id'],
				'title'                    => $row['title'],
				'url'                      => $row['url'],
				'sessions'                 => (int) $row['sessions'],
				'pageviews'                => (int) $row['pageviews'],
				'visitors'                 => (int) $row['visitors'],
				'clicks'                   => (int) $row['clicks'],
				'scroll_events'            => (int) $row['scroll_events'],
				'avg_scroll_depth_percent' => round( (float) $row['avg_scroll_depth_percent'], 2 ),
				'avg_time_on_page_seconds' => round( (float) $row['avg_time_on_page_seconds'], 2 ),
				'bounce_rate_percent'      => '',
				'last_seen_at'             => $row['last_seen_at'],
			);
		}
		return $out;
	}

	/**
	 * Top users.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function top_users_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return array();
		}
		$s    = $this->table( 'optibehavior_sessions' );
		$join = '';
		$country_sql = 'NULL';
		$country_name_sql = 'NULL';
		if ( $this->table_exists( 'optibehavior_visitors' ) ) {
			$v                = $this->table( 'optibehavior_visitors' );
			$join             = " LEFT JOIN {$v} v ON s.visitor_id = v.id ";
			$country_sql      = 'MAX(v.country)';
			$country_name_sql = 'MAX(v.country_name)';
		}
		$spam = $this->spam_clause( 's', $filters['exclude_spam'] );
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT s.visitor_id, MAX(s.user_id) user_id, COUNT(*) sessions, SUM(s.duration) total_time_seconds, AVG(NULLIF(s.duration,0)) avg_session_seconds, AVG(s.page_views) pages_per_session, MAX(s.start_time) last_seen_at, {$country_sql} country, {$country_name_sql} country_name FROM {$s} s {$join} WHERE s.start_time BETWEEN %s AND %s {$spam} GROUP BY s.visitor_id ORDER BY total_time_seconds DESC LIMIT %d",
				$filters['start_date'],
				$filters['end_date'],
				$this->row_limit + 1
			),
			ARRAY_A
		);

		$rows = $this->limit_rows( 'top_users', $rows );
		$days = max( 1, ceil( ( strtotime( $filters['end_date'] ) - strtotime( $filters['start_date'] ) + 1 ) / DAY_IN_SECONDS ) );
		$out  = array();
		$rank = 1;
		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id'];
			$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
			$out[]   = array(
				'rank'                => $rank++,
				'visitor_id'          => $row['visitor_id'],
				'user_id'             => $user_id,
				'display_name'        => $user ? $user->display_name : '',
				'daily_frequency'     => round( (int) $row['sessions'] / $days, 2 ),
				'avg_session_seconds' => round( (float) $row['avg_session_seconds'], 2 ),
				'total_time_seconds'  => (int) $row['total_time_seconds'],
				'sessions'            => (int) $row['sessions'],
				'pages_per_session'   => round( (float) $row['pages_per_session'], 2 ),
				'country'             => $row['country'],
				'country_name'        => $row['country_name'],
				'last_seen_at'        => $row['last_seen_at'],
			);
		}
		return $out;
	}

	/**
	 * Referrers.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function referrer_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return array();
		}
		$s     = $this->table( 'optibehavior_sessions' );
		$spam  = $this->spam_clause( '', $filters['exclude_spam'] );
		$total = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$s} WHERE start_time BETWEEN %s AND %s {$spam}", $filters['start_date'], $filters['end_date'] ) );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT COALESCE(NULLIF(utm_source,''), NULLIF(referrer,''), 'Direct') referrer_url, MAX(utm_source) utm_source, MAX(utm_medium) utm_medium, MAX(utm_campaign) utm_campaign, COUNT(*) sessions FROM {$s} WHERE start_time BETWEEN %s AND %s {$spam} GROUP BY referrer_url ORDER BY sessions DESC LIMIT %d",
				$filters['start_date'],
				$filters['end_date'],
				$this->row_limit + 1
			),
			ARRAY_A
		);
		$rows  = $this->limit_rows( 'referrers', $rows );
		$out   = array();
		$rank  = 1;
		foreach ( $rows as $row ) {
			$referrer = (string) $row['referrer_url'];
			$type     = 'Direct' === $referrer ? 'direct' : ( wp_parse_url( $referrer, PHP_URL_HOST ) ? 'referral' : 'campaign' );
			$out[]    = array(
				'rank'         => $rank++,
				'referrer_type'=> $type,
				'referrer_url' => $referrer,
				'utm_source'   => $row['utm_source'],
				'utm_medium'   => $row['utm_medium'],
				'utm_campaign' => $row['utm_campaign'],
				'sessions'     => (int) $row['sessions'],
				'percentage'   => $this->percent( $row['sessions'], $total ),
			);
		}
		return $out;
	}

	/**
	 * Visitor dimensions.
	 *
	 * @param array  $filters Filters.
	 * @param string $dataset Dataset key.
	 * @param string $expression SQL expression.
	 * @param string $field Output field.
	 * @param string $extra_expression Extra SQL expression.
	 * @param string $extra_field Extra output field.
	 * @return array
	 */
	private function visitor_dimension_rows( $filters, $dataset, $expression, $field, $extra_expression = '', $extra_field = '' ) {
		if ( ! $this->table_exists( 'optibehavior_sessions' ) || ! $this->table_exists( 'optibehavior_visitors' ) ) {
			return array();
		}
		$s      = $this->table( 'optibehavior_sessions' );
		$v      = $this->table( 'optibehavior_visitors' );
		$spam   = $this->spam_clause( 's', $filters['exclude_spam'] );
		$total  = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$s} s WHERE s.start_time BETWEEN %s AND %s {$spam}", $filters['start_date'], $filters['end_date'] ) );
		$extra  = $extra_expression ? ', MAX(' . $extra_expression . ') extra_value, COUNT(DISTINCT s.visitor_id) visitors' : '';
		$rows   = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT COALESCE(NULLIF({$expression},''), 'Unknown') label, COUNT(*) sessions {$extra} FROM {$s} s LEFT JOIN {$v} v ON s.visitor_id = v.id WHERE s.start_time BETWEEN %s AND %s {$spam} GROUP BY label ORDER BY sessions DESC LIMIT %d",
				$filters['start_date'],
				$filters['end_date'],
				$this->row_limit + 1
			),
			ARRAY_A
		);
		$rows   = $this->limit_rows( $dataset, $rows );
		$out    = array();
		$rank   = 1;
		foreach ( $rows as $row ) {
			$item = array(
				'rank'       => $rank++,
				$field       => $row['label'],
				'sessions'   => (int) $row['sessions'],
				'percentage' => $this->percent( $row['sessions'], $total ),
			);
			if ( $extra_field ) {
				$item[ $extra_field ] = $row['extra_value'];
				$item['visitors']     = (int) $row['visitors'];
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Device types.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function device_type_rows( $filters ) {
		$rows = $this->visitor_dimension_rows( $filters, 'device_types', 'v.device_type', 'device_type' );
		foreach ( $rows as $index => $row ) {
			unset( $rows[ $index ]['rank'] );
		}
		return $rows;
	}

	/**
	 * Screen resolutions.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function screen_resolution_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_sessions' ) || ! $this->table_exists( 'optibehavior_visitors' ) ) {
			return array();
		}
		$s     = $this->table( 'optibehavior_sessions' );
		$v     = $this->table( 'optibehavior_visitors' );
		$spam  = $this->spam_clause( 's', $filters['exclude_spam'] );
		$total = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$s} s WHERE s.start_time BETWEEN %s AND %s {$spam}", $filters['start_date'], $filters['end_date'] ) );
		$rows  = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT v.screen_width width, v.screen_height height, COUNT(*) sessions FROM {$s} s LEFT JOIN {$v} v ON s.visitor_id = v.id WHERE s.start_time BETWEEN %s AND %s {$spam} GROUP BY v.screen_width, v.screen_height ORDER BY sessions DESC LIMIT %d", $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A );
		$rows  = $this->limit_rows( 'screen_resolutions', $rows );
		$out   = array();
		$rank  = 1;
		foreach ( $rows as $row ) {
			$width = (int) $row['width'];
			$height = (int) $row['height'];
			$out[] = array(
				'rank'              => $rank++,
				'screen_resolution' => $width && $height ? $width . 'x' . $height : 'Unknown',
				'width'             => $width,
				'height'            => $height,
				'sessions'          => (int) $row['sessions'],
				'percentage'        => $this->percent( $row['sessions'], $total ),
			);
		}
		return $out;
	}

	/**
	 * Traffic classification.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function traffic_classification_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return array();
		}
		$s     = $this->table( 'optibehavior_sessions' );
		$rows  = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT COALESCE(NULLIF(traffic_type,''),'human') traffic_type, COALESCE(NULLIF(spam_reason,''),'') spam_reason, COUNT(*) sessions FROM {$s} WHERE start_time BETWEEN %s AND %s GROUP BY traffic_type, spam_reason ORDER BY sessions DESC LIMIT %d", $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A );
		$rows  = $this->limit_rows( 'traffic_classification', $rows );
		$total = array_sum( array_map( 'intval', wp_list_pluck( $rows, 'sessions' ) ) );
		$out   = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'traffic_type' => $row['traffic_type'],
				'sessions'     => (int) $row['sessions'],
				'percentage'   => $this->percent( $row['sessions'], $total ),
				'spam_reason'  => 'spam' === $row['traffic_type'] ? $row['spam_reason'] : '',
			);
		}
		return $out;
	}

	/**
	 * New vs returning.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function new_vs_returning_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_sessions' ) || ! $this->table_exists( 'optibehavior_visitors' ) ) {
			return array();
		}
		$s    = $this->table( 'optibehavior_sessions' );
		$v    = $this->table( 'optibehavior_visitors' );
		$spam = $this->spam_clause( 's', $filters['exclude_spam'] );
		$row  = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT COUNT(DISTINCT CASE WHEN v.visit_count <= 1 THEN s.visitor_id END) new_visitors, COUNT(DISTINCT CASE WHEN v.visit_count > 1 THEN s.visitor_id END) returning_visitors FROM {$s} s LEFT JOIN {$v} v ON s.visitor_id = v.id WHERE s.start_time BETWEEN %s AND %s {$spam}", $filters['start_date'], $filters['end_date'] ), ARRAY_A );
		$new  = (int) ( $row['new_visitors'] ?? 0 );
		$ret  = (int) ( $row['returning_visitors'] ?? 0 );
		$total = $new + $ret;
		return array(
			array( 'visitor_type' => 'new', 'visitors' => $new, 'percentage' => $this->percent( $new, $total ) ),
			array( 'visitor_type' => 'returning', 'visitors' => $ret, 'percentage' => $this->percent( $ret, $total ) ),
		);
	}

	/**
	 * Visited directories.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function visited_directory_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_pageviews' ) ) {
			return array();
		}
		$pv    = $this->table( 'optibehavior_pageviews' );
		$join  = '';
		$where = '';
		if ( $filters['exclude_spam'] && $this->table_exists( 'optibehavior_sessions' ) ) {
			$s     = $this->table( 'optibehavior_sessions' );
			$join  = " INNER JOIN {$s} s ON pv.session_id = s.id ";
			$where = $this->spam_clause( 's', true );
		}
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT pv.url, COUNT(*) pageviews FROM {$pv} pv {$join} WHERE pv.view_time BETWEEN %s AND %s {$where} GROUP BY pv.url ORDER BY pageviews DESC LIMIT %d", $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A );
		$rows = $this->limit_rows( 'visited_directories', $rows );
		$groups = array();
		$total = 0;
		foreach ( $rows as $row ) {
			$path  = (string) wp_parse_url( $row['url'], PHP_URL_PATH );
			$parts = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
			$dir   = empty( $parts ) ? '/' : '/' . $parts[0] . '/';
			if ( ! isset( $groups[ $dir ] ) ) {
				$groups[ $dir ] = array(
					'directory_label' => '/' === $dir ? 'Home' : trim( $dir, '/' ),
					'directory_path'  => $dir,
					'pageviews'       => 0,
				);
			}
			$groups[ $dir ]['pageviews'] += (int) $row['pageviews'];
			$total += (int) $row['pageviews'];
		}
		usort( $groups, function( $a, $b ) {
			return $b['pageviews'] <=> $a['pageviews'];
		} );
		foreach ( $groups as $index => $group ) {
			$groups[ $index ]['percentage'] = $this->percent( $group['pageviews'], $total );
		}
		return $groups;
	}

	/**
	 * User intent.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function user_intent_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return array();
		}
		$s     = $this->table( 'optibehavior_sessions' );
		$spam  = $this->spam_clause( '', $filters['exclude_spam'] );
		$rows  = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT CASE WHEN duration >= 180 OR page_views >= 5 THEN 'high' WHEN duration >= 60 OR page_views >= 2 THEN 'medium' ELSE 'low' END intent_level, COUNT(*) sessions FROM {$s} WHERE start_time BETWEEN %s AND %s {$spam} GROUP BY intent_level", $filters['start_date'], $filters['end_date'] ), ARRAY_A );
		$total = array_sum( array_map( 'intval', wp_list_pluck( $rows, 'sessions' ) ) );
		$out   = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'intent_level' => $row['intent_level'],
				'sessions'     => (int) $row['sessions'],
				'percentage'   => $this->percent( $row['sessions'], $total ),
			);
		}
		return $out;
	}

	/**
	 * Bot traffic.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function bot_traffic_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_bot_visits' ) ) {
			return array();
		}
		$t     = $this->table( 'optibehavior_bot_visits' );
		$rows  = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT COALESCE(NULLIF(bot_type,''),'Unknown') bot_type, COUNT(*) visits, MAX(visit_time) last_seen_at FROM {$t} WHERE visit_time BETWEEN %s AND %s GROUP BY bot_type ORDER BY visits DESC LIMIT %d", $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A );
		$rows  = $this->limit_rows( 'bot_traffic', $rows );
		$total = array_sum( array_map( 'intval', wp_list_pluck( $rows, 'visits' ) ) );
		$out   = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'bot_type'     => $row['bot_type'],
				'visits'       => (int) $row['visits'],
				'percentage'   => $this->percent( $row['visits'], $total ),
				'last_seen_at' => $row['last_seen_at'],
			);
		}
		return $out;
	}

	/**
	 * Realtime snapshot.
	 *
	 * @return array
	 */
	private function realtime_snapshot_rows() {
		if ( ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return array();
		}
		$s     = $this->table( 'optibehavior_sessions' );
		$join  = '';
		$visitor_select = 'NULL country, NULL device_type, NULL browser,';
		if ( $this->table_exists( 'optibehavior_visitors' ) ) {
			$v              = $this->table( 'optibehavior_visitors' );
			$join           = " LEFT JOIN {$v} v ON s.visitor_id = v.id ";
			$visitor_select = 'v.country, v.device_type, v.browser,';
		}
		$since  = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( 5 * MINUTE_IN_SECONDS ) );
		$active = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$s} WHERE start_time >= %s OR end_time >= %s", $since, $since ) );
		$rows   = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT s.id recent_session_id, s.visitor_id, COALESCE(NULLIF(s.exit_page,''), s.entry_page) current_url, {$visitor_select} COALESCE(s.end_time, s.start_time) last_activity_at FROM {$s} s {$join} ORDER BY COALESCE(s.end_time, s.start_time) DESC LIMIT %d", min( 10, $this->row_limit ) ), ARRAY_A );
		$out    = array();
		$now    = current_time( 'mysql' );
		foreach ( $rows as $row ) {
			$out[] = array(
				'snapshot_at'       => $now,
				'active_visitors'   => $active,
				'recent_session_id' => (int) $row['recent_session_id'],
				'visitor_id'        => $row['visitor_id'],
				'current_url'       => $row['current_url'],
				'country'           => $row['country'],
				'device_type'       => $row['device_type'],
				'browser'           => $row['browser'],
				'last_activity_at'  => $row['last_activity_at'],
			);
		}
		return $out;
	}

	/**
	 * Heatmap summary.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function heatmap_summary_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_events' ) ) {
			return array();
		}
		$e    = $this->table( 'optibehavior_events' );
		$join = '';
		$title_sql = "''";
		$url_sql   = "''";
		if ( $this->table_exists( 'optibehavior_pages' ) ) {
			$p         = $this->table( 'optibehavior_pages' );
			$join      = " LEFT JOIN {$p} p ON COALESCE(NULLIF(e.page_id2,0), e.page_id) = p.id ";
			$title_sql = 'MAX(p.title)';
			$url_sql   = 'MAX(p.url)';
		}
		$scroll_like = $this->wpdb->esc_like( 'scroll' ) . '%';
		$rows        = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT COALESCE(NULLIF(e.page_id2,0), e.page_id) page_id, {$title_sql} title, {$url_sql} url, SUM(CASE WHEN e.event = 'click' THEN 1 ELSE 0 END) total_clicks, SUM(CASE WHEN e.event LIKE %s THEN 1 ELSE 0 END) total_scroll_events, COUNT(DISTINCT CASE WHEN e.event = 'click' THEN e.session_id END) click_sessions, COUNT(DISTINCT CASE WHEN e.event LIKE %s THEN e.session_id END) scroll_sessions, MAX(e.insert_at) last_event_at FROM {$e} e {$join} WHERE e.insert_at BETWEEN %s AND %s GROUP BY page_id ORDER BY total_clicks DESC, total_scroll_events DESC LIMIT %d", $scroll_like, $scroll_like, $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A );
		return $this->limit_rows( 'heatmap_summary', $rows );
	}

	/**
	 * Funnel dataset.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function funnel_rows( $filters ) {
		$f    = $this->table( 'opti_behavior_funnels' );
		$tr   = $this->table( 'opti_behavior_funnel_tracking' );
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT f.id funnel_id, f.name, f.status, f.steps, COUNT(DISTINCT tr.session_id) started_sessions, SUM(CASE WHEN tr.completed = 1 THEN 1 ELSE 0 END) completed_sessions, AVG(CASE WHEN tr.completed = 1 AND tr.completion_time IS NOT NULL THEN TIMESTAMPDIFF(SECOND, tr.entry_time, tr.completion_time) END) avg_completion_seconds, f.created_at, f.updated_at FROM {$f} f LEFT JOIN {$tr} tr ON tr.funnel_id = f.id AND tr.entry_time BETWEEN %s AND %s GROUP BY f.id ORDER BY f.created_at DESC LIMIT %d", $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A );
		$rows = $this->limit_rows( 'funnels', $rows );
		foreach ( $rows as $index => $row ) {
			$steps = json_decode( $row['steps'], true );
			$rows[ $index ]['steps_count'] = is_array( $steps ) ? count( $steps ) : 0;
			$rows[ $index ]['conversion_rate_percent'] = $this->percent( $row['completed_sessions'], $row['started_sessions'] );
		}
		return $rows;
	}

	/**
	 * Funnel steps.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function funnel_step_rows( $filters ) {
		$f       = $this->table( 'opti_behavior_funnels' );
		$tr      = $this->table( 'opti_behavior_funnel_tracking' );
		$funnels = $this->wpdb->get_results( 'SELECT id, steps FROM ' . $f . ' ORDER BY id ASC LIMIT ' . (int) $this->row_limit, ARRAY_A );
		$out     = array();
		foreach ( $funnels as $funnel ) {
			$steps = json_decode( $funnel['steps'], true );
			if ( ! is_array( $steps ) ) {
				continue;
			}
			foreach ( array_values( $steps ) as $index => $step ) {
				$step_index = $index + 1;
				$reached    = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(DISTINCT session_id) FROM {$tr} WHERE funnel_id = %d AND max_step_reached >= %d AND entry_time BETWEEN %s AND %s", $funnel['id'], $step_index, $filters['start_date'], $filters['end_date'] ) );
				$next       = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(DISTINCT session_id) FROM {$tr} WHERE funnel_id = %d AND max_step_reached >= %d AND entry_time BETWEEN %s AND %s", $funnel['id'], $step_index + 1, $filters['start_date'], $filters['end_date'] ) );
				$out[]      = array(
					'funnel_id'                    => (int) $funnel['id'],
					'step_index'                   => $step_index,
					'step_name'                    => $step['name'] ?? $step['title'] ?? 'Step ' . $step_index,
					'url_pattern'                  => $step['url'] ?? $step['url_pattern'] ?? '',
					'match_type'                   => $step['match_type'] ?? 'contains',
					'sessions_reached'             => $reached,
					'dropoffs'                     => max( 0, $reached - $next ),
					'step_conversion_rate_percent' => $this->percent( $next, $reached ),
				);
			}
		}
		return $this->limit_rows( 'funnel_steps', $out );
	}

	/**
	 * A/B tests.
	 *
	 * @return array
	 */
	private function ab_test_rows() {
		$t = $this->table( 'optibehavior_ab_tests' );
		return $this->limit_rows( 'ab_tests', $this->wpdb->get_results( 'SELECT id test_id, name, status, test_type, stat_engine, optimization, target_url, traffic_percent, started_at, ended_at, winner_variant_id, created_at, updated_at FROM ' . $t . ' ORDER BY created_at DESC LIMIT ' . (int) ( $this->row_limit + 1 ), ARRAY_A ) );
	}

	/**
	 * A/B variants.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function ab_variant_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_ab_variants' ) ) {
			return array();
		}
		$v = $this->table( 'optibehavior_ab_variants' );
		$imp_join = '';
		$con_join = '';
		if ( $this->table_exists( 'optibehavior_ab_impressions' ) ) {
			$i        = $this->table( 'optibehavior_ab_impressions' );
			$imp_join = $this->wpdb->prepare( " LEFT JOIN (SELECT test_id, variant_id, COUNT(*) impressions, COUNT(DISTINCT visitor_id) unique_visitors FROM {$i} WHERE created_at BETWEEN %s AND %s GROUP BY test_id, variant_id) imp ON imp.variant_id = v.id AND imp.test_id = v.test_id ", $filters['start_date'], $filters['end_date'] );
		}
		if ( $this->table_exists( 'optibehavior_ab_conversions' ) ) {
			$c        = $this->table( 'optibehavior_ab_conversions' );
			$con_join = $this->wpdb->prepare( " LEFT JOIN (SELECT test_id, variant_id, COUNT(*) conversions, COALESCE(SUM(revenue),0) total_revenue FROM {$c} WHERE created_at BETWEEN %s AND %s GROUP BY test_id, variant_id) con ON con.variant_id = v.id AND con.test_id = v.test_id ", $filters['start_date'], $filters['end_date'] );
		}
		$rows = $this->limit_rows( 'ab_variants', $this->wpdb->get_results( 'SELECT v.test_id, v.id variant_id, v.name, v.is_control, v.traffic_weight, COALESCE(imp.impressions,0) impressions, COALESCE(imp.unique_visitors,0) unique_visitors, COALESCE(con.conversions,0) conversions, COALESCE(con.total_revenue,0) total_revenue, v.created_at FROM ' . $v . ' v ' . $imp_join . $con_join . ' ORDER BY v.test_id ASC, v.sort_order ASC, v.id ASC LIMIT ' . (int) ( $this->row_limit + 1 ), ARRAY_A ) );
		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['conversion_rate_percent'] = $this->percent( $row['conversions'], $row['impressions'] );
		}
		return $rows;
	}

	/**
	 * A/B goals.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function ab_goal_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_ab_goals' ) ) {
			return array();
		}
		$g = $this->table( 'optibehavior_ab_goals' );
		$con_join = '';
		if ( $this->table_exists( 'optibehavior_ab_conversions' ) ) {
			$c        = $this->table( 'optibehavior_ab_conversions' );
			$con_join = $this->wpdb->prepare( " LEFT JOIN (SELECT test_id, goal_id, COUNT(*) conversions, COALESCE(SUM(revenue),0) total_revenue FROM {$c} WHERE created_at BETWEEN %s AND %s GROUP BY test_id, goal_id) con ON con.goal_id = g.id AND con.test_id = g.test_id ", $filters['start_date'], $filters['end_date'] );
		}
		return $this->limit_rows( 'ab_goals', $this->wpdb->get_results( 'SELECT g.test_id, g.id goal_id, g.name, g.goal_type, g.is_primary, COALESCE(con.conversions,0) conversions, COALESCE(con.total_revenue,0) total_revenue, g.created_at FROM ' . $g . ' g ' . $con_join . ' ORDER BY g.test_id ASC, g.is_primary DESC, g.id ASC LIMIT ' . (int) ( $this->row_limit + 1 ), ARRAY_A ) );
	}

	/**
	 * Recordings summary.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function recordings_summary_rows( $filters ) {
		$r    = $this->table( 'optibehavior_recordings' );
		$join = '';
		if ( $this->table_exists( 'optibehavior_pages' ) ) {
			$p    = $this->table( 'optibehavior_pages' );
			$join = " LEFT JOIN {$p} p ON r.page_id = p.id ";
		}
		return $this->limit_rows( 'recordings_summary', $this->wpdb->get_results( $this->wpdb->prepare( "SELECT r.id recording_id, r.session_id, r.page_id, p.title page_title, p.url page_url, r.start_time, r.duration duration_seconds, r.file_size, r.storage_type, r.watched, r.device_type, r.browser, r.os, r.country, r.country_name, r.page_count pages_count, r.event_count events_count FROM {$r} r {$join} WHERE r.start_time BETWEEN %s AND %s ORDER BY r.start_time DESC LIMIT %d", $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A ) );
	}

	/**
	 * Add Pro error-tracking datasets.
	 *
	 * @param array $datasets Datasets.
	 * @param array $filters Filters.
	 * @return void
	 */
	private function add_pro_error_tracking_datasets( &$datasets, $filters ) {
		if ( $this->table_exists( 'optibehavior_error_types' ) ) {
			$t = $this->table( 'optibehavior_error_types' );
			$datasets['errors_summary'] = $this->limit_rows( 'errors_summary', $this->wpdb->get_results( $this->wpdb->prepare( "SELECT id error_type_id, error_hash, error_type, error_message, error_source, severity, status, occurrence_count, affected_sessions, affected_users, affected_pages, priority, first_seen, last_seen FROM {$t} WHERE last_seen BETWEEN %s AND %s ORDER BY occurrence_count DESC LIMIT %d", $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A ) );
		}
		if ( $this->table_exists( 'optibehavior_friction' ) ) {
			$t = $this->table( 'optibehavior_friction' );
			$datasets['friction_events'] = $this->limit_rows( 'friction_events', $this->wpdb->get_results( $this->wpdb->prepare( "SELECT id friction_id, session_id, recording_id, page_id, friction_type, element_selector, element_tag, element_id, element_class, element_text, click_count, time_window, x_position, y_position, viewport_width, viewport_height, url, browser, device_type, visitor_id, occurred_at FROM {$t} WHERE occurred_at BETWEEN %s AND %s ORDER BY occurred_at DESC LIMIT %d", $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A ) );
		}
		if ( $this->table_exists( 'optibehavior_performance' ) ) {
			$t = $this->table( 'optibehavior_performance' );
			$datasets['performance'] = $this->limit_rows( 'performance', $this->wpdb->get_results( $this->wpdb->prepare( "SELECT id, session_id, page_id, url, dns_lookup, tcp_connection, ssl_handshake, ttfb, response_time, dom_interactive, dom_complete, load_complete, lcp, fid, cls, fcp, inp, resource_count, total_transfer_size, performance_score, is_slow, browser, device_type, connection_type, visitor_id, measured_at FROM {$t} WHERE measured_at BETWEEN %s AND %s ORDER BY measured_at DESC LIMIT %d", $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A ) );
		}
		if ( $this->table_exists( 'optibehavior_broken_links' ) ) {
			$t = $this->table( 'optibehavior_broken_links' );
			$datasets['broken_links'] = $this->limit_rows( 'broken_links', $this->wpdb->get_results( $this->wpdb->prepare( "SELECT id, url, source_page_id, source_url, link_text, http_status, error_type, occurrence_count, affected_sessions, status, first_detected, last_detected, fixed_at FROM {$t} WHERE last_detected BETWEEN %s AND %s ORDER BY last_detected DESC LIMIT %d", $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A ) );
		}
	}

	/**
	 * Add form analytics datasets.
	 *
	 * @param array $datasets Datasets.
	 * @param array $filters Filters.
	 * @return void
	 */
	private function add_form_analytics_datasets( &$datasets, $filters ) {
		if ( $this->table_exists( 'optibehavior_form_submissions' ) ) {
			$t = $this->table( 'optibehavior_form_submissions' );
			$forms = $this->limit_rows( 'form_analytics_forms', $this->wpdb->get_results( $this->wpdb->prepare( "SELECT form_id, MAX(form_name) form_name, MAX(form_action) form_action, MAX(form_plugin) form_plugin, MAX(page_id) page_id, MAX(url) url, COUNT(DISTINCT session_id) sessions, SUM(was_submitted) submissions, SUM(was_abandoned) abandons, AVG(NULLIF(time_to_complete,0)) avg_time_to_complete_seconds, MAX(total_fields) total_fields, AVG(fields_interacted) avg_fields_interacted, AVG(fields_completed) avg_fields_completed, MAX(created_at) last_activity_at FROM {$t} WHERE created_at BETWEEN %s AND %s GROUP BY form_id ORDER BY sessions DESC LIMIT %d", $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A ) );
			foreach ( $forms as $index => $row ) {
				$forms[ $index ]['conversion_rate_percent'] = $this->percent( $row['submissions'], $row['sessions'] );
				$forms[ $index ]['avg_time_to_complete_seconds'] = round( (float) $row['avg_time_to_complete_seconds'], 2 );
				$forms[ $index ]['avg_fields_interacted'] = round( (float) $row['avg_fields_interacted'], 2 );
				$forms[ $index ]['avg_fields_completed'] = round( (float) $row['avg_fields_completed'], 2 );
			}
			$datasets['form_analytics_forms'] = $forms;
		}
		if ( $this->table_exists( 'optibehavior_form_interactions' ) ) {
			$t = $this->table( 'optibehavior_form_interactions' );
			$datasets['form_analytics_fields'] = $this->limit_rows( 'form_analytics_fields', $this->wpdb->get_results( $this->wpdb->prepare( "SELECT form_id, field_name, MAX(field_type) field_type, MAX(field_label) field_label, MIN(field_order) field_order, SUM(interaction_count) interactions, AVG(NULLIF(time_spent,0)) avg_time_spent_seconds, SUM(was_changed) changed_count, SUM(had_error) error_count, SUM(was_refilled) refill_count, SUM(left_blank) left_blank_count, 0 abandonment_count FROM {$t} WHERE created_at BETWEEN %s AND %s GROUP BY form_id, field_name ORDER BY form_id ASC, field_order ASC LIMIT %d", $filters['start_date'], $filters['end_date'], $this->row_limit + 1 ), ARRAY_A ) );
		}
	}

	/**
	 * User journey rows derived from pageviews.
	 *
	 * @param array $filters Filters.
	 * @return array
	 */
	private function user_journey_rows( $filters ) {
		if ( ! $this->table_exists( 'optibehavior_pageviews' ) || ! $this->table_exists( 'optibehavior_sessions' ) ) {
			return array();
		}
		$pv   = $this->table( 'optibehavior_pageviews' );
		$s    = $this->table( 'optibehavior_sessions' );
		$spam = $this->spam_clause( 's', $filters['exclude_spam'] );
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT path, COUNT(*) sessions, AVG(duration) avg_duration_seconds, MIN(entry_page) entry_page, MAX(exit_page) exit_page FROM (SELECT s.id sid, s.duration, s.entry_page, s.exit_page, GROUP_CONCAT(COALESCE(NULLIF(pv.title,''), pv.url) ORDER BY pv.view_time SEPARATOR ' > ') path FROM {$s} s INNER JOIN {$pv} pv ON pv.session_id = s.id WHERE s.start_time BETWEEN %s AND %s {$spam} GROUP BY s.id LIMIT 50000) paths GROUP BY path ORDER BY sessions DESC LIMIT %d", $filters['start_date'], $filters['end_date'], min( $this->row_limit + 1, 1000 ) ), ARRAY_A );
		$rows = $this->limit_rows( 'user_journeys', $rows );
		$total = array_sum( array_map( 'intval', wp_list_pluck( $rows, 'sessions' ) ) );
		$out = array();
		$rank = 1;
		foreach ( $rows as $row ) {
			$out[] = array(
				'rank'                   => $rank++,
				'path'                   => $row['path'],
				'sessions'               => (int) $row['sessions'],
				'percentage'             => $this->percent( $row['sessions'], $total ),
				'avg_duration_seconds'   => round( (float) $row['avg_duration_seconds'], 2 ),
				'entry_page'             => $row['entry_page'],
				'exit_page'              => $row['exit_page'],
				'completed_funnel_count' => 0,
			);
		}
		return $out;
	}

	/**
	 * Normalize all rows to exact header order and keys.
	 *
	 * @param array $datasets Datasets.
	 * @return array
	 */
	private function normalize_datasets( $datasets ) {
		$headers = self::get_dataset_headers();
		$out     = array();
		foreach ( $datasets as $key => $rows ) {
			if ( ! isset( $headers[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = array();
			foreach ( (array) $rows as $row ) {
				$item = array();
				foreach ( $headers[ $key ] as $header ) {
					$item[ $header ] = isset( $row[ $header ] ) ? $row[ $header ] : '';
				}
				$out[ $key ][] = $item;
			}
		}
		return $out;
	}

	/**
	 * Normalize raw traffic rows to exact header order and keys.
	 *
	 * @param array $datasets Datasets.
	 * @return array
	 */
	private function normalize_raw_traffic_datasets( $datasets ) {
		$headers = self::get_raw_traffic_headers();
		$out     = array();
		foreach ( $datasets as $key => $rows ) {
			if ( ! isset( $headers[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = array();
			foreach ( (array) $rows as $row ) {
				$item = array();
				foreach ( $headers[ $key ] as $header ) {
					$item[ $header ] = isset( $row[ $header ] ) ? $row[ $header ] : '';
				}
				$out[ $key ][] = $item;
			}
		}
		return $out;
	}

	/**
	 * Table name helper.
	 *
	 * @param string $suffix Table suffix.
	 * @return string
	 */
	private function table( $suffix ) {
		if ( ! in_array( $suffix, $this->allowed_table_suffixes, true ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $suffix ) ) {
			return '';
		}
		return $this->wpdb->prefix . $suffix;
	}

	/**
	 * Table existence check.
	 *
	 * @param string $suffix Table suffix.
	 * @return bool
	 */
	private function table_exists( $suffix ) {
		if ( ! isset( $this->table_exists[ $suffix ] ) ) {
			$table = $this->table( $suffix );
			if ( '' === $table ) {
				$this->table_exists[ $suffix ] = false;
			} else {
				$this->table_exists[ $suffix ] = (bool) $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			}
		}
		return $this->table_exists[ $suffix ];
	}

	/**
	 * Get columns.
	 *
	 * @param string $suffix Table suffix.
	 * @return array
	 */
	private function get_columns( $suffix ) {
		if ( ! isset( $this->columns[ $suffix ] ) ) {
			$this->columns[ $suffix ] = $this->table_exists( $suffix ) ? $this->wpdb->get_col( 'DESCRIBE ' . $this->table( $suffix ), 0 ) : array();
		}
		return $this->columns[ $suffix ];
	}

	/**
	 * Column existence check.
	 *
	 * @param string $suffix Table suffix.
	 * @param string $column Column name.
	 * @return bool
	 */
	private function column_exists( $suffix, $column ) {
		return in_array( $column, $this->get_columns( $suffix ), true );
	}

	/**
	 * Spam exclusion clause for sessions.
	 *
	 * @param string $alias Table alias.
	 * @param bool   $exclude_spam Exclude spam.
	 * @return string
	 */
	private function spam_clause( $alias = '', $exclude_spam = false ) {
		if ( ! $exclude_spam ) {
			return '';
		}
		$prefix   = $alias ? $alias . '.' : '';
		$settings = get_option( 'opti_behavior_traffic_settings', array( 'spam_detection_enabled' => true, 'spam_duration_threshold' => 3 ) );
		if ( ! empty( $settings['spam_detection_enabled'] ) && $this->column_exists( 'optibehavior_sessions', 'duration' ) ) {
			$threshold = isset( $settings['spam_duration_threshold'] ) ? absint( $settings['spam_duration_threshold'] ) : 3;
			return ' AND ((' . $prefix . 'traffic_type IS NULL OR ' . $prefix . "traffic_type = '' OR " . $prefix . "traffic_type NOT IN ('spam', 'bot', 'automated')) AND COALESCE(" . $prefix . 'duration, 0) >= ' . $threshold . ')';
		}
		return ' AND (' . $prefix . 'traffic_type IS NULL OR ' . $prefix . "traffic_type = '' OR " . $prefix . "traffic_type NOT IN ('spam', 'bot', 'automated'))";
	}

	/**
	 * Limit rows and record truncation.
	 *
	 * @param string $dataset Dataset key.
	 * @param array  $rows Rows.
	 * @return array
	 */
	private function limit_rows( $dataset, $rows ) {
		$rows = is_array( $rows ) ? $rows : array();
		if ( count( $rows ) > $this->row_limit ) {
			$this->truncated[ $dataset ] = array(
				'dataset'   => $dataset,
				'row_limit' => $this->row_limit,
				'truncated' => true,
			);
			return array_slice( $rows, 0, $this->row_limit );
		}
		return $rows;
	}

	/**
	 * Percentage helper.
	 *
	 * @param float $value Value.
	 * @param float $total Total.
	 * @return float
	 */
	private function percent( $value, $total ) {
		return (float) $total > 0 ? round( ( (float) $value / (float) $total ) * 100, 2 ) : 0;
	}

	/**
	 * Percent change helper.
	 *
	 * @param float $current Current value.
	 * @param float $previous Previous value.
	 * @return float
	 */
	private function percent_change( $current, $previous ) {
		$current  = (float) $current;
		$previous = (float) $previous;
		if ( $previous <= 0 ) {
			return $current > 0 ? 100 : 0;
		}
		return round( ( ( $current - $previous ) / $previous ) * 100, 2 );
	}

	/**
	 * Check if Pro is active.
	 *
	 * @return bool
	 */
	private function is_pro_active() {
		if ( function_exists( 'opti_behavior_pro_active' ) ) {
			return (bool) opti_behavior_pro_active();
		}
		if ( class_exists( 'Opti_Behavior_Gate' ) && is_callable( array( 'Opti_Behavior_Gate', 'pro_active' ) ) ) {
			return (bool) Opti_Behavior_Gate::pro_active();
		}
		return defined( 'OptiBehavior_PRO_VERSION' ) || defined( 'OPTI_BEHAVIOR_PRO_VERSION' );
	}

	/**
	 * Check whether the current install can access full raw traffic exports.
	 *
	 * This is stricter than the generic Pro-presence detector because the raw
	 * traffic scope can include sensitive fields such as session IPs. Constants
	 * or table presence are never accepted as proof of entitlement.
	 *
	 * @return bool
	 */
	public function can_export_full_raw_traffic() {
		if ( class_exists( 'Opti_Behavior_Gate' ) && is_callable( array( 'Opti_Behavior_Gate', 'pro_active' ) ) ) {
			if ( ! Opti_Behavior_Gate::pro_active() ) {
				return false;
			}
		} elseif ( function_exists( 'opti_behavior_pro_validate_env' ) ) {
			if ( ! opti_behavior_pro_validate_env() ) {
				return false;
			}
		} elseif ( function_exists( 'opti_behavior_pro_active' ) ) {
			if ( ! opti_behavior_pro_active() ) {
				return false;
			}
		} else {
			return false;
		}

		if ( ! class_exists( 'Opti_Behavior_Manifest_Manager' ) || ! is_callable( array( 'Opti_Behavior_Manifest_Manager', 'get_instance' ) ) ) {
			return false;
		}

		$manager = Opti_Behavior_Manifest_Manager::get_instance();
		if ( ! is_object( $manager ) || ! is_callable( array( $manager, 'has_pro_access' ) ) ) {
			return false;
		}

		// Empty feature key validates the signed Pro access token without
		// depending on a token feature name that older API responses may lack.
		if ( ! $manager->has_pro_access( '' ) ) {
			return false;
		}

		// A revoked licence (suspended / chargebacked / blacklisted / disabled)
		// must lose the raw-traffic scope immediately, even while a cached
		// signed token is still inside its short lifetime.
		if ( function_exists( 'opti_behavior_pro_access_context_denies' )
			&& opti_behavior_pro_access_context_denies( '' ) ) {
			return false;
		}

		/**
		 * Filters the raw-traffic export entitlement.
		 *
		 * Deny-only: returning false withholds the scope (and hides the export
		 * link); returning true can never grant a scope the licence checks
		 * above already refused.
		 *
		 * @since 1.9.0.7
		 *
		 * @param bool $allowed Whether the raw-traffic scope is entitled.
		 */
		return (bool) apply_filters( 'opti_behavior_dashboard_export_can_raw_traffic', true );
	}

	/**
	 * Check Pro feature gate.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	private function pro_feature_allowed( $feature ) {
		if ( ! $this->is_pro_active() ) {
			return false;
		}
		if ( class_exists( 'Opti_Behavior_Manifest_Manager' ) && is_callable( array( 'Opti_Behavior_Manifest_Manager', 'get_instance' ) ) ) {
			$manager = Opti_Behavior_Manifest_Manager::get_instance();
			if ( is_object( $manager ) && is_callable( array( $manager, 'has_pro_access' ) ) ) {
				return (bool) $manager->has_pro_access( $feature );
			}
		}
		return false;
	}

	/**
	 * Stream a gated export error.
	 *
	 * @param WP_Error $error Error object.
	 * @return void
	 */
	private function stream_error( $error ) {
		$status = 403;
		$data   = $error->get_error_data();
		if ( is_array( $data ) && ! empty( $data['status'] ) ) {
			$status = absint( $data['status'] );
		}
		if ( function_exists( 'status_header' ) ) {
			status_header( $status );
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $error->get_error_message() );
		exit;
	}

	/**
	 * Stream JSON.
	 *
	 * @param array $report Report.
	 * @return void
	 */
	private function stream_json( $report ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $this->filename( 'json', $report['meta']['scope'] ) . '"' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Stream CSV ZIP/fallback.
	 *
	 * @param array $report Report.
	 * @return void
	 */
	private function stream_csv( $report ) {
		$scope   = isset( $report['meta']['scope'] ) ? $report['meta']['scope'] : 'dashboard';
		$headers = $this->headers_for_scope( $scope );

		if ( class_exists( 'ZipArchive' ) ) {
			$tmp = wp_tempnam( $this->filename( 'zip', $scope ) );
			$zip = new ZipArchive();
			if ( true === $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
				$zip->addFromString( 'manifest.csv', $this->csv_string( $this->manifest_rows( $report ), array( 'key', 'value' ) ) );
				if ( ! empty( $report['meta']['truncated_datasets'] ) ) {
					$zip->addFromString( 'truncated_datasets.csv', $this->csv_string( $report['meta']['truncated_datasets'], array( 'dataset', 'row_limit', 'truncated' ) ) );
				}
				foreach ( $report['datasets'] as $dataset => $rows ) {
					if ( empty( $headers[ $dataset ] ) ) {
						continue;
					}
					$zip->addFromString( $dataset . '.csv', $this->csv_string( $rows, $headers[ $dataset ] ) );
				}
				$zip->close();
				$zip_body = $this->get_zip_file_contents( $tmp );
				if ( false !== $zip_body ) {
					header( 'Content-Type: application/zip' );
					header( 'Content-Disposition: attachment; filename="' . $this->filename( 'zip', $scope ) . '"' );
					header( 'Content-Length: ' . strlen( $zip_body ) );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw ZIP attachment; HTML escaping would corrupt the binary download.
					echo $zip_body;
					wp_delete_file( $tmp );
					exit;
				}
				wp_delete_file( $tmp );
			}
			// open() failed: wp_tempnam() already created the (empty) temp file.
			if ( $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $this->filename( 'csv', $scope ) . '"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw CSV attachment; HTML escaping would corrupt the download.
		echo $this->sectioned_csv_string( $report );
		exit;
	}

	/**
	 * Read a generated ZIP attachment through the WordPress filesystem API.
	 *
	 * @param string $path Absolute path to the generated temporary ZIP file.
	 * @return string|false ZIP bytes, or false when the filesystem API cannot read them.
	 */
	private function get_zip_file_contents( $path ) {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return false;
		}

		return $wp_filesystem->get_contents( $path );
	}

	/**
	 * Dataset headers for a report scope.
	 *
	 * @param string $scope Report scope.
	 * @return array
	 */
	private function headers_for_scope( $scope ) {
		$headers = 'raw_traffic' === $scope ? self::get_raw_traffic_headers() : self::get_dataset_headers();

		return apply_filters( 'opti_behavior_dashboard_export_headers_for_scope', $headers, $scope );
	}

	/**
	 * Create filename.
	 *
	 * @param string $extension File extension.
	 * @return string
	 */
	private function filename( $extension, $scope = 'dashboard' ) {
		$prefix = 'raw_traffic' === $scope ? 'opti-behavior-raw-traffic-export-' : 'opti-behavior-dashboard-export-';
		return $prefix . gmdate( 'Y-m-d-His' ) . '.' . $extension;
	}

	/**
	 * Manifest rows.
	 *
	 * @param array $report Report.
	 * @return array
	 */
	private function manifest_rows( $report ) {
		$rows    = array();
		$flatten = function( $prefix, $value ) use ( &$flatten, &$rows ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $child ) {
					$flatten( $prefix ? $prefix . '.' . $key : $key, $child );
				}
				return;
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}
			$rows[] = array( 'key' => $prefix, 'value' => $value );
		};
		$flatten( 'meta', $report['meta'] );
		$flatten( 'filters', $report['filters'] );
		$flatten( 'environment', $report['environment'] );
		return $rows;
	}

	/**
	 * Build a CSV string.
	 *
	 * @param array $rows Rows.
	 * @param array $headers Headers.
	 * @return string
	 */
	public function csv_string( $rows, $headers ) {
		$csv = $this->csv_line( $headers );
		foreach ( (array) $rows as $row ) {
			$line = array();
			foreach ( $headers as $header ) {
				$line[] = $this->escape_csv_cell( isset( $row[ $header ] ) ? $row[ $header ] : '' );
			}
			$csv .= $this->csv_line( $line );
		}
		return $csv;
	}

	/**
	 * Build fallback sectioned CSV.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	private function sectioned_csv_string( $report ) {
		$scope   = isset( $report['meta']['scope'] ) ? $report['meta']['scope'] : 'dashboard';
		$headers = $this->headers_for_scope( $scope );
		$csv     = $this->csv_line( array( '# dataset:manifest' ) );
		$csv    .= $this->csv_line( array( 'key', 'value' ) );
		foreach ( $this->manifest_rows( $report ) as $row ) {
			$csv .= $this->csv_line( array( $this->escape_csv_cell( $row['key'] ), $this->escape_csv_cell( $row['value'] ) ) );
		}
		foreach ( $report['datasets'] as $dataset => $rows ) {
			if ( empty( $headers[ $dataset ] ) ) {
				continue;
			}
			$csv .= $this->csv_line( array() );
			$csv .= $this->csv_line( array( '# dataset:' . $dataset ) );
			$csv .= $this->csv_line( $headers[ $dataset ] );
			foreach ( $rows as $row ) {
				$line = array();
				foreach ( $headers[ $dataset ] as $header ) {
					$line[] = $this->escape_csv_cell( isset( $row[ $header ] ) ? $row[ $header ] : '' );
				}
				$csv .= $this->csv_line( $line );
			}
		}
		return $csv;
	}

	/**
	 * Build one RFC-4180-style CSV row.
	 *
	 * @param array $fields Row fields.
	 * @return string
	 */
	private function csv_line( $fields ) {
		$escaped = array();
		foreach ( (array) $fields as $field ) {
			if ( is_bool( $field ) ) {
				$field = $field ? '1' : '0';
			}
			if ( is_array( $field ) || is_object( $field ) ) {
				$field = wp_json_encode( $field );
			}
			$field = (string) $field;
			if ( preg_match( '/[,"\\\\\r\n\t ]/', $field ) ) {
				$field = '"' . str_replace( '"', '""', $field ) . '"';
			}
			$escaped[] = $field;
		}
		return implode( ',', $escaped ) . "\n";
	}

	/**
	 * Escape a CSV cell and guard against spreadsheet formula injection.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public function escape_csv_cell( $value ) {
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value );
		}
		$value = (string) $value;
		if ( '' !== $value && preg_match( '/^[=+\-@]/', $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}
