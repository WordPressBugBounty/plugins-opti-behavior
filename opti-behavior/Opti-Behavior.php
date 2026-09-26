<?php
/**
 * Plugin Name: Opti-Behavior – Self-Hosted Heatmaps, Session Recordings, Funnels, A/B Testing & Smart Insights
 * Plugin URI:  https://optiuser.com/
 * Description: Self-hosted heatmaps, funnels, A/B WooCommerce testing, behavior analytics & Smart Insights for WordPress. Own your data and optimize what users do.
 * Version:     1.9.2.1
 * Author:      OptiUser
 * Author URI:  https://optiuser.com/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: opti-behavior
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package opti-behavior
 * @copyright 2025-2026 OptiUser
 * @version 1.8.2.11
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check PHP version compatibility.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', 'opti_behavior_php_version_notice' );
	/**
	 * Display admin notice for incompatible PHP version.
	 *
	 * Shows an error message when the server is running an incompatible PHP version
	 * and automatically deactivates the plugin.
	 *
	 * @since 1.0.4
	 */
	function opti_behavior_php_version_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: 1: Required PHP version, 2: Current PHP version */
					esc_html__( 'Opti-Behavior requires PHP version %1$s or higher. You are running PHP version %2$s. Please upgrade PHP to activate this plugin.', 'opti-behavior' ),
					'7.4',
					esc_html( PHP_VERSION )
				);
				?>
			</p>
		</div>
		<?php
		// Deactivate the plugin.
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	}
	return;
}

if ( ! function_exists( 'opti_behavior_heatmap_conflict' ) ) {
	/**
	 * Display error message when plugin conflict is detected.
	 *
	 * Terminates execution and displays a user-friendly error message
	 * when another version of the plugin is already active.
	 *
	 * @since 1.0.0
	 */
	function opti_behavior_heatmap_conflict() {
		die( esc_html__( 'Fail to activate. Another version of opti-behavior Heatmap is already active.', 'opti-behavior' ) );
	}
}

// Check for plugin conflicts.
if ( defined( 'OPTI_BEHAVIOR_HEATMAP' ) ) {
	if ( is_admin() ) {
		register_activation_hook( __FILE__, 'opti_behavior_heatmap_conflict' );
	}
	return;
} else {
	define( 'OPTI_BEHAVIOR_HEATMAP', __FILE__ );
}

// Define plugin constants.
define( 'OPTI_BEHAVIOR_HEATMAP_VERSION', '1.9.2.1' );
define( 'OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPTI_BEHAVIOR_HEATMAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR', OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'includes/' );
define( 'OPTI_BEHAVIOR_HEATMAP_ADMIN_DIR', OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'admin/' );
define( 'OPTI_BEHAVIOR_HEATMAP_PUBLIC_DIR', OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'public/' );
define( 'OPTI_BEHAVIOR_HEATMAP_ASSETS_URL', OPTI_BEHAVIOR_HEATMAP_PLUGIN_URL . 'assets/' );

/*
 * Capability flag: this Free hosts the Pro "Page X-Ray" tab of Smart Insights
 * (tab bar, period form, locked view; filter
 * `opti_behavior_smart_insights_page_xray_available`, action
 * `opti_behavior_smart_insights_render_page_xray`). Pro hooks the tab only when
 * it is defined. Never compare versions for this.
 */
define( 'OPTI_BEHAVIOR_SI_XRAY_HOST', 1 );

/**
 * Whether the Pro "Page X-Ray" tab can be opened on this request (Pro active,
 * licence gate open, a Pro that ships the tab). Never throws.
 *
 * @return bool
 */
function opti_behavior_page_xray_available() {
	try {
		return (bool) apply_filters( 'opti_behavior_smart_insights_page_xray_available', false );
	} catch ( Throwable $e ) {
		return false;
	}
}

/**
 * API Environment Configuration
 * Set the environment for API connections:
 * - 'local'  => Uses http://localhost/API/ for local development
 * - 'live'   => Uses https://api.optiuser.com/ for production (default)
 *
 * Change this value to switch between local and live API servers.
 */
if ( ! defined( 'OPTI_BEHAVIOR_ENVIRONMENT' ) ) {
	define( 'OPTI_BEHAVIOR_ENVIRONMENT', 'live' ); // Options: 'local' or 'live'
}

// Initialize autoloader.
require_once __DIR__ . '/includes/class-autoloader.php';
Opti_Behavior_Heatmap_Autoloader::init( __DIR__ );

// Self-heal stale lowercase main-file basename entries in active_plugins
// (a build once shipped opti-behavior.php lowercase) and an orphaned
// scheduled-reports cron event. Runs on plugins_loaded priority 1.
require_once __DIR__ . '/includes/class-opti-behavior-basename-migration.php';
Opti_Behavior_Basename_Migration::init();

// Load tooltip helper functions.
require_once __DIR__ . '/includes/tooltip-helper.php';

// Load dashboard cache helpers (TTL + invalidation).
require_once __DIR__ . '/includes/opti-behavior-dashboard-cache.php';

// Load shared admin contact resolver early for welcome/trial and Pro registration flows.
require_once __DIR__ . '/includes/class-opti-behavior-admin-email-resolver.php';

// Load welcome/consent class early so its activation hook fires on plugin activation.
require_once __DIR__ . '/includes/class-opti-behavior-welcome.php';

// Load onboarding popup class (shown on dashboard after first activation with no data).
require_once __DIR__ . '/includes/class-opti-behavior-onboarding.php';

// Load delayed WordPress.org review reminder banner.
require_once __DIR__ . '/includes/class-opti-behavior-review-banner.php';

// Load the Pro endpoint graceful-degradation shim. Serves cached/CDN visitors
// running stale Pro tracker JS a well-formed AJAX success for any Pro-only save
// action that has no live handler, so they stop flooding admin-ajax with 400
// retries. Registered on `init` priority 999 — after Pro binds its real
// handlers on `init` priority 11 — so the per-action has_action() probe is
// authoritative (covers Pro off AND Pro active-but-unlicensed).
require_once __DIR__ . '/includes/class-opti-behavior-pro-shim.php';
add_action( 'init', array( 'Opti_Behavior_Pro_Ajax_Shim', 'maybe_register' ), 999 );

// Load Filter Profiles: FREE-owned site-wide storage + CRUD AJAX for saved
// advanced-filter sets, shared across every FREE + PRO filter surface. The class
// name falls outside the autoloader's prefix map, so require it explicitly.
require_once __DIR__ . '/includes/class-opti-behavior-filter-profiles.php';
add_action( 'init', array( 'Opti_Behavior_Filter_Profiles', 'init' ) );

// Register plugin hooks.
register_activation_hook( __FILE__, 'Opti_Behavior_Heatmap_Core::activation' );
register_activation_hook( __FILE__, array( 'Opti_Behavior_Welcome', 'on_activation' ) );
register_activation_hook( __FILE__, array( 'Opti_Behavior_Review_Banner', 'on_activation' ) );
// Seed the FREE-owned filter-profiles option on activation (no-op if present).
register_activation_hook( __FILE__, array( 'Opti_Behavior_Filter_Profiles', 'seed' ) );
register_deactivation_hook( __FILE__, 'Opti_Behavior_Heatmap_Core::deactivation' );

if ( ! function_exists( 'opti_behavior_purge_all_page_caches' ) ) {
	/**
	 * Purge all known full-page caches domain-wide.
	 *
	 * Cached HTML embeds this plugin's tracker bootstrap (script tags, nonces,
	 * inline config). Activating or deactivating either plugin changes what the
	 * page HTML must contain, so stale cached copies would keep serving trackers
	 * that no longer exist (404 JS, dead AJAX endpoints) or omit ones that should
	 * run. Best-effort: every branch is guarded and the whole call never throws.
	 *
	 * @since 1.7.1
	 */
	function opti_behavior_purge_all_page_caches() {
		try {
			// WP Rocket.
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
			// LiteSpeed Cache.
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook owned by LiteSpeed Cache (its documented purge API); not a hook defined by this plugin.
			// W3 Total Cache.
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
			}
			// WP Super Cache.
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
			}
			// WP Fastest Cache.
			if ( function_exists( 'wpfc_clear_all_cache' ) ) {
				wpfc_clear_all_cache( true );
			}
			// Cache Enabler.
			do_action( 'cache_enabler_clear_complete_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook owned by Cache Enabler (its documented purge API); not a hook defined by this plugin.
			// Hummingbird.
			do_action( 'wphb_clear_page_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party hook owned by Hummingbird (its documented purge API); not a hook defined by this plugin.
			// SiteGround Optimizer.
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache();
			}
			// Autoptimize.
			if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
				autoptimizeCache::clearall();
			}
		} catch ( \Throwable $e ) {
			// Never block (de)activation on a cache-purge failure.
			unset( $e );
		}
	}
}
// Stale cached HTML must never outlive an activation state change (see helper docblock).
register_activation_hook( __FILE__, 'opti_behavior_purge_all_page_caches' );
register_deactivation_hook( __FILE__, 'opti_behavior_purge_all_page_caches' );

/**
 * Whitelist of admin-language locales supported by the private
 * `opti_behavior_admin_language` option.
 *
 * @since 1.8.2
 */
function opti_behavior_admin_language_whitelist() {
	return array( 'en_US', 'fr_FR', 'de_DE', 'es_ES', 'pt_BR', 'it_IT' );
}

/**
 * Resolve the user-forced admin UI locale for Opti-Behavior screens.
 *
 * An empty string means "follow the WordPress site locale", which is the
 * behaviour WordPress.org expects: a French site must display the plugin in
 * French with no extra configuration.
 *
 * @since 1.8.1.5
 * @return string Whitelisted locale, or '' to follow WordPress.
 */
function opti_behavior_forced_admin_locale() {
	$locale = (string) get_option( 'opti_behavior_admin_language', '' );

	if ( '' === $locale ) {
		return '';
	}

	return in_array( $locale, opti_behavior_admin_language_whitelist(), true ) ? $locale : '';
}

/**
 * Whether the current request is an Opti-Behavior admin screen or AJAX call.
 *
 * Ported from the legacy admin-locale filter so the same page-scoping
 * logic is preserved: the plugin's own admin page slugs
 * (plus PRO page slugs when PRO is active), its AJAX action prefixes, and
 * the plugins.php screen.
 *
 * @since 1.8.1.5
 * @return bool
 */
function opti_behavior_is_plugin_admin_context() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameter used for page identification (read-only operation)
	$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	global $pagenow;
	$is_plugins_screen = ( isset( $pagenow ) && 'plugins.php' === $pagenow );

	$opti_behavior_pages = array(
		'opti-behavior-analytics',
		'opti-behavior-heatmaps',
		'opti-behavior-heatmap-detail',
		'opti-behavior-settings',
		'opti-behavior-ai-insights',
		'opti-behavior-funnels',
		'opti-behavior-ab-testing',
		'opti-behavior-smart-insights',
	);

	// Add PRO pages if PRO version is active
	if ( opti_behavior_pro_active() ) {
		$opti_behavior_pages[] = 'opti-behavior-recordings';
		$opti_behavior_pages[] = 'opti-behavior-errors';
		$opti_behavior_pages[] = 'opti-behavior-user-journey';
		$opti_behavior_pages[] = 'opti-behavior-form-analytics';
	}

	// Check if we're doing an AJAX request for Opti-Behavior
	$is_opti_behavior_ajax = false;
	if ( wp_doing_ajax() ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- POST parameter used for action identification only (read-only operation, nonce verified in actual AJAX handlers)
		$ajax_action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( strpos( $ajax_action, 'opti_behavior' ) === 0 || strpos( $ajax_action, 'optibehavior' ) === 0 ) {
			$is_opti_behavior_ajax = true;
		}
	}

	return in_array( $current_page, $opti_behavior_pages, true ) || $is_opti_behavior_ajax || $is_plugins_screen;
}

/**
 * Resolve the best available catalog file for a domain and locale.
 *
 * WordPress.org language packs live in WP_LANG_DIR/plugins and always win
 * over the copy bundled with the plugin, matching core's own precedence.
 * Passing the .mo path is sufficient: load_textdomain() prefers a sibling
 * .l10n.php automatically when one exists.
 *
 * @since 1.8.1.6
 *
 * @param string $domain      Text domain.
 * @param string $locale      Locale to look for.
 * @param string $plugin_path Absolute path to the plugin's languages directory.
 * @return string|false Absolute path to the catalog, or false when none exists.
 */
function opti_behavior_locate_catalog( $domain, $locale, $plugin_path ) {
	$candidates = array(
		WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo',
		trailingslashit( $plugin_path ) . $domain . '-' . $locale . '.mo',
	);

	foreach ( $candidates as $candidate ) {
		if ( is_readable( $candidate ) ) {
			return $candidate;
		}
	}

	return false;
}

/**
 * Load the plugin text domain, honouring the per-plugin admin language.
 *
 * Without an override, this registers the plugin's languages directory and
 * returns: WordPress' just-in-time loader then resolves the site locale and
 * prefers a WordPress.org language pack when one is installed.
 *
 * With an override, the forced catalog is loaded under the *current* locale
 * key. WP_Translation_Controller indexes catalogs by locale and looks them
 * up via determine_locale(), so registering the French catalog under fr_FR
 * on an en_US site would load a catalog nothing ever queries.
 *
 * Priority 1 so the catalog is in place before any other init callback
 * calls __() with this domain.
 *
 * @since 1.8.1.6
 */
function opti_behavior_load_textdomain() {
	$languages_rel = dirname( plugin_basename( __FILE__ ) ) . '/languages';

	// Registers the search path for just-in-time loading. Harmless when an
	// override follows, and required on WordPress < 6.7 (plugin supports 5.8+)
	// so bundled /languages/*.mo catalogs resolve before WP auto-registers them.
	load_plugin_textdomain( 'opti-behavior', false, $languages_rel ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Required for bundled translations on WordPress 5.8-6.6 (min supported 5.8); WP only auto-registers plugin language dirs from 6.7.

	if ( ! is_admin() || ! opti_behavior_is_plugin_admin_context() ) {
		return;
	}

	$forced = opti_behavior_forced_admin_locale();

	if ( '' === $forced ) {
		return;
	}

	$current = determine_locale();

	if ( $forced === $current ) {
		return;
	}

	if ( 'en_US' === $forced ) {
		unload_textdomain( 'opti-behavior', false );
		return;
	}

	$catalog = opti_behavior_locate_catalog(
		'opti-behavior',
		$forced,
		plugin_dir_path( __FILE__ ) . 'languages'
	);

	if ( false === $catalog ) {
		return;
	}

	// Discard anything just-in-time loading may already have resolved for
	// the site locale, then register the forced catalog under $current.
	unload_textdomain( 'opti-behavior', true );
	load_textdomain( 'opti-behavior', $catalog, $current );
}
add_action( 'init', 'opti_behavior_load_textdomain', 1 );

/**
 * One-time migration: 'en_US' written before 1.8.1.5 was a default, not a
 * deliberate user choice. Reset it to '' so WordPress.org language packs
 * and the site locale apply. Users who genuinely want English can pick it
 * again in Settings → Language.
 *
 * @since 1.8.1.5
 */
function opti_behavior_migrate_admin_language_option() {
	if ( get_option( 'opti_behavior_i18n_migrated_1815' ) ) {
		return;
	}

	if ( 'en_US' === get_option( 'opti_behavior_admin_language', '' ) ) {
		update_option( 'opti_behavior_admin_language', '' );
	}

	update_option( 'opti_behavior_i18n_migrated_1815', 1 );
}
add_action( 'admin_init', 'opti_behavior_migrate_admin_language_option' );

// Idempotent, presence-keyed re-seed of the FREE-owned filter-profiles option.
// Guarantees the row exists on sites that upgraded (no re-activation) without
// ever clobbering saved profiles — add_option() is a no-op when the row exists.
add_action( 'admin_init', array( 'Opti_Behavior_Filter_Profiles', 'seed' ) );

/**
 * Initialize plugin core and performance optimizer.
 *
 * @since 1.0.0
 */
add_action(
	'init',
	function() {
		$core = Opti_Behavior_Heatmap_Core::get_instance();
		$core->maybe_migrate_legacy_default_auto_cleanup_settings();

		// Initialize performance optimizer
		require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-performance-optimizer.php';

		// License manager is now in Pro plugin (opti-behavior-pro)
		// It will be loaded by the Pro plugin if active

		// Manifest manager is now in Pro plugin (opti-behavior-pro)
		// It will be loaded by the Pro plugin if active
		// Free plugin has unlimited features - no need for limit checking

		// Initialize file storage
		require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-heatmap-file-storage.php';

		// Initialize centralized optimizer/cache compatibility layer.
		require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-optimizer-compat.php';
		Opti_Behavior_Optimizer_Compat::init();

		// Initialize tracker-heartbeat: detects optimizer/cache plugins
		// silently killing tracking scripts (zero events despite traffic).
		require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-tracker-heartbeat.php';
		Opti_Behavior_Tracker_Heartbeat::init();

		// Initialize welcome/consent flow (must run before tracker).
		Opti_Behavior_Welcome::init();

		// Initialize onboarding popup (shown on dashboard when no data exists).
		Opti_Behavior_Onboarding::init();

		// Initialize delayed WordPress.org review reminder banner.
		Opti_Behavior_Review_Banner::init();

		// Initialize setup guide: "How it works" setup check + one-time
		// "own visits" and "the menu changed" messages (plugin screens only).
		require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-setup-guide.php';
		Opti_Behavior_Setup_Guide::init();

		// Initialize plugin tracker (works for both Free-only and Free+Pro setups)
		// Sends installation data with 24h heartbeat, auto-detects plugin type
		require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-free-tracker.php';
		Opti_Behavior_Free_Tracker::init();

		// Initialize broadcast banner manager (API-driven in-plugin messages)
		require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-broadcast.php';
		Opti_Behavior_Broadcast::init();

		// Initialize deactivation survey AJAX relay (plugins.php flow).
		Opti_Behavior_Heatmap_Deactivation_Survey::init();

		// Initialize cron health watchdog (admin-only notice when WP-Cron is
		// not firing; throttled hourly, no auto-repair, zero front-end cost).
		require_once OPTI_BEHAVIOR_HEATMAP_INCLUDES_DIR . 'class-opti-behavior-cron-health.php';
		Opti_Behavior_Cron_Health::init();

		// Session recording is now a PRO feature
		// It will be initialized by opti-behavior-pro plugin if active
	}
);

/**
 * Check if Pro plugin files are present.
 * This checks if the Pro plugin code exists, but features are gated by the signed manifest.
 *
 * @since 1.0.0
 * @return bool True if Pro plugin is active, false otherwise.
 */
if ( ! function_exists( 'opti_behavior_pro_active' ) ) {
	function opti_behavior_pro_active() {
		// When the Pro gate is loaded it is the single source of truth: a
		// revoked / suspended / blacklisted install must read as inactive even
		// though the Pro plugin file is present. This mirrors the gate-aware
		// definition in opti-behavior-pro.php so that whichever plugin wins the
		// function_exists() race (Free loads first alphabetically) behaves the
		// same — otherwise this license-blind stub would shadow the Pro one and
		// wrongly report active on a revoked install.
		//
		// Re-entry guard: gate state computation transitively instantiates Pro
		// singletons whose constructors call back into this function before
		// their singleton is assigned; without the guard the chain recurses to
		// a stack overflow. On re-entry fail-safe to false.
		static $computing = false;
		if ( class_exists( 'Opti_Behavior_Gate' ) ) {
			if ( $computing ) {
				return false;
			}
			$computing = true;
			try {
				return Opti_Behavior_Gate::pro_active();
			} finally {
				$computing = false;
			}
		}
		// Gate absent ⇒ Pro not loaded ⇒ inactive.
		return defined( 'OPTI_BEHAVIOR_PRO_VERSION' );
	}
}

/**
 * Whether the current visitor's IP is excluded from all tracking.
 *
 * Global wrapper so the Pro plugin (and third parties) can gate their
 * trackers with a simple function_exists() check.
 *
 * @since 1.6.0
 * @param string|null $ip IP to test; null = current request.
 * @return bool True when tracking scripts must not run.
 */
if ( ! function_exists( 'opti_behavior_ip_is_excluded' ) ) {
	function opti_behavior_ip_is_excluded( $ip = null ) {
		return Opti_Behavior_IP_Exclusion::is_excluded( $ip );
	}
}

/**
 * Add custom cron intervals for scheduled reports.
 *
 * @since 1.1.0
 * @param array $schedules Existing schedules.
 * @return array Modified schedules.
 */
add_filter(
	'cron_schedules',
	function( $schedules ) {
		if ( ! isset( $schedules['every_fifteen_minutes'] ) ) {
			// Translations cannot load before `init` (WP 6.7+ `_doing_it_wrong`); this filter can fire earlier. @since 1.8.1.6
			$display = did_action( 'init' ) ? __( 'Every 15 Minutes', 'opti-behavior' ) : 'Every 15 Minutes';

			$schedules['every_fifteen_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => $display,
			);
		}
		return $schedules;
	}
);
