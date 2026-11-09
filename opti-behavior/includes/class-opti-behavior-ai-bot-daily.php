<?php
/**
 * Daily counters of the AI bot passes (OPT-2859).
 *
 * The crawler log `optibehavior_bot_visits` keeps one row per hit for 30 days
 * only: the three AI widgets of the Traffic screen and the AI block of the site
 * report could never look further back. This table holds one counter row per
 * day x AI bot x page instead of one row per hit, so a year of history costs a
 * few thousand rows. No IP, no user agent, no referrer: a day, a bot slug, a
 * page and a number.
 *
 * `optibehavior_bot_visits` and the Bot Traffic widget are untouched.
 *
 * @package Opti_Behavior
 * @since 1.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_AI_Bot_Daily
 */
class Opti_Behavior_AI_Bot_Daily {

	/**
	 * Table name without the WordPress prefix.
	 */
	const TABLE = 'optibehavior_ai_bot_daily';

	/**
	 * Schema version (bump on any schema change).
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'opti_behavior_ai_bot_daily_db_version';

	/**
	 * Option holding the state of the one-shot fill from `bot_visits`.
	 */
	const BACKFILL_OPTION = 'opti_behavior_ai_bot_daily_backfill';

	/**
	 * Months of counters kept by default.
	 */
	const RETENTION_MONTHS = 12;

	/**
	 * Days of counters kept by default (the 12 months above).
	 */
	const RETENTION_DAYS = 365;

	/**
	 * Stored address length (the unique key uses the hash, never the address).
	 */
	const URL_MAX = 255;

	/**
	 * Whether the hooks are registered.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/**
	 * Register the hooks (admin only: the table self-heals on an admin page,
	 * the write path needs no hook of its own — the bot tracker calls record()).
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_create_table' ) );
		}
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Whether the table was created by this schema version.
	 *
	 * @return bool
	 */
	public static function table_ready() {
		return self::DB_VERSION === (string) get_option( self::DB_VERSION_OPTION, '' );
	}

	/**
	 * Create / upgrade the table (dbDelta-safe). Called by the database setup.
	 *
	 * @param string $charset_collate Optional charset collation.
	 * @return void
	 */
	public static function create_table( $charset_collate = '' ) {
		global $wpdb;

		if ( '' === $charset_collate ) {
			$charset_collate = $wpdb->get_charset_collate();
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$engine = class_exists( 'Opti_Behavior_Heatmap_Database' ) ? Opti_Behavior_Heatmap_Database::engine_clause() : 'ENGINE=InnoDB';

		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			day         date                NOT NULL,
			bot_type    varchar(50)         NOT NULL,
			url_hash    char(32)            NOT NULL,
			visited_url varchar(255)        NOT NULL DEFAULT '',
			hits        int(10)    UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY day_bot_url (day, bot_type, url_hash),
			KEY day_type (day, bot_type)
			) " . $engine . ' ' . $charset_collate
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Create the table when this schema version is not installed yet (a plugin
	 * update does not always run the full database setup). Never on AJAX / cron.
	 *
	 * @return void
	 */
	public static function maybe_create_table() {
		if ( self::table_ready() ) {
			return;
		}
		if ( ( wp_doing_ajax() || wp_doing_cron() ) && 'cli' !== PHP_SAPI ) {
			return;
		}
		self::create_table();
		self::maybe_backfill();
	}

	/**
	 * Hash of the stored address: the unique key stays bounded whatever the
	 * length of the address.
	 *
	 * @param string $url Visited address.
	 * @return string
	 */
	public static function url_hash( $url ) {
		return md5( self::clip_url( $url ) );
	}

	/**
	 * Address as stored (clipped, personal data already removed upstream).
	 *
	 * @param string $url Visited address.
	 * @return string
	 */
	public static function clip_url( $url ) {
		return substr( (string) $url, 0, self::URL_MAX );
	}

	/**
	 * Count one AI bot pass.
	 *
	 * One upsert, nothing read first. A bot absent from the AI map is not AI:
	 * it stays in `bot_visits` / Bot Traffic only.
	 *
	 * @param string $bot_type Stored bot slug.
	 * @param string $url      Visited address (already cleaned of personal data).
	 * @param string $day      Optional day (Y-m-d), site day by default.
	 * @return bool
	 */
	public static function record( $bot_type, $url, $day = '' ) {
		global $wpdb;

		$bot_type = strtolower( trim( (string) $bot_type ) );
		$url      = self::clip_url( $url );
		if ( '' === $bot_type || '' === $url || ! self::table_ready() ) {
			return false;
		}
		if ( ! self::is_ai_bot( $bot_type ) ) {
			return false;
		}

		if ( '' === $day ) {
			$day = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		}
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counter upsert on the plugin's own table.
		$done = $wpdb->query( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own table name; values go through prepare().
			$wpdb->prepare(
				"INSERT INTO {$table} (day, bot_type, url_hash, visited_url, hits) VALUES (%s, %s, %s, %s, 1)
				ON DUPLICATE KEY UPDATE hits = hits + 1",
				$day,
				$bot_type,
				self::url_hash( $url ),
				$url
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false !== $done;
	}

	/**
	 * Whether a stored bot slug belongs to the AI map.
	 *
	 * @param string $bot_type Stored bot slug.
	 * @return bool
	 */
	public static function is_ai_bot( $bot_type ) {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Bot_Tracker' ) ) {
			return false;
		}
		$map = Opti_Behavior_Heatmap_Bot_Tracker::get_ai_bot_map();
		return isset( $map[ strtolower( (string) $bot_type ) ] );
	}

	/**
	 * Fill the counters once from the crawler log (the 30 days still kept).
	 *
	 * Idempotent: a day already counted higher than the log keeps its value, so
	 * a second pass never doubles a figure.
	 *
	 * @return int Counter rows written.
	 */
	public static function maybe_backfill() {
		global $wpdb;

		if ( ! self::table_ready() || self::DB_VERSION === (string) get_option( self::BACKFILL_OPTION, '' ) ) {
			return 0;
		}

		$bot_types = self::ai_bot_types();
		if ( empty( $bot_types ) ) {
			return 0;
		}

		$log = $wpdb->prefix . 'optibehavior_bot_visits';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own tables; bot slugs bound via prepare().
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log ) );
		if ( $exists !== $log ) {
			update_option( self::BACKFILL_OPTION, self::DB_VERSION, false );
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $bot_types ), '%s' ) );
		$table        = self::table();
		$written      = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, bot_type, url_hash, visited_url, hits)
				SELECT DATE(visit_time), LOWER(bot_type), MD5(LEFT(visited_url, 255)), LEFT(visited_url, 255), COUNT(*)
				FROM {$log}
				WHERE visited_url <> '' AND LOWER(bot_type) IN ({$placeholders})
				GROUP BY DATE(visit_time), LOWER(bot_type), LEFT(visited_url, 255)
				ON DUPLICATE KEY UPDATE hits = GREATEST(hits, VALUES(hits))",
				$bot_types
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		update_option( self::BACKFILL_OPTION, self::DB_VERSION, false );

		return max( 0, (int) $written );
	}

	/**
	 * The AI bot slugs of the map.
	 *
	 * @return string[]
	 */
	public static function ai_bot_types() {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Bot_Tracker' ) ) {
			return array();
		}
		return array_map( 'strtolower', array_keys( Opti_Behavior_Heatmap_Bot_Tracker::get_ai_bot_map() ) );
	}

	/**
	 * Days of counters kept.
	 *
	 * @return int 0 = never pruned.
	 */
	public static function retention_days() {
		/**
		 * Days of AI counter rows kept (0 = never prune).
		 *
		 * Same shape as `opti_behavior_bot_visits_retention_days`, which rules
		 * the 30-day crawler log; these counters keep 12 months.
		 *
		 * @since 1.11.0
		 * @param int $days Default 365.
		 */
		return absint( apply_filters( 'opti_behavior_ai_bot_daily_retention_days', self::RETENTION_DAYS ) );
	}

	/**
	 * Delete counter rows older than the retention window.
	 *
	 * Called by the existing cleanup service (no new cron).
	 *
	 * @return int Rows deleted.
	 */
	public static function prune() {
		global $wpdb;

		if ( ! self::table_ready() ) {
			return 0;
		}
		$days = self::retention_days();
		if ( $days < 1 ) {
			return 0;
		}

		$table  = self::table();
		$cutoff = wp_date( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; value bound via prepare().
		$deleted = $wpdb->query( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own table name.
			$wpdb->prepare( "DELETE FROM {$table} WHERE day < %s LIMIT 20000", $cutoff )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return max( 0, (int) $deleted );
	}

	/**
	 * Oldest day held by the counters ('' when the table is empty).
	 *
	 * Read by the widgets to tell a period that reaches further back than the
	 * kept data, instead of the old standing "30 days" sentence.
	 *
	 * @return string Y-m-d or ''.
	 */
	public static function oldest_day() {
		global $wpdb;

		if ( ! self::table_ready() ) {
			return '';
		}
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Plugin's own table, no value.
		$day = $wpdb->get_var( "SELECT MIN(day) FROM {$table}" ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own table name.
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		return $day ? (string) $day : '';
	}

	/**
	 * Passes per bot slug over a period.
	 *
	 * @param string $from Start day (Y-m-d or Y-m-d H:i:s).
	 * @param string $to   End day (Y-m-d or Y-m-d H:i:s).
	 * @return array List of { bot_type, cnt }.
	 */
	public static function query_types( $from, $to ) {
		global $wpdb;

		$bot_types = self::ai_bot_types();
		if ( ! self::table_ready() || empty( $bot_types ) ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $bot_types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own table; values bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bot_type, SUM(hits) AS cnt
				FROM {$table}
				WHERE day BETWEEN %s AND %s AND bot_type IN ({$placeholders})
				GROUP BY bot_type",
				array_merge( array( self::day_of( $from ), self::day_of( $to ) ), $bot_types )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return (array) $rows;
	}

	/**
	 * Passes per page x bot slug over a period.
	 *
	 * @param string $from  Start day.
	 * @param string $to    End day.
	 * @param int    $limit Rows scanned.
	 * @return array List of { visited_url, bot_type, cnt }.
	 */
	public static function query_pages( $from, $to, $limit = 400 ) {
		global $wpdb;

		$bot_types = self::ai_bot_types();
		if ( ! self::table_ready() || empty( $bot_types ) ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $bot_types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own table; values bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT visited_url, bot_type, SUM(hits) AS cnt
				FROM {$table}
				WHERE day BETWEEN %s AND %s AND bot_type IN ({$placeholders})
				GROUP BY visited_url, bot_type
				ORDER BY cnt DESC
				LIMIT %d",
				array_merge( array( self::day_of( $from ), self::day_of( $to ) ), $bot_types, array( max( 50, absint( $limit ) ) ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return (array) $rows;
	}

	/**
	 * Every page address an AI bot read over a period (OPT-2874).
	 *
	 * Addresses only, no figure: the AI block needs the REAL number of pages
	 * read, which the capped `query_pages()` list cannot tell.
	 *
	 * @param string $from  Start day.
	 * @param string $to    End day.
	 * @param int    $limit Addresses read at most.
	 * @return array List of addresses.
	 */
	public static function query_page_urls( $from, $to, $limit = 5000 ) {
		global $wpdb;

		$bot_types = self::ai_bot_types();
		if ( ! self::table_ready() || empty( $bot_types ) ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $bot_types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin's own table; values bound via prepare().
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT visited_url
				FROM {$table}
				WHERE day BETWEEN %s AND %s AND bot_type IN ({$placeholders})
				LIMIT %d",
				array_merge( array( self::day_of( $from ), self::day_of( $to ) ), $bot_types, array( max( 50, absint( $limit ) ) ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array_map( 'strval', (array) $rows );
	}

	/**
	 * Day part of a date string.
	 *
	 * @param string $value Y-m-d or Y-m-d H:i:s.
	 * @return string Y-m-d.
	 */
	public static function day_of( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return gmdate( 'Y-m-d' );
		}
		return substr( $value, 0, 10 );
	}
}
