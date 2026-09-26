<?php
/**
 * Database Class
 *
 * Handles database operations, setup, and migrations.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database Class
 *
 * Provides database operations and table management.
 *
 * @since 1.0.0
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
class Opti_Behavior_Heatmap_Database {

	/**
	 * Completion flag for the one-time spam re-classification data repair.
	 *
	 * @since 1.7.1
	 * @var string
	 */
	const RECLASSIFY_SPAM_DONE_OPTION = 'opti_behavior_spam_reclassify_v1_done';

	/**
	 * Resumable cursor option for the spam re-classification data repair.
	 *
	 * @since 1.7.1
	 * @var string
	 */
	const RECLASSIFY_SPAM_CURSOR_OPTION = 'opti_behavior_spam_reclassify_v1_cursor';

	/**
	 * Cron hook that continues the spam re-classification batches.
	 *
	 * @since 1.7.1
	 * @var string
	 */
	const RECLASSIFY_SPAM_CRON_HOOK = 'opti_behavior_reclassify_spam_batch';

	/**
	 * Sessions re-classified per batch (bounded for large tables).
	 *
	 * @since 1.7.1
	 * @var int
	 */
	const RECLASSIFY_SPAM_BATCH_SIZE = 100;

	/**
	 * Only re-classify sessions started within this many days.
	 *
	 * @since 1.7.1
	 * @var int
	 */
	const RECLASSIFY_SPAM_WINDOW_DAYS = 30;

	/**
	 * Recurring cron hook for the stale-session finalization sweep.
	 *
	 * Server-side safety net: finalizes the spam verdict for sessions whose
	 * `is_final=1` end beacon never arrived (throttled/killed background tabs),
	 * which otherwise stay on the column-default `human` forever and pollute
	 * every spam-excluded report.
	 *
	 * @since 1.7.1
	 * @var string
	 */
	const FINALIZE_STALE_CRON_HOOK = 'opti_behavior_finalize_stale_sessions';

	/**
	 * Cron hook that performs heavy, potentially minutes-long schema/data
	 * migrations (large-table index builds, batched invalid-data cleanup)
	 * OFF the web-request path. One heavy statement per tick, resumable.
	 *
	 * @since 1.8.1.7
	 */
	const HEAVY_MIGRATIONS_CRON_HOOK = 'opti_behavior_heavy_migrations';

	/**
	 * Row-count estimate above which a table is considered "large": DDL on it
	 * (ALTER/CREATE INDEX) or full-table DML must never run inline in a web
	 * request and is deferred to HEAVY_MIGRATIONS_CRON_HOOK instead.
	 *
	 * @since 1.8.1.7
	 */
	const LARGE_TABLE_ROW_THRESHOLD = 100000;

	/**
	 * Option holding CREATE TABLE statements whose dbDelta pass was deferred
	 * because the target table is large and the request was a web request
	 * (real schema changes on large tables are applied by the heavy-migrations
	 * worker instead, one table per tick).
	 *
	 * @since 1.8.1.7
	 */
	const PENDING_DBDELTA_OPTION = 'opti_behavior_pending_dbdelta';

	/**
	 * In-progress pass state { cutoff, cursor } for the stale-session sweep.
	 *
	 * @since 1.7.1
	 * @var string
	 */
	const FINALIZE_STALE_STATE_OPTION = 'opti_behavior_stale_finalize_state';

	/**
	 * Watermark option: end-time upper bound of the last completed sweep pass.
	 * The next pass only scans sessions that ended after this, so each ended
	 * session is finalized exactly once.
	 *
	 * @since 1.7.1
	 * @var string
	 */
	const FINALIZE_STALE_WATERMARK_OPTION = 'opti_behavior_stale_finalize_watermark';

	/**
	 * Sessions finalized per sweep batch (bounded for large tables).
	 *
	 * @since 1.7.1
	 * @var int
	 */
	const FINALIZE_STALE_BATCH_SIZE = 100;

	/**
	 * Only sweep sessions started within this many days.
	 *
	 * @since 1.7.1
	 * @var int
	 */
	const FINALIZE_STALE_WINDOW_DAYS = 30;

	/**
	 * A session must have been silent for this many minutes before it can be
	 * finalized (matches the 30-minute session window: still-live sessions keep
	 * the deferred-verdict lifecycle).
	 *
	 * @since 1.7.1
	 * @var int
	 */
	const FINALIZE_STALE_GRACE_MINUTES = 30;

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

		// PRODUCTION SAFETY (C2-4): intercept every dbDelta() pass over this
		// plugin's tables (Free and Pro both use the wp_optibehavior_* prefix).
		// 1) Normalizes column-definition whitespace so dbDelta's single-space
		//    type-extraction regex works — without this, the padded schema
		//    strings make dbDelta think EVERY column changed and it emits a
		//    full `ALTER TABLE ... CHANGE COLUMN` battery per table on every
		//    setup() run (measured: minutes of copying + MDL queueing on the
		//    2M/5M-row tables — a confirmed site-down path at customer scale).
		// 2) Defers any remaining (i.e. REAL) schema change on a large existing
		//    table to the heavy-migrations worker when running in a web request.
		add_filter( 'dbdelta_create_queries', array( $this, 'filter_dbdelta_create_queries' ) );
	}

	/**
	 * Plugin activation setup.
	 *
	 * @since 1.0.0
	 */
	public function activation() {
		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'Plugin activation started', 'info', 'database' );
		$this->setup();
		$debug_manager->log( 'Plugin activation completed', 'info', 'database' );
	}

	/**
	 * Setup database tables and options.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function setup() {
		global $wpdb;
		$debug_manager = $this->core->get_debug_manager();

		// PRODUCTION SAFETY (C2-3): setup() used to stack concurrently — several
		// admin tabs / requests hitting the version gate at once each started the
		// full migration battery, piling identical DDL behind MySQL metadata locks
		// (measured: workers stuck 400-2000s → worker-pool exhaustion → site down).
		// A non-blocking named lock makes setup() single-flight: the first
		// request runs it, every concurrent request skips instantly.
		//
		// PORTABILITY: use the fail-open lock helper. On engines without GET_LOCK
		// (SQLite integration returns NULL/empty) the helper reports UNSUPPORTED
		// and we PROCEED — never skip table creation. Only a definitive BUSY
		// ('0' = another connection holds it) skips.
		$lock_state = Opti_Behavior_Heatmap_DB_Lock::acquire( 'opti_behavior_setup', 0 );
		if ( Opti_Behavior_Heatmap_DB_Lock::BUSY === $lock_state ) {
			$debug_manager->log( 'Database setup already running in another request, skipping', 'info', 'database' );
			return;
		}

		// PRODUCTION SAFETY (C2-4): if the background heavy-migration worker is
		// mid-build (e.g. a multi-minute ADD KEY on a large table), the inline
		// dbDelta pass below would queue an EXCLUSIVE metadata lock behind that
		// build — and MySQL's fair MDL queue then blocks every later SELECT on
		// the table too, stalling the whole site until the build finishes
		// (measured: admin request hung 300s+ at customer scale). Web requests
		// therefore skip setup entirely while the worker holds its lock; the
		// version gate stays open, so the next request after the worker finishes
		// completes setup at normal (seconds) cost. CLI is exempt: operators
		// running the setup deliberately can wait.
		if ( 'cli' !== PHP_SAPI ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock probe.
			$worker_busy = $wpdb->get_var( "SELECT IS_USED_LOCK('opti_behavior_heavy_migrations')" );
			if ( null !== $worker_busy ) {
				$debug_manager->log( 'Heavy-migration worker active, deferring inline database setup', 'info', 'database' );
				Opti_Behavior_Heatmap_DB_Lock::release( 'opti_behavior_setup', $lock_state );
				return;
			}
		}

		try {
			$this->setup_locked();
		} finally {
			Opti_Behavior_Heatmap_DB_Lock::release( 'opti_behavior_setup', $lock_state );
		}
	}

	/**
	 * Actual database setup body. Only ever runs under the 'opti_behavior_setup'
	 * named lock acquired in setup().
	 *
	 * @since 1.8.1.7
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	private function setup_locked() {
		global $wpdb;
		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'Database setup started', 'info', 'database' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate  = $wpdb->get_charset_collate();
		$max_index_length = 191;

		// Capture whether the core tables already existed BEFORE this setup run.
		// This flag is used by handle_migrations() to distinguish a genuine fresh install
		// (tables were just created) from an old incomplete install (tables pre-existed).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time pre-setup check; caching inappropriate here.
		$tables_preexisted = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'optibehavior_events' )
		);

		// Create main events table
		$debug_manager->log( 'Creating/updating events table', 'debug', 'database' );
		$this->create_events_table( $charset_collate );

		// Create pages table
		$debug_manager->log( 'Creating/updating pages table', 'debug', 'database' );
		$this->create_pages_table( $charset_collate, $max_index_length );

		// Create sessions table
		$debug_manager->log( 'Creating/updating sessions table', 'debug', 'database' );
		$this->create_sessions_table( $charset_collate );

		// Create visitors table
		$debug_manager->log( 'Creating/updating visitors table', 'debug', 'database' );
		$this->create_visitors_table( $charset_collate );

		// Create pageviews table
		$debug_manager->log( 'Creating/updating pageviews table', 'debug', 'database' );
		$this->create_pageviews_table( $charset_collate );

		// Create recordings table
		$debug_manager->log( 'Creating/updating recordings table', 'debug', 'database' );
		$this->create_recordings_table( $charset_collate );

		// Create session pages table
		$debug_manager->log( 'Creating/updating session pages table', 'debug', 'database' );
		$this->create_session_pages_table( $charset_collate );

		// Create referrer tracking table
		$debug_manager->log( 'Creating/updating referrers table', 'debug', 'database' );
		$this->create_referrers_table( $charset_collate );

		// Create outbound clicks table
		$debug_manager->log( 'Creating/updating outbound clicks table', 'debug', 'database' );
		$this->create_outbound_clicks_table( $charset_collate );

		// Create bot visits table
		$debug_manager->log( 'Creating/updating bot visits table', 'debug', 'database' );
		$this->create_bot_visits_table( $charset_collate );

		// Create heatmap pages mapping table (for fast heatmap storage)
		$debug_manager->log( 'Creating/updating heatmap pages table', 'debug', 'database' );
		$this->create_heatmap_pages_table( $charset_collate );

		// Create per-day heatmap aggregate index table (dated-period fast path)
		$debug_manager->log( 'Creating/updating heatmap daily index table', 'debug', 'database' );
		$this->create_heatmap_daily_table( $charset_collate );

		// Create daily stats table (for pre-aggregated dashboard metrics)
		$debug_manager->log( 'Creating/updating daily stats table', 'debug', 'database' );
		$this->create_daily_stats_table( $charset_collate );

		// Create daily dimension stats table (Option B aggregate history: per-day
		// device/browser/os/resolution/country/referrer/page/hour/... breakdowns
		// kept indefinitely so the Dashboard survives beyond raw retention).
		if ( class_exists( 'Opti_Behavior_Dimension_Aggregates' ) ) {
			$debug_manager->log( 'Creating/updating daily dimension stats table', 'debug', 'database' );
			Opti_Behavior_Dimension_Aggregates::create_table( $charset_collate );
			Opti_Behavior_Dimension_Aggregates::maybe_schedule_backfill();
		}

		// Create Smart Insights table (for deterministic, aggregated insight storage)
		$debug_manager->log( 'Creating/updating Smart Insights table', 'debug', 'database' );
		$this->create_insights_table( $charset_collate );

		// Create error tracking tables (PRO feature)
		$debug_manager->log( 'Creating/updating errors table', 'debug', 'database' );
		$this->create_errors_table( $charset_collate );

		$debug_manager->log( 'Creating/updating error types table', 'debug', 'database' );
		$this->create_error_types_table( $charset_collate );

		$debug_manager->log( 'Creating/updating friction table', 'debug', 'database' );
		$this->create_friction_table( $charset_collate );

		$debug_manager->log( 'Creating/updating performance table', 'debug', 'database' );
		$this->create_performance_table( $charset_collate );

		$debug_manager->log( 'Creating/updating broken links table', 'debug', 'database' );
		$this->create_broken_links_table( $charset_collate );

		// Create user journey groups table (PRO feature)
		$debug_manager->log( 'Creating/updating journey groups table', 'debug', 'database' );
		$this->create_journey_groups_table( $charset_collate );

		// Create scheduled reports tables
		$debug_manager->log( 'Creating/updating report schedules tables', 'debug', 'database' );
		$this->create_report_schedules_tables( $charset_collate );

		// Create A/B testing tables and set default options
		$debug_manager->log( 'Creating/updating A/B testing tables', 'debug', 'database' );
		if ( ! class_exists( 'Opti_Behavior_AB_Test_Database' ) ) {
			$ab_db_path = OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-ab-test-database.php';
			if ( file_exists( $ab_db_path ) ) {
				require_once $ab_db_path;
			}
		}
		if ( class_exists( 'Opti_Behavior_AB_Test_Database' ) ) {
			Opti_Behavior_AB_Test_Database::create_tables();
			Opti_Behavior_AB_Test_Database::maybe_set_default_options();
		}

		// Handle migrations from older versions (must run AFTER all tables are created)
		$debug_manager->log( 'Checking for database migrations', 'debug', 'database' );
		$this->handle_migrations( $tables_preexisted );

		// Engagement counters (1.9.2): sessions.click/scroll/move_count columns,
		// migration state seed, one-off backfill + legacy row purge via cron.
		// Also drops the redundant events indexes once their covering indexes
		// exist (deferred to the cron tick on large tables).
		if ( class_exists( 'Opti_Behavior_Heatmap_Engagement_Counters' ) ) {
			Opti_Behavior_Heatmap_Engagement_Counters::ensure_schema();
		}
		if ( 'cli' === PHP_SAPI || ! $this->is_large_table( $wpdb->prefix . 'optibehavior_events' ) ) {
			self::drop_redundant_indexes( 'cli' !== PHP_SAPI );
		}

		// Create default report schedule if none exists
		$debug_manager->log( 'Checking for default report schedule', 'debug', 'database' );
		$this->maybe_create_default_schedule();

		// Update plugin version
		$options = $this->core->get_options();
		$options['activated_ver']  = OPTI_BEHAVIOR_HEATMAP_VERSION;
		$options['activated_plan'] = Opti_Behavior_Heatmap_Core::PLAN;

		// Clean up invalid data
		$debug_manager->log( 'Cleaning up invalid data', 'debug', 'database' );
		$this->cleanup_invalid_data();

		// Setup cron
		$debug_manager->log( 'Setting up daily cron job', 'debug', 'database' );
		$this->setup_daily_cron();

		// Set default auto-cleanup settings on fresh install (do not overwrite existing settings).
		$this->maybe_set_default_auto_cleanup();

		// Update URL2 field
		$debug_manager->log( 'Updating URL2 field', 'debug', 'database' );
		$this->update_url2();

		$debug_manager->log( 'Database setup completed successfully', 'info', 'database' );
	}

	/**
	 * Handle database migrations.
	 *
	 * @param bool $tables_preexisted Whether the core tables existed before this setup run.
	 *                                Pass false (default) on a fresh install so the legacy
	 *                                "drop empty tables" branch is never triggered for tables
	 *                                that setup() itself just created.
	 */
	private function handle_migrations( $tables_preexisted = false ) {
		global $wpdb;
		$options = $this->core->get_options();

		// Legacy migration: older installs (pre-1.0.0) could leave empty tables with no
		// version record.  We drop them so dbDelta can recreate them correctly.
		// IMPORTANT: only run this when the tables existed BEFORE this activation ($tables_preexisted).
		// On a genuine fresh install the tables were just created by setup() and must NOT be dropped.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration: checking table existence.
		if ( $tables_preexisted &&
			! isset( $options['activated_ver'] ) &&
			$wpdb->get_row( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'optibehavior_events' ) ) &&
			$wpdb->get_row( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'optibehavior_pages' ) )
		) {
			// Check if there's existing data
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration: checking for existing data.
			if ( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'optibehavior_events' ) ||
				$wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'optibehavior_pages' ) ) {
				$options['activated_ver'] = '1.0.0';
			} else {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema changes required for plugin cleanup during migration
				$wpdb->query( 'DROP TABLE ' . $wpdb->prefix . 'optibehavior_events' );
				$wpdb->query( 'DROP TABLE ' . $wpdb->prefix . 'optibehavior_pages' );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
		}

		// Specific version migrations
		if ( isset( $options['activated_ver'] ) ) {
			$this->migrate_to_130( $options['activated_ver'] );
			$this->migrate_to_140( $options['activated_ver'] );
			$this->migrate_to_traffic_classification( $options['activated_ver'] );
			$this->migrate_to_taxonomy_tracking( $options['activated_ver'] );
			$this->migrate_session_pages_metadata( $options['activated_ver'] );
			$this->migrate_to_element_anchor_tracking( $options['activated_ver'] );
		}

		// Always run performance index migration (checks internally if needed)
		$this->migrate_performance_indexes();

		// Always ensure the heatmap_pages Phase 3 aggregate columns + sort indexes
		// exist (checks internally; safety net beside the dbDelta in setup()).
		$this->migrate_heatmap_pages_aggregate_columns();

		// Always ensure the per-day heatmap index schema exists (daily table +
		// daily_* marker columns; checks internally; safety net beside the
		// dbDelta in setup()).
		$this->migrate_heatmap_daily_index_schema();

		// Always run NULL ip → Anonymous migration (checks internally if needed)
		$this->migrate_null_ip_to_anonymous();

		// One-time url2 re-normalisation (tracking-param strip + trailing slash);
		// checks internally if needed.
		$this->migrate_url2_canonical_normalization();

		// Always run empty page-title backfill (checks internally if needed).
		// Cleans up rows created with an empty title (e.g. older Pro session-recording
		// stub inserts) that otherwise surface as "Untitled Page" in the dashboard.
		$this->migrate_backfill_empty_page_titles();

		// Always run sessions.exit_page backfill (checks internally if needed).
		// Historical rows never had exit_page written by the tracker; repairs
		// them from recorded pageviews so the Exit Page filter works.
		$this->migrate_backfill_session_exit_pages();

		// Always run the pageviews.exit_page FLAG backfill (checks internally if
		// needed). Different column from the one above: a tinyint marking the last
		// pageview of each session, which nothing ever wrote (Bug 3).
		$this->migrate_backfill_pageview_exit_flags();

		// One-time aggregate resync after the 1.7.5 allow-list page-identity fix
		// (checks internally if needed).
		$this->migrate_resync_heatmap_aggregates_for_allowlist();

		// One-time data repair: re-classify recently-flagged spam sessions so the
		// permanent-stick case (last heartbeat preceded final click flush) recovers
		// under the canonical classifier. Batched + cron-continued; checks internally.
		$this->migrate_reclassify_flagged_spam_sessions();

		// Always ensure the error/friction/performance tables carry the visitor
		// attribute columns (country/country_name everywhere + os on friction &
		// performance) and backfill them from optibehavior_visitors. Idempotent;
		// re-runs are no-ops (checks columns via INFORMATION_SCHEMA, backfills only
		// NULL rows). Keeps FREE schema in lockstep with PRO for Errors filtering.
		$this->migrate_error_tables_visitor_metadata();
	}

	/**
	 * Add visitor-attribute columns to the error/friction/performance tables and
	 * backfill them from optibehavior_visitors.
	 *
	 * Supports retroactive visitor-attribute filtering on the PRO Errors page:
	 *  - optibehavior_errors        gains country, country_name
	 *  - optibehavior_friction      gains os, country, country_name
	 *  - optibehavior_performance   gains os, country, country_name
	 *
	 * Fully idempotent: columns are added only when INFORMATION_SCHEMA reports them
	 * missing, indexes only when absent, and the backfill UPDATE touches only rows
	 * whose country IS NULL, so repeat runs are no-ops. Never drops or rewrites data.
	 */
	private function migrate_error_tables_visitor_metadata() {
		global $wpdb;

		$visitors = $wpdb->prefix . 'optibehavior_visitors';

		// column additions per table: name => column definition.
		$plan = array(
			'optibehavior_errors'      => array(
				'country'      => "varchar(2) DEFAULT NULL AFTER device_type",
				'country_name' => "varchar(100) DEFAULT NULL AFTER country",
			),
			'optibehavior_friction'    => array(
				'os'           => "varchar(100) DEFAULT NULL AFTER browser",
				'country'      => "varchar(2) DEFAULT NULL AFTER device_type",
				'country_name' => "varchar(100) DEFAULT NULL AFTER country",
			),
			'optibehavior_performance' => array(
				'os'           => "varchar(100) DEFAULT NULL AFTER browser",
				'country'      => "varchar(2) DEFAULT NULL AFTER connection_type",
				'country_name' => "varchar(100) DEFAULT NULL AFTER country",
			),
		);

		$debug_manager = $this->core->get_debug_manager();

		foreach ( $plan as $suffix => $columns ) {
			$table = $wpdb->prefix . $suffix;

			// Skip tables that do not exist on this install (error tables are only
			// created when the PRO error-tracking feature has run its setup).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Migration: table existence probe.
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				continue;
			}

			$safe_table = esc_sql( $table );

			foreach ( $columns as $column => $definition ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration: column existence probe via INFORMATION_SCHEMA.
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
						WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
						$table,
						$column
					)
				);

				if ( ! $exists ) {
					// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Migration: DDL cannot be prepared; table escaped, definition is a literal.
					$wpdb->query( "ALTER TABLE {$safe_table} ADD COLUMN {$column} {$definition}" );
					// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
					$debug_manager->log( "Added {$column} column to {$suffix}", 'debug', 'database' );
				}
			}

			// Add KEY country (country) when absent.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration: index existence probe via INFORMATION_SCHEMA.
			$index_exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
					WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
					$table,
					'country'
				)
			);
			if ( ! $index_exists ) {
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Migration: DDL cannot be prepared; table escaped.
				$wpdb->query( "ALTER TABLE {$safe_table} ADD KEY country (country)" );
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
		}

		// Backfill from visitors. Only runs while the completion flag is unset, and
		// only touches rows with country IS NULL, so re-runs are no-ops. Bounded per
		// pass (LIMIT) so large tables never issue one giant statement; a pending
		// flag lets a later admin load / this same call continue until drained.
		$backfill_done = get_option( 'opti_behavior_error_visitor_backfill_done', '' );
		if ( '1' === $backfill_done ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Migration: visitors table existence probe.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $visitors ) ) ) {
			return;
		}

		$batch_limit = 5000;
		$remaining   = false; // becomes true if any table still has NULL rows to fill.

		foreach ( array( 'optibehavior_errors', 'optibehavior_friction', 'optibehavior_performance' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Migration: table existence probe.
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				continue;
			}

			$safe_table    = esc_sql( $table );
			$safe_visitors = esc_sql( $visitors );

			// Batched UPDATE ... JOIN: restrict to a LIMIT-bounded window of the
			// still-NULL rows so a single pass stays cheap on huge tables.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Migration: table names escaped; batched backfill.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"UPDATE {$safe_table} t
				JOIN ( SELECT id FROM {$safe_table} WHERE country IS NULL AND visitor_id IS NOT NULL LIMIT {$batch_limit} ) sel ON sel.id = t.id
				JOIN {$safe_visitors} v ON v.id = t.visitor_id
				SET t.country = v.country, t.country_name = v.country_name
				WHERE t.country IS NULL"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Any rows left that still map to a known visitor? If so, more passes needed.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Migration: remaining-work probe.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$still = $wpdb->get_var(
				"SELECT COUNT(*) FROM {$safe_table} t JOIN {$safe_visitors} v ON v.id = t.visitor_id WHERE t.country IS NULL"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( $still > 0 ) {
				$remaining = true;
			}
		}

		if ( ! $remaining ) {
			update_option( 'opti_behavior_error_visitor_backfill_done', '1', false );
			$debug_manager->log( 'Error/friction/performance visitor-metadata backfill complete', 'info', 'database' );
		}
	}

	/**
	 * Migration to version 1.3.0
	 *
	 * @param string $current_version Current version.
	 */
	private function migrate_to_130( $current_version ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		if ( version_compare( $current_version, '1.3.0', '<' ) &&
			$wpdb->get_var( "DESCRIBE " . $wpdb->prefix . "optibehavior_events original" )
		) {
			$max_index_length = 191;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema changes required for version 1.3.0 migration
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query( "ALTER TABLE " . $wpdb->prefix . "optibehavior_events CHANGE COLUMN original page_id int(8) UNSIGNED NOT NULL, ADD COLUMN page_id2 int(8) UNSIGNED NOT NULL AFTER page_id, DROP COLUMN keep_query, DROP COLUMN keep_hash, DROP COLUMN united, ADD KEY page_id2 (page_id2)" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query( "UPDATE " . $wpdb->prefix . "optibehavior_events SET page_id2 = page_id" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query( $wpdb->prepare( "ALTER TABLE " . $wpdb->prefix . "optibehavior_pages ADD COLUMN url2 text NOT NULL AFTER url, ADD KEY url2 (url2(%d))", $max_index_length ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/**
	 * Migration to version 1.4.0
	 *
	 * @param string $current_version Current version.
	 */
	private function migrate_to_140( $current_version ) {
		global $wpdb;

		if ( version_compare( $current_version, '1.4.0', '<' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema changes required for version 1.4.0 migration
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			if ( $wpdb->get_var( "DESCRIBE " . $wpdb->prefix . "optibehavior_events accuracy" ) ) {
				$wpdb->query( "ALTER TABLE " . $wpdb->prefix . "optibehavior_events DROP COLUMN accuracy" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			if ( $wpdb->get_var( "SHOW INDEX FROM " . $wpdb->prefix . "optibehavior_events WHERE KEY_NAME = 'event_accuracy'" ) ) {
				$wpdb->query( "DROP INDEX event_accuracy ON " . $wpdb->prefix . "optibehavior_events" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/**
	 * Migration to add traffic classification columns
	 *
	 * @param string $current_version Current version.
	 */
	private function migrate_to_traffic_classification( $current_version ) {
		global $wpdb;

		// Check if traffic_type column already exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration check, caching not appropriate
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = %s",
				DB_NAME,
				$wpdb->prefix . 'optibehavior_sessions',
				'traffic_type'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $column_exists ) {
			$debug_manager = $this->core->get_debug_manager();
			$debug_manager->log( 'Adding traffic classification columns to sessions table', 'info', 'database' );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema changes required for traffic classification feature
			// Add traffic_type column
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_sessions
				ADD COLUMN traffic_type VARCHAR(20) DEFAULT 'human' AFTER ip"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Add bot_type column
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_sessions
				ADD COLUMN bot_type VARCHAR(50) DEFAULT NULL AFTER traffic_type"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Add spam_reason column
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_sessions
				ADD COLUMN spam_reason VARCHAR(100) DEFAULT NULL AFTER bot_type"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Add index for performance
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_sessions
				ADD KEY idx_traffic_type (traffic_type)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

			$debug_manager->log( 'Traffic classification columns added successfully', 'info', 'database' );
		}
	}

	/**
	 * Migration to add taxonomy tracking columns to pages table
	 *
	 * @param string $current_version Current version.
	 */
	private function migrate_to_taxonomy_tracking( $current_version ) {
		global $wpdb;

		// Check if post_id column already exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration check, caching not appropriate
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = %s",
				DB_NAME,
				$wpdb->prefix . 'optibehavior_pages',
				'post_id'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $column_exists ) {
			$debug_manager = $this->core->get_debug_manager();
			$debug_manager->log( 'Adding taxonomy tracking columns to pages table', 'info', 'database' );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema changes required for taxonomy tracking feature
			// Add post_id column
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_pages
				ADD COLUMN post_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER title"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Add term_id column
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_pages
				ADD COLUMN term_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER post_id"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Add taxonomy column
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_pages
				ADD COLUMN taxonomy VARCHAR(50) DEFAULT NULL AFTER term_id"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Add object_type column
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_pages
				ADD COLUMN object_type VARCHAR(20) DEFAULT NULL AFTER taxonomy"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Add indexes for performance
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_pages
				ADD KEY post_id (post_id),
				ADD KEY term_id (term_id),
				ADD KEY taxonomy (taxonomy),
				ADD KEY object_type (object_type)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

			$debug_manager->log( 'Taxonomy tracking columns added successfully', 'info', 'database' );
		}
	}

	/**
	 * Migration to add element-anchored click tracking columns to events table.
	 *
	 * Adds `element_xpath`, `element_rel_x`, `element_rel_y` so click events can be
	 * re-projected onto their anchor element at render time, in addition to (never
	 * replacing) the existing absolute `x`/`y` coordinates. All columns are nullable
	 * and additive-only: legacy rows and rows where the anchor could not be resolved
	 * keep working via the existing absolute-coordinate rendering path.
	 *
	 * @param string $current_version Current version.
	 */
	private function migrate_to_element_anchor_tracking( $current_version ) {
		global $wpdb;

		// Check if element_xpath column already exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration check, caching not appropriate
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = %s",
				DB_NAME,
				$wpdb->prefix . 'optibehavior_events',
				'element_xpath'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $column_exists ) {
			$debug_manager = $this->core->get_debug_manager();
			$debug_manager->log( 'Adding element anchor tracking columns to events table', 'info', 'database' );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema changes required for element-anchored click heatmap feature
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_events
				ADD COLUMN element_xpath VARCHAR(512) DEFAULT NULL AFTER element_href,
				ADD COLUMN element_rel_x FLOAT(7,6) DEFAULT NULL AFTER element_xpath,
				ADD COLUMN element_rel_y FLOAT(7,6) DEFAULT NULL AFTER element_rel_x"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

			$debug_manager->log( 'Element anchor tracking columns added successfully', 'info', 'database' );
		}
	}

	/**
	 * Migration to add metadata columns to session_pages table
	 * This migration fixes the performance issue with 100M+ session recordings
	 * by storing searchable metadata in the database instead of scanning encrypted files
	 *
	 * @param string $current_version Current version.
	 */
	private function migrate_session_pages_metadata( $current_version ) {
		global $wpdb;

		$debug_manager = $this->core->get_debug_manager();

		// Check if metadata columns already exist by checking one key column
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration check, caching not appropriate
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = %s",
				DB_NAME,
				$wpdb->prefix . 'optibehavior_session_pages',
				'device_type'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $column_exists ) {
			$debug_manager->log( 'Starting session_pages metadata migration - adding filterable columns', 'info', 'database' );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema changes required for session recordings performance optimization

			// Add device and browser columns
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_session_pages
				ADD COLUMN device_type VARCHAR(50) DEFAULT NULL AFTER page_order,
				ADD COLUMN browser VARCHAR(100) DEFAULT NULL AFTER device_type,
				ADD COLUMN os VARCHAR(100) DEFAULT NULL AFTER browser"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$debug_manager->log( 'Added device_type, browser, os columns', 'debug', 'database' );

			// Add location columns
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_session_pages
				ADD COLUMN country VARCHAR(2) DEFAULT NULL AFTER os,
				ADD COLUMN country_name VARCHAR(100) DEFAULT NULL AFTER country"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$debug_manager->log( 'Added country, country_name columns', 'debug', 'database' );

			// Add screen resolution columns
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_session_pages
				ADD COLUMN screen_width INT(10) UNSIGNED DEFAULT NULL AFTER country_name,
				ADD COLUMN screen_height INT(10) UNSIGNED DEFAULT NULL AFTER screen_width"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$debug_manager->log( 'Added screen_width, screen_height columns', 'debug', 'database' );

			// Add referrer and visitor type columns
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_session_pages
				ADD COLUMN referrer TEXT DEFAULT NULL AFTER screen_height,
				ADD COLUMN visitor_type VARCHAR(20) DEFAULT NULL AFTER referrer"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$debug_manager->log( 'Added referrer, visitor_type columns', 'debug', 'database' );

			// Add UTM tracking columns
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_session_pages
				ADD COLUMN utm_campaign VARCHAR(100) DEFAULT NULL AFTER visitor_type,
				ADD COLUMN utm_source VARCHAR(100) DEFAULT NULL AFTER utm_campaign,
				ADD COLUMN utm_medium VARCHAR(100) DEFAULT NULL AFTER utm_source"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$debug_manager->log( 'Added utm_campaign, utm_source, utm_medium columns', 'debug', 'database' );

			// Add traffic channel and watched status columns
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_session_pages
				ADD COLUMN traffic_channel VARCHAR(20) DEFAULT NULL AFTER utm_medium,
				ADD COLUMN watched TINYINT(1) UNSIGNED DEFAULT 0 AFTER traffic_channel"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$debug_manager->log( 'Added traffic_channel, watched columns', 'debug', 'database' );

			// Add performance indexes for fast filtering
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $wpdb->prefix . "optibehavior_session_pages
				ADD KEY device_type (device_type),
				ADD KEY browser (browser),
				ADD KEY country (country),
				ADD KEY visitor_type (visitor_type),
				ADD KEY watched (watched)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$debug_manager->log( 'Added performance indexes', 'debug', 'database' );

			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

			// Get table statistics
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$total_rows = $wpdb->get_var( "SELECT COUNT(*) FROM " . $wpdb->prefix . "optibehavior_session_pages" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$old_size = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ROUND((data_length + index_length) / 1024 / 1024, 2) as size_mb
					FROM information_schema.tables
					WHERE table_schema = %s
					AND table_name = %s",
					DB_NAME,
					$wpdb->prefix . 'optibehavior_session_pages'
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			$debug_manager->log(
				sprintf(
					'Session pages metadata migration completed successfully. Total rows: %s, Table size: %s MB',
					number_format( $total_rows ),
					$old_size
				),
				'info',
				'database'
			);

			$debug_manager->log(
				'IMPORTANT: New session recordings will automatically save metadata. Old recordings will continue to work but won\'t have metadata for filtering.',
				'info',
				'database'
			);
		} else {
			$debug_manager->log( 'Session pages metadata columns already exist, skipping migration', 'debug', 'database' );
		}
	}

	/**
	 * Migration to add performance indexes for spam filtering optimization
	 *
	 * Adds composite indexes to sessions table for faster spam filter queries.
	 * These indexes significantly improve dashboard load times when spam filtering is enabled.
	 */
	private function migrate_performance_indexes() {
		global $wpdb;

		$debug_manager = $this->core->get_debug_manager();
		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';

		// Guard: skip entirely if the sessions table does not exist yet
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check before any ALTER TABLE
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions_table ) ) ) {
			$debug_manager->log( 'Sessions table does not exist yet, skipping performance index migration', 'debug', 'database' );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			return;
		}

		// PRODUCTION SAFETY (C2-3): the ALTERs below run inline. On a LARGE
		// sessions table that is minutes-long blocking DDL inside a web
		// request — defer everything to the background worker instead (all
		// these index defs are part of its managed list).
		if ( 'cli' !== PHP_SAPI && $this->is_large_table( $sessions_table ) ) {
			$this->schedule_heavy_migrations();
			// Still delegate to add_large_dataset_indexes(): it defers its own
			// large builds and handles the version-keyed completion flag.
			$this->add_large_dataset_indexes();
			return;
		}

		// Check if the composite spam filter index already exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration check, caching not appropriate
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$index_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND INDEX_NAME = %s",
				DB_NAME,
				$sessions_table,
				'idx_spam_filter'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $index_exists ) {
			$debug_manager->log( 'Adding performance indexes for spam filtering optimization', 'info', 'database' );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema changes required for performance optimization
			// Add composite index for spam filter queries (covers date range + traffic type + duration + events)
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $sessions_table . "
				ADD KEY idx_spam_filter (start_time, traffic_type, duration, events_count)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Add individual indexes for duration and events_count if they don't exist
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$duration_index_exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
					WHERE TABLE_SCHEMA = %s
					AND TABLE_NAME = %s
					AND INDEX_NAME = %s",
					DB_NAME,
					$sessions_table,
					'idx_duration'
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! $duration_index_exists ) {
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				$wpdb->query(
					"ALTER TABLE " . $sessions_table . "
					ADD KEY idx_duration (duration)"
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$events_index_exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
					WHERE TABLE_SCHEMA = %s
					AND TABLE_NAME = %s
					AND INDEX_NAME = %s",
					DB_NAME,
					$sessions_table,
					'idx_events_count'
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! $events_index_exists ) {
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				$wpdb->query(
					"ALTER TABLE " . $sessions_table . "
					ADD KEY idx_events_count (events_count)"
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

			$debug_manager->log( 'Performance indexes added successfully', 'info', 'database' );
		} else {
			$debug_manager->log( 'Performance indexes already exist, skipping migration', 'debug', 'database' );
		}
		// Add additional performance indexes for large dataset optimization (1M+ rows)
		$this->add_large_dataset_indexes();
	}

	/**
	 * Migrate sessions that have ip = NULL or ip = '' to ip = 'Anonymous'.
	 *
	 * These rows were caused by a race condition in ajax_save_recording() that could create
	 * a session record before ajax_register_page_visit() had a chance to store the correct
	 * ip value.  The fix stores 'Anonymous' in the fallback INSERT, but this one-time
	 * migration cleans up any existing rows that already have a NULL/empty ip.
	 *
	 * Public so it can also be triggered from admin_init for already-active installs
	 * that haven't gone through a fresh setup() cycle yet.
	 */
	public function migrate_null_ip_to_anonymous() {
		global $wpdb;

		$debug_manager  = $this->core->get_debug_manager();
		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';

		// Guard: skip if table doesn't exist yet
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check before migration
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions_table ) ) ) {
			return;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// Use a one-time option flag so we only run this expensive UPDATE once
		if ( get_option( 'opti_behavior_null_ip_migrated' ) ) {
			return;
		}

		$debug_manager->log( 'Running NULL ip → Anonymous migration for sessions table', 'info', 'database' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time data migration; no caching needed
		$updated = $wpdb->query(
			$wpdb->prepare( 'UPDATE ' . $wpdb->prefix . 'optibehavior_sessions SET ip = %s WHERE ip IS NULL OR ip = %s', 'Anonymous', '' )
		);

		$debug_manager->log( 'NULL ip migration complete: ' . (int) $updated . ' row(s) updated', 'info', 'database' );

		update_option( 'opti_behavior_null_ip_migrated', '1', false );
	}

	/**
	 * Backfill empty/NULL page titles in the pages table.
	 *
	 * Older Pro session-recording code inserted page rows with an empty title and no
	 * url2/object_type metadata. Those rows render as "Untitled Page" in the dashboard.
	 * This one-time migration re-runs each affected URL through the analytics resolver
	 * (get_or_create_page_id), which recomputes a proper title, url2, and taxonomy
	 * metadata in place — the same resolution the live event ingest performs.
	 *
	 * Public so it can also be triggered from admin_init for already-active installs
	 * that haven't gone through a fresh setup() cycle yet.
	 */
	public function migrate_backfill_empty_page_titles() {
		global $wpdb;

		$debug_manager = $this->core->get_debug_manager();
		$pages_table   = $wpdb->prefix . 'optibehavior_pages';

		// Guard: skip if table doesn't exist yet.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check before migration
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pages_table ) ) ) {
			return;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// One-time flag so we only run this scan/update once.
		if ( get_option( 'opti_behavior_empty_titles_backfilled' ) ) {
			return;
		}

		$analytics = $this->core->get_analytics();
		if ( ! $analytics || ! method_exists( $analytics, 'get_or_create_page_id' ) ) {
			// Resolver unavailable this request; leave the flag unset so a later
			// request retries.
			return;
		}

		// Fetch rows with an empty or NULL title that have a usable URL.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time data migration; no caching needed
		$rows = $wpdb->get_results(
			"SELECT id, url FROM {$wpdb->prefix}optibehavior_pages WHERE ( title IS NULL OR title = '' ) AND url <> '' LIMIT 5000"
		);

		$updated = 0;
		if ( $rows ) {
			$debug_manager->log( 'Running empty page-title backfill for ' . count( $rows ) . ' row(s)', 'info', 'database' );

			foreach ( $rows as $row ) {
				// Resolver recomputes proper title + url2 + object_type and updates in place.
				$analytics->get_or_create_page_id( $row->url, '' );
				++$updated;
			}

			$debug_manager->log( 'Empty page-title backfill complete: ' . (int) $updated . ' row(s) processed', 'info', 'database' );
		}

		// Remove orphan stub rows with an empty URL and no attached events. These are
		// unusable (no URL to resolve, nothing references them) and would otherwise
		// linger as "Untitled Page" ghosts. Guarded by the events-count subquery so a
		// row that any event references is never deleted.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time cleanup of orphan rows
		$deleted = $wpdb->query(
			"DELETE FROM {$wpdb->prefix}optibehavior_pages
			 WHERE ( url IS NULL OR url = '' )
			   AND ( title IS NULL OR title = '' )
			   AND id NOT IN (
			       SELECT page_id FROM {$wpdb->prefix}optibehavior_events
			       UNION
			       SELECT page_id2 FROM {$wpdb->prefix}optibehavior_events
			   )"
		);
		if ( $deleted ) {
			$debug_manager->log( 'Removed ' . (int) $deleted . ' orphan url-less page stub row(s)', 'info', 'database' );
		}

		update_option( 'opti_behavior_empty_titles_backfilled', '1', false );
	}

	/**
	 * One-time backfill of sessions.exit_page from recorded pageviews.
	 *
	 * Historically the tracker never wrote sessions.exit_page (only entry_page
	 * at insert), leaving the column NULL on every live install. That made the
	 * dashboard's Exit Page advanced filter (s.exit_page LIKE %s) and the
	 * Exit Page filter suggestions permanently empty. Going forward the AJAX
	 * handler keeps the column current on every pageview
	 * (sync_session_exit_page()); this migration repairs historical rows:
	 *
	 *  - Pass 1: sessions that have pageview rows get the URL of their LAST
	 *    pageview (max view_time, id as tiebreaker). Batched by session id so
	 *    huge installs cannot lock the table in one statement.
	 *  - Pass 2: remaining empty rows (no pageview rows recorded/retained) fall
	 *    back to entry_page — for a single-page session the entry IS the exit.
	 *
	 * Idempotent: guarded by an option flag that is only written after BOTH
	 * passes fully complete, so an interrupted run (batch cap hit / request
	 * killed) simply resumes on a later admin_init.
	 */
	public function migrate_backfill_session_exit_pages() {
		global $wpdb;

		// One-time flag so we only run this backfill once.
		if ( get_option( 'opti_behavior_exit_pages_backfilled' ) ) {
			return;
		}

		$sessions_table  = $wpdb->prefix . 'optibehavior_sessions';
		$pageviews_table = $wpdb->prefix . 'optibehavior_pageviews';

		// Guard: skip if the tables don't exist yet.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check before migration
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions_table ) ) ||
			! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pageviews_table ) ) ) {
			return;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$debug_manager = $this->core->get_debug_manager();
		$batch_size    = 2000;
		$max_batches   = 20; // Per-request cap; an unfinished run resumes next admin_init.

		// Pass 1: last recorded pageview URL for sessions that have pageviews.
		for ( $batch = 0; $batch < $max_batches; $batch++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time data migration; table names from $wpdb->prefix.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT s.id FROM {$sessions_table} s
				 WHERE ( s.exit_page IS NULL OR s.exit_page = '' )
				   AND EXISTS ( SELECT 1 FROM {$pageviews_table} pv WHERE pv.session_id = s.id )
				 LIMIT %d",
				$batch_size
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( empty( $ids ) ) {
				break;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
			// Correlated subquery rides the pageviews session_id index; the batch
			// IN() list keeps each statement short-lived.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time data migration; placeholders built from validated batch ids.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$sessions_table} s
				 SET s.exit_page = (
					SELECT pv.url FROM {$pageviews_table} pv
					WHERE pv.session_id = s.id
					ORDER BY pv.view_time DESC, pv.id DESC
					LIMIT 1
				 )
				 WHERE s.id IN ( {$placeholders} )",
				$ids
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			$debug_manager->log( 'Exit-page backfill pass 1: updated batch of ' . count( $ids ) . ' session(s)', 'info', 'database' );

			if ( count( $ids ) < $batch_size ) {
				break;
			}

			if ( $batch === $max_batches - 1 ) {
				// Cap hit with a full batch: more rows likely remain. Leave the
				// flag unset so the next admin_init continues the backfill.
				return;
			}
		}

		// Pass 2: sessions with no pageview rows at all fall back to entry_page.
		for ( $batch = 0; $batch < $max_batches; $batch++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time data migration; table names from $wpdb->prefix.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$affected = $wpdb->query( $wpdb->prepare(
				"UPDATE {$sessions_table}
				 SET exit_page = entry_page
				 WHERE ( exit_page IS NULL OR exit_page = '' )
				   AND entry_page IS NOT NULL AND entry_page != ''
				 LIMIT %d",
				$batch_size
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! $affected ) {
				break;
			}

			$debug_manager->log( 'Exit-page backfill pass 2: updated ' . (int) $affected . ' bounce session(s)', 'info', 'database' );

			if ( $affected < $batch_size ) {
				break;
			}

			if ( $batch === $max_batches - 1 ) {
				return;
			}
		}

		update_option( 'opti_behavior_exit_pages_backfilled', '1', false );
		$debug_manager->log( 'Exit-page backfill complete', 'info', 'database' );
	}

	/**
	 * One-time backfill of the pageviews.exit_page FLAG.
	 *
	 * Bug 3: `{prefix}optibehavior_pageviews.exit_page` (tinyint) was never written
	 * by any code path, so the Smart Insights aggregator's
	 * `COUNT(DISTINCT CASE WHEN pv.exit_page = 1 ...)` was always 0, every page
	 * reported exit_rate 0/null, and `high_exit_rate_page` could not trigger.
	 * Going forward the AJAX handler maintains the flag on every pageview insert
	 * (sync_pageview_exit_flag()); this repairs historical rows by marking the LAST
	 * pageview of each session (max view_time, id as tiebreaker).
	 *
	 * Distinct from migrate_backfill_session_exit_pages(), which fills the TEXT
	 * `sessions.exit_page` URL column.
	 *
	 * Batched by session id so the pageviews table — the largest in the schema —
	 * is never locked by one statement. Idempotent: the option flag is written only
	 * after the pass fully drains, so an interrupted run resumes on a later
	 * admin_init.
	 *
	 * @since 1.0.9
	 */
	public function migrate_backfill_pageview_exit_flags() {
		global $wpdb;

		if ( get_option( 'opti_behavior_pageview_exit_flags_backfilled' ) ) {
			return;
		}

		$pageviews_table = $wpdb->prefix . 'optibehavior_pageviews';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix (never user input), values bound via $wpdb->prepare(); one-time data migration.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pageviews_table ) ) ) {
			return;
		}

		// Mid-upgrade installs may not have the column yet: try again next time.
		$has_column = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$pageviews_table,
				'exit_page'
			)
		);
		if ( ! $has_column ) {
			return;
		}

		$debug_manager = $this->core->get_debug_manager();
		$batch_size    = 2000;
		$max_batches   = 20; // Per-request cap; an unfinished run resumes next admin_init.

		for ( $batch = 0; $batch < $max_batches; $batch++ ) {
			// Sessions that have pageviews but no exit flag on any of them yet.
			$session_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT session_id
					 FROM {$pageviews_table}
					 WHERE session_id IS NOT NULL AND session_id <> ''
					 GROUP BY session_id
					 HAVING MAX(exit_page) = 0
					 LIMIT %d",
					$batch_size
				)
			);

			if ( empty( $session_ids ) ) {
				break;
			}

			$placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );

			// Correlated subquery picks each session's last pageview and rides the
			// pageviews session_id index; the IN() batch keeps the statement short.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$pageviews_table} pv
					 INNER JOIN (
						SELECT session_id, MAX(view_time) AS last_view
						FROM {$pageviews_table}
						WHERE session_id IN ( {$placeholders} )
						GROUP BY session_id
					 ) last_pv ON last_pv.session_id = pv.session_id AND last_pv.last_view = pv.view_time
					 SET pv.exit_page = 1",
					$session_ids
				)
			);

			$debug_manager->log( 'Pageview exit-flag backfill: marked batch of ' . count( $session_ids ) . ' session(s)', 'info', 'database' );

			if ( count( $session_ids ) < $batch_size ) {
				break;
			}

			if ( $batch === $max_batches - 1 ) {
				// Cap hit with a full batch: more rows likely remain. Leave the flag
				// unset so the next admin_init continues the backfill.
				return;
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		update_option( 'opti_behavior_pageview_exit_flags_backfilled', '1', false );
		$debug_manager->log( 'Pageview exit-flag backfill complete', 'info', 'database' );
	}

	/**
	 * One-time resync of the heatmap aggregate columns after the allow-list
	 * page-identity fix (1.7.5, staging Bug C).
	 *
	 * The spam allow-list used by batch_heatmap_file_metrics_by_page() now
	 * matches session evidence by normalized URL as well as page_id, so
	 * aggregate values precomputed under the old single-page_id logic can
	 * undercount sessions/interactions. Marking every synced row stale
	 * (agg_synced_at = NULL) makes sync_heatmap_aggregate_columns() recompute
	 * them lazily — in capped batches — under the fixed logic on the next
	 * admin loads. No data is changed here, only the "is exact" marker.
	 */
	public function migrate_resync_heatmap_aggregates_for_allowlist() {
		global $wpdb;

		// One-time flag so we only invalidate once per install.
		if ( get_option( 'opti_behavior_hm_agg_allowlist_url_resynced' ) ) {
			return;
		}

		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// Guard: skip if the mapping table doesn't exist yet.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check before migration
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// Guard: aggregate columns may not exist yet (interrupted upgrade); the
		// column migration runs first, so just retry on a later request.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema capability probe
		$has_col = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				'agg_synced_at'
			)
		);
		if ( ! $has_col ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time staleness marker; identifier hard-coded from $wpdb->prefix.
		$marked = $wpdb->query( "UPDATE {$table} SET agg_synced_at = NULL WHERE agg_synced_at IS NOT NULL" );

		$debug_manager = $this->core->get_debug_manager();
		if ( $debug_manager ) {
			$debug_manager->log( 'Heatmap aggregate allow-list resync: marked ' . (int) $marked . ' row(s) stale for recompute', 'info', 'database' );
		}

		update_option( 'opti_behavior_hm_agg_allowlist_url_resynced', '1', false );
	}

	/**
	 * One-time cleanup of duplicate `optibehavior_heatmap_pages` mappings (BUG-1).
	 *
	 * Historically a single page_id could accumulate more than one mapping row
	 * (a live canonical URL plus a stale QA/alias URL whose WP post was later
	 * deleted, or after an AUTO_INCREMENT id reuse). Because the file
	 * aggregators union every hash dir mapped to a page, those siblings
	 * double-count every heatmap-derived number and let the aggregate sync
	 * stamp identical agg_* onto each row. This collapses each duplicated
	 * page_id down to one canonical row (see
	 * {@see Opti_Behavior_Heatmap_Storage::dedupe_page_mappings()}), archiving
	 * the stale rows' on-disk data under `_orphaned/` (never hard-deleted).
	 *
	 * Idempotent: guarded by an option flag. Also runs opportunistically on
	 * ingest (storage insert branch) so duplicates can never re-accumulate.
	 *
	 * @since 1.8.4
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function migrate_dedupe_heatmap_page_mappings() {
		global $wpdb;

		// One-time flag so the full-table sweep only runs once per install.
		if ( get_option( 'opti_behavior_hm_pages_deduped' ) ) {
			return;
		}

		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// Guard: skip if the mapping table doesn't exist yet.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check before migration.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		if ( ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			return;
		}

		// Every page_id with more than one mapping row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier hard-coded from $wpdb->prefix.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$dup_ids = $wpdb->get_col(
			"SELECT page_id FROM {$table} GROUP BY page_id HAVING COUNT(*) > 1"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$dup_ids = array_values( array_filter( array_map( 'intval', (array) $dup_ids ) ) );

		if ( empty( $dup_ids ) ) {
			update_option( 'opti_behavior_hm_pages_deduped', '1', false );
			return;
		}

		$storage       = Opti_Behavior_Heatmap_Storage::get_instance();
		$affected       = array();
		$total_removed  = 0;
		foreach ( $dup_ids as $pid ) {
			$res = $storage->dedupe_page_mappings( $pid );
			if ( ! empty( $res['removed'] ) ) {
				$affected[]     = $pid;
				$total_removed += (int) $res['removed'];
			}
		}

		// Recompute aggregates for the collapsed pages, then rotate every
		// heatmap cache so the dashboard re-reads the deduped universe.
		if ( ! empty( $affected ) ) {
			$storage->mark_pages_stale( $affected );
			if ( function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
				opti_behavior_flush_heatmap_caches();
			}
		}

		$debug_manager = $this->core->get_debug_manager();
		if ( $debug_manager ) {
			$debug_manager->log(
				sprintf(
					'Heatmap mapping dedupe: collapsed %d duplicated page_id(s), archived %d stale row(s)/dir(s)',
					count( $affected ),
					$total_removed
				),
				'info',
				'database'
			);
		}

		update_option( 'opti_behavior_hm_pages_deduped', '1', false );
	}

	/**
	 * One-time purge of STALE single-row QA heatmap mappings (data repair).
	 *
	 * Companion to {@see migrate_dedupe_heatmap_page_mappings()}: the dedupe pass
	 * only collapses page_ids that have MORE than one mapping row, so a page
	 * whose ONLY row is a stale QA fixture URL (e.g. `ob-pro-qa-…`,
	 * `ob-dash-qa-…`) that no longer matches the page's current canonical /
	 * A/B-variant identity survives it untouched — its sessions kept inflating
	 * the multi-variant detail pill (the homepage group summed the QA rows of its
	 * A/B-variant page_ids). Delegates the conservative, non-destructive purge
	 * (QA slug AND url_hash != page's canonical hash; dir archived to
	 * `_orphaned/`, never hard-deleted) to
	 * {@see Opti_Behavior_Heatmap_Storage::purge_stale_qa_page_mappings()}, then
	 * marks the collapsed pages stale and rotates the heatmap caches so the
	 * dashboard re-reads the cleaned universe.
	 *
	 * Idempotent: guarded by its own option flag.
	 *
	 * @since 2026-08-13 (stale-QA single-row purge)
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @return void
	 */
	public function migrate_purge_stale_qa_page_mappings() {
		global $wpdb;

		// One-time flag so the full-table sweep only runs once per install.
		if ( get_option( 'opti_behavior_hm_qa_mappings_purged' ) ) {
			return;
		}

		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// Guard: skip if the mapping table doesn't exist yet.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check before migration.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		if ( ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			return;
		}

		$storage = Opti_Behavior_Heatmap_Storage::get_instance();
		$res     = $storage->purge_stale_qa_page_mappings();

		// Recompute aggregates for the affected pages, then rotate every heatmap
		// cache so the dashboard re-reads the cleaned universe.
		if ( ! empty( $res['affected'] ) ) {
			$storage->mark_pages_stale( $res['affected'] );
			if ( function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
				opti_behavior_flush_heatmap_caches();
			}
		}

		$debug_manager = $this->core->get_debug_manager();
		if ( $debug_manager ) {
			$debug_manager->log(
				sprintf(
					'Stale-QA heatmap mapping purge: removed %d row(s)/dir(s) across %d page(s)',
					(int) $res['removed'],
					count( (array) $res['affected'] )
				),
				'info',
				'database'
			);
		}

		update_option( 'opti_behavior_hm_qa_mappings_purged', '1', false );
	}

	/**
	 * One-time archival of ORPHAN heatmap file sessions (file/DB reconciliation).
	 *
	 * Master decision 2026-08-13: every user-facing session surface reads ONE
	 * canonical universe = distinct human, spam-excluded pageview sessions. The
	 * heatmap file store, however, still held session tokens with NO matching DB
	 * session row (e.g. files kept after their DB rows were pruned by
	 * retention/cleanup, or written by a beacon whose session never persisted).
	 * Those orphans inflated the file-derived detail surfaces (Visitor Type
	 * dropdown, Views strip) above the canonical pill. This sweep archives every
	 * such orphan FILE to `_orphaned/` (moved, never hard-deleted — fully
	 * recoverable), then marks the affected pages stale and rotates the heatmap
	 * caches so the dashboard re-reads the reconciled universe.
	 *
	 * Orphan detection reuses the live Free dashboard orphan gate
	 * (get_heatmap_known_db_session_lookup_for_page_bridge(), RC-C / spec §3.2):
	 * a file token with no 3-form match in wp_optibehavior_sessions is an orphan.
	 * When that resolver cannot restrict safely for a page it returns boolean
	 * `true` (fail-safe) and NOTHING is archived for that page.
	 *
	 * Idempotent: guarded by its own option flag.
	 *
	 * @since 2026-08-13 (file/DB orphan reconciliation)
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @return void
	 */
	public function migrate_archive_orphan_heatmap_file_sessions() {
		global $wpdb;

		// One-time flag so the full-store sweep only runs once per install.
		if ( get_option( 'opti_behavior_hm_orphan_files_archived' ) ) {
			return;
		}

		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// Guard: skip if the mapping table doesn't exist yet.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check before migration.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		if ( ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) || ! class_exists( 'Opti_Behavior_Heatmap_Core' ) ) {
			return;
		}

		// The orphan gate lives on the live Free dashboard instance.
		$core      = Opti_Behavior_Heatmap_Core::get_instance();
		$dashboard = ( $core && method_exists( $core, 'get_dashboard' ) ) ? $core->get_dashboard() : null;
		if ( ! $dashboard || ! method_exists( $dashboard, 'get_heatmap_known_db_session_lookup_for_page_bridge' ) ) {
			return; // Cannot resolve the known-session lookup — defer (flag stays unset).
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier hard-coded from $wpdb->prefix.
		$page_ids = $wpdb->get_col( "SELECT DISTINCT page_id FROM {$table}" );
		$page_ids = array_values( array_filter( array_map( 'intval', (array) $page_ids ) ) );

		if ( empty( $page_ids ) ) {
			update_option( 'opti_behavior_hm_orphan_files_archived', '1', false );
			return;
		}

		$storage        = Opti_Behavior_Heatmap_Storage::get_instance();
		$affected       = array();
		$total_files    = 0;
		$total_sessions = 0;

		foreach ( $page_ids as $pid ) {
			$lookup = $dashboard->get_heatmap_known_db_session_lookup_for_page_bridge( $pid );
			$res    = $storage->archive_orphan_file_sessions_for_page( $pid, $lookup );
			if ( ! empty( $res['archived'] ) ) {
				$affected[]      = $pid;
				$total_files    += (int) $res['archived'];
				$total_sessions += count( (array) $res['sessions'] );
			}
		}

		// Recompute aggregates for the reconciled pages, then rotate every heatmap
		// cache so the dashboard re-reads the cleaned universe.
		if ( ! empty( $affected ) ) {
			$storage->mark_pages_stale( $affected );
			if ( function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
				opti_behavior_flush_heatmap_caches();
			}
		}

		$debug_manager = $this->core->get_debug_manager();
		if ( $debug_manager ) {
			$debug_manager->log(
				sprintf(
					'Heatmap orphan-file archival: moved %d orphan file(s) across %d session(s) on %d page(s) to _orphaned/',
					$total_files,
					$total_sessions,
					count( $affected )
				),
				'info',
				'database'
			);
		}

		update_option( 'opti_behavior_hm_orphan_files_archived', '1', false );
	}

	/**
	 * One-time re-classification of recently-flagged spam sessions (data repair).
	 *
	 * Repairs the permanent-stick case (RC-1): a session whose last heartbeat
	 * preceded the final heatmap click/scroll flush keeps a stale `spam` flag
	 * because the Free ingest lifecycle never re-evaluates it (the Pro
	 * re-classifier is commonly absent). Re-runs the canonical classifier over
	 * `spam` rows in start_time-descending, id-stable batches so it stays safe
	 * on large tables; a single scheduled event continues the scan across
	 * requests until the window is exhausted. Traffic classification caches are
	 * cleared by the classifier as sessions change.
	 *
	 * Idempotent: guarded by a completion option flag plus a resumable cursor.
	 * Public so it can be triggered from admin_init on already-active installs
	 * that have not gone through a fresh setup() cycle yet.
	 *
	 * @since 1.7.1
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function migrate_reclassify_flagged_spam_sessions() {
		global $wpdb;

		// One-time flag so the repair only runs to completion once per install.
		if ( get_option( self::RECLASSIFY_SPAM_DONE_OPTION ) ) {
			return;
		}

		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';

		// Guard: skip if the sessions table does not exist yet.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check before migration
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions_table ) ) ) {
			return;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// Process one batch inline; the remainder (if any) is handed off to a
		// single scheduled event so no admin_init request re-classifies an
		// unbounded number of rows.
		$this->run_reclassify_spam_batch();
	}

	/**
	 * Run one spam re-classification batch and persist the resumable cursor.
	 *
	 * Shared by the inline migration step and the continuation cron. Delegates
	 * the batch scan/classify to the canonical classifier, advances the cursor,
	 * and — when the scan is exhausted — sets the completion flag, clears the
	 * cursor, and unschedules the continuation cron.
	 *
	 * @since 1.7.1
	 * @return array {
	 *     Batch result.
	 *     @type int  $processed Sessions scanned this batch.
	 *     @type int  $changed   Sessions whose classification flipped.
	 *     @type bool $done      Whether the scan is complete.
	 * }
	 */
	public function run_reclassify_spam_batch() {
		if ( get_option( self::RECLASSIFY_SPAM_DONE_OPTION ) ) {
			wp_clear_scheduled_hook( self::RECLASSIFY_SPAM_CRON_HOOK );
			return array( 'processed' => 0, 'changed' => 0, 'done' => true );
		}

		if ( ! class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
			$filter_file = __DIR__ . '/class-opti-behavior-stats-spam-filter.php';
			if ( file_exists( $filter_file ) ) {
				require_once $filter_file;
			}
			if ( ! class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
				// Classifier unavailable this request; retry on a later call.
				return array( 'processed' => 0, 'changed' => 0, 'done' => false );
			}
		}

		$cursor = get_option( self::RECLASSIFY_SPAM_CURSOR_OPTION, null );
		if ( ! is_array( $cursor ) ) {
			$cursor = null;
		}

		$result = Opti_Behavior_Stats_Spam_Filter::reclassify_flagged_sessions_batch(
			array(
				'limit'       => self::RECLASSIFY_SPAM_BATCH_SIZE,
				'window_days' => self::RECLASSIFY_SPAM_WINDOW_DAYS,
				'cursor'      => $cursor,
			)
		);

		$debug_manager = $this->core->get_debug_manager();
		if ( $debug_manager ) {
			$debug_manager->log(
				sprintf(
					'Spam re-classification batch: processed %d, changed %d, done %s',
					(int) $result['processed'],
					(int) $result['changed'],
					! empty( $result['done'] ) ? 'yes' : 'no'
				),
				'info',
				'database'
			);
		}

		if ( ! empty( $result['done'] ) ) {
			update_option( self::RECLASSIFY_SPAM_DONE_OPTION, '1', false );
			delete_option( self::RECLASSIFY_SPAM_CURSOR_OPTION );
			wp_clear_scheduled_hook( self::RECLASSIFY_SPAM_CRON_HOOK );
		} else {
			update_option( self::RECLASSIFY_SPAM_CURSOR_OPTION, $result['cursor'], false );
			if ( ! wp_next_scheduled( self::RECLASSIFY_SPAM_CRON_HOOK ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::RECLASSIFY_SPAM_CRON_HOOK );
			}
		}

		return $result;
	}

	/**
	 * Ensure the recurring stale-session finalization sweep is scheduled.
	 *
	 * Hourly recurrence: the sweep is a safety net for lost `is_final` beacons,
	 * not a primary classification path, so hourly is frequent enough to keep
	 * reports honest while staying cheap (the watermark makes each ended session
	 * scan exactly once). Safe to call on every admin_init.
	 *
	 * @since 1.7.1
	 * @return void
	 */
	public function ensure_finalize_stale_sessions_cron() {
		if ( ! wp_next_scheduled( self::FINALIZE_STALE_CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::FINALIZE_STALE_CRON_HOOK );
		}
	}

	/**
	 * Run one stale-session finalization sweep batch.
	 *
	 * Wraps Opti_Behavior_Stats_Spam_Filter::finalize_stale_sessions_batch()
	 * with persistent pass state:
	 *  - a pass is defined by a fixed `cutoff` (now - grace window, computed once
	 *    at pass start so chained batches scan a stable range);
	 *  - the watermark (last completed pass's cutoff) is the pass lower bound, so
	 *    every ended session is finalized exactly once;
	 *  - an unfinished pass hands the remainder to a single scheduled event, so
	 *    no request finalizes an unbounded number of rows;
	 *  - on completion the watermark advances to the pass cutoff.
	 *
	 * Triggered by the hourly cron and opportunistically when the analytics
	 * dashboard loads (so an admin looking at the numbers sees corrected data
	 * without waiting for cron).
	 *
	 * @since 1.7.1
	 * @return array {
	 *     Batch result.
	 *     @type int  $processed Sessions scanned this batch.
	 *     @type int  $changed   Sessions whose classification flipped.
	 *     @type bool $done      Whether the current pass is complete.
	 * }
	 */
	public function run_finalize_stale_sessions_batch() {
		if ( ! class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
			$filter_file = __DIR__ . '/class-opti-behavior-stats-spam-filter.php';
			if ( file_exists( $filter_file ) ) {
				require_once $filter_file;
			}
			if ( ! class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
				// Classifier unavailable this request; retry on a later call.
				return array( 'processed' => 0, 'changed' => 0, 'done' => false );
			}
		}

		$watermark = get_option( self::FINALIZE_STALE_WATERMARK_OPTION, '' );
		$state     = get_option( self::FINALIZE_STALE_STATE_OPTION, null );

		if ( ! is_array( $state ) || empty( $state['cutoff'] ) ) {
			// Start a new pass. The cutoff uses the current_time('mysql') clock
			// basis — the same clock that stamps session start/end times — so
			// PHP/DB clock skew cannot misjudge staleness.
			$cutoff = gmdate(
				'Y-m-d H:i:s',
				strtotime( current_time( 'mysql' ) ) - self::FINALIZE_STALE_GRACE_MINUTES * MINUTE_IN_SECONDS
			);

			// Nothing can have ended in an empty/negative window; skip the scan.
			if ( '' !== $watermark && $cutoff <= $watermark ) {
				return array( 'processed' => 0, 'changed' => 0, 'done' => true );
			}

			$state = array(
				'cutoff' => $cutoff,
				'cursor' => null,
			);
		}

		$result = Opti_Behavior_Stats_Spam_Filter::finalize_stale_sessions_batch(
			array(
				'limit'        => self::FINALIZE_STALE_BATCH_SIZE,
				'window_days'  => self::FINALIZE_STALE_WINDOW_DAYS,
				'stale_before' => $state['cutoff'],
				'since'        => '' !== $watermark ? $watermark : null,
				'cursor'       => isset( $state['cursor'] ) ? $state['cursor'] : null,
			)
		);

		$debug_manager = $this->core->get_debug_manager();
		if ( $debug_manager ) {
			$debug_manager->log(
				sprintf(
					'Stale-session finalize sweep batch: processed %d, changed %d, done %s',
					(int) $result['processed'],
					(int) $result['changed'],
					! empty( $result['done'] ) ? 'yes' : 'no'
				),
				'info',
				'database'
			);
		}

		if ( ! empty( $result['done'] ) ) {
			update_option( self::FINALIZE_STALE_WATERMARK_OPTION, $state['cutoff'], false );
			delete_option( self::FINALIZE_STALE_STATE_OPTION );
		} else {
			$state['cursor'] = $result['cursor'];
			update_option( self::FINALIZE_STALE_STATE_OPTION, $state, false );
			// Chain the remainder promptly. The hourly recurring event for this
			// hook usually exists, so a bare wp_next_scheduled() guard would skip
			// the continuation and stall the pass for up to an hour; only skip
			// when some run of this hook is already imminent.
			$next = wp_next_scheduled( self::FINALIZE_STALE_CRON_HOOK );
			if ( ! $next || $next > time() + 2 * MINUTE_IN_SECONDS ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::FINALIZE_STALE_CRON_HOOK );
			}
		}

		return $result;
	}

	/**
	 * Add performance indexes optimized for large datasets (1M+ rows)
	 *
	 * These indexes are critical for dashboard performance when handling
	 * millions of sessions, pageviews, and events.
	 */
	private function add_large_dataset_indexes() {
		global $wpdb;

		$debug_manager = $this->core->get_debug_manager();

		// RESILIENCE: a large-table ADD KEY can exceed max_execution_time. setup()
		// only persists activated_ver AFTER every migration, so a fatal mid-ALTER
		// would leave activated_ver unset and force a full setup() (dbDelta over all
		// tables + this index loop) on every single admin load. Two safeguards:
		//   1. Each ALTER below is independently idempotent (skipped when the index
		//      already exists) and DDL auto-commits, so a partial run is resumable -
		//      a re-run only builds the indexes that are still missing.
		//   2. A version-keyed completion flag lets a fully-finished migration
		//      early-return before the per-index INFORMATION_SCHEMA probes, so once
		//      the indexes exist setup() reaches its activated_ver persistence quickly
		//      and stops re-running. This is a structural/schema flag, not analytics
		//      data, so persisting it does not violate the live-stats rule.
		$indexes_done_ver = get_option( 'opti_behavior_large_indexes_ver' );
		if ( OPTI_BEHAVIOR_HEATMAP_VERSION === $indexes_done_ver ) {
			$debug_manager->log( 'Large dataset indexes already verified for this version, skipping', 'debug', 'database' );
			return;
		}

		// PRODUCTION SAFETY (C2-3): an ALTER TABLE ... ADD KEY on a multi-million
		// row table measured 7-12+ MINUTES at customer scale (2M pageviews / 5M
		// events) — running that inline in a web request fatals at
		// max_execution_time mid-battery and locks the table for every other
		// request. Missing indexes on LARGE tables are therefore deferred to the
		// HEAVY_MIGRATIONS_CRON_HOOK background worker (one ALTER per tick,
		// resumable); only small-table indexes (fast, sub-second) build inline.
		// CLI (WP-CLI / test harnesses) has no request time limit and may build
		// everything inline — that is also the documented operator fast-path
		// ("wp eval" the setup) for admins who want the indexes immediately.
		$allow_large_inline = ( 'cli' === PHP_SAPI ) || ( defined( 'WP_CLI' ) && WP_CLI );
		$deferred           = $this->build_missing_managed_indexes( $allow_large_inline );
		if ( $deferred > 0 ) {
			$debug_manager->log(
				sprintf( 'Deferred %d large-table index build(s) to background cron', $deferred ),
				'info',
				'database'
			);
			$this->schedule_heavy_migrations();
			// Do NOT set the completion flag: the background worker sets it once
			// every managed index actually exists.
			return;
		}

		// All indexes are present now; record completion so future setup() passes
		// skip the per-index probes and reach activated_ver persistence quickly.
		update_option( 'opti_behavior_large_indexes_ver', OPTI_BEHAVIOR_HEATMAP_VERSION, false );

		$debug_manager->log( 'Large dataset performance indexes migration completed', 'info', 'database' );
	}

	/**
	 * Canonical list of managed performance indexes: [table_suffix, index_name, columns].
	 *
	 * Merges the historical add_large_dataset_indexes() set with the dashboard
	 * maintenance-trait set (ensure_db_indexes_impl) so ONE background worker
	 * owns every heavy index build.
	 *
	 * @since 1.8.1.7
	 * @return array[] Index definitions.
	 */
	public function get_managed_index_defs() {
		return array(
			// Sessions table - for date range + traffic type queries
			array( 'optibehavior_sessions', 'idx_start_traffic', 'start_time, traffic_type' ),
			// Sessions table - for bounce rate queries
			array( 'optibehavior_sessions', 'idx_bounce_time', 'is_bounce, start_time' ),
			// Sessions table - composite for top users aggregation with date filtering
			array( 'optibehavior_sessions', 'idx_visitor_traffic_time', 'visitor_id, traffic_type, start_time' ),
			// Sessions table - COVERING index for the canonical dashboard traffic timeseries
			// (daily Sessions/Visitors/Page Views). Lets the GROUP BY DATE(start_time)
			// aggregation read sessions, visitor_id (COUNT DISTINCT) and page_views (SUM)
			// straight from the index for the range+spam scan, avoiding row lookups.
			array( 'optibehavior_sessions', 'idx_dash_traffic', 'start_time, traffic_type, visitor_id, page_views' ),
			// Sessions table - COVERING index for the folded bounce-rate aggregation
			// (total sessions + bounce sessions computed in one range scan).
			array( 'optibehavior_sessions', 'idx_dash_bounce', 'start_time, traffic_type, is_bounce' ),

			// Pageviews table - for JOIN with sessions and date range queries
			array( 'optibehavior_pageviews', 'idx_pv_time_session', 'view_time, session_id' ),
			// Pageviews table - for the realtime "latest pageview per active session"
			// lookup (get_active_visitors): session_id leads so the per-session
			// MAX(view_time) GROUP BY is index-served instead of full-scanning pageviews.
			array( 'optibehavior_pageviews', 'idx_pv_session_time', 'session_id, view_time' ),
			// Pageviews table - for page-specific queries with date filtering
			// Pageviews table - for scroll depth aggregation with date filtering
			array( 'optibehavior_pageviews', 'idx_pv_scroll_time', 'view_time, scroll_depth' ),
			// Pageviews table - COVERING index for the canonical pageview-session
			// counts (heatmap list Sessions column / detail pill / device chips):
			// distinct (page_id, session_id[, visitor_id]) pairs are read straight
			// from the index, so the per-group DISTINCT dedupe never touches rows.
			// Without it the 100k-pageview scale benchmark measured ~10 s per
			// grouped COUNT(DISTINCT); with it the same aggregation is ~0.3 s.
			array( 'optibehavior_pageviews', 'idx_pv_page_sess_vis', 'page_id, session_id, visitor_id' ),
			// Pageviews table - COVERING index for the DATED variant of the same
			// pageview-session counts (view_time range filter per page). Keeps the
			// dated heatmap-list Sessions column an index-only scan: without it the
			// date filter forces a row lookup per index entry (~5 s per grouped
			// COUNT(DISTINCT) at 100k pageviews in the scale benchmark).
			array( 'optibehavior_pageviews', 'idx_pv_page_time_sess', 'page_id, view_time, session_id, visitor_id' ),

			// Events table - for heatmap queries with viewport filtering
			array( 'optibehavior_events', 'idx_page_event_width', 'page_id2, event, width' ),
			// (1.9.2) idx_event_insert removed: exact duplicate of idx_events_event_time below.
			// Events table - for Top Clicked Elements aggregation (group by selector per page/event type)
			array( 'optibehavior_events', 'idx_top_elements', 'page_id2, event, element_selector(100)' ),

			// Bot visits table - for bot traffic aggregation
			array( 'optibehavior_bot_visits', 'idx_bot_time_type', 'visit_time, bot_type' ),

			// Referrers table - for referrer grouping with date filtering
			array( 'optibehavior_referrers', 'idx_ref_time_type', 'created_at, referrer_type' ),

			// Session pages - for entry/exit page queries (page_order + session_id lookups)
			array( 'optibehavior_session_pages', 'idx_sp_order_session', 'page_order, session_id' ),
			// Session pages - for max page_order per session (exit page detection)
			array( 'optibehavior_session_pages', 'idx_sp_session_order', 'session_id, page_order' ),

			// Merged from the dashboard maintenance trait (ensure_db_indexes_impl)
			// so the background worker covers those too on large installs.
			array( 'optibehavior_events', 'idx_events_page_event_time', 'page_id2, event, insert_at' ),
			array( 'optibehavior_events', 'idx_events_event_time', 'event, insert_at' ),
			array( 'optibehavior_pageviews', 'idx_pv_url', 'url(191)' ),

			// Merged from migrate_performance_indexes() so its ALTERs are also
			// covered by the background worker on large installs.
			array( 'optibehavior_sessions', 'idx_spam_filter', 'start_time, traffic_type, duration, events_count' ),
			array( 'optibehavior_sessions', 'idx_duration', 'duration' ),
			array( 'optibehavior_sessions', 'idx_events_count', 'events_count' ),
			array( 'optibehavior_sessions', 'idx_sessions_visitor_start', 'visitor_id, start_time' ),
			// 1.9.0.6: realtime "active visitors" widget (polled every 15 s per open
			// dashboard tab) filters on end_time; without this index it full-scanned
			// sessions while heartbeats were updating the same rows.
			array( 'optibehavior_sessions', 'idx_sessions_end_time', 'end_time' ),
		);
	}

	/**
	 * Cheap row-count estimate for a table (INFORMATION_SCHEMA statistics,
	 * O(1) — never scans the table).
	 *
	 * @since 1.8.1.7
	 * @param string $table Full (prefixed) table name.
	 * @return int Estimated row count (0 when the table does not exist).
	 */
	public function get_table_row_estimate( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- O(1) statistics read used as a size guard.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);
	}

	/**
	 * Whether a table is too large for inline (web-request) DDL/full-table DML.
	 *
	 * @since 1.8.1.7
	 * Drop redundant indexes on optibehavior_events (1.9.2).
	 *
	 * On a 600k-row install the index footprint (189 MB) was 2.6x the data
	 * (72 MB). Three keys were pure duplicates / left-prefixes of the managed
	 * composite indexes:
	 *   event            ⊂ idx_events_event_time (event, insert_at)
	 *   idx_event_insert = idx_events_event_time (exact duplicate)
	 *   page_id2         ⊂ idx_events_page_event_time (page_id2, event, insert_at)
	 *
	 * Each drop happens only when its covering index already exists, so query
	 * plans never lose an index. Idempotent, cheap when nothing to do (one
	 * INFORMATION_SCHEMA read), safe to call from setup and from cron.
	 *
	 * @since 1.9.2
	 * @return int Number of indexes dropped.
	 */
	public static function drop_redundant_event_indexes() {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_events';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);
		if ( ! is_array( $existing ) || ! $existing ) {
			return 0;
		}
		$existing = array_flip( $existing );

		$pairs = array(
			'event'            => 'idx_events_event_time',
			'idx_event_insert' => 'idx_events_event_time',
			'page_id2'         => 'idx_events_page_event_time',
		);

		$dropped = 0;
		foreach ( $pairs as $redundant => $covering ) {
			if ( ! isset( $existing[ $redundant ] ) || ! isset( $existing[ $covering ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table ($wpdb->prefix) and hardcoded index names from the redundant-index map; identifiers cannot be placeholders on WP < 6.2.
			if ( false !== $wpdb->query( "ALTER TABLE {$table} DROP INDEX `{$redundant}`" ) ) {
				++$dropped;
			}
		}
		return $dropped;
	}

	/**
	 * Explicit storage-engine clause for every plugin CREATE TABLE (1.9.3).
	 *
	 * Without it MySQL falls back to the server default, which on some
	 * hosts (and WAMP) is still MyISAM: table-level locks on every tracking
	 * INSERT, no crash safety, no incremental space reclaim. Returns an empty
	 * string when InnoDB is unavailable so table creation never fails.
	 *
	 * @return string 'ENGINE=InnoDB' or ''.
	 */
	public static function engine_clause() {
		static $clause = null;
		if ( null !== $clause ) {
			return $clause;
		}
		$clause = self::innodb_available() ? 'ENGINE=InnoDB' : '';
		return (string) apply_filters( 'opti_behavior_table_engine_clause', $clause );
	}

	/**
	 * Whether the server offers InnoDB (YES or DEFAULT in SHOW ENGINES).
	 *
	 * @return bool
	 */
	public static function innodb_available() {
		global $wpdb;
		static $available = null;
		if ( null !== $available ) {
			return $available;
		}
		$available = false;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( 'SHOW ENGINES', ARRAY_A );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( isset( $row['Engine'], $row['Support'] )
					&& 'innodb' === strtolower( (string) $row['Engine'] )
					&& in_array( strtoupper( (string) $row['Support'] ), array( 'YES', 'DEFAULT' ), true ) ) {
					$available = true;
					break;
				}
			}
		}
		return $available;
	}

	/**
	 * Redundant secondary indexes and the covering index that makes each one
	 * useless (1.9.3 space audit). An index is dropped ONLY when its covering
	 * index exists on the table, so a partially migrated install never loses
	 * a lookup path. Free-owned names only: indexes created by the Pro plugin
	 * are never touched (an older Pro would simply re-create them).
	 *
	 * Table suffix => array( redundant_index => covering_index ).
	 *
	 * @return array
	 */
	public static function get_redundant_index_map() {
		return array(
			'optibehavior_events'        => array(
				'event'            => 'idx_events_event_time',
				'idx_event_insert' => 'idx_events_event_time',
				'page_id2'         => 'idx_events_page_event_time',
			),
			'optibehavior_sessions'      => array(
				'start_time'              => 'idx_start_traffic',
				'idx_sessions_start_time' => 'idx_start_traffic',
				'idx_start_time_only'     => 'idx_start_traffic',
				'visitor_id'              => 'idx_sessions_visitor_start',
				'idx_sessions_visitor'    => 'idx_sessions_visitor_start',
				'idx_visitor_time'        => 'idx_sessions_visitor_start',
				'is_bounce'               => 'idx_bounce_time',
			),
			// session_id / page_id single-column indexes stay: the dashboard
			// evidence queries carry FORCE INDEX (session_id|page_id) hints.
			'optibehavior_pageviews'     => array(
				'idx_pv_session_id' => 'idx_pv_session_time',
				'view_time'         => 'idx_pv_time_session',
				'idx_pv_view_time'  => 'idx_pv_time_session',
				'idx_pv_page_time'  => 'idx_pv_page_time_sess',
			),
			'optibehavior_recordings'    => array(
				'idx_recordings_page_start'     => 'page_start_time',
				'idx_recordings_start_duration' => 'start_duration',
				'idx_recordings_watched'        => 'watched',
				'start_time'                    => 'start_duration',
				'page_id'                       => 'page_start_time',
			),
			'optibehavior_session_pages' => array(
				'page_order' => 'idx_sp_order_session',
			),
			'optibehavior_visitors'      => array(
				'idx_visitors_last_visit' => 'last_visit',
				'idx_visitor_country'     => 'PRIMARY',
			),
			'optibehavior_pages'         => array(
				'idx_pages_url' => 'url',
			),
			'optibehavior_bot_visits'    => array(
				'visit_time' => 'idx_bot_time_type',
			),
		);
	}

	/**
	 * Drop every redundant index whose covering index exists. Idempotent and
	 * safe to call from activation, the daily tick or a Cleanup Tasks
	 * "Run now": nothing happens when the index is already gone.
	 *
	 * @param bool $skip_large Skip tables above the large-table threshold
	 *                         (DROP INDEX rebuilds the whole table on MyISAM;
	 *                         the daily tick passes false).
	 * @return array { dropped: int, deferred: string[] }
	 */
	public static function drop_redundant_indexes( $skip_large = false ) {
		global $wpdb;
		$result = array(
			'dropped'  => 0,
			'deferred' => array(),
		);
		foreach ( self::get_redundant_index_map() as $suffix => $pairs ) {
			$table = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					DB_NAME,
					$table
				)
			);
			if ( ! is_array( $existing ) || ! $existing ) {
				continue;
			}
			$existing = array_flip( $existing );
			$todo     = array();
			foreach ( $pairs as $redundant => $covering ) {
				if ( isset( $existing[ $redundant ] ) && isset( $existing[ $covering ] ) ) {
					$todo[] = $redundant;
				}
			}
			if ( ! $todo ) {
				continue;
			}
			if ( $skip_large && self::estimate_rows_static( $table ) > (int) apply_filters( 'opti_behavior_large_table_threshold', self::LARGE_TABLE_ROW_THRESHOLD, $table ) ) {
				$result['deferred'][] = $suffix;
				continue;
			}
			foreach ( $todo as $redundant ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table ($wpdb->prefix) and hardcoded index names from the redundant-index map; identifiers cannot be placeholders on WP < 6.2.
				if ( false !== $wpdb->query( "ALTER TABLE {$table} DROP INDEX `{$redundant}`" ) ) {
					++$result['dropped'];
				}
			}
		}
		return $result;
	}

	/**
	 * information_schema row estimate without a class instance (activation-safe).
	 *
	 * @param string $table Full table name.
	 * @return int
	 */
	private static function estimate_rows_static( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', DB_NAME, $table ) );
	}

	/**
	 * Public entry for the DB size cap: age out every raw row older than
	 * $cutoff across the retention sweep tables (same batched path as the
	 * daily retention, so linked tables stay consistent).
	 *
	 * @param string $cutoff UTC datetime (Y-m-d H:i:s).
	 * @param string $label  Reason shown in the debug log.
	 * @return int Rows deleted.
	 */
	public function delete_raw_data_before( $cutoff, $label = 'size cap' ) {
		return (int) $this->delete_old_data_before( $cutoff, $label );
	}

	/**
	 * (Re)create the tables shared with the Pro plugin from the Free
	 * definitions (single schema source since 1.9.3). Pro >= 1.9.3 calls
	 * this instead of running its own CREATE TABLE copies; an older Pro keeps
	 * its own `CREATE TABLE IF NOT EXISTS` fallbacks, which are no-ops on an
	 * existing table.
	 *
	 * @return void
	 */
	public function ensure_shared_schema() {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$charset_collate = $wpdb->get_charset_collate();
		$this->create_recordings_table( $charset_collate );
		$this->create_errors_table( $charset_collate );
		$this->create_error_types_table( $charset_collate );
		$this->create_friction_table( $charset_collate );
		$this->create_performance_table( $charset_collate );
		$this->create_broken_links_table( $charset_collate );
		$this->create_journey_groups_table( $charset_collate );
	}

	/**
	 * Table suffixes whose schema is owned by Free but written by Pro.
	 *
	 * @return string[]
	 */
	public static function get_shared_table_suffixes() {
		return array(
			'optibehavior_recordings',
			'optibehavior_errors',
			'optibehavior_error_types',
			'optibehavior_friction',
			'optibehavior_performance',
			'optibehavior_broken_links',
			'optibehavior_journey_groups',
		);
	}

	/**
	 * @param string $table Full (prefixed) table name.
	 * @return bool True when heavy work on this table must be deferred to cron.
	 */
	public function is_large_table( $table ) {
		/**
		 * Filters the row-count threshold above which heavy DDL/DML on a table
		 * is deferred to the background worker.
		 *
		 * @since 1.8.1.7
		 * @param int    $threshold Row estimate threshold.
		 * @param string $table     Full (prefixed) table name.
		 */
		$threshold = (int) apply_filters( 'opti_behavior_large_table_threshold', self::LARGE_TABLE_ROW_THRESHOLD, $table );
		return $this->get_table_row_estimate( $table ) > $threshold;
	}

	/**
	 * Build managed indexes that are still missing.
	 *
	 * @since 1.8.1.7
	 * @param bool $allow_large     Whether large-table builds may run (true only
	 *                              in the background cron worker / CLI).
	 * @param int  $max_large_builds Max number of large-table ALTERs to execute in
	 *                              this call (background worker passes 1 = one
	 *                              heavy statement per tick).
	 * @return int Number of missing indexes NOT built in this call (deferred).
	 */
	private function build_missing_managed_indexes( $allow_large, $max_large_builds = 0 ) {
		global $wpdb;
		$debug_manager = $this->core->get_debug_manager();
		$deferred      = 0;
		$large_built   = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema changes required for performance optimization
		foreach ( $this->get_managed_index_defs() as $index_def ) {
			list( $table_suffix, $index_name, $columns ) = $index_def;
			$table = $wpdb->prefix . $table_suffix;

			// Guard: skip if the table does not exist (e.g. called before table creation)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check before ALTER TABLE
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				continue;
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}

			// Check if index already exists
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration check
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
					WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
					DB_NAME,
					$table,
					$index_name
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $exists ) {
				continue;
			}

			$is_large = $this->is_large_table( $table );
			if ( $is_large && ( ! $allow_large || ( $max_large_builds > 0 && $large_built >= $max_large_builds ) ) ) {
				++$deferred;
				continue;
			}

			$debug_manager->log( "Adding performance index " . $index_name . " on " . $table_suffix, 'info', 'database' );
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query( "ALTER TABLE " . $table . " ADD KEY " . $index_name . " (" . $columns . ")" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( $is_large ) {
				++$large_built;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

		return $deferred;
	}

	/**
	 * dbDelta 'dbdelta_create_queries' filter (registered in the constructor).
	 *
	 * For every CREATE TABLE statement targeting an optibehavior table:
	 *
	 * 1. Column-definition lines get their whitespace runs collapsed to single
	 *    spaces. WordPress' dbDelta extracts the column type with the pattern
	 *    "`?field`? ([^ ]*)" — exactly ONE space between name and type — so the
	 *    column-aligned (padded) schema strings used across this plugin made the
	 *    extracted type EMPTY and dbDelta re-issued `CHANGE COLUMN` for every
	 *    column of every table on each pass (a no-op table rebuild costing
	 *    minutes per multi-million-row table, inline in the request).
	 *    Index lines (PRIMARY KEY/KEY/UNIQUE/...) are left untouched; dbDelta's
	 *    index parser is whitespace-tolerant.
	 * 2. After normalization only REAL schema differences remain. If the table
	 *    already exists, is large, and this is a web request (not CLI/cron),
	 *    the statement is removed from the pass and stored for the
	 *    heavy-migrations worker, which replays it via dbDelta() in a cron
	 *    tick (one table per tick) — no DDL on large tables inline in web
	 *    requests, ever.
	 *
	 * @since 1.8.1.7
	 * @param array $cqueries CREATE TABLE statements keyed by table name.
	 * @return array Filtered statements.
	 */
	public function filter_dbdelta_create_queries( $cqueries ) {
		global $wpdb;

		if ( ! is_array( $cqueries ) ) {
			return $cqueries;
		}

		$defer_allowed = ( 'cli' !== PHP_SAPI )
			&& ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			&& ! ( defined( 'DOING_CRON' ) && DOING_CRON );

		$deferred = false;
		foreach ( $cqueries as $table => $qry ) {
			if ( false === strpos( (string) $table, 'optibehavior' ) ) {
				continue;
			}

			$cqueries[ $table ] = $this->normalize_dbdelta_schema( $qry );

			if ( ! $defer_allowed ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence probe before deciding to defer DDL.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists || ! $this->is_large_table( $table ) ) {
				continue;
			}

			$pending           = get_option( self::PENDING_DBDELTA_OPTION, array() );
			$pending           = is_array( $pending ) ? $pending : array();
			$pending[ $table ] = $cqueries[ $table ];
			update_option( self::PENDING_DBDELTA_OPTION, $pending, false );
			unset( $cqueries[ $table ] );
			$deferred = true;
		}

		if ( $deferred ) {
			$this->schedule_heavy_migrations();
		}

		return $cqueries;
	}

	/**
	 * Collapse whitespace runs in the column-definition lines of a CREATE TABLE
	 * statement so dbDelta's single-space type-extraction regex matches.
	 * Index/constraint lines are preserved verbatim.
	 *
	 * @since 1.8.1.7
	 * @param string $sql CREATE TABLE statement.
	 * @return string Normalized statement.
	 */
	private function normalize_dbdelta_schema( $sql ) {
		$lines = explode( "\n", (string) $sql );
		foreach ( $lines as $i => $line ) {
			$trimmed = ltrim( $line );
			if ( preg_match( '/^(PRIMARY\s+KEY|UNIQUE|FULLTEXT|SPATIAL|KEY|INDEX|CONSTRAINT|CREATE\s+TABLE|\))/i', $trimmed ) ) {
				continue;
			}
			$lines[ $i ] = preg_replace( '/[ \t]+/', ' ', $line );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Schedule (or keep scheduled) the heavy-migrations background worker.
	 *
	 * @since 1.8.1.7
	 * @param int $delay Seconds from now for the next tick.
	 */
	public function schedule_heavy_migrations( $delay = 60 ) {
		if ( ! wp_next_scheduled( self::HEAVY_MIGRATIONS_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + max( 1, (int) $delay ), self::HEAVY_MIGRATIONS_CRON_HOOK );
		}
	}

	/**
	 * Background worker: performs heavy migrations one statement per tick,
	 * resumable, under an advisory lock so ticks never overlap.
	 *
	 * Order of work:
	 *   1. Build ONE missing large-table index (plus any cheap small-table ones).
	 *   2. When every managed index exists, persist the version-keyed completion
	 *      flag (per-step progress: each ALTER auto-commits, so a killed tick
	 *      resumes exactly where it stopped).
	 *   3. Run the batched invalid-data cleanup within a time budget.
	 * Reschedules itself (+60s) while any work remains.
	 *
	 * @since 1.8.1.7
	 * @return void
	 */
	public function run_heavy_migrations() {
		global $wpdb;

		$lock_state = Opti_Behavior_Heatmap_DB_Lock::acquire( 'opti_behavior_heavy_migrations', 0 );
		if ( Opti_Behavior_Heatmap_DB_Lock::BUSY === $lock_state ) {
			// RESILIENCE (C2-4): the lock holder may be a PHP worker that already
			// fataled at max_execution_time while MySQL finishes its multi-minute
			// ALTER server-side (the lock is only released when that connection
			// ends). Without a rechain here, the whole pipeline would stall the
			// moment the dead worker's own rechain never ran. Keep ticking until
			// the lock frees; each blocked tick costs a single SELECT.
			$this->schedule_heavy_migrations();
			return;
		}

		try {
			$debug_manager = $this->core->get_debug_manager();

			// RESILIENCE (C2-4): a large ALTER can take many minutes. If PHP dies
			// at max_execution_time mid-statement the client connection drops and
			// MySQL ABORTS the ALTER — the worker would then retry the same doomed
			// build forever (measured: pageviews ADD KEY killed at 120 s on every
			// tick, index never completed). Lift the limits for this cron tick so
			// the statement can run to completion.
			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Best effort; some hosts disable it. Needed so a long index-build cron tick runs to completion instead of dying at the default 120s limit.
			}

			// Step 0: replay deferred dbDelta passes (real schema changes on large
			// tables detected during a web request) — one table per tick.
			$pending = get_option( self::PENDING_DBDELTA_OPTION, array() );
			if ( is_array( $pending ) && ! empty( $pending ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				// Pre-arm a successor tick before the potentially long statement.
				$this->schedule_heavy_migrations( 2 * MINUTE_IN_SECONDS );
				foreach ( $pending as $table => $sql ) {
					$debug_manager->log( 'Replaying deferred dbDelta for ' . $table, 'info', 'database' );
					dbDelta( $sql );
					unset( $pending[ $table ] );
					if ( empty( $pending ) ) {
						delete_option( self::PENDING_DBDELTA_OPTION );
					} else {
						update_option( self::PENDING_DBDELTA_OPTION, $pending, false );
					}
					break; // One table per tick.
				}
				if ( ! empty( $pending ) ) {
					$this->schedule_heavy_migrations();
					return;
				}
			}

			// Step 1: index builds — at most ONE large ALTER per tick.
			if ( OPTI_BEHAVIOR_HEATMAP_VERSION !== get_option( 'opti_behavior_large_indexes_ver' ) ) {
				// RESILIENCE (C2-4): pre-arm the next tick BEFORE starting the
				// ALTER. A large build can exceed PHP's max_execution_time — the
				// worker fatals mid-query, MySQL completes the ALTER server-side,
				// and the post-build rechain below never runs. The pre-armed
				// event guarantees a successor tick that observes the finished
				// index via its INFORMATION_SCHEMA probe and resumes.
				$this->schedule_heavy_migrations( 2 * MINUTE_IN_SECONDS );
				$remaining = $this->build_missing_managed_indexes( true, 1 );
				if ( $remaining > 0 ) {
					$this->schedule_heavy_migrations();
					return;
				}
				update_option( 'opti_behavior_large_indexes_ver', OPTI_BEHAVIOR_HEATMAP_VERSION, false );
				$debug_manager->log( 'Background large dataset index builds completed', 'info', 'database' );
			}

			// Step 2: batched invalid-data cleanup (time budget per tick).
			if ( OPTI_BEHAVIOR_HEATMAP_VERSION !== get_option( 'opti_behavior_invalid_data_cleaned_ver' ) ) {
				if ( ! $this->cleanup_invalid_data_batched( 10 ) ) {
					$this->schedule_heavy_migrations();
					return;
				}
				update_option( 'opti_behavior_invalid_data_cleaned_ver', OPTI_BEHAVIOR_HEATMAP_VERSION, false );
				$debug_manager->log( 'Background invalid-data cleanup completed', 'info', 'database' );
			}

			// All work done: remove any pre-armed successor tick so the hook
			// stops firing.
			wp_clear_scheduled_hook( self::HEAVY_MIGRATIONS_CRON_HOOK );
		} finally {
			Opti_Behavior_Heatmap_DB_Lock::release( 'opti_behavior_heavy_migrations', $lock_state );
		}
	}

	/**
	 * Batched invalid click-data cleanup (DELETE ... LIMIT loop, time-budgeted).
	 *
	 * @since 1.8.1.7
	 * @param int $budget_seconds Max wall time to spend in this call.
	 * @return bool True when no invalid rows remain (done), false to resume later.
	 */
	private function cleanup_invalid_data_batched( $budget_seconds = 10 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence check.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'optibehavior_events' ) ) ) {
			return true;
		}

		// The (x < 1 AND y < 1) predicate is unindexed, so an unbounded DELETE
		// full-scans the events table (measured 88s at 5M rows even when zero
		// rows match). Walk the table by primary-key range instead: each
		// statement scans at most CHUNK rows, and the cursor persists so an
		// interrupted pass resumes instead of restarting.
		$chunk = 200000;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- O(1) PK max read.
		$max_id   = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . $wpdb->prefix . 'optibehavior_events' );
		$cursor   = (int) get_option( 'opti_behavior_invalid_cleanup_cursor', 0 );
		$deadline = microtime( true ) + max( 1, (int) $budget_seconds );

		while ( $cursor <= $max_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; batched maintenance DELETE.
			$wpdb->query( $wpdb->prepare(
				'DELETE FROM ' . $wpdb->prefix . 'optibehavior_events WHERE id > %d AND id <= %d AND event IN (%d, %d) AND ( x < 1 AND y < 1 )',
				$cursor,
				$cursor + $chunk,
				Opti_Behavior_Heatmap_Core::CLICK_PC,
				Opti_Behavior_Heatmap_Core::CLICK_MOBILE
			) );
			$cursor += $chunk;
			update_option( 'opti_behavior_invalid_cleanup_cursor', $cursor, false );
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
		}

		delete_option( 'opti_behavior_invalid_cleanup_cursor' );
		return true;
	}

	/**
	 * Create events table
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_events_table( $charset_collate ) {
		global $wpdb;
		
		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_events (
			id           bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			page_id      int(8)       UNSIGNED NOT NULL,
			page_id2     int(8)       UNSIGNED NOT NULL,
			session_id   varchar(64)           DEFAULT NULL,
			event        tinyint(3)   UNSIGNED NOT NULL,
			x            mediumint(5) UNSIGNED NOT NULL,
			y            mediumint(7) UNSIGNED NOT NULL,
			width        mediumint(5) UNSIGNED NOT NULL,
			height       mediumint(7) UNSIGNED NOT NULL,
			x_normalized float(7,6)            DEFAULT NULL,
			y_normalized float(7,6)            DEFAULT NULL,
			viewport_width mediumint(5) UNSIGNED DEFAULT NULL,
			viewport_height mediumint(7) UNSIGNED DEFAULT NULL,
			element_tag  varchar(50)           DEFAULT NULL,
			element_id   varchar(100)          DEFAULT NULL,
			element_class varchar(200)         DEFAULT NULL,
			element_text varchar(150)          DEFAULT NULL,
			element_type varchar(40)           DEFAULT NULL,
			element_builder varchar(50)        DEFAULT NULL,
			element_selector varchar(191)      DEFAULT NULL,
			element_href varchar(191)          DEFAULT NULL,
			element_xpath varchar(512)         DEFAULT NULL,
			element_rel_x float(7,6)           DEFAULT NULL,
			element_rel_y float(7,6)           DEFAULT NULL,
			insert_at    datetime              NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY insert_at (insert_at)
			) " . self::engine_clause() . " " . $charset_collate
		);
		// NOTE (1.9.2): the single-column `event` and `page_id2` keys were removed;
		// they are left-prefixes of the managed composite indexes
		// idx_events_event_time (event, insert_at) and idx_events_page_event_time
		// (page_id2, event, insert_at). See drop_redundant_event_indexes().
	}

	/**
	 * Create pages table
	 *
	 * @param string $charset_collate Database charset collation.
	 * @param int    $max_index_length Maximum index length.
	 */
	private function create_pages_table( $charset_collate, $max_index_length ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_pages (
			id           int(8)   UNSIGNED NOT NULL AUTO_INCREMENT,
			url          text              NOT NULL,
			url2         text              NOT NULL,
			title        text              NOT NULL,
			post_id      bigint(20) UNSIGNED DEFAULT NULL,
			term_id      bigint(20) UNSIGNED DEFAULT NULL,
			taxonomy     varchar(50)       DEFAULT NULL,
			object_type  varchar(20)       DEFAULT NULL,
			insert_at    datetime          NOT NULL,
			update_at    datetime          NOT NULL,
			PRIMARY KEY  (id),
			KEY url (url(" . $max_index_length . ")),
			KEY url2 (url2(" . $max_index_length . ")),
			KEY post_id (post_id),
			KEY term_id (term_id),
			KEY taxonomy (taxonomy),
			KEY object_type (object_type)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create sessions table
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_sessions_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_sessions (
			id           varchar(64)          NOT NULL,
			visitor_id   varchar(64)          NOT NULL,
			user_id      bigint(20)  UNSIGNED DEFAULT NULL,
			start_time   datetime             NOT NULL,
			end_time     datetime             DEFAULT NULL,
			duration     int(10)     UNSIGNED DEFAULT 0,
			page_views   int(5)      UNSIGNED DEFAULT 0,
			events_count int(10)     UNSIGNED DEFAULT 0,
			click_count  int(10)     UNSIGNED NOT NULL DEFAULT 0,
			scroll_count int(10)     UNSIGNED NOT NULL DEFAULT 0,
			move_count   int(10)     UNSIGNED NOT NULL DEFAULT 0,
			is_bounce    tinyint(1)           DEFAULT 1,
			referrer     text                 DEFAULT NULL,
			utm_source   varchar(100)         DEFAULT NULL,
			utm_medium   varchar(100)         DEFAULT NULL,
			utm_campaign varchar(100)         DEFAULT NULL,
			utm_term     varchar(100)         DEFAULT NULL,
			utm_content  varchar(100)         DEFAULT NULL,
			entry_page   text                 DEFAULT NULL,
			exit_page    text                 DEFAULT NULL,
			ip           varchar(45)          DEFAULT NULL,
			traffic_type varchar(20)          DEFAULT 'human',
			bot_type     varchar(50)          DEFAULT NULL,
			spam_reason  varchar(100)         DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY idx_traffic_type (traffic_type),
			KEY idx_spam_filter (start_time, traffic_type, duration, events_count),
			KEY idx_duration (duration),
			KEY idx_events_count (events_count)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create visitors table
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_visitors_table( $charset_collate ) {
		global $wpdb;
		
		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_visitors (
			id           varchar(64)          NOT NULL,
			first_visit  datetime             NOT NULL,
			last_visit   datetime             NOT NULL,
			visit_count  int(5)      UNSIGNED DEFAULT 1,
			total_sessions int(5)    UNSIGNED DEFAULT 0,
			total_pageviews int(10)  UNSIGNED DEFAULT 0,
			device_type  varchar(20)          DEFAULT NULL,
			browser      varchar(50)          DEFAULT NULL,
			browser_version varchar(20)       DEFAULT NULL,
			os           varchar(50)          DEFAULT NULL,
			os_version   varchar(20)          DEFAULT NULL,
			screen_width int(5)      UNSIGNED DEFAULT NULL,
			screen_height int(5)     UNSIGNED DEFAULT NULL,
			country      varchar(2)           DEFAULT NULL,
			country_name varchar(100)         DEFAULT NULL,
			region       varchar(100)         DEFAULT NULL,
			city         varchar(100)         DEFAULT NULL,
			timezone     varchar(50)          DEFAULT NULL,
			language     varchar(10)          DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY first_visit (first_visit),
			KEY last_visit (last_visit),
			KEY country (country),
			KEY device_type (device_type)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create pageviews table
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_pageviews_table( $charset_collate ) {
		global $wpdb;
		
		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_pageviews (
			id           bigint(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id   varchar(64)          NOT NULL,
			visitor_id   varchar(64)          NOT NULL,
			page_id      int(8)      UNSIGNED NOT NULL,
			url          text                 NOT NULL,
			title        text                 NOT NULL,
			view_time    datetime             NOT NULL,
			time_on_page int(10)     UNSIGNED DEFAULT 0,
			scroll_depth int(3)      UNSIGNED DEFAULT 0,
			exit_page    tinyint(1)           DEFAULT 0,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY visitor_id (visitor_id),
			KEY page_id (page_id)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create recordings table
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_recordings_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_recordings (
			id           bigint(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id   varchar(64)          NOT NULL,
			page_id      int(8)      UNSIGNED NOT NULL,
			recording_data longtext            DEFAULT NULL,
			file_path    varchar(255)         DEFAULT NULL,
			storage_type varchar(20)          DEFAULT 'database',
			start_time   datetime             NOT NULL,
			duration     int(10)     UNSIGNED DEFAULT 0,
			file_size    int(10)     UNSIGNED DEFAULT 0,
			watched      tinyint(1)           DEFAULT 0,
			watched_at   datetime             DEFAULT NULL,
			share_hash   varchar(12)          DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY share_hash (share_hash),
			KEY session_id (session_id),
			KEY page_start_time (page_id, start_time),
			KEY start_duration (start_time, duration),
			KEY watched (watched),
			KEY file_path (file_path),
			KEY storage_type (storage_type)
			) " . self::engine_clause() . " " . $charset_collate
		);

		// Migrate existing schema if needed
		$this->migrate_recordings_schema();
	}

	/**
	 * Migrate recordings table schema to support file storage
	 */
	private function migrate_recordings_schema() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'optibehavior_recordings';

		// Check if file_path column exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration: checking column existence via INFORMATION_SCHEMA.
		$column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'file_path'",
				$table_name
			)
		);

		if ( empty( $column_exists ) ) {
			// Escape table name for ALTER TABLE statements (DDL does not support placeholders)
			$safe_table_name = esc_sql( $table_name );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema changes required for recordings table migration. DDL statements do not support placeholders. Table name is escaped with esc_sql().
			// Add file_path column
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $safe_table_name . "
				ADD COLUMN file_path varchar(255) DEFAULT NULL AFTER recording_data,
				ADD COLUMN storage_type varchar(20) DEFAULT 'database' AFTER file_path,
				ADD INDEX file_path (file_path),
				ADD INDEX storage_type (storage_type)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Make recording_data nullable for file-based storage
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $safe_table_name . "
				MODIFY COLUMN recording_data longtext DEFAULT NULL"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		// Migrate metadata columns for performance optimization
		$this->migrate_recordings_metadata();
	}

	/**
	 * Migrate recordings table to add metadata columns for performance optimization
	 *
	 * This migration adds metadata columns to the recordings table to avoid reading
	 * files every time when displaying the recordings list. Metadata is cached in
	 * the database for fast filtering and sorting.
	 */
	private function migrate_recordings_metadata() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'optibehavior_recordings';

		// Check if metadata columns already exist
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration: checking column existence via INFORMATION_SCHEMA.
		$column_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'device_type'",
				$table_name
			)
		);

		if ( empty( $column_exists ) ) {
			$debug_manager = $this->core->get_debug_manager();
			$debug_manager->log( 'Starting recordings table metadata migration - adding performance optimization columns', 'info', 'database' );

			// Escape table name for ALTER TABLE statements
			$safe_table_name = esc_sql( $table_name );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema changes required for recordings table migration. DDL statements do not support placeholders. Table name is escaped with esc_sql().
			// Add metadata columns for fast filtering and display
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $safe_table_name . "
				ADD COLUMN device_type VARCHAR(50) DEFAULT NULL AFTER watched_at,
				ADD COLUMN browser VARCHAR(100) DEFAULT NULL AFTER device_type,
				ADD COLUMN os VARCHAR(100) DEFAULT NULL AFTER browser,
				ADD COLUMN country VARCHAR(2) DEFAULT NULL AFTER os,
				ADD COLUMN country_name VARCHAR(100) DEFAULT NULL AFTER country,
				ADD COLUMN screen_width INT(10) UNSIGNED DEFAULT NULL AFTER country_name,
				ADD COLUMN screen_height INT(10) UNSIGNED DEFAULT NULL AFTER screen_width,
				ADD COLUMN event_count INT(10) UNSIGNED DEFAULT 0 AFTER screen_height,
				ADD COLUMN click_count INT(10) UNSIGNED DEFAULT 0 AFTER event_count,
				ADD COLUMN page_count INT(5) UNSIGNED DEFAULT 0 AFTER click_count,
				ADD COLUMN referrer TEXT DEFAULT NULL AFTER page_count,
				ADD COLUMN visitor_type VARCHAR(20) DEFAULT NULL AFTER referrer,
				ADD COLUMN utm_campaign VARCHAR(100) DEFAULT NULL AFTER visitor_type,
				ADD COLUMN utm_source VARCHAR(100) DEFAULT NULL AFTER utm_campaign,
				ADD COLUMN utm_medium VARCHAR(100) DEFAULT NULL AFTER utm_source"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Add indexes for common filters
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"ALTER TABLE " . $safe_table_name . "
				ADD INDEX device_type (device_type),
				ADD INDEX browser (browser),
				ADD INDEX os (os),
				ADD INDEX country (country),
				ADD INDEX visitor_type (visitor_type),
				ADD INDEX duration_filter (duration),
				ADD INDEX page_count (page_count)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

			$debug_manager->log( 'Recordings metadata migration completed successfully', 'info', 'database' );
		}
	}

	/**
	 * Create session pages table
	 *
	 * Tracks individual page visits within a session for multi-page recording support.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_session_pages_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_session_pages (
			id           bigint(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			recording_id bigint(20)  UNSIGNED NOT NULL,
			session_id   varchar(64)          NOT NULL,
			page_id      int(8)      UNSIGNED NOT NULL,
			url          text                 NOT NULL,
			title        text                 DEFAULT NULL,
			entry_time   datetime             NOT NULL,
			exit_time    datetime             DEFAULT NULL,
			duration     int(10)     UNSIGNED DEFAULT 0,
			events_count int(10)     UNSIGNED DEFAULT 0,
			clicks_count int(10)     UNSIGNED DEFAULT 0,
			scroll_depth int(3)      UNSIGNED DEFAULT 0,
			page_order   int(5)      UNSIGNED DEFAULT 1,
			device_type  varchar(50)          DEFAULT NULL,
			browser      varchar(100)         DEFAULT NULL,
			os           varchar(100)         DEFAULT NULL,
			country      varchar(2)           DEFAULT NULL,
			country_name varchar(100)         DEFAULT NULL,
			screen_width int(10)     UNSIGNED DEFAULT NULL,
			screen_height int(10)    UNSIGNED DEFAULT NULL,
			referrer     text                 DEFAULT NULL,
			visitor_type varchar(20)          DEFAULT NULL,
			utm_campaign varchar(100)         DEFAULT NULL,
			utm_source   varchar(100)         DEFAULT NULL,
			utm_medium   varchar(100)         DEFAULT NULL,
			traffic_channel varchar(20)       DEFAULT NULL,
			watched      tinyint(1)  UNSIGNED DEFAULT 0,
			recording_data longtext            DEFAULT NULL,
			file_path    varchar(255)         DEFAULT NULL,
			storage_type varchar(20)          DEFAULT 'database',
			PRIMARY KEY  (id),
			KEY recording_id (recording_id),
			KEY session_id (session_id),
			KEY page_id (page_id),
			KEY entry_time (entry_time),
			KEY file_path (file_path),
			KEY device_type (device_type),
			KEY browser (browser),
			KEY country (country),
			KEY duration (duration),
			KEY clicks_count (clicks_count),
			KEY visitor_type (visitor_type),
			KEY watched (watched)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create referrers table
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_referrers_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_referrers (
			id           bigint(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id   varchar(64)          NOT NULL,
			page_id      int(8)      UNSIGNED NOT NULL,
			referrer_url text                 DEFAULT NULL,
			referrer_type varchar(20)         DEFAULT 'external',
			entry_url    text                 NOT NULL,
			utm_source   varchar(100)         DEFAULT NULL,
			utm_medium   varchar(100)         DEFAULT NULL,
			utm_campaign varchar(100)         DEFAULT NULL,
			utm_term     varchar(100)         DEFAULT NULL,
			utm_content  varchar(100)         DEFAULT NULL,
			created_at   datetime             NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY page_id (page_id),
			KEY referrer_type (referrer_type),
			KEY created_at (created_at)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create outbound clicks table
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_outbound_clicks_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_outbound_clicks (
			id           bigint(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id   varchar(64)          NOT NULL,
			page_id      int(8)      UNSIGNED NOT NULL,
			source_url   text                 NOT NULL,
			target_url   text                 NOT NULL,
			click_type   varchar(20)          DEFAULT 'external',
			element_tag  varchar(50)          DEFAULT NULL,
			element_id   varchar(100)         DEFAULT NULL,
			element_class varchar(200)        DEFAULT NULL,
			element_text varchar(500)         DEFAULT NULL,
			x            mediumint(5) UNSIGNED DEFAULT NULL,
			y            mediumint(7) UNSIGNED DEFAULT NULL,
			created_at   datetime             NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY page_id (page_id),
			KEY click_type (click_type),
			KEY created_at (created_at)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create bot visits table
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_bot_visits_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_bot_visits (
			id           bigint(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			bot_type     varchar(50)          NOT NULL,
			user_agent   text                 NOT NULL,
			ip           varchar(45)          DEFAULT NULL,
			visited_url  text                 NOT NULL,
			referrer     text                 DEFAULT NULL,
			visit_time   datetime             NOT NULL,
			PRIMARY KEY  (id),
			KEY bot_type (bot_type),
			KEY ip (ip)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create heatmap pages mapping table
	 *
	 * Maps page_id to url_hash for fast heatmap file lookups.
	 * This table enables the optimized heatmap storage system.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_heatmap_pages_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_heatmap_pages (
			id           bigint(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			page_id      int(8)      UNSIGNED NOT NULL,
			url_hash     varchar(32)          NOT NULL,
			url          text                 NOT NULL,
			created_at   datetime             NOT NULL,
			updated_at   datetime             NOT NULL,
			click_count  int(10)     UNSIGNED DEFAULT 0,
			move_count   int(10)     UNSIGNED DEFAULT 0,
			scroll_count int(10)     UNSIGNED DEFAULT 0,
			last_data_at datetime             DEFAULT NULL,
			agg_click_pc         int(10)  UNSIGNED DEFAULT 0,
			agg_click_mobile     int(10)  UNSIGNED DEFAULT 0,
			agg_break_pc         int(10)  UNSIGNED DEFAULT 0,
			agg_break_mobile     int(10)  UNSIGNED DEFAULT 0,
			agg_att_pc           int(10)  UNSIGNED DEFAULT 0,
			agg_att_mobile       int(10)  UNSIGNED DEFAULT 0,
			agg_sessions_desktop int(10)  UNSIGNED DEFAULT 0,
			agg_sessions_mobile  int(10)  UNSIGNED DEFAULT 0,
			agg_sessions         int(10)  UNSIGNED DEFAULT 0,
			agg_sort_sessions    int(10)  UNSIGNED DEFAULT 0,
			agg_canonical_sessions         int(10)  UNSIGNED DEFAULT 0,
			agg_canonical_sessions_desktop int(10)  UNSIGNED DEFAULT 0,
			agg_canonical_sessions_mobile  int(10)  UNSIGNED DEFAULT 0,
			agg_canonical_synced_at        datetime          DEFAULT NULL,
			agg_interactions     int(10)  UNSIGNED DEFAULT 0,
			agg_last_event       datetime          DEFAULT NULL,
			agg_has_data         tinyint(1)        DEFAULT 0,
			agg_spam_state       tinyint(1)        DEFAULT 0,
			agg_synced_at        datetime          DEFAULT NULL,
			daily_synced_at      datetime          DEFAULT NULL,
			daily_spam_state     tinyint(1)        DEFAULT 0,
			daily_file_count     int(10)  UNSIGNED DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY page_id (page_id),
			KEY last_data_at (last_data_at),
			KEY agg_sort_inter (agg_has_data, agg_interactions),
			KEY agg_sort_sess (agg_has_data, agg_sort_sessions),
			KEY agg_sort_canon (agg_has_data, agg_canonical_sessions),
			KEY agg_sort_last (agg_has_data, agg_last_event),
			KEY agg_synced (agg_synced_at),
			KEY daily_synced (daily_synced_at)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create the per-day heatmap aggregate index table (spec §P1).
	 *
	 * One row per (page_id, day): per-device interaction point buckets, unique
	 * guest session counts, per-day file count and the newest event timestamp.
	 * Every value is derivable from filenames only, so building/refreshing rows
	 * never reads file contents. Dated list/stats views SUM over this table —
	 * O(pages), regardless of how many session JSON files exist on disk.
	 *
	 * Maintained by: write-time upserts in
	 * {@see Opti_Behavior_Heatmap_Storage::save_heatmap_data()} (one O(1) upsert
	 * per beacon save) + the incremental cron indexer
	 * (Opti_Behavior_Heatmap_Dashboard::cron_sync_heatmap_daily_index()), which
	 * re-derives stale pages from a filename-only pass.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_heatmap_daily_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_heatmap_daily (
			id               bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			page_id          int(8)     UNSIGNED NOT NULL,
			day              date                NOT NULL,
			click_pc         int(10)    UNSIGNED DEFAULT 0,
			click_mobile     int(10)    UNSIGNED DEFAULT 0,
			att_pc           int(10)    UNSIGNED DEFAULT 0,
			att_mobile       int(10)    UNSIGNED DEFAULT 0,
			break_pc         int(10)    UNSIGNED DEFAULT 0,
			break_mobile     int(10)    UNSIGNED DEFAULT 0,
			sessions_desktop int(10)    UNSIGNED DEFAULT 0,
			sessions_mobile  int(10)    UNSIGNED DEFAULT 0,
			sessions_tablet  int(10)    UNSIGNED DEFAULT 0,
			file_count       int(10)    UNSIGNED DEFAULT 0,
			last_event_ts    datetime            DEFAULT NULL,
			synced_at        datetime            DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY page_day (page_id, day),
			KEY day (day)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Ensure the heatmap_pages Phase 3 aggregate columns + sort indexes exist.
	 *
	 * dbDelta in {@see create_heatmap_pages_table()} adds them on a normal
	 * upgrade, but this guarded ALTER path is a safety net for installs where a
	 * partial/legacy dbDelta did not pick up the composite KEYs. It is idempotent
	 * (checks INFORMATION_SCHEMA before each change) and never touches data; new
	 * rows/columns default to agg_synced_at = NULL so the dashboard treats them as
	 * "stale" and recomputes exact values on first read.
	 *
	 * @since 1.6.7
	 */
	private function migrate_heatmap_pages_aggregate_columns() {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// Guard: table must exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time migration existence check.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}

		$columns = array(
			'agg_click_pc'         => 'int(10) UNSIGNED DEFAULT 0',
			'agg_click_mobile'     => 'int(10) UNSIGNED DEFAULT 0',
			'agg_break_pc'         => 'int(10) UNSIGNED DEFAULT 0',
			'agg_break_mobile'     => 'int(10) UNSIGNED DEFAULT 0',
			'agg_att_pc'           => 'int(10) UNSIGNED DEFAULT 0',
			'agg_att_mobile'       => 'int(10) UNSIGNED DEFAULT 0',
			'agg_sessions_desktop' => 'int(10) UNSIGNED DEFAULT 0',
			'agg_sessions_mobile'  => 'int(10) UNSIGNED DEFAULT 0',
			'agg_sessions'         => 'int(10) UNSIGNED DEFAULT 0',
			'agg_sort_sessions'    => 'int(10) UNSIGNED DEFAULT 0',
			'agg_canonical_sessions'         => 'int(10) UNSIGNED DEFAULT 0',
			'agg_canonical_sessions_desktop' => 'int(10) UNSIGNED DEFAULT 0',
			'agg_canonical_sessions_mobile'  => 'int(10) UNSIGNED DEFAULT 0',
			'agg_canonical_synced_at'        => 'datetime DEFAULT NULL',
			'agg_interactions'     => 'int(10) UNSIGNED DEFAULT 0',
			'agg_last_event'       => 'datetime DEFAULT NULL',
			'agg_has_data'         => 'tinyint(1) DEFAULT 0',
			'agg_spam_state'       => 'tinyint(1) DEFAULT 0',
			'agg_synced_at'        => 'datetime DEFAULT NULL',
		);

		$canonical_col_added = false;
		foreach ( $columns as $name => $definition ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration column existence check.
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
					DB_NAME,
					$table,
					$name
				)
			);
			if ( ! $exists ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Idempotent schema migration; identifiers are hard-coded.
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$definition}" );
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
				if ( 'agg_canonical_sessions' === $name ) {
					$canonical_col_added = true;
				}
			}
		}

		// Transition-window seed: the Sessions column is now SQL-sorted on
		// agg_canonical_sessions (canonical all-human universe). Until the
		// dedicated canonical backfill cron drains every page to its exact
		// canonical count, seed the freshly-added column with the best available
		// non-zero proxy (the guest-session counters) so ORDER BY never strands a
		// real page at 0 (agg_canonical_synced_at stays NULL -> the backfill
		// refines each row to the exact canonical value). Idempotent: only runs
		// the one time the column is first created.
		if ( $canonical_col_added ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time seed of a newly added column; identifiers hard-coded.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query(
				"UPDATE {$table}
				SET agg_canonical_sessions         = GREATEST(agg_sessions, agg_sort_sessions, agg_sessions_desktop + agg_sessions_mobile),
					agg_canonical_sessions_desktop = agg_sessions_desktop,
					agg_canonical_sessions_mobile  = agg_sessions_mobile,
					agg_canonical_synced_at        = NULL
				WHERE agg_has_data = 1"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$indexes = array(
			'agg_sort_inter' => '(agg_has_data, agg_interactions)',
			'agg_sort_sess'  => '(agg_has_data, agg_sort_sessions)',
			'agg_sort_canon' => '(agg_has_data, agg_canonical_sessions)',
			'agg_sort_last'  => '(agg_has_data, agg_last_event)',
			'agg_synced'     => '(agg_synced_at)',
		);

		foreach ( $indexes as $index_name => $index_cols ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration index existence check.
			$index_exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
					DB_NAME,
					$table,
					$index_name
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( ! $index_exists ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Idempotent schema migration; identifiers are hard-coded.
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				$wpdb->query( "ALTER TABLE {$table} ADD KEY {$index_name} {$index_cols}" );
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
		}
	}

	/**
	 * Ensure the per-day heatmap index schema exists (spec §P1/§7).
	 *
	 * Safety net beside the dbDelta in setup() — mirrors
	 * {@see migrate_heatmap_pages_aggregate_columns()}: idempotent, checks
	 * INFORMATION_SCHEMA before each change, never touches data. New/upgraded
	 * mapping rows default to daily_synced_at = NULL, so the incremental cron
	 * indexer treats every page as "stale" and backfills its daily rows from a
	 * filename-only pass (upgrade backfill, spec §5b).
	 *
	 * Public so QA harnesses and the cron indexer can self-heal an interrupted
	 * upgrade without re-running the whole setup().
	 *
	 * @since 1.9.x
	 * @return void
	 */
	public function migrate_heatmap_daily_index_schema() {
		global $wpdb;

		$pages_table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$daily_table = $wpdb->prefix . 'optibehavior_heatmap_daily';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Guard: mapping table must exist (fresh installs run setup() first).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration existence check.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pages_table ) ) ) {
			return;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// 1) Daily index table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration existence check.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $daily_table ) ) ) {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
			$charset_collate = $wpdb->get_charset_collate();
			$this->create_heatmap_daily_table( $charset_collate );
		}

		// 2) daily_* marker columns on the mapping table.
		$columns = array(
			'daily_synced_at'  => 'datetime DEFAULT NULL',
			'daily_spam_state' => 'tinyint(1) DEFAULT 0',
			'daily_file_count' => 'int(10) UNSIGNED DEFAULT 0',
		);

		foreach ( $columns as $name => $definition ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration column existence check.
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					DB_NAME,
					$pages_table,
					$name
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( ! $exists ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Idempotent schema migration; identifiers are hard-coded.
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				$wpdb->query( "ALTER TABLE {$pages_table} ADD COLUMN {$name} {$definition}" );
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// 3) Staleness index.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Migration index existence check.
		$index_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				DB_NAME,
				$pages_table,
				'daily_synced'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! $index_exists ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Idempotent schema migration; identifiers are hard-coded.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->query( "ALTER TABLE {$pages_table} ADD KEY daily_synced (daily_synced_at)" );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}
	}

	/**
	 * Create daily stats table for pre-aggregated dashboard metrics
	 *
	 * This table stores pre-computed daily statistics to avoid expensive
	 * GROUP BY DATE() queries on millions of rows. Data is aggregated
	 * nightly via cron job for fast dashboard loading.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_daily_stats_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_daily_stats (
			id                  bigint(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			stat_date           date                 NOT NULL,
			sessions            int(10)     UNSIGNED DEFAULT 0,
			visitors            int(10)     UNSIGNED DEFAULT 0,
			pageviews           int(10)     UNSIGNED DEFAULT 0,
			bounce_sessions     int(10)     UNSIGNED DEFAULT 0,
			total_duration      bigint(20)  UNSIGNED DEFAULT 0,
			total_scroll_depth  bigint(20)  UNSIGNED DEFAULT 0,
			scroll_depth_count  int(10)     UNSIGNED DEFAULT 0,
			human_sessions      int(10)     UNSIGNED DEFAULT 0,
			spam_sessions       int(10)     UNSIGNED DEFAULT 0,
			automated_sessions  int(10)     UNSIGNED DEFAULT 0,
			new_visitors        int(10)     UNSIGNED DEFAULT 0,
			returning_visitors  int(10)     UNSIGNED DEFAULT 0,
			last_aggregated     datetime             NOT NULL,
			is_finalized        tinyint(1)           DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY stat_date (stat_date),
			KEY is_finalized (is_finalized),
			KEY last_aggregated (last_aggregated)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create Smart Insights table for aggregated, explainable recommendations.
	 *
	 * This table intentionally stores only aggregated insight payloads. It does
	 * not store raw visitor events, replay payloads, or externally generated AI
	 * data. `dbDelta()` makes this safe to run on activation and during admin
	 * self-healing upgrades for existing installs.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_insights_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_insights (
			id                          bigint(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			signal_id                   varchar(100)         NOT NULL,
			signal_name                 varchar(191)         NOT NULL,
			category                    varchar(100)         NOT NULL DEFAULT '',
			entity_type                 varchar(50)          NOT NULL DEFAULT '',
			entity_id                   text                 NOT NULL,
			entity_label                text                 NOT NULL,
			date_from                   date                 NOT NULL,
			date_to                     date                 NOT NULL,
			metrics_json                longtext             NOT NULL,
			detection_json              longtext             NOT NULL,
			scores_json                 longtext             NOT NULL,
			segment_json                longtext             DEFAULT NULL,
			trend_json                  longtext             DEFAULT NULL,
			parent_insight_id           bigint(20)  UNSIGNED DEFAULT NULL,
			correlation_json            longtext             DEFAULT NULL,
			evidence_refs_json          longtext             DEFAULT NULL,
			hypothesis_json             longtext             DEFAULT NULL,
			experiment_json             longtext             DEFAULT NULL,
			impact_json                 longtext             DEFAULT NULL,
			outcome_json                longtext             DEFAULT NULL,
			interpretation              text                 NOT NULL,
			why_it_matters              text                 NOT NULL,
			likely_causes_json          longtext             NOT NULL,
			recommended_actions_json    longtext             NOT NULL,
			related_reports_json        longtext             NOT NULL,
			availability                varchar(50)          NOT NULL DEFAULT 'free',
			group_key                   varchar(191)         NOT NULL DEFAULT '',
			suppressed_until            datetime             DEFAULT NULL,
			source_plugin               varchar(20)          NOT NULL DEFAULT 'free',
			visibility_tier             varchar(50)          NOT NULL DEFAULT 'free',
			status                      varchar(50)          NOT NULL DEFAULT 'new',
			rule_version                varchar(50)          NOT NULL DEFAULT '',
			created_at                  datetime             NOT NULL,
			updated_at                  datetime             NOT NULL,
			last_seen_at                datetime             NOT NULL,
			resolved_at                 datetime             DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_signal_entity (signal_id, entity_type),
			KEY idx_status_updated (status, updated_at),
			KEY idx_date_range (date_from, date_to),
			KEY idx_entity (entity_type, entity_id(191)),
			KEY idx_group_key (group_key),
			KEY idx_visibility_status (visibility_tier, status),
			KEY idx_suppressed_until (suppressed_until),
			KEY idx_parent_insight (parent_insight_id)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create errors table for tracking JavaScript and other errors
	 *
	 * Stores individual error instances captured from sessions.
	 * Part of the Error Tracking PRO feature.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_errors_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_errors (
			id              bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id      varchar(64)           DEFAULT NULL,
			recording_id    bigint(20)   UNSIGNED DEFAULT NULL,
			page_id         int(8)       UNSIGNED DEFAULT NULL,
			error_type      varchar(50)           NOT NULL,
			error_message   text                  NOT NULL,
			error_stack     text                  DEFAULT NULL,
			error_source    varchar(255)          DEFAULT NULL,
			error_line      int(10)      UNSIGNED DEFAULT NULL,
			error_column    int(10)      UNSIGNED DEFAULT NULL,
			severity        varchar(20)           DEFAULT 'error',
			browser         varchar(100)          DEFAULT NULL,
			os              varchar(100)          DEFAULT NULL,
			device_type     varchar(50)           DEFAULT NULL,
			country         varchar(2)            DEFAULT NULL,
			country_name    varchar(100)          DEFAULT NULL,
			url             text                  DEFAULT NULL,
			user_id         bigint(20)   UNSIGNED DEFAULT NULL,
			visitor_id      varchar(64)           DEFAULT NULL,
			occurred_at     datetime              NOT NULL,
			resolved        tinyint(1)            DEFAULT 0,
			resolved_at     datetime              DEFAULT NULL,
			resolved_by     bigint(20)   UNSIGNED DEFAULT NULL,
			notes           text                  DEFAULT NULL,
			created_at      datetime              NOT NULL,
			error_origin    varchar(500)          DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY recording_id (recording_id),
			KEY error_type (error_type),
			KEY severity (severity),
			KEY occurred_at (occurred_at),
			KEY resolved (resolved),
			KEY page_id (page_id),
			KEY browser (browser),
			KEY device_type (device_type),
			KEY country (country),
			KEY visitor_id (visitor_id),
			KEY error_origin (error_origin(191))
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create error types table for aggregated error statistics
	 *
	 * Groups similar errors together for quick dashboard queries.
	 * Part of the Error Tracking PRO feature.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_error_types_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_error_types (
			id                bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			error_hash        varchar(64)           NOT NULL,
			error_type        varchar(50)           NOT NULL,
			error_message     text                  NOT NULL,
			error_source      varchar(255)          DEFAULT NULL,
			first_seen        datetime              NOT NULL,
			last_seen         datetime              NOT NULL,
			occurrence_count  int(10)      UNSIGNED DEFAULT 1,
			affected_sessions int(10)      UNSIGNED DEFAULT 1,
			affected_users    int(10)      UNSIGNED DEFAULT 1,
			affected_pages    int(10)      UNSIGNED DEFAULT 1,
			severity          varchar(20)           DEFAULT 'error',
			status            varchar(20)           DEFAULT 'open',
			assigned_to       bigint(20)   UNSIGNED DEFAULT NULL,
			priority          int(3)       UNSIGNED DEFAULT 50,
			created_at        datetime              NOT NULL,
			updated_at        datetime              NOT NULL,
			error_origin      varchar(500)          DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY error_hash (error_hash),
			KEY error_type (error_type),
			KEY severity (severity),
			KEY status (status),
			KEY priority (priority),
			KEY occurrence_count (occurrence_count),
			KEY last_seen (last_seen),
			KEY error_origin (error_origin(191))
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create friction table for tracking user friction events
	 *
	 * Stores rage clicks, dead clicks, form abandonment, etc.
	 * Part of the Error Tracking PRO feature.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_friction_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_friction (
			id                bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id        varchar(64)           DEFAULT NULL,
			recording_id      bigint(20)   UNSIGNED DEFAULT NULL,
			page_id           int(8)       UNSIGNED DEFAULT NULL,
			friction_type     varchar(50)           NOT NULL,
			element_selector  varchar(500)          DEFAULT NULL,
			element_tag       varchar(50)           DEFAULT NULL,
			element_id        varchar(100)          DEFAULT NULL,
			element_class     varchar(200)          DEFAULT NULL,
			element_text      varchar(255)          DEFAULT NULL,
			click_count       int(5)       UNSIGNED DEFAULT NULL,
			time_window       int(10)      UNSIGNED DEFAULT NULL,
			x_position        int(5)       UNSIGNED DEFAULT NULL,
			y_position        int(5)       UNSIGNED DEFAULT NULL,
			viewport_width    int(5)       UNSIGNED DEFAULT NULL,
			viewport_height   int(5)       UNSIGNED DEFAULT NULL,
			url               text                  DEFAULT NULL,
			browser           varchar(100)          DEFAULT NULL,
			os                varchar(100)          DEFAULT NULL,
			device_type       varchar(50)           DEFAULT NULL,
			country           varchar(2)            DEFAULT NULL,
			country_name      varchar(100)          DEFAULT NULL,
			visitor_id        varchar(64)           DEFAULT NULL,
			user_id           bigint(20)   UNSIGNED DEFAULT NULL,
			occurred_at       datetime              NOT NULL,
			created_at        datetime              NOT NULL,
			error_signature   varchar(500)          DEFAULT NULL,
			error_group       varchar(500)          DEFAULT NULL,
			element_xpath     varchar(500)          DEFAULT NULL,
			signal_version    tinyint(3)   UNSIGNED DEFAULT NULL,
			target_interactive tinyint(1)           DEFAULT NULL,
			target_disabled   tinyint(1)            DEFAULT NULL,
			overlay_covered   tinyint(1)            DEFAULT NULL,
			post_click_outcome varchar(20)          DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY recording_id (recording_id),
			KEY friction_type (friction_type),
			KEY page_id (page_id),
			KEY occurred_at (occurred_at),
			KEY element_selector (element_selector(191)),
			KEY country (country),
			KEY visitor_id (visitor_id),
			KEY error_signature (error_signature(191)),
			KEY error_group (error_group(191))
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create performance table for page performance metrics
	 *
	 * Stores Core Web Vitals and other performance metrics.
	 * Part of the Error Tracking PRO feature.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_performance_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_performance (
			id                  bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id          varchar(64)           DEFAULT NULL,
			page_id             int(8)       UNSIGNED DEFAULT NULL,
			url                 text                  DEFAULT NULL,
			dns_lookup          int(10)      UNSIGNED DEFAULT NULL,
			tcp_connection      int(10)      UNSIGNED DEFAULT NULL,
			ssl_handshake       int(10)      UNSIGNED DEFAULT NULL,
			ttfb                int(10)      UNSIGNED DEFAULT NULL,
			response_time       int(10)      UNSIGNED DEFAULT NULL,
			dom_interactive     int(10)      UNSIGNED DEFAULT NULL,
			dom_complete        int(10)      UNSIGNED DEFAULT NULL,
			load_complete       int(10)      UNSIGNED DEFAULT NULL,
			lcp                 int(10)      UNSIGNED DEFAULT NULL,
			fid                 int(10)      UNSIGNED DEFAULT NULL,
			cls                 float(5,3)            DEFAULT NULL,
			fcp                 int(10)      UNSIGNED DEFAULT NULL,
			inp                 int(10)      UNSIGNED DEFAULT NULL,
			resource_count      int(5)       UNSIGNED DEFAULT NULL,
			total_transfer_size bigint(20)   UNSIGNED DEFAULT NULL,
			performance_score   int(3)       UNSIGNED DEFAULT NULL,
			is_slow             tinyint(1)            DEFAULT 0,
			browser             varchar(100)          DEFAULT NULL,
			os                  varchar(100)          DEFAULT NULL,
			device_type         varchar(50)           DEFAULT NULL,
			connection_type     varchar(50)           DEFAULT NULL,
			country             varchar(2)            DEFAULT NULL,
			country_name        varchar(100)          DEFAULT NULL,
			visitor_id          varchar(64)           DEFAULT NULL,
			measured_at         datetime              NOT NULL,
			created_at          datetime              NOT NULL,
			page_title          varchar(255)          DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY page_id (page_id),
			KEY measured_at (measured_at),
			KEY is_slow (is_slow),
			KEY performance_score (performance_score),
			KEY load_complete (load_complete),
			KEY lcp (lcp),
			KEY country (country),
			KEY visitor_id (visitor_id)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create broken links table for tracking 404s and broken links
	 *
	 * Stores broken links and 404 errors detected during sessions.
	 * Part of the Error Tracking PRO feature.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_broken_links_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_broken_links (
			id                bigint(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			url               text                  NOT NULL,
			url_hash          varchar(64)           NOT NULL,
			source_page_id    int(8)       UNSIGNED DEFAULT NULL,
			source_url        text                  DEFAULT NULL,
			link_text         varchar(255)          DEFAULT NULL,
			http_status       int(3)       UNSIGNED DEFAULT NULL,
			error_type        varchar(50)           DEFAULT '404',
			first_detected    datetime              NOT NULL,
			last_detected     datetime              NOT NULL,
			occurrence_count  int(10)      UNSIGNED DEFAULT 1,
			affected_sessions int(10)      UNSIGNED DEFAULT 1,
			status            varchar(20)           DEFAULT 'open',
			fixed_at          datetime              DEFAULT NULL,
			notes             text                  DEFAULT NULL,
			created_at        datetime              NOT NULL,
			updated_at        datetime              NOT NULL,
			last_checked_at   datetime              DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY source_page_id (source_page_id),
			KEY http_status (http_status),
			KEY status (status),
			KEY occurrence_count (occurrence_count),
			KEY last_detected (last_detected),
			KEY error_type (error_type),
			KEY bl_recheck (status, error_type, last_checked_at)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create journey groups table
	 *
	 * Creates table for storing user journey page grouping rules (PRO feature).
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_journey_groups_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_journey_groups (
			id             bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			group_name     varchar(255)        NOT NULL,
			group_color    varchar(7)          DEFAULT '#6366f1',
			match_type     varchar(20)         DEFAULT 'contains',
			match_pattern  varchar(500)        NOT NULL,
			priority       int(11)             DEFAULT 0,
			is_active      tinyint(1)          DEFAULT 1,
			created_at     datetime            DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_active_priority (is_active, priority)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create report schedules tables
	 *
	 * Creates tables for storing scheduled report configurations and logs.
	 *
	 * @param string $charset_collate Database charset collation.
	 */
	private function create_report_schedules_tables( $charset_collate ) {
		global $wpdb;

		// Report schedules table
		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_report_schedules (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(255) NOT NULL DEFAULT 'My Report',
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				frequency VARCHAR(20) NOT NULL DEFAULT 'weekly',
				day_of_week TINYINT UNSIGNED DEFAULT 1,
				day_of_month TINYINT UNSIGNED DEFAULT 1,
				send_time TIME NOT NULL DEFAULT '09:00:00',
				timezone VARCHAR(50) DEFAULT '',
				report_period VARCHAR(30) NOT NULL DEFAULT 'last7days',
				include_kpis TINYINT(1) DEFAULT 1,
				include_top_pages TINYINT(1) DEFAULT 1,
				include_top_referrers TINYINT(1) DEFAULT 1,
				include_traffic_breakdown TINYINT(1) DEFAULT 1,
				include_smart_insights TINYINT(1) DEFAULT 1,
				include_funnels TINYINT(1) DEFAULT 1,
				include_heatmap_summary TINYINT(1) DEFAULT 1,
				include_geographic TINYINT(1) DEFAULT 1,
				include_recordings_stats TINYINT(1) DEFAULT 0,
				include_errors TINYINT(1) DEFAULT 0,
				include_friction TINYINT(1) DEFAULT 0,
				include_performance TINYINT(1) DEFAULT 0,
				include_broken_links TINYINT(1) DEFAULT 0,
				include_user_journeys TINYINT(1) DEFAULT 0,
				include_form_analytics TINYINT(1) DEFAULT 0,
				recipients TEXT NOT NULL,
				created_by BIGINT UNSIGNED DEFAULT NULL,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				last_sent_at DATETIME DEFAULT NULL,
				next_send_at DATETIME DEFAULT NULL,
				PRIMARY KEY (id),
				KEY enabled (enabled),
				KEY next_send_at (next_send_at),
				KEY frequency (frequency)
			) " . self::engine_clause() . " " . $charset_collate
		);

		// Report logs table
		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_report_logs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				schedule_id BIGINT UNSIGNED NOT NULL,
				sent_at DATETIME NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				recipients_count INT UNSIGNED DEFAULT 0,
				recipients_success INT UNSIGNED DEFAULT 0,
				report_period_start DATE DEFAULT NULL,
				report_period_end DATE DEFAULT NULL,
				error_message TEXT DEFAULT NULL,
				execution_time_ms INT UNSIGNED DEFAULT 0,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY schedule_id (schedule_id),
				KEY sent_at (sent_at),
				KEY status (status)
			) " . self::engine_clause() . " " . $charset_collate
		);
	}

	/**
	 * Create a default report schedule if none exists.
	 *
	 * Creates a weekly report schedule for the admin email on first activation.
	 *
	 * @since 1.1.0
	 */
	private function maybe_create_default_schedule() {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Check if any schedules exist
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM " . $wpdb->prefix . "optibehavior_report_schedules" );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $count > 0 ) {
			return; // Schedules already exist, don't create default
		}

		// Get admin email
		$admin_email = get_option( 'admin_email' );
		if ( empty( $admin_email ) ) {
			return;
		}

		// Calculate next Monday at 9 AM
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now = new DateTime( 'now', $tz );
		$next_monday = clone $now;
		$next_monday->modify( 'next monday' );
		$next_monday->setTime( 9, 0, 0 );

		// Pro sections default to enabled when pro is active
		$pro_default = ( function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active() ) ? 1 : 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Create default weekly report
		$wpdb->insert(
			$wpdb->prefix . 'optibehavior_report_schedules',
			array(
				'name'                      => __( 'Weekly Analytics Report', 'opti-behavior' ),
				'enabled'                   => 1,
				'frequency'                 => 'weekly',
				'day_of_week'               => 1, // Monday
				'day_of_month'              => 1,
				'send_time'                 => '09:00:00',
				'timezone'                  => '',
				'report_period'             => 'last7days',
				'include_kpis'              => 1,
				'include_top_pages'         => 1,
				'include_top_referrers'     => 1,
				'include_traffic_breakdown' => 1,
				'include_smart_insights'    => 1,
				'include_funnels'           => 1,
				'include_heatmap_summary'   => 1,
				'include_geographic'        => 1,
				'include_recordings_stats'  => $pro_default,
				'include_errors'            => $pro_default,
				'include_friction'          => $pro_default,
				'include_performance'       => $pro_default,
				'include_broken_links'      => $pro_default,
				'include_user_journeys'     => $pro_default,
				'include_form_analytics'    => $pro_default,
				'recipients'                => wp_json_encode( array( $admin_email ) ),
				'created_by'                => get_current_user_id(),
				'created_at'                => current_time( 'mysql' ),
				'updated_at'                => current_time( 'mysql' ),
				'next_send_at'              => $next_monday->format( 'Y-m-d H:i:s' ),
			),
			array(
				'%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s',
				'%d', '%d', '%d', '%d', '%d', '%d', '%d',
				'%d', '%d', '%d', '%d', '%d', '%d',
				'%d', '%d',
				'%s', '%d', '%s', '%s', '%s',
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'Created default weekly report schedule for ' . $admin_email, 'info', 'database' );
	}

	/**
	 * Clean up invalid click data
	 */
	private function cleanup_invalid_data() {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Guard: table may not exist on a fresh install where the legacy migration dropped it,
		// or in error-recovery scenarios.  Skip silently rather than triggering a DB error.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence check; caching not appropriate.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'optibehavior_events' ) ) ) {
			return;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// PRODUCTION SAFETY (C2-3): the (x<1 AND y<1) predicate is unindexed, so
		// this DELETE full-scans the events table (measured 88s at 5M rows for
		// ZERO deleted rows) — and setup() used to re-run it on every attempt.
		// Version-flag the cleanup and, on large tables, hand it to the batched
		// background worker instead of running inline in a web request.
		if ( OPTI_BEHAVIOR_HEATMAP_VERSION === get_option( 'opti_behavior_invalid_data_cleaned_ver' ) ) {
			return;
		}

		if ( 'cli' !== PHP_SAPI && $this->is_large_table( $wpdb->prefix . 'optibehavior_events' ) ) {
			$this->schedule_heavy_migrations();
			return;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . $wpdb->prefix . 'optibehavior_events WHERE event IN (%d, %d) AND ( x < 1 AND y < 1 )',
			Opti_Behavior_Heatmap_Core::CLICK_PC,
			Opti_Behavior_Heatmap_Core::CLICK_MOBILE
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		update_option( 'opti_behavior_invalid_data_cleaned_ver', OPTI_BEHAVIOR_HEATMAP_VERSION, false );
	}

	/**
	 * Setup daily cron jobs
	 */
	private function setup_daily_cron() {
		$tz  = $this->wp_timezone();
		$now = new DateTime( 'now', $tz );

		// Schedule aggregation at 3 AM (runs before cleanup)
		$am3 = new DateTime( 'T0300', $tz );
		if ( $am3 < $now ) {
			$am3->add( new DateInterval( 'P1D' ) );
		}
		$am3_ts = $am3->getTimestamp();

		$next_agg = wp_next_scheduled( 'opti_behavior_aggregate_daily_stats' );
		if ( ! $next_agg ) {
			wp_schedule_event( $am3_ts, 'daily', 'opti_behavior_aggregate_daily_stats' );
		} elseif ( $next_agg < $am3_ts || $am3_ts + 3600 < $next_agg ) {
			wp_clear_scheduled_hook( 'opti_behavior_aggregate_daily_stats' );
			wp_schedule_event( $am3_ts, 'daily', 'opti_behavior_aggregate_daily_stats' );
		}

		// Schedule cleanup at 4 AM (runs after aggregation)
		$am4 = new DateTime( 'T0400', $tz );
		if ( $am4 < $now ) {
			$am4->add( new DateInterval( 'P1D' ) );
		}
		$am4_ts = $am4->getTimestamp();

		$next = wp_next_scheduled( 'opti_behavior_heatmap_cron_daily' );
		if ( ! $next ) {
			wp_schedule_event( $am4_ts, 'daily', 'opti_behavior_heatmap_cron_daily' );
		} elseif ( $next < $am4_ts || $am4_ts + 3600 < $next ) {
			wp_clear_scheduled_hook( 'opti_behavior_heatmap_cron_daily' );
			wp_schedule_event( $am4_ts, 'daily', 'opti_behavior_heatmap_cron_daily' );
		}

		// Schedule Smart Insights generation at 3:45 AM, after daily aggregation
		// and before raw-data cleanup. This only schedules the job; generation is
		// still run by cron/admin/AJAX paths, never by public page rendering.
		$am345 = new DateTime( 'T0345', $tz );
		if ( $am345 < $now ) {
			$am345->add( new DateInterval( 'P1D' ) );
		}
		$smart_insights_hook = Opti_Behavior_Smart_Insights_Generator::CRON_HOOK;
		if ( class_exists( 'Opti_Behavior_Smart_Insights_Scheduler' ) ) {
			Opti_Behavior_Smart_Insights_Scheduler::reschedule();
		} elseif ( ! wp_next_scheduled( $smart_insights_hook ) ) {
			wp_schedule_event( $am345->getTimestamp(), 'daily', $smart_insights_hook );
		}

		// Schedule report sending (every 15 minutes).
		$this->ensure_scheduled_reports_cron();

		// A/B Testing cron: daily aggregation (03:15), daily raw-data cleanup
		// (05:00) and the hourly auto-winner check. Delegated to the A/B manager
		// so the very same routine also repairs a slot that drifted on an install
		// upgraded from an older version (QA-B-AB-019).
		if ( class_exists( 'Opti_Behavior_AB_Test_Manager' ) ) {
			Opti_Behavior_AB_Test_Manager::ensure_cron_schedules();
		}
	}

	/**
	 * Set sensible default auto-cleanup settings on first install.
	 *
	 * Fresh installs receive canonical settings. Existing installs are NEVER
	 * overwritten: a one-time pass only seeds keys their saved shape is
	 * missing (see maybe_apply_default_auto_cleanup_once()); the admin's
	 * `enabled` flag and deletion conditions are always authoritative.
	 *
	 * Defaults:
	 *  - Enabled, daily frequency.
	 *  - Remove sessions older than 360 days.
	 *  - Remove bot/spam/automated sessions.
	 *  - Remove short sessions (< 5s) older than 1 day.
	 *  - Delete orphaned visitors without sessions.
	 *
	 * @since 1.2.5
	 */
	private function maybe_set_default_auto_cleanup() {
		$existing = get_option( 'opti_behavior_auto_cleanup_settings' );
		$defaults = $this->get_default_auto_cleanup_settings();

		if ( false !== $existing ) {
			$this->maybe_apply_default_auto_cleanup_once( $existing, $defaults );

			return;
		}

		update_option( 'opti_behavior_auto_cleanup_settings', $defaults );

		// Fresh installs already run the current defaults; never overwrite later customizations.
		update_option( 'opti_behavior_auto_cleanup_defaults_migrated', '1', false );

		// Schedule the cron event for daily cleanup.
		$hook = 'opti_behavior_scheduled_smart_cleanup';
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $hook );
		}

		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'Default auto-cleanup settings applied (daily, 360-day retention, bot/spam + short-session cleanup)', 'info', 'database' );
	}

	/**
	 * Get canonical fresh-install auto-cleanup defaults.
	 *
	 * @since 1.2.7
	 * @return array Default settings.
	 */
	private function get_default_auto_cleanup_settings() {
		return array(
			'enabled'                  => true,
			'frequency'                => 'daily',
			'delete_orphaned_visitors' => true,
			'conditions'               => array(
				'older_than_days'       => 360,  // Remove sessions older than 360 days.
				'include_bots'          => true, // Back-compat alias for spam traffic cleanup.
				'include_spam_traffic'  => true, // Remove spam, bot, and automated sessions.
				'min_duration'          => 5,    // Remove sessions shorter than 5 seconds.
				'min_duration_age_days' => 1,    // Only if older than 1 day.
			),
			'max_rows_per_run'         => 50000, // Keep cron cleanup bounded.
			'optimize_after_cleanup'   => false, // Manual cleanup defaults to optimize; scheduled cleanup opts in.
			'recalculate_spam_before_cleanup' => false,
			'last_run'                 => null,
			'last_result'              => array(),
		);
	}

	/**
	 * Seed missing auto-cleanup setting keys exactly once after a plugin
	 * update — WITHOUT ever overwriting what the install already has.
	 *
	 * 2026-08-16 incident guardrail: the previous one-time injection replaced
	 * whatever the admin had saved with the canonical defaults (enabled /
	 * daily / spam + short-session cleanup) and force-rescheduled the cron.
	 * That silently re-enabled scheduled deletion after an update and
	 * triggered a mass session deletion in production. Existing values —
	 * especially the `enabled` flag and the deletion `conditions` — are now
	 * authoritative and are never touched:
	 *  - Only top-level keys the saved shape is MISSING are seeded.
	 *  - The safety-critical keys seed to inert values (enabled => false,
	 *    conditions => no rules), so an update can never silently enable a
	 *    deletion rule the admin did not configure.
	 *  - The cron event is never cleared/rescheduled; it is only created when
	 *    the saved settings are enabled and no event exists (self-heal on the
	 *    SAVED cadence, not the daily default).
	 *
	 * The unsafe legacy condition shape (max_duration = 2 / 30 days) is NOT
	 * handled here: {@see Opti_Behavior_Smart_Cleanup_Service::maybe_migrate_saved_auto_cleanup_settings()}
	 * migrates it at run time on every supported cadence.
	 *
	 * @since 1.2.8
	 * @since 2026-08-16 No longer overwrites existing settings; seeds missing keys only.
	 * @param mixed $existing Saved auto-cleanup settings.
	 * @param array $defaults Canonical default settings.
	 */
	private function maybe_apply_default_auto_cleanup_once( $existing, $defaults ) {
		if ( get_option( 'opti_behavior_auto_cleanup_defaults_migrated' ) ) {
			return;
		}

		$debug_manager = $this->core->get_debug_manager();

		if ( ! is_array( $existing ) ) {
			// Unreadable/corrupt option value: treat like a fresh install.
			update_option( 'opti_behavior_auto_cleanup_settings', $defaults );
			update_option( 'opti_behavior_auto_cleanup_defaults_migrated', '1', false );

			$hook = 'opti_behavior_scheduled_smart_cleanup';
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $hook );
			}

			$debug_manager->log( 'Auto-cleanup settings were unreadable; canonical defaults restored', 'warning', 'database' );
			return;
		}

		$seeded = $existing;

		// Safety-critical keys seed to inert values when missing: an update
		// must never silently enable deletion or add deletion rules.
		if ( ! array_key_exists( 'enabled', $seeded ) ) {
			$seeded['enabled'] = false;
		}
		if ( ! isset( $seeded['conditions'] ) || ! is_array( $seeded['conditions'] ) ) {
			$seeded['conditions'] = array();
		}

		foreach ( $defaults as $key => $value ) {
			if ( in_array( $key, array( 'enabled', 'conditions' ), true ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $seeded ) ) {
				$seeded[ $key ] = $value;
			}
		}

		if ( $seeded !== $existing ) {
			update_option( 'opti_behavior_auto_cleanup_settings', $seeded );
		}

		update_option( 'opti_behavior_auto_cleanup_defaults_migrated', '1', false );

		// Cron self-heal only: never clear/reschedule an existing event and
		// never force the daily default cadence onto the install. Monthly runs
		// on the daily hook and is cadence-guarded inside run_scheduled_cleanup().
		if ( ! empty( $seeded['enabled'] ) ) {
			$hook = 'opti_behavior_scheduled_smart_cleanup';
			if ( ! wp_next_scheduled( $hook ) ) {
				$recurrence = ( isset( $seeded['frequency'] ) && in_array( $seeded['frequency'], array( 'daily', 'weekly' ), true ) ) ? $seeded['frequency'] : 'daily';
				wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, $hook );
			}
		}

		$debug_manager->log( 'Preserved existing auto-cleanup settings after update (no-overwrite policy); seeded missing keys only', 'info', 'database' );
	}

	/**
	 * Keep the scheduled Conditional Cleanup cron event in sync with the saved
	 * `enabled` flag — the two must never contradict each other.
	 *
	 * Why this exists: deactivation clears every plugin cron hook
	 * ({@see Opti_Behavior_Heatmap_Core::get_all_cron_hooks()}), while
	 * reactivation only re-arms the event on a FRESH install —
	 * maybe_apply_default_auto_cleanup_once() is gated behind the one-shot
	 * `opti_behavior_auto_cleanup_defaults_migrated` flag and returns
	 * immediately on every existing install. A single deactivate/reactivate
	 * cycle therefore left "Enable automatic cleanup" checked with no cron
	 * event behind it, forever.
	 *
	 * Safety (2026-08-16 incident policy): this NEVER changes the admin's
	 * settings. It only makes WP-Cron agree with the flag the admin already
	 * saved — arming when enabled, clearing when disabled. It cannot introduce
	 * a deletion rule that was not configured.
	 *
	 * Duplicate-guard rule: the decision comes from a FULL cron-array scan of
	 * the hook (recurring events counted separately), never from the
	 * earliest-event-only wp_next_scheduled() shortcut.
	 *
	 * Cheap: one option read + one cron-array read on every call.
	 *
	 * @since 1.9.x
	 * @return string One of: healthy | scheduled | rescheduled | cleared | disabled | failed.
	 */
	public function ensure_scheduled_smart_cleanup_cron() {
		$hook     = 'opti_behavior_scheduled_smart_cleanup';
		$settings = get_option( 'opti_behavior_auto_cleanup_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$enabled   = ! empty( $settings['enabled'] );
		$frequency = ( isset( $settings['frequency'] ) && in_array( $settings['frequency'], array( 'daily', 'weekly', 'monthly' ), true ) )
			? (string) $settings['frequency']
			: 'daily';

		// 'monthly' has no core recurrence: it rides the daily event and is
		// throttled inside Opti_Behavior_Smart_Cleanup_Service::run_scheduled_cleanup().
		$recurrence = ( 'monthly' === $frequency ) ? 'daily' : $frequency;

		$recurring_count = 0;
		$armed_recurrence = '';
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) || ! isset( $hooks[ $hook ] ) ) {
				continue;
			}
			foreach ( (array) $hooks[ $hook ] as $event ) {
				if ( is_array( $event ) && ! empty( $event['schedule'] ) ) {
					++$recurring_count;
					if ( '' === $armed_recurrence ) {
						$armed_recurrence = (string) $event['schedule'];
					}
				}
			}
		}

		if ( ! $enabled ) {
			if ( $recurring_count > 0 ) {
				wp_clear_scheduled_hook( $hook );
				return 'cleared';
			}
			return 'disabled';
		}

		$rescheduled = false;
		if ( $recurring_count > 1 || ( 1 === $recurring_count && $armed_recurrence !== $recurrence ) ) {
			// Duplicate events, or a cadence that no longer matches the saved
			// setting: collapse to exactly one correct event.
			wp_clear_scheduled_hook( $hook );
			$recurring_count = 0;
			$rescheduled     = true;
		}

		if ( $recurring_count > 0 ) {
			return 'healthy';
		}

		$scheduled = wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, $hook );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			return 'failed';
		}

		return $rescheduled ? 'rescheduled' : 'scheduled';
	}

	/**
	 * Ensure the scheduled reports worker cron event exists.
	 *
	 * @since 1.2.7
	 * @param bool $only_when_enabled_schedule Only schedule when at least one enabled report schedule exists.
	 * @return bool True when the worker is scheduled or already healthy.
	 */
	public function ensure_scheduled_reports_cron( $only_when_enabled_schedule = false ) {
		$hook       = 'opti_behavior_send_scheduled_reports';
		$recurrence = 'every_fifteen_minutes';

		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );

		if ( $only_when_enabled_schedule && ! $this->has_enabled_report_schedules() ) {
			return false;
		}

		$next     = wp_next_scheduled( $hook );
		$schedule = $next ? wp_get_schedule( $hook ) : false;

		if ( $next && $recurrence !== $schedule ) {
			wp_clear_scheduled_hook( $hook );
			$next = false;
		}

		if ( ! $next ) {
			$scheduled = wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );

			return ! is_wp_error( $scheduled ) && false !== $scheduled;
		}

		return true;
	}

	/**
	 * Check whether report schedules table has enabled schedules.
	 *
	 * @since 1.2.7
	 * @return bool True when at least one schedule is enabled.
	 */
	private function has_enabled_report_schedules() {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_report_schedules';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight table existence check for cron self-healing.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return false;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix.
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is built from $wpdb->prefix; values are prepared.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE enabled = %d", 1 ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter

		return (int) $count > 0;
	}

	/**
	 * Add custom cron intervals.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_intervals( $schedules ) {
		if ( ! isset( $schedules['every_fifteen_minutes'] ) ) {
			$schedules['every_fifteen_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 Minutes', 'opti-behavior' ),
			);
		}
		return $schedules;
	}

	/**
	 * Get WordPress timezone
	 *
	 * @return DateTimeZone
	 */
	private function wp_timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		// Polyfill for wp_timezone() function
		$timezone_string = get_option( 'timezone_string' );

		if ( $timezone_string ) {
			return new DateTimeZone( $timezone_string );
		}

		$offset  = (float) get_option( 'gmt_offset' );
		$hours   = (int) $offset;
		$minutes = ( $offset - $hours );

		$sign      = ( $offset < 0 ) ? '-' : '+';
		$abs_hour  = abs( $hours );
		$abs_mins  = abs( $minutes * 60 );
		$tz_offset = sprintf( '%s%02d:%02d', $sign, $abs_hour, $abs_mins );

		return new DateTimeZone( $tz_offset );
	}

	/**
	 * Update URL2 field for existing pages
	 */
	public function update_url2() {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Guard: table may not exist on a fresh install; skip silently.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence check; caching not appropriate.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'optibehavior_pages' ) ) ) {
			return;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$options = $this->core->get_options();
		$query_filter = $this->make_url_filter( true );
		$fragment_filter = $options['keep_url_hash'];

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$pages = $wpdb->get_results( 'SELECT id, url FROM ' . $wpdb->prefix . 'optibehavior_pages WHERE url2 = \'\'' );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $pages as $page ) {
			$url2 = $this->rebuild_url( $page->url, $query_filter, $fragment_filter );
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->update(
				$wpdb->prefix . "optibehavior_pages",
				array( 'url2' => $url2 ),
				array( 'id' => $page->id ),
				array( '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}
	}

	/**
	 * One-time migration: recompute url2 for every page row after the canonical
	 * URL rules changed (2026-09-12): tracking IDs such as srsltid/gbraid/... are
	 * now always stripped and the trailing slash is normalised. Without this,
	 * rows created under the old rules keep a url2 that no new visit can match,
	 * so every such page would get yet another duplicate row.
	 *
	 * Duplicate rows that already exist keep their own id; lookups now prefer
	 * the lowest id (see Opti_Behavior_Heatmap_Analytics::get_or_create_page_id())
	 * and read paths merge siblings through get_sibling_page_ids().
	 */
	public function migrate_url2_canonical_normalization() {
		if ( get_option( 'opti_behavior_url2_canonical_v2_migrated' ) ) {
			return;
		}

		$debug_manager = $this->core->get_debug_manager();
		$count         = $this->rebuild_all_url2();
		$debug_manager->log( 'url2 canonical normalisation migration complete: ' . (int) $count . ' page row(s) rebuilt', 'info', 'database' );

		update_option( 'opti_behavior_url2_canonical_v2_migrated', '1', false );
	}

	/**
	 * Canonical URL base used to decide whether two page rows are the "same
	 * page" for heatmap purposes: scheme + lowercase host + path without
	 * trailing slash. No port, no query, no fragment. This is exactly the
	 * string Opti_Behavior_Heatmap_Storage::get_url_hash() md5()s, so two rows
	 * with the same base share one heatmap file directory.
	 *
	 * @param string $url URL.
	 * @return string Canonical base, or '' when the URL cannot be parsed.
	 */
	public function get_canonical_url_base( $url ) {
		$parsed = wp_parse_url( (string) $url );
		if ( ! $parsed || ! is_array( $parsed ) ) {
			return '';
		}

		$base = '';
		if ( isset( $parsed['scheme'] ) ) {
			$base .= $parsed['scheme'] . '://';
		}
		if ( isset( $parsed['host'] ) ) {
			$base .= strtolower( $parsed['host'] );
		}
		if ( isset( $parsed['path'] ) ) {
			$base .= rtrim( $parsed['path'], '/' );
		}

		return $base;
	}

	/**
	 * Expand a set of page IDs with every other optibehavior_pages row that
	 * shares the same canonical URL base (see get_canonical_url_base()).
	 *
	 * Why: before the url2 rules were tightened, one physical page could end
	 * up as several rows (".../page/" vs ".../page" vs ".../page/?srsltid=..."),
	 * while their heatmap files always landed in ONE directory (get_url_hash()
	 * strips query + trailing slash). Read paths that restrict sessions by
	 * DB page_id (session_pages, pageviews, events) must therefore consider
	 * the whole sibling group, or the detail page shows only the sessions
	 * that happened to be recorded under the requested id.
	 *
	 * @param array $page_ids Page IDs.
	 * @return array Unique page IDs: the input first, siblings appended. Max 100.
	 */
	public function get_sibling_page_ids( $page_ids ) {
		global $wpdb;

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return array();
		}

		$table        = $wpdb->prefix . 'optibehavior_pages';
		$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix, placeholders built from count(); values bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, url FROM {$table} WHERE id IN ({$placeholders})", $page_ids )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( empty( $rows ) ) {
			return $page_ids;
		}

		$bases = array();
		foreach ( $rows as $row ) {
			$base = $this->get_canonical_url_base( $row->url );
			if ( '' !== $base ) {
				$bases[ $base ] = true;
			}
		}
		if ( empty( $bases ) ) {
			return $page_ids;
		}

		// Cheap SQL pre-filter (prefix match), exact check done in PHP below.
		$where  = array();
		$params = array();
		foreach ( array_keys( $bases ) as $base ) {
			$like     = $wpdb->esc_like( $base );
			$where[]  = '( url = %s OR url = %s OR url LIKE %s OR url LIKE %s OR url LIKE %s OR url LIKE %s )';
			$params[] = $base;
			$params[] = $base . '/';
			$params[] = $like . '?%';
			$params[] = $like . '/?%';
			$params[] = $like . '#%';
			$params[] = $like . '/#%';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix, WHERE built from fixed placeholder fragments; values bound via prepare().
		$candidates = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, url FROM {$table} WHERE " . implode( ' OR ', $where ) . ' ORDER BY id ASC LIMIT 500', $params )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! empty( $candidates ) ) {
			foreach ( $candidates as $candidate ) {
				if ( isset( $bases[ $this->get_canonical_url_base( $candidate->url ) ] ) ) {
					$page_ids[] = (int) $candidate->id;
				}
			}
		}

		return array_slice( array_values( array_unique( $page_ids ) ), 0, 100 );
	}

	/**
	 * Rebuild ALL URL2 fields (used when settings change)
	 */
	public function rebuild_all_url2() {
		global $wpdb;

		$options = $this->core->get_options();
		$query_filter = $this->make_url_filter( true );
		$fragment_filter = $options['keep_url_hash'];

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Get ALL pages, not just empty url2
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$pages = $wpdb->get_results( "SELECT id, url FROM " . $wpdb->prefix . "optibehavior_pages" );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $pages as $page ) {
			$url2 = $this->rebuild_url( $page->url, $query_filter, $fragment_filter );
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->update(
				$wpdb->prefix . "optibehavior_pages",
				array( 'url2' => $url2 ),
				array( 'id' => $page->id ),
				array( '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		return count( $pages );
	}

	/**
	 * Make URL filter for query parameters
	 *
	 * @param bool $use_options Whether to use current options.
	 * @return array|null
	 */
	public function make_url_filter( $use_options = false ) {
		$options = $this->core->get_options();

		if ( $use_options ) {
			$keep_url_query = isset( $options['keep_url_query'] ) ? $options['keep_url_query'] : false;
			$include = isset( $options['url_query_include'] ) && is_array( $options['url_query_include'] ) ? $options['url_query_include'] : array();
			$exclude = isset( $options['url_query_exclude'] ) && is_array( $options['url_query_exclude'] ) ? $options['url_query_exclude'] : array();
		} else {
			$keep_url_query = false;
			$include = array();
			$exclude = array();
		}

		global $wp;
		$wp_public_query_vars = $wp->public_query_vars;

		// Handle case where $wp->public_query_vars is null (during plugin activation)
		if ( ! is_array( $wp_public_query_vars ) ) {
			$wp_public_query_vars = array();
		}

		// Build initial filter according to "keep_url_query" option
		if ( $keep_url_query ) {
			$filter = array_merge( $wp_public_query_vars, $exclude );
		} else {
			$filter = array_diff( $wp_public_query_vars, $include );
		}

		// Always strip debug/marketing params from canonical URL (url2)
		$always_strip = array(
			'opti_behavior_debug',
			'utm_source','utm_medium','utm_campaign','utm_term','utm_content',
			'gclid','fbclid','msclkid','mc_cid','mc_eid','ref',
			// Click / tracking IDs appended by ad networks and social apps. Any of
			// these left in url2 splits one physical page into several page rows
			// (each with its own page_id), which then fragments session counts and
			// heatmap detail views. Same URL, same page.
			'srsltid','gbraid','wbraid','dclid','yclid','ttclid','twclid','li_fat_id',
			'igshid','_gl','_ga','mkt_tok','sscid','epik','vero_id','_hsenc','_hsmi',
			'hsa_cam','hsa_grp','hsa_ad','hsa_src','hsa_tgt','hsa_kw','hsa_mt','hsa_net','hsa_ver',
			'elementor-preview','ver','preview','preview_id','preview_nonce' // Strip preview/version params
		);
		$filter = array_values( array_unique( array_merge( $filter, $always_strip ) ) );

		return $filter;
	}

	/**
	 * Rebuild URL with filters
	 *
	 * @param string     $url Original URL.
	 * @param array|null $query_filter Query parameter filter.
	 * @param bool       $fragment_filter Fragment filter.
	 * @return string
	 */
	public function rebuild_url( $url, $query_filter = null, $fragment_filter = null ) {
		$parsed = wp_parse_url( $url );

		if ( ! $parsed ) {
			return $url;
		}

		$result = '';

		// Scheme and host
		if ( isset( $parsed['scheme'] ) ) {
			$result .= $parsed['scheme'] . '://';
		}
		if ( isset( $parsed['host'] ) ) {
			$result .= $parsed['host'];
		}
		if ( isset( $parsed['port'] ) ) {
			$result .= ':' . $parsed['port'];
		}
		// Path: normalise the trailing slash so "/page" and "/page/" resolve to
		// the SAME page row. This mirrors Opti_Behavior_Heatmap_Storage::get_url_hash()
		// (which rtrim()s the path before hashing), so url2 and the heatmap file
		// directory now agree on what "one page" is. Root stays "/".
		$path = isset( $parsed['path'] ) ? rtrim( $parsed['path'], '/' ) : '';
		$result .= ( '' === $path ) ? '/' : $path;

		// Query parameters
		if ( isset( $parsed['query'] ) && null !== $query_filter ) {
			parse_str( $parsed['query'], $query_params );
			$filtered_params = array();

			foreach ( $query_params as $key => $value ) {
				if ( ! in_array( $key, $query_filter, true ) ) {
					$filtered_params[ $key ] = $value;
				}
			}

			if ( ! empty( $filtered_params ) ) {
				$result .= '?' . http_build_query( $filtered_params );
			}
		} elseif ( isset( $parsed['query'] ) ) {
			$result .= '?' . $parsed['query'];
		}

		// Fragment
		if ( isset( $parsed['fragment'] ) && $fragment_filter ) {
			$result .= '#' . $parsed['fragment'];
		}

		return $result;
	}

	/**
	 * Delete old data based on retention period
	 *
	 * Uses batched deletion to avoid timeouts and lock contention
	 * on large datasets (1M+ rows).
	 *
	 * @param int $months Number of months to retain. 0 (or any falsy/unset
	 *                    value) means retention is DISABLED: nothing is
	 *                    deleted. Never clamp a missing value up to 1 —
	 *                    that turned an unset option into a silent
	 *                    "delete everything older than 1 month" daily wipe.
	 * @return int Number of rows deleted (0 when retention is disabled).
	 */
	public function delete_old_data( $months = 0 ) {
		$debug_manager = $this->core->get_debug_manager();
		$months = absint( $months );
		if ( $months < 1 ) {
			$debug_manager->log( 'delete_old_data skipped: retention period not set (0 = disabled)', 'info', 'database' );
			return 0;
		}
		$date = new DateTime();
		$date->sub( new DateInterval( "P" . $months . "M" ) );
		$cutoff = $date->format( 'Y-m-d H:i:s' );

		return $this->delete_old_data_before( $cutoff, sprintf( '%d month(s)', $months ) );
	}

	/**
	 * Delete old raw data based on the master day-based retention window.
	 *
	 * Unified Retention Protocol (2026-08-15): ONE master retention setting
	 * (days, default 365, {@see Opti_Behavior_Retention_Policy}) drives all
	 * raw/per-session data. Files are deleted/archived in the SAME session
	 * cascade as their DB rows; aggregate tables (daily_stats,
	 * daily_dimension_stats, heatmap_daily, ab_daily_stats) are exempt and
	 * kept indefinitely.
	 *
	 * @since 1.9.0 (Unified Retention Protocol)
	 * @param int $days Days to retain raw data. 0/falsy = retention disabled.
	 * @return int Number of rows deleted.
	 */
	public function delete_old_data_by_days( $days = 0 ) {
		$debug_manager = $this->core->get_debug_manager();
		$days = absint( $days );
		if ( $days < 1 ) {
			$debug_manager->log( 'delete_old_data_by_days skipped: master retention disabled (0 days)', 'info', 'database' );
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return $this->delete_old_data_before( $cutoff, sprintf( '%d day(s)', $days ) );
	}

	/**
	 * Prune dashboard aggregate tables older than an optional month cap.
	 *
	 * Tiered Retention (2026-08-15, round 2): dashboard aggregates are kept
	 * FOREVER by default ({@see Opti_Behavior_Retention_Policy},
	 * aggregates_retention_months = 0). This method only runs when the user
	 * explicitly opts into an advanced cap (> 0 months). It is the ONLY code
	 * path allowed to delete aggregate rows — the master retention purge and
	 * the F6 backstop sweep both exempt these tables.
	 *
	 * @since 1.9.0 (Tiered Retention round 2)
	 * @param int $months Cap in months. 0/falsy = kept forever (no-op).
	 * @return int Total aggregate rows deleted across all tables.
	 */
	public function prune_aggregates_older_than_months( $months ) {
		global $wpdb;

		$debug_manager = $this->core->get_debug_manager();
		$months        = absint( $months );
		if ( $months < 1 ) {
			// Default: aggregates kept FOREVER. Never clamp 0 up to 1.
			return 0;
		}

		$cutoff_date = gmdate( 'Y-m-d', strtotime( '-' . $months . ' months' ) );

		// Aggregate table suffix => DATE column.
		$aggregate_tables = array(
			'optibehavior_daily_stats'           => 'stat_date',
			'optibehavior_daily_dimension_stats' => 'stat_date',
			'optibehavior_heatmap_daily'         => 'day',
			'optibehavior_ab_daily_stats'        => 'stat_date',
		);

		$total_deleted = 0;

		foreach ( $aggregate_tables as $suffix => $date_column ) {
			$table = $wpdb->prefix . $suffix;

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Table name from $wpdb->prefix.
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( ! $exists ) {
				continue;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table/column names are hardcoded above.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE {$date_column} < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff_date
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( $deleted ) {
				$total_deleted += (int) $deleted;
			}
		}

		if ( $total_deleted > 0 ) {
			$debug_manager->log(
				sprintf(
					'Aggregates cap: pruned %d aggregate rows older than %d month(s) (< %s)',
					$total_deleted,
					$months,
					$cutoff_date
				),
				'info',
				'database'
			);
		}

		return $total_deleted;
	}

	/**
	 * Table => date-column map for the Phase-2 retention backstop sweep.
	 *
	 * The sweep catches expired rows the session cascade could not reach
	 * (rows without a session_id, or whose session started after the cutoff
	 * while the row itself is older). Defense-in-depth (spec Finding F6):
	 * this list MUST be a superset of
	 * {@see Opti_Behavior_Smart_Cleanup_Service::get_session_child_table_map()}
	 * plus the sessions table itself and session-less tables (bot_visits).
	 * Aggregate tables (daily_stats, daily_dimension_stats, heatmap_daily,
	 * ab_daily_stats) are intentionally EXEMPT — kept indefinitely per the
	 * Unified Retention Protocol.
	 *
	 * Regression-guarded by tests/test-retention-sweep-superset.php.
	 *
	 * @since 1.9.0 (F6 sweep/cascade parity)
	 * @return array<string,string> Table suffix (without $wpdb->prefix) => date column.
	 */
	public static function get_retention_sweep_table_map() {
		return array(
			'optibehavior_events'            => 'insert_at',
			'optibehavior_sessions'          => 'start_time',
			'optibehavior_pageviews'         => 'view_time',
			'optibehavior_recordings'        => 'start_time',
			'optibehavior_session_pages'     => 'entry_time',
			'optibehavior_referrers'         => 'created_at',
			'optibehavior_outbound_clicks'   => 'created_at',
			'optibehavior_bot_visits'        => 'visit_time',
			'optibehavior_errors'            => 'occurred_at',
			'optibehavior_friction'          => 'occurred_at',
			'optibehavior_performance'       => 'measured_at',
			// F6: session-child tables previously missing from the sweep.
			'optibehavior_form_interactions' => 'created_at',
			'optibehavior_form_submissions'  => 'created_at',
			'opti_behavior_funnel_tracking'  => 'entry_time',
			'optibehavior_ab_impressions'    => 'created_at',
			'optibehavior_ab_conversions'    => 'created_at',
			// Dedupe rows keyed per error message / per 404 URL / per
			// visitor-day: new keys keep arriving (bots, dynamic messages), so
			// rows not seen within the window age out instead of growing forever.
			'optibehavior_error_types'         => 'last_seen',
			'optibehavior_visitors'           => 'last_visit',
			'optibehavior_broken_links'        => 'last_detected',
			// Audit/log tables with no other prune path (Cleanup audit 2026-09-13):
			// one row per report send / per A/B decision — slow but unbounded.
			'optibehavior_report_logs'         => 'sent_at',
			'optibehavior_ab_decision_log'     => 'created_at',
		);
	}

	/**
	 * Shared retention-purge engine used by the legacy month-based and the
	 * unified day-based entry points.
	 *
	 * @since 1.9.0 (Unified Retention Protocol)
	 * @param string $cutoff       MySQL datetime; data older than this is purged.
	 * @param string $period_label Human label for the configured window (for the cleanup log).
	 * @return int Number of rows deleted.
	 */
	private function delete_old_data_before( $cutoff, $period_label ) {
		global $wpdb;

		$debug_manager = $this->core->get_debug_manager();

		$debug_manager->log( "Starting data cleanup for records older than " . $cutoff, 'info', 'database' );

		// Phase 1: delete expired sessions through the Smart Cleanup cascade so
		// their recording files and heatmap JSON files are removed together with
		// the DB rows. Bare row deletes below would leak orphan files on disk
		// (the historical cause of Heatmap Sync Repair orphan findings).
		$cascade = $this->cascade_delete_expired_sessions( $cutoff );

		// Tables to clean with their date columns.
		$tables = self::get_retention_sweep_table_map();

		$batch_size   = 10000;
		$total_deleted = 0;
		$rows_by_table = array();

		// Phase 2: sweep remaining expired rows that the cascade could not
		// reach (rows without a session, or whose session started after the
		// cutoff while the row itself is older).
		foreach ( $tables as $table_suffix => $date_column ) {
			$table = $wpdb->prefix . $table_suffix;

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Check if table exists before trying to delete
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Checking table existence.
			$table_exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! $table_exists ) {
				continue;
			}

			$table_deleted = 0;

			do {
				// Delete in batches to avoid lock timeouts
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb->prefix; analytics queries.
				// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/column names from internal literal map; values are prepared.
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				$deleted = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM " . $table . " WHERE " . $date_column . " < %s LIMIT %d",
						$cutoff,
						$batch_size
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
				// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter

				if ( $deleted > 0 ) {
					$table_deleted += $deleted;
					$total_deleted += $deleted;

					// Yield to other processes between batches
					if ( $deleted >= $batch_size ) {
						usleep( 100000 ); // 100ms pause
					}
				}
			} while ( $deleted >= $batch_size );

			if ( $table_deleted > 0 ) {
				$rows_by_table[ $table_suffix ] = $table_deleted;
				$debug_manager->log(
					"Deleted " . $table_deleted . " old records from " . $table_suffix,
					'debug',
					'database'
				);
			}
		}

		// Don't delete daily_stats - keep for historical reports
		// Daily stats are pre-aggregated and small in size

		// Merge cascade totals into the overall report.
		if ( is_array( $cascade ) ) {
			$total_deleted += $cascade['rows_deleted'];
			foreach ( $cascade['rows_by_table'] as $table_suffix => $count ) {
				if ( $count > 0 ) {
					$rows_by_table[ $table_suffix ] = ( isset( $rows_by_table[ $table_suffix ] ) ? $rows_by_table[ $table_suffix ] : 0 ) + $count;
				}
			}
		}

		$debug_manager->log(
			"Data cleanup completed. Total records deleted: " . $total_deleted,
			'info',
			'database'
		);

		// Record the run in the Danger Zone cleanup history so retention
		// deletions are never invisible to the admin again.
		$this->log_retention_run( $period_label, $cutoff, $cascade, $rows_by_table );

		// Optimize tables if significant data was deleted
		if ( $total_deleted > 50000 ) {
			$this->optimize_tables_after_cleanup();
		}

		return $total_deleted;
	}

	/**
	 * Delete sessions older than the retention cutoff via the Smart Cleanup
	 * cascade so files and DB rows stay in sync.
	 *
	 * Routes every expired session through
	 * {@see Opti_Behavior_Smart_Cleanup_Service::cascade_delete_sessions()},
	 * which removes recording files, heatmap interaction JSON files, orphaned
	 * page hash directories and all session-child rows, then flushes the
	 * heatmap caches.
	 *
	 * @since 2026-08-15 (retention file/DB sync)
	 * @param string $cutoff MySQL datetime; sessions with start_time older than this are deleted.
	 * @return array|null Aggregate stats, or null when the service is unavailable.
	 */
	private function cascade_delete_expired_sessions( $cutoff ) {
		global $wpdb;

		if ( ! class_exists( 'Opti_Behavior_Smart_Cleanup_Service' ) ) {
			return null;
		}

		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Checking table existence.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions_table ) ) ) {
			return null;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$service    = new Opti_Behavior_Smart_Cleanup_Service();
		$batch_size = Opti_Behavior_Smart_Cleanup_Service::DEFAULT_BATCH_SIZE;
		$stats      = array(
			'sessions_deleted'          => 0,
			'files_deleted'             => 0,
			'heatmap_files_deleted'     => 0,
			'orphaned_visitors_deleted' => 0,
			'rows_deleted'              => 0,
			'rows_by_table'             => array(),
		);

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table from $wpdb->prefix; values prepared.
			$session_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM ' . $sessions_table . ' WHERE start_time < %s LIMIT %d',
					$cutoff,
					$batch_size
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( empty( $session_ids ) ) {
				break;
			}

			$deleted = $service->cascade_delete_sessions( $session_ids );
			$result  = $service->get_last_cascade_result();

			$stats['sessions_deleted']      += $deleted;
			$stats['files_deleted']         += isset( $result['files_deleted'] ) ? absint( $result['files_deleted'] ) : 0;
			$stats['heatmap_files_deleted'] += isset( $result['heatmap_files_deleted'] ) ? absint( $result['heatmap_files_deleted'] ) : 0;

			if ( isset( $result['rows_deleted_by_table'] ) && is_array( $result['rows_deleted_by_table'] ) ) {
				foreach ( $result['rows_deleted_by_table'] as $table_suffix => $count ) {
					$count = absint( $count );
					$stats['rows_deleted']                  += $count;
					$stats['rows_by_table'][ $table_suffix ] = ( isset( $stats['rows_by_table'][ $table_suffix ] ) ? $stats['rows_by_table'][ $table_suffix ] : 0 ) + $count;
				}
			}

			// Safety: if nothing was deleted despite matches, bail to avoid
			// spinning on undeletable rows.
			if ( $deleted < 1 ) {
				break;
			}

			if ( count( $session_ids ) >= $batch_size ) {
				usleep( 100000 ); // 100ms pause between batches.
			}
		} while ( count( $session_ids ) >= $batch_size );

		if ( $stats['sessions_deleted'] > 0 ) {
			$stats['orphaned_visitors_deleted'] = $service->delete_orphaned_visitors();
		}

		return $stats;
	}

	/**
	 * Write a Danger Zone cleanup-history entry for a retention run.
	 *
	 * The legacy retention cron used to delete silently (debug log only);
	 * every run is now visible in the cleanup history alongside manual and
	 * scheduled Smart Cleanup runs.
	 *
	 * @since 2026-08-15 (retention file/DB sync)
	 * @param string     $period_label  Configured retention window label (e.g. "365 day(s)").
	 * @param string     $cutoff        MySQL datetime cutoff used.
	 * @param array|null $cascade       Aggregate stats from {@see cascade_delete_expired_sessions()}.
	 * @param array      $rows_by_table Rows deleted per table (cascade + sweep merged).
	 */
	private function log_retention_run( $period_label, $cutoff, $cascade, $rows_by_table ) {
		if ( ! class_exists( 'Opti_Behavior_Smart_Cleanup_Service' ) ) {
			return;
		}

		$sessions_deleted = 0;
		$files_deleted    = 0;
		$orphans          = 0;
		$warnings         = array();

		if ( is_array( $cascade ) ) {
			$sessions_deleted = $cascade['sessions_deleted'];
			$files_deleted    = $cascade['files_deleted'] + $cascade['heatmap_files_deleted'];
			$orphans          = $cascade['orphaned_visitors_deleted'];
		} else {
			$warnings[] = 'Smart Cleanup service unavailable: rows were deleted without file sync.';
			$sessions_deleted = isset( $rows_by_table['optibehavior_sessions'] ) ? absint( $rows_by_table['optibehavior_sessions'] ) : 0;
		}

		$events_deleted = isset( $rows_by_table['optibehavior_events'] ) ? absint( $rows_by_table['optibehavior_events'] ) : 0;

		// A note, not a warning: the purge ran as configured. The sentence says
		// "deleted" only when something was deleted.
		$rows_total = is_array( $rows_by_table ) ? array_sum( array_map( 'absint', $rows_by_table ) ) : 0;
		$note       = ( $sessions_deleted > 0 || $rows_total > 0 || $files_deleted > 0 )
			/* translators: 1: cutoff date, 2: retention period (e.g. "365 day(s)") */
			? sprintf( __( 'Retention (%2$s): data older than %1$s deleted.', 'opti-behavior' ), $cutoff, $period_label )
			/* translators: 1: cutoff date, 2: retention period (e.g. "365 day(s)") */
			: sprintf( __( 'Retention (%2$s): no data older than %1$s to delete.', 'opti-behavior' ), $cutoff, $period_label );

		$service = new Opti_Behavior_Smart_Cleanup_Service();
		$service->add_cleanup_log(
			'retention',
			$sessions_deleted,
			$events_deleted,
			$files_deleted,
			array(
				'rows_by_table'             => $rows_by_table,
				'files_deleted'             => $files_deleted,
				'orphaned_visitors_deleted' => $orphans,
				'status'                    => 'completed',
				'warnings'                  => $warnings,
				'notes'                     => array( $note ),
			)
		);
	}

	/**
	 * Optimize tables after bulk deletion to reclaim disk space
	 *
	 * Only optimizes tables with significant fragmentation (>10MB free space).
	 */
	private function optimize_tables_after_cleanup() {
		global $wpdb;

		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'Checking tables for optimization after cleanup', 'debug', 'database' );

		$tables_to_check = array(
			$wpdb->prefix . 'optibehavior_events',
			$wpdb->prefix . 'optibehavior_sessions',
			$wpdb->prefix . 'optibehavior_pageviews',
		);

		foreach ( $tables_to_check as $table ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time check during maintenance
			$status = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT Data_free FROM information_schema.tables
					WHERE table_schema = %s AND table_name = %s",
					DB_NAME,
					$table
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			// Only optimize if >10MB fragmented space
			if ( $status && $status->Data_free > 10 * 1024 * 1024 ) {
				$debug_manager->log( "Optimizing table " . $table . " (fragmented: " . round( $status->Data_free / 1024 / 1024, 2 ) . 'MB)', 'info', 'database' );
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table optimization required for maintenance
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "OPTIMIZE TABLE " . $table );
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
		}
	}

	/**
	 * Clean up orphaned pages (rows with no surviving heatmap event).
	 *
	 * Guardrail policy (2026-08-15, Option B): a page row whose url_hash
	 * directory STILL holds heatmap JSON files is KEPT even when its events are
	 * gone — the historical retention bug purged events/sessions while the
	 * files survived, and the old unconditional `DELETE ... LEFT JOIN` then
	 * erased the page's URL (its only remaining DB copy), making the on-disk
	 * data unrecoverable by the registry backfill.
	 *
	 * Bounded cost on installs with millions of JSON files:
	 *  - candidates fetched in id-ordered batches (indexed join, LIMIT), total
	 *    capped per run ({@see 'opti_behavior_cleanup_pages_max_per_run'});
	 *  - registry hashes resolved with ONE batched query per batch;
	 *  - the file check is {@see Opti_Behavior_Heatmap_Storage::hash_dir_has_heatmap_files()}
	 *    — readdir stopping at the first `.json`, O(few) per hash, no globs of
	 *    the uploads tree.
	 *
	 * @since 2026-08-15 Keeps rows whose hash dirs still hold JSON files; batched.
	 * @return array{deleted:int,kept:int} Deletion/keep counters (used by tests).
	 */
	public function cleanup_pages() {
		global $wpdb;

		$result = array(
			'deleted' => 0,
			'kept'    => 0,
		);

		$pages_table    = $wpdb->prefix . 'optibehavior_pages';
		$events_table   = $wpdb->prefix . 'optibehavior_events';
		$registry_table = $wpdb->prefix . 'optibehavior_heatmap_pages';

		$storage = class_exists( 'Opti_Behavior_Heatmap_Storage' ) ? Opti_Behavior_Heatmap_Storage::get_instance() : null;

		if ( ! $storage ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// No storage layer available — legacy behavior (cannot check files).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$deleted           = $wpdb->query(
				"DELETE p FROM {$pages_table} p
				LEFT JOIN {$events_table} e ON p.id = e.page_id2
				WHERE e.page_id2 IS NULL"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$result['deleted'] = false !== $deleted ? absint( $deleted ) : 0;
			// Page ids are unknown on this legacy path — sweep the per-day heatmap
			// index for rows whose page no longer exists (F1, 2026-08-15).
			$this->purge_orphaned_heatmap_daily_rows();
			return $result;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence guard; daily cron only.
		$registry_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $registry_table ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		/**
		 * Cap the number of orphan-candidate page rows examined per daily run so
		 * the cron tick stays bounded regardless of table size.
		 *
		 * @since 2026-08-15
		 * @param int $max_per_run Maximum candidate rows per run.
		 */
		$max_per_run = max( 100, (int) apply_filters( 'opti_behavior_cleanup_pages_max_per_run', 5000 ) );
		$batch_size  = 500;
		$cursor      = 0;
		$examined    = 0;

		while ( $examined < $max_per_run ) {
			$limit = min( $batch_size, $max_per_run - $examined );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin tables from $wpdb->prefix; values bound via prepare().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily maintenance scan.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.id, p.url FROM {$pages_table} p
					LEFT JOIN {$events_table} e ON p.id = e.page_id2
					WHERE e.page_id2 IS NULL AND p.id > %d
					ORDER BY p.id ASC
					LIMIT %d",
					$cursor,
					$limit
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( empty( $rows ) ) {
				break;
			}

			$examined += count( $rows );

			// Batch-resolve registry url_hash rows for the whole candidate set.
			$hash_map = array();
			if ( $registry_exists ) {
				$ids          = array_map( static function ( $r ) { return (int) $r->id; }, $rows );
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; ids bound via %d placeholders.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily maintenance scan; page_id is indexed.
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				$mapping_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT page_id, url_hash FROM {$registry_table} WHERE page_id IN ({$placeholders})",
						...$ids
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
				foreach ( (array) $mapping_rows as $mrow ) {
					$hash_map[ (int) $mrow->page_id ][] = (string) $mrow->url_hash;
				}
			}

			$delete_ids = array();
			foreach ( $rows as $row ) {
				$page_id = (int) $row->id;
				$cursor  = max( $cursor, $page_id );

				// Candidate hashes: every registry row + the write-path canonical
				// hash + the 3 legacy md5 URL variants (bounded, tiny set).
				$hashes = isset( $hash_map[ $page_id ] ) ? $hash_map[ $page_id ] : array();
				$url    = (string) $row->url;
				if ( '' !== $url ) {
					$hashes[] = $storage->get_url_hash( $url );
					$hashes[] = md5( $url );
					$hashes[] = md5( rtrim( $url, '/' ) );
					$hashes[] = md5( $url . '/' );
				}

				$has_files = false;
				foreach ( array_unique( $hashes ) as $hash ) {
					if ( $storage->hash_dir_has_heatmap_files( $hash ) ) {
						$has_files = true;
						break;
					}
				}

				if ( $has_files ) {
					// Files survive on disk — keep the row so the URL (and the
					// registry backfill's ability to resolve it) is preserved.
					++$result['kept'];
					continue;
				}

				$delete_ids[] = $page_id;
			}

			if ( ! empty( $delete_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $delete_ids ), '%d' ) );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; ids bound via %d placeholders.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily maintenance delete.
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
				$deleted = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$pages_table} WHERE id IN ({$placeholders})",
						...$delete_ids
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$result['deleted'] += false !== $deleted ? absint( $deleted ) : 0;

				// Cascade: drop the deleted pages' per-day heatmap index rows so
				// dated Heatmaps views stop reporting them (F1, 2026-08-15).
				$this->delete_heatmap_daily_rows_for_pages( $delete_ids );
			}

			if ( count( $rows ) < $limit ) {
				break; // Candidate set drained.
			}
		}

		// Backstop: purge per-day index rows whose page no longer exists at all
		// (covers rows orphaned before this cascade shipped, or by external
		// deletions that bypassed delete_data()/cleanup_pages()).
		$this->purge_orphaned_heatmap_daily_rows();

		return $result;
	}

	/**
	 * Delete `optibehavior_heatmap_daily` rows belonging to the given pages.
	 *
	 * Cascade half of the F1 fix (2026-08-15): the per-day heatmap click index
	 * is an AGGREGATE kept indefinitely (never age-purged by retention), but its
	 * rows must die together with their parent page entry — the Heatmaps dated
	 * views SUM this table directly, so surviving rows would misrepresent pages
	 * whose events/registry rows were just removed.
	 *
	 * @since 2026-08-15
	 * @param array $page_ids Parent page ids (optibehavior_pages.id).
	 * @return int Number of daily index rows deleted.
	 */
	public function delete_heatmap_daily_rows_for_pages( $page_ids ) {
		global $wpdb;

		$page_ids = array_values( array_filter( array_map( 'absint', (array) $page_ids ) ) );
		if ( empty( $page_ids ) ) {
			return 0;
		}

		$daily_table = $wpdb->prefix . 'optibehavior_heatmap_daily';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence guard (table added mid-1.9.x).
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $daily_table ) ) ) {
			return 0;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table from $wpdb->prefix; ids bound via %d placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$daily_table} WHERE page_id IN ({$placeholders})",
				...$page_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return false !== $deleted ? absint( $deleted ) : 0;
	}

	/**
	 * Purge `optibehavior_heatmap_daily` rows whose page no longer exists.
	 *
	 * Orphan backstop half of the F1 fix (2026-08-15). A daily index row is an
	 * orphan only when its page_id is absent from BOTH `optibehavior_pages` and
	 * the `optibehavior_heatmap_pages` registry — a registry-only page can still
	 * own live on-disk JSON files (Option B guardrail), and its daily rows must
	 * survive so the dated views keep working until the files themselves go.
	 *
	 * Bounded: orphan page_ids are resolved in batches of 500 and the run is
	 * capped via the {@see 'opti_behavior_heatmap_daily_purge_max_per_run'}
	 * filter, so the daily cron tick stays cheap on huge tables.
	 *
	 * @since 2026-08-15
	 * @param int $max_per_run Optional cap override; 0 = use the filter default.
	 * @return int Number of daily index rows deleted.
	 */
	public function purge_orphaned_heatmap_daily_rows( $max_per_run = 0 ) {
		global $wpdb;

		$daily_table    = $wpdb->prefix . 'optibehavior_heatmap_daily';
		$pages_table    = $wpdb->prefix . 'optibehavior_pages';
		$registry_table = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence guard (table added mid-1.9.x).
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $daily_table ) ) ) {
			return 0;
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence guard.
		$registry_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $registry_table ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $max_per_run <= 0 ) {
			/**
			 * Cap the number of orphaned page_ids purged from the per-day heatmap
			 * index per run so the daily cron tick stays bounded.
			 *
			 * @since 2026-08-15
			 * @param int $max_per_run Maximum orphan page_ids per run.
			 */
			$max_per_run = (int) apply_filters( 'opti_behavior_heatmap_daily_purge_max_per_run', 5000 );
		}
		$max_per_run = max( 100, $max_per_run );

		$batch_size = 500;
		$examined   = 0;
		$deleted    = 0;

		while ( $examined < $max_per_run ) {
			$limit = min( $batch_size, $max_per_run - $examined );

			$registry_join = $registry_exists
				? "LEFT JOIN {$registry_table} r ON hd.page_id = r.page_id"
				: '';
			$registry_cond = $registry_exists ? 'AND r.page_id IS NULL' : '';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin tables from $wpdb->prefix; limit bound via prepare().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily maintenance scan; page_id indexed on every joined table.
			$orphan_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT hd.page_id FROM {$daily_table} hd
					LEFT JOIN {$pages_table} p ON hd.page_id = p.id
					{$registry_join}
					WHERE p.id IS NULL {$registry_cond}
					LIMIT %d",
					$limit
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( empty( $orphan_ids ) ) {
				break;
			}

			$examined += count( $orphan_ids );
			$deleted  += $this->delete_heatmap_daily_rows_for_pages( $orphan_ids );

			if ( count( $orphan_ids ) < $limit ) {
				break; // Orphan set drained.
			}
		}

		return $deleted;
	}

	/**
	 * Delete all heatmap data
	 */
	public function delete_all() {
		global $wpdb;

		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_events" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_pages" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_sessions" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_visitors" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_pageviews" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_recordings" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_referrers" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_outbound_clicks" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_errors" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_error_types" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_friction" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_performance" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_broken_links" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_insights" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "opti_behavior_funnels" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "opti_behavior_funnel_tracking" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_journey_groups" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_report_logs" );
		$wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . "optibehavior_visitor_daily_stats" );
	}

	/**
	 * Delete specific page data
	 *
	 * @param array $page_ids Array of page IDs to delete.
	 */
	public function delete_data( $page_ids ) {
		global $wpdb;

		if ( empty( $page_ids ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM " . $wpdb->prefix . "optibehavior_events WHERE page_id2 IN (" . implode( ',', array_fill( 0, count( $page_ids ), '%d' ) ) . ")",
			...$page_ids
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM " . $wpdb->prefix . "optibehavior_pages WHERE id IN (" . implode( ',', array_fill( 0, count( $page_ids ), '%d' ) ) . ")",
			...$page_ids
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Also drop the heatmap allowlist/aggregate rows: the Available Heatmaps
		// fast path serves url + counters + agg_* straight from this table, so a
		// leftover row would keep the deleted page in the list with stale numbers.
		$heatmap_pages_table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $heatmap_pages_table ) ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; IDs bound via %d placeholders.
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$heatmap_pages_table} WHERE page_id IN (" . implode( ',', array_fill( 0, count( $page_ids ), '%d' ) ) . ")",
				...$page_ids
			) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// Cascade to the per-day heatmap index (F1, 2026-08-15): dated list/stats
		// views SUM straight over optibehavior_heatmap_daily, so leftover rows
		// would keep reporting clicks/sessions for a page whose events and
		// registry entry were just deleted.
		$this->delete_heatmap_daily_rows_for_pages( $page_ids );

		// Deleting heatmap rows changes every aggregate — invalidate cached counts.
		if ( function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
			opti_behavior_flush_heatmap_caches();
		}
	}

	/**
	 * Validate options
	 *
	 * @param array $new_options New options.
	 * @param array $old_options Old options.
	 * @return array
	 */
}
