<?php
/**
 * Tracker Heartbeat — silent-kill detection for frontend tracking scripts.
 *
 * A page-optimizer/cache plugin (WP Rocket Combine JS, Autoptimize, etc.) can
 * merge or otherwise mangle a tracking script's source so it never executes,
 * with ZERO console errors and zero network requests — a completely silent
 * data-loss failure (see report.md Bug #3: Combine JS killed funnel-tracker.js
 * and ab-test-tracker.js while the site kept receiving normal page-view
 * traffic).
 *
 * This class detects that condition WITHOUT adding any new frontend code:
 * for each registered tracking module it compares "the module is enabled and
 * the site had traffic in the last 24h" (existing `optibehavior_pageviews`
 * table, populated by the always-on heatmap/page-view beacon) against "the
 * module's own event table received at least one row in the last 24h" using
 * data that already exists for every module (funnel/AB/heatmap tables here,
 * Pro's recording/errors/form-interaction tables via the same registry
 * pattern used by `opti_behavior_protected_scripts`).
 *
 * Extension point: `opti_behavior_tracker_modules` filter — Pro (or any
 * add-on) registers its own modules the same way it registers protected
 * scripts with Opti_Behavior_Optimizer_Compat.
 *
 * Constraints honored:
 * - No PII: only COUNT(*) aggregates and static module labels are ever read.
 * - Negligible overhead: at most one lightweight COUNT query per module, and
 *   only once per CHECK_INTERVAL (cached in a transient) — never per request.
 * - No notice spam: transient result cache + a dismiss action with a 7-day
 *   backoff (mirrors Opti_Behavior_Review_Banner's remind/hide pattern).
 * - Zero-traffic sites never alert: the whole check short-circuits when the
 *   site received no page views in the window, so a quiet site can't produce
 *   a false "tracker is dead" positive.
 *
 * @package opti-behavior
 * @since   1.7.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lightweight COUNT(*) aggregates only, cached in a transient; all table names are constructed from $wpdb->prefix and hardcoded identifiers, no user input.
class Opti_Behavior_Tracker_Heartbeat {

	/**
	 * Singleton instance.
	 *
	 * @since 1.7.1
	 * @var Opti_Behavior_Tracker_Heartbeat|null
	 */
	private static $instance = null;

	/**
	 * Double-registration guard.
	 *
	 * @since 1.7.1
	 * @var bool
	 */
	private static $booted = false;

	/** Transient key caching the last computed mismatch list. */
	const TRANSIENT_RESULT = 'opti_behavior_heartbeat_result';

	/** How often the (cheap but non-free) DB comparison is recomputed. */
	const CHECK_INTERVAL = 6 * HOUR_IN_SECONDS;

	/** Lookback window used for both the traffic and the per-module counts. */
	const WINDOW_SECONDS = DAY_IN_SECONDS;

	/** Option storing the "don't show again until" unix timestamp. */
	const OPTION_DISMISSED_UNTIL = 'opti_behavior_heartbeat_dismissed_until';

	/** How long a dismissal silences the notice for. */
	const DISMISS_BACKOFF = 7 * DAY_IN_SECONDS;

	/** admin-post action name used by the dismiss link. */
	const ACTION_NAME = 'opti_behavior_heartbeat_dismiss';

	/** Nonce action name for the dismiss link. */
	const NONCE_ACTION = 'opti_behavior_heartbeat_dismiss';

	/**
	 * Documentation link shown in the notice.
	 *
	 * Points at the live support page (verified HTTP 200 on 2026-07-09; the
	 * originally planned /docs/tracking-blocked-by-cache-plugin/ slug does
	 * not exist yet). Swap to the dedicated docs article once published.
	 */
	const DOC_URL = 'https://optiuser.com/support/';

	/**
	 * Per-request cache of the resolved module registry.
	 *
	 * @since 1.7.1
	 * @var array|null
	 */
	private $modules = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since 1.7.1
	 * @return Opti_Behavior_Tracker_Heartbeat
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bootstrap once. Idempotent.
	 *
	 * @since 1.7.1
	 * @return void
	 */
	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		self::instance()->register_hooks();
	}

	/**
	 * Private constructor — use init()/instance().
	 *
	 * @since 1.7.1
	 */
	private function __construct() {}

	/**
	 * Register admin-only hooks. No frontend hooks are added at all.
	 *
	 * @since 1.7.1
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION_NAME, array( $this, 'handle_dismiss' ) );
	}

	// -------------------------------------------------------------------------
	// Module registry
	// -------------------------------------------------------------------------

	/**
	 * Resolve the module registry (lazy, cached per request).
	 *
	 * Merges Free's built-in modules (funnel, A/B testing, heatmap) through
	 * the `opti_behavior_tracker_modules` filter so Pro can contribute its own
	 * (session recording, errors tracking, form analytics) — same extension
	 * pattern as `opti_behavior_protected_scripts`.
	 *
	 * Each module is:
	 * array {
	 *     @type string   $key         Unique module key (for logging/testing).
	 *     @type string   $label       Human-readable label shown in the notice.
	 *     @type callable $is_enabled  bool fn(): whether the module is currently
	 *                                 active (and entitled, for Pro modules) —
	 *                                 disabled modules are never flagged.
	 *     @type callable $event_count int fn( string $since_mysql_datetime ):
	 *                                 COUNT(*) of the module's own events since
	 *                                 the given cutoff.
	 * }
	 *
	 * @since 1.7.1
	 * @return array[]
	 */
	private function get_modules() {
		if ( null !== $this->modules ) {
			return $this->modules;
		}

		$defaults = $this->default_modules();

		/**
		 * Filter the registry of tracker modules checked for silent failures.
		 *
		 * @since 1.7.1
		 * @param array[] $defaults Free's built-in module descriptors.
		 */
		$registry = apply_filters( 'opti_behavior_tracker_modules', $defaults );

		$this->modules = $this->normalize_modules( $registry );

		return $this->modules;
	}

	/**
	 * Free's built-in module descriptors.
	 *
	 * @since 1.7.1
	 * @return array[]
	 */
	private function default_modules() {
		return array(
			array(
				'key'         => 'funnel',
				'label'       => __( 'Funnel Tracking', 'opti-behavior' ),
				'is_enabled'  => array( $this, 'funnel_enabled' ),
				'event_count' => array( $this, 'funnel_event_count' ),
			),
			array(
				'key'         => 'ab_test',
				'label'       => __( 'A/B Testing', 'opti-behavior' ),
				'is_enabled'  => array( $this, 'ab_test_enabled' ),
				'event_count' => array( $this, 'ab_test_event_count' ),
			),
			array(
				'key'         => 'heatmap',
				'label'       => __( 'Heatmap Tracking', 'opti-behavior' ),
				'is_enabled'  => '__return_true',
				'event_count' => array( $this, 'heatmap_event_count' ),
			),
		);
	}

	/**
	 * Validate/normalize a (possibly filter-mangled) module registry.
	 *
	 * @since 1.7.1
	 * @param mixed $registry Raw registry from the filter.
	 * @return array[] Only well-formed module descriptors.
	 */
	private function normalize_modules( $registry ) {
		if ( ! is_array( $registry ) ) {
			return array();
		}

		$valid = array();
		foreach ( $registry as $module ) {
			if ( ! is_array( $module ) ) {
				continue;
			}
			if ( empty( $module['key'] ) || empty( $module['label'] ) ) {
				continue;
			}
			if ( ! isset( $module['is_enabled'] ) || ! is_callable( $module['is_enabled'] ) ) {
				continue;
			}
			if ( ! isset( $module['event_count'] ) || ! is_callable( $module['event_count'] ) ) {
				continue;
			}
			$valid[] = $module;
		}

		return $valid;
	}

	// -------------------------------------------------------------------------
	// Free module callbacks
	// -------------------------------------------------------------------------

	/**
	 * Whether at least one Funnel is Active.
	 *
	 * @since 1.7.1
	 * @return bool
	 */
	public function funnel_enabled() {
		global $wpdb;
		$table = $wpdb->prefix . 'opti_behavior_funnels';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Hard-coded plugin table name from $wpdb->prefix; no dynamic values.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" ) > 0;
	}

	/**
	 * Count funnel-tracking rows (entries or step progressions) since cutoff.
	 *
	 * @since 1.7.1
	 * @param string $since MySQL datetime cutoff.
	 * @return int
	 */
	public function funnel_event_count( $since ) {
		global $wpdb;
		$table = $wpdb->prefix . 'opti_behavior_funnel_tracking';
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Hard-coded plugin table name from $wpdb->prefix; values bound via prepare().
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE entry_time >= %s OR last_activity >= %s", $since, $since )
		);
	}

	/**
	 * Whether at least one A/B test is Running.
	 *
	 * @since 1.7.1
	 * @return bool
	 */
	public function ab_test_enabled() {
		if ( class_exists( 'Opti_Behavior_AB_Test_Database' ) ) {
			return Opti_Behavior_AB_Test_Database::get_running_tests_count() > 0;
		}
		return false;
	}

	/**
	 * Count A/B impressions (visitor exposures) since cutoff.
	 *
	 * @since 1.7.1
	 * @param string $since MySQL datetime cutoff.
	 * @return int
	 */
	public function ab_test_event_count( $since ) {
		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_ab_impressions';
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Hard-coded plugin table name from $wpdb->prefix; value bound via prepare().
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since )
		);
	}

	/**
	 * Heatmap tracking has no dedicated on/off toggle in Free — it is a core
	 * always-on feature, so it is always considered "enabled".
	 *
	 * @since 1.7.1
	 * @return bool
	 */
	public function heatmap_enabled() {
		return true;
	}

	/**
	 * Count heatmap click/interaction events since cutoff.
	 *
	 * @since 1.7.1
	 * @param string $since MySQL datetime cutoff.
	 * @return int
	 */
	public function heatmap_event_count( $since ) {
		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_events';
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Hard-coded plugin table name from $wpdb->prefix; value bound via prepare().
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE insert_at >= %s", $since )
		);
	}

	// -------------------------------------------------------------------------
	// Traffic denominator
	// -------------------------------------------------------------------------

	/**
	 * Count page views since cutoff — the "did the site have traffic at all"
	 * denominator shared by every module, so a quiet site never false-positives.
	 *
	 * @since 1.7.1
	 * @param string $since MySQL datetime cutoff.
	 * @return int
	 */
	public static function traffic_count( $since ) {
		global $wpdb;
		$table = $wpdb->prefix . 'optibehavior_pageviews';
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Hard-coded plugin table name from $wpdb->prefix; value bound via prepare().
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE view_time >= %s", $since )
		);
	}

	// -------------------------------------------------------------------------
	// Evaluation
	// -------------------------------------------------------------------------

	/**
	 * Run the comparison for every enabled module using the real (wall-clock)
	 * lookback window.
	 *
	 * @since 1.7.1
	 * @return string[] Labels of modules that look silently dead, or empty.
	 */
	public function evaluate() {
		$since_ts = current_time( 'timestamp' ) - self::WINDOW_SECONDS; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- window arithmetic against site-local `current_time( 'mysql' )` columns, not a nonce/cache-buster.
		$since    = gmdate( 'Y-m-d H:i:s', $since_ts );

		return $this->evaluate_since( $since );
	}

	/**
	 * Run the comparison for every enabled module against an injected cutoff.
	 *
	 * Split out of evaluate() solely so tests can supply a deterministic
	 * cutoff (e.g. a near-future timestamp to simulate "zero events since
	 * X") without depending on real wall-clock traffic. Production code
	 * always goes through evaluate().
	 *
	 * @since 1.7.1
	 * @param string $since MySQL datetime cutoff.
	 * @return string[] Labels of modules that look silently dead, or empty.
	 */
	public function evaluate_since( $since ) {
		// No traffic at all in the window: never alert (can't distinguish
		// "tracker dead" from "nobody visited"), per plan requirement.
		if ( self::traffic_count( $since ) <= 0 ) {
			return array();
		}

		$mismatches = array();
		foreach ( $this->get_modules() as $module ) {
			if ( ! call_user_func( $module['is_enabled'] ) ) {
				continue;
			}
			$count = (int) call_user_func( $module['event_count'], $since );
			if ( $count <= 0 ) {
				$mismatches[] = $module['label'];
			}
		}

		return $mismatches;
	}

	/**
	 * Evaluate with a transient cache so the DB comparison runs at most once
	 * per CHECK_INTERVAL regardless of admin traffic.
	 *
	 * @since 1.7.1
	 * @return string[]
	 */
	private function get_cached_mismatches() {
		$cached = get_transient( self::TRANSIENT_RESULT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$mismatches = $this->evaluate();
		set_transient( self::TRANSIENT_RESULT, $mismatches, self::CHECK_INTERVAL );

		return $mismatches;
	}

	// -------------------------------------------------------------------------
	// Culprit optimizer detection (best-effort — for notice copy only, never
	// affects whether the notice fires).
	// -------------------------------------------------------------------------

	/**
	 * Detect the most likely active optimizer/cache plugin, for notice copy.
	 *
	 * Kept for backwards compatibility: returns only the FIRST detected
	 * plugin (or '' when none). New callers should prefer
	 * detect_culprit_plugins(), which returns every active optimizer so the
	 * notice can name all of them on multi-optimizer sites.
	 *
	 * @since 1.7.1
	 * @return string Human-readable plugin name, or '' if none detected.
	 */
	public function detect_culprit_plugin() {
		$culprits = $this->detect_culprit_plugins();
		return $culprits ? $culprits[0] : '';
	}

	/**
	 * Detect ALL active optimizer/cache plugins, for notice copy.
	 *
	 * Dual detection per plugin, either signal suffices:
	 *  1. Runtime marker (version constant / main class) — WP Rocket
	 *     (`WP_ROCKET_VERSION`) and LiteSpeed Cache (`LSCWP_V`) verified
	 *     against plugin sources in this install; the rest are each plugin's
	 *     well-known stable marker. Plugins with no stable public runtime
	 *     marker rely on basename detection alone.
	 *  2. Plugin basename present in the `active_plugins` option (plus
	 *     network-activated plugins on multisite) — canonical WordPress.org
	 *     directory slugs, stable public identifiers that do not require
	 *     source verification. This makes detection robust even if a plugin
	 *     renames an internal constant/class.
	 * On a false/missed detection the notice simply falls back to a generic
	 * "a caching or optimization plugin" phrase — detection is cosmetic, not
	 * load-bearing.
	 *
	 * @since 1.7.1
	 * @return string[] Human-readable plugin names, empty array if none detected.
	 */
	public function detect_culprit_plugins() {
		// label => array( marker-callback, plugin basename(s) — string or array ).
		$checks = array(
			'WP Rocket'            => array(
				static function () {
					return defined( 'WP_ROCKET_VERSION' );
				},
				'wp-rocket/wp-rocket.php',
			),
			'LiteSpeed Cache'      => array(
				static function () {
					return defined( 'LSCWP_V' );
				},
				'litespeed-cache/litespeed-cache.php',
			),
			'Autoptimize'          => array(
				static function () {
					return class_exists( 'autoptimizeMain' );
				},
				'autoptimize/autoptimize.php',
			),
			'SiteGround Optimizer' => array(
				static function () {
					return class_exists( 'SiteGround_Optimizer\Loader\Loader' );
				},
				'sg-cachepress/sg-cachepress.php',
			),
			'Perfmatters'          => array(
				static function () {
					return defined( 'PERFMATTERS_VERSION' );
				},
				'perfmatters/perfmatters.php',
			),
			'WP-Optimize'          => array(
				static function () {
					return class_exists( 'WP_Optimize' );
				},
				'wp-optimize/wp-optimize.php',
			),
			'Jetpack Boost'        => array(
				static function () {
					return class_exists( 'Automattic\Jetpack_Boost\Jetpack_Boost' );
				},
				'jetpack-boost/jetpack-boost.php',
			),
			'W3 Total Cache'       => array(
				static function () {
					return defined( 'W3TC_VERSION' );
				},
				'w3-total-cache/w3-total-cache.php',
			),
			'Breeze'               => array(
				static function () {
					return defined( 'BREEZE_VERSION' );
				},
				'breeze/breeze.php',
			),
			'FlyingPress'          => array(
				static function () {
					return defined( 'FLYING_PRESS_VERSION' );
				},
				'flying-press/flying-press.php',
			),
			'WP Fastest Cache'     => array(
				static function () {
					return class_exists( 'WpFastestCache' );
				},
				'wp-fastest-cache/wpFastestCache.php',
			),
			'WP Super Cache'       => array(
				static function () {
					return defined( 'WPCACHEHOME' );
				},
				'wp-super-cache/wp-cache.php',
			),
			'Cache Enabler'        => array(
				static function () {
					return class_exists( 'Cache_Enabler' );
				},
				'cache-enabler/cache-enabler.php',
			),
			'Hummingbird'          => array(
				static function () {
					return defined( 'WPHB_VERSION' );
				},
				// Free (wp.org) and Pro (WPMU DEV) folder names.
				array( 'hummingbird-performance/wp-hummingbird.php', 'wp-hummingbird/wp-hummingbird.php' ),
			),
			'Swift Performance'    => array(
				static function () {
					return class_exists( 'Swift_Performance' ) || class_exists( 'Swift_Performance_Lite' );
				},
				// Lite (wp.org) and Pro folder names.
				array( 'swift-performance-lite/performance.php', 'swift-performance/performance.php' ),
			),
			'NitroPack'            => array(
				static function () {
					return defined( 'NITROPACK_VERSION' );
				},
				'nitropack/main.php',
			),
			'Asset CleanUp'        => array(
				static function () {
					return defined( 'WPACU_PLUGIN_VERSION' );
				},
				'wp-asset-clean-up/wpacu.php',
			),
			'Speed Booster Pack'   => array(
				static function () {
					return defined( 'SBP_VERSION' );
				},
				'speed-booster-pack/speed-booster-pack.php',
			),
			// No stable public runtime marker documented for the two below —
			// basename detection alone (dual-detection degrades gracefully).
			'Flying Scripts'       => array(
				static function () {
					return false;
				},
				'flying-scripts/flying-scripts.php',
			),
			'WP Meteor'            => array(
				static function () {
					return false;
				},
				'wp-meteor/wp-meteor.php',
			),
		);

		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active_plugins = array_merge( $active_plugins, array_keys( $network_active ) );
		}

		$detected = array();
		foreach ( $checks as $label => $check ) {
			$basenames = is_array( $check[1] ) ? $check[1] : array( $check[1] );
			if ( call_user_func( $check[0] ) || array_intersect( $basenames, $active_plugins ) ) {
				$detected[] = $label;
			}
		}

		return $detected;
	}

	// -------------------------------------------------------------------------
	// Admin notice
	// -------------------------------------------------------------------------

	/**
	 * Whether the notice is currently suppressed by a prior dismissal.
	 *
	 * Factored out (rather than inlined in render_notice()) so the backoff
	 * behaviour is directly unit-testable without needing an admin request.
	 *
	 * @since 1.7.1
	 * @return bool
	 */
	public static function is_dismissed() {
		return (int) get_option( self::OPTION_DISMISSED_UNTIL, 0 ) > time();
	}

	/**
	 * Render the dismissible admin notice when a mismatch is detected.
	 *
	 * @since 1.7.1
	 * @return void
	 */
	public function render_notice() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::is_dismissed() ) {
			return;
		}

		$mismatches = $this->get_cached_mismatches();
		if ( empty( $mismatches ) ) {
			return;
		}

		$culprits    = $this->detect_culprit_plugins();
		$module_list = implode( ', ', array_map( 'sanitize_text_field', $mismatches ) );

		$dismiss_url = wp_nonce_url(
			add_query_arg(
				array( 'action' => self::ACTION_NAME ),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION
		);
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Opti-Behavior: tracking may be silently blocked', 'opti-behavior' ); ?></strong>
			</p>
			<p>
				<?php
				if ( count( $culprits ) > 1 ) {
					printf(
						/* translators: 1: comma-separated list of module labels, 2: comma-separated list of detected optimizer/cache plugin names. */
						esc_html__( 'Your site received visitors in the last 24 hours, but %1$s recorded zero events in that time. This usually means one of your caching or optimization plugins (%2$s) is stripping or merging the tracking script before it can run — with no visible error.', 'opti-behavior' ),
						'<strong>' . esc_html( $module_list ) . '</strong>',
						'<strong>' . esc_html( implode( ', ', $culprits ) ) . '</strong>'
					);
				} else {
					$culprit = $culprits ? $culprits[0] : __( 'a caching or optimization plugin', 'opti-behavior' );
					printf(
						/* translators: 1: comma-separated list of module labels, 2: likely optimizer/cache plugin name. */
						esc_html__( 'Your site received visitors in the last 24 hours, but %1$s recorded zero events in that time. This usually means %2$s is stripping or merging the tracking script before it can run — with no visible error.', 'opti-behavior' ),
						'<strong>' . esc_html( $module_list ) . '</strong>',
						'<strong>' . esc_html( $culprit ) . '</strong>'
					);
				}
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( self::DOC_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Learn how to fix this', 'opti-behavior' ); ?>
				</a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url( $dismiss_url ); ?>">
					<?php esc_html_e( 'Dismiss for 7 days', 'opti-behavior' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the "Dismiss for 7 days" admin-post action.
	 *
	 * @since 1.7.1
	 * @return void
	 */
	public function handle_dismiss() {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'opti-behavior' ),
				esc_html__( 'Unauthorized', 'opti-behavior' ),
				array( 'response' => 403 )
			);
		}

		update_option( self::OPTION_DISMISSED_UNTIL, (string) ( time() + self::DISMISS_BACKOFF ), false );

		$redirect_url = wp_get_referer();
		if ( ! $redirect_url ) {
			$redirect_url = admin_url();
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
