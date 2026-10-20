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
			'privacy_mode' => $this->get_privacy_mode(),
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

		// Shop block: WooCommerce + shop counting on, something counted in the period (OPT-1735).
		$shop = $this->get_shop_block();
		if ( ! empty( $shop ) ) {
			$report['sections']['shop'] = $shop;
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

		// Short version of the PDF site report: its figures, its 3 costly issues (OPT-1553).
		// Last: the Smart Insights cards are refreshed above, the Pro sections are reused.
		$site = $this->get_site_summary( $report );
		if ( null !== $site ) {
			$report['site'] = $site;
		}

		return $report;
	}

	/**
	 * The "Shop" block (OPT-1735, replaces the OPT-1516 line: no separate shop
	 * e-mail any more). Built when WooCommerce is active and shop counting is on
	 * (Opti_Behavior_Shop_Report::enabled()), for THIS report's period compared
	 * with the period before; nothing counted = no block. Totals and product
	 * names only. Pro "Money at stake" = filter `opti_behavior_shop_report_pro`
	 * with this period; Free alone = the grey Pro line.
	 *
	 * @return array period_report() + { pro (array|null), pro_line (bool) } or empty.
	 */
	public function get_shop_block() {
		if ( ! class_exists( 'Opti_Behavior_Shop_Report' ) || ! method_exists( 'Opti_Behavior_Shop_Report', 'period_report' ) ) {
			return array();
		}
		$shop = Opti_Behavior_Shop_Report::period_report( $this->period_start->format( 'Y-m-d' ), $this->period_end->format( 'Y-m-d' ) );
		if ( null === $shop ) {
			return array();
		}
		$shop['pro']      = Opti_Behavior_Shop_Report::pro_part( $shop );
		$shop['pro_line'] = null === $shop['pro'] && ! defined( 'OPTI_BEHAVIOR_PRO_VERSION' );
		return $shop;
	}

	/**
	 * The e-mail as a short version of the PDF site report (OPT-1553): the
	 * context of Opti_Behavior_Report_Site for the days of this report (same
	 * call as the PDF, so the same figures), `report_url` = the Reports screen
	 * for these days, and for Pro the 3 most visited pages with their
	 * "Problem #1" (filter `opti_behavior_report_email_pro`) and one line of
	 * Pro figures of the period.
	 *
	 * @param array $report Report built so far (Pro sections reused when present).
	 * @return array|null Null when the PDF data cannot be read (the e-mail keeps its old blocks).
	 */
	public function get_site_summary( $report = array() ) {
		if ( ! class_exists( 'Opti_Behavior_Report_Site' ) || ! method_exists( 'Opti_Behavior_Report_Site', 'email_summary' ) ) {
			return null;
		}
		$from    = $this->period_start->format( 'Y-m-d' );
		$to      = $this->period_end->format( 'Y-m-d' );
		$spam    = $this->should_exclude_spam();
		$summary = Opti_Behavior_Report_Site::email_summary( Opti_Behavior_Report_Site::email_context( $from, $to, $spam ) );
		if ( ! is_array( $summary ) ) {
			return null;
		}
		$summary['report_url'] = class_exists( 'Opti_Behavior_Reports_Screen' )
			? Opti_Behavior_Reports_Screen::screen_url( '', $from, $to, $spam )
			: admin_url( 'admin.php?page=opti-behavior-reports' );
		$summary['pro'] = null;

		// One short block per signal of the menu (OPT-1596), same rows as above.
		$summary['signals'] = array();
		if ( $summary['visits'] > 0 && class_exists( 'Opti_Behavior_Report_Signals' ) ) {
			try {
				$sources            = $this->signal_sources( $from, $to );
				$sources['site']    = $summary;
				$summary['signals'] = Opti_Behavior_Report_Signals::blocks( $from, $to, $spam, $this->is_pro, $sources );
			} catch ( Throwable $e ) {
				$summary['signals'] = array();
			}
		}

		if ( $this->is_pro && $summary['visits'] > 0 ) {
			/**
			 * Pro block of the scheduled report e-mail (OPT-1553).
			 *
			 * @param null|array $pro     Null = no Pro block. Answer: { pages: [ { title, path, visits, problem } ] }.
			 * @param array      $summary E-mail summary of the site report.
			 * @param array      $args    { period '', from, to, exclude_spam }.
			 */
			$pro   = apply_filters(
				'opti_behavior_report_email_pro',
				null,
				$summary,
				array(
					'period'       => '',
					'from'         => $from,
					'to'           => $to,
					'exclude_spam' => $spam,
				)
			);
			$pages = array();
			if ( is_array( $pro ) && ! empty( $pro['pages'] ) && is_array( $pro['pages'] ) ) {
				foreach ( array_slice( array_values( $pro['pages'] ), 0, 3 ) as $page ) {
					if ( ! is_array( $page ) ) {
						continue;
					}
					$pages[] = array(
						'title'   => isset( $page['title'] ) ? sanitize_text_field( (string) $page['title'] ) : '',
						'path'    => isset( $page['path'] ) ? sanitize_text_field( (string) $page['path'] ) : '',
						'visits'  => isset( $page['visits'] ) ? absint( $page['visits'] ) : 0,
						'problem' => isset( $page['problem'] ) ? sanitize_text_field( (string) $page['problem'] ) : '',
					);
				}
			}
			$summary['pro'] = array(
				'pages'   => $pages,
				'figures' => $this->get_pro_figures( $report ),
			);
		}

		return $summary;
	}

	/**
	 * Data of the report signal blocks for given days (OPT-1596): the click
	 * block and the funnels block of this e-mail, read for $from..$to.
	 *
	 * @param string $from First day (Y-m-d).
	 * @param string $to   Last day (Y-m-d).
	 * @return array { heatmap, funnels, ab_tests }
	 */
	public function signal_sources( $from, $to ) {
		$tz         = wp_timezone();
		$keep_start = $this->period_start;
		$keep_end   = $this->period_end;

		$this->period_start = new DateTime( $from . ' 00:00:00', $tz );
		$this->period_end   = new DateTime( $to . ' 23:59:59', $tz );
		try {
			$out = array(
				'heatmap'  => $this->get_heatmap_summary(),
				'funnels'  => $this->get_funnel_data(),
				'ab_tests' => $this->get_ab_test_rows(),
			);
		} finally {
			$this->period_start = $keep_start;
			$this->period_end   = $keep_end;
		}
		return $out;
	}

	/**
	 * A/B tests of the period (OPT-1650): running now and started by the last
	 * day, or ended (completed / applied) during the period, with the stored
	 * winner variant name. Rows of the A/B Testing screen, nothing computed.
	 *
	 * @return array[] { name, status, ended_at, winner } (no table = empty).
	 */
	private function get_ab_test_rows() {
		global $wpdb;
		$tests    = $wpdb->prefix . 'optibehavior_ab_tests';
		$variants = $wpdb->prefix . 'optibehavior_ab_variants';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tests ) ) ) !== $tests ) {
			return array();
		}
		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end   = $this->period_end->format( 'Y-m-d H:i:s' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.name, t.status, t.ended_at, v.name AS winner
				FROM {$tests} t
				LEFT JOIN {$variants} v ON v.id = t.winner_variant_id AND v.test_id = t.id
				WHERE ( t.status = 'running' AND t.started_at IS NOT NULL AND t.started_at <= %s )
					OR ( t.status IN ( 'completed', 'applied' ) AND t.ended_at BETWEEN %s AND %s )
				ORDER BY t.ended_at DESC, t.id DESC
				LIMIT 200",
				$end,
				$start,
				$end
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Pro figures of the period for the e-mail line (OPT-1553): recordings,
	 * abandoned forms, JavaScript errors (the "JS error" rule of the
	 * recordings filter: network / resource / HTTP errors left out). A figure
	 * that cannot be read is left out.
	 *
	 * @param array $report Report built so far.
	 * @return array { recordings?, forms_abandoned?, js_errors? }
	 */
	private function get_pro_figures( $report ) {
		global $wpdb;
		$out = array();
		try {
			$rec               = isset( $report['sections']['recordings']['total'] ) ? $report['sections']['recordings'] : $this->get_recordings_stats();
			$out['recordings'] = (int) $rec['total'];
		} catch ( Throwable $e ) {
			unset( $out['recordings'] );
		}
		try {
			$forms = isset( $report['sections']['form_analytics']['abandonments'] ) ? $report['sections']['form_analytics'] : $this->get_form_analytics_data();
			if ( isset( $forms['abandonments'] ) ) {
				$out['forms_abandoned'] = (int) $forms['abandonments'];
			}
		} catch ( Throwable $e ) {
			unset( $out['forms_abandoned'] );
		}
		$types = class_exists( 'Opti_Behavior_Session_Recording' ) && defined( 'Opti_Behavior_Session_Recording::NON_JS_ERROR_TYPES' )
			? (array) Opti_Behavior_Session_Recording::NON_JS_ERROR_TYPES
			: array( 'network_error', 'resource_load_error', 'resource_error', 'broken_link', '404', 'api_error', 'timeout_error', 'cors_error' );
		$in    = "'" . implode( "', '", array_map( 'esc_sql', $types ) ) . "'";
		$spam  = $this->session_spam_sql( 's' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; fixed type list escaped above.
		$js = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->prefix}optibehavior_errors e
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON e.session_id = s.id
				WHERE e.occurred_at BETWEEN %s AND %s
				AND COALESCE(e.error_type, '') NOT IN ({$in})
				AND LEFT(COALESCE(e.error_type, ''), 5) != 'http_'
				{$spam}",
				$this->period_start->format( 'Y-m-d H:i:s' ),
				$this->period_end->format( 'Y-m-d H:i:s' )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( null !== $js ) {
			$out['js_errors'] = (int) $js;
		}

		return $out;
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
	 * Tracking privacy mode of the site ('full' or 'anonymous', the default).
	 *
	 * In Cookieless (anonymous) mode the visitor id is a daily hash, so a
	 * visitor who comes back on another day counts again (OPT-1511).
	 *
	 * @return string
	 */
	private function get_privacy_mode() {
		$options = ( $this->core && method_exists( $this->core, 'get_options' ) ) ? $this->core->get_options() : array();
		$mode    = isset( $options['privacy_mode'] ) ? $options['privacy_mode'] : 'anonymous';
		return ( 'full' === $mode ) ? 'full' : 'anonymous';
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
					COUNT(DISTINCT NULLIF(s.visitor_id, '')) as visitors,
					COUNT(*) as sessions,
					SUM(s.page_views) as pageviews,
					AVG(" . opti_behavior_capped_seconds_sql( 's.duration', 'session' ) . ") as avg_duration,
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
					COUNT(DISTINCT NULLIF(s.visitor_id, '')) as visitors,
					COUNT(*) as sessions,
					SUM(s.page_views) as pageviews,
					AVG(" . opti_behavior_capped_seconds_sql( 's.duration', 'session' ) . ") as avg_duration,
					SUM(CASE WHEN s.is_bounce = 1 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0) as bounce_rate
				FROM {$wpdb->prefix}optibehavior_sessions s
				WHERE s.start_time BETWEEN %s AND %s
				{$session_spam_sql}",
				$prev_start,
				$prev_end
			),
			ARRAY_A
		);

		// Average scroll depth = the Traffic KPI card (OPT-1549): page views
		// with a scroll only, plus the daily_stats rollup of pre-raw days.
		$scroll      = $this->get_screen_scroll_depth( $start, $end );
		$prev_scroll = $this->get_screen_scroll_depth( $prev_start, $prev_end );
		// Rates: no base = no session before (a rollup scroll with 0 session is
		// not one), same rule as the Traffic tiles (OPT-1626, QA OPT-1651).
		if ( (int) ( $previous['sessions'] ?? 0 ) <= 0 ) {
			$prev_scroll = 0;
		}

		$kpis = array(
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
				'change' => $this->calculate_rate_change( $current['bounce_rate'] ?? 0, $previous['bounce_rate'] ?? 0, $previous['sessions'] ?? 0, true ),
			),
			'avg_scroll_depth' => array(
				'value'  => round( (float) ( $scroll ?? 0 ), 1 ),
				'change' => $this->calculate_rate_change( $scroll ?? 0, $prev_scroll ?? 0, $previous['sessions'] ?? 0 ),
			),
		);

		// Previous period not comparable (plugin installed during it, data
		// pruned…): the Traffic tiles print "—", so no arrow here either, the
		// same grey "new" as an empty previous period (views + subject).
		if ( ! $this->is_previous_period_comparable( $prev_start, $prev_end ) ) {
			foreach ( $kpis as $metric => $kpi ) {
				$kpis[ $metric ]['change'] = array(
					'value'     => null,
					'direction' => 'new',
					'positive'  => null,
				);
			}
		}

		return $kpis;
	}

	/**
	 * Whether the previous period can be compared, through the Traffic
	 * screen's rule (data helpers is_previous_period_comparable(), OPT-1626).
	 * No dashboard object = comparable (old behaviour).
	 *
	 * @param string $prev_start Previous period start.
	 * @param string $prev_end   Previous period end.
	 * @return bool
	 */
	private function is_previous_period_comparable( $prev_start, $prev_end ) {
		$dash = class_exists( 'Opti_Behavior_Report_Site' ) ? Opti_Behavior_Report_Site::dashboard() : null;
		if ( ! is_object( $dash ) || ! method_exists( $dash, 'is_previous_period_comparable' ) ) {
			return true;
		}
		try {
			return (bool) $dash->is_previous_period_comparable( $prev_start, $prev_end );
		} catch ( \Throwable $e ) {
			return true;
		}
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
					p.id as page_id,
					p.title,
					p.url,
					COUNT(pv.id) as views,
					AVG(
						CASE
							WHEN pv.time_on_page > 0 THEN pv.time_on_page
							ELSE LEAST(1800, GREATEST(0, TIMESTAMPDIFF(SECOND, pv.view_time,
								COALESCE(
									(
										SELECT MIN(pv_next.view_time)
										FROM {$wpdb->prefix}optibehavior_pageviews pv_next
										WHERE pv_next.session_id = pv.session_id
										AND pv_next.session_id <> ''
										AND pv_next.view_time > pv.view_time
									),
									s.end_time
								)
							)))
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
				max( 200, (int) $limit )
			),
			ARRAY_A
		);
		$pages = array_slice( self::merge_pages_by_identity( (array) $pages ), 0, max( 1, (int) $limit ) );

		foreach ( $pages as &$page ) {
			$page['page_id']            = (int) ( $page['page_id'] ?? 0 );
			$page['views']              = (int) $page['views'];
			$avg_time                   = (float) ( $page['avg_time'] ?? 0 );
			$page['avg_time_formatted'] = $avg_time > 0 ? $this->format_duration( $avg_time ) : '—';
			$page['avg_scroll']         = round( (float) ( $page['avg_scroll'] ?? 0 ), 1 );
		}

		return $pages;
	}

	/**
	 * One row per page (OPT-1635): rows of page records that are the same page
	 * for `opti_behavior_page_identity_key()` (?srsltid, utm_*, www, trailing
	 * slash…) are merged like the Traffic Top Pages widget. Views summed, time
	 * and scroll weighted by views, the clean URL (no query) kept, then the
	 * busiest member. Sorted by views.
	 *
	 * @param array $rows Rows { page_id, title, url, views, avg_time, avg_scroll }.
	 * @return array
	 */
	public static function merge_pages_by_identity( array $rows ) {
		$groups = array();
		foreach ( $rows as $row ) {
			$url   = (string) ( $row['url'] ?? '' );
			$key   = function_exists( 'opti_behavior_page_identity_key' ) ? (string) opti_behavior_page_identity_key( $url ) : '';
			$key   = '' === $key ? 'id:' . (int) ( $row['page_id'] ?? 0 ) : $key;
			$views = (int) ( $row['views'] ?? 0 );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'row'    => $row,
					'rank'   => array( -1, -1 ),
					'views'  => 0,
					'time'   => 0.0,
					'time_w' => 0,
					'scroll' => 0.0,
					'scr_w'  => 0,
				);
			}
			$group           = &$groups[ $key ];
			$group['views'] += $views;
			if ( null !== ( $row['avg_time'] ?? null ) && $views > 0 ) {
				$group['time']   += (float) $row['avg_time'] * $views;
				$group['time_w'] += $views;
			}
			if ( null !== ( $row['avg_scroll'] ?? null ) && $views > 0 ) {
				$group['scroll'] += (float) $row['avg_scroll'] * $views;
				$group['scr_w']  += $views;
			}
			$rank = array( false === strpos( $url, '?' ) ? 1 : 0, $views );
			if ( $rank > $group['rank'] ) {
				$group['rank'] = $rank;
				$group['row']  = $row;
			}
			unset( $group );
		}

		$merged = array();
		foreach ( $groups as $group ) {
			$row               = $group['row'];
			$row['views']      = $group['views'];
			$row['avg_time']   = $group['time_w'] > 0 ? $group['time'] / $group['time_w'] : null;
			$row['avg_scroll'] = $group['scr_w'] > 0 ? $group['scroll'] / $group['scr_w'] : null;
			$merged[]          = $row;
		}
		usort(
			$merged,
			function ( $a, $b ) {
				return (int) $b['views'] <=> (int) $a['views'];
			}
		);
		return $merged;
	}

	/**
	 * Get top referrers data.
	 *
	 * @param int $limit Number of referrers to return.
	 * @return array
	 */
	private function get_top_referrers( $limit = 10 ) {
		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end   = $this->period_end->format( 'Y-m-d H:i:s' );

		// Rows of the Traffic screen Referrers widget (OPT-1549): visits from
		// the sessions table, one row per source name (Google, not google.com
		// twice). "Direct / None" is the Direct line of the Traffic breakdown
		// and "Unclassified" no source: both left out of this list.
		$figures = $this->get_screen_figures( $start, $end, array( 'referrers' ) );
		$rows    = is_array( $figures ) && isset( $figures['referrers'] ) ? (array) $figures['referrers'] : array();
		$skip    = array( __( 'Direct / None', 'opti-behavior' ), __( 'Unclassified', 'opti-behavior' ), 'Direct / None', 'Unclassified' );

		// Share base (OPT-1650) = every visit of the period, Direct / None and
		// Unclassified included (the widget rows add up to the sessions, like
		// the referrers dataset of the export).
		$total = 0;
		foreach ( $rows as $row ) {
			$total += isset( $row['count'] ) ? max( 0, (int) $row['count'] ) : 0;
		}

		$referrers = array();
		foreach ( $rows as $row ) {
			$source = isset( $row['referrer'] ) ? (string) $row['referrer'] : '';
			$visits = isset( $row['count'] ) ? (int) $row['count'] : 0;
			if ( '' === $source || $visits < 1 || in_array( $source, $skip, true ) ) {
				continue;
			}
			$referrers[] = array(
				'source' => $source,
				'domain' => isset( $row['domain'] ) ? (string) $row['domain'] : '',
				'visits' => $visits,
				'share'  => class_exists( 'Opti_Behavior_Report_Site' ) ? Opti_Behavior_Report_Site::share( $visits, $total ) : ( $total > 0 ? (int) round( 100 * $visits / $total ) : 0 ),
			);
		}
		usort(
			$referrers,
			function ( $a, $b ) {
				return $b['visits'] - $a['visits'];
			}
		);

		return array_slice( $referrers, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Avg. scroll depth of a range, as the Traffic KPI card computes it.
	 *
	 * @param string $start Start date-time.
	 * @param string $end   End date-time.
	 * @return float
	 */
	private function get_screen_scroll_depth( $start, $end ) {
		$figures = $this->get_screen_figures( $start, $end, array( 'scroll' ) );
		if ( is_array( $figures ) && isset( $figures['scroll'] ) ) {
			return (float) $figures['scroll'];
		}

		// No dashboard object: same predicate as the card, raw rows only.
		global $wpdb;
		$session_spam_sql = $this->session_spam_sql( 's' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$scroll = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT AVG(pv.scroll_depth)
				FROM {$wpdb->prefix}optibehavior_pageviews pv
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON pv.session_id = s.id
				WHERE pv.view_time BETWEEN %s AND %s AND pv.scroll_depth > 0
				{$session_spam_sql}",
				$start,
				$end
			)
		);

		return round( (float) $scroll, 1 );
	}

	/**
	 * Figures read through the Traffic screen's code (dashboard
	 * get_report_email_figures()), or null when the dashboard is missing.
	 *
	 * @param string $start Start date-time.
	 * @param string $end   End date-time.
	 * @param array  $parts 'scroll' and / or 'referrers'.
	 * @return array|null
	 */
	private function get_screen_figures( $start, $end, array $parts ) {
		$dash = class_exists( 'Opti_Behavior_Report_Site' ) ? Opti_Behavior_Report_Site::dashboard() : null;
		if ( ! is_object( $dash ) || ! method_exists( $dash, 'get_report_email_figures' ) ) {
			return null;
		}
		try {
			return $dash->get_report_email_figures( $start, $end, $this->should_exclude_spam(), $parts );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Get traffic breakdown data.
	 *
	 * Visits (session rows), never referrer rows (OPT-1511): the referrers
	 * table only holds sessions that arrived with a referrer or a UTM tag,
	 * and a same-site referrer is stored there as "direct". Same rows and
	 * same rule as the Traffic screen Referrers widget (dashboard
	 * get_referrers_data()): "direct" = that widget's "Direct / None" (no
	 * other-site referrer, no ad click id, no UTM tag), "external" = every
	 * other visit. The two lines add up to the Sessions KPI.
	 *
	 * @return array
	 */
	private function get_traffic_breakdown() {
		global $wpdb;

		$start = $this->period_start->format( 'Y-m-d H:i:s' );
		$end = $this->period_end->format( 'Y-m-d H:i:s' );
		$session_spam_sql = $this->session_spam_sql( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					s.referrer AS raw_ref,
					s.utm_source AS raw_utm,
					s.utm_medium AS raw_med,
					s.entry_page AS entry_url,
					COUNT(DISTINCT s.id) AS cnt
				FROM {$wpdb->prefix}optibehavior_sessions s
				WHERE s.start_time BETWEEN %s AND %s
				{$session_spam_sql}
				GROUP BY s.referrer, s.utm_source, s.utm_medium, s.entry_page",
				$start,
				$end
			),
			ARRAY_A
		);

		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$counts    = array();
		foreach ( (array) $rows as $row ) {
			$type            = self::classify_visit_source( $row['raw_ref'] ?? '', $row['raw_utm'] ?? '', $row['raw_med'] ?? '', $row['entry_url'] ?? '', $site_host );
			$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + (int) $row['cnt'];
		}
		arsort( $counts );

		$total     = array_sum( $counts );
		$breakdown = array();
		foreach ( $counts as $type => $count ) {
			$breakdown[] = array(
				'type'       => $type,
				'count'      => (int) $count,
				'percentage' => $total > 0 ? round( $count * 100 / $total, 1 ) : 0,
			);
		}

		return array(
			'breakdown' => $breakdown,
			'total'     => $total,
		);
	}

	/**
	 * Source type of one visit: 'direct' or 'external'.
	 *
	 * Same rule as the "Direct / None" row of the Traffic screen Referrers
	 * widget: a referrer on this site (www. ignored) counts as no referrer;
	 * without a referrer, an ad click id or a UTM tag still names a source.
	 *
	 * @param string $referrer   Session referrer.
	 * @param string $utm_source Session utm_source.
	 * @param string $utm_medium Session utm_medium.
	 * @param string $entry_url  Session entry page.
	 * @param string $site_host  Host of home_url().
	 * @return string
	 */
	public static function classify_visit_source( $referrer, $utm_source, $utm_medium, $entry_url, $site_host ) {
		$referrer = is_string( $referrer ) ? trim( $referrer ) : '';
		$host     = '' !== $referrer ? (string) wp_parse_url( $referrer, PHP_URL_HOST ) : '';
		if ( '' !== $host ) {
			$host_clean = preg_replace( '/^www\./i', '', $host );
			$site_clean = preg_replace( '/^www\./i', '', (string) $site_host );
			if ( '' !== $site_clean && 0 === strcasecmp( $host_clean, $site_clean ) ) {
				$host = '';
			}
		}
		if ( '' !== $host ) {
			return 'external';
		}

		$entry_url = is_string( $entry_url ) ? trim( $entry_url ) : '';
		$query     = '' !== $entry_url ? (string) wp_parse_url( $entry_url, PHP_URL_QUERY ) : '';
		if ( '' !== $query ) {
			$params = array();
			parse_str( $query, $params );
			foreach ( array( 'gclid', 'msclkid', 'fbclid', 'twclid', 'ttclid', 'yclid', 'gbraid', 'wbraid', 'mc_eid' ) as $click_id ) {
				if ( ! empty( $params[ $click_id ] ) ) {
					return 'external';
				}
			}
		}

		$utm_source = is_string( $utm_source ) ? trim( $utm_source ) : '';
		$utm_medium = is_string( $utm_medium ) ? trim( $utm_medium ) : '';
		return ( '' !== $utm_source || '' !== $utm_medium ) ? 'external' : 'direct';
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

		// Click rows = events 16 / 17, like Engagement_Counters::CLICK_EVENTS and the
		// Heatmaps screens; the old 1 / 2 codes are never written, the block was always 0 (OPT-1515).

		// Total clicks
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$total_clicks = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->prefix}optibehavior_events e
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON e.session_id = s.id
				WHERE e.insert_at BETWEEN %s AND %s
				AND e.event IN (16, 17)
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
					p.id AS page_id,
					p.title,
					p.url,
					COUNT(e.id) as clicks
				FROM {$wpdb->prefix}optibehavior_events e
				INNER JOIN {$wpdb->prefix}optibehavior_pages p ON e.page_id2 = p.id
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON e.session_id = s.id
				WHERE e.insert_at BETWEEN %s AND %s
				AND e.event IN (16, 17)
				{$session_spam_sql}
				GROUP BY p.id
				ORDER BY clicks DESC
				LIMIT 5",
				$start,
				$end
			),
			ARRAY_A
		);

		// Most clicked element of the 3 pages the e-mail lists (OPT-1515).
		if ( is_array( $top_clicked ) ) {
			foreach ( $top_clicked as $i => $row ) {
				$top_clicked[ $i ]['element'] = $i < self::HEATMAP_ELEMENT_PAGES
					? $this->get_top_clicked_element( (int) $row['page_id'], $start, $end, $session_spam_sql )
					: '';
			}
		}

		// Every clicked page of the period, not the 5 rows above (OPT-1511).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$pages_tracked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.id)
				FROM {$wpdb->prefix}optibehavior_events e
				INNER JOIN {$wpdb->prefix}optibehavior_pages p ON e.page_id2 = p.id
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON e.session_id = s.id
				WHERE e.insert_at BETWEEN %s AND %s
				AND e.event IN (16, 17)
				{$session_spam_sql}",
				$start,
				$end
			)
		);

		return array(
			'total_clicks'   => (int) $total_clicks,
			'top_clicked'    => $top_clicked,
			'pages_tracked'  => (int) $pages_tracked,
		);
	}

	/**
	 * Pages of the click block that get their most clicked element (OPT-1515).
	 */
	const HEATMAP_ELEMENT_PAGES = 3;

	/**
	 * Longest element label printed in the e-mail (OPT-1515).
	 */
	const HEATMAP_ELEMENT_MAX_CHARS = 40;

	/**
	 * Label of the most clicked element of one page in the period (OPT-1515).
	 *
	 * Same click rows as get_heatmap_summary() (events 16 / 17, same spam
	 * clause). Elements are grouped like the Page X-Ray preview (selector,
	 * else text, else tag); the first group with a printable label wins.
	 *
	 * @param int    $page_id          optibehavior_pages id.
	 * @param string $start            Period start (Y-m-d H:i:s).
	 * @param string $end              Period end (Y-m-d H:i:s).
	 * @param string $session_spam_sql Spam clause on alias s.
	 * @return string Label, '' when none can be shown.
	 */
	private function get_top_clicked_element( $page_id, $start, $end, $session_spam_sql ) {
		global $wpdb;

		if ( $page_id <= 0 ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					MAX(e.element_tag) AS tag,
					MAX(e.element_text) AS txt,
					MAX(e.element_class) AS cls,
					MAX(e.element_id) AS eid,
					COUNT(*) AS c
				FROM {$wpdb->prefix}optibehavior_events e
				INNER JOIN {$wpdb->prefix}optibehavior_sessions s ON e.session_id = s.id
				WHERE e.page_id2 = %d
				AND e.insert_at BETWEEN %s AND %s
				AND e.event IN (16, 17)
				AND LOWER(COALESCE(e.element_tag, '')) NOT IN ('', 'html', 'body')
				{$session_spam_sql}
				GROUP BY COALESCE(NULLIF(e.element_selector, ''), NULLIF(e.element_text, ''), e.element_tag)
				ORDER BY c DESC
				LIMIT 10",
				$page_id,
				$start,
				$end
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' )
				&& Opti_Behavior_Stats_Spam_Filter::is_consent_element( array( $row['cls'] ?? '', $row['eid'] ?? '' ) ) ) {
				continue;
			}
			$label = self::element_label( $row['tag'] ?? '', $row['txt'] ?? '', $row['cls'] ?? '' );
			if ( '' !== $label ) {
				return $label;
			}
		}

		return '';
	}

	/**
	 * Short readable label of a clicked element (OPT-1515).
	 *
	 * Visible text first (the tracker never stores a form field value), else
	 * tag + first readable class ("button.add-to-cart"). A part holding "@"
	 * or a run of 6+ digits (e-mail, user / order id) is never printed; such
	 * a text gives '' so the caller takes the next element.
	 *
	 * @param string $tag   element_tag.
	 * @param string $text  element_text.
	 * @param string $class element_class.
	 * @return string Plain text, at most HEATMAP_ELEMENT_MAX_CHARS characters; '' = nothing safe.
	 */
	public static function element_label( $tag, $text, $class ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
		if ( '' !== $text ) {
			// Text with personal data = skip the element, never a bare "span".
			return self::is_safe_element_part( $text ) ? self::clip_element_label( $text ) : '';
		}

		$tag = strtolower( trim( (string) $tag ) );
		if ( ! preg_match( '/^[a-z][a-z0-9-]{0,20}$/', $tag ) ) {
			return '';
		}

		$label = $tag;
		foreach ( preg_split( '/\s+/', trim( (string) $class ) ) as $name ) {
			if ( preg_match( '/^[a-z][a-z_-]{2,30}$/i', $name ) && self::is_safe_element_part( $name ) ) {
				$label .= '.' . $name;
				break;
			}
		}

		return self::clip_element_label( $label );
	}

	/**
	 * No e-mail address, no long number (OPT-1515).
	 *
	 * @param string $value Text.
	 * @return bool
	 */
	private static function is_safe_element_part( $value ) {
		return false === strpos( $value, '@' ) && ! preg_match( '/\d{6,}/', $value );
	}

	/**
	 * Cut a label to HEATMAP_ELEMENT_MAX_CHARS characters (OPT-1515).
	 *
	 * @param string $label Label.
	 * @return string
	 */
	private static function clip_element_label( $label ) {
		return function_exists( 'mb_strimwidth' )
			? mb_strimwidth( $label, 0, self::HEATMAP_ELEMENT_MAX_CHARS, '…', 'UTF-8' )
			: substr( $label, 0, self::HEATMAP_ELEMENT_MAX_CHARS );
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
		// OPT-1619: a suggested template funnel (not confirmed) is left out of the report.
		$confirmed_sql = class_exists( 'Opti_Behavior_Funnel_Database' ) ? Opti_Behavior_Funnel_Database::confirmed_sql( 'f' ) : '';

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

		// Get active funnels with tracking stats for the period. Visits, never
		// tracking rows (OPT-588): same entries / completions as the Funnels
		// screen and Smart Insights (a visit with several rows counts once).
		// A visit counts only with its session row, like the Funnels screen
		// (INNER JOIN there): rows without a session never count (OPT-1596).
		// The LEFT JOIN stays so a funnel without entries keeps its 0 row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$funnels = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					f.id,
					f.name,
					COUNT(DISTINCT CASE WHEN s.id IS NOT NULL THEN ft.session_id END) as total_entries,
					COUNT(DISTINCT CASE WHEN s.id IS NOT NULL AND ft.completed = 1 THEN ft.session_id END) as completions,
					ROUND(
						COUNT(DISTINCT CASE WHEN s.id IS NOT NULL AND ft.completed = 1 THEN ft.session_id END) * 100.0
						/ NULLIF(COUNT(DISTINCT CASE WHEN s.id IS NOT NULL THEN ft.session_id END), 0), 1
					) as conversion_rate
				FROM {$table_funnels} f
				LEFT JOIN {$table_tracking} ft
					ON f.id = ft.funnel_id
					AND ft.entry_time BETWEEN %s AND %s
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON ft.session_id = s.id
				WHERE f.status = 'active'{$confirmed_sql}
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
			"SELECT COUNT(*) FROM {$table_funnels} f WHERE f.status = 'active'{$confirmed_sql}"
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
					COUNT(DISTINCT NULLIF(s.visitor_id, '')) as visitors
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
					AVG(r.duration) as avg_duration,
					SUM(r.watched = 1) as watched,
					AVG(r.page_count) as avg_pages
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
		$title = $this->get_first_non_empty( $insight, array( 'title', 'headline', 'card_title', 'signal_label', 'signal_name', 'signal_id' ), __( 'Smart Insight', 'opti-behavior' ) );
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
		$values = array();
		foreach ( array( 'detection', 'metrics' ) as $field ) {
			if ( isset( $insight[ $field ] ) && is_array( $insight[ $field ] ) ) {
				$values = array_merge( $values, $insight[ $field ] ); // Metrics win on a shared key.
			}
		}

		// The figures the card is about first, then the other measured figures in stored order (OPT-1514).
		$signal = isset( $insight['signal_id'] ) ? sanitize_key( (string) $insight['signal_id'] ) : '';
		$keys   = array_merge(
			self::EVIDENCE_SIGNAL_KEYS[ $signal ] ?? array(),
			self::EVIDENCE_GENERIC_KEYS,
			array_keys( $values )
		);

		$evidence = array();
		$used     = array();
		foreach ( $keys as $position => $key ) {
			if ( count( $evidence ) >= 3 ) {
				break;
			}
			if ( ! is_string( $key ) || isset( $used[ $key ] ) || ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value = $values[ $key ];
			if ( ! is_numeric( $value ) || is_bool( $value ) || self::is_technical_evidence_key( $key ) ) {
				continue;
			}
			// A zero says nothing unless the card itself is about that figure.
			$listed = in_array( $key, self::EVIDENCE_SIGNAL_KEYS[ $signal ] ?? array(), true );
			if ( ! $listed && 0.0 === (float) $value ) {
				continue;
			}

			$used[ $key ] = true;
			$evidence[]   = sprintf( '%1$s: %2$s', $this->evidence_label( $key ), $this->format_metric_value( $value, $key ) );
		}

		return $evidence;
	}

	/**
	 * Evidence figures per Free signal, in the order they are printed (OPT-1514).
	 *
	 * @var array<string, string[]>
	 */
	const EVIDENCE_SIGNAL_KEYS = array(
		'basic_bounce_alert'              => array( 'bounce_rate', 'site_avg_bounce_rate', 'sessions' ),
		'high_exit_rate_page'             => array( 'exit_rate', 'site_avg_exit_rate', 'sessions' ),
		'low_scroll_depth_important_page' => array( 'avg_scroll_depth', 'site_avg_scroll_depth', 'sessions' ),
		'low_scroll_depth_page'           => array( 'avg_scroll_depth', 'site_avg_scroll_depth', 'sessions' ),
		'high_traffic_low_engagement'     => array( 'sessions', 'avg_time_on_page', 'avg_scroll_depth' ),
		'basic_mobile_bounce_warning'     => array( 'bounce_rate', 'desktop_bounce_rate', 'sessions' ),
		'traffic_spike_observation'       => array( 'sessions', 'previous_sessions', 'bounce_rate' ),
		'funnel_dropoff_detected'         => array( 'dropoff_rate', 'entries', 'completions' ),
	);

	/**
	 * Figures worth printing for any other card, in this order.
	 *
	 * @var string[]
	 */
	const EVIDENCE_GENERIC_KEYS = array( 'sessions', 'exit_rate', 'bounce_rate', 'dropoff_rate', 'conversion_rate', 'avg_scroll_depth', 'avg_time_on_page' );

	/**
	 * Internal bookkeeping numbers of a detection payload (ids, thresholds, sums, gaps, scores).
	 *
	 * @param string $key Payload key.
	 * @return bool
	 */
	private static function is_technical_evidence_key( $key ) {
		return (bool) preg_match( '/(^|_)ids?$|_key$|_sum(_squares)?$|_samples$|_coverage$|_gap$|_rows$|^threshold_|^impact_|^sample_|^correlat|^previous_(status|priority)|^priority_|^recurrence|^matched_|^observ|^(step_count|funnel_step|observed_max_step|avg_max_step_reached|top_page_sessions)$|^site_(users|pageviews|sessions|bounce_sessions)$/', $key );
	}

	/**
	 * Human label of an evidence key (labels of the Smart Insights notification cards, OPT-1514).
	 *
	 * @param string $key Payload key.
	 * @return string
	 */
	private function evidence_label( $key ) {
		// Same msgids as trait-opti-behavior-ajax-handlers.php get_smart_insights_notification_metric_label_impl().
		$labels = array(
			'page_sessions'          => __( 'Sessions', 'opti-behavior' ),
			'sessions'               => __( 'Sessions', 'opti-behavior' ),
			'pageviews'              => __( 'Pageviews', 'opti-behavior' ),
			'page_bounce_rate'       => __( 'Bounce rate', 'opti-behavior' ),
			'bounce_rate'            => __( 'Bounce rate', 'opti-behavior' ),
			'page_exit_rate'         => __( 'Exit rate', 'opti-behavior' ),
			'exit_rate'              => __( 'Exit rate', 'opti-behavior' ),
			'page_avg_scroll_depth'  => __( 'Avg. scroll depth', 'opti-behavior' ),
			'avg_scroll_depth'       => __( 'Avg. scroll depth', 'opti-behavior' ),
			'avg_time_on_page'       => __( 'Avg. time on page', 'opti-behavior' ),
			'page_avg_time_on_page'  => __( 'Avg. time on page', 'opti-behavior' ),
			'mobile_sessions'        => __( 'Mobile sessions', 'opti-behavior' ),
			'mobile_bounce_rate'     => __( 'Mobile bounce rate', 'opti-behavior' ),
			// Keys of the Free signals the notification table does not carry.
			'site_avg_bounce_rate'   => __( 'Site average bounce rate', 'opti-behavior' ),
			'site_avg_exit_rate'     => __( 'Site average exit rate', 'opti-behavior' ),
			'site_avg_scroll_depth'  => __( 'Site average scroll depth', 'opti-behavior' ),
			'site_avg_time_on_page'  => __( 'Site average time on page', 'opti-behavior' ),
			'desktop_bounce_rate'    => __( 'Desktop bounce rate', 'opti-behavior' ),
			'previous_sessions'      => __( 'Sessions in the previous period', 'opti-behavior' ),
			'dropoff_rate'           => __( 'Drop-off rate', 'opti-behavior' ),
			'completion_rate'        => __( 'Completion rate', 'opti-behavior' ),
			'conversion_rate'        => __( 'Conversion rate', 'opti-behavior' ),
			'entries'                => __( 'Funnel entries', 'opti-behavior' ),
			'completions'            => __( 'Completions', 'opti-behavior' ),
			'users'                  => __( 'Visitors', 'opti-behavior' ),
		);

		return $labels[ $key ] ?? $this->humanize_key( $key );
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
	 * Rates ("_rate", "_ratio", "_pct", scroll depth) print as "43%" (a 0-1 rate is
	 * multiplied by 100), times on page as a duration, other numbers localized (OPT-1514).
	 *
	 * @param mixed  $value Metric value.
	 * @param string $key   Metric key ('' = plain number).
	 * @return string
	 */
	private function format_metric_value( $value, $key = '' ) {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'opti-behavior' ) : __( 'No', 'opti-behavior' );
		}

		if ( is_numeric( $value ) ) {
			$number = (float) $value;
			$key    = (string) $key;
			if ( preg_match( '/_rat(e|io)$/', $key ) ) {
				if ( $number >= 0 && $number <= 1 ) {
					$number *= 100;
				}
				return $this->format_number( $number ) . '%';
			}
			if ( preg_match( '/_pct$|scroll_depth$/', $key ) ) {
				return $this->format_number( $number ) . '%';
			}
			if ( preg_match( '/time_on_page$|_duration$|_seconds$|^avg_session_time$/', $key ) ) {
				return $this->format_duration( (int) round( $number ) );
			}

			return '' === $key ? (string) round( $number, 2 ) : $this->format_number( $number );
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Localized number: whole numbers without decimals, others with one.
	 *
	 * @param float $number Number.
	 * @return string
	 */
	private function format_number( $number ) {
		$decimals = abs( $number - round( $number ) ) < 0.05 ? 0 : 1;

		return number_format_i18n( $number, $decimals );
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
	 * @return array Previous period empty and current > 0 = direction 'new',
	 *               value null (nothing to compare with, OPT-1510).
	 */
	private function calculate_change( $current, $previous, $inverse = false ) {
		$current = (float) $current;
		$previous = (float) $previous;

		if ( 0.0 === $previous ) {
			return array(
				'value'     => $current > 0 ? null : 0,
				'direction' => $current > 0 ? 'new' : 'neutral',
				'positive'  => null,
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
	 * Change of a rate (bounce rate, scroll depth) = gap in points, one
	 * decimal, the Traffic screen rule (data helpers get_period_change(),
	 * OPT-1626): 29.7 vs 11.3 = "+18.4 pts", never a relative percent
	 * (OPT-1766). No session in the previous period = no base ('new'); a 0 %
	 * rate over real visits is a value.
	 *
	 * @param float $current       Current rate (percent).
	 * @param float $previous      Previous rate (percent).
	 * @param int   $prev_sessions Sessions of the previous period.
	 * @param bool  $inverse       Whether lower is better.
	 * @return array { value, direction, positive, unit 'pts' }.
	 */
	private function calculate_rate_change( $current, $previous, $prev_sessions, $inverse = false ) {
		if ( (int) $prev_sessions <= 0 ) {
			return array(
				'value'     => (float) $current > 0 ? null : 0,
				'direction' => (float) $current > 0 ? 'new' : 'neutral',
				'positive'  => null,
				'unit'      => 'pts',
			);
		}

		$gap       = round( (float) $current - (float) $previous, 1 );
		$direction = $gap > 0 ? 'up' : ( $gap < 0 ? 'down' : 'neutral' );
		$positive  = null;
		if ( abs( $gap ) > 0.5 ) {
			$positive = $inverse ? $gap < 0 : $gap > 0;
		}

		return array(
			'value'     => abs( $gap ),
			'direction' => $direction,
			'positive'  => $positive,
			'unit'      => 'pts',
		);
	}

	/**
	 * Printed amount of a KPI change: "18.4%" for a count, "18.4 pts" for a
	 * rate (unit 'pts', OPT-1766). Plain text, not escaped.
	 *
	 * @param array $change Change descriptor (value, unit).
	 * @return string
	 */
	public static function change_amount_text( $change ) {
		$value = isset( $change['value'] ) ? (float) $change['value'] : 0.0;
		if ( 'pts' === ( $change['unit'] ?? '' ) ) {
			$num = number_format_i18n( $value, abs( $value - round( $value ) ) > 0.05 ? 1 : 0 );
			/* translators: %s: gap between two rates in percentage points, e.g. "18.4". */
			return sprintf( _x( '%s pts', 'percentage points', 'opti-behavior' ), $num );
		}
		return ( isset( $change['value'] ) ? $change['value'] : '' ) . '%';
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
