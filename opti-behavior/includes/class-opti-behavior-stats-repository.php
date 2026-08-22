<?php
/**
 * Shared stats repository.
 *
 * @package opti-behavior
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reusable query helpers for canonical metrics.
 */
class Opti_Behavior_Stats_Repository {
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names use the WordPress prefix and optional WHERE fragments come from the shared spam-filter SQL builder.

	const CACHE_GROUP = 'opti_behavior_stats_repository';
	const CACHE_TTL   = 60;

	/**
	 * Return a SQL-safe plugin table name.
	 *
	 * @param string $suffix Table suffix without the WordPress prefix.
	 * @return string
	 */
	private static function table_name( $suffix ) {
		global $wpdb;

		return esc_sql( $wpdb->prefix . $suffix );
	}

	/**
	 * Build a stable object-cache key for a stats context.
	 *
	 * @param string $metric  Metric identifier.
	 * @param array  $context Stats context.
	 * @return string
	 */
	private static function cache_key( $metric, $context ) {
		return 'stats_' . sanitize_key( $metric ) . '_' . md5( wp_json_encode( (array) $context ) );
	}

	/**
	 * Build a canonical context from inclusive dashboard-style date boundaries.
	 *
	 * Dashboard and Pro pages commonly pass MySQL datetimes where the end value is
	 * the inclusive last second of the selected day. Repository queries use the
	 * shared half-open contract, so normalize the end boundary once here.
	 *
	 * @param string $start_date Inclusive start datetime.
	 * @param string $end_date   Inclusive end datetime.
	 * @param array  $args       Optional context args.
	 * @return array
	 */
	public static function context_from_inclusive_range( $start_date, $end_date, $args = array() ) {
		$start_date = sanitize_text_field( (string) $start_date );
		$end_date   = sanitize_text_field( (string) $end_date );
		$sql_end_ts = strtotime( $end_date . ' +1 second' );

		$context = array(
			'period'       => 'custom',
			'start_date'   => substr( $start_date, 0, 10 ),
			'end_date'     => substr( $end_date, 0, 10 ),
			'start'        => $start_date,
			'end'          => $end_date,
			'sql_start'    => $start_date,
			'sql_end'      => $sql_end_ts ? gmdate( 'Y-m-d H:i:s', $sql_end_ts ) : $end_date,
			'end_mode'     => 'exclusive',
			'source_scope' => 'all_sessions',
			'metric_grain' => 'session',
			'feature'      => 'shared',
			'exclude_spam' => class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ? Opti_Behavior_Stats_Spam_Filter::is_enabled() : false,
		);

		return wp_parse_args( (array) $args, $context );
	}

	/**
	 * Count canonical sessions from inclusive date boundaries.
	 *
	 * @param string $start_date Inclusive start datetime.
	 * @param string $end_date   Inclusive end datetime.
	 * @param array  $args       Optional context args.
	 * @return int
	 */
	public static function count_sessions_in_range( $start_date, $end_date, $args = array() ) {
		return self::count_sessions( self::context_from_inclusive_range( $start_date, $end_date, $args ) );
	}

	/**
	 * Count all core sessions in a context.
	 *
	 * @param array $context Stats context.
	 * @return int
	 */
	public static function count_sessions( $context ) {
		global $wpdb;

		$cache_key = self::cache_key( 'sessions', $context );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return absint( $cached );
		}

		$table = self::table_name( 'optibehavior_sessions' );
		$where = self::session_spam_sql( $context, 's' );

		$count = absint(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics aggregate on a custom plugin table; cached above; spam SQL comes from the shared safe builder.
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} s WHERE s.start_time >= %s AND s.start_time < %s{$where}",
					$context['sql_start'],
					$context['sql_end']
				)
			)
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Count unique visitors in core sessions in a context.
	 *
	 * @param array $context Stats context.
	 * @return int
	 */
	public static function count_visitors( $context ) {
		global $wpdb;

		$cache_key = self::cache_key( 'visitors', $context );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return absint( $cached );
		}

		$table = self::table_name( 'optibehavior_sessions' );
		$where = self::session_spam_sql( $context, 's' );

		$count = absint(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics aggregate on a custom plugin table; cached above; spam SQL comes from the shared safe builder.
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT NULLIF(s.visitor_id, '')) FROM {$table} s WHERE s.start_time >= %s AND s.start_time < %s{$where}",
					$context['sql_start'],
					$context['sql_end']
				)
			)
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Count pageviews in a context.
	 *
	 * @param array $context Stats context.
	 * @return int
	 */
	public static function count_pageviews( $context ) {
		global $wpdb;

		$cache_key = self::cache_key( 'pageviews', $context );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return absint( $cached );
		}

		$table          = self::table_name( 'optibehavior_pageviews' );
		$sessions_table = self::table_name( 'optibehavior_sessions' );
		$where          = self::session_spam_sql( $context, 's' );

		if ( '' !== $where ) {
			$count = absint(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics aggregate on custom plugin tables; cached above; spam SQL comes from the shared safe builder.
				$wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$table} pv INNER JOIN {$sessions_table} s ON pv.session_id = s.id WHERE pv.view_time >= %s AND pv.view_time < %s{$where}",
						$context['sql_start'],
						$context['sql_end']
					)
				)
			);

			wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

			return $count;
		}

		$count = absint(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Analytics aggregate on a custom plugin table; cached above.
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE view_time >= %s AND view_time < %s",
					$context['sql_start'],
					$context['sql_end']
				)
			)
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Build the session spam SQL for a repository context.
	 *
	 * Page-level Exclude Spam toggles are temporary reporting overrides. The
	 * global Traffic Behavior settings still define the thresholds, but an
	 * explicit context value of exclude_spam=false must include all sessions.
	 *
	 * @param array  $context Stats context.
	 * @param string $alias   Sessions table alias.
	 * @return string SQL fragment beginning with AND, or an empty string.
	 */
	private static function session_spam_sql( $context, $alias = 's' ) {
		$exclude_spam = array_key_exists( 'exclude_spam', $context ) ? (bool) $context['exclude_spam'] : null;

		return Opti_Behavior_Stats_Spam_Filter::session_sql( $alias, 'AND', $exclude_spam );
	}

	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
