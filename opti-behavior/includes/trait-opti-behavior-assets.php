<?php
/**
 * Assets Management Trait
 *
 * Handles asset enqueuing and script/style management.
 *
 * @package Opti_Behavior
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assets Trait
 *
 * Provides asset management functionality.
 *
 * @since 1.0.0
 */
trait Opti_Behavior_Assets_Trait {
	/**
	 * Enqueue dashboard assets.
	 *
	 * @since 1.0.0
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	private function enqueue_dashboard_assets_impl( $hook_suffix ) {
        // Normalise $hook_suffix on edge-case admin page loads where WordPress may pass null.
        // PHP 8.2 deprecates strpos(null, ...), so cast missing values to an empty string.
        $hook_suffix = $hook_suffix ?? '';

        // Enqueue admin menu styles globally on all admin pages (includes menu icon sizing)
        wp_enqueue_style(
            'opti-behavior-admin-menu',
            OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/admin-menu.css',
            array(),
            OPTI_BEHAVIOR_HEATMAP_VERSION // Plugin-owned asset: bust on every plugin update.
        );

        // Lightweight Smart Insights global notifications are allowed on non-Opti-Behavior admin pages.
        if ( $this->should_load_smart_insights_notifications_impl( $hook_suffix ) ) {
            wp_enqueue_style(
                'opti-behavior-smart-insights-notifications',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/smart-insights-notifications.css',
                array(),
                OPTI_BEHAVIOR_HEATMAP_VERSION . '-smart-insights-notifications-v10'
            );

            wp_enqueue_script(
                'opti-behavior-smart-insights-notifications',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/smart-insights-notifications.js',
                array(),
                OPTI_BEHAVIOR_HEATMAP_VERSION . '-smart-insights-notifications-v10',
                true
            );

            wp_localize_script(
                'opti-behavior-smart-insights-notifications',
                'optiBehaviorSmartInsightNotifications',
                array(
                    'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                    'nonce'       => wp_create_nonce( 'opti_behavior_smart_insights_notifications' ),
                    'payloadAction' => 'optibehavior_smart_insights_notifications',
                    'stateAction' => 'optibehavior_smart_insights_notification_state',
                    'requestContext' => $this->get_smart_insights_notification_request_context_impl( $hook_suffix ),
                    'i18n'        => array(
                        'label'          => __( 'Smart Insights', 'opti-behavior' ),
                        'shortLabel'     => __( 'Insights', 'opti-behavior' ),
                        'subtitle'       => __( 'New behavior signals', 'opti-behavior' ),
                        'allCaughtUp'    => __( 'All caught up', 'opti-behavior' ),
                        'unread'         => __( 'unread', 'opti-behavior' ),
                        'open'           => __( 'Open Smart Insights', 'opti-behavior' ),
                        'openInsight'    => __( 'Open insight', 'opti-behavior' ),
                        'openCenter'     => __( 'Open Smart Insights center', 'opti-behavior' ),
                        'markSeen'       => __( 'Mark all as seen', 'opti-behavior' ),
                        'settings'       => __( 'Settings', 'opti-behavior' ),
                        'minimize'       => __( 'Minimize', 'opti-behavior' ),
                        'compact'        => __( 'Hide to compact side point', 'opti-behavior' ),
                        'compactLauncherAria' => __( 'Collapse Smart Insights to the compact side point', 'opti-behavior' ),
                        'compactLauncherTitle' => __( 'Collapse to side point', 'opti-behavior' ),
                        'launcherGroup'  => __( 'Smart Insights notification launcher', 'opti-behavior' ),
                        'restore'        => __( 'Restore Smart Insights', 'opti-behavior' ),
                        'restoreAria'    => __( 'Restore Smart Insights notifications from compact side mode', 'opti-behavior' ),
                        'hide'           => __( 'Hide to side point', 'opti-behavior' ),
                        'hideToSide'     => __( 'Hide to compact side point', 'opti-behavior' ),
                        'close'          => __( 'Close notification panel', 'opti-behavior' ),
                        'badgeOverflow'  => __( '99 or more unread Smart Insights', 'opti-behavior' ),
                        'emptyTitle'     => __( 'No new Smart Insights signals right now.', 'opti-behavior' ),
                        'emptyBody'      => __( 'Open the center any time to review the full behavior workbench.', 'opti-behavior' ),
                        'loading'        => __( 'Checking Smart Insights...', 'opti-behavior' ),
                        'loadingTitle'   => __( 'Checking Smart Insights...', 'opti-behavior' ),
                        'loadingBody'    => __( 'Your notification list is loading. You can still collapse or restore the launcher now.', 'opti-behavior' ),
                        'error'          => __( 'Unable to load Smart Insights notifications.', 'opti-behavior' ),
                        'priority'       => __( 'Priority', 'opti-behavior' ),
                        'confidence'     => __( 'Confidence', 'opti-behavior' ),
                        'status'         => __( 'Status', 'opti-behavior' ),
                        'statusNew'      => __( 'New', 'opti-behavior' ),
                        'low'            => __( 'Low', 'opti-behavior' ),
                        'proLocked'      => __( 'Pro preview', 'opti-behavior' ),
                        'detected'       => __( 'Detected', 'opti-behavior' ),
                    ),
                )
            );
        }

        // Only load plugin-specific assets on plugin pages
        if ( strpos( $hook_suffix, 'opti-behavior-' ) === false ) {
            return;
        }

        // Enqueue Lucide Icons (bundled locally, MIT licensed)
        wp_enqueue_script( 'lucide-icons', OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/lucide.min.js', array(), OPTI_BEHAVIOR_HEATMAP_VERSION, true );

        // Enqueue Chart.js (bundled locally, MIT licensed) - Updated to v4.5.0
        wp_enqueue_script( 'chart-js', OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/chart.umd.min.js', array(), OPTI_BEHAVIOR_HEATMAP_VERSION, true );

        // Load Leaflet early (in head) so the map shows immediately (bundled locally, BSD-2-Clause licensed)
        wp_enqueue_style( 'leaflet', OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/leaflet.min.css', array(), OPTI_BEHAVIOR_HEATMAP_VERSION );
        wp_enqueue_script( 'leaflet', OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/leaflet.min.js', array(), OPTI_BEHAVIOR_HEATMAP_VERSION, false );

        // Enqueue Leaflet configuration script (moved to external file: assets/js/leaflet-config.js)
        wp_enqueue_script(
            'opti-behavior-leaflet-config',
            OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/leaflet-config.js',
            array( 'leaflet' ),
            OPTI_BEHAVIOR_HEATMAP_VERSION,
            false // Load in head after Leaflet
        );

        // Pass Leaflet image path to JavaScript
        wp_localize_script(
            'opti-behavior-leaflet-config',
            'opti_behaviorLeafletConfig',
            array(
                'imagePath' => OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'images/leaflet/',
            )
        );

        // Enqueue dashboard styles
        wp_enqueue_style(
            'opti-behavior-dashboard',
            OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/dashboard.css',
            array(),
            OPTI_BEHAVIOR_HEATMAP_VERSION // Plugin version bump busts asset caches on update.
        );

        // Enqueue main plugin styles (includes toggle switches, etc.)
        wp_enqueue_style(
            'opti-behavior-style',
            OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/style.css',
            array(),
            OPTI_BEHAVIOR_HEATMAP_VERSION // Version bump to force cache refresh
        );

        // Enqueue admin utility styles (loading states, forms, etc.)
        wp_enqueue_style(
            'opti-behavior-admin-utilities',
            OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/admin-utilities.css',
            array(),
            OPTI_BEHAVIOR_HEATMAP_VERSION
        );

        // Enqueue tooltip styles (user-friendly help tooltips across all pages)
        wp_enqueue_style(
            'opti-behavior-tooltips',
            OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/tooltips.css',
            array(),
            OPTI_BEHAVIOR_HEATMAP_VERSION
        );

        // Override CSS tooltip labels with translated text
        $simple_label = esc_html__( 'In simple terms:', 'opti-behavior' ) . ' ';
        $example_label = esc_html__( 'Example:', 'opti-behavior' ) . ' ';
        $tooltip_i18n_css = '.ob-tooltip-simple::before { content: "' . $simple_label . '"; }';
        $tooltip_i18n_css .= ' .ob-tooltip-example::before { content: "' . $example_label . '"; }';
        wp_add_inline_style( 'opti-behavior-tooltips', $tooltip_i18n_css );

        // Enqueue tooltip smart positioning script
        wp_enqueue_script(
            'opti-behavior-tooltips',
            OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/tooltips.js',
            array(),
            OPTI_BEHAVIOR_HEATMAP_VERSION,
            true
        );

        // Enqueue admin utility scripts (confirmation dialogs, etc.)
        wp_enqueue_script(
            'opti-behavior-admin-utilities',
            OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/admin-utilities.js',
            array(),
            OPTI_BEHAVIOR_HEATMAP_VERSION,
            true
        );

        // Initialize Lucide Icons after page load
        wp_add_inline_script(
            'lucide-icons',
            'document.addEventListener("DOMContentLoaded", function() {
                if (typeof lucide !== "undefined") {
                    lucide.createIcons();
                }
            });'
        );

        // Enqueue Pro Trial Banner assets (shown when Pro is not active)
        $opti_behavior_trial_state = $this->get_trial_banner_state();
        if ( 'hidden' !== $opti_behavior_trial_state['display'] ) {
            wp_enqueue_style(
                'opti-behavior-pro-trial-banner',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/pro-trial-banner.css',
                array(),
                OPTI_BEHAVIOR_HEATMAP_VERSION
            );

            wp_enqueue_script(
                'opti-behavior-pro-trial-banner',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/pro-trial-banner.js',
                array(),
                OPTI_BEHAVIOR_HEATMAP_VERSION,
                true
            );

            wp_localize_script(
                'opti-behavior-pro-trial-banner',
                'optiBehaviorTrial',
                array(
                    'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
                    'startNonce'            => wp_create_nonce( 'opti_behavior_start_trial' ),
                    'dismissNonce'          => wp_create_nonce( 'opti_behavior_dismiss_trial' ),
                    'trialState'            => $opti_behavior_trial_state['display'],
                    'trialDaysLeft'         => $opti_behavior_trial_state['days_left'],
                    'trialExpiresTimestamp' => $opti_behavior_trial_state['expires_timestamp'],
                    'downloadUrl'           => $opti_behavior_trial_state['download_url'],
                    'i18n'                  => array(
                        'trialActivated' => __( 'Pro Trial Activated!', 'opti-behavior' ),
                        'trialActiveDesc' => __( 'You have 6 months of free access to all Pro features. Download the Pro plugin to get started!', 'opti-behavior' ),
                        'daysLeft'       => __( 'days left', 'opti-behavior' ),
                        'downloadPro'    => __( 'Download Pro Plugin', 'opti-behavior' ),
                        'dismiss'        => __( 'Dismiss', 'opti-behavior' ),
                        'trialExpired'   => __( 'Trial expired', 'opti-behavior' ),
                        'unknownError'   => __( 'Unknown error', 'opti-behavior' ),
                        'requestFailed'  => __( 'Request failed', 'opti-behavior' ),
                        'invalidResponse' => __( 'Invalid response', 'opti-behavior' ),
                        'networkError'   => __( 'Network error', 'opti-behavior' ),
                        'pleaseTryAgain' => __( 'Please try again.', 'opti-behavior' ),
                    ),
                )
            );
        }

        // Add modern sessions styles only for sessions page
        if ( strpos( $hook_suffix, 'opti-behavior-sessions' ) !== false ) {
            wp_enqueue_style(
                'opti-behavior-sessions',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/sessions.css',
                array( 'opti-behavior-dashboard' ),
                OPTI_BEHAVIOR_HEATMAP_VERSION
            );
        }

        // Add modern heatmaps styles for heatmaps page
        if ( strpos( $hook_suffix, 'opti-behavior-heatmaps' ) !== false ) {
            wp_enqueue_style(
                'opti-behavior-heatmaps',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/heatmaps.css',
                array( 'opti-behavior-dashboard' ),
                OPTI_BEHAVIOR_HEATMAP_VERSION
            );
            // Enqueue mobile simulator script from external file
            wp_enqueue_script(
                'opti-behavior-mobile-simulator',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/mobile-simulator.js',
                array(), // No dependencies
                OPTI_BEHAVIOR_HEATMAP_VERSION, // Version bump to force cache refresh for mobile parameter stripping fix
                true
            );
            // Pass translated strings to the mobile simulator script.
            wp_localize_script(
                'opti-behavior-mobile-simulator',
                'optiBehaviorMobileSimulator',
                array(
                    'strings' => array(
                        'mobileDeviceSimulator' => __( 'Mobile Device Simulator', 'opti-behavior' ),
                        'close'                 => __( 'Close', 'opti-behavior' ),
                        'mobileHeatmap'         => __( 'Mobile Heatmap', 'opti-behavior' ),
                    ),
                )
            );
            // Enqueue heatmaps page scripts from external file
            wp_enqueue_script(
                'opti-behavior-heatmaps',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/heatmaps.js',
                array(), // No dependencies
                OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . time(),
                true
            );
            wp_localize_script(
                'opti-behavior-heatmaps',
                'optiBehaviorHeatmapsL10n',
                array(
                    'strings' => array(
                        'desyncTooltip' => __( 'Interaction files and detected sessions are out of sync for this page. Click Repair to refresh.', 'opti-behavior' ),
                        'repair'        => __( 'Repair', 'opti-behavior' ),
                    ),
                )
            );
        }

        // Add Roadmap styles for the legacy AI Insights page slug.
        $is_roadmap_screen = strpos( $hook_suffix, 'opti-behavior-ai-insights' ) !== false;
        if ( $is_roadmap_screen ) {
            wp_enqueue_style(
                'opti-behavior-ai-insights',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/ai-insights-page.css',
                array( 'opti-behavior-dashboard-styles' ),
                OPTI_BEHAVIOR_HEATMAP_VERSION . '-roadmap-shared-header-v2'
            );
        }

        // Add recordings upgrade styles for free Session Recordings page
        if ( strpos( $hook_suffix, 'opti-behavior-recordings' ) !== false && ! opti_behavior_pro_active() ) {
            wp_enqueue_style(
                'opti-behavior-recordings-upgrade',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/recordings-upgrade.css',
                array( 'opti-behavior-dashboard' ),
                OPTI_BEHAVIOR_HEATMAP_VERSION
            );
        }

        // Add user journey upgrade styles for free User Journeys page
        if ( strpos( $hook_suffix, 'opti-behavior-user-journey' ) !== false && ! opti_behavior_pro_active() ) {
            wp_enqueue_style(
                'opti-behavior-user-journey-upgrade',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/recordings-upgrade.css',
                array( 'opti-behavior-dashboard' ),
                OPTI_BEHAVIOR_HEATMAP_VERSION
            );
        }

        // Add errors upgrade styles for free Errors Tracking page (uses recordings-upgrade.css for consistent header styling)
        if ( strpos( $hook_suffix, 'opti-behavior-errors' ) !== false && ! opti_behavior_pro_active() ) {
            wp_enqueue_style(
                'opti-behavior-errors-upgrade',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/recordings-upgrade.css',
                array( 'opti-behavior-dashboard' ),
                OPTI_BEHAVIOR_HEATMAP_VERSION
            );
        }

        // Add form analytics upgrade styles for free Form Analytics page
        if ( strpos( $hook_suffix, 'opti-behavior-form-analytics' ) !== false && ! opti_behavior_pro_active() ) {
            wp_enqueue_style(
                'opti-behavior-form-analytics-upgrade',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/recordings-upgrade.css',
                array( 'opti-behavior-dashboard' ),
                OPTI_BEHAVIOR_HEATMAP_VERSION
            );
        }

        // Add settings page styles for settings page
        if ( strpos( $hook_suffix, 'opti-behavior-settings' ) !== false ) {
            // Enqueue settings modal styles
            wp_enqueue_style(
                'opti-behavior-settings-modal',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/settings-modal.css',
                array(),
                OPTI_BEHAVIOR_HEATMAP_VERSION
            );

            wp_enqueue_style(
                'opti-behavior-settings',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/settings.css',
                array( 'opti-behavior-dashboard' ),
                file_exists( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/settings.css' )
                    ? (string) filemtime( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/settings.css' )
                    : OPTI_BEHAVIOR_HEATMAP_VERSION . '-smart-insights-settings-v2'
            );
            // Enqueue settings page scripts from external file
            wp_enqueue_script(
                'opti-behavior-settings-js',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/settings.js',
                array( 'jquery' ), // Depends on jQuery
                file_exists( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/js/settings.js' )
                    ? (string) filemtime( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/js/settings.js' )
                    : OPTI_BEHAVIOR_HEATMAP_VERSION . '-smart-insights-settings-v3',
                true
            );
            // Pass nonces to settings script
            wp_localize_script(
                'opti-behavior-settings-js',
                'opti_behaviorSettings',
                array(
                    'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
                    'viewLogNonce'       => wp_create_nonce( 'opti_behavior_view_log' ),
                    'cleanupNonce'       => wp_create_nonce( 'opti_behavior_cleanup' ),
                    'settingsNonce'      => wp_create_nonce( 'opti_behavior_settings_nonce' ),
                    'deleteAllNonce'     => wp_create_nonce( 'opti_behavior_delete_all_ajax' ),
                    'deleteRangeNonce'   => wp_create_nonce( 'opti_behavior_delete_range_ajax' ),
                    'smartCleanupNonce'  => wp_create_nonce( 'opti_behavior_smart_cleanup' ),
                    'dangerSizesNonce'   => wp_create_nonce( 'opti_behavior_danger_sizes' ),
                    'storageStatsNonce'  => wp_create_nonce( 'opti_behavior_storage_stats' ),
                    'dashboardNonce'     => wp_create_nonce( 'opti_behavior_dashboard_nonce' ),
                    'strings'            => array(
                        'sizeDbLabel'       => __( 'DB', 'opti-behavior' ),
                        'sizeFilesLabel'    => __( 'Files', 'opti-behavior' ),
                        'sizeTotalLabel'    => __( 'Total storage:', 'opti-behavior' ),
                        'repairing'         => __( 'Repairing…', 'opti-behavior' ),
                        /* translators: %d: number of pages checked */
                        'repairComplete'    => __( 'Heatmap sync repaired for %d page(s).', 'opti-behavior' ),
                        'repairHasMore'     => __( 'More pages remain; run again to continue.', 'opti-behavior' ),
                        'repairError'       => __( 'Error repairing heatmap sync. Please try again.', 'opti-behavior' ),
                        /* translators: 1: folders scanned, 2: total folders */
                        'rebuildScanning'      => __( 'Scanning %1$d of %2$d folders…', 'opti-behavior' ),
                        'rebuildPaused'        => __( 'Paused — progress is saved.', 'opti-behavior' ),
                        'rebuildAborted'       => __( 'Aborted.', 'opti-behavior' ),
                        'rebuildComplete'      => __( 'Rebuild complete.', 'opti-behavior' ),
                        'rebuildError'         => __( 'Rebuild request failed. Please try again.', 'opti-behavior' ),
                        /* translators: 1: pages inserted, 2: spam-excluded, 3: bot-only skipped, 4: already registered, 5: no valid data */
                        'rebuildSummary'       => __( 'Pages inserted: %1$d · spam-excluded: %2$d · bot-only skipped: %3$d · already registered: %4$d · no valid data: %5$d', 'opti-behavior' ),
                        'rebuildConfirmStart'  => __( 'Start rebuilding the heatmap registry from files? This runs in the background and never deletes any data.', 'opti-behavior' ),
                        'rebuildConfirmAbort'  => __( 'Abort the rebuild? Progress made so far is kept; a new start rescans from the beginning.', 'opti-behavior' ),
                        /* translators: 1: archived folders scanned, 2: total archived folders */
                        'orphanScanning'       => __( 'Scanning %1$d of %2$d archived folders…', 'opti-behavior' ),
                        'orphanPaused'         => __( 'Paused — progress is saved.', 'opti-behavior' ),
                        'orphanAborted'        => __( 'Aborted.', 'opti-behavior' ),
                        'orphanComplete'       => __( 'Archive scan complete. Nothing was restored or modified.', 'opti-behavior' ),
                        'orphanError'          => __( 'Archive scan request failed. Please try again.', 'opti-behavior' ),
                        /* translators: 1: eligible folders, 2: still-live skipped, 3: unreadable, 4: total sessions, 5: unknown-session percentage */
                        'orphanSummary'        => __( 'Eligible for restore: %1$d folders · still live (skipped): %2$d · unreadable: %3$d · sessions: %4$d (%5$d%% unknown to the database)', 'opti-behavior' ),
                        'orphanSampleTitle'    => __( 'Sample of restorable pages:', 'opti-behavior' ),
                        /* translators: 1: page URL, 2: session count, 3: unknown-session count */
                        'orphanSampleItem'     => __( '%1$s — %2$d session(s), %3$d unknown', 'opti-behavior' ),
                        'orphanConfirmStart'   => __( 'Scan the archived heatmap data? This is a report only — it runs in the background and never restores, moves, or deletes anything.', 'opti-behavior' ),
                        'orphanConfirmAbort'   => __( 'Abort the archive scan? The partial report is kept; a new scan starts from the beginning.', 'opti-behavior' ),
                        'orphanPurgeSaved'     => __( 'Archive retention setting saved.', 'opti-behavior' ),
                        'orphanPurgeError'     => __( 'Could not save the archive retention setting. Please try again.', 'opti-behavior' ),
                        /* translators: 1: archived folders processed, 2: total archived folders */
                        'orphanRestoreRunning' => __( 'Restoring %1$d of %2$d archived folders…', 'opti-behavior' ),
                        'orphanRestoreComplete' => __( 'Restore complete. Heatmap registry rebuild has been queued in the background.', 'opti-behavior' ),
                        'orphanRestoreError'   => __( 'Restore request failed. Progress is saved — press Start again to resume.', 'opti-behavior' ),
                        'orphanRestoreWaiting' => __( 'Another maintenance task is running — retrying shortly…', 'opti-behavior' ),
                        'orphanRestoreConfirm' => __( 'Restore all archived heatmap data back into the live data folder? Live files are never overwritten, and the registry rebuild runs automatically afterwards.', 'opti-behavior' ),
                        /* translators: 1: folders restored, 2: folders merged, 3: files moved, 4: files kept live, 5: failed folders */
                        'orphanRestoreSummary' => __( 'Folders restored: %1$d · merged into live data: %2$d · files moved: %3$d · files kept live (skipped): %4$d · failed: %5$d', 'opti-behavior' ),
                        /* translators: 1: sessions processed so far, 2: total flagged sessions */
                        'reevalSpamProcessing' => __( 'Re-evaluating %1$d of %2$d flagged sessions…', 'opti-behavior' ),
                        'reevalSpamComplete'   => __( 'Spam re-evaluation complete.', 'opti-behavior' ),
                        /* translators: 1: sessions re-checked, 2: sessions restored to human */
                        'reevalSpamSummary'    => __( 'Sessions re-checked: %1$d · restored to human: %2$d', 'opti-behavior' ),
                        'reevalSpamError'      => __( 'Re-evaluation request failed. Please try again — completed batches are already saved.', 'opti-behavior' ),
                        'reevalSpamConfirm'    => __( 'Re-check sessions flagged as spam for "few clicks" against the current thresholds? Sessions that now qualify as human are restored; nothing is deleted.', 'opti-behavior' ),
                        'autoRepairSaved'      => __( 'Auto-repair setting saved.', 'opti-behavior' ),
                        'dataRetentionSaved'   => __( 'Data retention setting saved.', 'opti-behavior' ),
                        'dataRetentionError'   => __( 'Could not save the data retention setting. Please try again.', 'opti-behavior' ),
                        'filesRetentionClamped' => __( 'Heavy-files retention cannot exceed the detailed-data window — value adjusted.', 'opti-behavior' ),
                        'aggregatesForever'    => __( 'Kept forever', 'opti-behavior' ),
                        /* translators: %d: number of months */
                        'aggregatesMonths'     => __( '%d months', 'opti-behavior' ),
                        /* translators: 1: older-than rule days, 2: detailed-data retention window days */
                        'olderThanRedundant'   => __( 'Note: the "Sessions older than %1$s days" rule overlaps the Data Retention detailed-data window (%2$s days), which already runs automatically every day — consider removing this rule and relying on the Data Retention tier instead.', 'opti-behavior' ),
                        'recalculating'     => __( 'Recalculating spam flags...', 'opti-behavior' ),
                        /* translators: %1$d: current session number, %2$d: total sessions */
                        'processing'        => __( 'Processing %1$d of %2$d sessions...', 'opti-behavior' ),
                        'completed'         => __( 'Spam recalculation completed successfully!', 'opti-behavior' ),
                        'error'             => __( 'Error during recalculation. Please try again.', 'opti-behavior' ),
                        'deleteError'       => __( 'Error during deletion. Please try again.', 'opti-behavior' ),
                        'deleteComplete'    => __( 'All analytics data has been deleted!', 'opti-behavior' ),
                        'invalidDateRange'  => __( 'Please select a valid date range.', 'opti-behavior' ),
                        'noConditions'      => __( 'Please select at least one condition.', 'opti-behavior' ),
                        'previewing'        => __( 'Checking...', 'opti-behavior' ),
                        'preview'           => __( 'Preview', 'opti-behavior' ),
                        'saving'            => __( 'Saving...', 'opti-behavior' ),
                        'saveSchedule'             => __( 'Save Schedule', 'opti-behavior' ),
                        'saveConditions'           => __( 'Save Conditions', 'opti-behavior' ),
                        'previewRequired'          => __( 'Please run a valid preview before deleting.', 'opti-behavior' ),
                        'loadingDebugLog'          => __( 'Loading debug log...', 'opti-behavior' ),
                        'logFileEmpty'             => __( 'Log file is empty', 'opti-behavior' ),
                        /* translators: %s: error detail */
                        'errorLoadingLog'          => __( 'Error loading log: %s', 'opti-behavior' ),
                        'selectDate'               => __( 'Please select a date', 'opti-behavior' ),
                        /* translators: %s: date string */
                        'confirmDeleteBefore'      => __( 'Are you sure you want to delete all recordings before %s?', 'opti-behavior' ),
                        'deleting'                 => __( 'Deleting...', 'opti-behavior' ),
                        'recordingsDeleted'        => __( 'Recordings deleted successfully', 'opti-behavior' ),
                        'errorColon'               => __( 'Error:', 'opti-behavior' ),
                        'deleteOldRecordings'      => __( 'Delete Old Recordings', 'opti-behavior' ),
                        'errorDeletingRecordings'  => __( 'Error deleting recordings', 'opti-behavior' ),
                        'enterValidDuration'       => __( 'Please enter a valid duration', 'opti-behavior' ),
                        /* translators: %s: duration in seconds */
                        'confirmDeleteShorterThan' => __( 'Are you sure you want to delete all recordings shorter than %s seconds?', 'opti-behavior' ),
                        'recordingsShortDeleted'   => __( 'Short recordings deleted successfully', 'opti-behavior' ),
                        'deleteShortRecordings'    => __( 'Delete Short Recordings', 'opti-behavior' ),
                        'confirmDeleteOrphaned'    => __( 'Are you sure you want to delete all orphaned recording files? This cannot be undone.', 'opti-behavior' ),
                        'orphanedFilesDeleted'     => __( 'Orphaned files deleted successfully', 'opti-behavior' ),
                        'errorDeletingOrphaned'    => __( 'Error deleting orphaned files', 'opti-behavior' ),
                        'deleteOrphanedFiles'      => __( 'Delete Orphaned Files', 'opti-behavior' ),
                        'saveTrafficSettings'      => __( 'Save Traffic Settings', 'opti-behavior' ),
                        'deselectAll'              => __( 'Deselect All', 'opti-behavior' ),
                        'selectAll'                => __( 'Select All', 'opti-behavior' ),
                        'deleteAllData'            => __( 'Delete All Analytics Data', 'opti-behavior' ),
                        'deleteSelectedData'       => __( 'Delete Selected Data', 'opti-behavior' ),
                        'warningDeleteAll'         => __( 'This will permanently delete ALL analytics data from your database. This action cannot be undone.', 'opti-behavior' ),
                        'warningDeleteSelected'    => __( 'This will permanently delete the selected analytics data from your database. This action cannot be undone.', 'opti-behavior' ),
                        'confirmDeleteAll'         => __( 'Yes, Delete All Data', 'opti-behavior' ),
                        'confirmDeleteSelected'    => __( 'Yes, Delete Selected Data', 'opti-behavior' ),
                        'initializing'             => __( 'Initializing...', 'opti-behavior' ),
                        'unknownError'             => __( 'Unknown error', 'opti-behavior' ),
                        'complete'                 => __( 'Complete!', 'opti-behavior' ),
                        'noTableRows'              => __( 'No table rows are currently matched.', 'opti-behavior' ),
                        'tableHeader'              => __( 'Table', 'opti-behavior' ),
                        'rowsHeader'               => __( 'Rows', 'opti-behavior' ),
                        'noneCurrentlyFlagged'     => __( 'None currently flagged', 'opti-behavior' ),
                        /* translators: %d: number of optimization candidate tables */
                        'affectedTables'           => __( '%d affected table(s)', 'opti-behavior' ),
                        'previewImpact'            => __( 'Preview impact', 'opti-behavior' ),
                        'sessions'                 => __( 'Sessions:', 'opti-behavior' ),
                        'recordingFiles'           => __( 'Recording files:', 'opti-behavior' ),
                        'orphanVisitorEstimate'    => __( 'Orphan visitor estimate:', 'opti-behavior' ),
                        'approxAffectedBytes'      => __( 'Approx. affected bytes:', 'opti-behavior' ),
                        'optimizationCandidates'   => __( 'Optimization candidates:', 'opti-behavior' ),
                        'conditionMatches'         => __( 'Matches per condition (OR rules)', 'opti-behavior' ),
                        'conditionHeader'          => __( 'Condition', 'opti-behavior' ),
                        'sessionsHeader'           => __( 'Sessions', 'opti-behavior' ),
                        'conditionMatchesNote'     => __( 'Each condition is an independent OR rule; a session matching several conditions is counted in each row, so the sum can exceed the total.', 'opti-behavior' ),
                        'trafficBreakdown'         => __( 'Traffic breakdown', 'opti-behavior' ),
                        'rowsByTable'              => __( 'Rows by table', 'opti-behavior' ),
                        'noMatchingTraffic'        => __( 'No matching traffic types.', 'opti-behavior' ),
                        'filesDeleted'             => __( 'Files deleted:', 'opti-behavior' ),
                        'orphanVisitorsDeleted'    => __( 'Orphan visitors deleted:', 'opti-behavior' ),
                        'optimizedTables'          => __( 'Optimized tables:', 'opti-behavior' ),
                        'rowsDeletedByTable'       => __( 'Rows deleted by table', 'opti-behavior' ),
                        'conditionsSaved'          => __( 'Conditions saved successfully.', 'opti-behavior' ),
                        'errorSavingConditions'    => __( 'Error saving conditions', 'opti-behavior' ),
                        'ajaxError'                => __( 'AJAX error', 'opti-behavior' ),
                        'previewDetailsHidden'     => __( 'Preview impact details could not be displayed, so deletion remains disabled.', 'opti-behavior' ),
                        'errorGeneric'             => __( 'Error', 'opti-behavior' ),
                        /* translators: %d: number of sessions deleted */
                        'sessionsDeleted'          => __( '%d sessions deleted successfully.', 'opti-behavior' ),
                        /* translators: %d: number of bot/spam sessions cleaned */
                        'botSessionsCleaned'       => __( '%d bot/spam sessions cleaned successfully.', 'opti-behavior' ),
                        'errorSavingSettings'      => __( 'Error saving settings', 'opti-behavior' ),
                        'storageLoadFailed'        => __( 'Could not load storage data.', 'opti-behavior' ),
                        'storageRetry'             => __( 'Retry', 'opti-behavior' ),
                        'storageApprox'            => __( '≈', 'opti-behavior' ),
                        'storageCountsFailed'      => __( 'Exact row counts could not be loaded — showing estimates (≈).', 'opti-behavior' ),
                        'storageNoTables'          => __( 'No database tables found.', 'opti-behavior' ),
                        'storageStatusActive'      => __( 'Active', 'opti-behavior' ),
                        'storageStatusEmpty'       => __( 'Empty', 'opti-behavior' ),
                    ),
                )
            );
        }

        // Localize data for dashboard scripts (attach to chart-js handle so it's always available)
        wp_localize_script(
            'chart-js',
            'opti_behaviorData',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'opti_behavior_dashboard_nonce' ),
                'strings'   => method_exists($this,'get_localized_strings') ? $this->get_localized_strings() : array(),
                'settings'  => method_exists($this,'get_dashboard_settings') ? $this->get_dashboard_settings() : array(),
            )
        );

        $is_smart_insights_screen = strpos( $hook_suffix, 'opti-behavior-smart-insights' ) !== false;
        $loads_smart_insights_ui  = $is_smart_insights_screen;
        if ( $loads_smart_insights_ui ) {
			/*
			 * Country flags in the "Where is the problem?" segment cards are drawn
			 * from Unicode regional-indicator pairs in smart-insights.js, NOT from
			 * assets/css/flag-icons.min.css.
			 *
			 * That stylesheet is only nominally "bundled": every one of its ~260
			 * flags resolves through `url(https://cdnjs.cloudflare.com/...)`, so
			 * enqueueing it would make an admin screen fetch third-party assets on
			 * render — broken on offline/air-gapped installs and a privacy leak of
			 * which country a site's audience comes from. The emoji path costs no
			 * request, no bundled asset, and degrades to a readable "FR" letter pair
			 * on platforms that ship no flag glyphs (Windows).
			 */
			wp_enqueue_script(
				'opti-behavior-smart-insights',
				OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/smart-insights.js',
				array(),
				OPTI_BEHAVIOR_HEATMAP_VERSION . '-smart-insights-segment-intel-v3-2',
				true
			);

            $smart_insights_has_pro_access = false;
            if ( class_exists( 'Opti_Behavior_Smart_Insights_Capabilities' ) ) {
                $smart_insights_capabilities = new Opti_Behavior_Smart_Insights_Capabilities();
                if ( is_callable( array( $smart_insights_capabilities, 'has_pro_access' ) ) ) {
                    $smart_insights_has_pro_access = (bool) $smart_insights_capabilities->has_pro_access();
                }
            } elseif ( function_exists( 'opti_behavior_pro_active' ) ) {
                $smart_insights_has_pro_access = (bool) opti_behavior_pro_active();
            }

            $smart_insights_exclude_spam = method_exists( $this, 'get_default_spam_exclusion_enabled' ) ? $this->get_default_spam_exclusion_enabled() : true;
            if ( isset( $_GET['exclude_spam'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Smart Insights filter state.
                $smart_insights_exclude_spam = '1' === (string) sanitize_text_field( wp_unslash( $_GET['exclude_spam'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Smart Insights filter state.
            }

            wp_localize_script(
                'opti-behavior-smart-insights',
                'optiBehaviorSmartInsights',
                array(
                    'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                    'nonce'        => wp_create_nonce( 'opti_behavior_dashboard_nonce' ),
                    'hasProAccess' => $smart_insights_has_pro_access,
                    'upgradeUrl'   => $this->get_pro_download_url_for_banner(),
                    'deepOpenInsightId' => isset( $_GET['insight_id'] ) ? absint( wp_unslash( $_GET['insight_id'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only deep-link state.
                    'excludeSpamDefault' => $smart_insights_exclude_spam ? '1' : '0',
                    'siteNow'      => current_time( 'mysql' ),
                    /**
                     * Filters how many Smart Insights cards stay visible before the "show more" fold.
                     *
                     * @param int $limit Number of top-level cards rendered outside the fold.
                     */
                    'visibleLimit' => (int) apply_filters( 'opti_behavior_smart_insights_visible_limit', 8 ),
                    /*
                     * Help tooltips for JS-rendered content. The list cards and the
                     * detail modal are built client-side, so the copy cannot come
                     * from opti_behavior_tooltip_e() the way the page header does;
                     * it is handed to the script instead and re-emitted with the
                     * exact same .ob-tooltip markup, which assets/js/tooltips.js
                     * binds automatically through its MutationObserver.
                     */
                    'tooltips'     => array(
                        'signals'  => function_exists( 'opti_behavior_get_smart_insights_signal_tooltips' ) ? opti_behavior_get_smart_insights_signal_tooltips() : array(),
                        'sections' => function_exists( 'opti_behavior_get_smart_insights_section_tooltips' ) ? opti_behavior_get_smart_insights_section_tooltips() : array(),
                    ),
                    'i18n'         => array(
                        'loading'            => __( 'Loading Smart Insights...', 'opti-behavior' ),
                        'refreshing'         => __( 'Refreshing Smart Insights...', 'opti-behavior' ),
                        'noInsights'         => __( 'No Smart Insights found for this period.', 'opti-behavior' ),
                        'lowData'            => __( 'Smart Insights appear once enough behavior data is available.', 'opti-behavior' ),
                        'viewDetails'        => __( 'View details', 'opti-behavior' ),
                        'markReviewed'       => __( 'Mark as reviewed', 'opti-behavior' ),
                        'markInProgress'     => __( 'In progress', 'opti-behavior' ),
                        'resolve'            => __( 'Resolve', 'opti-behavior' ),
                        'ignore'             => __( 'Ignore', 'opti-behavior' ),
                        'statusUpdated'      => __( 'Smart Insight status updated.', 'opti-behavior' ),
                        'refreshComplete'    => __( 'Smart Insights refreshed.', 'opti-behavior' ),
                        'error'              => __( 'Unable to load Smart Insights. Please try again.', 'opti-behavior' ),
                        'sessions'           => __( 'Sessions', 'opti-behavior' ),
                        'bounceRate'         => __( 'Bounce rate', 'opti-behavior' ),
                        'scrollDepth'        => __( 'Avg. scroll depth', 'opti-behavior' ),
                        'timeOnPage'         => __( 'Avg. time on page', 'opti-behavior' ),
                        'exitRate'           => __( 'Exit rate', 'opti-behavior' ),
                        'conversionRate'     => __( 'Conversion rate', 'opti-behavior' ),
                        'ctaClickRate'       => __( 'CTA click rate', 'opti-behavior' ),
                        'vsBaseline'         => __( 'vs', 'opti-behavior' ),
                        'priority'           => __( 'Priority', 'opti-behavior' ),
                        'confidence'         => __( 'Confidence', 'opti-behavior' ),
                        'critical'           => __( 'Critical', 'opti-behavior' ),
                        'high'               => __( 'High', 'opti-behavior' ),
                        'medium'             => __( 'Medium', 'opti-behavior' ),
                        'low'                => __( 'Low', 'opti-behavior' ),
                        'status'             => __( 'Status', 'opti-behavior' ),
                        'diagnosis'          => __( 'Diagnosis', 'opti-behavior' ),
                        'evidence'           => __( 'Evidence', 'opti-behavior' ),
                        'context'            => __( 'Context', 'opti-behavior' ),
                        'category'           => __( 'Category', 'opti-behavior' ),
                        'entity'             => __( 'Entity', 'opti-behavior' ),
                        'recurrence'         => __( 'Recurrence', 'opti-behavior' ),
                        'dateRange'          => __( 'Date range', 'opti-behavior' ),
                        'lastSeen'           => __( 'Last seen', 'opti-behavior' ),
                        'analysisWindow'     => __( 'Analysis window', 'opti-behavior' ),
                        'detected'           => __( 'Detected', 'opti-behavior' ),
                        'justNow'            => __( 'Just now', 'opti-behavior' ),
                        /* translators: %s: number of minutes. */
                        'minutesAgo'         => __( '%s min ago', 'opti-behavior' ),
                        /* translators: %s: number of hours. */
                        'hoursAgo'           => __( '%sh ago', 'opti-behavior' ),
                        /* translators: %s: number of days. */
                        'daysAgo'            => __( '%sd ago', 'opti-behavior' ),
                        'page'               => __( 'Page', 'opti-behavior' ),
                        'form'               => __( 'Form', 'opti-behavior' ),
                        'funnel'             => __( 'Funnel', 'opti-behavior' ),
                        'source'             => __( 'Source', 'opti-behavior' ),
                        'campaign'           => __( 'Campaign', 'opti-behavior' ),
                        'segment'            => __( 'Segment', 'opti-behavior' ),
                        'cta'                => __( 'CTA', 'opti-behavior' ),
                        'errorType'          => __( 'Error', 'opti-behavior' ),
                        'recurring'          => __( 'Recurring', 'opti-behavior' ),
                        'recurrenceHistory'  => __( 'Older overlapping detections are grouped into this card.', 'opti-behavior' ),
                        'relatedUnavailable' => __( 'No deep link available', 'opti-behavior' ),
                        'noApplicableReports' => __( 'No report applies directly to this insight.', 'opti-behavior' ),
                        /* translators: %s: number of reports that do not apply to this insight. */
                        'relatedOtherReport' => __( '%s other report does not apply to this insight', 'opti-behavior' ),
                        /* translators: %s: number of reports that do not apply to this insight. */
                        'relatedOtherReports' => __( '%s other reports do not apply to this insight', 'opti-behavior' ),
                        'recommendedAction'  => __( 'Recommended action', 'opti-behavior' ),
                        'recommendedActions' => __( 'Recommended actions', 'opti-behavior' ),
                        'nextAction'         => __( 'Next action', 'opti-behavior' ),
                        'defaultNextAction'  => __( 'Review the evidence, open the most relevant report, and decide whether this should move to In progress.', 'opti-behavior' ),
                        'likelyCauses'       => __( 'Likely causes', 'opti-behavior' ),
                        'genericCausesToggle' => __( 'Possible causes (generic)', 'opti-behavior' ),
                        /* translators: %s: formatted number of sessions. */
                        'causeSessions'      => __( '%s sessions', 'opti-behavior' ),
                        /* translators: %s: formatted percentage of affected sessions. */
                        'causeShareOfSessions' => __( '%s of affected sessions', 'opti-behavior' ),
                        'businessImpact'     => __( 'Business impact', 'opti-behavior' ),
                        'measuredCauses'     => __( 'Measured causes', 'opti-behavior' ),
                        'correlatedStory'    => __( 'Correlated story', 'opti-behavior' ),
                        /* translators: %s: number of correlated signals. */
                        'storySignalCount'   => __( '%s correlated signals', 'opti-behavior' ),
                        /* translators: %s: number of measured causes. */
                        'storyCauseCount'    => __( '%s measured causes', 'opti-behavior' ),
                        'revenueExposure'    => __( 'Revenue exposure', 'opti-behavior' ),
                        'revenueLocked'      => __( 'Revenue exposure for this leak is available in Pro.', 'opti-behavior' ),
                        'causeSharesLocked'  => __( 'Upgrade to Pro to see how much of this problem each cause explains.', 'opti-behavior' ),
                        /* translators: %s: formatted currency amount. */
                        'impactRevenueHeadline' => __( '≈ %s at risk this period', 'opti-behavior' ),
                        /* translators: %1$s: number of lost visitors, %2$s: formatted average order value. */
                        'impactRevenueOrders' => __( '%1$s drop-offs × %2$s avg. order', 'opti-behavior' ),
                        /* translators: %1$s: number of lost visitors, %2$s: formatted value of one conversion. */
                        'impactRevenueManual' => __( '%1$s drop-offs × %2$s per conversion', 'opti-behavior' ),
                        /* translators: %1$s: number of lost visitors, %2$s: conversion rate applied, %3$s: formatted average order value. */
                        'impactRevenueOrdersFactored' => __( '%1$s drop-offs × %2$s conversion rate × %3$s avg. order', 'opti-behavior' ),
                        /* translators: %1$s: number of lost visitors, %2$s: conversion rate applied, %3$s: formatted value of one conversion. */
                        'impactRevenueManualFactored' => __( '%1$s drop-offs × %2$s conversion rate × %3$s per conversion', 'opti-behavior' ),
                        /* translators: %s: number of sessions measured. */
                        'impactObservation'  => __( 'Too little traffic to size the impact reliably (%s sessions). Treat this as an observation to confirm, not a loss to act on.', 'opti-behavior' ),
                        'impactObservationNoSample' => __( 'Too little traffic to size the impact reliably. Treat this as an observation to confirm, not a loss to act on.', 'opti-behavior' ),
                        'impactObservationLabel' => __( 'Observation', 'opti-behavior' ),
                        'impactBasis'        => __( 'Measured on', 'opti-behavior' ),
                        'whyItMatters'       => __( 'Why it matters', 'opti-behavior' ),
                        'whatHappened'       => __( 'What happened', 'opti-behavior' ),
                        'trendComparison'    => __( 'Trend comparison', 'opti-behavior' ),
                        'segmentBreakdown'   => __( 'Segment breakdown', 'opti-behavior' ),
                        'whoIsAffected'      => __( 'Who is affected?', 'opti-behavior' ),
                        // "Where is the problem?" - the merged verdict, comparison bars and daily chart.
                        'whereTitle'         => __( 'Where is the problem?', 'opti-behavior' ),
                        'whereLoadingSegments' => __( 'Measuring which audience carries this...', 'opti-behavior' ),
                        'whereLoadingSeries' => __( 'Measuring the daily trend...', 'opti-behavior' ),
                        /* translators: %1$s: segment label, %2$s: metric label, %3$s: segment value, %4$s: value for everyone else, %5$s: segment sessions, %6$s: total sessions. */
                        'whereVerdictWorse'  => __( '%1$s carries this: %2$s %3$s vs %4$s for everyone else (%5$s of %6$s sessions).', 'opti-behavior' ),
                        /* translators: %1$s: segment label, %2$s: metric label, %3$s: segment value, %4$s: value for everyone else, %5$s: segment sessions, %6$s: total sessions. */
                        'whereVerdictBetter' => __( '%1$s behaves differently — better: %2$s %3$s vs %4$s for everyone else (%5$s of %6$s sessions).', 'opti-behavior' ),
                        'whereUniform'       => __( 'Spread evenly across device, source, country and time — this is the page, not an audience.', 'opti-behavior' ),
                        /* translators: %s: number of sessions measured in scope. */
                        'whereTooFewSessions' => __( 'Not enough sessions in this period to point at an audience (%s measured). Collect more traffic before blaming a segment.', 'opti-behavior' ),
                        'whereNeedsPageContext' => __( 'Segment split needs page-level context; open the funnel or form report for step-level detail.', 'opti-behavior' ),
                        /* translators: %s: audience segment name, e.g. "Mobile". */
                        'whereAlreadySegment' => __( 'This insight already isolates one audience segment (%s). The per-audience split applies to page-scoped insights - check the page-level insights correlated with this issue.', 'opti-behavior' ),
                        'whereUnavailable'   => __( 'No audience split could be measured for this insight scope.', 'opti-behavior' ),
                        'whereEveryoneElse'  => __( 'Everyone else', 'opti-behavior' ),
                        'whereNoValue'       => __( 'no data', 'opti-behavior' ),
                        'whereBarHint'       => __( 'Show this segment on the chart and re-scope the evidence links.', 'opti-behavior' ),
                        /* translators: %1$s: sessions in the segment, %2$s: sessions in scope. */
                        'whereBarSessions'   => __( '%1$s of %2$s sessions', 'opti-behavior' ),
                        'whereShortcutsTitle' => __( 'Open filtered to this segment', 'opti-behavior' ),
                        /* translators: %1$s: destination report name, %2$s: segment label. */
                        'whereShortcut'      => __( '%1$s (%2$s)', 'opti-behavior' ),
                        /* translators: %s: metric label. */
                        'whereChartTitle'    => __( 'Daily %s', 'opti-behavior' ),
                        'whereChartCurrent'  => __( 'This period', 'opti-behavior' ),
                        'whereChartPrevious' => __( 'Previous period', 'opti-behavior' ),
                        'whereFirstDetected' => __( 'First detected', 'opti-behavior' ),
                        /* translators: %1$s: date, %2$s: metric value, %3$s: number of sessions. */
                        'whereChartTooltip'  => __( '%1$s: %2$s (%3$s sessions)', 'opti-behavior' ),
                        'whereChartEmpty'    => __( 'No daily data is available for this period yet.', 'opti-behavior' ),
                        'whereChartError'    => __( 'Unable to load the daily trend.', 'opti-behavior' ),
                        /* translators: %1$s: sessions measured, %2$s: sessions required. */
                        'whereTooFewSessionsFloor' => __( 'Not enough sessions in this period to point at an audience: %1$s of the %2$s needed. Collect more traffic before blaming a segment.', 'opti-behavior' ),
                        'whereThisSegment'   => __( 'This segment', 'opti-behavior' ),
                        // Segment-health pulse strip (schema v3): shown to every tier.
                        'wherePulseTitle'    => __( 'Segment health', 'opti-behavior' ),
                        'wherePulseBroken'   => __( 'Carries the problem', 'opti-behavior' ),
                        'wherePulseWatch'    => __( 'Worth watching', 'opti-behavior' ),
                        'wherePulseHealthy'  => __( 'Behaves normally', 'opti-behavior' ),
                        'wherePulseInsufficient' => __( 'Not enough data', 'opti-behavior' ),
                        /* translators: %1$s: segments above the data floor, %2$s: segments found. */
                        'wherePulseMeasured' => __( '%1$s of %2$s segments measured', 'opti-behavior' ),
                        'whereBucketInsufficient' => __( 'Below the data floor — measured, but not conclusive.', 'opti-behavior' ),
                        // Sub-heading above the per-segment outlier cards, so they can carry their own help tooltip.
                        'whereOutliersTitle' => __( 'Segments that differ most', 'opti-behavior' ),
                        // Per-segment trend across the two halves of the period.
                        'whereTrendDegrading' => __( 'Getting worse across the period', 'opti-behavior' ),
                        'whereTrendImproving' => __( 'Getting better across the period', 'opti-behavior' ),
                        'whereTrendStable'   => __( 'Flat across the period', 'opti-behavior' ),
                        'whereTrendInsufficient' => __( 'Too few sessions per half to read a trend', 'opti-behavior' ),
                        /* translators: %1$s: trend wording, %2$s: early value then late value. */
                        'whereTrendDetail'   => __( '%1$s (%2$s)', 'opti-behavior' ),
                        /* translators: %1$s: metric label, %2$s: number of days. */
                        'whereSparklineLabel' => __( 'Daily %1$s for this segment over %2$s days', 'opti-behavior' ),
                        // Combination (paired) segments.
                        'whereComboBadge'    => __( 'Combined', 'opti-behavior' ),
                        'whereComboLockedOne' => __( '1 combined-segment pattern found', 'opti-behavior' ),
                        /* translators: %s: number of combined-segment patterns. */
                        'whereComboLockedMany' => __( '%s combined-segment patterns found', 'opti-behavior' ),
                        'whereComboLockedBody' => __( 'A pattern that only appears when two audience traits are combined — neither trait on its own was significant. Unlock the full pair in Pro.', 'opti-behavior' ),
                        // Traffic-mix shift: "did the page get worse, or did the audience change?"
                        'whereMixTitle'      => __( 'Did the audience change?', 'opti-behavior' ),
                        /* translators: %1$s: previous period start date, %2$s: previous period end date. */
                        'whereMixPrevious'   => __( 'compared with %1$s to %2$s', 'opti-behavior' ),
                        /* translators: %1$s: previous share of sessions, %2$s: current share of sessions. */
                        'whereMixShare'      => __( '%1$s → %2$s of sessions', 'opti-behavior' ),
                        'whereMixDegrades'   => __( 'and it performs worse than everyone else — the metric drop is a traffic-mix effect, not a page regression.', 'opti-behavior' ),
                        'whereMixNeutral'    => __( 'but it behaves like everyone else — the spike does not explain the metric change.', 'opti-behavior' ),
                        /* translators: %1$s: number of segments, %2$s: previous combined share, %3$s: current combined share. */
                        'whereMixGrouped'    => __( '%1$s segments grew together: %2$s → %3$s of sessions.', 'opti-behavior' ),
                        /* translators: %1$s: segment label, %2$s: previous share of sessions, %3$s: current share of sessions. */
                        'whereMixLockedHeadline' => __( 'Traffic from %1$s moved from %2$s to %3$s of sessions.', 'opti-behavior' ),
                        'whereMixLockedGeneric' => __( 'Your traffic mix changed measurably over this period.', 'opti-behavior' ),
                        'whereMixLockedBody' => __( 'The full breakdown — every segment that grew, by how much, and whether it explains the metric — is available in Pro.', 'opti-behavior' ),
                        'segmentsLoading'    => __( 'Measuring the affected population...', 'opti-behavior' ),
                        'segmentsEmpty'      => __( 'No segment split could be measured for this insight scope.', 'opti-behavior' ),
                        'segmentsError'      => __( 'Unable to load the affected segments.', 'opti-behavior' ),
                        /* translators: %s: number of sessions. */
                        'segmentsPopulation' => __( '%s affected sessions in scope', 'opti-behavior' ),
                        'segmentsTopLabel'   => __( 'Most affected', 'opti-behavior' ),
                        'segmentsLockedMore' => __( 'Upgrade to Pro to break this insight down by device, source, campaign, and visitor type.', 'opti-behavior' ),
                        'segmentScopeHint'   => __( 'Select a segment to re-scope the evidence links below.', 'opti-behavior' ),
                        /* translators: %s: segment label. */
                        'segmentScopeActive' => __( 'Evidence links scoped to %s', 'opti-behavior' ),
                        'segmentScopeClear'  => __( 'Clear segment scope', 'opti-behavior' ),
                        'segmentSessions'    => __( 'sessions', 'opti-behavior' ),
                        'segmentsUpgradeCta' => __( 'Upgrade to Pro', 'opti-behavior' ),
                        'evidenceRefsTitle'  => __( 'Evidence and proof', 'opti-behavior' ),
                        /* translators: %s: number of evidence references. */
                        'evidenceRefsLockedCount' => __( '%s evidence references collected for this insight', 'opti-behavior' ),
                        'evidencePreviousPeriod' => __( 'Previous period (site-wide)', 'opti-behavior' ),
                        'hypothesisTitle'    => __( 'Hypothesis and next experiment', 'opti-behavior' ),
                        'hypothesisMetric'   => __( 'Goal metric', 'opti-behavior' ),
                        'hypothesisSegment'  => __( 'Suggested audience', 'opti-behavior' ),
                        'hypothesisTypicalRange' => __( 'Typical industry range', 'opti-behavior' ),
                        'hypothesisSampleSize' => __( 'Sessions needed per variant', 'opti-behavior' ),
                        'hypothesisDuration' => __( 'Estimated duration', 'opti-behavior' ),
                        /* translators: %s: number of days. */
                        'hypothesisDurationDays' => __( '%s days', 'opti-behavior' ),
                        /* translators: %s: number of days the server-side estimate was capped at. */
                        'hypothesisDurationCapped' => __( 'More than %s days at current traffic', 'opti-behavior' ),
                        /* translators: %s: recomputed number of days the test would really take. */
                        'hypothesisDurationReal' => __( '~%s days at current traffic', 'opti-behavior' ),
                        /* translators: %s: average number of sessions per day. */
                        'hypothesisNotFeasible' => __( 'An A/B test is not feasible at current traffic (~%s sessions/day). Ship the change and compare before/after instead.', 'opti-behavior' ),
                        'hypothesisNoBaseline' => __( 'No measurable baseline for this goal metric yet — ship the change and compare before/after.', 'opti-behavior' ),
                        /* translators: %s: number of related signals grouped under this insight. */
                        'alsoDetectedHere'   => __( 'Also detected here (%s)', 'opti-behavior' ),
                        /* translators: %s: priority score. */
                        'priorityShort'      => __( 'priority %s', 'opti-behavior' ),
                        /* translators: %s: number of sessions lost. */
                        'sessionsLostShort'  => __( '%s sessions lost', 'opti-behavior' ),
                        /* translators: 1: signal name, 2: number of traffic sources. */
                        'sourceGroupTitle'   => __( '%1$s across %2$s traffic sources', 'opti-behavior' ),
                        /* translators: %s: number of traffic sources grouped in one card. */
                        'sourceGroupCount'   => __( '%s traffic sources', 'opti-behavior' ),
                        /* translators: %s: number of sessions on this traffic source. */
                        'sourceGroupSessions' => __( '%s sessions', 'opti-behavior' ),
                        /* translators: %s: conversion rate of this traffic source. */
                        'sourceGroupConversion' => __( '%s conversion rate', 'opti-behavior' ),
                        'sourceGroupExplanation' => __( 'The same signal fired on several traffic sources. Open a source to see its own diagnosis.', 'opti-behavior' ),
                        /* translators: %s: formatted money amount. */
                        'briefAmountAtRisk'  => __( '%s at risk', 'opti-behavior' ),
                        /* translators: %s: number of visitors. */
                        'briefVisitorsAtRisk' => __( '%s visitors at risk', 'opti-behavior' ),
                        /* translators: %s: number of sessions the insight was measured on. */
                        'briefObservation'   => __( 'Observation on %s sessions', 'opti-behavior' ),
                        /* translators: %s: number of sessions the insight was measured on. */
                        'briefSessionsMeasured' => __( 'Measured on %s sessions', 'opti-behavior' ),
                        'stateNew'           => __( 'New', 'opti-behavior' ),
                        'stateWorse'         => __( 'Worse', 'opti-behavior' ),
                        'stateBetter'        => __( 'Better', 'opti-behavior' ),
                        'stateSteady'        => __( 'Unchanged', 'opti-behavior' ),
                        'stateResolved'      => __( 'Resolved', 'opti-behavior' ),
                        /* translators: %s: date the insight was marked resolved. */
                        'outcomeFixed'       => __( 'Fixed %s', 'opti-behavior' ),
                        /* translators: 1: metric name, 2: value before the fix, 3: value after the fix, 4: signed change. */
                        'outcomeChange'      => __( '%1$s %2$s → %3$s (%4$s)', 'opti-behavior' ),
                        /* translators: %s: change expressed in percentage points. */
                        'outcomePoints'      => __( '%s pts', 'opti-behavior' ),
                        /* translators: %s: change expressed in seconds. */
                        'outcomeSeconds'     => __( '%ss', 'opti-behavior' ),
                        'outcomeNoChange'    => __( 'no measurable change yet', 'opti-behavior' ),
                        'outcomeNotMeasurable' => __( 'not measurable yet', 'opti-behavior' ),
                        'outcomePending'     => __( 'measurement in progress', 'opti-behavior' ),
                        'winsThisMonth'      => __( 'Wins this month', 'opti-behavior' ),
                        /* translators: %s: number of additional insights hidden behind the fold. */
                        'showMoreObservations' => __( 'Show %s more observations', 'opti-behavior' ),
                        'showFewerObservations' => __( 'Show fewer', 'opti-behavior' ),
                        'hypothesisDisclaimer' => __( 'Expected ranges are typical published results for this kind of change, not a prediction for your site.', 'opti-behavior' ),
                        'hypothesisCreateTest' => __( 'Create A/B test from this insight', 'opti-behavior' ),
                        'hypothesisLinkedTest' => __( 'Linked A/B test', 'opti-behavior' ),
                        /* translators: %s: A/B test ID. */
                        'hypothesisTestNumber' => __( 'Test #%s', 'opti-behavior' ),
                        'hypothesisLockedTitle' => __( 'A testable hypothesis is ready for this insight', 'opti-behavior' ),
                        'hypothesisLockedHint' => __( 'Upgrade to Pro to see the suggested hypothesis, the typical industry range, and the sample size this test would need.', 'opti-behavior' ),
                        'stageTrackerLabel'  => __( 'Experiment progress', 'opti-behavior' ),
                        'stageDetected'      => __( 'Detected', 'opti-behavior' ),
                        'stageDiagnosed'     => __( 'Diagnosed', 'opti-behavior' ),
                        'stageHypothesis'    => __( 'Hypothesis', 'opti-behavior' ),
                        'stageTesting'       => __( 'Testing', 'opti-behavior' ),
                        'stageVerified'      => __( 'Verified', 'opti-behavior' ),
                        'experimentResultTitle' => __( 'Experiment result', 'opti-behavior' ),
                        'verdictLift'        => __( 'Measured lift', 'opti-behavior' ),
                        'verdictProbability' => __( 'Probability to beat control', 'opti-behavior' ),
                        'verdictSample'      => __( 'Sessions measured', 'opti-behavior' ),
                        'verdictWinners'     => __( 'Segments that won', 'opti-behavior' ),
                        'verdictLosers'      => __( 'Segments that lost', 'opti-behavior' ),
                        'verdictNextStep'    => __( 'Next experiment', 'opti-behavior' ),
                        'categoryLearning'   => __( 'Experiment learning', 'opti-behavior' ),
                        'openReport'         => __( 'Open report', 'opti-behavior' ),
                        'relatedReports'     => __( 'Related reports', 'opti-behavior' ),
                        'relatedReport'      => __( 'Related report', 'opti-behavior' ),
                        'proLocked'          => __( 'Pro preview', 'opti-behavior' ),
                        'proDiagnosis'       => __( 'Pro diagnosis available', 'opti-behavior' ),
                        'proDiagnosisDescription' => __( 'Unlock segment breakdowns, related recordings, and advanced CRO recommendations.', 'opti-behavior' ),
                        'proEvidenceLocked'  => __( 'Detailed evidence is available in Pro.', 'opti-behavior' ),
                        'proRelatedLocked'   => __( 'Related recordings, journeys, and segment reports unlock in Pro.', 'opti-behavior' ),
                        'segmentLockedTitle' => __( 'Advanced segment insight available in Pro', 'opti-behavior' ),
                        'segmentLockedBody'  => __( 'Upgrade to unlock device/source breakdowns, related session recordings, and advanced recommendations.', 'opti-behavior' ),
                        'weeklyLockedAria'   => __( 'Weekly CRO Summary is locked in Free mode', 'opti-behavior' ),
                        'weeklyPreviewAria'  => __( 'Protected Weekly CRO Summary preview', 'opti-behavior' ),
                        'weeklyLockedHeadline' => __( 'Unlock your weekly CRO briefing.', 'opti-behavior' ),
                        'weeklyLockedBody'   => __( 'Pro summarizes priority patterns and recommended actions across your Smart Insights without exposing protected report data in Free.', 'opti-behavior' ),
                        'weeklyRecurringIssues' => __( 'Recurring issues', 'opti-behavior' ),
                        'weeklyRecurringIssuesBody' => __( 'Patterns that keep returning across the week.', 'opti-behavior' ),
                        'weeklyBiggestImprovement' => __( 'Biggest improvement', 'opti-behavior' ),
                        'weeklyBiggestImprovementBody' => __( 'The strongest positive movement to learn from.', 'opti-behavior' ),
                        'weeklyBiggestDecline' => __( 'Biggest decline', 'opti-behavior' ),
                        'weeklyBiggestDeclineBody' => __( 'The highest-risk change needing review.', 'opti-behavior' ),
                        'weeklyRecommendedNextAction' => __( 'Recommended next action', 'opti-behavior' ),
                        'weeklyRecommendedNextActionBody' => __( 'One prioritized action for the next optimization step.', 'opti-behavior' ),
                        'weeklyUpgradeCta'   => __( 'Upgrade to unlock summary', 'opti-behavior' ),
                        'weeklyProtectedNote' => __( 'Pro-only metrics stay protected until Pro is active.', 'opti-behavior' ),
                        'noSegmentData'      => __( 'No segment breakdown is available for this insight yet.', 'opti-behavior' ),
                        'noTrendData'        => __( 'No previous-period trend data is available for this insight yet.', 'opti-behavior' ),
                        'noCriticalSignals'  => __( 'No critical behavior signals detected for this period.', 'opti-behavior' ),
                        'noCriticalSignalsBody' => __( 'Your key engagement metrics are within expected ranges. You can still review heatmaps, funnels, and source reports to look for optimization opportunities.', 'opti-behavior' ),
                        'lowDataTitle'       => __( 'Not enough data yet to generate reliable Smart Insights.', 'opti-behavior' ),
                        'lowDataBody'        => __( 'OptiUser needs more visitor activity before it can detect meaningful behavior patterns. Insights become more reliable after at least 100 sessions per page or segment.', 'opti-behavior' ),
                        'noMatchingInsights' => __( 'No insights match these filters.', 'opti-behavior' ),
                        'noMatchingInsightsBody' => __( 'Try broadening the filters or selecting a longer date range.', 'opti-behavior' ),
                        'selectCustomRangeTitle' => __( 'Custom range requires dates', 'opti-behavior' ),
                        'selectCustomRange'  => __( 'Select a start and end date to use a custom range.', 'opti-behavior' ),
                        'statusNew'          => __( 'New', 'opti-behavior' ),
                        'statusViewed'       => __( 'Reviewed', 'opti-behavior' ),
                        'statusInProgress'   => __( 'In progress', 'opti-behavior' ),
                        'statusResolved'     => __( 'Resolved', 'opti-behavior' ),
                        'statusIgnored'      => __( 'Ignored', 'opti-behavior' ),
                        'statusAutoResolved' => __( 'Auto-resolved', 'opti-behavior' ),
                        'openInsight'        => __( 'Open insight', 'opti-behavior' ),
                        'smartInsight'       => __( 'Smart Insight', 'opti-behavior' ),
                        'close'              => __( 'Close', 'opti-behavior' ),
                        'abTesting'                        => __( 'A/B testing', 'opti-behavior' ),
                        'abandonmentRate'                  => __( 'Abandonment rate', 'opti-behavior' ),
                        'activeInsights'                   => __( 'Active', 'opti-behavior' ),
                        'adminEdit'                        => __( 'Admin edit', 'opti-behavior' ),
                        'analystBriefing'                  => __( 'Analyst briefing', 'opti-behavior' ),
                        'analytics'                        => __( 'Analytics', 'opti-behavior' ),
                        'baselineUnavailable'              => __( 'Baseline unavailable', 'opti-behavior' ),
                        'biggestMover'                     => __( 'Biggest mover', 'opti-behavior' ),
                        'categoryCampaign'                 => __( 'Campaign Issue', 'opti-behavior' ),
                        'categoryDevice'                   => __( 'Mobile Issue', 'opti-behavior' ),
                        'categoryEngagement'               => __( 'Engagement Issue', 'opti-behavior' ),
                        'categoryExit'                     => __( 'Exit / Abandonment', 'opti-behavior' ),
                        'categoryForm'                     => __( 'Form Friction', 'opti-behavior' ),
                        'categoryFunnel'                   => __( 'Funnel Drop-off', 'opti-behavior' ),
                        'categoryRevenue'                  => __( 'Revenue Opportunity', 'opti-behavior' ),
                        'categoryTechnical'                => __( 'Technical Issue', 'opti-behavior' ),
                        'categoryTrend'                    => __( 'Traffic Quality', 'opti-behavior' ),
                        'categoryUxCro'                    => __( 'UX / CRO', 'opti-behavior' ),
                        'checkFriction'                    => __( 'Check friction', 'opti-behavior' ),
                        'chooseStatus'                     => __( 'Choose status', 'opti-behavior' ),
                        'cleanSessionConversionRate'       => __( 'Clean session conversion', 'opti-behavior' ),
                        'createAbTest'                     => __( 'Create A/B test', 'opti-behavior' ),
                        'ctaClicks'                        => __( 'CTA clicks', 'opti-behavior' ),
                        'ctaExposures'                     => __( 'CTA exposures', 'opti-behavior' ),
                        'current'                          => __( 'Current', 'opti-behavior' ),
                        'deadClickRate'                    => __( 'Dead click rate', 'opti-behavior' ),
                        'deadClicks'                       => __( 'Dead clicks', 'opti-behavior' ),
                        'errorCount'                       => __( 'Errors', 'opti-behavior' ),
                        'errorRate'                        => __( 'Error rate', 'opti-behavior' ),
                        'errorSessionConversionRate'       => __( 'Error session conversion', 'opti-behavior' ),
                        'errorSessions'                    => __( 'Error sessions', 'opti-behavior' ),
                        'errorTracking'                    => __( 'Error tracking', 'opti-behavior' ),
                        'errorsFormContextDescription'     => __( 'Error reports need page or error-type context before this form can be isolated.', 'opti-behavior' ),
                        'errorsFormFilterUnsupported'      => __( 'Errors cannot be filtered by this form yet. Inspect form analytics for field errors tied to this form.', 'opti-behavior' ),
                        'evidenceUnavailable'              => __( 'Evidence unavailable', 'opti-behavior' ),
                        'exposedVisitors'                  => __( 'Exposed visitors', 'opti-behavior' ),
                        'findPageInHeatmaps'               => __( 'Find page in heatmaps', 'opti-behavior' ),
                        'findPageInHeatmapsDescription'    => __( 'Open the heatmap list filtered to this page URL.', 'opti-behavior' ),
                        'formAnalytics'                    => __( 'Form analytics', 'opti-behavior' ),
                        'formAnalyticsNeedsFormContext'    => __( 'Form analytics needs a specific form or field context for this insight.', 'opti-behavior' ),
                        'formStarts'                       => __( 'Form starts', 'opti-behavior' ),
                        'formSubmits'                      => __( 'Form submits', 'opti-behavior' ),
                        'frictionEvents'                   => __( 'Friction events', 'opti-behavior' ),
                        'frictionReport'                   => __( 'Friction report', 'opti-behavior' ),
                        'funnelCompletionRate'             => __( 'Completion rate', 'opti-behavior' ),
                        'funnelDropoffRate'                => __( 'Drop-off rate', 'opti-behavior' ),
                        'funnelEntrants'                   => __( 'Funnel entrants', 'opti-behavior' ),
                        'heatmap'                          => __( 'Heatmap', 'opti-behavior' ),
                        'highPriority'                     => __( 'High priority', 'opti-behavior' ),
                        'improving'                        => __( 'Improving', 'opti-behavior' ),
                        'inspectFieldDropoff'              => __( 'Inspect field drop-off', 'opti-behavior' ),
                        'inspectFieldDropoffDescription'   => __( 'Open this form with field-level abandonment and error context.', 'opti-behavior' ),
                        'inspectForm'                      => __( 'Inspect form', 'opti-behavior' ),
                        'journeyFormContextDescription'    => __( 'Journey filters need page or visitor context before this form can be isolated.', 'opti-behavior' ),
                        'journeyFormFilterUnsupported'     => __( 'Journeys cannot be filtered by this form yet. Use form analytics to inspect the abandonment path.', 'opti-behavior' ),
                        'loadingSummary'                   => __( 'Loading Weekly CRO Summary...', 'opti-behavior' ),
                        'moreIssues'                       => __( 'more issues', 'opti-behavior' ),
                        'movement'                         => __( 'Trend watch', 'opti-behavior' ),
                        'noClearMover'                     => __( 'No clear movement detected.', 'opti-behavior' ),
                        'noPreviousData'                   => __( 'No previous data', 'opti-behavior' ),
                        'noRelatedReports'                 => __( 'No related reports are available for this insight yet.', 'opti-behavior' ),
                        'noSummaryItems'                   => __( 'No notable items for this section yet.', 'opti-behavior' ),
                        'noTrendBaseline'                  => __( 'No previous-period trend is available for this insight yet. Use the site baseline comparison in Evidence until enough previous-period data exists.', 'opti-behavior' ),
                        'notRelated'                       => __( 'Not related', 'opti-behavior' ),
                        'openAnalytics'                    => __( 'Open analytics', 'opti-behavior' ),
                        'openAnalyticsForPage'             => __( 'Open analytics for this page', 'opti-behavior' ),
                        'openAnalyticsForSegment'          => __( 'Open analytics for this segment', 'opti-behavior' ),
                        'openFunnel'                       => __( 'Open funnel', 'opti-behavior' ),
                        'openFunnelContext'                => __( 'Open this funnel report', 'opti-behavior' ),
                        'openHeatmap'                      => __( 'Open heatmap', 'opti-behavior' ),
                        'openHeatmapForPage'               => __( 'Open heatmap for this page', 'opti-behavior' ),
                        'openReport'                       => __( 'Open report', 'opti-behavior' ),
                        'openSupportingReport'             => __( 'Open supporting report', 'opti-behavior' ),
                        'openingLinkedInsight'             => __( 'Opening the linked Smart Insight. It may be outside the current filters.', 'opti-behavior' ),
                        'previous'                         => __( 'Previous', 'opti-behavior' ),
                        'previousTrendUnavailable'         => __( 'Previous-period trend is not available yet; these comparisons use the current site baseline.', 'opti-behavior' ),
                        'rageClicks'                       => __( 'Rage clicks', 'opti-behavior' ),
                        'recordingsFormContextDescription' => __( 'Recordings need page or visitor context before this form can be isolated.', 'opti-behavior' ),
                        'recordingsFormFilterUnsupported'  => __( 'Recordings cannot be filtered by this form yet. Inspect the form analytics report for field-level evidence.', 'opti-behavior' ),
                        'recurringPatterns'                => __( 'Recurring patterns', 'opti-behavior' ),
                        'reportDestination'                => __( 'Report destination', 'opti-behavior' ),
                        'reviewErrors'                     => __( 'Review errors', 'opti-behavior' ),
                        'reviewErrorsForForm'              => __( 'Review errors for this form', 'opti-behavior' ),
                        'reviewErrorsForPage'              => __( 'Review errors for this page', 'opti-behavior' ),
                        'reviewJourneys'                   => __( 'Review journeys', 'opti-behavior' ),
                        'reviewJourneysForForm'            => __( 'Review journeys for this form', 'opti-behavior' ),
                        'reviewJourneysForPage'            => __( 'Review journeys from this page', 'opti-behavior' ),
                        'reviewJourneysForSegment'         => __( 'Review journeys for this segment', 'opti-behavior' ),
                        'sessionRecordings'                => __( 'Session recordings', 'opti-behavior' ),
                        'siteBaseline'                     => __( 'Site baseline', 'opti-behavior' ),
                        'summaryScopeNote'                 => __( 'Counts use deduplicated active insight groups for the selected range.', 'opti-behavior' ),
                        'unavailable'                      => __( 'Unavailable', 'opti-behavior' ),
                        'unknownEntity'                    => __( 'Unknown entity', 'opti-behavior' ),
                        'updateStatus'                     => __( 'Update status', 'opti-behavior' ),
                        'userJourneys'                     => __( 'User journeys', 'opti-behavior' ),
                        'viewPage'                         => __( 'View page', 'opti-behavior' ),
                        'viewRecordings'                   => __( 'View recordings', 'opti-behavior' ),
                        'viewRecordingsForForm'            => __( 'View recordings for this form', 'opti-behavior' ),
                        'viewRecordingsForPage'            => __( 'View recordings filtered to this page', 'opti-behavior' ),
                        'viewRecordingsForSegment'         => __( 'View recordings for this segment', 'opti-behavior' ),
                        'whereToInvestigate'               => __( 'Where to investigate', 'opti-behavior' ),
                        'worsening'                        => __( 'Worsening', 'opti-behavior' ),
                        'reportDescHeatmap'                => __( 'Review page-level click, movement, and scroll evidence.', 'opti-behavior' ),
                        'reportDescRecordings'             => __( 'Watch sessions that match this behavior pattern.', 'opti-behavior' ),
                        'reportDescJourney'                => __( 'Trace the path visitors took before and after this signal.', 'opti-behavior' ),
                        'reportDescForm'                   => __( 'Find fields, errors, and abandonments driving friction.', 'opti-behavior' ),
                        'reportDescErrors'                 => __( 'Check technical errors associated with this opportunity.', 'opti-behavior' ),
                        'reportDescFriction'               => __( 'Inspect rage clicks, dead clicks, and UI friction.', 'opti-behavior' ),
                        'reportDescFunnel'                 => __( 'See where visitors drop out of the conversion path.', 'opti-behavior' ),
                        'reportDescAbTesting'              => __( 'Turn this finding into an experiment.', 'opti-behavior' ),
                        'reportDescAnalytics'              => __( 'Review supporting traffic and engagement context.', 'opti-behavior' ),
                        'reportDescDevice'                 => __( 'Compare behavior by device and segment.', 'opti-behavior' ),
                        'reportDescDefault'                => __( 'Open the most relevant supporting report.', 'opti-behavior' ),
                        'categoryGeneral'                  => __( 'General', 'opti-behavior' ),
                        /* translators: %d: number of high-priority issues. */
                        'weeklyHeadline'                   => __( '%d high-priority issues need review this week', 'opti-behavior' ),
                        'defaultRecommendedAction'         => __( 'Review the highest-priority issue and open the supporting report.', 'opti-behavior' ),
                        'yes'                              => __( 'Yes', 'opti-behavior' ),
                        'no'                               => __( 'No', 'opti-behavior' ),
                        'item'                             => __( 'Item', 'opti-behavior' ),
                        'compactScrollDepth'               => __( 'Scroll depth', 'opti-behavior' ),
                        'pageviews'                        => __( 'Pageviews', 'opti-behavior' ),
                        'dateRangeTo'                      => __( 'to', 'opti-behavior' ),
                        'addToCartRate'                    => __( 'Add-to-cart rate', 'opti-behavior' ),
                        'cartToCheckoutRate'               => __( 'Cart-to-checkout rate', 'opti-behavior' ),
                        'checkoutToPurchaseRate'           => __( 'Checkout-to-purchase rate', 'opti-behavior' ),
                        'productViews'                     => __( 'Product views', 'opti-behavior' ),
                        'cartSessions'                     => __( 'Cart sessions', 'opti-behavior' ),
                        'checkoutSessions'                 => __( 'Checkout sessions', 'opti-behavior' ),
                    ),
                )
            );
        }

        // Add shared dashboard design primitives on analytics and Smart Insights pages.
        $is_dashboard_style_screen = strpos( $hook_suffix, 'opti-behavior-analytics' ) !== false || strpos( $hook_suffix, 'opti-behavior-smart-insights' ) !== false || $is_roadmap_screen;
        if ( $is_dashboard_style_screen ) {
			wp_enqueue_style(
				'opti-behavior-dashboard-styles',
				OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/dashboard_styles.css',
				array( 'opti-behavior-dashboard' ),
				// Cache-bust on file change (mirrors dashboard.js) so style updates
				// ship without requiring a plugin version bump.
				file_exists( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/dashboard_styles.css' )
					? OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . filemtime( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/dashboard_styles.css' )
					: OPTI_BEHAVIOR_HEATMAP_VERSION
			);
		}

		// Smart Insights redesign layer. Scoped to .ob-smart-insights-center-page
		// and loaded only on that screen, after the two shared dashboard
		// stylesheets, so the Analytics screen keeps its own cascade untouched.
		if ( strpos( $hook_suffix, 'opti-behavior-smart-insights' ) !== false ) {
			$opti_behavior_smart_insights_center_css = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/smart-insights-center.css';

			wp_enqueue_style(
				'opti-behavior-smart-insights-center',
				OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/smart-insights-center.css',
				array( 'opti-behavior-dashboard-styles' ),
				// Cache-bust on file change so style updates ship without a
				// plugin version bump.
				file_exists( $opti_behavior_smart_insights_center_css )
					? OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . filemtime( $opti_behavior_smart_insights_center_css )
					: OPTI_BEHAVIOR_HEATMAP_VERSION
			);
		}

        // Add dashboard-specific dynamic scripts if on analytics page.
        if ( strpos( $hook_suffix, 'opti-behavior-analytics' ) !== false ) {
            // Shared advanced-filters UI module (icon multi-select dropdowns,
            // count-annotated options, autocomplete suggestions). Extracted from
            // dashboard.js; dashboard.js consumes window.OptiBehaviorFilterUI so
            // the module MUST load first (declared as a dependency below).
            $opti_behavior_filter_ui_js  = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/js/opti-behavior-filter-ui.js';
            $opti_behavior_filter_ui_css = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/filter-ui.css';

            wp_enqueue_script(
                'opti-behavior-filter-ui',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/opti-behavior-filter-ui.js',
                array(),
                file_exists( $opti_behavior_filter_ui_js )
                    ? OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . filemtime( $opti_behavior_filter_ui_js )
                    : OPTI_BEHAVIOR_HEATMAP_VERSION,
                true
            );

            // Styles moved verbatim from dashboard_styles.css; loaded after it
            // (dependency) so the cascade order is unchanged.
            wp_enqueue_style(
                'opti-behavior-filter-ui',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/filter-ui.css',
                array( 'opti-behavior-dashboard-styles' ),
                file_exists( $opti_behavior_filter_ui_css )
                    ? OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . filemtime( $opti_behavior_filter_ui_css )
                    : OPTI_BEHAVIOR_HEATMAP_VERSION
            );

            // Shared filter-badge module: the "Filters (N)" active-filter counter
            // and the URL helpers that reflect a Smart Insights deep link in the
            // page's own filter controls. Dependency-free.
            $opti_behavior_filter_badge_js = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/js/opti-behavior-filter-badge.js';
            wp_enqueue_script(
                'opti-behavior-filter-badge',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/opti-behavior-filter-badge.js',
                array(),
                file_exists( $opti_behavior_filter_badge_js )
                    ? OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . filemtime( $opti_behavior_filter_badge_js )
                    : OPTI_BEHAVIOR_HEATMAP_VERSION,
                true
            );

            // Filter Profiles module (site-wide saved advanced-filter sets).
            // Depends on the shared filter-UI module: repopulating icon
            // multi-selects on profile load calls select._obIconSync(), which
            // that module installs. Config carries the AJAX URL + the dedicated
            // nonce for the FREE CRUD endpoints.
            $opti_behavior_filter_profiles_js = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/js/opti-behavior-filter-profiles.js';
            wp_enqueue_script(
                'opti-behavior-filter-profiles',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/opti-behavior-filter-profiles.js',
                array( 'opti-behavior-filter-ui' ),
                file_exists( $opti_behavior_filter_profiles_js )
                    ? OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . filemtime( $opti_behavior_filter_profiles_js )
                    : OPTI_BEHAVIOR_HEATMAP_VERSION,
                true
            );

            wp_localize_script(
                'opti-behavior-filter-profiles',
                'OptiBehaviorProfilesConfig',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( 'opti_behavior_filter_profiles' ),
                )
            );

            // Enqueue dashboard page scripts from external file
            // Version 1.0.4: Top Pages now uses heatmap sessions count consistently (server + JS)
            $opti_behavior_dashboard_js = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/js/dashboard.js';
            $opti_behavior_dashboard_ver = file_exists( $opti_behavior_dashboard_js )
                ? OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . filemtime( $opti_behavior_dashboard_js )
                : OPTI_BEHAVIOR_HEATMAP_VERSION;

            wp_enqueue_script(
                'opti-behavior-dashboard-scripts',
                OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/dashboard.js',
                array( 'chart-js', 'opti-behavior-filter-ui', 'opti-behavior-filter-badge', 'opti-behavior-filter-profiles' ), // Chart.js + shared filter-UI + badge + profiles modules
                $opti_behavior_dashboard_ver,
                true
            );

            // Pass AJAX URL, nonce, and translated strings to dashboard.js
            wp_localize_script(
                'opti-behavior-dashboard-scripts',
                'opti_behaviorDashboard',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( 'optibehavior_top_users' ),
                    'i18n'    => array(
                        'dayNames'                => array(
                            __( 'Sunday', 'opti-behavior' ),
                            __( 'Monday', 'opti-behavior' ),
                            __( 'Tuesday', 'opti-behavior' ),
                            __( 'Wednesday', 'opti-behavior' ),
                            __( 'Thursday', 'opti-behavior' ),
                            __( 'Friday', 'opti-behavior' ),
                            __( 'Saturday', 'opti-behavior' ),
                        ),
                        'visits'                  => __( 'visits', 'opti-behavior' ),
                        'high'                    => __( 'High', 'opti-behavior' ),
                        'low'                     => __( 'Low', 'opti-behavior' ),
                        'pc'                      => __( 'PC', 'opti-behavior' ),
                        'desktop'                 => __( 'Desktop', 'opti-behavior' ),
                        'mobile'                  => __( 'Mobile', 'opti-behavior' ),
                        'tablet'                  => __( 'Tablet', 'opti-behavior' ),
                        'vsLastPeriod'            => __( '% vs last period', 'opti-behavior' ),
                        'mapUnavailable'          => __( 'Map unavailable', 'opti-behavior' ),
                        'mapUnavailableOffline'   => __( 'Map unavailable (offline)', 'opti-behavior' ),
                        'noScreenResolutionData'  => __( 'No screen resolution data available', 'opti-behavior' ),
                        'tryBroadeningDateRange'  => __( 'Try broadening the date range or check back later.', 'opti-behavior' ),
                        'noCountryData'           => __( 'No country data available', 'opti-behavior' ),
                        'noReferrerData'          => __( 'No referrer data available', 'opti-behavior' ),
                        'humanTraffic'            => __( 'Human Traffic', 'opti-behavior' ),
                        'automated'               => __( 'Automated', 'opti-behavior' ),
                        'spamTraffic'             => __( 'Spam Traffic', 'opti-behavior' ),
                        'noActiveVisitors'        => __( 'No active visitors right now', 'opti-behavior' ),
                        'trafficUpdates'          => __( 'Traffic updates in real-time.', 'opti-behavior' ),
                        'excludeSpam'             => __( 'Exclude Spam', 'opti-behavior' ),
                        'includeSpam'             => __( 'Include Spam', 'opti-behavior' ),
                        'interactions'            => __( 'Heatmap sessions', 'opti-behavior' ),
                        'clicks'                  => __( 'Clicks', 'opti-behavior' ),
                        'avgTimeSpent'            => __( 'Average time spent', 'opti-behavior' ),
                        'editPage'                => __( 'Edit this page', 'opti-behavior' ),
                        'directNone'              => __( 'Direct / None', 'opti-behavior' ),
                        'unknown'                 => __( 'Unknown', 'opti-behavior' ),
                        'justNow'                 => __( 'just now', 'opti-behavior' ),
                        /* translators: %s: number of minutes */
                        'minutesAgo'              => __( '%sm ago', 'opti-behavior' ),
                        /* translators: %s: number of hours */
                        'hoursAgo'                => __( '%sh ago', 'opti-behavior' ),
                        /* translators: %s: number of days */
                        'daysAgo'                 => __( '%sd ago', 'opti-behavior' ),
                        /* translators: %s: number of months */
                        'monthsAgo'               => __( '%smo ago', 'opti-behavior' ),
                        /* translators: %s: time duration */
                        'ago'                     => __( '%s ago', 'opti-behavior' ),
                        'visitorsLabel'           => __( 'Visitors', 'opti-behavior' ),
                        'sessionsLabel'           => __( 'Sessions', 'opti-behavior' ),
                        'pageViewsLabel'          => __( 'Page Views', 'opti-behavior' ),
                        'avgSessionTimeLabel'     => __( 'Avg. Session Time', 'opti-behavior' ),
                        'avgScrollDepthLabel'     => __( 'Avg. Scroll Depth', 'opti-behavior' ),
                        'bounceRateLabel'         => __( 'Bounce Rate', 'opti-behavior' ),
                        'highIntent'              => __( 'High intent', 'opti-behavior' ),
                        'mediumIntent'            => __( 'Medium intent', 'opti-behavior' ),
                        'lowIntent'               => __( 'Low intent', 'opti-behavior' ),
                        'totalVisitors'           => __( 'Total Visitors', 'opti-behavior' ),
                        'loggedInVisitors'        => __( 'Logged In Visitors', 'opti-behavior' ),
                        'newRegistrations'        => __( 'New Registrations', 'opti-behavior' ),
                        'newVisitors'             => __( 'New Visitors', 'opti-behavior' ),
                        'returningVisitors'       => __( 'Returning Visitors', 'opti-behavior' ),
                        'noBrowserData'           => __( 'No browser data available', 'opti-behavior' ),
                        'noDataYet'               => __( 'No data yet', 'opti-behavior' ),
                        'noDeviceData'            => __( 'No device data available', 'opti-behavior' ),
                        'noOperatingSystemData'   => __( 'No operating system data available', 'opti-behavior' ),
                        'noPageData'              => __( 'No page data available', 'opti-behavior' ),
                        'noVisitorData'           => __( 'No visitor data available', 'opti-behavior' ),
                        'dataWillAppear'          => __( 'Data will appear as visitors interact with your site.', 'opti-behavior' ),
                        'chartBrowsers'           => __( 'Browsers', 'opti-behavior' ),
                        'chartOperatingSystems'   => __( 'Operating Systems', 'opti-behavior' ),
                        'chartCountries'          => __( 'Countries', 'opti-behavior' ),
                        'chartReferrers'          => __( 'Referrers', 'opti-behavior' ),
                        'chartUserIntent'         => __( 'User Intent', 'opti-behavior' ),
                        'excludingSpam'           => __( 'Excluding Spam', 'opti-behavior' ),
                        'includingSpam'           => __( 'Including Spam', 'opti-behavior' ),
                        'spamExcludedTitle'       => __( 'Spam traffic is excluded from all statistics', 'opti-behavior' ),
                        'spamIncludedTitle'       => __( 'Spam traffic is included in all statistics', 'opti-behavior' ),
                        'viewUserProfile'         => __( 'View user profile', 'opti-behavior' ),
                        'errorLoadingData'        => __( 'Error loading data', 'opti-behavior' ),
                        'errorLoadingHeatmap'     => __( 'Error loading heatmap', 'opti-behavior' ),
                        'selectStartEndDates'     => __( 'Please select start and end dates.', 'opti-behavior' ),
                    ),
                )
            );
        }

        // Enqueue onboarding popup assets on the dashboard page when conditions are met.
        if ( strpos( $hook_suffix, 'opti-behavior-analytics' ) !== false ) {
            $opti_behavior_onboarding = Opti_Behavior_Onboarding::init();
            if ( $opti_behavior_onboarding->should_show() ) {
                $opti_behavior_onboarding->enqueue_assets();
            }
        }

        // Note: Recordings page styles are now enqueued by the PRO plugin
        // See: opti-behavior-pro/includes/class-opti-behavior-pro-core.php
    }

    /**
     * Ensure critical scripts are not delayed by Cloudflare Rocket Loader.
     *
     * @since 1.0.0
     * @param string $tag    Script tag HTML.
     * @param string $handle Script handle.
     * @param string $src    Script source URL.
     * @return string Modified script tag.
     */
    private function filter_script_loader_tag_impl( $tag, $handle, $src ) {
        if ( $handle === 'leaflet' || $handle === 'opti-behavior-leaflet-config' ) {
            if ( strpos( $tag, 'data-cfasync' ) === false ) {
                $tag = str_replace( '<script ', '<script data-cfasync="false" ', $tag );
            }
        }
        return $tag;
    }

    /**
     * Suppress third-party admin notices on plugin pages.
     *
     * This comprehensive implementation uses multiple techniques to remove ALL admin notices:
     * 1. Removes WordPress admin_notices and user_admin_notices hooks
     * 2. Removes all_admin_notices hooks
     * 3. CSS hiding for any notices that slip through
     * 4. JavaScript cleanup for dynamically added notices
     *
     * @since 1.0.0
     * @global array $wp_filter WordPress filter hooks.
     */
    private function suppress_admin_notices_impl() {
        if ( ! function_exists('get_current_screen') ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || empty($screen->id) ) { return; }

        // Check if we're on an opti-behavior page by looking for opti-behavior in the screen ID
        // This covers all variations: toplevel_page_opti-behavior-*, opti-behavior_page_opti-behavior-*, etc.
        if ( strpos( $screen->id, 'opti-behavior' ) === false &&
             strpos( $screen->id, 'opti_behavior' ) === false ) {
            return;
        }

        // TECHNIQUE 1: Remove all admin notice hooks
        $this->remove_all_admin_notice_hooks();

        // TECHNIQUE 2: Comprehensive CSS hiding - enqueue external CSS file
        wp_enqueue_style(
            'opti-behavior-admin-notices',
            OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/admin-notices.css',
            array(),
            // v8: the third-party "upgrade" notice suppressor no longer matches
            // this plugin's own `ob-*-upgrade-*` elements, so cached copies of v7
            // must not survive — they hide every Pro teaser in the modal.
            OPTI_BEHAVIOR_HEATMAP_VERSION . '-admin-notices-v8'
        );

        // TECHNIQUE 3: JavaScript cleanup for dynamically added notices
        // Enqueue notice cleanup script from external file
        wp_enqueue_script(
            'opti-behavior-notice-cleanup',
            OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/notice-cleanup.js',
            array( 'jquery' ), // Depends on jQuery
            OPTI_BEHAVIOR_HEATMAP_VERSION . '-v2', // v2: exclude ob-* prefixed elements from removal
            true // Load in footer
        );
    }

    /**
     * Remove all admin notice hooks from WordPress.
     *
     * This is the most effective method - prevents notices from being output at all.
     *
     * @since 1.0.0
     * @global array $wp_filter WordPress filter hooks.
     */
    private function remove_all_admin_notice_hooks() {
        global $wp_filter;

        // List of all admin notice hooks used by WordPress and plugins
        $notice_hooks = array(
            'admin_notices',
            'user_admin_notices',
            'network_admin_notices',
            'all_admin_notices',
        );

        foreach ( $notice_hooks as $hook ) {
            if ( isset( $wp_filter[$hook] ) ) {
                // Remove all callbacks except those from opti-behavior
                if ( is_object( $wp_filter[$hook] ) && method_exists( $wp_filter[$hook], 'callbacks' ) ) {
                    $callbacks = $wp_filter[$hook]->callbacks;
                } else {
                    $callbacks = isset( $wp_filter[$hook] ) ? $wp_filter[$hook] : array();
                }

                foreach ( $callbacks as $priority => $functions ) {
                    foreach ( $functions as $function ) {
                        // Keep only opti_behavior notices (if any)
                        $is_opti_behavior = false;

                        if ( is_array( $function['function'] ) ) {
                            $class_name = is_object( $function['function'][0] )
                                ? get_class( $function['function'][0] )
                                : $function['function'][0];

                            $class_lower = strtolower( $class_name );
                            if ( strpos( $class_lower, 'opti_behavior' ) !== false || strpos( $class_lower, 'opti-behavior' ) !== false ) {
                                $is_opti_behavior = true;
                            }
                        } elseif ( is_string( $function['function'] ) ) {
                            $func_lower = strtolower( $function['function'] );
                            if ( strpos( $func_lower, 'opti_behavior' ) !== false || strpos( $func_lower, 'opti-behavior' ) !== false ) {
                                $is_opti_behavior = true;
                            }
                        }

                        // Remove third-party notices
                        if ( ! $is_opti_behavior ) {
                            remove_action( $hook, $function['function'], $priority );
                        }
                    }
                }
            }
        }
    }

    /**
     * Determine the current trial banner display state.
     *
     * Returns an array with:
     *  - display: 'invite' | 'active' | 'expired' | 'hidden'
     *  - days_left: int (0 when not active)
     *  - expires_timestamp: int Unix timestamp (0 when not applicable)
     *  - download_url: string URL to download Pro
     *
     * @since 1.1.3
     * @return array Trial state data.
     */
    private function get_trial_banner_state() {
        $result = array(
            'display'           => 'hidden',
            'days_left'         => 0,
            'expires_timestamp' => 0,
            'download_url'      => '',
        );

        // If Pro plugin is active, check the manifest to decide banner visibility.
        // Only hide the banner if the user has a valid paid license (pro/premium/enterprise).
        // If their license/trial has expired, they should still see the banner.
        if ( function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active() ) {
            if ( class_exists( 'Opti_Behavior_Manifest_Manager' ) ) {
                $manifest_mgr = Opti_Behavior_Manifest_Manager::get_instance();

                // If trial expired, show expired banner so user can upgrade.
                // Guard with method_exists() for backward compatibility with older Pro versions.
                if ( method_exists( $manifest_mgr, 'is_trial_expired' ) && $manifest_mgr->is_trial_expired() ) {
                    $result['display']      = 'expired';
                    $result['download_url'] = $this->get_pro_download_url_for_banner();
                    return $result;
                }

                // If manifest confirms active Pro features, no banner needed.
                if ( method_exists( $manifest_mgr, 'is_pro' ) && $manifest_mgr->is_pro() ) {
                    return $result; // hidden
                }
            } else {
                // Manifest manager not available but Pro constant is defined.
                // Assume Pro is legitimately active; hide banner.
                return $result; // hidden
            }

            // Pro plugin active but no valid pro manifest (e.g. license expired,
            // free-tier fallback). Fall through to free-only logic below.
        }

        // --- Free-only mode: decide banner state ---

        $trial_started = (int) get_option( 'opti_behavior_pro_trial_started', 0 );

        // Trial never started
        if ( 0 === $trial_started ) {
            // Check if user dismissed the banner
            $dismissed = (int) get_option( 'opti_behavior_pro_trial_banner_dismissed', 0 );
            if ( $dismissed > 0 ) {
                // Re-show after 30 days
                if ( ( time() - $dismissed ) < ( 30 * DAY_IN_SECONDS ) ) {
                    return $result; // hidden
                }
            }
            $result['display']      = 'invite';
            $result['download_url'] = $this->get_pro_download_url_for_banner();
            return $result;
        }

        // Trial was started; check expiry
        $expires   = $trial_started + ( 180 * DAY_IN_SECONDS );
        $days_left = max( 0, (int) ceil( ( $expires - time() ) / DAY_IN_SECONDS ) );

        if ( $days_left > 0 ) {
            $result['display']           = 'active';
            $result['days_left']         = $days_left;
            $result['expires_timestamp'] = $expires;
            $result['download_url']      = $this->get_pro_download_url_for_banner();
        } else {
            // Check if dismissed
            $dismissed = (int) get_option( 'opti_behavior_pro_trial_banner_dismissed', 0 );
            if ( $dismissed > 0 && ( time() - $dismissed ) < ( 30 * DAY_IN_SECONDS ) ) {
                return $result; // hidden
            }
            $result['display']      = 'expired';
            $result['download_url'] = $this->get_pro_download_url_for_banner();
        }

        return $result;
    }

    /**
     * Build the Pro download URL for the trial banner (lightweight version).
     *
     * Uses cached access code when available, falls back to URL without code.
     *
     * @since 1.1.3
     * @return string Download URL.
     */
    private function get_pro_download_url_for_banner() {
        $current_user = wp_get_current_user();
        $cache_key    = 'ob_dl_access_' . md5( $current_user->user_login . site_url() );
        $access_code  = get_transient( $cache_key );

        return add_query_arg(
            array(
                'site_url'    => rawurlencode( site_url() ),
                'username'    => rawurlencode( $current_user->user_login ),
                'email'       => rawurlencode( $current_user->user_email ),
                'access_code' => rawurlencode( $access_code ? $access_code : '' ),
            ),
            'https://optiuser.com/opti-behavior/ob-download-pro/'
        );
    }
}

