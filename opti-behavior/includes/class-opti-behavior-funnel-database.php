<?php
/**
 * Funnel Database Schema Manager
 *
 * Handles creation and management of funnel-related database tables.
 *
 * @package opti-behavior
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Funnel Database Class
 */
class Opti_Behavior_Funnel_Database {

	/**
	 * Schema version of the two funnel tables. Bump it whenever
	 * create_tables() changes a column, a key or a table option.
	 */
	const DB_VERSION = '1.1.0'; // OPT-1619: `confirmed` column (suggested template funnels).

	/**
	 * Option holding the installed funnel schema version (not autoloaded:
	 * only read by the database setup).
	 */
	const DB_VERSION_OPTION = 'opti_behavior_funnel_db_version';

	/**
	 * Create or update the funnel tables only when needed: the schema version
	 * changed or one of the two tables is missing.
	 *
	 * Called from Opti_Behavior_Heatmap_Database::setup_locked() (activation,
	 * plugin update, admin self-heal). Never called on a front-end or AJAX
	 * request: dbDelta() used to run DESCRIBE + SHOW INDEX on both tables on
	 * every request of the site.
	 *
	 * @return bool True when dbDelta() ran.
	 */
	public static function maybe_create_tables() {
		global $wpdb;

		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			$missing = false;
			foreach ( array( 'opti_behavior_funnels', 'opti_behavior_funnel_tracking' ) as $suffix ) {
				$table = $wpdb->prefix . $suffix;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check during setup only.
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
					$missing = true;
					break;
				}
			}
			if ( ! $missing ) {
				return false;
			}
		}

		self::create_tables();
		return true;
	}

	/**
	 * Create funnel database tables
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$engine          = Opti_Behavior_Heatmap_Database::engine_clause();

		// Table for funnel definitions
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		// Table for funnel step tracking
		$table_funnel_tracking = $wpdb->prefix . 'opti_behavior_funnel_tracking';

		$sql_funnels = "CREATE TABLE {$table_funnels} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			description text,
			steps longtext NOT NULL COMMENT 'JSON array of step definitions',
			status varchar(20) DEFAULT 'active',
			source varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'manual | auto | discovered',
			recipe_id varchar(64) DEFAULT NULL COMMENT 'Auto-builder recipe provenance',
			confirmed tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = suggested template funnel the user has not confirmed',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY source (source),
			KEY created_at (created_at)
		) {$engine} {$charset_collate};";

		$sql_tracking = "CREATE TABLE {$table_funnel_tracking} (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			session_id varchar(255) NOT NULL,
			funnel_id bigint(20) NOT NULL,
			current_step int(11) DEFAULT 0,
			max_step_reached int(11) DEFAULT 0,
			completed tinyint(1) DEFAULT 0,
			entry_time datetime DEFAULT CURRENT_TIMESTAMP,
			last_activity datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			completion_time datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY session_id (session_id),
			KEY funnel_id (funnel_id),
			KEY completed (completed),
			KEY entry_time (entry_time)
		) {$engine} {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$previous_version = get_option( self::DB_VERSION_OPTION );
		dbDelta( $sql_funnels );
		dbDelta( $sql_tracking );
		self::$confirmed_column = null;

		// OPT-1619: funnels the auto-builder created from a template and the
		// user never edited become suggestions (nothing deleted, tracking goes on).
		if ( self::DB_VERSION !== $previous_version && ( false === $previous_version || version_compare( (string) $previous_version, '1.1.0', '<' ) ) ) {
			self::mark_untouched_auto_funnels_suggested();
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );

		// Do NOT create default funnel - users should define their own funnels
	}

	/**
	 * Memo of has_confirmed_column() (null = not checked yet).
	 *
	 * @var bool|null
	 */
	private static $confirmed_column = null;

	/**
	 * Memo of suggested_ids() (null = not read yet).
	 *
	 * @var int[]|null
	 */
	private static $suggested_ids = null;

	/**
	 * One-time migration of OPT-1619: a funnel the auto-builder created
	 * (`source` auto) and nobody changed since (updated_at within a minute of
	 * created_at) becomes a suggestion. updated_at is kept as it is.
	 *
	 * @return int Rows marked.
	 */
	public static function mark_untouched_auto_funnels_suggested() {
		global $wpdb;

		if ( ! self::has_confirmed_column() ) {
			return 0;
		}
		$table = $wpdb->prefix . 'opti_behavior_funnels';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema migration on the plugin table, no input.
		$done = $wpdb->query( "UPDATE {$table} SET confirmed = 0, updated_at = updated_at WHERE source = 'auto' AND confirmed = 1 AND status IN ( 'active', 'suspended' ) AND ( updated_at IS NULL OR created_at IS NULL OR updated_at <= DATE_ADD( created_at, INTERVAL 60 SECOND ) )" );
		self::$suggested_ids = null;

		return (int) $done;
	}

	/**
	 * Whether the funnels table has the `confirmed` column (OPT-1619). False
	 * until the schema setup ran after the update: readers then keep every
	 * funnel, as before.
	 *
	 * @return bool
	 */
	public static function has_confirmed_column() {
		global $wpdb;

		if ( null === self::$confirmed_column ) {
			$table = $wpdb->prefix . 'opti_behavior_funnels';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema probe of the plugin table, memoized per request.
			self::$confirmed_column = (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'confirmed'" );
		}

		return self::$confirmed_column;
	}

	/**
	 * SQL condition "this funnel counts" (OPT-1619): confirmed by the user.
	 * '' when the column does not exist yet. Contains no `%`.
	 *
	 * @param string $alias Table alias ('' = none).
	 * @return string " AND f.confirmed = 1" or ''.
	 */
	public static function confirmed_sql( $alias = '' ) {
		if ( ! self::has_confirmed_column() ) {
			return '';
		}
		$alias = preg_replace( '/[^a-z0-9_]/i', '', (string) $alias );

		return ' AND ' . ( '' !== $alias ? $alias . '.' : '' ) . 'confirmed = 1';
	}

	/**
	 * Ids of the suggested funnels (template funnels not confirmed yet),
	 * deleted ones left out. Read once per request.
	 *
	 * @return int[]
	 */
	public static function suggested_ids() {
		global $wpdb;

		if ( null === self::$suggested_ids ) {
			self::$suggested_ids = array();
			if ( self::has_confirmed_column() ) {
				$table = $wpdb->prefix . 'opti_behavior_funnels';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin table, no input, memoized per request.
				self::$suggested_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$table} WHERE confirmed = 0 AND status <> 'deleted'" ) );
			}
		}

		return self::$suggested_ids;
	}

	/**
	 * Confirm a suggested funnel (button "Confirm" or an edit): it counts from
	 * now on in Smart Insights, Page X-Ray and the reports.
	 *
	 * @param int $funnel_id Funnel id.
	 * @return bool True when the row is confirmed (or already was).
	 */
	public static function confirm( $funnel_id ) {
		global $wpdb;

		$funnel_id = (int) $funnel_id;
		if ( $funnel_id <= 0 ) {
			return false;
		}
		if ( ! self::has_confirmed_column() ) {
			return true;
		}
		$table = $wpdb->prefix . 'opti_behavior_funnels';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table, id bound by update().
		$done                = $wpdb->update( $table, array( 'confirmed' => 1 ), array( 'id' => $funnel_id ), array( '%d' ), array( '%d' ) );
		self::$suggested_ids = null;

		return false !== $done;
	}

	/**
	 * Forget the memos (tests, after a write in the same request).
	 *
	 * @return void
	 */
	public static function reset_memo() {
		self::$confirmed_column = null;
		self::$suggested_ids    = null;
	}

	/**
	 * Compute the canonical step signature of a funnel.
	 *
	 * The signature is the dedupe key used by the auto-builder: two funnels
	 * whose ordered ( match_type, url_pattern ) pairs are identical describe the
	 * same journey, regardless of their name, description or step labels.
	 *
	 * Formula (spec.md §3.5 — must stay byte-for-byte stable, it is persisted
	 * indirectly through dedupe decisions):
	 *
	 *     sha1( implode( '|', array_map( fn( $s ) => $s['match_type'] . ':' . $s['url_pattern'], $steps ) ) )
	 *
	 * No normalization (no trim, no case folding, no trailing-slash fixing) is
	 * applied on purpose: the generated patterns come from a single generator
	 * and normalizing here would silently collapse two genuinely different
	 * user-authored funnels.
	 *
	 * @since 1.8.4
	 *
	 * @param array|string $steps Steps array (step shape: `match_type`,
	 *                            `url_pattern`) or the raw JSON string as
	 *                            stored in `opti_behavior_funnels.steps`.
	 * @return string 40-char sha1 hash, or '' when no usable step was supplied.
	 */
	public static function get_steps_signature( $steps ) {
		if ( is_string( $steps ) ) {
			$steps = json_decode( $steps, true );
		}

		if ( ! is_array( $steps ) || empty( $steps ) ) {
			return '';
		}

		$parts = array();
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$match_type  = isset( $step['match_type'] ) ? (string) $step['match_type'] : '';
			$url_pattern = isset( $step['url_pattern'] ) ? (string) $step['url_pattern'] : '';
			$parts[]     = $match_type . ':' . $url_pattern;
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return sha1( implode( '|', $parts ) );
	}
}
