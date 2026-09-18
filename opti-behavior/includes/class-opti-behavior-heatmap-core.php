<?php
/**
 * Core Class
 *
 * Main core class handling initialization and coordination.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core Class
 *
 * Handles plugin initialization and component coordination.
 *
 * @since 1.0.0
 */
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
class Opti_Behavior_Heatmap_Core {

	const SLUG = 'opti-behavior';
	const PLAN = 'basic';

	const FROM_PC     = 0;
	const FROM_MOBILE = 1;

	const ACCESS_NAMES = array(
		self::FROM_PC     => 'pc',
		self::FROM_MOBILE => 'mobile',
	);

	const VIEW_WIDTH = array(
		self::FROM_PC     => 1250,
		self::FROM_MOBILE => 375,
	);

	const CLICK_PC         = 0x10; // 16
	const CLICK_MOBILE     = 0x11; // 17
	const BREAKAWAY_PC     = 0x20; // 32
	const BREAKAWAY_MOBILE = 0x21; // 33
	const ATTENTION_PC     = 0x30; // 48
	const ATTENTION_MOBILE = 0x31; // 49

	const EVENT_NAMES = array(
		'click_pc'         => self::CLICK_PC,
		'click_mobile'     => self::CLICK_MOBILE,
		'breakaway_pc'     => self::BREAKAWAY_PC,
		'breakaway_mobile' => self::BREAKAWAY_MOBILE,
		'attention_pc'     => self::ATTENTION_PC,
		'attention_mobile' => self::ATTENTION_MOBILE,
	);

	const LIST_PER_PAGE = 25;

	/**
	 * Singleton instance
	 *
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private static $instance = null;

	/**
	 * Options handler
	 *
	 * @var Opti_Behavior_Heatmap_Options
	 */
	protected $options;

	/**
	 * Database handler
	 *
	 * @var Opti_Behavior_Heatmap_Database
	 */
	protected $database;

	/**
	 * Data protection handler
	 *
	 * @var Opti_Behavior_Heatmap_Data_Protection
	 */
	protected $data_protection;

	/**
	 * Debug manager
	 *
	 * @var Opti_Behavior_Heatmap_Debug_Manager
	 */
	protected $debug_manager;

	/**
	 * Session handler
	 *
	 * @var Opti_Behavior_Heatmap_Session
	 */
	protected $session;

	/**
	 * Analytics handler
	 *
	 * @var Opti_Behavior_Heatmap_Analytics
	 */
	protected $analytics;

	/**
	 * Frontend handler
	 *
	 * @var Opti_Behavior_Heatmap_Frontend
	 */
	protected $frontend;

	/**
	 * AJAX handler
	 *
	 * @var Opti_Behavior_Heatmap_Ajax_Handler
	 */
	protected $ajax;

	/**
	 * Bot tracker
	 *
	 * @var Opti_Behavior_Heatmap_Bot_Tracker
	 */
	protected $bot_tracker;

	/**
	 * Dashboard handler
	 *
	 * @var Opti_Behavior_Heatmap_Dashboard
	 */
	protected $dashboard;

	/**
	 * Post meta box handler
	 *
	 * @var Opti_Behavior_Heatmap_Post_Metabox
	 */
	protected $post_metabox;

	/**
	 * Funnel page handler
	 *
	 * @var Opti_Behavior_Funnel_Page
	 */
	protected $funnel_page;

	/**
	 * File storage handler
	 *
	 * @var Opti_Behavior_Heatmap_File_Storage
	 */
	protected $file_storage;

	/**
	 * Heatmap storage handler (optimized file-based storage)
	 *
	 * @var Opti_Behavior_Heatmap_Storage
	 */
	protected $heatmap_storage;

	/**
	 * Data archiver
	 *
	 * @var Opti_Behavior_Heatmap_Data_Archiver
	 */
	protected $data_archiver;

	/**
	 * Smart Insights generator.
	 *
	 * @var Opti_Behavior_Smart_Insights_Generator|null
	 */
	protected $smart_insights_generator = null;

	/**
	 * Is debug mode
	 *
	 * @var bool
	 */
	public $is_debug = false;

	/**
	 * Accuracy ranges
	 *
	 * @var array
	 */
	protected $ar = array();

	/**
	 * Get singleton instance
	 *
	 * @return Opti_Behavior_Heatmap_Core
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Make the singleton visible immediately during construction.
		//
		// WordPress can load text domains while this constructor is still running;
		// our text-domain override calls get_instance() for logging. Without this
		// early assignment, that re-entrant call creates a second core instance,
		// registering admin_menu callbacks twice and rendering pages such as
		// Funnels twice.
		if ( null === self::$instance ) {
			self::$instance = $this;
		}

		$this->init_debug();
		$this->init_options();
		$this->init_components();
		$this->init_hooks();
		$this->set_accuracy_ranges();
	}

	/**
	 * Initialize debug mode.
	 *
	 * @since 1.0.0
	 */
	private function init_debug() {
		$this->is_debug = (bool) apply_filters( 'opti_behavior_heatmap_debug', false );
	}

	/**
	 * Initialize options.
	 *
	 * @since 1.0.0
	 */
	private function init_options() {
		$this->options = new Opti_Behavior_Heatmap_Options( null );
	}

	/**
	 * Check whether a Pro runtime is already loaded in this request.
	 *
	 * Free and Pro intentionally share a small set of heatmap class names. Older
	 * Pro builds include some of those files unconditionally, so Free must not
	 * pre-load its copies while an old Pro runtime is active.
	 *
	 * @return bool
	 */
	private function is_pro_runtime_loaded() {
		return defined( 'OptiBehavior_PRO_VERSION' )
			|| defined( 'OPTI_BEHAVIOR_PRO_VERSION' )
			|| class_exists( 'OptiBehavior_Pro_Core', false );
	}

	/**
	 * Get the loaded Pro version constant, if available.
	 *
	 * @return string
	 */
	private function get_loaded_pro_version() {
		if ( defined( 'OPTI_BEHAVIOR_PRO_VERSION' ) ) {
			return OPTI_BEHAVIOR_PRO_VERSION;
		}

		if ( defined( 'OptiBehavior_PRO_VERSION' ) ) {
			return OptiBehavior_PRO_VERSION;
		}

		return '';
	}

	/**
	 * Check whether the loaded Pro version has shared-class include guards.
	 *
	 * @return bool
	 */
	private function pro_runtime_has_shared_class_guards() {
		$pro_version = $this->get_loaded_pro_version();

		return '' !== $pro_version && version_compare( $pro_version, '1.5.30', '>=' );
	}

	/**
	 * Initialize components.
	 *
	 * @since 1.0.0
	 */
	private function init_components() {
		// Debug manager (must be first for logging)
		$this->debug_manager = new Opti_Behavior_Heatmap_Debug_Manager( $this );
		$this->debug_manager->log( 'Opti-Behavior plugin initializing...', 'info', 'core' );

		// Core components
		$this->debug_manager->log( 'Initializing database component', 'debug', 'core' );
		$this->database  = new Opti_Behavior_Heatmap_Database( $this );

		$this->debug_manager->log( 'Initializing session component', 'debug', 'core' );
		$this->session   = new Opti_Behavior_Heatmap_Session( $this );

		$this->debug_manager->log( 'Initializing analytics component', 'debug', 'core' );
		$this->analytics = new Opti_Behavior_Heatmap_Analytics( $this );

		// Data protection system (must be initialized early)
		$this->debug_manager->log( 'Initializing data protection system', 'debug', 'core' );
		$this->data_protection = new Opti_Behavior_Heatmap_Data_Protection( $this );

		// File storage system (now available in free version too).
		// Do not autoload Free's copy while Pro is active: old Pro builds can
		// later load their own same-named class and fatal on redeclaration.
		if ( ! $this->is_pro_runtime_loaded()
			&& ! class_exists( 'Opti_Behavior_Heatmap_File_Storage', false )
			&& file_exists( OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-heatmap-file-storage.php' )
		) {
			require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-heatmap-file-storage.php';
		}

		if ( class_exists( 'Opti_Behavior_Heatmap_File_Storage', false ) ) {
			$this->debug_manager->log( 'Initializing file storage', 'info', 'core' );
			$this->file_storage = new Opti_Behavior_Heatmap_File_Storage( $this->debug_manager );

			// Data archiver (requires file storage, Pro feature only)
			if ( opti_behavior_pro_active() && class_exists( 'Opti_Behavior_Heatmap_Data_Archiver' ) ) {
				$this->debug_manager->log( 'Pro version detected - initializing data archiver', 'debug', 'core' );
				$this->data_archiver = new Opti_Behavior_Heatmap_Data_Archiver( $this->file_storage );
				$this->data_archiver->init();
			}
		} elseif ( $this->is_pro_runtime_loaded() ) {
			$this->debug_manager->log( 'Skipping Free file storage preload because Pro runtime is loaded', 'debug', 'core' );
		} else {
			$this->debug_manager->log( 'File storage class not found', 'warning', 'core' );
		}

		// Optimized heatmap storage (file-based with metadata in filename)
		if ( class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			$this->debug_manager->log( 'Initializing optimized heatmap storage', 'info', 'core' );
			$this->heatmap_storage = Opti_Behavior_Heatmap_Storage::get_instance();
			$this->heatmap_storage->set_debug_manager( $this->debug_manager );
		}

		// Frontend components
		$this->debug_manager->log( 'Initializing frontend component', 'debug', 'core' );
		$this->frontend = new Opti_Behavior_Heatmap_Frontend( $this );

		// AJAX handler (needed for both frontend and admin)
		$this->debug_manager->log( 'Initializing AJAX handler', 'debug', 'core' );
		$this->ajax = new Opti_Behavior_Heatmap_Ajax_Handler( $this );

		// Bot tracker (server-side bot detection)
		$this->debug_manager->log( 'Initializing bot tracker', 'debug', 'core' );
		$this->bot_tracker = new Opti_Behavior_Heatmap_Bot_Tracker( $this );

		// A/B Testing components (loaded on both frontend and admin).
		$this->debug_manager->log( 'Initializing A/B testing components', 'debug', 'core' );
		$this->init_ab_testing();

		// Funnel tracking (needs to be initialized on both frontend and backend)
		$this->debug_manager->log( 'Initializing funnel tracking', 'debug', 'core' );
		require_once OPTI_BEHAVIOR_HEATMAP_ADMIN_DIR . 'class-opti-behavior-funnel-page.php';
		$this->funnel_page = new Opti_Behavior_Funnel_Page( $this );

		// WP-Cron context: the dashboard registers the aggregate-sync cron
		// worker (opti_behavior_heatmap_agg_sync) in its constructor, so it
		// must exist on cron requests too (wp-cron.php is not is_admin()).
		if ( ! is_admin() && defined( 'DOING_CRON' ) && DOING_CRON ) {
			$this->dashboard = new Opti_Behavior_Heatmap_Dashboard( $this );
		}

		// Admin components
		if ( is_admin() ) {
			$this->debug_manager->log( 'Admin context detected - initializing admin components', 'debug', 'core' );
			$this->dashboard = new Opti_Behavior_Heatmap_Dashboard( $this );
			$this->post_metabox = new Opti_Behavior_Heatmap_Post_Metabox( $this );

			// Initialize heatmap utilities (from Pro, now in Free)
			// Only load if Pro plugin is not active to avoid duplicate class declarations.
			//
			// TIMING: the Free core is usually initialized on `init` priority 10 while
			// the Pro plugin only boots its core + manifest/status managers on `init`
			// priority 11 (opti_behavior_pro_init). Before that boot the hardened gate
			// fails closed, so opti_behavior_pro_validate_env() returns false even for
			// a perfectly valid Pro license — the Free fallback then loaded its own
			// copy of the detail page, and Pro instantiated a second one, registering
			// the hidden admin page twice and rendering the whole detail page twice
			// (with the Free locked "Top Clicked Elements" block on top of that).
			// When the Pro runtime is loaded but its core has not booted yet, DEFER
			// the decision to `init` priority 20 so the license state is knowable.
			$pro_boot_pending = $this->is_pro_runtime_loaded()
				&& ! class_exists( 'OptiBehavior_Pro_Core', false )
				&& ( ! did_action( 'init' ) || doing_action( 'init' ) );

			if ( $pro_boot_pending ) {
				$this->debug_manager->log( 'Pro runtime loaded but not booted yet - deferring FREE heatmap component decision to init:20', 'debug', 'core' );
				add_action( 'init', array( $this, 'maybe_load_free_heatmap_components' ), 20 );
			} else {
				$this->maybe_load_free_heatmap_components();
			}
		}

		$this->debug_manager->log( 'All components initialized successfully', 'info', 'core' );
	}

	/**
	 * Load the FREE copies of the heatmap detail page / parser / cache / AJAX
	 * components when the Pro runtime does not own them.
	 *
	 * FREE-feature recovery: when Pro plugin file is loaded but the license is
	 * invalid (suspended / disabled / expired / chargebacked / blacklisted /
	 * missing manifest), Pro's init_modules() early-returns and does NOT load
	 * the heatmap detail page / parser / cache / AJAX classes. In that case
	 * $pro_active is still true (the helper is hardcoded), but the FREE plugin
	 * must take over so FREE-tier features (click heatmap detail page) keep
	 * working. We detect this by also checking opti_behavior_pro_validate_env(),
	 * which returns false when the manifest does not grant Pro access.
	 *
	 * May run deferred on `init` priority 20 (after Pro boots on priority 11).
	 *
	 * @since 1.8.1.4
	 */
	public function maybe_load_free_heatmap_components() {
		$pro_runtime_loaded = $this->is_pro_runtime_loaded();
		$pro_active         = function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active();
		$pro_modules_loaded = $pro_active
			&& function_exists( 'opti_behavior_pro_validate_env' )
			&& opti_behavior_pro_validate_env();
		$can_load_free_heatmap_components = ! $pro_modules_loaded
			&& ( ! $pro_runtime_loaded || $this->pro_runtime_has_shared_class_guards() );
		$this->debug_manager->log( 'Pro plugin active status: ' . ( $pro_active ? 'YES' : 'NO' ) . '; Pro runtime loaded: ' . ( $pro_runtime_loaded ? 'YES' : 'NO' ) . '; Pro modules loaded: ' . ( $pro_modules_loaded ? 'YES' : 'NO' ), 'debug', 'core' );

		if ( $can_load_free_heatmap_components ) {
			$this->debug_manager->log( 'Loading FREE version heatmap components (Pro modules unavailable)', 'info', 'core' );

			if ( ! class_exists( 'Opti_Behavior_Heatmap_Parser', false ) && file_exists( OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-heatmap-parser.php' ) ) {
				require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-heatmap-parser.php';
				$this->debug_manager->log( 'Loaded Opti_Behavior_Heatmap_Parser class', 'debug', 'core' );
			}
			if ( ! class_exists( 'Opti_Behavior_Heatmap_Cache', false ) && file_exists( OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-heatmap-cache.php' ) ) {
				require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-heatmap-cache.php';
				$this->debug_manager->log( 'Loaded Opti_Behavior_Heatmap_Cache class', 'debug', 'core' );
			}

			// Initialize heatmap detail page (from Pro, now in Free)
			// Already checked $pro_active above - this code only runs if Pro is NOT active
			if ( ! class_exists( 'Opti_Behavior_Heatmap_Detail_Page', false ) && file_exists( OPTI_BEHAVIOR_HEATMAP_ADMIN_DIR . 'class-opti-behavior-heatmap-detail-page.php' ) ) {
				require_once OPTI_BEHAVIOR_HEATMAP_ADMIN_DIR . 'class-opti-behavior-heatmap-detail-page.php';
				new Opti_Behavior_Heatmap_Detail_Page();
				$this->debug_manager->log( 'Initialized Opti_Behavior_Heatmap_Detail_Page', 'info', 'core' );
			}

			// Initialize heatmap AJAX handlers (from Pro, now in Free)
			$ajax_file_path = OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-heatmap-ajax.php';
			$ajax_class_exists = class_exists( 'Opti_Behavior_Heatmap_Ajax', false );
			$ajax_file_exists = file_exists( $ajax_file_path );

			$this->debug_manager->log( 'AJAX class check: class_exists=' . ( $ajax_class_exists ? 'YES' : 'NO' ) . ', file_exists=' . ( $ajax_file_exists ? 'YES' : 'NO' ) . ', path=' . $ajax_file_path, 'debug', 'core' );

			// Already checked $pro_active above - this code only runs if Pro is NOT active
			if ( file_exists( $ajax_file_path ) ) {
				if ( ! class_exists( 'Opti_Behavior_Heatmap_Ajax', false ) ) {
					require_once $ajax_file_path;
				}
				// Always instantiate when Pro is inactive
				new Opti_Behavior_Heatmap_Ajax();
				$this->debug_manager->log( 'Initialized Opti_Behavior_Heatmap_Ajax - AJAX handlers registered for FREE version', 'info', 'core' );
			} else {
				$this->debug_manager->log( 'ERROR: Cannot load Opti_Behavior_Heatmap_Ajax - file does not exist', 'error', 'core' );
			}
		} else {
			$this->debug_manager->log( 'Pro runtime owns heatmap components - skipping FREE version heatmap components', 'info', 'core' );
		}
	}

	/**
	 * Initialize A/B testing components.
	 *
	 * Loads the database layer, bucketer, statistical engine, manager,
	 * renderer (frontend only), and registers the AJAX trait actions.
	 *
	 * @since 1.3.0
	 */
	private function init_ab_testing() {
		// All A/B classes are autoloaded via class-autoloader.php class map.
		// Renderer (frontend only — hooks into template_redirect, the_title, etc.).
		if ( ! is_admin() ) {
			if ( class_exists( 'Opti_Behavior_AB_Test_Renderer' ) ) {
				$renderer = Opti_Behavior_AB_Test_Renderer::get_instance();
				$renderer->init();
				$this->debug_manager->log( 'A/B test renderer initialized', 'debug', 'core' );
			}
		}

		// Admin page (registers menu, enqueues assets).
		if ( is_admin() && class_exists( 'Opti_Behavior_AB_Test_Page' ) ) {
			new Opti_Behavior_AB_Test_Page( $this );
			$this->debug_manager->log( 'A/B test admin page initialized', 'debug', 'core' );
		}

		// Visual Editor — hooks admin_menu, admin_enqueue_scripts, wp_ajax_*, and
		// wp_enqueue_scripts (frontend helper injection), so instantiated unconditionally.
		if ( class_exists( 'Opti_Behavior_AB_Test_Visual_Editor' ) ) {
			new Opti_Behavior_AB_Test_Visual_Editor();
			$this->debug_manager->log( 'A/B test visual editor initialized', 'debug', 'core' );
		}

		// Register AJAX actions via the trait on the existing AJAX handler.
		// The trait Opti_Behavior_AB_Tests_Ajax is `use`d by Opti_Behavior_Heatmap_Ajax_Handler.
		if ( isset( $this->ajax ) && method_exists( $this->ajax, 'register_ab_test_ajax_actions' ) ) {
			$this->ajax->register_ab_test_ajax_actions();
			$this->debug_manager->log( 'A/B test AJAX actions registered', 'debug', 'core' );
		}

		$this->debug_manager->log( 'A/B testing components loaded', 'info', 'core' );
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks() {
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'opti_behavior_heatmap_cron_daily', array( $this, 'opti_behavior_heatmap_cron_daily' ) );
		add_action( 'opti_behavior_files_tier_continue', array( $this, 'run_heavy_files_tier' ) );
		add_action( 'opti_behavior_recording_fallback_continue', array( $this, 'run_recording_files_fallback' ) );
		add_action( 'opti_behavior_spam_tier_run', array( $this, 'run_daily_spam_tier' ) );
		add_action( 'opti_behavior_db_size_cap_run', array( 'Opti_Behavior_DB_Size_Cap', 'run_tick' ) );
		add_action( 'opti_behavior_db_schema_migration_run', array( 'Opti_Behavior_DB_Schema_Migration', 'run_tick' ) );
		// 1.9.5: heatmaps only for pages that matter + one-time archive-page prune.
		if ( class_exists( 'Opti_Behavior_Heatmap_Page_Type_Prune' ) ) {
			Opti_Behavior_Heatmap_Page_Type_Prune::register_hooks();
		}
		add_action( Opti_Behavior_Heatmap_Engagement_Counters::TICK_HOOK, array( 'Opti_Behavior_Heatmap_Engagement_Counters', 'run_tick' ) );
		add_action( 'opti_behavior_aggregate_daily_stats', array( $this, 'aggregate_daily_stats' ) );
		add_action( 'opti_behavior_send_scheduled_reports', array( $this, 'process_scheduled_reports' ) );
		add_action( 'opti_behavior_scheduled_smart_cleanup', array( $this, 'run_scheduled_smart_cleanup' ) );
		// Cleanup Tasks run tracker: every run of a registry task hook (cron or
		// "Run now") ends in one Cleanup History entry with its stats. Late
		// init so Pro has appended its rows through the registry filter.
		add_action( 'init', array( 'Opti_Behavior_Cleanup_Task_Registry', 'maybe_register_run_tracking' ), 99 );
		// Backup cron for the 3-hour debug auto-disable (lazy check in the
		// debug manager constructor remains authoritative on every request).
		add_action( Opti_Behavior_Heatmap_Debug_Manager::AUTO_DISABLE_CRON_HOOK, array( $this->debug_manager, 'handle_auto_disable_event' ) );
		// Dismissible wp-admin notice shown after a debug flag was auto-disabled.
		add_action( 'admin_notices', array( $this->debug_manager, 'render_auto_disabled_notice' ) );
		add_action( 'admin_post_' . Opti_Behavior_Heatmap_Debug_Manager::NOTICE_DISMISS_ACTION, array( $this->debug_manager, 'handle_dismiss_auto_disabled_notice' ) );
		add_action( Opti_Behavior_Heatmap_Database::RECLASSIFY_SPAM_CRON_HOOK, array( $this->database, 'run_reclassify_spam_batch' ) );
		// Heavy-migrations background worker (C2-3): builds large-table indexes
		// and runs batched invalid-data cleanup OFF the web-request path — one
		// heavy statement per tick, resumable.
		add_action( Opti_Behavior_Heatmap_Database::HEAVY_MIGRATIONS_CRON_HOOK, array( $this->database, 'run_heavy_migrations' ) );
		// Stale-session finalize sweep: recurring cron + opportunistic run when the
		// analytics dashboard loads, so an admin viewing the reports sees sessions
		// whose is_final beacon was lost already finalized (spam vs human) instead
		// of waiting for the next cron tick.
		add_action( Opti_Behavior_Heatmap_Database::FINALIZE_STALE_CRON_HOOK, array( $this->database, 'run_finalize_stale_sessions_batch' ) );
		add_action( 'load-toplevel_page_opti-behavior-analytics', array( $this->database, 'run_finalize_stale_sessions_batch' ), 5 );
		add_filter( 'cron_schedules', array( $this->database, 'add_cron_intervals' ) );
		add_action( Opti_Behavior_Smart_Insights_Generator::CRON_HOOK, array( $this, 'process_smart_insights_daily' ) );
		add_action( Opti_Behavior_Smart_Insights_Scheduler::BATCH_HOOK, array( $this, 'process_smart_insights_scheduler_batch' ) );
		add_action( Opti_Behavior_Smart_Insights_Scheduler::OUTCOME_HOOK, array( $this, 'process_smart_insights_outcome_check' ) );
		add_filter( 'cron_schedules', array( 'Opti_Behavior_Smart_Insights_Scheduler', 'add_cron_schedules' ) );
		add_action( 'load-toplevel_page_opti-behavior-analytics', array( $this, 'maybe_refresh_smart_insights_admin' ) );
		add_action( 'load-opti-behavior_page_opti-behavior-smart-insights', array( $this, 'maybe_refresh_smart_insights_admin' ) );
		add_filter( 'wp_is_mobile', array( $this, 'wp_is_mobile' ) );

		// Unified Retention Protocol: one-shot dimension-aggregate backfill of
		// historical days from still-available raw data (batched single events).
		add_action( Opti_Behavior_Dimension_Aggregates::BACKFILL_HOOK, array( 'Opti_Behavior_Dimension_Aggregates', 'run_backfill' ) );

		// A/B testing cron hooks.
		add_action( 'opti_behavior_ab_aggregate_daily', array( $this, 'ab_aggregate_daily_stats' ) );
		add_action( 'opti_behavior_ab_auto_winner_check', array( $this, 'ab_auto_winner_check' ) );
		add_action( 'opti_behavior_ab_cleanup', array( $this, 'ab_cleanup_old_data' ) );

		// DEF-AB-003 fix: wire up the Pro scheduler so tests with a
		// start_date/end_date actually auto-start and auto-stop. The Pro
		// class `Opti_Behavior_AB_Test_Scheduler` defines the static methods
		// but nothing was invoking them, leaving the whole schedule feature
		// dormant. Hooking onto the existing hourly auto-winner cron keeps
		// the cron-table footprint small.
		add_action( 'opti_behavior_ab_auto_winner_check', array( __CLASS__, 'ab_run_scheduler_pro' ), 20 );
	}

	/**
	 * DEF-AB-003 fix helper — invoked from the hourly `opti_behavior_ab_auto_winner_check`
	 * cron tick. No-ops safely when the Pro plugin is inactive or the class is missing.
	 *
	 * @since 1.2.8
	 */
	public static function ab_run_scheduler_pro() {
		if ( ! class_exists( 'Opti_Behavior_AB_Test_Scheduler' ) ) {
			return;
		}
		if ( method_exists( 'Opti_Behavior_AB_Test_Scheduler', 'auto_start_scheduled_tests' ) ) {
			Opti_Behavior_AB_Test_Scheduler::auto_start_scheduled_tests();
		}
		if ( method_exists( 'Opti_Behavior_AB_Test_Scheduler', 'auto_stop_expired_tests' ) ) {
			Opti_Behavior_AB_Test_Scheduler::auto_stop_expired_tests();
		}
	}

	/**
	 * Set accuracy ranges
	 */
	private function set_accuracy_ranges() {
		$this->ar = $this->get_ar();
	}

	/**
	 * Get accuracy ranges
	 *
	 * @return array
	 */
	public function get_ar() {
		return array(
			// PC accuracy ranges (index 0)
			array(
				1 => array( 600, 2560 ),  // Standard accuracy: very wide range
				2 => array( 700, 1920 ),  // High accuracy: common desktop range
			),
			// Mobile accuracy ranges (index 1)
			array(
				1 => array( 280, 768 ),   // Standard accuracy: all mobile devices
				2 => array( 320, 600 ),   // High accuracy: common mobile range
			),
		);
	}

	/**
	 * Admin init callback
	 */
	public function admin_init() {
		// PRODUCTION SAFETY (C2-3): admin_init also fires on every admin-ajax.php
		// request — including every ANONYMOUS frontend tracking beacon — and this
		// method is pure maintenance (version-gated setup/migrations, structural
		// probes, cron ensures, asset registration for admin screens). Running it
		// per beacon meant the migration battery could start from frontend
		// traffic (measured site-down mechanism). Only run it on real admin
		// screen requests; CLI is kept for the test harnesses.
		if ( ( wp_doing_ajax() || wp_doing_cron() ) && 'cli' !== PHP_SAPI ) {
			return;
		}

		// Handle activation
		if ( is_admin() && in_array( get_option( 'Activated_Plugin' ), array( 'opti-behavior', 'opti_behavior_heatmap' ) ) ) {
			delete_option( 'Activated_Plugin' );
			$this->database->activation();
		}

		// Check for version updates
		$activated_ver = $this->options['activated_ver'] ? $this->options['activated_ver'] : '';
		if ( version_compare( $activated_ver, OPTI_BEHAVIOR_HEATMAP_VERSION, '<' ) || self::PLAN !== $this->options['activated_plan'] ) {
			$this->database->setup();
		}

			// Safety check: ensure analytics tables exist (self-healing on admin).
			// This intentionally covers every table created by database setup, not just
			// the newest tables, so interrupted upgrades and partial uploads can recover
			// by re-running dbDelta without deleting existing data.
			//
			// PERF: running ~27 SHOW TABLES LIKE queries on every admin load adds
			// measurable latency on big installs. Once verified, cache the result in a
			// short-lived transient so the structural probe only runs occasionally. This
			// is a schema/structure check (not analytics data), so caching is safe; the
			// transient expires quickly so a dropped table still self-heals soon after.
			global $wpdb;
			$tables_verified = get_transient( 'opti_behavior_tables_verified' );
			if ( 'ok' !== $tables_verified ) {
				$need_setup = false;
				$tables = array(
					"{$wpdb->prefix}optibehavior_events",
					"{$wpdb->prefix}optibehavior_pages",
					"{$wpdb->prefix}optibehavior_sessions",
					"{$wpdb->prefix}optibehavior_visitors",
					"{$wpdb->prefix}optibehavior_pageviews",
					"{$wpdb->prefix}optibehavior_recordings",
					"{$wpdb->prefix}optibehavior_session_pages",
					"{$wpdb->prefix}optibehavior_referrers",
					"{$wpdb->prefix}optibehavior_outbound_clicks",
					"{$wpdb->prefix}optibehavior_bot_visits",
					"{$wpdb->prefix}optibehavior_heatmap_pages",
					"{$wpdb->prefix}optibehavior_daily_stats",
					"{$wpdb->prefix}optibehavior_insights",
					"{$wpdb->prefix}optibehavior_errors",
					"{$wpdb->prefix}optibehavior_error_types",
					"{$wpdb->prefix}optibehavior_friction",
					"{$wpdb->prefix}optibehavior_performance",
					"{$wpdb->prefix}optibehavior_broken_links",
					"{$wpdb->prefix}optibehavior_journey_groups",
					"{$wpdb->prefix}optibehavior_report_schedules",
					"{$wpdb->prefix}optibehavior_report_logs",
					"{$wpdb->prefix}optibehavior_ab_tests",
					"{$wpdb->prefix}optibehavior_ab_variants",
					"{$wpdb->prefix}optibehavior_ab_goals",
					"{$wpdb->prefix}optibehavior_ab_impressions",
					"{$wpdb->prefix}optibehavior_ab_conversions",
					"{$wpdb->prefix}optibehavior_ab_daily_stats",
					"{$wpdb->prefix}optibehavior_ab_decision_log",
				);
				foreach ( $tables as $t ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Checking table existence.
					if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
						$need_setup = true;
						break;
					}
				}
				if ( $need_setup ) {
					$this->database->setup();
				} else {
					// All tables present: skip the structural probe on subsequent loads
					// for a short window. Kept short so a dropped table self-heals soon.
					set_transient( 'opti_behavior_tables_verified', 'ok', 10 * MINUTE_IN_SECONDS );
				}
			}

			// One-time data migration: fix sessions that have ip = NULL due to the
			// ajax_save_recording() race-condition bug.  The migration is idempotent
			// (guarded by an option flag) so it is safe to call on every admin_init.
			$this->database->migrate_null_ip_to_anonymous();

			// One-time data migration: re-normalise url2 (strip srsltid/gbraid/... +
			// trailing slash) so one physical page stops splitting into several
			// page rows. Idempotent (guarded by an option flag).
			if ( method_exists( $this->database, 'migrate_url2_canonical_normalization' ) ) {
				$this->database->migrate_url2_canonical_normalization();
			}

			// One-time data migration: backfill empty/NULL page titles (e.g. from older
			// Pro session-recording stub inserts) so they no longer render as
			// "Untitled Page". Idempotent (guarded by an option flag).
			$this->database->migrate_backfill_empty_page_titles();

			// One-time data migration: backfill sessions.exit_page from recorded
			// pageviews (the tracker historically never wrote the column, leaving
			// the Exit Page dashboard filter with nothing to match). Batched;
			// idempotent (guarded by an option flag).
			$this->database->migrate_backfill_session_exit_pages();

			// One-time aggregate resync after the 1.7.5 heatmap allow-list
			// page-identity fix. Idempotent (guarded by an option flag).
			$this->database->migrate_resync_heatmap_aggregates_for_allowlist();

			// One-time cleanup: collapse duplicate optibehavior_heatmap_pages
			// mappings (stale QA/alias URLs colliding on one page_id) that
			// double-count every heatmap number. Archives stale data under
			// _orphaned/. Idempotent (guarded by an option flag).
			$this->database->migrate_dedupe_heatmap_page_mappings();

			// One-time cleanup: purge STALE single-row QA heatmap mappings that the
			// dedupe pass skips (page_ids with a single mapping row pointing at a
			// dead ob-pro-qa-/ob-dash-qa- URL). Same non-destructive _orphaned/
			// archival. Idempotent (guarded by its own option flag).
			$this->database->migrate_purge_stale_qa_page_mappings();

			// One-time file/DB reconciliation: archive ORPHAN heatmap file sessions
			// (filename tokens with no matching DB session row) to _orphaned/ so the
			// file-derived detail surfaces (Visitor Type dropdown, Views strip) stop
			// exceeding the canonical pageview-session count. Moved, never deleted.
			// Idempotent (guarded by its own option flag).
			$this->database->migrate_archive_orphan_heatmap_file_sessions();

			// One-time data repair: re-classify recently-flagged spam sessions so
			// permanent-stick false positives (last heartbeat preceded final click
			// flush) recover. Batched + cron-continued; guarded by an option flag.
			$this->database->migrate_reclassify_flagged_spam_sessions();

			// Recurring safety net: finalize sessions whose is_final end beacon was
			// lost, so they cannot stay on the column-default 'human' forever.
			// Ensured from admin_init so already-active installs get the schedule
			// without a fresh setup() cycle.
			$this->database->ensure_finalize_stale_sessions_cron();

			// Recurring background sync-status reconciliation (spec §3b): keeps
			// the heatmap DB-vs-file desync cache warm without ever scanning the
			// filesystem on a request. Ensured from admin_init (dashboard is not
			// always instantiated during setup()) so already-active installs get
			// the schedule without reactivating.
			if ( $this->dashboard && method_exists( $this->dashboard, 'ensure_heatmap_sync_reconcile_cron' ) ) {
				$this->dashboard->ensure_heatmap_sync_reconcile_cron();
			}

			// Recurring per-day-index drift reconciler (spec P4.2): rotating
			// sweep comparing cheap dir metadata (file count + mtime) against the
			// daily index so deletions made outside the plugin (FTP, shell,
			// external cleanup) are detected and re-derived. Ensured from
			// admin_init for the same reason as the reconcile cron above.
			if ( $this->dashboard && method_exists( $this->dashboard, 'ensure_heatmap_reconcile_cron' ) ) {
				$this->dashboard->ensure_heatmap_reconcile_cron();
			}

			// Self-serve heatmap index backfill (Task 1, no WP-CLI): on upgrade /
			// first admin load, detect a stale/unpopulated agg_* or per-day index
			// backlog and arm the existing self-rescheduling drain workers to
			// converge the FULL backlog in bounded batches. Also re-arms the
			// chain on later admin visits if WP-Cron dropped a continuation
			// event. database->setup() has already (re)created the columns/table
			// above on the upgrade request, so the backlog is detectable here.
			// Cheap: two option reads when the chain is already live.
			if ( $this->dashboard && method_exists( $this->dashboard, 'ensure_heatmap_backfill_drain' ) ) {
				$this->dashboard->ensure_heatmap_backfill_drain();
			}

			// Recurring daily heatmap sync auto-repair (Danger Zone toggle,
			// default ON): schedules the event when enabled, clears it when the
			// admin opted out. Ensured from admin_init for the same reason as
			// the reconcile cron above.
			if ( $this->dashboard && method_exists( $this->dashboard, 'ensure_heatmap_auto_repair_cron' ) ) {
				$this->dashboard->ensure_heatmap_auto_repair_cron();
			}

			// Recurring events-based registry backfill (DB-sourced): heals the
			// shutdown-flush desync so pages with durable heatmap events but no
			// registry row become visible on the Heatmaps list. Ensured from
			// admin_init for the same reason as the reconcile cron above.
			if ( $this->dashboard && method_exists( $this->dashboard, 'ensure_heatmap_registry_backfill_cron' ) ) {
				$this->dashboard->ensure_heatmap_registry_backfill_cron();
			}

			// Recurring daily `_orphaned/` archive retention purge (unbounded-
			// growth fix): permanently deletes archive dirs older than the
			// configurable retention window (default 90 days; 0 disables).
			// Ensured from admin_init for the same reason as the crons above.
			if ( $this->dashboard && method_exists( $this->dashboard, 'ensure_heatmap_orphan_purge_cron' ) ) {
				$this->dashboard->ensure_heatmap_orphan_purge_cron();
			}

			// One-shot product auto-rebuild migration (zero-touch, 2026-08-15):
			// first load after upgrading to a build that ships the file-first
			// registry rebuild schedules the rebuild chain automatically —
			// customers never need to find the Danger Zone button. Starts
			// immediately so it beats the daily crons that age out spam-session
			// verdicts. Subsequent loads cost one autoload-off option read.
			// (Also triggered from the daily heatmap cron for sites whose
			// wp-admin is never opened — see opti_behavior_heatmap_cron_daily().)
			if ( $this->dashboard && method_exists( $this->dashboard, 'maybe_schedule_heatmap_rebuild_migration' ) ) {
				$this->dashboard->maybe_schedule_heatmap_rebuild_migration();
			}

			// One-time settings migration: the product default for spam scrolls is
			// now 0. Update only installs that still have the previous full default
			// tuple (10 seconds, 1 scroll, 1 click) so custom settings are preserved.
			$this->maybe_migrate_spam_scroll_default();

			// One-time settings migration: the product default for the spam session
			// duration threshold is now 3 seconds. Update only installs that still
			// have the previous full default tuple (10 seconds, 0 scrolls, 1 click,
			// detection enabled) so custom settings are preserved.
			$this->maybe_migrate_spam_duration_default();

			// Persistently migrate legacy default-like Smart Cleanup settings so
			// direct option reads no longer see the unsafe max_duration = 2 rule.
			$this->maybe_migrate_legacy_default_auto_cleanup_settings();

			// One-time data repair: cap historical session durations inflated by
			// the old wall-clock duration formula (tab left open = 10+ hour
			// "sessions"). Batched + option-flag guarded; resumes on the next
			// admin load until complete.
			$this->maybe_repair_inflated_session_durations();

			// Existing installations may already have the newest tables but not the
			// Smart Insights cron event. Ensure scheduling from admin only so public
			// requests never run generation logic during upgrades.
			$this->ensure_smart_insights_cron();

			// Self-heal the scheduled report worker when report schedules exist.
			$this->ensure_scheduled_reports_cron( true );

			// Keep the Conditional Cleanup cron event in sync with the saved
			// "Enable automatic cleanup" flag. Deactivation clears every plugin
			// cron hook and reactivation only re-arms this one on a FRESH
			// install, so an enabled install could otherwise sit with no event
			// behind a checked box. Never touches the admin's settings; only
			// arms/clears the event to match them. Cheap: one option read + one
			// cron-array read.
			if ( $this->database && method_exists( $this->database, 'ensure_scheduled_smart_cleanup_cron' ) ) {
				$this->database->ensure_scheduled_smart_cleanup_cron();
			}

		// Register scripts and styles
		$this->register_assets();
	}

	/**
	 * Migrate the old spam-scroll default from 1 to 0 without overriding custom rules.
	 *
	 * @return void
	 */
	private function maybe_migrate_spam_scroll_default() {
		if ( get_option( 'opti_behavior_spam_scroll_default_migrated' ) ) {
			return;
		}

		$settings = get_option( 'opti_behavior_traffic_settings', array() );
		if ( is_array( $settings )
			&& isset( $settings['spam_duration_threshold'], $settings['spam_min_scrolls_threshold'], $settings['spam_min_clicks_threshold'] )
			&& 10 === intval( $settings['spam_duration_threshold'] )
			&& 1 === intval( $settings['spam_min_scrolls_threshold'] )
			&& 1 === intval( $settings['spam_min_clicks_threshold'] )
		) {
			$settings['spam_min_scrolls_threshold'] = 0;
			update_option( 'opti_behavior_traffic_settings', $settings, false );
		}

		update_option( 'opti_behavior_spam_scroll_default_migrated', '1', false );
	}

	/**
	 * Migrate the old spam-duration default from 10 to 3 without overriding custom rules.
	 *
	 * Only installs whose saved settings still exactly match the previous full
	 * default tuple (duration 10 seconds, 0 scrolls, 1 click, detection enabled)
	 * are updated, mirroring maybe_migrate_spam_scroll_default(). Runs after the
	 * scroll migration so installs carried from the oldest default (10/1/1) are
	 * first normalized to 10/0/1 and then picked up here.
	 *
	 * @return void
	 */
	private function maybe_migrate_spam_duration_default() {
		if ( get_option( 'opti_behavior_spam_duration_default_migrated' ) ) {
			return;
		}

		$settings = get_option( 'opti_behavior_traffic_settings', array() );
		if ( is_array( $settings )
			&& isset( $settings['spam_duration_threshold'], $settings['spam_min_scrolls_threshold'], $settings['spam_min_clicks_threshold'] )
			&& 10 === intval( $settings['spam_duration_threshold'] )
			&& 0 === intval( $settings['spam_min_scrolls_threshold'] )
			&& 1 === intval( $settings['spam_min_clicks_threshold'] )
			&& ( ! array_key_exists( 'spam_detection_enabled', $settings ) || ! empty( $settings['spam_detection_enabled'] ) )
		) {
			$settings['spam_duration_threshold'] = 3;
			update_option( 'opti_behavior_traffic_settings', $settings, false );
		}

		update_option( 'opti_behavior_spam_duration_default_migrated', '1', false );
	}

	/**
	 * One-time data repair: cap historical session durations inflated by the
	 * old duration formula (duration = full wall-clock span between start_time
	 * and the last activity ping). A tab left open with occasional activity
	 * produced 10+ hour "sessions" that skewed the Avg Session Time KPI, the
	 * Top Engaged Users table, and the daily_stats rollup.
	 *
	 * The tracker now accumulates ACTIVE time only (idle-aware update in
	 * ensure_session_exists()); this migration brings already-recorded rows in
	 * line by capping each session at the inactivity window and recomputing
	 * daily_stats.total_duration for days that still have raw session rows
	 * (finalized rollup days are otherwise never re-aggregated).
	 *
	 * Batched (5000 rows per UPDATE, limited passes per request) and guarded
	 * by an option flag; if an install has more inflated rows than one request
	 * can process, the flag stays unset and the repair resumes on the next
	 * admin load until complete.
	 *
	 * @return void
	 */
	private function maybe_repair_inflated_session_durations() {
		global $wpdb;

		// v2: the first pass (opti_behavior_session_duration_cap_migrated) ran
		// before the idle-aware accumulator got a TOTAL ceiling, so sessions
		// pinged continuously across days re-inflated to 100+ hours AFTER the
		// one-time repair (visible as impossible spikes in the Avg Session Time
		// KPI sparkline). Re-run the repair once with the same 2h total cap now
		// enforced at write time in ensure_session_exists().
		if ( get_option( 'opti_behavior_session_duration_cap_migrated_v2' ) ) {
			return;
		}

		$sessions_table = $wpdb->prefix . 'optibehavior_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions_table ) );
		if ( ! $table_exists ) {
			update_option( 'opti_behavior_session_duration_cap_migrated_v2', '1', false );
			return;
		}

		// Same 2-hour total ceiling as the live tracker's accumulator cap
		// (opti_behavior_session_duration_cap_total in ensure_session_exists()).
		$cap        = max( 60, (int) apply_filters( 'opti_behavior_session_duration_cap_total', 7200 ) );
		$batch_size = 5000;
		$max_passes = 10;
		$complete   = true;

		// 1) Cap rows whose stored duration exceeds the inactivity window.
		for ( $pass = 0; $pass < $max_passes; $pass++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; batched one-time data repair.
			$affected = (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$sessions_table} SET duration = %d WHERE duration > %d LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cap,
				$cap,
				$batch_size
			) );
			if ( $affected < $batch_size ) {
				break;
			}
		}
		if ( $pass >= $max_passes ) {
			$complete = false;
		}

		// 2) Legacy rows with duration = 0 but a huge start/end span: read paths
		// fall back to TIMESTAMPDIFF(start_time, end_time) for these, so they
		// are equally inflated. Persist the capped value so every consumer
		// (including the CASE WHEN duration > 0 fallback) sees sane data.
		if ( $complete ) {
			for ( $pass = 0; $pass < $max_passes; $pass++ ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix; batched one-time data repair.
				$affected = (int) $wpdb->query( $wpdb->prepare(
					"UPDATE {$sessions_table}
					SET duration = %d
					WHERE duration = 0
					  AND end_time IS NOT NULL
					  AND TIMESTAMPDIFF(SECOND, start_time, end_time) > %d
					LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cap,
					$cap,
					$batch_size
				) );
				if ( $affected < $batch_size ) {
					break;
				}
			}
			if ( $pass >= $max_passes ) {
				$complete = false;
			}
		}

		if ( ! $complete ) {
			// More rows than one request should process: resume next admin load.
			return;
		}

		// 3) Repair the pre-aggregated rollup with the same semantics the
		// nightly aggregator uses (plain SUM(duration) per calendar day). Only
		// days that still have raw session rows are touched; fully pruned
		// historical days keep their existing aggregate.
		$daily_stats_table = $wpdb->prefix . 'optibehavior_daily_stats';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$daily_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $daily_stats_table ) );
		if ( $daily_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; one-time rollup repair, no user input.
			$wpdb->query(
				"UPDATE {$daily_stats_table} ds
				INNER JOIN (
					SELECT DATE(start_time) AS d, SUM(duration) AS td
					FROM {$sessions_table}
					GROUP BY DATE(start_time)
				) x ON x.d = ds.stat_date
				SET ds.total_duration = x.td"
			);
		}

		update_option( 'opti_behavior_session_duration_cap_migrated_v2', '1', false );
	}

	/**
	 * Migrate saved Smart Cleanup defaults from destructive legacy semantics.
	 *
	 * Older default-like settings used max_duration = 2 for the intended
	 * "short sessions" rule. Runtime normalization already prevents execution
	 * with that predicate; this method also persists the safe min_duration shape
	 * so health probes and raw option reads are no longer unsafe.
	 *
	 * @since 1.2.7
	 * @return array Current saved settings after any migration.
	 */
	public function maybe_migrate_legacy_default_auto_cleanup_settings() {
		if ( ! class_exists( 'Opti_Behavior_Smart_Cleanup_Service' ) ) {
			return array();
		}

		$before  = get_option( 'opti_behavior_auto_cleanup_settings', null );
		$service = new Opti_Behavior_Smart_Cleanup_Service();
		$after   = $service->maybe_migrate_saved_auto_cleanup_settings();

		if ( is_array( $before ) && $before !== get_option( 'opti_behavior_auto_cleanup_settings', null ) ) {
			$this->debug_manager->log( 'Migrated saved auto-cleanup settings from legacy max_duration defaults to safe min_duration semantics', 'info', 'core' );
		}

		return $after;
	}

	/**
	 * Register assets
	 */
	private function register_assets() {
		// Register debug script (must be first). Always registered so third
		// parties depending on the handle never break, but only enqueued (as a
		// dependency) when JS debug is enabled — see $debug_deps below.
		wp_register_script(
			'opti-behavior-debug',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/opti-behavior-debug.js',
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION,
			false
		);

		// Debug JS is only pulled onto the page while JS debug is enabled
		// (auto-expires after 3 hours). All consumers guard every call with
		// `if (window.OptiBehaviorDebug)`, so its absence is safe.
		$js_debug_enabled = $this->debug_manager->is_js_debug_enabled();
		$debug_deps       = $js_debug_enabled ? array( 'opti-behavior-debug' ) : array();

		// Localize debug configuration (useless without the file, skip when off).
		if ( $js_debug_enabled ) {
			wp_localize_script(
				'opti-behavior-debug',
				'opti_behaviorDebugConfig',
				$this->debug_manager->get_js_debug_config()
			);
		}

		wp_register_script(
			'opti-behavior',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/opti-behavior-heatmap-simple.js',
			array_merge( array( 'jquery' ), $debug_deps ),
			OPTI_BEHAVIOR_HEATMAP_VERSION,
			false
		);
		wp_register_style(
			'opti-behavior',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/style.css',
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION
		);
	}

	/**
	 * Daily cron callback
	 */
	public function opti_behavior_heatmap_cron_daily() {
		// One-shot product auto-rebuild migration for sites whose wp-admin is
		// never opened (zero-touch, 2026-08-15). MUST run before the retention
		// purge below: delete_old_data() ages out spam-session rows, turning
		// known-spam tokens into unknown tokens the rebuild would then have to
		// treat as legitimate. One autoload-off option read once migrated.
		if ( $this->dashboard && method_exists( $this->dashboard, 'maybe_schedule_heatmap_rebuild_migration' ) ) {
			$this->dashboard->maybe_schedule_heatmap_rebuild_migration();
		}

		// Engagement counters (1.9.2): zero-touch schema seed for sites whose
		// wp-admin is never opened (database->setup() only runs on admin
		// requests), then resume backfill / legacy scroll-move row purge if a
		// one-off tick was lost (also re-arms after a Pro update flips the
		// lean-mode gate open).
		if ( class_exists( 'Opti_Behavior_Heatmap_Engagement_Counters' ) && ! Opti_Behavior_Heatmap_Engagement_Counters::columns_ready() ) {
			Opti_Behavior_Heatmap_Engagement_Counters::ensure_schema();
		}
		if ( class_exists( 'Opti_Behavior_Heatmap_Engagement_Counters' ) ) {
			Opti_Behavior_Heatmap_Engagement_Counters::maybe_schedule_tick( 30 );
		}

		// Self-serve heatmap index backfill (Task 1) for sites whose wp-admin is
		// never opened: same detection/arming as admin_init, so the agg_*/daily
		// index backlog still converges with zero manual steps.
		if ( $this->dashboard && method_exists( $this->dashboard, 'ensure_heatmap_backfill_drain' ) ) {
			$this->dashboard->ensure_heatmap_backfill_drain();
		}

		// Unified Retention Protocol: ONE master day-based retention window
		// (default 365 days) drives all raw/per-session data. The legacy
		// month-based `period` option is absorbed by the master setting via
		// a one-shot migration inside Opti_Behavior_Retention_Policy.
		$retention_days = Opti_Behavior_Retention_Policy::get_raw_retention_days();
		if ( $retention_days > 0 ) {
			$this->database->delete_old_data_by_days( $retention_days );
		}

		// Tiered Retention (2026-08-15, second round): the spam/bot tier and
		// the heavy-files tier ride the existing daily cron (no new cron
		// event — smaller cron-table footprint, nothing new to unschedule on
		// deactivate/uninstall). Both are bounded-batch.
		$this->run_daily_tiered_retention();

		// 1.9.3: size caps (oldest raw rows first) then schema upkeep
		// (redundant index drop, InnoDB conversion, OPTIMIZE reclaim).
		if ( class_exists( 'Opti_Behavior_DB_Size_Cap' ) ) {
			Opti_Behavior_DB_Size_Cap::run_tick();
		}
		if ( class_exists( 'Opti_Behavior_DB_Schema_Migration' ) ) {
			Opti_Behavior_DB_Schema_Migration::run_tick();
		}

		$this->database->cleanup_pages();
		if ( $this->debug_manager ) {
			$this->debug_manager->cleanup_old_logs();
		}
	}

	/**
	 * Tiered Retention daily pass (spam tier + heavy-files tier).
	 *
	 * 1. Spam/bot tier (default ON, toggle): purge raw spam/bot/automated
	 *    sessions via the Smart Cleanup session cascade (DB rows + files stay
	 *    in sync), bounded per run. Dashboard bot-traffic history survives in
	 *    `daily_stats.spam_sessions` / `automated_sessions` aggregates.
	 * 2. Heavy-files tier: archive heatmap JSON files older than the files
	 *    window to `_orphaned/` (Option B guardrail — never hard-deleted;
	 *    the Archived Heatmap Data Retention purge ages them out later) and
	 *    flag the affected heatmap pages so the Heatmaps UI can show an
	 *    "files archived" badge instead of a broken render. Recording/event
	 *    files are handled by the Pro archiver on the same effective window.
	 * 3. Optional aggregates cap (advanced, default 0 = Forever): when a cap
	 *    is configured, prune aggregate rows older than the cap.
	 *
	 * Public so QA harnesses can invoke a deterministic single pass.
	 *
	 * @since 1.9.1 (Tiered Retention)
	 * @return array Summary of the pass (for QA/logging).
	 */
	public function run_daily_tiered_retention() {
		$summary = array(
			'spam_sessions_deleted'   => 0,
			'heatmap_files_archived'  => 0,
			'heatmap_files_capped'    => false,
			'recording_files_deleted' => 0,
			'aggregate_rows_pruned'   => 0,
		);

		if ( ! class_exists( 'Opti_Behavior_Retention_Policy' ) ) {
			return $summary;
		}

		// --- 1. Spam/bot tier: daily bounded cascade purge (own task row +
		// continuation while capped, see run_daily_spam_tier()). ---
		$spam_result                      = $this->run_daily_spam_tier();
		$summary['spam_sessions_deleted'] = $spam_result['sessions_deleted'];

		// --- 2. Heavy-files tier: heatmap raw files (archive, never delete). ---
		$files_tier                        = $this->run_heavy_files_tier();
		$summary['heatmap_files_archived'] = $files_tier['archived'];
		$summary['heatmap_files_capped']   = $files_tier['capped'];

		// --- 2b. Recording files fallback (Pro inactive / license-gated). ---
		// The Pro archiver owns recordings/ retention. When nothing is attached
		// to its cron hook the files would otherwise outlive the files window
		// until the raw cascade (or forever with raw retention = 0).
		$fallback                           = $this->run_recording_files_fallback();
		$summary['recording_files_deleted'] = $fallback['deleted'];

		// --- 3. Optional aggregates cap (default 0 = kept FOREVER). ---
		$agg_months = Opti_Behavior_Retention_Policy::get_aggregates_retention_months();
		if ( $agg_months > 0 && method_exists( $this->database, 'prune_aggregates_older_than_months' ) ) {
			$summary['aggregate_rows_pruned'] = absint( $this->database->prune_aggregates_older_than_months( $agg_months ) );
		}

		return $summary;
	}

	/**
	 * Heavy-files tier pass: archive heatmap JSON older than the files window.
	 *
	 * Bounded per call (file cap + time budget, resume cursor inside the
	 * storage helper). While a pass is capped a one-off continuation runs a
	 * minute later, so busy sites drain the backlog the same day instead of
	 * falling further behind at a fixed number of files per day.
	 *
	 * @since 2026-09-13
	 * @return array{archived:int,capped:bool}
	 */
	public function run_heavy_files_tier() {
		$result = array(
			'archived' => 0,
			'capped'   => false,
		);

		if ( ! class_exists( 'Opti_Behavior_Retention_Policy' ) || ! class_exists( 'Opti_Behavior_Heatmap_Storage' ) ) {
			return $result;
		}

		$files_days = Opti_Behavior_Retention_Policy::get_effective_files_retention_days();
		$raw_days   = Opti_Behavior_Retention_Policy::get_raw_retention_days();
		// Only needed when files expire EARLIER than the DB cascade would
		// remove them anyway (files < raw, or raw disabled while files set).
		$files_expire_early = $files_days > 0 && ( $raw_days < 1 || $files_days < $raw_days );
		if ( ! $files_expire_early ) {
			return $result;
		}

		$storage = Opti_Behavior_Heatmap_Storage::get_instance();
		if ( ! method_exists( $storage, 'archive_heatmap_files_older_than' ) ) {
			return $result;
		}

		$cutoff_ts = time() - ( $files_days * DAY_IN_SECONDS );
		/**
		 * Bound the number of heatmap files archived per call.
		 *
		 * @since 1.9.1
		 * @param int $max_files Max files moved per run.
		 */
		$max_files = absint( apply_filters( 'opti_behavior_files_tier_max_files_per_run', 2000 ) );
		$archive   = $storage->archive_heatmap_files_older_than( $cutoff_ts, $max_files );

		$result['archived'] = isset( $archive['archived'] ) ? absint( $archive['archived'] ) : 0;
		$result['capped']   = ! empty( $archive['capped'] );

		if ( $result['capped'] && ! wp_next_scheduled( 'opti_behavior_files_tier_continue' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'opti_behavior_files_tier_continue' );
		}

		return $result;
	}

	/**
	 * Recording-files retention fallback: delete `recordings/` files older
	 * than the effective files window when NO handler is attached to the Pro
	 * archiver hook (`opti_behavior_cleanup_old_files`) — i.e. Pro is
	 * deactivated, or its runtime was skipped (license gate closed, memory
	 * guard) before the archiver could be wired.
	 *
	 * Skips itself whenever the Pro archiver is live, so the two never race
	 * on the same tree. Bounded per call; while capped a one-off continuation
	 * runs a minute later so a big backlog drains the same day.
	 *
	 * No DB stubbing here: Free's raw cascade removes the rows on its own
	 * window, and a re-activated Pro archiver stubs by date on its next run.
	 *
	 * @since 1.9.x (Cleanup audit 2026-09-13)
	 * @return array{deleted:int,bytes:int,capped:bool,skipped:string}
	 */
	public function run_recording_files_fallback() {
		$result = array(
			'deleted' => 0,
			'bytes'   => 0,
			'capped'  => false,
			'skipped' => '',
		);

		if ( has_action( 'opti_behavior_cleanup_old_files' ) ) {
			$result['skipped'] = 'pro_archiver_active';
			return $result;
		}
		if ( ! class_exists( 'Opti_Behavior_Retention_Policy' ) ) {
			$result['skipped'] = 'no_policy';
			return $result;
		}
		$files_days = Opti_Behavior_Retention_Policy::get_effective_files_retention_days();
		if ( $files_days < 1 ) {
			$result['skipped'] = 'retention_disabled';
			return $result;
		}

		$storage = $this->file_storage;
		if ( ! $storage && class_exists( 'Opti_Behavior_Heatmap_File_Storage' ) ) {
			$storage = new Opti_Behavior_Heatmap_File_Storage( $this->debug_manager );
		}
		if ( ! $storage || ! method_exists( $storage, 'delete_recording_files_older_than' ) ) {
			$result['skipped'] = 'no_storage';
			return $result;
		}

		// Same clock as the recordings/{Y/m/d/H} partition (current_time).
		$cutoff = current_time( 'timestamp' ) - ( $files_days * DAY_IN_SECONDS );
		$sweep  = $storage->delete_recording_files_older_than(
			$cutoff,
			array(
				'max_dirs'    => absint( apply_filters( 'opti_behavior_recording_fallback_max_dirs', 200 ) ),
				'time_budget' => (float) apply_filters( 'opti_behavior_recording_fallback_time_budget', 20 ),
			)
		);
		$result['deleted'] = isset( $sweep['deleted'] ) ? absint( $sweep['deleted'] ) : 0;
		$result['bytes']   = isset( $sweep['bytes'] ) ? absint( $sweep['bytes'] ) : 0;
		$result['capped']  = ! empty( $sweep['capped'] );

		if ( $result['capped'] && ! wp_next_scheduled( 'opti_behavior_recording_fallback_continue' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'opti_behavior_recording_fallback_continue' );
		}

		return $result;
	}

	/**
	 * Spam/bot tier pass: the automatic twin of the "Clean Bot/Spam Traffic"
	 * button. Same selection (every session whose traffic_type is in the
	 * shared Traffic Behavior bot set), same cascade (rows + recording and
	 * heatmap files), bounded per call. The only deliberate difference: the
	 * bot-visit LOG table is pruned at 30 days instead of truncated, so the
	 * dashboard keeps its recent bot history.
	 *
	 * Runs inside the daily tiered pass AND on its own one-off hook
	 * `opti_behavior_spam_tier_run` (Cleanup Tasks "Run now" + continuation
	 * while a pass is capped), so a bot flood larger than one run's cap
	 * drains the same day instead of accumulating.
	 *
	 * @since 1.9.x (Cleanup audit 2026-09-13)
	 * @return array{sessions_deleted:int,capped:bool,skipped:string}
	 */
	public function run_daily_spam_tier() {
		$result = array(
			'sessions_deleted' => 0,
			'capped'           => false,
			'skipped'          => '',
		);

		if ( ! class_exists( 'Opti_Behavior_Retention_Policy' ) || ! class_exists( 'Opti_Behavior_Smart_Cleanup_Service' ) ) {
			$result['skipped'] = 'missing_class';
			return $result;
		}
		if ( ! Opti_Behavior_Retention_Policy::is_spam_daily_enabled() ) {
			$result['skipped'] = 'disabled';
			return $result;
		}
		$service = new Opti_Behavior_Smart_Cleanup_Service();
		if ( ! method_exists( $service, 'run_daily_spam_cleanup' ) ) {
			$result['skipped'] = 'no_service';
			return $result;
		}

		$spam_result                = $service->run_daily_spam_cleanup();
		$result['sessions_deleted'] = isset( $spam_result['sessions_deleted'] ) ? absint( $spam_result['sessions_deleted'] ) : 0;
		$result['capped']           = ! empty( $spam_result['capped'] ) && empty( $spam_result['aborted'] );

		if ( $result['capped'] && ! wp_next_scheduled( 'opti_behavior_spam_tier_run' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'opti_behavior_spam_tier_run' );
		}

		return $result;
	}

	/**
	 * Cron callback: generate default Smart Insights for the last 7 days.
	 *
	 * @since 1.3.3
	 */
	public function process_smart_insights_daily() {
		// Unified Retention Protocol: auto-prune resolved/auto-resolved insights
		// older than the configured window (default 12 months, 0 = never).
		if ( class_exists( 'Opti_Behavior_Smart_Insights_Repository' ) ) {
			$pruned = ( new Opti_Behavior_Smart_Insights_Repository() )->prune_stale_insights();
			if ( $pruned > 0 && $this->debug_manager ) {
				$this->debug_manager->log( 'Smart Insights prune removed ' . $pruned . ' stale closed insight(s).', 'info', 'smart-insights' );
			}
		}

		$generator = $this->get_smart_insights_generator();
		if ( ! $generator ) {
			return;
		}

		$result = Opti_Behavior_Smart_Insights_Scheduler::kickoff( $generator );

		if ( $this->debug_manager ) {
			if ( is_wp_error( $result ) ) {
				$this->debug_manager->log( 'Smart Insights scheduler kickoff failed: ' . $result->get_error_message(), 'warning', 'smart-insights' );
			} else {
				$this->debug_manager->log( 'Smart Insights scheduler kickoff completed: ' . wp_json_encode( $result ), 'info', 'smart-insights' );
			}
		}
	}

	/**
	 * Cron callback: process a small Smart Insights scheduler batch.
	 *
	 * @since 1.3.6
	 */
	public function process_smart_insights_scheduler_batch() {
		$generator = $this->get_smart_insights_generator();
		if ( ! $generator ) {
			return;
		}

		$result = Opti_Behavior_Smart_Insights_Scheduler::process_batch( $generator );

		if ( $this->debug_manager ) {
			if ( is_wp_error( $result ) ) {
				$this->debug_manager->log( 'Smart Insights scheduler batch failed: ' . $result->get_error_message(), 'warning', 'smart-insights' );
			} else {
				$this->debug_manager->log( 'Smart Insights scheduler batch completed: ' . wp_json_encode( $result ), 'info', 'smart-insights' );
			}
		}
	}

	/**
	 * Cron callback: measure resolved Smart Insights after the fix.
	 *
	 * Bounded batch, read-only re-evaluation of the same signal on the same
	 * entity over the two weeks that followed the resolution.
	 *
	 * @since 1.4.0
	 */
	public function process_smart_insights_outcome_check() {
		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Scheduler' ) ) {
			return;
		}

		$result = Opti_Behavior_Smart_Insights_Scheduler::run_outcome_check( $this->get_smart_insights_generator() );

		if ( $this->debug_manager ) {
			$this->debug_manager->log( 'Smart Insights outcome check completed: ' . wp_json_encode( $result ), 'info', 'smart-insights' );
		}
	}

	/**
	 * Admin page-load callback: keep Smart Insights page loads read-only.
	 *
	 * Smart Insights generation mutates global insight lifecycle state by
	 * upserting, suppressing, and auto-resolving rows. Running that generation
	 * during passive admin page loads can make visible counts drift on a normal
	 * browser refresh. Keep this load-* hook as a compatibility no-op and leave
	 * mutation to explicit refresh requests and scheduled cron.
	 *
	 * @since 1.3.3
	 */
	public function maybe_refresh_smart_insights_admin() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->debug_manager ) {
			$this->debug_manager->log( 'Smart Insights passive admin page load is read-only; generation is reserved for explicit refresh and cron.', 'debug', 'smart-insights' );
		}
	}

	/**
	 * Ensure the Smart Insights daily cron is scheduled.
	 *
	 * @since 1.3.3
	 */
	private function ensure_smart_insights_cron() {
		Opti_Behavior_Smart_Insights_Scheduler::reschedule();
	}

	/**
	 * Aggregate daily statistics from raw data into pre-calculated daily_stats table.
	 * Runs via cron at 3 AM daily (before cleanup at 4 AM).
	 * - Finalizes yesterday's data
	 * - Updates today's running totals
	 * - Backfills any missing days (last 7 days)
	 *
	 * @since 1.0.4
	 */
	public function aggregate_daily_stats() {
		global $wpdb;

		$debug_manager = $this->get_debug_manager();
		$debug_manager->log( 'Starting daily stats aggregation', 'info', 'aggregation' );

		$daily_stats_table = $wpdb->prefix . 'optibehavior_daily_stats';
		$sessions_table    = $wpdb->prefix . 'optibehavior_sessions';
		$visitors_table    = $wpdb->prefix . 'optibehavior_visitors';
		$pageviews_table   = $wpdb->prefix . 'optibehavior_pageviews';

		// Check if daily_stats table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $daily_stats_table ) );
		if ( ! $table_exists ) {
			$debug_manager->log( 'Daily stats table does not exist, skipping aggregation', 'warning', 'aggregation' );
			return;
		}

		// Get dates to process: today, yesterday, and backfill last 7 days
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// Build list of dates to process (last 7 days for backfill + today)
		$dates_to_process = array();
		for ( $i = 7; $i >= 0; $i-- ) {
			$dates_to_process[] = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
		}

		$processed_count = 0;

		foreach ( $dates_to_process as $stat_date ) {
			$is_today     = ( $stat_date === $today );
			$is_yesterday = ( $stat_date === $yesterday );

			// Check if this date already has finalized data (skip unless it's today/yesterday)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, is_finalized FROM {$daily_stats_table} WHERE stat_date = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$stat_date
				)
			);

			// Skip finalized historical data (but always process today and yesterday)
			if ( $existing && $existing->is_finalized && ! $is_today && ! $is_yesterday ) {
				continue;
			}

			$date_start = $stat_date . ' 00:00:00';
			$date_end   = $stat_date . ' 23:59:59';

			// Aggregate sessions data for this date
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$session_stats = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COUNT(*) as sessions,
						COUNT(DISTINCT visitor_id) as visitors,
						SUM(page_views) as pageviews,
						SUM(CASE WHEN is_bounce = 1 THEN 1 ELSE 0 END) as bounce_sessions,
						SUM(duration) as total_duration,
						SUM(CASE WHEN traffic_type = 'human' THEN 1 ELSE 0 END) as human_sessions,
						SUM(CASE WHEN traffic_type = 'spam' THEN 1 ELSE 0 END) as spam_sessions,
						SUM(CASE WHEN traffic_type = 'automated' OR traffic_type = 'bot' THEN 1 ELSE 0 END) as automated_sessions
					FROM {$sessions_table}
					WHERE start_time BETWEEN %s AND %s",
					$date_start,
					$date_end
				)
			);

			// Get scroll depth data from pageviews
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$scroll_stats = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						SUM(scroll_depth) as total_scroll_depth,
						COUNT(CASE WHEN scroll_depth > 0 THEN 1 END) as scroll_depth_count
					FROM {$pageviews_table}
					WHERE view_time BETWEEN %s AND %s",
					$date_start,
					$date_end
				)
			);

			// Count new vs returning visitors for this date
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
			$visitor_stats = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COUNT(DISTINCT CASE WHEN v.first_visit >= %s AND v.first_visit <= %s THEN s.visitor_id END) as new_visitors,
						COUNT(DISTINCT CASE WHEN v.first_visit < %s THEN s.visitor_id END) as returning_visitors
					FROM {$sessions_table} s
					LEFT JOIN {$visitors_table} v ON s.visitor_id = v.id
					WHERE s.start_time BETWEEN %s AND %s",
					$date_start,
					$date_end,
					$date_start,
					$date_start,
					$date_end
				)
			);

			// Prepare data for insert/update
			$stats_data = array(
				'stat_date'          => $stat_date,
				'sessions'           => (int) ( $session_stats->sessions ?? 0 ),
				'visitors'           => (int) ( $session_stats->visitors ?? 0 ),
				'pageviews'          => (int) ( $session_stats->pageviews ?? 0 ),
				'bounce_sessions'    => (int) ( $session_stats->bounce_sessions ?? 0 ),
				'total_duration'     => (int) ( $session_stats->total_duration ?? 0 ),
				'total_scroll_depth' => (int) ( $scroll_stats->total_scroll_depth ?? 0 ),
				'scroll_depth_count' => (int) ( $scroll_stats->scroll_depth_count ?? 0 ),
				'human_sessions'     => (int) ( $session_stats->human_sessions ?? 0 ),
				'spam_sessions'      => (int) ( $session_stats->spam_sessions ?? 0 ),
				'automated_sessions' => (int) ( $session_stats->automated_sessions ?? 0 ),
				'new_visitors'       => (int) ( $visitor_stats->new_visitors ?? 0 ),
				'returning_visitors' => (int) ( $visitor_stats->returning_visitors ?? 0 ),
				'last_aggregated'    => current_time( 'mysql' ),
				'is_finalized'       => $is_today ? 0 : 1, // Today is never finalized, past days are.
			);

			if ( $existing ) {
				// Update existing record
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
				$wpdb->update(
					$daily_stats_table,
					$stats_data,
					array( 'id' => $existing->id ),
					array( '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d' ),
					array( '%d' )
				);
			} else {
				// Insert new record
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from $wpdb->prefix; analytics queries.
				$wpdb->insert(
					$daily_stats_table,
					$stats_data,
					array( '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d' )
				);
			}

			// Unified Retention Protocol (Option B): enrich the daily aggregate
			// with per-dimension breakdowns (device/browser/os/resolution/
			// country/referrer/page/hour/visitor_type/traffic/user_type) so
			// Dashboard widgets keep working for dates older than the raw
			// retention window.
			if ( class_exists( 'Opti_Behavior_Dimension_Aggregates' ) ) {
				Opti_Behavior_Dimension_Aggregates::aggregate_for_date( $stat_date );
			}

			++$processed_count;
		}

		$debug_manager->log( "Daily stats aggregation complete. Processed {$processed_count} days.", 'info', 'aggregation' );
	}

	/**
	 * Process scheduled reports via cron.
	 *
	 * Checks for due schedules and sends reports.
	 * Runs every 15 minutes via WordPress cron.
	 *
	 * @since 1.1.0
	 */
	/**
	 * Cron callback: aggregate A/B testing daily stats.
	 *
	 * Rolls up raw impression and conversion rows from yesterday into the
	 * daily_stats table for faster reporting.
	 *
	 * @since 1.3.0
	 */
	public function ab_aggregate_daily_stats() {
		if ( ! class_exists( 'Opti_Behavior_AB_Test_Database' ) ) {
			return;
		}
		$this->debug_manager->log( 'Running A/B daily stats aggregation', 'info', 'ab-cron' );
		Opti_Behavior_AB_Test_Database::aggregate_daily_stats(); // Defaults to yesterday.
	}

	/**
	 * Cron callback: check running A/B tests for auto-winner declaration.
	 *
	 * For each running test, evaluates statistical significance and, if
	 * the result is conclusive and the minimum duration has elapsed,
	 * automatically stops the test and declares a winner.
	 *
	 * @since 1.3.0
	 */
	public function ab_auto_winner_check() {
		if ( ! class_exists( 'Opti_Behavior_AB_Test_Database' ) || ! class_exists( 'Opti_Behavior_AB_Test_Engine' ) || ! class_exists( 'Opti_Behavior_AB_Test_Manager' ) ) {
			return;
		}

		$this->debug_manager->log( 'Running A/B auto-winner check', 'info', 'ab-cron' );

		// Pro: Auto-start/stop scheduled tests.
		if ( class_exists( 'Opti_Behavior_AB_Test_Scheduler' ) ) {
			$started = Opti_Behavior_AB_Test_Scheduler::auto_start_scheduled_tests();
			$stopped = Opti_Behavior_AB_Test_Scheduler::auto_stop_expired_tests();
			if ( $started || $stopped ) {
				$this->debug_manager->log(
					sprintf( 'Scheduler: auto-started %d, auto-stopped %d tests', $started, $stopped ),
					'info',
					'ab-cron'
				);
			}
		}

		$running_tests = Opti_Behavior_AB_Test_Database::get_tests( array( 'status' => 'running', 'per_page' => 100 ) );
		$manager       = Opti_Behavior_AB_Test_Manager::get_instance();

		foreach ( $running_tests as $opti_ab_test ) {
			$opti_ab_stats = Opti_Behavior_AB_Test_Database::get_test_stats( (int) $opti_ab_test->id );
			if ( empty( $opti_ab_stats ) ) {
				continue;
			}

			$opti_ab_sig = Opti_Behavior_AB_Test_Engine::check_significance(
				$opti_ab_stats,
				floatval( $opti_ab_test->confidence_level ),
				(int) $opti_ab_test->min_sample_size,
				(int) $opti_ab_test->min_duration_hours,
				$opti_ab_test->started_at
			);

			if ( ! empty( $opti_ab_sig['is_significant'] ) && ! empty( $opti_ab_sig['winner_variant_id'] ) ) {
				$this->debug_manager->log(
					sprintf( 'Auto-declaring winner for test #%d: variant #%d', $opti_ab_test->id, $opti_ab_sig['winner_variant_id'] ),
					'info',
					'ab-cron'
				);
				$manager->declare_winner( (int) $opti_ab_test->id, (int) $opti_ab_sig['winner_variant_id'] );
			}
		}
	}

	/**
	 * Cron callback: clean up old A/B testing raw data.
	 *
	 * Deletes raw impression and conversion rows older than the master
	 * raw-data retention window (Unified Retention Protocol). Aggregated
	 * daily stats are kept indefinitely.
	 *
	 * @since 1.3.0
	 */
	public function ab_cleanup_old_data() {
		if ( ! class_exists( 'Opti_Behavior_AB_Test_Database' ) ) {
			Opti_Behavior_Cleanup_Task_Registry::report_run(
				array(
					'status' => 'skipped',
					'note'   => __( 'A/B testing is not loaded — nothing was deleted.', 'opti-behavior' ),
				)
			);
			return;
		}

		// Unified Retention Protocol: the legacy independent A/B clock
		// (opti_behavior_ab_settings['data_retention_days']) is retired —
		// the master raw retention setting now drives A/B raw cleanup.
		$retention_days = Opti_Behavior_Retention_Policy::get_raw_retention_days();
		if ( $retention_days < 1 ) {
			Opti_Behavior_Cleanup_Task_Registry::report_run(
				array(
					'status' => 'skipped',
					'note'   => __( 'Data retention is set to keep data forever — no A/B data was deleted.', 'opti-behavior' ),
				)
			);
			return; // Retention disabled — keep raw A/B data.
		}

		$this->debug_manager->log( sprintf( 'Running A/B data cleanup (retention: %d days)', $retention_days ), 'info', 'ab-cron' );
		$rows_by_table = Opti_Behavior_AB_Test_Database::cleanup_old_data_by_table( $retention_days );
		$deleted       = array_sum( $rows_by_table );
		$this->debug_manager->log( sprintf( 'A/B cleanup deleted %d rows', $deleted ), 'info', 'ab-cron' );

		Opti_Behavior_Cleanup_Task_Registry::report_run(
			array(
				'status'         => 'completed',
				'events_deleted' => $deleted,
				'rows_by_table'  => $rows_by_table,
				'note'           => sprintf(
					/* translators: 1: rows deleted, 2: retention window in days */
					__( '%1$d raw A/B row(s) older than %2$d days deleted; aggregated results kept.', 'opti-behavior' ),
					$deleted,
					$retention_days
				),
			)
		);
	}

	public function process_scheduled_reports() {
		$debug_manager = $this->get_debug_manager();
		$debug_manager->log( 'Starting scheduled reports processing', 'info', 'reports' );

		// Load required classes
		if ( ! class_exists( 'Opti_Behavior_Report_Scheduler' ) ) {
			require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-report-scheduler.php';
		}
		if ( ! class_exists( 'Opti_Behavior_Report_Generator' ) ) {
			require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-report-generator.php';
		}
		if ( ! class_exists( 'Opti_Behavior_Report_Mailer' ) ) {
			require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-report-mailer.php';
		}

		$scheduler = new Opti_Behavior_Report_Scheduler( $this );
		$generator = new Opti_Behavior_Report_Generator( $this );
		$mailer    = new Opti_Behavior_Report_Mailer( $this );

		// Get due schedules
		$due_schedules = $scheduler->get_due_schedules();

		if ( empty( $due_schedules ) ) {
			$debug_manager->log( 'No due schedules found', 'debug', 'reports' );
			return;
		}

		$debug_manager->log( sprintf( 'Found %d due schedule(s)', count( $due_schedules ) ), 'info', 'reports' );

		foreach ( $due_schedules as $schedule ) {
			$start_time = microtime( true );

			try {
				$debug_manager->log( sprintf( 'Processing schedule #%d: %s', $schedule['id'], $schedule['name'] ), 'info', 'reports' );

				// Generate report data
				$report = $generator->generate( $schedule );

				if ( ! $report || empty( $report['sections'] ) ) {
					$debug_manager->log( sprintf( 'Schedule #%d: Failed to generate report data', $schedule['id'] ), 'error', 'reports' );

					$scheduler->log_send(
						$schedule['id'],
						'failed',
						array(
							'error'           => 'Failed to generate report data',
							'execution_time'  => (int) ( ( microtime( true ) - $start_time ) * 1000 ),
							'recipients_count'=> count( $schedule['recipients'] ),
						)
					);

					continue;
				}

				// Send report
				$result = $mailer->send( $schedule, $report );

				// Calculate execution time
				$execution_time = (int) ( ( microtime( true ) - $start_time ) * 1000 );

				// Log result
				$status = $result['success'] ? 'success' : ( $result['partial'] ? 'partial' : 'failed' );

				$scheduler->log_send(
					$schedule['id'],
					$status,
					array(
						'recipients_count'   => count( $schedule['recipients'] ),
						'recipients_success' => $result['sent_count'],
						'period_start'       => $report['period_start'],
						'period_end'         => $report['period_end'],
						'error'              => $result['error'] ?? null,
						'execution_time'     => $execution_time,
					)
				);

				// Mark as sent and calculate next send time
				$scheduler->mark_as_sent( $schedule['id'] );

				$debug_manager->log(
					sprintf(
						'Schedule #%d: Completed. Status: %s, Sent: %d/%d, Time: %dms',
						$schedule['id'],
						$status,
						$result['sent_count'],
						count( $schedule['recipients'] ),
						$execution_time
					),
					$status === 'success' ? 'info' : 'warning',
					'reports'
				);

			} catch ( Exception $e ) {
				$debug_manager->log(
					sprintf( 'Schedule #%d: Exception - %s', $schedule['id'], $e->getMessage() ),
					'error',
					'reports'
				);

				$scheduler->log_send(
					$schedule['id'],
					'failed',
					array(
						'error'            => $e->getMessage(),
						'execution_time'   => (int) ( ( microtime( true ) - $start_time ) * 1000 ),
						'recipients_count' => count( $schedule['recipients'] ),
					)
				);
			}
		}

		// Clean up old logs (keep last 90 days)
		$scheduler->clear_old_logs( 90 );

		$debug_manager->log( 'Scheduled reports processing complete', 'info', 'reports' );
	}

	/**
	 * Filter wp_is_mobile
	 *
	 * @param bool $is_mobile Whether the request is from a mobile device.
	 * @return bool
	 */
	public function wp_is_mobile( $is_mobile ) {
		// Nonce verification not required - this filter only reads GET parameters for display purposes
		// The actual heatmap view is protected by capability checks in can_view()
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET parameter for display mode
		$optibehavior = filter_input( INPUT_GET, 'opti-behavior', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET parameter for display mode
		$mobile_view = isset( $_GET['mobile_view'] ) && sanitize_text_field( wp_unslash( $_GET['mobile_view'] ) ) == '1';
		return $is_mobile;
	}

	/**
	 * Option validation callback
	 *
	 * @param array $new_options New options.
	 * @param array $old_options Old options.
	 * @return array
	 */
	public function option_checker( $new_options, $old_options = null ) {
		// Simple validation - just return the options as-is for now
		// More complex validation can be added later
		return $new_options;
	}

	/**
	 * Get options
	 *
	 * @return Opti_Behavior_Heatmap_Options
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Ensure the scheduled reports cron worker exists.
	 *
	 * @since 1.2.7
	 * @param bool $only_when_enabled_schedule Only schedule when at least one enabled report schedule exists.
	 * @return bool True when the worker is scheduled or already healthy.
	 */
	public function ensure_scheduled_reports_cron( $only_when_enabled_schedule = false ) {
		if ( ! $this->database || ! method_exists( $this->database, 'ensure_scheduled_reports_cron' ) ) {
			return false;
		}

		return $this->database->ensure_scheduled_reports_cron( $only_when_enabled_schedule );
	}

	/**
	 * Get database handler
	 *
	 * @return Opti_Behavior_Heatmap_Database
	 */
	public function get_database() {
		return $this->database;
	}

	/**
	 * Get session handler
	 *
	 * @return Opti_Behavior_Heatmap_Session
	 */
	public function get_session() {
		return $this->session;
	}

	/**
	 * Get analytics handler
	 *
	 * @return Opti_Behavior_Heatmap_Analytics
	 */
	public function get_analytics() {
		return $this->analytics;
	}

	/**
	 * Get frontend handler
	 *
	 * @return Opti_Behavior_Heatmap_Frontend
	 */
	public function get_frontend() {
		return $this->frontend;
	}

	/**
	 * Get AJAX handler
	 *
	 * @return Opti_Behavior_Heatmap_Ajax_Handler
	 */
	public function get_ajax() {
		return $this->ajax;
	}

	/**
	 * Get Smart Insights generator.
	 *
	 * @return Opti_Behavior_Smart_Insights_Generator|null
	 */
	public function get_smart_insights_generator() {
		if ( ! class_exists( 'Opti_Behavior_Smart_Insights_Generator' ) ) {
			return null;
		}

		if ( null === $this->smart_insights_generator ) {
			$this->smart_insights_generator = new Opti_Behavior_Smart_Insights_Generator();
		}

		return $this->smart_insights_generator;
	}

	/**
	 * Get dashboard handler
	 *
	 * @return Opti_Behavior_Heatmap_Dashboard
	 */
	public function get_dashboard() {
		return $this->dashboard;
	}

	/**
	 * Get data protection handler
	 *
	 * @return Opti_Behavior_Heatmap_Data_Protection
	 */
	public function get_data_protection() {
		return $this->data_protection;
	}

	/**
	 * Get debug manager
	 *
	 * @return Opti_Behavior_Heatmap_Debug_Manager
	 */
	public function get_debug_manager() {
		return $this->debug_manager;
	}

	/**
	 * Get file storage handler
	 *
	 * @return Opti_Behavior_Heatmap_File_Storage
	 */
	public function get_file_storage() {
		return $this->file_storage;
	}

	/**
	 * Get heatmap storage handler (optimized file-based storage)
	 *
	 * @return Opti_Behavior_Heatmap_Storage
	 */
	public function get_heatmap_storage() {
		return $this->heatmap_storage;
	}

	/**
	 * Get data archiver
	 *
	 * @return Opti_Behavior_Heatmap_Data_Archiver
	 */
	public function get_data_archiver() {
		return $this->data_archiver;
	}

	/**
	 * Get funnel page handler
	 *
	 * @return Opti_Behavior_Funnel_Page
	 */
	public function get_funnel_page() {
		return $this->funnel_page;
	}

	/**
	 * Check if user can view heatmaps
	 *
	 * @return bool
	 */
	public function can_view() {
		// Protect heatmap view: only administrators can view overlay via URL parameter
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameter check for heatmap overlay (permission check follows)
		if ( isset( $_GET['opti-behavior'] ) ) {
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			return current_user_can( 'manage_options' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Fallback: restrict general heatmap viewing to administrators as well
		return current_user_can( 'manage_options' );
	}

	/**
	 * Static activation hook
	 */
	public static function activation() {
		$instance = self::get_instance();
		$instance->database->activation();
	}

	/**
	 * Complete list of every WP-Cron hook the free plugin schedules.
	 *
	 * Single source of truth for deactivation cleanup (and any other code
	 * that needs to reason about the plugin's cron footprint). Hook names
	 * are intentionally string literals — deactivation must never fatal on
	 * a class that happens not to be loaded; the comment next to each entry
	 * names the constant that owns the value where one exists.
	 *
	 * Keep this list in sync with every wp_schedule_event() /
	 * wp_schedule_single_event() call in the plugin.
	 *
	 * @since 1.9.x
	 * @return string[] Cron hook names (recurring and one-off).
	 */
	public static function get_all_cron_hooks() {
		$hooks = array(
			// --- Recurring events -------------------------------------------------
			'opti_behavior_heatmap_cron_daily',              // Daily heatmap maintenance (database).
			'opti_behavior_aggregate_daily_stats',           // Daily stats aggregation (database).
			'opti_behavior_send_scheduled_reports',          // Opti_Behavior_Basename_Migration::REPORT_CRON_HOOK (every 15 min reports worker).
			'opti_behavior_scheduled_smart_cleanup',         // Scheduled smart cleanup (daily/weekly).
			'opti_behavior_heatmap_auto_repair',             // Opti_Behavior_Heatmap_Dashboard::HEATMAP_AUTO_REPAIR_CRON_HOOK (daily + one-off continuations).
			'opti_behavior_smart_insights_generate_daily',   // Opti_Behavior_Smart_Insights_Generator::CRON_HOOK.
			'opti_behavior_smart_insights_outcome_check',    // Opti_Behavior_Smart_Insights_Scheduler::OUTCOME_HOOK (daily before/after check).
			'opti_behavior_heatmap_sync_reconcile',          // Opti_Behavior_Heatmap_Dashboard::HEATMAP_SYNC_CRON_HOOK (every 15 min).
			'opti_behavior_heatmap_reconcile',               // Opti_Behavior_Heatmap_Dashboard::HEATMAP_RECONCILE_CRON_HOOK (hourly + one-off continuations).
			'opti_behavior_heatmap_registry_backfill',       // Opti_Behavior_Heatmap_Dashboard::HEATMAP_BACKFILL_CRON_HOOK (daily + one-off continuations).
			'opti_behavior_heatmap_orphan_purge_daily',      // Opti_Behavior_Heatmap_Dashboard::HEATMAP_ORPHAN_PURGE_CRON_HOOK (daily + one-off continuations).
			'opti_behavior_finalize_stale_sessions',         // Opti_Behavior_Heatmap_Database::FINALIZE_STALE_CRON_HOOK (hourly + one-off continuations).
			'opti_behavior_ab_aggregate_daily',              // A/B Testing daily aggregation.
			'opti_behavior_ab_auto_winner_check',            // A/B Testing hourly auto-winner check.
			'opti_behavior_ab_cleanup',                      // A/B Testing daily cleanup.
			'opti_behavior_db_size_cap_run',             // 'opti_behavior_db_size_cap_run' (one-off, run-now + continuation).
			'opti_behavior_db_schema_migration_run',     // 'opti_behavior_db_schema_migration_run' (one-off).
			'opti_behavior_daily_heartbeat',                 // Free tracker daily heartbeat.
			// --- One-off events ---------------------------------------------------
			'opti_behavior_debug_auto_disable',              // Opti_Behavior_Heatmap_Debug_Manager::AUTO_DISABLE_CRON_HOOK.
			'opti_behavior_smart_insights_scheduler_batch',  // Opti_Behavior_Smart_Insights_Scheduler::BATCH_HOOK.
			'opti_behavior_heatmap_agg_sync',                // Aggregate-index sync worker.
			'opti_behavior_heatmap_daily_sync',              // Daily-index sync worker.
			'opti_behavior_heatmap_stats_warm',              // Opti_Behavior_Heatmap_Dashboard::HEATMAP_STATS_WARM_CRON_HOOK (scheduled WITH args).
			'opti_behavior_heatmap_registry_rebuild_files',  // Opti_Behavior_Heatmap_Dashboard::HEATMAP_REBUILD_CRON_HOOK.
			'opti_behavior_heatmap_orphan_report_scan',      // Opti_Behavior_Heatmap_Dashboard::HEATMAP_ORPHAN_REPORT_CRON_HOOK.
			'opti_behavior_canonlist_refresh',               // Opti_Behavior_Heatmap_Dashboard::CANONLIST_REFRESH_CRON_HOOK (scheduled WITH args).
			'opti_behavior_heatmap_canon_sync',              // Opti_Behavior_Heatmap_Dashboard::CANONICAL_SESSIONS_SYNC_CRON_HOOK.
			'opti_behavior_reclassify_spam_batch',           // Opti_Behavior_Heatmap_Database::RECLASSIFY_SPAM_CRON_HOOK.
			'opti_behavior_heavy_migrations',                // Opti_Behavior_Heatmap_Database::HEAVY_MIGRATIONS_CRON_HOOK.
			'opti_behavior_dimension_backfill',              // Opti_Behavior_Dimension_Aggregates::BACKFILL_HOOK.
			'opti_behavior_deep_integrity_check',            // Opti_Behavior_Heatmap_Data_Protection::DEEP_CHECK_CRON_HOOK.
			'opti_behavior_files_tier_continue',             // Heavy-files tier continuation (run_heavy_files_tier()).
			'opti_behavior_recording_fallback_continue',     // Recording-files fallback continuation (run_recording_files_fallback()).
			'opti_behavior_spam_tier_run',                   // Spam/bot tier run-now + continuation (run_daily_spam_tier()).
			'opti_behavior_engagement_counters_tick',        // Opti_Behavior_Heatmap_Engagement_Counters::TICK_HOOK (backfill + purge).
			'opti_behavior_page_type_prune_run',             // 1.9.5 archive-page prune run-now + continuation.
			'opti_behavior_page_type_prune_restore',         // 1.9.5 archive-page prune restore pass.
			'opti_behavior_page_type_prune_purge',           // 1.9.5 archive-page prune purge pass.
			'opti_behavior_heatmap_migration_batch',         // Heatmap file-storage migration batch worker.
			'opti_behavior_recording_orphan_sweep',          // Orphaned recording-file sweep (scheduled by Pro, cleared by Free).
		);

		/**
		 * Filter the list of cron hooks cleared on plugin deactivation.
		 *
		 * Allows the Pro plugin or third parties to append their own hooks so
		 * they are unscheduled together with the free plugin's events.
		 *
		 * @since 1.9.x
		 * @param string[] $hooks Cron hook names.
		 */
		return apply_filters( 'opti_behavior_cron_hooks', $hooks );
	}

	/**
	 * Static deactivation hook
	 *
	 * Clears every WP-Cron event the plugin ever schedules (recurring and
	 * one-off). wp_unschedule_hook() (WP 4.9+) removes ALL events for a hook
	 * regardless of args — required for args-keyed hooks such as
	 * `opti_behavior_heatmap_stats_warm` and `opti_behavior_canonlist_refresh`,
	 * which wp_clear_scheduled_hook() without args would miss.
	 */
	public static function deactivation() {
		foreach ( self::get_all_cron_hooks() as $hook ) {
			if ( function_exists( 'wp_unschedule_hook' ) ) {
				wp_unschedule_hook( $hook );
			} else {
				wp_clear_scheduled_hook( $hook );
			}
		}
	}

	/**
	 * Cron callback for scheduled smart cleanup.
	 *
	 * @since 1.0.9
	 */
	public function run_scheduled_smart_cleanup() {
		$this->maybe_migrate_legacy_default_auto_cleanup_settings();

		$settings = get_option( 'opti_behavior_auto_cleanup_settings', array() );

		if ( empty( $settings['enabled'] ) ) {
			Opti_Behavior_Cleanup_Task_Registry::report_run(
				array(
					'status' => 'skipped',
					'note'   => __( 'Automatic cleanup is turned off — nothing was deleted.', 'opti-behavior' ),
				)
			);
			return;
		}

		$frequency = isset( $settings['frequency'] ) && in_array( $settings['frequency'], array( 'daily', 'weekly', 'monthly' ), true ) ? $settings['frequency'] : 'daily';
		$last_run  = isset( $settings['last_run'] ) ? intval( $settings['last_run'] ) : 0;
		$intervals = array(
			'daily'   => DAY_IN_SECONDS,
			'weekly'  => 7 * DAY_IN_SECONDS,
			'monthly' => 30 * DAY_IN_SECONDS,
		);
		// One hour of grace: the daily event fires ~24h after the previous
		// run finished, which a strict comparison skipped every other day.
		if ( $last_run > 0 && ( time() - $last_run ) < ( $intervals[ $frequency ] - HOUR_IN_SECONDS ) ) {
			Opti_Behavior_Cleanup_Task_Registry::report_run(
				array(
					'status' => 'skipped',
					'note'   => __( 'Not due yet for the configured frequency — nothing was deleted.', 'opti-behavior' ),
				)
			);
			return;
		}

		// Cron requests are not admin requests, so the dashboard/maintenance
		// trait is normally not instantiated. Run the service directly so
		// enabled Scheduled Auto-Cleanup settings execute from WordPress cron.
		if ( ! class_exists( 'Opti_Behavior_Smart_Cleanup_Service' ) ) {
			$service_path = OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-smart-cleanup-service.php';
			if ( file_exists( $service_path ) ) {
				require_once $service_path;
			}
		}

		if ( class_exists( 'Opti_Behavior_Smart_Cleanup_Service' ) ) {
			$service = new Opti_Behavior_Smart_Cleanup_Service();
			$service->run_scheduled_cleanup();
		} elseif ( $this->dashboard ) {
			// Defensive fallback for unusual legacy load orders.
			$this->dashboard->run_scheduled_cleanup();
		}
	}
}
