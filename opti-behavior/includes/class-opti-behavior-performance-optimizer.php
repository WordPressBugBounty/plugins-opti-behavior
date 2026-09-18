<?php
/**
 * Performance Optimizer
 *
 * Adds database indexes and optimizations for better performance with large datasets
 *
 * @package OptiBehavior
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performance Optimizer Class
 *
 * Adds database indexes and optimizations for better performance with large datasets.
 *
 * @since 1.0.0
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database queries required for analytics plugin functionality. Custom tables used for high-volume event tracking. Caching not appropriate for real-time analytics data.
class OptiBehavior_Performance_Optimizer {

	/**
	 * Initialize the optimizer.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run_optimization' ) );
	}

	/**
	 * Check if optimization needs to run.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_run_optimization() {
		// PRODUCTION SAFETY (C2-3): never run schema/OPTIMIZE work from
		// admin-ajax (anonymous tracking beacons) or cron bootstraps.
		if ( ( wp_doing_ajax() || wp_doing_cron() ) && 'cli' !== PHP_SAPI ) {
			return;
		}

		$current_version = get_option( 'opti_behavior_db_version', '0' );
		$target_version  = '1.2.0';

		if ( version_compare( $current_version, $target_version, '<' ) ) {
			self::run_optimization();
			update_option( 'opti_behavior_db_version', $target_version );
		}
	}

	/**
	 * Run database optimizations.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public static function run_optimization() {
		global $wpdb;

		// Add watched columns to recordings table if they don't exist
		self::add_column_if_not_exists(
			"{$wpdb->prefix}optibehavior_recordings",
			'watched',
			'ALTER TABLE %s ADD COLUMN watched tinyint(1) DEFAULT 0'
		);

		self::add_column_if_not_exists(
			"{$wpdb->prefix}optibehavior_recordings",
			'watched_at',
			'ALTER TABLE %s ADD COLUMN watched_at datetime DEFAULT NULL'
		);

		// Add indexes to sessions table for better JOIN performance
		self::add_index_if_not_exists(
			"{$wpdb->prefix}optibehavior_sessions",
			'idx_sessions_visitor_start',
			'CREATE INDEX idx_sessions_visitor_start ON %s (visitor_id, start_time)'
		);
		// Optimize tables
		self::optimize_tables();
	}

	/**
	 * Add column if it doesn't exist.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @param string $table        Table name.
	 * @param string $column_name  Column name.
	 * @param string $sql_template SQL template with %s placeholder for table name.
	 */
	private static function add_column_if_not_exists( $table, $column_name, $sql_template ) {
		global $wpdb;

		// Check if column exists
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1)
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s
				AND COLUMN_NAME = %s",
				$table,
				$column_name
			)
		);

		if ( (int) $exists === 0 ) {
			// Column doesn't exist, create it
			// Escape table name for DDL statement (DDL does not support placeholders)
			$safe_table = esc_sql( $table );
			// Execute DDL query with escaped table name - column definitions are hardcoded in caller
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statement with escaped table name, column definitions are hardcoded and validated by caller
			$wpdb->query( sprintf( $sql_template, $safe_table ) );

		}
	}

	/**
	 * Add index if it doesn't exist.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @param string $table        Table name.
	 * @param string $index_name   Index name.
	 * @param string $sql_template SQL template with %s placeholder for table name.
	 */
	private static function add_index_if_not_exists( $table, $index_name, $sql_template ) {
		global $wpdb;

		// Check if index exists
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1)
				FROM information_schema.STATISTICS
				WHERE TABLE_SCHEMA = DATABASE()
				AND TABLE_NAME = %s
				AND INDEX_NAME = %s",
				$table,
				$index_name
			)
		);

		if ( (int) $exists === 0 ) {
			// Index doesn't exist, create it
			// Escape table name for DDL statement (DDL does not support placeholders)
			$safe_table = esc_sql( $table );
			// Execute DDL query with escaped table name - index definitions are hardcoded in caller
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- DDL statement with escaped table name, index definitions are hardcoded and validated by caller
			$wpdb->query( sprintf( $sql_template, $safe_table ) );

		}
	}

	/**
	 * Optimize database tables.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	private static function optimize_tables() {
		global $wpdb;

		$tables = array(
			"{$wpdb->prefix}optibehavior_recordings",
			"{$wpdb->prefix}optibehavior_sessions",
			"{$wpdb->prefix}optibehavior_visitors",
			"{$wpdb->prefix}optibehavior_pages",
			"{$wpdb->prefix}optibehavior_pageviews",
			"{$wpdb->prefix}optibehavior_events",
		);

		foreach ( $tables as $table ) {
			// Check if table exists
			$table_exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(1) 
					FROM information_schema.TABLES 
					WHERE TABLE_SCHEMA = DATABASE() 
					AND TABLE_NAME = %s",
					$table
				)
			);

			if ( (int) $table_exists === 1 ) {
				// PRODUCTION SAFETY (C2-3): OPTIMIZE TABLE is a full table
				// rebuild — minutes of blocking work on a large table. Skip it
				// for large tables; it is a defrag nicety, not a correctness
				// requirement (InnoDB reclaims space incrementally anyway).
				$row_estimate = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
						$table
					)
				);
				if ( $row_estimate > 100000 ) {
					continue;
				}
				$wpdb->query(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- OPTIMIZE TABLE does not support placeholders. Table name from controlled list.
					"OPTIMIZE TABLE {$table}"
				);
			}
		}
	}

}

OptiBehavior_Performance_Optimizer::init();

