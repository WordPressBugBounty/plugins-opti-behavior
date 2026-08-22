<?php
/**
 * Dashboard Class
 *
 * Modern React-based dashboard for advanced analytics and session recording.
 * Updated: 2025-12-29 - Fixed session counting bug
 *
 * @package opti-behavior
 * @copyright 2025 OptiUser
 * @version 1.0.4
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard SQL fragments are built from internal allow-lists, $wpdb->prefix tables, and prepared placeholders.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Load view helper functions.
require_once OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'views/dashboard-views.php';
require_once OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'views/sessions-views.php';
require_once OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'views/heatmaps-views.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-sessions-views.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-heatmaps-views.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-dashboard-views.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-ajax-handlers.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-data-helpers.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-assets.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-exports.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-maintenance.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-settings-views.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-ai-insights-views.php';

/**
 * Dashboard Class
 *
 * Provides modern dashboard functionality with analytics and session recording.
 *
 * @since 1.0.0
 */
class Opti_Behavior_Heatmap_Dashboard {
	use Opti_Behavior_Sessions_Views_Trait;
	use Opti_Behavior_Dashboard_Views_Trait;
	use Opti_Behavior_Heatmaps_Views_Trait;
	use Opti_Behavior_Ajax_Handlers_Trait;
	use Opti_Behavior_Data_Helpers_Trait;
	use Opti_Behavior_Assets_Trait;
	use Opti_Behavior_Settings_Views_Trait;
	use Opti_Behavior_Exports_Trait;
	use Opti_Behavior_Maintenance_Trait;
	use Opti_Behavior_AI_Insights_Views_Trait;



	/**
	 * opti-behavior Heatmap instance
	 *
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $heatmap;

	/**
	 * Funnel page instance
	 *
	 * @var Opti_Behavior_Funnel_Page
	 */
	private $funnel_page;

	/**
	 * Smart Insights admin page instance.
	 *
	 * @var Opti_Behavior_Smart_Insights_Page|null
	 */
	private $smart_insights_page = null;

	/**
	 * Request-scoped memo caches for the heatmap file-scan helpers.
	 *
	 * The Heatmaps list/stats path resolves the same page directories, spam
	 * session lookups and JSON point counts many times within a single request
	 * (stats runs ~6 aggregate passes; the table runs an all-pages pass plus
	 * per-device passes). Memoizing collapses that to a single computation per
	 * unique input without changing any value.
	 *
	 * @var array
	 */
	private $hm_dirs_memo = array();
	private $hm_allowed_sessions_memo = array();
	/**
	 * Per-page "known DB session" lookup memo (RC-C orphan gating). Value is
	 * either a 3-form lookup array (same key shape as $hm_allowed_sessions_memo)
	 * or the boolean `true` sentinel meaning "resolver could not restrict
	 * safely — fail-safe, treat every file token as a known DB session".
	 *
	 * @var array
	 */
	private $hm_known_sessions_memo = array();
	private $hm_points_memo = array();
	private $hm_metrics_memo = array();
	private $hm_recording_memo = array();
	private $hm_canonical_memo = array();
	private $hm_all_visitors_db_memo = array();
	private $hm_reset_ts_memo = null;
	private $hm_page_base_url_memo = array();
	private $hm_page_file_tokens_memo = array();
	private $hm_session_index_memo = array();
	private $hm_glob_memo = array();
	private $hm_dated_fast_memo = array();

	/**
	 * Request-scoped memo of {@see prepare_fast_heatmap_alltime_index()}:
	 * false = not computed yet; null = not applicable; array = prep result.
	 * Prevents the bounded inline sync from running twice when the SQL-paged
	 * reader declines and the in-memory fast path follows (Task 3).
	 *
	 * @var false|null|array
	 */
	private $hm_alltime_prep_memo = false;

	/**
	 * Request-scoped cache for "do the Phase 3 aggregate columns exist?".
	 *
	 * Resolved once per request via INFORMATION_SCHEMA so the indexed fast path
	 * can cheaply decide whether the precomputed columns are available.
	 *
	 * @var bool|null
	 */
	private static $hm_agg_cols_exist = null;

	/**
	 * Whether the per-day heatmap index (daily table + daily_* marker columns)
	 * is available. Resolved once per request (spec §P1).
	 *
	 * @var bool|null
	 */
	private static $hm_daily_index_available = null;

	/**
	 * Background heatmap DB/file sync reconciliation (spec §3b/§3c).
	 *
	 * A recurring WP-Cron job populates the {@see self::HEATMAP_SYNC_TRANSIENT}
	 * cache in small resumable batches so Opti_Behavior_Heatmap_Ajax's
	 * get_session_counts_with_sync_status() always has a recent (<= TTL)
	 * file-derived count to compare against the DB total, without ever
	 * scanning the filesystem on the count-read hot path.
	 *
	 * @since 1.8.x
	 */
	const HEATMAP_SYNC_CRON_HOOK     = 'opti_behavior_heatmap_sync_reconcile';
	const HEATMAP_SYNC_CURSOR_OPTION = 'opti_behavior_heatmap_sync_cursor';
	const HEATMAP_SYNC_TRANSIENT     = 'opti_behavior_heatmap_sync_status';
	const HEATMAP_SYNC_LOCK_NAME     = 'opti_behavior_heatmap_sync_reconcile';
	const HEATMAP_SYNC_BATCH_SIZE    = 25;

	/**
	 * Scheduled heatmap sync auto-repair (Danger Zone toggle, default ON).
	 *
	 * A daily WP-Cron event that runs the SAME repair pass as the manual
	 * per-row/global "Repair" button ({@see self::repair_heatmap_sync_for_pages()}):
	 * refresh the {@see self::HEATMAP_SYNC_TRANSIENT} cache and invalidate stale
	 * agg_* aggregate columns for every mapped page, in chunked cursor-resumable
	 * batches under the shared advisory lock. NEVER deletes JSON heatmap files
	 * or DB rows. Opt-out via the Danger Zone "Auto-repair heatmap sync stats
	 * daily" toggle (option absent == enabled).
	 *
	 * @since 1.8.x
	 */
	const HEATMAP_AUTO_REPAIR_CRON_HOOK     = 'opti_behavior_heatmap_auto_repair';
	const HEATMAP_AUTO_REPAIR_OPTION        = 'opti_behavior_heatmap_auto_repair_enabled';
	const HEATMAP_AUTO_REPAIR_CURSOR_OPTION = 'opti_behavior_heatmap_auto_repair_cursor';

	/**
	 * Unix timestamp of the last COMPLETED auto-repair pass (autoload-off
	 * option). Guards against back-to-back full passes: when a pass finishes
	 * (cursor reset to 0), stale queued one-off continuation events must not
	 * immediately start ANOTHER full pass (observed live 2026-08-16 — chained
	 * passes kept the whole pages table churning). A FRESH pass (cursor == 0)
	 * is skipped while the previous completion is younger than the filterable
	 * `opti_behavior_heatmap_auto_repair_min_interval` (default 1 hour);
	 * mid-pass continuations (cursor > 0) are never affected.
	 *
	 * @since 1.9.x
	 */
	const HEATMAP_AUTO_REPAIR_LAST_COMPLETE_OPTION = 'opti_behavior_heatmap_auto_repair_last_complete';

	/**
	 * Background warm-up of the heatmap KPI stats payload (Task 4 SWR + cron):
	 * one-off event per spam state, fired when a WEB stats request at scale hit
	 * a cold view it is forbidden to compute inline (never-inline-full-scan).
	 *
	 * @since 1.9.x
	 */
	const HEATMAP_STATS_WARM_CRON_HOOK = 'opti_behavior_heatmap_stats_warm';

	/**
	 * Per-day-index drift reconciler (spec P4.2): recurring cron hook and
	 * the rotating sweep cursor (last inspected page_id).
	 *
	 * @since 1.9.x
	 */
	const HEATMAP_RECONCILE_CRON_HOOK     = 'opti_behavior_heatmap_reconcile';
	const HEATMAP_RECONCILE_CURSOR_OPTION = 'opti_behavior_heatmap_reconcile_cursor';

	/**
	 * Events-based heatmap registry backfill (DB-sourced, conflict-safe).
	 *
	 * Heals the documented shutdown-flush desync: pages that have durable
	 * heatmap-interaction rows in optibehavior_events (event codes
	 * 16/17/32/33/48/49) with a surviving, non-spam session but NO
	 * optibehavior_heatmap_pages registry row — so they are permanently invisible
	 * on admin.php?page=opti-behavior-heatmaps. A batched, cursor-resumable
	 * WP-Cron job (~200 pages/run) inserts the missing registry row with counts
	 * derived from the events table itself. The DATABASE is the sole source of
	 * truth — disk-only orphan directories (files with no surviving session) are
	 * NEVER backfilled, so Smart-Cleanup/spam-deleted data can never be
	 * resurrected. Runs under the shared HEATMAP_SYNC_LOCK_NAME advisory lock so
	 * it never overlaps a reconcile/repair pass. Frequent 1-minute continuations
	 * catch up the initial backlog; once caught up the daily recurrence is a
	 * light watchdog that exits after a single fast query.
	 *
	 * @since 1.9.x
	 */
	const HEATMAP_BACKFILL_CRON_HOOK     = 'opti_behavior_heatmap_registry_backfill';
	const HEATMAP_BACKFILL_CURSOR_OPTION = 'opti_behavior_heatmap_backfill_cursor';
	const HEATMAP_BACKFILL_DONE_OPTION   = 'opti_behavior_heatmap_backfill_complete';
	const HEATMAP_BACKFILL_BATCH_SIZE    = 200;

	/**
	 * File-first heatmap registry rebuild (Option A, 2026-08-15 remediation).
	 *
	 * ADMIN-TRIGGERED ONLY — there is deliberately NO recurring schedule. The
	 * Danger Zone "Rebuild Heatmap Registry From Files" button schedules a
	 * single one-off event on this hook; each tick runs ONE bounded batch of
	 * {@see Opti_Behavior_Heatmap_Registry_Rebuild::run_batch()} (~20 s budget,
	 * <=100 dirs) and self-reschedules a 1-minute continuation while work
	 * remains (mirrors the auto-repair continuation pattern), so sites with
	 * millions of JSON files never block an admin page load or a cron worker.
	 * Progress is read from the rebuild service's progress OPTION only — the
	 * poll AJAX endpoint never touches the filesystem.
	 *
	 * @since 2026-08-15 (registry rebuild from files, Option A)
	 */
	const HEATMAP_REBUILD_CRON_HOOK = 'opti_behavior_heatmap_registry_rebuild_files';

	/**
	 * Product auto-rebuild (zero-touch for customers, 2026-08-15).
	 *
	 * The plugin is a paid product — customers will not open Danger Zone or
	 * follow deployment instructions, so the registry rebuild must trigger
	 * itself. Two automatic triggers reuse the EXISTING rebuild chain above
	 * (idempotent: a second full pass inserts 0 rows):
	 *
	 *  1. One-shot migration ({@see self::maybe_schedule_heatmap_rebuild_migration()}):
	 *     on the first load after upgrading to a build that ships the rebuild,
	 *     the chain is scheduled IMMEDIATELY (not delayed) so it runs before
	 *     daily crons can age out spam-session verdicts (delete_old_data()
	 *     turns known-spam tokens into unknown tokens, which the rebuild must
	 *     then treat as legitimate). Guarded by the autoload-off flag option
	 *     below (value = plugin version that performed the migration).
	 *  2. Weekly self-heal divergence probe
	 *     ({@see self::maybe_run_heatmap_rebuild_divergence_probe()}): a
	 *     lightweight readdir count of root hash dirs (hard cap
	 *     {@see self::HEATMAP_REBUILD_PROBE_DIR_CAP}, then stop) compared
	 *     against the dir count recorded by the last COMPLETED rebuild pass
	 *     ({@see Opti_Behavior_Heatmap_Registry_Rebuild::PROBE_BASELINE_OPTION},
	 *     growth ratio filterable, default 3x) — or, while no pass has ever
	 *     completed, against the registry row count (divergence ratio
	 *     filterable, default 3x). When exceeded, the rebuild is scheduled
	 *     with a +6 h offset, outside the busy 3-5 AM cron window. Piggybacks
	 *     on the always-scheduled sync-reconcile cron — NO new recurring
	 *     schedule.
	 *
	 * @since 2026-08-15 (product auto-rebuild)
	 */
	const HEATMAP_REBUILD_MIGRATION_OPTION = 'opti_behavior_heatmap_rebuild_migration_done';
	const HEATMAP_REBUILD_PROBE_OPTION     = 'opti_behavior_heatmap_rebuild_probe_last_run';
	const HEATMAP_REBUILD_PROBE_DIR_CAP    = 500;

	/**
	 * `_orphaned/` archive dry-run REPORT (Option C phase 1, 2026-08-15).
	 *
	 * ADMIN-TRIGGERED ONLY, REPORT-ONLY — same tick/continuation shape as the
	 * registry rebuild above, but the pass NEVER writes to any table and NEVER
	 * moves/restores any file: it only inventories the `_orphaned/` archive
	 * (eligible-for-restore dirs, URLs, session counts, unknown-token share)
	 * into a progress option the poll endpoint reads. The restore action is a
	 * deliberately separate, deferred, opt-in follow-up.
	 *
	 * @since 2026-08-15 (`_orphaned/` dry-run report, Option C phase 1)
	 */
	const HEATMAP_ORPHAN_REPORT_CRON_HOOK = 'opti_behavior_heatmap_orphan_report_scan';

	/**
	 * `_orphaned/` archive retention PURGE (unbounded-growth fix, 2026-08-15).
	 *
	 * RECURRING DAILY (auto-protection, not admin-triggered) — unlike the
	 * rebuild/report tools above. Each tick runs ONE bounded batch of
	 * {@see Opti_Behavior_Heatmap_Orphan_Purge::run_batch()} (~20 s budget,
	 * <=100 dirs) permanently deleting archive dirs older than the retention
	 * window (default 90 days; option + filter; 0 disables), and
	 * self-reschedules 1-minute continuations while work remains so a large
	 * eligible set never exceeds one tick budget. Dirs younger than the
	 * window are never touched (restore window for Option C phase 2); only
	 * `{32-hex}` / `{32-hex}-YmdHis` names are ever considered.
	 *
	 * @since 2026-08-15 (`_orphaned/` retention purge)
	 */
	const HEATMAP_ORPHAN_PURGE_CRON_HOOK = 'opti_behavior_heatmap_orphan_purge_daily';

	/**
	 * Constructor
	 *
	 * @param Opti_Behavior_Heatmap_Core $heatmap opti-behavior Heatmap instance.
	 */
	public function __construct( $heatmap ) {
		$this->heatmap = $heatmap;
		$this->init_hooks();
		// Funnel page is now initialized globally in Core class (needed for frontend tracking)
		$this->funnel_page = $this->heatmap->get_funnel_page();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// WP-Cron worker that resyncs stale heatmap aggregate columns off the
		// request path (Perf RC2). Registered unconditionally so it also runs
		// on DOING_CRON requests (Core instantiates the dashboard for cron).
		add_action( 'opti_behavior_heatmap_agg_sync', array( $this, 'cron_sync_heatmap_aggregates' ) );

		// WP-Cron worker for the per-day heatmap index (spec §P1): filename-only
		// re-derivation of stale pages' daily rows. Registered unconditionally so
		// it also runs on DOING_CRON requests (same as the agg-sync hook above).
		add_action( 'opti_behavior_heatmap_daily_sync', array( $this, 'cron_sync_heatmap_daily_index' ) );

		// WP-Cron worker for the KPI stats warm-up (Task 4 SWR + cron): the ONE
		// context where the exact full stats aggregation may run at scale.
		// Registered unconditionally so it also runs on DOING_CRON requests.
		add_action( self::HEATMAP_STATS_WARM_CRON_HOOK, array( $this, 'run_heatmap_stats_warm' ) );

		// WP-Cron worker for the per-day-index drift reconciler (spec P4.2):
		// detects file deletions the immediate hooks could not see (manual
		// FTP/shell removal, vanished url_hash dirs) from cheap dir metadata.
		// Registered unconditionally so it also runs on DOING_CRON requests.
		add_action( self::HEATMAP_RECONCILE_CRON_HOOK, array( $this, 'cron_reconcile_heatmap_daily_index' ) );

		// Background DB/file sync-status reconciliation cache (spec §3b).
		// Registered unconditionally so it also runs on DOING_CRON requests
		// (Core instantiates the dashboard for cron, same as the agg-sync hook above).
		add_action( self::HEATMAP_SYNC_CRON_HOOK, array( $this, 'run_heatmap_sync_reconcile_batch' ) );
		// Daily scheduled auto-repair (Danger Zone toggle, default ON) — routes
		// to the same repair implementation as the manual Repair AJAX handler.
		// Registered unconditionally so it also runs on DOING_CRON requests.
		add_action( self::HEATMAP_AUTO_REPAIR_CRON_HOOK, array( $this, 'run_heatmap_auto_repair' ) );
		// Events-based registry backfill (DB-sourced): heals the shutdown-flush
		// desync by creating the missing optibehavior_heatmap_pages rows so
		// orphaned-but-durable interactions become visible on the Heatmaps list.
		// Registered unconditionally so it also runs on DOING_CRON requests.
		add_action( self::HEATMAP_BACKFILL_CRON_HOOK, array( $this, 'run_heatmap_registry_backfill' ) );
		// File-first registry rebuild (Option A): admin-triggered one-off cron
		// ticks with 1-minute self-rescheduled continuations — NO recurring
		// schedule. Registered unconditionally so it runs on DOING_CRON requests.
		add_action( self::HEATMAP_REBUILD_CRON_HOOK, array( $this, 'run_heatmap_registry_rebuild_tick' ) );
		// `_orphaned/` dry-run report (Option C phase 1): admin-triggered,
		// report-only one-off cron ticks with self-rescheduled continuations.
		// Registered unconditionally so it runs on DOING_CRON requests.
		add_action( self::HEATMAP_ORPHAN_REPORT_CRON_HOOK, array( $this, 'run_heatmap_orphan_report_tick' ) );
		// Manual "Repair" — Option A: recompute the sync-status cache only,
		// never restores/reconstructs deleted analytics rows (spec §3c).
		add_action( 'wp_ajax_opti_behavior_repair_heatmap_sync', array( $this, 'ajax_repair_heatmap_sync' ) );
		// File-first registry rebuild AJAX: start / poll progress / pause-resume-
		// abort. Poll reads the progress OPTION only (never scans the filesystem).
		add_action( 'wp_ajax_optibehavior_heatmap_rebuild_start', array( $this, 'ajax_heatmap_rebuild_start' ) );
		add_action( 'wp_ajax_optibehavior_heatmap_rebuild_progress', array( $this, 'ajax_heatmap_rebuild_progress' ) );
		add_action( 'wp_ajax_optibehavior_heatmap_rebuild_control', array( $this, 'ajax_heatmap_rebuild_control' ) );
		// `_orphaned/` dry-run report AJAX: start / poll progress / pause-
		// resume-abort. Poll reads the progress OPTION only (never scans disk).
		add_action( 'wp_ajax_optibehavior_heatmap_orphan_report_start', array( $this, 'ajax_heatmap_orphan_report_start' ) );
		add_action( 'wp_ajax_optibehavior_heatmap_orphan_report_progress', array( $this, 'ajax_heatmap_orphan_report_progress' ) );
		add_action( 'wp_ajax_optibehavior_heatmap_orphan_report_control', array( $this, 'ajax_heatmap_orphan_report_control' ) );
		// `_orphaned/` retention purge: recurring DAILY auto-protection ticks
		// with 1-minute self-rescheduled continuations while work remains.
		// Registered unconditionally so it runs on DOING_CRON requests.
		add_action( self::HEATMAP_ORPHAN_PURGE_CRON_HOOK, array( $this, 'run_heatmap_orphan_purge_tick' ) );
		// `_orphaned/` retention setting save (Danger Zone widget): cheap
		// option write + cron self-heal only.
		add_action( 'wp_ajax_optibehavior_heatmap_orphan_purge_save', array( $this, 'ajax_heatmap_orphan_purge_save' ) );
		// Phase C recovery tooling (2026-08-16 incident): batched, cursor-
		// resumable client-driven loops — spam-flag re-evaluation and
		// `_orphaned/` archive restore.
		add_action( 'wp_ajax_optibehavior_reevaluate_spam_flags', array( $this, 'ajax_reevaluate_spam_flags' ) );
		add_action( 'wp_ajax_optibehavior_heatmap_orphan_restore_batch', array( $this, 'ajax_heatmap_orphan_restore_batch' ) );
		// Unified Retention Protocol master setting save (Danger Zone widget):
		// cheap option write only.
		add_action( 'wp_ajax_optibehavior_data_retention_save', array( $this, 'ajax_data_retention_save' ) );

		add_action( 'admin_menu', array( $this, 'add_dashboard_menu' ), 10 );
		add_action( 'admin_menu', array( $this, 'add_ai_insights_menu' ), 15 );
		add_action( 'admin_menu', array( $this, 'add_settings_menu' ), 20 );
		add_action( 'admin_menu', array( $this, 'reorder_submenu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_assets' ) );
		// Discreet auto-rebuild status ("in progress / done") on plugin admin
		// pages only — one autoload-off option read, nothing else.
		add_action( 'admin_notices', array( $this, 'maybe_render_heatmap_rebuild_status_notice' ) );
		add_action( 'admin_footer', array( $this, 'render_smart_insights_notifications_root' ) );
		add_filter( 'script_loader_tag', array( $this, 'filter_script_loader_tag' ), 10, 3 );
		add_action( 'wp_ajax_optibehavior_dashboard_data', array( $this, 'ajax_dashboard_data' ) );
		add_action( 'wp_ajax_opti_behavior_get_dashboard_filter_options', array( $this, 'ajax_get_dashboard_filter_options' ) );
		add_action( 'wp_ajax_optibehavior_session_data', array( $this, 'ajax_session_data' ) );
		add_action( 'wp_ajax_optibehavior_analytics_data', array( $this, 'ajax_analytics_data' ) );
		add_action( 'wp_ajax_optibehavior_top_users', array( $this, 'ajax_top_users' ) );
		add_action( 'wp_ajax_optibehavior_visitor_heatmap', array( $this, 'ajax_visitor_heatmap' ) );
		// Spam recalculation AJAX handlers
		add_action( 'wp_ajax_optibehavior_recalculate_spam', array( $this, 'ajax_recalculate_spam' ) );
		add_action( 'wp_ajax_optibehavior_recalculate_spam_status', array( $this, 'ajax_recalculate_spam_status' ) );
		// Delete all data AJAX handler
		add_action( 'wp_ajax_optibehavior_delete_all_data', array( $this, 'ajax_delete_all_data' ) );
		// Danger Zone per-category storage sizes (async, cached)
		add_action( 'wp_ajax_optibehavior_get_danger_zone_sizes', array( $this, 'ajax_get_danger_zone_sizes' ) );
		// Storage Stats tab async endpoints (table overview, batched exact counts, file scan)
		add_action( 'wp_ajax_optibehavior_storage_stats_tables', array( $this, 'ajax_storage_stats_tables' ) );
		add_action( 'wp_ajax_optibehavior_storage_stats_counts', array( $this, 'ajax_storage_stats_counts' ) );
		add_action( 'wp_ajax_optibehavior_storage_stats_files', array( $this, 'ajax_storage_stats_files' ) );
		// Delete data by date range AJAX handler
		add_action( 'wp_ajax_optibehavior_delete_data_by_range', array( $this, 'ajax_delete_data_by_range' ) );
		// Smart Data Cleanup AJAX handlers
		add_action( 'wp_ajax_optibehavior_smart_cleanup_preview', array( $this, 'ajax_smart_cleanup_preview' ) );
		add_action( 'wp_ajax_optibehavior_smart_cleanup_execute', array( $this, 'ajax_smart_cleanup_execute' ) );
		add_action( 'wp_ajax_optibehavior_bot_cleanup', array( $this, 'ajax_bot_cleanup' ) );
		add_action( 'wp_ajax_optibehavior_save_auto_cleanup', array( $this, 'ajax_save_auto_cleanup_settings' ) );
		// Suppress third-party admin notices on opti-behavior Analytics admin pages only
		add_action( 'admin_head', array( $this, 'suppress_admin_notices' ), 1 );
		// Add custom menu icon CSS
		add_action( 'admin_head', array( $this, 'add_menu_icon_css' ) );
		// AJAX endpoint for Heatmaps table (sorting/pagination)
		add_action( 'wp_ajax_optibehavior_heatmaps_sessions', array( $this, 'ajax_heatmaps_sessions' ) );
		add_action( 'wp_ajax_optibehavior_heatmaps_table', array( $this, 'ajax_heatmaps_table' ) );
		add_action( 'wp_ajax_optibehavior_heatmaps_stats', array( $this, 'ajax_heatmaps_stats' ) );
		// Debug log AJAX handlers
		add_action( 'wp_ajax_opti_behavior_get_debug_log', array( $this, 'ajax_get_debug_log' ) );
		add_action( 'admin_init', array( $this, 'handle_debug_log_download' ) );
		// Cleanup AJAX handlers
		add_action( 'wp_ajax_opti_behavior_cleanup_by_date', array( $this, 'ajax_cleanup_by_date' ) );
		add_action( 'wp_ajax_opti_behavior_cleanup_by_duration', array( $this, 'ajax_cleanup_by_duration' ) );
		add_action( 'wp_ajax_opti_behavior_cleanup_orphaned', array( $this, 'ajax_cleanup_orphaned' ) );
		// Maintenance AJAX handlers
		add_action( 'wp_ajax_opti_behavior_fix_android_os', array( $this, 'ajax_fix_android_os' ) );
		// Scheduled Reports AJAX handlers
		add_action( 'wp_ajax_optibehavior_get_schedule', array( $this, 'ajax_get_schedule' ) );
		add_action( 'wp_ajax_optibehavior_save_schedule', array( $this, 'ajax_save_schedule' ) );
		add_action( 'wp_ajax_optibehavior_toggle_schedule', array( $this, 'ajax_toggle_schedule' ) );
		add_action( 'wp_ajax_optibehavior_delete_schedule', array( $this, 'ajax_delete_schedule' ) );
		add_action( 'wp_ajax_optibehavior_test_report', array( $this, 'ajax_test_report' ) );
		add_action( 'wp_ajax_optibehavior_save_email_settings', array( $this, 'ajax_save_email_settings' ) );
		add_action( 'wp_ajax_optibehavior_test_email_connection', array( $this, 'ajax_test_email_connection' ) );
		// Smart Insights AJAX handlers.
		add_action( 'wp_ajax_optibehavior_smart_insights_list', array( $this, 'ajax_smart_insights_list' ) );
		add_action( 'wp_ajax_optibehavior_smart_insights_detail', array( $this, 'ajax_smart_insights_detail' ) );
		add_action( 'wp_ajax_optibehavior_smart_insights_refresh', array( $this, 'ajax_smart_insights_refresh' ) );
		add_action( 'wp_ajax_optibehavior_smart_insights_update_status', array( $this, 'ajax_smart_insights_update_status' ) );
		add_action( 'wp_ajax_optibehavior_smart_insights_summary', array( $this, 'ajax_smart_insights_summary' ) );
		add_action( 'wp_ajax_optibehavior_smart_insights_notifications', array( $this, 'ajax_smart_insights_notifications' ) );
		add_action( 'wp_ajax_optibehavior_smart_insights_notification_state', array( $this, 'ajax_smart_insights_notification_state' ) );
		// Export handlers (admin-post)
		add_action( 'admin_post_opti_behavior_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_opti_behavior_dashboard_export', array( $this, 'handle_dashboard_export' ) );
		add_action( 'admin_post_nopriv_opti_behavior_dashboard_export', array( $this, 'handle_dashboard_export' ) );
		// Throttled DB index enforcement for performance on admin pages
		add_action( 'admin_init', array( $this, 'maybe_ensure_db_indexes' ) );
		// Background refresh of the canonical-session overlay cache (C2-3
		// stale-while-revalidate): recomputes the grouped pageview-session map
		// in a cron tick so large installs never run it inline in a render.
		add_action( self::CANONLIST_REFRESH_CRON_HOOK, array( $this, 'run_canonlist_refresh' ), 10, 4 );
		// Throttled referrers backfill (parse utm_source from entry_page) — only on our analytics screens
		add_action( 'load-toplevel_page_opti-behavior-analytics', array( $this, 'maybe_backfill_referrers' ) );
		// Pro trial banner AJAX handlers
		add_action( 'wp_ajax_opti_behavior_start_pro_trial', array( $this, 'ajax_start_pro_trial' ) );
		add_action( 'wp_ajax_opti_behavior_dismiss_trial_banner', array( $this, 'ajax_dismiss_trial_banner' ) );
	}

		public function handle_export(){
			// Admin-post handler to stream CSV or SQL download
			if ( ! function_exists('current_user_can') || ! current_user_can('manage_options') ) { if (function_exists('status_header')) status_header(403); exit('Permission denied'); }
			if ( ! isset($_REQUEST['_wpnonce']) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'opti_behavior_export') ) { if (function_exists('status_header')) status_header(403); exit('Invalid nonce'); }
			$mode = isset($_REQUEST['mode']) ? sanitize_text_field( wp_unslash( $_REQUEST['mode'] ) ) : 'csv';
			// End all active output buffers to avoid stray output
			while (ob_get_level()) { ob_end_clean(); }
			if ( function_exists('nocache_headers') ) { nocache_headers(); }
			if ($mode === 'sql') { $this->export_analytics_sql(); } else { $this->export_analytics_csv(); }
			exit;
		}

		/**
		 * Handle dashboard report export downloads.
		 *
		 * Streams safe dashboard-level CSV/JSON exports for administrators.
		 *
		 * @since 1.2.8
		 */
		public function handle_dashboard_export() {
			if ( ! current_user_can( 'manage_options' ) ) {
				if ( function_exists( 'status_header' ) ) {
					status_header( 403 );
				}
				wp_die( esc_html__( 'Permission denied', 'opti-behavior' ), '', array( 'response' => 403 ) );
			}

			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'opti_behavior_dashboard_export' ) ) {
				if ( function_exists( 'status_header' ) ) {
					status_header( 403 );
				}
				wp_die( esc_html__( 'Invalid nonce', 'opti-behavior' ), '', array( 'response' => 403 ) );
			}

			$exporter = new Opti_Behavior_Dashboard_Exporter();
			$exporter->stream( $_REQUEST );
			exit;
		}



		/**
		 * Suppress third-party admin notices on opti-behavior Analytics pages
		 */
		public function suppress_admin_notices() {
		return $this->suppress_admin_notices_impl();
	}

	/**
	 * AJAX: Start Pro free trial.
	 *
	 * @since 1.1.3
	 */
	public function ajax_start_pro_trial() {
		return $this->ajax_start_pro_trial_impl();
	}

	/**
	 * AJAX: Dismiss trial banner.
	 *
	 * @since 1.1.3
	 */
	public function ajax_dismiss_trial_banner() {
		return $this->ajax_dismiss_trial_banner_impl();
	}


	/**
	 * Add dashboard menu
	 */
	public function add_dashboard_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Use custom PNG icon (WordPress handles PNG better than ICO for menu icons)
		$icon_url = plugins_url( 'assets/images/256x256.png', dirname( __FILE__ ) );

		add_menu_page(
			__( 'Opti-Behavior ', 'opti-behavior' ),
			__( 'Opti-Behavior ', 'opti-behavior' ),
			'manage_options',
			'opti-behavior-analytics',
			array( $this, 'render_dashboard' ),
			$icon_url,
			30
		);

		// Lucide "layout-dashboard" icon (20x20)
		$dashboard_icon = '<span style="display:inline-flex;align-items:center;margin-right:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg></span>';
		add_submenu_page(
			'opti-behavior-analytics',
			__( 'Dashboard', 'opti-behavior' ),
			$dashboard_icon . __( 'Dashboard', 'opti-behavior' ),
			'manage_options',
			'opti-behavior-analytics',
			array( $this, 'render_dashboard' )
		);

		// Lucide "lightbulb" icon for Smart Insights (20x20).
		$smart_insights_icon = '<span style="display:inline-flex;align-items:center;margin-right:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 14c.2-1 .7-1.7 1.5-2.5A4.8 4.8 0 0 0 18 8 6 6 0 0 0 6 8c0 1.3.5 2.5 1.5 3.5.8.8 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/></svg></span>';
		add_submenu_page(
			'opti-behavior-analytics',
			__( 'Smart Insights', 'opti-behavior' ),
			$smart_insights_icon . __( 'Insights', 'opti-behavior' ),
			'manage_options',
			'opti-behavior-smart-insights',
			array( $this, 'render_smart_insights' )
		);

		// Lucide "flame" icon for Heatmaps (20x20)
		$heatmaps_icon = '<span style="display:inline-flex;align-items:center;margin-right:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg></span>';
		add_submenu_page(
			'opti-behavior-analytics',
			__( 'Heatmaps', 'opti-behavior' ),
			$heatmaps_icon . __( 'Heatmaps', 'opti-behavior' ),
			'manage_options',
			'opti-behavior-heatmaps',
			array( $this, 'render_heatmaps' )
		);

		// Lucide "video" icon for Recordings (20x20) — placed before Funnels for logical flow
		$recordings_icon = '<span style="display:inline-flex;align-items:center;margin-right:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg></span>';
		$recordings_pro_badge = '';
		if ( ! $this->pro_feature_pages_available() ) {
			$recordings_pro_badge = '<span class="opti-menu-pro-badge">PRO</span>';
		}
		// Session Recordings menu - always visible
		// If PRO is not active, shows upgrade page
		// If PRO is active, PRO plugin will override this with actual recordings page
		add_submenu_page(
			'opti-behavior-analytics',
			__( 'Session Recordings', 'opti-behavior' ),
			$recordings_icon . __( 'Recordings', 'opti-behavior' ) . $recordings_pro_badge,
			'manage_options',
			'opti-behavior-recordings',
			array( $this, 'render_recordings_upgrade' )
		);

		// Lucide "filter" icon for Funnels (20x20)
		$funnels_icon = '<span style="display:inline-flex;align-items:center;margin-right:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg></span>';
		// Funnel Analytics menu
		add_submenu_page(
			'opti-behavior-analytics',
			__( 'Funnel Analytics', 'opti-behavior' ),
			$funnels_icon . __( 'Funnels', 'opti-behavior' ),
			'manage_options',
			'opti-behavior-funnels',
			array( $this->funnel_page, 'render_funnels' )
		);

		// PRO feature menus: User Journeys, Errors Tracking, Form Analytics
		// Show upgrade placeholder pages whenever the PRO plugin's page classes
		// will NOT register their own menus (they register at priority 13).
		// pro_feature_pages_available() mirrors the exact gate the PRO plugin
		// uses (per-feature guard, domain-bound token), so these menu items can
		// never silently disappear: either the real PRO page registers, or the
		// upgrade placeholder does.
		$show_pro_upgrade_menus = ! $this->pro_feature_pages_available();

		if ( $show_pro_upgrade_menus ) {
			// User Journeys menu - placeholder when PRO is not active
			$journey_icon = '<span style="display:inline-flex;align-items:center;margin-right:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" x2="6" y1="3" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/></svg></span>';
			add_submenu_page(
				'opti-behavior-analytics',
				__( 'User Journeys', 'opti-behavior' ),
				$journey_icon . __( 'User Journeys', 'opti-behavior' ) . '<span class="opti-menu-pro-badge">PRO</span>',
				'manage_options',
				'opti-behavior-user-journey',
				array( $this, 'render_user_journey_upgrade' )
			);

			$errors_icon = '<span style="display:inline-flex;align-items:center;margin-right:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg></span>';
			add_submenu_page(
				'opti-behavior-analytics',
				__( 'Errors Tracking', 'opti-behavior' ),
				$errors_icon . __( 'Errors Tracking', 'opti-behavior' ) . '<span class="opti-menu-pro-badge">PRO</span>',
				'manage_options',
				'opti-behavior-errors',
				array( $this, 'render_errors_upgrade' )
			);

			// Form Analytics menu - placeholder when PRO is not active
			$form_icon = '<span style="display:inline-flex;align-items:center;margin-right:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h14a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v4"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M2 15h10"/><path d="m9 18 3-3-3-3"/></svg></span>';
			add_submenu_page(
				'opti-behavior-analytics',
				__( 'Form Analytics', 'opti-behavior' ),
				$form_icon . __( 'Forms', 'opti-behavior' ) . '<span class="opti-menu-pro-badge">PRO</span>',
				'manage_options',
				'opti-behavior-form-analytics',
				array( $this, 'render_form_analytics_upgrade' )
			);
		}

	}

	/**
	 * Predict whether the PRO plugin will register its own feature pages
	 * (Recordings, User Journeys, Errors Tracking, Form Analytics).
	 *
	 * Mirrors the exact gate used by Opti_Behavior_Pro_Core::init_modules():
	 * PRO pages register only when the per-feature guard grants at least one
	 * protected feature. The guard is stricter than the manifest gate
	 * (opti_behavior_pro_validate_env()) because the Pro access token is
	 * domain-bound — on cloned/staging/migrated sites the manifest gate can
	 * pass while every feature is denied. Checking only the manifest gate
	 * made the three menu items silently vanish in that state; this check
	 * guarantees the upgrade placeholders appear whenever the real PRO pages
	 * won't register.
	 *
	 * @return bool True when the PRO feature pages will register their menus.
	 */
	private function pro_feature_pages_available() {
		if ( ! function_exists( 'opti_behavior_pro_active' ) || ! opti_behavior_pro_active() ) {
			return false;
		}
		if ( ! function_exists( 'opti_behavior_pro_validate_env' ) || ! opti_behavior_pro_validate_env() ) {
			return false;
		}
		if ( ! class_exists( 'Opti_Behavior_Pro_Feature_Guard' ) ) {
			return false;
		}

		// Same feature list and OR-logic as the PRO plugin's init_modules() gate.
		$features = array(
			'recordings',
			'error_tracking',
			'form_analytics',
			'user_journey',
			'ab_testing_pro',
			'woocommerce_ab_testing',
			'smart_insights',
		);

		try {
			foreach ( $features as $feature ) {
				if ( Opti_Behavior_Pro_Feature_Guard::can_access( $feature ) ) {
					return true;
				}
			}
		} catch ( Throwable $e ) {
			return false;
		}

		return false;
	}

	/**
	 * Add Settings menu
	 */
	public function add_settings_menu() {
		// Lucide "settings" icon (20x20)
		$settings_icon = '<span style="display:inline-flex;align-items:center;margin-right:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg></span>';
		add_submenu_page(
			'opti-behavior-analytics',
			__( 'Settings', 'opti-behavior' ),
			$settings_icon . __( 'Settings', 'opti-behavior' ),
			'manage_options',
			'opti-behavior-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Add Roadmap menu
	 */
	public function add_ai_insights_menu() {
		// Lucide "sparkles" icon for the roadmap item (20x20)
		$ai_icon = '<span style="display:inline-flex;align-items:center;margin-right:6px;"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/></svg></span>';
		add_submenu_page(
			'opti-behavior-analytics',
			__( 'Roadmap', 'opti-behavior' ),
			$ai_icon . __( 'Roadmap', 'opti-behavior' ),
			'manage_options',
			'opti-behavior-ai-insights',
			array( $this, 'render_ai_insights' )
		);
	}

	/**
	 * Reorder Opti-Behavior submenu items for a logical flow.
	 * Runs at priority 999 so all plugins (free + pro) have registered their items.
	 *
	 * Target order:
	 * Dashboard -> Heatmaps -> Recordings -> Funnels -> A/B Testing ->
	 * User Journeys -> Errors Tracking -> Forms -> Smart Insights ->
	 * Settings -> Roadmap
	 */
	public function reorder_submenu() {
		global $submenu;
		if ( ! isset( $submenu['opti-behavior-analytics'] ) ) {
			return;
		}

		$items = $submenu['opti-behavior-analytics'];

		// Desired slug order
		$slug_order = array(
			'opti-behavior-analytics',       // Dashboard
			'opti-behavior-heatmaps',        // Heatmaps
			'opti-behavior-recordings',      // Recordings
			'opti-behavior-funnels',         // Funnels
			'opti-behavior-ab-testing',      // A/B Testing
			'opti-behavior-user-journey',    // User Journeys
			'opti-behavior-errors',          // Errors Tracking
			'opti-behavior-form-analytics',  // Forms
			'opti-behavior-smart-insights',  // Smart Insights
			'opti-behavior-settings',        // Settings
			'opti-behavior-ai-insights',     // Roadmap (last)
		);

		$ordered  = array();
		$leftover = array();

		// Index items by slug (position [2] in the submenu array)
		$by_slug = array();
		foreach ( $items as $item ) {
			$slug = $item[2];
			$by_slug[ $slug ] = $item;
		}

		// Place items in desired order
		foreach ( $slug_order as $slug ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$ordered[] = $by_slug[ $slug ];
				unset( $by_slug[ $slug ] );
			}
		}

		// Append any remaining items (hidden pages, etc.)
		foreach ( $by_slug as $item ) {
			$ordered[] = $item;
		}

		$submenu['opti-behavior-analytics'] = $ordered; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Enqueue dashboard assets
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_dashboard_assets( $hook_suffix ) {
		return $this->enqueue_dashboard_assets_impl( $hook_suffix );
	}


		/**
		 * Ensure critical scripts are not delayed by Cloudflare Rocket Loader
		 */
		public function filter_script_loader_tag( $tag, $handle, $src ) {
		return $this->filter_script_loader_tag_impl( $tag, $handle, $src );
	}

	/**
	 * Add custom CSS for menu icon sizing
	 * Note: This is now handled in enqueue_dashboard_assets_impl()
	 */
	public function add_menu_icon_css() {
		// Styles are now enqueued properly in trait-opti-behavior-assets.php
	}

	/**
	 * Get localized strings
	 *
	 * @return array
	 */
	private function get_localized_strings() {
		return array(
			'dashboard'        => __( 'Dashboard', 'opti-behavior' ),
			'sessions'         => __( 'Sessions', 'opti-behavior' ),
			'visitors'         => __( 'Visitors', 'opti-behavior' ),
			'pageviews'        => __( 'Page Views', 'opti-behavior' ),
			'bounceRate'       => __( 'Bounce Rate', 'opti-behavior' ),
			'avgSessionTime'   => __( 'Avg. Session Time', 'opti-behavior' ),
			'topPages'         => __( 'Top Pages', 'opti-behavior' ),
			'countries'        => __( 'Countries', 'opti-behavior' ),
			'browsers'         => __( 'Browsers', 'opti-behavior' ),
			'realTimeVisitors' => __( 'Real-time Visitors', 'opti-behavior' ),
			'sessionRecordings' => __( 'Session Recordings', 'opti-behavior' ),
			'duration'         => __( 'Duration', 'opti-behavior' ),
			'pages'            => __( 'Pages', 'opti-behavior' ),
			'country'          => __( 'Country', 'opti-behavior' ),
			'device'           => __( 'Device', 'opti-behavior' ),
			'browser'          => __( 'Browser', 'opti-behavior' ),
			'viewRecording'    => __( 'View Recording', 'opti-behavior' ),
			'today'            => __( 'Today', 'opti-behavior' ),
			'yesterday'        => __( 'Yesterday', 'opti-behavior' ),
			'last7Days'        => __( 'Last 7 Days', 'opti-behavior' ),
			'last30Days'       => __( 'Last 30 Days', 'opti-behavior' ),
			'thisMonth'        => __( 'This Month', 'opti-behavior' ),
			'lastMonth'        => __( 'Last Month', 'opti-behavior' ),
			'loading'          => __( 'Loading...', 'opti-behavior' ),
			'noData'           => __( 'No data available', 'opti-behavior' ),
			'error'            => __( 'Error loading data', 'opti-behavior' ),
			// Dashboard widget strings
			'newVisitors'      => __( 'New Visitors', 'opti-behavior' ),
			'returningVisitors' => __( 'Returning Visitors', 'opti-behavior' ),
			'totalVisitors'    => __( 'Total Visitors', 'opti-behavior' ),
			'loggedInVisitors' => __( 'Logged In Visitors', 'opti-behavior' ),
			'newRegistrations' => __( 'New Registrations', 'opti-behavior' ),
			'homePage'         => __( 'Home Page', 'opti-behavior' ),
			'posts'            => __( 'Posts', 'opti-behavior' ),
			// User intent
			'lowIntent'        => __( 'Low intent', 'opti-behavior' ),
			'mediumIntent'     => __( 'Medium intent', 'opti-behavior' ),
			'highIntent'       => __( 'High intent', 'opti-behavior' ),
			// Device types
			'desktop'          => __( 'Desktop', 'opti-behavior' ),
			'mobile'           => __( 'Mobile', 'opti-behavior' ),
			'tablet'           => __( 'Tablet', 'opti-behavior' ),
			'pc'               => __( 'PC', 'opti-behavior' ),
			// Operating systems
			'windows'          => __( 'Windows', 'opti-behavior' ),
			'chrome'           => __( 'Chrome', 'opti-behavior' ),
			// Empty state messages
			'noReferrerData'   => __( 'No referrer data available', 'opti-behavior' ),
			'noCountryData'    => __( 'No country data available', 'opti-behavior' ),
			'noScreenResolutionData' => __( 'No screen resolution data available', 'opti-behavior' ),
			'tryBroadeningDateRange' => __( 'Try broadening the date range or check back later.', 'opti-behavior' ),
		'noPageData'              => __( 'No page data available', 'opti-behavior' ),
		'noVisitorData'           => __( 'No visitor data available', 'opti-behavior' ),
		'noBrowserData'           => __( 'No browser data available', 'opti-behavior' ),
		'noDeviceData'            => __( 'No device data available', 'opti-behavior' ),
		'dataWillAppear'          => __( 'Data will appear as visitors interact with your site.', 'opti-behavior' ),
		'noDataYet'               => __( 'No data yet', 'opti-behavior' ),
		);
	}

	/**
	 * Get dashboard settings
	 *
	 * @return array
	 */
	private function get_dashboard_settings() {
		return array(
			'refreshInterval' => 30000, // 30 seconds
			'dateFormat'      => get_option( 'date_format' ),
			'timeFormat'      => get_option( 'time_format' ),
			'timezone'        => wp_timezone_string(),
			'currency'        => get_option( 'woocommerce_currency', 'USD' ),
		);
	}

	/**
	 * Render main dashboard
	 */
	public function render_dashboard() {
		// Determine initial filter from querystring
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameters for filtering dashboard view (read-only operation)
		$period = isset($_GET['period']) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : 'last30days';
		$start_q = isset($_GET['start_date']) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : null;
		$end_q   = isset($_GET['end_date']) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : null;
		$exclude_spam = $this->resolve_spam_exclusion_from_request( $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Store exclude_spam flag globally for use in queries
		$GLOBALS['opti_behavior_exclude_spam'] = $exclude_spam;

		// PERFORMANCE: Calculate date range only (fast) - stats will load via AJAX.
		$date_range = $this->get_dashboard_effective_date_range( $period, $start_q, $end_q );

		// Minimal data for immediate render - everything else loads via AJAX
		$dashboard_data = array(
			'stats'      => array(
				'sessions'         => 0,
				'visitors'         => 0,
				'pageviews'        => 0,
				'avg_session_time' => 0,
				'avg_scroll_depth' => 0,
				'bounce_rate'      => 0,
			),
			'period'     => $period,
			'date_range' => $date_range,
			'changes'    => array(
				'visitors'         => 0,
				'sessions'         => 0,
				'pageviews'        => 0,
				'avg_session_time' => 0,
				'avg_scroll_depth' => 0,
				'bounce_rate'      => 0,
			),
			'daily_history' => array(),
			'charts'        => array(),
			'realtime'      => array( 'active_visitors' => array() ),
			// Empty data for widgets that render conditionally - will be populated via AJAX
			'traffic_classification' => array(),
			'bot_traffic'            => array(),
			'new_vs_returning'       => array(),
			'visited_directories'    => array(),
			'new_registered_users'   => array(),
		);

		// PERF (Section-1 speed, FIX 5): fire the gating Section-1 KPI request as
		// EARLY as possible (inline, near TTFB) instead of waiting for the
		// footer-loaded dashboard.js to boot (~tens of seconds later on big data).
		// The in-flight promise is stashed on window.optiBehaviorEarlyKpi so the
		// dashboard.js summary_stats loader ADOPTS it (single-flight) rather than
		// issuing a second identical query. Analytics values stay live/exact — this
		// only changes WHEN the existing request starts, not what it computes.
		$early_nonce = wp_create_nonce( 'opti_behavior_dashboard_nonce' );
		$early_ajax  = admin_url( 'admin-ajax.php' );
		$early_spam  = $exclude_spam ? '1' : '0';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only mirror of the page's own force flag for the early request.
		$early_force = ( isset( $_GET['force_refresh'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['force_refresh'] ) ) ) ? '1' : '0';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// FIX A (v1.6.17): build the early prefetch sig + body from the RESOLVED
		// effective date range (the same value dashboard.js computes from the
		// #start-date/#end-date inputs), NOT from raw $_GET start_date/end_date
		// (which are null on the default last30days view). On the default view the
		// raw values were empty, so the prefetch sig never matched dashboard.js's
		// resolved sig and the adopt always failed → summary_stats ran 2-3x. Using
		// the resolved range makes the two sigs provably identical. Server result is
		// unchanged: get_dashboard_effective_date_range() ignores explicit dates for
		// non-custom periods, so period-only and resolved-date requests compute the
		// same numbers.
		$early_start = ( isset( $date_range['start'] ) && $date_range['start'] ) ? substr( $date_range['start'], 0, 10 ) : '';
		$early_end   = ( isset( $date_range['end'] ) && $date_range['end'] ) ? substr( $date_range['end'], 0, 10 ) : '';
		?>
		<script>
		/* Opti-Behavior: early Section-1 KPI prefetch (fires before footer JS). */
		window.optiBehaviorAnalyticsPage = true;
		window.optiBehaviorDeferSmartInsights = true;
		window.optiBehaviorSection1Ready = false;
		(function () {
			try {
				// Trailing empty segment matches the advanced-filters-hash slot appended
				// to the sig by dashboard.js (assets/js/dashboard.js widgetSignature() /
				// loadWidgetWithTimeout()) — the page always starts unfiltered, so the
				// hash is always '' on this early prefetch.
				var sig = 'summary_stats|<?php echo esc_js( $period ); ?>|<?php echo esc_js( $early_start ); ?>|<?php echo esc_js( $early_end ); ?>|<?php echo esc_js( $early_spam ); ?>|';
				var body = new FormData();
				body.append( 'action', 'optibehavior_dashboard_data' );
				body.append( 'widget', 'summary_stats' );
				body.append( 'nonce', '<?php echo esc_js( $early_nonce ); ?>' );
				body.append( 'period', '<?php echo esc_js( $period ); ?>' );
				<?php if ( '' !== $early_start ) : ?>body.append( 'start_date', '<?php echo esc_js( $early_start ); ?>' );<?php endif; ?>
				<?php if ( '' !== $early_end ) : ?>body.append( 'end_date', '<?php echo esc_js( $early_end ); ?>' );<?php endif; ?>
				body.append( 'exclude_spam', '<?php echo esc_js( $early_spam ); ?>' );
				body.append( 'force_refresh', '<?php echo esc_js( $early_force ); ?>' );
				var p = fetch( '<?php echo esc_js( $early_ajax ); ?>', {
					method: 'POST',
					body: body,
					credentials: 'same-origin'
				} ).then( function ( r ) { return r.json(); } );
				// Swallow rejections so an early network error never becomes an
				// unhandled promise; the dashboard.js loader will retry on adopt-fail.
				p.catch( function () {} );
				window.optiBehaviorEarlyKpi = { sig: sig, promise: p, startedAt: ( window.performance && performance.now ) ? performance.now() : Date.now() };
			} catch ( e ) {}
			// Safety net: if Section-1 never signals (all widgets error), release the
			// deferred Section-2+ loaders after a bounded wait so they are not stuck.
			setTimeout( function () {
				if ( ! window.optiBehaviorSection1Ready ) {
					window.optiBehaviorSection1Ready = true;
					try { window.dispatchEvent( new Event( 'optibehavior:section1ready' ) ); } catch ( e ) {}
				}
			}, 20000 );
		})();
		</script>
		<div class="wrap opti-behavior-dashboard-page">
			<?php $this->render_pro_trial_banner(); ?>
			<?php $this->render_dashboard_header($dashboard_data['date_range'], $period); ?>
			<?php $this->render_advanced_filters_panel(); ?>
			<?php if ( function_exists( 'opti_behavior_pro_sodium_banner' ) ) { opti_behavior_pro_sodium_banner(); } ?>
			<?php $this->render_dashboard_content($dashboard_data); ?>
		</div>
		<?php
		// Add minimal dashboard data - stats will be loaded via AJAX for instant page render
		wp_add_inline_script( 'chart-js', '
			window.opti_behaviorData = window.opti_behaviorData || {};
			window.opti_behaviorData.dashboard = ' . wp_json_encode($dashboard_data) . ';
			window.opti_behaviorData.period = "' . esc_js($period) . '";
			window.opti_behaviorData.startDate = "' . esc_js($early_start) . '";
			window.opti_behaviorData.endDate = "' . esc_js($early_end) . '";
			window.opti_behaviorData.excludeSpam = "' . ($exclude_spam ? '1' : '0') . '";
		', 'before' );
	}

	/**
	 * Render dashboard header
	 */

	/**
	 * Render dashboard stats
	 */

	/**
	 * Render dashboard content
	 */
	private function render_dashboard_content($dashboard_data) {

			// Delegate rendering to trait (refactor extraction)
			return $this->render_dashboard_content_view($dashboard_data);

		?>
		<div class="dashboard-content">
			<div class="dashboard-grid">
				<!-- Sessions Over Time Chart -->
				<div class="dashboard-widget chart-widget">
					<div class="widget-header">
						<h3 class="widget-title">
							<span class="widget-icon">📈</span>
							Traffic Overview
						</h3>
					</div>
					<div class="widget-content">
						<canvas id="sessions-chart" width="400" height="200"></canvas>
					</div>
				</div>


				<!-- Browsers Widget -->
				<div class="dashboard-widget browsers-widget">
					<div class="widget-header">
						<h3 class="widget-title"><span class="widget-icon">🧭</span> Browsers</h3>
					</div>
					<div class="widget-content compact">
						<canvas id="browsers-chart"></canvas>
					</div>
				</div>
					<!-- Device Types Widget -->
					<div class="dashboard-widget device-widget">
						<div class="widget-header">
							<h3 class="widget-title"><span class="widget-icon">📱</span> Device Types</h3>
						</div>
						<div class="widget-content compact">
							<canvas id="device-types-pie"></canvas>
						</div>
					</div>


				<!-- Operating Systems Widget -->
				<div class="dashboard-widget os-widget">
					<div class="widget-header">
						<h3 class="widget-title"><span class="widget-icon">💻</span> Operating Systems</h3>
					</div>
					<div class="widget-content compact">
						<canvas id="os-chart"></canvas>
					</div>





				</div>

					<!-- Top Engaged Users (bottom-right) -->
					<?php $optibehavior_tu_nonce = wp_create_nonce('optibehavior_top_users'); $optibehavior_tu_ajax = admin_url('admin-ajax.php'); ?>
					<div class="dashboard-widget top-users-widget">
						<div class="widget-header">
							<h3 class="widget-title"><span class="widget-icon">👤</span> <?php echo esc_html__('Top Engaged Users','opti-behavior'); ?></h3>
						</div>
						<div class="widget-content">
							<div class="optibehavior-table-wrap">
								<table class="widefat striped">
									<thead>
									<tr>
										<th>#</th>
										<th><?php echo esc_html__('Visitor','opti-behavior'); ?></th>
										<th title="<?php echo esc_attr__('Average sessions per active day', 'opti-behavior'); ?>"><?php esc_html_e('Daily Freq', 'opti-behavior'); ?></th>
										<th title="<?php echo esc_attr__('Average time per session', 'opti-behavior'); ?>"><?php esc_html_e('Avg Session', 'opti-behavior'); ?></th>
										<th><?php echo esc_html__('Total Time','opti-behavior'); ?></th>
										<th><?php echo esc_html__('Sessions','opti-behavior'); ?></th>
										<th title="<?php echo esc_attr__('Pages per session', 'opti-behavior'); ?>"><?php esc_html_e('Pages/Sess', 'opti-behavior'); ?></th>
										<th><?php echo esc_html__('Country','opti-behavior'); ?></th>
										<th><?php echo esc_html__('Last Seen','opti-behavior'); ?></th>
									</tr>
									</thead>
									<tbody id="optibehavior-tu2-body">
										<tr><td colspan="9">
											<div class="optibehavior-loading-state">
												<span class="spinner is-active"></span>
												<span><?php echo esc_html__('Loading top users…','opti-behavior'); ?></span>
											</div>
										</td></tr>
									</tbody>
								</table>
							</div>
						</div>
					</div>
					<!-- Note: Top users loading script is now properly enqueued via wp_add_inline_script() in get_dashboard_scripts() method -->
					<?php ?>


				<!-- Top Countries Widget -->
				<div class="dashboard-widget countries-widget">
					<div class="widget-header">
						<h3 class="widget-title"><span class="widget-icon">🌍</span> Top Countries</h3>
					</div>
					<div class="widget-content compact">
						<canvas id="countries-chart"></canvas>
					</div>
				</div>

				<!-- Real-time Visitors -->
				<div class="dashboard-widget realtime-widget">
					<div class="widget-header">
						<h3 class="widget-title">
							<span class="live-badge" aria-label="<?php echo esc_attr__('Live', 'opti-behavior'); ?>"><span class="live-dot" aria-hidden="true"></span><?php esc_html_e('Live', 'opti-behavior'); ?></span>
							<?php esc_html_e('Real-time Visitors', 'opti-behavior'); ?>
							<span class="visitor-count"><?php echo count($dashboard_data['realtime']['active_visitors']); ?></span>
						</h3>
					</div>
					<div class="widget-content">
						<div class="realtime-visitors" id="realtime-visitors">
							<?php foreach ($dashboard_data['realtime']['active_visitors'] as $visitor): ?>
							<div class="visitor-item grid">
								<span class="visitor-datetime"><?php echo esc_html($visitor['visited_at'] ?? ''); ?><?php if(!empty($visitor['time_ago'])): ?> - <span class="ago"><?php echo esc_html($visitor['time_ago']); ?></span><?php endif; ?></span>
								<span class="visitor-flag-country"><span class="visitor-flag"><?php echo $visitor['flag']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span> <span class="visitor-location"><?php echo esc_html($visitor['country']); ?></span></span>
								<span class="visitor-pageblock"><span class="visitor-title"><?php echo esc_html($visitor['page_title'] ?? ''); ?></span><a class="visitor-url" href="<?php echo esc_url($visitor['current_url'] ?? ''); ?>" target="_blank" rel="noopener"><?php echo esc_html($visitor['current_url'] ?? ''); ?></a></span>
								<span class="visitor-ip-col"><?php
								$ip_raw = isset( $visitor['ip'] ) ? trim( (string) $visitor['ip'] ) : '';
								if ( '' === $ip_raw ) {
									echo '<span class="visitor-ip-pill">-</span>';
								} elseif ( 'Anonymous' === $ip_raw ) {
									echo '<span class="visitor-ip-pill visitor-ip-anon"><i data-lucide="shield-check" style="width:13px;height:13px;display:inline-block;vertical-align:-2px;margin-right:3px;"></i>' . esc_html__( 'Anonymous', 'opti-behavior' ) . '</span>';
								} elseif ( false !== strpos( $ip_raw, ':' ) ) {
									echo '<span class="visitor-ip-pill">' . esc_html( substr( $ip_raw, 0, 7 ) . '…' . substr( $ip_raw, -7 ) ) . '</span>';
								} else {
									echo '<span class="visitor-ip-pill">' . esc_html( $ip_raw ) . '</span>';
								}
								?></span>
							</div>
							<?php endforeach; ?>

							<?php if (empty($dashboard_data['realtime']['active_visitors'])): ?>
							<div class="optibehavior-empty-state is-visible">
								<svg viewBox='0 0 24 24' fill='none' stroke='#9ca3af' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'><path d='M16 11a4 4 0 1 0-8 0'/><path d='M3 21a7 7 0 0 1 18 0'/></svg>
								<div class="optibehavior-empty-title"><?php esc_html_e('No active visitors right now', 'opti-behavior'); ?></div>
								<div class="optibehavior-empty-sub"><?php esc_html_e('Traffic updates in real-time.', 'opti-behavior'); ?></div>
							</div>
							<?php endif; ?>
						</div>
					</div>
				</div>

					<!-- Real-time Visitor Map -->
					<div class="dashboard-widget realtime-map-widget">
						<div class="widget-header">
							<h3 class="widget-title"><span class="widget-icon">🗺️</span> Real-time Visitor Map</h3>
						</div>
						<div class="widget-content">
							<div id="realtime-map" class="realtime-map" aria-label="<?php echo esc_attr__('Interactive world map of live visitors', 'opti-behavior'); ?>"></div>
						</div>
					</div>

						<?php
						// Realtime map styles are now enqueued in trait-opti-behavior-assets.php
						// Realtime map initialization script is now in get_dashboard_scripts_impl()
						?>
						<!-- Note: Realtime map initialization script is now properly enqueued via wp_add_inline_script() in get_dashboard_scripts_impl() method -->
						<?php  ?>



				<!-- Top Pages -->
				<div class="dashboard-widget pages-widget">
					<div class="widget-header">
						<h3 class="widget-title">
							<span class="widget-icon" aria-hidden="true">
								<svg class="widget-icon-svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path></svg>
							</span>
							Top Pages
						</h3>
					</div>
					<div class="widget-content">
						<div class="top-pages">
							<?php if (empty($dashboard_data['charts']['top_pages'])): ?>
								<div class="optibehavior-empty-state is-visible">
									<svg viewBox='0 0 24 24' fill='none' stroke='#9ca3af' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'><path d='M4 3h14a2 2 0 0 1 2 2v14l-4-4H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z'/></svg>
									<div class="optibehavior-empty-title"><?php esc_html_e('No page views yet', 'opti-behavior'); ?></div>
									<div class="optibehavior-empty-sub"><?php esc_html_e('Once visitors view pages, you’ll see them here.', 'opti-behavior'); ?></div>
								</div>
							<?php else: ?>
							<?php foreach ($dashboard_data['charts']['top_pages'] as $page): ?>
							<div class="page-item">
								<div class="page-info">
									<div class="page-title"><?php echo esc_html($page['title']); ?></div>
									<div class="page-url" title="<?php echo esc_attr($page['url']); ?>"><?php $u=$page['url']; if(strlen($u)>80){$keep=79; $left=(int)ceil($keep/2); $right=(int)floor($keep/2); $u=substr($u,0,$left).'…'.substr($u,-$right);} echo esc_html($u); ?></div>
								</div>
								<div class="page-stats">
									<span class="page-views"><?php echo esc_html($page['views']); ?></span>
									<a class="optibehavior-heatmap-btn" target="_blank" href="<?php echo esc_url($page['pc_heatmap']); ?>" aria-label="<?php
										// translators: %s: page title
										echo esc_attr( sprintf( __('Open PC heatmap for %s', 'opti-behavior'), $page['title'] ) ); ?>">🖥️ PC</a>
									<a class="optibehavior-heatmap-btn alt" target="_blank" href="<?php echo esc_url($page['mobile_heatmap']); ?>" aria-label="<?php
										// translators: %s: page title
										echo esc_attr( sprintf( __('Open Mobile heatmap for %s', 'opti-behavior'), $page['title'] ) ); ?>">📱 Mobile</a>
								</div>
							</div>
							<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div>
				</div>

					<!-- Top Referrers -->
					<div class="dashboard-widget referrers-widget">
						<div class="widget-header">
							<h3 class="widget-title"><span class="widget-icon">🔗</span> Top Referrers</h3>
						</div>
						<div class="widget-content compact">
							<canvas id="referrers-chart"></canvas>
						</div>
					</div>






			</div>
		</div>
		<?php
	}

	/**
	 * Render the Smart Insights admin center.
	 *
	 * @since 1.3.3
	 */
	public function render_smart_insights() {
		$page = $this->get_smart_insights_page();

		if ( $page && method_exists( $page, 'render' ) ) {
			$page->render();
			return;
		}

		wp_die( esc_html__( 'Smart Insights are not available yet. Please reload the admin page to complete the upgrade.', 'opti-behavior' ) );
	}

	/**
	 * Get or create the Smart Insights page controller.
	 *
	 * @since 1.3.3
	 * @return Opti_Behavior_Smart_Insights_Page|null
	 */
	private function get_smart_insights_page() {
		if ( null !== $this->smart_insights_page ) {
			return $this->smart_insights_page;
		}

		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Page' ) ) {
			return null;
		}

		$this->smart_insights_page = new Opti_Behavior_Smart_Insights_Page( $this->heatmap );

		return $this->smart_insights_page;
	}

	/**
	 * Render modern heatmaps page
	 */
	public function render_heatmaps() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin filter preference.
		$GLOBALS['opti_behavior_exclude_spam'] = $this->resolve_spam_exclusion_from_request( $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap opti-behavior-heatmaps-modern">';
		$this->render_pro_trial_banner();
		$this->render_heatmaps_header();
		if ( function_exists( 'opti_behavior_pro_sodium_banner' ) ) { opti_behavior_pro_sodium_banner(); }
		$this->render_heatmaps_stats();
		$this->render_heatmaps_content();
		echo '</div>';

		// Note: Heatmaps page scripts are now enqueued from external file assets/js/heatmaps.js
		// Enqueuing is handled in includes/trait-opti-behavior-assets.php (lines 87-94)
	}

	/**
	 * Render recordings upgrade page
	 * Shows promotional page when PRO is not active
	 * If PRO is active, this will be overridden by the PRO plugin
	 */
	public function render_recordings_upgrade() {
		// If PRO is active, this shouldn't be called (PRO plugin overrides the menu).
		// To avoid duplicate headers when both callbacks are attached, bail out silently.
		if ( opti_behavior_pro_active() ) {
			return;
		}

		// Show upgrade/promotional page
		$template_path = plugin_dir_path( dirname( __FILE__ ) ) . 'admin/recordings-upgrade-page.php';

		// Template path validation
		$debug_manager = $this->heatmap->get_debug_manager();
		$debug_manager->log( 'Template path: ' . $template_path, 'debug', 'recordings' );
		$debug_manager->log( 'Template exists: ' . ( file_exists( $template_path ) ? 'YES' : 'NO' ), 'debug', 'recordings' );

		if ( file_exists( $template_path ) ) {
			require_once $template_path;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Session Recordings', 'opti-behavior' ) . '</h1>';
			echo '<p>' . esc_html__( 'Template file not found.', 'opti-behavior' ) . '</p></div>';
		}
	}

	/**
	 * Render errors tracking upgrade page
	 * Shows promotional page when PRO is not active
	 * If PRO is active, PRO plugin registers its own errors page
	 */
	public function render_errors_upgrade() {
		$template_path = plugin_dir_path( dirname( __FILE__ ) ) . 'admin/errors-upgrade-page.php';

		if ( file_exists( $template_path ) ) {
			require_once $template_path;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Errors Tracking', 'opti-behavior' ) . '</h1>';
			echo '<p>' . esc_html__( 'Template file not found.', 'opti-behavior' ) . '</p></div>';
		}
	}

	/**
	 * Render user journey upgrade page
	 * Shows promotional page when PRO is not active
	 * If PRO is active, PRO plugin registers its own user journey page
	 */
	public function render_user_journey_upgrade() {
		$template_path = plugin_dir_path( dirname( __FILE__ ) ) . 'admin/user-journey-upgrade-page.php';

		if ( file_exists( $template_path ) ) {
			require_once $template_path;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'User Journeys', 'opti-behavior' ) . '</h1>';
			echo '<p>' . esc_html__( 'Template file not found.', 'opti-behavior' ) . '</p></div>';
		}
	}

	/**
	 * Render Form Analytics upgrade page (placeholder when PRO is not active).
	 */
	public function render_form_analytics_upgrade() {
		$template_path = plugin_dir_path( dirname( __FILE__ ) ) . 'admin/form-analytics-upgrade-page.php';

		if ( file_exists( $template_path ) ) {
			require_once $template_path;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Form Analytics', 'opti-behavior' ) . '</h1>';
			echo '<p>' . esc_html__( 'Upgrade to Opti-Behavior Pro to unlock Form Analytics.', 'opti-behavior' ) . '</p></div>';
		}
	}

	/**
	 * Render heatmaps page header
	 */

	/**
	 * Render heatmaps statistics section
	 */

	/**
	 * Render heatmaps content section
	 */

	/**
	 * Render simplified heatmap table
	 */
	private function render_simplified_heatmap_table() {
		// Handle delete action
		if (!empty($_POST['optibehavior_delete_page_id'])) {
			$del_id = intval( wp_unslash( $_POST['optibehavior_delete_page_id'] ) );
			if (isset($_POST['_wpnonce']) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'optibehavior_delete_heatmap_' . $del_id)) {
				$this->heatmap->get_database()->delete_data(array($del_id));
				echo '<div class="notice notice-success"><p>' . esc_html__('Heatmap data deleted.', 'opti-behavior') . '</p></div>';
			}
		}

			// Performance: render shell only; populate via AJAX
			?>
			<div class="simplified-heatmap-table" data-optibehavior-table>
				<div class="table-skeleton">
					<span class="spinner"></span>
					<span><?php esc_html_e('Loading analytics data...', 'opti-behavior'); ?></span>
				</div>
			</div>
		<?php
		// Inline styles moved to assets/css/dashboard.css for WordPress compliance
		// Heatmap table styles are now in dashboard.css
		// Note: Heatmaps page scripts are now properly enqueued via wp_add_inline_script() in render_heatmaps() method
	}

	/**
	 * AJAX: Heatmaps table HTML (sorting + pagination)
	 */
	public function ajax_heatmaps_table() {
		return $this->ajax_heatmaps_table_impl();
	}

	/**
	 * AJAX: Heatmaps KPI stat cards HTML.
	 */
	public function ajax_heatmaps_stats() {
		return $this->ajax_heatmaps_stats_impl();
	}

		/**
		 * Render only the inner table + compact pagination
		 */
		private function render_heatmap_table_inner( $args ) {
			$per_page = isset($args['per_page']) ? $args['per_page'] : 10;
			$orderby = isset($args['orderby']) ? $args['orderby'] : 'last_updated';
			$order   = isset($args['order']) ? $args['order'] : 'desc';
			$page    = isset($args['page']) ? $args['page'] : 1;
			$period  = isset($args['period']) ? $args['period'] : 'last30days';
			$start   = isset($args['start']) ? $args['start'] : '';
			$end     = isset($args['end']) ? $args['end'] : '';
		$search  = isset($args['search']) ? $args['search'] : '';

			// Date range resolution for efficient queries
			if ($period === 'custom' && !empty($start) && !empty($end)) {
				$start_date = $start . ' 00:00:00';
				$end_date   = $end   . ' 23:59:59';
			} elseif ($period === 'all' || $period === 'alltime' || $period === '') {
				// All-time: null dates trigger the indexed fast path (precomputed agg columns,
				// exact + spam-aware, zero JSON file IO) in get_heatmap_rows_paged.
				$start_date = null;
				$end_date   = null;
			} else {
				$range = $this->get_date_range($period);
				$start_date = $range['start'];
				$end_date   = $range['end'];
			}

			// Optimized: fetch only the current page from DB (no full-scan)
				// Debug log for AJAX parameters


			$allowed = array('interactions','sessions','last_updated');
			if (!in_array($orderby,$allowed,true)) { $orderby='last_updated'; }
			list($heatmaps, $total) = $this->get_heatmap_rows_paged($orderby, $order, (int)$page, (int)$per_page, $start_date, $end_date, $search);
			// Fallback: if filtered date range yields zero, show all-time so table is not empty
			if ($total === 0 && $start_date && $end_date && empty($search)) {
				list($heatmaps, $total) = $this->get_heatmap_rows_paged($orderby, $order, (int)$page, (int)$per_page, null, null, $search);
			}

			// Unified session metric (plan step "Unify session metric"): attach
			// the all-visitors pair (sessions_file_all / sessions_db_all) the
			// detail header pill shows, so the cell below renders the SAME two
			// numbers as the detail page. Cached file side + one batched DB
			// query; rows without both sides keep the legacy guest-scoped cell.
			$heatmaps = $this->overlay_unified_session_metric( is_array( $heatmaps ) ? $heatmaps : array() );
			$pages = max(1,(int)ceil($total/$per_page));
			$page = min($page,$pages);
			$offset = ($page-1)*$per_page;

			$qs = function($p) use($orderby,$order){ return esc_attr( http_build_query( array_merge(['orderby'=>$orderby,'order'=>$order],$p) ) ); };
			$toggle = function($col) use ($order){ return $order==='asc'?'desc':'asc'; };
			$arrow = function($col) use ($orderby,$order){ if($orderby!==$col) return ''; return $order==='asc'?'↑':'↓'; };
			$current = function($col) use ($orderby,$order){ return $orderby===$col ? $order : ''; };
			$render_sort_link = function( $column, $label ) use ( $toggle, $current, $arrow, $orderby ) {
				?>
				<a href="#" class="opti-heatmap-sort-link" data-sort="<?php echo esc_attr( $column ); ?>" data-order="<?php echo esc_attr( $toggle( $column ) ); ?>" data-current="<?php echo esc_attr( $current( $column ) ); ?>" data-active="<?php echo ( $orderby === $column ) ? '1' : '0'; ?>">
					<span class="opti-heatmap-sort-label"><?php echo esc_html( $label ); ?></span>
					<span class="opti-heatmap-sort-direction" aria-hidden="true"><?php echo esc_html( $arrow( $column ) ); ?></span>
					<span class="opti-heatmap-sort-spinner" aria-hidden="true"></span>
					<span class="screen-reader-text opti-heatmap-sort-pending-text" aria-hidden="true"><?php esc_html_e( 'Loading sort results...', 'opti-behavior' ); ?></span>
				</a>
				<?php
			};
			$heatmap_tooltips = opti_behavior_get_heatmaps_tooltips();
			// Tiered Retention (round 2): pages whose raw event files were
			// archived by the heavy-files tier before their DB rows expired.
			// Graceful degradation — the row stays listed with a badge
			// instead of silently rendering an empty heatmap.
			$files_expired_flags = array();
			if ( class_exists( 'Opti_Behavior_Heatmap_Storage' ) && method_exists( 'Opti_Behavior_Heatmap_Storage', 'get_instance' ) ) {
				$fx_storage = Opti_Behavior_Heatmap_Storage::get_instance();
				if ( method_exists( $fx_storage, 'get_files_expired_page_flags' ) ) {
					$files_expired_flags = $fx_storage->get_files_expired_page_flags();
				}
			}
			?>
			<div class="simplified-heatmap-table" data-optibehavior-table>
				<table class="heatmap-table heatmaps-modern-table">
					<thead>
						<tr>
							<th><?php esc_html_e('Page', 'opti-behavior'); ?></th>
							<th><?php opti_behavior_tooltip_e( $heatmap_tooltips['interactions']['title'], $heatmap_tooltips['interactions']['content'], $heatmap_tooltips['interactions']['simple'], '', array( 'position' => 'bottom' ) ); ?><?php $render_sort_link( 'interactions', __( 'Interactions', 'opti-behavior' ) ); ?></th>
							<th><?php opti_behavior_tooltip_e( $heatmap_tooltips['sessions']['title'], $heatmap_tooltips['sessions']['content'], $heatmap_tooltips['sessions']['simple'], '', array( 'position' => 'bottom' ) ); ?><?php $render_sort_link( 'sessions', __( 'Sessions', 'opti-behavior' ) ); ?><span class="opti-heatmap-scope-label" style="display:block;font-size:11px;font-weight:400;color:#6b7280;"><?php esc_html_e( 'All time', 'opti-behavior' ); ?></span></th>
							<th><?php esc_html_e('Device Split', 'opti-behavior'); ?></th>
							<th><?php $render_sort_link( 'last_updated', __( 'Last Updated', 'opti-behavior' ) ); ?></th>
							<th><?php esc_html_e('Actions', 'opti-behavior'); ?></th>
							<?php echo apply_filters( 'opti_behavior_heatmap_list_columns_after', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output escaped by the filter callback ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($heatmaps as $heatmap): $heatmap = (array) $heatmap; ?>
						<tr class="heatmap-row heatmap-table-row" data-page-id="<?php echo intval($heatmap['id']); ?>">
							<td class="page-info">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=opti-behavior-heatmap-detail&page_id=' . intval($heatmap['id']) . '&type=click&device=desktop' ) ); ?>" class="page-title-link" title="<?php echo esc_attr__( 'View click heatmap', 'opti-behavior' ); ?>">
									<div class="page-title" title="<?php echo esc_attr($heatmap['title']); ?>"><?php echo esc_html($heatmap['title']); ?></div>
								</a>
								<a href="<?php echo esc_url($heatmap['url']); ?>" target="_blank" rel="noopener noreferrer" class="page-url heatmap-page-url" title="<?php echo esc_attr($heatmap['url']); ?>"><?php echo esc_html($heatmap['url']); ?></a>
								<?php if ( isset( $files_expired_flags[ (int) $heatmap['id'] ] ) ) : ?>
								<span class="heatmap-files-expired-badge" style="display:inline-block;margin-top:4px;padding:1px 8px;font-size:11px;line-height:18px;border-radius:9999px;background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb;" title="<?php echo esc_attr__( 'Raw interaction files for this page were archived by the heavy-files retention tier. Historical stats remain; detailed replay data is no longer available.', 'opti-behavior' ); ?>">
									<i data-lucide="archive" style="width:11px;height:11px;vertical-align:middle;margin-right:3px;"></i><?php esc_html_e( 'Files archived', 'opti-behavior' ); ?>
								</span>
								<?php endif; ?>
							</td>

							<td class="interactions-count"><strong><?php echo esc_html($heatmap['interactions']); ?></strong></td>
							<?php
							// Unified session metric (plan step "Unify session
							// metric"): the cell shows the SAME pair as the detail
							// header pill — "X / Y" where X = sessions with heatmap
							// event data (file_total_all, all visitor types) and
							// Y = all DB sessions for the page (all visitor types,
							// all time). Collapses to a single number when equal.
							// Fail open: rows without both unified sides (cold
							// reconciliation cache / Free-only installs) keep the
							// legacy guest-scoped dual-metric cell (first = DB
							// sessions, second = file-derived with-interactions).
							$hm_file_all = array_key_exists( 'sessions_file_all', $heatmap ) ? $heatmap['sessions_file_all'] : null;
							$hm_db_all   = array_key_exists( 'sessions_db_all', $heatmap ) ? $heatmap['sessions_db_all'] : null;
							// "Delete Heatmap Data" dual display: reset pages whose
							// since-reset count differs from all-time render
							// "valid / total" (e.g. "45 / 108") with an explaining
							// tooltip; the primary (sorted/displayed) number is the
							// since-reset count, matching the detail pill.
							$hm_reset_all_time = array_key_exists( 'sessions_all_time', $heatmap ) ? (int) $heatmap['sessions_all_time'] : null;
							$hm_reset_tooltip  = '';
							if ( null !== $hm_reset_all_time ) {
								$hm_first     = (int) $heatmap['sessions'];
								$hm_second    = $hm_reset_all_time;
								$hm_desynced  = false;
								$hm_show_dual = $hm_second !== $hm_first;
								// translators: %1$s: sessions counted since the heatmap data deletion, %2$s: total sessions recorded all time.
								$hm_reset_tooltip = sprintf( __( '%1$s sessions with heatmap data (counted since this page\'s heatmap data was deleted) / %2$s total sessions recorded all time. Analytics keeps all sessions; heatmaps restart from the deletion.', 'opti-behavior' ), number_format_i18n( $hm_first ), number_format_i18n( $hm_second ) );
							} elseif ( null !== $hm_file_all && null !== $hm_db_all ) {
								$hm_first      = (int) $hm_file_all;
								$hm_second_raw = (int) $hm_db_all;
								// Orphaned-files fallback — mirrors the detail
								// pill (ajax_get_total_recordings()): the DB knows
								// no sessions but event files exist, so surface
								// the file-derived count instead of "0".
								$hm_second   = ( 0 === $hm_second_raw && $hm_first > 0 ) ? $hm_first : $hm_second_raw;
								// Desync from the RAW pair: more sessions carry
								// event files than sessions exist at all (orphan
								// files / stale cache) — repairable, keep badge.
								$hm_desynced  = $hm_first > $hm_second_raw;
								$hm_show_dual = $hm_first !== $hm_second;
							} else {
								$hm_second            = array_key_exists( 'sessions_with_interactions', $heatmap ) ? $heatmap['sessions_with_interactions'] : null;
								$hm_first             = (int) $heatmap['sessions'];
								$hm_desynced          = ! empty( $heatmap['desynced'] );
								$hm_show_dual         = null !== $hm_second && (int) $hm_second !== $hm_first;
							}
							?>
							<td class="sessions-count" data-page-id="<?php echo intval($heatmap['id']); ?>" data-sessions="<?php echo intval($hm_first); ?>"<?php if ( '' !== $hm_reset_tooltip ) : ?> title="<?php echo esc_attr( $hm_reset_tooltip ); ?>"<?php endif; ?>>
								<strong><?php echo esc_html($hm_first); ?></strong>
								<?php if ( $hm_show_dual ) : ?>
								<span class="<?php echo ( null !== $hm_reset_all_time ) ? 'sessions-all-time' : 'sessions-interactions'; ?>"><?php echo esc_html( '/ ' . (int) $hm_second ); ?></span>
								<?php endif; ?>
								<?php if ( $hm_desynced ) : ?>
								<span class="sessions-desync-badge" title="<?php echo esc_attr__( 'Interaction files and detected sessions are out of sync for this page. Click Repair to refresh.', 'opti-behavior' ); ?>">
									<i data-lucide="alert-triangle" style="width:12px;height:12px;vertical-align:middle;"></i>
									<a href="#" class="sessions-repair-link" data-page-id="<?php echo intval($heatmap['id']); ?>"><?php esc_html_e( 'Repair', 'opti-behavior' ); ?></a>
								</span>
								<?php endif; ?>
							</td>
							<td class="device-info"><span class="mobile-percentage"><?php echo esc_html($heatmap['mobile_percentage']); ?>% <i data-lucide="smartphone" style="width:14px;height:14px;vertical-align:middle;"></i> / <?php echo esc_html(100 - intval($heatmap['mobile_percentage'])); ?>% <i data-lucide="monitor" style="width:14px;height:14px;vertical-align:middle;"></i></span></td>
							<td class="last-updated"><?php echo esc_html($heatmap['last_updated']); ?></td>
							<td class="actions">
								<a href="<?php echo esc_url($heatmap['view_pc_url']); ?>" class="button button-primary button-small optibehavior-btn optibehavior-btn-desktop heatmap-action-btn" target="_blank" title="<?php echo esc_attr__('Open desktop heatmap', 'opti-behavior'); ?>"><i data-lucide="monitor" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"></i> <?php
									// translators: %s: number of desktop sessions
									printf( esc_html__('Desktop (%s)', 'opti-behavior'), esc_html(number_format($heatmap['sessions_desktop'])) ); ?></a>
								<a href="<?php echo esc_url($heatmap['view_mobile_url']); ?>" class="button button-secondary button-small optibehavior-btn optibehavior-btn-mobile heatmap-action-btn" target="_blank" title="<?php echo esc_attr__('Open mobile heatmap', 'opti-behavior'); ?>"><i data-lucide="smartphone" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"></i> <?php
									// translators: %s: number of mobile sessions
									printf( esc_html__('Mobile (%s)', 'opti-behavior'), esc_html(number_format($heatmap['sessions_mobile'])) ); ?></a>
							</td>
							<?php echo apply_filters( 'opti_behavior_heatmap_list_row_after', '', $heatmap ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output escaped by the filter callback ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if (empty($heatmaps)): ?><div class="no-heatmaps"><p><?php esc_html_e('No heatmaps available.', 'opti-behavior'); ?></p></div><?php endif; ?>

				<?php
				// Compact pagination window with ellipses
				$window = 5; $half = floor($window/2);
				$start = max(1, $page - $half); $end = min($pages, $start + $window - 1); $start = max(1, $end - $window + 1);
				?>
				<div class="table-pagination table-pagination--center" data-total-pages="<?php echo esc_attr($pages); ?>" data-orderby="<?php echo esc_attr($orderby); ?>" data-order="<?php echo esc_attr($order); ?>">
					<div class="entries-info"><?php
						// translators: %1$s: start range, %2$s: end range, %3$s: total items, %4$s: current page, %5$s: total pages
						printf( esc_html__('Showing %1$s–%2$s of %3$s · Page %4$s / %5$s', 'opti-behavior'), esc_html($total ? ($offset+1) : 0), esc_html(min($offset + $per_page, $total)), esc_html($total), esc_html($page), esc_html($pages) ); ?></div>
					<div class="pagination-controls" data-paging>
						<button class="pagination-btn" data-first <?php disabled($page<=1); ?>><?php esc_html_e('First', 'opti-behavior'); ?></button>
						<button class="pagination-btn" data-prev <?php disabled($page<=1); ?>>‹ <?php esc_html_e('Prev', 'opti-behavior'); ?></button>
						<?php if ($start > 1): ?><span class="pagination-ellipsis">…</span><?php endif; ?>
						<?php for($i=$start;$i<=$end;$i++): ?>
							<?php if ($i===$page): ?>
								<span class="pagination-current"><?php echo esc_html($i); ?></span>
							<?php else: ?>
								<a href="#" class="pagination-btn" data-page="<?php echo esc_attr($i); ?>"><?php echo esc_html($i); ?></a>
							<?php endif; ?>
						<?php endfor; ?>

					<!-- Note: Sessions lazy load script is now properly enqueued via wp_add_inline_script() in render_heatmaps() method -->
					<?php /* Removed duplicate inline script - already in get_heatmaps_page_scripts() as optibehaviorUpdateSessionsCounts */ ?>

						<?php if ($end < $pages): ?><span class="pagination-ellipsis">…</span><?php endif; ?>
						<button class="pagination-btn" data-next <?php disabled($page>=$pages); ?>><?php esc_html_e('Next', 'opti-behavior'); ?> ›</button>
						<button class="pagination-btn" data-last <?php disabled($page>=$pages); ?>><?php esc_html_e('Last', 'opti-behavior'); ?></button>
					</div>
				</div>

		</div>
		<?php
	}



	/**
	 * Get heatmap statistics from database or file storage
	 */
	private function get_heatmap_statistics() {
		global $wpdb;

		// Get date ranges for calculations
		$today = gmdate('Y-m-d');
		$week_ago = gmdate('Y-m-d', strtotime('-7 days'));

		// Cache the (expensive) stat-card aggregation. The cached payload is the
		// exact array returned below; only freshness is bounded (by the
		// ingest/delete flush, the TTL, and the date stamp in the key — which
		// rolls the "this week" window at midnight). Spam state is part of the
		// key so the ON/OFF toggle each get their own exact result.
		$stats_cache_key = function_exists( 'opti_behavior_heatmap_cache_key' )
			? opti_behavior_heatmap_cache_key( 'stats', array(
				'spam'  => $this->is_spam_excluded() ? '1' : '0',
				'day'   => $today,
				'shape' => 2, // Payload v2: total_sessions/sessions_growth replaced mobile_percentage/mobile_change.
			) )
			: '';
		if ( $stats_cache_key && ! $this->should_force_refresh_dashboard_cache() ) {
			$cached_stats = get_transient( $stats_cache_key );
			if ( is_array( $cached_stats ) ) {
				return $cached_stats;
			}
		}
		$two_weeks_ago = gmdate('Y-m-d', strtotime('-14 days'));
		$month_ago = gmdate('Y-m-d', strtotime('-30 days'));

		// Check storage mode
		$file_storage = $this->heatmap->get_file_storage();
		$storage_settings = $file_storage ? $file_storage->get_settings() : array( 'storage_mode' => 'database' );

		// Always use file storage if it exists and has data
		// Force file storage detection based on actual data availability
		$use_file_storage = false;
		$heatmap_visible_session_total = null;
		$heatmap_file_pages_data = $this->get_heatmap_pages_data_from_file_source();
		$use_file_storage = ! empty( $heatmap_file_pages_data );

		// Perf (RC3): serve the ALL-TIME KPI aggregation from the precomputed
		// agg_* columns — the byte-for-byte output of the same file aggregators,
		// and the exact values the table fast path displays — instead of a full
		// JSON file scan of every page. maybe_fast_heatmap_pages_data() handles
		// the bounded resync + bootstrap fallback internally and returns NULL
		// whenever the columns cannot represent this request (then the original
		// full-scan branch below runs unchanged).
		$agg_pages_data = $use_file_storage ? $this->maybe_fast_heatmap_pages_data( null, null ) : null;

		// Task 4 (spec §3.4 / §4-A.3): on installs above the file threshold (or
		// after the self-measuring valve tripped), a WEB stats request must NEVER
		// inline full-scan — neither the all-time pass nor the "this week"
		// windowed pass. CLI/cron contexts keep the exact full pipeline (that is
		// where the background warm-up below computes), and the explicit legacy
		// filter (parity tests) keeps the live path too.
		$is_background_ctx  = ( 'cli' === PHP_SAPI ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() );
		$stats_indexed_only = $use_file_storage
			&& ! $is_background_ctx
			&& apply_filters( 'opti_behavior_heatmap_use_indexed_sort', true )
			&& $this->should_use_indexed_heatmap_alltime_path();

		// Version-independent stale-while-revalidate copy of the stats payload
		// (C2-3 pattern, same as the canonical-overlay map): serves cold views
		// instantly while a background cron recomputes the exact numbers.
		// v2 suffix: payload shape changed (mobile_percentage/mobile_change →
		// total_sessions/sessions_growth), so pre-change stale copies must miss.
		$stats_stale_key = 'ob_hm_stats_sw2_' . ( $this->is_spam_excluded() ? '1' : '0' );

		// Cold when the indexes cannot represent this request:
		//  - all-time basis: agg_* fast path bailed (e.g. the request is not the
		//    default spam-excluded view the columns model);
		//  - week window: the per-day index is not available to serve the
		//    "this week" deltas (apply_heatmap_windowed_metrics() would fall
		//    back to the windowed live file scan).
		$stats_cold            = $stats_indexed_only
			&& ( ! is_array( $agg_pages_data ) || ! $this->should_use_indexed_heatmap_dated_path() );
		$stats_skip_window_pass = false;
		if ( $stats_cold ) {
			// SWR + cron (Task 4): warm the exact payload in the background —
			// cron context runs the full pipeline (uncapped file scan allowed).
			$this->schedule_heatmap_stats_warm();

			$stale_stats = get_transient( $stats_stale_key );
			if ( is_array( $stale_stats ) ) {
				return $stale_stats;
			}

			// No stale copy at all (very first cold request): degrade instead of
			// full-scanning — all-time cards from the indexed agg_* universe,
			// week deltas zero until the daily index / warm cron converge
			// (transition window, spec §9: staleness accepted, no 60s+ hang).
			if ( ! is_array( $agg_pages_data ) ) {
				$agg_pages_data = $this->fetch_fast_heatmap_pages_data_rows( array() );
			}
			$stats_skip_window_pass = true;
			if ( $this->heatmap_daily_index_available() ) {
				$this->schedule_heatmap_daily_index_sync( 30 );
			}
		}

		// The "this week" windowed passes must scan files, but only pages that
		// could have events in the window need scanning (exact: pages whose
		// last allowed event AND last ingest are both older than the window
		// contribute nothing to it).
		$window_pages_data = $heatmap_file_pages_data;
		if ( is_array( $agg_pages_data ) && $use_file_storage ) {
			$window_pages_data = $this->filter_heatmap_pages_data_to_window_candidates( $heatmap_file_pages_data, $week_ago );
		}

		// Compute each expensive pass ONCE and reuse (Task 4): the all-time
		// basis is consumed by four card computations below and the windowed
		// pass by two — identical inputs, identical outputs, so hoisting them
		// changes no number, only removes redundant recomputation.
		$pages_data_alltime = array();
		if ( $use_file_storage ) {
			$pages_data_alltime = is_array( $agg_pages_data )
				? $agg_pages_data
				: $this->apply_heatmap_file_metrics_to_pages_data( $heatmap_file_pages_data, null, null, $this->is_spam_excluded() );
		}

		$this_week_pages_data = array();
		if ( $use_file_storage && ! $stats_skip_window_pass ) {
			// Served from the per-day index on indexed installs (zero file IO,
			// see apply_heatmap_windowed_metrics()); windowed live scan on small
			// installs (unchanged behaviour).
			$this_week_pages_data = $this->apply_heatmap_windowed_metrics(
				$window_pages_data,
				$week_ago . ' 00:00:00',
				gmdate( 'Y-m-d' ) . ' 23:59:59'
			);
		}

		if ( $use_file_storage ) {
			// Get statistics from file storage
			$pages_data = $pages_data_alltime;

			// Total heatmaps (unique pages with events)
			$total_heatmaps = count( $pages_data );
		} else {
			// Total heatmaps (pages with events) from database
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, event numbers are hardcoded integers
			$total_heatmaps = $wpdb->get_var(
				"SELECT COUNT(DISTINCT page_id2)
				FROM " . $wpdb->prefix . "optibehavior_events
				WHERE event IN (16, 17, 32, 33, 48, 49)"
			);
		}

		// Heatmaps growth (this week vs all time)
		// Compare: How many NEW pages got heatmaps this week
		if ( $use_file_storage ) {
			// Get this week's heatmaps from file storage (window-candidate
			// subset when the agg_* fast path is active — same numbers, only
			// pages that can contribute to the window are scanned). In indexed
			// mode (spec §P2) the window is served from the per-day index —
			// zero file IO. Hoisted single computation (Task 4).
			$pages_data_this_week = $this_week_pages_data;

			// Filter pages that have events from this week
			$week_ago_timestamp = strtotime( $week_ago );
			$this_week_heatmaps = 0;
			foreach ( $pages_data_this_week as $page_data ) {
				if ( isset( $page_data['last_event_time'] ) && strtotime( $page_data['last_event_time'] ) >= $week_ago_timestamp ) {
					$this_week_heatmaps++;
				}
			}
		} else {
			// Get this week's heatmaps from database
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
			$this_week_heatmaps = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT page_id2)
				FROM " . $wpdb->prefix . "optibehavior_events
				WHERE event IN (16, 17, 32, 33, 48, 49)
				AND insert_at >= %s",
				$week_ago
			));
		}

		// Calculate: (this week count / all-time count) * 100
		// Shows what % of all-time heatmaps were created this week
		// Example: 5 this week / 13 all-time = 38% (meaning 38% of all heatmaps are from this week)
		$heatmaps_growth = $total_heatmaps > 0 ?
			round(($this_week_heatmaps / $total_heatmaps) * 100) : 0;

		// Total clicks (click events only)
		if ( $use_file_storage ) {
			// Get total clicks from file storage (hoisted single computation).
			$all_pages_data = $pages_data_alltime;
			$total_clicks = 0;
			foreach ( $all_pages_data as $page_data ) {
				$total_clicks += ( $page_data['click_pc'] + $page_data['click_mobile'] );
			}
		} else{
			// Get total clicks from database
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, event numbers are hardcoded integers
			$total_clicks = $wpdb->get_var(
				"SELECT COUNT(*)
				FROM " . $wpdb->prefix . "optibehavior_events
				WHERE event IN (16, 17)"
			);
		}

		// Clicks growth (this week vs all time)
		if ( $use_file_storage ) {
			// Get this week's clicks from file storage (window-candidate subset
			// when the agg_* fast path is active — see $window_pages_data).
			// Indexed mode serves the window from the per-day index (spec §P2).
			// Hoisted single computation (Task 4) — same window, same rows.
			$this_week_clicks = 0;
			foreach ( $this_week_pages_data as $page_data ) {
				$this_week_clicks += ( $page_data['click_pc'] + $page_data['click_mobile'] );
			}
		} else {
			// Get this week's clicks from database
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
			$this_week_clicks = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*)
				FROM " . $wpdb->prefix . "optibehavior_events
				WHERE event IN (16, 17)
				AND insert_at >= %s",
				$week_ago
			));
		}

		// Calculate: (this week clicks / all-time clicks) * 100
		// Shows what % of all-time clicks happened this week
		// Example: 50 this week / 200 all-time = 25% (meaning 25% of all clicks are from this week)
		$clicks_growth = $total_clicks > 0 ?
			round(($this_week_clicks / $total_clicks) * 100) : 0;

		// Total Sessions — count of visitor sessions in the sessions table,
		// respecting the global spam-exclusion filter (same policy the other
		// cards and the Analytics Dashboard use: spam/bot/automated excluded
		// when the product-wide Spam Detection default is ON).
		if ( $use_file_storage ) {
			// Keep the visible heatmap-session total for the zero-guard below —
			// same spam-filtered universe the heatmap table displays.
			$pages_data    = $pages_data_alltime;
			$visible_total = 0;

			foreach ( $pages_data as $page_data ) {
				$visible_total += isset( $page_data['sessions_desktop'] ) ? (int) $page_data['sessions_desktop'] : 0;
				$visible_total += isset( $page_data['sessions_mobile'] ) ? (int) $page_data['sessions_mobile'] : 0;
			}

			$heatmap_visible_session_total = $visible_total;
		}

		$sessions_spam_clause = $this->get_spam_exclusion_clause( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix; spam clause built from sanitized identifiers.
		$total_sessions = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM " . $wpdb->prefix . "optibehavior_sessions s
			WHERE 1=1" . $sessions_spam_clause
		);

		// Sessions growth (this week vs all time) — same semantics as the
		// Total Heatmaps / Total Clicks cards: what % of all-time sessions
		// started this week.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix; spam clause built from sanitized identifiers.
		$this_week_sessions = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			FROM " . $wpdb->prefix . "optibehavior_sessions s
			WHERE s.start_time >= %s" . $sessions_spam_clause,
			$week_ago
		));

		$sessions_growth = $total_sessions > 0 ?
			round( ( $this_week_sessions / $total_sessions ) * 100 ) : 0;

		// Average time on page - calculate total duration divided by total page views
		// This gives the true average time spent per page view across all sessions
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		$avg_time = $wpdb->get_var(
			"SELECT ROUND(SUM(duration) / SUM(page_views))
			FROM " . $wpdb->prefix . "optibehavior_sessions
			WHERE duration > 0 AND page_views > 0"
		);
		$avg_time = $avg_time ? intval($avg_time) : 0;

		// Time improvement (this week vs all time average)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		$this_week_avg_time = $wpdb->get_var( $wpdb->prepare(
			"SELECT ROUND(SUM(duration) / SUM(page_views))
			FROM " . $wpdb->prefix . "optibehavior_sessions
			WHERE duration > 0 AND page_views > 0
			AND start_time >= %s",
			$week_ago
		));

		// Compare this week's avg time to all-time avg time
		$time_improvement = ($avg_time > 0 && $this_week_avg_time > 0) ?
			round((($this_week_avg_time - $avg_time) / $avg_time) * 100) : 0;

		// Hottest page (page with most clicks)
		if ( $use_file_storage ) {
			// Get hottest page from file storage (hoisted single computation).
			$pages_data = $pages_data_alltime;

			// Find page with most clicks
			$hottest_page_id = null;
			$max_clicks = 0;

			foreach ( $pages_data as $page_data ) {
				$page_clicks = $page_data['click_pc'] + $page_data['click_mobile'];
				if ( $page_clicks > $max_clicks ) {
					$max_clicks = $page_clicks;
					$hottest_page_id = $page_data['page_id'];
				}
			}

			// Get page title from database
			if ( $hottest_page_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
				$hottest_page = $wpdb->get_row( $wpdb->prepare(
					"SELECT title, url
					FROM " . $wpdb->prefix . "optibehavior_pages
					WHERE id = %d",
					$hottest_page_id
				) );

				$hottest_page_clicks = $max_clicks;
				$hottest_page_name = $hottest_page ? $hottest_page->title : 'No data';
			} else {
				$hottest_page_clicks = 0;
				$hottest_page_name = 'No data';
			}
		} else {
			// Get hottest page from database
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
			$hottest_page = $wpdb->get_row(
				"SELECT p.title, p.url, COUNT(*) as clicks
				FROM " . $wpdb->prefix . "optibehavior_events e
				LEFT JOIN " . $wpdb->prefix . "optibehavior_pages p ON e.page_id2 = p.id
				WHERE e.event IN (16, 17)
				GROUP BY e.page_id2
				ORDER BY clicks DESC
				LIMIT 1"
			);

			$hottest_page_clicks = $hottest_page ? $hottest_page->clicks : 0;
			$hottest_page_name = $hottest_page ? $hottest_page->title : 'No data';
		}

		// Click-through Rate (CTR) - all time
		// Correct semantics: % of pageviews that resulted in at least one click.
		//   numerator   = number of unique (session, page) pairs that recorded
		//                 a click event (event IN 16,17).
		//   denominator = total pageview rows.
		//
		// The earlier formula divided TOTAL click events by pageviews, which
		// produced nonsensical values (e.g. 1166.7 %) whenever the average
		// clicks-per-pageview exceeded 1. CTR is by definition a ratio in
		// [0 %, 100 %]; the new formula guarantees that.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		$pageviews_with_click = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT CONCAT_WS('|', session_id, page_id2))
			FROM " . $wpdb->prefix . "optibehavior_events
			WHERE event IN (16, 17)"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		$total_pageviews = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM " . $wpdb->prefix . "optibehavior_pageviews"
		);

		$conversion_rate = $total_pageviews > 0 ?
			min( 100.0, round( ( $pageviews_with_click / $total_pageviews ) * 100, 1 ) ) : 0;

		// CTR improvement (this week vs all time) — same reconciled semantics:
		// numerator is unique (session, page) pairs with clicks in the window,
		// denominator is pageview rows in the window. Result always ≤ 100 %.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		$this_week_pageviews_with_click = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT CONCAT_WS('|', session_id, page_id2))
			FROM " . $wpdb->prefix . "optibehavior_events
			WHERE event IN (16, 17)
			AND insert_at >= %s",
			$week_ago
		));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		$this_week_views = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			FROM " . $wpdb->prefix . "optibehavior_pageviews
			WHERE view_time >= %s",
			$week_ago
		));

		$this_week_ctr = $this_week_views > 0 ?
			min( 100.0, round( ( $this_week_pageviews_with_click / $this_week_views ) * 100, 1 ) ) : 0;

		// Compare this week's CTR to all-time CTR
		$ctr_improvement = $conversion_rate > 0 ?
			round((($this_week_ctr - $conversion_rate) / $conversion_rate) * 100) : 0;

		// If the global spam filter leaves no valid heatmap sessions, every Heatmap
		// card must render zero/no data. Otherwise the Heatmap page contradicts the
		// Analytics Dashboard, which already filters these sessions out.
		if ( $use_file_storage && $this->is_spam_excluded() && 0 === (int) $heatmap_visible_session_total ) {
			$avg_time        = 0;
			$time_improvement = 0;
			$conversion_rate = 0;
			$ctr_improvement = 0;
		}

		$stats = array(
			'total_heatmaps' => intval($total_heatmaps),
			'heatmaps_growth' => intval($heatmaps_growth),
			'total_clicks' => intval($total_clicks),
			'clicks_growth' => intval($clicks_growth),
			'total_sessions' => intval($total_sessions),
			'sessions_growth' => intval($sessions_growth),
			'avg_time' => intval($avg_time),
			'time_improvement' => intval($time_improvement),
			'hottest_page_clicks' => intval($hottest_page_clicks),
			'hottest_page_name' => $hottest_page_name,
			'conversion_rate' => floatval($conversion_rate),
			'ctr_improvement' => intval($ctr_improvement)
		);

		$stats_ttl = function_exists( 'opti_behavior_heatmap_cache_ttl' )
			? opti_behavior_heatmap_cache_ttl()
			: 15 * MINUTE_IN_SECONDS;

		if ( $stats_cache_key ) {
			// A degraded cold payload (Task 4: no stale copy, week deltas zeroed)
			// is cached only briefly so requests keep re-arming the warm cron and
			// pick up the exact cron-computed payload within a minute of it landing.
			set_transient( $stats_cache_key, $stats, $stats_cold ? MINUTE_IN_SECONDS : $stats_ttl );
		}

		if ( ! $stats_cold ) {
			// Version-independent stale-while-revalidate copy (Task 4): only ever
			// holds an EXACT payload — the degraded cold result must not poison
			// the SWR fallback. Longer TTL, same rationale as the canonlist copy.
			set_transient( $stats_stale_key, $stats, max( $stats_ttl * 4, HOUR_IN_SECONDS ) );
		}

		return $stats;
	}

	/**
	 * Schedule a one-off background warm-up of the heatmap KPI stats payload
	 * (Task 4 SWR + cron): the cron context recomputes the EXACT numbers with
	 * the full pipeline (file scans are uncapped off the request path) and
	 * refreshes both the versioned transient and the stale SWR copy, so the
	 * next stats request serves exact values from cache. Dedupes per spam state.
	 *
	 * @since 1.9.x
	 * @return void
	 */
	private function schedule_heatmap_stats_warm() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		$spam = $this->is_spam_excluded() ? '1' : '0';
		if ( ! wp_next_scheduled( self::HEATMAP_STATS_WARM_CRON_HOOK, array( $spam ) ) ) {
			wp_schedule_single_event( time() + 30, self::HEATMAP_STATS_WARM_CRON_HOOK, array( $spam ) );
		}
	}

	/**
	 * Cron callback: background recompute of the heatmap KPI stats payload for
	 * one spam state (Task 4 SWR + cron for cold windows).
	 *
	 * Runs in cron context, where the never-inline-full-scan guard of
	 * {@see get_heatmap_statistics()} is inactive by design: this is the ONE
	 * place the exact full aggregation is allowed to run at scale, off the
	 * request path. The (possibly degraded) versioned transient is dropped
	 * first so the recompute actually happens and lands in both caches.
	 *
	 * @since 1.9.x
	 * @param string $spam '1' = spam-excluded view, '0' = spam-included view.
	 * @return void
	 */
	public function run_heatmap_stats_warm( $spam = '1' ) {
		$prev_flag = isset( $GLOBALS['opti_behavior_exclude_spam'] ) ? $GLOBALS['opti_behavior_exclude_spam'] : null;

		$GLOBALS['opti_behavior_exclude_spam'] = ( '1' === (string) $spam );

		$stats_cache_key = function_exists( 'opti_behavior_heatmap_cache_key' )
			? opti_behavior_heatmap_cache_key( 'stats', array(
				'spam' => $this->is_spam_excluded() ? '1' : '0',
				'day'  => gmdate( 'Y-m-d' ),
			) )
			: '';
		if ( $stats_cache_key ) {
			delete_transient( $stats_cache_key );
		}

		$this->get_heatmap_statistics();

		if ( null === $prev_flag ) {
			unset( $GLOBALS['opti_behavior_exclude_spam'] );
		} else {
			$GLOBALS['opti_behavior_exclude_spam'] = $prev_flag;
		}
	}

	/**
	 * Get enhanced heatmap data from database or file storage
	 */
	private function get_enhanced_heatmap_data() {
		global $wpdb;

		// Check storage mode
		$file_storage = $this->heatmap->get_file_storage();
		$storage_settings = $file_storage ? $file_storage->get_settings() : array( 'storage_mode' => 'database' );

		if ( $storage_settings['storage_mode'] === 'file' && $file_storage ) {
			// Get pages with heatmap data from file storage
			$pages_data = $file_storage->get_pages_with_heatmap_data();

			// Convert to objects for compatibility with database results
			$results = array();
			foreach ( $pages_data as $page_data ) {

				// Get page info from database
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
				$page_info = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, title, url, update_at
					FROM " . $wpdb->prefix . "optibehavior_pages
					WHERE id = %d",
					$page_data['page_id']
				) );

				if ( $page_info ) {
					$obj = new stdClass();
					$obj->id = $page_info->id;
					$obj->title = $page_info->title;
					$obj->url = $page_info->url;
					$obj->update_at = $page_info->update_at;
					$obj->click_pc = $page_data['click_pc'];
					$obj->click_mobile = $page_data['click_mobile'];
					$obj->breakaway_pc = $page_data['breakaway_pc'];
					$obj->breakaway_mobile = $page_data['breakaway_mobile'];
					$obj->attention_pc = $page_data['attention_pc'];
					$obj->attention_mobile = $page_data['attention_mobile'];
					$obj->last_event_time = $page_data['last_event_time'];
					$results[] = $obj;
				}
			}

			// Sort by last event time descending
			usort( $results, function( $a, $b ) {
				return strtotime( $b->last_event_time ) - strtotime( $a->last_event_time );
			});
		} else {
			// Get pages with heatmap data from database
			$query = "SELECT
				p.id,
				p.title,
				p.url,
				p.update_at,
				COUNT(CASE WHEN e.event = 16 THEN 1 END) as click_pc,
				COUNT(CASE WHEN e.event = 17 THEN 1 END) as click_mobile,
				COUNT(CASE WHEN e.event = 32 THEN 1 END) as breakaway_pc,
				COUNT(CASE WHEN e.event = 33 THEN 1 END) as breakaway_mobile,
				COUNT(CASE WHEN e.event = 48 THEN 1 END) as attention_pc,
				COUNT(CASE WHEN e.event = 49 THEN 1 END) as attention_mobile,
				MAX(e.insert_at) as last_event_time
			FROM " . $wpdb->prefix . "optibehavior_pages p
			LEFT JOIN " . $wpdb->prefix . "optibehavior_events e ON p.id = e.page_id2
			WHERE e.event IN (16, 17, 32, 33, 48, 49)
			GROUP BY p.id
			HAVING (click_pc + click_mobile + breakaway_pc + breakaway_mobile + attention_pc + attention_mobile) > 0
			ORDER BY last_event_time DESC";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static query with no user input, no dynamic parameters
			$results = $wpdb->get_results( $query );
		}

		$heatmaps = array();
		$thumbnail_placeholder = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjEyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZjBmMGYwIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPkhFQVRNQVA8L3RleHQ+PC9zdmc+';

		foreach ($results as $row) {
			// Get session count for this page URL using pageviews table
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
			$session_count = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(DISTINCT pv.session_id)
				FROM " . $wpdb->prefix . "optibehavior_pageviews pv
				WHERE pv.url = %s OR pv.url LIKE %s",
				$row->url,
				$row->url . '%'
			));

			// If no pageviews data, try sessions table with entry_page/exit_page
			if (!$session_count) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
				$session_count = $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(DISTINCT id)
					FROM " . $wpdb->prefix . "optibehavior_sessions
					WHERE entry_page = %s OR exit_page = %s OR entry_page LIKE %s OR exit_page LIKE %s",
					$row->url,
					$row->url,
					$row->url . '%',
					$row->url . '%'
				));
			}

			$session_count = $session_count ? intval($session_count) : 0;
			// Calculate total interactions
			$total_interactions = $row->click_pc + $row->click_mobile +
								$row->breakaway_pc + $row->breakaway_mobile +
								$row->attention_pc + $row->attention_mobile;

			// Determine primary heatmap type based on highest count
			$type = 'click';
			$type_icon = '👆';
			$max_count = max($row->click_pc + $row->click_mobile,
							$row->breakaway_pc + $row->breakaway_mobile,
							$row->attention_pc + $row->attention_mobile);

			if ($max_count == ($row->breakaway_pc + $row->breakaway_mobile)) {
				$type = 'scroll';
				$type_icon = '📜';
			} elseif ($max_count == ($row->attention_pc + $row->attention_mobile)) {
				$type = 'attention';
				$type_icon = '👁️';
			}

			// Calculate mobile percentage
			$mobile_interactions = $row->click_mobile + $row->breakaway_mobile + $row->attention_mobile;
			$mobile_percentage = $total_interactions > 0 ?
				round(($mobile_interactions / $total_interactions) * 100) : 0;

			// Calculate time since last update
			$last_updated = $this->time_elapsed_string($row->last_event_time);

			// Determine status based on recent activity
			$hours_since_update = (time() - strtotime($row->last_event_time)) / 3600;
			$status = 'active';
			$status_icon = '🟢';

			if ($hours_since_update > 168) { // 1 week
				$status = 'inactive';
				$status_icon = '🔴';
			} elseif ($hours_since_update > 24) { // 1 day
				$status = 'processing';
				$status_icon = '🟡';
			}

			// Generate proper heatmap view URL based on actual data
			$event_name = 'click_pc'; // Default to PC clicks
			$has_mixed_devices = false;

			// Determine the correct event type and check for mixed device data
			if ($type === 'click') {
				$has_pc = $row->click_pc > 0;
				$has_mobile = $row->click_mobile > 0;
				$has_mixed_devices = $has_pc && $has_mobile;

				if ($has_mobile && !$has_pc) {
					$event_name = 'click_mobile';
				} else {
					// Default to PC view for mixed devices or PC-only
					$event_name = 'click_pc';
				}
			} elseif ($type === 'scroll') {
				$has_pc = $row->breakaway_pc > 0;
				$has_mobile = $row->breakaway_mobile > 0;
				$has_mixed_devices = $has_pc && $has_mobile;

				if ($has_mobile && !$has_pc) {
					$event_name = 'breakaway_mobile';
				} else {
					// Default to PC view for mixed devices or PC-only
					$event_name = 'breakaway_pc';
				}
			} elseif ($type === 'attention') {
				$has_pc = $row->attention_pc > 0;
				$has_mobile = $row->attention_mobile > 0;
				$has_mixed_devices = $has_pc && $has_mobile;

				if ($has_mobile && !$has_pc) {
					$event_name = 'attention_mobile';
				} else {
					// Default to PC view for mixed devices or PC-only
					$event_name = 'attention_pc';
				}
			}

			// Create proper heatmap URL that opens the page with overlay
			$page_url = $row->url;
			$param = (false === strpos($page_url, '?')) ? '?' : '&';
			$param .= 'opti-behavior=' . $event_name . '-' . $row->id;

			// Insert query string
			if (false === strpos($page_url, '#')) {
				$view_url = $page_url . $param;
			} else {
				$s = explode('#', $page_url, 2);
				$view_url = $s[0] . $param . '#' . $s[1];
			}

			// Create PC-only and Mobile-only URLs
			$pc_param = (false === strpos($page_url, '?')) ? '?' : '&';
			$pc_param .= 'opti-behavior=' . $type . '_pc-' . $row->id;

			// Mobile URLs include viewport simulation parameters for accurate display
			$mobile_param = (false === strpos($page_url, '?')) ? '?' : '&';
			$mobile_param .= 'opti-behavior=' . $type . '_mobile-' . $row->id . '&mobile_view=1&vw=375&vh=667';

			// Generate PC and Mobile URLs
			if (false === strpos($page_url, '#')) {
				$view_pc_url = $page_url . $pc_param;
				$view_mobile_url = $page_url . $mobile_param;
			} else {
				$s = explode('#', $page_url, 2);
				$view_pc_url = $s[0] . $pc_param . '#' . $s[1];
				$view_mobile_url = $s[0] . $mobile_param . '#' . $s[1];
			}

			// Clean up title and URL for display
			$title = $row->title ? $row->title : 'Untitled Page';
			$url = $row->url;

			// Truncate long titles
			if (strlen($title) > 50) {
				$title = substr($title, 0, 47) . '...';
			}

			$heatmaps[] = array(
				'id' => $row->id,
				'title' => $title,
				'url' => $url,
				'type' => $type,
				'type_icon' => $type_icon,
				'interactions' => $total_interactions,
				'sessions' => $session_count,
				'device_split' => $mobile_percentage . '%',
					'mobile_percentage' => intval($mobile_percentage),

			);
			// close previous function properly
		}

			// Return compiled rows for dashboard
			return $heatmaps;
		}



		/**
		 * Optimized paginated fetch for heatmap rows
		 * Returns array( $rows, $total ) where $rows are already mapped to UI fields
		 */
		private function get_heatmap_rows_paged($orderby, $order, $page, $per_page, $start_date = null, $end_date = null, $search = '') {
			global $wpdb;

			// Check storage mode
			$file_storage = $this->heatmap->get_file_storage();
			$storage_settings = $file_storage ? $file_storage->get_settings() : array( 'storage_mode' => 'database' );
			$heatmap_file_pages_data = $this->get_heatmap_pages_data_from_file_source();

			if ( 'file' === $storage_settings['storage_mode'] || ! empty( $heatmap_file_pages_data ) ) {
				// Use file storage implementation whenever heatmap files exist. The detail
				// page and device buttons are file-based, so the list must use the same
				// source even when the saved storage mode is still "database".
				return $this->get_heatmap_rows_paged_from_files( $orderby, $order, $page, $per_page, $start_date, $end_date, $search );
			}
			// Database storage implementation (original code)
			$order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
			$offset = max(0, ($page - 1) * $per_page);
			$allowed_map = array(
				'interactions' => 'interactions',
				'sessions'     => 'sessions',
				'last_updated' => 'last_event_time'
			);
			$order_by_sql = isset($allowed_map[$orderby]) ? $allowed_map[$orderby] : 'last_event_time';

			// Validate offset and per_page are integers
			$offset = absint($offset);
			$per_page = absint($per_page);

			// Build search filter — matches page title OR URL so users can find
			// a page by either (the search input placeholder "Search heatmaps..."
			// implies both; previously URL-only searches returned zero rows).
			$search_filter = '';
			$search_params = array();
			if (!empty($search)) {
				$search_like = '%' . $wpdb->esc_like($search) . '%';
				$search_filter = " AND (p.title LIKE %s OR p.url LIKE %s)";
				$search_params[] = $search_like;
				$search_params[] = $search_like;
			}

			// Total distinct pages with heatmap events (fast count) with optional date range and search
			if (!empty($search)) {
				// With search, need to join pages table
				if ($start_date && $end_date) {
					$count_params = array_merge(array($start_date, $end_date), $search_params);
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
					$total = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(DISTINCT e.page_id2) FROM " . $wpdb->prefix . "optibehavior_events e INNER JOIN " . $wpdb->prefix . "optibehavior_pages p ON e.page_id2 = p.id WHERE e.event IN (16,17,32,33,48,49) AND e.insert_at BETWEEN %s AND %s AND (p.title LIKE %s OR p.url LIKE %s)",
						$count_params
					));
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
					$total = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(DISTINCT e.page_id2) FROM " . $wpdb->prefix . "optibehavior_events e INNER JOIN " . $wpdb->prefix . "optibehavior_pages p ON e.page_id2 = p.id WHERE e.event IN (16,17,32,33,48,49) AND (p.title LIKE %s OR p.url LIKE %s)",
						$search_params
					));
				}
			} else {
				// Without search, original query
				if ($start_date && $end_date) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
					$total = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(DISTINCT page_id2) FROM " . $wpdb->prefix . "optibehavior_events e WHERE e.event IN (16,17,32,33,48,49) AND e.insert_at BETWEEN %s AND %s",
						$start_date, $end_date
					));
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
					$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT page_id2) FROM " . $wpdb->prefix . "optibehavior_events WHERE event IN (16,17,32,33,48,49)" );
				}
			}
			if ($total === 0) { return array(array(), 0); }

				// Unified aggregated query for all orderings. The old "id-first"
				// preselect branch (COUNT(DISTINCT pv.session_id) FROM pageviews +
				// hardcoded sessions_desktop/mobile = 0) has been removed: it
				// produced session totals that diverged from the canonical
				// session_pages + recordings counts used below, so a page's
				// numbers changed depending on the sort column. All orderings now
				// share the single canonical-overlaid query path below.
				$pages_table = $wpdb->prefix . 'optibehavior_pages';
				$events_table = $wpdb->prefix . 'optibehavior_events';
				$pageviews_table = $wpdb->prefix . 'optibehavior_pageviews';

				// Check if Pro version tables exist (for seamless Free/Pro compatibility)
				$recordings_table = $wpdb->prefix . 'optibehavior_recordings';
				$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
				$visitors_table = $wpdb->prefix . 'optibehavior_visitors';
				$session_pages_table = $wpdb->prefix . 'optibehavior_session_pages';

				// Check if Pro plugin is active (not just if table exists)
				$has_pro_tables = function_exists('opti_behavior_pro_active') && opti_behavior_pro_active();

				// Aggregated current slice with sessions via subquery (leverages url index)
				$date_filter_events = ($start_date && $end_date) ? " AND e.insert_at BETWEEN %s AND %s" : '';
				$date_filter_pv     = ($start_date && $end_date) ? " WHERE pv.view_time BETWEEN %s AND %s" : '';
				$date_filter_events_sub = ($start_date && $end_date) ? " AND e2.insert_at BETWEEN '" . $start_date . "' AND '" . $end_date . "'" : '';

				// Count sessions by device from events table (works for both Free and Pro)
				// Desktop events: 16 (click_pc), 32 (breakaway_pc), 48 (attention_pc)
				// Mobile events: 17 (click_mobile), 33 (breakaway_mobile), 49 (attention_mobile)
				$select_sessions = ", COALESCE(sess.total_sessions,0) AS sessions, COALESCE(sess.desktop_sessions,0) AS desktop_sessions, COALESCE(sess.mobile_sessions,0) AS mobile_sessions";

				$join_sessions = " LEFT JOIN (
					SELECT e2.page_id2 as page_id,
						COUNT(DISTINCT e2.session_id) AS total_sessions,
						COUNT(DISTINCT CASE WHEN e2.event IN (16,32,48) THEN e2.session_id END) AS desktop_sessions,
						COUNT(DISTINCT CASE WHEN e2.event IN (17,33,49) THEN e2.session_id END) AS mobile_sessions
					FROM " . $events_table . " e2
					WHERE e2.event IN (16,17,32,33,48,49)" . $date_filter_events_sub . "
					GROUP BY e2.page_id2
				) sess ON sess.page_id = p.id";

				$all_joins = $join_sessions;

				$sql =
				"SELECT p.id, p.title, p.url,
					SUM(CASE WHEN e.event = 16 THEN 1 ELSE 0 END) AS click_pc,
					SUM(CASE WHEN e.event = 17 THEN 1 ELSE 0 END) AS click_mobile,
					SUM(CASE WHEN e.event = 32 THEN 1 ELSE 0 END) AS breakaway_pc,
					SUM(CASE WHEN e.event = 33 THEN 1 ELSE 0 END) AS breakaway_mobile,
					SUM(CASE WHEN e.event = 48 THEN 1 ELSE 0 END) AS attention_pc,
					SUM(CASE WHEN e.event = 49 THEN 1 ELSE 0 END) AS attention_mobile,
					SUM(CASE WHEN e.event IN (16,17,32,33,48,49) THEN 1 ELSE 0 END) AS interactions,
					MAX(e.insert_at) AS last_event_time" . $select_sessions . "
				FROM " . $pages_table . " p
				JOIN " . $events_table . " e ON p.id = e.page_id2 AND e.event IN (16,17,32,33,48,49)" . $date_filter_events . $search_filter . $all_joins . "
				GROUP BY p.id
				HAVING interactions > 0
				ORDER BY " . $order_by_sql . " " . $order . "
				LIMIT %d OFFSET %d";
			$params = array();
			if ($start_date && $end_date) { $params[] = $start_date; $params[] = $end_date; }
			// Add search params if searching
			if (!empty($search)) { $params = array_merge($params, $search_params); }
			// Note: sessions subquery uses hardcoded dates, no extra placeholders needed
			$params[] = $per_page; $params[] = $offset;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names are prefixed safely, ORDER BY/direction are validated, all user inputs properly parameterized
			$results = $wpdb->get_results( $wpdb->prepare($sql, $params) );

			$display_sessions_map = array();
			$display_desktop_map  = array();
			$display_mobile_map   = array();
			if ( ! empty( $results ) ) {
				$result_page_ids = array_map(
					static function( $result_row ) {
						return isset( $result_row->id ) ? absint( $result_row->id ) : 0;
					},
					$results
				);
				$result_page_ids = array_values( array_filter( array_unique( $result_page_ids ) ) );

				// Use the file/canonical counters when heatmap files exist OR when
				// the Pro canonical DB path is available, so the list matches the
				// detail page even for recording-only (no-file) pages.
				$canonical_map = $this->get_canonical_db_device_counts( $result_page_ids, $start_date, $end_date );
				$has_canonical = null !== $canonical_map;
				if ( ! empty( $result_page_ids ) && ( $has_canonical || $this->has_heatmap_file_data_for_pages( $result_page_ids ) ) ) {
					$display_sessions_map = $this->batch_sessions_counts_by_page_from_files( $result_page_ids, $start_date, $end_date );
					$display_desktop_map  = $this->batch_recording_counts_by_device( $result_page_ids, 'desktop', $start_date, $end_date );
					$display_mobile_map   = $this->batch_recording_counts_by_device( $result_page_ids, 'mobile', $start_date, $end_date );
				}
			}

			$rows = array();
			$thumbnail_placeholder = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjEyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZjBmMGYwIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxNCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPkhFQVRNQVA8L3RleHQ+PC9zdmc+';
			foreach ($results as $row) {
					$click_pc = (int)$row->click_pc; $click_mobile = (int)$row->click_mobile;
					$break_pc = (int)$row->breakaway_pc; $break_mobile = (int)$row->breakaway_mobile;
					$att_pc = (int)$row->attention_pc; $att_mobile = (int)$row->attention_mobile;
					$total_interactions = $click_pc + $click_mobile + $break_pc + $break_mobile + $att_pc + $att_mobile;
					$type = 'click'; $max_bucket = max($click_pc + $click_mobile, $break_pc + $break_mobile, $att_pc + $att_mobile);
					if ($max_bucket === ($break_pc + $break_mobile)) { $type='scroll'; }
					elseif ($max_bucket === ($att_pc + $att_mobile)) { $type='attention'; }
					$last_updated = $this->time_elapsed_string($row->last_event_time);
					$page_url = $row->url; $id = (int)$row->id;
					$pc_url         = admin_url( 'admin.php?page=opti-behavior-heatmap-detail&page_id=' . $id . '&type=' . $type . '&device=desktop' );
					$view_url       = $pc_url; // Use admin heatmap detail URL
					$mobile_url     = admin_url( 'admin.php?page=opti-behavior-heatmap-detail&page_id=' . $id . '&type=' . $type . '&device=mobile' );

					// Get session counts by device from query results
					$total_sessions = isset($row->sessions) ? (int)$row->sessions : 0;
					$desktop_sessions = isset($row->desktop_sessions) ? (int)$row->desktop_sessions : 0;
					$mobile_sessions = isset($row->mobile_sessions) ? (int)$row->mobile_sessions : 0;

					if ( isset( $display_sessions_map[ $id ] ) ) {
						$total_sessions   = (int) $display_sessions_map[ $id ];
						$desktop_sessions = isset( $display_desktop_map[ $id ] ) ? (int) $display_desktop_map[ $id ] : 0;
						$mobile_sessions  = isset( $display_mobile_map[ $id ] ) ? (int) $display_mobile_map[ $id ] : 0;
					}

					// Calculate mobile percentage from sessions (for DEVICE SPLIT column)
					$mobile_percentage = $total_sessions > 0 ? round(($mobile_sessions / $total_sessions) * 100) : 0;

					$rows[] = array(
						'id' => $id,
						'title' => $row->title ?: 'Untitled Page',
						'url' => $row->url,
						'interactions' => (int)$total_interactions,
						'sessions' => $total_sessions,
						'mobile_percentage' => (int)$mobile_percentage,
						'last_updated' => $last_updated,
						'views_total' => $total_sessions,
						'views_pc' => $desktop_sessions,
						'views_mobile' => $mobile_sessions,
						'last_event_time' => (int)strtotime($row->last_event_time),
						'view_url' => $view_url,
						'view_pc_url' => $pc_url,
						'view_mobile_url' => $mobile_url,
						// Session counts by device type (for Desktop/Mobile buttons)
						'sessions_desktop' => $desktop_sessions,
						'sessions_mobile' => $mobile_sessions,
						'thumbnail' => $thumbnail_placeholder,
						// Pro breakaway/attention event counts (event 32/33/48/49)
						'breakaway_pc'     => (int)$break_pc,
						'breakaway_mobile' => (int)$break_mobile,
						'attention_pc'     => (int)$att_pc,
						'attention_mobile' => (int)$att_mobile,
					);

					// Dual-metric fields (spec §1/§2), same pass-through as the
					// file-storage branch below: get_canonical_db_device_counts()
					// already attaches these onto $canonical_map per page id when
					// Pro's sync-status resolver is available. Additive — absent on
					// Free-only installs or pages with no cached sync-status entry.
					if ( isset( $canonical_map[ $id ]['sessions_with_interactions'] ) ) {
						$rows[ count( $rows ) - 1 ]['sessions_with_interactions'] = $canonical_map[ $id ]['sessions_with_interactions'];
					}
					if ( isset( $canonical_map[ $id ]['desynced'] ) ) {
						$rows[ count( $rows ) - 1 ]['desynced'] = (bool) $canonical_map[ $id ]['desynced'];
					}
				}
				return array($rows, $total);

		}

		/**
		 * Collapse multiple `optibehavior_heatmap_pages` rows for the same
		 * `page_id` into a single row.
		 *
		 * The mapping table keys on `url_hash` (one row per distinct URL
		 * variant a page has ever been seen under, e.g. with/without a
		 * trailing slash — {@see Opti_Behavior_Heatmap_Storage::update_url_hash_mapping()}),
		 * not on `page_id`. Both `get_pages_with_heatmap_data()` (raw, one
		 * entry per mapping row) and `maybe_fast_heatmap_pages_data()`
		 * (indexed, also one entry per mapping row — its own agg_* columns
		 * already carry the *full* page aggregate, since
		 * {@see sync_heatmap_aggregate_columns()} updates every mapping row
		 * sharing a page_id with the same computed totals) can therefore
		 * legitimately return 2+ array entries that describe the same
		 * logical page. Left uncollapsed, that multiplicity leaks straight
		 * into the Heatmaps list as duplicate consecutive rows and an
		 * inflated "Showing X-Y of N" total.
		 *
		 * Two duplicate shapes are handled differently so no real data is
		 * ever silently dropped:
		 * - Rows whose comparable metric fields already match exactly (the
		 *   common case — both already carry the same full-page aggregate)
		 *   are true double-inclusions: the extra row is discarded.
		 * - Rows whose fields differ (a rarer case — raw, not-yet-aggregated
		 *   per-URL-variant partial counts, e.g. clicks split across a
		 *   trailing-slash and non-trailing-slash bucket) are merged by
		 *   summing the INTERACTION fields, so the page's real total is
		 *   preserved rather than truncated to whichever duplicate happened
		 *   to be kept.
		 *
		 * SESSION fields (sessions, sessions_desktop/mobile/tablet) are NEVER
		 * summed across duplicates — every pipeline that stamps them before
		 * this dedupe writes PAGE-LEVEL totals onto each mapping row (the
		 * fast path's agg_* columns store the full page aggregate on every
		 * row sharing a page_id, and overlay_canonical_session_counts()
		 * likewise stamps the same canonical DB total onto every duplicate).
		 * Summing them double-counted the page's sessions whenever the
		 * duplicate rows differed in any other field (e.g. one row carrying
		 * stale agg interaction values), producing the live "list says 12,
		 * detail says 6" mismatch for page_id=4: two mapping rows, each
		 * overlaid with the canonical guest total of 6, merged to 12. The
		 * merge keeps the MAX instead (identical values in the healthy case;
		 * the closest-to-complete value when one row is stale).
		 *
		 * @since 1.9.0
		 * @param array $pages_data Raw page aggregate rows, one per mapping row.
		 * @return array De-duplicated rows, at most one per page_id (rows with
		 *                no page_id are passed through unchanged).
		 */
		private function dedupe_heatmap_pages_data_by_page_id( array $pages_data ) {
			if ( empty( $pages_data ) ) {
				return $pages_data;
			}

			// Per-URL-variant partials: summing across duplicate rows preserves
			// the page total.
			$summable_fields = array(
				'click_pc', 'click_mobile', 'breakaway_pc', 'breakaway_mobile',
				'attention_pc', 'attention_mobile',
			);
			// Page-scope totals: already stamped with the full page value on
			// every duplicate row (agg_* columns / canonical overlay) — summing
			// double-counts, so merge keeps the max instead.
			$page_scope_fields = array(
				'sessions', 'sessions_desktop', 'sessions_mobile', 'sessions_tablet',
			);
			$mergeable_fields  = array_merge( $summable_fields, $page_scope_fields );

			$merged      = array();
			$index_by_id = array();

			foreach ( $pages_data as $row ) {
				$page_id = isset( $row['page_id'] ) ? (int) $row['page_id'] : 0;

				if ( ! $page_id || ! isset( $index_by_id[ $page_id ] ) ) {
					if ( $page_id ) {
						$index_by_id[ $page_id ] = count( $merged );
					}
					$merged[] = $row;
					continue;
				}

				$idx      = $index_by_id[ $page_id ];
				$existing = $merged[ $idx ];

				$is_exact_duplicate = true;
				foreach ( $mergeable_fields as $field ) {
					$existing_val = isset( $existing[ $field ] ) ? (int) $existing[ $field ] : 0;
					$row_val      = isset( $row[ $field ] ) ? (int) $row[ $field ] : 0;
					if ( $existing_val !== $row_val ) {
						$is_exact_duplicate = false;
						break;
					}
				}

				if ( ! $is_exact_duplicate ) {
					foreach ( $summable_fields as $field ) {
						if ( ! isset( $existing[ $field ] ) && ! isset( $row[ $field ] ) ) {
							continue;
						}
						$existing_val               = isset( $existing[ $field ] ) ? (int) $existing[ $field ] : 0;
						$row_val                     = isset( $row[ $field ] ) ? (int) $row[ $field ] : 0;
						$merged[ $idx ][ $field ]    = $existing_val + $row_val;
					}
					foreach ( $page_scope_fields as $field ) {
						if ( ! isset( $existing[ $field ] ) && ! isset( $row[ $field ] ) ) {
							continue;
						}
						$existing_val               = isset( $existing[ $field ] ) ? (int) $existing[ $field ] : 0;
						$row_val                     = isset( $row[ $field ] ) ? (int) $row[ $field ] : 0;
						$merged[ $idx ][ $field ]    = max( $existing_val, $row_val );
					}
				}

				// Keep the most recent last_event_time across the merged rows either way.
				if ( ! empty( $row['last_event_time'] )
					&& ( empty( $existing['last_event_time'] ) || strtotime( $row['last_event_time'] ) > strtotime( $existing['last_event_time'] ) )
				) {
					$merged[ $idx ]['last_event_time'] = $row['last_event_time'];
				}
			}

			return $merged;
		}

		/**
		 * Get heatmap rows from file storage with pagination and sorting
		 *
		 * @param string $orderby Column to order by
		 * @param string $order ASC or DESC
		 * @param int $page Current page number
		 * @param int $per_page Items per page
		 * @param string|null $start_date Start date filter
		 * @param string|null $end_date End date filter
		 * @return array Array of [rows, total]
		 */
		private function get_heatmap_rows_paged_from_files($orderby, $order, $page, $per_page, $start_date = null, $end_date = null, $search = '') {
			global $wpdb;

			$file_storage = $this->heatmap->get_file_storage();

			// Task 3 (spec §4-A.2): for the default all-time view, answer sort +
			// pagination + total count in SQL over the precomputed agg_* columns,
			// so dedupe, the known-pages (orphan) filter, the canonical sessions
			// overlay and the render loop only ever touch the displayed rows
			// (per_page) instead of the full mapping set (~35k at customer scale).
			// Returns null when not applicable (dated view, non-default spam,
			// unsynced backlog, or a sort the agg_* columns cannot answer) — the
			// existing in-memory pipeline below then handles the request.
			if ( null === $start_date && null === $end_date ) {
				$sql_paged = $this->maybe_sql_paged_heatmap_rows( $orderby, $order, $page, $per_page, $search );
				if ( null !== $sql_paged ) {
					return $sql_paged;
				}
			}

			// Phase 3 fast path: when the view is all-time + default-spam-excluded,
			// source row metrics from the precomputed aggregate columns (indexed),
			// skipping per-request file IO. Returns null when not applicable.
			$from_columns = false;
			$pages_data   = $this->maybe_fast_heatmap_pages_data( $start_date, $end_date );

			// Adaptive mode (spec §P0): dated requests (incl. the page default
			// last30days) on installs above the live-scan threshold — or after the
			// self-measuring valve tripped — are served from the per-day index
			// (O(pages), zero file IO) instead of the O(files) live scan below.
			// Below the threshold nothing changes: small installs keep the live
			// near-real-time scan.
			if ( null === $pages_data
				&& ( null !== $start_date || null !== $end_date )
				&& $this->should_use_indexed_heatmap_dated_path() ) {
				$pages_data = $this->maybe_fast_heatmap_pages_data_dated( $start_date, $end_date );
			}

			if ( null !== $pages_data ) {
				$from_columns = true;
			} else {
				// Get all pages with heatmap data from file storage
				$pages_data = $this->get_heatmap_pages_data_from_file_source();
			}

			// Collapse multiple optibehavior_heatmap_pages mapping rows that
			// belong to the same page_id (one row per URL-normalization variant,
			// e.g. trailing-slash vs non-trailing-slash) into a single logical
			// row BEFORE counting/paginating. Without this, a page with 2+
			// mapping rows renders as 2+ identical consecutive table rows and
			// inflates the "Showing X-Y of N" total. See dedupe_heatmap_pages_data_by_page_id().
			$pages_data = $this->dedupe_heatmap_pages_data_by_page_id( $pages_data );

			if ( empty( $pages_data ) ) {
				return array( array(), 0 );
			}

			// Drop orphaned page ids (heatmap file/mapping rows whose page was
			// deleted from the pages table) BEFORE counting and paginating.
			// The render loop below skips rows without page info, so keeping
			// orphans here overcounted $total and produced short pages (e.g.
			// "Showing 1–10" with only 7 visible rows).
			$pages_data = $this->filter_heatmap_pages_data_to_known_pages( $pages_data );

			if ( empty( $pages_data ) ) {
				return array( array(), 0 );
			}

			// Filter by date range if provided
			if ( $start_date && $end_date ) {
				$start_timestamp = strtotime( $start_date );
				$end_timestamp = strtotime( $end_date );
				$pages_data = array_filter( $pages_data, function( $page ) use ( $start_timestamp, $end_timestamp ) {
					if ( empty( $page['last_event_time'] ) ) {
						return false;
					}
					$event_time = strtotime( $page['last_event_time'] );
					return $event_time >= $start_timestamp && $event_time <= $end_timestamp;
				});
			}

			// Filter by search term if provided — matches title OR URL so
			// users can search by either (matches the placeholder "Search heatmaps...").
			if ( ! empty( $search ) ) {
				$pages_table = $wpdb->prefix . 'optibehavior_pages';
				$all_page_ids = array_map( function( $page ) {
					return $page['page_id'];
				}, $pages_data );

				if ( ! empty( $all_page_ids ) ) {
					$placeholders = implode( ',', array_fill( 0, count( $all_page_ids ), '%d' ) );
					$search_like = '%' . $wpdb->esc_like( $search ) . '%';

					// Build query to get page IDs that match the search term in title OR url.
					$query_string = "SELECT id FROM " . $pages_table . " WHERE id IN (" . $placeholders . ") AND (title LIKE %s OR url LIKE %s)";
					$query_params = array_merge( $all_page_ids, array( $search_like, $search_like ) );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query string built with table prefix and placeholders
					$matching_ids = $wpdb->get_col( $wpdb->prepare( $query_string, $query_params ) );
					$matching_ids = array_map( 'intval', $matching_ids );

					// Filter pages_data to only include matching pages
					$pages_data = array_filter( $pages_data, function( $page ) use ( $matching_ids ) {
						return in_array( (int) $page['page_id'], $matching_ids, true );
					});
					$pages_data = array_values( $pages_data ); // Re-index array
				}
			}

			// Rebuild page aggregates from the same JSON files used by the detail page.
			// This keeps Heatmap cards/table aligned with spam filtering. Without this,
			// raw heatmap page totals can show interactions for sessions that are hidden
			// by the global spam filter, producing rows like "1 interaction / 0 sessions".
			// Fast path already carries exact aggregate metrics in the columns,
			// so skip the file-IO recompute (would re-scan JSON for no change).
			if ( ! $from_columns ) {
				// Self-measuring safety valve (spec §P0): time the live scan; when
				// it blows the soft budget, flag "force indexed" so subsequent
				// requests take the daily-index path even if the total-files
				// estimate lags behind reality.
				$live_scan_started = microtime( true );
				$pages_data        = $this->apply_heatmap_file_metrics_to_pages_data(
					$pages_data,
					$start_date,
					$end_date,
					$this->is_spam_excluded()
				);
				$this->note_heatmap_live_scan_time( ( microtime( true ) - $live_scan_started ) * 1000 );
			}

			$total = count( $pages_data );
			if ( $total === 0 ) {
				return array( array(), 0 );
			}

			// CANONICAL SESSION UNIVERSE (master decision 2026-08-13): the
			// Sessions column now shows canonical human pageview sessions for the
			// page's CONTENT group (same universe as the detail pill / frontend
			// bar / metabox), NOT the heatmap-file agg_sessions. Overlay the
			// group-aware canonical desktop/mobile split onto every row (batched,
			// a handful of queries regardless of row count) BEFORE the sort +
			// pagination below so the Sessions sort key equals the displayed
			// value and list == pill for every page. Runs over the FULL set so
			// sorting/pagination rank on the canonical value, not the stale
			// file counts.
			$this->overlay_canonical_pageview_sessions( $pages_data, $start_date, $end_date );

			// If sorting by sessions, the sort key MUST be derived from the exact
			// same fields the render loop below displays (sessions_desktop +
			// sessions_mobile — see the `$total_sessions` computation further
			// down), for BOTH the fast (agg columns) and file-scan paths.
			//
			// Previously this re-fetched an independent "sort" count via
			// batch_recording_counts_by_device() (file-scan path) — a DIFFERENT
			// session-counting routine than the one that populated
			// sessions_desktop/sessions_mobile (batch_heatmap_file_metrics_by_page(),
			// applied earlier via apply_heatmap_file_metrics_to_pages_data()), and
			// the fast path separately carried its own independent
			// agg_sort_sessions column (from a DB/recording-based count) instead
			// of agg_sessions_desktop+agg_sessions_mobile (the displayed values).
			// The two counts disagree whenever spam/guest/points filtering
			// diverges between the two implementations, so the sort key and the
			// number shown in the Sessions column silently went out of sync
			// (rows do not sort in true ascending/descending numeric order).
			// Deriving 'sessions' here from the already-finalized
			// sessions_desktop/sessions_mobile fields guarantees sort key ===
			// displayed value, regardless of source.
			//
			// UNIFIED HEATMAP SCOPE (2026-08-13): every heatmap surface — list
			// Sessions cell, detail device chips, and the detail header pill —
			// now renders the ONE guest spam-excluded universe (agg_sessions =
			// sessions_desktop + sessions_mobile). The old all-visitors
			// 'file_total_all' overlay (which produced the "102" pill/list
			// mismatch against the 92 chips) is retired from the headline; the
			// reconciliation cache is kept for internal sync-health only. The
			// sort key below is therefore the SAME guest desktop+mobile sum the
			// cell displays, so rows sort in true numeric order of the visible
			// value (no sort/display divergence).
			if ( $orderby === 'sessions' ) {
				foreach ( $pages_data as &$page_data ) {
					$sd = isset( $page_data['sessions_desktop'] ) ? (int) $page_data['sessions_desktop'] : 0;
					$sm = isset( $page_data['sessions_mobile'] ) ? (int) $page_data['sessions_mobile'] : 0;
					$page_data['sessions'] = $sd + $sm;
				}
				unset( $page_data ); // Break reference
			}

			// Sort based on orderby parameter
			$order_lower = strtolower( $order );
			$is_asc = ( $order_lower === 'asc' );

			usort( $pages_data, function( $a, $b ) use ( $orderby, $is_asc ) {
				$val_a = 0;
				$val_b = 0;

				switch ( $orderby ) {
					case 'interactions':
					// Sum all interaction types
					$val_a = ( $a['click_pc'] + $a['click_mobile'] + $a['breakaway_pc'] + $a['breakaway_mobile'] + $a['attention_pc'] + $a['attention_mobile'] );
					$val_b = ( $b['click_pc'] + $b['click_mobile'] + $b['breakaway_pc'] + $b['breakaway_mobile'] + $b['attention_pc'] + $b['attention_mobile'] );
					break;
				case 'sessions':
					$val_a = isset( $a['sessions'] ) ? (int)$a['sessions'] : 0;
					$val_b = isset( $b['sessions'] ) ? (int)$b['sessions'] : 0;
					break;
				case 'clicks':
						$val_a = ( $a['click_pc'] + $a['click_mobile'] );
						$val_b = ( $b['click_pc'] + $b['click_mobile'] );
						break;
					case 'last_updated':
					default:
						// Sort by last event time
						$val_a = ! empty( $a['last_event_time'] ) ? strtotime( $a['last_event_time'] ) : 0;
						$val_b = ! empty( $b['last_event_time'] ) ? strtotime( $b['last_event_time'] ) : 0;
						break;
				}

				if ( $val_a === $val_b ) {
					// Deterministic tiebreak (page_id ASC), independent of the
					// caller's pre-sort array order. Without this, ties resolve
					// by incoming array order (PHP's stable usort), which differs
					// between the legacy file-scan source and the fast indexed
					// aggregate-columns source (maybe_fast_heatmap_pages_data()) —
					// causing the two paths to disagree on row order whenever two
					// pages share the same sort value (e.g. equal session counts).
					$pid_a = isset( $a['page_id'] ) ? (int) $a['page_id'] : 0;
					$pid_b = isset( $b['page_id'] ) ? (int) $b['page_id'] : 0;
					return $pid_a <=> $pid_b;
				}

				if ( $is_asc ) {
					return ( $val_a < $val_b ) ? -1 : 1;
				} else {
					return ( $val_a > $val_b ) ? -1 : 1;
				}
			});

			// Implement pagination
			$offset = max( 0, ( $page - 1 ) * $per_page );
			$pages_data_paged = array_slice( $pages_data, $offset, $per_page );

			return array( $this->build_heatmap_rows_for_display( $pages_data_paged, $from_columns, $start_date, $end_date ), $total );
		}

		/**
		 * Render the final UI row arrays for an already-paged pages_data subset.
		 *
		 * Extracted (Task 3) from the tail of {@see get_heatmap_rows_paged_from_files()}
		 * so the SQL-paginated all-time reader ({@see maybe_sql_paged_heatmap_rows()})
		 * shares the EXACT same row-shaping code as the in-memory pipeline — any
		 * divergence between the two paths would break the byte-for-byte parity
		 * the fast-path harness asserts.
		 *
		 * @since 1.9.x
		 * @param array       $pages_data_paged The paged subset (per_page rows max).
		 * @param bool        $from_columns     True when rows came from the agg_* index
		 *                                      (per-device session counts already present,
		 *                                      skip the events-table batch).
		 * @param string|null $start_date       Optional window start.
		 * @param string|null $end_date         Optional window end.
		 * @return array UI row arrays.
		 */
		private function build_heatmap_rows_for_display( array $pages_data_paged, $from_columns, $start_date = null, $end_date = null ) {
			global $wpdb;

			// Get page IDs for database queries
			$page_ids = array_map( function( $page ) {
				return $page['page_id'];
			}, $pages_data_paged );

			// Query database for page info (title, url)
			$page_info_results = array();
			if ( ! empty( $page_ids ) ) {
				$pages_table = $wpdb->prefix . 'optibehavior_pages';
				$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );

				// Build query string using sprintf to avoid interpolation warnings
				$query_string = "SELECT id, title, url FROM " . $pages_table . " WHERE id IN (" . $placeholders . ")";

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query string built with table prefix and placeholders, all IDs are properly parameterized
				$page_info_results = $wpdb->get_results( $wpdb->prepare( $query_string, $page_ids ), 'OBJECT_K' );
			}

			// Check if events table exists (works in both free and pro versions)
			$events_table = $wpdb->prefix . 'optibehavior_events';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			$has_events_table = ( $wpdb->get_var( "SHOW TABLES LIKE '" . $events_table . "'" ) === $events_table );

			$desktop_recordings_map = array();
			$mobile_recordings_map = array();

			// Fast path carries per-device session counts in the columns
			// (sessions_desktop/sessions_mobile), so skip the events-table batch.
			if ( ! $from_columns && $has_events_table ) {
				// Batch query for desktop session counts using events table
				$desktop_recordings_map = $this->batch_recording_counts_by_device( $page_ids, 'desktop', $start_date, $end_date );

				// Batch query for mobile session counts using events table
				$mobile_recordings_map = $this->batch_recording_counts_by_device( $page_ids, 'mobile', $start_date, $end_date );
			}

			// Format rows for the UI
			$rows = array();
			foreach ( $pages_data_paged as $page_data ) {
				$page_id = $page_data['page_id'];

				// Get page info from database
				if ( ! isset( $page_info_results[ $page_id ] ) ) {
					continue;
				}

				$page_info = $page_info_results[ $page_id ];
				$title = $page_info->title ? $page_info->title : 'Untitled Page';
				$url = $page_info->url;

				// Title truncation handled by CSS text-overflow: ellipsis
				// Full title preserved for hover tooltip via title attribute

				// Calculate totals
				$click_pc = intval( $page_data['click_pc'] );
				$click_mobile = intval( $page_data['click_mobile'] );
				$breakaway_pc = intval( $page_data['breakaway_pc'] );
				$breakaway_mobile = intval( $page_data['breakaway_mobile'] );
				$attention_pc = intval( $page_data['attention_pc'] );
				$attention_mobile = intval( $page_data['attention_mobile'] );

				$total_clicks = $click_pc + $click_mobile;
				$total_breakaway = $breakaway_pc + $breakaway_mobile;
				$total_attention = $attention_pc + $attention_mobile;

				// Determine primary type
				$max_count = max( $total_clicks, $total_breakaway, $total_attention );
				$type = 'click';
				$type_icon = '🖱️';

				if ( $max_count === $total_breakaway ) {
					$type = 'scroll';
					$type_icon = '📜';
				} elseif ( $max_count === $total_attention ) {
					$type = 'attention';
					$type_icon = '👁️';
				}

				// Calculate mobile percentage from recording session counts (more accurate)
				// Use desktop/mobile recording maps which are fetched from JSON files with device info
				$desktop_sessions_for_page = isset( $page_data['sessions_desktop'] ) ? (int) $page_data['sessions_desktop'] : ( isset( $desktop_recordings_map[ $page_id ] ) ? (int) $desktop_recordings_map[ $page_id ] : 0 );
				$mobile_sessions_for_page = isset( $page_data['sessions_mobile'] ) ? (int) $page_data['sessions_mobile'] : ( isset( $mobile_recordings_map[ $page_id ] ) ? (int) $mobile_recordings_map[ $page_id ] : 0 );
				$total_sessions_for_device = $desktop_sessions_for_page + $mobile_sessions_for_page;

				if ( $total_sessions_for_device > 0 ) {
					$mobile_percentage = round( ( $mobile_sessions_for_page / $total_sessions_for_device ) * 100 );
				} else {
					// Fallback to event-based calculation if no recording session data
					$total_events = $click_pc + $click_mobile + $breakaway_pc + $breakaway_mobile + $attention_pc + $attention_mobile;
					$mobile_events = $click_mobile + $breakaway_mobile + $attention_mobile;
					$mobile_percentage = $total_events > 0 ? round( ( $mobile_events / $total_events ) * 100 ) : 0;
				}

				// Determine status based on last event time
				$status = 'active';
				$status_icon = '🟢';

				if ( ! empty( $page_data['last_event_time'] ) ) {
					$hours_since_update = ( time() - strtotime( $page_data['last_event_time'] ) ) / 3600;

					if ( $hours_since_update > 168 ) { // 7 days
						$status = 'inactive';
						$status_icon = '🔴';
					} elseif ( $hours_since_update > 24 ) { // 1 day
						$status = 'processing';
						$status_icon = '🟡';
					}
				}

				// Generate proper heatmap view URL
				$event_name = 'click_pc';

				if ( $type === 'click' ) {
					$has_pc = $click_pc > 0;
					$has_mobile = $click_mobile > 0;

					if ( $has_mobile && ! $has_pc ) {
						$event_name = 'click_mobile';
					} else {
						$event_name = 'click_pc';
					}
				} elseif ( $type === 'scroll' ) {
					$has_pc = $breakaway_pc > 0;
					$has_mobile = $breakaway_mobile > 0;

					if ( $has_mobile && ! $has_pc ) {
						$event_name = 'breakaway_mobile';
					} else {
						$event_name = 'breakaway_pc';
					}
				} elseif ( $type === 'attention' ) {
					$has_pc = $attention_pc > 0;
					$has_mobile = $attention_mobile > 0;

					if ( $has_mobile && ! $has_pc ) {
						$event_name = 'attention_mobile';
					} else {
						$event_name = 'attention_pc';
					}
				}

				// Create proper heatmap URL
				$page_url = $url;
				$param = '?opti_behavior_heatmap=' . $event_name;

				if ( false === strpos( $page_url, '#' ) ) {
					$view_url = $page_url . $param;
				} else {
					$s = explode( '#', $page_url, 2 );
					$view_url = $s[0] . $param . '#' . $s[1];
				}

				// Create PC-only and Mobile-only admin URLs - always default to 'click' heatmap
				$view_pc_url = admin_url( 'admin.php?page=opti-behavior-heatmap-detail&page_id=' . $page_id . '&type=click&device=desktop' );
				$view_mobile_url = admin_url( 'admin.php?page=opti-behavior-heatmap-detail&page_id=' . $page_id . '&type=click&device=mobile' );

				// Format last updated time
				$last_updated = 'Never';
				if ( ! empty( $page_data['last_event_time'] ) ) {
					$timestamp = strtotime( $page_data['last_event_time'] );
					if ( $timestamp !== false ) {
						$last_updated = $this->time_ago( $timestamp );
					}
				}

				// Placeholder thumbnail
				$thumbnail_placeholder = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="100" height="60" viewBox="0 0 100 60"%3E%3Crect fill="%23f3f4f6" width="100" height="60"/%3E%3Ctext x="50" y="30" font-family="Arial" font-size="12" fill="%239ca3af" text-anchor="middle" dominant-baseline="middle"%3EPreview%3C/text%3E%3C/svg%3E';

				// Calculate total interactions per device type (clicks + scroll + attention)
				$total_interactions = $total_clicks + $total_breakaway + $total_attention;

				// Sessions = Desktop + Mobile from file storage (same source as the Desktop/Mobile buttons).
				// This ensures the Sessions column matches the sum of the device buttons in the Actions column.
				$total_sessions = $desktop_sessions_for_page + $mobile_sessions_for_page;

				$rows[] = array(
					'id' => $page_id,  // Use 'id' to match template expectations
					'page_id' => $page_id,
					'title' => $title,
					'url' => $url,
					'type' => $type,
					'type_icon' => $type_icon,
					'clicks' => $total_clicks,
					'interactions' => $total_interactions, // Sum ALL interaction types
					'sessions' => $total_sessions,
					'status' => $status,
					'status_icon' => $status_icon,
					'last_updated' => $last_updated,
					'mobile_percentage' => $mobile_percentage,
					// Session counts by device type
					'views_pc' => $desktop_sessions_for_page,
					'views_mobile' => $mobile_sessions_for_page,
					// Session counts by device type (for Desktop/Mobile buttons)
					'sessions_desktop' => $desktop_sessions_for_page,
					'sessions_mobile' => $mobile_sessions_for_page,
					'view_url' => $view_url,
					'view_pc_url' => $view_pc_url,
					'view_mobile_url' => $view_mobile_url,
					'thumbnail' => $thumbnail_placeholder,
					// Pro breakaway/attention event counts (event 32/33/48/49)
					'breakaway_pc'     => $breakaway_pc,
					'breakaway_mobile' => $breakaway_mobile,
					'attention_pc'     => $attention_pc,
					'attention_mobile' => $attention_mobile,
				);

				// Dual-metric fields (spec §1/§2): carried through from
				// apply_heatmap_file_metrics_to_pages_data()'s overlay_canonical_session_counts()
				// call on $page_data, which this loop otherwise only reads
				// individual fields from. Additive — absent (null/unset) on
				// Free-only installs or pages with no cached sync-status entry.
				if ( array_key_exists( 'sessions_with_interactions', $page_data ) ) {
					$rows[ count( $rows ) - 1 ]['sessions_with_interactions'] = $page_data['sessions_with_interactions'];
				}
				if ( array_key_exists( 'desynced', $page_data ) ) {
					$rows[ count( $rows ) - 1 ]['desynced'] = (bool) $page_data['desynced'];
				}
				// "Delete Heatmap Data" dual display: all-time total stamped by
				// overlay_canonical_pageview_sessions() on reset pages whose
				// since-reset count differs — the cell renders "valid / total".
				if ( array_key_exists( 'sessions_all_time', $page_data ) ) {
					$rows[ count( $rows ) - 1 ]['sessions_all_time'] = (int) $page_data['sessions_all_time'];
				}
			}

			return $rows;
		}

		private function batch_sessions_counts_by_page($page_ids, $start_date=null, $end_date=null){
			// Prefer file storage when available because heatmap detail/device buttons
			// are rendered from heatmap JSON files. This keeps list sessions,
			// Desktop/Mobile buttons, and detail stats on the same source.
			$file_storage = $this->heatmap->get_file_storage();
			$storage_settings = $file_storage ? $file_storage->get_settings() : array( 'storage_mode' => 'database' );

			if ( 'file' === $storage_settings['storage_mode'] || $this->has_heatmap_file_data_for_pages( $page_ids ) ) {
				// Use file-based counting to match device split counting
				return $this->batch_sessions_counts_by_page_from_files($page_ids, $start_date, $end_date);
			}

			// UNIFIED STATS FIX: When Pro is active but file storage is unavailable,
			// use recordings-based counting as a fallback.
			$is_pro_active = function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active();

			if ( $is_pro_active ) {
				return $this->batch_sessions_counts_by_page_from_recordings($page_ids, $start_date, $end_date);
			}

			// Fall back to database implementation
			return $this->batch_sessions_counts_by_page_impl($page_ids, $start_date, $end_date);
		}

		/**
		 * Count sessions from recordings table (unified counting for Pro).
		 * This ensures heatmap sessions match recording sessions exactly.
		 *
		 * @param array $page_ids Array of page IDs.
		 * @param string|null $start_date Optional start date filter.
		 * @param string|null $end_date Optional end date filter.
		 * @return array Map of page_id => session count.
		 */
		private function batch_sessions_counts_by_page_from_recordings($page_ids, $start_date = null, $end_date = null) {
			global $wpdb;

			if ( empty( $page_ids ) ) {
				return array();
			}

			$map = array();

			// Initialize all page IDs with 0
			foreach ( $page_ids as $page_id ) {
				$map[ $page_id ] = 0;
			}

			$table_recordings    = $wpdb->prefix . 'optibehavior_recordings';
			$table_session_pages = $wpdb->prefix . 'optibehavior_session_pages';

			// Build placeholders for page IDs
			$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );

			// Build date filter
			$date_filter = '';
			$params = $page_ids;
			if ( $start_date && $end_date ) {
				$date_filter = ' AND r.start_time BETWEEN %s AND %s';
				$params[] = $start_date;
				$params[] = $end_date;
			}

			// Count unique sessions that have recordings for each page
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic placeholders built safely with array_fill
			$query = $wpdb->prepare(
				"SELECT sp.page_id, COUNT(DISTINCT r.session_id) as session_count
				FROM " . $table_recordings . " r
				INNER JOIN " . $table_session_pages . " sp ON sp.session_id = r.session_id
				WHERE sp.page_id IN (" . $placeholders . ")
				" . $date_filter . "
				GROUP BY sp.page_id",
				$params
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is safely constructed from prepared parts above.
			$results = $wpdb->get_results( $query );

			foreach ( $results as $row ) {
				$map[ $row->page_id ] = intval( $row->session_count );
			}

			return $map;
		}



	/**
	 * Remove pages_data entries whose page_id no longer exists in the pages table.
	 *
	 * Heatmap JSON files / mapping-table rows can outlive their page row (page
	 * deleted, tables cleaned). Downstream consumers join on the pages table for
	 * title/url and silently skip missing ids, so orphans must not be counted
	 * toward pagination totals. One indexed primary-key lookup, memoized per
	 * request.
	 *
	 * @param array $pages_data Page aggregate rows (each with 'page_id').
	 * @return array Same rows, orphans removed, re-indexed.
	 */
	private function filter_heatmap_pages_data_to_known_pages( $pages_data ) {
		global $wpdb;

		if ( empty( $pages_data ) ) {
			return array();
		}

		$page_ids = array();
		foreach ( $pages_data as $page_data ) {
			if ( isset( $page_data['page_id'] ) ) {
				$page_ids[] = absint( $page_data['page_id'] );
			}
		}
		$page_ids = array_values( array_filter( array_unique( $page_ids ) ) );

		if ( empty( $page_ids ) ) {
			return array();
		}

		// Per-request memo: id => true (exists) / false (orphan). Only ids not
		// yet checked are queried, so repeat calls with different id sets stay
		// correct and cheap.
		static $checked_ids = array();
		$unchecked = array();
		foreach ( $page_ids as $pid ) {
			if ( ! array_key_exists( $pid, $checked_ids ) ) {
				$unchecked[] = $pid;
			}
		}

		if ( ! empty( $unchecked ) ) {
			$pages_table  = $wpdb->prefix . 'optibehavior_pages';
			$placeholders = implode( ',', array_fill( 0, count( $unchecked ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Primary-key lookup; table name from $wpdb->prefix, ids parameterized.
			$existing = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$pages_table} WHERE id IN ({$placeholders})",
				$unchecked
			) );

			$existing_map = array_fill_keys( array_map( 'intval', (array) $existing ), true );
			foreach ( $unchecked as $pid ) {
				$checked_ids[ $pid ] = isset( $existing_map[ $pid ] );
			}
		}

		$has_orphans = false;
		foreach ( $page_ids as $pid ) {
			if ( empty( $checked_ids[ $pid ] ) ) {
				$has_orphans = true;
				break;
			}
		}

		// Fast exit: nothing orphaned (common case).
		if ( ! $has_orphans ) {
			return $pages_data;
		}

		$filtered = array();
		foreach ( $pages_data as $page_data ) {
			$pid = isset( $page_data['page_id'] ) ? (int) $page_data['page_id'] : 0;
			if ( $pid && ! empty( $checked_ids[ $pid ] ) ) {
				$filtered[] = $page_data;
			}
		}

		return array_values( $filtered );
	}

	/**
	 * Get heatmap page aggregates from the active file source or the mapping table.
	 *
	 * @return array
	 */
	private function get_heatmap_pages_data_from_file_source() {
		$file_storage = $this->heatmap->get_file_storage();
		if ( $file_storage ) {
			$pages_data = $file_storage->get_pages_with_heatmap_data();
			if ( ! empty( $pages_data ) ) {
				return $pages_data;
			}
		}

		return $this->get_heatmap_pages_data_from_mapping_table();
	}

	/**
	 * Fallback page aggregate reader when the file storage object is unavailable.
	 *
	 * @return array
	 */
	private function get_heatmap_pages_data_from_mapping_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( $table_exists !== $table_name ) {
			return array();
		}

		$results = $wpdb->get_results(
			"SELECT page_id, url, click_count, move_count, scroll_count, last_data_at
			FROM {$table_name}
			WHERE click_count > 0 OR move_count > 0 OR scroll_count > 0
			ORDER BY last_data_at DESC"
		);

		if ( empty( $results ) ) {
			return array();
		}

		$pages_data = array();
		foreach ( $results as $row ) {
			$pages_data[] = array(
				'page_id'          => (int) $row->page_id,
				'url'              => $row->url,
				'click_pc'         => (int) $row->click_count,
				'click_mobile'     => 0,
				'breakaway_pc'     => (int) $row->scroll_count,
				'breakaway_mobile' => 0,
				'attention_pc'     => (int) $row->move_count,
				'attention_mobile' => 0,
				'last_event_time'  => $row->last_data_at,
			);
		}

		return $pages_data;
	}

	/**
	 * Replace heatmap page aggregates with metrics read from the same JSON files
	 * used by the detail page and Desktop/Mobile buttons.
	 *
	 * @param array       $pages_data Raw page aggregates.
	 * @param string|null $start_date Optional start date filter.
	 * @param string|null $end_date Optional end date filter.
	 * @param bool        $drop_empty Whether pages with no allowed file/session data should be hidden.
	 * @return array
	 */
	private function apply_heatmap_file_metrics_to_pages_data( $pages_data, $start_date = null, $end_date = null, $drop_empty = false ) {
		if ( empty( $pages_data ) ) {
			return array();
		}

		$page_ids = array();
		foreach ( $pages_data as $page_data ) {
			if ( isset( $page_data['page_id'] ) ) {
				$page_ids[] = absint( $page_data['page_id'] );
			}
		}
		$page_ids = array_values( array_filter( array_unique( $page_ids ) ) );

		if ( empty( $page_ids ) ) {
			return array();
		}

		$metrics_map = $this->batch_heatmap_file_metrics_by_page( $page_ids, $start_date, $end_date );
		$filtered    = array();

		foreach ( $pages_data as $page_data ) {
			$page_id = isset( $page_data['page_id'] ) ? absint( $page_data['page_id'] ) : 0;
			$metrics = $page_id && isset( $metrics_map[ $page_id ] ) ? $metrics_map[ $page_id ] : null;

			if ( $drop_empty && ( ! $metrics || empty( $metrics['has_data'] ) ) ) {
				continue;
			}

			if ( $metrics && ! empty( $metrics['has_data'] ) ) {
				foreach ( array( 'click_pc', 'click_mobile', 'breakaway_pc', 'breakaway_mobile', 'attention_pc', 'attention_mobile', 'sessions', 'sessions_desktop', 'sessions_mobile', 'sessions_tablet' ) as $metric_key ) {
					$page_data[ $metric_key ] = isset( $metrics[ $metric_key ] ) ? (int) $metrics[ $metric_key ] : 0;
				}

				if ( ! empty( $metrics['last_event_time'] ) ) {
					$page_data['last_event_time'] = $metrics['last_event_time'];
				}
			}

			$filtered[] = $page_data;
		}

		// UNIFIED-METRIC PARITY: keep the file-derived guest spam-excluded session
		// counts (sessions_desktop + sessions_mobile + tablet). Do NOT overlay
		// canonical recording-DB counts — that reintroduced the "N / M" dual +
		// desync badge and broke list==chips==pill parity.
		return array_values( $filtered );
	}

	/**
	 * Batch read heatmap JSON files and return spam-aware metrics per page.
	 *
	 * @param array       $page_ids Page IDs.
	 * @param string|null $start_date Optional start date filter.
	 * @param string|null $end_date Optional end date filter.
	 * @return array
	 */
	private function batch_heatmap_file_metrics_by_page( $page_ids, $start_date = null, $end_date = null ) {
		if ( empty( $page_ids ) ) {
			return array();
		}

		// Request-scoped + persistent cache. The result is the exact output of
		// the file scan below, so caching changes only freshness (bounded by the
		// ingest/delete flush + TTL), never any displayed count.
		$cache_parts = $this->heatmap_aggregate_cache_parts( $page_ids, $start_date, $end_date );
		$memo_key    = 'm|' . $cache_parts['sig'];
		if ( isset( $this->hm_metrics_memo[ $memo_key ] ) ) {
			return $this->hm_metrics_memo[ $memo_key ];
		}
		$transient_key = function_exists( 'opti_behavior_heatmap_cache_key' )
			? opti_behavior_heatmap_cache_key( 'metrics', $cache_parts['parts'] )
			: '';
		if ( $transient_key && ! $this->should_force_refresh_dashboard_cache() ) {
			$cached = get_transient( $transient_key );
			if ( is_array( $cached ) ) {
				$this->hm_metrics_memo[ $memo_key ] = $cached;
				return $cached;
			}
		}

		$upload_dir      = wp_upload_dir();
		$base_upload_dir = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';
		$start_ts        = $start_date ? strtotime( $start_date ) : null;
		$end_ts          = $end_date ? strtotime( $end_date ) : null;
		$folder_map      = array(
			'clicks'  => array( 'desktop' => 'click_pc', 'mobile' => 'click_mobile', 'tablet' => 'click_mobile' ),
			'moves'   => array( 'desktop' => 'attention_pc', 'mobile' => 'attention_mobile', 'tablet' => 'attention_mobile' ),
			'scrolls' => array( 'desktop' => 'breakaway_pc', 'mobile' => 'breakaway_mobile', 'tablet' => 'breakaway_mobile' ),
		);
		$metrics = array();

		// Perf (RC3): batch the file-dir resolution too (2 chunked queries
		// instead of 2 round-trips per page in the loop below).
		$this->prefetch_heatmap_file_dirs_for_pages( $page_ids, $base_upload_dir );

		// Hard per-request file-scan cap (spec §P3 safety net): pages are
		// admitted in order until the cap is consumed; the rest are served with
		// zero metrics this request (stale) while a background sync converges.
		// The page-granular subset keeps the spam token pre-pass and the metric
		// loop below consistent (same files considered by both), and stops
		// BEFORE globbing further pages — no request is ever O(total files).
		// CLI/cron contexts are uncapped (see heatmap_request_scan_cap()).
		$scan_cap    = $this->heatmap_request_scan_cap();
		$scan_capped = false;
		$scan_pages  = $page_ids;
		if ( $scan_cap > 0 ) {
			$scanned = 0;
			$subset  = array();
			foreach ( $page_ids as $cap_pid ) {
				$cap_pid = absint( $cap_pid );
				if ( ! $cap_pid ) {
					continue;
				}
				if ( ! empty( $subset ) && $scanned >= $scan_cap ) {
					$scan_capped = true;
					break;
				}
				$page_files = 0;
				foreach ( $this->get_heatmap_file_dirs_for_page( $cap_pid, $base_upload_dir ) as $cap_dir ) {
					foreach ( array( 'clicks', 'moves', 'scrolls' ) as $cap_folder ) {
						$page_files += count( $this->glob_heatmap_dir_files( trailingslashit( $cap_dir ) . $cap_folder ) );
					}
				}
				if ( ! empty( $subset ) && ( $scanned + $page_files ) > $scan_cap ) {
					$scan_capped = true;
					break;
				}
				$subset[] = $cap_pid;
				$scanned += $page_files;
			}
			if ( $scan_capped ) {
				$scan_pages = $subset;
				// Converge the skipped pages in the background (also schedules
				// the daily-index sibling worker).
				$this->schedule_heatmap_aggregate_sync( 30 );
			}
		}
		$scan_lookup = array();
		foreach ( $scan_pages as $scan_pid ) {
			$scan_lookup[ absint( $scan_pid ) ] = true;
		}

		// Perf (RC1/RC4): resolve the spam allow-lists for the WHOLE page set in
		// O(1) queries up front (fills the per-page memo the loop below reads),
		// instead of 2 full-scan queries per page. The file session tokens are
		// passed so the resolver can restrict evidence scanning to sessions that
		// can actually match a heatmap file of these pages — this loop only ever
		// tests file-parsed session tokens against the lookup, so the restricted
		// lookup is outcome-identical (see resolver docblock for the proof).
		if ( $this->is_spam_excluded() ) {
			$this->get_allowed_heatmap_session_lookup_for_pages(
				$scan_pages,
				$this->collect_heatmap_file_session_tokens_for_pages( $scan_pages, $base_upload_dir )
			);
		}

		foreach ( $page_ids as $page_id ) {
			$page_id = absint( $page_id );
			if ( ! $page_id ) {
				continue;
			}

			$metrics[ $page_id ] = array(
				'click_pc'         => 0,
				'click_mobile'     => 0,
				'breakaway_pc'     => 0,
				'breakaway_mobile' => 0,
				'attention_pc'     => 0,
				'attention_mobile' => 0,
				'sessions'         => 0,
				'sessions_desktop' => 0,
				'sessions_mobile'  => 0,
				'sessions_tablet'  => 0,
				'last_event_time'  => '',
				'has_data'         => false,
			);

			// Over the per-request scan cap (spec §P3): keep the zero-metrics
			// placeholder; the scheduled background sync refreshes this page.
			if ( ! isset( $scan_lookup[ $page_id ] ) ) {
				continue;
			}

			$spam_excluded    = $this->is_spam_excluded();
			$allowed_sessions = array();
			if ( $spam_excluded ) {
				$allowed_sessions = $this->get_allowed_heatmap_session_lookup_for_page( $page_id );
				// RC-C: an empty (or partial) allow-list no longer skips the
				// page — file sessions the DB has no trace of at all are
				// orphans and must still be counted (see
				// is_heatmap_session_allowed_or_orphan()/is_heatmap_session_known() below).
			}

			$url_hash_dirs = $this->get_heatmap_file_dirs_for_page( $page_id, $base_upload_dir );
			if ( empty( $url_hash_dirs ) ) {
				continue;
			}

			$sessions_by_device = array(
				'desktop' => array(),
				'mobile'  => array(),
				'tablet'  => array(),
			);
			$last_ts  = 0;
			$reset_ts = $this->get_heatmap_reset_timestamp_for_page( $page_id );

			foreach ( $url_hash_dirs as $url_hash_dir ) {
				foreach ( $folder_map as $folder_name => $event_buckets ) {
					$dir   = trailingslashit( $url_hash_dir ) . $folder_name;
					$files = $this->glob_heatmap_dir_files( $dir );
					if ( empty( $files ) ) {
						continue;
					}

					foreach ( $files as $file ) {
						$file_parts = $this->parse_heatmap_file_name( $file );
						if ( empty( $file_parts ) ) {
							continue;
						}

						$file_ts     = $file_parts['timestamp'];
						$file_device = $file_parts['device'];
						$session     = $file_parts['session'];

						if ( $start_ts && $file_ts < $start_ts ) {
							continue;
						}
						if ( $end_ts && $file_ts > $end_ts ) {
							continue;
						}
						// Strict membership (not is_heatmap_session_allowed()'s "empty
						// lookup = unrestricted" shortcut): a page whose allow-list
						// legitimately resolved to zero sessions must still fall
						// through to the known-session check below, not admit a
						// known-spam session unconditionally (RC-C).
						if ( $spam_excluded && ! $this->is_heatmap_session_in_lookup( $session, $allowed_sessions ) ) {
							$known_sessions = $this->get_known_heatmap_session_lookup_for_page( $page_id, $base_upload_dir );
							if ( $this->is_heatmap_session_known( $session, $known_sessions ) ) {
								continue; // Known DB session, not allow-listed: genuine spam — filtered.
							}
						}

						$count = $this->count_heatmap_file_points( $file );
						if ( $count <= 0 ) {
							continue;
						}

						$bucket = isset( $event_buckets[ $file_device ] ) ? $event_buckets[ $file_device ] : $event_buckets['desktop'];
						$metrics[ $page_id ][ $bucket ] += $count;
						$metrics[ $page_id ]['has_data'] = true;

						// Session counts must match the detail page's default filtered
						// view: Guest Visitor only (logged-in users can see different
						// page content), excluding sessions captured before the page's
						// "Delete Heatmap Data" reset. Interaction point totals above
						// are unchanged.
						if ( 'G' === $file_parts['marker'] && ! ( $reset_ts && $file_ts < $reset_ts ) ) {
							if ( ! isset( $sessions_by_device[ $file_device ] ) ) {
								$sessions_by_device[ $file_device ] = array();
							}
							$sessions_by_device[ $file_device ][ $session ] = true;
						}

						if ( $file_ts > $last_ts ) {
							$last_ts = $file_ts;
						}
					}
				}
			}

			$metrics[ $page_id ]['sessions_desktop'] = count( $sessions_by_device['desktop'] );
			$metrics[ $page_id ]['sessions_mobile']  = count( $sessions_by_device['mobile'] );
			$metrics[ $page_id ]['sessions_tablet']  = count( $sessions_by_device['tablet'] );
			$metrics[ $page_id ]['sessions']         = $metrics[ $page_id ]['sessions_desktop'] + $metrics[ $page_id ]['sessions_mobile'] + $metrics[ $page_id ]['sessions_tablet'];

			if ( $last_ts > 0 ) {
				$metrics[ $page_id ]['last_event_time'] = gmdate( 'Y-m-d H:i:s', $last_ts );
			}
		}

		$this->hm_metrics_memo[ $memo_key ] = $metrics;
		// A cap-truncated result is partial — never persist it, or the stale
		// zero rows would outlive the request via the transient cache.
		if ( $transient_key && ! $scan_capped ) {
			set_transient( $transient_key, $metrics, opti_behavior_heatmap_cache_ttl() );
		}

		return $metrics;
	}

	/**
	 * Build a stable cache signature for the heatmap aggregate helpers.
	 *
	 * Combines the (sorted) page-id set, optional date range, the active spam
	 * filter state and the configured spam thresholds, so any input that can
	 * change a count produces a distinct key — guaranteeing cached values never
	 * mismatch the live computation.
	 *
	 * @param array       $page_ids   Page IDs.
	 * @param string|null $start_date Optional start date.
	 * @param string|null $end_date   Optional end date.
	 * @param string      $extra      Optional extra discriminator (e.g. device).
	 * @return array { sig: string, parts: array }
	 */
	private function heatmap_aggregate_cache_parts( $page_ids, $start_date = null, $end_date = null, $extra = '' ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		sort( $ids, SORT_NUMERIC );

		$spam = $this->is_spam_excluded() ? '1' : '0';

		// Spam thresholds change which sessions are "allowed", so fold them in.
		$traffic_settings = get_option( 'opti_behavior_traffic_settings', array() );
		$thresholds       = is_array( $traffic_settings )
			? array(
				isset( $traffic_settings['spam_duration_threshold'] ) ? (int) $traffic_settings['spam_duration_threshold'] : 3,
				isset( $traffic_settings['spam_min_scrolls_threshold'] ) ? (int) $traffic_settings['spam_min_scrolls_threshold'] : 0,
				isset( $traffic_settings['spam_min_clicks_threshold'] ) ? (int) $traffic_settings['spam_min_clicks_threshold'] : 1,
			)
			: array( 3, 0, 1 );

		$parts = array(
			'ids'   => $ids,
			'start' => (string) $start_date,
			'end'   => (string) $end_date,
			'spam'  => $spam,
			'thr'   => $thresholds,
			'extra' => (string) $extra,
			// Counting-semantics version: bumped when the default scope changes
			// (v3 = guest-only default restored — logged-in visitors can see
			// different page content, so they are excluded from default counts),
			// so stale transients from the old semantics are never reused.
			'sem'   => 'v3',
		);

		return array(
			'sig'   => md5( (string) wp_json_encode( $parts ) ),
			'parts' => $parts,
		);
	}

	/**
	 * Whether the Phase 3 heatmap aggregate columns exist on the mapping table.
	 *
	 * Resolved once per request. When the migration has not run yet (e.g. an
	 * interrupted upgrade) the indexed fast path silently disables itself and the
	 * Heatmaps list falls back to the file-scan path, so behaviour is unchanged.
	 *
	 * @since 1.6.7
	 * @return bool
	 */
	private function heatmap_pages_aggregate_columns_exist() {
		if ( null !== self::$hm_agg_cols_exist ) {
			return self::$hm_agg_cols_exist;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema capability probe; result cached for the request.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				DB_NAME,
				$table,
				'agg_synced_at'
			)
		);

		self::$hm_agg_cols_exist = ( $count > 0 );
		return self::$hm_agg_cols_exist;
	}

	/**
	 * Refresh the precomputed aggregate columns for the DEFAULT spam state.
	 *
	 * Only rows that were never synced, or synced under a different default spam
	 * state, are recomputed — using the SAME file-scan aggregators the dashboard
	 * uses live ({@see batch_heatmap_file_metrics_by_page()} for the displayed
	 * counts, {@see batch_recording_counts_by_device()} for the Sessions sort
	 * basis). Because the stored values are the byte-for-byte output of those
	 * functions, the indexed fast path can never diverge from the live calc.
	 *
	 * Ingest marks rows stale (agg_synced_at = NULL); this method makes them exact
	 * again on the next admin load, so in steady state the sort path does zero
	 * file IO. The batch is capped (filterable) AND bounded by a wall-time budget
	 * so a large change set never blocks a single request — any remainder is
	 * chained via a WP-Cron single event ({@see cron_sync_heatmap_aggregates()}).
	 *
	 * @since 1.6.7
	 * @param array|null $page_ids    Restrict to these page IDs (default: all stale).
	 * @param float|null $time_budget Max wall-clock seconds to spend (null = unbounded).
	 * @param int|null   $batch_max   Default batch cap before the filter (null = 1000, legacy).
	 * @return int Number of pages resynced.
	 */
	private function sync_heatmap_aggregate_columns( $page_ids = null, $time_budget = null, $batch_max = null ) {
		global $wpdb;

		if ( ! $this->heatmap_pages_aggregate_columns_exist() ) {
			return 0;
		}

		// PRODUCTION SAFETY (C2-4): single-flight. During the crash-window re-run
		// SEVERAL copies of the evidence GROUP BY ran concurrently (cron worker +
		// parallel admin renders each starting their own sync) and queued 44-54 s
		// scans on top of each other. A non-blocking advisory lock lets exactly
		// one sync run at a time; every other caller returns instantly and the
		// backlog is picked up by the next cron tick.
		// PORTABILITY: fail-open lock helper — proceeds when GET_LOCK is
		// unsupported (SQLite) so aggregation still runs; only a definitive
		// BUSY ('0') skips.
		$lock_state = Opti_Behavior_Heatmap_DB_Lock::acquire( 'opti_behavior_agg_sync', 0 );
		if ( Opti_Behavior_Heatmap_DB_Lock::BUSY === $lock_state ) {
			return 0;
		}

		try {
			return $this->sync_heatmap_aggregate_columns_locked( $page_ids, $time_budget, $batch_max );
		} finally {
			Opti_Behavior_Heatmap_DB_Lock::release( 'opti_behavior_agg_sync', $lock_state );
		}
	}

	/**
	 * Body of the aggregate-column sync. Only ever runs under the
	 * 'opti_behavior_agg_sync' advisory lock acquired by the public entry.
	 *
	 * @since 1.8.1.7
	 * @param array|null $page_ids    Page ids to restrict to.
	 * @param float|null $time_budget Wall-time budget in seconds.
	 * @param int|null   $batch_max   Max pages per pass.
	 * @return int Pages synced.
	 */
	private function sync_heatmap_aggregate_columns_locked( $page_ids = null, $time_budget = null, $batch_max = null ) {
		global $wpdb;

		$table        = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$default_spam = $this->get_default_spam_exclusion_enabled() ? 1 : 0;

		$where  = "(agg_synced_at IS NULL OR agg_spam_state <> %d) AND (click_count > 0 OR move_count > 0 OR scroll_count > 0)";
		$params = array( $default_spam );

		if ( is_array( $page_ids ) && ! empty( $page_ids ) ) {
			$ids = array_values( array_filter( array_map( 'absint', $page_ids ) ) );
			if ( ! empty( $ids ) ) {
				$where   .= ' AND page_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
				$params   = array_merge( $params, $ids );
			}
		}

		$default_max = ( null !== $batch_max && (int) $batch_max > 0 ) ? (int) $batch_max : 1000;
		$max         = (int) apply_filters( 'opti_behavior_heatmap_sync_batch_max', $default_max );
		$params[]    = $max > 0 ? $max : $default_max;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared with placeholders; identifiers hard-coded.
		$stale_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT page_id FROM {$table} WHERE {$where} ORDER BY last_data_at DESC LIMIT %d", $params )
		);
		$stale_ids = array_values( array_filter( array_map( 'intval', (array) $stale_ids ) ) );

		if ( empty( $stale_ids ) ) {
			return 0;
		}

		// Compute under the default spam state, then restore the request's flag.
		$prev_spam = array_key_exists( 'opti_behavior_exclude_spam', $GLOBALS ) ? $GLOBALS['opti_behavior_exclude_spam'] : null;
		$GLOBALS['opti_behavior_exclude_spam'] = (bool) $default_spam;

		// Perf (RC1): resolve the spam allow-lists for the whole batch in O(1)
		// queries up front — fills the memo the per-chunk aggregators read.
		if ( (bool) $default_spam ) {
			$this->get_allowed_heatmap_session_lookup_for_pages( $stale_ids );
		}

		$started = microtime( true );
		$now     = current_time( 'mysql' );
		$synced  = 0;

		// Process in small chunks so the wall-time budget is honoured between
		// chunks — a cold cache on a huge site can no longer block the request.
		foreach ( array_chunk( $stale_ids, 10 ) as $chunk ) {
			if ( null !== $time_budget && $synced > 0 && ( microtime( true ) - $started ) >= (float) $time_budget ) {
				break;
			}

			$metrics     = $this->batch_heatmap_file_metrics_by_page( $chunk, null, null );
			$rec_desktop = $this->batch_recording_counts_by_device( $chunk, 'desktop', null, null );
			$rec_mobile  = $this->batch_recording_counts_by_device( $chunk, 'mobile', null, null );

			// BUG-1: resolve the concrete mapping-row ids for this chunk up front
			// so the aggregate write targets rows by `id`, not a blind
			// `WHERE page_id` that would fan identical agg_* onto every sibling
			// row (the historical duplicate-mapping double-count). Post-dedupe
			// this is one id per page; the map keeps the write correct even if a
			// stray sibling ever reappears.
			$row_ids_by_page = array();
			$chunk_ph        = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared with placeholders; identifier hard-coded.
			$id_rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, page_id FROM {$table} WHERE page_id IN ({$chunk_ph})", $chunk )
			);
			foreach ( (array) $id_rows as $ir ) {
				$row_ids_by_page[ (int) $ir->page_id ][] = (int) $ir->id;
			}

			foreach ( $chunk as $pid ) {
				$m = isset( $metrics[ $pid ] ) ? $metrics[ $pid ] : array();

				$cpc = isset( $m['click_pc'] ) ? (int) $m['click_pc'] : 0;
				$cmb = isset( $m['click_mobile'] ) ? (int) $m['click_mobile'] : 0;
				$bpc = isset( $m['breakaway_pc'] ) ? (int) $m['breakaway_pc'] : 0;
				$bmb = isset( $m['breakaway_mobile'] ) ? (int) $m['breakaway_mobile'] : 0;
				$apc = isset( $m['attention_pc'] ) ? (int) $m['attention_pc'] : 0;
				$amb = isset( $m['attention_mobile'] ) ? (int) $m['attention_mobile'] : 0;
				$sd  = isset( $m['sessions_desktop'] ) ? (int) $m['sessions_desktop'] : 0;
				$sm  = isset( $m['sessions_mobile'] ) ? (int) $m['sessions_mobile'] : 0;

				$sort_sessions = ( isset( $rec_desktop[ $pid ] ) ? (int) $rec_desktop[ $pid ] : 0 )
					+ ( isset( $rec_mobile[ $pid ] ) ? (int) $rec_mobile[ $pid ] : 0 );

				$data = array(
					'agg_click_pc'         => $cpc,
					'agg_click_mobile'     => $cmb,
					'agg_break_pc'         => $bpc,
					'agg_break_mobile'     => $bmb,
					'agg_att_pc'           => $apc,
					'agg_att_mobile'       => $amb,
					'agg_sessions_desktop' => $sd,
					'agg_sessions_mobile'  => $sm,
					'agg_sessions'         => $sd + $sm,
					'agg_sort_sessions'    => $sort_sessions,
					'agg_interactions'     => $cpc + $cmb + $bpc + $bmb + $apc + $amb,
					'agg_last_event'       => ( ! empty( $m['last_event_time'] ) ) ? $m['last_event_time'] : null,
					'agg_has_data'         => ( ! empty( $m['has_data'] ) ) ? 1 : 0,
					'agg_spam_state'       => $default_spam,
					'agg_synced_at'        => $now,
				);
				$formats = array( '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s' );

				$target_ids = isset( $row_ids_by_page[ $pid ] ) ? $row_ids_by_page[ $pid ] : array();
				if ( empty( $target_ids ) ) {
					// Defensive fallback: no id resolved (row vanished mid-batch)
					// — keep the page synced via the legacy page_id predicate.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate column maintenance; values are computed ints/strings.
					$wpdb->update( $table, $data, array( 'page_id' => $pid ), $formats, array( '%d' ) );
				} else {
					foreach ( $target_ids as $row_id ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate column maintenance; values are computed ints/strings.
						$wpdb->update( $table, $data, array( 'id' => $row_id ), $formats, array( '%d' ) );
					}
				}
				++$synced;
			}
		}

		if ( null === $prev_spam ) {
			unset( $GLOBALS['opti_behavior_exclude_spam'] );
		} else {
			$GLOBALS['opti_behavior_exclude_spam'] = $prev_spam;
		}

		return $synced;
	}

	/**
	 * Whether any heatmap mapping rows still need an aggregate-column resync.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	private function has_stale_heatmap_aggregate_rows() {
		global $wpdb;

		if ( ! $this->heatmap_pages_aggregate_columns_exist() ) {
			return false;
		}

		$table        = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$default_spam = $this->get_default_spam_exclusion_enabled() ? 1 : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; identifiers hard-coded.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT page_id FROM {$table}
				WHERE (agg_synced_at IS NULL OR agg_spam_state <> %d)
					AND (click_count > 0 OR move_count > 0 OR scroll_count > 0)
				LIMIT 1",
				$default_spam
			)
		);

		return ! empty( $found );
	}

	/**
	 * Whether any mapping rows with data were NEVER aggregate-synced at all.
	 *
	 * Distinguishes "stale after ingest" (previous agg_* values are still
	 * serveable) from "never computed" (all agg_* columns are at their zero
	 * defaults — the fast path would wrongly hide such rows). A previously
	 * synced row keeps agg_has_data / agg_interactions / agg_last_event from
	 * its last sync even after ingest re-marks it stale.
	 *
	 * @since 1.7.0
	 * @return bool
	 */
	private function has_unsynced_heatmap_aggregate_rows() {
		$ids = $this->get_unsynced_heatmap_aggregate_page_ids( 1 );
		return ! empty( $ids );
	}

	/**
	 * Page ids of mapping rows with data that were NEVER aggregate-synced (RC6).
	 *
	 * Same predicate as {@see has_unsynced_heatmap_aggregate_rows()} but returns
	 * the id list so the fast path can bound its file-scan fallback to exactly
	 * this subset instead of discarding the whole indexed result.
	 *
	 * @since 1.8.2
	 * @param int $limit Max ids to return (0 = no limit).
	 * @return int[] Never-synced page ids (empty when columns are unavailable).
	 */
	private function get_unsynced_heatmap_aggregate_page_ids( $limit = 0 ) {
		global $wpdb;

		if ( ! $this->heatmap_pages_aggregate_columns_exist() ) {
			return array();
		}

		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$sql   = "SELECT page_id FROM {$table}
			WHERE agg_synced_at IS NULL
				AND agg_has_data = 0
				AND agg_interactions = 0
				AND agg_last_event IS NULL
				AND (click_count > 0 OR move_count > 0 OR scroll_count > 0)";

		$limit = (int) $limit;
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only probe; identifiers hard-coded, limit prepared.
		$ids = $wpdb->get_col( $sql );

		return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Schedule (once) the WP-Cron worker that drains the stale aggregate backlog.
	 *
	 * @since 1.7.0
	 * @param int $delay Seconds from now (default 30).
	 * @return void
	 */
	private function schedule_heatmap_aggregate_sync( $delay = 30 ) {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		// The per-day index shares every staleness trigger with the agg_*
		// columns (ingest, deletion, spam flips), so whenever the agg backlog
		// needs draining the daily backlog does too — schedule its sibling
		// worker here (cheap wp_next_scheduled check inside; the worker no-ops
		// when nothing is stale). Spec §P1 cron indexer wiring.
		$this->schedule_heatmap_daily_index_sync( $delay );

		if ( wp_next_scheduled( 'opti_behavior_heatmap_agg_sync' ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, (int) $delay ), 'opti_behavior_heatmap_agg_sync' );
	}

	/**
	 * WP-Cron worker: resync stale heatmap aggregate columns in bounded batches,
	 * re-scheduling itself until the backlog is drained (Perf RC2).
	 *
	 * Budget defaults keep each cron run well under typical PHP/gateway limits:
	 * up to 200 pages per run, 20 s wall-time.
	 *
	 * @since 1.7.0
	 * @return void
	 */
	public function cron_sync_heatmap_aggregates() {
		$budget = (float) apply_filters( 'opti_behavior_heatmap_agg_cron_time_budget', 20.0 );
		$batch  = (int) apply_filters( 'opti_behavior_heatmap_agg_cron_batch', 200 );

		$this->sync_heatmap_aggregate_columns( null, $budget, $batch );

		if ( $this->has_stale_heatmap_aggregate_rows() ) {
			$this->schedule_heatmap_aggregate_sync( 60 );
		}
	}

	/**
	 * Immediately resync the aggregate columns of specific pages, in bounded
	 * batches, within a wall-time budget.
	 *
	 * Cleanup-durability entry (2026-08-16 incident): after a scheduled
	 * cleanup cascade marks its affected pages stale, the Heatmaps list used
	 * to collapse to a tiny count (53 of ~3500 heatmaps in production) until
	 * an admin manually ran "Repair Heatmap Sync", because nothing recomputed
	 * the agg_* columns until the next admin page load. The Smart Cleanup
	 * service calls this right after `mark_pages_stale()` so the list is
	 * consistent again as soon as the cleanup pass finishes.
	 *
	 * Reuses {@see sync_heatmap_aggregate_columns()} — and therefore the
	 * single-flight 'opti_behavior_agg_sync' advisory lock and the 1000-page
	 * batch cap — in a loop until the affected pages are drained or the
	 * budget is spent. Each pass only ever picks still-stale rows, so the
	 * loop strictly shrinks the backlog (a lock-contended pass returns 0 and
	 * exits). Any remainder is drained by the self-rescheduling cron worker.
	 *
	 * @since 2026-08-16
	 * @param array<int,int> $page_ids    Heatmap page IDs to resync.
	 * @param float|null     $time_budget Max wall-clock seconds (null = unbounded).
	 * @return int Number of pages resynced in this call.
	 */
	public function resync_heatmap_aggregates_for_pages( $page_ids, $time_budget = 10.0 ) {
		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return 0;
		}

		$budget  = ( null === $time_budget ) ? null : max( 0.0, (float) $time_budget );
		$started = microtime( true );
		$total   = 0;

		do {
			$remaining = ( null === $budget ) ? null : ( $budget - ( microtime( true ) - $started ) );
			if ( null !== $remaining && $remaining <= 0 ) {
				break;
			}

			$synced = $this->sync_heatmap_aggregate_columns( $page_ids, $remaining, 1000 );
			$total += $synced;
		} while ( $synced > 0 );

		// Leftovers (budget hit, lock contention, or unrelated stale pages)
		// drain in the background so a cron cleanup pass never hangs here.
		if ( $this->has_stale_heatmap_aggregate_rows() ) {
			$this->schedule_heatmap_aggregate_sync( 30 );
		}

		return $total;
	}

	/**
	 * Whether the per-day heatmap index is usable (daily table + the daily_*
	 * marker columns on the mapping table). Resolved once per request; when the
	 * migration has not run yet the indexer silently no-ops (spec §P1).
	 *
	 * @since 1.9.x
	 * @return bool
	 */
	private function heatmap_daily_index_available() {
		if ( null !== self::$hm_daily_index_available ) {
			return self::$hm_daily_index_available;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema capability probe; result cached for the request.
		$table_ok = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'optibehavior_heatmap_daily' )
		);

		$cols_ok = false;
		if ( $table_ok ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema capability probe; result cached for the request.
			$cols_ok = (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					DB_NAME,
					$wpdb->prefix . 'optibehavior_heatmap_pages',
					'daily_synced_at'
				)
			);
		}

		self::$hm_daily_index_available = ( $table_ok && $cols_ok );
		return self::$hm_daily_index_available;
	}

	/**
	 * Refresh the per-day heatmap index for stale pages (spec §P1 incremental
	 * cron indexer).
	 *
	 * Mirrors {@see sync_heatmap_aggregate_columns()}: single-flight via a
	 * non-blocking advisory lock, filterable batch cap, wall-time budget
	 * honoured between chunks. A page is stale when ingest/deletion/spam
	 * reclassification NULLed daily_synced_at, or when the default spam state
	 * changed since its rows were derived (daily_spam_state mismatch) — this
	 * also covers upgrade backfill, where every page starts NULL.
	 *
	 * Re-derivation is FILENAME-ONLY (timestamp, device, count token, G|L
	 * marker, session token): zero file-content IO (~0.4 s per 50k filenames
	 * measured), so even a huge page converges within a few cron runs.
	 *
	 * @since 1.9.x
	 * @param array|null $page_ids    Restrict to these page IDs (default: all stale).
	 * @param float|null $time_budget Max wall-clock seconds to spend (null = unbounded).
	 * @param int|null   $batch_max   Default batch cap before the filter (null = 1000).
	 * @return int Number of pages re-derived.
	 */
	private function sync_heatmap_daily_index( $page_ids = null, $time_budget = null, $batch_max = null ) {
		global $wpdb;

		if ( ! $this->heatmap_daily_index_available() ) {
			return 0;
		}

		// Single-flight (same rationale as the agg sync): exactly one indexer
		// runs at a time; every other caller returns instantly.
		// PORTABILITY: fail-open lock helper (see sync_heatmap_aggregate_columns).
		$lock_state = Opti_Behavior_Heatmap_DB_Lock::acquire( 'opti_behavior_daily_sync', 0 );
		if ( Opti_Behavior_Heatmap_DB_Lock::BUSY === $lock_state ) {
			return 0;
		}

		try {
			return $this->sync_heatmap_daily_index_locked( $page_ids, $time_budget, $batch_max );
		} finally {
			Opti_Behavior_Heatmap_DB_Lock::release( 'opti_behavior_daily_sync', $lock_state );
		}
	}

	/**
	 * Body of the daily-index sync. Only ever runs under the
	 * 'opti_behavior_daily_sync' advisory lock acquired by the public entry.
	 *
	 * @since 1.9.x
	 * @param array|null $page_ids    Page ids to restrict to.
	 * @param float|null $time_budget Wall-time budget in seconds.
	 * @param int|null   $batch_max   Max pages per pass.
	 * @return int Pages re-derived.
	 */
	private function sync_heatmap_daily_index_locked( $page_ids = null, $time_budget = null, $batch_max = null ) {
		global $wpdb;

		$pages_table  = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$daily_table  = $wpdb->prefix . 'optibehavior_heatmap_daily';
		$default_spam = $this->get_default_spam_exclusion_enabled() ? 1 : 0;

		$where  = "(daily_synced_at IS NULL OR daily_spam_state <> %d) AND (click_count > 0 OR move_count > 0 OR scroll_count > 0)";
		$params = array( $default_spam );

		if ( is_array( $page_ids ) && ! empty( $page_ids ) ) {
			$ids = array_values( array_filter( array_map( 'absint', $page_ids ) ) );
			if ( ! empty( $ids ) ) {
				$where  .= ' AND page_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
				$params  = array_merge( $params, $ids );
			}
		}

		$default_max = ( null !== $batch_max && (int) $batch_max > 0 ) ? (int) $batch_max : 1000;
		$max         = (int) apply_filters( 'opti_behavior_heatmap_daily_sync_batch_max', $default_max );
		$params[]    = $max > 0 ? $max : $default_max;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared with placeholders; identifiers hard-coded.
		$stale_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT page_id FROM {$pages_table} WHERE {$where} ORDER BY last_data_at DESC LIMIT %d", $params )
		);
		$stale_ids = array_values( array_filter( array_map( 'intval', (array) $stale_ids ) ) );

		if ( empty( $stale_ids ) ) {
			return 0;
		}

		// Derive under the DEFAULT spam state (same contract as the agg sync);
		// restore the request's flag afterwards.
		$prev_spam                             = array_key_exists( 'opti_behavior_exclude_spam', $GLOBALS ) ? $GLOBALS['opti_behavior_exclude_spam'] : null;
		$GLOBALS['opti_behavior_exclude_spam'] = (bool) $default_spam;

		if ( (bool) $default_spam ) {
			$this->get_allowed_heatmap_session_lookup_for_pages( $stale_ids );
		}

		$upload_dir      = wp_upload_dir();
		$base_upload_dir = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';
		$started         = microtime( true );
		$now             = current_time( 'mysql' );
		$synced          = 0;
		$estimate_delta  = 0;
		$storage         = class_exists( 'Opti_Behavior_Heatmap_Storage' ) ? Opti_Behavior_Heatmap_Storage::get_instance() : null;

		foreach ( array_chunk( $stale_ids, 10 ) as $chunk ) {
			if ( null !== $time_budget && $synced > 0 && ( microtime( true ) - $started ) >= (float) $time_budget ) {
				break;
			}

			$this->prefetch_heatmap_file_dirs_for_pages( $chunk, $base_upload_dir );

			// Resolve concrete mapping-row ids up front (BUG-1 pattern): the
			// per-page file total is written to ONE canonical row per page so
			// sibling rows can never double the SUM(daily_file_count) the
			// estimate correction reads.
			$row_ids_by_page = array();
			$chunk_ph        = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared with placeholders; identifier hard-coded.
			$id_rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, page_id FROM {$pages_table} WHERE page_id IN ({$chunk_ph}) ORDER BY id ASC", $chunk )
			);
			foreach ( (array) $id_rows as $ir ) {
				$row_ids_by_page[ (int) $ir->page_id ][] = (int) $ir->id;
			}

			foreach ( $chunk as $pid ) {
				$derived = $this->derive_heatmap_daily_rows_for_page( $pid, $base_upload_dir );

				// Previous stored file total for the exact estimate correction.
				// Read from the daily rows themselves (BEFORE the replace below):
				// write-time upserts increment daily.file_count AND the estimate
				// option in lockstep, so this sum is exactly what the estimate
				// currently includes for this page — the pages.daily_file_count
				// stamp would miss those write-time increments and double-count
				// them on the first pass after ingest.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; identifier hard-coded.
				$prev_total      = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COALESCE(SUM(file_count), 0) FROM {$daily_table} WHERE page_id = %d", $pid )
				);
				$estimate_delta += ( $derived['file_total'] - $prev_total );

				// Full replace: DELETE + bulk INSERT keeps vanished days from
				// lingering (files deleted/reset since the last derivation).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; identifier hard-coded.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$daily_table} WHERE page_id = %d", $pid ) );

				if ( ! empty( $derived['rows'] ) ) {
					foreach ( array_chunk( $derived['rows'], 100 ) as $row_chunk ) {
						$values       = array();
						$placeholders = array();
						foreach ( $row_chunk as $row ) {
							// A day whose every file was spam-filtered or empty has
							// no last event — keep the column NULL (prepare() has no
							// NULL placeholder, so it is inlined as a literal).
							$has_last_ts    = ! empty( $row['last_event_ts'] );
							$placeholders[] = $has_last_ts
								? '(%d, %s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s, %s)'
								: '(%d, %s, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, NULL, %s)';
							array_push(
								$values,
								$pid,
								$row['day'],
								$row['click_pc'],
								$row['click_mobile'],
								$row['att_pc'],
								$row['att_mobile'],
								$row['break_pc'],
								$row['break_mobile'],
								$row['sessions_desktop'],
								$row['sessions_mobile'],
								$row['sessions_tablet'],
								$row['file_count']
							);
							if ( $has_last_ts ) {
								$values[] = $row['last_event_ts'];
							}
							$values[] = $now;
						}
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared with placeholders; identifiers hard-coded.
						$wpdb->query(
							$wpdb->prepare(
								"INSERT INTO {$daily_table}
								(page_id, day, click_pc, click_mobile, att_pc, att_mobile, break_pc, break_mobile,
								sessions_desktop, sessions_mobile, sessions_tablet, file_count, last_event_ts, synced_at)
								VALUES " . implode( ', ', $placeholders ),
								$values
							)
						);
					}
				}

				// Stamp the mapping row(s): synced marker + spam state on every
				// sibling; the file total on the canonical (lowest-id) row only.
				$target_ids   = isset( $row_ids_by_page[ $pid ] ) ? $row_ids_by_page[ $pid ] : array();
				$canonical_id = ! empty( $target_ids ) ? $target_ids[0] : 0;
				if ( $canonical_id ) {
					foreach ( $target_ids as $row_id ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Index-state maintenance; computed values.
						$wpdb->update(
							$pages_table,
							array(
								'daily_synced_at'  => $now,
								'daily_spam_state' => $default_spam,
								'daily_file_count' => ( $row_id === $canonical_id ) ? $derived['file_total'] : 0,
							),
							array( 'id' => $row_id ),
							array( '%s', '%d', '%d' ),
							array( '%d' )
						);
					}
				} else {
					// Row vanished mid-batch — legacy page_id predicate fallback.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Index-state maintenance; computed values.
					$wpdb->update(
						$pages_table,
						array(
							'daily_synced_at'  => $now,
							'daily_spam_state' => $default_spam,
							'daily_file_count' => $derived['file_total'],
						),
						array( 'page_id' => $pid ),
						array( '%s', '%d', '%d' ),
						array( '%d' )
					);
				}

				++$synced;
			}
		}

		// Correct the adaptive-mode total-files estimate exactly for every page
		// re-derived this pass (spec §P0: "corrected exactly on every pass").
		if ( 0 !== $estimate_delta && $storage && method_exists( $storage, 'adjust_total_files_estimate' ) ) {
			$storage->adjust_total_files_estimate( $estimate_delta );
		}

		if ( null === $prev_spam ) {
			unset( $GLOBALS['opti_behavior_exclude_spam'] );
		} else {
			$GLOBALS['opti_behavior_exclude_spam'] = $prev_spam;
		}

		return $synced;
	}

	/**
	 * Derive a page's per-day index rows from FILENAMES ONLY (spec §P1).
	 *
	 * Mirrors the per-file loop of {@see batch_heatmap_file_metrics_by_page()}
	 * exactly — same folder→bucket map, same spam-session filtering, same
	 * guest-only + reset-timestamp session semantics, same
	 * count_heatmap_file_points() token fast path — but buckets every value by
	 * the filename timestamp's UTC day, so summing the produced rows over any
	 * date window reproduces the live file-scan result by construction.
	 *
	 * Per-day file_count deliberately counts ALL parseable session files of
	 * that day (before spam/zero-point filtering) and file_total counts every
	 * globbed .json — these feed the disk-reconciliation/estimate logic, not
	 * displayed metrics.
	 *
	 * @since 1.9.x
	 * @param int    $page_id         Page id.
	 * @param string $base_upload_dir Heatmap data base dir (trailing slash).
	 * @return array{rows: array<int, array>, file_total: int}
	 */
	private function derive_heatmap_daily_rows_for_page( $page_id, $base_upload_dir ) {
		$page_id = absint( $page_id );
		$result  = array(
			'rows'       => array(),
			'file_total' => 0,
		);
		if ( ! $page_id ) {
			return $result;
		}

		$folder_map = array(
			'clicks'  => array( 'desktop' => 'click_pc', 'mobile' => 'click_mobile', 'tablet' => 'click_mobile' ),
			'moves'   => array( 'desktop' => 'att_pc', 'mobile' => 'att_mobile', 'tablet' => 'att_mobile' ),
			'scrolls' => array( 'desktop' => 'break_pc', 'mobile' => 'break_mobile', 'tablet' => 'break_mobile' ),
		);

		$spam_excluded    = $this->is_spam_excluded();
		$allowed_sessions = $spam_excluded ? $this->get_allowed_heatmap_session_lookup_for_page( $page_id ) : array();

		$url_hash_dirs = $this->get_heatmap_file_dirs_for_page( $page_id, $base_upload_dir );
		if ( empty( $url_hash_dirs ) ) {
			return $result;
		}

		$reset_ts = $this->get_heatmap_reset_timestamp_for_page( $page_id );
		$days     = array(); // day => aggregate buckets.
		$sessions = array(); // day => device => session => true.

		foreach ( $url_hash_dirs as $url_hash_dir ) {
			foreach ( $folder_map as $folder_name => $event_buckets ) {
				$dir = trailingslashit( $url_hash_dir ) . $folder_name;
				// Fresh glob: this page is being re-derived precisely because
				// its files changed (stale mark / drift), so a listing memoized
				// earlier in the request may be outdated (spec P4.2).
				$files = $this->glob_heatmap_dir_files( $dir, true );
				if ( empty( $files ) ) {
					continue;
				}

				$result['file_total'] += count( $files );

				foreach ( $files as $file ) {
					$file_parts = $this->parse_heatmap_file_name( $file );
					if ( empty( $file_parts ) ) {
						continue;
					}

					$file_ts     = $file_parts['timestamp'];
					$file_device = $file_parts['device'];
					$session     = $file_parts['session'];
					$day         = gmdate( 'Y-m-d', $file_ts );

					if ( ! isset( $days[ $day ] ) ) {
						$days[ $day ] = array(
							'day'              => $day,
							'click_pc'         => 0,
							'click_mobile'     => 0,
							'att_pc'           => 0,
							'att_mobile'       => 0,
							'break_pc'         => 0,
							'break_mobile'     => 0,
							'sessions_desktop' => 0,
							'sessions_mobile'  => 0,
							'sessions_tablet'  => 0,
							'file_count'       => 0,
							'last_ts'          => 0,
						);
					}
					++$days[ $day ]['file_count'];

					// Same strict spam-membership semantics as the live scan
					// (RC-C: orphan sessions the DB has never seen still count).
					if ( $spam_excluded && ! $this->is_heatmap_session_in_lookup( $session, $allowed_sessions ) ) {
						$known_sessions = $this->get_known_heatmap_session_lookup_for_page( $page_id, $base_upload_dir );
						if ( $this->is_heatmap_session_known( $session, $known_sessions ) ) {
							continue; // Known DB session, not allow-listed: spam — filtered.
						}
					}

					$count = $this->count_heatmap_file_points( $file );
					if ( $count <= 0 ) {
						continue;
					}

					$bucket                    = isset( $event_buckets[ $file_device ] ) ? $event_buckets[ $file_device ] : $event_buckets['desktop'];
					$days[ $day ][ $bucket ] += $count;

					// Guest-only session counting with reset-floor exclusion —
					// identical to the live scan's session semantics.
					if ( 'G' === $file_parts['marker'] && ! ( $reset_ts && $file_ts < $reset_ts ) ) {
						$sessions[ $day ][ $file_device ][ $session ] = true;
					}

					if ( $file_ts > $days[ $day ]['last_ts'] ) {
						$days[ $day ]['last_ts'] = $file_ts;
					}
				}
			}
		}

		ksort( $days );
		foreach ( $days as $day => $row ) {
			foreach ( array( 'desktop', 'mobile', 'tablet' ) as $dev ) {
				$row[ 'sessions_' . $dev ] = isset( $sessions[ $day ][ $dev ] ) ? count( $sessions[ $day ][ $dev ] ) : 0;
			}
			$row['last_event_ts'] = $row['last_ts'] > 0 ? gmdate( 'Y-m-d H:i:s', $row['last_ts'] ) : null;
			unset( $row['last_ts'] );
			$result['rows'][] = $row;
		}

		return $result;
	}

	/**
	 * Whether any mapping rows still need a daily-index re-derivation.
	 *
	 * @since 1.9.x
	 * @return bool
	 */
	private function has_stale_heatmap_daily_rows() {
		global $wpdb;

		if ( ! $this->heatmap_daily_index_available() ) {
			return false;
		}

		$table        = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$default_spam = $this->get_default_spam_exclusion_enabled() ? 1 : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; identifiers hard-coded.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT page_id FROM {$table}
				WHERE (daily_synced_at IS NULL OR daily_spam_state <> %d)
					AND (click_count > 0 OR move_count > 0 OR scroll_count > 0)
				LIMIT 1",
				$default_spam
			)
		);

		return ! empty( $found );
	}

	/**
	 * Schedule (once) the WP-Cron worker that drains the stale daily-index
	 * backlog (spec §P1 — sibling of {@see schedule_heatmap_aggregate_sync()}).
	 *
	 * @since 1.9.x
	 * @param int $delay Seconds from now (default 30).
	 * @return void
	 */
	private function schedule_heatmap_daily_index_sync( $delay = 30 ) {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( wp_next_scheduled( 'opti_behavior_heatmap_daily_sync' ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, (int) $delay ), 'opti_behavior_heatmap_daily_sync' );
	}

	/**
	 * WP-Cron worker: re-derive stale per-day index rows in bounded batches,
	 * re-scheduling itself until the backlog is drained (spec §P1 — mirrors
	 * {@see cron_sync_heatmap_aggregates()}). Handles the upgrade backfill
	 * (every page starts daily_synced_at = NULL) a batch at a time.
	 *
	 * @since 1.9.x
	 * @return void
	 */
	public function cron_sync_heatmap_daily_index() {
		$budget = (float) apply_filters( 'opti_behavior_heatmap_daily_cron_time_budget', 20.0 );
		$batch  = (int) apply_filters( 'opti_behavior_heatmap_daily_cron_batch', 200 );

		$this->sync_heatmap_daily_index( null, $budget, $batch );

		if ( $this->has_stale_heatmap_daily_rows() ) {
			$this->schedule_heatmap_daily_index_sync( 60 );
		}
	}

	/**
	 * Self-serve backfill trigger: on upgrade / admin load, arm the background
	 * drain workers whenever the agg_* columns or the per-day index carry a
	 * backlog (Task 1 — no WP-CLI, no manual step).
	 *
	 * Two use cases this closes that the lazy data-path arming does not:
	 *  1. Upgrade / cold install: an existing 35k–50k-page install upgrades to a
	 *     build that ships the agg_* columns / per-day index with every row NULL. The
	 *     Heatmaps page may never be opened (or its fast path never reached), so
	 *     nothing would otherwise start draining the full backlog.
	 *  2. Chain survival: the self-rescheduling worker chain
	 *     ({@see cron_sync_heatmap_aggregates()} / {@see cron_sync_heatmap_daily_index()})
	 *     dies if WP-Cron silently drops the single continuation event (host
	 *     quirk, DISABLE_WP_CRON with a missed external tick, a fatal in an
	 *     unrelated cron on the same run). Re-running this on every admin visit
	 *     re-detects the still-present backlog and re-arms the chain.
	 *
	 * Cost/overlap guard: if BOTH drain workers are already queued the chain is
	 * live and this returns after two O(1) option reads — no DB probe. Only when
	 * a worker is missing do we run the cheap LIMIT-1 staleness probes, and the
	 * schedule_* helpers each carry their own wp_next_scheduled guard, so the
	 * sibling already queued is never double-scheduled. Safe to call on every
	 * admin_init (mirrors {@see ensure_heatmap_reconcile_cron()}).
	 *
	 * @since 1.9.x
	 * @return void
	 */
	public function ensure_heatmap_backfill_drain() {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		// Chain-alive fast path: if BOTH sibling workers are queued the
		// self-rescheduling drain is live — nothing to do. Keeps the check
		// ~free on the overwhelming majority of admin loads.
		$agg_queued   = (bool) wp_next_scheduled( 'opti_behavior_heatmap_agg_sync' );
		$daily_queued = (bool) wp_next_scheduled( 'opti_behavior_heatmap_daily_sync' );
		if ( $agg_queued && $daily_queued ) {
			return;
		}

		// No live chain for at least one index: probe for a backlog with the
		// cheap LIMIT-1 indexed staleness queries and arm the worker(s) that
		// need it. has_stale_* return false when the columns/table do not exist
		// yet (migration pending), so a truly fresh install no-ops here.
		if ( ! $agg_queued && $this->has_stale_heatmap_aggregate_rows() ) {
			// Also arms the daily sibling (schedule_heatmap_aggregate_sync()
			// schedules schedule_heatmap_daily_index_sync() first).
			$this->schedule_heatmap_aggregate_sync( 30 );
			return;
		}

		if ( ! $daily_queued && $this->has_stale_heatmap_daily_rows() ) {
			$this->schedule_heatmap_daily_index_sync( 30 );
		}
	}

	/**
	 * Self-heal the recurring per-day-index drift reconciler cron (spec P4.2).
	 *
	 * Mirrors {@see self::ensure_heatmap_sync_reconcile_cron()}: safe to call on
	 * every admin_init; adds the hourly recurrence when missing without touching
	 * one-off continuation events, reschedules when the recurrence drifted.
	 *
	 * @since 1.9.x
	 * @return void
	 */
	public function ensure_heatmap_reconcile_cron() {
		$hook       = self::HEATMAP_RECONCILE_CRON_HOOK;
		$recurrence = 'hourly';

		$next     = wp_next_scheduled( $hook );
		$schedule = $next ? wp_get_schedule( $hook ) : false;

		if ( $next && false === $schedule ) {
			// A one-off continuation event exists but the recurring event does
			// not; leave the continuation alone and add the recurrence.
			$next = false;
		} elseif ( $next && $recurrence !== $schedule ) {
			wp_clear_scheduled_hook( $hook );
			$next = false;
		}

		if ( ! $next ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
		}
	}

	/**
	 * WP-Cron worker: sweep synced pages for index-vs-disk drift in rotating
	 * batches (spec P4.2). Deletions the immediate hooks (spec P4.1) could not
	 * see - manual FTP/shell removal, external cleanup, vanished url_hash dirs -
	 * are detected here from cheap dir metadata (file count + dir mtime, zero
	 * content IO) and repaired by stale-marking the page for the filename-only
	 * daily indexer.
	 *
	 * @since 1.9.x
	 * @return void
	 */
	public function cron_reconcile_heatmap_daily_index() {
		$budget = (float) apply_filters( 'opti_behavior_heatmap_reconcile_time_budget', 20.0 );
		$batch  = (int) apply_filters( 'opti_behavior_heatmap_reconcile_batch', 100 );

		$result = $this->reconcile_heatmap_daily_index( $budget, $batch );

		if ( ! empty( $result['drift_pages'] ) || ! empty( $result['vanished_pages'] ) ) {
			// Drifted pages were stale-marked - have the daily indexer re-derive
			// them shortly (it also corrects the total-files estimate exactly).
			// Vanished pages had their all-time agg columns invalidated - the
			// same worker re-derives those to an exact zero.
			$this->schedule_heatmap_aggregate_sync( 30 );
		}

		if ( ! empty( $result['more'] ) ) {
			// Sweep not finished - continue in ~1 min (one-off event; the hourly
			// recurrence stays in place for the next full sweep).
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HEATMAP_RECONCILE_CRON_HOOK );
		}
	}

	/**
	 * Drift-reconciliation entry point (spec P4.2). Single-flight via a
	 * non-blocking advisory lock, same pattern as the daily indexer.
	 *
	 * @since 1.9.x
	 * @param float $time_budget Max wall-clock seconds to spend.
	 * @param int   $batch_max   Max pages to inspect this pass.
	 * @return array{checked:int,drift_pages:int[],vanished_pages:int[],more:bool}
	 */
	private function reconcile_heatmap_daily_index( $time_budget = 20.0, $batch_max = 100 ) {
		global $wpdb;

		$result = array(
			'checked'        => 0,
			'drift_pages'    => array(),
			'vanished_pages' => array(),
			'more'           => false,
		);

		if ( ! $this->heatmap_daily_index_available() ) {
			return $result;
		}

		// PORTABILITY: fail-open lock helper (see sync_heatmap_aggregate_columns).
		$lock_state = Opti_Behavior_Heatmap_DB_Lock::acquire( 'opti_behavior_daily_reconcile', 0 );
		if ( Opti_Behavior_Heatmap_DB_Lock::BUSY === $lock_state ) {
			return $result;
		}

		try {
			return $this->reconcile_heatmap_daily_index_locked( $time_budget, $batch_max );
		} finally {
			Opti_Behavior_Heatmap_DB_Lock::release( 'opti_behavior_daily_reconcile', $lock_state );
		}
	}

	/**
	 * Body of the drift sweep. Only ever runs under the
	 * 'opti_behavior_daily_reconcile' advisory lock.
	 *
	 * Per page (SYNCED pages only - stale ones already await the indexer):
	 * compare the on-disk per-event-dir .json count and the newest event-dir
	 * mtime against the daily rows' stored file_count sum and the sync stamp.
	 * Count mismatch or a dir touched after the stamp => stale-mark (the
	 * filename-only indexer re-derives and corrects the estimate exactly).
	 * Every url_hash dir vanished => delete the page's daily rows, zero its
	 * file-count stamp and subtract the vanished files from the estimate
	 * directly (nothing left to derive).
	 *
	 * The sweep rotates through pages via a persisted page_id cursor so a full
	 * pass over 1,000+ pages completes across a few cron runs within the
	 * wall-time budget.
	 *
	 * @since 1.9.x
	 * @param float $time_budget Max wall-clock seconds.
	 * @param int   $batch_max   Max pages this pass.
	 * @return array{checked:int,drift_pages:int[],vanished_pages:int[],more:bool}
	 */
	private function reconcile_heatmap_daily_index_locked( $time_budget = 20.0, $batch_max = 100 ) {
		global $wpdb;

		$result = array(
			'checked'        => 0,
			'drift_pages'    => array(),
			'vanished_pages' => array(),
			'more'           => false,
		);

		$pages_table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$daily_table = $wpdb->prefix . 'optibehavior_heatmap_daily';
		$cursor      = max( 0, (int) get_option( self::HEATMAP_RECONCILE_CURSOR_OPTION, 0 ) );
		$batch_max   = max( 1, (int) $batch_max );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; identifier hard-coded.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page_id, MIN(daily_synced_at) AS synced_at
				FROM {$pages_table}
				WHERE page_id > %d AND daily_synced_at IS NOT NULL
				GROUP BY page_id ORDER BY page_id ASC LIMIT %d",
				$cursor,
				$batch_max
			)
		);

		if ( empty( $rows ) ) {
			// Sweep complete - rewind for the next recurrence.
			update_option( self::HEATMAP_RECONCILE_CURSOR_OPTION, 0, false );
			return $result;
		}

		$page_ids = array();
		$sync_ts  = array();
		foreach ( $rows as $r ) {
			$pid             = (int) $r->page_id;
			$page_ids[]      = $pid;
			$sync_ts[ $pid ] = $r->synced_at ? (int) strtotime( get_gmt_from_date( $r->synced_at ) ) : 0;
		}

		// Stored per-page file totals from the daily rows themselves: write-time
		// upserts increment daily.file_count in lockstep with new files, so a
		// healthy page matches its disk count exactly even between indexer runs.
		$stored = array();
		$ph     = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; identifier hard-coded.
		$sum_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page_id, COALESCE(SUM(file_count), 0) AS files FROM {$daily_table} WHERE page_id IN ({$ph}) GROUP BY page_id",
				$page_ids
			)
		);
		foreach ( (array) $sum_rows as $sr ) {
			$stored[ (int) $sr->page_id ] = (int) $sr->files;
		}

		$upload_dir      = wp_upload_dir();
		$base_upload_dir = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';
		$started         = microtime( true );
		$now             = current_time( 'mysql' );
		$last_pid        = $cursor;
		$storage         = class_exists( 'Opti_Behavior_Heatmap_Storage' ) ? Opti_Behavior_Heatmap_Storage::get_instance() : null;

		$this->prefetch_heatmap_file_dirs_for_pages( $page_ids, $base_upload_dir );

		foreach ( $page_ids as $pid ) {
			if ( $result['checked'] > 0 && ( microtime( true ) - $started ) >= (float) $time_budget ) {
				$result['more'] = true;
				break;
			}

			$last_pid = $pid;
			++$result['checked'];

			$stored_files = isset( $stored[ $pid ] ) ? $stored[ $pid ] : 0;
			$dirs         = $this->get_heatmap_file_dirs_for_page( $pid, $base_upload_dir );

			$existing_dirs = array();
			foreach ( (array) $dirs as $d ) {
				if ( is_dir( $d ) ) {
					$existing_dirs[] = $d;
				}
			}

			if ( empty( $existing_dirs ) ) {
				// Whole url_hash dir(s) vanished (manual removal, purge):
				// nothing left to derive - drop the daily rows and settle the
				// index/estimate directly (spec P4.2 vanished-dir case).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; identifier hard-coded.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$daily_table} WHERE page_id = %d", $pid ) );
				// Also invalidate the ALL-TIME aggregate columns: the table's
				// all-time fast path reads agg_interactions, which still holds
				// the pre-deletion value — without this the vanished page keeps
				// reporting its old interactions forever. The agg sync re-derives
				// from the (now empty) disk state to an exact zero.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; identifier hard-coded.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$pages_table}
						SET daily_synced_at = %s, daily_file_count = 0, agg_synced_at = NULL
						WHERE page_id = %d",
						$now,
						$pid
					)
				);
				if ( $stored_files > 0 ) {
					if ( $storage && method_exists( $storage, 'adjust_total_files_estimate' ) ) {
						$storage->adjust_total_files_estimate( -$stored_files );
					}
					$result['vanished_pages'][] = $pid;
				}
				continue;
			}

			// Cheap disk metadata: .json count per event dir + newest dir mtime.
			// No file contents are ever read here (spec P4.2).
			$disk_files = 0;
			$max_mtime  = 0;
			foreach ( $existing_dirs as $url_hash_dir ) {
				foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder_name ) {
					$dir = trailingslashit( $url_hash_dir ) . $folder_name;
					if ( ! is_dir( $dir ) ) {
						continue;
					}
					$disk_files += count( $this->glob_heatmap_dir_files( $dir, true ) );
					$dir_mtime   = (int) @filemtime( $dir );
					if ( $dir_mtime > $max_mtime ) {
						$max_mtime = $dir_mtime;
					}
				}
			}

			$synced_ts = isset( $sync_ts[ $pid ] ) ? (int) $sync_ts[ $pid ] : 0;

			if ( $disk_files !== $stored_files || ( $synced_ts > 0 && $max_mtime > $synced_ts ) ) {
				$result['drift_pages'][] = $pid;
			}
		}

		if ( ! empty( $result['drift_pages'] ) && $storage && method_exists( $storage, 'mark_pages_stale' ) ) {
			// Stale-mark => the filename-only daily indexer re-derives the rows
			// and corrects the total-files estimate exactly (spec P0/P1).
			$storage->mark_pages_stale( $result['drift_pages'] );
		}

		if ( ! $result['more'] ) {
			$result['more'] = ( count( $page_ids ) === $batch_max );
		}
		update_option( self::HEATMAP_RECONCILE_CURSOR_OPTION, $result['more'] ? $last_pid : 0, false );

		return $result;
	}

	/**
	 * O(1) read of the running total-files estimate (spec §P0 adaptive-mode
	 * heuristic). Maintained by the storage write path, deletion hooks and the
	 * daily-index cron (exact correction on every pass).
	 *
	 * @since 1.9.x
	 * @return int
	 */
	private function get_heatmap_total_files_estimate() {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			return 0;
		}
		return max( 0, (int) get_option( Opti_Behavior_Heatmap_Storage::TOTAL_FILES_ESTIMATE_OPTION, 0 ) );
	}

	/**
	 * File-count threshold below which dated requests keep the live near-real-time
	 * file scan (spec §P0). Above it the per-day index serves the request.
	 *
	 * @since 1.9.x
	 * @return int Always > 0.
	 */
	private function heatmap_live_scan_threshold() {
		$default = 5000;

		/**
		 * Filters the adaptive-mode live-scan threshold (total session files).
		 *
		 * @param int $default Default threshold (5,000 files ≈ 1–2 s filename-only scan).
		 */
		$threshold = (int) apply_filters( 'opti_behavior_heatmap_live_scan_threshold', $default );

		return $threshold > 0 ? $threshold : $default;
	}

	/**
	 * Soft wall-time budget for a live dated scan (spec §P0 safety valve).
	 *
	 * @since 1.9.x
	 * @return int Milliseconds, always > 0.
	 */
	private function heatmap_live_scan_soft_budget_ms() {
		$default = 2000;

		/**
		 * Filters the live-scan soft budget in milliseconds. A live scan that
		 * exceeds it flips the install to the indexed path for subsequent
		 * requests (self-measuring valve — no request can time out repeatedly
		 * on a wrong total-files estimate).
		 *
		 * @param int $default Default soft budget (2,000 ms).
		 */
		$budget = (int) apply_filters( 'opti_behavior_heatmap_live_scan_soft_budget_ms', $default );

		return $budget > 0 ? $budget : $default;
	}

	/**
	 * Hard cap on session files any single web request may enumerate/scan
	 * (spec §P3 safety net). 0 = uncapped (CLI / cron maintenance contexts,
	 * where full passes are intended and bounded by their own budgets).
	 *
	 * @since 1.9.x
	 * @return int Cap (>= 0; 0 disables the cap).
	 */
	private function heatmap_request_scan_cap() {
		$is_background = ( 'cli' === PHP_SAPI ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() );
		$default       = $is_background ? 0 : 5000;

		/**
		 * Filters the per-request live file-scan cap. When a request would scan
		 * more files, the remaining pages are served stale (zero metrics until
		 * the cron indexer catches up) and a background sync is scheduled —
		 * no web request is ever O(total files).
		 *
		 * @param int  $default       Default cap (5,000 on web requests, 0 = uncapped in CLI/cron).
		 * @param bool $is_background Whether this is a CLI/cron context.
		 */
		$cap = (int) apply_filters( 'opti_behavior_heatmap_max_files_per_request_scan', $default, $is_background );

		return max( 0, $cap );
	}

	/**
	 * Whether the "force indexed" valve flag is active (spec §P0).
	 *
	 * Set when a live scan exceeded the soft budget; carries a validity window
	 * so an install that later shrinks (mass deletion) self-heals back to the
	 * live path — the valve simply re-arms on the next slow scan.
	 *
	 * @since 1.9.x
	 * @return bool
	 */
	private function heatmap_force_indexed_flag_active() {
		$until = (int) get_option( 'opti_behavior_heatmap_force_indexed_until', 0 );
		return $until > time();
	}

	/**
	 * Arm the "force indexed" valve flag for subsequent requests (spec §P0).
	 *
	 * @since 1.9.x
	 * @return void
	 */
	private function set_heatmap_force_indexed_flag() {
		$window = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		update_option( 'opti_behavior_heatmap_force_indexed_until', (string) ( time() + $window ), 'yes' );
	}

	/**
	 * Adaptive-mode decision for dated requests (spec §P0): indexed path when
	 * the tracked total-files estimate exceeds the live-scan threshold, or when
	 * the self-measuring valve tripped. O(1) — one autoloaded option read each,
	 * no counting at decision time.
	 *
	 * @since 1.9.x
	 * @return bool True = serve the request from the per-day index.
	 */
	private function should_use_indexed_heatmap_dated_path() {
		if ( ! $this->heatmap_daily_index_available() ) {
			return false;
		}
		if ( $this->heatmap_force_indexed_flag_active() ) {
			return true;
		}
		return $this->get_heatmap_total_files_estimate() > $this->heatmap_live_scan_threshold();
	}

	/**
	 * Adaptive-mode decision for the all-time (period=all) request (spec §P0,
	 * Task 2): indexed `agg_*` path when the tracked total-files estimate
	 * exceeds the live-scan threshold, or when the self-measuring valve tripped.
	 * All-time analogue of {@see should_use_indexed_heatmap_dated_path()}, but
	 * keyed on the precomputed aggregate COLUMNS (the all-time index) rather than
	 * the per-day daily index. O(1) — one autoloaded option read each, no
	 * counting at decision time.
	 *
	 * When true, {@see maybe_fast_heatmap_pages_data()} must NEVER bail to the
	 * full O(files) live scan just because the unsynced backlog exceeds the
	 * inline-merge cap: it serves the indexed rows as-is + a bounded-merge of a
	 * capped never-synced subset (mirroring the large-scale branch) and lets
	 * WP-Cron converge the backlog.
	 *
	 * @since 1.9.x
	 * @return bool True = keep the request on the indexed all-time path.
	 */
	private function should_use_indexed_heatmap_alltime_path() {
		if ( ! $this->heatmap_pages_aggregate_columns_exist() ) {
			return false;
		}
		if ( $this->heatmap_force_indexed_flag_active() ) {
			return true;
		}
		return $this->get_heatmap_total_files_estimate() > $this->heatmap_live_scan_threshold();
	}

	/**
	 * Record the wall time of a live dated file scan (spec §P0 safety valve):
	 * above the soft budget, arm the force-indexed flag and make sure the
	 * daily index is being built so the next request can actually use it.
	 *
	 * @since 1.9.x
	 * @param float $elapsed_ms Live-scan wall time in milliseconds.
	 * @return void
	 */
	private function note_heatmap_live_scan_time( $elapsed_ms ) {
		if ( (float) $elapsed_ms <= (float) $this->heatmap_live_scan_soft_budget_ms() ) {
			return;
		}
		if ( ! $this->heatmap_daily_index_available() ) {
			return;
		}
		$this->set_heatmap_force_indexed_flag();
		$this->schedule_heatmap_daily_index_sync( 30 );
	}

	/**
	 * Page ids of mapping rows with data whose per-day index rows were NEVER
	 * derived (daily_synced_at IS NULL) — the RC6 bounded-merge candidates of
	 * the dated fast path.
	 *
	 * @since 1.9.x
	 * @param int $limit Max ids to return (0 = no limit).
	 * @return int[]
	 */
	private function get_unsynced_heatmap_daily_page_ids( $limit = 0 ) {
		global $wpdb;

		if ( ! $this->heatmap_daily_index_available() ) {
			return array();
		}

		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$sql   = "SELECT DISTINCT page_id FROM {$table}
			WHERE daily_synced_at IS NULL
				AND (click_count > 0 OR move_count > 0 OR scroll_count > 0)";

		$limit = (int) $limit;
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read-only probe; identifiers hard-coded, limit prepared.
		$ids = $wpdb->get_col( $sql );

		return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Dated-period fast path (spec §P2): serve a date-windowed table/stats
	 * request from `optibehavior_heatmap_daily` SUM queries — O(pages), zero
	 * file IO — instead of the O(files) live scan.
	 *
	 * Returns rows in the exact shape of {@see maybe_fast_heatmap_pages_data()}
	 * (click/attention/breakaway buckets, guest per-device session counts,
	 * windowed last_event_time), restricted to pages with counted (non-spam,
	 * non-empty) data inside the window — mirroring the live path's
	 * drop_empty behaviour under the default spam-excluded view. Daily sums ==
	 * live file-scan sums by construction (proven by the daily-index parity
	 * test); period uniques = SUM of per-day uniques (spec §5b, cross-midnight
	 * double-count accepted).
	 *
	 * Unsynced pages (daily_synced_at NULL) follow the RC6 bounded-merge
	 * pattern: a small capped subset is computed live (their write-time daily
	 * rows are excluded so the two sources stay disjoint); above the cap the
	 * indexed rows are served as-is (stale for those pages) and cron converges
	 * the backlog — NEVER a full file-scan fallback.
	 *
	 * Returns null when the index cannot represent this request (no window, no
	 * table, non-default spam view) — the caller then decides live vs nothing.
	 *
	 * @since 1.9.x
	 * @param string|null $start_date Window start (datetime or date string).
	 * @param string|null $end_date   Window end (datetime or date string).
	 * @return array|null Pages-data rows, or null when not applicable.
	 */
	private function maybe_fast_heatmap_pages_data_dated( $start_date, $end_date ) {
		if ( null === $start_date && null === $end_date ) {
			return null;
		}
		if ( ! $this->heatmap_daily_index_available() ) {
			return null;
		}
		if ( ! $this->get_default_spam_exclusion_enabled() || ! $this->is_spam_excluded() ) {
			return null;
		}
		if ( ! apply_filters( 'opti_behavior_heatmap_use_indexed_sort', true ) ) {
			return null;
		}

		// Day-granular window (the index buckets by the filename timestamp's
		// UTC day — same day function the derivation uses).
		$start_ts  = $start_date ? strtotime( $start_date ) : 0;
		$end_ts    = $end_date ? strtotime( $end_date ) : time();
		$day_start = $start_ts ? gmdate( 'Y-m-d', $start_ts ) : '0000-01-01';
		$day_end   = $end_ts ? gmdate( 'Y-m-d', $end_ts ) : '9999-12-31';

		// Request memo: the table path and both stats windowed passes hit the
		// same window within one request — compute once.
		$memo_key = $day_start . '|' . $day_end;
		if ( isset( $this->hm_dated_fast_memo[ $memo_key ] ) ) {
			$cached = $this->hm_dated_fast_memo[ $memo_key ];
			return false === $cached ? null : $cached;
		}

		// Bounded inline top-up (mirrors the all-time fast path): drain a few
		// stale pages so fresh ingest appears quickly, but never let a cold
		// backfill block the render — cron drains the rest.
		$inline_budget = (float) apply_filters( 'opti_behavior_heatmap_sync_inline_time_budget', 5.0 );
		$inline_batch  = (int) apply_filters( 'opti_behavior_heatmap_sync_inline_batch', 25 );
		$this->sync_heatmap_daily_index( null, $inline_budget, $inline_batch );

		$merge_ids = array();
		if ( $this->has_stale_heatmap_daily_rows() ) {
			$this->schedule_heatmap_daily_index_sync( 30 );

			// RC6 bounded merge: only a small never-derived subset is ever
			// computed live; above the cap the indexed rows are served as-is
			// (stale + cron), never a full scan (spec §P2).
			$merge_max = max( 1, (int) apply_filters( 'opti_behavior_heatmap_unsynced_merge_max', 150 ) );
			$unsynced  = $this->get_unsynced_heatmap_daily_page_ids( $merge_max + 1 );
			if ( count( $unsynced ) <= $merge_max ) {
				$merge_ids = $unsynced;
			}
		}

		global $wpdb;
		$daily_table = $wpdb->prefix . 'optibehavior_heatmap_daily';

		$exclude_sql = '';
		$params      = array( $day_start, $day_end );
		if ( ! empty( $merge_ids ) ) {
			// The live-merged pages may already hold write-time daily rows —
			// exclude them here so every page is sourced from exactly one of
			// the two exact computations (no double count).
			$exclude_sql = ' AND page_id NOT IN (' . implode( ',', array_fill( 0, count( $merge_ids ), '%d' ) ) . ')';
			$params      = array_merge( $params, $merge_ids );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared with placeholders; identifiers hard-coded. Indexed O(pages) SUM by design.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT page_id,
					SUM(click_pc) AS click_pc, SUM(click_mobile) AS click_mobile,
					SUM(att_pc) AS att_pc, SUM(att_mobile) AS att_mobile,
					SUM(break_pc) AS break_pc, SUM(break_mobile) AS break_mobile,
					SUM(sessions_desktop) AS sessions_desktop,
					SUM(sessions_mobile) AS sessions_mobile,
					SUM(sessions_tablet) AS sessions_tablet,
					MAX(last_event_ts) AS last_event_ts, MAX(day) AS max_day
				FROM {$daily_table}
				WHERE day BETWEEN %s AND %s{$exclude_sql}
				GROUP BY page_id",
				$params
			)
		);

		$pages_data = array();
		foreach ( (array) $rows as $r ) {
			$points = (int) $r->click_pc + (int) $r->click_mobile
				+ (int) $r->att_pc + (int) $r->att_mobile
				+ (int) $r->break_pc + (int) $r->break_mobile;
			if ( $points <= 0 ) {
				// Mirrors the live path's drop_empty under the spam-excluded
				// view: a page whose every window file was spam/empty is hidden.
				continue;
			}

			$pages_data[] = array(
				'page_id'          => (int) $r->page_id,
				'click_pc'         => (int) $r->click_pc,
				'click_mobile'     => (int) $r->click_mobile,
				'breakaway_pc'     => (int) $r->break_pc,
				'breakaway_mobile' => (int) $r->break_mobile,
				'attention_pc'     => (int) $r->att_pc,
				'attention_mobile' => (int) $r->att_mobile,
				'sessions_desktop' => (int) $r->sessions_desktop,
				'sessions_mobile'  => (int) $r->sessions_mobile,
				'sessions_tablet'  => (int) $r->sessions_tablet,
				// Same convention as the all-time fast path: displayed Sessions
				// == desktop + mobile (sort key == displayed value).
				'sessions'         => (int) $r->sessions_desktop + (int) $r->sessions_mobile,
				'last_event_time'  => ! empty( $r->last_event_ts ) ? $r->last_event_ts : ( $r->max_day . ' 00:00:00' ),
			);
		}

		// RC6 merge: compute the (small) never-derived subset with the SAME
		// live file-scan aggregators, windowed, and append. Disjoint from the
		// indexed rows by the NOT IN above.
		if ( ! empty( $merge_ids ) ) {
			$lookup = array();
			foreach ( $merge_ids as $mid ) {
				$lookup[ (int) $mid ] = true;
			}

			$subset = array();
			foreach ( (array) $this->get_heatmap_pages_data_from_file_source() as $row ) {
				if ( isset( $row['page_id'] ) && isset( $lookup[ (int) $row['page_id'] ] ) ) {
					$subset[] = $row;
				}
			}
			if ( ! empty( $subset ) ) {
				$subset = $this->apply_heatmap_file_metrics_to_pages_data( $subset, $start_date, $end_date, true );
				foreach ( $subset as &$srow ) {
					$sd               = isset( $srow['sessions_desktop'] ) ? (int) $srow['sessions_desktop'] : 0;
					$sm               = isset( $srow['sessions_mobile'] ) ? (int) $srow['sessions_mobile'] : 0;
					$srow['sessions'] = $sd + $sm;
				}
				unset( $srow );
				$pages_data = array_merge( $pages_data, array_values( $subset ) );
			}
		}

		$this->hm_dated_fast_memo[ $memo_key ] = $pages_data;

		return $pages_data;
	}

	/**
	 * Windowed metrics dispatcher for the stats cards (spec §P2): the "this
	 * week" passes are served from the per-day index on installs in indexed
	 * mode, and from the live windowed file scan otherwise (unchanged
	 * small-install behaviour).
	 *
	 * @since 1.9.x
	 * @param array       $pages_data Window-candidate pages data.
	 * @param string|null $start_date Window start.
	 * @param string|null $end_date   Window end.
	 * @return array Pages-data rows with windowed metrics.
	 */
	private function apply_heatmap_windowed_metrics( $pages_data, $start_date, $end_date ) {
		if ( $this->should_use_indexed_heatmap_dated_path() ) {
			$indexed = $this->maybe_fast_heatmap_pages_data_dated( $start_date, $end_date );
			if ( is_array( $indexed ) ) {
				$wanted = array();
				foreach ( (array) $pages_data as $row ) {
					if ( isset( $row['page_id'] ) ) {
						$wanted[ (int) $row['page_id'] ] = true;
					}
				}
				$out = array();
				foreach ( $indexed as $row ) {
					if ( isset( $row['page_id'] ) && isset( $wanted[ (int) $row['page_id'] ] ) ) {
						$out[] = $row;
					}
				}
				return $out;
			}
		}

		return $this->apply_heatmap_file_metrics_to_pages_data(
			$pages_data,
			$start_date,
			$end_date,
			$this->is_spam_excluded()
		);
	}

	/**
	 * Self-heal the recurring heatmap DB/file sync-status reconciliation cron
	 * (spec §3b). Mirrors Opti_Behavior_Heatmap_Database::ensure_scheduled_reports_cron()
	 * / ensure_finalize_stale_sessions_cron(): safe to call on every admin_init,
	 * reschedules when the recurrence drifted (e.g. the interval definition
	 * changed or the event was cleared).
	 *
	 * @since 1.8.x
	 * @return void
	 */
	public function ensure_heatmap_sync_reconcile_cron() {
		$hook       = self::HEATMAP_SYNC_CRON_HOOK;
		$recurrence = 'every_fifteen_minutes';

		$next     = wp_next_scheduled( $hook );
		$schedule = $next ? wp_get_schedule( $hook ) : false;

		if ( $next && $recurrence !== $schedule ) {
			wp_clear_scheduled_hook( $hook );
			$next = false;
		}

		if ( ! $next ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
		}
	}

	/**
	 * Whether the daily scheduled heatmap sync auto-repair is enabled.
	 *
	 * Opt-out semantics: the option is created lazily, so an absent option
	 * means the default — enabled. Only an explicit '0' disables it.
	 *
	 * @since 1.8.x
	 * @return bool
	 */
	public function is_heatmap_auto_repair_enabled() {
		return '0' !== (string) get_option( self::HEATMAP_AUTO_REPAIR_OPTION, '1' );
	}

	/**
	 * Self-heal the daily heatmap sync auto-repair cron event according to the
	 * Danger Zone toggle: schedule (daily) when enabled, clear when disabled.
	 * Mirrors {@see self::ensure_heatmap_sync_reconcile_cron()} — safe to call
	 * on every admin_init and from the settings save handler.
	 *
	 * @since 1.8.x
	 * @return void
	 */
	public function ensure_heatmap_auto_repair_cron() {
		$hook       = self::HEATMAP_AUTO_REPAIR_CRON_HOOK;
		$recurrence = 'daily';

		if ( ! $this->is_heatmap_auto_repair_enabled() ) {
			wp_clear_scheduled_hook( $hook );
			return;
		}

		$next     = wp_next_scheduled( $hook );
		$schedule = $next ? wp_get_schedule( $hook ) : false;

		if ( $next && false === $schedule ) {
			// A one-off continuation event exists but the recurring daily event
			// does not; leave the continuation alone and add the recurrence.
			$next = false;
		} elseif ( $next && $recurrence !== $schedule ) {
			wp_clear_scheduled_hook( $hook );
			$next = false;
		}

		if ( ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, $hook );
		}
	}

	/**
	 * Self-heal the recurring events-based registry backfill cron. Daily
	 * recurrence acts as a light watchdog once the initial backlog is drained;
	 * catch-up speed comes from 1-minute single-event continuations scheduled by
	 * {@see self::run_heatmap_registry_backfill()} while work remains. Safe to
	 * call on every admin_init (mirrors ensure_heatmap_sync_reconcile_cron()).
	 *
	 * @since 1.9.x
	 * @return void
	 */
	public function ensure_heatmap_registry_backfill_cron() {
		$hook       = self::HEATMAP_BACKFILL_CRON_HOOK;
		$recurrence = 'daily';

		$next     = wp_next_scheduled( $hook );
		$schedule = $next ? wp_get_schedule( $hook ) : false;

		if ( $next && false === $schedule ) {
			// A one-off continuation exists but the recurring daily event does
			// not; leave the continuation alone and add the recurrence.
			$next = false;
		} elseif ( $next && $recurrence !== $schedule ) {
			wp_clear_scheduled_hook( $hook );
			$next = false;
		}

		if ( ! $next ) {
			// Kick off soon so a freshly-updated install starts healing its
			// backlog within a minute instead of waiting a full day.
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
		}
	}

	/**
	 * Schedule a one-off continuation of the backfill pass (backlog not yet
	 * drained, or lock contention). Same hook as the daily recurrence, so the
	 * callback path is identical.
	 *
	 * @since 1.9.x
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	private function schedule_heatmap_backfill_continuation( $delay ) {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::HEATMAP_BACKFILL_CRON_HOOK );
	}

	/**
	 * WP-Cron callback: one batch of the events-based registry backfill.
	 *
	 * Finds up to {@see self::HEATMAP_BACKFILL_BATCH_SIZE} page_id2 values that
	 * carry heatmap-interaction events (codes 16/17/32/33/48/49) tied to a
	 * SURVIVING, non-spam session but have NO optibehavior_heatmap_pages row, and
	 * inserts the registry row with click/move/scroll counts derived from the
	 * events table — restricted to those surviving non-spam sessions. Disk-only
	 * orphan directories (files with no surviving session) are intentionally NOT
	 * touched, so cleanup/spam-deleted data can never be resurrected.
	 *
	 * Guarded by the shared {@see self::HEATMAP_SYNC_LOCK_NAME} advisory lock so
	 * it never overlaps a reconcile/repair pass. Cursor-resumable over page_id2;
	 * a full drained pass resets the cursor and marks the backfill complete.
	 *
	 * @since 1.9.x
	 * @return array{processed:int,inserted:int,complete:bool} Run result (used by tests).
	 */
	public function run_heatmap_registry_backfill() {
		global $wpdb;

		$result = array(
			'processed' => 0,
			'inserted'  => 0,
			'complete'  => false,
		);

		$events_table  = $wpdb->prefix . 'optibehavior_events';
		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		$pages_table   = $wpdb->prefix . 'optibehavior_pages';
		$registry_table = $wpdb->prefix . 'optibehavior_heatmap_pages';

		foreach ( array( $events_table, $sessions_table, $pages_table, $registry_table ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence guard; runs on cron only.
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				$result['complete'] = true;
				return $result;
			}
		}

		$got_lock = $this->acquire_heatmap_compute_lock( self::HEATMAP_SYNC_LOCK_NAME, 5 );
		if ( ! $got_lock ) {
			// A reconcile/repair pass is scanning; retry shortly instead of
			// skipping the backlog.
			$this->schedule_heatmap_backfill_continuation( 5 * MINUTE_IN_SECONDS );
			return $result;
		}

		try {
			$cursor = (int) get_option( self::HEATMAP_BACKFILL_CURSOR_OPTION, 0 );

			// Respect the shared spam policy: exclude spam/bot/automated sessions
			// from BOTH candidate detection and derived counts, so a page whose
			// only interactions came from excluded traffic is never resurrected.
			$spam_where = '';
			if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
				$spam_where = Opti_Behavior_Stats_Spam_Filter::session_sql( 's', 'AND', null );
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin tables from $wpdb->prefix; the spam fragment is built by the shared filter from an esc_sql allow-list; values below are bound via prepare().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron-time backfill scan; no caching.
			$candidates = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT e.page_id2 AS page_id,
						SUM(CASE WHEN e.event IN (16,17) THEN 1 ELSE 0 END) AS click_count,
						SUM(CASE WHEN e.event IN (48,49) THEN 1 ELSE 0 END) AS move_count,
						SUM(CASE WHEN e.event IN (32,33) THEN 1 ELSE 0 END) AS scroll_count
					FROM {$events_table} e
					INNER JOIN {$sessions_table} s ON s.id = e.session_id
					LEFT JOIN {$registry_table} hp ON hp.page_id = e.page_id2
					WHERE e.event IN (16,17,32,33,48,49)
						AND e.page_id2 > %d
						AND hp.page_id IS NULL
						{$spam_where}
					GROUP BY e.page_id2
					HAVING (click_count + move_count + scroll_count) > 0
					ORDER BY e.page_id2 ASC
					LIMIT %d",
					$cursor,
					self::HEATMAP_BACKFILL_BATCH_SIZE
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( empty( $candidates ) ) {
				// Drained: either fully caught up, or (cursor>0) reached the end.
				// Reset the cursor so the next daily watchdog rescans from the
				// start and heals any newly-orphaned pages.
				update_option( self::HEATMAP_BACKFILL_CURSOR_OPTION, 0, false );
				update_option( self::HEATMAP_BACKFILL_DONE_OPTION, '1', false );
				$result['complete'] = true;
				return $result;
			}

			// A new backlog is being worked: clear the done marker.
			update_option( self::HEATMAP_BACKFILL_DONE_OPTION, '0', false );

			$storage  = class_exists( 'Opti_Behavior_Heatmap_Storage' ) ? Opti_Behavior_Heatmap_Storage::get_instance() : null;
			$now      = current_time( 'mysql' );
			$max_pid  = $cursor;

			foreach ( $candidates as $row ) {
				$page_id = (int) $row->page_id;
				$max_pid = max( $max_pid, $page_id );
				++$result['processed'];

				// Resolve the page's current URL; without it we cannot compute a
				// url_hash matching the write path — skip (cursor still advances).
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; id prepared.
				$url = $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$pages_table}
					WHERE id = %d", $page_id ) );
				if ( ! $url && $storage ) {
					// Guardrail (2026-08-15, Option B): the optibehavior_pages row
					// may have been erased by cleanup_pages() after a retention
					// purge, while the page's sessions/events/JSON files survive.
					// Recover the URL from the page's newest heatmap JSON file
					// (decodes AT MOST one file) before giving up on the page.
					$url = $this->resolve_backfill_page_url_from_files( $page_id, $storage );
				}
				if ( ! $url || ! $storage ) {
					continue;
				}

				$url_hash = $storage->get_url_hash( $url );

				// Race guard: re-check no row was created since the batch SELECT
				// (e.g. live traffic wrote one) to avoid a duplicate registry row.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table; values prepared.
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$registry_table} WHERE page_id = %d OR url_hash = %s",
						$page_id,
						$url_hash
					)
				);
				if ( $exists > 0 ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table from $wpdb->prefix; wpdb->insert prepares values.
				$inserted = $wpdb->insert(
					$registry_table,
					array(
						'page_id'      => $page_id,
						'url_hash'     => $url_hash,
						'url'          => $url,
						'created_at'   => $now,
						'updated_at'   => $now,
						'click_count'  => (int) $row->click_count,
						'move_count'   => (int) $row->move_count,
						'scroll_count' => (int) $row->scroll_count,
						'last_data_at' => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
				);

				if ( false !== $inserted ) {
					++$result['inserted'];
				}
			}

			// Advance the cursor past this batch (even for skipped rows) so the
			// pass always makes forward progress and never loops.
			update_option( self::HEATMAP_BACKFILL_CURSOR_OPTION, $max_pid, false );
		} finally {
			$this->release_heatmap_compute_lock( self::HEATMAP_SYNC_LOCK_NAME, $got_lock );
		}

		if ( $result['inserted'] > 0 && function_exists( 'opti_behavior_flush_heatmap_caches' ) ) {
			opti_behavior_flush_heatmap_caches();
		}

		// Backlog not drained: continue quickly (1 minute) to catch up.
		$this->schedule_heatmap_backfill_continuation( MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Backfill URL fallback when the optibehavior_pages row is gone (guardrail
	 * policy 2026-08-15, Option B).
	 *
	 * The historical retention bug deleted DB rows while heatmap JSON files
	 * survived on disk; the next daily cleanup_pages() then erased the page's
	 * optibehavior_pages row, so the backfill could never resolve a URL and
	 * skipped the page forever. This fallback recovers the URL, bounded-cost:
	 *
	 *  1. Newest URL recorded for the page in optibehavior_pageviews, then
	 *     optibehavior_session_pages (both single indexed page_id lookups).
	 *  2. Locate the page's on-disk hash dir from that candidate URL (canonical
	 *     write-path hash + the 3 legacy md5 variants — at most 4 is_dir checks)
	 *     and decode the NEWEST heatmap JSON file in it (ONE json_decode max;
	 *     filenames are timestamp-prefixed so "newest" is a string max, no
	 *     content reads). The file's self-described `url` is the exact
	 *     writer-path URL, so the derived url_hash matches the existing dir.
	 *  3. If the file cannot be located/decoded, fall back to the DB candidate
	 *     URL; if no candidate exists either, return '' (caller skips as before).
	 *
	 * Only runs for candidate pages whose pages-row is missing (rare), inside a
	 * cron batch capped at {@see self::HEATMAP_BACKFILL_BATCH_SIZE} — never on a
	 * hot path.
	 *
	 * @since 2026-08-15 (guardrail: backfill URL fallback)
	 * @param int                          $page_id Page ID (optibehavior_pages id, row missing).
	 * @param Opti_Behavior_Heatmap_Storage $storage Storage instance.
	 * @return string Resolved URL, or '' when unresolvable.
	 */
	private function resolve_backfill_page_url_from_files( $page_id, $storage ) {
		global $wpdb;

		$page_id = (int) $page_id;
		if ( $page_id <= 0 || ! $storage ) {
			return '';
		}

		// 1. Candidate URL from tables that denormalize the URL per pageview.
		$candidate = '';
		foreach ( array( 'optibehavior_pageviews', 'optibehavior_session_pages' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence guard; cron-only fallback.
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table from $wpdb->prefix; id prepared; page_id is indexed.
			$candidate = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT url FROM {$table} WHERE page_id = %d ORDER BY id DESC LIMIT 1",
					$page_id
				)
			);
			if ( '' !== $candidate ) {
				break;
			}
		}

		if ( '' === $candidate ) {
			// No DB trace of the URL at all — the file-first rebuild (Option A)
			// is the tool for fully-wiped pages; nothing safe to do here.
			return '';
		}

		// 2. Locate the page's hash dir from the candidate (≤4 is_dir checks).
		$base_dir = $storage->get_base_dir();
		$hashes   = array_unique(
			array(
				$storage->get_url_hash( $candidate ),
				md5( $candidate ),
				md5( rtrim( $candidate, '/' ) ),
				md5( $candidate . '/' ),
			)
		);

		$newest_file = '';
		$newest_name = '';
		foreach ( $hashes as $hash ) {
			if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $hash ) || ! is_dir( $base_dir . $hash ) ) {
				continue;
			}
			foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder ) {
				foreach ( (array) glob( $base_dir . $hash . '/' . $folder . '/*.json' ) as $file ) {
					$name = basename( $file );
					// Filenames start with the unix timestamp — lexical compare
					// on the basename picks the newest without any content read.
					if ( '' === $newest_name || strcmp( $name, $newest_name ) > 0 ) {
						$newest_name = $name;
						$newest_file = $file;
					}
				}
			}
			if ( '' !== $newest_file ) {
				break; // Dir found and non-empty — never scan another hash dir.
			}
		}

		// 3. Decode ONE file max; trust its writer-recorded URL when it belongs
		// to this page (hash dirs are keyed by URL and can be shared).
		if ( '' !== $newest_file ) {
			$decoded = json_decode( (string) file_get_contents( $newest_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plugin-owned data file.
			if ( is_array( $decoded ) && ! empty( $decoded['url'] )
				&& ( empty( $decoded['page_id'] ) || (int) $decoded['page_id'] === $page_id ) ) {
				return (string) $decoded['url'];
			}
		}

		return $candidate;
	}

	/**
	 * WP-Cron callback: ONE batched tick of the file-first registry rebuild
	 * (Option A). Admin-triggered only — no recurring schedule exists; the
	 * start AJAX handler schedules the first one-off event and this callback
	 * self-reschedules 1-minute continuations while work remains (mirrors
	 * {@see self::run_heatmap_auto_repair()}'s continuation pattern).
	 *
	 * Perf: the tick itself is bounded by the service's ~20 s budget / <=100
	 * dirs per batch; lock contention with backfill/reconcile/repair retries
	 * in 5 minutes instead of stacking scans. A paused pass schedules nothing —
	 * the resume AJAX action re-arms the continuation chain.
	 *
	 * @since 2026-08-15 (registry rebuild from files, Option A)
	 * @return array Tick result from Opti_Behavior_Heatmap_Registry_Rebuild::run_batch() (used by tests).
	 */
	public function run_heatmap_registry_rebuild_tick() {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild' ) ) {
			return array(
				'processed' => 0,
				'inserted'  => 0,
				'complete'  => true,
				'locked'    => false,
				'paused'    => false,
				'aborted'   => false,
			);
		}

		$service = new Opti_Behavior_Heatmap_Registry_Rebuild();
		$result  = $service->run_batch();

		if ( $result['locked'] ) {
			// Backfill/reconcile/repair holds the shared advisory lock: retry
			// the same tick later instead of losing the pass.
			$this->schedule_heatmap_rebuild_continuation( 5 * MINUTE_IN_SECONDS );
			return $result;
		}

		if ( ! $result['complete'] && ! $result['paused'] ) {
			$this->schedule_heatmap_rebuild_continuation( MINUTE_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Schedule a one-off continuation tick of the file-first registry rebuild.
	 * Dedupes against an already-pending event so parallel callers (tick +
	 * AJAX resume) can never stack multiple continuation chains.
	 *
	 * @since 2026-08-15 (registry rebuild from files, Option A)
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	public function schedule_heatmap_rebuild_continuation( $delay ) {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( wp_next_scheduled( self::HEATMAP_REBUILD_CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::HEATMAP_REBUILD_CRON_HOOK );
	}

	/**
	 * One-shot product auto-rebuild migration (zero-touch for customers).
	 *
	 * On the first load (admin_init OR the daily heatmap cron — customers may
	 * never open wp-admin) after upgrading to a build that ships the file-first
	 * registry rebuild, schedule the existing rebuild cron chain automatically,
	 * starting IMMEDIATELY. Not delayed on purpose: daily crons age out
	 * spam-session verdicts (delete_old_data() removes known-spam session rows,
	 * turning their tokens into unknown tokens the rebuild must then treat as
	 * legitimate), so the sooner the pass scans, the more accurate its spam
	 * gate is.
	 *
	 * Guarded by the versioned {@see self::HEATMAP_REBUILD_MIGRATION_OPTION}
	 * flag (autoload off) — set BEFORE scheduling so the migration can never
	 * double-fire, and checked first so subsequent loads cost ONE option read.
	 * An in-flight or already-scheduled pass (e.g. the admin pressed the
	 * Danger Zone button before this ran) is never clobbered: the flag is set
	 * and the existing pass is left alone.
	 *
	 * @since 2026-08-15 (product auto-rebuild)
	 * @return bool True when this call performed the migration (flag was absent).
	 */
	public function maybe_schedule_heatmap_rebuild_migration() {
		if ( '' !== (string) get_option( self::HEATMAP_REBUILD_MIGRATION_OPTION, '' ) ) {
			return false; // Already migrated — one cheap autoload-off option read.
		}
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild' ) ) {
			return false; // Service unavailable (partial deploy) — retry next load.
		}

		update_option(
			self::HEATMAP_REBUILD_MIGRATION_OPTION,
			defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '1',
			false
		);

		// Never clobber a pass the admin already started manually (or a
		// continuation chain already in flight): cursor/progress reset here
		// would lose its work. The flag above still records the migration.
		if ( wp_next_scheduled( self::HEATMAP_REBUILD_CRON_HOOK )
			|| Opti_Behavior_Heatmap_Registry_Rebuild::is_rebuild_active() ) {
			return true;
		}

		$service = new Opti_Behavior_Heatmap_Registry_Rebuild();
		$service->reset();
		$this->schedule_heatmap_rebuild_continuation( 1 );

		return true;
	}

	/**
	 * Weekly self-heal divergence probe (product auto-rebuild trigger 2).
	 *
	 * Piggybacks on the always-scheduled sync-reconcile cron (called at the
	 * top of {@see self::run_heatmap_sync_reconcile_batch()}) — NO new
	 * recurring schedule. Cost on the 15-minute ticks where the weekly window
	 * has not elapsed: ONE autoload-off option read.
	 *
	 * When due, it streams the root hash-dir names of the heatmap data dir
	 * (readdir(), HARD CAP {@see self::HEATMAP_REBUILD_PROBE_DIR_CAP} then
	 * stop — never a full enumeration on huge sites) and compares the count
	 * against a baseline picked by history:
	 *
	 *  - A completed rebuild pass exists (GROWTH baseline,
	 *    {@see Opti_Behavior_Heatmap_Registry_Rebuild::PROBE_BASELINE_OPTION}
	 *    = dir count that pass scanned): re-trigger only when dirs GREW past
	 *    baseline x growth ratio (default 3x, filterable via
	 *    `opti_behavior_heatmap_rebuild_probe_growth_ratio`). Bot-site fix:
	 *    bot-only dirs never earn registry rows, so comparing dirs against
	 *    registry rows would flag bot-heavy sites as divergent every week and
	 *    burn ~2 h of rebuild IO forever; a steady bot-dir surplus is NOT
	 *    divergence — only new, never-scanned dirs are.
	 *  - No completed pass yet (FALLBACK): dirs exceeding registry `COUNT(*)`
	 *    by the divergence ratio (default 3x, filterable via
	 *    `opti_behavior_heatmap_rebuild_divergence_ratio`) means the registry
	 *    lost rows the files still back — first-run self-heal.
	 *
	 * Either way the rebuild is scheduled offset +6 h (filterable via
	 * `opti_behavior_heatmap_rebuild_probe_delay`) so the heavy pass lands
	 * OUTSIDE the busy 3-5 AM daily-cron window.
	 *
	 * The dir cap makes the probe conservative by design: with the count
	 * capped at 500, a baseline (or registry) larger than 500/ratio can never
	 * trigger — exactly right, because such a site is clearly populated/
	 * scanned and the write-path upsert keeps the registry current.
	 *
	 * @since 2026-08-15 (product auto-rebuild)
	 * @return bool True when a rebuild pass was scheduled by this probe.
	 */
	public function maybe_run_heatmap_rebuild_divergence_probe() {
		$interval = (int) apply_filters( 'opti_behavior_heatmap_rebuild_probe_interval', WEEK_IN_SECONDS );
		$last_run = (int) get_option( self::HEATMAP_REBUILD_PROBE_OPTION, 0 );
		if ( ( time() - $last_run ) < max( 1, $interval ) ) {
			return false; // Weekly window not elapsed — one option read, done.
		}
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild' ) ) {
			return false;
		}

		// Claim the weekly slot FIRST so concurrent cron ticks (or a probe that
		// fatals mid-count) cannot re-run the filesystem count back-to-back.
		update_option( self::HEATMAP_REBUILD_PROBE_OPTION, time(), false );

		// A pass in flight or already scheduled makes the probe moot.
		if ( wp_next_scheduled( self::HEATMAP_REBUILD_CRON_HOOK )
			|| Opti_Behavior_Heatmap_Registry_Rebuild::is_rebuild_active() ) {
			return false;
		}

		$dir_count = $this->count_heatmap_root_hash_dirs( self::HEATMAP_REBUILD_PROBE_DIR_CAP );

		$baseline = (int) get_option( Opti_Behavior_Heatmap_Registry_Rebuild::PROBE_BASELINE_OPTION, 0 );
		if ( $baseline > 0 ) {
			// GROWTH-BASED comparison (bot-site fix, 2026-08-15): a completed
			// pass recorded the dir count it scanned. Bot-only dirs never earn
			// registry rows, so a registry-rows comparison here would flag a
			// bot-heavy site as divergent EVERY week and re-run a full rebuild
			// forever. Instead, only real growth since the last completed scan
			// (new dirs the rebuild has never seen) re-triggers.
			$growth_ratio = (float) apply_filters( 'opti_behavior_heatmap_rebuild_probe_growth_ratio', 3.0 );
			if ( $growth_ratio <= 0 ) {
				$growth_ratio = 3.0;
			}

			if ( $dir_count < ( $baseline * $growth_ratio ) ) {
				return false; // No meaningful growth since the last completed scan.
			}
		} else {
			// FALLBACK (no completed pass yet): registry-rows comparison, so
			// first-run self-heal on a gutted registry still works.
			global $wpdb;
			$registry_table = $wpdb->prefix . 'optibehavior_heatmap_pages';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence guard; cron only.
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $registry_table ) ) ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed plugin table from $wpdb->prefix; no user input.
			$registry_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$registry_table}" );

			$ratio = (float) apply_filters( 'opti_behavior_heatmap_rebuild_divergence_ratio', 3.0 );
			if ( $ratio <= 0 ) {
				$ratio = 3.0;
			}

			if ( $dir_count < ( max( 1, $registry_rows ) * $ratio ) ) {
				return false; // No large divergence — nothing to heal this week.
			}
		}

		$service = new Opti_Behavior_Heatmap_Registry_Rebuild();
		$service->reset();

		$delay = (int) apply_filters( 'opti_behavior_heatmap_rebuild_probe_delay', 6 * HOUR_IN_SECONDS );
		$this->schedule_heatmap_rebuild_continuation( max( 1, $delay ) );

		return true;
	}

	/**
	 * Count root url_hash dirs of the heatmap data dir, stopping at a hard cap.
	 *
	 * Single readdir() stream over hash-dir NAMES only — never recursive,
	 * never a glob; at most `cap` is_dir() stats. Honors the same
	 * `opti_behavior_heatmap_rebuild_base_dir` filter as the rebuild service
	 * so tests can point the probe at a fixture root.
	 *
	 * @since 2026-08-15 (product auto-rebuild)
	 * @param int $cap Stop counting when reached.
	 * @return int Number of root hash dirs found (<= cap).
	 */
	private function count_heatmap_root_hash_dirs( $cap ) {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			return 0;
		}

		$base = trailingslashit( Opti_Behavior_Heatmap_Storage::get_instance()->get_base_dir() );
		$base = trailingslashit( (string) apply_filters( 'opti_behavior_heatmap_rebuild_base_dir', $base ) );

		$count = 0;
		if ( ! is_dir( $base ) ) {
			return $count;
		}
		$handle = opendir( $base );
		if ( false === $handle ) {
			return $count;
		}
		while ( false !== ( $entry = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( ! preg_match( '/^[a-f0-9]{32}$/', $entry ) ) {
				continue; // Skips '.', '..', 'recordings', '_orphaned', strays.
			}
			if ( is_dir( $base . $entry ) ) {
				++$count;
				if ( $count >= max( 1, (int) $cap ) ) {
					break; // Hard cap — plenty of signal, stop stat-ing.
				}
			}
		}
		closedir( $handle );

		return $count;
	}

	/**
	 * Discreet admin status for the auto-triggered rebuild: a small notice on
	 * the plugin's own admin pages while a pass is running ("rebuild in
	 * progress") and for 24 h after it finishes ("done"), driven entirely by
	 * the EXISTING progress option — one autoload-off option read, plugin
	 * pages only, never any filesystem or table access.
	 *
	 * @since 2026-08-15 (product auto-rebuild)
	 * @return void
	 */
	public function maybe_render_heatmap_rebuild_status_notice() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page gate for a status notice.
		if ( 0 !== strpos( $page, 'opti-behavior' ) ) {
			return; // Plugin admin pages only — zero cost anywhere else.
		}
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild' ) ) {
			return;
		}

		$progress = get_option( Opti_Behavior_Heatmap_Registry_Rebuild::PROGRESS_OPTION, array() );
		if ( ! is_array( $progress ) || empty( $progress['state'] ) ) {
			return;
		}

		$state   = (string) $progress['state'];
		$updated = isset( $progress['updated_at'] ) ? strtotime( (string) $progress['updated_at'] ) : false;
		$age     = ( false !== $updated ) ? ( current_time( 'timestamp' ) - $updated ) : PHP_INT_MAX;

		if ( in_array( $state, array( 'pending', 'running' ), true )
			&& Opti_Behavior_Heatmap_Registry_Rebuild::is_rebuild_active() ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: directories scanned so far, 2: total directories, 3: pages restored so far. */
						__( 'Opti Behavior: heatmap registry rebuild in progress (%1$d of %2$d directories scanned, %3$d pages restored). Heatmap listings grow as it completes.', 'opti-behavior' ),
						(int) ( $progress['scanned'] ?? 0 ),
						(int) ( $progress['total_dirs'] ?? 0 ),
						(int) ( $progress['inserted'] ?? 0 )
					)
				)
			);
			return;
		}

		if ( 'complete' === $state && $age <= DAY_IN_SECONDS && (int) ( $progress['inserted'] ?? 0 ) > 0 ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of heatmap pages restored by the rebuild. */
						__( 'Opti Behavior: heatmap registry rebuild done — %d pages restored from files.', 'opti-behavior' ),
						(int) $progress['inserted']
					)
				)
			);
		}
	}

	/**
	 * WP-Cron callback: ONE batched, REPORT-ONLY tick of the `_orphaned/`
	 * archive dry-run scan (Option C phase 1). Admin-triggered only — no
	 * recurring schedule exists; the start AJAX handler schedules the first
	 * one-off event and this callback self-reschedules 1-minute continuations
	 * while work remains (same shape as the registry rebuild tick above).
	 *
	 * The pass never writes to any table and never moves/restores any file.
	 *
	 * @since 2026-08-15 (`_orphaned/` dry-run report, Option C phase 1)
	 * @return array Tick result from Opti_Behavior_Heatmap_Orphan_Report::run_batch() (used by tests).
	 */
	public function run_heatmap_orphan_report_tick() {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Orphan_Report' ) ) {
			return array(
				'processed' => 0,
				'eligible'  => 0,
				'complete'  => true,
				'locked'    => false,
				'paused'    => false,
				'aborted'   => false,
			);
		}

		$service = new Opti_Behavior_Heatmap_Orphan_Report();
		$result  = $service->run_batch();

		if ( $result['locked'] ) {
			// Backfill/reconcile/repair/rebuild holds the shared advisory lock:
			// retry the same tick later instead of losing the pass.
			$this->schedule_heatmap_orphan_report_continuation( 5 * MINUTE_IN_SECONDS );
			return $result;
		}

		if ( ! $result['complete'] && ! $result['paused'] ) {
			$this->schedule_heatmap_orphan_report_continuation( MINUTE_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Schedule a one-off continuation tick of the `_orphaned/` dry-run report.
	 * Dedupes against an already-pending event so parallel callers (tick +
	 * AJAX resume) can never stack multiple continuation chains.
	 *
	 * @since 2026-08-15 (`_orphaned/` dry-run report, Option C phase 1)
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	public function schedule_heatmap_orphan_report_continuation( $delay ) {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( wp_next_scheduled( self::HEATMAP_ORPHAN_REPORT_CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::HEATMAP_ORPHAN_REPORT_CRON_HOOK );
	}

	/**
	 * WP-Cron callback: ONE batched tick of the `_orphaned/` retention purge.
	 * Fired by the RECURRING daily event ({@see self::ensure_heatmap_orphan_purge_cron()})
	 * and self-reschedules 1-minute continuations while the day's eligible
	 * set exceeds one tick budget (same continuation shape as the rebuild).
	 *
	 * Perf: bounded by the service's ~20 s budget / <=100 dirs per batch;
	 * lock contention with the other maintenance passes retries in 5 minutes
	 * instead of stacking scans.
	 *
	 * @since 2026-08-15 (`_orphaned/` retention purge)
	 * @return array Tick result from Opti_Behavior_Heatmap_Orphan_Purge::run_batch() (used by tests).
	 */
	public function run_heatmap_orphan_purge_tick() {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Orphan_Purge' ) ) {
			return array(
				'processed' => 0,
				'deleted'   => 0,
				'kept'      => 0,
				'complete'  => true,
				'locked'    => false,
				'disabled'  => false,
			);
		}

		$service = new Opti_Behavior_Heatmap_Orphan_Purge();
		$result  = $service->run_batch();

		if ( $result['locked'] ) {
			// Backfill/reconcile/repair/rebuild/report holds the shared
			// advisory lock: retry the same tick later instead of losing
			// today's purge pass.
			$this->schedule_heatmap_orphan_purge_continuation( 5 * MINUTE_IN_SECONDS );
			return $result;
		}

		if ( ! $result['complete'] ) {
			$this->schedule_heatmap_orphan_purge_continuation( MINUTE_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Schedule a one-off continuation tick of the `_orphaned/` retention
	 * purge. Dedupes against an already-pending one-off event; the recurring
	 * daily event is a different schedule entry and is left alone.
	 *
	 * @since 2026-08-15 (`_orphaned/` retention purge)
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	public function schedule_heatmap_orphan_purge_continuation( $delay ) {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( wp_next_scheduled( self::HEATMAP_ORPHAN_PURGE_CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::HEATMAP_ORPHAN_PURGE_CRON_HOOK );
	}

	/**
	 * Self-heal the recurring daily `_orphaned/` retention purge cron event:
	 * schedule (daily) while the retention window is non-zero, clear it when
	 * the admin disabled the purge (retention = 0 days). Safe to call on
	 * every admin_init and from the settings save handler (mirrors
	 * {@see self::ensure_heatmap_auto_repair_cron()}).
	 *
	 * @since 2026-08-15 (`_orphaned/` retention purge)
	 * @return void
	 */
	public function ensure_heatmap_orphan_purge_cron() {
		if ( ! class_exists( 'Opti_Behavior_Heatmap_Orphan_Purge' ) ) {
			return;
		}

		$hook       = self::HEATMAP_ORPHAN_PURGE_CRON_HOOK;
		$recurrence = 'daily';

		$service = new Opti_Behavior_Heatmap_Orphan_Purge();
		if ( $service->get_retention_days() <= 0 ) {
			wp_clear_scheduled_hook( $hook );
			return;
		}

		$next     = wp_next_scheduled( $hook );
		$schedule = $next ? wp_get_schedule( $hook ) : false;

		if ( $next && false === $schedule ) {
			// A one-off continuation event exists but the recurring daily event
			// does not; leave the continuation alone and add the recurrence.
			$next = false;
		} elseif ( $next && $recurrence !== $schedule ) {
			wp_clear_scheduled_hook( $hook );
			$next = false;
		}

		if ( ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, $hook );
		}
	}

	/**
	 * Shared repair implementation (Option A — recompute-only) used by BOTH the
	 * manual Repair AJAX handler ({@see Opti_Behavior_Ajax_Handlers_Trait::ajax_repair_heatmap_sync()})
	 * and the daily scheduled auto-repair cron ({@see self::run_heatmap_auto_repair()}):
	 *
	 *  1. Recompute the DB/file sync-status entries for the given pages and
	 *     merge them into the {@see self::HEATMAP_SYNC_TRANSIENT} cache.
	 *  2. Invalidate the agg_* aggregate columns ONLY for pages whose
	 *     recomputed entry actually CHANGED versus the previously cached one
	 *     (or whose fresh computation flags a desync when no cached entry
	 *     exists to diff against) so the next server render agrees with the
	 *     just-repaired numbers (repair durability).
	 *
	 * The changed-only invalidation is deliberate (live-prod incident,
	 * 2026-08-16): the daily auto-repair cron walks ALL mapped pages, and the
	 * previous unconditional invalidation NULLed agg_synced_at for the entire
	 * pages table every pass (10k+ rows), racing the resync worker and
	 * collapsing the visible Heatmaps list (1,998 -> 487 observed). Untouched
	 * pages now stay synced; a manual Repair after a real incident still
	 * refreshes every page whose counts differ.
	 *
	 * Never deletes JSON heatmap files or DB analytics rows. Callers must hold
	 * the {@see self::HEATMAP_SYNC_LOCK_NAME} advisory lock.
	 *
	 * @since 1.8.x
	 * @param int[] $page_ids Page IDs to repair.
	 * @return array<int,array> Freshly computed page-keyed sync-status entries.
	 */
	public function repair_heatmap_sync_for_pages( array $page_ids ) {
		$page_ids = array_values( array_filter( array_map( 'absint', $page_ids ) ) );
		if ( empty( $page_ids ) ) {
			return array();
		}

		// Snapshot the pre-repair cache BEFORE merging, so we can diff each
		// fresh entry against what the plugin previously believed.
		$previous = get_transient( self::HEATMAP_SYNC_TRANSIENT );
		if ( ! is_array( $previous ) ) {
			$previous = array();
		}

		$entries = $this->compute_heatmap_sync_status_for_pages( $page_ids );
		$this->merge_heatmap_sync_status_into_cache( $entries );

		$changed = array();
		foreach ( $entries as $page_id => $entry ) {
			$cached = isset( $previous[ $page_id ] ) && is_array( $previous[ $page_id ] ) ? $previous[ $page_id ] : null;
			if ( $this->heatmap_sync_entry_changed( $entry, $cached ) ) {
				$changed[] = (int) $page_id;
			}
		}

		if ( ! empty( $changed ) ) {
			$this->invalidate_heatmap_aggregates_for_pages( $changed );
		}

		return $entries;
	}

	/**
	 * Decide whether a freshly recomputed sync-status entry differs from the
	 * previously cached one enough to warrant invalidating the page's agg_*
	 * aggregate columns ({@see self::repair_heatmap_sync_for_pages()}).
	 *
	 * Rules:
	 *  - No cached entry (transient expired / first run): we cannot diff, so
	 *    only invalidate when the fresh computation itself flags a desync
	 *    (file counts disagree with DB counts) — a healthy page with no cache
	 *    entry stays synced instead of being blanket-invalidated.
	 *  - Cached entry present: compare every count/flag field EXCEPT the
	 *    checked_at heartbeat. Fields missing from the cached entry (older
	 *    plugin generation wrote it) are skipped rather than treated as a
	 *    change, so a version upgrade does not trigger one final blanket pass.
	 *
	 * @since 1.9.x
	 * @param array      $entry  Freshly computed entry.
	 * @param array|null $cached Previously cached entry for the same page, if any.
	 * @return bool True when the page's aggregates should be invalidated.
	 */
	private function heatmap_sync_entry_changed( array $entry, $cached ) {
		if ( ! is_array( $cached ) ) {
			return ! empty( $entry['desynced'] ) || true === ( isset( $entry['desynced_all'] ) ? $entry['desynced_all'] : null );
		}

		$compare_keys = array(
			'db_total',
			'file_total',
			'file_total_all',
			'db_total_all',
			'orphan_total_all',
			'detected_total_all',
			'file_devices',
			'file_devices_all',
			'desynced',
			'desynced_all',
		);

		foreach ( $compare_keys as $key ) {
			if ( ! array_key_exists( $key, $cached ) || ! array_key_exists( $key, $entry ) ) {
				// Additive field the older cached generation never wrote (or a
				// future generation removed): not diffable, skip.
				continue;
			}

			$new_value = $entry[ $key ];
			$old_value = $cached[ $key ];

			if ( is_array( $new_value ) || is_array( $old_value ) ) {
				// Device splits: key-insensitive order, values numeric.
				if ( ! is_array( $new_value ) || ! is_array( $old_value ) ) {
					return true;
				}
				if ( array_map( 'intval', $new_value ) != array_map( 'intval', $old_value ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Order-insensitive assoc compare.
					return true;
				}
				continue;
			}

			// Scalars: null is significant ("unknown", e.g. Free-only installs)
			// and must not loosely equal 0; numeric values compare as ints.
			if ( is_null( $new_value ) !== is_null( $old_value ) ) {
				return true;
			}
			if ( null === $new_value ) {
				continue;
			}
			if ( is_bool( $new_value ) || is_bool( $old_value ) ) {
				if ( (bool) $new_value !== (bool) $old_value ) {
					return true;
				}
				continue;
			}
			if ( (int) $new_value !== (int) $old_value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * WP-Cron callback: daily scheduled heatmap sync auto-repair (Danger Zone
	 * toggle, default ON).
	 *
	 * Runs the global repair pass over ALL mapped pages in chunked,
	 * cursor-resumable batches via {@see self::repair_heatmap_sync_for_pages()}
	 * — the exact implementation behind the manual Repair button — guarded by
	 * the shared advisory lock so it never overlaps a reconcile tick or a
	 * concurrent manual Repair. When the wall-time budget is exhausted before
	 * the page list is drained, a one-off continuation event resumes from the
	 * persisted cursor a minute later.
	 *
	 * @since 1.8.x
	 * @return array{processed:int,complete:bool} Run result (also used by tests).
	 */
	public function run_heatmap_auto_repair() {
		global $wpdb;

		$result = array(
			'processed' => 0,
			'complete'  => false,
		);

		if ( ! $this->is_heatmap_auto_repair_enabled() ) {
			// Toggle switched off after the event fired (or a stale one-off
			// continuation): do nothing, and self-heal the schedule away.
			$this->ensure_heatmap_auto_repair_cron();
			return $result;
		}

		// Back-to-back pass guard (live-prod incident, 2026-08-16): after a
		// pass completed (cursor reset to 0), stale queued one-off continuation
		// events immediately started ANOTHER full pass — passes chained
		// endlessly. A FRESH pass (cursor == 0) is skipped while the previous
		// completion is younger than the min interval; mid-pass continuations
		// (cursor > 0) always run so a budget-split pass still drains.
		$pending_cursor = (int) get_option( self::HEATMAP_AUTO_REPAIR_CURSOR_OPTION, 0 );
		if ( 0 === $pending_cursor ) {
			$last_complete = (int) get_option( self::HEATMAP_AUTO_REPAIR_LAST_COMPLETE_OPTION, 0 );
			$min_interval  = (int) apply_filters( 'opti_behavior_heatmap_auto_repair_min_interval', HOUR_IN_SECONDS );
			if ( $last_complete > 0 && ( time() - $last_complete ) < $min_interval ) {
				$result['complete'] = true;
				return $result;
			}
		}

		$got_lock = $this->acquire_heatmap_compute_lock( self::HEATMAP_SYNC_LOCK_NAME, 5 );
		if ( ! $got_lock ) {
			// A reconcile tick or manual Repair is already scanning; retry the
			// whole pass shortly instead of skipping today's repair.
			$this->schedule_heatmap_auto_repair_continuation( 5 * MINUTE_IN_SECONDS );
			return $result;
		}

		try {
			$table = $wpdb->prefix . 'optibehavior_heatmap_pages';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check; runs on cron, not the request hot path.
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				$result['complete'] = true;
				return $result;
			}

			$budget = (float) apply_filters( 'opti_behavior_heatmap_auto_repair_time_budget', 20.0 );
			$start  = microtime( true );
			$cursor = (int) get_option( self::HEATMAP_AUTO_REPAIR_CURSOR_OPTION, 0 );

			while ( true ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; values prepared.
				$page_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT page_id FROM {$table} WHERE page_id > %d ORDER BY page_id ASC LIMIT %d",
						$cursor,
						self::HEATMAP_SYNC_BATCH_SIZE
					)
				);
				$page_ids = array_values( array_filter( array_map( 'absint', (array) $page_ids ) ) );

				if ( empty( $page_ids ) ) {
					// Full pass complete: reset the cursor for the next daily
					// run, stamp the completion (fresh-pass guard) and drop any
					// stale queued one-off continuations so they cannot chain a
					// new full pass right behind this one.
					update_option( self::HEATMAP_AUTO_REPAIR_CURSOR_OPTION, 0, false );
					update_option( self::HEATMAP_AUTO_REPAIR_LAST_COMPLETE_OPTION, time(), false );
					$this->clear_heatmap_auto_repair_continuations();
					$result['complete'] = true;
					break;
				}

				$this->repair_heatmap_sync_for_pages( $page_ids );

				$cursor = max( $page_ids );
				update_option( self::HEATMAP_AUTO_REPAIR_CURSOR_OPTION, $cursor, false );
				$result['processed'] += count( $page_ids );

				if ( ( microtime( true ) - $start ) >= $budget ) {
					break;
				}
			}
		} finally {
			$this->release_heatmap_compute_lock( self::HEATMAP_SYNC_LOCK_NAME, $got_lock );
		}

		if ( ! $result['complete'] ) {
			$this->schedule_heatmap_auto_repair_continuation( MINUTE_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Schedule a one-off continuation of the auto-repair pass (budget exhausted
	 * or lock contention). Same hook as the daily recurrence, so the callback
	 * and toggle gating are identical.
	 *
	 * @since 1.8.x
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	private function schedule_heatmap_auto_repair_continuation( $delay ) {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::HEATMAP_AUTO_REPAIR_CRON_HOOK );
	}

	/**
	 * Unschedule any pending ONE-OFF continuation events on the auto-repair
	 * hook, leaving the recurring daily event untouched. Called when a full
	 * pass completes so stale queued continuations (budget-split or
	 * lock-contention retries queued earlier in the pass) cannot immediately
	 * chain a brand-new full pass (live-prod incident, 2026-08-16). The
	 * fresh-pass timestamp guard in {@see self::run_heatmap_auto_repair()} is
	 * the belt to this suspender: even a continuation this cannot see (e.g.
	 * scheduled between the scan and now) fires as a cheap no-op.
	 *
	 * @since 1.9.x
	 * @return void
	 */
	private function clear_heatmap_auto_repair_continuations() {
		if ( ! function_exists( 'wp_get_scheduled_event' ) || ! function_exists( 'wp_unschedule_event' ) ) {
			return;
		}

		// wp_get_scheduled_event() returns the EARLIEST event for the hook; a
		// one-off has schedule === false, the daily recurrence a schedule
		// string. Pop one-offs until the earliest remaining event is the
		// recurrence (or none). Bounded: continuations are queued at most once
		// per run, so a handful of iterations at worst.
		for ( $i = 0; $i < 10; $i++ ) {
			$event = wp_get_scheduled_event( self::HEATMAP_AUTO_REPAIR_CRON_HOOK );
			if ( ! $event || ! empty( $event->schedule ) ) {
				break;
			}
			wp_unschedule_event( $event->timestamp, self::HEATMAP_AUTO_REPAIR_CRON_HOOK );
		}
	}

	/**
	 * Run one chunk of the background sync-status reconciliation pass (spec §3b).
	 *
	 * Advances a resumable cursor over distinct page_ids known to
	 * `optibehavior_heatmap_pages`, ordered by page_id and wrapping back to the
	 * start once the end is reached so every page's cache entry is periodically
	 * refreshed (never permanently stale). Recomputes DB total / file total /
	 * desynced for one small batch and merges the result into the
	 * {@see self::HEATMAP_SYNC_TRANSIENT} cache.
	 *
	 * Guarded by the shared advisory lock (short timeout) so an overlapping
	 * cron tick, or a concurrent manual Repair, never scans the filesystem
	 * twice at once — the run is simply skipped and retried on the next tick.
	 *
	 * @since 1.8.x
	 * @return array{processed:int,cursor:int,wrapped:bool} Batch result (also used by tests).
	 */
	public function run_heatmap_sync_reconcile_batch() {
		global $wpdb;

		// Weekly self-heal divergence probe (product auto-rebuild, 2026-08-15):
		// piggybacks on this always-scheduled cron so no new recurring event is
		// needed. Costs ONE autoload-off option read on ticks where the weekly
		// window has not elapsed; runs BEFORE the lock because it only counts
		// dir names (capped) and schedules — it never scans file contents.
		$this->maybe_run_heatmap_rebuild_divergence_probe();

		$result = array( 'processed' => 0, 'cursor' => (int) get_option( self::HEATMAP_SYNC_CURSOR_OPTION, 0 ), 'wrapped' => false );

		$got_lock = $this->acquire_heatmap_compute_lock( self::HEATMAP_SYNC_LOCK_NAME, 5 );
		if ( ! $got_lock ) {
			// Another run (cron tick or manual Repair) is already scanning;
			// skip this tick, the recurring cron will retry shortly.
			return $result;
		}

		try {
			$table = $wpdb->prefix . 'optibehavior_heatmap_pages';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety check; runs on cron, not the request hot path.
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return $result;
			}

			$cursor = (int) get_option( self::HEATMAP_SYNC_CURSOR_OPTION, 0 );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; values prepared.
			$page_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT page_id FROM {$table} WHERE page_id > %d ORDER BY page_id ASC LIMIT %d",
					$cursor,
					self::HEATMAP_SYNC_BATCH_SIZE
				)
			);
			$page_ids = array_values( array_filter( array_map( 'absint', (array) $page_ids ) ) );

			$wrapped = false;
			if ( empty( $page_ids ) && $cursor > 0 ) {
				// Reached the end of the page list: wrap back to the start so
				// every page is periodically re-checked instead of the cache
				// going stale forever once the initial full pass completes.
				$wrapped = true;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; value prepared.
				$page_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT page_id FROM {$table} ORDER BY page_id ASC LIMIT %d",
						self::HEATMAP_SYNC_BATCH_SIZE
					)
				);
				$page_ids = array_values( array_filter( array_map( 'absint', (array) $page_ids ) ) );
			}

			if ( empty( $page_ids ) ) {
				// No heatmap pages at all yet; reset the cursor and try again
				// next tick once data exists.
				update_option( self::HEATMAP_SYNC_CURSOR_OPTION, 0, false );
				return $result;
			}

			$this->merge_heatmap_sync_status_into_cache( $this->compute_heatmap_sync_status_for_pages( $page_ids ) );

			// Non-destructive detection of stale duplicate mapping rows
			// (spec §3.4, finding 4): log only, never delete/merge — 3.1's
			// multi-dir resolution already makes sure a stale row can never
			// shadow the real one for scanning purposes.
			$this->log_stale_heatmap_mapping_rows( $page_ids );

			$new_cursor = max( $page_ids );
			update_option( self::HEATMAP_SYNC_CURSOR_OPTION, $new_cursor, false );

			$result = array(
				'processed' => count( $page_ids ),
				'cursor'    => $new_cursor,
				'wrapped'   => $wrapped,
			);
		} finally {
			$this->release_heatmap_compute_lock( self::HEATMAP_SYNC_LOCK_NAME, $got_lock );
		}

		return $result;
	}

	/**
	 * Detect stale duplicate `optibehavior_heatmap_pages` mapping rows for a
	 * set of pages and log them (spec §3.4, finding 4) — NEVER deletes or
	 * merges rows/files (non-destructive policy, user-approved decision).
	 *
	 * A page with more than one mapping row (e.g. a dead QA URL hash left
	 * alongside the real one, the live page_id=3 repro) is not itself a bug —
	 * 3.1's multi-dir resolution (get_url_hashes_for_page()) already scans
	 * every row's directory, so a stale row can no longer shadow the real one
	 * for counting/rendering. This only surfaces the situation in the debug
	 * log so an admin can investigate/clean up manually; a future merge tool
	 * is out of scope.
	 *
	 * "Stale" = a mapping row whose url_hash matches none of the page's
	 * current live URL hash variants (raw / trailing-slash-stripped /
	 * trailing-slash-appended md5, same 3 forms get_url_hashes_for_page()
	 * derives) — i.e. it was written for a URL the page no longer has.
	 *
	 * De-duped via a transient of already-logged row ids so a recurring
	 * cron tick does not re-log the same stale row every batch.
	 *
	 * @since 1.9.x
	 * @param array $page_ids Page IDs to check (the current reconcile batch).
	 * @return void
	 */
	private function log_stale_heatmap_mapping_rows( array $page_ids ) {
		global $wpdb;

		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return;
		}

		$table        = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; ids prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, page_id, url_hash, url FROM {$table} WHERE page_id IN ({$placeholders}) ORDER BY page_id ASC, id ASC",
				$page_ids
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return;
		}

		$by_page = array();
		foreach ( (array) $rows as $row ) {
			$by_page[ (int) $row['page_id'] ][] = $row;
		}
		// Pages with a single mapping row have nothing to detect.
		$by_page = array_filter( $by_page, function ( $page_rows ) {
			return count( $page_rows ) > 1;
		} );
		if ( empty( $by_page ) ) {
			return;
		}

		$logged_key    = 'opti_behavior_heatmap_stale_rows_logged';
		$already_logged = get_transient( $logged_key );
		if ( ! is_array( $already_logged ) ) {
			$already_logged = array();
		}

		$pages_table   = $wpdb->prefix . 'optibehavior_pages';
		$debug_manager = null;
		$newly_logged  = array();

		foreach ( $by_page as $pid => $page_rows ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; id prepared.
			$current_url = $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$pages_table} WHERE id = %d", $pid ) );
			if ( ! $current_url ) {
				continue;
			}
			$live_hashes = array( md5( $current_url ), md5( rtrim( $current_url, '/' ) ), md5( $current_url . '/' ) );

			foreach ( $page_rows as $page_row ) {
				if ( in_array( $page_row['url_hash'], $live_hashes, true ) ) {
					continue; // Matches the page's current URL: not stale.
				}
				$row_id = (int) $page_row['id'];
				if ( isset( $already_logged[ $row_id ] ) ) {
					continue; // Already logged recently; avoid log spam on every cron tick.
				}

				if ( null === $debug_manager ) {
					$debug_manager = $this->heatmap->get_debug_manager();
				}
				$debug_manager->log(
					sprintf(
						'Stale heatmap mapping row (spec finding 4): page_id=%d mapping_row_id=%d url_hash=%s dir_url=%s does not match the page\'s current URL (%s). Not deleted (non-destructive policy) — its directory is still scanned via get_url_hashes_for_page().',
						$pid,
						$row_id,
						$page_row['url_hash'],
						$page_row['url'],
						$current_url
					),
					'warning',
					'heatmap-sync'
				);
				$newly_logged[ $row_id ] = true;
			}
		}

		if ( ! empty( $newly_logged ) ) {
			set_transient( $logged_key, array_merge( $already_logged, $newly_logged ), DAY_IN_SECONDS );
		}
	}

	/**
	 * Distinct page_ids known to have heatmap data, for the manual global
	 * Repair AJAX handler. Ordered by page_id for simple, deterministic
	 * pagination across repeated calls.
	 *
	 * @since 1.8.x
	 * @param int $limit Max rows to fetch.
	 * @return int[]
	 */
	private function get_all_heatmap_page_ids_for_repair( $limit ) {
		global $wpdb;

		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence guard.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; limit prepared.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT page_id FROM {$table} ORDER BY page_id ASC LIMIT %d", max( 1, (int) $limit ) ) );

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Merge freshly computed per-page sync-status entries into the shared
	 * background reconciliation cache, preserving entries for pages not in
	 * this batch (spec §3b data model).
	 *
	 * @since 1.8.x
	 * @param array<int,array{db_total:int|null,file_total:int,desynced:bool,checked_at:int}> $entries Page-keyed entries.
	 * @return array The full merged map that was persisted.
	 */
	private function merge_heatmap_sync_status_into_cache( array $entries ) {
		$map = get_transient( self::HEATMAP_SYNC_TRANSIENT );
		if ( ! is_array( $map ) ) {
			$map = array();
		}

		if ( empty( $entries ) ) {
			return $map;
		}

		foreach ( $entries as $page_id => $entry ) {
			$map[ $page_id ] = $entry;
		}

		$ttl = (int) apply_filters( 'opti_behavior_heatmap_sync_status_ttl', 6 * HOUR_IN_SECONDS );
		set_transient( self::HEATMAP_SYNC_TRANSIENT, $map, max( MINUTE_IN_SECONDS, $ttl ) );

		return $map;
	}

	/**
	 * Read a page's cached file-derived per-device session split from the
	 * reconciliation cache (the 'file_devices' key added in 1.9.0).
	 *
	 * Returns null when the cache has no entry for the page OR the entry
	 * predates the per-device split (pre-1.9.0 shape) — callers must treat
	 * that as "not derivable" and keep their existing values (fail open).
	 *
	 * @since 1.9.0
	 * @param int $page_id Page ID.
	 * @return array{desktop:int,mobile:int,tablet:int}|null
	 */
	private function get_cached_heatmap_sync_file_devices( $page_id ) {
		$map = get_transient( self::HEATMAP_SYNC_TRANSIENT );
		if ( ! is_array( $map ) || ! isset( $map[ $page_id ]['file_devices'] ) || ! is_array( $map[ $page_id ]['file_devices'] ) ) {
			return null;
		}

		$devices = $map[ $page_id ]['file_devices'];

		return array(
			'desktop' => isset( $devices['desktop'] ) ? (int) $devices['desktop'] : 0,
			'mobile'  => isset( $devices['mobile'] ) ? (int) $devices['mobile'] : 0,
			'tablet'  => isset( $devices['tablet'] ) ? (int) $devices['tablet'] : 0,
		);
	}

	/**
	 * Read a page's cached ALL-VISITORS, orphan-aware per-device session
	 * split from the reconciliation cache (the 'file_devices_all' key,
	 * list-device-button fix). Unlike {@see get_cached_heatmap_sync_file_devices()}
	 * (guest-scoped), this partitions the SAME universe as 'file_total_all' /
	 * 'detected_total_all' — sum( result ) == the cached file_total_all — so
	 * it is the correct source for list-row Desktop/Mobile buttons, which
	 * must sum to the row's session cell (also sourced from that pair).
	 *
	 * Returns null when the cache has no entry for the page OR the entry
	 * predates this field — callers must fail open and keep their existing
	 * (legacy DB-only) values.
	 *
	 * @since 1.9.x
	 * @param int $page_id Page ID.
	 * @return array{desktop:int,mobile:int,tablet:int}|null
	 */
	private function get_cached_heatmap_sync_file_devices_all( $page_id ) {
		$map = get_transient( self::HEATMAP_SYNC_TRANSIENT );
		if ( ! is_array( $map ) || ! isset( $map[ $page_id ]['file_devices_all'] ) || ! is_array( $map[ $page_id ]['file_devices_all'] ) ) {
			return null;
		}

		$devices = $map[ $page_id ]['file_devices_all'];

		return array(
			'desktop' => isset( $devices['desktop'] ) ? (int) $devices['desktop'] : 0,
			'mobile'  => isset( $devices['mobile'] ) ? (int) $devices['mobile'] : 0,
			'tablet'  => isset( $devices['tablet'] ) ? (int) $devices['tablet'] : 0,
		);
	}

	/**
	 * Bridge (Free -> Pro): all-visitors file-derived session count for one
	 * page, for the detail header's event-coverage pill ("X / Y sessions"
	 * where X = sessions with recorded heatmap events across ALL visitor
	 * types).
	 *
	 * Reads the reconciliation cache first; when the entry is missing or
	 * predates the 'file_total_all' field, computes it for this single page
	 * (same scoped scan the per-page Repair runs) and merges it into the
	 * cache so subsequent detail-page loads are cache hits. Called from Pro's
	 * ajax_get_total_recordings() via the Core->get_dashboard() accessor with
	 * method_exists() guards, so older Free versions degrade to a hidden
	 * first number instead of fataling.
	 *
	 * @since 1.9.x
	 * @param int $page_id Page ID.
	 * @return int|null All-visitors deduped file session count, or null when
	 *                  it cannot be computed (invalid page).
	 */
	public function get_heatmap_all_visitors_file_total_bridge( $page_id ) {
		$pair = $this->get_heatmap_session_coverage_bridge( $page_id );

		return null === $pair ? null : $pair['file_total_all'];
	}

	/**
	 * Bridge (Free -> Pro): the honest session-coverage pair for one page
	 * (spec §3.3, RC-D) — X = 'file_total_all' (sessions with heatmap event
	 * data, all visitor types, same as the older single-int bridge) and Y =
	 * 'detected_total_all' (DB sessions UNION orphan file sessions — the
	 * TRUE "detected sessions" total, always >= file_total_all on a fresh
	 * entry). Supersedes {@see get_heatmap_all_visitors_file_total_bridge()}
	 * for consumers that need Y too (the detail pill, Repair payload); that
	 * older bridge now delegates here and is kept only for BC with any Pro
	 * build that still calls it directly.
	 *
	 * Same cache-first, compute-on-miss + merge behavior: reads the
	 * reconciliation cache, and when the entry is missing or predates
	 * 'file_total_all', computes + caches it for this single page (the same
	 * scoped scan the per-page Repair runs).
	 *
	 * @since 1.9.x
	 * @param int $page_id Page ID.
	 * @return array{file_total_all:int,detected_total_all:int|null}|null Null
	 *                    when the page cannot be resolved at all;
	 *                    'detected_total_all' is null when it is not yet
	 *                    computable for this page (e.g. Free-only install,
	 *                    no Pro DB-total helper available).
	 */
	public function get_heatmap_session_coverage_bridge( $page_ids, $period = 'all', $start_date = '', $end_date = '' ) {
		// Accepts a single page_id (BC) OR the canonical page_ids group of a
		// multi-variant page. The count is resolved from the page's CONTENT
		// group (post_id / URL), so a partial URL page_ids list still yields the
		// full-page number.
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $ids ) ) {
			return null;
		}

		// CANONICAL SESSION UNIVERSE (master decision 2026-08-13, supersedes the
		// earlier "heatmap surfaces keep their own agg_sessions file universe"
		// decision): the detail header pill now shows the SAME canonical human
		// pageview-session count (optibehavior_pageviews distinct session_id,
		// spam-excluded) as the frontend stats bar, the post-edit metabox, and
		// the dashboard — NOT the heatmap-file agg_sessions. This kills the
		// two-universe display the user rejected ("customer will think the
		// difference is a bug": pill 5 vs bar/metabox 45 for the same homepage).
		//
		// Returned as the coverage pair Pro's ajax_get_total_recordings()
		// consumes: 'detected_total_all' is the headline number Pro renders as
		// the pill total; 'file_total_all' is left null so Pro hides the leading
		// "X /" coverage number (isset() === false) and the pill reads a single
		// "(45 sessions)". agg_sessions / reconciliation totals stay in code for
		// internal sync-health/Repair only — no longer a user-facing headline.
		$sessions = $this->get_canonical_pageview_sessions_for_group( $ids, $period, $start_date, $end_date );
		if ( null === $sessions ) {
			return null;
		}

		$pair = array(
			// Null on purpose: hides the coverage "X /" number on the pill (Pro
			// checks isset()), collapsing it to one canonical session total.
			'file_total_all'     => null,
			'detected_total_all' => (int) $sessions,
		);

		// "Delete Heatmap Data" dual display: when the page group carries a reset
		// floor AND the since-reset count differs from the all-time count, expose
		// the all-time total so the pill can render "(45 / 108 sessions)" — the
		// headline (detected_total_all) stays the floored count on every surface.
		if ( $this->get_heatmap_reset_floor_for_group( $ids ) ) {
			$all_time = $this->get_canonical_pageview_sessions_for_group( $ids, $period, $start_date, $end_date, false );
			if ( null !== $all_time && (int) $all_time !== (int) $sessions ) {
				$pair['reset_all_time'] = (int) $all_time;
			}
		}

		return $pair;
	}

	/**
	 * Canonical human pageview-session total for a heatmap page (or canonical
	 * page_ids group), resolved through the Free page-analytics repository so it
	 * matches the frontend stats bar / post-edit metabox / dashboard for the same
	 * content page. Resolves from the primary page id's content group (post_id /
	 * URL) — the first page id that maps to a known content page wins — so a
	 * partial URL page_ids list still yields the full-page number.
	 *
	 * @since 2026-08-13 (canonical session universe)
	 * @param int|int[] $page_ids   Page id or canonical group.
	 * @param string    $period     Standard period slug (default 'all').
	 * @param string    $start_date Custom start.
	 * @param string    $end_date   Custom end.
	 * @return int|null Canonical session count, or null when no page id resolves
	 *                  to a known content page (callers fail open).
	 */
	/**
	 * Cron hook for the background canonical-overlay cache refresh (C2-3).
	 *
	 * @since 1.8.1.7
	 */
	const CANONLIST_REFRESH_CRON_HOOK = 'opti_behavior_canonlist_refresh';

	/**
	 * Compute the canonical grouped pageview-session map and persist it to both
	 * the versioned cache key and the version-independent stale-while-revalidate
	 * copy.
	 *
	 * @since 1.8.1.7
	 * @param int[]  $page_ids  Page ids (sorted).
	 * @param string $period    'all' or 'custom'.
	 * @param string $start     Y-m-d start bound ('' for all-time).
	 * @param string $end       Y-m-d end bound ('' for all-time).
	 * @param string $cache_key Versioned transient key ('' to skip).
	 * @param string $stale_key Version-independent transient key ('' to skip).
	 * @param array  $floors    Optional "Delete Heatmap Data" reset floors
	 *                          (page id => mysql datetime) — see
	 *                          get_heatmap_reset_floors_for_pages().
	 * @return array|null Map keyed by page_id, or null on failure.
	 */
	private function compute_and_cache_canonlist_map( $page_ids, $period, $start, $end, $cache_key, $stale_key, $floors = array() ) {
		$repo = new Opti_Behavior_Page_Analytics_Repository();
		$map  = $repo->get_group_session_counts_for_pages( $page_ids, $period, $start, $end, is_array( $floors ) ? $floors : array() );
		if ( is_array( $map ) ) {
			$ttl = function_exists( 'opti_behavior_heatmap_cache_ttl' ) ? opti_behavior_heatmap_cache_ttl() : 15 * MINUTE_IN_SECONDS;
			if ( $cache_key ) {
				set_transient( $cache_key, $map, $ttl );
			}
			if ( $stale_key ) {
				// Longer TTL: this copy only serves as a stale fallback while a
				// background refresh recomputes the fresh value.
				set_transient( $stale_key, $map, max( $ttl * 4, HOUR_IN_SECONDS ) );
			}
		}
		return $map;
	}

	/**
	 * Cron callback: background refresh of the canonical-overlay cache for one
	 * page-id group (C2-3 stale-while-revalidate).
	 *
	 * @since 1.8.1.7
	 * @param int[]  $page_ids Page ids.
	 * @param string $period   'all' or 'custom'.
	 * @param string $start    Y-m-d start bound.
	 * @param string $end      Y-m-d end bound.
	 */
	public function run_canonlist_refresh( $page_ids = array(), $period = 'all', $start = '', $end = '' ) {
		$page_ids = array_values( array_filter( array_map( 'intval', (array) $page_ids ) ) );
		if ( empty( $page_ids ) ) {
			return;
		}
		sort( $page_ids, SORT_NUMERIC );
		$seed_parts = array(
			'ids'    => $page_ids,
			'period' => (string) $period,
			'start'  => (string) $start,
			'end'    => (string) $end,
		);
		// "Delete Heatmap Data" reset floors: resolved from the option here (not
		// passed through the cron args) so the background recompute always uses
		// the CURRENT floors and its seed matches the inline overlay's seed.
		$floors = $this->get_heatmap_reset_floors_for_pages( $page_ids );
		if ( ! empty( $floors ) ) {
			$seed_parts['floors'] = $floors;
		}
		$cache_key = function_exists( 'opti_behavior_heatmap_cache_key' ) ? opti_behavior_heatmap_cache_key( 'canonlist', $seed_parts ) : '';
		$stale_key = 'ob_hm_canonlist_sw_' . md5( (string) wp_json_encode( $seed_parts ) );
		$this->compute_and_cache_canonlist_map( $page_ids, (string) $period, (string) $start, (string) $end, $cache_key, $stale_key, $floors );
	}

	/**
	 * Overlay canonical human pageview-session counts onto the Heatmaps list
	 * rows, in place. Replaces each row's file-based sessions_desktop /
	 * sessions_mobile (and the derived Sessions total) with the canonical
	 * group-aware count from the Free page-analytics repository so the list
	 * Sessions column matches the detail pill / frontend bar / metabox. Tablet
	 * and unknown-device sessions fold into the desktop bucket (the list only
	 * renders Desktop/Mobile), and desktop is reconciled so desktop + mobile ==
	 * the group session total. No-ops when the repository is unavailable
	 * (Free-only safety) so callers keep the legacy file counts.
	 *
	 * @since 2026-08-13 (canonical session universe)
	 * @param array  $pages_data Reference to the list rows (page_id keyed data).
	 * @param string $start_date Optional list window start (null/'' = all time).
	 * @param string $end_date   Optional list window end.
	 * @return void
	 */
	private function overlay_canonical_pageview_sessions( array &$pages_data, $start_date = null, $end_date = null ) {
		if ( empty( $pages_data ) || ! class_exists( 'Opti_Behavior_Page_Analytics_Repository' ) ) {
			return;
		}

		$page_ids = array();
		foreach ( $pages_data as $pd ) {
			if ( isset( $pd['page_id'] ) ) {
				$page_ids[] = (int) $pd['page_id'];
			}
		}
		$page_ids = array_values( array_unique( array_filter( $page_ids ) ) );
		if ( empty( $page_ids ) ) {
			return;
		}

		// All-time list (default) => 'all'; a bounded window => 'custom' with the
		// same Y-m-d bounds the file-scan path filters on.
		if ( $start_date && $end_date ) {
			$period = 'custom';
			$start  = gmdate( 'Y-m-d', strtotime( (string) $start_date ) );
			$end    = gmdate( 'Y-m-d', strtotime( (string) $end_date ) );
		} else {
			$period = 'all';
			$start  = '';
			$end    = '';
		}

		// Request + persistent cache: the grouped canonical-session queries scan
		// the pageviews table (heavy at 100k+ rows) and previously re-ran on
		// EVERY list render (warm == cold, see SCALE-005/006).
		//
		// PRODUCTION SAFETY (C2-3): the key used to embed MAX(pageviews.id), so
		// on a live site EVERY tracked pageview rotated the key and the cache
		// missed on essentially every render (measured 45-60s per render without
		// the covering index). The key is now STABLE (ids/period/bounds + the
		// heatmap cache version); the TTL bounds staleness. On large installs a
		// version-independent stale-while-revalidate copy is kept so a cache
		// rotation serves the last-known map instantly and recomputes in a
		// background cron tick instead of blocking the admin render.
		global $wpdb;
		sort( $page_ids, SORT_NUMERIC );
		$seed_parts = array(
			'ids'    => $page_ids,
			'period' => $period,
			'start'  => $start,
			'end'    => $end,
		);
		// "Delete Heatmap Data" reset floors (parity with the detail pill / chips):
		// floored pages count sessions since their reset, and the floors join BOTH
		// cache seeds — the versioned 'canonlist' key AND the version-independent
		// stale-while-revalidate key — so a reset (or un-reset) can never keep
		// serving pre-fix numbers from a stale copy. Only added when non-empty so
		// installs without resets keep their existing stable keys.
		$floors = $this->get_heatmap_reset_floors_for_pages( $page_ids );
		if ( ! empty( $floors ) ) {
			$seed_parts['floors'] = $floors;
		}
		$cache_key = '';
		if ( function_exists( 'opti_behavior_heatmap_cache_key' ) ) {
			$cache_key = opti_behavior_heatmap_cache_key( 'canonlist', $seed_parts );
		}
		$stale_key = 'ob_hm_canonlist_sw_' . md5( (string) wp_json_encode( $seed_parts ) );

		$map = null;
		if ( $cache_key && ! $this->should_force_refresh_dashboard_cache() ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				$map = $cached;
			}
		}

		$large_scale = false;
		if ( ! is_array( $map ) ) {
			// Stale-while-revalidate: on large installs serve the last-known map
			// and refresh it in the background instead of running the grouped
			// COUNT(DISTINCT) scan inline in this admin request.
			$core     = Opti_Behavior_Heatmap_Core::get_instance();
			$database = $core ? $core->get_database() : null;
			$large_scale = ( 'cli' !== PHP_SAPI )
				&& $database
				&& method_exists( $database, 'is_large_table' )
				&& $database->is_large_table( $wpdb->prefix . 'optibehavior_pageviews' );
			if ( $large_scale && ! $this->should_force_refresh_dashboard_cache() ) {
				$stale = get_transient( $stale_key );
				if ( is_array( $stale ) ) {
					$map = $stale;
				}
				// Refresh in the background (dedupes on identical args).
				if ( ! wp_next_scheduled( self::CANONLIST_REFRESH_CRON_HOOK, array( $page_ids, $period, $start, $end ) ) ) {
					wp_schedule_single_event( time() + 30, self::CANONLIST_REFRESH_CRON_HOOK, array( $page_ids, $period, $start, $end ) );
				}
			}
		}

		if ( ! is_array( $map ) ) {
			// PRODUCTION SAFETY (C2-4): a completely cold overlay at large scale
			// (no versioned cache, no stale copy — e.g. the very first admin list
			// render after upload) must NOT run the grouped COUNT(DISTINCT) scan
			// inline (measured 60 s without the covering index). The background
			// refresh scheduled above computes it; until then the list keeps its
			// file-derived session counts.
			if ( $large_scale ) {
				return;
			}
			$map = $this->compute_and_cache_canonlist_map( $page_ids, $period, $start, $end, $cache_key, $stale_key, $floors );
		}

		if ( empty( $map ) ) {
			return;
		}

		foreach ( $pages_data as &$pd ) {
			$pid = isset( $pd['page_id'] ) ? (int) $pd['page_id'] : 0;
			if ( ! $pid || ! isset( $map[ $pid ] ) ) {
				continue;
			}

			$c       = $map[ $pid ];
			$total   = (int) $c['sessions'];
			$mobile  = (int) $c['mobile'];
			// Fold tablet + unknown into desktop (list has no tablet column) and
			// reconcile so Desktop + Mobile == the canonical group total.
			$desktop = max( 0, $total - $mobile );

			$pd['sessions_desktop'] = $desktop;
			$pd['sessions_mobile']  = $mobile;
			$pd['sessions']         = $total;

			// "Delete Heatmap Data" dual display: floored pages whose since-reset
			// count differs from the all-time count carry the all-time total so
			// the list cell can render "valid / total" (e.g. "45 / 108"). Pages
			// without a reset (or where both counts agree) are untouched.
			if ( isset( $c['sessions_all_time'] ) && (int) $c['sessions_all_time'] !== $total ) {
				$pd['sessions_all_time'] = (int) $c['sessions_all_time'];
			} else {
				unset( $pd['sessions_all_time'] );
			}
		}
		unset( $pd );
	}

	private function get_canonical_pageview_sessions_for_group( $page_ids, $period = 'all', $start_date = '', $end_date = '', $apply_reset_floor = true ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $ids ) || ! class_exists( 'Opti_Behavior_Page_Analytics_Repository' ) ) {
			return null;
		}

		$repo = new Opti_Behavior_Page_Analytics_Repository();
		$args = array(
			'period'     => $period,
			'start_date' => $start_date,
			'end_date'   => $end_date,
		);
		// Honour "Delete Heatmap Data": drop pageview-sessions captured before the
		// page's reset so the detail pill clears after a delete (same contract the
		// file-scan / session_pages paths already enforce). $apply_reset_floor =
		// false is the pill's dual-display probe for the all-time total.
		$reset_floor = $apply_reset_floor ? $this->get_heatmap_reset_floor_for_group( $ids ) : '';
		if ( $reset_floor ) {
			$args['min_view_time'] = $reset_floor;
		}

		foreach ( $ids as $pid ) {
			$metrics = $repo->get_canonical_metrics_for_page_id( $pid, $args );
			if ( is_array( $metrics ) && isset( $metrics['sessions'] ) ) {
				return (int) $metrics['sessions'];
			}
		}

		return null;
	}

	/**
	 * Bridge (Free -> Pro): canonical device split for a heatmap page (or group)
	 * — the SAME canonical human pageview-session universe as the pill, split by
	 * device, so the detail device chips sum to the pill total (chips == pill).
	 * Unknown-device sessions fold into desktop and the desktop bucket is
	 * reconciled so desktop + mobile + tablet == the pill session total exactly.
	 *
	 * @since 2026-08-13 (canonical session universe)
	 * @param int|int[] $page_ids   Page id or canonical group.
	 * @param string    $period     Standard period slug.
	 * @param string    $start_date Custom start.
	 * @param string    $end_date   Custom end.
	 * @return array{desktop:int,mobile:int,tablet:int,total:int}|null Null when
	 *                  no page id resolves to a known content page.
	 */
	public function get_heatmap_canonical_device_counts( $page_ids, $period = 'all', $start_date = '', $end_date = '' ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $ids ) || ! class_exists( 'Opti_Behavior_Page_Analytics_Repository' ) ) {
			return null;
		}

		$repo = new Opti_Behavior_Page_Analytics_Repository();
		$args = array(
			'period'     => $period,
			'start_date' => $start_date,
			'end_date'   => $end_date,
		);
		// Honour "Delete Heatmap Data" reset floor so the detail device chips /
		// Views clear after a delete (same contract as the pill above).
		$reset_floor = $this->get_heatmap_reset_floor_for_group( $ids );
		if ( $reset_floor ) {
			$args['min_view_time'] = $reset_floor;
		}

		foreach ( $ids as $pid ) {
			$metrics = $repo->get_canonical_metrics_for_page_id( $pid, $args );
			if ( ! is_array( $metrics ) || ! isset( $metrics['sessions'] ) ) {
				continue;
			}

			$devices = isset( $metrics['devices'] ) && is_array( $metrics['devices'] ) ? $metrics['devices'] : array();
			$total   = (int) $metrics['sessions'];
			$mobile  = isset( $devices['mobile'] ) ? (int) $devices['mobile'] : 0;
			$tablet  = isset( $devices['tablet'] ) ? (int) $devices['tablet'] : 0;
			// Desktop absorbs unknown-device sessions and is reconciled so the
			// chips always sum to the pill total (no silently dropped sessions
			// when a session touches more than one device type).
			$desktop = max( 0, $total - $mobile - $tablet );

			return array(
				'desktop' => $desktop,
				'mobile'  => $mobile,
				'tablet'  => $tablet,
				'total'   => $total,
			);
		}

		return null;
	}

	/**
	 * Bridge (Free -> Pro): canonical visitor-type split (guest / logged-in) for
	 * a heatmap page (or group) — the SAME canonical human pageview-session
	 * universe as the pill, split by visitor type, so the detail Visitor Type
	 * dropdown option counts sum to the pill total (guest + logged_in == pill).
	 * Supersedes the old file-token + orphan-union counts that inflated the
	 * dropdown (92/11) far past the pill (46).
	 *
	 * @since 2026-08-13 (canonical Visitor Type dropdown)
	 * @param int|int[] $page_ids   Page id or canonical group.
	 * @param string    $period     Standard period slug.
	 * @param string    $start_date Custom start.
	 * @param string    $end_date   Custom end.
	 * @return array{guest:int,logged_in:int,total:int}|null Null when no page id
	 *                  resolves to a known content page.
	 */
	public function get_heatmap_canonical_visitor_type_counts( $page_ids, $period = 'all', $start_date = '', $end_date = '' ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $ids ) || ! class_exists( 'Opti_Behavior_Page_Analytics_Repository' ) ) {
			return null;
		}

		$repo = new Opti_Behavior_Page_Analytics_Repository();
		$args = array(
			'period'     => $period,
			'start_date' => $start_date,
			'end_date'   => $end_date,
		);
		// Honour "Delete Heatmap Data" reset floor so guest + logged_in keeps
		// summing to the (floored) pill total after a delete (same contract as
		// the pill / device chips above).
		$reset_floor = $this->get_heatmap_reset_floor_for_group( $ids );
		if ( $reset_floor ) {
			$args['min_view_time'] = $reset_floor;
		}

		foreach ( $ids as $pid ) {
			$metrics = $repo->get_canonical_metrics_for_page_id( $pid, $args );
			if ( ! is_array( $metrics ) || ! isset( $metrics['sessions'] ) ) {
				continue;
			}

			$total = (int) $metrics['sessions'];
			$vt    = isset( $metrics['visitor_types'] ) && is_array( $metrics['visitor_types'] ) ? $metrics['visitor_types'] : array();
			$logged = isset( $vt['logged_in'] ) ? (int) $vt['logged_in'] : 0;
			// Guest absorbs any residual so guest + logged == the pill total exactly
			// (no silently dropped sessions if attribution is partial).
			$guest  = max( 0, $total - $logged );

			return array(
				'guest'     => $guest,
				'logged_in' => $logged,
				'total'     => $total,
			);
		}

		return null;
	}

	/**
	 * Bridge (Free -> Pro): canonical per-value session counts for one filter
	 * dimension (Country / Browser / Operating System / Entry Page / Exit Page /
	 * Traffic Channel / Referrer) over the SAME canonical human pageview-session
	 * universe as the header pill, device chips and Visitor Type dropdown
	 * (distinct optibehavior_pageviews.session_id, human, spam-excluded, resolved
	 * across the page's full content group, respecting the page's Period
	 * selector). Supersedes the heatmap-file token / session_pages counts that
	 * disagreed with the pill (e.g. Chrome 41 / France 41 / Windows 41 vs pill
	 * 46). For the closed-universe dimensions (country / browser / os /
	 * traffic_channel) sessions with no attribute fold into an explicit
	 * 'Unknown' bucket so the option-count sum equals the pill total; the
	 * open-ended suggestion dimensions (entry_page / exit_page / referrer) drop
	 * empty / same-site values (they feed a typeahead) so their sum may be <= the
	 * pill.
	 *
	 * @since 2026-08-13 (canonical filter-option counts, plan step 198)
	 * @param int|int[] $page_ids   Page id or canonical group (heatmap detail sends the whole page_ids list).
	 * @param string    $dimension  country|browser|os|entry_page|exit_page|traffic_channel|referrer.
	 * @param string    $period     Standard period slug (default 'all').
	 * @param string    $start_date Custom start.
	 * @param string    $end_date   Custom end.
	 * @return array<string,int>|null Map of value => session count (may include an
	 *                  'Unknown' bucket), or null when unavailable / dimension
	 *                  unsupported (caller keeps its legacy file-based counts).
	 */
	public function get_heatmap_canonical_dimension_counts( $page_ids, $dimension, $period = 'all', $start_date = '', $end_date = '' ) {
		global $wpdb;

		$dimension = sanitize_key( (string) $dimension );
		$supported = array( 'country', 'browser', 'os', 'entry_page', 'exit_page', 'traffic_channel', 'referrer' );
		if ( ! in_array( $dimension, $supported, true ) || ! class_exists( 'Opti_Behavior_Page_Analytics_Repository' ) ) {
			return null;
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $ids ) ) {
			return null;
		}

		$repo         = new Opti_Behavior_Page_Analytics_Repository();
		$pv_table     = $wpdb->prefix . 'optibehavior_pageviews';
		$sessions_tbl = $wpdb->prefix . 'optibehavior_sessions';
		$visitors_tbl = $wpdb->prefix . 'optibehavior_visitors';

		// Resolve the canonical content group EXACTLY as the pill does (shared
		// helper): take the first requested page id that maps to a known content
		// page and expand it to its full post_id / URL variant group. Guarantees
		// dropdown == pill.
		$group = $this->resolve_heatmap_canonical_group( $ids );
		if ( empty( $group ) ) {
			return null;
		}

		$date_range = $repo->normalize_date_range( $period, $start_date, $end_date );
		$scope      = $repo->get_traffic_scope_sql( 's', Opti_Behavior_Page_Analytics_Repository::TRAFFIC_SCOPE_HUMAN );
		$scope_sql  = isset( $scope['sql'] ) ? $scope['sql'] : '';

		$has_bounds = ! empty( $date_range['has_bounds'] );
		$date_sql   = $has_bounds ? ' AND pv.view_time BETWEEN %s AND %s' : '';
		$date_prm   = $has_bounds ? array( $date_range['start'], $date_range['end'] ) : array();

		// Honour "Delete Heatmap Data" reset floor (datetime precision) so every
		// dimension option count sums to the (floored) pill total after a delete
		// (mirrors get_heatmap_canonical_filtered_device_counts()).
		$reset_floor = $this->get_heatmap_reset_floor_for_group( $ids );
		if ( $reset_floor ) {
			$date_sql  .= ' AND pv.view_time >= %s';
			$date_prm[] = $reset_floor;
		}

		$page_ph = implode( ',', array_fill( 0, count( $group ), '%d' ) );

		// Per-dimension SELECT expression + which enrichment JOINs are needed.
		$need_visitors = false;
		$referrer_norm = "REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(s.referrer, 'https://', ''), 'http://', ''), '/', 1), '?', 1), 'www.', '')";
		switch ( $dimension ) {
			case 'country':
				$need_visitors = true;
				$value_expr    = "UPPER(TRIM(COALESCE(v.country, '')))";
				break;
			case 'browser':
				$need_visitors = true;
				$value_expr    = "TRIM(COALESCE(v.browser, ''))";
				break;
			case 'os':
				$need_visitors = true;
				$value_expr    = "TRIM(COALESCE(v.os, ''))";
				break;
			case 'entry_page':
				$value_expr = "TRIM(COALESCE(s.entry_page, ''))";
				break;
			case 'exit_page':
				$value_expr = "TRIM(COALESCE(s.exit_page, ''))";
				break;
			case 'referrer':
				$value_expr = $referrer_norm;
				break;
			case 'traffic_channel':
			default:
				$value_expr = $this->build_traffic_channel_case_sql();
				break;
		}

		$visitors_join = $need_visitors ? "LEFT JOIN {$visitors_tbl} v ON v.id = pv.visitor_id" : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prefix tables + prepared placeholders; value_expr is a hard-coded column/CASE fragment.
		$sql  = "SELECT {$value_expr} AS dim_value, COUNT(DISTINCT pv.session_id) AS sessions
			FROM {$pv_table} pv
			LEFT JOIN {$sessions_tbl} s ON s.id = pv.session_id
			{$visitors_join}
			WHERE pv.page_id IN ({$page_ph}){$date_sql}{$scope_sql}
			GROUP BY dim_value";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( $group, $date_prm ) ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return null;
		}

		// Dimensions whose values feed a typeahead suggestion list (not a fixed
		// closed set): drop blank / same-site values instead of bucketing them,
		// so they stay usable filter suggestions. The closed dimensions bucket
		// blanks into 'Unknown' so their sum equals the pill total.
		$suggestion_dims = array( 'entry_page', 'exit_page', 'referrer' );
		$is_suggestion   = in_array( $dimension, $suggestion_dims, true );
		$site_host       = wp_parse_url( home_url(), PHP_URL_HOST );
		$site_host       = $site_host ? preg_replace( '/^www\./i', '', (string) $site_host ) : '';

		$counts  = array();
		$display = array();
		$unknown = 0;
		foreach ( $rows as $row ) {
			$value = isset( $row['dim_value'] ) ? trim( (string) $row['dim_value'] ) : '';
			$count = isset( $row['sessions'] ) ? absint( $row['sessions'] ) : 0;

			$blank = ( '' === $value || 'unknown' === strtolower( $value ) );
			if ( 'country' === $dimension && ! $blank ) {
				$blank = in_array( strtoupper( $value ), array( 'XX', 'UN' ), true );
			}
			if ( 'referrer' === $dimension && ! $blank && '' !== $site_host && 0 === strcasecmp( $value, $site_host ) ) {
				$blank = true; // own host === Direct, not a referrer suggestion.
			}

			if ( $blank ) {
				$unknown += $count;
				continue;
			}

			$key = strtolower( $value );
			if ( ! isset( $display[ $key ] ) ) {
				$display[ $key ]            = 'country' === $dimension ? strtoupper( $value ) : $value;
				$counts[ $display[ $key ] ] = 0;
			}
			$counts[ $display[ $key ] ] += $count;
		}

		if ( ! $is_suggestion && $unknown > 0 ) {
			$counts['Unknown'] = ( isset( $counts['Unknown'] ) ? $counts['Unknown'] : 0 ) + $unknown;
		}

		arsort( $counts );

		return $counts;
	}

	/**
	 * Resolve a set of heatmap page ids to their canonical content group EXACTLY
	 * as the header pill does: take the first requested page id that maps to a
	 * known content page and expand it (via the repository identity resolver) to
	 * its full post_id / URL variant group. Shared by the canonical dimension /
	 * filtered-count bridges so every heatmap surface counts one identical group.
	 *
	 * @since 2026-08-13 (bottom-bar filter scoping, plan step 216)
	 * @param int[] $ids Requested page ids.
	 * @return int[] Canonical group page ids (empty when none resolve).
	 */
	private function resolve_heatmap_canonical_group( array $ids ) {
		global $wpdb;

		if ( ! class_exists( 'Opti_Behavior_Page_Analytics_Repository' ) ) {
			return array();
		}

		$repo        = new Opti_Behavior_Page_Analytics_Repository();
		$pages_table = $wpdb->prefix . 'optibehavior_pages';

		foreach ( $ids as $pid ) {
			$pid = absint( $pid );
			if ( ! $pid ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix table; id prepared.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_id, url FROM {$pages_table} WHERE id = %d", $pid ), ARRAY_A );
			if ( ! $row ) {
				continue;
			}
			if ( ! empty( $row['post_id'] ) ) {
				$resolver = array( 'post_id' => absint( $row['post_id'] ) );
			} elseif ( ! empty( $row['url'] ) ) {
				$resolver = array( 'page_url' => (string) $row['url'] );
			} else {
				continue;
			}
			$identity = $repo->resolve_page_identity( $resolver );
			if ( ! empty( $identity['page_ids'] ) ) {
				return array_values( array_unique( array_map( 'absint', $identity['page_ids'] ) ) );
			}
		}

		return array();
	}

	/**
	 * Fold a raw device_type token into the three heatmap device buckets
	 * (unknown / unrecognized -> desktop), mirroring the repository / file
	 * bucketer so device chips sum toward the session total.
	 *
	 * @since 2026-08-13 (bottom-bar filter scoping, plan step 216)
	 * @param string $device_type Raw device type token.
	 * @return string 'desktop'|'mobile'|'tablet'.
	 */
	private function fold_heatmap_device_bucket( $device_type ) {
		$device_type = strtolower( (string) $device_type );
		if ( 'mobile' === $device_type || 'phone' === $device_type ) {
			return 'mobile';
		}
		if ( 'tablet' === $device_type ) {
			return 'tablet';
		}
		return 'desktop';
	}

	/**
	 * Whether any advanced/basic canonical session filter is active on $filters
	 * (country / browser / os / visitor_type / entry_page / exit_page / referrer
	 * / traffic_channel / utm_* / duration bounds). When NONE are active the
	 * bottom bar must equal the period-total pill, so the filtered counter
	 * short-circuits to the unfiltered canonical device counter.
	 *
	 * A visitor_type of '' (All Visitors — the surface default) is NOT a filter,
	 * so the default state stays equal to the pill (chips/Views == pill).
	 *
	 * @since 2026-08-13 (bottom-bar filter scoping, plan step 216)
	 * @param array $filters Filters array.
	 * @return bool True when at least one canonical filter is active.
	 */
	private function heatmap_canonical_filters_active( array $filters ) {
		foreach ( array( 'country', 'browser', 'os', 'visitor_type', 'entry_page', 'exit_page', 'referrer', 'traffic_channel', 'utm_campaign', 'utm_source', 'utm_medium' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				return true;
			}
		}
		if ( ( isset( $filters['duration_min'] ) && '' !== $filters['duration_min'] )
			|| ( isset( $filters['duration_max'] ) && '' !== $filters['duration_max'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Build the WHERE fragment + bound params for the active canonical session
	 * filters, over the pageviews(pv) + sessions(s) + visitors(v) join. Values
	 * are matched against the SAME columns the canonical dropdown option counts
	 * use, so applying a filter shrinks the bar to exactly that option's subset
	 * (e.g. visitor_type=guest -> 36 == the Visitor Type "Guest" option count).
	 *
	 * @since 2026-08-13 (bottom-bar filter scoping, plan step 216)
	 * @param array $filters Filters array (raw dropdown/panel values).
	 * @param string $sql    OUT: SQL fragment (leading " AND ..."), '' when none.
	 * @param array  $params OUT: bound parameters in fragment order.
	 * @return void
	 */
	private function build_canonical_filter_where( array $filters, &$sql, &$params ) {
		global $wpdb;

		$sql     = '';
		$params  = array();
		$clauses = array();

		$csv = function ( $val ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', (string) $val ) ) ) );
		};

		// Country codes (upper), browser + os (case-insensitive) live on visitors.
		if ( ! empty( $filters['country'] ) ) {
			$vals = array_map( 'strtoupper', $csv( $filters['country'] ) );
			if ( $vals ) {
				$ph        = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
				$clauses[] = "UPPER(TRIM(COALESCE(v.country,''))) IN ({$ph})";
				$params    = array_merge( $params, $vals );
			}
		}
		if ( ! empty( $filters['browser'] ) ) {
			$vals = array_map( 'strtolower', $csv( $filters['browser'] ) );
			if ( $vals ) {
				$ph        = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
				$clauses[] = "LOWER(TRIM(COALESCE(v.browser,''))) IN ({$ph})";
				$params    = array_merge( $params, $vals );
			}
		}
		if ( ! empty( $filters['os'] ) ) {
			$vals = array_map( 'strtolower', $csv( $filters['os'] ) );
			if ( $vals ) {
				$ph        = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
				$clauses[] = "LOWER(TRIM(COALESCE(v.os,''))) IN ({$ph})";
				$params    = array_merge( $params, $vals );
			}
		}

		// Visitor type (guest vs logged-in) from sessions.user_id.
		if ( ! empty( $filters['visitor_type'] ) ) {
			$vt = strtolower( (string) $filters['visitor_type'] );
			if ( 'guest' === $vt ) {
				$clauses[] = '( s.user_id IS NULL OR s.user_id = 0 )';
			} elseif ( 'logged_in' === $vt ) {
				$clauses[] = '( s.user_id IS NOT NULL AND s.user_id > 0 )';
			}
		}

		// Entry / exit / referrer LIKE (sessions).
		foreach ( array( 'entry_page', 'exit_page', 'referrer' ) as $col ) {
			if ( ! empty( $filters[ $col ] ) ) {
				$clauses[] = "s.{$col} LIKE %s";
				$params[]  = '%' . $wpdb->esc_like( (string) $filters[ $col ] ) . '%';
			}
		}

		// Traffic channel (derived from referrer + UTM, same CASE as dropdowns).
		if ( ! empty( $filters['traffic_channel'] ) ) {
			$vals = $csv( $filters['traffic_channel'] );
			if ( $vals ) {
				$ph        = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
				$clauses[] = '( ' . $this->build_traffic_channel_case_sql() . " ) IN ({$ph})";
				$params    = array_merge( $params, $vals );
			}
		}

		// UTM parameters (exact, sessions).
		foreach ( array( 'utm_campaign', 'utm_source', 'utm_medium' ) as $col ) {
			if ( ! empty( $filters[ $col ] ) ) {
				$vals = $csv( $filters[ $col ] );
				if ( $vals ) {
					$ph        = implode( ',', array_fill( 0, count( $vals ), '%s' ) );
					$clauses[] = "s.{$col} IN ({$ph})";
					$params    = array_merge( $params, $vals );
				}
			}
		}

		// Session duration bounds (seconds).
		if ( isset( $filters['duration_min'] ) && '' !== $filters['duration_min'] ) {
			$clauses[] = 'COALESCE(s.duration,0) >= %d';
			$params[]  = absint( $filters['duration_min'] );
		}
		if ( isset( $filters['duration_max'] ) && '' !== $filters['duration_max'] ) {
			$clauses[] = 'COALESCE(s.duration,0) <= %d';
			$params[]  = absint( $filters['duration_max'] );
		}

		if ( $clauses ) {
			$sql = ' AND ' . implode( ' AND ', $clauses );
		}
	}

	/**
	 * Bridge (Free -> Pro): canonical device split for a heatmap page group under
	 * the FULL active filter set (period + advanced Filters panel) — the bottom
	 * bar's device chips + Views. Same canonical human pageview-session universe
	 * as the header pill, but reactive to every applied filter so:
	 *   - unfiltered  => chips/Views == pill (delegates to the unfiltered counter)
	 *   - filtered    => chips sum == Views == filtered session count <= pill
	 *
	 * @since 2026-08-13 (bottom-bar filter scoping, plan step 216)
	 * @param int|int[] $page_ids   Page id or canonical group.
	 * @param array     $filters    Active filters (raw dropdown/panel values).
	 * @param string    $period     Standard period slug.
	 * @param string    $start_date Custom start.
	 * @param string    $end_date   Custom end.
	 * @return array{desktop:int,mobile:int,tablet:int,total:int}|null Null when
	 *                  the canonical universe is unavailable (caller keeps its
	 *                  legacy counts).
	 */
	public function get_heatmap_canonical_filtered_device_counts( $page_ids, $filters = array(), $period = 'all', $start_date = '', $end_date = '' ) {
		global $wpdb;

		if ( ! class_exists( 'Opti_Behavior_Page_Analytics_Repository' ) ) {
			return null;
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $ids ) ) {
			return null;
		}

		$filters = is_array( $filters ) ? $filters : array();

		// No active filter -> the bar must equal the period-total pill exactly.
		if ( ! $this->heatmap_canonical_filters_active( $filters ) ) {
			return $this->get_heatmap_canonical_device_counts( $ids, $period, $start_date, $end_date );
		}

		$group = $this->resolve_heatmap_canonical_group( $ids );
		if ( empty( $group ) ) {
			return null;
		}

		$repo         = new Opti_Behavior_Page_Analytics_Repository();
		$pv_table     = $wpdb->prefix . 'optibehavior_pageviews';
		$sessions_tbl = $wpdb->prefix . 'optibehavior_sessions';
		$visitors_tbl = $wpdb->prefix . 'optibehavior_visitors';

		$date_range = $repo->normalize_date_range( $period, $start_date, $end_date );
		$scope      = $repo->get_traffic_scope_sql( 's', Opti_Behavior_Page_Analytics_Repository::TRAFFIC_SCOPE_HUMAN );
		$scope_sql  = isset( $scope['sql'] ) ? $scope['sql'] : '';

		$has_bounds = ! empty( $date_range['has_bounds'] );
		$date_sql   = $has_bounds ? ' AND pv.view_time BETWEEN %s AND %s' : '';

		// Honour "Delete Heatmap Data" reset floor (datetime precision) so filtered
		// chips / Views also clear after a delete.
		$reset_floor = $this->get_heatmap_reset_floor_for_group( $ids );
		if ( $reset_floor ) {
			$date_sql .= ' AND pv.view_time >= %s';
		}

		$filter_sql = '';
		$filter_prm = array();
		$this->build_canonical_filter_where( $filters, $filter_sql, $filter_prm );

		$page_ph = implode( ',', array_fill( 0, count( $group ), '%d' ) );

		// Param order matches placeholder order: group ids, date bounds, reset
		// floor, filters.
		$common_prm = $group;
		if ( $has_bounds ) {
			$common_prm[] = $date_range['start'];
			$common_prm[] = $date_range['end'];
		}
		if ( $reset_floor ) {
			$common_prm[] = $reset_floor;
		}
		$common_prm = array_merge( $common_prm, $filter_prm );

		$join  = "LEFT JOIN {$sessions_tbl} s ON s.id = pv.session_id LEFT JOIN {$visitors_tbl} v ON v.id = pv.visitor_id";
		$where = "WHERE pv.page_id IN ({$page_ph}){$date_sql}{$scope_sql}{$filter_sql}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prefix tables + prepared placeholders; joins/where are hard-coded fragments.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT pv.session_id) FROM {$pv_table} pv {$join} {$where}", ...$common_prm ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prefix tables + prepared placeholders; joins/where are hard-coded fragments.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(NULLIF(LOWER(v.device_type),''),'unknown') AS dt, COUNT(DISTINCT pv.session_id) AS c FROM {$pv_table} pv {$join} {$where} GROUP BY dt", ...$common_prm ), ARRAY_A );

		$mobile = 0;
		$tablet = 0;
		foreach ( (array) $rows as $r ) {
			$bucket = $this->fold_heatmap_device_bucket( isset( $r['dt'] ) ? $r['dt'] : '' );
			$c      = isset( $r['c'] ) ? (int) $r['c'] : 0;
			if ( 'mobile' === $bucket ) {
				$mobile += $c;
			} elseif ( 'tablet' === $bucket ) {
				$tablet += $c;
			}
		}
		// Desktop absorbs unknown-device + any residual so the chips always sum to
		// the distinct filtered total (chips sum == Views == total).
		$desktop = max( 0, $total - $mobile - $tablet );

		return array(
			'desktop' => $desktop,
			'mobile'  => $mobile,
			'tablet'  => $tablet,
			'total'   => $total,
		);
	}

	/**
	 * Guest spam-excluded heatmap session total for one page — the ONE unified
	 * heatmap universe (agg_sessions = sessions_desktop + sessions_mobile) the
	 * list Sessions cell and the detail device chips both render. Read straight
	 * from the fast-path aggregate columns so the pill, chips, and list can
	 * never disagree (they all read agg_sessions).
	 *
	 * @since 2026-08-13 (unified heatmap scope)
	 * @param int|int[] $page_ids Page ID, or the canonical page_ids group (all
	 *                            variant page_ids of one content page). When an
	 *                            array is given the agg_sessions are SUMMED
	 *                            across the whole group so the pill matches the
	 *                            chips/list, which also aggregate the group.
	 * @return int|null agg_sessions for the page(s), or null when NONE of the
	 *                  page_ids have a heatmap mapping row (so callers fail open).
	 */
	private function get_page_guest_heatmap_sessions( $page_ids ) {
		global $wpdb;

		// Normalize a single id or a canonical group to a de-duplicated id list.
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $ids ) ) {
			return null;
		}

		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// SUM agg_sessions across every mapping row for the whole page_ids group
		// (multi-variant pages) AND across any sibling rows of a single page_id
		// (post-dedupe there is one row per page_id, but SUM stays correct if a
		// duplicate slips back in).
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; placeholders bound below; agg column read.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS row_count, SUM(agg_sessions) AS agg_total FROM {$table} WHERE page_id IN ({$placeholders})",
				$ids
			)
		);

		if ( ! $row || (int) $row->row_count === 0 ) {
			return null; // No heatmap mapping row for any page in the group — fail open.
		}

		return (int) $row->agg_total;
	}

	/**
	 * Batch all-visitors DB session totals for a set of pages (unified list
	 * metric, plan step "Unify session metric").
	 *
	 * Second number of the list's "X / Y" sessions cell: Y = ALL sessions
	 * recorded in optibehavior_session_pages for the page — every visitor type
	 * (guest + logged in), all time — the exact same query scope the detail
	 * header pill's Y uses (Pro's ajax_get_total_recordings() ->
	 * get_session_page_device_counts() with an empty visitor_type), just read
	 * through the batched variant so one indexed query covers the whole table
	 * page. No file IO. Returns null when Pro / the count helper is
	 * unavailable (Free-only installs fail open to the legacy cell).
	 *
	 * @since 1.9.x
	 * @param array $page_ids Page IDs.
	 * @return array<int,int>|null Map of page_id => all-visitors session total, or null.
	 */
	private function get_all_visitors_db_session_totals( array $page_ids ) {
		if ( empty( $page_ids ) ) {
			return null;
		}

		if ( ! class_exists( 'Opti_Behavior_Heatmap_Ajax', false )
			|| ! method_exists( 'Opti_Behavior_Heatmap_Ajax', 'get_count_helper' ) ) {
			return null;
		}

		$normalized = array_values( array_unique( array_filter( array_map( 'absint', $page_ids ) ) ) );
		if ( empty( $normalized ) ) {
			return null;
		}
		sort( $normalized );

		$memo_key = implode( ',', $normalized );
		if ( array_key_exists( $memo_key, $this->hm_all_visitors_db_memo ) ) {
			return $this->hm_all_visitors_db_memo[ $memo_key ];
		}

		$helper = Opti_Behavior_Heatmap_Ajax::get_count_helper();
		if ( ! $helper || ! method_exists( $helper, 'get_batch_session_page_device_counts' ) ) {
			return $this->hm_all_visitors_db_memo[ $memo_key ] = null;
		}

		$map = $helper->get_batch_session_page_device_counts(
			$normalized,
			'all',
			array(
				// Empty visitor_type = ALL visitors — same scope as the detail
				// pill's Y (build_session_page_scope_sql() only narrows on a
				// non-empty visitor_type).
				'visitor_type' => '',
				'exclude_spam' => $this->is_spam_excluded() ? 1 : 0,
			)
		);
		if ( ! is_array( $map ) ) {
			return $this->hm_all_visitors_db_memo[ $memo_key ] = null;
		}

		$totals = array();
		foreach ( $map as $pid => $counts ) {
			$totals[ (int) $pid ] = isset( $counts['total'] ) ? (int) $counts['total'] : 0;
		}

		return $this->hm_all_visitors_db_memo[ $memo_key ] = $totals;
	}

	/**
	 * Overlay the unified all-visitors session metric onto list rows (plan
	 * step "Unify session metric" — list and detail show the SAME pair).
	 *
	 * Attaches two additive fields per row:
	 *  - sessions_file_all: sessions WITH heatmap event data (unique session
	 *    tokens across the page's JSON files, all visitor types) — read from
	 *    the reconciliation cache's 'file_total_all' (the same value the
	 *    detail pill's bridge serves). NEVER computed synchronously here
	 *    (hot path): pages without a cache entry are skipped.
	 *  - sessions_db_all: the page's "Y" — the reconciliation cache's
	 *    'detected_total_all' (DB sessions UNION orphan file sessions, spec
	 *    §3.3) when the cache entry has it; falls back to the live
	 *    all-visitors DB-only query (pre-Honest-Y cache entries / isset
	 *    staleness check, same pattern as the 'file_total_all' gate above)
	 *    so old cache entries keep rendering instead of going blank.
	 *
	 * Rows missing either side keep their legacy guest-scoped dual-metric
	 * fields untouched (fail open), so Free-only installs and cold-cache
	 * pages render exactly as before.
	 *
	 * @since 1.9.x
	 * @param array $rows List rows (arrays with an 'id' key).
	 * @return array Rows with the unified metric attached where available.
	 *
	 * UNIFIED HEATMAP SCOPE (2026-08-13): retired. Every heatmap surface (list
	 * Sessions cell, detail device chips, detail header pill) now renders the
	 * ONE guest spam-excluded universe (agg_sessions). This overlay used to
	 * replace the list cell's first number with the reconciliation cache's
	 * all-visitors 'file_total_all', which is exactly the "102 vs 92" mismatch
	 * this pass eliminates. It is now a no-op so the list cell keeps the
	 * guest-scoped agg_sessions value the rows already carry (kept as a method
	 * for BC — call site + tests still reference it by name).
	 */
	private function overlay_unified_session_metric( $rows ) {
		// No-op: do NOT overlay the all-visitors file_total_all/detected_total_all
		// pair. The list renders guest spam-excluded agg_sessions (== the detail
		// chips and header pill). Returning rows untouched keeps sort key ===
		// displayed value (the guest desktop+mobile sum computed upstream).
		return $rows;
	}

	/**
	 * Legacy all-visitors overlay body, retired 2026-08-13 (kept dead for
	 * reference/diff clarity; never executed). See overlay_unified_session_metric().
	 *
	 * @param array $rows List rows.
	 * @return array Rows.
	 */
	private function overlay_unified_session_metric_legacy( $rows ) {
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return $rows;
		}

		$sync_map = get_transient( self::HEATMAP_SYNC_TRANSIENT );
		if ( ! is_array( $sync_map ) || empty( $sync_map ) ) {
			return $rows;
		}

		$ids_with_file_all = array();
		foreach ( $rows as $row ) {
			$row_arr = (array) $row;
			$id      = isset( $row_arr['id'] ) ? absint( $row_arr['id'] ) : 0;
			if ( $id && isset( $sync_map[ $id ]['file_total_all'] ) ) {
				$ids_with_file_all[] = $id;
			}
		}
		$ids_with_file_all = array_values( array_unique( $ids_with_file_all ) );
		if ( empty( $ids_with_file_all ) ) {
			return $rows;
		}

		$db_totals = $this->get_all_visitors_db_session_totals( $ids_with_file_all );
		if ( ! is_array( $db_totals ) ) {
			return $rows;
		}

		foreach ( $rows as &$row ) {
			$is_object = is_object( $row );
			$row_arr   = (array) $row;
			$id        = isset( $row_arr['id'] ) ? absint( $row_arr['id'] ) : 0;
			if ( ! $id || ! isset( $sync_map[ $id ]['file_total_all'] ) || ! array_key_exists( $id, $db_totals ) ) {
				continue;
			}
			// Honest Y (spec §3.3): prefer the cache's detected_total_all
			// (DB UNION orphan file sessions) over the DB-only live query so
			// the list cell agrees with the detail pill's Y and never shows
			// the impossible "X > Y" state for pages with orphan files.
			$sessions_db_all = isset( $sync_map[ $id ]['detected_total_all'] )
				? (int) $sync_map[ $id ]['detected_total_all']
				: (int) $db_totals[ $id ];

			if ( $is_object ) {
				$row->sessions_file_all = (int) $sync_map[ $id ]['file_total_all'];
				$row->sessions_db_all   = $sessions_db_all;
			} else {
				$row['sessions_file_all'] = (int) $sync_map[ $id ]['file_total_all'];
				$row['sessions_db_all']   = $sessions_db_all;
			}

			// List device buttons (spec: "device buttons must share the row's
			// session-cell file universe"): whenever the row shows the unified
			// X/Y pair above, its Desktop/Mobile/Tablet buttons must partition
			// the SAME file_total_all universe, not a DB-only/guest-only split.
			// Cache-only (precomputed by the same reconcile/cron/Repair pass
			// that filled file_total_all/file_devices_all) — no per-row file
			// IO or DB query here, so this stays safe at large scale. Fails
			// open (legacy sessions_desktop/mobile untouched) when the cache
			// entry predates 'file_devices_all'.
			if ( isset( $sync_map[ $id ]['file_devices_all'] ) && is_array( $sync_map[ $id ]['file_devices_all'] ) ) {
				$devices_all = $sync_map[ $id ]['file_devices_all'];
				$sd          = isset( $devices_all['desktop'] ) ? (int) $devices_all['desktop'] : 0;
				$sm          = isset( $devices_all['mobile'] ) ? (int) $devices_all['mobile'] : 0;
				$st          = isset( $devices_all['tablet'] ) ? (int) $devices_all['tablet'] : 0;
				if ( $is_object ) {
					$row->sessions_desktop = $sd;
					$row->sessions_mobile  = $sm;
					$row->sessions_tablet  = $st;
				} else {
					$row['sessions_desktop'] = $sd;
					$row['sessions_mobile']  = $sm;
					$row['sessions_tablet']  = $st;
				}
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Mark the stored fast-path aggregate columns stale for a set of pages
	 * (Repair durability, plan step "Make Repair durable").
	 *
	 * A successful manual Repair recomputes the reconciliation cache, but the
	 * list's fast path serves precomputed agg_* columns that are only ever
	 * resynced when agg_synced_at is NULL (or the default spam state changed).
	 * Data cleanup can delete sessions/files underneath an already-synced row,
	 * leaving agg_sessions_* / agg_interactions permanently stale — a reload then
	 * reverts the just-repaired row (live "11 / 8" case, page_id=1). NULLing
	 * agg_synced_at makes the next render's inline sync (or the agg cron)
	 * recompute those columns from the CURRENT files, so the server-rendered
	 * numbers agree with the repair result. Cheap: one UPDATE over the page ids
	 * (all mapping rows sharing each page_id), no file IO here.
	 *
	 * Deliberately does NOT zero the agg_* values themselves: the row stays
	 * "stale-but-serveable" (not "never-synced"), so the fast path keeps
	 * rendering until the resync lands — same semantics ingest uses.
	 *
	 * @since 1.9.0
	 * @param array $page_ids Page IDs whose aggregates must be recomputed.
	 * @return int Number of mapping rows marked stale.
	 */
	private function invalidate_heatmap_aggregates_for_pages( array $page_ids ) {
		global $wpdb;

		if ( ! $this->heatmap_pages_aggregate_columns_exist() ) {
			return 0;
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $page_ids ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table   = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$updated = 0;

		// Repair/cleanup also invalidates the per-day index rows (spec §P1):
		// files may have been deleted underneath already-derived daily rows.
		$set_clause = $this->heatmap_daily_index_available()
			? 'agg_synced_at = NULL, daily_synced_at = NULL'
			: 'agg_synced_at = NULL';

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Aggregate column maintenance; identifiers hard-coded, ids prepared.
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET {$set_clause} WHERE page_id IN ({$placeholders}) AND agg_synced_at IS NOT NULL",
					$chunk
				)
			);
			if ( false !== $result ) {
				$updated += (int) $result;
			}
		}

		if ( $updated > 0 ) {
			// Drain the recompute in the background too, in case no admin list
			// render (inline sync) happens soon after the repair.
			$this->schedule_heatmap_aggregate_sync( 30 );
		}

		return $updated;
	}

	/**
	 * Compute DB total / file total / desynced for a set of pages, reading the
	 * DB side through the same canonical bridge the list/detail pages already
	 * use (spec §1: get_canonical_db_device_counts(), Pro's recordings-scope +
	 * guest-visitor + reset-timestamp default view; null on Free-only installs)
	 * and the file side via a filesystem scan.
	 *
	 * Pure computation: does not touch the transient cache or the advisory
	 * lock, so it is safely reusable by both the cron batch and the manual
	 * Repair AJAX handler — the caller owns the lock.
	 *
	 * @since 1.8.x
	 * @param array $page_ids Page IDs.
	 * @return array<int,array{db_total:int|null,file_total:int,desynced:bool,checked_at:int}>
	 */
	private function compute_heatmap_sync_status_for_pages( array $page_ids ) {
		$page_ids = array_values( array_unique( array_filter( array_map( 'absint', $page_ids ) ) ) );
		if ( empty( $page_ids ) ) {
			return array();
		}

		$db_map = $this->get_canonical_db_device_counts( $page_ids );

		// All-visitors DB totals (unified list/detail metric): the Y side of
		// the "X / Y" pair both surfaces display. One batched query; null map
		// on Free-only installs (entries then omit db_total_all — additive).
		$db_all_map = $this->get_all_visitors_db_session_totals( $page_ids );

		$now    = time();
		$result = array();
		foreach ( $page_ids as $page_id ) {
			$db_total = null;
			if ( is_array( $db_map ) && isset( $db_map[ $page_id ]['total'] ) ) {
				$db_total = (int) $db_map[ $page_id ]['total'];
			}

			$db_total_all = null;
			if ( is_array( $db_all_map ) && array_key_exists( $page_id, $db_all_map ) ) {
				$db_total_all = (int) $db_all_map[ $page_id ];
			}

			$file_counts = $this->count_heatmap_file_sessions_for_page_by_device( $page_id );
			$file_total  = (int) $file_counts['total'];
			$file_total_all = isset( $file_counts['total_all'] ) ? (int) $file_counts['total_all'] : (int) $file_counts['total'];
			$orphan_total_all = isset( $file_counts['orphan_total_all'] ) ? (int) $file_counts['orphan_total_all'] : 0;

			$desynced = ( null !== $db_total && $db_total > 0 )
				? $file_total > $db_total
				: $file_total > 0;

			// Honest Y (spec §3.3, RC-D): detected sessions = DB sessions UNION
			// orphan file sessions (a file session with NO DB trace at all IS a
			// detected session; cleanup/retention deleting its DB rows does not
			// un-detect it). max() enforces the Y >= X invariant even for the
			// edge case where a file session matches a DB session session_pages
			// no longer attributes to this page_id (sibling/base-URL matches).
			// Null (not 0) when db_total_all itself is unavailable (Free-only
			// install, no Pro batch DB helper) — additive, same nullability
			// pattern as db_total_all; consumers fall back to legacy behavior.
			$detected_total_all = null;
			if ( null !== $db_total_all ) {
				$detected_total_all = max( $db_total_all + $orphan_total_all, $file_total_all );
			}

			$result[ $page_id ] = array(
				'db_total'       => $db_total,
				'file_total'     => $file_total,
				// All-visitors (G + L) deduped file session count (additive):
				// backs the detail header's event-coverage pill, which counts
				// every visitor type unlike the guest-scoped file_total.
				'file_total_all' => $file_total_all,
				// All-visitors DB session total (additive, unified metric):
				// the Y next to file_total_all's X on both the list cell and
				// the detail pill; lets the Repair AJAX response repaint the
				// list cell with the same pair the server renders.
				'db_total_all'   => $db_total_all,
				// Deduped all-visitors file sessions with NO trace at all in
				// wp_optibehavior_sessions (additive, spec §3.3): the part of
				// file_total_all that db_total_all structurally cannot see.
				'orphan_total_all'   => $orphan_total_all,
				// Honest Y = max( db_total_all + orphan_total_all, file_total_all ),
				// additive, null when db_total_all is unavailable. This is the
				// TRUE "detected sessions" total: DB sessions UNION orphan file
				// sessions. Guarantees detected_total_all >= file_total_all, so
				// a fresh entry can never show the "X > Y" impossible state.
				'detected_total_all' => $detected_total_all,
				// Per-device file split (additive, since 1.9.0): lets the list
				// overlay replace stale agg_sessions_desktop/mobile values when
				// the canonical DB total is 0 (Repair durability).
				'file_devices'   => $file_counts['devices'],
				// All-visitors per-device file split (additive, list-device-
				// button fix): the SAME universe as file_total_all /
				// detected_total_all — sum( file_devices_all ) == file_total_all.
				// Lets the list row's Desktop/Mobile buttons partition the exact
				// universe the row's session cell shows, instead of a DB-only
				// or guest-only split. Precomputed here (reconcile batch / cron
				// / Repair), never scanned in the render path.
				'file_devices_all' => isset( $file_counts['devices_all'] ) ? $file_counts['devices_all'] : $file_counts['devices'],
				// Guest-scoped legacy desync flag: internal fallback logic only
				// (get_heatmap_session_counts_with_sync_status() truth table).
				// Surfaces (list badge / detail pill) no longer render a badge
				// from this flag — see 'desynced_all' below.
				'desynced'       => $desynced,
				// All-visitors desync (spec §3.3): file_total_all > detected_total_all.
				// False by construction on every freshly computed entry (the
				// max() above enforces detected_total_all >= file_total_all);
				// can only be true when a surface compares this entry's
				// file_total_all against a STALE detected_total_all read from a
				// mixed-generation cache read — i.e. a genuinely repairable
				// state, not the pre-fix "orphan files always desynced" bug.
				// Null when detected_total_all itself is unavailable.
				'desynced_all'   => ( null !== $detected_total_all ) ? ( $file_total_all > $detected_total_all ) : null,
				'checked_at'     => $now,
			);
		}

		return $result;
	}

	/**
	 * Count unique interaction sessions across all devices/folders for one
	 * page directly from heatmap JSON files (glob + token parse, deduped by
	 * session token — spec §3b), applying the same guest-visitor and
	 * reset-timestamp defaults the existing file-based fallback uses elsewhere
	 * in this class so the number stays comparable to the DB total.
	 *
	 * @since 1.8.x
	 * @param int $page_id Page ID.
	 * @return int
	 */
	private function count_heatmap_file_sessions_for_page( $page_id ) {
		$counts = $this->count_heatmap_file_sessions_for_page_by_device( $page_id );

		return (int) $counts['total'];
	}

	/**
	 * Same file-derived unique-session count as
	 * {@see count_heatmap_file_sessions_for_page()}, but additionally split per
	 * device bucket (desktop/mobile/tablet, from filename part 4 via
	 * {@see normalize_heatmap_device_key()}).
	 *
	 * The per-device sets are deduped independently (a session seen on two
	 * devices counts once in each device bucket, mirroring the existing live
	 * per-device file scans), while 'total' stays deduped across ALL devices —
	 * identical to the pre-existing single-total computation.
	 *
	 * Needed so the list's Repair/overlay path (plan: "Make Repair durable")
	 * can replace stale agg_sessions_desktop/mobile values with file-derived
	 * device counts when the canonical DB total is 0.
	 *
	 * 'total_all' (additive, since 1.9.x) is the same deduped cross-device
	 * session count WITHOUT the guest gate — all visitor types (G + L) — for
	 * the detail header's all-visitors event-coverage pill. The same reset-
	 * timestamp and spam-allowlist gates still apply, so it stays comparable
	 * to the canonical DB all-visitors total.
	 *
	 * 'devices_all' (additive, list-device-button fix): the SAME all-visitors,
	 * orphan-aware universe as 'total_all' (not the guest-only 'devices'
	 * split above), bucketed per device. Sum( devices_all ) == total_all
	 * always (normalize_heatmap_device_key() has no "Unknown" fallback —
	 * unparseable device tokens land in 'desktop', mirroring Pro's
	 * normalize_heatmap_device_bucket()), so this is what list-row device
	 * buttons must render against to sum to the row's session cell.
	 *
	 * @since 1.9.0
	 * @param int $page_id Page ID.
	 * @return array{total:int,total_all:int,devices:array{desktop:int,mobile:int,tablet:int},devices_all:array{desktop:int,mobile:int,tablet:int}}
	 */
	private function count_heatmap_file_sessions_for_page_by_device( $page_id ) {
		$empty   = array(
			'total'            => 0,
			'total_all'        => 0,
			'orphan_total_all' => 0,
			'devices'          => array(
				'desktop' => 0,
				'mobile'  => 0,
				'tablet'  => 0,
			),
			'devices_all'      => array(
				'desktop' => 0,
				'mobile'  => 0,
				'tablet'  => 0,
			),
		);
		$page_id = absint( $page_id );
		if ( ! $page_id ) {
			return $empty;
		}

		$upload_dir      = wp_upload_dir();
		$base_upload_dir = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';
		$url_hash_dirs   = $this->get_heatmap_file_dirs_for_page( $page_id, $base_upload_dir );
		if ( empty( $url_hash_dirs ) ) {
			return $empty;
		}

		// Use the product-wide default (Traffic Behavior setting), not the
		// request-scoped $GLOBALS override: this runs from cron/AJAX outside
		// the normal dashboard request, where that global is never set.
		$spam_excluded    = $this->get_default_spam_exclusion_enabled();
		$allowed_sessions = array();
		if ( $spam_excluded ) {
			$allowed_sessions = $this->get_allowed_heatmap_session_lookup_for_page( $page_id );
			// RC-C: an empty (or partial) allow-list no longer short-circuits
			// to zero — file sessions with NO trace at all in the DB are
			// orphans and must still be counted; see
			// is_heatmap_session_allowed_or_orphan() below.
		}

		$reset_ts = $this->get_heatmap_reset_timestamp_for_page( $page_id );

		$sessions                 = array();
		$all_visitor_sessions     = array();
		$orphan_sessions_all      = array();
		$device_sessions          = array(
			'desktop' => array(),
			'mobile'  => array(),
			'tablet'  => array(),
		);
		$device_sessions_all      = array(
			'desktop' => array(),
			'mobile'  => array(),
			'tablet'  => array(),
		);
		foreach ( $url_hash_dirs as $url_hash_dir ) {
			foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder_name ) {
				$dir   = trailingslashit( $url_hash_dir ) . $folder_name;
				$files = $this->glob_heatmap_dir_files( $dir );
				if ( empty( $files ) ) {
					continue;
				}

				foreach ( $files as $file ) {
					$filename = basename( $file );
					$parts    = explode( '_', str_replace( '.json', '', $filename ) );
					if ( count( $parts ) < 6 ) {
						continue;
					}

					$file_ts = intval( $parts[0] );
					if ( $reset_ts && $file_ts < $reset_ts ) {
						continue;
					}

					$session = Opti_Behavior_Heatmap_Storage::parse_session_token( $parts );

					// Strict membership (not is_heatmap_session_allowed()'s "empty
					// lookup = unrestricted" shortcut): a page whose allow-list
					// legitimately resolved to zero sessions must still fall
					// through to the known-session check below, not admit a
					// known-spam session unconditionally (RC-C).
					$is_allowed = ! $spam_excluded || $this->is_heatmap_session_in_lookup( $session, $allowed_sessions );
					if ( ! $is_allowed ) {
						$known_sessions = $this->get_known_heatmap_session_lookup_for_page( $page_id, $base_upload_dir );
						if ( $this->is_heatmap_session_known( $session, $known_sessions ) ) {
							continue; // Known DB session, not allow-listed: genuine spam — filtered.
						}
					}

					// All-visitors coverage (detail header pill): every visitor
					// type counts here, before the guest gate below.
					$all_visitor_sessions[ $session ] = true;
					if ( ! $is_allowed ) {
						$orphan_sessions_all[ $session ] = true;
					}

					// All-visitors device split (list device buttons, spec:
					// "Heatmaps LIST page device buttons must share the row's
					// session-cell file universe"): bucketed on the SAME
					// admission ( allowed-or-orphan ) as total_all, before the
					// guest gate below — so sum( devices_all ) == total_all,
					// matching the row's session cell exactly.
					$device_all = $this->normalize_heatmap_device_key( isset( $parts[4] ) ? $parts[4] : '' );
					$device_sessions_all[ $device_all ][ $session ] = true;

					// List page / desync comparison defaults to Guest Visitor:
					// only count guest (G) sessions so 'total' stays comparable
					// to the DB total's default filtered view.
					if ( 'G' !== Opti_Behavior_Heatmap_Storage::parse_visitor_marker( $parts ) ) {
						continue;
					}

					$sessions[ $session ] = true;

					$device = $this->normalize_heatmap_device_key( isset( $parts[4] ) ? $parts[4] : '' );
					$device_sessions[ $device ][ $session ] = true;
				}
			}
		}

		return array(
			'total'            => count( $sessions ),
			'total_all'        => count( $all_visitor_sessions ),
			'orphan_total_all' => count( $orphan_sessions_all ),
			'devices_all'      => array(
				'desktop' => count( $device_sessions_all['desktop'] ),
				'mobile'  => count( $device_sessions_all['mobile'] ),
				'tablet'  => count( $device_sessions_all['tablet'] ),
			),
			'devices'          => array(
				'desktop' => count( $device_sessions['desktop'] ),
				'mobile'  => count( $device_sessions['mobile'] ),
				'tablet'  => count( $device_sessions['tablet'] ),
			),
		);
	}

	/**
	 * Build the heatmap page list from precomputed indexed columns, or NULL.
	 *
	 * Returns the same per-row shape the file-scan source produces (one entry per
	 * mapping row, ordered by last_data_at DESC) so the existing sort / slice /
	 * format pipeline runs unchanged — but with zero per-request file IO. Returns
	 * NULL (caller falls back to the live file-scan path) whenever the request is
	 * NOT the all-time, default-spam-excluded view the columns represent:
	 *
	 *   - a date window is active (columns are all-time);
	 *   - the product default spam state is "include spam" (the displayed list then
	 *     keeps raw-fallback rows the columns do not model);
	 *   - the request toggled spam off the default (user decision #3 — that view
	 *     keeps its cached live calc);
	 *   - the columns/migration are unavailable, or disabled via filter.
	 *
	 * @since 1.6.7
	 * @param string|null $start_date Active start filter.
	 * @param string|null $end_date   Active end filter.
	 * @return array|null Per-row page data, or null to use the live path.
	 */
	private function maybe_fast_heatmap_pages_data( $start_date, $end_date ) {
		if ( null !== $start_date || null !== $end_date ) {
			return null;
		}
		$prep = $this->prepare_fast_heatmap_alltime_index();
		if ( null === $prep ) {
			return null;
		}
		return $this->fetch_fast_heatmap_pages_data_rows( $prep['unsynced_ids'] );
	}

	/**
	 * Shared preamble of the all-time indexed fast path (Task 3 split):
	 * eligibility gates, bounded inline resync, cron scheduling and the
	 * never-synced (RC6) subset decision — everything
	 * {@see maybe_fast_heatmap_pages_data()} used to do before its SELECT.
	 *
	 * Extracted so the SQL-paginated reader
	 * ({@see maybe_sql_paged_heatmap_rows()}) can share the exact same gating
	 * and side effects without duplicating them. Memoized per request/instance
	 * so a declined SQL attempt followed by the in-memory fast path does not
	 * run the inline sync budget twice.
	 *
	 * @since 1.9.x
	 * @return array|null Null when the indexed all-time path must not serve this
	 *                    request (caller falls back to the live pipeline);
	 *                    otherwise array{unsynced_ids: int[]} — the bounded
	 *                    never-synced subset to live-merge (possibly empty).
	 */
	private function prepare_fast_heatmap_alltime_index() {
		if ( false !== $this->hm_alltime_prep_memo ) {
			return $this->hm_alltime_prep_memo;
		}

		$prep = $this->prepare_fast_heatmap_alltime_index_uncached();
		$this->hm_alltime_prep_memo = $prep;
		return $prep;
	}

	/**
	 * Body of {@see prepare_fast_heatmap_alltime_index()} (memoized wrapper).
	 *
	 * @since 1.9.x
	 * @return array|null See wrapper.
	 */
	private function prepare_fast_heatmap_alltime_index_uncached() {
		if ( ! $this->heatmap_pages_aggregate_columns_exist() ) {
			return null;
		}
		if ( ! $this->get_default_spam_exclusion_enabled() || ! $this->is_spam_excluded() ) {
			return null;
		}
		if ( ! apply_filters( 'opti_behavior_heatmap_use_indexed_sort', true ) ) {
			return null;
		}

		// PRODUCTION SAFETY (C2-4): at customer scale a SINGLE evidence GROUP BY
		// over the pageviews table measured 44-54 s — the inline sync's wall-time
		// budget only bounds work BETWEEN chunks, so one chunk alone blew the
		// request budget (measured: heatmap list 171 s → 500 during the C2-4
		// crash-window re-run). When the pageviews table is large, NO aggregate
		// sync work runs inline in a web request: the list serves the current
		// agg_* values and WP-Cron drains the backlog (never-synced pages appear
		// as the cron catches up).
		$large_scale = false;
		if ( 'cli' !== PHP_SAPI ) {
			$sync_core     = Opti_Behavior_Heatmap_Core::get_instance();
			$sync_database = $sync_core ? $sync_core->get_database() : null;
			global $wpdb;
			$large_scale = $sync_database
				&& method_exists( $sync_database, 'is_large_table' )
				&& $sync_database->is_large_table( $wpdb->prefix . 'optibehavior_pageviews' );
		}

		// Make the columns exact for any pages changed since the last sync —
		// but NEVER let a cold sync block the render (Perf RC2): small batch,
		// hard wall-time budget, and the remainder is drained by WP-Cron while
		// the list is served from the current (slightly stale) agg_* values.
		if ( ! $large_scale ) {
			$inline_budget = (float) apply_filters( 'opti_behavior_heatmap_sync_inline_time_budget', 5.0 );
			$inline_batch  = (int) apply_filters( 'opti_behavior_heatmap_sync_inline_batch', 25 );
			$this->sync_heatmap_aggregate_columns( null, $inline_budget, $inline_batch );
		}

		$unsynced_ids = array();
		if ( $this->has_stale_heatmap_aggregate_rows() ) {
			$this->schedule_heatmap_aggregate_sync( 30 );

			// Large installs: never compute the unsynced subset live and never
			// fall back to the full O(N-files + pageviews-scan) live path — both
			// re-run the 44-54 s evidence queries inline. Fall through with an
			// empty merge set: the indexed rows are served as-is and the cron
			// worker converges the backlog within minutes.

			// Bootstrap guard (RC6, per-page): rows that were NEVER synced have
			// no serveable agg_* values (agg_has_data defaults to 0, which would
			// silently hide them from the list). Instead of discarding the WHOLE
			// indexed result for one such row — which on a continuously-ingesting
			// site made every request fall back to a full O(N) file scan — the
			// never-synced subset is computed live (bounded, see the merge below)
			// and merged with the indexed rows. Numbers stay exact: every page is
			// sourced from exactly one of the two exact computations.
			//
			// A true bootstrap (e.g. first request after the column migration,
			// where *everything* is unsynced) would make the merge as expensive
			// as the full scan. On SMALL installs (below the file threshold) keep
			// the original full live fallback for that case — it is fast at that
			// scale. On installs ABOVE the threshold, Task 2 forbids the full scan
			// entirely: the backlog branch below caps the merge and serves indexed.
			if ( ! $large_scale ) {
				$merge_max    = max( 1, (int) apply_filters( 'opti_behavior_heatmap_unsynced_merge_max', 150 ) );
				$unsynced_ids = $this->get_unsynced_heatmap_aggregate_page_ids( $merge_max + 1 );
				if ( count( $unsynced_ids ) > $merge_max ) {
					// Backlog exceeds the inline-merge cap. Task 2 (spec §P0/§9):
					// on an install above the file-scan threshold — or after the
					// self-measuring valve tripped — the all-time view must NEVER
					// fall back to the full O(files) live scan (68 s at 35k). Serve
					// the indexed agg_* rows as-is + bounded-merge only a capped
					// never-synced subset (exactly like the large-scale branch) and
					// let the WP-Cron drain converge the backlog within minutes.
					// Below the threshold (small install) the merge would cost as
					// much as a full scan (true bootstrap) and the live scan is
					// fast anyway, so keep the exact live fallback there.
					if ( $this->should_use_indexed_heatmap_alltime_path() ) {
						// Cron drain already scheduled above (has_stale branch).
						$unsynced_ids = array_slice( $unsynced_ids, 0, $merge_max );
					} else {
						return null;
					}
				}
			}
		}

		return array( 'unsynced_ids' => $unsynced_ids );
	}

	/**
	 * SELECT + row-shaping body of the all-time indexed fast path (Task 3
	 * split of {@see maybe_fast_heatmap_pages_data()}): reads the full agg_*
	 * row set and appends the live-computed never-synced subset (RC6 merge).
	 *
	 * @since 1.9.x
	 * @param int[] $unsynced_ids Bounded never-synced subset from
	 *                            {@see prepare_fast_heatmap_alltime_index()}.
	 * @return array Pages-data rows.
	 */
	private function fetch_fast_heatmap_pages_data_rows( $unsynced_ids ) {
		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_heatmap_pages';

		// One entry per mapping row (matches the file-source multiplicity & order).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Read of own aggregate columns; no user input in SQL.
		$rows = $wpdb->get_results(
			"SELECT page_id, agg_click_pc, agg_click_mobile, agg_break_pc, agg_break_mobile,
				agg_att_pc, agg_att_mobile, agg_sessions_desktop, agg_sessions_mobile,
				agg_sort_sessions, agg_last_event
			FROM {$table}
			WHERE ( click_count > 0 OR move_count > 0 OR scroll_count > 0 ) AND agg_has_data = 1
			ORDER BY last_data_at DESC"
		);

		$pages_data = array();
		foreach ( (array) $rows as $r ) {
			$pages_data[] = array(
				'page_id'          => (int) $r->page_id,
				'click_pc'         => (int) $r->agg_click_pc,
				'click_mobile'     => (int) $r->agg_click_mobile,
				'breakaway_pc'     => (int) $r->agg_break_pc,
				'breakaway_mobile' => (int) $r->agg_break_mobile,
				'attention_pc'     => (int) $r->agg_att_pc,
				'attention_mobile' => (int) $r->agg_att_mobile,
				'sessions_desktop' => (int) $r->agg_sessions_desktop,
				'sessions_mobile'  => (int) $r->agg_sessions_mobile,
				// UNIFIED-METRIC PARITY: the list Sessions value IS the guest
				// spam-excluded heatmap-file scope (agg_sessions universe), the
				// same number the device chips and the detail pill show. Sort key
				// == displayed value (desktop+mobile), so no sort/display mismatch.
				'sessions'         => (int) $r->agg_sessions_desktop + (int) $r->agg_sessions_mobile,
				'last_event_time'  => $r->agg_last_event,
			);
		}

		// RC6 merge: compute the (small) never-synced subset with the SAME live
		// file-scan aggregators the full fallback would have used, and append.
		// The indexed query above cannot contain these pages (agg_has_data = 0
		// until first sync), so the two sources are disjoint — no double count.
		if ( ! empty( $unsynced_ids ) ) {
			$pages_data = array_merge( $pages_data, $this->build_unsynced_heatmap_pages_data( $unsynced_ids ) );
		}

		// UNIFIED-METRIC PARITY: do NOT overlay canonical recording-DB session
		// counts here. The list Sessions column shows the guest spam-excluded
		// heatmap-file scope (agg_sessions), identical to the device chips and
		// the detail pill. Overlaying recording DB counts reintroduced the
		// "4 / 95" dual + desync badge and broke pill==chips==list parity.
		return $pages_data;
	}

	/**
	 * All-time SQL-paginated list reader (Task 3, spec §4-A.2): answers
	 * ORDER BY + LIMIT/OFFSET + COUNT directly against the agg_* columns so
	 * only the displayed rows (per_page) are ever hydrated, overlaid and
	 * rendered — O(per_page) PHP work instead of O(all pages).
	 *
	 * Correctness mapping onto the in-memory pipeline (byte-for-byte parity):
	 * - dedupe_heatmap_pages_data_by_page_id()  → GROUP BY hp.page_id. The agg
	 *   sync stamps IDENTICAL page-level totals on every sibling mapping row of
	 *   a page (never-synced siblings are excluded by agg_has_data = 1), so
	 *   MAX() returns that shared value — same result as the in-memory
	 *   exact-duplicate collapse.
	 * - filter_heatmap_pages_data_to_known_pages() → INNER JOIN pages p
	 *   (orphans excluded from rows AND the total, as before).
	 * - search → same title-OR-url LIKE the in-memory path issues.
	 * - usort() ordering → ORDER BY <agg expr> dir, hp.page_id ASC: the sort
	 *   expressions are the exact field sums the usort comparator computes, and
	 *   the secondary key reproduces its deterministic page_id-ASC tiebreak.
	 * - overlay_canonical_pageview_sessions() → run over the paged subset only;
	 *   per-page canonical values are independent of set membership, so each
	 *   displayed row gets the same number the full-set overlay stamped.
	 *
	 * Declines (returns null → caller falls back to the in-memory pipeline):
	 * - orderby 'sessions': its sort key is the canonical overlay value
	 *   (stamped post-overlay so sort key == displayed value); that value lives
	 *   in the analytics repository, not in the agg_* columns, so a SQL ORDER BY
	 *   cannot reproduce it.
	 * - the fast path itself is not applicable (dated view, non-default spam,
	 *   columns missing, filter off).
	 * - a never-synced (RC6) subset exists: those pages have no serveable agg_*
	 *   rows, and their bounded live merge changes global ordering — exact SQL
	 *   pagination resumes once the cron drains them (transition window, §9).
	 *
	 * @since 1.9.x
	 * @param string $orderby  Sort column (interactions|clicks|last_updated|sessions).
	 * @param string $order    asc|desc.
	 * @param int    $page     1-based page number.
	 * @param int    $per_page Rows per page.
	 * @param string $search   Optional title/url search term.
	 * @return array|null array(rows, total) or null when not applicable.
	 */
	private function maybe_sql_paged_heatmap_rows( $orderby, $order, $page, $per_page, $search = '' ) {
		global $wpdb;

		$sort_map = array(
			'interactions' => '(MAX(hp.agg_click_pc) + MAX(hp.agg_click_mobile) + MAX(hp.agg_break_pc) + MAX(hp.agg_break_mobile) + MAX(hp.agg_att_pc) + MAX(hp.agg_att_mobile))',
			'clicks'       => '(MAX(hp.agg_click_pc) + MAX(hp.agg_click_mobile))',
			'last_updated' => 'MAX(hp.agg_last_event)',
		);
		if ( ! isset( $sort_map[ $orderby ] ) ) {
			return null;
		}

		$prep = $this->prepare_fast_heatmap_alltime_index();
		if ( null === $prep || ! empty( $prep['unsynced_ids'] ) ) {
			return null;
		}

		$hp_table    = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$pages_table = $wpdb->prefix . 'optibehavior_pages';

		// Same row universe as the in-memory fast path: mapping rows with data
		// (click/move/scroll counters), aggregate-visible (agg_has_data = 1 is
		// the stored drop_empty/spam outcome), known page (INNER JOIN).
		$where  = '(hp.click_count > 0 OR hp.move_count > 0 OR hp.scroll_count > 0) AND hp.agg_has_data = 1';
		$params = array();
		if ( ! empty( $search ) ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND (p.title LIKE %s OR p.url LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$count_sql = "SELECT COUNT(DISTINCT hp.page_id)
			FROM {$hp_table} hp
			INNER JOIN {$pages_table} p ON p.id = hp.page_id
			WHERE {$where}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers hard-coded from $wpdb->prefix; user input prepared.
		$total = (int) $wpdb->get_var( empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, $params ) );
		if ( 0 === $total ) {
			return array( array(), 0 );
		}

		$direction = ( 'asc' === strtolower( (string) $order ) ) ? 'ASC' : 'DESC';
		$offset    = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		$rows_sql = "SELECT hp.page_id,
				MAX(hp.agg_click_pc) AS agg_click_pc,
				MAX(hp.agg_click_mobile) AS agg_click_mobile,
				MAX(hp.agg_break_pc) AS agg_break_pc,
				MAX(hp.agg_break_mobile) AS agg_break_mobile,
				MAX(hp.agg_att_pc) AS agg_att_pc,
				MAX(hp.agg_att_mobile) AS agg_att_mobile,
				MAX(hp.agg_sessions_desktop) AS agg_sessions_desktop,
				MAX(hp.agg_sessions_mobile) AS agg_sessions_mobile,
				MAX(hp.agg_last_event) AS agg_last_event
			FROM {$hp_table} hp
			INNER JOIN {$pages_table} p ON p.id = hp.page_id
			WHERE {$where}
			GROUP BY hp.page_id
			ORDER BY " . $sort_map[ $orderby ] . ' ' . $direction . ", hp.page_id ASC
			LIMIT %d OFFSET %d";
		$rows_params = array_merge( $params, array( max( 1, (int) $per_page ), $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers hard-coded; sort expr from fixed whitelist; user input prepared.
		$results = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ) );

		$pages_data_paged = array();
		foreach ( (array) $results as $r ) {
			$pages_data_paged[] = array(
				'page_id'          => (int) $r->page_id,
				'click_pc'         => (int) $r->agg_click_pc,
				'click_mobile'     => (int) $r->agg_click_mobile,
				'breakaway_pc'     => (int) $r->agg_break_pc,
				'breakaway_mobile' => (int) $r->agg_break_mobile,
				'attention_pc'     => (int) $r->agg_att_pc,
				'attention_mobile' => (int) $r->agg_att_mobile,
				'sessions_desktop' => (int) $r->agg_sessions_desktop,
				'sessions_mobile'  => (int) $r->agg_sessions_mobile,
				'sessions'         => (int) $r->agg_sessions_desktop + (int) $r->agg_sessions_mobile,
				'last_event_time'  => $r->agg_last_event,
			);
		}

		// Canonical sessions overlay over the DISPLAYED rows only — the 5-surface
		// Sessions parity invariant is preserved because per-page canonical values
		// are set-independent (same number the full-set overlay would stamp).
		$this->overlay_canonical_pageview_sessions( $pages_data_paged, null, null );

		return array( $this->build_heatmap_rows_for_display( $pages_data_paged, true, null, null ), $total );
	}

	/**
	 * Build fast-path-shaped rows for never-synced pages via the live file scan (RC6).
	 *
	 * Produces, for the given page ids only, exactly what the full live fallback
	 * would have produced for them:
	 *   - base rows from the same file source ({@see get_heatmap_pages_data_from_file_source()});
	 *   - exact metrics via {@see apply_heatmap_file_metrics_to_pages_data()} with
	 *     drop_empty (the indexed path's agg_has_data = 1 filter is the stored
	 *     output of the very same has_data flag);
	 *   - the Sessions SORT basis from {@see batch_recording_counts_by_device()},
	 *     mirroring how {@see sync_heatmap_aggregate_columns()} computes
	 *     agg_sort_sessions for the indexed rows. The caller's final
	 *     {@see overlay_canonical_session_counts()} pass then applies canonical
	 *     DB counts on top where available, same as for the indexed rows.
	 *
	 * Only called from the fast path, i.e. under the all-time,
	 * default-spam-excluded view.
	 *
	 * @since 1.8.2
	 * @param int[] $unsynced_ids Never-synced page ids (small, bounded set).
	 * @return array Pages-data rows for those ids (may be empty).
	 */
	private function build_unsynced_heatmap_pages_data( $unsynced_ids ) {
		$lookup = array();
		foreach ( (array) $unsynced_ids as $uid ) {
			$uid = absint( $uid );
			if ( $uid ) {
				$lookup[ $uid ] = true;
			}
		}
		if ( empty( $lookup ) ) {
			return array();
		}

		$subset = array();
		foreach ( (array) $this->get_heatmap_pages_data_from_file_source() as $row ) {
			if ( isset( $row['page_id'] ) && isset( $lookup[ (int) $row['page_id'] ] ) ) {
				$subset[] = $row;
			}
		}
		if ( empty( $subset ) ) {
			return array();
		}

		// Exact live metrics for the subset (spam filter is ON here — the fast
		// path only runs under the default-spam-excluded view). drop_empty
		// mirrors the indexed query's agg_has_data = 1 condition.
		$subset = $this->apply_heatmap_file_metrics_to_pages_data( $subset, null, null, true );
		if ( empty( $subset ) ) {
			return array();
		}

		// Sessions SORT basis parity: indexed rows carry agg_sort_sessions
		// (recording device counts, desktop + mobile). Recompute the same value
		// live for the subset. The final canonical overlay (run by the caller
		// over the merged set) may replace it where DB counts exist — exactly
		// as it does for the indexed rows.
		$subset_ids  = array();
		foreach ( $subset as $row ) {
			if ( isset( $row['page_id'] ) ) {
				$subset_ids[] = (int) $row['page_id'];
			}
		}
		$rec_desktop = $this->batch_recording_counts_by_device( $subset_ids, 'desktop', null, null );
		$rec_mobile  = $this->batch_recording_counts_by_device( $subset_ids, 'mobile', null, null );
		foreach ( $subset as &$row ) {
			$pid            = isset( $row['page_id'] ) ? (int) $row['page_id'] : 0;
			$row['sessions'] = ( isset( $rec_desktop[ $pid ] ) ? (int) $rec_desktop[ $pid ] : 0 )
				+ ( isset( $rec_mobile[ $pid ] ) ? (int) $rec_mobile[ $pid ] : 0 );
		}
		unset( $row );

		return array_values( $subset );
	}

	/**
	 * Reduce a pages_data set to the pages that CAN have events inside the
	 * recent stats window (Perf RC3).
	 *
	 * Exact superset filter: a page contributes to the "this week" windowed
	 * file pass only if it has an allowed event in the window — impossible
	 * when BOTH its last allowed event (agg_last_event) and its last ingest
	 * (last_data_at, always fresh) predate the window. A one-day safety
	 * margin absorbs any timezone skew between the two datetime sources.
	 * Numbers are unchanged: excluded pages would have produced no windowed
	 * rows; included pages are still computed exactly from their files.
	 *
	 * @since 1.7.0
	 * @param array  $pages_data   Full file-source pages data.
	 * @param string $window_start Window start date (Y-m-d).
	 * @return array Filtered pages data (same row shape/order).
	 */
	private function filter_heatmap_pages_data_to_window_candidates( $pages_data, $window_start ) {
		global $wpdb;

		if ( empty( $pages_data ) || ! $this->heatmap_pages_aggregate_columns_exist() ) {
			return $pages_data;
		}

		$table  = $wpdb->prefix . 'optibehavior_heatmap_pages';
		$cutoff = gmdate( 'Y-m-d', strtotime( $window_start . ' -1 day' ) ) . ' 00:00:00';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared; identifiers hard-coded.
		$candidate_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT page_id FROM {$table}
				WHERE (click_count > 0 OR move_count > 0 OR scroll_count > 0)
					AND ( ( agg_last_event IS NOT NULL AND agg_last_event >= %s )
						OR ( last_data_at IS NOT NULL AND last_data_at >= %s )
						OR agg_synced_at IS NULL )",
				$cutoff,
				$cutoff
			)
		);

		$candidates = array();
		foreach ( (array) $candidate_ids as $cid ) {
			$candidates[ (int) $cid ] = true;
		}

		$filtered = array();
		foreach ( (array) $pages_data as $row ) {
			if ( isset( $row['page_id'] ) && isset( $candidates[ (int) $row['page_id'] ] ) ) {
				$filtered[] = $row;
			}
		}

		return $filtered;
	}

	/**
	 * Parse a heatmap JSON filename.
	 *
	 * @param string $file File path.
	 * @return array
	 */
	private function parse_heatmap_file_name( $file ) {
		$filename = basename( $file );
		$parts    = explode( '_', str_replace( '.json', '', $filename ) );

		if ( count( $parts ) < 6 ) {
			return array();
		}

		return array(
			'timestamp' => intval( $parts[0] ),
			'device'    => $this->normalize_heatmap_device_key( $parts[4] ),
			'marker'    => Opti_Behavior_Heatmap_Storage::parse_visitor_marker( $parts ),
			'session'   => (string) end( $parts ),
		);
	}

	/**
	 * List the JSON files of a heatmap event folder, memoized per request
	 * (perf, RC4).
	 *
	 * The same (url_hash_dir, folder) pair is globbed by the token collector,
	 * the metrics loop and both per-device recording passes within one request;
	 * on Windows/NTFS each is_dir()+glob() round-trip is milliseconds, so the
	 * repeats dominated the file stage. One directory listing per request is a
	 * consistent snapshot — the original re-globs could only ever differ if a
	 * file appeared mid-request, in which case either result was acceptable.
	 *
	 * Callers that must observe on-disk deletions made after the memo was
	 * populated (the drift reconciler and the stale-page daily re-derivation)
	 * pass $fresh = true: the entry is re-globbed and the memo refreshed, so
	 * later same-request readers also see the corrected listing.
	 *
	 * @param string $dir   Folder path (no trailing slash).
	 * @param bool   $fresh Re-glob even when memoized (spec P4.2 correctness).
	 * @return array JSON file paths ('' when the folder does not exist).
	 */
	private function glob_heatmap_dir_files( $dir, $fresh = false ) {
		if ( $fresh || ! isset( $this->hm_glob_memo[ $dir ] ) ) {
			$this->hm_glob_memo[ $dir ] = is_dir( $dir ) ? (array) glob( $dir . '/*.json' ) : array();
		}
		return $this->hm_glob_memo[ $dir ];
	}

	/**
	 * Count interaction points stored in a heatmap JSON file.
	 *
	 * Fast path (zero IO): the point COUNT is encoded in the filename at token
	 * index 3 by Opti_Behavior_Heatmap_Storage::build_filename() and rebuilt on
	 * every merge, so filename count == decoded count by construction. Every
	 * historical filename format (6/7/8/10/14 parts) carries the count at the
	 * same position — see parse_filename_metadata(). Skipping stat + read +
	 * decode here turns the aggregate passes from O(bytes) into O(1) per file.
	 *
	 * The read-decode path below survives only as a fallback for legacy or
	 * malformed filenames, and is gated by the shared max-read-bytes size guard
	 * so an oversized file can never exhaust PHP memory (previously a fatal on
	 * the unbounded file_get_contents()).
	 *
	 * @param string $file File path.
	 * @return int
	 */
	private function count_heatmap_file_points( $file ) {
		$parts = explode( '_', str_replace( '.json', '', basename( $file ) ) );
		if ( count( $parts ) >= 6 && ctype_digit( (string) $parts[3] ) ) {
			return (int) $parts[3];
		}

		// Legacy/malformed filename fallback: memoize by path + size + mtime so
		// the same JSON file is decoded once per request even though it is
		// scanned by several aggregate passes.
		$stat     = @stat( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Race with cleanup jobs deleting files.
		$memo_key = $file . '|' . ( is_array( $stat ) ? $stat['size'] . '|' . $stat['mtime'] : '0|0' );
		if ( isset( $this->hm_points_memo[ $memo_key ] ) ) {
			return $this->hm_points_memo[ $memo_key ];
		}

		// Size guard: never read an unbounded file into memory (logs once).
		if ( Opti_Behavior_Heatmap_Storage::file_exceeds_read_limit( $file ) ) {
			return $this->hm_points_memo[ $memo_key ] = 0;
		}

		$content = file_get_contents( $file );
		if ( false === $content || '' === $content ) {
			return $this->hm_points_memo[ $memo_key ] = 0;
		}

		$payload = json_decode( $content, true );
		if ( ! is_array( $payload ) ) {
			return $this->hm_points_memo[ $memo_key ] = 0;
		}

		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			return $this->hm_points_memo[ $memo_key ] = count( $payload['data'] );
		}

		return $this->hm_points_memo[ $memo_key ] = 1;
	}

		/**
		 * Batch query to get recording counts by device for multiple pages
		 *
		 * @param array $page_ids Array of page IDs
		 * @param string $device Device type ('desktop' or 'mobile')
		 * @param string|null $start_date Optional start date filter
		 * @param string|null $end_date Optional end date filter
		 * @return array Map of page_id => recording count
		 */
	private function batch_recording_counts_by_device( $page_ids, $device = 'desktop', $start_date = null, $end_date = null ) {
		global $wpdb;

		if ( empty( $page_ids ) ) {
			return array();
		}

		$target_device_key = $this->normalize_heatmap_device_key( $device );
		$cache_parts       = $this->heatmap_aggregate_cache_parts( $page_ids, $start_date, $end_date, 'dev:' . $target_device_key );
		$memo_key          = 'r|' . $cache_parts['sig'];
		if ( isset( $this->hm_recording_memo[ $memo_key ] ) ) {
			return $this->hm_recording_memo[ $memo_key ];
		}
		$transient_key = function_exists( 'opti_behavior_heatmap_cache_key' )
			? opti_behavior_heatmap_cache_key( 'rec', $cache_parts['parts'] )
			: '';
		if ( $transient_key && ! $this->should_force_refresh_dashboard_cache() ) {
			$cached = get_transient( $transient_key );
			if ( is_array( $cached ) ) {
				$this->hm_recording_memo[ $memo_key ] = $cached;
				return $cached;
			}
		}

		// Canonical parity (Pro active): the Heatmaps list must show the same
		// per-page/device session counts as the detail page's default filtered
		// view (recordings scope + guest visitors + reset exclusion). Resolve
		// the batched DB counts once; pages with a DB total fall through to it,
		// while pages with no DB rows still use the file-based fallback below
		// (mirrors get_recordings_by_device()'s DB-then-file decision per page).
		$canonical_map = $this->get_canonical_db_device_counts( $page_ids, $start_date, $end_date );

		$map             = array();
		$upload_dir      = wp_upload_dir();
		$base_upload_dir = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';
		$target_device   = $this->normalize_heatmap_device_key( $device );
		$start_ts        = $start_date ? strtotime( $start_date ) : null;
		$end_ts          = $end_date ? strtotime( $end_date ) : null;

		// Perf (RC3): batch the file-dir resolution too (2 chunked queries
		// instead of 2 round-trips per page in the loop below).
		$this->prefetch_heatmap_file_dirs_for_pages( $page_ids, $base_upload_dir );

		// Perf (RC1/RC4): batch-resolve spam allow-lists once for the whole page
		// set, restricted to sessions matching these pages' file tokens (this
		// loop only tests file-parsed tokens — outcome-identical, see resolver).
		if ( $this->is_spam_excluded() ) {
			$this->get_allowed_heatmap_session_lookup_for_pages(
				$page_ids,
				$this->collect_heatmap_file_session_tokens_for_pages( $page_ids, $base_upload_dir )
			);
		}

		foreach ( $page_ids as $page_id ) {
			$page_id = absint( $page_id );
			if ( ! $page_id ) {
				continue;
			}

			// Canonical DB count wins whenever the page has recording-backed
			// sessions, matching the detail page exactly.
			if ( null !== $canonical_map && ! empty( $canonical_map[ $page_id ]['total'] ) ) {
				$map[ $page_id ] = isset( $canonical_map[ $page_id ][ $target_device_key ] )
					? (int) $canonical_map[ $page_id ][ $target_device_key ]
					: 0;
				continue;
			}

			$spam_excluded    = $this->is_spam_excluded();
			$allowed_sessions = array();
			if ( $spam_excluded ) {
				$allowed_sessions = $this->get_allowed_heatmap_session_lookup_for_page( $page_id );
				// RC-C: an empty (or partial) allow-list no longer forces a
				// zero count — file sessions the DB has no trace of at all
				// are orphans and must still be counted (see
				// is_heatmap_session_allowed_or_orphan()/is_heatmap_session_known() below).
			}

			$url_hash_dirs = $this->get_heatmap_file_dirs_for_page( $page_id, $base_upload_dir );
			if ( empty( $url_hash_dirs ) ) {
				$map[ $page_id ] = 0;
				continue;
			}

			// File-based fallback (Free-only installs, or pages without DB
			// recordings). Apply the same "Guest Visitor" default and heatmap
			// reset-timestamp exclusion the detail page uses so both surfaces
			// agree even without Pro's DB path.
			$reset_ts = $this->get_heatmap_reset_timestamp_for_page( $page_id );

			$sessions = array();
			foreach ( $url_hash_dirs as $url_hash_dir ) {
				foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder_name ) {
					$dir   = trailingslashit( $url_hash_dir ) . $folder_name;
					$files = $this->glob_heatmap_dir_files( $dir );
					if ( empty( $files ) ) {
						continue;
					}

					foreach ( $files as $file ) {
						$filename = basename( $file );
						$parts    = explode( '_', str_replace( '.json', '', $filename ) );
						if ( count( $parts ) < 6 ) {
							continue;
						}

						$file_ts     = intval( $parts[0] );
						$file_device = $this->normalize_heatmap_device_key( $parts[4] );
						$session     = Opti_Behavior_Heatmap_Storage::parse_session_token( $parts );

						if ( $file_device !== $target_device ) {
							continue;
						}
						if ( $start_ts && $file_ts < $start_ts ) {
							continue;
						}
						if ( $end_ts && $file_ts > $end_ts ) {
							continue;
						}
						if ( $reset_ts && $file_ts < $reset_ts ) {
							continue;
						}
						// Detail page defaults to Guest Visitor: count only guest (G)
						// sessions so the list matches that view (logged-in users
						// can see different page content).
						if ( 'G' !== Opti_Behavior_Heatmap_Storage::parse_visitor_marker( $parts ) ) {
							continue;
						}
						// Strict membership (not is_heatmap_session_allowed()'s "empty
						// lookup = unrestricted" shortcut): a page whose allow-list
						// legitimately resolved to zero sessions must still fall
						// through to the known-session check below, not admit a
						// known-spam session unconditionally (RC-C).
						if ( $spam_excluded && ! $this->is_heatmap_session_in_lookup( $session, $allowed_sessions ) ) {
							$known_sessions = $this->get_known_heatmap_session_lookup_for_page( $page_id, $base_upload_dir );
							if ( $this->is_heatmap_session_known( $session, $known_sessions ) ) {
								continue; // Known DB session, not allow-listed: genuine spam — filtered.
							}
						}

						$sessions[ $session ] = true;
					}
				}
			}

			$map[ $page_id ] = count( $sessions );
		}

		$this->hm_recording_memo[ $memo_key ] = $map;
		if ( $transient_key ) {
			set_transient( $transient_key, $map, opti_behavior_heatmap_cache_ttl() );
		}

		return $map;
	}

	/**
	 * Get canonical per-page device session counts from the Pro DB path.
	 *
	 * Delegates to the detail page's own batched counting service so the list
	 * page shows numbers identical to the detail page's default filtered view
	 * (recordings scope + guest visitors + heatmap reset exclusion). Returns
	 * null when Pro is inactive or the session_pages table is unavailable, so
	 * the caller keeps its file-based fallback.
	 *
	 * @param array       $page_ids   Page IDs.
	 * @param string|null $start_date Optional start date.
	 * @param string|null $end_date   Optional end date.
	 * @return array|null Map of page_id => array('desktop','mobile','tablet','total'), or null.
	 */
	private function get_canonical_db_device_counts( $page_ids, $start_date = null, $end_date = null ) {
		if ( empty( $page_ids ) ) {
			return null;
		}

		if ( ! class_exists( 'Opti_Behavior_Heatmap_Ajax', false )
			|| ! method_exists( 'Opti_Behavior_Heatmap_Ajax', 'get_count_helper' )
			|| ! method_exists( 'Opti_Behavior_Heatmap_Ajax', 'get_batch_session_page_device_counts' ) ) {
			return null;
		}

		$normalized = array_values( array_unique( array_filter( array_map( 'absint', (array) $page_ids ) ) ) );
		if ( empty( $normalized ) ) {
			return null;
		}
		sort( $normalized );

		$memo_key = implode( ',', $normalized ) . '|' . (string) $start_date . '|' . (string) $end_date;
		if ( array_key_exists( $memo_key, $this->hm_canonical_memo ) ) {
			return $this->hm_canonical_memo[ $memo_key ];
		}

		$helper = Opti_Behavior_Heatmap_Ajax::get_count_helper();
		if ( ! $helper ) {
			return $this->hm_canonical_memo[ $memo_key ] = null;
		}

		$date_range = ( $start_date && $end_date ) ? 'custom' : 'all';
		$filters    = array(
			// Match the detail page's default "Guest Visitor" view so the list
			// headline numbers agree with the detail page on first load
			// (logged-in visitors can see different page content, so guest is
			// the default scope on both surfaces).
			'visitor_type' => 'guest',
			'exclude_spam' => $this->is_spam_excluded() ? 1 : 0,
		);
		if ( 'custom' === $date_range ) {
			$filters['start_date'] = $start_date;
			$filters['end_date']   = $end_date;
		}

		$map = $helper->get_batch_session_page_device_counts( $normalized, $date_range, $filters );
		if ( ! is_array( $map ) ) {
			return $this->hm_canonical_memo[ $memo_key ] = null;
		}

		// Shared session-count resolver (spec §1): additively attach
		// sessions_with_interactions/desynced from the same resolver the
		// detail page now uses, so the list can eventually show both numbers
		// and a desync badge. Passing $map as the known DB counts avoids a
		// second query — the resolver only reads the (currently empty, until
		// spec §3b ships) reconciliation cache on top of it.
		if ( method_exists( $helper, 'get_session_counts_with_sync_status' ) ) {
			$sync_status = $helper->get_session_counts_with_sync_status( $normalized, $date_range, $filters, $map );
			if ( is_array( $sync_status ) ) {
				foreach ( $normalized as $page_id ) {
					if ( ! isset( $sync_status[ $page_id ] ) ) {
						continue;
					}
					if ( ! isset( $map[ $page_id ] ) ) {
						continue;
					}
					$map[ $page_id ]['sessions_with_interactions'] = $sync_status[ $page_id ]['file_total'];
					$map[ $page_id ]['desynced']                   = (bool) $sync_status[ $page_id ]['desynced'];
				}
			}
		}

		return $this->hm_canonical_memo[ $memo_key ] = $map;
	}

	/**
	 * Overlay canonical DB session counts onto file-derived pages_data rows.
	 *
	 * When Pro is active the detail page counts sessions from the recordings DB
	 * scope, so the list's file-derived sessions_desktop/mobile/tablet columns
	 * must be replaced with the same canonical values. Pages without any
	 * recording-backed session keep their file-based counts (already
	 * reset filtered), mirroring get_recordings_by_device()'s per-page
	 * DB-then-file decision. Interaction columns are untouched.
	 *
	 * @param array       $pages_data Pages data with page_id + session columns.
	 * @param string|null $start_date Optional start date.
	 * @param string|null $end_date   Optional end date.
	 * @return array Pages data with canonical session columns applied where available.
	 */
	private function overlay_canonical_session_counts( $pages_data, $start_date = null, $end_date = null ) {
		if ( empty( $pages_data ) || ! is_array( $pages_data ) ) {
			return $pages_data;
		}

		$page_ids = array();
		foreach ( $pages_data as $page_data ) {
			if ( isset( $page_data['page_id'] ) ) {
				$page_ids[] = absint( $page_data['page_id'] );
			}
		}
		$page_ids = array_values( array_filter( array_unique( $page_ids ) ) );
		if ( empty( $page_ids ) ) {
			return $pages_data;
		}

		$canonical = $this->get_canonical_db_device_counts( $page_ids, $start_date, $end_date );
		if ( null === $canonical ) {
			return $pages_data;
		}

		foreach ( $pages_data as &$page_data ) {
			$page_id = isset( $page_data['page_id'] ) ? absint( $page_data['page_id'] ) : 0;
			if ( ! $page_id ) {
				continue;
			}

			// Dual-metric fields (spec §1/§2), additive: carried alongside the
			// existing 'sessions' column regardless of whether the canonical
			// total below is used, so a desynced-but-empty-DB page still gets
			// its badge data.
			if ( isset( $canonical[ $page_id ]['sessions_with_interactions'] ) ) {
				$page_data['sessions_with_interactions'] = $canonical[ $page_id ]['sessions_with_interactions'];
			}
			if ( isset( $canonical[ $page_id ]['desynced'] ) ) {
				$page_data['desynced'] = (bool) $canonical[ $page_id ]['desynced'];
			}

			if ( empty( $canonical[ $page_id ]['total'] ) ) {
				// Repair durability (plan: "Make Repair durable"): the DB says 0
				// sessions, so the resolver's total is the file-derived count.
				// The row's pre-computed values may come from the fast path's
				// STALE agg_* columns (counted before a data cleanup), which
				// otherwise win here forever ("11 / 8" reload-revert bug,
				// page_id=1) and break list-vs-detail parity (detail shows the
				// resolver total). When a fresh sync-status entry exists for the
				// page (sessions_with_interactions non-null — only attached on
				// the comparable default view) AND carries the per-device file
				// split, the resolver's numbers win. Fail open otherwise: no
				// cache entry (or a pre-1.9.0 entry without the device split)
				// keeps today's file-scan/agg values unchanged.
				if ( isset( $page_data['sessions_with_interactions'] )
					&& null !== $page_data['sessions_with_interactions'] ) {
					$file_devices = $this->get_cached_heatmap_sync_file_devices( $page_id );
					if ( is_array( $file_devices ) ) {
						$page_data['sessions_desktop'] = $file_devices['desktop'];
						$page_data['sessions_mobile']  = $file_devices['mobile'];
						$page_data['sessions_tablet']  = $file_devices['tablet'];
						$page_data['sessions']         = (int) $page_data['sessions_with_interactions'];
					}
				}
				continue;
			}

			$page_data['sessions_desktop'] = (int) $canonical[ $page_id ]['desktop'];
			$page_data['sessions_mobile']  = (int) $canonical[ $page_id ]['mobile'];
			$page_data['sessions_tablet']  = (int) $canonical[ $page_id ]['tablet'];
			$page_data['sessions']         = (int) $canonical[ $page_id ]['total'];
		}
		unset( $page_data );

		return $pages_data;
	}

	/**
	 * Resolve a page's heatmap reset timestamp (unix) for file-based exclusion.
	 *
	 * Honors "Delete Heatmap Data": file-based fallback counts must drop
	 * sessions captured before the page's reset, matching the DB path's
	 * reset clause.
	 *
	 * @param int $page_id Page ID.
	 * @return int Unix timestamp, or 0 when the page was never reset.
	 */
	private function get_heatmap_reset_timestamp_for_page( $page_id ) {
		if ( null === $this->hm_reset_ts_memo ) {
			$resets = get_option( 'opti_behavior_heatmap_page_resets', array() );
			$this->hm_reset_ts_memo = is_array( $resets ) ? $resets : array();
		}

		$page_id = absint( $page_id );
		if ( empty( $this->hm_reset_ts_memo[ $page_id ] ) ) {
			return 0;
		}

		$reset_ts = strtotime( (string) $this->hm_reset_ts_memo[ $page_id ] );

		return $reset_ts ? (int) $reset_ts : 0;
	}

	/**
	 * Resolve the "Delete Heatmap Data" reset floor (raw mysql datetime) for a
	 * heatmap page group — the most recent reset among the given page ids, so the
	 * canonical pageview-session Views / device chips / pill on the detail page
	 * drop sessions captured before the deletion, exactly like the file-scan and
	 * session_pages (build_session_page_reset_clause) paths already do.
	 *
	 * The raw stored string is returned (not a converted unix ts) so it compares
	 * against pv.view_time with the same timezone semantics the DB reset clause
	 * uses against sp.entry_time. Empty string when no page in the group was reset
	 * (callers then apply no floor — unchanged behaviour).
	 *
	 * @param int[] $page_ids Canonical page ids for the detail surface.
	 * @return string Raw mysql datetime, or '' when the group was never reset.
	 */
	private function get_heatmap_reset_floor_for_group( $page_ids ) {
		if ( null === $this->hm_reset_ts_memo ) {
			$resets                 = get_option( 'opti_behavior_heatmap_page_resets', array() );
			$this->hm_reset_ts_memo = is_array( $resets ) ? $resets : array();
		}

		$floor_ts  = 0;
		$floor_str = '';
		foreach ( (array) $page_ids as $pid ) {
			$pid = absint( $pid );
			if ( empty( $this->hm_reset_ts_memo[ $pid ] ) ) {
				continue;
			}
			$ts = strtotime( (string) $this->hm_reset_ts_memo[ $pid ] );
			if ( $ts && $ts > $floor_ts ) {
				$floor_ts  = $ts;
				$floor_str = (string) $this->hm_reset_ts_memo[ $pid ];
			}
		}

		return $floor_str;
	}

	/**
	 * Resolve the "Delete Heatmap Data" reset floors for a set of heatmap list
	 * pages — map of page id => raw mysql datetime, ONLY for pages that were
	 * reset. Sorted by page id so the map is stable for cache-key seeding.
	 * Used by the canonical list overlay / canonlist cron refresh to (a) floor
	 * the batched session counts like the detail pill and (b) rotate both
	 * canonlist cache keys whenever a floor changes.
	 *
	 * @param int[] $page_ids Listed page ids.
	 * @return array<int,string> page id => raw mysql datetime (may be empty).
	 */
	private function get_heatmap_reset_floors_for_pages( $page_ids ) {
		if ( null === $this->hm_reset_ts_memo ) {
			$resets                 = get_option( 'opti_behavior_heatmap_page_resets', array() );
			$this->hm_reset_ts_memo = is_array( $resets ) ? $resets : array();
		}

		$floors = array();
		foreach ( (array) $page_ids as $pid ) {
			$pid = absint( $pid );
			if ( ! $pid || empty( $this->hm_reset_ts_memo[ $pid ] ) ) {
				continue;
			}
			if ( strtotime( (string) $this->hm_reset_ts_memo[ $pid ] ) ) {
				$floors[ $pid ] = (string) $this->hm_reset_ts_memo[ $pid ];
			}
		}
		ksort( $floors );

		return $floors;
	}

	/**
	 * Normalize heatmap file device values to dashboard device buckets.
	 *
	 * @param string $device Raw device value.
	 * @return string
	 */
	private function normalize_heatmap_device_key( $device ) {
		$device = strtolower( trim( (string) $device ) );
		if ( 'phone' === $device ) {
			return 'mobile';
		}
		if ( in_array( $device, array( 'desktop', 'mobile', 'tablet' ), true ) ) {
			return $device;
		}
		return 'desktop';
	}

	/**
	 * Batch-resolve heatmap file directories for a whole page set (perf, RC3).
	 *
	 * get_heatmap_file_dirs_for_page() issues 2 SQL round-trips per page; on a
	 * windowed stats pass over hundreds of pages that alone dominates the cold
	 * time. This pre-filler executes the SAME two lookups as 2 chunked IN()
	 * queries and stores the per-page result (identical dir resolution logic,
	 * including the 3 md5 URL variants and is_dir() checks) into the same
	 * memo the per-page resolver reads, so displayed numbers cannot change.
	 *
	 * @param array  $page_ids        Page IDs.
	 * @param string $base_upload_dir Base heatmap upload directory.
	 * @return void
	 */
	private function prefetch_heatmap_file_dirs_for_pages( $page_ids, $base_upload_dir ) {
		global $wpdb;

		$missing = array();
		foreach ( (array) $page_ids as $page_id ) {
			$page_id = absint( $page_id );
			if ( $page_id && ! isset( $this->hm_dirs_memo[ $page_id ] ) ) {
				$missing[ $page_id ] = true;
			}
		}
		if ( empty( $missing ) ) {
			return;
		}
		$missing = array_keys( $missing );

		$hash_map = array();
		$url_map  = array();
		foreach ( array_chunk( $missing, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; placeholders built above.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT page_id, url_hash FROM {$wpdb->prefix}optibehavior_heatmap_pages WHERE page_id IN ({$placeholders}) ORDER BY id ASC", $chunk ) );
			foreach ( (array) $rows as $row ) {
				$pid = (int) $row->page_id;
				if ( ! isset( $hash_map[ $pid ] ) ) {
					$hash_map[ $pid ] = array();
				}
				// Accumulate ALL mapping rows for the page (a page can have
				// more than one, e.g. a stale QA URL hash alongside the live
				// one) - the file universe is the union, not the first row.
				$hash_map[ $pid ][] = (string) $row->url_hash;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; placeholders built above.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, url FROM {$wpdb->prefix}optibehavior_pages WHERE id IN ({$placeholders})", $chunk ) );
			foreach ( (array) $rows as $row ) {
				$pid = (int) $row->id;
				if ( ! isset( $url_map[ $pid ] ) ) {
					$url_map[ $pid ] = (string) $row->url;
				}
			}
		}

		foreach ( $missing as $page_id ) {
			$dirs = array();

			foreach ( (array) ( $hash_map[ $page_id ] ?? array() ) as $mapped_hash ) {
				if ( $mapped_hash && is_dir( $base_upload_dir . $mapped_hash ) ) {
					$dirs[] = $base_upload_dir . $mapped_hash;
				}
			}

			if ( ! empty( $url_map[ $page_id ] ) ) {
				$url = $url_map[ $page_id ];
				foreach ( array( md5( $url ), md5( rtrim( $url, '/' ) ), md5( $url . '/' ) ) as $url_hash ) {
					$dir = $base_upload_dir . $url_hash;
					if ( is_dir( $dir ) ) {
						$dirs[] = $dir;
					}
				}
			}

			$this->hm_dirs_memo[ $page_id ] = array_values( array_unique( $dirs ) );
		}
	}

	/**
	 * Resolve heatmap file directories for a page using the canonical mapping table first.
	 *
	 * @param int    $page_id         Page ID.
	 * @param string $base_upload_dir Base heatmap upload directory.
	 * @return array
	 */
	private function get_heatmap_file_dirs_for_page( $page_id, $base_upload_dir ) {
		global $wpdb;

		$memo_key = absint( $page_id );
		if ( isset( $this->hm_dirs_memo[ $memo_key ] ) ) {
			return $this->hm_dirs_memo[ $memo_key ];
		}

		$dirs          = array();
		$mapped_hashes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT url_hash FROM {$wpdb->prefix}optibehavior_heatmap_pages WHERE page_id = %d ORDER BY id ASC",
				$page_id
			)
		);

		foreach ( (array) $mapped_hashes as $mapped_hash ) {
			if ( $mapped_hash && is_dir( $base_upload_dir . $mapped_hash ) ) {
				$dirs[] = $base_upload_dir . $mapped_hash;
			}
		}

		$url = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT url FROM {$wpdb->prefix}optibehavior_pages WHERE id = %d",
				$page_id
			)
		);
		if ( $url ) {
			foreach ( array( md5( $url ), md5( rtrim( $url, '/' ) ), md5( $url . '/' ) ) as $url_hash ) {
				$dir = $base_upload_dir . $url_hash;
				if ( is_dir( $dir ) ) {
					$dirs[] = $dir;
				}
			}
		}

		$dirs = array_values( array_unique( $dirs ) );
		$this->hm_dirs_memo[ $memo_key ] = $dirs;

		return $dirs;
	}

	/**
	 * Collect the distinct session tokens present in the heatmap JSON files of a
	 * page set (perf, RC4).
	 *
	 * The allow-list consumers only ever test session tokens parsed from these
	 * very filenames, so this is the complete universe of tokens the lookup can
	 * be probed with. Tokens are collected across ALL files of the page (no date
	 * filter) because the resulting lookup is memoized per page and reused by
	 * every windowed pass.
	 *
	 * @param array  $page_ids        Page IDs.
	 * @param string $base_upload_dir Base heatmap upload directory.
	 * @return array Distinct session tokens (strings).
	 */
	private function collect_heatmap_file_session_tokens_for_pages( $page_ids, $base_upload_dir ) {
		$this->prefetch_heatmap_file_dirs_for_pages( $page_ids, $base_upload_dir );

		$tokens = array();
		foreach ( (array) $page_ids as $page_id ) {
			$page_id = absint( $page_id );
			if ( ! $page_id ) {
				continue;
			}

			if ( ! isset( $this->hm_page_file_tokens_memo[ $page_id ] ) ) {
				$page_tokens = array();
				foreach ( $this->get_heatmap_file_dirs_for_page( $page_id, $base_upload_dir ) as $url_hash_dir ) {
					foreach ( array( 'clicks', 'moves', 'scrolls' ) as $folder_name ) {
						$dir   = trailingslashit( $url_hash_dir ) . $folder_name;
						$files = $this->glob_heatmap_dir_files( $dir );
						if ( empty( $files ) ) {
							continue;
						}
						foreach ( $files as $file ) {
							$file_parts = $this->parse_heatmap_file_name( $file );
							if ( empty( $file_parts ) ) {
								continue;
							}
							$page_tokens[ (string) $file_parts['session'] ] = true;
						}
					}
				}
				$this->hm_page_file_tokens_memo[ $page_id ] = $page_tokens;
			}

			foreach ( $this->hm_page_file_tokens_memo[ $page_id ] as $token => $unused ) {
				$tokens[ $token ] = true;
			}
		}

		return array_map( 'strval', array_keys( $tokens ) );
	}

	/**
	 * Resolve the DB session ids that can match any of the given file tokens
	 * under is_heatmap_session_allowed()'s 3-form matching (perf, RC4).
	 *
	 * The lookup built by the batched resolver keys every allowed session id d
	 * as {d, clean(d), last8(clean(d))} and a file token t matches when
	 * {t, clean(t), last8(clean(t))} intersects those keys. This method returns
	 * exactly D = { d : triple(d) ∩ U ≠ ∅ } with U = ∪ triple(t) — i.e. every
	 * session that could ever match a token, and no other — via ONE scan of the
	 * (small) sessions table.
	 *
	 * Returns null when the restriction cannot be computed safely (form-set too
	 * large, matched-set too large, or SQL error e.g. REGEXP_REPLACE missing on
	 * MySQL < 8.0 / MariaDB < 10.0.5) — callers then fall back to the
	 * unrestricted scan, which is always correct.
	 *
	 * @param array $tokens File session tokens.
	 * @return array|null Session ids, empty array when no token can match, or
	 *                    null for "do not restrict".
	 */
	private function resolve_allowlist_session_ids_for_tokens( $tokens ) {
		global $wpdb;

		$forms = array();
		foreach ( (array) $tokens as $token ) {
			$token = (string) $token;
			if ( '' === $token ) {
				continue;
			}
			$clean                            = preg_replace( '/[^a-zA-Z0-9]/', '', $token );
			$forms[ $token ]                  = true;
			$forms[ $clean ]                  = true;
			$forms[ substr( $clean, -8 ) ]    = true;
			if ( count( $forms ) > 20000 ) {
				return null; // Too many candidate forms — unrestricted scan is cheaper/safer.
			}
		}
		$forms = array_map( 'strval', array_keys( $forms ) );
		if ( empty( $forms ) ) {
			return array();
		}

		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		$ph             = implode( ',', array_fill( 0, count( $forms ), '%s' ) );

		$prev_suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; all values parameterized.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$sessions_table}
				WHERE id IN ({$ph})
					OR REGEXP_REPLACE(id, '[^a-zA-Z0-9]', '') IN ({$ph})
					OR RIGHT(REGEXP_REPLACE(id, '[^a-zA-Z0-9]', ''), 8) IN ({$ph})",
				array_merge( $forms, $forms, $forms )
			)
		);
		$wpdb->suppress_errors( $prev_suppress );

		if ( ! empty( $wpdb->last_error ) ) {
			return null; // REGEXP_REPLACE unavailable or similar — fall back to unrestricted.
		}

		$ids = array_values( array_unique( array_map( 'strval', (array) $ids ) ) );
		if ( count( $ids ) > 5000 ) {
			return null; // IN() list would be huge — unrestricted indexed scan is fine.
		}

		return $ids;
	}

	/**
	 * Whether an evidence table has the `session_id` index (memoized per table).
	 *
	 * Used to decide if the RC4-restricted evidence queries may carry a
	 * FORCE INDEX (session_id) hint — forcing a non-existent index is an error.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function evidence_table_has_session_index( $table ) {
		global $wpdb;

		if ( ! isset( $this->hm_session_index_memo[ $table ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix.
			$rows = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'session_id'" );
			$this->hm_session_index_memo[ $table ] = ! empty( $rows );
		}

		return $this->hm_session_index_memo[ $table ];
	}

	/**
	 * Build a lookup of spam-filter-approved session IDs for a heatmap page.
	 *
	 * SPAM-FILTER POLICY (Task 4 convergence, deliberate divergence): heatmaps are
	 * PAGE-SCOPED interaction maps, not session-grain KPIs. They are INTENTIONALLY
	 * EXEMPT from the strict canonical session predicate
	 * (Opti_Behavior_Stats_Spam_Filter::session_sql()) that the Dashboard count
	 * tiles, Avg Session Time, Avg Scroll Depth and Funnels share. The count tiles
	 * hide a session outright when it fails the duration/scroll/click gate; a heatmap
	 * must instead keep every legitimate human interaction on the page visible so the
	 * click/scroll overlay is not silently emptied. This is consistent with the
	 * dashboard toggle: excluded traffic types (spam/bot/automated) are still removed
	 * by the WHERE clause and the duration threshold still applies, but the strict
	 * scroll+click AND-gate is relaxed by an OR-guard that admits human/unclassified
	 * sessions whose engagement evidence lives only in heatmap files. Net effect: a
	 * genuinely-spam session is filtered on both surfaces; a borderline human session
	 * may appear on its heatmap while being excluded from the session-grain totals —
	 * that asymmetry is by design, not a divergent/forgotten filter.
	 *
	 * The per-page beacon counters (session_pages.clicks_count / scroll_depth) can
	 * legitimately read 0 for a heatmap-only visit: that counter is written by the
	 * session-recording beacon, not the heatmap tracker (see handle_heatmap_event()
	 * reconciliation in the AJAX handler). So an engaged, non-spam human session whose
	 * real clicks live only in the heatmap files must NOT be dropped merely because
	 * those counters are 0. The HAVING clause OR-in a session-level guard: a session
	 * already past the duration gate and flagged human (or unclassified) is allowed;
	 * heatmap file presence for the (page, session) pair is the per-page proof.
	 * Bots/spam/automated stay filtered by the WHERE clause and the duration threshold.
	 * Regression coverage: tests/test-heatmap-zero-session-counters-visible.php.
	 *
	 * Page identity caveat (staging regression 2026-07-09, Bug C): heatmap file
	 * storage buckets by NORMALIZED URL (get_url_hash() strips query/fragment and
	 * the trailing slash), so one url_hash directory collects sessions whose
	 * session_pages rows point at MANY different page_ids — query-string variants
	 * (e.g. ?opti_ab_variant=2, ?utm_*, cache-busters) each get their own pages
	 * row, and a pages row can even be deleted+recreated under a new id while the
	 * old session_pages rows keep the stale page_id. Filtering the allow-list by a
	 * single sp.page_id therefore silently rejected every session attached to a
	 * sibling page_id and froze the dashboard on old data. The lookup now matches
	 * session evidence by page_id OR by the same URL normalization the file
	 * storage uses, so all sessions in the url_hash bucket are considered.
	 *
	 * @param int $page_id Page ID.
	 * @return array
	 */
	private function get_allowed_heatmap_session_lookup_for_page( $page_id ) {
		$memo_key = absint( $page_id );
		if ( isset( $this->hm_allowed_sessions_memo[ $memo_key ] ) ) {
			return $this->hm_allowed_sessions_memo[ $memo_key ];
		}

		// Delegate to the batched resolver (fills the memo for this page).
		$this->get_allowed_heatmap_session_lookup_for_pages( array( $memo_key ) );

		return isset( $this->hm_allowed_sessions_memo[ $memo_key ] ) ? $this->hm_allowed_sessions_memo[ $memo_key ] : array();
	}

	/**
	 * Public bridge (Free -> Pro): expose the Heatmaps list's spam allow-list
	 * lookup for a single page so Pro's detail-page file scan can gate on the
	 * SAME evidence the list already uses, instead of Pro's narrower
	 * session_pages-only query (see get_allowed_heatmap_session_lookup_for_pages()
	 * for the pageviews-fallback + base-URL-complement rationale). Mirrors the
	 * existing Pro -> Free bridge pattern (Opti_Behavior_Heatmap_Ajax::get_count_helper()),
	 * just in the opposite direction. Called via
	 * Opti_Behavior_Heatmap_Core::get_instance()->get_dashboard() so it reuses
	 * the live, already-hooked instance instead of constructing a new one.
	 *
	 * @since 1.0.8.31
	 * @param int $page_id Page ID.
	 * @return array Session-id lookup map (raw id / cleaned id / 8-char suffix => true).
	 */
	public function get_heatmap_session_allowlist_for_page_bridge( $page_id ) {
		return $this->get_allowed_heatmap_session_lookup_for_page( absint( $page_id ) );
	}

	/**
	 * Build the "known DB session" lookup for a page's heatmap file tokens
	 * (RC-C orphan gating, spec §3.2).
	 *
	 * The spam allow-list can only vouch for sessions the DB still has
	 * evidence for; a file whose DB rows were removed by cleanup/retention
	 * is invisible to it and gets silently dropped even though its JSON
	 * events are real. This resolves, for the page's own file tokens, which
	 * of them the DB still has ANY trace of at all (via
	 * resolve_allowlist_session_ids_for_tokens()'s 3-form matching against
	 * wp_optibehavior_sessions) — a token with NO trace is an orphan and
	 * must be admitted regardless of the allow-list (see
	 * is_heatmap_session_allowed_or_orphan()).
	 *
	 * @param int         $page_id         Page ID.
	 * @param string|null $base_upload_dir Optional precomputed base heatmap
	 *                                     upload dir (perf, avoids recompute).
	 * @return array|true 3-form lookup map (same key shape as the allow-list),
	 *                    or boolean `true` when the resolver could not
	 *                    restrict safely (fail-safe: every token is treated
	 *                    as known, i.e. no orphan admission — today's
	 *                    behavior for that page).
	 */
	private function get_known_heatmap_session_lookup_for_page( $page_id, $base_upload_dir = null ) {
		$memo_key = absint( $page_id );
		if ( isset( $this->hm_known_sessions_memo[ $memo_key ] ) ) {
			return $this->hm_known_sessions_memo[ $memo_key ];
		}

		if ( null === $base_upload_dir ) {
			$upload_dir      = wp_upload_dir();
			$base_upload_dir = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';
		}

		$page_tokens = $this->collect_heatmap_file_session_tokens_for_pages( array( $memo_key ), $base_upload_dir );
		$ids         = $this->resolve_allowlist_session_ids_for_tokens( $page_tokens );

		if ( null === $ids ) {
			// Fail-safe: cannot compute the restriction safely — treat every
			// token as a known DB session (no orphan admission for this page).
			$this->hm_known_sessions_memo[ $memo_key ] = true;
			return true;
		}

		$lookup = array();
		foreach ( $ids as $id ) {
			$id    = (string) $id;
			$clean = preg_replace( '/[^a-zA-Z0-9]/', '', $id );
			$lookup[ $id ]                  = true;
			$lookup[ $clean ]               = true;
			$lookup[ substr( $clean, -8 ) ] = true;
		}

		$this->hm_known_sessions_memo[ $memo_key ] = $lookup;
		return $lookup;
	}

	/**
	 * Public bridge (Free -> Pro): expose the "known DB session" lookup for a
	 * page's heatmap file tokens so Pro's file scanners (detail quick-stats,
	 * device counts, fast-path render, filter options) can admit orphan file
	 * sessions with the same evidence the Free dashboard's own orphan-aware
	 * gate uses (get_known_heatmap_session_lookup_for_page(), RC-C / spec
	 * §3.2). Mirrors get_heatmap_session_allowlist_for_page_bridge().
	 *
	 * @since 1.0.8.31
	 * @param int $page_id Page ID.
	 * @return array|true 3-form lookup map (session id / cleaned id / 8-char
	 *                     suffix => true), or boolean `true` (fail-safe:
	 *                     resolver could not restrict safely, treat every
	 *                     token as known, i.e. no orphan admission).
	 */
	public function get_heatmap_known_db_session_lookup_for_page_bridge( $page_id ) {
		return $this->get_known_heatmap_session_lookup_for_page( absint( $page_id ) );
	}

	/**
	 * Batched spam allow-list resolver (perf fix RC1, 2026-07-13).
	 *
	 * The per-page resolver ran TWO aggregate queries per heatmap page, each with an
	 * `OR <string-function-of(url)> = %s` predicate that forces a full scan of
	 * session_pages / pageviews. With ~1,740 heatmap pages that meant ~3,480 full
	 * table scans per dashboard request (measured 100–180 s on production, hitting
	 * the host's 180 s limit). This method resolves the allow-lists for a whole set
	 * of pages with TWO queries per 500-page chunk — the identical match predicate
	 * batched with IN() — and reproduces the original per-page GROUP BY/HAVING
	 * semantics in PHP:
	 *
	 *   - a row matches page P when row.page_id = P OR normalized(row.url) equals
	 *     P's normalized base URL (same Bug C bucket identity as before);
	 *   - per (page, session): MAX(scroll_depth) / SUM(clicks_count) over all
	 *     matched rows (sum split across finer SQL groups re-summed in PHP, so the
	 *     aggregates are byte-for-byte those of the old per-page query);
	 *   - identical duration gate, engagement AND-gate and human/unclassified
	 *     OR-guard (see the long rationale on the wrapper above);
	 *   - session_pages and pageviews evidence remain a UNION of allowed session
	 *     ids, exactly like the old array_merge of the two get_col results.
	 *
	 * Results populate $hm_allowed_sessions_memo — same memo the per-page wrapper
	 * reads — so all existing per-page call sites keep working unchanged.
	 *
	 * RC4 (2026-07-13): callers that only ever probe the lookup with session
	 * tokens parsed from the pages' heatmap file names may pass those tokens.
	 * The resolver then restricts the evidence scans to the DB sessions that can
	 * match ANY of those tokens (exact 3-form matching mirror, see
	 * resolve_allowlist_session_ids_for_tokens()). Outcome-identical for such
	 * callers: sessions dropped by the restriction can never satisfy
	 * is_heatmap_session_allowed() for any of the files, and a restricted-empty
	 * lookup makes the consumer skip the page — the same zero metrics the
	 * unrestricted lookup produces when no file token matches it. Displayed
	 * numbers cannot change; the evidence scans shrink from every row of the
	 * matched pages to a small indexed session_id IN () set.
	 *
	 * @param array      $page_ids            Page IDs to resolve.
	 * @param array|null $file_session_tokens Optional file session tokens for
	 *                                        evidence restriction (RC4).
	 * @return void
	 */
	private function get_allowed_heatmap_session_lookup_for_pages( $page_ids, $file_session_tokens = null ) {
		global $wpdb;

		$pending = array();
		foreach ( (array) $page_ids as $pid ) {
			$pid = absint( $pid );
			if ( $pid && ! isset( $this->hm_allowed_sessions_memo[ $pid ] ) ) {
				$pending[ $pid ] = true;
			}
		}
		$pending = array_keys( $pending );
		if ( empty( $pending ) ) {
			return;
		}

		// Perf (RC4): resolve the session-id restriction, when tokens provided.
		$restrict_ids = null;
		if ( is_array( $file_session_tokens ) ) {
			$restrict_ids = $this->resolve_allowlist_session_ids_for_tokens( $file_session_tokens );
			if ( is_array( $restrict_ids ) && empty( $restrict_ids ) ) {
				// No DB session can match any file token: every page's lookup is
				// empty (consumer skips the page — identical outcome to an
				// unrestricted lookup no file token matches). No scans needed.
				foreach ( $pending as $pid ) {
					$this->hm_allowed_sessions_memo[ $pid ] = array();
				}
				return;
			}
		}

		$session_pages_table = $wpdb->prefix . 'optibehavior_session_pages';
		$sessions_table      = $wpdb->prefix . 'optibehavior_sessions';
		$pageviews_table     = $wpdb->prefix . 'optibehavior_pageviews';

		// PRODUCTION SAFETY (C2-4): the evidence GROUP BY over pageviews measured
		// 44-54 s per statement at 2M rows during the crash-window (no covering
		// index yet). A web request must never hang on it — MySQL's
		// MAX_EXECUTION_TIME hint hard-caps the statement server-side; on the cap
		// the query returns an error, wpdb yields no rows, and the affected pages
		// simply render without allow-list evidence for this request (exact values
		// return once the background index build / agg-sync cron has caught up).
		// CLI and cron contexts run unhinted (their budgets are managed by the
		// callers).
		$exec_hint = '';
		if ( 'cli' !== PHP_SAPI && ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			$timeout_ms = (int) apply_filters( 'opti_behavior_allowlist_query_timeout_ms', 15000 );
			if ( $timeout_ms > 0 ) {
				$exec_hint = '/*+ MAX_EXECUTION_TIME(' . $timeout_ms . ') */ ';
			}
		}

		$traffic_settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_duration_threshold'    => 3,
				'spam_min_scrolls_threshold' => 0,
				'spam_min_clicks_threshold'  => 1,
			)
		);

		$spam_duration = isset( $traffic_settings['spam_duration_threshold'] ) ? max( 0, intval( $traffic_settings['spam_duration_threshold'] ) ) : 3;
		$spam_scrolls  = isset( $traffic_settings['spam_min_scrolls_threshold'] ) ? max( 0, intval( $traffic_settings['spam_min_scrolls_threshold'] ) ) : 0;
		$spam_clicks   = isset( $traffic_settings['spam_min_clicks_threshold'] ) ? max( 0, intval( $traffic_settings['spam_min_clicks_threshold'] ) ) : 1;

		// Same URL normalization the file storage bucket key uses (Bug C identity).
		$sp_base_expr = "LOWER(TRIM(TRAILING '/' FROM SUBSTRING_INDEX(SUBSTRING_INDEX(src.url, '#', 1), '?', 1)))";

		// Batch-resolve base URLs (2 IN() queries instead of up to 2 per page).
		$base_urls = $this->get_normalized_heatmap_page_base_urls( $pending );

		$allowed_sessions_by_page = array();
		foreach ( $pending as $pid ) {
			$allowed_sessions_by_page[ $pid ] = array();
		}

		// Chunk size 2000: the NOT IN() complement branch below full-covers the
		// candidate set only when the whole set fits one chunk, and 2000 int +
		// 2000 string placeholders are far below any packet/prepare limit.
		foreach ( array_chunk( $pending, 2000 ) as $chunk ) {
			$base_to_pages = array();
			foreach ( $chunk as $pid ) {
				$base = isset( $base_urls[ $pid ] ) ? strtolower( $base_urls[ $pid ] ) : '';
				if ( '' !== $base ) {
					$base_to_pages[ $base ][] = $pid;
				}
			}
			$bases = array_keys( $base_to_pages );

			// Perf (RC1b, 2026-07-13): the old single predicate
			//   `src.page_id IN (...) OR <normalized-url-expr> IN (...)`
			// forced a FULL scan evaluating the string-normalization expression
			// on EVERY evidence row. The GROUP BY key contains BOTH page_id and
			// base_url, so every row of a group shares them — the groups
			// therefore partition EXACTLY into:
			//   (1) groups whose page_id is in the chunk  → indexed IN() scan,
			//       no expression in the WHERE clause at all;
			//   (2) groups whose page_id is NOT in the chunk but whose base URL
			//       matches → the expression is only evaluated on rows that
			//       already failed the cheap integer NOT IN() check.
			// Union of (1)+(2) = the original result set, byte for byte; no
			// group is split or duplicated, so 'sum' aggregates cannot double.
			$id_ph      = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$match_ids  = 'src.page_id IN (' . $id_ph . ')';
			$match_base = '';
			$base_params = array();
			if ( ! empty( $bases ) ) {
				$base_ph     = implode( ',', array_fill( 0, count( $bases ), '%s' ) );
				$match_base  = "src.page_id NOT IN ({$id_ph}) AND {$sp_base_expr} IN ({$base_ph})";
				$base_params = array_merge( $chunk, $bases );
			}
			// Branch (2) carries a FORCE INDEX (page_id) hint: without it MySQL
			// prefers a full scan and evaluates the (expensive) URL expression on
			// every row BEFORE the cheap integer NOT IN() — the hint makes it walk
			// the complement ranges of the NOT IN() instead, so the expression only
			// ever runs on rows that are not already covered by branch (1).
			$matches = array( array( $match_ids, $chunk, '' ) );
			if ( '' !== $match_base ) {
				$matches[] = array( $match_base, $base_params, 'FORCE INDEX (page_id)' );
			}

			// Perf (RC1b, 2026-07-13): aggregate the evidence table FIRST in a
			// derived table (pure src columns) and join sessions ONLY on the
			// grouped result. The old shape joined sessions on EVERY raw
			// evidence row before grouping — 100k+ eq_ref lookups against the
			// varchar(64) utf8mb4 PK dominated the cold stats time (~10 s at
			// 100k pageviews). The GROUP BY keys already contained the session
			// id, and every s.* column is constant per session, so grouping
			// before or after the join produces the SAME groups with the SAME
			// aggregates — output rows are byte-identical, only the join count
			// drops from #evidence-rows to #groups.

			// Perf (RC4): when the token restriction resolved, every evidence
			// query also gets `AND src.session_id IN (...)` — an indexed lookup
			// that shrinks the scanned rows from all rows of the matched pages
			// to just the sessions that can match a heatmap file token. The
			// FORCE INDEX (session_id) hint replaces the branch hints because
			// the optimizer's page_id range estimates are unreliable here and
			// the session set is the tightest bound (only applied when the
			// index actually exists — forcing a missing index is an error).
			$session_pred   = '';
			$session_params = array();
			if ( is_array( $restrict_ids ) ) {
				$sid_ph         = implode( ',', array_fill( 0, count( $restrict_ids ), '%s' ) );
				$session_pred   = " AND src.session_id IN ({$sid_ph})";
				$session_params = $restrict_ids;
			}

			foreach ( $matches as $match_pair ) {
				list( $match, $params, $index_hint ) = $match_pair;

				$sp_hint = $index_hint;
				$pv_hint = $index_hint;
				if ( '' !== $session_pred ) {
					if ( $this->evidence_table_has_session_index( $session_pages_table ) ) {
						$sp_hint = 'FORCE INDEX (session_id)';
					}
					if ( $this->evidence_table_has_session_index( $pageviews_table ) ) {
						$pv_hint = 'FORCE INDEX (session_id)';
					}
				}
				$query_params = array_merge( $params, $session_params );

				// session_pages (Pro recording beacon) evidence.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; all user inputs parameterized.
				$sp_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT {$exec_hint}g.session_id, g.page_id, g.base_url, g.max_scroll, g.sum_clicks,
							(CASE WHEN s.duration > 0 THEN s.duration ELSE TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time)) END) AS eff_duration,
							s.traffic_type
						FROM (
							SELECT src.session_id, src.page_id,
								{$sp_base_expr} AS base_url,
								MAX(COALESCE(src.scroll_depth, 0)) AS max_scroll,
								SUM(COALESCE(src.clicks_count, 0)) AS sum_clicks
							FROM {$session_pages_table} src {$sp_hint}
							WHERE ( {$match} ){$session_pred}
							GROUP BY src.session_id, src.page_id, base_url
						) g
						INNER JOIN {$sessions_table} s ON s.id = g.session_id
						WHERE (s.traffic_type IS NULL OR s.traffic_type = '' OR s.traffic_type NOT IN ('spam', 'bot', 'automated'))",
						$query_params
					)
				);
				$this->distribute_allowed_session_rows( $sp_rows, $chunk, $base_to_pages, $allowed_sessions_by_page, 'sum', $spam_duration, $spam_scrolls, $spam_clicks );

				// FREE-ONLY FALLBACK (Bug R5-HM, 2026-07-09): pageviews evidence merged in —
				// identical traffic/duration gates; click evidence is the session-level
				// events_count counter (NOT summed across groups: session-level value).
				// Regression coverage: tests/test-heatmap-free-only-pageviews-allowlist.php.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; all user inputs parameterized.
				$pv_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT {$exec_hint}g.session_id, g.page_id, g.base_url, g.max_scroll,
							COALESCE(s.events_count, 0) AS sum_clicks,
							(CASE WHEN s.duration > 0 THEN s.duration ELSE TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time)) END) AS eff_duration,
							s.traffic_type
						FROM (
							SELECT src.session_id, src.page_id,
								{$sp_base_expr} AS base_url,
								MAX(COALESCE(src.scroll_depth, 0)) AS max_scroll
							FROM {$pageviews_table} src {$pv_hint}
							WHERE ( {$match} ){$session_pred}
							GROUP BY src.session_id, src.page_id, base_url
						) g
						INNER JOIN {$sessions_table} s ON s.id = g.session_id
						WHERE (s.traffic_type IS NULL OR s.traffic_type = '' OR s.traffic_type NOT IN ('spam', 'bot', 'automated'))",
						$query_params
					)
				);
				$this->distribute_allowed_session_rows( $pv_rows, $chunk, $base_to_pages, $allowed_sessions_by_page, 'max', $spam_duration, $spam_scrolls, $spam_clicks );
			}
		}

		foreach ( $pending as $pid ) {
			$lookup = array();
			foreach ( array_keys( $allowed_sessions_by_page[ $pid ] ) as $session_id ) {
				$session_id = (string) $session_id;
				if ( '' === $session_id ) {
					continue;
				}
				$clean = preg_replace( '/[^a-zA-Z0-9]/', '', $session_id );
				$lookup[ $session_id ]          = true;
				$lookup[ $clean ]               = true;
				$lookup[ substr( $clean, -8 ) ] = true;
			}
			$this->hm_allowed_sessions_memo[ $pid ] = $lookup;
		}
	}

	/**
	 * Distribute batched evidence rows to their page buckets and apply the
	 * original per-page HAVING gate in PHP.
	 *
	 * A row belongs to page P when row.page_id = P OR its normalized base URL is
	 * P's base URL (a row can belong to several duplicate pages, exactly like the
	 * old per-page OR predicate matched it for each of them). Aggregates are
	 * re-combined per (page, session): MAX of maxes, SUM of sums ('sum' mode,
	 * session_pages.clicks_count) or the session-level value ('max' mode,
	 * sessions.events_count — constant per session, so MAX == the old ungrouped
	 * COALESCE(s.events_count,0)).
	 *
	 * @param array  $rows                     Result rows.
	 * @param array  $chunk                    Page IDs in this chunk.
	 * @param array  $base_to_pages            base_url => page_ids map.
	 * @param array  $allowed_sessions_by_page Output accumulator (by reference).
	 * @param string $clicks_mode              'sum' or 'max'.
	 * @param int    $spam_duration            Duration threshold.
	 * @param int    $spam_scrolls             Scroll threshold.
	 * @param int    $spam_clicks              Click threshold.
	 * @return void
	 */
	private function distribute_allowed_session_rows( $rows, $chunk, $base_to_pages, &$allowed_sessions_by_page, $clicks_mode, $spam_duration, $spam_scrolls, $spam_clicks ) {
		if ( empty( $rows ) ) {
			return;
		}

		$in_chunk = array_fill_keys( $chunk, true );
		$acc      = array();

		foreach ( $rows as $row ) {
			$targets = array();
			$row_pid = (int) $row->page_id;
			if ( isset( $in_chunk[ $row_pid ] ) ) {
				$targets[ $row_pid ] = true;
			}
			$row_base = (string) $row->base_url;
			if ( '' !== $row_base && isset( $base_to_pages[ $row_base ] ) ) {
				foreach ( $base_to_pages[ $row_base ] as $bpid ) {
					$targets[ $bpid ] = true;
				}
			}
			if ( empty( $targets ) ) {
				continue;
			}

			$sid = (string) $row->session_id;
			foreach ( array_keys( $targets ) as $tpid ) {
				if ( ! isset( $acc[ $tpid ][ $sid ] ) ) {
					$acc[ $tpid ][ $sid ] = array(
						'max_scroll'   => 0,
						'sum_clicks'   => 0,
						'eff_duration' => (int) $row->eff_duration,
						'traffic_type' => $row->traffic_type,
					);
				}
				$acc[ $tpid ][ $sid ]['max_scroll'] = max( $acc[ $tpid ][ $sid ]['max_scroll'], (int) $row->max_scroll );
				if ( 'sum' === $clicks_mode ) {
					$acc[ $tpid ][ $sid ]['sum_clicks'] += (int) $row->sum_clicks;
				} else {
					$acc[ $tpid ][ $sid ]['sum_clicks'] = max( $acc[ $tpid ][ $sid ]['sum_clicks'], (int) $row->sum_clicks );
				}
			}
		}

		foreach ( $acc as $tpid => $sessions ) {
			foreach ( $sessions as $sid => $a ) {
				$is_humanish = ( null === $a['traffic_type'] || '' === $a['traffic_type'] || 'human' === $a['traffic_type'] );
				if ( $a['eff_duration'] >= $spam_duration
					&& ( ( $a['max_scroll'] >= $spam_scrolls && $a['sum_clicks'] >= $spam_clicks ) || $is_humanish ) ) {
					$allowed_sessions_by_page[ $tpid ][ $sid ] = true;
				}
			}
		}
	}

	/**
	 * Batch variant of get_normalized_heatmap_page_base_url().
	 *
	 * Resolves the normalized base URL for many pages with two IN() queries
	 * (heatmap mapping table first — freshest owner of the bucket — then the
	 * analytics pages table for the remainder) and fills the same
	 * $hm_page_base_url_memo the per-page resolver uses.
	 *
	 * @param array $page_ids Page IDs.
	 * @return array Map of page_id => normalized base URL ('' when unresolvable).
	 */
	private function get_normalized_heatmap_page_base_urls( $page_ids ) {
		global $wpdb;

		$result  = array();
		$pending = array();
		foreach ( (array) $page_ids as $pid ) {
			$pid = absint( $pid );
			if ( ! $pid ) {
				continue;
			}
			if ( isset( $this->hm_page_base_url_memo[ $pid ] ) ) {
				$result[ $pid ] = $this->hm_page_base_url_memo[ $pid ];
			} else {
				$pending[ $pid ] = true;
			}
		}
		$pending = array_keys( $pending );

		foreach ( array_chunk( $pending, 500 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$urls = array();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; ids parameterized.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT page_id, url FROM {$wpdb->prefix}optibehavior_heatmap_pages WHERE page_id IN ({$ph}) ORDER BY id ASC",
					$chunk
				)
			);
			foreach ( (array) $rows as $row ) {
				$pid = (int) $row->page_id;
				if ( ! isset( $urls[ $pid ] ) && ! empty( $row->url ) ) {
					$urls[ $pid ] = $row->url;
				}
			}

			$missing = array();
			foreach ( $chunk as $pid ) {
				if ( ! isset( $urls[ $pid ] ) ) {
					$missing[] = $pid;
				}
			}
			if ( ! empty( $missing ) ) {
				$mph = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; ids parameterized.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id AS page_id, url FROM {$wpdb->prefix}optibehavior_pages WHERE id IN ({$mph})",
						$missing
					)
				);
				foreach ( (array) $rows as $row ) {
					$pid = (int) $row->page_id;
					if ( ! isset( $urls[ $pid ] ) && ! empty( $row->url ) ) {
						$urls[ $pid ] = $row->url;
					}
				}
			}

			foreach ( $chunk as $pid ) {
				$base = '';
				if ( isset( $urls[ $pid ] ) ) {
					$parts = wp_parse_url( $urls[ $pid ] );
					if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
						$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'http';
						$path   = isset( $parts['path'] ) ? $parts['path'] : '';
						$base   = $scheme . '://' . strtolower( $parts['host'] ) . rtrim( $path, '/' );
					}
				}
				$this->hm_page_base_url_memo[ $pid ] = $base;
				$result[ $pid ]                      = $base;
			}
		}

		return $result;
	}

	/**
	 * Resolve the normalized base URL for a heatmap page.
	 *
	 * Mirrors the URL normalization used by the file storage bucket key
	 * (Opti_Behavior_Heatmap_Storage::get_url_hash()) WITHOUT the md5:
	 * scheme:// + lowercased host + path with the trailing slash trimmed —
	 * query string and fragment dropped. This is the shared identity that
	 * lets the spam allow-list match session_pages rows across query-string
	 * variants and recreated page rows (see the Bug C caveat on
	 * get_allowed_heatmap_session_lookup_for_page()).
	 *
	 * Resolution order: the heatmap mapping table (its url column is written
	 * on every heatmap event, so it is the freshest owner of the bucket),
	 * then the analytics pages table. Returns '' when neither resolves.
	 *
	 * @param int $page_id Page ID.
	 * @return string Normalized base URL, or '' when unresolvable.
	 */
	private function get_normalized_heatmap_page_base_url( $page_id ) {
		global $wpdb;

		$memo_key = absint( $page_id );
		if ( isset( $this->hm_page_base_url_memo[ $memo_key ] ) ) {
			return $this->hm_page_base_url_memo[ $memo_key ];
		}

		$url = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT url FROM {$wpdb->prefix}optibehavior_heatmap_pages WHERE page_id = %d ORDER BY id ASC LIMIT 1",
				$page_id
			)
		);

		if ( empty( $url ) ) {
			$url = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT url FROM {$wpdb->prefix}optibehavior_pages WHERE id = %d",
					$page_id
				)
			);
		}

		$base = '';
		if ( ! empty( $url ) ) {
			$parts = wp_parse_url( $url );
			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				$scheme = ! empty( $parts['scheme'] ) ? $parts['scheme'] : 'http';
				$path   = isset( $parts['path'] ) ? $parts['path'] : '';
				$base   = $scheme . '://' . strtolower( $parts['host'] ) . rtrim( $path, '/' );
			}
		}

		$this->hm_page_base_url_memo[ $memo_key ] = $base;

		return $base;
	}

	/**
	 * Check whether a heatmap file session is allowed by the optional spam lookup.
	 *
	 * @param string $session_id Session ID parsed from filename.
	 * @param array  $lookup     Allowed lookup map.
	 * @return bool
	 */
	private function is_heatmap_session_allowed( $session_id, $lookup ) {
		if ( empty( $lookup ) ) {
			return true;
		}

		return $this->is_heatmap_session_in_lookup( $session_id, $lookup );
	}

	/**
	 * Raw 3-form membership test against a session lookup map, with NO
	 * "empty lookup means unrestricted" shortcut (unlike
	 * is_heatmap_session_allowed()). An empty lookup here always means "not
	 * present" — required for RC-C: a page whose allow-list legitimately
	 * resolved to zero sessions must NOT be treated as "no restriction,
	 * admit everyone", or a known-spam session on that same page would be
	 * wrongly admitted alongside the genuine orphans.
	 *
	 * @param string $session_id Session ID parsed from filename.
	 * @param array  $lookup     Lookup map (allow-list or known-session shape).
	 * @return bool
	 */
	private function is_heatmap_session_in_lookup( $session_id, $lookup ) {
		if ( empty( $lookup ) ) {
			return false;
		}

		$session_id = (string) $session_id;
		$clean      = preg_replace( '/[^a-zA-Z0-9]/', '', $session_id );

		return isset( $lookup[ $session_id ] ) || isset( $lookup[ $clean ] ) || isset( $lookup[ substr( $clean, -8 ) ] );
	}

	/**
	 * Check whether a session id/token is present in a "known DB session"
	 * lookup built by get_known_heatmap_session_lookup_for_page() (RC-C).
	 *
	 * An EMPTY array means "the DB has no trace of any of this page's
	 * tokens" — i.e. every token is unknown/orphan. The boolean `true`
	 * sentinel is the fail-safe case (resolver could not restrict safely):
	 * every token is treated as known.
	 *
	 * @param string     $session_id   Session ID parsed from filename.
	 * @param array|true $known_lookup Known-session lookup map, or `true`.
	 * @return bool
	 */
	private function is_heatmap_session_known( $session_id, $known_lookup ) {
		if ( true === $known_lookup ) {
			return true;
		}

		return $this->is_heatmap_session_in_lookup( $session_id, $known_lookup );
	}

	/**
	 * Orphan-aware spam gate (spec §3.2, RC-C):
	 *
	 *   allowed(token) = in_allowlist(token) OR NOT known_db_session(token)
	 *
	 * A session already vouched for by the spam allow-list is always
	 * admitted. Otherwise, when the DB has NO trace of the token at all
	 * (under is_heatmap_session_known()'s 3-form matching), it cannot be
	 * evidenced as spam — its JSON file is the only evidence and it must
	 * count. A session the DB DOES know about but that failed the allow-list
	 * gate (duration/scroll/click thresholds or traffic_type) is genuinely
	 * filtered spam and stays rejected.
	 *
	 * NOTE: unlike is_heatmap_session_allowed(), the allow-list check here is
	 * STRICT (is_heatmap_session_in_lookup(), no "empty = unrestricted"
	 * shortcut) — a page whose allow-list legitimately resolved to zero
	 * sessions must fall through to the known-session check, not admit
	 * everyone unconditionally. Callers that want "spam filtering entirely
	 * off" semantics should not call this gate at all (pass every session
	 * through directly), matching is_heatmap_session_allowed( $id, array() ).
	 *
	 * @param string     $session_id     Session ID parsed from filename.
	 * @param array      $allowed_lookup Allow-list lookup map.
	 * @param array|true $known_lookup   Known-DB-session lookup map (see get_known_heatmap_session_lookup_for_page()).
	 * @return bool
	 */
	private function is_heatmap_session_allowed_or_orphan( $session_id, $allowed_lookup, $known_lookup ) {
		if ( $this->is_heatmap_session_in_lookup( $session_id, $allowed_lookup ) ) {
			return true;
		}

		return ! $this->is_heatmap_session_known( $session_id, $known_lookup );
	}

	/**
	 * Check whether heatmap JSON files exist for any of the provided pages.
	 *
	 * @param array $page_ids Page IDs.
	 * @return bool
	 */
	private function has_heatmap_file_data_for_pages( $page_ids ) {
		$upload_dir      = wp_upload_dir();
		$base_upload_dir = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data/';

		foreach ( (array) $page_ids as $page_id ) {
			$page_id = absint( $page_id );
			if ( ! $page_id ) {
				continue;
			}

			if ( ! empty( $this->get_heatmap_file_dirs_for_page( $page_id, $base_upload_dir ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get session counts from file storage for multiple pages
	 * Counts total unique sessions across all devices and event types
	 *
	 * @param array $page_ids Array of page IDs
	 * @param string|null $start_date Optional start date filter
	 * @param string|null $end_date Optional end date filter
	 * @return array Map of page_id => session count
	 */
	private function batch_sessions_counts_by_page_from_files( $page_ids, $start_date = null, $end_date = null ) {
		if ( empty( $page_ids ) ) {
			return array();
		}

		$map = array();
		foreach ( $page_ids as $page_id ) {
			$map[ absint( $page_id ) ] = 0;
		}

		$desktop_map = $this->batch_recording_counts_by_device( $page_ids, 'desktop', $start_date, $end_date );
		$mobile_map  = $this->batch_recording_counts_by_device( $page_ids, 'mobile', $start_date, $end_date );
		$tablet_map  = $this->batch_recording_counts_by_device( $page_ids, 'tablet', $start_date, $end_date );

		foreach ( $page_ids as $page_id ) {
			$page_id = absint( $page_id );
			if ( ! $page_id ) {
				continue;
			}

			$map[ $page_id ] =
				( isset( $desktop_map[ $page_id ] ) ? intval( $desktop_map[ $page_id ] ) : 0 ) +
				( isset( $mobile_map[ $page_id ] ) ? intval( $mobile_map[ $page_id ] ) : 0 ) +
				( isset( $tablet_map[ $page_id ] ) ? intval( $tablet_map[ $page_id ] ) : 0 );
		}

		return $map;
	}

		public function ajax_heatmaps_sessions(){
			return $this->ajax_heatmaps_sessions_impl();
		}



		private function batch_sessions_counts($urls, $start_date=null, $end_date=null){
			global $wpdb;
			if (empty($urls)) {
				return array();
			}

			// Use esc_sql() for table name to satisfy WordPress Plugin Check
			$pv_table = esc_sql( $wpdb->prefix . 'optibehavior_pageviews' );
			$map = array();

			// Process each URL individually to avoid SQL injection from dynamic WHERE clause
			// This is safer than using implode() on WHERE clauses
			foreach ($urls as $url) {
				if ($start_date && $end_date) {
					$count = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(DISTINCT pv.session_id)
							FROM " . $pv_table . " pv
							WHERE pv.url LIKE CONCAT(%s, '%%')
							AND pv.view_time BETWEEN %s AND %s",
							$url,
							$start_date,
							$end_date
						)
					);
				} else {
					$count = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(DISTINCT pv.session_id)
							FROM " . $pv_table . " pv
							WHERE pv.url LIKE CONCAT(%s, '%%')",
							$url
						)
					);
				}

				// Extract base URL (without query string)
				$base_url = strtok($url, '?');
				$map[$base_url] = (int)$count;
			}

			return $map;
		}



	/**
	 * Get heatmap insights from database
	 */
	private function get_heatmap_insights() {
		global $wpdb;

		$week_ago = gmdate('Y-m-d', strtotime('-7 days'));
		$two_weeks_ago = gmdate('Y-m-d', strtotime('-14 days'));

		// Top performing pages by pageviews (more reliable than clicks)
		// Try pageviews first
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static query with prepare
		$top_pages_query = "SELECT
			COALESCE(NULLIF(pv.title, ''), 'Untitled Page') as title,
			pv.url,
			COUNT(DISTINCT pv.session_id) as views,
			COUNT(DISTINCT CASE WHEN pv.view_time >= %s THEN pv.session_id END) as recent_views,
			COUNT(DISTINCT CASE WHEN pv.view_time BETWEEN %s AND %s THEN pv.session_id END) as previous_views
		FROM " . $wpdb->prefix . "optibehavior_pageviews pv
		WHERE pv.view_time IS NOT NULL AND pv.url IS NOT NULL AND pv.url != ''
		GROUP BY pv.url
		ORDER BY views DESC
		LIMIT 5";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static query with prepare
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$top_pages_results = $wpdb->get_results( $wpdb->prepare(
			$top_pages_query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static query variable
			$week_ago,
			$two_weeks_ago,
			$week_ago
		));

		// If no pageviews data, fallback to events data
		if ( empty( $top_pages_results ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static query
			$top_pages_query_fallback = "SELECT
				COALESCE(NULLIF(p.title, ''), 'Untitled Page') as title,
				p.url,
				COUNT(*) as views,
				0 as recent_views,
				0 as previous_views
			FROM " . $wpdb->prefix . "optibehavior_events e
			JOIN " . $wpdb->prefix . "optibehavior_pages p ON e.page_id2 = p.id
			WHERE p.url IS NOT NULL AND p.url != ''
			GROUP BY p.url
			ORDER BY views DESC
			LIMIT 5";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static query
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			$top_pages_results = $wpdb->get_results( $top_pages_query_fallback );
		}

		// Get click data from file storage for all pages
		$file_storage = $this->heatmap->get_file_storage();
		$clicks_map = array();

		if ( $file_storage ) {
			$pages_with_heatmap_data = $file_storage->get_pages_with_heatmap_data();
			foreach ( $pages_with_heatmap_data as $heatmap_page ) {
				$clicks_map[ $heatmap_page['url'] ] = intval( $heatmap_page['click_pc'] ) + intval( $heatmap_page['click_mobile'] );
			}
		}

		$top_pages = array();
		foreach ($top_pages_results as $page) {
			// Calculate trend
			$trend = 'neutral';
			$trend_icon = '➡️';
			$change = 0;

			if ($page->previous_views > 0) {
				$change = round((($page->recent_views - $page->previous_views) / $page->previous_views) * 100);
				if ($change > 0) {
					$trend = 'positive';
					$trend_icon = '📈';
				} elseif ($change < 0) {
					$trend = 'negative';
					$trend_icon = '📉';
				}
			} elseif ($page->recent_views > 0) {
				$trend = 'positive';
				$trend_icon = '📈';
				$change = 100;
			}

			$title = $page->title ? $page->title : 'Untitled Page';
			if (strlen($title) > 30) {
				$title = substr($title, 0, 27) . '...';
			}

			// Get actual click count from heatmap file storage
			$clicks = isset( $clicks_map[ $page->url ] ) ? $clicks_map[ $page->url ] : 0;

			$top_pages[] = array(
				'title' => $title,
				'url' => $page->url,
				'clicks' => $clicks,
				'trend' => $trend,
				'trend_icon' => $trend_icon,
				'change' => abs($change)
			);
		}

		// Device breakdown from visitor data
		$device_query = "SELECT
			v.device_type,
			COUNT(DISTINCT s.id) as sessions
		FROM " . $wpdb->prefix . "optibehavior_sessions s
		LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
		WHERE v.device_type IS NOT NULL
		GROUP BY v.device_type
		ORDER BY sessions DESC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static query, no user input
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$device_results = $wpdb->get_results($device_query);
		$total_sessions = array_sum(array_column($device_results, 'sessions'));

		$devices = array();
		foreach ($device_results as $device) {
			$percentage = $total_sessions > 0 ?
				round(($device->sessions / $total_sessions) * 100) : 0;

			$icon = '🖥️'; // Default desktop
			$name = ucfirst($device->device_type);

			if (strtolower($device->device_type) === 'mobile') {
				$icon = '📱';
			} elseif (strtolower($device->device_type) === 'tablet') {
				$icon = '📱';
			}

			$devices[] = array(
				'name' => $name,
				'icon' => $icon,
				'percentage' => $percentage
			);
		}

		// Peak activity time (hour with most events)
		$peak_time_query = "SELECT
			HOUR(insert_at) as hour,
			COUNT(*) as events
		FROM " . $wpdb->prefix . "optibehavior_events
		WHERE event IN (16, 17, 32, 33, 48, 49)
		AND insert_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
		GROUP BY HOUR(insert_at)
		ORDER BY events DESC
		LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static query, no user input
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$peak_result = $wpdb->get_row($peak_time_query);
		$peak_time = $peak_result ?
			gmdate('g:i A', mktime($peak_result->hour, 0, 0)) : 'No data';

		// Average intensity (events per page)
		$intensity_query = "SELECT
			AVG(event_count) as avg_intensity
		FROM (
			SELECT page_id2, COUNT(*) as event_count
			FROM " . $wpdb->prefix . "optibehavior_events
			WHERE event IN (16, 17, 32, 33, 48, 49)
			GROUP BY page_id2
		) as page_events";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static query, no user input
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$intensity_result = $wpdb->get_var($intensity_query);
		$avg_intensity = $intensity_result ? round($intensity_result, 1) : 0;

		return array(
			'top_pages' => $top_pages,
			'devices' => $devices,
			'peak_time' => $peak_time,
			'avg_intensity' => $avg_intensity
		);
	}

	/**
	 * Get total heatmaps count from database
	 */
	private function get_total_heatmaps() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT page_id2)
			FROM " . $wpdb->prefix . "optibehavior_events
			WHERE event IN (16, 17, 32, 33, 48, 49)"
		);

		return intval($count);
	}

	/**
	 * Helper method to calculate time elapsed string
	 */
	private function time_elapsed_string($datetime) {
		$time = time() - strtotime($datetime);

		if ($time < 60) {
			return __( 'just now', 'opti-behavior' );
		} elseif ($time < 3600) {
			$minutes = floor($time / 60);
			/* translators: %s: number of minutes */
			return sprintf( __( '%sm ago', 'opti-behavior' ), $minutes );
		} elseif ($time < 86400) {
			$hours = floor($time / 3600);
			/* translators: %s: number of hours */
			return sprintf( __( '%sh ago', 'opti-behavior' ), $hours );
		} elseif ($time < 2592000) {
			$days = floor($time / 86400);
			/* translators: %s: number of days */
			return sprintf( __( '%sd ago', 'opti-behavior' ), $days );
		} else {
			$months = floor($time / 2592000);
			/* translators: %s: number of months */
			return sprintf( __( '%smo ago', 'opti-behavior' ), $months );
		}
	}



	/**
	 * AJAX handler for dashboard data
	 */
	public function ajax_dashboard_data() {
		return $this->ajax_dashboard_data_impl();
	}

	/**
	 * AJAX handler for the FREE dashboard advanced-filters panel's dropdown data.
	 *
	 * @since 1.0.4
	 */
	public function ajax_get_dashboard_filter_options() {
		return $this->ajax_get_dashboard_filter_options_impl();
	}

	/**
	 * AJAX handler for session data
	 */
	public function ajax_session_data() {
		return $this->ajax_session_data_impl();
	}

	/**
	 * AJAX handler for analytics data
	 */
	public function ajax_analytics_data() {
		return $this->ajax_analytics_data_impl();
	}

	/**
	 * AJAX handler for Top Engaged Users widget
	 */
	public function ajax_top_users() {
		return $this->ajax_top_users_impl();
	}

	/**
	 * AJAX handler for Smart Insights list.
	 *
	 * @since 1.3.3
	 */
	public function ajax_smart_insights_list() {
		return $this->ajax_smart_insights_list_impl();
	}

	/**
	 * AJAX handler for Smart Insight detail.
	 *
	 * @since 1.3.3
	 */
	public function ajax_smart_insights_detail() {
		return $this->ajax_smart_insights_detail_impl();
	}

	/**
	 * AJAX handler for Smart Insights refresh.
	 *
	 * @since 1.3.3
	 */
	public function ajax_smart_insights_refresh() {
		return $this->ajax_smart_insights_refresh_impl();
	}

	/**
	 * AJAX handler for Smart Insight status updates.
	 *
	 * @since 1.3.3
	 */
	public function ajax_smart_insights_update_status() {
		return $this->ajax_smart_insights_update_status_impl();
	}

	/**
	 * AJAX handler for Smart Insights weekly summary.
	 *
	 * @since 1.3.3
	 */
	public function ajax_smart_insights_summary() {
		return $this->ajax_smart_insights_summary_impl();
	}

	/**
	 * AJAX handler for compact Smart Insights notifications.
	 *
	 * @since 1.3.5
	 */
	public function ajax_smart_insights_notifications() {
		return $this->ajax_smart_insights_notifications_payload_impl();
	}

	/**
	 * AJAX handler for Smart Insights notification user state.
	 *
	 * @since 1.3.5
	 */
	public function ajax_smart_insights_notification_state() {
		return $this->ajax_smart_insights_notification_state_impl();
	}

	/**
	 * Render lightweight Smart Insights notification root in admin footer.
	 *
	 * @since 1.3.5
	 */
	public function render_smart_insights_notifications_root() {
		if ( ! $this->should_load_smart_insights_notifications_impl() ) {
			return;
		}
		?>
		<div id="opti-behavior-smart-insights-notifications-root" class="ob-si-notification-root" aria-live="polite"></div>
		<?php
	}

	/**
	 * AJAX handler for Visitor Heatmap widget
	 */
	public function ajax_visitor_heatmap() {
		return $this->ajax_visitor_heatmap_impl();
	}

	/**
	 * Build a normalized dashboard stats context from SQL date boundaries.
	 *
	 * @param string $start_date Start datetime (inclusive).
	 * @param string $end_date   End datetime (inclusive).
	 * @param string $metric_grain Metric grain for the widget.
	 * @return array
	 */
	private function get_dashboard_stats_context_for_range( $start_date, $end_date, $metric_grain = 'session' ) {
		$start_date = sanitize_text_field( (string) $start_date );
		$end_date   = sanitize_text_field( (string) $end_date );

		$source_scope = $this->should_use_recorded_dashboard_scope() ? 'recorded_sessions' : 'all_sessions';
		$context      = array(
			'period'       => 'custom',
			'start_date'   => substr( $start_date, 0, 10 ),
			'end_date'     => substr( $end_date, 0, 10 ),
			'sql_start'    => $start_date,
			'sql_end'      => gmdate( 'Y-m-d H:i:s', strtotime( $end_date . ' +1 second' ) ),
			'start'        => $start_date,
			'end'          => $end_date,
			'exclude_spam' => $this->is_spam_excluded(),
			'source_scope' => $source_scope,
			'metric_grain' => sanitize_key( (string) $metric_grain ),
			'feature'      => 'dashboard',
		);

		if ( class_exists( 'Opti_Behavior_Stats_Context' ) ) {
			$context = array_merge(
				$context,
				Opti_Behavior_Stats_Context::create(
					array(
						'period'       => 'custom',
						'start_date'   => $context['start_date'],
						'end_date'     => $context['end_date'],
						'source_scope' => $source_scope,
						'metric_grain' => $context['metric_grain'],
						'feature'      => 'dashboard',
					)
				)
			);
			$context['start']        = $start_date;
			$context['end']          = $end_date;
			$context['sql_start']    = $start_date;
			$context['sql_end']      = gmdate( 'Y-m-d H:i:s', strtotime( $end_date . ' +1 second' ) );
			$context['exclude_spam'] = $this->is_spam_excluded();
		}

		return $context;
	}

	/**
	 * Get canonical session baseline for dashboard dimensions.
	 *
	 * @param array $context Widget context.
	 * @return int
	 */
	private function get_dashboard_dimension_session_baseline( $context ) {
		$start = isset( $context['start'] ) ? (string) $context['start'] : '';
		$end   = isset( $context['end'] ) ? (string) $context['end'] : '';

		if ( '' === $start || '' === $end ) {
			return 0;
		}

		$totals = $this->get_dashboard_traffic_totals( $start, $end );
		return absint( $totals['sessions'] ?? 0 );
	}

	/**
	 * Get canonical visitor baseline for visitor-grain dashboard widgets.
	 *
	 * @param array $context Widget context.
	 * @return int
	 */
	private function get_dashboard_dimension_visitor_baseline( $context ) {
		$start = isset( $context['start'] ) ? (string) $context['start'] : '';
		$end   = isset( $context['end'] ) ? (string) $context['end'] : '';

		if ( '' === $start || '' === $end ) {
			return 0;
		}

		$totals = $this->get_dashboard_traffic_totals( $start, $end );
		return absint( $totals['visitors'] ?? 0 );
	}

	/**
	 * Add/remove bucket counts so widget totals match the KPI session baseline.
	 *
	 * @param array  $buckets  Bucket map label => count.
	 * @param int    $baseline Canonical session baseline.
	 * @param string $label    Bucket label.
	 * @return array
	 */
	private function add_dashboard_unclassified_bucket( $buckets, $baseline, $label = 'Unclassified' ) {
		$baseline = absint( $baseline );
		$total    = 0;

		foreach ( (array) $buckets as $count ) {
			$total += absint( $count );
		}

		if ( $baseline > $total ) {
			$buckets[ $label ] = ( isset( $buckets[ $label ] ) ? absint( $buckets[ $label ] ) : 0 ) + ( $baseline - $total );
		} elseif ( $baseline < $total ) {
			$buckets = $this->reduce_dashboard_bucket_overage(
				$buckets,
				$total - $baseline,
				array( $label, 'Unknown', 'Other' )
			);
		}

		return $buckets;
	}

	/**
	 * Reduce bucket counts when live widget queries outrun the cached KPI baseline.
	 *
	 * @param array $buckets          Bucket map label => count.
	 * @param int   $overage          Count to subtract.
	 * @param array $preferred_labels Labels to reduce first.
	 * @return array
	 */
	private function reduce_dashboard_bucket_overage( $buckets, $overage, $preferred_labels = array() ) {
		$overage = absint( $overage );
		if ( $overage <= 0 ) {
			return $buckets;
		}

		$subtract_from_label = static function ( &$bucket_map, $label, &$remaining ) {
			if ( $remaining <= 0 || ! isset( $bucket_map[ $label ] ) ) {
				return;
			}

			$count     = absint( $bucket_map[ $label ] );
			$reduction = min( $count, $remaining );
			$count    -= $reduction;
			$remaining -= $reduction;

			if ( $count > 0 ) {
				$bucket_map[ $label ] = $count;
			} else {
				unset( $bucket_map[ $label ] );
			}
		};

		foreach ( (array) $preferred_labels as $preferred_label ) {
			$subtract_from_label( $buckets, $preferred_label, $overage );
			if ( $overage <= 0 ) {
				return $buckets;
			}
		}

		arsort( $buckets );
		foreach ( array_keys( $buckets ) as $label ) {
			$subtract_from_label( $buckets, $label, $overage );
			if ( $overage <= 0 ) {
				break;
			}
		}

		return $buckets;
	}

	/**
	 * Keep a displayed dimension list capped while preserving the distribution total.
	 *
	 * @param array  $buckets         Bucket map label => count.
	 * @param int    $limit           Maximum non-reserved rows to keep before grouping the remainder.
	 * @param string $other_label     Label for grouped classified buckets.
	 * @param array  $reserved_labels Labels that should remain separate from the cap.
	 * @return array
	 */
	private function limit_dashboard_bucket_map( $buckets, $limit, $other_label = 'Other', $reserved_labels = array() ) {
		$limit          = max( 1, absint( $limit ) );
		$reserved       = array();
		$working        = array();
		$reserved_labels = array_values( array_unique( array_map( 'strval', (array) $reserved_labels ) ) );

		foreach ( (array) $buckets as $label => $count ) {
			$label = (string) $label;
			$count = absint( $count );
			if ( $count <= 0 ) {
				continue;
			}

			if ( in_array( $label, $reserved_labels, true ) ) {
				$reserved[ $label ] = ( isset( $reserved[ $label ] ) ? absint( $reserved[ $label ] ) : 0 ) + $count;
			} else {
				$working[ $label ] = ( isset( $working[ $label ] ) ? absint( $working[ $label ] ) : 0 ) + $count;
			}
		}

		arsort( $working );
		if ( count( $working ) > $limit ) {
			$top       = array_slice( $working, 0, $limit, true );
			$remainder = array_slice( $working, $limit, null, true );
			$other     = 0;

			foreach ( $remainder as $count ) {
				$other += absint( $count );
			}

			if ( $other > 0 ) {
				$top[ $other_label ] = ( isset( $top[ $other_label ] ) ? absint( $top[ $other_label ] ) : 0 ) + $other;
			}

			$working = $top;
		}

		foreach ( $reserved_labels as $label ) {
			if ( isset( $reserved[ $label ] ) && $reserved[ $label ] > 0 ) {
				$working[ $label ] = absint( $reserved[ $label ] );
			}
		}

		return $working;
	}

	/**
	 * Whether the current request explicitly asks for dashboard cache bypass.
	 *
	 * @return bool
	 */
	private function should_force_refresh_dashboard_cache() {
		return function_exists( 'opti_behavior_is_force_refresh_requested' ) && opti_behavior_is_force_refresh_requested();
	}

	/**
	 * Read a widget transient with force-refresh support.
	 *
	 * @param string $cache_key Transient key.
	 * @return mixed False on miss/force-refresh, otherwise cached payload.
	 */
	private function get_dashboard_widget_transient( $cache_key ) {
		if ( $this->should_force_refresh_dashboard_cache() ) {
			delete_transient( $cache_key );
			return false;
		}

		return get_transient( $cache_key );
	}

	/**
	 * Get screen resolution data
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array
	 */
	private function get_screen_resolution_data( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters ) ) {
			return $this->get_screen_resolution_data_filtered( $start_date, $end_date, $filters );
		}

		$context    = $this->get_dashboard_stats_context_for_range( $start_date, $end_date, 'session' );
		$cache_seed = class_exists( 'Opti_Behavior_Stats_Context' )
			? Opti_Behavior_Stats_Context::cache_key( $context )
			: md5( $start_date . '|' . $end_date );
		$cache_key  = 'opti_behavior_screen_res_' . md5( 'dim-v2|' . $cache_seed . '|' . ( $this->is_spam_excluded() ? '1' : '0' ) );
		$cached     = $this->get_dashboard_widget_cached_data( $cache_key, $start_date, $end_date, 'screen_resolution' );
		if ( false !== $cached ) {
			return $cached;
		}

		$spam_clause = $this->get_spam_exclusion_clause( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dashboard dimension aggregation.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN v.screen_width IS NULL OR v.screen_height IS NULL THEN 'Unknown'
						WHEN v.screen_width <= 0 OR v.screen_height <= 0 THEN 'Unknown'
						ELSE CONCAT(v.screen_width, 'x', v.screen_height)
					END AS resolution,
					COUNT(DISTINCT s.id) AS count
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
				GROUP BY resolution
				ORDER BY count DESC",
				$start_date,
				$end_date
			)
		);

		$map = array();
		foreach ( (array) $results as $row ) {
			$label = isset( $row->resolution ) ? trim( (string) $row->resolution ) : '';
			if ( '' === $label ) {
				$label = 'Unknown';
			}
			$map[ $label ] = ( isset( $map[ $label ] ) ? absint( $map[ $label ] ) : 0 ) + absint( $row->count );
		}

		$baseline = $this->get_dashboard_dimension_session_baseline( $context );
		$map      = $this->add_dashboard_unclassified_bucket( $map, $baseline, 'Unclassified' );

		// Unified Retention Protocol: merge aggregated resolution history for
		// days older than the raw retention window.
		$map = $this->merge_pre_raw_dimension_counts( 'resolution', $start_date, $end_date, $map );

		$unknown      = isset( $map['Unknown'] ) ? absint( $map['Unknown'] ) : 0;
		$unclassified = isset( $map['Unclassified'] ) ? absint( $map['Unclassified'] ) : 0;
		unset( $map['Unknown'], $map['Unclassified'] );
		$map = $this->limit_dashboard_bucket_map( $map, 9, 'Other' );

		$data = array();
		foreach ( $map as $label => $count ) {
			$display_label = 'Other' === $label ? __( 'Other', 'opti-behavior' ) : $label;
			$data[] = array(
				'resolution' => $display_label,
				'count'      => absint( $count ),
			);
		}

		if ( $unknown > 0 ) {
			$data[] = array(
				'resolution' => __( 'Unknown', 'opti-behavior' ),
				'count'      => $unknown,
			);
		}

		if ( $unclassified > 0 ) {
			$data[] = array(
				'resolution' => __( 'Unclassified', 'opti-behavior' ),
				'count'      => $unclassified,
			);
		}

		// Never freeze an empty result (Bug #4): that's the state most likely to
		// change on the very next session insert.
		return $this->set_dashboard_widget_cached_data( $cache_key, $data, empty( $data ), $start_date, $end_date, 'screen_resolution' );
	}

	/**
	 * Filtered variant of get_screen_resolution_data(). Bypasses the transient
	 * cache and the unfiltered-baseline reconciliation (Unclassified bucket
	 * balancing against KPI totals doesn't apply once a filter narrows the
	 * session universe) and appends the advanced-filters WHERE fragment.
	 *
	 * @since 1.0.4
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param array  $filters    Sanitized advanced filters.
	 * @return array
	 */
	private function get_screen_resolution_data_filtered( $start_date, $end_date, array $filters ) {
		global $wpdb;

		$spam_clause      = $this->get_spam_exclusion_clause( 's' );
		$filter_sql        = $this->build_advanced_filters_sql( $filters );
		$params           = array_merge( array( $start_date, $end_date ), $filter_sql['params'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dashboard dimension aggregation.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN v.screen_width IS NULL OR v.screen_height IS NULL THEN 'Unknown'
						WHEN v.screen_width <= 0 OR v.screen_height <= 0 THEN 'Unknown'
						ELSE CONCAT(v.screen_width, 'x', v.screen_height)
					END AS resolution,
					COUNT(DISTINCT s.id) AS count
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
				GROUP BY resolution
				ORDER BY count DESC",
				$params
			)
		);

		$map = array();
		foreach ( (array) $results as $row ) {
			$label = isset( $row->resolution ) ? trim( (string) $row->resolution ) : '';
			if ( '' === $label ) {
				$label = 'Unknown';
			}
			$map[ $label ] = ( isset( $map[ $label ] ) ? absint( $map[ $label ] ) : 0 ) + absint( $row->count );
		}

		$unknown = isset( $map['Unknown'] ) ? absint( $map['Unknown'] ) : 0;
		unset( $map['Unknown'] );
		$map = $this->limit_dashboard_bucket_map( $map, 9, 'Other' );

		$data = array();
		foreach ( $map as $label => $count ) {
			$display_label = 'Other' === $label ? __( 'Other', 'opti-behavior' ) : $label;
			$data[] = array(
				'resolution' => $display_label,
				'count'      => absint( $count ),
			);
		}

		if ( $unknown > 0 ) {
			$data[] = array(
				'resolution' => __( 'Unknown', 'opti-behavior' ),
				'count'      => $unknown,
			);
		}

		return $data;
	}

	/**
	 * Get dashboard data
	 *
	 * @param string $period Time period.
	 * @return array
	 */
	private function get_dashboard_data( $period, $start_override = null, $end_override = null, array $filters = array() ) {
		return $this->get_dashboard_data_impl($period, $start_override, $end_override, $filters);
	}

	/**
	 * Get dashboard stats only (for fast initial page load)
	 *
	 * @param string $period Time period.
	 * @param string|null $start_override Optional start date override.
	 * @param string|null $end_override Optional end date override.
	 * @param array $filters Optional sanitized advanced-filters array (allow-listed keys only).
	 * @return array Minimal dashboard data with stats only.
	 */
	private function get_dashboard_stats_only( $period, $start_override = null, $end_override = null, array $filters = array() ) {
		return $this->get_dashboard_stats_only_impl($period, $start_override, $end_override, $filters);
	}

	/**
	 * Get session data
	 *
	 * @param int   $page Current page.
	 * @param int   $per_page Items per page.
	 * @param array $filters Filters.
	 * @return array
	 */
	private function get_session_data( $page, $per_page, $filters ) {
		return $this->get_session_data_impl($page, $per_page, $filters);
	}

	/**
	 * Get analytics data
	 *
	 * @param string $type Analytics type.
	 * @param string $period Time period.
	 * @return array
	 */
	private function get_analytics_data( $type, $period ) {
		return $this->get_analytics_data_impl($type, $period);
	}

	/**
	 * Get date range for period
	 *
	 * @param string $period Period string.
	 * @return array
	 */
	private function get_date_range( $period ) {
		return $this->get_date_range_impl($period);
	}

	/**
	 * Get the first available data date from the database.
	 *
	 * @return string|null
	 */
	private function get_first_available_data_date() {
		return $this->get_first_available_data_date_impl();
	}

	/**
	 * Resolve the dashboard metric source scope.
	 *
	 * Default is all tracked sessions. A filter can opt in to recorded sessions
	 * for installs that intentionally want the dashboard to mirror recordings.
	 *
	 * @return string all_sessions|recorded_sessions
	 */
	private function get_dashboard_stats_source_scope() {
		$scope = apply_filters( 'opti_behavior_dashboard_stats_source_scope', 'all_sessions' );
		$scope = sanitize_key( (string) $scope );

		return in_array( $scope, array( 'all_sessions', 'recorded_sessions' ), true ) ? $scope : 'all_sessions';
	}

	/**
	 * Check whether dashboard KPIs should use the Pro recordings scope.
	 *
	 * Uses the same session universe as Session Recordings only when Pro is active,
	 * recordings access is allowed, and required tables are present.
	 *
	 * @return bool
	 */
	private function should_use_recorded_dashboard_scope() {
		global $wpdb;

		if ( 'recorded_sessions' !== $this->get_dashboard_stats_source_scope() ) {
			return false;
		}

		$has_pro_signal = (
			( function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active() ) ||
			defined( 'OptiBehavior_PRO_VERSION' ) ||
			defined( 'OPTI_BEHAVIOR_PRO_VERSION' ) ||
			class_exists( 'Opti_Behavior_Session_Recording' ) ||
			function_exists( 'opti_behavior_pro_get_access_context' )
		);

		if ( ! $has_pro_signal ) {
			return false;
		}

		if ( function_exists( 'opti_behavior_pro_get_access_context' ) ) {
			$access_context = opti_behavior_pro_get_access_context();
			if ( is_array( $access_context ) ) {
				$features = ( isset( $access_context['features'] ) && is_array( $access_context['features'] ) ) ? $access_context['features'] : array();
				if ( array_key_exists( 'recordings', $features ) && empty( $features['recordings'] ) ) {
					return false;
				}

				$revocation_state = isset( $access_context['revocation_state'] ) ? sanitize_key( (string) $access_context['revocation_state'] ) : 'none';
				if ( in_array( $revocation_state, array( 'suspended', 'chargebacked', 'blacklisted', 'disabled' ), true ) ) {
					return false;
				}
			}
		}

		$recordings_table = $wpdb->prefix . 'optibehavior_recordings';
		$sessions_table   = $wpdb->prefix . 'optibehavior_sessions';

		if ( ! $this->dashboard_table_exists( $recordings_table ) || ! $this->dashboard_table_exists( $sessions_table ) ) {
			return false;
		}

		// The recordings spam filter depends on scroll events table when enabled.
		if ( $this->is_spam_excluded() ) {
			$events_table = $wpdb->prefix . 'optibehavior_events';
			if ( ! $this->dashboard_table_exists( $events_table ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether a table exists.
	 *
	 * @param string $table_name Full table name.
	 * @return bool
	 */
	private function dashboard_table_exists( $table_name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight existence check for dashboard query routing.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		return $table_exists === $table_name;
	}

	/**
	 * Build SQL fragments for dashboard recordings spam-exclusion parity.
	 *
	 * Mirrors Session Recordings thresholds: duration, scroll events, clicks.
	 *
	 * @param string $recording_alias Recordings table alias.
	 * @param string $session_alias   Sessions table alias.
	 * @param string $scroll_alias    Scroll-count derived table alias.
	 * @return array
	 */
	private function get_dashboard_recordings_spam_filter_sql( $recording_alias = 'r', $session_alias = 's', $scroll_alias = 'dashboard_spam_scrolls', $exclude_spam = null ) {
		global $wpdb;

		if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
			$filter = Opti_Behavior_Stats_Spam_Filter::recordings_threshold_sql( $recording_alias, $session_alias, $scroll_alias, $exclude_spam );
			if ( ! empty( $filter['where'] ) && 0 !== strpos( ltrim( $filter['where'] ), 'AND ' ) ) {
				$filter['where'] = ' AND ' . $filter['where'];
			}
			return $filter;
		}

		$traffic_settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_detection_enabled'      => true,
				'spam_duration_threshold'     => 3,
				'spam_min_scrolls_threshold'  => 0,
				'spam_min_clicks_threshold'   => 1,
			)
		);

		if ( is_array( $traffic_settings ) && array_key_exists( 'spam_detection_enabled', $traffic_settings ) && empty( $traffic_settings['spam_detection_enabled'] ) ) {
			return array(
				'join'   => '',
				'where'  => '',
				'values' => array(),
			);
		}

		$spam_duration = isset( $traffic_settings['spam_duration_threshold'] ) ? max( 0, intval( $traffic_settings['spam_duration_threshold'] ) ) : 3;
		$spam_scrolls  = isset( $traffic_settings['spam_min_scrolls_threshold'] ) ? max( 0, intval( $traffic_settings['spam_min_scrolls_threshold'] ) ) : 0;
		$spam_clicks   = isset( $traffic_settings['spam_min_clicks_threshold'] ) ? max( 0, intval( $traffic_settings['spam_min_clicks_threshold'] ) ) : 1;
		// Ghost-session tolerance: include the recording's own duration so a
		// recording whose sessions-table row is missing (cleanup/retention/
		// ingestion gaps) is judged on its real engagement instead of a NULL
		// session duration, and do not require s.id — the traffic-type clause
		// is NULL-tolerant, so stored spam verdicts still exclude when present.
		$duration_sql  = "GREATEST(COALESCE({$recording_alias}.duration, 0), COALESCE({$session_alias}.duration, 0), COALESCE(TIMESTAMPDIFF(SECOND, {$session_alias}.start_time, COALESCE({$session_alias}.end_time, {$session_alias}.start_time)), 0))";

		return array(
			'join'   => " LEFT JOIN (
					SELECT session_id, COUNT(*) AS scroll_count
					FROM {$wpdb->prefix}optibehavior_events
					WHERE event IN (32,33)
					GROUP BY session_id
				) {$scroll_alias} ON {$recording_alias}.session_id = {$scroll_alias}.session_id",
			'where'  => " AND (({$session_alias}.traffic_type IS NULL OR {$session_alias}.traffic_type NOT IN ('spam','bot','automated'))
				AND {$duration_sql} >= %d
				AND COALESCE({$scroll_alias}.scroll_count, 0) >= %d
				AND {$recording_alias}.click_count >= %d)",
			'values' => array( $spam_duration, $spam_scrolls, $spam_clicks ),
		);
	}

	/**
	 * Get recordings-scope dashboard totals for sessions/visitors/recordings.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array
	 */
	private function get_recorded_dashboard_counts( $start_date, $end_date ) {
		global $wpdb;

		static $counts_cache = array();
		$exclude_spam = $this->is_spam_excluded();
		$spam_policy  = class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ? Opti_Behavior_Stats_Spam_Filter::cache_key( $exclude_spam ) : ( $exclude_spam ? 'exclude' : 'include' );
		$cache_key    = md5( $start_date . '|' . $end_date . '|' . ( $exclude_spam ? '1' : '0' ) . '|' . $spam_policy );
		if ( isset( $counts_cache[ $cache_key ] ) ) {
			return $counts_cache[ $cache_key ];
		}

		$recordings_table = $wpdb->prefix . 'optibehavior_recordings';
		$sessions_table   = $wpdb->prefix . 'optibehavior_sessions';
		$params           = array( $start_date, $end_date );
		$spam_join        = '';
		$spam_where       = '';

		if ( $exclude_spam ) {
			$spam_filter = $this->get_dashboard_recordings_spam_filter_sql( 'r', 's', 'dashboard_spam_scrolls', true );
			$spam_join   = isset( $spam_filter['join'] ) ? $spam_filter['join'] : '';
			$spam_where  = isset( $spam_filter['where'] ) ? $spam_filter['where'] : '';
			if ( ! empty( $spam_filter['values'] ) && is_array( $spam_filter['values'] ) ) {
				$params = array_merge( $params, $spam_filter['values'] );
			}
		}

		$query = $wpdb->prepare(
			"SELECT
				COALESCE(SUM(recorded_sessions.recording_count), 0) AS recordings,
				COUNT(*) AS sessions,
				COUNT(DISTINCT NULLIF(recorded_sessions.visitor_id, '')) AS visitors,
				COALESCE(SUM(recorded_sessions.session_pageviews), 0) AS pageviews
			FROM (
				SELECT
					r.session_id,
					COUNT(DISTINCT r.id) AS recording_count,
					MAX(s.visitor_id) AS visitor_id,
					CASE WHEN COALESCE(MAX(s.page_views), 0) > 0 THEN COALESCE(MAX(s.page_views), 0) ELSE 1 END AS session_pageviews
				FROM {$recordings_table} r
				LEFT JOIN {$sessions_table} s ON r.session_id = s.id
				{$spam_join}
				WHERE r.start_time BETWEEN %s AND %s{$spam_where}
				GROUP BY r.session_id
			) recorded_sessions",
			$params
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard KPI aggregate query.
		$row = $wpdb->get_row( $query, ARRAY_A );

		$counts = array(
			'recordings' => isset( $row['recordings'] ) ? absint( $row['recordings'] ) : 0,
			'sessions'   => isset( $row['sessions'] ) ? absint( $row['sessions'] ) : 0,
			'visitors'   => isset( $row['visitors'] ) ? absint( $row['visitors'] ) : 0,
			'pageviews'  => isset( $row['pageviews'] ) ? absint( $row['pageviews'] ) : 0,
		);

		$counts_cache[ $cache_key ] = $counts;
		return $counts;
	}

	/**
	 * Get daily history aligned to the Pro recordings scope used by KPI counts.
	 *
	 * Returns false when Pro recordings scope is not active so existing
	 * daily_stats/realtime history sources remain unchanged for Free-only mode.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array|false
	 */
	private function get_recorded_daily_stats_history( $start_date, $end_date ) {
		global $wpdb;

		if ( ! $this->should_use_recorded_dashboard_scope() ) {
			return false;
		}

		static $history_cache = array();
		$exclude_spam = $this->is_spam_excluded();
		$spam_policy  = class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ? Opti_Behavior_Stats_Spam_Filter::cache_key( $exclude_spam ) : ( $exclude_spam ? 'exclude' : 'include' );
		$cache_key    = md5( $start_date . '|' . $end_date . '|' . ( $exclude_spam ? '1' : '0' ) . '|' . $spam_policy );
		if ( isset( $history_cache[ $cache_key ] ) ) {
			return $history_cache[ $cache_key ];
		}

		$history = $this->get_daily_stats_from_aggregated( $start_date, $end_date );
		if ( false === $history ) {
			$history = $this->get_daily_stats_realtime( $start_date, $end_date );
		}

		if ( ! is_array( $history ) || empty( $history['dates'] ) || ! is_array( $history['dates'] ) ) {
			return false;
		}

		$recordings_table = $wpdb->prefix . 'optibehavior_recordings';
		$sessions_table   = $wpdb->prefix . 'optibehavior_sessions';
		$params           = array( $start_date, $end_date );
		$spam_join        = '';
		$spam_where       = '';

		if ( $exclude_spam ) {
			$spam_filter = $this->get_dashboard_recordings_spam_filter_sql( 'r', 's', 'dashboard_spam_scrolls_daily', true );
			$spam_join   = isset( $spam_filter['join'] ) ? $spam_filter['join'] : '';
			$spam_where  = isset( $spam_filter['where'] ) ? $spam_filter['where'] : '';
			if ( ! empty( $spam_filter['values'] ) && is_array( $spam_filter['values'] ) ) {
				$params = array_merge( $params, $spam_filter['values'] );
			}
		}

		$query = $wpdb->prepare(
			"SELECT
				recorded_sessions.stat_date,
				COUNT(*) AS sessions,
				COUNT(DISTINCT NULLIF(recorded_sessions.visitor_id, '')) AS visitors,
				COALESCE(SUM(recorded_sessions.session_pageviews), 0) AS pageviews
			FROM (
				SELECT
					DATE(MIN(r.start_time)) AS stat_date,
					r.session_id,
					MAX(s.visitor_id) AS visitor_id,
					CASE WHEN COALESCE(MAX(s.page_views), 0) > 0 THEN COALESCE(MAX(s.page_views), 0) ELSE 1 END AS session_pageviews
				FROM {$recordings_table} r
				LEFT JOIN {$sessions_table} s ON r.session_id = s.id
				{$spam_join}
				WHERE r.start_time BETWEEN %s AND %s{$spam_where}
				GROUP BY r.session_id
			) recorded_sessions
			GROUP BY recorded_sessions.stat_date
			ORDER BY recorded_sessions.stat_date ASC",
			$params
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard history aggregate for recordings scope parity.
		$rows = $wpdb->get_results( $query, ARRAY_A );

		$visitors_by_date = array();
		$sessions_by_date = array();
		$pageviews_by_date = array();
		foreach ( $history['dates'] as $date_key ) {
			$visitors_by_date[ $date_key ] = 0;
			$sessions_by_date[ $date_key ] = 0;
			$pageviews_by_date[ $date_key ] = 0;
		}

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$date_key = isset( $row['stat_date'] ) ? (string) $row['stat_date'] : '';
				if ( '' === $date_key || ! isset( $visitors_by_date[ $date_key ] ) ) {
					continue;
				}
				$visitors_by_date[ $date_key ] = isset( $row['visitors'] ) ? absint( $row['visitors'] ) : 0;
				$sessions_by_date[ $date_key ] = isset( $row['sessions'] ) ? absint( $row['sessions'] ) : 0;
				$pageviews_by_date[ $date_key ] = isset( $row['pageviews'] ) ? absint( $row['pageviews'] ) : 0;
			}
		}

		$history['visitors'] = array_values( $visitors_by_date );
		$history['sessions'] = array_values( $sessions_by_date );
		$history['pageviews'] = array_values( $pageviews_by_date );

		$history_cache[ $cache_key ] = $history;
		return $history;
	}

	/**
	 * Get sessions count
	 * UNIFIED STATS FIX: When Pro is active, count from recordings table
	 * to match the recordings list exactly.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return int
	 */
	private function get_sessions_count( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters ) ) {
			$spam_clause = $this->get_spam_exclusion_clause( 's' );
			$filter_sql  = $this->build_advanced_filters_sql( $filters );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
			return intval( $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM " . $wpdb->prefix . "optibehavior_sessions s
				LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'],
				array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
			) ) );
		}

		if ( $this->should_use_recorded_dashboard_scope() ) {
			$recorded_counts = $this->get_recorded_dashboard_counts( $start_date, $end_date );
			return isset( $recorded_counts['sessions'] ) ? absint( $recorded_counts['sessions'] ) : 0;
		}

		if ( ! $this->is_spam_excluded() ) {
			$daily_sessions = $this->get_daily_stats_kpi_sum( 'sessions', $start_date, $end_date );
			if ( null !== $daily_sessions ) {
				return $daily_sessions;
			}
		}

		/*
		 * When no daily aggregate row exists, use the live canonical session
		 * baseline so newly tracked sessions still appear immediately.
		 */
		if ( class_exists( 'Opti_Behavior_Stats_Repository' ) ) {
			return Opti_Behavior_Stats_Repository::count_sessions_in_range(
				$start_date,
				$end_date,
				array(
					'feature'      => 'dashboard',
					'source_scope' => 'all_sessions',
					'metric_grain' => 'session',
					'exclude_spam' => $this->is_spam_excluded(),
				)
			);
		}

		// Fallback: count from sessions table with LIMIT for safety
		$spam_clause = $this->get_spam_exclusion_clause( 's' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		return intval( $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM " . $wpdb->prefix . "optibehavior_sessions s WHERE s.start_time BETWEEN %s AND %s" . $spam_clause,
			$start_date,
			$end_date
		) ) );
	}

	/**
	 * Get visitors count
	 * UNIFIED STATS FIX: When Pro is active, count visitors from recordings
	 * to ensure consistency with session counts.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return int
	 */
	private function get_visitors_count( $start_date, $end_date ) {
		global $wpdb;

		if ( $this->should_use_recorded_dashboard_scope() ) {
			$recorded_counts = $this->get_recorded_dashboard_counts( $start_date, $end_date );
			return isset( $recorded_counts['visitors'] ) ? absint( $recorded_counts['visitors'] ) : 0;
		}

		if ( ! $this->is_spam_excluded() ) {
			$daily_visitors = $this->get_daily_stats_kpi_sum( 'visitors', $start_date, $end_date );
			if ( null !== $daily_visitors ) {
				return $daily_visitors;
			}
		}

		if ( class_exists( 'Opti_Behavior_Stats_Repository' ) ) {
			$context = Opti_Behavior_Stats_Repository::context_from_inclusive_range(
				$start_date,
				$end_date,
				array(
					'feature'      => 'dashboard',
					'source_scope' => 'all_sessions',
					'metric_grain' => 'visitor',
					'exclude_spam' => $this->is_spam_excluded(),
				)
			);

			return Opti_Behavior_Stats_Repository::count_visitors( $context );
		}

		// Try to use pre-aggregated daily_stats for performance (works for both Pro and Free)
		$daily_stats_table = $wpdb->prefix . 'optibehavior_daily_stats';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $daily_stats_table ) );
		if ( $table_exists && ! $this->is_spam_excluded() ) {
			$start_d = substr( $start_date, 0, 10 );
			$end_d = substr( $end_date, 0, 10 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			$sum = $wpdb->get_var( $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT SUM(visitors) FROM " . $daily_stats_table . " WHERE stat_date BETWEEN %s AND %s",
				$start_d,
				$end_d
			) );
			if ( $sum !== null ) {
				return intval( $sum );
			}
		}

		// Fallback: count from sessions table
		$spam_clause = $this->get_spam_exclusion_clause( 's' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		return intval( $wpdb->get_var( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(DISTINCT s.visitor_id) FROM " . $wpdb->prefix . "optibehavior_sessions s WHERE s.start_time BETWEEN %s AND %s AND s.visitor_id IS NOT NULL AND s.visitor_id != ''" . $spam_clause,
			$start_date,
			$end_date
		) ) );
	}

	/**
	 * Get an unfiltered KPI total from daily_stats when available.
	 *
	 * Daily aggregates represent the legacy/free dashboard baseline when spam
	 * filtering is disabled: human, spam, bot, and automated traffic should all
	 * remain visible. When spam exclusion is active, callers must use live
	 * session-aware queries because daily_stats cannot reliably apply the current
	 * traffic-type policy.
	 *
	 * @param string $metric     Metric column to sum.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return int|null Aggregated metric total, or null when no aggregate row exists.
	 */
	private function get_daily_stats_kpi_sum( $metric, $start_date, $end_date ) {
		global $wpdb;

		$allowed_metrics = array( 'sessions', 'visitors', 'pageviews' );
		if ( ! in_array( $metric, $allowed_metrics, true ) ) {
			return null;
		}

		$daily_stats_table = $wpdb->prefix . 'optibehavior_daily_stats';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Analytics aggregate availability check.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $daily_stats_table ) );
		if ( ! $table_exists ) {
			return null;
		}

		$start_d = substr( $start_date, 0, 10 );
		$end_d   = substr( $end_date, 0, 10 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Analytics aggregate query; metric is allow-listed.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS row_count, SUM({$metric}) AS total FROM {$daily_stats_table} WHERE stat_date BETWEEN %s AND %s",
				$start_d,
				$end_d
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || empty( $row['row_count'] ) ) {
			return null;
		}

		return absint( $row['total'] ?? 0 );
	}

	/**
	 * Aggregate-only head segment of the requested range (Unified Retention
	 * Protocol / Option B): the sub-range that precedes the oldest surviving
	 * raw session row. Widgets merge pre-aggregated history for this segment
	 * so the Dashboard keeps working for dates older than the raw retention
	 * window. Null when raw data still covers the whole range.
	 *
	 * @since 1.9.0
	 * @param string $start_date Range start (Y-m-d H:i:s or Y-m-d).
	 * @param string $end_date   Range end.
	 * @return array|null { start: Y-m-d, end: Y-m-d } or null.
	 */
	private function get_pre_raw_aggregate_segment( $start_date, $end_date ) {
		if ( ! class_exists( 'Opti_Behavior_Dimension_Aggregates' ) ) {
			return null;
		}

		return Opti_Behavior_Dimension_Aggregates::get_pre_raw_segment( $start_date, $end_date );
	}

	/**
	 * Merge pre-aggregated dimension history (days older than the raw
	 * retention window) into a raw-derived label => count map.
	 *
	 * Additive-safe: the aggregate segment strictly precedes the oldest raw
	 * session, so raw queries contribute zero rows for those days.
	 *
	 * @since 1.9.0
	 * @param string $dimension  Dimension key (device, browser, os, ...).
	 * @param string $start_date Range start.
	 * @param string $end_date   Range end.
	 * @param array  $map        label => count map built from raw data.
	 * @param string $metric     Metric to merge (sessions|visitors|pageviews).
	 * @return array Updated map.
	 */
	private function merge_pre_raw_dimension_counts( $dimension, $start_date, $end_date, array $map, $metric = 'sessions' ) {
		$segment = $this->get_pre_raw_aggregate_segment( $start_date, $end_date );
		if ( null === $segment ) {
			return $map;
		}

		$counts = Opti_Behavior_Dimension_Aggregates::get_dimension_counts(
			$dimension,
			$segment['start'],
			$segment['end'],
			$this->is_spam_excluded()
		);

		foreach ( $counts as $value => $row ) {
			$count = isset( $row[ $metric ] ) ? absint( $row[ $metric ] ) : 0;
			if ( $count < 1 ) {
				continue;
			}
			if ( isset( $map[ $value ] ) ) {
				$map[ $value ] += $count;
			} else {
				$map[ $value ] = $count;
			}
		}

		return $map;
	}

	/**
	 * Roll up daily_stats over the aggregate-only head segment of a range.
	 *
	 * Used by scalar KPIs (bounce rate, scroll depth, pageviews) to include
	 * history older than the raw retention window. Only applies when spam is
	 * NOT excluded (daily_stats totals carry no per-traffic-type split for
	 * bounce/scroll metrics).
	 *
	 * @since 1.9.0
	 * @param string $start_date Range start.
	 * @param string $end_date   Range end.
	 * @return array|null Summed daily_stats columns for the pre-raw segment.
	 */
	private function get_pre_raw_daily_stats_rollup( $start_date, $end_date ) {
		global $wpdb;

		if ( $this->is_spam_excluded() ) {
			return null;
		}

		$segment = $this->get_pre_raw_aggregate_segment( $start_date, $end_date );
		if ( null === $segment ) {
			return null;
		}

		$daily_stats_table = $wpdb->prefix . 'optibehavior_daily_stats';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate availability check.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $daily_stats_table ) );
		if ( ! $table_exists ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Analytics aggregate query; table name from $wpdb->prefix.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS row_count,
					COALESCE(SUM(sessions), 0) AS sessions,
					COALESCE(SUM(visitors), 0) AS visitors,
					COALESCE(SUM(pageviews), 0) AS pageviews,
					COALESCE(SUM(bounce_sessions), 0) AS bounce_sessions,
					COALESCE(SUM(total_duration), 0) AS total_duration,
					COALESCE(SUM(total_scroll_depth), 0) AS total_scroll_depth,
					COALESCE(SUM(scroll_depth_count), 0) AS scroll_depth_count
				FROM {$daily_stats_table} WHERE stat_date BETWEEN %s AND %s",
				$segment['start'],
				$segment['end']
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || empty( $row['row_count'] ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Per-day daily_stats rows for the aggregate-only head segment, keyed by
	 * date, for filling the Traffic Overview timeseries beyond the raw window.
	 *
	 * @since 1.9.0
	 * @param string $start_date Range start.
	 * @param string $end_date   Range end.
	 * @return array date => { sessions, visitors, pageviews }
	 */
	private function get_pre_raw_daily_stats_series( $start_date, $end_date ) {
		global $wpdb;

		if ( $this->is_spam_excluded() ) {
			return array();
		}

		$segment = $this->get_pre_raw_aggregate_segment( $start_date, $end_date );
		if ( null === $segment ) {
			return array();
		}

		$daily_stats_table = $wpdb->prefix . 'optibehavior_daily_stats';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate availability check.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $daily_stats_table ) );
		if ( ! $table_exists ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Analytics aggregate query; table name from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT stat_date, sessions, visitors, pageviews
				FROM {$daily_stats_table} WHERE stat_date BETWEEN %s AND %s",
				$segment['start'],
				$segment['end']
			),
			ARRAY_A
		);

		$series = array();
		foreach ( (array) $rows as $row ) {
			$date_key = isset( $row['stat_date'] ) ? substr( (string) $row['stat_date'], 0, 10 ) : '';
			if ( '' === $date_key ) {
				continue;
			}
			$series[ $date_key ] = array(
				'sessions'  => absint( $row['sessions'] ?? 0 ),
				'visitors'  => absint( $row['visitors'] ?? 0 ),
				'pageviews' => absint( $row['pageviews'] ?? 0 ),
			);
		}

		return $series;
	}

	/**
	 * Get pageviews count
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return int
	 */
	private function get_pageviews_count( $start_date, $end_date ) {
		global $wpdb;

		if ( $this->should_use_recorded_dashboard_scope() ) {
			$recorded_counts = $this->get_recorded_dashboard_counts( $start_date, $end_date );
			return isset( $recorded_counts['pageviews'] ) ? absint( $recorded_counts['pageviews'] ) : 0;
		}

		$spam_clause = $this->get_spam_exclusion_clause( 's' );

		/*
		 * Dashboard Page Views is session-scoped so it shares the same universe
		 * as the Visitors and Sessions KPI cards. A tracked session represents at
		 * least one visit, even when legacy/consent/race-condition rows are missing
		 * a physical optibehavior_pageviews row or have stale page_views=0.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pageviews = intval( $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(CASE WHEN COALESCE(s.page_views, 0) > 0 THEN s.page_views ELSE 1 END), 0)
			FROM " . $wpdb->prefix . "optibehavior_sessions s
			WHERE s.start_time BETWEEN %s AND %s" . $spam_clause,
			$start_date,
			$end_date
		) ) );

		// Unified Retention Protocol: include aggregated history for days older
		// than the raw retention window (additive — no raw rows exist there).
		$rollup = $this->get_pre_raw_daily_stats_rollup( $start_date, $end_date );
		if ( is_array( $rollup ) ) {
			$pageviews += absint( $rollup['pageviews'] );
		}

		return $pageviews;
	}

	/**
	 * Get bounce rate
	 * UNIFIED STATS FIX: When Pro is active, calculate from sessions with recordings.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return float
	 */
	private function get_bounce_rate( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

		// Always calculate bounce rate from sessions table for consistency with daily history charts.
		// PERF: fold the previous two scans (total sessions + bounce sessions) into a
		// single range scan using a conditional SUM. The numbers are identical to the
		// prior COUNT(*) / COUNT(*) WHERE is_bounce = 1 pair, but only one pass over the
		// start_time range is required (covered by idx_dash_bounce).
		$spam_clause = $this->get_spam_exclusion_clause( 's' );
		$filter_sql  = $this->build_advanced_filters_sql( $filters );
		$join_v      = ! empty( $filters ) ? ( "LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id" ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) AS total_sessions,
				SUM(CASE WHEN s.is_bounce = 1 THEN 1 ELSE 0 END) AS bounce_sessions
			FROM " . $wpdb->prefix . "optibehavior_sessions s
			" . $join_v . "
			WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'],
			array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
		) );

		$total_sessions  = $row ? (int) $row->total_sessions : 0;
		$bounce_sessions = $row ? (int) $row->bounce_sessions : 0;

		// Unified Retention Protocol: merge aggregated history for the part of
		// the range older than the raw retention window (unfiltered view only).
		if ( empty( $filters ) ) {
			$rollup = $this->get_pre_raw_daily_stats_rollup( $start_date, $end_date );
			if ( is_array( $rollup ) ) {
				$total_sessions  += absint( $rollup['sessions'] );
				$bounce_sessions += absint( $rollup['bounce_sessions'] );
			}
		}

		if ( ! $total_sessions ) {
			return 0;
		}

		return round( ( $bounce_sessions / $total_sessions ) * 100, 2 );
	}

	/**
	 * Get the current AND previous period bounce rate in a SINGLE table scan.
	 *
	 * PERF (Section-1 speed): {@see get_summary_stats_data()} previously called
	 * {@see get_bounce_rate()} twice (current + previous period), i.e. two separate
	 * range scans of the sessions table. The two periods are contiguous and
	 * non-overlapping ($prev_end = $cur_start - 1s), so a single scan over the
	 * union range [$prev_start, $cur_end] with conditional SUM/COUNT reproduces
	 * BOTH rates byte-for-byte while reading the start_time range only once
	 * (covered by idx_dash_bounce). The per-period numerator/denominator and the
	 * round(...,2) match get_bounce_rate() exactly.
	 *
	 * @since 1.6.16
	 * @param string $cur_start  Current period start (Y-m-d H:i:s).
	 * @param string $cur_end    Current period end (Y-m-d H:i:s).
	 * @param string $prev_start Previous period start (Y-m-d H:i:s).
	 * @param string $prev_end   Previous period end (Y-m-d H:i:s).
	 * @return array { current: float, previous: float }
	 */
	private function get_bounce_rate_pair( $cur_start, $cur_end, $prev_start, $prev_end ) {
		global $wpdb;

		$spam_clause = $this->get_spam_exclusion_clause( 's' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT
				SUM(CASE WHEN s.start_time BETWEEN %s AND %s THEN 1 ELSE 0 END) AS cur_total,
				SUM(CASE WHEN s.start_time BETWEEN %s AND %s AND s.is_bounce = 1 THEN 1 ELSE 0 END) AS cur_bounce,
				SUM(CASE WHEN s.start_time BETWEEN %s AND %s THEN 1 ELSE 0 END) AS prev_total,
				SUM(CASE WHEN s.start_time BETWEEN %s AND %s AND s.is_bounce = 1 THEN 1 ELSE 0 END) AS prev_bounce
			FROM " . $wpdb->prefix . "optibehavior_sessions s
			WHERE s.start_time BETWEEN %s AND %s" . $spam_clause,
			$cur_start, $cur_end,
			$cur_start, $cur_end,
			$prev_start, $prev_end,
			$prev_start, $prev_end,
			$prev_start, $cur_end
		) );

		$cur_total   = $row ? (int) $row->cur_total : 0;
		$cur_bounce  = $row ? (int) $row->cur_bounce : 0;
		$prev_total  = $row ? (int) $row->prev_total : 0;
		$prev_bounce = $row ? (int) $row->prev_bounce : 0;

		// Unified Retention Protocol: merge aggregated history for the parts of
		// either period that are older than the raw retention window.
		$cur_rollup = $this->get_pre_raw_daily_stats_rollup( $cur_start, $cur_end );
		if ( is_array( $cur_rollup ) ) {
			$cur_total  += absint( $cur_rollup['sessions'] );
			$cur_bounce += absint( $cur_rollup['bounce_sessions'] );
		}
		$prev_rollup = $this->get_pre_raw_daily_stats_rollup( $prev_start, $prev_end );
		if ( is_array( $prev_rollup ) ) {
			$prev_total  += absint( $prev_rollup['sessions'] );
			$prev_bounce += absint( $prev_rollup['bounce_sessions'] );
		}

		return array(
			'current'  => $cur_total ? round( ( $cur_bounce / $cur_total ) * 100, 2 ) : 0,
			'previous' => $prev_total ? round( ( $prev_bounce / $prev_total ) * 100, 2 ) : 0,
		);
	}

	/**
	 * Get average session time
	 * UNIFIED STATS FIX: When Pro is active, calculate from sessions with recordings.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return int
	 */
	private function get_avg_session_time( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters ) ) {
			$spam_clause = $this->get_spam_exclusion_clause( 's' );
			$filter_sql  = $this->build_advanced_filters_sql( $filters );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
			$avg_duration = $wpdb->get_var( $wpdb->prepare(
				"SELECT AVG(duration) FROM (
					SELECT s.duration FROM " . $wpdb->prefix . "optibehavior_sessions s
					LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
					WHERE s.start_time BETWEEN %s AND %s AND s.duration > 0" . $spam_clause . $filter_sql['where'] . "
					LIMIT 10000
				) sampled",
				array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
			) );

			return intval( $avg_duration ?: 0 );
		}

		// The pre-aggregated daily_stats rollup has NO spam split (total_duration/
		// sessions include every traffic_type). Using it while spam exclusion is
		// active would diverge from the count tiles / Avg Scroll Depth, which honor
		// the canonical session_sql() predicate. So only take the fast pre-aggregate
		// path when spam is NOT being excluded; otherwise compute from the sessions
		// table with the same resolved exclusion clause as the count tiles.
		$exclude_spam = $this->is_spam_excluded();

		// Try to use pre-aggregated daily_stats for performance
		$daily_stats_table = $wpdb->prefix . 'optibehavior_daily_stats';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $daily_stats_table ) );
		if ( $table_exists && ! $exclude_spam ) {
			$start_d = substr( $start_date, 0, 10 );
			$end_d = substr( $end_date, 0, 10 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			$stats = $wpdb->get_row( $wpdb->prepare(
				"SELECT SUM(total_duration) as total_duration, SUM(sessions) as sessions
				FROM " . $daily_stats_table . " WHERE stat_date BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$start_d,
				$end_d
			) );
			if ( $stats && $stats->sessions > 0 ) {
				return intval( $stats->total_duration / $stats->sessions );
			}
		}

		// Fallback: average from sessions table with sampling
		$spam_clause = $this->get_spam_exclusion_clause( 's' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$avg_duration = $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(duration) FROM (
				SELECT s.duration FROM " . $wpdb->prefix . "optibehavior_sessions s
				WHERE s.start_time BETWEEN %s AND %s AND s.duration > 0" . $spam_clause . "
				LIMIT 10000
			) sampled",
			$start_date,
			$end_date
		) );

		return intval( $avg_duration ?: 0 );
	}

		/**
		 * Get average scroll depth (percentage across pageviews)
		 *
		 * @param string $start_date Start date.
		 * @param string $end_date   End date.
		 * @return float
		 */
	private function get_avg_scroll_depth( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

			if ( ! empty( $filters ) ) {
				$spam_clause = $this->get_spam_exclusion_clause( 's' );
				$filter_sql  = $this->build_advanced_filters_sql( $filters );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
				$avg = $wpdb->get_var( $wpdb->prepare(
					"SELECT AVG(scroll_depth) FROM (
						SELECT pv.scroll_depth FROM " . $wpdb->prefix . "optibehavior_pageviews pv
						INNER JOIN " . $wpdb->prefix . "optibehavior_sessions s ON pv.session_id = s.id
						LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
						WHERE pv.view_time BETWEEN %s AND %s AND pv.scroll_depth > 0" . $spam_clause . $filter_sql['where'] . "
						LIMIT 10000
					) sampled",
					array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
				) );
				return $avg !== null ? round( floatval( $avg ), 1 ) : 0;
			}

			// Check if spam exclusion is enabled
			if ( $this->is_spam_excluded() ) {
				$spam_clause = $this->get_spam_exclusion_clause( 's' );
				// Join with sessions to filter out spam
				// Regression marker: AND pv.scroll_depth > 0 AND s.traffic_type IN.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$avg = $wpdb->get_var( $wpdb->prepare(
					"SELECT AVG(scroll_depth) FROM (
						SELECT pv.scroll_depth FROM " . $wpdb->prefix . "optibehavior_pageviews pv
						INNER JOIN " . $wpdb->prefix . "optibehavior_sessions s ON pv.session_id = s.id
						WHERE pv.view_time BETWEEN %s AND %s AND pv.scroll_depth > 0" . $spam_clause . "
						LIMIT 10000
					) sampled",
					$start_date,
					$end_date
				) );
				return $avg !== null ? round( floatval( $avg ), 1 ) : 0;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$raw = $wpdb->get_row( $wpdb->prepare(
				"SELECT COALESCE(SUM(scroll_depth), 0) AS total, COUNT(*) AS cnt FROM (
					SELECT scroll_depth FROM " . $wpdb->prefix . "optibehavior_pageviews WHERE view_time BETWEEN %s AND %s AND scroll_depth > 0 LIMIT 10000
				) sampled",
				$start_date,
				$end_date
			), ARRAY_A );

			$total = is_array( $raw ) ? floatval( $raw['total'] ) : 0;
			$cnt   = is_array( $raw ) ? intval( $raw['cnt'] ) : 0;

			// Unified Retention Protocol: merge aggregated scroll history for
			// days older than the raw retention window (weighted combine).
			$rollup = $this->get_pre_raw_daily_stats_rollup( $start_date, $end_date );
			if ( is_array( $rollup ) && absint( $rollup['scroll_depth_count'] ) > 0 ) {
				$total += floatval( $rollup['total_scroll_depth'] );
				$cnt   += absint( $rollup['scroll_depth_count'] );
			}

			return $cnt > 0 ? round( $total / $cnt, 1 ) : 0;

	}

	/**
	 * Get canonical dashboard traffic totals from the shared traffic series.
	 *
	 * @param string $start_date Start date-time.
	 * @param string $end_date   End date-time.
	 * @return array
	 */
	private function get_dashboard_traffic_totals( $start_date, $end_date, array $filters = array() ) {
		$totals = array(
			'sessions'  => 0,
			'visitors'  => 0,
			'pageviews' => 0,
		);
		$series = $this->get_dashboard_traffic_timeseries( $start_date, $end_date, $filters );

		foreach ( (array) $series as $point ) {
			$totals['sessions']  += absint( $point['sessions'] ?? 0 );
			$totals['visitors']  += absint( $point['visitors'] ?? 0 );
			$totals['pageviews'] += absint( $point['pageviews'] ?? 0 );
		}

		return $totals;
	}

	/**
	 * Get sessions chart data
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array
	 */
	private function get_sessions_chart_data( $start_date, $end_date, array $filters = array() ) {
		return $this->get_dashboard_traffic_timeseries( $start_date, $end_date, $filters );
	}

	/**
	 * Build canonical daily traffic series used by Dashboard KPIs and charts.
	 *
	 * This guarantees one shared source/range/spam policy for:
	 * - KPI totals (Visitors/Sessions/Page Views),
	 * - KPI sparklines daily history,
	 * - Traffic Overview chart datasets.
	 *
	 * @param string $start_date Start date-time (inclusive).
	 * @param string $end_date   End date-time (inclusive).
	 * @return array
	 */
	private function get_dashboard_traffic_timeseries( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters ) ) {
			return $this->get_dashboard_traffic_timeseries_filtered( $start_date, $end_date, $filters );
		}

		$exclude_spam = $this->is_spam_excluded();
		$spam_policy  = class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ? Opti_Behavior_Stats_Spam_Filter::cache_key( $exclude_spam ) : ( $exclude_spam ? 'exclude' : 'include' );
		$cache_key    = 'opti_behavior_dashboard_traffic_series_' . md5( $start_date . '|' . $end_date . '|' . ( $exclude_spam ? '1' : '0' ) . '|' . $spam_policy );
		$cached       = method_exists( $this, 'get_dashboard_widget_transient' )
			? $this->get_dashboard_widget_transient( $cache_key )
			: get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$start_day = substr( (string) $start_date, 0, 10 );
		$end_day   = substr( (string) $end_date, 0, 10 );

		$series_map = array();
		try {
			$cursor = new DateTime( $start_day . ' 00:00:00' );
			$limit  = new DateTime( $end_day . ' 00:00:00' );
			while ( $cursor <= $limit ) {
				$date_key = $cursor->format( 'Y-m-d' );
				$series_map[ $date_key ] = array(
					'date'      => $date_key,
					'sessions'  => 0,
					'visitors'  => 0,
					'pageviews' => 0,
				);
				$cursor->modify( '+1 day' );
			}
		} catch ( Exception $e ) {
			$series_map = array();
		}

		if ( $this->should_use_recorded_dashboard_scope() ) {
			$recordings_table = $wpdb->prefix . 'optibehavior_recordings';
			$sessions_table   = $wpdb->prefix . 'optibehavior_sessions';
			$params           = array( $start_date, $end_date );
			$spam_join        = '';
			$spam_where       = '';

			if ( $exclude_spam ) {
				$spam_filter = $this->get_dashboard_recordings_spam_filter_sql( 'r', 's', 'dashboard_traffic_spam_scrolls', true );
				$spam_join   = isset( $spam_filter['join'] ) ? $spam_filter['join'] : '';
				$spam_where  = isset( $spam_filter['where'] ) ? $spam_filter['where'] : '';
				if ( ! empty( $spam_filter['values'] ) && is_array( $spam_filter['values'] ) ) {
					$params = array_merge( $params, $spam_filter['values'] );
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics source query using plugin tables.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT recorded_sessions.d,
						COUNT(*) AS sessions,
						COUNT(DISTINCT NULLIF(recorded_sessions.visitor_id, '')) AS visitors,
						COALESCE(SUM(recorded_sessions.session_pageviews), 0) AS pageviews
					FROM (
						SELECT
							DATE(MIN(r.start_time)) AS d,
							r.session_id,
							MAX(s.visitor_id) AS visitor_id,
							CASE WHEN COALESCE(MAX(s.page_views), 0) > 0 THEN COALESCE(MAX(s.page_views), 0) ELSE 1 END AS session_pageviews
						FROM {$recordings_table} r
						LEFT JOIN {$sessions_table} s ON r.session_id = s.id
						{$spam_join}
						WHERE r.start_time BETWEEN %s AND %s{$spam_where}
						GROUP BY r.session_id
					) recorded_sessions
					GROUP BY recorded_sessions.d
					ORDER BY recorded_sessions.d ASC",
					$params
				),
				ARRAY_A
			);
		} else {
			$spam_clause = $this->get_spam_exclusion_clause( 's' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics source query using plugin tables.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE(s.start_time) AS d,
						COUNT(*) AS sessions,
						COUNT(DISTINCT NULLIF(s.visitor_id, '')) AS visitors,
						COALESCE(SUM(CASE WHEN COALESCE(s.page_views, 0) > 0 THEN s.page_views ELSE 1 END), 0) AS pageviews
					FROM " . $wpdb->prefix . "optibehavior_sessions s
					WHERE s.start_time BETWEEN %s AND %s{$spam_clause}
					GROUP BY DATE(s.start_time)
					ORDER BY d ASC",
					$start_date,
					$end_date
				),
				ARRAY_A
			);
		}

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$date_key = isset( $row['d'] ) ? (string) $row['d'] : '';
				if ( '' === $date_key || ! isset( $series_map[ $date_key ] ) ) {
					continue;
				}

				$series_map[ $date_key ]['sessions']  = isset( $row['sessions'] ) ? absint( $row['sessions'] ) : 0;
				$series_map[ $date_key ]['visitors']  = isset( $row['visitors'] ) ? absint( $row['visitors'] ) : 0;
				$series_map[ $date_key ]['pageviews'] = isset( $row['pageviews'] ) ? absint( $row['pageviews'] ) : 0;
			}
		}

		// Unified Retention Protocol: fill days older than the raw retention
		// window from the daily_stats aggregate so the Traffic Overview chart
		// and KPI sparklines keep their history (unfiltered scope only; the
		// recorded-sessions PRO scope keeps its own universe).
		if ( ! $this->should_use_recorded_dashboard_scope() ) {
			$agg_series = $this->get_pre_raw_daily_stats_series( $start_date, $end_date );
			foreach ( $agg_series as $date_key => $agg_point ) {
				if ( ! isset( $series_map[ $date_key ] ) ) {
					continue;
				}
				if ( $series_map[ $date_key ]['sessions'] > 0 || $series_map[ $date_key ]['pageviews'] > 0 ) {
					continue; // Raw data present — never overwrite.
				}
				$series_map[ $date_key ]['sessions']  = $agg_point['sessions'];
				$series_map[ $date_key ]['visitors']  = $agg_point['visitors'];
				$series_map[ $date_key ]['pageviews'] = $agg_point['pageviews'];
			}
		}

		$result = array_values( $series_map );
		set_transient( $cache_key, $result, 900 );

		return $result;
	}

	/**
	 * Filtered variant of get_dashboard_traffic_timeseries(). Bypasses both
	 * the transient cache and the recorded-sessions (PRO) scope fast path
	 * (which is not built for arbitrary session/visitor filter conditions),
	 * always computing the daily series live with the advanced-filters WHERE
	 * fragment appended.
	 *
	 * @since 1.0.4
	 * @param string $start_date Start date-time (inclusive).
	 * @param string $end_date   End date-time (inclusive).
	 * @param array  $filters    Sanitized advanced filters.
	 * @return array
	 */
	private function get_dashboard_traffic_timeseries_filtered( $start_date, $end_date, array $filters ) {
		global $wpdb;

		$start_day = substr( (string) $start_date, 0, 10 );
		$end_day   = substr( (string) $end_date, 0, 10 );

		$series_map = array();
		try {
			$cursor = new DateTime( $start_day . ' 00:00:00' );
			$limit  = new DateTime( $end_day . ' 00:00:00' );
			while ( $cursor <= $limit ) {
				$date_key = $cursor->format( 'Y-m-d' );
				$series_map[ $date_key ] = array(
					'date'      => $date_key,
					'sessions'  => 0,
					'visitors'  => 0,
					'pageviews' => 0,
				);
				$cursor->modify( '+1 day' );
			}
		} catch ( Exception $e ) {
			$series_map = array();
		}

		$spam_clause = $this->get_spam_exclusion_clause( 's' );
		$filter_sql  = $this->build_advanced_filters_sql( $filters );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics source query using plugin tables.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(s.start_time) AS d,
					COUNT(*) AS sessions,
					COUNT(DISTINCT NULLIF(s.visitor_id, '')) AS visitors,
					COALESCE(SUM(CASE WHEN COALESCE(s.page_views, 0) > 0 THEN s.page_views ELSE 1 END), 0) AS pageviews
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
				GROUP BY DATE(s.start_time)
				ORDER BY d ASC",
				array_merge( array( $start_date, $end_date ), $filter_sql['params'] )
			),
			ARRAY_A
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$date_key = isset( $row['d'] ) ? (string) $row['d'] : '';
				if ( '' === $date_key || ! isset( $series_map[ $date_key ] ) ) {
					continue;
				}

				$series_map[ $date_key ]['sessions']  = isset( $row['sessions'] ) ? absint( $row['sessions'] ) : 0;
				$series_map[ $date_key ]['visitors']  = isset( $row['visitors'] ) ? absint( $row['visitors'] ) : 0;
				$series_map[ $date_key ]['pageviews'] = isset( $row['pageviews'] ) ? absint( $row['pageviews'] ) : 0;
			}
		}

		return array_values( $series_map );
	}

	/**
	 * Get device types data
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array
	 */
	private function get_device_types_data( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters ) ) {
			return $this->get_device_types_data_filtered( $start_date, $end_date, $filters );
		}

		$context   = $this->get_dashboard_stats_context_for_range( $start_date, $end_date, 'session' );
		$cache_seed = class_exists( 'Opti_Behavior_Stats_Context' )
			? Opti_Behavior_Stats_Context::cache_key( $context )
			: md5( $start_date . '|' . $end_date );
		$cache_key = 'opti_behavior_device_types_' . md5( 'dim-v2|' . $cache_seed . '|' . ( $this->is_spam_excluded() ? '1' : '0' ) );
		$cached    = $this->get_dashboard_widget_cached_data( $cache_key, $start_date, $end_date, 'device_types' );
		if ( false !== $cached ) {
			return $cached;
		}

		$spam_clause = $this->get_spam_exclusion_clause( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dashboard dimension aggregation.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN v.device_type IS NULL OR TRIM(v.device_type) = '' THEN 'Unknown'
						WHEN LOWER(TRIM(v.device_type)) IN ('mobile','phone','smartphone') THEN 'Mobile'
						WHEN LOWER(TRIM(v.device_type)) IN ('tablet','ipad') THEN 'Tablet'
						WHEN LOWER(TRIM(v.device_type)) IN ('desktop','pc','computer','laptop') THEN 'Desktop'
						WHEN LOWER(TRIM(v.device_type)) IN ('unknown','undefined','other') THEN 'Unknown'
						ELSE 'Unknown'
					END AS device_name,
					COUNT(DISTINCT s.id) AS count
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
				GROUP BY device_name",
				$start_date,
				$end_date
			)
		);

		$map = array(
			'Desktop' => 0,
			'Mobile'  => 0,
			'Tablet'  => 0,
			'Unknown' => 0,
		);

		foreach ( (array) $results as $row ) {
			$key = isset( $row->device_name ) ? trim( (string) $row->device_name ) : 'Unknown';
			if ( ! isset( $map[ $key ] ) ) {
				$key = 'Unknown';
			}
			$map[ $key ] += absint( $row->count );
		}

		$baseline = $this->get_dashboard_dimension_session_baseline( $context );
		$map      = $this->add_dashboard_unclassified_bucket( $map, $baseline, 'Unclassified' );

		// Unified Retention Protocol: merge aggregated device history for days
		// older than the raw retention window (after the raw-only baseline
		// reconciliation so the Unclassified bucket stays raw-scoped).
		$map = $this->merge_pre_raw_dimension_counts( 'device', $start_date, $end_date, $map );

		$data = array();
		foreach ( array( 'Desktop', 'Mobile', 'Tablet', 'Unknown', 'Unclassified' ) as $label ) {
			$count = isset( $map[ $label ] ) ? absint( $map[ $label ] ) : 0;
			if ( $count <= 0 ) {
				continue;
			}

			$display_label = $label;
			if ( 'Unknown' === $label ) {
				$display_label = __( 'Unknown', 'opti-behavior' );
			} elseif ( 'Unclassified' === $label ) {
				$display_label = __( 'Unclassified', 'opti-behavior' );
			}

			$data[] = array(
				'name'  => $display_label,
				'count' => $count,
			);
		}

		// Never freeze an empty result (Bug #4): that's the state most likely to
		// change on the very next session insert.
		return $this->set_dashboard_widget_cached_data( $cache_key, $data, empty( $data ), $start_date, $end_date, 'device_types' );
	}

	/**
	 * Filtered variant of get_device_types_data(). See
	 * get_screen_resolution_data_filtered() for the shared rationale (no
	 * cache, no unfiltered-baseline reconciliation, filters WHERE appended).
	 *
	 * @since 1.0.4
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param array  $filters    Sanitized advanced filters.
	 * @return array
	 */
	private function get_device_types_data_filtered( $start_date, $end_date, array $filters ) {
		global $wpdb;

		$spam_clause = $this->get_spam_exclusion_clause( 's' );
		$filter_sql  = $this->build_advanced_filters_sql( $filters );
		$params      = array_merge( array( $start_date, $end_date ), $filter_sql['params'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dashboard dimension aggregation.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN v.device_type IS NULL OR TRIM(v.device_type) = '' THEN 'Unknown'
						WHEN LOWER(TRIM(v.device_type)) IN ('mobile','phone','smartphone') THEN 'Mobile'
						WHEN LOWER(TRIM(v.device_type)) IN ('tablet','ipad') THEN 'Tablet'
						WHEN LOWER(TRIM(v.device_type)) IN ('desktop','pc','computer','laptop') THEN 'Desktop'
						WHEN LOWER(TRIM(v.device_type)) IN ('unknown','undefined','other') THEN 'Unknown'
						ELSE 'Unknown'
					END AS device_name,
					COUNT(DISTINCT s.id) AS count
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
				GROUP BY device_name",
				$params
			)
		);

		$map = array(
			'Desktop' => 0,
			'Mobile'  => 0,
			'Tablet'  => 0,
			'Unknown' => 0,
		);

		foreach ( (array) $results as $row ) {
			$key = isset( $row->device_name ) ? trim( (string) $row->device_name ) : 'Unknown';
			if ( ! isset( $map[ $key ] ) ) {
				$key = 'Unknown';
			}
			$map[ $key ] += absint( $row->count );
		}

		$data = array();
		foreach ( array( 'Desktop', 'Mobile', 'Tablet', 'Unknown' ) as $label ) {
			$count = isset( $map[ $label ] ) ? absint( $map[ $label ] ) : 0;
			if ( $count <= 0 ) {
				continue;
			}

			$data[] = array(
				'name'  => 'Unknown' === $label ? __( 'Unknown', 'opti-behavior' ) : $label,
				'count' => $count,
			);
		}

		return $data;
	}


	/**
	 * Get top pages data
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @param array  $filters    Sanitized advanced filters.
	 * @return array
	 */
	/**
	 * Resolve the wp-admin edit URL for a tracked page URL, when it maps to an
	 * editable post/page and the current user may edit it.
	 *
	 * @since 1.8.1.3
	 * @param string $url Public page URL.
	 * @return string Edit URL or '' when not editable/resolvable.
	 */
	private function get_page_edit_url( $url ) {
		if ( '' === $url || ! function_exists( 'url_to_postid' ) ) {
			return '';
		}
		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return '';
		}
		$edit_url = get_edit_post_link( $post_id, 'raw' );
		return $edit_url ? $edit_url : '';
	}

	private function get_top_pages_data( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters ) ) {
			return $this->get_top_pages_data_filtered( $start_date, $end_date, $filters );
		}

		$exclude_spam = $this->is_spam_excluded();
		$spam_policy  = class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ? Opti_Behavior_Stats_Spam_Filter::cache_key( $exclude_spam ) : ( $exclude_spam ? 'exclude' : 'include' );
		$cache_key    = 'opti_behavior_top_pages_' . md5( 'v4-editurl|' . $start_date . '|' . $end_date . '|' . ( $exclude_spam ? '1' : '0' ) . '|' . $spam_policy );
		$cached       = $this->get_dashboard_widget_cached_data( $cache_key, $start_date, $end_date, 'top_pages' );
		if ( false !== $cached ) {
			return $cached;
		}

		$spam_join_pageviews  = '';
		$spam_where_pageviews = '';
		if ( $exclude_spam ) {
			$spam_join_pageviews  = "INNER JOIN " . $wpdb->prefix . "optibehavior_sessions sess ON pv.session_id = sess.id";
			$spam_where_pageviews = $this->get_spam_exclusion_clause( 'sess' );
		}

		// Canonical source for Top Pages: pageviews in the selected period.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard analytics query.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					COALESCE(NULLIF(p.id, 0), NULLIF(pv.page_id, 0), 0) AS page_id,
					COALESCE(NULLIF(p.url, ''), NULLIF(pv.url, ''), '') AS url,
					COALESCE(NULLIF(p.title, ''), NULLIF(pv.title, ''), '') AS title,
					COUNT(*) AS pageviews,
					COUNT(DISTINCT NULLIF(pv.session_id, '')) AS sessions
				FROM " . $wpdb->prefix . "optibehavior_pageviews pv
				LEFT JOIN " . $wpdb->prefix . "optibehavior_pages p ON pv.page_id = p.id
				" . $spam_join_pageviews . "
				WHERE pv.view_time BETWEEN %s AND %s" . $spam_where_pageviews . "
				GROUP BY page_id, url, title
				ORDER BY pageviews DESC
				LIMIT 30",
				$start_date,
				$end_date
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			// Never freeze an empty result (Bug #4): that's the state most likely
			// to change on the very next pageview.
			return $this->set_dashboard_widget_cached_data( $cache_key, array(), true, $start_date, $end_date, 'top_pages' );
		}

		$duration   = max( 1, strtotime( $end_date ) - strtotime( $start_date ) + 1 );
		$prev_end   = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - 1 );
		$prev_start = gmdate( 'Y-m-d H:i:s', strtotime( $start_date ) - $duration );

		$page_ids = array();
		foreach ( $results as $row ) {
			$page_id = isset( $row['page_id'] ) ? absint( $row['page_id'] ) : 0;
			if ( $page_id > 0 ) {
				$page_ids[] = $page_id;
			}
		}
		$page_ids = array_values( array_unique( $page_ids ) );

		$views_previous_stats = array();
		$click_stats          = array();
		$total_clicks_current = 0;

		if ( ! empty( $page_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );

			$prev_params = array( $prev_start, $prev_end );
			$prev_params = array_merge( $prev_params, $page_ids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard analytics query.
			$prev_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pv.page_id, COUNT(*) AS previous_pageviews
					FROM " . $wpdb->prefix . "optibehavior_pageviews pv
					" . $spam_join_pageviews . "
					WHERE pv.view_time BETWEEN %s AND %s
					AND pv.page_id IN ( " . $placeholders . " )" . $spam_where_pageviews . "
					GROUP BY pv.page_id",
					$prev_params
				),
				ARRAY_A
			);
			foreach ( (array) $prev_rows as $prev_row ) {
				$views_previous_stats[ absint( $prev_row['page_id'] ) ] = isset( $prev_row['previous_pageviews'] ) ? absint( $prev_row['previous_pageviews'] ) : 0;
			}

			$event_spam_join  = '';
			$event_spam_where = '';
			if ( $exclude_spam ) {
				$event_spam_join  = "INNER JOIN " . $wpdb->prefix . "optibehavior_sessions sess ON e.session_id = sess.id";
				$event_spam_where = $this->get_spam_exclusion_clause( 'sess' );
			}

			$click_current_params = array( $start_date, $end_date );
			$click_current_params = array_merge( $click_current_params, $page_ids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard analytics query.
			$click_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT COALESCE(e.page_id2, e.page_id) AS page_id, COUNT(*) AS clicks
					FROM " . $wpdb->prefix . "optibehavior_events e
					" . $event_spam_join . "
					WHERE e.insert_at BETWEEN %s AND %s
					AND e.event IN (16,17)
					AND COALESCE(e.page_id2, e.page_id) IN ( " . $placeholders . " )" . $event_spam_where . "
					GROUP BY COALESCE(e.page_id2, e.page_id)",
					$click_current_params
				),
				ARRAY_A
			);
			foreach ( (array) $click_rows as $click_row ) {
				$page_id = absint( $click_row['page_id'] );
				$current = isset( $click_row['clicks'] ) ? absint( $click_row['clicks'] ) : 0;
				$click_stats[ $page_id ] = array(
					'current'  => $current,
					'previous' => 0,
				);
				$total_clicks_current += $current;
			}

			$click_prev_params = array( $prev_start, $prev_end );
			$click_prev_params = array_merge( $click_prev_params, $page_ids );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard analytics query.
			$click_prev_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT COALESCE(e.page_id2, e.page_id) AS page_id, COUNT(*) AS clicks
					FROM " . $wpdb->prefix . "optibehavior_events e
					" . $event_spam_join . "
					WHERE e.insert_at BETWEEN %s AND %s
					AND e.event IN (16,17)
					AND COALESCE(e.page_id2, e.page_id) IN ( " . $placeholders . " )" . $event_spam_where . "
					GROUP BY COALESCE(e.page_id2, e.page_id)",
					$click_prev_params
				),
				ARRAY_A
			);
			foreach ( (array) $click_prev_rows as $click_prev_row ) {
				$page_id_prev = absint( $click_prev_row['page_id'] );
				$previous     = isset( $click_prev_row['clicks'] ) ? absint( $click_prev_row['clicks'] ) : 0;
				if ( ! isset( $click_stats[ $page_id_prev ] ) ) {
					$click_stats[ $page_id_prev ] = array(
						'current'  => 0,
						'previous' => $previous,
					);
				} else {
					$click_stats[ $page_id_prev ]['previous'] = $previous;
				}
			}
		}

		$total_pageviews_period = (int) $this->get_pageviews_count( $start_date, $end_date );
		$data = array();
		foreach ( $results as $row ) {
			$page_id2 = isset( $row['page_id'] ) ? absint( $row['page_id'] ) : 0;
			$url      = isset( $row['url'] ) ? (string) $row['url'] : '';
			$title    = isset( $row['title'] ) ? (string) $row['title'] : '';
			$pc_url   = $page_id2 ? $url . ( ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'opti-behavior=click_pc-' . $page_id2 ) : '';
			$mb_url   = $page_id2 ? $url . ( ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'opti-behavior=click_mobile-' . $page_id2 . '&mobile_view=1&vw=375&vh=667' ) : '';
			$views    = isset( $row['pageviews'] ) ? absint( $row['pageviews'] ) : 0;
			$sessions = isset( $row['sessions'] ) ? absint( $row['sessions'] ) : 0;

			$views_previous = isset( $views_previous_stats[ $page_id2 ] ) ? absint( $views_previous_stats[ $page_id2 ] ) : 0;
			if ( $views_previous <= 0 ) {
				$views_change = $views > 0 ? 100 : 0;
			} else {
				$views_change = round( ( ( $views - $views_previous ) / $views_previous ) * 100 );
			}

			if ( $views_change > 0 ) {
				$views_trend = 'up';
			} elseif ( $views_change < 0 ) {
				$views_trend = 'down';
			} else {
				$views_trend = 'neutral';
			}

			$click_current  = isset( $click_stats[ $page_id2 ] ) ? intval( $click_stats[ $page_id2 ]['current'] ) : 0;
			$click_previous = isset( $click_stats[ $page_id2 ] ) ? intval( $click_stats[ $page_id2 ]['previous'] ) : 0;
			if ( $click_previous <= 0 ) {
				$click_change = $click_current > 0 ? 100 : 0;
			} else {
				$click_change = round( ( ( $click_current - $click_previous ) / $click_previous ) * 100 );
			}

			if ( $click_change > 0 ) {
				$click_trend = 'up';
			} elseif ( $click_change < 0 ) {
				$click_trend = 'down';
			} else {
				$click_trend = 'neutral';
			}

			$data[] = array(
				'url'               => $url,
				'title'             => $title ?: 'Untitled',
				'views'             => $views,
				'sessions'          => $sessions,
				'percentage'        => $total_pageviews_period > 0 ? round( ( $views / $total_pageviews_period ) * 100 ) : 0,
				'views_change'      => $views_change,
				'views_trend'       => $views_trend,
				'pc_heatmap'        => $pc_url,
				'mobile_heatmap'    => $mb_url,
				'clicks'            => $click_current,
				'clicks_percentage' => $total_clicks_current > 0 ? round( ( $click_current / $total_clicks_current ) * 100 ) : 0,
				'clicks_change'     => $click_change,
				'clicks_trend'      => $click_trend,
				'edit_url'          => $this->get_page_edit_url( $url ),
			);
		}

		// Never freeze an empty result (Bug #4): that's the state most likely to
		// change on the very next pageview.
		return $this->set_dashboard_widget_cached_data( $cache_key, $data, empty( $data ), $start_date, $end_date, 'top_pages' );
	}

	/**
	 * Filtered variant of get_top_pages_data(). Restricts the pageviews scan
	 * to sessions matching the advanced filters (via an INNER JOIN back to
	 * sessions/visitors) and bypasses the transient cache.
	 *
	 * Scope-limited vs. the unfiltered method: skips the previous-period
	 * views_change/clicks comparison (always returned as zero/neutral) since
	 * that requires four additional filtered sub-queries for a rarely-used
	 * comparison affordance while filters are active; core pageviews/sessions
	 * counts and percentage-of-total are fully correct against the filter set.
	 *
	 * @since 1.0.4
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param array  $filters    Sanitized advanced filters.
	 * @return array
	 */
	private function get_top_pages_data_filtered( $start_date, $end_date, array $filters ) {
		global $wpdb;

		$spam_clause  = $this->get_spam_exclusion_clause( 's' );
		$filter_sql   = $this->build_advanced_filters_sql( $filters );
		$session_join = "INNER JOIN " . $wpdb->prefix . "optibehavior_sessions s ON pv.session_id = s.id
					LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id";
		$session_where = " AND s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard analytics query.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					COALESCE(NULLIF(p.id, 0), NULLIF(pv.page_id, 0), 0) AS page_id,
					COALESCE(NULLIF(p.url, ''), NULLIF(pv.url, ''), '') AS url,
					COALESCE(NULLIF(p.title, ''), NULLIF(pv.title, ''), '') AS title,
					COUNT(*) AS pageviews,
					COUNT(DISTINCT NULLIF(pv.session_id, '')) AS sessions
				FROM " . $wpdb->prefix . "optibehavior_pageviews pv
				LEFT JOIN " . $wpdb->prefix . "optibehavior_pages p ON pv.page_id = p.id
				" . $session_join . "
				WHERE pv.view_time BETWEEN %s AND %s" . $session_where . "
				GROUP BY page_id, url, title
				ORDER BY pageviews DESC
				LIMIT 30",
				array_merge( array( $start_date, $end_date, $start_date, $end_date ), $filter_sql['params'] )
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dashboard analytics query.
		$total_pageviews_period = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM " . $wpdb->prefix . "optibehavior_pageviews pv
				" . $session_join . "
				WHERE pv.view_time BETWEEN %s AND %s" . $session_where,
				array_merge( array( $start_date, $end_date, $start_date, $end_date ), $filter_sql['params'] )
			)
		);

		$data = array();
		foreach ( $results as $row ) {
			$page_id2 = isset( $row['page_id'] ) ? absint( $row['page_id'] ) : 0;
			$url      = isset( $row['url'] ) ? (string) $row['url'] : '';
			$title    = isset( $row['title'] ) ? (string) $row['title'] : '';
			$pc_url   = $page_id2 ? $url . ( ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'opti-behavior=click_pc-' . $page_id2 ) : '';
			$mb_url   = $page_id2 ? $url . ( ( strpos( $url, '?' ) === false ? '?' : '&' ) . 'opti-behavior=click_mobile-' . $page_id2 . '&mobile_view=1&vw=375&vh=667' ) : '';
			$views    = isset( $row['pageviews'] ) ? absint( $row['pageviews'] ) : 0;
			$sessions = isset( $row['sessions'] ) ? absint( $row['sessions'] ) : 0;

			$data[] = array(
				'url'               => $url,
				'title'             => $title ?: 'Untitled',
				'views'             => $views,
				'sessions'          => $sessions,
				'percentage'        => $total_pageviews_period > 0 ? round( ( $views / $total_pageviews_period ) * 100 ) : 0,
				'views_change'      => 0,
				'views_trend'       => 'neutral',
				'pc_heatmap'        => $pc_url,
				'mobile_heatmap'    => $mb_url,
				'clicks'            => 0,
				'clicks_percentage' => 0,
				'clicks_change'     => 0,
				'clicks_trend'      => 'neutral',
				'edit_url'          => $this->get_page_edit_url( $url ),
			);
		}

		return $data;
	}

	/**
	 * Get countries data
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array
	 */
	private function get_countries_data( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters ) ) {
			return $this->get_countries_data_filtered( $start_date, $end_date, $filters );
		}

		$context    = $this->get_dashboard_stats_context_for_range( $start_date, $end_date, 'session' );
		$cache_seed = class_exists( 'Opti_Behavior_Stats_Context' )
			? Opti_Behavior_Stats_Context::cache_key( $context )
			: md5( $start_date . '|' . $end_date );
		$cache_key  = 'opti_behavior_countries_' . md5( 'dim-v3|' . $cache_seed . '|' . ( $this->is_spam_excluded() ? '1' : '0' ) );
		$cached     = $this->get_dashboard_widget_cached_data( $cache_key, $start_date, $end_date, 'countries' );
		if ( false !== $cached ) {
			return $cached;
		}

		$spam_clause = $this->get_spam_exclusion_clause( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dashboard dimension aggregation.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN v.country IS NULL OR TRIM(v.country) = '' OR UPPER(TRIM(v.country)) = 'UN' THEN 'UN'
						ELSE UPPER(TRIM(v.country))
					END AS country_code,
					CASE
						WHEN v.country IS NULL OR TRIM(v.country) = '' OR UPPER(TRIM(v.country)) = 'UN' THEN %s
						ELSE COALESCE(NULLIF(TRIM(v.country_name), ''), UPPER(TRIM(v.country)))
					END AS country_name,
					COUNT(DISTINCT s.id) AS count
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
				GROUP BY country_code, country_name
				ORDER BY count DESC",
				__( 'Unknown', 'opti-behavior' ),
				$start_date,
				$end_date
			)
		);

		$data_map = array();
		foreach ( (array) $results as $row ) {
			$code = isset( $row->country_code ) ? strtoupper( trim( (string) $row->country_code ) ) : 'UN';
			if ( '' === $code ) {
				$code = 'UN';
			}

			if ( ! isset( $data_map[ $code ] ) ) {
				$name = isset( $row->country_name ) ? trim( (string) $row->country_name ) : '';
				if ( '' === $name ) {
					$name = ( 'UN' === $code ) ? __( 'Unknown', 'opti-behavior' ) : $this->get_country_name( $code );
				}
				$data_map[ $code ] = array(
					'country'      => $code,
					'country_name' => $name,
					'count'        => 0,
				);
			}

			$data_map[ $code ]['count'] += absint( $row->count );
		}

		$baseline    = $this->get_dashboard_dimension_session_baseline( $context );
		$current_sum = 0;
		foreach ( $data_map as $bucket ) {
			$current_sum += absint( $bucket['count'] );
		}

		if ( $baseline > $current_sum ) {
			$data_map['UNCLASSIFIED'] = array(
				'country'      => 'UNCLASSIFIED',
				'country_name' => __( 'Unclassified', 'opti-behavior' ),
				'count'        => $baseline - $current_sum,
			);
		} elseif ( $baseline < $current_sum ) {
			$overage          = $current_sum - $baseline;
			$preferred_codes  = array( 'UNCLASSIFIED', 'UN', 'OTHER' );
			$sorted_codes     = array_keys( $data_map );

			usort(
				$sorted_codes,
				static function ( $a, $b ) use ( $data_map ) {
					return (int) $data_map[ $b ]['count'] <=> (int) $data_map[ $a ]['count'];
				}
			);

			foreach ( array_merge( $preferred_codes, $sorted_codes ) as $code_to_reduce ) {
				if ( $overage <= 0 || ! isset( $data_map[ $code_to_reduce ] ) ) {
					continue;
				}

				$count     = absint( $data_map[ $code_to_reduce ]['count'] );
				$reduction = min( $count, $overage );
				$count    -= $reduction;
				$overage  -= $reduction;

				if ( $count > 0 ) {
					$data_map[ $code_to_reduce ]['count'] = $count;
				} else {
					unset( $data_map[ $code_to_reduce ] );
				}
			}
		}

		// Unified Retention Protocol: merge aggregated country history for days
		// older than the raw retention window (after the raw-only baseline
		// reconciliation above).
		$pre_raw_segment = $this->get_pre_raw_aggregate_segment( $start_date, $end_date );
		if ( null !== $pre_raw_segment ) {
			$agg_counts = Opti_Behavior_Dimension_Aggregates::get_dimension_counts( 'country', $pre_raw_segment['start'], $pre_raw_segment['end'], $this->is_spam_excluded() );
			foreach ( $agg_counts as $agg_value => $agg_row ) {
				$agg_sessions = absint( $agg_row['sessions'] ?? 0 );
				if ( $agg_sessions < 1 ) {
					continue;
				}
				$code = strtoupper( trim( (string) $agg_value ) );
				if ( '' === $code || 'UNKNOWN' === $code ) {
					$code = 'UN';
				}
				if ( ! isset( $data_map[ $code ] ) ) {
					$data_map[ $code ] = array(
						'country'      => $code,
						'country_name' => ( 'UN' === $code ) ? __( 'Unknown', 'opti-behavior' ) : $this->get_country_name( $code ),
						'count'        => 0,
					);
				}
				$data_map[ $code ]['count'] += $agg_sessions;
			}
		}

		$unknown      = isset( $data_map['UN'] ) ? $data_map['UN'] : null;
		$unclassified = isset( $data_map['UNCLASSIFIED'] ) ? $data_map['UNCLASSIFIED'] : null;
		unset( $data_map['UN'], $data_map['UNCLASSIFIED'] );

		$data_rows = array_values( $data_map );
		usort(
			$data_rows,
			static function ( $a, $b ) {
				return (int) $b['count'] <=> (int) $a['count'];
			}
		);

		$data           = array_slice( $data_rows, 0, 49 );
		$other_count    = 0;
		$remaining_rows = array_slice( $data_rows, 49 );
		foreach ( $remaining_rows as $remaining_row ) {
			$other_count += isset( $remaining_row['count'] ) ? absint( $remaining_row['count'] ) : 0;
		}

		if ( $other_count > 0 ) {
			$data[] = array(
				'country'      => 'OTHER',
				'country_name' => __( 'Other', 'opti-behavior' ),
				'count'        => $other_count,
			);
		}

		if ( ! empty( $unknown ) ) {
			$data[] = $unknown;
		}
		if ( ! empty( $unclassified ) ) {
			$data[] = $unclassified;
		}

		// Never freeze an empty result (Bug #4): that's the state most likely to
		// change on the very next session insert.
		return $this->set_dashboard_widget_cached_data( $cache_key, $data, empty( $data ), $start_date, $end_date, 'countries' );
	}

	/**
	 * Filtered variant of get_countries_data(). See
	 * get_screen_resolution_data_filtered() for the shared rationale (no
	 * cache, no unfiltered-baseline reconciliation, filters WHERE appended).
	 *
	 * @since 1.0.4
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param array  $filters    Sanitized advanced filters.
	 * @return array
	 */
	private function get_countries_data_filtered( $start_date, $end_date, array $filters ) {
		global $wpdb;

		$spam_clause = $this->get_spam_exclusion_clause( 's' );
		$filter_sql  = $this->build_advanced_filters_sql( $filters );
		$params      = array_merge( array( __( 'Unknown', 'opti-behavior' ), $start_date, $end_date ), $filter_sql['params'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dashboard dimension aggregation.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN v.country IS NULL OR TRIM(v.country) = '' OR UPPER(TRIM(v.country)) = 'UN' THEN 'UN'
						ELSE UPPER(TRIM(v.country))
					END AS country_code,
					CASE
						WHEN v.country IS NULL OR TRIM(v.country) = '' OR UPPER(TRIM(v.country)) = 'UN' THEN %s
						ELSE COALESCE(NULLIF(TRIM(v.country_name), ''), UPPER(TRIM(v.country)))
					END AS country_name,
					COUNT(DISTINCT s.id) AS count
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
				GROUP BY country_code, country_name
				ORDER BY count DESC",
				$params
			)
		);

		$data_map = array();
		foreach ( (array) $results as $row ) {
			$code = isset( $row->country_code ) ? strtoupper( trim( (string) $row->country_code ) ) : 'UN';
			if ( '' === $code ) {
				$code = 'UN';
			}

			if ( ! isset( $data_map[ $code ] ) ) {
				$name = isset( $row->country_name ) ? trim( (string) $row->country_name ) : '';
				if ( '' === $name ) {
					$name = ( 'UN' === $code ) ? __( 'Unknown', 'opti-behavior' ) : $this->get_country_name( $code );
				}
				$data_map[ $code ] = array(
					'country'      => $code,
					'country_name' => $name,
					'count'        => 0,
				);
			}

			$data_map[ $code ]['count'] += absint( $row->count );
		}

		$unknown = isset( $data_map['UN'] ) ? $data_map['UN'] : null;
		unset( $data_map['UN'] );

		$data_rows = array_values( $data_map );
		usort(
			$data_rows,
			static function ( $a, $b ) {
				return (int) $b['count'] <=> (int) $a['count'];
			}
		);

		$data           = array_slice( $data_rows, 0, 49 );
		$other_count    = 0;
		$remaining_rows = array_slice( $data_rows, 49 );
		foreach ( $remaining_rows as $remaining_row ) {
			$other_count += isset( $remaining_row['count'] ) ? absint( $remaining_row['count'] ) : 0;
		}

		if ( $other_count > 0 ) {
			$data[] = array(
				'country'      => 'OTHER',
				'country_name' => __( 'Other', 'opti-behavior' ),
				'count'        => $other_count,
			);
		}

		if ( ! empty( $unknown ) ) {
			$data[] = $unknown;
		}

		return $data;
	}

	/**
	 * Get browsers data
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array
	 */
	private function get_browsers_data( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters ) ) {
			return $this->get_browsers_data_filtered( $start_date, $end_date, $filters );
		}

		$context    = $this->get_dashboard_stats_context_for_range( $start_date, $end_date, 'session' );
		$cache_seed = class_exists( 'Opti_Behavior_Stats_Context' )
			? Opti_Behavior_Stats_Context::cache_key( $context )
			: md5( $start_date . '|' . $end_date );
		$cache_key  = 'opti_behavior_browsers_' . md5( 'dim-v3|' . $cache_seed . '|' . ( $this->is_spam_excluded() ? '1' : '0' ) );
		$cached     = $this->get_dashboard_widget_cached_data( $cache_key, $start_date, $end_date, 'browsers' );
		if ( false !== $cached ) {
			return $cached;
		}

		$spam_clause = $this->get_spam_exclusion_clause( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dashboard dimension aggregation.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN v.browser IS NULL OR TRIM(v.browser) = '' THEN 'Unknown'
						WHEN LOWER(TRIM(v.browser)) IN ('unknown','undefined','other') THEN 'Unknown'
						ELSE TRIM(v.browser)
					END AS browser_name,
					COUNT(DISTINCT s.id) AS count
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
				GROUP BY browser_name
				ORDER BY count DESC",
				$start_date,
				$end_date
			)
		);

		$buckets = array();
		foreach ( (array) $results as $row ) {
			$key = isset( $row->browser_name ) ? trim( (string) $row->browser_name ) : 'Unknown';
			if ( '' === $key ) {
				$key = 'Unknown';
			}
			$buckets[ $key ] = ( isset( $buckets[ $key ] ) ? absint( $buckets[ $key ] ) : 0 ) + absint( $row->count );
		}

		$baseline = $this->get_dashboard_dimension_session_baseline( $context );
		$buckets  = $this->add_dashboard_unclassified_bucket( $buckets, $baseline, 'Unclassified' );

		// Unified Retention Protocol: merge aggregated browser history for
		// days older than the raw retention window.
		$buckets = $this->merge_pre_raw_dimension_counts( 'browser', $start_date, $end_date, $buckets );

		$unknown      = isset( $buckets['Unknown'] ) ? absint( $buckets['Unknown'] ) : 0;
		$unclassified = isset( $buckets['Unclassified'] ) ? absint( $buckets['Unclassified'] ) : 0;
		unset( $buckets['Unknown'], $buckets['Unclassified'] );
		$buckets = $this->limit_dashboard_bucket_map( $buckets, 49, 'Other' );

		$data = array();
		foreach ( $buckets as $label => $count ) {
			$display_label = 'Other' === $label ? __( 'Other', 'opti-behavior' ) : $label;
			$data[] = array(
				'browser' => $display_label,
				'count'   => absint( $count ),
			);
		}

		if ( $unknown > 0 ) {
			$data[] = array(
				'browser' => __( 'Unknown', 'opti-behavior' ),
				'count'   => $unknown,
			);
		}
		if ( $unclassified > 0 ) {
			$data[] = array(
				'browser' => __( 'Unclassified', 'opti-behavior' ),
				'count'   => $unclassified,
			);
		}

		// Never freeze an empty result (Bug #4): that's the state most likely to
		// change on the very next session insert.
		return $this->set_dashboard_widget_cached_data( $cache_key, $data, empty( $data ), $start_date, $end_date, 'browsers' );
	}

	/**
	 * Filtered variant of get_browsers_data(). See
	 * get_screen_resolution_data_filtered() for the shared rationale (no
	 * cache, no unfiltered-baseline reconciliation, filters WHERE appended).
	 *
	 * @since 1.0.4
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param array  $filters    Sanitized advanced filters.
	 * @return array
	 */
	private function get_browsers_data_filtered( $start_date, $end_date, array $filters ) {
		global $wpdb;

		$spam_clause = $this->get_spam_exclusion_clause( 's' );
		$filter_sql  = $this->build_advanced_filters_sql( $filters );
		$params      = array_merge( array( $start_date, $end_date ), $filter_sql['params'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dashboard dimension aggregation.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN v.browser IS NULL OR TRIM(v.browser) = '' THEN 'Unknown'
						WHEN LOWER(TRIM(v.browser)) IN ('unknown','undefined','other') THEN 'Unknown'
						ELSE TRIM(v.browser)
					END AS browser_name,
					COUNT(DISTINCT s.id) AS count
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
				WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
				GROUP BY browser_name
				ORDER BY count DESC",
				$params
			)
		);

		$buckets = array();
		foreach ( (array) $results as $row ) {
			$key = isset( $row->browser_name ) ? trim( (string) $row->browser_name ) : 'Unknown';
			if ( '' === $key ) {
				$key = 'Unknown';
			}
			$buckets[ $key ] = ( isset( $buckets[ $key ] ) ? absint( $buckets[ $key ] ) : 0 ) + absint( $row->count );
		}

		$unknown = isset( $buckets['Unknown'] ) ? absint( $buckets['Unknown'] ) : 0;
		unset( $buckets['Unknown'] );
		$buckets = $this->limit_dashboard_bucket_map( $buckets, 49, 'Other' );

		$data = array();
		foreach ( $buckets as $label => $count ) {
			$display_label = 'Other' === $label ? __( 'Other', 'opti-behavior' ) : $label;
			$data[] = array(
				'browser' => $display_label,
				'count'   => absint( $count ),
			);
		}

		if ( $unknown > 0 ) {
			$data[] = array(
				'browser' => __( 'Unknown', 'opti-behavior' ),
				'count'   => $unknown,
			);
		}

		return $data;
	}

	/**
	 * Extract main domain from a hostname (removes subdomains)
	 * Examples: api.pfbaza.website -> pfbaza.website, www.example.com -> example.com
	 *
	 * @param string $hostname The hostname to extract from.
	 * @return string The main domain.
	 */
	private function extract_main_domain( $hostname ) {
		if ( empty( $hostname ) ) {
			return '';
		}

		// Remove 'www.' prefix if present
		$hostname = preg_replace( '/^www\./i', '', $hostname );

		// Split by dots
		$parts = explode( '.', $hostname );
		$count = count( $parts );

		// If only 2 parts (e.g., example.com), return as-is
		if ( $count <= 2 ) {
			return $hostname;
		}

		// Known two-part TLDs (e.g., .co.uk, .com.au)
		$two_part_tlds = array( 'co.uk', 'com.au', 'co.za', 'co.nz', 'com.br', 'co.in', 'co.jp' );
		$last_two = $parts[ $count - 2 ] . '.' . $parts[ $count - 1 ];

		if ( in_array( $last_two, $two_part_tlds, true ) ) {
			// Return domain.co.uk format (3 parts)
			if ( $count >= 3 ) {
				return $parts[ $count - 3 ] . '.' . $parts[ $count - 2 ] . '.' . $parts[ $count - 1 ];
			}
			return $hostname;
		}

		// Standard TLD: return last 2 parts (domain.tld)
		return $parts[ $count - 2 ] . '.' . $parts[ $count - 1 ];
	}

	/**
	 * Get top referrers data
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array
	 */
	private function get_referrers_data( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters ) ) {
			return $this->get_referrers_data_filtered( $start_date, $end_date, $filters );
		}

		$context    = $this->get_dashboard_stats_context_for_range( $start_date, $end_date, 'session' );
		$cache_seed = class_exists( 'Opti_Behavior_Stats_Context' )
			? Opti_Behavior_Stats_Context::cache_key( $context )
			: md5( $start_date . '|' . $end_date );
		$cache_key  = 'opti_behavior_referrers_' . md5( 'dim-v3|' . $cache_seed . '|' . ( $this->is_spam_excluded() ? '1' : '0' ) );
		$cached     = $this->get_dashboard_widget_cached_data( $cache_key, $start_date, $end_date, 'referrers' );
		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Fallback for HTTP_HOST, sanitized by wp_parse_url
		$site_host = function_exists('home_url') ? wp_parse_url( home_url(), PHP_URL_HOST ) : ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' );

		$spam_clause = $this->get_spam_exclusion_clause( 's' );

		// Pull sessions in the range. We fetch referrer, entry_page (for UTM/click ids), and basic counts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.referrer AS raw_ref, s.utm_source AS raw_utm, s.utm_medium AS raw_med, s.entry_page AS entry_url, COUNT(DISTINCT s.id) AS cnt
			 FROM " . $wpdb->prefix . "optibehavior_sessions s
			 WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
			 GROUP BY s.referrer, s.utm_source, s.utm_medium, s.entry_page
			 ORDER BY cnt DESC
			 LIMIT 5000",
			$start_date, $end_date
		) );

		$agg = array();

		// Strip www. prefix from site host for robust comparison (handles www vs non-www mismatch)
		$site_host_clean = $site_host ? preg_replace( '/^www\./i', '', $site_host ) : '';

		// Known classifiers (shared with the Traffic Channel filter).
		list( $search_map, $social_map ) = $this->get_traffic_channel_domain_maps();
		$paid_click_ids = array('gclid','msclkid','fbclid','twclid','ttclid','yclid','gbraid','wbraid','mc_eid');

		foreach ( (array)$rows as $r ) {
			$ref = is_string($r->raw_ref) ? trim($r->raw_ref) : '';
			$utm = is_string($r->raw_utm) ? trim($r->raw_utm) : '';
			$med = is_string($r->raw_med) ? trim($r->raw_med) : '';
			$entry = is_string($r->entry_url) ? trim($r->entry_url) : '';
			$host = '';
			if ($ref !== '') { $host = wp_parse_url($ref, PHP_URL_HOST) ?: ''; }
			// Same-site referrer => direct (strip www. for robust comparison)
			$host_clean = $host ? preg_replace( '/^www\./i', '', $host ) : '';
			if ($host_clean && $site_host_clean && strcasecmp($host_clean, $site_host_clean) === 0) { $host = ''; }

			// Inspect entry_page query for click IDs and UTM
			$qs = $entry ? wp_parse_url($entry, PHP_URL_QUERY) : '';
			$params = array();
			if ($qs) { parse_str($qs, $params); }
			$has_click_id = false;
			foreach ($paid_click_ids as $cid) { if (!empty($params[$cid])) { $has_click_id = true; break; } }

			$label = 'Direct / None';

			if ($host !== '') {
				// Classify by known search/social domains first
				$name = $this->match_referrer_domain_map( $host, $search_map );
				if ( '' === $name ) { $name = $this->match_referrer_domain_map( $host, $social_map ); }
				$label = ( '' !== $name ) ? $name : $host;
			} else {
				// No referrer; check click IDs and UTM
				if ($has_click_id) {
					if (!empty($params['gclid']))      { $label = 'Google Ads'; }
					elseif (!empty($params['msclkid'])) { $label = 'Microsoft Ads'; }
					elseif (!empty($params['fbclid']))  { $label = 'Facebook Ads'; }
					elseif (!empty($params['twclid']))  { $label = 'Twitter Ads'; }
					elseif (!empty($params['ttclid']))  { $label = 'TikTok Ads'; }
					else { $label = 'Paid Ads'; }
				} elseif ($utm !== '' || $med !== '') {
					// Prefer utm_source; fall back to utm_medium to categorize
					$src = $utm !== '' ? $utm : $med;
					// normalize some common values
					$src_l = strtolower($src);
					if (in_array($src_l, array('cpc','ppc','paid','paid_search'))) { $label = 'Paid Search'; }
					elseif (in_array($src_l, array('email','newsletter'))) { $label = 'Email'; }
					elseif (in_array($src_l, array('social','social_media'))) { $label = 'Social'; }
					else { $label = $src; }
				} else {
					$label = 'Direct / None';
				}
			}

			$agg[$label] = ($agg[$label] ?? 0) + intval($r->cnt);
		}

		$baseline = $this->get_dashboard_dimension_session_baseline( $context );
		$agg      = $this->add_dashboard_unclassified_bucket( $agg, $baseline, 'Unclassified' );

		// Unified Retention Protocol: merge aggregated referrer history (domain
		// or "(direct)") for days older than the raw retention window.
		$pre_raw_segment = $this->get_pre_raw_aggregate_segment( $start_date, $end_date );
		if ( null !== $pre_raw_segment ) {
			$agg_counts = Opti_Behavior_Dimension_Aggregates::get_dimension_counts( 'referrer', $pre_raw_segment['start'], $pre_raw_segment['end'], $this->is_spam_excluded() );
			foreach ( $agg_counts as $agg_value => $agg_row ) {
				$agg_sessions = absint( $agg_row['sessions'] ?? 0 );
				if ( $agg_sessions < 1 ) {
					continue;
				}
				$agg_host = trim( (string) $agg_value );
				if ( '' === $agg_host || '(direct)' === $agg_host ) {
					$agg_label = 'Direct / None';
				} else {
					$agg_host_clean = preg_replace( '/^www\./i', '', $agg_host );
					if ( $site_host_clean && strcasecmp( $agg_host_clean, $site_host_clean ) === 0 ) {
						$agg_label = 'Direct / None';
					} else {
						$agg_name  = $this->match_referrer_domain_map( $agg_host, $search_map );
						if ( '' === $agg_name ) {
							$agg_name = $this->match_referrer_domain_map( $agg_host, $social_map );
						}
						$agg_label = ( '' !== $agg_name ) ? $agg_name : $agg_host;
					}
				}
				$agg[ $agg_label ] = ( $agg[ $agg_label ] ?? 0 ) + $agg_sessions;
			}
		}

		$unclassified = isset( $agg['Unclassified'] ) ? absint( $agg['Unclassified'] ) : 0;
		unset( $agg['Unclassified'] );
		$agg = $this->limit_dashboard_bucket_map( $agg, 49, 'Other' );
		if ( $unclassified > 0 ) {
			$agg['Unclassified'] = $unclassified;
		}

		$out = array();
		foreach ( array_keys($agg) as $k ) {

			// Extract domain for favicon
			$domain = '';
			$favicon_url = '';

			// Try to extract domain from referrer label
			if ( $k !== 'Direct / None' && $k !== 'Paid Ads' && $k !== 'Paid Search' && $k !== 'Email' && $k !== 'Social' && $k !== 'Unclassified' && $k !== 'Other' ) {
				// Check if it's a known service (e.g., "Google", "Facebook")
				$known_domains = $this->get_referrer_known_domains();

				if ( isset( $known_domains[ $k ] ) ) {
					$domain = $known_domains[ $k ];
				} else {
					// It's a domain or subdomain (e.g., "api.pfbaza.website")
					// Extract main domain for favicon (api.pfbaza.website -> pfbaza.website)
					$domain = $this->extract_main_domain( $k );
				}

				// Build favicon URL using the main domain
				if ( $domain ) {
					$favicon_url = 'https://' . $domain . '/favicon.ico';
				}
			}

			$display_label = $k;
			if ( $k === 'Direct / None' ) {
				$display_label = __( 'Direct / None', 'opti-behavior' );
			} elseif ( $k === 'Other' ) {
				$display_label = __( 'Other', 'opti-behavior' );
			} elseif ( $k === 'Unclassified' ) {
				$display_label = __( 'Unclassified', 'opti-behavior' );
			}
			$out[] = array(
				'referrer' => $display_label,
				'count' => intval($agg[$k]),
				'domain' => $domain,
				'favicon_url' => $favicon_url
			);
		}

		// Never freeze an empty result (Bug #4): that's the state most likely to
		// change on the very next session insert.
		return $this->set_dashboard_widget_cached_data( $cache_key, $out, empty( $out ), $start_date, $end_date, 'referrers' );
	}

	/**
	 * Filtered variant of get_referrers_data(). Same classification rules,
	 * with the advanced-filters WHERE fragment appended (requiring a
	 * visitors join the unfiltered query doesn't otherwise need) and no
	 * transient cache / unfiltered-baseline reconciliation.
	 *
	 * @since 1.0.4
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param array  $filters    Sanitized advanced filters.
	 * @return array
	 */
	private function get_referrers_data_filtered( $start_date, $end_date, array $filters ) {
		global $wpdb;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Fallback for HTTP_HOST, sanitized by wp_parse_url
		$site_host = function_exists( 'home_url' ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' );

		$spam_clause = $this->get_spam_exclusion_clause( 's' );
		$filter_sql  = $this->build_advanced_filters_sql( $filters );
		$params      = array_merge( array( $start_date, $end_date ), $filter_sql['params'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.referrer AS raw_ref, s.utm_source AS raw_utm, s.utm_medium AS raw_med, s.entry_page AS entry_url, COUNT(DISTINCT s.id) AS cnt
			 FROM " . $wpdb->prefix . "optibehavior_sessions s
			 LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
			 WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
			 GROUP BY s.referrer, s.utm_source, s.utm_medium, s.entry_page
			 ORDER BY cnt DESC
			 LIMIT 5000",
			$params
		) );

		$agg = array();

		$site_host_clean = $site_host ? preg_replace( '/^www\./i', '', $site_host ) : '';

		list( $search_map, $social_map ) = $this->get_traffic_channel_domain_maps();
		$paid_click_ids = array( 'gclid', 'msclkid', 'fbclid', 'twclid', 'ttclid', 'yclid', 'gbraid', 'wbraid', 'mc_eid' );

		foreach ( (array) $rows as $r ) {
			$ref   = is_string( $r->raw_ref ) ? trim( $r->raw_ref ) : '';
			$utm   = is_string( $r->raw_utm ) ? trim( $r->raw_utm ) : '';
			$med   = is_string( $r->raw_med ) ? trim( $r->raw_med ) : '';
			$entry = is_string( $r->entry_url ) ? trim( $r->entry_url ) : '';
			$host  = '';
			if ( $ref !== '' ) { $host = wp_parse_url( $ref, PHP_URL_HOST ) ?: ''; }
			$host_clean = $host ? preg_replace( '/^www\./i', '', $host ) : '';
			if ( $host_clean && $site_host_clean && strcasecmp( $host_clean, $site_host_clean ) === 0 ) { $host = ''; }

			$qs     = $entry ? wp_parse_url( $entry, PHP_URL_QUERY ) : '';
			$params = array();
			if ( $qs ) { parse_str( $qs, $params ); }
			$has_click_id = false;
			foreach ( $paid_click_ids as $cid ) { if ( ! empty( $params[ $cid ] ) ) { $has_click_id = true; break; } }

			$label = 'Direct / None';

			if ( $host !== '' ) {
				$name = $this->match_referrer_domain_map( $host, $search_map );
				if ( '' === $name ) { $name = $this->match_referrer_domain_map( $host, $social_map ); }
				$label = ( '' !== $name ) ? $name : $host;
			} else {
				if ( $has_click_id ) {
					if ( ! empty( $params['gclid'] ) )      { $label = 'Google Ads'; }
					elseif ( ! empty( $params['msclkid'] ) ) { $label = 'Microsoft Ads'; }
					elseif ( ! empty( $params['fbclid'] ) )  { $label = 'Facebook Ads'; }
					elseif ( ! empty( $params['twclid'] ) )  { $label = 'Twitter Ads'; }
					elseif ( ! empty( $params['ttclid'] ) )  { $label = 'TikTok Ads'; }
					else { $label = 'Paid Ads'; }
				} elseif ( $utm !== '' || $med !== '' ) {
					$src   = $utm !== '' ? $utm : $med;
					$src_l = strtolower( $src );
					if ( in_array( $src_l, array( 'cpc', 'ppc', 'paid', 'paid_search' ) ) ) { $label = 'Paid Search'; }
					elseif ( in_array( $src_l, array( 'email', 'newsletter' ) ) ) { $label = 'Email'; }
					elseif ( in_array( $src_l, array( 'social', 'social_media' ) ) ) { $label = 'Social'; }
					else { $label = $src; }
				} else {
					$label = 'Direct / None';
				}
			}

			$agg[ $label ] = ( $agg[ $label ] ?? 0 ) + intval( $r->cnt );
		}

		$agg = $this->limit_dashboard_bucket_map( $agg, 49, 'Other' );

		$out = array();
		foreach ( array_keys( $agg ) as $k ) {
			$domain      = '';
			$favicon_url = '';

			if ( $k !== 'Direct / None' && $k !== 'Paid Ads' && $k !== 'Paid Search' && $k !== 'Email' && $k !== 'Social' && $k !== 'Other' ) {
				$known_domains = $this->get_referrer_known_domains();

				if ( isset( $known_domains[ $k ] ) ) {
					$domain = $known_domains[ $k ];
				} else {
					$domain = $this->extract_main_domain( $k );
				}

				if ( $domain ) {
					$favicon_url = 'https://' . $domain . '/favicon.ico';
				}
			}

			$display_label = $k;
			if ( $k === 'Direct / None' ) {
				$display_label = __( 'Direct / None', 'opti-behavior' );
			} elseif ( $k === 'Other' ) {
				$display_label = __( 'Other', 'opti-behavior' );
			}
			$out[] = array(
				'referrer'    => $display_label,
				'count'       => intval( $agg[ $k ] ),
				'domain'      => $domain,
				'favicon_url' => $favicon_url,
			);
		}

		return $out;
	}

	/**
	 * Classify a session's referrer/UTM values into one of the six FREE
	 * dashboard "Traffic Channel" buckets: Direct, Organic Search, Paid Ads,
	 * Social Media, Email, Referral.
	 *
	 * Extracted/simplified from the ad-hoc classification logic in
	 * get_referrers_data() (search/social domain maps + UTM keyword rules).
	 * Unlike get_referrers_data(), this helper does not inspect the entry
	 * page's query string for paid click IDs (gclid/fbclid/...) since it is
	 * not passed the entry page URL; click-id traffic here falls back to the
	 * UTM-based rules below.
	 *
	 * @since 1.0.4
	 * @param string|null $referrer   Raw session referrer URL.
	 * @param string|null $utm_source Raw session utm_source value.
	 * @param string|null $utm_medium Raw session utm_medium value.
	 * @return string One of: 'Direct', 'Organic Search', 'Paid Ads', 'Social Media', 'Email', 'Referral'.
	 */
	private function classify_traffic_channel( $referrer, $utm_source, $utm_medium ) {
		$referrer   = is_string( $referrer ) ? trim( $referrer ) : '';
		$utm_source = is_string( $utm_source ) ? trim( $utm_source ) : '';
		$utm_medium = is_string( $utm_medium ) ? trim( $utm_medium ) : '';

		list( $search_map, $social_map ) = $this->get_traffic_channel_domain_maps();

		$host = '';
		if ( '' !== $referrer ) {
			$host = wp_parse_url( $referrer, PHP_URL_HOST ) ?: '';
		}

		// Same-site referrer counts as no (external) referrer.
		if ( '' !== $host ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Fallback for HTTP_HOST, sanitized by wp_parse_url.
			$site_host       = function_exists( 'home_url' ) ? wp_parse_url( home_url(), PHP_URL_HOST ) : ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' );
			$site_host_clean = $site_host ? preg_replace( '/^www\./i', '', $site_host ) : '';
			$host_clean      = preg_replace( '/^www\./i', '', $host );
			if ( $host_clean && $site_host_clean && 0 === strcasecmp( $host_clean, $site_host_clean ) ) {
				$host = '';
			}
		}

		if ( '' !== $host ) {
			$host_clean = strtolower( preg_replace( '/^www\./i', '', $host ) );
			$matches    = function ( $needle ) use ( $host, $host_clean ) {
				if ( 0 === strpos( $needle, '=' ) ) {
					// Exact-host needle: matches the bare host only (www. already stripped).
					return $host_clean === strtolower( substr( $needle, 1 ) );
				}
				return false !== stripos( $host, $needle );
			};
			foreach ( array_keys( $search_map ) as $needle ) {
				if ( $matches( $needle ) ) {
					return 'Organic Search';
				}
			}
			foreach ( array_keys( $social_map ) as $needle ) {
				if ( $matches( $needle ) ) {
					return 'Social Media';
				}
			}
			return 'Referral';
		}

		// No external referrer host — classify by UTM signals.
		$medium_l = strtolower( $utm_medium );
		$source_l = strtolower( $utm_source );

		if ( in_array( $medium_l, array( 'cpc', 'ppc', 'paid', 'paid_search' ), true )
			|| in_array( $source_l, array( 'cpc', 'ppc', 'paid', 'paid_search' ), true ) ) {
			return 'Paid Ads';
		}
		if ( in_array( $medium_l, array( 'email', 'newsletter' ), true )
			|| in_array( $source_l, array( 'email', 'newsletter' ), true ) ) {
			return 'Email';
		}
		if ( in_array( $medium_l, array( 'social', 'social_media' ), true )
			|| in_array( $source_l, array( 'social', 'social_media' ), true ) ) {
			return 'Social Media';
		}
		if ( 'organic' === $medium_l || 'organic' === $source_l ) {
			return 'Organic Search';
		}
		if ( '' !== $utm_source || '' !== $utm_medium ) {
			return 'Referral';
		}

		return 'Direct';
	}

	/**
	 * Shared known-domain maps used by both classify_traffic_channel() and
	 * the SQL-level traffic_channel filter expression built in
	 * build_advanced_filters_sql(), so the two stay in sync.
	 *
	 * @since 1.0.4
	 * @return array{0: array<string,string>, 1: array<string,string>} [ $search_map, $social_map ]
	 */
	private function get_traffic_channel_domain_maps() {
		/*
		 * Needle syntax:
		 *  - plain string  => substring match against the referrer/host
		 *    (safe only for needles too distinctive to appear inside other
		 *    domains, e.g. "duckduckgo.com", "google.").
		 *  - "=" prefix    => exact-host match ("=x.com" matches x.com /
		 *    www.x.com only). Required for short domains whose raw substring
		 *    would false-positive inside unrelated hosts ("x.com" is inside
		 *    gmx.com/fedex.com, "t.co" is inside every *t.com domain).
		 *
		 * 2026 landscape: AI assistants (ChatGPT, Perplexity, Claude,
		 * Copilot) are classified as Organic Search — they answer queries
		 * and refer clicks the same way search engines do. Gemini arrives
		 * via gemini.google.com (covered by "google.").
		 */
		$search_map = array(
			'google.' => 'Google', 'bing.com' => 'Bing', 'duckduckgo.com' => 'DuckDuckGo',
			'yahoo.' => 'Yahoo', 'yandex.' => 'Yandex', 'baidu.' => 'Baidu', 'naver.com' => 'Naver',
			'ecosia.org' => 'Ecosia', 'startpage.com' => 'Startpage', 'qwant.com' => 'Qwant',
			'search.brave.com' => 'Brave Search', 'kagi.com' => 'Kagi',
			'seznam.cz' => 'Seznam', 'sogou.com' => 'Sogou',
			// AI assistants / answer engines.
			'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT',
			'perplexity.ai' => 'Perplexity', 'claude.ai' => 'Claude',
			'copilot.microsoft.com' => 'Copilot',
		);
		$social_map = array(
			'facebook.com' => 'Facebook', '=fb.com' => 'Facebook', '=fb.me' => 'Facebook',
			'instagram.com' => 'Instagram',
			'twitter.com' => 'Twitter/X', '=x.com' => 'Twitter/X', '=t.co' => 'Twitter/X',
			'linkedin.com' => 'LinkedIn', '=lnkd.in' => 'LinkedIn',
			'pinterest.' => 'Pinterest', '=pin.it' => 'Pinterest',
			'reddit.com' => 'Reddit', '=redd.it' => 'Reddit',
			'tiktok.com' => 'TikTok', 'snapchat.com' => 'Snapchat',
			'youtube.com' => 'YouTube', '=youtu.be' => 'YouTube',
			'threads.net' => 'Threads', 'threads.com' => 'Threads',
			'bsky.app' => 'Bluesky', 'mastodon.' => 'Mastodon',
			'whatsapp.com' => 'WhatsApp', '=wa.me' => 'WhatsApp',
			'telegram.org' => 'Telegram', '=t.me' => 'Telegram',
			'discord.com' => 'Discord', 'discord.gg' => 'Discord',
			'twitch.tv' => 'Twitch', '=vk.com' => 'VK', 'weibo.com' => 'Weibo',
			'quora.com' => 'Quora', 'nextdoor.com' => 'Nextdoor',
		);

		return array( $search_map, $social_map );
	}

	/**
	 * Match a referrer host against a domain map (needle syntax documented
	 * in get_traffic_channel_domain_maps()).
	 *
	 * @since 1.0.4
	 * @param string $host Referrer host (e.g. "www.google.com").
	 * @param array  $map  needle => display-name map.
	 * @return string Display name of the matched service, '' when none match.
	 */
	private function match_referrer_domain_map( $host, array $map ) {
		$host       = (string) $host;
		$host_clean = strtolower( preg_replace( '/^www\./i', '', $host ) );
		foreach ( $map as $needle => $name ) {
			if ( 0 === strpos( $needle, '=' ) ) {
				if ( $host_clean === strtolower( substr( $needle, 1 ) ) ) {
					return $name;
				}
				continue;
			}
			if ( false !== stripos( $host, $needle ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Display-name => canonical domain map used for Referrers-widget
	 * favicons. Kept in sync with get_traffic_channel_domain_maps().
	 *
	 * @since 1.0.4
	 * @return array<string,string>
	 */
	private function get_referrer_known_domains() {
		return array(
			'Google' => 'google.com', 'Bing' => 'bing.com', 'DuckDuckGo' => 'duckduckgo.com',
			'Yahoo' => 'yahoo.com', 'Yandex' => 'yandex.com', 'Baidu' => 'baidu.com', 'Naver' => 'naver.com',
			'Ecosia' => 'ecosia.org', 'Startpage' => 'startpage.com', 'Qwant' => 'qwant.com',
			'Brave Search' => 'brave.com', 'Kagi' => 'kagi.com', 'Seznam' => 'seznam.cz', 'Sogou' => 'sogou.com',
			'ChatGPT' => 'chatgpt.com', 'Perplexity' => 'perplexity.ai', 'Claude' => 'claude.ai',
			'Copilot' => 'microsoft.com',
			'Facebook' => 'facebook.com', 'Instagram' => 'instagram.com', 'Twitter/X' => 'x.com',
			'LinkedIn' => 'linkedin.com', 'Pinterest' => 'pinterest.com', 'Reddit' => 'reddit.com',
			'TikTok' => 'tiktok.com', 'Snapchat' => 'snapchat.com', 'YouTube' => 'youtube.com',
			'Threads' => 'threads.net', 'Bluesky' => 'bsky.app', 'Mastodon' => 'mastodon.social',
			'WhatsApp' => 'whatsapp.com', 'Telegram' => 'telegram.org', 'Discord' => 'discord.com',
			'Twitch' => 'twitch.tv', 'VK' => 'vk.com', 'Weibo' => 'weibo.com',
			'Quora' => 'quora.com', 'Nextdoor' => 'nextdoor.com',
			'Google Ads' => 'google.com', 'Microsoft Ads' => 'bing.com', 'Facebook Ads' => 'facebook.com',
			'Twitter Ads' => 'x.com', 'TikTok Ads' => 'tiktok.com',
		);
	}

	/**
	 * Build the SQL CASE expression that derives the same six Traffic
	 * Channel buckets as classify_traffic_channel(), but in pure SQL so it
	 * can be evaluated per-row inside a WHERE clause without pulling every
	 * session into PHP first.
	 *
	 * Mirrors classify_traffic_channel()'s rule order (known search/social
	 * domain substrings on s.referrer, then UTM keyword rules, then Direct)
	 * using LIKE matching against the raw referrer column — it does not
	 * strip the site's own host as "same-site" the way the PHP helper does,
	 * since that requires a runtime home_url() comparison unsuited to a
	 * cached SQL fragment; same-site referrers fall into Referral instead of
	 * Direct.
	 *
	 * @since 1.0.4
	 * @return string SQL CASE expression (no trailing alias).
	 */
	private function build_traffic_channel_case_sql() {
		list( $search_map, $social_map ) = $this->get_traffic_channel_domain_maps();

		/*
		 * This fragment is always appended to a larger query string that gets
		 * passed through $wpdb->prepare() by the caller (see
		 * build_advanced_filters_sql() docblock). $wpdb->prepare() treats a
		 * lone "%" immediately followed by s/d/f/F/i as a printf-style
		 * placeholder (e.g. the "%s" inside "%startpage.com%" or "%d" inside
		 * "%duckduckgo.com%"), which desyncs the placeholder/argument count
		 * and corrupts the whole query. Doubling the percent signs here
		 * ("%%needle%%") makes prepare() treat them as escaped literal "%"
		 * characters, so the executed SQL still has single "%" wildcards.
		 */
		$needle_likes = function ( $needle ) {
			if ( 0 === strpos( $needle, '=' ) ) {
				/*
				 * Exact-host needle: anchor on "://host" so the LIKE cannot
				 * match the needle inside a longer domain (mirrors the exact
				 * host comparison in classify_traffic_channel()).
				 */
				$host = esc_sql( substr( $needle, 1 ) );
				return "(s.referrer LIKE '%%://" . $host . "/%%'"
					. " OR s.referrer LIKE '%%://www." . $host . "/%%'"
					. " OR s.referrer LIKE '%%://" . $host . "'"
					. " OR s.referrer LIKE '%%://www." . $host . "')";
			}
			return "s.referrer LIKE '%%" . esc_sql( $needle ) . "%%'";
		};

		$search_likes = array();
		foreach ( array_keys( $search_map ) as $needle ) {
			$search_likes[] = $needle_likes( $needle );
		}
		$social_likes = array();
		foreach ( array_keys( $social_map ) as $needle ) {
			$social_likes[] = $needle_likes( $needle );
		}

		return "CASE
				WHEN s.referrer IS NOT NULL AND s.referrer <> '' AND (" . implode( ' OR ', $search_likes ) . ") THEN 'Organic Search'
				WHEN s.referrer IS NOT NULL AND s.referrer <> '' AND (" . implode( ' OR ', $social_likes ) . ") THEN 'Social Media'
				WHEN s.referrer IS NOT NULL AND s.referrer <> '' THEN 'Referral'
				WHEN LOWER(TRIM(s.utm_medium)) IN ('cpc','ppc','paid','paid_search') OR LOWER(TRIM(s.utm_source)) IN ('cpc','ppc','paid','paid_search') THEN 'Paid Ads'
				WHEN LOWER(TRIM(s.utm_medium)) IN ('email','newsletter') OR LOWER(TRIM(s.utm_source)) IN ('email','newsletter') THEN 'Email'
				WHEN LOWER(TRIM(s.utm_medium)) IN ('social','social_media') OR LOWER(TRIM(s.utm_source)) IN ('social','social_media') THEN 'Social Media'
				WHEN LOWER(TRIM(s.utm_medium)) = 'organic' OR LOWER(TRIM(s.utm_source)) = 'organic' THEN 'Organic Search'
				WHEN (s.utm_source IS NOT NULL AND s.utm_source <> '') OR (s.utm_medium IS NOT NULL AND s.utm_medium <> '') THEN 'Referral'
				ELSE 'Direct'
			END";
	}

	/**
	 * Allow-listed FREE dashboard advanced-filter fields.
	 *
	 * @since 1.0.4
	 * @return string[]
	 */
	private function get_advanced_filters_allowed_fields() {
		return array(
			'browser',
			'country',
			'device_type',
			'os',
			'visitor_type',
			'duration_min',
			'duration_max',
			'page_count_min',
			'page_count_max',
			'entry_page',
			'exit_page',
			'referrer',
			'traffic_channel',
			'utm_campaign',
			'utm_source',
			'utm_medium',
		);
	}

	/**
	 * Centralized builder that turns a sanitized advanced-filters array into
	 * a SQL WHERE fragment + matching wpdb::prepare() params, so every
	 * aggregate query method reuses one source of truth instead of
	 * duplicating filter SQL ~13 times.
	 *
	 * Unknown/non-allow-listed keys are silently ignored (defense in depth —
	 * callers are also expected to allow-list before this point). Empty
	 * string/null values are treated as "not set" and skipped.
	 *
	 * Assumes the standard FREE dashboard query aliases: `s` for
	 * optibehavior_sessions and `v` for optibehavior_visitors (both already
	 * joined in every aggregate query this will be wired into in Task 3).
	 *
	 * @since 1.0.4
	 * @param array $filters Sanitized filters, allow-listed keys only.
	 * @return array{where: string, params: array} WHERE fragment (leading " AND (...)" or empty string) + params.
	 */
	private function build_advanced_filters_sql( array $filters ) {
		global $wpdb;

		if ( empty( $filters ) ) {
			return array(
				'where'  => '',
				'params' => array(),
			);
		}

		$allowed_fields   = $this->get_advanced_filters_allowed_fields();
		$duration_expr    = "(CASE WHEN s.duration > 0 THEN s.duration ELSE TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time)) END)";
		$page_count_expr  = '(CASE WHEN COALESCE(s.page_views, 0) > 0 THEN COALESCE(s.page_views, 0) ELSE 1 END)';
		$conditions       = array();
		$params           = array();

		// Emits `expr = %s` for a scalar or `expr IN (%s, ...)` for an array so
		// multi-select filters (several browsers/countries/...) use OR semantics
		// within the field while distinct fields still AND together.
		$add_eq_or_in = function ( $expr, $value, $transform = null ) use ( &$conditions, &$params ) {
			$values = is_array( $value ) ? array_values( $value ) : array( $value );
			$values = array_map(
				function ( $v ) use ( $transform ) {
					$v = sanitize_text_field( $v );
					return $transform ? call_user_func( $transform, $v ) : $v;
				},
				$values
			);
			$values = array_values( array_unique( array_filter( $values, 'strlen' ) ) );
			if ( empty( $values ) ) {
				return;
			}
			if ( 1 === count( $values ) ) {
				$conditions[] = $expr . ' = %s';
				$params[]     = $values[0];
				return;
			}
			$conditions[] = $expr . ' IN (' . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ')';
			foreach ( $values as $v ) {
				$params[] = $v;
			}
		};

		foreach ( $filters as $field => $value ) {
			if ( ! is_string( $field ) || ! in_array( $field, $allowed_fields, true ) ) {
				continue;
			}
			if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) || ( is_array( $value ) && empty( $value ) ) ) {
				continue;
			}

			switch ( $field ) {
				case 'browser':
					$add_eq_or_in( 'v.browser', $value );
					break;

				case 'country':
					$add_eq_or_in( 'UPPER(TRIM(v.country))', $value, 'strtoupper' );
					break;

				case 'device_type':
					$add_eq_or_in( 'v.device_type', $value );
					break;

				case 'os':
					$add_eq_or_in( 'v.os', $value );
					break;

				case 'visitor_type':
					$visitor_type = strtolower( sanitize_text_field( $value ) );
					if ( 'new' === $visitor_type ) {
						$conditions[] = 'COALESCE(v.visit_count, 1) <= 1';
					} elseif ( 'returning' === $visitor_type ) {
						$conditions[] = 'COALESCE(v.visit_count, 1) > 1';
					}
					break;

				case 'duration_min':
					$conditions[] = $duration_expr . ' >= %d';
					$params[]     = absint( $value );
					break;

				case 'duration_max':
					$conditions[] = $duration_expr . ' <= %d';
					$params[]     = absint( $value );
					break;

				case 'page_count_min':
					$conditions[] = $page_count_expr . ' >= %d';
					$params[]     = absint( $value );
					break;

				case 'page_count_max':
					$conditions[] = $page_count_expr . ' <= %d';
					$params[]     = absint( $value );
					break;

				case 'entry_page':
					$conditions[] = 's.entry_page LIKE %s';
					$params[]     = '%' . $wpdb->esc_like( sanitize_text_field( $value ) ) . '%';
					break;

				case 'exit_page':
					$conditions[] = 's.exit_page LIKE %s';
					$params[]     = '%' . $wpdb->esc_like( sanitize_text_field( $value ) ) . '%';
					break;

				case 'referrer':
					$conditions[] = 's.referrer LIKE %s';
					$params[]     = '%' . $wpdb->esc_like( sanitize_text_field( $value ) ) . '%';
					break;

				case 'traffic_channel':
					$conditions[] = '(' . $this->build_traffic_channel_case_sql() . ') = %s';
					$params[]     = sanitize_text_field( $value );
					break;

				case 'utm_campaign':
					$add_eq_or_in( 's.utm_campaign', $value );
					break;

				case 'utm_source':
					$add_eq_or_in( 's.utm_source', $value );
					break;

				case 'utm_medium':
					$add_eq_or_in( 's.utm_medium', $value );
					break;
			}
		}

		if ( empty( $conditions ) ) {
			return array(
				'where'  => '',
				'params' => array(),
			);
		}

		return array(
			'where'  => ' AND (' . implode( ' AND ', $conditions ) . ')',
			'params' => $params,
		);
	}

	/**
	 * Get operating systems data
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array
	 */
	private function get_operating_systems_data( $start_date, $end_date, array $filters = array() ) {
		global $wpdb;

		if ( ! empty( $filters ) ) {
			return $this->get_operating_systems_data_filtered( $start_date, $end_date, $filters );
		}

		$context    = $this->get_dashboard_stats_context_for_range( $start_date, $end_date, 'session' );
		$cache_seed = class_exists( 'Opti_Behavior_Stats_Context' )
			? Opti_Behavior_Stats_Context::cache_key( $context )
			: md5( $start_date . '|' . $end_date );
		$cache_key  = 'opti_behavior_os_' . md5( 'dim-v2|' . $cache_seed . '|' . ( $this->is_spam_excluded() ? '1' : '0' ) );
		$cached     = $this->get_dashboard_widget_cached_data( $cache_key, $start_date, $end_date, 'operating_systems' );
		if ( false !== $cached ) {
			return $cached;
		}

		$spam_clause = $this->get_spam_exclusion_clause( 's' );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- Static LIKE patterns with no user input
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dashboard dimension aggregation.
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				CASE
					WHEN v.os IS NULL OR TRIM(v.os) = '' THEN 'Unknown'
					WHEN LOWER(TRIM(v.os)) IN ('unknown','undefined','other','') THEN 'Unknown'
					WHEN LOWER(TRIM(v.os)) IN ('mac os','macos','os x','mac os x','macintosh') OR LOWER(TRIM(v.os)) LIKE 'mac%%' THEN 'macOS'
					WHEN LOWER(TRIM(v.os)) IN ('ios','iphone os','ipados') OR LOWER(TRIM(v.os)) LIKE 'ios%%' THEN 'iOS'
					WHEN LOWER(TRIM(v.os)) LIKE 'windows%%' OR LOWER(TRIM(v.os)) LIKE 'win %%' OR LOWER(TRIM(v.os)) LIKE 'win10%%' OR LOWER(TRIM(v.os)) LIKE 'win11%%' THEN 'Windows'
					WHEN LOWER(TRIM(v.os)) LIKE 'android%%' THEN 'Android'
					WHEN LOWER(TRIM(v.os)) IN ('ubuntu','debian','fedora','centos','red hat','suse','freebsd','openbsd','netbsd') OR LOWER(TRIM(v.os)) LIKE 'linux%%' THEN 'Linux'
					WHEN LOWER(TRIM(v.os)) IN ('chromeos','chrome os','cros') THEN 'Chrome OS'
					ELSE TRIM(v.os)
				END AS os,
				COUNT(DISTINCT s.id) AS count
			FROM " . $wpdb->prefix . "optibehavior_sessions s
			LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
			WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . "
			GROUP BY os
			ORDER BY count DESC
			",
			$start_date,
			$end_date
		) );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

		$map = array();
		foreach ( $results as $row ) {
			$label = isset( $row->os ) ? trim( (string) $row->os ) : '';
			if ( '' === $label ) {
				$label = 'Unknown';
			}
			if ( ! isset( $map[ $label ] ) ) { $map[ $label ] = 0; }
			$map[ $label ] += absint( $row->count );
		}

		$baseline = $this->get_dashboard_dimension_session_baseline( $context );
		$map      = $this->add_dashboard_unclassified_bucket( $map, $baseline, 'Unclassified' );

		// Unified Retention Protocol: merge aggregated OS history for days
		// older than the raw retention window.
		$map = $this->merge_pre_raw_dimension_counts( 'os', $start_date, $end_date, $map );

		$unknown      = isset( $map['Unknown'] ) ? absint( $map['Unknown'] ) : 0;
		$unclassified = isset( $map['Unclassified'] ) ? absint( $map['Unclassified'] ) : 0;
		unset( $map['Unknown'], $map['Unclassified'] );
		$map = $this->limit_dashboard_bucket_map( $map, 9, 'Other' );

		$data = array();
		foreach ( $map as $label => $count ) {
			$display_label = 'Other' === $label ? __( 'Other', 'opti-behavior' ) : $label;
			$data[] = array( 'os' => $display_label, 'count' => absint( $count ) );
		}

		if ( $unknown > 0 ) {
			$data[] = array( 'os' => __( 'Unknown', 'opti-behavior' ), 'count' => $unknown );
		}
		if ( $unclassified > 0 ) {
			$data[] = array( 'os' => __( 'Unclassified', 'opti-behavior' ), 'count' => $unclassified );
		}

		// Never freeze an empty result (Bug #4): that's the state most likely to
		// change on the very next session insert.
		return $this->set_dashboard_widget_cached_data( $cache_key, $data, empty( $data ), $start_date, $end_date, 'operating_systems' );
	}

	/**
	 * Filtered variant of get_operating_systems_data(). See
	 * get_screen_resolution_data_filtered() for the shared rationale (no
	 * cache, no unfiltered-baseline reconciliation, filters WHERE appended).
	 *
	 * @since 1.0.4
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param array  $filters    Sanitized advanced filters.
	 * @return array
	 */
	private function get_operating_systems_data_filtered( $start_date, $end_date, array $filters ) {
		global $wpdb;

		$spam_clause = $this->get_spam_exclusion_clause( 's' );
		$filter_sql  = $this->build_advanced_filters_sql( $filters );
		$params      = array_merge( array( $start_date, $end_date ), $filter_sql['params'] );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- Static LIKE patterns with no user input
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dashboard dimension aggregation.
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				CASE
					WHEN v.os IS NULL OR TRIM(v.os) = '' THEN 'Unknown'
					WHEN LOWER(TRIM(v.os)) IN ('unknown','undefined','other','') THEN 'Unknown'
					WHEN LOWER(TRIM(v.os)) IN ('mac os','macos','os x','mac os x','macintosh') OR LOWER(TRIM(v.os)) LIKE 'mac%%' THEN 'macOS'
					WHEN LOWER(TRIM(v.os)) IN ('ios','iphone os','ipados') OR LOWER(TRIM(v.os)) LIKE 'ios%%' THEN 'iOS'
					WHEN LOWER(TRIM(v.os)) LIKE 'windows%%' OR LOWER(TRIM(v.os)) LIKE 'win %%' OR LOWER(TRIM(v.os)) LIKE 'win10%%' OR LOWER(TRIM(v.os)) LIKE 'win11%%' THEN 'Windows'
					WHEN LOWER(TRIM(v.os)) LIKE 'android%%' THEN 'Android'
					WHEN LOWER(TRIM(v.os)) IN ('ubuntu','debian','fedora','centos','red hat','suse','freebsd','openbsd','netbsd') OR LOWER(TRIM(v.os)) LIKE 'linux%%' THEN 'Linux'
					WHEN LOWER(TRIM(v.os)) IN ('chromeos','chrome os','cros') THEN 'Chrome OS'
					ELSE TRIM(v.os)
				END AS os,
				COUNT(DISTINCT s.id) AS count
			FROM " . $wpdb->prefix . "optibehavior_sessions s
			LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
			WHERE s.start_time BETWEEN %s AND %s" . $spam_clause . $filter_sql['where'] . "
			GROUP BY os
			ORDER BY count DESC
			",
			$params
		) );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

		$map = array();
		foreach ( $results as $row ) {
			$label = isset( $row->os ) ? trim( (string) $row->os ) : '';
			if ( '' === $label ) {
				$label = 'Unknown';
			}
			if ( ! isset( $map[ $label ] ) ) { $map[ $label ] = 0; }
			$map[ $label ] += absint( $row->count );
		}

		$unknown = isset( $map['Unknown'] ) ? absint( $map['Unknown'] ) : 0;
		unset( $map['Unknown'] );
		$map = $this->limit_dashboard_bucket_map( $map, 9, 'Other' );

		$data = array();
		foreach ( $map as $label => $count ) {
			$display_label = 'Other' === $label ? __( 'Other', 'opti-behavior' ) : $label;
			$data[] = array( 'os' => $display_label, 'count' => absint( $count ) );
		}

		if ( $unknown > 0 ) {
			$data[] = array( 'os' => __( 'Unknown', 'opti-behavior' ), 'count' => $unknown );
		}

		return $data;
	}



	/**
	 * Get active visitors
	 *
	 * @return int
	 */
	private function get_active_visitors() {
		global $wpdb;

		// Consider visitors active if they had activity in the last 10 minutes
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 600 );

		// Get spam exclusion clause
		$spam_clause = $this->get_spam_exclusion_clause( 's' );

		// Get active visitors with their CURRENT page (most recent pageview), not just entry page.
		// Uses a subquery on optibehavior_pageviews to find the last page viewed per session.
		// Falls back to entry_page if no pageview exists yet (e.g., session just started).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				ls.session_id,
				ls.visitor_id,
				ls.entry_page,
				ls.start_time,
				ls.end_time,
				ls.ip,
				COALESCE(NULLIF(TRIM(v.country),''), 'UN') AS country,
				v.country_name,
				v.device_type,
				COALESCE(latest_pv.url, ls.entry_page) as current_page,
				COALESCE(latest_pv.title, p.title, 'Unknown Page') as page_title,
				COALESCE(ls.end_time, ls.start_time) as last_activity
			FROM (
				SELECT s.id as session_id, s.visitor_id, s.entry_page, s.start_time, s.end_time, s.ip,
					ROW_NUMBER() OVER (PARTITION BY s.visitor_id ORDER BY COALESCE(s.end_time, s.start_time) DESC) as rn
				FROM " . $wpdb->prefix . "optibehavior_sessions s
				WHERE (s.end_time > %s OR s.start_time > %s)" . $spam_clause . "
			) ls
			LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON ls.visitor_id = v.id
			LEFT JOIN " . $wpdb->prefix . "optibehavior_pages p ON p.id = (
				SELECT MIN(p2.id) FROM " . $wpdb->prefix . "optibehavior_pages p2
				WHERE p2.url = ls.entry_page OR p2.url2 = ls.entry_page
			)
			LEFT JOIN (
				SELECT pv1.session_id, pv1.url, pv1.title
				FROM " . $wpdb->prefix . "optibehavior_pageviews pv1
				INNER JOIN (
					-- PERF: only compute the latest pageview for sessions active in the
					-- cutoff window. Restricting the GROUP BY to the recent-session set
					-- (indexed eq-join on sessions.id) avoids the full-table
					-- MAX(view_time) GROUP BY scan over every pageview ever recorded.
					-- Result is identical because latest_pv is only LEFT JOINed onto
					-- ls.session_id, which is itself the recent set.
					SELECT pv.session_id, MAX(pv.view_time) as max_view_time
					FROM " . $wpdb->prefix . "optibehavior_pageviews pv
					INNER JOIN " . $wpdb->prefix . "optibehavior_sessions rs
						ON rs.id = pv.session_id AND (rs.end_time > %s OR rs.start_time > %s)
					GROUP BY pv.session_id
				) pv2 ON pv1.session_id = pv2.session_id AND pv1.view_time = pv2.max_view_time
				WHERE pv1.id = (
					SELECT MAX(pv3.id) FROM " . $wpdb->prefix . "optibehavior_pageviews pv3
					WHERE pv3.session_id = pv1.session_id AND pv3.view_time = pv2.max_view_time
				)
			) latest_pv ON ls.session_id = latest_pv.session_id
			WHERE ls.rn = 1
			ORDER BY last_activity DESC
			LIMIT 50",
			$cutoff,
			$cutoff,
			$cutoff,
			$cutoff
		) );

		// Generate flag emoji dynamically from ISO country code (fallback to globe)
		// This avoids missing flags like ZA (South Africa), etc.
		$country_flag = function(string $code) {
			$code = strtoupper(trim($code));
			if (preg_match('/^[A-Z]{2}$/', $code)) {
				$base = 127397; // regional indicator base
				$chars = str_split($code);
				$emoji = '';
				foreach ($chars as $ch) { $emoji .= mb_convert_encoding('&#' . ($base + ord($ch)) . ';', 'UTF-8', 'HTML-ENTITIES'); }
				return $emoji;
			}
			return '🌍';
		};

		$visitors = array();
		foreach ( $results as $row ) {
			$last_activity = strtotime($row->end_time ?: $row->start_time);
			$time_ago = $this->time_ago($last_activity);

			// Get page title with proper fallback logic
			$page_title = $row->page_title;
			$current_page = (string) $row->current_page;
			$visitor_ip = (string) ($row->ip ?? '');
			// Normalize empty/null IP to 'Anonymous' -- semantically equivalent: no IP was stored (GDPR-safe)
			if ( '' === $visitor_ip ) {
				$visitor_ip = 'Anonymous';
			}

			// Skip visitors without both a valid URL and IP address
			if ( empty( $current_page ) && empty( $visitor_ip ) ) {
				continue;
			}

			// If we still have "Unknown Page", try to extract a meaningful title from the URL
			if ( empty( $page_title ) || $page_title === 'Unknown Page' ) {
				if ( ! empty( $current_page ) ) {
					// Parse the URL to get a readable title
					$parsed_url = wp_parse_url( $current_page );
					$path = isset( $parsed_url['path'] ) ? trim( $parsed_url['path'], '/' ) : '';

					if ( empty( $path ) ) {
						$page_title = 'Homepage';
					} else {
						// Get the last segment of the path
						$segments = explode( '/', $path );
						$last_segment = end( $segments );

						// Convert slug to readable title
						$page_title = ucwords( str_replace( array( '-', '_' ), ' ', $last_segment ) );

						// If still empty, use a generic title
						if ( empty( $page_title ) ) {
							$page_title = 'Page';
						}
					}
				} else {
					$page_title = 'Unknown Page';
				}
			}

			$country_code = strtoupper($row->country ?: 'UN');
			$country_name = $row->country_name ?: ($country_code !== 'UN' ? $this->get_country_name($country_code) : 'Unknown');
			$visitors[] = array(
				'session_id'   => $row->session_id,
				'country'      => $country_name,
				'country_code' => $country_code,
				'flag'         => $country_flag($country_code),
				'page_title'   => $page_title,
				'current_url'  => $current_page,
				'visited_at'   => date_i18n('j-M H:i', $last_activity),
				'time_ago'     => $time_ago,
				'ip'           => $visitor_ip,
				'device_type'  => $row->device_type ?: 'Desktop'
			);
		}

		return $visitors;
	}

	/**
	 * Get time ago string
	 */
	private function time_ago($timestamp) {
		$time_diff = time() - $timestamp;

		if ($time_diff < 60) {
			return __( 'just now', 'opti-behavior' );
		} elseif ($time_diff < 3600) {
			$minutes = floor($time_diff / 60);
			/* translators: %s: number of minutes */
			return sprintf( __( '%sm ago', 'opti-behavior' ), $minutes );
		} elseif ($time_diff < 86400) {
			$hours = floor($time_diff / 3600);
			/* translators: %s: number of hours */
			return sprintf( __( '%sh ago', 'opti-behavior' ), $hours );
		} else {
			$days = floor($time_diff / 86400);
			/* translators: %s: number of days */
			return sprintf( __( '%sd ago', 'opti-behavior' ), $days );
		}
	}

	/**
	 * Get country name from country code
	 */
	private function get_country_name($country_code) {
		$country_code = strtoupper(trim((string)$country_code));
		$countries = array('AF'=>'Afghanistan','AX'=>'Åland Islands','AL'=>'Albania','DZ'=>'Algeria','AS'=>'American Samoa','AD'=>'Andorra','AO'=>'Angola','AI'=>'Anguilla','AQ'=>'Antarctica','AG'=>'Antigua and Barbuda','AR'=>'Argentina','AM'=>'Armenia','AW'=>'Aruba','AU'=>'Australia','AT'=>'Austria','AZ'=>'Azerbaijan','BS'=>'Bahamas','BH'=>'Bahrain','BD'=>'Bangladesh','BB'=>'Barbados','BY'=>'Belarus','BE'=>'Belgium','BZ'=>'Belize','BJ'=>'Benin','BM'=>'Bermuda','BT'=>'Bhutan','BO'=>'Bolivia','BQ'=>'Bonaire, Sint Eustatius and Saba','BA'=>'Bosnia and Herzegovina','BW'=>'Botswana','BV'=>'Bouvet Island','BR'=>'Brazil','IO'=>'British Indian Ocean Territory','BN'=>'Brunei','BG'=>'Bulgaria','BF'=>'Burkina Faso','BI'=>'Burundi','CV'=>'Cabo Verde','KH'=>'Cambodia','CM'=>'Cameroon','CA'=>'Canada','KY'=>'Cayman Islands','CF'=>'Central African Republic','TD'=>'Chad','CL'=>'Chile','CN'=>'China','CX'=>'Christmas Island','CC'=>'Cocos (Keeling) Islands','CO'=>'Colombia','KM'=>'Comoros','CG'=>'Congo','CD'=>'Congo, Democratic Republic of the','CK'=>'Cook Islands','CR'=>'Costa Rica','CI'=>'Côte d’Ivoire','HR'=>'Croatia','CU'=>'Cuba','CW'=>'Curaçao','CY'=>'Cyprus','CZ'=>'Czech Republic','DK'=>'Denmark','DJ'=>'Djibouti','DM'=>'Dominica','DO'=>'Dominican Republic','EC'=>'Ecuador','EG'=>'Egypt','SV'=>'El Salvador','GQ'=>'Equatorial Guinea','ER'=>'Eritrea','EE'=>'Estonia','SZ'=>'Eswatini','ET'=>'Ethiopia','FK'=>'Falkland Islands','FO'=>'Faroe Islands','FJ'=>'Fiji','FI'=>'Finland','FR'=>'France','GF'=>'French Guiana','PF'=>'French Polynesia','TF'=>'French Southern Territories','GA'=>'Gabon','GM'=>'Gambia','GE'=>'Georgia','DE'=>'Germany','GH'=>'Ghana','GI'=>'Gibraltar','GR'=>'Greece','GL'=>'Greenland','GD'=>'Grenada','GP'=>'Guadeloupe','GU'=>'Guam','GT'=>'Guatemala','GG'=>'Guernsey','GN'=>'Guinea','GW'=>'Guinea-Bissau','GY'=>'Guyana','HT'=>'Haiti','HM'=>'Heard Island and McDonald Islands','VA'=>'Holy See','HN'=>'Honduras','HK'=>'Hong Kong','HU'=>'Hungary','IS'=>'Iceland','IN'=>'India','ID'=>'Indonesia','IR'=>'Iran','IQ'=>'Iraq','IE'=>'Ireland','IM'=>'Isle of Man','IL'=>'Israel','IT'=>'Italy','JM'=>'Jamaica','JP'=>'Japan','JE'=>'Jersey','JO'=>'Jordan','KZ'=>'Kazakhstan','KE'=>'Kenya','KI'=>'Kiribati','KP'=>'North Korea','KR'=>'South Korea','KW'=>'Kuwait','KG'=>'Kyrgyzstan','LA'=>'Laos','LV'=>'Latvia','LB'=>'Lebanon','LS'=>'Lesotho','LR'=>'Liberia','LY'=>'Libya','LI'=>'Liechtenstein','LT'=>'Lithuania','LU'=>'Luxembourg','MO'=>'Macao','MG'=>'Madagascar','MW'=>'Malawi','MY'=>'Malaysia','MV'=>'Maldives','ML'=>'Mali','MT'=>'Malta','MH'=>'Marshall Islands','MQ'=>'Martinique','MR'=>'Mauritania','MU'=>'Mauritius','YT'=>'Mayotte','MX'=>'Mexico','FM'=>'Micronesia','MD'=>'Moldova','MC'=>'Monaco','MN'=>'Mongolia','ME'=>'Montenegro','MS'=>'Montserrat','MA'=>'Morocco','MZ'=>'Mozambique','MM'=>'Myanmar','NA'=>'Namibia','NR'=>'Nauru','NP'=>'Nepal','NL'=>'Netherlands','NC'=>'New Caledonia','NZ'=>'New Zealand','NI'=>'Nicaragua','NE'=>'Niger','NG'=>'Nigeria','NU'=>'Niue','NF'=>'Norfolk Island','MK'=>'North Macedonia','MP'=>'Northern Mariana Islands','NO'=>'Norway','OM'=>'Oman','PK'=>'Pakistan','PW'=>'Palau','PS'=>'Palestine, State of','PA'=>'Panama','PG'=>'Papua New Guinea','PY'=>'Paraguay','PE'=>'Peru','PH'=>'Philippines','PN'=>'Pitcairn','PL'=>'Poland','PT'=>'Portugal','PR'=>'Puerto Rico','QA'=>'Qatar','RE'=>'Réunion','RO'=>'Romania','RU'=>'Russia','RW'=>'Rwanda','BL'=>'Saint Barthélemy','SH'=>'Saint Helena, Ascension and Tristan da Cunha','KN'=>'Saint Kitts and Nevis','LC'=>'Saint Lucia','MF'=>'Saint Martin','PM'=>'Saint Pierre and Miquelon','VC'=>'Saint Vincent and the Grenadines','WS'=>'Samoa','SM'=>'San Marino','ST'=>'São Tomé and Príncipe','SA'=>'Saudi Arabia','SN'=>'Senegal','RS'=>'Serbia','SC'=>'Seychelles','SL'=>'Sierra Leone','SG'=>'Singapore','SX'=>'Sint Maarten','SK'=>'Slovakia','SI'=>'Slovenia','SB'=>'Solomon Islands','SO'=>'Somalia','ZA'=>'South Africa','GS'=>'South Georgia and the South Sandwich Islands','SS'=>'South Sudan','ES'=>'Spain','LK'=>'Sri Lanka','SD'=>'Sudan','SR'=>'Suriname','SJ'=>'Svalbard and Jan Mayen','SE'=>'Sweden','CH'=>'Switzerland','SY'=>'Syria','TW'=>'Taiwan','TJ'=>'Tajikistan','TZ'=>'Tanzania','TH'=>'Thailand','TL'=>'Timor-Leste','TG'=>'Togo','TK'=>'Tokelau','TO'=>'Tonga','TT'=>'Trinidad and Tobago','TN'=>'Tunisia','TR'=>'Türkiye','TM'=>'Turkmenistan','TC'=>'Turks and Caicos Islands','TV'=>'Tuvalu','UG'=>'Uganda','UA'=>'Ukraine','AE'=>'United Arab Emirates','GB'=>'United Kingdom','US'=>'United States','UM'=>'U.S. Outlying Islands','UY'=>'Uruguay','UZ'=>'Uzbekistan','VU'=>'Vanuatu','VE'=>'Venezuela','VN'=>'Vietnam','VG'=>'British Virgin Islands','VI'=>'U.S. Virgin Islands','WF'=>'Wallis and Futuna','EH'=>'Western Sahara','YE'=>'Yemen','ZM'=>'Zambia','ZW'=>'Zimbabwe');

		// Fallback: if code not in map, show the code itself (never "Unknown")
		return $countries[$country_code] ?? ($country_code ?: 'Other');
	}

	/**
	 * Get recent sessions
	 *
	 * @param int $limit Number of sessions to return.
	 * @return array
	 */
	private function get_recent_sessions( $limit = 10 ) {
		global $wpdb;

		// Get spam exclusion clause
		$spam_clause = $this->get_spam_exclusion_clause( 's' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Analytics data requires direct DB queries; table name from $wpdb->prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				s.id,
				s.start_time,
				s.entry_page,
				v.device_type,
				v.country_name
			FROM " . $wpdb->prefix . "optibehavior_sessions s
			LEFT JOIN " . $wpdb->prefix . "optibehavior_visitors v ON s.visitor_id = v.id
			WHERE 1=1" . $spam_clause . "
			ORDER BY s.start_time DESC
			LIMIT %d",
			$limit
		) );

		$data = array();
		foreach ( $results as $row ) {
			$data[] = array(
				'id' => $row->id,
				'start_time' => $row->start_time,
				'entry_page' => $row->entry_page,
				'device_type' => $row->device_type,
				'country_name' => $row->country_name,
			);
		}

		return $data;
	}

	/**
	 * Get funnel data
	 *
	 * @param array $date_range Date range.
	 * @return array
	 */
	private function get_funnel_data( $date_range ) {
		// Placeholder for funnel analysis
		return array(
			'steps' => array(),
			'conversion_rate' => 0,
		);
	}

	/**
	 * Get user journey data
	 *
	 * @param array $date_range Date range.
	 * @return array
	 */
	private function get_user_journey_data( $date_range ) {
		// Placeholder for user journey analysis
		return array(
			'paths' => array(),
			'common_flows' => array(),
		);
	}

	/**
	 * Get conversion data
	 *
	 * @param array $date_range Date range.
	 * @return array
	 */
	private function get_conversion_data( $date_range ) {
		// Placeholder for conversion analysis
		return array(
			'goals' => array(),
			'conversion_rate' => 0,
		);
	}

	/**
	 * Get retention data
	 *
	 * @param array $date_range Date range.
	 * @return array
	 */
	private function get_retention_data( $date_range ) {
		// Placeholder for retention analysis
		return array(
			'cohorts' => array(),
			'retention_rate' => 0,
		);
	}

	/**
	 * Get overview analytics
	 *
	 * @param array $date_range Date range.
	 * @return array
	 */
	private function get_overview_analytics( $date_range ) {
		return array(
			'summary' => $this->get_dashboard_data( 'last30days' ),
			'trends' => array(),
		);
	}

	/**
	 * Render modern settings page
	 */
	public function render_settings() {
		return $this->render_settings_impl();
	}

	/**
	 * Render settings page header
	 */
	private function render_settings_header() {
		return $this->render_settings_header_impl();
	}

	/**
	 * Render settings content
	 */
	private function render_settings_content($settings) {
		return $this->render_settings_content_impl($settings);
	}

	/**
	 * Get current settings from database
	 */
	private function get_current_settings() {
		return $this->get_current_settings_impl();
	}

	/**
	 * Handle settings form submission
	 */
	private function delete_all_analytics_data() {
		return $this->delete_all_analytics_data_impl();
	}

	private function delete_logs_in_date_range($start, $end){
		return $this->delete_logs_in_date_range_impl($start, $end);
	}

	private function export_analytics_csv(){
		return $this->export_analytics_csv_impl();
	}

	private function export_analytics_sql(){
		return $this->export_analytics_sql_impl();
	}

	private function handle_settings_save() {
		return $this->handle_settings_save_impl();
	}

		/**
		 * Conditionally ensure helpful DB indexes for performance, at most once per day
		 */
		public function maybe_ensure_db_indexes(){
			return $this->maybe_ensure_db_indexes_impl();
		}

		/**
		 * Create missing DB indexes used by dashboard queries
		 */
		private function ensure_db_indexes(){
			return $this->ensure_db_indexes_impl();
		}

		/**
		 * Conditionally backfill referrers (utm_source) from entry_page for recent sessions
		 * Runs at most once per day and only for admins
		 */
		public function maybe_backfill_referrers(){
			return $this->maybe_backfill_referrers_impl();
		}

		private function backfill_referrers_from_entry_page($days = 30, $limit = 2000){
			return $this->backfill_referrers_from_entry_page_impl($days, $limit);
		}

		/**
		 * AJAX handler: Cleanup recordings by date
		 */
		public function ajax_cleanup_by_date() {
			return $this->ajax_cleanup_by_date_impl();
		}

		/**
		 * AJAX handler: Cleanup recordings by duration
		 */
		public function ajax_cleanup_by_duration() {
			return $this->ajax_cleanup_by_duration_impl();
		}

		/**
		 * AJAX handler: Cleanup orphaned recording files
		 */
		public function ajax_cleanup_orphaned() {
			return $this->ajax_cleanup_orphaned_files_impl();
		}

		/**
		 * AJAX handler to get debug log contents
		 */
		public function ajax_get_debug_log() {
			// Verify nonce
			if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'opti_behavior_view_log' ) ) {
				wp_send_json_error( 'Security check failed' );
			}

			// Check permissions
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Insufficient permissions' );
			}

			$debug_manager = $this->heatmap->get_debug_manager();
			$log_contents = $debug_manager->get_log_contents( 500 ); // Last 500 lines

			wp_send_json_success( array( 'log_content' => $log_contents ) );
		}

		/**
		 * Handle debug log download
		 */
		public function handle_debug_log_download() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameter check for download action (permission check follows)
			// Check if download is requested
			if ( ! isset( $_GET['opti_behavior_download_log'] ) ) {
				return;
			}

			// Check permissions
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'Insufficient permissions' );
			}

			// Check we're on the settings page
			if ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'opti-behavior-settings' ) {
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				return;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$debug_manager = $this->heatmap->get_debug_manager();
			$log_file = $debug_manager->get_log_file_path();

			if ( ! file_exists( $log_file ) ) {
				wp_die( 'Log file not found' );
			}

			// Set headers for download
			header( 'Content-Type: text/plain' );
			header( 'Content-Disposition: attachment; filename="opti-behavior-heatmap-debug-' . gmdate( 'Y-m-d-H-i-s' ) . '.log"' );
			header( 'Content-Length: ' . filesize( $log_file ) );

			// Stream file contents directly -- readfile() is correct for local file downloads
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming local log file for admin download
			readfile( $log_file );
			exit;
		}

		/**
		 * AJAX: Get schedule data
		 */
		public function ajax_get_schedule() {
			check_ajax_referer( 'opti_behavior_schedule_action', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Permission denied' );
			}

			$schedule_id = isset( $_POST['schedule_id'] ) ? intval( $_POST['schedule_id'] ) : 0;
			if ( ! $schedule_id ) {
				wp_send_json_error( 'Invalid schedule ID' );
			}

			$scheduler = new Opti_Behavior_Report_Scheduler( $this->heatmap );
			$schedule = $scheduler->get_schedule( $schedule_id );

			if ( ! $schedule ) {
				wp_send_json_error( 'Schedule not found' );
			}

			wp_send_json_success( $schedule );
		}

		/**
		 * AJAX: Save schedule
		 */
		public function ajax_save_schedule() {
			check_ajax_referer( 'opti_behavior_schedule_action', 'schedule_nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Permission denied' );
			}

			$schedule_id = isset( $_POST['schedule_id'] ) ? intval( $_POST['schedule_id'] ) : 0;

			// Collect form data
			$data = array(
				'name'                      => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'frequency'                 => isset( $_POST['frequency'] ) ? sanitize_key( $_POST['frequency'] ) : 'weekly',
				'day_of_week'               => isset( $_POST['day_of_week'] ) ? intval( $_POST['day_of_week'] ) : 1,
				'day_of_month'              => isset( $_POST['day_of_month'] ) ? intval( $_POST['day_of_month'] ) : 1,
				'send_time'                 => isset( $_POST['send_time'] ) ? sanitize_text_field( wp_unslash( $_POST['send_time'] ) ) . ':00' : '09:00:00',
				'report_period'             => isset( $_POST['report_period'] ) ? sanitize_key( $_POST['report_period'] ) : 'last7days',
				'include_kpis'              => isset( $_POST['include_kpis'] ) ? 1 : 0,
				'include_top_pages'         => isset( $_POST['include_top_pages'] ) ? 1 : 0,
				'include_top_referrers'     => isset( $_POST['include_top_referrers'] ) ? 1 : 0,
				'include_traffic_breakdown' => isset( $_POST['include_traffic_breakdown'] ) ? 1 : 0,
				'include_smart_insights'    => isset( $_POST['include_smart_insights'] ) ? 1 : 0,
				'include_funnels'           => isset( $_POST['include_funnels'] ) ? 1 : 0,
				'include_heatmap_summary'   => isset( $_POST['include_heatmap_summary'] ) ? 1 : 0,
				'include_geographic'        => isset( $_POST['include_geographic'] ) ? 1 : 0,
				'include_recordings_stats'  => isset( $_POST['include_recordings_stats'] ) ? 1 : 0,
				'include_errors'            => isset( $_POST['include_errors'] ) ? 1 : 0,
				'include_friction'          => isset( $_POST['include_friction'] ) ? 1 : 0,
				'include_performance'       => isset( $_POST['include_performance'] ) ? 1 : 0,
				'include_broken_links'      => isset( $_POST['include_broken_links'] ) ? 1 : 0,
				'include_user_journeys'     => isset( $_POST['include_user_journeys'] ) ? 1 : 0,
				'include_form_analytics'    => isset( $_POST['include_form_analytics'] ) ? 1 : 0,
				'recipients'                => isset( $_POST['recipients'] ) ? sanitize_textarea_field( wp_unslash( $_POST['recipients'] ) ) : '',
			);

			$scheduler = new Opti_Behavior_Report_Scheduler( $this->heatmap );

			if ( $schedule_id ) {
				// Update existing
				$result = $scheduler->update_schedule( $schedule_id, $data );
			} else {
				// Create new
				$result = $scheduler->create_schedule( $data );
			}

			if ( $result ) {
				wp_send_json_success( array( 'id' => $result ) );
			} else {
				wp_send_json_error( 'Failed to save schedule. Make sure you have valid email recipients.' );
			}
		}

		/**
		 * AJAX: Toggle schedule enabled/disabled
		 */
		public function ajax_toggle_schedule() {
			check_ajax_referer( 'opti_behavior_schedule_action', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Permission denied' );
			}

			$schedule_id = isset( $_POST['schedule_id'] ) ? intval( $_POST['schedule_id'] ) : 0;
			$enabled = isset( $_POST['enabled'] ) ? intval( $_POST['enabled'] ) : 0;

			if ( ! $schedule_id ) {
				wp_send_json_error( 'Invalid schedule ID' );
			}

			$scheduler = new Opti_Behavior_Report_Scheduler( $this->heatmap );
			$result = $scheduler->toggle_schedule( $schedule_id, $enabled );

			if ( $result ) {
				wp_send_json_success();
			} else {
				wp_send_json_error( 'Failed to toggle schedule' );
			}
		}

		/**
		 * AJAX: Delete schedule
		 */
		public function ajax_delete_schedule() {
			check_ajax_referer( 'opti_behavior_schedule_action', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Permission denied' );
			}

			$schedule_id = isset( $_POST['schedule_id'] ) ? intval( $_POST['schedule_id'] ) : 0;

			if ( ! $schedule_id ) {
				wp_send_json_error( 'Invalid schedule ID' );
			}

			$scheduler = new Opti_Behavior_Report_Scheduler( $this->heatmap );
			$result = $scheduler->delete_schedule( $schedule_id );

			if ( $result ) {
				wp_send_json_success();
			} else {
				wp_send_json_error( 'Failed to delete schedule' );
			}
		}

		/**
		 * AJAX: Send test report email
		 */
		public function ajax_test_report() {
			check_ajax_referer( 'opti_behavior_schedule_action', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Permission denied' );
			}

			$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$schedule_id = isset( $_POST['schedule_id'] ) ? intval( $_POST['schedule_id'] ) : 0;

			if ( ! is_email( $email ) ) {
				wp_send_json_error( 'Invalid email address' );
			}

			// Get schedule if provided
			$schedule = array();
			if ( $schedule_id ) {
				$scheduler = new Opti_Behavior_Report_Scheduler( $this->heatmap );
				$schedule = $scheduler->get_schedule( $schedule_id );
			}

			try {
				// Send test
				$mailer = new Opti_Behavior_Report_Mailer( $this->heatmap );
				$result = $mailer->send_test( $email, $schedule );

				if ( is_wp_error( $result ) ) {
					wp_send_json_error( $result->get_error_message() );
				}

				wp_send_json_success();
			} catch ( \Throwable $opti_behavior_err ) {
				wp_send_json_error( 'Exception: ' . $opti_behavior_err->getMessage() . ' in ' . $opti_behavior_err->getFile() . ':' . $opti_behavior_err->getLine() );
			}
		}

		/**
		 * AJAX: Save report email settings
		 */
		public function ajax_save_email_settings() {
			check_ajax_referer( 'opti_behavior_schedule_action', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Permission denied' );
			}

			$from_name = isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '';
			$from_email = isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '';
			$email_method = isset( $_POST['email_method'] ) && in_array( $_POST['email_method'], array( 'wp_mail', 'smtp' ), true )
				? sanitize_text_field( wp_unslash( $_POST['email_method'] ) )
				: 'wp_mail';

			// Validate email if provided
			if ( ! empty( $from_email ) && ! is_email( $from_email ) ) {
				wp_send_json_error( __( 'Invalid email address', 'opti-behavior' ) );
			}

			// Build a plain array of changed values to pass to save()
			$new_options = array(
				'reports_from_name'  => $from_name,
				'reports_from_email' => $from_email,
				'email_method'       => $email_method,
			);

			// Save SMTP settings if method is smtp
			if ( 'smtp' === $email_method ) {
				$new_options['smtp_host']       = isset( $_POST['smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_host'] ) ) : '';
				$new_options['smtp_port']       = isset( $_POST['smtp_port'] ) ? absint( $_POST['smtp_port'] ) : 587;
				$new_options['smtp_encryption'] = isset( $_POST['smtp_encryption'] ) && in_array( $_POST['smtp_encryption'], array( 'none', 'ssl', 'tls' ), true )
					? sanitize_text_field( wp_unslash( $_POST['smtp_encryption'] ) )
					: 'tls';
				$new_options['smtp_username']   = isset( $_POST['smtp_username'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_username'] ) ) : '';
				// Only update password if a new one was provided (not the placeholder)
				$smtp_password = isset( $_POST['smtp_password'] ) ? wp_unslash( $_POST['smtp_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( ! empty( $smtp_password ) ) {
					$new_options['smtp_password'] = $smtp_password;
				}
			}

			$this->heatmap->get_options()->save( $new_options );

			wp_send_json_success( array(
				'message' => __( 'Email settings saved', 'opti-behavior' ),
			) );
		}

		/**
		 * AJAX: Test email connection (sends a simple test email using saved settings)
		 */
		public function ajax_test_email_connection() {
			check_ajax_referer( 'opti_behavior_schedule_action', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Permission denied' );
			}

			$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			if ( ! is_email( $email ) ) {
				wp_send_json_error( __( 'Invalid email address', 'opti-behavior' ) );
			}

			$options = $this->heatmap->get_options();
			$email_method = isset( $options['email_method'] ) ? $options['email_method'] : 'wp_mail';
			$from_name = ! empty( $options['reports_from_name'] ) ? $options['reports_from_name'] : get_bloginfo( 'name' );
			$from_email = ! empty( $options['reports_from_email'] ) ? $options['reports_from_email'] : get_option( 'admin_email' );

			$subject = sprintf(
				/* translators: %s: site name */
				__( '[%s] Opti-Behavior Test Email', 'opti-behavior' ),
				get_bloginfo( 'name' )
			);
			$body = sprintf(
				/* translators: 1: site name, 2: email method */
				__( 'This is a test email from %1$s sent via %2$s. If you received this, your email configuration is working correctly.', 'opti-behavior' ),
				get_bloginfo( 'name' ),
				'smtp' === $email_method ? 'Custom SMTP' : 'WordPress Default (wp_mail)'
			);

			try {
				if ( 'smtp' === $email_method ) {
					$result = Opti_Behavior_Report_Mailer::send_via_smtp(
						$email,
						$subject,
						$body,
						$from_name,
						$from_email,
						$options
					);
					if ( true !== $result ) {
						wp_send_json_error( $result );
					}
				} else {
					$headers = array(
						'Content-Type: text/html; charset=UTF-8',
						sprintf( 'From: %s <%s>', $from_name, $from_email ),
					);
					$sent = wp_mail( $email, $subject, $body, $headers );
					if ( ! $sent ) {
						wp_send_json_error( __( 'wp_mail() failed — check your server mail configuration', 'opti-behavior' ) );
					}
				}
				wp_send_json_success();
			} catch ( \Throwable $opti_behavior_err ) {
				wp_send_json_error( $opti_behavior_err->getMessage() );
			}
		}

}
