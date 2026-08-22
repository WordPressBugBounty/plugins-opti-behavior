<?php
/**
 * Daily Dimension Aggregates (Unified Retention Protocol — Option B)
 *
 * Pre-aggregates per-day dimensional breakdowns of session traffic so every
 * Dashboard widget keeps working for date ranges OLDER than the master
 * raw-data retention window (raw sessions/pageviews are age-purged after
 * `Opti_Behavior_Retention_Policy::get_raw_retention_days()` days, while
 * these aggregate rows — like `optibehavior_daily_stats` — are kept
 * indefinitely).
 *
 * One flexible table, `optibehavior_daily_dimension_stats`, stores one row
 * per (stat_date, dimension, dim_value):
 *
 * | dimension    | dim_value examples          | feeds Dashboard widget            |
 * |--------------|-----------------------------|-----------------------------------|
 * | device       | Desktop / Mobile / Tablet   | Device Types pie                  |
 * | browser      | Chrome / Firefox / …        | Top Browser, browsers chart       |
 * | os           | Windows / iOS / …           | Operating Systems chart           |
 * | resolution   | 1920x1080 / …               | Screen Resolution chart           |
 * | country      | US / FR / …                 | Top Country, countries widget     |
 * | referrer     | google.com / (direct) / …   | Top Referrer, referrers widget    |
 * | page         | https://…/pricing/          | Top Pages, TOP PAGE               |
 * | hour         | 00 … 23                     | PEAK HOUR                         |
 * | visitor_type | new / returning             | New vs Returning chart            |
 * | traffic      | human / spam / automated    | Bot Traffic %, classification     |
 * | user_type    | guest / registered          | New Registered Users chart        |
 *
 * Populated by the existing 3am `opti_behavior_aggregate_daily_stats` cron
 * (per processed day) and backfilled once on upgrade from still-available
 * raw data via a one-shot background event.
 *
 * @package opti-behavior
 * @since   1.9.0 (Unified Retention Protocol)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily dimension aggregate builder + query API.
 *
 * @since 1.9.0
 */
class Opti_Behavior_Dimension_Aggregates {

	/**
	 * Unprefixed table name.
	 */
	const TABLE = 'optibehavior_daily_dimension_stats';

	/**
	 * One-shot upgrade backfill flag option.
	 */
	const BACKFILL_FLAG_OPTION = 'opti_behavior_dimension_backfill_done';

	/**
	 * Background backfill event hook.
	 */
	const BACKFILL_HOOK = 'opti_behavior_dimension_backfill';

	/**
	 * Max distinct pages aggregated per day (keeps the table bounded).
	 */
	const MAX_PAGES_PER_DAY = 100;

	/**
	 * Max distinct values kept per dimension per day (defensive bound).
	 */
	const MAX_VALUES_PER_DIMENSION = 200;

	/**
	 * Supported dimensions.
	 *
	 * @return string[]
	 */
	public static function get_dimensions() {
		return array( 'device', 'browser', 'os', 'resolution', 'country', 'referrer', 'page', 'hour', 'visitor_type', 'traffic', 'user_type' );
	}

	/**
	 * Fully prefixed table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create/upgrade the dimension aggregate table (dbDelta-safe).
	 *
	 * @param string $charset_collate Charset collation.
	 */
	public static function create_table( $charset_collate ) {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta(
			'CREATE TABLE ' . $wpdb->prefix . self::TABLE . " (
			id              bigint(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			stat_date       date                 NOT NULL,
			dimension       varchar(32)          NOT NULL,
			dim_value       varchar(150)         NOT NULL,
			sessions        int(10)     UNSIGNED DEFAULT 0,
			human_sessions  int(10)     UNSIGNED DEFAULT 0,
			visitors        int(10)     UNSIGNED DEFAULT 0,
			pageviews       int(10)     UNSIGNED DEFAULT 0,
			bounce_sessions int(10)     UNSIGNED DEFAULT 0,
			total_duration  bigint(20)  UNSIGNED DEFAULT 0,
			last_aggregated datetime             NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY date_dim_value (stat_date, dimension, dim_value),
			KEY dim_date (dimension, stat_date)
			) " . $charset_collate
		);
	}

	/**
	 * Whether the aggregate table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence check.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) );
	}

	/**
	 * (Re)aggregate all dimension rows for one calendar day from raw data.
	 *
	 * Idempotent: existing rows for the day are replaced, so re-running for
	 * today/yesterday (like the daily_stats cron does) is safe.
	 *
	 * @param string $stat_date Y-m-d day to aggregate.
	 * @return int Number of dimension rows written.
	 */
	public static function aggregate_for_date( $stat_date ) {
		global $wpdb;

		$stat_date = substr( (string) $stat_date, 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $stat_date ) || ! self::table_exists() ) {
			return 0;
		}

		$table     = self::table();
		$sessions  = $wpdb->prefix . 'optibehavior_sessions';
		$visitors  = $wpdb->prefix . 'optibehavior_visitors';
		$pageviews = $wpdb->prefix . 'optibehavior_pageviews';
		$start     = $stat_date . ' 00:00:00';
		$end       = $stat_date . ' 23:59:59';
		$now       = current_time( 'mysql' );

		// Replace the whole day atomically-enough for a nightly cron.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table; values prepared.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE stat_date = %s", $stat_date ) );

		$metric_cols = "COUNT(*) AS sessions,
			SUM(CASE WHEN s.traffic_type = 'human' OR s.traffic_type IS NULL OR s.traffic_type = '' THEN 1 ELSE 0 END) AS human_sessions,
			COUNT(DISTINCT NULLIF(s.visitor_id, '')) AS visitors,
			COALESCE(SUM(CASE WHEN COALESCE(s.page_views, 0) > 0 THEN s.page_views ELSE 1 END), 0) AS pageviews,
			SUM(CASE WHEN s.is_bounce = 1 THEN 1 ELSE 0 END) AS bounce_sessions,
			COALESCE(SUM(COALESCE(s.duration, 0)), 0) AS total_duration";

		$definitions = array(
			'device'       => array(
				'select' => "CASE
					WHEN v.device_type IS NULL OR TRIM(v.device_type) = '' THEN 'Unknown'
					WHEN LOWER(TRIM(v.device_type)) IN ('mobile','phone','smartphone') THEN 'Mobile'
					WHEN LOWER(TRIM(v.device_type)) IN ('tablet','ipad') THEN 'Tablet'
					WHEN LOWER(TRIM(v.device_type)) IN ('desktop','pc','computer','laptop') THEN 'Desktop'
					ELSE 'Unknown'
				END",
				'join'   => "LEFT JOIN {$visitors} v ON s.visitor_id = v.id",
			),
			'browser'      => array(
				'select' => "COALESCE(NULLIF(TRIM(v.browser), ''), 'Unknown')",
				'join'   => "LEFT JOIN {$visitors} v ON s.visitor_id = v.id",
			),
			'os'           => array(
				'select' => "COALESCE(NULLIF(TRIM(v.os), ''), 'Unknown')",
				'join'   => "LEFT JOIN {$visitors} v ON s.visitor_id = v.id",
			),
			'resolution'   => array(
				'select' => "CASE WHEN COALESCE(v.screen_width, 0) > 0 AND COALESCE(v.screen_height, 0) > 0
					THEN CONCAT(v.screen_width, 'x', v.screen_height) ELSE 'Unknown' END",
				'join'   => "LEFT JOIN {$visitors} v ON s.visitor_id = v.id",
			),
			'country'      => array(
				'select' => "COALESCE(NULLIF(TRIM(v.country), ''), 'Unknown')",
				'join'   => "LEFT JOIN {$visitors} v ON s.visitor_id = v.id",
			),
			'referrer'     => array(
				'select' => "CASE
					WHEN s.referrer IS NULL OR TRIM(s.referrer) = '' THEN '(direct)'
					ELSE LEFT(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(LOWER(TRIM(s.referrer)), 'https://', ''), 'http://', ''), '/', 1), ':', 1), 150)
				END",
				'join'   => '',
			),
			'hour'         => array(
				'select' => "LPAD(HOUR(s.start_time), 2, '0')",
				'join'   => '',
			),
			'visitor_type' => array(
				'select' => "CASE WHEN v.first_visit IS NOT NULL AND v.first_visit BETWEEN %s AND %s THEN 'new' ELSE 'returning' END",
				'join'   => "LEFT JOIN {$visitors} v ON s.visitor_id = v.id",
				'extra_params' => array( $start, $end ),
			),
			'traffic'      => array(
				'select' => "CASE
					WHEN s.traffic_type IS NULL OR TRIM(s.traffic_type) = '' THEN 'human'
					WHEN LOWER(TRIM(s.traffic_type)) = 'bot' THEN 'automated'
					ELSE LOWER(TRIM(s.traffic_type))
				END",
				'join'   => '',
			),
			'user_type'    => array(
				'select' => "CASE WHEN COALESCE(s.user_id, 0) > 0 THEN 'registered' ELSE 'guest' END",
				'join'   => '',
			),
			'page'         => array(
				'select' => "LEFT(COALESCE(NULLIF(TRIM(s.entry_page), ''), '(unknown)'), 150)",
				'join'   => '',
				'limit'  => self::MAX_PAGES_PER_DAY,
			),
		);

		$rows_written = 0;

		foreach ( $definitions as $dimension => $def ) {
			$limit  = isset( $def['limit'] ) ? absint( $def['limit'] ) : self::MAX_VALUES_PER_DIMENSION;
			$params = array( $dimension, $now );
			if ( ! empty( $def['extra_params'] ) ) {
				$params = array_merge( $params, $def['extra_params'] );
			}
			$params[] = $start;
			$params[] = $end;
			$params[] = $limit;

			$sql = "INSERT INTO {$table}
				(stat_date, dimension, dim_value, sessions, human_sessions, visitors, pageviews, bounce_sessions, total_duration, last_aggregated)
				SELECT '{$stat_date}', %s, dim.dim_value, dim.sessions, dim.human_sessions, dim.visitors, dim.pageviews, dim.bounce_sessions, dim.total_duration, %s
				FROM (
					SELECT {$def['select']} AS dim_value, {$metric_cols}
					FROM {$sessions} s
					{$def['join']}
					WHERE s.start_time BETWEEN %s AND %s
					GROUP BY dim_value
					ORDER BY sessions DESC
					LIMIT %d
				) dim";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin tables; values prepared; SELECT fragments are hard-coded literals.
			$inserted = $wpdb->query( $wpdb->prepare( $sql, $params ) );
			if ( is_numeric( $inserted ) && $inserted > 0 ) {
				$rows_written += (int) $inserted;
			}
		}

		return $rows_written;
	}

	/**
	 * Get aggregated per-value session counts for a dimension over a range.
	 *
	 * @param string $dimension    One of {@see get_dimensions()}.
	 * @param string $start_date   Range start (datetime or date, inclusive).
	 * @param string $end_date     Range end (datetime or date, inclusive).
	 * @param bool   $exclude_spam Use human-only session counts when true.
	 * @return array<string,array> dim_value => { sessions, visitors, pageviews, bounce_sessions, total_duration }
	 */
	public static function get_dimension_counts( $dimension, $start_date, $end_date, $exclude_spam = false ) {
		global $wpdb;

		if ( ! in_array( $dimension, self::get_dimensions(), true ) || ! self::table_exists() ) {
			return array();
		}

		$table       = self::table();
		$sessions_expr = $exclude_spam ? 'human_sessions' : 'sessions';
		$start_d     = substr( (string) $start_date, 0, 10 );
		$end_d       = substr( (string) $end_date, 0, 10 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table; column allow-listed; values prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT dim_value,
					SUM({$sessions_expr}) AS sessions,
					SUM(visitors) AS visitors,
					SUM(pageviews) AS pageviews,
					SUM(bounce_sessions) AS bounce_sessions,
					SUM(total_duration) AS total_duration
				FROM {$table}
				WHERE dimension = %s AND stat_date BETWEEN %s AND %s
				GROUP BY dim_value
				ORDER BY sessions DESC",
				$dimension,
				$start_d,
				$end_d
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$value = isset( $row['dim_value'] ) ? (string) $row['dim_value'] : '';
			if ( '' === $value ) {
				continue;
			}
			$out[ $value ] = array(
				'sessions'        => absint( $row['sessions'] ?? 0 ),
				'visitors'        => absint( $row['visitors'] ?? 0 ),
				'pageviews'       => absint( $row['pageviews'] ?? 0 ),
				'bounce_sessions' => absint( $row['bounce_sessions'] ?? 0 ),
				'total_duration'  => absint( $row['total_duration'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * Oldest raw session timestamp still in the database ("raw data floor").
	 *
	 * Dashboard ranges that begin BEFORE this floor must merge aggregate
	 * history for the missing head of the range.
	 *
	 * @return string|null MySQL datetime, or null when no raw sessions exist.
	 */
	public static function get_raw_data_floor() {
		global $wpdb;

		$cached = get_transient( 'opti_behavior_raw_data_floor' );
		if ( false !== $cached ) {
			return '' === $cached ? null : $cached;
		}

		$sessions = $wpdb->prefix . 'optibehavior_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence check.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions ) ) ) {
			set_transient( 'opti_behavior_raw_data_floor', '', 5 * MINUTE_IN_SECONDS );
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table.
		$floor = $wpdb->get_var( "SELECT MIN(start_time) FROM {$sessions}" );
		$floor = $floor ? (string) $floor : null;

		set_transient( 'opti_behavior_raw_data_floor', null === $floor ? '' : $floor, 5 * MINUTE_IN_SECONDS );

		return $floor;
	}

	/**
	 * Split a dashboard range into the pre-raw (aggregate-only) head segment.
	 *
	 * @param string $start_date Range start (datetime).
	 * @param string $end_date   Range end (datetime).
	 * @return array|null { start: Y-m-d, end: Y-m-d } aggregate segment, or null when raw data covers the range.
	 */
	public static function get_pre_raw_segment( $start_date, $end_date ) {
		$floor = self::get_raw_data_floor();
		if ( null === $floor ) {
			// No raw data at all: the whole range is aggregate-only.
			return array(
				'start' => substr( (string) $start_date, 0, 10 ),
				'end'   => substr( (string) $end_date, 0, 10 ),
			);
		}

		$floor_day = substr( $floor, 0, 10 );
		$start_day = substr( (string) $start_date, 0, 10 );
		$end_day   = substr( (string) $end_date, 0, 10 );

		if ( $start_day >= $floor_day ) {
			return null; // Raw data covers the requested range.
		}

		$pre_end = gmdate( 'Y-m-d', strtotime( $floor_day . ' -1 day' ) );
		if ( $pre_end > $end_day ) {
			$pre_end = $end_day;
		}

		return array(
			'start' => $start_day,
			'end'   => $pre_end,
		);
	}

	/**
	 * Ensure the one-shot upgrade backfill is scheduled.
	 *
	 * Backfills the dimension table from still-available raw sessions so the
	 * dashboard's aggregate history starts on day one of the upgrade instead
	 * of only accruing from the next cron run forward.
	 */
	public static function maybe_schedule_backfill() {
		if ( get_option( self::BACKFILL_FLAG_OPTION ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::BACKFILL_HOOK );
		}
	}

	/**
	 * Cron callback: backfill dimension aggregates from raw data.
	 *
	 * Processes oldest days first, bounded per run; reschedules itself until
	 * every raw day has dimension rows, then sets the done flag.
	 *
	 * @param int $max_days Max days to aggregate in one run.
	 * @return int Days processed this run.
	 */
	public static function run_backfill( $max_days = 30 ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			update_option( self::BACKFILL_FLAG_OPTION, 1, false );
			return 0;
		}

		$sessions = $wpdb->prefix . 'optibehavior_sessions';
		$table    = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence check.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions ) ) ) {
			update_option( self::BACKFILL_FLAG_OPTION, 1, false );
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin tables; value prepared.
		$days = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT DATE(s.start_time) AS d
				FROM {$sessions} s
				LEFT JOIN {$table} agg ON agg.stat_date = DATE(s.start_time) AND agg.dimension = 'device'
				WHERE agg.id IS NULL AND DATE(s.start_time) < %s
				ORDER BY d ASC
				LIMIT %d",
				gmdate( 'Y-m-d' ),
				absint( $max_days )
			)
		);

		if ( empty( $days ) ) {
			update_option( self::BACKFILL_FLAG_OPTION, 1, false );
			return 0;
		}

		foreach ( $days as $day ) {
			self::aggregate_for_date( $day );
		}

		if ( count( $days ) >= absint( $max_days ) && ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::BACKFILL_HOOK );
		} elseif ( count( $days ) < absint( $max_days ) ) {
			update_option( self::BACKFILL_FLAG_OPTION, 1, false );
		}

		return count( $days );
	}
}
