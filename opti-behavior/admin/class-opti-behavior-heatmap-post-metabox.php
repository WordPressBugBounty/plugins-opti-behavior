<?php
/**
 * Post Metabox Class
 *
 * Handles the heatmap analytics meta box on post/page edit screens.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Metabox analytics SQL fragments are built by local helpers from fixed columns, absint IDs, and prepared placeholders.

/**
 * Post Metabox Class
 *
 * Provides analytics meta box for post/page editor screens.
 *
 * @since 1.0.0
 */
class Opti_Behavior_Heatmap_Post_Metabox {
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- SQL fragments below are built by local whitelisted helpers and passed to $wpdb->prepare() with matching variadic parameters.

	/**
	 * Core instance.
	 *
	 * @since 1.0.0
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Opti_Behavior_Heatmap_Core $core Core instance.
	 */
	public function __construct( $core ) {
		$this->core = $core;
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_editor_analytics_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_metabox_assets' ) );
		add_action( 'wp_ajax_optibehavior_post_analytics', array( $this, 'ajax_post_analytics' ) );
		add_action( 'wp_ajax_optibehavior_post_timeseries', array( $this, 'ajax_post_timeseries' ) );
		add_action( 'wp_ajax_optibehavior_post_analytics_chart', array( $this, 'ajax_post_analytics_chart' ) );
		add_action( 'wp_ajax_optibehavior_post_referrers', array( $this, 'ajax_post_referrers' ) );
		add_action( 'wp_ajax_optibehavior_post_outbound_clicks', array( $this, 'ajax_post_outbound_clicks' ) );
		add_action( 'wp_ajax_optibehavior_post_countries', array( $this, 'ajax_post_countries' ) );
		add_action( 'wp_ajax_optibehavior_post_browsers', array( $this, 'ajax_post_browsers' ) );
		add_action( 'wp_ajax_optibehavior_post_devices', array( $this, 'ajax_post_devices' ) );
	}

	/**
	 * Create the canonical page analytics repository.
	 *
	 * @return Opti_Behavior_Page_Analytics_Repository
	 */
	private function get_page_analytics_repository() {
		return new Opti_Behavior_Page_Analytics_Repository();
	}

	/**
	 * Shared default analytics period — "All time" — matching the frontend
	 * stats bar so both surfaces show the same baseline for a page by default.
	 *
	 * @return string
	 */
	private function get_default_analytics_period() {
		return Opti_Behavior_Page_Analytics_Repository::get_default_standard_period();
	}

	/**
	 * Read the requested standard period from the current AJAX POST (nonce is
	 * verified by each caller before this runs). Returns '' when absent so the
	 * analytics context falls back to the shared default ("All time").
	 *
	 * @return string
	 */
	private function get_requested_period() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the per-post nonce before invoking this.
		if ( ! isset( $_POST['period'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return Opti_Behavior_Page_Analytics_Repository::sanitize_standard_period( wp_unslash( $_POST['period'] ) );
	}

	/**
	 * Resolve a post/revision to repository identity, period, and traffic scope.
	 *
	 * @param int    $post_id Requested post or revision ID.
	 * @param string $period  Optional analytics period.
	 * @return array
	 */
	private function get_post_analytics_context( $post_id, $period = '' ) {
		$repository    = $this->get_page_analytics_repository();
		$period        = $period ? sanitize_key( $period ) : $this->get_default_analytics_period();
		$traffic_scope = Opti_Behavior_Page_Analytics_Repository::TRAFFIC_SCOPE_HUMAN;
		$identity      = $repository->resolve_page_identity(
			array(
				'post_id' => absint( $post_id ),
				'period'  => $period,
			)
		);
		$canonical_id  = ! empty( $identity['wp_post_id'] ) ? absint( $identity['wp_post_id'] ) : absint( $post_id );
		$canonical_url = ! empty( $identity['canonical_url'] ) ? $identity['canonical_url'] : get_permalink( $canonical_id );
		$date_range    = $repository->normalize_date_range( $period );

		return array(
			'repository'        => $repository,
			'request_post_id'   => absint( $post_id ),
			'canonical_post_id' => $canonical_id,
			'canonical_url'     => $canonical_url,
			'identity'          => $identity,
			'page_id'           => ! empty( $identity['page_id'] ) ? absint( $identity['page_id'] ) : 0,
			'page_ids'          => ! empty( $identity['page_ids'] ) ? array_map( 'absint', $identity['page_ids'] ) : array(),
			'period'            => $date_range['period'],
			'period_label'      => $date_range['label'],
			'date_range'        => $date_range,
			'traffic_scope'     => $traffic_scope,
		);
	}

	/**
	 * Build a prepared IN clause for canonical page IDs.
	 *
	 * @param string $column   SQL column/expression.
	 * @param array  $page_ids Page IDs.
	 * @return string
	 */
	private function build_page_ids_clause( $column, array $page_ids ) {
		$page_ids = array_values( array_filter( array_map( 'absint', $page_ids ) ) );
		if ( empty( $page_ids ) ) {
			return '1=0';
		}

		return $column . ' IN (' . implode( ',', array_fill( 0, count( $page_ids ), '%d' ) ) . ')';
	}

	/**
	 * Build date range SQL for repository-normalized periods.
	 *
	 * @param string $column     SQL date column.
	 * @param array  $date_range Repository date range.
	 * @return string
	 */
	private function build_date_clause( $column, array $date_range ) {
		return empty( $date_range['has_bounds'] ) ? '' : " AND {$column} BETWEEN %s AND %s";
	}

	/**
	 * Return date range parameters.
	 *
	 * @param array $date_range Repository date range.
	 * @return array
	 */
	private function get_date_params( array $date_range ) {
		return empty( $date_range['has_bounds'] ) ? array() : array( $date_range['start'], $date_range['end'] );
	}

	/**
	 * Count click events by device event type for the canonical page set.
	 *
	 * @param array  $context Analytics context.
	 * @param int    $event   Event ID.
	 * @param string $alias   Session alias.
	 * @return int
	 */
	private function count_canonical_events( array $context, $event, $alias = 's' ) {
		if ( empty( $context['page_ids'] ) ) {
			return 0;
		}

		global $wpdb;

		$where   = $this->build_page_ids_clause( 'e.page_id2', $context['page_ids'] );
		$where  .= $this->build_date_clause( 'e.insert_at', $context['date_range'] );
		$where  .= $context['repository']->get_traffic_scope_sql( $alias, $context['traffic_scope'] )['sql'];
		$params  = array_merge( $context['page_ids'], $this->get_date_params( $context['date_range'] ) );
		$params[] = absint( $event );

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM {$wpdb->prefix}optibehavior_events e
					LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON s.id = e.session_id
					WHERE {$where}
						AND e.event = %d",
					...$params
				)
			)
		);
	}

	/**
	 * Return latest activity timestamp for the canonical page set and period.
	 *
	 * @param array $context Analytics context.
	 * @return string|null
	 */
	private function get_canonical_last_updated( array $context ) {
		if ( empty( $context['page_ids'] ) ) {
			return null;
		}

		global $wpdb;

		$page_clause = $this->build_page_ids_clause( 'e.page_id2', $context['page_ids'] );
		$date_clause = $this->build_date_clause( 'e.insert_at', $context['date_range'] );
		$traffic     = $context['repository']->get_traffic_scope_sql( 's', $context['traffic_scope'] )['sql'];
		$params      = array_merge( $context['page_ids'], $this->get_date_params( $context['date_range'] ) );

		$last_from_events = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(e.insert_at)
				FROM {$wpdb->prefix}optibehavior_events e
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON s.id = e.session_id
				WHERE {$page_clause}{$date_clause}{$traffic}",
				...$params
			)
		);

		$page_clause = $this->build_page_ids_clause( 'pv.page_id', $context['page_ids'] );
		$date_clause = $this->build_date_clause( 'pv.view_time', $context['date_range'] );
		$params      = array_merge( $context['page_ids'], $this->get_date_params( $context['date_range'] ) );

		$last_from_pageviews = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(pv.view_time)
				FROM {$wpdb->prefix}optibehavior_pageviews pv
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON s.id = pv.session_id
				WHERE {$page_clause}{$date_clause}{$traffic}",
				...$params
			)
		);

		$timestamps = array_filter( array( $last_from_events, $last_from_pageviews ) );

		return ! empty( $timestamps ) ? max( $timestamps ) : null;
	}

	/**
	 * Format a latest activity timestamp for display.
	 *
	 * @param string|null $timestamp Timestamp.
	 * @return string|null
	 */
	private function format_last_updated( $timestamp ) {
		if ( ! $timestamp ) {
			return null;
		}

		$diff = current_time( 'timestamp' ) - strtotime( $timestamp );
		if ( $diff < 60 ) {
			return __( 'Just now', 'opti-behavior' );
		}
		if ( $diff < 3600 ) {
			return sprintf(
				/* translators: %d: number of minutes */
				__( '%d min ago', 'opti-behavior' ),
				floor( $diff / 60 )
			);
		}
		if ( $diff < 86400 ) {
			return sprintf(
				/* translators: %d: number of hours */
				__( '%d hours ago', 'opti-behavior' ),
				floor( $diff / 3600 )
			);
		}

		return sprintf(
			/* translators: %d: number of days */
			__( '%d days ago', 'opti-behavior' ),
			floor( $diff / 86400 )
		);
	}

	/**
	 * Let Pro provide dimension rows from the same canonical source used for
	 * repository metrics.
	 *
	 * @param string $dimension Dimension key.
	 * @param array  $context   Analytics context.
	 * @return array|null
	 */
	private function get_provider_dimension_rows( $dimension, array $context ) {
		$rows = apply_filters( 'opti_behavior_page_analytics_dimension_rows', null, sanitize_key( $dimension ), $context );
		if ( ! is_array( $rows ) ) {
			return null;
		}

		// Provider rows (Pro) hold the same visitor-supplied strings as the
		// Free queries: clean them the same way before they reach the admin JS.
		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $key => $value ) {
				if ( ! is_string( $value ) ) {
					continue;
				}
				$rows[ $i ][ $key ] = in_array( $key, array( 'referrer_url', 'target_url', 'url' ), true )
					? self::clean_tracked_url_for_output( $value )
					: self::clean_tracked_text_for_output( $value );
			}
		}

		return $rows;
	}

	/**
	 * Let Pro provide visitor-type counts from the same source used for metrics.
	 *
	 * @param array $context Analytics context.
	 * @param array $metrics Repository metrics.
	 * @return array|null
	 */
	private function get_provider_visitor_type_counts( array $context, array $metrics ) {
		$counts = apply_filters( 'opti_behavior_page_analytics_visitor_type_counts', null, $context, $metrics );

		return is_array( $counts ) ? $counts : null;
	}

	/**
	 * Normalize chart/timeseries ranges to site-time SQL bounds.
	 *
	 * @param string $range Chart range slug.
	 * @return array
	 */
	private function get_chart_range_context( $range ) {
		$range = sanitize_key( $range );
		if ( ! in_array( $range, array( '24h', '7d', '30d', '90d', 'all' ), true ) ) {
			$range = '7d';
		}

		$timezone       = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now            = new DateTimeImmutable( 'now', $timezone );
		$start          = null;
		$fallback_start = null;
		$group_format   = '%Y-%m-%d';

		switch ( $range ) {
			case '24h':
				$start          = $now->modify( '-24 hours' );
				$fallback_start = $now->modify( '-7 days' );
				$group_format   = '%Y-%m-%d %H:00:00';
				break;
			case '7d':
				$start = $now->modify( '-7 days' );
				break;
			case '30d':
				$start = $now->modify( '-30 days' );
				break;
			case '90d':
				$start = $now->modify( '-90 days' );
				break;
			case 'all':
			default:
				break;
		}

		return array(
			'range'          => $range,
			'group_format'   => $group_format,
			'date_range'     => array(
				'start'      => $start ? $start->format( 'Y-m-d H:i:s' ) : null,
				'end'        => $now->format( 'Y-m-d H:i:s' ),
				'has_bounds' => (bool) $start,
			),
			'fallback_range' => $fallback_start
				? array(
					'start'      => $fallback_start->format( 'Y-m-d H:i:s' ),
					'end'        => $now->format( 'Y-m-d H:i:s' ),
					'has_bounds' => true,
				)
				: null,
		);
	}

	/**
	 * Return the DATE_FORMAT expression for a chart bucket.
	 *
	 * @param string $column       Date/time column.
	 * @param string $group_format Normalized group format.
	 * @return string
	 */
	private function get_chart_bucket_expression( $column, $group_format ) {
		if ( '%Y-%m-%d %H:00:00' === $group_format ) {
			return "DATE_FORMAT({$column}, '%%Y-%%m-%%d %%H:00:00')";
		}

		return "DATE_FORMAT({$column}, '%%Y-%%m-%%d')";
	}

	/**
	 * Query canonical click/mobile event timeseries rows.
	 *
	 * @param array $context       Analytics context.
	 * @param array $range_context Chart range context.
	 * @return array
	 */
	private function query_canonical_timeseries_rows( array $context, array $range_context ) {
		if ( empty( $context['page_ids'] ) ) {
			return array();
		}

		global $wpdb;

		$page_clause = $this->build_page_ids_clause( 'e.page_id2', $context['page_ids'] );
		$date_clause = $this->build_date_clause( 'e.insert_at', $range_context['date_range'] );
		$traffic     = $context['repository']->get_traffic_scope_sql( 's', $context['traffic_scope'] )['sql'];
		$bucket      = $this->get_chart_bucket_expression( 'e.insert_at', $range_context['group_format'] );
		$params      = array_merge( $context['page_ids'], $this->get_date_params( $range_context['date_range'] ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$bucket} AS bucket,
					SUM(CASE WHEN e.event = 16 THEN 1 ELSE 0 END) AS pc,
					SUM(CASE WHEN e.event = 17 THEN 1 ELSE 0 END) AS mobile
				FROM {$wpdb->prefix}optibehavior_events e
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON s.id = e.session_id
				WHERE {$page_clause}{$date_clause}{$traffic}
				GROUP BY bucket
				ORDER BY bucket ASC",
				...$params
			),
			ARRAY_A
		);
	}

	/**
	 * Query canonical chart rows for sessions and events.
	 *
	 * @param array $context       Analytics context.
	 * @param array $range_context Chart range context.
	 * @return array
	 */
	private function query_canonical_chart_rows( array $context, array $range_context ) {
		if ( empty( $context['page_ids'] ) ) {
			return array();
		}

		global $wpdb;

		$merged = array();

		$page_clause = $this->build_page_ids_clause( 'pv.page_id', $context['page_ids'] );
		$date_clause = $this->build_date_clause( 'pv.view_time', $range_context['date_range'] );
		$traffic     = $context['repository']->get_traffic_scope_sql( 's', $context['traffic_scope'] )['sql'];
		$bucket      = $this->get_chart_bucket_expression( 'pv.view_time', $range_context['group_format'] );
		$params      = array_merge( $context['page_ids'], $this->get_date_params( $range_context['date_range'] ) );

		$pageview_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$bucket} AS bucket,
					COUNT(DISTINCT pv.session_id) AS total_sessions,
					COUNT(DISTINCT CASE WHEN LOWER(COALESCE(v.device_type, '')) = 'desktop' THEN pv.session_id END) AS desktop_sessions,
					COUNT(DISTINCT CASE WHEN LOWER(COALESCE(v.device_type, '')) = 'mobile' THEN pv.session_id END) AS mobile_sessions
				FROM {$wpdb->prefix}optibehavior_pageviews pv
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON s.id = pv.session_id
				LEFT JOIN {$wpdb->prefix}optibehavior_visitors v ON pv.visitor_id = v.id
				WHERE {$page_clause}{$date_clause}{$traffic}
				GROUP BY bucket
				ORDER BY bucket ASC",
				...$params
			),
			ARRAY_A
		);

		$page_clause = $this->build_page_ids_clause( 'e.page_id2', $context['page_ids'] );
		$date_clause = $this->build_date_clause( 'e.insert_at', $range_context['date_range'] );
		$traffic     = $context['repository']->get_traffic_scope_sql( 's', $context['traffic_scope'] )['sql'];
		$bucket      = $this->get_chart_bucket_expression( 'e.insert_at', $range_context['group_format'] );
		$params      = array_merge( $context['page_ids'], $this->get_date_params( $range_context['date_range'] ) );

		$event_session_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$bucket} AS bucket,
					COUNT(DISTINCT e.session_id) AS total_sessions,
					COUNT(DISTINCT CASE WHEN LOWER(COALESCE(v.device_type, '')) = 'desktop' THEN e.session_id END) AS desktop_sessions,
					COUNT(DISTINCT CASE WHEN LOWER(COALESCE(v.device_type, '')) = 'mobile' THEN e.session_id END) AS mobile_sessions
				FROM {$wpdb->prefix}optibehavior_events e
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON s.id = e.session_id
				LEFT JOIN {$wpdb->prefix}optibehavior_visitors v ON s.visitor_id = v.id
				WHERE {$page_clause}{$date_clause}{$traffic}
				GROUP BY bucket
				ORDER BY bucket ASC",
				...$params
			),
			ARRAY_A
		);

		foreach ( array_merge( $pageview_rows, $event_session_rows ) as $row ) {
			$bucket_key = $row['bucket'];
			if ( ! isset( $merged[ $bucket_key ] ) ) {
				$merged[ $bucket_key ] = array(
					'total_visitors'   => 0,
					'desktop_visitors' => 0,
					'mobile_visitors'  => 0,
					'desktop_events'   => 0,
					'mobile_events'    => 0,
				);
			}

			$merged[ $bucket_key ]['total_visitors']   = max( $merged[ $bucket_key ]['total_visitors'], absint( $row['total_sessions'] ) );
			$merged[ $bucket_key ]['desktop_visitors'] = max( $merged[ $bucket_key ]['desktop_visitors'], absint( $row['desktop_sessions'] ) );
			$merged[ $bucket_key ]['mobile_visitors']  = max( $merged[ $bucket_key ]['mobile_visitors'], absint( $row['mobile_sessions'] ) );
		}

		$event_count_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$bucket} AS bucket,
					SUM(CASE WHEN e.event = 16 THEN 1 ELSE 0 END) AS desktop_events,
					SUM(CASE WHEN e.event = 17 THEN 1 ELSE 0 END) AS mobile_events
				FROM {$wpdb->prefix}optibehavior_events e
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON s.id = e.session_id
				WHERE {$page_clause}{$date_clause}{$traffic}
				GROUP BY bucket
				ORDER BY bucket ASC",
				...$params
			),
			ARRAY_A
		);

		foreach ( $event_count_rows as $row ) {
			$bucket_key = $row['bucket'];
			if ( ! isset( $merged[ $bucket_key ] ) ) {
				$merged[ $bucket_key ] = array(
					'total_visitors'   => 0,
					'desktop_visitors' => 0,
					'mobile_visitors'  => 0,
					'desktop_events'   => 0,
					'mobile_events'    => 0,
				);
			}

			$merged[ $bucket_key ]['desktop_events'] = absint( $row['desktop_events'] );
			$merged[ $bucket_key ]['mobile_events']  = absint( $row['mobile_events'] );
		}

		ksort( $merged );

		return $merged;
	}

	/**
	 * Register heatmap analytics meta box.
	 *
	 * @since 1.0.0
	 */
	public function add_editor_analytics_box() {
		if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		add_meta_box(
			'optibehavior_editor_analytics',
			'<i data-lucide="flame" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i>' . __( 'Post Analytics', 'opti-behavior' ),
			array( $this, 'render_editor_analytics_box' ),
			array( 'post', 'page' ),
			'normal',
			'default'
		);
	}

	/**
	 * Enqueue metabox assets.
	 *
	 * @since 1.0.0
	 * @global WP_Post $post Current post object.
	 * @param string   $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_metabox_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		global $post;
		if ( ! $post ) {
			return;
		}

		// Enqueue Lucide Icons (bundled locally, MIT licensed)
		wp_enqueue_script(
			'lucide-icons',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/lucide.min.js',
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION,
			true
		);

		// Initialize Lucide Icons after page load
		wp_add_inline_script(
			'lucide-icons',
			'document.addEventListener("DOMContentLoaded", function() {
				if (typeof lucide !== "undefined") {
					lucide.createIcons();
				}
			});'
		);

		wp_enqueue_script(
			'chart-js',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/chart.umd.min.js',
			array(),
			'4.4.0-' . OPTI_BEHAVIOR_HEATMAP_VERSION, // Lib ver + plugin ver so updates cache-bust.
			true
		);

		wp_enqueue_script(
			'opti-behavior-metabox-analytics',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/metabox-analytics.js',
			array( 'chart-js', 'jquery' ),
			$this->get_metabox_asset_version( 'assets/js/metabox-analytics.js' ),
			true
		);

		// Localize script data for metabox analytics
		$nonce   = wp_create_nonce( 'optibehavior_post_analytics_' . $post->ID );
		$context = $this->get_post_analytics_context( $post->ID );
		wp_localize_script(
			'opti-behavior-metabox-analytics',
			'optiBehaviorMetaboxData',
			array(
				'postId'       => $post->ID,
				'nonce'        => $nonce,
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'postDate'     => get_the_date( 'c', $context['canonical_post_id'] ),
				'period'       => $context['period'],
				'periodLabel'  => $context['period_label'],
				'periods'      => Opti_Behavior_Page_Analytics_Repository::get_standard_periods(),
				'defaultPeriod' => Opti_Behavior_Page_Analytics_Repository::get_default_standard_period(),
				'trafficScope' => $context['traffic_scope'],
				'assetVersion' => $this->get_metabox_asset_version( 'assets/js/metabox-analytics.js' ),
				'i18n'         => array(
					'desktop'                    => __( 'Desktop', 'opti-behavior' ),
					'mobile'                     => __( 'Mobile', 'opti-behavior' ),
					'sessions'                   => __( 'Sessions', 'opti-behavior' ),
					'analytics_not_published'    => __( 'Analytics will be available after this post is published.', 'opti-behavior' ),
					'direct_visit'               => __( 'Direct Visit', 'opti-behavior' ),
					'visits'                     => __( 'visits', 'opti-behavior' ),
					'no_referrer_data'           => __( 'No referrer data available', 'opti-behavior' ),
					'data_appears_after_visit'   => __( 'Data will appear once visitors access this page.', 'opti-behavior' ),
					'unable_load_referrer'       => __( 'Unable to load referrer data', 'opti-behavior' ),
					'try_refreshing'             => __( 'Please try refreshing the page.', 'opti-behavior' ),
					'left_page'                  => __( 'Left page', 'opti-behavior' ),
					'clicks'                     => __( 'clicks', 'opti-behavior' ),
					'no_outbound_clicks'         => __( 'No outbound clicks recorded', 'opti-behavior' ),
					'data_appears_after_click'   => __( 'Data will appear once visitors click links on this page.', 'opti-behavior' ),
					'unable_load_outbound'       => __( 'Unable to load outbound click data', 'opti-behavior' ),
					'unknown'                    => __( 'Unknown', 'opti-behavior' ),
					/* translators: %s: formatted session count */
					'new_sessions_tooltip'       => __( 'New Sessions: %s sessions (first session for that visitor)', 'opti-behavior' ),
					/* translators: %s: formatted session count */
					'returning_sessions_tooltip' => __( 'Returning Sessions: %s sessions (visitor had an earlier session)', 'opti-behavior' ),
				),
			)
		);

		// Enqueue metabox styles from external file
		wp_enqueue_style(
			'opti-behavior-metabox',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/metabox.css',
			array(),
			$this->get_metabox_asset_version( 'assets/css/metabox.css' )
		);

		wp_add_inline_style( 'opti-behavior-metabox', $this->get_metabox_controls_critical_css() );

		// Enqueue tooltips CSS for metabox
		wp_enqueue_style(
			'opti-behavior-tooltips',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/tooltips.css',
			array(),
			$this->get_metabox_asset_version( 'assets/css/tooltips.css' )
		);

		wp_enqueue_script(
			'opti-behavior-tooltips',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/tooltips.js',
			array(),
			$this->get_metabox_asset_version( 'assets/js/tooltips.js' ),
			true
		);
	}

	/**
	 * Build a cache-busting version string for edit-post meta-box assets.
	 *
	 * The plugin release version is kept in the value for package traceability, while
	 * the file modified time ensures an uploaded CSS/JS hotfix cannot keep serving an
	 * older browser/CDN cached copy with the same `?ver=` query string.
	 *
	 * @since 1.5.2
	 *
	 * @param string $relative_path Asset path relative to the plugin root.
	 * @return string Cache-busting asset version.
	 */
	private function get_metabox_asset_version( $relative_path ) {
		$version    = defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '1.5.2';
		$asset_path = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . ltrim( $relative_path, '/\\' );

		if ( file_exists( $asset_path ) ) {
			return $version . '-' . filemtime( $asset_path );
		}

		return $version;
	}

	/**
	 * Return critical controls-row CSS inline so the layout fix survives stale CSS.
	 *
	 * The same rules live in `assets/css/metabox.css`; this minimal inline copy is
	 * intentionally limited to the P1 controls row so an uploaded PHP hotfix can
	 * override an older cached stylesheet immediately.
	 *
	 * @since 1.5.2
	 *
	 * @return string Critical CSS for the edit-post analytics controls row.
	 */
	private function get_metabox_controls_critical_css() {
		return '.optibehavior-heatmap-controls{display:grid!important;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;gap:12px 16px;width:100%;min-width:0}.optibehavior-analytics-actions{grid-column:1;justify-self:start;min-width:0;max-width:100%}.optibehavior-time-filters{grid-column:2;justify-self:center;max-width:100%;overflow-x:auto}.optibehavior-action-buttons{grid-column:3;justify-self:end;display:inline-flex;align-items:center;justify-content:flex-end;flex-wrap:nowrap;gap:12px;min-width:0;max-width:100%;white-space:normal}.optibehavior-active-period{display:none!important}@media (max-width:900px){.optibehavior-heatmap-controls{grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:start}.optibehavior-analytics-actions{grid-column:1;justify-self:start}.optibehavior-action-buttons{grid-column:2;justify-self:end}.optibehavior-time-filters{grid-column:1/-1;justify-self:start}}@media (max-width:640px){.optibehavior-heatmap-controls{grid-template-columns:1fr}.optibehavior-analytics-actions,.optibehavior-action-buttons,.optibehavior-time-filters{grid-column:1;justify-self:stretch}.optibehavior-analytics-actions,.optibehavior-time-filters{justify-content:flex-start}.optibehavior-action-buttons{justify-content:flex-end}}';
	}

	/**
	 * Get metabox styles
	 *
	 * NOTE: CSS code has been moved to external file: assets/css/metabox.css
	 * The external file is enqueued in this class (lines 118-123)
	 */
	/**
	 * Render the meta box UI (loads data via AJAX)
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_editor_analytics_box( $post ) {
		$nonce = wp_create_nonce( 'optibehavior_post_analytics_' . $post->ID );
		$metabox_tooltips = opti_behavior_get_metabox_tooltips();
		$analytics_context = $this->get_post_analytics_context( $post->ID );
		$active_period_label = sprintf(
			/* translators: %s: analytics period label, for example "Last 7 Days". */
			__( 'Analytics period: %s', 'opti-behavior' ),
			$analytics_context['period_label']
		);
		?>
		<!-- Note: Metabox styles are loaded from external file: assets/css/metabox.css -->
		<!-- 3-Column Analytics Layout -->
		<div class="optibehavior-analytics-container" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<!-- Top Filters Row -->
			<div class="optibehavior-top-filters-row">
				<div class="optibehavior-active-period" data-period="<?php echo esc_attr( $analytics_context['period'] ); ?>" data-traffic-scope="<?php echo esc_attr( $analytics_context['traffic_scope'] ); ?>" aria-label="<?php echo esc_attr( $active_period_label ); ?>">
				</div>
				<div class="optibehavior-period-filter">
					<label for="optibehavior-period-select-<?php echo esc_attr( $post->ID ); ?>" class="screen-reader-text"><?php echo esc_html__( 'Analytics period', 'opti-behavior' ); ?></label>
					<i data-lucide="calendar" style="width:14px;height:14px;vertical-align:middle;"></i>
					<select id="optibehavior-period-select-<?php echo esc_attr( $post->ID ); ?>" class="optibehavior-period-select" aria-label="<?php echo esc_attr__( 'Analytics period', 'opti-behavior' ); ?>">
						<?php foreach ( Opti_Behavior_Page_Analytics_Repository::get_standard_periods() as $period_value => $period_label_text ) : ?>
							<option value="<?php echo esc_attr( $period_value ); ?>" <?php selected( $period_value, $analytics_context['period'] ); ?>><?php echo esc_html( $period_label_text ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<div class="optibehavior-analytics-grid">
				<!-- Column 1: Entry Sources -->
				<div class="optibehavior-analytics-column optibehavior-entry-column">
					<div class="optibehavior-column-header">
						<div class="optibehavior-column-icon"><i data-lucide="link" style="width:18px;height:18px;"></i></div>
						<h3 class="optibehavior-column-title"><?php echo esc_html__( 'Entry Sources', 'opti-behavior' ); ?> <?php opti_behavior_tooltip_e( $metabox_tooltips['entry_sources']['title'], $metabox_tooltips['entry_sources']['content'], $metabox_tooltips['entry_sources']['simple'] ); ?></h3>
						<div class="optibehavior-column-subtitle"><?php echo esc_html__( 'How visitors arrive', 'opti-behavior' ); ?></div>
					</div>
					<div class="optibehavior-column-content">
						<div id="optibehavior-referrers-content">
							<div id="optibehavior-referrers-loading" class="optibehavior-loading"><?php echo esc_html__( 'Loading referrer data...', 'opti-behavior' ); ?></div>
							<div id="optibehavior-referrers-list" class="optibehavior-scroll-area"></div>
							<div id="optibehavior-referrers-empty" class="optibehavior-empty-state">
								<i data-lucide="link" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
								<div class="optibehavior-empty-title"><?php echo esc_html__( 'No referrer data available', 'opti-behavior' ); ?></div>
								<div class="optibehavior-empty-sub"><?php echo esc_html__( 'Data will appear once visitors access this page.', 'opti-behavior' ); ?></div>
							</div>
						</div>
					</div>
				</div>

				<!-- Column 2: Visitor Behavior -->
				<div class="optibehavior-analytics-column optibehavior-behavior-column">
					<div class="optibehavior-column-header">
						<div class="optibehavior-column-icon"><i data-lucide="bar-chart-3" style="width:18px;height:18px;"></i></div>
						<h3 class="optibehavior-column-title"><?php echo esc_html__( 'Visitor Behavior', 'opti-behavior' ); ?> <?php opti_behavior_tooltip_e( $metabox_tooltips['visitor_behavior']['title'], $metabox_tooltips['visitor_behavior']['content'], $metabox_tooltips['visitor_behavior']['simple'] ); ?></h3>
						<div class="optibehavior-column-subtitle"><?php echo esc_html__( 'On-page analytics', 'opti-behavior' ); ?></div>
					</div>
					<div class="optibehavior-column-content">
						<div class="optibehavior-behavior-stats">
							<div class="optibehavior-behavior-stat">
								<div class="optibehavior-behavior-label">
									<i data-lucide="clock" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></i>
									<?php echo esc_html__( 'Avg Time on Page', 'opti-behavior' ); ?>
									<?php opti_behavior_tooltip_e( $metabox_tooltips['avg_time_on_page']['title'], $metabox_tooltips['avg_time_on_page']['content'], $metabox_tooltips['avg_time_on_page']['simple'], $metabox_tooltips['avg_time_on_page']['example'], array( 'position' => 'right' ) ); ?>
								</div>
								<div class="optibehavior-behavior-value">
									<span class="optibehavior-stat-badge" id="optibehavior-avg">—</span>
								</div>
							</div>
							<div class="optibehavior-behavior-stat">
								<div class="optibehavior-behavior-label">
									<i data-lucide="mouse-pointer-click" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></i>
									<?php echo esc_html__( 'Total Interactions', 'opti-behavior' ); ?>
									<?php opti_behavior_tooltip_e( $metabox_tooltips['total_interactions']['title'], $metabox_tooltips['total_interactions']['content'], $metabox_tooltips['total_interactions']['simple'], '', array( 'position' => 'right' ) ); ?>
								</div>
								<div class="optibehavior-behavior-value">
									<span class="optibehavior-stat-badge" id="optibehavior-int">—</span>
								</div>
							</div>
							<div class="optibehavior-behavior-stat">
								<div class="optibehavior-behavior-label">
									<i data-lucide="monitor" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></i>
									<?php echo esc_html__( 'Desktop Events', 'opti-behavior' ); ?>
									<?php opti_behavior_tooltip_e( $metabox_tooltips['desktop_events']['title'], $metabox_tooltips['desktop_events']['content'], $metabox_tooltips['desktop_events']['simple'], '', array( 'position' => 'right' ) ); ?>
								</div>
								<div class="optibehavior-behavior-value">
									<span class="optibehavior-stat-badge" id="optibehavior-pc-events">—</span>
								</div>
							</div>
							<div class="optibehavior-behavior-stat">
								<div class="optibehavior-behavior-label">
									<i data-lucide="smartphone" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></i>
									<?php echo esc_html__( 'Mobile Events', 'opti-behavior' ); ?>
									<?php opti_behavior_tooltip_e( $metabox_tooltips['mobile_events']['title'], $metabox_tooltips['mobile_events']['content'], $metabox_tooltips['mobile_events']['simple'], '', array( 'position' => 'right' ) ); ?>
								</div>
								<div class="optibehavior-behavior-value">
									<span class="optibehavior-stat-badge" id="optibehavior-mobile-events">—</span>
								</div>
							</div>
							<div class="optibehavior-behavior-stat">
								<div class="optibehavior-behavior-label">
									<i data-lucide="arrow-down" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></i>
									<?php echo esc_html__( 'Avg. Scroll Depth', 'opti-behavior' ); ?>
									<?php opti_behavior_tooltip_e( $metabox_tooltips['avg_scroll_depth']['title'], $metabox_tooltips['avg_scroll_depth']['content'], $metabox_tooltips['avg_scroll_depth']['simple'], '', array( 'position' => 'right' ) ); ?>
								</div>
								<div class="optibehavior-behavior-value">
									<span class="optibehavior-stat-badge" id="optibehavior-scroll">—</span>
								</div>
							</div>
							<div class="optibehavior-behavior-stat">
								<div class="optibehavior-behavior-label">
									<i data-lucide="log-out" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></i>
									<?php echo esc_html__( 'Bounce Rate', 'opti-behavior' ); ?>
									<?php opti_behavior_tooltip_e( $metabox_tooltips['bounce_rate_page']['title'], $metabox_tooltips['bounce_rate_page']['content'], $metabox_tooltips['bounce_rate_page']['simple'], '', array( 'position' => 'right' ) ); ?>
								</div>
								<div class="optibehavior-behavior-value">
									<span class="optibehavior-stat-badge" id="optibehavior-bounce">—</span>
								</div>
							</div>
							<div class="optibehavior-behavior-stat">
								<div class="optibehavior-behavior-label">
									<i data-lucide="route" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></i>
									<?php echo esc_html__( 'Session Type', 'opti-behavior' ); ?>
									<?php opti_behavior_tooltip_e( $metabox_tooltips['session_type']['title'], $metabox_tooltips['session_type']['content'], $metabox_tooltips['session_type']['simple'], '', array( 'position' => 'right' ) ); ?>
								</div>
								<div class="optibehavior-behavior-value" id="optibehavior-session-type">—</div>
							</div>
							<div class="optibehavior-behavior-stat">
								<div class="optibehavior-behavior-label">
									<i data-lucide="refresh-cw" style="width:14px;height:14px;margin-right:4px;vertical-align:middle;"></i>
									<?php echo esc_html__( 'Last Updated', 'opti-behavior' ); ?>
									<?php opti_behavior_tooltip_e( $metabox_tooltips['last_updated']['title'], $metabox_tooltips['last_updated']['content'], $metabox_tooltips['last_updated']['simple'], '', array( 'position' => 'right' ) ); ?>
								</div>
								<div class="optibehavior-behavior-value" id="optibehavior-upd">—</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Column 3: Exit Behavior -->
				<div class="optibehavior-analytics-column optibehavior-exit-column">
					<div class="optibehavior-column-header">
						<div class="optibehavior-column-icon"><i data-lucide="external-link" style="width:18px;height:18px;"></i></div>
						<h3 class="optibehavior-column-title"><?php echo esc_html__( 'Exit Behavior', 'opti-behavior' ); ?> <?php opti_behavior_tooltip_e( $metabox_tooltips['exit_behavior']['title'], $metabox_tooltips['exit_behavior']['content'], $metabox_tooltips['exit_behavior']['simple'] ); ?></h3>
						<div class="optibehavior-column-subtitle"><?php echo esc_html__( 'Where visitors go', 'opti-behavior' ); ?></div>
					</div>
					<div class="optibehavior-column-content">
						<div id="optibehavior-outbound-content">
							<div id="optibehavior-outbound-loading" class="optibehavior-loading"><?php echo esc_html__( 'Loading outbound click data...', 'opti-behavior' ); ?></div>
							<div id="optibehavior-outbound-list" class="optibehavior-scroll-area"></div>
							<div id="optibehavior-outbound-empty" class="optibehavior-empty-state">
								<i data-lucide="external-link" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
								<div class="optibehavior-empty-title"><?php echo esc_html__( 'No outbound clicks recorded', 'opti-behavior' ); ?></div>
								<div class="optibehavior-empty-sub"><?php echo esc_html__( 'Data will appear once visitors click links on this page.', 'opti-behavior' ); ?></div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- New Analytics Row: Countries, Browsers, Device Types -->
			<div class="optibehavior-analytics-grid optibehavior-secondary-grid">
				<!-- Countries Column -->
				<div class="optibehavior-analytics-column optibehavior-countries-column">
					<div class="optibehavior-column-header">
						<div class="optibehavior-column-icon"><i data-lucide="globe" style="width:18px;height:18px;"></i></div>
						<h3 class="optibehavior-column-title"><?php echo esc_html__( 'Countries', 'opti-behavior' ); ?> <?php opti_behavior_tooltip_e( $metabox_tooltips['countries_page']['title'], $metabox_tooltips['countries_page']['content'], $metabox_tooltips['countries_page']['simple'] ); ?></h3>
					</div>
					<div class="optibehavior-column-content">
						<div id="optibehavior-countries-content">
							<div id="optibehavior-countries-table" class="optibehavior-scroll-area"></div>
							<div id="optibehavior-countries-empty" class="optibehavior-empty-state">
								<i data-lucide="globe" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
								<div class="optibehavior-empty-title"><?php echo esc_html__( 'No country data available', 'opti-behavior' ); ?></div>
								<div class="optibehavior-empty-sub"><?php echo esc_html__( 'Data will appear once visitors access this page.', 'opti-behavior' ); ?></div>
							</div>
						</div>
					</div>
				</div>

				<!-- Browsers Column -->
				<div class="optibehavior-analytics-column optibehavior-browsers-column">
					<div class="optibehavior-column-header">
						<div class="optibehavior-column-icon"><i data-lucide="compass" style="width:18px;height:18px;"></i></div>
						<h3 class="optibehavior-column-title"><?php echo esc_html__( 'Browsers', 'opti-behavior' ); ?> <?php opti_behavior_tooltip_e( $metabox_tooltips['browsers_page']['title'], $metabox_tooltips['browsers_page']['content'], $metabox_tooltips['browsers_page']['simple'] ); ?></h3>
					</div>
					<div class="optibehavior-column-content">
						<div id="optibehavior-browsers-content">
							<div id="optibehavior-browsers-table" class="optibehavior-scroll-area"></div>
							<div id="optibehavior-browsers-empty" class="optibehavior-empty-state">
								<i data-lucide="compass" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
								<div class="optibehavior-empty-title"><?php echo esc_html__( 'No browser data available', 'opti-behavior' ); ?></div>
								<div class="optibehavior-empty-sub"><?php echo esc_html__( 'Data will appear once visitors access this page.', 'opti-behavior' ); ?></div>
							</div>
						</div>
					</div>
				</div>

				<!-- Device Types Column -->
				<div class="optibehavior-analytics-column optibehavior-devices-column">
					<div class="optibehavior-column-header">
						<div class="optibehavior-column-icon"><i data-lucide="monitor-smartphone" style="width:18px;height:18px;"></i></div>
						<h3 class="optibehavior-column-title"><?php echo esc_html__( 'Device Types', 'opti-behavior' ); ?> <?php opti_behavior_tooltip_e( $metabox_tooltips['devices_page']['title'], $metabox_tooltips['devices_page']['content'], $metabox_tooltips['devices_page']['simple'] ); ?></h3>
					</div>
					<div class="optibehavior-column-content">
						<div id="optibehavior-devices-content">
							<div id="optibehavior-devices-table" class="optibehavior-scroll-area"></div>
							<div id="optibehavior-devices-empty" class="optibehavior-empty-state">
								<i data-lucide="monitor-smartphone" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
								<div class="optibehavior-empty-title"><?php echo esc_html__( 'No device data available', 'opti-behavior' ); ?></div>
								<div class="optibehavior-empty-sub"><?php echo esc_html__( 'Data will appear once visitors access this page.', 'opti-behavior' ); ?></div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Controls Row -->
			<div class="optibehavior-controls-row">
				<div class="optibehavior-heatmap-controls">
					<?php
					$heatmap_page_id   = $analytics_context['page_id'];
					$heatmap_page_ids  = ! empty( $analytics_context['page_ids'] ) ? implode( ',', array_values( array_filter( array_map( 'absint', $analytics_context['page_ids'] ) ) ) ) : '';

					if ( $heatmap_page_id ) :
						$heatmap_url = add_query_arg(
							array(
								'page'       => 'opti-behavior-heatmap-detail',
								'page_id'    => $heatmap_page_id,
								'page_ids'   => $heatmap_page_ids,
								'type'       => 'click',
								'device'     => 'desktop',
								'date_range' => $analytics_context['period'],
							),
							admin_url( 'admin.php' )
						);
					?>
						<div class="optibehavior-analytics-actions">
							<div class="optibehavior-heatmap-links">
								<a href="<?php echo esc_url( $heatmap_url ); ?>" class="optibehavior-heatmap-link" target="_blank">
									<i data-lucide="flame" style="width:16px;height:16px;vertical-align:middle;"></i>
									<span><?php echo esc_html__( 'View Heatmap', 'opti-behavior' ); ?></span>
								</a>
								<?php opti_behavior_tooltip_e( $metabox_tooltips['view_heatmap']['title'], $metabox_tooltips['view_heatmap']['content'], $metabox_tooltips['view_heatmap']['simple'], '', array( 'position' => 'bottom' ) ); ?>
							</div>
						</div>
					<?php endif; ?>
					<div class="optibehavior-time-filters">
						<a data-range="24h" class="optibehavior-time-filter active" title="<?php echo esc_attr__( 'Last 24 Hours', 'opti-behavior' ); ?>"><?php echo esc_html__( '24h', 'opti-behavior' ); ?></a>
						<a data-range="7d" class="optibehavior-time-filter" title="<?php echo esc_attr__( 'Last 7 Days', 'opti-behavior' ); ?>"><?php echo esc_html__( '7d', 'opti-behavior' ); ?></a>
						<a data-range="30d" class="optibehavior-time-filter" title="<?php echo esc_attr__( 'Last 30 Days', 'opti-behavior' ); ?>"><?php echo esc_html__( '30d', 'opti-behavior' ); ?></a>
						<a data-range="90d" class="optibehavior-time-filter" title="<?php echo esc_attr__( 'Last 3 Months', 'opti-behavior' ); ?>"><?php echo esc_html__( '3mo', 'opti-behavior' ); ?></a>
						<a data-range="all" class="optibehavior-time-filter" title="<?php echo esc_attr__( 'All Time', 'opti-behavior' ); ?>"><?php echo esc_html__( 'All', 'opti-behavior' ); ?></a>
					</div>
					<?php if ( $heatmap_page_id ) : ?>
						<div class="optibehavior-action-buttons">
							<?php if ( opti_behavior_pro_active() ) : ?>
								<?php
								$recordings_url = add_query_arg(
									array(
										'page'       => 'opti-behavior-recordings',
										'page_id'    => $heatmap_page_id,
										'page_ids'   => $heatmap_page_ids,
										'date_range' => $analytics_context['period'],
									),
									admin_url( 'admin.php' )
								);
								?>
								<a href="<?php echo esc_url( $recordings_url ); ?>" class="optibehavior-heatmap-link" target="_blank">
									<i data-lucide="video" style="width:16px;height:16px;vertical-align:middle;"></i>
									<span><?php echo esc_html__( 'View Recordings', 'opti-behavior' ); ?></span>
								</a>
								<?php opti_behavior_tooltip_e( $metabox_tooltips['view_recordings']['title'], $metabox_tooltips['view_recordings']['content'], $metabox_tooltips['view_recordings']['simple'], '', array( 'position' => 'bottom', 'pro' => true ) ); ?>
							<?php else : ?>
								<a href="#" class="optibehavior-heatmap-link optibehavior-pro-only" title="<?php echo esc_attr__( 'Upgrade to Pro to view session recordings', 'opti-behavior' ); ?>">
									<i data-lucide="video" style="width:16px;height:16px;vertical-align:middle;"></i>
									<span><?php echo esc_html__( 'Session Recordings', 'opti-behavior' ); ?></span>
									<span class="optibehavior-pro-badge">PRO</span>
								</a>
								<?php opti_behavior_tooltip_e( $metabox_tooltips['view_recordings']['title'], $metabox_tooltips['view_recordings']['content'], $metabox_tooltips['view_recordings']['simple'], '', array( 'position' => 'bottom', 'pro' => true ) ); ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<!-- Hidden elements for JavaScript compatibility -->
					<div style="display: none;">
						<a id="optibehavior-view-pc" href="#" target="_blank"><span></span></a>
						<a id="optibehavior-view-mob" href="#" target="_blank"><span></span></a>
					</div>
				</div>
				<div id="optibehavior-chart-loading" class="optibehavior-loading-state" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 20px; gap: 12px;">
					<span class="spinner is-active"></span>
					<span style="color: #64748b; font-size: 14px;"><?php echo esc_html__( 'Loading analytics data...', 'opti-behavior' ); ?></span>
				</div>
				<canvas id="optibehavior-chart" height="280" style="display: none;"></canvas>
				<div id="optibehavior-empty" class="optibehavior-empty-state" style="display: none;">
					<i data-lucide="bar-chart-3" style="width: 48px; height: 48px; color: #9ca3af; stroke-width: 1.5;"></i>
					<div class="optibehavior-empty-title"><?php echo esc_html__( 'No heatmap data yet', 'opti-behavior' ); ?></div>
					<div class="optibehavior-empty-sub"><?php echo esc_html__( 'Data will appear once visitors access this page.', 'opti-behavior' ); ?></div>
				</div>
			</div>
		</div>
		<?php
		// Note: Metabox analytics script is now properly enqueued via wp_enqueue_script() in enqueue_metabox_assets() method
		// Script data is localized via wp_localize_script() in enqueue_metabox_assets() method
	}

	/**
	 * AJAX handler for editor analytics box
	 */
	public function ajax_post_analytics() {
		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No post_id provided', 'opti-behavior' ) ) );
		}

		$post_id = intval( $_POST['post_id'] );
		check_ajax_referer( 'optibehavior_post_analytics_' . $post_id, 'nonce' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Permission denied', 'opti-behavior' ) ) );
		}

		$period  = $this->get_requested_period();
		$context = $this->get_post_analytics_context( $post_id, $period );

		// Check the canonical parent post so revisions inherit their published page's analytics.
		$post_status = get_post_status( $context['canonical_post_id'] );
		if ( 'publish' !== $post_status ) {
			wp_send_json_error(
				array(
					'message'       => esc_html__( 'Post not published yet', 'opti-behavior' ),
					'not_published' => true,
					'post_status'   => $post_status,
				)
			);
		}

		if ( empty( $context['canonical_url'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No permalink found', 'opti-behavior' ) ) );
		}

		$metrics = $context['repository']->get_page_metrics(
			array(
				'post_id'       => $post_id,
				'period'        => $context['period'],
				'traffic_scope' => $context['traffic_scope'],
				'prefer_pro'    => true,
			)
		);

		$pc    = $this->count_canonical_events( $context, 16 );
		$mb    = $this->count_canonical_events( $context, 17 );
		$total = max( absint( $metrics['interactions'] ), $pc + $mb );

		$last_updated = $this->format_last_updated( $this->get_canonical_last_updated( $context ) );

		global $wpdb;
		$new_sessions       = 0;
		$returning_sessions = 0;
		$session_type_total = 0;

		if ( ! empty( $context['page_ids'] ) ) {
			$page_clause = $this->build_page_ids_clause( 'pv.page_id', $context['page_ids'] );
			$date_clause = $this->build_date_clause( 'pv.view_time', $context['date_range'] );
			$traffic     = $context['repository']->get_traffic_scope_sql( 's', $context['traffic_scope'] )['sql'];
			$params      = array_merge( $context['page_ids'], $this->get_date_params( $context['date_range'] ) );

			$session_type_counts = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COUNT(DISTINCT pv.session_id) AS total_sessions,
						COUNT(DISTINCT CASE WHEN prev.id IS NULL THEN pv.session_id END) AS new_sessions,
						COUNT(DISTINCT CASE WHEN prev.id IS NOT NULL THEN pv.session_id END) AS returning_sessions
					FROM {$wpdb->prefix}optibehavior_pageviews pv
					LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON s.id = pv.session_id
					LEFT JOIN {$wpdb->prefix}optibehavior_sessions prev
						ON prev.visitor_id = s.visitor_id
						AND prev.id <> s.id
						AND (
							prev.start_time < s.start_time
							OR (prev.start_time = s.start_time AND prev.id < s.id)
						)
					WHERE {$page_clause}{$date_clause}{$traffic}
						AND pv.session_id IS NOT NULL
						AND pv.session_id <> ''",
					...$params
				)
			);

			if ( $session_type_counts ) {
				$session_type_total = absint( $session_type_counts->total_sessions );
				$new_sessions       = absint( $session_type_counts->new_sessions );
				$returning_sessions = absint( $session_type_counts->returning_sessions );
			}
		}

		$classified_sessions = $new_sessions + $returning_sessions;
		if ( $session_type_total > $classified_sessions ) {
			$new_sessions += ( $session_type_total - $classified_sessions );
		}

		$total_session_types   = $new_sessions + $returning_sessions;
		$returning_session_pct = $total_session_types > 0 ? round( ( $returning_sessions / $total_session_types ) * 100 ) : 0;
		$mobile_pct     = $total > 0 ? round( ( $mb / $total ) * 100 ) : 0;
		$page_id2       = $context['page_id'];
		$url            = $context['canonical_url'];
		$pc_url         = $page_id2 ? $url . ( ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'opti-behavior=click_pc-' . $page_id2 ) : '';
		$mb_url         = $page_id2 ? $url . ( ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'opti-behavior=click_mobile-' . $page_id2 . '&mobile_view=1&vw=375&vh=667' ) : '';

		wp_send_json_success(
			array(
				// Backward-compatible keys consumed by metabox-analytics.js.
				'total_interactions'   => $total,
				'pc_clicks'            => $pc,
				'mobile_clicks'        => $mb,
				'avg_time'             => absint( round( $metrics['avg_time_seconds'] ) ),
				'avg_scroll_depth'     => round( (float) $metrics['scroll_depth_percent'], 1 ),
				'bounce_rate'          => round( (float) $metrics['bounce_rate_percent'], 1 ),
				'mobile_pct'           => $mobile_pct,
				'new_visitors'         => $new_sessions,
				'returning_visitors'   => $returning_sessions,
				'returning_pct'        => $returning_session_pct,
				'new_sessions'         => $new_sessions,
				'returning_sessions'   => $returning_sessions,
				'session_type_total'   => $total_session_types,
				'returning_session_pct' => $returning_session_pct,
				'last_updated'         => $last_updated,
				'view_pc_url'          => $pc_url,
				'view_mobile_url'      => $mb_url,
				// Canonical repository contract fields for UI/API parity with the frontend stats bar.
				'visitors'             => $metrics['visitors'],
				'sessions'             => $metrics['sessions'],
				'pageviews'            => $metrics['pageviews'],
				'avg_time_formatted'   => $metrics['avg_time'],
				'scroll_depth'         => $metrics['scroll_depth'],
				'bounce_rate_display'  => $metrics['bounce_rate'],
				'devices'              => $metrics['devices'],
				'pro'                  => $metrics['pro'],
				'page_id'              => $metrics['page_id'],
				'page_ids'             => $metrics['page_ids'],
				'period'               => $metrics['period'],
				'period_label'         => $metrics['period_label'],
				'traffic_scope'        => $metrics['traffic_scope'],
				'source'               => $metrics['source'],
				'contract_version'     => $metrics['contract_version'],
				'canonical_post_id'    => $context['canonical_post_id'],
				'request_post_id'      => $context['request_post_id'],
			)
		);
	}

	/**
	 * AJAX provider for timeseries data (interactions over time)
	 */
	public function ajax_post_timeseries() {
		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error();
		}

		$post_id = absint( $_POST['post_id'] );
		check_ajax_referer( 'optibehavior_post_analytics_' . $post_id, 'nonce' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error();
		}

		$context = $this->get_post_analytics_context( $post_id );
		if ( 'publish' !== get_post_status( $context['canonical_post_id'] ) || empty( $context['page_ids'] ) ) {
			wp_send_json_success(
				array(
					'labels'        => array(),
					'pc'            => array(),
					'mobile'        => array(),
					'group'         => '%Y-%m-%d',
					'page_ids'      => array(),
					'traffic_scope' => $context['traffic_scope'],
				)
			);
		}

		$range_context = $this->get_chart_range_context( isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '7d' );
		$rows          = $this->query_canonical_timeseries_rows( $context, $range_context );
		$labels        = array();
		$pc            = array();
		$mobile        = array();

		foreach ( $rows as $r ) {
			$labels[] = $r['bucket'];
			$pc[]      = absint( $r['pc'] );
			$mobile[]  = absint( $r['mobile'] );
		}

		wp_send_json_success(
			array(
				'labels'        => $labels,
				'pc'            => $pc,
				'mobile'        => $mobile,
				'group'         => $range_context['group_format'],
				'page_ids'      => $context['page_ids'],
				'period'        => $context['period'],
				'traffic_scope' => $context['traffic_scope'],
			)
		);
	}

	/**
	 * AJAX handler for enhanced post analytics chart data.
	 * Returns Total Visitors, Desktop Visitors, Desktop Events, Mobile Visitors, Mobile Events.
	 *
	 * @since 1.0.3
	 */
	public function ajax_post_analytics_chart() {
		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'No post_id provided', 'opti-behavior' ) ) );
		}

		$post_id = absint( $_POST['post_id'] );
		check_ajax_referer( 'optibehavior_post_analytics_' . $post_id, 'nonce' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Permission denied', 'opti-behavior' ) ) );
		}

		$context = $this->get_post_analytics_context( $post_id );

		// Check canonical parent status so revisions use the published parent page.
		if ( 'publish' !== get_post_status( $context['canonical_post_id'] ) || empty( $context['page_ids'] ) ) {
			wp_send_json_success(
				array(
					'labels'           => array(),
					'total_visitors'   => array(),
					'desktop_visitors' => array(),
					'mobile_visitors'  => array(),
					'desktop_events'   => array(),
					'mobile_events'    => array(),
					'group'            => '%Y-%m-%d',
					'page_ids'         => array(),
					'traffic_scope'    => $context['traffic_scope'],
				)
			);
		}

		$range_context = $this->get_chart_range_context( isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '7d' );
		$merged        = $this->query_canonical_chart_rows( $context, $range_context );

		// Preserve the previous 24h UX: if the current day has no buckets, widen to 7 days.
		if ( empty( $merged ) && ! empty( $range_context['fallback_range'] ) ) {
			$range_context['date_range'] = $range_context['fallback_range'];
			$merged                      = $this->query_canonical_chart_rows( $context, $range_context );
		}

		$labels           = array();
		$total_visitors   = array();
		$desktop_visitors = array();
		$mobile_visitors  = array();
		$desktop_events   = array();
		$mobile_events    = array();

		foreach ( $merged as $bucket => $data ) {
			$labels[]           = $bucket;
			$total_visitors[]   = absint( $data['total_visitors'] );
			$desktop_visitors[] = absint( $data['desktop_visitors'] );
			$mobile_visitors[]  = absint( $data['mobile_visitors'] );
			$desktop_events[]   = absint( $data['desktop_events'] );
			$mobile_events[]    = absint( $data['mobile_events'] );
		}

		wp_send_json_success(
			array(
				'labels'           => $labels,
				'total_visitors'   => $total_visitors,
				'desktop_visitors' => $desktop_visitors,
				'mobile_visitors'  => $mobile_visitors,
				'desktop_events'   => $desktop_events,
				'mobile_events'    => $mobile_events,
				'group'            => $range_context['group_format'],
				'page_ids'         => $context['page_ids'],
				'period'           => $context['period'],
				'traffic_scope'    => $context['traffic_scope'],
			)
		);
	}

	/**
	 * AJAX handler for post referrers data
	 */
	public function ajax_post_referrers() {
		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error();
		}

		$post_id = intval( $_POST['post_id'] );
		check_ajax_referer( 'optibehavior_post_analytics_' . $post_id, 'nonce' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error();
		}

		$context = $this->get_post_analytics_context( $post_id, $this->get_requested_period() );
		if ( 'publish' !== get_post_status( $context['canonical_post_id'] ) || empty( $context['page_ids'] ) ) {
			wp_send_json_success( array() );
		}

		$provider_rows = $this->get_provider_dimension_rows( 'referrers', $context );
		if ( null !== $provider_rows ) {
			wp_send_json_success( $provider_rows );
		}

		global $wpdb;

		$page_clause = $this->build_page_ids_clause( 'pv.page_id', $context['page_ids'] );
		$date_clause = $this->build_date_clause( 'pv.view_time', $context['date_range'] );
		$traffic     = $context['repository']->get_traffic_scope_sql( 's', $context['traffic_scope'] )['sql'];
		$params      = array_merge(
			array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( home_url(), PHP_URL_HOST ) ),
			$context['page_ids'],
			$this->get_date_params( $context['date_range'] ),
			array( 10 )
		);

		$referrers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN s.referrer IS NULL OR TRIM(s.referrer) = '' THEN NULL
						WHEN s.referrer LIKE CONCAT('%%', %s, '%%') THEN NULL
						ELSE COALESCE(NULLIF(TRIM(s.referrer), ''), NULL)
					END AS referrer_url,
					CASE
						WHEN s.referrer IS NULL OR TRIM(s.referrer) = '' THEN 'direct'
						WHEN s.referrer LIKE CONCAT('%%', %s, '%%') THEN 'direct'
						ELSE 'external'
					END AS referrer_type,
					COUNT(DISTINCT s.id) AS count,
					COALESCE(NULLIF(TRIM(s.utm_source), ''), NULL) AS utm_source,
					COALESCE(NULLIF(TRIM(s.utm_medium), ''), NULL) AS utm_medium,
					COALESCE(NULLIF(TRIM(s.utm_campaign), ''), NULL) AS utm_campaign,
					MAX(s.start_time) AS last_visit
				FROM {$wpdb->prefix}optibehavior_sessions s
				INNER JOIN {$wpdb->prefix}optibehavior_pageviews pv ON s.id = pv.session_id
				WHERE {$page_clause}{$date_clause}{$traffic}
				GROUP BY referrer_url,
					referrer_type,
					COALESCE(NULLIF(TRIM(s.utm_source), ''), 'NONE'),
					COALESCE(NULLIF(TRIM(s.utm_medium), ''), 'NONE'),
					COALESCE(NULLIF(TRIM(s.utm_campaign), ''), 'NONE')
				ORDER BY count DESC, last_visit DESC
				LIMIT %d",
				...$params
			),
			ARRAY_A
		);

		foreach ( (array) $referrers as $i => $row ) {
			if ( isset( $row['referrer_url'] ) && null !== $row['referrer_url'] ) {
				$referrers[ $i ]['referrer_url'] = self::clean_tracked_url_for_output( $row['referrer_url'] );
			}
		}

		wp_send_json_success( $referrers );
	}

	/**
	 * Visitor-supplied strings leave the server without any character that can
	 * break out of an HTML attribute, whatever version of the admin JS reads
	 * them (rows stored before the ingest fix included).
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function clean_tracked_text_for_output( $value ) {
		return trim( str_replace( array( '"', '<', '>', '`' ), '', wp_strip_all_tags( (string) $value ) ) );
	}

	/**
	 * A stored URL: a well-formed http(s) / mailto / tel URL as esc_url_raw()
	 * returns it, anything else as inert text.
	 *
	 * @param mixed $value Stored URL.
	 * @return string
	 */
	private static function clean_tracked_url_for_output( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^(https?|mailto|tel):/i', $value ) ) {
			$url = esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return self::clean_tracked_text_for_output( $value );
	}

	/**
	 * AJAX handler for post outbound clicks data
	 */
	public function ajax_post_outbound_clicks() {
		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error();
		}

		$post_id = intval( $_POST['post_id'] );
		check_ajax_referer( 'optibehavior_post_analytics_' . $post_id, 'nonce' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error();
		}

		$context = $this->get_post_analytics_context( $post_id, $this->get_requested_period() );
		if ( 'publish' !== get_post_status( $context['canonical_post_id'] ) || empty( $context['page_ids'] ) ) {
			wp_send_json_success( array() );
		}

		global $wpdb;

		$page_clause = $this->build_page_ids_clause( 'oc.page_id', $context['page_ids'] );
		$date_clause = $this->build_date_clause( 'oc.created_at', $context['date_range'] );
		$traffic     = $context['repository']->get_traffic_scope_sql( 's', $context['traffic_scope'] )['sql'];
		$params      = array_merge( $context['page_ids'], $this->get_date_params( $context['date_range'] ) );
		$outbound_clicks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT target_url, click_type, COUNT(*) AS count,
					element_tag, element_text,
					MAX(oc.created_at) AS last_click
				FROM {$wpdb->prefix}optibehavior_outbound_clicks oc
				LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON s.id = oc.session_id
				WHERE {$page_clause}{$date_clause}{$traffic}
				GROUP BY target_url, click_type
				ORDER BY count DESC, last_click DESC
				LIMIT %d",
				...array_merge( $params, array( 10 ) )
			),
			ARRAY_A
		);

		$page_clause = $this->build_page_ids_clause( 'pv.page_id', $context['page_ids'] );
		$date_clause = $this->build_date_clause( 'pv.view_time', $context['date_range'] );
		$traffic     = $context['repository']->get_traffic_scope_sql( 's', $context['traffic_scope'] )['sql'];
		$params      = array_merge( $context['page_ids'], $this->get_date_params( $context['date_range'] ) );

		$total_sessions = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pv.session_id)
					FROM {$wpdb->prefix}optibehavior_pageviews pv
					LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON s.id = pv.session_id
					WHERE {$page_clause}{$date_clause}{$traffic}",
					...$params
				)
			)
		);

		$click_clause = $this->build_page_ids_clause( 'oc.page_id', $context['page_ids'] );
		$click_date_clause = $this->build_date_clause( 'oc.created_at', $context['date_range'] );
		$click_traffic     = $context['repository']->get_traffic_scope_sql( 'ocs', $context['traffic_scope'] )['sql'];
		$click_params      = array_merge( $context['page_ids'], $this->get_date_params( $context['date_range'] ) );
		$sessions_with_clicks = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT oc.session_id)
					FROM {$wpdb->prefix}optibehavior_outbound_clicks oc
					LEFT JOIN {$wpdb->prefix}optibehavior_sessions ocs ON ocs.id = oc.session_id
					WHERE {$click_clause}{$click_date_clause}{$click_traffic}",
					...$click_params
				)
			)
		);

		// Visitor-supplied (CVE-2026-95686): clean rows stored before the ingest fix.
		foreach ( (array) $outbound_clicks as $i => $row ) {
			$outbound_clicks[ $i ]['target_url'] = self::clean_tracked_url_for_output( isset( $row['target_url'] ) ? $row['target_url'] : '' );
			foreach ( array( 'element_tag', 'element_text' ) as $field ) {
				if ( isset( $row[ $field ] ) ) {
					$outbound_clicks[ $i ][ $field ] = self::clean_tracked_text_for_output( $row[ $field ] );
				}
			}
		}

		$sessions_left_without_click = max( 0, $total_sessions - $sessions_with_clicks );

		if ( $sessions_left_without_click > 0 ) {
			$last_left = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(pv.view_time)
					FROM {$wpdb->prefix}optibehavior_pageviews pv
					LEFT JOIN {$wpdb->prefix}optibehavior_sessions s ON s.id = pv.session_id
					WHERE {$page_clause}{$date_clause}{$traffic}
						AND pv.session_id NOT IN (
							SELECT DISTINCT session_id
							FROM {$wpdb->prefix}optibehavior_outbound_clicks oc
							LEFT JOIN {$wpdb->prefix}optibehavior_sessions ocs ON ocs.id = oc.session_id
							WHERE {$click_clause}{$click_date_clause}{$click_traffic}
								AND oc.session_id IS NOT NULL
								AND oc.session_id <> ''
						)",
					...array_merge( $params, $click_params )
				)
			);

			$outbound_clicks[] = array(
				'target_url'   => 'Left page',
				'click_type'   => 'left',
				'element_tag'  => null,
				'element_text' => null,
				'count'        => $sessions_left_without_click,
				'last_click'   => $last_left ?: current_time( 'mysql' ),
			);
		}

		wp_send_json_success( $outbound_clicks );
	}

	/**
	 * AJAX handler for post countries data
	 */
	public function ajax_post_countries() {
		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error();
		}

		$post_id = intval( $_POST['post_id'] );
		check_ajax_referer( 'optibehavior_post_analytics_' . $post_id, 'nonce' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error();
		}

		$context = $this->get_post_analytics_context( $post_id, $this->get_requested_period() );
		if ( 'publish' !== get_post_status( $context['canonical_post_id'] ) || empty( $context['page_ids'] ) ) {
			wp_send_json_success( array() );
		}

		$provider_rows = $this->get_provider_dimension_rows( 'countries', $context );
		if ( null !== $provider_rows ) {
			wp_send_json_success( $provider_rows );
		}

		global $wpdb;
		$page_clause = $this->build_page_ids_clause( 'pv.page_id', $context['page_ids'] );
		$date_clause = $this->build_date_clause( 'pv.view_time', $context['date_range'] );
		$traffic     = $context['repository']->get_traffic_scope_sql( 's', $context['traffic_scope'] )['sql'];
		$params      = array_merge( $context['page_ids'], $this->get_date_params( $context['date_range'] ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT UPPER(COALESCE(NULLIF(TRIM(vis.country),''), '')) AS country,
					vis.country_name,
					COUNT(DISTINCT s.id) AS count
				FROM {$wpdb->prefix}optibehavior_sessions s
				LEFT JOIN {$wpdb->prefix}optibehavior_visitors vis ON s.visitor_id = vis.id
				LEFT JOIN {$wpdb->prefix}optibehavior_pageviews pv ON s.id = pv.session_id
				WHERE {$page_clause}{$date_clause}{$traffic}
					AND COALESCE(NULLIF(TRIM(vis.country),''),'UN') <> 'UN'
				GROUP BY country, vis.country_name
				ORDER BY count DESC
				LIMIT 10",
				...$params
			)
		);

		$data = array();
		foreach ( $results as $row ) {
			$code = strtoupper( $row->country ?: '' );
			// Rows written before the ingest fix may hold a visitor-supplied name
			// or code (CVE-2026-95809): only an ISO code and a clean label go out.
			if ( ! preg_match( '/^[A-Z]{2}$/', $code ) ) {
				$code = '';
			}
			$name = self::clean_tracked_text_for_output( $row->country_name );
			if ( '' === $name ) {
				$name = $code ?: 'Global';
			}
			$data[] = array(
				'country'      => $code ?: 'Global',
				'country_name' => $name,
				'count'        => intval( $row->count ),
			);
		}

		wp_send_json_success( $data );
	}

	/**
	 * AJAX handler for post browsers data
	 */
	public function ajax_post_browsers() {
		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error();
		}

		$post_id = intval( $_POST['post_id'] );
		check_ajax_referer( 'optibehavior_post_analytics_' . $post_id, 'nonce' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error();
		}

		$context = $this->get_post_analytics_context( $post_id, $this->get_requested_period() );
		if ( 'publish' !== get_post_status( $context['canonical_post_id'] ) || empty( $context['page_ids'] ) ) {
			wp_send_json_success( array() );
		}

		$provider_rows = $this->get_provider_dimension_rows( 'browsers', $context );
		if ( null !== $provider_rows ) {
			wp_send_json_success( $provider_rows );
		}

		global $wpdb;
		$page_clause = $this->build_page_ids_clause( 'pv.page_id', $context['page_ids'] );
		$date_clause = $this->build_date_clause( 'pv.view_time', $context['date_range'] );
		$traffic     = $context['repository']->get_traffic_scope_sql( 's', $context['traffic_scope'] )['sql'];
		$params      = array_merge( $context['page_ids'], $this->get_date_params( $context['date_range'] ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN vis.browser IS NULL OR TRIM(vis.browser) = '' THEN 'Unknown'
						WHEN LOWER(vis.browser) IN ('unknown','undefined','other') THEN 'Unknown'
						ELSE TRIM(vis.browser)
					END AS browser,
					COUNT(DISTINCT s.id) AS count
				FROM {$wpdb->prefix}optibehavior_sessions s
				LEFT JOIN {$wpdb->prefix}optibehavior_visitors vis ON s.visitor_id = vis.id
				LEFT JOIN {$wpdb->prefix}optibehavior_pageviews pv ON s.id = pv.session_id
				WHERE {$page_clause}{$date_clause}{$traffic}
				GROUP BY browser
				ORDER BY count DESC
				LIMIT 10",
				...$params
			)
		);

		$data = array();
		foreach ( $results as $row ) {
			$data[] = array(
				'name'  => self::clean_tracked_text_for_output( $row->browser ) ?: 'Unknown',
				'count' => intval( $row->count ),
			);
		}

		wp_send_json_success( $data );
	}

	/**
	 * AJAX handler for post devices data
	 */
	public function ajax_post_devices() {
		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error();
		}

		$post_id = intval( $_POST['post_id'] );
		check_ajax_referer( 'optibehavior_post_analytics_' . $post_id, 'nonce' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error();
		}

		$context = $this->get_post_analytics_context( $post_id, $this->get_requested_period() );
		if ( 'publish' !== get_post_status( $context['canonical_post_id'] ) || empty( $context['page_ids'] ) ) {
			wp_send_json_success( array() );
		}

		$provider_rows = $this->get_provider_dimension_rows( 'devices', $context );
		if ( null !== $provider_rows ) {
			wp_send_json_success( $provider_rows );
		}

		global $wpdb;
		$page_clause = $this->build_page_ids_clause( 'pv.page_id', $context['page_ids'] );
		$date_clause = $this->build_date_clause( 'pv.view_time', $context['date_range'] );
		$traffic     = $context['repository']->get_traffic_scope_sql( 's', $context['traffic_scope'] )['sql'];
		$params      = array_merge( $context['page_ids'], $this->get_date_params( $context['date_range'] ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN vis.device_type IS NULL OR TRIM(vis.device_type) = '' THEN 'Unknown'
						WHEN LOWER(vis.device_type) IN ('unknown','undefined','other') THEN 'Unknown'
						ELSE TRIM(vis.device_type)
					END AS device,
					COUNT(DISTINCT s.id) AS count
				FROM {$wpdb->prefix}optibehavior_sessions s
				LEFT JOIN {$wpdb->prefix}optibehavior_visitors vis ON s.visitor_id = vis.id
				LEFT JOIN {$wpdb->prefix}optibehavior_pageviews pv ON s.id = pv.session_id
				WHERE {$page_clause}{$date_clause}{$traffic}
				GROUP BY device
				ORDER BY count DESC
				LIMIT 10",
				...$params
			)
		);

		$data = array();
		foreach ( $results as $row ) {
			$data[] = array(
				'name'  => self::clean_tracked_text_for_output( $row->device ) ?: 'Unknown',
				'count' => intval( $row->count ),
			);
		}

		wp_send_json_success( $data );
	}

	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
}
