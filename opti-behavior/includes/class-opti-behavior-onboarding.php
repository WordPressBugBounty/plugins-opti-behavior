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

	/**
	 * WordPress option: '1' when the user ticked the auto-create opt-in in the
	 * onboarding modal, '0' otherwise. Recorded for support/analytics only —
	 * the creation itself is done by `optibehavior_create_recommended_funnels`,
	 * whose step-signature guard is the authoritative idempotency mechanism.
	 *
	 * @since 1.8.4
	 */
	const OPTION_CREATE_FUNNELS = 'opti_behavior_onboarding_create_funnels';

	/**
	 * WordPress option: the recipe id the selected goal(s) mapped to, so the
	 * Funnels page / support can tell which funnel the user came for.
	 *
	 * @since 1.8.4
	 */
	const OPTION_GOAL_RECIPE = 'opti_behavior_onboarding_goal_recipe';

	/**
	 * Query parameter that forces the popup to render for visual debugging.
	 *
	 * Debug-only: honoured solely when `WP_DEBUG` is on and the current user is
	 * an administrator, so it is inert on production sites.
	 *
	 * @since 1.9.0
	 */
	const PREVIEW_PARAM = 'ob_preview_onboarding';

	/** @var self|null Singleton instance. */
	private static $instance = null;

	/** @var array|null Memoized offered Free recipes (see get_recommended_recipes()). */
	private $recommended_recipes = null;

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
		// Debug-only preview (?ob_preview_onboarding=1): render regardless of
		// consent / dismissal / existing sessions so the modal can be inspected
		// on a site that already has data. Never persists anything.
		if ( $this->is_preview_request() ) {
			return true;
		}

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
	 * Whether the debug-only preview may be used at all on this request.
	 *
	 * Two independent gates, both required:
	 * 1. `WP_DEBUG` is enabled (development install only).
	 * 2. The current user is a logged-in administrator.
	 *
	 * @since 1.9.0
	 * @return bool
	 */
	public function preview_allowed() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return false;
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether this request asks for the debug-only onboarding preview.
	 *
	 * @since 1.9.0
	 * @return bool
	 */
	public function is_preview_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only debug flag; capability + WP_DEBUG gated below.
		$opti_behavior_requested = isset( $_GET[ self::PREVIEW_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PREVIEW_PARAM ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '1' !== $opti_behavior_requested ) {
			return false;
		}

		return $this->preview_allowed();
	}

	// -------------------------------------------------------------------------
	// Auto-funnel opt-in (spec.md §2.3-3, locked decision 1)
	// -------------------------------------------------------------------------

	/**
	 * Goal (step 2) → candidate recipe ids, best match first.
	 *
	 * The goals the modal asks about are *feature* goals, not site types, so the
	 * map is only a preference order: the recipe actually highlighted is the
	 * first candidate that the detector really offers for this site. A goal with
	 * no offered candidate falls back to the highest-priority offered recipe.
	 *
	 * @since 1.8.4
	 * @return array<string, string[]>
	 */
	public function get_goal_recipe_map() {
		$map = array(
			'funnels'      => array( 'woo_purchase', 'edd_digital_purchase', 'membership_subscription', 'lead_generation', 'signup', 'blog_engagement' ),
			'forms'        => array( 'lead_generation', 'signup', 'woo_cart_recovery' ),
			'analytics'    => array( 'blog_engagement', 'woo_purchase', 'lead_generation' ),
			'heatmaps'     => array( 'woo_purchase', 'blog_engagement', 'lead_generation' ),
			'recordings'   => array( 'woo_cart_recovery', 'signup', 'blog_engagement' ),
			'user-journey' => array( 'woo_purchase', 'blog_engagement', 'signup' ),
			'errors'       => array( 'woo_cart_recovery', 'signup', 'lead_generation' ),
			'ai-insights'  => array( 'woo_purchase', 'lead_generation', 'blog_engagement' ),
		);

		/**
		 * Filter the onboarding goal → recipe candidate map.
		 *
		 * @since 1.8.4
		 *
		 * @param array<string, string[]> $map Goal slug => ordered recipe ids.
		 */
		$map = apply_filters( 'opti_behavior_onboarding_goal_recipes', $map );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * The recipes the opt-in would actually create for this site.
	 *
	 * This mirrors `Opti_Behavior_Funnel_Auto_Builder::create_recommended()`
	 * exactly — the endpoint the opt-in calls — so the funnel names printed in
	 * the checkbox description are the funnels the user really gets:
	 * offered recipes, minus dismissed ones, minus Pro-tier ones while the Pro
	 * gate is closed (the endpoint skips those server-side as `locked`).
	 *
	 * On a Free site this is exactly the Free set of spec.md §2.4.
	 *
	 * @since 1.8.4
	 * @return array[] Entries of { id, label, description }.
	 */
	public function get_recommended_recipes() {
		if ( null !== $this->recommended_recipes ) {
			return $this->recommended_recipes;
		}

		$this->recommended_recipes = array();

		// The autoloader does not cover Opti_Behavior_Funnel_* — require explicitly.
		if ( defined( 'OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR' ) ) {
			require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-funnel-site-detector.php';
			require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-funnel-recipes.php';
			require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-funnel-auto-builder.php';
		}

		if ( ! class_exists( 'Opti_Behavior_Funnel_Site_Detector' ) || ! class_exists( 'Opti_Behavior_Funnel_Recipes' ) ) {
			return $this->recommended_recipes;
		}

		// Read-only use of the builder (tier gate + dismissal list); no
		// persister is needed because nothing is written here.
		$builder   = class_exists( 'Opti_Behavior_Funnel_Auto_Builder' ) ? new Opti_Behavior_Funnel_Auto_Builder() : null;
		$pro_open  = $builder ? $builder->pro_available() : false;
		$dismissed = $builder ? $builder->get_dismissed() : array();
		$context   = Opti_Behavior_Funnel_Site_Detector::instance()->detect();
		$available = Opti_Behavior_Funnel_Recipes::instance()->get_available( $context );

		foreach ( $available as $recipe ) {
			if ( in_array( (string) $recipe['id'], (array) $dismissed, true ) ) {
				continue;
			}

			if ( Opti_Behavior_Funnel_Recipes::TIER_FREE !== $recipe['tier'] && ! $pro_open ) {
				continue;
			}

			$this->recommended_recipes[] = array(
				'id'          => (string) $recipe['id'],
				'label'       => (string) $recipe['label'],
				'description' => (string) $recipe['description'],
			);
		}

		return $this->recommended_recipes;
	}

	/**
	 * Map the selected goal(s) to one offered recipe id.
	 *
	 * @since 1.8.4
	 *
	 * @param string|string[] $goals Goal slug, or comma-separated list / array of them.
	 * @return string Recipe id, or '' when this site is offered no Free recipe.
	 */
	public function map_goals_to_recipe( $goals ) {
		$recommended = $this->get_recommended_recipes();
		if ( empty( $recommended ) ) {
			return '';
		}

		$offered = wp_list_pluck( $recommended, 'id' );

		if ( ! is_array( $goals ) ) {
			$goals = explode( ',', (string) $goals );
		}

		$map = $this->get_goal_recipe_map();

		foreach ( $goals as $goal ) {
			$goal = trim( (string) $goal );
			if ( '' === $goal || empty( $map[ $goal ] ) ) {
				continue;
			}

			foreach ( (array) $map[ $goal ] as $candidate ) {
				if ( in_array( $candidate, $offered, true ) ) {
					return (string) $candidate;
				}
			}
		}

		// No goal matched an offered recipe — fall back to the top-priority one.
		return (string) $offered[0];
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
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'opti_behavior_dismiss_onboarding' ),
				'settingsUrl'  => admin_url( 'admin.php?page=opti-behavior-settings&settings_tab=scheduled-reports' ),
				// Auto-funnel opt-in: the bulk-create endpoint lives on the
				// funnels page class and uses its own nonce (spec.md §4.2).
				'funnelsNonce' => wp_create_nonce( 'opti_behavior_funnels' ),
				'recommended'  => $this->get_recommended_recipes(),
				'goalRecipes'  => $this->get_goal_recipe_map(),
				// Debug preview: the popup runs read-only — no dismissal is
				// recorded and no funnel is ever created (see onboarding-popup.js).
				'preview'      => $this->is_preview_request(),
				'i18n'         => array(
					'step1of4'          => __( 'Step 1 of 4', 'opti-behavior' ),
					'step2of4'          => __( 'Step 2 of 4', 'opti-behavior' ),
					'step3of4'          => __( 'Step 3 of 4', 'opti-behavior' ),
					'step4of4'          => __( 'Step 4 of 4', 'opti-behavior' ),
					'btnContinue'       => __( 'Continue', 'opti-behavior' ),
					'btnGoDashboard'    => __( 'Go to Dashboard', 'opti-behavior' ),
					'setupComplete'     => __( 'Setup complete', 'opti-behavior' ),
					'setupCompleteDesc' => __( 'Your dashboard is ready. Data will appear as visitors arrive on your site.', 'opti-behavior' ),
					/* translators: %s: funnel name matching the selected goal. */
					'goalMatch'         => __( 'Best match for your goal: %s', 'opti-behavior' ),
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

		// Debug preview (see PREVIEW_PARAM): second line of defence — the popup
		// JS already skips this request in preview mode, but should it ever be
		// sent, answer without persisting anything.
		$opti_behavior_is_preview = ( isset( $_POST['preview'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_POST['preview'] ) ) );
		if ( $opti_behavior_is_preview && $this->preview_allowed() ) {
			wp_send_json_success(
				array(
					'preview'        => true,
					'create_funnels' => false,
					'goal_recipe'    => '',
				)
			);
		}

		update_option( self::OPTION_DISMISSED, '1', false );

		$opti_behavior_goal = '';

		// Store selected goal for analytics (optional).
		if ( ! empty( $_POST['goal'] ) ) {
			$opti_behavior_goal = sanitize_text_field( wp_unslash( $_POST['goal'] ) );
			update_option( self::OPTION_GOAL, $opti_behavior_goal, false );
		}

		// Auto-funnel opt-in (locked decision 1): record the choice only. The
		// funnels themselves are created by the separate
		// `optibehavior_create_recommended_funnels` request the popup fires when
		// the box is ticked; that endpoint's signature guard makes a second
		// onboarding run a no-op instead of a duplicate.
		$opti_behavior_create_funnels = ( isset( $_POST['create_funnels'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_POST['create_funnels'] ) ) );
		update_option( self::OPTION_CREATE_FUNNELS, $opti_behavior_create_funnels ? '1' : '0', false );

		$opti_behavior_goal_recipe = $this->map_goals_to_recipe( $opti_behavior_goal );
		if ( '' !== $opti_behavior_goal_recipe ) {
			update_option( self::OPTION_GOAL_RECIPE, $opti_behavior_goal_recipe, false );
		}

		wp_send_json_success(
			array(
				'create_funnels' => $opti_behavior_create_funnels,
				'goal_recipe'    => $opti_behavior_goal_recipe,
			)
		);
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

						<?php $opti_behavior_recommended = $this->get_recommended_recipes(); ?>
						<?php if ( ! empty( $opti_behavior_recommended ) ) : ?>
							<?php
							// Pre-checked by default: finishing the setup with the box
							// still ticked creates the recommended set. Unticking it, or
							// skipping/closing the popup, still creates nothing.
							$opti_behavior_recipe_labels = wp_list_pluck( $opti_behavior_recommended, 'label' );
							?>
							<div class="ob-optin-box">
								<label class="ob-optin-row" for="ob-createFunnels">
									<input type="checkbox" id="ob-createFunnels" class="ob-optin-check" value="1" checked="checked" />
									<span class="ob-optin-text">
										<span class="ob-optin-title"><?php esc_html_e( 'Create the recommended funnels for my site', 'opti-behavior' ); ?></span>
										<span class="ob-optin-desc">
											<?php
											printf(
												/* translators: %s: comma-separated list of funnel names. */
												esc_html__( 'Based on what we detected on your site: %s. You can edit or delete them at any time.', 'opti-behavior' ),
												esc_html( implode( ', ', $opti_behavior_recipe_labels ) )
											);
											?>
										</span>
										<span class="ob-optin-match" id="ob-createFunnelsMatch" hidden></span>
									</span>
								</label>
							</div>
						<?php endif; ?>
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
