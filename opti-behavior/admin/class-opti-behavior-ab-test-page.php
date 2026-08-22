<?php
/**
 * A/B Test Admin Page
 *
 * Registers the admin menu, enqueues assets, and renders the A/B testing
 * dashboard (test list, builder wizard, and results pages).
 *
 * @package opti-behavior
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opti_Behavior_AB_Test_Page {

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'opti-behavior-ab-testing';

	/**
	 * Core instance.
	 *
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param Opti_Behavior_Heatmap_Core $core Core instance.
	 */
	public function __construct( $core ) {
		$this->core = $core;
		$this->init_hooks();
	}

	/**
	 * Register hooks.
	 */
	private function init_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the A/B Testing submenu page.
	 */
	public function register_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Lucide "split" icon (20x20).
		$icon = '<span style="display:inline-flex;align-items:center;margin-right:6px;">'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" '
			. 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" '
			. 'stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 3H3v5"/>'
			. '<path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/>'
			. '<path d="m15 9 6-6"/><path d="M16.5 21.5 12 17"/></svg></span>';

		add_submenu_page(
			'opti-behavior-analytics',
			__( 'A/B Testing', 'opti-behavior' ),
			$icon . __( 'A/B Testing', 'opti-behavior' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS/JS on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		// The hook_suffix for submenu pages is "opti-behavior_page_opti-behavior-ab-testing".
		if ( false === strpos( $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		$version = defined( 'OPTI_BEHAVIOR_HEATMAP_VERSION' ) ? OPTI_BEHAVIOR_HEATMAP_VERSION : '1.3.0';

		$opti_behavior_ab_css_ver = $version . '.' . filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/css/ab-testing.css' );
		wp_enqueue_style(
			'opti-behavior-ab-testing',
			plugins_url( 'assets/css/ab-testing.css', dirname( __FILE__ ) ),
			array(),
			$opti_behavior_ab_css_ver
		);

		// Chart.js (already bundled for the analytics dashboard).
		if ( ! wp_script_is( 'chart-js', 'registered' ) ) {
			wp_register_script(
				'chart-js',
				plugins_url( 'assets/js/chart.umd.min.js', dirname( __FILE__ ) ),
				array(),
				'4.4.1-' . OPTI_BEHAVIOR_HEATMAP_VERSION, // Lib ver + plugin ver so updates cache-bust.
				true
			);
		}

		$opti_behavior_ab_js_ver = $version . '.' . filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/js/ab-test-admin.js' );
		wp_enqueue_script(
			'opti-behavior-ab-admin',
			plugins_url( 'assets/js/ab-test-admin.js', dirname( __FILE__ ) ),
			array( 'jquery', 'wp-util', 'chart-js' ),
			$opti_behavior_ab_js_ver,
			true
		);

		// Localize.
		$free_limits = get_option(
			'opti_behavior_ab_free_limits',
			array(
				'max_concurrent_tests' => 3,
				'max_variants_per_test' => 3,
				'max_goals_per_test' => 1,
			)
		);

		wp_localize_script(
			'opti-behavior-ab-admin',
			'optiBehaviorABAdmin',
			array(
				'ajax_url'           => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( 'opti_behavior_ab_nonce' ),
				'is_pro'             => function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active(),
				'has_woo'            => class_exists( 'WooCommerce' ),
				'home_url'           => home_url(),
				'visual_editor_url'  => admin_url( 'admin.php?page=opti-behavior-ab-visual-editor' ),
				'exclude_spam'       => $this->resolve_spam_exclusion_from_request( $_GET ) ? '1' : '0', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
				'free_limits'        => $free_limits,
				'target_picker'      => array(
					'search_action' => 'opti_behavior_ab_search_targets',
					'per_page'      => 20,
					'quick_targets' => $this->get_ab_target_picker_quick_targets(),
				),
				'strings'            => $this->get_js_strings(),
			)
		);
	}

	/**
	 * Get translatable JS strings.
	 *
	 * @return array
	 */
	private function get_js_strings() {
		return array(
			'confirm_delete_title'  => __( 'Delete A/B Test', 'opti-behavior' ),
			'confirm_delete'        => __( 'Are you sure you want to delete this test? This action cannot be undone.', 'opti-behavior' ),
			'confirm_delete_button' => __( 'Delete', 'opti-behavior' ),
			'confirm_stop_title'    => __( 'Stop A/B Test', 'opti-behavior' ),
			'confirm_stop'          => __( 'Are you sure you want to stop this test? You will not be able to resume it.', 'opti-behavior' ),
			'confirm_stop_button'   => __( 'Stop Test', 'opti-behavior' ),
			'confirm_cancel'        => __( 'Cancel', 'opti-behavior' ),
			'confirm_winner_title'  => __( 'Declare Best-Performing Variant', 'opti-behavior' ),
			'confirm_winner'        => __( 'Opti Behavior will declare the variant with the highest conversion rate as the winner based on the current test results.', 'opti-behavior' ),
			'confirm_winner_button' => __( 'Declare Best Performer', 'opti-behavior' ),
			'confirm_revert_title'  => __( 'Disable Applied Winner', 'opti-behavior' ),
			'confirm_revert'        => __( 'This will disable the permanent winner change and return the test to Completed. The declared winner and historical data will be preserved.', 'opti-behavior' ),
			'confirm_revert_button' => __( 'Disable Applied Winner', 'opti-behavior' ),
			'confirm_apply'         => __( 'This will permanently apply the winning variant. Continue?', 'opti-behavior' ),
			'saving'           => __( 'Saving…', 'opti-behavior' ),
			'saved'            => __( 'Saved!', 'opti-behavior' ),
			'error'            => __( 'An error occurred. Please try again.', 'opti-behavior' ),
			'loading'          => __( 'Loading…', 'opti-behavior' ),
			'no_tests'         => __( 'No A/B tests found. Create your first test to start optimizing!', 'opti-behavior' ),
			'status_draft'     => __( 'Draft', 'opti-behavior' ),
			'status_running'   => __( 'Running', 'opti-behavior' ),
			'status_paused'    => __( 'Paused', 'opti-behavior' ),
			'status_completed' => __( 'Completed', 'opti-behavior' ),
			'status_deleted'   => __( 'Deleted', 'opti-behavior' ),
			'pro_required'     => __( 'This feature requires Opti-Behavior Pro.', 'opti-behavior' ),
			'step_type'        => __( 'Test Type', 'opti-behavior' ),
			'step_target'      => __( 'Target', 'opti-behavior' ),
			'step_variants'    => __( 'Variants', 'opti-behavior' ),
			'step_goals'       => __( 'Goals', 'opti-behavior' ),
			'step_settings'    => __( 'Settings', 'opti-behavior' ),
			'step_review'      => __( 'Review', 'opti-behavior' ),

			// Goal field labels & placeholders.
			'goal_url_label'              => __( 'Destination URL', 'opti-behavior' ),
			'goal_url_placeholder'        => __( 'https://example.com/thank-you/', 'opti-behavior' ),
			'goal_url_help'               => __( 'Visitors who land on this URL will be counted as conversions.', 'opti-behavior' ),
			'goal_url_invalid'            => __( 'Please enter a valid URL.', 'opti-behavior' ),
			'goal_selector_label'         => __( 'CSS Selector', 'opti-behavior' ),
			'goal_selector_placeholder'   => __( 'e.g., .buy-button, #cta-link', 'opti-behavior' ),
			'goal_selector_help'          => __( 'Clicks on any element matching this CSS selector will be counted as conversions.', 'opti-behavior' ),
			'goal_form_label'             => __( 'Form', 'opti-behavior' ),
			'goal_form_any'               => __( 'Any form on page', 'opti-behavior' ),
			'goal_form_custom'            => __( 'Custom CSS selector', 'opti-behavior' ),
			'goal_form_selector_placeholder' => __( 'e.g., #contact-form, .wpcf7-form', 'opti-behavior' ),
			'goal_form_help'              => __( 'Form submissions on the page will be counted as conversions.', 'opti-behavior' ),

			// Pro goal type labels & help text (shown disabled in free plugin).
			'goal_scroll_label'           => __( 'Scroll Depth', 'opti-behavior' ),
			'goal_scroll_help_pro'        => __( 'Upgrade to Pro to track scroll depth conversions.', 'opti-behavior' ),
			'goal_time_label'             => __( 'Time on Page', 'opti-behavior' ),
			'goal_time_seconds'           => __( 'seconds', 'opti-behavior' ),
			'goal_time_minutes'           => __( 'minutes', 'opti-behavior' ),
			'goal_time_help_pro'          => __( 'Upgrade to Pro to track time-on-page conversions.', 'opti-behavior' ),
			'goal_pro_required'           => __( 'Upgrade to Opti-Behavior Pro to use this goal type.', 'opti-behavior' ),
			/* translators: %d: maximum number of variants allowed per A/B test. */
			'limit_variants_reached'      => __( 'Free plan limit reached: maximum %d variants per test.', 'opti-behavior' ),
			/* translators: %d: maximum number of goals allowed per A/B test. */
			'limit_goals_reached'         => __( 'Free plan limit reached: maximum %d goals per test.', 'opti-behavior' ),

			// Click goal — renamed free button + Pro visual-picker button.
			'goal_click_add_element'      => __( '+ Add CSS Selector', 'opti-behavior' ),
			'goal_click_pick_element'     => __( '✦ Pick Element Visually', 'opti-behavior' ),
			'goal_click_pick_pro_title'   => __( 'Upgrade to Pro to pick elements visually from the live page.', 'opti-behavior' ),

			// Review step — goal summary labels.
			'review_goal_no_url'          => __( '(no URL set)', 'opti-behavior' ),
			'review_goal_no_selector'     => __( '(no selector set)', 'opti-behavior' ),
			'review_goal_any_form'        => __( 'Any form on page', 'opti-behavior' ),
			'review_goal_custom_form'     => __( 'Custom selector', 'opti-behavior' ),
			'review_goal_any_revenue'     => __( 'Any revenue', 'opti-behavior' ),
			'review_goal_any_add_to_cart' => __( 'Any product', 'opti-behavior' ),
			'review_goal_any_purchase'    => __( 'Any purchase', 'opti-behavior' ),
			'review_goal_bounce_stays'    => __( 'Stays on page', 'opti-behavior' ),
			'review_goal_bounce_bounces'  => __( 'Bounces off page', 'opti-behavior' ),

			// Enhanced Element target picker.
			'target_picker_content_mode'       => __( 'WordPress Content', 'opti-behavior' ),
			'target_picker_url_mode'           => __( 'URL', 'opti-behavior' ),
			'target_picker_search_placeholder' => __( 'Search pages, posts, products, and public content...', 'opti-behavior' ),
			'target_picker_searching'          => __( 'Searching targets...', 'opti-behavior' ),
			'target_picker_no_results'         => __( 'No matching targets found.', 'opti-behavior' ),
			'target_picker_load_more'          => __( 'Load more results', 'opti-behavior' ),
			'target_picker_change'             => __( 'Change', 'opti-behavior' ),
			'target_picker_clear'              => __( 'Clear target', 'opti-behavior' ),
			'target_picker_selected'           => __( 'Selected target', 'opti-behavior' ),
			'target_picker_quick_targets'      => __( 'Quick targets', 'opti-behavior' ),
			'target_picker_url_invalid'        => __( 'Please enter a same-site URL.', 'opti-behavior' ),

			// Tooltip HTML strings for JS-rendered elements (pre-rendered by PHP).
			'tooltip_variant_name'       => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Variant Name', 'opti-behavior' ),
				__( 'A short label for this version — e.g., "Variant B – Red Button". Used in charts and reports.', 'opti-behavior' ),
				'', '', array( 'position' => 'right' )
			) : '',
			'tooltip_traffic_split'      => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Traffic Split', 'opti-behavior' ),
				__( 'What percentage of test visitors sees this variant. All variant percentages including Control must add up to 100%.', 'opti-behavior' ),
				'', '', array( 'position' => 'right' )
			) : '',
			'tooltip_control_variant'    => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Control Variant', 'opti-behavior' ),
				__( 'The original, unchanged version of your page. All other variants are measured against this baseline.', 'opti-behavior' ),
				'', '', array( 'position' => 'top' )
			) : '',
			'tooltip_variant_url'        => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Variant URL', 'opti-behavior' ),
				__( 'The alternative page URL visitors will be redirected to for this variant.', 'opti-behavior' ),
				'', '', array( 'position' => 'right' )
			) : '',
			'tooltip_visual_editor'      => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Open Visual Editor', 'opti-behavior' ),
				__( 'Launch the point-and-click editor to modify HTML elements on the page for this variant. Changes are stored as a diff — no file edits.', 'opti-behavior' ),
				'', '', array( 'position' => 'top' )
			) : '',
			'tooltip_goal_type'          => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Goal Type', 'opti-behavior' ),
				__( 'Choose the type of conversion event to track for this goal.', 'opti-behavior' ),
				'', '', array( 'position' => 'right' )
			) : '',
			'tooltip_goal_url'           => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Destination URL', 'opti-behavior' ),
				__( 'Visitors who land on this exact URL will trigger a conversion. Supports partial matching with trailing wildcards.', 'opti-behavior' ),
				'', '', array( 'position' => 'right' )
			) : '',
			'tooltip_goal_selector'      => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'CSS Selector', 'opti-behavior' ),
				__( 'An element identifier like .buy-button or #cta-link. Clicks on any matching element trigger a conversion.', 'opti-behavior' ),
				'', '', array( 'position' => 'right' )
			) : '',
			'tooltip_goal_form'          => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Form Selector', 'opti-behavior' ),
				__( 'Choose "Any form" to track all form submissions, or enter a CSS selector to target a specific form.', 'opti-behavior' ),
				'', '', array( 'position' => 'right' )
			) : '',
			'tooltip_variant_conv_rate'  => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Conversion Rate', 'opti-behavior' ),
				__( 'The percentage of visitors who achieved the goal while seeing this variant. Higher is better.', 'opti-behavior' ),
				'', '', array( 'position' => 'top' )
			) : '',
			'tooltip_variant_visitors'   => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Visitors', 'opti-behavior' ),
				__( 'The number of times this variant was shown to visitors since the test started.', 'opti-behavior' ),
				'', '', array( 'position' => 'top' )
			) : '',
			'tooltip_variant_uplift'     => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Uplift vs. Control', 'opti-behavior' ),
				__( 'The relative improvement (or decline) of this variant\'s conversion rate compared to the original Control.', 'opti-behavior' ),
				'', '', array( 'position' => 'top' )
			) : '',
			'tooltip_variant_sig'        => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Statistical Significance', 'opti-behavior' ),
				__( 'The p-value indicates the probability that the observed difference is due to chance. Values below 0.05 are considered statistically significant at 95% confidence.', 'opti-behavior' ),
				'', '', array( 'position' => 'top' )
			) : '',
			'tooltip_review_type'        => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Test Type', 'opti-behavior' ),
				__( 'The kind of split test: Page Split redirects visitors to different URLs, Element modifies page content in place, WooCommerce tests product page elements.', 'opti-behavior' ),
				'', '', array( 'position' => 'right' )
			) : '',
			'tooltip_review_url'         => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Target URL', 'opti-behavior' ),
				__( 'The live page where visitors will be enrolled in the test.', 'opti-behavior' ),
				'', '', array( 'position' => 'right' )
			) : '',
			'tooltip_review_variants'    => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Variants', 'opti-behavior' ),
				__( 'Each version of the page including the original Control and all challenger variants.', 'opti-behavior' ),
				'', '', array( 'position' => 'right' )
			) : '',
			'tooltip_review_goals'       => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Goals', 'opti-behavior' ),
				__( 'The conversion actions the test will measure to determine a winner.', 'opti-behavior' ),
				'', '', array( 'position' => 'right' )
			) : '',
			'tooltip_review_settings'    => function_exists( 'opti_behavior_tooltip' ) ? opti_behavior_tooltip(
				__( 'Test Settings', 'opti-behavior' ),
				__( 'The confidence level, traffic percentage, and duration thresholds that govern when a winner can be declared.', 'opti-behavior' ),
				'', '', array( 'position' => 'right' )
			) : '',

			// Wizard validation + navigation.
			'validate_select_type'    => __( 'Please select a test type.', 'opti-behavior' ),
			'validate_enter_name'     => __( 'Please enter a test name.', 'opti-behavior' ),
			'validate_select_product' => __( 'Please select a WooCommerce product.', 'opti-behavior' ),
			'validate_select_target'  => __( 'Please select WordPress content or enter a same-site URL.', 'opti-behavior' ),
			'validate_min_variants'   => __( 'You need at least 2 variants.', 'opti-behavior' ),
			'validate_min_goals'      => __( 'You need at least 1 goal.', 'opti-behavior' ),
			/* translators: %s: current total of variant traffic weights as a percentage. */
			'validate_weights_total'  => __( 'Variant traffic weights must total 100%. Current total: %s%.', 'opti-behavior' ),
			'launch_test'             => __( 'Launch Test', 'opti-behavior' ),
			'save_draft'              => __( 'Save Draft', 'opti-behavior' ),

			// Step 2 titles / descriptions.
			'step2_title_product'     => __( 'Select Product', 'opti-behavior' ),
			'step2_desc_page'         => __( 'Choose which page or post this test applies to.', 'opti-behavior' ),
			'step2_desc_product'      => __( 'Choose the WooCommerce product you want to A/B test.', 'opti-behavior' ),
			'step2_desc_element'      => __( 'Choose WordPress content or enter a same-site URL for this Element test.', 'opti-behavior' ),

			// Target fallback labels.
			'target_post_fallback'    => __( 'Post #', 'opti-behavior' ),
			'target_type_content'     => __( 'Content', 'opti-behavior' ),

			// Visual editor / variant cards.
			'variant_redirect_url_label'       => __( 'Redirect URL', 'opti-behavior' ),
			'variant_redirect_url_placeholder' => __( 'https://example.com/variant-page', 'opti-behavior' ),
			've_open_editor'          => __( 'Open Visual Editor', 'opti-behavior' ),
			've_no_changes_yet'       => __( 'No changes yet', 'opti-behavior' ),
			've_add_changes_hint'     => __( 'Add changes via Visual Editor', 'opti-behavior' ),
			've_no_target_page'       => __( 'No target page', 'opti-behavior' ),
			've_configure_target_hint' => __( 'Configure a target in Step 2', 'opti-behavior' ),
			've_original_no_mods'     => __( 'Original page — no modifications', 'opti-behavior' ),
			've_control_desc'         => __( 'Visitors in this group see the unmodified page. This is the baseline your variants are compared against.', 'opti-behavior' ),
			've_control_desc_short'   => __( 'Visitors in this group see the unmodified page.', 'opti-behavior' ),
			've_no_visual_changes'    => __( 'No visual changes configured yet.', 'opti-behavior' ),
			've_no_preview'           => __( 'No preview', 'opti-behavior' ),
			'preview_variant'         => __( 'Variant preview', 'opti-behavior' ),
			'preview_original_page'   => __( 'Original page', 'opti-behavior' ),

			// Visual-change label words.
			'change_one'   => __( 'change', 'opti-behavior' ),
			'change_many'  => __( 'changes', 'opti-behavior' ),
			'vc_block'     => __( 'Block', 'opti-behavior' ),
			'vc_after'     => __( 'after', 'opti-behavior' ),
			'vc_page'      => __( 'page', 'opti-behavior' ),
			'vc_duplicate' => __( 'Duplicate', 'opti-behavior' ),
			'vc_hide'      => __( 'Hide', 'opti-behavior' ),
			'vc_move'      => __( 'Move', 'opti-behavior' ),
			'vc_edit'      => __( 'Edit', 'opti-behavior' ),
			'vc_style'     => __( 'Style', 'opti-behavior' ),
			'vc_unknown'   => __( 'Unknown', 'opti-behavior' ),
			'vc_styled'    => __( 'styled', 'opti-behavior' ),
			'vc_added'     => __( 'added', 'opti-behavior' ),
			'vc_hidden'    => __( 'hidden', 'opti-behavior' ),
			'vc_edited'    => __( 'edited', 'opti-behavior' ),

			// Variant row.
			'variant_badge_control'   => __( 'Control', 'opti-behavior' ),
			'variant_badge_variant'   => __( 'Variant', 'opti-behavior' ),
			'variant_name_placeholder' => __( 'Variant name', 'opti-behavior' ),
			'variant_weight_title'    => __( 'Traffic weight', 'opti-behavior' ),
			'remove'                  => __( 'Remove', 'opti-behavior' ),
			'pro_badge'               => __( 'Pro', 'opti-behavior' ),
			'pro_suffix'              => __( ' (Pro)', 'opti-behavior' ),

			// Save-draft / visual-editor toasts.
			've_saving_draft_retry'    => __( 'Saving draft. Click Open Visual Editor again after the direct link appears.', 'opti-behavior' ),
			've_draft_save_failed'     => __( 'Could not save draft. Please try again.', 'opti-behavior' ),
			've_draft_saved_retry'     => __( 'Draft saved. Click Open Visual Editor again to open the direct link.', 'opti-behavior' ),
			've_draft_saved_no_variant' => __( 'Draft saved but could not find variant. Please save the draft, then use the direct Visual Editor link.', 'opti-behavior' ),
			've_draft_saved_no_data'   => __( 'Draft saved but variant data could not be loaded. Please save the draft, then use the direct Visual Editor link.', 'opti-behavior' ),
			'default_test_name_suffix' => __( ' Test', 'opti-behavior' ),

			// Review summary.
			'review_test_info'        => __( 'Test Info', 'opti-behavior' ),
			'review_name'             => __( 'Name', 'opti-behavior' ),
			'review_type'             => __( 'Type', 'opti-behavior' ),
			'review_target'           => __( 'Target', 'opti-behavior' ),
			'review_woo_title_prefix' => __( 'title: ', 'opti-behavior' ),
			'review_woo_price_prefix' => __( 'price: ', 'opti-behavior' ),
			'review_visual_editor'    => __( '(Visual Editor)', 'opti-behavior' ),
			'review_weight'           => __( 'Weight: ', 'opti-behavior' ),
			'review_element_one'      => __( 'element', 'opti-behavior' ),
			'review_element_many'     => __( 'elements', 'opti-behavior' ),
			'review_confidence'       => __( 'Confidence', 'opti-behavior' ),
			'review_traffic'          => __( 'Traffic %', 'opti-behavior' ),
			'review_min_sample'       => __( 'Min Sample Size', 'opti-behavior' ),
			'review_min_duration'     => __( 'Min Duration', 'opti-behavior' ),
			'day_one'                 => __( 'day', 'opti-behavior' ),
			'day_many'                => __( 'days', 'opti-behavior' ),

			// Review goal detail labels referenced by JS (added so they translate).
			'review_goal_detected_form'    => __( 'Detected form', 'opti-behavior' ),
			'review_goal_per_variant_form' => __( 'Per-variant form selector', 'opti-behavior' ),
			'review_goal_revenue_category' => __( 'Category ID', 'opti-behavior' ),
			'review_goal_revenue_min'      => __( 'Min value', 'opti-behavior' ),
			'review_goal_revenue_product'  => __( 'Current page product', 'opti-behavior' ),
			'review_goal_cart_product'     => __( 'Product ID', 'opti-behavior' ),
			'review_goal_cart_current'     => __( 'Current page product', 'opti-behavior' ),
			'review_goal_purchase_min'     => __( 'Min value', 'opti-behavior' ),
			'review_goal_purchase_current' => __( 'Current page product', 'opti-behavior' ),

			// Goal type display labels.
			'goal_label_page_visit'      => __( 'Page Visit', 'opti-behavior' ),
			'goal_label_click'           => __( 'Click', 'opti-behavior' ),
			'goal_label_form_submit'     => __( 'Form Submit', 'opti-behavior' ),
			'goal_label_scroll_depth'    => __( 'Scroll Depth', 'opti-behavior' ),
			'goal_label_time_on_page'    => __( 'Time on Page', 'opti-behavior' ),
			'goal_label_revenue'         => __( 'Revenue', 'opti-behavior' ),
			'goal_label_bounce_rate'     => __( 'Bounce Rate', 'opti-behavior' ),
			'goal_label_woo_add_to_cart' => __( 'Woo Add to Cart', 'opti-behavior' ),
			'goal_label_woo_purchase'    => __( 'Woo Purchase', 'opti-behavior' ),

			// Goal type hints.
			'goal_hint_page_visit'      => __( 'Counts a conversion each time a visitor loads the target URL.', 'opti-behavior' ),
			'goal_hint_click'           => __( 'Counts a conversion each time a visitor clicks an element matching any of the selectors below.', 'opti-behavior' ),
			'goal_hint_form_submit'     => __( 'Counts a conversion each time a visitor submits a form matching the selector.', 'opti-behavior' ),
			'goal_hint_scroll_depth'    => __( 'Counts a conversion when the visitor scrolls at least this far down the page.', 'opti-behavior' ),
			'goal_hint_time_on_page'    => __( 'Counts a conversion when the visitor stays on the page for at least this long.', 'opti-behavior' ),
			'goal_hint_revenue'         => __( 'Aggregates revenue from WooCommerce orders attributed to the test.', 'opti-behavior' ),
			'goal_hint_bounce_rate'     => __( 'Session is a “non-bounce” when it lasts longer than this threshold.', 'opti-behavior' ),
			'goal_hint_woo_add_to_cart' => __( 'Counts a conversion when the visitor adds the test product to their cart.', 'opti-behavior' ),
			'goal_hint_woo_purchase'    => __( 'Counts a conversion when the visitor completes a purchase of the test product.', 'opti-behavior' ),

			// Goal type conversion labels.
			'goal_conv_page_visit'   => __( 'Page Visits', 'opti-behavior' ),
			'goal_conv_click'        => __( 'Clicks', 'opti-behavior' ),
			'goal_conv_form_submit'  => __( 'Form Submissions', 'opti-behavior' ),
			'goal_conv_scroll_depth' => __( 'Scroll Conversions', 'opti-behavior' ),
			'goal_conv_time_on_page' => __( 'Time Conversions', 'opti-behavior' ),
			'goal_conv_revenue'      => __( 'Revenue Conversions', 'opti-behavior' ),
			'goal_conv_bounce_rate'  => __( 'Bounce Conversions', 'opti-behavior' ),
			'conversions'            => __( 'Conversions', 'opti-behavior' ),

			// WooCommerce variant modal.
			'woo_modal_control_title' => __( 'Product Info — Control (Original)', 'opti-behavior' ),
			'woo_modal_edit_title'    => __( 'Edit Variant — ', 'opti-behavior' ),
			'close'                   => __( 'Close', 'opti-behavior' ),
			'woo_product_image_alt'   => __( 'Product image', 'opti-behavior' ),
			'woo_field_title'         => __( 'Title', 'opti-behavior' ),
			'woo_ph_title'            => __( 'Original product title', 'opti-behavior' ),
			'woo_field_short_desc'    => __( 'Short description', 'opti-behavior' ),
			'woo_ph_short_desc'       => __( 'Original short description', 'opti-behavior' ),
			'woo_field_regular_price' => __( 'Regular price', 'opti-behavior' ),
			'woo_field_sale_price'    => __( 'Sale price', 'opti-behavior' ),
			'woo_field_cta'           => __( 'Button text (CTA)', 'opti-behavior' ),
			'woo_ph_cta'              => __( 'Original button text', 'opti-behavior' ),
			'woo_save_changes'        => __( 'Save Changes', 'opti-behavior' ),
			'woo_variant_updated'     => __( 'Variant updated successfully.', 'opti-behavior' ),
			'woo_save_failed'         => __( 'Failed to save.', 'opti-behavior' ),
			'network_error'           => __( 'Network error.', 'opti-behavior' ),

			// WooCommerce variant info card.
			'woo_view_product_info'   => __( 'View Product Info', 'opti-behavior' ),
			'woo_view_edit_variant'   => __( 'View / Edit Variant', 'opti-behavior' ),
			'woo_change_title'        => __( 'Title', 'opti-behavior' ),
			'woo_change_description'  => __( 'Description', 'opti-behavior' ),
			'woo_change_price'        => __( 'Price', 'opti-behavior' ),
			'woo_change_sale_price'   => __( 'Sale price', 'opti-behavior' ),
			'woo_change_cta'          => __( 'CTA', 'opti-behavior' ),
			'woo_change_image'        => __( 'Image', 'opti-behavior' ),

			// Goal selector / tabs.
			'goal_primary_suffix'     => __( ' (Primary)', 'opti-behavior' ),
			'primary'                 => __( 'Primary', 'opti-behavior' ),

			// Goal config display (legacy badge area).
			'gc_url_label'       => __( 'URL:', 'opti-behavior' ),
			'gc_selector_label'  => __( 'Selector:', 'opti-behavior' ),
			'gc_form_label'      => __( 'Form:', 'opti-behavior' ),
			'gc_depth_label'     => __( 'Depth:', 'opti-behavior' ),
			'gc_time_label'      => __( 'Time:', 'opti-behavior' ),
			'gc_threshold_label' => __( 'Threshold:', 'opti-behavior' ),

			// Goal configuration card.
			'gcc_type'         => __( 'Type', 'opti-behavior' ),
			'gcc_url'          => __( 'URL', 'opti-behavior' ),
			'gcc_any_page'     => __( 'Any page', 'opti-behavior' ),
			'gcc_selectors'    => __( 'Selectors', 'opti-behavior' ),
			'gcc_selector'     => __( 'Selector', 'opti-behavior' ),
			'gcc_any_click'    => __( 'Any click', 'opti-behavior' ),
			'gcc_form'         => __( 'Form', 'opti-behavior' ),
			'gcc_depth'        => __( 'Depth', 'opti-behavior' ),
			'gcc_depth_value'  => __( '% of the page', 'opti-behavior' ),
			'gcc_time'         => __( 'Time', 'opti-behavior' ),
			'gcc_time_value'   => __( 'seconds on page', 'opti-behavior' ),
			'gcc_threshold'    => __( 'Threshold', 'opti-behavior' ),
			'gcc_seconds'      => __( 'seconds', 'opti-behavior' ),
			'gcc_source'       => __( 'Source', 'opti-behavior' ),
			'gcc_source_value' => __( 'All WooCommerce orders attributed to this test', 'opti-behavior' ),
			'gcc_mode'         => __( 'Mode', 'opti-behavior' ),
			'gcc_inverse'      => __( 'Inverse', 'opti-behavior' ),
			'gcc_inverse_desc' => __( '(session longer than threshold = conversion)', 'opti-behavior' ),
			'gcc_product'      => __( 'Product', 'opti-behavior' ),
			'gcc_test_product' => __( 'Test product', 'opti-behavior' ),

			// Significance hints.
			'sig_reached'    => __( 'Statistical significance reached! The result is reliable.', 'opti-behavior' ),
			/* translators: 1: current confidence percentage, 2: remaining percentage points, 3: target threshold percentage. */
			'sig_progress'   => __( 'Current confidence: %1$s%. Need %2$s more percentage points to reach %3$s% significance threshold.', 'opti-behavior' ),
			'sig_collecting' => __( 'Collecting data — more visitors are needed to reach statistical significance.', 'opti-behavior' ),

			// Variant result cards.
			'pp_vs_control_suffix' => __( ' pp vs control', 'opti-behavior' ),
			'pp_suffix'            => __( ' pp', 'opti-behavior' ),
			'no_difference'        => __( 'No difference', 'opti-behavior' ),
			'vs_control_suffix'    => __( '% vs control', 'opti-behavior' ),
			'baseline'             => __( 'Baseline', 'opti-behavior' ),
			'original_suffix'      => __( '(original)', 'opti-behavior' ),
			'visitors'             => __( 'Visitors', 'opti-behavior' ),
			'ci_95'                => __( '95% CI', 'opti-behavior' ),
			'p_value'              => __( 'p-value', 'opti-behavior' ),
			'vs_badge'             => __( 'VS', 'opti-behavior' ),
			'winner'               => __( 'Winner', 'opti-behavior' ),
			'chart_variant_prefix' => __( 'Variant ', 'opti-behavior' ),

			// Winner action toasts.
			'winner_declared'         => __( 'Winner declared!', 'opti-behavior' ),
			'applied_winner_disabled' => __( 'Applied winner disabled.', 'opti-behavior' ),

			// Apply-winner diff dialog.
			'diff_edit_link'        => __( 'Edit ↗', 'opti-behavior' ),
			'diff_changes_prefix'   => __( 'Changes will apply to ', 'opti-behavior' ),
			'diff_no_field_changes' => __( 'No field changes detected.', 'opti-behavior' ),
			'diff_no_change_badge'  => __( 'no change', 'opti-behavior' ),
			'diff_title'            => __( 'Apply Winner Permanently — Review Changes', 'opti-behavior' ),
			'diff_th_field'         => __( 'Field', 'opti-behavior' ),
			'diff_th_before'        => __( 'Before', 'opti-behavior' ),
			'diff_th_after'         => __( 'After', 'opti-behavior' ),
			'diff_snapshot_note'    => __( 'A snapshot will be saved automatically — you’ll be able to revert in the next update.', 'opti-behavior' ),
			'diff_commit'           => __( 'Review & Commit', 'opti-behavior' ),
			/* translators: %s: number of fields updated. */
			'diff_applied_one'      => __( 'Applied — %s field updated.', 'opti-behavior' ),
			/* translators: %s: number of fields updated. */
			'diff_applied_many'     => __( 'Applied — %s fields updated.', 'opti-behavior' ),
			'diff_winner_applied'   => __( 'Winner applied permanently.', 'opti-behavior' ),

			// Diff field labels.
			'diff_field_title'             => __( 'Title', 'opti-behavior' ),
			'diff_field_content'           => __( 'Content', 'opti-behavior' ),
			'diff_field_excerpt'           => __( 'Excerpt', 'opti-behavior' ),
			'diff_field_short_description' => __( 'Short Description', 'opti-behavior' ),
			'diff_field_full_description'  => __( 'Full Description', 'opti-behavior' ),
			'diff_field_regular_price'     => __( 'Regular Price', 'opti-behavior' ),
			'diff_field_sale_price'        => __( 'Sale Price', 'opti-behavior' ),
			'diff_field_price'             => __( 'Price', 'opti-behavior' ),
			'diff_field_image_id'          => __( 'Featured Image', 'opti-behavior' ),
			'diff_field_gallery_image_ids' => __( 'Gallery Images', 'opti-behavior' ),
			'diff_field_add_to_cart_text'  => __( 'Add to Cart Text', 'opti-behavior' ),
			'diff_field_element_changes'   => __( 'Visual Changes', 'opti-behavior' ),
			'diff_field_redirect'          => __( 'Redirect URL', 'opti-behavior' ),

			// Diff type labels.
			'diff_type_headline'        => __( 'Headline Test', 'opti-behavior' ),
			'diff_type_element'         => __( 'Element Test', 'opti-behavior' ),
			'diff_type_page_split'      => __( 'Page Split Test', 'opti-behavior' ),
			'diff_type_woocommerce'     => __( 'WooCommerce Product', 'opti-behavior' ),
			'diff_type_generic_suffix'  => __( ' Test', 'opti-behavior' ),
			'diff_type_ab'              => __( 'A/B Test', 'opti-behavior' ),

			// Diff type-aware sentences.
			'diff_sentence_default_name' => __( 'the winning variant', 'opti-behavior' ),
			/* translators: %s: product or page name. */
			'diff_sentence_woocommerce'  => __( 'This will update the product page content (title, price, image, CTA) for “%s” permanently.', 'opti-behavior' ),
			/* translators: %s: product or page name. */
			'diff_sentence_page_split'   => __( 'This will register a permanent 301 redirect from the control URL to the winning page for “%s”.', 'opti-behavior' ),
			/* translators: %s: product or page name. */
			'diff_sentence_element'      => __( 'This will permanently apply the winning variant’s visual changes to “%s”.', 'opti-behavior' ),
			/* translators: %s: product or page name. */
			'diff_sentence_default'      => __( 'This will permanently apply the winning variant content to “%s”.', 'opti-behavior' ),

			// Diff value rendering.
			'diff_empty'     => __( '(empty)', 'opti-behavior' ),
			'diff_none'      => __( '(none)', 'opti-behavior' ),
			'diff_show_full' => __( 'Show full', 'opti-behavior' ),
			'diff_show_less' => __( 'Show less', 'opti-behavior' ),

			// Test list cards / pagination.
			'untitled_test'             => __( 'Untitled Test', 'opti-behavior' ),
			'tooltip_winning_variant'   => __( 'Winning variant', 'opti-behavior' ),
			'winner_prefix'             => __( 'Winner: ', 'opti-behavior' ),
			'started_prefix'            => __( 'Started: ', 'opti-behavior' ),
			'ended_prefix'              => __( 'Ended: ', 'opti-behavior' ),
			'stat_impressions'          => __( 'Impressions', 'opti-behavior' ),
			'stat_primary_conversions'  => __( 'Primary Conversions', 'opti-behavior' ),
			'tooltip_primary_rate'      => __( 'Primary-goal conversion rate (primary conversions / impressions)', 'opti-behavior' ),
			'stat_primary_rate'         => __( 'Primary Rate', 'opti-behavior' ),
			'tooltip_all_goal_conv'     => __( 'All goal conversions can exceed impressions when a visitor completes multiple goals.', 'opti-behavior' ),
			'stat_all_goal_conv'        => __( 'All-Goal Conv.', 'opti-behavior' ),
			'card_edit'                 => __( 'Edit', 'opti-behavior' ),
			'card_start'                => __( 'Start', 'opti-behavior' ),
			'card_results'              => __( 'Results', 'opti-behavior' ),
			'card_pause'                => __( 'Pause', 'opti-behavior' ),
			'card_resume'               => __( 'Resume', 'opti-behavior' ),
			'tooltip_duplicate'         => __( 'Duplicate', 'opti-behavior' ),
			'tooltip_delete'            => __( 'Delete', 'opti-behavior' ),
			/* translators: 1: range start, 2: range end, 3: total count. */
			'pagination_info'           => __( 'Showing %1$s–%2$s of %3$s tests', 'opti-behavior' ),
			'action_done'               => __( 'Done!', 'opti-behavior' ),
		);
	}

	/**
	 * Resolve spam exclusion state from request input, falling back to Traffic Behavior settings.
	 *
	 * @param array|null $source Optional request source.
	 * @return bool
	 */
	private function resolve_spam_exclusion_from_request( $source = null ) {
		if ( null === $source ) {
			$source = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
		}

		if ( is_array( $source ) && array_key_exists( 'exclude_spam', $source ) ) {
			return '1' === (string) sanitize_text_field( wp_unslash( $source['exclude_spam'] ) );
		}

		$settings = get_option( 'opti_behavior_traffic_settings', array( 'spam_detection_enabled' => true ) );
		if ( ! is_array( $settings ) ) {
			return true;
		}

		return ! array_key_exists( 'spam_detection_enabled', $settings ) || ! empty( $settings['spam_detection_enabled'] );
	}

	/**
	 * Route to the appropriate view (list, builder, results).
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'opti-behavior' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view routing
		$view    = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';
		$test_id = isset( $_GET['test_id'] ) ? absint( $_GET['test_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div id="opti-ab-app" class="wrap opti-ab-wrap">';

		switch ( $view ) {
			case 'builder':
				$this->render_builder( $test_id );
				break;

			case 'results':
				$this->render_results( $test_id );
				break;

			default:
				$this->render_test_list();
				break;
		}

		$this->render_confirm_modal();

		echo '</div>';
		// Hide default WP page title inserted above our custom header.
		echo '<style>.opti-ab-wrap > h1:first-child:not(.opti-ab-page-header__title):not(.opti-ab-subpage-header__title) { display:none !important; }</style>';
	}

	/**
	 * Render the shared admin confirmation modal.
	 *
	 * The modal is populated by JavaScript for destructive actions such as
	 * deleting or stopping tests, so every A/B Testing view needs this markup.
	 */
	private function render_confirm_modal() {
		?>
		<!-- Confirm Modal (hidden by default, shown via JS) -->
		<div id="opti-ab-confirm-modal" class="opti-ab-confirm-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="opti-ab-modal-title" aria-describedby="opti-ab-modal-message">
			<div class="opti-ab-confirm-modal">
				<div class="opti-ab-confirm-modal__icon">
					<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
				</div>
				<h2 id="opti-ab-modal-title" class="opti-ab-confirm-modal__title"><?php esc_html_e( 'Delete A/B Test', 'opti-behavior' ); ?></h2>
				<p id="opti-ab-modal-message" class="opti-ab-confirm-modal__message"><?php esc_html_e( 'Are you sure you want to delete this test? This action cannot be undone.', 'opti-behavior' ); ?></p>
				<div class="opti-ab-confirm-modal__actions">
					<button type="button" id="opti-ab-modal-cancel" class="opti-ab-confirm-modal__btn-cancel"><?php esc_html_e( 'Cancel', 'opti-behavior' ); ?></button>
					<button type="button" id="opti-ab-modal-confirm" class="opti-ab-confirm-modal__btn-danger"><?php esc_html_e( 'Delete', 'opti-behavior' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// View: Test List
	// =========================================================================

	/**
	 * Render the test list dashboard.
	 */
	private function render_test_list() {
		$free_limits = get_option(
			'opti_behavior_ab_free_limits',
			array(
				'max_concurrent_tests'  => 3,
				'max_variants_per_test' => 3,
				'max_goals_per_test'    => 1,
			)
		);

		$is_pro                     = function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active();
		$opti_behavior_ab_tooltips  = function_exists( 'opti_behavior_get_ab_testing_tooltips' ) ? opti_behavior_get_ab_testing_tooltips() : array();
		$exclude_spam               = $this->resolve_spam_exclusion_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
		?>
		<!-- Purple gradient header matching Heatmaps / Funnels -->
		<div class="opti-ab-page-header">
			<div class="opti-ab-page-header__content">
				<div class="opti-ab-page-header__title-section">
					<div class="opti-ab-page-header__icon">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/><path d="M16.5 21.5 12 17"/></svg>
					</div>
					<div>
						<h1 class="opti-ab-page-header__title">
							<?php esc_html_e( 'A/B Testing', 'opti-behavior' ); ?>
							<?php if ( ! empty( $opti_behavior_ab_tooltips['ab_testing_heading'] ) ) : ?>
							<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['ab_testing_heading']['title'], $opti_behavior_ab_tooltips['ab_testing_heading']['content'], $opti_behavior_ab_tooltips['ab_testing_heading']['simple'], '', array( 'position' => 'right' ) ); ?>
							<?php endif; ?>
						</h1>
						<p class="opti-ab-page-header__subtitle"><?php esc_html_e( 'Create and manage split tests to optimize your pages.', 'opti-behavior' ); ?></p>
					</div>
				</div>
				<div class="opti-ab-page-header__actions">
					<button type="button" class="filter-btn opti-ab-exclude-spam <?php echo $exclude_spam ? 'active' : ''; ?>" id="opti-ab-exclude-spam-toggle" aria-pressed="<?php echo esc_attr( $exclude_spam ? 'true' : 'false' ); ?>" aria-label="<?php esc_attr_e( 'Exclude spam traffic', 'opti-behavior' ); ?>" title="<?php esc_attr_e( 'Exclude spam traffic from A/B testing analytics', 'opti-behavior' ); ?>">
						<span class="filter-icon"><i data-lucide="<?php echo esc_attr( $exclude_spam ? 'shield-check' : 'shield-off' ); ?>" aria-hidden="true"></i></span>
						<span class="filter-label"><?php esc_html_e( 'Exclude Spam', 'opti-behavior' ); ?></span>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=builder' ) ); ?>" class="opti-ab-btn-create">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
						<?php esc_html_e( 'New Test', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['new_test_btn'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['new_test_btn']['title'], $opti_behavior_ab_tooltips['new_test_btn']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
						<?php endif; ?>
					</a>
				</div>
			</div>
		</div>
		<div class="opti-ab-content">

		<!-- Quick Stats Bar -->
		<div class="opti-ab-stats-bar" id="opti-ab-stats-bar">
			<div class="opti-ab-stat-card">
				<div class="opti-ab-stat-card__icon opti-ab-stat-card__icon--running">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>
				</div>
				<div class="opti-ab-stat-card__body">
					<span class="opti-ab-stat-card__value" id="opti-ab-stat-running">—</span>
					<span class="opti-ab-stat-card__label">
						<?php esc_html_e( 'Running', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['stat_running'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['stat_running']['title'], $opti_behavior_ab_tooltips['stat_running']['content'], '', '', array( 'position' => 'top' ) ); ?>
						<?php endif; ?>
					</span>
				</div>
			</div>
			<div class="opti-ab-stat-card">
				<div class="opti-ab-stat-card__icon opti-ab-stat-card__icon--draft">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/></svg>
				</div>
				<div class="opti-ab-stat-card__body">
					<span class="opti-ab-stat-card__value" id="opti-ab-stat-draft">—</span>
					<span class="opti-ab-stat-card__label">
						<?php esc_html_e( 'Drafts', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['stat_drafts'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['stat_drafts']['title'], $opti_behavior_ab_tooltips['stat_drafts']['content'], '', '', array( 'position' => 'top' ) ); ?>
						<?php endif; ?>
					</span>
				</div>
			</div>
			<div class="opti-ab-stat-card">
				<div class="opti-ab-stat-card__icon opti-ab-stat-card__icon--completed">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>
				</div>
				<div class="opti-ab-stat-card__body">
					<span class="opti-ab-stat-card__value" id="opti-ab-stat-completed">—</span>
					<span class="opti-ab-stat-card__label">
						<?php esc_html_e( 'Completed', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['stat_completed'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['stat_completed']['title'], $opti_behavior_ab_tooltips['stat_completed']['content'], '', '', array( 'position' => 'top' ) ); ?>
						<?php endif; ?>
					</span>
				</div>
			</div>
			<div class="opti-ab-stat-card">
				<div class="opti-ab-stat-card__icon opti-ab-stat-card__icon--impressions">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
				</div>
				<div class="opti-ab-stat-card__body">
					<span class="opti-ab-stat-card__value" id="opti-ab-stat-impressions">—</span>
					<span class="opti-ab-stat-card__label">
						<?php esc_html_e( 'Total Impressions', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['stat_impressions'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['stat_impressions']['title'], $opti_behavior_ab_tooltips['stat_impressions']['content'], '', '', array( 'position' => 'top' ) ); ?>
						<?php endif; ?>
					</span>
				</div>
			</div>
		</div>

		<!-- Filters -->
		<div class="opti-ab-filters">
			<div class="opti-ab-filters__left">
				<div class="opti-ab-filter-wrap">
					<?php if ( ! empty( $opti_behavior_ab_tooltips['filter_status'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['filter_status']['title'], $opti_behavior_ab_tooltips['filter_status']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
					<select id="opti-ab-filter-status" class="opti-ab-select">
						<option value=""><?php esc_html_e( 'All Statuses', 'opti-behavior' ); ?></option>
						<option value="draft"><?php esc_html_e( 'Draft', 'opti-behavior' ); ?></option>
						<option value="running"><?php esc_html_e( 'Running', 'opti-behavior' ); ?></option>
						<option value="paused"><?php esc_html_e( 'Paused', 'opti-behavior' ); ?></option>
						<option value="completed"><?php esc_html_e( 'Completed', 'opti-behavior' ); ?></option>
						<option value="archived"><?php esc_html_e( 'Archived', 'opti-behavior' ); ?></option>
					</select>
				</div>
				<div class="opti-ab-filter-wrap">
					<?php if ( ! empty( $opti_behavior_ab_tooltips['filter_type'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['filter_type']['title'], $opti_behavior_ab_tooltips['filter_type']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
					<select id="opti-ab-filter-type" class="opti-ab-select">
						<option value=""><?php esc_html_e( 'All Types', 'opti-behavior' ); ?></option>
						<option value="page_split"><?php esc_html_e( 'Page Split', 'opti-behavior' ); ?></option>
						<option value="element"><?php esc_html_e( 'Element', 'opti-behavior' ); ?></option>
						<?php if ( $is_pro ) : ?>
						<option value="woocommerce"><?php esc_html_e( 'WooCommerce', 'opti-behavior' ); ?></option>
						<?php endif; ?>
					</select>
				</div>
			</div>
			<div class="opti-ab-filters__right">
				<div class="opti-ab-search-wrapper">
					<span class="opti-ab-search-icon">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
					</span>
					<input type="text" id="opti-ab-search" class="opti-ab-input" placeholder="<?php esc_attr_e( 'Search tests…', 'opti-behavior' ); ?>">
					<?php if ( ! empty( $opti_behavior_ab_tooltips['search_tests'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['search_tests']['title'], $opti_behavior_ab_tooltips['search_tests']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Test List -->
		<div id="opti-ab-test-list" class="opti-ab-test-list">
			<div class="opti-ab-loading">
				<div class="opti-ab-spinner"></div>
				<p><?php esc_html_e( 'Loading tests…', 'opti-behavior' ); ?></p>
			</div>
		</div>

		<!-- Pagination -->
		<div id="opti-ab-pagination" class="opti-ab-pagination"></div>

		<!-- Empty State (hidden by default) -->
		<div id="opti-ab-empty-state" class="opti-ab-empty-state" style="display:none;">
			<div class="opti-ab-empty-state__icon">
				<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/><path d="M16.5 21.5 12 17"/></svg>
			</div>
			<h2><?php esc_html_e( 'No A/B Tests Yet', 'opti-behavior' ); ?></h2>
			<p><?php esc_html_e( 'Create your first test to start optimizing your pages with data-driven decisions.', 'opti-behavior' ); ?></p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&view=builder' ) ); ?>" class="button button-primary button-hero">
				<?php esc_html_e( 'Create Your First Test', 'opti-behavior' ); ?>
			</a>
		</div>

		<?php
		// Free plan limits notice.
		if ( ! $is_pro ) :
			?>
		<div class="opti-ab-limits-notice">
			<p>
				<?php if ( ! empty( $opti_behavior_ab_tooltips['free_limits_notice'] ) ) : ?>
				<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['free_limits_notice']['title'], $opti_behavior_ab_tooltips['free_limits_notice']['content'], '', '', array( 'position' => 'top' ) ); ?>
				<?php endif; ?>
				<?php
				printf(
					/* translators: %1$d: max concurrent tests, %2$d: max variants, %3$d: max goals */
					esc_html__( 'Free plan: up to %1$d concurrent tests, %2$d variants per test, %3$d goal per test.', 'opti-behavior' ),
					intval( $free_limits['max_concurrent_tests'] ),
					intval( $free_limits['max_variants_per_test'] ),
					intval( $free_limits['max_goals_per_test'] )
				);
				?>
				<a href="https://opti-behavior.com/pro" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro for unlimited tests →', 'opti-behavior' ); ?></a>
			</p>
		</div>
		<?php endif; ?>
		</div><!-- /.opti-ab-content -->

		<?php
	}

	// =========================================================================
	// View: Builder Wizard
	// =========================================================================

	/**
	 * Build normalized metadata for a preselected Element target.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	private function get_ab_target_picker_selected_target( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return null;
		}

		$post_type_object = get_post_type_object( $post->post_type );
		$type_label       = $post_type_object && ! empty( $post_type_object->labels->singular_name ) ? $post_type_object->labels->singular_name : $post->post_type;
		$title            = get_the_title( $post );
		$permalink        = get_permalink( $post );

		return array(
			'id'         => (int) $post->ID,
			'title'      => '' !== $title ? $title : __( '(no title)', 'opti-behavior' ),
			'post_type'  => $post->post_type,
			'type_label' => $type_label,
			'url'        => $permalink ? $permalink : '',
			'status'     => $post->post_status,
		);
	}

	/**
	 * Build same-site quick targets for Element URL mode.
	 *
	 * @return array[]
	 */
	private function get_ab_target_picker_quick_targets() {
		$quick_targets = array(
			array(
				'id'          => 'home',
				'label'       => __( 'Homepage', 'opti-behavior' ),
				'description' => __( 'Use the site home URL.', 'opti-behavior' ),
				'url'         => home_url( '/' ),
			),
		);

		$posts_page_id = absint( get_option( 'page_for_posts' ) );

		if ( $posts_page_id ) {
			$posts_page_url = get_permalink( $posts_page_id );

			if ( $posts_page_url && untrailingslashit( $posts_page_url ) !== untrailingslashit( home_url( '/' ) ) ) {
				$quick_targets[] = array(
					'id'          => 'posts_page',
					'label'       => __( 'Posts Page', 'opti-behavior' ),
					'description' => __( 'Use the configured blog posts page.', 'opti-behavior' ),
					'url'         => $posts_page_url,
				);
			}
		}

		return $quick_targets;
	}

	/**
	 * Render the test builder wizard.
	 *
	 * @param int $test_id Test ID (0 for new test).
	 */
	private function render_builder( $test_id = 0 ) {
		$test     = null;
		$variants = array();
		$goals    = array();

		if ( $test_id ) {
			$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
			if ( $test ) {
				$variants = Opti_Behavior_AB_Test_Database::get_variants( $test_id );
				$goals    = Opti_Behavior_AB_Test_Database::get_goals( $test_id );
			}
		}

		$is_pro                    = function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active();
		$opti_behavior_ab_tooltips = function_exists( 'opti_behavior_get_ab_testing_tooltips' ) ? opti_behavior_get_ab_testing_tooltips() : array();
		$opti_ab_target_post_id    = $test ? absint( $test->target_post_id ) : 0;
		$opti_ab_saved_target_url  = $test ? (string) $test->target_url : '';
		$opti_ab_selected_target   = $this->get_ab_target_picker_selected_target( $opti_ab_target_post_id );
		$opti_ab_is_element_test   = $test && 'element' === $test->test_type;
		$opti_ab_target_url_value  = $opti_ab_saved_target_url;
		$opti_ab_target_mode       = $opti_ab_selected_target ? 'content' : ( $opti_ab_saved_target_url ? 'url' : 'content' );
		$opti_ab_quick_targets     = $this->get_ab_target_picker_quick_targets();

		if ( $opti_ab_is_element_test && '' === $opti_ab_target_url_value && $opti_ab_selected_target && ! empty( $opti_ab_selected_target['url'] ) ) {
			$opti_ab_target_url_value = $opti_ab_selected_target['url'];
		}
		?>
		<!-- Purple gradient header for builder -->
		<div class="opti-ab-subpage-header">
			<div class="opti-ab-subpage-header__content">
				<div class="opti-ab-subpage-header__left">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="opti-ab-back-link">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
						<?php esc_html_e( 'Back to Tests', 'opti-behavior' ); ?>
					</a>
					<h1 class="opti-ab-subpage-header__title">
						<?php echo $test_id ? esc_html__( 'Edit Test', 'opti-behavior' ) : esc_html__( 'Create New Test', 'opti-behavior' ); ?>
					</h1>
				</div>
			</div>
		</div>
		<div class="opti-ab-content">

		<!-- Wizard Steps Indicator -->
		<div class="opti-ab-wizard-steps">
			<div class="opti-ab-wizard-step active" data-step="1">
				<span class="opti-ab-wizard-step__number">1</span>
				<span class="opti-ab-wizard-step__label">
					<?php esc_html_e( 'Type', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['step_type_heading'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['step_type_heading']['title'], $opti_behavior_ab_tooltips['step_type_heading']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
				</span>
			</div>
			<div class="opti-ab-wizard-step" data-step="2">
				<span class="opti-ab-wizard-step__number">2</span>
				<span class="opti-ab-wizard-step__label">
					<?php esc_html_e( 'Target', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['step_target_heading'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['step_target_heading']['title'], $opti_behavior_ab_tooltips['step_target_heading']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
				</span>
			</div>
			<div class="opti-ab-wizard-step" data-step="3">
				<span class="opti-ab-wizard-step__number">3</span>
				<span class="opti-ab-wizard-step__label">
					<?php esc_html_e( 'Variants', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['step_variants_heading'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['step_variants_heading']['title'], $opti_behavior_ab_tooltips['step_variants_heading']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
				</span>
			</div>
			<div class="opti-ab-wizard-step" data-step="4">
				<span class="opti-ab-wizard-step__number">4</span>
				<span class="opti-ab-wizard-step__label">
					<?php esc_html_e( 'Goals', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['step_goals_heading'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['step_goals_heading']['title'], $opti_behavior_ab_tooltips['step_goals_heading']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
				</span>
			</div>
			<div class="opti-ab-wizard-step" data-step="5">
				<span class="opti-ab-wizard-step__number">5</span>
				<span class="opti-ab-wizard-step__label">
					<?php esc_html_e( 'Settings', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['step_settings_heading'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['step_settings_heading']['title'], $opti_behavior_ab_tooltips['step_settings_heading']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
				</span>
			</div>
			<div class="opti-ab-wizard-step" data-step="6">
				<span class="opti-ab-wizard-step__number">6</span>
				<span class="opti-ab-wizard-step__label">
					<?php esc_html_e( 'Review', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['step_review_heading'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['step_review_heading']['title'], $opti_behavior_ab_tooltips['step_review_heading']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
				</span>
			</div>
		</div>

		<!-- Wizard Container -->
		<div id="opti-ab-wizard" class="opti-ab-wizard"
			data-test-id="<?php echo esc_attr( $test_id ); ?>"
			data-test="<?php echo $test ? esc_attr( wp_json_encode( $test ) ) : ''; ?>"
			data-variants="<?php echo esc_attr( wp_json_encode( $variants ) ); ?>"
			data-goals="<?php echo esc_attr( wp_json_encode( $goals ) ); ?>">

			<!-- Step 1: Test Type -->
			<div class="opti-ab-wizard-panel active" data-step="1">
				<h2><?php esc_html_e( 'Choose Test Type', 'opti-behavior' ); ?></h2>
				<p class="opti-ab-wizard-panel__desc"><?php esc_html_e( 'Select the type of A/B test you want to run.', 'opti-behavior' ); ?></p>
				<div class="opti-ab-type-grid">
					<label class="opti-ab-type-card" data-type="page_split">
						<input type="radio" name="test_type" value="page_split" <?php checked( $test && 'page_split' === $test->test_type ); ?>>
						<div class="opti-ab-type-card__icon">
							<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 3v18"/></svg>
						</div>
						<strong><?php esc_html_e( 'Page Split', 'opti-behavior' ); ?></strong>
						<span><?php esc_html_e( 'Redirect visitors to different page URLs.', 'opti-behavior' ); ?></span>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['type_page_split'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['type_page_split']['title'], $opti_behavior_ab_tooltips['type_page_split']['content'], '', '', array( 'position' => 'top' ) ); ?>
						<?php endif; ?>
					</label>
					<label class="opti-ab-type-card" data-type="element">
						<input type="radio" name="test_type" value="element" <?php checked( $test && 'element' === $test->test_type ); ?>>
						<div class="opti-ab-type-card__icon">
							<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
						</div>
						<strong><?php esc_html_e( 'Element', 'opti-behavior' ); ?></strong>
						<span><?php esc_html_e( 'Modify any DOM element via visual editor.', 'opti-behavior' ); ?></span>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['type_element'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['type_element']['title'], $opti_behavior_ab_tooltips['type_element']['content'], '', '', array( 'position' => 'top' ) ); ?>
						<?php endif; ?>
					</label>
					<label class="opti-ab-type-card<?php echo ! $is_pro ? ' opti-ab-type-card--pro' : ''; ?>" data-type="woocommerce">
						<input type="radio" name="test_type" value="woocommerce" <?php disabled( ! $is_pro ); ?> <?php checked( $test && 'woocommerce' === $test->test_type ); ?>>
						<div class="opti-ab-type-card__icon">
							<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
						</div>
						<strong><?php esc_html_e( 'WooCommerce', 'opti-behavior' ); ?></strong>
						<span><?php esc_html_e( 'Product pages, pricing, CTAs.', 'opti-behavior' ); ?></span>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['type_woocommerce'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['type_woocommerce']['title'], $opti_behavior_ab_tooltips['type_woocommerce']['content'], $opti_behavior_ab_tooltips['type_woocommerce']['simple'], '', array( 'position' => 'top', 'pro' => true ) ); ?>
						<?php endif; ?>
						<?php if ( ! $is_pro ) : ?>
						<span class="opti-ab-pro-badge">PRO</span>
						<?php endif; ?>
					</label>
				</div>
			</div>

			<!-- Step 2: Target -->
			<div class="opti-ab-wizard-panel" data-step="2">
				<h2 id="opti-ab-step2-title"><?php esc_html_e( 'Select Target', 'opti-behavior' ); ?></h2>
				<p class="opti-ab-wizard-panel__desc" id="opti-ab-step2-desc"><?php esc_html_e( 'Choose which page or post this test applies to.', 'opti-behavior' ); ?></p>
				<div class="opti-ab-form-group">
					<label for="opti-ab-test-name">
						<?php esc_html_e( 'Test Name', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['test_name'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['test_name']['title'], $opti_behavior_ab_tooltips['test_name']['content'], '', '', array( 'position' => 'right' ) ); ?>
						<?php endif; ?>
					</label>
					<input type="text" id="opti-ab-test-name" class="opti-ab-input opti-ab-input--full"
						value="<?php echo $test ? esc_attr( $test->name ) : ''; ?>"
						placeholder="<?php esc_attr_e( 'e.g., Homepage Hero CTA Test', 'opti-behavior' ); ?>">
				</div>
				<div class="opti-ab-form-group" id="opti-ab-target-url-group">
					<label for="opti-ab-target-url">
						<?php esc_html_e( 'Target URL', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['target_url'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['target_url']['title'], $opti_behavior_ab_tooltips['target_url']['content'], '', '', array( 'position' => 'right' ) ); ?>
						<?php endif; ?>
					</label>
					<input type="url" id="opti-ab-target-url" class="opti-ab-input opti-ab-input--full"
						value="<?php echo esc_attr( $opti_ab_target_url_value ); ?>"
						placeholder="<?php echo esc_attr( home_url( '/your-page/' ) ); ?>">
					<p class="opti-ab-help-text"><?php esc_html_e( 'The original page where the test runs.', 'opti-behavior' ); ?></p>
				</div>
				<div class="opti-ab-form-group opti-ab-target-picker-group" id="opti-ab-target-post-group" style="display:none;">
					<div
						id="opti-ab-element-target-picker"
						class="opti-ab-target-picker"
						data-initial-mode="<?php echo esc_attr( $opti_ab_target_mode ); ?>"
						data-url-field="opti-ab-target-url"
						data-post-field="opti-ab-target-post"
						data-selected-target="<?php echo esc_attr( wp_json_encode( $opti_ab_selected_target ) ); ?>"
						data-quick-targets="<?php echo esc_attr( wp_json_encode( $opti_ab_quick_targets ) ); ?>">

						<div class="opti-ab-target-picker__header">
							<div>
								<span class="opti-ab-target-picker__label">
									<?php esc_html_e( 'Element Target', 'opti-behavior' ); ?>
									<?php if ( ! empty( $opti_behavior_ab_tooltips['target_post'] ) ) : ?>
									<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['target_post']['title'], $opti_behavior_ab_tooltips['target_post']['content'], '', '', array( 'position' => 'right' ) ); ?>
									<?php endif; ?>
								</span>
								<p class="opti-ab-help-text"><?php esc_html_e( 'Choose a WordPress page/post or enter a same-site URL for archives, the homepage, or custom routes.', 'opti-behavior' ); ?></p>
							</div>
						</div>

						<input type="hidden" id="opti-ab-target-source" value="<?php echo esc_attr( $opti_ab_target_mode ); ?>">
						<input type="hidden" id="opti-ab-target-label" value="<?php echo esc_attr( $opti_ab_selected_target ? $opti_ab_selected_target['title'] : $opti_ab_saved_target_url ); ?>">
						<select id="opti-ab-target-post" class="opti-ab-select opti-ab-target-picker__compat-select" aria-hidden="true" tabindex="-1" style="display:none;">
							<option value=""><?php esc_html_e( '- Select -', 'opti-behavior' ); ?></option>
							<?php if ( $opti_ab_selected_target ) : ?>
								<option
									value="<?php echo esc_attr( $opti_ab_selected_target['id'] ); ?>"
									data-url="<?php echo esc_attr( $opti_ab_selected_target['url'] ); ?>"
									data-title="<?php echo esc_attr( $opti_ab_selected_target['title'] ); ?>"
									data-post-type="<?php echo esc_attr( $opti_ab_selected_target['post_type'] ); ?>"
									data-type-label="<?php echo esc_attr( $opti_ab_selected_target['type_label'] ); ?>"
									data-status="<?php echo esc_attr( $opti_ab_selected_target['status'] ); ?>"
									selected>
									<?php echo esc_html( $opti_ab_selected_target['title'] ); ?>
									(<?php echo esc_html( $opti_ab_selected_target['type_label'] ); ?>)
								</option>
							<?php endif; ?>
						</select>

						<div class="opti-ab-target-picker__modes" role="tablist" aria-label="<?php esc_attr_e( 'Element target mode', 'opti-behavior' ); ?>">
							<button
								type="button"
								class="button opti-ab-target-picker__mode<?php echo 'content' === $opti_ab_target_mode ? ' is-active' : ''; ?>"
								id="opti-ab-target-mode-content"
								role="tab"
								aria-selected="<?php echo 'content' === $opti_ab_target_mode ? 'true' : 'false'; ?>"
								aria-controls="opti-ab-target-content-panel"
								data-target-mode="content">
								<?php esc_html_e( 'WordPress Content', 'opti-behavior' ); ?>
							</button>
							<button
								type="button"
								class="button opti-ab-target-picker__mode<?php echo 'url' === $opti_ab_target_mode ? ' is-active' : ''; ?>"
								id="opti-ab-target-mode-url"
								role="tab"
								aria-selected="<?php echo 'url' === $opti_ab_target_mode ? 'true' : 'false'; ?>"
								aria-controls="opti-ab-target-url-panel"
								data-target-mode="url">
								<?php esc_html_e( 'URL', 'opti-behavior' ); ?>
							</button>
						</div>

						<div
							class="opti-ab-target-picker__panel opti-ab-target-picker__panel--content"
							id="opti-ab-target-content-panel"
							role="tabpanel"
							aria-labelledby="opti-ab-target-mode-content"
							<?php echo 'content' === $opti_ab_target_mode ? '' : 'style="display:none;"'; ?>>
							<div
								id="opti-ab-target-selected"
								class="opti-ab-target-picker__selected"
								<?php echo $opti_ab_selected_target ? '' : 'style="display:none;"'; ?>>
								<div class="opti-ab-target-picker__selected-body">
									<span class="opti-ab-target-picker__selected-label"><?php esc_html_e( 'Selected target', 'opti-behavior' ); ?></span>
									<h4 id="opti-ab-target-selected-title"><?php echo $opti_ab_selected_target ? esc_html( $opti_ab_selected_target['title'] ) : ''; ?></h4>
									<div class="opti-ab-target-picker__selected-meta">
										<span id="opti-ab-target-selected-type"><?php echo $opti_ab_selected_target ? esc_html( $opti_ab_selected_target['type_label'] ) : ''; ?></span>
										<span id="opti-ab-target-selected-status"><?php echo $opti_ab_selected_target && ! empty( $opti_ab_selected_target['status'] ) ? esc_html( $opti_ab_selected_target['status'] ) : ''; ?></span>
									</div>
									<a
										id="opti-ab-target-selected-url"
										href="<?php echo $opti_ab_selected_target && ! empty( $opti_ab_selected_target['url'] ) ? esc_url( $opti_ab_selected_target['url'] ) : '#'; ?>"
										target="_blank"
										rel="noopener">
										<?php echo $opti_ab_selected_target && ! empty( $opti_ab_selected_target['url'] ) ? esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $opti_ab_selected_target['url'] ) ) ) : ''; ?>
									</a>
								</div>
								<div class="opti-ab-target-picker__selected-actions">
									<button type="button" class="button" id="opti-ab-target-change"><?php esc_html_e( 'Change', 'opti-behavior' ); ?></button>
									<button type="button" class="button-link-delete" id="opti-ab-target-clear"><?php esc_html_e( 'Clear', 'opti-behavior' ); ?></button>
								</div>
							</div>

							<div id="opti-ab-target-search-wrap" class="opti-ab-target-picker__search">
								<label for="opti-ab-target-search" class="screen-reader-text"><?php esc_html_e( 'Search WordPress content', 'opti-behavior' ); ?></label>
								<input
									type="search"
									id="opti-ab-target-search"
									class="opti-ab-input opti-ab-input--full"
									autocomplete="off"
									placeholder="<?php esc_attr_e( 'Search pages, posts, products, and public content...', 'opti-behavior' ); ?>">
								<p class="opti-ab-help-text"><?php esc_html_e( 'Search is paginated so large sites are not preloaded into this builder.', 'opti-behavior' ); ?></p>
							</div>

							<div id="opti-ab-target-search-status" class="opti-ab-target-picker__status" aria-live="polite"></div>
							<div id="opti-ab-target-results" class="opti-ab-target-picker__results" role="listbox" aria-label="<?php esc_attr_e( 'Target search results', 'opti-behavior' ); ?>"></div>
							<button type="button" class="button opti-ab-target-picker__load-more" id="opti-ab-target-load-more" style="display:none;">
								<?php esc_html_e( 'Load more results', 'opti-behavior' ); ?>
							</button>
						</div>

						<div
							class="opti-ab-target-picker__panel opti-ab-target-picker__panel--url"
							id="opti-ab-target-url-panel"
							role="tabpanel"
							aria-labelledby="opti-ab-target-mode-url"
							<?php echo 'url' === $opti_ab_target_mode ? '' : 'style="display:none;"'; ?>>
							<label for="opti-ab-target-url-proxy"><?php esc_html_e( 'Same-site URL', 'opti-behavior' ); ?></label>
							<input
								type="url"
								id="opti-ab-target-url-proxy"
								class="opti-ab-input opti-ab-input--full"
								value="<?php echo esc_attr( $opti_ab_saved_target_url ); ?>"
								placeholder="<?php echo esc_attr( home_url( '/category/example/' ) ); ?>"
								data-sync-target="opti-ab-target-url">
							<p class="opti-ab-help-text"><?php esc_html_e( 'Use this for the homepage, blog page, taxonomy archives, or custom same-site routes. The canonical value is stored in the Target URL field.', 'opti-behavior' ); ?></p>

							<?php if ( ! empty( $opti_ab_quick_targets ) ) : ?>
							<div class="opti-ab-target-picker__quick">
								<span class="opti-ab-target-picker__quick-label"><?php esc_html_e( 'Quick targets', 'opti-behavior' ); ?></span>
								<div class="opti-ab-target-picker__quick-buttons">
									<?php foreach ( $opti_ab_quick_targets as $opti_ab_quick_target ) : ?>
									<button
										type="button"
										class="button opti-ab-target-quick"
										data-target-source="<?php echo esc_attr( $opti_ab_quick_target['id'] ); ?>"
										data-target-label="<?php echo esc_attr( $opti_ab_quick_target['label'] ); ?>"
										data-target-url="<?php echo esc_url( $opti_ab_quick_target['url'] ); ?>">
										<strong><?php echo esc_html( $opti_ab_quick_target['label'] ); ?></strong>
										<span><?php echo esc_html( $opti_ab_quick_target['description'] ); ?></span>
									</button>
									<?php endforeach; ?>
								</div>
							</div>
							<?php endif; ?>

							<div
								id="opti-ab-target-url-summary"
								class="opti-ab-target-picker__url-summary"
								<?php echo 'url' === $opti_ab_target_mode && '' !== $opti_ab_saved_target_url ? '' : 'style="display:none;"'; ?>>
								<span class="opti-ab-target-picker__selected-label"><?php esc_html_e( 'Selected URL', 'opti-behavior' ); ?></span>
								<a
									id="opti-ab-target-url-summary-link"
									href="<?php echo '' !== $opti_ab_saved_target_url ? esc_url( $opti_ab_saved_target_url ) : '#'; ?>"
									target="_blank"
									rel="noopener">
									<?php echo '' !== $opti_ab_saved_target_url ? esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $opti_ab_saved_target_url ) ) ) : ''; ?>
								</a>
							</div>
						</div>
					</div>
				</div>


				<!-- WooCommerce Product Picker (shown only for woocommerce test type, populated by Pro JS) -->
				<div class="opti-ab-form-group" id="opti-ab-woo-product-picker-group" style="display:none;">
					<label>
						<?php esc_html_e( 'Select Product', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['woo_product'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['woo_product']['title'], $opti_behavior_ab_tooltips['woo_product']['content'], '', '', array( 'position' => 'right' ) ); ?>
						<?php endif; ?>
					</label>
					<div id="opti-ab-woo-product-picker" class="opti-ab-woo-product-picker">
						<!-- Search bar -->
						<div class="opti-ab-woo-picker__search-bar">
							<div class="opti-ab-woo-picker__search-wrap">
								<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
								<input type="text" id="opti-ab-woo-product-search" class="opti-ab-input opti-ab-input--full"
									placeholder="<?php esc_attr_e( 'Search products by name, SKU, or category…', 'opti-behavior' ); ?>">
								<?php if ( ! empty( $opti_behavior_ab_tooltips['woo_product_search'] ) ) : ?>
								<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['woo_product_search']['title'], $opti_behavior_ab_tooltips['woo_product_search']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
								<?php endif; ?>
							</div>
							<div class="opti-ab-filter-wrap">
								<?php if ( ! empty( $opti_behavior_ab_tooltips['woo_category_filter'] ) ) : ?>
								<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['woo_category_filter']['title'], $opti_behavior_ab_tooltips['woo_category_filter']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
								<?php endif; ?>
							</div>
							<select id="opti-ab-woo-category-filter" class="opti-ab-select">
								<option value=""><?php esc_html_e( 'All Categories', 'opti-behavior' ); ?></option>
								<?php
								if ( class_exists( 'WooCommerce' ) ) {
									$opti_ab_woo_cats = get_terms(
										array(
											'taxonomy'   => 'product_cat',
											'hide_empty' => false,
											'number'     => 200,
											'orderby'    => 'name',
											'order'      => 'ASC',
										)
									);
									if ( ! is_wp_error( $opti_ab_woo_cats ) ) {
										foreach ( $opti_ab_woo_cats as $opti_ab_cat ) {
											echo '<option value="' . esc_attr( $opti_ab_cat->term_id ) . '">' . esc_html( $opti_ab_cat->name ) . '</option>';
										}
									}
								}
								?>
							</select>
						</div>

						<!-- Product grid / list -->
						<div id="opti-ab-woo-product-list" class="opti-ab-woo-picker__product-list">
							<div class="opti-ab-loading">
								<div class="opti-ab-spinner"></div>
								<p><?php esc_html_e( 'Loading products…', 'opti-behavior' ); ?></p>
							</div>
						</div>

						<!-- Selected product preview card -->
						<div id="opti-ab-woo-selected-product" class="opti-ab-woo-picker__selected" style="display:none;">
							<div class="opti-ab-woo-picker__selected-inner">
								<img id="opti-ab-woo-selected-thumb" src="" alt="">
								<div class="opti-ab-woo-picker__selected-info">
									<h4 id="opti-ab-woo-selected-name"></h4>
									<div class="opti-ab-woo-picker__selected-meta">
										<span id="opti-ab-woo-selected-price" class="opti-ab-woo-picker__price"></span>
										<span id="opti-ab-woo-selected-sku" class="opti-ab-woo-picker__sku"></span>
										<span id="opti-ab-woo-selected-stock" class="opti-ab-woo-picker__stock"></span>
									</div>
									<p id="opti-ab-woo-selected-desc" class="opti-ab-woo-picker__desc"></p>
								</div>
								<button type="button" id="opti-ab-woo-change-product" class="button" title="<?php esc_attr_e( 'Change Product', 'opti-behavior' ); ?>">
									<?php esc_html_e( 'Change', 'opti-behavior' ); ?>
								</button>
							</div>
						</div>
						<input type="hidden" id="opti-ab-woo-selected-product-id" value="<?php echo $test ? esc_attr( $test->target_post_id ) : ''; ?>">
					</div>
					<p class="opti-ab-help-text"><?php esc_html_e( 'Select a WooCommerce product to A/B test. You can modify its title, price, description, and more in the Variants step.', 'opti-behavior' ); ?></p>
				</div>
			</div>

			<!-- Step 3: Variants -->
			<div class="opti-ab-wizard-panel" data-step="3">
				<h2><?php esc_html_e( 'Define Variants', 'opti-behavior' ); ?></h2>
				<p class="opti-ab-wizard-panel__desc"><?php esc_html_e( 'Add the different variations you want to test.', 'opti-behavior' ); ?></p>
				<div id="opti-ab-variants-list" class="opti-ab-variants-list">
					<!-- Variants added dynamically by JS -->
				</div>
				<button type="button" id="opti-ab-add-variant" class="button opti-ab-btn-add-variant">
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
					<?php esc_html_e( 'Add Variant', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['add_variant_btn'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['add_variant_btn']['title'], $opti_behavior_ab_tooltips['add_variant_btn']['content'], '', '', array( 'position' => 'top' ) ); ?>
					<?php endif; ?>
				</button>
			</div>

			<!-- Step 4: Goals -->
			<div class="opti-ab-wizard-panel" data-step="4">
				<h2><?php esc_html_e( 'Set Goals', 'opti-behavior' ); ?></h2>
				<p class="opti-ab-wizard-panel__desc"><?php esc_html_e( 'Define what counts as a conversion.', 'opti-behavior' ); ?></p>
				<div id="opti-ab-goals-list" class="opti-ab-goals-list">
					<!-- Goals added dynamically by JS -->
				</div>
				<button type="button" id="opti-ab-add-goal" class="button opti-ab-btn-add-goal">
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
					<?php esc_html_e( 'Add Goal', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['add_goal_btn'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['add_goal_btn']['title'], $opti_behavior_ab_tooltips['add_goal_btn']['content'], '', '', array( 'position' => 'top' ) ); ?>
					<?php endif; ?>
				</button>
			</div>

			<!-- Step 5: Settings -->
			<div class="opti-ab-wizard-panel" data-step="5">
				<h2><?php esc_html_e( 'Test Settings', 'opti-behavior' ); ?></h2>
				<p class="opti-ab-wizard-panel__desc"><?php esc_html_e( 'Configure statistical and traffic settings.', 'opti-behavior' ); ?></p>
				<div class="opti-ab-settings-grid">
					<div class="opti-ab-form-group">
						<label for="opti-ab-confidence">
							<?php esc_html_e( 'Confidence Level', 'opti-behavior' ); ?>
							<?php if ( ! empty( $opti_behavior_ab_tooltips['confidence_level'] ) ) : ?>
							<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['confidence_level']['title'], $opti_behavior_ab_tooltips['confidence_level']['content'], '', '', array( 'position' => 'right' ) ); ?>
							<?php endif; ?>
						</label>
						<select id="opti-ab-confidence" class="opti-ab-select">
							<option value="0.90" <?php selected( $test && 0.90 === (float) $test->confidence_level ); ?>>90%</option>
							<option value="0.95" <?php selected( ! $test || 0.95 === (float) $test->confidence_level ); ?>>95%<?php esc_html_e( ' (recommended)', 'opti-behavior' ); ?></option>
							<option value="0.99" <?php selected( $test && 0.99 === (float) $test->confidence_level ); ?>>99%</option>
						</select>
					</div>
					<div class="opti-ab-form-group">
						<label for="opti-ab-traffic">
							<?php esc_html_e( 'Traffic Percentage', 'opti-behavior' ); ?>
							<?php if ( ! empty( $opti_behavior_ab_tooltips['traffic_percentage'] ) ) : ?>
							<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['traffic_percentage']['title'], $opti_behavior_ab_tooltips['traffic_percentage']['content'], '', '', array( 'position' => 'right' ) ); ?>
							<?php endif; ?>
						</label>
						<input type="number" id="opti-ab-traffic" class="opti-ab-input" min="10" max="100" step="5"
							value="<?php echo $test ? esc_attr( $test->traffic_percent ) : '100'; ?>">
						<p class="opti-ab-help-text"><?php esc_html_e( 'Percentage of visitors included in the test.', 'opti-behavior' ); ?></p>
					</div>
					<div class="opti-ab-form-group">
						<label for="opti-ab-min-sample">
							<?php esc_html_e( 'Minimum Sample Size', 'opti-behavior' ); ?>
							<?php if ( ! empty( $opti_behavior_ab_tooltips['min_sample_size'] ) ) : ?>
							<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['min_sample_size']['title'], $opti_behavior_ab_tooltips['min_sample_size']['content'], '', '', array( 'position' => 'right' ) ); ?>
							<?php endif; ?>
						</label>
						<input type="number" id="opti-ab-min-sample" class="opti-ab-input" min="0" step="100"
							value="<?php echo $test ? esc_attr( $test->min_sample_size ) : '100'; ?>">
						<p class="opti-ab-help-text"><?php esc_html_e( 'Min impressions per variant before declaring significance.', 'opti-behavior' ); ?></p>
					</div>
					<div class="opti-ab-form-group">
						<label for="opti-ab-min-duration">
							<?php esc_html_e( 'Minimum Duration (days)', 'opti-behavior' ); ?>
							<?php if ( ! empty( $opti_behavior_ab_tooltips['min_duration'] ) ) : ?>
							<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['min_duration']['title'], $opti_behavior_ab_tooltips['min_duration']['content'], '', '', array( 'position' => 'right' ) ); ?>
							<?php endif; ?>
						</label>
						<input type="number" id="opti-ab-min-duration" class="opti-ab-input" min="1" max="365"
							value="<?php echo $test ? esc_attr( max( 1, intval( $test->min_duration_hours ) / 24 ) ) : '7'; ?>">
						<p class="opti-ab-help-text"><?php esc_html_e( 'Test must run at least this many days.', 'opti-behavior' ); ?></p>
					</div>
				</div>

				<?php
				/**
				 * Hook for Pro to inject targeting, scheduling, and other panels
				 * inside the Settings step in the builder wizard.
				 *
				 * Pro sections (Audience Targeting, Test Schedule) are rendered
				 * INSIDE the Step 5 wizard panel so the wizard hide/show logic
				 * controls their visibility correctly.
				 *
				 * @since 1.3.0
				 * @param int $test_id Current test ID (0 for new tests).
				 */
				do_action( 'opti_behavior_ab_builder_after_settings', $test_id );

				if ( ! $is_pro ) :
				?>

				<!-- Audience Targeting — disabled Pro preview -->
				<div class="opti-ab-pro-section opti-ab-targeting-section opti-ab-pro-section--placeholder"
					id="opti-ab-targeting-preview"
					aria-label="<?php esc_attr_e( 'Audience Targeting — Pro only', 'opti-behavior' ); ?>">
					<h3 class="opti-ab-pro-section__title">
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
						<?php esc_html_e( 'Audience Targeting', 'opti-behavior' ); ?>
						<span class="opti-ab-pro-badge" aria-label="<?php esc_attr_e( 'Pro only', 'opti-behavior' ); ?>">PRO</span>
					</h3>
					<p class="opti-ab-pro-section__desc">
						<?php esc_html_e( 'Define who should see this test. Groups use OR logic; rules within a group use AND logic.', 'opti-behavior' ); ?>
					</p>
					<!-- Skeleton preview row — all controls disabled -->
					<div class="opti-ab-targeting-group opti-ab-targeting-group--preview" aria-hidden="true">
						<div class="opti-ab-targeting-group-header">
							<span class="opti-ab-targeting-group-label"><?php esc_html_e( 'Group 1', 'opti-behavior' ); ?></span>
						</div>
						<div class="opti-ab-targeting-rule">
							<select class="opti-ab-select" disabled aria-disabled="true">
								<option><?php esc_html_e( 'Device', 'opti-behavior' ); ?></option>
							</select>
							<select class="opti-ab-select" disabled aria-disabled="true">
								<option><?php esc_html_e( 'equals', 'opti-behavior' ); ?></option>
							</select>
							<input type="text" class="opti-ab-input" disabled aria-disabled="true" value="desktop">
						</div>
					</div>
					<button type="button" class="button opti-ab-btn-add-group" disabled aria-disabled="true"
						title="<?php esc_attr_e( 'Upgrade to Pro to unlock audience targeting.', 'opti-behavior' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
						<?php esc_html_e( '+ Add Group (OR)', 'opti-behavior' ); ?>
					</button>
					<p class="opti-ab-field-help opti-ab-field-help--pro">
						<?php esc_html_e( 'Upgrade to Pro to unlock advanced audience targeting and test scheduling.', 'opti-behavior' ); ?>
					</p>
				</div>

				<!-- Test Schedule — disabled Pro preview -->
				<div class="opti-ab-pro-section opti-ab-scheduling-section opti-ab-pro-section--placeholder"
					id="opti-ab-schedule-preview"
					aria-label="<?php esc_attr_e( 'Test Schedule — Pro only', 'opti-behavior' ); ?>">
					<h3 class="opti-ab-pro-section__title">
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
						<?php esc_html_e( 'Test Schedule', 'opti-behavior' ); ?>
						<span class="opti-ab-pro-badge" aria-label="<?php esc_attr_e( 'Pro only', 'opti-behavior' ); ?>">PRO</span>
					</h3>
					<p class="opti-ab-pro-section__desc">
						<?php esc_html_e( 'Control when this test is active. Leave blank for always-on.', 'opti-behavior' ); ?>
					</p>
					<div class="opti-ab-schedule-grid">
						<div class="opti-ab-form-group">
							<label><?php esc_html_e( 'Start Date & Time', 'opti-behavior' ); ?></label>
							<input type="datetime-local" class="opti-ab-input" disabled aria-disabled="true">
						</div>
						<div class="opti-ab-form-group">
							<label><?php esc_html_e( 'End Date & Time', 'opti-behavior' ); ?></label>
							<input type="datetime-local" class="opti-ab-input" disabled aria-disabled="true">
						</div>
						<div class="opti-ab-form-group opti-ab-form-group--full">
							<label><?php esc_html_e( 'Active Days', 'opti-behavior' ); ?></label>
							<div class="opti-ab-days-picker">
								<?php
								$opti_behavior_days = array(
									__( 'Sun', 'opti-behavior' ),
									__( 'Mon', 'opti-behavior' ),
									__( 'Tue', 'opti-behavior' ),
									__( 'Wed', 'opti-behavior' ),
									__( 'Thu', 'opti-behavior' ),
									__( 'Fri', 'opti-behavior' ),
									__( 'Sat', 'opti-behavior' ),
								);
								foreach ( $opti_behavior_days as $opti_behavior_day_label ) :
									?>
									<label class="opti-ab-day-toggle">
										<input type="checkbox" checked disabled aria-disabled="true">
										<span><?php echo esc_html( $opti_behavior_day_label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="opti-ab-form-group">
							<label><?php esc_html_e( 'Active Hours Start', 'opti-behavior' ); ?></label>
							<input type="time" class="opti-ab-input" disabled aria-disabled="true" value="00:00">
						</div>
						<div class="opti-ab-form-group">
							<label><?php esc_html_e( 'Active Hours End', 'opti-behavior' ); ?></label>
							<input type="time" class="opti-ab-input" disabled aria-disabled="true" value="23:59">
						</div>
						<div class="opti-ab-form-group">
							<label><?php esc_html_e( 'Timezone', 'opti-behavior' ); ?></label>
							<select class="opti-ab-select" disabled aria-disabled="true">
								<option>UTC</option>
							</select>
						</div>
					</div>
				</div>

				<?php
				endif; /* ! $is_pro */
				?>
			</div>

			<!-- Step 6: Review -->
			<div class="opti-ab-wizard-panel" data-step="6">
				<h2><?php esc_html_e( 'Review & Launch', 'opti-behavior' ); ?></h2>
				<p class="opti-ab-wizard-panel__desc"><?php esc_html_e( 'Review your test configuration before launching.', 'opti-behavior' ); ?></p>
				<div id="opti-ab-review-summary" class="opti-ab-review-summary">
					<!-- Populated by JS -->
				</div>
			</div>

			<!-- Wizard Navigation -->
			<div class="opti-ab-wizard-nav">
				<button type="button" id="opti-ab-wizard-prev" class="button" style="display:none;">
					<?php esc_html_e( '← Previous', 'opti-behavior' ); ?>
				</button>
				<div class="opti-ab-wizard-nav__right">
					<button type="button" id="opti-ab-wizard-save-draft" class="button">
						<?php esc_html_e( 'Save Draft', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['save_draft_btn'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['save_draft_btn']['title'], $opti_behavior_ab_tooltips['save_draft_btn']['content'], '', '', array( 'position' => 'top' ) ); ?>
						<?php endif; ?>
					</button>
					<button type="button" id="opti-ab-wizard-next" class="button button-primary">
						<?php esc_html_e( 'Next →', 'opti-behavior' ); ?>
					</button>
					<button type="button" id="opti-ab-wizard-launch" class="button button-primary" style="display:none;">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="6 3 20 12 6 21 6 3"/></svg>
						<?php esc_html_e( 'Launch Test', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['launch_test_btn'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['launch_test_btn']['title'], $opti_behavior_ab_tooltips['launch_test_btn']['content'], '', '', array( 'position' => 'top' ) ); ?>
						<?php endif; ?>
					</button>
				</div>
			</div>
		</div>
		</div><!-- /.opti-ab-content -->
		<?php
	}

	// =========================================================================
	// View: Results
	// =========================================================================

	/**
	 * Render the test results page.
	 *
	 * @param int $test_id Test ID.
	 */
	private function render_results( $test_id ) {
		if ( ! $test_id ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No test ID specified.', 'opti-behavior' ) . '</p></div>';
			return;
		}

		$test = Opti_Behavior_AB_Test_Database::get_test( $test_id );
		if ( ! $test ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Test not found.', 'opti-behavior' ) . '</p></div>';
			return;
		}

		$opti_behavior_ab_tooltips = function_exists( 'opti_behavior_get_ab_testing_tooltips' ) ? opti_behavior_get_ab_testing_tooltips() : array();
		$exclude_spam              = $this->resolve_spam_exclusion_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
		?>
		<!-- Purple gradient header for results -->
		<div class="opti-ab-subpage-header">
			<div class="opti-ab-subpage-header__content">
				<div class="opti-ab-subpage-header__left">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="opti-ab-back-link">
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
						<?php esc_html_e( 'Back to Tests', 'opti-behavior' ); ?>
					</a>
					<h1 class="opti-ab-subpage-header__title">
						<?php echo esc_html( $test->name ); ?>
						<span class="opti-ab-badge opti-ab-badge--<?php echo esc_attr( $test->status ); ?>"><?php echo esc_html( ucfirst( $test->status ) ); ?></span>
					</h1>
				</div>
				<div class="opti-ab-subpage-header__right">
					<button type="button" class="filter-btn opti-ab-exclude-spam <?php echo $exclude_spam ? 'active' : ''; ?>" id="opti-ab-exclude-spam-toggle" aria-pressed="<?php echo esc_attr( $exclude_spam ? 'true' : 'false' ); ?>" aria-label="<?php esc_attr_e( 'Exclude spam traffic', 'opti-behavior' ); ?>" title="<?php esc_attr_e( 'Exclude spam traffic from A/B testing results', 'opti-behavior' ); ?>">
						<span class="filter-icon"><i data-lucide="<?php echo esc_attr( $exclude_spam ? 'shield-check' : 'shield-off' ); ?>" aria-hidden="true"></i></span>
						<span class="filter-label"><?php esc_html_e( 'Exclude Spam', 'opti-behavior' ); ?></span>
					</button>
					<?php if ( 'running' === $test->status ) : ?>
					<button type="button" class="opti-ab-header-action-btn opti-ab-action-btn" data-action="pause" data-test-id="<?php echo esc_attr( $test_id ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></svg>
						<?php esc_html_e( 'Pause', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['pause_btn'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['pause_btn']['title'], $opti_behavior_ab_tooltips['pause_btn']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
						<?php endif; ?>
					</button>
					<button type="button" class="opti-ab-header-action-btn opti-ab-header-action-btn--danger opti-ab-action-btn" data-action="stop" data-test-id="<?php echo esc_attr( $test_id ); ?>">
						<?php esc_html_e( 'Stop Test', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['stop_btn'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['stop_btn']['title'], $opti_behavior_ab_tooltips['stop_btn']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
						<?php endif; ?>
					</button>
					<?php elseif ( 'paused' === $test->status ) : ?>
					<button type="button" class="opti-ab-header-action-btn opti-ab-action-btn" data-action="start" data-test-id="<?php echo esc_attr( $test_id ); ?>">
						<?php esc_html_e( 'Resume', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['resume_btn'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['resume_btn']['title'], $opti_behavior_ab_tooltips['resume_btn']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
						<?php endif; ?>
					</button>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="opti-ab-content">

		<!-- Results Tab Bar — goal tabs built by JS; Pro prepends DE tab via hook -->
		<div id="opti-ab-results-tabs" class="opti-ab-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Test results views', 'opti-behavior' ); ?>">
			<?php
			/**
			 * Fires inside the results tablist before JS-built goal tabs.
			 * Pro uses this to prepend the "Decision engine" tab button.
			 *
			 * @since 1.4.0
			 * @param int $test_id Current test ID.
			 */
			do_action( 'opti_behavior_ab_results_before_tabs', $test_id );
			?>
		</div>
		<?php
		/**
		 * Fires after the results tablist closes. Symmetry hook for extensions.
		 *
		 * @since 1.4.0
		 * @param int $test_id Current test ID.
		 */
		do_action( 'opti_behavior_ab_results_after_tabs', $test_id );

		/**
		 * Whether to hide the goal panel on initial render (because a different
		 * tab, such as the Pro Decision Engine, is the default-active tab).
		 * Pro hooks this filter to return true when the DE tab is present.
		 *
		 * Prevents the visible flash of empty goal content before JS kicks in.
		 *
		 * @since 1.4.0
		 * @param bool $hide    Whether to start with the goal panel hidden. Default false.
		 * @param int  $test_id Current test ID.
		 */
		$opti_behavior_hide_goal_panel = apply_filters( 'opti_behavior_ab_hide_goal_panel_default', false, $test_id );
		?>

		<!-- Goal Results Panel — wraps hero strip → chart → winner banner -->
		<div id="opti-ab-goal-panel" class="opti-ab-tabpanel" role="tabpanel" data-panel="goal"<?php echo $opti_behavior_hide_goal_panel ? ' hidden' : ''; ?>>

		<!-- Hero Metric Strip -->
		<div class="opti-ab-results-hero" id="opti-ab-results-summary" data-test-id="<?php echo esc_attr( $test_id ); ?>">
			<div class="opti-ab-hero-card" id="opti-ab-hero-visitors">
				<div class="opti-ab-hero-card__icon opti-ab-hero-card__icon--blue">
					<i data-lucide="eye"></i>
				</div>
				<div class="opti-ab-hero-card__value" id="opti-ab-total-impressions">—</div>
				<div class="opti-ab-hero-card__label">
					<?php esc_html_e( 'Total Visitors', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['hero_total_visitors'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['hero_total_visitors']['title'], $opti_behavior_ab_tooltips['hero_total_visitors']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
				</div>
			</div>
			<div class="opti-ab-hero-card" id="opti-ab-hero-duration">
				<div class="opti-ab-hero-card__icon opti-ab-hero-card__icon--orange">
					<i data-lucide="calendar"></i>
				</div>
				<div class="opti-ab-hero-card__value" id="opti-ab-duration">—</div>
				<div class="opti-ab-hero-card__label">
					<?php esc_html_e( 'Test Duration', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['hero_duration'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['hero_duration']['title'], $opti_behavior_ab_tooltips['hero_duration']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
				</div>
			</div>
			<div class="opti-ab-hero-card" id="opti-ab-hero-best-rate">
				<div class="opti-ab-hero-card__icon opti-ab-hero-card__icon--green">
					<i data-lucide="zap"></i>
				</div>
				<div class="opti-ab-hero-card__value" id="opti-ab-best-rate">—</div>
				<div class="opti-ab-hero-card__label">
					<?php esc_html_e( 'Best Conv. Rate', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['hero_best_rate'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['hero_best_rate']['title'], $opti_behavior_ab_tooltips['hero_best_rate']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
				</div>
			</div>
			<div class="opti-ab-hero-card" id="opti-ab-hero-significance">
				<div class="opti-ab-hero-card__icon opti-ab-hero-card__icon--purple">
					<i data-lucide="shield"></i>
				</div>
				<div class="opti-ab-hero-card__value" id="opti-ab-sig-hero">—</div>
				<div class="opti-ab-hero-card__label">
					<?php esc_html_e( 'Confidence', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['hero_confidence'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['hero_confidence']['title'], $opti_behavior_ab_tooltips['hero_confidence']['content'], '', '', array( 'position' => 'bottom' ) ); ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		$opti_behavior_display_url = $test->target_url;
		if ( empty( $opti_behavior_display_url ) && ! empty( $test->target_post_id ) ) {
			$opti_behavior_display_url = get_permalink( $test->target_post_id );
		}
		if ( ! empty( $opti_behavior_display_url ) ) :
		?>
		<div class="opti-ab-target-pill" id="opti-ab-target-page-meta">
			<i data-lucide="link"></i>
			<a href="<?php echo esc_url( $opti_behavior_display_url ); ?>" target="_blank">
				<?php echo esc_html( preg_replace( '#^https?://#', '', untrailingslashit( $opti_behavior_display_url ) ) ); ?>
			</a>
		</div>
		<?php endif; ?>

		<!-- Goal Selector -->
		<div class="opti-ab-goal-selector" id="opti-ab-goal-selector" style="display:none;">
			<div class="opti-ab-goal-selector__inner">
				<label for="opti-ab-goal-select">
					<?php esc_html_e( 'Goal', 'opti-behavior' ); ?>:
					<?php if ( ! empty( $opti_behavior_ab_tooltips['goal_selector'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['goal_selector']['title'], $opti_behavior_ab_tooltips['goal_selector']['content'], '', '', array( 'position' => 'right' ) ); ?>
					<?php endif; ?>
				</label>
				<select id="opti-ab-goal-select" class="opti-ab-select">
					<!-- Populated by JS -->
				</select>
				<span id="opti-ab-goal-type-badge" class="opti-ab-badge"></span>
			</div>
			<div id="opti-ab-goal-config-info" class="opti-ab-goal-config-info"></div>
		</div>

		<!-- Radial Significance Gauge -->
		<div class="opti-ab-sig-gauge-wrap" id="opti-ab-significance-bar">
			<div class="opti-ab-sig-gauge">
				<svg viewBox="0 0 200 200" class="opti-ab-sig-gauge__svg" width="200" height="200">
					<circle class="opti-ab-sig-gauge__track" cx="100" cy="100" r="82" />
					<circle class="opti-ab-sig-gauge__arc" cx="100" cy="100" r="82"
						id="opti-ab-significance-fill"
						transform="rotate(-90 100 100)"
						style="stroke-dashoffset:515;" />
				</svg>
				<div class="opti-ab-sig-gauge__center">
					<span class="opti-ab-sig-gauge__pct" id="opti-ab-significance-pct">0%</span>
					<span class="opti-ab-sig-gauge__label"><?php esc_html_e( 'Confidence', 'opti-behavior' ); ?></span>
				</div>
			</div>
			<div class="opti-ab-sig-gauge__info">
				<div class="opti-ab-sig-gauge__title">
					<?php esc_html_e( 'Statistical Significance', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['sig_gauge'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['sig_gauge']['title'], $opti_behavior_ab_tooltips['sig_gauge']['content'], '', '', array( 'position' => 'right' ) ); ?>
					<?php endif; ?>
				</div>
				<div class="opti-ab-sig-gauge__hint" id="opti-ab-significance-hint">
					<?php esc_html_e( 'Collecting data — more visitors are needed to reach statistical significance.', 'opti-behavior' ); ?>
				</div>
				<div class="opti-ab-sig-gauge__threshold-label">
					<?php esc_html_e( 'Target: 95% confidence threshold', 'opti-behavior' ); ?>
					<?php if ( ! empty( $opti_behavior_ab_tooltips['sig_threshold'] ) ) : ?>
					<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['sig_threshold']['title'], $opti_behavior_ab_tooltips['sig_threshold']['content'], '', '', array( 'position' => 'right' ) ); ?>
					<?php endif; ?>
				</div>
			</div>
			<!-- Per-Tab Goal Configuration Card (auto-updates on tab switch) -->
			<aside
				class="opti-ab-goal-config-card"
				id="opti-ab-goal-config-card"
				aria-live="polite"
				aria-label="<?php esc_attr_e( 'Current goal configuration', 'opti-behavior' ); ?>"
				hidden
			>
				<header class="opti-ab-goal-config-card__header">
					<span class="opti-ab-goal-config-card__icon" aria-hidden="true">
						<i data-lucide="target"></i>
					</span>
					<span class="opti-ab-goal-config-card__titles">
						<span class="opti-ab-goal-config-card__eyebrow">
							<?php esc_html_e( 'Goal Configuration', 'opti-behavior' ); ?>
							<?php if ( ! empty( $opti_behavior_ab_tooltips['goal_config_card'] ) ) : ?>
							<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['goal_config_card']['title'], $opti_behavior_ab_tooltips['goal_config_card']['content'], '', '', array( 'position' => 'right' ) ); ?>
							<?php endif; ?>
						</span>
						<span class="opti-ab-goal-config-card__name" id="opti-ab-goal-config-card-name">—</span>
					</span>
					<span class="opti-ab-goal-config-card__badge" id="opti-ab-goal-config-card-badge" hidden></span>
				</header>
				<dl class="opti-ab-goal-config-card__body" id="opti-ab-goal-config-card-body"></dl>
			</aside>
		</div>

		<!-- Variant Cards -->
		<div id="opti-ab-variant-cards" class="opti-ab-variant-cards">
			<div class="opti-ab-loading">
				<div class="opti-ab-spinner"></div>
				<p><?php esc_html_e( 'Loading results…', 'opti-behavior' ); ?></p>
			</div>
		</div>

		<?php
		/**
		 * Hook for Pro to inject Bayesian analysis, revenue metrics, and
		 * segment breakdown after the variant cards on the results page.
		 *
		 * @since 1.3.0
		 * @param int $test_id Current test ID.
		 */
		do_action( 'opti_behavior_ab_results_after_variants', $test_id );
		?>

		<!-- Conversion Rate Chart -->
		<div class="opti-ab-chart-container">
			<h3>
				<?php esc_html_e( 'Conversion Rate Over Time', 'opti-behavior' ); ?>
				<?php if ( ! empty( $opti_behavior_ab_tooltips['conversion_chart'] ) ) : ?>
				<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['conversion_chart']['title'], $opti_behavior_ab_tooltips['conversion_chart']['content'], '', '', array( 'position' => 'right' ) ); ?>
				<?php endif; ?>
			</h3>
			<div style="position:relative;height:350px;width:100%;">
				<canvas id="opti-ab-chart"></canvas>
				<div id="opti-ab-chart-empty" class="opti-ab-chart-empty" style="display:none;">
					<div class="opti-ab-chart-empty__icon">
						<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18.7 8l-5.1 5.2-2.8-2.7L7 14.3"/></svg>
					</div>
					<p>
						<?php esc_html_e( 'No conversion data yet', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['no_conversion_data'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['no_conversion_data']['title'], $opti_behavior_ab_tooltips['no_conversion_data']['content'], '', '', array( 'position' => 'top' ) ); ?>
						<?php endif; ?>
					</p>
					<span><?php esc_html_e( 'Data will appear here once visitors start converting.', 'opti-behavior' ); ?></span>
				</div>
			</div>
		</div>

		<!-- Winner Banner -->
		<div id="opti-ab-winner-actions" class="opti-ab-winner-cta" style="display:none;">
			<div class="opti-ab-winner-cta__inner">
				<div class="opti-ab-winner-cta__icon">
					<i data-lucide="trophy"></i>
				</div>
				<div class="opti-ab-winner-cta__copy">
					<h3>
						<?php esc_html_e( 'A Winner Has Emerged', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['winner_banner'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['winner_banner']['title'], $opti_behavior_ab_tooltips['winner_banner']['content'], '', '', array( 'position' => 'right' ) ); ?>
						<?php endif; ?>
					</h3>
					<p><?php esc_html_e( 'Declare a winner or apply the best-performing variant to your site permanently.', 'opti-behavior' ); ?></p>
				</div>
				<div class="opti-ab-winner-cta__actions">
					<button type="button" id="opti-ab-declare-winner" class="opti-ab-winner-pill opti-ab-winner-pill--primary">
						<?php esc_html_e( 'Declare Best Performer', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['winner_declare_pill'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['winner_declare_pill']['title'], $opti_behavior_ab_tooltips['winner_declare_pill']['content'], '', '', array( 'position' => 'top' ) ); ?>
						<?php endif; ?>
					</button>
					<button type="button" id="opti-ab-apply-winner" class="opti-ab-winner-pill opti-ab-winner-pill--secondary" style="display:none;">
						<?php esc_html_e( 'Apply Permanently', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['winner_apply_pill'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['winner_apply_pill']['title'], $opti_behavior_ab_tooltips['winner_apply_pill']['content'], '', '', array( 'position' => 'top' ) ); ?>
						<?php endif; ?>
					</button>
					<button type="button" id="opti-ab-revert-winner" class="opti-ab-winner-pill opti-ab-winner-pill--danger" style="display:none;">
						<?php esc_html_e( 'Disable Applied Winner', 'opti-behavior' ); ?>
						<?php if ( ! empty( $opti_behavior_ab_tooltips['winner_revert_pill'] ) ) : ?>
						<?php opti_behavior_tooltip_e( $opti_behavior_ab_tooltips['winner_revert_pill']['title'], $opti_behavior_ab_tooltips['winner_revert_pill']['content'], '', '', array( 'position' => 'top' ) ); ?>
						<?php endif; ?>
					</button>
				</div>
			</div>
		</div>
		</div><!-- /#opti-ab-goal-panel -->
		</div><!-- /.opti-ab-content -->
		<?php
	}
}
