<?php
/**
 * Data Protection & Auto-Recovery System
 *
 * Prevents data loss when plugin is disabled, renamed, or moved.
 * Automatically detects and recovers from data integrity issues.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data Protection Class
 *
 * Handles data integrity checks and automatic recovery.
 *
 * @since 1.0.0
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct database queries required for analytics plugin functionality. Custom tables used for high-volume event tracking. Caching not appropriate for real-time analytics data.
class Opti_Behavior_Heatmap_Data_Protection {

	/**
	 * Core instance.
	 *
	 * @since 1.0.0
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $core;

	/**
	 * Protection status option name.
	 *
	 * @since 1.0.0
	 */
	const PROTECTION_OPTION = 'opti_behavior_heatmap_data_protection';

	/**
	 * Last check timestamp option.
	 *
	 * @since 1.0.0
	 */
	const LAST_CHECK_OPTION = 'opti_behavior_heatmap_last_integrity_check';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Opti_Behavior_Heatmap_Core $core Core instance.
	 */
	public function __construct( $core ) {
		$this->core = $core;

		add_action( 'admin_init', array( $this, 'run_integrity_check' ), 5 );
		add_action( 'admin_notices', array( $this, 'show_recovery_notice' ) );
		// Background worker for the heavy (full-table-scan) integrity checks on
		// large installs (C2-3): keeps the hourly deep scan off admin requests.
		add_action( self::DEEP_CHECK_CRON_HOOK, array( $this, 'run_deep_integrity_check' ) );
	}

	/**
	 * Cron hook for the deferred deep integrity check.
	 *
	 * @since 1.8.1.7
	 */
	const DEEP_CHECK_CRON_HOOK = 'opti_behavior_deep_integrity_check';

	/**
	 * Run comprehensive data integrity check.
	 *
	 * Runs every time admin loads to ensure data is accessible.
	 *
	 * @since 1.0.0
	 */
	public function run_integrity_check() {
		// Only run in admin area
		if ( ! is_admin() ) {
			return;
		}

		// PRODUCTION SAFETY (C2-3): admin_init also fires for every admin-ajax
		// request (incl. anonymous tracking beacons) and wp-cron bootstraps —
		// never run maintenance scans there. CLI kept for test harnesses.
		if ( ( wp_doing_ajax() || wp_doing_cron() ) && 'cli' !== PHP_SAPI ) {
			return;
		}

		// Check if we need to run (throttle to once per hour)
		$last_check = get_option( self::LAST_CHECK_OPTION, 0 );
		if ( time() - $last_check < 3600 ) {
			return;
		}

		global $wpdb;
		$issues_found = array();
		$auto_fixed = array();

		// 1. Check if all required tables exist
		$required_tables = array(
			'optibehavior_events',
			'optibehavior_pages',
			'optibehavior_sessions',
			'optibehavior_visitors',
			'optibehavior_pageviews',
			'optibehavior_recordings',
			'optibehavior_session_pages',
			'optibehavior_referrers',
			'optibehavior_outbound_clicks',
			'optibehavior_bot_visits',
			'optibehavior_heatmap_pages',
			'optibehavior_daily_stats',
			'optibehavior_errors',
			'optibehavior_error_types',
			'optibehavior_friction',
			'optibehavior_performance',
			'optibehavior_broken_links',
			'optibehavior_journey_groups',
			'optibehavior_report_schedules',
			'optibehavior_report_logs',
			'optibehavior_ab_tests',
			'optibehavior_ab_variants',
			'optibehavior_ab_goals',
			'optibehavior_ab_impressions',
			'optibehavior_ab_conversions',
			'optibehavior_ab_daily_stats',
			'optibehavior_ab_decision_log',
		);

		$missing_tables = array();
		foreach ( $required_tables as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) );
			if ( ! $exists ) {
				$missing_tables[] = $table;
			}
		}

		if ( ! empty( $missing_tables ) ) {
			$issues_found[] = 'missing_tables';
			// Auto-fix: Recreate tables
			$this->core->get_database()->setup();
			$auto_fixed[] = 'recreated_missing_tables';
		}

		// 2. Check plugin options integrity
		$options = $this->core->get_options();
		if ( ! isset( $options['activated_ver'] ) || empty( $options['activated_ver'] ) ) {
			$issues_found[] = 'missing_version';
			
			// Auto-fix: Restore version info without losing data
			$this->restore_plugin_options();
			$auto_fixed[] = 'restored_plugin_options';
		}

		// Update last check timestamp BEFORE the heavy scans so a concurrent
		// admin load never starts a second pass.
		update_option( self::LAST_CHECK_OPTION, time() );

		// PRODUCTION SAFETY (C2-3): checks 3+4 below are full scans of the
		// events table (measured ~5s combined at 5M rows, unbounded above
		// that). On large installs run them in a background cron tick instead
		// of synchronously inside an admin request.
		$database = $this->core->get_database();
		if ( 'cli' !== PHP_SAPI
			&& $database
			&& method_exists( $database, 'is_large_table' )
			&& $database->is_large_table( $wpdb->prefix . 'optibehavior_events' )
		) {
			if ( ! wp_next_scheduled( self::DEEP_CHECK_CRON_HOOK ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::DEEP_CHECK_CRON_HOOK );
			}
			update_option(
				self::PROTECTION_OPTION,
				array(
					'last_check'       => time(),
					'issues_found'     => $issues_found,
					'auto_fixed'       => $auto_fixed,
					'event_count'      => -1, // Deferred to background deep check.
					'accessible_count' => 0,
				)
			);
			if ( ! empty( $auto_fixed ) ) {
				$debug_manager = $this->core->get_debug_manager();
				$debug_manager->log( 'Auto-recovery completed. Fixed: ' . implode( ', ', $auto_fixed ), 'info', 'data-protection' );
			}
			return;
		}

		$this->run_deep_integrity_check( $issues_found, $auto_fixed );
	}

	/**
	 * Deep integrity checks (full-table scans): data accessibility + orphaned
	 * events. Runs inline on small installs, via cron on large ones.
	 *
	 * @since 1.8.1.7
	 * @param array $issues_found Issues already collected by the cheap checks.
	 * @param array $auto_fixed   Fixes already applied by the cheap checks.
	 */
	public function run_deep_integrity_check( $issues_found = array(), $auto_fixed = array() ) {
		global $wpdb;

		$issues_found = is_array( $issues_found ) ? $issues_found : array();
		$auto_fixed   = is_array( $auto_fixed ) ? $auto_fixed : array();

		// 3. Check data accessibility (can we query the data?)
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix, no user input
		$event_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}optibehavior_events" );
		if ( $event_count > 0 ) {
			// We have events, check if they're accessible via the heatmap query
			$accessible_count = $wpdb->get_var( "
				SELECT COUNT(DISTINCT p.id)
				FROM {$wpdb->prefix}optibehavior_pages p
				INNER JOIN {$wpdb->prefix}optibehavior_events e ON p.id = e.page_id2
				WHERE e.event IN (16, 17, 32, 33, 48, 49)
			" );

			if ( $accessible_count == 0 ) {
				$issues_found[] = 'data_not_accessible';
				
				// Auto-fix: Rebuild page_id2 relationships
				$this->rebuild_page_relationships();
				$auto_fixed[] = 'rebuilt_page_relationships';
			}
		}

		// 4. Check for orphaned events
		$orphaned = $wpdb->get_var( "
			SELECT COUNT(*) 
			FROM {$wpdb->prefix}optibehavior_events e
			LEFT JOIN {$wpdb->prefix}optibehavior_pages p ON e.page_id2 = p.id
			WHERE p.id IS NULL AND e.page_id2 != 0
		" );

		if ( $orphaned > 0 ) {
			$issues_found[] = 'orphaned_events';
			// Auto-fix: Sync orphaned events with pages table
			$this->fix_orphaned_events();
			$auto_fixed[] = 'fixed_orphaned_events';
		}

		// 5. Clear stale caches
		$this->clear_plugin_caches();

		// Store protection status
		$protection_status = array(
			'last_check' => time(),
			'issues_found' => $issues_found,
			'auto_fixed' => $auto_fixed,
			'event_count' => $event_count,
			'accessible_count' => isset( $accessible_count ) ? $accessible_count : 0,
		);
		update_option( self::PROTECTION_OPTION, $protection_status );

		// Log if issues were found and fixed
		if ( ! empty( $auto_fixed ) ) {
			$debug_manager = $this->core->get_debug_manager();
			$debug_manager->log( 'Auto-recovery completed. Fixed: ' . implode( ', ', $auto_fixed ), 'info', 'data-protection' );
		}
	}

	/**
	 * Restore plugin options without losing data.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	private function restore_plugin_options() {
		global $wpdb;
		
		$options = $this->core->get_options();
		
		// Check if we have existing data to determine if this is a recovery scenario
		$has_data = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}optibehavior_events" ) > 0;
		
		if ( $has_data ) {
			// Data exists but options are missing/corrupted - this is a recovery scenario
			$options['activated_ver'] = OPTI_BEHAVIOR_HEATMAP_VERSION;
			$options['activated_plan'] = Opti_Behavior_Heatmap_Core::PLAN;
			
			// Preserve existing settings or set safe defaults
			if ( ! isset( $options['period'] ) ) {
				$options['period'] = 90; // Default retention period
			}

			$this->core->get_options()->save( $options );

			$debug_manager = $this->core->get_debug_manager();
			$debug_manager->log( 'Plugin options restored. Data preserved.', 'info', 'data-protection' );
		}
	}

	/**
	 * Rebuild page_id and page_id2 relationships.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	private function rebuild_page_relationships() {
		global $wpdb;
		
		// Ensure page_id2 matches page_id for all events
		$wpdb->query( "
			UPDATE {$wpdb->prefix}optibehavior_events e
			INNER JOIN {$wpdb->prefix}optibehavior_pages p ON e.page_id = p.id
			SET e.page_id2 = e.page_id
			WHERE e.page_id2 = 0 OR e.page_id2 != e.page_id
		" );

		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'Page relationships rebuilt.', 'info', 'data-protection' );
	}

	/**
	 * Fix orphaned events by creating missing page entries.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	private function fix_orphaned_events() {
		global $wpdb;
		
		// Find events with page_id2 that don't exist in pages table
		$orphaned_events = $wpdb->get_results( "
			SELECT DISTINCT e.page_id2, e.page_id
			FROM {$wpdb->prefix}optibehavior_events e
			LEFT JOIN {$wpdb->prefix}optibehavior_pages p ON e.page_id2 = p.id
			WHERE p.id IS NULL AND e.page_id2 != 0
			LIMIT 100
		" );
		
		foreach ( $orphaned_events as $event ) {
			// Try to find the page by page_id instead
			$page = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}optibehavior_pages WHERE id = %d",
				$event->page_id
			) );
			
			if ( $page ) {
				// Update events to use the correct page_id
				$wpdb->update(
					"{$wpdb->prefix}optibehavior_events",
					array( 'page_id2' => $event->page_id ),
					array( 'page_id2' => $event->page_id2 ),
					array( '%d' ),
					array( '%d' )
				);
			}
		}

		$debug_manager = $this->core->get_debug_manager();
		$debug_manager->log( 'Fixed ' . count( $orphaned_events ) . ' orphaned events.', 'info', 'data-protection' );
	}

	/**
	 * Clear all plugin-related caches.
	 *
	 * @since 1.0.0
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	private function clear_plugin_caches() {
		global $wpdb;
		
		// Clear WordPress transients
		$wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_opti-behavior%'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE '_transient_timeout_opti-behavior%'" );
		
		// Clear only plugin-specific object cache keys — never flush the entire cache
		// (wp_cache_flush() would nuke Redis/Memcached data for all plugins site-wide).
		wp_cache_delete( 'opti_behavior_heatmap_option', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Show admin notice if data was recovered.
	 *
	 * @since 1.0.0
	 */
	public function show_recovery_notice() {
		$protection_status = get_option( self::PROTECTION_OPTION );
		
		if ( ! $protection_status || empty( $protection_status['auto_fixed'] ) ) {
			return;
		}
		
		// Only show notice once per recovery
		if ( isset( $protection_status['notice_shown'] ) ) {
			return;
		}
		
		// Check if we're on the plugin's admin pages
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'opti-behavior' ) === false ) {
			return;
		}
		
		$event_count = isset( $protection_status['event_count'] ) ? $protection_status['event_count'] : 0;
		$accessible_count = isset( $protection_status['accessible_count'] ) ? $protection_status['accessible_count'] : 0;
		
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<strong>✅ opti-behavior Heatmap Data Protection:</strong> 
				Your heatmap data has been automatically recovered! 
				<?php echo esc_html( number_format( $event_count ) ); ?> events and 
				<?php echo esc_html( number_format( $accessible_count ) ); ?> heatmaps are now accessible.
			</p>
			<p>
				<em>The plugin detected and fixed data integrity issues automatically. No data was lost.</em>
			</p>
		</div>
		<?php
		
		// Mark notice as shown
		$protection_status['notice_shown'] = true;
		update_option( self::PROTECTION_OPTION, $protection_status );
	}

	/**
	 * Manual recovery trigger.
	 *
	 * Can be called from admin interface.
	 *
	 * @since 1.0.0
	 * @return array Protection status data.
	 */
	public function manual_recovery() {
		// Force a fresh integrity check
		delete_option( self::LAST_CHECK_OPTION );
		delete_option( self::PROTECTION_OPTION );
		
		$this->run_integrity_check();
		
		$protection_status = get_option( self::PROTECTION_OPTION );
		return $protection_status;
	}

	/**
	 * Get protection status for display.
	 *
	 * @since 1.0.0
	 * @return array Protection status data.
	 */
	public function get_status() {
		return get_option( self::PROTECTION_OPTION, array() );
	}
}

