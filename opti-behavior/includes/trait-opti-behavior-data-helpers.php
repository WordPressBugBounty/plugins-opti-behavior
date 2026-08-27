<?php
/**
 * Data Helpers Trait
 *
 * Groups data helper methods extracted from the dashboard class.
 *
 * @package opti-behavior
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard helper SQL fragments are internal traffic-scope clauses and prepared date filters.
if ( ! trait_exists( 'opti_behavior_Data_Helpers_Trait' ) ) {
	/**
	 * Data Helpers Trait
	 *
	 * Provides data retrieval and processing methods for analytics dashboard.
	 *
	 * @since 1.0.0
	 */
	trait Opti_Behavior_Data_Helpers_Trait {

		/**
		 * Get the SQL clause to exclude spam traffic.
		 *
		 * THRESHOLD-BASED: In addition to checking traffic_type, also filters by
		 * duration threshold so that unclassified sessions (heartbeat never fired for
		 * short visits) are correctly excluded even when traffic_type = 'human' (default).
		 *
		 * @since 1.0.5
		 * @param string $table_alias Optional table alias (e.g., 's' for sessions table).
		 * @return string SQL clause like "AND (...)" or empty string.
		 */
		private function get_spam_exclusion_clause( $table_alias = '' ) {
			$exclude_spam = isset( $GLOBALS['opti_behavior_exclude_spam'] ) && $GLOBALS['opti_behavior_exclude_spam'];
			if ( ! $exclude_spam ) {
				return '';
			}

			if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
				return Opti_Behavior_Stats_Spam_Filter::session_sql( $table_alias, 'AND', true );
			}

			$traffic_settings = get_option(
				'opti_behavior_traffic_settings',
				array(
					'spam_detection_enabled'  => true,
					'spam_duration_threshold' => 3,
				)
			);

			if ( is_array( $traffic_settings ) && array_key_exists( 'spam_detection_enabled', $traffic_settings ) && empty( $traffic_settings['spam_detection_enabled'] ) ) {
				return '';
			}

			$prefix        = $table_alias ? $table_alias . '.' : '';
			$spam_duration = isset( $traffic_settings['spam_duration_threshold'] ) ? max( 0, intval( $traffic_settings['spam_duration_threshold'] ) ) : 3;
			$duration_sql  = "(CASE WHEN " . $prefix . "duration > 0 THEN " . $prefix . "duration ELSE TIMESTAMPDIFF(SECOND, " . $prefix . "start_time, COALESCE(" . $prefix . "end_time, " . $prefix . "start_time)) END)";

			return " AND (" . $prefix . "traffic_type IS NULL OR " . $prefix . "traffic_type = '' OR " . $prefix . "traffic_type NOT IN ('spam', 'bot', 'automated')) AND " . $duration_sql . " >= " . $spam_duration;
		}

		/**
		 * Check if spam exclusion is enabled.
		 *
		 * @since 1.0.5
		 * @return bool True if spam should be excluded.
		 */
		private function is_spam_excluded() {
			return isset( $GLOBALS['opti_behavior_exclude_spam'] ) && $GLOBALS['opti_behavior_exclude_spam'];
		}

		/**
		 * SQL condition matching only sessions whose spam classification is final.
		 *
		 * Sessions are inserted with the column defaults traffic_type='human' and
		 * is_bounce=1, and while a session is live (or recently ended) the fresh
		 * spam verdict is deliberately deferred: heartbeats classify with
		 * allow_spam_verdict=false and the verdict only lands via the is_final=1
		 * end beacon or the finalize_stale_sessions_batch() safety-net sweep,
		 * which waits FINALIZE_STALE_GRACE_MINUTES after a session ends. Bots
		 * usually never send the end beacon, so under a bot wave the current day
		 * always contains a rolling pool of not-yet-finalized sessions that still
		 * read as human bounces — inflating today's bucket in the Bounce Rate KPI
		 * and its sparkline history far above every finalized past day.
		 *
		 * This condition scopes bounce-rate numerators AND denominators (when spam
		 * is excluded) to sessions that are past the finalize grace window, i.e.
		 * whose classification the sweep has had a chance to finalize. Today's
		 * bucket then carries the same semantics as every past bucket instead of a
		 * transient spike that decays as classification catches up. The exclusion
		 * is purely window-scoped: once a session ages past the grace window it
		 * counts normally, so genuine human quick bounces are only deferred, never
		 * dropped.
		 *
		 * The cutoff uses the same current_time('mysql') clock basis that stamps
		 * start_time/end_time at ingest (matching finalize_stale_sessions_batch()),
		 * so PHP/DB clock skew cannot shift the window.
		 *
		 * @since 1.7.2
		 * @param string $table_alias Optional sessions table alias (e.g. 's').
		 * @return string Prepared SQL boolean expression (no leading AND).
		 */
		private function get_finalized_sessions_condition( $table_alias = 's' ) {
			global $wpdb;

			$grace_minutes = class_exists( 'Opti_Behavior_Heatmap_Database' )
				? (int) Opti_Behavior_Heatmap_Database::FINALIZE_STALE_GRACE_MINUTES
				: 30;

			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $grace_minutes * MINUTE_IN_SECONDS );

			$table_alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table_alias );
			$prefix      = $table_alias ? $table_alias . '.' : '';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Column prefix sanitized above; cutoff bound via placeholder.
			return $wpdb->prepare( "COALESCE({$prefix}end_time, {$prefix}start_time) < %s", $cutoff );
		}

		/**
		 * Default spam exclusion state controlled by Traffic Behavior settings.
		 *
		 * The Traffic Behavior "Spam Detection" toggle is the product-wide source
		 * of truth. Page-level exclude_spam=0/1 parameters are temporary overrides
		 * for the current screen/request only.
		 *
		 * @return bool True when spam should be excluded by default.
		 */
		private function get_default_spam_exclusion_enabled() {
			if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
				return Opti_Behavior_Stats_Spam_Filter::is_enabled();
			}

			$traffic_settings = get_option( 'opti_behavior_traffic_settings', array(
				'spam_detection_enabled' => true,
			) );

			if ( ! is_array( $traffic_settings ) ) {
				return true;
			}

			return ! array_key_exists( 'spam_detection_enabled', $traffic_settings ) || ! empty( $traffic_settings['spam_detection_enabled'] );
		}

		/**
		 * Resolve spam exclusion from request override or Traffic Behavior default.
		 *
		 * Pages with an Exclude Spam button must allow explicit per-request
		 * overrides. The global Traffic Behavior setting remains the default when
		 * no override is present.
		 *
		 * @param array|null $source Optional request array. Defaults to $_REQUEST.
		 * @return bool True when the current request should exclude spam.
		 */
		private function resolve_spam_exclusion_from_request( $source = null ) {
			if ( null === $source ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only reporting filter; callers pass verified sources for state-changing AJAX.
				$source = $_REQUEST;
			}

			if ( is_array( $source ) && array_key_exists( 'exclude_spam', $source ) ) {
				$value = wp_unslash( $source['exclude_spam'] );
				return in_array( (string) $value, array( '1', 'true', 'yes', 'on' ), true );
			}

			return $this->get_default_spam_exclusion_enabled();
		}

		/**
		 * Decide whether a dashboard widget should use transient cache.
		 *
		 * Small datasets are cheap to calculate and are often still changing during
		 * QA or a new site's first visits. Bypassing cache below the threshold keeps
		 * live widgets accurate while preserving cache protection for larger datasets.
		 *
		 * @since 1.3.8
		 * @param string $start_date Start date (Y-m-d H:i:s).
		 * @param string $end_date   End date (Y-m-d H:i:s).
		 * @param string $widget_key Dashboard widget/cache key.
		 * @return bool True when transient cache should be used.
		 */
		private function should_use_dashboard_cache( $start_date, $end_date, $widget_key ) {
			global $wpdb;

			$minimum_records = (int) apply_filters( 'opti_behavior_dashboard_cache_min_records', 100, $widget_key );
			$minimum_records = max( 1, $minimum_records );
			$sessions_table  = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			$session_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$sessions_table} WHERE start_time BETWEEN %s AND %s",
					$start_date,
					$end_date
				)
			);

			return $session_count >= $minimum_records;
		}

		/**
		 * Get adaptive cache TTL for dashboard widgets.
		 *
		 * @since 1.3.8
		 * @param string $widget_key Dashboard widget/cache key.
		 * @return int Cache TTL in seconds.
		 */
		private function get_dashboard_cache_ttl( $widget_key ) {
			$default_ttls = array(
				'traffic_classification'    => 60,
				'user_intent'               => 60,
				'device_types'              => 900,
				'countries'                 => 900,
				'browsers'                  => 900,
				'operating_systems'         => 900,
				'screen_resolution'         => 900,
				'referrers'                 => 900,
				'top_pages'                 => 900,
				'new_vs_returning_visitors' => 300,
				'visited_directories'       => 300,
				'bot_traffic'               => 300,
			);

			$ttl = isset( $default_ttls[ $widget_key ] ) ? $default_ttls[ $widget_key ] : 300;

			return max( 30, (int) apply_filters( 'opti_behavior_dashboard_cache_ttl', $ttl, $widget_key ) );
		}

		/**
		 * Read a dashboard widget's cached value through the shared caching policy.
		 *
		 * Single choke point every Traffic Overview widget funnels through so none
		 * of them can silently diverge from the caching policy (Bug #4, root cause
		 * 1): bypass (and clear) the transient below the
		 * {@see should_use_dashboard_cache()} record threshold or during an
		 * explicit force-refresh, so small/young datasets and manual refreshes are
		 * always computed live.
		 *
		 * @since 1.7.1
		 * @param string $cache_key  Transient key.
		 * @param string $start_date Effective range start (Y-m-d H:i:s).
		 * @param string $end_date   Effective range end (Y-m-d H:i:s).
		 * @param string $widget_key Widget identifier (see {@see opti_behavior_dashboard_widget_cache_registry()}).
		 * @return mixed|false Cached value, or false when the caller must recompute live.
		 */
		private function get_dashboard_widget_cached_data( $cache_key, $start_date, $end_date, $widget_key ) {
			$use_cache = $this->should_use_dashboard_cache( $start_date, $end_date, $widget_key )
				&& ! ( function_exists( 'opti_behavior_is_force_refresh_requested' ) && opti_behavior_is_force_refresh_requested() );

			if ( ! $use_cache ) {
				delete_transient( $cache_key );
				return false;
			}

			$cached = method_exists( $this, 'get_dashboard_widget_transient' )
				? $this->get_dashboard_widget_transient( $cache_key )
				: get_transient( $cache_key );

			return false !== $cached ? $cached : false;
		}

		/**
		 * Persist a dashboard widget's computed value through the shared caching policy.
		 *
		 * Never freezes an empty result (Bug #4, root cause 1): an empty/zero
		 * result is exactly the state most likely to change on the very next
		 * pageview/session insert, so caching it would recreate the "No data
		 * available" freeze even with {@see should_use_dashboard_cache()} gating
		 * everything else. Also refuses to store below the
		 * should_use_dashboard_cache() record threshold so small datasets always
		 * stay live, mirroring {@see get_dashboard_widget_cached_data()}'s read gate.
		 *
		 * @since 1.7.1
		 * @param string $cache_key  Transient key.
		 * @param mixed  $data       Computed widget data to return (and maybe cache).
		 * @param bool   $is_empty   Whether $data represents "no rows matched."
		 * @param string $start_date Effective range start (Y-m-d H:i:s).
		 * @param string $end_date   Effective range end (Y-m-d H:i:s).
		 * @param string $widget_key Widget identifier.
		 * @return mixed Passthrough of $data, so call sites can `return` the helper directly.
		 */
		private function set_dashboard_widget_cached_data( $cache_key, $data, $is_empty, $start_date, $end_date, $widget_key ) {
			if ( ! $is_empty && $this->should_use_dashboard_cache( $start_date, $end_date, $widget_key ) ) {
				set_transient( $cache_key, $data, $this->get_dashboard_cache_ttl( $widget_key ) );
			}

			return $data;
		}

		/**
		 * Resolve the Analytics Dashboard's effective date range.
		 *
		 * Dashboard quick-period defaults should not render long leading empty history on
		 * first-time or sparse installs. This wrapper keeps the global date resolver
		 * unchanged while moving dashboard-only quick periods forward to the first
		 * available history day.
		 *
		 * @since 1.3.8
		 * @param string      $period         Period identifier.
		 * @param string|null $start_override Optional custom start date.
		 * @param string|null $end_override   Optional custom end date.
		 * @return array Date range with start/end dates.
		 */
		private function get_dashboard_effective_date_range( $period, $start_override = null, $end_override = null ) {
			$normalized_period = class_exists( 'Opti_Behavior_Stats_Date_Range' )
				? Opti_Behavior_Stats_Date_Range::normalize_period( $period )
				: $this->dashboard_normalize_period( $period );

			if ( 'custom' === $normalized_period && $start_override && $end_override ) {
				return $this->get_date_range_impl(
					'custom',
					sanitize_text_field( $start_override ),
					sanitize_text_field( $end_override )
				);
			}

			$date_range          = $this->get_date_range_impl( $normalized_period );
			$dashboard_periods   = array( 'last7days', 'last14days', 'last30days' );
			$normalized_period   = isset( $date_range['period'] ) ? $date_range['period'] : $normalized_period;
			$canonical_start_day = isset( $date_range['start_date'] )
				? $this->dashboard_date_from_value( $date_range['start_date'] )
				: $this->dashboard_date_from_value( isset( $date_range['start'] ) ? $date_range['start'] : '' );

			if ( ! in_array( $normalized_period, $dashboard_periods, true ) || ! $canonical_start_day ) {
				return $date_range;
			}

			$first_data_date = $this->get_first_available_data_date();
			$effective_day   = $first_data_date
				? $this->dashboard_date_from_value( $first_data_date )
				: $this->dashboard_date_from_value( current_time( 'Y-m-d' ) );

			if ( ! $effective_day ) {
				return $date_range;
			}

			if ( $first_data_date && $effective_day <= $canonical_start_day ) {
				return $date_range;
			}

			$effective_end = $this->get_dashboard_sparse_period_end_date( $normalized_period, $effective_day );

			// Never let a sparse-history quick period resolve into the future
			// (Bug #4, root cause 2): the sparse-history end date is meant to stop
			// a long empty *leading* history, not to project the window past
			// "today." Re-derived on every call (not frozen once first data
			// appears), so the window keeps tracking today as the site accumulates
			// history beyond the initial sparse window.
			$today = $this->dashboard_date_from_value( current_time( 'Y-m-d' ) );
			if ( $today && $effective_end > $today ) {
				$effective_end = $today;
			}

			return $this->build_dashboard_effective_date_range(
				$date_range,
				$normalized_period,
				$effective_day,
				$effective_end,
				$first_data_date
			);
		}

		/**
		 * Normalize dashboard period aliases when the canonical resolver is unavailable.
		 *
		 * @since 1.3.8
		 * @param string $period Period identifier.
		 * @return string
		 */
		private function dashboard_normalize_period( $period ) {
			$period = sanitize_key( (string) $period );
			$map    = array(
				'7days'        => 'last7days',
				'last7day'     => 'last7days',
				'last7'        => 'last7days',
				'last_7_days'  => 'last7days',
				'lastweek'     => 'last7days',
				'14days'       => 'last14days',
				'last_14_days' => 'last14days',
				'30days'       => 'last30days',
				'last30'       => 'last30days',
				'last_30_days' => 'last30days',
			);

			return isset( $map[ $period ] ) ? $map[ $period ] : $period;
		}

		/**
		 * Build a dashboard sparse-history end date for a quick period.
		 *
		 * @since 1.3.8
		 * @param string            $period Period identifier.
		 * @param DateTimeImmutable $start  Effective start date.
		 * @return DateTimeImmutable
		 */
		private function get_dashboard_sparse_period_end_date( $period, DateTimeImmutable $start ) {
			switch ( $period ) {
				case 'last7days':
					return $start->modify( '+6 days' );

				case 'last14days':
					return $start->modify( '+13 days' );

				case 'last30days':
					if ( '01' === $start->format( 'd' ) ) {
						return $start->modify( 'last day of this month' );
					}

					return $this->dashboard_same_day_next_month( $start );
			}

			return $start;
		}

		/**
		 * Return the same day in the next month, capped to that month's last day.
		 *
		 * @since 1.3.8
		 * @param DateTimeImmutable $date Source date.
		 * @return DateTimeImmutable
		 */
		private function dashboard_same_day_next_month( DateTimeImmutable $date ) {
			$next_month = $date->modify( 'first day of next month' );
			$last_day   = (int) $next_month->modify( 'last day of this month' )->format( 'd' );
			$target_day = min( (int) $date->format( 'd' ), $last_day );

			return $next_month->setDate(
				(int) $next_month->format( 'Y' ),
				(int) $next_month->format( 'm' ),
				$target_day
			);
		}

		/**
		 * Parse a dashboard date value into an immutable local date.
		 *
		 * @since 1.3.8
		 * @param mixed $value Date-ish value.
		 * @return DateTimeImmutable|null
		 */
		private function dashboard_date_from_value( $value ) {
			if ( $value instanceof DateTimeInterface ) {
				return DateTimeImmutable::createFromInterface( $value )->setTime( 0, 0, 0 );
			}

			$value = trim( (string) $value );
			if ( '' === $value ) {
				return null;
			}

			try {
				return new DateTimeImmutable( substr( $value, 0, 10 ), wp_timezone() );
			} catch ( Exception $e ) {
				return null;
			}
		}

		/**
		 * Apply effective dashboard boundaries while preserving resolver metadata.
		 *
		 * @since 1.3.8
		 * @param array             $date_range      Canonical date range.
		 * @param string            $period          Normalized period.
		 * @param DateTimeImmutable $effective_start Effective start date.
		 * @param DateTimeImmutable $effective_end   Effective end date.
		 * @param string|null       $first_data_date First available history date.
		 * @return array
		 */
		private function build_dashboard_effective_date_range( $date_range, $period, DateTimeImmutable $effective_start, DateTimeImmutable $effective_end, $first_data_date ) {
			$sql_start = $effective_start->setTime( 0, 0, 0 );
			$sql_end   = $effective_end->modify( '+1 day' )->setTime( 0, 0, 0 );

			$date_range['period']          = $period;
			$date_range['start_date']      = $effective_start->format( 'Y-m-d' );
			$date_range['end_date']        = $effective_end->format( 'Y-m-d' );
			$date_range['start']           = $sql_start->format( 'Y-m-d H:i:s' );
			$date_range['end']             = $sql_end->modify( '-1 second' )->format( 'Y-m-d H:i:s' );
			$date_range['sql_start']       = $sql_start->format( 'Y-m-d H:i:s' );
			$date_range['sql_end']         = $sql_end->format( 'Y-m-d H:i:s' );
			$date_range['end_mode']        = 'exclusive';
			$date_range['timezone']        = wp_timezone()->getName();
			$date_range['effective']       = true;
			$date_range['history_aligned'] = true;
			$date_range['first_data_date'] = $first_data_date ? substr( $first_data_date, 0, 10 ) : '';
			$date_range['start_date_obj']  = $effective_start;
			$date_range['end_date_obj']    = $effective_end;

			return $date_range;
		}

		/**
		 * Build inclusive Y-m-d keys for a dashboard SQL date range.
		 *
		 * @since 1.3.8
		 * @param string $start_date Start date-time.
		 * @param string $end_date   End date-time.
		 * @return array
		 */
		private function get_dashboard_daily_date_keys( $start_date, $end_date ) {
			$dates     = array();
			$start_day = substr( (string) $start_date, 0, 10 );
			$end_day   = substr( (string) $end_date, 0, 10 );

			if ( '' === $start_day || '' === $end_day ) {
				return $dates;
			}

			try {
				$cursor = new DateTime( $start_day . ' 00:00:00' );
				$limit  = new DateTime( $end_day . ' 00:00:00' );

				while ( $cursor <= $limit ) {
					$dates[] = $cursor->format( 'Y-m-d' );
					$cursor->modify( '+1 day' );
				}
			} catch ( Exception $e ) {
				return array();
			}

			return $dates;
		}

		/**
		 * Align a daily history metric to a target date list.
		 *
		 * @since 1.3.8
		 * @param array $source_dates  Source history dates.
		 * @param array $source_values Source metric values.
		 * @param array $target_dates  Target dates.
		 * @return array
		 */
		private function align_dashboard_history_metric_to_dates( $source_dates, $source_values, $target_dates ) {
			$value_map     = array();
			$source_dates  = is_array( $source_dates ) ? array_values( $source_dates ) : array();
			$source_values = is_array( $source_values ) ? array_values( $source_values ) : array();
			$target_dates  = is_array( $target_dates ) ? array_values( $target_dates ) : array();

			foreach ( $source_dates as $index => $date ) {
				$date_key = substr( (string) $date, 0, 10 );
				if ( '' === $date_key ) {
					continue;
				}

				$value_map[ $date_key ] = isset( $source_values[ $index ] ) && is_numeric( $source_values[ $index ] )
					? (float) $source_values[ $index ]
					: 0;
			}

			$aligned = array();
			foreach ( $target_dates as $date ) {
				$date_key   = substr( (string) $date, 0, 10 );
				$aligned[] = isset( $value_map[ $date_key ] ) ? $value_map[ $date_key ] : 0;
			}

			return $aligned;
		}

		/**
		 * Get dashboard data for specified period.
		 *
		 * @since 1.0.0
		 * @global wpdb $wpdb WordPress database abstraction object.
		 * @param string      $period         Period identifier.
		 * @param string|null $start_override Optional start date override.
		 * @param string|null $end_override   Optional end date override.
		 * @param array       $filters        Optional sanitized advanced-filters array (allow-listed keys only). Empty = unfiltered (default, backward compatible).
		 * @return array Dashboard data including stats, charts, and realtime data.
		 */
		private function get_dashboard_data_impl( $period, $start_override = null, $end_override = null, array $filters = array() ) {
			global $wpdb;

			$date_range = $this->get_dashboard_effective_date_range( $period, $start_override, $end_override );
			$start_date = $date_range['start'];
			$end_date   = $date_range['end'];

			$traffic_totals = method_exists( $this, 'get_dashboard_traffic_totals' )
				? $this->get_dashboard_traffic_totals( $start_date, $end_date, $filters )
				: array(
					'sessions'  => $this->get_sessions_count( $start_date, $end_date ),
					'visitors'  => $this->get_visitors_count( $start_date, $end_date ),
					'pageviews' => $this->get_pageviews_count( $start_date, $end_date ),
				);
			$stats = array(
				'sessions'         => absint( $traffic_totals['sessions'] ?? 0 ),
				'visitors'         => absint( $traffic_totals['visitors'] ?? 0 ),
				'pageviews'        => absint( $traffic_totals['pageviews'] ?? 0 ),
				'avg_session_time' => $this->get_avg_session_time( $start_date, $end_date, $filters ),
				'avg_scroll_depth' => $this->get_avg_scroll_depth( $start_date, $end_date, $filters ),
				'bounce_rate'      => $this->get_bounce_rate( $start_date, $end_date, $filters ),
			);

			$charts = array(
				'sessions_chart'     => $this->get_sessions_chart_data( $start_date, $end_date, $filters ),
				'top_pages'          => $this->get_top_pages_data( $start_date, $end_date, $filters ),
				'countries'          => $this->get_countries_data( $start_date, $end_date, $filters ),
				'browsers'           => $this->get_browsers_data( $start_date, $end_date, $filters ),
				'device_types'       => $this->get_device_types_data( $start_date, $end_date, $filters ),
				'operating_systems'  => $this->get_operating_systems_data( $start_date, $end_date, $filters ),
				'screen_resolutions' => $this->get_screen_resolution_data( $start_date, $end_date, $filters ),
				'referrers'          => $this->get_referrers_data( $start_date, $end_date, $filters ),
			);

			// Realtime stays exempt from advanced filters (inherently "right now" data).
			$realtime = array(
				'active_visitors' => $this->get_active_visitors(),
				'recent_sessions' => $this->get_recent_sessions( 10 ),
			);

			$duration   = max( 1, strtotime( $end_date ) - strtotime( $start_date ) + 1 );
			$prev_end   = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - 1 );
			$prev_start = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - $duration );
			$prev_traffic_totals = method_exists( $this, 'get_dashboard_traffic_totals' )
				? $this->get_dashboard_traffic_totals( $prev_start, $prev_end, $filters )
				: array(
					'sessions'  => $this->get_sessions_count( $prev_start, $prev_end ),
					'visitors'  => $this->get_visitors_count( $prev_start, $prev_end ),
					'pageviews' => $this->get_pageviews_count( $prev_start, $prev_end ),
				);
			$prev_stats = array(
				'sessions'         => absint( $prev_traffic_totals['sessions'] ?? 0 ),
				'visitors'         => absint( $prev_traffic_totals['visitors'] ?? 0 ),
				'pageviews'        => absint( $prev_traffic_totals['pageviews'] ?? 0 ),
				'avg_session_time' => $this->get_avg_session_time( $prev_start, $prev_end, $filters ),
				'avg_scroll_depth' => $this->get_avg_scroll_depth( $prev_start, $prev_end, $filters ),
				'bounce_rate'      => $this->get_bounce_rate( $prev_start, $prev_end, $filters ),
			);

			$pct = function ( $cur, $prev ) {
				if ( $prev <= 0 ) {
					return $cur > 0 ? 100 : 0;
				}
				return ( ( $cur - $prev ) / $prev ) * 100;
			};

			$changes = array(
				'visitors'         => $pct( $stats['visitors'], $prev_stats['visitors'] ),
				'sessions'         => $pct( $stats['sessions'], $prev_stats['sessions'] ),
				'pageviews'        => $pct( $stats['pageviews'], $prev_stats['pageviews'] ),
				'avg_session_time' => $pct( $stats['avg_session_time'], $prev_stats['avg_session_time'] ),
				'avg_scroll_depth' => $pct( $stats['avg_scroll_depth'], $prev_stats['avg_scroll_depth'] ),
				'bounce_rate'      => $pct( $stats['bounce_rate'], $prev_stats['bounce_rate'] ),
			);

			foreach ( $changes as $k => $v ) {
				$changes[ $k ] = round( (float) $v );
			}

			$user_intent = method_exists( $this, 'get_user_intent_data' ) ? $this->get_user_intent_data( $start_date, $end_date ) : array();

			// Get traffic classification data
			$traffic_classification = method_exists( $this, 'get_traffic_classification_data_impl' ) ? $this->get_traffic_classification_data_impl( $start_date, $end_date ) : array();

			// Get bot traffic data
			$bot_traffic = method_exists( $this, 'get_bot_traffic_data_impl' ) ? $this->get_bot_traffic_data_impl( $start_date, $end_date ) : array();

			// Get new vs returning visitors data
			$new_vs_returning = method_exists( $this, 'get_new_vs_returning_visitors_data_impl' ) ? $this->get_new_vs_returning_visitors_data_impl( $start_date, $end_date ) : array();

			// Get visited directories data
			$visited_directories = method_exists( $this, 'get_visited_directories_data_impl' ) ? $this->get_visited_directories_data_impl( $start_date, $end_date ) : array();

			// Get new registered users data
			$new_registered_users = method_exists( $this, 'get_new_registered_users_data_impl' ) ? $this->get_new_registered_users_data_impl( $start_date, $end_date ) : array();

			return array(
				'stats'                  => $stats,
				'charts'                 => $charts,
				'realtime'               => $realtime,
				'user_intent'            => $user_intent,
				'traffic_classification' => $traffic_classification,
				'bot_traffic'            => $bot_traffic,
				'new_vs_returning'       => $new_vs_returning,
				'visited_directories'    => $visited_directories,
				'new_registered_users'   => $new_registered_users,
				'period'                 => $period,
				'date_range'             => $date_range,
				'changes'                => $changes,
			);
		}

		/**
		 * Get only stats and date range for fast initial page load.
		 * Widget data will be loaded asynchronously via AJAX.
		 *
		 * @since 3.0.7
		 * @param string      $period        Period identifier.
		 * @param string|null $start_override Custom start date.
		 * @param string|null $end_override   Custom end date.
		 * @param array       $filters        Optional sanitized advanced-filters array (allow-listed keys only). Empty = unfiltered (default, backward compatible).
		 * @return array Minimal dashboard data with stats only.
		 */
		private function get_dashboard_stats_only_impl( $period, $start_override = null, $end_override = null, array $filters = array() ) {
			global $wpdb;

			$date_range = $this->get_dashboard_effective_date_range( $period, $start_override, $end_override );
			$start_date = $date_range['start'];
			$end_date   = $date_range['end'];

			$traffic_totals = method_exists( $this, 'get_dashboard_traffic_totals' )
				? $this->get_dashboard_traffic_totals( $start_date, $end_date, $filters )
				: array(
					'sessions'  => $this->get_sessions_count( $start_date, $end_date ),
					'visitors'  => $this->get_visitors_count( $start_date, $end_date ),
					'pageviews' => $this->get_pageviews_count( $start_date, $end_date ),
				);
			$stats = array(
				'sessions'         => absint( $traffic_totals['sessions'] ?? 0 ),
				'visitors'         => absint( $traffic_totals['visitors'] ?? 0 ),
				'pageviews'        => absint( $traffic_totals['pageviews'] ?? 0 ),
				'avg_session_time' => $this->get_avg_session_time( $start_date, $end_date, $filters ),
				'avg_scroll_depth' => $this->get_avg_scroll_depth( $start_date, $end_date, $filters ),
				'bounce_rate'      => $this->get_bounce_rate( $start_date, $end_date, $filters ),
			);

			$duration   = max( 1, strtotime( $end_date ) - strtotime( $start_date ) + 1 );
			$prev_end   = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - 1 );
			$prev_start = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - $duration );
			$prev_traffic_totals = method_exists( $this, 'get_dashboard_traffic_totals' )
				? $this->get_dashboard_traffic_totals( $prev_start, $prev_end, $filters )
				: array(
					'sessions'  => $this->get_sessions_count( $prev_start, $prev_end ),
					'visitors'  => $this->get_visitors_count( $prev_start, $prev_end ),
					'pageviews' => $this->get_pageviews_count( $prev_start, $prev_end ),
				);
			$prev_stats = array(
				'sessions'         => absint( $prev_traffic_totals['sessions'] ?? 0 ),
				'visitors'         => absint( $prev_traffic_totals['visitors'] ?? 0 ),
				'pageviews'        => absint( $prev_traffic_totals['pageviews'] ?? 0 ),
				'avg_session_time' => $this->get_avg_session_time( $prev_start, $prev_end, $filters ),
				'avg_scroll_depth' => $this->get_avg_scroll_depth( $prev_start, $prev_end, $filters ),
				'bounce_rate'      => $this->get_bounce_rate( $prev_start, $prev_end, $filters ),
			);

			$pct = function ( $cur, $prev ) {
				if ( $prev <= 0 ) {
					return $cur > 0 ? 100 : 0;
				}
				return ( ( $cur - $prev ) / $prev ) * 100;
			};

			$changes = array(
				'visitors'         => $pct( $stats['visitors'], $prev_stats['visitors'] ),
				'sessions'         => $pct( $stats['sessions'], $prev_stats['sessions'] ),
				'pageviews'        => $pct( $stats['pageviews'], $prev_stats['pageviews'] ),
				'avg_session_time' => $pct( $stats['avg_session_time'], $prev_stats['avg_session_time'] ),
				'avg_scroll_depth' => $pct( $stats['avg_scroll_depth'], $prev_stats['avg_scroll_depth'] ),
				'bounce_rate'      => $pct( $stats['bounce_rate'], $prev_stats['bounce_rate'] ),
			);

			foreach ( $changes as $k => $v ) {
				$changes[ $k ] = round( (float) $v );
			}

			// Skip slow queries in stats-only mode - they will be loaded via AJAX
			// Get daily history data for stats cards (fast - uses pre-aggregated data).
			// The pre-aggregated daily table has no per-filter breakdown, so when
			// advanced filters are active ALL per-day series are built live: traffic
			// from the filtered canonical timeseries (same grain as the KPI totals),
			// time/scroll/bounce from get_dashboard_filtered_metric_history().
			if ( empty( $filters ) ) {
				$daily_history = $this->get_daily_stats_history( $start_date, $end_date );
			} else {
				$daily_history = array(
					'dates'             => array(),
					'visitors'          => array(),
					'sessions'          => array(),
					'pageviews'         => array(),
					'avg_session_time'  => array(),
					'avg_scroll_depth'  => array(),
					'bounce_rate'       => array(),
				);
				$filtered_series = method_exists( $this, 'get_dashboard_traffic_timeseries' )
					? $this->get_dashboard_traffic_timeseries( $start_date, $end_date, $filters )
					: array();
				foreach ( (array) $filtered_series as $point ) {
					$daily_history['dates'][]     = isset( $point['date'] ) ? (string) $point['date'] : '';
					$daily_history['visitors'][]  = absint( $point['visitors'] ?? 0 );
					$daily_history['sessions'][]  = absint( $point['sessions'] ?? 0 );
					$daily_history['pageviews'][] = absint( $point['pageviews'] ?? 0 );
				}
				$metric_history = method_exists( $this, 'get_dashboard_filtered_metric_history' )
					? $this->get_dashboard_filtered_metric_history( $start_date, $end_date, $filters, $daily_history['dates'] )
					: array( 'avg_session_time' => array(), 'avg_scroll_depth' => array(), 'bounce_rate' => array() );
				$daily_history['avg_session_time'] = $metric_history['avg_session_time'];
				$daily_history['avg_scroll_depth'] = $metric_history['avg_scroll_depth'];
				$daily_history['bounce_rate']      = $metric_history['bounce_rate'];
			}

			return array(
				'stats'      => $stats,
				'period'     => $period,
				'date_range' => $date_range,
				'changes'    => $changes,
				'daily_history' => $daily_history,
				// Empty arrays/structures for widgets - will be loaded async
				'charts'                 => array(
					'sessions_chart'     => array(),
					'top_pages'          => array(),
					'countries'          => array(),
					'browsers'           => array(),
					'device_types'       => array(),
					'operating_systems'  => array(),
					'screen_resolutions' => array(),
					'referrers'          => array(),
				),
				'realtime'               => array(
					'active_visitors' => array(),
					'recent_sessions' => array(),
				),
				'user_intent'            => array(),
				'traffic_classification' => array(),
				'bot_traffic'            => array(),
				'new_vs_returning'       => array(),
				'visited_directories'    => array(),
				'new_registered_users'   => array(),
			);
		}

		/**
		 * Get summary stats data for the stats cards (loaded via AJAX for fast page render).
		 *
		 * OPTIMIZED: Reduced from 9 queries to 3 queries by combining aggregations.
		 *
		 * @since 1.0.8.42
		 * @param string $start_date Start date.
		 * @param string $end_date   End date.
		 * @param array  $filters    Optional sanitized advanced-filters array (allow-listed keys only). Empty = unfiltered (default, backward compatible).
		 * @return array Summary stats with current and previous period comparison.
		 */
		private function get_summary_stats_data( $start_date, $end_date, array $filters = array() ) {
			global $wpdb;

			// Advanced filters bypass the cache + the timeseries/bounce-pair perf
			// shortcuts entirely (neither has filter support) and always run live,
			// per user decision (spec.md section 7, item 3). Filtered results are
			// never read from / written to the unfiltered transient cache key.
			if ( ! empty( $filters ) ) {
				$duration   = max( 1, strtotime( $end_date ) - strtotime( $start_date ) + 1 );
				$prev_end   = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - 1 );
				$prev_start = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - $duration );

				// Build the filtered per-day series once, then derive the totals from
				// it (same construction as get_dashboard_traffic_totals()), so the
				// Traffic Overview chart and the KPI cards share one filtered grain.
				$filtered_series = method_exists( $this, 'get_dashboard_traffic_timeseries' )
					? $this->get_dashboard_traffic_timeseries( $start_date, $end_date, $filters )
					: array();
				$traffic_totals  = array( 'sessions' => 0, 'visitors' => 0, 'pageviews' => 0 );
				foreach ( (array) $filtered_series as $point ) {
					$traffic_totals['sessions']  += absint( $point['sessions'] ?? 0 );
					$traffic_totals['visitors']  += absint( $point['visitors'] ?? 0 );
					$traffic_totals['pageviews'] += absint( $point['pageviews'] ?? 0 );
				}
				$sessions       = absint( $traffic_totals['sessions'] ?? 0 );
				$visitors       = absint( $traffic_totals['visitors'] ?? 0 );
				$pageviews      = absint( $traffic_totals['pageviews'] ?? 0 );
				$avg_duration   = (float) $this->get_avg_session_time( $start_date, $end_date, $filters );
				$avg_scroll     = (float) $this->get_avg_scroll_depth( $start_date, $end_date, $filters );
				$bounce_rate    = (float) $this->get_bounce_rate( $start_date, $end_date, $filters );

				$prev_traffic_totals = $this->get_dashboard_traffic_totals( $prev_start, $prev_end, $filters );
				$prev_sessions       = absint( $prev_traffic_totals['sessions'] ?? 0 );
				$prev_visitors       = absint( $prev_traffic_totals['visitors'] ?? 0 );
				$prev_pageviews      = absint( $prev_traffic_totals['pageviews'] ?? 0 );
				$prev_avg_duration   = (float) $this->get_avg_session_time( $prev_start, $prev_end, $filters );
				$prev_avg_scroll     = (float) $this->get_avg_scroll_depth( $prev_start, $prev_end, $filters );
				$prev_bounce_rate    = (float) $this->get_bounce_rate( $prev_start, $prev_end, $filters );

				$stats = array(
					'sessions'         => $sessions,
					'visitors'         => $visitors,
					'pageviews'        => $pageviews,
					'avg_session_time' => $avg_duration,
					'avg_scroll_depth' => $avg_scroll,
					'bounce_rate'      => $bounce_rate,
				);

				$prev_stats = array(
					'sessions'         => $prev_sessions,
					'visitors'         => $prev_visitors,
					'pageviews'        => $prev_pageviews,
					'avg_session_time' => $prev_avg_duration,
					'avg_scroll_depth' => $prev_avg_scroll,
					'bounce_rate'      => $prev_bounce_rate,
				);

				$pct = function ( $cur, $prev ) {
					if ( $prev <= 0 ) {
						return $cur > 0 ? 100 : 0;
					}
					return ( ( $cur - $prev ) / $prev ) * 100;
				};

				$changes = array(
					'visitors'         => round( $pct( $stats['visitors'], $prev_stats['visitors'] ) ),
					'sessions'         => round( $pct( $stats['sessions'], $prev_stats['sessions'] ) ),
					'pageviews'        => round( $pct( $stats['pageviews'], $prev_stats['pageviews'] ) ),
					'avg_session_time' => round( $pct( $stats['avg_session_time'], $prev_stats['avg_session_time'] ) ),
					'avg_scroll_depth' => round( $pct( $stats['avg_scroll_depth'], $prev_stats['avg_scroll_depth'] ) ),
					'bounce_rate'      => round( $pct( $stats['bounce_rate'], $prev_stats['bounce_rate'] ) ),
				);

				// Traffic per-day series comes live from the filtered timeseries so the
				// Traffic Overview chart matches the filtered KPI totals. The remaining
				// sparkline metrics (time/scroll/bounce) are computed live per-day with
				// the same filters (get_dashboard_filtered_metric_history) so all six
				// KPI mini-charts show filtered data. Result is never cached (always
				// live while filtered).
				$filtered_daily = array(
					'dates'     => array(),
					'visitors'  => array(),
					'sessions'  => array(),
					'pageviews' => array(),
				);
				foreach ( (array) $filtered_series as $point ) {
					$filtered_daily['dates'][]     = isset( $point['date'] ) ? (string) $point['date'] : '';
					$filtered_daily['visitors'][]  = absint( $point['visitors'] ?? 0 );
					$filtered_daily['sessions'][]  = absint( $point['sessions'] ?? 0 );
					$filtered_daily['pageviews'][] = absint( $point['pageviews'] ?? 0 );
				}

				$metric_history = method_exists( $this, 'get_dashboard_filtered_metric_history' )
					? $this->get_dashboard_filtered_metric_history( $start_date, $end_date, $filters, $filtered_daily['dates'] )
					: array( 'avg_session_time' => array(), 'avg_scroll_depth' => array(), 'bounce_rate' => array() );

				return array(
					'stats'         => $stats,
					'changes'       => $changes,
					'daily_history' => array(
						'dates'             => $filtered_daily['dates'],
						'visitors'          => $filtered_daily['visitors'],
						'sessions'          => $filtered_daily['sessions'],
						'pageviews'         => $filtered_daily['pageviews'],
						'avg_session_time'  => $metric_history['avg_session_time'],
						'avg_scroll_depth'  => $metric_history['avg_scroll_depth'],
						'bounce_rate'       => $metric_history['bounce_rate'],
					),
				);
			}

			// Check cache first (short TTL; filterable via opti_behavior_dashboard_cache_ttl).
			// Before 1.2.9 this was 15 minutes with no invalidation, causing stat cards
			// to stay stale for up to 15 min after new visitor activity.
			$exclude_spam = $this->is_spam_excluded();
			$spam_policy  = class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ? Opti_Behavior_Stats_Spam_Filter::cache_key( $exclude_spam ) : ( $exclude_spam ? 'exclude' : 'include' );
			$cache_key = 'opti_behavior_summary_stats_' . md5( $start_date . $end_date . ( $exclude_spam ? '1' : '0' ) . '|' . $spam_policy );

			// Honour a force-refresh request (Refresh button) by bypassing the cache.
			if ( function_exists( 'opti_behavior_is_force_refresh_requested' ) && opti_behavior_is_force_refresh_requested() ) {
				delete_transient( $cache_key );
			} else {
				$cached = get_transient( $cache_key );
				if ( false !== $cached ) {
					return $cached;
				}
			}

			// Calculate previous period dates
			$duration   = max( 1, strtotime( $end_date ) - strtotime( $start_date ) + 1 );
			$prev_end   = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - 1 );
			$prev_start = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - $duration );

			$current_series = method_exists( $this, 'get_dashboard_traffic_timeseries' )
				? $this->get_dashboard_traffic_timeseries( $start_date, $end_date )
				: array();

			/*
			 * KPI cards, KPI sparklines, and Traffic Overview must share one traffic
			 * grain. Sessions and Visitors are session-start metrics, so Page Views
			 * is also derived from the selected session universe instead of directly
			 * counting physical pageview rows that may be missing on legacy/consent
			 * sessions.
			 *
			 * PERF (Section-1 speed): the current-period totals are the per-day SUM of
			 * $current_series, which is EXACTLY what get_dashboard_traffic_totals()
			 * computes from the same series. Deriving them inline avoids a second
			 * identical (heavy COUNT DISTINCT + GROUP BY) timeseries query per load.
			 * Values are byte-identical to the previous get_dashboard_traffic_totals()
			 * result by construction.
			 */
			if ( method_exists( $this, 'get_dashboard_traffic_totals' ) ) {
				$traffic_totals = array( 'sessions' => 0, 'visitors' => 0, 'pageviews' => 0 );
				foreach ( (array) $current_series as $point ) {
					$traffic_totals['sessions']  += absint( $point['sessions'] ?? 0 );
					$traffic_totals['visitors']  += absint( $point['visitors'] ?? 0 );
					$traffic_totals['pageviews'] += absint( $point['pageviews'] ?? 0 );
				}
				// Visitors is DISTINCT over the range; the per-day series sum above
				// double-counts multi-day visitors. Use the range-wide DISTINCT count
				// (mirrors get_dashboard_traffic_totals()) so this KPI card matches.
				$traffic_totals['visitors'] = absint( $this->get_visitors_count( $start_date, $end_date ) );
			} else {
				$traffic_totals = array(
					'sessions'  => $this->get_sessions_count( $start_date, $end_date ),
					'visitors'  => $this->get_visitors_count( $start_date, $end_date ),
					'pageviews' => $this->get_pageviews_count( $start_date, $end_date ),
				);
			}
			$sessions     = absint( $traffic_totals['sessions'] ?? 0 );
			$visitors     = absint( $traffic_totals['visitors'] ?? 0 );
			$pageviews    = absint( $traffic_totals['pageviews'] ?? 0 );
			$avg_duration = (float) $this->get_avg_session_time( $start_date, $end_date );
			$avg_scroll   = (float) $this->get_avg_scroll_depth( $start_date, $end_date );

			// PERF (Section-1 speed): compute current + previous bounce rate in ONE
			// sessions scan (conditional aggregate over the contiguous union range)
			// instead of two separate range scans. Byte-identical to two
			// get_bounce_rate() calls. Falls back to two calls if the helper is absent.
			if ( method_exists( $this, 'get_bounce_rate_pair' ) ) {
				$bounce_pair      = $this->get_bounce_rate_pair( $start_date, $end_date, $prev_start, $prev_end );
				$bounce_rate      = (float) $bounce_pair['current'];
				$prev_bounce_rate = (float) $bounce_pair['previous'];
			} else {
				$bounce_rate      = (float) $this->get_bounce_rate( $start_date, $end_date );
				$prev_bounce_rate = (float) $this->get_bounce_rate( $prev_start, $prev_end );
			}

			$prev_traffic_totals = method_exists( $this, 'get_dashboard_traffic_totals' )
				? $this->get_dashboard_traffic_totals( $prev_start, $prev_end )
				: array(
					'sessions'  => $this->get_sessions_count( $prev_start, $prev_end ),
					'visitors'  => $this->get_visitors_count( $prev_start, $prev_end ),
					'pageviews' => $this->get_pageviews_count( $prev_start, $prev_end ),
				);
			$prev_sessions  = absint( $prev_traffic_totals['sessions'] ?? 0 );
			$prev_visitors  = absint( $prev_traffic_totals['visitors'] ?? 0 );
			$prev_pageviews = absint( $prev_traffic_totals['pageviews'] ?? 0 );

			// Previous-period non-traffic metrics. These must use the previous-period
			// boundaries (mirroring the traffic totals above) so the KPI cards show a
			// real period-over-period trend instead of comparing each metric to itself.
			$prev_avg_duration = (float) $this->get_avg_session_time( $prev_start, $prev_end );
			$prev_avg_scroll   = (float) $this->get_avg_scroll_depth( $prev_start, $prev_end );

			// Build stats arrays
			$stats = array(
				'sessions'         => $sessions,
				'visitors'         => $visitors,
				'pageviews'        => $pageviews,
				'avg_session_time' => $avg_duration,
				'avg_scroll_depth' => $avg_scroll,
				'bounce_rate'      => $bounce_rate,
			);

			$prev_stats = array(
				'sessions'         => $prev_sessions,
				'visitors'         => $prev_visitors,
				'pageviews'        => $prev_pageviews,
				'avg_session_time' => $prev_avg_duration,
				'avg_scroll_depth' => $prev_avg_scroll,
				'bounce_rate'      => $prev_bounce_rate,
			);

			// Calculate percentage changes
			$pct = function ( $cur, $prev ) {
				if ( $prev <= 0 ) {
					return $cur > 0 ? 100 : 0;
				}
				return ( ( $cur - $prev ) / $prev ) * 100;
			};

			$changes = array(
				'visitors'         => round( $pct( $stats['visitors'], $prev_stats['visitors'] ) ),
				'sessions'         => round( $pct( $stats['sessions'], $prev_stats['sessions'] ) ),
				'pageviews'        => round( $pct( $stats['pageviews'], $prev_stats['pageviews'] ) ),
				'avg_session_time' => round( $pct( $stats['avg_session_time'], $prev_stats['avg_session_time'] ) ),
				'avg_scroll_depth' => round( $pct( $stats['avg_scroll_depth'], $prev_stats['avg_scroll_depth'] ) ),
				'bounce_rate'      => round( $pct( $stats['bounce_rate'], $prev_stats['bounce_rate'] ) ),
			);

			// Get daily history data for stat card mini-charts, then override the three
			// core traffic metrics with the canonical Dashboard traffic series so KPI
			// totals, KPI sparklines, and Traffic Overview always share one source.
			$daily_history = $this->get_daily_stats_history( $start_date, $end_date );
			if ( ! is_array( $daily_history ) ) {
				$daily_history = array();
			}
			$history_dates = isset( $daily_history['dates'] ) && is_array( $daily_history['dates'] ) ? $daily_history['dates'] : array();

			$traffic_dates     = array();
			$traffic_visitors  = array();
			$traffic_sessions  = array();
			$traffic_pageviews = array();
			if ( is_array( $current_series ) ) {
				foreach ( $current_series as $point ) {
					$traffic_dates[]     = isset( $point['date'] ) ? (string) $point['date'] : '';
					$traffic_visitors[]  = isset( $point['visitors'] ) ? absint( $point['visitors'] ) : 0;
					$traffic_sessions[]  = isset( $point['sessions'] ) ? absint( $point['sessions'] ) : 0;
					$traffic_pageviews[] = isset( $point['pageviews'] ) ? absint( $point['pageviews'] ) : 0;
				}
			}

			foreach ( array( 'avg_session_time', 'avg_scroll_depth', 'bounce_rate' ) as $metric_key ) {
				$daily_history[ $metric_key ] = $this->align_dashboard_history_metric_to_dates(
					$history_dates,
					isset( $daily_history[ $metric_key ] ) && is_array( $daily_history[ $metric_key ] ) ? $daily_history[ $metric_key ] : array(),
					$traffic_dates
				);
			}

			$daily_history['dates']     = $traffic_dates;
			$daily_history['visitors']  = $traffic_visitors;
			$daily_history['sessions']  = $traffic_sessions;
			$daily_history['pageviews'] = $traffic_pageviews;

			$result = array(
				'stats'         => $stats,
				'changes'       => $changes,
				'daily_history' => $daily_history,
			);

			// Cache for a short TTL (default 30 s, filterable). Invalidated on session insert/update.
			$ttl = function_exists( 'opti_behavior_dashboard_cache_ttl' ) ? opti_behavior_dashboard_cache_ttl() : 30;
			set_transient( $cache_key, $result, $ttl );

			return $result;
		}

		/**
		 * Build the per-day Avg Session Time / Avg Scroll Depth / Bounce Rate
		 * series for the KPI sparklines when advanced filters are active.
		 *
		 * The pre-aggregated daily_stats rollup has no per-filter breakdown, so
		 * these three series are computed live (two GROUP BY DATE() queries: one
		 * over sessions, one over pageviews) with the same spam clause + advanced
		 * filters WHERE fragment the filtered KPI totals use. Per-day semantics
		 * mirror the KPI aggregates: avg duration over duration > 0 sessions,
		 * avg scroll over scroll_depth > 0 pageviews, bounce = bounce/total*100.
		 *
		 * @since 1.8.1.2
		 * @param string $start_date Start date-time (inclusive).
		 * @param string $end_date   End date-time (inclusive).
		 * @param array  $filters    Sanitized advanced filters (non-empty).
		 * @param array  $dates      Day keys (Y-m-d) to align the series to, in order.
		 * @return array { avg_session_time: int[], avg_scroll_depth: float[], bounce_rate: float[] }
		 */
		private function get_dashboard_filtered_metric_history( $start_date, $end_date, array $filters, array $dates ) {
			global $wpdb;

			$out = array(
				'avg_session_time' => array(),
				'avg_scroll_depth' => array(),
				'bounce_rate'      => array(),
			);
			if ( empty( $dates ) || empty( $filters ) ) {
				return $out;
			}

			$spam_clause = $this->get_spam_exclusion_clause( 's' );
			$filter_sql  = $this->build_advanced_filters_sql( $filters );
			$params      = array_merge( array( $start_date, $end_date ), $filter_sql['params'] );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Per-day session metrics (duration + bounce) in one scan.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
			$session_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE(s.start_time) AS d,
					AVG(CASE WHEN s.duration > 0 THEN s.duration ELSE NULL END) AS avg_duration,
					COUNT(*) AS total_sessions,
					SUM(CASE WHEN s.is_bounce = 1 THEN 1 ELSE 0 END) AS bounce_sessions
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
				GROUP BY DATE(s.start_time)",
				$params
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Per-day scroll depth (same predicate as the filtered KPI aggregate).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
			$scroll_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE(pv.view_time) AS d, AVG(pv.scroll_depth) AS avg_scroll
				FROM " . $wpdb->prefix . "optibehavior_pageviews pv
				INNER JOIN " . $wpdb->prefix . "optibehavior_sessions s ON pv.session_id = s.id
				LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
				WHERE pv.view_time BETWEEN %s AND %s AND pv.scroll_depth > 0" . $spam_clause . $filter_sql['where'] . "
				GROUP BY DATE(pv.view_time)",
				$params
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			$by_day = array();
			foreach ( (array) $session_rows as $row ) {
				$total = (int) $row->total_sessions;
				$by_day[ (string) $row->d ] = array(
					'time'   => intval( $row->avg_duration ?: 0 ),
					'bounce' => $total > 0 ? round( ( (int) $row->bounce_sessions / $total ) * 100, 2 ) : 0,
				);
			}
			$scroll_by_day = array();
			foreach ( (array) $scroll_rows as $row ) {
				$scroll_by_day[ (string) $row->d ] = null !== $row->avg_scroll ? round( floatval( $row->avg_scroll ), 1 ) : 0;
			}

			foreach ( $dates as $day ) {
				$day = (string) $day;
				$out['avg_session_time'][] = isset( $by_day[ $day ] ) ? $by_day[ $day ]['time'] : 0;
				$out['bounce_rate'][]      = isset( $by_day[ $day ] ) ? $by_day[ $day ]['bounce'] : 0;
				$out['avg_scroll_depth'][] = isset( $scroll_by_day[ $day ] ) ? $scroll_by_day[ $day ] : 0;
			}

			return $out;
		}

		/**
		 * Get top engaged users data.
		 *
		 * @since 1.0.8.42
		 * @param string $start_date Start date.
		 * @param string $end_date   End date.
		 * @return array Top engaged users.
		 */
		private function get_top_engaged_users_data( $start_date, $end_date, array $filters = array() ) {
			global $wpdb;

			$has_filters = ! empty( $filters );
			$filter_sql  = $has_filters ? $this->build_advanced_filters_sql( $filters ) : array( 'where' => '', 'params' => array() );
			$filter_join = '' !== $filter_sql['where']
				? " LEFT JOIN {$wpdb->prefix}optibehavior_visitors v ON s.visitor_id = v.id"
				: '';

			$spam_clause = $this->get_spam_exclusion_clause( 's' );

			// Check cache first (short TTL; filterable via opti_behavior_dashboard_cache_ttl).
			$exclude_spam = $this->is_spam_excluded();
			if ( $has_filters ) {
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Filtered dashboard aggregate; table name from $wpdb->prefix.
				$visitor_total = max(
					0,
					(int) $wpdb->get_var(
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							"SELECT COUNT(DISTINCT NULLIF(s.visitor_id, ''))
							FROM " . $wpdb->prefix . "optibehavior_sessions s" . $filter_join . "
							WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'],
							array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
						)
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			} else {
				$visitor_total = max( 0, (int) $this->get_visitors_count( $start_date, $end_date ) );
			}
			$row_limit = min( 10, $visitor_total );
			$cache_key = 'opti_behavior_top_users_' . md5( 'v4|' . $start_date . '|' . $end_date . '|' . ( $exclude_spam ? '1' : '0' ) . '|' . $visitor_total . '|' . $row_limit );

			// Honour a force-refresh request (Refresh button) by bypassing the cache.
			// Filtered requests never touch the shared cache.
			if ( $has_filters ) {
				if ( 0 === $row_limit ) {
					return array();
				}
			} elseif ( function_exists( 'opti_behavior_is_force_refresh_requested' ) && opti_behavior_is_force_refresh_requested() ) {
				delete_transient( $cache_key );
			} else {
				$cached = get_transient( $cache_key );
				if ( false !== $cached ) {
					return $cached;
				}
			}

			if ( 0 === $row_limit ) {
				set_transient( $cache_key, array(), function_exists( 'opti_behavior_dashboard_cache_ttl' ) ? opti_behavior_dashboard_cache_ttl() : 30 );
				return array();
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Get top users by engagement score (total_duration + sessions bonus), capped to the Visitors KPI basis.
			// Only include visitors with average session duration >= 60 seconds to ensure quality engagement
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT
					s.visitor_id,
					COUNT(DISTINCT s.id) as total_sessions,
					SUM(s.duration) as total_duration,
					AVG(s.duration) as avg_duration,
					SUM(
						CASE
							WHEN COALESCE(s.page_views, 0) > 0 THEN COALESCE(s.page_views, 0)
							ELSE COALESCE(pvc.pageview_count, 0)
						END
					) as total_pageviews,
					MAX(s.start_time) as last_visit
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				LEFT JOIN (
					SELECT
						session_id,
						COUNT(*) AS pageview_count
					FROM " . $wpdb->prefix . "optibehavior_pageviews
					WHERE view_time BETWEEN %s AND %s
					GROUP BY session_id
				) pvc ON pvc.session_id = s.id" . $filter_join . "
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
				  AND s.visitor_id IS NOT NULL
				  AND s.visitor_id != ''
				  AND s.duration > 0" . $filter_sql['where'] . "
				GROUP BY s.visitor_id
				HAVING AVG(s.duration) >= 60
				ORDER BY (total_duration + (total_sessions * 60)) DESC
				LIMIT %d",
				array_merge(
					array( $start_date, $end_date, $start_date, $end_date ),
					$filter_sql['params'],
					array( $row_limit )
				)
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			$data = array();
			foreach ( $results as $row ) {
				$data[] = array(
					'visitor_id'     => $row->visitor_id,
					'sessions'       => intval( $row->total_sessions ),
					'total_duration' => intval( $row->total_duration ),
					'avg_duration'   => round( floatval( $row->avg_duration ) ),
					'pageviews'      => intval( $row->total_pageviews ),
					'last_visit'     => $row->last_visit,
				);
			}

			$data = array_slice( $data, 0, $row_limit );

			if ( $has_filters ) {
				return $data;
			}

			// Cache for a short TTL (default 30 s, filterable). Invalidated on session insert/update.
			$ttl = function_exists( 'opti_behavior_dashboard_cache_ttl' ) ? opti_behavior_dashboard_cache_ttl() : 30;
			set_transient( $cache_key, $data, $ttl );

			return $data;
		}

		/**
		 * Get session data with pagination and filters.
		 *
		 * @since 1.0.0
		 * @global wpdb $wpdb WordPress database abstraction object.
		 * @param int   $page     Page number.
		 * @param int   $per_page Items per page.
		 * @param array $filters  Filter criteria.
		 * @return array Session data with pagination info.
		 */
		private function get_session_data_impl( $page, $per_page, $filters ) {
			global $wpdb;

			$offset = ( $page - 1 ) * $per_page;

			// Ensure offset and per_page are positive integers
			$offset = absint( $offset );
			$per_page = absint( $per_page );

			$prepare_args  = array();
			$has_date_from = ! empty( $filters['date_from'] );
			$has_date_to   = ! empty( $filters['date_to'] );
			$has_device    = ! empty( $filters['device_type'] );
			$has_country   = ! empty( $filters['country'] );

			$where_conditions = array();
			if ( $has_date_from ) {
				$where_conditions[] = 's.start_time >= %s';
				$prepare_args[]     = $filters['date_from'];
			}
			if ( $has_date_to ) {
				$where_conditions[] = 's.start_time <= %s';
				$prepare_args[]     = $filters['date_to'];
			}
			if ( $has_device ) {
				$where_conditions[] = 'v.device_type = %s';
				$prepare_args[]     = $filters['device_type'];
			}
			if ( $has_country ) {
				$where_conditions[] = 'v.country = %s';
				$prepare_args[]     = $filters['country'];
			}

			$where_clause = ! empty( $where_conditions ) ? implode( ' AND ', $where_conditions ) : '1=1';
			// WHERE clause is safe: built from validated placeholders only (%s), actual values are in $prepare_args

			$prepare_args[] = $per_page;
			$prepare_args[] = $offset;

			$sessions_table = esc_sql( $wpdb->prefix . 'optibehavior_sessions' );
			$visitors_table = esc_sql( $wpdb->prefix . 'optibehavior_visitors' );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are esc_sql escaped, WHERE clause contains only %s placeholders (values in prepare_args)
			$sql = "SELECT
				s.id,
				s.start_time,
				s.end_time,
				s.duration,
				s.page_views,
				s.events_count,
				s.is_bounce,
				s.entry_page,
				s.exit_page,
				v.device_type,
				v.browser,
				v.os,
				v.country,
				v.country_name,
				v.city
			FROM " . $sessions_table . " s
			LEFT JOIN " . $visitors_table . " v ON s.visitor_id = v.id
			WHERE " . $where_clause . "
			ORDER BY s.start_time DESC
			LIMIT %d OFFSET %d";

			// Prepare and execute the query with dynamic number of parameters
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE clause built from validated placeholders, table names are esc_sql escaped, all user inputs properly parameterized
			$sessions = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ) );

			$count_prepare_args = array_slice( $prepare_args, 0, -2 );

			if ( empty( $count_prepare_args ) ) {
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				$total = $wpdb->get_var(
					"SELECT COUNT(*)
					FROM " . $sessions_table . " s
					LEFT JOIN " . $visitors_table . " v ON s.visitor_id = v.id
					WHERE 1=1"
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			} else {
				$count_sql = "SELECT COUNT(*)
				FROM " . $sessions_table . " s
				LEFT JOIN " . $visitors_table . " v ON s.visitor_id = v.id
				WHERE " . $where_clause;

				// Prepare and execute the count query with dynamic number of parameters
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- WHERE clause built from validated placeholders, table names are esc_sql escaped, all user inputs properly parameterized
				$total = $wpdb->get_var( $wpdb->prepare( $count_sql, ...$count_prepare_args ) );
			}

			return array(
				'sessions'    => $sessions,
				'total'       => intval( $total ),
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total / $per_page ),
			);
		}

		/**
		 * Get analytics data by type and period.
		 *
		 * @since 1.0.0
		 * @param string $type   Analytics type.
		 * @param string $period Period identifier.
		 * @return array Analytics data.
		 */
		private function get_analytics_data_impl( $type, $period ) {
			$date_range = $this->get_date_range( $period );

			switch ( $type ) {
				case 'funnel':
					return $this->get_funnel_data( $date_range );
				case 'user_journey':
					return $this->get_user_journey_data( $date_range );
				case 'conversion':
					return $this->get_conversion_data( $date_range );
				case 'retention':
					return $this->get_retention_data( $date_range );
				default:
					return $this->get_overview_analytics( $date_range );
			}
		}

		/**
		 * Get date range for specified period.
		 *
		 * @since 1.0.0
		 * @param string $period Period identifier.
		 * @return array Date range with start/end dates.
		 */
		private function get_date_range_impl( $period, $start_date = '', $end_date = '' ) {
			if ( class_exists( 'Opti_Behavior_Stats_Date_Range' ) ) {
				$range = Opti_Behavior_Stats_Date_Range::resolve( $period, $start_date, $end_date );
				$range['start_date_obj'] = $range['start_date_obj'];
				$range['end_date_obj']   = $range['end_date_obj'];
				return $range;
			}

			$now       = strtotime( current_time( 'mysql' ) );
			$period    = sanitize_key( (string) $period );
			$start     = gmdate( 'Y-m-d 00:00:00', strtotime( '-6 days', $now ) );
			$end       = gmdate( 'Y-m-d 23:59:59', $now );

			switch ( $period ) {
				case 'today':
					$start = gmdate( 'Y-m-d 00:00:00', $now );
					$end   = gmdate( 'Y-m-d 23:59:59', $now );
					break;

				case 'yesterday':
					$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day', $now ) );
					$end   = gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day', $now ) );
					break;

				case 'last14days':
					$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-13 days', $now ) );
					$end   = gmdate( 'Y-m-d 23:59:59', $now );
					break;

				case 'last30days':
					$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-29 days', $now ) );
					$end   = gmdate( 'Y-m-d 23:59:59', $now );
					break;

				case 'last90days':
					$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-89 days', $now ) );
					$end   = gmdate( 'Y-m-d 23:59:59', $now );
					break;

				case 'thismonth':
					$start = gmdate( 'Y-m-01 00:00:00', $now );
					$end   = gmdate( 'Y-m-d 23:59:59', $now );
					break;

				case 'lastmonth':
					$start = gmdate( 'Y-m-01 00:00:00', strtotime( 'first day of last month', $now ) );
					$end   = gmdate( 'Y-m-t 23:59:59', strtotime( 'last day of last month', $now ) );
					break;

				case 'custom':
					if ( $start_date && $end_date ) {
						$start = sanitize_text_field( $start_date ) . ' 00:00:00';
						$end   = sanitize_text_field( $end_date ) . ' 23:59:59';
					}
					break;

				case '7days':
				case 'last7days':
				default:
					$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-6 days', $now ) );
					$end   = gmdate( 'Y-m-d 23:59:59', $now );
					break;
			}

			return array(
				'start'      => $start,
				'end'        => $end,
				'start_date' => gmdate( 'Y-m-d', strtotime( $start ) ),
				'end_date'   => gmdate( 'Y-m-d', strtotime( $end ) ),
			);
		}

		/**
		 * Get the first available data date from the database.
		 * Checks both sessions and pageviews tables to find the earliest data.
		 *
		 * @since 1.0.0
		 * @global wpdb $wpdb WordPress database abstraction object.
		 * @return string|null The first available date in 'Y-m-d H:i:s' format, or null if no data exists.
		 */
		private function get_first_available_data_date_impl() {
			global $wpdb;

			// Get the earliest session start_time
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$first_session = $wpdb->get_var(
				"SELECT MIN(start_time) FROM " . $wpdb->prefix . "optibehavior_sessions"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Get the earliest pageview view_time
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$first_pageview = $wpdb->get_var(
				"SELECT MIN(view_time) FROM " . $wpdb->prefix . "optibehavior_pageviews"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Find the earliest date between the two
			$dates = array_filter( array( $first_session, $first_pageview ) );

			if ( empty( $dates ) ) {
				return null;
			}

			return min( $dates );
		}

		/**
		 * Batch get session counts by page IDs.
		 *
		 * @since 1.0.0
		 * @global wpdb $wpdb WordPress database abstraction object.
		 * @param array       $page_ids   Array of page IDs.
		 * @param string|null $start_date Optional start date.
		 * @param string|null $end_date   Optional end date.
		 * @return array Map of page ID to session count.
		 */
		private function batch_sessions_counts_by_page_impl( $page_ids, $start_date = null, $end_date = null ) {
			global $wpdb;

			if ( empty( $page_ids ) ) {
				return array();
			}

			// Use recordings table to match device split counts (consistent data source)
			$recordings_table     = esc_sql( $wpdb->prefix . 'optibehavior_recordings' );
			$session_pages_table  = esc_sql( $wpdb->prefix . 'optibehavior_session_pages' );
			$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
			$prepare_args = $page_ids;

			// Check if recordings table exists
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '" . $recordings_table . "'" ) === $recordings_table;
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! $table_exists ) {
				// Fallback to pageviews if recordings table doesn't exist (free version)
				$pv_table = esc_sql( $wpdb->prefix . 'optibehavior_pageviews' );
				if ( $start_date && $end_date ) {
					$prepare_args[] = $start_date;
					$prepare_args[] = $end_date;
					$sql = "SELECT pv.page_id, COUNT(DISTINCT pv.session_id) c
					FROM " . $pv_table . " pv
					WHERE pv.page_id IN ($placeholders)
					AND pv.view_time BETWEEN %s AND %s
					GROUP BY pv.page_id";
				} else {
					$sql = "SELECT pv.page_id, COUNT(DISTINCT pv.session_id) c
					FROM " . $pv_table . " pv
					WHERE pv.page_id IN ($placeholders)
					GROUP BY pv.page_id";
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ) );
			} else {
				// Use recordings table for consistent counting with device split
				if ( $start_date && $end_date ) {
					$prepare_args[] = $start_date;
					$prepare_args[] = $end_date;

					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$sql = "SELECT sp.page_id, COUNT(DISTINCT r.session_id) c
					FROM " . $recordings_table . " r
					INNER JOIN " . $session_pages_table . " sp ON sp.session_id = r.session_id
					WHERE sp.page_id IN ($placeholders)
					AND r.start_time BETWEEN %s AND %s
					GROUP BY sp.page_id";

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
					$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ) );
				} else {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$sql = "SELECT sp.page_id, COUNT(DISTINCT r.session_id) c
					FROM " . $recordings_table . " r
					INNER JOIN " . $session_pages_table . " sp ON sp.session_id = r.session_id
					WHERE sp.page_id IN ($placeholders)
					GROUP BY sp.page_id";

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
					$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare_args ) );
				}
			}

			$map = array();
			foreach ( $rows as $r ) {
				$map[ (int) $r->page_id ] = (int) $r->c;
			}
			return $map;
		}

		/**
		 * Get traffic classification data
		 *
		 * @param string $start_date Start date (Y-m-d H:i:s).
		 * @param string $end_date End date (Y-m-d H:i:s).
		 * @return array Traffic classification data.
		 */
		private function get_traffic_classification_data_impl( $start_date, $end_date, array $filters = array() ) {
			global $wpdb;

			$has_filters = ! empty( $filters );
			$filter_sql  = $this->build_advanced_filters_sql( $filters );
			$filter_join = '' !== $filter_sql['where']
				? ' LEFT JOIN ' . $wpdb->prefix . 'optibehavior_visitors v ON s.visitor_id = v.id'
				: '';

			if ( $has_filters ) {
				// Filtered baseline: same live filtered sessions count the KPIs use.
				$baseline = $this->get_sessions_count( $start_date, $end_date, $filters );
			} else {
				$context  = method_exists( $this, 'get_dashboard_stats_context_for_range' )
					? $this->get_dashboard_stats_context_for_range( $start_date, $end_date, 'session' )
					: array(
						'start' => $start_date,
						'end'   => $end_date,
					);
				$baseline = method_exists( $this, 'get_dashboard_dimension_session_baseline' )
					? $this->get_dashboard_dimension_session_baseline( $context )
					: $this->get_sessions_count( $start_date, $end_date );
			}

			/*
			 * In Free+Pro recorded-session scope, the top Sessions KPI uses the
			 * live recorded-count source. Traffic Classification must use that
			 * same source too; otherwise a stale cached Traffic Overview series can
			 * make this widget lag behind the visible KPI, e.g. 62 human sessions
			 * while the Sessions KPI shows 65. Keep the frozen baseline for the
			 * normal all-session dashboard refresh path.
			 */
			if ( ! $has_filters && method_exists( $this, 'should_use_recorded_dashboard_scope' ) && $this->should_use_recorded_dashboard_scope() ) {
				$baseline = $this->get_sessions_count( $start_date, $end_date );
			}

			// Honour the dashboard force-live contract: a force-refresh request must
			// bypass the cache even when the record-count threshold would otherwise
			// allow caching, so this widget never lags behind the live KPIs.
			// Advanced filters always force the live path (cache keys don't encode them).
			$use_cache = ! $has_filters
				&& $this->should_use_dashboard_cache( $start_date, $end_date, 'traffic_classification' )
				&& ! ( function_exists( 'opti_behavior_is_force_refresh_requested' ) && opti_behavior_is_force_refresh_requested() );
			$traffic_settings = get_option( 'opti_behavior_traffic_settings', array(
				'spam_detection_enabled'  => true,
				'spam_duration_threshold' => 3,
			) );
			if ( ! is_array( $traffic_settings ) ) {
				$traffic_settings = array();
			}
			$spam_detection_enabled = ! array_key_exists( 'spam_detection_enabled', $traffic_settings ) || ! empty( $traffic_settings['spam_detection_enabled'] );
			$spam_duration = max( 0, intval( $traffic_settings['spam_duration_threshold'] ?? 3 ) );
			$spam_scrolls  = max( 0, intval( $traffic_settings['spam_min_scrolls_threshold'] ?? 0 ) );
			$spam_clicks   = max( 0, intval( $traffic_settings['spam_min_clicks_threshold'] ?? 1 ) );

			// Check cache only when enough records exist to justify caching.
			$cache_key = 'opti_behavior_traffic_class_' . md5( 'kpi-baseline-v4|' . $start_date . $end_date . '|' . $baseline . '|' . ( $this->is_spam_excluded() ? '1' : '0' ) . '|' . wp_json_encode( $traffic_settings ) );
			if ( $use_cache ) {
				$cached = get_transient( $cache_key );
				if ( false !== $cached ) {
					return $cached;
				}
			} elseif ( ! $has_filters ) {
				delete_transient( $cache_key );
				delete_transient( 'opti_behavior_traffic_class_' . md5( $start_date . $end_date ) );
			}

			$human_traffic_condition = "(s.traffic_type IS NULL OR s.traffic_type = '' OR s.traffic_type NOT IN ('spam', 'bot', 'automated'))";

			// Canonical stored-flag classification. Bucket sessions strictly by the
			// traffic_type the classifier already persisted (ingest heartbeat /
			// session-end beacon / post-event save / repair migration) using the
			// robust multi-source engagement contract in classify_session(). This
			// must NOT re-derive engagement at query time: the old events-only
			// (16/17, 32/33) recompute lagged the canonical classifier and made this
			// widget disagree with the Sessions KPI, count tiles, funnels, and avg
			// tiles — a human-flagged session whose click events had not yet flushed
			// counted as human here but was dropped by the count tiles. Keying only
			// on the stored flag keeps every surface identical.
			$human_condition     = "(" . $human_traffic_condition . ")";
			$spam_clause         = $this->get_spam_exclusion_clause( 's' );
			$automated_condition = "s.traffic_type IN ('bot', 'automated')";
			$spam_condition      = "s.traffic_type = 'spam'";

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			/*
			 * Classification is session-based. Its "human" bucket must match the
			 * same spam exclusion rule used by summary cards, otherwise users see
			 * impossible dashboards like 13 filtered sessions but 21 human sessions.
			 */
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			$traffic_counts = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						SUM(CASE WHEN " . $human_condition . " THEN 1 ELSE 0 END) as human_count,
						SUM(CASE WHEN " . $automated_condition . " THEN 1 ELSE 0 END) as automated_count,
						SUM(CASE WHEN " . $spam_condition . " THEN 1 ELSE 0 END) as spam_count,
						COUNT(*) as total_count
					FROM " . $wpdb->prefix . "optibehavior_sessions s" . $filter_join . "
					WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'],
					array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			/*
			 * Keep the visible distribution tied to the active dashboard baseline,
			 * but count flagged spam sessions from the full selected date range.
			 * When Exclude Spam is enabled, the main query intentionally filters
			 * spam out via $spam_clause; using that same clause for this summary
			 * made the "Spam Traffic / Flagged sessions" card incorrectly show 0.
			 */
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			$detected_spam_counts = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						SUM(CASE WHEN " . $spam_condition . " THEN 1 ELSE 0 END) as spam_count,
						COUNT(*) as total_count
					FROM " . $wpdb->prefix . "optibehavior_sessions s" . $filter_join . "
					WHERE s.start_time BETWEEN %s AND %s" . $filter_sql['where'],
					array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			$detected_spam_count = intval( $detected_spam_counts['spam_count'] ?? 0 );
			$detected_total      = intval( $detected_spam_counts['total_count'] ?? 0 );
			$detected_percentage = $detected_total > 0 ? round( ( $detected_spam_count / $detected_total ) * 100, 1 ) : 0;

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Get spam reason breakdown from the full selected date range.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			$spam_reasons = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						s.spam_reason,
						COUNT(*) as count
					FROM " . $wpdb->prefix . "optibehavior_sessions s" . $filter_join . "
					WHERE s.start_time BETWEEN %s AND %s
						AND s.traffic_type = 'spam'
						AND s.spam_reason IS NOT NULL" . $filter_sql['where'] . "
					GROUP BY s.spam_reason",
					array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Calculate totals and percentages.
			$human_count = intval( $traffic_counts['human_count'] ?? 0 );
			$automated_count = intval( $traffic_counts['automated_count'] ?? 0 );
			$spam_count = intval( $traffic_counts['spam_count'] ?? 0 );
			$bucket_map = array(
				'human'     => $human_count,
				'automated' => $automated_count,
				'spam'      => $spam_count,
			);
			$current_total = $human_count + $automated_count + $spam_count;
			// Filtered runs skip baseline reconciliation: buckets already come from the
			// exact same filtered live query semantics as the filtered KPIs.
			if ( $has_filters ) {
				$baseline = $current_total;
			}
			if ( $baseline > $current_total ) {
				$bucket_map['human'] += $baseline - $current_total;
			} elseif ( $baseline < $current_total && method_exists( $this, 'reduce_dashboard_bucket_overage' ) ) {
				$bucket_map = $this->reduce_dashboard_bucket_overage(
					$bucket_map,
					$current_total - $baseline,
					array( 'human', 'automated', 'spam' )
				);
			}

			// Unified Retention Protocol: merge aggregated traffic-type history
			// for days older than the raw retention window (unfiltered only).
			if ( ! $has_filters && class_exists( 'Opti_Behavior_Dimension_Aggregates' ) ) {
				$pre_raw_segment = Opti_Behavior_Dimension_Aggregates::get_pre_raw_segment( $start_date, $end_date );
				if ( null !== $pre_raw_segment ) {
					$agg_counts = Opti_Behavior_Dimension_Aggregates::get_dimension_counts( 'traffic', $pre_raw_segment['start'], $pre_raw_segment['end'], $this->is_spam_excluded() );
					foreach ( $agg_counts as $agg_value => $agg_row ) {
						$agg_key = strtolower( trim( (string) $agg_value ) );
						if ( isset( $bucket_map[ $agg_key ] ) ) {
							$bucket_map[ $agg_key ] += absint( $agg_row['sessions'] ?? 0 );
						}
					}
				}
			}

			$human_count     = absint( $bucket_map['human'] ?? 0 );
			$automated_count = absint( $bucket_map['automated'] ?? 0 );
			$spam_count      = absint( $bucket_map['spam'] ?? 0 );
			$total           = $human_count + $automated_count + $spam_count;
			$data = array(
				'human'     => array( 'count' => $human_count, 'percentage' => 0 ),
				'automated' => array( 'count' => $automated_count, 'percentage' => 0 ),
				'spam'      => array(
					'count'               => $spam_count,
					'percentage'          => 0,
					'reasons'             => array(),
					'detected_count'      => $detected_spam_count,
					'detected_percentage' => $detected_percentage,
					'detected_total'      => $detected_total,
				),
			);

			// Calculate percentages
			if ( $total > 0 ) {
				foreach ( $data as $type => $info ) {
					$data[ $type ]['percentage'] = round( ( $info['count'] / $total ) * 100, 1 );
				}
			}

			// Add spam reason breakdown
			foreach ( $spam_reasons as $row ) {
				$data['spam']['reasons'][ $row['spam_reason'] ] = intval( $row['count'] );
			}

			$data['total'] = $total;
			$data['human_sessions_baseline'] = absint( $baseline );
			$data['non_human_total'] = $automated_count + $spam_count;
			$data['spam_detected_count'] = $detected_spam_count;
			$data['spam_detected_percentage'] = $detected_percentage;
			$data['all_sessions_total'] = $detected_total;
			$data['context'] = array(
				'metric_grain' => 'session',
				'source_scope' => 'all_sessions',
				'exclude_spam' => $this->is_spam_excluded(),
			);

			// Get previous period data for comparison
			$duration = strtotime( $end_date ) - strtotime( $start_date );
			$prev_end = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - 1 );
			$prev_start = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - $duration );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			$prev_counts = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						SUM(CASE WHEN " . $human_condition . " THEN 1 ELSE 0 END) as human_count,
						SUM(CASE WHEN " . $automated_condition . " THEN 1 ELSE 0 END) as automated_count,
						SUM(CASE WHEN " . $spam_condition . " THEN 1 ELSE 0 END) as spam_count
					FROM " . $wpdb->prefix . "optibehavior_sessions s" . $filter_join . "
					WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'],
					array_merge( array( $prev_start, $prev_end ), $filter_sql['params'] )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			$prev_total = intval( $prev_counts['human_count'] ?? 0 ) + intval( $prev_counts['automated_count'] ?? 0 ) + intval( $prev_counts['spam_count'] ?? 0 );
			if ( $prev_total > 0 ) {
				$data['change_percentage'] = round( ( ( $total - $prev_total ) / $prev_total ) * 100 );
			} else {
				$data['change_percentage'] = $total > 0 ? 100 : 0;
			}

			if ( $use_cache ) {
				set_transient( $cache_key, $data, $this->get_dashboard_cache_ttl( 'traffic_classification' ) );
			}

			return $data;
		}

		/**
		 * Get bot traffic data
		 *
		 * @param string $start_date Start date (Y-m-d H:i:s).
		 * @param string $end_date End date (Y-m-d H:i:s).
		 * @return array Bot traffic data.
		 */
		private function get_bot_traffic_data_impl( $start_date, $end_date ) {
			global $wpdb;

			// Shared cache gate (Bug #4): bypass below the should_use_dashboard_cache()
			// record threshold or on an explicit force-refresh, so this widget never
			// serves a stale/empty value while the rest of the dashboard is live.
			$cache_key = 'opti_behavior_bot_traffic_' . md5( 'per-bot-change-v2|' . $start_date . $end_date );
			$cached    = $this->get_dashboard_widget_cached_data( $cache_key, $start_date, $end_date, 'bot_traffic' );
			if ( false !== $cached ) {
				return $cached;
			}

			// Get bot visit counts by bot type (limited to top 20 for performance)
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$bot_counts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						COALESCE(NULLIF(TRIM(bot_type), ''), %s) AS bot_type,
						COUNT(*) as count
					FROM " . $wpdb->prefix . "optibehavior_bot_visits
					WHERE visit_time BETWEEN %s AND %s
					GROUP BY bot_type
					ORDER BY count DESC
					LIMIT 20",
					__( 'Unknown', 'opti-behavior' ),
					$start_date,
					$end_date
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Calculate total and percentages
			$total = 0;
			$data = array();

			foreach ( $bot_counts as $row ) {
				$bot_type = $row['bot_type'];
				$count = intval( $row['count'] );
				$total += $count;

				$data[ $bot_type ] = array(
					'count' => $count,
					'percentage' => 0,
				);
			}

			// Calculate percentages
			if ( $total > 0 ) {
				foreach ( $data as $bot_type => $info ) {
					$data[ $bot_type ]['percentage'] = round( ( $info['count'] / $total ) * 100, 1 );
				}
			}

			// Get previous period data for comparison (grouped per bot so each row
			// can show its own real trend instead of a hardcoded 100%).
			$duration = strtotime( $end_date ) - strtotime( $start_date );
			$prev_end = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - 1 );
			$prev_start = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - $duration );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$prev_bot_counts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						COALESCE(NULLIF(TRIM(bot_type), ''), %s) AS bot_type,
						COUNT(*) as count
					FROM " . $wpdb->prefix . "optibehavior_bot_visits
					WHERE visit_time BETWEEN %s AND %s
					GROUP BY bot_type",
					__( 'Unknown', 'opti-behavior' ),
					$prev_start,
					$prev_end
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			$prev_by_bot = array();
			$prev_total  = 0;
			foreach ( $prev_bot_counts as $row ) {
				$prev_count                    = intval( $row['count'] );
				$prev_by_bot[ $row['bot_type'] ] = $prev_count;
				$prev_total                   += $prev_count;
			}

			// Per-bot change vs. previous period.
			foreach ( $data as $bot_type => $info ) {
				$prev_count = isset( $prev_by_bot[ $bot_type ] ) ? $prev_by_bot[ $bot_type ] : 0;
				if ( $prev_count > 0 ) {
					$data[ $bot_type ]['change'] = intval( round( ( ( $info['count'] - $prev_count ) / $prev_count ) * 100 ) );
				} else {
					$data[ $bot_type ]['change'] = $info['count'] > 0 ? 100 : 0;
				}
			}

			if ( $prev_total > 0 ) {
				$change_percentage = round( ( ( $total - $prev_total ) / $prev_total ) * 100 );
			} else {
				$change_percentage = $total > 0 ? 100 : 0;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$result = array(
				'total' => $total,
				'bots' => $data,
				'change_percentage' => $change_percentage,
				'human_sessions_baseline' => absint( $this->get_sessions_count( $start_date, $end_date ) ),
				'bot_sessions' => absint(
					$wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*)
							FROM " . $wpdb->prefix . "optibehavior_sessions s
							WHERE s.start_time BETWEEN %s AND %s
							AND s.traffic_type IN ('bot', 'automated')",
							$start_date,
							$end_date
						)
					)
				),
				'context' => array(
					'metric_grain' => 'bot_visit',
					'source_scope' => 'bot_traffic',
					'exclude_spam' => $this->is_spam_excluded(),
				),
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Never freeze a zero-bot result (Bug #4): that's the state most likely
			// to change on the very next bot visit.
			return $this->set_dashboard_widget_cached_data( $cache_key, $result, 0 === $total, $start_date, $end_date, 'bot_traffic' );
		}

		/**
		 * Get new vs returning visitors data
		 *
		 * @param string $start_date Start date (Y-m-d H:i:s).
		 * @param string $end_date End date (Y-m-d H:i:s).
		 * @return array New vs returning visitors data.
		 */
		private function get_new_vs_returning_visitors_data_impl( $start_date, $end_date, array $filters = array() ) {
			global $wpdb;

			$has_filters = ! empty( $filters );

			$cache_key = 'opti_behavior_new_returning_' . md5( 'visitor-baseline-v2|' . $start_date . '|' . $end_date . '|' . ( $this->is_spam_excluded() ? '1' : '0' ) );
			if ( ! $has_filters ) {
				$cached = $this->get_dashboard_widget_cached_data( $cache_key, $start_date, $end_date, 'new_vs_returning_visitors' );
				if ( false !== $cached ) {
					return $cached;
				}
			}

			$current_counts = $this->get_new_vs_returning_counts_for_range( $start_date, $end_date, $filters );

			$duration   = max( 1, strtotime( $end_date ) - strtotime( $start_date ) + 1 );
			$prev_end   = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - 1 );
			$prev_start = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - $duration );
			$prev_counts = $this->get_new_vs_returning_counts_for_range( $prev_start, $prev_end, $filters );

			$new_visitors       = isset( $current_counts['new_visitors'] ) ? absint( $current_counts['new_visitors'] ) : 0;
			$returning_visitors = isset( $current_counts['returning_visitors'] ) ? absint( $current_counts['returning_visitors'] ) : 0;
			$unknown_visitors   = isset( $current_counts['unknown_visitors'] ) ? absint( $current_counts['unknown_visitors'] ) : 0;
			$total_visitors     = isset( $current_counts['total_visitors'] ) ? absint( $current_counts['total_visitors'] ) : ( $new_visitors + $returning_visitors + $unknown_visitors );

			// Keep a strict two-slice UI while ensuring totals reconcile with Visitors KPI.
			$returning_visitors += $unknown_visitors;

			// Reconcile the widget total with the Visitors KPI baseline (sum of per-day
			// distinct visitors) so every widget reports the same visitor number. The
			// unfiltered path uses the canonical baseline helper; the filtered path
			// derives the same basis from the filtered per-day series.
			$visitor_baseline = null;
			if ( ! $has_filters && method_exists( $this, 'get_dashboard_stats_context_for_range' ) && method_exists( $this, 'get_dashboard_dimension_visitor_baseline' ) ) {
				$visitor_context  = $this->get_dashboard_stats_context_for_range( $start_date, $end_date, 'visitor' );
				$visitor_baseline = $this->get_dashboard_dimension_visitor_baseline( $visitor_context );
			} elseif ( $has_filters && method_exists( $this, 'get_dashboard_traffic_timeseries' ) ) {
				$filtered_series  = $this->get_dashboard_traffic_timeseries( $start_date, $end_date, $filters );
				$visitor_baseline = 0;
				foreach ( (array) $filtered_series as $series_point ) {
					$visitor_baseline += isset( $series_point['visitors'] ) ? absint( $series_point['visitors'] ) : 0;
				}
			}

			if ( null !== $visitor_baseline ) {
				if ( $visitor_baseline !== $total_visitors ) {
					if ( $visitor_baseline > $total_visitors ) {
						$returning_visitors += $visitor_baseline - $total_visitors;
					} else {
						$overage = $total_visitors - $visitor_baseline;
						$take    = min( $returning_visitors, $overage );
						$returning_visitors -= $take;
						$overage            -= $take;
						if ( $overage > 0 ) {
							$new_visitors = max( 0, $new_visitors - $overage );
						}
					}

					$total_visitors = $visitor_baseline;
				}
			}

			// Unified Retention Protocol: merge aggregated new/returning history
			// for days older than the raw retention window (unfiltered only).
			if ( ! $has_filters && class_exists( 'Opti_Behavior_Dimension_Aggregates' ) ) {
				$pre_raw_segment = Opti_Behavior_Dimension_Aggregates::get_pre_raw_segment( $start_date, $end_date );
				if ( null !== $pre_raw_segment ) {
					$agg_counts = Opti_Behavior_Dimension_Aggregates::get_dimension_counts( 'visitor_type', $pre_raw_segment['start'], $pre_raw_segment['end'], $this->is_spam_excluded() );
					foreach ( $agg_counts as $agg_value => $agg_row ) {
						$agg_visitors = absint( $agg_row['visitors'] ?? 0 );
						if ( $agg_visitors < 1 ) {
							continue;
						}
						if ( 'new' === $agg_value ) {
							$new_visitors += $agg_visitors;
						} else {
							$returning_visitors += $agg_visitors;
						}
						$total_visitors += $agg_visitors;
					}
				}
			}

			$prev_new       = isset( $prev_counts['new_visitors'] ) ? absint( $prev_counts['new_visitors'] ) : 0;
			$prev_returning = isset( $prev_counts['returning_visitors'] ) ? absint( $prev_counts['returning_visitors'] ) : 0;
			$prev_unknown   = isset( $prev_counts['unknown_visitors'] ) ? absint( $prev_counts['unknown_visitors'] ) : 0;
			$prev_returning += $prev_unknown;

			$new_percentage       = $total_visitors > 0 ? round( ( $new_visitors / $total_visitors ) * 100, 1 ) : 0;
			$returning_percentage = $total_visitors > 0 ? round( ( $returning_visitors / $total_visitors ) * 100, 1 ) : 0;

			$new_change = $prev_new > 0 ? round( ( ( $new_visitors - $prev_new ) / $prev_new ) * 100 ) : ( $new_visitors > 0 ? 100 : 0 );
			$returning_change = $prev_returning > 0 ? round( ( ( $returning_visitors - $prev_returning ) / $prev_returning ) * 100 ) : ( $returning_visitors > 0 ? 100 : 0 );

			$result = array(
				'new_visitors'                => $new_visitors,
				'returning_visitors'          => $returning_visitors,
				'total'                       => $total_visitors,
				'new_percentage'              => $new_percentage,
				'returning_percentage'        => $returning_percentage,
				'new_change'                  => $new_change,
				'returning_change'            => $returning_change,
				'unknown_visitors_collapsed'  => $unknown_visitors,
			);

			if ( $has_filters ) {
				return $result;
			}

			// Never freeze a zero-visitor result (Bug #4): that's the state most
			// likely to change on the very next session insert.
			return $this->set_dashboard_widget_cached_data( $cache_key, $result, 0 === $total_visitors, $start_date, $end_date, 'new_vs_returning_visitors' );
		}

		/**
		 * Resolve new/returning counts for a range using the dashboard session scope.
		 *
		 * @param string $start_date Start date (Y-m-d H:i:s).
		 * @param string $end_date   End date (Y-m-d H:i:s).
		 * @return array
		 */
		private function get_new_vs_returning_counts_for_range( $start_date, $end_date, array $filters = array() ) {
			global $wpdb;

			$has_filters = ! empty( $filters );
			$filter_sql  = $has_filters ? $this->build_advanced_filters_sql( $filters ) : array( 'where' => '', 'params' => array() );

			$base_params = array( $start_date, $end_date );

			if ( ! $has_filters && method_exists( $this, 'should_use_recorded_dashboard_scope' ) && $this->should_use_recorded_dashboard_scope() ) {
				$spam_join  = '';
				$spam_where = '';
				$params     = $base_params;

				if ( method_exists( $this, 'get_default_spam_exclusion_enabled' ) && $this->get_default_spam_exclusion_enabled() && method_exists( $this, 'get_dashboard_recordings_spam_filter_sql' ) ) {
					$recordings_filter = $this->get_dashboard_recordings_spam_filter_sql( 'r', 's', 'dashboard_nvr_scrolls' );
					$spam_join  = isset( $recordings_filter['join'] ) ? $recordings_filter['join'] : '';
					$spam_where = isset( $recordings_filter['where'] ) ? $recordings_filter['where'] : '';
					if ( ! empty( $recordings_filter['values'] ) && is_array( $recordings_filter['values'] ) ) {
						$params = array_merge( $params, $recordings_filter['values'] );
					}
				}

				// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic dashboard filters supply their placeholders via variadic arrays.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard analytics query.
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT
							COUNT(DISTINCT CASE WHEN COALESCE(v.visit_count, 1) <= 1 THEN NULLIF(s.visitor_id, '') END) AS new_visitors,
							COUNT(DISTINCT CASE WHEN COALESCE(v.visit_count, 1) > 1 THEN NULLIF(s.visitor_id, '') END) AS returning_visitors,
							COUNT(DISTINCT NULLIF(s.visitor_id, '')) AS total_visitors
						FROM " . $wpdb->prefix . "optibehavior_recordings r
						LEFT JOIN " . $wpdb->prefix . "optibehavior_sessions s ON r.session_id = s.id
						LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
						" . $spam_join . "
						WHERE r.start_time BETWEEN %s AND %s" . $spam_where,
						...$params
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
				// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			} else {
				$spam_where = $this->get_spam_exclusion_clause( 's' );
				// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic dashboard filters supply their placeholders via variadic arrays.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard analytics query.
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT
							COUNT(DISTINCT CASE WHEN COALESCE(v.visit_count, 1) <= 1 THEN NULLIF(s.visitor_id, '') END) AS new_visitors,
							COUNT(DISTINCT CASE WHEN COALESCE(v.visit_count, 1) > 1 THEN NULLIF(s.visitor_id, '') END) AS returning_visitors,
							COUNT(DISTINCT NULLIF(s.visitor_id, '')) AS total_visitors
						FROM " . $wpdb->prefix . "optibehavior_sessions s
						LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
						WHERE s.start_time BETWEEN %s AND %s" . $spam_where . $filter_sql['where'],
						...array_merge( $base_params, $filter_sql['params'] )
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
				// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			}

			$new_visitors       = isset( $row['new_visitors'] ) ? absint( $row['new_visitors'] ) : 0;
			$returning_visitors = isset( $row['returning_visitors'] ) ? absint( $row['returning_visitors'] ) : 0;
			$total_visitors     = isset( $row['total_visitors'] ) ? absint( $row['total_visitors'] ) : ( $new_visitors + $returning_visitors );
			$unknown_visitors   = max( 0, $total_visitors - ( $new_visitors + $returning_visitors ) );

			return array(
				'new_visitors'       => $new_visitors,
				'returning_visitors' => $returning_visitors,
				'unknown_visitors'   => $unknown_visitors,
				'total_visitors'     => $total_visitors,
			);
		}

		/**
		 * Get lightweight per-section summary teaser values for the dashboard
		 * accordion headers.
		 *
		 * Each value is a single cheap aggregate (top-1 / COUNT) on an indexed
		 * column so this endpoint stays fast even on large data sets and never
		 * re-runs the heavy per-widget queries. No transients are used: the values
		 * are LIVE and EXACT on every load (analytics product rule), honouring the
		 * force-live flag already set by the AJAX dispatcher.
		 *
		 * @since 1.6.11
		 * @param string $start_date Start date (Y-m-d H:i:s).
		 * @param string $end_date   End date (Y-m-d H:i:s).
		 * @return array Map of section key => ordered list of chip arrays.
		 */
		private function get_section_summaries_data( $start_date, $end_date, array $filters = array() ) {
			global $wpdb;

			$has_filters = ! empty( $filters );
			$filter_sql  = $has_filters ? $this->build_advanced_filters_sql( $filters ) : array( 'where' => '', 'params' => array() );
			$filter_join = '' !== $filter_sql['where']
				? " LEFT JOIN {$wpdb->prefix}optibehavior_visitors v ON s.visitor_id = v.id"
				: '';

			// PERF: these teaser chips run ~10 separate GROUP BY scans over the sessions
			// table (top page, peak hour, top type, referrer, country, browser, ...),
			// which measured ~11 s on live. They are header hints, not the live KPIs, so a
			// short transient cache (default 30 s, flushed on new session activity) is a
			// safe, large win. Honour the explicit force-refresh contract so the Refresh
			// button still recomputes fresh values.
			$exclude_spam = $this->is_spam_excluded();
			$cache_key    = 'opti_behavior_section_summaries_' . md5( $start_date . '|' . $end_date . '|' . ( $exclude_spam ? '1' : '0' ) );
			$force_live   = function_exists( 'opti_behavior_is_force_refresh_requested' ) && opti_behavior_is_force_refresh_requested();
			if ( $has_filters ) {
				// Filtered requests are always live; never read or prime the shared cache.
				$force_live = true;
			} elseif ( $force_live ) {
				delete_transient( $cache_key );
			} else {
				$cached = get_transient( $cache_key );
				if ( false !== $cached ) {
					return $cached;
				}
			}

			$spam_clause   = $this->get_spam_exclusion_clause( 's' );
			$sessions_tbl  = $wpdb->prefix . 'optibehavior_sessions';
			$visitors_tbl  = $wpdb->prefix . 'optibehavior_visitors';
			$pageviews_tbl = $wpdb->prefix . 'optibehavior_pageviews';
			$bots_tbl      = $wpdb->prefix . 'optibehavior_bot_visits';

			// --- Section 1: Key Metrics (reuse cheap canonical counts) ---
			if ( $has_filters ) {
				// Match the Visitors KPI basis (sum of per-day distinct visitors) so the
				// chip reports the same number as the KPI card under active filters.
				$visitors = 0;
				if ( method_exists( $this, 'get_dashboard_traffic_timeseries' ) ) {
					$chip_series = $this->get_dashboard_traffic_timeseries( $start_date, $end_date, $filters );
					foreach ( (array) $chip_series as $chip_point ) {
						$visitors += isset( $chip_point['visitors'] ) ? absint( $chip_point['visitors'] ) : 0;
					}
				}
			} else {
				$visitors = absint( $this->get_visitors_count( $start_date, $end_date ) );
			}
			$sessions = absint( $this->get_sessions_count( $start_date, $end_date, $filters ) );
			$bounce   = (float) $this->get_bounce_rate( $start_date, $end_date, $filters );

			$key_metrics = array(
				$this->build_section_chip( 'users', __( 'Visitors', 'opti-behavior' ), number_format_i18n( $visitors ), true ),
				$this->build_section_chip( 'activity', __( 'Sessions', 'opti-behavior' ), number_format_i18n( $sessions ), true ),
				$this->build_section_chip( 'trending-down', __( 'Bounce Rate', 'opti-behavior' ), round( $bounce ) . '%', true ),
			);

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// --- Section 2: Engagement & Content ---
			// Top page by pageviews (matches the Top Pages widget natural metric).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Light dashboard summary aggregate; table name from $wpdb->prefix.
			$top_page = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT MAX(pv.title) AS title, MAX(pv.url) AS url, COUNT(*) AS c
					FROM " . $pageviews_tbl . " pv
					INNER JOIN " . $sessions_tbl . " s ON pv.session_id = s.id" . $filter_join . "
					WHERE pv.view_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
					GROUP BY pv.page_id
					ORDER BY c DESC
					LIMIT 1",
					array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$top_page_label = '';
			if ( $top_page ) {
				$top_page_label = trim( (string) $top_page->title );
				if ( '' === $top_page_label ) {
					$top_page_label = $this->shorten_summary_path( (string) $top_page->url );
				}
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Peak activity hour by session start (indexed on start_time).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Light dashboard summary aggregate; table name from $wpdb->prefix.
			$hour_rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT HOUR(s.start_time) AS h, COUNT(*) AS c
					FROM " . $sessions_tbl . " s" . $filter_join . "
					WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
					GROUP BY h",
					array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			$hour_map = array();
			foreach ( (array) $hour_rows as $hour_row ) {
				if ( null === $hour_row->h ) {
					continue;
				}
				$hour_map[ (int) $hour_row->h % 24 ] = ( $hour_map[ (int) $hour_row->h % 24 ] ?? 0 ) + (int) $hour_row->c;
			}

			// Unified Retention Protocol: merge aggregated hourly-bucket history
			// for days older than the raw retention window (unfiltered only).
			if ( ! $has_filters && class_exists( 'Opti_Behavior_Dimension_Aggregates' ) ) {
				$pre_raw_segment = Opti_Behavior_Dimension_Aggregates::get_pre_raw_segment( $start_date, $end_date );
				if ( null !== $pre_raw_segment ) {
					$agg_hours = Opti_Behavior_Dimension_Aggregates::get_dimension_counts( 'hour', $pre_raw_segment['start'], $pre_raw_segment['end'], $this->is_spam_excluded() );
					foreach ( $agg_hours as $agg_hour => $agg_row ) {
						$h_key              = (int) $agg_hour % 24;
						$hour_map[ $h_key ] = ( $hour_map[ $h_key ] ?? 0 ) + absint( $agg_row['sessions'] ?? 0 );
					}
				}
			}

			$peak_hour_label = '';
			if ( ! empty( $hour_map ) ) {
				arsort( $hour_map );
				$h0              = (int) array_key_first( $hour_map );
				$h1              = ( $h0 + 1 ) % 24;
				$peak_hour_label = sprintf( '%02d:00–%02d:00', $h0, $h1 );
			}

			$avg_time      = (float) $this->get_avg_session_time( $start_date, $end_date, $filters );
			$avg_time_label = $avg_time > 0 ? $this->format_summary_duration( $avg_time ) : '';

			$engagement = array(
				$this->build_section_chip( 'file-text', __( 'Top Page', 'opti-behavior' ), $top_page_label, '' !== $top_page_label ),
				$this->build_section_chip( 'clock', __( 'Peak Hour', 'opti-behavior' ), $peak_hour_label, '' !== $peak_hour_label ),
				$this->build_section_chip( 'timer', __( 'Avg. Session', 'opti-behavior' ), $avg_time_label, '' !== $avg_time_label ),
			);

			// --- Section 3: Audience Insights ---
			$nvr        = $this->get_new_vs_returning_counts_for_range( $start_date, $end_date, $filters );
			$nvr_new    = absint( isset( $nvr['new_visitors'] ) ? $nvr['new_visitors'] : 0 );
			$nvr_ret    = absint( isset( $nvr['returning_visitors'] ) ? $nvr['returning_visitors'] : 0 );
			$nvr_total  = $nvr_new + $nvr_ret;
			$nvr_label  = $nvr_total > 0
				/* translators: 1: new visitor count, 2: returning visitor count */
				? sprintf( __( '%1$s new · %2$s ret.', 'opti-behavior' ), number_format_i18n( $nvr_new ), number_format_i18n( $nvr_ret ) )
				: '';

			// Bot traffic share: bot_visits vs human sessions in range.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Light dashboard summary aggregate; table name from $wpdb->prefix.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$bot_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM " . $bots_tbl . " WHERE visit_time BETWEEN %s AND %s",
					$start_date,
					$end_date
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$bot_denom = $bot_count + $sessions;
			$bot_label = $bot_denom > 0 ? round( ( $bot_count / $bot_denom ) * 100 ) . '%' : '';

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Top traffic classification type (indexed on traffic_type).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Light dashboard summary aggregate; table name from $wpdb->prefix.
			$top_type = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COALESCE(NULLIF(TRIM(s.traffic_type), ''), 'human') AS t, COUNT(*) AS c
					FROM " . $sessions_tbl . " s" . $filter_join . "
					WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
					GROUP BY t
					ORDER BY c DESC
					LIMIT 1",
					array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$top_type_label = $top_type ? $this->humanize_summary_label( (string) $top_type->t ) : '';

			$audience = array(
				$this->build_section_chip( 'user-plus', __( 'New vs Returning', 'opti-behavior' ), $nvr_label, '' !== $nvr_label ),
				$this->build_section_chip( 'bot', __( 'Bot Traffic', 'opti-behavior' ), $bot_label, '' !== $bot_label ),
				$this->build_section_chip( 'shield', __( 'Top Traffic', 'opti-behavior' ), $top_type_label, '' !== $top_type_label ),
			);

			// --- Section 4: Tech & Acquisition ---
			// Top referrer host (exact max by host; self/empty => Direct).
			$site_host = function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';
			$site_host = preg_replace( '/^www\./i', '', strtolower( $site_host ) );
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Light dashboard summary aggregate; table name from $wpdb->prefix.
			$ref_rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT
						CASE
							WHEN s.referrer IS NULL OR TRIM(s.referrer) = '' THEN 'Direct'
							ELSE LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(s.referrer), '://', -1), '/', 1), '?', 1))
						END AS host,
						COUNT(*) AS c
					FROM " . $sessions_tbl . " s" . $filter_join . "
					WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
					GROUP BY host
					ORDER BY c DESC
					LIMIT 5",
					array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$top_referrer = '';
			foreach ( (array) $ref_rows as $ref_row ) {
				$host = isset( $ref_row->host ) ? strtolower( trim( (string) $ref_row->host ) ) : '';
				$host = preg_replace( '/^www\./i', '', $host );
				if ( '' === $host || 'direct' === $host ) {
					$top_referrer = __( 'Direct / None', 'opti-behavior' );
					break;
				}
				if ( $site_host && $host === $site_host ) {
					continue;
				}
				$top_referrer = $host;
				break;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Top country by sessions (matches Countries widget natural metric).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Light dashboard summary aggregate; table name from $wpdb->prefix.
			$top_country = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT UPPER(TRIM(v.country)) AS cc, COUNT(*) AS c
					FROM " . $sessions_tbl . " s
					LEFT JOIN " . $visitors_tbl . " v ON s.visitor_id = v.id
					WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
						AND v.country IS NOT NULL AND TRIM(v.country) <> '' AND UPPER(TRIM(v.country)) <> 'UN'
					GROUP BY cc
					ORDER BY c DESC
					LIMIT 1",
					array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$top_country_label = '';
			if ( $top_country && ! empty( $top_country->cc ) ) {
				$top_country_label = $this->get_country_name( (string) $top_country->cc );
				if ( '' === trim( (string) $top_country_label ) ) {
					$top_country_label = (string) $top_country->cc;
				}
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Top browser by sessions (matches Browsers widget natural metric).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Light dashboard summary aggregate; table name from $wpdb->prefix.
			$top_browser = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT TRIM(v.browser) AS b, COUNT(*) AS c
					FROM " . $sessions_tbl . " s
					LEFT JOIN " . $visitors_tbl . " v ON s.visitor_id = v.id
					WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
						AND v.browser IS NOT NULL AND TRIM(v.browser) <> ''
						AND LOWER(TRIM(v.browser)) NOT IN ('unknown', 'undefined', 'other')
					GROUP BY b
					ORDER BY c DESC
					LIMIT 1",
					array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$top_browser_label = $top_browser ? trim( (string) $top_browser->b ) : '';

			$tech = array(
				$this->build_section_chip( 'link', __( 'Top Referrer', 'opti-behavior' ), $top_referrer, '' !== $top_referrer ),
				$this->build_section_chip( 'globe', __( 'Top Country', 'opti-behavior' ), $top_country_label, '' !== $top_country_label ),
				$this->build_section_chip( 'compass', __( 'Top Browser', 'opti-behavior' ), $top_browser_label, '' !== $top_browser_label ),
			);

			$result = array(
				'key-metrics'      => $key_metrics,
				'engagement'       => $engagement,
				'audience'         => $audience,
				'tech-acquisition' => $tech,
			);

			if ( ! $has_filters ) {
				$ttl = function_exists( 'opti_behavior_dashboard_cache_ttl' ) ? opti_behavior_dashboard_cache_ttl() : 30;
				set_transient( $cache_key, $result, $ttl );
			}

			return $result;
		}

		/**
		 * Build a single section summary chip payload.
		 *
		 * @since 1.6.11
		 * @param string $icon     Lucide icon name.
		 * @param string $label    Chip label.
		 * @param string $value    Chip value (empty string when no data).
		 * @param bool   $has_data Whether a real value is present.
		 * @return array
		 */
		private function build_section_chip( $icon, $label, $value, $has_data ) {
			return array(
				'icon'     => (string) $icon,
				'label'    => (string) $label,
				'value'    => (string) $value,
				'has_data' => (bool) $has_data,
			);
		}

		/**
		 * Format a duration in seconds into a compact human label (e.g. "1m 23s").
		 *
		 * @since 1.6.11
		 * @param float $seconds Duration in seconds.
		 * @return string
		 */
		private function format_summary_duration( $seconds ) {
			$seconds = max( 0, (int) round( $seconds ) );
			if ( $seconds < 60 ) {
				/* translators: %d: seconds */
				return sprintf( __( '%ds', 'opti-behavior' ), $seconds );
			}
			$mins = (int) floor( $seconds / 60 );
			$secs = $seconds % 60;
			/* translators: 1: minutes, 2: seconds */
			return sprintf( __( '%1$dm %2$ds', 'opti-behavior' ), $mins, $secs );
		}

		/**
		 * Shorten a URL to its path for compact chip display.
		 *
		 * @since 1.6.11
		 * @param string $url URL.
		 * @return string
		 */
		private function shorten_summary_path( $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				return '';
			}
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				return $path;
			}
			return $url;
		}

		/**
		 * Humanize a raw machine label (e.g. "human" => "Human").
		 *
		 * @since 1.6.11
		 * @param string $label Raw label.
		 * @return string
		 */
		private function humanize_summary_label( $label ) {
			$label = trim( (string) $label );
			if ( '' === $label ) {
				return '';
			}
			$map = array(
				'human'     => __( 'Human', 'opti-behavior' ),
				'bot'       => __( 'Bot', 'opti-behavior' ),
				'spam'      => __( 'Spam', 'opti-behavior' ),
				'automated' => __( 'Automated', 'opti-behavior' ),
			);
			$key = strtolower( $label );
			if ( isset( $map[ $key ] ) ) {
				return $map[ $key ];
			}
			return ucwords( str_replace( array( '_', '-' ), ' ', $label ) );
		}

		/**
		 * Get visited directories data.
		 *
		 * Uses entry-directory session counts so one session contributes to one
		 * directory bucket. This keeps totals aligned with the sessions basis.
		 *
		 * @param string $start_date Start date (Y-m-d H:i:s).
		 * @param string $end_date   End date (Y-m-d H:i:s).
		 * @return array
		 */
		private function get_visited_directories_data_impl( $start_date, $end_date, array $filters = array() ) {
			global $wpdb;

			$has_filters = ! empty( $filters );
			$filter_sql  = $has_filters ? $this->build_advanced_filters_sql( $filters ) : array( 'where' => '', 'params' => array() );
			$filter_join = '' !== $filter_sql['where']
				? " LEFT JOIN {$wpdb->prefix}optibehavior_visitors v ON s.visitor_id = v.id"
				: '';

			$cache_key = 'opti_behavior_visited_directories_' . md5( 'session-entry-v6-generic-no-unknown|' . $start_date . '|' . $end_date . '|' . ( $this->is_spam_excluded() ? '1' : '0' ) );
			if ( ! $has_filters ) {
				$cached = $this->get_dashboard_widget_cached_data( $cache_key, $start_date, $end_date, 'visited_directories' );
				if ( false !== $cached ) {
					return $cached;
				}
			}

			$spam_where = $this->get_spam_exclusion_clause( 's' );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// One row per session using first pageview URL with session entry_page fallback.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard analytics query.
			$current_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT COALESCE(NULLIF(first_pageviews.entry_url, ''), NULLIF(s.entry_page, ''), '') AS entry_url,
						COUNT(DISTINCT s.id) AS sessions
					FROM " . $wpdb->prefix . "optibehavior_sessions s
					LEFT JOIN (
						SELECT pv.session_id,
							SUBSTRING_INDEX(
								GROUP_CONCAT(COALESCE(NULLIF(p.url, ''), NULLIF(pv.url, ''), '') ORDER BY pv.view_time ASC SEPARATOR '\n'),
								'\n',
								1
							) AS entry_url
						FROM " . $wpdb->prefix . "optibehavior_pageviews pv
						LEFT JOIN " . $wpdb->prefix . "optibehavior_pages p ON pv.page_id = p.id
						WHERE pv.view_time BETWEEN %s AND %s
						GROUP BY pv.session_id
					) first_pageviews ON first_pageviews.session_id = s.id" . $filter_join . "
					WHERE s.start_time BETWEEN %s AND %s" . $spam_where . $filter_sql['where'] . "
					GROUP BY entry_url",
					array_merge( array( $start_date, $end_date, $start_date, $end_date ), $filter_sql['params'] )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			$duration   = max( 1, strtotime( $end_date ) - strtotime( $start_date ) + 1 );
			$prev_end   = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - 1 );
			$prev_start = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - $duration );

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard analytics query.
			$prev_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT COALESCE(NULLIF(first_pageviews.entry_url, ''), NULLIF(s.entry_page, ''), '') AS entry_url,
						COUNT(DISTINCT s.id) AS sessions
					FROM " . $wpdb->prefix . "optibehavior_sessions s
					LEFT JOIN (
						SELECT pv.session_id,
							SUBSTRING_INDEX(
								GROUP_CONCAT(COALESCE(NULLIF(p.url, ''), NULLIF(pv.url, ''), '') ORDER BY pv.view_time ASC SEPARATOR '\n'),
								'\n',
								1
							) AS entry_url
						FROM " . $wpdb->prefix . "optibehavior_pageviews pv
						LEFT JOIN " . $wpdb->prefix . "optibehavior_pages p ON pv.page_id = p.id
						WHERE pv.view_time BETWEEN %s AND %s
						GROUP BY pv.session_id
					) first_pageviews ON first_pageviews.session_id = s.id" . $filter_join . "
					WHERE s.start_time BETWEEN %s AND %s" . $spam_where . $filter_sql['where'] . "
					GROUP BY entry_url",
					array_merge( array( $prev_start, $prev_end, $prev_start, $prev_end ), $filter_sql['params'] )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			$prev_by_directory = array();
			foreach ( (array) $prev_rows as $prev_row ) {
				$directory_key = $this->get_directory_bucket_key( isset( $prev_row['entry_url'] ) ? (string) $prev_row['entry_url'] : '' );
				if ( ! isset( $prev_by_directory[ $directory_key ] ) ) {
					$prev_by_directory[ $directory_key ] = 0;
				}
				$prev_by_directory[ $directory_key ] += isset( $prev_row['sessions'] ) ? absint( $prev_row['sessions'] ) : 0;
			}

			$directories_map = array();
			foreach ( (array) $current_rows as $current_row ) {
				$entry_url   = isset( $current_row['entry_url'] ) ? (string) $current_row['entry_url'] : '';
				$current_cnt = isset( $current_row['sessions'] ) ? absint( $current_row['sessions'] ) : 0;
				$bucket      = $this->get_directory_bucket_from_url( $entry_url );
				$bucket_key  = $bucket['key'];

				if ( ! isset( $directories_map[ $bucket_key ] ) ) {
					$directories_map[ $bucket_key ] = array(
						'type'      => $bucket['label'],
						'url'       => $bucket['url'],
						'taxonomy'  => $bucket_key,
						'sessions'  => 0,
						'pageviews' => 0,
						'change'    => 0,
					);
				}

				$directories_map[ $bucket_key ]['sessions']  += $current_cnt;
				$directories_map[ $bucket_key ]['pageviews'] += $current_cnt;
			}

			$directories = array_values( $directories_map );
			$current_total = 0;
			foreach ( $directories as $directory_row ) {
				$current_total += isset( $directory_row['sessions'] ) ? absint( $directory_row['sessions'] ) : 0;
			}

			if ( ! $has_filters && method_exists( $this, 'get_dashboard_stats_context_for_range' ) && method_exists( $this, 'get_dashboard_dimension_session_baseline' ) ) {
				$session_context  = $this->get_dashboard_stats_context_for_range( $start_date, $end_date, 'session' );
				$session_baseline = $this->get_dashboard_dimension_session_baseline( $session_context );

				if ( $session_baseline > $current_total ) {
					$missing = $session_baseline - $current_total;
					if ( ! isset( $directories_map['other'] ) ) {
						$other_bucket = $this->get_directory_bucket_payload( 'other' );
						$directories_map['other'] = array(
							'type'      => $other_bucket['label'],
							'url'       => $other_bucket['url'],
							'taxonomy'  => $other_bucket['key'],
							'sessions'  => 0,
							'pageviews' => 0,
							'change'    => 0,
						);
					}
					$directories_map['other']['sessions']  += $missing;
					$directories_map['other']['pageviews'] += $missing;
					$directories = array_values( $directories_map );
				} elseif ( $session_baseline < $current_total ) {
					$overage = $current_total - $session_baseline;
					usort(
						$directories,
						function ( $a, $b ) {
							return (int) $b['sessions'] <=> (int) $a['sessions'];
						}
					);
					foreach ( $directories as $index => $directory_row ) {
						if ( $overage <= 0 ) {
							break;
						}

						$count     = isset( $directory_row['sessions'] ) ? absint( $directory_row['sessions'] ) : 0;
						$reduction = min( $count, $overage );
						$count    -= $reduction;
						$overage  -= $reduction;

						$directories[ $index ]['sessions']  = $count;
						$directories[ $index ]['pageviews'] = min( isset( $directories[ $index ]['pageviews'] ) ? absint( $directories[ $index ]['pageviews'] ) : $count, $count );
					}
					$directories = array_values(
						array_filter(
							$directories,
							static function ( $directory_row ) {
								return ! empty( $directory_row['sessions'] );
							}
						)
					);
				}
			}

			foreach ( $directories as $index => $item ) {
				$bucket_key = isset( $item['taxonomy'] ) ? (string) $item['taxonomy'] : 'home';
				$prev_count = isset( $prev_by_directory[ $bucket_key ] ) ? absint( $prev_by_directory[ $bucket_key ] ) : 0;
				$curr_count = isset( $item['sessions'] ) ? absint( $item['sessions'] ) : 0;
				$change     = $prev_count > 0 ? round( ( ( $curr_count - $prev_count ) / $prev_count ) * 100 ) : ( $curr_count > 0 ? 100 : 0 );
				$directories[ $index ]['change'] = $change;
			}

			usort(
				$directories,
				function ( $a, $b ) {
					return (int) $b['sessions'] <=> (int) $a['sessions'];
				}
			);

			if ( count( $directories ) > 50 ) {
				$visible_directories = array_slice( $directories, 0, 49 );
				$hidden_directories  = array_slice( $directories, 49 );
				$other_sessions      = 0;
				foreach ( $hidden_directories as $hidden_directory ) {
					$other_sessions += isset( $hidden_directory['sessions'] ) ? absint( $hidden_directory['sessions'] ) : 0;
				}

				if ( $other_sessions > 0 ) {
					$visible_directories[] = array(
						'type'      => __( 'Other', 'opti-behavior' ),
						'url'       => home_url( '/' ),
						'taxonomy'  => 'other',
						'sessions'  => $other_sessions,
						'pageviews' => $other_sessions,
						'change'    => 0,
					);
				}
			} else {
				$visible_directories = $directories;
			}

			$result = array(
				'directories' => $visible_directories,
				'total'       => count( $directories ),
			);

			if ( $has_filters ) {
				return $result;
			}

			// Never freeze a zero-session result (Bug #4): that's the state most
			// likely to change on the very next session insert.
			return $this->set_dashboard_widget_cached_data( $cache_key, $result, empty( $directories ), $start_date, $end_date, 'visited_directories' );
		}

		/**
		 * Build a stable directory bucket key from a URL.
		 *
		 * @param string $url URL.
		 * @return string
		 */
		private function get_directory_bucket_key( $url ) {
			$bucket = $this->get_directory_bucket_from_url( $url );
			return isset( $bucket['key'] ) ? (string) $bucket['key'] : 'other';
		}

		/**
		 * Build a stable directory bucket payload from a URL.
		 *
		 * Buckets intentionally use generic dashboard categories instead of
		 * content-specific slugs. This prevents entries such as "Forex Robots" or
		 * translated category names from appearing in the aggregate dashboard while
		 * still preserving one-session-to-one-bucket reconciliation.
		 *
		 * @param string $url URL.
		 * @return array{key:string,label:string,url:string}
		 */
		private function get_directory_bucket_from_url( $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				return $this->get_directory_bucket_payload( 'other' );
			}

			$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
			$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
			if ( $this->is_directory_bucket_search_url( $path, $query ) ) {
				return $this->get_directory_bucket_payload( 'search' );
			}

			$path = $this->get_directory_bucket_relative_path( $path );
			$path = trim( $path, '/' );
			if ( '' === $path ) {
				return $this->get_directory_bucket_payload( 'home' );
			}

			$segments = array_values(
				array_filter(
					explode( '/', $path ),
					static function ( $segment ) {
						return '' !== $segment;
					}
				)
			);
			if ( empty( $segments ) ) {
				return $this->get_directory_bucket_payload( 'home' );
			}

			$taxonomy_bucket = $this->get_directory_bucket_taxonomy_type_from_path( $segments, true );
			if ( '' !== $taxonomy_bucket ) {
				return $this->get_directory_bucket_payload( $taxonomy_bucket );
			}

			if ( function_exists( 'url_to_postid' ) && url_to_postid( $url ) > 0 ) {
				return $this->get_directory_bucket_payload( 'page' );
			}

			$taxonomy_bucket = $this->get_directory_bucket_taxonomy_type_from_path( $segments, false );
			if ( '' !== $taxonomy_bucket ) {
				return $this->get_directory_bucket_payload( $taxonomy_bucket );
			}

			return $this->get_directory_bucket_payload( 'other' );
		}

		/**
		 * Build a generic directory bucket payload.
		 *
		 * @param string $bucket_key Generic bucket key.
		 * @return array{key:string,label:string,url:string}
		 */
		private function get_directory_bucket_payload( $bucket_key ) {
			$allowed_keys = array( 'home', 'category', 'tag', 'search', 'page', 'other' );
			$bucket_key   = sanitize_key( (string) $bucket_key );
			if ( ! in_array( $bucket_key, $allowed_keys, true ) ) {
				$bucket_key = 'other';
			}

			return array(
				'key'   => $bucket_key,
				'label' => $this->get_directory_bucket_label( $bucket_key ),
				'url'   => $this->get_directory_bucket_url( $bucket_key ),
			);
		}

		/**
		 * Determine whether a URL is a WordPress search URL.
		 *
		 * @param string $path  URL path.
		 * @param string $query URL query string.
		 * @return bool
		 */
		private function is_directory_bucket_search_url( $path, $query ) {
			$query_args = array();
			if ( '' !== (string) $query ) {
				wp_parse_str( (string) $query, $query_args );
				if ( array_key_exists( 's', $query_args ) ) {
					return true;
				}
			}

			$relative_path = trim( $this->get_directory_bucket_relative_path( (string) $path ), '/' );
			$segments      = array_values(
				array_filter(
					explode( '/', $relative_path ),
					static function ( $segment ) {
						return '' !== $segment;
					}
				)
			);

			if ( empty( $segments ) ) {
				return false;
			}

			return 'search' === $this->normalize_directory_bucket_segment_slug( $segments[0] );
		}

		/**
		 * Detect generic taxonomy bucket type from a path.
		 *
		 * @param array $segments       Relative path segments.
		 * @param bool  $base_only      Whether only explicit taxonomy bases should match.
		 * @return string
		 */
		private function get_directory_bucket_taxonomy_type_from_path( $segments, $base_only ) {
			$segments = is_array( $segments ) ? array_values( $segments ) : array();
			if ( empty( $segments ) ) {
				return '';
			}

			$first_slug = $this->normalize_directory_bucket_segment_slug( $segments[0] );
			if ( '' === $first_slug ) {
				return '';
			}

			if ( in_array( $first_slug, $this->get_directory_bucket_category_bases(), true ) ) {
				return 'category';
			}

			if ( in_array( $first_slug, $this->get_directory_bucket_tag_bases(), true ) ) {
				return 'tag';
			}

			if ( $base_only ) {
				return '';
			}

			$full_path_slugs = array();
			foreach ( $segments as $segment ) {
				$segment_slug = $this->normalize_directory_bucket_segment_slug( $segment );
				if ( '' !== $segment_slug ) {
					$full_path_slugs[] = $segment_slug;
				}
			}
			$full_path = implode( '/', $full_path_slugs );

			if ( $this->directory_bucket_term_exists( $full_path, array( 'category', 'product_cat' ) ) || $this->directory_bucket_term_exists( $first_slug, array( 'category', 'product_cat' ) ) ) {
				return 'category';
			}

			if ( $this->directory_bucket_term_exists( $first_slug, array( 'post_tag', 'product_tag' ) ) ) {
				return 'tag';
			}

			return '';
		}

		/**
		 * Normalize a raw path segment to a comparable slug.
		 *
		 * @param string $segment Raw path segment.
		 * @return string
		 */
		private function normalize_directory_bucket_segment_slug( $segment ) {
			$decoded = $this->decode_directory_bucket_segment( $segment );
			$slug    = sanitize_title( '' !== $decoded ? $decoded : (string) $segment );

			return is_string( $slug ) ? trim( $slug ) : '';
		}

		/**
		 * Get generic category URL bases.
		 *
		 * @return array
		 */
		private function get_directory_bucket_category_bases() {
			$category_base = (string) get_option( 'category_base', '' );
			$bases         = array( $category_base, 'category', 'categories', 'product-category', 'product_cat' );

			return $this->normalize_directory_bucket_base_list( $bases );
		}

		/**
		 * Get generic tag URL bases.
		 *
		 * @return array
		 */
		private function get_directory_bucket_tag_bases() {
			$tag_base = (string) get_option( 'tag_base', '' );
			$bases    = array( $tag_base, 'tag', 'tags', 'product-tag', 'product_tag' );

			return $this->normalize_directory_bucket_base_list( $bases );
		}

		/**
		 * Normalize taxonomy base candidates.
		 *
		 * @param array $bases Raw base values.
		 * @return array
		 */
		private function normalize_directory_bucket_base_list( $bases ) {
			$normalized = array();
			foreach ( (array) $bases as $base ) {
				$base = trim( (string) $base, '/' );
				if ( '' === $base ) {
					continue;
				}

				$first_segment = strtok( $base, '/' );
				$slug          = $this->normalize_directory_bucket_segment_slug( false !== $first_segment ? $first_segment : $base );
				if ( '' !== $slug ) {
					$normalized[] = $slug;
				}
			}

			return array_values( array_unique( $normalized ) );
		}

		/**
		 * Check whether a term slug exists in any supplied taxonomy.
		 *
		 * @param string $slug       Term slug or hierarchical slug path.
		 * @param array  $taxonomies Taxonomies to inspect.
		 * @return bool
		 */
		private function directory_bucket_term_exists( $slug, $taxonomies ) {
			$slug = trim( (string) $slug, '/' );
			if ( '' === $slug || ! function_exists( 'term_exists' ) ) {
				return false;
			}

			foreach ( (array) $taxonomies as $taxonomy ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}

				if ( false !== strpos( $slug, '/' ) && 'category' === $taxonomy && function_exists( 'get_category_by_path' ) ) {
					$category = get_category_by_path( $slug, false );
					if ( $category && ! is_wp_error( $category ) ) {
						return true;
					}
				}

				$term = term_exists( $slug, $taxonomy );
				if ( 0 !== $term && null !== $term ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Strip the WordPress install path from a URL path before bucketing.
		 *
		 * WordPress can be installed in a subdirectory (for example /wordpress/).
		 * Dashboard directory labels should describe the content directory below
		 * the site root, not the installation folder.
		 *
		 * @param string $path URL path.
		 * @return string
		 */
		private function get_directory_bucket_relative_path( $path ) {
			$path = (string) $path;
			if ( '' === $path ) {
				return '';
			}

			$trimmed_path = trim( $path, '/' );
			$base_paths   = array(
				(string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
				(string) wp_parse_url( site_url( '/' ), PHP_URL_PATH ),
			);

			foreach ( $base_paths as $base_path ) {
				$base_path = trim( $base_path, '/' );
				if ( '' === $base_path ) {
					continue;
				}

				if ( $trimmed_path === $base_path ) {
					return '';
				}

				if ( 0 === strpos( $trimmed_path, $base_path . '/' ) ) {
					return substr( $trimmed_path, strlen( $base_path ) + 1 );
				}
			}

			return $path;
		}

		/**
		 * Convert a directory bucket key to a human label.
		 *
		 * @param string $bucket_key Bucket key.
		 * @return string
		 */
		private function get_directory_bucket_label( $bucket_key ) {
			if ( 'home' === $bucket_key ) {
				return __( 'Home', 'opti-behavior' );
			}

			if ( 'category' === $bucket_key ) {
				return __( 'Category', 'opti-behavior' );
			}

			if ( 'tag' === $bucket_key ) {
				return __( 'Tag', 'opti-behavior' );
			}

			if ( 'search' === $bucket_key ) {
				return __( 'Search', 'opti-behavior' );
			}

			if ( 'page' === $bucket_key ) {
				return __( 'Page', 'opti-behavior' );
			}

			if ( 'other' === $bucket_key ) {
				return __( 'Other', 'opti-behavior' );
			}

			return ucwords( str_replace( '-', ' ', $bucket_key ) );
		}

		/**
		 * Decode one URL path segment for display.
		 *
		 * @param string $segment Raw URL path segment.
		 * @return string
		 */
		private function decode_directory_bucket_segment( $segment ) {
			$segment = trim( (string) $segment );
			if ( '' === $segment ) {
				return '';
			}

			$decoded = rawurldecode( $segment );
			if ( function_exists( 'wp_check_invalid_utf8' ) ) {
				$decoded = wp_check_invalid_utf8( $decoded, true );
			}

			if ( '' === $decoded ) {
				return '';
			}

			// A slash encoded inside a segment should not render like hierarchy.
			$decoded = str_replace( array( '/', '\\' ), ' ', $decoded );
			$decoded = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $decoded );

			return is_string( $decoded ) ? trim( $decoded ) : '';
		}

		/**
		 * Convert a raw path segment to a clean human label.
		 *
		 * @param string $raw_segment Raw URL path segment.
		 * @param string $bucket_key  Bucket key fallback.
		 * @return string
		 */
		private function get_directory_bucket_label_from_segment( $raw_segment, $bucket_key ) {
			if ( 'home' === $bucket_key || 'other' === $bucket_key ) {
				return $this->get_directory_bucket_label( $bucket_key );
			}

			$label = $this->decode_directory_bucket_segment( $raw_segment );
			if ( '' === $label ) {
				$label = $this->get_directory_bucket_label( $bucket_key );
			}

			$label = wp_strip_all_tags( $label );
			$label = str_replace( array( '-', '_', '+' ), ' ', $label );
			$label = preg_replace( '/\s+/u', ' ', $label );
			$label = is_string( $label ) ? trim( $label ) : '';

			if ( '' === $label ) {
				return $this->get_directory_bucket_label( $bucket_key );
			}

			return ucwords( $label );
		}

		/**
		 * Convert a directory bucket key to a site URL.
		 *
		 * @param string $bucket_key Bucket key.
		 * @return string
		 */
		private function get_directory_bucket_url( $bucket_key ) {
			if ( 'category' === $bucket_key ) {
				$category_bases = $this->get_directory_bucket_category_bases();
				$category_base  = ! empty( $category_bases ) ? $category_bases[0] : 'category';
				return home_url( '/' . trim( $category_base, '/' ) . '/' );
			}

			if ( 'tag' === $bucket_key ) {
				$tag_bases = $this->get_directory_bucket_tag_bases();
				$tag_base  = ! empty( $tag_bases ) ? $tag_bases[0] : 'tag';
				return home_url( '/' . trim( $tag_base, '/' ) . '/' );
			}

			if ( 'search' === $bucket_key ) {
				return home_url( '/?s=' );
			}

			if ( 'home' === $bucket_key || 'page' === $bucket_key || 'other' === $bucket_key ) {
				return home_url( '/' );
			}

			return home_url( '/' );
		}

	/**
	 * Get taxonomy key for classification grouping
	 *
	 * @param array $page Page data array with object_type and taxonomy.
	 * @return string Taxonomy key for grouping.
	 */
	private function get_taxonomy_key_for_classification( $page ) {
		$object_type = $page['object_type'] ?? '';
		$taxonomy = $page['taxonomy'] ?? '';

		// Home page
		if ( $object_type === 'home' ) {
			return 'home';
		}

		// Author archives
		if ( $object_type === 'author' ) {
			return array(
				'display_name' => __( 'Authors', 'opti-behavior' ),
				'url'          => home_url( '/author/' ),
			);
		}

		// Search pages
		if ( $object_type === 'search' ) {
			return array(
				'display_name' => __( 'Search', 'opti-behavior' ),
				'url'          => home_url( '/?s=' ),
			);
		}

		// Date archives
		if ( $object_type === 'date_archive' ) {
			return array(
				'display_name' => __( 'Date Archives', 'opti-behavior' ),
				'url'          => home_url( '/' ),
			);
		}

		// Custom post type archives
		if ( $object_type === 'post_type_archive' && ! empty( $taxonomy ) ) {
			$post_type_object = get_post_type_object( $taxonomy );
			if ( $post_type_object && isset( $post_type_object->labels->name ) ) {
				$display_name = $post_type_object->labels->name;
				$archive_link = get_post_type_archive_link( $taxonomy );
			} else {
				$display_name = __( 'Archives', 'opti-behavior' );
				$archive_link = home_url( '/' );
			}

			return array(
				'display_name' => $display_name,
				'url'          => $archive_link ?: home_url( '/' ),
			);
		}

		// 404 pages
		if ( $object_type === '404' ) {
			return array(
				'display_name' => __( '404 Pages', 'opti-behavior' ),
				'url'          => '#',
			);
		}

		// Terms (categories, tags, custom taxonomies)
		if ( $object_type === 'term' && ! empty( $taxonomy ) ) {
			return 'term_' . $taxonomy;
		}

		// Post
		if ( $object_type === 'post' ) {
			return 'post';
		}

		// Page
		if ( $object_type === 'page' ) {
			return 'page';
		}

		// Product
		if ( $object_type === 'product' ) {
			return 'product';
		}

		// Archive pages
		if ( strpos( $object_type, 'archive' ) !== false ) {
			return $object_type;
		}

		// Other/unknown
		return 'other';
	}

	/**
	 * Detect taxonomy information from URL (wrapper for analytics class method)
	 *
	 * @param string $url Page URL.
	 * @return array|null Array with post_id, term_id, taxonomy, and object_type.
	 */
	private function detect_taxonomy_from_url( $url ) {
		global $wpdb;

		$result = array(
			'post_id'     => null,
			'term_id'     => null,
			'taxonomy'    => null,
			'object_type' => null,
		);

		// Parse URL
		$parsed_url = wp_parse_url( $url );
		$path = isset( $parsed_url['path'] ) ? trim( $parsed_url['path'], '/' ) : '';

		// Remove WordPress base path
		$site_url = get_site_url();
		$site_path = wp_parse_url( $site_url, PHP_URL_PATH );
		if ( $site_path && strpos( $path, ltrim( $site_path, '/' ) ) === 0 ) {
			$path = substr( $path, strlen( ltrim( $site_path, '/' ) ) );
			$path = ltrim( $path, '/' );
		}

		// Check for home page
		if ( empty( $path ) ) {
			$result['object_type'] = 'home';
			return $result;
		}

		// Split path into parts
		$path_parts = explode( '/', $path );

		// Check for category archives
		if ( $path_parts[0] === 'category' && isset( $path_parts[1] ) ) {
			$slug = $path_parts[1];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time taxonomy lookup; table names from $wpdb properties.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$term = $wpdb->get_row( $wpdb->prepare(
				"SELECT t.term_id, t.name, tt.taxonomy
				FROM " . $wpdb->terms . " t
				INNER JOIN " . $wpdb->term_taxonomy . " tt ON t.term_id = tt.term_id
				WHERE t.slug = %s AND tt.taxonomy = 'category'
				LIMIT 1",
				$slug
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $term ) {
				$result['term_id'] = (int) $term->term_id;
				$result['taxonomy'] = 'category';
				$result['object_type'] = 'term';
			}
			return $result;
		}

		// Check for tag archives
		if ( $path_parts[0] === 'tag' && isset( $path_parts[1] ) ) {
			$slug = $path_parts[1];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time taxonomy lookup; table names from $wpdb properties.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$term = $wpdb->get_row( $wpdb->prepare(
				"SELECT t.term_id, t.name, tt.taxonomy
				FROM " . $wpdb->terms . " t
				INNER JOIN " . $wpdb->term_taxonomy . " tt ON t.term_id = tt.term_id
				WHERE t.slug = %s AND tt.taxonomy = 'post_tag'
				LIMIT 1",
				$slug
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $term ) {
				$result['term_id'] = (int) $term->term_id;
				$result['taxonomy'] = 'post_tag';
				$result['object_type'] = 'term';
			}
			return $result;
		}

		// Try to find post by slug
		$slug = $path_parts[ count( $path_parts ) - 1 ];
		if ( ! empty( $slug ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time post lookup; table name from $wpdb property.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$post = $wpdb->get_row( $wpdb->prepare(
				"SELECT ID, post_name, post_type
				FROM " . $wpdb->posts . "
				WHERE post_name = %s AND post_status = 'publish'
				LIMIT 1",
				$slug
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $post ) {
				$result['post_id'] = (int) $post->ID;
				$result['object_type'] = 'post';
			}
		}

		return $result;
	}

	/**
	 * Get display information for taxonomy data
	 *
	 * @param array $data Data array with taxonomy, object_type, term_id, post_id.
	 * @return array|null Array with display_name and url, or null if data invalid.
	 */
	private function get_taxonomy_display_info( $data ) {
		global $wpdb;

		$object_type = $data['object_type'] ?? null;
		$taxonomy = $data['taxonomy'] ?? null;
		$term_id = isset( $data['term_id'] ) ? intval( $data['term_id'] ) : null;
		$post_id = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : null;

		// Handle special cases
		if ( $object_type === 'home' ) {
			return array(
				'display_name' => 'Home',
				'url'          => home_url( '/' ),
			);
		}

		if ( $object_type === 'system' ) {
			return array(
				'display_name' => 'System Pages',
				'url'          => admin_url(),
			);
		}

		// Handle terms (categories, tags, custom taxonomies)
		if ( $object_type === 'term' && $term_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Querying term data; table names from $wpdb properties.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$term = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT t.name, t.slug, tt.taxonomy
					FROM " . $wpdb->terms . " t
					INNER JOIN " . $wpdb->term_taxonomy . " tt ON t.term_id = tt.term_id
					WHERE t.term_id = %d
					LIMIT 1",
					$term_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $term ) {
				// Format display name based on taxonomy
				$display_prefix = '';
				switch ( $term->taxonomy ) {
					case 'category':
						$display_prefix = 'Category: ';
						break;
					case 'post_tag':
						$display_prefix = 'Tag: ';
						break;
					case 'product_cat':
						$display_prefix = 'Product Category: ';
						break;
					case 'product_tag':
						$display_prefix = 'Product Tag: ';
						break;
					default:
						// Format custom taxonomy name
						$tax_name = str_replace( array( '_', '-' ), ' ', $term->taxonomy );
						$display_prefix = ucwords( $tax_name ) . ': ';
						break;
				}

				// Get term link
				$term_link = get_term_link( $term_id, $term->taxonomy );
				if ( is_wp_error( $term_link ) ) {
					$term_link = home_url( '/' );
				}

				return array(
					'display_name' => $display_prefix . $term->name,
					'url'          => $term_link,
				);
			}
		}

		// Handle posts/pages
		if ( $object_type === 'post' && $post_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Querying post data; table name from $wpdb property.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$post = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT post_title, post_name, post_type
					FROM " . $wpdb->posts . "
					WHERE ID = %d
					LIMIT 1",
					$post_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $post ) {
				$post_link = get_permalink( $post_id );
				if ( ! $post_link ) {
					$post_link = home_url( '/' );
				}

				$display_name = $post->post_title;
				if ( empty( $display_name ) ) {
					$display_name = ucwords( str_replace( array( '-', '_' ), ' ', $post->post_name ) );
				}

				return array(
					'display_name' => $display_name,
					'url'          => $post_link,
				);
			}
		}

		// Default for unknown types
		return array(
			'display_name' => 'Other',
			'url'          => home_url( '/' ),
		);
	}

	/**
	 * Get display information for a taxonomy type (not a specific term/post)
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $object_type Object type (home, term, post, system, post_type_archive).
	 * @return array Array with display_name and url.
	 */
	private function get_taxonomy_type_display_info( $taxonomy, $object_type ) {
		// Home page
		if ( $object_type === 'home' ) {
			return array(
				'display_name' => __( 'Home Page', 'opti-behavior' ),
				'url'          => home_url( '/' ),
			);
		}

		// Posts
		if ( $object_type === 'post' ) {
			return array(
				'display_name' => __( 'Posts', 'opti-behavior' ),
				'url'          => get_post_type_archive_link( 'post' ) ?: home_url( '/' ),
			);
		}

		// Pages
		if ( $object_type === 'page' ) {
			return array(
				'display_name' => __( 'Pages', 'opti-behavior' ),
				'url'          => '#',
			);
		}

		// Products
		if ( $object_type === 'product' ) {
			return array(
				'display_name' => __( 'Products', 'opti-behavior' ),
				'url'          => get_post_type_archive_link( 'product' ) ?: home_url( '/' ),
			);
		}

		// Author archives
		if ( $object_type === 'author' ) {
			return array(
				'display_name' => __( 'Authors', 'opti-behavior' ),
				'url'          => home_url( '/author/' ),
			);
		}

		// Search pages
		if ( $object_type === 'search' ) {
			return array(
				'display_name' => __( 'Search', 'opti-behavior' ),
				'url'          => home_url( '/?s=' ),
			);
		}

		// Date archives
		if ( $object_type === 'date_archive' ) {
			return array(
				'display_name' => __( 'Date Archives', 'opti-behavior' ),
				'url'          => home_url( '/' ),
			);
		}

		// Custom post type archives
		if ( $object_type === 'post_type_archive' && ! empty( $taxonomy ) ) {
			$post_type_object = get_post_type_object( $taxonomy );
			if ( $post_type_object && isset( $post_type_object->labels->name ) ) {
				$display_name = $post_type_object->labels->name;
				$archive_link = get_post_type_archive_link( $taxonomy );
			} else {
				$display_name = __( 'Archives', 'opti-behavior' );
				$archive_link = home_url( '/' );
			}

			return array(
				'display_name' => $display_name,
				'url'          => $archive_link ?: home_url( '/' ),
			);
		}

		// 404 pages
		if ( $object_type === '404' ) {
			return array(
				'display_name' => __( '404 Pages', 'opti-behavior' ),
				'url'          => '#',
			);
		}

		// Terms (categories, tags, custom taxonomies)
		if ( $object_type === 'term' && ! empty( $taxonomy ) ) {
			switch ( $taxonomy ) {
				case 'category':
					return array(
						'display_name' => __( 'Categories', 'opti-behavior' ),
						'url'          => home_url( '/category/' ),
					);

				case 'post_tag':
					return array(
						'display_name' => __( 'Tags', 'opti-behavior' ),
						'url'          => home_url( '/tag/' ),
					);

				case 'product_cat':
					return array(
						'display_name' => __( 'Product Categories', 'opti-behavior' ),
						'url'          => home_url( '/product-category/' ),
					);

				case 'product_tag':
					return array(
						'display_name' => __( 'Product Tags', 'opti-behavior' ),
						'url'          => home_url( '/product-tag/' ),
					);

				default:
					// Custom taxonomy - try to get from WordPress
					$tax_object = get_taxonomy( $taxonomy );
					if ( $tax_object && isset( $tax_object->labels->name ) ) {
						$display_name = $tax_object->labels->name;
					} else {
						// Fallback: format taxonomy slug
						$display_name = ucfirst( str_replace( array( '_', '-' ), ' ', $taxonomy ) );
					}

					return array(
						'display_name' => $display_name,
						'url'          => home_url( '/' ),
					);
			}
		}

		// Archive pages
		if ( strpos( $object_type, 'archive' ) !== false ) {
			return array(
				'display_name' => __( 'Archive Pages', 'opti-behavior' ),
				'url'          => home_url( '/' ),
			);
		}

		// Other/unknown
		return array(
			'display_name' => __( 'Other', 'opti-behavior' ),
			'url'          => home_url( '/' ),
		);
	}


		/**
		 * Get new registered users data
		 *
		 * @param string $start_date Start date (Y-m-d H:i:s).
		 * @param string $end_date End date (Y-m-d H:i:s).
		 * @return array New registered users data.
		 */

	/**
	 * Get display information for an individual taxonomy item (term, post, page, etc.)
	 *
	 * @param array $item Item data with taxonomy, object_type, term_id, post_id, and url.
	 * @return array Array with display_name and url.
	 */
	private function get_taxonomy_item_display_info( $item ) {
		$object_type = $item['object_type'] ?? '';
		$taxonomy = $item['taxonomy'] ?? '';
		$term_id = isset( $item['term_id'] ) ? intval( $item['term_id'] ) : null;
		$post_id = isset( $item['post_id'] ) ? intval( $item['post_id'] ) : null;
		$url = $item['url'] ?? '';

		// Home page
		if ( $object_type === 'home' ) {
			return array(
				'display_name' => __( 'Home Page', 'opti-behavior' ),
				'url'          => home_url( '/' ),
			);
		}

		// Terms (categories, tags, authors, custom taxonomies)
		if ( $object_type === 'term' && $term_id ) {
			$term = get_term( $term_id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				// Format display name based on taxonomy
				$tax_object = get_taxonomy( $taxonomy );
				$tax_label = '';
				
				if ( $tax_object && isset( $tax_object->labels->singular_name ) ) {
					$tax_label = $tax_object->labels->singular_name;
				} else {
					// Fallback: format taxonomy slug
					switch ( $taxonomy ) {
						case 'category':
							$tax_label = __( 'Category', 'opti-behavior' );
							break;
						case 'post_tag':
							$tax_label = __( 'Tag', 'opti-behavior' );
							break;
						case 'product_cat':
							$tax_label = __( 'Product Category', 'opti-behavior' );
							break;
						case 'product_tag':
							$tax_label = __( 'Product Tag', 'opti-behavior' );
							break;
						default:
							$tax_label = ucwords( str_replace( array( '_', '-' ), ' ', $taxonomy ) );
							break;
					}
				}

				// Get term link
				$term_link = get_term_link( $term_id, $taxonomy );
				if ( is_wp_error( $term_link ) ) {
					$term_link = $url ?: home_url( '/' );
				}

				return array(
					'display_name' => $tax_label . ': ' . $term->name,
					'url'          => $term_link,
				);
			}
		}

		// Posts/Pages/Products
		if ( in_array( $object_type, array( 'post', 'page', 'product' ), true ) && $post_id ) {
			$post_title = get_the_title( $post_id );
			$permalink = get_permalink( $post_id );

			if ( $post_title ) {
				// Format display name based on post type
				$type_label = '';
				switch ( $object_type ) {
					case 'post':
						$type_label = __( 'Post', 'opti-behavior' );
						break;
					case 'page':
						$type_label = __( 'Page', 'opti-behavior' );
						break;
					case 'product':
						$type_label = __( 'Product', 'opti-behavior' );
						break;
					default:
						$type_label = ucfirst( $object_type );
						break;
				}

				return array(
					'display_name' => $type_label . ': ' . $post_title,
					'url'          => $permalink ?: ( $url ?: home_url( '/' ) ),
				);
			}
		}

		// Fallback: use URL or generic name
		if ( ! empty( $url ) ) {
			// Try to extract a meaningful name from the URL
			$path = wp_parse_url( $url, PHP_URL_PATH );
			$display_name = basename( $path ) ?: __( 'Unknown Page', 'opti-behavior' );
			$display_name = ucwords( str_replace( array( '-', '_' ), ' ', $display_name ) );

			return array(
				'display_name' => $display_name,
				'url'          => $url,
			);
		}

		// Final fallback
		return array(
			'display_name' => __( 'Unknown Page', 'opti-behavior' ),
			'url'          => home_url( '/' ),
		);
	}

	private function get_new_registered_users_data_impl( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

		$has_filters = ! empty( $filters );
		$filter_sql  = $has_filters ? $this->build_advanced_filters_sql( $filters ) : array( 'where' => '', 'params' => array() );
		$filter_join = '' !== $filter_sql['where']
			? " LEFT JOIN {$wpdb->prefix}optibehavior_visitors v ON s.visitor_id = v.id"
			: '';

		$spam_clause = $this->get_spam_exclusion_clause( 's' );

		// Use the same visitor baseline as the Dashboard Visitors KPI.
		if ( ! $has_filters && method_exists( $this, 'get_dashboard_stats_context_for_range' ) && method_exists( $this, 'get_dashboard_dimension_visitor_baseline' ) ) {
			$visitor_context = $this->get_dashboard_stats_context_for_range( $start_date, $end_date, 'visitor' );
			$total_visitors  = $this->get_dashboard_dimension_visitor_baseline( $visitor_context );
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table query for analytics data
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			$total_visitors = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT NULLIF(s.visitor_id, ''))
					FROM " . $wpdb->prefix . "optibehavior_sessions s" . $filter_join . "
					WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'],
					array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$total_visitors = absint( $total_visitors );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Get logged in visitors (visitors who had logged-in sessions) for current period
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table query for analytics data
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$logged_in_visitors = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT NULLIF(s.visitor_id, ''))
				FROM " . $wpdb->prefix . "optibehavior_sessions s" . $filter_join . "
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
				AND s.user_id IS NOT NULL
				AND s.user_id > 0" . $filter_sql['where'],
				array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$logged_in_visitors = min( absint( $logged_in_visitors ), $total_visitors );

		// Get new registered users count for current period
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress users table query; table name from $wpdb property.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$new_registrations = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM " . $wpdb->users . "
				WHERE user_registered BETWEEN %s AND %s",
				$start_date,
				$end_date
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$new_registrations = intval( $new_registrations );

		// Get previous period data for comparison
		$duration = strtotime( $end_date ) - strtotime( $start_date );
		$prev_end = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - 1 );
		$prev_start = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - $duration );

		// Previous period: total visitors using the same KPI baseline source.
		if ( ! $has_filters && method_exists( $this, 'get_dashboard_stats_context_for_range' ) && method_exists( $this, 'get_dashboard_dimension_visitor_baseline' ) ) {
			$prev_visitor_context = $this->get_dashboard_stats_context_for_range( $prev_start, $prev_end, 'visitor' );
			$prev_total_visitors  = $this->get_dashboard_dimension_visitor_baseline( $prev_visitor_context );
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table query for analytics data
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			$prev_total_visitors = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT NULLIF(s.visitor_id, ''))
					FROM " . $wpdb->prefix . "optibehavior_sessions s" . $filter_join . "
					WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'],
					array_merge( array( $prev_start, $prev_end ), $filter_sql['params'] )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$prev_total_visitors = absint( $prev_total_visitors );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Previous period: logged in visitors
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table query for analytics data
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$prev_logged_in = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT NULLIF(s.visitor_id, ''))
				FROM " . $wpdb->prefix . "optibehavior_sessions s" . $filter_join . "
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
				AND s.user_id IS NOT NULL
				AND s.user_id > 0" . $filter_sql['where'],
				array_merge( array( $prev_start, $prev_end ), $filter_sql['params'] )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$prev_logged_in = min( absint( $prev_logged_in ), $prev_total_visitors );

		// Previous period: new registrations
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPress users table query; table name from $wpdb property.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$prev_registrations = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM " . $wpdb->users . "
				WHERE user_registered BETWEEN %s AND %s",
				$prev_start,
				$prev_end
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$prev_registrations = intval( $prev_registrations );

		$result = array(
			'total_visitors' => $total_visitors,
			'logged_in_visitors' => $logged_in_visitors,
			'new_registrations' => $new_registrations,
			'previous_total_visitors' => $prev_total_visitors,
			'previous_logged_in_visitors' => $prev_logged_in,
			'previous_new_registrations' => $prev_registrations,
			'total' => $total_visitors,
		);

		return $result;
	}

	/**
	 * Get daily breakdown stats for history bar charts
	 * Uses pre-aggregated daily_stats table for fast loading with fallback to real-time queries.
	 *
	 * @param string $start_date Start date (Y-m-d H:i:s).
	 * @param string $end_date End date (Y-m-d H:i:s).
	 * @return array Daily stats breakdown for all metrics.
	 */
	private function get_daily_stats_history( $start_date, $end_date ) {
		global $wpdb;

		// Keep stat-card history aligned with Pro recordings-scope KPI counts when available.
		if ( method_exists( $this, 'get_recorded_daily_stats_history' ) ) {
			$recorded_history = $this->get_recorded_daily_stats_history( $start_date, $end_date );
			if ( is_array( $recorded_history ) ) {
				return $recorded_history;
			}
		}

		// Try to use pre-aggregated data first (much faster for large datasets)
		$aggregated_result = $this->get_daily_stats_from_aggregated( $start_date, $end_date );
		if ( false !== $aggregated_result ) {
			return $aggregated_result;
		}

		// Fallback to real-time queries if aggregated data not available
		return $this->get_daily_stats_realtime( $start_date, $end_date );
	}

	/**
	 * Get daily stats from pre-aggregated daily_stats table.
	 * Returns false if table doesn't exist or data coverage is insufficient.
	 *
	 * @param string $start_date Start date (Y-m-d H:i:s).
	 * @param string $end_date End date (Y-m-d H:i:s).
	 * @return array|false Daily stats or false if not available.
	 */
	private function get_daily_stats_from_aggregated( $start_date, $end_date ) {
		global $wpdb;

		$daily_stats_table = $wpdb->prefix . 'optibehavior_daily_stats';

		// Check if daily_stats table exists
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $daily_stats_table ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! $table_exists ) {
			return false;
		}

		// Extract date parts for query
		$start_date_only = gmdate( 'Y-m-d', strtotime( $start_date ) );
		$end_date_only   = gmdate( 'Y-m-d', strtotime( $end_date ) );

		// Initialize result arrays
		$daily_data = array(
			'visitors'         => array(),
			'sessions'         => array(),
			'pageviews'        => array(),
			'avg_session_time' => array(),
			'avg_scroll_depth' => array(),
			'bounce_rate'      => array(),
			'dates'            => array(),
		);

		// Initialize all dates with zero values
		foreach ( $this->get_dashboard_daily_date_keys( $start_date, $end_date ) as $date_key ) {
			$daily_data['dates'][]                       = $date_key;
			$daily_data['visitors'][ $date_key ]         = 0;
			$daily_data['sessions'][ $date_key ]         = 0;
			$daily_data['pageviews'][ $date_key ]        = 0;
			$daily_data['avg_session_time'][ $date_key ] = 0;
			$daily_data['avg_scroll_depth'][ $date_key ] = 0;
			$daily_data['bounce_rate'][ $date_key ]      = 0;
		}

		// Query pre-aggregated data (single query instead of 6!)
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$aggregated_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					stat_date,
					sessions,
					visitors,
					pageviews,
					bounce_sessions,
					total_duration,
					total_scroll_depth,
					scroll_depth_count,
					human_sessions,
					spam_sessions
				FROM " . $daily_stats_table . "
				WHERE stat_date BETWEEN %s AND %s
				ORDER BY stat_date ASC",
				$start_date_only,
				$end_date_only
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Check if we have sufficient coverage (at least 50% of dates)
		$expected_days = count( $daily_data['dates'] );
		$actual_days   = count( $aggregated_data );

		if ( $actual_days < ( $expected_days * 0.5 ) ) {
			// Not enough aggregated data, fall back to real-time
			return false;
		}

		// Check if spam should be excluded
		$exclude_spam = $this->is_spam_excluded();

		// Populate from aggregated data
		foreach ( $aggregated_data as $row ) {
			$date_key = $row['stat_date'];

			if ( ! isset( $daily_data['visitors'][ $date_key ] ) ) {
				continue; // Date not in our range.
			}

			// Use human_sessions if spam is excluded, otherwise total sessions.
			$sessions = $exclude_spam ? intval( $row['human_sessions'] ) : intval( $row['sessions'] );

			$daily_data['visitors'][ $date_key ]  = intval( $row['visitors'] );
			$daily_data['sessions'][ $date_key ]  = $sessions;
			$daily_data['pageviews'][ $date_key ] = intval( $row['pageviews'] );

			// Calculate average session time from totals.
			$total_duration = intval( $row['total_duration'] );
			$daily_data['avg_session_time'][ $date_key ] = $sessions > 0 ? round( $total_duration / $sessions ) : 0;

			// Calculate average scroll depth from totals.
			$total_scroll  = intval( $row['total_scroll_depth'] );
			$scroll_count  = intval( $row['scroll_depth_count'] );
			$daily_data['avg_scroll_depth'][ $date_key ] = $scroll_count > 0 ? round( $total_scroll / $scroll_count, 1 ) : 0;

			// Calculate bounce rate.
			// NOTE: when spam is excluded this uses an all-traffic numerator over a
			// human-only denominator and is fully recomputed below (see corrective query).
			// Clamp guards against corrupt aggregates where bounce_sessions > sessions.
			$bounce_sessions = intval( $row['bounce_sessions'] );
			$daily_data['bounce_rate'][ $date_key ] = $sessions > 0 ? min( 100.0, round( ( $bounce_sessions / $sessions ) * 100, 1 ) ) : 0;
		}

		// Bounce-rate correction when spam is excluded.
		// The aggregated `bounce_sessions` column counts ALL traffic types (human + spam
		// + bot), but with spam excluded the denominator uses `human_sessions` only.
		// Dividing an all-traffic numerator by a human-only denominator can exceed 100%
		// (bots bounce while humans do not), producing impossible values such as 2500%
		// in the Bounce Rate stat-card sparkline tooltip. The daily_stats table has no
		// human-only bounce column, so recompute the daily bounce rate straight from the
		// sessions table with the same spam filter applied to BOTH numerator and
		// denominator — identical formula to get_daily_stats_realtime().
		//
		// IMPORTANT: reset the whole array to 0 first, then fill only the days that still
		// have rows in the sessions table. Pro's data archiver can purge old session rows
		// while keeping their daily_stats aggregate; those archived days have no surviving
		// human sessions (dashboard shows sessions=0 for them), so they must read 0 rather
		// than keep the stale all-traffic aggregate — otherwise they display impossible
		// values like 866.7% on a day with 0 sessions.
		if ( $exclude_spam ) {
			foreach ( $daily_data['bounce_rate'] as $reset_key => $reset_val ) {
				$daily_data['bounce_rate'][ $reset_key ] = 0;
			}

			// Avg Session Time has the same numerator/denominator mismatch as
			// bounce rate on this path: daily_stats.total_duration sums ALL
			// traffic (human + spam + bot, each capped at 2h), but the divisor
			// above switched to human_sessions when spam is excluded. A day with
			// many spam sessions and few human ones then averages far above any
			// real session (e.g. 2.5h spikes in the KPI sparkline). Recompute
			// per-day from the sessions table with the spam filter applied to
			// BOTH sides, like bounce rate below.
			foreach ( $daily_data['avg_session_time'] as $reset_key => $reset_val ) {
				$daily_data['avg_session_time'][ $reset_key ] = 0;
			}

			$spam_clause = $this->get_spam_exclusion_clause( 's' );
			// Bounce num/denom are additionally scoped to finalized sessions only:
			// today's bucket must not count sessions still inside the spam-verdict
			// grace window (unfinalized bots read as human bounces and make the
			// last sparkline bar spike). Avg session time keeps the full scope.
			$finalized = $this->get_finalized_sessions_condition( 's' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; spam clause built from trusted internal SQL.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$bounce_rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT DATE(s.start_time) AS stat_date,
						SUM(CASE WHEN s.is_bounce = 1 AND " . $finalized . " THEN 1 ELSE 0 END) AS bounces,
						SUM(CASE WHEN " . $finalized . " THEN 1 ELSE 0 END) AS total,
						AVG(s.duration) AS avg_time
					FROM " . $wpdb->prefix . "optibehavior_sessions s
					WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
					GROUP BY DATE(s.start_time)",
					$start_date,
					$end_date
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( is_array( $bounce_rows ) ) {
				foreach ( $bounce_rows as $bounce_row ) {
					$date_key = $bounce_row['stat_date'];
					if ( ! isset( $daily_data['bounce_rate'][ $date_key ] ) ) {
						continue;
					}
					$total   = intval( $bounce_row['total'] );
					$bounces = intval( $bounce_row['bounces'] );
					$daily_data['bounce_rate'][ $date_key ] = $total > 0 ? min( 100.0, round( ( $bounces / $total ) * 100, 1 ) ) : 0;
					$daily_data['avg_session_time'][ $date_key ] = round( floatval( $bounce_row['avg_time'] ) );
				}
			}
		}

		// Convert associative arrays to indexed arrays for easier JS consumption.
		return array(
			'dates'            => $daily_data['dates'],
			'visitors'         => array_values( $daily_data['visitors'] ),
			'sessions'         => array_values( $daily_data['sessions'] ),
			'pageviews'        => array_values( $daily_data['pageviews'] ),
			'avg_session_time' => array_values( $daily_data['avg_session_time'] ),
			'avg_scroll_depth' => array_values( $daily_data['avg_scroll_depth'] ),
			'bounce_rate'      => array_values( $daily_data['bounce_rate'] ),
		);
	}

	/**
	 * Get daily stats using real-time queries (fallback for when aggregated data not available).
	 *
	 * @param string $start_date Start date (Y-m-d H:i:s).
	 * @param string $end_date End date (Y-m-d H:i:s).
	 * @return array Daily stats breakdown for all metrics.
	 */
	private function get_daily_stats_realtime( $start_date, $end_date ) {
		global $wpdb;

		$spam_clause = $this->get_spam_exclusion_clause( 's' );

		// Initialize result arrays
		$daily_data = array(
			'visitors'         => array(),
			'sessions'         => array(),
			'pageviews'        => array(),
			'avg_session_time' => array(),
			'avg_scroll_depth' => array(),
			'bounce_rate'      => array(),
			'dates'            => array(),
		);

		// Initialize all dates with zero values
		foreach ( $this->get_dashboard_daily_date_keys( $start_date, $end_date ) as $date_key ) {
			$daily_data['dates'][]                       = $date_key;
			$daily_data['visitors'][ $date_key ]         = 0;
			$daily_data['sessions'][ $date_key ]         = 0;
			$daily_data['pageviews'][ $date_key ]        = 0;
			$daily_data['avg_session_time'][ $date_key ] = 0;
			$daily_data['avg_scroll_depth'][ $date_key ] = 0;
			$daily_data['bounce_rate'][ $date_key ]      = 0;
		}

		// Get daily visitors count
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$visitors_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(s.start_time) as date,
					COUNT(DISTINCT s.visitor_id) as count
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
				GROUP BY DATE(s.start_time)
				ORDER BY date ASC",
				$start_date,
				$end_date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $visitors_data as $row ) {
			$daily_data['visitors'][ $row['date'] ] = intval( $row['count'] );
		}

		// Get daily sessions count
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$sessions_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(s.start_time) as date,
					COUNT(*) as count
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
				GROUP BY DATE(s.start_time)
				ORDER BY date ASC",
				$start_date,
				$end_date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $sessions_data as $row ) {
			$daily_data['sessions'][ $row['date'] ] = intval( $row['count'] );
		}

		// Get daily pageviews count - need to join sessions for spam filtering
		if ( $this->is_spam_excluded() ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$pageviews_data = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						DATE(pv.view_time) as date,
						COUNT(*) as count
					FROM " . $wpdb->prefix . "optibehavior_pageviews pv
					INNER JOIN " . $wpdb->prefix . "optibehavior_sessions s ON pv.session_id = s.id
					WHERE pv.view_time BETWEEN %s AND %s" . $this->get_spam_exclusion_clause( 's' ) . "
					GROUP BY DATE(pv.view_time)
					ORDER BY date ASC",
					$start_date,
					$end_date
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$pageviews_data = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						DATE(view_time) as date,
						COUNT(*) as count
					FROM " . $wpdb->prefix . "optibehavior_pageviews
					WHERE view_time BETWEEN %s AND %s
					GROUP BY DATE(view_time)
					ORDER BY date ASC",
					$start_date,
					$end_date
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		foreach ( $pageviews_data as $row ) {
			$daily_data['pageviews'][ $row['date'] ] = intval( $row['count'] );
		}

		// Get daily average session time
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$session_time_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(s.start_time) as date,
					AVG(s.duration) as avg_time
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
				GROUP BY DATE(s.start_time)
				ORDER BY date ASC",
				$start_date,
				$end_date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $session_time_data as $row ) {
			$daily_data['avg_session_time'][ $row['date'] ] = round( floatval( $row['avg_time'] ) );
		}

		// Get daily average scroll depth - need to join sessions for spam filtering
		if ( $this->is_spam_excluded() ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$scroll_depth_data = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						DATE(pv.view_time) as date,
						AVG(pv.scroll_depth) as avg_depth
					FROM " . $wpdb->prefix . "optibehavior_pageviews pv
					INNER JOIN " . $wpdb->prefix . "optibehavior_sessions s ON pv.session_id = s.id
					WHERE pv.view_time BETWEEN %s AND %s
					AND pv.scroll_depth > 0" . $this->get_spam_exclusion_clause( 's' ) . "
					GROUP BY DATE(pv.view_time)
					ORDER BY date ASC",
					$start_date,
					$end_date
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$scroll_depth_data = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						DATE(pv.view_time) as date,
						AVG(pv.scroll_depth) as avg_depth
					FROM " . $wpdb->prefix . "optibehavior_pageviews pv
					WHERE pv.view_time BETWEEN %s AND %s
					AND pv.scroll_depth > 0
					GROUP BY DATE(pv.view_time)
					ORDER BY date ASC",
					$start_date,
					$end_date
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		foreach ( $scroll_depth_data as $row ) {
			$daily_data['avg_scroll_depth'][ $row['date'] ] = round( floatval( $row['avg_depth'] ), 1 );
		}

		// Get daily bounce rate.
		// Must use the aliased sessions table ("s") + aliased spam clause: the spam
		// filter contains correlated sub-queries (… WHERE event.session_id = s.id).
		// Without the alias, the bare "id" binds to the inner events table instead of
		// the outer sessions row, breaking correlation and returning zero rows — which
		// left the Bounce Rate stat-card sparkline empty whenever spam was excluded.
		//
		// When spam is excluded, bounce num/denom are additionally scoped to
		// finalized sessions only (see get_finalized_sessions_condition()): sessions
		// still inside the spam-verdict grace window would otherwise count as human
		// bounces and make today's bar spike.
		$finalized = $this->is_spam_excluded() ? $this->get_finalized_sessions_condition( 's' ) : '1=1';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$bounce_rate_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(s.start_time) as date,
					SUM(CASE WHEN s.is_bounce = 1 AND " . $finalized . " THEN 1 ELSE 0 END) as bounces,
					SUM(CASE WHEN " . $finalized . " THEN 1 ELSE 0 END) as total
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
				GROUP BY DATE(s.start_time)
				ORDER BY date ASC",
				$start_date,
				$end_date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $bounce_rate_data as $row ) {
			$total                                        = intval( $row['total'] );
			$bounces                                      = intval( $row['bounces'] );
			$daily_data['bounce_rate'][ $row['date'] ] = $total > 0 ? round( ( $bounces / $total ) * 100, 1 ) : 0;
		}

		// Convert associative arrays to indexed arrays for easier JS consumption
		return array(
			'dates'            => $daily_data['dates'],
			'visitors'         => array_values( $daily_data['visitors'] ),
			'sessions'         => array_values( $daily_data['sessions'] ),
			'pageviews'        => array_values( $daily_data['pageviews'] ),
			'avg_session_time' => array_values( $daily_data['avg_session_time'] ),
			'avg_scroll_depth' => array_values( $daily_data['avg_scroll_depth'] ),
			'bounce_rate'      => array_values( $daily_data['bounce_rate'] ),
		);
	}
}
} // End if ( ! trait_exists(...) ).
