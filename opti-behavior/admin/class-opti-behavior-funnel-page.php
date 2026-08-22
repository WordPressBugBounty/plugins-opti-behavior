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
require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-funnel-database.php';

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

	/**
	 * opti-behavior Heatmap instance
	 *
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $heatmap;

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
		add_action( 'wp_ajax_optibehavior_get_funnels', array( $this, 'ajax_get_funnels' ) );
		add_action( 'wp_ajax_optibehavior_get_funnels_summary', array( $this, 'ajax_get_funnels_summary' ) );
		add_action( 'wp_ajax_optibehavior_get_funnel_kpi', array( $this, 'ajax_get_funnel_kpi' ) );
		add_action( 'wp_ajax_optibehavior_get_funnel', array( $this, 'ajax_get_funnel' ) );
		add_action( 'wp_ajax_optibehavior_save_funnel', array( $this, 'ajax_save_funnel' ) );
		add_action( 'wp_ajax_optibehavior_delete_funnel', array( $this, 'ajax_delete_funnel' ) );
		add_action( 'wp_ajax_optibehavior_set_funnel_status', array( $this, 'ajax_set_funnel_status' ) );
		add_action( 'wp_ajax_optibehavior_reset_funnel_data', array( $this, 'ajax_reset_funnel_data' ) );
		add_action( 'wp_ajax_optibehavior_get_funnel_countries', array( $this, 'ajax_get_funnel_countries' ) );

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

		// Enqueue funnel CSS.
		wp_enqueue_style(
			'opti-behavior-funnels',
			plugins_url( 'assets/css/funnels.css', dirname( __FILE__ ) ),
			array( 'opti-behavior-dashboard', 'opti-behavior-heatmaps' ),
			OPTI_BEHAVIOR_HEATMAP_VERSION
		);

		// Enqueue funnel JavaScript.
		wp_enqueue_script(
			'opti-behavior-funnels',
			plugins_url( 'assets/js/funnels.js', dirname( __FILE__ ) ),
			array( 'jquery', 'chart-js' ),
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview-mode detection.
		if ( isset( $_GET['opti_heatmap_preview'] ) || isset( $_GET['opti_preview_as_guest'] ) || isset( $_GET['opti_preview_as_mobile'] ) ) {
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
			plugins_url( 'assets/js/funnel-tracker.js', dirname( __FILE__ ) ),
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
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
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
		}
		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;
		$filter = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : 'all';
		$country = 'all';
		if ( isset( $_POST['country'] ) ) {
			$country = map_deep( wp_unslash( $_POST['country'] ), 'sanitize_text_field' );
		}

		// Calculate date range.
		$date_range = $this->calculate_date_range( $period, $start_date, $end_date );

		// Get funnel data based on user-defined funnel.
		if ( $funnel_id > 0 ) {
			$funnel_data = $this->get_user_funnel_analytics( $funnel_id, $date_range['start'], $date_range['end'], $filter, $country );
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
				$funnel_data = $this->get_user_funnel_analytics( $default_funnel->id, $date_range['start'], $date_range['end'], $filter, $country );
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
	 * @return array Funnel data.
	 */
	private function get_user_funnel_analytics( $funnel_id, $start_date, $end_date, $filter = 'all', $country = 'all' ) {
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

		$steps_definitions = json_decode( $funnel->steps, true );
		$country_codes     = $this->normalize_country_filter( $country );

		// Build device and country filter clause.
		// Only JOIN to sessions/visitors tables when device or country filtering is actually needed.
		// An unconditional INNER JOIN would silently drop all funnel records whose session_id
		// has no matching row in optibehavior_sessions (e.g. anonymous/pre-consent sessions).
		$exclude_spam  = $this->resolve_spam_exclusion_from_request();
		$needs_join    = ( $filter !== 'all' || ! empty( $country_codes ) || $exclude_spam );
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
					{$filter_where}",
				$funnel_id,
				$start_date,
				$end_date
			)
		);

		if ( ! $total_entries ) {
			$total_entries = 0;
		}

		// Calculate step-by-step progression.
		$funnel_steps = array();
		$initial_entries = $total_entries; // Keep initial count for all comparisons

		foreach ( $steps_definitions as $index => $step_def ) {
			$step_number = $index + 1;

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
						{$filter_where}",
					$funnel_id,
					$start_date,
					$end_date,
					$step_number
				)
			);

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
					{$filter_where}",
				$funnel_id,
				$start_date,
				$end_date
			)
		);

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
			$source = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
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
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$funnels = $wpdb->get_results(
			"SELECT id, name, description, steps, status, created_at
			FROM {$table_funnels}
			WHERE status = 'active'
			ORDER BY created_at DESC",
			ARRAY_A
		);

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
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
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
		}

		$date_range = $this->calculate_date_range( $period, $start_date, $end_date );

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$funnels = $wpdb->get_results(
			"SELECT id, name, description, steps, status
			FROM {$table_funnels}
			WHERE status IN ( 'active', 'suspended' )
			ORDER BY created_at DESC",
			ARRAY_A
		);

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
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;
		if ( $funnel_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid funnel ID' ) );
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
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;

		if ( $funnel_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid funnel ID' ) );
		}

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

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

		if ( ! $funnel ) {
			wp_send_json_error( array( 'message' => 'Funnel not found' ) );
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
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		// Get funnel data.
		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$steps = isset( $_POST['steps'] ) ? sanitize_text_field( wp_unslash( $_POST['steps'] ) ) : '';

		// Validate.
		if ( empty( $name ) || empty( $steps ) ) {
			wp_send_json_error( array( 'message' => 'Name and steps are required' ) );
		}

		// Decode and validate steps.
		$steps_array = json_decode( $steps, true );
		if ( ! is_array( $steps_array ) || empty( $steps_array ) ) {
			wp_send_json_error( array( 'message' => 'Invalid steps format' ) );
		}

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		$data = array(
			'name'        => $name,
			'description' => $description,
			'steps'       => wp_json_encode( $steps_array ),
			'status'      => 'active',
		);

		if ( $funnel_id > 0 ) {
			// Update existing funnel.
			$wpdb->update(
				$table_funnels,
				$data,
				array( 'id' => $funnel_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// Insert new funnel.
			$wpdb->insert(
				$table_funnels,
				$data,
				array( '%s', '%s', '%s', '%s' )
			);
			$funnel_id = $wpdb->insert_id;
		}

		// The funnel list + steps are baked into every frontend page as the
		// inline optiBehaviorFunnelTracker config; stale cached HTML would keep
		// serving the OLD funnel definitions (0 entries for a new funnel until
		// the cache expires — WP Rocket default lifespan is 10 h).
		$this->purge_page_caches();

		wp_send_json_success( array( 'funnel_id' => $funnel_id, 'message' => 'Funnel saved successfully' ) );
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
	 * @since 1.7.1
	 * @return void
	 */
	private function purge_page_caches() {
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
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;

		if ( $funnel_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid funnel ID' ) );
		}

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		// Soft delete by setting status to 'deleted'.
		$wpdb->update(
			$table_funnels,
			array( 'status' => 'deleted' ),
			array( 'id' => $funnel_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Deleted funnel must disappear from the cached inline tracker config.
		$this->purge_page_caches();

		wp_send_json_success( array( 'message' => 'Funnel deleted successfully' ) );
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
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;
		$status    = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

		if ( $funnel_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid funnel ID' ) );
		}

		// Only the two user-facing states are accepted here. 'deleted' is managed
		// exclusively by ajax_delete_funnel() and must never be set via this path.
		if ( ! in_array( $status, array( 'active', 'suspended' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid status' ) );
		}

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';

		$updated = $wpdb->update(
			$table_funnels,
			array( 'status' => $status ),
			array( 'id' => $funnel_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => 'Error updating funnel status' ) );
		}

		// Active/suspended state changes which funnels the cached inline
		// tracker config contains (only 'active' funnels are embedded).
		$this->purge_page_caches();

		wp_send_json_success(
			array(
				'funnel_id' => $funnel_id,
				'status'    => $status,
				'message'   => 'Funnel status updated successfully',
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
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;

		if ( $funnel_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid funnel ID' ) );
		}

		global $wpdb;
		$table_tracking = $wpdb->prefix . 'opti_behavior_funnel_tracking';

		// Delete all tracking data for this funnel (but keep the funnel configuration)
		$deleted = $wpdb->delete(
			$table_tracking,
			array( 'funnel_id' => $funnel_id ),
			array( '%d' )
		);

		if ( $deleted === false ) {
			wp_send_json_error( array( 'message' => 'Error resetting funnel data' ) );
		}

		wp_send_json_success( array(
			'message' => 'Funnel data reset successfully',
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

		if ( empty( $session_id ) || $funnel_id <= 0 || empty( $current_url ) ) {
			wp_send_json_error( array( 'message' => 'Invalid tracking data' ) );
		}

		global $wpdb;
		$table_funnels = $wpdb->prefix . 'opti_behavior_funnels';
		$table_tracking = $wpdb->prefix . 'opti_behavior_funnel_tracking';

		// Get funnel definition.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$funnel = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT steps FROM {$table_funnels} WHERE id = %d AND status = 'active'",
				$funnel_id
			)
		);

		if ( ! $funnel ) {
			wp_send_json_error( array( 'message' => 'Funnel not found' ) );
		}

		$steps = json_decode( $funnel->steps, true );
		if ( empty( $steps ) || ! is_array( $steps ) ) {
			wp_send_json_error( array( 'message' => 'Funnel has no steps' ) );
		}

		// Deduplication guard: if the server-side PHP tracker (track_all_requests)
		// already claimed this session+funnel+url in the same request cycle, skip
		// the AJAX advancement to prevent double-counting. Without this guard, a
		// single page visit advances two steps when consecutive steps both match
		// the same URL (e.g. back-to-back 'any'/'pageview' steps).
		$php_tracker_url = get_transient( 'ob_srv_' . md5( $session_id . '_' . $funnel_id ) );
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
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name safely derived from $wpdb->prefix.
			$active_funnels = $wpdb->get_results(
				"SELECT id, steps FROM {$wpdb->prefix}opti_behavior_funnels WHERE status = 'active' LIMIT 50",
				ARRAY_A
			);
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
			$steps = json_decode( $funnel['steps'], true );
			if ( empty( $steps ) ) {
				continue;
			}

			// Fast-exit pre-filter: queue a DB write only if AT LEAST ONE step
			// pattern matches this URL. The real step decision (which step to
			// advance to, if any) is made in save_funnel_tracking() based on the
			// session's current progress â€” we must not commit to a "matched step"
			// here, because overlapping patterns (e.g. step 1 = 'any') would
			// otherwise hijack every advancement.
			$any_match = false;
			foreach ( $steps as $step ) {
				if ( $this->url_matches_pattern( $current_url, $step['url_pattern'], $step['match_type'] ) ) {
					$any_match = true;
					break;
				}
			}

			if ( $any_match ) {
				// Mark this session+funnel+url as being handled by the server-side
				// tracker. The JS AJAX tracker checks this flag and skips its AJAX
				// call so that a single page visit never advances more than one step
				// (double-advancement can happen when consecutive steps both match
				// the same URL, e.g. two 'any'/'pageview' steps back-to-back).
				// 10-second TTL â€” long enough for the JS AJAX call to arrive.
				set_transient( 'ob_srv_' . md5( $session_id . '_' . $funnel['id'] ), $current_url, 10 );

				// PERFORMANCE: Defer DB write to shutdown (non-blocking).
				$this->queue_tracking( $session_id, (int) $funnel['id'], 0, $current_url, $steps );
			}
		}
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
		check_ajax_referer( 'opti_behavior_funnels', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		// Get funnel ID, period, and date range from request
		$funnel_id = isset( $_POST['funnel_id'] ) ? intval( $_POST['funnel_id'] ) : 0;
		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '7days';
		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$cohort_start_at = isset( $_POST['cohort_start_at'] ) ? sanitize_text_field( wp_unslash( $_POST['cohort_start_at'] ) ) : '';
		$cohort_end_at = isset( $_POST['cohort_end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['cohort_end_at'] ) ) : '';
		if ( $cohort_start_at && $cohort_end_at ) {
			$start_date = $cohort_start_at;
			$end_date   = $cohort_end_at;
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
		} else {
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
