<?php
/**
 * Autoloader Class
 *
 * Handles automatic loading of plugin classes.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autoloader Class
 *
 * Provides PSR-4 style autoloading for plugin classes.
 *
 * @since 1.0.0
 */
class Opti_Behavior_Heatmap_Autoloader {

	/**
	 * Plugin directory path.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private static $plugin_dir;

	/**
	 * Class map for faster loading.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private static $class_map = array(
		'Opti_Behavior_Heatmap_Core'            => 'includes/class-opti-behavior-heatmap-core.php',
		'Opti_Behavior_Heatmap_Admin_Email_Resolver' => 'includes/class-opti-behavior-admin-email-resolver.php',
		'Opti_Behavior_Heatmap_Options'         => 'includes/class-opti-behavior-heatmap-options.php',
		'Opti_Behavior_Heatmap_Database'        => 'includes/class-opti-behavior-heatmap-database.php',
		'Opti_Behavior_Heatmap_DB_Lock'         => 'includes/class-opti-behavior-heatmap-db-lock.php',
		'Opti_Behavior_Heatmap_Session'         => 'includes/class-opti-behavior-heatmap-session.php',
		'Opti_Behavior_Heatmap_Analytics'       => 'includes/class-opti-behavior-heatmap-analytics.php',
		'Opti_Behavior_Heatmap_Data_Protection' => 'includes/class-opti-behavior-heatmap-data-protection.php',
		'Opti_Behavior_Heatmap_Debug_Manager'   => 'includes/class-opti-behavior-heatmap-debug-manager.php',
		'Opti_Behavior_Heatmap_Deactivation_Survey' => 'includes/class-opti-behavior-deactivation-survey.php',
		'Opti_Behavior_Heatmap_Dashboard'       => 'admin/class-opti-behavior-heatmap-dashboard.php',
		'Opti_Behavior_Heatmap_List'            => 'admin/class-opti-behavior-heatmap-list.php',
		'Opti_Behavior_Heatmap_Ajax_Handler'    => 'admin/class-opti-behavior-heatmap-ajax-handler.php',
		'Opti_Behavior_Heatmap_Post_Metabox'    => 'admin/class-opti-behavior-heatmap-post-metabox.php',
		'Opti_Behavior_Heatmap_Frontend'        => 'public/class-opti-behavior-heatmap-frontend.php',
		'Opti_Behavior_Page_Analytics_Repository' => 'includes/class-opti-behavior-page-analytics-repository.php',
		'Opti_Behavior_Dashboard_Exporter'      => 'includes/class-opti-behavior-dashboard-exporter.php',
		'Opti_Behavior_Stats_Context'           => 'includes/class-opti-behavior-stats-context.php',
		'Opti_Behavior_Stats_Date_Range'        => 'includes/class-opti-behavior-stats-date-range.php',
		'Opti_Behavior_Stats_Spam_Filter'       => 'includes/class-opti-behavior-stats-spam-filter.php',
		'Opti_Behavior_IP_Exclusion'            => 'includes/class-opti-behavior-ip-exclusion.php',
		'Opti_Behavior_Ingest_Gate'             => 'includes/class-opti-behavior-ingest-gate.php',
		'Opti_Behavior_Stats_Repository'        => 'includes/class-opti-behavior-stats-repository.php',
		'Opti_Behavior_Smart_Cleanup_Service'    => 'includes/class-opti-behavior-smart-cleanup-service.php',
		// Unified Retention Protocol (master retention + aggregate history).
		'Opti_Behavior_Retention_Policy'        => 'includes/class-opti-behavior-retention-policy.php',
		'Opti_Behavior_Dimension_Aggregates'    => 'includes/class-opti-behavior-dimension-aggregates.php',
		// Report scheduling classes
		'Opti_Behavior_Report_Scheduler'        => 'includes/class-opti-behavior-report-scheduler.php',
		'Opti_Behavior_Report_Generator'        => 'includes/class-opti-behavior-report-generator.php',
		'Opti_Behavior_Report_Mailer'           => 'includes/class-opti-behavior-report-mailer.php',
		// Smart Insights classes.
		'Opti_Behavior_Smart_Insights_Repository' => 'includes/class-opti-behavior-smart-insights-repository.php',
		'Opti_Behavior_Smart_Insights_Metric_Aggregator' => 'includes/class-opti-behavior-smart-insights-metric-aggregator.php',
		'Opti_Behavior_Smart_Insights_Source_Aggregator' => 'includes/class-opti-behavior-smart-insights-source-aggregator.php',
		'Opti_Behavior_Smart_Insights_Device_Aggregator' => 'includes/class-opti-behavior-smart-insights-device-aggregator.php',
		'Opti_Behavior_Smart_Insights_Funnel_Aggregator' => 'includes/class-opti-behavior-smart-insights-funnel-aggregator.php',
		'Opti_Behavior_Smart_Insights_Heatmap_Aggregator' => 'includes/class-opti-behavior-smart-insights-heatmap-aggregator.php',
		'Opti_Behavior_Smart_Insights_Trend_Calculator' => 'includes/class-opti-behavior-smart-insights-trend-calculator.php',
		'Opti_Behavior_Smart_Insights_Baseline_Calculator' => 'includes/class-opti-behavior-smart-insights-baseline-calculator.php',
		'Opti_Behavior_Smart_Insights_Scorer'   => 'includes/class-opti-behavior-smart-insights-scorer.php',
		'Opti_Behavior_Smart_Insights_Recommendations' => 'includes/class-opti-behavior-smart-insights-recommendations.php',
		'Opti_Behavior_Smart_Insights_Signal_Registry' => 'includes/class-opti-behavior-smart-insights-signal-registry.php',
		'Opti_Behavior_Smart_Insights_Capabilities' => 'includes/class-opti-behavior-smart-insights-capabilities.php',
		'Opti_Behavior_Smart_Insights_Weekly_Summary' => 'includes/class-opti-behavior-smart-insights-weekly-summary.php',
		'Opti_Behavior_Smart_Insights_Correlator' => 'includes/class-opti-behavior-smart-insights-correlator.php',
		'Opti_Behavior_Smart_Insights_Impact_Calculator' => 'includes/class-opti-behavior-smart-insights-impact-calculator.php',
		'Opti_Behavior_Smart_Insights_Segment_Matrix' => 'includes/class-opti-behavior-smart-insights-segment-matrix.php',
		'Opti_Behavior_Smart_Insights_Evidence_Builder' => 'includes/class-opti-behavior-smart-insights-evidence-builder.php',
		'Opti_Behavior_Smart_Insights_Outcome_Evaluator' => 'includes/class-opti-behavior-smart-insights-outcome-evaluator.php',
		'Opti_Behavior_Smart_Insights_Generator' => 'includes/class-opti-behavior-smart-insights-generator.php',
		'Opti_Behavior_Smart_Insights_Scheduler' => 'includes/class-opti-behavior-smart-insights-scheduler.php',
		'Opti_Behavior_Smart_Insights_Page_Signal_Base' => 'includes/class-opti-behavior-smart-insights-page-signal-base.php',
		'Opti_Behavior_Smart_Insights_Signal_High_Traffic_Low_Engagement' => 'includes/class-opti-behavior-smart-insights-signal-high-traffic-low-engagement.php',
		'Opti_Behavior_Smart_Insights_Signal_High_Exit_Rate_Page' => 'includes/class-opti-behavior-smart-insights-signal-high-exit-rate-page.php',
		'Opti_Behavior_Smart_Insights_Signal_Low_Scroll_Depth_Page' => 'includes/class-opti-behavior-smart-insights-signal-low-scroll-depth-page.php',
		'Opti_Behavior_Smart_Insights_Signal_Basic_Bounce_Alert' => 'includes/class-opti-behavior-smart-insights-signal-basic-bounce-alert.php',
		'Opti_Behavior_Smart_Insights_Signal_Traffic_Spike_Observation' => 'includes/class-opti-behavior-smart-insights-signal-traffic-spike-observation.php',
		'Opti_Behavior_Smart_Insights_Signal_Basic_Mobile_Bounce_Warning' => 'includes/class-opti-behavior-smart-insights-signal-basic-mobile-bounce-warning.php',
		'Opti_Behavior_Smart_Insights_Page'     => 'admin/class-opti-behavior-smart-insights-page.php',
		// A/B Testing classes and traits.
		'Opti_Behavior_AB_Tests_Ajax'           => 'includes/trait-opti-behavior-ab-tests-ajax.php',
		'Opti_Behavior_AB_Test_Database'        => 'includes/class-opti-behavior-ab-test-database.php',
		'Opti_Behavior_AB_Test_Bucketer'        => 'includes/class-opti-behavior-ab-test-bucketer.php',
		'Opti_Behavior_AB_Test_Engine'          => 'includes/class-opti-behavior-ab-test-engine.php',
		'Opti_Behavior_AB_Test_Manager'         => 'includes/class-opti-behavior-ab-test-manager.php',
		'Opti_Behavior_AB_Test_Renderer'        => 'includes/class-opti-behavior-ab-test-renderer.php',
		'Opti_Behavior_AB_Test_Page'            => 'admin/class-opti-behavior-ab-test-page.php',
		'Opti_Behavior_AB_Test_Visual_Editor'   => 'admin/class-opti-behavior-ab-test-visual-editor.php',
	);

	/**
	 * Initialize the autoloader.
	 *
	 * @since 1.0.0
	 * @param string $plugin_dir Plugin directory path.
	 */
	public static function init( $plugin_dir ) {
		self::$plugin_dir = $plugin_dir;
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload classes.
	 *
	 * Attempts to load classes using the class map first,
	 * then falls back to convention-based loading.
	 *
	 * @since 1.0.0
	 * @param string $class_name Class name to load.
	 */
	public static function autoload( $class_name ) {
		// Handle Opti_Behavior_Heatmap_*, Opti_Behavior_Page_*, Opti_Behavior_Dashboard_*, Opti_Behavior_Stats_*, Opti_Behavior_Report_*, Opti_Behavior_Consent_*, Opti_Behavior_Smart_Cleanup_*, Opti_Behavior_Smart_Insights_*, and Opti_Behavior_AB_* classes/traits.
		if ( strpos( $class_name, 'Opti_Behavior_Heatmap_' ) !== 0
			&& strpos( $class_name, 'Opti_Behavior_Page_' ) !== 0
			&& strpos( $class_name, 'Opti_Behavior_Dashboard_' ) !== 0
			&& strpos( $class_name, 'Opti_Behavior_Stats_' ) !== 0
			&& strpos( $class_name, 'Opti_Behavior_Report_' ) !== 0
			&& strpos( $class_name, 'Opti_Behavior_Consent_' ) !== 0
			&& strpos( $class_name, 'Opti_Behavior_Smart_Cleanup_' ) !== 0
			&& strpos( $class_name, 'Opti_Behavior_Smart_Insights_' ) !== 0
			&& strpos( $class_name, 'Opti_Behavior_AB_' ) !== 0
			&& strpos( $class_name, 'Opti_Behavior_IP_' ) !== 0
			&& strpos( $class_name, 'Opti_Behavior_Ingest_' ) !== 0
			&& strpos( $class_name, 'Opti_Behavior_Retention_' ) !== 0
			&& strpos( $class_name, 'Opti_Behavior_Dimension_' ) !== 0
		) {
			return;
		}

		// These classes exist in BOTH Free and Pro plugins with the same class name.
		// They must NOT be autoloaded — they are loaded explicitly by the Free Core
		// constructor (when Pro is inactive) or by the Pro Core (when Pro is active).
		// Without this exclusion, Pro's class_exists() checks trigger this autoloader,
		// which loads the Free version, preventing Pro from loading its own version.
		$shared_classes = array(
			'Opti_Behavior_Heatmap_Ajax',
			'Opti_Behavior_Heatmap_Detail_Page',
			'Opti_Behavior_Heatmap_Parser',
			'Opti_Behavior_Heatmap_Cache',
			'Opti_Behavior_Heatmap_File_Storage',
		);
		if ( in_array( $class_name, $shared_classes, true ) ) {
			return;
		}

		if ( isset( self::$class_map[ $class_name ] ) ) {
			$file_path = self::$plugin_dir . '/' . self::$class_map[ $class_name ];
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
				return;
			}
		}

		$file_name = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

		$directories = array(
			'includes',
			'admin',
			'public',
		);

		foreach ( $directories as $dir ) {
			$file_path = self::$plugin_dir . '/' . $dir . '/' . $file_name;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
				return;
			}
		}
	}

}
