<?php
/**
 * Uninstall handler for Opti-Behavior
 *
 * Removes plugin options, transients, database tables, cron jobs,
 * and file storage when the plugin is deleted via the WordPress Plugins admin page.
 *
 * @package opti-behavior
 * @since   1.2.4
 */

// Exit if not called by WordPress uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$opti_behavior_delete_on_uninstall = get_option( 'opti_behavior_delete_on_uninstall', false );

if ( ! $opti_behavior_delete_on_uninstall ) {
	return;
}

// Prevent data deletion if multiple plugin instances exist.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$opti_behavior_installed = 0;
foreach ( array_keys( get_plugins() ) as $plugin ) {
	if ( 'Opti-Behavior.php' !== basename( $plugin ) ) {
		continue;
	}
	++$opti_behavior_installed;
	if ( 1 < $opti_behavior_installed ) {
		return;
	}
}

global $wpdb;

// Snapshot the debug-log location BEFORE the options are wiped: the log
// folder is removed further down, after the data directory.
$opti_behavior_debug_settings = get_option( 'opti_behavior_heatmap_debug_settings', array() );
$opti_behavior_debug_settings = is_array( $opti_behavior_debug_settings ) ? $opti_behavior_debug_settings : array();

// Delete all plugin options.
delete_option( 'opti_behavior_heatmap_option' );
delete_option( 'opti_behavior_delete_on_uninstall' );
delete_option( 'opti_behavior_db_size_cap_state' );
delete_option( 'opti_behavior_db_schema_migration_state' );
delete_option( 'opti_behavior_page_type_prune_migrated' );
delete_option( 'opti_behavior_page_type_prune_state' );
delete_option( 'opti_behavior_heatmap_debug_settings' );
delete_option( 'opti_behavior_heatmap_data_protection' );
delete_option( 'opti_behavior_heatmap_last_integrity_check' );
delete_option( 'opti_behavior_db_version' );
delete_option( 'opti_behavior_db_optimization_status' );
delete_option( 'opti_behavior_file_storage_settings' );
// Orphaned recording-file sweep (Ticket C, scheduled by Pro).
delete_option( 'opti_behavior_orphan_sweep_done' );
delete_option( 'opti_behavior_orphan_sweep_state' );
delete_option( 'opti_behavior_heatmap_migration_status' );
delete_option( 'opti_behavior_heatmap_migration_complete' );
delete_option( 'opti_behavior_hm_pages_deduped' );
delete_option( 'opti_behavior_master_secret' );
delete_option( 'opti_behavior_auto_cleanup_settings' );
delete_option( 'opti_behavior_cleanup_logs' );
delete_option( 'opti_behavior_cleanup_task_runs' );
delete_option( 'opti_behavior_cleanup_task_manual' );
delete_option( 'opti_behavior_admin_language' );
delete_option( 'opti_behavior_i18n_migrated_1815' );
delete_option( 'opti_behavior_traffic_settings' );
delete_option( 'opti_behavior_intent_rules' );
// Site-wide saved advanced-filter profiles (FREE-owned; PRO must never delete it).
delete_option( 'opti_behavior_filter_profiles' );
delete_option( 'opti_behavior_form_analytics_settings' );
// Free tracker options
delete_option( 'opti_behavior_tracker_last_heartbeat' );
delete_option( 'opti_behavior_tracked' );
// Consent / welcome options
delete_option( 'opti_behavior_consent_accepted' );
delete_option( 'opti_behavior_activation_redirect' );
// Onboarding popup options
delete_option( 'opti_behavior_onboarding_dismissed' );
delete_option( 'opti_behavior_onboarding_goal' );
// Review reminder banner options
delete_option( 'opti_behavior_review_installed_at' );
delete_option( 'opti_behavior_review_banner_status' );
delete_option( 'opti_behavior_review_banner_remind_at' );
// A/B testing options.
delete_option( 'opti_behavior_ab_free_limits' );
delete_option( 'opti_behavior_ab_settings' );
delete_option( 'opti_behavior_ab_db_version' );
delete_option( 'opti_behavior_ab_ga4_settings' );
// Trial-related options.
delete_option( 'opti_behavior_trial_license_key' );
delete_option( 'opti_behavior_trial_expires_at' );
delete_option( 'opti_behavior_installation_id' );
delete_option( 'opti_behavior_active_trial_key' );
// Scheduled heatmap sync auto-repair (Danger Zone toggle).
delete_option( 'opti_behavior_heatmap_auto_repair_enabled' );
delete_option( 'opti_behavior_heatmap_auto_repair_cursor' );
// File-first heatmap registry rebuild (Danger Zone tool).
delete_option( 'opti_behavior_heatmap_rebuild_cursor' );
delete_option( 'opti_behavior_heatmap_rebuild_progress' );
delete_option( 'opti_behavior_heatmap_rebuild_control' );
// Product auto-rebuild triggers (one-shot migration flag + weekly probe).
delete_option( 'opti_behavior_heatmap_rebuild_migration_done' );
delete_option( 'opti_behavior_heatmap_rebuild_probe_last_run' );
delete_option( 'opti_behavior_heatmap_rebuild_probe_baseline' );
// `_orphaned/` archive dry-run report (Danger Zone tool, report-only).
delete_option( 'opti_behavior_heatmap_orphan_report_cursor' );
delete_option( 'opti_behavior_heatmap_orphan_report_progress' );
delete_option( 'opti_behavior_heatmap_orphan_report_control' );
// `_orphaned/` archive retention purge (daily auto-protection).
delete_option( 'opti_behavior_heatmap_orphan_purge_cursor' );
delete_option( 'opti_behavior_heatmap_orphan_purge_progress' );
delete_option( 'opti_behavior_heatmap_orphan_retention_days' );
// Unified Retention Protocol (master raw-data retention + dimension backfill flag).
delete_option( 'opti_behavior_data_retention' );
delete_option( 'opti_behavior_dimension_backfill_done' );
// Tiered Retention (round 2): heavy-files tier "files archived" page flags.
delete_option( 'opti_behavior_heatmap_files_expired_pages' );
// Pending "debug auto-disabled after 3 hours" admin notice.
delete_option( 'opti_behavior_debug_auto_disabled_notice' );

// Settings groups written by the settings page / subsystems.
delete_option( 'opti_behavior_debug_settings' );
delete_option( 'opti_behavior_privacy_settings' );
delete_option( 'opti_behavior_recording_settings' );
delete_option( 'opti_behavior_errors_tracking_settings' );
delete_option( 'opti_behavior_report_email_settings' );
delete_option( 'opti_behavior_report_schedules' );
delete_option( 'opti_behavior_frontend_stats_bar' );
delete_option( 'opti_behavior_onboarding_goal_recipe' );

// Smart Insights (generator, scheduler, notifications, segment cache salt).
delete_option( 'opti_behavior_smart_insights_last_generation' );
delete_option( 'opti_behavior_smart_insights_notifications' );
delete_option( 'opti_behavior_smart_insights_scheduler' );
delete_option( 'opti_behavior_smart_insights_scheduler_state' );
delete_option( 'opti_behavior_smart_insights_segment_cache_salt' );

// Privacy salts + registration / API heartbeat state.
delete_option( 'opti_behavior_daily_salt' );
delete_option( 'opti_behavior_daily_salt_date' );
delete_option( 'opti_behavior_registered_site_url' );
delete_option( 'opti_behavior_registration_date' );
delete_option( 'opti_behavior_last_api_success' );

// Engagement counters + funnel autopilot / suggestions state.
delete_option( 'opti_behavior_engagement_counters' );
delete_option( 'opti_behavior_funnel_autopilot_last_scan' );
delete_option( 'opti_behavior_funnel_suggestions_dismissed' );
delete_option( 'opti_behavior_bl_last_sweep' );

// Heatmap sync / reconcile / tier cursors and cache version.
delete_option( 'opti_behavior_heatmap_auto_repair_last_complete' );
delete_option( 'opti_behavior_heatmap_backfill_complete' );
delete_option( 'opti_behavior_heatmap_backfill_cursor' );
delete_option( 'opti_behavior_heatmap_cache_ver' );
delete_option( 'opti_behavior_heatmap_files_tier_cursor' );
delete_option( 'opti_behavior_heatmap_page_resets' );
delete_option( 'opti_behavior_heatmap_force_indexed_until' );
delete_option( 'opti_behavior_heatmap_reconcile_cursor' );
delete_option( 'opti_behavior_heatmap_sync_cursor' );
delete_option( 'opti_behavior_heatmap_total_files_estimate' );
delete_option( 'opti_behavior_stale_finalize_watermark' );

// Cleanup-sweep watermarks (dashboard / errors / recordings tiers).
delete_option( 'opti_behavior_sweep_dashboard' );
delete_option( 'opti_behavior_sweep_dashboard_dirty' );
delete_option( 'opti_behavior_sweep_errors' );
delete_option( 'opti_behavior_sweep_errors_dirty' );
delete_option( 'opti_behavior_sweep_recordings' );
delete_option( 'opti_behavior_sweep_recordings_dirty' );

// One-shot migration / backfill flags (never re-created after uninstall).
delete_option( 'opti_behavior_ab_metadata_backfill' );
delete_option( 'opti_behavior_auto_cleanup_defaults_migrated' );
delete_option( 'opti_behavior_empty_titles_backfilled' );
delete_option( 'opti_behavior_error_origin_backfill_done' );
delete_option( 'opti_behavior_error_origin_backfill_v2_done' );
delete_option( 'opti_behavior_error_origin_backfill_v3_done' );
delete_option( 'opti_behavior_error_visitor_backfill_done' );
delete_option( 'opti_behavior_exit_pages_backfilled' );
delete_option( 'opti_behavior_friction_error_group_backfill_done' );
delete_option( 'opti_behavior_friction_error_group_gap_drain_done' );
delete_option( 'opti_behavior_friction_error_signature_backfill_done' );
delete_option( 'opti_behavior_friction_noise_cleanup_done' );
delete_option( 'opti_behavior_friction_target_key_backfill_done' );
delete_option( 'opti_behavior_hm_agg_allowlist_url_resynced' );
delete_option( 'opti_behavior_hm_orphan_files_archived' );
delete_option( 'opti_behavior_hm_qa_mappings_purged' );
delete_option( 'opti_behavior_invalid_data_cleaned_ver' );
delete_option( 'opti_behavior_large_indexes_ver' );
delete_option( 'opti_behavior_null_ip_migrated' );
delete_option( 'opti_behavior_pageview_exit_flags_backfilled' );
delete_option( 'opti_behavior_sc_max_rows_50k_migrated' );
delete_option( 'opti_behavior_session_duration_cap_migrated' );
delete_option( 'opti_behavior_session_duration_cap_migrated_v2' );
delete_option( 'opti_behavior_short_session_default_migrated' );
delete_option( 'opti_behavior_spam_duration_default_migrated' );
delete_option( 'opti_behavior_spam_reclassify_v1_done' );
delete_option( 'opti_behavior_spam_scroll_default_migrated' );
delete_option( 'opti_behavior_url2_canonical_v2_migrated' );

// Transients.
delete_transient( 'opti_behavior_trial_failed' );
delete_transient( 'opti_behavior_pro_download_url' );

// Clear all scheduled cron jobs.
//
// Opti_Behavior_Heatmap_Core::get_all_cron_hooks() is the single source of
// truth for this list (QA-B-SCHEMA-002 / QA-F-SCHEMA-003). It is iterated
// below when the class file can be loaded, so a hook added there is cleared
// here automatically; the literal calls that follow keep the uninstall safe
// when the plugin files are already gone and keep the static parity check
// able to read the list without executing this script.
$opti_behavior_core_file = plugin_dir_path( __FILE__ ) . 'includes/class-opti-behavior-heatmap-core.php';
if ( ! class_exists( 'Opti_Behavior_Heatmap_Core' ) && is_readable( $opti_behavior_core_file ) ) {
	require_once $opti_behavior_core_file;
}
if ( class_exists( 'Opti_Behavior_Heatmap_Core' ) && method_exists( 'Opti_Behavior_Heatmap_Core', 'get_all_cron_hooks' ) ) {
	foreach ( (array) Opti_Behavior_Heatmap_Core::get_all_cron_hooks() as $opti_behavior_cron_hook ) {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( (string) $opti_behavior_cron_hook );
		} else {
			wp_clear_scheduled_hook( (string) $opti_behavior_cron_hook );
		}
	}
}

// --- Recurring events ---
wp_clear_scheduled_hook( 'opti_behavior_heatmap_cron_daily' );
wp_clear_scheduled_hook( 'opti_behavior_aggregate_daily_stats' );
wp_clear_scheduled_hook( 'opti_behavior_send_scheduled_reports' );
wp_clear_scheduled_hook( 'opti_behavior_scheduled_smart_cleanup' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_auto_repair' );
wp_clear_scheduled_hook( 'opti_behavior_smart_insights_generate_daily' );
wp_clear_scheduled_hook( 'opti_behavior_smart_insights_outcome_check' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_sync_reconcile' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_reconcile' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_registry_backfill' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_orphan_purge_daily' );
wp_clear_scheduled_hook( 'opti_behavior_finalize_stale_sessions' );
wp_clear_scheduled_hook( 'opti_behavior_ab_aggregate_daily' );
wp_clear_scheduled_hook( 'opti_behavior_ab_auto_winner_check' );
wp_clear_scheduled_hook( 'opti_behavior_ab_cleanup' );
wp_clear_scheduled_hook( 'opti_behavior_db_size_cap_run' );
wp_clear_scheduled_hook( 'opti_behavior_db_schema_migration_run' );
wp_clear_scheduled_hook( 'opti_behavior_daily_heartbeat' );
// --- One-off events ---
wp_clear_scheduled_hook( 'opti_behavior_debug_auto_disable' );
wp_clear_scheduled_hook( 'opti_behavior_smart_insights_scheduler_batch' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_agg_sync' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_daily_sync' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_stats_warm' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_registry_rebuild_files' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_orphan_report_scan' );
wp_clear_scheduled_hook( 'opti_behavior_canonlist_refresh' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_canon_sync' );
wp_clear_scheduled_hook( 'opti_behavior_reclassify_spam_batch' );
wp_clear_scheduled_hook( 'opti_behavior_heavy_migrations' );
wp_clear_scheduled_hook( 'opti_behavior_dimension_backfill' );
wp_clear_scheduled_hook( 'opti_behavior_deep_integrity_check' );
wp_clear_scheduled_hook( 'opti_behavior_files_tier_continue' );
wp_clear_scheduled_hook( 'opti_behavior_recording_fallback_continue' );
wp_clear_scheduled_hook( 'opti_behavior_spam_tier_run' );
wp_clear_scheduled_hook( 'opti_behavior_engagement_counters_tick' );
wp_clear_scheduled_hook( 'opti_behavior_page_type_prune_run' );
wp_clear_scheduled_hook( 'opti_behavior_page_type_prune_restore' );
wp_clear_scheduled_hook( 'opti_behavior_page_type_prune_purge' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_migration_batch' );
wp_clear_scheduled_hook( 'opti_behavior_recording_orphan_sweep' );

// Drop all database tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_pages" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_sessions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_visitors" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_pageviews" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_recordings" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_session_pages" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_referrers" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_outbound_clicks" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_bot_visits" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_report_schedules" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_report_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_heatmap_pages" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_daily_stats" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_daily_dimension_stats" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_errors" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_error_types" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_friction" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_performance" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_broken_links" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_journey_groups" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}opti_behavior_funnels" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}opti_behavior_funnel_tracking" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_form_interactions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_form_submissions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_heatmap_daily" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_insights" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Drop A/B testing tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_ab_daily_stats" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_ab_conversions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_ab_impressions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_ab_goals" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_ab_variants" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_ab_tests" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_ab_decision_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_ab_targeting_rules" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optibehavior_ab_schedule" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete file storage directory (heatmaps, session recordings, events, etc.)
$opti_behavior_upload_dir = wp_upload_dir();
$opti_behavior_data_dir   = trailingslashit( $opti_behavior_upload_dir['basedir'] ) . 'opti-behavior-data/';
if ( file_exists( $opti_behavior_data_dir ) ) {
	opti_behavior_uninstall_delete_directory( $opti_behavior_data_dir );
}

// Delete the debug log folder (default uploads/opti-behavior-logs/; honours
// custom_log_path + log_folder). Only OUR files are removed and the folder is
// dropped only when that leaves it empty — a customer pointing log_folder at
// a shared directory must never lose foreign files.
$opti_behavior_log_base = ! empty( $opti_behavior_debug_settings['custom_log_path'] )
	? trailingslashit( (string) $opti_behavior_debug_settings['custom_log_path'] )
	: trailingslashit( $opti_behavior_upload_dir['basedir'] );
$opti_behavior_log_folder = isset( $opti_behavior_debug_settings['log_folder'] )
	? trim( (string) $opti_behavior_debug_settings['log_folder'], "/\\ \t" )
	: 'opti-behavior-logs';
if ( '' !== $opti_behavior_log_folder ) {
	$opti_behavior_log_dir = $opti_behavior_log_base . $opti_behavior_log_folder . '/';
	if ( is_dir( $opti_behavior_log_dir ) ) {
		foreach ( (array) glob( $opti_behavior_log_dir . 'opti-behavior-heatmap-debug*.log' ) as $opti_behavior_log_file ) {
			if ( is_file( $opti_behavior_log_file ) ) {
				wp_delete_file( $opti_behavior_log_file );
			}
		}
		foreach ( array( '.htaccess', 'index.php' ) as $opti_behavior_guard_file ) {
			if ( is_file( $opti_behavior_log_dir . $opti_behavior_guard_file ) ) {
				wp_delete_file( $opti_behavior_log_dir . $opti_behavior_guard_file );
			}
		}
		$opti_behavior_log_left = @scandir( $opti_behavior_log_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable dir simply stays.
		if ( is_array( $opti_behavior_log_left ) && count( $opti_behavior_log_left ) <= 2 ) {
			@rmdir( $opti_behavior_log_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty-dir removal; failure is non-fatal.
		}
	}
}

// Delete legacy recordings directory.
$opti_behavior_legacy_dir = WP_CONTENT_DIR . '/opti-behavior-recordings';
if ( file_exists( $opti_behavior_legacy_dir ) ) {
	opti_behavior_uninstall_delete_directory( $opti_behavior_legacy_dir );
}

// Clean up transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_opti_behavior_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_timeout_opti_behavior_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

/**
 * Recursively delete a directory and all its contents.
 *
 * @param string $dir Directory path to delete.
 * @return bool True on success, false on failure.
 */
function opti_behavior_uninstall_delete_directory( $dir ) {
	if ( ! file_exists( $dir ) ) {
		return true;
	}

	if ( ! is_dir( $dir ) ) {
		wp_delete_file( $dir );
		return ! file_exists( $dir );
	}

	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		if ( ! opti_behavior_uninstall_delete_directory( $dir . DIRECTORY_SEPARATOR . $item ) ) {
			return false;
		}
	}

	global $wp_filesystem;
	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();
	}
	return $wp_filesystem->rmdir( $dir );
}
