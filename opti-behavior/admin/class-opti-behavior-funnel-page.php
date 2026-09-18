<?php
/**
 * Funnel Analytics Page Class
 *
 * Handles funnel visualization, conversion tracking, and user journey analytics.
 *
 * @package opti-behavior
 * @copyright 2025 OptiUser
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load funnel views trait and database class.
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-funnels-views.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'trait-opti-behavior-advanced-filters.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-funnel-database.php';

// Auto-funnel stack (detection -> recipes -> builder). Not covered by the
// autoloader (it does not map the Opti_Behavior_Funnel_* prefix), so it is wired
// explicitly here, next to the funnel database class.
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-funnel-site-detector.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-funnel-recipes.php';
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-funnel-auto-builder.php';

/**
 * Funnel Analytics Page Class
 *
 * @since 1.0.0
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
class Opti_Behavior_Funnel_Page {
	use Opti_Behavior_Funnels_Views_Trait;
	use Opti_Behavior_Advanced_Filters_Trait;

	/**
	 * opti-behavior Heatmap instance
	 *
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $heatmap;

	/**
	 * Lazily-built auto-funnel builder.
	 *
	 * @since 1.8.4
	 * @var Opti_Behavior_Funnel_Auto_Builder|null
	 */
	private $auto_builder = null;

	/**
	 * User meta holding the suggestions-panel display preference.
	 *
	 * Per USER, not per site: how noisy the panel is allowed to be is a personal
	 * screen preference (same reasoning as the Smart Insights notification
	 * meta), and two admins on the same site must not fight over it. Shape:
	 * `array( 'collapsed' => 0|1, 'show_created' => 0|1 )`; a missing key means
	 * "never chosen", which the script resolves per surface (collapsed by
	 * default once the site has funnels, always expanded in the empty state).
	 *
	 * @since 1.8.4.1
	 * @var string
	 */
	const SUGGESTIONS_UI_META = 'opti_behavior_funnel_suggestions_ui';

	/**
	 * Constructor
	 *
	 * @param Opti_Behavior_Heatmap_Core $heatmap opti-behavior Heatmap instance.
	 */
	public function __construct( $heatmap ) {
		$this->heatmap = $heatmap;
		$this->init_database();
		$this->init_hooks();
	}

	/**
	 * Initialize database tables
	 */
	private function init_database() {
		Opti_Behavior_Funnel_Database::create_tables();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_funnel_assets' ) );
		add_action( 'wp_ajax_optibehavior_funnel_data', array( $this, 'ajax_funnel_data' ) );
		add_action( 'wp_ajax_optibehavior_funnel_filter_options', array( $this, 'ajax_funnel_filter_options' ) );
		add_action( 'wp_ajax_optibehavior_get_funnels', array( $this, 'ajax_get_funnels' ) );
		add_action( 'wp_ajax_optibehavior_get_funnels_summary', array( $this, 'ajax_get_funnels_summary' ) );
		add_action( 'wp_ajax_optibehavior_get_funnel_kpi', array( $this, 'ajax_get_funnel_kpi' ) );
		add_action( 'wp_ajax_optibehavior_get_funnel', array( $this, 'ajax_get_funnel' ) );
		add_action( 'wp_ajax_optibehavior_save_funnel', array( $this, 'ajax_save_funnel' ) );
		add_action( 'wp_ajax_optibehavior_delete_funnel', array( $this, 'ajax_delete_funnel' ) );
		add_action( 'wp_ajax_optibehavior_set_funnel_status', array( $this, 'ajax_set_funnel_status' ) );
		add_action( 'wp_ajax_optibehavior_reset_funnel_data', array( $this, 'ajax_reset_funnel_data' ) );
		add_action( 'wp_ajax_optibehavior_get_funnel_countries', array( $this, 'ajax_get_funnel_countries' ) );

		// Auto-funnel suggestions (spec.md §4.2).
		add_action( 'wp_ajax_optibehavior_funnel_suggestions', array( $this, 'ajax_funnel_suggestions' ) );
		add_action( 'wp_ajax_optibehavior_create_funnel_from_recipe', array( $this, 'ajax_create_funnel_from_recipe' ) );
		add_action( 'wp_ajax_optibehavior_create_recommended_funnels', array( $this, 'ajax_create_recommended_funnels' ) );
		add_action( 'wp_ajax_optibehavior_dismiss_funnel_suggestion', array( $this, 'ajax_dismiss_funnel_suggestion' ) );
		add_action( 'wp_ajax_optibehavior_funnel_suggestions_prefs', array( $this, 'ajax_save_funnel_suggestions_prefs' ) );

		// Frontend tracking
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_tracking' ) );
		add_action( 'wp_ajax_optibehavior_track_funnel', array( $this, 'ajax_track_funnel' ) );
		add_action( 'wp_ajax_nopriv_optibehavior_track_funnel', array( $this, 'ajax_track_funnel' ) );

		// Universal URL tracking - tracks EVERY WordPress request
		add_action( 'wp', array( $this, 'track_all_requests' ), 1 );

		// Optimizer/cache exclusion filters for funnel-tracker.js are registered
		// centrally by Opti_Behavior_Optimizer_Compat (Phase A refactor). Its file
		// and handle live in the central registry defaults.
	}

	/**
	 * Whether the PRO-gated funnel advanced filter is available.
	 *
	 * v1 uses the coarse ENV gate (spec.md §4.3-b): any valid PRO entitlement
	 * unlocks it. Wrapped in ONE helper so switching to a dedicated feature key
	 * (spec.md §4.3-a: Opti_Behavior_Pro_Feature_Guard::can_access('funnel_advanced_filter'))
	 * later is a single-spot change. This is the AUTHORITATIVE gate: the AJAX
	 * handler consults it before applying any client-supplied advanced_filters,
	 * so a forged POST from a free site is ignored (the client flag is cosmetic).
	 *
	 * @since 1.0.5
	 * @return bool
	 */
	public function funnel_advanced_filter_available() {
		return function_exists( 'opti_behavior_pro_active' )
			&& function_exists( 'opti_behavior_pro_validate_env' )
			&& opti_behavior_pro_active()
			&& opti_behavior_pro_validate_env();
	}

	/**
	 * Funnel advanced-filter allow-list: the 15 kept fields (dashboard's 16
	 * minus `exit_page`, which conflicts with funnel completion/abandonment —
	 * spec.md §3-6). Used to strip `exit_page` from a sanitized filters array
	 * before it reaches build_advanced_filters_sql().
	 *
	 * @since 1.0.5
	 * @param array $filters Sanitized filters (dashboard shape).
	 * @return array Filters with any funnel-disallowed keys removed.
	 */
	private function restrict_funnel_advanced_filters( array $filters ) {
		unset( $filters['exit_page'] );
		return $filters;
	}

	/**
	 * Enqueue funnel page assets
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_funnel_assets( $hook_suffix ) {
		// Only load on funnel page.
		if ( 'opti-behavior_page_opti-behavior-funnels' !== $hook_suffix ) {
			return;
		}

		// Enqueue Chart.js (already registered by dashboard).
		wp_enqueue_script( 'chart-js' );

		// Enqueue flag-icons CSS for country flags (bundled locally).
		wp_enqueue_style(
			'flag-icons',
			plugins_url( 'assets/css/flag-icons.min.css', dirname( __FILE__ ) ),
			array(),
			'7.2.3-' . OPTI_BEHAVIOR_HEATMAP_VERSION // Lib ver + plugin ver so updates cache-bust.
		);

		// Enqueue heatmaps CSS for header styles.
		wp_enqueue_style(
			'opti-behavior-heatmaps',
			plugins_url( 'assets/css/heatmaps.css', dirname( __FILE__ ) ),
			array( 'opti-behavior-dashboard' ),
			OPTI_BEHAVIOR_HEATMAP_VERSION
		);

		// Shared dashboard design primitives (control-bar: .dashboard-controls,
		// .period-selector, .refresh-btn purple pills) — the detail-page header
		// reuses the dashboard control bar, so its base styling must load here.
		$dashboard_styles_path = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/dashboard_styles.css';
		wp_enqueue_style(
			'opti-behavior-dashboard-styles',
			plugins_url( 'assets/css/dashboard_styles.css', dirname( __FILE__ ) ),
			array( 'opti-behavior-dashboard' ),
			file_exists( $dashboard_styles_path )
				? OPTI_BEHAVIOR_HEATMAP_VERSION . '.' . filemtime( $dashboard_styles_path )
				: OPTI_BEHAVIOR_HEATMAP_VERSION
		);

		// Shared advanced-filters UI stylesheet (icon dropdowns, suggestion menus)
		// — the detail-page advanced filter reuses the dashboard filter panel.
		wp_enqueue_style(
			'opti-behavior-filter-ui',
			plugins_url( 'assets/css/filter-ui.css', dirname( __FILE__ ) ),
			array( 'opti-behavior-dashboard-styles' ),
			OPTI_BEHAVIOR_HEATMAP_VERSION
		);

		// Enqueue funnel CSS.
		wp_enqueue_style(
			'opti-behavior-funnels',
			plugins_url( 'assets/css/funnels.css', dirname( __FILE__ ) ),
			array( 'opti-behavior-dashboard', 'opti-behavior-dashboard-styles', 'opti-behavior-heatmaps', 'opti-behavior-filter-ui' ),
			OPTI_BEHAVIOR_HEATMAP_VERSION
		);

		// Shared filter-UI module (window.OptiBehaviorFilterUI) — dependency-free,
		// powers the detail-page advanced filter's icon multi-selects + suggestions.
		wp_enqueue_script(
			'opti-behavior-filter-ui',
			plugins_url( 'assets/js/opti-behavior-filter-ui.js', dirname( __FILE__ ) ),
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION,
			true
		);

		// Shared filter-badge module (window.OptiBehaviorFilterBadge) — the
		// "Filters (N)" counter + deep-link URL reflection helpers.
		wp_enqueue_script(
			'opti-behavior-filter-badge',
			plugins_url( 'assets/js/opti-behavior-filter-badge.js', dirname( __FILE__ ) ),
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION,
			true
		);

		// Filter Profiles module (site-wide saved advanced-filter sets). Funnels
		// enqueues its own assets (not the shared assets trait), so the module +
		// its config must be registered here too. Depends on the shared filter-UI
		// module: repopulating icon multi-selects on profile load calls
		// select._obIconSync(), which that module installs.
		wp_enqueue_script(
			'opti-behavior-filter-profiles',
			plugins_url( 'assets/js/opti-behavior-filter-profiles.js', dirname( __FILE__ ) ),
			array( 'opti-behavior-filter-ui' ),
			OPTI_BEHAVIOR_HEATMAP_VERSION,
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

		// Enqueue funnel JavaScript.
		wp_enqueue_script(
			'opti-behavior-funnels',
			plugins_url( 'assets/js/funnels.js', dirname( __FILE__ ) ),
			array( 'jquery', 'chart-js', 'opti-behavior-filter-ui', 'opti-behavior-filter-badge', 'opti-behavior-filter-profiles' ),
			OPTI_BEHAVIOR_HEATMAP_VERSION,
			true
		);

		// Localize script with AJAX URL and nonce.
		wp_localize_script(
			'opti-behavior-funnels',
			'optiBehaviorFunnels',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'opti_behavior_funnels' ),
				// Base URL of the funnels index (used by list rows to link to each
				// funnel's own detail page: &funnel=<id>).
				'pageUrl' => admin_url( 'admin.php?page=opti-behavior-funnels' ),
				// PRO gate flag for the detail-page advanced filter. Cosmetic only —
				// the server (ajax_funnel_data) re-checks funnel_advanced_filter_available()
				// before applying any advanced_filters, so a forged flag changes nothing.
				'advancedFilterPro' => $this->funnel_advanced_filter_available() ? 1 : 0,
				// Nonce for the shared dashboard filter-options endpoint
				// (opti_behavior_get_dashboard_filter_options) reused to populate the
				// detail-page advanced filter's browser/country/os/utm option lists.
				'filterOptionsNonce' => wp_create_nonce( 'opti_behavior_dashboard_nonce' ),
				// Recordings deep-link base. Pro registers this filter and returns a
				// non-empty URL only when the recordings gate is open; empty
				// otherwise (the funnel step icon then routes to the upsell page).
				'recordingsBaseUrl'   => apply_filters( 'opti_behavior_recordings_deeplink_base', '' ),
				// Upsell target when Pro/recordings is unavailable. The Free plugin
				// registers this slug and shows the recordings upgrade page.
				'recordingsUpsellUrl' => admin_url( 'admin.php?page=opti-behavior-recordings' ),
				'strings' => array(
					'loading'        => __( 'Loading...', 'opti-behavior' ),
					'noData'         => __( 'No data available', 'opti-behavior' ),
					'error'          => __( 'Error loading data', 'opti-behavior' ),
					'step'           => __( 'Step', 'opti-behavior' ),
					'steps'          => __( 'Steps', 'opti-behavior' ),
					'completed'      => __( 'Completed', 'opti-behavior' ),
					'abandoned'      => __( 'Abandoned', 'opti-behavior' ),
					'filters'        => __( 'Filters', 'opti-behavior' ),
					'conversionRate' => __( 'Conversion Rate', 'opti-behavior' ),
					'dropOffRate'    => __( 'Drop-off Rate', 'opti-behavior' ),
					'users'          => __( 'users', 'opti-behavior' ),
					'views'          => __( 'views', 'opti-behavior' ),
					'saveFunnel'     => __( 'Save Funnel', 'opti-behavior' ),
					'updateFunnel'   => __( 'Update Funnel', 'opti-behavior' ),
					'description'    => __( 'Description', 'opti-behavior' ),
					'stepNamePlaceholder' => __( 'Step name (e.g., Landing Page)', 'opti-behavior' ),
					'urlPatternPlaceholder' => __( 'URL pattern (e.g., /cart)', 'opti-behavior' ),
					'anyPage'        => __( 'Any Page', 'opti-behavior' ),
					'exactUrl'       => __( 'Exact URL', 'opti-behavior' ),
					'urlContains'    => __( 'URL Contains', 'opti-behavior' ),
					'urlStartsWith'  => __( 'URL Starts With', 'opti-behavior' ),
					'urlEndsWith'    => __( 'URL Ends With', 'opti-behavior' ),
					'regex'          => __( 'Regex', 'opti-behavior' ),
					'anyPageView'    => __( 'Any Page View', 'opti-behavior' ),
					'funnelSavedSuccess' => __( 'Funnel saved successfully!', 'opti-behavior' ),
					'sevenDays'      => __( '7 Days', 'opti-behavior' ),
					'thirtyDays'     => __( '30 Days', 'opti-behavior' ),
					'ninetyDays'     => __( '90 Days', 'opti-behavior' ),
					'customRange'    => __( 'Custom Range', 'opti-behavior' ),
					'allDevices'     => __( 'All Devices', 'opti-behavior' ),
					'desktop'        => __( 'Desktop', 'opti-behavior' ),
					'mobile'         => __( 'Mobile', 'opti-behavior' ),
					'tablet'         => __( 'Tablet', 'opti-behavior' ),
					'allCountries'   => __( 'All Countries', 'opti-behavior' ),
					'searchCountries' => __( 'Search countries...', 'opti-behavior' ),
					'clear'          => __( 'Clear', 'opti-behavior' ),
					'apply'          => __( 'Apply', 'opti-behavior' ),
					'buildNewFunnel'        => __( 'Build New Funnel', 'opti-behavior' ),
					'editFunnel'            => __( 'Edit Funnel', 'opti-behavior' ),
					'duplicate'             => __( 'Duplicate', 'opti-behavior' ),
					'resetData'             => __( 'Reset Data', 'opti-behavior' ),
					'delete'                => __( 'Delete', 'opti-behavior' ),
					'suspend'               => __( 'Suspend', 'opti-behavior' ),
					'activate'              => __( 'Activate', 'opti-behavior' ),
					'suspended'             => __( 'Suspended', 'opti-behavior' ),
					'statusUpdateSuccess'   => __( 'Funnel status updated successfully.', 'opti-behavior' ),
					'statusUpdateError'     => __( 'Error updating funnel status. Please try again.', 'opti-behavior' ),
					'resetDataSuccess'      => __( 'Funnel data has been reset successfully. The funnel configuration (steps and URLs) has been preserved.', 'opti-behavior' ),
					'resetDataError'        => __( 'Error resetting funnel data', 'opti-behavior' ),
					'resetDataNetworkError' => __( 'Error resetting funnel data. Please try again.', 'opti-behavior' ),
					'unknownError'          => __( 'Unknown error', 'opti-behavior' ),
					'totalFunnels'          => __( 'Total Funnels', 'opti-behavior' ),
					'totalEntries'          => __( 'Total Entries', 'opti-behavior' ),
					'totalCompletions'      => __( 'Total Completions', 'opti-behavior' ),
					'avgConversionRate'     => __( 'Avg. Conversion Rate', 'opti-behavior' ),
					'allStatuses'           => __( 'All Statuses', 'opti-behavior' ),
					'active'                => __( 'Active', 'opti-behavior' ),
					'searchFunnels'         => __( 'Search funnels…', 'opti-behavior' ),
					'viewDetails'           => __( 'View details', 'opti-behavior' ),
					'hideDetails'           => __( 'Hide details', 'opti-behavior' ),
					'entries'               => __( 'Entries', 'opti-behavior' ),
					'completions'           => __( 'Completions', 'opti-behavior' ),
					'dropOff'               => __( 'Drop-off', 'opti-behavior' ),
					'stepCount'             => __( 'Steps', 'opti-behavior' ),
					/* translators: 1: first item index, 2: last item index, 3: total items. */
					'showingRange'          => __( 'Showing %1$d–%2$d of %3$d funnels', 'opti-behavior' ),
					'noFunnelsFound'        => __( 'No funnels match your search.', 'opti-behavior' ),
					'replayWatch'           => __( 'Watch replays of visitors who did not reach this step', 'opti-behavior' ),
					'replayUpsell'          => __( 'Upgrade to watch session replays of visitors who dropped here', 'opti-behavior' ),
					/* translators: %s: funnel name */
					'confirmDeleteFunnel'      => __( 'Are you sure you want to delete "%s"? This action cannot be undone.', 'opti-behavior' ),
					/* translators: %s: funnel name */
					'confirmResetFunnelData'   => __( "Are you sure you want to reset all data for \"%s\"?\n\nThis will delete all tracking data but keep the funnel configuration (steps and URLs).\n\nThis action cannot be undone.", 'opti-behavior' ),
					'customRangeTitle'         => __( 'Select Custom Date Range', 'opti-behavior' ),
					'startDate'                => __( 'Start Date', 'opti-behavior' ),
					'endDate'                  => __( 'End Date', 'opti-behavior' ),
					'cancel'                   => __( 'Cancel', 'opti-behavior' ),
					'saving'                   => __( 'Saving...', 'opti-behavior' ),
					'customRangeBothRequired'  => __( 'Please select both start and end dates', 'opti-behavior' ),
					'customRangeStartBeforeEnd'=> __( 'Start date must be before end date', 'opti-behavior' ),
					'funnelNameRequired'       => __( 'Please enter a funnel name', 'opti-behavior' ),
					'stepNamesRequired'        => __( 'Please fill in all step names', 'opti-behavior' ),
					'errorSavingFunnel'        => __( 'Error saving funnel', 'opti-behavior' ),
					'errorSavingFunnelRetry'   => __( 'Error saving funnel. Please try again.', 'opti-behavior' ),
					'errorLoadingFunnel'       => __( 'Error loading funnel', 'opti-behavior' ),
					'errorLoadingFunnelRetry'  => __( 'Error loading funnel. Please try again.', 'opti-behavior' ),
					'errorDeletingFunnel'      => __( 'Error deleting funnel', 'opti-behavior' ),
					'errorDeletingFunnelRetry' => __( 'Error deleting funnel. Please try again.', 'opti-behavior' ),
					'noCountryData'            => __( 'No country data available for this period', 'opti-behavior' ),
					/* translators: %d: number of selected countries */
					'nCountries'               => __( '%d Countries', 'opti-behavior' ),
					'copySuffix'               => __( ' (Copy)', 'opti-behavior' ),
					'placeholderAny'           => __( 'No pattern needed - matches all URLs', 'opti-behavior' ),
					'placeholderExact'         => __( 'Example: https://example.com/checkout', 'opti-behavior' ),
					'placeholderContains'      => __( 'Example: /product (matches any URL containing /product)', 'opti-behavior' ),
					'placeholderStartsWith'    => __( 'Example: https://example.com (matches URLs starting with this)', 'opti-behavior' ),
					'placeholderEndsWith'      => __( 'Example: .pdf (matches URLs ending with .pdf)', 'opti-behavior' ),
					'placeholderRegex'         => __( 'Example: product/[0-9]+ (matches product/123, product/456)', 'opti-behavior' ),
					'placeholderPageview'      => __( 'No pattern needed - matches all page views', 'opti-behavior' ),
					'placeholderDefault'       => __( 'Enter URL pattern', 'opti-behavior' ),
					'smartInsightsContext'     => __( 'Smart Insights context: this funnel is the related issue.', 'opti-behavior' ),
				),
			)
		);

		$this->enqueue_funnel_suggestions_assets();
	}

	/**
	 * Enqueue + localize the auto-funnel suggestions panel (spec.md §2.3).
	 *
	 * Called from enqueue_funnel_assets(), so it inherits the funnels-hook-only
	 * guard: the script never loads on any other admin screen. It depends on
	 * `opti-behavior-funnels` because "Customize" calls
	 * window.optiFunnelOpenBuilderWithSteps(), which that file installs.
	 *
	 * No detection runs here — the panel is filled by an AJAX round-trip after
	 * paint, so a page render never sweeps third-party plugins.
	 *
	 * @since 1.8.4
	 * @return void
	 */
	private function enqueue_funnel_suggestions_assets() {
		wp_enqueue_script(
			'opti-behavior-funnel-suggestions',
			plugins_url( 'assets/js/funnel-suggestions.js', dirname( __FILE__ ) ),
			array( 'jquery', 'opti-behavior-funnels' ),
			OPTI_BEHAVIOR_HEATMAP_VERSION,
			true
		);

		wp_localize_script(
			'opti-behavior-funnel-suggestions',
			'optiBehaviorFunnelSuggestions',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'opti_behavior_funnels' ),
				'pageUrl'    => admin_url( 'admin.php?page=opti-behavior-funnels' ),
				'upgradeUrl' => admin_url( 'admin.php?page=opti-behavior-recordings' ),
				// Cosmetic only: the create endpoint re-checks the tier gate
				// server-side, so a forged flag unlocks nothing.
				'proActive'  => $this->funnel_advanced_filter_available() ? 1 : 0,
				// Per-user panel display state (1.8.4.1). '' = never chosen, so the
				// script applies the per-surface default: collapsed above a funnel
				// list, always expanded in the empty state.
				'prefs'      => $this->get_funnel_suggestions_prefs(),
				// Detected-family labels. Kept out of the recipe payload so the
				// same slug reads identically in the panel header and on a card.
				'siteTypes'  => array(
					'woocommerce' => __( 'WooCommerce', 'opti-behavior' ),
					'edd'         => __( 'Easy Digital Downloads', 'opti-behavior' ),
					'membership'  => __( 'Membership', 'opti-behavior' ),
					'lms'         => __( 'Courses', 'opti-behavior' ),
					'booking'     => __( 'Booking', 'opti-behavior' ),
					'lead'        => __( 'Lead generation', 'opti-behavior' ),
					'signup'      => __( 'Signup', 'opti-behavior' ),
					'blog'        => __( 'Blog', 'opti-behavior' ),
					'traffic'     => __( 'Traffic', 'opti-behavior' ),
				),
				'strings'    => array(
					'loading'             => __( 'Loading suggestions…', 'opti-behavior' ),
					'loadError'           => __( 'Could not load funnel suggestions.', 'opti-behavior' ),
					'networkError'        => __( 'Request failed. Please try again.', 'opti-behavior' ),
					'unknownError'        => __( 'Unknown error', 'opti-behavior' ),
					'subtitle'            => __( 'Ready-made funnels built from your own URLs. Nothing is created until you say so.', 'opti-behavior' ),
					'noSuggestions'       => __( 'No funnel suggestions for this site right now. Build one manually, or re-scan after installing a store, form or membership plugin.', 'opti-behavior' ),
					/* translators: %s: detected site type, e.g. WooCommerce. */
					'detectedBadge'       => __( '%s detected', 'opti-behavior' ),
					/* translators: %s: comma-separated list of detected site types. */
					'unresolvedBadge'     => __( 'Unresolved: %s', 'opti-behavior' ),
					'unresolvedHint'      => __( 'Detected, but no usable URL could be resolved - no funnel is guessed for it.', 'opti-behavior' ),
					'createFunnel'        => __( 'Create funnel', 'opti-behavior' ),
					'creating'            => __( 'Creating…', 'opti-behavior' ),
					'customize'           => __( 'Customize', 'opti-behavior' ),
					'dismissTitle'        => __( 'Dismiss this suggestion', 'opti-behavior' ),
					'dismissed'           => __( 'Suggestion dismissed. "Re-scan site" brings it back.', 'opti-behavior' ),
					'rescanning'          => __( 'Scanning your site…', 'opti-behavior' ),
					'rescanned'           => __( 'Site re-scanned. Dismissed suggestions are back.', 'opti-behavior' ),
					'proBadge'            => __( 'Pro', 'opti-behavior' ),
					'upgrade'             => __( 'Upgrade to unlock', 'opti-behavior' ),
					'alreadyCreated'      => __( 'Already created', 'opti-behavior' ),
					'similarExists'       => __( 'Similar funnel exists', 'opti-behavior' ),
					'createDisabledExists' => __( 'A funnel with these steps already exists.', 'opti-behavior' ),
					/* translators: %s: existing funnel name. */
					'viewExisting'        => __( 'View "%s"', 'opti-behavior' ),
					/* translators: %s: created funnel name. */
					'created'             => __( 'Funnel "%s" created.', 'opti-behavior' ),
					/* translators: 1: created funnel name, 2: number of historical sessions replayed into it. */
					'createdBackfilled'   => __( 'Funnel "%1$s" created and populated with %2$d sessions of history.', 'opti-behavior' ),
					'duplicate'           => __( 'A matching funnel already exists - nothing was created.', 'opti-behavior' ),
					'createError'         => __( 'Could not create the funnel.', 'opti-behavior' ),
					/* translators: 1: number of funnels created, 2: number of suggestions skipped. */
					'bulkCreated'         => __( 'Created %1$d funnel(s), skipped %2$d.', 'opti-behavior' ),
					/* translators: 1: number of funnels created, 2: number of suggestions skipped, 3: number of historical sessions replayed. */
					'bulkCreatedBackfilled' => __( 'Created %1$d funnel(s), skipped %2$d - populated with %3$d sessions of history.', 'opti-behavior' ),
					'stepsUnavailable'    => __( 'Step preview unavailable.', 'opti-behavior' ),
					'builderUnavailable'  => __( 'The funnel builder is not available on this screen.', 'opti-behavior' ),
					// Collapsible summary bar (1.8.4.1 UX pass).
					/* translators: 1: number of suggestions not created yet, 2: number of suggestions that already have a funnel. */
					'barCounts'           => __( '%1$d new · %2$d created', 'opti-behavior' ),
					'showPanel'           => __( 'Show', 'opti-behavior' ),
					'hidePanel'           => __( 'Hide', 'opti-behavior' ),
					/* translators: %d: number of suggestions that already have a funnel. */
					'showCreated'         => __( 'Show created (%d)', 'opti-behavior' ),
					/* translators: %d: number of suggestions that already have a funnel. */
					'hideCreated'         => __( 'Hide created (%d)', 'opti-behavior' ),
					'allCreated'          => __( 'Every funnel we suggest for this site already exists. Re-scan after installing or configuring a plugin.', 'opti-behavior' ),
				),
			)
		);
	}

	/**
	 * Enqueue frontend funnel tracking script
	 */
	public function enqueue_frontend_tracking() {
		// Don't load on admin pages
		if ( is_admin() ) {
			return;
		}

		// Never track inside the heatmap preview iframe. The preview renders the
		// page with guest context (opti_preview_as_guest) while the iframe's AJAX
		// requests still carry the logged-in admin's cookies, so the guest-scoped
		// nonce fails validation (admin-ajax 403 noise) and any accepted hit would
		// pollute funnel analytics with admin preview visits. The Pro trackers
		// (errors tracking, session recording) skip these params the same way.
		// The A/B visual editor and the admin variant preview render the page in
		// an iframe too (QA-B-TRACK-011): no tracker may be emitted there either.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only preview-mode detection (presence check only); no state change.
		if ( isset( $_GET['opti_heatmap_preview'] ) || isset( $_GET['opti_preview_as_guest'] ) || isset( $_GET['opti_preview_as_mobile'] )
			|| isset( $_GET['opti_ab_visual_editor'] ) || isset( $_GET['opti_ab_admin_preview'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Traffic & Behavior -> "Track administrators" applies to EVERY render-time
		// tracker, not only the reporter (QA-B-TRACK-012).
		if ( class_exists( 'Opti_Behavior_Heatmap_Frontend' )
			&& ! Opti_Behavior_Heatmap_Frontend::admin_tracking_allowed() ) {
			return;
		}

		// NOTE: no excluded-IP check here — render-time exclusion gets baked into
		// page-cached HTML and hides the tracker from all visitors (cache
		// poisoning). IP exclusion is enforced at ingest via
		// Opti_Behavior_Ingest_Gate.

		// Get all active funnels
		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$funnels = $wpdb->get_results(
			"SELECT id, name, steps FROM {$table_funnels} WHERE status = 'active'",
			ARRAY_A
		);

		// If no active funnels, don't load tracking script
		if ( empty( $funnels ) ) {
			return;
		}

		// Decode steps for each funnel
		foreach ( $funnels as &$funnel ) {
			$funnel['steps'] = json_decode( $funnel['steps'], true );
		}

		// Enqueue funnel tracker script. Debug JS is only a dependency while JS
		// debug is enabled (auto-expires after 3 hours); the tracker guards all
		// calls with `if (window.OptiBehaviorDebug)`, so its absence is safe.
		$debug_deps = $this->heatmap->get_debug_manager()->is_js_debug_enabled()
			? array( 'opti-behavior-debug' )
			: array();
		wp_enqueue_script(
			'opti-behavior-funnel-tracker',
			Opti_Behavior_Optimizer_Compat::js_asset_url( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR, OPTI_BEHAVIOR_HEATMAP_ASSETS_URL, 'js/funnel-tracker.js' ),
			$debug_deps,
			OPTI_BEHAVIOR_HEATMAP_VERSION,
			true
		);

		// Localize script with funnel data
		wp_localize_script(
			'opti-behavior-funnel-tracker',
			'optiBehaviorFunnelTracker',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'opti_behavior_funnels' ),
				'funnels' => $funnels,
			)
		);
	}

	/**
	 * AJAX handler for funnel data
	 */
	public function ajax_funnel_data() {
		// Verify nonce.
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'opti-behavior' ) ) );
		}

		// Get period from request.
		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '7days';
		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$cohort_start_at = isset( $_POST['cohort_start_at'] ) ? sanitize_text_field( wp_unslash( $_POST['cohort_start_at'] ) ) : '';
		$cohort_end_at = isset( $_POST['cohort_end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['cohort_end_at'] ) ) : '';
		if ( $cohort_start_at && $cohort_end_at ) {
			$start_date = $cohort_start_at;
			$end_date   = $cohort_end_at;
			// Explicit cohort bounds outrank the period preset. calculate_date_range()
			// hands the pair to Opti_Behavior_Stats_Date_Range::resolve(), which only
			// honours explicit dates for the `custom` period — without this the bounds
			// were silently dropped and a 90-day cohort returned the 7-day figures
			// (QA-B-FUNNEL-060).
			$period     = 'custom';
		}
		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;
		$filter = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : 'all';
		$country = 'all';
		if ( isset( $_POST['country'] ) ) {
			$country = map_deep( wp_unslash( $_POST['country'] ), 'sanitize_text_field' );
		}

		// PRO advanced filters (spec.md §4.3). SERVER-AUTHORITATIVE gate: parse the
		// client-supplied advanced_filters ONLY when the PRO gate passes, then strip
		// the funnel-disallowed `exit_page` field. A forged POST from a free site
		// yields an empty array here, so it changes nothing (defense in depth).
		$advanced_filters = array();
		if ( $this->funnel_advanced_filter_available() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above via check_ajax_referer().
			$advanced_filters = $this->restrict_funnel_advanced_filters(
				$this->sanitize_advanced_filters_from_request( $_POST )
			);
		}

		// Calculate date range.
		$date_range = $this->calculate_date_range( $period, $start_date, $end_date );

		// Get funnel data based on user-defined funnel.
		if ( $funnel_id > 0 ) {
			$funnel_data = $this->get_user_funnel_analytics( $funnel_id, $date_range['start'], $date_range['end'], $filter, $country, $advanced_filters );
		} else {
			// Get first active funnel as default.
			global $wpdb;
			$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			$default_funnel = $wpdb->get_row(
				"SELECT id FROM {$table_funnels} WHERE status = 'active' ORDER BY created_at ASC LIMIT 1"
			);

			if ( $default_funnel ) {
				$funnel_data = $this->get_user_funnel_analytics( $default_funnel->id, $date_range['start'], $date_range['end'], $filter, $country, $advanced_filters );
			} else {
				// No funnels defined, return empty data.
				$funnel_data = array(
					'total_entries'    => 0,
					'completed'        => 0,
					'conversion_rate'  => '0%',
					'dropoff_rate'     => '100%',
					'funnel_steps'     => array(),
					'entry_pages'      => array(),
					'exit_pages'       => array(),
				);
			}
		}

		wp_send_json_success( $funnel_data );
	}

	/**
	 * AJAX: funnel-scoped advanced-filter option lists.
	 *
	 * The detail-page advanced filter previously reused the site-wide dashboard
	 * options endpoint, so its browser/country/os/utm dropdowns showed GLOBAL
	 * session counts (e.g. Chrome 26) instead of counts scoped to the sessions
	 * that actually entered THIS funnel in the current period (max = total
	 * entries). This endpoint aggregates the same dimensions but joined through
	 * wp_opti_behavior_funnel_tracking, so every option count reflects only the
	 * funnel's own entries and can never exceed Total Entries.
	 *
	 * @since 1.8.2.8
	 */
	public function ajax_funnel_filter_options() {
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'opti-behavior' ) ) );
		}

		global $wpdb;

		$funnel_id = isset( $_REQUEST['funnel_id'] ) ? intval( $_REQUEST['funnel_id'] ) : 0;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		$period = isset( $_REQUEST['period'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['period'] ) ) : '30days';
		$start  = isset( $_REQUEST['start_date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['start_date'] ) ) : '';
		$end    = isset( $_REQUEST['end_date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['end_date'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$empty_payload = array(
			'browsers'         => array(),
			'countries'        => array(),
			'devices'          => array(),
			'os'               => array(),
			'visitor_types'    => array(),
			'traffic_channels' => array(),
			'entry_pages'      => array(),
			'exit_pages'       => array(),
			'referrers'        => array(),
			'utm_campaigns'    => array(),
			'utm_sources'      => array(),
			'utm_mediums'      => array(),
		);

		// PRO gate (parity with ajax_funnel_data): the advanced filter is a PRO
		// feature. A forged request from a free site returns empty option lists
		// so no funnel-scoped aggregates leak through a locked panel.
		if ( $funnel_id <= 0 || ! $this->funnel_advanced_filter_available() ) {
			wp_send_json_success( $empty_payload );
		}

		$date_range = $this->calculate_date_range( $period, $start, $end );
		$start_date = $date_range['start'];
		$end_date   = $date_range['end'];

		$exclude_spam = $this->resolve_spam_exclusion_from_request( $_REQUEST );

		$tracking_table = $wpdb->prefix . 'opti_behavior_funnel_tracking';
		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		$visitors_table = $wpdb->prefix . 'optibehavior_visitors';

		$spam_condition = $exclude_spam ? $this->get_spam_session_condition( 's' ) : '';
		$spam_clause    = ( '' !== $spam_condition ) ? ' AND ' . $spam_condition : '';

		// Funnel-scoped bases. entry_time uses the same half-open [start, end)
		// bounds as get_user_funnel_analytics(), and every count is
		// COUNT(DISTINCT t.session_id) so option totals track Total Entries.
		$session_scope = "FROM {$tracking_table} t INNER JOIN {$sessions_table} s ON t.session_id = s.id WHERE t.funnel_id = %d AND t.entry_time >= %s AND t.entry_time < %s" . $spam_clause;
		$join_scope    = "FROM {$tracking_table} t INNER JOIN {$sessions_table} s ON t.session_id = s.id INNER JOIN {$visitors_table} v ON s.visitor_id = v.id WHERE t.funnel_id = %d AND t.entry_time >= %s AND t.entry_time < %s" . $spam_clause;
		$range_params  = array( $funnel_id, $start_date, $end_date );

		$visitor_dim = function ( $column, $limit ) use ( $wpdb, $join_scope, $range_params ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded column/table names from $wpdb->prefix.
			return $wpdb->get_results( $wpdb->prepare(
				"SELECT v.{$column} AS value, COUNT(DISTINCT t.session_id) AS count {$join_scope} AND v.{$column} IS NOT NULL AND v.{$column} != '' GROUP BY v.{$column} ORDER BY count DESC LIMIT {$limit}",
				$range_params
			) );
		};

		$session_dim = function ( $column, $limit ) use ( $wpdb, $session_scope, $range_params ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded column/table names from $wpdb->prefix.
			return $wpdb->get_results( $wpdb->prepare(
				"SELECT s.{$column} AS value, COUNT(DISTINCT t.session_id) AS count {$session_scope} AND s.{$column} IS NOT NULL AND s.{$column} != '' GROUP BY s.{$column} ORDER BY count DESC LIMIT {$limit}",
				$range_params
			) );
		};

		$browsers = $visitor_dim( 'browser', 50 );
		$devices  = $visitor_dim( 'device_type', 10 );
		$os_list  = $visitor_dim( 'os', 30 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded table names from $wpdb->prefix.
		$countries = $wpdb->get_results( $wpdb->prepare(
			"SELECT v.country, v.country_name, COUNT(DISTINCT t.session_id) AS count {$join_scope} AND v.country IS NOT NULL AND v.country != '' GROUP BY v.country, v.country_name ORDER BY count DESC LIMIT 100",
			$range_params
		) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Hard-coded table names from $wpdb->prefix.
		$visitor_type_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(DISTINCT CASE WHEN COALESCE(v.visit_count, 1) <= 1 THEN t.session_id END) AS new_count,
			        COUNT(DISTINCT CASE WHEN COALESCE(v.visit_count, 1) > 1 THEN t.session_id END) AS returning_count
			 {$join_scope}",
			$range_params
		) );
		$visitor_types = array(
			array( 'value' => 'new', 'count' => $visitor_type_row ? (int) $visitor_type_row->new_count : 0 ),
			array( 'value' => 'returning', 'count' => $visitor_type_row ? (int) $visitor_type_row->returning_count : 0 ),
		);

		$channel_case     = method_exists( $this, 'build_traffic_channel_case_sql' ) ? $this->build_traffic_channel_case_sql() : '';
		$traffic_channels = array();
		if ( '' !== $channel_case ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static CASE fragment + tables from $wpdb->prefix.
			$traffic_channels = $wpdb->get_results( $wpdb->prepare(
				"SELECT ({$channel_case}) AS value, COUNT(DISTINCT t.session_id) AS count {$session_scope} GROUP BY value ORDER BY count DESC",
				$range_params
			) );
		}

		$utm_campaigns = $session_dim( 'utm_campaign', 50 );
		$utm_sources   = $session_dim( 'utm_source', 50 );
		$utm_mediums   = $session_dim( 'utm_medium', 50 );
		$entry_pages   = $session_dim( 'entry_page', 50 );

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$site_host = $site_host ? preg_replace( '/^www\./i', '', $site_host ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Tables from $wpdb->prefix, host esc_sql()-escaped.
		$referrers = $wpdb->get_results( $wpdb->prepare(
			"SELECT REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(s.referrer, 'https://', ''), 'http://', ''), '/', 1), '?', 1), 'www.', '') AS value,
			        COUNT(DISTINCT t.session_id) AS count
			 {$session_scope}
			 AND s.referrer IS NOT NULL AND s.referrer != ''
			 GROUP BY value
			 HAVING value != '' AND value != '" . esc_sql( $site_host ) . "'
			 ORDER BY count DESC
			 LIMIT 50",
			$range_params
		) );

		$to_value_count = function ( $rows ) {
			$out = array();
			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'value' => (string) $row->value,
					'count' => (int) $row->count,
				);
			}
			return $out;
		};

		$country_list = array();
		foreach ( (array) $countries as $row ) {
			$country_list[] = array(
				'country'      => (string) $row->country,
				'country_name' => (string) $row->country_name,
				'count'        => (int) $row->count,
			);
		}

		wp_send_json_success( array(
			'browsers'         => $to_value_count( $browsers ),
			'countries'        => $country_list,
			'devices'          => $to_value_count( $devices ),
			'os'               => $to_value_count( $os_list ),
			'visitor_types'    => $visitor_types,
			'traffic_channels' => $to_value_count( $traffic_channels ),
			'entry_pages'      => $to_value_count( $entry_pages ),
			'exit_pages'       => array(),
			'referrers'        => $to_value_count( $referrers ),
			'utm_campaigns'    => $to_value_count( $utm_campaigns ),
			'utm_sources'      => $to_value_count( $utm_sources ),
			'utm_mediums'      => $to_value_count( $utm_mediums ),
		) );
	}

	/**
	 * Calculate date range based on period
	 *
	 * @param string $period Period string.
	 * @param string $start_date Custom start date.
	 * @param string $end_date Custom end date.
	 * @return array Date range array.
	 */
	private function calculate_date_range( $period, $start_date = '', $end_date = '' ) {
		if ( 'custom' === $period && $start_date && $end_date && ( $this->has_time_component( $start_date ) || $this->has_time_component( $end_date ) ) ) {
			$start = $this->normalize_custom_datetime_bound( $start_date, 'start' );
			$end   = $this->normalize_custom_datetime_bound( $end_date, 'end' );
			return array(
				'start'      => $start,
				'end'        => $end,
				'start_date' => substr( $start, 0, 10 ),
				'end_date'   => substr( $end, 0, 10 ),
			);
		}

		if ( class_exists( 'Opti_Behavior_Stats_Date_Range' ) ) {
			$range = Opti_Behavior_Stats_Date_Range::resolve( $period, $start_date, $end_date );
			return array(
				'start'      => $range['sql_start'],
				'end'        => $range['sql_end'],
				'start_date' => $range['start_date'],
				'end_date'   => $range['end_date'],
			);
		}

		$end = gmdate( 'Y-m-d 23:59:59' );
		$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-29 days' ) );

		switch ( $period ) {
			case '7days':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-6 days' ) );
				break;
			case '90days':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-90 days' ) );
				break;
			case 'custom':
				if ( $start_date && $end_date ) {
					$start = gmdate( 'Y-m-d 00:00:00', strtotime( $start_date ) );
					$end = gmdate( 'Y-m-d 23:59:59', strtotime( $end_date ) );
				}
				break;
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Whether a custom Smart Insights bound includes an exact time.
	 *
	 * @param string $value Date or datetime value.
	 * @return bool
	 */
	private function has_time_component( $value ) {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', trim( (string) $value ) );
	}

	/**
	 * Normalize a custom date/datetime bound while preserving exact cohort times.
	 *
	 * @param string $value Date or datetime value.
	 * @param string $bound start|end.
	 * @return string MySQL datetime.
	 */
	private function normalize_custom_datetime_bound( $value, $bound ) {
		$value = trim( str_replace( 'T', ' ', (string) $value ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$value .= 'end' === $bound ? ' 23:59:59' : ' 00:00:00';
		}

		$timestamp = strtotime( $value );
		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : gmdate( 'Y-m-d ' . ( 'end' === $bound ? '23:59:59' : '00:00:00' ) );
	}

	/**
	 * Get user-defined funnel analytics data
	 *
	 * @param int    $funnel_id Funnel ID.
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @param string $filter Device filter (all, desktop, mobile, tablet).
	 * @param mixed  $country Country filter (all, country code, comma-separated codes, or array of codes).
	 * @param array  $advanced_filters PRO advanced filters (already sanitized + gated + exit_page-stripped).
	 * @return array Funnel data.
	 */
	private function get_user_funnel_analytics( $funnel_id, $start_date, $end_date, $filter = 'all', $country = 'all', array $advanced_filters = array() ) {
		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';
		$table_tracking = $wpdb->prefix . 'opti_behavior_funnel_tracking';
		$table_sessions = $wpdb->prefix . 'optibehavior_sessions';
		$table_visitors = $wpdb->prefix . 'optibehavior_visitors';

		// Get funnel definition.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$funnel = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_funnels} WHERE id = %d AND status = 'active'",
				$funnel_id
			)
		);

		if ( ! $funnel ) {
			return array(
				'total_entries'    => 0,
				'completed'        => 0,
				'conversion_rate'  => '0%',
				'dropoff_rate'     => '100%',
				'funnel_steps'     => array(),
				'entry_pages'      => array(),
				'exit_pages'       => array(),
			);
		}

		$steps_definitions = $this->read_funnel_steps( $funnel->steps );
		$country_codes     = $this->normalize_country_filter( $country );

		// Build device and country filter clause.
		// Only JOIN to sessions/visitors tables when device or country filtering is actually needed.
		// An unconditional INNER JOIN would silently drop all funnel records whose session_id
		// has no matching row in optibehavior_sessions (e.g. anonymous/pre-consent sessions).
		// PRO advanced filters (spec.md §4.4). Reuse the SHARED SQL builder — its
		// documented aliases (`s`=sessions, `v`=visitors) are exactly the JOIN
		// aliases below, so the WHERE fragment drops straight in. $advanced_filters
		// is already sanitized + PRO-gated + exit_page-stripped by the caller;
		// build_advanced_filters_sql() re-checks the allow-list independently.
		$advanced_sql    = $this->build_advanced_filters_sql( $advanced_filters );
		$advanced_where  = $advanced_sql['where'];   // ' AND (...)' with %s/%d placeholders, or ''.
		$advanced_params = $advanced_sql['params'];  // Positional params for the placeholders above.
		$has_advanced    = ( '' !== $advanced_where );

		$exclude_spam  = $this->resolve_spam_exclusion_from_request();
		// Any active filter forces the sessions/visitors JOIN; an unconditional
		// INNER JOIN would silently drop funnel records whose session has no row
		// in optibehavior_sessions (anonymous/pre-consent hits).
		$needs_join    = ( $filter !== 'all' || ! empty( $country_codes ) || $exclude_spam || $has_advanced );
		$filter_join   = $needs_join
			? "INNER JOIN {$table_sessions} s ON t.session_id = s.id
			   INNER JOIN {$table_visitors} v ON s.visitor_id = v.id"
			: '';
		$filter_where  = '';
		$filters_applied = array();

		if ( $filter !== 'all' ) {
			$filters_applied[] = $wpdb->prepare( "v.device_type = %s", $filter );
		}

		if ( ! empty( $country_codes ) ) {
			if ( count( $country_codes ) === 1 ) {
				$filters_applied[] = $wpdb->prepare( 'UPPER(v.country) = %s', $country_codes[0] );
			} elseif ( count( $country_codes ) > 1 ) {
				$placeholders = implode( ',', array_fill( 0, count( $country_codes ), '%s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prepared fragment is used in the final query below.
				$filters_applied[] = $wpdb->prepare( "UPPER(v.country) IN ($placeholders)", ...$country_codes );
			}
		}

		if ( $exclude_spam ) {
			$spam_condition = $this->get_spam_session_condition( 's' );
			if ( '' !== $spam_condition ) {
				$filters_applied[] = $spam_condition;
			}
		}

		if ( ! empty( $filters_applied ) ) {
			$filter_where = 'AND ' . implode( ' AND ', $filters_applied );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Get all tracking records in date range.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total_entries = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT t.session_id)
				FROM {$table_tracking} t
				{$filter_join}
				WHERE t.funnel_id = %d
					AND t.entry_time >= %s
					AND t.entry_time < %s
					{$filter_where}
					{$advanced_where}",
				array_merge( array( $funnel_id, $start_date, $end_date ), $advanced_params )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $total_entries ) {
			$total_entries = 0;
		}

		// Calculate step-by-step progression.
		$funnel_steps = array();
		$initial_entries = $total_entries; // Keep initial count for all comparisons

		foreach ( $steps_definitions as $index => $step_def ) {
			$step_number = (int) $index + 1;

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Count sessions that reached this step or beyond.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			$completed = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT t.session_id)
					FROM {$table_tracking} t
					{$filter_join}
					WHERE t.funnel_id = %d
						AND t.entry_time >= %s
						AND t.entry_time < %s
						AND t.max_step_reached >= %d
						{$filter_where}
						{$advanced_where}",
					array_merge( array( $funnel_id, $start_date, $end_date, $step_number ), $advanced_params )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! $completed ) {
				$completed = 0;
			}

			// Always calculate against initial entries (Step 1), not previous step
			$abandoned = $initial_entries - $completed;
			$completion_rate = $initial_entries > 0 ? round( ( $completed / $initial_entries ) * 100, 1 ) : 0;
			$abandonment_rate = round( 100 - $completion_rate, 1 );

			$funnel_steps[] = array(
				'step_number'      => $step_number,
				'step_name'        => $step_def['name'],
				'completed'        => $completed,
				'abandoned'        => $abandoned,
				'completion_rate'  => $completion_rate,
				'abandonment_rate' => $abandonment_rate,
				'total_from_prev'  => $initial_entries, // Always show total from initial
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Get completed count (sessions that completed all steps).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$completed = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT t.session_id)
				FROM {$table_tracking} t
				{$filter_join}
				WHERE t.funnel_id = %d
					AND t.entry_time >= %s
					AND t.entry_time < %s
					AND t.completed = 1
					{$filter_where}
					{$advanced_where}",
				array_merge( array( $funnel_id, $start_date, $end_date ), $advanced_params )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $completed ) {
			$completed = 0;
		}

		$conversion_rate = $total_entries > 0 ? round( ( $completed / $total_entries ) * 100, 1 ) : 0;
		$dropoff_rate = 100 - $conversion_rate;

		// Get entry pages.
		$entry_pages = $this->get_top_entry_pages( $start_date, $end_date, 10 );

		// Get exit pages.
		$exit_pages = $this->get_top_exit_pages( $start_date, $end_date, 10 );

		return array(
			'funnel_id'        => $funnel_id,
			'funnel_name'      => $funnel->name,
			'total_entries'    => (int) $total_entries,
			'completed'        => (int) $completed,
			'conversion_rate'  => $conversion_rate . '%',
			'dropoff_rate'     => $dropoff_rate . '%',
			'funnel_steps'     => $funnel_steps,
			'entry_pages'      => $entry_pages,
			'exit_pages'       => $exit_pages,
		);
	}

	/**
	 * Normalize funnel country filters from AJAX and tests.
	 *
	 * Accepts scalar comma-separated strings and array submissions, then returns
	 * unique uppercase two-letter country codes. Empty or malformed input maps to
	 * an empty list, which means "all countries" for analytics queries.
	 *
	 * @param mixed $country Raw country filter input.
	 * @return array<int, string> Normalized country codes.
	 */
	private function normalize_country_filter( $country ) {
		if ( is_string( $country ) && 'all' === strtolower( trim( $country ) ) ) {
			return array();
		}

		$pending = is_array( $country ) ? $country : array( $country );
		$codes   = array();

		while ( ! empty( $pending ) ) {
			$value = array_shift( $pending );

			if ( is_array( $value ) ) {
				foreach ( $value as $nested_value ) {
					$pending[] = $nested_value;
				}
				continue;
			}

			if ( null === $value ) {
				continue;
			}

			foreach ( explode( ',', (string) $value ) as $token ) {
				$code = strtoupper( sanitize_text_field( trim( $token ) ) );

				if ( ! preg_match( '/^[A-Z]{2}$/', $code ) ) {
					continue;
				}

				$codes[ $code ] = true;
			}
		}

		return array_keys( $codes );
	}

	/**
	 * Get funnel analytics data (legacy - for page depth tracking)
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array Funnel data.
	 */
	private function get_funnel_analytics( $start_date, $end_date ) {
		global $wpdb;
		$table_sessions = $wpdb->prefix . 'optibehavior_sessions';
		$table_events = $wpdb->prefix . 'opti_behavior_events';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Get all sessions in date range.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total_sessions = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT session_id)
				FROM {$table_sessions}
				WHERE created_at >= %s AND created_at <= %s",
				$start_date,
				$end_date
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $total_sessions ) {
			$total_sessions = 0;
		}

		// Define funnel steps based on page depth.
		$funnel_steps = $this->calculate_funnel_steps( $start_date, $end_date );

		// Get entry pages.
		$entry_pages = $this->get_top_entry_pages( $start_date, $end_date, 10 );

		// Get exit pages.
		$exit_pages = $this->get_top_exit_pages( $start_date, $end_date, 10 );

		// Calculate overall metrics.
		$completed = isset( $funnel_steps[count( $funnel_steps ) - 1] ) ? $funnel_steps[count( $funnel_steps ) - 1]['completed'] : 0;
		$conversion_rate = $total_sessions > 0 ? round( ( $completed / $total_sessions ) * 100, 1 ) : 0;
		$dropoff_rate = 100 - $conversion_rate;

		return array(
			'total_entries'    => (int) $total_sessions,
			'completed'        => (int) $completed,
			'conversion_rate'  => $conversion_rate . '%',
			'dropoff_rate'     => $dropoff_rate . '%',
			'funnel_steps'     => $funnel_steps,
			'entry_pages'      => $entry_pages,
			'exit_pages'       => $exit_pages,
		);
	}

	/**
	 * Calculate funnel steps based on session progression
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @return array Funnel steps data.
	 */
	private function calculate_funnel_steps( $start_date, $end_date ) {
		global $wpdb;
		$table_sessions = $wpdb->prefix . 'optibehavior_sessions';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Get session page counts distribution.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$page_depth_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					page_count,
					COUNT(DISTINCT session_id) as sessions
				FROM {$table_sessions}
				WHERE created_at >= %s AND created_at <= %s
				GROUP BY page_count
				ORDER BY page_count ASC",
				$start_date,
				$end_date
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Define funnel steps: Entry, 2+ pages, 3+ pages, 4+ pages, 5+ pages, 6+ pages.
		$steps = array(
			array( 'name' => 'All Sessions', 'min_pages' => 1 ),
			array( 'name' => '2+ Page Views', 'min_pages' => 2 ),
			array( 'name' => '3+ Page Views', 'min_pages' => 3 ),
			array( 'name' => '4+ Page Views', 'min_pages' => 4 ),
			array( 'name' => '5+ Page Views', 'min_pages' => 5 ),
			array( 'name' => '6+ Page Views', 'min_pages' => 6 ),
		);

		$funnel_steps = array();
		$total_sessions = 0;

		// Calculate total sessions.
		foreach ( $page_depth_data as $row ) {
			$total_sessions += (int) $row['sessions'];
		}

		$previous_completed = $total_sessions;

		// Calculate each step.
		foreach ( $steps as $index => $step ) {
			$completed = 0;

			// Sum sessions that meet minimum page requirement.
			foreach ( $page_depth_data as $row ) {
				if ( (int) $row['page_count'] >= $step['min_pages'] ) {
					$completed += (int) $row['sessions'];
				}
			}

			$abandoned = $previous_completed - $completed;
			$completion_rate = $previous_completed > 0 ? round( ( $completed / $previous_completed ) * 100, 1 ) : 0;
			$abandonment_rate = round( 100 - $completion_rate, 1 );

			$funnel_steps[] = array(
				'step_number'      => $index + 1,
				'step_name'        => $step['name'],
				'completed'        => $completed,
				'abandoned'        => $abandoned,
				'completion_rate'  => $completion_rate,
				'abandonment_rate' => $abandonment_rate,
				'total_from_prev'  => $previous_completed,
			);

			$previous_completed = $completed;
		}

		return $funnel_steps;
	}

	/**
	 * Get top entry pages
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @param int    $limit Result limit.
	 * @return array Entry pages data.
	 */
	private function get_top_entry_pages( $start_date, $end_date, $limit = 10 ) {
		global $wpdb;
		$table_sessions = $wpdb->prefix . 'optibehavior_sessions';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					entry_page,
					COUNT(*) as entries,
					AVG(page_views) as avg_depth
				FROM {$table_sessions}
				WHERE start_time >= %s AND start_time <= %s
					AND entry_page IS NOT NULL
					AND entry_page != ''
				GROUP BY entry_page
				ORDER BY entries DESC
				LIMIT %d",
				$start_date,
				$end_date,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array_map(
			function( $row ) {
				return array(
					'page'       => $row['entry_page'],
					'entries'    => (int) $row['entries'],
					'avg_depth'  => round( (float) $row['avg_depth'], 1 ),
				);
			},
			$results
		);
	}

	/**
	 * Get top exit pages
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date End date.
	 * @param int    $limit Result limit.
	 * @return array Exit pages data.
	 */
	private function get_top_exit_pages( $start_date, $end_date, $limit = 10 ) {
		global $wpdb;
		$table_sessions = $wpdb->prefix . 'optibehavior_sessions';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					exit_page,
					COUNT(*) as exits,
					AVG(page_views) as avg_depth
				FROM {$table_sessions}
				WHERE start_time >= %s AND start_time <= %s
					AND exit_page IS NOT NULL
					AND exit_page != ''
				GROUP BY exit_page
				ORDER BY exits DESC
				LIMIT %d",
				$start_date,
				$end_date,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array_map(
			function( $row ) {
				return array(
					'page'      => $row['exit_page'],
					'exits'     => (int) $row['exits'],
					'avg_depth' => round( (float) $row['avg_depth'], 1 ),
				);
			},
			$results
		);
	}

	/**
	 * Global default for excluding spam, controlled by Traffic Behavior settings.
	 *
	 * Missing legacy option rows are treated as enabled because the settings UI
	 * renders spam detection enabled by default.
	 *
	 * @return bool
	 */
	private function get_global_spam_exclusion_default() {
		$traffic_settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_detection_enabled' => true,
			)
		);

		if ( ! is_array( $traffic_settings ) ) {
			return true;
		}

		return ! array_key_exists( 'spam_detection_enabled', $traffic_settings ) || ! empty( $traffic_settings['spam_detection_enabled'] );
	}

	/**
	 * Resolve the funnel spam filter state from request input, falling back to settings.
	 *
	 * @param array|null $source Optional request source.
	 * @return bool
	 */
	public function resolve_spam_exclusion_from_request( $source = null ) {
		if ( null === $source ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter state; no state change, nonce not required.
			$source = $_REQUEST;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		if ( is_array( $source ) && array_key_exists( 'exclude_spam', $source ) ) {
			return '1' === (string) sanitize_text_field( wp_unslash( $source['exclude_spam'] ) );
		}

		return $this->get_global_spam_exclusion_default();
	}

	/**
	 * Build spam exclusion condition for a sessions table alias.
	 *
	 * @param string $session_alias Sessions table alias.
	 * @return string SQL condition without leading AND.
	 */
	private function get_spam_session_condition( $session_alias = 's' ) {
		if ( class_exists( 'Opti_Behavior_Stats_Spam_Filter' ) ) {
			$where = Opti_Behavior_Stats_Spam_Filter::session_sql( $session_alias, 'AND', true );
			return ltrim( preg_replace( '/^\\s*AND\\s+/i', '', $where ) );
		}

		$traffic_settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_duration_threshold' => 3,
			)
		);
		$spam_duration = isset( $traffic_settings['spam_duration_threshold'] ) ? max( 0, absint( $traffic_settings['spam_duration_threshold'] ) ) : 3;

		return "({$session_alias}.traffic_type IS NULL OR {$session_alias}.traffic_type = '' OR {$session_alias}.traffic_type NOT IN ('spam', 'bot', 'automated')) AND COALESCE({$session_alias}.duration, 0) >= {$spam_duration}";
	}

	/**
	 * AJAX handler to get all funnels
	 */
	public function ajax_get_funnels() {
		// Verify nonce.
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'opti-behavior' ) ) );
		}

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$funnels = $wpdb->get_results(
			"SELECT id, name, description, steps, status, created_at
			FROM {$table_funnels}
			WHERE status = 'active'
			ORDER BY created_at DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Decode steps JSON for each funnel.
		foreach ( $funnels as &$funnel ) {
			$funnel['steps'] = json_decode( $funnel['steps'], true );
		}

		wp_send_json_success( $funnels );
	}

	/**
	 * AJAX handler for the funnels list summary.
	 *
	 * Returns per-funnel KPI rows plus global totals for the current global
	 * period/device filter in a single request, mirroring the A/B testing list
	 * endpoint so the summary stat-bar and per-row badges load together (no N
	 * per-card round-trips).
	 */
	public function ajax_get_funnels_summary() {
		// Verify nonce.
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'opti-behavior' ) ) );
		}

		// Resolve the global period/device filter from the request.
		$period          = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '7days';
		$filter          = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : 'all';
		$start_date      = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date        = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$cohort_start_at = isset( $_POST['cohort_start_at'] ) ? sanitize_text_field( wp_unslash( $_POST['cohort_start_at'] ) ) : '';
		$cohort_end_at   = isset( $_POST['cohort_end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['cohort_end_at'] ) ) : '';
		if ( $cohort_start_at && $cohort_end_at ) {
			$start_date = $cohort_start_at;
			$end_date   = $cohort_end_at;
			// Explicit cohort bounds outrank the period preset. calculate_date_range()
			// hands the pair to Opti_Behavior_Stats_Date_Range::resolve(), which only
			// honours explicit dates for the `custom` period — without this the bounds
			// were silently dropped and a 90-day cohort returned the 7-day figures
			// (QA-B-FUNNEL-060).
			$period     = 'custom';
		}

		$date_range = $this->calculate_date_range( $period, $start_date, $end_date );

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$funnels = $wpdb->get_results(
			"SELECT id, name, description, steps, status
			FROM {$table_funnels}
			WHERE status IN ( 'active', 'suspended' )
			ORDER BY created_at DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		$rows               = array();
		$total_entries      = 0;
		$total_completions  = 0;
		$funnel_count       = 0;

		if ( ! empty( $funnels ) ) {
			foreach ( $funnels as $funnel ) {
				$funnel_id   = (int) $funnel['id'];
				$steps       = json_decode( $funnel['steps'], true );
				$step_count  = is_array( $steps ) ? count( $steps ) : 0;

				$analytics = $this->get_user_funnel_analytics(
					$funnel_id,
					$date_range['start'],
					$date_range['end'],
					$filter,
					'all'
				);

				$entries        = isset( $analytics['total_entries'] ) ? (int) $analytics['total_entries'] : 0;
				$completions    = isset( $analytics['completed'] ) ? (int) $analytics['completed'] : 0;
				$conversion     = $entries > 0 ? round( ( $completions / $entries ) * 100, 1 ) : 0.0;
				$dropoff        = round( 100 - $conversion, 1 );

				// Backward-compat: a missing/blank status is treated as 'active'.
				$status = ! empty( $funnel['status'] ) ? $funnel['status'] : 'active';

				$rows[] = array(
					'id'              => $funnel_id,
					'name'            => $funnel['name'],
					'description'     => $funnel['description'],
					'step_count'      => $step_count,
					'entries'         => $entries,
					'completions'     => $completions,
					'conversion_rate' => $conversion,
					'dropoff_rate'    => $dropoff,
					'status'          => $status,
				);

				$total_entries     += $entries;
				$total_completions += $completions;
				$funnel_count++;
			}
		}

		// Weighted average: total completions / total entries (not the mean of
		// per-funnel rates). Divide-by-zero guarded → 0 entries yields 0%.
		$avg_conversion = $total_entries > 0 ? round( ( $total_completions / $total_entries ) * 100, 1 ) : 0.0;

		wp_send_json_success(
			array(
				'funnels' => $rows,
				'totals'  => array(
					'funnels'             => $funnel_count,
					'entries'             => $total_entries,
					'completions'         => $total_completions,
					'avg_conversion_rate' => $avg_conversion,
				),
			)
		);
	}

	/**
	 * AJAX handler for a SINGLE funnel's collapsed-row KPI badges.
	 *
	 * Each funnel row owns its own Period + Device selection (independent per
	 * funnel). When that selection changes the client re-fetches only that row's
	 * KPI here, then re-aggregates the top summary client-side. Mirrors the
	 * per-funnel computation in ajax_get_funnels_summary().
	 */
	public function ajax_get_funnel_kpi() {
		// Verify nonce.
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'opti-behavior' ) ) );
		}

		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;
		if ( $funnel_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid funnel ID', 'opti-behavior' ) ) );
		}

		// Resolve this funnel's own period/device filter from the request.
		$period          = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '7days';
		$filter          = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : 'all';
		$start_date      = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date        = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$cohort_start_at = isset( $_POST['cohort_start_at'] ) ? sanitize_text_field( wp_unslash( $_POST['cohort_start_at'] ) ) : '';
		$cohort_end_at   = isset( $_POST['cohort_end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['cohort_end_at'] ) ) : '';
		if ( $cohort_start_at && $cohort_end_at ) {
			$start_date = $cohort_start_at;
			$end_date   = $cohort_end_at;
			// Explicit cohort bounds outrank the period preset. calculate_date_range()
			// hands the pair to Opti_Behavior_Stats_Date_Range::resolve(), which only
			// honours explicit dates for the `custom` period — without this the bounds
			// were silently dropped and a 90-day cohort returned the 7-day figures
			// (QA-B-FUNNEL-060).
			$period     = 'custom';
		}

		$date_range = $this->calculate_date_range( $period, $start_date, $end_date );

		$analytics   = $this->get_user_funnel_analytics( $funnel_id, $date_range['start'], $date_range['end'], $filter, 'all' );
		$entries     = isset( $analytics['total_entries'] ) ? (int) $analytics['total_entries'] : 0;
		$completions = isset( $analytics['completed'] ) ? (int) $analytics['completed'] : 0;
		$conversion  = $entries > 0 ? round( ( $completions / $entries ) * 100, 1 ) : 0.0;
		$dropoff     = round( 100 - $conversion, 1 );

		wp_send_json_success(
			array(
				'id'              => $funnel_id,
				'entries'         => $entries,
				'completions'     => $completions,
				'conversion_rate' => $conversion,
				'dropoff_rate'    => $dropoff,
			)
		);
	}

	/**
	 * AJAX handler to get a single funnel by ID
	 */
	public function ajax_get_funnel() {
		// Verify nonce.
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'opti-behavior' ) ) );
		}

		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;

		if ( $funnel_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid funnel ID', 'opti-behavior' ) ) );
		}

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$funnel = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, description, steps, status, created_at
				FROM {$table_funnels}
				WHERE id = %d",
				$funnel_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $funnel ) {
			wp_send_json_error( array( 'message' => __( 'Funnel not found', 'opti-behavior' ) ) );
		}

		// Decode steps JSON.
		$funnel['steps'] = json_decode( $funnel['steps'], true );

		wp_send_json_success( $funnel );
	}

	/**
	 * AJAX handler to save funnel
	 */
	public function ajax_save_funnel() {
		// Verify nonce.
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'opti-behavior' ) ) );
		}

		// Get funnel data.
		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		// NOT sanitize_text_field(): this field is a JSON document, and that filter
		// strips `<`-led sequences and collapses newlines, so a step whose
		// url_pattern contains `<` (e.g. the regex `product/[^<]+`) was silently
		// mangled before json_decode() ever saw it — the builder then either failed
		// with "Invalid steps format" or persisted a corrupted pattern
		// (QA-B-FUNNEL-035). Every decoded field is sanitized in
		// validate_funnel_steps() below instead.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JSON payload; each decoded field is sanitized in validate_funnel_steps().
		$steps = isset( $_POST['steps'] ) ? wp_unslash( $_POST['steps'] ) : '';
		if ( ! is_string( $steps ) ) {
			$steps = '';
		}

		// Validate.
		if ( empty( $name ) || empty( $steps ) ) {
			wp_send_json_error( array( 'message' => __( 'Name and steps are required', 'opti-behavior' ) ) );
		}

		// Decode and validate steps. A "non-empty array" check is not enough: an
		// object-shaped JSON map ({"a":1}) satisfies it, persists, and then makes
		// the whole Funnels list fatal (QA-B-FUNNEL-010).
		$steps_array = $this->validate_funnel_steps( $steps );
		if ( false === $steps_array ) {
			wp_send_json_error( array( 'message' => __( 'Invalid steps format', 'opti-behavior' ) ) );
		}

		// Single shared write path (insert + update + cache purge).
		$saved_id = $this->persist_funnel(
			array(
				'name'        => $name,
				'description' => $description,
				'steps'       => $steps_array,
				'status'      => 'active',
			),
			$funnel_id
		);

		if ( is_wp_error( $saved_id ) ) {
			wp_send_json_error( array( 'message' => $saved_id->get_error_message() ) );
		}

		wp_send_json_success( array( 'funnel_id' => $saved_id, 'message' => __( 'Funnel saved successfully', 'opti-behavior' ) ) );
	}

	/**
	 * Tolerant reader for a stored funnel `steps` payload.
	 *
	 * Accepts the raw JSON column (or an already-decoded value) and ALWAYS returns
	 * a zero-indexed LIST whose every entry carries `url_pattern` and `match_type`
	 * strings. Legacy rows, a third-party `opti_behavior_funnel_recipe_steps`
	 * filter and hand-crafted POSTs from before the write-side validation landed
	 * could store an object-shaped map or a step missing either key; the matcher
	 * then emitted a PHP warning on EVERY frontend request via
	 * track_all_requests(), and get_user_funnel_analytics() added a string key to
	 * an int and died with a TypeError (KNOWN-SUSPECTS FUNNEL 1,
	 * QA-B-FUNNEL-010/011).
	 *
	 * @since 1.9.0.7
	 *
	 * @param mixed $steps Raw JSON string, or an already-decoded steps value.
	 * @return array Zero-indexed list of usable step definitions (possibly empty).
	 */
	private function read_funnel_steps( $steps ) {
		if ( is_string( $steps ) ) {
			$steps = json_decode( $steps, true );
		}

		if ( ! is_array( $steps ) ) {
			return array();
		}

		$clean = array();

		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}

			$pattern    = ( isset( $step['url_pattern'] ) && is_scalar( $step['url_pattern'] ) ) ? (string) $step['url_pattern'] : '';
			$match_type = ( isset( $step['match_type'] ) && is_scalar( $step['match_type'] ) ) ? (string) $step['match_type'] : '';

			if ( '' === $match_type ) {
				continue;
			}

			// `any` / `pageview` deliberately ignore their pattern; every other
			// match type without one would match nothing (or everything).
			if ( '' === $pattern && ! in_array( $match_type, array( 'any', 'pageview' ), true ) ) {
				continue;
			}

			$step['url_pattern'] = $pattern;
			$step['match_type']  = $match_type;

			if ( ! isset( $step['name'] ) || ! is_scalar( $step['name'] ) || '' === (string) $step['name'] ) {
				/* translators: %d: funnel step number. */
				$step['name'] = sprintf( __( 'Step %d', 'opti-behavior' ), count( $clean ) + 1 );
			} else {
				$step['name'] = (string) $step['name'];
			}

			$clean[] = $step;
		}

		return $clean;
	}

	/**
	 * Write-side validation for a funnel `steps` payload.
	 *
	 * The historic check was "a non-empty array", which an object-shaped JSON map
	 * ({"a":1}) satisfies. Such a row persisted happily and then took the Funnels
	 * list down for every admin: get_user_funnel_analytics() computes
	 * `$index + 1` over the step keys, and PHP 8 throws
	 * `TypeError: Unsupported operand types: string + int` (QA-B-FUNNEL-010).
	 * Steps must therefore be a LIST whose EVERY entry carries a usable
	 * `url_pattern` + `match_type` pair (QA-B-FUNNEL-011) — a partially usable
	 * payload is rejected rather than silently tracking fewer steps than the user
	 * defined.
	 *
	 * @since 1.9.0.7
	 *
	 * @param mixed $steps Raw JSON string, or an already-decoded steps value.
	 * @return array|false Sanitized zero-indexed step list, or false when invalid.
	 */
	private function validate_funnel_steps( $steps ) {
		if ( is_string( $steps ) ) {
			$steps = json_decode( $steps, true );
		}

		if ( ! is_array( $steps ) || empty( $steps ) ) {
			return false;
		}

		// A LIST, never an object-shaped map.
		if ( array_keys( $steps ) !== range( 0, count( $steps ) - 1 ) ) {
			return false;
		}

		$clean = $this->read_funnel_steps( $steps );

		if ( count( $clean ) !== count( $steps ) ) {
			return false;
		}

		foreach ( $clean as $index => $step ) {
			// Names are display text; patterns are NOT (a regex may legitimately
			// contain `<`, which sanitize_text_field() would eat — see
			// QA-B-FUNNEL-035), so they only lose control characters and length.
			$clean[ $index ]['name']        = sanitize_text_field( $step['name'] );
			$clean[ $index ]['url_pattern'] = substr( preg_replace( '/[\x00-\x1F\x7F]/', '', $step['url_pattern'] ), 0, 500 );
			$clean[ $index ]['match_type']  = sanitize_key( $step['match_type'] );
		}

		return $clean;
	}

	/**
	 * Shared funnel persistence service — the single write path for funnel
	 * definitions (manual builder saves AND auto-builder recipe creations).
	 *
	 * Extracted from ajax_save_funnel() so that every producer of a funnel row
	 * goes through the same validation, the same column set and — critically —
	 * the same ONE cache-purge site. The frontend inline
	 * optiBehaviorFunnelTracker config is baked into cached page HTML, so a
	 * write path that forgets to purge silently produces a funnel that records
	 * nothing until the cache expires (WP Rocket default lifespan is 10 h).
	 *
	 * Provenance columns (`source`, `recipe_id`, spec.md §4.1) are handled here:
	 *
	 * - On INSERT they are always written (defaults: `manual` / NULL).
	 * - On UPDATE they are written ONLY when explicitly supplied, so that a user
	 *   editing an auto-created funnel in the builder does not silently reset
	 *   its provenance back to `manual`.
	 *
	 * @since 1.8.4
	 *
	 * @param array $data {
	 *     Funnel fields.
	 *
	 *     @type string       $name        Required. Funnel name.
	 *     @type string       $description Optional. Funnel description.
	 *     @type array|string $steps       Required. Step array (or its JSON string).
	 *     @type string       $status      Optional. 'active' (default) | 'suspended' | 'deleted'.
	 *     @type string       $source      Optional. 'manual' (default) | 'auto' | 'discovered'.
	 *     @type string|null  $recipe_id   Optional. Auto-builder recipe provenance.
	 * }
	 * @param int   $funnel_id Existing funnel id to update; 0 (default) inserts.
	 * @return int|WP_Error Funnel id on success, WP_Error on validation/DB failure.
	 */
	public function persist_funnel( array $data, $funnel_id = 0 ) {
		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		$funnel_id = max( 0, (int) $funnel_id );

		$name        = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		$description = isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '';

		// Shared write-path validation: steps must be a LIST of usable step
		// definitions, never an object-shaped map (QA-B-FUNNEL-010/011).
		$steps = isset( $data['steps'] ) ? $this->validate_funnel_steps( $data['steps'] ) : false;

		if ( '' === $name ) {
			return new WP_Error( 'opti_behavior_funnel_missing_name', 'Name and steps are required' );
		}
		if ( false === $steps ) {
			return new WP_Error( 'opti_behavior_funnel_invalid_steps', 'Invalid steps format' );
		}

		// Status allow-list; 'deleted' stays reachable so the shared path can
		// express every state the dedicated handlers already produce.
		$status = isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'active';
		if ( ! in_array( $status, array( 'active', 'suspended', 'deleted' ), true ) ) {
			$status = 'active';
		}

		$row     = array(
			'name'        => $name,
			'description' => $description,
			'steps'       => wp_json_encode( $steps ),
			'status'      => $status,
		);
		$formats = array( '%s', '%s', '%s', '%s' );

		// Provenance: always on insert, opt-in on update (see docblock).
		$is_insert = ( 0 === $funnel_id );

		if ( $is_insert || array_key_exists( 'source', $data ) ) {
			$source = isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'manual';
			if ( ! in_array( $source, array( 'manual', 'auto', 'discovered' ), true ) ) {
				$source = 'manual';
			}
			$row['source'] = $source;
			$formats[]     = '%s';
		}

		if ( $is_insert || array_key_exists( 'recipe_id', $data ) ) {
			$recipe_id = isset( $data['recipe_id'] ) ? sanitize_key( $data['recipe_id'] ) : '';
			$recipe_id = ( '' === $recipe_id ) ? null : substr( $recipe_id, 0, 64 );

			$row['recipe_id'] = $recipe_id;
			$formats[]        = '%s';
		}

		if ( $funnel_id > 0 ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Update existing funnel.
			$result = $wpdb->update(
				$table_funnels,
				$row,
				array( 'id' => $funnel_id ),
				$formats,
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( false === $result ) {
				return new WP_Error( 'opti_behavior_funnel_update_failed', 'Funnel could not be saved' );
			}
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Insert new funnel.
			$result = $wpdb->insert(
				$table_funnels,
				$row,
				$formats
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( false === $result ) {
				return new WP_Error( 'opti_behavior_funnel_insert_failed', 'Funnel could not be saved' );
			}

			$funnel_id = (int) $wpdb->insert_id;
		}

		// The funnel list + steps are baked into every frontend page as the
		// inline optiBehaviorFunnelTracker config; stale cached HTML would keep
		// serving the OLD funnel definitions (0 entries for a new funnel until
		// the cache expires — WP Rocket default lifespan is 10 h).
		$this->purge_page_caches(
			array(
				'action'    => 'save',
				'funnel_id' => $funnel_id,
			)
		);

		return $funnel_id;
	}

	/**
	 * The auto-funnel builder, wired to this instance as its persistence service
	 * so every generated funnel goes through the same persist_funnel() write path
	 * (and therefore the same single cache-purge site) as a manual builder save.
	 *
	 * @since 1.8.4
	 * @return Opti_Behavior_Funnel_Auto_Builder
	 */
	public function get_auto_builder() {
		if ( null === $this->auto_builder ) {
			$this->auto_builder = new Opti_Behavior_Funnel_Auto_Builder( $this );
		}
		return $this->auto_builder;
	}

	/**
	 * Guard shared by the four auto-funnel endpoints: valid nonce + admin.
	 *
	 * Exits with a JSON error response when the request is not authorized.
	 *
	 * Also raises the memory ceiling for the authorized request. The Funnels page
	 * fires several of these endpoints concurrently on load, and admin-ajax.php —
	 * unlike admin.php — never applies WP's admin memory limit, so on a busy site
	 * the PHP default can be exhausted by other plugins' bootstrap work and the
	 * request that loses the race dies with a 500 before any handler runs. Raising
	 * here (once, after the request is proven to come from an authenticated admin,
	 * so an anonymous flood can never use it to inflate memory) covers every
	 * funnel endpoint without per-handler duplication.
	 *
	 * @since 1.8.4
	 * @return void
	 */
	private function check_funnel_ajax_access() {
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'opti-behavior' ) ) );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
	}

	/**
	 * AJAX: the suggestion payload for this site (spec.md §4.2).
	 *
	 * `force=1` is the "Re-scan site" button: it bypasses the 12 h detection
	 * transient AND clears the dismissal list, which is what makes dismissing a
	 * card reversible (spec.md §3.5).
	 *
	 * @since 1.8.4
	 * @return void
	 */
	public function ajax_funnel_suggestions() {
		$this->check_funnel_ajax_access();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in check_funnel_ajax_access().
		$force = isset( $_POST['force'] ) ? ( '1' === (string) sanitize_text_field( wp_unslash( $_POST['force'] ) ) ) : false;

		$builder = $this->get_auto_builder();

		if ( $force ) {
			// A re-scan restores every dismissed card (locked decision 8: the
			// user must always be able to get a suggestion back).
			$builder->clear_dismissed();
		}

		$context = $builder->get_context( $force );

		wp_send_json_success( $builder->get_suggestions( $context ) );
	}

	/**
	 * AJAX: create one funnel from a recipe (spec.md §4.2).
	 *
	 * Returns `{ duplicate: true, existing_funnel_id }` instead of inserting when
	 * the journey already exists — the authoritative idempotency guard, so a
	 * double-click or a stale client cannot duplicate a row (spec.md §3.5).
	 *
	 * @since 1.8.4
	 * @return void
	 */
	public function ajax_create_funnel_from_recipe() {
		$this->check_funnel_ajax_access();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in check_funnel_ajax_access().
		$recipe_id = isset( $_POST['recipe_id'] ) ? sanitize_key( wp_unslash( $_POST['recipe_id'] ) ) : '';

		if ( '' === $recipe_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid recipe.', 'opti-behavior' ) ) );
		}

		$result = $this->get_auto_builder()->create_from_recipe( $recipe_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				)
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: create the whole recommended set (spec.md §4.2).
	 *
	 * Also the endpoint the onboarding opt-in checkbox calls.
	 *
	 * @since 1.8.4
	 * @return void
	 */
	public function ajax_create_recommended_funnels() {
		$this->check_funnel_ajax_access();

		wp_send_json_success( $this->get_auto_builder()->create_recommended() );
	}

	/**
	 * AJAX: dismiss one suggestion card (spec.md §4.2).
	 *
	 * The only mechanism that removes a card from the panel, always
	 * user-initiated and reversible through "Re-scan site".
	 *
	 * @since 1.8.4
	 * @return void
	 */
	public function ajax_dismiss_funnel_suggestion() {
		$this->check_funnel_ajax_access();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in check_funnel_ajax_access().
		$recipe_id = isset( $_POST['recipe_id'] ) ? sanitize_key( wp_unslash( $_POST['recipe_id'] ) ) : '';

		if ( '' === $recipe_id || ! $this->get_auto_builder()->dismiss( $recipe_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid recipe.', 'opti-behavior' ) ) );
		}

		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * The current user's suggestions-panel display preference.
	 *
	 * Returned as STRINGS because this is handed to the browser through
	 * `wp_localize_script()`, which casts every scalar to a string anyway — so
	 * the JS compares against '1'/'0' and treats '' as "never chosen" instead of
	 * tripping over a truthy "0".
	 *
	 * @since 1.8.4.1
	 * @return array { collapsed: ''|'0'|'1', showCreated: ''|'0'|'1' }
	 */
	private function get_funnel_suggestions_prefs() {
		$stored = get_user_meta( get_current_user_id(), self::SUGGESTIONS_UI_META, true );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'collapsed'   => isset( $stored['collapsed'] ) ? ( $stored['collapsed'] ? '1' : '0' ) : '',
			'showCreated' => isset( $stored['show_created'] ) ? ( $stored['show_created'] ? '1' : '0' ) : '',
		);
	}

	/**
	 * AJAX: persist the suggestions-panel display preference (1.8.4.1 UX pass).
	 *
	 * Display-only. It stores no funnel state, changes no suggestion payload and
	 * never removes a card — losing this preference costs the user one click, so
	 * the client fires it and ignores the answer.
	 *
	 * Same guard as every other funnel endpoint (funnels nonce + manage_options)
	 * and a strict two-key allow-list: only `collapsed` and `show_created` are
	 * read, each coerced to 0/1, and the stored array is intersected back down to
	 * those two keys so a legacy or hand-edited meta row cannot grow.
	 *
	 * @since 1.8.4.1
	 * @return void
	 */
	public function ajax_save_funnel_suggestions_prefs() {
		$this->check_funnel_ajax_access();

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'No user to store this preference for.', 'opti-behavior' ) ) );
		}

		$stored = get_user_meta( $user_id, self::SUGGESTIONS_UI_META, true );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$allowed = array(
			'collapsed'    => 1,
			'show_created' => 1,
		);
		$dirty   = false;

		foreach ( array_keys( $allowed ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in check_funnel_ajax_access().
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in check_funnel_ajax_access().
			$value          = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			$stored[ $key ] = ( '1' === (string) $value ) ? 1 : 0;
			$dirty          = true;
		}

		if ( ! $dirty ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to save.', 'opti-behavior' ) ) );
		}

		$stored = array_intersect_key( $stored, $allowed );

		update_user_meta( $user_id, self::SUGGESTIONS_UI_META, $stored );

		wp_send_json_success( $stored );
	}

	/**
	 * Purge all known full-page caches after a funnel-config mutation.
	 *
	 * The frontend inline config (optiBehaviorFunnelTracker: funnel list,
	 * steps, match patterns) is embedded in cached page HTML, so every
	 * create / update / delete / status change must invalidate page caches —
	 * mirroring Opti_Behavior_AB_Test_Manager::purge_page_cache_for_test().
	 * Funnel steps can match any URL on the site, so the domain-wide helper
	 * is used. Data-only mutations (reset of tracking rows) do NOT need this.
	 *
	 * The purge can be disabled via the `opti_behavior_funnel_purge_mode`
	 * option ('all' = purge, 'none' = skip) and/or the
	 * `opti_behavior_should_purge_cache` filter. The gate is fail-open: the
	 * purge runs unless the stored option value is exactly the string 'none'
	 * (missing, false, corrupt or unknown values all behave as 'all').
	 * Trade-off when disabled: cached HTML keeps serving the OLD inline
	 * optiBehaviorFunnelTracker config until natural cache expiry (WP Rocket
	 * default lifespan is 10 h), so new/changed funnels collect no data from
	 * those pages in the meantime.
	 *
	 * @since 1.7.1
	 * @since 1.8.3 Added the purge-mode option gate, the
	 *              `opti_behavior_should_purge_cache` filter and `$context`.
	 *
	 * @param array $context {
	 *     Mutation context passed to the filter.
	 *
	 *     @type string $action    One of 'save', 'delete', 'status'.
	 *     @type int    $funnel_id The funnel being mutated.
	 * }
	 * @return void
	 */
	private function purge_page_caches( array $context = array() ) {
		// Fail-open: purge unless the option is exactly 'none'.
		$should_purge = ( 'none' !== get_option( 'opti_behavior_funnel_purge_mode', 'all' ) );

		/**
		 * Filter whether a funnel mutation should purge full-page caches.
		 *
		 * @since 1.8.3
		 *
		 * @param bool  $should_purge Setting-derived decision (true unless the
		 *                            `opti_behavior_funnel_purge_mode` option is 'none').
		 * @param array $context      { 'action' => 'save'|'delete'|'status', 'funnel_id' => int }
		 */
		$should_purge = apply_filters( 'opti_behavior_should_purge_cache', $should_purge, $context );

		if ( ! $should_purge ) {
			return;
		}

		if ( function_exists( 'opti_behavior_purge_all_page_caches' ) ) {
			opti_behavior_purge_all_page_caches();
		}
	}

	/**
	 * AJAX handler to delete funnel
	 */
	public function ajax_delete_funnel() {
		// Verify nonce.
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'opti-behavior' ) ) );
		}

		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;

		if ( $funnel_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid funnel ID', 'opti-behavior' ) ) );
		}

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Soft delete by setting status to 'deleted'.
		$wpdb->update(
			$table_funnels,
			array( 'status' => 'deleted' ),
			array( 'id' => $funnel_id ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Deleted funnel must disappear from the cached inline tracker config.
		$this->purge_page_caches(
			array(
				'action'    => 'delete',
				'funnel_id' => $funnel_id,
			)
		);

		wp_send_json_success( array( 'message' => __( 'Funnel deleted successfully', 'opti-behavior' ) ) );
	}

	/**
	 * AJAX handler to toggle a funnel's status between 'active' and 'suspended'.
	 *
	 * A suspended funnel stays in the list (shown with a "Suspended" badge) and
	 * keeps all of its existing tracking data, but stops recording NEW visitor
	 * entries because every tracking path filters on `status = 'active'`. Flipping
	 * back to 'active' resumes recording. No data is deleted.
	 */
	public function ajax_set_funnel_status() {
		// Verify nonce.
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'opti-behavior' ) ) );
		}

		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;
		$status    = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		if ( $funnel_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid funnel ID', 'opti-behavior' ) ) );
		}

		// Only the two user-facing states are accepted here. 'deleted' is managed
		// exclusively by ajax_delete_funnel() and must never be set via this path.
		if ( ! in_array( $status, array( 'active', 'suspended' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid status', 'opti-behavior' ) ) );
		}

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$updated = $wpdb->update(
			$table_funnels,
			array( 'status' => $status ),
			array( 'id' => $funnel_id ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => __( 'Error updating funnel status', 'opti-behavior' ) ) );
		}

		// Active/suspended state changes which funnels the cached inline
		// tracker config contains (only 'active' funnels are embedded).
		$this->purge_page_caches(
			array(
				'action'    => 'status',
				'funnel_id' => $funnel_id,
			)
		);

		wp_send_json_success(
			array(
				'funnel_id' => $funnel_id,
				'status'    => $status,
				'message'   => __( 'Funnel status updated successfully', 'opti-behavior' ),
			)
		);
	}

	/**
	 * AJAX handler to reset funnel data (keeps configuration, deletes tracking data)
	 */
	public function ajax_reset_funnel_data() {
		// Verify nonce.
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'opti-behavior' ) ) );
		}

		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;

		if ( $funnel_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid funnel ID', 'opti-behavior' ) ) );
		}

		global $wpdb;
		$table_tracking = $wpdb->prefix . 'opti_behavior_funnel_tracking';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Delete all tracking data for this funnel (but keep the funnel configuration)
		$deleted = $wpdb->delete(
			$table_tracking,
			array( 'funnel_id' => $funnel_id ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $deleted === false ) {
			wp_send_json_error( array( 'message' => __( 'Error resetting funnel data', 'opti-behavior' ) ) );
		}

		wp_send_json_success( array(
			'message' => __( 'Funnel data reset successfully', 'opti-behavior' ),
			'deleted_count' => $deleted
		) );
	}

	/**
	 * AJAX handler to track funnel progression
	 */
	public function ajax_track_funnel() {
		// Verify nonce.
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		// Ingest gate: silently ignore funnel events from bots / excluded IPs
		// (200 success so the funnel tracker JS does not retry).
		if ( Opti_Behavior_Ingest_Gate::should_reject( 'funnel_track' ) ) {
			wp_send_json_success( array( 'status' => 'ignored' ) );
		}

		// Get tracking data.
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;
		$current_url = isset( $_POST['current_url'] ) ? esc_url_raw( wp_unslash( $_POST['current_url'] ) ) : '';

		// Cache-safe funnel identity (D1 / leak #3). Under full-page caching the
		// client session_id (broker seed) is frozen into the cached HTML, so two
		// DISTINCT direct visitors would report the SAME funnel session_id and be
		// counted as one funnel session. For effectively-anon guests with no real
		// (full-consent) session cookie, recompute the per-visitor 30-min session
		// id server-side at ingest — identical to the value the heatmap/session
		// path stores — so funnel rows key to the correct per-visitor session.
		// admin-ajax is never full-page cached, so the server sees the real IP/UA.
		$session_id = $this->resolve_anon_funnel_session_id( $session_id );

		if ( empty( $session_id ) || $funnel_id <= 0 || empty( $current_url ) ) {
			wp_send_json_error( array( 'message' => 'Invalid tracking data' ) );
		}

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';
		$table_tracking = $wpdb->prefix . 'opti_behavior_funnel_tracking';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Get funnel definition.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$funnel = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT steps FROM {$table_funnels} WHERE id = %d AND status = 'active'",
				$funnel_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $funnel ) {
			wp_send_json_error( array( 'message' => __( 'Funnel not found', 'opti-behavior' ) ) );
		}

		$steps = $this->read_funnel_steps( $funnel->steps );
		if ( empty( $steps ) ) {
			wp_send_json_error( array( 'message' => __( 'Funnel has no steps', 'opti-behavior' ) ) );
		}

		// Deduplication guard: if the server-side PHP tracker (track_all_requests)
		// already claimed this session+funnel+url in the same request cycle, skip
		// the AJAX advancement to prevent double-counting. Without this guard, a
		// single page visit advances two steps when consecutive steps both match
		// the same URL (e.g. back-to-back 'any'/'pageview' steps).
		$php_tracker_url = $this->get_server_tracker_claim( $session_id, $funnel_id );
		if ( false !== $php_tracker_url && $php_tracker_url === $current_url ) {
			wp_send_json_success( array( 'message' => 'Server-side tracker handled this request' ) );
			return;
		}

		// Fast-exit: if no step pattern matches this URL at all, skip the DB work.
		$any_match = false;
		foreach ( $steps as $step ) {
			if ( $this->url_matches_pattern( $current_url, $step['url_pattern'], $step['match_type'] ) ) {
				$any_match = true;
				break;
			}
		}

		if ( ! $any_match ) {
			wp_send_json_success( array( 'message' => 'URL does not match any funnel step' ) );
			return;
		}

		// Delegate to save_funnel_tracking â€” it is context-aware and will decide
		// whether to enter the funnel at step 1 or advance the session by +1.
		// We never pre-commit to a "matched step" here, because overlapping
		// patterns (e.g. a step 1 with match_type='any') would hijack every
		// advancement by matching the URL first.
		$outcome = $this->save_funnel_tracking( $session_id, $funnel_id, 0, $current_url, $steps );

		if ( is_array( $outcome ) && ! empty( $outcome['advanced'] ) ) {
			wp_send_json_success( array(
				'step'      => (int) $outcome['step'],
				'completed' => (int) $outcome['completed'],
			) );
		}

		wp_send_json_success( array( 'message' => 'URL is not the next sequential step for this session' ) );
	}

	/**
	 * Resolve the cache-safe funnel session id for an effectively-anonymous guest.
	 *
	 * Cache-safe identity (decision D1 / leak #3): a full-page cache freezes the
	 * first visitor's session seed into the HTML, so the client-supplied
	 * session_id cannot be trusted to distinguish visitors. When the request is
	 * from an effectively-anonymous guest (anonymous privacy mode, or full mode
	 * without consent) and carries no real full-consent session cookie, this
	 * recomputes the deterministic per-visitor 30-min session id server-side via
	 * Opti_Behavior_Heatmap_Session::get_anonymous_identity() — the SAME value the
	 * heatmap/session-start path stores — so funnel rows key to the correct
	 * session. Logged-in users and full-consent visitors (with a real
	 * optibehavior_sid cookie) keep their client session id unchanged.
	 *
	 * Degrades gracefully: returns the original client session id whenever the
	 * gate does not apply or the server identity is unavailable (older Free
	 * without get_anonymous_identity(), unresolvable hash) — never empties it.
	 *
	 * @since 1.0.8
	 * @param string $client_session_id Pre-sanitized session id from the POST body.
	 * @return string Cache-safe session id for downstream funnel storage.
	 */
	private function resolve_anon_funnel_session_id( $client_session_id ) {
		// Logged-in users are identified by a stable server id and are never
		// served frozen cached identity — leave their session id untouched.
		if ( is_user_logged_in() ) {
			return $client_session_id;
		}

		// A real full-consent session cookie means the client id is cache-safe
		// (set per-browser by JS, not baked into cached HTML) — do not override.
		if ( ! empty( $_COOKIE['optibehavior_sid'] ) || ! empty( $_COOKIE['opti_behavior_session_id'] ) ) {
			return $client_session_id;
		}

		if ( ! $this->heatmap || ! method_exists( $this->heatmap, 'get_options' ) ) {
			return $client_session_id;
		}

		$options      = $this->heatmap->get_options();
		$privacy_mode = ( is_array( $options ) && isset( $options['privacy_mode'] ) && 'full' === $options['privacy_mode'] ) ? 'full' : 'anonymous';
		$has_consent  = isset( $_COOKIE['optibehavior_consent'] )
			&& 'granted' === sanitize_text_field( wp_unslash( $_COOKIE['optibehavior_consent'] ) );

		$is_effectively_anon = ( 'anonymous' === $privacy_mode ) || ( 'full' === $privacy_mode && ! $has_consent );
		if ( ! $is_effectively_anon ) {
			return $client_session_id;
		}

		$session_obj = method_exists( $this->heatmap, 'get_session' ) ? $this->heatmap->get_session() : null;
		if ( $session_obj && method_exists( $session_obj, 'get_anonymous_identity' ) ) {
			$identity = $session_obj->get_anonymous_identity();
			if ( ! empty( $identity['session_id'] ) ) {
				return $identity['session_id'];
			}
		}

		return $client_session_id;
	}

	/**
	 * Universal request tracker - OPTIMIZED for millions of users
	 * Uses caching and deferred writes for maximum performance
	 */
	public function track_all_requests() {
		// FAST EXIT 1: Don't track admin pages (no DB query)
		if ( is_admin() ) {
			return;
		}

		// FAST EXIT 2: Don't track bots (shared ingest-gate list — superset of
		// the old local is_bot_request() patterns)
		if ( Opti_Behavior_Ingest_Gate::is_bot_user_agent() ) {
			return;
		}

		// FAST EXIT 2b: Don't track excluded IPs (server-side per-request check,
		// cache-safe — this runs on every request, not baked into cached HTML)
		if ( class_exists( 'Opti_Behavior_IP_Exclusion' ) && Opti_Behavior_IP_Exclusion::is_excluded() ) {
			return;
		}

		// FAST EXIT 3: No session = no tracking
		$session_id = isset( $_COOKIE['optibehavior_sid'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['optibehavior_sid'] ) ) : '';
		if ( empty( $session_id ) ) {
			$session_id = isset( $_COOKIE['opti_behavior_session_id'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['opti_behavior_session_id'] ) ) : '';
		}

		if ( empty( $session_id ) ) {
			return;
		}

		// PERFORMANCE: Cache active funnels (5 min cache = only 1 DB query per 300 requests!)
		static $active_funnels = null;
		static $cache_time = 0;

		if ( $active_funnels === null || ( time() - $cache_time ) > 300 ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			$active_funnels = $wpdb->get_results(
				"SELECT id, steps FROM {$wpdb->prefix}opti_behavior_funnels WHERE status = 'active' LIMIT 50",
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$cache_time = time();
		}

		// FAST EXIT 4: No funnels
		if ( empty( $active_funnels ) ) {
			return;
		}

		// Build URL once
		$http_host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $http_host . $request_uri;

		// Check funnels (in-memory only, very fast)
		foreach ( $active_funnels as $funnel ) {
			$steps = $this->read_funnel_steps( $funnel['steps'] );
			if ( empty( $steps ) ) {
				continue;
			}

			// Fast-exit pre-filter: queue a DB write only if AT LEAST ONE step
			// pattern matches this URL. The real step decision (which step to
			// advance to, if any) is made in save_funnel_tracking() based on the
			// session's current progress â€” we must not commit to a "matched step"
			// here, because overlapping patterns (e.g. step 1 = 'any') would
			// otherwise hijack every advancement.
			// The COUNT matters as well as the fact of a match: the JS tracker can
			// only double-advance when TWO steps match the same URL (it advances
			// only when the NEXT step's pattern matches too), so the cross-request
			// dedup flag below is written only for that case (QA-B-FUNNEL-044).
			$match_count = 0;
			foreach ( $steps as $step ) {
				if ( $this->url_matches_pattern( $current_url, $step['url_pattern'], $step['match_type'] ) ) {
					++$match_count;
					if ( $match_count > 1 ) {
						break;
					}
				}
			}

			if ( $match_count > 0 ) {
				// Mark this session+funnel+url as being handled by the server-side
				// tracker. The JS AJAX tracker checks this flag and skips its AJAX
				// call so that a single page visit never advances more than one step
				// (double-advancement can happen when consecutive steps both match
				// the same URL, e.g. two 'any'/'pageview' steps back-to-back).
				// 10-second TTL â€” long enough for the JS AJAX call to arrive.
				$this->set_server_tracker_claim( $session_id, (int) $funnel['id'], $current_url, $match_count > 1 );

				// PERFORMANCE: Defer DB write to shutdown (non-blocking).
				$this->queue_tracking( $session_id, (int) $funnel['id'], 0, $current_url, $steps );
			}
		}
	}

	/**
	 * Cache group for the server-side tracker's per-request claim flags.
	 *
	 * @since 1.9.0.7
	 * @var string
	 */
	const SERVER_CLAIM_GROUP = 'opti_behavior_funnels';

	/**
	 * Request-scoped mirror of the claims written during this request.
	 *
	 * @since 1.9.0.7
	 * @var array
	 */
	private static $server_claims = array();

	/**
	 * Cache key for a session+funnel claim.
	 *
	 * @since 1.9.0.7
	 *
	 * @param string $session_id Session identifier.
	 * @param int    $funnel_id  Funnel row ID.
	 * @return string
	 */
	private function server_tracker_claim_key( $session_id, $funnel_id ) {
		return 'ob_srv_' . md5( $session_id . '_' . $funnel_id );
	}

	/**
	 * Record "the server-side tracker already handled this session+funnel+url".
	 *
	 * This used to be an unconditional set_transient(), which without a persistent
	 * object cache is one wp_options INSERT plus a _transient_timeout_ sibling PER
	 * matching pageview PER funnel: write amplification on exactly the
	 * high-traffic pages funnels are pointed at, plus a steady stream of expired
	 * _transient_ob_srv_* rows (QA-B-FUNNEL-044).
	 *
	 * The flag now lives in the object cache (free, and genuinely cross-request on
	 * any install with a persistent backend). The wp_options-backed transient is
	 * still written, but ONLY when this URL matches more than one step AND there
	 * is no persistent object cache, i.e. only for the overlapping-pattern funnels
	 * the guard actually exists for. A normal funnel, whose steps match distinct
	 * URLs, cannot double-advance and so costs no option write at all.
	 *
	 * @since 1.9.0.7
	 *
	 * @param string $session_id  Session identifier.
	 * @param int    $funnel_id   Funnel row ID.
	 * @param string $current_url URL that was claimed.
	 * @param bool   $overlapping Whether more than one step matches this URL.
	 * @return void
	 */
	private function set_server_tracker_claim( $session_id, $funnel_id, $current_url, $overlapping ) {
		$key = $this->server_tracker_claim_key( $session_id, $funnel_id );

		self::$server_claims[ $key ] = $current_url;
		wp_cache_set( $key, $current_url, self::SERVER_CLAIM_GROUP, 10 );

		if ( $overlapping && ! wp_using_ext_object_cache() ) {
			set_transient( $key, $current_url, 10 );
		}
	}

	/**
	 * Read back a server-side tracker claim.
	 *
	 * Checks the request-scoped mirror, then the object cache, then the legacy
	 * transient (claims written by an older release, and by the
	 * overlapping-pattern path above).
	 *
	 * @since 1.9.0.7
	 *
	 * @param string $session_id Session identifier.
	 * @param int    $funnel_id  Funnel row ID.
	 * @return string|false Claimed URL, or false when there is no claim.
	 */
	private function get_server_tracker_claim( $session_id, $funnel_id ) {
		$key = $this->server_tracker_claim_key( $session_id, $funnel_id );

		if ( isset( self::$server_claims[ $key ] ) ) {
			return self::$server_claims[ $key ];
		}

		$cached = wp_cache_get( $key, self::SERVER_CLAIM_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		return get_transient( $key );
	}

	/**
	 * Queue tracking for deferred write (non-blocking performance optimization)
	 */
	private function queue_tracking( $session_id, $funnel_id, $matched_step, $current_url, $steps ) {
		static $queue = array();
		static $registered = false;

		$queue[] = compact( 'session_id', 'funnel_id', 'matched_step', 'current_url', 'steps' );

		if ( ! $registered ) {
			$self = $this;
			add_action( 'shutdown', function() use ( &$queue, $self ) {
				foreach ( $queue as $item ) {
					$self->save_funnel_tracking(
						$item['session_id'],
						$item['funnel_id'],
						$item['matched_step'],
						$item['current_url'],
						$item['steps']
					);
				}
			}, 999 );
			$registered = true;
		}
	}

	/**
	 * Save funnel tracking data.
	 *
	 * Context-aware: the real step decision is made here based on the session's
	 * current progress, NOT on the caller's `$matched_step`. That parameter is
	 * retained only for backward compatibility with `queue_tracking()` and is
	 * ignored â€” using it led to the "any-match hijack" bug where a step 1 with
	 * `match_type = 'any'` matched every URL, causing callers to pass
	 * `$matched_step = 1` for a visit to step 2's URL and the method to reject
	 * the advancement as "not the next sequential step".
	 *
	 * The authoritative rule applied here:
	 *   - New session:  URL must match step 1's pattern â†’ insert with step=1.
	 *   - Existing session: URL must match the next-expected step's pattern
	 *     (steps[max_step_reached]) â†’ advance by +1. Otherwise do nothing.
	 *
	 * @param string $session_id   Session identifier.
	 * @param int    $funnel_id    Funnel row ID.
	 * @param int    $matched_step IGNORED â€” kept for backward compatibility.
	 * @param string $current_url  URL the visitor is on right now.
	 * @param array  $steps        Decoded funnel step definitions.
	 * @return array|null Outcome: ['advanced' => bool, 'step' => int, 'completed' => int]
	 *                   when a decision was made, or null on invalid input.
	 */
	private function save_funnel_tracking( $session_id, $funnel_id, $matched_step, $current_url, $steps ) {
		unset( $matched_step ); // Intentionally ignored â€” see docblock.

		global $wpdb;
		$table_tracking = $wpdb->prefix . 'opti_behavior_funnel_tracking';

		if ( empty( $steps ) || ! is_array( $steps ) ) {
			return null;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		// Check if tracking already exists for this session and funnel.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$tracking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_tracking} WHERE session_id = %s AND funnel_id = %d",
				$session_id,
				$funnel_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $tracking ) {
			// The session is already in the funnel. Advance only if the CURRENT
			// URL matches the NEXT expected step's pattern explicitly. Overlapping
			// patterns (e.g. step 1 = 'any') would otherwise falsely block every
			// advancement, because the caller's "first-match" hint points at
			// step 1 even on a step-2 URL.
			$next_index = (int) $tracking->max_step_reached; // 0-based index of the next step.

			if ( $next_index >= count( $steps ) ) {
				return array( 'advanced' => false, 'step' => (int) $tracking->max_step_reached, 'completed' => (int) $tracking->completed );
			}

			$next_step = $steps[ $next_index ];
			if ( ! $this->url_matches_pattern( $current_url, $next_step['url_pattern'], $next_step['match_type'] ) ) {
				return array( 'advanced' => false, 'step' => (int) $tracking->max_step_reached, 'completed' => (int) $tracking->completed );
			}

			$advanced_step = $next_index + 1;
			$is_completed  = ( $advanced_step === count( $steps ) ) ? 1 : 0;

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			$wpdb->update(
				$table_tracking,
				array(
					'current_step'      => $advanced_step,
					'max_step_reached'  => $advanced_step,
					'completed'         => $is_completed,
					'completion_time'   => $is_completed ? current_time( 'mysql' ) : null,
				),
				array( 'id' => $tracking->id ),
				array( '%d', '%d', '%d', '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

			return array( 'advanced' => true, 'step' => $advanced_step, 'completed' => $is_completed );
		}

		// New session: must explicitly match step 1 to enter the funnel. No
		// mid-funnel entries allowed, so someone landing directly on the
		// checkout page is never counted as having completed the funnel.
		$first_step = $steps[0];
		if ( ! $this->url_matches_pattern( $current_url, $first_step['url_pattern'], $first_step['match_type'] ) ) {
			return array( 'advanced' => false, 'step' => 0, 'completed' => 0 );
		}

		$is_completed = ( 1 === count( $steps ) ) ? 1 : 0;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
		$wpdb->insert(
			$table_tracking,
			array(
				'session_id'        => $session_id,
				'funnel_id'         => $funnel_id,
				'current_step'      => 1,
				'max_step_reached'  => 1,
				'completed'         => $is_completed,
				'entry_time'        => current_time( 'mysql' ),
				'completion_time'   => $is_completed ? current_time( 'mysql' ) : null,
			),
			array( '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array( 'advanced' => true, 'step' => 1, 'completed' => $is_completed );
	}

	/**
	 * Check if current request is from a bot
	 */
	private function is_bot_request() {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$bot_patterns = array(
			'bot', 'crawl', 'spider', 'slurp', 'search', 'index',
			'facebook', 'twitter', 'linkedin', 'pinterest',
			'chatgpt-user', 'claude-user', 'claude-web', 'anthropic-ai',
			'perplexity-user', 'google-extended', 'googleother',
			'gemini', 'bard', 'ccbot', 'bytespider', 'amazonbot',
			'applebot', 'meta-externalagent', 'cohere-ai', 'youbot',
			'omgili', 'ai2bot', 'allenai', 'duckassistbot',
			'grok', 'xai', 'mistral-ai', 'deepseek',
		);

		foreach ( $bot_patterns as $pattern ) {
			if ( stripos( $user_agent, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if URL matches pattern.
	 *
	 * Defensive normalization: if `match_type` is `any` or `pageview` BUT a
	 * specific `url_pattern` is provided (non-empty and not the `*` wildcard),
	 * interpret it as a `contains` match. Rationale: `any` / `pageview` return
	 * true unconditionally, so any `url_pattern` set by the user is silently
	 * ignored. In practice users configure a real URL pattern (e.g. for a
	 * /checkout step) and forget to change the default match_type â€” this used
	 * to cause every page visit to falsely "advance" past those steps because
	 * the URL pattern was ignored. Respecting the pattern matches user intent
	 * while remaining backwards-compatible: true entry triggers with an empty
	 * pattern or `*` still behave as "match any URL".
	 *
	 * @param string $url        URL to check.
	 * @param string $pattern    Pattern to match.
	 * @param string $match_type Match type (any, exact, contains, starts_with, ends_with, regex, pageview).
	 * @return bool True if matches.
	 */
	private function url_matches_pattern( $url, $pattern, $match_type ) {
		// Defensive normalization â€” see docblock.
		if ( ( 'any' === $match_type || 'pageview' === $match_type )
			&& is_string( $pattern )
			&& '' !== $pattern
			&& '*' !== $pattern
		) {
			$match_type = 'contains';
		}

		switch ( $match_type ) {
			case 'any':
				return true;
			case 'exact':
				// Normalized comparison. Raw string equality could never match
				// the path-only patterns the admin UI stores (e.g. '/checkout/')
				// against the full href reported by funnel-tracker.js — funnels
				// with 'exact' steps silently recorded ZERO conversions
				// (Bug #1, E2E test 2026-07-08). See normalize_url_for_exact()
				// for the exact semantics.
				$pattern_str  = is_string( $pattern ) ? $pattern : '';
				$keep_query   = ( false !== strpos( $pattern_str, '?' ) );
				$url_norm     = $this->normalize_url_for_exact( $url, $keep_query );
				$pattern_norm = $this->normalize_url_for_exact( $pattern_str, $keep_query );
				if ( '' !== $pattern_norm['host'] && $url_norm['host'] !== $pattern_norm['host'] ) {
					return false;
				}
				return $url_norm['path'] === $pattern_norm['path'];
			case 'contains':
				// Guard: empty pattern matches everything (avoids ValueError on PHP 8.0+ where
				// stripos() rejects an empty needle). Keeps parity with the JS implementation.
				if ( ! is_string( $pattern ) || '' === $pattern ) {
					return true;
				}
				return stripos( $url, $pattern ) !== false;
			case 'starts_with':
				// Guard: same PHP 8.0 empty-needle protection as 'contains'.
				if ( ! is_string( $pattern ) || '' === $pattern ) {
					return true;
				}
				return stripos( $url, $pattern ) === 0;
			case 'ends_with':
				// Guard: empty pattern matches everything. PHP's substr($url, -0) returns the
				// full string and strcasecmp(full_url,'') is non-zero (returns false), while JS
				// url.substring(len-0)='' and ''==='' is true. The guard aligns both sides.
				if ( ! is_string( $pattern ) || '' === $pattern ) {
					return true;
				}
				$pattern_len = strlen( $pattern );
				return strcasecmp( substr( $url, -$pattern_len ), $pattern ) === 0;
			case 'regex':
				// Use a different delimiter to avoid conflicts with / in URLs
				$safe_pattern = str_replace( '#', '\#', $pattern );
				return @preg_match( '#' . $safe_pattern . '#', $url ) === 1;
			case 'pageview':
				return true; // Any page view counts.
			default:
				return false;
		}
	}

	/**
	 * Normalize a URL or pattern for 'exact' matching.
	 *
	 * Splits the value into host + path (+ optional query) components so that
	 * url_matches_pattern() compares user intent instead of raw strings:
	 *  - fragments (#...) never participate;
	 *  - the query string participates only when the PATTERN itself contains
	 *    one ($keep_query), so tracking params such as ?utm_source=... on the
	 *    live URL do not break an exact path match;
	 *  - trailing slashes are ignored ('/checkout/' equals '/checkout');
	 *  - comparison is case-insensitive (consistent with 'contains');
	 *  - the scheme is ignored; the host is compared only when the pattern is
	 *    an absolute URL. The admin UI stores path-only patterns like
	 *    '/checkout/', which under raw string comparison could NEVER match the
	 *    full href sent by funnel-tracker.js — the Bug #1 root cause.
	 *
	 * Must stay in sync with normalizeUrlForExact() in
	 * assets/js/funnel-tracker.js and backfill_normalize_url_for_exact() in
	 * the Pro user-journey admin page.
	 *
	 * @param string $value      URL or pattern.
	 * @param bool   $keep_query Whether the query string participates.
	 * @return array { host: string, path: string } Normalized components.
	 */
	private function normalize_url_for_exact( $value, $keep_query = false ) {
		if ( ! is_string( $value ) ) {
			$value = '';
		}
		// Fragment never participates.
		$hash_pos = strpos( $value, '#' );
		if ( false !== $hash_pos ) {
			$value = substr( $value, 0, $hash_pos );
		}
		// Split off the query string.
		$query     = '';
		$query_pos = strpos( $value, '?' );
		if ( false !== $query_pos ) {
			if ( $keep_query ) {
				$query = substr( $value, $query_pos );
			}
			$value = substr( $value, 0, $query_pos );
		}
		// Absolute URL: separate host, keep path only. Scheme is discarded.
		$host = '';
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://([^/]*)(/.*)?$#i', $value, $m ) ) {
			$host  = strtolower( $m[1] );
			$value = ( isset( $m[2] ) && '' !== $m[2] ) ? $m[2] : '/';
		}
		$value = strtolower( $value );
		if ( '' === $value ) {
			$value = '/';
		}
		if ( '/' !== $value[0] ) {
			$value = '/' . $value;
		}
		if ( strlen( $value ) > 1 ) {
			$value = rtrim( $value, '/' );
			if ( '' === $value ) {
				$value = '/';
			}
		}
		return array(
			'host' => $host,
			'path' => $value . strtolower( $query ),
		);
	}

	/**
	 * AJAX handler to get available countries from funnel tracking data
	 * Only returns countries that have data for the specified funnel
	 */
	public function ajax_get_funnel_countries() {
		// Shared gate: the SAME nonce check and the SAME "Permission denied"
		// message every other funnel admin action answers with. A bespoke
		// "Unauthorized" here made this one endpoint deviate from the contract
		// clients switch on (QA-B-FUNNEL-009).
		$this->check_funnel_ajax_access();

		// Get funnel ID, period, and date range from request
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce + capability verified by check_funnel_ajax_access() above (check_ajax_referer).
		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;
		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '7days';
		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$cohort_start_at = isset( $_POST['cohort_start_at'] ) ? sanitize_text_field( wp_unslash( $_POST['cohort_start_at'] ) ) : '';
		$cohort_end_at = isset( $_POST['cohort_end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['cohort_end_at'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( $cohort_start_at && $cohort_end_at ) {
			$start_date = $cohort_start_at;
			$end_date   = $cohort_end_at;
			// Explicit cohort bounds outrank the period preset. calculate_date_range()
			// hands the pair to Opti_Behavior_Stats_Date_Range::resolve(), which only
			// honours explicit dates for the `custom` period — without this the bounds
			// were silently dropped and a 90-day cohort returned the 7-day figures
			// (QA-B-FUNNEL-060).
			$period     = 'custom';
		}

		// Calculate date range
		$date_range = $this->calculate_date_range( $period, $start_date, $end_date );

		global $wpdb;
		$table_sessions = $wpdb->prefix . 'optibehavior_sessions';
		$table_visitors = $wpdb->prefix . 'optibehavior_visitors';
		$table_tracking = $wpdb->prefix . 'opti_behavior_funnel_tracking';
		$exclude_spam   = $this->resolve_spam_exclusion_from_request();
		$spam_condition = $exclude_spam ? $this->get_spam_session_condition( 's' ) : '';
		$spam_where     = '' !== $spam_condition ? ' AND ' . $spam_condition : '';

		// Get unique countries from visitors who have data for the specific funnel
		if ( $funnel_id > 0 ) {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Only get countries that have tracking data for this specific funnel in the date range
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			$countries = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT v.country, v.country_name
					FROM {$table_visitors} v
					INNER JOIN {$table_sessions} s ON v.id = s.visitor_id
					INNER JOIN {$table_tracking} t ON s.id = t.session_id
					WHERE t.funnel_id = %d
					AND t.entry_time >= %s
					AND t.entry_time < %s
					AND v.country IS NOT NULL
					AND v.country != ''
					AND v.country != 'UN'
					{$spam_where}
					ORDER BY v.country_name ASC",
					$funnel_id,
					$date_range['start'],
					$date_range['end']
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables; identifiers from $wpdb->prefix and internal allow-lists (never user input), values bound via $wpdb->prepare(); direct real-time query, per-request caching not applicable; schema managed on plugin activation.
			// Fallback: get all countries with any session data
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
			$countries = $wpdb->get_results(
				"SELECT DISTINCT v.country, v.country_name
				FROM {$table_visitors} v
				INNER JOIN {$table_sessions} s ON v.id = s.visitor_id
				WHERE v.country IS NOT NULL
				AND v.country != ''
				AND v.country != 'UN'
				{$spam_where}
				ORDER BY v.country_name ASC",
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$country_list = array();
		foreach ( $countries as $country ) {
			$country_code = strtoupper( $country['country'] );
			$country_name = ! empty( $country['country_name'] ) ? $country['country_name'] : $country_code;

			$country_list[] = array(
				'code' => $country_code,
				'name' => $country_name,
			);
		}

		wp_send_json_success( array( 'countries' => $country_list ) );
	}
}
