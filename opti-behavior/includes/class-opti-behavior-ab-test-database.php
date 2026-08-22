<?php
/**
 * A/B Test Database Class
 *
 * Handles database schema creation, CRUD operations, and queries
 * for the A/B testing feature.
 *
 * @package opti-behavior
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- All table names are constructed from $wpdb->prefix and hardcoded identifiers; no user input is involved.
class Opti_Behavior_AB_Test_Database {

	// -------------------------------------------------------------------------
	// Schema version
	// -------------------------------------------------------------------------

	/** Current DB schema version — bump when making breaking schema changes. */
	const DB_VERSION = '1.2.1';

	/** WP option that stores the installed schema version. */
	const DB_VERSION_OPTION = 'opti_behavior_ab_db_version';

	// -------------------------------------------------------------------------
	// Test status constants
	// -------------------------------------------------------------------------

	const STATUS_DRAFT     = 'draft';
	const STATUS_RUNNING   = 'running';
	const STATUS_PAUSED    = 'paused';
	const STATUS_COMPLETED = 'completed';
	const STATUS_APPLIED   = 'applied';
	const STATUS_ARCHIVED  = 'archived';

	// -------------------------------------------------------------------------
	// Test type constants
	// -------------------------------------------------------------------------

	const TYPE_PAGE_SPLIT  = 'page_split';
	const TYPE_HEADLINE    = 'headline';
	const TYPE_SHORTCODE   = 'shortcode';
	const TYPE_ELEMENT     = 'element';
	const TYPE_WOOCOMMERCE = 'woocommerce';
	const TYPE_CSS         = 'css';
	const TYPE_MENU        = 'menu';
	const TYPE_MVT         = 'mvt';
	const TYPE_FUNNEL      = 'funnel';

	// -------------------------------------------------------------------------
	// Goal type constants
	// -------------------------------------------------------------------------

	const GOAL_PAGE_VISIT      = 'page_visit';
	const GOAL_CLICK           = 'click';
	const GOAL_FORM_SUBMIT     = 'form_submit';
	const GOAL_WOO_ADD_TO_CART = 'woo_add_to_cart';
	const GOAL_WOO_PURCHASE    = 'woo_purchase';
	const GOAL_CUSTOM_EVENT    = 'custom_event';
	const GOAL_SCROLL_DEPTH    = 'scroll_depth';
	const GOAL_TIME_ON_PAGE    = 'time_on_page';
	const GOAL_REVENUE         = 'revenue';
	const GOAL_BOUNCE_RATE     = 'bounce_rate';

	// -------------------------------------------------------------------------
	// Statistical engine constants
	// -------------------------------------------------------------------------

	const ENGINE_FREQUENTIST = 'frequentist';
	const ENGINE_BAYESIAN    = 'bayesian';

	// -------------------------------------------------------------------------
	// Optimization mode constants
	// -------------------------------------------------------------------------

	const OPT_MANUAL      = 'manual';
	const OPT_AUTO_WINNER = 'auto_winner';
	const OPT_BANDIT      = 'bandit';

	// -------------------------------------------------------------------------
	// Active-tests transient
	// -------------------------------------------------------------------------

	/** Transient key for running-test cache. */
	const TRANSIENT_ACTIVE_TESTS = 'opti_behavior_ab_active_tests_all';

	/** Transient TTL in seconds (5 minutes). */
	const TRANSIENT_TTL = 300;

	// -------------------------------------------------------------------------
	// Free plan limits
	// Stored as a single WP option (array) so admins can override via Settings.
	// -------------------------------------------------------------------------

	/**
	 * Default free plan limits (configurable via WordPress options).
	 *
	 * @var array
	 */
	const DEFAULT_FREE_LIMITS = array(
		'max_concurrent_tests'  => 3,
		'max_variants_per_test' => 3,
		'max_goals_per_test'    => 3,
	);

	/**
	 * Get the configurable free plan limits.
	 *
	 * Limits are stored as a WordPress option so administrators can adjust them.
	 *
	 * @since 1.3.0
	 * @return array Associative array of limit keys and their current values.
	 */
	public static function get_free_limits() {
		$limits = get_option( 'opti_behavior_ab_free_limits', self::DEFAULT_FREE_LIMITS );
		return wp_parse_args( $limits, self::DEFAULT_FREE_LIMITS );
	}

	/**
	 * Update the free plan limits.
	 *
	 * @since 1.3.0
	 * @param array $new_limits Associative array of limit keys to update.
	 * @return bool True on success, false on failure.
	 */
	public static function update_free_limits( $new_limits ) {
		$current = self::get_free_limits();
		$merged  = wp_parse_args( $new_limits, $current );

		// Validate: all values must be positive integers.
		foreach ( $merged as $key => $value ) {
			$merged[ $key ] = max( 1, absint( $value ) );
		}

		return update_option( 'opti_behavior_ab_free_limits', $merged );
	}

	/**
	 * Create all A/B testing database tables.
	 *
	 * Called during plugin activation / upgrade via Opti_Behavior_Heatmap_Database::setup().
	 * Uses dbDelta for safe schema migrations — never destroys existing data.
	 *
	 * @since 1.3.0
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// Snapshot installed version BEFORE dbDelta so pre-schema migrations
		// (e.g. de-duping rows before a UNIQUE KEY is added) know whether to run.
		$installed_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		// Bug #3 — Impressions UNIQUE (test_id, visitor_id) requires that any
		// existing duplicate rows are collapsed BEFORE dbDelta attempts to add
		// the UNIQUE KEY, otherwise MySQL rejects the index creation.
		if ( version_compare( $installed_version, '1.1.0', '<' ) ) {
			self::migrate_dedupe_ab_impressions();
		}

		// Bug #5 — Conversions UNIQUE (test_id, goal_id, visitor_id) added in
		// DB_VERSION 1.2.1. Same rationale as Bug #3: collapse pre-existing
		// duplicates before dbDelta tries to create the UNIQUE KEY, otherwise
		// MySQL rejects index creation on tables with conflicting rows.
		if ( version_compare( $installed_version, '1.2.1', '<' ) ) {
			self::migrate_dedupe_ab_conversions();
		}

		self::create_tests_table( $charset_collate );
		self::create_variants_table( $charset_collate );
		self::create_goals_table( $charset_collate );
		self::create_impressions_table( $charset_collate );
		self::create_conversions_table( $charset_collate );
		self::create_daily_stats_table( $charset_collate );
		self::create_decision_log_table( $charset_collate );

		// Bug #4 — Back-fill device_type / browser / country on rows inserted
		// before the Bug #2 fix (which added UA parsing at insert time). Gated
		// by its own option so it only runs once per install, independent of
		// DB_VERSION changes.
		self::maybe_backfill_ab_impression_metadata();

		// Record installed schema version for future migrations.
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * One-shot migration: collapse duplicate impression rows before the
	 * UNIQUE KEY (test_id, visitor_id) is added in DB_VERSION 1.1.0.
	 *
	 * Keeps the earliest-id row for each (test_id, visitor_id) pair and
	 * deletes the rest. Safe to re-run — a no-op once duplicates are gone.
	 *
	 * @since 1.3.1
	 */
	/**
	 * Bug #4 — Back-fill device_type / browser / country on historical
	 * ab_impressions rows that were inserted before the Bug #2 fix, which
	 * first populated these columns at insert time.
	 *
	 * Strategy (idempotent, batched):
	 *   1. For rows whose visitor_id matches wp_optibehavior_visitors.id and
	 *      that visitor has already-resolved device_type / browser / country,
	 *      copy those values over.
	 *   2. For the remainder (simulated / anonymised visitors, or visitors
	 *      whose entry in the visitors table is also empty), mark
	 *      device_type = 'unknown' and browser = 'Unknown'. `country` stays
	 *      NULL because there is no source of truth for it.
	 *
	 * Gated by a dedicated option separate from DB_VERSION, so it runs
	 * exactly once per install and can be re-triggered in the future by
	 * deleting the option if the backfill algorithm is ever improved.
	 *
	 * @since 1.3.1
	 */
	public static function maybe_backfill_ab_impression_metadata() {
		$flag_option = 'opti_behavior_ab_metadata_backfill';
		$current     = get_option( $flag_option, '' );

		// Skip if already run at the current implementation version.
		if ( 'v1' === $current ) {
			return;
		}

		self::backfill_ab_impression_metadata();

		update_option( $flag_option, 'v1', false );
	}

	/**
	 * Core back-fill implementation. Exposed separately from the gated
	 * wrapper so tests can call it directly and assert row counts without
	 * having to fight the one-shot flag.
	 *
	 * Returns an array summarising how many rows were updated by each
	 * strategy: [ 'joined' => int, 'unknown' => int ].
	 *
	 * @since 1.3.1
	 * @param int $batch_size Rows per UPDATE batch. Default 500.
	 * @return array{joined:int, unknown:int}
	 */
	public static function backfill_ab_impression_metadata( $batch_size = 500 ) {
		global $wpdb;

		$imp_table = $wpdb->prefix . 'optibehavior_ab_impressions';
		$vis_table = $wpdb->prefix . 'optibehavior_visitors';
		$summary   = array( 'joined' => 0, 'unknown' => 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$imp_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $imp_table ) );
		if ( ! $imp_exists ) {
			return $summary;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$vis_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $vis_table ) );

		$batch_size = max( 50, absint( $batch_size ) );

		// -------------------------------------------------------------------
		// Strategy 1: JOIN with visitors table when available.
		// -------------------------------------------------------------------
		if ( $vis_exists ) {
			// Single atomic UPDATE — MySQL handles it in one statement, so no
			// explicit batching is required for the JOIN-able subset (which
			// is usually small).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$affected = $wpdb->query(
				"UPDATE {$imp_table} i
				 INNER JOIN {$vis_table} v ON v.id = i.visitor_id
				 SET
					i.device_type = COALESCE( NULLIF(i.device_type, ''), v.device_type ),
					i.browser     = COALESCE( NULLIF(i.browser, ''),     v.browser ),
					i.country     = COALESCE( NULLIF(i.country, ''),     v.country )
				 WHERE
					(i.device_type IS NULL OR i.device_type = '' OR
					 i.browser     IS NULL OR i.browser     = '' OR
					 i.country     IS NULL OR i.country     = '')
				 AND (
					(v.device_type IS NOT NULL AND v.device_type <> '')
					OR (v.browser IS NOT NULL AND v.browser <> '')
					OR (v.country IS NOT NULL AND v.country <> '')
				 )"
			);
			$summary['joined'] = max( 0, (int) $affected );
		}

		// -------------------------------------------------------------------
		// Strategy 2: mark remaining empty rows as 'unknown' / 'Unknown'.
		// Done in batches of $batch_size to avoid long locks on huge tables.
		// -------------------------------------------------------------------
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$imp_table}
					 SET device_type = 'unknown', browser = 'Unknown'
					 WHERE (device_type IS NULL OR device_type = '')
					   AND (browser     IS NULL OR browser     = '')
					 LIMIT %d",
					$batch_size
				)
			);

			$affected = (int) $affected;
			$summary['unknown'] += max( 0, $affected );

			// Stop when no more rows match the WHERE.
		} while ( $affected >= $batch_size );

		return $summary;
	}

	private static function migrate_dedupe_ab_impressions() {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_impressions';

		// Skip silently if the table doesn't exist yet (fresh install path).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return;
		}

		// Delete every row whose id is greater than the MIN(id) for its
		// (test_id, visitor_id) group. Self-join is required because MySQL
		// disallows DELETE with a subquery over the same table on some
		// versions — the self-join form is portable.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"DELETE dup FROM {$table} AS dup
			 INNER JOIN (
				SELECT test_id, visitor_id, MIN(id) AS keeper_id
				FROM {$table}
				GROUP BY test_id, visitor_id
				HAVING COUNT(*) > 1
			 ) AS keep
			 ON dup.test_id    = keep.test_id
			 AND dup.visitor_id = keep.visitor_id
			 AND dup.id         > keep.keeper_id"
		);
	}

	/**
	 * One-shot migration: collapse duplicate conversion rows before the
	 * UNIQUE KEY (test_id, goal_id, visitor_id) is added in DB_VERSION 1.2.1.
	 *
	 * Mirror of migrate_dedupe_ab_impressions(): keep the lowest id for each
	 * (test_id, goal_id, visitor_id) triple, drop the rest. Safe to re-run.
	 *
	 * @since 1.3.2
	 */
	private static function migrate_dedupe_ab_conversions() {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_conversions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"DELETE dup FROM {$table} AS dup
			 INNER JOIN (
				SELECT test_id, goal_id, visitor_id, MIN(id) AS keeper_id
				FROM {$table}
				GROUP BY test_id, goal_id, visitor_id
				HAVING COUNT(*) > 1
			 ) AS keep
			 ON dup.test_id    = keep.test_id
			 AND dup.goal_id    = keep.goal_id
			 AND dup.visitor_id = keep.visitor_id
			 AND dup.id         > keep.keeper_id"
		);
	}

	/**
	 * Set default A/B testing WordPress options on first activation.
	 *
	 * Uses add_option() so existing (user-configured) values are never
	 * overwritten by a plugin update or re-activation.
	 *
	 * @since 1.3.0
	 */
	public static function maybe_set_default_options() {
		// Free plan limits array — single option, editable as a group.
		add_option( 'opti_behavior_ab_free_limits', self::DEFAULT_FREE_LIMITS, '', 'no' );

		// Global A/B settings.
		add_option(
			'opti_behavior_ab_settings',
			array(
				'default_stat_engine' => self::ENGINE_FREQUENTIST,
				'default_confidence'  => 0.95,
				'data_retention_days' => 365,
			),
			'',
			'no'
		);
	}

	/**
	 * Create the ab_tests table.
	 *
	 * Stores test definitions, configuration, and lifecycle state.
	 *
	 * @since 1.3.0
	 * @param string $charset_collate Database charset collation.
	 */
	private static function create_tests_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_ab_tests (
			id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name            varchar(255)        NOT NULL,
			description     text                DEFAULT NULL,
			test_type       varchar(50)         NOT NULL DEFAULT 'page_split',
			status          varchar(20)         NOT NULL DEFAULT 'draft',
			stat_engine     varchar(20)         NOT NULL DEFAULT 'frequentist',
			optimization    varchar(20)         NOT NULL DEFAULT 'manual',
			confidence_level decimal(5,4)       NOT NULL DEFAULT 0.9500,
			min_sample_size  int(11)            NOT NULL DEFAULT 100,
			min_duration_hours int(11)          NOT NULL DEFAULT 24,
			traffic_percent  tinyint(3) UNSIGNED NOT NULL DEFAULT 100,
			target_url      varchar(500)        DEFAULT NULL,
			target_post_id  bigint(20) UNSIGNED DEFAULT NULL,
			target_selector varchar(500)        DEFAULT NULL,
			settings        longtext            DEFAULT NULL,
			created_by      bigint(20) UNSIGNED NOT NULL,
			started_at      datetime            DEFAULT NULL,
			ended_at        datetime            DEFAULT NULL,
			winner_variant_id bigint(20) UNSIGNED DEFAULT NULL,
			applied_at      datetime            DEFAULT NULL,
			applied_by      bigint(20) UNSIGNED DEFAULT NULL,
			created_at      datetime            NOT NULL,
			updated_at      datetime            NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY test_type (test_type),
			KEY target_post_id (target_post_id),
			KEY status_type (status, test_type),
			KEY started_at (started_at),
			KEY created_at (created_at)
			) " . $charset_collate
		);
	}

	/**
	 * Create the ab_variants table.
	 *
	 * Stores variant definitions for each test.
	 *
	 * @since 1.3.0
	 * @param string $charset_collate Database charset collation.
	 */
	private static function create_variants_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_ab_variants (
			id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			test_id         bigint(20) UNSIGNED NOT NULL,
			name            varchar(255)        NOT NULL,
			is_control      tinyint(1)          NOT NULL DEFAULT 0,
			traffic_weight  tinyint(3) UNSIGNED NOT NULL DEFAULT 50,
			variant_data    longtext            NOT NULL,
			sort_order      tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
			created_at      datetime            NOT NULL,
			PRIMARY KEY  (id),
			KEY test_id (test_id),
			KEY test_control (test_id, is_control)
			) " . $charset_collate
		);
	}

	/**
	 * Create the ab_goals table.
	 *
	 * Stores conversion goal definitions for each test.
	 *
	 * @since 1.3.0
	 * @param string $charset_collate Database charset collation.
	 */
	private static function create_goals_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_ab_goals (
			id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			test_id         bigint(20) UNSIGNED NOT NULL,
			name            varchar(255)        NOT NULL,
			goal_type       varchar(50)         NOT NULL DEFAULT 'page_visit',
			goal_config     text                NOT NULL,
			is_primary      tinyint(1)          NOT NULL DEFAULT 0,
			created_at      datetime            NOT NULL,
			PRIMARY KEY  (id),
			KEY test_id (test_id)
			) " . $charset_collate
		);
	}

	/**
	 * Create the ab_impressions table.
	 *
	 * Records each time a visitor is served a variant (raw event data).
	 *
	 * @since 1.3.0
	 * @param string $charset_collate Database charset collation.
	 */
	private static function create_impressions_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_ab_impressions (
			id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			test_id         bigint(20) UNSIGNED NOT NULL,
			variant_id      bigint(20) UNSIGNED NOT NULL,
			visitor_id      varchar(64)         NOT NULL,
			session_id      varchar(64)         DEFAULT NULL,
			ip_hash         varchar(64)         DEFAULT NULL,
			device_type     varchar(20)         DEFAULT NULL,
			browser         varchar(50)         DEFAULT NULL,
			country         varchar(2)          DEFAULT NULL,
			referrer_domain varchar(255)        DEFAULT NULL,
			utm_source      varchar(100)        DEFAULT NULL,
			utm_medium      varchar(100)        DEFAULT NULL,
			utm_campaign    varchar(100)        DEFAULT NULL,
			created_at      datetime            NOT NULL,
			PRIMARY KEY  (id),
			KEY test_variant (test_id, variant_id),
			UNIQUE KEY visitor_test_unique (test_id, visitor_id),
			KEY created_at (created_at)
			) " . $charset_collate
		);
	}

	/**
	 * Create the ab_conversions table.
	 *
	 * Records each conversion event (goal achieved by a visitor).
	 *
	 * @since 1.3.0
	 * @param string $charset_collate Database charset collation.
	 */
	private static function create_conversions_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_ab_conversions (
			id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			test_id         bigint(20) UNSIGNED NOT NULL,
			variant_id      bigint(20) UNSIGNED NOT NULL,
			goal_id         bigint(20) UNSIGNED NOT NULL,
			visitor_id      varchar(64)         NOT NULL,
			session_id      varchar(64)         DEFAULT NULL,
			revenue         decimal(12,2)       DEFAULT NULL,
			metadata        text                DEFAULT NULL,
			created_at      datetime            NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY conv_unique (test_id, goal_id, visitor_id),
			KEY test_variant_goal (test_id, variant_id, goal_id),
			KEY visitor_test (visitor_id, test_id),
			KEY created_at (created_at)
			) " . $charset_collate
		);
	}

	/**
	 * Create the ab_daily_stats table.
	 *
	 * Pre-aggregated daily statistics for fast dashboard queries.
	 *
	 * goal_id = 0 is the sentinel for impression-level rows (no specific goal).
	 * goal_id > 0 corresponds to a real conversion goal.
	 *
	 * NOTE: goal_id is NOT NULL DEFAULT 0 (not nullable) so that the UNIQUE KEY
	 * works correctly. MySQL treats two NULL values as non-equal in unique indexes,
	 * which would break ON DUPLICATE KEY UPDATE for impression rows.
	 *
	 * @since 1.3.0
	 * @param string $charset_collate Database charset collation.
	 */
	private static function create_daily_stats_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_ab_daily_stats (
			id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			test_id         bigint(20) UNSIGNED NOT NULL,
			variant_id      bigint(20) UNSIGNED NOT NULL,
			goal_id         bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			stat_date       date                NOT NULL,
			impressions     int(11) UNSIGNED    NOT NULL DEFAULT 0,
			unique_visitors int(11) UNSIGNED    NOT NULL DEFAULT 0,
			conversions     int(11) UNSIGNED    NOT NULL DEFAULT 0,
			total_revenue   decimal(12,2)       NOT NULL DEFAULT 0.00,
			PRIMARY KEY  (id),
			UNIQUE KEY daily_unique (test_id, variant_id, goal_id, stat_date),
			KEY stat_date (stat_date),
			KEY test_id (test_id)
			) " . $charset_collate
		);
	}

	/**
	 * Create the ab_decision_log table.
	 *
	 * Stores an audit trail of every declare / apply / revert action for each
	 * A/B test. The idempotency_key UNIQUE constraint prevents double-apply race
	 * conditions. snapshot_json holds the previous (before) values so that
	 * future Revert operations can restore them.
	 *
	 * @since 1.3.2
	 * @param string $charset_collate Database charset collation.
	 */
	private static function create_decision_log_table( $charset_collate ) {
		global $wpdb;

		dbDelta(
			"CREATE TABLE " . $wpdb->prefix . "optibehavior_ab_decision_log (
			id                  bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			test_id             bigint(20) UNSIGNED NOT NULL,
			event               varchar(32)         NOT NULL,
			actor_user_id       bigint(20) UNSIGNED NOT NULL,
			winner_variant_id   bigint(20) UNSIGNED DEFAULT NULL,
			target_post_id      bigint(20) UNSIGNED DEFAULT NULL,
			snapshot_json       longtext            DEFAULT NULL,
			changed_fields_json longtext            DEFAULT NULL,
			idempotency_key     varchar(64)         NOT NULL DEFAULT '',
			notes               text                DEFAULT NULL,
			created_at          datetime            NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency (idempotency_key),
			KEY test_id (test_id),
			KEY event_test (test_id, event),
			KEY created_at (created_at)
			) " . $charset_collate
		);
	}

	// =========================================================================
	// CRUD: Decision Log
	// =========================================================================

	/**
	 * Insert a row into the decision log using INSERT IGNORE (idempotency guard).
	 *
	 * Returns the inserted row ID on success, 0 if the idempotency_key already
	 * exists (meaning the action was already logged), or false on DB error.
	 *
	 * @since 1.3.2
	 * @param array $data {
	 *     @type int    $test_id           Test ID.
	 *     @type string $event             One of 'declared', 'applied', 'reverted'.
	 *     @type int    $actor_user_id     WordPress user ID performing the action.
	 *     @type int    $winner_variant_id Winning variant ID (nullable).
	 *     @type int    $target_post_id    Target post/product ID (nullable).
	 *     @type string $snapshot_json     JSON blob of previous values (nullable).
	 *     @type string $changed_fields_json JSON blob of changed fields (nullable).
	 *     @type string $idempotency_key   Unique key to prevent duplicate entries.
	 *     @type string $notes             Optional human-readable notes.
	 * }
	 * @return int|false Inserted row ID (or 0 if duplicate), false on error.
	 */
	public static function insert_decision_log( $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$defaults = array(
			'test_id'             => 0,
			'event'               => '',
			'actor_user_id'       => 0,
			'winner_variant_id'   => null,
			'target_post_id'      => null,
			'snapshot_json'       => null,
			'changed_fields_json' => null,
			'idempotency_key'     => '',
			'notes'               => null,
			'created_at'          => $now,
		);

		$data = wp_parse_args( $data, $defaults );

		$table = $wpdb->prefix . 'optibehavior_ab_decision_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . $table . '
				(test_id, event, actor_user_id, winner_variant_id, target_post_id,
				 snapshot_json, changed_fields_json, idempotency_key, notes, created_at)
				VALUES (%d, %s, %d, %s, %s, %s, %s, %s, %s, %s)',
				absint( $data['test_id'] ),
				sanitize_key( $data['event'] ),
				absint( $data['actor_user_id'] ),
				null !== $data['winner_variant_id'] ? absint( $data['winner_variant_id'] ) : null,
				null !== $data['target_post_id'] ? absint( $data['target_post_id'] ) : null,
				$data['snapshot_json'],
				$data['changed_fields_json'],
				sanitize_text_field( $data['idempotency_key'] ),
				$data['notes'] ? sanitize_textarea_field( $data['notes'] ) : null,
				$data['created_at']
			)
		);

		if ( false === $result ) {
			return false;
		}

		// INSERT IGNORE returns 0 affected rows when the key already exists.
		if ( 0 === $result ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a decision log row's snapshot and changed_fields columns.
	 *
	 * Called after the apply is complete to fill in the snapshot data that
	 * was not yet available when the idempotency row was first inserted.
	 *
	 * @since 1.3.2
	 * @param int    $log_id              Row ID to update.
	 * @param string $snapshot_json       JSON-encoded before-values.
	 * @param string $changed_fields_json JSON-encoded field-change map.
	 * @return bool True on success.
	 */
	public static function update_decision_log( $log_id, $snapshot_json, $changed_fields_json ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_decision_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->update(
			$table,
			array(
				'snapshot_json'       => $snapshot_json,
				'changed_fields_json' => $changed_fields_json,
			),
			array( 'id' => absint( $log_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get the most recent decision log row for a test with a given event type.
	 *
	 * @since 1.3.2
	 * @param int    $test_id Test ID.
	 * @param string $event   Event type (e.g. 'applied').
	 * @return object|null Row object or null.
	 */
	public static function get_latest_decision_log( $test_id, $event ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_decision_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $table . ' WHERE test_id = %d AND event = %s ORDER BY id DESC LIMIT 1',
				absint( $test_id ),
				sanitize_key( $event )
			)
		);
	}

	// =========================================================================
	// CRUD: Tests
	// =========================================================================

	/**
	 * Insert a new A/B test.
	 *
	 * @since 1.3.0
	 * @param array $data Test data.
	 * @return int|false The test ID on success, false on failure.
	 */
	public static function insert_test( $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$defaults = array(
			'name'               => '',
			'description'        => null,
			'test_type'          => 'page_split',
			'status'             => 'draft',
			'stat_engine'        => 'frequentist',
			'optimization'       => 'manual',
			'confidence_level'   => 0.95,
			'min_sample_size'    => 100,
			'min_duration_hours' => 24,
			'traffic_percent'    => 100,
			'target_url'         => null,
			'target_post_id'     => null,
			'target_selector'    => null,
			'settings'           => null,
			'created_by'         => get_current_user_id(),
			'created_at'         => $now,
			'updated_at'         => $now,
		);

		$data = wp_parse_args( $data, $defaults );

		// Encode settings to JSON if passed as array.
		if ( is_array( $data['settings'] ) ) {
			$data['settings'] = wp_json_encode( $data['settings'] );
		}

		$result = $wpdb->insert(
			$wpdb->prefix . 'optibehavior_ab_tests',
			array(
				'name'               => sanitize_text_field( $data['name'] ),
				'description'        => $data['description'] ? sanitize_textarea_field( $data['description'] ) : null,
				'test_type'          => sanitize_key( $data['test_type'] ),
				'status'             => sanitize_key( $data['status'] ),
				'stat_engine'        => sanitize_key( $data['stat_engine'] ),
				'optimization'       => sanitize_key( $data['optimization'] ),
				'confidence_level'   => floatval( $data['confidence_level'] ),
				'min_sample_size'    => absint( $data['min_sample_size'] ),
				'min_duration_hours' => absint( $data['min_duration_hours'] ),
				'traffic_percent'    => absint( $data['traffic_percent'] ),
				'target_url'         => $data['target_url'] ? esc_url_raw( $data['target_url'] ) : null,
				'target_post_id'     => $data['target_post_id'] ? absint( $data['target_post_id'] ) : null,
				'target_selector'    => $data['target_selector'] ? sanitize_text_field( $data['target_selector'] ) : null,
				'settings'           => $data['settings'],
				'created_by'         => absint( $data['created_by'] ),
				'created_at'         => $data['created_at'],
				'updated_at'         => $data['updated_at'],
			),
			array(
				'%s', '%s', '%s', '%s', '%s', '%s',
				'%f', '%d', '%d', '%d',
				'%s', '%d', '%s', '%s', '%d',
				'%s', '%s',
			)
		);

		if ( false === $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a single A/B test by ID.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return object|null Test row or null.
	 */
	public static function get_test( $test_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_tests';

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $test_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Get tests with optional filters.
	 *
	 * @since 1.3.0
	 * @param array $args {
	 *     Optional query arguments.
	 *     @type string $status    Filter by status.
	 *     @type string $test_type Filter by test type.
	 *     @type string $orderby   Column to order by. Default 'created_at'.
	 *     @type string $order     ASC or DESC. Default 'DESC'.
	 *     @type int    $per_page  Results per page. Default 25.
	 *     @type int    $page      Page number. Default 1.
	 * }
	 * @return array Array of test row objects.
	 */
	public static function get_tests( $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_tests';

		$defaults = array(
			'status'    => '',
			'test_type' => '',
			'search'    => '',
			'orderby'   => 'created_at',
			'order'     => 'DESC',
			'per_page'  => 25,
			'page'      => 1,
		);

		$args = wp_parse_args( $args, $defaults );

		// Build WHERE clauses.
		$where   = array( '1=1' );
		$prepare = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]   = 'status = %s';
			$prepare[] = sanitize_key( $args['status'] );
		}

		if ( ! empty( $args['test_type'] ) ) {
			$where[]   = 'test_type = %s';
			$prepare[] = sanitize_key( $args['test_type'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]   = 'name LIKE %s';
			$prepare[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}

		$where_clause = implode( ' AND ', $where );

		// Whitelist ORDER BY.
		$allowed_orderby = array( 'id', 'name', 'status', 'test_type', 'created_at', 'updated_at', 'started_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = max( 0, ( absint( $args['page'] ) - 1 ) * $per_page );

		$sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$prepare[] = $per_page;
		$prepare[] = $offset;

		if ( ! empty( $prepare ) ) {
			$sql = $wpdb->prepare( $sql, $prepare ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count tests with optional filters.
	 *
	 * @since 1.3.0
	 * @param array $args Optional filters (status, test_type, search).
	 * @return int Total count.
	 */
	public static function count_tests( $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_tests';

		$where   = array( '1=1' );
		$prepare = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]   = 'status = %s';
			$prepare[] = sanitize_key( $args['status'] );
		}

		if ( ! empty( $args['test_type'] ) ) {
			$where[]   = 'test_type = %s';
			$prepare[] = sanitize_key( $args['test_type'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]   = 'name LIKE %s';
			$prepare[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}

		$where_clause = implode( ' AND ', $where );

		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";

		if ( ! empty( $prepare ) ) {
			$sql = $wpdb->prepare( $sql, $prepare ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Update a test.
	 *
	 * @since 1.3.0
	 * @param int   $test_id Test ID.
	 * @param array $data    Columns to update.
	 * @return bool True on success, false on failure.
	 */
	public static function update_test( $test_id, $data ) {
		global $wpdb;

		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$data['settings'] = wp_json_encode( $data['settings'] );
		}

		// Build a sanitised update array with only valid table columns and proper
		// type casting — mirrors the column handling in insert_test().
		// Without this, callers that pass extra keys (e.g. 'variants', 'goals',
		// 'test_id') cause $wpdb->update() to fail silently, discarding ALL
		// changes including target_url.
		$update = array( 'updated_at' => current_time( 'mysql' ) );
		$format = array( '%s' );

		if ( isset( $data['name'] ) ) {
			$update['name'] = sanitize_text_field( $data['name'] );
			$format[]       = '%s';
		}
		if ( isset( $data['description'] ) ) {
			$update['description'] = $data['description'] ? sanitize_textarea_field( $data['description'] ) : null;
			$format[]              = '%s';
		}
		if ( isset( $data['test_type'] ) ) {
			$update['test_type'] = sanitize_key( $data['test_type'] );
			$format[]            = '%s';
		}
		if ( isset( $data['status'] ) ) {
			$update['status'] = sanitize_key( $data['status'] );
			$format[]         = '%s';
		}
		if ( isset( $data['stat_engine'] ) ) {
			$update['stat_engine'] = sanitize_key( $data['stat_engine'] );
			$format[]              = '%s';
		}
		if ( isset( $data['optimization'] ) ) {
			$update['optimization'] = sanitize_key( $data['optimization'] );
			$format[]               = '%s';
		}
		if ( isset( $data['confidence_level'] ) ) {
			$update['confidence_level'] = floatval( $data['confidence_level'] );
			$format[]                   = '%f';
		}
		if ( isset( $data['min_sample_size'] ) ) {
			$update['min_sample_size'] = absint( $data['min_sample_size'] );
			$format[]                  = '%d';
		}
		if ( isset( $data['min_duration_hours'] ) ) {
			$update['min_duration_hours'] = absint( $data['min_duration_hours'] );
			$format[]                     = '%d';
		}
		if ( isset( $data['traffic_percent'] ) ) {
			$update['traffic_percent'] = absint( $data['traffic_percent'] );
			$format[]                  = '%d';
		}
		if ( array_key_exists( 'target_url', $data ) ) {
			$update['target_url'] = $data['target_url'] ? esc_url_raw( $data['target_url'] ) : null;
			$format[]             = '%s';
		}
		if ( array_key_exists( 'target_post_id', $data ) ) {
			$update['target_post_id'] = $data['target_post_id'] ? absint( $data['target_post_id'] ) : null;
			$format[]                 = '%d';
		}
		if ( array_key_exists( 'target_selector', $data ) ) {
			$update['target_selector'] = $data['target_selector'] ? sanitize_text_field( $data['target_selector'] ) : null;
			$format[]                  = '%s';
		}
		if ( array_key_exists( 'settings', $data ) ) {
			$update['settings'] = $data['settings'];
			$format[]           = '%s';
		}
		if ( isset( $data['started_at'] ) ) {
			$update['started_at'] = $data['started_at'];
			$format[]             = '%s';
		}
		if ( isset( $data['ended_at'] ) ) {
			$update['ended_at'] = $data['ended_at'];
			$format[]           = '%s';
		}
		if ( isset( $data['winner_variant_id'] ) ) {
			$update['winner_variant_id'] = $data['winner_variant_id'] ? absint( $data['winner_variant_id'] ) : null;
			$format[]                    = '%d';
		}
		if ( array_key_exists( 'applied_at', $data ) ) {
			$update['applied_at'] = $data['applied_at'] ? $data['applied_at'] : null;
			$format[]             = '%s';
		}
		if ( array_key_exists( 'applied_by', $data ) ) {
			$update['applied_by'] = $data['applied_by'] ? absint( $data['applied_by'] ) : null;
			$format[]             = '%d';
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'optibehavior_ab_tests',
			$update,
			array( 'id' => absint( $test_id ) ),
			$format,
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete a test and all associated data.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return bool True on success.
	 */
	public static function delete_test( $test_id ) {
		global $wpdb;

		$test_id = absint( $test_id );

		// Delete in dependency order: conversions, impressions, daily_stats, goals, variants, test.
		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_conversions', array( 'test_id' => $test_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_impressions', array( 'test_id' => $test_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_daily_stats', array( 'test_id' => $test_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_goals', array( 'test_id' => $test_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_variants', array( 'test_id' => $test_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_tests', array( 'id' => $test_id ), array( '%d' ) );

		// Delete Pro tables if they exist.
		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_targeting_rules', array( 'test_id' => $test_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_schedule', array( 'test_id' => $test_id ), array( '%d' ) );

		return true;
	}

	/**
	 * Get active (running) tests, optionally filtered by target URL.
	 *
	 * Results are cached in a transient for performance.
	 *
	 * @since 1.3.0
	 * @param string|null $target_url Optional URL filter.
	 * @return array Array of test objects.
	 */
	public static function get_active_tests( $target_url = null ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_tests';

		if ( null === $target_url ) {
			$cache_key = 'opti_behavior_ab_active_tests_all';
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}

			$results = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC", 'running' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			set_transient( $cache_key, $results, 5 * MINUTE_IN_SECONDS );
			return $results;
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND target_url = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'running',
				$target_url
			)
		);
	}

	/**
	 * Invalidate the active tests cache.
	 *
	 * Should be called whenever a test status changes.
	 *
	 * @since 1.3.0
	 */
	public static function invalidate_active_tests_cache() {
		delete_transient( 'opti_behavior_ab_active_tests_all' );
	}

	// =========================================================================
	// CRUD: Variants
	// =========================================================================

	/**
	 * Insert a variant.
	 *
	 * @since 1.3.0
	 * @param array $data Variant data.
	 * @return int|false Variant ID or false.
	 */
	public static function insert_variant( $data ) {
		global $wpdb;

		$defaults = array(
			'test_id'        => 0,
			'name'           => '',
			'is_control'     => 0,
			'traffic_weight' => 50,
			'variant_data'   => '{}',
			'sort_order'     => 0,
			'created_at'     => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $data, $defaults );

		if ( is_array( $data['variant_data'] ) ) {
			$data['variant_data'] = wp_json_encode( $data['variant_data'] );
		}

		$result = $wpdb->insert(
			$wpdb->prefix . 'optibehavior_ab_variants',
			array(
				'test_id'        => absint( $data['test_id'] ),
				'name'           => sanitize_text_field( $data['name'] ),
				'is_control'     => absint( $data['is_control'] ) ? 1 : 0,
				'traffic_weight' => min( 100, max( 0, absint( $data['traffic_weight'] ) ) ),
				'variant_data'   => $data['variant_data'],
				'sort_order'     => absint( $data['sort_order'] ),
				'created_at'     => $data['created_at'],
			),
			array( '%d', '%s', '%d', '%d', '%s', '%d', '%s' )
		);

		return false === $result ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Get all variants for a test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return array Array of variant objects.
	 */
	public static function get_variants( $test_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_variants';

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE test_id = %d ORDER BY sort_order ASC, id ASC", absint( $test_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Get a single variant by ID.
	 *
	 * @since 1.3.0
	 * @param int $variant_id Variant ID.
	 * @return object|null Variant row or null.
	 */
	public static function get_variant( $variant_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_variants';

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $variant_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Update a variant.
	 *
	 * @since 1.3.0
	 * @param int   $variant_id Variant ID.
	 * @param array $data       Columns to update.
	 * @return bool True on success.
	 */
	public static function update_variant( $variant_id, $data ) {
		global $wpdb;

		if ( isset( $data['variant_data'] ) && is_array( $data['variant_data'] ) ) {
			$data['variant_data'] = wp_json_encode( $data['variant_data'] );
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'optibehavior_ab_variants',
			$data,
			array( 'id' => absint( $variant_id ) ),
			null,
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete a variant.
	 *
	 * @since 1.3.0
	 * @param int $variant_id Variant ID.
	 * @return bool True on success.
	 */
	public static function delete_variant( $variant_id ) {
		global $wpdb;

		$variant_id = absint( $variant_id );

		// Also clean up impressions/conversions referencing this variant.
		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_impressions', array( 'variant_id' => $variant_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_conversions', array( 'variant_id' => $variant_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_daily_stats', array( 'variant_id' => $variant_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_variants', array( 'id' => $variant_id ), array( '%d' ) );

		return true;
	}

	// =========================================================================
	// CRUD: Goals
	// =========================================================================

	/**
	 * Normalize a goal_config payload before persisting.
	 *
	 * Trims leading/trailing whitespace from the `url` key (page_visit goals).
	 * A trailing space in the stored URL survives wp_parse_url() inside the
	 * renderer's goal-URL match pass (untrailingslashit only strips slashes),
	 * so the strict path comparison never matches and page_visit conversions
	 * are silently never tracked. The JS tracker is immune (the WHATWG URL
	 * parser trims), which made the asymmetry hard to spot — normalize at
	 * write time so stored data is always clean.
	 *
	 * @since 1.7.2
	 * @param array|string $config Goal config array or JSON string.
	 * @return string JSON-encoded normalized config.
	 */
	private static function normalize_goal_config( $config ) {
		$decoded = is_array( $config ) ? $config : json_decode( (string) $config, true );

		if ( ! is_array( $decoded ) ) {
			// Not decodable — persist unchanged to avoid data loss.
			return is_string( $config ) ? $config : (string) wp_json_encode( $config );
		}

		if ( isset( $decoded['url'] ) && is_string( $decoded['url'] ) ) {
			$decoded['url'] = trim( $decoded['url'] );
		}

		return (string) wp_json_encode( $decoded );
	}

	/**
	 * Insert a goal.
	 *
	 * @since 1.3.0
	 * @param array $data Goal data.
	 * @return int|false Goal ID or false.
	 */
	public static function insert_goal( $data ) {
		global $wpdb;

		$defaults = array(
			'test_id'     => 0,
			'name'        => '',
			'goal_type'   => 'page_visit',
			'goal_config' => '{}',
			'is_primary'  => 0,
			'created_at'  => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $data, $defaults );

		$data['goal_config'] = self::normalize_goal_config( $data['goal_config'] );

		$result = $wpdb->insert(
			$wpdb->prefix . 'optibehavior_ab_goals',
			array(
				'test_id'     => absint( $data['test_id'] ),
				'name'        => sanitize_text_field( $data['name'] ),
				'goal_type'   => sanitize_key( $data['goal_type'] ),
				'goal_config' => $data['goal_config'],
				'is_primary'  => absint( $data['is_primary'] ) ? 1 : 0,
				'created_at'  => $data['created_at'],
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		return false === $result ? false : (int) $wpdb->insert_id;
	}

	/**
	 * Get all goals for a test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return array Array of goal objects.
	 */
	public static function get_goals( $test_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_goals';

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE test_id = %d ORDER BY is_primary DESC, id ASC", absint( $test_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Delete goals for a test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return bool True on success.
	 */
	public static function delete_goals_for_test( $test_id ) {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'optibehavior_ab_goals', array( 'test_id' => absint( $test_id ) ), array( '%d' ) );
		return true;
	}

	// =========================================================================
	// CRUD: Impressions
	// =========================================================================

	/**
	 * Record an impression (visitor saw a variant).
	 *
	 * @since 1.3.0
	 * @param array $data Impression data.
	 * @return int|false Impression ID or false.
	 */
	public static function record_impression( $data ) {
		global $wpdb;

		$table      = $wpdb->prefix . 'optibehavior_ab_impressions';
		$visitor_id = sanitize_text_field( $data['visitor_id'] );
		$test_id    = absint( $data['test_id'] );

		// Bug #3 — Fast path: avoid the INSERT when an impression already exists
		// for this (test_id, visitor_id). The UNIQUE KEY visitor_test_unique
		// below protects us against the race (two concurrent tracker flushes
		// — visibilitychange + beforeunload — that both pass this SELECT
		// before either INSERTs); the pre-check just skips the unnecessary
		// write in the common single-flush case.
		//
		// Bug #R3 — Cookie/DB drift: when the same visitor returns with a
		// different cookie-assigned variant (stale opti_ab_{test_id} cookie
		// after admin variant edit, traffic-split change, or manual cookie
		// surgery), the bucketer hands the new variant to the renderer/tracker
		// but the old impression row still attributes the visitor to the old
		// variant — dashboard never reflects the visit. UPDATE variant_id when
		// it differs so stats track the visitor's CURRENT assignment. UNIQUE
		// dedup still ensures one row per (test, visitor); we just keep its
		// variant_id authoritative.
		$existing_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, variant_id FROM {$table} WHERE visitor_id = %s AND test_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$visitor_id,
				$test_id
			)
		);

		if ( $existing_row ) {
			$incoming_variant = absint( $data['variant_id'] );
			if ( $incoming_variant > 0 && (int) $existing_row->variant_id !== $incoming_variant ) {
				$wpdb->update(
					$table,
					array( 'variant_id' => $incoming_variant ),
					array( 'id' => (int) $existing_row->id ),
					array( '%d' ),
					array( '%d' )
				);
			}
			return (int) $existing_row->id;
		}

		// Race-safe insert. If a concurrent request slipped past the SELECT
		// above and inserted first, the UNIQUE KEY (test_id, visitor_id)
		// causes this INSERT to fail. $wpdb->insert() returns false on
		// duplicate — we hide the MySQL "Duplicate entry" warning so it
		// doesn't pollute debug.log, then re-read the existing row so
		// callers still get a valid impression id.
		$previous_show_errors = $wpdb->hide_errors();

		$result = $wpdb->insert(
			$table,
			array(
				'test_id'         => $test_id,
				'variant_id'      => absint( $data['variant_id'] ),
				'visitor_id'      => $visitor_id,
				'session_id'      => isset( $data['session_id'] ) ? sanitize_text_field( $data['session_id'] ) : null,
				'ip_hash'         => isset( $data['ip_hash'] ) ? sanitize_text_field( $data['ip_hash'] ) : null,
				'device_type'     => isset( $data['device_type'] ) ? sanitize_key( $data['device_type'] ) : null,
				'browser'         => isset( $data['browser'] ) ? sanitize_text_field( $data['browser'] ) : null,
				'country'         => isset( $data['country'] ) ? sanitize_text_field( substr( $data['country'], 0, 2 ) ) : null,
				'referrer_domain' => isset( $data['referrer_domain'] ) ? sanitize_text_field( $data['referrer_domain'] ) : null,
				'utm_source'      => isset( $data['utm_source'] ) ? sanitize_text_field( $data['utm_source'] ) : null,
				'utm_medium'      => isset( $data['utm_medium'] ) ? sanitize_text_field( $data['utm_medium'] ) : null,
				'utm_campaign'    => isset( $data['utm_campaign'] ) ? sanitize_text_field( $data['utm_campaign'] ) : null,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Restore previous error display mode.
		if ( $previous_show_errors ) {
			$wpdb->show_errors();
		}

		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}

		// INSERT failed — most likely a UNIQUE KEY collision from a concurrent
		// request. Re-read the existing row so the caller still gets a usable
		// impression id (idempotent behaviour). Apply the same Bug #R3
		// drift-correction UPDATE here so the race-loser path also keeps
		// variant_id authoritative.
		$existing_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, variant_id FROM {$table} WHERE visitor_id = %s AND test_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$visitor_id,
				$test_id
			)
		);

		if ( $existing_row ) {
			$incoming_variant = absint( $data['variant_id'] );
			if ( $incoming_variant > 0 && (int) $existing_row->variant_id !== $incoming_variant ) {
				$wpdb->update(
					$table,
					array( 'variant_id' => $incoming_variant ),
					array( 'id' => (int) $existing_row->id ),
					array( '%d' ),
					array( '%d' )
				);
			}
			return (int) $existing_row->id;
		}

		return false;
	}

	// =========================================================================
	// CRUD: Conversions
	// =========================================================================

	/**
	 * Record a conversion (goal achieved).
	 *
	 * Prevents duplicate conversions for the same visitor + test + goal.
	 *
	 * @since 1.3.0
	 * @param array $data Conversion data.
	 * @return int|false Conversion ID or false.
	 */
	public static function record_conversion( $data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_conversions';

		if ( isset( $data['metadata'] ) && is_array( $data['metadata'] ) ) {
			$data['metadata'] = wp_json_encode( $data['metadata'] );
		}

		$test_id    = absint( $data['test_id'] );
		$variant_id = absint( $data['variant_id'] );
		$goal_id    = absint( $data['goal_id'] );
		$visitor_id = sanitize_text_field( $data['visitor_id'] );
		$session_id = isset( $data['session_id'] ) ? sanitize_text_field( $data['session_id'] ) : null;
		$revenue    = isset( $data['revenue'] ) ? floatval( $data['revenue'] ) : null;
		$metadata   = isset( $data['metadata'] ) ? $data['metadata'] : null;
		$created_at = current_time( 'mysql' );

		// Atomic upsert. The UNIQUE KEY conv_unique (test_id, goal_id, visitor_id)
		// guarantees one row per (test, goal, visitor); ON DUPLICATE KEY UPDATE id=id
		// makes the second concurrent insert a no-op without triggering an INSERT
		// race window like the previous SELECT-then-INSERT path did.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INSERT INTO {$table} (test_id, variant_id, goal_id, visitor_id, session_id, revenue, metadata, created_at)
			 VALUES (%d, %d, %d, %s, %s, %f, %s, %s)
			 ON DUPLICATE KEY UPDATE id = id",
			$test_id,
			$variant_id,
			$goal_id,
			$visitor_id,
			null === $session_id ? '' : $session_id,
			null === $revenue ? 0 : $revenue,
			null === $metadata ? '' : $metadata,
			$created_at
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			return false;
		}

		// New row inserted: insert_id > 0. Existing row matched by ON DUPLICATE KEY:
		// MySQL returns 0 — re-fetch the existing id so callers get a stable handle.
		if ( $wpdb->insert_id ) {
			return (int) $wpdb->insert_id;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE test_id = %d AND goal_id = %d AND visitor_id = %s LIMIT 1",
				$test_id,
				$goal_id,
				$visitor_id
			)
		);
	}

	// =========================================================================
	// Statistics / Aggregation Queries
	// =========================================================================

	/**
	 * Get summary statistics for a test (total impressions, conversions, per variant).
	 *
	 * @since 1.3.0
	 * @param int      $test_id Test ID.
	 * @param int|null  $goal_id Optional goal ID filter (null = primary goal).
	 * @param bool|null $exclude_spam Optional spam filter override.
	 * @return array Array of variant stat objects.
	 */
	public static function get_test_stats( $test_id, $goal_id = null, $exclude_spam = null ) {
		global $wpdb;

		$impressions_table = $wpdb->prefix . 'optibehavior_ab_impressions';
		$conversions_table = $wpdb->prefix . 'optibehavior_ab_conversions';
		$variants_table    = $wpdb->prefix . 'optibehavior_ab_variants';

		// If no goal_id, get primary goal.
		if ( null === $goal_id ) {
			$goals_table = $wpdb->prefix . 'optibehavior_ab_goals';
			$goal_id     = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$goals_table} WHERE test_id = %d AND is_primary = 1 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					absint( $test_id )
				)
			);
		}

		$impression_spam_where = self::get_ab_spam_session_filter_sql( 'i', $exclude_spam );
		$conversion_spam_where = self::get_ab_spam_session_filter_sql( 'c', $exclude_spam );

		$sql = "SELECT
				v.id AS variant_id,
				v.name AS variant_name,
				v.is_control,
				v.traffic_weight,
				v.variant_data,
				COALESCE(imp.impression_count, 0) AS impressions,
				COALESCE(imp.unique_visitors, 0) AS unique_visitors,
				COALESCE(conv.conversion_count, 0) AS conversions,
				COALESCE(conv.total_revenue, 0) AS revenue
			FROM {$variants_table} v
			LEFT JOIN (
				SELECT i.variant_id,
					COUNT(*) AS impression_count,
					COUNT(DISTINCT i.visitor_id) AS unique_visitors
				FROM {$impressions_table} i
				WHERE i.test_id = %d{$impression_spam_where}
				GROUP BY i.variant_id
			) imp ON imp.variant_id = v.id
			LEFT JOIN (
				SELECT c.variant_id,
					COUNT(*) AS conversion_count,
					COALESCE(SUM(c.revenue), 0) AS total_revenue
				FROM {$conversions_table} c
				WHERE c.test_id = %d{$conversion_spam_where}" . ( $goal_id ? " AND c.goal_id = %d" : "" ) . "
				GROUP BY c.variant_id
			) conv ON conv.variant_id = v.id
			WHERE v.test_id = %d
			ORDER BY v.sort_order ASC, v.id ASC";

		if ( $goal_id ) {
			return $wpdb->get_results(
				$wpdb->prepare( $sql, absint( $test_id ), absint( $test_id ), absint( $goal_id ), absint( $test_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare( $sql, absint( $test_id ), absint( $test_id ), absint( $test_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Get daily stats for a test (for charting).
	 *
	 * @since 1.3.0
	 * @param int      $test_id  Test ID.
	 * @param int|null $goal_id  Optional goal ID filter.
	 * @param string   $from     Start date (Y-m-d). Default: 30 days ago.
	 * @param string   $to       End date (Y-m-d). Default: today.
	 * @param bool|null $exclude_spam Optional spam filter override.
	 * @return array Array of daily stat objects.
	 */
	public static function get_daily_stats( $test_id, $goal_id = null, $from = null, $to = null, $exclude_spam = null ) {
		global $wpdb;

		$impressions_table = $wpdb->prefix . 'optibehavior_ab_impressions';
		$conversions_table = $wpdb->prefix . 'optibehavior_ab_conversions';
		$variants_table    = $wpdb->prefix . 'optibehavior_ab_variants';

		if ( null === $from ) {
			$from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		}
		if ( null === $to ) {
			$to = gmdate( 'Y-m-d' );
		}

		// If no goal_id provided, use the primary goal so the conversion rate is meaningful.
		if ( null === $goal_id ) {
			$goals_table = $wpdb->prefix . 'optibehavior_ab_goals';
			$goal_id     = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$goals_table} WHERE test_id = %d AND is_primary = 1 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					absint( $test_id )
				)
			);
		}

		// Build optional goal filter for the conversions sub-query.
		$goal_clause = $goal_id ? ' AND c.goal_id = %d' : '';
		$impression_spam_where = self::get_ab_spam_session_filter_sql( 'i', $exclude_spam );
		$conversion_spam_where = self::get_ab_spam_session_filter_sql( 'c', $exclude_spam );

		// Query raw tables in real time, grouped by date — same approach as get_test_stats()
		// but with a DATE() grouping for the time-series chart.
		$sql = "SELECT
					v.id          AS variant_id,
					v.name        AS variant_name,
					DATE(i.created_at) AS stat_date,
					COUNT(i.id)   AS impressions,
					COALESCE(conv.conversions, 0)      AS conversions,
					COALESCE(conv.total_revenue, 0.00) AS total_revenue
				FROM {$impressions_table} i
				JOIN {$variants_table} v ON v.id = i.variant_id
				LEFT JOIN (
					SELECT c.variant_id,
					       DATE(c.created_at)             AS conv_date,
					       COUNT(*)                       AS conversions,
					       COALESCE(SUM(c.revenue), 0.00) AS total_revenue
					FROM {$conversions_table} c
					WHERE c.test_id = %d{$conversion_spam_where}" . $goal_clause . "
					  AND DATE(c.created_at) >= %s
					  AND DATE(c.created_at) <= %s
					GROUP BY c.variant_id, conv_date
				) conv ON conv.variant_id = i.variant_id
				       AND conv.conv_date = DATE(i.created_at)
				WHERE i.test_id = %d{$impression_spam_where}
				  AND DATE(i.created_at) >= %s
				  AND DATE(i.created_at) <= %s
				GROUP BY v.id, DATE(i.created_at)
				ORDER BY stat_date ASC, v.id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		if ( $goal_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$sql,
					absint( $test_id ), absint( $goal_id ), $from, $to,
					absint( $test_id ), $from, $to
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$sql,
					absint( $test_id ), $from, $to,
					absint( $test_id ), $from, $to
				)
			);
		}

		return self::zero_fill_daily_stats( $rows, $test_id );
	}

	/**
	 * Zero-fill the daily-stats result set.
	 *
	 * The raw SQL in {@see get_daily_stats()} only returns (variant, date) tuples
	 * where at least one impression exists on that date. For the
	 * "Conversion Rate Over Time" chart and any other time-series consumer, we
	 * need a dense matrix — every variant of the test must have a row for every
	 * observed date, with zero-valued impressions/conversions filled in when
	 * the variant received no traffic that day. Otherwise Chart.js (or any
	 * other consumer) sees a gap and cannot draw a continuous line.
	 *
	 * @since 1.3.1
	 * @param array $rows    Raw rows from the daily_stats SQL.
	 * @param int   $test_id Test ID (used to look up the full variant roster).
	 * @return array Dense matrix sorted by (stat_date ASC, variant_id ASC).
	 */
	private static function zero_fill_daily_stats( $rows, $test_id ) {
		if ( empty( $rows ) ) {
			return $rows;
		}

		// Collect all observed dates and mark which (variant,date) pairs exist.
		$dates = array();
		$seen  = array();
		foreach ( $rows as $r ) {
			$dates[ $r->stat_date ]                            = true;
			$seen[ $r->variant_id . '|' . $r->stat_date ]      = true;
		}
		$dates = array_keys( $dates );

		// Load the full variant roster for this test so we can zero-fill
		// variants that are entirely absent from $rows on a given date.
		$variants = self::get_variants( absint( $test_id ) );
		if ( empty( $variants ) ) {
			return $rows;
		}

		foreach ( $variants as $v ) {
			foreach ( $dates as $d ) {
				$key = $v->id . '|' . $d;
				if ( empty( $seen[ $key ] ) ) {
					$rows[]       = (object) array(
						'variant_id'    => (int) $v->id,
						'variant_name'  => $v->name,
						'stat_date'     => $d,
						'impressions'   => 0,
						'conversions'   => 0,
						'total_revenue' => 0.0,
					);
					$seen[ $key ] = true;
				}
			}
		}

		// Preserve the original SQL ordering: stat_date ASC, variant_id ASC.
		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a->stat_date !== $b->stat_date ) {
					return strcmp( $a->stat_date, $b->stat_date );
				}
				return (int) $a->variant_id - (int) $b->variant_id;
			}
		);

		return $rows;
	}

	/**
	 * Aggregate raw impressions/conversions into daily stats.
	 *
	 * Called by cron job. Processes data for a specific date.
	 *
	 * @since 1.3.0
	 * @param string $date Date to aggregate (Y-m-d). Default: yesterday.
	 */
	public static function aggregate_daily_stats( $date = null ) {
		global $wpdb;

		if ( null === $date ) {
			$date = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		}

		$impressions_table = $wpdb->prefix . 'optibehavior_ab_impressions';
		$conversions_table = $wpdb->prefix . 'optibehavior_ab_conversions';
		$daily_table       = $wpdb->prefix . 'optibehavior_ab_daily_stats';

		// Aggregate impressions.
		$impression_stats = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT test_id, variant_id, COUNT(*) AS impressions, COUNT(DISTINCT visitor_id) AS unique_visitors
				FROM {$impressions_table}
				WHERE DATE(created_at) = %s
				GROUP BY test_id, variant_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$date
			)
		);

		foreach ( $impression_stats as $stat ) {
			// goal_id = 0 is the sentinel for impression-level rows (no specific goal).
			// Using 0 (not NULL) ensures ON DUPLICATE KEY UPDATE fires correctly —
			// MySQL treats two NULLs as non-equal in unique indexes.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$daily_table} (test_id, variant_id, goal_id, stat_date, impressions, unique_visitors, conversions, total_revenue)
					VALUES (%d, %d, 0, %s, %d, %d, 0, 0.00)
					ON DUPLICATE KEY UPDATE impressions = VALUES(impressions), unique_visitors = VALUES(unique_visitors)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$stat->test_id,
					$stat->variant_id,
					$date,
					$stat->impressions,
					$stat->unique_visitors
				)
			);
		}

		// Aggregate conversions per goal.
		$conversion_stats = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT test_id, variant_id, goal_id, COUNT(*) AS conversions, COALESCE(SUM(revenue), 0) AS total_revenue
				FROM {$conversions_table}
				WHERE DATE(created_at) = %s
				GROUP BY test_id, variant_id, goal_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$date
			)
		);

		foreach ( $conversion_stats as $stat ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$daily_table} (test_id, variant_id, goal_id, stat_date, impressions, unique_visitors, conversions, total_revenue)
					VALUES (%d, %d, %d, %s, 0, 0, %d, %f)
					ON DUPLICATE KEY UPDATE conversions = VALUES(conversions), total_revenue = VALUES(total_revenue)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$stat->test_id,
					$stat->variant_id,
					$stat->goal_id,
					$date,
					$stat->conversions,
					$stat->total_revenue
				)
			);
		}
	}

	/**
	 * Clean up old raw data (impressions/conversions) based on retention days.
	 *
	 * @since 1.3.0
	 * @param int $retention_days Number of days to keep raw data. Default: 90.
	 * @return int Total rows deleted.
	 */
	public static function cleanup_old_data( $retention_days = 90 ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

		$deleted_impressions = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . $wpdb->prefix . "optibehavior_ab_impressions WHERE created_at < %s",
				$cutoff
			)
		);

		$deleted_conversions = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . $wpdb->prefix . "optibehavior_ab_conversions WHERE created_at < %s",
				$cutoff
			)
		);

		return (int) $deleted_impressions + (int) $deleted_conversions;
	}

	/**
	 * Drop all A/B testing tables.
	 *
	 * Used during uninstall.
	 *
	 * @since 1.3.0
	 */
	public static function drop_tables() {
		global $wpdb;

		$tables = array(
			'optibehavior_ab_daily_stats',
			'optibehavior_ab_conversions',
			'optibehavior_ab_impressions',
			'optibehavior_ab_goals',
			'optibehavior_ab_variants',
			'optibehavior_ab_tests',
			// Pro tables (safe to attempt even if free-only).
			'optibehavior_ab_targeting_rules',
			'optibehavior_ab_schedule',
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	// =========================================================================
	// Additional helper methods for Tests
	// =========================================================================

	/**
	 * Count currently running tests.
	 *
	 * Used by the manager / AJAX handlers to enforce the free-plan
	 * concurrent-test limit before starting a new test.
	 *
	 * @since 1.3.0
	 * @return int
	 */
	public static function get_running_tests_count() {
		return self::count_tests( array( 'status' => self::STATUS_RUNNING ) );
	}

	// =========================================================================
	// Additional helper methods for Variants
	// =========================================================================

	/**
	 * Get the control (original) variant for a test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return object|null Variant row object or null.
	 */
	public static function get_control_variant( $test_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_variants';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE test_id = %d AND is_control = 1 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $test_id )
			)
		);
	}

	/**
	 * Delete all variants for a test.
	 *
	 * Used by delete_test() cascade and by the manager when replacing variants.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return int|false Number of rows deleted or false on error.
	 */
	public static function delete_variants_for_test( $test_id ) {
		global $wpdb;
		return $wpdb->delete(
			$wpdb->prefix . 'optibehavior_ab_variants',
			array( 'test_id' => absint( $test_id ) ),
			array( '%d' )
		);
	}

	// =========================================================================
	// Additional helper methods for Goals
	// =========================================================================

	/**
	 * Get a single goal by primary key.
	 *
	 * @since 1.3.0
	 * @param int $goal_id Goal ID.
	 * @return object|null
	 */
	public static function get_goal( $goal_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_goals';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $goal_id )
			)
		);
	}

	/**
	 * Get the primary conversion goal for a test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return object|null
	 */
	public static function get_primary_goal( $test_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_goals';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE test_id = %d AND is_primary = 1 LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $test_id )
			)
		);
	}

	/**
	 * Update a goal record.
	 *
	 * @since 1.3.0
	 * @param int   $goal_id Goal ID.
	 * @param array $data    Column → value pairs to update.
	 * @return bool True on success, false on failure.
	 */
	public static function update_goal( $goal_id, $data ) {
		global $wpdb;

		if ( isset( $data['goal_config'] ) ) {
			$data['goal_config'] = self::normalize_goal_config( $data['goal_config'] );
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'optibehavior_ab_goals',
			$data,
			array( 'id' => absint( $goal_id ) ),
			null,
			array( '%d' )
		);

		return false !== $result;
	}

	// =========================================================================
	// Additional helper methods for Impressions
	// =========================================================================

	/**
	 * Check whether a visitor already has an impression for a test.
	 *
	 * Lighter-weight alternative to record_impression()'s built-in dedup check
	 * when you only need to know the assignment (not create a new record).
	 *
	 * @since 1.3.0
	 * @param string $visitor_id Visitor UUID.
	 * @param int    $test_id    Test ID.
	 * @return bool
	 */
	public static function has_impression( $visitor_id, $test_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_impressions';

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE visitor_id = %s AND test_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				sanitize_text_field( $visitor_id ),
				absint( $test_id )
			)
		);
	}

	/**
	 * Get impression counts (total + unique visitors) per variant for a test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return array Rows with keys: variant_id, total, unique_visitors.
	 */
	public static function get_impression_counts( $test_id ) {
		global $wpdb;

		$table      = $wpdb->prefix . 'optibehavior_ab_impressions';
		$spam_where = self::get_ab_spam_session_filter_sql( 'i' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.variant_id, COUNT(*) AS total, COUNT(DISTINCT i.visitor_id) AS unique_visitors
				 FROM {$table} i
				 WHERE i.test_id = %d{$spam_where}
				 GROUP BY i.variant_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				absint( $test_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Delete all impressions for a test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return int|false Rows deleted or false.
	 */
	public static function delete_impressions_for_test( $test_id ) {
		global $wpdb;
		return $wpdb->delete(
			$wpdb->prefix . 'optibehavior_ab_impressions',
			array( 'test_id' => absint( $test_id ) ),
			array( '%d' )
		);
	}

	// =========================================================================
	// Additional helper methods for Conversions
	// =========================================================================

	/**
	 * Check whether a visitor has already converted on a goal for a test.
	 *
	 * @since 1.3.0
	 * @param string $visitor_id Visitor UUID.
	 * @param int    $test_id    Test ID.
	 * @param int    $goal_id    Goal ID.
	 * @return bool
	 */
	public static function has_conversion( $visitor_id, $test_id, $goal_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_ab_conversions';

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE visitor_id = %s AND test_id = %d AND goal_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				sanitize_text_field( $visitor_id ),
				absint( $test_id ),
				absint( $goal_id )
			)
		);
	}

	/**
	 * Get conversion counts (and revenue) per variant/goal for a test.
	 *
	 * @since 1.3.0
	 * @param int      $test_id Test ID.
	 * @param int|null $goal_id Filter to a single goal. Default null (all goals).
	 * @return array Rows with keys: variant_id, goal_id, total, total_revenue.
	 */
	public static function get_conversion_counts( $test_id, $goal_id = null ) {
		global $wpdb;

		$table      = $wpdb->prefix . 'optibehavior_ab_conversions';
		$spam_where = self::get_ab_spam_session_filter_sql( 'c' );

		$where   = 'c.test_id = %d' . $spam_where;
		$prepare = array( absint( $test_id ) );

		if ( null !== $goal_id ) {
			$where    .= ' AND c.goal_id = %d';
			$prepare[] = absint( $goal_id );
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.variant_id, c.goal_id, COUNT(*) AS total, COALESCE(SUM(c.revenue), 0.00) AS total_revenue
				 FROM {$table} c
				 WHERE {$where}
				 GROUP BY c.variant_id, c.goal_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$prepare
			),
			ARRAY_A
		);
	}

	/**
	 * Delete all conversions for a test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return int|false Rows deleted or false.
	 */
	public static function delete_conversions_for_test( $test_id ) {
		global $wpdb;
		return $wpdb->delete(
			$wpdb->prefix . 'optibehavior_ab_conversions',
			array( 'test_id' => absint( $test_id ) ),
			array( '%d' )
		);
	}


	/**
	 * Determine whether global Traffic Behavior spam exclusion is enabled.
	 *
	 * Missing settings intentionally default to enabled because the settings UI
	 * renders Spam Traffic Detection as enabled by default.
	 *
	 * @return bool
	 */
	private static function get_global_spam_exclusion_default() {
		$settings = get_option( 'opti_behavior_traffic_settings', array() );

		if ( ! is_array( $settings ) ) {
			return true;
		}

		return ! array_key_exists( 'spam_detection_enabled', $settings ) || ! empty( $settings['spam_detection_enabled'] );
	}

	/**
	 * Build the A/B table spam exclusion clause for tables that store session_id.
	 *
	 * @param string    $table_alias SQL alias for the A/B events table.
	 * @param bool|null $exclude_spam Optional spam filter override.
	 * @return string SQL fragment beginning with AND, or an empty string.
	 */
	public static function get_ab_spam_session_filter_sql( $table_alias, $exclude_spam = null ) {
		global $wpdb;

		$should_exclude_spam = null === $exclude_spam ? self::get_global_spam_exclusion_default() : (bool) $exclude_spam;

		if ( ! $should_exclude_spam ) {
			return '';
		}

		$settings  = get_option( 'opti_behavior_traffic_settings', array() );
		$threshold = 3;

		if ( is_array( $settings ) && isset( $settings['spam_duration_threshold'] ) ) {
			$threshold = max( 0, absint( $settings['spam_duration_threshold'] ) );
		}

		$alias          = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table_alias );
		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		$event_session  = "{$alias}.session_id";
		$event_visitor  = "{$alias}.visitor_id";
		$event_created  = "{$alias}.created_at";
		$traffic_sql    = "(s.traffic_type IS NULL OR s.traffic_type = '' OR s.traffic_type NOT IN ('spam', 'bot', 'automated'))";
		$duration_sql   = '(CASE WHEN s.duration > 0 THEN s.duration ELSE TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time)) END)';
		$policy_sql     = "{$traffic_sql} AND {$duration_sql} >= {$threshold}";

		return " AND (
			EXISTS (
				SELECT 1
				FROM {$sessions_table} s
				WHERE s.id = {$event_session}
				  AND {$policy_sql}
			)
			OR (
				({$event_session} IS NULL OR {$event_session} = '')
				AND NOT EXISTS (
					SELECT 1
					FROM {$sessions_table} s_any
					WHERE s_any.visitor_id = {$event_visitor}
					  AND s_any.start_time <= {$event_created}
				)
			)
			OR (
				({$event_session} IS NULL OR {$event_session} = '' OR NOT EXISTS (
					SELECT 1
					FROM {$sessions_table} s_known
					WHERE s_known.id = {$event_session}
				))
				AND EXISTS (
					SELECT 1
					FROM {$sessions_table} s
					WHERE s.id = (
						SELECT s_latest.id
						FROM {$sessions_table} s_latest
						WHERE s_latest.visitor_id = {$event_visitor}
						  AND s_latest.start_time <= {$event_created}
						ORDER BY s_latest.start_time DESC, s_latest.id DESC
						LIMIT 1
					)
					  AND {$policy_sql}
				)
			)
		)";
	}

	// =========================================================================
	// Additional helper methods for Daily Stats
	// =========================================================================

	/**
	 * Delete all daily_stats rows for a test.
	 *
	 * @since 1.3.0
	 * @param int $test_id Test ID.
	 * @return int|false Rows deleted or false.
	 */
	public static function delete_daily_stats_for_test( $test_id ) {
		global $wpdb;
		return $wpdb->delete(
			$wpdb->prefix . 'optibehavior_ab_daily_stats',
			array( 'test_id' => absint( $test_id ) ),
			array( '%d' )
		);
	}
}
