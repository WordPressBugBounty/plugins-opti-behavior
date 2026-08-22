<?php
/**
 * Settings Views Trait
 *
 * Extracts the settings page rendering and persistence logic from the dashboard class,
 * preserving the public method signatures via wrapper delegations in the class.
 *
 * @package opti-behavior
 * @version 1.0.3 - Added PRO restriction for Individual Display option
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Views Trait
 *
 * Provides settings page rendering methods.
 *
 * @since 1.0.0
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
if ( ! trait_exists( 'opti_behavior_Settings_Views_Trait' ) ) {
	trait Opti_Behavior_Settings_Views_Trait {
		/**
		 * Render settings page implementation.
		 *
		 * @since 1.0.0
		 */
		private function render_settings_impl() {
            // Handle form submission
            if (isset($_POST['opti_behavior_settings_submit'])) {
                $this->handle_settings_save_impl();
            }
            // Handle Debug Settings Save
            if (isset($_POST['opti_behavior_debug_settings_submit'])) {
                if (!isset($_POST['opti_behavior_debug_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_debug_nonce'] ) ), 'opti_behavior_debug_settings')) { wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) ); }
                if (!current_user_can('manage_options')) { wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) ); }
                $this->handle_debug_settings_save_impl();
            }
            // Handle Clear Debug Log
            if (isset($_POST['opti_behavior_clear_debug_log'])) {
                if (!isset($_POST['opti_behavior_clear_log_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_clear_log_nonce'] ) ), 'opti_behavior_clear_log')) { wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) ); }
                if (!current_user_can('manage_options')) { wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) ); }
                $this->handle_clear_debug_log_impl();
            }
            // Handle Manual Data Recovery
            if (isset($_POST['opti_behavior_manual_recovery'])) {
                if (!isset($_POST['opti_behavior_recovery_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_recovery_nonce'] ) ), 'opti_behavior_manual_recovery')) { wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) ); }
                if (!current_user_can('manage_options')) { wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) ); }
                $this->handle_manual_recovery_impl();
            }
            // Handle Danger Zone: Delete All Analytics Data
            if (isset($_POST['opti_behavior_delete_all_data'])) {
                if (!isset($_POST['opti_behavior_delete_all_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_delete_all_nonce'] ) ), 'opti_behavior_delete_all')) { wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) ); }
                if (!current_user_can('manage_options')) { wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) ); }
                $this->delete_all_analytics_data();
                add_settings_error( 'opti_behavior_settings', 'data-deleted', esc_html__( 'All analytics data has been permanently deleted.', 'opti-behavior' ), 'success' );
                set_transient( 'settings_errors', get_settings_errors(), 30 );
            }
            // Handle Delete Logs in Date Range
            if (isset($_POST['opti_behavior_delete_range'])) {
                if (!isset($_POST['opti_behavior_delete_range_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_delete_range_nonce'] ) ), 'opti_behavior_delete_range')) { wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) ); }
                if (!current_user_can('manage_options')) { wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) ); }
                $start = sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) );
                $end   = sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) );
                $this->delete_logs_in_date_range($start, $end);
                add_settings_error( 'opti_behavior_settings', 'range-deleted', esc_html__( 'Analytics data in the selected date range has been deleted.', 'opti-behavior' ), 'success' );
                set_transient( 'settings_errors', get_settings_errors(), 30 );
            }
            // Handle Frontend Stats Bar Settings Save
            if (isset($_POST['opti_behavior_frontend_stats_bar_submit'])) {
                $this->handle_frontend_stats_bar_save_impl();
            }
            // Handle Smart Insights notification settings and personal recovery actions.
            if (isset($_POST['opti_behavior_smart_insights_notifications_submit']) || isset($_POST['opti_behavior_smart_insights_show_again']) || isset($_POST['opti_behavior_smart_insights_compact_side']) || isset($_POST['opti_behavior_smart_insights_reset_seen'])) {
                $this->handle_smart_insights_notifications_save_impl();
            }
            // Handle Traffic Classification Settings Save
            if (isset($_POST['opti_behavior_traffic_settings_submit'])) {
                $this->handle_traffic_settings_save_impl();
            }
            // Handle Admin Tracking Settings Save
            if (isset($_POST['opti_behavior_logged_in_tracking_submit'])) {
                $this->handle_logged_in_tracking_save_impl();
            }
            // Handle User Intent Rules Settings Save
            if (isset($_POST['opti_behavior_intent_rules_submit'])) {
                $this->handle_intent_rules_save_impl();
            }
            // Handle Uninstall Settings Save
            if (isset($_POST['save_uninstall_settings'])) {
                if (!isset($_POST['opti_behavior_uninstall_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_uninstall_nonce'] ) ), 'opti_behavior_uninstall_settings')) { wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) ); }
                if (!current_user_can('manage_options')) { wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) ); }
                $delete_on_uninstall = isset($_POST['delete_on_uninstall']) ? true : false;
                update_option('opti_behavior_delete_on_uninstall', $delete_on_uninstall);
                add_settings_error('opti_behavior_settings', 'settings_updated', esc_html__( 'Uninstall settings saved successfully.', 'opti-behavior' ), 'success');
            }
            // Handle File Storage Settings Save
            if (isset($_POST['opti_behavior_file_storage_submit'])) {
                if (!isset($_POST['opti_behavior_file_storage_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_file_storage_nonce'] ) ), 'opti_behavior_file_storage_settings')) { wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) ); }
                if (!current_user_can('manage_options')) { wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) ); }
                $this->handle_file_storage_settings_save_impl();
            }
            // Handle Language Settings Save
            if (isset($_POST['opti_behavior_language_submit'])) {
                if (!isset($_POST['opti_behavior_language_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_language_nonce'] ) ), 'opti_behavior_language_settings')) { wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) ); }
                if (!current_user_can('manage_options')) { wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) ); }
                $this->handle_language_settings_save_impl();
            }
            // Handle Privacy & GDPR Settings Save
            if (isset($_POST['opti_behavior_privacy_settings_submit'])) {
                if (!isset($_POST['opti_behavior_privacy_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_privacy_nonce'] ) ), 'opti_behavior_privacy_settings')) { wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) ); }
                if (!current_user_can('manage_options')) { wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) ); }
                $this->handle_privacy_settings_save_impl();
            }
            // Handle Consent Banner Settings Save
            if (isset($_POST['opti_behavior_consent_banner_submit'])) {
                if (!isset($_POST['opti_behavior_consent_banner_nonce']) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_consent_banner_nonce'] ) ), 'opti_behavior_consent_banner_settings')) { wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) ); }
                if (!current_user_can('manage_options')) { wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) ); }
                $this->handle_consent_banner_settings_save_impl();
            }

            // Get current settings
            $settings = $this->get_current_settings_impl();
            ?>
            <div class="wrap opti-behavior-settings-page">
                <?php $this->render_pro_trial_banner(); ?>
                <?php $this->render_settings_header_impl(); ?>
                <?php if ( function_exists( 'opti_behavior_pro_sodium_banner' ) ) { opti_behavior_pro_sodium_banner(); } ?>
                <?php
                // Display settings errors (for add_settings_error() calls)
                // Note: WordPress automatically displays admin_notices on admin pages
                settings_errors('opti_behavior_settings');
                ?>
                <?php $this->render_settings_content_impl($settings); ?>
            </div>
            <?php
            // Note: Settings page styles and scripts are enqueued in enqueue_dashboard_assets_impl()
        }

        /**
         * Impl: settings page header
         */
        private function render_settings_header_impl() {
            ?>
            <div class="settings-header">
                <div class="settings-header-content">
                    <div class="settings-title-section">
                        <div class="settings-icon"><i data-lucide="settings"></i></div>
                        <div class="settings-title-text">
                            <h1 class="settings-title"><?php esc_html_e( 'Settings', 'opti-behavior' ); ?></h1>
                            <div class="settings-subtitle"><?php esc_html_e( 'Configure opti-behavior: Heatmap, tracking, Data and display options', 'opti-behavior' ); ?></div>
                        </div>
                    </div>
                    <?php if ( class_exists( 'Opti_Behavior_Gate' ) ) :
                        // Show badge whenever Pro plugin is installed (Gate class loaded), regardless of bucket.
                        // Surfaces PRO / FREE / BLOCKED so user always sees current license status.
                        $ob_bucket = Opti_Behavior_Gate::bucket();
                        $ob_state  = Opti_Behavior_Gate::state();

                        // Bucket pill color (high-contrast on purple gradient header)
                        if ( 'pro' === $ob_bucket ) {
                            $ob_pill_bg    = '#10b981'; // emerald-500
                            $ob_pill_label = 'PRO';
                            $ob_pill_dot   = '#34d399';
                        } elseif ( 'absent' === $ob_bucket || 'free' === $ob_bucket ) {
                            $ob_pill_bg    = '#f59e0b'; // amber-500
                            $ob_pill_label = 'FREE';
                            $ob_pill_dot   = '#fbbf24';
                        } else {
                            $ob_pill_bg    = '#ef4444'; // red-500
                            $ob_pill_label = 'BLOCKED';
                            $ob_pill_dot   = '#f87171';
                        }

                        // State human label
                        $ob_state_labels = array(
                            'active'       => 'Active',
                            'grace_period' => 'Grace Period',
                            'expired'      => 'Expired',
                            'none'         => 'No License',
                            'unknown'      => 'Not Verified',
                        );
                        $ob_state_label = isset( $ob_state_labels[ $ob_state ] )
                            ? $ob_state_labels[ $ob_state ]
                            : ucwords( str_replace( '_', ' ', $ob_state ) );

                        $ob_license_url = admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=license' );
                    ?>
                    <div class="settings-license-badge" style="display:flex;align-items:center;gap:8px;margin-left:auto;padding:6px 8px 6px 14px;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.22);border-radius:999px;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);box-shadow:0 1px 2px rgba(0,0,0,0.08);">
                        <span style="display:inline-flex;align-items:center;gap:6px;">
                            <span aria-hidden="true" style="width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr( $ob_pill_dot ); ?>;box-shadow:0 0 0 3px rgba(255,255,255,0.18);"></span>
                            <span style="font-size:12px;font-weight:700;letter-spacing:.6px;color:#ffffff;text-transform:uppercase;line-height:1;">
                                <?php echo esc_html( $ob_pill_label ); ?>
                            </span>
                            <span aria-hidden="true" style="width:1px;height:14px;background:rgba(255,255,255,0.28);"></span>
                            <span style="font-size:13px;font-weight:500;color:rgba(255,255,255,0.92);line-height:1;">
                                <?php echo esc_html( $ob_state_label ); ?>
                            </span>
                        </span>
                        <a href="<?php echo esc_url( $ob_license_url ); ?>" style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;color:#ffffff;text-decoration:none;background:<?php echo esc_attr( $ob_pill_bg ); ?>;padding:5px 10px;border-radius:999px;white-space:nowrap;line-height:1;transition:transform .12s ease,box-shadow .12s ease;box-shadow:0 1px 2px rgba(0,0,0,0.18);" onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 3px 8px rgba(0,0,0,0.22)';" onmouseout="this.style.transform='';this.style.boxShadow='0 1px 2px rgba(0,0,0,0.18)';">
                            <?php esc_html_e( 'View License', 'opti-behavior' ); ?>
                            <span aria-hidden="true" style="font-size:13px;line-height:1;">&rarr;</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }

        /**
         * Impl: settings content
         */
        private function render_settings_content_impl($settings) {
            // Get active tab from URL or default to first tab (privacy-gdpr)
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameter used for tab navigation (read-only operation)
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash not needed for sanitize_text_field
            $active_tab = isset($_GET['settings_tab']) ? sanitize_text_field($_GET['settings_tab']) : 'privacy-gdpr';
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
            ?>
            <div class="settings-content">
                <div class="opti-behavior-settings-container">
                    <!-- Vertical Tab Navigation -->
                    <div class="settings-tab-nav">
                        <a href="?page=opti-behavior-settings&settings_tab=privacy-gdpr"
                           class="settings-tab-link <?php echo $active_tab === 'privacy-gdpr' ? 'active' : ''; ?>">
                            <span class="tab-icon"><i data-lucide="shield"></i></span>
                            <span class="tab-label"><?php esc_html_e( 'Privacy & GDPR', 'opti-behavior' ); ?></span>
                        </a>
                        <a href="?page=opti-behavior-settings&settings_tab=traffic-behavior"
                           class="settings-tab-link <?php echo $active_tab === 'traffic-behavior' ? 'active' : ''; ?>">
                            <span class="tab-icon"><i data-lucide="bar-chart-3"></i></span>
                            <span class="tab-label"><?php esc_html_e( 'Traffic & Behavior', 'opti-behavior' ); ?></span>
                        </a>
                        <a href="?page=opti-behavior-settings&settings_tab=smart-insights"
                           class="settings-tab-link <?php echo $active_tab === 'smart-insights' ? 'active' : ''; ?>">
                            <span class="tab-icon"><i data-lucide="lightbulb"></i></span>
                            <span class="tab-label"><?php esc_html_e( 'Smart Insights', 'opti-behavior' ); ?></span>
                        </a>
                        <a href="?page=opti-behavior-settings&settings_tab=data-collection"
                           class="settings-tab-link <?php echo $active_tab === 'data-collection' ? 'active' : ''; ?>">
                            <span class="tab-icon"><i data-lucide="flame"></i></span>
                            <span class="tab-label"><?php esc_html_e( 'Heatmap Settings', 'opti-behavior' ); ?></span>
                        </a>
                        <?php if ( opti_behavior_pro_active() ) : ?>
                        <a href="?page=opti-behavior-settings&settings_tab=recordings"
                           class="settings-tab-link <?php echo $active_tab === 'recordings' ? 'active' : ''; ?>">
                            <span class="tab-icon"><i data-lucide="video"></i></span>
                            <span class="tab-label"><?php esc_html_e( 'Session Recordings', 'opti-behavior' ); ?></span>
                        </a>
                        <?php endif; ?>
                        <a href="?page=opti-behavior-settings&settings_tab=frontend-stats-bar"
                           class="settings-tab-link <?php echo $active_tab === 'frontend-stats-bar' ? 'active' : ''; ?>">
                            <span class="tab-icon"><i data-lucide="monitor"></i></span>
                            <span class="tab-label"><?php esc_html_e( 'Frontend Stats Bar', 'opti-behavior' ); ?></span>
                        </a>
                        <a href="?page=opti-behavior-settings&settings_tab=scheduled-reports"
                           class="settings-tab-link <?php echo $active_tab === 'scheduled-reports' ? 'active' : ''; ?>">
                            <span class="tab-icon"><i data-lucide="calendar-clock"></i></span>
                            <span class="tab-label"><?php esc_html_e( 'Scheduled Reports', 'opti-behavior' ); ?></span>
                        </a>
                        <?php if ( opti_behavior_pro_active() ) : ?>
                        <a href="?page=opti-behavior-settings&settings_tab=license"
                           class="settings-tab-link <?php echo $active_tab === 'license' ? 'active' : ''; ?>">
                            <span class="tab-icon"><i data-lucide="key"></i></span>
                            <span class="tab-label"><?php esc_html_e( 'License & Quota', 'opti-behavior' ); ?></span>
                        </a>
                        <?php endif; ?>
                        <a href="?page=opti-behavior-settings&settings_tab=storage-stats"
                           class="settings-tab-link <?php echo $active_tab === 'storage-stats' ? 'active' : ''; ?>">
                            <span class="tab-icon"><i data-lucide="hard-drive"></i></span>
                            <span class="tab-label"><?php esc_html_e( 'Storage Stats', 'opti-behavior' ); ?></span>
                        </a>
                        <a href="?page=opti-behavior-settings&settings_tab=language"
                           class="settings-tab-link <?php echo $active_tab === 'language' ? 'active' : ''; ?>">
                            <span class="tab-icon"><i data-lucide="languages"></i></span>
                            <span class="tab-label"><?php esc_html_e( 'Language', 'opti-behavior' ); ?></span>
                        </a>
                        <a href="?page=opti-behavior-settings&settings_tab=debug-logging"
                           class="settings-tab-link <?php echo $active_tab === 'debug-logging' ? 'active' : ''; ?>">
                            <span class="tab-icon"><i data-lucide="bug"></i></span>
                            <span class="tab-label"><?php esc_html_e( 'Debug & Logging', 'opti-behavior' ); ?></span>
                        </a>
                        <a href="?page=opti-behavior-settings&settings_tab=danger-zone"
                           class="settings-tab-link <?php echo $active_tab === 'danger-zone' ? 'active' : ''; ?>">
                            <span class="tab-icon"><i data-lucide="alert-triangle"></i></span>
                            <span class="tab-label"><?php esc_html_e( 'Danger Zone', 'opti-behavior' ); ?></span>
                        </a>
                    </div>

                    <!-- Tab Content Area -->
                    <div class="settings-tab-content">
                        <?php
                        // Render active tab content
                        switch ($active_tab) {
                            case 'privacy-gdpr':
                                $this->render_privacy_gdpr_tab();
                                break;
                            case 'data-collection':
                                $this->render_data_collection_tab($settings);
                                break;
                            case 'recordings':
                                if ( opti_behavior_pro_active() ) {
                                    /**
                                     * Allow Pro plugin to render the Session Recordings settings tab.
                                     *
                                     * @since 1.0.0
                                     */
                                    do_action( 'opti_behavior_render_recordings_settings_tab' );
                                } else {
                                    $this->render_privacy_gdpr_tab();
                                }
                                break;
                            case 'traffic-behavior':
                            case 'dashboard-settings': // backward-compat alias
                                $this->render_dashboard_settings_tab();
                                break;
                            case 'smart-insights':
                                $this->render_smart_insights_notifications_tab();
                                break;
                            case 'frontend-stats-bar':
                                $this->render_frontend_stats_bar_tab();
                                break;
                            case 'scheduled-reports':
                                $this->render_scheduled_reports_tab();
                                break;
                            case 'license':
                                if ( has_action( 'opti_behavior_render_license_quota_tab' ) ) {
                                    /**
                                     * Allow Pro plugin to render the License & Quota settings tab.
                                     *
                                     * Keep this recovery UI available even when the Pro feature
                                     * gate is currently closed, because this tab validates the
                                     * key/API state and refreshes the signed feature manifest.
                                     *
                                     * @since 1.0.0
                                     */
                                    do_action( 'opti_behavior_render_license_quota_tab' );
                                } else {
                                    $this->render_license_fallback_tab();
                                }
                                break;
                            case 'storage-stats':
                                $this->render_storage_stats_tab();
                                break;
                            case 'language':
                                $this->render_language_tab();
                                break;
                            case 'debug-logging':
                                $this->render_debug_logging_tab();
                                break;
                            case 'danger-zone':
                                $this->render_danger_zone_tab();
                                break;
                            default:
                                $this->render_privacy_gdpr_tab();
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * Render Data Collection Tab
         */
        private function render_data_collection_tab($settings) {
            $tooltips = opti_behavior_get_settings_tooltips();
            ?>
            <form method="post" action="" class="opti-behavior-settings-form">
                <?php wp_nonce_field('opti_behavior_settings_save', 'opti_behavior_settings_nonce'); ?>

                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon"><i data-lucide="flame"></i></span>
                            <?php esc_html_e( 'Heatmap Settings', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description"><?php esc_html_e( 'Configure how heatmap data is collected, tracked, and displayed', 'opti-behavior' ); ?></p>
                    </div>

                    <div class="settings-grid">
                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Tracking Accuracy', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['tracking_accuracy']['title'], $tooltips['tracking_accuracy']['content'], $tooltips['tracking_accuracy']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Controls which screen sizes are tracked for heatmap data', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="accuracy" value="high" <?php checked($settings['accuracy'], 'high'); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'High Accuracy', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'PC: 700-1920px | Mobile: 320-600px', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="accuracy" value="standard" <?php checked($settings['accuracy'], 'standard'); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'Standard', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'PC: 600-2560px | Mobile: 280-768px', 'opti-behavior' ); ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Non-Singular Pages', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['non_singular']['title'], $tooltips['non_singular']['content'], $tooltips['non_singular']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Track category pages, archives, and other non-singular pages', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="non_singular" value="report"
                                               <?php checked($settings['non_singular'], 'report'); ?>>
                                        <span class="radio-label">
                                            <?php esc_html_e( 'Track All Pages', 'opti-behavior' ); ?>
                                        </span>
                                        <span class="radio-description"><?php esc_html_e( 'Include category and archive pages', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="non_singular" value="no_report" <?php checked($settings['non_singular'], 'no_report'); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'Posts & Pages Only', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Track only individual posts and pages', 'opti-behavior' ); ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Ajax Delay Time', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['ajax_delay']['title'], $tooltips['ajax_delay']['content'], $tooltips['ajax_delay']['simple'], $tooltips['ajax_delay']['example'], array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Delay before sending tracking data to server', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="input-with-suffix">
                                    <input type="number" name="ajax_delay" value="<?php echo esc_attr($settings['ajax_delay']); ?>" min="1000" max="10000" step="500" class="number-input">
                                    <span class="input-suffix"><?php esc_html_e( 'ms', 'opti-behavior' ); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Drawing Points Limit', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['drawing_points']['title'], $tooltips['drawing_points']['content'], $tooltips['drawing_points']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Maximum number of data points to display on heatmaps', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="drawing_points" value="unlimited" <?php checked($settings['drawing_points'], 'unlimited'); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'Unlimited', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Show all data points', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="drawing_points" value="10000" <?php checked($settings['drawing_points'], '10000'); ?>>
                                        <span class="radio-label"><?php esc_html_e( '10,000 Points', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'High detail, good performance', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="drawing_points" value="3000" <?php checked($settings['drawing_points'], '3000'); ?>>
                                        <span class="radio-label"><?php esc_html_e( '3,000 Points', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Balanced detail and speed', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="drawing_points" value="1000" <?php checked($settings['drawing_points'], '1000'); ?>>
                                        <span class="radio-label"><?php esc_html_e( '1,000 Points', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Fast loading, basic detail', 'opti-behavior' ); ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Count Bar Display', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['count_bar']['title'], $tooltips['count_bar']['content'], $tooltips['count_bar']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Show interaction count information on heatmaps', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="toggle-switch">
                                    <input type="checkbox" name="count_bar" value="show" <?php checked($settings['count_bar'], 'show'); ?> id="count_bar_toggle">
                                    <label for="count_bar_toggle" class="toggle-label">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'URL Hash Handling', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['url_hash']['title'], $tooltips['url_hash']['content'], $tooltips['url_hash']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'How to handle URLs with hash fragments (#section)', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="url_hash" value="integrated" <?php checked($settings['url_hash'], 'integrated'); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'Integrated Display', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Combine data from all hash variations', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="url_hash" value="individual"
                                               <?php checked($settings['url_hash'], 'individual'); ?>>
                                        <span class="radio-label">
                                            <?php esc_html_e( 'Individual Display', 'opti-behavior' ); ?>
                                        </span>
                                        <span class="radio-description"><?php esc_html_e( 'Separate data for each hash variation', 'opti-behavior' ); ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Save Button -->
                    <div class="settings-actions">
                        <button type="submit" name="opti_behavior_settings_submit" class="btn-save">
                            <span class="btn-icon"><i data-lucide="save"></i></span>
                            <?php esc_html_e( 'Save Settings', 'opti-behavior' ); ?>
                        </button>
                        <div class="save-status" id="save-status"></div>
                    </div>
                </div>
            </form>
            <?php
        }

        /**
         * Render Display Options Tab
         */
        private function render_display_options_tab($settings) {
            ?>
            <form method="post" action="" class="opti-behavior-settings-form">
                <?php wp_nonce_field('opti_behavior_settings_save', 'opti_behavior_settings_nonce'); ?>

                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon">🎨</span>
                            <?php esc_html_e( 'Display Options', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description"><?php esc_html_e( 'Customize how heatmaps are displayed and rendered', 'opti-behavior' ); ?></p>
                    </div>

                    <div class="settings-grid">
                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Drawing Points Limit', 'opti-behavior' ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Maximum number of data points to display on heatmaps', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="drawing_points" value="unlimited" <?php checked($settings['drawing_points'], 'unlimited'); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'Unlimited', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Show all data points', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="drawing_points" value="10000" <?php checked($settings['drawing_points'], '10000'); ?>>
                                        <span class="radio-label"><?php esc_html_e( '10,000 Points', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'High detail, good performance', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="drawing_points" value="3000" <?php checked($settings['drawing_points'], '3000'); ?>>
                                        <span class="radio-label"><?php esc_html_e( '3,000 Points', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Balanced detail and speed', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="drawing_points" value="1000" <?php checked($settings['drawing_points'], '1000'); ?>>
                                        <span class="radio-label"><?php esc_html_e( '1,000 Points', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Fast loading, basic detail', 'opti-behavior' ); ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Count Bar Display', 'opti-behavior' ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Show interaction count information on heatmaps', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="toggle-switch">
                                    <input type="checkbox" name="count_bar" value="show" <?php checked($settings['count_bar'], 'show'); ?> id="count_bar_toggle">
                                    <label for="count_bar_toggle" class="toggle-label">
                                        <span class="toggle-slider"></span>
                                        <span class="toggle-text"><?php esc_html_e( 'Show count bar', 'opti-behavior' ); ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'URL Hash Handling', 'opti-behavior' ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'How to handle URLs with hash fragments (#section)', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="url_hash" value="integrated" <?php checked($settings['url_hash'], 'integrated'); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'Integrated Display', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Combine data from all hash variations', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="url_hash" value="individual"
                                               <?php checked($settings['url_hash'], 'individual'); ?>>
                                        <span class="radio-label">
                                            <?php esc_html_e( 'Individual Display', 'opti-behavior' ); ?>
                                        </span>
                                        <span class="radio-description"><?php esc_html_e( 'Separate data for each hash variation', 'opti-behavior' ); ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Save Button -->
                    <div class="settings-actions">
                        <button type="submit" name="opti_behavior_settings_submit" class="btn-save">
                            <span class="btn-icon"><i data-lucide="save"></i></span>
                            <?php esc_html_e( 'Save Settings', 'opti-behavior' ); ?>
                        </button>
                        <div class="save-status" id="save-status"></div>
                    </div>
                </div>
            </form>
            <?php
        }

        /**
         * Render Storage Stats Tab
         */
        private function render_storage_stats_tab() {
            $this->render_storage_stats_impl();
        }

        /**
         * Render Debug Logging Tab
         */
        private function render_debug_logging_tab() {
            $this->render_debug_settings_impl();
        }

        /**
         * Render Danger Zone Tab
         */
        /**
         * Render License & Quota fallback tab
         * Shown when PRO plugin files are present but Pro core is not fully initialized
         * (e.g. feature manifest validation failed)
         */
        private function render_license_fallback_tab() {
            ?>
            <div class="opti-behavior-settings-form">
                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon"><i data-lucide="key"></i></span>
                            <?php esc_html_e( 'License & Quota', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description"><?php esc_html_e( 'Your current license and registration status', 'opti-behavior' ); ?></p>
                    </div>
                    <div class="recordings-upgrade-content" style="margin-top: 20px;">
                        <div class="upgrade-card">
                            <div class="upgrade-icon">
                                <i data-lucide="alert-circle" style="width: 48px; height: 48px; color: #d97706;"></i>
                            </div>
                            <h2 class="upgrade-title"><?php esc_html_e( 'Pro Features Not Initialized', 'opti-behavior' ); ?></h2>
                            <p class="upgrade-description">
                                <?php esc_html_e( 'The Pro plugin is installed but could not fully initialize. This usually means the feature manifest has not been validated yet.', 'opti-behavior' ); ?>
                                <br />
                                <?php esc_html_e( 'Please ensure your site is registered and can reach the API server. Try refreshing the page or re-activating the Pro plugin.', 'opti-behavior' ); ?>
                            </p>
                            <div class="upgrade-cta">
                                <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button button-primary button-hero">
                                    <i data-lucide="refresh-cw"></i>
                                    <?php esc_html_e( 'Go to Plugins', 'opti-behavior' ); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }

        private function render_danger_zone_tab() {
            $tooltips = opti_behavior_get_settings_tooltips();
            ?>

            <div class="opti-behavior-settings-form opti-behavior-settings-form-danger">
                <div class="danger-zone-tabs">
                    <button type="button" class="danger-zone-tab active" data-danger-tab="full-reset">
                        <i data-lucide="alert-triangle"></i> <?php esc_html_e( 'Full Reset', 'opti-behavior' ); ?>
                    </button>
                    <button type="button" class="danger-zone-tab" data-danger-tab="date-range">
                        <i data-lucide="calendar-x"></i> <?php esc_html_e( 'Date Range', 'opti-behavior' ); ?>
                    </button>
                    <button type="button" class="danger-zone-tab" data-danger-tab="smart-cleanup">
                        <i data-lucide="shield-alert"></i> <?php esc_html_e( 'Smart Cleanup', 'opti-behavior' ); ?>
                    </button>
                    <button type="button" class="danger-zone-tab" data-danger-tab="auto-schedule">
                        <i data-lucide="clock"></i> <?php esc_html_e( 'Auto Schedule', 'opti-behavior' ); ?>
                    </button>
                    <button type="button" class="danger-zone-tab" data-danger-tab="uninstall">
                        <i data-lucide="trash-2"></i> <?php esc_html_e( 'Uninstall', 'opti-behavior' ); ?>
                    </button>
                </div>

                <div class="danger-tab-panel active" data-danger-tab="full-reset">
                <div class="settings-section settings-section-danger">
                    <div class="section-header">
                        <h3 class="section-title section-title-danger">
                            <span class="section-icon"><i data-lucide="alert-triangle"></i></span>
                            <?php esc_html_e( 'Danger Zone - Data Reset', 'opti-behavior' ); ?>
                            <?php opti_behavior_tooltip_e( $tooltips['delete_all_data']['title'], $tooltips['delete_all_data']['content'], $tooltips['delete_all_data']['simple'], '', array( 'position' => 'bottom', 'theme' => 'danger' ) ); ?>
                        </h3>
                        <p class="section-description">
                            <?php esc_html_e( 'Permanently delete analytics data from your database. These actions cannot be undone.', 'opti-behavior' ); ?>
                        </p>
                    </div>

                    <div class="danger-warning-box">
                        <p class="danger-warning-text"><strong><i data-lucide="alert-triangle"></i> <?php esc_html_e( 'Warning:', 'opti-behavior' ); ?></strong> <?php esc_html_e( 'Select the data categories you want to delete. All options are selected by default.', 'opti-behavior' ); ?></p>
                        <div class="danger-categories-toggle">
                            <a href="#" id="opti-behavior-toggle-all-categories"><?php esc_html_e( 'Deselect All', 'opti-behavior' ); ?></a>
                            <span id="opti-behavior-danger-total-size" class="danger-total-size" aria-live="polite"></span>
                        </div>
                        <div class="danger-categories-list">
                            <label class="condition-row danger-category-row">
                                <input type="checkbox" class="condition-check danger-category-check" data-category="sessions" checked>
                                <span class="condition-label"><strong><?php esc_html_e( 'Sessions & Pageviews', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'Sessions, pageviews, daily stats', 'opti-behavior' ); ?> <span class="danger-category-count">(6 <?php esc_html_e( 'tables', 'opti-behavior' ); ?>)</span> <span class="danger-category-size" data-category="sessions"></span></span>
                            </label>
                            <label class="condition-row danger-category-row">
                                <input type="checkbox" class="condition-check danger-category-check" data-category="visitors" checked>
                                <span class="condition-label"><strong><?php esc_html_e( 'Visitors', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'Unique visitors, bot visits', 'opti-behavior' ); ?> <span class="danger-category-count">(2 <?php esc_html_e( 'tables', 'opti-behavior' ); ?>)</span> <span class="danger-category-size" data-category="visitors"></span></span>
                            </label>
                            <label class="condition-row danger-category-row">
                                <input type="checkbox" class="condition-check danger-category-check" data-category="events" checked>
                                <span class="condition-label"><strong><?php esc_html_e( 'Events & Interactions', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'Click events, scrolls, movements', 'opti-behavior' ); ?> <span class="danger-category-count">(1 <?php esc_html_e( 'table', 'opti-behavior' ); ?> + <?php esc_html_e( 'files', 'opti-behavior' ); ?>)</span> <span class="danger-category-size" data-category="events"></span></span>
                            </label>
                            <label class="condition-row danger-category-row">
                                <input type="checkbox" class="condition-check danger-category-check" data-category="heatmaps" checked>
                                <span class="condition-label"><strong><?php esc_html_e( 'Heatmap Data', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'Heatmap page snapshots', 'opti-behavior' ); ?> <span class="danger-category-count">(1 <?php esc_html_e( 'table', 'opti-behavior' ); ?> + <?php esc_html_e( 'files', 'opti-behavior' ); ?>)</span> <span class="danger-category-size" data-category="heatmaps"></span></span>
                            </label>
                            <label class="condition-row danger-category-row">
                                <input type="checkbox" class="condition-check danger-category-check" data-category="recordings" checked>
                                <span class="condition-label"><strong><?php esc_html_e( 'Session Recordings', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'Replay data and files', 'opti-behavior' ); ?> <span class="danger-category-count">(1 <?php esc_html_e( 'table', 'opti-behavior' ); ?> + <?php esc_html_e( 'files', 'opti-behavior' ); ?>)</span> <span class="danger-category-size" data-category="recordings"></span></span>
                            </label>
                            <label class="condition-row danger-category-row">
                                <input type="checkbox" class="condition-check danger-category-check" data-category="traffic" checked>
                                <span class="condition-label"><strong><?php esc_html_e( 'Traffic Sources', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'Referrers, UTM, outbound clicks', 'opti-behavior' ); ?> <span class="danger-category-count">(2 <?php esc_html_e( 'tables', 'opti-behavior' ); ?>)</span> <span class="danger-category-size" data-category="traffic"></span></span>
                            </label>
                            <label class="condition-row danger-category-row">
                                <input type="checkbox" class="condition-check danger-category-check" data-category="errors" checked>
                                <span class="condition-label"><strong><?php esc_html_e( 'Error Tracking', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'JS errors, broken links, friction, performance', 'opti-behavior' ); ?> <span class="danger-category-count">(5 <?php esc_html_e( 'tables', 'opti-behavior' ); ?>)</span> <span class="danger-category-size" data-category="errors"></span></span>
                            </label>
                            <label class="condition-row danger-category-row">
                                <input type="checkbox" class="condition-check danger-category-check" data-category="smart_insights" checked>
                                <span class="condition-label"><strong><?php esc_html_e( 'Smart Insights', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'Stored recommendations, statuses, and scheduler state', 'opti-behavior' ); ?> <span class="danger-category-count">(1 <?php esc_html_e( 'table', 'opti-behavior' ); ?> + <?php esc_html_e( 'state reset', 'opti-behavior' ); ?>)</span> <span class="danger-category-size" data-category="smart_insights"></span></span>
                            </label>
                            <label class="condition-row danger-category-row">
                                <input type="checkbox" class="condition-check danger-category-check" data-category="funnels" checked>
                                <span class="condition-label"><strong><?php esc_html_e( 'Funnels', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'Funnel definitions and tracking', 'opti-behavior' ); ?> <span class="danger-category-count">(2 <?php esc_html_e( 'tables', 'opti-behavior' ); ?>)</span> <span class="danger-category-size" data-category="funnels"></span></span>
                            </label>
                            <label class="condition-row danger-category-row">
                                <input type="checkbox" class="condition-check danger-category-check" data-category="forms" checked>
                                <span class="condition-label"><strong><?php esc_html_e( 'Forms & Reports', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'Form interactions, journey groups, report schedules and logs', 'opti-behavior' ); ?> <span class="danger-category-count">(5 <?php esc_html_e( 'tables', 'opti-behavior' ); ?>)</span> <span class="danger-category-size" data-category="forms"></span></span>
                            </label>
                            <label class="condition-row danger-category-row">
                                <input type="checkbox" class="condition-check danger-category-check" data-category="ab_testing" checked>
                                <span class="condition-label"><strong><?php esc_html_e( 'A/B Testing', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'Tests, variants, goals, impressions, conversions, stats', 'opti-behavior' ); ?> <span class="danger-category-count">(7+ <?php esc_html_e( 'tables', 'opti-behavior' ); ?>)</span> <span class="danger-category-size" data-category="ab_testing"></span></span>
                            </label>
                        </div>
                    </div>

                    <div class="danger-action-group">
                        <button type="button" id="opti-behavior-delete-all-btn" class="btn-danger">
                            <span class="btn-icon"><i data-lucide="trash-2"></i></span>
                            <?php esc_html_e( 'Delete All Analytics Data', 'opti-behavior' ); ?>
                        </button>
                    </div>

                    <!-- Delete All Confirmation Modal -->
                    <div id="opti-behavior-delete-modal" class="opti-behavior-modal" style="display: none;">
                        <div class="opti-behavior-modal-overlay"></div>
                        <div class="opti-behavior-modal-content">
                            <div class="opti-behavior-modal-header">
                                <h3 class="opti-behavior-modal-title">
                                    <i data-lucide="alert-triangle"></i>
                                    <?php esc_html_e( 'Confirm Data Deletion', 'opti-behavior' ); ?>
                                </h3>
                                <button type="button" class="opti-behavior-modal-close">&times;</button>
                            </div>
                            <div class="opti-behavior-modal-body">
                                <div id="opti-behavior-delete-confirm" class="opti-behavior-delete-step">
                                    <p id="opti-behavior-modal-warning-text" class="opti-behavior-modal-warning">
                                        <strong><?php esc_html_e( 'Warning:', 'opti-behavior' ); ?></strong>
                                        <span id="opti-behavior-modal-warning-body"><?php esc_html_e( 'This will permanently delete ALL analytics data from your database. This action cannot be undone.', 'opti-behavior' ); ?></span>
                                    </p>
                                    <p><?php esc_html_e( 'The following data will be deleted:', 'opti-behavior' ); ?></p>
                                    <ul id="opti-behavior-delete-category-list" class="opti-behavior-delete-list"></ul>
                                    <div class="opti-behavior-modal-actions">
                                        <button type="button" class="btn-secondary opti-behavior-modal-cancel">
                                            <?php esc_html_e( 'Cancel', 'opti-behavior' ); ?>
                                        </button>
                                        <button type="button" id="opti-behavior-confirm-delete-btn" class="btn-danger">
                                            <span class="btn-icon"><i data-lucide="trash-2"></i></span>
                                            <?php esc_html_e( 'Yes, Delete All Data', 'opti-behavior' ); ?>
                                        </button>
                                    </div>
                                </div>
                                <div id="opti-behavior-delete-progress" class="opti-behavior-delete-step" style="display: none;">
                                    <p class="opti-behavior-progress-title"><?php esc_html_e( 'Deleting data...', 'opti-behavior' ); ?></p>
                                    <div class="opti-behavior-progress-container">
                                        <div class="opti-behavior-progress-bar">
                                            <div id="opti-behavior-delete-progress-bar" class="opti-behavior-progress-fill"></div>
                                        </div>
                                        <span id="opti-behavior-delete-percent" class="opti-behavior-progress-percent">0%</span>
                                    </div>
                                    <p id="opti-behavior-delete-status" class="opti-behavior-progress-status"><?php esc_html_e( 'Initializing...', 'opti-behavior' ); ?></p>
                                </div>
                                <div id="opti-behavior-delete-complete" class="opti-behavior-delete-step" style="display: none;">
                                    <div class="opti-behavior-success-icon">
                                        <i data-lucide="check-circle"></i>
                                    </div>
                                    <p class="opti-behavior-success-title"><?php esc_html_e( 'Deletion Complete!', 'opti-behavior' ); ?></p>
                                    <p id="opti-behavior-delete-result" class="opti-behavior-success-message"></p>
                                    <div class="opti-behavior-modal-actions">
                                        <button type="button" class="btn-primary opti-behavior-modal-done">
                                            <?php esc_html_e( 'Done', 'opti-behavior' ); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                </div><!-- /.danger-tab-panel full-reset -->

                <div class="danger-tab-panel" data-danger-tab="date-range">
                <!-- Block 2: Delete Data by Date Range -->
                <div class="settings-section settings-section-danger">
                    <div class="section-header">
                        <h3 class="section-title section-title-danger">
                            <span class="section-icon"><i data-lucide="calendar-x"></i></span>
                            <?php esc_html_e( 'Delete Data by Date Range', 'opti-behavior' ); ?>
                            <?php opti_behavior_tooltip_e( $tooltips['delete_date_range']['title'], $tooltips['delete_date_range']['content'], $tooltips['delete_date_range']['simple'], '', array( 'position' => 'right', 'theme' => 'danger' ) ); ?>
                        </h3>
                        <p class="section-description">
                            <?php esc_html_e( 'Delete analytics data collected within a specific date range. Data outside this range will be preserved.', 'opti-behavior' ); ?>
                        </p>
                    </div>

                    <div class="danger-warning-box">
                        <p class="danger-warning-text"><strong><i data-lucide="alert-triangle"></i> <?php esc_html_e( 'Warning:', 'opti-behavior' ); ?></strong> <?php esc_html_e( 'This will permanently delete analytics data within the selected date range. Data outside this range will not be affected.', 'opti-behavior' ); ?></p>
                        <ul class="danger-warning-list">
                            <li><strong><?php esc_html_e( 'Click Events:', 'opti-behavior' ); ?></strong> <?php esc_html_e( 'Clicks, scrolls, mouse movements, form interactions', 'opti-behavior' ); ?></li>
                            <li><strong><?php esc_html_e( 'Sessions:', 'opti-behavior' ); ?></strong> <?php esc_html_e( 'User sessions, pageviews, bounce rates, time on site', 'opti-behavior' ); ?></li>
                            <li><strong><?php esc_html_e( 'Visitors:', 'opti-behavior' ); ?></strong> <?php esc_html_e( 'Visitor data with no sessions outside the range', 'opti-behavior' ); ?></li>
                            <li><strong><?php esc_html_e( 'Heatmaps:', 'opti-behavior' ); ?></strong> <?php esc_html_e( 'Click heatmaps, scroll maps, attention maps data for this period', 'opti-behavior' ); ?></li>
                            <li><strong><?php esc_html_e( 'Recordings:', 'opti-behavior' ); ?></strong> <?php esc_html_e( 'Session recording files and replay data captured during this period', 'opti-behavior' ); ?></li>
                            <li><strong><?php esc_html_e( 'Traffic Sources:', 'opti-behavior' ); ?></strong> <?php esc_html_e( 'Referrers, UTM parameters, outbound clicks', 'opti-behavior' ); ?></li>
                            <li><strong><?php esc_html_e( 'Error Logs:', 'opti-behavior' ); ?></strong> <?php esc_html_e( 'JavaScript errors, broken links, performance metrics', 'opti-behavior' ); ?></li>
                        </ul>
                    </div>

                    <?php
                    $today = gmdate('Y-m-d');
                    $thirty_days_ago = gmdate('Y-m-d', strtotime('-30 days'));
                    ?>
                    <div class="danger-range-inputs">
                        <div class="danger-range-field">
                            <label class="danger-range-label"><?php esc_html_e( 'Start Date:', 'opti-behavior' ); ?></label>
                            <input type="date" id="opti-behavior-range-start" value="<?php echo esc_attr($thirty_days_ago); ?>" class="danger-date-input">
                        </div>
                        <div class="danger-range-field">
                            <label class="danger-range-label"><?php esc_html_e( 'End Date:', 'opti-behavior' ); ?></label>
                            <input type="date" id="opti-behavior-range-end" value="<?php echo esc_attr($today); ?>" class="danger-date-input">
                        </div>
                    </div>

                    <button type="button" id="opti-behavior-delete-range-btn" class="btn-danger">
                        <span class="btn-icon"><i data-lucide="trash-2"></i></span>
                        <?php esc_html_e( 'Delete Logs in Date Range', 'opti-behavior' ); ?>
                    </button>

                    <!-- Delete by Date Range Modal -->
                    <div id="opti-behavior-delete-range-modal" class="opti-behavior-modal" style="display: none;">
                        <div class="opti-behavior-modal-overlay"></div>
                        <div class="opti-behavior-modal-content">
                            <div class="opti-behavior-modal-header opti-behavior-modal-header-warning">
                                <h3 class="opti-behavior-modal-title">
                                    <i data-lucide="calendar-x"></i>
                                    <?php esc_html_e( 'Delete Data by Date Range', 'opti-behavior' ); ?>
                                </h3>
                                <button type="button" class="opti-behavior-modal-close">&times;</button>
                            </div>
                            <div class="opti-behavior-modal-body">
                                <div id="opti-behavior-range-confirm" class="opti-behavior-delete-step">
                                    <div class="opti-behavior-date-range-summary">
                                        <p><?php esc_html_e( 'You are about to delete all analytics data between:', 'opti-behavior' ); ?></p>
                                        <div class="opti-behavior-date-range-display">
                                            <span id="opti-behavior-range-display-start" class="date-badge"></span>
                                            <span class="date-separator"><?php esc_html_e( 'to', 'opti-behavior' ); ?></span>
                                            <span id="opti-behavior-range-display-end" class="date-badge"></span>
                                        </div>
                                    </div>
                                    <p class="opti-behavior-modal-warning">
                                        <strong><?php esc_html_e( 'Warning:', 'opti-behavior' ); ?></strong>
                                        <?php esc_html_e( 'This will permanently delete analytics data within the selected date range. This action cannot be undone.', 'opti-behavior' ); ?>
                                    </p>
                                    <p><?php esc_html_e( 'The following data will be deleted for the selected period:', 'opti-behavior' ); ?></p>
                                    <ul class="opti-behavior-delete-list opti-behavior-delete-list-compact">
                                        <li><?php esc_html_e( 'Click events, scrolls, mouse movements', 'opti-behavior' ); ?></li>
                                        <li><?php esc_html_e( 'Sessions and pageviews', 'opti-behavior' ); ?></li>
                                        <li><?php esc_html_e( 'Recordings within the date range', 'opti-behavior' ); ?></li>
                                        <li><?php esc_html_e( 'Error logs and performance data', 'opti-behavior' ); ?></li>
                                    </ul>
                                    <div class="opti-behavior-modal-actions">
                                        <button type="button" class="btn-secondary opti-behavior-modal-cancel">
                                            <?php esc_html_e( 'Cancel', 'opti-behavior' ); ?>
                                        </button>
                                        <button type="button" id="opti-behavior-confirm-range-delete-btn" class="btn-danger">
                                            <span class="btn-icon"><i data-lucide="trash-2"></i></span>
                                            <?php esc_html_e( 'Yes, Delete Data', 'opti-behavior' ); ?>
                                        </button>
                                    </div>
                                </div>
                                <div id="opti-behavior-range-progress" class="opti-behavior-delete-step" style="display: none;">
                                    <p class="opti-behavior-progress-title"><?php esc_html_e( 'Deleting data...', 'opti-behavior' ); ?></p>
                                    <div class="opti-behavior-progress-container">
                                        <div class="opti-behavior-progress-bar">
                                            <div id="opti-behavior-range-progress-bar" class="opti-behavior-progress-fill"></div>
                                        </div>
                                        <span id="opti-behavior-range-percent" class="opti-behavior-progress-percent">0%</span>
                                    </div>
                                    <p id="opti-behavior-range-status" class="opti-behavior-progress-status"><?php esc_html_e( 'Initializing...', 'opti-behavior' ); ?></p>
                                </div>
                                <div id="opti-behavior-range-complete" class="opti-behavior-delete-step" style="display: none;">
                                    <div class="opti-behavior-success-icon">
                                        <i data-lucide="check-circle"></i>
                                    </div>
                                    <p class="opti-behavior-success-title"><?php esc_html_e( 'Deletion Complete!', 'opti-behavior' ); ?></p>
                                    <p id="opti-behavior-range-result" class="opti-behavior-success-message"></p>
                                    <div class="opti-behavior-modal-actions">
                                        <button type="button" class="btn-primary opti-behavior-modal-done">
                                            <?php esc_html_e( 'Done', 'opti-behavior' ); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                </div><!-- /.danger-tab-panel date-range -->

                <?php $this->render_smart_cleanup_section(); ?>
            </div>
            <?php
        }

        /**
         * Render the Smart Data Cleanup section inside Danger Zone tab.
         *
         * @since 1.0.9
         */
        private function render_smart_cleanup_section() {
            $bot_count        = $this->count_bot_sessions();
            $auto_settings    = $this->normalize_auto_cleanup_settings_for_display( get_option( 'opti_behavior_auto_cleanup_settings', array() ) );
            $saved_conditions = isset( $auto_settings['conditions'] ) ? $auto_settings['conditions'] : array();
            $cleanup_logs     = $this->get_cleanup_logs( 5 );
            $tooltips         = opti_behavior_get_settings_tooltips();
            // Daily heatmap sync auto-repair toggle (separate option, default ON).
            $auto_repair_enabled = method_exists( $this, 'is_heatmap_auto_repair_enabled' ) ? $this->is_heatmap_auto_repair_enabled() : true;
            ?>

            <div class="danger-tab-panel" data-danger-tab="smart-cleanup">
            <!-- Block 3: Smart Data Cleanup -->
            <div class="settings-section settings-section-danger settings-section-smart-cleanup">
                <div class="section-header">
                    <h3 class="section-title section-title-danger">
                        <span class="section-icon"><i data-lucide="shield-alert"></i></span>
                        <?php esc_html_e( 'Smart Data Cleanup', 'opti-behavior' ); ?>
                        <?php opti_behavior_tooltip_e( $tooltips['smart_cleanup']['title'], $tooltips['smart_cleanup']['content'], $tooltips['smart_cleanup']['simple'], '', array( 'position' => 'right', 'theme' => 'danger' ) ); ?>
                    </h3>
                    <p class="section-description">
                        <?php esc_html_e( 'Identify and remove spam, bot, or low-quality visitor data to reduce database size and prevent server slowdown.', 'opti-behavior' ); ?>
                    </p>
                </div>

                <!-- Card 1/3: Data Retention (tiered model, round 2) -->
                <?php
                // Cheap render: ONE option read via the retention policy service.
                $retention_available = class_exists( 'Opti_Behavior_Retention_Policy' );
                $tier_settings       = $retention_available
                    ? Opti_Behavior_Retention_Policy::get_settings()
                    : array(
                        'raw_retention_days'          => 365,
                        'insights_prune_months'       => 12,
                        'spam_daily_enabled'          => true,
                        'files_retention_days'        => 90,
                        'aggregates_retention_months' => 0,
                    );
                $retention_days_value  = absint( $tier_settings['raw_retention_days'] );
                $insights_prune_months = absint( $tier_settings['insights_prune_months'] );
                $spam_daily_enabled    = ! empty( $tier_settings['spam_daily_enabled'] );
                $files_days_value      = absint( $tier_settings['files_retention_days'] );
                $agg_months_value      = absint( $tier_settings['aggregates_retention_months'] );
                ?>
                <div class="smart-cleanup-subsection" id="opti-behavior-data-retention-widget">
                    <h4 class="danger-subtitle">
                        <i data-lucide="database"></i>
                        <?php esc_html_e( 'Data Retention', 'opti-behavior' ); ?>
                    </h4>
                    <p class="smart-cleanup-description">
                        <?php esc_html_e( 'Tiered retention: spam and bot sessions are purged daily, heavy files (recordings and raw heatmap event files) expire first, detailed per-visitor data expires later, and dashboard summaries are kept forever — charts and totals keep working for dates older than these windows.', 'opti-behavior' ); ?>
                    </p>
                    <label class="condition-row">
                        <input type="checkbox" id="sc-spam-daily-enabled" <?php checked( $spam_daily_enabled ); ?>>
                        <span class="condition-label"><?php esc_html_e( 'Spam & bot sessions: purge automatically every day', 'opti-behavior' ); ?></span>
                    </label>
                    <label class="condition-row">
                        <span class="condition-label"><?php esc_html_e( 'Heavy files (recordings & raw heatmap files) after', 'opti-behavior' ); ?></span>
                        <input type="number" id="sc-files-retention-days" class="condition-input condition-input-small" min="0" max="3650" value="<?php echo esc_attr( (string) $files_days_value ); ?>">
                        <span class="condition-unit"><?php esc_html_e( 'days (0 = same as detailed data)', 'opti-behavior' ); ?></span>
                    </label>
                    <label class="condition-row">
                        <span class="condition-label"><?php esc_html_e( 'Detailed data (sessions, events, per-visitor rows) after', 'opti-behavior' ); ?></span>
                        <input type="number" id="sc-data-retention-days" class="condition-input condition-input-small" min="0" max="3650" value="<?php echo esc_attr( (string) $retention_days_value ); ?>">
                        <span class="condition-unit"><?php esc_html_e( 'days (0 = never)', 'opti-behavior' ); ?></span>
                    </label>
                    <p class="condition-row" style="margin:4px 0 0;">
                        <span class="condition-label"><?php esc_html_e( 'Dashboard summaries:', 'opti-behavior' ); ?></span>
                        <strong id="sc-aggregates-retention-label"><?php
                        if ( $agg_months_value > 0 ) {
                            /* translators: %d: number of months dashboard summaries are kept */
                            printf( esc_html__( '%d months', 'opti-behavior' ), (int) $agg_months_value );
                        } else {
                            esc_html_e( 'Kept forever', 'opti-behavior' );
                        }
                        ?></strong>
                    </p>
                    <p class="smart-cleanup-description" style="margin-top:4px;">
                        <?php esc_html_e( 'The heavy-files window can never be longer than the detailed-data window — it is clamped automatically on save. Bot traffic percentages survive the daily spam purge via the daily summaries.', 'opti-behavior' ); ?>
                    </p>
                    <details class="smart-cleanup-advanced" style="margin-top:8px;">
                        <summary style="cursor:pointer;"><?php esc_html_e( 'Advanced', 'opti-behavior' ); ?></summary>
                        <label class="condition-row" style="margin-top:8px;">
                            <span class="condition-label"><?php esc_html_e( 'Cap dashboard summaries at', 'opti-behavior' ); ?></span>
                            <input type="number" id="sc-aggregates-retention-months" class="condition-input condition-input-small" min="0" max="120" value="<?php echo esc_attr( (string) $agg_months_value ); ?>">
                            <span class="condition-unit"><?php esc_html_e( 'months (0 = keep forever, recommended)', 'opti-behavior' ); ?></span>
                        </label>
                        <label class="condition-row" style="margin-top:8px;">
                            <span class="condition-label"><?php esc_html_e( 'Remove resolved/dismissed insights after', 'opti-behavior' ); ?></span>
                            <input type="number" id="sc-insights-prune-months" class="condition-input condition-input-small" min="0" max="120" value="<?php echo esc_attr( (string) $insights_prune_months ); ?>">
                            <span class="condition-unit"><?php esc_html_e( 'months (0 = never)', 'opti-behavior' ); ?></span>
                        </label>
                        <p class="smart-cleanup-description" style="margin-top:4px;">
                            <?php esc_html_e( 'Expired heatmap files are archived first (never deleted immediately) — the “Archived Heatmap Data Retention” setting below controls when archives are permanently removed.', 'opti-behavior' ); ?>
                        </p>

                        <!-- `_orphaned/` Archive Retention Purge (daily auto-protection, unbounded-growth fix).
                             Relocated here from the "Repair & Archive" card (round 3) — it is a retention
                             setting, not a repair tool. Same option + AJAX save path, UI move only. -->
                        <?php
                        // Cheap render: TWO option reads (setting + last-run progress).
                        // No filesystem scan ever happens on an admin page load — the
                        // purge runs in bounded, self-rescheduling daily cron ticks.
                        $orphan_purge_available = class_exists( 'Opti_Behavior_Heatmap_Orphan_Purge' );
                        $orphan_purge_days      = $orphan_purge_available
                            ? ( new Opti_Behavior_Heatmap_Orphan_Purge() )->get_retention_days_setting()
                            : 90;
                        $orphan_purge_progress  = $orphan_purge_available
                            ? ( new Opti_Behavior_Heatmap_Orphan_Purge() )->get_progress()
                            : array();
                        ?>
                        <div id="opti-behavior-heatmap-orphan-purge-widget" style="margin-top:12px;">
                            <h4 class="danger-subtitle" style="font-size:13px;">
                                <i data-lucide="timer-off"></i>
                                <?php esc_html_e( 'Archived Heatmap Data Retention', 'opti-behavior' ); ?>
                            </h4>
                            <p class="smart-cleanup-description">
                                <?php esc_html_e( 'Automatically and permanently deletes folders from the _orphaned archive once they have been archived for longer than the retention window below. Runs daily in small background batches. Newer archives are never touched, so they stay available for a future restore. Set to 0 to disable automatic deletion entirely.', 'opti-behavior' ); ?>
                            </p>
                            <label class="condition-row">
                                <span class="condition-label"><?php esc_html_e( 'Delete archived data after', 'opti-behavior' ); ?></span>
                                <input type="number" id="sc-orphan-purge-retention" class="condition-input condition-input-small" min="0" max="3650" value="<?php echo esc_attr( (string) absint( $orphan_purge_days ) ); ?>">
                                <span class="condition-unit"><?php esc_html_e( 'days (0 = never)', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="smart-cleanup-actions">
                                <button type="button" id="opti-behavior-heatmap-orphan-purge-save-btn" class="btn-secondary">
                                    <span class="btn-icon"><i data-lucide="save"></i></span>
                                    <?php esc_html_e( 'Save archive retention setting', 'opti-behavior' ); ?>
                                </button>
                                <span id="opti-behavior-heatmap-orphan-purge-status" class="smart-cleanup-repair-status"></span>
                            </div>
                            <?php if ( ! empty( $orphan_purge_progress['finished_at'] ) ) : ?>
                                <p class="smart-cleanup-description" style="margin-top:8px;">
                                    <?php
                                    printf(
                                        /* translators: 1: date/time of the last completed purge run, 2: deleted folder count, 3: kept folder count */
                                        esc_html__( 'Last purge run finished %1$s — %2$d folder(s) deleted, %3$d kept.', 'opti-behavior' ),
                                        esc_html( (string) $orphan_purge_progress['finished_at'] ),
                                        (int) ( isset( $orphan_purge_progress['deleted'] ) ? $orphan_purge_progress['deleted'] : 0 ),
                                        (int) ( isset( $orphan_purge_progress['kept'] ) ? $orphan_purge_progress['kept'] : 0 )
                                    );
                                    if ( ! empty( $orphan_purge_progress['failed'] ) ) {
                                        echo ' ';
                                        printf(
                                            /* translators: %d: folders that could not be deleted or dated */
                                            esc_html__( '%d folder(s) were skipped because their age could not be determined or deletion failed.', 'opti-behavior' ),
                                            (int) $orphan_purge_progress['failed']
                                        );
                                    }
                                    ?>
                                </p>
                            <?php elseif ( ! empty( $orphan_purge_progress['state'] ) && 'running' === $orphan_purge_progress['state'] ) : ?>
                                <p class="smart-cleanup-description" style="margin-top:8px;">
                                    <?php esc_html_e( 'A purge pass is currently running in the background.', 'opti-behavior' ); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </details>
                    <div class="smart-cleanup-actions">
                        <button type="button" id="opti-behavior-data-retention-save-btn" class="btn-secondary">
                            <span class="btn-icon"><i data-lucide="save"></i></span>
                            <?php esc_html_e( 'Save retention setting', 'opti-behavior' ); ?>
                        </button>
                        <span id="opti-behavior-data-retention-status" class="smart-cleanup-repair-status"></span>
                    </div>
                </div>

                <!-- Card 2/3: Manual Cleanup (bot/spam now + expert conditional rules) -->
                <div class="smart-cleanup-subsection" id="opti-behavior-manual-cleanup-card">
                    <h4 class="danger-subtitle">
                        <i data-lucide="bot"></i>
                        <?php esc_html_e( 'Manual Cleanup', 'opti-behavior' ); ?>
                        <?php opti_behavior_tooltip_e( $tooltips['bot_spam_cleanup']['title'], $tooltips['bot_spam_cleanup']['content'], $tooltips['bot_spam_cleanup']['simple'], '', array( 'position' => 'right', 'theme' => 'danger' ) ); ?>
                    </h4>
                    <p class="smart-cleanup-description">
                        <?php esc_html_e( 'Remove all sessions classified as spam, bot, or automated by the shared Traffic Behavior policy. This deletes their events, recordings, and all related data.', 'opti-behavior' ); ?>
                    </p>
                    <div class="smart-cleanup-bot-count">
                        <span class="bot-count-label"><?php esc_html_e( 'Currently detected:', 'opti-behavior' ); ?></span>
                        <span class="bot-count-value" id="opti-behavior-bot-count"><?php echo esc_html( $bot_count ); ?></span>
                        <span class="bot-count-suffix"><?php esc_html_e( 'bot/spam/automated records', 'opti-behavior' ); ?></span>
                    </div>
                    <button type="button" id="opti-behavior-bot-cleanup-btn" class="btn-danger" <?php echo $bot_count === 0 ? 'disabled' : ''; ?>>
                        <span class="btn-icon"><i data-lucide="trash-2"></i></span>
                        <?php esc_html_e( 'Clean Bot/Spam Traffic', 'opti-behavior' ); ?>
                    </button>

                    <!-- Conditional Cleanup (expert feature — collapsed by default) -->
                    <details class="smart-cleanup-advanced" id="opti-behavior-conditional-cleanup-details" style="margin-top:16px;">
                        <summary style="cursor:pointer;">
                            <strong><i data-lucide="filter" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"></i><?php esc_html_e( 'Conditional Cleanup rules (expert)', 'opti-behavior' ); ?></strong>
                            <?php opti_behavior_tooltip_e( $tooltips['conditional_cleanup']['title'], $tooltips['conditional_cleanup']['content'], $tooltips['conditional_cleanup']['simple'], '', array( 'position' => 'right', 'theme' => 'danger' ) ); ?>
                        </summary>
                    <p class="smart-cleanup-description" style="margin-top:8px;">
                        <?php esc_html_e( 'Delete sessions matching ANY of the following conditions (OR logic). A session is deleted as soon as it matches one active condition — it does not need to match all of them.', 'opti-behavior' ); ?>
                    </p>
                    <p class="smart-cleanup-or-note">
                        <strong><?php esc_html_e( 'Match ANY (OR)', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'each checked condition below is an independent deletion rule.', 'opti-behavior' ); ?>
                    </p>

                    <?php // Non-blocking redundancy notice (round 3): filled by JS when an active
                          // conditional rule overlaps a Data Retention tier window. ?>
                    <p class="smart-cleanup-or-note" id="sc-conditional-redundancy-notice" style="display:none;" aria-live="polite"></p>

                    <div class="smart-cleanup-conditions">
                        <!-- Time-based -->
                        <div class="conditions-group">
                            <h5 class="conditions-group-title"><?php esc_html_e( 'Time-Based', 'opti-behavior' ); ?> <?php opti_behavior_tooltip_e( $tooltips['conditions_time_based']['title'], $tooltips['conditions_time_based']['content'], $tooltips['conditions_time_based']['simple'], '', array( 'position' => 'right', 'theme' => 'danger' ) ); ?></h5>

                            <?php
                            // Deprecated rule (round 3): "Sessions older than X days" duplicates the
                            // detailed-DB Data Retention tier. Soft migration — the row is HIDDEN for
                            // users who do not currently have it enabled; users with the rule active
                            // in saved settings keep it (with an inline deprecation notice) and the
                            // backend continues honoring the condition unchanged.
                            if ( ! empty( $saved_conditions['older_than_days'] ) ) :
                            ?>
                            <label class="condition-row" id="sc-older-than-row">
                                <input type="checkbox" class="condition-check" data-condition="older_than_days" checked>
                                <span class="condition-label"><?php esc_html_e( 'Sessions older than', 'opti-behavior' ); ?></span>
                                <input type="number" class="condition-input condition-input-small" id="sc-older-than-days" min="1" value="<?php echo esc_attr( $saved_conditions['older_than_days'] ); ?>">
                                <span class="condition-unit"><?php esc_html_e( 'days', 'opti-behavior' ); ?></span>
                            </label>
                            <p class="smart-cleanup-description" id="sc-older-than-deprecated-notice" style="margin:2px 0 8px;">
                                <em><?php esc_html_e( 'Deprecated: this rule duplicates the “Detailed data” window in the Data Retention card above, which already runs automatically every day. Consider using the Data Retention tier instead — once this rule is unchecked and saved, it disappears from this list.', 'opti-behavior' ); ?></em>
                            </p>
                            <?php endif; ?>

                            <label class="condition-row">
                                <input type="checkbox" class="condition-check" data-condition="inactive_visitor_days" <?php checked( ! empty( $saved_conditions['inactive_visitor_days'] ) ); ?>>
                                <span class="condition-label"><?php esc_html_e( 'Inactive visitors (no return in', 'opti-behavior' ); ?></span>
                                <input type="number" class="condition-input condition-input-small" id="sc-inactive-days" min="1" value="<?php echo esc_attr( ! empty( $saved_conditions['inactive_visitor_days'] ) ? $saved_conditions['inactive_visitor_days'] : 30 ); ?>" <?php echo empty( $saved_conditions['inactive_visitor_days'] ) ? 'disabled' : ''; ?>>
                                <span class="condition-unit"><?php esc_html_e( 'days)', 'opti-behavior' ); ?></span>
                            </label>
                        </div>

                        <!-- Quality-based -->
                        <div class="conditions-group">
                            <h5 class="conditions-group-title"><?php esc_html_e( 'Quality-Based', 'opti-behavior' ); ?> <?php opti_behavior_tooltip_e( $tooltips['conditions_quality_based']['title'], $tooltips['conditions_quality_based']['content'], $tooltips['conditions_quality_based']['simple'], '', array( 'position' => 'right', 'theme' => 'danger' ) ); ?></h5>

                            <label class="condition-row">
                                <input type="checkbox" class="condition-check" data-condition="include_spam_traffic" <?php checked( ! empty( $saved_conditions['include_spam_traffic'] ) || ! empty( $saved_conditions['include_bots'] ) ); ?>>
                                <span class="condition-label"><?php esc_html_e( 'Spam, bot, and automated sessions', 'opti-behavior' ); ?></span>
                            </label>

                            <label class="condition-row">
                                <input type="checkbox" class="condition-check" data-condition="min_duration" <?php checked( ! empty( $saved_conditions['min_duration'] ) ); ?>>
                                <span class="condition-label"><?php esc_html_e( 'Session duration less than', 'opti-behavior' ); ?></span>
                                <input type="number" class="condition-input condition-input-small" id="sc-min-duration" min="1" value="<?php echo esc_attr( ! empty( $saved_conditions['min_duration'] ) ? $saved_conditions['min_duration'] : 5 ); ?>" <?php echo empty( $saved_conditions['min_duration'] ) ? 'disabled' : ''; ?>>
                                <span class="condition-unit"><?php esc_html_e( 'sec, older than', 'opti-behavior' ); ?></span>
                                <input type="number" class="condition-input condition-input-small" id="sc-min-duration-age" min="0" value="<?php echo esc_attr( isset( $saved_conditions['min_duration_age_days'] ) ? $saved_conditions['min_duration_age_days'] : 1 ); ?>" <?php echo empty( $saved_conditions['min_duration'] ) ? 'disabled' : ''; ?>>
                                <span class="condition-unit"><?php esc_html_e( 'days', 'opti-behavior' ); ?></span>
                            </label>

                            <label class="condition-row">
                                <input type="checkbox" class="condition-check" data-condition="max_duration" <?php checked( ! empty( $saved_conditions['max_duration'] ) ); ?>>
                                <span class="condition-label"><?php esc_html_e( 'Session duration more than', 'opti-behavior' ); ?></span>
                                <input type="number" class="condition-input condition-input-small" id="sc-max-duration" min="1" value="<?php echo esc_attr( ! empty( $saved_conditions['max_duration'] ) ? $saved_conditions['max_duration'] : 3600 ); ?>" <?php echo empty( $saved_conditions['max_duration'] ) ? 'disabled' : ''; ?>>
                                <span class="condition-unit"><?php esc_html_e( 'sec, older than', 'opti-behavior' ); ?></span>
                                <input type="number" class="condition-input condition-input-small" id="sc-max-duration-age" min="0" value="<?php echo esc_attr( isset( $saved_conditions['max_duration_age_days'] ) ? $saved_conditions['max_duration_age_days'] : 7 ); ?>" <?php echo empty( $saved_conditions['max_duration'] ) ? 'disabled' : ''; ?>>
                                <span class="condition-unit"><?php esc_html_e( 'days', 'opti-behavior' ); ?></span>
                            </label>

                            <label class="condition-row">
                                <input type="checkbox" class="condition-check" data-condition="min_events" <?php checked( ! empty( $saved_conditions['min_events'] ) ); ?>>
                                <span class="condition-label"><?php esc_html_e( 'Event count less than', 'opti-behavior' ); ?></span>
                                <input type="number" class="condition-input condition-input-small" id="sc-min-events" min="1" value="<?php echo esc_attr( ! empty( $saved_conditions['min_events'] ) ? $saved_conditions['min_events'] : 2 ); ?>" <?php echo empty( $saved_conditions['min_events'] ) ? 'disabled' : ''; ?>>
                                <span class="condition-unit"><?php esc_html_e( ', older than', 'opti-behavior' ); ?></span>
                                <input type="number" class="condition-input condition-input-small" id="sc-min-events-age" min="0" value="<?php echo esc_attr( isset( $saved_conditions['min_events_age_days'] ) ? $saved_conditions['min_events_age_days'] : 3 ); ?>" <?php echo empty( $saved_conditions['min_events'] ) ? 'disabled' : ''; ?>>
                                <span class="condition-unit"><?php esc_html_e( 'days', 'opti-behavior' ); ?></span>
                            </label>

                            <label class="condition-row">
                                <input type="checkbox" class="condition-check" data-condition="max_events" <?php checked( ! empty( $saved_conditions['max_events'] ) ); ?>>
                                <span class="condition-label"><?php esc_html_e( 'Event count more than', 'opti-behavior' ); ?></span>
                                <input type="number" class="condition-input condition-input-small" id="sc-max-events" min="1" value="<?php echo esc_attr( ! empty( $saved_conditions['max_events'] ) ? $saved_conditions['max_events'] : 1000 ); ?>" <?php echo empty( $saved_conditions['max_events'] ) ? 'disabled' : ''; ?>>
                            </label>

                            <label class="condition-row">
                                <input type="checkbox" class="condition-check" data-condition="min_page_views" <?php checked( ! empty( $saved_conditions['min_page_views'] ) ); ?>>
                                <span class="condition-label"><?php esc_html_e( 'Page views less than', 'opti-behavior' ); ?></span>
                                <input type="number" class="condition-input condition-input-small" id="sc-min-pageviews" min="1" value="<?php echo esc_attr( ! empty( $saved_conditions['min_page_views'] ) ? $saved_conditions['min_page_views'] : 2 ); ?>" <?php echo empty( $saved_conditions['min_page_views'] ) ? 'disabled' : ''; ?>>
                            </label>

                            <label class="condition-row">
                                <input type="checkbox" class="condition-check" data-condition="bounce_only" <?php checked( ! empty( $saved_conditions['bounce_only'] ) ); ?>>
                                <span class="condition-label"><?php esc_html_e( 'Single page bounced visits, older than', 'opti-behavior' ); ?></span>
                                <input type="number" class="condition-input condition-input-small" id="sc-bounce-age" min="0" value="<?php echo esc_attr( isset( $saved_conditions['bounce_age_days'] ) ? $saved_conditions['bounce_age_days'] : 7 ); ?>" <?php echo empty( $saved_conditions['bounce_only'] ) ? 'disabled' : ''; ?>>
                                <span class="condition-unit"><?php esc_html_e( 'days', 'opti-behavior' ); ?></span>
                            </label>
                        </div>

                        <!-- Visitor Behavior -->
                        <div class="conditions-group">
                            <h5 class="conditions-group-title"><?php esc_html_e( 'Visitor Behavior', 'opti-behavior' ); ?> <?php opti_behavior_tooltip_e( $tooltips['conditions_visitor_behavior']['title'], $tooltips['conditions_visitor_behavior']['content'], $tooltips['conditions_visitor_behavior']['simple'], '', array( 'position' => 'right', 'theme' => 'danger' ) ); ?></h5>

                            <label class="condition-row">
                                <input type="checkbox" class="condition-check" data-condition="single_visit_only" <?php checked( ! empty( $saved_conditions['single_visit_only'] ) ); ?>>
                                <span class="condition-label"><?php esc_html_e( 'Single-visit visitors inactive for', 'opti-behavior' ); ?></span>
                                <input type="number" class="condition-input condition-input-small" id="sc-single-visit-age" min="1" value="<?php echo esc_attr( isset( $saved_conditions['single_visit_age_days'] ) ? $saved_conditions['single_visit_age_days'] : 30 ); ?>" <?php echo empty( $saved_conditions['single_visit_only'] ) ? 'disabled' : ''; ?>>
                                <span class="condition-unit"><?php esc_html_e( 'days', 'opti-behavior' ); ?></span>
                            </label>
                        </div>

                        <!-- Options -->
                        <div class="conditions-group conditions-group-options">
                            <label class="condition-row">
                                <input type="checkbox" id="sc-delete-orphans" <?php checked( ! isset( $auto_settings['delete_orphaned_visitors'] ) || ! empty( $auto_settings['delete_orphaned_visitors'] ) ); ?>>
                                <span class="condition-label"><?php esc_html_e( 'Auto-delete orphaned visitors (no remaining sessions)', 'opti-behavior' ); ?></span>
                            </label>
                            <label class="condition-row">
                                <input type="checkbox" id="sc-optimize-after-cleanup" checked>
                                <span class="condition-label"><?php esc_html_e( 'Optimize affected database tables after manual cleanup when fragmentation is high', 'opti-behavior' ); ?></span>
                            </label>
                        </div>
                    </div>

                    <div class="smart-cleanup-actions">
                        <button type="button" id="opti-behavior-save-conditions-btn" class="btn-secondary">
                            <span class="btn-icon"><i data-lucide="save"></i></span>
                            <span class="btn-text"><?php esc_html_e( 'Save Conditions', 'opti-behavior' ); ?></span>
                        </button>
                        <button type="button" id="opti-behavior-smart-preview-btn" class="btn-secondary">
                            <span class="btn-icon"><i data-lucide="eye"></i></span>
                            <span class="btn-text"><?php esc_html_e( 'Preview', 'opti-behavior' ); ?></span>
                            <span class="preview-count" id="opti-behavior-preview-count" style="display:none;"></span>
                        </button>
                        <button type="button" id="opti-behavior-smart-delete-btn" class="btn-danger" disabled>
                            <span class="btn-icon"><i data-lucide="trash-2"></i></span>
                            <?php esc_html_e( 'Delete Matching Sessions', 'opti-behavior' ); ?>
                        </button>
                    </div>

                    <div id="opti-behavior-smart-preview-details" class="smart-cleanup-impact-details" style="display:none;" aria-live="polite" aria-hidden="true"></div>
                    </details>
                </div>

                <!-- Card 3/3: Repair & Archive (collapsed by default) -->
                <details class="smart-cleanup-subsection" id="opti-behavior-repair-archive-card">
                    <summary style="cursor:pointer;">
                        <h4 class="danger-subtitle" style="display:inline-flex;align-items:center;gap:6px;margin:0;">
                            <i data-lucide="wrench"></i>
                            <?php esc_html_e( 'Repair & Archive', 'opti-behavior' ); ?>
                        </h4>
                    </summary>

                <!-- Heatmap Sync Repair -->
                <div class="smart-cleanup-subsection">
                    <h4 class="danger-subtitle">
                        <i data-lucide="refresh-cw"></i>
                        <?php esc_html_e( 'Heatmap Sync Repair', 'opti-behavior' ); ?>
                    </h4>
                    <p class="smart-cleanup-description">
                        <?php esc_html_e( 'Recomputes the sessions / with-interactions counts shown on the Heatmaps list and detail pages for every page (does not delete or restore any data). Use this if a page shows a desync warning.', 'opti-behavior' ); ?>
                    </p>
                    <button type="button" id="opti-behavior-repair-heatmap-sync-btn" class="btn-secondary">
                        <span class="btn-icon"><i data-lucide="refresh-cw"></i></span>
                        <?php esc_html_e( 'Repair Heatmap Sync', 'opti-behavior' ); ?>
                    </button>
                    <span id="opti-behavior-repair-heatmap-sync-status" class="smart-cleanup-repair-status"></span>

                    <?php // Relocated here from the Auto Schedule tab (round 3): manual + auto repair
                          // now live side by side. Same option + cron wiring — saved immediately on
                          // change through the existing schedule-save AJAX path. Independent of the
                          // cleanup enabled flag; recompute-only, never deletes data. ?>
                    <label class="condition-row auto-repair-toggle" style="margin-top:12px;">
                        <input type="checkbox" id="sc-auto-repair-heatmap-sync" <?php checked( $auto_repair_enabled ); ?>>
                        <span class="condition-label"><strong><?php esc_html_e( 'Auto-repair heatmap sync stats daily', 'opti-behavior' ); ?></strong> &mdash; <?php esc_html_e( 'refreshes the heatmap session sync cache and stale aggregate counters every 24 hours (same as the Repair button). Never deletes heatmap files or database rows. Saved immediately when toggled.', 'opti-behavior' ); ?></span>
                    </label>
                </div>

                <!-- Heatmap Registry Rebuild From Files (Option A) -->
                <?php
                // Cheap render: ONE option read for the initial progress snapshot.
                // No filesystem scan ever happens on an admin page load — all
                // heavy work runs in bounded, self-rescheduling cron ticks.
                $rebuild_progress = class_exists( 'Opti_Behavior_Heatmap_Registry_Rebuild' )
                    ? ( new Opti_Behavior_Heatmap_Registry_Rebuild() )->get_progress()
                    : array( 'state' => 'unavailable' );
                ?>
                <div class="smart-cleanup-subsection" id="opti-behavior-heatmap-rebuild-widget"
                    data-progress="<?php echo esc_attr( (string) wp_json_encode( $rebuild_progress ) ); ?>">
                    <h4 class="danger-subtitle">
                        <i data-lucide="folder-sync"></i>
                        <?php esc_html_e( 'Rebuild Heatmap Registry From Files', 'opti-behavior' ); ?>
                    </h4>
                    <p class="smart-cleanup-description">
                        <?php esc_html_e( 'Scans the heatmap data files on disk and re-creates missing Heatmaps list entries (e.g. pages whose database rows were lost to an old retention bug). Runs safely in the background in small batches — it never deletes or moves any files, and known spam/bot sessions stay excluded. You can leave this page while it runs.', 'opti-behavior' ); ?>
                    </p>
                    <div class="smart-cleanup-actions">
                        <button type="button" id="opti-behavior-heatmap-rebuild-start-btn" class="btn-secondary">
                            <span class="btn-icon"><i data-lucide="play"></i></span>
                            <?php esc_html_e( 'Rebuild heatmap registry from files', 'opti-behavior' ); ?>
                        </button>
                        <button type="button" id="opti-behavior-heatmap-rebuild-pause-btn" class="btn-secondary" style="display:none;">
                            <span class="btn-icon"><i data-lucide="pause"></i></span>
                            <?php esc_html_e( 'Pause', 'opti-behavior' ); ?>
                        </button>
                        <button type="button" id="opti-behavior-heatmap-rebuild-resume-btn" class="btn-secondary" style="display:none;">
                            <span class="btn-icon"><i data-lucide="play"></i></span>
                            <?php esc_html_e( 'Resume', 'opti-behavior' ); ?>
                        </button>
                        <button type="button" id="opti-behavior-heatmap-rebuild-abort-btn" class="btn-danger" style="display:none;">
                            <span class="btn-icon"><i data-lucide="x"></i></span>
                            <?php esc_html_e( 'Abort', 'opti-behavior' ); ?>
                        </button>
                    </div>
                    <div id="opti-behavior-heatmap-rebuild-progress-row" style="display:none;align-items:center;gap:12px;margin-top:12px;">
                        <div class="opti-behavior-progress-bar" style="max-width:420px;">
                            <div class="opti-behavior-progress-fill" id="opti-behavior-heatmap-rebuild-progress-fill" style="width:0%;"></div>
                        </div>
                        <span id="opti-behavior-heatmap-rebuild-progress-text" class="smart-cleanup-repair-status" aria-live="polite"></span>
                    </div>
                    <p id="opti-behavior-heatmap-rebuild-summary" class="smart-cleanup-description" style="display:none;margin-top:8px;" aria-live="polite"></p>
                </div>

                <!-- `_orphaned/` Archive Dry-Run Report (Option C phase 1 — report only, NO restore) -->
                <?php
                // Cheap render: ONE option read for the initial progress snapshot.
                // No filesystem scan ever happens on an admin page load — all
                // heavy work runs in bounded, self-rescheduling cron ticks.
                $orphan_report_progress = class_exists( 'Opti_Behavior_Heatmap_Orphan_Report' )
                    ? ( new Opti_Behavior_Heatmap_Orphan_Report() )->get_progress()
                    : array( 'state' => 'unavailable' );
                ?>
                <div class="smart-cleanup-subsection" id="opti-behavior-heatmap-orphan-report-widget"
                    data-progress="<?php echo esc_attr( (string) wp_json_encode( $orphan_report_progress ) ); ?>">
                    <h4 class="danger-subtitle">
                        <i data-lucide="archive"></i>
                        <?php esc_html_e( 'Archived Heatmap Data Report', 'opti-behavior' ); ?>
                    </h4>
                    <p class="smart-cleanup-description">
                        <?php esc_html_e( 'Scans the _orphaned archive (heatmap data moved aside by past cleanups) and reports what could be restored: pages whose archive folder no longer conflicts with live data, with their URL, session count, and how many sessions the database no longer knows about. This is a report only — nothing is restored, moved, or deleted.', 'opti-behavior' ); ?>
                    </p>
                    <div class="smart-cleanup-actions">
                        <button type="button" id="opti-behavior-heatmap-orphan-report-start-btn" class="btn-secondary">
                            <span class="btn-icon"><i data-lucide="search"></i></span>
                            <?php esc_html_e( 'Scan archived heatmap data', 'opti-behavior' ); ?>
                        </button>
                        <button type="button" id="opti-behavior-heatmap-orphan-report-pause-btn" class="btn-secondary" style="display:none;">
                            <span class="btn-icon"><i data-lucide="pause"></i></span>
                            <?php esc_html_e( 'Pause', 'opti-behavior' ); ?>
                        </button>
                        <button type="button" id="opti-behavior-heatmap-orphan-report-resume-btn" class="btn-secondary" style="display:none;">
                            <span class="btn-icon"><i data-lucide="play"></i></span>
                            <?php esc_html_e( 'Resume', 'opti-behavior' ); ?>
                        </button>
                        <button type="button" id="opti-behavior-heatmap-orphan-report-abort-btn" class="btn-danger" style="display:none;">
                            <span class="btn-icon"><i data-lucide="x"></i></span>
                            <?php esc_html_e( 'Abort', 'opti-behavior' ); ?>
                        </button>
                    </div>
                    <div id="opti-behavior-heatmap-orphan-report-progress-row" style="display:none;align-items:center;gap:12px;margin-top:12px;">
                        <div class="opti-behavior-progress-bar" style="max-width:420px;">
                            <div class="opti-behavior-progress-fill" id="opti-behavior-heatmap-orphan-report-progress-fill" style="width:0%;"></div>
                        </div>
                        <span id="opti-behavior-heatmap-orphan-report-progress-text" class="smart-cleanup-repair-status" aria-live="polite"></span>
                    </div>
                    <p id="opti-behavior-heatmap-orphan-report-summary" class="smart-cleanup-description" style="display:none;margin-top:8px;" aria-live="polite"></p>
                    <div id="opti-behavior-heatmap-orphan-report-sample" style="display:none;margin-top:8px;"></div>
                </div>

                <!-- `_orphaned/` Archive RESTORE (Phase C recovery tooling, 2026-08-16 incident) -->
                <?php
                // Cheap render: ONE option read for the initial progress snapshot.
                // All heavy work happens in bounded, client-looped AJAX batches.
                $orphan_restore_progress = class_exists( 'Opti_Behavior_Heatmap_Orphan_Restore' )
                    ? ( new Opti_Behavior_Heatmap_Orphan_Restore() )->get_progress()
                    : array( 'state' => 'unavailable' );
                // Never ship the (potentially large) hash list to the browser.
                unset( $orphan_restore_progress['restored_hashes'] );
                ?>
                <div class="smart-cleanup-subsection" id="opti-behavior-heatmap-orphan-restore-widget"
                    data-progress="<?php echo esc_attr( (string) wp_json_encode( $orphan_restore_progress ) ); ?>">
                    <h4 class="danger-subtitle">
                        <i data-lucide="archive-restore"></i>
                        <?php esc_html_e( 'Restore Archived Heatmap Data', 'opti-behavior' ); ?>
                    </h4>
                    <p class="smart-cleanup-description">
                        <?php esc_html_e( 'Moves the heatmap data folders that past cleanups archived under _orphaned back into the live data directory, then refreshes the affected pages and starts the registry rebuild so recovered pages reappear in the Heatmaps list. Existing live files are never overwritten, and the archive retention purge is paused while the recovery runs. Resumable: if interrupted, restarting continues where it stopped.', 'opti-behavior' ); ?>
                    </p>
                    <div class="smart-cleanup-actions">
                        <button type="button" id="opti-behavior-heatmap-orphan-restore-start-btn" class="btn-secondary">
                            <span class="btn-icon"><i data-lucide="archive-restore"></i></span>
                            <?php esc_html_e( 'Restore archived heatmap data', 'opti-behavior' ); ?>
                        </button>
                    </div>
                    <div id="opti-behavior-heatmap-orphan-restore-progress-row" style="display:none;align-items:center;gap:12px;margin-top:12px;">
                        <div class="opti-behavior-progress-bar" style="max-width:420px;">
                            <div class="opti-behavior-progress-fill" id="opti-behavior-heatmap-orphan-restore-progress-fill" style="width:0%;"></div>
                        </div>
                        <span id="opti-behavior-heatmap-orphan-restore-progress-text" class="smart-cleanup-repair-status" aria-live="polite"></span>
                    </div>
                    <p id="opti-behavior-heatmap-orphan-restore-summary" class="smart-cleanup-description" style="display:none;margin-top:8px;" aria-live="polite"></p>
                </div>

                <!-- Spam-flag re-evaluation (Phase C recovery tooling, 2026-08-16 incident) -->
                <div class="smart-cleanup-subsection" id="opti-behavior-reevaluate-spam-widget">
                    <h4 class="danger-subtitle">
                        <i data-lucide="rotate-ccw"></i>
                        <?php esc_html_e( 'Re-evaluate Spam Flags', 'opti-behavior' ); ?>
                    </h4>
                    <p class="smart-cleanup-description">
                        <?php esc_html_e( 'Re-checks sessions currently flagged as spam for having too few clicks against their real engagement evidence (page click counters, heatmap click events, and recording data). Sessions that actually had clicks are downgraded back to human and reappear in reports and the Heatmaps list; genuinely click-less sessions keep their spam flag. Safe to run any time — it never deletes anything.', 'opti-behavior' ); ?>
                    </p>
                    <div class="smart-cleanup-actions">
                        <button type="button" id="opti-behavior-reevaluate-spam-start-btn" class="btn-secondary">
                            <span class="btn-icon"><i data-lucide="rotate-ccw"></i></span>
                            <?php esc_html_e( 'Re-evaluate spam flags', 'opti-behavior' ); ?>
                        </button>
                    </div>
                    <div id="opti-behavior-reevaluate-spam-progress-row" style="display:none;align-items:center;gap:12px;margin-top:12px;">
                        <div class="opti-behavior-progress-bar" style="max-width:420px;">
                            <div class="opti-behavior-progress-fill" id="opti-behavior-reevaluate-spam-progress-fill" style="width:0%;"></div>
                        </div>
                        <span id="opti-behavior-reevaluate-spam-progress-text" class="smart-cleanup-repair-status" aria-live="polite"></span>
                    </div>
                    <p id="opti-behavior-reevaluate-spam-summary" class="smart-cleanup-description" style="display:none;margin-top:8px;" aria-live="polite"></p>
                </div>

                <?php // The "Archived Heatmap Data Retention" setting was relocated (round 3) to the
                      // Data Retention card's Advanced section above — it is a retention setting,
                      // not a repair tool. Same option + AJAX save path, same element IDs. ?>

                </details><!-- /#opti-behavior-repair-archive-card -->

            </div>
            </div><!-- /.danger-tab-panel smart-cleanup -->

            <div class="danger-tab-panel" data-danger-tab="auto-schedule">
            <div class="settings-section settings-section-danger settings-section-smart-cleanup">
                <!-- Scheduled Auto-Cleanup -->
                <div class="smart-cleanup-subsection">
                    <h4 class="danger-subtitle">
                        <i data-lucide="clock"></i>
                        <?php esc_html_e( 'Scheduled Auto-Cleanup', 'opti-behavior' ); ?>
                        <?php opti_behavior_tooltip_e( $tooltips['scheduled_auto_cleanup']['title'], $tooltips['scheduled_auto_cleanup']['content'], $tooltips['scheduled_auto_cleanup']['simple'], '', array( 'position' => 'right', 'theme' => 'danger' ) ); ?>
                    </h4>
                    <p class="smart-cleanup-description">
                        <?php esc_html_e( 'Baseline tiered retention (the Data Retention card on the Smart Cleanup tab) already runs automatically every day — you do not need to schedule anything for it. This tab only schedules the advanced Conditional Cleanup rules: sessions matching ANY active condition (OR logic) are deleted on each run.', 'opti-behavior' ); ?>
                    </p>

                    <div class="auto-cleanup-settings">
                        <label class="condition-row auto-cleanup-toggle">
                            <input type="checkbox" id="sc-auto-enabled" <?php checked( ! empty( $auto_settings['enabled'] ) ); ?>>
                            <span class="condition-label"><strong><?php esc_html_e( 'Enable automatic cleanup', 'opti-behavior' ); ?></strong></span>
                        </label>

                        <div class="auto-cleanup-frequency" id="sc-frequency-group" style="<?php echo empty( $auto_settings['enabled'] ) ? 'opacity:0.5;pointer-events:none;' : ''; ?>">
                            <label class="condition-label"><?php esc_html_e( 'Frequency:', 'opti-behavior' ); ?></label>
                            <select id="sc-auto-frequency" class="condition-select">
                                <option value="daily" <?php selected( isset( $auto_settings['frequency'] ) ? $auto_settings['frequency'] : 'daily', 'daily' ); ?>><?php esc_html_e( 'Daily', 'opti-behavior' ); ?></option>
                                <option value="weekly" <?php selected( isset( $auto_settings['frequency'] ) ? $auto_settings['frequency'] : '', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'opti-behavior' ); ?></option>
                                <option value="monthly" <?php selected( isset( $auto_settings['frequency'] ) ? $auto_settings['frequency'] : '', 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'opti-behavior' ); ?></option>
                            </select>
                        </div>

                        <div class="auto-cleanup-limits" id="sc-schedule-limits-group" style="<?php echo empty( $auto_settings['enabled'] ) ? 'opacity:0.5;pointer-events:none;' : ''; ?>">
                            <label class="condition-row">
                                <span class="condition-label"><?php esc_html_e( 'Maximum sessions per run', 'opti-behavior' ); ?></span>
                                <input type="number" id="sc-max-rows-per-run" class="condition-input condition-input-small" min="1" max="50000" value="<?php echo esc_attr( ! empty( $auto_settings['max_rows_per_run'] ) ? absint( $auto_settings['max_rows_per_run'] ) : 5000 ); ?>">
                            </label>
                            <label class="condition-row">
                                <input type="checkbox" id="sc-schedule-optimize-after-cleanup" <?php checked( ! empty( $auto_settings['optimize_after_cleanup'] ) ); ?>>
                                <span class="condition-label"><?php esc_html_e( 'Optimize affected tables after scheduled cleanup when fragmentation is high', 'opti-behavior' ); ?></span>
                            </label>
                            <label class="condition-row">
                                <input type="checkbox" id="sc-recalculate-spam-before-cleanup" <?php checked( ! empty( $auto_settings['recalculate_spam_before_cleanup'] ) ); ?>>
                                <span class="condition-label"><?php esc_html_e( 'Recalculate spam classifications before scheduled cleanup (bounded by the per-run cap)', 'opti-behavior' ); ?></span>
                            </label>
                        </div>

                        <?php // The "Auto-repair heatmap sync stats daily" toggle was relocated (round 3)
                              // to the Smart Cleanup tab's "Repair & Archive" card, next to the manual
                              // Repair button. Same option + cron wiring, same element ID. ?>

                        <?php if ( ! empty( $auto_settings['last_run'] ) ) : ?>
                            <p class="auto-cleanup-last-run">
                                <i data-lucide="check-circle"></i>
                                <?php
                                printf(
                                    /* translators: %s: date/time of last run */
                                    esc_html__( 'Last run: %s', 'opti-behavior' ),
                                    esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $auto_settings['last_run'] ) )
                                );
                                ?>
                            </p>
                        <?php endif; ?>

                        <?php if ( ! empty( $auto_settings['last_result'] ) && is_array( $auto_settings['last_result'] ) ) : ?>
                            <div class="auto-cleanup-last-result">
                                <strong><?php esc_html_e( 'Last result:', 'opti-behavior' ); ?></strong>
                                <?php
                                $last_result = $auto_settings['last_result'];
                                printf(
                                    /* translators: 1: status, 2: deleted count, 3: remaining count */
                                    esc_html__( '%1$s — %2$d sessions deleted, %3$d remaining.', 'opti-behavior' ),
                                    esc_html( ucfirst( isset( $last_result['status'] ) ? $last_result['status'] : 'completed' ) ),
                                    intval( isset( $last_result['sessions_deleted'] ) ? $last_result['sessions_deleted'] : 0 ),
                                    intval( isset( $last_result['remaining_sessions'] ) ? $last_result['remaining_sessions'] : 0 )
                                );
                                if ( ! empty( $last_result['more_rows_remain'] ) ) {
                                    echo ' ' . esc_html__( 'More matching rows remain for the next scheduled run.', 'opti-behavior' );
                                }
                                ?>
                            </div>
                        <?php endif; ?>

                        <button type="button" id="opti-behavior-save-schedule-btn" class="btn-secondary">
                            <span class="btn-icon"><i data-lucide="save"></i></span>
                            <?php esc_html_e( 'Save Schedule', 'opti-behavior' ); ?>
                        </button>
                    </div>
                </div>

                <!-- Cleanup History -->
                <?php if ( ! empty( $cleanup_logs ) ) : ?>
                <div class="smart-cleanup-subsection">
                    <h4 class="danger-subtitle">
                        <i data-lucide="history"></i>
                        <?php esc_html_e( 'Cleanup History', 'opti-behavior' ); ?>
                        <?php opti_behavior_tooltip_e( $tooltips['cleanup_history']['title'], $tooltips['cleanup_history']['content'], $tooltips['cleanup_history']['simple'], '', array( 'position' => 'right', 'theme' => 'danger' ) ); ?>
                    </h4>
                    <ul class="cleanup-history-list">
                        <?php foreach ( $cleanup_logs as $log ) : ?>
                            <li class="cleanup-history-item">
                                <span class="cleanup-history-date"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log['timestamp'] ) ) ); ?></span>
                                <span class="cleanup-history-type cleanup-history-type-<?php echo esc_attr( $log['type'] ); ?>"><?php echo esc_html( ucfirst( $log['type'] ) ); ?></span>
                                <span class="cleanup-history-stats">
                                    <?php
                                    printf(
                                        /* translators: %d: number of sessions deleted */
                                        esc_html__( '%d sessions deleted', 'opti-behavior' ),
                                        intval( $log['sessions_deleted'] )
                                    );
                                    ?>
                                </span>
                                <?php
                                $history_details = array();
                                if ( ! empty( $log['files_deleted'] ) ) {
                                    $history_details[] = sprintf(
                                        /* translators: %d: number of recording files */
                                        esc_html__( '%d files', 'opti-behavior' ),
                                        intval( $log['files_deleted'] )
                                    );
                                }
                                if ( ! empty( $log['orphaned_visitors_deleted'] ) ) {
                                    $history_details[] = sprintf(
                                        /* translators: %d: number of visitors */
                                        esc_html__( '%d orphan visitors', 'opti-behavior' ),
                                        intval( $log['orphaned_visitors_deleted'] )
                                    );
                                }
                                if ( ! empty( $log['estimated_bytes_reclaimed'] ) ) {
                                    $history_details[] = sprintf(
                                        /* translators: %s: formatted byte count */
                                        esc_html__( '~%s affected', 'opti-behavior' ),
                                        esc_html( size_format( intval( $log['estimated_bytes_reclaimed'] ) ) )
                                    );
                                }
                                if ( ! empty( $log['optimized_tables'] ) && is_array( $log['optimized_tables'] ) ) {
                                    $history_details[] = sprintf(
                                        /* translators: %d: number of optimized tables */
                                        esc_html__( '%d optimized tables', 'opti-behavior' ),
                                        count( $log['optimized_tables'] )
                                    );
                                }
                                ?>
                                <?php if ( ! empty( $history_details ) || ! empty( $log['rows_by_table'] ) || ! empty( $log['warnings'] ) ) : ?>
                                    <div class="cleanup-history-details">
                                        <?php if ( ! empty( $history_details ) ) : ?>
                                            <span class="cleanup-history-detail-summary"><?php echo esc_html( implode( ' • ', $history_details ) ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $log['rows_by_table'] ) && is_array( $log['rows_by_table'] ) ) : ?>
                                            <details class="cleanup-history-table-details">
                                                <summary><?php esc_html_e( 'Rows by table', 'opti-behavior' ); ?></summary>
                                                <ul>
                                                    <?php foreach ( $log['rows_by_table'] as $table_name => $row_count ) : ?>
                                                        <?php if ( intval( $row_count ) <= 0 ) : ?>
                                                            <?php continue; ?>
                                                        <?php endif; ?>
                                                        <li>
                                                            <code><?php echo esc_html( $table_name ); ?></code>:
                                                            <?php echo esc_html( number_format_i18n( intval( $row_count ) ) ); ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </details>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $log['warnings'] ) && is_array( $log['warnings'] ) ) : ?>
                                            <ul class="cleanup-history-warnings">
                                                <?php foreach ( $log['warnings'] as $warning ) : ?>
                                                    <li><?php echo esc_html( $warning ); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

            </div>
            </div><!-- /.danger-tab-panel auto-schedule -->

                <!-- Bot Cleanup Modal -->
                <div id="opti-behavior-bot-cleanup-modal" class="opti-behavior-modal" style="display: none;">
                    <div class="opti-behavior-modal-overlay"></div>
                    <div class="opti-behavior-modal-content">
                        <div class="opti-behavior-modal-header">
                            <h3 class="opti-behavior-modal-title">
                                <i data-lucide="bot"></i>
                                <?php esc_html_e( 'Clean Bot/Spam Traffic', 'opti-behavior' ); ?>
                            </h3>
                            <button type="button" class="opti-behavior-modal-close">&times;</button>
                        </div>
                        <div class="opti-behavior-modal-body">
                            <div id="opti-behavior-bot-confirm" class="opti-behavior-delete-step">
                                <p class="opti-behavior-modal-warning">
                                    <strong><?php esc_html_e( 'Warning:', 'opti-behavior' ); ?></strong>
                                    <?php esc_html_e( 'This will permanently delete all spam, bot, and automated sessions and their related data (events, recordings, pageviews, etc.). This action cannot be undone.', 'opti-behavior' ); ?>
                                </p>
                                <div class="opti-behavior-modal-actions">
                                    <button type="button" class="btn-secondary opti-behavior-modal-cancel"><?php esc_html_e( 'Cancel', 'opti-behavior' ); ?></button>
                                    <button type="button" id="opti-behavior-confirm-bot-cleanup-btn" class="btn-danger">
                                        <span class="btn-icon"><i data-lucide="trash-2"></i></span>
                                        <?php esc_html_e( 'Yes, Clean Bot/Spam Traffic', 'opti-behavior' ); ?>
                                    </button>
                                </div>
                            </div>
                            <div id="opti-behavior-bot-progress" class="opti-behavior-delete-step" style="display: none;">
                                <p class="opti-behavior-progress-title"><?php esc_html_e( 'Cleaning bot/spam traffic...', 'opti-behavior' ); ?></p>
                                <div class="opti-behavior-progress-container">
                                    <div class="opti-behavior-progress-bar">
                                        <div id="opti-behavior-bot-progress-bar" class="opti-behavior-progress-fill"></div>
                                    </div>
                                    <span id="opti-behavior-bot-percent" class="opti-behavior-progress-percent">0%</span>
                                </div>
                                <p id="opti-behavior-bot-status" class="opti-behavior-progress-status"><?php esc_html_e( 'Initializing...', 'opti-behavior' ); ?></p>
                            </div>
                            <div id="opti-behavior-bot-complete" class="opti-behavior-delete-step" style="display: none;">
                                <div class="opti-behavior-success-icon"><i data-lucide="check-circle"></i></div>
                                <p class="opti-behavior-success-title"><?php esc_html_e( 'Cleanup Complete!', 'opti-behavior' ); ?></p>
                                <p id="opti-behavior-bot-result" class="opti-behavior-success-message"></p>
                                <div class="opti-behavior-modal-actions">
                                    <button type="button" class="btn-primary opti-behavior-modal-done"><?php esc_html_e( 'Done', 'opti-behavior' ); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Smart Cleanup Modal -->
                <div id="opti-behavior-smart-cleanup-modal" class="opti-behavior-modal" style="display: none;">
                    <div class="opti-behavior-modal-overlay"></div>
                    <div class="opti-behavior-modal-content">
                        <div class="opti-behavior-modal-header">
                            <h3 class="opti-behavior-modal-title">
                                <i data-lucide="filter"></i>
                                <?php esc_html_e( 'Delete Matching Sessions', 'opti-behavior' ); ?>
                            </h3>
                            <button type="button" class="opti-behavior-modal-close">&times;</button>
                        </div>
                        <div class="opti-behavior-modal-body">
                            <div id="opti-behavior-smart-confirm" class="opti-behavior-delete-step">
                                <p class="opti-behavior-modal-warning">
                                    <strong><?php esc_html_e( 'Warning:', 'opti-behavior' ); ?></strong>
                                    <?php esc_html_e( 'This will permanently delete all sessions matching your conditions and their related data. This action cannot be undone.', 'opti-behavior' ); ?>
                                </p>
                                <p id="opti-behavior-smart-preview-summary"></p>
                                <div id="opti-behavior-smart-preview-summary-details" class="smart-cleanup-confirm-impact-details"></div>
                                <div class="opti-behavior-modal-actions">
                                    <button type="button" class="btn-secondary opti-behavior-modal-cancel"><?php esc_html_e( 'Cancel', 'opti-behavior' ); ?></button>
                                    <button type="button" id="opti-behavior-confirm-smart-cleanup-btn" class="btn-danger">
                                        <span class="btn-icon"><i data-lucide="trash-2"></i></span>
                                        <?php esc_html_e( 'Yes, Delete Matching', 'opti-behavior' ); ?>
                                    </button>
                                </div>
                            </div>
                            <div id="opti-behavior-smart-progress" class="opti-behavior-delete-step" style="display: none;">
                                <p class="opti-behavior-progress-title"><?php esc_html_e( 'Deleting matching sessions...', 'opti-behavior' ); ?></p>
                                <div class="opti-behavior-progress-container">
                                    <div class="opti-behavior-progress-bar">
                                        <div id="opti-behavior-smart-progress-bar" class="opti-behavior-progress-fill"></div>
                                    </div>
                                    <span id="opti-behavior-smart-percent" class="opti-behavior-progress-percent">0%</span>
                                </div>
                                <p id="opti-behavior-smart-status" class="opti-behavior-progress-status"><?php esc_html_e( 'Initializing...', 'opti-behavior' ); ?></p>
                            </div>
                            <div id="opti-behavior-smart-complete" class="opti-behavior-delete-step" style="display: none;">
                                <div class="opti-behavior-success-icon"><i data-lucide="check-circle"></i></div>
                                <p class="opti-behavior-success-title"><?php esc_html_e( 'Cleanup Complete!', 'opti-behavior' ); ?></p>
                                <p id="opti-behavior-smart-result" class="opti-behavior-success-message"></p>
                                <div id="opti-behavior-smart-result-details" class="smart-cleanup-result-details"></div>
                                <div class="opti-behavior-modal-actions">
                                    <button type="button" class="btn-primary opti-behavior-modal-done"><?php esc_html_e( 'Done', 'opti-behavior' ); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="danger-tab-panel" data-danger-tab="uninstall">
                <?php $this->render_uninstall_tab(); ?>
                </div>

            </div>
            <?php
        }

        /**
         * Normalize scheduled cleanup settings before rendering the Danger Zone UI.
         *
         * This keeps legacy default-like options from showing the old destructive
         * "duration more than 2 sec" condition as selected. Runtime cleanup already
         * normalizes this shape, but the UI must display and re-save the safe
         * "duration less than 2 sec, older than 30 days" rule.
         *
         * @since 1.2.7
         * @param mixed $auto_settings Saved auto-cleanup settings.
         * @return array Normalized auto-cleanup settings for display.
         */
        private function normalize_auto_cleanup_settings_for_display( $auto_settings ) {
            $auto_settings = is_array( $auto_settings ) ? $auto_settings : array();

            if ( method_exists( $this, 'get_smart_cleanup_service' ) ) {
                return $this->get_smart_cleanup_service()->normalize_auto_cleanup_settings( $auto_settings );
            }

            if ( ! class_exists( 'Opti_Behavior_Smart_Cleanup_Service' ) ) {
                $service_path = __DIR__ . '/class-opti-behavior-smart-cleanup-service.php';
                if ( file_exists( $service_path ) ) {
                    require_once $service_path;
                }
            }

            if ( class_exists( 'Opti_Behavior_Smart_Cleanup_Service' ) ) {
                $service = new Opti_Behavior_Smart_Cleanup_Service();
                return $service->normalize_auto_cleanup_settings( $auto_settings );
            }

            return $auto_settings;
        }

        /**
         * Render Uninstall Tab (rendered as sub-section inside Danger Zone)
         */
        private function render_uninstall_tab() {
            $tooltips = opti_behavior_get_settings_tooltips();
            ?>
            <div class="opti-behavior-settings-form opti-behavior-settings-form-danger">
                <div class="settings-section settings-section-danger">
                    <div class="section-header">
                        <h3 class="section-title section-title-danger">
                            <span class="section-icon"><i data-lucide="trash-2"></i></span>
                            <?php esc_html_e( 'Uninstall Settings', 'opti-behavior' ); ?>
                            <?php opti_behavior_tooltip_e( $tooltips['delete_on_uninstall']['title'], $tooltips['delete_on_uninstall']['content'], $tooltips['delete_on_uninstall']['simple'], '', array( 'position' => 'bottom', 'theme' => 'warning' ) ); ?>
                        </h3>
                        <p class="section-description">
                            <?php esc_html_e( 'Configure what happens when you uninstall this plugin. Choose whether to keep or remove all data and database tables.', 'opti-behavior' ); ?>
                        </p>
                    </div>

                    <div class="danger-warning-box">
                        <p class="danger-warning-text"><strong><i data-lucide="alert-triangle"></i> <?php esc_html_e( 'Important:', 'opti-behavior' ); ?></strong> <?php esc_html_e( 'These settings determine what happens when you uninstall the plugin.', 'opti-behavior' ); ?></p>
                        <p class="danger-warning-text"><?php esc_html_e( 'If you enable "Delete on Uninstall", the following will be permanently removed:', 'opti-behavior' ); ?></p>
                        <ul class="danger-warning-list">
                            <li><?php esc_html_e( 'All database tables (events, sessions, visitors, pageviews, pages, referrers, outbound clicks, bot visits, recordings, heatmap pages, daily stats, funnels, funnel tracking, journey groups, report schedules, report logs)', 'opti-behavior' ); ?></li>
                            <li><?php esc_html_e( 'All plugin settings, configurations and saved options', 'opti-behavior' ); ?></li>
                            <li><?php esc_html_e( 'All heatmap data files and click/scroll/attention maps', 'opti-behavior' ); ?></li>
                            <li><?php esc_html_e( 'All session recording files and replay data', 'opti-behavior' ); ?></li>
                            <li><?php esc_html_e( 'All event tracking data files', 'opti-behavior' ); ?></li>
                            <li><?php esc_html_e( 'All error tracking data (JS errors, broken links, performance metrics, friction events)', 'opti-behavior' ); ?></li>
                            <li><?php esc_html_e( 'All scheduled cron jobs and cleanup schedules', 'opti-behavior' ); ?></li>
                            <li><?php esc_html_e( 'All cached transients and temporary data', 'opti-behavior' ); ?></li>
                        </ul>
                    </div>

                    <form method="post" class="uninstall-settings-form">
                        <?php
                        wp_nonce_field('opti_behavior_uninstall_settings', 'opti_behavior_uninstall_nonce');
                        // Default to false (disabled) - user must explicitly enable data deletion
                        $delete_on_uninstall = get_option('opti_behavior_delete_on_uninstall', false);
                        ?>

                        <div class="uninstall-option">
                            <label class="uninstall-checkbox-label">
                                <input type="checkbox" name="delete_on_uninstall" value="1" <?php checked($delete_on_uninstall, true); ?> class="uninstall-checkbox">
                                <span class="uninstall-checkbox-text">
                                    <strong><?php esc_html_e( 'Delete all data and tables on plugin uninstall', 'opti-behavior' ); ?></strong>
                                </span>
                            </label>
                            <p class="uninstall-option-description">
                                <?php esc_html_e( 'When enabled, all plugin data and database tables will be permanently deleted when you uninstall the plugin. Leave unchecked if you plan to reinstall the plugin later and want to keep your data.', 'opti-behavior' ); ?>
                            </p>
                        </div>

                        <div class="uninstall-actions">
                            <button type="submit" name="save_uninstall_settings" class="btn-danger">
                                <span class="btn-icon"><i data-lucide="save"></i></span>
                                <?php esc_html_e( 'Save Uninstall Settings', 'opti-behavior' ); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php
        }

        /**
         * Render Language Tab
         */
        private function render_language_tab() {
            $tooltips = opti_behavior_get_settings_tooltips();
            // Check if settings were just saved (via POST)
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in calling function
            $settings_saved = isset($_POST['opti_behavior_language_submit']);
            // phpcs:enable WordPress.Security.NonceVerification.Missing

            // Get current language setting ('' = follow the WordPress site locale).
            $current_language = get_option('opti_behavior_admin_language', '');

            // Define available languages
            $available_languages = array(
                ''      => __('Same as WordPress', 'opti-behavior'),
                'en_US' => __('English', 'opti-behavior'),
                'fr_FR' => __('French', 'opti-behavior'),
                'de_DE' => __('German', 'opti-behavior'),
                'es_ES' => __('Spanish', 'opti-behavior'),
                'pt_BR' => __('Portuguese', 'opti-behavior'),
                'it_IT' => __('Italian', 'opti-behavior'),
            );

            // Country flag SVGs for each locale (inline, Windows-compatible)
            $flag_svgs = array(
                ''      => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
                'en_US' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 30" width="24" height="12"><clipPath id="usf"><rect width="60" height="30"/></clipPath><g clip-path="url(#usf)"><rect width="60" height="30" fill="#bf0a30"/><rect y="2.31" width="60" height="2.31" fill="#fff"/><rect y="6.92" width="60" height="2.31" fill="#fff"/><rect y="11.54" width="60" height="2.31" fill="#fff"/><rect y="16.15" width="60" height="2.31" fill="#fff"/><rect y="20.77" width="60" height="2.31" fill="#fff"/><rect y="25.38" width="60" height="2.31" fill="#fff"/><rect width="24" height="16.15" fill="#002868"/></g></svg>',
                'fr_FR' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 20" width="24" height="16"><rect width="10" height="20" fill="#002395"/><rect x="10" width="10" height="20" fill="#fff"/><rect x="20" width="10" height="20" fill="#ed2939"/></svg>',
                'de_DE' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 18" width="24" height="14"><rect width="30" height="6" fill="#000"/><rect y="6" width="30" height="6" fill="#d00"/><rect y="12" width="30" height="6" fill="#fc0"/></svg>',
                'es_ES' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 20" width="24" height="16"><rect width="30" height="20" fill="#c60b1e"/><rect y="5" width="30" height="10" fill="#ffc400"/></svg>',
                'pt_BR' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 21" width="24" height="16"><rect width="30" height="21" fill="#009b3a"/><polygon points="15,2 28,10.5 15,19 2,10.5" fill="#fedf00"/><circle cx="15" cy="10.5" r="4.5" fill="#002776"/><path d="M10.5,10.5 Q15,7.5 19.5,10.5" fill="none" stroke="#fff" stroke-width="0.6"/></svg>',
                'it_IT' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 20" width="24" height="16"><rect width="10" height="20" fill="#009246"/><rect x="10" width="10" height="20" fill="#fff"/><rect x="20" width="10" height="20" fill="#ce2b37"/></svg>',
            );
            ?>
            <div class="opti-behavior-settings-form">
                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon"><i data-lucide="languages"></i></span>
                            <?php esc_html_e('Language Settings', 'opti-behavior'); ?>
                        </h3>
                        <p class="section-description">
                            <?php esc_html_e('Select your preferred language for the Opti-Behavior plugin admin interface.', 'opti-behavior'); ?>
                        </p>
                    </div>

                    <form method="post" class="language-settings-form">
                        <?php wp_nonce_field('opti_behavior_language_settings', 'opti_behavior_language_nonce'); ?>

                        <?php if ($settings_saved): ?>
                            <div class="notice notice-success is-dismissible" style="margin: 20px 0;">
                                <p><?php esc_html_e('Language settings saved successfully. Reloading page to apply new language...', 'opti-behavior'); ?></p>
                            </div>
                            <script>
                                // Auto-reload page after 1 second to apply new language
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1000);
                            </script>
                        <?php endif; ?>

                        <div class="settings-grid">
                            <div class="setting-item">
                                <label class="setting-label">
                                    <span class="label-text"><?php esc_html_e('Admin Language', 'opti-behavior'); ?><?php opti_behavior_tooltip_e( $tooltips['admin_language']['title'], $tooltips['admin_language']['content'], $tooltips['admin_language']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                    <span class="label-description"><?php esc_html_e('Choose the language for plugin menus, settings, and messages', 'opti-behavior'); ?></span>
                                </label>
                                <div class="setting-control">
                                    <input type="hidden" name="opti_behavior_language" id="opti_behavior_language" value="<?php echo esc_attr($current_language); ?>">
                                    <div class="ob-lang-dropdown" id="ob-lang-dropdown">
                                        <div class="ob-lang-selected" id="ob-lang-selected">
                                            <span class="ob-lang-flag"><?php echo $flag_svgs[$current_language]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                            <span class="ob-lang-name"><?php echo esc_html($available_languages[$current_language]); ?></span>
                                            <span class="ob-lang-arrow"><i data-lucide="chevron-down"></i></span>
                                        </div>
                                        <div class="ob-lang-options" id="ob-lang-options">
                                            <?php foreach ($available_languages as $locale => $language_name): ?>
                                                <div class="ob-lang-option<?php echo $locale === $current_language ? ' active' : ''; ?>" data-value="<?php echo esc_attr($locale); ?>">
                                                    <span class="ob-lang-flag"><?php echo $flag_svgs[$locale]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                                    <span class="ob-lang-name"><?php echo esc_html($language_name); ?></span>
                                                    <?php if ($locale === $current_language): ?>
                                                        <span class="ob-lang-check"><i data-lucide="check"></i></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <script>
                                    (function(){
                                        var dd = document.getElementById('ob-lang-dropdown');
                                        var sel = document.getElementById('ob-lang-selected');
                                        var opts = document.getElementById('ob-lang-options');
                                        var input = document.getElementById('opti_behavior_language');
                                        sel.addEventListener('click', function(e) {
                                            e.stopPropagation();
                                            dd.classList.toggle('open');
                                        });
                                        opts.addEventListener('click', function(e) {
                                            var opt = e.target.closest('.ob-lang-option');
                                            if (!opt) return;
                                            var val = opt.getAttribute('data-value');
                                            input.value = val;
                                            sel.querySelector('.ob-lang-flag').innerHTML = opt.querySelector('.ob-lang-flag').innerHTML;
                                            sel.querySelector('.ob-lang-name').textContent = opt.querySelector('.ob-lang-name').textContent;
                                            opts.querySelectorAll('.ob-lang-option').forEach(function(o) {
                                                o.classList.remove('active');
                                                var ck = o.querySelector('.ob-lang-check');
                                                if (ck) ck.remove();
                                            });
                                            opt.classList.add('active');
                                            var check = document.createElement('span');
                                            check.className = 'ob-lang-check';
                                            check.innerHTML = '<i data-lucide="check"></i>';
                                            opt.appendChild(check);
                                            if (window.lucide) lucide.createIcons();
                                            dd.classList.remove('open');
                                        });
                                        document.addEventListener('click', function() { dd.classList.remove('open'); });
                                    })();
                                    </script>
                                </div>
                            </div>
                        </div>

                        <div class="language-info-box">
                            <p class="language-info-text">
                                <strong><i data-lucide="info"></i> <?php esc_html_e('Note:', 'opti-behavior'); ?></strong>
                                <?php esc_html_e('This setting only affects the Opti-Behavior plugin admin interface. It does not change the language of your WordPress admin or other plugins.', 'opti-behavior'); ?>
                            </p>
                        </div>

                        <div class="settings-actions">
                            <button type="submit" name="opti_behavior_language_submit" class="btn-save">
                                <span class="btn-icon"><i data-lucide="save"></i></span>
                                <?php esc_html_e('Save Language Settings', 'opti-behavior'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php
        }

        /**
         * Handle language settings save
         */
        private function handle_language_settings_save_impl() {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function (line 93)
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash not needed for sanitize_text_field

            // Get and sanitize the selected language
            $selected_language = isset($_POST['opti_behavior_language']) ? sanitize_text_field($_POST['opti_behavior_language']) : '';

            // Whitelist of allowed languages ('' = follow the WordPress site locale)
            $allowed_languages = array('', 'en_US', 'fr_FR', 'de_DE', 'es_ES', 'pt_BR', 'it_IT');

            // Validate against whitelist
            if (!in_array($selected_language, $allowed_languages, true)) {
                $selected_language = ''; // Default to "Same as WordPress" if invalid
            }

            // Save the language setting
            update_option('opti_behavior_admin_language', $selected_language);

            // phpcs:enable WordPress.Security.NonceVerification.Missing
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

            // Add success message
            add_settings_error(
                'opti_behavior_settings',
                'language_updated',
                __('Language settings saved successfully. The new language will be applied on the next page load.', 'opti-behavior'),
                'success'
            );

            // Set transient to persist the message across page reload
            set_transient('settings_errors', get_settings_errors(), 30);
        }

        /**
         * Render Privacy & GDPR Tab.
         *
         * Renders two cards:
         * 1. Privacy Mode selector (anonymous vs. full tracking).
         * 2. Consent Banner Configuration (only relevant in full tracking mode).
         *
         * @since 1.0.3
         * @return void
         */
        private function render_privacy_gdpr_tab() {
            // Read current privacy_mode from options; default to 'anonymous'.
            $options      = maybe_unserialize( get_option( 'opti_behavior_heatmap_option', array() ) );
            if ( ! is_array( $options ) ) {
                $options = array();
            }
            $privacy_mode = isset( $options['privacy_mode'] ) ? $options['privacy_mode'] : 'anonymous';

            // Read consent banner options with defaults.
            $banner_defaults = array(
                'consent_banner_enabled'      => false,
                'consent_banner_position'     => 'compact-card',
                'consent_banner_position_user_set' => false,
                'consent_banner_accent_color' => '#2e7d32',
                'consent_banner_bg_color'     => '#ffffff',
                'consent_banner_text_color'   => '#333333',
                'consent_banner_title'        => '',
                'consent_banner_message'      => '',
                'consent_banner_accept_label' => '',
                'consent_banner_reject_label' => '',
                'consent_banner_customize_label' => '',
                'consent_banner_save_label'   => '',
                'consent_banner_analytics_label' => '',
                'consent_banner_analytics_description' => '',
                'consent_banner_policy_label' => '',
                'consent_banner_policy_url'   => '',
            );
            $options = wp_parse_args( $options, $banner_defaults );
            if ( empty( $options['consent_banner_position_user_set'] ) ) {
                $options['consent_banner_position'] = 'compact-card';
            }

            // Detect active third-party consent plugin.
            if ( ! class_exists( 'Opti_Behavior_Consent_Detector' ) ) {
                require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-consent-detector.php';
            }
            $detector             = new Opti_Behavior_Consent_Detector();
            $has_consent_plugin   = $detector->has_consent_plugin();
            $detected_plugin_label = $detector->get_detected_plugin_label();

            // Load tooltip definitions.
            $tooltips = opti_behavior_get_settings_tooltips();

            // CSS and JS for the consent card are registered in assets/css/settings.css
            // and assets/js/settings.js, which are enqueued via enqueue_dashboard_assets_impl().
            ?>
            <div class="opti-behavior-settings-form">

                <!-- Card 1: Privacy Mode -->
                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon"><i data-lucide="shield"></i></span>
                            <?php esc_html_e( 'Privacy & GDPR Settings', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description">
                            <?php esc_html_e( 'Control how visitor data is collected and stored to comply with GDPR and other privacy regulations.', 'opti-behavior' ); ?>
                        </p>
                    </div>

                    <!-- Mode Comparison Info-Box (CSS grid for parser compatibility) -->
                    <div style="background:#e8f4fd; border:1px solid #64b5f6; border-radius:6px; padding:16px 20px; margin-bottom:20px; font-size:13px; line-height:1.5; color:#1565c0;">
                        <strong style="display:block; margin-bottom:10px;"><?php esc_html_e( 'Which mode should I choose?', 'opti-behavior' ); ?></strong>
                        <?php
                        // Reusable pill badge inline styles for the comparison table.
                        $opti_behavior_pill_no  = 'display:inline-block; padding:2px 12px; border-radius:10px; font-size:11px; font-weight:600; background:#e8f5e9; color:#2e7d32;';
                        $opti_behavior_pill_yes = 'display:inline-block; padding:2px 12px; border-radius:10px; font-size:11px; font-weight:600; background:#fff3e0; color:#e65100;';
                        $opti_behavior_pill_ok  = 'display:inline-block; padding:2px 12px; border-radius:10px; font-size:11px; font-weight:600; background:#e8f5e9; color:#2e7d32;';
                        $opti_behavior_pill_req = 'display:inline-block; padding:2px 12px; border-radius:10px; font-size:11px; font-weight:600; background:#fce4ec; color:#c62828;';
                        ?>
                        <div class="ob-privacy-comparison-wrap">
                        <div class="ob-privacy-comparison-table" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0; border:1px solid #ddd; border-radius:4px; overflow:hidden; color:#333;">
                            <div style="padding:8px 10px; background:#f1f1f1; border-bottom:1px solid #ddd; font-weight:600;"><?php esc_html_e( 'Feature', 'opti-behavior' ); ?></div>
                            <div style="padding:8px 10px; background:#f1f1f1; border-bottom:1px solid #ddd; border-left:1px solid #ddd; font-weight:600; text-align:center;"><?php esc_html_e( 'Anonymous Mode', 'opti-behavior' ); ?></div>
                            <div style="padding:8px 10px; background:#f1f1f1; border-bottom:1px solid #ddd; border-left:1px solid #ddd; font-weight:600; text-align:center;"><?php esc_html_e( 'Full Tracking', 'opti-behavior' ); ?></div>

                            <div style="padding:8px 10px; border-bottom:1px solid #eee;"><?php esc_html_e( 'Cookies set', 'opti-behavior' ); ?></div>
                            <div style="padding:8px 10px; border-bottom:1px solid #eee; border-left:1px solid #ddd; text-align:center;"><span style="<?php echo esc_attr( $opti_behavior_pill_no ); ?>"><?php esc_html_e( 'Disabled', 'opti-behavior' ); ?></span></div>
                            <div style="padding:8px 10px; border-bottom:1px solid #eee; border-left:1px solid #ddd; text-align:center;"><span style="<?php echo esc_attr( $opti_behavior_pill_yes ); ?>"><?php esc_html_e( 'Enabled', 'opti-behavior' ); ?></span></div>

                            <div style="padding:8px 10px; border-bottom:1px solid #eee; background:#fafafa;"><?php esc_html_e( 'IP address stored', 'opti-behavior' ); ?></div>
                            <div style="padding:8px 10px; border-bottom:1px solid #eee; border-left:1px solid #ddd; text-align:center; background:#fafafa;"><span style="<?php echo esc_attr( $opti_behavior_pill_no ); ?>"><?php esc_html_e( 'Disabled', 'opti-behavior' ); ?></span></div>
                            <div style="padding:8px 10px; border-bottom:1px solid #eee; border-left:1px solid #ddd; text-align:center; background:#fafafa;"><span style="<?php echo esc_attr( $opti_behavior_pill_yes ); ?>"><?php esc_html_e( 'Enabled', 'opti-behavior' ); ?></span></div>

                            <div style="padding:8px 10px; border-bottom:1px solid #eee;"><?php esc_html_e( 'Geolocation API calls', 'opti-behavior' ); ?></div>
                            <div style="padding:8px 10px; border-bottom:1px solid #eee; border-left:1px solid #ddd; text-align:center;"><span style="<?php echo esc_attr( $opti_behavior_pill_no ); ?>"><?php esc_html_e( 'Disabled', 'opti-behavior' ); ?></span></div>
                            <div style="padding:8px 10px; border-bottom:1px solid #eee; border-left:1px solid #ddd; text-align:center;"><span style="<?php echo esc_attr( $opti_behavior_pill_yes ); ?>"><?php esc_html_e( 'Enabled', 'opti-behavior' ); ?></span></div>

                            <div style="padding:8px 10px; border-bottom:1px solid #eee; background:#fafafa;"><?php esc_html_e( 'Visitor identification', 'opti-behavior' ); ?></div>
                            <div style="padding:8px 10px; border-bottom:1px solid #eee; border-left:1px solid #ddd; text-align:center; background:#fafafa; font-size:12px;"><?php esc_html_e( 'Daily hash (non-personal)', 'opti-behavior' ); ?></div>
                            <div style="padding:8px 10px; border-bottom:1px solid #eee; border-left:1px solid #ddd; text-align:center; background:#fafafa; font-size:12px;"><?php esc_html_e( 'Persistent cookie', 'opti-behavior' ); ?></div>

                            <div style="padding:8px 10px; border-bottom:1px solid #eee;"><?php esc_html_e( 'Consent banner required', 'opti-behavior' ); ?></div>
                            <div style="padding:8px 10px; border-bottom:1px solid #eee; border-left:1px solid #ddd; text-align:center;"><span style="<?php echo esc_attr( $opti_behavior_pill_ok ); ?>"><?php esc_html_e( 'Not required', 'opti-behavior' ); ?></span></div>
                            <div style="padding:8px 10px; border-bottom:1px solid #eee; border-left:1px solid #ddd; text-align:center;"><span style="<?php echo esc_attr( $opti_behavior_pill_req ); ?>"><?php esc_html_e( 'Required', 'opti-behavior' ); ?></span></div>

                            <div style="padding:8px 10px; background:#fafafa;"><?php esc_html_e( 'GDPR compliant', 'opti-behavior' ); ?></div>
                            <div style="padding:8px 10px; border-left:1px solid #ddd; text-align:center; background:#fafafa;"><span style="<?php echo esc_attr( $opti_behavior_pill_ok ); ?>">&#10003; <?php esc_html_e( 'By default', 'opti-behavior' ); ?></span></div>
                            <div style="padding:8px 10px; border-left:1px solid #ddd; text-align:center; background:#fafafa;"><span style="<?php echo esc_attr( $opti_behavior_pill_ok ); ?>">&#10003; <?php esc_html_e( 'With consent', 'opti-behavior' ); ?></span></div>

                            <div style="padding:8px 10px; border-top:2px solid #ddd; font-weight:600;"><?php esc_html_e( 'Tracking quality', 'opti-behavior' ); ?></div>
                            <div style="padding:8px 10px; border-left:1px solid #ddd; border-top:2px solid #ddd; text-align:center;"><span style="display:inline-block; padding:2px 12px; border-radius:10px; font-size:11px; font-weight:600; background:#fff3e0; color:#e65100;"><?php esc_html_e( 'Basic', 'opti-behavior' ); ?></span></div>
                            <div style="padding:8px 10px; border-left:1px solid #ddd; border-top:2px solid #ddd; text-align:center;"><span style="display:inline-block; padding:2px 12px; border-radius:10px; font-size:11px; font-weight:600; background:#e8f5e9; color:#2e7d32;"><?php esc_html_e( 'Complete', 'opti-behavior' ); ?></span></div>

                            <div style="padding:6px 10px; background:#fafafa; font-size:12px; color:#666; border-top:1px solid #eee;"><?php esc_html_e( 'Returning visitors', 'opti-behavior' ); ?></div>
                            <div style="padding:6px 10px; border-left:1px solid #ddd; text-align:center; background:#fafafa; font-size:12px; color:#999;"><?php esc_html_e( 'Not identified', 'opti-behavior' ); ?></div>
                            <div style="padding:6px 10px; border-left:1px solid #ddd; text-align:center; background:#fafafa; font-size:12px; color:#2e7d32;"><?php esc_html_e( 'Recognized across visits', 'opti-behavior' ); ?></div>

                            <div style="padding:6px 10px; font-size:12px; color:#666; border-top:1px solid #eee;"><?php esc_html_e( 'Session accuracy', 'opti-behavior' ); ?></div>
                            <div style="padding:6px 10px; border-left:1px solid #ddd; text-align:center; font-size:12px; color:#999;"><?php esc_html_e( 'Estimated (daily hash)', 'opti-behavior' ); ?></div>
                            <div style="padding:6px 10px; border-left:1px solid #ddd; text-align:center; font-size:12px; color:#2e7d32;"><?php esc_html_e( 'Precise (cookie-based)', 'opti-behavior' ); ?></div>

                            <div style="padding:6px 10px; background:#fafafa; font-size:12px; color:#666; border-top:1px solid #eee;"><?php esc_html_e( 'Visitor location', 'opti-behavior' ); ?></div>
                            <div style="padding:6px 10px; border-left:1px solid #ddd; text-align:center; background:#fafafa; font-size:12px; color:#999;"><?php esc_html_e( 'Not available', 'opti-behavior' ); ?></div>
                            <div style="padding:6px 10px; border-left:1px solid #ddd; text-align:center; background:#fafafa; font-size:12px; color:#2e7d32;"><?php esc_html_e( 'Country & city level', 'opti-behavior' ); ?></div>

                            <div style="padding:6px 10px; font-size:12px; color:#666; border-top:1px solid #eee;"><?php esc_html_e( 'User journey tracking', 'opti-behavior' ); ?></div>
                            <div style="padding:6px 10px; border-left:1px solid #ddd; text-align:center; font-size:12px; color:#999;"><?php esc_html_e( 'Single session only', 'opti-behavior' ); ?></div>
                            <div style="padding:6px 10px; border-left:1px solid #ddd; text-align:center; font-size:12px; color:#2e7d32;"><?php esc_html_e( 'Full cross-session journey', 'opti-behavior' ); ?></div>
                        </div>
                        </div>
                    </div>

                    <form method="post" class="privacy-settings-form">
                        <?php wp_nonce_field( 'opti_behavior_privacy_settings', 'opti_behavior_privacy_nonce' ); ?>

                        <!-- Privacy Mode selector — same padding + 1fr 1fr 1fr as comparison table above -->
                        <div class="ob-privacy-mode-wrap">
                        <div class="ob-privacy-mode-grid" style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0; align-items:stretch; padding:0 20px; margin-bottom:20px;">
                            <div style="padding:14px 10px 14px 0;">
                                <span class="label-text" style="font-weight:600; font-size:14px;">
                                    <?php esc_html_e( 'Privacy Mode', 'opti-behavior' ); ?>
                                    <?php opti_behavior_tooltip_e( $tooltips['privacy_mode']['title'], $tooltips['privacy_mode']['content'], $tooltips['privacy_mode']['simple'], '', array( 'position' => 'right' ) ); ?>
                                </span>
                                <span class="label-description" style="display:block; margin-top:4px; font-size:12px; color:#646970;"><?php esc_html_e( 'Choose how visitor data is collected and stored', 'opti-behavior' ); ?></span>
                            </div>
                            <label class="radio-option ob-privacy-mode-option" style="margin:0; border-radius:6px 0 0 6px; border-right:none; flex-direction:column;">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <input type="radio" name="privacy_mode" value="anonymous" <?php checked( $privacy_mode, 'anonymous' ); ?>>
                                    <span class="radio-label">
                                        <?php esc_html_e( 'Anonymous Mode', 'opti-behavior' ); ?>
                                        <?php opti_behavior_tooltip_e( $tooltips['anonymous_mode']['title'], $tooltips['anonymous_mode']['content'], $tooltips['anonymous_mode']['simple'], $tooltips['anonymous_mode']['example'], array( 'position' => 'right' ) ); ?>
                                    </span>
                                </div>
                                <span class="radio-description" style="margin-top:8px; padding-left:28px; font-size:12px;"><?php esc_html_e( 'GDPR-safe, recommended. No cookies, no client-side storage, no IP storage, no geolocation. No consent banner needed.', 'opti-behavior' ); ?></span>
                            </label>
                            <label class="radio-option ob-privacy-mode-option" style="margin:0; border-radius:0 6px 6px 0; flex-direction:column;">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <input type="radio" name="privacy_mode" value="full" <?php checked( $privacy_mode, 'full' ); ?>>
                                    <span class="radio-label">
                                        <?php esc_html_e( 'Full Tracking', 'opti-behavior' ); ?>
                                        <?php opti_behavior_tooltip_e( $tooltips['full_tracking']['title'], $tooltips['full_tracking']['content'], $tooltips['full_tracking']['simple'], $tooltips['full_tracking']['example'], array( 'position' => 'right' ) ); ?>
                                    </span>
                                </div>
                                <span class="radio-description" style="margin-top:8px; padding-left:28px; font-size:12px;"><?php esc_html_e( 'Cookies, IP addresses, geolocation. More accurate tracking. Consent banner required.', 'opti-behavior' ); ?></span>
                            </label>
                        </div>
                        </div>

                <!-- Consent Banner Configuration (inside same form) -->
                <div class="ob-consent-card<?php echo ( 'full' !== $privacy_mode ) ? ' ob-consent-card--hidden' : ''; ?>" style="margin-top:24px; padding-top:24px; border-top:1px solid #e1e5e9;">
                    <h3 class="section-title" style="margin:0 0 8px;">
                        <span class="section-icon"><i data-lucide="message-square"></i></span>
                        <?php esc_html_e( 'Consent Banner Configuration', 'opti-behavior' ); ?>
                        <?php opti_behavior_tooltip_e( $tooltips['consent_banner']['title'], $tooltips['consent_banner']['content'], $tooltips['consent_banner']['simple'], $tooltips['consent_banner']['example'], array( 'position' => 'right' ) ); ?>
                    </h3>
                    <p class="section-description" style="margin:0 0 16px; color:#646970; font-size:13px;">
                        <?php esc_html_e( 'Configure the cookie consent banner displayed to visitors in Full Tracking mode. If a supported third-party consent plugin is detected, the built-in banner is bypassed automatically.', 'opti-behavior' ); ?>
                    </p>

                    <!-- Detected consent plugin status -->
                    <?php if ( $has_consent_plugin ) : ?>
                    <div class="ob-consent-status-notice ob-detected">
                        <span class="ob-notice-icon"><i data-lucide="check-circle"></i></span>
                        <span>
                            <strong><?php esc_html_e( 'Third-party consent plugin detected:', 'opti-behavior' ); ?></strong>
                            <?php echo esc_html( $detected_plugin_label ); ?>
                        </span>
                    </div>
                    <?php else : ?>
                    <div class="ob-consent-status-notice ob-not-detected">
                        <span class="ob-notice-icon"><i data-lucide="info"></i></span>
                        <span>
                            <?php esc_html_e( 'No supported third-party consent plugin detected.', 'opti-behavior' ); ?>
                            <?php esc_html_e( 'Supported:', 'opti-behavior' ); ?>
                            <strong>Cookiebot, Complianz, CookieYes, Moove GDPR, Cookie Notice, Real Cookie Banner, Borlabs Cookie, WP DSGVO Tools.</strong>
                        </span>
                    </div>
                    <?php endif; ?>

                        <?php
                        $banner_prefer = isset( $options['consent_banner_prefer'] ) ? $options['consent_banner_prefer'] : 'auto';
                        ?>

                        <!-- Banner preference: which consent system to use -->
                        <div class="setting-item" style="margin-bottom:20px;">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Consent Banner Source', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['consent_banner_source']['title'], $tooltips['consent_banner_source']['content'], $tooltips['consent_banner_source']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Choose which consent banner to display to visitors', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="consent_banner_prefer" value="auto" <?php checked( $banner_prefer, 'auto' ); ?>>
                                        <div>
                                            <span class="radio-label"><?php esc_html_e( 'Auto-detect (recommended)', 'opti-behavior' ); ?></span>
                                            <?php if ( $has_consent_plugin ) : ?>
                                            <span class="radio-description" style="color:#2e7d32;">
                                                &#10003; <?php
                                                printf(
                                                    /* translators: %s: Name of the detected consent plugin */
                                                    esc_html__( 'Detected: %s — consent will be handled by this plugin.', 'opti-behavior' ),
                                                    '<strong>' . esc_html( $detected_plugin_label ) . '</strong>'
                                                );
                                                ?>
                                            </span>
                                            <?php else : ?>
                                            <span class="radio-description" style="color:#b26200;">
                                                &#9888; <?php esc_html_e( 'No third-party consent plugin detected — the built-in Opti-Behavior banner will be displayed. To disable the banner, switch to Anonymous Mode.', 'opti-behavior' ); ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="consent_banner_prefer" value="builtin" <?php checked( $banner_prefer, 'builtin' ); ?>>
                                        <div>
                                            <span class="radio-label"><?php esc_html_e( 'Always use built-in banner', 'opti-behavior' ); ?></span>
                                            <span class="radio-description"><?php esc_html_e( 'Always show the Opti-Behavior consent banner, even if a third-party plugin is installed.', 'opti-behavior' ); ?></span>
                                        </div>
                                    </label>
                                    <?php if ( $has_consent_plugin ) : ?>
                                    <label class="radio-option">
                                        <input type="radio" name="consent_banner_prefer" value="thirdparty" <?php checked( $banner_prefer, 'thirdparty' ); ?>>
                                        <div>
                                            <span class="radio-label">
                                                <?php
                                                printf(
                                                    /* translators: %s: Name of the detected consent plugin */
                                                    esc_html__( 'Always use %s', 'opti-behavior' ),
                                                    esc_html( $detected_plugin_label )
                                                );
                                                ?>
                                            </span>
                                            <span class="radio-description"><?php esc_html_e( 'Always defer consent handling to your third-party plugin and never show the built-in banner.', 'opti-behavior' ); ?></span>
                                        </div>
                                    </label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Banner position -->
                        <div class="setting-item" style="margin-bottom:20px;">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Banner Position', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_position']['title'], $tooltips['banner_position']['content'], $tooltips['banner_position']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Where the consent banner appears on the page', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="consent_banner_position" value="compact-card" <?php checked( $options['consent_banner_position'], 'compact-card' ); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'Compact Card', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Recommended. A smaller card-style banner with compact buttons.', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="consent_banner_position" value="bottom-bar" <?php checked( $options['consent_banner_position'], 'bottom-bar' ); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'Bottom Bar', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Fixed bar across the bottom of the page.', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="consent_banner_position" value="top-bar" <?php checked( $options['consent_banner_position'], 'top-bar' ); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'Top Bar', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Fixed bar across the top of the page.', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="consent_banner_position" value="popup" <?php checked( $options['consent_banner_position'], 'popup' ); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'Centered Popup', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Modal dialog centered on the page with a backdrop overlay.', 'opti-behavior' ); ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Color pickers -->
                        <div class="setting-item" style="margin-bottom:20px;">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Banner Colors', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_colors']['title'], $tooltips['banner_colors']['content'], $tooltips['banner_colors']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Customize the banner appearance to match your site branding', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="ob-color-pickers">
                                    <div class="ob-color-picker-item">
                                        <label for="ob_consent_accent_color"><?php esc_html_e( 'Accent Color', 'opti-behavior' ); ?></label>
                                        <input
                                            type="color"
                                            id="ob_consent_accent_color"
                                            name="consent_banner_accent_color"
                                            value="<?php echo esc_attr( $options['consent_banner_accent_color'] ); ?>"
                                        >
                                        <span class="ob-color-hex"><?php echo esc_html( $options['consent_banner_accent_color'] ); ?></span>
                                    </div>
                                    <div class="ob-color-picker-item">
                                        <label for="ob_consent_bg_color"><?php esc_html_e( 'Background Color', 'opti-behavior' ); ?></label>
                                        <input
                                            type="color"
                                            id="ob_consent_bg_color"
                                            name="consent_banner_bg_color"
                                            value="<?php echo esc_attr( $options['consent_banner_bg_color'] ); ?>"
                                        >
                                        <span class="ob-color-hex"><?php echo esc_html( $options['consent_banner_bg_color'] ); ?></span>
                                    </div>
                                    <div class="ob-color-picker-item">
                                        <label for="ob_consent_text_color"><?php esc_html_e( 'Text Color', 'opti-behavior' ); ?></label>
                                        <input
                                            type="color"
                                            id="ob_consent_text_color"
                                            name="consent_banner_text_color"
                                            value="<?php echo esc_attr( $options['consent_banner_text_color'] ); ?>"
                                        >
                                        <span class="ob-color-hex"><?php echo esc_html( $options['consent_banner_text_color'] ); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Banner title -->
                        <div class="setting-item" style="margin-bottom:16px;">
                            <label class="setting-label" for="ob_consent_banner_title">
                                <span class="label-text"><?php esc_html_e( 'Banner Title', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_title']['title'], $tooltips['banner_title']['content'], $tooltips['banner_title']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Leave empty to use the default translatable title', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <input
                                    type="text"
                                    id="ob_consent_banner_title"
                                    name="consent_banner_title"
                                    value="<?php echo esc_attr( $options['consent_banner_title'] ); ?>"
                                    placeholder="<?php esc_attr_e( 'We value your privacy', 'opti-behavior' ); ?>"
                                    class="regular-text"
                                >
                            </div>
                        </div>

                        <!-- Banner message -->
                        <div class="setting-item" style="margin-bottom:24px;">
                            <label class="setting-label" for="ob_consent_banner_message">
                                <span class="label-text"><?php esc_html_e( 'Banner Message', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_message']['title'], $tooltips['banner_message']['content'], $tooltips['banner_message']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Leave empty to use the default translatable message', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <textarea
                                    id="ob_consent_banner_message"
                                    name="consent_banner_message"
                                    rows="4"
                                    class="large-text"
                                    placeholder="<?php esc_attr_e( 'We use analytics cookies to understand how visitors interact with our website. This helps us improve content and user experience.', 'opti-behavior' ); ?>"
                                ><?php echo esc_textarea( $options['consent_banner_message'] ); ?></textarea>
                            </div>
                        </div>

                        <!-- Banner button labels -->
                        <div class="setting-item" style="margin-bottom:20px;">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Banner Button Text', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_button_text']['title'], $tooltips['banner_button_text']['content'], $tooltips['banner_button_text']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Translate or customize the visible button labels. Leave empty to use defaults.', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="ob-consent-field-grid ob-consent-field-grid--buttons">
                                    <div class="ob-consent-field">
                                        <label for="ob_consent_customize_label"><?php esc_html_e( 'Customize Button', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_customize_button']['title'], $tooltips['banner_customize_button']['content'], $tooltips['banner_customize_button']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                        <input type="text" id="ob_consent_customize_label" name="consent_banner_customize_label" value="<?php echo esc_attr( $options['consent_banner_customize_label'] ); ?>" placeholder="<?php esc_attr_e( 'Customize', 'opti-behavior' ); ?>" class="regular-text">
                                    </div>
                                    <div class="ob-consent-field">
                                        <label for="ob_consent_reject_label"><?php esc_html_e( 'Reject Button', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_reject_button']['title'], $tooltips['banner_reject_button']['content'], $tooltips['banner_reject_button']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                        <input type="text" id="ob_consent_reject_label" name="consent_banner_reject_label" value="<?php echo esc_attr( $options['consent_banner_reject_label'] ); ?>" placeholder="<?php esc_attr_e( 'Reject All', 'opti-behavior' ); ?>" class="regular-text">
                                    </div>
                                    <div class="ob-consent-field">
                                        <label for="ob_consent_accept_label"><?php esc_html_e( 'Accept Button', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_accept_button']['title'], $tooltips['banner_accept_button']['content'], $tooltips['banner_accept_button']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                        <input type="text" id="ob_consent_accept_label" name="consent_banner_accept_label" value="<?php echo esc_attr( $options['consent_banner_accept_label'] ); ?>" placeholder="<?php esc_attr_e( 'Accept All', 'opti-behavior' ); ?>" class="regular-text">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Banner advanced text -->
                        <div class="setting-item" style="margin-bottom:24px;">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Advanced Banner Text', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['advanced_banner_text']['title'], $tooltips['advanced_banner_text']['content'], $tooltips['advanced_banner_text']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Customize the policy link and the text shown inside the Customize panel.', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <input type="checkbox" id="ob_consent_advanced_text_toggle" class="ob-consent-advanced-toggle">
                                <label class="ob-consent-advanced-toggle-row" for="ob_consent_advanced_text_toggle">
                                    <span class="ob-consent-advanced-toggle-indicator" aria-hidden="true"></span>
                                    <span class="ob-consent-advanced-toggle-text"><?php esc_html_e( 'Show advanced banner text options', 'opti-behavior' ); ?></span>
                                </label>
                                <div class="ob-consent-advanced-panel" id="ob_consent_advanced_text_panel">
                                    <div class="ob-consent-field-grid ob-consent-field-grid--two">
                                        <div class="ob-consent-field">
                                            <label for="ob_consent_policy_label"><?php esc_html_e( 'Policy Link Label', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_policy_label']['title'], $tooltips['banner_policy_label']['content'], $tooltips['banner_policy_label']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                            <input type="text" id="ob_consent_policy_label" name="consent_banner_policy_label" value="<?php echo esc_attr( $options['consent_banner_policy_label'] ); ?>" placeholder="<?php esc_attr_e( 'Cookie Policy', 'opti-behavior' ); ?>" class="regular-text">
                                        </div>
                                        <div class="ob-consent-field">
                                            <label for="ob_consent_policy_url"><?php esc_html_e( 'Policy Link URL', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_policy_url']['title'], $tooltips['banner_policy_url']['content'], $tooltips['banner_policy_url']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                            <input type="url" id="ob_consent_policy_url" name="consent_banner_policy_url" value="<?php echo esc_url( $options['consent_banner_policy_url'] ); ?>" placeholder="<?php echo esc_url( function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : home_url( '/' ) ); ?>" class="regular-text">
                                        </div>
                                    </div>
                                    <div class="ob-consent-field-grid ob-consent-field-grid--two">
                                        <div class="ob-consent-field">
                                            <label for="ob_consent_analytics_label"><?php esc_html_e( 'Customize Panel Label', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_customize_panel_label']['title'], $tooltips['banner_customize_panel_label']['content'], $tooltips['banner_customize_panel_label']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                            <input type="text" id="ob_consent_analytics_label" name="consent_banner_analytics_label" value="<?php echo esc_attr( $options['consent_banner_analytics_label'] ); ?>" placeholder="<?php esc_attr_e( 'Analytics cookies', 'opti-behavior' ); ?>" class="regular-text">
                                        </div>
                                        <div class="ob-consent-field">
                                            <label for="ob_consent_save_label"><?php esc_html_e( 'Save Choice Button', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_save_choice_button']['title'], $tooltips['banner_save_choice_button']['content'], $tooltips['banner_save_choice_button']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                            <input type="text" id="ob_consent_save_label" name="consent_banner_save_label" value="<?php echo esc_attr( $options['consent_banner_save_label'] ); ?>" placeholder="<?php esc_attr_e( 'Save my choice', 'opti-behavior' ); ?>" class="regular-text">
                                        </div>
                                    </div>
                                    <label for="ob_consent_analytics_description"><?php esc_html_e( 'Customize Panel Description', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['banner_customize_panel_description']['title'], $tooltips['banner_customize_panel_description']['content'], $tooltips['banner_customize_panel_description']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                    <textarea id="ob_consent_analytics_description" name="consent_banner_analytics_description" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Help us understand how visitors interact with our site by collecting anonymous usage data.', 'opti-behavior' ); ?>"><?php echo esc_textarea( $options['consent_banner_analytics_description'] ); ?></textarea>
                                </div>
                            </div>
                        </div>

                </div><!-- /.ob-consent-card -->

                        <div class="settings-actions" style="margin-top:24px;">
                            <button type="submit" name="opti_behavior_privacy_settings_submit" class="btn-save">
                                <span class="btn-icon"><i data-lucide="save"></i></span>
                                <?php esc_html_e( 'Save Settings', 'opti-behavior' ); ?>
                            </button>
                        </div>
                    </form>
                </div>

            </div>
            <?php
        }

        /**
         * Handle Privacy & GDPR settings save
         */
        private function handle_privacy_settings_save_impl() {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function
            $privacy_mode_input = isset( $_POST['privacy_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['privacy_mode'] ) ) : 'anonymous';
            // phpcs:enable WordPress.Security.NonceVerification.Missing

            // Whitelist of allowed values.
            $allowed_modes = array( 'anonymous', 'full' );
            if ( ! in_array( $privacy_mode_input, $allowed_modes, true ) ) {
                $privacy_mode_input = 'anonymous';
            }

            // Load, update and persist the main options array.
            $options = maybe_unserialize( get_option( 'opti_behavior_heatmap_option', array() ) );
            if ( ! is_array( $options ) ) {
                $options = array();
            }
            $options['privacy_mode'] = $privacy_mode_input;

            // Also save consent banner settings (unified form).
            if ( 'full' === $privacy_mode_input ) {
                $this->merge_consent_banner_options( $options );
            }

            update_option( 'opti_behavior_heatmap_option', maybe_serialize( $options ) );

            add_settings_error(
                'opti_behavior_settings',
                'privacy_mode_updated',
                esc_html__( 'Settings saved successfully.', 'opti-behavior' ),
                'success'
            );
            set_transient( 'settings_errors', get_settings_errors(), 30 );
        }

        /**
         * Merge consent banner POST fields into the given options array (by reference).
         *
         * Extracted so both the unified save handler and the legacy consent-only handler can use it.
         *
         * @param array &$options Options array to update in-place.
         */
        private function merge_consent_banner_options( &$options ) {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function.

            // Banner preference.
            $allowed_prefer = array( 'auto', 'builtin', 'thirdparty' );
            $banner_prefer  = isset( $_POST['consent_banner_prefer'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_prefer'] ) )
                : 'auto';
            if ( ! in_array( $banner_prefer, $allowed_prefer, true ) ) {
                $banner_prefer = 'auto';
            }
            $options['consent_banner_prefer'] = $banner_prefer;

            // Enabled toggle: always true (checkbox removed; kept as true for frontend compatibility).
            $options['consent_banner_enabled'] = true;

            // Position.
            $allowed_positions = array( 'compact-card', 'bottom-bar', 'top-bar', 'popup' );
            $pos = isset( $_POST['consent_banner_position'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_position'] ) )
                : 'compact-card';
            $options['consent_banner_position'] = in_array( $pos, $allowed_positions, true ) ? $pos : 'compact-card';
            $options['consent_banner_position_user_set'] = true;

            // Colors.
            $options['consent_banner_accent_color'] = isset( $_POST['consent_banner_accent_color'] )
                ? ( sanitize_hex_color( wp_unslash( $_POST['consent_banner_accent_color'] ) ) ?: '#2e7d32' )
                : '#2e7d32';
            $options['consent_banner_bg_color'] = isset( $_POST['consent_banner_bg_color'] )
                ? ( sanitize_hex_color( wp_unslash( $_POST['consent_banner_bg_color'] ) ) ?: '#ffffff' )
                : '#ffffff';
            $options['consent_banner_text_color'] = isset( $_POST['consent_banner_text_color'] )
                ? ( sanitize_hex_color( wp_unslash( $_POST['consent_banner_text_color'] ) ) ?: '#333333' )
                : '#333333';

            // Text fields.
            $options['consent_banner_title'] = isset( $_POST['consent_banner_title'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_title'] ) )
                : '';
            $options['consent_banner_message'] = isset( $_POST['consent_banner_message'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['consent_banner_message'] ) )
                : '';
            $options['consent_banner_accept_label'] = isset( $_POST['consent_banner_accept_label'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_accept_label'] ) )
                : '';
            $options['consent_banner_reject_label'] = isset( $_POST['consent_banner_reject_label'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_reject_label'] ) )
                : '';
            $options['consent_banner_customize_label'] = isset( $_POST['consent_banner_customize_label'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_customize_label'] ) )
                : '';
            $options['consent_banner_save_label'] = isset( $_POST['consent_banner_save_label'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_save_label'] ) )
                : '';
            $options['consent_banner_analytics_label'] = isset( $_POST['consent_banner_analytics_label'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_analytics_label'] ) )
                : '';
            $options['consent_banner_analytics_description'] = isset( $_POST['consent_banner_analytics_description'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['consent_banner_analytics_description'] ) )
                : '';
            $options['consent_banner_policy_label'] = isset( $_POST['consent_banner_policy_label'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_policy_label'] ) )
                : '';
            $options['consent_banner_policy_url'] = isset( $_POST['consent_banner_policy_url'] )
                ? esc_url_raw( wp_unslash( $_POST['consent_banner_policy_url'] ) )
                : '';

            // phpcs:enable WordPress.Security.NonceVerification.Missing
        }

        /**
         * Handle Consent Banner settings save.
         *
         * Validates and persists the 7 consent banner option keys into the
         * main `opti_behavior_heatmap_option` array. Nonce and capability
         * checks are performed in the calling `render_settings_impl()` handler.
         *
         * @since 1.0.3
         * @return void
         */
        private function handle_consent_banner_settings_save_impl() {
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function.

            // Banner preference: auto / builtin / thirdparty.
            $allowed_prefer = array( 'auto', 'builtin', 'thirdparty' );
            $banner_prefer  = isset( $_POST['consent_banner_prefer'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_prefer'] ) )
                : 'auto';
            if ( ! in_array( $banner_prefer, $allowed_prefer, true ) ) {
                $banner_prefer = 'auto';
            }

            // Boolean: always true (checkbox removed; kept as true for frontend compatibility).
            $banner_enabled = true;

            // Banner position — whitelist of allowed values.
            $allowed_positions = array( 'compact-card', 'bottom-bar', 'top-bar', 'popup' );
            $banner_position   = isset( $_POST['consent_banner_position'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_position'] ) )
                : 'compact-card';
            if ( ! in_array( $banner_position, $allowed_positions, true ) ) {
                $banner_position = 'compact-card';
            }

            // Color pickers — validate as hex color codes.
            $accent_color = isset( $_POST['consent_banner_accent_color'] )
                ? sanitize_hex_color( wp_unslash( $_POST['consent_banner_accent_color'] ) )
                : '#2e7d32';
            if ( empty( $accent_color ) ) {
                $accent_color = '#2e7d32';
            }

            $bg_color = isset( $_POST['consent_banner_bg_color'] )
                ? sanitize_hex_color( wp_unslash( $_POST['consent_banner_bg_color'] ) )
                : '#ffffff';
            if ( empty( $bg_color ) ) {
                $bg_color = '#ffffff';
            }

            $text_color = isset( $_POST['consent_banner_text_color'] )
                ? sanitize_hex_color( wp_unslash( $_POST['consent_banner_text_color'] ) )
                : '#333333';
            if ( empty( $text_color ) ) {
                $text_color = '#333333';
            }

            // Text fields — allow empty strings (empty = use default translatable strings).
            $banner_title = isset( $_POST['consent_banner_title'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_title'] ) )
                : '';

            $banner_message = isset( $_POST['consent_banner_message'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['consent_banner_message'] ) )
                : '';

            $banner_accept_label = isset( $_POST['consent_banner_accept_label'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_accept_label'] ) )
                : '';
            $banner_reject_label = isset( $_POST['consent_banner_reject_label'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_reject_label'] ) )
                : '';
            $banner_customize_label = isset( $_POST['consent_banner_customize_label'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_customize_label'] ) )
                : '';
            $banner_save_label = isset( $_POST['consent_banner_save_label'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_save_label'] ) )
                : '';
            $banner_analytics_label = isset( $_POST['consent_banner_analytics_label'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_analytics_label'] ) )
                : '';
            $banner_analytics_description = isset( $_POST['consent_banner_analytics_description'] )
                ? sanitize_textarea_field( wp_unslash( $_POST['consent_banner_analytics_description'] ) )
                : '';
            $banner_policy_label = isset( $_POST['consent_banner_policy_label'] )
                ? sanitize_text_field( wp_unslash( $_POST['consent_banner_policy_label'] ) )
                : '';
            $banner_policy_url = isset( $_POST['consent_banner_policy_url'] )
                ? esc_url_raw( wp_unslash( $_POST['consent_banner_policy_url'] ) )
                : '';

            // phpcs:enable WordPress.Security.NonceVerification.Missing

            // Load, update and persist the main options array.
            $options = maybe_unserialize( get_option( 'opti_behavior_heatmap_option', array() ) );
            if ( ! is_array( $options ) ) {
                $options = array();
            }

            $options['consent_banner_prefer']       = $banner_prefer;
            $options['consent_banner_enabled']      = $banner_enabled;
            $options['consent_banner_position']     = $banner_position;
            $options['consent_banner_position_user_set'] = true;
            $options['consent_banner_accent_color'] = $accent_color;
            $options['consent_banner_bg_color']     = $bg_color;
            $options['consent_banner_text_color']   = $text_color;
            $options['consent_banner_title']        = $banner_title;
            $options['consent_banner_message']      = $banner_message;
            $options['consent_banner_accept_label'] = $banner_accept_label;
            $options['consent_banner_reject_label'] = $banner_reject_label;
            $options['consent_banner_customize_label'] = $banner_customize_label;
            $options['consent_banner_save_label']   = $banner_save_label;
            $options['consent_banner_analytics_label'] = $banner_analytics_label;
            $options['consent_banner_analytics_description'] = $banner_analytics_description;
            $options['consent_banner_policy_label'] = $banner_policy_label;
            $options['consent_banner_policy_url']   = $banner_policy_url;

            update_option( 'opti_behavior_heatmap_option', maybe_serialize( $options ) );

            add_settings_error(
                'opti_behavior_settings',
                'consent_banner_updated',
                esc_html__( 'Consent banner settings saved successfully.', 'opti-behavior' ),
                'success'
            );
            set_transient( 'settings_errors', get_settings_errors(), 30 );
        }

        /**
         * Impl: Get current settings
         */
        private function get_current_settings_impl() {
            $defaults = array(
                'accuracy' => 2,
                'report_non_singular' => 1,
                'ajax_delay_time' => 3000,
                'drawing_points' => 3000,
                'count_bar' => 1,
                'keep_url_hash' => false
            );

            $options = maybe_unserialize( get_option('opti_behavior_heatmap_option', array()) );
            $options = wp_parse_args( $options, $defaults );

            // Map old integer format to new string format for display
            // Handle drawing_points: 0 = unlimited, otherwise use the integer value as string
            $drawing_points_value = '3000'; // default
            if (isset($options['drawing_points'])) {
                if ($options['drawing_points'] == 0) {
                    $drawing_points_value = 'unlimited';
                } else {
                    $drawing_points_value = strval($options['drawing_points']);
                }
            }

            // Non-singular pages setting
            $non_singular_display = ($options['report_non_singular'] == 1) ? 'report' : 'no_report';

            // URL hash handling setting
            $url_hash_display = ($options['keep_url_hash'] == true) ? 'individual' : 'integrated';

            return array(
                'accuracy' => ($options['accuracy'] == 2) ? 'high' : 'standard',
                'non_singular' => $non_singular_display,
                'ajax_delay' => isset($options['ajax_delay_time']) ? strval($options['ajax_delay_time']) : '3000',
                'drawing_points' => $drawing_points_value,
                'count_bar' => ($options['count_bar'] == 1) ? 'show' : 'hide',
                'url_hash' => $url_hash_display
            );
        }

        /**
         * Impl: Save settings
         */
        private function handle_settings_save_impl() {
            $nonce = isset($_POST['opti_behavior_settings_nonce']) ? sanitize_text_field( wp_unslash( $_POST['opti_behavior_settings_nonce'] ) ) : '';
            if (!wp_verify_nonce($nonce, 'opti_behavior_settings_save')) {
                wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) );
            }
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
            }

            // Get current options to preserve other settings
            $options = maybe_unserialize( get_option('opti_behavior_heatmap_option', array()) );
            if (!is_array($options)) {
                $options = array();
            }

            // Map new string format to old integer format
            $accuracy_input = isset($_POST['accuracy']) ? sanitize_text_field( wp_unslash( $_POST['accuracy'] ) ) : 'normal';
            $accuracy_value = ($accuracy_input === 'high') ? 2 : 1;

            // Non-singular pages setting
            $non_singular_input = isset($_POST['non_singular']) ? sanitize_text_field( wp_unslash( $_POST['non_singular'] ) ) : '';
            $non_singular_value = ($non_singular_input === 'report') ? 1 : 0;

            $ajax_delay_input = isset($_POST['ajax_delay']) ? sanitize_text_field( wp_unslash( $_POST['ajax_delay'] ) ) : '3000';
            $ajax_delay_value = intval($ajax_delay_input);

            // Handle drawing_points: 'unlimited' = 0, otherwise convert string to integer
            $drawing_points_input = isset($_POST['drawing_points']) ? sanitize_text_field( wp_unslash( $_POST['drawing_points'] ) ) : '3000';
            if ($drawing_points_input === 'unlimited') {
                $drawing_points_value = 0;
            } else {
                $drawing_points_value = intval($drawing_points_input);
            }

            $count_bar_input = isset($_POST['count_bar']) ? sanitize_text_field( wp_unslash( $_POST['count_bar'] ) ) : '';
            $count_bar_value = ($count_bar_input === 'show') ? 1 : 0;

            // URL Hash Handling setting
            $url_hash_input = isset($_POST['url_hash']) ? sanitize_text_field( wp_unslash( $_POST['url_hash'] ) ) : '';
            $url_hash_value = ($url_hash_input === 'individual') ? true : false;

            // Check if URL hash setting changed
            $url_hash_changed = isset($options['keep_url_hash']) && $options['keep_url_hash'] !== $url_hash_value;

            // Update only the changed settings
            $options['accuracy'] = $accuracy_value;
            $options['report_non_singular'] = $non_singular_value;
            $options['ajax_delay_time'] = $ajax_delay_value;
            $options['drawing_points'] = $drawing_points_value;
            $options['count_bar'] = $count_bar_value;
            $options['keep_url_hash'] = $url_hash_value;

            update_option('opti_behavior_heatmap_option', maybe_serialize($options));

            // If URL hash setting changed, rebuild all url2 fields
            if ($url_hash_changed) {
                $database = $this->heatmap->get_database();
                $count = $database->rebuild_all_url2();
                add_action('admin_notices', function() use ($count) {
                    /* translators: %s: number of rebuilt URLs */
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Settings saved successfully! Rebuilt %s page URLs.', 'opti-behavior' ), $count ) ) . '</p></div>';
                });
            } else {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully!', 'opti-behavior' ) . '</p></div>';
                });
            }
        }

        /**
         * Handle manual data recovery
         */
        private function handle_manual_recovery_impl() {
            $data_protection = $this->heatmap->get_data_protection();
            if ($data_protection) {
                $result = $data_protection->manual_recovery();

                $event_count = isset($result['event_count']) ? $result['event_count'] : 0;
                $accessible_count = isset($result['accessible_count']) ? $result['accessible_count'] : 0;
                $auto_fixed = isset($result['auto_fixed']) ? $result['auto_fixed'] : array();

                if (!empty($auto_fixed)) {
                    add_action('admin_notices', function() use ($event_count, $accessible_count, $auto_fixed) {
                        echo '<div class="notice notice-success is-dismissible">';
                        echo '<p><strong>' . esc_html__( '✅ Data Recovery Completed!', 'opti-behavior' ) . '</strong></p>';
                        /* translators: %1$s: number of events, %2$s: number of accessible heatmaps */
                        echo '<p>' . esc_html( sprintf( __( 'Found and recovered: %1$s events, %2$s accessible heatmaps.', 'opti-behavior' ), number_format($event_count), number_format($accessible_count) ) ) . '</p>';
                        /* translators: %s: list of fixed issues */
                        echo '<p>' . esc_html( sprintf( __( 'Issues fixed: %s', 'opti-behavior' ), implode(', ', array_map(function($fix) {
                            return str_replace('_', ' ', ucfirst($fix));
                        }, $auto_fixed)) ) ) . '</p>';
                        echo '</div>';
                    });
                } else {
                    add_action('admin_notices', function() use ($event_count) {
                        echo '<div class="notice notice-info is-dismissible">';
                        echo '<p><strong>' . esc_html__( '✅ Data Check Completed!', 'opti-behavior' ) . '</strong></p>';
                        /* translators: %s: number of events */
                        echo '<p>' . esc_html( sprintf( __( 'No issues detected. Your data is healthy with %s events.', 'opti-behavior' ), number_format($event_count) ) ) . '</p>';
                        echo '</div>';
                    });
                }
            }
        }

        /**
         * Render storage stats section.
         *
         * Emits only a static shell with skeleton placeholders — no database
         * or filesystem work happens here, so the page paints instantly.
         * All data loads asynchronously via the optibehavior_storage_stats_*
         * AJAX endpoints (see trait-opti-behavior-ajax-handlers.php) and is
         * injected by the storage-stats module in assets/js/settings.js.
         */
        private function render_storage_stats_impl() {
            $tooltips = opti_behavior_get_settings_tooltips();
            ?>
            <div class="opti-behavior-settings-form" id="ob-storage-stats">
                <!-- Summary Stats Section -->
                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon"><i data-lucide="hard-drive"></i></span>
                            <?php esc_html_e( 'Storage Overview', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description">
                            <?php esc_html_e( 'View storage usage for database tables and file storage. Understand which elements consume the most space.', 'opti-behavior' ); ?>
                        </p>
                    </div>

                    <div class="storage-stats-grid">
                        <div class="storage-stat-card storage-stat-card-primary">
                            <div class="storage-stat-icon"><i data-lucide="database"></i></div>
                            <div class="storage-stat-content">
                                <div class="storage-stat-label"><?php esc_html_e( 'Database Size', 'opti-behavior' ); ?></div>
                                <div class="storage-stat-value ob-skeleton" data-stat="db_size">&nbsp;</div>
                            </div>
                            <div class="storage-stat-tooltip"><?php opti_behavior_tooltip_e( $tooltips['database_size']['title'], $tooltips['database_size']['content'], $tooltips['database_size']['simple'], '', array( 'position' => 'bottom' ) ); ?></div>
                        </div>

                        <div class="storage-stat-card storage-stat-card-secondary">
                            <div class="storage-stat-icon"><i data-lucide="folder"></i></div>
                            <div class="storage-stat-content">
                                <div class="storage-stat-label"><?php esc_html_e( 'File Storage', 'opti-behavior' ); ?></div>
                                <div class="storage-stat-value ob-skeleton" data-stat="file_size">&nbsp;</div>
                            </div>
                            <div class="storage-stat-tooltip"><?php opti_behavior_tooltip_e( $tooltips['file_storage']['title'], $tooltips['file_storage']['content'], $tooltips['file_storage']['simple'], '', array( 'position' => 'bottom' ) ); ?></div>
                        </div>

                        <div class="storage-stat-card">
                            <div class="storage-stat-icon"><i data-lucide="table"></i></div>
                            <div class="storage-stat-content">
                                <div class="storage-stat-label"><?php esc_html_e( 'Total Tables', 'opti-behavior' ); ?></div>
                                <div class="storage-stat-value ob-skeleton" data-stat="tables">&nbsp;</div>
                            </div>
                            <div class="storage-stat-tooltip"><?php opti_behavior_tooltip_e( $tooltips['total_tables']['title'], $tooltips['total_tables']['content'], $tooltips['total_tables']['simple'], '', array( 'position' => 'bottom' ) ); ?></div>
                        </div>

                        <div class="storage-stat-card">
                            <div class="storage-stat-icon"><i data-lucide="rows-3"></i></div>
                            <div class="storage-stat-content">
                                <div class="storage-stat-label"><?php esc_html_e( 'Total Records', 'opti-behavior' ); ?></div>
                                <div class="storage-stat-value ob-skeleton" data-stat="records">&nbsp;</div>
                            </div>
                            <div class="storage-stat-tooltip"><?php opti_behavior_tooltip_e( $tooltips['total_records']['title'], $tooltips['total_records']['content'], $tooltips['total_records']['simple'], '', array( 'position' => 'bottom' ) ); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Database Tables Section -->
                <div class="settings-section" style="margin-top: 30px;">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon"><i data-lucide="database"></i></span>
                            <?php esc_html_e( 'Database Tables', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description">
                            <?php esc_html_e( 'Detailed breakdown of storage usage per database table, sorted by size.', 'opti-behavior' ); ?>
                        </p>
                    </div>

                    <div class="storage-table-container">
                        <table class="storage-table">
                            <thead>
                                <tr>
                                    <th class="storage-table-name"><?php esc_html_e( 'Table Name', 'opti-behavior' ); ?></th>
                                    <th class="storage-table-rows"><?php esc_html_e( 'Rows', 'opti-behavior' ); ?></th>
                                    <th class="storage-table-data"><?php esc_html_e( 'Data', 'opti-behavior' ); ?></th>
                                    <th class="storage-table-index"><?php esc_html_e( 'Index', 'opti-behavior' ); ?></th>
                                    <th class="storage-table-total"><?php esc_html_e( 'Total', 'opti-behavior' ); ?></th>
                                    <th class="storage-table-bar"><?php esc_html_e( 'Usage', 'opti-behavior' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="ob-storage-tables-body">
                                <?php for ( $i = 0; $i < 6; $i++ ) : ?>
                                <tr class="ob-skeleton-row">
                                    <td class="storage-table-name"><span class="ob-skeleton ob-skeleton-line ob-skeleton-wide"></span></td>
                                    <td class="storage-table-rows"><span class="ob-skeleton ob-skeleton-line"></span></td>
                                    <td class="storage-table-data"><span class="ob-skeleton ob-skeleton-line"></span></td>
                                    <td class="storage-table-index"><span class="ob-skeleton ob-skeleton-line"></span></td>
                                    <td class="storage-table-total"><span class="ob-skeleton ob-skeleton-line"></span></td>
                                    <td class="storage-table-bar"><span class="ob-skeleton ob-skeleton-line"></span></td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- File Storage Section -->
                <div class="settings-section" style="margin-top: 30px;">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon"><i data-lucide="folder-open"></i></span>
                            <?php esc_html_e( 'File Storage', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description">
                            <?php esc_html_e( 'Storage used by session recordings and cached data files.', 'opti-behavior' ); ?>
                        </p>
                    </div>

                    <div class="storage-file-info">
                        <div class="storage-file-path">
                            <div class="file-path-label">
                                <i data-lucide="folder"></i>
                                <?php esc_html_e( 'Storage Location', 'opti-behavior' ); ?>
                            </div>
                            <code class="file-path-value ob-skeleton" data-file-stat="path">&nbsp;</code>
                        </div>

                        <div class="storage-file-stats-grid">
                            <div class="storage-file-stat">
                                <div class="file-stat-icon"><i data-lucide="hard-drive"></i></div>
                                <div class="file-stat-content">
                                    <span class="file-stat-value ob-skeleton" data-file-stat="total_size">&nbsp;</span>
                                    <span class="file-stat-label"><?php esc_html_e( 'Total Size', 'opti-behavior' ); ?></span>
                                </div>
                            </div>
                            <div class="storage-file-stat">
                                <div class="file-stat-icon"><i data-lucide="file"></i></div>
                                <div class="file-stat-content">
                                    <span class="file-stat-value ob-skeleton" data-file-stat="file_count">&nbsp;</span>
                                    <span class="file-stat-label"><?php esc_html_e( 'Files', 'opti-behavior' ); ?></span>
                                </div>
                            </div>
                            <div class="storage-file-stat">
                                <div class="file-stat-icon"><i data-lucide="folder-tree"></i></div>
                                <div class="file-stat-content">
                                    <span class="file-stat-value ob-skeleton" data-file-stat="folder_count">&nbsp;</span>
                                    <span class="file-stat-label"><?php esc_html_e( 'Folders', 'opti-behavior' ); ?></span>
                                </div>
                            </div>
                            <div class="storage-file-stat">
                                <div class="file-stat-icon" data-file-stat-icon="status"><i data-lucide="circle"></i></div>
                                <div class="file-stat-content">
                                    <span class="file-stat-value ob-skeleton" data-file-stat="status">&nbsp;</span>
                                    <span class="file-stat-label"><?php esc_html_e( 'Status', 'opti-behavior' ); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="storage-file-notice" id="ob-storage-file-notice" style="display: none;">
                            <i data-lucide="info"></i>
                            <span><?php esc_html_e( 'File storage folder will be created automatically when recordings or file-based storage is enabled.', 'opti-behavior' ); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * Get the storage table definition map (table suffix => friendly name + icon).
         *
         * Single source of truth for which plugin tables the Storage Stats tab
         * reports on. Also serves as the allowlist for AJAX exact-count requests
         * (never trust client input for table names).
         *
         * @since 1.8.x
         * @return array<string, array{name: string, icon: string}>
         */
        private function get_storage_table_definitions() {
            return array(
                'optibehavior_events' => array( 'name' => __( 'Click Events', 'opti-behavior' ), 'icon' => 'mouse-pointer-click' ),
                'optibehavior_pages' => array( 'name' => __( 'Tracked Pages', 'opti-behavior' ), 'icon' => 'file-text' ),
                'optibehavior_sessions' => array( 'name' => __( 'Sessions', 'opti-behavior' ), 'icon' => 'user' ),
                'optibehavior_visitors' => array( 'name' => __( 'Visitors', 'opti-behavior' ), 'icon' => 'users' ),
                'optibehavior_pageviews' => array( 'name' => __( 'Page Views', 'opti-behavior' ), 'icon' => 'eye' ),
                'optibehavior_recordings' => array( 'name' => __( 'Recordings', 'opti-behavior' ), 'icon' => 'video' ),
                'optibehavior_session_pages' => array( 'name' => __( 'Session Pages', 'opti-behavior' ), 'icon' => 'layers' ),
                'optibehavior_referrers' => array( 'name' => __( 'Referrers', 'opti-behavior' ), 'icon' => 'external-link' ),
                'optibehavior_outbound_clicks' => array( 'name' => __( 'Outbound Clicks', 'opti-behavior' ), 'icon' => 'arrow-up-right' ),
                'optibehavior_bot_visits' => array( 'name' => __( 'Bot Visits', 'opti-behavior' ), 'icon' => 'bot' ),
                'optibehavior_heatmap_pages' => array( 'name' => __( 'Heatmap Data', 'opti-behavior' ), 'icon' => 'flame' ),
                'optibehavior_daily_stats' => array( 'name' => __( 'Daily Stats', 'opti-behavior' ), 'icon' => 'bar-chart-2' ),
                'optibehavior_visitor_daily_stats' => array( 'name' => __( 'Visitor Daily Stats', 'opti-behavior' ), 'icon' => 'calendar-days' ),
                'optibehavior_insights' => array( 'name' => __( 'Smart Insights', 'opti-behavior' ), 'icon' => 'lightbulb' ),
                'optibehavior_journey_groups' => array( 'name' => __( 'Journey Groups', 'opti-behavior' ), 'icon' => 'route' ),
                'optibehavior_errors' => array( 'name' => __( 'JS Errors', 'opti-behavior' ), 'icon' => 'alert-circle' ),
                'optibehavior_error_types' => array( 'name' => __( 'Error Types', 'opti-behavior' ), 'icon' => 'alert-triangle' ),
                'optibehavior_friction' => array( 'name' => __( 'Friction Events', 'opti-behavior' ), 'icon' => 'zap-off' ),
                'optibehavior_performance' => array( 'name' => __( 'Performance', 'opti-behavior' ), 'icon' => 'gauge' ),
                'optibehavior_broken_links' => array( 'name' => __( 'Broken Links', 'opti-behavior' ), 'icon' => 'link-2-off' ),
                'optibehavior_report_schedules' => array( 'name' => __( 'Report Schedules', 'opti-behavior' ), 'icon' => 'calendar-clock' ),
                'optibehavior_report_logs' => array( 'name' => __( 'Report Logs', 'opti-behavior' ), 'icon' => 'scroll-text' ),
                'opti_behavior_funnels' => array( 'name' => __( 'Funnels', 'opti-behavior' ), 'icon' => 'filter' ),
                'opti_behavior_funnel_tracking' => array( 'name' => __( 'Funnel Tracking', 'opti-behavior' ), 'icon' => 'git-branch' ),
                'optibehavior_form_interactions' => array( 'name' => __( 'Form Interactions', 'opti-behavior' ), 'icon' => 'text-cursor-input' ),
                'optibehavior_form_submissions' => array( 'name' => __( 'Form Submissions', 'opti-behavior' ), 'icon' => 'file-check' ),
                'optibehavior_ab_decision_log' => array( 'name' => __( 'A/B Decision Log', 'opti-behavior' ), 'icon' => 'clipboard-list' ),
                'optibehavior_ab_tests' => array( 'name' => __( 'A/B Tests', 'opti-behavior' ), 'icon' => 'flask-conical' ),
                'optibehavior_ab_variants' => array( 'name' => __( 'A/B Variants', 'opti-behavior' ), 'icon' => 'git-fork' ),
                'optibehavior_ab_goals' => array( 'name' => __( 'A/B Goals', 'opti-behavior' ), 'icon' => 'target' ),
                'optibehavior_ab_impressions' => array( 'name' => __( 'A/B Impressions', 'opti-behavior' ), 'icon' => 'eye' ),
                'optibehavior_ab_conversions' => array( 'name' => __( 'A/B Conversions', 'opti-behavior' ), 'icon' => 'check-circle' ),
                'optibehavior_ab_daily_stats' => array( 'name' => __( 'A/B Daily Stats', 'opti-behavior' ), 'icon' => 'bar-chart-3' ),
                'optibehavior_ab_targeting_rules' => array( 'name' => __( 'A/B Targeting Rules', 'opti-behavior' ), 'icon' => 'crosshair' ),
                'optibehavior_ab_schedule' => array( 'name' => __( 'A/B Schedule', 'opti-behavior' ), 'icon' => 'calendar-clock' ),
            );
        }

        /**
         * Get sizes + estimated row counts for all plugin tables in one query.
         *
         * Uses a single information_schema.TABLES query instead of per-table
         * SHOW TABLES / SHOW TABLE STATUS / COUNT(*) round-trips, so it stays
         * milliseconds-fast regardless of data volume. Existence check is
         * implicit: missing tables are simply absent from the result.
         *
         * TABLE_ROWS is the InnoDB estimate — exact counts arrive later via
         * get_storage_tables_exact_counts() in batched AJAX requests.
         *
         * @since 1.8.x
         * @return array[] Per-table stats sorted by total_size DESC:
         *                 {suffix, table_name, friendly_name, icon,
         *                 rows_estimate, data_size, index_size, total_size}.
         */
        private function get_storage_tables_overview() {
            global $wpdb;

            $definitions     = $this->get_storage_table_definitions();
            $suffix_by_table = array();
            $table_names     = array();

            foreach ( $definitions as $suffix => $definition ) {
                $full_table_name                     = $wpdb->prefix . $suffix;
                $suffix_by_table[ $full_table_name ] = $suffix;
                $table_names[]                       = $full_table_name;
            }

            if ( empty( $table_names ) ) {
                return array();
            }

            $placeholders = implode( ', ', array_fill( 0, count( $table_names ), '%s' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list built from the fixed definitions map; values passed via prepare().
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT table_name AS tbl,
                        COALESCE( table_rows, 0 ) AS rows_estimate,
                        COALESCE( data_length, 0 ) AS data_length,
                        COALESCE( index_length, 0 ) AS index_length
                 FROM information_schema.TABLES
                 WHERE table_schema = %s AND table_name IN ( {$placeholders} )",
                array_merge( array( DB_NAME ), $table_names )
            ), ARRAY_A );

            $stats = array();

            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    if ( ! isset( $suffix_by_table[ $row['tbl'] ] ) ) {
                        continue;
                    }

                    $suffix     = $suffix_by_table[ $row['tbl'] ];
                    $definition = $definitions[ $suffix ];

                    $rows_estimate = (int) $row['rows_estimate'];
                    // When the table has 0 rows (estimate), report size as 0
                    // (InnoDB tablespace overhead is not user data) — same rule
                    // as the previous synchronous implementation.
                    $data_size  = $rows_estimate > 0 ? (int) $row['data_length'] : 0;
                    $index_size = $rows_estimate > 0 ? (int) $row['index_length'] : 0;

                    $stats[] = array(
                        'suffix'        => $suffix,
                        'table_name'    => $row['tbl'],
                        'friendly_name' => $definition['name'],
                        'icon'          => $definition['icon'],
                        'rows_estimate' => $rows_estimate,
                        'data_size'     => $data_size,
                        'index_size'    => $index_size,
                        'total_size'    => $data_size + $index_size,
                    );
                }
            }

            // Sort by total size descending.
            usort( $stats, function( $a, $b ) {
                return $b['total_size'] - $a['total_size'];
            } );

            return $stats;
        }

        /**
         * Get exact COUNT(*) row counts for a batch of plugin tables.
         *
         * Every suffix is validated against the get_storage_table_definitions()
         * allowlist (client input is never used as a table name) and the batch
         * is capped at 10 tables per call so a single request can never queue
         * up dozens of expensive full-index scans.
         *
         * @since 1.8.x
         * @param array $table_suffixes Table suffixes (without $wpdb->prefix).
         * @return array<string, int> Map of suffix => exact row count.
         */
        private function get_storage_tables_exact_counts( array $table_suffixes ) {
            global $wpdb;

            $definitions = $this->get_storage_table_definitions();

            $valid = array();
            foreach ( $table_suffixes as $suffix ) {
                if ( is_string( $suffix ) && isset( $definitions[ $suffix ] ) ) {
                    $valid[] = $suffix;
                }
            }

            $valid = array_slice( array_values( array_unique( $valid ) ), 0, 10 );

            $counts = array();
            foreach ( $valid as $suffix ) {
                $full_table_name = $wpdb->prefix . $suffix;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from the fixed allowlist + $wpdb->prefix.
                $counts[ $suffix ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table_name}`" );
            }

            return $counts;
        }

        /**
         * Get database table statistics for all plugin tables
         *
         * @return array Array of table statistics
         */
        private function get_database_table_stats() {
            global $wpdb;

            $table_definitions = $this->get_storage_table_definitions();

            $stats = array();

            foreach ( $table_definitions as $table_suffix => $definition ) {
                $full_table_name = $wpdb->prefix . $table_suffix;

                // Check if table exists first
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Checking table existence.
                $table_exists = $wpdb->get_var( $wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $full_table_name
                ) );

                if ( ! $table_exists ) {
                    continue;
                }

                // Get table status for size information
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SHOW TABLE STATUS requires literal table name.
                $table_status = $wpdb->get_row(
                    "SHOW TABLE STATUS LIKE '" . esc_sql( $full_table_name ) . "'"
                );

                if ( $table_status ) {
                    // Use exact COUNT(*) instead of InnoDB estimated row counts
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
                    $row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_table_name}`" );
                    // When table has 0 rows, report size as 0 (InnoDB overhead is not user data)
                    $data_size = $row_count > 0 ? (int) $table_status->Data_length : 0;
                    $index_size = $row_count > 0 ? (int) $table_status->Index_length : 0;

                    $stats[] = array(
                        'table_name' => $full_table_name,
                        'friendly_name' => $definition['name'],
                        'icon' => $definition['icon'],
                        'rows' => $row_count,
                        'data_size' => $data_size,
                        'index_size' => $index_size,
                        'total_size' => $data_size + $index_size,
                    );
                }
            }

            // Sort by total size descending
            usort( $stats, function( $a, $b ) {
                return $b['total_size'] - $a['total_size'];
            } );

            return $stats;
        }

        /**
         * Get file storage statistics
         *
         * @return array File storage statistics
         */
        private function get_file_storage_stats() {
            $upload_dir = wp_upload_dir();
            $storage_path = trailingslashit( $upload_dir['basedir'] ) . 'opti-behavior-data';

            $stats = array(
                'path' => str_replace( ABSPATH, '', $storage_path ),
                'total_size' => 0,
                'file_count' => 0,
                'folder_count' => 0,
                'exists' => false,
            );

            if ( is_dir( $storage_path ) ) {
                $stats['exists'] = true;
                $this->calculate_folder_size( $storage_path, $stats );
            }

            return $stats;
        }

        /**
         * Recursively calculate folder size and counts
         *
         * @param string $path Folder path
         * @param array  $stats Reference to stats array
         */
        private function calculate_folder_size( $path, &$stats ) {
            $files = scandir( $path );

            foreach ( $files as $file ) {
                if ( $file === '.' || $file === '..' ) {
                    continue;
                }

                $full_path = $path . DIRECTORY_SEPARATOR . $file;

                if ( is_dir( $full_path ) ) {
                    $stats['folder_count']++;
                    $this->calculate_folder_size( $full_path, $stats );
                } else {
                    $stats['file_count']++;
                    $stats['total_size'] += filesize( $full_path );
                }
            }
        }

        /**
         * Format bytes to human readable format
         *
         * @param int $bytes Number of bytes
         * @param int $precision Decimal precision
         * @return string Formatted size string
         */
        private function format_bytes( $bytes, $precision = 2 ) {
            $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

            $bytes = max( $bytes, 0 );
            $pow = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
            $pow = min( $pow, count( $units ) - 1 );

            $bytes /= pow( 1024, $pow );

            return round( $bytes, $precision ) . ' ' . $units[ $pow ];
        }

        /**
         * Render debug settings section
         */
        private function render_debug_settings_impl() {
            $tooltips = opti_behavior_get_settings_tooltips();
            $debug_manager = $this->heatmap->get_debug_manager();
            $debug_settings = $debug_manager->get_settings();
            $log_file_path = $debug_manager->get_log_file_path();
            $log_file_size = $debug_manager->get_log_file_size();

            // 3-hour auto-disable: lifetime + per-flag remaining time.
            $debug_lifetime       = $debug_manager->get_max_debug_lifetime();
            $debug_lifetime_hours = max( 1, (int) round( $debug_lifetime / HOUR_IN_SECONDS ) );
            $now                  = time();
            $php_debug_expires_at = ! empty( $debug_settings['php_debug_enabled'] ) && (int) $debug_settings['php_debug_enabled_at'] > 0
                ? (int) $debug_settings['php_debug_enabled_at'] + $debug_lifetime
                : 0;
            $js_debug_expires_at  = ! empty( $debug_settings['js_debug_enabled'] ) && (int) $debug_settings['js_debug_enabled_at'] > 0
                ? (int) $debug_settings['js_debug_enabled_at'] + $debug_lifetime
                : 0;
            ?>
            <div class="opti-behavior-settings-form">
                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon"><i data-lucide="bug"></i></span>
                            <?php esc_html_e( 'Debug & Logging Settings', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description">
                            <?php esc_html_e( 'Configure debug logging for PHP and JavaScript. Enable debugging to troubleshoot issues and monitor plugin behavior. Logs are stored securely and can be viewed or downloaded below.', 'opti-behavior' ); ?>
                        </p>
                        <div class="notice notice-info inline">
                            <p>
                                <?php
                                printf(
                                    /* translators: %d: number of hours before debug logging is automatically switched off. */
                                    esc_html( _n( 'Debug logging switches off automatically after %d hour to protect site performance.', 'Debug logging switches off automatically after %d hours to protect site performance.', $debug_lifetime_hours, 'opti-behavior' ) ),
                                    (int) $debug_lifetime_hours
                                );
                                ?>
                            </p>
                        </div>
                    </div>

                    <form method="post" class="debug-settings-form">
                        <?php wp_nonce_field('opti_behavior_debug_settings', 'opti_behavior_debug_nonce'); ?>

                    <div class="debug-subsections">
                        <!-- PHP Debug Settings -->
                        <div class="debug-subsection">
                            <h4 class="debug-subsection-title">
                                <span class="subsection-icon"><i data-lucide="wrench"></i></span> <?php esc_html_e( 'PHP Debug Settings', 'opti-behavior' ); ?>
                            </h4>

                            <div class="debug-field">
                                <label class="debug-checkbox-label">
                                    <input type="checkbox" name="php_debug_enabled" value="1" <?php checked($debug_settings['php_debug_enabled'], true); ?>>
                                    <span class="checkbox-text"><?php esc_html_e( 'Enable PHP Debug Logging', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['php_debug']['title'], $tooltips['php_debug']['content'], $tooltips['php_debug']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                </label>
                                <p class="debug-field-description">
                                    <?php esc_html_e( 'Log PHP errors, warnings, and debug messages to custom log file', 'opti-behavior' ); ?>
                                </p>
                                <?php if ( $php_debug_expires_at > $now ) : ?>
                                    <p class="debug-field-description debug-auto-disable-countdown">
                                        <?php
                                        printf(
                                            /* translators: %s: human-readable time remaining (e.g. "2 hours"). */
                                            esc_html__( 'Auto-disables in %s.', 'opti-behavior' ),
                                            esc_html( human_time_diff( $now, $php_debug_expires_at ) )
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div class="debug-field">
                                <label class="debug-label"><?php esc_html_e( 'Log Level', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['log_level']['title'], $tooltips['log_level']['content'], $tooltips['log_level']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                <select name="log_level" class="debug-select">
                                    <option value="error" <?php selected($debug_settings['log_level'], 'error'); ?>><?php esc_html_e( 'Error Only', 'opti-behavior' ); ?></option>
                                    <option value="warning" <?php selected($debug_settings['log_level'], 'warning'); ?>><?php esc_html_e( 'Warning & Error', 'opti-behavior' ); ?></option>
                                    <option value="info" <?php selected($debug_settings['log_level'], 'info'); ?>><?php esc_html_e( 'Info, Warning & Error', 'opti-behavior' ); ?></option>
                                    <option value="debug" <?php selected($debug_settings['log_level'], 'debug'); ?>><?php esc_html_e( 'Debug (All Messages)', 'opti-behavior' ); ?></option>
                                </select>
                                <p class="debug-field-description">
                                    <?php esc_html_e( 'Higher levels include more detailed logging', 'opti-behavior' ); ?>
                                </p>
                            </div>

                            <div class="debug-field">
                                <label class="debug-checkbox-label">
                                    <input type="checkbox" name="log_to_file" value="1" <?php checked($debug_settings['log_to_file'], true); ?>>
                                    <span class="checkbox-text"><?php esc_html_e( 'Log to File', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['log_to_file']['title'], $tooltips['log_to_file']['content'], $tooltips['log_to_file']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                </label>
                            </div>

                            <div class="debug-field">
                                <label class="debug-checkbox-label">
                                    <input type="checkbox" name="log_to_console" value="1" <?php checked($debug_settings['log_to_console'], true); ?>>
                                    <span class="checkbox-text"><?php esc_html_e( 'Log to Browser Console (AJAX)', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['log_to_console']['title'], $tooltips['log_to_console']['content'], $tooltips['log_to_console']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                </label>
                            </div>
                        </div>

                        <!-- JavaScript Debug Settings -->
                        <div class="debug-subsection">
                            <h4 class="debug-subsection-title">
                                <span class="subsection-icon"><i data-lucide="scroll-text"></i></span> <?php esc_html_e( 'JavaScript Debug Settings', 'opti-behavior' ); ?>
                            </h4>

                            <div class="debug-field">
                                <label class="debug-checkbox-label">
                                    <input type="checkbox" name="js_debug_enabled" value="1" <?php checked($debug_settings['js_debug_enabled'], true); ?>>
                                    <span class="checkbox-text"><?php esc_html_e( 'Enable JavaScript Debug Logging', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['js_debug']['title'], $tooltips['js_debug']['content'], $tooltips['js_debug']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                </label>
                                <p class="debug-field-description">
                                    <?php esc_html_e( 'Output debug messages to browser console with timestamps and formatting', 'opti-behavior' ); ?>
                                </p>
                                <?php if ( $js_debug_expires_at > $now ) : ?>
                                    <p class="debug-field-description debug-auto-disable-countdown">
                                        <?php
                                        printf(
                                            /* translators: %s: human-readable time remaining (e.g. "2 hours"). */
                                            esc_html__( 'Auto-disables in %s.', 'opti-behavior' ),
                                            esc_html( human_time_diff( $now, $js_debug_expires_at ) )
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Log File Settings -->
                        <div class="debug-subsection">
                            <h4 class="debug-subsection-title">
                                <span class="subsection-icon"><i data-lucide="folder"></i></span> <?php esc_html_e( 'Log File Settings', 'opti-behavior' ); ?>
                            </h4>

                            <div class="debug-field">
                                <label class="debug-label"><?php esc_html_e( 'Custom Log Path', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['custom_log_path']['title'], $tooltips['custom_log_path']['content'], $tooltips['custom_log_path']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                <?php $upload_dir = wp_upload_dir(); ?>
                                <input type="text" name="custom_log_path" value="<?php echo esc_attr($debug_settings['custom_log_path']); ?>" placeholder="<?php echo esc_attr($upload_dir['basedir']); ?>" class="debug-input debug-input-monospace">
                                <p class="debug-field-description">
                                    <?php esc_html_e( 'Leave empty to use default uploads directory. Must be an absolute path.', 'opti-behavior' ); ?>
                                </p>
                            </div>

                            <div class="debug-field">
                                <label class="debug-label"><?php esc_html_e( 'Log Folder Name', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['log_folder_name']['title'], $tooltips['log_folder_name']['content'], $tooltips['log_folder_name']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                <input type="text" name="log_folder" value="<?php echo esc_attr($debug_settings['log_folder']); ?>" placeholder="opti-behavior-logs" class="debug-input debug-input-medium">
                                <p class="debug-field-description">
                                    <?php esc_html_e( 'Folder name within the log path where logs will be stored', 'opti-behavior' ); ?>
                                </p>
                            </div>

                            <div class="debug-field">
                                <label class="debug-label"><?php esc_html_e( 'Max Log File Size (MB)', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['max_log_size']['title'], $tooltips['max_log_size']['content'], $tooltips['max_log_size']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                <input type="number" name="max_log_size" value="<?php echo esc_attr($debug_settings['max_log_size']); ?>" min="1" max="100" class="debug-input debug-input-small">
                                <p class="debug-field-description">
                                    <?php esc_html_e( 'Log file will be rotated when it exceeds this size', 'opti-behavior' ); ?>
                                </p>
                            </div>

                            <div class="debug-field">
                                <label class="debug-checkbox-label">
                                    <input type="checkbox" name="auto_cleanup" value="1" <?php checked($debug_settings['auto_cleanup'], true); ?>>
                                    <span class="checkbox-text"><?php esc_html_e( 'Auto-Cleanup Old Logs', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['auto_cleanup_logs']['title'], $tooltips['auto_cleanup_logs']['content'], $tooltips['auto_cleanup_logs']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                </label>
                            </div>

                            <div class="debug-field">
                                <label class="debug-label"><?php esc_html_e( 'Cleanup After (Days)', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['cleanup_after_days']['title'], $tooltips['cleanup_after_days']['content'], $tooltips['cleanup_after_days']['simple'], '', array( 'position' => 'right' ) ); ?></label>
                                <input type="number" name="cleanup_days" value="<?php echo esc_attr($debug_settings['cleanup_days']); ?>" min="1" max="365" class="debug-input debug-input-small">
                                <p class="debug-field-description">
                                    <?php esc_html_e( 'Automatically delete log files older than this many days', 'opti-behavior' ); ?>
                                </p>
                            </div>

                            <div class="debug-log-info">
                                <p class="debug-log-info-title"><?php esc_html_e( 'Current Log File:', 'opti-behavior' ); ?></p>
                                <p class="debug-log-info-path">
                                    <?php echo esc_html($log_file_path); ?>
                                </p>
                                <p class="debug-log-info-size">
                                    <strong><?php esc_html_e( 'Size:', 'opti-behavior' ); ?></strong> <?php echo esc_html($log_file_size); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="debug-actions">
                        <button type="submit" name="opti_behavior_debug_settings_submit" class="btn-save">
                            <span class="btn-icon"><i data-lucide="save"></i></span>
                            <?php esc_html_e( 'Save Debug Settings', 'opti-behavior' ); ?>
                        </button>
                    </div>
                </form>

                <!-- Log Management -->
                <div class="debug-log-management">
                    <h4 class="debug-log-management-title"><?php esc_html_e( 'Log Management', 'opti-behavior' ); ?></h4>

                    <div class="debug-log-actions">
                        <button type="button" id="opti-behavior-view-log-btn" class="btn-save">
                            <span class="btn-icon"><i data-lucide="eye"></i></span>
                            <?php esc_html_e( 'View Log', 'opti-behavior' ); ?>
                        </button>
                        <a href="<?php echo esc_url(add_query_arg('opti_behavior_download_log', '1', admin_url('admin.php?page=opti-behavior-settings'))); ?>" class="btn-save">
                            <span class="btn-icon"><i data-lucide="download"></i></span>
                            <?php esc_html_e( 'Download Log', 'opti-behavior' ); ?>
                        </a>
                        <form method="post" class="optibehavior-inline-form-block optibehavior-delete-form" data-confirm="<?php echo esc_attr__('Are you sure you want to clear the debug log?', 'opti-behavior'); ?>">
                            <?php wp_nonce_field('opti_behavior_clear_log', 'opti_behavior_clear_log_nonce'); ?>
                            <button type="submit" name="opti_behavior_clear_debug_log" class="btn-save">
                                <span class="btn-icon"><i data-lucide="trash-2"></i></span>
                                <?php esc_html_e( 'Clear Log', 'opti-behavior' ); ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Log Viewer Modal -->
                <div id="opti-behavior-log-viewer">
                    <div class="log-viewer-container">
                        <div class="log-viewer-header">
                            <h3><?php esc_html_e( 'Debug Log Viewer', 'opti-behavior' ); ?></h3>
                            <button class="button opti-behavior-log-close-btn"><?php esc_html_e( '✕ Close', 'opti-behavior' ); ?></button>
                        </div>
                        <div class="log-viewer-content">
                            <pre id="opti-behavior-log-content"></pre>
                        </div>
                    </div>
                </div>
                <!-- Note: Debug log viewer script is now properly enqueued via wp_add_inline_script() in render_settings_impl() method -->
                </div>
            </div>
            <?php
        }

        /**
         * Handle debug settings save
         */
        private function handle_debug_settings_save_impl() {
            $debug_manager = $this->heatmap->get_debug_manager();

            // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function (line 28)
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash not needed for sanitize_text_field
            $settings = array(
                'php_debug_enabled' => isset($_POST['php_debug_enabled']),
                'js_debug_enabled'  => isset($_POST['js_debug_enabled']),
                'log_to_file'       => isset($_POST['log_to_file']),
                'log_to_console'    => isset($_POST['log_to_console']),
                'log_level'         => sanitize_text_field($_POST['log_level'] ?? 'info'),
                'custom_log_path'   => sanitize_text_field($_POST['custom_log_path'] ?? ''),
                'log_folder'        => sanitize_text_field($_POST['log_folder'] ?? 'opti-behavior-logs'),
                'max_log_size'      => absint($_POST['max_log_size'] ?? 10),
                'auto_cleanup'      => isset($_POST['auto_cleanup']),
                'cleanup_days'      => absint($_POST['cleanup_days'] ?? 30),
            );
            // phpcs:enable WordPress.Security.NonceVerification.Missing
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

            $debug_manager->save_settings($settings);

            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Debug settings saved successfully!', 'opti-behavior' ) . '</p></div>';
            });
        }

        /**
         * Handle clear debug log
         */
        private function handle_clear_debug_log_impl() {
            $debug_manager = $this->heatmap->get_debug_manager();
            $debug_manager->clear_log();

            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Debug log cleared successfully!', 'opti-behavior' ) . '</p></div>';
            });
        }

        /**
         * Render file storage settings tab
         */
        private function render_file_storage_tab() {
            $tooltips = opti_behavior_get_settings_tooltips();
            $file_storage = $this->heatmap->get_file_storage();
            $archiver = $this->heatmap->get_data_archiver();

            if (!$file_storage) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'File storage system not available.', 'opti-behavior' ) . '</p></div>';
                return;
            }

            $settings = $file_storage->get_settings();
            $stats = $file_storage->get_storage_stats();
            $archiver_stats = $archiver->get_stats();
            ?>
            <form method="post" action="" class="opti-behavior-settings-form">
                <?php wp_nonce_field('opti_behavior_file_storage_settings', 'opti_behavior_file_storage_nonce'); ?>

                <!-- Storage Health Overview -->
                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon"><i data-lucide="database"></i></span>
                            <?php esc_html_e( 'Storage Health', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description"><?php esc_html_e( 'Current storage usage and system status', 'opti-behavior' ); ?></p>
                    </div>

                    <div class="storage-stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon"><i data-lucide="hard-drive"></i></div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo esc_html( size_format( $stats['total_size'] ) ); ?></div>
                                <div class="stat-label"><?php esc_html_e( 'Total Storage Used', 'opti-behavior' ); ?></div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon"><i data-lucide="files"></i></div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo number_format($stats['file_count']); ?></div>
                                <div class="stat-label"><?php esc_html_e( 'Total Files', 'opti-behavior' ); ?></div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon"><i data-lucide="video"></i></div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo number_format($stats['recordings_count']); ?></div>
                                <div class="stat-label"><?php esc_html_e( 'Recording Files', 'opti-behavior' ); ?></div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon"><i data-lucide="activity"></i></div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo number_format($stats['events_count']); ?></div>
                                <div class="stat-label"><?php esc_html_e( 'Event Files', 'opti-behavior' ); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="storage-stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon"><i data-lucide="database"></i></div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo number_format($archiver_stats['db_recordings']); ?></div>
                                <div class="stat-label"><?php esc_html_e( 'Database Recordings', 'opti-behavior' ); ?></div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon"><i data-lucide="folder-open"></i></div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo number_format($archiver_stats['file_recordings']); ?></div>
                                <div class="stat-label"><?php esc_html_e( 'File-Based Recordings', 'opti-behavior' ); ?></div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon"><i data-lucide="clock"></i></div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo esc_html( $archiver_stats['next_cleanup'] ? gmdate( 'M d, H:i', $archiver_stats['next_cleanup'] ) : __( 'Not scheduled', 'opti-behavior' ) ); ?></div>
                                <div class="stat-label"><?php esc_html_e( 'Next Cleanup', 'opti-behavior' ); ?></div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon"><i data-lucide="search"></i></div>
                            <div class="stat-content">
                                <div class="stat-value"><?php echo esc_html( $archiver_stats['next_storage_check'] ? gmdate( 'M d, H:i', $archiver_stats['next_storage_check'] ) : __( 'Not scheduled', 'opti-behavior' ) ); ?></div>
                                <div class="stat-label"><?php esc_html_e( 'Next Storage Check', 'opti-behavior' ); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Storage Mode -->
                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon"><i data-lucide="video"></i></span>
                            <?php esc_html_e( 'Storage Mode', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description"><?php esc_html_e( 'Choose how to store recordings and events data', 'opti-behavior' ); ?></p>
                    </div>

                    <div class="settings-grid">
                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Storage Type', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['storage_mode']['title'], $tooltips['storage_mode']['content'], $tooltips['storage_mode']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'File storage recommended for high-traffic sites (30K+ visits/day)', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="storage_mode" value="file" <?php checked($settings['storage_mode'], 'file'); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'File Storage', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Store data in organized files (Recommended)', 'opti-behavior' ); ?></span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="storage_mode" value="database" <?php checked($settings['storage_mode'], 'database'); ?>>
                                        <span class="radio-label"><?php esc_html_e( 'Database Storage', 'opti-behavior' ); ?></span>
                                        <span class="radio-description"><?php esc_html_e( 'Store data in MySQL database (Legacy)', 'opti-behavior' ); ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Compression', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['compression']['title'], $tooltips['compression']['content'], $tooltips['compression']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Enable gzip compression (saves 80-90% disk space)', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="toggle-switch">
                                    <input type="checkbox" name="compression" value="1" <?php checked($settings['compression'], true); ?> id="compression_toggle">
                                    <label for="compression_toggle" class="toggle-label">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Data Retention -->
                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon">🗓️</span>
                            <?php esc_html_e( 'Data Retention', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description"><?php esc_html_e( 'Configure how long to keep data before automatic deletion', 'opti-behavior' ); ?></p>
                    </div>

                    <div class="settings-grid">
                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Retention Period', 'opti-behavior' ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'File cleanup follows the master Data Retention setting (Danger Zone → Data Retention). Files are removed together with their database rows in the same daily cleanup.', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <p class="setting-static-value">
                                    <?php
                                    $master_retention_days = function_exists( 'opti_behavior_get_raw_retention_days' ) ? intval( opti_behavior_get_raw_retention_days() ) : 365;
                                    if ( $master_retention_days > 0 ) {
                                        printf(
                                            /* translators: %d: number of days from the master data retention setting */
                                            esc_html__( 'Currently: %d days', 'opti-behavior' ),
                                            $master_retention_days
                                        );
                                    } else {
                                        esc_html_e( 'Currently: keep forever', 'opti-behavior' );
                                    }
                                    ?>
                                </p>
                            </div>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Auto Cleanup', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['auto_cleanup']['title'], $tooltips['auto_cleanup']['content'], $tooltips['auto_cleanup']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Automatically delete old files daily', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="toggle-switch">
                                    <input type="checkbox" name="auto_cleanup" value="1" <?php checked($settings['auto_cleanup'], true); ?> id="auto_cleanup_toggle">
                                    <label for="auto_cleanup_toggle" class="toggle-label">
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Storage Limits -->
                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon">📏</span>
                            <?php esc_html_e( 'Storage Limits', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description"><?php esc_html_e( 'Prevent unlimited storage growth', 'opti-behavior' ); ?></p>
                    </div>

                    <div class="settings-grid">
                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Maximum Storage Size', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['max_storage_mb']['title'], $tooltips['max_storage_mb']['content'], $tooltips['max_storage_mb']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Delete oldest files when this limit is exceeded', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="input-with-suffix">
                                    <input type="number" name="max_storage_mb" value="<?php echo esc_attr($settings['max_storage_mb']); ?>" min="100" max="100000" step="100" class="number-input">
                                    <span class="input-suffix"><?php esc_html_e( 'MB', 'opti-behavior' ); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Manual Cleanup -->
                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon">🧹</span>
                            <?php esc_html_e( 'Manual Cleanup', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description"><?php esc_html_e( 'Manually clean up old recording files', 'opti-behavior' ); ?></p>
                    </div>

                    <div class="settings-grid">
                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Delete Recordings Before Date', 'opti-behavior' ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Remove all recordings created before this date', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <input type="date" name="cleanup_before_date" id="cleanup_before_date" class="date-input" max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
                                <button type="button" id="cleanup_by_date_btn" class="btn-secondary">
                                    <span class="btn-icon"><i data-lucide="trash-2"></i></span>
                                    <?php esc_html_e( 'Delete Old Recordings', 'opti-behavior' ); ?>
                                </button>
                            </div>
                        </div>

                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Delete Short Recordings', 'opti-behavior' ); ?></span>
                                <span class="label-description"><?php esc_html_e( 'Remove recordings shorter than specified duration', 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <div class="input-with-suffix">
                                    <input type="number" name="min_duration_seconds" id="min_duration_seconds" value="5" min="1" max="300" class="number-input">
                                    <span class="input-suffix"><?php esc_html_e( 'seconds', 'opti-behavior' ); ?></span>
                                </div>
                                <button type="button" id="cleanup_by_duration_btn" class="btn-secondary">
                                    <span class="btn-icon"><i data-lucide="trash-2"></i></span>
                                    <?php esc_html_e( 'Delete Short Recordings', 'opti-behavior' ); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div id="cleanup_result"></div>
                </div>

                <!-- Orphaned Files Cleanup -->
                <div class="settings-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            <span class="section-icon"><i data-lucide="trash-2"></i></span>
                            <?php esc_html_e( 'Orphaned Files Cleanup', 'opti-behavior' ); ?>
                        </h3>
                        <p class="section-description"><?php esc_html_e( 'Clean up recording files that have no database records', 'opti-behavior' ); ?></p>
                    </div>

                    <div class="settings-grid">
                        <div class="setting-item">
                            <label class="setting-label">
                                <span class="label-text"><?php esc_html_e( 'Delete Orphaned Files', 'opti-behavior' ); ?></span>
                                <span class="label-description"><?php esc_html_e( "Remove all recording files that don't have corresponding database entries", 'opti-behavior' ); ?></span>
                            </label>
                            <div class="setting-control">
                                <button type="button" id="cleanup_orphaned_files_btn" class="btn-secondary">
                                    <span class="btn-icon"><i data-lucide="trash-2"></i></span>
                                    <?php esc_html_e( 'Delete Orphaned Files', 'opti-behavior' ); ?>
                                </button>
                                <p class="description optibehavior-description-spacing">
                                    <strong><?php esc_html_e( 'Warning:', 'opti-behavior' ); ?></strong> <?php esc_html_e( "This will permanently delete all .json.gz files in the recordings directory that don't have matching database records.", 'opti-behavior' ); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div id="orphaned_cleanup_result"></div>
                </div>

                <!-- Save Button -->
                <div class="settings-actions">
                    <button type="submit" name="opti_behavior_file_storage_submit" class="btn-save">
                        <span class="btn-icon"><i data-lucide="video"></i></span>
                        <?php esc_html_e( 'Save Recording Settings', 'opti-behavior' ); ?>
                    </button>
                </div>
            </form>
            <!-- Note: Storage stats styles and cleanup scripts are now properly enqueued via wp_add_inline_style() and wp_add_inline_script() in render_settings_impl() method -->
            <?php
        }

        /**
         * Handle file storage settings save
         */
        private function handle_file_storage_settings_save_impl() {
            $file_storage = $this->heatmap->get_file_storage();

            if (!$file_storage) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'File storage system not available.', 'opti-behavior' ) . '</p></div>';
                });
                return;
            }

            // Retention no longer editable here — file cleanup follows the master
            // Data Retention setting (Unified Retention Protocol). Preserve the
            // stored value so legacy fallbacks keep a sane number.
            $current_settings = $file_storage->get_settings();

            // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in calling function (line 68)
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash not needed for sanitize_text_field
            $new_settings = array(
                'storage_mode'   => sanitize_text_field($_POST['storage_mode'] ?? 'file'),
                'compression'    => isset($_POST['compression']),
                'retention_days' => absint($current_settings['retention_days'] ?? 30),
                'max_storage_mb' => absint($_POST['max_storage_mb'] ?? 5000),
                'auto_cleanup'   => isset($_POST['auto_cleanup']),
            );
            // phpcs:enable WordPress.Security.NonceVerification.Missing
            // phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

            $file_storage->update_settings($new_settings);

            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'File storage settings saved successfully!', 'opti-behavior' ) . '</p></div>';
            });
        }

        /**
         * Render Pro upgrade message for locked features
         */
        private function render_pro_upgrade_message($feature_name) {
            ?>
            <div class="pro-upgrade-container">
                <div class="pro-upgrade-card">
                    <div class="pro-upgrade-icon">🔒</div>
                    <h2 class="pro-upgrade-title"><?php echo esc_html($feature_name); ?> - <?php esc_html_e( 'Pro Feature', 'opti-behavior' ); ?></h2>
                    <p class="pro-upgrade-description">
                        <?php esc_html_e( 'This feature is available in', 'opti-behavior' ); ?> <strong><?php esc_html_e( 'Opti-Behavior Pro', 'opti-behavior' ); ?></strong>. <?php esc_html_e( 'Upgrade to unlock advanced file storage capabilities including:', 'opti-behavior' ); ?>
                    </p>
                    <ul class="pro-upgrade-features">
                        <li>✅ <?php esc_html_e( 'File-based storage to prevent database bloat', 'opti-behavior' ); ?></li>
                        <li>✅ <?php esc_html_e( 'Automatic compression (saves 80-90% disk space)', 'opti-behavior' ); ?></li>
                        <li>✅ <?php esc_html_e( 'Hierarchical file organization by date/hour', 'opti-behavior' ); ?></li>
                        <li>✅ <?php esc_html_e( 'Manual cleanup tools (by date or duration)', 'opti-behavior' ); ?></li>
                        <li>✅ <?php esc_html_e( 'Storage health monitoring dashboard', 'opti-behavior' ); ?></li>
                        <li>✅ <?php esc_html_e( 'Automatic retention and cleanup policies', 'opti-behavior' ); ?></li>
                        <li>✅ <?php esc_html_e( 'Support for high-traffic sites (30K+ visits/day)', 'opti-behavior' ); ?></li>
                    </ul>
                    <div class="pro-upgrade-actions">
                        <a href="#" class="button button-primary button-large pro-upgrade-button">
                            🚀 <?php esc_html_e( 'Upgrade to Pro', 'opti-behavior' ); ?>
                        </a>
                        <a href="#" class="button button-secondary button-large">
                            <?php esc_html_e( 'Learn More', 'opti-behavior' ); ?>
                        </a>
                    </div>
                </div>
            </div>
            <!-- Note: Pro upgrade card styles are now properly enqueued via wp_add_inline_style() in render_settings_impl() method -->
            <?php
        }

        /**
         * Handle traffic classification settings save
         */
        private function handle_traffic_settings_save_impl() {
            // Verify nonce
            if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'opti_behavior_traffic_settings_submit' ) ) {
                wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) );
            }

            // Check user permissions
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
            }

            // Get and sanitize settings
            $traffic_settings = isset( $_POST['opti_behavior_traffic'] ) ? map_deep( wp_unslash( $_POST['opti_behavior_traffic'] ), 'sanitize_text_field' ) : array();

            // Merge over the existing option so keys owned by other forms on this
            // tab (e.g. track_admin_users from the Admin Tracking form) are
            // preserved instead of being wiped on every traffic settings save.
            $existing_settings = get_option( 'opti_behavior_traffic_settings', array() );
            if ( ! is_array( $existing_settings ) ) {
                $existing_settings = array();
            }

            $settings = array_merge(
                $existing_settings,
                array(
                    'bot_detection_enabled'        => isset( $traffic_settings['bot_detection_enabled'] ) ? true : false,
                    'automated_detection_enabled'  => isset( $traffic_settings['automated_detection_enabled'] ) ? true : false,
                    'spam_detection_enabled'       => isset( $traffic_settings['spam_detection_enabled'] ) ? true : false,
                    'spam_duration_threshold'      => isset( $traffic_settings['spam_duration_threshold'] ) ? intval( $traffic_settings['spam_duration_threshold'] ) : 3,
                    'spam_min_scrolls_threshold'   => isset( $traffic_settings['spam_min_scrolls_threshold'] ) ? intval( $traffic_settings['spam_min_scrolls_threshold'] ) : 0,
                    'spam_min_clicks_threshold'    => isset( $traffic_settings['spam_min_clicks_threshold'] ) ? intval( $traffic_settings['spam_min_clicks_threshold'] ) : 1,
                    'custom_bot_patterns'          => array(),
                )
            );

            // Validate thresholds
            if ( $settings['spam_duration_threshold'] < 0 ) {
                $settings['spam_duration_threshold'] = 0;
            }
            if ( $settings['spam_duration_threshold'] > 120 ) {
                $settings['spam_duration_threshold'] = 120;
            }
            if ( $settings['spam_min_scrolls_threshold'] < 0 ) {
                $settings['spam_min_scrolls_threshold'] = 0;
            }
            if ( $settings['spam_min_scrolls_threshold'] > 10 ) {
                $settings['spam_min_scrolls_threshold'] = 10;
            }
            if ( $settings['spam_min_clicks_threshold'] < 0 ) {
                $settings['spam_min_clicks_threshold'] = 0;
            }
            if ( $settings['spam_min_clicks_threshold'] > 10 ) {
                $settings['spam_min_clicks_threshold'] = 10;
            }

            // Parse custom bot patterns (already unslashed and sanitized above)
            if ( isset( $traffic_settings['custom_bot_patterns'] ) && ! empty( $traffic_settings['custom_bot_patterns'] ) ) {
                $patterns_text = $traffic_settings['custom_bot_patterns'];
                $patterns = explode( "\n", $patterns_text );
                foreach ( $patterns as $pattern ) {
                    $pattern = trim( $pattern );
                    if ( ! empty( $pattern ) ) {
                        $settings['custom_bot_patterns'][] = $pattern;
                    }
                }
            }

            // Parse excluded IPs (one rule per line: exact IP, CIDR, or IPv4 wildcard).
            // Read from $_POST directly with sanitize_textarea_field: the map_deep
            // sanitize_text_field pass above strips newlines, which would merge
            // all textarea lines into one unparseable string.
            $invalid_ip_rules = array();
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at the top of this method.
            if ( isset( $_POST['opti_behavior_traffic']['excluded_ips'] ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified at the top of this method.
                $excluded_ips_raw         = sanitize_textarea_field( wp_unslash( $_POST['opti_behavior_traffic']['excluded_ips'] ) );
                $parsed                   = Opti_Behavior_IP_Exclusion::parse_rules( $excluded_ips_raw );
                $settings['excluded_ips'] = $parsed['valid'];
                $invalid_ip_rules         = $parsed['invalid'];
            } else {
                $settings['excluded_ips'] = array();
            }

            // Save settings
            update_option( 'opti_behavior_traffic_settings', $settings );
            Opti_Behavior_IP_Exclusion::flush_cache();

            // Clear traffic classification cache
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache cleanup query.
            $wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_opti_behavior_traffic_class_%'" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache cleanup query.
            $wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_timeout_opti_behavior_traffic_class_%'" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache cleanup query.
            $wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_opti_recordings_by_page_%'" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache cleanup query.
            $wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_timeout_opti_recordings_by_page_%'" );

            // Note: Spam recalculation is now handled via AJAX with progress bar
            // The JavaScript will trigger batch recalculation after settings are saved
            // This provides better UX for large datasets and prevents timeouts

            // Show success message
            add_settings_error(
                'opti_behavior_settings',
                'settings_updated',
                __( 'Traffic classification settings saved successfully.', 'opti-behavior' ),
                'updated'
            );

            // Warn about dropped IP-exclusion rules (invalid format).
            if ( ! empty( $invalid_ip_rules ) ) {
                add_settings_error(
                    'opti_behavior_settings',
                    'invalid_excluded_ips',
                    sprintf(
                        /* translators: %s: comma-separated list of rejected IP rules. */
                        __( 'Some excluded IP entries were ignored because they are not valid IPs, CIDR ranges, or wildcards: %s', 'opti-behavior' ),
                        implode( ', ', array_map( 'esc_html', $invalid_ip_rules ) )
                    ),
                    'warning'
                );
            }
            set_transient( 'settings_errors', get_settings_errors(), 30 );
        }

        /**
         * Handle logged-in user tracking settings save
         *
         * @since 1.0.5
         */
        private function handle_logged_in_tracking_save_impl() {
            // Verify nonce
            if ( ! isset( $_POST['_wpnonce_logged_in'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_logged_in'] ) ), 'opti_behavior_logged_in_tracking_submit' ) ) {
                wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) );
            }

            // Check user permissions
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
            }

            // Get submitted data
            $logged_in_data = isset( $_POST['opti_behavior_logged_in'] ) ? map_deep( wp_unslash( $_POST['opti_behavior_logged_in'] ), 'sanitize_text_field' ) : array();

            $track_admin = isset( $logged_in_data['track_admin'] ) ? true : false;

            // Merge into existing traffic settings to preserve other fields
            $settings = get_option( 'opti_behavior_traffic_settings', array() );
            $settings['track_admin_users'] = $track_admin;
            // Clean up old keys
            unset( $settings['exclude_logged_in_users'], $settings['track_logged_in_users'], $settings['exclude_roles'] );

            update_option( 'opti_behavior_traffic_settings', $settings );

            add_settings_error(
                'opti_behavior_settings',
                'settings_updated',
                __( 'Admin tracking settings saved successfully.', 'opti-behavior' ),
                'updated'
            );
            set_transient( 'settings_errors', get_settings_errors(), 30 );
        }

        /**
         * Handle user intent rules save
         */
        private function handle_intent_rules_save_impl() {
            // Verify nonce
            if ( ! isset( $_POST['opti_behavior_intent_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_intent_nonce'] ) ), 'opti_behavior_intent_rules_submit' ) ) {
                wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) );
            }

            // Check user permissions
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
            }

            // Get and sanitize settings
            $intent_data = isset( $_POST['opti_behavior_intent'] ) ? map_deep( wp_unslash( $_POST['opti_behavior_intent'] ), 'sanitize_text_field' ) : array();

            $intent_rules = array(
                'low_intent' => array(
                    'time_spent' => isset( $intent_data['low_intent']['time_spent'] ) ? absint( $intent_data['low_intent']['time_spent'] ) : 10,
                    'clicks' => isset( $intent_data['low_intent']['clicks'] ) ? absint( $intent_data['low_intent']['clicks'] ) : 0,
                    'scroll_depth' => isset( $intent_data['low_intent']['scroll_depth'] ) ? absint( $intent_data['low_intent']['scroll_depth'] ) : 0,
                ),
                'medium_intent' => array(
                    'time_spent' => isset( $intent_data['medium_intent']['time_spent'] ) ? absint( $intent_data['medium_intent']['time_spent'] ) : 30,
                    'clicks' => isset( $intent_data['medium_intent']['clicks'] ) ? absint( $intent_data['medium_intent']['clicks'] ) : 1,
                    'scroll_depth' => isset( $intent_data['medium_intent']['scroll_depth'] ) ? absint( $intent_data['medium_intent']['scroll_depth'] ) : 15,
                ),
                'high_intent' => array(
                    'time_spent' => isset( $intent_data['high_intent']['time_spent'] ) ? absint( $intent_data['high_intent']['time_spent'] ) : 45,
                    'clicks' => isset( $intent_data['high_intent']['clicks'] ) ? absint( $intent_data['high_intent']['clicks'] ) : 2,
                    'scroll_depth' => isset( $intent_data['high_intent']['scroll_depth'] ) ? absint( $intent_data['high_intent']['scroll_depth'] ) : 50,
                ),
            );

            // Validate ranges
            foreach ( $intent_rules as $level => $rules ) {
                // Validate time_spent (0-3600 seconds)
                if ( $rules['time_spent'] < 0 ) {
                    $intent_rules[ $level ]['time_spent'] = 0;
                }
                if ( $rules['time_spent'] > 3600 ) {
                    $intent_rules[ $level ]['time_spent'] = 3600;
                }

                // Validate clicks (0-100)
                if ( $rules['clicks'] < 0 ) {
                    $intent_rules[ $level ]['clicks'] = 0;
                }
                if ( $rules['clicks'] > 100 ) {
                    $intent_rules[ $level ]['clicks'] = 100;
                }

                // Validate scroll_depth (0-100%)
                if ( $rules['scroll_depth'] < 0 ) {
                    $intent_rules[ $level ]['scroll_depth'] = 0;
                }
                if ( $rules['scroll_depth'] > 100 ) {
                    $intent_rules[ $level ]['scroll_depth'] = 100;
                }
            }

            // Save settings
            update_option( 'opti_behavior_intent_rules', $intent_rules );

            // Show success message
            add_settings_error(
                'opti_behavior_settings',
                'settings_updated',
                __( 'User intent rules saved successfully.', 'opti-behavior' ),
                'updated'
            );
            set_transient( 'settings_errors', get_settings_errors(), 30 );
        }

        /**
         * Render Traffic & Behavior tab (formerly Dashboard Settings)
         */
        private function render_dashboard_settings_tab() {
            ?>
            <div class="opti-behavior-settings-form opti-behavior-traffic-settings-form">
                <div class="settings-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <span class="section-icon"><i data-lucide="bar-chart-3"></i></span>
                            <?php esc_html_e( 'Traffic & Behavior', 'opti-behavior' ); ?>
                        </h2>
                        <p class="section-description">
                            <?php esc_html_e( 'Configure traffic classification rules and user intent detection for accurate behavioral analytics.', 'opti-behavior' ); ?>
                        </p>
                    </div>

                    <!-- Category 1: Traffic Classification -->
                    <div class="settings-category">
                        <h3 class="category-title">
                            <span class="category-icon"><i data-lucide="traffic-cone"></i></span>
                            <?php esc_html_e( 'Traffic Classification', 'opti-behavior' ); ?>
                        </h3>
                        <?php $this->render_traffic_classification_category(); ?>
                    </div>

                    <!-- Category 2: Admin Tracking -->
                    <div class="settings-category">
                        <h3 class="category-title">
                            <span class="category-icon"><i data-lucide="user-x"></i></span>
                            <?php esc_html_e( 'Admin Tracking', 'opti-behavior' ); ?>
                        </h3>
                        <?php $this->render_logged_in_tracking_category(); ?>
                    </div>

                    <!-- Category 3: User Intent Rules -->
                    <div class="settings-category">
                        <h3 class="category-title">
                            <span class="category-icon"><i data-lucide="target"></i></span>
                            <?php esc_html_e( 'User Intent Rules', 'opti-behavior' ); ?>
                        </h3>
                        <?php $this->render_user_intent_rules_category(); ?>
                    </div>
                </div>
            </div>
            <?php
        }

        /**
         * Render Traffic Classification category (moved from standalone tab)
         */
        private function render_traffic_classification_category() {
            $tooltips = opti_behavior_get_settings_tooltips();
            $settings = get_option( 'opti_behavior_traffic_settings', array(
                'bot_detection_enabled'        => true,
                'automated_detection_enabled'  => true,
                'spam_detection_enabled'       => true,
                'spam_duration_threshold'      => 3,
                'spam_min_scrolls_threshold'   => 0,
                'spam_min_clicks_threshold'    => 1,
                'custom_bot_patterns'          => array(),
            ) );

            $bot_enabled = isset( $settings['bot_detection_enabled'] ) ? (bool) $settings['bot_detection_enabled'] : true;
            $automated_enabled = isset( $settings['automated_detection_enabled'] ) ? (bool) $settings['automated_detection_enabled'] : true;
            $spam_enabled = isset( $settings['spam_detection_enabled'] ) ? (bool) $settings['spam_detection_enabled'] : true;
            $spam_status_class = $spam_enabled ? 'is-enabled' : 'is-disabled';
            $spam_status_text = $spam_enabled ? __( 'Enabled', 'opti-behavior' ) : __( 'Disabled', 'opti-behavior' );
            $spam_duration = isset( $settings['spam_duration_threshold'] ) ? intval( $settings['spam_duration_threshold'] ) : 3;
            $spam_min_scrolls = isset( $settings['spam_min_scrolls_threshold'] ) ? intval( $settings['spam_min_scrolls_threshold'] ) : 0;
            $spam_min_clicks = isset( $settings['spam_min_clicks_threshold'] ) ? intval( $settings['spam_min_clicks_threshold'] ) : 1;
            $custom_patterns = isset( $settings['custom_bot_patterns'] ) && is_array( $settings['custom_bot_patterns'] ) ? $settings['custom_bot_patterns'] : array();
            $custom_patterns_text = implode( "\n", $custom_patterns );
            $excluded_ips = isset( $settings['excluded_ips'] ) && is_array( $settings['excluded_ips'] ) ? $settings['excluded_ips'] : array();
            $excluded_ips_text = implode( "\n", $excluded_ips );
            $current_visitor_ip = Opti_Behavior_IP_Exclusion::get_client_ip();
            ?>
            <form method="post" action="" class="opti-behavior-category-form">
                <?php wp_nonce_field( 'opti_behavior_traffic_settings_submit', '_wpnonce' ); ?>

                <p class="category-description"><?php esc_html_e( 'Configure how Opti-Behavior classifies different types of traffic.', 'opti-behavior' ); ?></p>

                <!-- Bot Detection Section -->
                <h4 style="margin: 20px 0 10px 0; padding: 10px 0; border-bottom: 1px solid #ccc; color: #1d2327;">
                    <span class="dashicons dashicons-desktop" style="margin-right: 8px;"></span>
                    <?php esc_html_e( 'Bot Traffic', 'opti-behavior' ); ?>
                </h4>
                <table class="form-table" style="margin-top: 0;">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Bot Detection', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['bot_detection']['title'], $tooltips['bot_detection']['content'], $tooltips['bot_detection']['simple'], '', array( 'position' => 'right' ) ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="opti_behavior_traffic[bot_detection_enabled]" value="1" <?php checked( $bot_enabled ); ?> />
                            <?php esc_html_e( 'Enable bot traffic detection', 'opti-behavior' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Detect and classify legitimate bots (search engines, social crawlers, SEO tools).', 'opti-behavior' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Custom Bot Patterns', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['custom_bot_patterns']['title'], $tooltips['custom_bot_patterns']['content'], $tooltips['custom_bot_patterns']['simple'], $tooltips['custom_bot_patterns']['example'], array( 'position' => 'right' ) ); ?></th>
                    <td>
                        <textarea name="opti_behavior_traffic[custom_bot_patterns]" rows="5" cols="50" class="large-text code"><?php echo esc_textarea( $custom_patterns_text ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Enter custom user agent patterns to detect as bots (one per line). Example: MyCustomBot', 'opti-behavior' ); ?></p>
                    </td>
                </tr>
                </table>

                <!-- Automated Traffic Section -->
                <h4 style="margin: 30px 0 10px 0; padding: 10px 0; border-bottom: 1px solid #ccc; color: #1d2327;">
                    <span class="dashicons dashicons-admin-generic" style="margin-right: 8px;"></span>
                    <?php esc_html_e( 'Automated Traffic', 'opti-behavior' ); ?>
                </h4>
                <table class="form-table" style="margin-top: 0;">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Automated Traffic Detection', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['automated_traffic']['title'], $tooltips['automated_traffic']['content'], $tooltips['automated_traffic']['simple'], '', array( 'position' => 'right' ) ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="opti_behavior_traffic[automated_detection_enabled]" value="1" <?php checked( $automated_enabled ); ?> />
                            <?php esc_html_e( 'Enable automated traffic detection', 'opti-behavior' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Detect headless browsers, automation tools, and scripts (Selenium, Puppeteer, curl, etc.).', 'opti-behavior' ); ?></p>
                    </td>
                </tr>
                </table>

                <!-- Spam Traffic Section -->
                <section class="ob-spam-traffic-card <?php echo esc_attr( $spam_status_class ); ?>" aria-labelledby="ob-spam-traffic-title">
                    <div class="ob-spam-traffic-card__header">
                        <div class="ob-spam-traffic-card__identity">
                            <span class="ob-spam-traffic-card__icon" aria-hidden="true">
                                <span class="dashicons dashicons-shield-alt"></span>
                            </span>
                            <div>
                                <h4 id="ob-spam-traffic-title"><?php esc_html_e( 'Spam Traffic', 'opti-behavior' ); ?></h4>
                                <p><?php esc_html_e( 'Filters suspicious non-human or low-engagement sessions before they pollute your reports.', 'opti-behavior' ); ?></p>
                            </div>
                        </div>
                        <span class="ob-spam-traffic-card__status"><?php echo esc_html( $spam_status_text ); ?></span>
                    </div>

                    <div class="ob-spam-traffic-card__toggle-row">
                        <label class="ob-spam-traffic-card__toggle-label">
                            <input type="checkbox" name="opti_behavior_traffic[spam_detection_enabled]" value="1" <?php checked( $spam_enabled ); ?> />
                            <span class="ob-spam-traffic-card__toggle-ui" aria-hidden="true"></span>
                            <span class="ob-spam-traffic-card__toggle-copy">
                                <strong><?php esc_html_e( 'Enable spam traffic detection', 'opti-behavior' ); ?></strong>
                                <span><?php esc_html_e( 'Classify brief, inactive, or suspicious sessions so analytics stay focused on real visitors.', 'opti-behavior' ); ?></span>
                            </span>
                        </label>
                        <div class="ob-spam-traffic-card__tooltip">
                            <?php opti_behavior_tooltip_e( $tooltips['spam_detection_setting']['title'], $tooltips['spam_detection_setting']['content'], $tooltips['spam_detection_setting']['simple'], '', array( 'position' => 'left' ) ); ?>
                        </div>
                    </div>

                    <div class="ob-spam-traffic-card__thresholds" aria-label="<?php esc_attr_e( 'Spam traffic thresholds', 'opti-behavior' ); ?>">
                        <div class="ob-spam-threshold-card">
                            <div class="ob-spam-threshold-card__label-row">
                                <label for="ob-spam-duration-threshold"><?php esc_html_e( 'Duration threshold', 'opti-behavior' ); ?></label>
                                <?php opti_behavior_tooltip_e( $tooltips['spam_duration']['title'], $tooltips['spam_duration']['content'], $tooltips['spam_duration']['simple'], $tooltips['spam_duration']['example'], array( 'position' => 'right' ) ); ?>
                            </div>
                            <p><?php esc_html_e( 'Sessions shorter than this may be spam.', 'opti-behavior' ); ?></p>
                            <div class="ob-spam-threshold-card__control">
                                <input id="ob-spam-duration-threshold" type="number" name="opti_behavior_traffic[spam_duration_threshold]" value="<?php echo esc_attr( $spam_duration ); ?>" min="0" max="120" step="1" class="small-text" />
                                <span><?php esc_html_e( 'seconds', 'opti-behavior' ); ?></span>
                            </div>
                        </div>

                        <div class="ob-spam-threshold-card">
                            <div class="ob-spam-threshold-card__label-row">
                                <label for="ob-spam-min-scrolls-threshold"><?php esc_html_e( 'Minimum scrolls', 'opti-behavior' ); ?></label>
                                <?php opti_behavior_tooltip_e( $tooltips['spam_min_scrolls']['title'], $tooltips['spam_min_scrolls']['content'], $tooltips['spam_min_scrolls']['simple'], '', array( 'position' => 'right' ) ); ?>
                            </div>
                            <p><?php esc_html_e( 'Require real page movement before a visit counts as legitimate.', 'opti-behavior' ); ?></p>
                            <div class="ob-spam-threshold-card__control">
                                <input id="ob-spam-min-scrolls-threshold" type="number" name="opti_behavior_traffic[spam_min_scrolls_threshold]" value="<?php echo esc_attr( $spam_min_scrolls ); ?>" min="0" max="10" step="1" class="small-text" />
                                <span><?php esc_html_e( 'scrolls', 'opti-behavior' ); ?></span>
                            </div>
                        </div>

                        <div class="ob-spam-threshold-card">
                            <div class="ob-spam-threshold-card__label-row">
                                <label for="ob-spam-min-clicks-threshold"><?php esc_html_e( 'Minimum clicks', 'opti-behavior' ); ?></label>
                                <?php opti_behavior_tooltip_e( $tooltips['spam_min_clicks']['title'], $tooltips['spam_min_clicks']['content'], $tooltips['spam_min_clicks']['simple'], '', array( 'position' => 'right' ) ); ?>
                            </div>
                            <p><?php esc_html_e( 'Require meaningful interaction signals from buttons, links, or page elements.', 'opti-behavior' ); ?></p>
                            <div class="ob-spam-threshold-card__control">
                                <input id="ob-spam-min-clicks-threshold" type="number" name="opti_behavior_traffic[spam_min_clicks_threshold]" value="<?php echo esc_attr( $spam_min_clicks ); ?>" min="0" max="10" step="1" class="small-text" />
                                <span><?php esc_html_e( 'clicks', 'opti-behavior' ); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="ob-spam-traffic-card__note">
                        <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                        <p><?php esc_html_e( 'Spam traffic filtering protects browser, device, source, and session analytics from crawler noise, search engine probes, and LLM/AI fetchers that can create browser-like but non-human traffic signals.', 'opti-behavior' ); ?></p>
                    </div>
                </section>

                <!-- Excluded IPs Section -->
                <h4 style="margin: 20px 0 10px 0; padding: 10px 0; border-bottom: 1px solid #ccc; color: #1d2327;">
                    <span class="dashicons dashicons-hidden" style="margin-right: 8px;"></span>
                    <?php esc_html_e( 'Excluded IP Addresses', 'opti-behavior' ); ?>
                </h4>
                <table class="form-table" style="margin-top: 0;">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Block Tracking for IPs', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['excluded_ips']['title'], $tooltips['excluded_ips']['content'], $tooltips['excluded_ips']['simple'], $tooltips['excluded_ips']['example'], array( 'position' => 'right' ) ); ?></th>
                    <td>
                        <textarea name="opti_behavior_traffic[excluded_ips]" rows="5" class="large-text code" placeholder="<?php echo esc_attr( "192.168.1.10\n203.0.113.0/24\n10.0.1.*\n2001:db8::1" ); ?>"><?php echo esc_textarea( $excluded_ips_text ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'One entry per line. Visitors from these IPs are completely ignored: no heatmap, behavior, A/B, funnel, session recording, form analytics or error tracking scripts are loaded for them. Useful to exclude your own or your team\'s traffic from analytics.', 'opti-behavior' ); ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e( 'Supported formats: exact IPv4/IPv6 (192.168.1.10, 2001:db8::1), CIDR ranges (203.0.113.0/24, 2001:db8::/32) and IPv4 wildcards (10.0.1.*).', 'opti-behavior' ); ?>
                        </p>
                        <?php if ( '' !== $current_visitor_ip ) : ?>
                        <p class="description">
                            <strong><?php esc_html_e( 'Your current IP:', 'opti-behavior' ); ?></strong>
                            <code><?php echo esc_html( $current_visitor_ip ); ?></code>
                            <?php if ( Opti_Behavior_IP_Exclusion::is_excluded( $current_visitor_ip ) ) : ?>
                                <span style="color: #d63638; font-weight: 600;"><?php esc_html_e( '(currently excluded from tracking)', 'opti-behavior' ); ?></span>
                            <?php endif; ?>
                        </p>
                        <?php endif; ?>
                    </td>
                </tr>
                </table>

            <button type="submit" name="opti_behavior_traffic_settings_submit" class="btn-save">
                <span class="btn-icon"><i data-lucide="save"></i></span>
                <?php esc_html_e( 'Save Traffic Settings', 'opti-behavior' ); ?>
            </button>
            </form>

            <!-- Spam Recalculation Progress Bar -->
            <div id="spam-recalc-container" style="display: none; margin-top: 20px; max-width: 600px;">
                <div style="background: #f0f0f1; border-radius: 4px; padding: 16px; border: 1px solid #c3c4c7;">
                    <div style="display: flex; align-items: center; margin-bottom: 12px;">
                        <span class="dashicons dashicons-update spin" style="margin-right: 8px; color: #2271b1;"></span>
                        <strong id="spam-recalc-title"><?php esc_html_e( 'Recalculating spam flags...', 'opti-behavior' ); ?></strong>
                    </div>
                    <div style="background: #fff; border-radius: 3px; height: 24px; overflow: hidden; border: 1px solid #c3c4c7;">
                        <div id="spam-recalc-progress-bar" style="background: linear-gradient(90deg, #2271b1, #135e96); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center;">
                            <span id="spam-recalc-percent" style="color: #fff; font-weight: 600; font-size: 12px;">0%</span>
                        </div>
                    </div>
                    <p id="spam-recalc-status" style="margin: 8px 0 0; color: #646970; font-size: 13px;">
                        <?php esc_html_e( 'Processing sessions...', 'opti-behavior' ); ?>
                    </p>
                </div>
            </div>

            <!-- Success Message Container -->
            <div id="spam-recalc-success" style="display: none; margin-top: 20px; max-width: 600px;">
                <div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; padding: 12px 16px; color: #155724;">
                    <span class="dashicons dashicons-yes-alt" style="margin-right: 8px;"></span>
                    <span id="spam-recalc-success-msg"><?php esc_html_e( 'Spam recalculation completed successfully!', 'opti-behavior' ); ?></span>
                </div>
            </div>

            <style>
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                .dashicons.spin {
                    animation: spin 1s linear infinite;
                }
            </style>
            <?php
        }

        /**
         * Render Admin Tracking category
         *
         * @since 1.0.5
         */
        private function render_logged_in_tracking_category() {
            $tooltips = opti_behavior_get_settings_tooltips();
            $settings = get_option( 'opti_behavior_traffic_settings', array() );

            // track_admin_users: true by default (admins are tracked)
            $track_admin = isset( $settings['track_admin_users'] ) ? (bool) $settings['track_admin_users'] : true;
            ?>
            <form method="post" action="" class="opti-behavior-category-form">
                <?php wp_nonce_field( 'opti_behavior_logged_in_tracking_submit', '_wpnonce_logged_in' ); ?>

                <p class="category-description"><?php esc_html_e( 'Control whether admin users are tracked. By default, admin visits are included in analytics data.', 'opti-behavior' ); ?></p>

                <table class="form-table" style="margin-top: 0;">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Track Admin Users', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['track_admin']['title'], $tooltips['track_admin']['content'], $tooltips['track_admin']['simple'], '', array( 'position' => 'right' ) ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="opti_behavior_logged_in[track_admin]" value="1" <?php checked( $track_admin ); ?> />
                            <?php esc_html_e( 'Track admin users', 'opti-behavior' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'When checked, admin users are tracked by heatmaps, session recordings, error tracking, and form analytics. Uncheck to exclude admin visits from your analytics.', 'opti-behavior' ); ?></p>
                    </td>
                </tr>
                </table>

                <button type="submit" name="opti_behavior_logged_in_tracking_submit" class="btn-save">
                    <span class="btn-icon"><i data-lucide="save"></i></span>
                    <?php esc_html_e( 'Save Tracking Settings', 'opti-behavior' ); ?>
                </button>
            </form>
            <?php
        }

        /**
         * Render User Intent Rules category
         */
        private function render_user_intent_rules_category() {
            $tooltips = opti_behavior_get_settings_tooltips();
            $intent_rules = get_option( 'opti_behavior_intent_rules', array(
                'low_intent' => array(
                    'time_spent' => 10,
                    'clicks' => 0,
                    'scroll_depth' => 0,
                ),
                'medium_intent' => array(
                    'time_spent' => 30,
                    'clicks' => 1,
                    'scroll_depth' => 15,
                ),
                'high_intent' => array(
                    'time_spent' => 45,
                    'clicks' => 2,
                    'scroll_depth' => 50,
                ),
            ) );

            $low_intent = isset( $intent_rules['low_intent'] ) ? $intent_rules['low_intent'] : array();
            $medium_intent = isset( $intent_rules['medium_intent'] ) ? $intent_rules['medium_intent'] : array();
            $high_intent = isset( $intent_rules['high_intent'] ) ? $intent_rules['high_intent'] : array();
            ?>
            <form method="post" action="" class="opti-behavior-category-form">
                <?php wp_nonce_field( 'opti_behavior_intent_rules_submit', 'opti_behavior_intent_nonce' ); ?>

                <style>
                    .intent-rules-card {
                        background: #fff;
                        border: 1px solid #e2e4e7;
                        border-radius: 8px;
                        padding: 24px;
                        max-width: 800px;
                        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
                    }
                    .intent-rules-header {
                        display: flex;
                        align-items: center;
                        margin-bottom: 8px;
                    }
                    .intent-rules-header .dashicons {
                        font-size: 24px;
                        width: 24px;
                        height: 24px;
                        margin-right: 12px;
                        color: #2271b1;
                    }
                    .intent-rules-header h3 {
                        margin: 0;
                        font-size: 16px;
                        font-weight: 600;
                        color: #1d2327;
                    }
                    .intent-rules-description {
                        color: #646970;
                        font-size: 13px;
                        margin: 0 0 20px 36px;
                        line-height: 1.5;
                    }
                    .intent-rules-table {
                        width: 100%;
                        border-collapse: separate;
                        border-spacing: 0;
                        border: 1px solid #e2e4e7;
                        border-radius: 6px;
                        overflow: hidden;
                    }
                    .intent-rules-table thead th {
                        background: #f8f9fa;
                        padding: 14px 16px;
                        font-weight: 600;
                        font-size: 13px;
                        color: #1d2327;
                        border-bottom: 1px solid #e2e4e7;
                        text-align: center;
                    }
                    .intent-rules-table thead th:first-child {
                        text-align: left;
                        width: 220px;
                    }
                    .intent-rules-table tbody td {
                        padding: 16px;
                        border-bottom: 1px solid #f0f0f1;
                        vertical-align: middle;
                    }
                    .intent-rules-table tbody tr:last-child td {
                        border-bottom: none;
                    }
                    .intent-rules-table tbody tr:hover {
                        background: #f8f9fa;
                    }
                    .intent-badge {
                        display: inline-flex;
                        align-items: center;
                        padding: 4px 10px;
                        border-radius: 12px;
                        font-size: 12px;
                        font-weight: 500;
                    }
                    .intent-badge.low {
                        background: #fce4e4;
                        color: #9b2c2c;
                    }
                    .intent-badge.medium {
                        background: #fef3cd;
                        color: #856404;
                    }
                    .intent-badge.high {
                        background: #d4edda;
                        color: #155724;
                    }
                    .intent-badge .dashicons {
                        font-size: 14px;
                        width: 14px;
                        height: 14px;
                        margin-right: 4px;
                    }
                    .metric-cell {
                        display: flex;
                        align-items: center;
                    }
                    .metric-cell .dashicons {
                        font-size: 18px;
                        width: 18px;
                        height: 18px;
                        margin-right: 10px;
                        color: #646970;
                    }
                    .metric-info {
                        display: flex;
                        flex-direction: column;
                    }
                    .metric-name {
                        font-weight: 500;
                        color: #1d2327;
                        font-size: 13px;
                    }
                    .metric-unit {
                        font-size: 11px;
                        color: #898f96;
                        margin-top: 2px;
                    }
                    .intent-input-wrapper {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    .intent-input-wrapper input[type="number"] {
                        width: 72px;
                        padding: 8px 10px;
                        border: 1px solid #dcdcde;
                        border-radius: 4px;
                        text-align: center;
                        font-size: 14px;
                        font-weight: 500;
                        transition: border-color 0.2s, box-shadow 0.2s;
                    }
                    .intent-input-wrapper input[type="number"]:focus {
                        border-color: #2271b1;
                        box-shadow: 0 0 0 1px #2271b1;
                        outline: none;
                    }
                    .intent-input-wrapper .input-suffix {
                        margin-left: 6px;
                        font-size: 12px;
                        color: #898f96;
                    }
                    .intent-rules-footer {
                        margin-top: 16px;
                        padding: 12px 16px;
                        background: #f0f6fc;
                        border-radius: 6px;
                        display: flex;
                        align-items: flex-start;
                    }
                    .intent-rules-footer .dashicons {
                        font-size: 16px;
                        width: 16px;
                        height: 16px;
                        margin-right: 10px;
                        color: #2271b1;
                        flex-shrink: 0;
                        margin-top: 2px;
                    }
                    .intent-rules-footer p {
                        margin: 0;
                        font-size: 12px;
                        color: #50575e;
                        line-height: 1.5;
                    }
                </style>

                <div class="intent-rules-card">
                    <div class="intent-rules-header">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <h3><?php esc_html_e( 'Engagement Thresholds', 'opti-behavior' ); ?></h3>
                    </div>
                    <p class="intent-rules-description">
                        <?php esc_html_e( 'Configure the minimum thresholds for each engagement level. Visitors are classified based on meeting at least 2 of the 3 criteria below.', 'opti-behavior' ); ?>
                    </p>

                    <table class="intent-rules-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Metric', 'opti-behavior' ); ?></th>
                                <th>
                                    <span class="intent-badge low">
                                        <span class="dashicons dashicons-arrow-down-alt"></span>
                                        <?php esc_html_e( 'Low', 'opti-behavior' ); ?>
                                    </span>
                                    <?php opti_behavior_tooltip_e( $tooltips['low_intent']['title'], $tooltips['low_intent']['content'], $tooltips['low_intent']['simple'], '', array( 'position' => 'bottom' ) ); ?>
                                </th>
                                <th>
                                    <span class="intent-badge medium">
                                        <span class="dashicons dashicons-minus"></span>
                                        <?php esc_html_e( 'Medium', 'opti-behavior' ); ?>
                                    </span>
                                    <?php opti_behavior_tooltip_e( $tooltips['medium_intent']['title'], $tooltips['medium_intent']['content'], $tooltips['medium_intent']['simple'], '', array( 'position' => 'bottom' ) ); ?>
                                </th>
                                <th>
                                    <span class="intent-badge high">
                                        <span class="dashicons dashicons-arrow-up-alt"></span>
                                        <?php esc_html_e( 'High', 'opti-behavior' ); ?>
                                    </span>
                                    <?php opti_behavior_tooltip_e( $tooltips['high_intent']['title'], $tooltips['high_intent']['content'], $tooltips['high_intent']['simple'], '', array( 'position' => 'bottom' ) ); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div class="metric-cell">
                                        <span class="dashicons dashicons-clock"></span>
                                        <div class="metric-info">
                                            <span class="metric-name"><?php esc_html_e( 'Time on Page', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['time_spent']['title'], $tooltips['time_spent']['content'], $tooltips['time_spent']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                            <span class="metric-unit"><?php esc_html_e( 'Duration in seconds', 'opti-behavior' ); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="intent-input-wrapper">
                                        <input type="number" name="opti_behavior_intent[low_intent][time_spent]"
                                               value="<?php echo esc_attr( isset( $low_intent['time_spent'] ) ? $low_intent['time_spent'] : 10 ); ?>"
                                               min="0" max="3600" step="1" />
                                        <span class="input-suffix"><?php esc_html_e( 'sec', 'opti-behavior' ); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="intent-input-wrapper">
                                        <input type="number" name="opti_behavior_intent[medium_intent][time_spent]"
                                               value="<?php echo esc_attr( isset( $medium_intent['time_spent'] ) ? $medium_intent['time_spent'] : 30 ); ?>"
                                               min="0" max="3600" step="1" />
                                        <span class="input-suffix"><?php esc_html_e( 'sec', 'opti-behavior' ); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="intent-input-wrapper">
                                        <input type="number" name="opti_behavior_intent[high_intent][time_spent]"
                                               value="<?php echo esc_attr( isset( $high_intent['time_spent'] ) ? $high_intent['time_spent'] : 45 ); ?>"
                                               min="0" max="3600" step="1" />
                                        <span class="input-suffix"><?php esc_html_e( 'sec', 'opti-behavior' ); ?></span>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="metric-cell">
                                        <span class="dashicons dashicons-image-rotate-left"></span>
                                        <div class="metric-info">
                                            <span class="metric-name"><?php esc_html_e( 'Click Interactions', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['clicks_threshold']['title'], $tooltips['clicks_threshold']['content'], $tooltips['clicks_threshold']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                            <span class="metric-unit"><?php esc_html_e( 'Number of clicks', 'opti-behavior' ); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="intent-input-wrapper">
                                        <input type="number" name="opti_behavior_intent[low_intent][clicks]"
                                               value="<?php echo esc_attr( isset( $low_intent['clicks'] ) ? $low_intent['clicks'] : 0 ); ?>"
                                               min="0" max="100" step="1" />
                                    </div>
                                </td>
                                <td>
                                    <div class="intent-input-wrapper">
                                        <input type="number" name="opti_behavior_intent[medium_intent][clicks]"
                                               value="<?php echo esc_attr( isset( $medium_intent['clicks'] ) ? $medium_intent['clicks'] : 1 ); ?>"
                                               min="0" max="100" step="1" />
                                    </div>
                                </td>
                                <td>
                                    <div class="intent-input-wrapper">
                                        <input type="number" name="opti_behavior_intent[high_intent][clicks]"
                                               value="<?php echo esc_attr( isset( $high_intent['clicks'] ) ? $high_intent['clicks'] : 2 ); ?>"
                                               min="0" max="100" step="1" />
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div class="metric-cell">
                                        <span class="dashicons dashicons-sort"></span>
                                        <div class="metric-info">
                                            <span class="metric-name"><?php esc_html_e( 'Scroll Depth', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['scroll_depth_threshold']['title'], $tooltips['scroll_depth_threshold']['content'], $tooltips['scroll_depth_threshold']['simple'], '', array( 'position' => 'right' ) ); ?></span>
                                            <span class="metric-unit"><?php esc_html_e( 'Percentage of page', 'opti-behavior' ); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="intent-input-wrapper">
                                        <input type="number" name="opti_behavior_intent[low_intent][scroll_depth]"
                                               value="<?php echo esc_attr( isset( $low_intent['scroll_depth'] ) ? $low_intent['scroll_depth'] : 0 ); ?>"
                                               min="0" max="100" step="1" />
                                        <span class="input-suffix">%</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="intent-input-wrapper">
                                        <input type="number" name="opti_behavior_intent[medium_intent][scroll_depth]"
                                               value="<?php echo esc_attr( isset( $medium_intent['scroll_depth'] ) ? $medium_intent['scroll_depth'] : 15 ); ?>"
                                               min="0" max="100" step="1" />
                                        <span class="input-suffix">%</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="intent-input-wrapper">
                                        <input type="number" name="opti_behavior_intent[high_intent][scroll_depth]"
                                               value="<?php echo esc_attr( isset( $high_intent['scroll_depth'] ) ? $high_intent['scroll_depth'] : 50 ); ?>"
                                               min="0" max="100" step="1" />
                                        <span class="input-suffix">%</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="intent-rules-footer">
                        <span class="dashicons dashicons-info-outline"></span>
                        <p><?php esc_html_e( 'Classification Logic: A visitor must meet at least 2 out of 3 thresholds to be assigned to an intent level. Higher intent levels take priority when multiple levels match.', 'opti-behavior' ); ?></p>
                    </div>
                </div>

                <button type="submit" name="opti_behavior_intent_rules_submit" class="btn-save">
                    <span class="btn-icon"><i data-lucide="save"></i></span>
                    <?php esc_html_e( 'Save User Intent Rules', 'opti-behavior' ); ?>
                </button>
            </form>
            <?php
        }

        /**
         * Recalculate spam flags for all existing sessions based on current settings.
         *
         * This function updates the traffic_type and spam_reason columns for all sessions
         * based on the spam detection criteria: duration, events, and bounce rate.
         *
         * @since 1.0.8.28
         * @param array $settings Traffic settings with spam thresholds.
         */
        private function recalculate_session_spam_flags( $settings ) {
            global $wpdb;

            $spam_duration_threshold      = isset( $settings['spam_duration_threshold'] ) ? max( 0, intval( $settings['spam_duration_threshold'] ) ) : 3;
            $spam_min_scrolls_threshold   = isset( $settings['spam_min_scrolls_threshold'] ) ? max( 0, intval( $settings['spam_min_scrolls_threshold'] ) ) : 0;
            $spam_min_clicks_threshold    = isset( $settings['spam_min_clicks_threshold'] ) ? max( 0, intval( $settings['spam_min_clicks_threshold'] ) ) : 1;

            $sessions_table = $wpdb->prefix . 'optibehavior_sessions';
            $events_table = $wpdb->prefix . 'optibehavior_events';

            // First, reset all sessions to 'human' traffic type (except bots)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
            $wpdb->query(
                "UPDATE {$sessions_table} SET traffic_type = 'human', spam_reason = NULL WHERE traffic_type = 'spam' OR traffic_type IS NULL"
            );

            // Mark sessions as spam if they fail ANY criterion (OR logic):
            // A legitimate session must meet ALL: duration >= threshold AND scrolls >= min AND clicks >= min
            // So spam = fails at least one criterion
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$sessions_table} s
                 LEFT JOIN (
                     SELECT session_id, COUNT(*) as scroll_count
                     FROM {$events_table}
                     WHERE event IN (32,33)
                     GROUP BY session_id
                 ) sc ON s.id = sc.session_id
                 LEFT JOIN (
                     SELECT session_id, COUNT(*) as click_count
                     FROM {$events_table}
                     WHERE event IN (16,17)
                     GROUP BY session_id
                 ) cc ON s.id = cc.session_id
                 SET s.traffic_type = 'spam',
                     s.spam_reason = CONCAT_WS(',',
                         CASE WHEN (
                             CASE WHEN s.duration > 0 THEN s.duration
                                  ELSE TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time))
                             END
                         ) < %d THEN 'short_duration' ELSE NULL END,
                         CASE WHEN COALESCE(sc.scroll_count, 0) < %d THEN 'few_scrolls' ELSE NULL END,
                         CASE WHEN COALESCE(cc.click_count, 0) < %d THEN 'few_clicks' ELSE NULL END
                     )
                 WHERE (s.traffic_type IS NULL OR s.traffic_type = 'human')
                 AND (
                     (
                         CASE
                             WHEN s.duration > 0 THEN s.duration
                             ELSE TIMESTAMPDIFF(SECOND, s.start_time, COALESCE(s.end_time, s.start_time))
                         END
                     ) < %d
                     OR COALESCE(sc.scroll_count, 0) < %d
                     OR COALESCE(cc.click_count, 0) < %d
                 )",
                $spam_duration_threshold,
                $spam_min_scrolls_threshold,
                $spam_min_clicks_threshold,
                $spam_duration_threshold,
                $spam_min_scrolls_threshold,
                $spam_min_clicks_threshold
            ) );

            // Clear all related caches
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache cleanup query.
            $wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_optibehavior_top_users_%'" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Cache cleanup query.
            $wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_timeout_optibehavior_top_users_%'" );
        }

        /**
         * Render Scheduled Reports Tab
         *
         * @since 1.1.0
         */
        private function render_scheduled_reports_tab() {
            $tooltips = opti_behavior_get_settings_tooltips();
            // Get the scheduler instance
            $core = Opti_Behavior_Heatmap_Core::get_instance();
            $scheduler = new Opti_Behavior_Report_Scheduler( $core );
            $generator = new Opti_Behavior_Report_Generator( $core );

            // Get schedules and stats
            $schedules = $scheduler->get_schedules();
            $stats = $scheduler->get_stats();
            $logs = $scheduler->get_logs( 0, array( 'limit' => 10 ) );

            $report_cron_next = wp_next_scheduled( 'opti_behavior_send_scheduled_reports' );
            $report_cron_schedule = $report_cron_next ? wp_get_schedule( 'opti_behavior_send_scheduled_reports' ) : false;
            $report_cron_healthy = $report_cron_next && 'every_fifteen_minutes' === $report_cron_schedule;
            $wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

            // Get available sections for the form
            $available_sections = $generator->get_available_sections();
            $is_pro = function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active();

            // Get frequency and period options
            $frequency_options = Opti_Behavior_Report_Scheduler::get_frequency_options();
            $period_options = Opti_Behavior_Report_Scheduler::get_period_options();
            $day_of_week_options = Opti_Behavior_Report_Scheduler::get_day_of_week_options();

            // Get current options
            $options = $core->get_options();
            $from_name = isset( $options['reports_from_name'] ) ? $options['reports_from_name'] : get_bloginfo( 'name' );
            $from_email = isset( $options['reports_from_email'] ) ? $options['reports_from_email'] : '';
            $email_method = isset( $options['email_method'] ) ? $options['email_method'] : 'wp_mail';
            $smtp_host = isset( $options['smtp_host'] ) ? $options['smtp_host'] : '';
            $smtp_port = isset( $options['smtp_port'] ) ? $options['smtp_port'] : '587';
            $smtp_encryption = isset( $options['smtp_encryption'] ) ? $options['smtp_encryption'] : 'tls';
            $smtp_username = isset( $options['smtp_username'] ) ? $options['smtp_username'] : '';
            $smtp_password = isset( $options['smtp_password'] ) ? $options['smtp_password'] : '';
            ?>
            <div class="settings-section">
                <div class="section-header">
                    <h3 class="section-title">
                        <span class="section-icon"><i data-lucide="calendar-clock"></i></span>
                        <?php esc_html_e( 'Scheduled Reports', 'opti-behavior' ); ?>
                        <?php opti_behavior_tooltip_e( $tooltips['scheduled_reports']['title'], $tooltips['scheduled_reports']['content'], $tooltips['scheduled_reports']['simple'], '', array( 'position' => 'right' ) ); ?>
                    </h3>
                    <p class="section-description">
                        <?php esc_html_e( 'Automatically send analytics reports to your team on a regular schedule.', 'opti-behavior' ); ?>
                    </p>
                </div>

                <!-- Stats Overview -->
                <div class="opti-behavior-reports-stats" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px;">
                    <div class="stat-card" style="background: #f9fafb; border-radius: 8px; padding: 16px; text-align: center;">
                        <div class="stat-value" style="font-size: 28px; font-weight: 700; color: #18181b;"><?php echo esc_html( $stats['total'] ); ?></div>
                        <div class="stat-label" style="font-size: 12px; color: #71717a; text-transform: uppercase;"><?php esc_html_e( 'Total Schedules', 'opti-behavior' ); ?></div>
                    </div>
                    <div class="stat-card" style="background: #f0fdf4; border-radius: 8px; padding: 16px; text-align: center;">
                        <div class="stat-value" style="font-size: 28px; font-weight: 700; color: #16a34a;"><?php echo esc_html( $stats['enabled'] ); ?></div>
                        <div class="stat-label" style="font-size: 12px; color: #71717a; text-transform: uppercase;"><?php esc_html_e( 'Active', 'opti-behavior' ); ?></div>
                    </div>
                    <div class="stat-card" style="background: #fef3c7; border-radius: 8px; padding: 16px; text-align: center;">
                        <div class="stat-value" style="font-size: 28px; font-weight: 700; color: #d97706;"><?php echo esc_html( $stats['disabled'] ); ?></div>
                        <div class="stat-label" style="font-size: 12px; color: #71717a; text-transform: uppercase;"><?php esc_html_e( 'Paused', 'opti-behavior' ); ?></div>
                    </div>
                    <div class="stat-card" style="background: #f9fafb; border-radius: 8px; padding: 16px; text-align: center;">
                        <div class="stat-value" style="font-size: 28px; font-weight: 700; color: #18181b;"><?php echo esc_html( $stats['success_rate'] ); ?>%</div>
                        <div class="stat-label" style="font-size: 12px; color: #71717a; text-transform: uppercase;"><?php esc_html_e( 'Success Rate', 'opti-behavior' ); ?></div>
                    </div>
                </div>

                <!-- Worker Status -->
                <div class="opti-behavior-report-worker-status" style="background: <?php echo $report_cron_healthy ? '#f0fdf4' : '#fef2f2'; ?>; border: 1px solid <?php echo $report_cron_healthy ? '#bbf7d0' : '#fecaca'; ?>; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
                    <h4 style="margin: 0 0 10px; font-size: 14px; font-weight: 600; color: #18181b;">
                        <i data-lucide="activity" style="width: 16px; height: 16px; vertical-align: middle; margin-right: 8px;"></i>
                        <?php esc_html_e( 'Report Worker Status', 'opti-behavior' ); ?>
                    </h4>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; font-size: 12px; color: #52525b;">
                        <div>
                            <strong style="display: block; color: #18181b;"><?php esc_html_e( 'Worker', 'opti-behavior' ); ?></strong>
                            <span style="color: <?php echo $report_cron_healthy ? '#16a34a' : '#dc2626'; ?>; font-weight: 600;">
                                <?php echo esc_html( $report_cron_healthy ? __( 'Scheduled every 15 minutes', 'opti-behavior' ) : __( 'Missing or incorrect', 'opti-behavior' ) ); ?>
                            </span>
                        </div>
                        <div>
                            <strong style="display: block; color: #18181b;"><?php esc_html_e( 'Next worker run', 'opti-behavior' ); ?></strong>
                            <?php echo esc_html( $report_cron_next ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $report_cron_next ) : __( 'Not scheduled', 'opti-behavior' ) ); ?>
                        </div>
                        <div>
                            <strong style="display: block; color: #18181b;"><?php esc_html_e( 'Site time', 'opti-behavior' ); ?></strong>
                            <?php echo esc_html( current_time( 'mysql' ) ); ?>
                        </div>
                    </div>
                    <?php if ( $wp_cron_disabled ) : ?>
                        <p style="margin: 12px 0 0; color: #b45309; font-size: 12px;">
                            <?php esc_html_e( 'DISABLE_WP_CRON is enabled. Make sure a real server cron calls wp-cron.php regularly.', 'opti-behavior' ); ?>
                        </p>
                    <?php elseif ( ! $report_cron_healthy && $stats['enabled'] > 0 ) : ?>
                        <p style="margin: 12px 0 0; color: #dc2626; font-size: 12px;">
                            <?php esc_html_e( 'Active schedules exist, but the report worker is not currently scheduled. Saving or toggling a schedule will repair it automatically.', 'opti-behavior' ); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Email Settings -->
                <div class="opti-behavior-email-settings" style="background: #f9fafb; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
                    <h4 style="margin: 0 0 16px; font-size: 14px; font-weight: 600; color: #18181b;">
                        <i data-lucide="mail" style="width: 16px; height: 16px; vertical-align: middle; margin-right: 8px;"></i>
                        <?php esc_html_e( 'Email Settings', 'opti-behavior' ); ?>
                    </h4>
                    <form method="post" action="" id="email-settings-form" class="opti-behavior-email-settings-form">
                        <?php wp_nonce_field( 'opti_behavior_report_email_settings', 'opti_behavior_report_email_nonce' ); ?>

                        <!-- Email Method Selector -->
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #52525b;">
                                <?php esc_html_e( 'Email Delivery Method', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['email_delivery_method']['title'], $tooltips['email_delivery_method']['content'], $tooltips['email_delivery_method']['simple'], '', array( 'position' => 'right' ) ); ?>
                            </label>
                            <div style="display: flex; gap: 16px;">
                                <label style="display: flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: 8px; border: 2px solid <?php echo $email_method === 'wp_mail' ? '#4f46e5' : '#e4e4e7'; ?>; cursor: pointer; background: <?php echo $email_method === 'wp_mail' ? '#eef2ff' : '#fff'; ?>; transition: all 0.2s;">
                                    <input type="radio" name="email_method" value="wp_mail" <?php checked( $email_method, 'wp_mail' ); ?> style="accent-color: #4f46e5;">
                                    <div>
                                        <div style="font-weight: 600; font-size: 13px; color: #18181b;"><?php esc_html_e( 'WordPress Default', 'opti-behavior' ); ?></div>
                                        <div style="font-size: 11px; color: #71717a;"><?php esc_html_e( 'Uses wp_mail() — works with most hosts', 'opti-behavior' ); ?></div>
                                    </div>
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: 8px; border: 2px solid <?php echo $email_method === 'smtp' ? '#4f46e5' : '#e4e4e7'; ?>; cursor: pointer; background: <?php echo $email_method === 'smtp' ? '#eef2ff' : '#fff'; ?>; transition: all 0.2s;">
                                    <input type="radio" name="email_method" value="smtp" <?php checked( $email_method, 'smtp' ); ?> style="accent-color: #4f46e5;">
                                    <div>
                                        <div style="font-weight: 600; font-size: 13px; color: #18181b;"><?php esc_html_e( 'Custom SMTP', 'opti-behavior' ); ?></div>
                                        <div style="font-size: 11px; color: #71717a;"><?php esc_html_e( 'Gmail, Outlook, SendGrid, etc.', 'opti-behavior' ); ?></div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- From Name & Email (always visible) -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="form-group">
                                <label for="reports_from_name" style="display: block; margin-bottom: 4px; font-size: 13px; color: #52525b;">
                                    <?php esc_html_e( 'From Name', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['from_name_email']['title'], $tooltips['from_name_email']['content'], $tooltips['from_name_email']['simple'], '', array( 'position' => 'right' ) ); ?>
                                </label>
                                <input type="text" id="reports_from_name" name="reports_from_name"
                                       value="<?php echo esc_attr( $from_name ); ?>"
                                       class="regular-text" style="width: 100%;"
                                       placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                            </div>
                            <div class="form-group">
                                <label for="reports_from_email" style="display: block; margin-bottom: 4px; font-size: 13px; color: #52525b;">
                                    <?php esc_html_e( 'From Email', 'opti-behavior' ); ?>
                                </label>
                                <input type="email" id="reports_from_email" name="reports_from_email"
                                       value="<?php echo esc_attr( $from_email ); ?>"
                                       class="regular-text" style="width: 100%;"
                                       placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                                <p class="description" style="margin-top: 4px; font-size: 11px; color: #a1a1aa;">
                                    <?php esc_html_e( 'Leave blank to use admin email', 'opti-behavior' ); ?>
                                </p>
                            </div>
                        </div>

                        <!-- SMTP Configuration (shown only when SMTP is selected) -->
                        <div id="opti-behavior-smtp-config" style="margin-top: 16px; padding: 16px; background: #fff; border: 1px solid #e4e4e7; border-radius: 8px; display: <?php echo $email_method === 'smtp' ? 'block' : 'none'; ?>;">
                            <h5 style="margin: 0 0 12px; font-size: 13px; font-weight: 600; color: #18181b;">
                                <i data-lucide="server" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 6px;"></i>
                                <?php esc_html_e( 'SMTP Configuration', 'opti-behavior' ); ?>
                            </h5>
                            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                                <div class="form-group">
                                    <label for="smtp_host" style="display: block; margin-bottom: 4px; font-size: 12px; color: #52525b;">
                                        <?php esc_html_e( 'SMTP Host', 'opti-behavior' ); ?>
                                    </label>
                                    <input type="text" id="smtp_host" name="smtp_host"
                                           value="<?php echo esc_attr( $smtp_host ); ?>"
                                           class="regular-text" style="width: 100%;"
                                           placeholder="smtp.gmail.com">
                                </div>
                                <div class="form-group">
                                    <label for="smtp_port" style="display: block; margin-bottom: 4px; font-size: 12px; color: #52525b;">
                                        <?php esc_html_e( 'Port', 'opti-behavior' ); ?>
                                    </label>
                                    <input type="number" id="smtp_port" name="smtp_port"
                                           value="<?php echo esc_attr( $smtp_port ); ?>"
                                           class="regular-text" style="width: 100%;"
                                           placeholder="587">
                                </div>
                                <div class="form-group">
                                    <label for="smtp_encryption" style="display: block; margin-bottom: 4px; font-size: 12px; color: #52525b;">
                                        <?php esc_html_e( 'Encryption', 'opti-behavior' ); ?>
                                    </label>
                                    <select id="smtp_encryption" name="smtp_encryption" style="width: 100%; height: 32px; border-radius: 4px; border: 1px solid #d4d4d8;">
                                        <option value="none" <?php selected( $smtp_encryption, 'none' ); ?>><?php esc_html_e( 'None', 'opti-behavior' ); ?></option>
                                        <option value="ssl" <?php selected( $smtp_encryption, 'ssl' ); ?>>SSL</option>
                                        <option value="tls" <?php selected( $smtp_encryption, 'tls' ); ?>>TLS</option>
                                    </select>
                                </div>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div class="form-group">
                                    <label for="smtp_username" style="display: block; margin-bottom: 4px; font-size: 12px; color: #52525b;">
                                        <?php esc_html_e( 'Username', 'opti-behavior' ); ?>
                                    </label>
                                    <input type="text" id="smtp_username" name="smtp_username"
                                           value="<?php echo esc_attr( $smtp_username ); ?>"
                                           class="regular-text" style="width: 100%;"
                                           placeholder="your@email.com" autocomplete="off">
                                </div>
                                <div class="form-group">
                                    <label for="smtp_password" style="display: block; margin-bottom: 4px; font-size: 12px; color: #52525b;">
                                        <?php esc_html_e( 'Password', 'opti-behavior' ); ?>
                                    </label>
                                    <input type="password" id="smtp_password" name="smtp_password"
                                           value="<?php echo esc_attr( $smtp_password ); ?>"
                                           class="regular-text" style="width: 100%;"
                                           placeholder="<?php echo $smtp_password ? '••••••••' : ''; ?>" autocomplete="new-password">
                                    <p class="description" style="margin-top: 4px; font-size: 11px; color: #a1a1aa;">
                                        <?php esc_html_e( 'For Gmail, use an App Password (not your regular password)', 'opti-behavior' ); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div style="display: flex; gap: 12px; margin-top: 16px; align-items: center;">
                            <button type="submit" name="opti_behavior_save_report_email_settings" class="btn-save">
                                <span class="btn-icon"><i data-lucide="save"></i></span>
                                <?php esc_html_e( 'Save Email Settings', 'opti-behavior' ); ?>
                            </button>
                            <button type="button" id="opti-behavior-test-email-btn" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 16px;">
                                <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                                <?php esc_html_e( 'Send Test Email', 'opti-behavior' ); ?>
                            </button>
                            <span id="opti-behavior-test-email-result" style="font-size: 13px;"></span>
                        </div>
                    </form>
                </div>

                <!-- Schedules List -->
                <div class="opti-behavior-schedules-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h4 style="margin: 0; font-size: 14px; font-weight: 600; color: #18181b;">
                            <i data-lucide="list" style="width: 16px; height: 16px; vertical-align: middle; margin-right: 8px;"></i>
                            <?php esc_html_e( 'Report Schedules', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['report_schedules']['title'], $tooltips['report_schedules']['content'], $tooltips['report_schedules']['simple'], '', array( 'position' => 'right' ) ); ?>
                        </h4>
                        <button type="button" id="opti-behavior-add-schedule" class="btn-save">
                            <span class="btn-icon"><i data-lucide="plus"></i></span>
                            <?php esc_html_e( 'New Schedule', 'opti-behavior' ); ?>
                        </button>
                    </div>

                    <?php if ( empty( $schedules ) ) : ?>
                        <div class="opti-behavior-empty-state" style="background: #f9fafb; border: 2px dashed #e4e4e7; border-radius: 8px; padding: 40px; text-align: center;">
                            <div style="font-size: 48px; margin-bottom: 16px;">📊</div>
                            <h4 style="margin: 0 0 8px; font-size: 16px; color: #18181b;"><?php esc_html_e( 'No scheduled reports yet', 'opti-behavior' ); ?></h4>
                            <p style="margin: 0 0 16px; color: #71717a; font-size: 14px;">
                                <?php esc_html_e( 'Create your first scheduled report to automatically receive analytics in your inbox.', 'opti-behavior' ); ?>
                            </p>
                            <button type="button" class="btn-save opti-behavior-add-schedule-btn">
                                <span class="btn-icon"><i data-lucide="plus"></i></span>
                                <?php esc_html_e( 'Create First Schedule', 'opti-behavior' ); ?>
                            </button>
                        </div>
                    <?php else : ?>
                        <div class="opti-behavior-schedules-list" style="border: 1px solid #e4e4e7; border-radius: 8px; overflow: hidden;">
                            <?php foreach ( $schedules as $index => $schedule ) :
                                $next_send = $schedule['next_send_at'] ? human_time_diff( strtotime( $schedule['next_send_at'] ) ) : '—';
                                $last_sent = $schedule['last_sent_at'] ? human_time_diff( strtotime( $schedule['last_sent_at'] ) ) . ' ' . __( 'ago', 'opti-behavior' ) : __( 'Never', 'opti-behavior' );
                                $recipients = is_array( $schedule['recipients'] ) ? $schedule['recipients'] : array();
                            ?>
                                <div class="schedule-item" style="display: flex; align-items: center; padding: 16px; <?php echo $index > 0 ? 'border-top: 1px solid #e4e4e7;' : ''; ?> <?php echo ! $schedule['enabled'] ? 'opacity: 0.6;' : ''; ?>">
                                    <div class="schedule-toggle" style="margin-right: 16px;">
                                        <label class="opti-behavior-toggle" style="position: relative; display: inline-block; width: 44px; height: 24px;">
                                            <input type="checkbox" class="schedule-enabled-toggle" data-schedule-id="<?php echo esc_attr( $schedule['id'] ); ?>"
                                                   <?php checked( $schedule['enabled'], 1 ); ?>
                                                   style="opacity: 0; width: 0; height: 0;">
                                            <span class="toggle-slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: <?php echo $schedule['enabled'] ? '#4f46e5' : '#ccc'; ?>; transition: .4s; border-radius: 24px;">
                                                <span style="position: absolute; content: ''; height: 18px; width: 18px; left: <?php echo $schedule['enabled'] ? '23px' : '3px'; ?>; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%;"></span>
                                            </span>
                                        </label>
                                    </div>
                                    <div class="schedule-info" style="flex: 1;">
                                        <div class="schedule-name" style="font-weight: 600; color: #18181b; margin-bottom: 4px;">
                                            <?php echo esc_html( $schedule['name'] ); ?>
                                        </div>
                                        <div class="schedule-meta" style="font-size: 12px; color: #71717a;">
                                            <?php
                                            $freq_label = $frequency_options[ $schedule['frequency'] ] ?? $schedule['frequency'];
                                            $period_label = $period_options[ $schedule['report_period'] ] ?? $schedule['report_period'];

                                            if ( 'weekly' === $schedule['frequency'] ) {
                                                $day_label = $day_of_week_options[ $schedule['day_of_week'] ] ?? '';
                                                /* translators: %1$s: day of week, %2$s: time */
                                                echo esc_html( sprintf( __( 'Every %1$s at %2$s', 'opti-behavior' ), $day_label, date_i18n( 'g:i A', strtotime( $schedule['send_time'] ) ) ) );
                                            } elseif ( 'monthly' === $schedule['frequency'] ) {
                                                /* translators: %1$s: day of month, %2$s: time */
                                                echo esc_html( sprintf( __( '%1$s of month at %2$s', 'opti-behavior' ), ordinal( $schedule['day_of_month'] ), date_i18n( 'g:i A', strtotime( $schedule['send_time'] ) ) ) );
                                            } else {
                                                /* translators: %s: time of day */
                                                echo esc_html( sprintf( __( 'Daily at %s', 'opti-behavior' ), date_i18n( 'g:i A', strtotime( $schedule['send_time'] ) ) ) );
                                            }
                                            ?>
                                            &bull; <?php echo esc_html( $period_label ); ?>
                                            &bull; <?php echo esc_html( count( $recipients ) ); ?> <?php echo esc_html( _n( 'recipient', 'recipients', count( $recipients ), 'opti-behavior' ) ); ?>
                                        </div>
                                    </div>
                                    <div class="schedule-status" style="text-align: right; margin-right: 16px;">
                                        <div style="font-size: 12px; color: #71717a;">
                                            <?php esc_html_e( 'Next:', 'opti-behavior' ); ?>
                                            <span style="color: #18181b; font-weight: 500;"><?php echo esc_html( $schedule['enabled'] ? $next_send : '—' ); ?></span>
                                        </div>
                                        <div style="font-size: 11px; color: #a1a1aa;">
                                            <?php esc_html_e( 'Last sent:', 'opti-behavior' ); ?> <?php echo esc_html( $last_sent ); ?>
                                        </div>
                                    </div>
                                    <div class="schedule-actions" style="display: flex; gap: 8px;">
                                        <button type="button" class="button button-small schedule-edit-btn" data-schedule-id="<?php echo esc_attr( $schedule['id'] ); ?>"
                                                title="<?php esc_attr_e( 'Edit', 'opti-behavior' ); ?>">
                                            <i data-lucide="pencil" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button type="button" class="button button-small schedule-test-btn" data-schedule-id="<?php echo esc_attr( $schedule['id'] ); ?>"
                                                title="<?php esc_attr_e( 'Send Test', 'opti-behavior' ); ?>">
                                            <i data-lucide="send" style="width: 14px; height: 14px;"></i>
                                        </button>
                                        <button type="button" class="button button-small schedule-delete-btn" data-schedule-id="<?php echo esc_attr( $schedule['id'] ); ?>"
                                                title="<?php esc_attr_e( 'Delete', 'opti-behavior' ); ?>" style="color: #dc2626;">
                                            <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Report History -->
                <?php if ( ! empty( $logs ) ) : ?>
                <div class="opti-behavior-report-logs" style="margin-top: 32px;">
                    <h4 style="margin: 0 0 16px; font-size: 14px; font-weight: 600; color: #18181b;">
                        <i data-lucide="history" style="width: 16px; height: 16px; vertical-align: middle; margin-right: 8px;"></i>
                        <?php esc_html_e( 'Recent Report History', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['report_history']['title'], $tooltips['report_history']['content'], $tooltips['report_history']['simple'], '', array( 'position' => 'right' ) ); ?>
                    </h4>
                    <div style="border: 1px solid #e4e4e7; border-radius: 8px; overflow: hidden;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                            <thead>
                                <tr style="background: #f9fafb;">
                                    <th style="padding: 12px; text-align: left; font-weight: 500; color: #52525b;"><?php esc_html_e( 'Report', 'opti-behavior' ); ?></th>
                                    <th style="padding: 12px; text-align: left; font-weight: 500; color: #52525b;"><?php esc_html_e( 'Sent', 'opti-behavior' ); ?></th>
                                    <th style="padding: 12px; text-align: center; font-weight: 500; color: #52525b;"><?php esc_html_e( 'Status', 'opti-behavior' ); ?></th>
                                    <th style="padding: 12px; text-align: right; font-weight: 500; color: #52525b;"><?php esc_html_e( 'Recipients', 'opti-behavior' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $logs as $log ) :
                                    $status_color = 'success' === $log['status'] ? '#16a34a' : ( 'failed' === $log['status'] ? '#dc2626' : '#d97706' );
                                    $status_bg = 'success' === $log['status'] ? '#f0fdf4' : ( 'failed' === $log['status'] ? '#fef2f2' : '#fef3c7' );
                                ?>
                                    <tr style="border-top: 1px solid #e4e4e7;">
                                        <td style="padding: 12px; color: #18181b;"><?php echo esc_html( $log['schedule_name'] ?? __( 'Deleted Schedule', 'opti-behavior' ) ); ?></td>
                                        <td style="padding: 12px; color: #71717a;">
                                            <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log['sent_at'] ) ) ); ?>
                                        </td>
                                        <td style="padding: 12px; text-align: center;">
                                            <span style="display: inline-block; padding: 2px 8px; background: <?php echo esc_attr( $status_bg ); ?>; color: <?php echo esc_attr( $status_color ); ?>; border-radius: 4px; font-size: 11px; font-weight: 500; text-transform: uppercase;">
                                                <?php echo esc_html( $log['status'] ); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 12px; text-align: right; color: #71717a;">
                                            <?php echo esc_html( $log['recipients_success'] ); ?>/<?php echo esc_html( $log['recipients_count'] ); ?>
                                        </td>
                                    </tr>
                                    <?php if ( ! empty( $log['error_message'] ) ) : ?>
                                    <tr style="background: #fef2f2;">
                                        <td colspan="4" style="padding: 8px 12px; font-size: 12px; color: #dc2626;">
                                            <i data-lucide="alert-circle" style="width: 12px; height: 12px; vertical-align: middle; margin-right: 4px;"></i>
                                            <?php echo esc_html( $log['error_message'] ); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Schedule Form Modal -->
            <div id="opti-behavior-schedule-modal" class="opti-behavior-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center;">
                <div class="modal-content" style="background: white; border-radius: 12px; width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);">
                    <div class="modal-header" style="padding: 20px 24px; border-bottom: 1px solid #e4e4e7; display: flex; justify-content: space-between; align-items: center;">
                        <h3 id="modal-title" style="margin: 0; font-size: 18px; font-weight: 600; color: #18181b;">
                            <?php esc_html_e( 'New Report Schedule', 'opti-behavior' ); ?>
                        </h3>
                        <button type="button" id="close-schedule-modal" style="background: none; border: none; cursor: pointer; padding: 4px;">
                            <i data-lucide="x" style="width: 20px; height: 20px; color: #71717a;"></i>
                        </button>
                    </div>
                    <form id="schedule-form" class="modal-body" style="padding: 24px;">
                        <input type="hidden" id="schedule-id" name="schedule_id" value="">
                        <?php wp_nonce_field( 'opti_behavior_schedule_action', 'schedule_nonce' ); ?>

                        <!-- Report Name -->
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label for="schedule-name" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #18181b;">
                                <?php esc_html_e( 'Report Name', 'opti-behavior' ); ?>
                            </label>
                            <input type="text" id="schedule-name" name="name" required
                                   style="width: 100%; padding: 10px 12px; border: 1px solid #d4d4d8; border-radius: 6px; font-size: 14px;"
                                   placeholder="<?php esc_attr_e( 'e.g., Weekly Performance Report', 'opti-behavior' ); ?>">
                        </div>

                        <!-- Schedule Section -->
                        <div style="background: #f9fafb; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
                            <h4 style="margin: 0 0 16px; font-size: 13px; font-weight: 600; color: #52525b; text-transform: uppercase; letter-spacing: 0.5px;">
                                <?php esc_html_e( 'Schedule', 'opti-behavior' ); ?>
                            </h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                                <div class="form-group">
                                    <label for="schedule-frequency" style="display: block; margin-bottom: 6px; font-size: 13px; color: #52525b;">
                                        <?php esc_html_e( 'Frequency', 'opti-behavior' ); ?>
                                    </label>
                                    <select id="schedule-frequency" name="frequency" style="width: 100%; padding: 10px 12px; border: 1px solid #d4d4d8; border-radius: 6px; font-size: 14px;">
                                        <?php foreach ( $frequency_options as $value => $label ) : ?>
                                            <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" id="day-of-week-group">
                                    <label for="schedule-day-of-week" style="display: block; margin-bottom: 6px; font-size: 13px; color: #52525b;">
                                        <?php esc_html_e( 'Day', 'opti-behavior' ); ?>
                                    </label>
                                    <select id="schedule-day-of-week" name="day_of_week" style="width: 100%; padding: 10px 12px; border: 1px solid #d4d4d8; border-radius: 6px; font-size: 14px;">
                                        <?php foreach ( $day_of_week_options as $value => $label ) : ?>
                                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, 1 ); ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" id="day-of-month-group" style="display: none;">
                                    <label for="schedule-day-of-month" style="display: block; margin-bottom: 6px; font-size: 13px; color: #52525b;">
                                        <?php esc_html_e( 'Day of Month', 'opti-behavior' ); ?>
                                    </label>
                                    <select id="schedule-day-of-month" name="day_of_month" style="width: 100%; padding: 10px 12px; border: 1px solid #d4d4d8; border-radius: 6px; font-size: 14px;">
                                        <?php for ( $i = 1; $i <= 28; $i++ ) : ?>
                                            <option value="<?php echo esc_attr( $i ); ?>"><?php echo esc_html( $i ); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="schedule-time" style="display: block; margin-bottom: 6px; font-size: 13px; color: #52525b;">
                                        <?php esc_html_e( 'Time', 'opti-behavior' ); ?>
                                    </label>
                                    <input type="time" id="schedule-time" name="send_time" value="09:00"
                                           style="width: 100%; padding: 10px 12px; border: 1px solid #d4d4d8; border-radius: 6px; font-size: 14px;">
                                </div>
                            </div>
                        </div>

                        <!-- Report Period -->
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label for="schedule-period" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #18181b;">
                                <?php esc_html_e( 'Report Period', 'opti-behavior' ); ?>
                            </label>
                            <select id="schedule-period" name="report_period" style="width: 100%; padding: 10px 12px; border: 1px solid #d4d4d8; border-radius: 6px; font-size: 14px;">
                                <?php foreach ( $period_options as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, 'last7days' ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Content Sections -->
                        <div style="margin-bottom: 20px;">
                            <h4 style="margin: 0 0 12px; font-size: 13px; font-weight: 600; color: #52525b; text-transform: uppercase; letter-spacing: 0.5px;">
                                <?php esc_html_e( 'Report Content', 'opti-behavior' ); ?>
                            </h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                <?php foreach ( $available_sections as $key => $section ) :
                                    $disabled = $section['pro'] && ! $is_pro;
                                ?>
                                    <label style="display: flex; align-items: center; padding: 10px 12px; background: <?php echo $disabled ? '#f4f4f5' : '#f9fafb'; ?>; border-radius: 6px; cursor: <?php echo $disabled ? 'not-allowed' : 'pointer'; ?>; <?php echo $disabled ? 'opacity: 0.6;' : ''; ?>">
                                        <input type="checkbox" name="include_<?php echo esc_attr( $key ); ?>" value="1"
                                               <?php checked( $section['default'], true ); ?>
                                               <?php disabled( $disabled, true ); ?>
                                               style="margin-right: 10px;">
                                        <span style="font-size: 13px; color: #18181b;">
                                            <?php echo esc_html( $section['label'] ); ?>
                                            <?php if ( $section['pro'] ) : ?>
                                                <span style="display: inline-block; padding: 1px 6px; background: #eef2ff; color: #4f46e5; border-radius: 4px; font-size: 10px; font-weight: 600; margin-left: 4px;">PRO</span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Recipients -->
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label for="schedule-recipients" style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #18181b;">
                                <?php esc_html_e( 'Recipients', 'opti-behavior' ); ?>
                            </label>
                            <textarea id="schedule-recipients" name="recipients" rows="3" required
                                      style="width: 100%; padding: 10px 12px; border: 1px solid #d4d4d8; border-radius: 6px; font-size: 14px; resize: vertical;"
                                      placeholder="<?php esc_attr_e( 'Enter email addresses, one per line', 'opti-behavior' ); ?>"><?php echo esc_textarea( get_option( 'admin_email' ) ); ?></textarea>
                            <p style="margin-top: 6px; font-size: 11px; color: #a1a1aa;">
                                <?php esc_html_e( 'Enter one email address per line', 'opti-behavior' ); ?>
                            </p>
                        </div>
                    </form>
                    <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid #e4e4e7; display: flex; justify-content: flex-end; gap: 12px; background: #f9fafb;">
                        <button type="button" id="cancel-schedule" class="button button-secondary">
                            <?php esc_html_e( 'Cancel', 'opti-behavior' ); ?>
                        </button>
                        <button type="button" id="test-schedule" class="button button-secondary">
                            <i data-lucide="send" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 4px;"></i>
                            <?php esc_html_e( 'Send Test', 'opti-behavior' ); ?>
                        </button>
                        <button type="button" id="save-schedule" class="button button-primary">
                            <?php esc_html_e( 'Save Schedule', 'opti-behavior' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Test Email Result Modal -->
            <div id="opti-behavior-test-result-modal" class="opti-behavior-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
                <div style="background: white; border-radius: 16px; width: 100%; max-width: 440px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); animation: modalSlideIn 0.3s ease-out;">
                    <div style="padding: 24px 24px 0; text-align: left;">
                        <h3 id="test-result-title" style="margin: 0 0 8px; font-size: 16px; font-weight: 600; color: #18181b;"></h3>
                        <p id="test-result-message" style="margin: 0; font-size: 14px; color: #52525b;"></p>
                    </div>
                    <div style="padding: 16px 24px 20px; display: flex; justify-content: flex-end;">
                        <button type="button" id="test-result-ok" style="padding: 8px 28px; border-radius: 20px; border: 2px solid #78716c; background: transparent; color: #44403c; font-size: 14px; font-weight: 600; cursor: pointer;">
                            <?php esc_html_e( 'OK', 'opti-behavior' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                // Test result modal helpers
                function showTestResultModal(title, message, isSuccess) {
                    $('#test-result-title').text(title);
                    var $msg = $('#test-result-message');
                    $msg.text(message);
                    $msg.css('color', isSuccess ? '#16a34a' : '#dc2626');
                    $('#opti-behavior-test-result-modal').css('display', 'flex');
                }
                $('#test-result-ok, #opti-behavior-test-result-modal').on('click', function(e) {
                    if (e.target === this || $(this).is('#test-result-ok')) {
                        $('#opti-behavior-test-result-modal').fadeOut(200);
                    }
                });

                // Modal open/close
                $('#opti-behavior-add-schedule, .opti-behavior-add-schedule-btn').on('click', function() {
                    $('#modal-title').text('<?php echo esc_js( __( 'New Report Schedule', 'opti-behavior' ) ); ?>');
                    $('#schedule-id').val('');
                    $('#schedule-form')[0].reset();
                    $('#opti-behavior-schedule-modal').css('display', 'flex');
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                });

                $('#close-schedule-modal, #cancel-schedule').on('click', function() {
                    $('#opti-behavior-schedule-modal').hide();
                });

                // Frequency change handler
                $('#schedule-frequency').on('change', function() {
                    var freq = $(this).val();
                    if (freq === 'weekly') {
                        $('#day-of-week-group').show();
                        $('#day-of-month-group').hide();
                    } else if (freq === 'monthly') {
                        $('#day-of-week-group').hide();
                        $('#day-of-month-group').show();
                    } else {
                        $('#day-of-week-group').hide();
                        $('#day-of-month-group').hide();
                    }
                }).trigger('change');

                // Edit schedule
                $('.schedule-edit-btn').on('click', function() {
                    var scheduleId = $(this).data('schedule-id');
                    // Load schedule data via AJAX
                    $.post(ajaxurl, {
                        action: 'optibehavior_get_schedule',
                        schedule_id: scheduleId,
                        nonce: $('#schedule_nonce').val()
                    }, function(response) {
                        if (response.success) {
                            var data = response.data;
                            $('#modal-title').text('<?php echo esc_js( __( 'Edit Report Schedule', 'opti-behavior' ) ); ?>');
                            $('#schedule-id').val(data.id);
                            $('#schedule-name').val(data.name);
                            $('#schedule-frequency').val(data.frequency).trigger('change');
                            $('#schedule-day-of-week').val(data.day_of_week);
                            $('#schedule-day-of-month').val(data.day_of_month);
                            $('#schedule-time').val(data.send_time.substring(0, 5));
                            $('#schedule-period').val(data.report_period);
                            $('#schedule-recipients').val(data.recipients.join('\n'));
                            // Set checkboxes
                            $('input[name^="include_"]').each(function() {
                                var key = $(this).attr('name');
                                $(this).prop('checked', data[key] == 1);
                            });
                            $('#opti-behavior-schedule-modal').css('display', 'flex');
                            if (typeof lucide !== 'undefined') lucide.createIcons();
                        }
                    });
                });

                // Save schedule
                $('#save-schedule').on('click', function() {
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Saving...', 'opti-behavior' ) ); ?>');

                    var formData = $('#schedule-form').serialize();
                    formData += '&action=optibehavior_save_schedule';

                    $.post(ajaxurl, formData, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data || '<?php echo esc_js( __( 'Error saving schedule', 'opti-behavior' ) ); ?>');
                            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Save Schedule', 'opti-behavior' ) ); ?>');
                        }
                    });
                });

                // Toggle schedule
                $('.schedule-enabled-toggle').on('change', function() {
                    var scheduleId = $(this).data('schedule-id');
                    var enabled = $(this).is(':checked') ? 1 : 0;
                    var $toggle = $(this);
                    var $slider = $toggle.siblings('.toggle-slider');

                    $.post(ajaxurl, {
                        action: 'optibehavior_toggle_schedule',
                        schedule_id: scheduleId,
                        enabled: enabled,
                        nonce: '<?php echo esc_js( wp_create_nonce( 'opti_behavior_schedule_action' ) ); ?>'
                    }, function(response) {
                        if (response.success) {
                            // Update toggle visual state
                            if (enabled) {
                                $slider.css('background-color', '#4f46e5');
                                $slider.find('span').css('left', '23px');
                            } else {
                                $slider.css('background-color', '#ccc');
                                $slider.find('span').css('left', '3px');
                            }
                            // Update stats counters
                            var activeCount = $('.schedule-enabled-toggle:checked').length;
                            var totalCount = $('.schedule-enabled-toggle').length;
                            var pausedCount = totalCount - activeCount;
                            var $statCards = $('.opti-behavior-reports-stats .stat-card');
                            $statCards.eq(1).find('.stat-value').text(activeCount);
                            $statCards.eq(2).find('.stat-value').text(pausedCount);
                        } else {
                            // Revert toggle on error
                            $toggle.prop('checked', !enabled);
                            location.reload();
                        }
                    });
                });

                // Delete schedule
                $('.schedule-delete-btn').on('click', function() {
                    if (!confirm('<?php echo esc_js( __( 'Are you sure you want to delete this schedule?', 'opti-behavior' ) ); ?>')) {
                        return;
                    }

                    var scheduleId = $(this).data('schedule-id');

                    $.post(ajaxurl, {
                        action: 'optibehavior_delete_schedule',
                        schedule_id: scheduleId,
                        nonce: '<?php echo esc_js( wp_create_nonce( 'opti_behavior_schedule_action' ) ); ?>'
                    }, function(response) {
                        location.reload();
                    });
                });

                // Test schedule
                $('.schedule-test-btn, #test-schedule').on('click', function() {
                    var scheduleId = $(this).data('schedule-id') || $('#schedule-id').val();
                    var email = prompt('<?php echo esc_js( __( 'Enter email address for test:', 'opti-behavior' ) ); ?>', '<?php echo esc_js( get_option( 'admin_email' ) ); ?>');

                    if (!email) return;

                    var $btn = $(this);
                    $btn.prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'optibehavior_test_report',
                        schedule_id: scheduleId,
                        email: email,
                        nonce: '<?php echo esc_js( wp_create_nonce( 'opti_behavior_schedule_action' ) ); ?>'
                    }, function(response) {
                        $btn.prop('disabled', false);
                        var siteHost = window.location.hostname;
                        if (response.success) {
                            showTestResultModal(siteHost + ' <?php echo esc_js( __( 'indicates', 'opti-behavior' ) ); ?>', '<?php echo esc_js( __( 'Test email sent successfully!', 'opti-behavior' ) ); ?>', true);
                        } else {
                            showTestResultModal(siteHost + ' <?php echo esc_js( __( 'indicates', 'opti-behavior' ) ); ?>', response.data || '<?php echo esc_js( __( 'Failed to send test email', 'opti-behavior' ) ); ?>', false);
                        }
                    }).fail(function(jqXHR, textStatus, errorThrown) {
                        $btn.prop('disabled', false);
                        var siteHost = window.location.hostname;
                        var errorMsg = '<?php echo esc_js( __( 'Server error', 'opti-behavior' ) ); ?>: ' + textStatus + ' (' + (jqXHR.status || '<?php echo esc_js( __( 'unknown', 'opti-behavior' ) ); ?>') + ')';
                        showTestResultModal(siteHost + ' <?php echo esc_js( __( 'indicates', 'opti-behavior' ) ); ?>', errorMsg, false);
                    });
                });

                // Email method toggle - show/hide SMTP config
                $('input[name="email_method"]').on('change', function() {
                    var method = $(this).val();
                    var $smtpConfig = $('#opti-behavior-smtp-config');
                    if (method === 'smtp') {
                        $smtpConfig.slideDown(200);
                    } else {
                        $smtpConfig.slideUp(200);
                    }
                    // Update radio label styling
                    $('input[name="email_method"]').each(function() {
                        var $label = $(this).closest('label');
                        if ($(this).is(':checked')) {
                            $label.css({'border-color': '#4f46e5', 'background': '#eef2ff'});
                        } else {
                            $label.css({'border-color': '#e4e4e7', 'background': '#fff'});
                        }
                    });
                });

                // Email settings form
                $('#email-settings-form').on('submit', function(e) {
                    e.preventDefault();
                    var $btn = $(this).find('button[type="submit"]');
                    var originalText = $btn.html();
                    $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Saving...', 'opti-behavior' ) ); ?>');

                    var postData = {
                        action: 'optibehavior_save_email_settings',
                        from_name: $('#reports_from_name').val(),
                        from_email: $('#reports_from_email').val(),
                        email_method: $('input[name="email_method"]:checked').val(),
                        nonce: '<?php echo esc_js( wp_create_nonce( 'opti_behavior_schedule_action' ) ); ?>'
                    };

                    // Include SMTP fields if method is smtp
                    if (postData.email_method === 'smtp') {
                        postData.smtp_host = $('#smtp_host').val();
                        postData.smtp_port = $('#smtp_port').val();
                        postData.smtp_encryption = $('#smtp_encryption').val();
                        postData.smtp_username = $('#smtp_username').val();
                        postData.smtp_password = $('#smtp_password').val();
                    }

                    $.post(ajaxurl, postData, function(response) {
                        $btn.prop('disabled', false).html(originalText);
                        if (response.success) {
                            var $notice = $('<div class="notice notice-success is-dismissible" style="margin: 10px 0;"><p><?php echo esc_js( __( 'Email settings saved successfully!', 'opti-behavior' ) ); ?></p></div>');
                            $('#email-settings-form').prepend($notice);
                            setTimeout(function() { $notice.fadeOut(); }, 3000);
                        } else {
                            alert(response.data || '<?php echo esc_js( __( 'Error saving settings', 'opti-behavior' ) ); ?>');
                        }
                    });
                });

                // Test email button
                $('#opti-behavior-test-email-btn').on('click', function() {
                    var email = prompt('<?php echo esc_js( __( 'Enter email address to send test:', 'opti-behavior' ) ); ?>', '<?php echo esc_js( get_option( 'admin_email' ) ); ?>');
                    if (!email) return;

                    var $btn = $(this);
                    var $result = $('#opti-behavior-test-email-result');
                    $btn.prop('disabled', true);
                    $result.html('<span style="color: #71717a;"><?php echo esc_js( __( 'Sending...', 'opti-behavior' ) ); ?></span>');

                    $.post(ajaxurl, {
                        action: 'optibehavior_test_email_connection',
                        email: email,
                        nonce: '<?php echo esc_js( wp_create_nonce( 'opti_behavior_schedule_action' ) ); ?>'
                    }, function(response) {
                        $btn.prop('disabled', false);
                        if (response.success) {
                            $result.html('<span style="color: #16a34a;">✓ <?php echo esc_js( __( 'Test email sent successfully!', 'opti-behavior' ) ); ?></span>');
                        } else {
                            $result.html('<span style="color: #dc2626;">✗ ' + (response.data || '<?php echo esc_js( __( 'Failed to send test email', 'opti-behavior' ) ); ?>') + '</span>');
                        }
                        setTimeout(function() { $result.fadeOut(500, function() { $(this).html('').show(); }); }, 5000);
                    }).fail(function() {
                        $btn.prop('disabled', false);
                        $result.html('<span style="color: #dc2626;">✗ <?php echo esc_js( __( 'Server error', 'opti-behavior' ) ); ?></span>');
                    });
                });
            });
            </script>
            <?php
        }


        /**
         * Render Smart Insights notification settings.
         *
         * @since 1.3.5
         */
        private function render_smart_insights_notifications_tab() {
            $settings   = $this->get_smart_insights_notification_settings_impl();
            $user_state = $this->get_smart_insights_notification_user_state_impl();
            $scheduler_settings = class_exists( 'Opti_Behavior_Smart_Insights_Scheduler' ) ? Opti_Behavior_Smart_Insights_Scheduler::get_settings() : array();
            $scheduler_state    = class_exists( 'Opti_Behavior_Smart_Insights_Scheduler' ) ? Opti_Behavior_Smart_Insights_Scheduler::get_state() : array();
            $next_kickoff       = class_exists( 'Opti_Behavior_Smart_Insights_Scheduler' ) ? Opti_Behavior_Smart_Insights_Scheduler::get_next_kickoff_timestamp() : false;
            $tooltips = function_exists( 'opti_behavior_get_smart_insights_tooltips' ) ? opti_behavior_get_smart_insights_tooltips() : array();
            $has_pro    = false;

            if ( class_exists( 'Opti_Behavior_Smart_Insights_Capabilities' ) ) {
                $capabilities = new Opti_Behavior_Smart_Insights_Capabilities();
                if ( is_callable( array( $capabilities, 'has_pro_access' ) ) ) {
                    $has_pro = (bool) $capabilities->has_pro_access();
                }
            } else {
                $has_pro = (bool) apply_filters( 'opti_behavior_smart_insights_has_pro_access', false, 'smart_insights_notifications' );
            }

            $state_label       = $this->get_smart_insights_notification_state_label_impl( $user_state['state'] );
            $is_compact_state  = ! empty( $user_state['is_compact'] );
            $compact_reason    = isset( $user_state['compact_reason'] ) ? $user_state['compact_reason'] : '';
            $compact_reason_labels = array(
                'site_default'    => __( 'Using the site default compact side point.', 'opti-behavior' ),
                'user_hidden'     => __( 'You hid the launcher to the side point; it remains restorable from every admin page.', 'opti-behavior' ),
                'user_minimized'  => __( 'You moved the launcher to compact side mode.', 'opti-behavior' ),
            );
            $state_description = $is_compact_state && isset( $compact_reason_labels[ $compact_reason ] )
                ? $compact_reason_labels[ $compact_reason ]
                : __( 'The full launcher is available for your account.', 'opti-behavior' );
            ?>
            <div class="ob-smart-insights-settings opti-behavior-settings-form">
                <?php if ( get_transient( 'opti_behavior_si_notifications_saved_' . get_current_user_id() ) ) : ?>
                    <?php delete_transient( 'opti_behavior_si_notifications_saved_' . get_current_user_id() ); ?>
                    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <span aria-hidden="true" style="font-size:16px;">✓</span>
                        <strong><?php esc_html_e( 'Smart Insights notification settings saved.', 'opti-behavior' ); ?></strong>
                    </div>
                <?php endif; ?>

                <div class="settings-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <span class="section-icon"><i data-lucide="lightbulb"></i></span>
                            <?php esc_html_e( 'Smart Insights Settings', 'opti-behavior' ); ?>
                            <?php if ( ! empty( $tooltips['center_heading'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) : ?>
                                <?php opti_behavior_tooltip_e( $tooltips['center_heading']['title'], $tooltips['center_heading']['content'], $tooltips['center_heading']['simple'], '', array( 'position' => 'right' ) ); ?>
                            <?php endif; ?>
                        </h2>
                        <p class="section-description">
                            <?php esc_html_e( 'Configure how Smart Insights recommendations appear across WordPress admin, including the restorable compact side point.', 'opti-behavior' ); ?>
                        </p>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;">
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;background:<?php echo $settings['enabled'] ? '#ecfdf5' : '#f8fafc'; ?>;border:1px solid <?php echo $settings['enabled'] ? '#86efac' : '#cbd5e1'; ?>;color:<?php echo $settings['enabled'] ? '#166534' : '#475569'; ?>;font-size:12px;font-weight:700;">
                                <?php
								echo esc_html(
									sprintf(
										/* translators: %s: global notifications enabled or disabled state. */
										__( 'Global notifications: %s', 'opti-behavior' ),
										$settings['enabled'] ? __( 'Enabled', 'opti-behavior' ) : __( 'Disabled', 'opti-behavior' )
									)
								);
								?>
                            </span>
                            <?php if ( ! empty( $scheduler_settings ) ) : ?>
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;background:<?php echo ! empty( $scheduler_settings['enabled'] ) ? '#ecfdf5' : '#f8fafc'; ?>;border:1px solid <?php echo ! empty( $scheduler_settings['enabled'] ) ? '#86efac' : '#cbd5e1'; ?>;color:<?php echo ! empty( $scheduler_settings['enabled'] ) ? '#166534' : '#475569'; ?>;font-size:12px;font-weight:700;">
                                <?php
								echo esc_html(
									sprintf(
										/* translators: %s: automatic generation enabled or disabled state. */
										__( 'Automatic generation: %s', 'opti-behavior' ),
										! empty( $scheduler_settings['enabled'] ) ? __( 'Enabled', 'opti-behavior' ) : __( 'Disabled', 'opti-behavior' )
									)
								);
								?>
                            </span>
                            <?php endif; ?>
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:999px;background:<?php echo $has_pro ? '#eef2ff' : '#f8fafc'; ?>;border:1px solid <?php echo $has_pro ? '#a5b4fc' : '#cbd5e1'; ?>;color:<?php echo $has_pro ? '#3730a3' : '#475569'; ?>;font-size:12px;font-weight:700;">
                                <?php
								echo esc_html(
									sprintf(
										/* translators: %s: Pro insights access state. */
										__( 'Pro insights: %s', 'opti-behavior' ),
										$has_pro ? __( 'Active', 'opti-behavior' ) : __( 'Locked', 'opti-behavior' )
									)
								);
								?>
                            </span>
                        </div>
                    </div>

                    <form method="post" action="" class="opti-behavior-category-form">
                        <?php wp_nonce_field( 'opti_behavior_smart_insights_notifications_save', 'opti_behavior_smart_insights_notifications_nonce' ); ?>

                        <?php if ( ! empty( $scheduler_settings ) ) : ?>
                        <div class="settings-category">
                            <h3 class="category-title">
                                <span class="category-icon"><i data-lucide="calendar-clock"></i></span>
                                <?php esc_html_e( 'Automatic generation', 'opti-behavior' ); ?>
                                <?php if ( ! empty( $tooltips['scheduler_settings'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) : ?>
                                    <?php opti_behavior_tooltip_e( $tooltips['scheduler_settings']['title'], $tooltips['scheduler_settings']['content'], $tooltips['scheduler_settings']['simple'], '', array( 'position' => 'right' ) ); ?>
                                <?php endif; ?>
                            </h3>
                            <p class="category-description"><?php esc_html_e( 'Run Smart Insights automatically in small cron batches so low-resource hosts avoid one large generation spike. Manual refresh remains available even when automatic generation is disabled.', 'opti-behavior' ); ?></p>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Enable automatic generation', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['enable_scheduler'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['enable_scheduler']['title'], $tooltips['enable_scheduler']['content'], $tooltips['enable_scheduler']['simple'], '', array( 'position' => 'right' ) ); } ?></th>
                                    <td>
                                        <label class="opti-toggle-switch">
                                            <input type="checkbox" name="opti_si_scheduler[enabled]" value="1" <?php checked( ! empty( $scheduler_settings['enabled'] ) ); ?> />
                                            <span class="opti-toggle-slider"></span>
                                        </label>
                                        <p class="description"><?php esc_html_e( 'Recommended. Keeps stored Smart Insights fresh on a safe background schedule.', 'opti-behavior' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Generation frequency', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['generation_frequency'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['generation_frequency']['title'], $tooltips['generation_frequency']['content'], $tooltips['generation_frequency']['simple'], '', array( 'position' => 'right' ) ); } ?></th>
                                    <td>
                                        <select name="opti_si_scheduler[frequency]">
                                            <option value="daily" <?php selected( $scheduler_settings['frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily — recommended', 'opti-behavior' ); ?></option>
                                            <option value="twicedaily" <?php selected( $scheduler_settings['frequency'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice daily — higher traffic sites', 'opti-behavior' ); ?></option>
                                            <option value="hourly" <?php selected( $scheduler_settings['frequency'], 'hourly' ); ?>><?php esc_html_e( 'Hourly — advanced/high traffic', 'opti-behavior' ); ?></option>
                                            <?php if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || 'every_minute' === $scheduler_settings['frequency'] || apply_filters( 'opti_behavior_smart_insights_show_testing_scheduler_frequency', false ) ) : ?>
                                            <option value="every_minute" <?php selected( $scheduler_settings['frequency'], 'every_minute' ); ?>><?php esc_html_e( 'Every minute — testing only', 'opti-behavior' ); ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <p class="description"><?php esc_html_e( 'Daily runs are scheduled after daily aggregation around 3:45 AM site time with a small site-specific offset.', 'opti-behavior' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Analysis period', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['scheduler_period'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['scheduler_period']['title'], $tooltips['scheduler_period']['content'], $tooltips['scheduler_period']['simple'], '', array( 'position' => 'right' ) ); } ?></th>
                                    <td>
                                        <select name="opti_si_scheduler[period]">
                                            <option value="last7days" <?php selected( $scheduler_settings['period'], 'last7days' ); ?>><?php esc_html_e( 'Last 7 Days', 'opti-behavior' ); ?></option>
                                            <option value="last14days" <?php selected( $scheduler_settings['period'], 'last14days' ); ?>><?php esc_html_e( 'Last 14 Days', 'opti-behavior' ); ?></option>
                                            <option value="last30days" <?php selected( $scheduler_settings['period'], 'last30days' ); ?>><?php esc_html_e( 'Last 30 Days', 'opti-behavior' ); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e( 'Automatic generation uses this rolling period. Manual refresh can still use the Smart Insights center controls.', 'opti-behavior' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Processing profile', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['processing_profile'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['processing_profile']['title'], $tooltips['processing_profile']['content'], $tooltips['processing_profile']['simple'], '', array( 'position' => 'right' ) ); } ?></th>
                                    <td>
                                        <select name="opti_si_scheduler[processing_profile]">
                                            <option value="gentle" <?php selected( $scheduler_settings['processing_profile'], 'gentle' ); ?>><?php esc_html_e( 'Gentle — one work item, 5-minute spacing', 'opti-behavior' ); ?></option>
                                            <option value="balanced" <?php selected( $scheduler_settings['processing_profile'], 'balanced' ); ?>><?php esc_html_e( 'Balanced — up to two work items, 2-minute spacing', 'opti-behavior' ); ?></option>
                                            <option value="testing" <?php selected( $scheduler_settings['processing_profile'], 'testing' ); ?>><?php esc_html_e( 'Testing — one small work item, 60-second spacing', 'opti-behavior' ); ?></option>
                                        </select>
                                        <p class="description"><?php
										echo esc_html(
											sprintf(
												/* translators: %1$d: candidate rows, %2$d: work items per tick, %3$d: seconds between ticks. */
												__( 'Current profile: %1$d candidate rows, %2$d work item(s) per tick, %3$d seconds between ticks.', 'opti-behavior' ),
												(int) $scheduler_settings['candidate_limit'],
												(int) $scheduler_settings['work_items_per_tick'],
												(int) $scheduler_settings['min_spacing_seconds']
											)
										);
										?></p>
                                    </td>
                                </tr>
                            </table>

                            <div style="margin-top:16px;padding:14px 16px;border:1px solid #dbe4ff;border-radius:12px;background:#f8fbff;">
                                <strong><?php esc_html_e( 'Scheduler status', 'opti-behavior' ); ?></strong>
                                <?php if ( ! empty( $tooltips['scheduler_status'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) : ?>
                                    <?php opti_behavior_tooltip_e( $tooltips['scheduler_status']['title'], $tooltips['scheduler_status']['content'], $tooltips['scheduler_status']['simple'], '', array( 'position' => 'bottom' ) ); ?>
                                <?php endif; ?>
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-top:10px;">
                                    <p style="margin:0;"><span class="description"><?php esc_html_e( 'Next kickoff', 'opti-behavior' ); ?></span><br><strong><?php echo $next_kickoff ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_kickoff ) ) : esc_html__( 'Not scheduled', 'opti-behavior' ); ?></strong></p>
                                    <p style="margin:0;"><span class="description"><?php esc_html_e( 'Cycle status', 'opti-behavior' ); ?></span><br><strong><?php echo esc_html( ! empty( $scheduler_state['status'] ) ? ucfirst( $scheduler_state['status'] ) : __( 'Idle', 'opti-behavior' ) ); ?></strong></p>
                                    <p style="margin:0;"><span class="description"><?php esc_html_e( 'Queue progress', 'opti-behavior' ); ?></span><br><strong><?php echo esc_html( sprintf( '%1$d / %2$d', (int) ( $scheduler_state['processed_items'] ?? 0 ), (int) ( $scheduler_state['total_work_items'] ?? 0 ) ) ); ?></strong></p>
                                    <p style="margin:0;"><span class="description"><?php esc_html_e( 'Last completed', 'opti-behavior' ); ?></span><br><strong><?php echo ! empty( $scheduler_state['completed_at'] ) ? esc_html( $scheduler_state['completed_at'] ) : esc_html__( 'Never', 'opti-behavior' ); ?></strong></p>
                                </div>
                                <?php if ( ! empty( $scheduler_state['last_error'] ) ) : ?>
                                    <p style="margin:10px 0 0;color:#b91c1c;"><strong><?php esc_html_e( 'Last error:', 'opti-behavior' ); ?></strong> <?php echo esc_html( $scheduler_state['last_error'] ); ?></p>
                                <?php endif; ?>
                                <p style="margin:10px 0 0;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=opti-behavior-smart-insights' ) ); ?>"><?php esc_html_e( 'Open Smart Insights center', 'opti-behavior' ); ?></a></p>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="settings-category">
                            <h3 class="category-title">
                                <span class="category-icon"><i data-lucide="bell-ring"></i></span>
                                <?php esc_html_e( 'Global notification launcher', 'opti-behavior' ); ?>
                                <?php if ( ! empty( $tooltips['notification_settings'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) : ?>
                                    <?php opti_behavior_tooltip_e( $tooltips['notification_settings']['title'], $tooltips['notification_settings']['content'], $tooltips['notification_settings']['simple'], '', array( 'position' => 'right' ) ); ?>
                                <?php endif; ?>
                            </h3>
                            <p class="category-description"><?php esc_html_e( 'Show a lightweight analyst launcher when important behavior signals are detected. The compact side point stays clickable so administrators are never stranded without a restore control.', 'opti-behavior' ); ?></p>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Enable Smart Insights notifications', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['enable_notifications'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['enable_notifications']['title'], $tooltips['enable_notifications']['content'], $tooltips['enable_notifications']['simple'], '', array( 'position' => 'right' ) ); } ?></th>
                                    <td>
                                        <label class="opti-toggle-switch">
                                            <input type="checkbox" name="opti_si_notifications[enabled]" value="1" <?php checked( $settings['enabled'] ); ?> />
                                            <span class="opti-toggle-slider"></span>
                                        </label>
                                        <p class="description"><?php esc_html_e( 'Show Smart Insights notifications across WordPress admin pages when new behavior signals are detected. Disabling this removes the global launcher and compact restore point for all eligible administrators.', 'opti-behavior' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Default launcher behavior', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['launcher_behavior'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['launcher_behavior']['title'], $tooltips['launcher_behavior']['content'], $tooltips['launcher_behavior']['simple'], '', array( 'position' => 'right' ) ); } ?></th>
                                    <td>
                                        <fieldset style="display:grid;gap:10px;max-width:760px;">
                                            <label style="display:block;padding:12px 14px;border:1px solid #d8dbe6;border-radius:12px;background:#fff;">
                                                <input type="radio" name="opti_si_notifications[launcher_behavior]" value="show_badge" <?php checked( $settings['launcher_behavior'], 'show_badge' ); ?> />
                                                <strong><?php esc_html_e( 'Show launcher with unread badge', 'opti-behavior' ); ?></strong>
                                                <span class="description" style="display:block;margin:4px 0 0 24px;"><?php esc_html_e( 'Recommended for administrators who want a persistent signal surface without auto-opening panels.', 'opti-behavior' ); ?></span>
                                            </label>
                                            <label style="display:block;padding:12px 14px;border:1px solid #d8dbe6;border-radius:12px;background:#fff;">
                                                <input type="radio" name="opti_si_notifications[launcher_behavior]" value="compact_side" <?php checked( in_array( $settings['launcher_behavior'], array( 'compact_side', 'minimized' ), true ) ); ?> />
                                                <strong><?php esc_html_e( 'Compact side mode by default', 'opti-behavior' ); ?></strong>
                                                <span class="description" style="display:block;margin:4px 0 0 24px;"><?php esc_html_e( 'Start administrators with a small side point and red unread badge. Clicking the point restores the full launcher, so this replaces the old minimized behavior without hiding notifications completely.', 'opti-behavior' ); ?></span>
                                            </label>
                                            <label style="display:block;padding:12px 14px;border:1px solid #d8dbe6;border-radius:12px;background:#fff;">
                                                <input type="radio" name="opti_si_notifications[launcher_behavior]" value="opti_pages_only" <?php checked( $settings['launcher_behavior'], 'opti_pages_only' ); ?> />
                                                <strong><?php esc_html_e( 'Only show on Opti-Behavior pages', 'opti-behavior' ); ?></strong>
                                                <span class="description" style="display:block;margin:4px 0 0 24px;"><?php esc_html_e( 'Use this for conservative admin layouts or heavily customized WordPress dashboards.', 'opti-behavior' ); ?></span>
                                            </label>
                                        </fieldset>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Notification priority threshold', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['priority_threshold'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['priority_threshold']['title'], $tooltips['priority_threshold']['content'], $tooltips['priority_threshold']['simple'], '', array( 'position' => 'right' ) ); } ?></th>
                                    <td>
                                        <select name="opti_si_notifications[priority_threshold]">
                                            <option value="all" <?php selected( $settings['priority_threshold'], 'all' ); ?>><?php esc_html_e( 'All actionable insights', 'opti-behavior' ); ?></option>
                                            <option value="medium" <?php selected( $settings['priority_threshold'], 'medium' ); ?>><?php esc_html_e( 'Medium and above', 'opti-behavior' ); ?></option>
                                            <option value="high" <?php selected( $settings['priority_threshold'], 'high' ); ?>><?php esc_html_e( 'High and critical only', 'opti-behavior' ); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e( 'Busy sites should use high/critical only to keep the global badge focused.', 'opti-behavior' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Notification period', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['notification_period'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['notification_period']['title'], $tooltips['notification_period']['content'], $tooltips['notification_period']['simple'], '', array( 'position' => 'right' ) ); } ?></th>
                                    <td>
                                        <select name="opti_si_notifications[period]">
                                            <option value="last7days" <?php selected( $settings['period'], 'last7days' ); ?>><?php esc_html_e( 'Last 7 Days', 'opti-behavior' ); ?></option>
                                            <option value="last14days" <?php selected( $settings['period'], 'last14days' ); ?>><?php esc_html_e( 'Last 14 Days', 'opti-behavior' ); ?></option>
                                            <option value="last30days" <?php selected( $settings['period'], 'last30days' ); ?>><?php esc_html_e( 'Last 30 Days', 'opti-behavior' ); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e( 'Custom investigation dates remain available in the Smart Insights center.', 'opti-behavior' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Locked Pro previews', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['locked_previews'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['locked_previews']['title'], $tooltips['locked_previews']['content'], $tooltips['locked_previews']['simple'], '', array( 'position' => 'right' ) ); } ?></th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="opti_si_notifications[include_locked_previews]" value="1" <?php checked( $settings['include_locked_previews'] ); ?> />
                                            <?php esc_html_e( 'Include one grouped locked-preview prompt in the global panel.', 'opti-behavior' ); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e( 'Default off. Locked previews remain available inside the Smart Insights center and do not count toward the unread badge.', 'opti-behavior' ); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="settings-category">
                            <h3 class="category-title">
                                <span class="category-icon"><i data-lucide="user-cog"></i></span>
                                <?php esc_html_e( 'Personal launcher state', 'opti-behavior' ); ?>
                                <?php if ( ! empty( $tooltips['personal_state'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) : ?>
                                    <?php opti_behavior_tooltip_e( $tooltips['personal_state']['title'], $tooltips['personal_state']['content'], $tooltips['personal_state']['simple'], '', array( 'position' => 'right' ) ); ?>
                                <?php endif; ?>
                            </h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'My launcher state', 'opti-behavior' ); ?></th>
                                    <td>
                                        <strong><?php echo esc_html( $state_label ); ?></strong>
                                        <p class="description"><?php echo esc_html( $state_description ); ?></p>
                                        <p class="description"><?php esc_html_e( 'This controls only your notification launcher visibility and unread marker, not Smart Insight lifecycle statuses.', 'opti-behavior' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Recovery controls', 'opti-behavior' ); ?> <?php if ( ! empty( $tooltips['recovery_controls'] ) && function_exists( 'opti_behavior_tooltip_e' ) ) { opti_behavior_tooltip_e( $tooltips['recovery_controls']['title'], $tooltips['recovery_controls']['content'], $tooltips['recovery_controls']['simple'], '', array( 'position' => 'right' ) ); } ?></th>
                                    <td style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                                        <button type="submit" name="opti_behavior_smart_insights_show_again" class="button">
                                            <?php esc_html_e( 'Restore full launcher for me', 'opti-behavior' ); ?>
                                        </button>
                                        <button type="submit" name="opti_behavior_smart_insights_compact_side" class="button">
                                            <?php esc_html_e( 'Move mine to side point', 'opti-behavior' ); ?>
                                        </button>
                                        <button type="submit" name="opti_behavior_smart_insights_reset_seen" class="button">
                                            <?php esc_html_e( 'Clear unread badge for me', 'opti-behavior' ); ?>
                                        </button>
                                        <p class="description" style="flex-basis:100%;margin:0;"><?php esc_html_e( 'Use these controls to recover from compact/hidden side mode or to intentionally place your personal launcher back on the side point.', 'opti-behavior' ); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <p class="submit">
                            <button type="submit" name="opti_behavior_smart_insights_notifications_submit" class="button button-primary">
                                <i data-lucide="save" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i>
                                <?php esc_html_e( 'Save Settings', 'opti-behavior' ); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>
            <?php
        }

        /**
         * Handle Smart Insights notification settings save.
         *
         * @since 1.3.5
         */
        private function handle_smart_insights_notifications_save_impl() {
            if ( ! isset( $_POST['opti_behavior_smart_insights_notifications_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_smart_insights_notifications_nonce'] ) ), 'opti_behavior_smart_insights_notifications_save' ) ) {
                wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) );
            }
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
            }

            if ( isset( $_POST['opti_behavior_smart_insights_show_again'] ) ) {
                delete_user_meta( get_current_user_id(), 'opti_behavior_si_notification_hidden' );
                delete_user_meta( get_current_user_id(), 'opti_behavior_si_notification_minimized' );
                set_transient( 'opti_behavior_si_notifications_saved_' . get_current_user_id(), true, 30 );
                return;
            }

            if ( isset( $_POST['opti_behavior_smart_insights_compact_side'] ) ) {
                update_user_meta( get_current_user_id(), 'opti_behavior_si_notification_minimized', 1 );
                delete_user_meta( get_current_user_id(), 'opti_behavior_si_notification_hidden' );
                set_transient( 'opti_behavior_si_notifications_saved_' . get_current_user_id(), true, 30 );
                return;
            }

            if ( isset( $_POST['opti_behavior_smart_insights_reset_seen'] ) ) {
                update_user_meta( get_current_user_id(), 'opti_behavior_si_notifications_last_seen', time() );
                delete_user_meta( get_current_user_id(), 'opti_behavior_si_notification_minimized' );
                set_transient( 'opti_behavior_si_notifications_saved_' . get_current_user_id(), true, 30 );
                return;
            }

            $input = isset( $_POST['opti_si_notifications'] ) && is_array( $_POST['opti_si_notifications'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['opti_si_notifications'] ) ) : array();

            $allowed_behaviors  = array( 'show_badge', 'minimized', 'compact_side', 'opti_pages_only' );
            $allowed_thresholds = array( 'all', 'medium', 'high' );
            $allowed_periods    = array( 'last7days', 'last14days', 'last30days' );

            $settings = array(
                'enabled'                 => ! empty( $input['enabled'] ),
                'launcher_behavior'       => in_array( ( $input['launcher_behavior'] ?? 'show_badge' ), $allowed_behaviors, true ) ? $input['launcher_behavior'] : 'show_badge',
                'priority_threshold'      => in_array( ( $input['priority_threshold'] ?? 'high' ), $allowed_thresholds, true ) ? $input['priority_threshold'] : 'high',
                'period'                  => in_array( ( $input['period'] ?? 'last7days' ), $allowed_periods, true ) ? $input['period'] : 'last7days',
                'include_locked_previews' => ! empty( $input['include_locked_previews'] ),
            );

            update_option( 'opti_behavior_smart_insights_notifications', $settings );

            if ( class_exists( 'Opti_Behavior_Smart_Insights_Scheduler' ) ) {
                $scheduler_input = isset( $_POST['opti_si_scheduler'] ) && is_array( $_POST['opti_si_scheduler'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['opti_si_scheduler'] ) ) : array();
                $profile_defaults = array(
                    'gentle'   => array(
                        'candidate_limit'     => 25,
                        'work_items_per_tick' => 1,
                        'min_spacing_seconds' => 300,
                    ),
                    'balanced' => array(
                        'candidate_limit'     => 50,
                        'work_items_per_tick' => 2,
                        'min_spacing_seconds' => 120,
                    ),
                    'testing'  => array(
                        'candidate_limit'     => 10,
                        'work_items_per_tick' => 1,
                        'min_spacing_seconds' => 60,
                    ),
                );
                $profile = isset( $scheduler_input['processing_profile'] ) ? sanitize_key( $scheduler_input['processing_profile'] ) : 'gentle';
                if ( ! isset( $profile_defaults[ $profile ] ) ) {
                    $profile = 'gentle';
                }
                $scheduler_settings = array(
                    'enabled'             => ! empty( $scheduler_input['enabled'] ),
                    'frequency'           => isset( $scheduler_input['frequency'] ) ? sanitize_key( $scheduler_input['frequency'] ) : 'daily',
                    'period'              => isset( $scheduler_input['period'] ) ? sanitize_key( $scheduler_input['period'] ) : 'last7days',
                    'processing_profile'  => $profile,
                    'candidate_limit'     => $profile_defaults[ $profile ]['candidate_limit'],
                    'work_items_per_tick' => $profile_defaults[ $profile ]['work_items_per_tick'],
                    'min_spacing_seconds' => $profile_defaults[ $profile ]['min_spacing_seconds'],
                    'auto_resolve'        => true,
                );
                Opti_Behavior_Smart_Insights_Scheduler::save_settings( $scheduler_settings );
            }
            set_transient( 'opti_behavior_si_notifications_saved_' . get_current_user_id(), true, 30 );
        }

        /**
         * Render the Frontend Stats Bar settings tab.
         *
         * @since 1.1.0
         */
        private function render_frontend_stats_bar_tab() {
            $tooltips = opti_behavior_get_settings_tooltips();
            $defaults = array(
                'enabled'           => true,
                'hide_in_builders'  => true,
                'period'            => 'last30days',
                'color_theme'       => 'dark',
                'show_visitors'     => true,
                'show_sessions'     => true,
                'show_pageviews'    => true,
                'show_avg_time'     => true,
                'show_scroll_depth' => true,
                'show_bounce_rate'  => true,
                'show_recordings'   => true,
                'show_errors'       => true,
                'show_friction'     => true,
                'show_forms'        => true,
            );
            $settings = get_option( 'opti_behavior_frontend_stats_bar', array() );
            if ( ! is_array( $settings ) ) {
                $settings = array();
            }
            $settings = wp_parse_args( $settings, $defaults );

            $opti_behavior_is_pro = function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active();

            $color_themes = array(
                'dark'   => array( 'label' => __( 'Dark', 'opti-behavior' ),   'bg' => '#1e1e2e', 'text' => '#ffffff' ),
                'light'  => array( 'label' => __( 'Light', 'opti-behavior' ),  'bg' => '#ffffff', 'text' => '#1e1e2e' ),
                'blue'   => array( 'label' => __( 'Blue', 'opti-behavior' ),   'bg' => '#1e3a5f', 'text' => '#e0f0ff' ),
                'green'  => array( 'label' => __( 'Green', 'opti-behavior' ),  'bg' => '#1a3c2a', 'text' => '#d4edda' ),
                'purple' => array( 'label' => __( 'Purple', 'opti-behavior' ), 'bg' => '#2d1b4e', 'text' => '#e8daff' ),
                'orange' => array( 'label' => __( 'Orange', 'opti-behavior' ), 'bg' => '#4a2800', 'text' => '#ffe8cc' ),
            );
            ?>
            <div class="opti-behavior-settings-form">
                <?php if ( get_transient( 'opti_behavior_fsb_saved' ) ) : ?>
                    <?php delete_transient( 'opti_behavior_fsb_saved' ); ?>
                    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:8px;">
                        <span style="font-size:16px;">✅</span>
                        <strong><?php esc_html_e( 'Frontend Stats Bar settings saved successfully.', 'opti-behavior' ); ?></strong>
                    </div>
                <?php endif; ?>
                <div class="settings-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <span class="section-icon"><i data-lucide="monitor"></i></span>
                            <?php esc_html_e( 'Frontend Stats Bar', 'opti-behavior' ); ?>
                        </h2>
                        <p class="section-description">
                            <?php esc_html_e( 'Display a compact analytics bar at the top of every frontend page. Visible only to administrators. Stats load asynchronously — zero impact on page speed.', 'opti-behavior' ); ?>
                        </p>
                    </div>

                    <form method="post" action="" class="opti-behavior-category-form">
                        <?php wp_nonce_field( 'opti_behavior_frontend_stats_bar_save', 'opti_behavior_frontend_stats_bar_nonce' ); ?>

                        <!-- Enable / Disable -->
                        <div class="settings-category">
                            <h3 class="category-title">
                                <span class="category-icon"><i data-lucide="toggle-left"></i></span>
                                <?php esc_html_e( 'General', 'opti-behavior' ); ?>
                            </h3>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Enable Stats Bar', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['enable_stats_bar']['title'], $tooltips['enable_stats_bar']['content'], $tooltips['enable_stats_bar']['simple'], '', array( 'position' => 'right' ) ); ?></th>
                                    <td>
                                        <label class="opti-toggle-switch">
                                            <input type="checkbox" name="opti_fsb[enabled]" value="1" <?php checked( $settings['enabled'] ); ?> />
                                            <span class="opti-toggle-slider"></span>
                                        </label>
                                        <p class="description"><?php esc_html_e( 'Show the analytics bar on all frontend pages for administrators.', 'opti-behavior' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Hide in Page Builders', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['hide_in_builders']['title'], $tooltips['hide_in_builders']['content'], $tooltips['hide_in_builders']['simple'], '', array( 'position' => 'right' ) ); ?></th>
                                    <td>
                                        <label class="opti-toggle-switch">
                                            <input type="checkbox" name="opti_fsb[hide_in_builders]" value="1" <?php checked( $settings['hide_in_builders'] ); ?> />
                                            <span class="opti-toggle-slider"></span>
                                        </label>
                                        <p class="description"><?php esc_html_e( 'Automatically hide the stats bar inside visual builder editors (Elementor, Divi, Beaver Builder, WPBakery, Brizy, Oxygen, Breakdance, SiteOrigin, Visual Composer, Themify, and more) so it never blocks the editing interface.', 'opti-behavior' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Stats Period', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['stats_period']['title'], $tooltips['stats_period']['content'], $tooltips['stats_period']['simple'], '', array( 'position' => 'right' ) ); ?></th>
                                    <td>
                                        <select name="opti_fsb[period]">
                                            <option value="today" <?php selected( $settings['period'], 'today' ); ?>><?php esc_html_e( 'Today', 'opti-behavior' ); ?></option>
                                            <option value="last7days" <?php selected( $settings['period'], 'last7days' ); ?>><?php esc_html_e( 'Last 7 Days', 'opti-behavior' ); ?></option>
                                            <option value="last30days" <?php selected( $settings['period'], 'last30days' ); ?>><?php esc_html_e( 'Last 30 Days', 'opti-behavior' ); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e( 'Time range for the statistics displayed in the bar.', 'opti-behavior' ); ?></p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <!-- Color Theme -->
                        <div class="settings-category">
                            <h3 class="category-title">
                                <span class="category-icon"><i data-lucide="palette"></i></span>
                                <?php esc_html_e( 'Color Theme', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['color_theme']['title'], $tooltips['color_theme']['content'], $tooltips['color_theme']['simple'], '', array( 'position' => 'right' ) ); ?>
                            </h3>
                            <p class="category-description"><?php esc_html_e( 'Choose a color theme that matches your website design.', 'opti-behavior' ); ?></p>
                            <div class="opti-fsb-theme-picker" style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0;">
                                <?php foreach ( $color_themes as $opti_behavior_theme_key => $opti_behavior_theme_data ) : ?>
                                <label class="opti-fsb-theme-swatch" style="cursor:pointer;text-align:center;">
                                    <input type="radio" name="opti_fsb[color_theme]" value="<?php echo esc_attr( $opti_behavior_theme_key ); ?>" <?php checked( $settings['color_theme'], $opti_behavior_theme_key ); ?> style="display:none;" />
                                    <span style="display:block;width:80px;height:40px;border-radius:8px;background:<?php echo esc_attr( $opti_behavior_theme_data['bg'] ); ?>;border:3px solid <?php echo $settings['color_theme'] === $opti_behavior_theme_key ? '#3b82f6' : '#d1d5db'; ?>;color:<?php echo esc_attr( $opti_behavior_theme_data['text'] ); ?>;line-height:40px;font-size:11px;font-weight:600;transition:border-color .2s;"><?php echo esc_html( $opti_behavior_theme_data['label'] ); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <!-- Live Preview -->
                            <div class="opti-fsb-preview" style="margin:16px 0 !important;padding:10px 20px !important;border-radius:8px;display:flex !important;visibility:visible !important;opacity:1 !important;height:auto !important;overflow:visible !important;align-items:center;gap:20px;font-size:13px;background:<?php echo esc_attr( $color_themes[ $settings['color_theme'] ]['bg'] ); ?>;color:<?php echo esc_attr( $color_themes[ $settings['color_theme'] ]['text'] ); ?>;transition:all .3s;">
                                <strong style="opacity:.7;">📊 Opti-Behavior</strong>
                                <span>👥 142 <?php esc_html_e( 'Visitors', 'opti-behavior' ); ?></span>
                                <span>📄 1,283 <?php esc_html_e( 'Views', 'opti-behavior' ); ?></span>
                                <span>⏱ 2m 34s</span>
                                <span>📉 32%</span>
                            </div>
                        </div>

                        <!-- Stat Toggles -->
                        <div class="settings-category">
                            <h3 class="category-title">
                                <span class="category-icon"><i data-lucide="sliders-horizontal"></i></span>
                                <?php esc_html_e( 'Visible Stats', 'opti-behavior' ); ?><?php opti_behavior_tooltip_e( $tooltips['visible_stats']['title'], $tooltips['visible_stats']['content'], $tooltips['visible_stats']['simple'], '', array( 'position' => 'right' ) ); ?>
                            </h3>
                            <p class="category-description"><?php esc_html_e( 'Select which statistics to display in the bar.', 'opti-behavior' ); ?></p>

                            <h4 style="margin:20px 0 10px;padding:10px 0;border-bottom:1px solid #e5e7eb;color:#1d2327;">
                                <?php esc_html_e( 'Free Stats', 'opti-behavior' ); ?>
                            </h4>
                            <table class="form-table" style="margin-top:0;">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Visitors', 'opti-behavior' ); ?></th>
                                    <td><label><input type="checkbox" name="opti_fsb[show_visitors]" value="1" <?php checked( $settings['show_visitors'] ); ?> /> <?php esc_html_e( 'Show unique visitor count', 'opti-behavior' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Sessions', 'opti-behavior' ); ?></th>
                                    <td><label><input type="checkbox" name="opti_fsb[show_sessions]" value="1" <?php checked( $settings['show_sessions'] ); ?> /> <?php esc_html_e( 'Show total session count', 'opti-behavior' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Page Views', 'opti-behavior' ); ?></th>
                                    <td><label><input type="checkbox" name="opti_fsb[show_pageviews]" value="1" <?php checked( $settings['show_pageviews'] ); ?> /> <?php esc_html_e( 'Show total page views', 'opti-behavior' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Avg. Session Time', 'opti-behavior' ); ?></th>
                                    <td><label><input type="checkbox" name="opti_fsb[show_avg_time]" value="1" <?php checked( $settings['show_avg_time'] ); ?> /> <?php esc_html_e( 'Show average session duration', 'opti-behavior' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Scroll Depth', 'opti-behavior' ); ?></th>
                                    <td><label><input type="checkbox" name="opti_fsb[show_scroll_depth]" value="1" <?php checked( $settings['show_scroll_depth'] ); ?> /> <?php esc_html_e( 'Show average scroll depth', 'opti-behavior' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Bounce Rate', 'opti-behavior' ); ?></th>
                                    <td><label><input type="checkbox" name="opti_fsb[show_bounce_rate]" value="1" <?php checked( $settings['show_bounce_rate'] ); ?> /> <?php esc_html_e( 'Show bounce rate percentage', 'opti-behavior' ); ?></label></td>
                                </tr>
                            </table>

                            <h4 style="margin:30px 0 10px;padding:10px 0;border-bottom:1px solid #e5e7eb;color:#1d2327;">
                                <?php esc_html_e( 'Pro Stats', 'opti-behavior' ); ?>
                                <?php if ( ! $opti_behavior_is_pro ) : ?>
                                    <span style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:4px;background:#f3e8ff;color:#7c3aed;font-size:11px;font-weight:600;"><?php esc_html_e( 'PRO Required', 'opti-behavior' ); ?></span>
                                <?php endif; ?>
                            </h4>
                            <table class="form-table" style="margin-top:0;<?php echo ! $opti_behavior_is_pro ? 'opacity:.5;' : ''; ?>">
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Recordings', 'opti-behavior' ); ?></th>
                                    <td><label><input type="checkbox" name="opti_fsb[show_recordings]" value="1" <?php checked( $settings['show_recordings'] ); ?> <?php disabled( ! $opti_behavior_is_pro ); ?> /> <?php esc_html_e( 'Show active recording count', 'opti-behavior' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'JS Errors', 'opti-behavior' ); ?></th>
                                    <td><label><input type="checkbox" name="opti_fsb[show_errors]" value="1" <?php checked( $settings['show_errors'] ); ?> <?php disabled( ! $opti_behavior_is_pro ); ?> /> <?php esc_html_e( 'Show JavaScript error count', 'opti-behavior' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Friction Events', 'opti-behavior' ); ?></th>
                                    <td><label><input type="checkbox" name="opti_fsb[show_friction]" value="1" <?php checked( $settings['show_friction'] ); ?> <?php disabled( ! $opti_behavior_is_pro ); ?> /> <?php esc_html_e( 'Show rage clicks and dead clicks', 'opti-behavior' ); ?></label></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Form Submissions', 'opti-behavior' ); ?></th>
                                    <td><label><input type="checkbox" name="opti_fsb[show_forms]" value="1" <?php checked( $settings['show_forms'] ); ?> <?php disabled( ! $opti_behavior_is_pro ); ?> /> <?php esc_html_e( 'Show form submission count', 'opti-behavior' ); ?></label></td>
                                </tr>
                            </table>
                        </div>

                        <p class="submit">
                            <button type="submit" name="opti_behavior_frontend_stats_bar_submit" class="button button-primary">
                                <i data-lucide="save" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i>
                                <?php esc_html_e( 'Save Settings', 'opti-behavior' ); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>

            <script>
            (function(){
                var swatches = document.querySelectorAll('.opti-fsb-theme-swatch input[type=radio]');
                var preview = document.querySelector('.opti-fsb-preview');
                var themes = <?php echo wp_json_encode( $color_themes ); ?>;
                swatches.forEach(function(radio){
                    radio.addEventListener('change', function(){
                        var t = themes[this.value];
                        if(!t) return;
                        preview.style.background = t.bg;
                        preview.style.color = t.text;
                        document.querySelectorAll('.opti-fsb-theme-swatch span').forEach(function(s){
                            s.style.borderColor = '#d1d5db';
                        });
                        this.parentElement.querySelector('span').style.borderColor = '#3b82f6';
                    });
                });
            })();
            </script>
            <?php
        }

        /**
         * Handle Frontend Stats Bar settings save.
         *
         * @since 1.1.0
         */
        private function handle_frontend_stats_bar_save_impl() {
            if ( ! isset( $_POST['opti_behavior_frontend_stats_bar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['opti_behavior_frontend_stats_bar_nonce'] ) ), 'opti_behavior_frontend_stats_bar_save' ) ) {
                wp_die( esc_html__( 'Security check failed', 'opti-behavior' ) );
            }
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Insufficient permissions', 'opti-behavior' ) );
            }

            $input = isset( $_POST['opti_fsb'] ) && is_array( $_POST['opti_fsb'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['opti_fsb'] ) ) : array();

            $allowed_periods = array( 'today', 'last7days', 'last30days' );
            $allowed_themes  = array( 'dark', 'light', 'blue', 'green', 'purple', 'orange' );

            $settings = array(
                'enabled'           => ! empty( $input['enabled'] ),
                'hide_in_builders'  => ! empty( $input['hide_in_builders'] ),
                'period'            => in_array( ( $input['period'] ?? 'today' ), $allowed_periods, true ) ? $input['period'] : 'today',
                'color_theme'       => in_array( ( $input['color_theme'] ?? 'dark' ), $allowed_themes, true ) ? $input['color_theme'] : 'dark',
                'show_visitors'     => ! empty( $input['show_visitors'] ),
                'show_sessions'     => ! empty( $input['show_sessions'] ),
                'show_pageviews'    => ! empty( $input['show_pageviews'] ),
                'show_avg_time'     => ! empty( $input['show_avg_time'] ),
                'show_scroll_depth' => ! empty( $input['show_scroll_depth'] ),
                'show_bounce_rate'  => ! empty( $input['show_bounce_rate'] ),
                'show_recordings'   => ! empty( $input['show_recordings'] ),
                'show_errors'       => ! empty( $input['show_errors'] ),
                'show_friction'     => ! empty( $input['show_friction'] ),
                'show_forms'        => ! empty( $input['show_forms'] ),
            );

            update_option( 'opti_behavior_frontend_stats_bar', $settings );

            // Clear cached stats so next load picks up new period
            delete_transient( 'opti_behavior_frontend_stats_today' );
            delete_transient( 'opti_behavior_frontend_stats_last7days' );
            delete_transient( 'opti_behavior_frontend_stats_last30days' );

            // Use transient for inline success message (admin notices are suppressed on plugin pages)
            set_transient( 'opti_behavior_fsb_saved', true, 30 );
        }


    }
}
