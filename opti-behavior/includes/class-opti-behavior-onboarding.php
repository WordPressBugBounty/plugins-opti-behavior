<?php
/**
 * Onboarding Popup
 *
 * Displays a multi-step onboarding popup on the dashboard after first activation
 * when no session data exists yet. Helps users understand that tracking is active
 * and guides them to see their first heatmap.
 *
 * @package opti-behavior
 * @since   1.6.0
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Opti_Behavior_Onboarding
 *
 * Manages the post-activation onboarding popup on the dashboard.
 *
 * @since 1.6.0
 */
class Opti_Behavior_Onboarding {

	/** WordPress option: set to '1' when onboarding is dismissed. */
	const OPTION_DISMISSED = 'opti_behavior_onboarding_dismissed';

	/** WordPress option: stores the user's selected goal from step 2. */
	const OPTION_GOAL = 'opti_behavior_onboarding_goal';

	/** @var self|null Singleton instance. */
	private static $instance = null;

	/**
	 * Initialize the singleton.
	 *
	 * @return self
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use init().
	 */
	private function __construct() {
		add_action( 'wp_ajax_opti_behavior_dismiss_onboarding', array( $this, 'ajax_dismiss' ) );
		// Render popup via admin_footer so it's outside #wpbody-content
		// (avoids admin-notices.css wildcard hide rules).
		add_action( 'admin_footer', array( $this, 'maybe_render_footer' ) );
	}

	/**
	 * Render popup in admin_footer if on the dashboard page and conditions are met.
	 *
	 * @return void
	 */
	public function maybe_render_footer() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only: GET param for page identification.
		$opti_behavior_current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'opti-behavior-analytics' !== $opti_behavior_current_page ) {
			return;
		}

		if ( ! $this->should_show() ) {
			return;
		}

		$this->render();
	}

	/**
	 * Check whether the onboarding popup should be displayed.
	 *
	 * Conditions:
	 * 1. User has accepted the welcome/consent page.
	 * 2. Onboarding has not been dismissed yet.
	 * 3. The site has no recorded sessions (fresh install).
	 *
	 * @return bool
	 */
	public function should_show() {
		// Must have consent.
		if ( ! class_exists( 'Opti_Behavior_Welcome' ) || ! Opti_Behavior_Welcome::has_consent() ) {
			return false;
		}

		// Already dismissed.
		if ( get_option( self::OPTION_DISMISSED, false ) ) {
			return false;
		}

		// Check for existing session data — if data exists, skip onboarding.
		// This also handles upgrades: existing users already have sessions recorded,
		// so the popup won't show for them. Only truly fresh installs see it.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_sessions = $wpdb->get_var( "SELECT EXISTS( SELECT 1 FROM `" . $wpdb->prefix . "optibehavior_sessions` LIMIT 1 )" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return ! (bool) $has_sessions;
	}

	/**
	 * Enqueue popup assets (CSS + JS). Call on the dashboard page only.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$css_path    = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/onboarding-popup.css';
		$js_path     = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/js/onboarding-popup.js';
		$css_version = file_exists( $css_path ) ? (string) filemtime( $css_path ) : OPTI_BEHAVIOR_HEATMAP_VERSION;
		$js_version  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : OPTI_BEHAVIOR_HEATMAP_VERSION;

		wp_enqueue_style(
			'opti-behavior-onboarding',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/onboarding-popup.css',
			array(),
			$css_version
		);

		wp_enqueue_script(
			'opti-behavior-onboarding',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/onboarding-popup.js',
			array(),
			$js_version,
			true
		);

		wp_localize_script(
			'opti-behavior-onboarding',
			'optiBehaviorOnboarding',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'opti_behavior_dismiss_onboarding' ),
				'settingsUrl' => admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=scheduled-reports' ),
				'i18n'        => array(
					'step1of4'         => __( 'Step 1 of 4', 'opti-behavior' ),
					'step2of4'         => __( 'Step 2 of 4', 'opti-behavior' ),
					'step3of4'         => __( 'Step 3 of 4', 'opti-behavior' ),
					'step4of4'         => __( 'Step 4 of 4', 'opti-behavior' ),
					'btnContinue'      => __( 'Continue', 'opti-behavior' ),
					'btnGoDashboard'   => __( 'Go to Dashboard', 'opti-behavior' ),
					'setupComplete'    => __( 'Setup complete', 'opti-behavior' ),
					'setupCompleteDesc' => __( 'Your dashboard is ready. Data will appear as visitors arrive on your site.', 'opti-behavior' ),
				),
			)
		);
	}

	/**
	 * AJAX handler — dismiss the onboarding popup.
	 *
	 * @return void
	 */
	public function ajax_dismiss() {
		check_ajax_referer( 'opti_behavior_dismiss_onboarding', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		update_option( self::OPTION_DISMISSED, '1', false );

		// Store selected goal for analytics (optional).
		if ( ! empty( $_POST['goal'] ) ) {
			$opti_behavior_goal = sanitize_text_field( wp_unslash( $_POST['goal'] ) );
			update_option( self::OPTION_GOAL, $opti_behavior_goal, false );
		}

		wp_send_json_success();
	}

	/**
	 * Render the onboarding popup HTML.
	 *
	 * @return void
	 */
	public function render() {
		?>
		<div class="ob-setup-overlay">
			<div class="ob-setup-modal">

				<!-- Header -->
				<div class="ob-modal-header">
					<div class="ob-modal-header-top">
						<div class="ob-plugin-icon">
							<img src="<?php echo esc_url( plugins_url( 'assets/images/256x256.png', dirname( __FILE__ ) ) ); ?>" alt="<?php esc_attr_e( 'Opti-Behavior', 'opti-behavior' ); ?>" width="36" height="36" />
						</div>
						<div>
							<div class="ob-plugin-name"><?php esc_html_e( 'Opti-Behavior', 'opti-behavior' ); ?></div>
							<div class="ob-plugin-sub"><?php esc_html_e( 'Quick setup — takes 2 minutes', 'opti-behavior' ); ?></div>
						</div>
						<div class="ob-progress-label" id="ob-stepLabel"><?php esc_html_e( 'Step 1 of 4', 'opti-behavior' ); ?></div>
					</div>
					<div class="ob-progress-row">
						<div class="ob-step-dot active" id="ob-dot0"></div>
						<div class="ob-step-dot" id="ob-dot1"></div>
						<div class="ob-step-dot" id="ob-dot2"></div>
						<div class="ob-step-dot" id="ob-dot3"></div>
					</div>
					<div class="ob-progress-bar-wrap">
						<div class="ob-progress-bar-fill" id="ob-progressFill" style="width:25%"></div>
					</div>
				</div>

				<!-- Body -->
				<div class="ob-modal-body">

					<!-- STEP 1: Tracking confirmed -->
					<div class="ob-step-panel active" id="ob-step0">
						<div class="ob-step-title"><?php esc_html_e( 'Tracking is active on your site', 'opti-behavior' ); ?></div>
						<div class="ob-step-desc"><?php esc_html_e( 'Opti-Behavior is now collecting data. Your first visitors will appear automatically — no extra configuration needed.', 'opti-behavior' ); ?></div>

						<div class="ob-status-box">
							<div class="ob-status-dot"></div>
							<div class="ob-status-text"><?php esc_html_e( 'Live — waiting for your first visitor', 'opti-behavior' ); ?></div>
						</div>

						<div class="ob-feature-grid">
							<div class="ob-feature-tile">
								<div class="ob-feature-icon" style="background:rgba(59,130,246,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14l3-3 3 2 4-6"/></svg>
								</div>
								<div class="ob-feature-name"><?php esc_html_e( 'Traffic & Behavior', 'opti-behavior' ); ?></div>
								<div class="ob-feature-info ob-feature-badges"><span class="ob-feature-badge ob-feature-badge-free"><?php esc_html_e( 'FREE', 'opti-behavior' ); ?></span></div>
							</div>
							<div class="ob-feature-tile">
								<div class="ob-feature-icon" style="background:rgba(239,68,68,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
								</div>
								<div class="ob-feature-name"><?php esc_html_e( 'Heatmap', 'opti-behavior' ); ?></div>
								<div class="ob-feature-info ob-feature-badges"><span class="ob-feature-badge ob-feature-badge-free"><?php esc_html_e( 'FREE', 'opti-behavior' ); ?></span><span class="ob-feature-badge ob-feature-badge-pro"><?php esc_html_e( 'PRO', 'opti-behavior' ); ?></span></div>
							</div>
							<div class="ob-feature-tile">
								<div class="ob-feature-icon" style="background:rgba(168,85,247,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#a855f7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>
								</div>
								<div class="ob-feature-name"><?php esc_html_e( 'Funnel', 'opti-behavior' ); ?></div>
								<div class="ob-feature-info ob-feature-badges"><span class="ob-feature-badge ob-feature-badge-free"><?php esc_html_e( 'FREE', 'opti-behavior' ); ?></span></div>
							</div>
							<div class="ob-feature-tile">
								<div class="ob-feature-icon" style="background:rgba(139,92,246,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="14" height="10" rx="2"/><path d="M16 8l5-3v11l-5-3"/></svg>
								</div>
								<div class="ob-feature-name"><?php esc_html_e( 'Session Record', 'opti-behavior' ); ?></div>
								<div class="ob-feature-info ob-feature-badges"><span class="ob-feature-badge ob-feature-badge-pro"><?php esc_html_e( 'PRO', 'opti-behavior' ); ?></span></div>
							</div>
							<div class="ob-feature-tile">
								<div class="ob-feature-icon" style="background:rgba(79,70,229,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h5"/><path d="M4 17h5"/><path d="M15 5h5"/><path d="M15 19h5"/><path d="M9 7c4 0 3 10 6 10"/><path d="M9 17c4 0 3-10 6-10"/></svg>
								</div>
								<div class="ob-feature-name"><?php esc_html_e( 'A/B Test', 'opti-behavior' ); ?></div>
								<div class="ob-feature-info ob-feature-badges"><span class="ob-feature-badge ob-feature-badge-free"><?php esc_html_e( 'FREE', 'opti-behavior' ); ?></span><span class="ob-feature-badge ob-feature-badge-pro"><?php esc_html_e( 'PRO', 'opti-behavior' ); ?></span></div>
							</div>
							<div class="ob-feature-tile">
								<div class="ob-feature-icon" style="background:rgba(13,148,136,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0d9488" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="6" r="3"/><path d="M5 9v6"/><circle cx="5" cy="18" r="3"/><path d="M12 3h7a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-7"/><path d="M12 13h7a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-7"/></svg>
								</div>
								<div class="ob-feature-name"><?php esc_html_e( 'User Journey', 'opti-behavior' ); ?></div>
								<div class="ob-feature-info ob-feature-badges"><span class="ob-feature-badge ob-feature-badge-pro"><?php esc_html_e( 'PRO', 'opti-behavior' ); ?></span></div>
							</div>
							<div class="ob-feature-tile">
								<div class="ob-feature-icon" style="background:rgba(220,38,38,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
								</div>
								<div class="ob-feature-name"><?php esc_html_e( 'Error Tracking', 'opti-behavior' ); ?></div>
								<div class="ob-feature-info ob-feature-badges"><span class="ob-feature-badge ob-feature-badge-pro"><?php esc_html_e( 'PRO', 'opti-behavior' ); ?></span></div>
							</div>
							<div class="ob-feature-tile">
								<div class="ob-feature-icon" style="background:rgba(245,158,11,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="14" height="18" rx="2"/><line x1="6" y1="8" x2="12" y2="8"/><line x1="6" y1="12" x2="10" y2="12"/><line x1="6" y1="16" x2="12" y2="16"/></svg>
								</div>
								<div class="ob-feature-name"><?php esc_html_e( 'Forms', 'opti-behavior' ); ?></div>
								<div class="ob-feature-info ob-feature-badges"><span class="ob-feature-badge ob-feature-badge-pro"><?php esc_html_e( 'PRO', 'opti-behavior' ); ?></span></div>
							</div>
							<div class="ob-feature-tile">
								<div class="ob-feature-icon" style="background:rgba(16,185,129,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
								</div>
								<div class="ob-feature-name"><?php esc_html_e( '100% Data on your server', 'opti-behavior' ); ?></div>
								<div class="ob-feature-info"><?php esc_html_e( 'Private by design', 'opti-behavior' ); ?></div>
							</div>
						</div>
					</div>

					<!-- STEP 2: Choose your goal -->
					<div class="ob-step-panel" id="ob-step1">
						<div class="ob-step-title"><?php esc_html_e( "What's your main goal?", 'opti-behavior' ); ?></div>
						<div class="ob-step-desc"><?php esc_html_e( "We'll highlight the right feature first so you see value faster.", 'opti-behavior' ); ?></div>

						<div class="ob-goal-grid">
							<div class="ob-goal-btn" data-goal="heatmaps">
								<div class="ob-goal-icon-wrap" style="background:rgba(239,68,68,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
								</div>
								<div>
									<div class="ob-goal-name"><?php esc_html_e( 'See where visitors click', 'opti-behavior' ); ?></div>
									<div class="ob-goal-desc"><?php esc_html_e( 'Heatmaps on every page', 'opti-behavior' ); ?></div>
								</div>
							</div>
							<div class="ob-goal-btn" data-goal="recordings">
								<div class="ob-goal-icon-wrap" style="background:rgba(139,92,246,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2"><rect x="2" y="3" width="14" height="10" rx="2"/><path d="M16 8l5-3v11l-5-3"/></svg>
								</div>
								<div>
									<div class="ob-goal-name"><?php esc_html_e( 'Watch user sessions', 'opti-behavior' ); ?></div>
									<div class="ob-goal-desc"><?php esc_html_e( 'Full HD session replay', 'opti-behavior' ); ?></div>
								</div>
							</div>
							<div class="ob-goal-btn" data-goal="forms">
								<div class="ob-goal-icon-wrap" style="background:rgba(245,158,11,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><rect x="2" y="3" width="14" height="18" rx="2"/><line x1="6" y1="8" x2="12" y2="8"/><line x1="6" y1="12" x2="10" y2="12"/></svg>
								</div>
								<div>
									<div class="ob-goal-name"><?php esc_html_e( 'Fix form abandonment', 'opti-behavior' ); ?></div>
									<div class="ob-goal-desc"><?php esc_html_e( 'Field-level analytics', 'opti-behavior' ); ?></div>
								</div>
							</div>
							<div class="ob-goal-btn" data-goal="funnels">
								<div class="ob-goal-icon-wrap" style="background:rgba(16,185,129,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
								</div>
								<div>
									<div class="ob-goal-name"><?php esc_html_e( 'Track conversion funnels', 'opti-behavior' ); ?></div>
									<div class="ob-goal-desc"><?php esc_html_e( 'Dropout analysis', 'opti-behavior' ); ?></div>
								</div>
							</div>
							<div class="ob-goal-btn" data-goal="analytics">
								<div class="ob-goal-icon-wrap" style="background:rgba(59,130,246,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
								</div>
								<div>
									<div class="ob-goal-name"><?php esc_html_e( 'Real Time Traffic & Analytics', 'opti-behavior' ); ?></div>
									<div class="ob-goal-desc"><?php esc_html_e( 'Live visitor tracking', 'opti-behavior' ); ?></div>
								</div>
							</div>
							<div class="ob-goal-btn" data-goal="user-journey">
								<div class="ob-goal-icon-wrap" style="background:rgba(236,72,153,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ec4899" stroke-width="2"><circle cx="12" cy="4.5" r="2.5"/><path d="M12 7v10"/><circle cx="5" cy="19.5" r="2.5"/><circle cx="19" cy="19.5" r="2.5"/><path d="M12 17l-7 2.5"/><path d="M12 17l7 2.5"/></svg>
								</div>
								<div>
									<div class="ob-goal-name"><?php esc_html_e( 'User Journey', 'opti-behavior' ); ?></div>
									<div class="ob-goal-desc"><?php esc_html_e( 'Full navigation paths', 'opti-behavior' ); ?></div>
								</div>
							</div>
							<div class="ob-goal-btn" data-goal="errors">
								<div class="ob-goal-icon-wrap" style="background:rgba(249,115,22,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f97316" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
								</div>
								<div>
									<div class="ob-goal-name"><?php esc_html_e( 'Errors Tracking', 'opti-behavior' ); ?></div>
									<div class="ob-goal-desc"><?php esc_html_e( 'JavaScript error monitoring', 'opti-behavior' ); ?></div>
								</div>
							</div>
							<div class="ob-goal-btn" data-goal="ai-insights">
								<div class="ob-goal-icon-wrap" style="background:rgba(20,184,166,0.08);">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#14b8a6" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 1.95-1.4 3.58-3.25 3.93L12 22"/><path d="M12 2a4 4 0 0 0-4 4c0 1.95 1.4 3.58 3.25 3.93"/><line x1="4.5" y1="9" x2="19.5" y2="9"/><circle cx="7.5" cy="14" r="1.5"/><circle cx="16.5" cy="14" r="1.5"/></svg>
								</div>
								<div>
									<div class="ob-goal-name"><?php esc_html_e( 'AI Insights', 'opti-behavior' ); ?></div>
									<div class="ob-goal-desc"><?php esc_html_e( 'Smart recommendations', 'opti-behavior' ); ?></div>
								</div>
							</div>
						</div>
					</div>

					<!-- STEP 3: Email reports -->
					<div class="ob-step-panel" id="ob-step2">
						<div class="ob-step-title"><?php esc_html_e( 'Set up automatic email reports', 'opti-behavior' ); ?></div>
						<div class="ob-step-desc"><?php esc_html_e( 'Connect your email sender once, choose who receives reports, and Opti-Behavior will send performance summaries automatically.', 'opti-behavior' ); ?></div>

						<div class="ob-email-report-card">
							<div class="ob-email-report-icon">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/><path d="M8 15h8"/></svg>
							</div>
							<div>
								<div class="ob-email-report-title"><?php esc_html_e( 'Reliable reports in your inbox', 'opti-behavior' ); ?></div>
								<div class="ob-email-report-text"><?php esc_html_e( 'Use WordPress mail for a quick start, or add SMTP for stronger delivery. Send a test email before enabling scheduled reports.', 'opti-behavior' ); ?></div>
							</div>
						</div>

						<div class="ob-wow-box">
							<div class="ob-wow-step">
								<div class="ob-wow-num">1</div>
								<div class="ob-wow-text"><?php esc_html_e( 'Open Scheduled Reports settings and choose WordPress mail or custom SMTP', 'opti-behavior' ); ?></div>
							</div>
							<div class="ob-wow-step">
								<div class="ob-wow-num">2</div>
								<div class="ob-wow-text"><?php esc_html_e( 'Send a test email to confirm delivery works correctly', 'opti-behavior' ); ?></div>
							</div>
							<div class="ob-wow-step">
								<div class="ob-wow-num">3</div>
								<div class="ob-wow-text"><?php esc_html_e( 'Add recipients, pick daily, weekly, or monthly, then save the schedule', 'opti-behavior' ); ?></div>
							</div>
						</div>

						<a class="ob-email-settings-link" href="<?php echo esc_url( admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=scheduled-reports' ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Open Scheduled Reports settings', 'opti-behavior' ); ?>
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
						</a>
					</div>

					<!-- STEP 4: First wow moment -->
					<div class="ob-step-panel" id="ob-step3">
						<div class="ob-step-title"><?php esc_html_e( 'Get your first heatmap', 'opti-behavior' ); ?></div>
						<div class="ob-step-desc"><?php esc_html_e( 'Follow these 3 steps and you\'ll see real data immediately — no waiting for traffic.', 'opti-behavior' ); ?></div>

						<div class="ob-wow-box">
							<div class="ob-wow-step">
								<div class="ob-wow-num">1</div>
								<div class="ob-wow-text"><?php esc_html_e( 'Open your website in a new private/incognito browser tab', 'opti-behavior' ); ?></div>
							</div>
							<div class="ob-wow-step">
								<div class="ob-wow-num">2</div>
								<div class="ob-wow-text"><?php esc_html_e( 'Click on 5 different elements on your page — buttons, images, links, anything', 'opti-behavior' ); ?></div>
							</div>
							<div class="ob-wow-step">
								<div class="ob-wow-num">3</div>
								<div class="ob-wow-text"><?php esc_html_e( 'Come back here and go to Heatmaps — your clicks will appear instantly', 'opti-behavior' ); ?></div>
							</div>
						</div>

						<div class="ob-status-box">
							<div class="ob-status-dot"></div>
							<div class="ob-status-text"><?php esc_html_e( 'You can start exploring all features right away — real visitor data will appear as soon as your first real traffic arrives', 'opti-behavior' ); ?></div>
						</div>
					</div>

				</div>

				<!-- Footer -->
				<div class="ob-divider"></div>
				<div class="ob-modal-footer">
					<button class="ob-btn-skip" id="ob-skipBtn"><?php esc_html_e( 'Skip setup', 'opti-behavior' ); ?></button>
					<button class="ob-btn-next" id="ob-nextBtn">
						<?php esc_html_e( 'Continue', 'opti-behavior' ); ?>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
					</button>
				</div>

			</div>
		</div>
		<?php
	}
}
