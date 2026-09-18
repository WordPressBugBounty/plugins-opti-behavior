<?php
/**
 * Frontend Class
 *
 * Handles frontend functionality and script enqueuing.
 *
 * @package opti-behavior
 * @copyright 2025 Opti-User
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend Class
 *
 * Provides frontend functionality and script management.
 *
 * @since 1.0.0
 */
class Opti_Behavior_Heatmap_Frontend {

	/**
	 * Core instance.
	 *
	 * @since 1.0.0
	 * @var Opti_Behavior_Heatmap_Core
	 */
	private $core;

	/**
	 * Current user for heatmap viewing.
	 *
	 * @since 1.0.0
	 * @var WP_User
	 */
	protected $user = null;

	/**
	 * A/B tracker config queued for inline emit in wp_head.
	 *
	 * Bug #3 fix: when LiteSpeed Cache's JS-Combine is active, the inline
	 * block emitted by wp_localize_script does NOT carry data-no-optimize="1"
	 * (the script_loader_tag filter only covers the external <script src=…>).
	 * The combined bundle runs AFTER the external tracker, so the IIFE bails
	 * because optiBehaviorABConfig is undefined at its load time. We work
	 * around this by echoing a protected <script data-no-optimize="1"> in
	 * wp_head BEFORE any script runs.
	 *
	 * @since 1.3.1
	 * @var array|null
	 */
	private $ab_tracker_config = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Opti_Behavior_Heatmap_Core $core Core instance.
	 */
	public function __construct( $core ) {
		$this->core = $core;
		$this->handle_heatmap_iframe_headers();
		$this->handle_guest_preview_mode();
		$this->handle_mobile_preview_mode();
		$this->init_hooks();
		$this->handle_heatmap_viewing();
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	private function init_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts' ) );

		// Frontend Stats Bar: AJAX endpoint (admin-only).
		add_action( 'wp_ajax_opti_behavior_frontend_stats', array( $this, 'ajax_frontend_stats' ) );

		// Frontend Stats Bar: enqueue assets early (must run before wp_head closes).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_stats_bar_assets' ), 20 );

		// Frontend Stats Bar: render bar HTML in footer BEFORE scripts (priority 5 < wp_print_footer_scripts at 20).
		add_action( 'wp_footer', array( $this, 'render_frontend_stats_bar' ), 5 );

		// Optimizer/cache exclusion filters + unified script-tag protection are
		// registered centrally by Opti_Behavior_Optimizer_Compat (Phase A refactor).

		// Phase C: early-event queue stub, printed as early as possible in
		// wp_head — but AFTER core's wp_enqueue_scripts() (hooked at wp_head
		// priority 1), because the stub gates itself on
		// wp_script_is( 'opti-behavior-reporter', 'enqueued' ) and that state
		// only becomes final once the 'wp_enqueue_scripts' action has fired.
		// Priority 2 (regression: priority 0 ran before the enqueue state
		// existed, so the stub never printed on a real frontend) still beats
		// wp_print_head_scripts (priority 9) and every external script tag.
		add_action( 'wp_head', array( $this, 'print_early_event_queue' ), 2 );
	}

	/**
	 * Remove X-Frame-Options header when page is loaded inside heatmap iframe.
	 *
	 * Many servers, security plugins, or themes send X-Frame-Options: DENY or
	 * SAMEORIGIN, which blocks the heatmap detail page from embedding the
	 * frontend page in an iframe. This method detects the heatmap iframe context
	 * (via opti_heatmap_preview, opti_preview_as_guest, or opti_preview_as_mobile
	 * query parameters) and removes the header so the iframe can load.
	 *
	 * SECURITY: This only fires when a specific preview parameter is present,
	 * so normal page visits are unaffected.
	 *
	 * @since 1.5.17
	 */
	public function handle_heatmap_iframe_headers() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only preview mode parameter
		$is_heatmap_preview = (
			( isset( $_GET['opti_heatmap_preview'] ) && '1' === $_GET['opti_heatmap_preview'] ) ||
			( isset( $_GET['opti_preview_as_guest'] ) && '1' === $_GET['opti_preview_as_guest'] ) ||
			( isset( $_GET['opti_preview_as_mobile'] ) && '1' === $_GET['opti_preview_as_mobile'] )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $is_heatmap_preview || is_admin() ) {
			return;
		}

		// Remove X-Frame-Options via wp_headers filter (works for most cases).
		add_filter(
			'wp_headers',
			function ( $headers ) {
				unset( $headers['X-Frame-Options'] );
				return $headers;
			},
			999
		);

		// Fallback: also remove via send_headers action (catches headers set by
		// server config, security plugins, or PHP ini settings).
		add_action(
			'send_headers',
			function () {
				header_remove( 'X-Frame-Options' );

				// CSP frame-ancestors overrides X-Frame-Options per W3C CSP spec.
				// Some CDNs (e.g., Hostinger hcdn) inject X-Frame-Options: DENY at the
				// infrastructure level after PHP finishes - header_remove() cannot strip it.
				// When Content-Security-Policy: frame-ancestors is present, browsers MUST
				// ignore X-Frame-Options entirely, allowing the iframe to load.
				// Using false as second param to avoid replacing existing CSP headers.
				header( "Content-Security-Policy: frame-ancestors 'self'", false );
			},
			999
		);

		// Propagate the preview context to every AJAX/REST request the previewed
		// page fires (fetch, XMLHttpRequest — jQuery uses XHR). Must print as
		// early as possible so it is installed before any widget boots.
		add_action( 'wp_head', array( $this, 'print_preview_ajax_context_script' ), 0 );
	}

	/**
	 * Print an inline script that appends the active opti preview flags to
	 * same-origin admin-ajax.php and REST requests fired from inside the
	 * heatmap preview iframe.
	 *
	 * Why: pages rendered with opti_preview_as_guest=1 mint their nonces for
	 * user 0, but AJAX from the iframe executes with the admin's cookies and a
	 * different current user — nonce verification fails and async widgets
	 * (contact forms, ticket portals, live lists) hang on their loading
	 * spinner forever. Tagging the requests lets handle_guest_preview_mode()
	 * apply the same guest context server-side, keeping nonces consistent for
	 * ANY theme/plugin without per-widget handling.
	 *
	 * SECURITY: the flags only ever REDUCE privileges (force guest user), and
	 * the patch only touches same-origin admin-ajax/REST URLs.
	 *
	 * @since 1.7.3
	 */
	public function print_preview_ajax_context_script() {
		?>
		<script id="opti-preview-ajax-context">
		(function() {
			'use strict';
			var FLAGS = ['opti_heatmap_preview', 'opti_preview_as_guest', 'opti_preview_as_mobile'];
			var search = window.location.search || '';
			var pairs = [];
			FLAGS.forEach(function(k) {
				var m = search.match(new RegExp('[?&]' + k + '=([^&#]+)'));
				if (m) {
					pairs.push(k + '=' + m[1]);
				}
			});
			if (!pairs.length) {
				return;
			}
			var extra = pairs.join('&');

			function shouldTag(url) {
				try {
					var u = new URL(url, window.location.href);
					if (u.origin !== window.location.origin) {
						return false;
					}
					if (u.search.indexOf('opti_heatmap_preview=') !== -1 || u.search.indexOf('opti_preview_as_guest=') !== -1) {
						return false; // Already tagged.
					}
					return /\/admin-ajax\.php$/.test(u.pathname) ||
						u.pathname.indexOf('/wp-json/') !== -1 ||
						/[?&]rest_route=/.test(u.search);
				} catch (e) {
					return false;
				}
			}

			function tag(url) {
				return url + (url.indexOf('?') === -1 ? '?' : '&') + extra;
			}

			if (window.fetch) {
				var origFetch = window.fetch;
				window.fetch = function(input, init) {
					try {
						if (typeof input === 'string' && shouldTag(input)) {
							input = tag(input);
						} else if (input && typeof input === 'object' && input.url && shouldTag(input.url)) {
							input = new Request(tag(input.url), input);
						}
					} catch (e) { /* fall through untagged */ }
					return origFetch.call(this, input, init);
				};
			}

			if (window.XMLHttpRequest && window.XMLHttpRequest.prototype) {
				var origOpen = window.XMLHttpRequest.prototype.open;
				window.XMLHttpRequest.prototype.open = function(method, url) {
					try {
						if (typeof url === 'string' && shouldTag(url)) {
							arguments[1] = tag(url);
						}
					} catch (e) { /* fall through untagged */ }
					return origOpen.apply(this, arguments);
				};
			}
		})();
		</script>
		<?php
	}

	/**
	 * Handle guest preview mode for heatmap iframe.
	 *
	 * When viewing heatmap with "Guest Visitors" filter, the iframe should
	 * display the page as a guest would see it (without admin bar and with
	 * guest-level content visibility).
	 *
	 * @since 1.0.0
	 */
	public function handle_guest_preview_mode() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Read-only preview mode parameter; forcing the guest user only ever REDUCES privileges.
		$is_guest_preview = ( isset( $_GET['opti_preview_as_guest'] ) && $_GET['opti_preview_as_guest'] === '1' );

		// AJAX/REST requests fired FROM inside a guest-preview iframe must run as
		// the same guest user the page was rendered as, otherwise nonces minted on
		// the page (for user 0) fail verification when the request executes with
		// the admin's cookies (observed: SupportCandy ticket form 401 "Unauthorized
		// request!" looping its spinner forever). Widgets like SupportCandy forward
		// the page query args in the POST body; our injected preview script also
		// appends them to admin-ajax/REST URLs, so honor both sources here.
		if ( ! $is_guest_preview && defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$is_guest_preview = ( isset( $_POST['opti_preview_as_guest'] ) && $_POST['opti_preview_as_guest'] === '1' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		if ( $is_guest_preview ) {
			// Store the original user to restore on shutdown (safety measure)
			$original_user_id = get_current_user_id();

			// Switch to guest user (ID 0) IMMEDIATELY so that membership plugins,
			// shortcodes, and content visibility plugins see the user as a guest.
			// This must happen before 'init' hook for most plugins to recognize it.
			wp_set_current_user( 0 );

			// Restore user on shutdown (safety measure for any cleanup processes)
			add_action(
				'shutdown',
				function () use ( $original_user_id ) {
					if ( $original_user_id > 0 ) {
						wp_set_current_user( $original_user_id );
					}
				},
				0
			);

			// Primary method: filter to disable admin bar
			add_filter( 'show_admin_bar', '__return_false', PHP_INT_MAX );

			// Set user preference to hide admin bar (checked before init)
			add_filter( 'get_user_option_show_admin_bar_front', '__return_false', PHP_INT_MAX );

			// Prevent WordPress from even initializing the admin bar
			// This runs very early, before init hook fires
			remove_action( 'init', '_wp_admin_bar_init' );

			// Also hook into init to remove it there as well (belt and suspenders)
			add_action( 'init', function() {
				remove_action( 'init', '_wp_admin_bar_init' );
			}, 0 );

			// Prevent admin bar CSS from being added to head
			add_action( 'wp_head', function() {
				remove_action( 'wp_head', '_admin_bar_bump_cb' );
			}, 0 );

			// Force hide via CSS as final fallback
			add_action( 'wp_head', function() {
				echo '<style type="text/css">html { margin-top: 0 !important; } body { margin-top: 0 !important; } #wpadminbar { display: none !important; visibility: hidden !important; }</style>' . "\n";
			}, 1 );
		}
	}


	/**
	 * Handle mobile preview mode for heatmap iframe.
	 *
	 * When viewing mobile/tablet heatmap, the iframe should display the page
	 * as it would appear on a mobile device with responsive layout.
	 *
	 * @since 1.0.0
	 */
	public function handle_mobile_preview_mode() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview mode parameter
		if ( isset( $_GET['opti_preview_as_mobile'] ) && $_GET['opti_preview_as_mobile'] === '1' ) {
			// Force WordPress to think this is a mobile device for responsive themes
			add_filter( 'wp_is_mobile', '__return_true', PHP_INT_MAX );

			// Add mobile viewport meta tag to head
			add_action( 'wp_head', function() {
				// Add viewport meta for mobile
				echo '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">' . "\n";
				// Add CSS to ensure mobile layout
				echo '<style type="text/css">
					/* Force mobile-friendly display */
					html, body { 
						max-width: 100vw !important; 
						overflow-x: hidden !important;
					}
				</style>' . "\n";
			}, 1 );

			// Add body class for mobile
			add_filter( 'body_class', function( $classes ) {
				$classes[] = 'opti-behavior-mobile-preview';
				$classes[] = 'mobile';
				return $classes;
			} );
		}
	}
	/**
	 * Handle heatmap viewing mode.
	 *
	 * @since 1.0.0
	 */
	private function handle_heatmap_viewing() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameter used for heatmap viewing mode (read-only check)
		if ( ! is_admin() && isset( $_GET['opti-behavior'] ) ) {
			// If an administrator is viewing, keep the current user to preserve capability checks
			if ( current_user_can( 'manage_options' ) ) {
				return;
			}
			// Legacy behavior: for non-admin viewers (now effectively disabled by permissions),
			// temporarily drop current user to prevent affecting analytics sessions
			$this->user = wp_get_current_user();
			add_action(
				'wp',
				function () {
					wp_set_current_user( 0 );
				}
			);
			add_action(
				'shutdown',
				function () {
					wp_set_current_user( $this->user->ID );
				},
				0
			);
		}
	}

	/**
	 * Register scripts and styles.
	 *
	 * @since 1.0.0
	 */
	public function register_scripts() {
		$version = OPTI_BEHAVIOR_HEATMAP_VERSION;
		$consent_js_path  = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/js/opti-behavior-consent.js';
		$consent_css_path = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/opti-behavior-consent.css';
		$consent_js_ver   = file_exists( $consent_js_path ) ? (string) filemtime( $consent_js_path ) : $version;
		$consent_css_ver  = file_exists( $consent_css_path ) ? (string) filemtime( $consent_css_path ) : $version;

		// Register debug script (must be first). Always registered so third
		// parties depending on the handle never break, but only enqueued (as a
		// dependency) when JS debug is enabled — see $debug_deps below.
		wp_register_script(
			'opti-behavior-debug',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/opti-behavior-debug.js',
			array(),
			$version,
			false
		);

		// Debug JS is only pulled onto the page while JS debug is enabled
		// (auto-expires after 3 hours). All consumers guard every call with
		// `if (window.OptiBehaviorDebug)`, so its absence is safe.
		$js_debug_enabled = $this->core->get_debug_manager()->is_js_debug_enabled();
		$debug_deps       = $js_debug_enabled ? array( 'opti-behavior-debug' ) : array();

		// Localize debug configuration (useless without the file, skip when off)
		if ( $js_debug_enabled ) {
			wp_localize_script(
				'opti-behavior-debug',
				'opti_behaviorDebugConfig',
				$this->core->get_debug_manager()->get_js_debug_config()
			);
		}

		// Register heatmap.js library (bundled locally, MIT licensed)
		wp_register_script(
			'heatmap-js',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/heatmap.min.js',
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION,
			true
		);

		// Register main heatmap script (depends on debug script and heatmap.js)
		// The minified version handles both viewer and reporter modes
		wp_register_script(
			'opti-behavior',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/opti-behavior-heatmap.min.js',
			array_merge( $debug_deps, array( 'heatmap-js' ) ),
			$version,
			true
		);

		// Register the shared cookieless anonymous ID broker. It owns the
		// in-memory anonymous visitor/session ids and the cross-tab
		// BroadcastChannel adoption protocol, and is reused by every
		// Anonymous-Mode tracker (heatmap reporter, A/B tracker, funnel tracker,
		// and — in Pro — the session recorder). Registered first so dependents
		// can declare it as a dependency and load after it.
		wp_register_script(
			'opti-behavior-anon-broker',
			Opti_Behavior_Optimizer_Compat::js_asset_url( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR, OPTI_BEHAVIOR_HEATMAP_ASSETS_URL, 'js/opti-behavior-anon-broker.js' ),
			array(),
			$version,
			true
		);

		// Register simple reporter script (for click tracking only)
		wp_register_script(
			'opti-behavior-reporter',
			Opti_Behavior_Optimizer_Compat::js_asset_url( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR, OPTI_BEHAVIOR_HEATMAP_ASSETS_URL, 'js/opti-behavior-heatmap-simple.js' ),
			array_merge( $debug_deps, array( 'opti-behavior-anon-broker' ) ),
			$version,
			true
		);

		// Session recorder script moved to PRO plugin
		// No longer registered in free version

		// Register frontend mobile simulator script
		wp_register_script(
			'opti-behavior-frontend-mobile-simulator',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/frontend-mobile-simulator.js',
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION, // Version bump to force cache refresh for iframe detection fix
			true
		);

		// Register frontend page title override script
		wp_register_script(
			'opti-behavior-frontend-page-title',
			Opti_Behavior_Optimizer_Compat::js_asset_url( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR, OPTI_BEHAVIOR_HEATMAP_ASSETS_URL, 'js/frontend-page-title.js' ),
			array( 'opti-behavior-reporter' ),
			$version,
			true
		);

		// Register heatmap capture script (for downloading heatmap as image)
		wp_register_script(
			'opti-behavior-heatmap-capture',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/heatmap-capture.js',
			array(),
			$version,
			true
		);

		// Register main heatmap style
		wp_register_style(
			'opti-behavior',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/style.css',
			array(),
			$version
		);

		// Register consent manager script (enqueued only in full privacy mode).
		// Depends on opti-behavior-reporter so consent fires before tracker init.
		wp_register_script(
			'opti-behavior-consent',
			Opti_Behavior_Optimizer_Compat::js_asset_url( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR, OPTI_BEHAVIOR_HEATMAP_ASSETS_URL, 'js/opti-behavior-consent.js' ),
			array( 'opti-behavior-reporter' ),
			$consent_js_ver,
			true
		);

		// Register consent banner stylesheet (enqueued only in full privacy mode).
		wp_register_style(
			'opti-behavior-consent',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/opti-behavior-consent.css',
			array(),
			$consent_css_ver
		);

		// Register A/B test tracker script (lightweight, no dependencies).
		wp_register_script(
			'opti-behavior-ab-tracker',
			Opti_Behavior_Optimizer_Compat::js_asset_url( OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR, OPTI_BEHAVIOR_HEATMAP_ASSETS_URL, 'js/ab-test-tracker.js' ),
			array( 'opti-behavior-anon-broker' ),
			$version,
			true
		);

		// Register A/B test anti-flicker stylesheet.
		wp_register_style(
			'opti-behavior-ab-anti-flicker',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/ab-test-anti-flicker.css',
			array(),
			$version
		);
	}

	/**
	 * Enqueue frontend scripts and styles.
	 *
	 * @since 1.0.0
	 */
	public function wp_enqueue_scripts() {
		// Skip ALL tracking scripts inside our own preview iframes: the heatmap
		// preview (blink loop) and the A/B visual editor / admin preview, whose
		// pageviews would otherwise be recorded as real traffic (QA-B-TRACK-011).
		$opti_preview_args = array(
			'opti_heatmap_preview',
			'opti_preview_as_guest',
			'opti_preview_as_mobile',
			'opti_ab_visual_editor',
			'opti_ab_admin_preview',
		);
		foreach ( $opti_preview_args as $opti_preview_arg ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview-mode check, no state change.
			if ( isset( $_GET[ $opti_preview_arg ] ) ) {
				// A preview response must never be cached or optimized: the
				// tracker-free HTML would otherwise be served to real visitors.
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Cross-plugin standard constant honoured by WP Super Cache, W3TC, WP Rocket, etc.; must keep this exact name.
				}
				if ( ! defined( 'DONOTROCKETOPTIMIZE' ) ) {
					define( 'DONOTROCKETOPTIMIZE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Cross-plugin standard constant read by WP Rocket; must keep this exact name.
				}
				return;
			}
		}

		// Check if this is a heatmap capture request (for downloading heatmap as image)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameter used for capture mode (read-only check)
		if ( isset( $_GET['opti_capture_mode'] ) && '1' === $_GET['opti_capture_mode'] ) {
			wp_enqueue_script( 'opti-behavior-heatmap-capture' );
			return;
		}

		if ( ! $this->should_enqueue_scripts() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameter used for heatmap viewing mode (read-only check)
		if ( ( isset( $_GET['opti-behavior'] ) || isset( $_GET['opti_behavior_heatmap'] ) ) && $this->core->can_view() ) {
			$this->enqueue_view_scripts();
		} elseif ( $this->is_reporting_page() ) {
			$this->enqueue_report_scripts();
		}
	}

	/**
	 * Check if scripts should be enqueued.
	 *
	 * @since 1.0.0
	 * @return bool True if scripts should be enqueued.
	 */
	private function should_enqueue_scripts() {
		// Don't enqueue on admin pages
		if ( is_admin() ) {
			return false;
		}

		// NOTE: bot user-agent and excluded-IP checks intentionally do NOT run
		// here. Page caches (WP Rocket, LiteSpeed) store one HTML copy for all
		// anonymous visitors, so any per-request decision made at render time
		// gets baked into the cached page and served to everyone (cache
		// poisoning). Those checks now run server-side at ingest via
		// Opti_Behavior_Ingest_Gate (includes/class-opti-behavior-ingest-gate.php),
		// which also holds the former private is_bot() pattern list verbatim.

		return true;
	}

	/**
	 * Check if current page should be reported.
	 *
	 * @since 1.0.0
	 * @return bool True if page should be reported.
	 */
	private function is_reporting_page() {
		$options = $this->core->get_options();

		// ALWAYS track all page types (singular, archives, search, home, 404, etc.)
		// This ensures all taxonomy types (categories, tags, authors, search, date archives) are tracked
		// The report_non_singular option is NOT applied here on purpose: this same
		// reporter records sessions, page views, UTM and A/B data, which must stay
		// complete. Since 1.9.5 the option only filters HEATMAP points of archive
		// URLs, server-side at ingest (Opti_Behavior_Heatmap_Page_Type_Prune,
		// called from Opti_Behavior_Heatmap_Storage::save_heatmap_data()) — which
		// also stays correct behind page caches.

		// Check excluded categories (only for single posts)
		if ( is_single() && ! empty( $options['exclude_categories'] ) ) {
			$post_categories = wp_get_post_categories( get_the_ID() );
			if ( array_intersect( $post_categories, $options['exclude_categories'] ) ) {
				return false;
			}
		}

		// Skip tracking for admin users if disabled in Traffic & Behavior settings
		// track_admin_users defaults to true (admins are tracked)
		if ( ! self::admin_tracking_allowed() ) {
			return false;
		}

		// Track everything else
		return true;
	}

	/**
	 * Whether the CURRENT user may be tracked by a render-time tracker.
	 *
	 * Shared gate for every Free tracker that is enqueued at render time
	 * (reporter, funnel tracker, …) so the Traffic & Behavior setting
	 * `track_admin_users` cannot be honoured by one tracker and ignored by the
	 * next (QA-B-TRACK-012). Anonymous visitors are always allowed: the
	 * capability check is the gate.
	 *
	 * @since 1.9.5
	 * @return bool True when this user may be tracked.
	 */
	public static function admin_tracking_allowed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return true;
		}

		// QA / preview override: `?opti_behavior_test_recording=1` re-enables the
		// reporter for THIS administrator only, mirroring the Pro recorder
		// override (Opti_Behavior_Session_Recording::enqueue_recording_scripts).
		// It is never honoured for anonymous visitors — the capability check
		// above is the gate — and it only ever turns tracking back ON, so no
		// nonce is required (it cannot change state or leak data).
		if ( self::is_test_tracking_override() ) {
			return true;
		}

		$traffic_settings = get_option( 'opti_behavior_traffic_settings', array() );

		return isset( $traffic_settings['track_admin_users'] ) ? (bool) $traffic_settings['track_admin_users'] : true;
	}

	/**
	 * Whether the administrator asked for a test/preview tracking pass.
	 *
	 * Honours `?opti_behavior_test_recording=1` and the 30-minute cookie the
	 * first request sets, exactly like the Pro session recorder. Callers MUST
	 * have already established that the current user can `manage_options`:
	 * this helper only reads the request, it performs no capability check of
	 * its own, and anonymous visitors never reach it.
	 *
	 * @since 1.9.0.7
	 * @return bool True when the override is active for this request.
	 */
	private static function is_test_tracking_override() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only preview flag, admin-only, cannot change state.
		$has_param  = isset( $_GET['opti_behavior_test_recording'] );
		$has_cookie = isset( $_COOKIE['opti_behavior_test_recording'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $has_param && ! $has_cookie ) {
			return false;
		}

		// Persist for 30 minutes so the follow-up page views of the same QA pass
		// stay tracked without re-adding the query argument (same cookie Pro sets).
		if ( $has_param && ! $has_cookie && ! headers_sent() ) {
			setcookie( 'opti_behavior_test_recording', '1', time() + 1800, '/', '', false, false );
		}

		return true;
	}

	/**
	 * Enqueue scripts for heatmap viewing.
	 *
	 * @since 1.0.0
	 */
	private function enqueue_view_scripts() {
		wp_enqueue_script( 'opti-behavior' );
		wp_enqueue_style( 'opti-behavior' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameter used for heatmap event display (read-only)
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Validated with isset() check above
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash not needed for sanitize_text_field
		// Support both parameter names for backward compatibility
		$event_name = isset( $_GET['opti_behavior_heatmap'] )
			? sanitize_text_field( $_GET['opti_behavior_heatmap'] )
			: sanitize_text_field( $_GET['opti-behavior'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		// Extract page ID from event name (e.g., "click_pc-1" -> page_id = 1)
		$page_id = 0;
		if ( preg_match( '/^(click_pc|click_mobile)-(\d+)$/', $event_name, $matches ) ) {
			$event_type = $matches[1];
			$page_id = intval( $matches[2] );
		} else {
			$event_type = $event_name;
		}

		$analytics = $this->core->get_analytics();
		$heatmap_data = array();

		// Try to fetch by explicit page_id from the query parameter first
		if ( $page_id && in_array( $event_type, array( 'click_pc', 'click_mobile' ), true ) ) {
			$event_id = Opti_Behavior_Heatmap_Core::EVENT_NAMES[ $event_type ];
			$heatmap_data = $analytics->get_click_heatmap( $page_id, $event_id );
		}

		// Fallback: if no data found (or no id provided), resolve the page id from the current permalink
		if ( ( empty( $heatmap_data ) || ! is_array( $heatmap_data ) ) && in_array( $event_type, array( 'click_pc', 'click_mobile' ), true ) ) {
			$event_id = isset( $event_id ) ? $event_id : Opti_Behavior_Heatmap_Core::EVENT_NAMES[ $event_type ];
			$fallback_url   = function_exists( 'get_permalink' ) ? get_permalink() : '';
			if ( ! empty( $fallback_url ) ) {
				$fallback_page_id = $analytics->get_or_create_page_id( $fallback_url, '' );
				if ( $fallback_page_id ) {
					$page_id = $fallback_page_id; // keep for localized data below
					$heatmap_data = $analytics->get_click_heatmap( $fallback_page_id, $event_id );
				}
			}
		}

		// Transform data to match heatmap.js library expectations
		$points = array();
		$page_height = 0;

		// Extract simple x,y points and calculate page dimensions
		foreach ( $heatmap_data as $point ) {
			$points[] = array(
				'x' => (int) $point['x'],
				'y' => (int) $point['y']
			);
			$page_height = max( $page_height, (int) $point['y'] );
		}

		// Create intensity counts array (40px segments)
		$segment_height = 40;
		// For mobile simulator, we need to ensure bars cover the full scrollable page
		// Use a minimum page height to ensure adequate coverage
		$min_page_height = 3000; // Minimum height to ensure full page coverage
		$effective_page_height = max( $page_height, $min_page_height );
		$segments_count = max( 1, ceil( $effective_page_height / $segment_height ) );
		$counts = array_fill( 0, $segments_count, 0 );

		// Count points in each segment
		foreach ( $points as $point ) {
			$segment = floor( $point['y'] / $segment_height );
			if ( $segment >= 0 && $segment < $segments_count ) {
				$counts[ $segment ]++;
			}
		}

		// Prepare data in the format expected by heatmap.js
		$heatmap_js_data = array(
			'points' => $points,
			'counts' => $counts
		);

		// Detect mobile heatmap requests for viewport simulation
		$is_mobile_heatmap = strpos( $event_type, '_mobile' ) !== false;
		$mobile_viewport = false;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET parameters used for mobile viewport display (read-only)
		if ( $is_mobile_heatmap || isset( $_GET['mobile_view'] ) ) {
			$mobile_viewport = array(
				'width' => isset( $_GET['vw'] ) ? intval( wp_unslash( $_GET['vw'] ) ) : 375,
				'height' => isset( $_GET['vh'] ) ? intval( wp_unslash( $_GET['vh'] ) ) : 667,
				'device_name' => 'Mobile Device'
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Get count_bar setting from options
		$options = $this->core->get_options();
		$count_bar_enabled = isset($options['count_bar']) ? intval($options['count_bar']) : 1;

		// Set up the main opti_behavior_heatmap global that the JavaScript expects
		$opti_behavior_config = array(
			'_mode'      => 'viewer',
			'event'      => $event_type, // Use clean event type (e.g., "click_pc")
			'page_id'    => $page_id,
			'url'        => function_exists( 'get_permalink' ) ? get_permalink() : '',
			'width'      => $mobile_viewport ? $mobile_viewport['width'] : 1250,
			'count_bar'  => $count_bar_enabled, // Use setting from database
			'data'       => wp_json_encode( $heatmap_js_data ), // Properly formatted data
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'opti_behavior_heatmap_nonce' ),
		);

		// Add mobile viewport configuration if needed
		if ( $mobile_viewport ) {
			$opti_behavior_config['mobile_viewport'] = $mobile_viewport;
		}

		wp_localize_script( 'opti-behavior', 'opti_behavior_heatmap', $opti_behavior_config );

		// Add mobile simulator functionality if needed
		if ( $mobile_viewport ) {
			$this->add_mobile_simulator_script( $mobile_viewport );
		}

		// Also set opti_behaviorHeatmapView for backward compatibility
		wp_localize_script( 'opti-behavior', 'opti_behaviorHeatmapView', array(
			'event'     => $event_name,
			'event_type' => isset( $event_type ) ? $event_type : $event_name,
			'page_id'   => $page_id,
			'data'      => $heatmap_data,
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'opti_behavior_heatmap_nonce' ),
		) );


	}

	/**
	 * Enqueue scripts for data reporting.
	 *
	 * @since 1.0.0
	 */
	private function enqueue_report_scripts() {
		// Use simple reporter script for click tracking (more reliable than minified version)
		wp_enqueue_script( 'opti-behavior-reporter' );

		// Session recorder moved to PRO plugin - no longer enqueued in free version

		$options = $this->core->get_options();
		$session = $this->core->get_session();

		// Defensive fallback (defense in depth alongside Opti_Behavior_Heatmap_Options::get_defaults()):
		// never let a missing/empty stored value localize as 0, which would make
		// the frontend tracker fire one AJAX request per click instead of batching
		// (see Bug #3 investigation).
		$ajax_bulk_value     = isset( $options['ajax_bulk'] ) && intval( $options['ajax_bulk'] ) > 0 ? intval( $options['ajax_bulk'] ) : 5;
		// Perf Fix B (customer report 2026-08): fallback raised 15000ms -> 30000ms to match the options default.
		$ajax_interval_value = isset( $options['ajax_interval'] ) && intval( $options['ajax_interval'] ) > 0 ? intval( $options['ajax_interval'] ) : 30000;

		// Anonymous-Mode identity exposed to JS (no cookies, no client-side storage).
		// anon_vid:      logged-in visitors use their WP user id; guests use the
		//                server daily-rotating hash (get_anonymous_hash()) so the
		//                render-time A/B bucketing and the JS tracker agree on the
		//                same visitor id without any client-minted random value.
		// anon_sid_seed: deterministic 30-min UTC session seed the JS ID broker
		//                uses as its cross-tab fallback when no sibling tab replies
		//                over BroadcastChannel. Shape: sess_<hash>_<utc30minbucket>,
		//                where the bucket is floor( unix_time / 1800 ).
		$anon_hash = ( $session && method_exists( $session, 'get_anonymous_hash' ) )
			? $session->get_anonymous_hash()
			: '';
		$anon_current_user_id = get_current_user_id();
		$anon_vid             = $anon_current_user_id > 0 ? 'wp_user_' . $anon_current_user_id : $anon_hash;
		// Hash truncated to 40 chars so the seed ('sess_' + 40 + '_' + bucket
		// ≈ 53 chars) fits the session_id VARCHAR(64) DB columns. Must match
		// the JS broker's computeFallbackSeed() truncation exactly.
		$anon_sid_seed        = '' !== $anon_hash ? 'sess_' . substr( $anon_hash, 0, 40 ) . '_' . intdiv( time(), 1800 ) : '';

		$config = array(
			'ajax_url'         => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'opti_behavior_heatmap_nonce' ),
			'session_id'       => '', // Generated client-side from cookies (RISK-02 fix: avoids caching shared IDs)
			'visitor_id'       => '', // Generated client-side from cookies (RISK-02 fix: avoids caching shared IDs)
			'ajax_delay_time'  => $options['ajax_delay_time'],
			'ajax_interval'    => $ajax_interval_value,
			'ajax_bulk'        => $ajax_bulk_value,
			'page_url'         => get_permalink(),
			'page_title'       => '', // Will be set by JavaScript using document.title
			'is_mobile'        => wp_is_mobile(),
			'screen_width'     => 0, // Will be set by JavaScript
			'screen_height'    => 0, // Will be set by JavaScript
			'user_agent'       => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'referrer'         => $this->get_filtered_referrer(),
			'is_logged_in'     => is_user_logged_in() ? 1 : 0,
			'user_id'          => get_current_user_id(),
			'privacy_mode'     => isset( $options['privacy_mode'] ) ? $options['privacy_mode'] : 'anonymous',
			'wp_user_id'       => get_current_user_id() > 0 ? 'wp_user_' . get_current_user_id() : '',
			'anon_vid'         => $anon_vid,
			'anon_sid_seed'    => $anon_sid_seed,
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- UTM parameters for frontend tracking (no authentication required)
			'utm_source'       => isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : '',
			'utm_medium'       => isset( $_GET['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_medium'] ) ) : '',
			'utm_campaign'     => isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : '',
			'utm_term'         => isset( $_GET['utm_term'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_term'] ) ) : '',
			'utm_content'      => isset( $_GET['utm_content'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_content'] ) ) : '',
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		);

		wp_localize_script( 'opti-behavior-reporter', 'optiBehaviorHeatmapConfig', $config );

		// Provide opti_behavior_heatmap configuration for the reporter script
		$opti_behavior_config = array(
			'_mode'           => 'reporter',
			'ajax_url'        => admin_url( 'admin-ajax.php' ),
			'action'          => 'opti_behavior_heatmap',
			'reports'         => 'click_pc,click_mobile,breakaway_pc,breakaway_mobile,attention_pc,attention_mobile',
			'debug'           => 0,
			'ajax_delay_time' => intval( $options['ajax_delay_time'] ),
			'ajax_interval'   => $ajax_interval_value,
			'ajax_bulk'       => $ajax_bulk_value,
			'nonce'           => wp_create_nonce( 'opti_behavior_heatmap_nonce' ),
			'is_pro_active'   => function_exists( 'opti_behavior_pro_active' ) && opti_behavior_pro_active() ? 1 : 0,
			'privacy_mode'    => isset( $options['privacy_mode'] ) ? $options['privacy_mode'] : 'anonymous',
			'is_logged_in'    => is_user_logged_in() ? 1 : 0,
		);
		wp_localize_script( 'opti-behavior-reporter', 'opti_behavior_heatmap', $opti_behavior_config );

		// Enqueue page title override script (moved to external file: assets/js/frontend-page-title.js)
		wp_enqueue_script( 'opti-behavior-frontend-page-title' );

		// ----------------------------------------------------------------
		// Consent manager: only enqueue when privacy_mode is 'full'.
		// In anonymous mode the plugin is already GDPR-safe — no consent UI needed.
		// ----------------------------------------------------------------
		$privacy_mode = isset( $options['privacy_mode'] ) ? $options['privacy_mode'] : 'anonymous';

		if ( 'full' === $privacy_mode ) {
			$this->enqueue_consent_assets( $options );
		}

		// Enqueue A/B test tracker when there are active tests.
		$this->maybe_enqueue_ab_tracker();
	}

	/**
	 * Enqueue consent JS + CSS and pass configuration to the browser.
	 *
	 * Called from enqueue_report_scripts() only when privacy_mode is 'full'.
	 * Builds the optiBehaviorConsentConfig object from the consent detector and
	 * saved plugin options, then injects CSS custom properties as inline style.
	 *
	 * @since 1.0.3
	 * @param array $options Plugin options array.
	 * @return void
	 */
	private function enqueue_consent_assets( $options ) {
		wp_enqueue_script( 'opti-behavior-consent' );
		wp_enqueue_style( 'opti-behavior-consent' );

		// Detect active third-party consent plugin.
		$detector       = new Opti_Behavior_Consent_Detector();
		$detected_slug  = $detector->get_detected_plugin();
		$detected_label = $detector->get_detected_plugin_label();

		// Banner preference: auto (default), builtin, thirdparty.
		$banner_prefer = isset( $options['consent_banner_prefer'] ) ? $options['consent_banner_prefer'] : 'auto';

		// Determine which consent system to use based on user preference.
		if ( 'builtin' === $banner_prefer ) {
			// User explicitly chose built-in: ignore third-party plugin.
			$detected_slug  = null;
			$detected_label = '';
			$show_builtin   = true;
		} elseif ( 'thirdparty' === $banner_prefer ) {
			// User explicitly chose third-party: ALWAYS defer, never show the
			// built-in banner. The old `&& null !== $detected_slug` guard fell
			// through to auto-detect whenever no consent plugin was recognised,
			// so an unsupported (or renamed) consent plugin silently got our
			// banner stacked on top of it — the one outcome this setting exists
			// to prevent (QA-B-SET-070).
			$show_builtin = false;
		} else {
			// Auto-detect (default): use third-party if detected, else built-in.
			$show_builtin = ( null === $detected_slug );
		}

		// Translatable default strings (used when site owner leaves them blank).
		$default_title   = __( 'We value your privacy', 'opti-behavior' );
		$default_message = __( 'We use cookies to enhance your browsing experience and analyze our traffic. By clicking "Accept All", you consent to our use of analytics cookies.', 'opti-behavior' );
		$default_policy_url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		if ( empty( $default_policy_url ) ) {
			$default_policy_url = home_url( '/privacy-policy/' );
		}

		$banner_title   = ! empty( $options['consent_banner_title'] )
			? sanitize_text_field( $options['consent_banner_title'] )
			: $default_title;

		$banner_message = ! empty( $options['consent_banner_message'] )
			? sanitize_textarea_field( $options['consent_banner_message'] )
			: $default_message;

		$banner_position_user_set = ! empty( $options['consent_banner_position_user_set'] );
		$banner_position     = ( $banner_position_user_set && isset( $options['consent_banner_position'] ) ) ? sanitize_text_field( $options['consent_banner_position'] ) : 'compact-card';
		$banner_accent_color = isset( $options['consent_banner_accent_color'] ) ? sanitize_hex_color( $options['consent_banner_accent_color'] ) : '#6c5ce7';
		// Old default accent (green) was saved on every settings save; use the brand purple instead.
		if ( '#2e7d32' === strtolower( (string) $banner_accent_color ) ) {
			$banner_accent_color = '#6c5ce7';
		}
		$banner_bg_color    = isset( $options['consent_banner_bg_color'] ) ? sanitize_hex_color( $options['consent_banner_bg_color'] ) : '#ffffff';
		$banner_text_color   = isset( $options['consent_banner_text_color'] ) ? sanitize_hex_color( $options['consent_banner_text_color'] ) : '#333333';

		// Banner branding ("Powered by Opti-Behavior" + icon) stays OFF in Free: WordPress.org
		// guideline 10 forbids public-site credits without opt-in. Opti-Behavior Pro enables it.
		$show_branding = (bool) apply_filters( 'opti_behavior_consent_banner_branding', false, $options );

		// Build JS config object.
		$consent_config = array(
			'detected_plugin'       => $detected_slug,
			'detected_plugin_label' => $detected_label,
			'show_builtin_banner'   => $show_builtin,
			'consent_cookie_name'   => 'optibehavior_consent',
			'pending_timeout'       => 8000,
			'is_admin_user'         => current_user_can( 'manage_options' ),
			'banner_position'       => $banner_position,
			'banner_accent_color'   => $banner_accent_color ? $banner_accent_color : '#6c5ce7',
			'banner_icon_url'       => $show_branding ? esc_url_raw( (string) apply_filters( 'opti_behavior_consent_banner_icon_url', plugins_url( 'assets/images/consent-icon.png', dirname( __FILE__ ) ) ) ) : '',
			'banner_powered_by_label' => $show_branding ? __( 'Powered by Opti-Behavior', 'opti-behavior' ) : '',
			'banner_powered_by_url' => $show_branding ? esc_url_raw( (string) apply_filters( 'opti_behavior_consent_banner_powered_by_url', 'https://optiuser.com/' ) ) : '',
			'banner_bg_color'       => $banner_bg_color ? $banner_bg_color : '#ffffff',
			'banner_text_color'     => $banner_text_color ? $banner_text_color : '#333333',
			'banner_title'          => $banner_title,
			'banner_message'        => $banner_message,
			'banner_accept_label'   => ! empty( $options['consent_banner_accept_label'] ) ? sanitize_text_field( $options['consent_banner_accept_label'] ) : __( 'Accept All', 'opti-behavior' ),
			'banner_reject_label'   => ! empty( $options['consent_banner_reject_label'] ) ? sanitize_text_field( $options['consent_banner_reject_label'] ) : __( 'Reject All', 'opti-behavior' ),
			'banner_customize_label' => ! empty( $options['consent_banner_customize_label'] ) ? sanitize_text_field( $options['consent_banner_customize_label'] ) : __( 'Customize', 'opti-behavior' ),
			'banner_save_label'     => ! empty( $options['consent_banner_save_label'] ) ? sanitize_text_field( $options['consent_banner_save_label'] ) : __( 'Save my choice', 'opti-behavior' ),
			'banner_analytics_label' => ! empty( $options['consent_banner_analytics_label'] ) ? sanitize_text_field( $options['consent_banner_analytics_label'] ) : __( 'Analytics cookies', 'opti-behavior' ),
			'banner_analytics_description' => ! empty( $options['consent_banner_analytics_description'] ) ? sanitize_textarea_field( $options['consent_banner_analytics_description'] ) : __( 'Help us understand how visitors interact with our site by collecting anonymous usage data.', 'opti-behavior' ),
			'banner_policy_label'   => ! empty( $options['consent_banner_policy_label'] ) ? sanitize_text_field( $options['consent_banner_policy_label'] ) : __( 'Cookie Policy', 'opti-behavior' ),
			'banner_policy_url'     => ! empty( $options['consent_banner_policy_url'] ) ? esc_url_raw( $options['consent_banner_policy_url'] ) : $default_policy_url,
			'banner_close_label'    => __( 'Close privacy banner', 'opti-behavior' ),
			'banner_analytics_aria' => __( 'Allow analytics cookies', 'opti-behavior' ),
		);

		wp_localize_script( 'opti-behavior-consent', 'optiBehaviorConsentConfig', $consent_config );

		// Admin users: set consent state EARLY via inline script so the heatmap
		// tracker (which runs before consent.js resolves) sees 'granted' immediately
		// and does NOT register its reload-on-consent listener. Without this,
		// admin page loads enter an infinite reload loop.
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_inline_script(
				'opti-behavior-consent',
				'window.optiBehaviorConsentState="granted";',
				'before'
			);
		}

		// Inject CSS custom properties so banner colors are theme-independent.
		$inline_css = sprintf(
			':root { --ob-consent-accent: %s; --ob-consent-bg: %s; --ob-consent-text: %s; }',
			esc_attr( $consent_config['banner_accent_color'] ),
			esc_attr( $consent_config['banner_bg_color'] ),
			esc_attr( $consent_config['banner_text_color'] )
		);
		wp_add_inline_style( 'opti-behavior-consent', $inline_css );
	}

	/**
	 * Enqueue A/B test tracker script when there are active tests on the current page.
	 *
	 * Checks the transient-cached active tests list, and if any match the current
	 * URL or post, enqueues the lightweight tracker with its config (ajax_url,
	 * nonce, goals). The variant data itself is output by the Renderer via wp_footer.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	private function maybe_enqueue_ab_tracker() {
		if ( ! class_exists( 'Opti_Behavior_AB_Test_Database' ) ) {
			return;
		}

		// Get active tests from cache.
		$active_tests = Opti_Behavior_AB_Test_Database::get_active_tests();
		if ( empty( $active_tests ) ) {
			return;
		}

		// Collect goals for all active tests.
		$all_goals = array();
		foreach ( $active_tests as $test ) {
			$goals = Opti_Behavior_AB_Test_Database::get_goals( (int) $test->id );
			if ( ! empty( $goals ) ) {
				foreach ( $goals as $goal ) {
					$all_goals[] = array(
						'id'          => (int) $goal->id,
						'test_id'     => (int) $goal->test_id,
						'goal_type'   => $goal->goal_type,
						'goal_config' => $goal->goal_config,
					);
				}
			}
		}

		// Enqueue the tracker.
		wp_enqueue_script( 'opti-behavior-ab-tracker' );

		// Build the config for the tracker.
		// DEF-AB-013 fix: expose `home_url` so `trackPageVisitGoal` can resolve
		// path-only goal URLs (e.g. "/shop/") against the WP home URL on
		// subdirectory installs (http://host/wordpress/) instead of incorrectly
		// resolving against window.location.origin alone.
		// Anonymous-Mode identity for the cookieless ID broker. The A/B tracker
		// can run on pages where the heatmap reporter is not enqueued (heatmap
		// tracking disabled but an A/B test active), so it must be able to
		// initialize the shared broker itself with the same server-derived
		// values (see enqueue_report_scripts() for the field semantics).
		$ab_options   = $this->core->get_options();
		$ab_session   = $this->core->get_session();
		$ab_anon_hash = ( $ab_session && method_exists( $ab_session, 'get_anonymous_hash' ) )
			? $ab_session->get_anonymous_hash()
			: '';
		$ab_user_id   = get_current_user_id();

		$ab_config = array(
			'ajax_url'      => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'opti_behavior_ab_frontend_nonce' ),
			'home_url'      => home_url( '/' ),
			'goals'         => $all_goals,
			'privacy_mode'  => isset( $ab_options['privacy_mode'] ) ? $ab_options['privacy_mode'] : 'anonymous',
			'anon_vid'      => $ab_user_id > 0 ? 'wp_user_' . $ab_user_id : $ab_anon_hash,
			// 40-char hash truncation: keeps the seed within VARCHAR(64) and
			// byte-identical to enqueue_report_scripts() / the JS broker.
			'anon_sid_seed' => '' !== $ab_anon_hash ? 'sess_' . substr( $ab_anon_hash, 0, 40 ) . '_' . intdiv( time(), 1800 ) : '',
		);

		// Bug #3 fix: do NOT use wp_localize_script — the inline block it emits
		// does not carry data-no-optimize="1" (script_loader_tag only reaches
		// the external <script src=…> tag), so LiteSpeed Cache combines it into
		// a deferred bundle that runs AFTER ab-test-tracker.js. Instead, stash
		// the config on the instance and echo a protected inline block in
		// wp_head, guaranteeing the global is set before ANY script executes.
		$this->ab_tracker_config = $ab_config;
		// Priority 2: run AFTER all wp_enqueue_scripts callbacks (which fire at
		// wp_head priority 1) but BEFORE wp_print_head_scripts (priority 9)
		// and long before any footer scripts. This guarantees the global is
		// defined in the <head> before ab-test-tracker.js ever executes.
		if ( ! has_action( 'wp_head', array( $this, 'print_ab_tracker_config_inline' ) ) ) {
			add_action( 'wp_head', array( $this, 'print_ab_tracker_config_inline' ), 2 );
		}
	}

	/**
	 * Echo the A/B tracker config as a protected inline <script> in wp_head.
	 *
	 * Called at wp_head priority 1 so the global is available before ANY
	 * footer script (including ab-test-tracker.js) runs. The
	 * data-no-optimize="1" attribute prevents LiteSpeed Cache, Autoptimize,
	 * WP Rocket, and SG Optimizer from moving the block into a deferred
	 * bundle. Kept the same element id as WordPress would have used
	 * (`opti-behavior-ab-tracker-js-extra`) for backwards-compat / debugging.
	 *
	 * @since 1.3.1
	 * @return void
	 */
	public function print_ab_tracker_config_inline() {
		if ( empty( $this->ab_tracker_config ) ) {
			return;
		}
		echo '<script id="opti-behavior-ab-tracker-js-extra" data-no-optimize="1">'
			. 'window.optiBehaviorABConfig=' . wp_json_encode( $this->ab_tracker_config ) . ';'
			. '</script>' . "\n";
	}

	/**
	 * Phase C — early-event queue: print a tiny inline click-buffering stub at
	 * wp_head priority 2.
	 *
	 * Insurance against UNKNOWN optimizers that delay/combine ALL JS (including
	 * opti-behavior-heatmap-simple.js, despite the Phase A/B exclusion filters).
	 * The stub captures raw click coordinates into `window._obq` from the very
	 * first paint, and `opti-behavior-heatmap-simple.js` replays them into its
	 * normal click pipeline via `window._obReplay()` once it boots — however
	 * late that happens.
	 *
	 * Gating — mirrors "same conditions that decide whether the reporter is
	 * enqueued": rather than re-implementing should_enqueue_scripts() /
	 * is_reporting_page() here, this reads the actual enqueue state via
	 * wp_script_is(). Those gates run on the 'wp_enqueue_scripts' action,
	 * which core fires FROM wp_head at priority 1, so this callback must run
	 * at priority 2+ for the enqueue state to be final (priority 0 saw a
	 * pre-enqueue state and never printed — fixed 1.7.5). Priority 2 still
	 * precedes wp_print_head_scripts (priority 9), so the stub is emitted
	 * before any external script tag. Single source of truth, no drift.
	 *
	 * Consent — inspected opti-behavior-heatmap-simple.js's startTracker() and
	 * opti-behavior-consent.js: the real tracker begins capturing clicks
	 * IMMEDIATELY in every privacy_mode/consent state. Even in 'full' privacy
	 * mode with consent not yet granted, it starts anonymous click tracking
	 * right away (GDPR Recital 26) instead of waiting for a decision; consent
	 * only affects whether IDs are persisted (anonymous vs full), never whether
	 * clicks are captured. There is no consent-blocked window in the real
	 * tracker for this stub to mirror, so none is added here — it buffers
	 * whenever the reporter is enqueued, matching actual behavior exactly.
	 *
	 * Dedup — `window._obLive` is set to `true` by HeatmapTracker.start() as
	 * its very first statement, synchronously before it attaches its own click
	 * listener. `cap()` below stops buffering the instant `_obLive` is true, so
	 * there is no window in which both this stub and the live tracker could
	 * record the same click.
	 *
	 * Cap — hard-capped at 200 buffered events (memory safety); additional
	 * clicks before boot are silently dropped, matching the plan's spec.
	 *
	 * No behavioral change: does not touch any AJAX endpoint, DB write, or the
	 * click-event schema — it only stages the same raw fields
	 * (x, y, width, height) that opti-behavior-heatmap-simple.js already
	 * computes for a live click, for later replay.
	 *
	 * @since 1.7.1
	 * @return void
	 */
	public function print_early_event_queue() {
		if ( ! wp_script_is( 'opti-behavior-reporter', 'enqueued' ) ) {
			return;
		}

		$js = <<<'JS'
(function () {
	if ( window._obq ) { return; }
	window._obq = [];
	var buf = window._obq;
	function dims() {
		var b = document.body;
		return {
			w: Math.max( document.documentElement.scrollWidth, b ? b.scrollWidth : 0, document.documentElement.clientWidth ),
			h: Math.max( document.documentElement.scrollHeight, b ? b.scrollHeight : 0, document.documentElement.clientHeight )
		};
	}
	function cap( e ) {
		// Live tracker has booted and attached its own listener - stop buffering.
		if ( window._obLive ) { return; }
		// Hard cap: no unbounded memory growth while waiting for the tracker.
		if ( buf.length >= 200 ) { return; }
		var b = document.body;
		var bar = document.getElementById( 'wpadminbar' );
		var off = bar ? bar.offsetHeight : 0;
		var x = e.pageX || ( e.clientX + ( document.documentElement.scrollLeft || ( b ? b.scrollLeft : 0 ) ) );
		var y = ( e.pageY || ( e.clientY + ( document.documentElement.scrollTop || ( b ? b.scrollTop : 0 ) ) ) ) - off;
		var d = dims();
		buf.push( { x: Math.floor( x ), y: Math.floor( y ), width: Math.floor( d.w ), height: Math.floor( d.h ) } );
	}
	document.addEventListener( 'click', cap, true );
	window._obReplay = function ( fn ) {
		var b = buf.slice();
		window._obq = [];
		buf = window._obq;
		for ( var i = 0; i < b.length; i++ ) {
			try { fn( b[ i ] ); } catch ( err ) {}
		}
	};
})();
JS;

		wp_print_inline_script_tag(
			$js,
			array(
				'nowprocket'       => true,
				'data-cfasync'     => 'false',
				'data-no-optimize' => '1',
			)
		);
		echo "\n";
	}

	/**
	 * Check if user is in descendant category.
	 *
	 * @since 1.0.0
	 * @param array   $cats Category IDs to check.
	 * @param WP_Post $_post Post object.
	 * @return bool True if in descendant category.
	 */
	public function in_descendant_category( $cats, $_post = null ) {
		if ( empty( $cats ) ) {
			return false;
		}

		if ( ! $_post ) {
			global $post;
			$_post = $post;
		}

		if ( ! $_post ) {
			return false;
		}

		$post_categories = wp_get_post_categories( $_post->ID );

		foreach ( $post_categories as $cat_id ) {
			if ( in_array( $cat_id, $cats ) ) {
				return true;
			}

			// Check parent categories
			$ancestors = get_ancestors( $cat_id, 'category' );
			if ( array_intersect( $ancestors, $cats ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add content end marker for unread detection.
	 *
	 * @since 1.0.0
	 * @param string $content Post content.
	 * @return string Modified content.
	 */
	public function add_content_end_marker( $content ) {
		$options = $this->core->get_options();

		if ( ! $options['content_end_marker'] ) {
			return $content;
		}

		if ( ! is_singular() ) {
			return $content;
		}

		$marker = '<div class="optibehavior-content-end-marker"></div>';
		return $content . $marker;
	}

	/**
	 * Get filtered referrer URL, excluding internal (same-domain) referrers.
	 *
	 * When a visitor navigates between pages on the same site, the browser's
	 * HTTP_REFERER header contains the previous page URL. This is an internal
	 * referrer and should not be recorded as an external traffic source.
	 *
	 * @since 1.5.20
	 * @return string Filtered referrer URL, or empty string for internal/no referrer.
	 */
	private function get_filtered_referrer() {
		if ( ! isset( $_SERVER['HTTP_REFERER'] ) ) {
			return '';
		}
		$referrer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		if ( empty( $referrer ) ) {
			return '';
		}

		$referrer_host = wp_parse_url( $referrer, PHP_URL_HOST );
		$site_host     = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $referrer_host && $site_host ) {
			// Strip www. prefix for robust comparison
			$ref_clean  = preg_replace( '/^www\./i', '', $referrer_host );
			$site_clean = preg_replace( '/^www\./i', '', $site_host );
			if ( strcasecmp( $ref_clean, $site_clean ) === 0 ) {
				return ''; // Internal referrer, treat as Direct
			}
		}

		return $referrer;
	}

	/**
	 * Get device-specific view width.
	 *
	 * @since 1.0.0
	 * @return int View width.
	 */
	public function get_view_width() {
		if ( wp_is_mobile() ) {
			return Opti_Behavior_Heatmap_Core::VIEW_WIDTH[ Opti_Behavior_Heatmap_Core::FROM_MOBILE ];
		}
		return Opti_Behavior_Heatmap_Core::VIEW_WIDTH[ Opti_Behavior_Heatmap_Core::FROM_PC ];
	}

	/**
	 * Get current event ID based on device.
	 *
	 * @since 1.0.0
	 * @return int Event ID.
	 */
	public function get_current_event_id() {
		if ( wp_is_mobile() ) {
			return Opti_Behavior_Heatmap_Core::CLICK_MOBILE;
		}
		return Opti_Behavior_Heatmap_Core::CLICK_PC;
	}

	/**
	 * Generate heatmap visualization HTML.
	 *
	 * @since 1.0.0
	 * @param array $data Heatmap data.
	 * @return string Heatmap HTML.
	 */
	public function generate_heatmap_html( $data ) {
		if ( empty( $data ) ) {
			return '<div class="optibehavior-no-data">' . esc_html__( 'No heatmap data available for this page.', 'opti-behavior' ) . '</div>';
		}

		$html = '<div class="optibehavior-heatmap-container">';
		$html .= '<canvas id="heatmapCanvas" class="optibehavior-heatmap-canvas"></canvas>';
		$html .= '<div class="optibehavior-heatmap-controls">';
		$html .= '<button id="optibehavior-toggle-heatmap" class="button">' . esc_html__( 'Toggle Heatmap', 'opti-behavior' ) . '</button>';
		$html .= '<div class="optibehavior-heatmap-legend">';
		$html .= '<span class="optibehavior-legend-low">' . esc_html__( 'Low Activity', 'opti-behavior' ) . '</span>';
		$html .= '<span class="optibehavior-legend-high">' . esc_html__( 'High Activity', 'opti-behavior' ) . '</span>';
		$html .= '</div>';
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Get heatmap JavaScript configuration.
	 *
	 * @since 1.0.0
	 * @param array $data Heatmap data.
	 * @return array JavaScript configuration.
	 */
	public function get_heatmap_js_config( $data ) {
		return array(
			'points'      => $data,
			'radius'      => 25,
			'maxOpacity'  => 0.8,
			'minOpacity'  => 0.1,
			'blur'        => 0.75,
			'gradient'    => array(
				'0.0' => 'blue',
				'0.3' => 'cyan',
				'0.5' => 'lime',
				'0.7' => 'yellow',
				'1.0' => 'red',
			),
		);
	}

	/**
	 * Add mobile simulator JavaScript functionality.
	 *
	 * NOTE: JavaScript code has been moved to external file: assets/js/frontend-mobile-simulator.js
	 * This method now enqueues the external file and passes device dimensions via wp_localize_script().
	 *
	 * @since 1.0.0
	 * @param array $mobile_viewport Mobile viewport configuration.
	 */
	private function add_mobile_simulator_script( $mobile_viewport ) {
		$mobile_width = $mobile_viewport['width'];
		$mobile_height = $mobile_viewport['height'];

		// Enqueue the external mobile simulator script
		wp_enqueue_script( 'opti-behavior-frontend-mobile-simulator' );

		// Pass device dimensions to JavaScript
		wp_localize_script(
			'opti-behavior-frontend-mobile-simulator',
			'opti_behaviorMobileSimulator',
			array(
				'deviceWidth'  => $mobile_width,
				'deviceHeight' => $mobile_height,
			)
		);

		// All JavaScript code has been moved to assets/js/frontend-mobile-simulator.js
	}

	/**
	 * =========================================================================
	 * Frontend Stats Bar — AJAX Endpoint + Rendering
	 * =========================================================================
	 */

	/**
	 * Get Frontend Stats Bar settings with defaults.
	 *
	 * @since 1.2.3
	 * @return array Settings array.
	 */
	private function get_stats_bar_settings() {
		$defaults = array(
			'enabled'           => true,
			'hide_in_builders'  => true,
			'period'            => 'last30days',
			'color_theme'       => 'dark',
			'show_visitors'     => true,
			'show_sessions'     => true,
			'show_pageviews'    => true,
			'show_avg_time'     => true,
			'show_scroll_depth' => true,
			'show_bounce_rate'  => true,
			'show_recordings'   => true,
			'show_errors'       => true,
			'show_friction'     => true,
			'show_forms'        => true,
		);
		$saved = get_option( 'opti_behavior_frontend_stats_bar', array() );
		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Detect whether the current request is a visual page builder edit or
	 * preview context (Elementor, Divi, Beaver Builder, WPBakery, Brizy,
	 * Oxygen, Breakdance, SiteOrigin, Visual Composer, Live Composer,
	 * MotoPress Content Editor, Themify Builder, Customizer/Kirki, ...).
	 *
	 * Builders load the frontend page inside an editing iframe where
	 * is_admin() is false, so without this check the Stats Bar would render
	 * on top of the builder UI and block editing.
	 *
	 * @since 1.7.2
	 * @return bool True when a builder editor/preview context is detected.
	 */
	private function is_builder_edit_mode() {
		// WP Customizer preview — also covers Kirki-based theme options
		// (GeneratePress, etc.) which run inside the Customizer.
		$is_builder = is_customize_preview();

		// Query-string signals set by builders when loading the edit/preview frame.
		if ( ! $is_builder ) {
			$builder_params = array(
				'elementor-preview',             // Elementor preview iframe.
				'fl_builder',                    // Beaver Builder.
				'fl_builder_ui',                 // Beaver Builder UI frame.
				'et_fb',                         // Divi Frontend Builder.
				'vc_editable',                   // WPBakery frontend editor iframe.
				'vcv-editable',                  // Visual Composer Website Builder.
				'vcv-action',                    // Visual Composer editor actions.
				'brizy-edit',                    // Brizy editor.
				'brizy-edit-iframe',             // Brizy editor iframe.
				'ct_builder',                    // Oxygen builder.
				'breakdance',                    // Breakdance builder.
				'breakdance_iframe',             // Breakdance builder iframe.
				'siteorigin_panels_live_editor', // SiteOrigin Page Builder Live Editor.
				'dslc',                          // Live Composer (?dslc=active).
				'motopress-ce',                  // MotoPress Content Editor.
				'tb-preview',                    // Themify Builder preview.
				'tf-preview',                    // Themify Builder (alternate param).
			);

			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only builder-context detection, no data processed.
			foreach ( $builder_params as $builder_param ) {
				if ( isset( $_GET[ $builder_param ] ) ) {
					$is_builder = true;
					break;
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		// WPBakery inline mode uses ?vc_action=vc_inline on the parent frame.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only builder-context detection, no data processed.
		if ( ! $is_builder && isset( $_GET['vc_action'] ) && 'vc_inline' === sanitize_key( wp_unslash( $_GET['vc_action'] ) ) ) {
			$is_builder = true;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Runtime fallbacks for builders whose edit frame may lack a query param.
		if ( ! $is_builder ) {
			if ( class_exists( '\Elementor\Plugin' )
				&& isset( \Elementor\Plugin::$instance->preview )
				&& is_callable( array( \Elementor\Plugin::$instance->preview, 'is_preview_mode' ) )
				&& \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
				$is_builder = true;
			} elseif ( class_exists( 'FLBuilderModel' )
				&& is_callable( array( 'FLBuilderModel', 'is_builder_active' ) )
				&& FLBuilderModel::is_builder_active() ) {
				$is_builder = true;
			} elseif ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
				$is_builder = true;
			} elseif ( function_exists( 'vc_is_inline' ) && vc_is_inline() ) {
				$is_builder = true;
			}
		}

		/**
		 * Filters whether the Frontend Stats Bar treats the current request as
		 * a page-builder edit context (and therefore hides the bar).
		 *
		 * @since 1.7.2
		 * @param bool $is_builder True when a builder editor is detected.
		 */
		return (bool) apply_filters( 'opti_behavior_stats_bar_is_builder_mode', $is_builder );
	}

	/**
	 * Compute start/end dates for a given period identifier.
	 *
	 * Mirrors the logic in Data_Helpers_Trait::get_date_range_impl() but is
	 * self-contained so the frontend class doesn't depend on the dashboard.
	 *
	 * @since 1.2.3
	 * @param string $period Period identifier (today|last7days|last30days).
	 * @return array { start: string, end: string }
	 */
	private function get_stats_bar_date_range( $period ) {
		$now = current_time( 'mysql' );

		switch ( $period ) {
			case 'today':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( $now ) );
				break;
			case 'last7days':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days', strtotime( $now ) ) );
				break;
			case 'last30days':
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days', strtotime( $now ) ) );
				break;
			default:
				$start = gmdate( 'Y-m-d 00:00:00', strtotime( $now ) );
		}

		return array(
			'start' => $start,
			'end'   => $now,
		);
	}

	/**
	 * AJAX handler: return frontend stats as JSON.
	 *
	 * Protected by nonce + manage_options capability.
	 * Uses transient caching (60 s TTL) to avoid DB hammering.
	 *
	 * @since 1.2.3
	 */
	public function ajax_frontend_stats() {
		// Security checks.
		check_ajax_referer( 'opti_behavior_frontend_stats_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$settings      = $this->get_stats_bar_settings();
		// Period is driven by the in-bar selector (shared standard options,
		// default "All time"); fall back to the shared default when the POST
		// value is absent or unrecognized. Nonce already verified above.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$period        = isset( $_POST['period'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? Opti_Behavior_Page_Analytics_Repository::sanitize_standard_period( wp_unslash( $_POST['period'] ) )
			: Opti_Behavior_Page_Analytics_Repository::get_default_standard_period();
		$traffic_scope = Opti_Behavior_Page_Analytics_Repository::TRAFFIC_SCOPE_HUMAN;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';

		$repository = new Opti_Behavior_Page_Analytics_Repository();
		$identity   = $repository->resolve_page_identity(
			array(
				'page_url' => $page_url,
				'period'   => $period,
			)
		);
		$page_id    = isset( $identity['page_id'] ) ? absint( $identity['page_id'] ) : 0;
		$page_ids   = isset( $identity['page_ids'] ) ? array_map( 'absint', $identity['page_ids'] ) : array();
		$pro_state  = defined( 'OPTI_BEHAVIOR_PRO_VERSION' ) || defined( 'OptiBehavior_PRO_VERSION' ) ? 'pro' : 'free';
		$traffic_settings = get_option(
			'opti_behavior_traffic_settings',
			array(
				'spam_detection_enabled' => true,
			)
		);
		$spam_exclusion_default = ( ! is_array( $traffic_settings ) || ! array_key_exists( 'spam_detection_enabled', $traffic_settings ) || ! empty( $traffic_settings['spam_detection_enabled'] ) ) ? 'exclude_spam' : 'include_spam';

		// Cache by repository contract, canonical page set, period, traffic scope, Pro state, and spam default.
		$cache_key = 'opti_behavior_frontend_stats_' . md5(
			implode(
				'|',
				array(
					Opti_Behavior_Page_Analytics_Repository::CONTRACT_VERSION,
					$period,
					$traffic_scope,
					$pro_state,
					$spam_exclusion_default,
					$page_url,
					implode( ',', $page_ids ),
				)
			)
		);
		$cached    = get_transient( $cache_key );
		$settings['_canonical_page_ids'] = $page_ids;

		if ( false !== $cached ) {
			/**
			 * Filters Pro plugin stats for the Frontend Stats Bar.
			 *
			 * @since 1.2.3
			 * @param array  $pro_stats  Empty array; Pro adds keys and returns it.
			 * @param string $period     Period identifier.
			 * @param array  $settings   Full settings array.
			 * @param int    $page_id    Page ID (0 = unknown page).
			 * @return array Pro stats keyed by stat name.
			 */
			$pro_stats = apply_filters( 'opti_behavior_frontend_stats_bar_pro_stats', array(), $period, $settings, $page_id );

			if ( ! empty( $pro_stats ) ) {
				$cached['pro'] = array_merge( isset( $cached['pro'] ) && is_array( $cached['pro'] ) ? $cached['pro'] : array(), $pro_stats );
			}
			$cached['page_id']       = $page_id;
			$cached['page_ids']      = $page_ids;
			$cached['page_url']      = $page_url;
			$cached['period']        = $period;
			$cached['traffic_scope'] = $traffic_scope;
			wp_send_json_success( $cached );
		}

		$data = $repository->get_page_metrics(
			array(
				'page_url'      => $page_url,
				'period'        => $period,
				'traffic_scope' => $traffic_scope,
				'prefer_pro'    => true,
			)
		);
		$data = array_merge(
			$data,
			array(
				'page_id'      => $page_id,
				'page_ids'     => $page_ids,
				'page_url'     => $page_url,
				'period'       => $period,
				'traffic_scope' => $traffic_scope,
			)
		);

		// Cache the canonical Free/Pro repository response for 60 seconds per page/scope/period.
		set_transient( $cache_key, $data, 60 );

		// Preserve the legacy Pro extension point until Pro moves fully to the repository filter.
		/** This filter is documented above. */
		$pro_stats = apply_filters( 'opti_behavior_frontend_stats_bar_pro_stats', array(), $period, $settings, $page_id );

		if ( ! empty( $pro_stats ) ) {
			$data['pro'] = array_merge( isset( $data['pro'] ) && is_array( $data['pro'] ) ? $data['pro'] : array(), $pro_stats );
		}

		wp_send_json_success( $data );
	}

	/**
	 * Enqueue Frontend Stats Bar CSS and JS assets.
	 *
	 * Runs on wp_enqueue_scripts (early) so assets are included in wp_head
	 * and wp_print_footer_scripts. The HTML is rendered later in wp_footer.
	 *
	 * @since 1.2.3
	 */
	/**
	 * Switch to the plugin's admin language for admin-facing frontend overlays
	 * (the stats bar is only visible to administrators, so it should follow the
	 * same language as the plugin's admin screens, not the site locale).
	 *
	 * WordPress 6.7+ hands catalog loading off to just-in-time loading, which
	 * resolves the locale via determine_locale() only. So the forced catalog
	 * is located directly and loaded under the *current* locale key, the
	 * same pattern used by the admin-side loader.
	 *
	 * @since 1.8.1.6
	 * @return array|false List of domains switched, or false when not switched.
	 */
	private function switch_to_admin_overlay_locale() {
		$admin_lang = opti_behavior_forced_admin_locale();
		$current    = determine_locale();

		if ( '' === $admin_lang || $admin_lang === $current ) {
			return false;
		}

		$domains = array(
			'opti-behavior' => OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'languages',
		);

		if ( defined( 'OPTI_BEHAVIOR_PRO_VERSION' ) ) {
			$domains['opti-behavior-pro'] = WP_PLUGIN_DIR . '/opti-behavior-pro/languages';
		}

		$switched = array();

		foreach ( $domains as $domain => $path ) {
			$catalog = opti_behavior_locate_catalog( $domain, $admin_lang, $path );

			if ( false === $catalog ) {
				continue;
			}

			unload_textdomain( $domain, true );

			if ( load_textdomain( $domain, $catalog, $current ) ) {
				$switched[] = $domain;
			}
		}

		return $switched ? $switched : false;
	}

	/**
	 * Restore the locale after rendering an admin-facing frontend overlay.
	 *
	 * @since 1.8.1.6
	 * @param array|false $switched Return value of switch_to_admin_overlay_locale().
	 */
	private function restore_admin_overlay_locale( $switched ) {
		if ( ! $switched ) {
			return;
		}

		// $reloadable = true, so just-in-time loading restores the site-locale
		// catalog (or a WordPress.org language pack) on the next translation call.
		foreach ( $switched as $domain ) {
			unload_textdomain( $domain, true );
		}
	}

	public function enqueue_frontend_stats_bar_assets() {
		// Only for admins on the frontend.
		if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Skip inside heatmap preview iframes and the A/B visual editor iframe.
		if ( null !== filter_input( INPUT_GET, 'opti_heatmap_preview' ) ||
			null !== filter_input( INPUT_GET, 'opti_preview_as_guest' ) ||
			null !== filter_input( INPUT_GET, 'opti_preview_as_mobile' ) ||
			null !== filter_input( INPUT_GET, 'opti_ab_visual_editor' ) ||
			null !== filter_input( INPUT_GET, 'opti_ab_admin_preview' ) ) {
			return;
		}

		$settings = $this->get_stats_bar_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		// Skip inside visual page builder editors (Elementor, Divi, ...).
		if ( ! empty( $settings['hide_in_builders'] ) && $this->is_builder_edit_mode() ) {
			return;
		}

		$stats_bar_css_path = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/css/frontend-stats-bar.css';
		$stats_bar_js_path  = OPTI_BEHAVIOR_HEATMAP_PLUGIN_DIR . 'assets/js/frontend-stats-bar.js';
		$stats_bar_css_ver  = file_exists( $stats_bar_css_path ) ? (string) filemtime( $stats_bar_css_path ) : OPTI_BEHAVIOR_HEATMAP_VERSION;
		$stats_bar_js_ver   = file_exists( $stats_bar_js_path ) ? (string) filemtime( $stats_bar_js_path ) : OPTI_BEHAVIOR_HEATMAP_VERSION;

		// Enqueue CSS (will be printed in wp_head).
		wp_enqueue_style(
			'opti-behavior-frontend-stats-bar',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'css/frontend-stats-bar.css',
			array(),
			$stats_bar_css_ver
		);

		// Lucide icons (only loaded on admin pages normally — need it on frontend for stat icons).
		wp_enqueue_script(
			'lucide-icons',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/lucide.min.js',
			array(),
			OPTI_BEHAVIOR_HEATMAP_VERSION,
			true
		);

		wp_enqueue_script(
			'opti-behavior-frontend-stats-bar',
			OPTI_BEHAVIOR_HEATMAP_ASSETS_URL . 'js/frontend-stats-bar.js',
			array( 'lucide-icons' ),
			$stats_bar_js_ver,
			true
		);

		$switched_locale = $this->switch_to_admin_overlay_locale();

		wp_localize_script(
			'opti-behavior-frontend-stats-bar',
			'optiBehaviorStatsBar',
			array(
				'ajax_url'           => admin_url( 'admin-ajax.php' ),
				'heatmap_detail_url' => admin_url( 'admin.php?page=opti-behavior-heatmap-detail' ),
				'heatmaps_url'       => admin_url( 'admin.php?page=opti-behavior-heatmaps' ),
				'nonce'              => wp_create_nonce( 'opti_behavior_frontend_stats_nonce' ),
				'recordings_url'     => admin_url( 'admin.php?page=opti-behavior-recordings' ),
				'settings'           => $settings,
				'periods'            => Opti_Behavior_Page_Analytics_Repository::get_standard_periods(),
				'default_period'     => Opti_Behavior_Page_Analytics_Repository::get_default_standard_period(),
			)
		);

		$this->restore_admin_overlay_locale( $switched_locale );
	}

	/**
	 * Render the Frontend Stats Bar skeleton in wp_footer.
	 *
	 * Only outputs HTML when:
	 * - Feature is enabled in settings
	 * - Current user has manage_options capability
	 * - Not inside an admin page or heatmap preview iframe
	 *
	 * @since 1.2.3
	 */
	public function render_frontend_stats_bar() {
		// Only for admins on the frontend.
		if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Skip inside heatmap preview iframes and the A/B visual editor iframe.
		if ( null !== filter_input( INPUT_GET, 'opti_heatmap_preview' ) ||
			null !== filter_input( INPUT_GET, 'opti_preview_as_guest' ) ||
			null !== filter_input( INPUT_GET, 'opti_preview_as_mobile' ) ||
			null !== filter_input( INPUT_GET, 'opti_ab_visual_editor' ) ||
			null !== filter_input( INPUT_GET, 'opti_ab_admin_preview' ) ) {
			return;
		}

		$settings = $this->get_stats_bar_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		// Skip inside visual page builder editors (Elementor, Divi, ...).
		if ( ! empty( $settings['hide_in_builders'] ) && $this->is_builder_edit_mode() ) {
			return;
		}

		// Admin-only overlay: render in the plugin's admin language.
		$switched_locale = $this->switch_to_admin_overlay_locale();

		$theme            = sanitize_html_class( $settings['color_theme'] );
		$standard_periods = Opti_Behavior_Page_Analytics_Repository::get_standard_periods();
		$default_period   = Opti_Behavior_Page_Analytics_Repository::get_default_standard_period();

		// Determine which stats to show.
		$is_pro = defined( 'OPTI_BEHAVIOR_PRO_VERSION' );

		// Build stat items array.
		$stat_items = array();
		if ( ! empty( $settings['show_visitors'] ) ) {
			$stat_items[] = array( 'key' => 'visitors', 'icon' => 'users', 'label' => __( 'Visitors', 'opti-behavior' ) );
		}
		if ( ! empty( $settings['show_sessions'] ) ) {
			$stat_items[] = array(
				'key'        => 'sessions',
				'icon'       => 'monitor',
				'label'      => __( 'Sessions', 'opti-behavior' ),
				'clickable'  => true,
				'url'        => admin_url( 'admin.php?page=opti-behavior-heatmaps' ),
				'link_class' => 'opti-stats-bar__item--sessions-link',
				'aria_label' => __( 'View heatmap for this page', 'opti-behavior' ),
			);
		}
		if ( ! empty( $settings['show_pageviews'] ) ) {
			$stat_items[] = array( 'key' => 'pageviews', 'icon' => 'eye', 'label' => __( 'Page Views', 'opti-behavior' ) );
		}
		if ( ! empty( $settings['show_avg_time'] ) ) {
			$stat_items[] = array( 'key' => 'avg_time', 'icon' => 'clock', 'label' => __( 'Avg Time', 'opti-behavior' ) );
		}
		if ( ! empty( $settings['show_scroll_depth'] ) ) {
			$stat_items[] = array( 'key' => 'scroll_depth', 'icon' => 'arrow-down', 'label' => __( 'Scroll', 'opti-behavior' ) );
		}
		if ( ! empty( $settings['show_bounce_rate'] ) ) {
			$stat_items[] = array( 'key' => 'bounce_rate', 'icon' => 'log-out', 'label' => __( 'Bounce', 'opti-behavior' ) );
		}

		// PRO stat placeholders.
		if ( $is_pro ) {
			if ( ! empty( $settings['show_recordings'] ) ) {
				$stat_items[] = array(
					'key'        => 'pro.recordings',
					'icon'       => 'video',
					'label'      => __( 'Recordings', 'opti-behavior' ),
					'clickable'  => true,
					'url'        => admin_url( 'admin.php?page=opti-behavior-recordings' ),
					'aria_label' => __( 'View session recordings for this page', 'opti-behavior' ),
				);
			}
			if ( ! empty( $settings['show_errors'] ) ) {
				$stat_items[] = array( 'key' => 'pro.errors', 'icon' => 'alert-triangle', 'label' => __( 'Errors', 'opti-behavior' ) );
			}
			if ( ! empty( $settings['show_friction'] ) ) {
				$stat_items[] = array( 'key' => 'pro.friction', 'icon' => 'zap', 'label' => __( 'Friction', 'opti-behavior' ) );
			}
			if ( ! empty( $settings['show_forms'] ) ) {
				$stat_items[] = array( 'key' => 'pro.forms', 'icon' => 'file-text', 'label' => __( 'Forms', 'opti-behavior' ) );
			}
		}

		?>
		<!-- Opti-Behavior Frontend Stats Bar -->
		<div id="opti-stats-bar" class="opti-stats-bar opti-stats-bar--<?php echo esc_attr( $theme ); ?>" role="banner" aria-label="<?php esc_attr_e( 'Analytics Stats Bar', 'opti-behavior' ); ?>">
			<div class="opti-stats-bar__inner">
				<label class="opti-stats-bar__period">
					<span class="screen-reader-text"><?php esc_html_e( 'Analytics period', 'opti-behavior' ); ?></span>
					<select class="opti-stats-bar__period-select no-select2 select2-off ignore-select2 no-nice-select no-chosen" data-no-select2="1" aria-label="<?php esc_attr_e( 'Analytics period', 'opti-behavior' ); ?>">
						<?php foreach ( $standard_periods as $period_value => $period_label_text ) : ?>
							<option value="<?php echo esc_attr( $period_value ); ?>" <?php selected( $period_value, $default_period ); ?>><?php echo esc_html( $period_label_text ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<span class="opti-stats-bar__separator"></span>
				<div class="opti-stats-bar__stats">
					<?php foreach ( $stat_items as $item ) : ?>
						<?php
						$is_clickable = ! empty( $item['clickable'] ) && ! empty( $item['url'] );
						$item_tag     = $is_clickable ? 'a' : 'div';
						$item_classes = 'opti-stats-bar__item';
						if ( $is_clickable ) {
							$item_classes .= ' opti-stats-bar__item--clickable';
							if ( ! empty( $item['link_class'] ) ) {
								$item_classes .= ' ' . sanitize_html_class( $item['link_class'] );
							} elseif ( 'pro.recordings' === $item['key'] ) {
								$item_classes .= ' opti-stats-bar__item--recordings-link';
							}
						}
						?>
						<<?php echo esc_attr( $item_tag ); ?>
							class="<?php echo esc_attr( $item_classes ); ?>"
							data-stat="<?php echo esc_attr( $item['key'] ); ?>"
							<?php if ( $is_clickable ) : ?>
								href="<?php echo esc_url( $item['url'] ); ?>"
								aria-label="<?php echo esc_attr( isset( $item['aria_label'] ) ? $item['aria_label'] : $item['label'] ); ?>"
							<?php endif; ?>
						>
							<i data-lucide="<?php echo esc_attr( $item['icon'] ); ?>"></i>
							<span class="opti-stats-bar__label"><?php echo esc_html( $item['label'] ); ?></span>
							<span class="opti-stats-bar__value" data-key="<?php echo esc_attr( $item['key'] ); ?>">—</span>
						</<?php echo esc_attr( $item_tag ); ?>>
					<?php endforeach; ?>
				</div>
				<button class="opti-stats-bar__toggle" title="<?php esc_attr_e( 'Hide Stats Bar', 'opti-behavior' ); ?>">
					<i data-lucide="eye-off"></i>
				</button>
			</div>
		</div>
		<!-- Collapsed pill (shown when bar is hidden) -->
		<button id="opti-stats-bar-pill" class="opti-stats-bar-pill opti-stats-bar--<?php echo esc_attr( $theme ); ?>" style="display:none;" title="<?php esc_attr_e( 'Show Stats Bar', 'opti-behavior' ); ?>">
			<i data-lucide="bar-chart-2"></i>
		</button>
		<?php
		$this->restore_admin_overlay_locale( $switched_locale );
	}
}
