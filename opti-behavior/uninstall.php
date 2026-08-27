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

// Delete all plugin options.
delete_option( 'opti_behavior_heatmap_option' );
delete_option( 'opti_behavior_delete_on_uninstall' );
delete_option( 'opti_behavior_heatmap_debug_settings' );
delete_option( 'opti_behavior_heatmap_data_protection' );
delete_option( 'opti_behavior_heatmap_last_integrity_check' );
delete_option( 'opti_behavior_db_version' );
delete_option( 'opti_behavior_db_optimization_status' );
delete_option( 'opti_behavior_file_storage_settings' );
delete_option( 'opti_behavior_heatmap_migration_status' );
delete_option( 'opti_behavior_heatmap_migration_complete' );
delete_option( 'opti_behavior_hm_pages_deduped' );
delete_option( 'opti_behavior_master_secret' );
delete_option( 'opti_behavior_auto_cleanup_settings' );
delete_option( 'opti_behavior_cleanup_logs' );
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

// Transients.
delete_transient( 'opti_behavior_trial_failed' );
delete_transient( 'opti_behavior_pro_download_url' );

// Clear all scheduled cron jobs.
wp_clear_scheduled_hook( 'opti_behavior_heatmap_cron_daily' );
wp_clear_scheduled_hook( 'opti_behavior_aggregate_daily_stats' );
wp_clear_scheduled_hook( 'opti_behavior_send_scheduled_reports' );
wp_clear_scheduled_hook( 'opti_behavior_scheduled_smart_cleanup' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_auto_repair' );
wp_clear_scheduled_hook( 'opti_behavior_debug_auto_disable' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_registry_rebuild_files' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_orphan_report_scan' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_orphan_purge_daily' );
wp_clear_scheduled_hook( 'opti_behavior_heatmap_migration_batch' );
wp_clear_scheduled_hook( 'opti_behavior_daily_heartbeat' );
wp_clear_scheduled_hook( 'opti_behavior_ab_aggregate_daily' );
wp_clear_scheduled_hook( 'opti_behavior_ab_auto_winner_check' );
wp_clear_scheduled_hook( 'opti_behavior_ab_cleanup' );
wp_clear_scheduled_hook( 'opti_behavior_dimension_backfill' );

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
