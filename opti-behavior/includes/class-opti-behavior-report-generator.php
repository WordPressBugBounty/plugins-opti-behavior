<?php
/**
 * Report Generator Class
 *
 * Generates report data by collecting analytics from the dashboard
 * and formatting it for email templates.
 *
 * @package opti-behavior
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Report Generator Class
 *
 * Collects analytics data and generates report content.
 *
 * @since 1.1.0
 */
class Opti_Behavior_Report_Generator {

	/**
	 * Core instance.
	 *
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $core;

	/**
	 * Schedule data.
	 *
	 * @var array
	 */
	private $schedule;

	/**
	 * Report period start date.
	 *
	 * @var DateTime
	 */
	private $period_start;

	/**
	 * Report period end date.
	 *
	 * @var DateTime
	 */
	private $period_end;

	/**
	 * Whether Pro features are available.
	 *
	 * @var bool
	 */
	private $is_pro;

	/**
	 * Constructor.
	 *
	 * @param Opti_Behavior_Heatmap_Core $core     Core instance.
	 * @param array                      $schedule Schedule data.
	 */
	public function __construct( $core, $schedule = array() ) {
		$this->core     = $core;
		$this->schedule = $schedule;
		$this->is_pro   = function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active();

		if ( ! empty( $schedule ) ) {
			$this->calculate_period();
		}
	}

	/**
	 * Set the schedule for report generation.
	 *
	 * @param array $schedule Schedule data.
	 */
	public function set_schedule( $schedule ) {
		$this->schedule = $schedule;
		$this->calculate_period();
	}

	/**
	 * Calculate the report period based on schedule settings.
	 */
	private function calculate_period() {
		$tz = wp_timezone();
		$now = new DateTime( 'now', $tz );

		switch ( $this->schedule['report_period'] ) {
			case 'yesterday':
				$this->period_start = ( clone $now )->modify( 'yesterday midnight' );
				$this->period_end = ( clone $now )->modify( 'yesterday 23:59:59' );
				break;

			case 'last7days':
				$this->period_start = ( clone $now )->modify( '-7 days midnight' );
				$this->period_end = ( clone $now )->modify( 'yesterday 23:59:59' );
				break;

			case 'last30days':
				$this->period_start = ( clone $now )->modify( '-30 days midnight' );
				$this->period_end = ( clone $now )->modify( 'yesterday 23:59:59' );
				break;

			case 'thismonth':
				$this->period_start = ( clone $now )->modify( 'first day of this month midnight' );
				$this->period_end = ( clone $now )->modify( 'yesterday 23:59:59' );
				break;

			case 'lastmonth':
				$this->period_start = ( clone $now )->modify( 'first day of last month midnight' );
				$this->period_end = ( clone $now )->modify( 'last day of last month 23:59:59' );
				break;

			default:
				$this->period_start = ( clone $now )->modify( '-7 days midnight' );
				$this->period_end = ( clone $now )->modify( 'yesterday 23:59:59' );
		}
	}

	/**
	 * Generate the complete report data.
	 *
	 * @param array $schedule Optional schedule data. If provided, overrides the constructor schedule.
	 * @return array Report data.
	 */
	public function generate( $schedule = null ) {
		// If schedule passed as parameter, use it
		if ( null !== $schedule ) {
			$this->set_schedule( $schedule );
		}

		// Ensure we have a schedule
		if ( empty( $this->schedule ) ) {
			return array(
				'site_name' => get_bloginfo( 'name' ),
				'error'     => 'No schedule provided',
				'sections'  => array(),
			);
		}
		$report = array(
			'site_name'    => get_bloginfo( 'name' ),
			'site_url'     => home_url(),
			'period_label' => $this->get_period_label(),
			'period_start' => $this->period_start->format( 'Y-m-d' ),
			'period_end'   => $this->period_end->format( 'Y-m-d' ),
			'generated_at' => current_time( 'mysql' ),
			'is_pro'       => $this->is_pro,
			'sections'     => array(),
		);

		// Always include KPIs if enabled
		if ( ! empty( $this->schedule['include_kpis'] ) ) {
			$report['sections']['kpis'] = $this->get_kpi_data();
		}

		// Smart Insights
		if ( $this->should_include_section( 'include_smart_insights', true ) ) {
			$report['sections']['smart_insights'] = $this->get_smart_insights_data();
		}

		// Top pages
		if ( ! empty( $this->schedule['include_top_pages'] ) ) {
			$report['sections']['top_pages'] = $this->get_top_pages();
		}

		// Top referrers
		if ( ! empty( $this->schedule['include_top_referrers'] ) ) {
			$report['sections']['top_referrers'] = $this->get_top_referrers();
		}

		// Traffic breakdown
		if ( ! empty( $this->schedule['include_traffic_breakdown'] ) ) {
			$report['sections']['traffic_breakdown'] = $this->get_traffic_breakdown();
		}

		// Heatmap summary (click data)
		if ( ! empty( $this->schedule['include_heatmap_summary'] ) ) {
			$report['sections']['heatmap_summary'] = $this->get_heatmap_summary();
		}

		// Funnels
		if ( ! empty( $this->schedule['include_funnels'] ) ) {
			$report['sections']['funnels'] = $this->get_funnel_data();
		}

		// Geographic distribution
		if ( ! empty( $this->schedule['include_geographic'] ) ) {
			$report['sections']['geographic'] = $this->get_geographic_data();
		}

		// Pro-only sections
		if ( $this->is_pro ) {
			// Session recordings stats
			if ( ! empty( $this->schedule['include_recordings_stats'] ) ) {
				$report['sections']['recordings'] = $this->get_recordings_stats();
			}

			// Errors
			if ( ! empty( $this->schedule['include_errors'] ) ) {
				$report['sections']['errors'] = $this->get_errors_data();
			}

			// Friction events
			if ( ! empty( $this->schedule['include_friction'] ) ) {
				$report['sections']['friction'] = $this->get_friction_data();
			}

			// Performance metrics
			if ( ! empty( $this->schedule['include_performance'] ) ) {
				$report['sections']['performance'] = $this->get_performance_data();
			}

			// Broken links
			if ( ! empty( $this->schedule['include_broken_links'] ) ) {
				$report['sections']['broken_links'] = $this->get_broken_links_data();
			}

			// User journeys
			if ( ! empty( $this->schedule['include_user_journeys'] ) ) {
				$report['sections']['user_journeys'] = $this->get_user_journeys_data();
			}

			// Form analytics
			if ( ! empty( $this->schedule['include_form_analytics'] ) ) {
				$report['sections']['form_analytics'] = $this->get_form_analytics_data();
			}
		}

		return $report;
	}

	/**
	 * Get human-readable period label.
	 *
	 * @return string
	 */
	public function get_period_label() {
		$format = get_option( 'date_format', 'Y-m-d' );

		return sprintf(
			/* translators: 1: start date, 2: end date */
			__( '%1$s - %2$s', 'opti-behavior' ),
			$this->period_start->format( $format ),
			$this->period_end->format( $format )
		);
	}

	/**
	 * Determine whether a report section should be included.
	 *
	 * New sections can default to enabled for legacy schedules whose rows do not
	 * yet contain the matching `include_*` column.
	 *
	 * @param string $field           Schedule include field.
	 * @param bool   $legacy_default  Default for missing legacy rows.
	 * @return bool
	 */
	private function should_include_section( $field, $legacy_default = false ) {
		if ( array_key_exists( $field, $this->schedule ) ) {
			return ! empty( $this->schedule[ $field ] );
		}

		return (bool) $legacy_default;
	}

	/**
	 * Resolve the current report spam-exclusion policy.
	 *
	 * @return bool
	 */
	private function should_exclude_spam() {
		if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
			return Opti_Behavior_Stats_Spam_Filter::should_exclude( null );
		}

		return true;
	}

	/**
	 * Build canonical session spam SQL for report queries.
	 *
	 * @param string $alias Sessions table alias.
	 * @return string
	 */
	private function session_spam_sql( $alias = 's' ) {
		if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
			return Opti_Behavior_Stats_Spam_Filter::session_sql( $alias, 'AND', null );
		}

		$alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $alias );
		return " AND {$alias}.traffic_type = 'human'";
	}

	/**
	 * Get KPI summary data.
	 *
	 * @return array
	 */
	private function get_kpi_data() {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		// Calculate previous period for comparison
		$period_days = $this->period_start->diff( $this->period_end )->days + 1;
		$prev_start = ( clone $this->period_start )->modify( "-{$period_days} days" )->format( 'Y-m-d H:i:s' );
		$prev_end = ( clone $this->period_start )->modify( '-1 second' )->format( 'Y-m-d H:i:s' );

		// Current period stats
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$current = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(DISTINCT s.visitor_id) as visitors,
					COUNT(*) as sessions,
					SUM(s.page_views) as pageviews,
					AVG(s.duration) as avg_duration,
					SUM(CASE WHEN s.is_bounce = 1 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0) as bounce_rate
				FROM {$wpdb->prefix}optibehavior_sessions s
				WHERE s.start_time BETWEEN %s AND %s
				{$session_spam_sql}",
				$start,
				$end
			),
			ARRAY_A
		);

		// Previous period stats
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$previous = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(DISTINCT s.visitor_id) as visitors,
					COUNT(*) as sessions,
					SUM(s.page_views) as pageviews,
					AVG(s.duration) as avg_duration,
					SUM(CASE WHEN s.is_bounce = 1 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0) as bounce_rate
				FROM {$wpdb->prefix}optibehavior_sessions s
				WHERE s.start_time BETWEEN %s AND %s
				{$session_spam_sql}",
				$prev_start,
				$prev_end
			),
			ARRAY_A
		);

		// Get average scroll depth (human traffic only)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$scroll = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(pv.scroll_depth)
				FROM {$wpdb->prefix}optibehavior_pageviews pv
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON pv.session_id = s.id
				WHERE pv.view_time BETWEEN %s AND %s
				{$session_spam_sql}",
				$start,
				$end
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$prev_scroll = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(pv.scroll_depth)
				FROM {$wpdb->prefix}optibehavior_pageviews pv
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON pv.session_id = s.id
				WHERE pv.view_time BETWEEN %s AND %s
				{$session_spam_sql}",
				$prev_start,
				$prev_end
			)
		);

		return array(
			'visitors'         => array(
				'value'  => (int) ( $current['visitors'] ?? 0 ),
				'change' => $this->calculate_change( $current['visitors'] ?? 0, $previous['visitors'] ?? 0 ),
			),
			'sessions'         => array(
				'value'  => (int) ( $current['sessions'] ?? 0 ),
				'change' => $this->calculate_change( $current['sessions'] ?? 0, $previous['sessions'] ?? 0 ),
			),
			'pageviews'        => array(
				'value'  => (int) ( $current['pageviews'] ?? 0 ),
				'change' => $this->calculate_change( $current['pageviews'] ?? 0, $previous['pageviews'] ?? 0 ),
			),
			'avg_session_time' => array(
				'value'     => (int) ( $current['avg_duration'] ?? 0 ),
				'formatted' => $this->format_duration( $current['avg_duration'] ?? 0 ),
				'change'    => $this->calculate_change( $current['avg_duration'] ?? 0, $previous['avg_duration'] ?? 0 ),
			),
			'bounce_rate'      => array(
				'value'  => round( (float) ( $current['bounce_rate'] ?? 0 ), 1 ),
				'change' => $this->calculate_change( $current['bounce_rate'] ?? 0, $previous['bounce_rate'] ?? 0, true ),
			),
			'avg_scroll_depth' => array(
				'value'  => round( (float) ( $scroll ?? 0 ), 1 ),
				'change' => $this->calculate_change( $scroll ?? 0, $prev_scroll ?? 0 ),
			),
		);
	}

	/**
	 * Get top pages data.
	 *
	 * @param int $limit Number of pages to return.
	 * @return array
	 */
	private function get_top_pages( $limit = 10 ) {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$pages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.title,
					p.url,
					COUNT(pv.id) as views,
					AVG(
						CASE
							WHEN pv.time_on_page > 0 THEN pv.time_on_page
							WHEN s.end_time IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(SECOND, pv.view_time, s.end_time))
							ELSE NULL
						END
					) as avg_time,
					AVG(pv.scroll_depth) as avg_scroll
				FROM {$wpdb->prefix}optibehavior_pageviews pv
				INNER JOIN {$wpdb->prefix}optibehavior_pages p ON pv.page_id = p.id
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON pv.session_id = s.id
				WHERE pv.view_time BETWEEN %s AND %s
				{$session_spam_sql}
				GROUP BY p.id
				ORDER BY views DESC
				LIMIT %d",
				$start,
				$end,
				$limit
			),
			ARRAY_A
		);

		foreach ( $pages as &$page ) {
			$page['views']              = (int) $page['views'];
			$avg_time                   = (float) ( $page['avg_time'] ?? 0 );
			$page['avg_time_formatted'] = $avg_time > 0 ? $this->format_duration( $avg_time ) : '—';
			$page['avg_scroll']         = round( (float) ( $page['avg_scroll'] ?? 0 ), 1 );
		}

		return $pages;
	}

	/**
	 * Get top referrers data.
	 *
	 * @param int $limit Number of referrers to return.
	 * @return array
	 */
	private function get_top_referrers( $limit = 10 ) {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$referrers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN r.referrer_url IS NULL OR r.referrer_url = '' THEN 'Direct'
						ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(r.referrer_url, '/', 3), '//', -1)
					END as source,
					r.referrer_type,
					COUNT(*) as visits
				FROM {$wpdb->prefix}optibehavior_referrers r
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON r.session_id = s.id
				WHERE r.created_at BETWEEN %s AND %s
				{$session_spam_sql}
				AND r.referrer_type != 'internal'
				GROUP BY source, r.referrer_type
				ORDER BY visits DESC
				LIMIT %d",
				$start,
				$end,
				$limit
			),
			ARRAY_A
		);

		foreach ( $referrers as &$ref ) {
			$ref['visits'] = (int) $ref['visits'];
		}

		return $referrers;
	}

	/**
	 * Get traffic breakdown data.
	 *
	 * @return array
	 */
	private function get_traffic_breakdown() {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$breakdown = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE WHEN r.referrer_type = 'internal' THEN 'direct' ELSE r.referrer_type END as type,
					COUNT(*) as count
				FROM {$wpdb->prefix}optibehavior_referrers r
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON r.session_id = s.id
				WHERE r.created_at BETWEEN %s AND %s
				{$session_spam_sql}
				GROUP BY type
				ORDER BY count DESC",
				$start,
				$end
			),
			ARRAY_A
		);

		$total = array_sum( array_column( $breakdown, 'count' ) );

		foreach ( $breakdown as &$item ) {
			$item['count'] = (int) $item['count'];
			$item['percentage'] = $total > 0 ? round( $item['count'] * 100 / $total, 1 ) : 0;
		}

		return array(
			'breakdown' => $breakdown,
			'total'     => $total,
		);
	}

	/**
	 * Get heatmap summary (click activity).
	 *
	 * @return array
	 */
	private function get_heatmap_summary() {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		// Total clicks
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$total_clicks = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->prefix}optibehavior_events e
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON e.session_id = s.id
				WHERE e.insert_at BETWEEN %s AND %s
				AND e.event IN (1, 2)
				{$session_spam_sql}",
				$start,
				$end
			)
		);

		// Top clicked pages
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$top_clicked = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.title,
					p.url,
					COUNT(e.id) as clicks
				FROM {$wpdb->prefix}optibehavior_events e
				INNER JOIN {$wpdb->prefix}optibehavior_pages p ON e.page_id2 = p.id
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON e.session_id = s.id
				WHERE e.insert_at BETWEEN %s AND %s
				AND e.event IN (1, 2)
				{$session_spam_sql}
				GROUP BY p.id
				ORDER BY clicks DESC
				LIMIT 5",
				$start,
				$end
			),
			ARRAY_A
		);

		return array(
			'total_clicks'   => (int) $total_clicks,
			'top_clicked'    => $top_clicked,
			'pages_tracked'  => count( $top_clicked ),
		);
	}

	/**
	 * Get funnel data.
	 *
	 * @return array
	 */
	private function get_funnel_data() {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end   = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		$table_funnels  = $wpdb->prefix . 'opti_behavior_funnels';
		$table_tracking = $wpdb->prefix . 'opti_behavior_funnel_tracking';

		// Check if tables exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_funnels )
		);
		if ( ! $table_exists ) {
			return array(
				'total_funnels' => 0,
				'funnels'       => array(),
			);
		}

		// Get active funnels with tracking stats for the period.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$funnels = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					f.id,
					f.name,
					COUNT(ft.id) as total_entries,
					SUM(CASE WHEN ft.completed = 1 THEN 1 ELSE 0 END) as completions,
					ROUND(
						SUM(CASE WHEN ft.completed = 1 THEN 1 ELSE 0 END) * 100.0
						/ NULLIF(COUNT(ft.id), 0), 1
					) as conversion_rate
				FROM {$table_funnels} f
				LEFT JOIN {$table_tracking} ft
					ON f.id = ft.funnel_id
					AND ft.entry_time BETWEEN %s AND %s
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON ft.session_id = s.id
				WHERE f.status = 'active'
				{$session_spam_sql}
				GROUP BY f.id, f.name
				ORDER BY total_entries DESC
				LIMIT 5",
				$start,
				$end
			),
			ARRAY_A
		);

		// Count total active funnels.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$total_funnels = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table_funnels} WHERE status = 'active'"
		);

		foreach ( $funnels as &$funnel ) {
			$funnel['total_entries']   = (int) $funnel['total_entries'];
			$funnel['completions']     = (int) $funnel['completions'];
			$funnel['conversion_rate'] = (float) ( $funnel['conversion_rate'] ?? 0 );
		}

		return array(
			'total_funnels' => $total_funnels,
			'funnels'       => $funnels,
		);
	}

	/**
	 * Get geographic distribution data.
	 *
	 * @param int $limit Number of countries to return.
	 * @return array
	 */
	private function get_geographic_data( $limit = 10 ) {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$countries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					v.country,
					v.country_name,
					COUNT(DISTINCT s.id) as sessions,
					COUNT(DISTINCT s.visitor_id) as visitors
				FROM {$wpdb->prefix}optibehavior_sessions s
				INNER JOIN {$wpdb->prefix}optibehavior_visitors v ON s.visitor_id = v.id
				WHERE s.start_time BETWEEN %s AND %s
				{$session_spam_sql}
				AND v.country IS NOT NULL
				GROUP BY v.country, v.country_name
				ORDER BY sessions DESC
				LIMIT %d",
				$start,
				$end,
				$limit
			),
			ARRAY_A
		);

		foreach ( $countries as &$country ) {
			$country['sessions'] = (int) $country['sessions'];
			$country['visitors'] = (int) $country['visitors'];
		}

		return $countries;
	}

	/**
	 * Get session recordings stats (Pro only).
	 *
	 * @return array
	 */
	private function get_recordings_stats() {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total,
					AVG(duration) as avg_duration,
					SUM(watched = 1) as watched,
					AVG(page_count) as avg_pages
				FROM {$wpdb->prefix}optibehavior_recordings r
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON r.session_id = s.id
				WHERE r.start_time BETWEEN %s AND %s
				{$session_spam_sql}",
				$start,
				$end
			),
			ARRAY_A
		);

		return array(
			'total'               => (int) ( $stats['total'] ?? 0 ),
			'avg_duration'        => (int) ( $stats['avg_duration'] ?? 0 ),
			'avg_duration_formatted' => $this->format_duration( $stats['avg_duration'] ?? 0 ),
			'watched'             => (int) ( $stats['watched'] ?? 0 ),
			'unwatched'           => (int) ( ( $stats['total'] ?? 0 ) - ( $stats['watched'] ?? 0 ) ),
			'avg_pages_per_session' => round( (float) ( $stats['avg_pages'] ?? 0 ), 1 ),
		);
	}

	/**
	 * Get errors data (Pro only).
	 *
	 * @return array
	 */
	private function get_errors_data() {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->prefix}optibehavior_errors e
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON e.session_id = s.id
				WHERE e.occurred_at BETWEEN %s AND %s
				{$session_spam_sql}",
				$start,
				$end
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$by_type = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					error_type,
					COUNT(*) as count
				FROM {$wpdb->prefix}optibehavior_errors e
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON e.session_id = s.id
				WHERE e.occurred_at BETWEEN %s AND %s
				{$session_spam_sql}
				GROUP BY error_type
				ORDER BY count DESC
				LIMIT 5",
				$start,
				$end
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$unresolved = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->prefix}optibehavior_errors e
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON e.session_id = s.id
				WHERE e.occurred_at BETWEEN %s AND %s
				{$session_spam_sql}
				AND e.resolved = 0",
				$start,
				$end
			)
		);

		return array(
			'total'      => (int) $total,
			'unresolved' => (int) $unresolved,
			'by_type'    => $by_type,
		);
	}

	/**
	 * Get friction events data (Pro only).
	 *
	 * @return array
	 */
	private function get_friction_data() {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total,
					SUM(friction_type = 'rage_click') as rage_clicks,
					SUM(friction_type = 'dead_click') as dead_clicks
				FROM {$wpdb->prefix}optibehavior_friction f
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON f.session_id = s.id
				WHERE f.occurred_at BETWEEN %s AND %s
				{$session_spam_sql}",
				$start,
				$end
			),
			ARRAY_A
		);

		// Top friction elements
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$top_elements = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					element_selector,
					friction_type,
					COUNT(*) as count
				FROM {$wpdb->prefix}optibehavior_friction f
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON f.session_id = s.id
				WHERE f.occurred_at BETWEEN %s AND %s
				{$session_spam_sql}
				GROUP BY element_selector, friction_type
				ORDER BY count DESC
				LIMIT 5",
				$start,
				$end
			),
			ARRAY_A
		);

		return array(
			'total'        => (int) ( $stats['total'] ?? 0 ),
			'rage_clicks'  => (int) ( $stats['rage_clicks'] ?? 0 ),
			'dead_clicks'  => (int) ( $stats['dead_clicks'] ?? 0 ),
			'top_elements' => $top_elements,
		);
	}

	/**
	 * Get performance metrics data (Pro only).
	 *
	 * @return array
	 */
	private function get_performance_data() {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$metrics = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					AVG(lcp) as avg_lcp,
					AVG(fid) as avg_fid,
					AVG(cls) as avg_cls,
					AVG(fcp) as avg_fcp,
					AVG(inp) as avg_inp,
					AVG(load_complete) as avg_load_time,
					SUM(is_slow = 1) * 100.0 / NULLIF(COUNT(*), 0) as slow_percentage
				FROM {$wpdb->prefix}optibehavior_performance
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON {$wpdb->prefix}optibehavior_performance.session_id = s.id
				WHERE measured_at BETWEEN %s AND %s
				{$session_spam_sql}",
				$start,
				$end
			),
			ARRAY_A
		);

		return array(
			'lcp'             => round( (float) ( $metrics['avg_lcp'] ?? 0 ) / 1000, 2 ), // Convert to seconds
			'fid'             => round( (float) ( $metrics['avg_fid'] ?? 0 ), 0 ),
			'cls'             => round( (float) ( $metrics['avg_cls'] ?? 0 ), 3 ),
			'fcp'             => round( (float) ( $metrics['avg_fcp'] ?? 0 ) / 1000, 2 ),
			'inp'             => round( (float) ( $metrics['avg_inp'] ?? 0 ), 0 ),
			'avg_load_time'   => round( (float) ( $metrics['avg_load_time'] ?? 0 ) / 1000, 2 ),
			'slow_percentage' => round( (float) ( $metrics['slow_percentage'] ?? 0 ), 1 ),
		);
	}

	/**
	 * Get broken links data (Pro only).
	 *
	 * @return array
	 */
	private function get_broken_links_data() {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end = $this->period_end->format( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->prefix}optibehavior_broken_links
				WHERE last_detected BETWEEN %s AND %s",
				$start,
				$end
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$open = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->prefix}optibehavior_broken_links
				WHERE last_detected BETWEEN %s AND %s
				AND status = 'open'",
				$start,
				$end
			)
		);

		// Top broken links
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$top_links = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					url,
					source_url,
					occurrence_count,
					http_status
				FROM {$wpdb->prefix}optibehavior_broken_links
				WHERE last_detected BETWEEN %s AND %s
				ORDER BY occurrence_count DESC
				LIMIT 5",
				$start,
				$end
			),
			ARRAY_A
		);

		return array(
			'total'     => (int) $total,
			'open'      => (int) $open,
			'fixed'     => (int) $total - (int) $open,
			'top_links' => $top_links,
		);
	}

	/**
	 * Get user journeys data (Pro only).
	 *
	 * @return array
	 */
	private function get_user_journeys_data() {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end   = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		$sp  = $wpdb->prefix . 'optibehavior_session_pages';
		$ses = $wpdb->prefix . 'optibehavior_sessions';

		// Total sessions and avg path length.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(DISTINCT s.id) as total_sessions,
					AVG(pc.page_count) as avg_path_length
				FROM {$ses} s
				LEFT JOIN (
					SELECT session_id, COUNT(*) as page_count
					FROM {$sp}
					GROUP BY session_id
				) pc ON s.id = pc.session_id
				WHERE s.start_time BETWEEN %s AND %s
				{$session_spam_sql}",
				$start,
				$end
			),
			ARRAY_A
		);

		$total_sessions = (int) ( $stats['total_sessions'] ?? 0 );

		// Top 5 entry pages.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$entry_pages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sp.url, COUNT(DISTINCT sp.session_id) as sessions
				FROM {$sp} sp
				INNER JOIN {$ses} s ON sp.session_id = s.id
				WHERE sp.page_order = 1
				AND s.start_time BETWEEN %s AND %s
				{$session_spam_sql}
				GROUP BY sp.url
				ORDER BY sessions DESC
				LIMIT 5",
				$start,
				$end
			),
			ARRAY_A
		);

		// Top 5 exit pages.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$exit_pages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sp.url, COUNT(*) as sessions
				FROM {$sp} sp
				INNER JOIN (
					SELECT session_id, MAX(page_order) as max_order
					FROM {$sp}
					GROUP BY session_id
				) lp ON sp.session_id = lp.session_id AND sp.page_order = lp.max_order
				INNER JOIN {$ses} s ON sp.session_id = s.id
				WHERE s.start_time BETWEEN %s AND %s
				{$session_spam_sql}
				GROUP BY sp.url
				ORDER BY sessions DESC
				LIMIT 5",
				$start,
				$end
			),
			ARRAY_A
		);

		foreach ( $exit_pages as &$page ) {
			$page['sessions']  = (int) $page['sessions'];
			$page['exit_rate'] = $total_sessions > 0
				? round( $page['sessions'] / $total_sessions * 100, 1 )
				: 0;
		}

		// Depth distribution.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$depth = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN pc.page_count = 1 THEN '1_page'
						WHEN pc.page_count BETWEEN 2 AND 3 THEN '2_3_pages'
						ELSE '4_plus_pages'
					END as depth_bucket,
					COUNT(*) as session_count
				FROM {$ses} s
				INNER JOIN (
					SELECT session_id, COUNT(*) as page_count
					FROM {$sp}
					GROUP BY session_id
				) pc ON s.id = pc.session_id
				WHERE s.start_time BETWEEN %s AND %s
				{$session_spam_sql}
				GROUP BY depth_bucket",
				$start,
				$end
			),
			ARRAY_A
		);

		$depth_map = array();
		foreach ( $depth as $row ) {
			$depth_map[ $row['depth_bucket'] ] = (int) $row['session_count'];
		}

		// Shorten URLs for display.
		foreach ( $entry_pages as &$page ) {
			$page['sessions'] = (int) $page['sessions'];
			$page['name']     = $this->shorten_url( $page['url'] );
		}
		foreach ( $exit_pages as &$page ) {
			$page['name'] = $this->shorten_url( $page['url'] );
		}

		return array(
			'total_sessions'  => $total_sessions,
			'avg_path_length' => round( (float) ( $stats['avg_path_length'] ?? 0 ), 1 ),
			'entry_pages'     => $entry_pages,
			'exit_pages'      => $exit_pages,
			'depth'           => array(
				'1_page'       => $depth_map['1_page'] ?? 0,
				'2_3_pages'    => $depth_map['2_3_pages'] ?? 0,
				'4_plus_pages' => $depth_map['4_plus_pages'] ?? 0,
			),
		);
	}

	/**
	 * Get form analytics data (Pro only).
	 *
	 * @return array
	 */
	private function get_form_analytics_data() {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end   = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		$table = $wpdb->prefix . 'optibehavior_form_submissions';

		// Check if table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
		if ( ! $table_exists ) {
			return array();
		}

		// Overall KPIs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$kpis = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) as form_views,
					SUM(CASE WHEN was_submitted = 1 THEN 1 ELSE 0 END) as submissions,
					SUM(CASE WHEN was_abandoned = 1 THEN 1 ELSE 0 END) as abandonments,
					ROUND( SUM(CASE WHEN was_submitted = 1 THEN 1 ELSE 0 END) * 100.0
						/ NULLIF(COUNT(*), 0), 1 ) as conversion_rate,
					ROUND( AVG(CASE WHEN was_submitted = 1 AND time_to_complete > 0
						THEN time_to_complete ELSE NULL END), 0 ) as avg_completion_time
				FROM {$table} f
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON f.session_id = s.id
				WHERE f.created_at BETWEEN %s AND %s
				{$session_spam_sql}",
				$start,
				$end
			),
			ARRAY_A
		);

		// Top 5 forms by conversion rate (min 5 views).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$top_forms = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					form_id,
					MAX(form_name) as form_name,
					MAX(form_plugin) as form_plugin,
					COUNT(*) as form_views,
					SUM(CASE WHEN was_submitted = 1 THEN 1 ELSE 0 END) as submissions,
					ROUND( SUM(CASE WHEN was_submitted = 1 THEN 1 ELSE 0 END) * 100.0
						/ NULLIF(COUNT(*), 0), 1 ) as conversion_rate
				FROM {$table} f
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON f.session_id = s.id
				WHERE f.created_at BETWEEN %s AND %s
				{$session_spam_sql}
				AND form_id != ''
				GROUP BY form_id
				HAVING COUNT(*) >= 5
				ORDER BY conversion_rate DESC, form_views DESC
				LIMIT 5",
				$start,
				$end
			),
			ARRAY_A
		);

		$avg_ct = (int) ( $kpis['avg_completion_time'] ?? 0 );

		return array(
			'form_views'                    => (int) ( $kpis['form_views'] ?? 0 ),
			'submissions'                   => (int) ( $kpis['submissions'] ?? 0 ),
			'abandonments'                  => (int) ( $kpis['abandonments'] ?? 0 ),
			'conversion_rate'               => (float) ( $kpis['conversion_rate'] ?? 0 ),
			'avg_completion_time'           => $avg_ct,
			'avg_completion_time_formatted' => $avg_ct > 0 ? $this->format_duration( $avg_ct ) : '—',
			'top_forms'                     => $top_forms ?: array(),
		);
	}

	/**
	 * Get Smart Insights data for the report period.
	 *
	 * @return array
	 */
	private function get_smart_insights_data() {
		$start_date   = $this->period_start->format( 'Y-m-d' );
		$end_date     = $this->period_end->format( 'Y-m-d' );
		$exclude_spam = $this->should_exclude_spam();
		$is_test      = ! empty( $this->schedule['is_test_report'] );

		$data = array(
			'count'         => 0,
			'insights'      => array(),
			'period_start'  => $start_date,
			'period_end'    => $end_date,
			'spam_excluded' => $exclude_spam,
			'generated'     => null,
			'empty_message' => __( 'No active Smart Insights detected for this report period.', 'opti-behavior' ),
		);

		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Repository' ) ) {
			return $data;
		}

		$refresh = ! $is_test && apply_filters( 'opti_behavior_scheduled_report_refresh_smart_insights', true, $this->schedule, $data );
		if ( $refresh && class_exists( 'Opti_Behavior_Smart_Insights_Generator' ) ) {
			$generator = new Opti_Behavior_Smart_Insights_Generator();
			$generated = $generator->generate_for_period(
				$start_date,
				$end_date,
				array(
					'source'          => 'scheduled_report',
					'exclude_spam'    => $exclude_spam,
					'candidate_limit' => 25,
					'force'           => true,
				)
			);

			if ( ! is_wp_error( $generated ) && is_array( $generated ) ) {
				$data['generated'] = $generated;
			}
		}

		$repository = new Opti_Behavior_Smart_Insights_Repository();
		$insights   = method_exists( $repository, 'get_top_insights' )
			? $repository->get_top_insights(
				array(
					'limit'             => 5,
					'date_overlap_from' => $start_date,
					'date_overlap_to'   => $end_date,
					'spam_scope'        => $exclude_spam,
				)
			)
			: array();

		if ( class_exists( 'Opti_Behavior_Smart_Insights_Capabilities' ) ) {
			$capabilities = new Opti_Behavior_Smart_Insights_Capabilities( $this->is_pro );
			$insights     = $capabilities->filter_insights_for_viewer( $insights, 'scheduled_report' );
		}

		$formatted = array();
		foreach ( array_slice( (array) $insights, 0, 5 ) as $insight ) {
			if ( is_array( $insight ) ) {
				$formatted[] = $this->format_smart_insight_for_report( $insight );
			}
		}

		$data['count']    = count( $formatted );
		$data['insights'] = $formatted;

		return $data;
	}

	/**
	 * Format one Smart Insight for compact email rendering.
	 *
	 * @param array $insight Decoded insight payload.
	 * @return array
	 */
	private function format_smart_insight_for_report( $insight ) {
		$title = $this->get_first_non_empty( $insight, array( 'title', 'headline', 'signal_label', 'signal_id' ), __( 'Smart Insight', 'opti-behavior' ) );
		$entity_label = $this->get_first_non_empty( $insight, array( 'entity_label', 'entity_name', 'entity_url', 'entity_id' ), '' );

		$scores     = isset( $insight['scores'] ) && is_array( $insight['scores'] ) ? $insight['scores'] : array();
		$priority   = $this->get_first_non_empty( $insight, array( 'priority', 'severity' ), '' );
		$confidence = isset( $scores['confidence'] ) ? (float) $scores['confidence'] : ( isset( $insight['confidence'] ) ? (float) $insight['confidence'] : 0 );
		if ( '' === $priority && isset( $scores['priority_score'] ) ) {
			$priority = (float) $scores['priority_score'] >= 70 ? 'high' : ( (float) $scores['priority_score'] >= 40 ? 'medium' : 'low' );
		}

		$actions = isset( $insight['recommended_actions'] ) && is_array( $insight['recommended_actions'] ) ? $insight['recommended_actions'] : array();
		$action  = '';
		if ( ! empty( $actions ) ) {
			$first_action = reset( $actions );
			if ( is_array( $first_action ) ) {
				$action = $this->get_first_non_empty( $first_action, array( 'title', 'label', 'description', 'action' ), '' );
			} elseif ( is_scalar( $first_action ) ) {
				$action = (string) $first_action;
			}
		}

		return array(
			'title'              => $title,
			'entity_label'       => $entity_label,
			'priority'           => $priority ? ucfirst( sanitize_text_field( $priority ) ) : __( 'Normal', 'opti-behavior' ),
			'confidence'         => $confidence > 0 ? round( $confidence * ( $confidence <= 1 ? 100 : 1 ) ) : 0,
			'interpretation'     => $this->get_first_non_empty( $insight, array( 'interpretation', 'why_it_matters', 'summary', 'description' ), '' ),
			'evidence'           => $this->build_smart_insight_evidence( $insight ),
			'recommended_action' => $action,
			'updated_at'         => isset( $insight['updated_at'] ) ? $insight['updated_at'] : '',
		);
	}

	/**
	 * Build compact evidence lines from insight metrics and detection payloads.
	 *
	 * @param array $insight Insight payload.
	 * @return array
	 */
	private function build_smart_insight_evidence( $insight ) {
		$evidence = array();
		foreach ( array( 'metrics', 'detection' ) as $field ) {
			$values = isset( $insight[ $field ] ) && is_array( $insight[ $field ] ) ? $insight[ $field ] : array();
			foreach ( $values as $key => $value ) {
				if ( count( $evidence ) >= 3 || is_array( $value ) || is_object( $value ) || '' === (string) $value ) {
					continue;
				}

				$evidence[] = sprintf( '%1$s: %2$s', $this->humanize_key( $key ), $this->format_metric_value( $value ) );
			}
		}

		return $evidence;
	}

	/**
	 * Get first non-empty scalar from an array.
	 *
	 * @param array  $values   Values.
	 * @param array  $keys     Candidate keys.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private function get_first_non_empty( $values, $keys, $fallback = '' ) {
		foreach ( $keys as $key ) {
			if ( isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) && '' !== (string) $values[ $key ] ) {
				return (string) $values[ $key ];
			}
		}

		return $fallback;
	}

	/**
	 * Humanize a metric key for email.
	 *
	 * @param string $key Metric key.
	 * @return string
	 */
	private function humanize_key( $key ) {
		return ucwords( str_replace( '_', ' ', sanitize_key( $key ) ) );
	}

	/**
	 * Format a metric value for email.
	 *
	 * @param mixed $value Metric value.
	 * @return string
	 */
	private function format_metric_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'opti-behavior' ) : __( 'No', 'opti-behavior' );
		}

		if ( is_numeric( $value ) ) {
			return (string) round( (float) $value, 2 );
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Shorten a URL to just the path for display.
	 *
	 * @param string $url Full URL.
	 * @return string Shortened path or 'Home'.
	 */
	private function shorten_url( $url ) {
		$parsed = wp_parse_url( $url );
		$path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
		if ( strlen( $path ) > 40 ) {
			$path = '...' . substr( $path, -37 );
		}
		return '/' === $path ? 'Home' : $path;
	}

	/**
	 * Calculate percentage change between two values.
	 *
	 * @param float $current  Current value.
	 * @param float $previous Previous value.
	 * @param bool  $inverse  Whether lower is better.
	 * @return array
	 */
	private function calculate_change( $current, $previous, $inverse = false ) {
		$current = (float) $current;
		$previous = (float) $previous;

		if ( 0.0 === $previous ) {
			return array(
				'value'     => $current > 0 ? 100 : 0,
				'direction' => $current > 0 ? 'up' : 'neutral',
				'positive'  => $current > 0 ? ! $inverse : null,
			);
		}

		$change = ( ( $current - $previous ) / $previous ) * 100;
		$direction = $change > 0 ? 'up' : ( $change < 0 ? 'down' : 'neutral' );

		// Determine if change is positive (good)
		$positive = null;
		if ( abs( $change ) > 0.5 ) {
			$positive = $inverse ? $change < 0 : $change > 0;
		}

		return array(
			'value'     => round( abs( $change ), 1 ),
			'direction' => $direction,
			'positive'  => $positive,
		);
	}

	/**
	 * Format duration in seconds to human-readable string.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string
	 */
	private function format_duration( $seconds ) {
		$seconds = (int) $seconds;

		if ( $seconds < 60 ) {
			return sprintf( '%ds', $seconds );
		}

		$minutes = floor( $seconds / 60 );
		$remaining_seconds = $seconds % 60;

		if ( $minutes < 60 ) {
			return sprintf( '%dm %ds', $minutes, $remaining_seconds );
		}

		$hours = floor( $minutes / 60 );
		$remaining_minutes = $minutes % 60;

		return sprintf( '%dh %dm', $hours, $remaining_minutes );
	}

	/**
	 * Get available report sections based on Pro status.
	 *
	 * @return array
	 */
	public function get_available_sections() {
		$sections = array(
			'kpis'              => array(
				'label'   => __( 'KPI Summary', 'opti-behavior' ),
				'pro'     => false,
				'default' => true,
			),
			'smart_insights'    => array(
				'label'   => __( 'Smart Insights', 'opti-behavior' ),
				'pro'     => false,
				'default' => true,
			),
			'top_pages'         => array(
				'label'   => __( 'Top Pages', 'opti-behavior' ),
				'pro'     => false,
				'default' => true,
			),
			'top_referrers'     => array(
				'label'   => __( 'Top Referrers', 'opti-behavior' ),
				'pro'     => false,
				'default' => true,
			),
			'traffic_breakdown' => array(
				'label'   => __( 'Traffic Breakdown', 'opti-behavior' ),
				'pro'     => false,
				'default' => true,
			),
			'heatmap_summary'   => array(
				'label'   => __( 'Click Heatmap Summary', 'opti-behavior' ),
				'pro'     => false,
				'default' => true,
			),
			'funnels'           => array(
				'label'   => __( 'Funnel Performance', 'opti-behavior' ),
				'pro'     => false,
				'default' => true,
			),
			'geographic'        => array(
				'label'   => __( 'Geographic Distribution', 'opti-behavior' ),
				'pro'     => false,
				'default' => true,
			),
			'recordings_stats'  => array(
				'label'   => __( 'Session Recording Stats', 'opti-behavior' ),
				'pro'     => true,
				'default' => $this->is_pro,
			),
			'errors'            => array(
				'label'   => __( 'JavaScript Errors Summary', 'opti-behavior' ),
				'pro'     => true,
				'default' => $this->is_pro,
			),
			'friction'          => array(
				'label'   => __( 'Friction Events (Rage Clicks)', 'opti-behavior' ),
				'pro'     => true,
				'default' => $this->is_pro,
			),
			'performance'       => array(
				'label'   => __( 'Performance Metrics (Web Vitals)', 'opti-behavior' ),
				'pro'     => true,
				'default' => $this->is_pro,
			),
			'broken_links'      => array(
				'label'   => __( 'Broken Links Report', 'opti-behavior' ),
				'pro'     => true,
				'default' => $this->is_pro,
			),
			'user_journeys'     => array(
				'label'   => __( 'User Journeys', 'opti-behavior' ),
				'pro'     => true,
				'default' => $this->is_pro,
			),
			'form_analytics'    => array(
				'label'   => __( 'Form Analytics', 'opti-behavior' ),
				'pro'     => true,
				'default' => $this->is_pro,
			),
		);

		return $sections;
	}
}
